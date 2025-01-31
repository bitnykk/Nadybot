<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use DateTime;
use Nadybot\Core\{
	BotRunner,
	CommandReply,
	DB,
	Http,
	HttpResponse,
	SettingManager,
};
use Throwable;

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'updatecsv',
 *		accessLevel = 'admin',
 *		help        = 'updatecsv.txt',
 *		description = "Shows a list of all csv files"
 *	)
 */
class UpdateCSVFilesController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public SettingManager $settingManager;

	public function getGitHash(string $file): ?string {
		$baseDir = BotRunner::getBasedir();
		$descriptors = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];

		$pid = proc_open("git hash-object " . escapeshellarg($file), $descriptors, $pipes, $baseDir);
		if ($pid === false) {
			return null;
		}
		fclose($pipes[0]);
		$gitHash = trim(stream_get_contents($pipes[1]));
		fclose($pipes[1]);
		fclose($pipes[2]);
		return $gitHash;
	}

	/**
	 * @HandlesCommand("updatecsv")
	 * @Matches("/^updatecsv$/i")
	 */
	public function updateCsvCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$checkCmd = BotRunner::isWindows() ? "where" : "command -v";
		$gitPath = shell_exec("{$checkCmd} git");
		$hasGit = is_string($gitPath) && is_executable(rtrim($gitPath));
		if (!$hasGit) {
			$sendto->reply(
				"In order to check if any files can be updated, you need ".
				"to have git installed and in your path."
			);
			return;
		}
		$this->http->get('https://api.github.com/repos/Nadybot/nadybot/git/trees/unstable')
			->withQueryParams(["recursive" => 1])
			->withTimeout(60)
			->withHeader("Accept", "application/vnd.github.v3+json")
			->withCallback([$this, "updateCSVFiles"], $sendto);
	}

	public function updateCSVFiles(HttpResponse $response, CommandReply $sendto): void {
		if ($response === null || $response->headers["status-code"] !== "200") {
			$error = $response->error ?: $response->body;
			try {
				$error = json_decode($error, false, 512, JSON_THROW_ON_ERROR);
				$error = $error->message;
			} catch (Throwable $e) {
				// Ignore it if not json
			}
			$sendto->reply("Error downloading the file list: {$error}");
			return;
		}
		try {
			$data = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$sendto->reply("Invalid data received from GitHub");
			return;
		}

		$updates = [];
		$todo = 0;
		foreach ($data->tree as $file) {
			if (!preg_match("/\.csv$/", $file->path)) {
				continue;
			}
			$localHash = $this->getGitHash($file->path);
			if ($localHash === $file->sha) {
				continue;
			}
			$updates[$file->path] = null;
			$todo++;
			$this->checkIfCanUpdateCsvFile(
				function(string $path, $result) use (&$updates, &$todo, $sendto) {
					$updates[$path] = $result;
					$todo--;
					if ($todo > 0) {
						return;
					}
					$msgs = [];
					foreach ($updates as $file => $result) {
						if ($result === false) {
							continue;
						}
						if ($result === true) {
							$msgs []= basename($file) . " was updated.";
						} elseif (is_string($result)) {
							$msgs []= $result;
						}
					}
					if (count($msgs)) {
						$sendto->reply(join("\n", $msgs));
					} else {
						$sendto->reply("Your database is already up-to-date.");
					}
				},
				$file->path
			);
		}
		if (count($updates) === 0) {
			$sendto->reply("No updates available right now.");
		}
	}

	protected function checkIfCanUpdateCsvFile(callable $callback, string $file): void {
		$this->http->get("https://api.github.com/repos/Nadybot/Nadybot/commits")
			->withQueryParams([
				"path" => $file,
				"page" => 1,
				"per_page" => 1,
			])
			->withTimeout(60)
			->withCallback([$this, "checkDateAndUpdateCsvFile"], $callback, $file);
	}

	public function checkDateAndUpdateCsvFile(HttpResponse $response, callable $callback, string $file): void {
		if ($response === null || $response->headers["status-code"] !== "200") {
			$callback($file, "Could not request last commit date for {$file}.");
			return;
		}
		try {
			$data = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$callback($file, "Error decoding the JSON data from GitHub for {$file}.");
			return;
		}
		$gitModified = DateTime::createFromFormat(
			DateTime::ISO8601,
			$data[0]->commit->committer->date
		);
		$localModified = @filemtime(BotRunner::getBasedir() . "/" . $file);
		if ($localModified === false || $localModified >= $gitModified->getTimestamp()) {
			$callback($file, false);
			return;
		}
		$this->http->get("https://raw.githubusercontent.com/Nadybot/Nadybot/unstable/{$file}")
			->withTimeout(60)
			->withHeader("Range", "bytes=0-1023")
			->withCallback([$this, "checkAndUpdateCsvFile"], $callback, $file, $gitModified);
	}

	public function checkAndUpdateCsvFile(HttpResponse $response, callable $callback, string $file, DateTime $gitModified): void {
		if ($response === null || $response->headers["status-code"] !== "206") {
			$callback($file, "Could not get the header of {$file} from GitHub.");
			return;
		}
		$fileBase = basename($file, '.csv');
		$settingName = strtolower("{$fileBase}_db_version");
		if (!$this->settingManager->exists($settingName)) {
			$callback($file, false);
			return;
		}
		$setting = $this->db->table(SettingManager::DB_TABLE)
			->where("name", $settingName)
			->asObj()
			->first();
		if (!isset($setting)) {
			$callback($file, false);
			return;
		}
		if (preg_match("/^#\s*Requires:\s*(.+)$/m", $response->body, $matches)) {
			if (!$this->db->hasAppliedMigration($setting->module, trim($matches[1]))) {
				$callback(
					$file,
					"The new version for {$file} cannot be applied, because you require ".
					"an update to your SQL schema first."
				);
				return;
			}
		}
		if (preg_match("/^\d+$/", $setting->value)) {
			if ((int)$setting->value >= $gitModified->getTimestamp()) {
				$callback($file, false);
				return;
			}
		}
		$this->http->get("https://raw.githubusercontent.com/Nadybot/Nadybot/unstable/{$file}")
			->withTimeout(60)
			->withCallback([$this, "updateCsvFile"], $callback, $setting->module, $file, $gitModified);
	}

	public function updateCsvFile(HttpResponse $response, callable $callback, string $module, string $file, DateTime $gitModified): void {
		if ($response === null || $response->headers["status-code"] !== "200") {
			$callback($file, "Couldn't download {$file} from GitHub.");
			return;
		}
		$tmpFile = tempnam(sys_get_temp_dir(), $file);
		if ($tmpFile !== false) {
			if (file_put_contents($tmpFile, $response->body)) {
				@touch($tmpFile, $gitModified->getTimestamp());
				try {
					$this->db->beginTransaction();
					$this->db->loadCSVFile($module, $tmpFile);
					$this->db->commit();
					$callback($file, true);
					return;
				} catch (Throwable $e) {
					$this->db->rollback();
					$callback($file, "There was an SQL error loading the CSV file {$file}, please check your logs.");
				} finally {
					@unlink($tmpFile);
				}
			}
		}
		@unlink($tmpFile);
		$callback($file, "Unable to save {$file} into a temporary file for updating.");
	}
}

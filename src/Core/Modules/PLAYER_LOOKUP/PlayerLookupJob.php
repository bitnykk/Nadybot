<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Illuminate\Support\Collection;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Nadybot;
use Nadybot\Core\QueryBuilder;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Timer;

class PlayerLookupJob {
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var Collection<Player> */
	public Collection $toUpdate;

	protected int $numActiveThreads = 0;

	/**
	 * Get a list of character names in need of updates
	 * @return Collection<Player>
	 */
	public function getOudatedCharacters(): Collection {
		return $this->db->table("players")
			->where("last_update", "<", time() - PlayerManager::CACHE_GRACE_TIME)
			->asObj(Player::class);
	}

	/**
	 * Get a list of character names who are alts without info
	 * @return Collection<Player>
	 */
	public function getMissingAlts(): Collection {
		return $this->db->table("alts")
			->whereNotExists(function (QueryBuilder $query): void {
				$query->from("players")
					->whereColumn("alts.alt", "players.name");
			})->select("alt")
			->asObj()
			->pluck("alt")
			->map(function (string $alt): Player {
				$result = new Player();
				$result->name = $alt;
				$result->dimension = $this->db->getDim();
				return $result;
			});
	}

	/** Start the lookup job and call the callback when done */
	public function run(callable $callback, ...$args): void {
		$numJobs = $this->settingManager->getInt('lookup_jobs');
		if ($numJobs === 0) {
			$callback(...$args);
			return;
		}
		$this->toUpdate = $this->getMissingAlts()
			->concat($this->getOudatedCharacters());
		$this->logger->log('DEBUG', $this->toUpdate->count() . " missing / outdated characters found.");
		for ($i = 0; $i < $numJobs; $i++) {
			$this->numActiveThreads++;
			$this->logger->log('DEBUG', 'Spawning lookup thread #' . $this->numActiveThreads);
			$this->startThread($i+1, $callback, ...$args);
		}
	}

	public function startThread(int $threadNum, callable $callback, ...$args): void {
		if ($this->toUpdate->isEmpty()) {
			$this->logger->log('TRACE', "[Thread #{$threadNum}] Queue empty, stopping thread.");
			$this->numActiveThreads--;
			if ($this->numActiveThreads === 0) {
				$this->logger->log('DEBUG', "[Thread #{$threadNum}] All threads stopped, calling callback.");
				$callback(...$args);
			}
			return;
		}
		/** @var Player */
		$todo = $this->toUpdate->shift();
		$this->logger->log('TRACE', "[Thread #{$threadNum}] Looking up " . $todo->name);
		$this->chatBot->getUid(
			$todo->name,
			[$this, "asyncPlayerLookup"],
			$threadNum,
			$todo,
			$callback,
			...$args
		);
	}

	public function asyncPlayerLookup(?int $uid, int $threadNum, Player $todo, callable $callback, ...$args): void {
		if ($uid === null) {
			$this->logger->log('TRACE', "[Thread #{$threadNum}] Player " . $todo->name . ' is inactive, not updating.');
			$this->timer->callLater(0, [$this, "startThread"], $threadNum, $callback, ...$args);
			return;
		}
		$this->logger->log('TRACE', "[Thread #{$threadNum}] Player " . $todo->name . ' is active, querying PORK.');
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($callback, $args, $todo, $threadNum): void {
				$this->logger->log(
					'TRACE',
					"[Thread #{$threadNum}] PORK lookup for " . $todo->name . ' done, '.
					(isset($player) ? 'data updated' : 'no data found')
				);
				$this->timer->callLater(1, [$this, "startThread"], $threadNum, $callback, ...$args);
			},
			$todo->name,
			$todo->dimension
		);
	}
}

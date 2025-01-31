<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE\Migrations;

use Exception;
use Nadybot\Core\Annotations\Setting;
use Nadybot\Core\Modules\DISCORD\DiscordChannel;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\DBSchema\RouteHopColor;
use Nadybot\Core\DBSchema\RouteHopFormat;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Modules\DISCORD\DiscordAPIClient;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SchemaMigration;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\TOWER_MODULE\TowerController;

class MigrateToRoutes implements SchemaMigration {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Inject */
	public TowerController $towerController;

	/** @Inject */
	public MessageHub $messageHub;

	protected function getSetting(DB $db, string $name): ?Setting {
		return $db->table(SettingManager::DB_TABLE)
			->where("name", $name)
			->asObj(Setting::class)
			->first();
	}

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$towerColor = $this->getSetting($db, "tower_spam_color");
		if (isset($towerColor)
			&& preg_match("/#([0-9A-F]{6})/", $towerColor->value, $matches)
		) {
			$towerColor = $matches[1];
		} else {
			$towerColor = "F06AED";
		}
		$hopColor = new RouteHopColor();
		$hopColor->hop = Source::SYSTEM . "(tower-*)";
		$hopColor->tag_color = $towerColor;
		$hopColor->text_color = null;
		$db->insert(MessageHub::DB_TABLE_COLORS, $hopColor);

		$hopFormat = new RouteHopFormat();
		$hopFormat->hop = Source::SYSTEM . "(tower-*)";
		$hopFormat->format = "TOWER";
		$hopFormat->render = true;
		$db->insert(Source::DB_TABLE, $hopFormat);

		$this->messageHub->loadTagColor();
		$this->messageHub->loadTagFormat();

		$table = MessageHub::DB_TABLE_ROUTES;
		$showWhere = $this->getSetting($db, "tower_spam_target");
		if (!isset($showWhere)) {
			if (strlen($this->chatBot->vars['my_guild']??"")) {
				$showWhere = 2;
			} else {
				$showWhere = 1;
			}
		} else {
			$showWhere = (int)$showWhere->value;
		}
		$map = [
			1 => Source::PRIV . "({$this->chatBot->vars['name']})",
			2 => Source::ORG,
		];
		foreach ($map as $flag => $dest) {
			if (($showWhere & $flag) === 0) {
				continue;
			}
			foreach (["tower-attack", "tower-victory"] as $type) {
				$route = new Route();
				$route->source = Source::SYSTEM . "({$type})";
				$route->destination = $dest;
				$db->insert($table, $route);
			}
		}
		$notifyChannel = $this->getSetting($db, "discord_notify_channel");
		if (!isset($notifyChannel) || $notifyChannel->value === "off") {
			return;
		}
		$this->discordAPIClient->getChannel(
			$notifyChannel->value,
			[$this, "migrateChannelToRoute"],
			$db,
			($showWhere & 4) > 0
		);
	}

	public function migrateChannelToRoute(DiscordChannel $channel, DB $db, bool $defaults): void {
		$types = [];
		if ($defaults) {
			$types = ["tower-attack", "tower-victory"];
		}
		$showWhere = $this->getSetting($db, "discord_notify_org_attacks");
		if (isset($showWhere) && $showWhere->value !== "off") {
			$types []= "tower-attack-own";
		}
		foreach ($types as $type) {
			$route = new Route();
			$route->source = Source::SYSTEM . "({$type})";
			$route->destination = Source::DISCORD_PRIV . "({$channel->name})";
			$route->id = $db->insert(MessageHub::DB_TABLE_ROUTES, $route);
			try {
				$msgRoute = $this->messageHub->createMessageRoute($route);
				$this->messageHub->addRoute($msgRoute);
			} catch (Exception $e) {
				// Ain't nothing we can do, errors will be given on next restart
			}
		}
	}
}

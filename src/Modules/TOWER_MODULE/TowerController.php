<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Closure;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	CommandReply,
	DB,
	DBSchema\Player,
	Event,
	EventManager,
	Http,
	HttpResponse,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Modules\DISCORD\DiscordController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	QueryBuilder,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\{
	HELPBOT_MODULE\Playfield,
	HELPBOT_MODULE\PlayfieldController,
	LEVEL_MODULE\LevelController,
	TIMERS_MODULE\Alert,
	TIMERS_MODULE\TimerController,
};
use Throwable;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'towerstats',
 *		accessLevel = 'all',
 *		description = 'Show how many towers each faction has lost',
 *		help        = 'towerstats.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'attacks',
 *      alias       = 'battles',
 *		accessLevel = 'all',
 *		description = 'Show the last Tower Attack messages',
 *		help        = 'attacks.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'lc',
 *		accessLevel = 'all',
 *		description = 'Show status of towers',
 *		help        = 'lc.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'opentimes',
 *		accessLevel = 'guild',
 *		description = 'Show status of towers',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'penalty',
 *		accessLevel = 'all',
 *		description = 'Show orgs in penalty',
 *		help        = 'penalty.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'remscout',
 *		accessLevel = 'guild',
 *		description = 'Remove tower info from watch list',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'scout',
 *		accessLevel = 'guild',
 *		description = 'Add tower info to watch list',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'needsscout',
 *		alias       = 'needscout',
 *		accessLevel = 'guild',
 *		description = 'Check which tower sites need scouting',
 *		help        = 'scout.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'hot',
 *		accessLevel = 'guild',
 *		description = 'Check which sites are or will be attackable soon',
 *		help        = 'hot.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'victory',
 *		accessLevel = 'all',
 *		description = 'Show the last Tower Battle results',
 *		help        = 'victory.txt',
 *		alias       = 'victories'
 *	)
 *  @ProvidesEvent("tower(attack)")
 *  @ProvidesEvent("tower(win)")
 */
class TowerController {

	public const DB_TOWER_ATTACK = "tower_attack_<myname>";
	public const DB_TOWER_VICTORY = "tower_victory_<myname>";
	public const TYPE_LEGACY = 0;
	public const FIXED_TIMES = [
		1 => [4, 22, 3],
		2 => [20, 14, 19],
	];

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public PlayfieldController $playfieldController;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DiscordController $discordController;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public LevelController $levelController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public TimerController $timerController;

	/** @var AttackListener[] */
	protected array $attackListeners = [];

	/** @var array<string,array<int,?string>> */
	protected array $lcOwningFactions = [];

	/**
	 * @Setting("tower_attack_spam")
	 * @Description("Layout types when displaying tower attacks")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("off;compact;normal")
	 * @Intoptions("0;1;2")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerAttackSpam = 2;

	/**
	 * @Setting("tower_page_size")
	 * @Description("Number of results to display for victory/attacks")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("5;10;15;20;25")
	 * @Intoptions("5;10;15;20;25")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerPageSize = 15;

	/**
	 * @Setting("tower_plant_timer")
	 * @Description("Start a timer for planting whenever a tower site goes down")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("off;priv;org")
	 * @Intoptions("0;1;2")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerPlantTimer = 0;

	/**
	 * @Setting("discord_notify_org_attacks")
	 * @Description("Notify message for Discord if being attacked")
	 * @Visibility("edit")
	 * @Type("text")
	 * @Options("off;@here Our field in {location} is being attacked by {player}")
	 * @AccessLevel("mod")
	 */
	public $defaultDiscordNotifyOrgAttacks = "@here Our field in {location} is being attacked by {player}";

	public int $lastDiscordNotify = 0;

	public const TIMER_NAME = "Towerbattles";

	/**
	 * Adds listener callback which will be called when tower attacks occur.
	 */
	public function registerAttackListener(callable $callback, $data=null): void {
		$listener = new AttackListener();
		$listener->callback = $callback;
		$listener->data = $data;
		$this->attackListeners []= $listener;
	}

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/tower_site.csv');

		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"tower_spam_target",
		// 	"Where to send tower messages to",
		// 	"edit",
		// 	"options",
		// 	"2",
		// 	"Off;Priv;Guild;Priv+Guild;Discord;Discord+Priv;Discord+Guild;Discord+Priv+Guild",
		// 	"0;1;2;3;4;5;6;7"
		// );

		// $this->settingManager->add(
		// 	$this->moduleName,
		// 	"tower_spam_color",
		// 	"What color to use for tower messages",
		// 	"edit",
		// 	"color",
		// 	"<font color=#F06AED>"
		// );
		$attack = new class implements MessageEmitter {
			public function getChannelName(): string {
				return Source::SYSTEM . "(tower-attack)";
			}
		};
		$attackOwn = new class implements MessageEmitter {
			public function getChannelName(): string {
				return Source::SYSTEM . "(tower-attack-own)";
			}
		};
		$victory = new class implements MessageEmitter {
			public function getChannelName(): string {
				return Source::SYSTEM . "(tower-victory)";
			}
		};
		$this->messageHub->registerMessageEmitter($attack)
			->registerMessageEmitter($attackOwn)
			->registerMessageEmitter($victory);
	}

	/**
	 * @Event("timer(30min)")
	 * @Description("Download factions owning towers")
	 */
	public function downloadFactionsOwningTowerSites() {
		$this->http
				->get('http://echtedomain.club/lc.php')
				->withTimeout(20)
				->withCallback([$this, 'parseFactionsOwningTowerSites']);
	}

	public function parseFactionsOwningTowerSites(HttpResponse $response): void {
		if (isset($response->error)) {
			return;
		}
		try {
			$sites = @json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return;
		}
		if (!is_array($sites)) {
			return;
		}
		foreach ($sites as $pf => $lcs) {
			$this->lcOwningFactions[$pf] = [];
			foreach ($lcs as $num => $faction) {
				if (isset($faction)) {
					$this->lcOwningFactions[$pf][(int)substr($num, 1)] = ucfirst(strtolower($faction));
				}
			}
		}
	}

	/**
	 * This command handler shows the last tower attack messages.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks (\d+)$/i")
	 * @Matches("/^attacks$/i")
	 */
	public function attacksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$page = $args[1] ?? 1;
		$this->attacksCommandHandler((int)$page, null, '', $sendto);
	}

	/**
	 * This command handler shows the last tower attack messages by site number
	 * and optionally by page.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks (?!org|player)([a-z0-9]+) (\d+) (\d+)$/i")
	 * @Matches("/^attacks (?!org|player)([a-z0-9]+) (\d+)$/i")
	 */
	public function attacks2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfield = $this->playfieldController->getPlayfieldByName($args[1]);
		if ($playfield === null) {
			$msg = "<highlight>{$args[1]}<end> is not a valid playfield.";
			$sendto->reply($msg);
			return;
		}

		$towerInfo = $this->getTowerInfo($playfield->id, (int)$args[2]);
		if ($towerInfo === null) {
			$msg = "<highlight>{$playfield->long_name}<end> doesn't have a site <highlight>X{$args[2]}<end>.";
			$sendto->reply($msg);
			return;
		}

		$cmd = "$args[1] $args[2] ";
		$search = function (QueryBuilder $query) use ($towerInfo) {
			$query->where("a.playfield_id", $towerInfo->playfield_id)
				->where("a.site_number", $towerInfo->site_number);
		};
		$page = $args[3] ?? 1;
		$this->attacksCommandHandler((int)$page, $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower attack messages where given
	 * org has been an attacker or defender.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks org (.+) (\d+)$/i")
	 * @Matches("/^attacks org (.+)$/i")
	 */
	public function attacksOrgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "org $args[1] ";
		$search = function (QueryBuilder $query) use ($args) {
			$query->whereIlike("a.att_guild_name", $args[1])
				->orWhereIlike("a.def_guild_name", $args[1]);
		};
		$this->attacksCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower attack messages where given
	 * player has been as attacker.
	 *
	 * @HandlesCommand("attacks")
	 * @Matches("/^attacks player (.+) (\d+)$/i")
	 * @Matches("/^attacks player (.+)$/i")
	 */
	public function attacksPlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "player $args[1] ";
		$search = function (QueryBuilder $query) use ($args) {
			$query->whereIlike("a.att_player", $args[1]);
		};
		$this->attacksCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows status of towers.
	 *
	 * @HandlesCommand("lc")
	 * @Matches("/^lc$/i")
	 */
	public function lcCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Collectionn<Playfield> */
		$playfields = $this->db->table("tower_site AS t")
			->join("playfields AS p", "p.id", "t.playfield_id")
			->orderBy("p.short_name")
			->select("p.*")->distinct()
			->asObj(Playfield::class);

		$blob = "<header2>Playfields with notum fields<end>\n";
		foreach ($playfields as $pf) {
			$baseLink = $this->text->makeChatcmd($pf->long_name, "/tell <myname> lc $pf->short_name");
			$blob .= "<tab>$baseLink <highlight>($pf->short_name)<end>\n";
		}
		$msg = $this->text->makeBlob('Land Control Index', $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows status of towers.
	 *
	 * @HandlesCommand("lc")
	 * @Matches("/^lc ([0-9a-z]+[a-z])$/i")
	 */
	public function lc2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfieldName = strtoupper($args[1]);
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Playfield <highlight>$playfieldName<end> could not be found.";
			$sendto->reply($msg);
			return;
		}

		/** @var Collection<SiteInfo> */
		$data = $this->db->table("tower_site AS t")
			->join("playfields AS p", "t.playfield_id", "p.id")
			->where("t.playfield_id", $playfield->id)
			->asObj(SiteInfo::class);
		if ($data->isEmpty()) {
			$msg = "Playfield <highlight>$playfield->long_name<end> does not have any tower sites.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		foreach ($data as $row) {
			$blob .= "<pagebreak>" . $this->formatSiteInfo($row) . "\n\n";
		}

		$msg = $this->text->makeBlob("All Bases in $playfield->long_name", $blob);

		$sendto->reply($msg);
	}

	/**
	 * This command handler shows status of towers.
	 *
	 * @HandlesCommand("lc")
	 * @Matches("/^lc ([0-9a-z]+[a-z])\s*(\d+)$/i")
	 */
	public function lc3Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfieldName = strtoupper($args[1]);
		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Playfield <highlight>$playfieldName<end> could not be found.";
			$sendto->reply($msg);
			return;
		}

		$siteNumber = (int)$args[2];
		/** @var ?SiteInfo */
		$site = $this->db->table("tower_site AS t")
			->join("playfields AS p", "p.id", "t.playfield_id")
			->where("t.playfield_id", $playfield->id)
			->where("t.site_number", $siteNumber)
			->asObj(SiteInfo::class)->first();
		if ($site === null) {
			$msg = "Invalid site number.";
			$sendto->reply($msg);
			return;
		}
		$blob = $this->formatSiteInfo($site) . "\n\n";

		// show last attacks and victories
		$query = $this->db->table(self::DB_TOWER_ATTACK, "a")
			->leftJoin(self::DB_TOWER_VICTORY . " AS v", "v.attack_id", "a.id")
			->where("a.playfield_id", $playfield->id)
			->where("a.site_number", $siteNumber)
			->orderByDesc("dt")
			->limit(10)
			->select("a.*", "v.*");
		$query->select($query->colFunc("COALESCE", ["v.time", "a.time"], "dt"));
		/** @var Collection<TowerAttackAndVictory> */
		$attacks = $query->asObj(TowerAttackAndVictory::class);
		if ($attacks->isNotEmpty()) {
			$blob .= "<header2>Recent Attacks<end>\n";
		}
		foreach ($attacks as $attack) {
			if (empty($attack->attack_id)) {
				// attack
				if (!empty($attack->att_guild_name)) {
					$name = $attack->att_guild_name;
				} else {
					$name = $attack->att_player;
				}
				$blob .= "<tab><$attack->att_faction>$name<end> attacked <$attack->def_faction>$attack->def_guild_name<end>\n";
			} else {
				// victory
				$blob .= "<tab><$attack->win_faction>$attack->win_guild_name<end> won against <$attack->lose_faction>$attack->lose_guild_name<end>\n";
			}
		}

		$msg = $this->text->makeBlob("$playfield->short_name $siteNumber", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("opentimes")
	 * @Matches("/^opentimes$/i")
	 */
	public function openTimesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$query = $this->db->table("scout_info")
			->groupBy("guild_name")
			->orderBy("guild_name");
		$data = $query->select("guild_name", $query->colFunc("SUM", "ct_ql", "total_ql"))
			->asObj();
		$contractQls = [];
		foreach ($data as $row) {
			$contractQls[$row->guild_name] = (int)$row->total_ql;
		}

		$data = $this->db->table("tower_site AS t")
			->join("scout_info AS s", function (JoinClause $join) {
				$join->on("t.playfield_id", "s.playfield_id")
					->on("s.site_number", "t.site_number");
			})->join("playfields AS p", "t.playfield_id", "p.id")
			->orderBy("guild_name")
			->orderByDesc("ct_ql")
			->asObj();

		if (count($data) > 0) {
			$blob = '';
			$currentGuildName = '';
			foreach ($data as $row) {
				if ($row->guild_name !== $currentGuildName) {
					$contractQl = $contractQls[$row->guild_name];
					$contractQl = ($contractQl * 2);
					$faction = strtolower($row->faction);

					$blob .= "\n<u><$faction>$row->guild_name<end></u> (Total Contract QL: $contractQl)\n";
					$currentGuildName = $row->guild_name;
				}
				$gasInfo = $this->getGasLevel((int)$row->close_time);
				$gasChangeString = "{$gasInfo->color}{$gasInfo->gas_level}<end> - ".
					"{$gasInfo->next_state} in <highlight>".
					$this->util->unixtimeToReadable($gasInfo->gas_change).
					"<end>";

				$siteLink = $this->text->makeChatcmd(
					"$row->short_name $row->site_number",
					"/tell <myname> lc $row->short_name $row->site_number"
				);
				$openTime = $row->close_time - (3600 * 6);
				if ($openTime < 0) {
					$openTime += 86400;
				}

				$blob .= "<tab>$siteLink - {$row->min_ql}-{$row->max_ql}, $row->ct_ql CT, $gasChangeString [by $row->scouted_by]\n";
			}

			$msg = $this->text->makeBlob("Scouted Bases", $blob);
		} else {
			$msg = "No sites currently scouted.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows orgs in penalty.
	 *
	 * @HandlesCommand("penalty")
	 * @Matches("/^penalty$/i")
	 * @Matches("/^penalty ([a-z0-9]+)$/i")
	 */
	public function penaltyCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$budatime = '2h';
		if (count($args) === 2) {
			$budatime = $args[1];
		}

		$time = $this->util->parseTime($budatime);
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$sendto->reply($msg);
			return;
		}

		$penaltyTimeString = $this->util->unixtimeToReadable($time, false);

		$orgs = $this->getSitesInPenalty(time() - $time);

		if (count($orgs) === 0) {
			$msg = "There are no orgs who have attacked or won battles in the past $penaltyTimeString.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		$currentFaction = '';
		foreach ($orgs as $org) {
			if ($currentFaction !== $org->att_faction) {
				$blob .= "\n<header2>{$org->att_faction}<end>\n";
				$currentFaction = $org->att_faction;
			}
			$timeString = $this->util->unixtimeToReadable(time() - $org->penalty_time, false);
			$blob .= "<tab><{$org->att_faction}>{$org->att_guild_name}<end> - $timeString ago\n";
		}
		$msg = $this->text->makeBlob("Orgs in penalty ($penaltyTimeString)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("needsscout")
	 * @Matches("/^needsscout$/i")
	 * @Matches("/^needsscout (?<pf>.+)$/i")
	 */
	public function needsScoutCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$query = $this->db->table("tower_site AS t")
			->leftJoin("scout_info AS s", function (JoinClause $join) {
				$join->on("t.playfield_id", "s.playfield_id")
					->on("s.site_number", "t.site_number");
			})->join("playfields AS p", "t.playfield_id", "p.id")
			->select("t.*", "p.*")
			->whereNull("s.playfield_id")
			->orderBy("p.short_name")
			->orderBy("t.site_number");
		if (isset($args["pf"])) {
			$pf = $this->playfieldController->getPlayfieldByName($args["pf"]);
			if (!isset($pf)) {
				$sendto->reply("Unable to find playfield <highlight>{$args['pf']}<end>.");
				return;
			}
			$query->where("p.id", $pf->id);
		}
		$data = $query->asObj(SiteInfo::class);
		if ($data->count() === 0) {
			$sendto->reply("No sites need scouting right now.");
			return;
		}
		$groups = $data->groupBy("short_name");
		$blob = $groups->map([$this, "formatSiteGroup"])
			->join("\n\n");

		$sendto->reply(
			$groups->count() > 1
				? $this->text->makeBlob(
					"Sites in need of scouting (" . $data->count() . ")",
					$blob
				)
				: str_replace("<pagebreak>", "", $blob)
		);
	}

	/**
	 * @param Collection<SiteInfo> $siteGroup
	 * @param string $shortName
	 * @return string
	 */
	public function formatSiteGroup(Collection $siteGroup, string $shortName): string {
		$siteLinks = $siteGroup->map(function(SiteInfo $site): string {
			$shortName = $site->short_name . " " . $site->site_number;
			$siteLink = $this->text->makeChatcmd(
				$shortName,
				"/tell <myname> <symbol>lc {$shortName}"
			);
			return $siteLink;
		})->join(", ");
		return "<pagebreak><header2>{$siteGroup[0]->long_name}<end>\n".
			"<tab>{$siteLinks}";
	}

	/**
	 * @HandlesCommand("hot")
	 * @Matches("/^hot$/i")
	 * @Matches("/^hot (?<faction>omni|neutral|clan)$/i")
	 * @Matches("/^hot (?<pf>[0-9a-z]+[a-z])$/i")
	 * @Matches("/^hot (?<pf>[0-9a-z]+[a-z]) (?<faction>omni|neutral|clan)$/i")
	 * @Matches("/^hot (?<faction>omni|neutral|clan) (?<pf>[0-9a-z]+[a-z])$/i")
	 */
	public function hotSitesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sites = $this->getSitesWithKnownTimer()
			->filter(function (HotSite $site): bool {
				$gas = $this->getGasLevel($site->info->close_time);
				return $gas->gas_level < 75;
			});
		if (isset($args['faction'])) {
			$sites = $sites->filter(function (HotSite $site) use ($args): bool {
				return $site->info->faction === ucfirst(strtolower($args['faction']));
			});
		}
		if (isset($args["pf"])) {
			$pf = $this->playfieldController->getPlayfieldByName($args["pf"]);
			if (!isset($pf)) {
				$sendto->reply("Unable to find playfield <highlight>{$args['pf']}<end>.");
				return;
			}
			$sites = $sites->filter(function (HotSite $site) use ($pf): bool {
				return $site->playfield_id === $pf->id;
			});
		}
		if ($sites->count() === 0) {
			$sendto->reply("No sites are currently hot.");
			return;
		}
		$blob = $this->renderHotSites($sites);
		$faction = isset($args['faction']) ? " " . strtolower($args['faction']) : "";
		$sendto->reply(
			$this->text->makeBlob(
				"Hot{$faction} sites (" . $sites->count() .")",
				$blob
			)
		);
	}

	/**
	 * @param Collection<HotSite> $site
	 * @return string[]
	 */
	public function renderHotSites(Collection $sites): string {
		$grouped = $sites->groupBy("short_name");
		$blob = $grouped->map(function (Collection $sites, string $short): string {
			return "<header2>{$sites[0]->long_name}<end>\n".
				$sites->map(function (HotSite $site): string {
					$shortName = $site->short_name . " " . $site->site_number;
					$line = "<tab>".
						$this->text->makeChatcmd(
							$shortName,
							"/tell <myname> <symbol>lc {$shortName}"
						);
					$factionColor = "";
					if ($site->info->faction) {
						$factionColor = "<" . strtolower($site->info->faction) . ">";
						$org = $site->info->guild_name ?? $site->info->faction;
						$line .= " {$factionColor}{$org}<end>";
					} else {
						$line .= " &lt;Free or unknown planter&gt;";
					}
					$gas = $this->getGasLevel($site->info->close_time);
					$line .= " {$gas->color}{$gas->gas_level}<end>, {$gas->next_state} in ".
						$this->util->unixtimeToReadable($gas->gas_change, false);
					return $line;
				})->join("\n");
		})->join("\n\n");
		return $blob;
	}

	/**
	 * Get a list of all sites with known closing time
	 *
	 * @return Collection<HotSite>
	 */
	public function getSitesWithKnownTimer(): Collection {
		$data = $this->db->table("tower_site", "t")
			->leftJoin("scout_info AS s", function (JoinClause $join) {
				$join->on("t.playfield_id", "s.playfield_id")
					->on("s.site_number", "t.site_number");
			})->join("playfields AS p", "t.playfield_id", "p.id")
			->orderBy("p.short_name")
			->orderBy("t.site_number")
			->select("t.*", "p.*", "s.ct_ql", "s.guild_name", "s.faction", "s.close_time")
			->asObj(HotSite::class);
		$data->each(function (HotSite $site) {
			$site->info = new HotInfo();
			$site->info->ct_ql = $site->ct_ql ? (int)$site->ct_ql : null;
			$site->info->guild_name = $site->guild_name ? (string)$site->guild_name : null;
			$site->info->faction = $site->faction ? (string)$site->faction : null;
			$site->info->close_time = $site->close_time ? (int)$site->close_time : null;
			foreach (['ct_ql', 'guild_name', 'faction', 'close_time'] as $attr) {
				unset($site->{$attr});
			}
		});
		$data->each(function (HotSite $site) {
			if (isset($site->ct_ql)) {
				return;
			}
			if ($site->timing !== self::TYPE_LEGACY) {
				$site->info->close_time = self::FIXED_TIMES[$site->timing][0] * 3600;
			}
			if (isset($this->lcOwningFactions[$site->long_name][$site->site_number])) {
				$site->info->faction = $this->lcOwningFactions[$site->long_name][$site->site_number];
			}
		});
		$orgsInPenalty = $this->getSitesInPenalty();
		// All fields of orgs that attacked another org in the last 2h
		// are hot for 2h
		$data->each(function (HotSite $site) use ($orgsInPenalty) {
			if (!isset($site->info->guild_name)) {
				return;
			}
			foreach ($orgsInPenalty as $org) {
				if ($org->att_guild_name !== $site->info->guild_name) {
					continue;
				}
				$penEnd = $org->penalty_time + 7200;
				$gas = $this->getGasLevel($site->info->close_time);
				if ($gas->close_time < $penEnd) {
					$site->info->close_time = $penEnd % 86400;
				}
			}
		});

		return $data->filter(fn($site) => isset($site->info->close_time));
	}

	/**
	 * This command handler removes tower info to watch list.
	 *
	 * @HandlesCommand("remscout")
	 * @Matches("/^remscout ([a-z0-9]+) (\d+)$/i")
	 */
	public function remscoutCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfieldName = $args[1];
		$siteNumber = (int)$args[2];

		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$msg = "Invalid playfield.";
			$sendto->reply($msg);
			return;
		}

		$towerInfo = $this->getTowerInfo($playfield->id, $siteNumber);
		if ($towerInfo === null) {
			$msg = "Invalid site number.";
			$sendto->reply($msg);
			return;
		}

		$numDeleted = $this->remScoutSite($playfield->id, $siteNumber);

		if ($numDeleted === 0) {
			$msg = "Could not find a scout record for <highlight>{$playfield->short_name} {$siteNumber}<end>.";
		} else {
			$msg = "<highlight>{$playfield->short_name} {$siteNumber}<end> removed successfully.";
		}
		$sendto->reply($msg);
	}

	protected function scoutInputHandler(string $sender, CommandReply $sendto, array $args): void {
		if (count($args) === 7) {
			$playfieldName = $args[1];
			$siteNumber = (int)$args[2];
			$closingTime = $args[3];
			$ctQL = (int)$args[4];
			$faction = $this->getFaction($args[5]);
			$guildName = $args[6];
		} else {
			$pattern = "@Control Tower - ([^ ]+)\s+Level: (\d+)\s+Danger level: (.+)\s+Alignment: ([^ ]+)\s+Organization: (.+)\s+Created at UTC: ([^ ]+) ([^ ]+)@si";
			if (preg_match($pattern, $args[3], $arr)) {
				$playfieldName = $args[1];
				$siteNumber = (int)$args[2];
				$closingTime = $arr[7];
				$ctQL = (int)$arr[2];
				$faction = $this->getFaction($arr[1]);
				$guildName = $arr[5];
			} else {
				return;
			}
		}

		$msg = $this->addScoutInfo($sender, $playfieldName, $siteNumber, $closingTime, $ctQL, $faction, $guildName);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("scout")
	 * @Matches("/^scout ([0-9a-z]+[a-z])\s*(\d+)\s+(.*)$/is")
	 */
	public function scoutCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->scoutInputHandler($sender, $sendto, $args);
	}

	public function addScoutInfo(string $sender, string $playfieldName, int $siteNumber, string $plantTime, int $ctQL, string $faction, string $guildName): string {
		if (!in_array($faction, ['Omni', 'Neutral', 'Clan'])) {
			return "Valid values for faction are: 'Omni', 'Neutral', and 'Clan'.";
		}

		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			return "Invalid playfield <highlight>{$playfieldName}<end>.";
		}

		$towerInfo = $this->getTowerInfo($playfield->id, $siteNumber);
		if ($towerInfo === null) {
			return "Invalid site number <highlight>{$playfield->long_name} {$siteNumber}<end>.";
		}

		if ($ctQL < $towerInfo->min_ql || $ctQL > $towerInfo->max_ql) {
			return "<highlight>$playfield->short_name $towerInfo->site_number<end> ".
				"can only accept Control Tower of ql ".
				"<highlight>{$towerInfo->min_ql}<end>-<highlight>{$towerInfo->max_ql}<end>.";
		}

		$plantTimeArray = explode(':', $plantTime);
		$plantTimeSeconds = (int)$plantTimeArray[0] * 3600 + (int)$plantTimeArray[1] * 60 + (int)$plantTimeArray[2];

		$this->addScoutSite($towerInfo, $plantTimeSeconds, $ctQL, $faction, $guildName, $sender);
		return "Scout info for <highlight>$playfield->short_name $siteNumber<end> has been updated.";
	}

	/**
	 * @HandlesCommand("towerstats")
	 * @Matches("/^towerstats (.+)$/i")
	 * @Matches("/^towerstats$/i")
	 */
	public function towerStatsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$budatime = "1d";
		if (count($args) === 2) {
			$budatime = $args[1];
		}

		$time = $this->util->parseTime($budatime);
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$sendto->reply($msg);
			return;
		}

		$timeString = $this->util->unixtimeToReadable($time);

		$blob = '';

		$query = $this->db->table(self::DB_TOWER_ATTACK)
			->where("time", ">=", time() - $time)
			->groupBy("att_faction")
			->orderBy("att_faction");
		$data = $query->orderBy($query->colFunc("COUNT", "att_faction"))
			->select("att_faction", $query->colFunc("COUNT", "att_faction", "num"))
			->asObj();
		foreach ($data as $row) {
			$blob .= "<{$row->att_faction}>{$row->att_faction}<end> have attacked <highlight>{$row->num}<end> times.\n";
		}
		if ($data->isNotEmpty()) {
			$blob .= "\n";
		}

		$query = $this->db->table(self::DB_TOWER_VICTORY)
			->where("time", ">=", time() - $time)
			->groupBy("lose_faction")
			->orderByDesc("num")
			->select("lose_faction");
		$data = $query->addSelect($query->colFunc("COUNT", "lose_faction", "num"))
			->asObj();
		foreach ($data as $row) {
			$blob .= "<{$row->lose_faction}>{$row->lose_faction}<end> have lost <highlight>{$row->num}<end> tower sites.\n";
		}

		if ($blob == '') {
			$msg = "No tower attacks or victories have been recorded.";
		} else {
			$msg = $this->text->makeBlob("Tower Stats for the Last $timeString", $blob);
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory (\d+)$/i")
	 * @Matches("/^victory$/i")
	 */
	public function victoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$page = (int)($args[1] ?? 1);
		$this->victoryCommandHandler($page, null, "", $sendto);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory (?!org|player)([a-z0-9]+) (\d+) (\d+)$/i")
	 * @Matches("/^victory (?!org|player)([a-z0-9]+) (\d+)$/i")
	 */
	public function victory2Command(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$playfield = $this->playfieldController->getPlayfieldByName($args[1]);
		if ($playfield === null) {
			$msg = "Invalid playfield.";
			$sendto->reply($msg);
			return;
		}

		$towerInfo = $this->getTowerInfo($playfield->id, (int)$args[2]);
		if ($towerInfo === null) {
			$msg = "Invalid site number.";
			$sendto->reply($msg);
			return;
		}

		$cmd = "$args[1] $args[2] ";
		$search = function (QueryBuilder $query) use ($towerInfo) {
			$query->where("a.playfield_id", $towerInfo->playfield_id)
				->where("a.site_number", $towerInfo->site_number);
		};
		$this->victoryCommandHandler((int)($args[3] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory org (.+) (\d+)$/i")
	 * @Matches("/^victory org (.+)$/i")
	 */
	public function victoryOrgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "org $args[1] ";
		$search = function (QueryBuilder $query) use ($args) {
			$query->whereIlike("v.win_guild_name", $args[1])
				->orWhereIlike("v.lose_guild_name", $args[1]);
		};
		$this->victoryCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * This command handler shows the last tower battle results.
	 *
	 * @HandlesCommand("victory")
	 * @Matches("/^victory player (.+) (\d+)$/i")
	 * @Matches("/^victory player (.+)$/i")
	 */
	public function victoryPlayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cmd = "player $args[1] ";
		$search = function (QueryBuilder $query) use ($args) {
			$query->whereIlike("a.att_player", $args[1]);
		};
		$this->victoryCommandHandler((int)($args[2] ?? 1), $search, $cmd, $sendto);
	}

	/**
	 * @Event("orgmsg")
	 * @Description("Notify if org's towers are attacked")
	 */
	public function attackOwnOrgMessageEvent(AOChatEvent $eventObj): void {
		if ($this->util->isValidSender($eventObj->sender)) {
			return;
		}
		if (
			!preg_match(
				"/^The tower (.+?) in (.+?) was just reduced to \d+ % health ".
				"by ([^ ]+) from the (.+?) organization!$/",
				$eventObj->message,
				$matches
			)
			&& !preg_match(
				"/^The tower (.+?) in (.+?) was just reduced to \d+ % health by ([^ ]+)!$/",
				$eventObj->message,
				$matches
			)
			&& !preg_match(
				"/^Your (.+?) tower in (?:.+?) in (.+?) has had its ".
				"defense shield disabled by ([^ ]+) \(.+?\)\.\s*".
				"The attacker is a member of the organization (.+?)\.$/",
				$eventObj->message,
				$matches
			)
		) {
			return;
		}
		$discordMessage = $this->settingManager->getString('discord_notify_org_attacks');
		if (empty($discordMessage) || $discordMessage === "off") {
			return;
		}
		// One notification every 5 minutes seems enough
		if (time() - $this->lastDiscordNotify < 300) {
			return;
		}
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($matches, $discordMessage): void {
				$attGuild = $matches[4] ?? null;
				$attPlayer = $matches[3];
				$playfieldName = $matches[2];
				if ($whois === null) {
					$whois = new Player();
					$whois->type = 'npc';
					$whois->name = $attPlayer;
					$whois->faction = 'Neutral';
				} else {
					$whois->type = 'player';
				}
				$playerName = "<highlight>{$whois->name}<end> ({$whois->faction}";
				if ($attGuild) {
					$playerName .= " org \"{$whois->guild}\"";
				}
				$playerName .= ")";
				$discordMessage = str_replace(
					["{player}", "{location}"],
					[$playerName, $playfieldName],
					$discordMessage
				);
				$r = new RoutableMessage($discordMessage);
				$r->appendPath(new Source(Source::SYSTEM, "tower-attack-own"));
				$this->messageHub->handle($r);
				// $this->discordController->sendDiscord($discordMessage, true);
				$this->lastDiscordNotify = time();
			},
			$matches[3]
		);
	}

	/**
	 * This event handler record attack messages.
	 *
	 * @Event("towers")
	 * @Description("Record attack messages")
	 */
	public function attackMessagesEvent(AOChatEvent $eventObj): void {
		$attack = new Attack();
		if (preg_match(
			"/^The (Clan|Neutral|Omni) organization ".
			"(.+) just entered a state of war! ".
			"(.+) attacked the (Clan|Neutral|Omni) organization ".
			"(.+)'s tower in ".
			"(.+) at location \\((\\d+),(\\d+)\\)\\.$/i",
			$eventObj->message,
			$arr
		)) {
			$attack->attSide = ucfirst(strtolower($arr[1]));  // comes across as a string instead of a reference, so convert to title case
			$attack->attGuild = $arr[2];
			$attack->attPlayer = $arr[3];
			$attack->defSide = ucfirst(strtolower($arr[4]));  // comes across as a string instead of a reference, so convert to title case
			$attack->defGuild = $arr[5];
			$attack->playfieldName = $arr[6];
			$attack->xCoords = (int)$arr[7];
			$attack->yCoords = (int)$arr[8];
		} elseif (preg_match(
			"/^(.+) just attacked the (Clan|Neutral|Omni) organization ".
			"(.+)'s tower in ".
			"(.+) at location \(([0-9]+), ([0-9]+)\).(.*)$/i",
			$eventObj->message,
			$arr
		)) {
			$attack->attPlayer = $arr[1];
			$attack->defSide = ucfirst(strtolower($arr[2]));  // comes across as a string instead of a reference, so convert to title case
			$attack->defGuild = $arr[3];
			$attack->playfieldName = $arr[4];
			$attack->xCoords = (int)$arr[5];
			$attack->yCoords = (int)$arr[6];
		} else {
			return;
		}

		// regardless of what the player lookup says, we use the information from the
		// attack message where applicable because that will always be most up to date
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($attack): void {
				$this->handleAttack($attack, $player);
			},
			$attack->attPlayer
		);
	}

	public function handleAttack(Attack $attack, ?Player $whois): void {
		if ($whois === null) {
			$whois = new Player();
			$whois->type = 'npc';

			// in case it's not a player who causes attack message (pet, mob, etc)
			$whois->name = $attack->attPlayer;
			$whois->faction = 'Neutral';
		} else {
			$whois->type = 'player';
		}
		if (isset($attack->attSide)) {
			$whois->faction = $attack->attSide;
		} else {
			$whois->factionGuess = true;
			$whois->originalGuild = $whois->guild;
		}
		$whois->guild = $attack->attGuild ?? null;

		$playfield = $this->playfieldController->getPlayfieldByName($attack->playfieldName);
		if ($playfield === null) {
			$this->logger->log('error', "ERROR! Could not find Playfield \"{$attack->playfieldName}\"");
			return;
		}
		$closestSite = $this->getClosestSite($playfield->id, $attack->xCoords, $attack->yCoords);

		$defender = new Defender();
		$defender->faction   = $attack->defSide;
		$defender->guild     = $attack->defGuild;
		$defender->playfield = $playfield;
		$defender->site      = $closestSite;

		foreach ($this->attackListeners as $listener) {
			$callback = $listener->callback;
			$callback($whois, $defender, $listener->data);
		}

		if ($closestSite === null) {
			$this->logger->log('error', "ERROR! Could not find closest site: ({$attack->playfieldName}) '{$playfield->id}' '{$attack->xCoords}' '{$attack->yCoords}'");
			$more = "[<red>UNKNOWN AREA!<end>]";
		} else {
			$this->recordAttack($whois, $attack, $closestSite);
			$this->logger->log('debug', "Site being attacked: ({$attack->playfieldName}) '{$closestSite->playfield_id}' '{$closestSite->site_number}'");

			// Beginning of the 'more' window
			$link = "";
			if (isset($whois->factionGuess)) {
				$link .= "<highlight>Warning:<end> The attacker could also be a pet with a fake name!\n\n";
			}
			$link .= "Attacker: <highlight>";
			if (isset($whois->firstname) && strlen($whois->firstname)) {
				$link .= $whois->firstname . " ";
			}

			$link .= '"' . $attack->attPlayer . '"';
			if (isset($whois->lastname) && strlen($whois->lastname)) {
				$link .= " " . $whois->lastname;
			}
			$link .= "<end>\n";

			if (isset($whois->breed) && strlen($whois->breed)) {
				$link .= "Breed: <highlight>$whois->breed<end>\n";
			}
			if (isset($whois->gender) && strlen($whois->gender)) {
				$link .= "Gender: <highlight>$whois->gender<end>\n";
			}

			if (isset($whois->profession) && strlen($whois->profession)) {
				$link .= "Profession: <highlight>$whois->profession<end>\n";
			}
			if (isset($whois->level)) {
				$level_info = $this->levelController->getLevelInfo($whois->level);
				$link .= "Level: <highlight>{$whois->level}/<green>{$whois->ai_level}<end> ({$level_info->pvpMin}-{$level_info->pvpMax})<end>\n";
			}

			$link .= "Alignment: <highlight>{$whois->faction}<end>\n";

			if (isset($whois->guild)) {
				$link .= "Organization: <highlight>$whois->guild<end>\n";
				if (isset($whois->guild_rank)) {
					$link .= "Organization Rank: <highlight>$whois->guild_rank<end>\n";
				}
			}

			$link .= "\n";

			$link .= "Defender: <highlight>{$attack->defGuild}<end>\n";
			$link .= "Alignment: <highlight>{$attack->defSide}<end>\n\n";

			$baseLink = $this->text->makeChatcmd("{$playfield->short_name} {$closestSite->site_number}", "/tell <myname> lc {$playfield->short_name} {$closestSite->site_number}");
			$attackWaypoint = $this->text->makeChatcmd("{$attack->xCoords}x{$attack->yCoords}", "/waypoint {$attack->xCoords} {$attack->yCoords} {$playfield->id}");
			$link .= "Playfield: <highlight>{$baseLink} ({$closestSite->min_ql}-{$closestSite->max_ql})<end>\n";
			$link .= "Location: <highlight>{$closestSite->site_name} ({$attackWaypoint})<end>\n";

			$more = $this->text->makeBlob("{$playfield->short_name} {$closestSite->site_number}", $link, 'Advanced Tower Info');
		}

		$targetOrg = "<".strtolower($attack->defSide).">{$attack->defGuild}<end>";

		// Starting tower message to org/private chat
		$msg = "";
		$likelyFake = isset($whois->factionGuess) && isset($whois->originalGuild) && strlen($whois->originalGuild);
		if ($whois->guild) {
			$msg .= "<".strtolower($whois->faction).">$whois->guild<end>";
		} else {
			$msg .= "<".strtolower($whois->faction).">{$attack->attPlayer}<end>";
		}
		$msg .= " attacked $targetOrg";

		$s = $this->settingManager->getInt("tower_attack_spam");
		// tower_attack_spam >= 2 (normal) includes attacker stats
		if ($s >= 2 && $whois->type !== 'npc' && !$likelyFake) {
			$msg .= " - ".preg_replace(
				"/, <(omni|neutral|clan)>(omni|neutral|clan)<end>/i",
				'',
				preg_replace(
					"/ of <(omni|neutral|clan)>.+?<end>/i",
					'',
					$this->playerManager->getInfo($whois, false)
				)
			);
		} elseif ($s >= 2 && $whois->type !== 'npc') {
			$msg .= " (<highlight>{$whois->level}<end>/<green>{$whois->ai_level}<end> <" . strtolower($whois->faction) . ">{$whois->faction}<end> <highlight>{$whois->profession}<end> or fake name)";
		}

		$msg .= " [$more]";

		if ($s === 0) {
			return;
		}
		$r = new RoutableMessage($msg);
		$r->appendPath(new Source(Source::SYSTEM, "tower-attack"));
		$this->messageHub->handle($r);
	}

	/**
	 * Set a timer to warn 1m, 5s and 0s before you can plant
	 */
	protected function setPlantTimer(string $timerLocation): void {
		$start = time();
		/** @var Alert[] */
		$alerts = [];

		$alert = new Alert();
		$alert->time = $start;
		$alert->message = "Started countdown for planting $timerLocation";
		$alerts []= $alert;

		$alert = new Alert();
		$alert->time = $start + 19*60;
		$alert->message = "<highlight>1 minute<end> remaining to plant $timerLocation";
		$alerts []= $alert;

		$countdown = [5, 4, 3, 2, 1];
		if ($this->settingManager->getInt('tower_plant_timer') === 2) {
			$countdown = [5];
		}
		foreach ($countdown as $remaining) {
			$alert = new Alert();
			$alert->time = $start + 20*60-$remaining;
			$alert->message = "<highlight>${remaining}s<end> remaining to plant ".strip_tags($timerLocation);
			$alerts []= $alert;
		}

		$alertPlant = new Alert();
		$alertPlant->time = $start + 20*60;
		$alertPlant->message = "Plant $timerLocation <highlight>NOW<end>";
		$alerts []= $alertPlant;

		$this->timerController->add(
			"Plant " . strip_tags($timerLocation),
			$this->chatBot->vars['name'],
			$this->settingManager->getInt('tower_plant_timer') === 1 ? "priv": "guild",
			$alerts,
			'timercontroller.timerCallback'
		);
	}

	/**
	 * This event handler record victory messages.
	 *
	 * @Event("towers")
	 * @Description("Record victory messages")
	 */
	public function victoryMessagesEvent(Event $eventObj): void {
		if (preg_match("/^The (Clan|Neutral|Omni) organization (.+) attacked the (Clan|Neutral|Omni) (.+) at their base in (.+). The attackers won!!$/i", $eventObj->message, $arr)) {
			$winnerFaction = $arr[1];
			$winnerOrgName = $arr[2];
			$loserFaction  = $arr[3];
			$loserOrgName  = $arr[4];
			$playfieldName = $arr[5];
		} elseif (preg_match("/^Notum Wars Update: The (clan|neutral|omni) organization (.+) lost their base in (.+).$/i", $eventObj->message, $arr)) {
			$winnerFaction = '';
			$winnerOrgName = '';
			$loserFaction  = ucfirst($arr[1]);  // capitalize the faction name to match the other messages
			$loserOrgName  = $arr[2];
			$playfieldName = $arr[3];
		} else {
			return;
		}

		$event = new TowerVictoryEvent();

		$playfield = $this->playfieldController->getPlayfieldByName($playfieldName);
		if ($playfield === null) {
			$this->logger->log('error', "Could not find playfield for name '$playfieldName'");
			return;
		}

		if (!$winnerFaction) {
			$msg = "<" . strtolower($loserFaction) . ">{$loserOrgName}<end> ".
				"abandoned their field";
		} else {
			$msg = "<".strtolower($winnerFaction).">{$winnerOrgName}<end>".
				" won against " .
				"<" . strtolower($loserFaction) . ">{$loserOrgName}<end>";
		}

		$lastAttack = $this->getLastAttack($winnerFaction, $winnerOrgName, $loserFaction, $loserOrgName, $playfield->id);
		// If we have the full scout information and the org has only 1 field
		// in that playfield, we can use that
		if ($lastAttack === null) {
			$data = $this->db->table("tower_site", "t")
				->leftJoin("scout_info AS s", function (JoinClause $join) {
					$join->on("t.playfield_id", "s.playfield_id")
						->on("s.site_number", "t.site_number");
				})
				->where("t.playfield_id", $playfield->id)
				->orderBy("t.site_number")
				->select("t.*", "s.guild_name", "s.faction")
				->asObj();
			// All bases scouted
			if ($data->whereNull("guild_name")->count() === 0) {
				$orgFields = $data->where("guild_name", $loserOrgName)
					->where("faction", $loserFaction);
				// And the losing org has only 1 field there
				if ($orgFields->count() === 1) {
					$siteNumber = (int)$orgFields->first()->site_number;
				}
			}
		} else {
			$siteNumber = $lastAttack->site_number;
		}
		if (isset($siteNumber)) {
			$towerInfo = $this->getTowerInfo($playfield->id, $siteNumber);
			$event->site = $towerInfo;
			$waypointLink = $this->text->makeChatcmd("Get a waypoint", "/waypoint {$towerInfo->x_coord} {$towerInfo->y_coord} {$playfield->id}");
			$timerLocation = $this->text->makeBlob(
				"{$playfield->short_name} {$siteNumber}",
				"Name: <highlight>{$towerInfo->site_name}<end><br>".
				"QL: <highlight>{$towerInfo->min_ql}<end> - <highlight>{$towerInfo->max_ql}<end><br>".
				"Action: $waypointLink",
				"Information about {$playfield->short_name} {$siteNumber}"
			);
			$msg .= " in " . $timerLocation;
		} else {
			$msg .= " in {$playfield->short_name}";
		}

		if ($this->settingManager->getInt('tower_plant_timer') !== 0) {
			if (!isset($siteNumber)) {
				$timerLocation = "unknown field in " . $playfield->short_name;
			}

			$this->setPlantTimer($timerLocation);
		}

		$r = new RoutableMessage($msg);
		$r->appendPath(new Source(Source::SYSTEM, "tower-victory"));
		$this->messageHub->handle($r);

		if (isset($towerInfo)) {
			$this->remScoutSite($towerInfo->playfield_id, $towerInfo->site_number);
		} else {
			// Since we couldn't identify the site number, mark all
			// sites of that org in that PF as unknown again
			$this->db->table("scout_info")
				->where("playfield_id", $playfield->id)
				->where("guild_name", $loserOrgName)
				->where("faction", $loserFaction)
				->delete();
		}
		if (!isset($lastAttack)) {
			$lastAttack = new TowerAttack();
			$lastAttack->att_guild_name = $winnerOrgName;
			$lastAttack->def_guild_name = $loserOrgName;
			$lastAttack->att_faction = $winnerFaction;
			$lastAttack->def_faction = $loserFaction;
			$lastAttack->playfield_id = $playfield->id;
			$lastAttack->id = -1;
			if (isset($siteNumber)) {
				$lastAttack->site_number = $siteNumber;
			}
		}

		$this->recordVictory($lastAttack);
		$event->attack = $lastAttack;
		$this->eventManager->fireEvent($event);
	}

	protected function attacksCommandHandler(?int $pageLabel=null, ?Closure $where, string $cmd, CommandReply $sendto): void {
		if ($pageLabel === null) {
			$pageLabel = 1;
		} elseif ($pageLabel < 1) {
			$msg = "You must choose a page number greater than 0";
			$sendto->reply($msg);
			return;
		}

		$pageSize = $this->settingManager->getInt('tower_page_size');
		$startRow = ($pageLabel - 1) * $pageSize;

		$query = $this->db->table(self::DB_TOWER_ATTACK, "a")
			->leftJoin("playfields AS p", "a.playfield_id", "p.id")
			->leftJoin("tower_site AS s", function (JoinClause $join) {
				$join->on("a.playfield_id", "s.playfield_id")
					->on("a.site_number", "s.site_number");
			});
		if (isset($where)) {
			$query->where($where);
		}
		$data = $query->orderByDesc("a.time")
			->limit($pageSize)
			->offset($startRow)
			->asObj();
		if ($data->isEmpty()) {
			$msg = "No tower attacks found.";
			$sendto->reply($msg);
			return;
		}
		$links = [];
		if ($pageLabel > 1) {
			$links['Previous Page'] = '/tell <myname> attacks ' . ($pageLabel - 1);
		}
		$links['Next Page'] = "/tell <myname> attacks {$cmd}" . ($pageLabel + 1);

		$blob = "The last $pageSize Tower Attacks (page $pageLabel)\n\n";
		$blob .= $this->text->makeHeaderLinks($links) . "\n\n";

		foreach ($data as $row) {
			$timeString = $this->util->unixtimeToReadable(time() - $row->time);
			$blob .= "Time: " . $this->util->date($row->time) . " (<highlight>$timeString<end> ago)\n";
			if ($row->att_faction == '') {
				$att_faction = "unknown";
			} else {
				$att_faction = strtolower($row->att_faction);
			}

			if ($row->def_faction == '') {
				$def_faction = "unknown";
			} else {
				$def_faction = strtolower($row->def_faction);
			}

			if ($row->att_profession == 'Unknown') {
				$blob .= "Attacker: <{$att_faction}>{$row->att_player}<end> ({$row->att_faction})\n";
			} elseif ($row->att_guild_name == '') {
				$blob .= "Attacker: <{$att_faction}>{$row->att_player}<end> ({$row->att_level}/<green>{$row->att_ai_level}<end> {$row->att_profession}) ({$row->att_faction})\n";
			} else {
				$blob .= "Attacker: {$row->att_player} ({$row->att_level}/<green>{$row->att_ai_level}<end> {$row->att_profession}) <{$att_faction}>{$row->att_guild_name}<end> ({$row->att_faction})\n";
			}

			$base = $this->text->makeChatcmd("{$row->short_name} {$row->site_number}", "/tell <myname> lc {$row->short_name} {$row->site_number}");
			$base .= " ({$row->min_ql}-{$row->max_ql})";

			$blob .= "Defender: <{$def_faction}>{$row->def_guild_name}<end> ({$row->def_faction})\n";
			$blob .= "Site: $base\n\n";
		}
		$msg = $this->text->makeBlob("Tower Attacks", $blob);

		$sendto->reply($msg);
	}

	protected function victoryCommandHandler(int $pageLabel, ?Closure $search, string $cmd, CommandReply $sendto): void {
		if ($pageLabel < 1) {
			$msg = "You must choose a page number greater than 0";
			$sendto->reply($msg);
			return;
		}

		$pageSize = $this->settingManager->getInt('tower_page_size');
		$startRow = ($pageLabel - 1) * $pageSize;

		$query = $this->db->table(self::DB_TOWER_VICTORY, "v")
			->leftJoin(self::DB_TOWER_ATTACK . " AS a", "a.id", "v.attack_id")
			->leftJoin("playfields AS p", "a.playfield_id", "p.id")
			->leftJoin("tower_site AS s", function (JoinClause $join) {
				$join->on("a.playfield_id", "s.playfield_id")
					->on("a.site_number", "s.site_number");
			})
			->orderByDesc("victory_time")
			->limit($pageSize)
			->offset($startRow)
			->select("*", "v.time AS victory_time", "a.time AS attack_time");
		if ($search) {
			$query->where($search);
		}

		/** @var TowerVictory[] */
		$data = $query->asObj(TowerVictory::class)->toArray();
		if (count($data) == 0) {
			$msg = "No Tower results found.";
		} else {
			$links = [];
			if ($pageLabel > 1) {
				$links['Previous Page'] = '/tell <myname> victory ' . ($pageLabel - 1);
			}
			$links['Next Page'] = "/tell <myname> victory {$cmd}" . ($pageLabel + 1);

			$blob = "The last $pageSize Tower Results (page $pageLabel)\n\n";
			$blob .= $this->text->makeHeaderLinks($links) . "\n\n";
			foreach ($data as $row) {
				$timeString = $this->util->unixtimeToReadable(time() - $row->victory_time);
				$blob .= "Time: " . $this->util->date($row->victory_time) . " (<highlight>$timeString<end> ago)\n";

				if (!$win_side = strtolower($row->win_faction)) {
					$win_side = "unknown";
				}
				if (!$lose_side = strtolower($row->lose_faction)) {
					$lose_side = "unknown";
				}

				if ($row->playfield_id != '' && $row->site_number != '') {
					$base = $this->text->makeChatcmd("{$row->short_name} {$row->site_number}", "/tell <myname> lc {$row->short_name} {$row->site_number}");
					$base .= " ({$row->min_ql}-{$row->max_ql})";
				} else {
					$base = "Unknown";
				}

				$blob .= "Winner: <{$win_side}>{$row->win_guild_name}<end> (".ucfirst($win_side).")\n";
				$blob .= "Loser: <{$lose_side}>{$row->lose_guild_name}<end> (".ucfirst($lose_side).")\n";
				$blob .= "Site: $base\n\n";
			}
			$msg = $this->text->makeBlob("Tower Victories", $blob);
		}

		$sendto->reply($msg);
	}

	public function getTowerInfo(int $playfieldID, int $siteNumber): ?TowerSite {
		return $this->db->table("tower_site AS t")
			->where("playfield_id", $playfieldID)
			->where("site_number", $siteNumber)
			->limit(1)
			->asObj(TowerSite::class)
			->first();
	}

	protected function getClosestSite(int $playfieldID, int $xCoords, int $yCoords): ?TowerSite {
		$inner = $this->db->table("tower_site")
			->where("playfield_id", $playfieldID)
			->select("*");
		$xDist = $inner->grammar->wrap("x_distance");
		$yDist = $inner->grammar->wrap("y_distance");
		$xCoord = $inner->grammar->wrap("x_coord");
		$yCoord = $inner->grammar->wrap("y_coord");
		$inner->selectRaw("({$xCoord} - ?) AS {$xDist}", [$xCoords]);
		$inner->selectRaw("({$yCoord} - ?) AS {$yDist}", [$yCoords]);
		$query = $this->db->fromSub($inner, "t")
			->orderBy("radius")
			->limit(1)
			->select("*");
		$query->selectRaw(
			"(({$xDist} * {$xDist}) + ({$yDist}  * {$yDist})) AS ".
			$query->grammar->wrap("radius")
		);

		return $query->asObj(TowerSite::class)->first();
	}

	protected function getLastAttack(string $attackFaction, string $attackOrgName, string $defendFaction, string $defendOrgName, int $playfieldID): ?TowerAttack {
		$time = time() - (7 * 3600);

		return $this->db->table(self::DB_TOWER_ATTACK)
			->where("att_guild_name", $attackOrgName)
			->where("att_faction", $attackFaction)
			->where("def_guild_name", $defendOrgName)
			->where("def_faction", $defendFaction)
			->where("playfield_id", $playfieldID)
			->where("time", ">=", $time)
			->limit(1)
			->asObj(TowerAttack::class)
			->first();
	}

	protected function recordAttack(Player $whois, Attack $attack, TowerSite $closestSite): int {
		$event = new TowerAttackEvent();
		$event->attacker = $whois;
		$event->defender = (object)["org" => $attack->defGuild, "faction" => $attack->defSide];
		$event->site = $closestSite;
		$event->type = "tower(attack)";
		$result = $this->db->table(self::DB_TOWER_ATTACK)
			->insert([
				"time" => time(),
				"att_guild_name" => $whois->guild ?? null,
				"att_faction" => $whois->faction ?? null,
				"att_player" => $whois->name ?? null,
				"att_level" => $whois->level ?? null,
				"att_ai_level" => $whois->ai_level ?? null,
				"att_profession" => $whois->profession ?? null,
				"def_guild_name" => $attack->defGuild,
				"def_faction" => $attack->defSide,
				"playfield_id" => $closestSite->playfield_id,
				"site_number" => $closestSite->site_number,
				"x_coords" => $attack->xCoords,
				"y_coords" => $attack->yCoords,
			]) ? 1 : 0;
		$this->eventManager->fireEvent($event);
		return $result;
	}

	protected function getLastVictory(int $playfieldID, int $siteNumber): ?TowerAttackAndVictory {
		return $this->db->table(self::DB_TOWER_VICTORY, "v")
			->join(self::DB_TOWER_ATTACK . " AS a", "a.id", "v.attack_id")
			->where("a.playfield_id", $playfieldID)
			->where("a.site_number", "=", $siteNumber)
			->orderByDesc("v.time")
			->limit(1)
			->asObj(TowerAttackAndVictory::class)
			->first();
	}

	protected function recordVictory(TowerAttack $attack): int {
		return $this->db->table(self::DB_TOWER_VICTORY)
			->insertGetId([
				"time" => time(),
				"win_guild_name" => $attack->att_guild_name,
				"win_faction" => $attack->att_faction,
				"lose_guild_name" => $attack->def_guild_name,
				"lose_faction" => $attack->def_faction,
				"attack_id" => $attack->id,
			]);
	}

	protected function addScoutSite(TowerSite $site, int $plantTime, int $ctQL, string $faction, string $orgName, string $scoutedBy): int {
		$closingTime = $plantTime;
		if ($site->timing !== self::TYPE_LEGACY) {
			$closingTime = self::FIXED_TIMES[$site->timing][0] * 3600;
		}
		$result = $this->db->table("scout_info")
			->upsert(
				[
					"playfield_id" => $site->playfield_id,
					"site_number" => $site->site_number,
					"scouted_on" => time(),
					"scouted_by" => $scoutedBy,
					"ct_ql" => $ctQL,
					"guild_name" => $orgName,
					"faction" => $faction,
					"close_time" => $closingTime,
				],
				["playfield_id", "site_number"]
			);
		return $result;
	}

	protected function remScoutSite(int $playfield_id, int $site_number): int {
		return $this->db->table("scout_info")
			->where("playfield_id", $playfield_id)
			->where("site_number", $site_number)
			->delete();
	}

	protected function checkGuildName(string $guildName): bool {
		return $this->db->table(self::DB_TOWER_ATTACK)
			->whereIlike("att_guild_name", $guildName)
			->orWhereIlike("def_guild_name", $guildName)
			->exists();
	}

	/**
	 * @return OrgInPenalty[]
	 */
	protected function getSitesInPenalty(?int $time=null): array {
		$time ??= time() - 7200;
		$query = $this->db->table(self::DB_TOWER_ATTACK, "t1")
			->leftJoin(self::DB_TOWER_VICTORY . " AS t2", "t1.id", "t2.attack_id")
			->where("att_guild_name", "!=", "");
		$penTime = $query->colFunc("COALESCE", ["t1.time", "t2.time"]);
		$query->where($query->colFunc("COALESCE", ["t2.time", "t1.time"]), ">", $time)
			->groupBy("att_guild_name", "att_faction")
			->orderBy("att_guild_name")
			->select("att_guild_name", "att_faction")
			->addSelect($query->rawFunc("MAX", $penTime, "penalty_time"))
			->orderByDesc($query->rawFunc("MAX", $penTime));
		return $query->asObj(OrgInPenalty::class)->toArray();
	}

	protected function getGasLevel(int $closeTime, ?int $time=null): GasInfo {
		$time ??= time();
		$currentTime = $time % 86400;

		$site = new GasInfo();
		$site->current_time = $currentTime;
		$site->close_time = $closeTime;

		if ($closeTime < $currentTime) {
			$closeTime += 86400;
		}

		$timeUntilCloseTime = $closeTime - $currentTime;
		$site->time_until_close_time = $timeUntilCloseTime;

		if ($timeUntilCloseTime < 3600 * 1) {
			$site->gas_change = $timeUntilCloseTime;
			$site->gas_level = '5%';
			$site->next_state = 'closes';
			$site->color = "<orange>";
		} elseif ($timeUntilCloseTime < 3600 * 6) {
			$site->gas_change = $timeUntilCloseTime;
			$site->gas_level = '25%';
			$site->next_state = 'closes';
			$site->color = "<green>";
		} else {
			$site->gas_change = $timeUntilCloseTime - (3600 * 6);
			$site->gas_level = '75%';
			$site->next_state = 'opens';
			$site->color = "<red>";
		}

		return $site;
	}

	protected function formatSiteInfo(SiteInfo $row): string {
		$waypointLink = $this->text->makeChatcmd($row->x_coord . "x" . $row->y_coord, "/waypoint {$row->x_coord} {$row->y_coord} {$row->playfield_id}");
		$attacksLink = $this->text->makeChatcmd("Recent attacks", "/tell <myname> attacks {$row->short_name} {$row->site_number}");
		$victoryLink = $this->text->makeChatcmd("Recent victories", "/tell <myname> victory {$row->short_name} {$row->site_number}");

		$blob = "Short name: <highlight>{$row->short_name} {$row->site_number}<end>\n";
		$blob .= "Long name: <highlight>{$row->site_name}, {$row->long_name}<end>\n";
		$blob .= "Level range: <highlight>{$row->min_ql}-{$row->max_ql}<end>\n";
		$blob .= "Center coordinates: $waypointLink\n";
		$blob .= $attacksLink . "\n";
		$blob .= $victoryLink;

		return $blob;
	}

	public function getFaction(string $input): string {
		$faction = ucfirst(strtolower($input));
		if ($faction == "Neut") {
			$faction = "Neutral";
		}
		return $faction;
	}
}

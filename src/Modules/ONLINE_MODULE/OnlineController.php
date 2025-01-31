<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	Nadybot,
	QueryBuilder,
	SettingManager,
	StopExecutionException,
	Text,
	Util,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;
use Nadybot\Modules\RAID_MODULE\RaidController;
use Nadybot\Modules\RELAY_MODULE\RelayController;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 * @author Naturarum (Paradise, RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'online',
 *		accessLevel = 'member',
 *		description = 'Shows who is online',
 *		help        = 'online.txt'
 *	)
 * @ProvidesEvent("online(org)")
 * @ProvidesEvent("offline(org)")
 */
class OnlineController {
	protected const GROUP_OFF = 0;
	protected const GROUP_BY_MAIN = 1;
	protected const GROUP_BY_PROFESSION = 2;

	protected const RAID_OFF = 0;
	protected const RAID_IN = 1;
	protected const RAID_NOT_IN = 2;
	protected const RAID_COMPACT = 4;

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public DiscordGatewayController $discordGatewayController;

	/** @Inject */
	public RaidController $raidController;

	/** @Inject */
	public RelayController $relayController;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup() {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->table("online")
			->where("added_by", $this->db->getBotname())
			->delete();

		$this->settingManager->add(
			$this->moduleName,
			"online_expire",
			"How long to wait before clearing online list",
			"edit",
			"time",
			"15m",
			"2m;5m;10m;15m;20m",
			'',
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_show_relay",
			"Include players from your relay(s) by default",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_show_org_guild",
			"Show org/rank for players in guild channel",
			"edit",
			"options",
			"1",
			"Show org and rank;Show rank only;Show org only;Show no org info",
			"2;1;3;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_show_org_priv",
			"Show org/rank for players in private channel",
			"edit",
			"options",
			"2",
			"Show org and rank;Show rank only;Show org only;Show no org info",
			"2;1;3;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_admin",
			"Show admin levels in online list",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_raid",
			"Show raid participation in online list",
			"edit",
			"options",
			"0",
			"off;in raid;not in raid;both;both, but compact",
			"0;1;2;3;7"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_group_by",
			"Group online list by",
			"edit",
			"options",
			"1",
			"do not group;player;profession",
			"0;1;2"
		);
		$this->settingManager->add(
			$this->moduleName,
			"online_show_discord",
			"Show players in discord voice channels",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"afk_brb_without_symbol",
			"React to afk and brb even without command prefix",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);

		$this->commandAlias->register($this->moduleName, "online", "o");
		$this->commandAlias->register($this->moduleName, "online", "sm");
	}

	public function buildOnlineQuery(string $sender, string $channelType): QueryBuilder {
		return $this->db->table("online")
			->where("name", $sender)
			->where("channel_type", $channelType)
			->where("added_by", $this->db->getBotname());
	}

	/**
	 * @HandlesCommand("online")
	 * @Matches("/^online$/i")
	 */
	public function onlineCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getOnlineList();
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("online")
	 * @Matches("/^online all$/i")
	 */
	public function onlineAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getOnlineList(true);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("online")
	 * @Matches("/^online (.+)$/i")
	 */
	public function onlineProfCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$profession = $this->util->getProfessionName($args[1]);
		if (empty($profession)) {
			$msg = "<highlight>{$args[1]}<end> is not a recognized profession.";
			$sendto->reply($msg);
			return;
		}

		$query = $this->db->table("online AS o");
		$query->leftJoin("alts AS a", function (JoinClause $join) {
			$join->on("o.name", "a.alt")
				->where("a.validated_by_main", true)
				->where("a.validated_by_alt", true);
		})->leftJoin("alts AS a2", "a2.main", $query->colFunc("COALESCE", ["a.main", "o.name"]))
		->leftJoin("players AS p", function (JoinClause $join) use ($query) {
			$join->on("a2.alt", "p.name")
				->orWhere($query->colFunc("COALESCE", ["a.main", "o.name"]), "p.name");
		})
		->leftJoin("online AS o2", "p.name", "o2.name")
		->where("p.profession", $profession)
		->orderByRaw($query->colFunc("COALESCE", ["a.main", "o.name"]))
		->select("p.*", "o.afk")
		->addSelect($query->colFunc("COALESCE", ["a.main", "p.name"], "pmain"))
		->selectRaw(
			"(CASE WHEN " . $query->grammar->wrap("o2.name") . " IS NULL ".
			"THEN 0 ELSE 1 END) AS " . $query->grammar->wrap("online")
		);
		/** @var Collection<OnlinePlayer> */
		$players = $query->asObj(OnlinePlayer::class);
		$count = $players->count();
		$mainCount = 0;
		$currentMain = "";
		$blob = "";

		if ($count === 0) {
			$msg = "$profession Search Results (0)";
			$sendto->reply($msg);
			return;
		}
		foreach ($players as $player) {
			if ($currentMain !== $player->pmain) {
				$mainCount++;
				$blob .= "\n<highlight>$player->pmain<end> has\n";
				$currentMain = $player->pmain;
			}

			$playerName = $player->name;
			if ($player->online) {
				$playerName = $this->text->makeChatcmd($player->name, "/tell {$player->name}");
			}
			if ($player->profession === null) {
				$blob .= "<tab>($playerName)\n";
			} else {
				$prof = $this->util->getProfessionAbbreviation($player->profession);
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->getProfessionId($player->profession).">";
				$blob.= "<tab>$profIcon $playerName - $player->level/<green>$player->ai_level<end> $prof";
			}
			if ($player->online) {
				$blob .= " <green>Online<end>";
			}
			$blob .= "\n";
		}
		$blob .= "\nWritten by Naturarum (RK2)";
		$msg = $this->text->makeBlob("$profession Search Results ($mainCount)", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @Event("logOn")
	 * @Description("Records an org member login in db")
	 */
	public function recordLogonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (!isset($this->chatBot->guildmembers[$sender])) {
			return;
		}
		$player = $this->addPlayerToOnlineList($sender, $this->chatBot->vars['my_guild'], 'guild');
		if ($player === null) {
			return;
		}
		$event = new OnlineEvent();
		$event->type = "online(org)";
		$event->channel = "org";
		$event->player = $player;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @Event("logOff")
	 * @Description("Records an org member logoff in db")
	 */
	public function recordLogoffEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (isset($this->chatBot->guildmembers[$sender])) {
			$this->removePlayerFromOnlineList($sender, 'guild');
			$event = new OfflineEvent();
			$event->type = "offline(org)";
			$event->player = $sender;
			$event->channel = "org";
			$this->eventManager->fireEvent($event);
		}
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends a tell to players on logon showing who is online in org")
	 */
	public function showOnlineOnLogonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;
		if (isset($this->chatBot->guildmembers[$sender]) && $this->chatBot->isReady()) {
			$msg = $this->getOnlineList(false);
			$this->chatBot->sendMassTell($msg, $sender);
		}
	}

	/**
	 * @Event("timer(10mins)")
	 * @Description("Online check")
	 */
	public function onlineCheckEvent(Event $eventObj): void {
		if (!$this->chatBot->isReady()) {
			return;
		}
		$data = $this->db->table("online")
			->select("name", "channel_type")
			->asObj();

		$guildArray = [];
		$privArray = [];

		foreach ($data as $row) {
			switch ($row->channel_type) {
				case 'guild':
					$guildArray []= $row->name;
					break;
				case 'priv':
					$privArray []= $row->name;
					break;
				default:
					$this->logger->log("WARN", "Unknown channel type: '$row->channel_type'. Expected: 'guild' or 'priv'");
			}
		}

		$time = time();

		foreach ($this->chatBot->guildmembers as $name => $rank) {
			if ($this->buddylistManager->isOnline($name)) {
				if (in_array($name, $guildArray)) {
					$this->buildOnlineQuery($name, "guild")
						->update(["dt" => $time]);
				} else {
					$this->db->table("online")
						->insert([
							"name" => $name,
							"channel" => $this->db->getMyguild(),
							"channel_type" => "guild",
							"added_by" => $this->db->getBotname(),
							"dt" => $time
						]);
				}
			}
		}

		foreach ($this->chatBot->chatlist as $name => $value) {
			if (in_array($name, $privArray)) {
					$this->buildOnlineQuery($name, "priv")
						->update(["dt" => $time]);
			} else {
					$this->db->table("online")
						->insert([
							"name" => $name,
							"channel" => $this->db->getMyguild() . " Guests",
							"channel_type" => "priv",
							"added_by" => $this->db->getBotname(),
							"dt" => $time
						]);
			}
		}

		$this->db->table("online")
			->where(function(QueryBuilder $query) use ($time) {
				$query->where("dt", "<", $time)
					->where("added_by", $this->db->getBotname());
			})->orWhere("dt", "<", $time - $this->settingManager->get('online_expire'))
			->delete();
	}

	/**
	 * @Event("priv")
	 * @Description("Afk check")
	 * @Help("afk")
	 */
	public function afkCheckPrivateChannelEvent(Event $eventObj) {
		$this->afkCheck($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * @Event("guild")
	 * @Description("Afk check")
	 * @Help("afk")
	 */
	public function afkCheckGuildChannelEvent(Event $eventObj) {
		$this->afkCheck($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * @Event("priv")
	 * @Description("Sets a member afk")
	 * @Help("afk")
	 */
	public function afkPrivateChannelEvent(Event $eventObj) {
		$this->afk($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * @Event("guild")
	 * @Description("Sets a member afk")
	 * @Help("afk")
	 */
	public function afkGuildChannelEvent(Event $eventObj): void {
		$this->afk($eventObj->sender, $eventObj->message, $eventObj->type);
	}

	/**
	 * Set someone back from afk if needed
	 */
	public function afkCheck($sender, string $message, string $type): void {
		// to stop raising and lowering the cloak messages from triggering afk check
		if (!$this->util->isValidSender($sender)) {
			return;
		}

		$symbol = $this->settingManager->getString('symbol');
		if (preg_match("/^\Q$symbol\E?afk(.*)$/i", $message)) {
			return;
		}
		$row = $this->buildOnlineQuery($sender, $type)
			->select("afk")
			->asObj()->first();

		if ($row === null || $row->afk === '') {
			return;
		}
		$time = explode('|', $row->afk)[0];
		$timeString = $this->util->unixtimeToReadable(time() - $time);
		// $sender is back
		$this->buildOnlineQuery($sender, $type)
			->update(["afk" => ""]);
		$msg = "<highlight>{$sender}<end> is back after $timeString.";

		if ('priv' == $type) {
			$this->chatBot->sendPrivate($msg);
		} elseif ('guild' == $type) {
			$this->chatBot->sendGuild($msg);
		}
	}

	public function afk(string $sender, string $message, string $type): void {
		$msg = null;
		$symbol = $this->settingManager->getString('symbol');
		$symbolModifier = "";
		if ($this->settingManager->getBool('afk_brb_without_symbol')) {
			$symbolModifier = "?";
		}
		if (preg_match("/^\Q$symbol\E${symbolModifier}afk$/i", $message)) {
			$reason = (string)time();
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
			$msg = "<highlight>$sender<end> is now AFK.";
		} elseif (preg_match("/^\Q$symbol\E${symbolModifier}brb(.*)$/i", $message, $arr)) {
			$reason = time() . '|brb ' . trim($arr[1]);
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
		} elseif (preg_match("/^\Q$symbol\E${symbolModifier}afk[, ]+(.*)$/i", $message, $arr)) {
			$reason = time() . '|' . $arr[1];
			$this->buildOnlineQuery($sender, $type)->update(["afk" => $reason]);
			$msg = "<highlight>$sender<end> is now AFK.";
		}

		if ($msg !== null) {
			if ('priv' == $type) {
				$this->chatBot->sendPrivate($msg);
			} elseif ('guild' == $type) {
				$this->chatBot->sendGuild($msg);
			}

			// if 'afk' was used as a command, throw StopExecutionException to prevent
			// normal command handling from occurring
			if ($message[0] == $symbol) {
				throw new StopExecutionException();
			}
		}
	}

	public function addPlayerToOnlineList(string $sender, string $channel, string $channelType): ?OnlinePlayer {
		$data = $this->buildOnlineQuery($sender, $channelType)
			->select("name")
			->asObj()->toArray();

		if (count($data) === 0) {
			$this->db->table("online")
				->insert([
					"name" => $sender,
					"channel" => $channelType,
					"channel_type" => $channelType,
					"added_by" => $this->db->getBotname(),
					"dt" => time()
				]);
		}
		$query = $this->db->table("online AS o")
			->leftJoin("alts AS a", function (JoinClause $join) {
				$join->on("o.name", "a.alt")
					->where("a.validated_by_main", true)
					->where("a.validated_by_alt", true);
			})->leftJoin("players AS p", "o.name", "p.name")
			->where("o.channel_type", $channelType)
			->where("o.name", $sender)
			->select("p.*", "o.name", "o.afk");
		$query->addSelect($query->colFunc("COALESCE", ["a.main", "o.name"], "pmain"));
		$op = $query->asObj(OnlinePlayer::class)->first();
		return $op;
	}

	public function removePlayerFromOnlineList(string $sender, string $channelType): void {
		$this->buildOnlineQuery($sender, $channelType)->delete();
	}

	/**
	 * Get a page or multiple pages with the online list
	 * @return string[]
	 */
	public function getOnlineList(bool $includeRelay=null): array {
		$includeRelay ??= $this->settingManager->getBool("online_show_relay");
		$orgData = $this->getPlayers('guild');
		$orgList = $this->formatData($orgData, $this->settingManager->getInt("online_show_org_guild"));

		$privData = $this->getPlayers('priv');
		$privList = $this->formatData($privData, $this->settingManager->getInt("online_show_org_priv"));

		/** @var array<string,OnlineList> */
		$relayList = [];
		foreach ($this->relayController->relays as $name => $relay) {
			$online = $relay->getOnlineList();
			foreach ($online as $chanName => $onlineChars) {
				$chars = array_values($onlineChars);
				$relayList[$chanName] = $this->formatData($chars, 0, static::GROUP_OFF);
			}
		}

		$discData = [];
		if ($this->settingManager->getBool("online_show_discord")) {
			$discData = $this->discordGatewayController->getPlayersInVoiceChannels();
		}

		$totalCount = $orgList->count + $privList->count;
		$totalMain = $orgList->countMains + $privList->countMains;

		$blob = "\n";
		if ($orgList->count > 0) {
			$blob .= "<header2>Org Channel ($orgList->countMains)<end>\n";
			$blob .= $orgList->blob;
			$blob .= "\n\n";
		}
		if ($privList->count > 0) {
			$blob .= "<header2>Private Channel ($privList->countMains)<end>\n";
			$blob .= $privList->blob;
			$blob .= "\n\n";
		}
		foreach ($discData as $serverName => $channels) {
			$guildCount = 0;
			$guildUsers = "";
			foreach ($channels as $channel => $users) {
				$guildUsers .= "\n<highlight>{$channel}<end>\n";
				foreach ($users as $user) {
					$guildUsers .= "<tab>{$user}\n";
					$guildCount++;
					$totalCount++;
					$totalMain++;
				}
			}
			$blob .= "<header2>{$serverName} ({$guildCount})<end>\n".
				$guildUsers;
			$blob .= "\n\n";
		}

		if ($includeRelay) {
			foreach ($relayList as $chanName => $chanList) {
				if ($chanList->count > 0) {
					$blob .= "<header2>{$chanName} ({$chanList->count})<end>\n".
						$chanList->blob ."\n\n";
					$totalCount += $chanList->count;
					$totalMain += $chanList->countMains;
				}
			}
		}

		if ($totalCount > 0) {
			$blob .= "Originally written by Naturarum (RK2)";
			$msg = $this->text->makeBlob("Players Online ($totalMain)", $blob);
		} else {
			$msg = "Players Online (0)";
		}
		return (array)$msg;
	}

	public function getOrgInfo(int $showOrgInfo, string $fancyColon, string $guild, string $guild_rank): string {
		switch ($showOrgInfo) {
			case 3:
				  return $guild !== "" ? " $fancyColon {$guild}":" $fancyColon Not in an org";
			case 2:
				  return $guild !== "" ? " $fancyColon {$guild} ({$guild_rank})":" $fancyColon Not in an org";
			case 1:
				  return $guild !== "" ? " $fancyColon {$guild_rank}":"";
			default:
				  return "";
		}
	}

	public function getAdminInfo(string $name, string $fancyColon): string {
		if (!$this->settingManager->getBool("online_admin")) {
			return "";
		}

		$accessLevel = $this->accessManager->getAccessLevelForCharacter($name);
		switch ($accessLevel) {
			case 'superadmin':
				  return " $fancyColon <red>SuperAdmin<end>";
			case 'admin':
				  return " $fancyColon <red>Admin<end>";
			case 'mod':
				  return " $fancyColon <green>Mod<end>";
			case 'rl':
				  return " $fancyColon <orange>RL<end>";
		}
		if (substr($accessLevel, 0, 5) === "raid_") {
			$setName = $this->settingManager->getString("name_{$accessLevel}");
			if ($setName !== null) {
				return " $fancyColon <orange>$setName<end>";
			}
		}
		return "";
	}

	public function getRaidInfo(string $name, string $fancyColon): array {
		$mode = $this->settingManager->getInt("online_raid");
		if ($mode === 0) {
			return ["", ""];
		}
		if (!isset($this->raidController->raid)) {
			return ["", ""];
		}
		$inRaid = isset($this->raidController->raid->raiders[$name])
			&& $this->raidController->raid->raiders[$name]->left === null;

		if (($mode & static::RAID_IN) && $inRaid) {
			if ($mode & static::RAID_COMPACT) {
				return ["[<green>R<end>] ", ""];
			}
			return ["", " $fancyColon <green>in raid<end>"];
		} elseif (($mode & static::RAID_NOT_IN) && !$inRaid) {
			if ($mode & static::RAID_COMPACT) {
				return ["[<red>R<end>] ", ""];
			}
			return ["", " $fancyColon <red>not in raid<end>"];
		}
		return ["", ""];
	}

	public function getAfkInfo(string $afk, string $fancyColon): string {
		if (empty($afk)) {
			return '';
		}
		$props = explode("|", $afk, 2);
		if (count($props) === 1 || !strlen($props[1])) {
			$timeString = $this->util->unixtimeToReadable(time() - $props[0], false);
			return " $fancyColon <highlight>AFK for $timeString<end>";
		}
		$timeString = $this->util->unixtimeToReadable(time() - $props[0], false);
		return " $fancyColon <highlight>AFK for $timeString: {$props[1]}<end>";
	}

	/**
	 * @param OnlinePlayer[] $players
	 * @param int $showOrgInfo
	 * @return OnlineList
	 */
	public function formatData(array $players, int $showOrgInfo, ?int $groupBy=null): OnlineList {
		$currentGroup = "";
		$separator = "-";
		$list = new OnlineList();
		$list->count = count($players);
		$list->countMains = 0;
		$list->blob = "";

		if ($list->count === 0) {
			return $list;
		}
		$groupBy ??= $this->settingManager->getInt('online_group_by');
		foreach ($players as $player) {
			if ($groupBy === static::GROUP_BY_MAIN) {
				if ($currentGroup !== $player->pmain) {
					$list->countMains++;
					$list->blob .= "\n<pagebreak><highlight>$player->pmain<end> on\n";
					$currentGroup = $player->pmain;
				}
			} elseif ($groupBy === static::GROUP_BY_PROFESSION) {
				$list->countMains++;
				if ($currentGroup !== $player->profession) {
					$profIcon = "?";
					if ($player->profession !== null) {
						$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->getProfessionId($player->profession).">";
					}
					$list->blob .= "\n<pagebreak>{$profIcon}<highlight>{$player->profession}<end>\n";
					$currentGroup = $player->profession;
				}
			} else {
				$list->countMains++;
			}

			$admin = $this->getAdminInfo($player->name, $separator);
			[$raidPre, $raidPost] = $this->getRaidInfo($player->name, $separator);
			$afk = $this->getAfkInfo($player->afk??"", $separator);

			if ($player->profession === null) {
				$list->blob .= "<tab>? {$raidPre}{$player->name}{$admin}{$raidPost}{$afk}\n";
			} else {
				$prof = $this->util->getProfessionAbbreviation($player->profession);
				$orgRank = $this->getOrgInfo($showOrgInfo, $separator, $player->guild, $player->guild_rank);
				$profIcon = "";
				if ($groupBy !== static::GROUP_BY_PROFESSION) {
					$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->getProfessionId($player->profession)."> ";
				}
				$list->blob.= "<tab>{$profIcon}{$raidPre}{$player->name} - {$player->level}/<green>{$player->ai_level}<end> {$prof}{$orgRank}{$admin}{$raidPost}{$afk}\n";
			}
		}

		return $list;
	}

	/**
	 * @return OnlinePlayer[]
	 */
	public function getPlayers(string $channelType): array {
		$query = $this->db->table("online AS o")
			->leftJoin("alts AS a", function (JoinClause $join) {
				$join->on("o.name", "a.alt")
					->where("a.validated_by_main", true)
					->where("a.validated_by_alt", true);
			})->leftJoin("players AS p", "o.name", "p.name")
			->where("o.channel_type", $channelType)
			->select("p.*", "o.name", "o.afk");
		$query->addSelect($query->colFunc("COALESCE", ["a.main", "o.name"], "pmain"));
		$groupBy = $this->settingManager->getInt('online_group_by');
		if ($groupBy === static::GROUP_BY_MAIN) {
			$query->orderByRaw($query->colFunc("COALESCE", ["a.main", "o.name"]));
		} elseif ($groupBy === static::GROUP_BY_PROFESSION) {
			$query->orderByRaw(
				"COALESCE(" . $query->grammar->wrap("p.profession") . ", ?) asc",
				['Unknown']
			)->orderBy("o.name");
		} else {
			$query->orderByRaw("o.name");
		}
		return $query->asObj(OnlinePlayer::class)->toArray();
	}

	public function getProfessionId(string $profession): ?int {
		$profToID = [
			"Adventurer" => 6,
			"Agent" => 5,
			"Bureaucrat" => 8,
			"Doctor" => 10,
			"Enforcer" => 9,
			"Engineer" => 3,
			"Fixer" => 4,
			"Keeper" => 14,
			"Martial Artist" => 2,
			"Meta-Physicist" => 12,
			"Nano-Technician" => 11,
			"Soldier" => 1,
			"Shade" => 15,
			"Trader" => 7,
		];
		return $profToID[$profession] ?? null;
	}

	/**
	 * Get a list of all people online in all linked channels
	 * @Api("/online")
	 * @GET
	 * @AccessLevelFrom("online")
	 * @ApiResult(code=200, class='OnlinePlayers', desc='A list of online players')
	 */
	public function apiOnlineEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$result = new OnlinePlayers();
		$result->org = $this->getPlayers('guild');
		$result->private_channel = $this->getPlayers('priv');
		return new ApiResponse($result);
	}
}

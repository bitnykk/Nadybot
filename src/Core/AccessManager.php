<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Nadybot\Core\DBSchema\Audit;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatLeaderController;
use Nadybot\Modules\RAID_MODULE\RaidRankController;
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Modules\GUILD_MODULE\GuildRankController;
use Nadybot\Modules\PRIVATE_CHANNEL_MODULE\PrivateChannelController;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;

/**
 * The AccessLevel class provides functionality for checking a player's access level.
 *
 * @Instance
 */
class AccessManager {
	public const DB_TABLE = "audit_<myname>";
	public const ADD_RANK = "add-rank";
	public const DEL_RANK = "del-rank";
	public const PERM_BAN = "permanent-ban";
	public const TEMP_BAN = "temporary-ban";
	public const JOIN = "join";
	public const KICK = "kick";
	public const LEAVE = "leave";
	public const INVITE = "invite";
	public const ADD_ALT = "add-alt";
	public const DEL_ALT = "del-alt";
	public const SET_MAIN = "set-main";

	/**
	 * @var array<string,int> $ACCESS_LEVELS
	 */
	private static array $ACCESS_LEVELS = [
		'none'          => 0,
		'superadmin'    => 1,
		'admin'         => 2,
		'mod'           => 3,
		'guild'         => 4,
		'raid_admin_3'  => 5,
		'raid_admin_2'  => 6,
		'raid_admin_1'  => 7,
		'raid_leader_3' => 8,
		'raid_leader_2' => 9,
		'raid_leader_1' => 10,
		// 'raid_level_3'  => 11,
		// 'raid_level_2'  => 12,
		// 'raid_level_1'  => 13,
		'member'        => 14,
		'rl'            => 15,
		'all'           => 16,
	];

	/** @Inject */
	public DB $db;

	/** @Inject */
	public SettingObject $setting;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public GuildRankController $guildRankController;

	/** @Inject */
	public RaidRankController $raidRankController;

	/**
	 * This method checks if given $sender has at least $accessLevel rights.
	 *
	 * Normally, you don't have to worry about access levels in the bot.
	 * The bot will automatically restrict access to commands based on the
	 * access level setting on the command and the access level of the user
	 * trying to access the command.
	 *
	 * However, there are some cases where you may need this functionality.
	 * For instance, you may have a command that displays the names of the last
	 * ten people to send a tell to the bot.  You may wish to display a "ban"
	 * link when a moderator or higher uses that command.
	 *
	 * To check if a character named 'Tyrence' has moderator access,
	 * you would do:
	 *
	 * <code>
	 * if ($this->accessManager->checkAccess("Tyrence", "moderator")) {
	 *    // Tyrence has [at least] moderator access level
	 * } else {
	 *    // Tyrence does not have moderator access level
	 * }
	 * </code>
	 *
	 * Note that this will return true if 'Tyrence' is a moderator on your
	 * bot, but also if he is anything higher, such as administrator, or superadmin.
	 *
	 * This command will check the character's "effective" access level, meaning
	 * the higher of it's own access level and that of it's main, if it has a main
	 * and if it has been validated as an alt.
	 */
	public function checkAccess(string $sender, string $accessLevel): bool {
		$this->logger->log("DEBUG", "Checking access level '$accessLevel' against character '$sender'");

		$returnVal = $this->checkSingleAccess($sender, $accessLevel);

		if ($returnVal === false) {
			// if current character doesn't have access,
			// and if the current character is not a main character,
			// and if the current character is validated,
			// then check access against the main character,
			// otherwise just return the result
			$altInfo = $this->altsController->getAltInfo($sender);
			if ($sender !== $altInfo->main && $altInfo->isValidated($sender)) {
				$this->logger->log("DEBUG", "Checking access level '$accessLevel' against the main of '$sender' which is '$altInfo->main'");
				$returnVal = $this->checkSingleAccess($altInfo->main, $accessLevel);
			}
		}

		return $returnVal;
	}

	/**
	 * This method checks if given $sender has at least $accessLevel rights.
	 *
	 * This is the same checkAccess() but doesn't check alt
	 */
	public function checkSingleAccess(string $sender, string $accessLevel): bool {
		$sender = ucfirst(strtolower($sender));

		$charAccessLevel = $this->getSingleAccessLevel($sender);
		return ($this->compareAccessLevels($charAccessLevel, $accessLevel) >= 0);
	}

	/**
	 * Turn the short accesslevel (rl, mod, admin) into the long version
	 */
	public function getDisplayName(string $accessLevel): string {
		$displayName = $this->getAccessLevel($accessLevel);
		switch ($displayName) {
			case "rl":
				return "raidleader";
			case "mod":
				return "moderator";
			case "admin":
				return "administrator";
		}
		if (substr($displayName, 0, 5) === "raid_") {
			$setName = $this->settingManager->getString("name_{$displayName}");
			if ($setName !== null) {
				return $setName;
			}
		}

		return $displayName;
	}

	public function highestRank(string $al1, string $al2): string {
		$cmd = $this->compareAccessLevels($al1, $al2);
		return ($cmd > 0) ? $al1 : $al2;
	}

	/**
	 * Returns the access level of $sender, ignoring guild admin and inheriting access level from main
	 */
	public function getSingleAccessLevel(string $sender): string {
		$orgRank = "all";
		if (isset($this->chatBot->guildmembers[$sender])
			&& $this->settingManager->getBool('map_org_ranks_to_bot_ranks')) {
			$orgRank = $this->guildRankController->getEffectiveAccessLevel(
				$this->chatBot->guildmembers[$sender]
			);
		}
		if ($this->chatBot->vars["SuperAdmin"] == $sender) {
			return "superadmin";
		}
		if (isset($this->adminManager->admins[$sender])) {
			$level = $this->adminManager->admins[$sender]["level"];
			if ($level >= 4) {
				return $this->highestRank($orgRank, "admin");
			}
			if ($level >= 3) {
				return $this->highestRank($orgRank, "mod");
			}
		}
		if (isset($this->raidRankController->ranks[$sender])) {
			$rank = $this->raidRankController->ranks[$sender]->rank;
			if ($rank >= 7) {
				return $this->highestRank("raid_admin_" . ($rank-6), $orgRank);
			}
			if ($rank >= 4) {
				return $this->highestRank("raid_leader_" . ($rank-3), $orgRank);
			}
			return $this->highestRank("raid_level_{$rank}", $orgRank);
		}
		if ($this->chatLeaderController !== null && $this->chatLeaderController->getLeader() == $sender) {
			return $this->highestRank("rl", $orgRank);
		}
		if (isset($this->chatBot->guildmembers[$sender])) {
			return $this->highestRank("guild", $orgRank);
		}

		if ($this->db->table(PrivateChannelController::DB_TABLE)
			->where("name", $sender)
			->exists()
		) {
			return "member";
		}
		return "all";
	}

	/**
	 * Returns the access level of $sender, accounting for guild admin and inheriting access level from main
	 */
	public function getAccessLevelForCharacter(string $sender): string {
		$sender = ucfirst(strtolower($sender));

		$accessLevel = $this->getSingleAccessLevel($sender);

		$altInfo = $this->altsController->getAltInfo($sender);
		if ($sender !== $altInfo->main && $altInfo->isValidated($sender)) {
			$mainAccessLevel = $this->getSingleAccessLevel($altInfo->main);
			if ($this->compareAccessLevels($mainAccessLevel, $accessLevel) > 0) {
				$accessLevel = $mainAccessLevel;
			}
		}

		return $accessLevel;
	}

	/**
	 * Compare 2 access levels
	 *
	 * @return int 1 if $accessLevel1 is a greater access level than $accessLevel2,
	 *             -1 if $accessLevel1 is a lesser access level than $accessLevel2,
	 *             0 if the access levels are equal.
	 */
	public function compareAccessLevels(string $accessLevel1, string $accessLevel2): int {
		$accessLevel1 = $this->getAccessLevel($accessLevel1);
		$accessLevel2 = $this->getAccessLevel($accessLevel2);

		$accessLevels = $this->getAccessLevels();

		return $accessLevels[$accessLevel2] <=> $accessLevels[$accessLevel1];
	}

	/**
	 * Compare the access levels of 2 characters, taking alts into account
	 *
	 * @return int 1 if the access level of $char1 is greater than the access level of $char2,
	 *             -1 if the access level of $char1 is less than the access level of $char2,
	 *             0 if the access levels of $char1 and $char2 are equal.
	 */
	public function compareCharacterAccessLevels(string $char1, string $char2): int {
		$char1 = ucfirst(strtolower($char1));
		$char2 = ucfirst(strtolower($char2));

		$char1AccessLevel = $this->getAccessLevelForCharacter($char1);
		$char2AccessLevel = $this->getAccessLevelForCharacter($char2);

		return $this->compareAccessLevels($char1AccessLevel, $char2AccessLevel);
	}

	/**
	 * Get the short version of the accesslevel, e.g. raidleader => rl
	 * @throws Exception
	 */
	public function getAccessLevel(string $accessLevel): string {
		$accessLevel = strtolower($accessLevel);
		switch ($accessLevel) {
			case "raidleader":
				$accessLevel = "rl";
				break;
			case "moderator":
				$accessLevel = "mod";
				break;
			case "administrator":
				$accessLevel = "admin";
				break;
		}

		$accessLevels = $this->getAccessLevels();
		if (isset($accessLevels[$accessLevel])) {
			return strtolower($accessLevel);
		}
		throw new Exception("Invalid access level '$accessLevel'.");
	}

	/**
	 * Return all allowed and known access levels
	 *
	 * @return int[] All access levels with the name as key and the number as value
	 */
	public function getAccessLevels(): array {
		return self::$ACCESS_LEVELS;
	}

	public function addAudit(Audit $audit): int {
		if (in_array($audit->action, [static::ADD_RANK, static::DEL_RANK])) {
			$revLook = array_flip(self::$ACCESS_LEVELS);
			$audit->value = $audit->value . " (" . $revLook[$audit->value] . ")";
		}
		return $this->db->insert(static::DB_TABLE, $audit);
	}

	protected function addRangeLimits(Request $request, QueryBuilder $query): ?Response {
		$max = $request->query["max"]??null;
		if (!isset($max)) {
			return null;
		}
		if (!preg_match("/^\d+$/", $max)) {
			return new Response(Response::UNPROCESSABLE_ENTITY, [], "max is not an integer value");
		}
		$query->where("id", "<=", $max);
		return null;
	}

	/**
	 * Query entries from the audit log
	 * @Api("/audit")
	 * @GET
	 * @QueryParam(name='limit', type='integer', desc='No more than this amount of entries will be returned. Default is 50', required=false)
	 * @QueryParam(name='offset', type='integer', desc='How many entries to skip before beginning to return entries, required=false)
	 * @QueryParam(name='actor', type='string', desc='Show only entries of this actor', required=false)
	 * @QueryParam(name='actee', type='string', desc='Show only entries with this actee', required=false)
	 * @QueryParam(name='action', type='string', desc='Show only entries with this action', required=false)
	 * @QueryParam(name='before', type='integer', desc='Show only entries from before the given timestamp', required=false)
	 * @QueryParam(name='after', type='integer', desc='Show only entries from after the given timestamp', required=false)
	 * @AccessLevel("mod")
	 * @ApiTag("audit")
	 * @ApiResult(code=200, class='Audit[]', desc='The audit log entries')
	 */
	public function auditGetListEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$query = $this->db->table(static::DB_TABLE)
			->orderByDesc("time")
			->orderByDesc("id");

		$limit = $request->query["limit"]??"50";
		if (isset($limit)) {
			if (!preg_match("/^\d+$/", $limit)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "limit is not an integer value");
			}
			$query->limit((int)$limit);
		}

		$offset = $request->query["offset"]??null;
		if (isset($offset)) {
			if (!preg_match("/^\d+$/", $offset)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "offset is not an integer value");
			}
			$query->offset((int)$offset);
		}

		$before = $request->query["before"]??null;
		if (isset($before)) {
			if (!preg_match("/^\d+$/", $before)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "before is not an integer value");
			}
			$query->where("time", "<=", $before);
		}

		$after = $request->query["after"]??null;
		if (isset($after)) {
			if (!preg_match("/^\d+$/", $after)) {
				return new Response(Response::UNPROCESSABLE_ENTITY, [], "after is not an integer value");
			}
			$query->where("time", ">=", $after);
		}

		$actor = $request->query["actor"]??null;
		if (isset($actor)) {
			$query->where("actor", ucfirst(strtolower($actor)));
		}

		$actee = $request->query["actee"]??null;
		if (isset($actee)) {
			$query->where("actee", ucfirst(strtolower($actee)));
		}

		$action = $request->query["action"]??null;
		if (isset($action)) {
			$query->where("action", strtolower($action));
		}

		return new ApiResponse(
			$query->asObj(Audit::class)->toArray()
		);
	}
}

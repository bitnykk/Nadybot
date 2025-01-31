<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Exception;
use Illuminate\Support\Collection;
use JsonException;
use Nadybot\Core\{
	ClassSpec,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	SettingManager,
	Text,
	Util,
	Websocket,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Modules\PREFERENCES\Preferences,
	Registry,
	Timer,
	WebsocketClient,
};
use Nadybot\Modules\GUILD_MODULE\GuildController;
use Nadybot\Modules\WEBSERVER_MODULE\ApiResponse;
use Nadybot\Modules\WEBSERVER_MODULE\HttpProtocolWrapper;
use Nadybot\Modules\WEBSERVER_MODULE\JsonImporter;
use Nadybot\Modules\WEBSERVER_MODULE\Request;
use Nadybot\Modules\WEBSERVER_MODULE\Response;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * @author Tyrence
 * @author Nadyita
 *
 * @Instance
 *
 * Commands this controller contains:
 *  @DefineCommand(
 *		command     = 'relay',
 *		accessLevel = 'mod',
 *		description = 'Setup and modify relays between bots',
 *		help        = 'relay.txt'
 *	)
 *  @ProvidesEvent("routable(message)")
 */
class RelayController {
	public const DB_TABLE = 'relay_<myname>';
	public const DB_TABLE_LAYER = 'relay_layer_<myname>';
	public const DB_TABLE_ARGUMENT = 'relay_layer_argument_<myname>';

	/** @var array<string,ClassSpec> */
	protected array $relayProtocols = [];

	/** @var array<string,ClassSpec> */
	protected array $transports = [];

	/** @var array<string,ClassSpec> */
	protected array $stackElements = [];

	/** @var array<string,Relay> */
	public array $relays = [];

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
	public QuickRelayController $quickRelayController;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public Preferences $preferences;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public GuildController $guildController;

	/** @Inject */
	public Websocket $websocket;

	/** @Inject */
	public EventManager $eventManager;

	/** @Logger */
	public LoggerWrapper $logger;

	public WebsocketClient $tyrClient;

	/** @Inject */
	public Timer $timer;

	/**
	 * @Event("connect")
	 * @Description("Load relays from database")
	 */
	public function loadRelays() {
		$relays = $this->getRelays();
		foreach ($relays as $relayConf) {
			try {
				$relay = $this->createRelayFromDB($relayConf);
				$this->addRelay($relay);
				$relay->init(function() use ($relay) {
					$this->logger->log('INFO', "Relay " . $relay->getName() . " initialized");
				});
			} catch (Exception $e) {
				$this->logger->log('ERROR', $e->getMessage(), $e);
			}
		}
	}

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->settingManager->add(
			$this->moduleName,
			'relay_guild_abbreviation',
			'Abbreviation to use for org name',
			'edit',
			'text',
			'none',
			'none'
		);
		$this->loadStackComponents();
	}

	public function loadStackComponents(): void {
		$types = [
			"RelayProtocol" => [
				"RelayProtocol",
				[$this, "registerRelayProtocol"],
			],
			"Layer" => [
				"RelayStackMember",
				[$this, "registerStackElement"],
			],
			"Transport" => [
				"RelayTransport",
				[$this, "registerTransport"],
			]
		];
		foreach ($types as $dir => $data) {
			$files = glob(__DIR__ . "/{$dir}/*.php");
			foreach ($files as $file) {
				require_once $file;
				$className = basename($file, ".php");
				$fullClass = __NAMESPACE__ . "\\{$dir}\\{$className}";
				$spec = $this->util->getClassSpecFromClass($fullClass, $data[0]);
				if (isset($spec)) {
					$data[1]($spec);
				}
			}
		}
	}

	public function registerRelayProtocol(ClassSpec $proto): bool {
		$this->relayProtocols[strtolower($proto->name)] = $proto;
		return true;
	}

	public function registerTransport(ClassSpec $proto): bool {
		$this->transports[strtolower($proto->name)] = $proto;
		return true;
	}

	public function registerStackElement(ClassSpec $proto): bool {
		$this->stackElements[strtolower($proto->name)] = $proto;
		return true;
	}

	public function getGuildAbbreviation(): string {
		if ($this->settingManager->getString('relay_guild_abbreviation') !== 'none') {
			return $this->settingManager->getString('relay_guild_abbreviation');
		} else {
			return $this->chatBot->vars["my_guild"];
		}
	}

	/**
	 * @param array<string,ClassSpec> $specs
	 * @return string[]
	 */
	protected function renderClassSpecOverview(array $specs, string $name, string $subCommand): array {
		$count = count($specs);
		if (!$count) {
			return ["No {$name}s available."];
		}
		$blobs = [];
		foreach ($specs as $spec) {
			$description = $spec->description ?? "Someone forgot to add a description";
			$entry = "<header2>{$spec->name}<end>\n".
				"<tab>".
				join("\n<tab>", explode("\n", trim($description)));
			if (count($spec->params)) {
				$entry .= "\n<tab>[" . $this->text->makeChatcmd("details", "/tell <myname> relay list {$subCommand} {$spec->name}") . "]";
			}
			$blobs []= $entry;
		}
		$blob = join("\n\n", $blobs);
		return (array)$this->text->makeBlob("Available {$name}s ({$count})", $blob);
	}

	/**
	 * @param array<string,ClassSpec> $specs
	 * @return string[]
	 */
	protected function renderClassSpecDetails(array $specs, string $key, string $name): array {
		$spec = $specs[$key] ?? null;
		if (!isset($spec)) {
			return ["No {$name} <highlight>{$key}<end> found."];
		}
		try {
			$refClass = new ReflectionClass($spec->class);
		} catch (ReflectionException $e) {
			return ["<highlight>{$spec->name}<end> cannot be initialized."];
		}
		try {
			$refConstr = $refClass->getMethod("__construct");
			$refParams = $refConstr->getParameters();
		} catch (ReflectionException $e) {
			$refParams = [];
		}
		$description = $spec->description ?? "Someone forgot to add a description";
		$blob = "<header2>Description<end>\n".
			"<tab>" . join("\n<tab>", explode("\n", trim($description))).
			"\n";
		if (count($spec->params)) {
			$blob .= "\n<header2>Parameters<end>\n";
			$parNum = 0;
			foreach ($spec->params as $param) {
				$type = ($param->type === $param::TYPE_SECRET) ? $param::TYPE_STRING : $param->type;
				$blob .= "<tab><green>{$type}<end> <highlight>{$param->name}<end>";
				if (!$param->required) {
					if (isset($refParams[$parNum]) && $refParams[$parNum]->isDefaultValueAvailable()) {
						try {
							$blob .= " (optional, default=".
								json_encode(
									$refParams[$parNum]->getDefaultValue(),
									JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE
								) . ")";
						} catch (JsonException $e) {
							$blob .= " (optional)";
						}
					} else {
						$blob .= " (optional)";
					}
				}
				$parNum++;
				$blob .= "\n<tab><i>".
					join("</i>\n<tab><i>", explode("\n", $param->description ?? "No description")).
					"</i>\n\n";
			}
		}
		return (array)$this->text->makeBlob(
			"Detailed description for {$spec->name}",
			$blob
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list protocols?$/i")
	 */
	public function relayListProtocolsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecOverview(
				$this->relayProtocols,
				"relay protocol",
				"protocol"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list protocol (.+)$/i")
	 */
	public function relayListProtocolDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecDetails(
				$this->relayProtocols,
				$args[1],
				"relay protocol",
				"protocol"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list transports?$/i")
	 */
	public function relayListTransportsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecOverview(
				$this->transports,
				"relay transport",
				"transport"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list transport (.+)$/i")
	 */
	public function relayListTransportDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecDetails(
				$this->transports,
				$args[1],
				"relay transport",
				"transport"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list layers?$/i")
	 */
	public function relayListStacksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecOverview(
				$this->stackElements,
				"relay layer",
				"layer"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay list layer (.+)$/i")
	 */
	public function relayListStackDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply(
			$this->renderClassSpecDetails(
				$this->stackElements,
				$args[1],
				"relay layer"
			)
		);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay add (?<name>.+?) (?<spec>.+)$/is")
	 */
	public function relayAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (strlen($args['name']) > 100) {
			$sendto->reply("The name of the relay must be 100 characters max.");
			return;
		}
		$relayConf = new RelayConfig();
		$relayConf->name = $args['name'];
		$parser = new RelayLayerExpressionParser();
		try {
			$relayConf->layers = $parser->parse($args["spec"]);
		} catch (LayerParserException $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		try {
			$relay = $this->createRelay($relayConf);
		} catch (Exception $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		$layers = [];
		foreach ($relayConf->layers as $layer) {
			$layers []= $layer->toString();
		}
		$blob = $this->quickRelayController->getRouteInformation(
			$args['name'],
			in_array($layer->layer, ["tyrbot", "nadynative"])
		);
		$msg = "Relay <highlight>{$args['name']}<end> added.";
		if (!$this->messageHub->hasRouteFor($relay->getChannelName())) {
			$help = $this->text->makeBlob("setup your routing", $blob);
			$msg .= " Make sure to {$help}, otherwise no messages will be exchanged.";
		}
		$sendto->reply($msg);
	}

	public function createRelay(RelayConfig $relayConf): Relay {
		if ($this->getRelayByName($relayConf->name)) {
			throw new Exception("The relay <highlight>{$relayConf->name}<end> already exists.");
		}
		$this->db->beginTransaction();
		try {
			$relayConf->id = $this->db->insert(static::DB_TABLE, $relayConf);
			foreach ($relayConf->layers as $layer) {
				$layer->relay_id = $relayConf->id;
				$layer->id = $this->db->insert(static::DB_TABLE_LAYER, $layer);
				foreach ($layer->arguments as $argument) {
					$argument->layer_id = $layer->id;
					$argument->id = $this->db->insert(static::DB_TABLE_ARGUMENT, $argument);
				}
			}
		} catch (Throwable $e) {
			$this->db->rollback();
			throw new Exception("Error saving the relay: " . $e->getMessage());
		}
		try {
			$relay = $this->createRelayFromDB($relayConf);
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		if (!$this->addRelay($relay)) {
			$this->db->rollback();
			throw new Exception("A relay with that name is already registered");
		}
		$this->db->commit();
		$relay->init(function() use ($relay) {
			$this->logger->log('INFO', "Relay " . $relay->getName() . " initialized");
		});
		return $relay;
	}

	public function deleteRelay(RelayConfig $relay): bool {
		/** @var int[] List of modifier-ids for the route */
		$layers = array_column($relay->layers, "id");
		$this->db->beginTransaction();
		try {
			if (count($layers)) {
				$this->db->table(static::DB_TABLE_ARGUMENT)
					->whereIn("layer_id", $layers)
					->delete();
				$this->db->table(static::DB_TABLE_LAYER)
					->where("relay_id", $relay->id)
					->delete();
			}
			$this->db->table(static::DB_TABLE)
				->delete($relay->id);
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		$this->db->commit();
		$liveRelay = $this->relays[$relay->name] ?? null;
		unset($this->relays[$relay->name]);
		if (!isset($liveRelay)) {
			return false;
		}
		$liveRelay->deinit(function(Relay $relay) {
			$this->logger->log('INFO', "Relay " . $relay->getName() . " destroyed");
			unset($relay);
		});
		return true;
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay describe (?<id>\d+)$/is")
	 * @Matches("/^relay describe (?<name>.+)$/is")
	 */
	public function relayDescribeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== "msg") {
			$sendto->reply(
				"Because the relay stack might contain passwords, ".
				"this command works only in tells."
			);
			return;
		}
		$relay = isset($args['id'])
			? $this->getRelay((int)$args['id'])
			: $this->getRelayByName($args['name']);
		/** @var ?RelayConfig $relay */
		if (!isset($relay)) {
			$sendto->reply(
				"Relay <highlight>".
				(isset($args['id']) ? "#{$args['id']}" : $args['name']).
				"<end> not found."
			);
			return;
		}
		$msg = "<symbol>relay add {$relay->name}";
		foreach ($relay->layers as $layer) {
			$msg .= " " . $layer->toString();
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay$/i")
	 * @Matches("/^relay list$/i")
	 */
	public function relayListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$relays = $this->getRelays();
		if (empty($relays)) {
			$sendto->reply("There are no relays defined.");
			return;
		}
		$blobs = [];
		foreach ($relays as $relay) {
			$blob = "<header2>{$relay->name}<end>\n";
			if (isset($this->transports[$relay->layers[0]->layer])) {
				$secrets = $this->transports[$relay->layers[0]->layer]->getSecrets();
				$blob .= "<tab>Transport: <highlight>" . $relay->layers[0]->toString("transport", $secrets) . "<end>\n";
			} else {
				$blob .= "<tab>Transport: <highlight>{$relay->layers[0]->layer}(<red>error<end>)<end>\n";
			}
			for ($i = 1; $i < count($relay->layers)-1; $i++) {
				if (isset($this->stackElements[$relay->layers[$i]->layer])) {
					$secrets = $this->stackElements[$relay->layers[$i]->layer]->getSecrets();
					$blob .= "<tab>Layer: <highlight>" . $relay->layers[$i]->toString("layer", $secrets) . "<end>\n";
				} else {
					$blob .= "<tab>Layer: <highlight>{$relay->layers[$i]->layer}(<red>error<end>)<end>\n";
				}
			}
			$layerName = $relay->layers[count($relay->layers)-1]->layer;
			if (isset($this->relayProtocols[$layerName])) {
				$secrets = $this->relayProtocols[$relay->layers[count($relay->layers)-1]->layer]->getSecrets();
				$blob .= "<tab>Protocol: <highlight>" . $relay->layers[count($relay->layers)-1]->toString("protocol", $secrets) . "<end>\n";
			} else {
				$blob .= "<tab>Protocol: <highlight>{$layerName}(<red>error<end>)<end>\n";
			}
			$live = $this->relays[$relay->name] ?? null;
			if (isset($live)) {
				$blob .= "<tab>Status: " . $live->getStatus()->toString();
			} else {
				$blob .= "<tab>Status: <red>error<end>";
			}
			$delLink = $this->text->makeChatcmd(
				"delete",
				"/tell <myname> relay rem {$relay->id}"
			);
			$desclLink = $this->text->makeChatcmd(
				"describe",
				"/tell <myname> relay describe {$relay->id}"
			);
			$blobs []= $blob . " [{$delLink}] [{$desclLink}]";
		}
		$msg = $this->text->makeBlob(
			"Relays (" . count($relays) . ")",
			join("\n\n", $blobs)
		);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("relay")
	 * @Matches("/^relay (?:rem|del) (?<id>\d+)$/i")
	 * @Matches("/^relay (?:rem|del) (?<name>.+)$/i")
	 */
	public function relayRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$relay = isset($args['id'])
			? $this->getRelay((int)$args['id'])
			: $this->getRelayByName($args['name']);
		if (!isset($relay)) {
			$sendto->reply(
				"Relay <highlight>".
				(isset($args['id']) ? "#{$args['id']}" : $args['name']).
				"<end> not found."
			);
			return;
		}
		try {
			$this->deleteRelay($relay);
		} catch (Exception $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		$sendto->reply(
			"Relay #{$relay->id} (<highlight>{$relay->name}<end>) deleted."
		);
	}

	/**
	 * Read all defined relays from the database
	 * @return RelayConfig[]
	 */
	public function getRelays(): array {
		$arguments = $this->db->table(static::DB_TABLE_ARGUMENT)
			->orderBy("id")
			->asObj(RelayLayerArgument::class)
			->groupBy("layer_id");
		$layers = $this->db->table(static::DB_TABLE_LAYER)
			->orderBy("id")
			->asObj(RelayLayer::class)
			->each(function (RelayLayer $layer) use ($arguments): void {
				$layer->arguments = $arguments->get($layer->id, new Collection())->toArray();
			})
			->groupBy("relay_id");
		$relays = $this->db->table(static::DB_TABLE)
			->orderBy("id")
			->asObj(RelayConfig::class)
			->each(function(RelayConfig $relay) use ($layers): void {
				$relay->layers = $layers->get($relay->id, new Collection())->toArray();
			})
			->toArray();
		return $relays;
	}

	/** Read a relay by its ID */
	public function getRelay(int $id): ?RelayConfig {
		/** @var RelayConfig|null */
		$relay = $this->db->table(static::DB_TABLE)
			->where("id", $id)
			->limit(1)
			->asObj(RelayConfig::class)
			->first();
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Read a relay by its name */
	public function getRelayByName(string $name): ?RelayConfig {
		/** @var RelayConfig|null */
		$relay = $this->db->table(static::DB_TABLE)
			->where("name", $name)
			->limit(1)
			->asObj(RelayConfig::class)
			->first();
		if (!isset($relay)) {
			return null;
		}
		$this->completeRelay($relay);
		return $relay;
	}

	/** Add layers and args to a relay from the DB */
	protected function completeRelay(RelayConfig $relay): void {
		$relay->layers = $this->db->table(static::DB_TABLE_LAYER)
		->where("relay_id", $relay->id)
		->orderBy("id")
		->asObj(RelayLayer::class)
		->toArray();
		foreach ($relay->layers as $layer) {
			$layer->arguments = $this->db->table(static::DB_TABLE_ARGUMENT)
			->where("layer_id", $layer->id)
			->orderBy("id")
			->asObj(RelayLayerArgument::class)
			->toArray();
		}
	}

	public function addRelay(Relay $relay): bool {
		if (isset($this->relays[$relay->getName()])) {
			return false;
		}
		$this->relays[$relay->getName()] = $relay;
		return true;
	}

	protected function createRelayFromDB(RelayConfig $conf): Relay {
		$relay = new Relay($conf->name);
		Registry::injectDependencies($relay);
		if (count($conf->layers) < 2) {
			throw new Exception(
				"Every relay must have at least 1 transport and 1 protocol."
			);
		}
		// The order is assumed to be transport --- protocol
		// If it's the other way around, let's reverse it
		if (
			!isset($this->transports[$conf->layers[0]->layer])
			&& isset($this->relayProtocols[$conf->layers[0]->layer])
		) {
			$conf->layers = array_reverse($conf->layers);
		}

		$stack = [];
		$transport = array_shift($conf->layers);
		$spec = $this->transports[strtolower($transport->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$transport->layer}<end> is not a ".
				"known transport for relaying. Perhaps the order was wrong?"
			);
		}
		$transportLayer = $this->getRelayLayer(
			$transport->layer,
			$transport->getKVArguments(),
			$spec
		);

		for ($i = 0; $i < count($conf->layers)-1; $i++) {
			$layerName = strtolower($conf->layers[$i]->layer);
			$spec = $this->stackElements[$layerName] ?? null;
			if (!isset($spec)) {
				throw new Exception(
					"<highlight>{$layerName}<end> is not a ".
					"known layer for relaying. Perhaps the order was wrong?"
				);
			}
			$stack []= $this->getRelayLayer(
				$layerName,
				$conf->layers[$i]->getKVArguments(),
				$spec
			);
		}

		$proto = array_pop($conf->layers);
		$spec = $this->relayProtocols[strtolower($proto->layer)] ?? null;
		if (!isset($spec)) {
			throw new Exception(
				"<highlight>{$proto->layer}<end> is not a ".
				"known relay protocol. Perhaps the order was wrong?"
			);
		}
		$protocolLayer = $this->getRelayLayer(
			$proto->layer,
			$proto->getKVArguments(),
			$spec
		);
		$relay->setStack($transportLayer, $protocolLayer, ...$stack);
		return $relay;
	}

	/**
	 * Get a fully configured relay layer or null if not possible
	 * @param string $name Name of the layer
	 * @param array<string,string> $params The parameters of the layer
	 */
	public function getRelayLayer(string $name, array $params, ClassSpec $spec): object {
		$name = strtolower($name);
		$arguments = [];
		$paramPos = 0;
		foreach ($spec->params as $parameter) {
			$value = $params[$parameter->name] ?? null;
			if (isset($value)) {
				switch ($parameter->type) {
					case $parameter::TYPE_BOOL:
						if (!in_array($value, ["true", "false"])) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be 'true' or 'false', ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= $value === "true";
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_INT:
						if (!preg_match("/^[+-]?\d+/", $value)) {
							throw new Exception(
								"Argument <highlight>{$parameter->name}<end> to ".
								"<highlight>{$name}<end> must be a number, ".
								"<highlight>'{$value}'<end> given."
							);
						}
						$arguments []= (int)$value;
						unset($params[$parameter->name]);
						break;
					case $parameter::TYPE_STRING_ARRAY:
						$value = array_map(fn($x) => (string)$x, (array)$value);
						$arguments []= $value;
						unset($params[$parameter->name]);
						break;
					default:
						$arguments []= (string)$value;
						unset($params[$parameter->name]);
				}
			} elseif ($parameter->required) {
				throw new Exception(
					"Missing required argument <highlight>{$parameter->name}<end> ".
					"to <highlight>{$name}<end>."
				);
			} else {
				$ref = new ReflectionMethod($spec->class, "__construct");
				$conParams = $ref->getParameters();
				if (!isset($conParams[$paramPos])) {
					continue;
				}
				if ($conParams[$paramPos]->isOptional()) {
					$arguments []= $conParams[$paramPos]->getDefaultValue();
				}
			}
			$paramPos++;
		}
		if (!empty($params)) {
			throw new Exception(
				"Unknown parameter" . (count($params) > 1 ? "s" : "").
				" <highlight>".
				(new Collection(array_keys($params)))
					->join("<end>, <highlight>", "<end> and <highlight>").
				"<end> to <highlight>{$name}<end>."
			);
		}
		$class = $spec->class;
		try {
			$result = new $class(...$arguments);
			Registry::injectDependencies($result);
			return $result;
		} catch (Throwable $e) {
			throw new Exception("There was an error setting up the {$name} layer: " . $e->getMessage());
		}
	}

	/**
	 * List all relay transports
	 * @Api("/relay-component/transport")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='ClassSpec[]', desc='The available relay transport layers')
	 */
	public function apiGetTransportsEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->transports));
	}

	/**
	 * List all relay layers
	 * @Api("/relay-component/layer")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='ClassSpec[]', desc='The available generic relay layers')
	 */
	public function apiGetLayersEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->stackElements));
	}

	/**
	 * List all relay protocols
	 * @Api("/relay-component/protocol")
	 * @GET
	 * @AccessLevel("all")
	 * @ApiResult(code=200, class='ClassSpec[]', desc='The available relay protocols')
	 */
	public function apiGetProtocolsEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->relayProtocols));
	}

	/**
	 * List all relays
	 * @Api("/relay")
	 * @GET
	 * @AccessLevelFrom("relay")
	 * @ApiResult(code=200, class='RelayConfig[]', desc='The configured relays')
	 */
	public function apiGetRelaysEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		return new ApiResponse(array_values($this->getRelays()));
	}

	/**
	 * Get a single relay
	 * @Api("/relay/%s")
	 * @GET
	 * @AccessLevelFrom("relay")
	 * @ApiResult(code=200, class='RelayConfig', desc='The configured relay')
	 * @ApiResult(code=404, desc='Relay not found')
	 */
	public function apiGetRelayByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($relay);
	}

	/**
	 * Delete a relay
	 * @Api("/relay/%s")
	 * @DELETE
	 * @AccessLevelFrom("relay")
	 * @ApiResult(code=204, desc='The relay was deleted')
	 * @ApiResult(code=404, desc='Relay not found')
	 */
	public function apiDelRelayByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		$relay = $this->getRelayByName($relay);
		if (!isset($relay)) {
			return new Response(Response::NOT_FOUND);
		}
		try {
			$this->deleteRelay($relay);
		} catch (Exception $e) {
			return new Response(Response::INTERNAL_SERVER_ERROR, [], $e->getMessage());
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Get a relay's status
	 * @Api("/relay/%s/status")
	 * @GET
	 * @AccessLevelFrom("relay")
	 * @ApiResult(code=200, class='RelayStatus', desc='The status message of the relay')
	 * @ApiResult(code=404, desc='Relay not found')
	 */
	public function apiGetRelayStatusByNameEndpoint(Request $request, HttpProtocolWrapper $server, string $relay): Response {
		if (!isset($this->relays[$relay])) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($this->relays[$relay]->getStatus());
	}

	/**
	 * Create a new relay
	 * @Api("/relay")
	 * @POST
	 * @AccessLevelFrom("relay")
	 * @ApiResult(code=204, desc='Relay created successfully')
	 */
	public function apiCreateRelay(Request $request, HttpProtocolWrapper $server): Response {
		$relay = $request->decodedBody;
		try {
			/** @var RelayConfig */
			$relay = JsonImporter::convert(RelayConfig::class, $relay);
			foreach ($relay->layers as &$layer) {
				/** @var RelayLayer */
				$layer = JsonImporter::convert(RelayLayer::class, $layer);
				foreach ($layer->arguments as &$argument) {
					$argument = JsonImporter::convert(RelayLayerArgument::class, $argument);
				}
			}
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		try {
			$this->createRelay($relay);
		} catch (Exception $e) {
			return new Response(
				Response::INTERNAL_SERVER_ERROR,
				[],
				$this->text->formatMessage($e->getMessage())
			);
		}
		return new Response(Response::NO_CONTENT);
	}
}

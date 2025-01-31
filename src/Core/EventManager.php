<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use Addendum\ReflectionAnnotatedMethod;
use Nadybot\Core\DBSchema\EventCfg;
use Nadybot\Core\Modules\MESSAGES\MessageHubController;

/**
 * @Instance
 */
class EventManager {
	public const DB_TABLE = "eventcfg_<myname>";

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public MessageHubController $messageHubController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,string[]> */
	public array $events = [];

	/** @var array<string,callable[]> */
	public array $dynamicEvents = [];

	/** @var array<array<string,mixed>> */
	private array $cronevents = [];

	/** @var string[] */
	private array $eventTypes = [
		'msg', 'priv', 'extpriv', 'guild', 'joinpriv', 'leavepriv',
		'extjoinpriv', 'extleavepriv', 'sendmsg', 'sendpriv', 'sendguild',
		'orgmsg', 'extjoinprivrequest', 'logon', 'logoff', 'towers',
		'connect', 'setup', 'amqp', 'pong', 'otherleavepriv'
	];

	private int $lastCronTime = 0;
	private bool $areConnectEventsFired = false;
	protected bool $eventsReady = false;
	/** Events that were disabled before eventhandler was initialized */
	protected array $dontActivateEvents = [];
	public const PACKET_TYPE_REGEX = '/packet\(\d+\)/';
	public const TIMER_EVENT_REGEX = '/timer\(([0-9a-z]+)\)/';

	/**
	 * @name: register
	 * @description: Registers an event on the bot so it can be configured
	 */
	public function register(string $module, string $type, string $filename, string $description='none', ?string $help='', ?int $defaultStatus=null): void {
		$type = strtolower($type);

		$this->logger->log('DEBUG', "Registering event Type:($type) Handler:($filename) Module:($module)");

		if (!$this->isValidEventType($type) && $this->getTimerEventTime($type) === 0) {
			$this->logger->log('ERROR', "Error registering event Type:($type) Handler:($filename) Module:($module). The type is not a recognized event type!");
			return;
		}

		[$name, $method] = explode(".", $filename);
		if (!Registry::instanceExists($name)) {
			$this->logger->log('ERROR', "Error registering method $filename for event type $type.  Could not find instance '$name'.");
			return;
		}

		try {
			if (isset($this->chatBot->existing_events[$type][$filename])) {
				$this->db->table(self::DB_TABLE)
					->where("type", $type)
					->where("file", $filename)
					->where("module", $module)
					->update([
						"verify" => 1,
						"description" => $description,
						"help" => $help,
					]);
				return;
			}
			if ($defaultStatus === null) {
				if ($this->chatBot->vars['default_module_status'] == 1) {
					$status = 1;
				} else {
					$status = 0;
				}
			} else {
				$status = $defaultStatus;
			}
				$this->db->table(self::DB_TABLE)
					->insert([
						"module" => $module,
						"type" => $type,
						"file" => $filename,
						"verify" => 1,
						"description" => $description,
						"status" => $status,
						"help" => $help,
					]);
		} catch (SQLException $e) {
			$this->logger->log('ERROR', "Error registering method $filename for event type $type: " . $e->getMessage());
		}
	}

	/**
	 * Activates an event
	 */
	public function activate(string $type, string $filename): void {
		$type = strtolower($type);

		$this->logger->log('DEBUG', "Activating event Type:($type) Handler:($filename)");

		[$name, $method] = explode(".", $filename);
		if (!Registry::instanceExists($name)) {
			$this->logger->log('ERROR', "Error activating method $filename for event type $type.  Could not find instance '$name'.");
			return;
		}

		if ($type == "setup") {
			$eventObj = new Event();
			$eventObj->type = 'setup';

			$this->callEventHandler($eventObj, $filename, []);
		} elseif ($this->isValidEventType($type)) {
			if (!isset($this->events[$type]) || !in_array($filename, $this->events[$type])) {
				$this->events[$type] []= $filename;
			} elseif ($this->chatBot->isReady()) {
				$this->logger->log('ERROR', "Error activating event Type:($type) Handler:($filename). Event already activated!");
			}
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$key = $this->getKeyForCronEvent($time, $filename);
				if ($key === null) {
					$this->cronevents[] = ['nextevent' => 0, 'filename' => $filename, 'time' => $time];
				} else {
					$this->logger->log('ERROR', "Error activating event Type:($type) Handler:($filename). Event already activated!");
				}
			} else {
				$this->logger->log('ERROR', "Error activating event Type:($type) Handler:($filename). The type is not a recognized event type!");
			}
		}
	}

	/**
	 * Subscribe to an event
	 */
	public function subscribe(string $type, callable $callback): void {
		$type = strtolower($type);

		if ($type == "setup") {
			return;
		}
		if ($this->isValidEventType($type)) {
			$this->dynamicEvents[$type] ??= [];
			if (!in_array($callback, $this->dynamicEvents[$type], true)) {
				$this->dynamicEvents[$type] []= $callback;
			}
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$this->logger->log('ERROR', "Dynamic timers are currently not supported");
			} else {
				$this->logger->log('ERROR', "Error activating event Type $type. The type is not a recognized event type!");
			}
		}
	}

	/**
	 * Unsubscribe from an event
	 */
	public function unsubscribe(string $type, callable $callback): void {
		$type = strtolower($type);

		if ($type == "setup") {
			return;
		}
		if ($this->isValidEventType($type)) {
			if (!isset($this->dynamicEvents[$type])) {
				return;
			}
			$this->dynamicEvents[$type] = array_values(
				array_filter(
					$this->dynamicEvents[$type],
					function($c) use ($callback): bool {
						return $c !== $callback;
					}
				)
			);
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$this->logger->log('ERROR', "Dynamic timers are currently not supported");
			} else {
				$this->logger->log('ERROR', "Error activating event Type $type. The type is not a recognized event type!");
			}
		}
	}

	/**
	 * Change the time when a cron event gets called next time
	 */
	public function setCronNextEvent(int $key, int $nextEvent): bool {
		if (!isset($this->cronevents[$key])) {
			return false;
		}
		$this->cronevents[$key]['nextevent'] = $nextEvent;
		return true;
	}

	/**
	 * Deactivates an event
	 */
	public function deactivate(string $type, string $filename): void {
		$type = strtolower($type);

		$this->logger->log('debug', "Deactivating event Type:($type) Handler:($filename)");

		if ($this->isValidEventType($type)) {
			if (in_array($filename, $this->events[$type]??[])) {
				$found = true;
				$temp = array_flip($this->events[$type]);
				unset($this->events[$type][$temp[$filename]]);
			}
		} else {
			$time = $this->getTimerEventTime($type);
			if ($time > 0) {
				$key = $this->getKeyForCronEvent($time, $filename);
				if ($key !== null) {
					$found = true;
					unset($this->cronevents[$key]);
				}
			} else {
				$this->logger->log('ERROR', "Error deactivating event Type:($type) Handler:($filename). The type is not a recognized event type!");
				return;
			}
		}

		if (!$found) {
			$this->logger->log('ERROR', "Error deactivating event Type:($type) Handler:($filename). The event is not active or doesn't exist!");
		}
	}

	/**
	 * Activates events that are annotated on one or more method names
	 * if the events are not already activated
	 */
	public function activateIfDeactivated(object $obj, string ...$eventMethods): void {
		foreach ($eventMethods as $eventMethod) {
			$filename = Registry::formatName(get_class($obj));
			$call = $filename . "." . $eventMethod;
			$type = $this->getEventTypeByMethod($obj, $eventMethod);
			if ($type === null) {
				$this->logger->log('ERROR', "Could not find event for '$call'");
				return;
			}
			if ($this->isValidEventType($type)) {
				if (isset($this->events[$type]) && in_array($call, $this->events[$type])) {
					// event already activated
					continue;
				}
				$this->activate($type, $call);
			} else {
				$time = $this->getTimerEventTime($type);
				if ($time > 0) {
					$key = $this->getKeyForCronEvent($time, $call);
					if ($key === null) {
						$this->cronevents[] = ['nextevent' => 0, 'filename' => $call, 'time' => $time];
					}
				} else {
					$this->logger->log('ERROR', "Error activating event Type:($type) Handler:($call). The type is not a recognized event type!");
				}
			}
		}
	}

	/**
	 * Deactivates events that are annotated on one or more method names
	 * if the events are not already deactivated
	 */
	public function deactivateIfActivated(object $obj, string ...$eventMethods): void {
		foreach ($eventMethods as $eventMethod) {
			$call = Registry::formatName(get_class($obj)) . "." . $eventMethod;
			$type = $this->getEventTypeByMethod($obj, $eventMethod);
			if ($type === null) {
				$this->logger->log('ERROR', "Could not find event for '$call'");
				return;
			}
			if ($this->isValidEventType($type)) {
				if (!isset($this->events[$type]) || !in_array($call, $this->events[$type])) {
					// event already deactivated
					continue;
				}
				$this->deactivate($type, $call);
			} else {
				$time = $this->getTimerEventTime($type);
				if ($time > 0) {
					if ($this->eventsReady === false) {
						$this->dontActivateEvents[$type] ??= [];
						$this->dontActivateEvents[$type][$call] = true;
					} else {
						$key = $this->getKeyForCronEvent($time, $call);
						if ($key !== null) {
							unset($this->cronevents[$key]);
						}
					}
				} else {
					$this->logger->log('ERROR', "Error deactivating event Type:($type) Handler:($call). The type is not a recognized event type!");
				}
			}
		}
	}

	public function getEventTypeByMethod(object $obj, string $methodName): ?string {
		$method = new ReflectionAnnotatedMethod($obj, $methodName);
		if ($method->hasAnnotation('Event')) {
			return strtolower($method->getAnnotation('Event')->value);
		}
		return null;
	}

	public function getKeyForCronEvent(int $time, string $filename): ?int {
		foreach ($this->cronevents as $key => $event) {
			if ($time == $event['time'] && $event['filename'] == $filename) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Loads the active events into memory and activates them
	 */
	public function loadEvents(): void {
		$this->logger->log('DEBUG', "Loading enabled events");

		$this->db->table(self::DB_TABLE)
			->where("status", 1)
			->asObj(EventCfg::class)
			->each(function(EventCfg $row) {
				if (isset($this->dontActivateEvents[$row->type][$row->file])) {
					unset($this->dontActivateEvents[$row->type][$row->file]);
				} else {
					$this->activate($row->type, $row->file);
				}
			});
		$this->eventsReady = true;
		$this->dontActivateEvents = [];
	}

	/**
	 * Call timer events
	 */
	public function crons(): void {
		$time = time();

		if ($this->lastCronTime == $time) {
			return;
		}
		$this->lastCronTime = $time;

		$this->logger->log('DEBUG', "Executing cron events at '$time'");
		foreach ($this->cronevents as $key => $event) {
			if ($this->cronevents[$key]['nextevent'] <= $time) {
				$this->logger->log('DEBUG', "Executing cron event '${event['filename']}'");

				$eventObj = new Event();
				$eventObj->type = strtolower((string)$event['time']);

				$this->cronevents[$key]['nextevent'] = $time + $event['time'];
				$this->callEventHandler($eventObj, $event['filename'], [$key]);
			}
		}
	}

	/**
	 * Execute Events that needs to be executed right after login
	 */
	public function executeConnectEvents(): void {

		if ($this->areConnectEventsFired) {
			return;
		}
		$this->areConnectEventsFired = true;

		$this->logger->log('DEBUG', "Executing connected events");
		$this->messageHubController->loadRouting();

		$eventObj = new Event();
		$eventObj->type = 'connect';

		$this->fireEvent($eventObj);
	}

	public function isValidEventType(string $type): bool {
		if (in_array($type, $this->eventTypes)) {
			return true;
		}
		if (preg_match(self::PACKET_TYPE_REGEX, $type) === 1) {
			return true;
		}
		foreach ($this->eventTypes as $check) {
			if (fnmatch($type, $check, FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}

	public function getTimerEventTime(string $type): int {
		if (preg_match(self::TIMER_EVENT_REGEX, $type, $arr) == 1) {
			$time = $this->util->parseTime($arr[1]);
			if ($time > 0) {
				return $time;
			}
		} else { // legacy timer event format
			$time = $this->util->parseTime($type);
			if ($time > 0) {
				return $time;
			}
		}
		return 0;
	}

	public function fireEvent(Event $eventObj, ...$args): void {
		foreach ($this->events as $type => $handlers) {
			if ($eventObj->type !== $type && !fnmatch($type, $eventObj->type, FNM_CASEFOLD)) {
				continue;
			}
			foreach ($handlers as $filename) {
				$this->callEventHandler($eventObj, $filename, $args);
			}
		}
		foreach ($this->dynamicEvents as $type => $handlers) {
			if ($eventObj->type !== $type && !fnmatch($type, $eventObj->type, FNM_CASEFOLD)) {
				continue;
			}
			foreach ($handlers as $callback) {
				$callback($eventObj, ...$args);
			}
		}
	}

	/**
	 * @throws StopExecutionException
	 */
	public function callEventHandler(Event $eventObj, string $handler, array $args): void {
		$this->logger->log('DEBUG', "Executing handler '$handler' for event type '$eventObj->type'");

		try {
			[$name, $method] = explode(".", $handler);
			$instance = Registry::getInstance($name);
			if ($instance === null) {
				$this->logger->log('ERROR', "Could not find instance for name '$name' in '$handler' for event type '$eventObj->type'");
			} else {
				$instance->$method($eventObj, ...$args);
			}
		} catch (StopExecutionException $e) {
			throw $e;
		} catch (Exception $e) {
			$this->logger->log(
				'ERROR',
				"Error calling event handler '$handler': " . $e->getMessage(),
				$e
			);
		}
	}

	/**
	 * Dynamically add an event to the allowed types
	 */
	public function addEventType(string $eventType): bool {
		$eventType = strtolower($eventType);

		if (in_array($eventType, $this->eventTypes)) {
			$this->logger->log('WARN', "Event type already registered: '$eventType'");
			return false;
		}
		$this->eventTypes []= $eventType;
		return true;
	}
}

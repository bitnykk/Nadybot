<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Illuminate\Support\Collection;
use Nadybot\Core\Registry;
use Nadybot\Core\DBSchema\RouteHopFormat;

class Source {
	public const DB_TABLE = "route_hop_format_<myname>";

	public const RELAY = "relay";
	public const ORG = "aoorg";
	public const PUB = "aopub";
	public const PRIV = "aopriv";
	public const TELL = "aotell";
	public const WEB = "web";
	public const DISCORD_PRIV = "discordpriv";
	public const DISCORD_MSG = "discordmsg";
	public const TRADEBOT = "tradebot";
	public const IRC = "irc";
	public const SYSTEM = "system";
	public const CONSOLE = "console";

	public string $name = "";
	public ?string $label = null;
	public string $type = self::ORG;
	public int $server = 5;

	/** @var Collection<RouteHopFormat> */
	public static Collection $format;

	public function __construct(string $type, string $name, ?string $label=null, ?int $dimension=null) {
		$this->type = $type;
		$this->name = $name;
		$this->label = $label;
		if (!isset($dimension)) {
			$this->server = (int)Registry::getInstance("chatBot")->vars["dimension"];
		} else {
			$this->server = $dimension;
		}
	}

	public function getFormat(): ?RouteHopFormat {
		$exactMatch = static::$format->first(
			function (RouteHopFormat $format): bool {
				return strpos($format->hop, "(") !== false
					&& fnmatch($format->hop, "{$this->type}({$this->name})", FNM_CASEFOLD);
			}
		);
		$exactMatch ??= static::$format->first(
			function (RouteHopFormat $format): bool {
				return fnmatch($format->hop, $this->type, FNM_CASEFOLD);
			}
		);
		return $exactMatch;
	}

	public function render(?Source $lastHop): ?string {
		$name = $this->label ?? $this->name;
		if (isset($lastHop) && $this->type === static::PRIV && $lastHop->type === static::ORG) {
			$name = $this->label ?? "Guest";
		}
		$exactMatch = $this->getFormat();
		if (!isset($exactMatch)) {
			return $name;
		}
		/** @var ?RouteHopFormat $exactMatch */
		if ($exactMatch->render === false) {
			return null;
		}
		if (strpos($exactMatch->format, "%s") !== false) {
			return sprintf($exactMatch->format, $name);
		}
		return $exactMatch->format;
	}
}

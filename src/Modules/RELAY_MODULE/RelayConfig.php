<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;

class RelayConfig extends DBRow {
	/**
	 * The unique ID of this relay config
	 * @json:ignore
	 */
	public int $id;

	/** The name of this relay */
	public string $name;

	/**
	 * @db:ignore
	 * @var RelayLayer[]
	 */
	public array $layers = [];
}

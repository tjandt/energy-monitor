<?php
/**
 * Config.php – lädt config.php (+ optionale config.local.php) genau einmal.
 *
 * config.local.php ist in .gitignore eingetragen und überschreibt rekursiv
 * einzelne Werte aus config.php, z.B. die DB-Zugangsdaten:
 *
 *   <?php return ['db' => ['host' => '...', 'user' => '...', 'pass' => '...']];
 */

final class Config {

	private static ?array $data = null;

	public static function all(): array {
		if (self::$data === null) {
			$root = dirname(__DIR__);
			$cfg = require $root . '/config.php';
			if (is_file($root . '/config.local.php')) {
				$cfg = array_replace_recursive($cfg, require $root . '/config.local.php');
			}
			self::$data = $cfg;
			date_default_timezone_set($cfg['app']['timezone']);
		}
		return self::$data;
	}

	public static function get(string $section): array {
		return self::all()[$section] ?? [];
	}

	/** Nur für Tests: Konfiguration ersetzen. */
	public static function override(array $cfg): void {
		self::$data = $cfg;
		date_default_timezone_set($cfg['app']['timezone']);
	}
}

<?php
/**
 * Database.php – PDO Singleton
 */

class Database {
	private static ?PDO $instance = null;

	public static function getConnection(): PDO {
		if (self::$instance === null) {
			$cfg = require __DIR__ . '/../config.php';
			$db  = $cfg['db'];

			$dsn = sprintf(
				'mysql:host=%s;port=%d;dbname=%s;charset=%s',
				$db['host'],
				$db['port'],
				$db['dbname'],
				$db['charset']
			);

			try {
				self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
				]);
			} catch (PDOException $e) {
				error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
				throw new RuntimeException('Datenbankverbindung fehlgeschlagen. Bitte Konfiguration prüfen.');
			}
		}
		return self::$instance;
	}
}

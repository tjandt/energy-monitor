<?php
/**
 * Database.php – PDO Singleton
 */

require_once __DIR__ . '/Config.php';

/** Meldung ist für Anwender bestimmt (enthält keine Zugangsdaten). */
class DatabaseUnavailableException extends RuntimeException {}

class Database {
	private static ?PDO $instance = null;

	public static function getConnection(): PDO {
		if (self::$instance === null) {
			$db = Config::get('db');

			// 'dsn' ist optional (z.B. für Tests/Demo mit SQLite); sonst MySQL aus host/port/dbname
			$dsn = $db['dsn'] ?? sprintf(
				'mysql:host=%s;port=%d;dbname=%s;charset=%s',
				$db['host'],
				$db['port'],
				$db['dbname'],
				$db['charset']
			);

			try {
				self::$instance = new PDO($dsn, $db['user'] ?? null, $db['pass'] ?? null, [
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
					PDO::ATTR_TIMEOUT            => 5,
				]);
			} catch (PDOException $e) {
				error_log('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
				throw new DatabaseUnavailableException('Datenbankverbindung fehlgeschlagen. Bitte Konfiguration prüfen.');
			}
		}
		return self::$instance;
	}
}

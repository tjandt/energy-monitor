<?php
/**
 * Auth.php – optionaler Passwortschutz (config.php -> auth.enabled).
 *
 * Ein gemeinsames Passwort (password_hash in der Konfiguration), Session-Cookie
 * (HttpOnly, SameSite=Lax) und eine IP-basierte Sperre gegen Durchprobieren.
 * Ist der Schutz aktiviert, aber kein Hash hinterlegt, wird jeder Zugriff
 * abgewiesen (fail closed).
 */

require_once __DIR__ . '/Config.php';

final class Auth {

	/** Verzeichnis für die Zähler der Fehlversuche (für Tests überschreibbar). */
	public static ?string $stateDir = null;

	public static function enabled(): bool {
		return !empty(Config::get('auth')['enabled']);
	}

	public static function isLoggedIn(): bool {
		if (!self::enabled()) return true;
		self::startSession();
		return ($_SESSION['em_auth'] ?? false) === true;
	}

	/** Für Seiten: nicht angemeldet -> Weiterleitung zum Login. */
	public static function requirePage(): void {
		if (self::isLoggedIn()) {
			if (self::enabled()) session_write_close();
			return;
		}
		header('Location: login.php');
		exit;
	}

	/** Für die API: nicht angemeldet -> 401 als JSON. */
	public static function requireApi(): void {
		if (self::isLoggedIn()) {
			if (self::enabled()) session_write_close(); // Session nicht für parallele Requests sperren
			return;
		}
		http_response_code(401);
		echo json_encode(['success' => false, 'error' => 'Nicht angemeldet', 'login_required' => true]);
		exit;
	}

	public static function verify(string $password, string $hash): bool {
		return $hash !== '' && password_verify($password, $hash);
	}

	/**
	 * @return string|null Fehlermeldung oder null bei Erfolg
	 */
	public static function login(string $password, string $ip): ?string {
		$cfg = Config::get('auth');
		if (empty($cfg['password_hash'])) {
			return 'Kein Passwort-Hash konfiguriert (config.php → auth.password_hash).';
		}
		if (($wait = self::lockedForSeconds($ip)) > 0) {
			return 'Zu viele Fehlversuche. Bitte in ' . (int) ceil($wait / 60) . ' Min. erneut versuchen.';
		}
		if (!self::verify($password, (string) $cfg['password_hash'])) {
			self::recordFailure($ip);
			usleep(500000); // bremst Durchprobieren zusätzlich
			return 'Falsches Passwort.';
		}
		self::clearFailures($ip);
		self::startSession();
		session_regenerate_id(true);
		$_SESSION['em_auth'] = true;
		session_write_close();
		return null;
	}

	public static function logout(): void {
		self::startSession();
		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$p = session_get_cookie_params();
			setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax']);
		}
		session_destroy();
	}

	private static function startSession(): void {
		if (session_status() === PHP_SESSION_ACTIVE) return;
		$lifetime = (int) (Config::get('auth')['session_hours'] ?? 24) * 3600;
		ini_set('session.gc_maxlifetime', (string) $lifetime);
		session_name('em_session');
		session_set_cookie_params([
			'lifetime' => $lifetime,
			'path'     => '/',
			'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		session_start();
	}

	// ------------------------------------------------------------------
	// Sperre nach Fehlversuchen
	// ------------------------------------------------------------------

	private static function stateFile(string $ip): string {
		return (self::$stateDir ?? sys_get_temp_dir()) . '/em_auth_' . sha1($ip) . '.json';
	}

	private static function readState(string $ip): array {
		$raw = @file_get_contents(self::stateFile($ip));
		$data = $raw ? json_decode($raw, true) : null;
		return is_array($data) ? $data : ['count' => 0, 'until' => 0];
	}

	public static function lockedForSeconds(string $ip): int {
		return max(0, (int) self::readState($ip)['until'] - time());
	}

	public static function recordFailure(string $ip): void {
		$cfg = Config::get('auth');
		$state = self::readState($ip);
		// nach Ablauf der Sperre beginnt die Zählung neu
		if ($state['until'] > 0 && $state['until'] <= time()) $state = ['count' => 0, 'until' => 0];
		$state['count']++;
		if ($state['count'] >= (int) ($cfg['max_attempts'] ?? 5)) {
			$state['until'] = time() + 60 * (int) ($cfg['lockout_minutes'] ?? 10);
			$state['count'] = 0;
		}
		@file_put_contents(self::stateFile($ip), json_encode($state), LOCK_EX);
	}

	public static function clearFailures(string $ip): void {
		@unlink(self::stateFile($ip));
	}
}

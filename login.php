<?php
// login.php – Anmeldung (nur relevant, wenn config.php -> auth.enabled gesetzt ist)
require_once __DIR__ . '/includes/Auth.php';

Config::all();
if (!Auth::enabled() || Auth::isLoggedIn()) {
	header('Location: index.php');
	exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$pw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
	$error = Auth::login($pw, $_SERVER['REMOTE_ADDR'] ?? '');
	if ($error === null) {
		header('Location: index.php');
		exit;
	}
	http_response_code(401);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Anmelden – Energy Monitor</title>
	<link rel="stylesheet" href="assets/css/style.css">
	<script>
		try {
			document.documentElement.setAttribute('data-theme', localStorage.getItem('em-theme') ||
				(matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
		} catch (e) { /* Storage gesperrt */ }
	</script>
</head>
<body>
<div class="container" style="max-width:420px; padding-top:12vh">
	<div class="card" style="cursor:default">
		<div class="brand" style="margin-bottom:18px">
			<svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
			Energy Monitor
		</div>
		<?php if ($error): ?>
			<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
		<?php endif; ?>
		<form method="post" autocomplete="off">
			<div class="field">
				<label for="password">Passwort</label>
				<input type="password" id="password" name="password" class="input" autofocus required autocomplete="current-password">
			</div>
			<button type="submit" class="btn btn-primary btn-block">Anmelden</button>
		</form>
	</div>
</div>
</body>
</html>

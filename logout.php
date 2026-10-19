<?php
// logout.php – Abmelden (nur per POST, damit fremde Seiten niemanden ausloggen können)
require_once __DIR__ . '/includes/Auth.php';

Config::all();
if ($_SERVER['REQUEST_METHOD'] === 'POST') Auth::logout();
header('Location: login.php');
exit;

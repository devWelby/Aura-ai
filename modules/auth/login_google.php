<?php
require_once __DIR__ . '/../../config/init.php';

if (isset($_SESSION['usuario_id'])) {
	header('Location: index.php');
	exit;
}

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI'] ?? '');

// Permissoes minimas: e-mail e perfil
$client->addScope('email');
$client->addScope('profile');
$client->setPrompt('select_account');

$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_state_created_at'] = time();
$client->setState($state);

$url = $client->createAuthUrl();
header('Location: ' . $url);
exit;

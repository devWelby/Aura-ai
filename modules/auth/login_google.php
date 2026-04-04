<?php
require_once __DIR__ . '/../../config/init.php';

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI'] ?? '');

// Permissoes minimas: e-mail e perfil
$client->addScope('email');
$client->addScope('profile');

$url = $client->createAuthUrl();
header('Location: ' . $url);
exit;

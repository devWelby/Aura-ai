<?php
// login_google.php
require_once 'config/init.php';

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);

// Pedimos permissão apenas para ler o E-mail e o Perfil (Nome/Foto)
$client->addScope("email");
$client->addScope("profile");

$url = $client->createAuthUrl();
header("Location: " . $url);
exit;
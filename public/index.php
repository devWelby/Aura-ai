<?php
require_once __DIR__ . '/../config/router.php';

$route = $_GET['route'] ?? 'dashboard';
dispatch_route($route);

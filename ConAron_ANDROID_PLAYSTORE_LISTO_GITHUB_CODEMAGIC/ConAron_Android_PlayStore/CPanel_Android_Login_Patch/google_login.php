<?php
declare(strict_types=1);

if (!function_exists('conaron_contains')) {
    function conaron_contains(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) !== false; }
}
if (!function_exists('conaron_starts_with')) {
    function conaron_starts_with(string $haystack, string $needle): bool { return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0; }
}


$config = require __DIR__ . '/config.php';
if (!is_array($config)) {
    http_response_code(500);
    exit('Configuracion invalida.');
}

session_name('CONARON_ADMIN');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

$clientId = trim((string)($config['google_oauth_client_id'] ?? ''));
$redirectUri = trim((string)($config['google_oauth_redirect_uri'] ?? ''));

if ($clientId === '' || conaron_starts_with($clientId, 'TU_') || $redirectUri === '' || conaron_contains($redirectUri, 'TU_DOMINIO')) {
    header('Location: index.html?google_error=' . rawurlencode('Configura Client ID, Client Secret y Redirect URI de Google OAuth en config.php antes de usar este boton.'));
    exit;
}

$role = strtolower(trim((string)($_GET['rol'] ?? 'cliente')));
if (!in_array($role, ['cliente', 'conductor'], true)) {
    $role = 'cliente';
}

$state = bin2hex(random_bytes(24));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_role'] = $role;
$_SESSION['google_oauth_registration'] = !empty($_GET['registro']) && $_GET['registro'] === '1';
$_SESSION['google_oauth_accept_terms'] = !empty($_GET['acepta']) && $_GET['acepta'] === '1';
$_SESSION['google_oauth_started_at'] = time();
$_SESSION['google_oauth_android'] = !empty($_GET['app']) && $_GET['app'] === 'android';

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
    'access_type' => 'online',
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
exit;

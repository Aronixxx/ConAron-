<?php
declare(strict_types=1);

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

function b64urlDecode(string $value): string|false
{
    $remainder = strlen($value) % 4;
    if ($remainder) $value .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function androidBridgeSecret(array $config): string
{
    $material = (string)($config['db_pass'] ?? '') . '|' .
        (string)($config['google_oauth_client_secret'] ?? '') . '|' .
        (string)($config['google_oauth_client_id'] ?? '') . '|ConAronAndroidBridgeV1';
    return hash('sha256', $material, true);
}

function failAndroidLogin(string $message): never
{
    header('Location: index.html?google_error=' . rawurlencode($message));
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
$parts = explode('.', $token, 2);
if (count($parts) !== 2) failAndroidLogin('El acceso de Google para Android no es valido.');

[$payloadEncoded, $signatureEncoded] = $parts;
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadEncoded, androidBridgeSecret($config), true)), '+/', '-_'), '=');
if (!hash_equals($expected, $signatureEncoded)) failAndroidLogin('El acceso de Google para Android no es valido.');

$payloadRaw = b64urlDecode($payloadEncoded);
$payload = $payloadRaw !== false ? json_decode($payloadRaw, true) : null;
if (!is_array($payload)) failAndroidLogin('El acceso de Google para Android no es valido.');

$userId = (int)($payload['uid'] ?? 0);
$expires = (int)($payload['exp'] ?? 0);
if ($userId <= 0 || $expires < time() || $expires > time() + 300) {
    failAndroidLogin('El acceso de Google para Android vencio. Vuelve a intentarlo.');
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    (string)($config['db_host'] ?? 'localhost'),
    (string)($config['db_port'] ?? '3306'),
    (string)($config['db_name'] ?? '')
);
$pdo = new PDO($dsn, (string)($config['db_user'] ?? ''), (string)($config['db_pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$stmt = $pdo->prepare("SELECT id,estado FROM usuarios WHERE id=:id LIMIT 1");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();
if (!$user || (string)$user['estado'] !== 'activo') failAndroidLogin('La cuenta ConAron no esta activa.');

session_regenerate_id(true);
$_SESSION['usuario_id'] = $userId;
unset($_SESSION['admin_id']);
header('Location: index.html?google=ok');
exit;

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

function androidBridgeBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function androidBridgeSecret(array $config): string
{
    $material = (string)($config['db_pass'] ?? '') . '|' .
        (string)($config['google_oauth_client_secret'] ?? '') . '|' .
        (string)($config['google_oauth_client_id'] ?? '') . '|ConAronAndroidBridgeV1';
    return hash('sha256', $material, true);
}

function createAndroidBridgeToken(int $userId, array $config): string
{
    $payload = androidBridgeBase64UrlEncode(json_encode([
        'uid' => $userId,
        'exp' => time() + 180,
        'nonce' => bin2hex(random_bytes(16))
    ], JSON_UNESCAPED_SLASHES));
    $signature = androidBridgeBase64UrlEncode(hash_hmac('sha256', $payload, androidBridgeSecret($config), true));
    return $payload . '.' . $signature;
}

function backWithError(string $message): never
{
    $android = !empty($_SESSION['google_oauth_android']);
    unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_role'], $_SESSION['google_oauth_registration'], $_SESSION['google_oauth_accept_terms'], $_SESSION['google_oauth_started_at'], $_SESSION['google_oauth_android']);
    if ($android) {
        header('Location: conaron://oauth?error=' . rawurlencode($message));
    } else {
        header('Location: index.html?google_error=' . rawurlencode($message));
    }
    exit;
}

function httpPostForm(string $url, array $fields): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Activa la extension cURL de PHP en tu hosting para usar Google.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('No se pudo conectar con Google: ' . $error);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        throw new RuntimeException('Google rechazo el intercambio de credenciales. Revisa Client ID, Client Secret y Redirect URI.');
    }
    return $json;
}

function httpGetJson(string $url, string $accessToken): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Activa la extension cURL de PHP en tu hosting para usar Google.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('No se pudo obtener el perfil de Google: ' . $error);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        throw new RuntimeException('No se pudo leer el perfil de Google.');
    }
    return $json;
}

function dbFromConfig(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string)($config['db_host'] ?? 'localhost'),
        (string)($config['db_port'] ?? '3306'),
        (string)($config['db_name'] ?? 'conaron')
    );

    $pdo = new PDO(
        $dsn,
        (string)($config['db_user'] ?? 'root'),
        (string)($config['db_pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '-05:00'");
    return $pdo;
}

function initialsFor(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part === '') continue;
        $out .= strtoupper(function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1));
    }
    return $out !== '' ? $out : 'CA';
}

function uniqueUsername(PDO $pdo, string $email): string
{
    $base = strtolower((string)strtok($email, '@'));
    $base = preg_replace('/[^a-z0-9_.-]+/', '', $base) ?? '';
    $base = trim($base, '._-');
    if (strlen($base) < 3) $base = 'googleuser';
    $base = substr($base, 0, 48);

    $candidate = $base;
    for ($i = 0; $i < 100; $i++) {
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = :usuario LIMIT 1');
        $st->execute(['usuario' => $candidate]);
        if (!$st->fetch()) return $candidate;
        $candidate = $base . '.' . random_int(1000, 9999);
    }
    return 'googleuser.' . random_int(100000, 999999);
}

try {
    if (isset($_GET['error'])) {
        backWithError('Google cancelo o rechazo el inicio de sesion.');
    }

    $state = (string)($_GET['state'] ?? '');
    $expected = (string)($_SESSION['google_oauth_state'] ?? '');
    $startedAt = (int)($_SESSION['google_oauth_started_at'] ?? 0);
    if ($state === '' || $expected === '' || !hash_equals($expected, $state) || $startedAt < time() - 600) {
        backWithError('La sesion de Google vencio. Intenta iniciar sesion otra vez.');
    }

    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') backWithError('Google no devolvio un codigo de autorizacion.');

    $clientId = trim((string)($config['google_oauth_client_id'] ?? ''));
    $clientSecret = trim((string)($config['google_oauth_client_secret'] ?? ''));
    $redirectUri = trim((string)($config['google_oauth_redirect_uri'] ?? ''));
    if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || conaron_starts_with($clientId, 'TU_') || conaron_starts_with($clientSecret, 'TU_') || conaron_contains($redirectUri, 'TU_DOMINIO')) {
        backWithError('Completa Client ID, Client Secret y Redirect URI de Google OAuth en config.php.');
    }

    $tokens = httpPostForm('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);

    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    if ($accessToken === '') throw new RuntimeException('Google no devolvio un token de acceso.');

    $google = httpGetJson('https://openidconnect.googleapis.com/v1/userinfo', $accessToken);
    $sub = trim((string)($google['sub'] ?? ''));
    $email = strtolower(trim((string)($google['email'] ?? '')));
    $emailVerified = (bool)($google['email_verified'] ?? false);
    $name = trim((string)($google['name'] ?? 'Usuario Google'));
    $picture = trim((string)($google['picture'] ?? ''));

    if ($sub === '' || $email === '' || !$emailVerified) {
        throw new RuntimeException('La cuenta de Google no devolvio un correo verificado.');
    }

    $role = (string)($_SESSION['google_oauth_role'] ?? 'cliente');
    if (!in_array($role, ['cliente', 'conductor'], true)) $role = 'cliente';
    $isRegistration = !empty($_SESSION['google_oauth_registration']);
    $acceptedTerms = !empty($_SESSION['google_oauth_accept_terms']);

    $pdo = dbFromConfig($config);
    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT * FROM usuarios WHERE google_sub = :sub LIMIT 1 FOR UPDATE');
    $st->execute(['sub' => $sub]);
    $user = $st->fetch();

    if (!$user) {
        $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = :email LIMIT 1 FOR UPDATE');
        $st->execute(['email' => $email]);
        $user = $st->fetch();

        if ($user) {
            if (!empty($user['google_sub']) && !hash_equals((string)$user['google_sub'], $sub)) {
                throw new RuntimeException('Este correo ya esta vinculado a otra cuenta de Google.');
            }
            $provider = ((string)$user['proveedor'] === 'local') ? 'local+google' : (string)$user['proveedor'];
            $pdo->prepare('UPDATE usuarios SET google_sub=:sub, proveedor=:proveedor, foto=COALESCE(NULLIF(foto,\'\'),:foto), actualizado_en=NOW() WHERE id=:id')
                ->execute([
                    'sub' => $sub,
                    'proveedor' => $provider,
                    'foto' => $picture !== '' ? $picture : null,
                    'id' => (int)$user['id'],
                ]);
        } else {
            if (!$isRegistration || !$acceptedTerms) {
                throw new RuntimeException('Esta cuenta Google aun no existe en ConAron. Usa Registrarme con Google y acepta Terminos y Privacidad.');
            }
            $username = uniqueUsername($pdo, $email);
            $randomPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            if ($randomPasswordHash === false) throw new RuntimeException('No se pudo crear la cuenta local.');

            $insert = $pdo->prepare(
                "INSERT INTO usuarios (
                    usuario,password_hash,rol,nombre,telefono,email,email_verificado_en,email_avisos,acepta_terminos_en,acepta_privacidad_en,
                    ciudad,zona,direccion,bio,avatar,foto,color_perfil,tema_perfil,google_sub,proveedor,estado,creado_en,actualizado_en
                ) VALUES (
                    :usuario,:password_hash,:rol,:nombre,NULL,:email,NOW(),1,NOW(),NOW(),
                    'Peru',NULL,NULL,:bio,:avatar,:foto,'#FF6B00','sistema',:google_sub,'google','activo',NOW(),NOW()
                )"
            );
            $insert->execute([
                'usuario' => $username,
                'password_hash' => $randomPasswordHash,
                'rol' => $role,
                'nombre' => $name !== '' ? $name : $username,
                'email' => $email,
                'bio' => 'Cuenta creada con Google. Completa tu celular para poder realizar o recibir llamadas durante los viajes.',
                'avatar' => initialsFor($name !== '' ? $name : $username),
                'foto' => $picture !== '' ? $picture : null,
                'google_sub' => $sub,
            ]);
            $user = ['id' => (int)$pdo->lastInsertId()];
        }
    }

    $userId = (int)$user['id'];
    $st = $pdo->prepare('SELECT estado FROM usuarios WHERE id=:id LIMIT 1');
    $st->execute(['id' => $userId]);
    $status = (string)$st->fetchColumn();
    if ($status !== 'activo') {
        throw new RuntimeException('Tu cuenta ConAron no esta activa. Contacta al administrador.');
    }

    $pdo->prepare('UPDATE usuarios SET email_verificado_en=COALESCE(email_verificado_en,NOW()), ultimo_acceso=NOW(), actualizado_en=NOW() WHERE id=:id')->execute(['id' => $userId]);
    $pdo->commit();

    $android = !empty($_SESSION['google_oauth_android']);
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $userId;
    unset($_SESSION['admin_id'], $_SESSION['google_oauth_state'], $_SESSION['google_oauth_role'], $_SESSION['google_oauth_registration'], $_SESSION['google_oauth_accept_terms'], $_SESSION['google_oauth_started_at'], $_SESSION['google_oauth_android']);

    if ($android) {
        $bridgeToken = createAndroidBridgeToken($userId, $config);
        header('Location: conaron://oauth?token=' . rawurlencode($bridgeToken));
    } else {
        header('Location: index.html?google=ok');
    }
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    backWithError($e->getMessage());
}

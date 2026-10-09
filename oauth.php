<?php
/**
 * Minimal OAuth proxy for Decap CMS GitHub backend.
 * Keep q17-oauth-config.php outside public_html; never commit client secret.
 */
declare(strict_types=1);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$allowedOrigin = 'https://q17.tech';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$path = trim((string)($_GET['path'] ?? ''), '/');
if ($path === '') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = trim($requestPath, '/');
}
if (!in_array($path, ['auth', 'callback'], true)) {
    http_response_code(404); exit('Not found');
}
if ($origin !== '' && $origin !== $allowedOrigin) {
    http_response_code(403); exit('Origin not allowed');
}
$configPath = dirname(__DIR__) . '/q17-oauth-config.php';
if (!is_file($configPath)) {
    http_response_code(500); exit('OAuth config is missing. See CMS-SETUP.md.');
}
$config = require $configPath;
$clientId = (string)($config['client_id'] ?? '');
$clientSecret = (string)($config['client_secret'] ?? '');
$callbackUrl = 'https://q17.tech/callback';
if ($clientId === '' || $clientSecret === '') {
    http_response_code(500); exit('OAuth client credentials are not configured.');
}

if ($path === 'auth') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); exit('Method not allowed'); }
    $state = bin2hex(random_bytes(24));
    $_SESSION['q17_oauth_state'] = $state;
    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $callbackUrl,
        'scope' => 'public_repo',
        'state' => $state,
    ]);
    header('Location: https://github.com/login/oauth/authorize?' . $params, true, 302);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); exit('Method not allowed'); }
$state = (string)($_GET['state'] ?? '');
$expectedState = (string)($_SESSION['q17_oauth_state'] ?? '');
unset($_SESSION['q17_oauth_state']);
if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    http_response_code(400); exit('Invalid OAuth state. Close this window and try again.');
}
if (isset($_GET['error'])) {
    http_response_code(400); exit('GitHub authorization was cancelled. Close this window and try again.');
}
$code = (string)($_GET['code'] ?? '');
if ($code === '') { http_response_code(400); exit('Missing authorization code'); }
if (!function_exists('curl_init')) { http_response_code(500); exit('PHP cURL extension is required'); }
$ch = curl_init('https://github.com/login/oauth/access_token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $callbackUrl,
    ]),
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);
if ($response === false || $status < 200 || $status >= 300) {
    error_log('Q17 OAuth token exchange failed: HTTP ' . $status . ' ' . $error);
    http_response_code(502); exit('GitHub token exchange failed. Check server logs.');
}
$data = json_decode($response, true);
$token = is_array($data) ? (string)($data['access_token'] ?? '') : '';
if ($token === '') {
    http_response_code(502); exit('GitHub did not return an access token.');
}
$payload = json_encode(['token' => $token, 'provider' => 'github'], JSON_UNESCAPED_SLASHES);
$targetOrigin = $allowedOrigin;
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>Авторизация Q17.tech</title></head><body><p>Авторизация завершена. Это окно можно закрыть.</p><script>
(function(){var payload = <?php echo json_encode('authorization:github:success:' . $payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>; if (window.opener) { window.opener.postMessage(payload, <?php echo json_encode($targetOrigin); ?>); } window.close();})();
</script></body></html>

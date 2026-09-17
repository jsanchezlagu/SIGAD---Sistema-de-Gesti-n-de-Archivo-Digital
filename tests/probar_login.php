<?php
/**
 * Prueba del login SIGAD (CLI, contra el PHP built-in o un origen dado).
 * Uso: php tests/probar_login.php [http://127.0.0.1:8080]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$fallos = 0;
function ok(string $msg): void { echo "OK  $msg\n"; }
function fail(string $msg): void { global $fallos; $fallos++; echo "FAIL $msg\n"; }

function http(string $method, string $url, array $fields = [], array &$headers = []): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_COOKIEJAR => '/tmp/sigad-login-test.cookies',
        CURLOPT_COOKIEFILE => '/tmp/sigad-login-test.cookies',
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $hsz)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$code, substr($raw, $hsz)];
}

@unlink('/tmp/sigad-login-test.cookies');

[$code, $body] = http('GET', $base . '/login.php');
$code === 200 ? ok("GET login.php → 200") : fail("GET login.php → $code (se esperaba 200)");
str_contains($body, 'SIGAD-LOGIN-V2') ? ok('marcador SIGAD-LOGIN-V2 en login.php') : fail('falta SIGAD-LOGIN-V2 en login.php');
str_contains($body, 'value="superadmin"') ? fail('login.php todavía trae superadmin') : ok('login.php sin superadmin');
str_contains($body, 'name="username"') ? ok('campo usuario') : fail('falta campo usuario');

[$code, $body] = http('GET', $base . '/index.php');
$code === 200 ? ok("GET index.php → 200") : fail("GET index.php → $code (se esperaba 200)");
str_contains($body, 'SIGAD-LOGIN-V2') ? ok('marcador SIGAD-LOGIN-V2 en index.php') : fail('falta SIGAD-LOGIN-V2 en index.php');
str_contains($body, 'value="superadmin"') ? fail('index.php todavía trae superadmin') : ok('index.php sin superadmin');

[$code, $body] = http('GET', $base . '/login.php'); // cookie + csrf
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf = $m[1] ?? '';
$csrf !== '' ? ok('token CSRF en el formulario') : fail('falta token CSRF');

[$code, $body] = http('POST', $base . '/login.php', [
    'csrf' => $csrf,
    'username' => 'admin',
    'password' => 'clave-incorrecta-xyz',
]);
$code === 200 ? ok("POST clave mala → 200 (no 500)") : fail("POST clave mala → $code");
str_contains($body, 'incorrectos') ? ok('aviso de usuario/clave incorrectos') : fail('no se mostró aviso de clave mala');

[$code, $body] = http('GET', $base . '/index.php');
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf = $m[1] ?? '';
[$code, $body] = http('POST', $base . '/index.php', [
    'csrf' => $csrf,
    'username' => 'admin',
    'password' => 'clave-incorrecta-xyz',
]);
$code === 200 ? ok("POST index.php clave mala → 200") : fail("POST index.php clave mala → $code");

$local = dirname(__DIR__) . '/config/config.local.php';
$backup = is_file($local) ? (string)file_get_contents($local) : null;
file_put_contents($local, "<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','no_existe_sigad');\ndefine('DB_USER','nadie');\ndefine('DB_PASS','x');\n");
try {
    [$code, $body] = http('GET', $base . '/login.php');
    preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
    $csrf = $m[1] ?? '';
    [$code, $body] = http('POST', $base . '/login.php', [
        'csrf' => $csrf,
        'username' => 'admin',
        'password' => 'Sigad2026',
    ]);
    $code === 200 ? ok("POST MySQL caído → 200 (no 500)") : fail("POST MySQL caído → $code (Chrome mostraría 500)");
    (str_contains($body, 'MySQL') || str_contains($body, 'install.php'))
        ? ok('aviso de MySQL / install.php en la ventana')
        : fail('no se mostró aviso de MySQL: ' . substr(trim(strip_tags($body)), 0, 180));
    str_contains($body, 'install.php') ? ok('enlace a install.php') : fail('falta enlace a install.php');
} finally {
    if ($backup !== null) file_put_contents($local, $backup);
    else @unlink($local);
}

echo $fallos === 0 ? "\nTodas las pruebas de login pasaron.\n" : "\n$fallos fallo(s).\n";
exit($fallos === 0 ? 0 : 1);

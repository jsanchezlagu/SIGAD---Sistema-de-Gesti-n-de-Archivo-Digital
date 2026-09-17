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
$cookieFile = '/tmp/sigad-login-test.cookies';
function ok(string $msg): void { echo "OK  $msg\n"; }
function fail(string $msg): void { global $fallos; $fallos++; echo "FAIL $msg\n"; }

function http(string $method, string $url, array $fields = []): array {
    global $cookieFile;
    $headerLines = '';
    $opts = [
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ];
    if (is_file($cookieFile)) {
        $opts['http']['header'] .= 'Cookie: ' . trim((string)file_get_contents($cookieFile)) . "\r\n";
    }
    if ($method === 'POST') {
        $opts['http']['content'] = http_build_query($fields);
    }
    $ctx = stream_context_create($opts);
    $body = (string)file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
            $code = (int)$m[1];
        }
        if (stripos($line, 'Set-Cookie:') === 0) {
            $c = trim(substr($line, strlen('Set-Cookie:')));
            $c = explode(';', $c, 2)[0];
            file_put_contents($cookieFile, $c);
        }
        $headerLines .= $line . "\n";
    }
    return [$code, $body, $headerLines];
}

@unlink($cookieFile);

[$code, $body] = http('GET', $base . '/login.php');
$code === 200 ? ok("GET login.php → 200") : fail("GET login.php → $code (se esperaba 200)");
str_contains($body, 'SIGAD-LOGIN-V2') ? ok('marcador SIGAD-LOGIN-V2 en login.php') : fail('falta SIGAD-LOGIN-V2 en login.php');
str_contains($body, 'value="superadmin"') ? fail('login.php todavía trae superadmin') : ok('login.php sin superadmin');
str_contains($body, 'name="username"') ? ok('campo usuario') : fail('falta campo usuario');

[$code, $body] = http('GET', $base . '/index.php');
$code === 200 ? ok("GET index.php → 200") : fail("GET index.php → $code (se esperaba 200)");
str_contains($body, 'SIGAD-LOGIN-V2') ? ok('marcador SIGAD-LOGIN-V2 en index.php') : fail('falta SIGAD-LOGIN-V2 en index.php');
str_contains($body, 'value="superadmin"') ? fail('index.php todavía trae superadmin') : ok('index.php sin superadmin');

[$code, $body] = http('GET', $base . '/login.php');
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

@unlink($cookieFile);
[$code, $body] = http('GET', $base . '/login.php');
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf = $m[1] ?? '';
[$code, $body, $hdr] = http('POST', $base . '/login.php', [
    'csrf' => $csrf,
    'username' => 'admin',
    'password' => 'Sigad2026',
]);
($code === 302 && str_contains($hdr, 'usuarios.php'))
    ? ok('POST admin/Sigad2026 → 302 usuarios.php')
    : fail("POST admin válido → $code (hdr: " . str_replace("\n", ' | ', trim($hdr)) . ')');

$root  = dirname(__DIR__);
$local = $root . '/config/config.local.php';
$backup = is_file($local) ? (string)file_get_contents($local) : null;
file_put_contents($local, "<?php\ndefine('DB_HOST','localhost');\ndefine('DB_NAME','no_existe_sigad');\ndefine('DB_USER','nadie');\ndefine('DB_PASS','x');\n");
try {
    $cmd = escapeshellarg(PHP_BINARY)
        . ' -d opcache.enable=0 -d opcache.enable_cli=0 '
        . escapeshellarg($root . '/tests/simular_mysql_caido.php');
    $cliOut = (string)shell_exec($cmd);
    str_contains($cliOut, 'MySQL') && str_contains($cliOut, 'install.php')
        ? ok('MySQL caído muestra aviso en la ventana (no HTTP 500)')
        : fail('MySQL caído no mostró aviso: ' . substr(trim(strip_tags($cliOut)), 0, 200));
    str_contains($cliOut, 'SIGAD-LOGIN-V2') ? ok('marcador presente con MySQL caído') : fail('faltó marcador con MySQL caído');
} finally {
    if ($backup !== null) file_put_contents($local, $backup);
    else @unlink($local);
}

echo $fallos === 0 ? "\nTodas las pruebas de login pasaron.\n" : "\n$fallos fallo(s).\n";
exit($fallos === 0 ? 0 : 1);

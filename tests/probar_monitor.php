<?php
/**
 * Pruebas del monitor de desarrollador.
 * Uso: php tests/probar_monitor.php [http://127.0.0.1:8080]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SIGAD_TEST', 1);
require_once __DIR__ . '/../config/config.php';

$fallos = 0;
function ok(string $m): void { echo "OK  $m\n"; }
function fail(string $m): void { global $fallos; $fallos++; echo "FAIL $m\n"; }

function http(string $method, string $url): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = (string)@file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $code = (int)$m[1];
    }
    return [$code, $body];
}

asegurar_esquema_monitor();
$clave = monitor_clave();
$clave !== '' && strlen($clave) >= 32 ? ok('clave de monitor generada') : fail('no se generó monitor.key');

monitor_registrar('prueba', 'info', 'evento de test SIGAD');
$pdo = monitor_pdo(false);
if (!$pdo) {
    fail('no hay PDO para el monitor');
} else {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM monitor_eventos WHERE tipo='prueba' AND mensaje LIKE '%test SIGAD%'")->fetchColumn();
    $n > 0 ? ok('evento escrito en monitor_eventos') : fail('no se insertó el evento');
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
[$code, $body] = http('GET', $base . '/monitor.php');
$code === 404 ? ok('monitor.php sin clave → 404') : fail("monitor.php sin clave → $code");

[$code, $body] = http('GET', $base . '/monitor.php?k=clave-falsa');
$code === 404 ? ok('monitor.php clave mala → 404') : fail("monitor.php clave mala → $code");

[$code, $body] = http('GET', $base . '/monitor.php?k=' . urlencode($clave));
$code === 200 && str_contains($body, 'Monitor de desarrollador')
    ? ok('monitor.php con clave → 200')
    : fail("monitor.php con clave → $code");
str_contains($body, 'Salud del servidor') ? ok('tablero de salud visible') : fail('falta tablero de salud');

[$code, $body] = http('GET', $base . '/monitor.php?k=' . urlencode($clave) . '&fmt=json');
$code === 200 || $code === 503 ? ok("JSON salud HTTP $code") : fail("JSON salud → $code");
$j = json_decode($body, true);
is_array($j) && isset($j['ok'], $j['php']) ? ok('JSON con ok y php') : fail('JSON inválido: ' . substr($body, 0, 120));

echo $fallos === 0 ? "\nTodas las pruebas del monitor pasaron.\n" : "\n$fallos fallo(s).\n";
exit($fallos === 0 ? 0 : 1);

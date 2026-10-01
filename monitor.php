<?php
/**
 * Tablero de desarrollador SIGAD.
 * Acceso: sesión ADMIN, o ?k=CLAVE (config/monitor.key).
 * JSON: monitor.php?k=CLAVE&fmt=json  (para UptimeRobot / curl).
 */
try {
    require_once __DIR__ . '/config/config.php';
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('SIGAD: no se pudo cargar la configuración.');
}

$esAdmin = function_exists('es_admin') && !empty($_SESSION['uid']) && es_admin();
$claveQs = (string)($_GET['k'] ?? '');
if (!$esAdmin && !monitor_clave_ok($claveQs)) {
    http_response_code(404);
    exit('No encontrado.');
}

$pdo = monitor_pdo(false);
asegurar_esquema_monitor($pdo);

if ($esAdmin && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['regenerar']) && function_exists('csrf_check') && csrf_check()) {
    $f = monitor_ruta_clave();
    $nueva = bin2hex(random_bytes(32));
    @file_put_contents($f, $nueva . "\n");
    monitor_registrar('monitor', 'info', 'Clave de desarrollador regenerada');
    header('Location: monitor.php');
    exit;
}

$phpOk  = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoExt = extension_loaded('pdo_mysql');
$upDir  = defined('RUTA_UPLOADS') ? RUTA_UPLOADS : (__DIR__ . '/uploads');
$upOk   = is_dir($upDir) && is_writable($upDir);
$dbOk   = $pdo instanceof PDO;
$dbErr  = '';
if ($pdo) {
    try { $pdo->query('SELECT 1'); } catch (Throwable $e) { $dbOk = false; $dbErr = $e->getMessage(); }
}

$kpi = ['usuarios' => 0, 'expedientes' => 0, 'documentos' => 0, 'errores_24h' => 0, 'ingresos_24h' => 0, 'fallos_login_24h' => 0];
$eventos = [];
$ingresos = [];
$fallos = [];
$logTail = '';

if ($pdo && $dbOk) {
    try {
        $kpi['usuarios']     = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        $kpi['expedientes']  = (int)$pdo->query('SELECT COUNT(*) FROM expedientes')->fetchColumn();
        $kpi['documentos']   = (int)$pdo->query('SELECT COUNT(*) FROM documentos')->fetchColumn();
        $kpi['errores_24h']  = (int)$pdo->query("SELECT COUNT(*) FROM monitor_eventos WHERE nivel='error' AND fecha > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        $kpi['ingresos_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE accion='INGRESO' AND fecha > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        $kpi['fallos_login_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM login_intentos WHERE exito=0 AND fecha > (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        $eventos = $pdo->query('SELECT fecha, nivel, tipo, mensaje, usuario, ip FROM monitor_eventos ORDER BY id DESC LIMIT 40')->fetchAll();
        $ingresos = $pdo->query("SELECT fecha, username, ip FROM auditoria WHERE accion='INGRESO' ORDER BY id DESC LIMIT 15")->fetchAll();
        $fallos = $pdo->query('SELECT fecha, usuario, ip FROM login_intentos WHERE exito=0 ORDER BY id DESC LIMIT 15')->fetchAll();
    } catch (Throwable $e) {
        $dbErr = $e->getMessage();
    }
}
$lf = monitor_ruta_log();
if (is_file($lf)) {
    $raw = (string)@file_get_contents($lf);
    $logTail = implode("\n", array_slice(explode("\n", $raw), -20));
}

$okGlobal = $phpOk && $pdoExt && $dbOk && $upOk;
$salud = [
    'ok'      => $okGlobal,
    'version' => SIGAD_VERSION,
    'php'     => PHP_VERSION,
    'php_ok'  => $phpOk,
    'pdo_mysql' => $pdoExt,
    'mysql'   => $dbOk,
    'uploads_escribible' => $upOk,
    'kpi'     => $kpi,
    'hora'    => date('c'),
];

if (($_GET['fmt'] ?? '') === 'json') {
    json_out($salud, $okGlobal ? 200 : 503);
}

$qsClave = $esAdmin ? '' : ('?k=' . urlencode($claveQs));
$hrefJson = 'monitor.php' . ($esAdmin ? '?fmt=json' : ('?k=' . urlencode($claveQs) . '&fmt=json'));
$claveMostrar = $esAdmin ? monitor_clave() : '';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'sigad.jeftech.net.pe');
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$urlMon = ($https ? 'https' : 'http') . '://' . $host . dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/monitor.php'));
$urlMon = rtrim($urlMon, '/') . '/monitor.php?k=' . urlencode($claveMostrar !== '' ? $claveMostrar : $claveQs);

function sem(bool $ok): string {
    return $ok
        ? '<span class="tag t-ok">OK</span>'
        : '<span class="tag t-bad">FALLO</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIGAD · Monitor de desarrollador</title>
<link rel="icon" href="assets/logo-msm.png">
<link rel="stylesheet" href="css/estilos.css">
</head>
<body class="app">
<?php if ($esAdmin): ?>
<aside class="side">
  <div class="marca"><img src="assets/logo-msm.png" alt=""><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav"><?= nav_html('Monitor') ?></nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></b><br><?= htmlspecialchars($_SESSION['rol'] ?? '') ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<?php endif; ?>
<main class="main" style="<?= $esAdmin ? '' : 'margin-left:0;max-width:1100px;margin:24px auto' ?>">
  <div class="top">
    <h1>Monitor de desarrollador</h1>
    <span class="muted">v<?= htmlspecialchars(SIGAD_VERSION) ?> · no envía datos fuera del servidor</span>
  </div>

  <div class="grid4">
    <div class="kpi"><b><?= $okGlobal ? 'EN LÍNEA' : 'REVISAR' ?></b><span>Estado general</span></div>
    <div class="kpi"><b><?= (int)$kpi['errores_24h'] ?></b><span>Errores (24 h)</span></div>
    <div class="kpi"><b><?= (int)$kpi['ingresos_24h'] ?></b><span>Ingresos (24 h)</span></div>
    <div class="kpi"><b><?= (int)$kpi['fallos_login_24h'] ?></b><span>Login fallido (24 h)</span></div>
  </div>

  <div class="card">
    <h3>Salud del servidor</h3>
    <table>
      <tr><th>Componente</th><th>Estado</th><th>Detalle</th></tr>
      <tr><td>PHP ≥ 8.1</td><td><?= sem($phpOk) ?></td><td class="mono"><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
      <tr><td>pdo_mysql</td><td><?= sem($pdoExt) ?></td><td><?= $pdoExt ? 'cargado' : 'falta en MultiPHP' ?></td></tr>
      <tr><td>MySQL</td><td><?= sem($dbOk) ?></td><td class="mono"><?= $dbOk ? htmlspecialchars(DB_HOST . ' / ' . DB_NAME) : htmlspecialchars($dbErr ?: 'sin conexión') ?></td></tr>
      <tr><td>uploads/</td><td><?= sem($upOk) ?></td><td class="mono"><?= htmlspecialchars($upDir) ?></td></tr>
      <tr><td>Expedientes / PDF / usuarios</td><td><?= sem($dbOk) ?></td>
        <td><?= (int)$kpi['expedientes'] ?> exp. · <?= (int)$kpi['documentos'] ?> PDF · <?= (int)$kpi['usuarios'] ?> usuarios</td></tr>
    </table>
    <p class="ayuda" style="margin-top:10px">JSON para un ping externo (UptimeRobot):
      <a href="<?= htmlspecialchars($hrefJson) ?>"><?= htmlspecialchars($hrefJson) ?></a>
      · HTTP 200 = sano, 503 = fallo.</p>
  </div>

  <?php if ($esAdmin): ?>
  <div class="card">
    <h3>URL remota (marque esta página)</h3>
    <p class="ayuda">Ábrala desde su celular o PC sin iniciar sesión. No la publique. Si se filtra, regenere la clave.</p>
    <input readonly value="<?= htmlspecialchars($urlMon) ?>" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px">
    <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Regenerar la clave? La URL anterior dejará de funcionar.');">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <button class="btn-sm btn-gray" name="regenerar" value="1">Regenerar clave</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>Últimos errores y avisos</h3>
    <?php if (!$eventos): ?>
      <p class="muted">Aún no hay eventos. Los 500, fallos de MySQL y excepciones PHP aparecen aquí.</p>
    <?php else: ?>
    <table>
      <tr><th>Fecha</th><th>Nivel</th><th>Tipo</th><th>Mensaje</th><th>Usuario</th><th>IP</th></tr>
      <?php foreach ($eventos as $e): ?>
      <tr>
        <td class="muted"><?= htmlspecialchars($e['fecha']) ?></td>
        <td><span class="tag <?= $e['nivel']==='error' ? 't-bad' : ($e['nivel']==='aviso' ? 't-warn' : 't-info') ?>"><?= htmlspecialchars($e['nivel']) ?></span></td>
        <td class="mono"><?= htmlspecialchars($e['tipo']) ?></td>
        <td><?= htmlspecialchars($e['mensaje']) ?></td>
        <td class="mono"><?= htmlspecialchars($e['usuario']) ?></td>
        <td class="mono muted"><?= htmlspecialchars($e['ip']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <?php if ($logTail !== ''): ?>
      <p class="muted" style="margin-top:10px">Respaldo en archivo (si MySQL no estaba):</p>
      <pre class="snippet" style="white-space:pre-wrap"><?= htmlspecialchars($logTail) ?></pre>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Ingresos recientes</h3>
    <?php if (!$ingresos): ?><p class="muted">Sin registros de auditoría todavía.</p>
    <?php else: ?>
    <table>
      <tr><th>Fecha</th><th>Usuario</th><th>IP</th></tr>
      <?php foreach ($ingresos as $i): ?>
      <tr><td class="muted"><?= htmlspecialchars($i['fecha']) ?></td>
          <td class="mono"><?= htmlspecialchars($i['username']) ?></td>
          <td class="mono muted"><?= htmlspecialchars($i['ip']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Intentos de login fallidos</h3>
    <?php if (!$fallos): ?><p class="muted">No hay fallos recientes.</p>
    <?php else: ?>
    <table>
      <tr><th>Fecha</th><th>Usuario intentado</th><th>IP</th></tr>
      <?php foreach ($fallos as $f): ?>
      <tr><td class="muted"><?= htmlspecialchars($f['fecha']) ?></td>
          <td class="mono"><?= htmlspecialchars($f['usuario']) ?></td>
          <td class="mono muted"><?= htmlspecialchars($f['ip']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</main>
</body>
</html>

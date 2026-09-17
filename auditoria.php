<?php
require_once 'config/config.php';
requiere_login();
if (!es_admin()) { header('Location: panel.php'); exit; }
$db = db();
$aud = $db->query("SELECT username, accion, detalle, ip, fecha FROM auditoria ORDER BY fecha DESC LIMIT 100")->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Auditoría</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Auditoría') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Auditoría</h1><span class="muted"><?= count($aud) ?> registros</span></div>
  <div class="card"><table>
    <tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr>
    <?php foreach ($aud as $a): ?>
    <tr><td class="muted"><?= $a['fecha'] ?></td><td class="mono"><?= htmlspecialchars($a['username']) ?></td>
        <td><span class="tag t-info"><?= $a['accion'] ?></span></td>
        <td><?= htmlspecialchars($a['detalle']) ?></td><td class="mono muted"><?= $a['ip'] ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</main></body></html>

<?php
require_once 'config/config.php';
requiere_login();
// ADMIN y OPERADOR pueden modificar; CONSULTA solo ve/descarga (se ocultan los controles)
$db = db();

$id = (int)($_GET['exp'] ?? 0);
$exp = $db->prepare("SELECT e.*, p.codigo pab FROM expedientes e
                     JOIN pabellones p ON p.id=e.pabellon_id WHERE e.id=?");
$exp->execute([$id]);
$exp = $exp->fetch();
if (!$exp) { header('Location: buscar.php'); exit; }

$mensaje = '';
// ELIMINAR PDF equivocado (solo ADMIN/OPERADOR)
if (isset($_GET['eliminar']) && puede_escribir()) {
    $did = (int)$_GET['eliminar'];
    $d = $db->prepare("SELECT * FROM documentos WHERE id=? AND expediente_id=?");
    $d->execute([$did, $id]);
    if ($doc = $d->fetch()) {
        $ruta = RUTA_UPLOADS . '/' . $doc['archivo'];
        if (is_file($ruta)) @unlink($ruta);
        $db->prepare("DELETE FROM documentos WHERE id=?")->execute([$did]);
        auditar('ELIMINO PDF', $exp['nro_expediente'] . ' · ' . $doc['nombre_original']);
        $mensaje = 'Documento eliminado. Ahora puede subir el correcto.';
    }
}

// RE-SUBIR el PDF correcto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['archivo']['tmp_name'])) {
    require_once 'includes/funciones.php';
    $sub = subir_pdf($_FILES['archivo'], $id, $db);
    $mensaje = $sub;
    auditar('CORRIGIO PDF', $exp['nro_expediente']);
}

$docs = $db->prepare("SELECT * FROM documentos WHERE expediente_id=? ORDER BY version, id");
$docs->execute([$id]);
$docs = $docs->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Documentos</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Buscar') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="logout.php" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top">
    <h1>Documentos del expediente</h1>
    <a class="btn-sm" href="buscar.php">← Volver</a>
  </div>
  <div class="card">
    <p><b class="mono"><?= htmlspecialchars($exp['nro_expediente']) ?></b> ·
       <?= htmlspecialchars($exp['asunto']) ?> ·
       <span class="muted">Ubicación: <?= htmlspecialchars($exp['pab']) ?></span></p>

    <?php if ($mensaje): ?><div class="banner"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <h3>Archivos cargados (<?= count($docs) ?>)</h3>
    <?php if (empty($docs)): ?>
      <p class="muted">Aún no hay documentos. Suba el PDF correcto abajo.</p>
    <?php else: ?>
      <table>
        <tr><th>Tomo</th><th>Archivo</th><th>Versión</th><th>Tamaño</th><th>SHA-256</th><th>Subido</th><th>Acciones</th></tr>
        <?php $t=1; foreach ($docs as $d): ?>
        <tr>
          <td><span class="tag t-ok">Tomo <?= $t++ ?></span></td>
          <td><?= htmlspecialchars($d['nombre_original']) ?></td>
          <td><span class="tag t-info">v<?= $d['version'] ?></span></td>
          <td class="mono"><?= $d['tamano_mb'] ?> MB</td>
          <td class="mono muted"><?= substr($d['hash_sha256'],0,12) ?>…</td>
          <td class="muted"><?= $d['subido_en'] ?></td>
          <td>
            <a class="btn-sm" href="descargar.php?id=<?= $d['id'] ?>" target="_blank">Ver</a>
            <?php if (!es_consulta()): ?><a class="btn-sm btn-gray" href="documentos.php?exp=<?= $id ?>&eliminar=<?= $d['id'] ?>"
               onclick="return confirm('¿Eliminar este PDF? Luego podrá subir el correcto.')">Eliminar</a><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if (!es_consulta()): ?>
    <h3 style="margin-top:18px">Subir / corregir PDF (hasta 400 MB)</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="file" name="archivo" accept="application/pdf" required>
      <button class="btn-sm" style="margin-top:12px" type="submit">Guardar PDF</button>
    </form>
    <?php else: ?>
    <p class="muted" style="margin-top:18px">Su perfil de consulta permite solo visualizar y descargar documentos.</p>
    <?php endif; ?>
  </div>
</main></body></html>

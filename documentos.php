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

// Control de acceso: el rol CONSULTA sólo puede ver expedientes APROBADOS
// (evita enumerar IDs para abrir expedientes en proceso — IDOR).
if (es_consulta() && $exp['estado'] !== 'APROBADO') { header('Location: buscar.php'); exit; }

// Toda acción que modifica datos exige un token CSRF válido.
if ($_SERVER['REQUEST_METHOD'] === 'POST') requiere_csrf();

$mensaje = '';

// Actualizar CUI / nombre de proyecto (expedientes antiguos se buscan así)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_datos']) && puede_escribir()) {
    $cui  = strtoupper(trim((string)($_POST['cui'] ?? '')));
    $proy = trim((string)($_POST['nombre_proyecto'] ?? ''));
    $db->prepare("UPDATE expedientes SET cui=?, nombre_proyecto=? WHERE id=?")
       ->execute([$cui, $proy, $id]);
    auditar('EDITO EXPEDIENTE', $exp['nro_expediente'] . ' CUI=' . $cui);
    $exp['cui'] = $cui;
    $exp['nombre_proyecto'] = $proy;
    $mensaje = 'CUI y nombre del proyecto actualizados. Ya se puede buscar por esos datos.';
}

// ELIMINAR PDF equivocado (solo ADMIN/OPERADOR, por POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id']) && puede_escribir()) {
    $did = (int)$_POST['eliminar_id'];
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

// RE-SUBIR el PDF correcto (solo ADMIN/OPERADOR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['eliminar_id']) && !isset($_POST['guardar_datos'])
    && puede_escribir() && !empty($_FILES['archivo']['tmp_name'])) {
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
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Documentos</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Buscar') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top">
    <h1>Documentos del expediente</h1>
    <a class="btn-sm" href="buscar.php">← Volver</a>
  </div>
  <div class="card">
    <p><b class="mono"><?= htmlspecialchars($exp['nro_expediente']) ?></b>
       <?php if (!empty($exp['cui'])): ?> · CUI <span class="mono"><?= htmlspecialchars($exp['cui']) ?></span><?php endif; ?>
       · <?= htmlspecialchars($exp['asunto']) ?> ·
       <span class="muted">Ubicación: <?= htmlspecialchars($exp['pab']) ?></span></p>
    <?php if (!empty($exp['nombre_proyecto'])): ?>
      <p><b>Proyecto:</b> <?= htmlspecialchars($exp['nombre_proyecto']) ?></p>
    <?php endif; ?>

    <?php if ($mensaje): ?><div class="banner"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>

    <?php if (puede_escribir()): ?>
    <h3>Datos para búsqueda (CUI y nombre de proyecto)</h3>
    <p class="ayuda">Complete estos campos en expedientes antiguos: el personal suele buscar por el nombre de la obra, no por el código EXP.</p>
    <form method="post" class="row" style="margin-bottom:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="guardar_datos" value="1">
      <div><label>CUI</label>
        <input name="cui" maxlength="40" value="<?= htmlspecialchars($exp['cui'] ?? '') ?>" placeholder="Código único de inversión"></div>
      <div class="grow"><label>Nombre del proyecto / obra</label>
        <input name="nombre_proyecto" value="<?= htmlspecialchars($exp['nombre_proyecto'] ?? '') ?>"
               placeholder="Ej. Mejoramiento de la Plaza de Armas"></div>
      <div style="flex:0"><button class="btn-sm" type="submit">Guardar datos</button></div>
    </form>
    <?php endif; ?>

    <h3>Archivos cargados (<?= count($docs) ?>)</h3>
    <?php if (empty($docs)): ?>
      <p class="muted">Aún no hay documentos. Suba el PDF correcto abajo.</p>
    <?php else: ?>
      <table>
        <tr><th>Tomo</th><th>Archivo</th><th>Versión</th><th>Tamaño</th><th>Texto</th><th>SHA-256</th><th>Subido</th><th>Acciones</th></tr>
        <?php $t=1; foreach ($docs as $d): ?>
        <?php
          $origenTxt = $d['texto_origen'] ?? '';
          $tieneTxt  = ($d['texto'] ?? '') !== '' && $d['texto'] !== null;
          if ($origenTxt === 'capa') $tagTxt = ['t-ok','Indexado (PDF)'];
          elseif ($origenTxt === 'ocr') $tagTxt = ['t-info','Indexado (OCR)'];
          elseif ($tieneTxt) $tagTxt = ['t-ok','Indexado'];
          else $tagTxt = ['t-warn','Sin texto (escaneo)'];
        ?>
        <tr>
          <td><span class="tag t-ok">Tomo <?= $t++ ?></span></td>
          <td><?= htmlspecialchars($d['nombre_original']) ?></td>
          <td><span class="tag t-info">v<?= $d['version'] ?></span></td>
          <td class="mono"><?= $d['tamano_mb'] ?> MB</td>
          <td><span class="tag <?= $tagTxt[0] ?>"><?= $tagTxt[1] ?></span></td>
          <td class="mono muted"><?= substr($d['hash_sha256'],0,12) ?>…</td>
          <td class="muted"><?= $d['subido_en'] ?></td>
          <td>
            <a class="btn-sm" href="descargar.php?id=<?= $d['id'] ?>" target="_blank">Ver</a>
            <?php if (!es_consulta()): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este PDF? Luego podrá subir el correcto.')">
              <?= csrf_field() ?>
              <input type="hidden" name="eliminar_id" value="<?= $d['id'] ?>">
              <button class="btn-sm btn-gray" type="submit">Eliminar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if (!es_consulta()): ?>
    <h3 style="margin-top:18px">Subir / corregir PDF (hasta 400 MB)</h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="file" name="archivo" accept="application/pdf" required>
      <button class="btn-sm" style="margin-top:12px" type="submit">Guardar PDF</button>
    </form>
    <?php else: ?>
    <p class="muted" style="margin-top:18px">Su perfil de consulta permite solo visualizar y descargar documentos.</p>
    <?php endif; ?>
  </div>
</main></body></html>

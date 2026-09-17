<?php
require_once 'config/config.php';
require_once 'includes/funciones.php';
requiere_login();
if (!puede_escribir()) { header('Location: panel.php'); exit; }
$db = db();
$pabs = $db->query("SELECT id, codigo, nombre FROM pabellones ORDER BY codigo")->fetchAll();
$ests = $db->query("SELECT id, pabellon_id, codigo FROM estantes ORDER BY codigo")->fetchAll();
$areas = $db->query("SELECT codigo, nombre FROM areas ORDER BY nombre")->fetchAll();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_csrf();
    $nro   = trim($_POST['nro_expediente'] ?? '');
    $cui   = strtoupper(trim($_POST['cui'] ?? ''));
    $proy  = trim($_POST['nombre_proyecto'] ?? '');
    $asun  = trim($_POST['asunto'] ?? '');
    $area  = trim($_POST['area_origen'] ?? '');
    $fecha = trim($_POST['fecha_expediente'] ?? '');
    $anio  = $fecha ? (int)substr($fecha, 0, 4) : (int)($_POST['anio'] ?? date('Y'));
    $pab   = (int)($_POST['pabellon_id'] ?? 0);
    $est   = !empty($_POST['estante_id']) ? (int)$_POST['estante_id'] : null;
    $folios= (int)($_POST['folios'] ?? 0);

    if ($nro === '' || $asun === '' || $area === '' || $pab === 0) {
        $msg = 'Complete número, asunto, área de origen y pabellón.';
    } else {
        $chk = $db->prepare("SELECT id FROM expedientes WHERE nro_expediente = ?");
        $chk->execute([$nro]);
        if ($chk->fetch()) {
            $msg = "El número $nro ya existe.";
        } else {
            $db->prepare("INSERT INTO expedientes
                (nro_expediente, cui, anio, fecha_expediente, area_origen, asunto, nombre_proyecto, pabellon_id, estante_id, folios, creado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$nro, $cui, $anio, $fecha ?: null, $area, $asun, $proy, $pab, $est, $folios, $_SESSION['uid']]);
            $exp_id = $db->lastInsertId();
            auditar('CREO EXPEDIENTE', $nro);
            $msg = "Expediente $nro registrado (ID $exp_id).";

            // subir todos los tomos (archivos multiples)
            $archivos = isset($_FILES['archivo']) ? $_FILES['archivo'] : null;
            if ($archivos && is_array($archivos['tmp_name'])) {
                // normalize a lista de archivos individuales
                $lista = [];
                foreach ($archivos['tmp_name'] as $i => $tmp) {
                    if (!empty($tmp)) {
                        $lista[] = [
                            'name'     => $archivos['name'][$i],
                            'type'     => $archivos['type'][$i],
                            'tmp_name' => $archivos['tmp_name'][$i],
                            'error'    => $archivos['error'][$i],
                            'size'     => $archivos['size'][$i],
                        ];
                    }
                }
                foreach ($lista as $f) {
                    $sub = subir_pdf($f, $exp_id, $db);
                    $msg .= ' ' . $sub;
                }
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Registrar</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Registrar') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Registrar expediente</h1></div>
  <?php if ($msg): ?><div class="banner"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <div class="card">
    <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h3>1. Datos del expediente</h3>
    <div class="row">
      <div><label>N° de expediente *</label><input name="nro_expediente" placeholder="EXP-2026-000001"></div>
      <div><label>CUI (código único de inversión)</label>
        <input name="cui" placeholder="Ej. 2445678" maxlength="40"></div>
      <div>
        <label>Fecha del expediente *</label>
        <input type="date" name="fecha_expediente" id="fexp" required
               max="<?= date('Y')+5 ?>-12-31" min="1990-01-01"
               value="<?= date('Y-m-d') ?>">
      </div>
      <div><label>Área de origen *</label>
        <select name="area_origen" required>
          <option value="">— seleccione —</option>
          <?php foreach ($areas as $a): ?><option value="<?= htmlspecialchars($a['nombre']) ?>"><?= htmlspecialchars($a['nombre']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="row" style="margin-top:10px">
      <div class="grow"><label>Nombre del proyecto / obra</label>
        <input name="nombre_proyecto" placeholder="Ej. Mejoramiento de la Plaza de Armas de San Marcos"></div>
    </div>
    <p class="ayuda">El CUI y el nombre del proyecto permiten encontrar expedientes antiguos aunque nadie recuerde el código EXP. Si el PDF es un escaneo sin texto, estos campos son la forma de localizarlo.</p>
    <div class="row" style="margin-top:10px">
      <div style="flex:2"><label>Asunto *</label><input name="asunto" placeholder="Descripción"></div>
      <div><label>Pabellón *</label>
        <select name="pabellon_id"><?php foreach ($pabs as $p): ?><option value="<?= $p['id'] ?>"><?= $p['codigo'] ?></option><?php endforeach; ?></select></div>
      <div><label>Estante</label>
        <select name="estante_id"><option value="">—</option><?php foreach ($ests as $e): ?><option value="<?= $e['id'] ?>"><?= $e['codigo'] ?></option><?php endforeach; ?></select></div>
      <div><label>Folios</label><input name="folios" value="0"></div>
    </div>
    <h3 style="margin-top:18px">2. Documentos PDF del expediente (hasta 400 MB c/u)</h3>
    <p class="muted" style="margin:0 0 8px;font-size:12px">Puede adjuntar varios tomos (Tomo 1, Tomo 2, Tomo 3…) bajo un mismo expediente.</p>
    <div id="tomos">
      <div class="fila-tomo" style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
        <span class="tag t-info" style="min-width:64px;text-align:center">Tomo 1</span>
        <input type="file" name="archivo[]" accept="application/pdf" style="flex:1">
      </div>
    </div>
    <button type="button" class="btn-sm" style="margin-top:4px" onclick="agregarTomo()">+ Agregar otro tomo</button>
    <button class="btn-sm" style="margin-top:16px" type="submit">Guardar expediente</button>
    <script>
      var _tomo = 1;
      function agregarTomo(){
        _tomo++;
        var d = document.createElement('div');
        d.className = 'fila-tomo';
        d.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px';
        d.innerHTML = '<span class="tag t-info" style="min-width:64px;text-align:center">Tomo '+_tomo+'</span>' +
                      '<input type="file" name="archivo[]" accept="application/pdf" style="flex:1">' +
                      '<button type="button" class="btn-sm btn-gray" onclick="this.parentNode.remove()">Quitar</button>';
        document.getElementById('tomos').appendChild(d);
      }
    </script>
    </form>
  </div>
</main></body></html>

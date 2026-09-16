<?php
require_once 'config/config.php';
requiere_login();
if (!es_admin()) { header('Location: panel.php'); exit; }
$db = db();

$mensaje = '';

// Toda acción que modifica datos exige un token CSRF válido.
if ($_SERVER['REQUEST_METHOD'] === 'POST') requiere_csrf();

// REGISTRAR / EDITAR AREA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['eliminar_id'])) {
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre = trim($_POST['nombre'] ?? '');
    $desc   = trim($_POST['descripcion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($codigo === '' || $nombre === '') {
        $mensaje = ['tipo'=>'err','txt'=>'Código y nombre son obligatorios.'];
    } else {
        if ($id > 0) {
            $db->prepare("UPDATE areas SET codigo=?, nombre=?, descripcion=? WHERE id=?")
               ->execute([$codigo, $nombre, $desc, $id]);
            auditar('EDITO AREA', $codigo);
            $mensaje = ['tipo'=>'ok','txt'=>"Área <b>".htmlspecialchars($nombre)."</b> actualizada."];
        } else {
            $chk = $db->prepare("SELECT id FROM areas WHERE codigo=?");
            $chk->execute([$codigo]);
            if ($chk->fetch()) {
                $mensaje = ['tipo'=>'err','txt'=>'Ese código de área ya existe.'];
            } else {
                $db->prepare("INSERT INTO areas (codigo, nombre, descripcion) VALUES (?,?,?)")
                   ->execute([$codigo, $nombre, $desc]);
                auditar('CREO AREA', $codigo);
                $mensaje = ['tipo'=>'ok','txt'=>"Área <b>".htmlspecialchars($nombre)."</b> registrada."];
            }
        }
    }
}

// ELIMINAR (sólo por POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    $did = (int)$_POST['eliminar_id'];
    $a = $db->prepare("SELECT nombre FROM areas WHERE id=?");
    $a->execute([$did]); $a = $a->fetch();
    if ($a) {
        $db->prepare("DELETE FROM areas WHERE id=?")->execute([$did]);
        auditar('ELIMINO AREA', $a['nombre']);
        $mensaje = ['tipo'=>'ok','txt'=>"Área <b>".htmlspecialchars($a['nombre'])."</b> eliminada."];
    }
}

// EDITAR (cargar datos)
$edit = null;
if (isset($_GET['editar'])) {
    $eid = (int)$_GET['editar'];
    $edit = $db->prepare("SELECT * FROM areas WHERE id=?");
    $edit->execute([$eid]); $edit = $edit->fetch();
}

$areas = $db->query("SELECT a.*, (SELECT COUNT(*) FROM usuarios u WHERE u.cargo=a.nombre) us,
                            (SELECT COUNT(*) FROM expedientes x WHERE x.area_origen=a.nombre) exp
                     FROM areas a ORDER BY a.codigo")->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Áreas</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Áreas') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Áreas de la Municipalidad</h1>
    <button class="btn-sm" onclick="document.getElementById('frm').style.display='block'">+ Nueva área</button>
  </div>

  <?php if ($mensaje): ?>
    <div class="banner" style="<?=
        $mensaje['tipo']==='ok' ? 'background:#dcfce7;color:#15803d;border:1px solid #86efac'
                                 : 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca' ?>;
        padding:11px;border-radius:8px;margin-bottom:14px"><?= $mensaje['txt'] ?></div>
  <?php endif; ?>

  <div class="card" id="frm" style="display:<?= $edit ? 'block':'none' ?>;margin-bottom:18px">
    <h3><?= $edit ? 'Editar área' : 'Registrar nueva área' ?></h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px">
        <label>Código (ej. GER, REN)<input name="codigo" required value="<?= htmlspecialchars($edit['codigo'] ?? '') ?>"
            style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px;text-transform:uppercase"></label>
        <label>Nombre del área<input name="nombre" required value="<?= htmlspecialchars($edit['nombre'] ?? '') ?>"
            style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
        <label style="grid-column:1/3">Descripción<input name="descripcion" value="<?= htmlspecialchars($edit['descripcion'] ?? '') ?>"
            style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
      </div>
      <button class="btn-sm" type="submit" style="margin-top:12px;background:#0f2d5c;color:#fff"><?= $edit ? 'Actualizar' : 'Guardar área' ?></button>
    </form>
  </div>

  <div class="card"><table>
    <tr><th>Código</th><th>Área</th><th>Descripción</th><th>Usuarios</th><th>Expedientes</th><th>Acción</th></tr>
    <?php foreach ($areas as $a): ?>
    <tr><td class="mono"><b><?= htmlspecialchars($a['codigo']) ?></b></td>
        <td><?= htmlspecialchars($a['nombre']) ?></td>
        <td class="muted"><?= htmlspecialchars($a['descripcion']) ?></td>
        <td><?= $a['us'] ?></td><td><?= number_format($a['exp']) ?></td>
        <td>
          <a class="btn-sm" href="areas.php?editar=<?= $a['id'] ?>">Editar</a>
          <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar esta área?')">
            <?= csrf_field() ?>
            <input type="hidden" name="eliminar_id" value="<?= $a['id'] ?>">
            <button class="btn-sm btn-gray" type="submit">Eliminar</button>
          </form>
        </td></tr>
    <?php endforeach; ?>
    <?php if (empty($areas)): ?><tr><td colspan="6" class="muted">Aún no hay áreas registradas.</td></tr><?php endif; ?>
  </table></div>
</main></body></html>

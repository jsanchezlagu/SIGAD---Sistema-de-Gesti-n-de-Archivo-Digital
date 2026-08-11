<?php
require_once 'config/config.php';
requiere_login();
if (!puede_escribir()) { header('Location: panel.php'); exit; }
$db = db();

$mensaje = '';

// REGISTRAR / EDITAR PABELLON (+ estantes)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre = trim($_POST['nombre'] ?? '');
    $ubi    = trim($_POST['ubicacion'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    // estantes enviados como array de codigos
    $ests   = isset($_POST['estante']) && is_array($_POST['estante'])
              ? array_filter(array_map('trim', $_POST['estante']), fn($x) => $x !== '')
              : [];

    if ($codigo === '' || $nombre === '') {
        $mensaje = ['tipo'=>'err','txt'=>'Código y nombre son obligatorios.'];
    } else {
        if ($id > 0) {
            $db->prepare("UPDATE pabellones SET codigo=?, nombre=?, ubicacion=? WHERE id=?")
               ->execute([$codigo, $nombre, $ubi, $id]);
            auditar('EDITO PABELLON', $codigo);
            // sincronizar estantes: los existentes se actualizan por posicion; nuevos se agregan
            $ex = $db->prepare("SELECT id, codigo FROM estantes WHERE pabellon_id=? ORDER BY id");
            $ex->execute([$id]); $existentes = $ex->fetchAll();
            $i = 0;
            foreach ($existentes as $e) {
                if (isset($ests[$i])) {
                    $db->prepare("UPDATE estantes SET codigo=? WHERE id=?")->execute([strtoupper($ests[$i]), $e['id']]);
                } else {
                    // eliminar solo si no tiene expedientes asociados
                    $c = $db->prepare("SELECT COUNT(*) FROM expedientes WHERE estante_id=?");
                    $c->execute([$e['id']]);
                    if ($c->fetchColumn() == 0) $db->prepare("DELETE FROM estantes WHERE id=?")->execute([$e['id']]);
                }
                $i++;
            }
            for (; $i < count($ests); $i++) {
                $db->prepare("INSERT INTO estantes (pabellon_id, codigo) VALUES (?,?)")
                   ->execute([$id, strtoupper($ests[$i])]);
            }
            $mensaje = ['tipo'=>'ok','txt'=>"Pabellón <b>{$nombre}</b> actualizado."];
        } else {
            $chk = $db->prepare("SELECT id FROM pabellones WHERE codigo=?");
            $chk->execute([$codigo]);
            if ($chk->fetch()) {
                $mensaje = ['tipo'=>'err','txt'=>'Ese código de pabellón ya existe.'];
            } else {
                $db->prepare("INSERT INTO pabellones (codigo, nombre, ubicacion) VALUES (?,?,?)")
                   ->execute([$codigo, $nombre, $ubi]);
                $nid = $db->lastInsertId();
                auditar('CREO PABELLON', $codigo);
                foreach ($ests as $c) {
                    $db->prepare("INSERT INTO estantes (pabellon_id, codigo) VALUES (?,?)")
                       ->execute([$nid, strtoupper($c)]);
                }
                $mensaje = ['tipo'=>'ok','txt'=>"Pabellón <b>{$nombre}</b> registrado."];
            }
        }
    }
}

// ELIMINAR (con sus estantes)
if (isset($_GET['eliminar'])) {
    $did = (int)$_GET['eliminar'];
    $p = $db->prepare("SELECT nombre FROM pabellones WHERE id=?");
    $p->execute([$did]); $p = $p->fetch();
    if ($p) {
        $db->prepare("DELETE FROM estantes WHERE pabellon_id=?")->execute([$did]);
        $db->prepare("DELETE FROM pabellones WHERE id=?")->execute([$did]);
        auditar('ELIMINO PABELLON', $p['nombre']);
        $mensaje = ['tipo'=>'ok','txt'=>"Pabellón <b>{$p['nombre']}</b> eliminado."];
    }
}

// EDITAR (cargar datos + estantes)
$edit = null; $est_edit = [];
if (isset($_GET['editar'])) {
    $eid = (int)$_GET['editar'];
    $edit = $db->prepare("SELECT * FROM pabellones WHERE id=?");
    $edit->execute([$eid]); $edit = $edit->fetch();
    if ($edit) {
        $es = $db->prepare("SELECT id, codigo FROM estantes WHERE pabellon_id=? ORDER BY id");
        $es->execute([$eid]); $est_edit = $es->fetchAll();
    }
}

$pabs = $db->query("SELECT p.id, p.codigo, p.nombre, p.ubicacion,
                    (SELECT COUNT(*) FROM estantes e WHERE e.pabellon_id=p.id) est,
                    (SELECT COUNT(*) FROM expedientes x WHERE x.pabellon_id=p.id) exp
                    FROM pabellones p ORDER BY p.codigo")->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Pabellones</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Pabellones') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="logout.php" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Pabellones</h1>
    <button class="btn-sm" onclick="document.getElementById('frm').style.display='block'">+ Nuevo pabellón</button>
  </div>

  <?php if ($mensaje): ?>
    <div class="banner" style="<?=
        $mensaje['tipo']==='ok' ? 'background:#dcfce7;color:#15803d;border:1px solid #86efac'
                                 : 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca' ?>;
        padding:11px;border-radius:8px;margin-bottom:14px"><?= $mensaje['txt'] ?></div>
  <?php endif; ?>

  <div class="card" id="frm" style="display:<?= $edit ? 'block':'none' ?>;margin-bottom:18px">
    <h3><?= $edit ? 'Editar pabellón' : 'Registrar nuevo pabellón' ?></h3>
    <form method="post">
      <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">
      <div style="display:grid;grid-template-columns:1fr 3fr;gap:10px">
        <label>Código (ej. PAB-A)<input name="codigo" required value="<?= htmlspecialchars($edit['codigo'] ?? '') ?>"
            style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px;text-transform:uppercase"></label>
        <label>Nombre<input name="nombre" required value="<?= htmlspecialchars($edit['nombre'] ?? '') ?>"
            style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
        <label style="grid-column:1/3">Ubicación<input name="ubicacion" value="<?= htmlspecialchars($edit['ubicacion'] ?? '') ?>"
            style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
      </div>

      <h3 style="margin-top:16px">Estantes</h3>
      <div id="estantes">
        <?php if ($edit && count($est_edit)): ?>
          <?php foreach ($est_edit as $e): ?>
            <div class="fila-est" style="display:flex;gap:8px;margin-bottom:6px">
              <input name="estante[]" value="<?= htmlspecialchars($e['codigo']) ?>"
                  style="flex:1;padding:8px;border:1px solid #e2e8f0;border-radius:8px;text-transform:uppercase">
              <button type="button" class="btn-sm btn-gray" onclick="this.parentNode.remove()">Quitar</button>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <button type="button" class="btn-sm" style="margin-top:6px" onclick="agregarEstante()">+ Agregar estante</button>

      <div style="margin-top:14px">
        <button class="btn-sm" type="submit" style="background:#0f2d5c;color:#fff"><?= $edit ? 'Actualizar' : 'Guardar pabellón' ?></button>
      </div>
    </form>
  </div>

  <div class="card"><table>
    <tr><th>Código</th><th>Nombre</th><th>Ubicación</th><th>Estantes</th><th>Expedientes</th><th>Acción</th></tr>
    <?php foreach ($pabs as $p): ?>
    <tr><td class="mono"><b><?= $p['codigo'] ?></b></td>
        <td><?= htmlspecialchars($p['nombre']) ?></td>
        <td class="muted"><?= htmlspecialchars($p['ubicacion']) ?></td>
        <td><?= $p['est'] ?></td><td><?= number_format($p['exp']) ?></td>
        <td>
          <a class="btn-sm" href="pabellones.php?editar=<?= $p['id'] ?>">Editar</a>
          <a class="btn-sm btn-gray" href="pabellones.php?eliminar=<?= $p['id'] ?>" onclick="return confirm('¿Eliminar este pabellón y sus estantes?')">Eliminar</a>
        </td></tr>
    <?php endforeach; ?>
    <?php if (empty($pabs)): ?><tr><td colspan="6" class="muted">Aún no hay pabellones registrados.</td></tr><?php endif; ?>
  </table></div>
</main>
<script>
  function agregarEstante(){
    var d = document.createElement('div');
    d.className = 'fila-est';
    d.style.cssText = 'display:flex;gap:8px;margin-bottom:6px';
    d.innerHTML = '<input name="estante[]" placeholder="Código estante (ej. A-01)" style="flex:1;padding:8px;border:1px solid #e2e8f0;border-radius:8px;text-transform:uppercase">' +
                  '<button type="button" class="btn-sm btn-gray" onclick="this.parentNode.remove()">Quitar</button>';
    document.getElementById('estantes').appendChild(d);
  }
</script>
</body></html>

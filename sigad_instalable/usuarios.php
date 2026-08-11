<?php
require_once 'config/config.php';
requiere_login();
if (!es_admin()) { header('Location: panel.php'); exit; }
$db = db();

$mensaje = '';

// REGISTRAR NUEVO USUARIO (solo ADMIN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['edit_id'])) {
    $username = trim($_POST['username'] ?? '');
    $nombres  = trim($_POST['nombres']  ?? '');
    $apellidos= trim($_POST['apellidos']?? '');
    $area     = trim($_POST['area']     ?? '');
    $rol      = $_POST['rol'] ?? 'CONSULTA';
    $clave    = $_POST['clave'] ?? '';
    $activo   = ($_POST['activo'] ?? '1') === '1' ? 1 : 0;

    if (!in_array($rol, ['ADMIN','OPERADOR','CONSULTA'])) $rol = 'CONSULTA';
    if ($username === '' || $nombres === '' || $apellidos === '' || $clave === '') {
        $mensaje = ['tipo'=>'err','txt'=>'Complete usuario, nombres, apellidos y clave.'];
    } elseif (strlen($clave) < 6) {
        $mensaje = ['tipo'=>'err','txt'=>'La clave debe tener al menos 6 caracteres.'];
    } else {
        $chk = $db->prepare("SELECT id FROM usuarios WHERE username=?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $mensaje = ['tipo'=>'err','txt'=>'Ese nombre de usuario ya existe.'];
        } else {
            $hash = password_hash($clave, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO usuarios
                (username, password, nombres, apellidos, cargo, rol, activo, creado_en)
                VALUES (?,?,?,?,?,?,?, NOW())")
                ->execute([$username, $hash, $nombres, $apellidos, $area, $rol, $activo]);
            auditar('CREO USUARIO', "{$username} ({$rol})");
            $mensaje = ['tipo'=>'ok','txt'=>"Usuario <b>{$username}</b> registrado como {$rol}."];
        }
    }
}

// MODIFICAR USUARIO (alta/baja + clave)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $eid   = (int)$_POST['edit_id'];
    $act   = ($_POST['estado'] ?? '1') === '1' ? 1 : 0;   // alta=1, baja=0
    $clave = trim($_POST['nueva_clave'] ?? '');

    // seguridad: nadie puede quitarse el rol admin a si mismo ni darse de baja a si mismo
    $yo = $_SESSION['uid'];
    if ($eid === $yo && $act === 0) {
        $mensaje = ['tipo'=>'err','txt'=>'No puede darse de baja a usted mismo.'];
    } else {
        if ($clave !== '') {
            if (strlen($clave) < 6) {
                $mensaje = ['tipo'=>'err','txt'=>'La nueva clave debe tener al menos 6 caracteres.'];
            } else {
                $hash = password_hash($clave, PASSWORD_DEFAULT);
                $db->prepare("UPDATE usuarios SET activo=?, password=? WHERE id=?")
                   ->execute([$act, $hash, $eid]);
                auditar('CAMBIO CLAVE USUARIO', "id $eid");
                $mensaje = ['tipo'=>'ok','txt'=>'Usuario actualizado (clave cambiada).'];
            }
        } else {
            $db->prepare("UPDATE usuarios SET activo=? WHERE id=?")->execute([$act, $eid]);
            auditar($act ? 'DIO DE ALTA USUARIO' : 'DIO DE BAJA USUARIO', "id $eid");
            $mensaje = ['tipo'=>'ok','txt'=> $act ? 'Usuario dado de ALTA.' : 'Usuario dado de BAJA.'];
        }
    }
}

// CARGAR DATOS DE EDICION
$edit = null;
if (isset($_GET['editar'])) {
    $eid = (int)$_GET['editar'];
    $edit = $db->prepare("SELECT id, username, nombres, apellidos, cargo, rol, activo FROM usuarios WHERE id=?");
    $edit->execute([$eid]); $edit = $edit->fetch();
}

$us = $db->query("SELECT id, username, CONCAT(nombres,' ',apellidos) nombre,
                        nombres, apellidos, cargo, rol, activo, creado_en, ultimo_ingreso
                   FROM usuarios ORDER BY rol, username")->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Usuarios</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Usuarios') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="logout.php" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Usuarios</h1>
    <button class="btn-sm" onclick="document.getElementById('frm').style.display='block'">+ Nuevo usuario</button>
  </div>

  <?php if ($mensaje): ?>
    <div class="banner" style="<?=
        $mensaje['tipo']==='ok' ? 'background:#dcfce7;color:#15803d;border:1px solid #86efac'
                                 : 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca' ?>;
        padding:11px;border-radius:8px;margin-bottom:14px"><?= $mensaje['txt'] ?></div>
  <?php endif; ?>

  <div class="card" id="frm" style="display:<?= $edit ? 'block':'none' ?>;margin-bottom:18px">
    <h3><?= $edit ? 'Modificar usuario: '.htmlspecialchars($edit['username']) : 'Registrar nuevo usuario' ?></h3>
    <form method="post">
      <?php if ($edit): ?><input type="hidden" name="edit_id" value="<?= $edit['id'] ?>"><?php endif; ?>
      <?php if (!$edit): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <label>Usuario (login)<input name="username" required style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
        <label>Rol
          <select name="rol" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
            <option value="OPERADOR">OPERADOR</option>
            <option value="CONSULTA">CONSULTA</option>
            <option value="ADMIN">ADMIN</option>
          </select></label>
        <label>Nombres<input name="nombres" required style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
        <label>Apellidos<input name="apellidos" required style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
        <label>Área / cargo<input name="area" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
        <label>Estado
          <select name="activo" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
            <option value="1" selected>Activo</option>
            <option value="0">Inactivo</option>
          </select></label>
        <label>Clave (mín. 6)<input name="clave" type="password" required style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
      </div>
      <button class="btn-sm" type="submit" style="margin-top:12px;background:#0f2d5c;color:#fff">Guardar usuario</button>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <label>Estado (alta/baja)
          <select name="estado" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
            <option value="1" <?= $edit['activo']?'selected':'' ?>>Alta (activo)</option>
            <option value="0" <?= $edit['activo']?'':'selected' ?>>Baja (inactivo)</option>
          </select></label>
        <label>Nueva contraseña (dejar vacío para no cambiar)
          <input name="nueva_clave" type="password" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px"></label>
      </div>
      <button class="btn-sm" type="submit" style="margin-top:12px;background:#0f2d5c;color:#fff">Guardar cambios</button>
      <?php endif; ?>
    </form>
  </div>

  <div class="card"><table>
    <tr><th>Usuario</th><th>Nombre</th><th>Área</th><th>Rol</th><th>Estado</th><th>Creado</th><th>Último ingreso</th><th>Modificar</th></tr>
    <?php foreach ($us as $u): ?>
    <tr><td class="mono"><?= htmlspecialchars($u['username']) ?></td>
        <td><?= htmlspecialchars($u['nombre']) ?></td>
        <td class="muted"><?= htmlspecialchars($u['cargo']) ?></td>
        <td><span class="tag <?= $u['rol']==='ADMIN'?'t-bad':($u['rol']==='OPERADOR'?'t-info':'t-ok') ?>"><?= $u['rol'] ?></span></td>
        <td><?= $u['activo']?'<span class="tag t-ok">Activo</span>':'<span class="tag t-warn">Inactivo</span>' ?></td>
        <td class="muted"><?= $u['creado_en'] ?></td>
        <td class="muted"><?= $u['ultimo_ingreso'] ?? 'nunca' ?></td>
        <td><a class="btn-sm btn-gray" href="usuarios.php?editar=<?= $u['id'] ?>">Modificar</a></td></tr>
    <?php endforeach; ?>
  </table></div>
</main></body></html>

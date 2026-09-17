<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIGAD - Archivo Central Municipalidad Distrital de San Marcos</title>
<link rel="icon" href="assets/logo-msm.png">
<link rel="stylesheet" href="css/estilos.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-bg" style="background-image:url('assets/fondo-municipalidad.jpg')"></div>
  <div class="login-box">
    <img src="assets/logo-msm.png" class="logo-login" alt="Escudo Municipalidad Distrital de San Marcos">
    <h1>SIGAD</h1>
    <p class="sub">Sistema de Gestión de Archivo Digital<br>
      <b>MUNICIPALIDAD DISTRITAL DE SAN MARCOS</b><br>
      <span style="font-size:11px">Archivo Central</span></p>
    <?php if (isset($_GET['e'])): ?>
      <div class="login-err">
        <?= $_GET['e'] == 2 ? 'Su cuenta está suspendida. Contacte al administrador.'
           : ($_GET['e'] == 4 ? 'No se pudo conectar a MySQL. Abra install.php y use los datos de cPanel.'
           : 'Usuario o contraseña incorrectos.') ?>
      </div>
    <?php endif; ?>
    <form method="post" action="login.php">
      <label>Usuario</label>
      <input name="username" autocomplete="username" autofocus>
      <label>Contraseña</label>
      <input name="password" type="password" autocomplete="current-password">
      <button class="btn" type="submit">Ingresar</button>
    </form>
    <div class="pie-login">Municipalidad Distrital de San Marcos · Huari · Áncash</div>
  </div>
</div>
</body>
</html>

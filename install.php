<?php
/**
 * Instalador / reparador SIGAD para cPanel + MySQL.
 * - Primera vez: crea tablas y el administrador.
 * - Si la BD ya existe: solo reescribe la conexión (no borra expedientes).
 * Elimine este archivo cuando el login ya funcione.
 */
$base = __DIR__;
$configFile = $base . '/config/config.php';
$localFile  = $base . '/config/config.local.php';
$ejemploFile = $base . '/config/config.ejemplo.php';
$lockFile   = $base . '/config/instalado.lock';
$error = '';
$done  = false;
$yaInstalado = is_file($lockFile) || is_file($localFile);

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoOk = extension_loaded('pdo_mysql');

function sigad_ident(string $s, string $campo): string {
    $s = trim($s);
    if ($s === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $s)) {
        throw new InvalidArgumentException("El campo $campo contiene caracteres no permitidos.");
    }
    return $s;
}

function sigad_ejecutar_sql(PDO $pdo, string $raw): void {
    $raw = preg_replace('/^\s*CREATE\s+DATABASE[^;]*;/im', '', $raw) ?? $raw;
    $raw = preg_replace('/^\s*USE\s+`?[\w-]+`?\s*;/im', '', $raw) ?? $raw;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $current = '';
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '--')) continue;
        $line = preg_replace('/\s--.*$/', '', $line) ?? $line;
        $line = rtrim($line);
        if ($line === '') continue;
        $current .= $line . "\n";
        if (str_ends_with($line, ';')) {
            $sql = trim($current);
            if ($sql !== '') $pdo->exec($sql);
            $current = '';
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function sigad_escribir_loader(string $configFile, string $ejemploFile): void {
    $src = is_readable($ejemploFile)
        ? (string)file_get_contents($ejemploFile)
        : "<?php\nif (is_file(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';\n"
          . "if (!defined('DB_HOST')) define('DB_HOST', 'localhost');\n"
          . "if (!defined('DB_NAME')) define('DB_NAME', 'sigad');\n"
          . "if (!defined('DB_USER')) define('DB_USER', 'sigad_user');\n"
          . "if (!defined('DB_PASS')) define('DB_PASS', 'sigad_pass');\n"
          . "if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');\n"
          . "if (!defined('APP_NOMBRE')) define('APP_NOMBRE', 'SIGAD');\n"
          . "if (!defined('ENTIDAD')) define('ENTIDAD', 'MUNICIPALIDAD DISTRITAL DE SAN MARCOS');\n"
          . "if (!defined('RUTA_BASE')) define('RUTA_BASE', dirname(__DIR__));\n"
          . "if (!defined('RUTA_UPLOADS')) define('RUTA_UPLOADS', RUTA_BASE . '/uploads');\n"
          . "if (!defined('URL_UPLOADS')) define('URL_UPLOADS', 'uploads');\n"
          . "require_once RUTA_BASE . '/includes/comun.php';\n";
    if (file_put_contents($configFile, $src) === false) {
        throw new RuntimeException('No se pudo escribir config/config.php.');
    }
}

function sigad_escribir_local(string $localFile, string $host, string $name, string $user, string $pass, string $ent): void {
    $tpl  = "<?php\n";
    $tpl .= "define('DB_HOST',    " . var_export($host, true) . ");\n";
    $tpl .= "define('DB_NAME',    " . var_export($name, true) . ");\n";
    $tpl .= "define('DB_USER',    " . var_export($user, true) . ");\n";
    $tpl .= "define('DB_PASS',    " . var_export($pass, true) . ");\n";
    $tpl .= "define('DB_CHARSET', 'utf8mb4');\n";
    $tpl .= "define('ENTIDAD',    " . var_export($ent, true) . ");\n";
    if (file_put_contents($localFile, $tpl) === false) {
        throw new RuntimeException('No se pudo escribir config/config.local.php.');
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!$phpOk) {
        $error = 'Se requiere PHP 8.1 o superior. En cPanel use MultiPHP Manager.';
    } elseif (!$pdoOk) {
        $error = 'Falta la extensión pdo_mysql. Actívela en MultiPHP INI / Select PHP Extensions.';
    } else {
        try {
            $host = sigad_ident($_POST['db_host'] ?? 'localhost', 'servidor BD');
            $name = sigad_ident($_POST['db_name'] ?? '', 'nombre de la BD');
            $user = sigad_ident($_POST['db_user'] ?? '', 'usuario de la BD');
            $pass = (string)($_POST['db_pass'] ?? '');
            $adm  = trim((string)($_POST['admin_user'] ?? 'admin'));
            $admPass = (string)($_POST['admin_pass'] ?? '');
            $ent  = trim((string)($_POST['entidad'] ?? 'MUNICIPALIDAD DISTRITAL DE SAN MARCOS'));
            $soloConexion = isset($_POST['solo_conexion']) || $yaInstalado;

            if (!$soloConexion && ($adm === '' || strlen($admPass) < 8)) {
                throw new InvalidArgumentException('El administrador necesita usuario y una clave de al menos 8 caracteres.');
            }
            if ($soloConexion && $admPass !== '' && strlen($admPass) < 8) {
                throw new InvalidArgumentException('Si cambia la clave del administrador, use al menos 8 caracteres.');
            }
            if (!is_dir($base . '/config') || !is_writable($base . '/config')) {
                throw new RuntimeException('La carpeta config/ no tiene permiso de escritura (chmod 755).');
            }

            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            try {
                $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, $opts);
            } catch (PDOException $e) {
                $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, $opts);
                try {
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo->exec("USE `{$name}`");
                } catch (Throwable $e2) {
                    throw new RuntimeException(
                        'No se pudo abrir la base de datos. En cPanel cree primero la BD y el usuario en «MySQL Databases», '
                        . 'asígneles All Privileges, y use los nombres exactos (suelen ser usuario_sigad). Detalle: '
                        . $e->getMessage()
                    );
                }
            }

            $sqlFile = $base . '/db/sigad.sql';
            if (!is_readable($sqlFile)) {
                throw new RuntimeException('No se encontró db/sigad.sql. Suba la carpeta db/ completa.');
            }
            // CREATE TABLE IF NOT EXISTS / INSERT IGNORE: no borra datos existentes.
            sigad_ejecutar_sql($pdo, (string)file_get_contents($sqlFile));

            if ($adm !== '' && $admPass !== '') {
                $hash = password_hash($admPass, PASSWORD_DEFAULT);
                $chk = $pdo->prepare('SELECT id FROM usuarios WHERE username=?');
                $chk->execute([$adm]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE usuarios SET password=?, rol='ADMIN', activo=1 WHERE username=?")
                        ->execute([$hash, $adm]);
                } else {
                    $pdo->prepare("INSERT INTO usuarios (username,password,nombres,apellidos,cargo,rol,activo)
                        VALUES (?,?, 'Admin','Sistema','Administrador del sistema', 'ADMIN', 1)")
                        ->execute([$adm, $hash]);
                }
            }

            sigad_escribir_local($localFile, $host, $name, $user, $pass, $ent);
            sigad_escribir_loader($configFile, $ejemploFile);

            $up = $base . '/uploads';
            if (!is_dir($up)) mkdir($up, 0755, true);
            @chmod($up, 0755);

            file_put_contents($lockFile, date('c') . " · instalado/reparado por " . ($adm !== '' ? $adm : 'cPanel') . "\n");
            $done = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIGAD · Instalador cPanel</title>
<link rel="stylesheet" href="css/estilos.css"></head>
<body style="background:#0f2d5c;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px">
<div style="background:#fff;border-radius:14px;padding:30px;width:520px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.4)">
  <h1 style="color:#0f2d5c;margin:0 0 4px;letter-spacing:2px">SIGAD</h1>
  <p style="color:#64748b;margin:0 0 14px;font-size:13px"><?= $yaInstalado ? 'Reparar conexión MySQL · cPanel' : 'Instalación en cPanel · MySQL' ?></p>

  <p class="ayuda">En cPanel → <b>MySQL Databases</b> copie el nombre de la base, el usuario y la clave (casi siempre <code>usuario_sigad</code>). El servidor es <code>localhost</code>. Si la base ya se creó por la mañana, este formulario <b>no borra expedientes</b>: solo vuelve a grabar la conexión.</p>

  <?php if (!$phpOk): ?>
    <div style="background:#fee2e2;color:#991b1b;padding:9px;border-radius:7px;font-size:13px;margin-bottom:12px">
      PHP <?= htmlspecialchars(PHP_VERSION) ?> detectado. Cambie a <b>PHP 8.1+</b> en MultiPHP Manager.
    </div>
  <?php endif; ?>
  <?php if (!$pdoOk): ?>
    <div style="background:#fee2e2;color:#991b1b;padding:9px;border-radius:7px;font-size:13px;margin-bottom:12px">
      Falta <b>pdo_mysql</b>. Actívelo en Select PHP Extensions.
    </div>
  <?php endif; ?>

  <?php if ($done): ?>
    <div class="banner" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:12px;border-radius:8px">
      Conexión grabada. Pruebe el login. Cuando entre, <b>elimine install.php</b> (Administrador de archivos → Delete).
    </div>
    <a class="btn" href="index.php" style="display:block;text-align:center;margin-top:16px;
       background:#0f2d5c;color:#fff;padding:11px;border-radius:8px;text-decoration:none;font-weight:700">Ir al login</a>
  <?php else: ?>
    <?php if ($error): ?><div style="background:#fee2e2;color:#991b1b;padding:9px;border-radius:7px;font-size:13px;margin-bottom:12px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?php if ($yaInstalado): ?><input type="hidden" name="solo_conexion" value="1"><?php endif; ?>
      <label style="font-size:12px;font-weight:600;color:#334155">Servidor MySQL</label>
      <input name="db_host" value="localhost" required style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Nombre de la base (cPanel)</label>
      <input name="db_name" placeholder="usuario_sigad" required style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Usuario MySQL (cPanel)</label>
      <input name="db_user" placeholder="usuario_sigad" required style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Clave MySQL</label>
      <input name="db_pass" type="password" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <hr style="border:0;border-top:1px solid #e2e8f0;margin:12px 0">
      <label style="font-size:12px;font-weight:600;color:#334155">Usuario administrador SIGAD<?= $yaInstalado ? ' (opcional)' : '' ?></label>
      <input name="admin_user" value="admin" <?= $yaInstalado ? '' : 'required' ?> style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Clave del administrador<?= $yaInstalado ? ' (vacío = no cambiar)' : ' (mín. 8)' ?></label>
      <input name="admin_pass" type="password" <?= $yaInstalado ? '' : 'required minlength="8"' ?> style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Nombre de la entidad</label>
      <input name="entidad" value="MUNICIPALIDAD DISTRITAL DE SAN MARCOS" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 14px">
      <button type="submit" style="width:100%;padding:11px;border:0;border-radius:8px;background:#0f2d5c;color:#fff;font-weight:700;cursor:pointer"><?= $yaInstalado ? 'Grabar conexión MySQL' : 'Instalar en MySQL' ?></button>
    </form>
  <?php endif; ?>
</div></body></html>

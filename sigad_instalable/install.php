<?php
/**
 * Instalador automatico de SIGAD PHP.
 * Uso: sube todos los archivos, abre /sigad/install.php, llena el formulario.
 * Elimina este archivo despues de instalar por seguridad.
 */
$base = __DIR__;   // install.php esta en la raiz del proyecto (igual que index.php)
$configFile = $base . '/config/config.php';
$error = '';
$done  = false;

// ¿Ya instalado? (config.php existe y tiene DB_NAME definido por el usuario)
if (is_file($configFile) && strpos(file_get_contents($configFile), 'DB_USER') !== false
    && !isset($_POST['reinstalar'])) {
    // permitir reinstalar con bandera
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $adm  = trim($_POST['admin_user'] ?? 'superadmin');
    $admPass = $_POST['admin_pass'] ?? '';
    $ent   = trim($_POST['entidad'] ?? 'MUNICIPALIDAD DISTRITAL DE SAN MARCOS');

    if (!$name || !$user || !$adm || strlen($admPass) < 6) {
        $error = 'Complete nombre BD, usuario BD, usuario admin y una clave de al menos 6 caracteres.';
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // crear BD si no existe
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");

            // importar esquema (quitando CREATE DATABASE / USE del sql original)
            $raw = file_get_contents($base . '/db/sigad.sql');
            $raw = preg_replace('/CREATE DATABASE[^;]*;/i', '', $raw);
            $raw = preg_replace('/USE\s+\w+;/i', '', $raw);
            // ejecutar statement por statement (PDO no acepta multi-query directo)
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $current = '';
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '--') === 0) continue; // salta comentarios
                $current .= $line . "\n";
                if (substr($line, -1) === ';') {   // fin de statement
                    $current = trim($current);
                    if ($current !== '') $pdo->exec($current);
                    $current = '';
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

            // crear administrador con la clave elegida
            $hash = password_hash($admPass, PASSWORD_DEFAULT);
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE username=?");
            $chk->execute([$adm]);
            if ($chk->fetch()) {
                $pdo->prepare("UPDATE usuarios SET password=?, rol='ADMIN', activo=1 WHERE username=?")
                    ->execute([$hash, $adm]);
            } else {
                $pdo->prepare("INSERT INTO usuarios (username,password,nombres,apellidos,cargo,rol,activo)
                    VALUES (?,?, 'Admin','Sistema','Administrador del sistema', 'ADMIN', 1)")
                    ->execute([$adm, $hash]);
            }

            // escribir config.php
            $tpl = "<?php\n";
            $tpl .= "define('DB_HOST',     " . var_export($host, true) . ");\n";
            $tpl .= "define('DB_NAME',     " . var_export($name, true) . ");\n";
            $tpl .= "define('DB_USER',     " . var_export($user, true) . ");\n";
            $tpl .= "define('DB_PASS',     " . var_export($pass, true) . ");\n";
            $tpl .= "define('DB_CHARSET',  'utf8mb4');\n\n";
            $tpl .= "define('APP_NOMBRE',  'SIGAD');\n";
            $tpl .= "define('ENTIDAD',     " . var_export($ent, true) . ");\n";
            $tpl .= "define('RUTA_BASE',   dirname(__DIR__));\n";
            $tpl .= "define('RUTA_UPLOADS', RUTA_BASE . '/uploads');\n";
            $tpl .= "define('URL_UPLOADS', 'uploads');\n\n";
            $tpl .= "if (session_status() === PHP_SESSION_NONE) {\n";
            $tpl .= "    \$sp = RUTA_BASE . '/tmp_sess';\n";
            $tpl .= "    if (!is_dir(\$sp)) @mkdir(\$sp, 0777, true);\n";
            $tpl .= "    if (is_dir(\$sp) && is_writable(\$sp)) session_save_path(\$sp);\n";
            $tpl .= "    session_start();\n}\n\n";
            $tpl .= "function db(): PDO {\n";
            $tpl .= "    static \$pdo;\n";
            $tpl .= "    if (\$pdo) return \$pdo;\n";
            $tpl .= "    \$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;\n";
            $tpl .= "    \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [\n";
            $tpl .= "        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n";
            $tpl .= "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);\n";
            $tpl .= "    return \$pdo;\n}\n\n";
            $tpl .= "function auditar(string \$accion, string \$detalle = ''): void {\n";
            $tpl .= "    if (!isset(\$_SESSION['uid'])) return;\n";
            $tpl .= "    \$ip = \$_SERVER['REMOTE_ADDR'] ?? '';\n";
            $tpl .= "    \$u = db()->prepare(\"INSERT INTO auditoria (usuario_id, username, accion, detalle, ip) VALUES (?,?,?,?,?)\");\n";
            $tpl .= "    \$u->execute([\$_SESSION['uid'], \$_SESSION['user'], \$accion, \$detalle, \$ip]);\n}\n\n";
            $tpl .= "function requiere_login(): void {\n";
            $tpl .= "    if (empty(\$_SESSION['uid'])) { header('Location: index.php'); exit; }\n}\n";
            $tpl .= "function puede_escribir(): bool { return in_array(\$_SESSION['rol'] ?? '', ['ADMIN','OPERADOR']); }\n";
            $tpl .= "function es_admin(): bool { return (\$_SESSION['rol'] ?? '') === 'ADMIN'; }\n";
            $tpl .= "function es_consulta(): bool { return (\$_SESSION['rol'] ?? '') === 'CONSULTA'; }\n";
            $tpl .= "function json_out(\$data, int \$code = 200): void {\n";
            $tpl .= "    http_response_code(\$code); header('Content-Type: application/json; charset=utf-8');\n";
            $tpl .= "    echo json_encode(\$data, JSON_UNESCAPED_UNICODE); exit;\n}\n";
            file_put_contents($configFile, $tpl);

            // permisos de uploads
            @chmod($base . '/uploads', 0755);
            $done = true;
        } catch (Throwable $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>SIGAD · Instalador</title>
<link rel="stylesheet" href="css/estilos.css"></head>
<body style="background:#0f2d5c;display:flex;align-items:center;justify-content:center;min-height:100vh">
<div style="background:#fff;border-radius:14px;padding:30px;width:440px;box-shadow:0 20px 60px rgba(0,0,0,.4)">
  <h1 style="color:#0f2d5c;margin:0 0 4px;letter-spacing:2px">SIGAD</h1>
  <p style="color:#64748b;margin:0 0 18px;font-size:13px">Instalación automática</p>
  <?php if ($done): ?>
    <div class="banner" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;padding:12px;border-radius:8px">
      ¡Instalado correctamente! <b>Elimine install.php</b> por seguridad.
    </div>
    <a class="btn" href="index.php" style="display:block;text-align:center;margin-top:16px;
       background:#0f2d5c;color:#fff;padding:11px;border-radius:8px;text-decoration:none;font-weight:700">Ir al login</a>
  <?php else: ?>
    <?php if ($error): ?><div style="background:#fee2e2;color:#991b1b;padding:9px;border-radius:7px;font-size:13px;margin-bottom:12px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <label style="font-size:12px;font-weight:600;color:#334155">Servidor BD</label>
      <input name="db_host" value="localhost" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Nombre de la base de datos</label>
      <input name="db_name" placeholder="sigad" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Usuario de la base de datos</label>
      <input name="db_user" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Clave de la base de datos</label>
      <input name="db_pass" type="password" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <hr style="border:0;border-top:1px solid #e2e8f0;margin:12px 0">
      <label style="font-size:12px;font-weight:600;color:#334155">Usuario administrador</label>
      <input name="admin_user" value="admin" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Clave del administrador (mín. 6)</label>
      <input name="admin_pass" type="password" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 10px">
      <label style="font-size:12px;font-weight:600;color:#334155">Nombre de la entidad</label>
      <input name="entidad" value="MUNICIPALIDAD DISTRITAL DE SAN MARCOS" style="width:100%;padding:9px;border:1px solid #e2e8f0;border-radius:8px;margin:4px 0 14px">
      <button type="submit" style="width:100%;padding:11px;border:0;border-radius:8px;background:#0f2d5c;color:#fff;font-weight:700;cursor:pointer">Instalar</button>
    </form>
  <?php endif; ?>
</div></body></html>

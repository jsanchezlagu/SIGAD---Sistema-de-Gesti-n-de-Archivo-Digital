<?php
/**
 * Configuracion central de SIGAD PHP.
 * En hosting compartido (cPanel) edita SOLO estas líneas con los datos que te
 * da el panel (phpMyAdmin / cPanel). No subas este archivo a repositorios
 * públicos: contiene las credenciales de tu base de datos.
 */
define('DB_HOST',     'localhost');
define('DB_NAME',     'sigad');
define('DB_USER',     'sigad_user');   // <- cambiar por el usuario de tu hosting
define('DB_PASS',     'sigad_pass');   // <- cambiar por tu clave
define('DB_CHARSET',  'utf8mb4');

define('APP_NOMBRE',  'SIGAD');
define('ENTIDAD',     'MUNICIPALIDAD DISTRITAL DE SAN MARCOS');
define('RUTA_BASE',   dirname(__DIR__));                  // raiz del proyecto (padre de config/)
define('RUTA_UPLOADS', RUTA_BASE . '/uploads');
define('URL_UPLOADS', 'uploads');

// Toda la lógica de sesión, seguridad (CSRF, cabeceras) y helpers vive en el
// núcleo común, para no duplicarla ni desincronizarla con el instalador.
require_once RUTA_BASE . '/includes/comun.php';

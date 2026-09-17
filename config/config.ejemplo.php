<?php
/**
 * Ejemplo de cargador. install.php copia esto a config.php si falta.
 * Las credenciales reales se guardan en config.local.php.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'sigad');
if (!defined('DB_USER'))    define('DB_USER',    'sigad_user');
if (!defined('DB_PASS'))    define('DB_PASS',    'sigad_pass');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

if (!defined('APP_NOMBRE')) define('APP_NOMBRE', 'SIGAD');
if (!defined('ENTIDAD'))    define('ENTIDAD',    'MUNICIPALIDAD DISTRITAL DE SAN MARCOS');
if (!defined('RUTA_BASE'))    define('RUTA_BASE',    dirname(__DIR__));
if (!defined('RUTA_UPLOADS')) define('RUTA_UPLOADS', RUTA_BASE . '/uploads');
if (!defined('URL_UPLOADS'))  define('URL_UPLOADS',  'uploads');

require_once RUTA_BASE . '/includes/comun.php';

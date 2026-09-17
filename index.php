<?php
// La ventana de ingreso vive en login.php (evita HTTP 500 en esa URL).
header('Location: login.php' . (isset($_GET['e']) ? ('?e=' . (int)$_GET['e']) : ''));
exit;

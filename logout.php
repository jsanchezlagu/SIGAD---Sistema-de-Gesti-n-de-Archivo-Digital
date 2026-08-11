<?php
require_once 'config/config.php';
auditar('SALIDA');
session_destroy();
header('Location: index.php');

<?php
require_once 'config/config.php';

if (isset($_SESSION['usuario_id'])) {
    registrarLog($_SESSION['usuario_id'], 'Cierre de sesión', 'Autenticación');
}

session_destroy();
header('Location: ' . BASE_URL . 'login.php');
exit;

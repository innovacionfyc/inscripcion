<?php
// admin/_auth.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// bloquear si no hay login
function require_login() {
    if (empty($_SESSION['uid'])) {
        header('Location: login.php');
        exit;
    }
}

// verificar rol
function require_role($role) {
    require_login();
    if (empty($_SESSION['rol']) || $_SESSION['rol'] !== $role) {
        http_response_code(403);
        echo "Acceso denegado.";
        exit;
    }
}

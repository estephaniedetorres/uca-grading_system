<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    if (!in_array($_SESSION['role'], $roles)) {
        header('Location: /unauthorized.php');
        exit;
    }
}

function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'name' => $_SESSION['name'] ?? $_SESSION['username']
        ];
    }
    return null;
}

function logout() {
    session_destroy();
    header('Location: /login.php');
    exit;
}

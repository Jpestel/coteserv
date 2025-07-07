<?php
// logout.php
// Page de déconnexion des utilisateurs
session_start();

// Destruction des données de session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

// Redirection vers la page d'accueil/login
header('Location: index.php');
exit;

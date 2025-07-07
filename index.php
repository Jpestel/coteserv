<?php
// index.php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
$pageTitle = 'Accueil';
require_once __DIR__ . '/includes/header.php';
?>
<div class="text-center">
    <h1>Bienvenue sur l’outil de gestion des plannings</h1>
    <p class="mt-4">
        <a href="login.php" class="btn">Se connecter</a>
        <a href="register.php" class="btn">S’inscrire</a>
    </p>
</div>
<?php
require_once __DIR__ . '/includes/footer.php';

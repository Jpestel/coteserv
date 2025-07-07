<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Gestion des plannings', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <header>
        <nav class="navbar">
            <a class="navbar-brand" href="index.php">Accueil</a>
            <button class="navbar-toggle" id="navbarToggle" aria-label="Menu">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </button>
            <div class="navbar-links" id="navbarLinks">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php">Tableau de bord</a>
                    <span class="navbar-user">
                        Bienvenue, <?= htmlspecialchars($_SESSION['user_prenom'] ?? $_SESSION['user_pseudo'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <a href="logout.php">Déconnexion</a>
                <?php else: ?>
                    <a href="login.php">Se connecter</a>
                    <a href="register.php">S'inscrire</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>
    <main class="container content">

        <script>
            // Toggle navigation for mobile
            document.getElementById('navbarToggle').addEventListener('click', function() {
                document.getElementById('navbarLinks').classList.toggle('active');
            });
        </script>
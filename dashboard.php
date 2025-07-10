<?php
// dashboard.php
session_start();
require_once __DIR__ . '/config/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$pageTitle   = 'Tableau de bord';
$userRole    = $_SESSION['user_role'];
$userPrenom  = htmlspecialchars($_SESSION['user_prenom'] ?? '', ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2>Bonjour, <?= $userPrenom ?> !</h2>
    <p>Vous êtes connecté en tant que <strong><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></strong>.</p>

    <?php if ($userRole === 'admin'): ?>
        <section>
            <h3>Administration</h3>
            <ul>
                <li><a href="admin_services.php">Gérer les services</a></li>
                <li><a href="admin_users.php">Gérer les utilisateurs</a></li>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($userRole === 'chefdecote'): ?>
        <section>
            <h3>Chef de côte</h3>
            <ul>
                <li><a href="chef_mois.php">Ouvrir/fermer un mois</a></li>
                <li><a href="chef_codes.php">Gérer les catégories et codes</a></li>
                <li><a href="chef_events.php">Gérer les événements</a></li>
                <li><a href="chef_souhaits.php">Valider/Refuser les souhaits</a></li>
            </ul>
        </section>
    <?php endif; ?>


    <section>
        <h3>Agents</h3>
        <ul>
            <li><a href="planning_souhaits.php">Saisir mes souhaits d'horaires</a></li>
            <li><a href="heures_sup.php">Saisir mes heures supplémentaires</a></li>
        </ul>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
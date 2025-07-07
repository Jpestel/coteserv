<?php
// admin_services_edit.php
// Page d'édition d'un service (séparée)
session_start();

require_once __DIR__ . '/config/db.php';

// Vérifier accès admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Récupération de l'ID du service
$serviceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($serviceId <= 0) {
    header('Location: admin_services.php');
    exit;
}

$errors = [];

// Récupération des données du service
$stmt = $pdo->prepare('SELECT id, nom, id_manager FROM service WHERE id = :id');
$stmt->execute(['id' => $serviceId]);
$service = $stmt->fetch();
if (!$service) {
    header('Location: admin_services.php');
    exit;
}

// Récupérer la liste des agents pour le select
$agents = $pdo->query('SELECT id, nom, prenom FROM user WHERE role IN ("agent","chefdecote","admin") ORDER BY nom, prenom')->fetchAll();

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceName = trim($_POST['service_name'] ?? '');
    $managerId   = ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null;

    // Validation
    if ($serviceName === '') {
        $errors[] = 'Le nom du service est requis.';
    }
    // Vérifier unicité en excluant l'actuel
    if (empty($errors)) {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM service WHERE nom = :nom AND id <> :id');
        $dup->execute(['nom' => $serviceName, 'id' => $serviceId]);
        if ($dup->fetchColumn() > 0) {
            $errors[] = 'Un autre service porte déjà ce nom.';
        }
    }

    // Mise à jour et redirection si OK
    if (empty($errors)) {
        $up = $pdo->prepare('UPDATE service SET nom = :nom, id_manager = :manager WHERE id = :id');
        $up->execute([
            'nom'     => $serviceName,
            'manager' => $managerId,
            'id'      => $serviceId
        ]);
        header('Location: admin_services.php');
        exit;
    }
}

$pageTitle = 'Éditer le service';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2>Éditer le service</h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="form-container">
        <label for="service_name">Nom du service</label>
        <input type="text" id="service_name" name="service_name" value="<?= htmlspecialchars($service['nom']) ?>" required>

        <label for="manager_id">Manager (optionnel)</label>
        <select id="manager_id" name="manager_id">
            <option value="">-- Aucun --</option>
            <?php foreach ($agents as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $a['id'] == $service['id_manager'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['nom'] . ' ' . $a['prenom']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Enregistrer les modifications</button>
        <a href="admin_services.php" class="btn">Retour</a>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
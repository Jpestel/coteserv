<?php
// admin_services.php
// Page d'administration des services (création, édition, suppression)
session_start();

require_once __DIR__ . '/config/db.php';

// Vérifier accès admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Gérer les services';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = null;

// Création de service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_service'])) {
    $serviceName = trim($_POST['service_name'] ?? '');
    $managerId   = $_POST['manager_id'] ?? null;
    if ($serviceName === '') {
        $errors[] = 'Le nom du service est requis.';
    }
    // Vérification doublon
    if (empty($errors)) {
        $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM service WHERE nom = :nom');
        $dupStmt->execute(['nom' => $serviceName]);
        if ($dupStmt->fetchColumn() > 0) {
            $errors[] = 'Un service avec ce nom existe déjà.';
        }
    }
    if (empty($errors)) {
        $stmt = $pdo->prepare('INSERT INTO service (nom, id_manager) VALUES (:nom, :manager)');
        $stmt->execute(['nom' => $serviceName, 'manager' => $managerId ?: null]);
        $success = 'Service créé avec succès.';
    }
}

// Traitement édition et suppression en POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Édition de service
    if (isset($_POST['edit_service'])) {
        $serviceId   = (int) ($_POST['service_id'] ?? 0);
        $serviceName = trim($_POST['service_name_edit'] ?? '');
        $managerId   = $_POST['manager_id_edit'] ?? null;
        if ($serviceName === '') {
            $errors[] = 'Le nom du service est requis pour l\'édition.';
        }
        if (empty($errors)) {
            $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM service WHERE nom = :nom AND id <> :id');
            $dupStmt->execute(['nom' => $serviceName, 'id' => $serviceId]);
            if ($dupStmt->fetchColumn() > 0) {
                $errors[] = 'Un autre service porte déjà ce nom.';
            }
        }
        if (empty($errors)) {
            $stmt = $pdo->prepare('UPDATE service SET nom = :nom, id_manager = :manager WHERE id = :id');
            $stmt->execute(['nom' => $serviceName, 'manager' => $managerId ?: null, 'id' => $serviceId]);
            $success = 'Service mis à jour avec succès.';
        }
    }
    // Suppression de service
    if (isset($_POST['delete_service'])) {
        $serviceId = (int) ($_POST['service_id_delete'] ?? 0);
        if ($serviceId > 0) {
            $stmt = $pdo->prepare('DELETE FROM service WHERE id = :id');
            $stmt->execute(['id' => $serviceId]);
            $success = 'Service supprimé avec succès.';
        }
    }
}

// Récupérer la liste des services
$services = $pdo->query(
    'SELECT s.id, s.nom, s.id_manager, u.nom AS manager_nom, u.prenom AS manager_prenom
     FROM service s
     LEFT JOIN user u ON s.id_manager = u.id
     ORDER BY s.nom'
)->fetchAll();

// Récupérer les agents pour les sélections
$agents = $pdo->query(
    'SELECT id, nom, prenom FROM user WHERE role IN ("agent","chefdecote","admin") ORDER BY nom, prenom'
)->fetchAll();
?>

<div class="container content">
    <h2>Gérer les services</h2>

    <!-- Messages -->
    <?php if ($errors): ?>
        <div class="errors">
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color: #d4edda; color: #155724; border-color: #c3e6cb;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire création -->
    <form method="post" class="form-container mb-4">
        <h3>Créer un nouveau service</h3>
        <label for="service_name">Nom du service</label>
        <input type="text" id="service_name" name="service_name" required>

        <label for="manager_id">Manager (optionnel)</label>
        <select id="manager_id" name="manager_id">
            <option value="">-- Aucun --</option>
            <?php foreach ($agents as $a): ?>
                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nom'] . ' ' . $a['prenom']) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" name="create_service">Créer</button>
    </form>

    <!-- Tableau des services -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nom du service</th>
                <th>Manager</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($services): ?>
                <?php foreach ($services as $s): ?>
                    <tr>
                        <td><?= $s['id'] ?></td>
                        <td><?= htmlspecialchars($s['nom']) ?></td>
                        <td><?= $s['manager_nom'] ? htmlspecialchars($s['manager_nom'] . ' ' . $s['manager_prenom']) : '-' ?></td>
                        <td>
                            <a href="admin_services_edit.php?id=<?= $s['id'] ?>" class="btn">Éditer</a>
                            <form method="post" style="display:inline-block; margin-left:0.5rem;" onsubmit="return confirm('Confirmer la suppression ?');">
                                <input type="hidden" name="service_id_delete" value="<?= $s['id'] ?>">
                                <button type="submit" name="delete_service" class="btn btn-danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">Aucun service trouvé.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
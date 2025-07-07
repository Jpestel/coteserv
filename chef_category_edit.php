<?php
// chef_category_edit.php
// Édition d'une catégorie pour le chefdecote
session_start();
require_once __DIR__ . '/config/db.php';

// Vérifier accès chefdecote
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

// Récupérer ID de la catégorie
$catId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($catId <= 0) {
    header('Location: chef_codes.php');
    exit;
}

$pageTitle = 'Éditer catégorie';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = null;
$serviceId = $_SESSION['user_service_id'];

// Charger les données de la catégorie
$stmt = $pdo->prepare('SELECT name FROM code_category WHERE id = ? AND service_id = ?');
$stmt->execute([$catId, $serviceId]);
$category = $stmt->fetch();
if (!$category) {
    header('Location: chef_codes.php');
    exit;
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newName = trim($_POST['category_name'] ?? '');
    if ($newName === '') {
        $errors[] = 'Le nom de la catégorie est requis.';
    } else {
        // Vérifier doublon sur ce service
        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM code_category WHERE name = ? AND service_id = ? AND id <> ?'
        );
        $dup->execute([$newName, $serviceId, $catId]);
        if ($dup->fetchColumn() > 0) {
            $errors[] = 'Une autre catégorie porte déjà ce nom.';
        }
    }

    // Mise à jour
    if (empty($errors)) {
        $upd = $pdo->prepare(
            'UPDATE code_category SET name = ? WHERE id = ? AND service_id = ?'
        );
        $upd->execute([$newName, $catId, $serviceId]);
        $success = 'Catégorie mise à jour avec succès.';
        $category['name'] = $newName;
    }
}
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" class="form-container">
        <label for="category_name">Nom de la catégorie</label>
        <input type="text" id="category_name" name="category_name"
            value="<?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?>" required>

        <button type="submit" class="btn">Enregistrer les modifications</button>
        <a href="chef_codes.php" class="btn">Retour</a>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
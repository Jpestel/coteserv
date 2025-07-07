<?php
// chef_codes_edit.php
// Édition d'un code horaire pour le chefdecote
session_start();
require_once __DIR__ . '/config/db.php';

// Vérifier accès chefdecote
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

// Récupérer ID du code
$codeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($codeId <= 0) {
    header('Location: chef_codes.php');
    exit;
}

$pageTitle = 'Éditer code';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = null;
$serviceId = $_SESSION['user_service_id'];

// Charger les catégories pour le select
$catsStmt = $pdo->prepare('SELECT id, name FROM code_category WHERE service_id = ? ORDER BY name');
$catsStmt->execute([$serviceId]);
$categories = $catsStmt->fetchAll();

// Charger les données du code
$stmt = $pdo->prepare(
    'SELECT category_id, code, label FROM code WHERE id = ?'
);
$stmt->execute([$codeId]);
$code = $stmt->fetch();
if (!$code) {
    header('Location: chef_codes.php');
    exit;
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $codeKey    = trim($_POST['code_key'] ?? '');
    $codeLabel  = trim($_POST['code_label'] ?? '');

    // Validation
    if ($categoryId <= 0) {
        $errors[] = 'Veuillez sélectionner une catégorie.';
    }
    if ($codeKey === '') {
        $errors[] = 'La clé du code est requise.';
    }
    if ($codeLabel === '') {
        $errors[] = 'La description du code est requise.';
    }

    // Vérifier doublon (autre que ce code)
    if (empty($errors)) {
        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM code WHERE category_id = ? AND code = ? AND id <> ?'
        );
        $dup->execute([$categoryId, $codeKey, $codeId]);
        if ($dup->fetchColumn() > 0) {
            $errors[] = 'Un autre code utilise déjà cette clé dans la catégorie.';
        }
    }

    // Mise à jour
    if (empty($errors)) {
        $upd = $pdo->prepare(
            'UPDATE code SET category_id = ?, code = ?, label = ? WHERE id = ?'
        );
        $upd->execute([$categoryId, $codeKey, $codeLabel, $codeId]);
        $success = 'Code mis à jour avec succès.';
        // rafraîchir valeurs
        $code['category_id'] = $categoryId;
        $code['code'] = $codeKey;
        $code['label'] = $codeLabel;
    }
}
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" class="form-container">
        <label for="category_id">Catégorie</label>
        <select id="category_id" name="category_id" required>
            <option value="">-- Choisir --</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $code['category_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="code_key">Clé du code</label>
        <input type="text" id="code_key" name="code_key" value="<?= htmlspecialchars($code['code']) ?>" maxlength="<?= $maxCodeLength ?>" required>

        <label for="code_label">Description / Plage horaire</label>
        <input type="text" id="code_label" name="code_label" value="<?= htmlspecialchars($code['label']) ?>" required>

        <button type="submit" class="btn">Enregistrer les modifications</button>
        <a href="chef_codes.php" class="btn">Retour</a>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
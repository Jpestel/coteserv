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

$maxCodeLen = 20; // correspond à la longueur du champ `code`
$pageTitle = 'Éditer un code horaire';
require_once __DIR__ . '/includes/header.php';

$errors    = [];
$success   = null;
$serviceId = $_SESSION['user_service_id'];

// Charger les catégories pour le select
$catsStmt = $pdo->prepare('SELECT id, name FROM code_category WHERE service_id = ? ORDER BY name');
$catsStmt->execute([$serviceId]);
$categories = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

// Charger les données du code (incluant HS minutes)
$stmt = $pdo->prepare(
    'SELECT category_id, code, label, heures_supplementaires_inc
       FROM code
      WHERE id = ?'
);
$stmt->execute([$codeId]);
$code = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$code) {
    header('Location: chef_codes.php');
    exit;
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $codeKey    = trim($_POST['code_key'] ?? '');
    $codeLabel  = trim($_POST['code_label'] ?? '');
    $hsMins     = (int)($_POST['hs_minutes'] ?? 0);

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
    if ($hsMins < 0) {
        $errors[] = 'Le nombre de minutes HS doit être supérieur ou égal à 0.';
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
            'UPDATE code
               SET category_id = ?, code = ?, label = ?, heures_supplementaires_inc = ?
             WHERE id = ?'
        );
        $upd->execute([$categoryId, $codeKey, $codeLabel, $hsMins, $codeId]);
        $success = 'Code mis à jour avec succès.';
        // rafraîchir valeurs
        $code['category_id'] = $categoryId;
        $code['code']        = $codeKey;
        $code['label']       = $codeLabel;
        $code['heures_supplementaires_inc'] = $hsMins;
    }
}
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="post" class="form-container">
        <label for="category_id">Catégorie</label>
        <select id="category_id" name="category_id" required>
            <option value="">-- Choisir --</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $code['category_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="code_key">Clé du code (max <?= $maxCodeLen ?> car.)</label>
        <input type="text" id="code_key" name="code_key" maxlength="<?= $maxCodeLen ?>" value="<?= htmlspecialchars($code['code'], ENT_QUOTES) ?>" required>

        <label for="code_label">Description / Plage horaire</label>
        <input type="text" id="code_label" name="code_label" value="<?= htmlspecialchars($code['label'], ENT_QUOTES) ?>" required>

        <label for="hs_minutes">Minutes HS incluses (optionnel)</label>
        <input type="number" id="hs_minutes" name="hs_minutes" min="0" step="1" value="<?= (int)$code['heures_supplementaires_inc'] ?>">
        <small>Entrez en minutes (ex: 90 pour 1h30).</small>

        <button type="submit" class="btn btn-success">Enregistrer les modifications</button>
        <a href="chef_codes.php" class="btn btn-secondary">Retour</a>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
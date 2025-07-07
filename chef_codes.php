<?php
// chef_codes.php
// Gestion des catégories et codes horaires pour le chefdecote
session_start();
require_once __DIR__ . '/config/db.php';

// Vérifier accès chefdecote
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Catégories & Codes';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = null;
$serviceId = $_SESSION['user_service_id'];
$maxCodeLength = 20; // correspond à la longueur du champ `code`

// Création de catégorie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_category'])) {
    $catName = trim($_POST['category_name'] ?? '');
    if ($catName === '') {
        $errors[] = 'Le nom de la catégorie est requis.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM code_category WHERE name = ? AND service_id = ?');
        $stmt->execute([$catName, $serviceId]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Une catégorie porte déjà ce nom.';
        } else {
            $ins = $pdo->prepare('INSERT INTO code_category (service_id, name) VALUES (?, ?)');
            $ins->execute([$serviceId, $catName]);
            $success = 'Catégorie créée.';
        }
    }
}

// Création de code avec inclusion d'heures supplémentaires
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_code'])) {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $codeKey    = trim($_POST['code_key'] ?? '');
    $codeLabel  = trim($_POST['code_label'] ?? '');
    $hsHours    = (float)($_POST['hs_hours'] ?? 0);
    $hsInc      = (int)round($hsHours * 60);

    if ($categoryId <= 0 || $codeKey === '' || $codeLabel === '') {
        $errors[] = 'Clé, description et catégorie sont requis.';
    } elseif ($hsInc < 0) {
        $errors[] = 'Le nombre d\'heures HS incluses doit être supérieur ou égal à 0.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM code WHERE category_id = ? AND code = ?');
        $stmt->execute([$categoryId, $codeKey]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Ce code existe déjà pour la catégorie sélectionnée.';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO code (category_id, code, label, heures_supplementaires_inc) VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$categoryId, $codeKey, $codeLabel, $hsInc]);
            $success = 'Code créé.';
        }
    }
}

// Suppression catégorie avec contrainte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category_id'])) {
    $catId = (int)$_POST['delete_category_id'];
    $check = $pdo->prepare('SELECT COUNT(*) FROM code WHERE category_id = ?');
    $check->execute([$catId]);
    if ($check->fetchColumn() > 0) {
        $errors[] = 'Impossible de supprimer : cette catégorie contient des codes. Supprimez d\'abord les codes associés.';
    } else {
        $del = $pdo->prepare('DELETE FROM code_category WHERE id = ? AND service_id = ?');
        $del->execute([$catId, $serviceId]);
        $success = 'Catégorie supprimée.';
    }
}

// Suppression code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_code_id'])) {
    $codeId = (int)$_POST['delete_code_id'];
    $del = $pdo->prepare('DELETE FROM code WHERE id = ?');
    $del->execute([$codeId]);
    $success = 'Code supprimé.';
}

// Récupérer catégories pour affichage
$catsStmt = $pdo->prepare('SELECT id, name FROM code_category WHERE service_id = ? ORDER BY name');
$catsStmt->execute([$serviceId]);
$categories = $catsStmt->fetchAll();

// Récupérer codes pour affichage
$codesStmt = $pdo->prepare(
    'SELECT c.id, c.code, c.label, c.heures_supplementaires_inc, cat.name AS category
     FROM code c
     JOIN code_category cat ON c.category_id = cat.id
     WHERE cat.service_id = ?
     ORDER BY cat.name, c.code'
);
$codesStmt->execute([$serviceId]);
$codes = $codesStmt->fetchAll();
?>

<div class="container content">
    <h2>Gérer catégories et codes horaires</h2>

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

    <!-- Formulaire création catégorie -->
    <section class="mb-4">
        <h3>Ajouter une catégorie</h3>
        <form method="post" class="form-container">
            <label for="category_name">Nom de la catégorie</label>
            <input type="text" id="category_name" name="category_name" required>
            <button type="submit" name="create_category">Créer catégorie</button>
        </form>
    </section>

    <!-- Tableau des catégories -->
    <section class="mb-4">
        <h3>Liste des catégories</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Catégorie</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?= $cat['id'] ?></td>
                        <td><?= htmlspecialchars($cat['name']) ?></td>
                        <td>
                            <a href="chef_category_edit.php?id=<?= $cat['id'] ?>" class="btn btn-sm">Éditer</a>
                            <form method="post" style="display:inline-block;margin-left:.5rem;" onsubmit="return confirm('Supprimer cette catégorie ?');">
                                <input type="hidden" name="delete_category_id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- Formulaire création code -->
    <section class="mb-4">
        <h3>Ajouter un code horaire</h3>
        <form method="post" class="form-container">
            <label for="category_id">Catégorie</label>
            <select id="category_id" name="category_id" required>
                <option value="">-- Choisir --</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="code_key">Clé du code (max <?= $maxCodeLength ?> car.)</label>
            <input type="text" id="code_key" name="code_key" maxlength="<?= $maxCodeLength ?>" required>
            <label for="code_label">Description / Plage horaire</label>
            <input type="text" id="code_label" name="code_label" required>
            <label for="hs_hours">Heures HS incluses (optionnel)</label>
            <input type="number" id="hs_hours" name="hs_hours" min="0" step="0.25" value="0">
            <small>Entrez en heures (ex: 1.5 pour 1h30).</small>
            <button type="submit" name="create_code">Créer code</button>
        </form>
    </section>

    <!-- Tableau des codes -->
    <section>
        <h3>Liste des codes</h3>
        <table>
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th>Code</th>
                    <th>Description</th>
                    <th>HS incluses (mn)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($codes as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['category']) ?></td>
                        <td><?= htmlspecialchars($c['code']) ?></td>
                        <td><?= htmlspecialchars($c['label']) ?></td>
                        <td>
                            <?php $mins = (int)$c['heures_supplementaires_inc'];
                            echo $mins;
                            $h = floor($mins / 60);
                            $m = $mins % 60;
                            echo ' (' . $h . 'h' . sprintf('%02d', $m) . 'mn)'; ?>
                        </td>
                        <td>
                            <a href="chef_codes_edit.php?id=<?= $c['id'] ?>" class="btn btn-sm">Éditer</a>
                            <form method="post" style="display:inline-block;margin-left:.5rem;" onsubmit="return confirm('Supprimer ce code ?');">
                                <input type="hidden" name="delete_code_id" value="<?= $c['id'] ?>">
                                <button type="submit" name="delete_code" class="btn btn-sm btn-danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
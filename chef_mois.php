<?php
// chef_mois.php
// Page de gestion des mois ouverts pour le chefdecote
session_start();

require_once __DIR__ . '/config/db.php';

// Vérifier accès chefdecote
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Gestion des mois ouverts';
require_once __DIR__ . '/includes/header.php';

$errors = [];
$success = null;
$serviceId = $_SESSION['user_service_id'];

// Formatter français
$formatter = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN,
    'MMMM yyyy'
);

// Prochains 6 mois
$monthsAvailable = [];
for ($i = 1; $i <= 6; $i++) {
    $dt = new DateTimeImmutable("first day of +{$i} month");
    $key = $dt->format('Y-m');
    $monthsAvailable[$key] = ucfirst($formatter->format($dt));
}

// Charger mois existants
$stmt = $pdo->prepare(
    'SELECT id, mois, actif, jours_ouvres_par_semaine FROM mois_ouvert WHERE id_service = :sid ORDER BY mois'
);
$stmt->execute(['sid' => $serviceId]);
$allMonths = $stmt->fetchAll();
$existingMonths = array_column($allMonths, 'mois');

// Actions individuelles
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_single'])) {
    $mid = (int)($_POST['month_id'] ?? 0);
    $action = $_POST['toggle_action'] ?? '';
    if ($mid > 0) {
        switch ($action) {
            case 'open':
                $pdo->prepare('UPDATE mois_ouvert SET actif = 1 WHERE id = ?')
                    ->execute([$mid]);
                $success = 'Mois ouvert.';
                break;
            case 'close':
                $pdo->prepare('UPDATE mois_ouvert SET actif = 0 WHERE id = ?')
                    ->execute([$mid]);
                $success = 'Mois fermé.';
                break;
            case 'delete':
                $pdo->prepare('DELETE FROM mois_ouvert WHERE id = ?')
                    ->execute([$mid]);
                $success = 'Mois supprimé.';
                break;
        }
    }
    // Recharger liste
    $stmt->execute(['sid' => $serviceId]);
    $allMonths = $stmt->fetchAll();
    $existingMonths = array_column($allMonths, 'mois');
}

// Formulaire ouverture multiple
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_months'])) {
    $selected = $_POST['months'] ?? [];
    if (empty($selected)) {
        $errors[] = 'Veuillez sélectionner au moins un mois.';
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Charger tous les mois existants sans modifier leur statut
            $stmtSel = $pdo->prepare('SELECT id, mois FROM mois_ouvert WHERE id_service = ?');
            $stmtSel->execute([$serviceId]);
            $existing = $stmtSel->fetchAll(PDO::FETCH_KEY_PAIR);

            // 2. Préparer activation et insertion
            $upd = $pdo->prepare('UPDATE mois_ouvert SET actif = 1 WHERE id = ?');
            $ins = $pdo->prepare(
                'INSERT INTO mois_ouvert (mois, actif, id_service, jours_ouvres_par_semaine)
                 VALUES (?, 1, ?, 5)'
            );

            // 3. Pour chaque mois sélectionné : activer s’il existe, sinon insérer
            foreach ($selected as $mois) {
                if (isset($existing[$mois])) {
                    $upd->execute([$existing[$mois]]);
                } else {
                    $ins->execute([$mois, $serviceId]);
                }
            }

            $pdo->commit();
            $success = 'Mois ouverts avec succès.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Erreur lors de l\'ouverture des mois : ' . $e->getMessage();
        }

        // 4. Recharger la liste des mois pour l’affichage
        $stmt->execute(['sid' => $serviceId]);
        $allMonths = $stmt->fetchAll();
    }
}

?>

<div class="container content">
    <h2>Gestion des mois ouverts</h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" class="form-container mb-4">
        <h3>Sélectionner les mois à ouvrir</h3>
        <label for="months">Mois disponibles</label>
        <select id="months" name="months[]" multiple size="6">
            <?php foreach ($monthsAvailable as $value => $label): ?>
                <?php if (in_array($value, $existingMonths, true)) continue; ?>
                <option value="<?= $value ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <small>Ctrl/Cmd + clic pour plusieurs.</small></br>
        <button type="submit" name="open_months">Enregistrer</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Mois</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($allMonths): ?>
                <?php foreach ($allMonths as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($formatter->format(new DateTimeImmutable($m['mois'] . '-01')), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $m['actif'] ? 'Ouvert' : 'Fermé' ?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="month_id" value="<?= $m['id'] ?>">
                                <input type="hidden" name="toggle_action" value="<?= $m['actif'] ? 'close' : 'open' ?>">
                                <button type="submit" name="toggle_single" class="btn btn-sm btn-<?= $m['actif'] ? 'warning' : 'success' ?>">
                                    <?= $m['actif'] ? 'Fermer' : 'Ouvrir' ?>
                                </button>
                            </form>
                            <a href="chef_mois_edit.php?id=<?= $m['id'] ?>" class="btn btn-sm" style="margin:0 0.5rem;">Éditer</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer définitivement ?');">
                                <input type="hidden" name="month_id" value="<?= $m['id'] ?>">
                                <input type="hidden" name="toggle_action" value="delete">
                                <button type="submit" name="toggle_single" class="btn btn-sm btn-danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">Aucun mois défini.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
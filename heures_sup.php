<?php
// heures_sup.php
session_start();
require_once __DIR__ . '/config/db.php';

// 0) Sécurité : tout utilisateur connecté peut y accéder
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = $_SESSION['user_id'];

// 1) Construire la liste des mois où l'utilisateur a saisi des HS
$monthsStmt = $pdo->prepare("
    SELECT DISTINCT DATE_FORMAT(date_saisie, '%Y-%m') AS ym
      FROM heures_supplementaires
     WHERE user_id = ?
     ORDER BY ym DESC
");
$monthsStmt->execute([$userId]);
$monthRows = array_column($monthsStmt->fetchAll(PDO::FETCH_ASSOC), 'ym');
if (empty($monthRows)) {
    // au moins le mois courant
    $monthRows[] = (new DateTimeImmutable())->format('Y-m');
}
$selectedMonth = $_GET['month'] ?? $monthRows[0];

// 2) Formatters
$fmtMonthYear = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN,
    'LLLL yyyy'
);
$fmtDate = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN
);

// 3) Traitement POST (ajout, suppression)
$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        // suppression
        $del = $pdo->prepare("DELETE FROM heures_supplementaires WHERE id = ? AND user_id = ?");
        $del->execute([(int)$_POST['delete_id'], $userId]);
        $success = 'Saisie supprimée.';
    } else {
        // ajout ou mise à jour
        $date   = trim($_POST['date'] ?? '');
        $minutes = (int)($_POST['minutes'] ?? 0);
        $source = trim($_POST['source'] ?? '');
        $editId = $_POST['edit_id'] ?? null;

        if (!$date) {
            $errors[] = 'La date est requise.';
        }
        if ($minutes <= 0) {
            $errors[] = 'Le nombre de minutes doit être supérieur à zéro.';
        }
        if ($source === '') {
            $errors[] = 'Le libellé (source) est requis.';
        }

        // unicité : si création et date déjà existante, erreur
        if (empty($errors) && !$editId) {
            $u = $pdo->prepare("
                SELECT COUNT(*) FROM heures_supplementaires
                 WHERE user_id = ? AND DATE(date_saisie) = ?
            ");
            $u->execute([$userId, $date]);
            if ($u->fetchColumn() > 0) {
                $errors[] = "Vous avez déjà saisi des HS pour le $date.";
            }
        }

        if (empty($errors)) {
            if ($editId) {
                // mise à jour
                $upd = $pdo->prepare("
                    UPDATE heures_supplementaires
                       SET date_saisie = ?, minutes = ?, source = ?
                     WHERE id = ? AND user_id = ?
                ");
                $upd->execute([
                    "$date 00:00:00",
                    $minutes,
                    $source,
                    (int)$editId,
                    $userId
                ]);
                $success = 'Saisie mise à jour.';
            } else {
                // insertion
                $ins = $pdo->prepare("
                    INSERT INTO heures_supplementaires
                      (user_id, date_saisie, minutes, source)
                    VALUES (?, ?, ?, ?)
                ");
                $ins->execute([
                    $userId,
                    "$date 00:00:00",
                    $minutes,
                    $source
                ]);
                $success = 'Heures supplémentaires enregistrées.';
                // si nouveau mois, l'ajouter à la liste
                if (!in_array(substr($date, 0, 7), $monthRows, true)) {
                    array_unshift($monthRows, substr($date, 0, 7));
                }
            }
        }
    }
}

// 4) Charger les saisies *du mois sélectionné*
$start = "$selectedMonth-01";
$end   = (new DateTimeImmutable("$selectedMonth-01"))
    ->modify('last day of this month')
    ->format('Y-m-d');
$listStmt = $pdo->prepare("
    SELECT id, DATE(date_saisie) AS d, minutes, source, created_at
      FROM heures_supplementaires
     WHERE user_id = ?
       AND DATE(date_saisie) BETWEEN ? AND ?
     ORDER BY date_saisie DESC
");
$listStmt->execute([$userId, $start, $end]);
$entries = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// 5) Calcul du total cumulé jusqu'à fin du mois sélectionné
$totalStmt = $pdo->prepare("
    SELECT SUM(minutes) FROM heures_supplementaires
     WHERE user_id = ?
       AND DATE(date_saisie) <= ?
");
$totalStmt->execute([$userId, $end]);
$totalMinutes = (int)$totalStmt->fetchColumn();
$totalH = floor($totalMinutes / 60);
$totalM = $totalMinutes % 60;

$pageTitle = 'Saisie des heures supplémentaires';
require __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <!-- Sélecteur de mois/année -->
    <form method="get" class="form-container mb-4">
        <label for="month">Afficher le mois</label>
        <select name="month" id="month" onchange="this.form.submit()">
            <?php foreach ($monthRows as $ym):
                $dt = new DateTimeImmutable("$ym-01");
            ?>
                <option value="<?= $ym ?>" <?= $ym === $selectedMonth ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($fmtMonthYear->format($dt)), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Formulaire de saisie / édition -->
    <section class="form-container mb-4">
        <h3><?= isset($_POST['edit_id']) ? 'Modifier une saisie' : 'Ajouter une saisie' ?></h3>
        <form method="post">
            <?php if (!empty($_POST['edit_id'])): ?>
                <input type="hidden" name="edit_id" value="<?= (int)$_POST['edit_id'] ?>">
            <?php endif; ?>

            <label for="date">Date</label>
            <input type="date" id="date" name="date" required
                value="<?= htmlspecialchars($_POST['date'] ?? '') ?>">

            <label for="minutes">Minutes effectuées</label>
            <input type="number" id="minutes" name="minutes" min="1" required
                value="<?= htmlspecialchars($_POST['minutes'] ?? '') ?>">

            <label for="source">Libellé / Source</label>
            <input type="text" id="source" name="source" required
                value="<?= htmlspecialchars($_POST['source'] ?? '') ?>">

            <button type="submit" class="btn btn-success">
                <?= isset($_POST['edit_id']) ? 'Mettre à jour' : 'Enregistrer' ?>
            </button>
        </form>
    </section>

    <!-- Tableau des saisies -->
    <section>
        <h3><?= ucfirst($fmtMonthYear->format(new DateTimeImmutable("$selectedMonth-01"))) ?></h3>
        <?php if (empty($entries)): ?>
            <p>Aucune saisie ce mois-ci.</p>
        <?php else: ?>
            <table class="planning-all">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Minutes</th>
                        <th>Source</th>
                        <th>Saisie le</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $row): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $fmtDate->format(new DateTimeImmutable($row['d'])),
                                    ENT_QUOTES
                                ) ?>
                            </td>
                            <td><?= htmlspecialchars($row['minutes'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['source'], ENT_QUOTES) ?></td>
                            <td>
                                <?= htmlspecialchars(
                                    (new DateTimeImmutable($row['created_at']))->format('d/m/Y H:i'),
                                    ENT_QUOTES
                                ) ?>
                            </td>
                            <td>
                                <!-- Éditer -->
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="edit_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="date" value="<?= substr($row['d'], 0, 10) ?>">
                                    <input type="hidden" name="minutes" value="<?= $row['minutes'] ?>">
                                    <input type="hidden" name="source" value="<?= htmlspecialchars($row['source'], ENT_QUOTES) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Éditer</button>
                                </form>
                                <!-- Supprimer -->
                                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette saisie ?');">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="mt-3">
                <strong>Total cumulé jusqu’à <?= htmlspecialchars(
                                                    ucfirst($fmtMonthYear->format(new DateTimeImmutable("$selectedMonth-01"))),
                                                    ENT_QUOTES
                                                ) ?> :</strong>
                <?= $totalMinutes ?> minutes (<?= $totalH ?>h<?= sprintf('%02d', $totalM) ?>)
            </p>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
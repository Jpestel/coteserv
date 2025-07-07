<?php
// chef_mois_edit.php
// Édition des jours ouvrés et jours fériés d'un mois ouvert avec override
session_start();
require_once __DIR__ . '/config/db.php';

// Vérifier accès chefdecote
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

// Récupération de l'ID du mois à éditer
$monthId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($monthId <= 0) {
    header('Location: chef_mois.php');
    exit;
}

// Formatters français
$monthFormatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'MMMM yyyy');
$weekdayFormatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'EEEE');

// Charger les informations du mois
$stmt = $pdo->prepare('SELECT mois, jours_ouvres_par_semaine FROM mois_ouvert WHERE id = ?');
$stmt->execute([$monthId]);
$month = $stmt->fetch();
if (!$month) {
    header('Location: chef_mois.php');
    exit;
}

list($year, $mon) = explode('-', $month['mois']);
$daysInMonth = (int)(new DateTimeImmutable("{$year}-{$mon}-01"))->format('t');

// Jours fériés fixes (MM-DD)
$fixedHolidays = ['01-01', '05-01', '05-08', '07-14', '08-15', '11-01', '11-11', '12-25'];

// Charger overrides existants
$stmt = $pdo->prepare('SELECT date, is_ferie FROM jours_feries_override WHERE mois_ouvert_id = ?');
$stmt->execute([$monthId]);
$overrides = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $overrides[$row['date']] = (bool)$row['is_ferie'];
}

$errors = [];
$success = null;
$isRefresh = isset($_POST['refresh']);

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Jours ouvrés
    $jours = isset($_POST['jours']) ? (int)$_POST['jours'] : $month['jours_ouvres_par_semaine'];
    if (!in_array($jours, [5, 6, 7], true)) {
        $errors[] = 'Nombre de jours ouvrés invalide.';
    }
    // Jours sélectionnés
    $selected = $_POST['holidays'] ?? [];

    // Construire nouveaux overrides pour tous les jours ouvrés
    $newOverrides = [];
    if (!$isRefresh) {
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date    = sprintf('%04d-%02d-%02d', $year, $mon, $d);
            $weekday = (int)(new DateTimeImmutable($date))->format('N');
            if ($weekday > $jours) continue;
            // Coché = férié, décoché = ouvré
            $newOverrides[$date] = in_array($date, $selected, true);
        }
    }

    if (empty($errors)) {
        // Mise à jour du nombre de jours ouvrés
        $pdo->prepare('UPDATE mois_ouvert SET jours_ouvres_par_semaine = ? WHERE id = ?')
            ->execute([$jours, $monthId]);

        if (!$isRefresh) {
            // Upsert overrides
            $ins = $pdo->prepare('INSERT INTO jours_feries_override (mois_ouvert_id, date, is_ferie) VALUES (?, ?, ?)');
            $upd = $pdo->prepare('UPDATE jours_feries_override SET is_ferie = ? WHERE mois_ouvert_id = ? AND date = ?');
            foreach ($newOverrides as $date => $isFerie) {
                if (isset($overrides[$date])) {
                    $upd->execute([(int)$isFerie, $monthId, $date]);
                } else {
                    $ins->execute([$monthId, $date, (int)$isFerie]);
                }
            }
            $overrides = $newOverrides;
            $success   = 'Mise à jour enregistrée.';
        } else {
            $success   = 'Affichage mis à jour.';
        }
        $month['jours_ouvres_par_semaine'] = $jours;
    }
}

// Affichage
$pageTitle = 'Éditer ' . htmlspecialchars($monthFormatter->format(new DateTimeImmutable($year . '-' . $mon . '-01')), ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container content">
    <h2><?= $pageTitle ?></h2>
    <?php if ($errors): ?>
        <div class="errors">
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <form method="post" class="form-container">
        <label for="jours">Jours ouvrés par semaine</label>
        <select id="jours" name="jours">
            <?php foreach ([5, 6, 7] as $j): ?>
                <option value="<?= $j ?>" <?= $month['jours_ouvres_par_semaine'] == $j ? 'selected' : '' ?>><?= $j ?> jours</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="refresh" class="btn" style="margin:1rem 0;">Afficher les jours</button>
        <h3>Jours fériés</h3>
        <small>Cases cochées = fériés; décochées = ouvrés.</small>
        <div style="max-height:200px;overflow:auto;padding:0.5rem;border:1px solid #ccc;">
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $date    = sprintf('%04d-%02d-%02d', $year, $mon, $d);
                $weekday = (int)(new DateTimeImmutable($date))->format('N');
                if ($weekday > $month['jours_ouvres_par_semaine']) continue;
                $isFixed = in_array(substr($date, 5), $fixedHolidays, true);
                $isChecked = $overrides[$date] ?? $isFixed;
                $weekdayFr = ucfirst($weekdayFormatter->format(new DateTimeImmutable($date)));
            ?>
                <div>
                    <label>
                        <input type="checkbox" name="holidays[]" value="<?= $date ?>" <?= $isChecked ? 'checked' : '' ?>>
                        <?= $d ?> <?= htmlspecialchars($weekdayFr) ?> <?php if ($isFixed): ?><em>(férié)</em><?php endif; ?>
                    </label>
                </div>
            <?php endfor; ?>
        </div>
        <button type="submit" class="btn">Enregistrer</button>
        <a href="chef_mois.php" class="btn">Retour</a>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
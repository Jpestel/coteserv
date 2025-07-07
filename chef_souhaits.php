<?php
// chef_souhaits.php
session_start();
require_once __DIR__ . '/config/db.php';

// 0) Accès réservé au chefdecote et admin
if (
    !isset($_SESSION['user_id'])
    || !in_array($_SESSION['user_role'], ['chefdecote', 'admin'], true)
) {
    header('Location: login.php');
    exit;
}

$userId    = $_SESSION['user_id'];
$serviceId = $_SESSION['user_service_id'];
$isChef    = $_SESSION['user_role'] === 'chefdecote';

// 0.5) Préinitialisation pour éviter warnings
$allCodes = [];

// 1) Charger les mois ouverts (français)
$moisStmt = $pdo->prepare(
    'SELECT id, mois
       FROM mois_ouvert
      WHERE id_service=? AND actif=1
      ORDER BY mois'
);
$moisStmt->execute([$serviceId]);
$moisList = $moisStmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Récupérer la liste des agents
$agStmt = $pdo->prepare(
    'SELECT id, prenom, nom
       FROM user
      WHERE id_service=? AND role IN ("agent","chefdecote","admin")
      ORDER BY nom, prenom'
);
$agStmt->execute([$serviceId]);
$agents = $agStmt->fetchAll(PDO::FETCH_ASSOC);

// 3) Sélection du mois et de la semaine
$moisId    = (int)($_GET['mois_id'] ?? 0);
$weekIndex = max(0, (int)($_GET['week']  ?? 0));

// 4) POST de validation/refus
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['souhait_id'], $_POST['action'])
    && $isChef
) {
    $nouveauStatut = $_POST['action'] === 'valider' ? 'validated' : 'refused';
    $upd = $pdo->prepare("UPDATE souhaits SET statut = ? WHERE id = ?");
    $upd->execute([$nouveauStatut, (int)$_POST['souhait_id']]);
    header("Location: chef_souhaits.php?mois_id=$moisId&week=$weekIndex");
    exit;
}

// 5) Préparer la grille si un mois est sélectionné
$weeks       = [];
$joursOuvres = 0;
$souhaits    = []; // souhaits[user_id][jour][]

if ($moisId > 0) {
    // a) mois & jours ouvrés
    $mstmt = $pdo->prepare(
        'SELECT mois, jours_ouvres_par_semaine
           FROM mois_ouvert
          WHERE id = ?'
    );
    $mstmt->execute([$moisId]);
    $md = $mstmt->fetch(PDO::FETCH_ASSOC);
    list($Y, $m) = explode('-', $md['mois']);
    $joursOuvres  = (int)$md['jours_ouvres_par_semaine'];
    $daysInMonth = (int)(new DateTimeImmutable("$Y-$m-01"))->format('t');

    // b) découpage en semaines
    $tmp = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dow       = (int)(new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d)))->format('N');
        $tmp[$dow] = $d;
        if ($dow === 7 || $d === $daysInMonth) {
            for ($i = 1; $i <= 7; $i++) {
                if (!isset($tmp[$i])) {
                    $tmp[$i] = null;
                }
            }
            ksort($tmp);
            $weeks[] = $tmp;
            $tmp     = [];
        }
    }
    if (!isset($weeks[$weekIndex])) {
        $weekIndex = 0;
    }
    $week = $weeks[$weekIndex];

    // c) charger tous les souhaits pour ce mois
    $sh = $pdo->prepare(
        'SELECT id, user_id, jour, code_id, statut
           FROM souhaits
          WHERE mois_ouvert_id = ?'
    );
    $sh->execute([$moisId]);
    foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $souhaits[$r['user_id']][$r['jour']][] = $r;
    }

    // d) charger tous les codes pour retrouver code + label
    $cd = $pdo->prepare('SELECT id, code, label FROM code');
    $cd->execute();
    foreach ($cd->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $allCodes[$c['id']] = [
            'code'  => $c['code'],
            'label' => $c['label'],
        ];
    }
}

// 6) Formatteurs FR
$fmtMonth   = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'MMMM yyyy');
$fmtWeekday = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'EEE');

$pageTitle = 'Validation des souhaits';
require __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>

    <!-- Sélecteur de mois -->
    <form method="get" class="form-container">
        <label for="mois_id">Mois ouvert</label>
        <select id="mois_id" name="mois_id" onchange="this.form.submit()">
            <option value="">–</option>
            <?php foreach ($moisList as $mth): ?>
                <option value="<?= $mth['id'] ?>" <?= $mth['id'] === $moisId ? 'selected' : '' ?>>
                    <?= ucfirst($fmtMonth->format(new DateTimeImmutable($mth['mois'] . '-01'))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($moisId > 0): ?>
        <!-- Navigation semaines -->
        <div class="week-nav">
            <?php if ($weekIndex > 0): ?>
                <a href="?mois_id=<?= $moisId ?>&week=<?= $weekIndex - 1 ?>" class="btn btn-sm btn-primary">
                    &larr; Sem. préc.
                </a>
            <?php endif; ?>
            <strong>
                <?php
                $start = reset($week);
                $end   = end($week);
                printf(
                    "Sem. du %02d au %02d %s",
                    $start,
                    $end,
                    ucfirst($fmtMonth->format(new DateTimeImmutable("$Y-$m-01")))
                );
                ?>
            </strong>
            <?php if (isset($weeks[$weekIndex + 1])): ?>
                <a href="?mois_id=<?= $moisId ?>&week=<?= $weekIndex + 1 ?>" class="btn btn-sm btn-primary">
                    Sem. suiv. &rarr;
                </a>
            <?php endif; ?>
        </div>

        <!-- Grille des souhaits -->
        <div class="planning-container">
            <table class="planning-all">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <?php foreach ($week as $d): ?>
                            <th>
                                <?= $d ?: '' ?><br>
                                <?= $d ? ucfirst($fmtWeekday->format(new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d)))) : '' ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $ag):
                        $uid = $ag['id'];
                    ?>
                        <tr>
                            <td><?= htmlspecialchars("{$ag['prenom']} {$ag['nom']}", ENT_QUOTES) ?></td>
                            <?php foreach ($week as $d): ?>
                                <td>
                                    <?php
                                    if (!$d) continue;
                                    $dt = new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d));
                                    if ((int)$dt->format('N') > $joursOuvres) continue;

                                    $ents = $souhaits[$uid][$d] ?? [];
                                    $stat = $ents[0]['statut'] ?? null;
                                    $locked = in_array($stat, ['pending', 'validated', 'refused'], true);
                                    ?>
                                    <div class="day-cell <?= $locked ? 'submitted' : '' ?>" data-jour="<?= $d ?>">
                                        <?php if ($locked): ?>
                                            <?php foreach ($ents as $e):
                                                $cid       = (int)$e['code_id'];
                                                $codeText  = $allCodes[$cid]['code']  ?? '-';
                                                $labelText = $allCodes[$cid]['label'] ?? '';
                                            ?>
                                                <div class="submitted-code">
                                                    <span class="code-text"><?= htmlspecialchars($codeText,  ENT_QUOTES) ?></span>
                                                    <span class="label-text"><?= htmlspecialchars($labelText, ENT_QUOTES) ?></span>
                                                    <span class="status <?= $e['statut'] ?>">
                                                        <?= $e['statut'] === 'pending'
                                                            ? 'En attente'
                                                            : ($e['statut'] === 'validated' ? 'Validé' : 'Refusé') ?>
                                                    </span>
                                                    <?php if ($isChef && $e['statut'] === 'pending'): ?>
                                                        <form method="post" style="display:inline">
                                                            <input type="hidden" name="souhait_id" value="<?= $e['id'] ?>">
                                                            <button name="action" value="valider" class="btn btn-sm btn-success">
                                                                Valider
                                                            </button>
                                                        </form>
                                                        <form method="post" style="display:inline">
                                                            <input type="hidden" name="souhait_id" value="<?= $e['id'] ?>">
                                                            <button name="action" value="refuser" class="btn btn-sm btn-danger">
                                                                Refuser
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            &ndash;
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
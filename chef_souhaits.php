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

// Préinitialisation\ n$allCodes = [];

// 1) Charger les mois ouverts
$moisStmt = $pdo->prepare(
    'SELECT id, mois
       FROM mois_ouvert
      WHERE id_service = ? AND actif = 1
      ORDER BY mois'
);
$moisStmt->execute([$serviceId]);
$moisList = $moisStmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Charger les agents
$agStmt = $pdo->prepare(
    'SELECT id, prenom, nom
       FROM user
      WHERE id_service = ? AND role IN ("agent","chefdecote","admin")
      ORDER BY nom, prenom'
);
$agStmt->execute([$serviceId]);
$agents = $agStmt->fetchAll(PDO::FETCH_ASSOC);
// 2a) Calcul du solde d’heures supp pour chaque agent
$balances = [];
foreach ($agents as $ag) {
    $balStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(minutes), 0) FROM heures_supplementaires WHERE user_id = ?'
    );
    $balStmt->execute([$ag['id']]);
    $totMin = (int)$balStmt->fetchColumn();
    $balances[$ag['id']] = [
        'h' => intdiv($totMin, 60),
        'm' => $totMin % 60,
    ];
}

// 3) Sélection du mois et de la semaine
$moisId    = (int)($_GET['mois_id'] ?? 0);
$weekIndex = max(0, (int)($_GET['week'] ?? 0));

// 4) POST validation/refus ou Valider tout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isChef) {

    // 4a) Valider tous les souhaits d’un agent
    if (isset($_POST['validate_all_user'])) {
        $userToVal = (int)$_POST['validate_all_user'];

        // 1) Récupérer tous les souhaits pendants pour cet agent et ce mois
        $getPend = $pdo->prepare(
            "SELECT id FROM souhaits
             WHERE user_id = ?
               AND mois_ouvert_id = ?
               AND statut = 'pending'"
        );
        $getPend->execute([$userToVal, $moisId]);
        $pendings = $getPend->fetchAll(PDO::FETCH_COLUMN);

        // 2) Pour chaque souhait, appliquer la logique “valider”
        foreach ($pendings as $sid) {
            // a) passage du statut
            $upd = $pdo->prepare(
                "UPDATE souhaits SET statut = 'validated' WHERE id = ?"
            );
            $upd->execute([$sid]);

            // b) récupération et insertion des HS si besoin
            $r = $pdo->prepare(
                'SELECT user_id, code_id, jour FROM souhaits WHERE id = ?'
            );
            $r->execute([$sid]);
            $row = $r->fetch(PDO::FETCH_ASSOC);

            if (!empty($row['code_id'])) {
                $cst = $pdo->prepare(
                    'SELECT heures_supplementaires_inc FROM code WHERE id = ?'
                );
                $cst->execute([$row['code_id']]);
                $minutes = (int)$cst->fetchColumn();

                if ($minutes !== 0) {
                    // charger mois pour date
                    $mo = $pdo->prepare(
                        'SELECT mois FROM mois_ouvert WHERE id = ?'
                    );
                    $mo->execute([$moisId]);
                    $md = $mo->fetch(PDO::FETCH_ASSOC);

                    $dateOvertime = sprintf(
                        '%s-%02d 00:00:00',
                        $md['mois'],
                        $row['jour']
                    );

                    // anti-doublon HS
                    $chk = $pdo->prepare(
                        'SELECT COUNT(*) FROM heures_supplementaires
                         WHERE user_id = ? AND date_saisie = ? AND minutes = ?'
                    );
                    $chk->execute([
                        $row['user_id'],
                        $dateOvertime,
                        $minutes,
                    ]);

                    if ($chk->fetchColumn() == 0) {
                        $ins = $pdo->prepare(
                            'INSERT INTO heures_supplementaires
                              (user_id,date_saisie,minutes,created_at,updated_at)
                             VALUES (?, ?, ?, NOW(), NOW())'
                        );
                        $ins->execute([
                            $row['user_id'],
                            $dateOvertime,
                            $minutes,
                        ]);
                    }
                }
            }
        }

        header("Location: chef_souhaits.php?mois_id={$moisId}&week={$weekIndex}");
        exit;
    }

    // 4b) Validation/refus individuel
    if (isset($_POST['souhait_id'], $_POST['action'])) {
        $souhaitId     = (int)$_POST['souhait_id'];
        $nouveauStatut = $_POST['action'] === 'valider' ? 'validated' : 'refused';

        // mise à jour du statut
        $upd = $pdo->prepare('UPDATE souhaits SET statut = ? WHERE id = ?');
        $upd->execute([$nouveauStatut, $souhaitId]);

        // si validé, ajouter heures sup
        if ($nouveauStatut === 'validated') {
            // récupérer user_id, code_id et jour du souhait
            $stmt = $pdo->prepare(
                'SELECT user_id, code_id, jour 
                   FROM souhaits 
                  WHERE id = ?'
            );
            $stmt->execute([$souhaitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!empty($row['code_id'])) {
                // récupérer l’incrément d’heures sup
                $codeStmt = $pdo->prepare(
                    'SELECT heures_supplementaires_inc 
                       FROM code 
                      WHERE id = ?'
                );
                $codeStmt->execute([$row['code_id']]);
                $minutes = (int)$codeStmt->fetchColumn();

                if ($minutes !== 0) {
                    // charger $md['mois'] pour construire la date
                    $mo = $pdo->prepare(
                        'SELECT mois 
                           FROM mois_ouvert 
                          WHERE id = ?'
                    );
                    $mo->execute([$moisId]);
                    $md = $mo->fetch(PDO::FETCH_ASSOC);

                    // construire la date du jour d'heures sup
                    $dateOvertime = sprintf(
                        '%s-%02d 00:00:00',
                        $md['mois'],
                        $row['jour']
                    );

                    // anti-doublon : on n’insère pas si déjà présent
                    $chkHS = $pdo->prepare(
                        'SELECT COUNT(*) 
                           FROM heures_supplementaires
                          WHERE user_id    = ?
                            AND date_saisie = ?
                            AND minutes     = ?'
                    );
                    $chkHS->execute([
                        $row['user_id'],
                        $dateOvertime,
                        $minutes,
                    ]);

                    if ($chkHS->fetchColumn() == 0) {
                        $insHS = $pdo->prepare(
                            'INSERT INTO heures_supplementaires
                                (user_id, date_saisie, minutes, created_at, updated_at)
                             VALUES (?, ?, ?, NOW(), NOW())'
                        );
                        $insHS->execute([
                            $row['user_id'],
                            $dateOvertime,
                            $minutes,
                        ]);
                    }
                }
            }
        }

        header("Location: chef_souhaits.php?mois_id={$moisId}&week={$weekIndex}");
        exit;
    }
}



// 5) Préparer la grille
$weeks       = [];
$joursOuvres = 0;
$souhaits    = [];
if ($moisId > 0) {
    // a) mois & jours ouvrés
    $mstmt = $pdo->prepare(
        'SELECT mois, jours_ouvres_par_semaine FROM mois_ouvert WHERE id = ?'
    );
    $mstmt->execute([$moisId]);
    $md = $mstmt->fetch(PDO::FETCH_ASSOC);
    list($Y, $m)           = explode('-', $md['mois']);
    $joursOuvres          = (int)$md['jours_ouvres_par_semaine'];
    $daysInMonth          = (int)(new DateTimeImmutable("$Y-$m-01"))->format('t');

    // b) découpage en semaines
    $tmp = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dow       = (int)(new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d)))->format('N');
        $tmp[$dow] = $d;
        if ($dow === 7 || $d === $daysInMonth) {
            for ($i = 1; $i <= 7; $i++) {
                if (!isset($tmp[$i])) $tmp[$i] = null;
            }
            ksort($tmp);
            $weeks[] = $tmp;
            $tmp     = [];
        }
    }
    if (!isset($weeks[$weekIndex])) $weekIndex = 0;
    $week = $weeks[$weekIndex];

    // c) charger souhaits
    $sh = $pdo->prepare(
        'SELECT id, user_id, jour, code_id, statut FROM souhaits WHERE mois_ouvert_id = ?'
    );
    $sh->execute([$moisId]);
    foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $souhaits[$r['user_id']][$r['jour']][] = $r;
    }

    // d) charger codes
    $cd = $pdo->prepare('SELECT id, code, label FROM code');
    $cd->execute();
    foreach ($cd->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $allCodes[$c['id']] = ['code' => $c['code'], 'label' => $c['label']];
    }
}

// 6) Formatteurs FR
$fmtMonth   = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN,
    'MMMM yyyy'
);
$fmtWeekday = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN,
    'EEE'
);

$pageTitle = 'Validation des souhaits';
require __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h2>
    <!-- Sélecteur de mois -->
    <form method="get" class="form-container">
        <label for="mois_id">Mois ouvert</label>
        <select id="mois_id" name="mois_id" onchange="this.form.submit()">
            <option value="">–</option>
            <?php foreach ($moisList as $mth): ?>
                <option value="<?= $mth['id'] ?>" <?= $mth['id'] === $moisId ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($fmtMonth->format(new DateTimeImmutable($mth['mois'] . '-01'))), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($moisId > 0): ?>
        <div class="week-nav">
            <?php if ($weekIndex > 0): ?>
                <a href="?mois_id=<?= $moisId ?>&week=<?= $weekIndex - 1 ?>" class="btn btn-sm btn-primary">&larr; Sem. préc.</a>
            <?php endif; ?>
            <strong><?php $s = reset($week);
                    $e = end($week);
                    printf("Sem. du %02d au %02d %s", $s, $e, htmlspecialchars(ucfirst($fmtMonth->format(new DateTimeImmutable("$Y-$m-01"))), ENT_QUOTES)); ?></strong>
            <?php if (isset($weeks[$weekIndex + 1])): ?>
                <a href="?mois_id=<?= $moisId ?>&week=<?= $weekIndex + 1 ?>" class="btn btn-sm btn-primary">Sem. suiv. &rarr;</a>
            <?php endif; ?>
        </div>

        <div class="planning-container">
            <table class="planning-all">
                <thead>
                    <tr>
                        <th>Agent</th><?php foreach ($week as $d): ?><th><?= $d ?: '' ?><br><?= $d ? htmlspecialchars(ucfirst($fmtWeekday->format(new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d)))), ENT_QUOTES) : '' ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $ag): ?>
                        <tr>
                            <!-- Colonne Nom / Prénom + bouton Valider tout -->
                            <td>
                                <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <!-- Nom de l'agent -->
                                        <span><?= htmlspecialchars("{$ag['prenom']} {$ag['nom']}", ENT_QUOTES) ?></span>

                                        <!-- Bouton Valider tout -->
                                        <?php if ($isChef): ?>
                                            <form method="post" style="margin: 0;">
                                                <input type="hidden" name="validate_all_user" value="<?= $ag['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Valider tout</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Solde des heures supplémentaires -->
                                    <div style="font-size: 0.85em; color: #555;">
                                        Solde HS : <?= $balances[$ag['id']]['h'] ?>h <?= sprintf('%02d', $balances[$ag['id']]['m']) ?>m
                                    </div>
                                </div>
                            </td>

                            <!-- Colonnes des jours de la semaine -->
                            <?php foreach ($week as $d): ?>
                                <td>
                                    <?php
                                    if (!$d) {
                                        continue;
                                    }
                                    $dt  = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $Y, $m, $d));
                                    $dow = (int)$dt->format('N');
                                    if ($dow > $joursOuvres) {
                                        continue;
                                    }

                                    $ents   = $souhaits[$ag['id']][$d] ?? [];
                                    $stat   = $ents[0]['statut'] ?? null;
                                    $locked = in_array($stat, ['pending', 'validated', 'refused'], true);

                                    if ($locked) {
                                        foreach ($ents as $e) {
                                            echo '<div class="submitted-code">';
                                            echo '<span class="code-text">'
                                                . htmlspecialchars($allCodes[$e['code_id']]['code'] ?? '-', ENT_QUOTES)
                                                . '</span>';
                                            echo '<span class="status ' . $e['statut'] . '">'
                                                . ($e['statut'] === 'pending'   ? 'En attente'
                                                    : ($e['statut'] === 'validated' ? 'Validé'
                                                        : 'Refusé'))
                                                . '</span>';

                                            if ($isChef && $e['statut'] === 'pending') {
                                                // bouton Valider
                                                echo '<form method="post" style="display:inline">'
                                                    . '<input type="hidden" name="souhait_id" value="' . $e['id'] . '">'
                                                    . '<button name="action" value="valider" class="btn btn-sm btn-success">Valider</button>'
                                                    . '</form>';

                                                // bouton Refuser
                                                echo '<form method="post" style="display:inline">'
                                                    . '<input type="hidden" name="souhait_id" value="' . $e['id'] . '">'
                                                    . '<button name="action" value="refuser" class="btn btn-sm btn-danger">Refuser</button>'
                                                    . '</form>';
                                            }

                                            echo '</div>';
                                        }
                                    } else {
                                        echo '&ndash;';
                                    }
                                    ?>
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
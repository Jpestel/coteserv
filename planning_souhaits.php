<?php
// planning_souhaits.php
session_start();
require_once __DIR__ . '/config/db.php';

// Messages pour l’affichage d’erreurs et de succès
$errors  = [];
$success = '';


// 0) Droits
if (
    !isset($_SESSION['user_id'])
    || !in_array($_SESSION['user_role'], ['agent', 'chefdecote', 'admin'], true)
) {
    header('Location: login.php');
    exit;
}
$userId    = $_SESSION['user_id'];
$serviceId = $_SESSION['user_service_id'];

// 1) Charger les mois ouverts
$moisStmt = $pdo->prepare(
    "SELECT id, mois
     FROM mois_ouvert
    WHERE id_service = ? AND actif = 1
    ORDER BY mois"
);
$moisStmt->execute([$serviceId]);
$moisList = $moisStmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Charger agents
$agStmt = $pdo->prepare(
    "SELECT id, nom, prenom
     FROM user
    WHERE id_service = ? AND role IN ('agent','chefdecote','admin')
    ORDER BY nom, prenom"
);
$agStmt->execute([$serviceId]);
$agents = $agStmt->fetchAll(PDO::FETCH_ASSOC);

// 2a) Mettre l'agent connecté en première position
usort($agents, function ($a, $b) use ($userId) {
    if ($a['id'] === $userId) return -1;
    if ($b['id'] === $userId) return 1;
    return strcmp(
        $a['nom'] . ' ' . $a['prenom'],
        $b['nom'] . ' ' . $b['prenom']
    );
});

// 3) Charger catégories et codes
$catStmt = $pdo->prepare(
    "SELECT id, name
     FROM code_category
    WHERE service_id = ?
    ORDER BY name"
);
$catStmt->execute([$serviceId]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$codeStmt = $pdo->prepare(
    "SELECT c.id, c.code, c.category_id
     FROM code c
     JOIN code_category cat ON c.category_id = cat.id
    WHERE cat.service_id = ?
    ORDER BY cat.name, c.code"
);
$codeStmt->execute([$serviceId]);
$allCodes = $codeStmt->fetchAll(PDO::FETCH_ASSOC);

$codesByCat = [];
foreach ($allCodes as $c) {
    $codesByCat[$c['category_id']][] = $c;
}

// 4) Sélection du mois et semaine
$selectedMois = (int)($_GET['mois_id'] ?? 0);
$weekIndex    = max(0, (int)($_GET['week'] ?? 0));
// --- Charger le mois ouvert pour avoir $md['mois'] en POST ---
if ($selectedMois) {
    $mo2 = $pdo->prepare(
        "SELECT mois
           FROM mois_ouvert
          WHERE id = ?"
    );
    $mo2->execute([$selectedMois]);
    $md = $mo2->fetch(PDO::FETCH_ASSOC);
}

// 4a) Calcul du solde d'heures supplémentaires de l'agent connecté
$hsStmt = $pdo->prepare("
    SELECT COALESCE(SUM(minutes), 0) AS total_min
      FROM heures_supplementaires
     WHERE user_id = ?
");
$hsStmt->execute([$userId]);
$totalMin = (int)$hsStmt->fetchColumn();

// Conversion en heures et minutes
$hsHours = intdiv($totalMin, 60);
$hsMins  = $totalMin % 60;


// 5) Traitement POST (modifier ou soumettre)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedMois) {
    if (isset($_POST['modify_jour'])) {
        $d = (int)$_POST['modify_jour'];

        // --- 1) Récupérer les anciens souhaits VALIDÉS pour ce jour ---
        $oldStmt = $pdo->prepare("
            SELECT s.code_id, c.heures_supplementaires_inc
            FROM souhaits s
            JOIN code c ON s.code_id = c.id
            WHERE s.user_id = ?
              AND s.mois_ouvert_id = ?
              AND s.jour = ?
              AND s.statut = 'validated'
        ");
        $oldStmt->execute([$userId, $selectedMois, $d]);
        $oldRows = $oldStmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 2) Construire la date (YYYY-MM-DD) pour supprimer HS ---
        // on suppose $md['mois'] déjà chargé en "YYYY-MM"
        $dateStr = sprintf('%s-%02d', $md['mois'], $d);

        // --- 3) Supprimer les heures_supplementaires associées ---
        $delHS = $pdo->prepare("
            DELETE FROM heures_supplementaires
            WHERE user_id = ?
              AND DATE(date_saisie) = ?
              AND minutes = ?
        ");
        foreach ($oldRows as $old) {
            $mins = (int)$old['heures_supplementaires_inc'];
            if ($mins !== 0) {
                $delHS->execute([$userId, $dateStr, $mins]);
            }
        }

        // --- 4) Supprimer les anciens souhaits ---
        $pdo->prepare("
            DELETE FROM souhaits
            WHERE user_id = ?
              AND mois_ouvert_id = ?
              AND jour = ?
        ")->execute([$userId, $selectedMois, $d]);
    } else {
        if (isset($_POST['submit_jour'])) {
            $jours = [(int)$_POST['submit_jour']];
        } else {
            $jours = array_keys($_POST['codes'] ?? []);
        }
        foreach ($jours as $d) {
            $pdo->prepare(
                "DELETE FROM souhaits
                 WHERE user_id = ?
                   AND mois_ouvert_id = ?
                   AND jour = ?"
            )->execute([$userId, $selectedMois, $d]);
            $codes = array_unique(array_filter($_POST['codes'][$d] ?? [], fn($c) => $c !== ''));
            $ins = $pdo->prepare(
                "INSERT INTO souhaits
                (user_id, mois_ouvert_id, jour, code_id, statut)
              VALUES (?, ?, ?, ?, 'pending')"
            );
            foreach ($codes as $cid) {
                $ins->execute([$userId, $selectedMois, $d, (int)$cid]);
            }
        }
        $success = 'Vos souhaits ont été enregistrés en attente de validation.';
    }
}

// 6) Charger la grille si mois sélectionné
$weeks = $joursOuvres = $souhaits = [];
if ($selectedMois) {
    $mo = $pdo->prepare(
        "SELECT mois, jours_ouvres_par_semaine
         FROM mois_ouvert
        WHERE id = ?"
    );
    $mo->execute([$selectedMois]);
    $md = $mo->fetch(PDO::FETCH_ASSOC);
    list($Y, $m) = explode('-', $md['mois']);
    $joursOuvres = (int)$md['jours_ouvres_par_semaine'];
    $daysInMonth = (int)(new DateTimeImmutable("$Y-$m-01"))->format('t');

    $eventsByDay = [];
    $evtStmt = $pdo->prepare(
        "SELECT `date`, titre
         FROM evenements
        WHERE service_id = ?
          AND YEAR(`date`)  = ?
          AND MONTH(`date`) = ?"
    );
    $evtStmt->execute([$serviceId, $Y, $m]);
    foreach ($evtStmt->fetchAll(PDO::FETCH_ASSOC) as $evt) {
        $day = (int)(new DateTimeImmutable($evt['date']))->format('j');
        $eventsByDay[$day][] = $evt['titre'];
    }
    // Charger jours fériés par défaut
    $defStmt = $pdo->prepare("
        SELECT jour
        FROM jours_feries_defaut
        WHERE mois = ?
    ");
    $defStmt->execute([(int)$m]);
    $feriesDef = $defStmt->fetchAll(PDO::FETCH_COLUMN);

    // Charger overrides
    $ovStmt = $pdo->prepare("
        SELECT date, is_ferie
        FROM jours_feries_override
        WHERE mois_ouvert_id = ?
    ");
    $ovStmt->execute([$selectedMois]);
    $overrides = $ovStmt->fetchAll(PDO::FETCH_ASSOC);

    // Construire un index des dates fériées (format YYYY-MM-DD)
    $feries = [];
    foreach ($feriesDef as $jour) {
        $feries[sprintf('%s-%02d', $md['mois'], $jour)] = true;
    }
    foreach ($overrides as $ov) {
        if ($ov['is_ferie']) {
            $feries[$ov['date']] = true;
        } else {
            unset($feries[$ov['date']]);
        }
    }
    $tmp = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dow = (int)(new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d)))->format('N');
        $tmp[$dow] = $d;
        if ($dow === 7 || $d === $daysInMonth) {
            for ($i = 1; $i <= 7; $i++) if (!isset($tmp[$i])) $tmp[$i] = null;
            ksort($tmp);
            $weeks[] = $tmp;
            $tmp = [];
        }
    }
    if (!isset($weeks[$weekIndex])) $weekIndex = 0;
    $week = $weeks[$weekIndex];

    $sh = $pdo->prepare(
        "SELECT user_id, jour, code_id, statut
         FROM souhaits
        WHERE mois_ouvert_id = ?"
    );
    $sh->execute([$selectedMois]);
    foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $souhaits[$r['user_id']][$r['jour']][] = $r;
    }
}

// 7) Formatters FR
$monthF  = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'MMMM yyyy');
$wkdayF  = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'EEE');

$pageTitle = 'Planning des souhaits';
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
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- Sélecteur de mois et solde heures supp -->
    <div class="controls" style="display: flex; align-items: center;">

        <!-- Sélecteur de mois -->
        <form method="get" class="form-container">
            <label for="mois_id">Choisir un mois</label><br>
            <select id="mois_id" name="mois_id" onchange="this.form.submit()">
                <option value="">–</option>
                <?php foreach ($moisList as $mth): ?>
                    <option value="<?= $mth['id'] ?>"
                        <?= $mth['id'] == $selectedMois ? ' selected' : '' ?>>
                        <?= ucfirst($monthF->format(new DateTimeImmutable($mth['mois'] . '-01'))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <!-- Solde des heures supplémentaires -->
        <div
            class="overtime-balance"
            style="
            margin-left: auto;
            margin-right: 100px;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        ">
            <strong>Solde heures sup' :</strong><br>
            <?= $hsHours ?>h <?= sprintf('%02d', $hsMins) ?>m
        </div>

    </div>



    <?php if ($selectedMois): ?>
        <!-- Navigation semaine -->
        <div class="week-nav">
            <?php if ($weekIndex > 0): ?><a href="?mois_id=<?= $selectedMois ?>&week=<?= $weekIndex - 1 ?>" class="btn btn-sm btn-primary">&larr; Précédente</a><?php endif; ?>
            <strong><?php $s = reset($week);
                    $e = end($week);
                    echo sprintf("Semaine du %02d au %02d %s", $s, $e, ucfirst($monthF->format(new DateTimeImmutable("$Y-$m-01")))); ?></strong>
            <?php if (isset($weeks[$weekIndex + 1])): ?><a href="?mois_id=<?= $selectedMois ?>&week=<?= $weekIndex + 1 ?>" class="btn btn-sm btn-primary">Suivante &rarr;</a><?php endif; ?>
        </div>

        <!-- Filtre catégories -->
        <div class="category-filter">
            <button type="button"
                id="clear-filters"
                class="btn btn-sm btn-secondary"
                style="margin-right:8px;">
                Tout décocher
            </button>
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="cat-btn active" data-cat="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Template select -->
        <template id="tpl-code-select">
            <select class="code-select">
                <option value="">-</option><?php foreach ($categories as $cat): foreach ($codesByCat[$cat['id']] ?? [] as $c): ?><option value="<?= $c['id'] ?>" data-cat="<?= $cat['id'] ?>"><?= htmlspecialchars($c['code'], ENT_QUOTES) ?></option><?php endforeach;
                                                                                                                                                                                                                                                endforeach; ?>
            </select>
        </template>

        <!-- Grille -->
        <div class="planning-wrapper">
            <form method="post" class="grid-form">
                <button type="submit" class="btn btn-success mb-2">Soumettre mes souhaits</button>
                <table class="planning-all">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <?php foreach ($week as $d): ?>
                                <?php if ($d): ?>
                                    <?php $dateStr = sprintf('%s-%02d', $md['mois'], $d); ?>
                                    <th>
                                        <?php if (isset($feries[$dateStr])): ?>
                                            <ul class="evt-list">
                                                <li class="evt-item"><strong>FERIÉ</strong></li>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if (!empty($eventsByDay[$d])): ?>
                                            <ul class="evt-list">
                                                <?php foreach ($eventsByDay[$d] as $t): ?>
                                                    <li class="evt-item"><?= htmlspecialchars($t, ENT_QUOTES) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?= $d ?><br>
                                        <?= ucfirst($wkdayF->format(new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d)))) ?>
                                    </th>
                                <?php else: ?>
                                    <th></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($agents as $ag): $uid = $ag['id']; ?>
                            <tr>
                                <td><?= htmlspecialchars($ag['prenom'] . ' ' . $ag['nom'], ENT_QUOTES) ?></td>
                                <?php foreach ($week as $d): ?>
                                    <td>
                                        <?php
                                        if (!$d) continue;
                                        $dt = new DateTimeImmutable("$Y-$m-" . sprintf('%02d', $d));
                                        if ((int)$dt->format('N') > $joursOuvres) continue;
                                        // Construire la chaîne de date pour tester le jour férié
                                        $dateStr = sprintf('%s-%02d', $md['mois'], $d);

                                        // Récupérer les souhaits existants
                                        $ents   = $souhaits[$uid][$d] ?? [];
                                        $stat   = $ents[0]['statut'] ?? null;
                                        $locked = in_array($stat, ['pending', 'validated', 'refused'], true);
                                        ?>
                                        <div class="day-cell <?= $locked ? 'submitted' : '' ?>" data-jour="<?= $d ?>">

                                            <?php if (isset($feries[$dateStr])): ?>
                                                <!-- Jour férié : même style qu'un événement -->
                                                <ul class="evt-list">
                                                    <li class="evt-item"><strong>FERIÉ</strong></li>
                                                </ul>

                                            <?php elseif ($locked): ?>
                                                <!-- Codes déjà soumis -->
                                                <?php foreach ($ents as $e):
                                                    $lbl = '-';
                                                    foreach ($allCodes as $c) {
                                                        if ($c['id'] == $e['code_id']) {
                                                            $lbl = $c['code'];
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <div class="submitted-code">
                                                        <span class="code-text"><?= htmlspecialchars($lbl, ENT_QUOTES) ?></span>
                                                        <span class="status <?= $e['statut'] ?>">
                                                            <?= $e['statut'] === 'pending'
                                                                ? 'En attente'
                                                                : ($e['statut'] === 'validated' ? 'Validé' : 'Refusé') ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($uid === $userId): ?>
                                                    <button type="submit"
                                                        name="modify_jour"
                                                        value="<?= $d ?>"
                                                        class="btn btn-sm btn-warning">
                                                        Modifier
                                                    </button>
                                                <?php endif; ?>

                                            <?php else: ?>
                                                <!-- Cellule de saisie pour l'agent -->
                                                <?php if ($uid === $userId): ?>
                                                    <?php foreach ($souhaits[$uid][$d] ?? [''] as $cid): ?>
                                                        <select name="codes[<?= $d ?>][]" class="code-select">
                                                            <option value="">-</option>
                                                            <?php foreach ($categories as $cat): ?>
                                                                <?php foreach ($codesByCat[$cat['id']] ?? [] as $c): ?>
                                                                    <option value="<?= $c['id'] ?>"
                                                                        data-cat="<?= $cat['id'] ?>"
                                                                        <?= ($c['id'] == $cid) ? ' selected' : '' ?>>
                                                                        <?= htmlspecialchars($c['code'], ENT_QUOTES) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endforeach; ?>
                                                    <button type="button" class="add-code-btn">+</button>
                                                    <button type="submit"
                                                        name="submit_jour"
                                                        value="<?= $d ?>"
                                                        class="btn-submit-cell btn btn-sm btn-success">
                                                        Soumettre
                                                    </button>
                                                    <?php else: ?>Côte de service non saisie<?php endif; ?>

                                                <?php endif; ?>

                                        </div>
                                    </td>
                                <?php endforeach; ?>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-success mt-2">Soumettre mes souhaits</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const activeCats = new Set(<?= json_encode(array_column($categories, 'id'), JSON_NUMERIC_CHECK) ?>);
        const tpl = document.getElementById('tpl-code-select').content.querySelector('select.code-select');

        function filter() {
            document.querySelectorAll('select.code-select').forEach(sel => {
                const cell = sel.closest('.day-cell');
                if (cell.classList.contains('submitted')) return;
                sel.querySelectorAll('option[data-cat]').forEach(opt => opt.style.display = activeCats.has(+opt.dataset.cat) ? '' : 'none');
                if (sel.value) {
                    const cat = +sel.selectedOptions[0].dataset.cat;
                    if (!activeCats.has(cat)) sel.value = '';
                }
            });
        }

        document.body.addEventListener('click', e => {
            // 1) Tout décocher
            if (e.target.matches('#clear-filters')) {
                activeCats.clear();
                document.querySelectorAll('.cat-btn').forEach(btn =>
                    btn.classList.remove('active')
                );
                return filter();
            }

            // 2) Sélection/dé-sélection individualle
            if (e.target.matches('.cat-btn')) {
                const id = +e.target.dataset.cat;
                if (e.target.classList.toggle('active')) {
                    activeCats.add(id);
                } else {
                    activeCats.delete(id);
                }
                return filter();
            }

            // 3) Ton handler pour add-code-btn reste inchangé
            if (e.target.matches('.add-code-btn')) {
                const cell = e.target.closest('.day-cell');
                if (cell.classList.contains('submitted')) return;
                const clone = tpl.cloneNode(true);
                clone.name = `codes[${cell.dataset.jour}][]`;
                cell.insertBefore(clone, e.target);
                return filter();
            }
        });

        filter();
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
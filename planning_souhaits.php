<?php
// planning_souhaits.php
session_start();
require_once __DIR__ . '/config/db.php';

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

// 1) Mois ouverts
$moisStmt = $pdo->prepare("
  SELECT id, mois
    FROM mois_ouvert
   WHERE id_service = ? AND actif = 1
   ORDER BY mois
");
$moisStmt->execute([$serviceId]);
$moisList = $moisStmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Agents du service
$agStmt = $pdo->prepare("
  SELECT id, nom, prenom
    FROM user
   WHERE id_service = ? AND role IN ('agent','chefdecote','admin')
   ORDER BY nom, prenom
");
$agStmt->execute([$serviceId]);
$agents = $agStmt->fetchAll(PDO::FETCH_ASSOC);

// 3) Catégories & codes pour <select>
$catStmt = $pdo->prepare("
  SELECT id, name
    FROM code_category
   WHERE service_id = ?
   ORDER BY name
");
$catStmt->execute([$serviceId]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$codeStmt = $pdo->prepare("
  SELECT c.id, c.code, c.category_id
    FROM code c
    JOIN code_category cat ON c.category_id = cat.id
   WHERE cat.service_id = ?
   ORDER BY cat.name, c.code
");
$codeStmt->execute([$serviceId]);
$allCodes = $codeStmt->fetchAll(PDO::FETCH_ASSOC);

$codesByCat = [];
foreach ($allCodes as $c) {
    $codesByCat[$c['category_id']][] = $c;
}

// 4) Sélection du mois et de la semaine
$selectedMois = (int)($_GET['mois_id'] ?? 0);
$weekIndex    = max(0, (int)($_GET['week'] ?? 0));

// 5) POST : enregistrement
$success = null;
$errors  = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedMois) {
    // récupérer année/mois
    $mStmt = $pdo->prepare("SELECT mois FROM mois_ouvert WHERE id=?");
    $mStmt->execute([$selectedMois]);
    list($Y, $m) = explode('-', $mStmt->fetchColumn());
    // on boucle seulement sur jours où l'agent a soumis
    foreach (array_keys($_POST['codes'] ?? []) as $d) {
        $d = (int)$d;
        $codes = array_unique(array_filter($_POST['codes'][$d], fn($c) => $c !== ''));
        // supprimer anciens pending
        $pdo->prepare("
          DELETE FROM souhaits
           WHERE user_id=? AND mois_ouvert_id=? AND jour=? AND statut='pending'
        ")->execute([$userId, $selectedMois, $d]);
        // réinsérer
        $ins = $pdo->prepare("
          INSERT INTO souhaits(user_id,mois_ouvert_id,jour,code_id,statut)
          VALUES(?,?,?,?, 'pending')
        ");
        foreach ($codes as $cid) {
            $ins->execute([$userId, $selectedMois, $d, (int)$cid]);
        }
    }
    $success = 'Vos souhaits ont été enregistrés en attente de validation.';
}

// 6) Préparer la grille si mois
$weeks = [];
$joursOuvres = 0;
$souhaits    = [];
if ($selectedMois) {
    // charger mois
    $mo = $pdo->prepare("
      SELECT mois, jours_ouvres_par_semaine
        FROM mois_ouvert WHERE id=?
    ");
    $mo->execute([$selectedMois]);
    $md = $mo->fetch(PDO::FETCH_ASSOC);
    list($Y, $m) = explode('-', $md['mois']);
    $joursOuvres = (int)$md['jours_ouvres_par_semaine'];
    $daysInMonth = (int)(new DateTimeImmutable("$Y-$m-01"))->format('t');

    // construire semaines
    $tmp = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dt = new DateTimeImmutable("$Y-$m-" . sprintf("%02d", $d));
        $dow = (int)$dt->format('N');
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

    // charger tous souhaits pour ce mois
    $sh = $pdo->prepare("
      SELECT user_id,jour,code_id,statut
        FROM souhaits
       WHERE mois_ouvert_id=?
    ");
    $sh->execute([$selectedMois]);
    foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $souhaits[$r['user_id']][$r['jour']][] = $r;
    }
}

// 7) Formatters FR
$monthF = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'MMMM yyyy');
$wkdayF = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris', IntlDateFormatter::GREGORIAN, 'EEE');

$pageTitle = 'Planning des souhaits';
require __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h2>
    <div class="instructions">
        1/ Sélectionner un mois ouvert</br>
        2/ Filtrer la ou les catégories de codes à conserver </br>
        3/ Sélectionner le ou les codes à soumettre sur un ou plusieurs jours (cliquer sur "+" pour soumettre plusieurs codes pour un même jour) </br>
        4/ Cliquer sur "Valider" avant de changer de filtre de catégories.</br>
        5/ Cliquer sur "Soumettre mes souhaits" quand vous avez terminé les saisies d'une semaine ou à tout moment.
    </div>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <!-- 1) Sélecteur de mois -->
    <form method="get" class="form-container">
        <label for="mois_id">1/ Sélectionner un mois ouvert</label>
        <select id="mois_id" name="mois_id" onchange="this.form.submit()">
            <option value="">–</option>
            <?php foreach ($moisList as $mth):
                $lbl = ucfirst($monthF->format(new DateTimeImmutable($mth['mois'] . '-01')));
            ?>
                <option value="<?= $mth['id'] ?>" <?= $mth['id'] == $selectedMois ? 'selected' : '' ?>>
                    <?= htmlspecialchars($lbl, ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($selectedMois): ?>
        <!-- Navigation semaine -->
        <div class="week-nav mb-3">
            <?php if ($weekIndex > 0): ?>
                <a href="?mois_id=<?= $selectedMois ?>&week=<?= $weekIndex - 1 ?>"
                    class="btn btn-sm btn-primary">&larr; Sem. préc.</a>
            <?php endif; ?>
            <strong>
                <?php
                $s = reset($week);
                $e = end($week);
                printf(
                    "Sem. du %02d au %02d %s",
                    $s,
                    $e,
                    ucfirst($monthF->format(new DateTimeImmutable("$Y-$m-01")))
                );
                ?>
            </strong>
            <?php if (isset($weeks[$weekIndex + 1])): ?>
                <a href="?mois_id=<?= $selectedMois ?>&week=<?= $weekIndex + 1 ?>"
                    class="btn btn-sm btn-primary">Sem. suiv. &rarr;</a>
            <?php endif; ?>

        </div>

        <!-- Filtre catégories -->
        <h4>2/ Filtrer par catégorie de codes</h4>
        <div class="category-filter mb-3">

            <?php foreach ($categories as $cat): ?>

                <button type="button" class="cat-btn active" data-cat="<?= $cat['id'] ?>">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Template select -->
        <template id="tpl-code-select">
            <select class="code-select">
                <option value="">-</option>
                <?php foreach ($categories as $cat): ?>
                    <?php foreach ($codesByCat[$cat['id']] ?? [] as $c): ?>
                        <option value="<?= $c['id'] ?>" data-cat="<?= $cat['id'] ?>">
                            <?= htmlspecialchars($c['code'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </template>

        <!-- Grille -->
        <div class="planning-wrapper">

            <form method="post" class="grid-form">
                <button
                    type="submit"
                    class="btn btn-success">Soumettre mes souhaits</button>
                <table class="planning-all">
                    <thead>
                        <tr>
                            <h4>3/ Sélectionner le ou les codes dans chaque case jour ouvré</h4>

                            <th>Agent</th>
                            <?php foreach ($week as $d): ?>
                                <th>
                                    <?= $d ?: '' ?><br>
                                    <?= $d
                                        ? ucfirst($wkdayF->format(
                                            new DateTimeImmutable(
                                                sprintf("%04d-%02d-%02d", $Y, $m, $d)
                                            )
                                        ))
                                        : ''
                                    ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agents as $ag):
                            $uid = $ag['id'];
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($ag['prenom'] . ' ' . $ag['nom'], ENT_QUOTES) ?></td>
                                <?php foreach ($week as $d): ?>
                                    <td>
                                        <?php
                                        if (!$d) continue;
                                        $dt  = new DateTimeImmutable(sprintf("%04d-%02d-%02d", $Y, $m, $d));
                                        if ((int)$dt->format('N') > $joursOuvres) continue;
                                        $ents   = $souhaits[$uid][$d] ?? [];
                                        $stat   = $ents[0]['statut'] ?? null;
                                        $locked = in_array($stat, ['pending', 'validated', 'refused'], true);
                                        ?>
                                        <div
                                            class="day-cell <?= $locked ? 'submitted' : '' ?>"
                                            data-jour="<?= $d ?>">
                                            <?php if ($locked): ?>
                                                <?php foreach ($ents as $e):
                                                    // n’affiche que le code
                                                    $lbl = '-';
                                                    foreach ($allCodes as $c) {
                                                        if ($c['id'] == $e['code_id']) {
                                                            $lbl = $c['code'];
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <div class="submitted-code">
                                                        <span class="code-text">
                                                            <?= htmlspecialchars($lbl, ENT_QUOTES) ?>
                                                        </span>
                                                        <span class="status <?= $e['statut'] ?>">
                                                            <?= $e['statut'] === 'pending'
                                                                ? 'En attente'
                                                                : ($e['statut'] === 'validated'
                                                                    ? 'Validé'
                                                                    : 'Refusé'
                                                                )
                                                            ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($uid === $userId): ?>
                                                    <button
                                                        type="button"
                                                        class="modify-btn">Modifier</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($uid === $userId):
                                                    $exist = array_map(fn($r) => $r['code_id'], $ents) ?: [''];
                                                    foreach ($exist as $cid): ?>
                                                        <select
                                                            name="codes[<?= $d ?>][]"
                                                            class="code-select">
                                                            <option value="">-</option>
                                                            <?php foreach ($categories as $cat): ?>
                                                                <?php foreach ($codesByCat[$cat['id']] ?? [] as $c):
                                                                    $sel = $c['id'] == $cid ? 'selected' : '';
                                                                ?>
                                                                    <option
                                                                        value="<?= $c['id'] ?>"
                                                                        data-cat="<?= $cat['id'] ?>"
                                                                        <?= $sel ?>>
                                                                        <?= htmlspecialchars($c['code'], ENT_QUOTES) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php endforeach; ?>
                                                    <button
                                                        type="button"
                                                        class="add-code-btn">+</button>
                                                    <button
                                                        type="button"
                                                        class="validate-btn">Valider</button>
                                                <?php else: ?>
                                                    &ndash;
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <button
                    type="submit"
                    class="btn btn-success">Soumettre mes souhaits</button>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const activeCats = new Set(<?= json_encode(array_column($categories, 'id'), JSON_NUMERIC_CHECK) ?>);
        const tpl = document.getElementById('tpl-code-select').content.querySelector('select.code-select');

        function filterCodeSelects() {
            document.querySelectorAll('select.code-select').forEach(sel => {
                const cell = sel.closest('.day-cell');
                if (cell.classList.contains('submitted')) return;
                sel.querySelectorAll('option[data-cat]').forEach(opt => {
                    opt.style.display = activeCats.has(+opt.dataset.cat) ? '' : 'none';
                });
                if (sel.value) {
                    const c = +sel.selectedOptions[0].dataset.cat;
                    if (!activeCats.has(c)) sel.value = '';
                }
            });
        }

        document.body.addEventListener('click', e => {
            if (e.target.matches('.cat-btn')) {
                const id = +e.target.dataset.cat;
                e.target.classList.toggle('active') ? activeCats.add(id) : activeCats.delete(id);
                return filterCodeSelects();
            }
            if (e.target.matches('.add-code-btn')) {
                const cell = e.target.closest('.day-cell');
                if (cell.classList.contains('submitted')) return;
                const clone = tpl.cloneNode(true);
                clone.name = `codes[${cell.dataset.jour}][]`;
                cell.insertBefore(clone, e.target);
                return filterCodeSelects();
            }
            if (e.target.matches('.validate-btn')) {
                const cell = e.target.closest('.day-cell');
                if (cell) cell.classList.add('submitted');
                return;
            }
            if (e.target.matches('.modify-btn')) {
                const cell = e.target.closest('.day-cell');
                if (!cell) return;
                cell.classList.remove('submitted');
                // vider
                cell.querySelectorAll('.submitted-code, select.code-select').forEach(x => x.remove());
                // recréer un seul <select>
                const sel = tpl.cloneNode(true);
                sel.name = `codes[${cell.dataset.jour}][]`;
                cell.insertBefore(sel, e.target);
                // add + et Valider
                ['add-code-btn', 'validate-btn'].forEach(cls => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = cls;
                    b.textContent = cls === 'add-code-btn' ? '+' : 'Valider';
                    cell.insertBefore(b, e.target);
                });
                return filterCodeSelects();
            }
        });

        filterCodeSelects();
    });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
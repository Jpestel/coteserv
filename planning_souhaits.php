<?php
// planning_souhaits.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';

// Accès réservé aux agents, chefdecote et admin
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['user_role'], ['agent', 'chefdecote', 'admin'], true)
) {
    header('Location: login.php');
    exit;
}

$userId    = $_SESSION['user_id'];
$serviceId = $_SESSION['user_service_id'];

// 1) Charger les mois ouverts
$moisStmt = $pdo->prepare("
    SELECT id, mois 
      FROM mois_ouvert 
     WHERE id_service = ? AND actif = 1
     ORDER BY mois
");
$moisStmt->execute([$serviceId]);
$moisList = $moisStmt->fetchAll(PDO::FETCH_ASSOC);

// Choix du mois en GET
$selectedMoisId = (int)($_GET['mois_id'] ?? 0);

// 2) Sauvegarde POST
$success = null;
$errors  = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedMoisId > 0) {
    // Récupère le mois pour compter le nombre de jours
    $mStmt = $pdo->prepare("SELECT mois FROM mois_ouvert WHERE id = ?");
    $mStmt->execute([$selectedMoisId]);
    list($Y, $m) = explode('-', $mStmt->fetchColumn());
    $daysInMonth = (int)(new DateTimeImmutable("$Y-$m-01"))->format('t');

    // On boucle uniquement sur les jours où l'agent a posté quelque chose
    foreach (array_keys($_POST['codes'] ?? []) as $d) {
        $d = (int)$d;
        $codes = array_unique(array_filter($_POST['codes'][$d], fn($c) => $c !== ''));

        // 2.1) Supprimer les anciens pending pour ce jour
        $del = $pdo->prepare("
            DELETE FROM souhaits
             WHERE user_id = :uid
               AND mois_ouvert_id = :mid
               AND jour = :jour
               AND statut = 'pending'
        ");
        $del->execute([
            'uid'  => $userId,
            'mid'  => $selectedMoisId,
            'jour' => $d
        ]);

        // 2.2) Réinsérer tous les codes sélectionnés en pending
        $ins = $pdo->prepare("
            INSERT INTO souhaits
              (user_id, mois_ouvert_id, jour, code_id, statut)
            VALUES
              (:uid, :mid, :jour, :cid, 'pending')
        ");
        foreach ($codes as $cid) {
            $ins->execute([
                'uid'  => $userId,
                'mid'  => $selectedMoisId,
                'jour' => $d,
                'cid'  => (int)$cid,
            ]);
        }
    }

    $success = 'Vos souhaits ont bien été enregistrés et sont en attente de validation.';
}

// 3) Préparer la grille si un mois est sélectionné
$daysInMonth = 0;
$souhaits     = [];
if ($selectedMoisId > 0) {
    // Charger mois & jours ouvrés
    $mo = $pdo->prepare("
        SELECT mois, jours_ouvres_par_semaine
          FROM mois_ouvert
         WHERE id = ?
    ");
    $mo->execute([$selectedMoisId]);
    $row = $mo->fetch(PDO::FETCH_ASSOC);
    list($Y, $m) = explode('-', $row['mois']);
    $daysInMonth = (int)(new DateTimeImmutable("$Y-$m-01"))->format('t');
    $joursOuvres  = (int)$row['jours_ouvres_par_semaine'];

    // Charger les souhaits déjà en base
    $sh = $pdo->prepare("
        SELECT jour, code_id, statut
          FROM souhaits
         WHERE user_id = ? 
           AND mois_ouvert_id = ?
    ");
    $sh->execute([$userId, $selectedMoisId]);
    foreach ($sh->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $souhaits[$r['jour']][] = $r;
    }
}

// 4) Charger catégories & codes
$cats = $pdo->prepare("
    SELECT id, name
      FROM code_category
     WHERE service_id = ?
     ORDER BY name
");
$cats->execute([$serviceId]);
$categories = $cats->fetchAll();

$cd = $pdo->prepare("
    SELECT c.id, c.code, c.label, c.category_id
      FROM code c
      JOIN code_category cat ON c.category_id = cat.id
     WHERE cat.service_id = ?
     ORDER BY cat.name, c.code
");
$cd->execute([$serviceId]);
$allCodes = $cd->fetchAll();

// Organiser par catégorie
$codesByCat = [];
foreach ($allCodes as $c) {
    $codesByCat[$c['category_id']][] = $c;
}

// Formatters FR pour l’affichage
$monthF = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN,
    'MMMM yyyy'
);
$wkdayF = new IntlDateFormatter(
    'fr_FR',
    IntlDateFormatter::NONE,
    IntlDateFormatter::NONE,
    'Europe/Paris',
    IntlDateFormatter::GREGORIAN,
    'EEE'
);

$pageTitle = 'Saisie des souhaits';
require __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h2>

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

    <!-- Sélecteur de mois -->
    <form method="get" class="form-container">
        <label for="mois_id">Choisissez un mois</label>
        <select id="mois_id" name="mois_id" required>
            <option value="">--</option>
            <?php foreach ($moisList as $mth):
                $lbl = ucfirst($monthF->format(new DateTimeImmutable($mth['mois'] . '-01')));
            ?>
                <option value="<?= $mth['id'] ?>"
                    <?= $mth['id'] == $selectedMoisId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($lbl, ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Afficher</button>
    </form>

    <?php if ($selectedMoisId > 0): ?>
        <!-- Filtrer par catégories -->
        <div class="category-filter">
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="cat-btn active" data-cat="<?= $cat['id'] ?>">
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Grille mensuelle -->
        <form method="post" class="grid-form">
            <table class="planning-grid">
                <?php
                // Construire les semaines
                $weeks = [];
                $wk = [];
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $dt = new DateTimeImmutable(sprintf("%04d-%02d-%02d", $Y, $m, $d));
                    $dow = (int)$dt->format('N');
                    $wk[$dow] = $d;
                    if ($dow === 7 || $d === $daysInMonth) {
                        for ($i = 1; $i <= 7; $i++) if (!isset($wk[$i])) $wk[$i] = null;
                        ksort($wk);
                        $weeks[] = $wk;
                        $wk = [];
                    }
                }
                foreach ($weeks as $week):
                ?>
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <?php foreach ($week as $d): ?>
                                <th>
                                    <?= $d ?: '' ?><br>
                                    <?php if ($d): ?>
                                        <?= ucfirst($wkdayF->format(
                                            new DateTimeImmutable(sprintf("%04d-%02d-%02d", $Y, $m, $d))
                                        )) ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($_SESSION['user_prenom'], ENT_QUOTES) ?></td>
                            <?php foreach ($week as $d): ?>
                                <td>
                                    <?php
                                    if (!$d) {
                                        // cellule vide hors mois
                                        continue;
                                    }
                                    $dt  = new DateTimeImmutable(sprintf("%04d-%02d-%02d", $Y, $m, $d));
                                    $dow = (int)$dt->format('N');
                                    if ($dow > $joursOuvres) {
                                        // weekend ou hors jours ouvrés
                                        continue;
                                    }

                                    // on récupère les souhaits du jour
                                    $entries = $souhaits[$d] ?? [];
                                    // statut partagé (tous les codes ont même statut)
                                    $statut = $entries[0]['statut'] ?? null;
                                    $isSubmitted = in_array($statut, ['pending', 'validated', 'refused'], true);
                                    ?>
                                    <div
                                        class="day-cell <?= $isSubmitted ? 'submitted' : '' ?>"
                                        data-jour="<?= $d ?>">
                                        <?php if ($isSubmitted): ?>
                                            <!-- affichage des codes soumis + statut + bouton Modifier -->
                                            <?php foreach ($entries as $e):
                                                $lbl = '-';
                                                foreach ($allCodes as $c) {
                                                    if ($c['id'] === (int)$e['code_id']) {
                                                        $lbl = $c['code'];
                                                        break;
                                                    }
                                                }
                                            ?>
                                                <div class="submitted-code">
                                                    <select disabled class="code-select">
                                                        <option><?= htmlspecialchars($lbl, ENT_QUOTES) ?></option>
                                                    </select>
                                                    <span class="status <?= $statut ?>">
                                                        <?= $statut === 'pending'   ? 'En attente'
                                                            : ($statut === 'validated' ? 'Validé' : 'Refusé') ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>

                                            <!-- bouton Modifier toujours visible en mode submitted -->
                                            <button type="button" class="modify-btn">Modifier</button>

                                        <?php else: ?>
                                            <!-- mode saisie libre : selects + + + Valider -->
                                            <?php
                                            // au moins un select (vide si pas d'existant)
                                            $existing = array_map(fn($r) => $r['code_id'], $entries) ?: [''];
                                            foreach ($existing as $cid):
                                            ?>
                                                <select name="codes[<?= $d ?>][]" class="code-select">
                                                    <option value="">-</option>
                                                    <?php foreach ($categories as $cat): ?>
                                                        <?php foreach ($codesByCat[$cat['id']] ?? [] as $c):
                                                            $sel = ($c['id'] == $cid) ? 'selected' : '';
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

                                            <button type="button" class="add-code-btn">+</button>
                                            <button type="button" class="validate-btn">Valider</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>



                <?php endforeach; ?>
            </table>

            <button type="submit">Enregistrer mes souhaits</button>
        </form>
    <?php endif; ?>
</div>





<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Récupérer la liste des catégories actives
        const activeCats = new Set(<?= json_encode(array_column($categories, 'id'), JSON_NUMERIC_CHECK) ?>);

        // On construira un template de <select> vierge à cloner
        const anyCell = document.querySelector('.day-cell:not(.submitted)');
        const templateSelect = anyCell ?
            anyCell.querySelector('select.code-select').cloneNode(true) :
            null;
        if (templateSelect) templateSelect.value = '';

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
            // filtre par catégorie
            if (e.target.matches('.cat-btn')) {
                const id = +e.target.dataset.cat;
                e.target.classList.toggle('active') ?
                    activeCats.add(id) :
                    activeCats.delete(id);
                return filterCodeSelects();
            }

            // bouton '+'
            if (e.target.matches('.add-code-btn')) {
                const cell = e.target.closest('.day-cell');
                if (cell.classList.contains('submitted')) return;
                const first = cell.querySelector('select.code-select');
                if (!first) return;
                const clone = first.cloneNode(true);
                clone.value = '';
                cell.insertBefore(clone, e.target);
                return filterCodeSelects();
            }

            // bouton 'Valider' -> passe en submitted
            if (e.target.matches('.validate-btn')) {
                const cell = e.target.closest('.day-cell');
                if (cell) cell.classList.add('submitted');
                return;
            }

            // bouton 'Modifier' -> repasse en saisie
            if (e.target.matches('.modify-btn')) {
                const cell = e.target.closest('.day-cell');
                if (!cell || !templateSelect) return;

                // Retirer l'état 'submitted'
                cell.classList.remove('submitted');

                // Vider tout contenu existant dans la cellule
                cell.querySelectorAll('.submitted-code, select.code-select').forEach(el => el.remove());

                // Recréer un seul <select> vierge
                const sel = templateSelect.cloneNode(true);
                sel.name = `codes[${cell.dataset.jour}][]`;
                cell.insertBefore(sel, e.target);

                // Réinsérer les boutons + et Valider (attention à l'ordre)
                const btnPlus = document.createElement('button');
                btnPlus.type = 'button';
                btnPlus.className = 'add-code-btn';
                btnPlus.textContent = '+';
                cell.insertBefore(btnPlus, e.target);

                const btnValide = document.createElement('button');
                btnValide.type = 'button';
                btnValide.className = 'validate-btn';
                btnValide.textContent = 'Valider';
                cell.insertBefore(btnValide, e.target);

                // On remet le bouton Modifier en fin
                e.target.remove(); // on supprime l'ancien
                cell.appendChild(e.target); // on le remet tout à la fin

                return filterCodeSelects();
            }
        });

        // premier filtrage
        filterCodeSelects();
    });
</script>






<?php require_once __DIR__ . '/includes/footer.php'; ?>
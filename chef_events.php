<?php
// chef_events.php
// Gestion des événements par le chef de côte

session_start();
require_once __DIR__ . '/config/db.php';

// ————————————————————————————————
// 0) Sécurité & initialisation
// ————————————————————————————————
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

$serviceId   = $_SESSION['user_service_id'];
$errors      = [];
$success     = null;

// ————————————————————————————————
// 0b) Mode édition (GET ?edit_id=)
// ————————————————————————————————
$editId      = (int)($_GET['edit_id'] ?? 0);
$eventToEdit = null;
if ($editId) {
    $stmt = $pdo->prepare(
        'SELECT id, `date`, titre, description
           FROM evenements
          WHERE id = ? AND service_id = ?'
    );
    $stmt->execute([$editId, $serviceId]);
    $eventToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$eventToEdit) {
        // identifiant invalide ou pas de droit → retour
        header('Location: chef_events.php');
        exit;
    }
}

// ————————————————————————————————
// 1) Traitement POST (création, édition, suppression)
// ————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1a) Édition
    if (isset($_POST['edit_event_id'])) {
        $id          = (int) $_POST['edit_event_id'];
        $date        = trim($_POST['date'] ?? '');
        $titre       = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($date === '')  $errors[] = 'La date est requise.';
        if ($titre === '') $errors[] = 'Le titre est requis.';

        if (empty($errors)) {
            $upd = $pdo->prepare(
                'UPDATE evenements
                    SET `date` = ?, titre = ?, description = ?
                  WHERE id = ? AND service_id = ?'
            );
            $upd->execute([$date, $titre, $description, $id, $serviceId]);
            $success = 'Événement mis à jour.';
            // libérer le mode édition
            $editId = 0;
        }

        // 1b) Création
    } elseif (isset($_POST['create_event'])) {
        $date        = trim($_POST['date'] ?? '');
        $titre       = trim($_POST['titre'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($date === '')  $errors[] = 'La date est requise.';
        if ($titre === '') $errors[] = 'Le titre est requis.';

        if (empty($errors)) {
            $ins = $pdo->prepare(
                'INSERT INTO evenements (service_id, `date`, titre, description)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$serviceId, $date, $titre, $description]);
            $success = 'Événement créé avec succès.';
        }

        // 1c) Suppression
    } elseif (isset($_POST['delete_event_id'])) {
        $evtId = (int) $_POST['delete_event_id'];
        $del = $pdo->prepare(
            'DELETE FROM evenements WHERE id = ? AND service_id = ?'
        );
        $del->execute([$evtId, $serviceId]);
        $success = 'Événement supprimé.';
    }
}

// ————————————————————————————————
// 2) Charger la liste des événements
// ————————————————————————————————
$list = $pdo->prepare(
    'SELECT id, `date`, titre, description
       FROM evenements
      WHERE service_id = ?
      ORDER BY `date` DESC, id DESC'
);
$list->execute([$serviceId]);
$events = $list->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Gérer les événements';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h2>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors"
            style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire de création / édition -->
    <section class="mb-4">
        <h3>
            <?= $editId ? 'Modifier un événement' : 'Ajouter un événement' ?>
        </h3>
        <form method="post" class="form-container">
            <?php if ($editId): ?>
                <input type="hidden" name="edit_event_id" value="<?= $editId ?>">
            <?php else: ?>
                <input type="hidden" name="create_event" value="1">
            <?php endif; ?>

            <label for="date">Date de l'événement</label>
            <input type="date" id="date" name="date" required
                value="<?= htmlspecialchars($eventToEdit['date'] ?? '', ENT_QUOTES) ?>">

            <label for="titre">Titre</label>
            <input type="text" id="titre" name="titre" maxlength="255" required
                value="<?= htmlspecialchars($eventToEdit['titre'] ?? '', ENT_QUOTES) ?>">

            <label for="description">Description (optionnelle)</label>
            <textarea id="description" name="description" rows="3"><?= htmlspecialchars($eventToEdit['description'] ?? '', ENT_QUOTES) ?></textarea>

            <button type="submit" class="btn btn-primary">
                <?= $editId ? 'Mettre à jour' : 'Créer l\'événement' ?>
            </button>
            <?php if ($editId): ?>
                <a href="chef_events.php" class="btn btn-secondary">Annuler</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- Tableau des événements -->
    <section>
        <h3>Liste des événements</h3>
        <?php if (empty($events)): ?>
            <p>Aucun événement pour le moment.</p>
        <?php else: ?>
            <table class="planning-all">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Titre</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $evt): ?>
                        <tr>
                            <td><?= htmlspecialchars($evt['date'], ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($evt['titre'], ENT_QUOTES) ?></td>
                            <td><?= nl2br(htmlspecialchars($evt['description'], ENT_QUOTES)) ?></td>
                            <td>
                                <!-- Éditer via lien GET -->
                                <a href="chef_events.php?edit_id=<?= $evt['id'] ?>"
                                    class="btn btn-sm btn-secondary">
                                    Éditer
                                </a>
                                <!-- Supprimer -->
                                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cet événement ?');">
                                    <input type="hidden" name="delete_event_id" value="<?= $evt['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
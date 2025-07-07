<?php
// chef_events.php
// Gestion des événements par le chef de côte

session_start();
require_once __DIR__ . '/config/db.php';

// Vérifier accès chefdecote
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'chefdecote') {
    header('Location: login.php');
    exit;
}

$serviceId = $_SESSION['user_service_id'];
$errors    = [];
$success   = null;

// 1) Création d'un événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    $date        = trim($_POST['date'] ?? '');
    $titre       = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validation
    if ($date === '') {
        $errors[] = 'La date est requise.';
    }
    if ($titre === '') {
        $errors[] = 'Le titre est requis.';
    }

    if (empty($errors)) {
        $ins = $pdo->prepare(
            'INSERT INTO evenements (service_id, `date`, titre, description)
             VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$serviceId, $date, $titre, $description]);
        $success = 'Événement créé avec succès.';
    }
}

// 2) Suppression d'un événement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event_id'])) {
    $evtId = (int)$_POST['delete_event_id'];
    $del = $pdo->prepare(
        'DELETE FROM evenements
         WHERE id = ? AND service_id = ?'
    );
    $del->execute([$evtId, $serviceId]);
    $success = 'Événement supprimé.';
}

// 3) Charger la liste des événements du service
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

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="errors" style="background-color:#d4edda;color:#155724;border-color:#c3e6cb;">
            <?= htmlspecialchars($success, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire de création -->
    <section class="mb-4">
        <h3>Ajouter un événement</h3>
        <form method="post" class="form-container">
            <input type="hidden" name="create_event" value="1">

            <label for="date">Date de l'événement</label>
            <input type="date" id="date" name="date" required>

            <label for="titre">Titre</label>
            <input type="text" id="titre" name="titre" maxlength="255" required>

            <label for="description">Description (optionnelle)</label>
            <textarea id="description" name="description" rows="3"></textarea>

            <button type="submit" class="btn btn-primary">Créer l'événement</button>
        </form>
    </section>

    <!-- Tableau des événements -->
    <section>
        <h3>Liste des événements</h3>
        <?php if (empty($events)): ?>
            <p>Aucun événement pour le moment.</p>
        <?php else: ?>
            <table>
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
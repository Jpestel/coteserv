<?php
// admin_users.php
// Page d'administration des utilisateurs
session_start();

require_once __DIR__ . '/config/db.php';

// Vérifier accès admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Gérer les utilisateurs';
require_once __DIR__ . '/includes/header.php';

// Recherche et filtres
$search        = trim($_GET['search'] ?? '');
$filterRole    = $_GET['role'] ?? '';
$filterService = $_GET['service'] ?? '';

// Récupérer services pour filtre
$services = $pdo->query('SELECT id, nom FROM service ORDER BY nom')->fetchAll();

// Construire requête dynamique
$sql = 'SELECT u.id, u.pseudo, u.email, u.nom, u.prenom, u.role, u.actif, COALESCE(s.nom, "-") AS service_name
        FROM user u
        LEFT JOIN service s ON u.id_service = s.id
        WHERE 1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (u.pseudo LIKE :search OR u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search)';
    $params['search'] = "%$search%";
}
if (in_array($filterRole, ['agent', 'chefdecote', 'admin'])) {
    $sql .= ' AND u.role = :role';
    $params['role'] = $filterRole;
}
if (ctype_digit($filterService)) {
    $sql .= ' AND u.id_service = :service';
    $params['service'] = (int)$filterService;
}
$sql .= ' ORDER BY u.nom, u.prenom';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<div class="container content">
    <h2>Gérer les utilisateurs</h2>

    <!-- Filtres et recherche -->
    <div class="form-container">
        <h3>Recherche et filtres</h3>
        <form method="get">
            <label for="search">Recherche :</label>
            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>">

            <label for="role">Rôle :</label>
            <select id="role" name="role">
                <option value="">Tous</option>
                <option value="agent" <?= $filterRole === 'agent' ? 'selected' : '' ?>>Agent</option>
                <option value="chefdecote" <?= $filterRole === 'chefdecote' ? 'selected' : '' ?>>Chef de côte</option>
                <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>

            <label for="service">Service :</label>
            <select id="service" name="service">
                <option value="">Tous</option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filterService == (string)$s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Appliquer</button>
            <a href="admin_users_edit.php" class="btn">+ Nouvel utilisateur</a>
        </form>
    </div>

    <!-- Tableau des utilisateurs -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Pseudo</th>
                <th>Nom</th>
                <th>Prénom</th>
                <th>Email</th>
                <th>Rôle</th>
                <th>Service</th>
                <th>Actif</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($users): ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['pseudo']) ?></td>
                        <td><?= htmlspecialchars($u['nom']) ?></td>
                        <td><?= htmlspecialchars($u['prenom']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td><?= htmlspecialchars($u['service_name']) ?></td>
                        <td><?= $u['actif'] ? 'Oui' : 'Non' ?></td>
                        <td>
                            <a href="admin_users_edit.php?id=<?= $u['id'] ?>">Éditer</a>
                            <?php if ($u['actif']): ?>
                                <a href="admin_users_toggle.php?id=<?= $u['id'] ?>&action=deactivate">Désactiver</a>
                            <?php else: ?>
                                <a href="admin_users_toggle.php?id=<?= $u['id'] ?>&action=activate">Activer</a>
                            <?php endif; ?>
                            <a href="admin_users_delete.php?id=<?= $u['id'] ?>" onclick="return confirm('Supprimer cet utilisateur ?');">Supprimer</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">Aucun utilisateur trouvé.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
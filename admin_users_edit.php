<?php
// admin_users_edit.php
// Page de création/édition d'un utilisateur
session_start();

require_once __DIR__ . '/config/db.php';

// Vérifier accès admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Récupérer ID utilisateur (pour édition)
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $userId > 0;

// Récupérer listes pour formulaires
$services = $pdo->query('SELECT id, nom FROM service ORDER BY nom')->fetchAll();
$roles    = ['agent' => 'Agent', 'chefdecote' => 'Chef de côte', 'admin' => 'Admin'];

// Charger données existantes si édition
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT id, pseudo, email, nom, prenom, role, id_service, actif FROM user WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        header('Location: admin_users.php');
        exit;
    }
} else {
    // Initialisation pour création
    $user = [
        'pseudo'     => '',
        'email'      => '',
        'nom'        => '',
        'prenom'     => '',
        'role'       => 'agent',
        'id_service' => null,
        'actif'      => 1
    ];
}

$errors = [];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pseudo    = trim($_POST['pseudo'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $role      = $_POST['role'] ?? 'agent';
    $serviceId = $_POST['service'] !== '' ? (int)$_POST['service'] : null;
    $actif     = isset($_POST['actif']) ? 1 : 0;
    $pwd       = $_POST['password'] ?? '';
    $pwd2      = $_POST['password2'] ?? '';

    // Validation
    if ($pseudo === '') $errors[] = 'Le pseudo est requis.';
    if ($email === '') {
        $errors[] = 'L\'email est requis.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email invalide.';
    }
    if ($nom === '') $errors[] = 'Le nom est requis.';
    if ($prenom === '') $errors[] = 'Le prénom est requis.';
    if (!isset($roles[$role])) $errors[] = 'Rôle invalide.';
    if (($pwd || $pwd2) && $pwd !== $pwd2) {
        $errors[] = 'Les mots de passe ne correspondent pas.';
    }
    // Unicité pseudo/email
    if (empty($errors)) {
        $sql = 'SELECT COUNT(*) FROM user WHERE (pseudo = :pseudo OR email = :email)';
        if ($isEdit) $sql .= ' AND id <> :id';
        $stmt = $pdo->prepare($sql);
        $params = ['pseudo' => $pseudo, 'email' => $email];
        if ($isEdit) $params['id'] = $userId;
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Ce pseudo ou cet email est déjà utilisé.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            // Mise à jour
            $fields = 'pseudo = :pseudo, email = :email, nom = :nom, prenom = :prenom, role = :role, id_service = :service, actif = :actif';
            if ($pwd) $fields .= ', password = :password';
            $sql = "UPDATE user SET $fields WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $params = [
                'pseudo'  => $pseudo,
                'email'   => $email,
                'nom'     => $nom,
                'prenom'  => $prenom,
                'role'    => $role,
                'service' => $serviceId,
                'actif'   => $actif,
                'id'      => $userId
            ];
            if ($pwd) $params['password'] = password_hash($pwd, PASSWORD_BCRYPT);
            $stmt->execute($params);
        } else {
            // Création
            $hash = password_hash($pwd, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO user (pseudo, email, password, nom, prenom, role, id_service, actif, date_creation)
                 VALUES (:pseudo, :email, :password, :nom, :prenom, :role, :service, :actif, NOW())'
            );
            $stmt->execute([
                'pseudo'   => $pseudo,
                'email'    => $email,
                'password' => $hash,
                'nom'      => $nom,
                'prenom'   => $prenom,
                'role'     => $role,
                'service'  => $serviceId,
                'actif'    => $actif
            ]);
        }
        header('Location: admin_users.php');
        exit;
    }
}

// Titre et inclusion de l'en-tête
$pageTitle = $isEdit ? 'Éditer un utilisateur' : 'Nouvel utilisateur';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container content">
    <h2><?= htmlspecialchars($pageTitle) ?></h2>

    <?php if ($errors): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="form-container">
        <label for="pseudo">Pseudo</label>
        <input type="text" id="pseudo" name="pseudo" value="<?= htmlspecialchars($user['pseudo']) ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>

        <label for="nom">Nom</label>
        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($user['nom']) ?>" required>

        <label for="prenom">Prénom</label>
        <input type="text" id="prenom" name="prenom" value="<?= htmlspecialchars($user['prenom']) ?>" required>

        <label for="role">Rôle</label>
        <select id="role" name="role">
            <?php foreach ($roles as $key => $label): ?>
                <option value="<?= $key ?>" <?= $user['role'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="service">Service</label>
        <select id="service" name="service">
            <option value="">-- Aucun --</option>
            <?php foreach ($services as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $user['id_service'] === $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nom']) ?></option>
            <?php endforeach; ?>
        </select>

        <div>
            <label>
                <input type="checkbox" name="actif" value="1" <?= $user['actif'] ? 'checked' : '' ?>> Actif
            </label>
        </div>

        <label for="password">Mot de passe <?= $isEdit ? '(laisser vide si inchangé)' : '' ?></label>
        <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>

        <label for="password2">Confirmation du mot de passe</label>
        <input type="password" id="password2" name="password2" <?= $isEdit ? '' : 'required' ?>>

        <button type="submit"><?= $isEdit ? 'Enregistrer' : 'Créer' ?></button>
        <a href="admin_users.php" class="btn">Annuler</a>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
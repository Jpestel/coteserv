<?php
// register.php
// Page d'inscription des utilisateurs (agents)

session_start();
require_once __DIR__ . '/config/db.php'; // Connexion PDO

// Si l'utilisateur est déjà connecté, redirection
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'Inscription';
require_once __DIR__ . '/includes/header.php';

$errors = [];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et nettoyage
    $pseudo    = trim($_POST['pseudo'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // Validation
    if ($pseudo === '')    $errors[] = 'Le pseudo est requis.';
    if ($email === '')     $errors[] = 'L\'email est requis.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
    if ($nom === '')       $errors[] = 'Le nom est requis.';
    if ($prenom === '')    $errors[] = 'Le prénom est requis.';
    if ($password === '')  $errors[] = 'Le mot de passe est requis.';
    if ($password !== $password2) $errors[] = 'Les mots de passe ne correspondent pas.';

    // Vérifier unicité du pseudo et de l'email
    if (empty($errors)) {
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM `user` WHERE pseudo = :pseudo OR email = :email');
        $checkStmt->execute(['pseudo' => $pseudo, 'email' => $email]);
        if ($checkStmt->fetchColumn() > 0) {
            $errors[] = 'Ce pseudo ou cet email est déjà utilisé.';
        }
    }

    // Insertion si OK
    if (empty($errors)) {
        // Déterminer rôle : premier admin, sinon agent
        $countStmt = $pdo->query('SELECT COUNT(*) FROM `user`');
        $userCount = (int)$countStmt->fetchColumn();
        $role = ($userCount === 0) ? 'admin' : 'agent';

        // Hash du mot de passe
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Insertion
        $insertSql = "INSERT INTO `user` (pseudo, email, password, nom, prenom, role) VALUES (:pseudo, :email, :password, :nom, :prenom, :role)";
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([
            'pseudo'   => $pseudo,
            'email'    => $email,
            'password' => $hash,
            'nom'      => $nom,
            'prenom'   => $prenom,
            'role'     => $role
        ]);

        // Redirection vers la page de connexion
        header('Location: login.php?registered=1');
        exit;
    }
}
?>

<div class="form-container">
    <h2>Inscription</h2>

    <?php if (!empty($errors)): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="register.php" method="post">
        <label for="pseudo">Pseudo</label>
        <input type="text" id="pseudo" name="pseudo" value="<?= htmlspecialchars($pseudo ?? '') ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required>

        <label for="nom">Nom</label>
        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($nom ?? '') ?>" required>

        <label for="prenom">Prénom</label>
        <input type="text" id="prenom" name="prenom" value="<?= htmlspecialchars($prenom ?? '') ?>" required>

        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" required>

        <label for="password2">Confirmation du mot de passe</label>
        <input type="password" id="password2" name="password2" required>

        <button type="submit">S'inscrire</button>
    </form>

    <p class="mt-2 text-center">Déjà inscrit ? <a href="login.php">Connectez-vous</a>.</p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php
// login.php
// Page de connexion des utilisateurs via email
session_start();

require_once __DIR__ . '/config/db.php'; // Connexion à la base via PDO

// Si l'utilisateur est déjà connecté, on le redirige
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Titre de la page pour header.php
$pageTitle = 'Connexion';

// Inclusion de l'en-tête
require_once __DIR__ . '/includes/header.php';

$errors = [];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et nettoyage des champs
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    if (empty($email)) {
        $errors[] = 'Veuillez saisir votre email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email invalide.';
    }
    if (empty($password)) {
        $errors[] = 'Veuillez saisir votre mot de passe.';
    }

    // Si pas d'erreurs, on vérifie les identifiants
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id, pseudo, prenom, password, actif, role, id_service FROM `user` WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && $user['actif']) {
            // Vérification du mot de passe
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id']         = $user['id'];
                $_SESSION['user_pseudo']     = $user['pseudo'];
                $_SESSION['user_prenom']     = $user['prenom'];    // Ajout du prénom
                $_SESSION['user_role']       = $user['role'];
                $_SESSION['user_service_id'] = $user['id_service'];
                header('Location: dashboard.php');
                exit;
            } else {
                $errors[] = 'Mot de passe incorrect.';
            }
        } else {
            $errors[] = 'Compte introuvable ou inactif.';
        }
    }
}
?>

<div class="form-container">
    <h2>Connexion</h2>
    <?php if (!empty($errors)): ?>
        <div class="errors">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="login.php" method="post">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Se connecter</button>
    </form>

    <p>Pas encore inscrit ? <a href="register.php">Créez un compte</a>.</p>
</div>

<?php
// Inclusion du pied de page
require_once __DIR__ . '/includes/footer.php';
?>
<?php
session_start();
require_once __DIR__ . '/config/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = $_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

// 1) Charger le souhait et vérifier l’appartenance
$stmt = $pdo->prepare("
  SELECT s.*, c.label, c.heures_supplementaires_inc, m.mois
  FROM souhaits s
  JOIN code c         ON c.id = s.code_id
  JOIN mois_ouvert m  ON m.id = s.mois_ouvert_id
  WHERE s.id = :id
    AND s.user_id     = :uid
");
$stmt->execute([':id' => $id, ':uid' => $userId]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$s) {
    http_response_code(404);
    exit('Souhait introuvable ou accès refusé.');
}

// 2) Charger la liste des codes pour ce service
$codes = $pdo->prepare(
    "SELECT c.id, c.code
     FROM code c
     JOIN code_category cat ON c.category_id = cat.id
    WHERE cat.service_id = ?
    ORDER BY cat.name, c.code"
);
$codes->execute([$_SESSION['user_service_id']]);
$codes = $codes->fetchAll(PDO::FETCH_ASSOC);

// 3) Traitement du POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newCode  = (int)$_POST['code_id'];
    $newEtat  = $_POST['statut'] ?? $s['statut'];
    $upd = $pdo->prepare("
      UPDATE souhaits
         SET code_id = :code,
             statut  = :etat
       WHERE id_souhait = :id
         AND user_id    = :uid
    ");
    $upd->execute([
        ':code' => $newCode,
        ':etat' => $newEtat,
        ':id' => $id,
        ':uid' => $userId
    ]);
    header('Location: planning_souhaits.php?mois_id=' . $s['mois_ouvert_id']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Modifier un souhait</title>
</head>

<body>
    <h2>Modifier le souhait du <?= htmlspecialchars($s['jour'] . '/' . $s['mois']) ?></h2>
    <form method="post">
        <label>Type d’absence</label>
        <select name="code_id">
            <?php foreach ($codes as $c): ?>
                <option value="<?= $c['id'] ?>"
                    <?= $c['id'] == $s['code_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Statut</label>
        <select name="statut">
            <?php foreach (['pending' => 'En attente', 'validated' => 'Validé', 'refused' => 'Refusé'] as $val => $lbl): ?>
                <option value="<?= $val ?>"
                    <?= $val == $s['statut'] ? 'selected' : '' ?>>
                    <?= $lbl ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Enregistrer</button>
        <a href="planning_souhaits.php?mois_id=<?= $s['mois_ouvert_id'] ?>">Annuler</a>
    </form>
</body>

</html>
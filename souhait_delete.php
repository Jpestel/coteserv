<?php
session_start();
require_once __DIR__ . '/config/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$id = (int)($_GET['id'] ?? 0);
$del = $pdo->prepare("
  DELETE FROM souhaits
  WHERE id_souhait = :id
    AND user_id    = :uid
");
$del->execute([':id' => $id, ':uid' => $_SESSION['user_id']]);
header('Location: planning_souhaits.php?mois_id=' . ($_GET['mois_id'] ?? ''));
exit;

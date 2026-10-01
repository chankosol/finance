<?php
// /finance/admin/pawn_delete.php
require_once '../includes/auth.php';
require_once '../includes/helpers.php';

fin_require_login();
fin_require_permission('delete_pawn');
$business_id = fin_require_business();
global $pdo;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: pawns.php');
    exit;
}

$st = $pdo->prepare("DELETE FROM pawns WHERE id = ? AND business_id = ?");
$st->execute([$id, $business_id]);

header('Location: pawns.php');
exit;

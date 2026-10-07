<?php
require_once 'config.php';
$db = getDB();

$query = "SELECT p.*, a.nama_alat, a.kategori_id, u.nama as nama_user, u.username
          FROM peminjaman p
          JOIN alat a ON p.alat_id = a.id
          JOIN users u ON p.user_id = u.id
          ORDER BY p.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute();
$peminjaman = $stmt->fetchAll();

echo '<h2>Raw Data:</h2>';
echo '<pre>' . json_encode($peminjaman, JSON_PRETTY_PRINT) . '</pre>';

echo '<h2>First Record Keys:</h2>';
if (!empty($peminjaman)) {
    echo '<pre>' . json_encode(array_keys($peminjaman[0])) . '</pre>';
    echo '<h2>First Record ID Value:</h2>';
    echo 'ID: ' . ($peminjaman[0]['id'] ?? 'NOT FOUND') . '<br>';
}
?>

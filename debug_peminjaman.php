<?php
require_once 'config.php';
startSession();
requireLogin();

$db = getDB();

// Debug query
$query = "SELECT p.id, p.user_id, p.alat_id, p.jumlah, p.tanggal_pinjam, p.tanggal_pengembalian_rencana, p.tanggal_pengembalian_aktual, p.status, p.catatan, p.created_at, p.updated_at, a.nama_alat, a.kategori_id, u.nama as nama_user, u.username
          FROM peminjaman p
          JOIN alat a ON p.alat_id = a.id
          JOIN users u ON p.user_id = u.id
          ORDER BY p.created_at DESC
          LIMIT 5";

echo "<h1>DEBUG QUERY PEMINJAMAN</h1>";
echo "<pre>Query: " . htmlspecialchars($query) . "</pre>";

try {
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll();
    
    echo "<h2>Hasil: " . count($result) . " records</h2>";
    
    if (!empty($result)) {
        echo "<h3>First Record:</h3>";
        echo "<pre>";
        print_r($result[0]);
        echo "</pre>";
        
        echo "<h3>Column Keys:</h3>";
        echo "<pre>";
        print_r(array_keys($result[0]));
        echo "</pre>";
        
        echo "<h3>ID value:</h3>";
        echo "<pre>";
        echo "isset(['id']): " . (isset($result[0]['id']) ? 'YES' : 'NO') . "\n";
        echo "Value: " . ($result[0]['id'] ?? 'NULL') . "\n";
        echo "</pre>";
    } else {
        echo "<p style='color:red'>Tidak ada data!</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

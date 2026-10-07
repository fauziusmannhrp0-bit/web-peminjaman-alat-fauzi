<?php
// cetak_laporan.php (versi perbaikan, tahan banting terhadap versi Dompdf / ketiadaan Dompdf)
// REQUIRE: config.php harus menyediakan startSession(), requireLogin(), getDB(), getCurrentUser()

require_once 'config.php';
startSession();
requireLogin();

$user = getCurrentUser();
// hanya admin atau petugas boleh mencetak
if (!in_array($user['nama_role'], ['admin', 'petugas'])) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

// validasi id — support both peminjaman_id (dari peminjaman.php) dan pengembalian_id (dari pengembalian.php)
$peminjamanId   = isset($_GET['peminjaman_id']) ? (int) $_GET['peminjaman_id'] : 0;
$pengembalianId = isset($_GET['pengembalian_id']) ? (int) $_GET['pengembalian_id'] : 0;

// Backward compatibility: jika ada parameter 'id', gunakan itu
if ($peminjamanId <= 0 && $pengembalianId <= 0) {
    $peminjamanId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($peminjamanId <= 0) {
        http_response_code(400);
        echo 'ID peminjaman atau pengembalian tidak valid.';
        exit;
    }
}

// Jika pengembalian_id diberikan, ambil peminjaman_id dari pengembalian
if ($pengembalianId > 0) {
    $db = getDB();
    $stmtPg = $db->prepare("SELECT peminjaman_id FROM pengembalian WHERE id = ?");
    $stmtPg->execute([$pengembalianId]);
    $pg = $stmtPg->fetch(PDO::FETCH_ASSOC);
    if (!$pg) {
        http_response_code(404);
        echo 'Data pengembalian tidak ditemukan.';
        exit;
    }
    $peminjamanId = (int)$pg['peminjaman_id'];
}

$id = $peminjamanId;

$db = getDB();

// Ambil data peminjaman utama
$stmt = $db->prepare("
    SELECT p.*, a.nama_alat, a.harga_alat, a.tarif_denda_per_hari,
           u.nama AS nama_user, u.username
    FROM peminjaman p
    JOIN alat a ON p.alat_id = a.id
    JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    http_response_code(404);
    echo 'Data peminjaman tidak ditemukan.';
    exit;
}

// Ambil unit (jika ada)
$stmtU = $db->prepare("
    SELECT ua.kode_unit, pu.kondisi_kembali
    FROM peminjaman_unit pu
    JOIN unit_alat ua ON ua.id = pu.unit_id
    WHERE pu.peminjaman_id = ?
    ORDER BY ua.kode_unit ASC
");
$stmtU->execute([$id]);
$units = $stmtU->fetchAll(PDO::FETCH_ASSOC);

// Hitung total denda dari tabel denda (jika ada)
$stmtD = $db->prepare("SELECT COALESCE(SUM(total_denda),0) AS total FROM denda WHERE peminjaman_id = ?");
$stmtD->execute([$id]);
$totalDenda = (int) $stmtD->fetchColumn();

// Validasi: hanya izinkan cetak jika sudah dikembalikan dan sudah dicek oleh petugas/admin
if (($data['status'] ?? '') !== 'dikembalikan') {
    http_response_code(403);
    echo 'Laporan hanya bisa dicetak setelah alat dikembalikan.';
    exit;
}

if (empty($data['diperiksa_oleh'])) {
    http_response_code(403);
    echo 'Laporan hanya bisa dicetak setelah pengecekan oleh petugas/admin.';
    exit;
}

// Pastikan ada hasil pengecekan unit (kondisi_kembali) atau setidaknya kondisi_pengembalian global
$hasUnitChecked = false;
foreach ($units as $u) {
    if (isset($u['kondisi_kembali']) && $u['kondisi_kembali'] !== null) { $hasUnitChecked = true; break; }
}
if (!$hasUnitChecked && empty($data['kondisi_pengembalian'])) {
    http_response_code(403);
    echo 'Laporan hanya bisa dicetak setelah petugas mengisi kondisi unit saat pengembalian.';
    exit;
}

// Sanitasi helper
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// --- Siapkan variabel yang akan dimasukkan ke HTML (hindari fungsi di dalam heredoc) ---
$nama_user           = e($data['nama_user'] ?? '-');
$username            = e($data['username'] ?? '-');
$status_peminjaman   = e($data['status'] ?? '-');
$nama_alat           = e($data['nama_alat'] ?? '-');
$jumlah              = (int) ($data['jumlah'] ?? 0);
$tanggal_pinjam      = !empty($data['tanggal_pinjam']) ? date('d/m/Y', strtotime($data['tanggal_pinjam'])) : (!empty($data['created_at']) ? date('d/m/Y', strtotime($data['created_at'])) : '-');
$jatuh_tempo         = !empty($data['tanggal_pengembalian_rencana']) ? date('d/m/Y', strtotime($data['tanggal_pengembalian_rencana'])) : '-';
$tanggal_kembali     = !empty($data['tanggal_pengembalian_aktual']) ? date('d/m/Y', strtotime($data['tanggal_pengembalian_aktual'])) : '-';
$hari_terlambat      = (int) ($data['hari_terlambat'] ?? 0);
$totalDendaFormatted = 'Rp ' . number_format($totalDenda,0,',','.');
$catatan_raw         = $data['catatan_pengembalian'] ?? $data['catatan'] ?? '-';
$catatan_sanitized   = e($catatan_raw);
$rawStatus = $data['status_denda'] ?? '';
if ($rawStatus === 'menunggu') {
    $statusDendaLabel = 'Belum Lunas';
} elseif ($rawStatus === 'lunas') {
    $statusDendaLabel = 'Lunas';
} elseif ($rawStatus === 'bebas' || $rawStatus === 'tidak_ada' || $rawStatus === '') {
    $statusDendaLabel = 'Tidak Ada Denda';
} else {
    // fallback: tampilkan nilai asli kapitalized
    $statusDendaLabel = ucwords(str_replace(['_','-'], [' ', ' '], $rawStatus));
}
$statusDendaLabel = e($statusDendaLabel);
$user_nama           = e($user['nama'] ?? '-');
$now                 = (new DateTime())->format('d/m/Y H:i');
$judul               = "Laporan Peminjaman #{$id}";

// Bangun HTML untuk bagian unit (jika ada)
$unitsHtml = '';
if (!empty($units)) {
    $rows = '';
    foreach ($units as $u) {
        $kode = e($u['kode_unit'] ?? '-');
        $kond = isset($u['kondisi_kembali']) && $u['kondisi_kembali'] !== null ? e(str_replace('_',' ',$u['kondisi_kembali'])) : '-';
        $rows .= "<tr><td>{$kode}</td><td>{$kond}</td></tr>";
    }
    $unitsHtml = <<<HTML
    <div class="section">
        <strong>Detail Unit</strong>
        <table>
            <thead><tr><th>Kode Unit</th><th>Kondisi Saat Dikembalikan</th></tr></thead>
            <tbody>
                {$rows}
            </tbody>
        </table>
    </div>
HTML;
}

// Bangun seluruh HTML laporan (menggunakan variabel yang sudah disanitasi)
$html = <<<HTML
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$judul}</title>
<style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size:12px; color:#222; margin:20px; }
    .header { text-align:center; margin-bottom:18px; }
    .title { font-size:16px; font-weight:700; margin-bottom:6px; }
    .meta { font-size:11px; color:#555; }
    .section { margin-bottom:12px; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; vertical-align:top; font-size:12px; }
    th { background:#f6f6f6; font-weight:700; }
    .small { font-size:11px; color:#555; }
    .right { text-align:right; }
    .note { white-space:pre-wrap; background:#fafafa; padding:8px; border-left:3px solid #ddd; }
    .footer { margin-top:26px; display:flex; justify-content:space-between; align-items:center; }
    .sig { text-align:center; font-size:12px; }
</style>
</head>
<body>
    <div class="header">
        <div class="title">LAPORAN PEMINJAMAN ALAT</div>
        <div class="meta">Tanggal Cetak: {$now}</div>
    </div>

    <div class="section">
        <strong>Data Peminjam</strong>
        <table>
            <tr><th>Nama</th><td>{$nama_user}</td></tr>
            <tr><th>Username</th><td>@{$username}</td></tr>
            <tr><th>Status Peminjaman</th><td>{$status_peminjaman}</td></tr>
        </table>
    </div>

    <div class="section">
        <strong>Detail Peminjaman</strong>
        <table>
            <tr><th>Nama Alat</th><td>{$nama_alat}</td></tr>
            <tr><th>Jumlah</th><td>{$jumlah} unit</td></tr>
            <tr><th>Tanggal Pinjam</th><td>{$tanggal_pinjam}</td></tr>
            <tr><th>Jatuh Tempo</th><td>{$jatuh_tempo}</td></tr>
            <tr><th>Tanggal Kembali (Aktual)</th><td>{$tanggal_kembali}</td></tr>
            <tr><th>Hari Terlambat</th><td>{$hari_terlambat} hari</td></tr>
        </table>
    </div>

    {$unitsHtml}

    <div class="section">
        <strong>Ringkasan Denda & Catatan</strong>
        <table>
            <tr><th>Total Denda</th><td class="right">{$totalDendaFormatted}</td></tr>
            <tr><th>Status Denda</th><td>{$statusDendaLabel}</td></tr>
            <tr><th>Catatan Petugas</th><td class="note">{$catatan_sanitized}</td></tr>
        </table>
    </div>

    

</body>
</html>
HTML;

// --- Cek apakah Dompdf tersedia ---
$dompdfAvailable = false;
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    // pastikan class Dompdf ada
    if (class_exists('\Dompdf\Dompdf')) {
        $dompdfAvailable = true;
    }
}

if ($dompdfAvailable) {
    // generate PDF via Dompdf dengan pengecekan fitur Options / method set_option
    try {
        // Buat instance Dompdf dengan atau tanpa Options, tergantung ketersediaan class Options
        $dompdf = null;
        if (class_exists('\Dompdf\Options')) {
            // jika tersedia, gunakan Options()
            $options = new \Dompdf\Options();
            if (method_exists($options, 'set')) {
                $options->set('isRemoteEnabled', true);
                $options->set('defaultFont', 'DejaVu Sans');
            }
            $dompdf = new \Dompdf\Dompdf($options);
        } else {
            // jika Options tidak ada, buat Dompdf tanpa argumen lalu set option jika method tersedia
            $dompdf = new \Dompdf\Dompdf();
            if (method_exists($dompdf, 'set_option')) {
                // beberapa versi menyediakan set_option
                $dompdf->set_option('isRemoteEnabled', true);
                $dompdf->set_option('defaultFont', 'DejaVu Sans');
            } elseif (method_exists($dompdf, 'setOptions')) {
                // fallback nama method alternatif
                $dompdf->setOptions(['isRemoteEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
            }
        }

        // load dan render
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'laporan_peminjaman_' . $id . '.pdf';
        // Stream inline (buka di browser). Jika ingin auto-download: ['Attachment'=>true]
        $dompdf->stream($filename, ['Attachment' => false]);
        exit;
    } catch (\Throwable $e) {
        // fallback ke HTML printable, tampilkan pesan aman
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>" . e($judul) . " - Error</title></head><body>";
        echo "<h3>Terjadi kesalahan saat membuat PDF</h3>";
        echo "<p>Pesan: " . e($e->getMessage()) . "</p>";
        echo "<hr>";
        echo $html;
        echo "</body></html>";
        exit;
    }
} else {
    // Dompdf tidak tersedia -> tampilkan HTML printable + instruksi
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>" . e($judul) . " - Print</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;margin:20px} .print-bar{margin-bottom:12px;padding:10px;background:#fff3cd;border:1px solid #ffeeba;border-radius:6px}</style>
    </head><body>";
    echo "<div class='print-bar'><strong>Perhatian:</strong> Dompdf tidak terpasang. Untuk hasil PDF otomatis, jalankan <code>composer require dompdf/dompdf</code> lalu ulangi. Untuk sementara, gunakan fungsi print browser (Ctrl+P / Cmd+P).</div>";
    echo $html;
    echo "<script>window.print()</script></body></html>";
    exit;
}
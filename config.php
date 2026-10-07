<?php
// ============================================
// CONFIG.PHP - FIXED: Timezone sync + formatDate
// ============================================

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================
// FIX #1: Set timezone PHP ke WIB (UTC+7)
// Tanpa ini, PHP dan MySQL bisa beda jam,
// menyebabkan hitungan denda meleset.
// ============================================
date_default_timezone_set('Asia/Jakarta');

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'peminjaman_alat');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'ToolLend');

// ============================================
// DATABASE CONNECTION
// ============================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // FIX #2: Sync timezone MySQL ke WIB agar NOW() & DATE_ADD di MySQL
            // menghasilkan waktu yang sama dengan DateTime di PHP.
            // Ini kunci agar tanggal_pinjam & tanggal_pengembalian_rencana benar.
            $pdo->exec("SET time_zone = '+07:00'");

        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            die(json_encode(['success' => false, 'message' => 'Database connection failed']));
        }
    }
    return $pdo;
}

// ============================================
// SESSION & AUTH
// ============================================
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    startSession();
    if (!isLoggedIn()) return null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT u.*, r.nama_role FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("getCurrentUser Error: " . $e->getMessage());
        return null;
    }
}

function hasRole(string ...$roles): bool {
    startSession();
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        header("Location: dashboard.php");
        exit;
    }
}

// ============================================
// UTILITIES
// ============================================
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * FIX #3: formatDate sekarang benar-benar menggunakan parameter $format.
 * Sebelumnya selalu mengabaikan $format dan tidak pernah menampilkan jam.
 *
 * Penggunaan:
 *   formatDate($date)              → "21 Feb 2026"  (tanpa jam, default)
 *   formatDate($date, 'd M Y H:i') → "21 Feb 2026 14:30"  (dengan jam)
 */
function formatDate(?string $date, string $format = 'd M Y'): string {
    if (!$date) return '-';
    try {
        $months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        $d      = new DateTime($date);
        $day    = $d->format('d');
        $month  = $months[(int)$d->format('n') - 1];
        $year   = $d->format('Y');
        $base   = "$day $month $year";

        // Tampilkan jam jika format memintanya (mengandung H atau i)
        if (strpos($format, 'H') !== false || strpos($format, 'i') !== false) {
            return $base . ' ' . $d->format('H:i');
        }
        return $base;
    } catch (Exception $e) {
        return $date;
    }
}

function formatRupiah(int $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function getStatusBadge(string $status): string {
    $map = [
        'menunggu'     => ['badge-warning',   'Menunggu'],
        'disetujui'    => ['badge-info',      'Disetujui'],
        'dipinjam'     => ['badge-primary',   'Dipinjam'],
        'dikembalikan' => ['badge-success',   'Dikembalikan'],
        'ditolak'      => ['badge-danger',    'Ditolak'],
        'dibatalkan'   => ['badge-secondary', 'Dibatalkan'],
        'terlambat'    => ['badge-danger',    'Terlambat'],
        'tersedia'     => ['badge-success',   'Tersedia'],
        'perawatan'    => ['badge-warning',   'Perawatan'],
        'baik'         => ['badge-success',       'Baik'],
        'sedang'       => ['badge-warning',       'Sedang'],
        'rusak'        => ['badge-danger',        'Rusak'],
        'rusak_ringan' => ['badge-rusak-ringan',  'Rusak Ringan'],
        'rusak_berat'  => ['badge-rusak-berat',   'Rusak Berat'],
        'hilang'       => ['badge-hilang',        'Hilang'],
        'belum_bayar'  => ['badge-danger',        'Belum Bayar'],
        'lunas'        => ['badge-success',       'Lunas'],
        'bebas'        => ['badge-success',       'Bebas Denda'],
        'perlu_perbaikan'    => ['badge-rusak-berat',  'Perlu Perbaikan'],
        'sedang_diperbaiki'  => ['badge-warning',      'Sedang Diperbaiki'],
        'sudah_diperbaiki'   => ['badge-success',      'Sudah Diperbaiki'],
    ];
    $info = $map[$status] ?? ['badge-secondary', ucfirst(str_replace('_',' ',$status))];
    return "<span class=\"badge {$info[0]}\">{$info[1]}</span>";
}

// ============================================
// FUNGSI DENDA
// ============================================

/**
 * Ambil konfigurasi persentase denda dari database
 */
function getKonfigurasiDenda(): array {
    try {
        $db = getDB();
        $rows = $db->query("SELECT kunci, nilai FROM konfigurasi_denda")->fetchAll();
        $config = [];
        foreach ($rows as $row) {
            $config[$row['kunci']] = (int)$row['nilai'];
        }
        return $config;
    } catch (Exception $e) {
        // default fallback
        return [
            'persen_rusak_ringan' => 30,
            'persen_rusak_berat'  => 75,
            'persen_hilang'       => 100,
        ];
    }
}

/**
 * Hitung total denda untuk satu peminjaman
 * Return array berisi detail komponen denda
 */
function hitungDenda(array $pinjaman, string $kondisi, array $alatData): array {
    $config = getKonfigurasiDenda();
    $hasil  = [
        'denda_keterlambatan' => 0,
        'hari_terlambat'      => 0,
        'tarif_per_hari'      => (int)$alatData['tarif_denda_per_hari'],
        'denda_kerusakan'     => 0,
        'kondisi_kerusakan'   => null,
        'persentase'          => 0,
        'harga_alat'          => (int)$alatData['harga_alat'],
        'jumlah_unit'         => (int)$pinjaman['jumlah'],
        'total_denda'         => 0,
    ];

    // --- Hitung keterlambatan ---
    if (!empty($pinjaman['tanggal_pengembalian_rencana']) && !empty($pinjaman['tanggal_pengembalian_aktual'])) {
        $rencana = new DateTime($pinjaman['tanggal_pengembalian_rencana']);
        $aktual  = new DateTime($pinjaman['tanggal_pengembalian_aktual']);

        if ($aktual > $rencana) {
            $interval  = $rencana->diff($aktual);
            $totalJam  = ($interval->days * 24) + $interval->h;
            $hariTerlambat = (int)floor($totalJam / 24); // floor = wajib 24 jam penuh per hari
            $hasil['hari_terlambat']      = $hariTerlambat;
            $hasil['denda_keterlambatan'] = $hariTerlambat * $hasil['tarif_per_hari'];
        }
    }

    // --- Hitung denda kerusakan ---
    $persen = 0;
    if ($kondisi === 'rusak_ringan') {
        $persen = $config['persen_rusak_ringan'] ?? 30;
    } elseif ($kondisi === 'rusak_berat') {
        $persen = $config['persen_rusak_berat'] ?? 75;
    } elseif ($kondisi === 'hilang') {
        $persen = $config['persen_hilang'] ?? 100;
    }

    if ($persen > 0 && $hasil['harga_alat'] > 0) {
        $hasil['kondisi_kerusakan'] = $kondisi;
        $hasil['persentase']        = $persen;
        $hasil['denda_kerusakan']   = (int)(($persen / 100) * $hasil['harga_alat'] * $hasil['jumlah_unit']);
    }

    $hasil['total_denda'] = $hasil['denda_keterlambatan'] + $hasil['denda_kerusakan'];

    return $hasil;
}

/**
 * Simpan denda ke database (dipanggil saat proses pengembalian)
 * Return true jika berhasil
 */
function simpanDenda(int $peminjamanId, array $hitungan, int $petugasId): bool {
    if ($hitungan['total_denda'] <= 0) return true;

    try {
        $db = getDB();

        if ($hitungan['denda_keterlambatan'] > 0) {
            $db->prepare("
                INSERT INTO denda
                  (peminjaman_id, jenis, hari_terlambat, tarif_per_hari,
                   jumlah_unit, total_denda, status, dikonfirmasi_oleh)
                VALUES (?, 'keterlambatan', ?, ?, ?, ?, 'belum_bayar', ?)
            ")->execute([
                $peminjamanId,
                $hitungan['hari_terlambat'],
                $hitungan['tarif_per_hari'],
                $hitungan['jumlah_unit'],
                $hitungan['denda_keterlambatan'],
                $petugasId,
            ]);
        }

        if ($hitungan['denda_kerusakan'] > 0) {
            $db->prepare("
                INSERT INTO denda
                  (peminjaman_id, jenis, kondisi_kerusakan,
                   harga_alat_snapshot, persentase,
                   jumlah_unit, total_denda, status, dikonfirmasi_oleh)
                VALUES (?, 'kerusakan', ?, ?, ?, ?, ?, 'belum_bayar', ?)
            ")->execute([
                $peminjamanId,
                $hitungan['kondisi_kerusakan'],
                $hitungan['harga_alat'],
                $hitungan['persentase'],
                $hitungan['jumlah_unit'],
                $hitungan['denda_kerusakan'],
                $petugasId,
            ]);
        }

        $db->prepare("UPDATE peminjaman SET status_denda='menunggu', hari_terlambat=? WHERE id=?")
           ->execute([$hitungan['hari_terlambat'], $peminjamanId]);

        $db->prepare("UPDATE users SET is_blocked=1 WHERE id=(SELECT user_id FROM peminjaman WHERE id=?)")
           ->execute([$peminjamanId]);

        return true;
    } catch (Exception $e) {
        error_log("simpanDenda Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Cek apakah user diblokir (ada denda belum lunas)
 */
function isUserBlocked(int $userId): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT is_blocked FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user && (int)$user['is_blocked'] === 1;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Catat riwayat kondisi alat
 */
function catatRiwayatKondisi(
    int $alatId,
    ?int $peminjamanId,
    string $kondisiBefore,
    string $kondisiAfter,
    int $stokLayakBefore,
    int $stokLayakAfter,
    string $keterangan,
    int $userId
): void {
    try {
        $db = getDB();
        $db->prepare("
            INSERT INTO riwayat_kondisi_alat
              (alat_id, peminjaman_id, kondisi_sebelum, kondisi_sesudah,
               stok_layak_sebelum, stok_layak_sesudah, keterangan, dicatat_oleh)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $alatId, $peminjamanId,
            $kondisiBefore, $kondisiAfter,
            $stokLayakBefore, $stokLayakAfter,
            $keterangan, $userId
        ]);
    } catch (Exception $e) {
        error_log("catatRiwayatKondisi Error: " . $e->getMessage());
    }
}

// ============================================
// NOTIFIKASI & LOG
// ============================================
function addNotifikasi(int $userId, string $judul, string $pesan, string $tipe = 'info'): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?,?,?,?)")
           ->execute([$userId, $judul, $pesan, $tipe]);
    } catch (Exception $e) {
        error_log("addNotifikasi Error: " . $e->getMessage());
    }
}

function getNotifikasiCount(int $userId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifikasi WHERE user_id = ? AND sudah_dibaca = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['cnt'];
    } catch (Exception $e) {
        return 0;
    }
}

function logAktivitas(?int $userId, string $aktivitas, string $detail = ''): void {
    try {
        $db  = getDB();
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $db->prepare("INSERT INTO log_aktivitas (user_id, aktivitas, detail, ip_address) VALUES (?,?,?,?)")
           ->execute([$userId, $aktivitas, $detail, $ip]);
    } catch (Exception $e) {
        error_log("logAktivitas Error: " . $e->getMessage());
    }
}
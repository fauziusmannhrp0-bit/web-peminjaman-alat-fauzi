<?php
require_once 'config.php';
startSession();
requireLogin();

$pageTitle = 'Dashboard';
$user = getCurrentUser();
$db = getDB();

$totalAlat       = $db->query("SELECT COUNT(*) as cnt FROM alat")->fetch()['cnt'];
$totalTersedia   = $db->query("SELECT COUNT(*) as cnt FROM alat WHERE status='tersedia'")->fetch()['cnt'];
$totalPeminjaman = $db->query("SELECT COUNT(*) as cnt FROM peminjaman")->fetch()['cnt'];
$totalPenunggu   = $db->query("SELECT COUNT(*) as cnt FROM peminjaman WHERE status='menunggu'")->fetch()['cnt'];
$totalUser       = $db->query("SELECT COUNT(*) as cnt FROM users WHERE role_id=3 AND status='aktif'")->fetch()['cnt'];
$totalDipinjam   = $db->query("SELECT COUNT(*) as cnt FROM peminjaman WHERE status='dipinjam'")->fetch()['cnt'];

if ($user['nama_role'] === 'user') {
    $stmt = $db->prepare("SELECT p.*, a.nama_alat FROM peminjaman p JOIN alat a ON p.alat_id = a.id WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 6");
    $stmt->execute([$user['id']]);
} else {
    $stmt = $db->prepare("SELECT p.*, a.nama_alat, u.nama as nama_user FROM peminjaman p JOIN alat a ON p.alat_id = a.id JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 6");
    $stmt->execute([]);
}
$recentPeminjaman = $stmt->fetchAll();

$alatTerbaru = $db->query("SELECT a.*, k.nama_kategori FROM alat a LEFT JOIN kategori_alat k ON a.kategori_id = k.id ORDER BY a.tanggal_tambah DESC LIMIT 4")->fetchAll();

include 'inc/header.php';
?>

<style>
/* ── Dashboard spesifik responsive ── */
.dash-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.dash-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 24px;
}
.alat-terbaru-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.alat-terbaru-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border);
    transition: var(--transition);
    min-width: 0; /* penting agar flex child tidak overflow */
}
.alat-terbaru-item:hover { background: rgba(255,255,255,0.05); }
.alat-terbaru-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg,#1a2340,#252d4a);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.alat-terbaru-icon i { color: var(--text-muted); font-size: 15px; }
.alat-terbaru-info {
    flex: 1;
    min-width: 0; /* agar text-overflow ellipsis bekerja */
}
.alat-terbaru-nama {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.alat-terbaru-sub {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}
.quick-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* ── Tabel peminjaman terbaru — sembunyikan kolom tertentu di mobile ── */
.col-hide-mobile { /* akan disembunyikan di < 480px */ }

/* ── TABLET: <= 900px ── */
@media (max-width: 900px) {
    .dash-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .dash-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }
}

/* ── MOBILE: <= 600px ── */
@media (max-width: 600px) {
    .dash-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 16px;
    }
    .stat-card { padding: 14px 12px; }
    .stat-value { font-size: 22px; }
    .stat-label { font-size: 11px; }
    .stat-icon { width: 34px; height: 34px; font-size: 14px; margin-bottom: 10px; }
    .dash-grid { gap: 12px; margin-bottom: 16px; }
    .card { padding: 14px 12px; }
    .card-header { margin-bottom: 12px; padding-bottom: 10px; }
    .page-header { margin-bottom: 16px; }
    .page-title { font-size: 20px; }
    .quick-actions .btn { font-size: 12px; padding: 8px 12px; }
    .alat-terbaru-item { padding: 9px 10px; }
    .alat-terbaru-icon { width: 36px; height: 36px; }
    .alat-terbaru-nama { font-size: 12px; }
}

/* ── SANGAT KECIL: <= 400px ── */
@media (max-width: 400px) {
    .dash-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    .stat-card { padding: 12px 10px; }
    .stat-value { font-size: 20px; }
    .quick-actions { gap: 8px; }
    .quick-actions .btn { font-size: 11.5px; padding: 7px 10px; }
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Selamat Datang, <span><?= sanitize($user['nama']) ?></span></h1>
        <p class="page-subtitle">Pantau aktivitas peminjaman alat Anda hari ini</p>
    </div>
</div>

<!-- STAT CARDS -->
<div class="dash-stats">
    <div class="stat-card s-green">
        <div class="stat-icon"><i class="fas fa-wrench"></i></div>
        <div class="stat-value"><?= $totalAlat ?></div>
        <div class="stat-label">Total Alat</div>
    </div>
    <div class="stat-card s-purple">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-value"><?= $totalTersedia ?></div>
        <div class="stat-label">Tersedia</div>
    </div>
    <div class="stat-card s-pink">
        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-value"><?= $totalPeminjaman ?></div>
        <div class="stat-label">Total Peminjaman</div>
    </div>
    <div class="stat-card s-blue">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-value"><?= $totalPenunggu ?></div>
        <div class="stat-label">Menunggu</div>
    </div>
</div>

<!-- GRID: Recent + Alat -->
<div class="dash-grid">

    <!-- RECENT PEMINJAMAN -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-history"></i> Peminjaman Terbaru</span>
            <a href="peminjaman.php" class="btn btn-secondary btn-sm">Lihat Semua</a>
        </div>
        <?php if (empty($recentPeminjaman)): ?>
            <div class="table-empty"><i class="fas fa-clipboard-list"></i><br>Belum ada peminjaman</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Alat</th>
                        <?php if ($user['nama_role'] !== 'user'): ?>
                        <th class="col-hide-mobile">User</th>
                        <?php endif; ?>
                        <th class="col-hide-mobile">Tanggal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPeminjaman as $p): ?>
                    <tr>
                        <td>
                            <div style="font-weight:500;color:var(--text-primary);font-size:12.5px">
                                <?= sanitize($p['nama_alat']) ?>
                            </div>
                            <?php if ($user['nama_role'] !== 'user'): ?>
                            <div class="col-show-mobile" style="font-size:11px;color:var(--text-muted);margin-top:2px">
                                <?= sanitize($p['nama_user']) ?>
                            </div>
                            <?php endif; ?>
                            <div class="col-show-mobile" style="font-size:11px;color:var(--text-muted);margin-top:1px">
                                <?= formatDate($p['tanggal_pinjam']) ?>
                            </div>
                        </td>
                        <?php if ($user['nama_role'] !== 'user'): ?>
                        <td class="col-hide-mobile" style="font-size:12.5px"><?= sanitize($p['nama_user']) ?></td>
                        <?php endif; ?>
                        <td class="col-hide-mobile" style="font-size:12px"><?= formatDate($p['tanggal_pinjam']) ?></td>
                        <td><?= getStatusBadge($p['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ALAT TERBARU -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-tools"></i> Alat Terbaru</span>
            <a href="alat.php" class="btn btn-secondary btn-sm">Lihat Semua</a>
        </div>
        <div class="alat-terbaru-list">
            <?php foreach ($alatTerbaru as $a): ?>
            <div class="alat-terbaru-item">
                <div class="alat-terbaru-icon">
                    <i class="fas fa-wrench"></i>
                </div>
                <div class="alat-terbaru-info">
                    <div class="alat-terbaru-nama"><?= sanitize($a['nama_alat']) ?></div>
                    <div class="alat-terbaru-sub">
                        <?= sanitize($a['nama_kategori'] ?? 'Umum') ?> &bull; Stok: <?= $a['stok'] ?>
                    </div>
                </div>
                <?= getStatusBadge($a['status']) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- QUICK ACTIONS (for user) -->
<?php if ($user['nama_role'] === 'user'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="fas fa-bolt"></i> Aksi Cepat</span>
    </div>
    <div class="quick-actions">
        <a href="alat.php" class="btn btn-primary"><i class="fas fa-search"></i> Cari Alat</a>
        <a href="peminjaman.php" class="btn btn-secondary"><i class="fas fa-clipboard-list"></i> Peminjaman Saya</a>
        <a href="pengembalian.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Pengembalian</a>
        <a href="profil.php" class="btn btn-secondary"><i class="fas fa-user-circle"></i> Profil</a>
    </div>
</div>
<?php endif; ?>

<!-- CSS untuk kolom yang disembunyikan/ditampilkan di mobile -->
<style>
.col-show-mobile { display: none; }
@media (max-width: 540px) {
    .col-hide-mobile { display: none; }
    .col-show-mobile { display: block; }
}
</style>

<?php include 'inc/footer.php'; ?>
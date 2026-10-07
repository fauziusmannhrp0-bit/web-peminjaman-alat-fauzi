<?php
require_once 'config.php';
startSession();
requireRole('admin');

$pageTitle = 'Laporan & Monitoring';
$user = getCurrentUser();
$db   = getDB();

// ── STATISTIK UTAMA ────────────────────────────────────────────
$totalAlat         = (int)$db->query("SELECT COUNT(*) FROM alat")->fetchColumn();
$totalUser         = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='aktif'")->fetchColumn();
$totalPeminjaman   = (int)$db->query("SELECT COUNT(*) FROM peminjaman")->fetchColumn();
$totalDikembalikan = (int)$db->query("SELECT COUNT(*) FROM peminjaman WHERE status='dikembalikan'")->fetchColumn();
$totalAktif        = (int)$db->query("SELECT COUNT(*) FROM peminjaman WHERE status='dipinjam'")->fetchColumn();
$totalMenunggu     = (int)$db->query("SELECT COUNT(*) FROM peminjaman WHERE status='menunggu'")->fetchColumn();
$totalUserBlokir   = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_blocked=1")->fetchColumn();
$totalDendaBelum   = (int)$db->query("SELECT COALESCE(SUM(total_denda),0) FROM denda WHERE status='belum_bayar'")->fetchColumn();
$totalDendaLunas   = (int)$db->query("SELECT COALESCE(SUM(total_denda),0) FROM denda WHERE status='lunas'")->fetchColumn();

// ── STATISTIK BULAN INI ────────────────────────────────────────
$pinjamBulanIni    = (int)$db->query("SELECT COUNT(*) FROM peminjaman WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$kembaliБulanIni   = (int)$db->query("SELECT COUNT(*) FROM peminjaman WHERE status='dikembalikan' AND MONTH(tanggal_pengembalian_aktual)=MONTH(NOW()) AND YEAR(tanggal_pengembalian_aktual)=YEAR(NOW())")->fetchColumn();
$dendaBulanIni     = (int)$db->query("SELECT COALESCE(SUM(total_denda),0) FROM denda WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

// ── DISTRIBUSI STATUS PEMINJAMAN ───────────────────────────────
$statusDist = $db->query("SELECT status, COUNT(*) as cnt FROM peminjaman GROUP BY status ORDER BY cnt DESC")->fetchAll();

// ── ALAT PALING BANYAK DIPINJAM ────────────────────────────────
$topAlat = $db->query("
    SELECT a.nama_alat, k.nama_kategori,
           COUNT(p.id) as total_pinjam,
           SUM(CASE WHEN p.status='dipinjam' THEN 1 ELSE 0 END) as sedang_dipinjam
    FROM peminjaman p
    JOIN alat a ON p.alat_id = a.id
    LEFT JOIN kategori_alat k ON a.kategori_id = k.id
    GROUP BY a.id ORDER BY total_pinjam DESC LIMIT 5
")->fetchAll();

// ── GRAFIK BULANAN (6 BULAN) ───────────────────────────────────
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $d = new DateTime(); $d->modify("-$i months");
    $m = $d->format('Y-m');
    $stmt = $db->prepare("SELECT COUNT(*) FROM peminjaman WHERE DATE_FORMAT(created_at,'%Y-%m')=?");
    $stmt->execute([$m]);
    $stmtK = $db->prepare("SELECT COUNT(*) FROM peminjaman WHERE status='dikembalikan' AND DATE_FORMAT(tanggal_pengembalian_aktual,'%Y-%m')=?");
    $stmtK->execute([$m]);
    $monthlyData[] = [
        'label'    => $d->format('M Y'),
        'pinjam'   => (int)$stmt->fetchColumn(),
        'kembali'  => (int)$stmtK->fetchColumn(),
    ];
}

// ── PEMINJAMAN AKTIF (sedang dipinjam) ─────────────────────────
$pinjamAktif = $db->query("
    SELECT p.id, p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
           p.jumlah, a.nama_alat, u.nama as nama_user, u.kelas, u.nis,
           DATEDIFF(CURDATE(), p.tanggal_pengembalian_rencana) as hari_lewat
    FROM peminjaman p
    JOIN alat a ON p.alat_id = a.id
    JOIN users u ON p.user_id = u.id
    WHERE p.status = 'dipinjam'
    ORDER BY p.tanggal_pengembalian_rencana ASC
")->fetchAll();

// ── PEMINJAMAN MENUNGGU PERSETUJUAN ────────────────────────────
$pinjamMenunggu = $db->query("
    SELECT p.id, p.created_at, p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
           p.jumlah, a.nama_alat, u.nama as nama_user, u.kelas
    FROM peminjaman p
    JOIN alat a ON p.alat_id = a.id
    JOIN users u ON p.user_id = u.id
    WHERE p.status = 'menunggu'
    ORDER BY p.created_at ASC
")->fetchAll();

// ── PENGEMBALIAN TERBARU ───────────────────────────────────────
$pengembalianTerbaru = $db->query("
    SELECT p.id, p.tanggal_pengembalian_aktual, p.kondisi_pengembalian,
           p.hari_terlambat, p.status_denda, p.jumlah,
           a.nama_alat, u.nama as nama_user, u.kelas,
           pt.nama as nama_pemeriksa
    FROM peminjaman p
    JOIN alat a ON p.alat_id = a.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users pt ON p.diperiksa_oleh = pt.id
    WHERE p.status = 'dikembalikan'
    ORDER BY p.tanggal_pengembalian_aktual DESC
    LIMIT 20
")->fetchAll();

// ── KONDISI INVENTARIS ─────────────────────────────────────────
$kondisiInventaris = $db->query("
    SELECT
        SUM(CASE WHEN kondisi='baik' THEN 1 ELSE 0 END)   as baik,
        SUM(CASE WHEN kondisi='sedang' THEN 1 ELSE 0 END) as sedang,
        SUM(CASE WHEN kondisi='rusak' THEN 1 ELSE 0 END)  as rusak,
        SUM(stok_layak) as total_layak,
        SUM(stok) as total_stok
    FROM alat
")->fetch();

// ── UNIT BERMASALAH (rusak/hilang) ─────────────────────────────
$unitBermasalah = $db->query("
    SELECT ua.kode_unit, ua.kondisi, ua.status,
           a.nama_alat, k.nama_kategori
    FROM unit_alat ua
    JOIN alat a ON ua.alat_id = a.id
    LEFT JOIN kategori_alat k ON a.kategori_id = k.id
    WHERE ua.kondisi IN ('rusak_ringan','rusak_berat','hilang')
    ORDER BY FIELD(ua.kondisi,'rusak_berat','hilang','rusak_ringan'), a.nama_alat
    LIMIT 10
")->fetchAll();

// ── LOG AKTIVITAS TERBARU ──────────────────────────────────────
$logs = $db->query("
    SELECT la.created_at, la.aktivitas, la.detail, la.ip_address,
           u.nama as nama_user, r.nama_role
    FROM log_aktivitas la
    LEFT JOIN users u ON la.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    ORDER BY la.created_at DESC LIMIT 15
")->fetchAll();

include 'inc/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laporan <span>& Monitoring</span></h1>
        <p class="page-subtitle">Pantau seluruh aktivitas sistem secara real-time</p>
    </div>
</div>

<!-- ── STATS UTAMA ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:28px">
    <div class="stat-card s-green">
        <div class="stat-icon"><i class="fas fa-wrench"></i></div>
        <div class="stat-value"><?= $totalAlat ?></div>
        <div class="stat-label">Total Alat</div>
        <div class="stat-change up"><i class="fas fa-check-circle"></i> <?= $kondisiInventaris['total_layak'] ?? 0 ?> unit layak</div>
    </div>
    <div class="stat-card s-blue">
        <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
        <div class="stat-value"><?= $totalAktif ?></div>
        <div class="stat-label">Sedang Dipinjam</div>
        <?php if ($totalMenunggu > 0): ?>
        <div class="stat-change" style="color:#ffc107"><i class="fas fa-clock"></i> <?= $totalMenunggu ?> menunggu</div>
        <?php endif; ?>
    </div>
    <div class="stat-card s-purple">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-value"><?= $totalUser ?></div>
        <div class="stat-label">User Aktif</div>
        <?php if ($totalUserBlokir > 0): ?>
        <div class="stat-change down"><i class="fas fa-ban"></i> <?= $totalUserBlokir ?> diblokir</div>
        <?php endif; ?>
    </div>
    <div class="stat-card s-pink">
        <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-value"><?= formatRupiah($totalDendaBelum) ?></div>
        <div class="stat-label">Denda Belum Lunas</div>
        <div class="stat-change up" style="color:var(--accent)"><i class="fas fa-check"></i> <?= formatRupiah($totalDendaLunas) ?> terkumpul</div>
    </div>
</div>

<!-- ── STATS BULAN INI ─────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:28px">
    <div class="card" style="padding:18px;text-align:center;border-color:rgba(77,166,255,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px">Pinjam Bulan Ini</div>
        <div style="font-size:26px;font-weight:800;color:#4da6ff;font-family:var(--font-display)"><?= $pinjamBulanIni ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">permintaan masuk</div>
    </div>
    <div class="card" style="padding:18px;text-align:center;border-color:rgba(0,212,170,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px">Kembali Bulan Ini</div>
        <div style="font-size:26px;font-weight:800;color:var(--accent);font-family:var(--font-display)"><?= $kembaliБulanIni ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">alat dikembalikan</div>
    </div>
    <div class="card" style="padding:18px;text-align:center;border-color:rgba(255,101,132,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px">Denda Bulan Ini</div>
        <div style="font-size:26px;font-weight:800;color:var(--accent3);font-family:var(--font-display)"><?= formatRupiah($dendaBulanIni) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">total diterbitkan</div>
    </div>
</div>

<!-- ── GRAFIK BULANAN ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <span class="card-title"><i class="fas fa-chart-bar"></i> Aktivitas 6 Bulan Terakhir</span>
        <div style="display:flex;gap:16px;font-size:11.5px">
            <span style="color:#4da6ff"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#4da6ff;margin-right:5px"></span>Dipinjam</span>
            <span style="color:var(--accent)"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--accent);margin-right:5px"></span>Dikembalikan</span>
        </div>
    </div>
    <?php
    $allCounts = array_merge(array_column($monthlyData,'pinjam'), array_column($monthlyData,'kembali'));
    $maxBar = $allCounts ? max($allCounts) : 1;
    if ($maxBar <= 0) $maxBar = 1;
    ?>
    <div style="display:flex;align-items:flex-end;gap:10px;height:160px;padding:0 4px">
        <?php foreach ($monthlyData as $m): ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
            <div style="flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:3px">
                <!-- Bar pinjam -->
                <div style="flex:1;border-radius:4px 4px 0 0;background:#4da6ff;
                    min-height:4px;height:<?= max(4, ($m['pinjam']/$maxBar)*100) ?>%;
                    opacity:0.9;position:relative" title="Dipinjam: <?= $m['pinjam'] ?>">
                </div>
                <!-- Bar kembali -->
                <div style="flex:1;border-radius:4px 4px 0 0;background:var(--accent);
                    min-height:4px;height:<?= max(4, ($m['kembali']/$maxBar)*100) ?>%;
                    opacity:0.9" title="Dikembalikan: <?= $m['kembali'] ?>">
                </div>
            </div>
            <div style="font-size:9.5px;color:var(--text-muted);text-align:center;white-space:nowrap"><?= $m['label'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── PEMANTAUAN PEMINJAMAN AKTIF ────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px" class="grid-responsive-lap">

    <!-- Sedang dipinjam -->
    <div class="card" style="padding:0;overflow:hidden">
        <div class="card-header" style="padding:16px 20px">
            <span class="card-title"><i class="fas fa-clipboard-list"></i> Sedang Dipinjam</span>
            <span style="font-size:12px;color:var(--text-muted)"><?= count($pinjamAktif) ?> aktif</span>
        </div>
        <?php if (empty($pinjamAktif)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">
            <i class="fas fa-check-circle" style="color:var(--accent);display:block;font-size:28px;margin-bottom:8px"></i>
            Tidak ada peminjaman aktif
        </div>
        <?php else: ?>
        <div style="max-height:320px;overflow-y:auto">
            <?php foreach ($pinjamAktif as $p):
                $terlambat = (int)$p['hari_lewat'];
            ?>
            <div style="padding:12px 20px;border-bottom:1px solid var(--border);
                        <?= $terlambat > 0 ? 'background:rgba(255,101,132,0.03)' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= sanitize($p['nama_alat']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-secondary);margin-top:1px">
                            <?= sanitize($p['nama_user']) ?>
                            <?= $p['kelas'] ? ' · '.$p['kelas'] : '' ?>
                            · <?= $p['jumlah'] ?> unit
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;margin-left:12px">
                        <?php if ($terlambat > 0): ?>
                            <span style="font-size:11px;font-weight:700;color:var(--accent3);
                                         background:rgba(255,101,132,0.1);padding:2px 8px;border-radius:20px">
                                <i class="fas fa-exclamation-circle"></i> +<?= $terlambat ?> hari
                            </span>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--text-muted)">
                                s/d <?= date('d M', strtotime($p['tanggal_pengembalian_rencana'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Menunggu persetujuan -->
    <div class="card" style="padding:0;overflow:hidden">
        <div class="card-header" style="padding:16px 20px">
            <span class="card-title"><i class="fas fa-hourglass-half"></i> Menunggu Persetujuan</span>
            <span style="font-size:12px;color:var(--text-muted)"><?= count($pinjamMenunggu) ?> antrian</span>
        </div>
        <?php if (empty($pinjamMenunggu)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">
            <i class="fas fa-check-circle" style="color:var(--accent);display:block;font-size:28px;margin-bottom:8px"></i>
            Tidak ada antrian
        </div>
        <?php else: ?>
        <div style="max-height:320px;overflow-y:auto">
            <?php foreach ($pinjamMenunggu as $p): ?>
            <div style="padding:12px 20px;border-bottom:1px solid var(--border)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= sanitize($p['nama_alat']) ?></div>
                        <div style="font-size:11.5px;color:var(--text-secondary);margin-top:1px">
                            <?= sanitize($p['nama_user']) ?>
                            <?= $p['kelas'] ? ' · '.$p['kelas'] : '' ?>
                            · <?= $p['jumlah'] ?> unit
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;margin-left:12px">
                        <span class="badge badge-warning" style="font-size:10px">Menunggu</span>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:3px">
                            <?= date('d M', strtotime($p['created_at'])) ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── PENGEMBALIAN TERBARU + KONDISI INVENTARIS ──────────── -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px" class="grid-responsive-lap">

    <!-- Pengembalian terbaru -->
    <div class="card" style="padding:0;overflow:hidden">
        <div class="card-header" style="padding:16px 20px">
            <span class="card-title"><i class="fas fa-undo"></i> Pengembalian Terbaru</span>
            <span style="font-size:12px;color:var(--text-muted)">20 terakhir</span>
        </div>
        <div class="table-wrap" style="max-height:350px;overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Alat</th>
                        <th>Kondisi</th>
                        <th>Terlambat</th>
                        <th>Denda</th>
                        <th>Diperiksa</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pengembalianTerbaru)): ?>
                    <tr><td colspan="7" class="table-empty">Belum ada data pengembalian</td></tr>
                    <?php else: ?>
                    <?php foreach ($pengembalianTerbaru as $pg):
                        $kondisiLabel = [
                            'baik'         => ['badge-success', 'Baik'],
                            'rusak_ringan' => ['badge-rusak-ringan', 'Rusak Ringan'],
                            'rusak_berat'  => ['badge-rusak-berat',  'Rusak Berat'],
                            'hilang'       => ['badge-hilang',       'Hilang'],
                        ];
                        $k = $kondisiLabel[$pg['kondisi_pengembalian']] ?? ['badge-secondary', ucfirst($pg['kondisi_pengembalian'])];
                    ?>
                    <tr>
                        <td>
                            <div style="font-size:13px;font-weight:500"><?= sanitize($pg['nama_user']) ?></div>
                            <?php if ($pg['kelas']): ?>
                            <div style="font-size:11px;color:var(--text-muted)"><?= $pg['kelas'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12.5px"><?= sanitize($pg['nama_alat']) ?></td>
                        <td><span class="badge <?= $k[0] ?>"><?= $k[1] ?></span></td>
                        <td style="font-size:12.5px">
                            <?php if ((int)$pg['hari_terlambat'] > 0): ?>
                                <span style="color:var(--accent3);font-weight:600"><?= $pg['hari_terlambat'] ?> hari</span>
                            <?php else: ?>
                                <span style="color:var(--accent);font-size:11px">Tepat waktu</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px">
                            <?php if ($pg['status_denda'] === 'belum_bayar' || $pg['status_denda'] === 'menunggu'): ?>
                                <span class="badge badge-danger" style="font-size:10px">Ada Denda</span>
                            <?php elseif ($pg['status_denda'] === 'lunas'): ?>
                                <span class="badge badge-success" style="font-size:10px">Lunas</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:11px">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11.5px;color:var(--text-secondary)"><?= $pg['nama_pemeriksa'] ? sanitize($pg['nama_pemeriksa']) : '—' ?></td>
                        <td style="font-size:11.5px;color:var(--text-muted)">
                            <?= $pg['tanggal_pengembalian_aktual'] ? date('d M Y', strtotime($pg['tanggal_pengembalian_aktual'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Kondisi inventaris + unit bermasalah -->
    <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Kondisi alat (ringkasan) -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fas fa-boxes"></i> Kondisi Inventaris</span>
            </div>
            <?php
            $ci       = $kondisiInventaris;
            $total    = max(1, (int)$ci['baik'] + (int)$ci['sedang'] + (int)$ci['rusak']);
            $items    = [
                ['label'=>'Baik',   'val'=>(int)$ci['baik'],   'color'=>'var(--accent)',  'bg'=>'rgba(0,212,170,0.15)'],
                ['label'=>'Sedang', 'val'=>(int)$ci['sedang'], 'color'=>'#ffc107',         'bg'=>'rgba(255,193,7,0.15)'],
                ['label'=>'Rusak',  'val'=>(int)$ci['rusak'],  'color'=>'var(--accent3)', 'bg'=>'rgba(255,101,132,0.15)'],
            ];
            ?>
            <div style="display:flex;flex-direction:column;gap:10px">
                <?php foreach ($items as $item): ?>
                <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:36px;height:36px;border-radius:8px;background:<?= $item['bg'] ?>;
                                display:flex;align-items:center;justify-content:center;
                                font-size:14px;font-weight:800;color:<?= $item['color'] ?>;flex-shrink:0">
                        <?= $item['val'] ?>
                    </div>
                    <div style="flex:1">
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                            <span style="font-size:12px;color:var(--text-secondary)"><?= $item['label'] ?></span>
                            <span style="font-size:11px;color:var(--text-muted)"><?= round(($item['val']/$total)*100) ?>%</span>
                        </div>
                        <div class="progress-bar-wrap" style="height:5px">
                            <div class="progress-bar" style="width:<?= round(($item['val']/$total)*100) ?>%;background:<?= $item['color'] ?>"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);
                        font-size:12px;color:var(--text-muted)">
                Total unit layak:
                <strong style="color:var(--accent)"><?= $ci['total_layak'] ?? 0 ?></strong>
                / <?= $ci['total_stok'] ?? 0 ?> total stok
            </div>
        </div>

        <!-- Unit bermasalah -->
        <?php if (!empty($unitBermasalah)): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title" style="color:var(--accent3)"><i class="fas fa-exclamation-triangle"></i> Unit Bermasalah</span>
                <span style="font-size:11px;color:var(--text-muted)"><?= count($unitBermasalah) ?> unit</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;max-height:200px;overflow-y:auto">
                <?php foreach ($unitBermasalah as $u):
                    $uc = ['rusak_ringan'=>['#ff6584','Rusak Ringan'],'rusak_berat'=>['#dc3545','Rusak Berat'],'hilang'=>['#ffc107','Hilang']][$u['kondisi']] ?? ['var(--text-muted)','?'];
                ?>
                <div style="display:flex;align-items:center;gap:8px;padding:8px;
                            border-radius:8px;background:rgba(255,255,255,0.02);
                            border:1px solid var(--glass-border)">
                    <div style="width:6px;height:6px;border-radius:50%;background:<?= $uc[0] ?>;flex-shrink:0"></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:12px;font-weight:600;font-family:monospace;
                                    color:var(--accent2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= sanitize($u['kode_unit']) ?>
                        </div>
                        <div style="font-size:10.5px;color:var(--text-muted)"><?= sanitize($u['nama_alat']) ?></div>
                    </div>
                    <span style="font-size:10px;font-weight:600;color:<?= $uc[0] ?>;flex-shrink:0"><?= $uc[1] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── TOP ALAT + DISTRIBUSI STATUS ───────────────────────── -->
<div class="grid-2" style="margin-bottom:24px">

    <!-- Alat paling dipinjam -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-trophy"></i> Alat Paling Sering Dipinjam</span>
        </div>
        <?php if (empty($topAlat)): ?>
        <div class="table-empty" style="padding:30px"><i class="fas fa-trophy"></i><br>Tidak ada data</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php
            $max0 = max(1, (int)($topAlat[0]['total_pinjam'] ?? 1));
            foreach ($topAlat as $rank => $a):
                $totalPinjam = (int)$a['total_pinjam'];
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px;
                        background:rgba(255,255,255,0.02);border-radius:8px;
                        border:1px solid var(--glass-border)">
                <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                    display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
                    <?= $rank===0?'background:rgba(255,193,7,0.15);color:#ffc107':($rank===1?'background:rgba(189,189,189,0.1);color:#bdbdbd':($rank===2?'background:rgba(205,127,50,0.1);color:#cd7f32':'background:rgba(255,255,255,0.04);color:var(--text-muted)')) ?>">
                    <?= $rank+1 ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:12.5px;font-weight:600;color:var(--text-primary);
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= sanitize($a['nama_alat']) ?>
                    </div>
                    <div style="font-size:10.5px;color:var(--text-muted)">
                        <?= $a['nama_kategori'] ?? '-' ?>
                        <?php if ((int)$a['sedang_dipinjam'] > 0): ?>
                        · <span style="color:#4da6ff"><?= $a['sedang_dipinjam'] ?> aktif</span>
                        <?php endif; ?>
                    </div>
                    <div class="progress-bar-wrap" style="margin-top:5px;height:4px">
                        <div class="progress-bar" style="width:<?= ($totalPinjam/$max0)*100 ?>%"></div>
                    </div>
                </div>
                <div style="font-size:16px;font-weight:800;color:var(--accent);font-family:var(--font-display);flex-shrink:0">
                    <?= $totalPinjam ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Distribusi status -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-chart-pie"></i> Distribusi Status Peminjaman</span>
        </div>
        <?php
        $statusColors = ['menunggu'=>'#ffc107','disetujui'=>'#6c63ff','dipinjam'=>'#4da6ff','dikembalikan'=>'#00d4aa','ditolak'=>'#ff6584','dibatalkan'=>'#5a6380'];
        $statusNames  = ['menunggu'=>'Menunggu','disetujui'=>'Disetujui','dipinjam'=>'Dipinjam','dikembalikan'=>'Dikembalikan','ditolak'=>'Ditolak','dibatalkan'=>'Dibatalkan'];
        $maxCnt = max(1, max(array_column($statusDist,'cnt') ?: [1]));
        ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($statusDist as $s):
                $cnt = (int)$s['cnt'];
                $pct = round(($cnt / $totalPeminjaman) * 100);
            ?>
            <div>
                <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                    <span style="font-size:12.5px;color:var(--text-secondary);font-weight:500">
                        <?= $statusNames[$s['status']] ?? ucfirst($s['status']) ?>
                    </span>
                    <span style="font-size:12px;color:var(--text-muted)"><?= $cnt ?> (<?= $pct ?>%)</span>
                </div>
                <div class="progress-bar-wrap" style="height:7px">
                    <div class="progress-bar" style="width:<?= ($cnt/$maxCnt)*100 ?>%;background:<?= $statusColors[$s['status']] ?? '#5a6380' ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--border);
                    text-align:center;font-size:12px;color:var(--text-muted)">
            Total semua peminjaman: <strong style="color:var(--text-primary)"><?= $totalPeminjaman ?></strong>
        </div>
    </div>

</div>

<!-- ── LOG AKTIVITAS ───────────────────────────────────────── -->
<div class="card" style="padding:0;overflow:hidden">
    <div class="card-header" style="padding:16px 20px">
        <span class="card-title"><i class="fas fa-list-alt"></i> Log Aktivitas Terbaru</span>
        <span style="font-size:12px;color:var(--text-muted)">15 terakhir</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Aktivitas</th>
                    <th>Detail</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="table-empty">Tidak ada log aktivitas</td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log):
                    $roleColor = ['admin'=>'var(--accent)','petugas'=>'var(--accent2)','user'=>'var(--accent3)'][$log['nama_role'] ?? ''] ?? 'var(--text-muted)';
                ?>
                <tr>
                    <td style="font-size:11.5px;color:var(--text-muted);white-space:nowrap">
                        <?= date('d M y H:i', strtotime($log['created_at'])) ?>
                    </td>
                    <td style="font-size:12.5px;font-weight:500"><?= sanitize($log['nama_user'] ?? '—') ?></td>
                    <td>
                        <span style="font-size:10.5px;font-weight:600;color:<?= $roleColor ?>">
                            <?= ucfirst($log['nama_role'] ?? '—') ?>
                        </span>
                    </td>
                    <td style="font-size:12.5px"><?= sanitize($log['aktivitas']) ?></td>
                    <td style="font-size:11.5px;color:var(--text-muted);max-width:200px;
                               overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= sanitize($log['detail'] ?? '—') ?>
                    </td>
                    <td style="font-size:11px;color:var(--text-muted);font-family:monospace">
                        <?= sanitize($log['ip_address'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
/* Grid 1 kolom saat mobile */
@media (max-width:900px) {
    .grid-responsive-lap { grid-template-columns:1fr !important; }
}
</style>

<?php include 'inc/footer.php'; ?>
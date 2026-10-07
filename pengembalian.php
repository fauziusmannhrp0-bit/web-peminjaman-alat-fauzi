<?php
// ============================================================
// pengembalian.php
// Flow: Request (user) → Konfirmasi (admin) → Cek Barang (admin) → Selesai
// ============================================================
require_once 'config.php';
startSession();
requireLogin();

$pageTitle = 'Pengembalian';
$user      = getCurrentUser();
$db        = getDB();

$statusFilter = $_GET['status'] ?? '';

$subDenda = "(SELECT COALESCE(SUM(total_denda),0) FROM denda WHERE peminjaman_id = p.id)";
$subUnitDetail = "(SELECT GROUP_CONCAT(
                     CONCAT(ua.kode_unit, ':', pu.kondisi_kembali)
                     ORDER BY ua.kode_unit ASC SEPARATOR '|'
                  ) FROM peminjaman_unit pu
                   JOIN unit_alat ua ON ua.id = pu.unit_id
                   WHERE pu.peminjaman_id = p.id AND pu.kondisi_kembali IS NOT NULL
                 )";

if ($user['nama_role'] === 'user') {
    $where  = ['p.user_id = ?']; $params = [$user['id']];
    if ($statusFilter) { $where[] = 'pg.status = ?'; $params[] = $statusFilter; }

    $stmt = $db->prepare("
        SELECT pg.*, p.jumlah, p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
               p.tanggal_pengembalian_aktual, p.status AS status_peminjaman,
               p.kondisi_pengembalian, p.catatan_pengembalian, p.diperiksa_oleh,
               p.hari_terlambat, p.status_denda,
               $subDenda AS total_denda_user, $subUnitDetail AS detail_kondisi_unit,
               a.nama_alat, a.harga_alat, a.tarif_denda_per_hari,
               u_periksa.nama AS nama_pemeriksa, u_konfirm.nama AS nama_pengkonfirmasi
        FROM pengembalian pg
        JOIN peminjaman p  ON pg.peminjaman_id = p.id
        JOIN alat a        ON p.alat_id = a.id
        LEFT JOIN users u_periksa  ON pg.diperiksa_oleh   = u_periksa.id
        LEFT JOIN users u_konfirm  ON pg.dikonfirmasi_oleh = u_konfirm.id
        WHERE " . implode(' AND ', $where) . " ORDER BY pg.created_at DESC
    ");
    $stmt->execute($params);
    $listPengembalian = $stmt->fetchAll();

    $stmtDipinjam = $db->prepare("
        SELECT p.id, p.jumlah, p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
               p.ada_request_pengembalian, a.nama_alat
        FROM peminjaman p JOIN alat a ON p.alat_id = a.id
        WHERE p.user_id = ? AND p.status = 'dipinjam'
        ORDER BY p.tanggal_pengembalian_rencana ASC
    ");
    $stmtDipinjam->execute([$user['id']]);
    $listDipinjam = $stmtDipinjam->fetchAll();

} else {
    $where = []; $params = [];
    if ($statusFilter) { $where[] = 'pg.status = ?'; $params[] = $statusFilter; }
    $whereStr = !empty($where) ? ' WHERE '.implode(' AND ',$where) : '';

    $stmt = $db->prepare("
        SELECT pg.*, p.jumlah, p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
               p.tanggal_pengembalian_aktual, p.status AS status_peminjaman,
               p.kondisi_pengembalian, p.catatan_pengembalian, p.diperiksa_oleh,
               p.hari_terlambat, p.status_denda,
               $subDenda AS total_denda_user, $subUnitDetail AS detail_kondisi_unit,
               a.nama_alat, a.harga_alat, a.tarif_denda_per_hari,
               u_user.nama AS nama_user, u_user.username,
               u_periksa.nama AS nama_pemeriksa, u_konfirm.nama AS nama_pengkonfirmasi
        FROM pengembalian pg
        JOIN peminjaman p      ON pg.peminjaman_id = p.id
        JOIN alat a            ON p.alat_id = a.id
        JOIN users u_user      ON pg.user_id = u_user.id
        LEFT JOIN users u_periksa  ON pg.diperiksa_oleh   = u_periksa.id
        LEFT JOIN users u_konfirm  ON pg.dikonfirmasi_oleh = u_konfirm.id
        $whereStr ORDER BY pg.created_at DESC
    ");
    $stmt->execute($params);
    $listPengembalian = $stmt->fetchAll();
    $listDipinjam     = [];
}

// Status counts
$statusList = ['menunggu','dikonfirmasi','selesai','dibatalkan'];
$statusCounts = [];
foreach ($statusList as $s) {
    if ($user['nama_role'] === 'user') {
        $sc = $db->prepare("SELECT COUNT(*) FROM pengembalian WHERE user_id = ? AND status = ?");
        $sc->execute([$user['id'], $s]);
    } else {
        $sc = $db->prepare("SELECT COUNT(*) FROM pengembalian WHERE status = ?");
        $sc->execute([$s]);
    }
    $statusCounts[$s] = (int)$sc->fetchColumn();
}

// Build print data
$printDataPengembalian = [];
foreach ($listPengembalian as $pg) {
    $terlambat = false; $hariTelat = 0;
    if (!empty($pg['tanggal_pengembalian_rencana'])) {
        try {
            $jt = new DateTime($pg['tanggal_pengembalian_rencana']); $sk = new DateTime();
            $terlambat = ($pg['status_peminjaman'] === 'dipinjam' && $sk > $jt);
            if ($terlambat) $hariTelat = (int)$sk->diff($jt)->days;
        } catch (Exception $e) {}
    }
    $printDataPengembalian[] = [
        'nama_alat'       => $pg['nama_alat'],
        'nama_user'       => $pg['nama_user'] ?? null,
        'username'        => $pg['username']  ?? null,
        'jumlah'          => $pg['jumlah'],
        'tgl_pinjam'      => !empty($pg['tanggal_pinjam'])
                                ? (new DateTime($pg['tanggal_pinjam']))->format('d M Y H:i') : '-',
        'jatuh_tempo'     => !empty($pg['tanggal_pengembalian_rencana'])
                                ? (new DateTime($pg['tanggal_pengembalian_rencana']))->format('d M Y H:i') : '-',
        'tgl_kembali'     => !empty($pg['tanggal_pengembalian_aktual'])
                                ? (new DateTime($pg['tanggal_pengembalian_aktual']))->format('d M Y H:i') : '-',
        'status'          => $pg['status'],
        'kondisi'         => $pg['kondisi_pengembalian'] ?? null,
        'hari_terlambat'  => (int)($pg['hari_terlambat'] ?? 0),
        'status_denda'    => $pg['status_denda'] ?? null,
        'total_denda'     => (int)($pg['total_denda_user'] ?? 0),
        'terlambat'       => $terlambat,
        'hari_telat'      => $hariTelat,
    ];
}

$statusLabelCetak = $statusFilter
    ? ucfirst(str_replace('_',' ',$statusFilter))
    : 'Semua Status';

include 'inc/header.php';

function badgePengembalian(string $status): string {
    $map = [
        'menunggu'     => ['#ffc107', 'rgba(255,193,7,0.12)',   'Menunggu'],
        'dikonfirmasi' => ['var(--accent2)', 'rgba(108,99,255,0.12)', 'Dikonfirmasi'],
        'selesai'      => ['var(--accent)', 'rgba(0,212,170,0.12)',  'Selesai'],
        'dibatalkan'   => ['var(--text-muted)', 'rgba(255,255,255,0.06)', 'Dibatalkan'],
    ];
    [$color, $bg, $label] = $map[$status] ?? ['var(--text-muted)', 'rgba(255,255,255,0.06)', $status];
    return "<span style=\"background:{$bg};color:{$color};font-weight:700;
                padding:3px 10px;border-radius:20px;font-size:12px\">{$label}</span>";
}
?>

<style>
.kondisi-tooltip-wrap:hover .kondisi-detail-bubble,
.kondisi-tooltip-wrap:focus-within .kondisi-detail-bubble {
    display:block !important;
    animation:fadeInUp .15s ease;
}
@keyframes fadeInUp {
    from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)}
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengembalian <span>Alat</span></h1>
        <p class="page-subtitle">
            <?= $user['nama_role'] === 'user'
                ? 'Ajukan request pengembalian, lalu datang setelah dikonfirmasi petugas'
                : 'Konfirmasi request dan lakukan pengecekan kondisi alat' ?>
        </p>
    </div>
</div>

<!-- KARTU ALAT DIPINJAM — hanya user -->
<?php if ($user['nama_role'] === 'user' && !empty($listDipinjam)): ?>
<div class="card" style="margin-bottom:24px">
    <div style="padding:18px 20px 14px;border-bottom:1px solid rgba(255,255,255,0.06)">
        <h3 style="margin:0;font-size:15px;font-weight:700">
            <i class="fas fa-hand-holding" style="color:var(--accent);margin-right:8px"></i>
            Alat yang Sedang Anda Pinjam
        </h3>
        <p style="margin:5px 0 0;font-size:12px;color:var(--text-muted)">
            Klik <strong>Request Pengembalian</strong> untuk mengajukan ke petugas,
            kemudian datang langsung setelah dikonfirmasi.
        </p>
    </div>
    <div style="padding:16px;display:grid;gap:10px">
        <?php foreach ($listDipinjam as $dp):
            $jatuhTempoDp = null; $terlambatDp = false;
            if (!empty($dp['tanggal_pengembalian_rencana'])) {
                try {
                    $jatuhTempoDp = new DateTime($dp['tanggal_pengembalian_rencana']);
                    $terlambatDp  = new DateTime() > $jatuhTempoDp;
                } catch (Exception $e) {}
            }
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
                    padding:14px 16px;background:rgba(255,255,255,0.03);
                    border:1px solid rgba(255,255,255,<?= $terlambatDp ? '0.14' : '0.06' ?>);
                    border-radius:12px;<?= $terlambatDp ? 'box-shadow:0 0 0 1px rgba(220,53,69,0.2);' : '' ?>">
            <div style="flex:1;min-width:180px">
                <div style="font-weight:600;font-size:14px"><?= sanitize($dp['nama_alat']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
                    <?= $dp['jumlah'] ?> unit &nbsp;|&nbsp;
                    Dipinjam: <?= !empty($dp['tanggal_pinjam']) ? formatDate($dp['tanggal_pinjam'], 'd M Y H:i') : '-' ?>
                </div>
                <div style="font-size:12px;margin-top:3px;
                            color:<?= $terlambatDp ? 'var(--accent3)' : 'var(--text-muted)' ?>;
                            font-weight:<?= $terlambatDp ? '600' : '400' ?>">
                    <i class="fas fa-<?= $terlambatDp ? 'exclamation-triangle' : 'calendar-alt' ?>" style="margin-right:4px"></i>
                    Jatuh tempo: <?= $jatuhTempoDp ? formatDate($dp['tanggal_pengembalian_rencana'], 'd M Y H:i') : '-' ?>
                    <?php if ($terlambatDp && $jatuhTempoDp): ?>
                        <span style="margin-left:6px;padding:2px 8px;background:rgba(220,53,69,0.15);color:#dc3545;
                                     border-radius:20px;font-size:11px;font-weight:700">
                            Terlambat <?= (new DateTime())->diff($jatuhTempoDp)->days ?> hari
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <?php if ($dp['ada_request_pengembalian']): ?>
                    <span style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;
                                 background:rgba(255,193,7,0.1);color:#ffc107;
                                 border:1px solid rgba(255,193,7,0.25);border-radius:9px;
                                 font-size:12px;font-weight:600">
                        <i class="fas fa-clock"></i> Request Terkirim — Tunggu Konfirmasi
                    </span>
                <?php else: ?>
                    <button class="btn btn-primary"
                            onclick="openModalRequest(<?= $dp['id'] ?>, '<?= addslashes(sanitize($dp['nama_alat'])) ?>')">
                        <i class="fas fa-undo"></i> Request Pengembalian
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif ($user['nama_role'] === 'user'): ?>
<div style="margin-bottom:20px;padding:13px 16px;background:rgba(0,212,170,0.06);
            border:1px solid rgba(0,212,170,0.15);border-radius:12px;
            display:flex;align-items:center;gap:10px;font-size:13px;color:var(--text-secondary)">
    <i class="fas fa-check-circle" style="color:var(--accent)"></i>
    Anda tidak sedang meminjam alat apapun saat ini.
    <a href="peminjaman.php" style="color:var(--accent2);text-decoration:underline;margin-left:4px">Lihat riwayat peminjaman →</a>
</div>
<?php endif; ?>

<!-- FILTER STATUS -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
    <a href="pengembalian.php" class="btn btn-sm <?= !$statusFilter ? 'btn-primary' : 'btn-secondary' ?>">
        Semua <span style="opacity:.6">(<?= array_sum($statusCounts) ?>)</span>
    </a>
    <?php $filterLabels = [
        'menunggu'     => ['fa-clock',        'Menunggu'],
        'dikonfirmasi' => ['fa-check-circle', 'Dikonfirmasi'],
        'selesai'      => ['fa-check-double', 'Selesai'],
        'dibatalkan'   => ['fa-times-circle', 'Dibatalkan'],
    ];
    foreach ($filterLabels as $s => [$icon, $label]): ?>
    <a href="pengembalian.php?status=<?= $s ?>"
       class="btn btn-sm <?= $statusFilter === $s ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fas <?= $icon ?>"></i> <?= $label ?>
        <span style="opacity:.6">(<?= $statusCounts[$s] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- SEARCH + CETAK -->
<div class="toolbar">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="form-control"
               placeholder="Cari nama alat<?= $user['nama_role'] !== 'user' ? ' atau nama user' : '' ?>...">
    </div>
    <span style="font-size:12px;color:var(--text-muted)"><?= count($listPengembalian) ?> record</span>

    <!-- TOMBOL CETAK TABEL -->
    <button onclick="cetakTabelPengembalian()"
            style="display:inline-flex;align-items:center;gap:8px;
                   padding:9px 18px;border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;
                   background:linear-gradient(135deg,rgba(0,212,170,0.15),rgba(108,99,255,0.1));
                   border:1.5px solid rgba(0,212,170,0.4);color:var(--accent);
                   transition:all .2s;white-space:nowrap"
            onmouseover="this.style.background='linear-gradient(135deg,rgba(0,212,170,0.25),rgba(108,99,255,0.15))'"
            onmouseout="this.style.background='linear-gradient(135deg,rgba(0,212,170,0.15),rgba(108,99,255,0.1))'">
        <i class="fas fa-print" style="font-size:15px"></i>
        Cetak Tabel
        <span style="font-size:11px;opacity:.7;font-weight:500">(<?= count($listPengembalian) ?>)</span>
    </button>
</div>

<!-- TABEL -->
<div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Alat</th>
                    <?php if ($user['nama_role'] !== 'user'): ?><th>Peminjam</th><?php endif; ?>
                    <th>Jumlah</th>
                    <th>Tgl Pinjam</th>
                    <th>Jatuh Tempo</th>
                    <th>Status</th>
                    <th>Denda</th>
                    <th style="width:200px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listPengembalian)): ?>
                <tr>
                    <td colspan="9" class="table-empty">
                        <i class="fas fa-box-open"></i><br>
                        <?= $user['nama_role'] === 'user'
                            ? 'Belum ada riwayat pengembalian. Gunakan tombol di atas untuk mengajukan request.'
                            : 'Tidak ada data pengembalian' ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($listPengembalian as $i => $pg):
                    $jatuhTempo = null; $terlambat = false;
                    if (!empty($pg['tanggal_pengembalian_rencana'])) {
                        try {
                            $jatuhTempo = new DateTime($pg['tanggal_pengembalian_rencana']);
                            $sekarang   = new DateTime();
                            $terlambat  = ($pg['status_peminjaman'] === 'dipinjam' && $sekarang > $jatuhTempo);
                        } catch (Exception $e) {}
                    }
                    $detailUnit = []; $ringkasanUnit = ['baik'=>0,'rusak_ringan'=>0,'rusak_berat'=>0,'hilang'=>0];
                    if (!empty($pg['detail_kondisi_unit'])) {
                        foreach (explode('|', $pg['detail_kondisi_unit']) as $item) {
                            $parts = explode(':', $item, 2);
                            if (count($parts) === 2) {
                                $detailUnit[] = ['kode'=>$parts[0],'kondisi'=>$parts[1]];
                                if (isset($ringkasanUnit[$parts[1]])) $ringkasanUnit[$parts[1]]++;
                            }
                        }
                    }
                    $adaVariasi = count(array_filter($ringkasanUnit)) > 1;
                    $tooltipLines = [];
                    if ($ringkasanUnit['baik']>0)        $tooltipLines[] = $ringkasanUnit['baik'].' unit baik';
                    if ($ringkasanUnit['rusak_ringan']>0) $tooltipLines[] = $ringkasanUnit['rusak_ringan'].' rusak ringan';
                    if ($ringkasanUnit['rusak_berat']>0)  $tooltipLines[] = $ringkasanUnit['rusak_berat'].' rusak berat';
                    if ($ringkasanUnit['hilang']>0)       $tooltipLines[] = $ringkasanUnit['hilang'].' hilang';
                ?>
                <tr class="filterable-row">
                    <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
                    <td style="font-weight:500;color:var(--text-primary)"><?= sanitize($pg['nama_alat']) ?></td>
                    <?php if ($user['nama_role'] !== 'user'): ?>
                    <td>
                        <div style="font-size:12.5px;font-weight:500"><?= sanitize($pg['nama_user']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">@<?= sanitize($pg['username']) ?></div>
                    </td>
                    <?php endif; ?>
                    <td><?= $pg['jumlah'] ?> unit</td>
                    <td><?= !empty($pg['tanggal_pinjam']) ? formatDate($pg['tanggal_pinjam'], 'd M Y H:i') : '-' ?></td>
                    <td>
                        <?php if ($jatuhTempo !== null): ?>
                            <span style="color:<?= $terlambat ? 'var(--accent3)' : 'inherit' ?>;font-weight:<?= $terlambat ? '600' : 'normal' ?>">
                                <?= formatDate($pg['tanggal_pengembalian_rencana'], 'd M Y H:i') ?>
                            </span>
                            <?php if ($terlambat): ?>
                                <div style="font-size:10.5px;color:var(--accent3)">
                                    <i class="fas fa-exclamation-triangle"></i> Terlambat <?= $sekarang->diff($jatuhTempo)->days ?> hari
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:11px">-</span>
                        <?php endif; ?>
                        <?php if (!empty($pg['tanggal_pengembalian_aktual'])): ?>
                            <div style="font-size:10.5px;color:var(--accent)">
                                Dikembalikan: <?= formatDate($pg['tanggal_pengembalian_aktual'], 'd M Y H:i') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= badgePengembalian($pg['status']) ?>
                        <?php if ($pg['status'] === 'selesai' && $pg['kondisi_pengembalian']): ?>
                            <br>
                            <?php if (count($detailUnit) > 1 && $adaVariasi): ?>
                                <span class="kondisi-tooltip-wrap" style="position:relative;display:inline-block">
                                    <?= getStatusBadge($pg['kondisi_pengembalian']) ?>
                                    <span class="kondisi-detail-bubble" style="
                                        display:none;position:absolute;bottom:calc(100% + 6px);left:0;
                                        background:#1e1e2e;border:1px solid rgba(255,255,255,0.12);
                                        border-radius:10px;padding:10px 13px;min-width:180px;
                                        box-shadow:0 8px 24px rgba(0,0,0,0.4);z-index:999;white-space:nowrap">
                                        <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:.5px">Detail per Unit</div>
                                        <?php foreach ($detailUnit as $du):
                                            $warna = match($du['kondisi']) { 'baik'=>'var(--accent)', 'rusak_ringan'=>'#ff6584', 'rusak_berat'=>'#dc3545', 'hilang'=>'#ffc107', default=>'var(--text-muted)' };
                                        ?>
                                            <div style="display:flex;justify-content:space-between;gap:16px;margin-bottom:4px;font-size:12px">
                                                <span style="font-family:monospace;color:var(--accent2);font-weight:600"><?= htmlspecialchars($du['kode']) ?></span>
                                                <span style="color:<?= $warna ?>;font-weight:600"><?= ucfirst(str_replace('_',' ',$du['kondisi'])) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <div style="margin-top:8px;padding-top:7px;border-top:1px solid rgba(255,255,255,0.08);font-size:11px;color:var(--text-muted)"><?= htmlspecialchars(implode(', ',$tooltipLines)) ?></div>
                                    </span>
                                </span>
                            <?php else: ?>
                                <?= getStatusBadge($pg['kondisi_pengembalian']) ?>
                            <?php endif; ?>
                        <?php elseif ($pg['status'] === 'dikonfirmasi'): ?>
                            <div style="font-size:11px;color:var(--accent);margin-top:3px">
                                <i class="fas fa-check-circle"></i> Siap dicek petugas
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($pg['status_denda'] === 'menunggu'): ?>
                            <span class="badge badge-danger">Rp <?= number_format($pg['total_denda_user'],0,',','.') ?></span>
                            <?php if ($user['nama_role'] !== 'user'): ?>
                            <br><button class="btn btn-sm btn-primary" style="margin-top:4px;font-size:11px"
                                onclick="konfirmasiLunas(<?= $pg['peminjaman_id'] ?>, '<?= sanitize($pg['nama_user']) ?>')">
                                <i class="fas fa-check"></i> Lunas
                            </button>
                            <?php endif; ?>
                        <?php elseif ($pg['status_denda'] === 'lunas'): ?>
                            <?= getStatusBadge('lunas') ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px">--</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <?php if ($user['nama_role'] === 'user'): ?>
                                <?php if ($pg['status'] === 'menunggu'): ?>
                                    <button class="btn btn-sm btn-danger"
                                            onclick="batalkanRequest(<?= $pg['id'] ?>, '<?= addslashes(sanitize($pg['nama_alat'])) ?>')">
                                        <i class="fas fa-times"></i> Batalkan
                                    </button>
                                <?php elseif ($pg['status'] === 'dikonfirmasi'): ?>
                                    <span style="font-size:12px;color:var(--accent);font-weight:600;display:inline-flex;align-items:center;gap:5px">
                                        <i class="fas fa-walking"></i> Segera datang ke petugas
                                    </span>
                                <?php elseif ($pg['status'] === 'selesai'): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="lihatDetail(<?= $pg['id'] ?>)">
                                        <i class="fas fa-info-circle"></i> Detail
                                    </button>
                                    <?php if (!empty($pg['diperiksa_oleh'])): ?>
                                    <button type="button" class="btn btn-sm btn-secondary"
                                            onclick="window.open('cetak_laporan.php?pengembalian_id=<?= (int)$pg['id'] ?>','_blank')">
                                        <i class="fas fa-file-pdf"></i> Cetak
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($pg['status'] === 'menunggu'): ?>
                                    <button class="btn btn-sm btn-primary"
                                            onclick="konfirmasiRequest(<?= $pg['id'] ?>, '<?= addslashes(sanitize($pg['nama_user'])) ?>', '<?= addslashes(sanitize($pg['nama_alat'])) ?>')">
                                        <i class="fas fa-check"></i> Konfirmasi
                                    </button>
                                    <button class="btn btn-sm btn-danger"
                                            onclick="tolakRequest(<?= $pg['id'] ?>, '<?= addslashes(sanitize($pg['nama_alat'])) ?>')">
                                        <i class="fas fa-ban"></i> Tolak
                                    </button>
                                <?php elseif ($pg['status'] === 'dikonfirmasi'): ?>
                                    <button class="btn btn-sm btn-warning"
                                            onclick="openFormCekBarang(<?= $pg['id'] ?>, '<?= addslashes(sanitize($pg['nama_alat'])) ?>', <?= (int)$pg['jumlah'] ?>)">
                                        <i class="fas fa-clipboard-check"></i> Cek Barang
                                    </button>
                                    <button class="btn btn-sm btn-danger"
                                            onclick="tolakRequest(<?= $pg['id'] ?>, '<?= addslashes(sanitize($pg['nama_alat'])) ?>')">
                                        <i class="fas fa-times"></i> Batal
                                    </button>
                                <?php elseif ($pg['status'] === 'selesai'): ?>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="lihatDetail(<?= $pg['id'] ?>)">
                                        <i class="fas fa-info-circle"></i> Detail
                                    </button>
                                    <?php if (!empty($pg['diperiksa_oleh'])): ?>
                                    <button type="button" class="btn btn-sm btn-secondary"
                                            onclick="window.open('cetak_laporan.php?pengembalian_id=<?= (int)$pg['id'] ?>','_blank')">
                                        <i class="fas fa-file-pdf"></i> Cetak
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'inc/footer.php'; ?>

<script>
/* ── DATA CETAK ── */
const _DP = <?= json_encode($printDataPengembalian, JSON_UNESCAPED_UNICODE) ?>;
const _MP = {
    role        : <?= json_encode($user['nama_role']) ?>,
    nama        : <?= json_encode($user['nama']) ?>,
    filter      : <?= json_encode($statusLabelCetak) ?>,
    total       : <?= count($listPengembalian) ?>,
    tanggal     : <?= json_encode(date('d M Y, H:i')) ?>,
    namaInstansi: 'Sistem Peminjaman Alat Praktikum',
};

/* ══════════════════════════════════════════════════════
   FUNGSI CETAK TABEL PENGEMBALIAN
   ══════════════════════════════════════════════════════ */
/**
 * GANTI fungsi cetakTabelPengembalian() di pengembalian.php
 * dengan fungsi di bawah ini.
 *
 * Perubahan:
 *  - Tampilan disederhanakan (hapus gradasi header, ringkasan tetap ada)
 *  - Buka tab baru, auto-print
 *  - Jika user klik Batal pada dialog print → tetap di tab baru
 */

function cetakTabelPengembalian() {
    const isAdmin = _MP.role !== 'user';

    const ST_PG = {
        menunggu     : 'Menunggu',
        dikonfirmasi : 'Dikonfirmasi',
        selesai      : 'Selesai',
        dibatalkan   : 'Dibatalkan',
    };
    const KONDISI_LABEL = {
        baik         : 'Baik',
        rusak_ringan : 'Rusak Ringan',
        rusak_berat  : 'Rusak Berat',
        hilang       : 'Hilang',
    };

    /* hitung ringkasan */
    const countPg = {}; let totalDendaAll = 0; let totalBermasalah = 0;
    _DP.forEach(d => {
        countPg[d.status] = (countPg[d.status]||0)+1;
        totalDendaAll += d.total_denda || 0;
        if (d.kondisi && d.kondisi !== 'baik') totalBermasalah++;
    });

    /* baris tabel */
    const rowsHtml = _DP.map((d, i) => {
        const peminjamCell = isAdmin
            ? `<td>${escapeHtml(d.nama_user || '-')}<br><small>@${escapeHtml(d.username || '')}</small></td>`
            : '';

        const jatuhTempo = d.jatuh_tempo === '-'
            ? '-'
            : d.terlambat
                ? `${d.jatuh_tempo}<br><small style="color:red">⚠ ${d.hari_telat} hari terlambat</small>`
                : d.jatuh_tempo;

        const tglKembali = d.tgl_kembali !== '-'
            ? `<strong>${d.tgl_kembali}</strong>`
            : '<em>Belum</em>';

        const kondisi = d.kondisi ? (KONDISI_LABEL[d.kondisi] || d.kondisi) : '-';

        const denda = d.total_denda > 0
            ? (d.status_denda === 'lunas'
                ? `✓ Lunas`
                : `Rp ${d.total_denda.toLocaleString('id-ID')}`)
            : '-';

        return `<tr>
            <td style="text-align:center">${i + 1}</td>
            <td>${escapeHtml(d.nama_alat)}</td>
            ${peminjamCell}
            <td style="text-align:center">${d.jumlah} unit</td>
            <td>${d.tgl_pinjam === '-' ? '-' : d.tgl_pinjam}</td>
            <td>${jatuhTempo}</td>
            <td>${tglKembali}</td>
            <td>${ST_PG[d.status] || d.status}</td>
            <td>${kondisi}</td>
            <td>${denda}</td>
        </tr>`;
    }).join('') || `<tr><td colspan="${isAdmin ? 10 : 9}" style="text-align:center;padding:20px">Tidak ada data</td></tr>`;

    const thPeminjam = isAdmin ? '<th>Peminjam</th>' : '';

    /* ringkasan status */
    const ringkasanHtml = Object.entries(countPg)
        .map(([s, n]) => `<span>${ST_PG[s] || s}: <strong>${n}</strong></span>`)
        .join(' &nbsp;|&nbsp; ');

    const html = `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Pengembalian</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 13px; color: #111; background: #fff; padding: 24px; }
  h2 { font-size: 17px; margin-bottom: 4px; }
  .meta { font-size: 12px; color: #555; margin-bottom: 14px; }
  .ringkasan { font-size: 12px; color: #333; margin-bottom: 14px; padding: 8px 12px;
               border: 1px solid #ddd; border-radius: 6px; background: #f9f9f9; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th { background: #333; color: #fff; padding: 8px 10px; text-align: left; font-size: 12px; }
  td { padding: 7px 10px; border-bottom: 1px solid #e0e0e0; vertical-align: top; font-size: 12px; }
  tr:nth-child(even) td { background: #f5f5f5; }
  small { font-size: 10.5px; }
  .footer { margin-top: 18px; font-size: 11px; color: #888; }
  @media print {
    @page { margin: 12mm; size: A4 landscape; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; padding: 0; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>

<h2>Laporan Pengembalian Alat</h2>
<div class="meta">
  Filter: <strong>${escapeHtml(_MP.filter)}</strong> &nbsp;|&nbsp;
  Dicetak: <strong>${escapeHtml(_MP.tanggal)}</strong> &nbsp;|&nbsp;
  Oleh: <strong>${escapeHtml(_MP.nama)}</strong> &nbsp;|&nbsp;
  Total: <strong>${_MP.total} record</strong>
  ${totalDendaAll > 0 ? ` &nbsp;|&nbsp; <span style="color:red">Total Denda: <strong>Rp ${totalDendaAll.toLocaleString('id-ID')}</strong></span>` : ''}
  ${totalBermasalah > 0 ? ` &nbsp;|&nbsp; <span style="color:orange">Bermasalah: <strong>${totalBermasalah}</strong></span>` : ''}
</div>

<div class="ringkasan">
  Ringkasan: &nbsp; ${ringkasanHtml}
</div>

<table>
  <thead>
    <tr>
      <th style="width:4%">#</th>
      <th>Alat</th>
      ${thPeminjam}
      <th style="width:7%">Jumlah</th>
      <th style="width:13%">Tgl Pinjam</th>
      <th style="width:14%">Jatuh Tempo</th>
      <th style="width:14%">Tgl Kembali</th>
      <th style="width:10%">Status</th>
      <th style="width:10%">Kondisi</th>
      <th style="width:10%">Denda</th>
    </tr>
  </thead>
  <tbody>
    ${rowsHtml}
  </tbody>
</table>

<div class="footer">
  ${escapeHtml(_MP.namaInstansi)} — Dokumen ini dicetak otomatis.
</div>

<script>
  window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 400);
  });
<\/script>
</body>
</html>`;

    const w = window.open('', '_blank');
    if (!w) {
        alert('Popup diblokir browser — izinkan popup untuk halaman ini lalu coba lagi.');
        return;
    }
    w.document.open();
    w.document.write(html);
    w.document.close();
}

// ══════════════════════════════════════════════════════════
// Semua fungsi JS dari versi sebelumnya (tidak berubah)
// ══════════════════════════════════════════════════════════
function openModalRequest(peminjamanId, namaAlat) {
    openModal('Request Pengembalian — ' + namaAlat, `
        <div style="margin-bottom:18px;padding:14px 16px;background:rgba(108,99,255,0.08);border:1px solid rgba(108,99,255,0.2);border-radius:12px;display:flex;align-items:flex-start;gap:12px">
            <i class="fas fa-undo" style="font-size:22px;color:var(--accent2);margin-top:2px;flex-shrink:0"></i>
            <div>
                <div style="font-weight:600;font-size:14px;margin-bottom:5px">${namaAlat}</div>
                <div style="font-size:12.5px;color:var(--text-muted);line-height:1.7">
                    Setelah request dikirim, tunggu konfirmasi dari petugas.<br>
                    Kemudian <strong>datang langsung</strong> ke tempat pengembalian untuk menyerahkan alat secara fisik.
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Catatan untuk Petugas <span style="color:var(--text-muted);font-size:11px">(opsional)</span></label>
            <textarea id="catatanRequestUser" class="form-control" rows="3" placeholder="Contoh: kondisi alat masih baik..."></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
            <button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
            <button class="btn btn-primary" id="btnKirimRequest" onclick="submitRequest(${peminjamanId})">
                <i class="fas fa-paper-plane"></i> Kirim Request
            </button>
        </div>
    `);
}
function submitRequest(peminjamanId) {
    const btn = document.getElementById('btnKirimRequest');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
    const fd = new FormData(); fd.append('action','request_pengembalian'); fd.append('peminjaman_id',peminjamanId);
    fd.append('catatan_user',document.getElementById('catatanRequestUser')?.value?.trim()||'');
    fetch('api/pengembalian.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){closeModal();showFlash(d.message,'sukses');setTimeout(()=>location.reload(),1400);}
        else{showFlash(d.message||'Gagal','kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Kirim Request';}
    }).catch(err=>{showFlash('Koneksi gagal: '+err.message,'kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Kirim Request';});
}
function batalkanRequest(pengembalianId, namaAlat) {
    confirmDialog(`Batalkan request pengembalian untuk <strong>${namaAlat}</strong>?`, function(){
        const fd=new FormData(); fd.append('action','batalkan_request'); fd.append('pengembalian_id',pengembalianId);
        fetch('api/pengembalian.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showFlash(d.message,d.success?'sukses':'kesalahan');if(d.success)setTimeout(()=>location.reload(),1200);}).catch(()=>showFlash('Koneksi gagal','kesalahan'));
    },'Batalkan Request');
}
function konfirmasiRequest(pengembalianId, namaUser, namaAlat) {
    openModal('Konfirmasi Request Pengembalian', `
        <div style="margin-bottom:16px;padding:14px 16px;background:rgba(0,212,170,0.07);border:1px solid rgba(0,212,170,0.2);border-radius:12px">
            <div style="font-weight:600;font-size:14px;margin-bottom:5px"><i class="fas fa-user" style="color:var(--accent);margin-right:7px"></i>${namaUser}</div>
            <div style="font-size:13px;color:var(--text-muted)"><i class="fas fa-wrench" style="margin-right:6px;opacity:.6"></i>${namaAlat}</div>
        </div>
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:18px;padding:12px 14px;background:rgba(108,99,255,0.06);border-left:3px solid rgba(108,99,255,0.4);border-radius:0 10px 10px 0;line-height:1.7">
            <i class="fas fa-info-circle" style="color:var(--accent2);margin-right:7px"></i>
            Setelah dikonfirmasi, user akan diminta datang langsung menyerahkan alat.
        </div>
        <div class="form-group">
            <label class="form-label">Pesan untuk User <span style="color:var(--text-muted);font-size:11px">(opsional)</span></label>
            <textarea id="catatanKonfirmasi" class="form-control" rows="2" placeholder="Contoh: Silakan datang ke gudang..."></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
            <button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
            <button class="btn btn-primary" id="btnKonfirmasi" onclick="submitKonfirmasi(${pengembalianId})"><i class="fas fa-check"></i> Konfirmasi</button>
        </div>
    `);
}
function submitKonfirmasi(pengembalianId) {
    const btn=document.getElementById('btnKonfirmasi'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Memproses...';
    const fd=new FormData(); fd.append('action','konfirmasi_request'); fd.append('pengembalian_id',pengembalianId);
    fd.append('catatan_konfirmasi',document.getElementById('catatanKonfirmasi')?.value?.trim()||'');
    fetch('api/pengembalian.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){closeModal();showFlash(d.message,'sukses');setTimeout(()=>location.reload(),1200);}
        else{showFlash(d.message||'Gagal','kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Konfirmasi';}
    }).catch(err=>{showFlash('Koneksi gagal: '+err.message,'kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Konfirmasi';});
}
function tolakRequest(pengembalianId, namaAlat) {
    openModal('Tolak / Batalkan Request', `
        <div style="font-size:13.5px;color:var(--text-secondary);margin-bottom:16px">Tolak request pengembalian untuk <strong>${namaAlat}</strong>?</div>
        <div class="form-group">
            <label class="form-label">Alasan <span style="color:var(--text-muted);font-size:11px">(opsional)</span></label>
            <textarea id="alasanTolak" class="form-control" rows="2" placeholder="Jelaskan alasan..."></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
            <button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-arrow-left"></i> Kembali</button>
            <button class="btn btn-danger" id="btnTolak" onclick="submitTolak(${pengembalianId})"><i class="fas fa-ban"></i> Tolak</button>
        </div>
    `);
}
function submitTolak(pengembalianId) {
    const btn=document.getElementById('btnTolak'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Memproses...';
    const fd=new FormData(); fd.append('action','tolak_request'); fd.append('pengembalian_id',pengembalianId);
    fd.append('alasan',document.getElementById('alasanTolak')?.value?.trim()||'');
    fetch('api/pengembalian.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){closeModal();showFlash(d.message,'sukses');setTimeout(()=>location.reload(),1200);}
        else{showFlash(d.message||'Gagal','kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-ban"></i> Tolak';}
    }).catch(err=>{showFlash('Koneksi gagal: '+err.message,'kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-ban"></i> Tolak';});
}
function openFormCekBarang(pengembalianId, namaAlat, jumlah) {
    openModal('Cek Barang — '+namaAlat,'<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:28px;color:var(--accent)"></i><div style="margin-top:12px;color:var(--text-muted)">Memuat data unit...</div></div>');
    fetch('api/pengembalian.php?action=get_unit_pengembalian&pengembalian_id='+pengembalianId).then(r=>r.json()).then(d=>{
        if(!d.success){showFlash(d.message||'Gagal memuat unit','kesalahan');return;}
        const units=d.units; window._unitsRetur=units;
        const kondisiOptions=`<option value="baik">✔ Baik</option><option value="rusak_ringan">⚠ Rusak Ringan</option><option value="rusak_berat">✖ Rusak Berat</option><option value="hilang">☠ Hilang</option>`;
        const unitRows=units.map(u=>`<tr><td style="font-weight:700;font-family:monospace;color:var(--accent2);font-size:13px">${u.kode_unit}</td><td><select id="rku_${u.unit_id}" data-uid="${u.unit_id}" class="form-control" style="font-size:13px" onchange="warnaiSelect(this)">${kondisiOptions}</select></td></tr>`).join('');
        _updateModal(`
            <div style="margin-bottom:14px;padding:12px 14px;background:rgba(255,193,7,0.07);border:1px solid rgba(255,193,7,0.2);border-radius:10px;display:flex;align-items:center;gap:10px">
                <i class="fas fa-clipboard-check" style="color:#ffc107;font-size:20px;flex-shrink:0"></i>
                <div><div style="font-weight:600;font-size:14px">${namaAlat}</div><div style="font-size:12px;color:var(--text-muted)">${units.length} unit</div></div>
            </div>
            <div class="table-wrap" style="margin-bottom:16px"><table><thead><tr><th>Kode Unit</th><th>Kondisi Saat Dikembalikan</th></tr></thead><tbody>${unitRows}</tbody></table></div>
            <div class="form-group"><label class="form-label">Catatan Pengecekan <span class="req">*</span></label><textarea id="catatanCek" class="form-control" rows="3" placeholder="Jelaskan kondisi alat secara detail..."></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                <button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
                <button class="btn btn-warning" id="btnSubmitCek" onclick="submitCekBarang(${pengembalianId})"><i class="fas fa-check"></i> Selesaikan Pengembalian</button>
            </div>
        `);
    }).catch(err=>showFlash('Koneksi gagal: '+err.message,'kesalahan'));
}
function submitCekBarang(pengembalianId) {
    const btn=document.getElementById('btnSubmitCek');
    const catatan=document.getElementById('catatanCek');
    if(!catatan||!catatan.value.trim()){showFlash('Catatan pengecekan tidak boleh kosong','kesalahan');catatan&&catatan.focus();return;}
    const units=window._unitsRetur||[];
    if(!units.length){showFlash('Data unit tidak ditemukan','kesalahan');return;}
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Memproses...';
    const fd=new FormData(); fd.append('action','proses_cek_pengembalian'); fd.append('pengembalian_id',pengembalianId); fd.append('catatan_pengembalian',catatan.value.trim());
    units.forEach(u=>{const sel=document.getElementById('rku_'+u.unit_id);if(sel)fd.append('kondisi_unit['+u.unit_id+']',sel.value);});
    fetch('api/pengembalian.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){closeModal();showFlash(d.message,'sukses');setTimeout(()=>location.reload(),1500);}
        else{showFlash(d.message||'Gagal memproses','kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Selesaikan Pengembalian';}
    }).catch(err=>{showFlash('Koneksi gagal: '+err.message,'kesalahan');btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Selesaikan Pengembalian';});
}
function warnaiSelect(select) {
    const colors={'baik':'rgba(0,212,170,0.12)','rusak_ringan':'rgba(255,101,132,0.12)','rusak_berat':'rgba(220,53,69,0.15)','hilang':'rgba(255,193,7,0.12)'};
    select.style.background=colors[select.value]||''; select.style.fontWeight=select.value==='baik'?'400':'600';
}
function konfirmasiLunas(peminjamanId, namaUser) {
    confirmDialog(`Konfirmasi bahwa <strong>${namaUser}</strong> telah membayar semua denda?`, function(){
        const fd=new FormData(); fd.append('action','lunas_denda'); fd.append('peminjaman_id',peminjamanId);
        fetch('api/pengembalian.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showFlash(d.message||'Berhasil',d.success?'sukses':'kesalahan');if(d.success)setTimeout(()=>location.reload(),1200);}).catch(()=>showFlash('Koneksi gagal','kesalahan'));
    },'Konfirmasi Pelunasan Denda');
}
function lihatDetail(pengembalianId) {
    openModal('Detail Pengembalian','<div style="text-align:center;padding:32px"><i class="fas fa-spinner fa-spin" style="font-size:26px;color:var(--accent)"></i><div style="margin-top:10px;color:var(--text-muted);font-size:13px">Memuat detail...</div></div>');
    fetch('api/pengembalian.php?action=get_detail_selesai&pengembalian_id='+pengembalianId).then(r=>r.json()).then(d=>{
        if(!d.success){showFlash(d.message||'Gagal memuat','kesalahan');return;}
        const dt=d.data; const units=dt.units||[];
        const warnaBadge={'baik':{bg:'rgba(0,212,170,0.12)',text:'var(--accent)',label:'Baik'},'rusak_ringan':{bg:'rgba(255,101,132,0.12)',text:'#ff6584',label:'Rusak Ringan'},'rusak_berat':{bg:'rgba(220,53,69,0.15)',text:'#dc3545',label:'Rusak Berat'},'hilang':{bg:'rgba(255,193,7,0.12)',text:'#ffc107',label:'Hilang'}};
        let unitSection='';
        if(units.length>0){
            const ringkasan={baik:0,rusak_ringan:0,rusak_berat:0,hilang:0};
            units.forEach(u=>{if(u.kondisi_kembali&&ringkasan[u.kondisi_kembali]!==undefined)ringkasan[u.kondisi_kembali]++;});
            const rb=Object.entries(ringkasan).filter(([,v])=>v>0).map(([k,v])=>{const s=warnaBadge[k];return`<span style="background:${s.bg};color:${s.text};font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px">${v} ${s.label}</span>`;}).join(' ');
            const ur=units.map(u=>{const k=u.kondisi_kembali||'baik';const s=warnaBadge[k]||warnaBadge['baik'];return`<div style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:8px;background:rgba(255,255,255,0.03);margin-bottom:5px"><span style="font-family:monospace;font-weight:700;font-size:13px;color:var(--accent2)">${u.kode_unit}</span><span style="background:${s.bg};color:${s.text};font-weight:600;padding:3px 10px;border-radius:20px;font-size:12px">${s.label}</span></div>`;}).join('');
            unitSection=`<div style="margin-bottom:16px"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px"><span style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px"><i class="fas fa-boxes"></i> Kondisi Per Unit</span><div style="display:flex;gap:5px;flex-wrap:wrap">${rb}</div></div><div style="max-height:220px;overflow-y:auto;padding-right:2px">${ur}</div></div><div style="height:1px;background:rgba(255,255,255,0.07);margin-bottom:16px"></div>`;
        }
        const sdw=dt.status_denda==='lunas'?'var(--accent)':dt.status_denda==='menunggu'?'#dc3545':'var(--text-muted)';
        const sdl=dt.status_denda==='lunas'?'Lunas':dt.status_denda==='menunggu'?`Belum Dibayar — ${dt.total_denda_format||''}`:'Tidak Ada Denda';
        const infoSection=`<div style="display:grid;gap:8px;margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 13px;background:rgba(255,255,255,0.03);border-radius:9px"><span style="color:var(--text-muted);font-size:13px"><i class="fas fa-calendar" style="margin-right:6px;opacity:.6"></i>Tgl Pinjam</span><span style="font-weight:600">${dt.tanggal_pinjam||'-'}</span></div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 13px;background:rgba(255,255,255,0.03);border-radius:9px"><span style="color:var(--text-muted);font-size:13px"><i class="fas fa-calendar-times" style="margin-right:6px;opacity:.6"></i>Jatuh Tempo</span><span style="font-weight:600">${dt.tanggal_pengembalian_rencana||'-'}</span></div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 13px;background:rgba(0,212,170,0.06);border-radius:9px;border:1px solid rgba(0,212,170,0.15)"><span style="color:var(--text-muted);font-size:13px"><i class="fas fa-calendar-check" style="margin-right:6px;color:var(--accent)"></i>Tgl Dikembalikan</span><span style="font-weight:700;color:var(--accent)">${dt.tanggal_pengembalian_aktual||'-'}</span></div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 13px;background:rgba(255,255,255,0.03);border-radius:9px"><span style="color:var(--text-muted);font-size:13px"><i class="fas fa-clock" style="margin-right:6px;opacity:.6"></i>Hari Terlambat</span><span style="font-weight:600;color:${dt.hari_terlambat>0?'#ffc107':'var(--accent)'}">${dt.hari_terlambat>0?dt.hari_terlambat+' hari':'Tepat Waktu'}</span></div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 13px;background:rgba(255,255,255,0.03);border-radius:9px"><span style="color:var(--text-muted);font-size:13px"><i class="fas fa-receipt" style="margin-right:6px;opacity:.6"></i>Status Denda</span><span style="font-weight:700;color:${sdw}">${sdl}</span></div>
        </div>
        <div style="margin-bottom:14px"><div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px"><i class="fas fa-sticky-note"></i> Catatan Petugas</div>
        <div style="padding:12px 14px;background:rgba(255,255,255,0.03);border-radius:9px;color:var(--text-secondary);font-size:13px;line-height:1.75;white-space:pre-wrap;border-left:3px solid rgba(108,99,255,0.4)">${dt.catatan_pengembalian||'-'}</div></div>
        <div style="font-size:11.5px;color:var(--text-muted)"><i class="fas fa-user-check" style="margin-right:4px;opacity:.6"></i>Diperiksa oleh: <strong style="color:var(--text-secondary)">${dt.nama_pemeriksa||'Sistem'}</strong></div>`;
        _updateModal(unitSection+infoSection+`<div style="margin-top:20px;text-align:right"><button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Tutup</button></div>`);
    }).catch(()=>showFlash('Koneksi gagal','kesalahan'));
}
function _updateModal(html) { const b=document.querySelector('.modal-body'); if(b) b.innerHTML=html; }
</script>
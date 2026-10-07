<?php
require_once 'config.php';
startSession();
requireLogin();

$pageTitle = 'Peminjaman';
$user = getCurrentUser();
$db = getDB();

$statusFilter = $_GET['status'] ?? '';
$currentPage = (int)($_GET['page'] ?? 1);
if ($currentPage < 1) $currentPage = 1;
$itemsPerPage = 25;

$where  = ["p.status NOT IN ('dikembalikan')"];
$params = [];
if ($user['nama_role'] === 'user') { $where[] = "p.user_id = ?"; $params[] = $user['id']; }
if ($statusFilter)                  { $where[] = "p.status = ?";   $params[] = $statusFilter; }

$whereStr = " WHERE " . implode(" AND ", $where);

$stmtCount = $db->prepare("SELECT COUNT(*) as total FROM peminjaman p JOIN alat a ON p.alat_id = a.id JOIN users u ON p.user_id = u.id$whereStr");
$stmtCount->execute($params);
$totalItems = (int)$stmtCount->fetch()['total'];
$totalPages = max(1, (int)ceil($totalItems / $itemsPerPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $itemsPerPage;

$baseCols = "p.id, p.user_id, p.alat_id, p.jumlah,
             p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
             p.status, p.catatan, p.ada_request_pengembalian, p.created_at,
             a.nama_alat, u.nama as nama_user, u.username
      FROM peminjaman p JOIN alat a ON p.alat_id = a.id JOIN users u ON p.user_id = u.id";

// Halaman (dengan pagination)
$stmt = $db->prepare("SELECT $baseCols$whereStr ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$itemsPerPage, $offset]));
$peminjaman = $stmt->fetchAll();

// Semua data untuk cetak
$stmtAll = $db->prepare("SELECT $baseCols$whereStr ORDER BY p.created_at DESC");
$stmtAll->execute($params);
$allPeminjaman = $stmtAll->fetchAll();

// Status counts
$statusList = ['menunggu','disetujui','dipinjam','ditolak','dibatalkan'];
$statusCounts = [];
foreach ($statusList as $s) {
    $p2 = [$s]; $bw = ["p.status = ?", "p.status NOT IN ('dikembalikan')"];
    if ($user['nama_role'] === 'user') { $bw[] = "p.user_id = ?"; $p2[] = $user['id']; }
    $sc = $db->prepare("SELECT COUNT(*) as cnt FROM peminjaman p WHERE " . implode(" AND ", $bw));
    $sc->execute($p2); $statusCounts[$s] = $sc->fetch()['cnt'];
}

$statusLabelCetak = $statusFilter ? ucfirst($statusFilter) : 'Semua Status Aktif';

// Build print data in PHP
$printData = [];
foreach ($allPeminjaman as $p) {
    $terlambat = false; $hariTelat = 0;
    if (!empty($p['tanggal_pengembalian_rencana'])) {
        try {
            $jt = new DateTime($p['tanggal_pengembalian_rencana']); $sk = new DateTime();
            $terlambat = ($p['status'] === 'dipinjam' && $sk > $jt);
            if ($terlambat) $hariTelat = (int)$sk->diff($jt)->days;
        } catch (Exception $e) {}
    }
    $printData[] = [
        'nama_alat'       => $p['nama_alat'],
        'nama_user'       => $p['nama_user'] ?? null,
        'username'        => $p['username']  ?? null,
        'jumlah'          => $p['jumlah'],
        'tgl_pinjam'      => !empty($p['tanggal_pinjam'])
                                ? (new DateTime($p['tanggal_pinjam']))->format('d M Y H:i') : '-',
        'jatuh_tempo'     => !empty($p['tanggal_pengembalian_rencana'])
                                ? (new DateTime($p['tanggal_pengembalian_rencana']))->format('d M Y H:i') : '-',
        'status'          => $p['status'],
        'terlambat'       => $terlambat,
        'hari_telat'      => $hariTelat,
        'ada_req_kembali' => (bool)$p['ada_request_pengembalian'],
    ];
}

include 'inc/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Daftar <span>Peminjaman</span></h1>
        <p class="page-subtitle">
            <?= $user['nama_role'] === 'user'
                ? 'Lihat dan kelola status peminjaman aktif Anda'
                : 'Kelola semua permintaan peminjaman alat' ?>
        </p>
    </div>
</div>

<div style="margin-bottom:20px;padding:13px 16px;background:rgba(108,99,255,0.07);
            border:1px solid rgba(108,99,255,0.2);border-radius:12px;
            display:flex;align-items:flex-start;gap:12px">
    <i class="fas fa-info-circle" style="color:var(--accent2);font-size:18px;margin-top:1px;flex-shrink:0"></i>
    <div style="font-size:13px;color:var(--text-secondary);line-height:1.7">
        <strong style="color:var(--text-primary)">Alur Peminjaman:</strong> Menunggu → Disetujui → Dipinjam.
        <?php if ($user['nama_role'] === 'user'): ?>
            Untuk mengembalikan alat, ajukan <strong>Request Pengembalian</strong> melalui halaman
            <a href="pengembalian.php" style="color:var(--accent2);text-decoration:underline">Pengembalian</a>.
        <?php else: ?>
            Riwayat pengembalian tersedia di halaman
            <a href="pengembalian.php" style="color:var(--accent2);text-decoration:underline">Pengembalian</a>.
        <?php endif; ?>
    </div>
</div>

<!-- Filter status -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
    <a href="peminjaman.php" class="btn btn-sm <?= !$statusFilter ? 'btn-primary' : 'btn-secondary' ?>">
        Semua <span style="opacity:.7">(<?= array_sum($statusCounts) ?>)</span>
    </a>
    <?php $statusLabels = [
        'menunggu'   => ['fa-clock',       'Menunggu'],
        'disetujui'  => ['fa-check',        'Disetujui'],
        'dipinjam'   => ['fa-hand-holding', 'Dipinjam'],
        'ditolak'    => ['fa-ban',          'Ditolak'],
        'dibatalkan' => ['fa-times',        'Dibatalkan'],
    ];
    foreach ($statusLabels as $s => $info): ?>
    <a href="peminjaman.php?status=<?= $s ?>" class="btn btn-sm <?= $statusFilter===$s ? 'btn-primary' : 'btn-secondary' ?>">
        <i class="fas <?= $info[0] ?>"></i> <?= $info[1] ?>
        <span style="opacity:.6">(<?= $statusCounts[$s] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Toolbar + Cetak -->
<div class="toolbar">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Cari nama alat atau user...">
    </div>
    <span style="font-size:12px;color:var(--text-muted)"><?= $totalItems ?> record</span>

    <!-- TOMBOL CETAK TABEL -->
    <button id="btnCetak" onclick="cetakTabelPeminjaman()"
            style="display:inline-flex;align-items:center;gap:8px;
                   padding:9px 18px;border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;
                   background:linear-gradient(135deg,rgba(108,99,255,0.15),rgba(0,212,170,0.1));
                   border:1.5px solid rgba(108,99,255,0.4);color:var(--accent2);
                   transition:all .2s;white-space:nowrap"
            onmouseover="this.style.background='linear-gradient(135deg,rgba(108,99,255,0.25),rgba(0,212,170,0.15))'"
            onmouseout="this.style.background='linear-gradient(135deg,rgba(108,99,255,0.15),rgba(0,212,170,0.1))'">
        <i class="fas fa-print" style="font-size:15px"></i>
        Cetak Tabel
        <span style="font-size:11px;opacity:.7;font-weight:500">(<?= count($allPeminjaman) ?>)</span>
    </button>
</div>

<!-- Tabel -->
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
                    <?php if ($user['nama_role'] !== 'user'): ?><th style="width:220px">Aksi</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($peminjaman)): ?>
                <tr><td colspan="8" class="table-empty"><i class="fas fa-clipboard-list"></i><br>Tidak ada data peminjaman aktif</td></tr>
                <?php else: ?>
                <?php foreach ($peminjaman as $i => $p):
                    $jatuhTempo = null;
                    if (!empty($p['tanggal_pengembalian_rencana'])) {
                        try { $jatuhTempo = new DateTime($p['tanggal_pengembalian_rencana']); } catch (Exception $e) {}
                    }
                    $sekarang  = new DateTime();
                    $terlambat = ($p['status'] === 'dipinjam' && $jatuhTempo !== null && $sekarang > $jatuhTempo);
                ?>
                <tr class="filterable-row">
                    <td style="color:var(--text-muted);font-size:12px"><?= $offset + $i + 1 ?></td>
                    <td style="font-weight:500;color:var(--text-primary)"><?= sanitize($p['nama_alat']) ?></td>
                    <?php if ($user['nama_role'] !== 'user'): ?>
                    <td>
                        <div style="font-size:12.5px;font-weight:500"><?= sanitize($p['nama_user']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">@<?= sanitize($p['username']) ?></div>
                    </td>
                    <?php endif; ?>
                    <td><?= $p['jumlah'] ?> unit</td>
                    <td>
                        <?php if (!empty($p['tanggal_pinjam'])): ?>
                            <?= formatDate($p['tanggal_pinjam'], 'd M Y H:i') ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:11px;font-style:italic">Belum aktif</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($jatuhTempo !== null): ?>
                            <span style="color:<?= $terlambat ? 'var(--accent3)' : 'inherit' ?>;font-weight:<?= $terlambat ? '600' : 'normal' ?>">
                                <?= formatDate($p['tanggal_pengembalian_rencana'], 'd M Y H:i') ?>
                            </span>
                            <?php if ($terlambat): ?>
                                <div style="font-size:10.5px;color:var(--accent3)">
                                    <i class="fas fa-exclamation-triangle"></i> Terlambat <?= $sekarang->diff($jatuhTempo)->days ?> hari
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:11px;font-style:italic">
                                <?= in_array($p['status'], ['menunggu','disetujui']) ? 'Menunggu aktif' : '-' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= getStatusBadge($p['status']) ?>
                        <?php if ($p['status'] === 'dipinjam' && $p['ada_request_pengembalian']): ?>
                            <div style="font-size:10.5px;color:#ffc107;margin-top:3px">
                                <i class="fas fa-clock"></i> Ada request pengembalian
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php if ($user['nama_role'] !== 'user'): ?>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <?php if ($p['status'] === 'menunggu'): ?>
                                <button type="button" class="btn btn-sm btn-primary" onclick="updateStatus(<?= (int)$p['id'] ?>, 'disetujui')">
                                    <i class="fas fa-check"></i> Setuju
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="updateStatus(<?= (int)$p['id'] ?>, 'ditolak')">
                                    <i class="fas fa-ban"></i> Tolak
                                </button>
                            <?php elseif ($p['status'] === 'disetujui'): ?>
                                <button type="button" class="btn btn-sm btn-primary" onclick="updateStatus(<?= (int)$p['id'] ?>, 'dipinjam')">
                                    <i class="fas fa-hand-holding"></i> Pinjamkan
                                </button>
                            <?php elseif ($p['status'] === 'dipinjam'): ?>
                                <a href="pengembalian.php" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-undo"></i> Ke Pengembalian
                                </a>
                            <?php endif; ?>
                            <?php if (in_array($p['status'], ['menunggu','disetujui'])): ?>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="updateStatus(<?= (int)$p['id'] ?>, 'dibatalkan')">
                                    <i class="fas fa-times"></i> Batal
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:20px;flex-wrap:wrap">
    <?php
        $mkUrl = fn($pg) => "peminjaman.php?page=$pg" . ($statusFilter ? "&status=".urlencode($statusFilter) : "");
        if ($currentPage > 1) echo '<a href="'.$mkUrl($currentPage-1).'" class="btn btn-sm btn-secondary"><i class="fas fa-chevron-left"></i> Sebelumnya</a>';
        $s = max(1,$currentPage-2); $e = min($totalPages,$currentPage+2);
        if ($s > 1) { echo '<a href="'.$mkUrl(1).'" class="btn btn-sm btn-secondary">1</a>'; if ($s > 2) echo '<span style="color:var(--text-muted)">...</span>'; }
        for ($pg=$s; $pg<=$e; $pg++) echo '<a href="'.$mkUrl($pg).'" class="btn btn-sm '.($pg===$currentPage?'btn-primary':'btn-secondary').'">'.$pg.'</a>';
        if ($e < $totalPages) { if ($e < $totalPages-1) echo '<span style="color:var(--text-muted)">...</span>'; echo '<a href="'.$mkUrl($totalPages).'" class="btn btn-sm btn-secondary">'.$totalPages.'</a>'; }
        if ($currentPage < $totalPages) echo '<a href="'.$mkUrl($currentPage+1).'" class="btn btn-sm btn-secondary">Selanjutnya <i class="fas fa-chevron-right"></i></a>';
    ?>
</div>
<?php endif; ?>

<?php include 'inc/footer.php'; ?>

<script>
/* ── DATA CETAK (semua data, bukan hanya halaman ini) ── */
const _D = <?= json_encode($printData, JSON_UNESCAPED_UNICODE) ?>;
const _M = {
    role        : <?= json_encode($user['nama_role']) ?>,
    nama        : <?= json_encode($user['nama']) ?>,
    filter      : <?= json_encode($statusLabelCetak) ?>,
    total       : <?= count($allPeminjaman) ?>,
    tanggal     : <?= json_encode(date('d M Y, H:i')) ?>,
    namaInstansi: 'Sistem Peminjaman Alat Praktikum',
};

/* CETAK DI TAB SAMA: perbaikan bug JS (tidak ada reassign ke const) */

/* util: escapeHtml (letak di atas supaya tersedia saat membentuk template) */
function escapeHtml(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

   /**
 * GANTI fungsi cetakTabelPeminjaman() di peminjaman.php
 * dengan fungsi di bawah ini.
 *
 * Perubahan:
 *  - Tidak lagi pakai iframe (yang menutup tab saat batal)
 *  - Buka tab baru, tulis laporan sederhana, auto-print
 *  - Jika user klik Batal pada dialog print → tetap di tab baru (bisa lihat laporan)
 */

function cetakTabelPeminjaman() {
    const isAdmin = _M.role !== 'user';

    const ST = {
        menunggu   : 'Menunggu',
        disetujui  : 'Disetujui',
        dipinjam   : 'Dipinjam',
        ditolak    : 'Ditolak',
        dibatalkan : 'Dibatalkan',
    };

    /* hitung ringkasan */
    const count = {};
    _D.forEach(d => { count[d.status] = (count[d.status]||0)+1; });
    const totalTelat = _D.filter(d => d.terlambat).length;

    /* baris tabel */
    const rowsHtml = _D.map((d, i) => {
        const statusLabel = ST[d.status] || d.status;

        const peminjamCell = isAdmin
            ? `<td>${escapeHtml(d.nama_user || '-')}<br><small>@${escapeHtml(d.username || '')}</small></td>`
            : '';

        const jatuhTempo = d.jatuh_tempo === '-'
            ? '-'
            : d.terlambat
                ? `${d.jatuh_tempo} <br><small style="color:red">⚠ Terlambat ${d.hari_telat} hari</small>`
                : d.jatuh_tempo;

        return `<tr>
            <td style="text-align:center">${i + 1}</td>
            <td>${escapeHtml(d.nama_alat)}</td>
            ${peminjamCell}
            <td style="text-align:center">${d.jumlah} unit</td>
            <td>${d.tgl_pinjam === '-' ? '<em>Belum aktif</em>' : d.tgl_pinjam}</td>
            <td>${jatuhTempo}</td>
            <td>${statusLabel}${d.ada_req_kembali ? '<br><small>⏳ Ada req. kembali</small>' : ''}</td>
        </tr>`;
    }).join('') || `<tr><td colspan="${isAdmin ? 7 : 6}" style="text-align:center;padding:20px">Tidak ada data</td></tr>`;

    const thPeminjam = isAdmin ? '<th>Peminjam</th>' : '';

    /* ringkasan status */
    const ringkasanHtml = Object.entries(count)
        .map(([s, n]) => `<span>${ST[s] || s}: <strong>${n}</strong></span>`)
        .join(' &nbsp;|&nbsp; ');

    const html = `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Peminjaman</title>
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

<h2>Laporan Peminjaman Alat</h2>
<div class="meta">
  Filter: <strong>${escapeHtml(_M.filter)}</strong> &nbsp;|&nbsp;
  Dicetak: <strong>${escapeHtml(_M.tanggal)}</strong> &nbsp;|&nbsp;
  Oleh: <strong>${escapeHtml(_M.nama)}</strong> &nbsp;|&nbsp;
  Total: <strong>${_M.total} record</strong>
  ${totalTelat > 0 ? ` &nbsp;|&nbsp; <span style="color:red">Terlambat: <strong>${totalTelat}</strong></span>` : ''}
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
      <th style="width:8%">Jumlah</th>
      <th style="width:15%">Tgl Pinjam</th>
      <th style="width:16%">Jatuh Tempo</th>
      <th style="width:11%">Status</th>
    </tr>
  </thead>
  <tbody>
    ${rowsHtml}
  </tbody>
</table>

<div class="footer">
  ${escapeHtml(_M.namaInstansi)} — Dokumen ini dicetak otomatis.
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

/* ── Update status peminjaman ── */
function updateStatus(id, status) {
    const labels = { disetujui:'menyetujui', ditolak:'menolak', dipinjam:'mengaktifkan peminjaman', dibatalkan:'membatalkan' };
    confirmDialog(
        `Apakah Anda yakin untuk <strong>${labels[status]||status}</strong> peminjaman ini?`,
        function () {
            const p = new URLSearchParams();
            p.append('action','update_status'); p.append('id',String(id)); p.append('status',String(status));
            fetch('api/peminjaman.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p.toString()})
                .then(r=>r.json())
                .then(d=>{ showFlash(d.message||'Berhasil',d.success?'sukses':'kesalahan'); if(d.success) setTimeout(()=>location.reload(),1200); })
                .catch(err=>showFlash('Koneksi gagal: '+err.message,'kesalahan'));
        }, 'Konfirmasi Aksi');
}
</script>
<?php
require_once 'config.php';
startSession();
requireLogin();

$pageTitle = 'Katalog Alat';
$user = getCurrentUser();
$db = getDB();

$kategori = $db->query("SELECT * FROM kategori_alat ORDER BY id")->fetchAll();
$alat     = $db->query("SELECT a.*, k.nama_kategori, k.ikon FROM alat a LEFT JOIN kategori_alat k ON a.kategori_id = k.id ORDER BY a.nama_alat ASC")->fetchAll();

if (!empty($alat)) {
    $stmtCounts = $db->prepare("
        SELECT COUNT(*) AS total, SUM(kondisi = 'baik' AND status = 'tersedia') AS tersedia
        FROM unit_alat WHERE alat_id = ?
    ");
    foreach ($alat as &$a) {
        $stmtCounts->execute([$a['id']]);
        $cnt = $stmtCounts->fetch();
        $a['stok']       = (int)($cnt['total']    ?? 0);
        $a['stok_layak'] = (int)($cnt['tersedia'] ?? 0);
        $a['tarif_harian'] = (int)($a['tarif_denda_per_hari'] ?? 0);
    }
    unset($a);
}

$alatPerKategori = [];
foreach ($kategori as $k) {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM alat WHERE kategori_id = ?");
    $stmt->execute([$k['id']]);
    $alatPerKategori[$k['id']] = $stmt->fetch()['cnt'] ?? 0;
}

include 'inc/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Katalog <span>Alat & Peralatan</span></h1>
        <p class="page-subtitle">Temukan dan pinjam alat praktikum sesuai jurusan Anda</p>
    </div>
</div>

<div class="toolbar">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Cari alat praktikum...">
    </div>
    <select id="filterKategori" class="form-control filter-select" onchange="filterTable()">
        <option value="">Semua Jurusan</option>
        <?php foreach ($kategori as $k): ?>
        <option value="<?= $k['id'] ?>"><?= sanitize($k['nama_kategori']) ?> (<?= $alatPerKategori[$k['id']] ?> alat)</option>
        <?php endforeach; ?>
    </select>
    <span style="font-size:12px;color:var(--text-muted);margin-left:auto"><?= count($alat) ?> alat tersedia</span>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px">
    <button class="btn btn-secondary btn-sm cat-pill active" onclick="filterByPill(this, '')">
        <i class="fas fa-th-large"></i> Semua Jurusan
    </button>
    <?php foreach ($kategori as $k): ?>
    <button class="btn btn-secondary btn-sm cat-pill" onclick="filterByPill(this, '<?= $k['id'] ?>')">
        <i class="fas <?= $k['ikon'] ?>"></i> <?= sanitize($k['nama_kategori']) ?>
        <span style="opacity:0.7;font-size:10px;margin-left:4px">(<?= $alatPerKategori[$k['id']] ?>)</span>
    </button>
    <?php endforeach; ?>
</div>

<!-- Info banner -->
<div style="background:linear-gradient(135deg,rgba(108,99,255,0.1),rgba(0,212,170,0.1));border:1px solid rgba(108,99,255,0.2);border-radius:12px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:flex-start;gap:14px">
    <i class="fas fa-info-circle" style="color:var(--accent2);font-size:24px;margin-top:2px"></i>
    <div style="flex:1">
        <div style="font-weight:700;color:var(--text-primary);margin-bottom:6px;font-size:15px">
            <i class="fas fa-clock" style="color:var(--accent2);margin-right:6px"></i>Informasi Waktu Peminjaman Realtime
        </div>
        <div style="font-size:13px;color:var(--text-secondary);line-height:1.7">
            <strong style="color:var(--accent2)">Sistem baru:</strong>
            Waktu peminjaman dimulai <strong>saat petugas menyerahkan alat</strong> kepada Anda (bukan saat request).
            <br>• Batas waktu peminjaman: <strong style="color:var(--accent)">2 hari (48 jam)</strong> sejak alat diserahkan
            <br>• Denda keterlambatan: <strong style="color:#ffc107">Per hari penuh (24 jam)</strong> sejak jatuh tempo
            <br>• Total <strong><?= count($alat) ?> alat</strong> tersedia untuk dipinjam
            <?php if ($user['nama_role'] === 'user'): ?>
            <br>• <i class="fas fa-shopping-basket" style="color:var(--accent)"></i>
                <strong style="color:var(--accent)"> Baru!</strong>
                Gunakan <strong>Tambah ke Keranjang</strong> untuk meminjam beberapa alat sekaligus
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Banner keranjang aktif — hanya user -->
<?php if ($user['nama_role'] === 'user'): ?>
<div id="cartActiveBanner" style="display:none;margin-bottom:18px;padding:12px 16px;
     background:rgba(0,212,170,0.08);border:1px solid rgba(0,212,170,0.25);border-radius:12px;
     display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:10px;font-size:13px;color:var(--text-secondary)">
        <i class="fas fa-shopping-basket" style="color:var(--accent);font-size:18px"></i>
        <span>Ada <strong id="bannerCartCount" style="color:var(--accent)">0 alat</strong> di keranjang Anda</span>
    </div>
    <button onclick="openCart()" class="btn btn-sm btn-primary" style="white-space:nowrap">
        <i class="fas fa-eye"></i> Lihat Keranjang &amp; Kirim
    </button>
</div>
<?php endif; ?>

<div class="alat-grid" id="alatGrid">
    <?php if (empty($alat)): ?>
    <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text-muted)">
        <i class="fas fa-box-open" style="font-size:48px;margin-bottom:16px;opacity:0.3"></i>
        <div style="font-size:16px;font-weight:500">Belum ada alat tersedia</div>
    </div>
    <?php else: ?>
    <?php foreach ($alat as $a): ?>
    <div class="alat-card filterable-card" data-kategori="<?= $a['kategori_id'] ?>">
        <div class="alat-card-img">
            <i class="fas <?= $a['ikon'] ?? 'fa-tools' ?>" style="font-size:42px"></i>
            <span class="alat-badge" style="position:absolute;top:12px;right:12px"><?= getStatusBadge($a['status']) ?></span>
        </div>
        <div class="alat-card-body">
            <div class="alat-card-kategori">
                <i class="fas <?= $a['ikon'] ?? 'fa-folder' ?>" style="margin-right:4px"></i>
                <?= sanitize($a['nama_kategori'] ?? 'Umum') ?>
            </div>
            <div class="alat-card-title"><?= sanitize($a['nama_alat']) ?></div>
            <div class="alat-card-desc"><?= sanitize($a['deskripsi'] ?? 'Tidak ada deskripsi') ?></div>

            <?php if ($a['tarif_harian'] > 0): ?>
            <div style="margin:10px 0;padding:8px 10px;background:rgba(255,193,7,0.1);border-radius:8px;border:1px solid rgba(255,193,7,0.2)">
                <div style="font-size:11px;color:#ffc107;font-weight:600;display:flex;align-items:center;gap:5px">
                    <i class="fas fa-exclamation-triangle"></i>
                    Denda Keterlambatan: <?= formatRupiah($a['tarif_harian']) ?> / hari
                </div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
                    Dihitung per hari penuh (24 jam) sejak jatuh tempo
                </div>
            </div>
            <?php endif; ?>

            <div class="alat-card-footer">
                <div class="alat-stok">
                    <span style="display:inline-flex;align-items:center;gap:4px">
                        <i class="fas fa-boxes" style="font-size:12px"></i>
                        Tersedia: <strong style="color:<?= $a['stok_layak'] > 5 ? 'var(--success)' : ($a['stok_layak'] > 0 ? 'var(--warning)' : 'var(--danger)') ?>"><?= $a['stok_layak'] ?></strong>
                    </span>
                    <span style="margin-left:8px;padding-left:8px;border-left:1px solid rgba(255,255,255,0.1)">
                        Kondisi: <?= getStatusBadge($a['kondisi']) ?>
                    </span>
                </div>

                <?php if ($user['nama_role'] === 'user' && $a['status'] === 'tersedia' && $a['stok_layak'] > 0): ?>
                <!-- USER: tombol Pinjam Alat + Tambah ke Keranjang -->
                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:8px">
                    <button class="btn btn-primary btn-sm"
                            onclick="openPinjem(<?= $a['id'] ?>, '<?= addslashes(sanitize($a['nama_alat'])) ?>', <?= $a['stok_layak'] ?>, '<?= addslashes(sanitize($a['nama_kategori'])) ?>', <?= $a['tarif_harian'] ?>)">
                        <i class="fas fa-hand-pointer"></i> Pinjam Alat
                    </button>
                    <button class="btn btn-sm"
                            style="background:rgba(0,212,170,0.12);border:1.5px solid rgba(0,212,170,0.35);
                                   color:var(--accent);font-weight:700;border-radius:9px;
                                   padding:6px 12px;cursor:pointer;transition:background .2s;white-space:nowrap"
                            onmouseover="this.style.background='rgba(0,212,170,0.22)'"
                            onmouseout="this.style.background='rgba(0,212,170,0.12)'"
                            onclick="addToCart(<?= $a['id'] ?>, '<?= addslashes(sanitize($a['nama_alat'])) ?>', <?= $a['stok_layak'] ?>, '<?= addslashes(sanitize($a['nama_kategori'])) ?>', <?= $a['tarif_harian'] ?>)">
                        <i class="fas fa-shopping-basket"></i> Keranjang
                    </button>
                </div>

                <?php elseif ($user['nama_role'] !== 'user' && $a['status'] === 'tersedia' && $a['stok_layak'] > 0): ?>
                <!-- ADMIN / PETUGAS: tidak bisa mengajukan peminjaman -->
                <div style="margin-top:8px">
                    <span class="btn btn-secondary btn-sm" style="opacity:.55;cursor:not-allowed;pointer-events:none">
                        <i class="fas fa-lock"></i> 
                    </span>
                </div>

                <?php elseif ($a['status'] !== 'tersedia' || $a['stok_layak'] <= 0): ?>
                <span class="btn btn-secondary btn-sm" style="opacity:.5;cursor:not-allowed">
                    <i class="fas fa-ban"></i> Tidak Tersedia
                </span>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div id="emptyState" style="display:none;text-align:center;padding:60px 20px;color:var(--text-muted)">
    <i class="fas fa-search" style="font-size:48px;margin-bottom:16px;opacity:0.3"></i>
    <div style="font-size:16px;font-weight:500">Tidak ada alat ditemukan</div>
    <div style="font-size:13px;margin-top:8px">Coba ubah filter atau kata kunci pencarian</div>
</div>

<?php include 'inc/footer.php'; ?>

<script>
/* ─────────────────────────────────────────────
   FILTER
───────────────────────────────────────────── */
function filterByPill(btn, kategoriId) {
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('filterKategori').value = kategoriId;
    filterTable();
}

function filterTable() {
    const search   = document.getElementById('searchInput')?.value?.toLowerCase() || '';
    const category = document.getElementById('filterKategori')?.value || '';
    const cards    = document.querySelectorAll('.filterable-card');
    let vis = 0;
    cards.forEach(c => {
        const show = c.textContent.toLowerCase().includes(search) && (!category || c.dataset.kategori === category);
        c.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('emptyState').style.display = (vis === 0 && cards.length > 0) ? 'block' : 'none';
}

document.getElementById('searchInput')?.addEventListener('input', filterTable);

/* ─────────────────────────────────────────────
   TAMBAH KE KERANJANG (hanya user)
───────────────────────────────────────────── */
function addToCart(alatId, namaAlat, stok, kategori, tarifHarian) {
    if (typeof cartAdd !== 'function') return;
    cartAdd({ alatId, namaAlat, stok, kategori, tarifHarian });
    updateCartBanner();

    // Animasi badge bounce
    const badge = document.getElementById('cartBadge');
    if (badge) {
        badge.classList.remove('bump');
        void badge.offsetWidth; // reflow
        badge.classList.add('bump');
        setTimeout(() => badge.classList.remove('bump'), 300);
    }
}

/* Banner di bawah info panel */
function updateCartBanner() {
    const banner = document.getElementById('cartActiveBanner');
    const label  = document.getElementById('bannerCartCount');
    if (!banner || !label) return;
    const n = typeof cartCount === 'function' ? cartCount() : 0;
    if (n > 0) {
        label.textContent = n + (n === 1 ? ' alat' : ' alat');
        banner.style.display = 'flex';
    } else {
        banner.style.display = 'none';
    }
}

/* ─────────────────────────────────────────────
   PINJAM LANGSUNG (modal form) — hanya user
───────────────────────────────────────────── */
function openPinjem(alatId, nama, stok, kategori, tarifHarian) {
    const tarifText = tarifHarian > 0
        ? `<div style="margin-top:10px;padding:10px 12px;background:rgba(255,193,7,0.1);border-radius:8px;border:1px solid rgba(255,193,7,0.2)">
               <div style="font-size:12px;color:#ffc107;font-weight:600;display:flex;align-items:center;gap:6px">
                   <i class="fas fa-info-circle"></i> Informasi Denda Keterlambatan
               </div>
               <div style="font-size:11px;color:var(--text-muted);margin-top:4px;line-height:1.6">
                   • Tarif: <strong style="color:#ffc107">${formatRupiah(tarifHarian)} / hari</strong>
                   <br>• Contoh terlambat 2 hari → denda ${formatRupiah(tarifHarian * 2)}
                   <br>• Dihitung <strong>per hari penuh (24 jam)</strong> sejak jatuh tempo
                   <br>• Denda per <strong>transaksi</strong> (tidak dikalikan jumlah unit)
               </div>
           </div>`
        : '';

    openModal('Form Peminjaman Alat', `
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:16px;
                    background:linear-gradient(135deg,rgba(108,99,255,0.1),rgba(0,212,170,0.1));
                    border-radius:10px;border:1px solid rgba(108,99,255,0.2)">
            <div style="width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#6c63ff,#00d4aa);
                        display:flex;align-items:center;justify-content:center">
                <i class="fas fa-tools" style="color:#fff;font-size:22px"></i>
            </div>
            <div style="flex:1">
                <div style="font-weight:600;color:var(--text-primary);font-size:15px">${nama}</div>
                <div style="font-size:12px;color:var(--text-muted)">
                    <i class="fas fa-layer-group"></i> ${kategori}
                    <span style="margin-left:12px;padding-left:12px;border-left:1px solid rgba(255,255,255,0.2)">
                        <i class="fas fa-boxes"></i> Tersedia: <strong style="color:var(--accent)">${stok} unit</strong>
                    </span>
                </div>
            </div>
        </div>

        <div style="margin-bottom:18px;padding:13px 15px;background:rgba(108,99,255,0.08);
                    border:1px solid rgba(108,99,255,0.2);border-radius:10px">
            <div style="font-size:12px;font-weight:600;color:var(--accent2);margin-bottom:6px;
                        display:flex;align-items:center;gap:6px">
                <i class="fas fa-clock"></i> Sistem Waktu Realtime
            </div>
            <div style="font-size:11.5px;color:var(--text-secondary);line-height:1.7">
                • Waktu peminjaman dimulai <strong>saat petugas menyerahkan alat</strong> kepada Anda
                <br>• Batas waktu: <strong style="color:var(--accent)">2 hari (48 jam)</strong> sejak alat diserahkan
                <br>• Tidak perlu mengisi tanggal (sistem otomatis)
            </div>
        </div>

        <form id="formPinjam">
            <input type="hidden" name="alat_id" value="${alatId}">
            <input type="hidden" name="action" value="pinjam">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-hashtag"></i> Jumlah Unit <span class="req">*</span></label>
                <input type="number" name="jumlah" class="form-control" min="1" max="${stok}" value="1" required>
                <small style="color:var(--text-muted);font-size:11px;margin-top:4px;display:block">Maksimal ${stok} unit tersedia</small>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-comment-alt"></i> Keperluan/Catatan</label>
                <textarea name="catatan" class="form-control" placeholder="Contoh: Untuk praktikum jaringan komputer..." rows="3"></textarea>
            </div>
            ${tarifText}
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Kirim Permintaan</button>
            </div>
        </form>
    `);

    document.getElementById('formPinjam').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spinner" style="width:18px;height:18px;border-width:2px"></div> Mengirim...'; }
        fetch('api/peminjaman.php', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(d => {
                if (d.success) { showFlash(d.message, 'sukses'); closeModal(); setTimeout(() => window.location.href = 'peminjaman.php', 1500); }
                else showFlash(d.message || 'Terjadi kesalahan', 'kesalahan');
            })
            .catch(err => showFlash('Koneksi gagal: ' + err.message, 'kesalahan'))
            .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim Permintaan'; }});
    });
}

function formatRupiah(n) { return 'Rp ' + parseInt(n).toLocaleString('id-ID'); }

/* ─────────────────────────────────────────────
   INIT — sinkron banner saat halaman dimuat
───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    updateCartBanner();
});
</script>
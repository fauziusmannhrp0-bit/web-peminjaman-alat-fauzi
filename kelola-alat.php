<?php
require_once 'config.php';
startSession();
requireRole('admin', 'petugas');

$pageTitle = 'Kelola Alat';
$user      = getCurrentUser();
$db        = getDB();

$alat    = $db->query("SELECT a.*, k.nama_kategori FROM alat a LEFT JOIN kategori_alat k ON a.kategori_id = k.id ORDER BY a.nama_alat ASC")->fetchAll();
$kategori= $db->query("SELECT * FROM kategori_alat ORDER BY id")->fetchAll();

include 'inc/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Kelola <span>Alat</span></h1>
        <p class="page-subtitle">Tambah, edit, dan hapus alat dari inventaris</p>
    </div>
    <div class="page-header-actions">
        <a href="alat-rusak.php" class="btn btn-danger"><i class="fas fa-exclamation-triangle"></i> Daftar Rusak &amp; Hilang</a>
        <button class="btn btn-success" onclick="openTambahStok()"><i class="fas fa-plus"></i> Tambah Stok</button>
        <button class="btn btn-primary" onclick="openTambahAlat()"><i class="fas fa-plus"></i> Tambah Alat</button>
    </div>
</div>

<div class="toolbar">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Cari alat...">
    </div>
    <select id="filterKategori" class="form-control filter-select" onchange="filterTable()">
        <option value="">Semua Kategori</option>
        <?php foreach ($kategori as $k): ?>
        <option value="<?= $k['id'] ?>"><?= sanitize($k['nama_kategori']) ?></option>
        <?php endforeach; ?>
    </select>
    <span style="font-size:12px;color:var(--text-muted);margin-left:auto"><?= count($alat) ?> alat</span>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama Alat</th>
                    <th>Kategori</th>
                    <th>Stok Total</th>
                    <th>Stok Tersedia</th>
                    <th>Harga</th>
                    <th>Denda/Hari</th>
                    <th>Kondisi</th>
                    <th>Status</th>
                    <th>Unit</th>
                    <th style="width:180px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($alat)): ?>
                <tr><td colspan="11" class="table-empty"><i class="fas fa-wrench"></i><br>Belum ada alat</td></tr>
                <?php else: ?>
                <?php foreach ($alat as $i => $a): ?>
                <?php
                    $stmtU = $db->prepare("
                        SELECT
                            COUNT(*)                                        AS total,
                            SUM(kondisi = 'baik' AND status = 'tersedia')  AS tersedia,
                            SUM(status  = 'dipinjam')                      AS dipinjam
                        FROM unit_alat
                        WHERE alat_id = ?
                    ");
                    $stmtU->execute([$a['id']]);
                    $uRow      = $stmtU->fetch();
                    $uTotal    = (int)($uRow['total']    ?? 0);
                    $uTersedia = (int)($uRow['tersedia'] ?? 0);
                    $uDipinjam = (int)($uRow['dipinjam'] ?? 0);

                    $pct   = $uTotal > 0 ? round(($uTersedia / $uTotal) * 100) : 0;
                    $warna = $pct >= 70 ? 'var(--accent)' : ($pct >= 30 ? '#ffc107' : 'var(--accent3)');
                ?>
                <tr class="filterable-row" data-kategori="<?= $a['kategori_id'] ?>">
                    <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
                    <td style="font-weight:500;color:var(--text-primary)"><?= sanitize($a['nama_alat']) ?></td>
                    <td style="color:var(--accent);font-size:12.5px"><?= sanitize($a['nama_kategori'] ?? '-') ?></td>
                    <td><?= $uTotal ?></td>
                    <td>
                        <span style="color:<?= $warna ?>;font-weight:600"><?= $uTersedia ?></span>
                        <span style="font-size:11px;color:var(--text-muted)"> (<?= $pct ?>%)</span>
                    </td>
                    <td style="font-size:12.5px"><?= $a['harga_alat'] > 0 ? formatRupiah((int)$a['harga_alat']) : '<span style="color:var(--text-muted)">-</span>' ?></td>
                    <td style="font-size:12.5px"><?= $a['tarif_denda_per_hari'] > 0 ? formatRupiah((int)$a['tarif_denda_per_hari']) : '<span style="color:var(--text-muted)">-</span>' ?></td>
                    <td><?= getStatusBadge($a['kondisi']) ?></td>
                    <td><?= getStatusBadge($a['status']) ?></td>
                    <td>
                        <div style="font-size:12.5px">
                            <span style="font-weight:600;color:var(--text-primary)"><?= $uTotal ?></span>
                            <span style="color:var(--text-muted)"> total</span>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted)">
                            <span style="color:var(--accent)"><?= $uTersedia ?></span> tersedia
                            &bull;
                            <span style="color:#ffc107"><?= $uDipinjam ?></span> dipinjam
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button class="btn btn-info btn-sm" onclick="lihatUnit(<?= $a['id'] ?>, '<?= addslashes(sanitize($a['nama_alat'])) ?>')"
                                style="background:rgba(108,99,255,0.15);color:var(--accent2);border:1px solid rgba(108,99,255,0.3)">
                                <i class="fas fa-list-ul"></i> Unit
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="openEditAlat(
                                <?= $a['id'] ?>,
                                '<?= addslashes(sanitize($a['nama_alat'])) ?>',
                                '<?= $a['kategori_id'] ?>',
                                <?= $uTotal ?>,
                                <?= $uTersedia ?>,
                                '<?= $a['kondisi'] ?>',
                                '<?= $a['status'] ?>',
                                '<?= addslashes(sanitize($a['deskripsi'] ?? '')) ?>',
                                <?= $a['harga_alat'] ?>,
                                <?= $a['tarif_denda_per_hari'] ?>
                            )"><i class="fas fa-pencil-alt"></i> Edit</button>
                            <button class="btn btn-danger btn-sm" onclick="hapusAlat(<?= $a['id'] ?>, '<?= addslashes(sanitize($a['nama_alat'])) ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
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

<style>
/* ── Unit Modal Redesign ─────────────────────────────────── */
.unit-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 18px;
}
.unit-stat-card {
    position: relative;
    padding: 14px 10px 12px;
    border-radius: 14px;
    text-align: center;
    overflow: hidden;
    transition: transform .18s ease, box-shadow .18s ease;
    cursor: default;
}
.unit-stat-card:hover { transform: translateY(-2px); }
.unit-stat-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 14px;
    opacity: .07;
    background: var(--sc-color);
}
.unit-stat-card .sc-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 8px;
    background: rgba(255,255,255,.06);
    font-size: 15px;
    color: var(--sc-color);
}
.unit-stat-card .sc-num {
    font-size: 26px; font-weight: 800; line-height: 1;
    color: var(--sc-color);
    letter-spacing: -1px;
}
.unit-stat-card .sc-label {
    font-size: 9.5px; font-weight: 700; letter-spacing: .6px;
    text-transform: uppercase; color: var(--sc-color);
    opacity: .75; margin-top: 5px;
}

/* Dipinjam banner */
.unit-dipinjam-banner {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; border-radius: 10px;
    background: rgba(253,126,20,.09);
    border: 1px solid rgba(253,126,20,.2);
    margin-bottom: 14px; font-size: 13px;
}

/* Unit cards grid */
.unit-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px;
    max-height: 370px;
    overflow-y: auto;
    padding-right: 2px;
}
.unit-cards-grid::-webkit-scrollbar { width: 5px; }
.unit-cards-grid::-webkit-scrollbar-track { background: transparent; }
.unit-cards-grid::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 3px; }

.unit-card {
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    padding: 14px 14px 12px;
    position: relative;
    transition: border-color .18s, background .18s, transform .18s;
    animation: ucFadeIn .25s ease both;
}
.unit-card:hover {
    background: rgba(255,255,255,.055);
    border-color: rgba(255,255,255,.14);
    transform: translateY(-1px);
}
@keyframes ucFadeIn {
    from { opacity:0; transform: translateY(6px); }
    to   { opacity:1; transform: translateY(0); }
}

/* left accent strip per kondisi */
.unit-card::after {
    content: '';
    position: absolute;
    left: 0; top: 18%; bottom: 18%;
    width: 3px; border-radius: 0 3px 3px 0;
    background: var(--uc-strip);
}

.unit-card-header {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 8px;
    margin-bottom: 10px;
}
.unit-kode {
    font-family: 'Courier New', monospace;
    font-size: 13.5px; font-weight: 700;
    color: var(--accent2);
    letter-spacing: .3px;
    line-height: 1.2;
}
.unit-kode small {
    display: block;
    font-size: 10px; font-weight: 400;
    color: var(--text-muted); letter-spacing: 0;
    font-family: inherit; margin-top: 2px;
}

/* pill badges */
.uc-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px;
    font-size: 10.5px; font-weight: 700; letter-spacing: .3px;
    text-transform: uppercase; white-space: nowrap;
    border: 1px solid transparent;
}

/* kondisi block */
.unit-kondisi-row {
    display: flex; align-items: center; gap: 7px;
    margin-bottom: 8px;
}

/* peminjam info */
.unit-peminjam {
    padding: 7px 10px;
    background: rgba(253,126,20,.07);
    border: 1px solid rgba(253,126,20,.15);
    border-radius: 8px;
    margin-bottom: 10px;
}
.unit-peminjam .pm-name {
    font-size: 12px; font-weight: 600;
    color: #fd9a4a;
}
.unit-peminjam .pm-date {
    font-size: 10.5px; color: var(--text-muted); margin-top: 2px;
}

/* action buttons */
.unit-card-actions {
    display: flex; gap: 6px; margin-top: 2px;
}
.uc-btn {
    flex: 1; padding: 6px 0;
    border-radius: 8px; border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.04);
    color: var(--text-secondary); font-size: 11.5px;
    cursor: pointer; transition: all .15s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.uc-btn:hover { background: rgba(255,255,255,.09); color: var(--text-primary); }
.uc-btn.danger { border-color: rgba(220,53,69,.25); color: #dc3545; }
.uc-btn.danger:hover { background: rgba(220,53,69,.12); }

.unit-dipinjam-badge {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; color: #fd7e14;
    padding: 6px 0; justify-content: center;
}

/* section separator */
.unit-section-label {
    font-size: 10px; font-weight: 700; letter-spacing: .8px;
    text-transform: uppercase; color: var(--text-muted);
    margin: 0 0 10px;
    display: flex; align-items: center; gap: 8px;
}
.unit-section-label::after {
    content: ''; flex: 1; height: 1px;
    background: rgba(255,255,255,.07);
}

/* empty state */
.unit-empty {
    grid-column: 1/-1;
    text-align: center; padding: 50px 20px;
    color: var(--text-muted); font-size: 13px;
}
.unit-empty i { font-size: 32px; opacity: .3; display: block; margin-bottom: 10px; }
</style>

<script>
const allAlat = <?= json_encode($alat) ?>;

function getKategoriOptions(selected = '') {
    const cats = <?= json_encode($kategori) ?>;
    return cats.map(k => `<option value="${k.id}" ${k.id == selected ? 'selected' : ''}>${k.nama_kategori}</option>`).join('');
}

function getAlatOptions(kategoriId = '', selected = '') {
    const filtered = allAlat.filter(a => !kategoriId || a.kategori_id == kategoriId);
    if (filtered.length === 0) return '<option value="">-- Tidak ada alat --</option>';
    return filtered.map(a => `<option value="${a.id}" ${a.id == selected ? 'selected' : ''}>${a.nama_alat}</option>`).join('');
}

// ── Form TAMBAH ─────────────────────────────────────────────────
function formTambahHtml(data = {}) {
    return `
        <div class="form-group">
            <label class="form-label">Nama Alat <span class="req">*</span></label>
            <input type="text" name="nama_alat" class="form-control" value="${data.nama||''}" placeholder="Nama alat..." required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Kategori</label>
                <select name="kategori_id" class="form-control">
                    <option value="">-- Pilih Kategori --</option>
                    ${getKategoriOptions(data.kategori||'')}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Stok Awal <span class="req">*</span></label>
                <input type="number" name="stok" class="form-control" min="0" value="${data.stok||1}" required>
                <small style="color:var(--text-muted);font-size:11px">Jumlah unit yang akan dibuat</small>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Kondisi</label>
                <select name="kondisi" class="form-control">
                    <option value="baik" ${data.kondisi==='baik'?'selected':''}>Baik</option>
                    <option value="sedang" ${data.kondisi==='sedang'?'selected':''}>Sedang</option>
                    <option value="rusak" ${data.kondisi==='rusak'?'selected':''}>Rusak</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="tersedia" ${data.status==='tersedia'?'selected':''}>Tersedia</option>
                    <option value="perawatan" ${data.status==='perawatan'?'selected':''}>Perawatan</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Harga Alat (Rp)</label>
                <input type="number" name="harga_alat" class="form-control" min="0" value="${data.harga||0}" placeholder="0">
                <small style="color:var(--text-muted);font-size:11px">Dipakai untuk menghitung denda kerusakan</small>
            </div>
            <div class="form-group">
                <label class="form-label">Denda Keterlambatan/Hari (Rp)</label>
                <input type="number" name="tarif_denda_per_hari" class="form-control" min="0" value="${data.tarif||0}" placeholder="0">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Deskripsi</label>
            <textarea name="deskripsi" class="form-control" rows="2" placeholder="Deskripsi alat...">${data.deskripsi||''}</textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
            <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
        </div>
    `;
}

// ── Form EDIT ────────────────────────────────────────────────────
function formEditHtml(data = {}) {
    return `
        <div class="form-group">
            <label class="form-label">Nama Alat <span class="req">*</span></label>
            <input type="text" name="nama_alat" class="form-control" value="${data.nama||''}" placeholder="Nama alat..." required>
        </div>
        <div class="form-group">
            <label class="form-label">Kategori</label>
            <select name="kategori_id" class="form-control">
                <option value="">-- Pilih Kategori --</option>
                ${getKategoriOptions(data.kategori||'')}
            </select>
        </div>
        <div style="margin-bottom:16px;padding:13px 15px;
                    background:rgba(255,255,255,0.03);
                    border:1px solid rgba(255,255,255,0.08);
                    border-radius:10px">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);
                        text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">
                <i class="fas fa-layer-group" style="margin-right:5px;opacity:.7"></i>
                Informasi Stok
                <span style="font-weight:400;font-size:11px;text-transform:none;letter-spacing:0;
                              color:var(--accent2);margin-left:8px">
                    (kelola via tombol <strong>Tambah Stok</strong> atau <strong>Unit</strong>)
                </span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div>
                    <label class="form-label" style="font-size:12px;margin-bottom:4px">Stok Total</label>
                    <div style="padding:9px 12px;background:rgba(255,255,255,0.04);
                                border:1px solid rgba(255,255,255,0.08);border-radius:8px;
                                font-weight:700;font-size:15px;color:var(--text-primary)">
                        ${data.stok}
                        <span style="font-size:11px;font-weight:400;color:var(--text-muted)"> unit</span>
                    </div>
                </div>
                <div>
                    <label class="form-label" style="font-size:12px;margin-bottom:4px">Stok Tersedia</label>
                    <div style="padding:9px 12px;background:rgba(0,212,170,0.06);
                                border:1px solid rgba(0,212,170,0.15);border-radius:8px;
                                font-weight:700;font-size:15px;color:var(--accent)">
                        ${data.stokLayak}
                        <span style="font-size:11px;font-weight:400;color:var(--text-muted)"> unit baik & tersedia</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Kondisi</label>
                <select name="kondisi" class="form-control">
                    <option value="baik"   ${data.kondisi==='baik'   ?'selected':''}>Baik</option>
                    <option value="sedang" ${data.kondisi==='sedang' ?'selected':''}>Sedang</option>
                    <option value="rusak"  ${data.kondisi==='rusak'  ?'selected':''}>Rusak</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="tersedia"  ${data.status==='tersedia' ?'selected':''}>Tersedia</option>
                    <option value="perawatan" ${data.status==='perawatan'?'selected':''}>Perawatan</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Harga Alat (Rp)</label>
                <input type="number" name="harga_alat" class="form-control" min="0" value="${data.harga||0}" placeholder="0">
                <small style="color:var(--text-muted);font-size:11px">Dipakai untuk menghitung denda kerusakan</small>
            </div>
            <div class="form-group">
                <label class="form-label">Denda Keterlambatan/Hari (Rp)</label>
                <input type="number" name="tarif_denda_per_hari" class="form-control" min="0" value="${data.tarif||0}" placeholder="0">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Deskripsi</label>
            <textarea name="deskripsi" class="form-control" rows="2" placeholder="Deskripsi alat...">${data.deskripsi||''}</textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
            <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
        </div>
    `;
}

function openTambahAlat() {
    openModal('Tambah Alat Baru', `
        <form id="formAlat">
            <input type="hidden" name="action" value="tambah">
            ${formTambahHtml({kondisi:'baik', status:'tersedia', stok:1})}
        </form>
    `);
    document.getElementById('formAlat').addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'api/alat.php', 'Alat berhasil ditambahkan!', 'kelola-alat.php');
    });
}

function openTambahStok() {
    openModal('Tambah Stok Alat', `
        <form id="formTambahStok">
            <input type="hidden" name="action" value="tambah_unit">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Kategori</label>
                    <select name="kategori_id" id="stokKategori" class="form-control" required>
                        <option value="">-- Pilih Kategori --</option>
                        ${getKategoriOptions()}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Alat</label>
                    <select name="alat_id" id="stokAlat" class="form-control" required>
                        <option value="">-- Pilih Alat --</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Jumlah Stok yang Ditambahkan <span class="req">*</span></label>
                <input type="number" name="jumlah" class="form-control" min="1" value="1" required>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Tambah</button>
            </div>
        </form>
    `);

    const catSelect  = document.getElementById('stokKategori');
    const alatSelect = document.getElementById('stokAlat');
    catSelect.addEventListener('change', () => {
        alatSelect.innerHTML = `<option value="">-- Pilih Alat --</option>` + getAlatOptions(catSelect.value);
    });

    document.getElementById('formTambahStok').addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'api/alat.php', 'Stok berhasil ditambahkan!', 'kelola-alat.php');
    });
}

function openEditAlat(id, nama, kategori, stok, stokLayak, kondisi, status, deskripsi, harga, tarif) {
    openModal('Edit Alat', `
        <form id="formAlat">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="${id}">
            ${formEditHtml({nama, kategori, stok, stokLayak, kondisi, status, deskripsi, harga, tarif})}
        </form>
    `);
    document.getElementById('formAlat').addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'api/alat.php', 'Alat berhasil diperbarui!', 'kelola-alat.php');
    });
}

// ── Helper: konfigurasi visual per kondisi & status ──────────────
function getKondisiCfg(kondisi) {
    const map = {
        baik:         { color:'#00d4aa', icon:'fa-check-circle',  label:'Baik' },
        rusak_ringan: { color:'#ff9f43', icon:'fa-tools',         label:'Rusak Ringan' },
        rusak_berat:  { color:'#dc3545', icon:'fa-times-circle',  label:'Rusak Berat' },
        hilang:       { color:'#ffc107', icon:'fa-question-circle',label:'Hilang' },
    };
    return map[kondisi] || { color:'var(--text-muted)', icon:'fa-circle', label: kondisi };
}

function getStatusCfg(status) {
    const map = {
        tersedia:  { color:'#00d4aa', bg:'rgba(0,212,170,.12)',  border:'rgba(0,212,170,.25)',  icon:'fa-circle-check',  label:'Tersedia' },
        dipinjam:  { color:'#fd9a4a', bg:'rgba(253,126,20,.12)', border:'rgba(253,126,20,.25)', icon:'fa-arrow-right-arrow-left', label:'Dipinjam' },
        perawatan: { color:'#6c63ff', bg:'rgba(108,99,255,.12)', border:'rgba(108,99,255,.25)', icon:'fa-wrench',        label:'Perawatan' },
        hilang:    { color:'#ffc107', bg:'rgba(255,193,7,.12)',  border:'rgba(255,193,7,.25)',  icon:'fa-ban',           label:'Hilang' },
    };
    return map[status] || { color:'var(--text-muted)', bg:'rgba(255,255,255,.05)', border:'rgba(255,255,255,.1)', icon:'fa-circle', label: status };
}

// ── LIHAT UNIT — redesigned ──────────────────────────────────────
function lihatUnit(alatId, namaAlat) {
    // Loading state
    openModal('Inventaris Unit: ' + namaAlat, `
        <div style="text-align:center;padding:56px 20px">
            <div class="spinner"></div>
            <div style="margin-top:14px;color:var(--text-muted);font-size:13px">Memuat data unit...</div>
        </div>
    `);

    fetch('api/alat.php?action=daftar_unit&alat_id=' + alatId, {credentials:'same-origin'})
        .then(r => r.json())
        .then(d => {
            if (!d.success) { showFlash(d.message, 'kesalahan'); return; }
            const units = d.units;

            // ── Hitung semua counter ──
            const uTotal       = units.length;
            const uBaik        = units.filter(u => u.kondisi === 'baik').length;
            const uTersedia    = units.filter(u => u.kondisi === 'baik' && u.status === 'tersedia').length;
            const uDipinjam    = units.filter(u => u.status === 'dipinjam').length;
            const uRusakRingan = units.filter(u => u.kondisi === 'rusak_ringan').length;
            const uRusakBerat  = units.filter(u => u.kondisi === 'rusak_berat').length;
            const uHilang      = units.filter(u => u.kondisi === 'hilang').length;
            const uPerawatan   = units.filter(u => u.status === 'perawatan' && u.kondisi !== 'rusak_ringan' && u.kondisi !== 'rusak_berat').length;

            // ── Progress bar tersedia ──
            const pct = uTotal > 0 ? Math.round((uTersedia / uTotal) * 100) : 0;
            const pctColor = pct >= 70 ? '#00d4aa' : pct >= 30 ? '#ffc107' : '#dc3545';

            // ── Stat cards ──
            const stats = [
                { num: uTotal,    label: 'Total Unit',  color: '#6c63ff', icon: 'fa-layer-group' },
                { num: uTersedia, label: 'Tersedia',    color: '#00d4aa', icon: 'fa-circle-check' },
                { num: uDipinjam, label: 'Dipinjam',    color: '#fd9a4a', icon: 'fa-arrow-right-arrow-left' },
                { num: uRusakRingan + uRusakBerat + uHilang, label: 'Bermasalah', color: '#ff6584', icon: 'fa-triangle-exclamation' },
            ];

            const statCardsHtml = stats.map(s => `
                <div class="unit-stat-card" style="--sc-color:${s.color}">
                    <div class="sc-icon"><i class="fas ${s.icon}"></i></div>
                    <div class="sc-num">${s.num}</div>
                    <div class="sc-label">${s.label}</div>
                </div>
            `).join('');

            // ── Progress bar ──
            const progressHtml = `
                <div style="margin-bottom:16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                        <span style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">
                            Ketersediaan Unit
                        </span>
                        <span style="font-size:12px;font-weight:700;color:${pctColor}">${pct}%</span>
                    </div>
                    <div style="height:6px;background:rgba(255,255,255,.07);border-radius:10px;overflow:hidden">
                        <div style="height:100%;width:${pct}%;background:${pctColor};border-radius:10px;
                                    transition:width .6s cubic-bezier(.4,0,.2,1);
                                    box-shadow:0 0 8px ${pctColor}55"></div>
                    </div>
                    <div style="display:flex;gap:14px;margin-top:8px">
                        ${uRusakRingan > 0 ? `<span style="font-size:11px;color:#ff9f43"><i class="fas fa-tools" style="margin-right:3px;font-size:10px"></i>${uRusakRingan} rusak ringan</span>` : ''}
                        ${uRusakBerat  > 0 ? `<span style="font-size:11px;color:#dc3545"><i class="fas fa-times-circle" style="margin-right:3px;font-size:10px"></i>${uRusakBerat} rusak berat</span>` : ''}
                        ${uHilang      > 0 ? `<span style="font-size:11px;color:#ffc107"><i class="fas fa-question-circle" style="margin-right:3px;font-size:10px"></i>${uHilang} hilang</span>` : ''}
                        ${uRusakRingan + uRusakBerat + uHilang === 0 ? `<span style="font-size:11px;color:var(--text-muted)">Semua unit dalam kondisi baik</span>` : ''}
                    </div>
                </div>
            `;

            // ── Dipinjam banner ──
            const dipinjamBanner = uDipinjam > 0 ? `
                <div class="unit-dipinjam-banner">
                    <i class="fas fa-arrow-right-arrow-left" style="color:#fd7e14;font-size:14px;flex-shrink:0"></i>
                    <span style="color:#fd9a4a">
                        <strong>${uDipinjam} unit</strong> sedang aktif dipinjam saat ini
                    </span>
                </div>
            ` : '';

            // ── Unit cards ──
            const unitCardsHtml = units.length === 0
                ? `<div class="unit-empty"><i class="fas fa-box-open"></i>Belum ada unit terdaftar</div>`
                : units.map((u, idx) => {
                    const kCfg = getKondisiCfg(u.kondisi);
                    const sCfg = getStatusCfg(u.status);

                    const peminjamHtml = u.nama_peminjam ? `
                        <div class="unit-peminjam">
                            <div class="pm-name"><i class="fas fa-user" style="font-size:10px;margin-right:4px;opacity:.7"></i>${u.nama_peminjam}</div>
                            <div class="pm-date"><i class="fas fa-calendar-check" style="font-size:9px;margin-right:3px;opacity:.6"></i>Kembali: ${u.tanggal_pengembalian_rencana}</div>
                        </div>
                    ` : '';

                    const actionsHtml = u.status !== 'dipinjam' ? `
                        <div class="unit-card-actions">
                            <button class="uc-btn"
                                onclick="editKondisiUnit(${u.id}, '${u.kode_unit.replace(/'/g,"\\'")}', '${u.kondisi}')">
                                <i class="fas fa-pen" style="font-size:10px"></i> Ubah
                            </button>
                            <button class="uc-btn danger"
                                onclick="hapusUnit(${u.id}, '${u.kode_unit.replace(/'/g,"\\'")}', ${alatId}, '${namaAlat.replace(/'/g,"\\'")}')">
                                <i class="fas fa-trash" style="font-size:10px"></i> Hapus
                            </button>
                        </div>
                    ` : `
                        <div class="unit-dipinjam-badge">
                            <i class="fas fa-lock" style="font-size:10px"></i> Sedang Dipinjam
                        </div>
                    `;

                    return `
                        <div class="unit-card" style="--uc-strip:${kCfg.color};animation-delay:${idx * 0.03}s">
                            <div class="unit-card-header">
                                <div>
                                    <div class="unit-kode">
                                        ${u.kode_unit}
                                        <small>#${String(idx+1).padStart(3,'0')}</small>
                                    </div>
                                </div>
                                <span class="uc-pill" style="background:${sCfg.bg};color:${sCfg.color};border-color:${sCfg.border}">
                                    <i class="fas ${sCfg.icon}" style="font-size:9px"></i>
                                    ${sCfg.label}
                                </span>
                            </div>
                            <div class="unit-kondisi-row">
                                <i class="fas ${kCfg.icon}" style="font-size:12px;color:${kCfg.color}"></i>
                                <span style="font-size:12px;color:${kCfg.color};font-weight:600">${kCfg.label}</span>
                            </div>
                            ${peminjamHtml}
                            ${actionsHtml}
                        </div>
                    `;
                }).join('');

            updateModalContent(`
                <div class="unit-stat-grid">${statCardsHtml}</div>
                ${progressHtml}
                ${dipinjamBanner}
                <div class="unit-section-label">Daftar Unit (${uTotal})</div>
                <div class="unit-cards-grid">${unitCardsHtml}</div>
            `);
        })
        .catch(() => showFlash('Gagal memuat data unit', 'kesalahan'));
}

function updateModalContent(html) {
    const body = document.querySelector('#mainModal .modal-body, .modal-body');
    if (body) body.innerHTML = html;
}

function editKondisiUnit(unitId, kodeUnit, kondisiLama) {
    const kondisiOpt = ['baik','rusak_ringan','rusak_berat','hilang'].map(k =>
        `<option value="${k}" ${k===kondisiLama?'selected':''}>${k.replace(/_/g,' ')}</option>`
    ).join('');

    openModal('Ubah Kondisi Unit: ' + kodeUnit, `
        <form id="formEditUnit">
            <input type="hidden" name="action" value="edit_unit">
            <input type="hidden" name="unit_id" value="${unitId}">
            <div class="form-group">
                <label class="form-label">Kondisi Unit <span class="req">*</span></label>
                <select name="kondisi" class="form-control" required>
                    ${kondisiOpt}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Catatan</label>
                <textarea name="catatan" class="form-control" rows="2" placeholder="Misal: komponen X rusak, sudah diganti..."></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </form>
    `);
    document.getElementById('formEditUnit').addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'api/alat.php', 'Kondisi unit berhasil diperbarui!', 'kelola-alat.php');
    });
}

function hapusAlat(id, nama) {
    confirmDialog(
        `Apakah Anda yakin ingin menghapus alat <strong>${nama}</strong>?<br>
         <small style="color:#ffc107;margin-top:6px;display:block">
             <i class="fas fa-exclamation-triangle"></i>
             Semua unit alat ini juga akan dihapus permanen dan tidak bisa dikembalikan.
         </small>`,
        function() {
            fetch('api/alat.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `action=hapus&id=${id}`
            })
            .then(r => r.json())
            .then(d => {
                showFlash(d.message || 'Berhasil', d.success ? 'sukses' : 'kesalahan');
                if (d.success) setTimeout(() => location.reload(), 1200);
            })
            .catch(() => showFlash('Koneksi gagal', 'kesalahan'));
        },
        'Hapus Alat'
    );
}

function hapusUnit(unitId, kodeUnit, alatId, namaAlat) {
    confirmDialog(
        `Hapus unit <strong>${kodeUnit}</strong>?<br>
         <small style="color:var(--text-muted);margin-top:4px;display:block">
             Unit ini akan dihapus permanen dari inventaris.
         </small>`,
        function() {
            fetch('api/alat.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `action=hapus_unit&unit_id=${unitId}`
            })
            .then(r => r.json())
            .then(d => {
                showFlash(d.message || 'Berhasil', d.success ? 'sukses' : 'kesalahan');
                if (d.success) setTimeout(() => lihatUnit(alatId, namaAlat), 700);
            })
            .catch(() => showFlash('Koneksi gagal', 'kesalahan'));
        },
        'Hapus Unit'
    );
}
</script>
<?php
require_once 'config.php';
startSession();
requireRole('admin');

$pageTitle = 'Kelola User';
$user = getCurrentUser();
$db = getDB();

// Query users lengkap: status manual + is_blocked (denda) + info denda
$users = $db->query("
    SELECT
        u.*,
        r.nama_role,
        COALESCE(denda_info.jumlah_denda, 0)    AS jumlah_denda_belum_lunas,
        COALESCE(denda_info.total_denda,  0)    AS total_denda_belum_lunas,
        COALESCE(pinjam_info.jumlah_pinjam, 0)  AS jumlah_pinjam_aktif
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN (
        SELECT p.user_id,
               COUNT(d.id)         AS jumlah_denda,
               SUM(d.total_denda)  AS total_denda
        FROM denda d
        JOIN peminjaman p ON p.id = d.peminjaman_id
        WHERE d.status = 'belum_bayar'
        GROUP BY p.user_id
    ) denda_info ON denda_info.user_id = u.id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS jumlah_pinjam
        FROM peminjaman
        WHERE status IN ('menunggu','disetujui','dipinjam')
        GROUP BY user_id
    ) pinjam_info ON pinjam_info.user_id = u.id
    ORDER BY u.id ASC
")->fetchAll();

include 'inc/header.php';

// Hitung ringkasan
$totalAktif         = 0;
$totalBlokirManual  = 0;
$totalBlokirDenda   = 0;
foreach ($users as $u) {
    $blokirDenda  = (int)$u['is_blocked'] === 1;
    $blokirManual = $u['status'] === 'nonaktif';
    if (!$blokirDenda && !$blokirManual) $totalAktif++;
    if ($blokirManual)  $totalBlokirManual++;
    if ($blokirDenda)   $totalBlokirDenda++;
}
?>

<!-- PAGE HEADER -->
<div class="page-header">
    <div>
        <h1 class="page-title">Kelola <span>User</span></h1>
        <p class="page-subtitle">Tambah dan kelola akun pengguna sistem</p>
    </div>
    <button class="btn btn-primary" onclick="openTambahUser()">
        <i class="fas fa-user-plus"></i> Tambah User
    </button>
</div>

<!-- RINGKASAN -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
    <div style="padding:14px 16px;background:rgba(0,212,170,0.07);
                border:1px solid rgba(0,212,170,0.18);border-radius:12px;
                display:flex;align-items:center;gap:12px">
        <i class="fas fa-user-check" style="font-size:22px;color:var(--accent)"></i>
        <div>
            <div style="font-size:22px;font-weight:700;color:var(--accent);line-height:1"><?= $totalAktif ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px">User Aktif Normal</div>
        </div>
    </div>
    <div style="padding:14px 16px;background:rgba(220,53,69,0.07);
                border:1px solid rgba(220,53,69,0.18);border-radius:12px;
                display:flex;align-items:center;gap:12px">
        <i class="fas fa-ban" style="font-size:22px;color:#dc3545"></i>
        <div>
            <div style="font-size:22px;font-weight:700;color:#dc3545;line-height:1"><?= $totalBlokirManual ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Diblokir Manual</div>
        </div>
    </div>
    <div style="padding:14px 16px;background:rgba(255,193,7,0.07);
                border:1px solid rgba(255,193,7,0.18);border-radius:12px;
                display:flex;align-items:center;gap:12px">
        <i class="fas fa-receipt" style="font-size:22px;color:#ffc107"></i>
        <div>
            <div style="font-size:22px;font-weight:700;color:#ffc107;line-height:1"><?= $totalBlokirDenda ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Diblokir Karena Denda</div>
        </div>
    </div>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Cari nama, username, email...">
    </div>
    <select id="filterStatus" class="form-control" style="max-width:180px" onchange="filterTable()">
        <option value="">Semua Status</option>
        <option value="aktif">Aktif Normal</option>
        <option value="blokir_manual">Diblokir Manual</option>
        <option value="blokir_denda">Diblokir (Denda)</option>
    </select>
    <span style="font-size:12px;color:var(--text-muted);margin-left:auto"><?= count($users) ?> user</span>
</div>

<!-- TABLE -->
<div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama</th>
                    <th>Username / Email</th>
                    <th>Role</th>
                    <th>Status Akun</th>
                    <th>Denda Belum Lunas</th>
                    <th style="width:230px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="7" class="table-empty"><i class="fas fa-users"></i><br>Belum ada user</td></tr>
                <?php else: ?>
                <?php foreach ($users as $i => $u):
                    $blokirDenda  = (int)$u['is_blocked']  === 1;
                    $blokirManual = $u['status'] === 'nonaktif';
                    $adaDenda     = (int)$u['jumlah_denda_belum_lunas'] > 0;

                    // Tentukan kategori filter
                    if ($blokirManual)      $filterCat = 'blokir_manual';
                    elseif ($blokirDenda)   $filterCat = 'blokir_denda';
                    else                   $filterCat = 'aktif';
                ?>
                <tr class="filterable-row" data-filter="<?= $filterCat ?>">
                    <td style="color:var(--text-muted);font-size:12px"><?= $i + 1 ?></td>

                    <!-- Nama -->
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div style="width:36px;height:36px;border-radius:9px;flex-shrink:0;
                                        display:flex;align-items:center;justify-content:center;
                                        font-size:12px;font-weight:700;color:#fff;
                                        background:linear-gradient(135deg,<?= ($blokirManual || $blokirDenda) ? '#6c757d,#495057' : 'var(--accent),var(--accent2)' ?>);
                                        opacity:<?= ($blokirManual || $blokirDenda) ? '0.7' : '1' ?>">
                                <?= strtoupper(substr($u['nama'], 0, 2)) ?>
                            </div>
                            <div>
                                <div style="font-weight:500;color:var(--text-primary)"><?= sanitize($u['nama']) ?></div>
                                <?php if ($blokirManual): ?>
                                <div style="font-size:10.5px;color:#dc3545;font-weight:600;margin-top:1px">
                                    <i class="fas fa-ban" style="margin-right:3px"></i>Diblokir Admin
                                </div>
                                <?php elseif ($blokirDenda): ?>
                                <div style="font-size:10.5px;color:#ffc107;font-weight:600;margin-top:1px">
                                    <i class="fas fa-lock" style="margin-right:3px"></i>Terkunci — Denda Belum Lunas
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <!-- Username / Email -->
                    <td>
                        <div style="font-size:12.5px;color:var(--text-primary)">@<?= sanitize($u['username']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= sanitize($u['email']) ?></div>
                    </td>

                    <!-- Role -->
                    <td>
                        <span class="badge badge-<?= $u['nama_role'] === 'admin' ? 'success' : ($u['nama_role'] === 'petugas' ? 'info' : 'warning') ?>">
                            <?= ucfirst($u['nama_role']) ?>
                        </span>
                    </td>

                    <!-- Status Akun — bedakan 3 kondisi -->
                    <td>
                        <?php if ($blokirManual): ?>
                            <span style="background:rgba(220,53,69,0.12);color:#dc3545;font-weight:700;
                                         padding:4px 10px;border-radius:20px;font-size:12px;
                                         display:inline-flex;align-items:center;gap:5px">
                                <i class="fas fa-ban"></i> Diblokir
                            </span>
                            <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px">oleh Admin</div>
                        <?php elseif ($blokirDenda): ?>
                            <span style="background:rgba(255,193,7,0.12);color:#ffc107;font-weight:700;
                                         padding:4px 10px;border-radius:20px;font-size:12px;
                                         display:inline-flex;align-items:center;gap:5px">
                                <i class="fas fa-lock"></i> Terkunci
                            </span>
                            <div style="font-size:10.5px;color:var(--text-muted);margin-top:3px">otomatis — ada denda</div>
                        <?php else: ?>
                            <span style="background:rgba(0,212,170,0.12);color:var(--accent);font-weight:700;
                                         padding:4px 10px;border-radius:20px;font-size:12px;
                                         display:inline-flex;align-items:center;gap:5px">
                                <i class="fas fa-check-circle"></i> Aktif
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Denda Belum Lunas -->
                    <td>
                        <?php if ($adaDenda): ?>
                            <div>
                                <span style="background:rgba(220,53,69,0.1);color:#dc3545;font-weight:700;
                                             padding:3px 8px;border-radius:20px;font-size:12px">
                                    <?= $u['jumlah_denda_belum_lunas'] ?> tagihan
                                </span>
                                <div style="font-size:11.5px;color:#dc3545;font-weight:700;margin-top:3px">
                                    Rp <?= number_format((int)$u['total_denda_belum_lunas'], 0, ',', '.') ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Aksi -->
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <!-- Edit selalu tampil -->
                            <button class="btn btn-secondary btn-sm" onclick="openEditUser(
                                <?= $u['id'] ?>,
                                '<?= addslashes(sanitize($u['nama'])) ?>',
                                '<?= addslashes(sanitize($u['email'])) ?>',
                                '<?= addslashes(sanitize($u['username'])) ?>',
                                <?= $u['role_id'] ?>,
                                '<?= addslashes(sanitize($u['telepon'] ?? '')) ?>',
                                '<?= $u['status'] ?>'
                            )"><i class="fas fa-pencil-alt"></i> Edit</button>

                            <?php if ($u['id'] !== $user['id']): ?>
                                <?php if ($blokirManual): ?>
                                <!-- Diblokir manual → tombol Aktifkan Manual -->
                                <button class="btn btn-sm"
                                    style="background:rgba(0,212,170,0.15);color:var(--accent);border:1px solid rgba(0,212,170,0.3)"
                                    onclick="aktifkanUser(<?= $u['id'] ?>, '<?= addslashes(sanitize($u['nama'])) ?>', 'manual', <?= (int)$u['jumlah_denda_belum_lunas'] ?>, <?= (int)$u['total_denda_belum_lunas'] ?>)">
                                    <i class="fas fa-unlock"></i> Aktifkan
                                </button>

                                <?php elseif ($blokirDenda): ?>
                                <!-- Diblokir denda → tombol Bebaskan Blokir Denda -->
                                <button class="btn btn-sm"
                                    style="background:rgba(255,193,7,0.15);color:#ffc107;border:1px solid rgba(255,193,7,0.4)"
                                    onclick="aktifkanUser(<?= $u['id'] ?>, '<?= addslashes(sanitize($u['nama'])) ?>', 'denda', <?= (int)$u['jumlah_denda_belum_lunas'] ?>, <?= (int)$u['total_denda_belum_lunas'] ?>)">
                                    <i class="fas fa-lock-open"></i> Bebaskan
                                </button>

                                <?php else: ?>
                                <!-- Aktif normal → tombol Blokir -->
                                <button class="btn btn-sm"
                                    style="background:rgba(220,53,69,0.15);color:#dc3545;border:1px solid rgba(220,53,69,0.3)"
                                    onclick="blokirUser(<?= $u['id'] ?>, '<?= addslashes(sanitize($u['nama'])) ?>')">
                                    <i class="fas fa-ban"></i> Blokir
                                </button>
                                <?php endif; ?>

                                <button class="btn btn-danger btn-sm"
                                    onclick="hapusUser(<?= $u['id'] ?>, '<?= addslashes(sanitize($u['nama'])) ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
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
// ── Filter tabel ─────────────────────────────────────────────────
function filterTable() {
    const q      = (document.getElementById('searchInput')?.value || '').toLowerCase();
    const filter = document.getElementById('filterStatus')?.value || '';
    document.querySelectorAll('.filterable-row').forEach(row => {
        const matchText   = row.textContent.toLowerCase().includes(q);
        const matchFilter = !filter || row.dataset.filter === filter;
        row.style.display = (matchText && matchFilter) ? '' : 'none';
    });
}
document.getElementById('searchInput')?.addEventListener('input', filterTable);

// ── Role options ─────────────────────────────────────────────────
function getRoleOptions(selected) {
    selected = parseInt(selected);
    return `<option value="1" ${selected===1?'selected':''}>Admin</option>
            <option value="2" ${selected===2?'selected':''}>Petugas</option>
            <option value="3" ${selected===3?'selected':''}>User</option>`;
}

// ── Kirim form ───────────────────────────────────────────────────
function kirimFormUser(form, successMsg) {
    const btn = form.querySelector('button[type="submit"]');
    const ori = btn?.innerHTML || '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...'; }
    fetch('api/user.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showFlash(d.message || successMsg, 'sukses'); closeModal(); setTimeout(() => location.reload(), 1200); }
        else showFlash(d.message || 'Terjadi kesalahan', 'kesalahan');
    })
    .catch(err => showFlash('Koneksi gagal: ' + err.message, 'kesalahan'))
    .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = ori; } });
}

// ── Tambah User ──────────────────────────────────────────────────
function openTambahUser() {
    openModal('Tambah User Baru', `
        <form id="formTambahUser">
            <input type="hidden" name="action" value="tambah">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap <span class="req">*</span></label>
                    <input type="text" name="nama" class="form-control" placeholder="Nama lengkap" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username <span class="req">*</span></label>
                    <input type="text" name="username" class="form-control" placeholder="Username (tanpa spasi)" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email <span class="req">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Telepon</label>
                    <input type="text" name="telepon" class="form-control" placeholder="08123456789">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Role <span class="req">*</span></label>
                    <select name="role_id" class="form-control" required>${getRoleOptions(3)}</select>
                </div>
                <div class="form-group">
                    <label class="form-label">Password <span class="req">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="Min. 6 karakter" required minlength="6">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Tambah User</button>
            </div>
        </form>`);
    document.getElementById('formTambahUser').addEventListener('submit', function(e) {
        e.preventDefault();
        if (this.querySelector('[name="password"]').value.length < 6) { showFlash('Password minimal 6 karakter!','kesalahan'); return; }
        kirimFormUser(this, 'User berhasil ditambahkan!');
    });
}

// ── Edit User ────────────────────────────────────────────────────
function openEditUser(id, nama, email, username, roleId, telepon, status) {
    openModal('Edit User', `
        <form id="formEditUser">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="${id}">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap <span class="req">*</span></label>
                    <input type="text" name="nama" class="form-control" value="${nama}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username <span class="req">*</span></label>
                    <input type="text" name="username" class="form-control" value="${username}" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Email <span class="req">*</span></label>
                    <input type="email" name="email" class="form-control" value="${email}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Telepon</label>
                    <input type="text" name="telepon" class="form-control" value="${telepon}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Role <span class="req">*</span></label>
                    <select name="role_id" class="form-control" required>${getRoleOptions(roleId)}</select>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="aktif" ${status==='aktif'?'selected':''}>Aktif</option>
                        <option value="nonaktif" ${status==='nonaktif'?'selected':''}>Nonaktif / Blokir</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Password Baru
                    <span style="color:var(--text-muted);font-weight:400;font-size:12px">(kosongkan jika tidak diubah)</span>
                </label>
                <input type="password" name="password" class="form-control" placeholder="Password baru min. 6 karakter">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </form>`);
    document.getElementById('formEditUser').addEventListener('submit', function(e) {
        e.preventDefault();
        const pw = this.querySelector('[name="password"]').value;
        if (pw && pw.length < 6) { showFlash('Password minimal 6 karakter!','kesalahan'); return; }
        kirimFormUser(this, 'User berhasil diperbarui!');
    });
}

// ── Blokir User (manual oleh admin) ─────────────────────────────
function blokirUser(id, nama) {
    openModal('Blokir Akun User', `
        <div style="margin-bottom:18px;padding:14px 16px;
                    background:rgba(220,53,69,0.08);border:1px solid rgba(220,53,69,0.25);
                    border-radius:12px;display:flex;align-items:flex-start;gap:12px">
            <i class="fas fa-ban" style="font-size:22px;color:#dc3545;margin-top:2px;flex-shrink:0"></i>
            <div>
                <div style="font-weight:600;font-size:14px;color:#dc3545;margin-bottom:4px">
                    Blokir akun <strong>${nama}</strong>?
                </div>
                <div style="font-size:13px;color:var(--text-muted);line-height:1.7">
                    User tidak akan bisa login dan mengajukan peminjaman selama diblokir.
                    Anda dapat mengaktifkan kembali kapan saja.
                </div>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
            <button class="btn btn-danger" id="btnKonfirmasiBlokir">
                <i class="fas fa-ban"></i> Ya, Blokir
            </button>
        </div>`);

    document.getElementById('btnKonfirmasiBlokir').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
        const fd = new FormData();
        fd.append('action', 'blokir_manual');
        fd.append('id', id);
        fetch('api/user.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showFlash(d.message || 'Berhasil', d.success ? 'sukses' : 'kesalahan');
            if (d.success) { closeModal(); setTimeout(() => location.reload(), 1200); }
            else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Ya, Blokir'; }
        })
        .catch(() => showFlash('Koneksi gagal', 'kesalahan'));
    });
}

// ── Aktifkan / Bebaskan Blokir ───────────────────────────────────
// jenisBlokir: 'manual' (status=nonaktif) | 'denda' (is_blocked=1)
// jumlahDenda, totalDenda: diteruskan dari PHP untuk tampilan
function aktifkanUser(id, nama, jenisBlokir, jumlahDenda, totalDenda) {
    // Tampilkan loading dulu sambil fetch detail denda terbaru dari server
    openModal(
        jenisBlokir === 'denda' ? 'Bebaskan Blokir Denda — ' + nama : 'Aktifkan Akun — ' + nama,
        '<div style="text-align:center;padding:36px">' +
        '<i class="fas fa-spinner fa-spin" style="font-size:28px;color:var(--accent)"></i>' +
        '<div style="margin-top:12px;color:var(--text-muted)">Memeriksa data denda...</div></div>'
    );

    fetch('api/user.php?action=cek_denda&user_id=' + id)
    .then(r => r.json())
    .then(d => {
        if (!d.success) { showFlash(d.message || 'Gagal memuat data', 'kesalahan'); return; }

        const dendaList  = d.denda      || [];
        const totalD     = d.total_denda || 0;
        const adaDenda   = dendaList.length > 0;

        // ── Bagian peringatan denda ──
        let dendaSection = '';
        if (adaDenda) {
            const rows = dendaList.map(item => `
                <div style="display:flex;justify-content:space-between;align-items:flex-start;
                            gap:12px;padding:10px 12px;margin-bottom:6px;
                            background:rgba(220,53,69,0.06);
                            border:1px solid rgba(220,53,69,0.15);border-radius:8px">
                    <div style="flex:1">
                        <div style="font-weight:600;font-size:13px;color:var(--text-primary)">
                            ${item.nama_alat
                                ? item.nama_alat
                                : '<span style="color:var(--text-muted);font-style:italic">Alat sudah dihapus dari sistem</span>'}
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                            ${item.jenis === 'keterlambatan'
                                ? `<i class="fas fa-clock" style="margin-right:4px"></i>Keterlambatan ${item.hari_terlambat} hari`
                                : `<i class="fas fa-tools" style="margin-right:4px"></i>Kerusakan: ${(item.kondisi_kerusakan||'-').replace(/_/g,' ')}`}
                        </div>
                    </div>
                    <div style="font-weight:700;font-size:14px;color:#dc3545;white-space:nowrap">
                        Rp ${Number(item.total_denda).toLocaleString('id-ID')}
                    </div>
                </div>`).join('');

            const labelJenis = jenisBlokir === 'denda'
                ? 'Akun ini diblokir otomatis oleh sistem karena denda di bawah belum dilunasi.'
                : 'User ini memiliki denda yang belum dilunasi.';

            dendaSection = `
                <div style="margin-bottom:16px;padding:14px 16px;
                            background:rgba(220,53,69,0.08);border:1px solid rgba(220,53,69,0.3);
                            border-radius:12px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                        <i class="fas fa-exclamation-triangle" style="font-size:20px;color:#dc3545;flex-shrink:0"></i>
                        <div>
                            <div style="font-weight:700;font-size:14px;color:#dc3545">
                                Peringatan: Ada Denda Belum Lunas!
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                                ${labelJenis}
                            </div>
                        </div>
                    </div>
                    <div style="max-height:200px;overflow-y:auto;margin-bottom:10px">${rows}</div>
                    <div style="display:flex;justify-content:space-between;align-items:center;
                                padding:10px 12px;background:rgba(220,53,69,0.1);
                                border-radius:8px;font-weight:700">
                        <span style="font-size:13px;color:var(--text-muted)">Total Tagihan</span>
                        <span style="font-size:15px;color:#dc3545">Rp ${totalD.toLocaleString('id-ID')}</span>
                    </div>
                    <div style="margin-top:10px;padding:10px 12px;
                                background:rgba(255,193,7,0.1);border-radius:8px;
                                font-size:12.5px;color:#ffc107;line-height:1.7">
                        <i class="fas fa-info-circle" style="margin-right:6px"></i>
                        Disarankan agar denda diselesaikan dahulu. Namun jika diperlukan,
                        Anda tetap dapat mengaktifkan akun ini secara paksa.
                        <strong>Denda tetap tercatat dan harus dilunasi.</strong>
                    </div>
                </div>`;
        } else {
            dendaSection = `
                <div style="margin-bottom:16px;padding:14px 16px;
                            background:rgba(0,212,170,0.07);border:1px solid rgba(0,212,170,0.2);
                            border-radius:12px;display:flex;align-items:center;gap:10px">
                    <i class="fas fa-check-circle" style="font-size:20px;color:var(--accent);flex-shrink:0"></i>
                    <div style="font-size:13px;color:var(--text-secondary);line-height:1.6">
                        Tidak ada denda tertunggak. Akun dapat diaktifkan dengan aman.
                    </div>
                </div>`;
        }

        const isForDenda   = jenisBlokir === 'denda';
        const btnLabel     = isForDenda
            ? (adaDenda ? 'Bebaskan (Abaikan Denda)' : 'Bebaskan Blokir')
            : (adaDenda ? 'Aktifkan (Abaikan Denda)' : 'Aktifkan Akun');
        const btnStyle     = adaDenda
            ? 'background:rgba(255,193,7,0.15);color:#ffc107;border:1px solid rgba(255,193,7,0.4)'
            : 'background:rgba(0,212,170,0.15);color:var(--accent);border:1px solid rgba(0,212,170,0.3)';
        const btnIcon      = isForDenda ? 'fa-lock-open' : 'fa-unlock';

        const body = document.querySelector('.modal-body');
        if (!body) return;
        body.innerHTML = `
            <!-- Info user -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;
                        padding:13px 15px;background:rgba(255,255,255,0.03);
                        border:1px solid rgba(255,255,255,0.08);border-radius:12px">
                <div style="width:40px;height:40px;border-radius:10px;
                            background:linear-gradient(135deg,var(--accent),var(--accent2));
                            display:flex;align-items:center;justify-content:center;
                            font-size:14px;font-weight:700;color:#fff;flex-shrink:0">
                    ${nama.substring(0,2).toUpperCase()}
                </div>
                <div>
                    <div style="font-weight:600;font-size:14px">${nama}</div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                        ${isForDenda
                            ? '<i class="fas fa-lock" style="color:#ffc107;margin-right:4px"></i>Terkunci oleh sistem karena denda'
                            : '<i class="fas fa-ban" style="color:#dc3545;margin-right:4px"></i>Diblokir secara manual oleh admin'}
                    </div>
                </div>
            </div>
            ${dendaSection}
            <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button class="btn btn-sm" id="btnAktifkanConfirm"
                        style="padding:8px 16px;font-size:13px;font-weight:600;border-radius:9px;${btnStyle}">
                    <i class="fas ${btnIcon}" style="margin-right:6px"></i>${btnLabel}
                </button>
            </div>`;

        document.getElementById('btnAktifkanConfirm').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
            const fd = new FormData();
            fd.append('action', isForDenda ? 'bebaskan_blokir_denda' : 'aktifkan_manual');
            fd.append('id', id);
            fetch('api/user.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showFlash(data.message || 'Berhasil', data.success ? 'sukses' : 'kesalahan');
                if (data.success) { closeModal(); setTimeout(() => location.reload(), 1200); }
                else { btn.disabled = false; btn.innerHTML = `<i class="fas ${btnIcon}"></i> ${btnLabel}`; }
            })
            .catch(() => showFlash('Koneksi gagal', 'kesalahan'));
        });
    })
    .catch(() => showFlash('Koneksi gagal', 'kesalahan'));
}

// ── Hapus User ───────────────────────────────────────────────────
function hapusUser(id, nama) {
    openModal('Hapus User', `
        <div style="padding:4px 0 18px">
            <p style="color:var(--text-secondary);font-size:14px;line-height:1.7;margin:0">
                Apakah Anda yakin ingin menghapus user <strong>${nama}</strong>?<br>
                <span style="color:#dc3545;font-size:13px">
                    <i class="fas fa-exclamation-triangle"></i> Tindakan ini tidak dapat dibatalkan!
                </span>
            </p>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Batal</button>
            <button class="btn btn-danger" id="btnKonfirmasiHapus"><i class="fas fa-trash"></i> Ya, Hapus</button>
        </div>`);
    document.getElementById('btnKonfirmasiHapus').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menghapus...';
        const fd = new FormData();
        fd.append('action', 'hapus');
        fd.append('id', String(id));
        fetch('api/user.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showFlash(data.message || 'Berhasil', data.success ? 'sukses' : 'kesalahan');
            if (data.success) { closeModal(); setTimeout(() => location.reload(), 1200); }
            else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Ya, Hapus'; }
        })
        .catch(err => showFlash('Koneksi gagal: ' + err.message, 'kesalahan'));
    });
}
</script>
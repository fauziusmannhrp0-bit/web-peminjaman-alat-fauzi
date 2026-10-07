<?php
require_once 'config.php';
startSession();
requireRole('admin');

$pageTitle = 'Laporan Denda';
$user      = getCurrentUser();
$db        = getDB();

// Denda belum lunas (hanya pantau, tidak ada aksi konfirmasi)
$dendaBelumLunas = $db->query("
    SELECT d.*, p.tanggal_pinjam, p.tanggal_pengembalian_rencana,
           p.tanggal_pengembalian_aktual, p.kondisi_pengembalian,
           a.nama_alat, u.nama as nama_user, u.username, u.kelas, u.nis,
           u.is_blocked,
           pt.nama as nama_petugas
    FROM denda d
    JOIN peminjaman p ON d.peminjaman_id = p.id
    JOIN alat a ON p.alat_id = a.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users pt ON d.dikonfirmasi_oleh = pt.id
    WHERE d.status = 'belum_bayar'
    ORDER BY d.total_denda DESC
")->fetchAll();

// Riwayat denda lunas
$dendaLunas = $db->query("
    SELECT d.*, p.tanggal_pinjam, a.nama_alat,
           u.nama as nama_user, u.username, u.kelas, u.nis,
           pt.nama as nama_petugas
    FROM denda d
    JOIN peminjaman p ON d.peminjaman_id = p.id
    JOIN alat a ON p.alat_id = a.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN users pt ON d.dikonfirmasi_oleh = pt.id
    WHERE d.status = 'lunas'
    ORDER BY d.dibayar_at DESC
    LIMIT 50
")->fetchAll();

// Statistik lengkap
$totalBelumLunas  = array_sum(array_column($dendaBelumLunas, 'total_denda'));
$totalLunas       = (int)$db->query("SELECT COALESCE(SUM(total_denda),0) as t FROM denda WHERE status='lunas'")->fetch()['t'];
$totalSemuaDenda  = (int)$db->query("SELECT COALESCE(SUM(total_denda),0) as t FROM denda")->fetch()['t'];
$jumlahUserBlokir = (int)$db->query("SELECT COUNT(*) as c FROM users WHERE is_blocked=1")->fetch()['c'];
$jumlahDendaLunas = (int)$db->query("SELECT COUNT(*) as c FROM denda WHERE status='lunas'")->fetch()['c'];
$jumlahDendaBelum = count($dendaBelumLunas);

// Denda tertinggi bulan ini
$dendaBulanIni = (int)$db->query("
    SELECT COALESCE(SUM(total_denda),0) as t FROM denda
    WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())
")->fetch()['t'];

// Top 5 user dengan denda terbanyak
$topUserDenda = $db->query("
    SELECT u.nama, u.kelas, u.nis,
           COUNT(d.id) as jumlah_denda,
           SUM(d.total_denda) as total,
           SUM(CASE WHEN d.status='belum_bayar' THEN d.total_denda ELSE 0 END) as sisa_tagihan,
           u.is_blocked
    FROM denda d
    JOIN peminjaman p ON d.peminjaman_id = p.id
    JOIN users u ON p.user_id = u.id
    GROUP BY u.id
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

// Rekapitulasi per jenis denda
$rekapJenis = $db->query("
    SELECT jenis,
           COUNT(*) as jumlah,
           SUM(total_denda) as total,
           SUM(CASE WHEN status='lunas' THEN total_denda ELSE 0 END) as terbayar
    FROM denda
    GROUP BY jenis
")->fetchAll();

// Konfigurasi denda
$config = getKonfigurasiDenda();

include 'inc/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laporan <span>Denda</span></h1>
        <p class="page-subtitle">Pantau dan analisis data denda — konfirmasi pembayaran dilakukan oleh Petugas</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-secondary" onclick="openKonfigurasi()">
            <i class="fas fa-cog"></i> Konfigurasi Denda
        </button>
    </div>
</div>

<!-- INFO BANNER: peran admin -->
<div style="background:rgba(108,99,255,0.08);border:1px solid rgba(108,99,255,0.2);border-radius:12px;
            padding:14px 18px;margin-bottom:24px;display:flex;align-items:center;gap:12px">
    <i class="fas fa-info-circle" style="color:var(--accent2);font-size:18px;flex-shrink:0"></i>
    <div style="font-size:13px;color:var(--text-secondary)">
        <strong style="color:var(--text-primary)">Mode Admin — Pantau & Analisis.</strong>
        Konfirmasi pelunasan denda merupakan wewenang <strong style="color:var(--accent2)">Petugas</strong>
        saat menginspeksi alat yang dikembalikan. Halaman ini menampilkan laporan menyeluruh untuk keperluan monitoring.
    </div>
</div>

<!-- STATISTIK UTAMA -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:28px">
    <div class="card" style="padding:20px;text-align:center;border-color:rgba(255,101,132,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">
            <i class="fas fa-clock" style="margin-right:4px"></i>Belum Lunas
        </div>
        <div style="font-size:24px;font-weight:700;color:var(--accent3)"><?= formatRupiah($totalBelumLunas) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $jumlahDendaBelum ?> tagihan aktif</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;border-color:rgba(0,212,170,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">
            <i class="fas fa-check-circle" style="margin-right:4px"></i>Terkumpul
        </div>
        <div style="font-size:24px;font-weight:700;color:var(--accent)"><?= formatRupiah($totalLunas) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $jumlahDendaLunas ?> denda lunas</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;border-color:rgba(77,166,255,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">
            <i class="fas fa-calendar-alt" style="margin-right:4px"></i>Bulan Ini
        </div>
        <div style="font-size:24px;font-weight:700;color:#4da6ff"><?= formatRupiah($dendaBulanIni) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">total denda diterbitkan</div>
    </div>
    <div class="card" style="padding:20px;text-align:center;border-color:rgba(108,99,255,0.2)">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:8px">
            <i class="fas fa-user-lock" style="margin-right:4px"></i>User Diblokir
        </div>
        <div style="font-size:24px;font-weight:700;color:var(--accent2)"><?= $jumlahUserBlokir ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">akibat denda belum lunas</div>
    </div>
</div>

<!-- REKAP & TOP USER (2 kolom) -->
<div class="grid-2" style="margin-bottom:28px">

    <!-- Rekap per jenis -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-chart-pie"></i> Rekap per Jenis Denda</div>
        </div>
        <?php if (empty($rekapJenis)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">Belum ada data denda</div>
        <?php else: ?>
        <?php foreach ($rekapJenis as $r):
            $pct = $r['total'] > 0 ? round(($r['terbayar'] / $r['total']) * 100) : 0;
            $isKeterlambatan = $r['jenis'] === 'keterlambatan';
            $warna = $isKeterlambatan ? '#ffc107' : 'var(--accent3)';
            $label = $isKeterlambatan ? 'Keterlambatan' : 'Kerusakan/Hilang';
        ?>
        <div style="margin-bottom:18px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <div>
                    <span style="font-size:13px;font-weight:600;color:var(--text-primary)"><?= $label ?></span>
                    <span style="font-size:11px;color:var(--text-muted);margin-left:8px"><?= $r['jumlah'] ?> kasus</span>
                </div>
                <div style="text-align:right">
                    <div style="font-size:13px;font-weight:700;color:<?= $warna ?>"><?= formatRupiah($r['total']) ?></div>
                    <div style="font-size:11px;color:var(--accent)"><?= formatRupiah($r['terbayar']) ?> terbayar</div>
                </div>
            </div>
            <div class="progress-bar-wrap">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $isKeterlambatan ? 'linear-gradient(90deg,#ffc107,#f39c12)' : 'linear-gradient(90deg,var(--accent3),#e84393)' ?>"></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= $pct ?>% sudah terbayar</div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Top 5 user denda terbesar -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-trophy"></i> Top 5 User Bermasalah</div>
        </div>
        <?php if (empty($topUserDenda)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">Belum ada data</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($topUserDenda as $i => $u): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;
                        background:rgba(255,255,255,0.02);border-radius:8px;
                        border:1px solid var(--glass-border)">
                <!-- Nomor urut -->
                <div style="width:28px;height:28px;border-radius:50%;flex-shrink:0;
                            display:flex;align-items:center;justify-content:center;
                            font-size:12px;font-weight:700;
                            background:<?= $i===0?'rgba(255,193,7,0.15)':($i===1?'rgba(189,189,189,0.1)':($i===2?'rgba(205,127,50,0.1)':'rgba(255,255,255,0.04)')) ?>;
                            color:<?= $i===0?'#ffc107':($i===1?'#bdbdbd':($i===2?'#cd7f32':'var(--text-muted)')) ?>">
                    <?= $i+1 ?>
                </div>
                <!-- Info user -->
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;color:var(--text-primary);
                                white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= sanitize($u['nama']) ?>
                        <?php if ($u['is_blocked']): ?>
                            <span class="badge badge-danger" style="font-size:10px;margin-left:4px">Blokir</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted)">
                        <?= $u['kelas'] ? $u['kelas'] : '-' ?>
                        <?= $u['nis'] ? ' · NIS '.$u['nis'] : '' ?>
                        · <?= $u['jumlah_denda'] ?> kasus
                    </div>
                </div>
                <!-- Total & sisa -->
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:13px;font-weight:700;color:var(--accent3)"><?= formatRupiah($u['total']) ?></div>
                    <?php if ($u['sisa_tagihan'] > 0): ?>
                    <div style="font-size:11px;color:var(--text-muted)">sisa <?= formatRupiah($u['sisa_tagihan']) ?></div>
                    <?php else: ?>
                    <div style="font-size:11px;color:var(--accent)">lunas semua</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- DAFTAR TAGIHAN AKTIF (hanya lihat, tanpa tombol aksi) -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <h3 style="font-size:16px;color:var(--accent3)">
        <i class="fas fa-exclamation-circle"></i> Tagihan Belum Lunas
        <?php if ($jumlahDendaBelum > 0): ?>
        <span style="font-size:12px;background:rgba(255,101,132,0.15);color:var(--accent3);
                     padding:2px 8px;border-radius:20px;margin-left:8px"><?= $jumlahDendaBelum ?></span>
        <?php endif; ?>
    </h3>
    <div style="font-size:12px;color:var(--text-muted);background:rgba(255,101,132,0.06);
                border:1px solid rgba(255,101,132,0.15);border-radius:8px;padding:6px 12px">
        <i class="fas fa-info-circle" style="margin-right:5px"></i>
        Konfirmasi lunas → wewenang <strong>Petugas</strong>
    </div>
</div>

<?php if (empty($dendaBelumLunas)): ?>
<div class="card" style="text-align:center;padding:40px;color:var(--text-muted);margin-bottom:32px">
    <i class="fas fa-check-circle" style="font-size:36px;color:var(--accent);display:block;margin-bottom:12px"></i>
    Tidak ada tagihan denda yang belum lunas
</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden;margin-bottom:32px">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Alat</th>
                    <th>Jenis</th>
                    <th>Rincian</th>
                    <th>Total Denda</th>
                    <th>Tanggal</th>
                    <th>Status User</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dendaBelumLunas as $d): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;font-size:13px"><?= sanitize($d['nama_user']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">
                            <?= $d['nis']   ? 'NIS: '.$d['nis'].' ' : '' ?>
                            <?= $d['kelas'] ? '| '.$d['kelas'] : '' ?>
                        </div>
                    </td>
                    <td style="font-size:13px"><?= sanitize($d['nama_alat']) ?></td>
                    <td>
                        <?php if ($d['jenis'] === 'keterlambatan'): ?>
                            <span class="badge badge-warning">Terlambat</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><?= ucfirst(str_replace('_',' ',$d['kondisi_kerusakan']??'Kerusakan')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-secondary)">
                        <?php if ($d['jenis'] === 'keterlambatan'): ?>
                            <?= $d['hari_terlambat'] ?> hari × <?= formatRupiah($d['tarif_per_hari']) ?>
                            <?php if ($d['jumlah_unit'] > 1): ?> × <?= $d['jumlah_unit'] ?> unit<?php endif; ?>
                        <?php else: ?>
                            <?= $d['persentase'] ?>% × <?= formatRupiah($d['harga_alat_snapshot']) ?>
                            <?php if ($d['jumlah_unit'] > 1): ?> × <?= $d['jumlah_unit'] ?> unit<?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><strong style="color:var(--accent3)"><?= formatRupiah($d['total_denda']) ?></strong></td>
                    <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($d['created_at']) ?></td>
                    <td>
                        <?php if ($d['is_blocked']): ?>
                            <span class="badge badge-danger"><i class="fas fa-ban" style="margin-right:3px"></i>Diblokir</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Aktif</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- RIWAYAT DENDA LUNAS -->
<h3 style="margin-bottom:16px;font-size:16px;color:var(--accent)">
    <i class="fas fa-check-circle"></i> Riwayat Pelunasan
    <span style="font-size:12px;color:var(--text-muted);font-weight:400;margin-left:6px">(50 terakhir)</span>
</h3>
<div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Alat</th>
                    <th>Jenis</th>
                    <th>Total</th>
                    <th>Dikonfirmasi oleh</th>
                    <th>Lunas Pada</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($dendaLunas)): ?>
                <tr><td colspan="6" class="table-empty">Belum ada riwayat pelunasan</td></tr>
                <?php else: ?>
                <?php foreach ($dendaLunas as $d): ?>
                <tr>
                    <td>
                        <div style="font-weight:500"><?= sanitize($d['nama_user']) ?></div>
                        <?php if ($d['kelas'] || $d['nis']): ?>
                        <div style="font-size:11px;color:var(--text-muted)">
                            <?= $d['nis'] ? 'NIS: '.$d['nis'] : '' ?>
                            <?= $d['kelas'] ? ' | '.$d['kelas'] : '' ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px"><?= sanitize($d['nama_alat']) ?></td>
                    <td>
                        <?= $d['jenis'] === 'keterlambatan'
                            ? '<span class="badge badge-warning">Terlambat</span>'
                            : '<span class="badge badge-danger">Kerusakan</span>' ?>
                    </td>
                    <td style="color:var(--accent);font-weight:600"><?= formatRupiah($d['total_denda']) ?></td>
                    <td style="font-size:12px;color:var(--text-secondary)">
                        <?= $d['nama_petugas'] ? sanitize($d['nama_petugas']) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted)"><?= formatDate($d['dibayar_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'inc/footer.php'; ?>

<script>
function openKonfigurasi() {
    openModal('Konfigurasi Persentase Denda', `
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:20px;
                  padding:12px;background:rgba(108,99,255,0.06);border-radius:8px;
                  border:1px solid rgba(108,99,255,0.15)">
            <i class="fas fa-info-circle" style="color:var(--accent2);margin-right:6px"></i>
            Persentase dihitung dari harga alat. Contoh: 30% dari Rp 500.000 = Rp 150.000
        </p>
        <form id="formKonfig">
            <div class="form-group">
                <label class="form-label">Rusak Ringan (%)</label>
                <input type="number" name="persen_rusak_ringan" class="form-control"
                    min="0" max="100" value="<?= $config['persen_rusak_ringan'] ?? 30 ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Rusak Berat (%)</label>
                <input type="number" name="persen_rusak_berat" class="form-control"
                    min="0" max="100" value="<?= $config['persen_rusak_berat'] ?? 75 ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Hilang (%)</label>
                <input type="number" name="persen_hilang" class="form-control"
                    min="0" max="100" value="<?= $config['persen_hilang'] ?? 100 ?>">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </form>
    `);
    document.getElementById('formKonfig').addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'api/denda.php', 'Konfigurasi berhasil disimpan!');
    });
}
</script>
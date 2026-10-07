<?php
require_once 'config.php';
startSession();
requireRole('admin', 'petugas');

$pageTitle = 'Daftar Alat Rusak & Hilang';
$user      = getCurrentUser();
$db        = getDB();

$tab = $_GET['tab'] ?? 'perlu_perbaikan';

// ── WHERE dasar: hanya unit bermasalah yang sudah dikembalikan ──────────────
$filterWhere = "pu.kondisi_kembali IN ('rusak_ringan','rusak_berat','hilang')
                AND p.status = 'dikembalikan'";

// ── Filter per tab — sekarang menggunakan pu.status_perbaikan (per-unit) ───
if ($tab === 'perlu_perbaikan') {
    $filterWhere .= " AND pu.kondisi_kembali IN ('rusak_ringan','rusak_berat')
                      AND (pu.status_perbaikan = 'perlu_perbaikan' OR pu.status_perbaikan IS NULL)";
} elseif ($tab === 'sedang_diperbaiki') {
    $filterWhere .= " AND pu.status_perbaikan = 'sedang_diperbaiki'";
} elseif ($tab === 'sudah_diperbaiki') {
    $filterWhere .= " AND pu.status_perbaikan = 'sudah_diperbaiki'";
} elseif ($tab === 'hilang') {
    $filterWhere .= " AND pu.kondisi_kembali = 'hilang'";
}
// 'semua' → tidak ada filter tambahan

// ── Query utama ─────────────────────────────────────────────────────────────
// KUNCI: pu.id dipakai untuk semua operasi perbaikan → per-unit bukan per-peminjaman
$query = "
    SELECT
        pu.id                          AS pu_id,          -- ID unit bermasalah (kunci utama aksi)
        pu.peminjaman_id,
        pu.kondisi_kembali             AS kondisi_unit,
        pu.status_perbaikan,                              -- status per-unit (baru)
        pu.catatan_perbaikan,
        pu.tanggal_perbaikan,
        ua.kode_unit,
        ua.id                          AS unit_alat_id,
        p.tanggal_pengembalian_aktual  AS tanggal_kejadian,
        p.catatan_pengembalian         AS catatan,
        p.jumlah                       AS jumlah_unit,
        a.id                           AS alat_id,
        a.nama_alat,
        k.nama_kategori,
        u.id                           AS user_id,
        u.nama                         AS nama_peminjam,
        u.username,
        u.kelas,
        u.nis,
        pt.nama                        AS nama_petugas,
        pb.nama                        AS nama_perbaiki,
        -- Stok layak dihitung langsung dari unit_alat agar selalu akurat
        (SELECT COUNT(*) FROM unit_alat WHERE alat_id = a.id)              AS stok_total,
        (SELECT COUNT(*) FROM unit_alat WHERE alat_id = a.id AND kondisi = 'baik') AS stok_layak
    FROM peminjaman_unit pu
    JOIN unit_alat ua   ON ua.id = pu.unit_id
    JOIN peminjaman p   ON p.id  = pu.peminjaman_id
    JOIN alat a         ON p.alat_id = a.id
    JOIN users u        ON p.user_id = u.id
    LEFT JOIN users pt  ON p.diperiksa_oleh  = pt.id
    LEFT JOIN users pb  ON pu.diperbaiki_oleh = pb.id
    LEFT JOIN kategori_alat k ON a.kategori_id = k.id
    WHERE $filterWhere
    ORDER BY p.tanggal_pengembalian_aktual DESC, a.nama_alat ASC, ua.kode_unit ASC
";

$daftarRusak = $db->query($query)->fetchAll();

// ── Counter tab (per-unit) ───────────────────────────────────────────────────
$cPerlu   = (int)$db->query("
    SELECT COUNT(*) FROM peminjaman_unit pu
    JOIN peminjaman p ON p.id = pu.peminjaman_id
    WHERE pu.kondisi_kembali IN ('rusak_ringan','rusak_berat')
      AND p.status = 'dikembalikan'
      AND (pu.status_perbaikan = 'perlu_perbaikan' OR pu.status_perbaikan IS NULL)
")->fetchColumn();

$cSedang  = (int)$db->query("
    SELECT COUNT(*) FROM peminjaman_unit pu
    JOIN peminjaman p ON p.id = pu.peminjaman_id
    WHERE p.status = 'dikembalikan'
      AND pu.kondisi_kembali IN ('rusak_ringan','rusak_berat')
      AND pu.status_perbaikan = 'sedang_diperbaiki'
")->fetchColumn();

$cSelesai = (int)$db->query("
    SELECT COUNT(*) FROM peminjaman_unit pu
    JOIN peminjaman p ON p.id = pu.peminjaman_id
    WHERE p.status = 'dikembalikan'
      AND pu.kondisi_kembali IN ('rusak_ringan','rusak_berat')
      AND pu.status_perbaikan = 'sudah_diperbaiki'
")->fetchColumn();

$cHilang  = (int)$db->query("
    SELECT COUNT(*) FROM peminjaman_unit pu
    JOIN peminjaman p ON p.id = pu.peminjaman_id
    WHERE pu.kondisi_kembali = 'hilang'
      AND p.status = 'dikembalikan'
")->fetchColumn();

$cSemua   = (int)$db->query("
    SELECT COUNT(*) FROM peminjaman_unit pu
    JOIN peminjaman p ON p.id = pu.peminjaman_id
    WHERE pu.kondisi_kembali IN ('rusak_ringan','rusak_berat','hilang')
      AND p.status = 'dikembalikan'
")->fetchColumn();

include 'inc/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Daftar Alat <span>Rusak & Hilang</span></h1>
        <p class="page-subtitle">Riwayat alat bermasalah — kelola proses perbaikan per unit di sini</p>
    </div>
    <a href="kelola-alat.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<!-- STATISTIK -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:28px">
    <div class="card" style="padding:18px;text-align:center">
        <div style="font-size:28px;font-weight:700;color:var(--text-primary)"><?= $cSemua ?></div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Total Unit Bermasalah</div>
    </div>
    <div class="card" style="padding:18px;text-align:center;border:1px solid rgba(220,53,69,0.25)">
        <div style="font-size:28px;font-weight:700;color:#dc3545"><?= $cPerlu ?></div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Perlu Perbaikan</div>
    </div>
    <div class="card" style="padding:18px;text-align:center;border:1px solid rgba(255,193,7,0.25)">
        <div style="font-size:28px;font-weight:700;color:#ffc107"><?= $cSedang ?></div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Sedang Diperbaiki</div>
    </div>
    <div class="card" style="padding:18px;text-align:center;border:1px solid rgba(0,212,170,0.25)">
        <div style="font-size:28px;font-weight:700;color:var(--accent)"><?= $cSelesai ?></div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Sudah Diperbaiki</div>
    </div>
    <div class="card" style="padding:18px;text-align:center;border:1px solid rgba(255,193,7,0.2)">
        <div style="font-size:28px;font-weight:700;color:#ffc107"><?= $cHilang ?></div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Hilang</div>
    </div>
</div>

<!-- INFO BANNER -->
<div style="background:rgba(255,193,7,0.06);border:1px solid rgba(255,193,7,0.18);border-radius:12px;
            padding:14px 18px;margin-bottom:24px;display:flex;align-items:flex-start;gap:12px">
    <i class="fas fa-info-circle" style="color:#ffc107;font-size:17px;margin-top:2px;flex-shrink:0"></i>
    <div style="font-size:13px;color:var(--text-secondary);line-height:1.7">
        Setiap unit dikelola <strong>secara terpisah</strong> — mengkonfirmasi perbaikan 1 unit
        <strong>tidak mempengaruhi</strong> unit lain dari transaksi yang sama.
        Setelah dikonfirmasi selesai, unit kembali ke stok layak katalog.
    </div>
</div>

<!-- TAB FILTER -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
    <?php
    $tabs = [
        'perlu_perbaikan'   => ['label' => '⚠ Perlu Perbaikan',   'count' => $cPerlu,   'color' => '#dc3545'],
        'sedang_diperbaiki' => ['label' => '🔧 Sedang Diperbaiki', 'count' => $cSedang,  'color' => '#ffc107'],
        'sudah_diperbaiki'  => ['label' => '✓ Sudah Diperbaiki',   'count' => $cSelesai, 'color' => 'var(--accent)'],
        'hilang'            => ['label' => '☠ Hilang',             'count' => $cHilang,  'color' => '#ffc107'],
        'semua'             => ['label' => 'Semua',                'count' => $cSemua,   'color' => 'var(--text-muted)'],
    ];
    foreach ($tabs as $key => $info):
        $active = $tab === $key;
    ?>
    <a href="alat-rusak.php?tab=<?= $key ?>"
       class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-secondary' ?>"
       style="<?= !$active ? "color:{$info['color']}" : '' ?>">
        <?= $info['label'] ?>
        <span style="opacity:0.65;font-size:10.5px;margin-left:3px">(<?= $info['count'] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" class="form-control" placeholder="Cari nama alat atau peminjam...">
    </div>
    <span style="font-size:12px;color:var(--text-muted);margin-left:auto"><?= count($daftarRusak) ?> unit</span>
</div>

<!-- TABEL -->
<div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama Alat</th>
                    <th>Kondisi</th>
                    <th>Kode Unit</th>
                    <th>Dikembalikan Oleh</th>
                    <th>Tgl Kejadian</th>
                    <th>Status Perbaikan</th>
                    <th>Stok Layak</th>
                    <th>Catatan</th>
                    <?php if (in_array($tab, ['perlu_perbaikan','sedang_diperbaiki','semua'])): ?>
                    <th style="width:160px">Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($daftarRusak)): ?>
                <tr>
                    <td colspan="10" class="table-empty">
                        <?php if ($tab === 'perlu_perbaikan'): ?>
                            <i class="fas fa-check-circle" style="font-size:36px;color:var(--accent);display:block;margin-bottom:12px"></i>
                            Tidak ada unit yang perlu diperbaiki
                        <?php elseif ($tab === 'sudah_diperbaiki'): ?>
                            <i class="fas fa-tools" style="font-size:36px;opacity:.3;display:block;margin-bottom:12px"></i>
                            Belum ada unit yang selesai diperbaiki
                        <?php else: ?>
                            <i class="fas fa-inbox" style="font-size:36px;opacity:.3;display:block;margin-bottom:12px"></i>
                            Tidak ada data
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($daftarRusak as $i => $row):
                    $borderColor = '';
                    if ($row['kondisi_unit'] === 'rusak_ringan')    $borderColor = '#ff6584';
                    elseif ($row['kondisi_unit'] === 'rusak_berat') $borderColor = '#dc3545';
                    elseif ($row['kondisi_unit'] === 'hilang')      $borderColor = '#ffc107';

                    // Gunakan status per-unit
                    $statusPerbaikan = $row['status_perbaikan'] ?? 'perlu_perbaikan';
                    $sudahDiperbaiki = $statusPerbaikan === 'sudah_diperbaiki';
                ?>
                <tr class="filterable-row"
                    style="border-left:3px solid <?= $borderColor ?>;<?= $sudahDiperbaiki ? 'opacity:0.65' : '' ?>">

                    <!-- # -->
                    <td style="color:var(--text-muted);font-size:12px"><?= $i + 1 ?></td>

                    <!-- Nama Alat -->
                    <td>
                        <div style="font-weight:600;color:var(--text-primary)"><?= sanitize($row['nama_alat']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= sanitize($row['nama_kategori'] ?? '-') ?></div>
                    </td>

                    <!-- Kondisi -->
                    <td>
                        <?php if ($row['kondisi_unit'] === 'rusak_ringan'): ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:600;color:#ff6584">
                                <i class="fas fa-exclamation-triangle"></i> Rusak Ringan
                            </span>
                        <?php elseif ($row['kondisi_unit'] === 'rusak_berat'): ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:#dc3545">
                                <i class="fas fa-times-circle"></i> Rusak Berat
                            </span>
                        <?php elseif ($row['kondisi_unit'] === 'hilang'): ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;color:#ffc107">
                                <i class="fas fa-question-circle"></i> Hilang
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Kode Unit (identitas spesifik unit) -->
                    <td style="text-align:center">
                        <span style="font-family:monospace;background:rgba(255,255,255,0.04);
                                     padding:6px 10px;border-radius:6px;display:inline-block;
                                     color:var(--accent2);font-weight:700;font-size:13px">
                            <?= htmlspecialchars($row['kode_unit']) ?>
                        </span>
                    </td>

                    <!-- Peminjam -->
                    <td>
                        <div style="font-weight:500;font-size:13px"><?= sanitize($row['nama_peminjam']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted)">
                            <?= $row['nis']   ? 'NIS: '.$row['nis']   : '' ?>
                            <?= $row['kelas'] ? ' | '.$row['kelas']   : '' ?>
                        </div>
                    </td>

                    <!-- Tgl Kejadian -->
                    <td style="font-size:12.5px"><?= formatDate($row['tanggal_kejadian']) ?></td>

                    <!-- Status Perbaikan (per-unit) -->
                    <td>
                        <?php if ($row['kondisi_unit'] === 'hilang'): ?>
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        <?php else: ?>
                            <?= getStatusBadge($statusPerbaikan) ?>
                            <?php if ($row['tanggal_perbaikan']): ?>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                                    Selesai: <?= formatDate($row['tanggal_perbaikan']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($row['nama_perbaiki']): ?>
                                <div style="font-size:11px;color:var(--text-muted)">
                                    Oleh: <?= sanitize($row['nama_perbaiki']) ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <!-- Stok Layak (live dari unit_alat) -->
                    <td>
                        <?php
                            $layak = (int)$row['stok_layak'];
                            $total = (int)$row['stok_total'];
                            $pct   = $total > 0 ? round(($layak / $total) * 100) : 0;
                            $warna = $pct >= 70 ? 'var(--accent)' : ($pct >= 30 ? '#ffc107' : '#dc3545');
                        ?>
                        <span style="font-weight:600;color:<?= $warna ?>"><?= $layak ?>/<?= $total ?></span>
                        <span style="font-size:11px;color:var(--text-muted)"> (<?= $pct ?>%)</span>
                    </td>

                    <!-- Catatan -->
                    <td style="max-width:180px">
                        <span style="font-size:12px;color:var(--text-secondary);
                                     display:-webkit-box;-webkit-line-clamp:2;
                                     -webkit-box-orient:vertical;overflow:hidden"
                              title="<?= sanitize($row['catatan'] ?? '') ?>">
                            <?= $row['catatan'] ? sanitize($row['catatan']) : '<span style="color:var(--text-muted)">—</span>' ?>
                        </span>
                        <?php if ($row['catatan_perbaikan']): ?>
                            <div style="margin-top:4px;padding:4px 6px;background:rgba(0,212,170,0.07);
                                        border-radius:6px;font-size:11px;color:var(--accent)">
                                📝 <?= sanitize($row['catatan_perbaikan']) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- Aksi — menggunakan pu_id bukan peminjaman_id -->
                    <?php if (in_array($tab, ['perlu_perbaikan','sedang_diperbaiki','semua'])): ?>
                    <td>
                        <?php if ($row['kondisi_unit'] !== 'hilang' && !$sudahDiperbaiki): ?>
                        <div style="display:flex;flex-direction:column;gap:6px">
                            <?php if ($statusPerbaikan === 'perlu_perbaikan'): ?>
                            <!-- Tombol Mulai Perbaiki — pakai pu_id -->
                            <button class="btn btn-sm btn-warning"
                                onclick="mulaiPerbaikan(
                                    <?= (int)$row['pu_id'] ?>,
                                    '<?= addslashes(sanitize($row['nama_alat'])) ?>',
                                    '<?= htmlspecialchars($row['kode_unit']) ?>'
                                )">
                                <i class="fas fa-tools"></i> Mulai Perbaiki
                            </button>
                            <?php endif; ?>
                            <?php if (in_array($statusPerbaikan, ['perlu_perbaikan','sedang_diperbaiki'])): ?>
                            <!-- Tombol Konfirmasi Selesai — pakai pu_id -->
                            <button class="btn btn-sm btn-primary"
                                onclick="konfirmasiSelesai(
                                    <?= (int)$row['pu_id'] ?>,
                                    '<?= addslashes(sanitize($row['nama_alat'])) ?>',
                                    '<?= htmlspecialchars($row['kode_unit']) ?>'
                                )">
                                <i class="fas fa-check"></i> Konfirmasi Selesai
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($sudahDiperbaiki): ?>
                            <span style="font-size:12px;color:var(--accent)">
                                <i class="fas fa-check-circle"></i> Selesai
                            </span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- KETERANGAN -->
<div style="margin-top:18px;display:flex;gap:20px;flex-wrap:wrap;align-items:center">
    <span style="font-size:12px;color:var(--text-muted)">Keterangan warna kondisi:</span>
    <span style="display:flex;align-items:center;gap:5px;font-size:12px">
        <span style="width:12px;height:12px;border-radius:3px;background:#ff6584;display:inline-block"></span>
        <span style="color:#ff6584">Rusak Ringan</span>
    </span>
    <span style="display:flex;align-items:center;gap:5px;font-size:12px">
        <span style="width:12px;height:12px;border-radius:3px;background:#dc3545;display:inline-block"></span>
        <span style="color:#dc3545;font-weight:700">Rusak Berat</span>
    </span>
    <span style="display:flex;align-items:center;gap:5px;font-size:12px">
        <span style="width:12px;height:12px;border-radius:3px;background:#ffc107;display:inline-block"></span>
        <span style="color:#ffc107;font-weight:700">Hilang</span>
    </span>
</div>

<?php include 'inc/footer.php'; ?>

<script>
// ============================================================
// PENTING: Semua fungsi kini menggunakan puId (peminjaman_unit.id)
// bukan peminjaman_id — agar setiap unit dikonfirmasi terpisah
// ============================================================

/**
 * Mulai perbaikan untuk 1 unit spesifik
 * @param {number} puId       — peminjaman_unit.id
 * @param {string} namaAlat
 * @param {string} kodeUnit   — misal "ACC-001"
 */
function mulaiPerbaikan(puId, namaAlat, kodeUnit) {
    openModal(`Mulai Perbaikan — ${namaAlat}`, `
        <div style="margin-bottom:16px;padding:12px 14px;
                    background:rgba(255,193,7,0.07);border:1px solid rgba(255,193,7,0.2);
                    border-radius:10px;display:flex;align-items:center;gap:12px">
            <i class="fas fa-tools" style="color:#ffc107;font-size:20px;flex-shrink:0"></i>
            <div>
                <div style="font-weight:600;font-size:14px">${namaAlat}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                    Kode Unit: <strong style="font-family:monospace;color:var(--accent2)">${kodeUnit}</strong>
                </div>
            </div>
        </div>
        <p style="color:var(--text-secondary);font-size:13px;margin-bottom:18px;line-height:1.6">
            Konfirmasi bahwa unit <strong style="font-family:monospace;color:var(--accent2)">${kodeUnit}</strong>
            sedang dalam proses perbaikan.<br>
            <span style="color:var(--text-muted);font-size:12px">Unit lain dari transaksi yang sama tidak terpengaruh.</span>
        </p>
        <div class="form-group">
            <label class="form-label">Catatan Perbaikan</label>
            <textarea id="catatanMulai" class="form-control" rows="3"
                placeholder="Contoh: Dibawa ke teknisi, estimasi 3 hari..."></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
            <button type="button" class="btn btn-warning" id="btnMulai"
                    onclick="submitPerbaikanUnit(${puId}, 'sedang_diperbaiki', 'btnMulai')">
                <i class="fas fa-tools"></i> Konfirmasi Mulai Perbaiki
            </button>
        </div>
    `);
}

/**
 * Konfirmasi selesai untuk 1 unit spesifik
 * @param {number} puId       — peminjaman_unit.id
 * @param {string} namaAlat
 * @param {string} kodeUnit
 */
function konfirmasiSelesai(puId, namaAlat, kodeUnit) {
    openModal(`Konfirmasi Selesai — ${namaAlat}`, `
        <div style="margin-bottom:16px;padding:12px 14px;
                    background:rgba(0,212,170,0.07);border:1px solid rgba(0,212,170,0.2);
                    border-radius:10px;display:flex;align-items:center;gap:12px">
            <i class="fas fa-check-circle" style="color:var(--accent);font-size:20px;flex-shrink:0"></i>
            <div>
                <div style="font-weight:600;font-size:14px">${namaAlat}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                    Kode Unit: <strong style="font-family:monospace;color:var(--accent2)">${kodeUnit}</strong>
                </div>
            </div>
        </div>
        <div style="padding:11px 14px;background:rgba(108,99,255,0.06);
                    border-left:3px solid rgba(108,99,255,0.3);border-radius:0 9px 9px 0;
                    font-size:13px;color:var(--text-secondary);margin-bottom:18px;line-height:1.7">
            <i class="fas fa-info-circle" style="color:var(--accent2);margin-right:6px"></i>
            Hanya unit <strong style="font-family:monospace;color:var(--accent2)">${kodeUnit}</strong>
            yang akan dikembalikan ke stok layak.<br>
            <span style="color:var(--text-muted);font-size:12px">Unit lain dari transaksi yang sama tetap di status masing-masing.</span>
        </div>
        <div class="form-group">
            <label class="form-label">Catatan Hasil Perbaikan <span class="req">*</span></label>
            <textarea id="catatanSelesai" class="form-control" rows="3"
                placeholder="Contoh: Komponen X diganti, sudah berfungsi normal..." required></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
            <button type="button" class="btn btn-primary" id="btnSelesai"
                    onclick="submitPerbaikanUnit(${puId}, 'sudah_diperbaiki', 'btnSelesai')">
                <i class="fas fa-check-circle"></i> Konfirmasi Selesai & Kembalikan ke Katalog
            </button>
        </div>
    `);
}

/**
 * Submit perubahan status perbaikan untuk 1 unit (pu_id)
 * Memanggil api/alat.php action=update_perbaikan_unit
 */
function submitPerbaikanUnit(puId, statusBaru, btnId) {
    const btn = document.getElementById(btnId);
    const catatanEl = document.getElementById(
        statusBaru === 'sudah_diperbaiki' ? 'catatanSelesai' : 'catatanMulai'
    );

    // Validasi catatan wajib untuk konfirmasi selesai
    if (statusBaru === 'sudah_diperbaiki' && (!catatanEl || !catatanEl.value.trim())) {
        showFlash('Catatan hasil perbaikan wajib diisi', 'kesalahan');
        catatanEl && catatanEl.focus();
        return;
    }

    if (btn) {
        btn.disabled  = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
    }

    const fd = new FormData();
    fd.append('action',            'update_perbaikan_unit');  // action baru per-unit
    fd.append('pu_id',             puId);                     // peminjaman_unit.id
    fd.append('status_perbaikan',  statusBaru);
    fd.append('catatan_perbaikan', catatanEl?.value?.trim() || '');

    fetch('api/alat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                closeModal();
                showFlash(d.message || 'Status perbaikan diperbarui!', 'sukses');
                setTimeout(() => location.reload(), 1200);
            } else {
                showFlash(d.message || 'Gagal memperbarui status', 'kesalahan');
                if (btn) {
                    btn.disabled  = false;
                    btn.innerHTML = statusBaru === 'sudah_diperbaiki'
                        ? '<i class="fas fa-check-circle"></i> Konfirmasi Selesai & Kembalikan ke Katalog'
                        : '<i class="fas fa-tools"></i> Konfirmasi Mulai Perbaiki';
                }
            }
        })
        .catch(err => {
            showFlash('Koneksi gagal: ' + err.message, 'kesalahan');
            if (btn) {
                btn.disabled  = false;
                btn.innerHTML = statusBaru === 'sudah_diperbaiki'
                    ? '<i class="fas fa-check-circle"></i> Konfirmasi Selesai & Kembalikan ke Katalog'
                    : '<i class="fas fa-tools"></i> Konfirmasi Mulai Perbaiki';
            }
        });
}
</script>
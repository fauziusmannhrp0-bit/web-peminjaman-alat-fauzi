<?php
require_once 'config.php';
startSession();
requireLogin();

$pageTitle = 'Profil';
$user = getCurrentUser();
$db = getDB();

// Handle profile update (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profil') {
    $nama = sanitize($_POST['nama'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $telepon = sanitize($_POST['telepon'] ?? '');
    $passwordLama = $_POST['password_lama'] ?? '';
    $passwordBaru = $_POST['password_baru'] ?? '';
    $passwordKonfirmasi = $_POST['password_konfirmasi'] ?? '';

    $errors = [];
    if (empty($nama)) $errors[] = 'Nama tidak boleh kosong';
    if (empty($email)) $errors[] = 'Email tidak boleh kosong';

    // Check unique email
    if (!empty($email)) {
        $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $user['id']]);
        if ($check->fetch()) $errors[] = 'Email sudah digunakan';
    }

    // Password change
    if (!empty($passwordBaru)) {
        if (empty($passwordLama)) {
            $errors[] = 'Password lama wajib diisi untuk mengubah password';
        } elseif (!verifyPassword($passwordLama, $user['password'])) {
            $errors[] = 'Password lama tidak benar';
        } elseif (strlen($passwordBaru) < 6) {
            $errors[] = 'Password baru minimal 6 karakter';
        } elseif ($passwordBaru !== $passwordKonfirmasi) {
            $errors[] = 'Konfirmasi password tidak cocok';
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash'] = ['type' => 'kesalahan', 'pesan' => implode('. ', $errors)];
    } else {
        $db->prepare("UPDATE users SET nama=?, email=?, telepon=? WHERE id=?")
           ->execute([$nama, $email, $telepon, $user['id']]);

        if (!empty($passwordBaru) && !empty($passwordLama) && verifyPassword($passwordLama, $user['password'])) {
            $db->prepare("UPDATE users SET password=? WHERE id=?")
               ->execute([hashPassword($passwordBaru), $user['id']]);
        }

        logAktivitas($user['id'], 'Perbarui profil');
        $_SESSION['flash'] = ['type' => 'sukses', 'pesan' => 'Profil berhasil diperbarui!'];
    }
    header("Location: profil.php");
    exit;
}

// Refresh user data
$user = getCurrentUser();

include 'inc/header.php';
?>

<!-- PAGE HEADER -->
<div class="page-header">
    <div>
        <h1 class="page-title">Profil <span>Saya</span></h1>
        <p class="page-subtitle">Kelola informasi akun Anda</p>
    </div>
</div>

<div class="grid-2">
    <!-- PROFILE CARD (Left) -->
    <div class="card" style="text-align:center;padding:36px 24px">
        <!-- Avatar -->
        <div style="width:90px;height:90px;border-radius:24px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:800;color:#fff;margin:0 auto 18px;box-shadow:0 6px 24px rgba(0,212,170,0.3)">
            <?= strtoupper(substr($user['nama'], 0, 2)) ?>
        </div>
        <h2 style="font-family:'Syne',sans-serif;font-size:20px;font-weight:700;color:var(--text-primary);margin-bottom:4px"><?= sanitize($user['nama']) ?></h2>
        <span class="badge badge-<?= $user['nama_role'] === 'admin' ? 'success' : ($user['nama_role'] === 'petugas' ? 'info' : 'warning') ?>" style="font-size:12px;padding:4px 14px"><?= ucfirst($user['nama_role']) ?></span>

        <!-- Stats -->
        <div style="display:flex;gap:24px;justify-content:center;margin:28px 0;padding:20px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)">
            <?php
            $myPinjaman = $db->prepare("SELECT COUNT(*) as cnt FROM peminjaman WHERE user_id = ?");
            $myPinjaman->execute([$user['id']]);
            $totalMy = $myPinjaman->fetch()['cnt'];

            $myDipinjam = $db->prepare("SELECT COUNT(*) as cnt FROM peminjaman WHERE user_id = ? AND status = 'dipinjam'");
            $myDipinjam->execute([$user['id']]);
            $activeMy = $myDipinjam->fetch()['cnt'];

            $myDikembalikan = $db->prepare("SELECT COUNT(*) as cnt FROM peminjaman WHERE user_id = ? AND status = 'dikembalikan'");
            $myDikembalikan->execute([$user['id']]);
            $returnedMy = $myDikembalikan->fetch()['cnt'];
            ?>
            <div>
                <div style="font-size:22px;font-weight:800;color:var(--accent);font-family:'Syne',sans-serif"><?= $totalMy ?></div>
                <div style="font-size:11px;color:var(--text-muted)">Total</div>
            </div>
            <div>
                <div style="font-size:22px;font-weight:800;color:#4da6ff;font-family:'Syne',sans-serif"><?= $activeMy ?></div>
                <div style="font-size:11px;color:var(--text-muted)">Aktif</div>
            </div>
            <div>
                <div style="font-size:22px;font-weight:800;color:var(--accent2);font-family:'Syne',sans-serif"><?= $returnedMy ?></div>
                <div style="font-size:11px;color:var(--text-muted)">Dikembalikan</div>
            </div>
        </div>

        <!-- Info Detail -->
        <div style="text-align:left">
            <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
                <i class="fas fa-envelope" style="color:var(--accent);width:16px;text-align:center"></i>
                <span style="font-size:12.5px;color:var(--text-muted)"><?= sanitize($user['email']) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
                <i class="fas fa-phone" style="color:var(--accent);width:16px;text-align:center"></i>
                <span style="font-size:12.5px;color:var(--text-muted)"><?= sanitize($user['telepon'] ?? 'Belum diisi') ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 0">
                <i class="fas fa-user" style="color:var(--accent);width:16px;text-align:center"></i>
                <span style="font-size:12.5px;color:var(--text-muted)">@<?= sanitize($user['username']) ?></span>
            </div>
        </div>
    </div>

    <!-- EDIT FORM (Right) -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-pencil-alt"></i> Edit Profil</span>
        </div>
        <form method="POST" action="profil.php">
            <input type="hidden" name="action" value="update_profil">

            <div class="form-group">
                <label class="form-label">Nama Lengkap <span class="req">*</span></label>
                <input type="text" name="nama" class="form-control" value="<?= sanitize($user['nama']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email <span class="req">*</span></label>
                <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Telepon</label>
                <input type="text" name="telepon" class="form-control" value="<?= sanitize($user['telepon'] ?? '') ?>" placeholder="081234567890">
            </div>

            <!-- PASSWORD SECTION -->
            <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:14px"><i class="fas fa-lock" style="color:var(--accent);margin-right:6px"></i>Ubah Password</div>

                <div class="form-group">
                    <label class="form-label">Password Lama</label>
                    <input type="password" name="password_lama" class="form-control" placeholder="Password saat ini">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="password_baru" class="form-control" placeholder="Min. 6 karakter">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Konfirmasi Password</label>
                        <input type="password" name="password_konfirmasi" class="form-control" placeholder="Ulangi password baru">
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:22px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
                <a href="profil.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<?php include 'inc/footer.php'; ?>

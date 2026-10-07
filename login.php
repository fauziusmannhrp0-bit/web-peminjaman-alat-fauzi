<?php
require_once 'config.php';
startSession();

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['flash'] = ['type' => 'kesalahan', 'pesan' => 'Username dan password wajib diisi.'];
        header("Location: login.php"); exit;
    }

    $db = getDB();

    // ── Query TANPA filter status agar bisa cek kondisi blokir ──
    $stmt = $db->prepare("
        SELECT u.*, r.nama_role
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($password, $user['password'])) {
        // User tidak ditemukan atau password salah — pesan generik
        $_SESSION['flash'] = ['type' => 'kesalahan', 'pesan' => 'Username atau password tidak benar.'];
        header("Location: login.php"); exit;

    } elseif ($user['status'] === 'nonaktif') {
        // ── Diblokir MANUAL oleh admin ────────────────────────────
        $_SESSION['flash'] = [
            'type'  => 'kesalahan',
            'pesan' => 'Akun Anda telah diblokir oleh admin. Silakan hubungi admin untuk informasi lebih lanjut.',
        ];
        header("Location: login.php"); exit;

    } 
    

    // ── Login berhasil ────────────────────────────────────────────
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['nama_role'];
    $_SESSION['nama']     = $user['nama'];

    logAktivitas($user['id'], 'Login berhasil');
    $_SESSION['flash'] = ['type' => 'sukses', 'pesan' => "Selamat datang, {$user['nama']}!"];
    header("Location: dashboard.php"); exit;
}

// Redirect if already logged in
if (isLoggedIn()) { header("Location: dashboard.php"); exit; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — ToolLend</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { min-height: 100vh; }
        .login-page { min-height: 100vh; }
        /* Geometric decoration */
        .geo-deco {
            position: fixed; pointer-events: none; z-index: 0;
        }
        .geo-deco.geo-1 {
            top: 10%; left: 8%;
            width: 180px; height: 180px;
            border: 1px solid rgba(0,212,170,0.12);
            border-radius: 50%;
            animation: geoFloat 8s ease-in-out infinite;
        }
        .geo-deco.geo-2 {
            bottom: 15%; right: 6%;
            width: 120px; height: 120px;
            border: 1px solid rgba(108,99,255,0.15);
            transform: rotate(45deg);
            animation: geoFloat 10s ease-in-out infinite reverse;
        }
        .geo-deco.geo-3 {
            top: 60%; left: 15%;
            width: 60px; height: 60px;
            background: rgba(255,101,132,0.06);
            border-radius: 12px;
            transform: rotate(20deg);
            animation: geoFloat 7s ease-in-out infinite 1s;
        }
        @keyframes geoFloat {
            0%,100%{ transform: translateY(0) rotate(0deg); }
            50%{ transform: translateY(-20px) rotate(5deg); }
        }
        .geo-deco.geo-2 { animation-name: geoFloat2; }
        @keyframes geoFloat2 {
            0%,100%{ transform: rotate(45deg) translateY(0); }
            50%{ transform: rotate(50deg) translateY(-15px); }
        }
    </style>
</head>
<body>
<div id="particles"></div>
<div class="geo-deco geo-1"></div>
<div class="geo-deco geo-2"></div>
<div class="geo-deco geo-3"></div>

<!-- FLASH -->
<?php if (isset($_SESSION['flash'])): ?>
<div class="flash-container">
    <div class="flash flash-<?= $_SESSION['flash']['type'] ?>" id="flashMsg">
        <?php
        // Icon berbeda-beda tergantung tipe flash
        $flashIcon = 'exclamation-circle'; // default error
        if ($_SESSION['flash']['type'] === 'sukses') {
            $flashIcon = 'check-circle';
        }
        ?>
        <i class="fas fa-<?= $flashIcon ?>"></i>
        <span><?= sanitize($_SESSION['flash']['pesan']) ?></span>
    </div>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<div class="login-page">
    <div class="login-box">
        <!-- LOGO -->
        <div class="login-logo">
            <div class="brand-icon" style="width:56px;height:56px;margin:0 auto 14px;font-size:24px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#00d4aa,#6c63ff);box-shadow:0 6px 20px rgba(0,212,170,0.35)">
                <i class="fas fa-tools" style="color:#fff"></i>
            </div>
            <h1 style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:#eef0f7">Tool<strong style="color:#00d4aa">Lend</strong></h1>
            <p style="font-size:13px;color:#5a6380;margin-top:5px">Sistem Peminjaman Alat Profesional</p>
        </div>

       

        <!-- FORM -->
        <form method="POST" action="login.php" class="login-form">
            <div class="form-group">
                <label class="form-label"><i class="fas fa-user" style="color:#00d4aa;margin-right:6px"></i>Username</label>
                <input type="text" name="username" class="form-control" placeholder="Masukkan username" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-lock" style="color:#00d4aa;margin-right:6px"></i>Password</label>
                <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:24px">
                <i class="fas fa-sign-in-alt"></i> Masuk
            </button>
        </form>

        <div style="text-align:center;margin-top:24px;font-size:11.5px;color:#5a6380">
            Sistem Peminjaman Alat &copy; <?= date('Y') ?>
        </div>
    </div>
</div>

<script src="js/main.js"></script>
</body>
</html>
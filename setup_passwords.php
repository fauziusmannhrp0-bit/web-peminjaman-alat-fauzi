<?php
/**
 * ============================================
 * SETUP SCRIPT — Jalankan SEKALI setelah import database.sql
 * URL: http://localhost/peminjaman-alat/setup_passwords.php
 * 
 * Script ini akan mengupdate password user demo
 * dengan bcrypt hash yang benar.
 * ============================================
 */

require_once 'config.php';

try {
    $db = getDB();

    $passwords = [
        'admin'   => 'admin123',
        'petugas' => 'petugas123',
        'user'    => 'user123',
    ];

    foreach ($passwords as $username => $password) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ? WHERE username = ?")
           ->execute([$hash, $username]);
    }

    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Setup Berhasil — ToolLend</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Segoe UI', sans-serif;
                background: #0a0e1a;
                color: #eef0f7;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 40px;
            }
            .box {
                background: #111829;
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 18px;
                padding: 48px 40px;
                max-width: 480px;
                width: 100%;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            }
            .icon {
                width: 72px; height: 72px;
                border-radius: 50%;
                background: linear-gradient(135deg, #00d4aa, #6c63ff);
                display: flex; align-items: center; justify-content: center;
                margin: 0 auto 24px;
                font-size: 28px; color: #fff;
            }
            h1 { font-size: 22px; margin-bottom: 8px; color: #00d4aa; }
            p { font-size: 14px; color: #8892b0; line-height: 1.7; margin-bottom: 6px; }
            .creds {
                background: rgba(0,212,170,0.08);
                border: 1px solid rgba(0,212,170,0.2);
                border-radius: 10px;
                padding: 18px; margin: 22px 0; text-align: left;
            }
            .creds .label { font-size: 11px; color: #00d4aa; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; font-weight: 600; }
            .creds .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; }
            .creds .row .user { color: #eef0f7; font-weight: 600; }
            .creds .row .pass { color: #8892b0; font-family: monospace; }
            a.btn {
                display: inline-block;
                background: linear-gradient(135deg, #00d4aa, #00b894);
                color: #fff;
                padding: 12px 32px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                margin-top: 8px;
                box-shadow: 0 4px 16px rgba(0,212,170,0.3);
            }
            a.btn:hover { filter: brightness(1.1); }
            .warning { font-size: 11px; color: #5a6380; margin-top: 16px; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="icon">&#10003;</div>
            <h1>Setup Berhasil!</h1>
            <p>Password untuk semua akun demo telah diperbarui dengan benar.</p>
            
            <div class="creds">
                <div class="label">Akun Demo</div>
                <div class="row"><span class="user">admin</span><span class="pass">admin123</span></div>
                <div class="row"><span class="user">petugas</span><span class="pass">petugas123</span></div>
                <div class="row"><span class="user">user</span><span class="pass">user123</span></div>
            </div>

            <a href="login.php" class="btn">Ke Halaman Login →</a>

            <p class="warning">Anda dapat menghapus file setup_passwords.php setelah ini.</p>
        </div>
    </body>
    </html>
    <?php
} catch (Exception $e) {
    ?>
    <!DOCTYPE html>
    <html><head><style>
        body { background: #0a0e1a; color: #ff6584; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .err { background: #1a0d0d; border: 1px solid rgba(255,101,132,0.3); border-radius: 14px; padding: 36px; max-width: 500px; text-align: center; }
        h2 { margin-bottom: 10px; }
        p { color: #8892b0; font-size: 13px; }
    </style></head><body>
        <div class="err">
            <h2>❌ Setup Gagal</h2>
            <p>Pastikan database sudah di-import dari file database.sql terlebih dahulu.</p>
            <p style="color:#ff6584;margin-top:10px;font-size:12px"><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
    </body></html>
    <?php
}
?>

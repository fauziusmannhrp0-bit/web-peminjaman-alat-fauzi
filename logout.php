<?php
require_once 'config.php';
startSession();

if (isLoggedIn()) {
    logAktivitas($_SESSION['user_id'], 'Logout');
    session_destroy();
}

session_start();
$_SESSION['flash'] = ['type' => 'info', 'pesan' => 'Anda telah berhasil logout.'];
header("Location: login.php");
exit;
?>

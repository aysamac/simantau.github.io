<?php
/* FILE TES SEMENTARA - hapus setelah masalah login selesai */

include "config/session.php";

if (!isset($_SESSION['hitung'])) {
    $_SESSION['hitung'] = 0;
}
$_SESSION['hitung']++;

$cookie_ada   = isset($_COOKIE[session_name()]);
$folder_sesi  = ini_get('session.save_path');
$bisa_tulis   = is_writable($folder_sesi);

$post_masuk   = ($_SERVER['REQUEST_METHOD'] === 'POST');
$tombol_masuk = isset($_POST['login']);
$user_kirim   = $_POST['username'] ?? '';
$pass_kirim   = $_POST['password'] ?? '';

function tampil($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
function ya($b) {
    return $b ? '<b style="color:green">YA</b>' : '<b style="color:red">TIDAK</b>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tes Session</title>
<style>
    body { font-family: Arial, sans-serif; padding: 16px; line-height: 1.6; }
    td { padding: 4px 8px; vertical-align: top; }
    input { width: 100%; padding: 10px; margin: 4px 0; font-size: 16px; box-sizing: border-box; }
    button { padding: 12px 20px; font-size: 16px; }
    .box { border: 1px solid #ccc; padding: 10px; margin-bottom: 16px; border-radius: 8px; }
</style>
</head>
<body>

<h3>1. Tes session / cookie</h3>
<div class="box">
<table>
    <tr><td>Cookie sesi dikirim browser</td><td><?= ya($cookie_ada) ?></td></tr>
    <tr><td>Session ID</td><td><?= tampil(session_id()) ?></td></tr>
    <tr><td>Hitungan kunjungan</td><td><b><?= (int)$_SESSION['hitung'] ?></b></td></tr>
    <tr><td>Folder session bisa ditulis</td><td><?= ya($bisa_tulis) ?></td></tr>
    <tr><td>Alamat yang dibuka</td><td><?= tampil($_SERVER['HTTP_HOST'] ?? '') ?></td></tr>
    <tr><td>IP perangkat ini</td><td><?= tampil($_SERVER['REMOTE_ADDR'] ?? '') ?></td></tr>
</table>
<p><i>Refresh halaman ini 2-3 kali. Hitungan harus naik (1, 2, 3...).</i></p>
</div>

<h3>2. Tes kiriman form</h3>
<div class="box">
<form method="POST">
    <input type="text" name="username" placeholder="Username" autocapitalize="off" autocorrect="off" spellcheck="false">
    <input type="password" name="password" placeholder="Password">
    <button type="submit" name="login">Kirim tes</button>
</form>

<?php if ($post_masuk) { ?>
<table>
    <tr><td>Form diterima server</td><td><?= ya($post_masuk) ?></td></tr>
    <tr><td>Tombol "login" ikut terkirim</td><td><?= ya($tombol_masuk) ?></td></tr>
    <tr><td>Username yang diterima</td><td>[<?= tampil($user_kirim) ?>] (<?= strlen($user_kirim) ?> huruf)</td></tr>
    <tr><td>Panjang password</td><td><?= strlen($pass_kirim) ?> huruf</td></tr>
</table>
<?php } ?>
</div>

</body>
</html>
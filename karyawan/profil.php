<?php

include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    $_SESSION["role"] != "Karyawan"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

$pesan_sukses = "";
$pesan_error  = "";

/* =========================================================
   DATA KARYAWAN
========================================================= */
$query_user = mysqli_query(
    $koneksi,
    "
    SELECT
        users.id_user,
        users.nama,
        users.no_hp,
        users.username,
        users.password,
        users.status,
        divisi.nama_divisi
    FROM users
    LEFT JOIN divisi
        ON users.id_divisi = divisi.id_divisi
    WHERE users.id_user = '$id_user'
    LIMIT 1
    "
);

$user = mysqli_fetch_assoc($query_user);

$nama_karyawan    = $user["nama"] ?? "-";
$no_hp            = $user["no_hp"] ?? "";
$username         = $user["username"] ?? "-";
$password_lama_db = $user["password"] ?? "";
$status_akun      = $user["status"] ?? "-";
$nama_divisi      = $user["nama_divisi"] ?? "-";

/* =========================================================
   NOTIFIKASI
========================================================= */
$query_notif = mysqli_query(
    $koneksi,
    "
    SELECT COUNT(*) AS total
    FROM notifikasi
    WHERE id_user='$id_user'
    AND status_baca='Belum Dibaca'
    "
);

$total_notif = 0;

if ($query_notif) {
    $data_notif  = mysqli_fetch_assoc($query_notif);
    $total_notif = $data_notif["total"] ?? 0;
}

$inisial = strtoupper(substr(trim($nama_karyawan), 0, 1));

/* =========================================================
   SIMPAN PROFIL
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ubah_profil"])) {

    $nama_baru     = trim($_POST["nama"] ?? "");
    $username_baru = trim($_POST["username"] ?? "");
    $no_hp_baru    = trim($_POST["no_hp"] ?? "");

    if ($nama_baru === "" || $username_baru === "" || $no_hp_baru === "") {

        $pesan_error = "Nama, username, dan No. HP wajib diisi.";

    } elseif (strlen($username_baru) < 3) {

        $pesan_error = "Username minimal 3 karakter.";

    } else {

        $nama_aman     = mysqli_real_escape_string($koneksi, $nama_baru);
        $username_aman = mysqli_real_escape_string($koneksi, $username_baru);
        $no_hp_aman    = mysqli_real_escape_string($koneksi, $no_hp_baru);

        $cek_username = mysqli_query(
            $koneksi,
            "SELECT id_user FROM users WHERE username='$username_aman' AND id_user!='$id_user' LIMIT 1"
        );

        if ($cek_username && mysqli_num_rows($cek_username) > 0) {

            $pesan_error = "Username sudah digunakan oleh pengguna lain.";

        } else {

            $update_profil = mysqli_query(
                $koneksi,
                "UPDATE users SET nama='$nama_aman', username='$username_aman', no_hp='$no_hp_aman' WHERE id_user='$id_user'"
            );

            if ($update_profil) {

                $nama_karyawan = $nama_baru;
                $username      = $username_baru;
                $no_hp         = $no_hp_baru;
                $inisial       = strtoupper(substr($nama_karyawan, 0, 1));
                $_SESSION["nama"]     = $nama_baru;
                $_SESSION["username"] = $username_baru;

                $pesan_sukses = "Profil berhasil diperbarui.";

            } else {

                $pesan_error = "Profil gagal diperbarui.";
            }
        }
    }
}

/* =========================================================
   UBAH PASSWORD
========================================================= */
$buka_panel_akun = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ubah_password"])) {

    $buka_panel_akun = true;

    $password_lama       = trim($_POST["password_lama"] ?? "");
    $password_baru       = trim($_POST["password_baru"] ?? "");
    $konfirmasi_password = trim($_POST["konfirmasi_password"] ?? "");

    if ($password_lama === "") {

        $pesan_error = "Password lama wajib diisi.";

    } elseif ($password_lama !== $password_lama_db) {

        $pesan_error = "Password lama tidak sesuai.";

    } elseif ($password_baru === "") {

        $pesan_error = "Password baru wajib diisi.";

    } elseif (strlen($password_baru) < 6) {

        $pesan_error = "Password baru minimal 6 karakter.";

    } elseif ($konfirmasi_password === "") {

        $pesan_error = "Konfirmasi password wajib diisi.";

    } elseif ($password_baru !== $konfirmasi_password) {

        $pesan_error = "Konfirmasi password baru tidak sesuai.";

    } elseif ($password_baru === $password_lama) {

        $pesan_error = "Password baru harus berbeda dari password lama.";

    } else {

        $password_baru_aman = mysqli_real_escape_string($koneksi, $password_baru);

        $update_password = mysqli_query(
            $koneksi,
            "UPDATE users SET password='$password_baru_aman' WHERE id_user='$id_user'"
        );

        if ($update_password) {

            // kolom "Password Lama" otomatis ikut berisi password baru
            $password_lama_db = $password_baru;

            $pesan_sukses = "Password berhasil diubah. Silakan gunakan password baru saat login berikutnya.";

        } else {

            $pesan_error = "Password gagal diubah. Silakan coba lagi.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profil - SIMO</title>
<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Segoe UI", Arial, sans-serif;
}

body {
    background: #fff;
    color: #263247;
    min-height: 100vh;
}

a {
    text-decoration: none;
    color: inherit;
}

.main {
    margin-left: 240px;
    width: calc(100% - 240px);
    min-width: 0;
}

.content {
    padding: 30px 34px 34px;
    background: #fff;
}

.page-title {
    margin-bottom: 22px;
}

.page-title h1 {
    font-size: 27px;
    line-height: 1.2;
    font-weight: 700;
    color: #182338;
    margin-bottom: 7px;
}

.page-title p {
    font-size: 14px;
    line-height: 1.5;
    color: #8290a3;
}

/* ALERT */
.alert {
    padding: 11px 13px;
    border-radius: 8px;
    font-size: 11px;
    margin-bottom: 16px;
    line-height: 1.5;
}

.alert-success {
    background: #e7f7ed;
    color: #25834d;
    border: 1px solid #c8ecd6;
}

.alert-error {
    background: #fdf0f1;
    color: #a51b25;
    border: 1px solid #f2c8cc;
}

#jsError {
    display: none;
}

/* LAYOUT PENGATURAN */
.settings-card {
    width: 100%;
    background: #ffffff;
    border: 1px solid #d9dde2;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
    padding: 26px 28px;
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    min-height: 0;
    overflow: hidden;
}

.settings-nav {
    padding: 4px 24px 0 0;
    border-right: 1px solid #e2e6eb;
    background: transparent;
}

.settings-nav-title {
    padding: 0 12px 11px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .7px;
    text-transform: uppercase;
    color: #9aa5b4;
}

.settings-link {
    width: 100%;
    min-height: 44px;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 9px 12px;
    border: 0;
    border-radius: 9px;
    background: transparent;
    color: #5d6b80;
    font-size: 14px;
    font-weight: 600;
    text-align: left;
    cursor: pointer;
    margin-bottom: 4px;
    transition: background .18s ease, color .18s ease, transform .18s ease;
}

.settings-link:hover {
    background: #fbf4f5;
    color: #a8212b;
    transform: translateY(-1px);
}

.settings-link.active {
    background: #fff1f2;
    color: #a8212b;
}

.settings-link svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.settings-content {
    padding: 4px 0 0 30px;
    min-width: 0;
}

.settings-panel {
    display: none;
}

.settings-panel.active {
    display: block;
}

.panel-header {
    padding-bottom: 17px;
    border-bottom: 1px solid #e4e8ed;
    margin-bottom: 22px;
}

.panel-header h2 {
    font-size: 21px;
    line-height: 1.25;
    font-weight: 700;
    color: #182338;
    margin-bottom: 5px;
}

.panel-header p {
    font-size: 13px;
    line-height: 1.5;
    color: #8290a3;
}

/* FORM PROFIL */
.profile-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 20px;
}

.field {
    min-height: 72px;
}

.field-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #768397;
    margin-bottom: 7px;
}

.field-value {
    min-height: 44px;
    display: flex;
    align-items: center;
    width: 100%;
    padding: 0 12px;
    border: 1px solid #d9dfe7;
    border-radius: 8px;
    background: #fff;
    color: #344054;
    font-size: 13px;
}

.field-input {
    width: 100%;
    height: 44px;
    border: 1px solid #d9dfe7;
    border-radius: 8px;
    background: #fff;
    color: #344054;
    padding: 0 12px;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 13px;
    outline: none;
}

.field-input:focus {
    border-color: #b51f2a;
    box-shadow: 0 0 0 3px rgba(181, 31, 42, .06);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 28px;
    padding: 4px 10px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 700;
    background: #e9f7ed;
    color: #328650;
}

.status-badge.status-inactive {
    background: #f9e8e9;
    color: #a8212b;
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #43a05f;
}

.status-inactive .status-dot {
    background: #a8212b;
}

.profile-form {
    margin-top: 22px;
}

.profile-actions {
    display: flex;
    justify-content: flex-start;
    margin-top: 22px;
    padding-top: 2px;
}

.btn-save-profile {
    height: 42px;
    padding: 0 18px;
    border: none;
    border-radius: 8px;
    background: #ae3037;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.btn-save-profile:hover {
    background: #96272e;
}

.profile-note {
    margin-top: 20px;
    padding-top: 17px;
    border-top: 1px solid #e4e8ed;
    font-size: 12px;
    line-height: 1.5;
    color: #8a96a6;
}

/* FORM AKUN / PASSWORD */
.password-box {
    max-width: 690px;
}

.password-input {
    position: relative;
}

.password-input input {
    width: 100%;
    height: 46px;
    padding: 0 43px 0 13px;
    border: 1px solid #d8dee7;
    border-radius: 8px;
    background: #fff;
    color: #344054;
    font-size: 13px;
    outline: none;
}

.password-input input:focus {
    border-color: #b51f2a;
    box-shadow: 0 0 0 3px rgba(181, 31, 42, .06);
}

.form-group {
    margin-bottom: 17px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 12px;
    font-weight: 600;
    color: #536174;
}

.password-note {
    margin-top: -6px;
    margin-bottom: 18px;
    font-size: 11px;
    color: #8b97a8;
}

.eye-button {
    position: absolute;
    top: 50%;
    right: 11px;
    transform: translateY(-50%);
    width: 25px;
    height: 25px;
    border: 0;
    background: transparent;
    color: #788496;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}

.eye-button svg {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.btn-save {
    height: 44px;
    padding: 0 19px;
    border: 0;
    border-radius: 8px;
    background: #b51f2a;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.btn-save:hover {
    background: #991c25;
}

.footer {
    text-align: center;
    color: #8b96a6;
    font-size: 11px;
    margin-top: 24px;
}

/* RESPONSIVE */
@media (max-width: 950px) {
    .settings-card {
        grid-template-columns: 180px minmax(0, 1fr);
    }
    .settings-content {
        padding-left: 22px;
    }
}

@media (max-width: 800px) {
    .main {
        margin-left: 0;
        width: 100%;
    }
    .content {
        padding: 20px;
    }
    .settings-card {
        grid-template-columns: 1fr;
        padding: 20px;
    }
    .settings-nav {
        border-right: 0;
        border-bottom: 1px solid #e2e6eb;
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 0 0 10px;
        margin-bottom: 18px;
    }
    .settings-nav-title {
        display: none;
    }
    .settings-link {
        width: auto;
        margin-bottom: 0;
    }
    .settings-content {
        padding: 0;
    }
    .profile-info-grid {
        grid-template-columns: 1fr;
    }
}

/* =========================================================
   DARK MODE
========================================================= */
html:has(body.dark-mode),
html:has(body.dark-mode) body {
    background: #0f141b !important;
}

html body.dark-mode {
    background: #0f141b !important;
    color: #e8edf4 !important;
}

html body.dark-mode .main,
html body.dark-mode .content {
    background: #0f141b !important;
    color: #e8edf4 !important;
}

html body.dark-mode .page-title h1,
html body.dark-mode .panel-header h2,
html body.dark-mode .settings-nav-title,
html body.dark-mode .form-group label {
    color: #edf2f7 !important;
}

html body.dark-mode .page-title p,
html body.dark-mode .panel-header p,
html body.dark-mode .profile-note,
html body.dark-mode .password-note,
html body.dark-mode .footer {
    color: #8f9bac !important;
}

html body.dark-mode .field-label {
    color: #8f9bac !important;
}

html body.dark-mode .settings-nav,
html body.dark-mode .panel-header,
html body.dark-mode .profile-note {
    border-color: #2c3542 !important;
}

html body.dark-mode input,
html body.dark-mode select,
html body.dark-mode textarea,
html body.dark-mode .field-input,
html body.dark-mode .password-input input,
html body.dark-mode .field-value {
    background: #202733 !important;
    color: #e8edf4 !important;
    border-color: #303947 !important;
}

html body.dark-mode input::placeholder,
html body.dark-mode textarea::placeholder {
    color: #7f8998 !important;
}

html body.dark-mode .field-input:focus,
html body.dark-mode .password-input input:focus {
    border-color: #b51f2a !important;
}

html body.dark-mode .settings-card {
    background: #151b23 !important;
    border: 1px solid #2c3542 !important;
    box-shadow: none !important;
}

html body.dark-mode .settings-nav,
html body.dark-mode .settings-content {
    background: transparent !important;
    box-shadow: none !important;
}

html body.dark-mode .settings-link {
    background: transparent !important;
    color: #cbd2dc !important;
}

html body.dark-mode .settings-link:hover,
html body.dark-mode .settings-link.active {
    background: #382326 !important;
    color: #f07a80 !important;
}

html body.dark-mode .status-badge {
    background: #183d2a !important;
    color: #72d89a !important;
}

html body.dark-mode .status-badge.status-inactive {
    background: #382326 !important;
    color: #f07a80 !important;
}

html body.dark-mode .status-dot {
    background: #3ba566 !important;
}

html body.dark-mode .status-inactive .status-dot {
    background: #f07a80 !important;
}

html body.dark-mode .btn-save,
html body.dark-mode .btn-save-profile {
    background: #b51f2a !important;
    color: #fff !important;
}

html body.dark-mode .btn-save:hover,
html body.dark-mode .btn-save-profile:hover {
    background: #941923 !important;
}

html body.dark-mode .eye-button {
    color: #aeb8c6 !important;
}

html body.dark-mode .alert-success {
    background: #183d2a !important;
    color: #72d89a !important;
    border-color: #2a5c40 !important;
}

html body.dark-mode .alert-error {
    background: #382326 !important;
    color: #f08a90 !important;
    border-color: #51363b !important;
}

</style>
</head>
<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<main class="main">

    <?php include "../includes/karyawan/topbar.php"; ?>

    <section class="content">

    <div class="page-title"><h1>Profil</h1><p>Kelola informasi akun dan keamanan akun Anda.</p></div>

    <?php if ($pesan_sukses != ""): ?><div class="alert alert-success"><?= htmlspecialchars($pesan_sukses); ?></div><?php endif; ?>

    <?php if ($pesan_error != ""): ?><div class="alert alert-error"><?= htmlspecialchars($pesan_error); ?></div><?php endif; ?>

    <div class="alert alert-error" id="jsError"></div>

    <div class="settings-card">

        <aside class="settings-nav">

            <div class="settings-nav-title">Pengaturan</div>

            <button type="button" class="settings-link <?= $buka_panel_akun ? "" : "active"; ?>" data-target="panel-profil"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c0-3.4 2.8-6 7-6s7 2.6 7 6"></path></svg><span>Profil</span></button>

            <button type="button" class="settings-link <?= $buka_panel_akun ? "active" : ""; ?>" data-target="panel-akun"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg><span>Akun</span></button>

        </aside>

        <section class="settings-content">

            <div class="settings-panel <?= $buka_panel_akun ? "" : "active"; ?>" id="panel-profil">

                <div class="panel-header"><h2>Profil</h2><p>Informasi pengguna yang sedang login pada sistem SIMO, termasuk nomor HP.</p></div>

                <form method="POST" class="profile-form" autocomplete="off">

                    <div class="profile-info-grid">

                        <div class="field"><label class="field-label" for="nama">Nama Lengkap</label><input type="text" id="nama" name="nama" class="field-input" value="<?= htmlspecialchars($nama_karyawan) ?>" required></div>

                        <div class="field"><label class="field-label" for="username">Username</label><input type="text" id="username" name="username" class="field-input" value="<?= htmlspecialchars($username) ?>" minlength="3" required></div>

                        <div class="field"><label class="field-label" for="no_hp">No. HP</label><input type="text" id="no_hp" name="no_hp" class="field-input" value="<?= htmlspecialchars($no_hp) ?>" placeholder="Masukkan No. HP" inputmode="tel" required></div>

                        <div class="field"><span class="field-label">Divisi</span><div class="field-value"><?= htmlspecialchars($nama_divisi) ?></div></div>

                        <div class="field"><span class="field-label">Status Akun</span><div class="field-value"><?php if (trim($status_akun) === "Aktif"): ?><span class="status-badge"><span class="status-dot"></span>Aktif</span><?php else: ?><span class="status-badge status-inactive"><span class="status-dot"></span>Tidak Aktif</span><?php endif; ?></div></div>

                    </div>

                    <input type="hidden" name="ubah_profil" value="1">

                    <div class="profile-actions"><button type="submit" class="btn-save-profile">Simpan Perubahan</button></div>

                </form>

                <div class="profile-note">Nama lengkap, username, dan nomor HP dapat diperbarui sesuai kebutuhan akun Karyawan.</div>

            </div>

            <div class="settings-panel <?= $buka_panel_akun ? "active" : ""; ?>" id="panel-akun">

                <div class="panel-header"><h2>Akun</h2><p>Perbarui password untuk menjaga keamanan akun Anda.</p></div>

                <div class="password-box"><form method="POST" id="formPassword" autocomplete="off">

                    <div class="form-group"><label for="password_lama">Password Lama</label><div class="password-input"><input type="password" id="password_lama" name="password_lama" value="<?= htmlspecialchars($password_lama_db) ?>" autocomplete="new-password" required><button type="button" class="eye-button" onclick="togglePassword('password_lama', this)" aria-label="Tampilkan password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></div></div>

                    <div class="form-group"><label for="password_baru">Password Baru</label><div class="password-input"><input type="text" id="password_baru" data-mask="password_baru_real" placeholder="Masukkan password baru" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" required><input type="hidden" id="password_baru_real" name="password_baru" value=""><button type="button" class="eye-button" onclick="togglePassword('password_baru', this)" aria-label="Tampilkan password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></div></div>

                    <div class="password-note">Password baru minimal 6 karakter.</div>

                    <div class="form-group"><label for="konfirmasi_password">Konfirmasi Password Baru</label><div class="password-input"><input type="text" id="konfirmasi_password" data-mask="konfirmasi_password_real" placeholder="Ulangi password baru" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" required><input type="hidden" id="konfirmasi_password_real" name="konfirmasi_password" value=""><button type="button" class="eye-button" onclick="togglePassword('konfirmasi_password', this)" aria-label="Tampilkan password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></div></div>

                    <button type="submit" name="ubah_password" class="btn-save">Simpan Password</button>

                </form></div>

            </div>

        </section>

    </div>

    <div class="footer">© <?= date("Y"); ?> SIMO METRP. All rights reserved.</div>

    </section>

</main>

<script>

/* TAB PENGATURAN */
document.querySelectorAll(".settings-link").forEach(function (link) {
    link.addEventListener("click", function () {
        var target = this.getAttribute("data-target");

        document.querySelectorAll(".settings-link").forEach(function (x) {
            x.classList.remove("active");
        });
        document.querySelectorAll(".settings-panel").forEach(function (x) {
            x.classList.remove("active");
        });

        this.classList.add("active");

        var panel = document.getElementById(target);
        if (panel) panel.classList.add("active");
    });
});

/*
 * Kolom password baru & konfirmasi memakai input teks biasa yang
 * ditampilkan sebagai titik-titik, supaya Firefox tidak menganggapnya
 * field password (dan tidak memunculkan daftar login tersimpan).
 * Nilai asli disimpan di input hidden dan itulah yang dikirim ke server.
 */
const BULLET = "\u2022";

document.querySelectorAll("input[data-mask]").forEach(function (visible) {

    const hidden = document.getElementById(visible.getAttribute("data-mask"));
    visible.dataset.show = "0";

    visible.addEventListener("input", function () {

        const typed = visible.value;
        const real = hidden.value;
        const maskedOld = BULLET.repeat(real.length);
        const oldView = visible.dataset.show === "1" ? real : maskedOld;

        let start = 0;

        while (
            start < typed.length &&
            start < oldView.length &&
            typed[start] === oldView[start]
        ) {
            start++;
        }

        let endNew = typed.length;
        let endOld = oldView.length;

        while (
            endNew > start &&
            endOld > start &&
            typed[endNew - 1] === oldView[endOld - 1]
        ) {
            endNew--;
            endOld--;
        }

        const inserted = typed.slice(start, endNew);
        hidden.value = real.slice(0, start) + inserted + real.slice(endOld);

        const caret = visible.selectionStart;

        visible.value = visible.dataset.show === "1"
            ? hidden.value
            : BULLET.repeat(hidden.value.length);

        visible.setSelectionRange(caret, caret);

    });

});

/* VALIDASI SEBELUM KIRIM (minimal 6 karakter) */
document.getElementById("formPassword").addEventListener("submit", function (e) {

    const baru = document.getElementById("password_baru_real").value;
    const konfirmasi = document.getElementById("konfirmasi_password_real").value;
    const box = document.getElementById("jsError");
    let msg = "";

    if (baru.length < 6) {
        msg = "Password baru minimal 6 karakter.";
    } else if (baru !== konfirmasi) {
        msg = "Konfirmasi password baru tidak sesuai.";
    }

    if (msg !== "") {
        e.preventDefault();
        box.textContent = msg;
        box.style.display = "block";
        window.scrollTo({ top: 0, behavior: "smooth" });
    } else {
        box.style.display = "none";
    }
});

/* TOGGLE PASSWORD */
function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button ? button.querySelector("svg") : null;

    if (!input || !icon) {
        return;
    }

    const eyeOff = '<path d="M3 3l18 18"></path><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3.1 3.9"></path><path d="M6.1 6.1C3.6 8 2 12 2 12s3.5 7 10 7c1.5 0 2.8-.3 4-.8"></path>';
    const eyeOn = '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path><circle cx="12" cy="12" r="2.5"></circle>';

    // Kolom bertopeng (password baru & konfirmasi)
    if (input.hasAttribute("data-mask")) {

        const hidden = document.getElementById(input.getAttribute("data-mask"));
        const show = input.dataset.show !== "1";

        input.dataset.show = show ? "1" : "0";
        input.value = show ? hidden.value : BULLET.repeat(hidden.value.length);

        button.setAttribute("aria-label", show ? "Sembunyikan password" : "Tampilkan password");
        icon.innerHTML = show ? eyeOff : eyeOn;

        return;
    }

    // Kolom password lama
    if (input.type === "password") {
        input.type = "text";
        button.setAttribute("aria-label", "Sembunyikan password");
        icon.innerHTML = eyeOff;
    } else {
        input.type = "password";
        button.setAttribute("aria-label", "Tampilkan password");
        icon.innerHTML = eyeOn;
    }
}

</script>

</body>
</html>
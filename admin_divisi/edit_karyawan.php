<?php

include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    $_SESSION["role"] != "Admin"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_admin = (int) $_SESSION["id_user"];

$query_admin = mysqli_query(
    $koneksi,
    "SELECT *
     FROM users
     WHERE id_user='$id_admin'
     LIMIT 1"
);

if (
    !$query_admin ||
    mysqli_num_rows($query_admin) == 0
) {
    header("Location: ../index.php");
    exit;
}

$admin = mysqli_fetch_assoc($query_admin);

$id_divisi = (int) $admin["id_divisi"];

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT *
     FROM divisi
     WHERE id_divisi='$id_divisi'
     LIMIT 1"
);

$divisi = mysqli_fetch_assoc($query_divisi);

$nama_divisi = $divisi["nama_divisi"] ?? "-";

$id_karyawan = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id_karyawan <= 0) {
    header("Location: karyawan.php");
    exit;
}

$query_karyawan = mysqli_query(
    $koneksi,
    "SELECT *
     FROM users
     WHERE id_user='$id_karyawan'
     AND role='Karyawan'
     AND id_divisi='$id_divisi'
     LIMIT 1"
);

if (
    !$query_karyawan ||
    mysqli_num_rows($query_karyawan) == 0
) {
    header("Location: karyawan.php");
    exit;
}

$karyawan = mysqli_fetch_assoc($query_karyawan);

$pesan = "";
$tipe_pesan = "";

if (isset($_POST["update_karyawan"])) {

    $nama = mysqli_real_escape_string(
        $koneksi,
        trim($_POST["nama"] ?? "")
    );

    $no_hp = mysqli_real_escape_string(
        $koneksi,
        trim($_POST["no_hp"] ?? "")
    );

    $username = mysqli_real_escape_string(
        $koneksi,
        trim($_POST["username"] ?? "")
    );

    $password = mysqli_real_escape_string(
        $koneksi,
        trim($_POST["password"] ?? "")
    );

    if (
        $nama == "" ||
        $username == ""
    ) {

        $pesan = "Nama dan username wajib diisi.";
        $tipe_pesan = "error";

    } else {

        $cek_username = mysqli_query(
            $koneksi,
            "SELECT id_user
             FROM users
             WHERE username='$username'
             AND id_user!='$id_karyawan'
             LIMIT 1"
        );

        if (
            $cek_username &&
            mysqli_num_rows($cek_username) > 0
        ) {

            $pesan = "Username sudah digunakan.";
            $tipe_pesan = "error";

        } else {

            $query_update = mysqli_query(
                $koneksi,
                "UPDATE users
                 SET
                    nama='$nama',
                    no_hp='$no_hp',
                    username='$username',
                    password='$password'
                 WHERE id_user='$id_karyawan'
                 AND role='Karyawan'
                 AND id_divisi='$id_divisi'
                 LIMIT 1"
            );

            if ($query_update) {

                header(
                    "Location: karyawan.php?pesan=edit"
                );
                exit;

            } else {

                $pesan = "Data karyawan gagal diperbarui.";
                $tipe_pesan = "error";
            }
        }
    }

    $karyawan["nama"] = $_POST["nama"] ?? "";
    $karyawan["no_hp"] = $_POST["no_hp"] ?? "";
    $karyawan["username"] = $_POST["username"] ?? "";
    $karyawan["password"] = $_POST["password"] ?? "";
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Karyawan - SIMO</title>
<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Segoe UI", Arial, sans-serif;
}

body {
    background: #fff;
    min-height: 100vh;
    color: #263247;
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

/* LAYOUT PENGATURAN (card berborder abu seperti profil super admin) */
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

.profile-actions {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-top: 22px;
    padding-top: 2px;
    gap: 10px;
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

.edit-input {
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
    transition: border-color .18s ease, box-shadow .18s ease;
}

.edit-input:focus {
    border-color: #b51f2a;
    box-shadow: 0 0 0 3px rgba(181, 31, 42, .06);
}

.edit-note {
    margin-top: 7px;
    font-size: 11px;
    line-height: 1.45;
    color: #8b97a8;
}

.edit-default {
    margin-top: 7px;
    border: 0;
    background: transparent;
    color: #ef6d74;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.edit-default svg {
    width: 14px;
    height: 14px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.edit-actions {
    display: flex;
    justify-content: flex-start;
    margin-top: 22px;
    padding-top: 17px;
    border-top: 1px solid #e4e8ed;
    gap: 10px;
}

.btn-back {
    height: 42px;
    min-width: 92px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1;
    border: 1px solid #4a535f;
    border-radius: 8px;
    background: #303640;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
}

.btn-back:hover {
    background: #3a424d;
}

.btn-edit-save {
    height: 42px;
    padding: 0 18px;
    border: 0;
    border-radius: 8px;
    background: #b51f2a;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.btn-edit-save:hover {
    background: #96272e;
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
    .edit-actions {
        flex-direction: column;
    }
    .btn-back,
    .btn-edit-save {
        width: 100%;
    }
}

/* =========================================================
   DARK MODE (palet sama dengan halaman karyawan)
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

/* judul & teks */
html body.dark-mode .page-title h1,
html body.dark-mode .panel-header h2,
html body.dark-mode .settings-nav-title,
html body.dark-mode .form-group label {
    color: #edf2f7 !important;
}

html body.dark-mode .page-title p,
html body.dark-mode .panel-header p,
html body.dark-mode .edit-note,
html body.dark-mode .footer {
    color: #8f9bac !important;
}

html body.dark-mode .field-label {
    color: #8f9bac !important;
}

/* garis pemisah */
html body.dark-mode .settings-nav,
html body.dark-mode .panel-header,
html body.dark-mode .edit-actions {
    border-color: #2c3542 !important;
}

/* input */
html body.dark-mode input,
html body.dark-mode select,
html body.dark-mode textarea,
html body.dark-mode .field-input,
html body.dark-mode .edit-input,
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
html body.dark-mode .edit-input:focus,
html body.dark-mode .password-input input:focus {
    border-color: #b51f2a !important;
}

/* card pengaturan (border abu) */
html body.dark-mode .settings-card {
    background: #151b23 !important;
    border: 1px solid #2c3542 !important;
    box-shadow: none !important;
}

/* menu samping pengaturan */
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

/* tombol */
html body.dark-mode .btn-back {
    background: #202733 !important;
    border-color: #303947 !important;
    color: #e8edf4 !important;
}

html body.dark-mode .btn-back:hover {
    background: #2a3342 !important;
}

html body.dark-mode .btn-save-profile,
html body.dark-mode .btn-edit-save {
    background: #b51f2a !important;
    color: #fff !important;
}

html body.dark-mode .btn-save-profile:hover,
html body.dark-mode .btn-edit-save:hover {
    background: #96272e !important;
}

html body.dark-mode .eye-button {
    color: #aeb8c6 !important;
}

/* alert */
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

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="content">

<div class="page-title">
    <h1>Edit Karyawan</h1>
    <p>Perbarui data karyawan pada divisi <?= htmlspecialchars($nama_divisi, ENT_QUOTES, "UTF-8"); ?>.</p>
</div>

<?php if ($pesan != ""): ?>
<div class="alert <?= $tipe_pesan == "success" ? "alert-success" : "alert-error"; ?>"><?= htmlspecialchars($pesan, ENT_QUOTES, "UTF-8"); ?></div>
<?php endif; ?>

<div class="settings-card">

    <aside class="settings-nav">
        <div class="settings-nav-title">Pengaturan</div>

        <button type="button" class="settings-link active" data-target="panel-profil">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c0-3.4 2.8-6 7-6s7 2.6 7 6"></path></svg>
            <span>Profil</span>
        </button>

        <button type="button" class="settings-link" data-target="panel-akun">
            <svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
            <span>Akun</span>
        </button>
    </aside>

    <section class="settings-content">

        <form method="POST" autocomplete="off">

            <!-- PANEL PROFIL -->
            <div class="settings-panel active" id="panel-profil">

                <div class="panel-header">
                    <h2>Profil</h2>
                    <p>Informasi data karyawan yang sedang diperbarui pada sistem SIMO.</p>
                </div>

                <div class="profile-info-grid">

                    <div class="field">
                        <label class="field-label" for="nama">Nama Karyawan</label>
                        <input type="text" id="nama" name="nama" class="field-input" value="<?= htmlspecialchars($karyawan["nama"] ?? "", ENT_QUOTES, "UTF-8"); ?>" required>
                    </div>

                    <div class="field">
                        <label class="field-label" for="no_hp">No. HP</label>
                        <input type="text" id="no_hp" name="no_hp" class="field-input" value="<?= htmlspecialchars($karyawan["no_hp"] ?? "", ENT_QUOTES, "UTF-8"); ?>" placeholder="Masukkan nomor HP">
                    </div>

                    <div class="field">
                        <label class="field-label" for="username">Username</label>
                        <input type="text" id="username" name="username" class="field-input" value="<?= htmlspecialchars($karyawan["username"] ?? "", ENT_QUOTES, "UTF-8"); ?>" minlength="3" required>
                    </div>

                    <div class="field">
                        <span class="field-label">Divisi</span>
                        <div class="field-value readonly"><?= htmlspecialchars($nama_divisi, ENT_QUOTES, "UTF-8"); ?></div>
                        <div class="edit-note">Divisi tidak dapat diubah oleh Admin Divisi.</div>
                    </div>

                </div>

                <div class="profile-actions">
                    <a href="karyawan.php" class="btn-back">Batal</a>
                    <button type="submit" name="update_karyawan" class="btn-save-profile">Simpan Perubahan</button>
                </div>

            </div>

            <!-- PANEL AKUN -->
            <div class="settings-panel" id="panel-akun">

                <div class="panel-header">
                    <h2>Akun</h2>
                    <p>Perbarui password untuk menjaga keamanan akun karyawan.</p>
                </div>

                <div class="password-box">
                    <div class="form-group">
                        <label for="password">Password</label>

                        <div class="password-input">
                            <input type="password" id="password" name="password" value="<?= htmlspecialchars($karyawan["password"] ?? "", ENT_QUOTES, "UTF-8"); ?>" class="edit-input" autocomplete="new-password">
                            <button type="button" class="eye-button" onclick="togglePassword()" aria-label="Tampilkan password">
                                <svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                            </button>
                        </div>

                        <button type="button" class="edit-default" id="passwordDefault">
                            <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path></svg>
                            Gunakan Password Default (123456)
                        </button>

                        <div class="edit-note">Password digunakan untuk login karyawan.</div>
                    </div>
                </div>

                <div class="edit-actions">
                    <a href="karyawan.php" class="btn-back">Batal</a>
                    <button type="submit" name="update_karyawan" class="btn-edit-save">Simpan Perubahan</button>
                </div>

            </div>

        </form>

    </section>

</div>

<div class="footer">© <?= date("Y"); ?> SIMO METRP. All rights reserved.</div>

</div>

</div>

<script>

const settingsLinks = document.querySelectorAll(".settings-link");
const settingsPanels = document.querySelectorAll(".settings-panel");

settingsLinks.forEach(function (link) {
    link.addEventListener("click", function () {
        const target = this.dataset.target;

        settingsLinks.forEach(function (item) {
            item.classList.remove("active");
        });
        settingsPanels.forEach(function (panel) {
            panel.classList.remove("active");
        });

        this.classList.add("active");
        document.getElementById(target)?.classList.add("active");
    });
});

function togglePassword() {
    const input = document.getElementById("password");
    const button = document.querySelector("#panel-akun .eye-button");

    if (!input) return;

    if (input.type === "password") {
        input.type = "text";
        button.setAttribute("aria-label", "Sembunyikan password");
    } else {
        input.type = "password";
        button.setAttribute("aria-label", "Tampilkan password");
    }
}

const passwordDefault = document.getElementById("passwordDefault");

if (passwordDefault) {
    passwordDefault.addEventListener("click", function () {
        const input = document.getElementById("password");
        input.value = "123456";
        input.type = "text";
        input.focus();
    });
}

const savedTheme = localStorage.getItem("simo-theme") || "dark";
document.body.classList.toggle("dark-mode", savedTheme === "dark");

</script>

</body>
</html>
<?php

include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "Super Admin"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

$pesan = "";
$tipe_pesan = "";
$password_lama_isi = "";

/* =====================================================
   SIMPAN PROFIL
===================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["simpan_profil"])) {

    $nama_baru = trim($_POST["nama"] ?? "");
    $username_baru = trim($_POST["username"] ?? "");

    if ($nama_baru === "" || $username_baru === "") {

        $pesan = "Nama lengkap dan username wajib diisi.";
        $tipe_pesan = "error";

    } elseif (strlen($username_baru) < 3) {

        $pesan = "Username minimal 3 karakter.";
        $tipe_pesan = "error";

    } else {

        $stmt_cek = mysqli_prepare(
            $koneksi,
            "SELECT id_user
             FROM users
             WHERE username = ?
             AND id_user != ?
             LIMIT 1"
        );

        if ($stmt_cek) {

            mysqli_stmt_bind_param($stmt_cek, "si", $username_baru, $id_user);
            mysqli_stmt_execute($stmt_cek);
            mysqli_stmt_store_result($stmt_cek);

            if (mysqli_stmt_num_rows($stmt_cek) > 0) {

                $pesan = "Username sudah digunakan oleh pengguna lain.";
                $tipe_pesan = "error";

            } else {

                $stmt_update_profil = mysqli_prepare(
                    $koneksi,
                    "UPDATE users
                     SET nama = ?, username = ?
                     WHERE id_user = ?
                     AND role = 'Super Admin'"
                );

                if ($stmt_update_profil) {

                    mysqli_stmt_bind_param(
                        $stmt_update_profil,
                        "ssi",
                        $nama_baru,
                        $username_baru,
                        $id_user
                    );

                    if (mysqli_stmt_execute($stmt_update_profil)) {

                        $pesan = "Profil berhasil diperbarui.";
                        $tipe_pesan = "success";

                        $_SESSION["nama"] = $nama_baru;
                        $_SESSION["username"] = $username_baru;

                    } else {

                        $pesan = "Profil gagal diperbarui.";
                        $tipe_pesan = "error";

                    }

                    mysqli_stmt_close($stmt_update_profil);

                } else {

                    $pesan = "Proses pembaruan profil gagal.";
                    $tipe_pesan = "error";

                }
            }

            mysqli_stmt_close($stmt_cek);

        } else {

            $pesan = "Data profil tidak dapat diproses.";
            $tipe_pesan = "error";

        }
    }
}

/* =====================================================
   UBAH PASSWORD
===================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ubah_password"])) {

    $password_lama = trim($_POST["password_lama"] ?? "");
    $password_baru = trim($_POST["password_baru"] ?? "");
    $konfirmasi_password = trim($_POST["konfirmasi_password"] ?? "");

    if (
        $password_lama === "" ||
        $password_baru === "" ||
        $konfirmasi_password === ""
    ) {

        $pesan = "Semua kolom password wajib diisi.";
        $tipe_pesan = "error";

    } elseif (strlen($password_baru) < 6) {

        $pesan = "Password baru minimal 6 karakter.";
        $tipe_pesan = "error";

    } elseif ($password_baru !== $konfirmasi_password) {

        $pesan = "Konfirmasi password baru tidak sesuai.";
        $tipe_pesan = "error";

    } else {

        $password_database = null;

        $stmt = mysqli_prepare(
            $koneksi,
            "SELECT password
             FROM users
             WHERE id_user = ?
             AND role = 'Super Admin'
             LIMIT 1"
        );

        if ($stmt) {

            mysqli_stmt_bind_param($stmt, "i", $id_user);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $password_database);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);

            if ($password_database === null || $password_database !== $password_lama) {

                $pesan = "Password lama tidak sesuai.";
                $tipe_pesan = "error";

            } else {

                $stmt_update = mysqli_prepare(
                    $koneksi,
                    "UPDATE users
                     SET password = ?
                     WHERE id_user = ?
                     AND role = 'Super Admin'"
                );

                if ($stmt_update) {

                    mysqli_stmt_bind_param($stmt_update, "si", $password_baru, $id_user);

                    if (mysqli_stmt_execute($stmt_update)) {

                        $pesan = "Password berhasil diperbarui.";
                        $tipe_pesan = "success";
                        $password_lama_isi = $password_baru;

                    } else {

                        $pesan = "Password gagal diperbarui.";
                        $tipe_pesan = "error";

                    }

                    mysqli_stmt_close($stmt_update);

                } else {

                    $pesan = "Proses pembaruan password gagal.";
                    $tipe_pesan = "error";

                }
            }

        } else {

            $pesan = "Data akun tidak dapat diproses.";
            $tipe_pesan = "error";

        }
    }
}

/* =====================================================
   AMBIL DATA PROFIL (tanpa password)
===================================================== */
$stmt = mysqli_prepare(
    $koneksi,
    "SELECT
        nama,
        username,
        role,
        status
     FROM users
     WHERE id_user = ?
     AND role = 'Super Admin'
     LIMIT 1"
);

if (!$stmt) {
    die("Query Profil gagal: " . mysqli_error($koneksi));
}

mysqli_stmt_bind_param($stmt, "i", $id_user);
mysqli_stmt_execute($stmt);

mysqli_stmt_bind_result(
    $stmt,
    $nama,
    $username,
    $role,
    $status
);

mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

$nama = $nama ?? "Super Admin";
$username = $username ?? "-";
$role = $role ?? "Super Admin";
$status = $status ?? "Aktif";

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
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #263247;
        }

        button,
        input {
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .main {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            background: #ffffff;
        }

        .content {
            padding: 30px 34px 34px;
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
            width: 100%;
            padding: 12px 15px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 13px;
            font-weight: 500;
        }

        .alert.success {
            background: #edf8f0;
            border: 1px solid #d6ecd9;
            color: #2f8650;
        }

        .alert.error {
            background: #fff2f3;
            border: 1px solid #efd4d7;
            color: #b51f2a;
        }

        /* CARD UTAMA */
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
            transition:
                background .18s ease,
                color .18s ease,
                transform .18s ease;
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
            background: transparent;
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
            background: #ffffff;
            color: #344054;
            font-size: 13px;
        }

        .field-value.readonly {
            background: #f8fafc;
            color: #667085;
        }

        .role-badge,
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
        }

        .role-badge {
            background: #f9e8e9;
            color: #a8212b;
        }

        .status-badge {
            background: #e9f7ed;
            color: #328650;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #43a05f;
        }

        .profile-form {
            margin-top: 22px;
        }

        .field-input {
            width: 100%;
            height: 44px;
            border: 1px solid #d9dfe7;
            border-radius: 8px;
            background: #ffffff;
            color: #344054;
            padding: 0 12px;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 13px;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease;
        }

        .field-input:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, 0.06);
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
            color: #ffffff;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s ease;
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
            background: #ffffff;
            color: #344054;
            font-size: 13px;
            outline: none;
            transition:
                border-color .18s ease,
                box-shadow .18s ease;
        }

        .password-input input:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, .06);
        }

        /* Hilangkan warna kuning/biru autofill bawaan browser */
        .password-input input:-moz-autofill,
        .password-input input:autofill {
            background-color: #ffffff !important;
            color: #344054 !important;
            filter: none !important;
        }

        .password-input input:-webkit-autofill,
        .password-input input:-webkit-autofill:hover,
        .password-input input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
            -webkit-text-fill-color: #344054 !important;
            caret-color: #344054;
        }

        html body.dark-mode .password-input input:-moz-autofill,
        html body.dark-mode .password-input input:autofill {
            background-color: #0f141b !important;
            color: #edf2f7 !important;
            filter: none !important;
        }

        html body.dark-mode .password-input input:-webkit-autofill,
        html body.dark-mode .password-input input:-webkit-autofill:hover,
        html body.dark-mode .password-input input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #0f141b inset !important;
            -webkit-text-fill-color: #edf2f7 !important;
            caret-color: #edf2f7;
        }

        /* Field username tersembunyi (agar password manager tahu akun yang diganti) */
        .visually-hidden-field {
            position: absolute !important;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
            opacity: 0;
            pointer-events: none;
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
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition:
                background .18s ease,
                transform .18s ease;
        }

        .btn-save:hover {
            background: #991c25;
            transform: translateY(-1px);
        }

        .footer {
            text-align: center;
            color: #8b96a6;
            font-size: 11px;
            margin-top: 24px;
        }

        @media (max-width: 950px) {
            .settings-card {
                grid-template-columns: 180px minmax(0, 1fr);
            }

            .settings-content {
                padding: 4px 0 0 22px;
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 22px 18px 28px;
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
                padding: 0 0 12px;
                margin-bottom: 20px;
            }

            .settings-nav-title {
                display: none;
            }

            .settings-link {
                width: auto;
                flex: 1;
                justify-content: center;
                margin-bottom: 0;
            }

            .settings-content {
                padding: 0;
            }
        }

        @media (max-width: 620px) {
            .profile-info-grid {
                grid-template-columns: 1fr;
            }

            .settings-link span {
                font-size: 13px;
            }
        }

        /* =====================================================
           DARK MODE (palet sama dengan Dashboard)
        ===================================================== */
        html:has(body.dark-mode),
        html:has(body.dark-mode) body { background: #0f141b !important; }

        html body.dark-mode { background: #0f141b !important; color: #e8edf4 !important; }

        html body.dark-mode .main,
        html body.dark-mode .content { background: #0f141b !important; }

        html body.dark-mode .page-title h1,
        html body.dark-mode .panel-header h2 { color: #edf2f7 !important; }

        html body.dark-mode .page-title p,
        html body.dark-mode .panel-header p,
        html body.dark-mode .settings-nav-title,
        html body.dark-mode .profile-note,
        html body.dark-mode .password-note,
        html body.dark-mode .footer { color: #8f9bac !important; }

        html body.dark-mode .settings-card {
            background: #151b23 !important;
            border: 1px solid #2c3542 !important;
            box-shadow: none !important;
        }

        html body.dark-mode .settings-nav { border-color: #2c3542 !important; }
        html body.dark-mode .settings-link { color: #cbd2dc !important; }

        html body.dark-mode .settings-link:hover,
        html body.dark-mode .settings-link.active {
            background: #382326 !important;
            color: #f07a80 !important;
        }

        html body.dark-mode .panel-header,
        html body.dark-mode .profile-note { border-color: #2c3542 !important; }

        html body.dark-mode .field-label,
        html body.dark-mode .form-group label { color: #cbd2dc !important; }

        html body.dark-mode .field-input,
        html body.dark-mode .password-input input {
            background: #0f141b !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
            color-scheme: dark !important;
        }

        html body.dark-mode .field-input::placeholder,
        html body.dark-mode .password-input input::placeholder { color: #6f7a8a !important; }

        html body.dark-mode .field-input:focus,
        html body.dark-mode .password-input input:focus {
            border-color: #b51f2a !important;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, .16) !important;
        }

        html body.dark-mode .field-value {
            background: #1b222c !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .field-value.readonly { background: #1b222c !important; }

        html body.dark-mode .eye-button { color: #aab3c1 !important; }
        html body.dark-mode .eye-button:hover { color: #f07a80 !important; }

        html body.dark-mode .btn-save-profile,
        html body.dark-mode .btn-save {
            background: #b51f2a !important;
            color: #fff !important;
        }

        html body.dark-mode .btn-save-profile:hover,
        html body.dark-mode .btn-save:hover { background: #d12b36 !important; }

        html body.dark-mode .role-badge { background: #1d3158 !important; color: #8eb5ff !important; }
        html body.dark-mode .status-badge { background: #183d2a !important; color: #72d89a !important; }
        html body.dark-mode .status-dot { background: #72d89a !important; }

        html body.dark-mode .alert.success {
            background: #183d2a !important;
            border-color: #2b5a3d !important;
            color: #72d89a !important;
        }

        html body.dark-mode .alert.error {
            background: #302326 !important;
            border-color: #493338 !important;
            color: #f08a90 !important;
        }

    </style>

</head>

<body>

    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">

            <div class="page-title">
                <h1>Profil</h1>
                <p>Kelola informasi akun dan keamanan akun Anda.</p>
            </div>

            <?php if ($pesan !== ""): ?>

                <div class="alert <?= htmlspecialchars($tipe_pesan) ?>">
                    <?= htmlspecialchars($pesan) ?>
                </div>

            <?php endif; ?>

            <div class="settings-card">

                <aside class="settings-nav">

                    <div class="settings-nav-title">
                        Pengaturan
                    </div>

                    <button type="button" class="settings-link active" data-target="panel-profil">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="8" r="3.5"></circle>
                            <path d="M5 20c0-3.4 2.8-6 7-6s7 2.6 7 6"></path>
                        </svg>
                        <span>Profil</span>
                    </button>

                    <button type="button" class="settings-link" data-target="panel-akun">
                        <svg viewBox="0 0 24 24">
                            <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        </svg>
                        <span>Akun</span>
                    </button>

                </aside>

                <section class="settings-content">

                    <!-- ================= PANEL PROFIL ================= -->
                    <div class="settings-panel active" id="panel-profil">

                        <div class="panel-header">
                            <h2>Profil</h2>
                            <p>Informasi pengguna yang sedang login pada sistem SIMO.</p>
                        </div>

                        <form method="POST" class="profile-form" autocomplete="off">

                            <div class="profile-info-grid">

                                <div class="field">
                                    <label class="field-label" for="nama">Nama Lengkap</label>
                                    <input
                                        type="text"
                                        id="nama"
                                        name="nama"
                                        class="field-input"
                                        value="<?= htmlspecialchars($nama) ?>"
                                        required
                                    >
                                </div>

                                <div class="field">
                                    <label class="field-label" for="username">Username</label>
                                    <input
                                        type="text"
                                        id="username"
                                        name="username"
                                        class="field-input"
                                        value="<?= htmlspecialchars($username) ?>"
                                        minlength="3"
                                        required
                                    >
                                </div>

                                <div class="field">
                                    <span class="field-label">Role</span>
                                    <div class="field-value">
                                        <span class="role-badge">
                                            <?= htmlspecialchars($role) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="field">
                                    <span class="field-label">Status Akun</span>
                                    <div class="field-value">

                                        <?php if (trim($status) === "Aktif"): ?>

                                            <span class="status-badge">
                                                <span class="status-dot"></span>
                                                Aktif
                                            </span>

                                        <?php else: ?>

                                            <span class="role-badge">
                                                <?= htmlspecialchars($status) ?>
                                            </span>

                                        <?php endif; ?>

                                    </div>
                                </div>

                            </div>

                            <div class="profile-actions">
                                <button type="submit" name="simpan_profil" class="btn-save-profile">
                                    Simpan Perubahan
                                </button>
                            </div>

                        </form>

                        <div class="profile-note">
                            Nama lengkap dan username dapat diperbarui sesuai kebutuhan akun Super Admin.
                        </div>

                    </div>

                    <!-- ================= PANEL AKUN ================= -->
                    <div class="settings-panel" id="panel-akun">

                        <div class="panel-header">
                            <h2>Akun</h2>
                            <p>Perbarui password untuk menjaga keamanan akun Super Admin.</p>
                        </div>

                        <div class="password-box">

                            <form method="POST" autocomplete="on">

                                <!--
                                    Username tersembunyi: memberi tahu password manager browser
                                    akun mana yang sedang diganti passwordnya, sehingga tidak
                                    muncul dialog "Pilih info masuk mana yang diperbarui".
                                -->
                                <input
                                    type="text"
                                    name="username_akun"
                                    class="visually-hidden-field"
                                    value="<?= htmlspecialchars($username) ?>"
                                    autocomplete="username"
                                    readonly
                                    tabindex="-1"
                                    aria-hidden="true"
                                >

                                <div class="form-group">

                                    <label for="password_lama">Password Lama</label>

                                    <div class="password-input">

                                        <input
                                            type="password"
                                            id="password_lama"
                                            name="password_lama"
                                            value="<?= htmlspecialchars($password_lama_isi) ?>"
                                            placeholder="Masukkan password lama"
                                            autocomplete="current-password"
                                            spellcheck="false"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="eye-button"
                                            onclick="togglePassword('password_lama', this)"
                                            aria-label="Tampilkan password"
                                        >
                                            <svg viewBox="0 0 24 24">
                                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                                <circle cx="12" cy="12" r="2.5"></circle>
                                            </svg>
                                        </button>

                                    </div>

                                </div>

                                <div class="form-group">

                                    <label for="password_baru">Password Baru</label>

                                    <div class="password-input">

                                        <input
                                            type="text"
                                            id="password_baru"
                                            data-mask="password_baru_real"
                                            placeholder="Masukkan password baru"
                                            autocomplete="off"
                                            autocapitalize="off"
                                            autocorrect="off"
                                            spellcheck="false"
                                            required
                                        >
                                        <input type="hidden" id="password_baru_real" name="password_baru" value="">

                                        <button
                                            type="button"
                                            class="eye-button"
                                            onclick="togglePassword('password_baru', this)"
                                            aria-label="Tampilkan password"
                                        >
                                            <svg viewBox="0 0 24 24">
                                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                                <circle cx="12" cy="12" r="2.5"></circle>
                                            </svg>
                                        </button>

                                    </div>

                                </div>

                                <div class="password-note">
                                    Password baru minimal 6 karakter.
                                </div>

                                <div class="form-group">

                                    <label for="konfirmasi_password">Konfirmasi Password Baru</label>

                                    <div class="password-input">

                                        <input
                                            type="text"
                                            id="konfirmasi_password"
                                            data-mask="konfirmasi_password_real"
                                            placeholder="Ulangi password baru"
                                            autocomplete="off"
                                            autocapitalize="off"
                                            autocorrect="off"
                                            spellcheck="false"
                                            required
                                        >
                                        <input type="hidden" id="konfirmasi_password_real" name="konfirmasi_password" value="">

                                        <button
                                            type="button"
                                            class="eye-button"
                                            onclick="togglePassword('konfirmasi_password', this)"
                                            aria-label="Tampilkan password"
                                        >
                                            <svg viewBox="0 0 24 24">
                                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                                <circle cx="12" cy="12" r="2.5"></circle>
                                            </svg>
                                        </button>

                                    </div>

                                </div>

                                <button type="submit" name="ubah_password" class="btn-save">
                                    Simpan Password
                                </button>

                            </form>

                        </div>

                    </div>

                </section>

            </div>

            <div class="footer">
                © <?= date("Y"); ?> SIMO METRP. All rights reserved.
            </div>

        </div>

    </div>

    <script>

        const settingsLinks = document.querySelectorAll(".settings-link");
        const settingsPanels = document.querySelectorAll(".settings-panel");

        settingsLinks.forEach((link) => {

            link.addEventListener("click", function () {

                const target = this.getAttribute("data-target");

                settingsLinks.forEach((item) => item.classList.remove("active"));
                settingsPanels.forEach((panel) => panel.classList.remove("active"));

                this.classList.add("active");

                const targetPanel = document.getElementById(target);

                if (targetPanel) {
                    targetPanel.classList.add("active");
                }

            });

        });

        const BULLET = "\u2022";

        const formPassword = document.querySelector("#panel-akun form");

        if (formPassword) {
            formPassword.addEventListener("submit", function (e) {
                const baru = document.getElementById("password_baru_real");
                if (baru && baru.value.length < 6) {
                    e.preventDefault();
                    alert("Password baru minimal 6 karakter.");
                }
            });
        }

        const EYE_OPEN = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                <circle cx="12" cy="12" r="2.5"></circle>
            </svg>
        `;

        const EYE_CLOSED = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3l18 18"></path>
                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>
                <path d="M9.9 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.2 17.2 0 0 1-3.1 3.9"></path>
                <path d="M6.2 6.2C3.4 8.2 2 12 2 12s3.5 7 10 7c1.5 0 2.8-.3 4-.8"></path>
            </svg>
        `;

        /*
         * Kolom password baru & konfirmasi memakai input teks biasa yang
         * ditampilkan sebagai titik-titik, supaya Firefox tidak menganggapnya
         * field password (dan tidak memunculkan daftar login tersimpan).
         * Nilai asli disimpan di input hidden dan itulah yang dikirim ke server.
         */
        document.querySelectorAll("input[data-mask]").forEach((visible) => {

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

        function togglePassword(id, button) {

            const input = document.getElementById(id);

            if (!input) {
                return;
            }

            // Kolom bertopeng (password baru & konfirmasi)
            if (input.hasAttribute("data-mask")) {

                const hidden = document.getElementById(input.getAttribute("data-mask"));
                const show = input.dataset.show !== "1";

                input.dataset.show = show ? "1" : "0";
                input.value = show ? hidden.value : BULLET.repeat(hidden.value.length);
                button.innerHTML = show ? EYE_CLOSED : EYE_OPEN;

                return;
            }

            // Kolom password biasa (password lama)
            if (input.type === "password") {
                input.type = "text";
                button.innerHTML = EYE_CLOSED;
            } else {
                input.type = "password";
                button.innerHTML = EYE_OPEN;
            }

        }

    </script>

</body>

</html>
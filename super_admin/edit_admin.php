<?php

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Super Admin") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_admin = isset($_GET["id"]) ? (int) $_GET["id"] : 0;

if ($id_admin <= 0) {
    header("Location: pengguna.php");
    exit;
}

$password_default = "123456";
$pesan = "";
$tipe_pesan = "";

$stmt_admin = mysqli_prepare(
    $koneksi,
    "SELECT
        u.id_user,
        u.nama,
        u.username,
        u.no_hp,
        u.password,
        u.role,
        u.status,
        u.id_divisi,
        d.nama_divisi
     FROM users u
     LEFT JOIN divisi d
        ON u.id_divisi = d.id_divisi
     WHERE u.id_user = ?
       AND u.role = 'Admin'
     LIMIT 1"
);

mysqli_stmt_bind_param($stmt_admin, "i", $id_admin);
mysqli_stmt_execute($stmt_admin);

$result_admin = mysqli_stmt_get_result($stmt_admin);
$data_admin = mysqli_fetch_assoc($result_admin);

mysqli_stmt_close($stmt_admin);

if (!$data_admin) {
    header("Location: pengguna.php");
    exit;
}

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT
        id_divisi,
        nama_divisi
     FROM divisi
     ORDER BY id_divisi ASC"
);

if (isset($_POST["simpan"])) {

    $nama = trim($_POST["nama"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $no_hp = trim($_POST["no_hp"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $id_divisi = (int) ($_POST["id_divisi"] ?? 0);

    if (
        $nama == "" ||
        $username == "" ||
        $no_hp == "" ||
        $password == "" ||
        $id_divisi <= 0
    ) {

        $pesan = "Semua data wajib diisi.";
        $tipe_pesan = "error";

    } else {

        $cek_username = mysqli_prepare(
            $koneksi,
            "SELECT
                id_user
             FROM users
             WHERE username = ?
               AND id_user != ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param($cek_username, "si", $username, $id_admin);
        mysqli_stmt_execute($cek_username);
        mysqli_stmt_store_result($cek_username);

        if (mysqli_stmt_num_rows($cek_username) > 0) {

            $pesan = "Username sudah digunakan oleh pengguna lain.";
            $tipe_pesan = "error";

            mysqli_stmt_close($cek_username);

        } else {

            $stmt_update = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET
                    nama = ?,
                    username = ?,
                    no_hp = ?,
                    password = ?,
                    id_divisi = ?
                 WHERE id_user = ?
                   AND role = 'Admin'"
            );

            mysqli_stmt_bind_param(
                $stmt_update,
                "ssssii",
                $nama,
                $username,
                $no_hp,
                $password,
                $id_divisi,
                $id_admin
            );

            if (mysqli_stmt_execute($stmt_update)) {

                mysqli_stmt_close($stmt_update);
                mysqli_stmt_close($cek_username);

                header("Location: pengguna.php?tab=admin&pesan=admin_diubah");
                exit;

            } else {

                $pesan = "Data admin gagal diubah: " . mysqli_error($koneksi);
                $tipe_pesan = "error";

                mysqli_stmt_close($stmt_update);
                mysqli_stmt_close($cek_username);

            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Admin - SIMO</title>

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
        input,
        select {
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

        .settings-card {
            width: 100%;
            background: transparent;
            border: none;
            border-radius: 0;
            display: grid;
            grid-template-columns: 220px minmax(0, 1fr);
            min-height: 0;
            overflow: visible;
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

        .required {
            color: #b51f2a;
        }

        .field-input,
        .field-select {
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

        .field-input:focus,
        .field-select:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, 0.06);
        }

        .field-value {
            min-height: 44px;
            display: flex;
            align-items: center;
            width: 100%;
            padding: 0 12px;
            border: 1px solid #d9dfe7;
            border-radius: 8px;
            background: #f8fafc;
            color: #667085;
            font-size: 13px;
        }

        .field-hint {
            margin-top: 6px;
            font-size: 11px;
            line-height: 1.4;
            color: #8b97a8;
        }

        .role-badge,
        .status-badge {
            min-height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
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

        .status-muted {
            background: #eeeeef;
            color: #8b95a4;
        }

        /* ===== PANEL AKUN / PASSWORD ===== */
        .password-box {
            max-width: 690px;
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

        .password-input {
            position: relative;
        }

        .password-input .field-input {
            padding-right: 43px;
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

        .form-actions {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e4e8ed;
        }

        .btn-save,
        .btn-cancel {
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            border-radius: 8px;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .18s ease, color .18s ease, border-color .18s ease;
        }

        .btn-save {
            border: none;
            background: #ae3037;
            color: #ffffff;
        }

        .btn-save:hover {
            background: #96272e;
        }

        .btn-cancel {
            border: 1px solid #d9dfe7;
            background: #ffffff;
            color: #667085;
        }

        .btn-cancel:hover {
            background: #f8f9fa;
        }

        .note {
            margin-top: 18px;
            padding: 0;
            border: 0;
            background: transparent;
            font-size: 12px;
            line-height: 1.5;
            color: #8a96a6;
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

            .form-actions {
                flex-wrap: wrap;
            }

            .btn-save,
            .btn-cancel {
                width: 100%;
            }
        }

        /* Card utama (light) */
        .settings-card {
            background: #ffffff;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
            padding: 26px 28px;
            overflow: hidden;
        }

        .settings-nav {
            border-top: none;
            border-bottom: none;
            border-left: none;
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
        html body.dark-mode .field-hint,
        html body.dark-mode .note,
        html body.dark-mode .footer { color: #8f9bac !important; }

        html body.dark-mode .settings-card {
            background: #151b23 !important;
            border: 1px solid #2c3542 !important;
            box-shadow: none !important;
        }

        html body.dark-mode .settings-nav { border-right-color: #2c3542 !important; }
        html body.dark-mode .settings-nav-title { color: #8f9bac !important; }
        html body.dark-mode .settings-link { color: #cbd2dc !important; }

        html body.dark-mode .settings-link:hover,
        html body.dark-mode .settings-link.active {
            background: #382326 !important;
            color: #f07a80 !important;
        }

        html body.dark-mode .panel-header,
        html body.dark-mode .form-actions { border-color: #2c3542 !important; }

        html body.dark-mode .field-label,
        html body.dark-mode .form-group label { color: #cbd2dc !important; }

        html body.dark-mode .required { color: #f07a80 !important; }

        html body.dark-mode .field-input,
        html body.dark-mode .field-select,
        html body.dark-mode .password-input .field-input {
            background: #0f141b !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
            color-scheme: dark !important;
        }

        html body.dark-mode .field-input:focus,
        html body.dark-mode .field-select:focus {
            border-color: #b51f2a !important;
            box-shadow: 0 0 0 3px rgba(181, 31, 42, .16) !important;
        }

        html body.dark-mode .field-select option {
            background: #151b23 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .field-value {
            background: #1b222c !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .eye-button { color: #aab3c1 !important; }
        html body.dark-mode .eye-button:hover { color: #f07a80 !important; }

        html body.dark-mode .btn-cancel {
            background: #202733 !important;
            border-color: #2c3542 !important;
            color: #cbd2dc !important;
        }

        html body.dark-mode .btn-cancel:hover { background: #2a3342 !important; }

        html body.dark-mode .btn-save {
            background: #b51f2a !important;
            border-color: #b51f2a !important;
            color: #fff !important;
        }

        html body.dark-mode .btn-save:hover {
            background: #d12b36 !important;
            border-color: #d12b36 !important;
        }

        html body.dark-mode .role-badge { background: #1d3158 !important; color: #8eb5ff !important; }
        html body.dark-mode .status-badge { background: #183d2a !important; color: #72d89a !important; }
        html body.dark-mode .status-dot { background: #72d89a !important; }
        html body.dark-mode .status-muted { background: #34383e !important; color: #c5cbd3 !important; }

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
            <h1>Edit Admin</h1>
            <p>Kelola informasi akun dan keamanan Admin Divisi.</p>
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

                <form method="POST" action="" autocomplete="off">

                    <!-- ================= PANEL PROFIL ================= -->
                    <div class="settings-panel active" id="panel-profil">

                        <div class="panel-header">
                            <h2>Profil</h2>
                            <p>Informasi akun Admin Divisi yang sedang dipilih.</p>
                        </div>

                        <div class="profile-info-grid">

                            <div class="field">
                                <label class="field-label" for="nama">
                                    Nama Lengkap <span class="required">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="nama"
                                    name="nama"
                                    class="field-input"
                                    value="<?= htmlspecialchars($data_admin["nama"] ?? "") ?>"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label class="field-label" for="username">
                                    Username <span class="required">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    class="field-input"
                                    value="<?= htmlspecialchars($data_admin["username"] ?? "") ?>"
                                    minlength="3"
                                    required
                                >
                                <div class="field-hint">
                                    Username digunakan untuk login.
                                </div>
                            </div>

                            <div class="field">
                                <label class="field-label" for="no_hp">
                                    No. HP <span class="required">*</span>
                                </label>
                                <input
                                    type="tel"
                                    id="no_hp"
                                    name="no_hp"
                                    class="field-input"
                                    value="<?= htmlspecialchars($data_admin["no_hp"] ?? "") ?>"
                                    maxlength="20"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label class="field-label" for="id_divisi">
                                    Departemen Utama <span class="required">*</span>
                                </label>
                                <select
                                    id="id_divisi"
                                    name="id_divisi"
                                    class="field-select"
                                    required
                                >
                                    <option value="">-- Pilih Departemen --</option>

                                    <?php
                                    if ($query_divisi && mysqli_num_rows($query_divisi) > 0) {

                                        while ($divisi = mysqli_fetch_assoc($query_divisi)) {

                                            $selected =
                                                (
                                                    (int) ($data_admin["id_divisi"] ?? 0) ===
                                                    (int) $divisi["id_divisi"]
                                                )
                                                ? "selected"
                                                : "";
                                    ?>

                                        <option
                                            value="<?= (int) $divisi["id_divisi"] ?>"
                                            <?= $selected ?>
                                        >
                                            <?= htmlspecialchars($divisi["nama_divisi"]) ?>
                                        </option>

                                    <?php
                                        }
                                    }
                                    ?>

                                </select>
                            </div>

                            <div class="field">
                                <span class="field-label">Role</span>
                                <div class="field-value">
                                    <span class="role-badge">
                                        <?= htmlspecialchars($data_admin["role"] ?? "Admin") ?>
                                    </span>
                                </div>
                            </div>

                            <div class="field">
                                <span class="field-label">Status Akun</span>
                                <div class="field-value">

                                    <?php if (trim($data_admin["status"] ?? "") === "Aktif"): ?>

                                        <span class="status-badge">
                                            <span class="status-dot"></span>
                                            Aktif
                                        </span>

                                    <?php else: ?>

                                        <span class="role-badge status-muted">
                                            <?= htmlspecialchars($data_admin["status"] ?? "Tidak Aktif") ?>
                                        </span>

                                    <?php endif; ?>

                                </div>
                            </div>

                        </div>

                        <div class="form-actions">
                            <a href="pengguna.php?tab=admin" class="btn-cancel">Batal</a>
                            <button type="submit" name="simpan" class="btn-save">Simpan Perubahan</button>
                        </div>

                        <div class="note">
                            Data profil Admin Divisi dapat diperbarui oleh Super Admin sesuai kebutuhan.
                        </div>

                    </div>

                    <!-- ================= PANEL AKUN ================= -->
                    <div class="settings-panel" id="panel-akun">

                        <div class="panel-header">
                            <h2>Akun</h2>
                            <p>Kelola password dan keamanan akun Admin Divisi.</p>
                        </div>

                        <div class="password-box">

                            <div class="form-group">

                                <label for="password">
                                    Password <span class="required">*</span>
                                </label>

                                <div class="password-input">

                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        class="field-input"
                                        value="<?= htmlspecialchars($data_admin["password"] ?? "") ?>"
                                        autocomplete="new-password"
                                        spellcheck="false"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="eye-button"
                                        onclick="togglePassword()"
                                        aria-label="Tampilkan password"
                                        title="Tampilkan password"
                                    >
                                        <svg viewBox="0 0 24 24">
                                            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path>
                                            <circle cx="12" cy="12" r="2.5"></circle>
                                        </svg>
                                    </button>

                                </div>

                                <button type="button" class="edit-default" id="passwordDefault">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M3 12a9 9 0 1 0 3-6.7"></path>
                                        <path d="M3 4v5h5"></path>
                                    </svg>
                                    Gunakan Password Default (<?= htmlspecialchars($password_default) ?>)
                                </button>

                                <div class="field-hint">
                                    Password dapat dilihat dan diubah oleh Super Admin.
                                </div>

                            </div>

                        </div>

                        <div class="form-actions">
                            <a href="pengguna.php?tab=admin" class="btn-cancel">Batal</a>
                            <button type="submit" name="simpan" class="btn-save">Simpan Perubahan</button>
                        </div>

                        <div class="note">
                            Gunakan password default apabila Admin tidak dapat mengakses akun.
                        </div>

                    </div>

                </form>

            </section>

        </div>

        <div class="footer">
            © <?= date("Y") ?> SIMO METRP. All rights reserved.
        </div>

    </div>

</div>

<script>

const PASSWORD_DEFAULT = <?= json_encode($password_default) ?>;

const settingsLinks = document.querySelectorAll(".settings-link");
const settingsPanels = document.querySelectorAll(".settings-panel");

settingsLinks.forEach(function (link) {

    link.addEventListener("click", function () {

        const target = this.getAttribute("data-target");

        settingsLinks.forEach(function (item) { item.classList.remove("active"); });
        settingsPanels.forEach(function (panel) { panel.classList.remove("active"); });

        this.classList.add("active");

        const targetPanel = document.getElementById(target);

        if (targetPanel) {
            targetPanel.classList.add("active");
        }

    });

});

const EYE_OPEN = '<svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>';

const EYE_CLOSED = '<svg viewBox="0 0 24 24"><path d="M3 3l18 18"></path><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path><path d="M9.9 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.2 17.2 0 0 1-3.1 3.9"></path><path d="M6.2 6.2C3.4 8.2 2 12 2 12s3.5 7 10 7c1.5 0 2.8-.3 4-.8"></path></svg>';

function togglePassword() {

    const input = document.getElementById("password");
    const button = document.querySelector("#panel-akun .eye-button");

    if (!input) {
        return;
    }

    if (input.type === "password") {
        input.type = "text";
        button.innerHTML = EYE_CLOSED;
        button.setAttribute("aria-label", "Sembunyikan password");
        button.setAttribute("title", "Sembunyikan password");
    } else {
        input.type = "password";
        button.innerHTML = EYE_OPEN;
        button.setAttribute("aria-label", "Tampilkan password");
        button.setAttribute("title", "Tampilkan password");
    }

}

/* Gunakan password default */
const passwordDefault = document.getElementById("passwordDefault");

if (passwordDefault) {

    passwordDefault.addEventListener("click", function () {

        const input = document.getElementById("password");
        const button = document.querySelector("#panel-akun .eye-button");

        input.value = PASSWORD_DEFAULT;
        input.type = "text";
        button.innerHTML = EYE_CLOSED;
        button.setAttribute("aria-label", "Sembunyikan password");
        button.setAttribute("title", "Sembunyikan password");
        input.focus();

    });

}

</script>

</body>

</html>
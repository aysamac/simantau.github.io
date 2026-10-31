<?php

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Super Admin") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/** @var mysqli $koneksi */

$id = $_SESSION["id_user"];

$pesan = "";
$jenis_pesan = "";

$data = mysqli_query($koneksi, "SELECT * FROM users WHERE id_user='$id'");
$user = mysqli_fetch_assoc($data);


/* =====================================================
   SIMPAN PERUBAHAN
===================================================== */

if (isset($_POST["simpan"])) {

    $nama = mysqli_real_escape_string($koneksi, $_POST["nama"]);
    $username = mysqli_real_escape_string($koneksi, $_POST["username"]);

    $password_lama = $_POST["password_lama"];
    $password_baru = $_POST["password_baru"];
    $konfirmasi = $_POST["konfirmasi"];


    if ($nama == "" || $username == "") {

        $pesan = "Nama dan username tidak boleh kosong.";
        $jenis_pesan = "error";

    } else {

        /* Jika ingin mengganti password */

        if ($password_baru != "") {

            if ($password_lama == "") {

                $pesan = "Masukkan password lama terlebih dahulu.";
                $jenis_pesan = "error";

            } elseif ($password_lama != $user["password"]) {

                $pesan = "Password lama salah.";
                $jenis_pesan = "error";

            } elseif ($password_baru != $konfirmasi) {

                $pesan = "Konfirmasi password baru tidak cocok.";
                $jenis_pesan = "error";

            } else {

                mysqli_query(
                    $koneksi,
                    "UPDATE users SET
                    nama='$nama',
                    username='$username',
                    password='$password_baru'
                    WHERE id_user='$id'"
                );

                $_SESSION["nama"] = $nama;

                $pesan = "Pengaturan akun berhasil disimpan.";
                $jenis_pesan = "success";

                $data = mysqli_query(
                    $koneksi,
                    "SELECT * FROM users WHERE id_user='$id'"
                );

                $user = mysqli_fetch_assoc($data);
            }

        } else {

            /* Jika password tidak diganti */

            mysqli_query(
                $koneksi,
                "UPDATE users SET
                nama='$nama',
                username='$username'
                WHERE id_user='$id'"
            );

            $_SESSION["nama"] = $nama;

            $pesan = "Pengaturan akun berhasil disimpan.";
            $jenis_pesan = "success";

            $data = mysqli_query(
                $koneksi,
                "SELECT * FROM users WHERE id_user='$id'"
            );

            $user = mysqli_fetch_assoc($data);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Pengaturan - SIMO</title>


    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", Arial, sans-serif;
        }


        body {
            background: #f2f5f9;
            min-height: 100vh;
            display: flex;
            color: #2c3644;
        }


        a {
            text-decoration: none;
            color: inherit;
        }


        /* =====================================================
           SIDEBAR
        ===================================================== */

        .sidebar {
            width: 240px;
            background: #181818;
            color: #d6d6d6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 1000;
        }


        .sidebar-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 18px 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }


        .sidebar-logo {
            width: 175px;
            height: auto;
            display: block;
            margin-bottom: 18px;
        }


        .sidebar-brand h1 {
            font-size: 24px;
            color: #ffffff;
            line-height: 1.1;
            letter-spacing: 0.5px;
        }


        .sidebar-brand p {
            font-size: 11px;
            color: #a9a9a9;
            margin-top: 5px;
            text-align: center;
        }


        /* =====================================================
           NAVIGATION
        ===================================================== */

        .nav {
            padding: 18px 14px;
            flex: 1;
        }


        .nav-title {
            font-size: 10px;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 8px 10px 10px;
        }


        .nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 500;
            color: #bdbdbd;
            margin-bottom: 3px;
            transition: background 0.15s ease, color 0.15s ease;
        }


        .nav a:hover {
            background: rgba(255,255,255,0.07);
            color: #ffffff;
        }


        .nav a.active {
            background: #b51f2a;
            color: #ffffff;
        }


        .nav a svg {
            width: 17px;
            height: 17px;
            stroke: currentColor;
            flex-shrink: 0;
        }


        /* =====================================================
           SIDEBAR BOTTOM
        ===================================================== */

        .sidebar-bottom {
            padding: 14px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }


        .logout {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 9px;
            color: #bdbdbd;
            font-size: 14px;
            transition: background 0.15s ease, color 0.15s ease;
        }


        .logout:hover {
            background: rgba(255,255,255,0.07);
            color: #ffffff;
        }


        .logout svg {
            width: 17px;
            height: 17px;
            stroke: currentColor;
        }


        /* =====================================================
           MAIN
        ===================================================== */

        .main {
            margin-left: 240px;
            width: calc(100% - 240px);
            min-width: 0;
        }


        /* =====================================================
           TOPBAR
        ===================================================== */

        .topbar {
            height: 72px;
            background: #ffffff;
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e7ecf3;
            position: sticky;
            top: 0;
            z-index: 900;
        }


        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }


        .topbar-left svg {
            width: 20px;
            height: 20px;
            stroke: #4c5b6e;
        }


        .topbar-left h2 {
            font-size: 18px;
            color: #1c2636;
        }


        .topbar-right {
            display: flex;
            align-items: center;
            gap: 22px;
        }


        /* =====================================================
           NOTIFICATION
        ===================================================== */

        .bell {
            position: relative;
            width: 22px;
            height: 22px;
        }


        .bell svg {
            width: 22px;
            height: 22px;
            stroke: #4c5b6e;
        }


        .bell .dot {
            position: absolute;
            top: -3px;
            right: -3px;
            width: 15px;
            height: 15px;
            background: #b51f2a;
            color: #ffffff;
            border-radius: 50%;
            font-size: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }


        /* =====================================================
           USER
        ===================================================== */

        .user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
        }


        .user-chip .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f4e8e9;
            display: flex;
            align-items: center;
            justify-content: center;
        }


        .user-chip .avatar svg {
            width: 18px;
            height: 18px;
            stroke: #b51f2a;
        }


        .user-chip .name {
            font-size: 13.5px;
            font-weight: 700;
            color: #1c2636;
        }


        .user-chip .role {
            font-size: 11.5px;
            color: #8b96a5;
        }


        .user-chip svg.chevron {
            width: 14px;
            height: 14px;
            stroke: #8b96a5;
        }


        /* =====================================================
           CONTENT
        ===================================================== */

        .content {
            padding: 26px 32px 40px;
        }


        .page-title {
            margin-bottom: 22px;
        }


        .page-title h2 {
            font-size: 17px;
            color: #1c2636;
            margin-bottom: 5px;
        }


        .page-title p {
            font-size: 13px;
            color: #667488;
        }


        /* =====================================================
           SETTING CARD
        ===================================================== */

        .setting-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(20,40,70,0.04);
            max-width: 850px;
        }


        .setting-head {
            padding-bottom: 16px;
            border-bottom: 1px solid #edf1f6;
            margin-bottom: 20px;
        }


        .setting-head h3 {
            font-size: 14.5px;
            color: #1c2636;
            margin-bottom: 4px;
        }


        .setting-head p {
            font-size: 12px;
            color: #8b96a5;
        }


        /* =====================================================
           FORM
        ===================================================== */

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 16px;
        }


        .form-group label {
            font-size: 12px;
            color: #667488;
            font-weight: 600;
        }


        .form-group input {
            width: 100%;
            height: 40px;
            border: 1px solid #dce3ec;
            border-radius: 8px;
            padding: 0 12px;
            font-size: 12.5px;
            color: #344154;
            background: #ffffff;
            outline: none;
        }


        .form-group input:focus {
            border-color: #b51f2a;
        }


        .form-group input:disabled {
            background: #f7f9fc;
            color: #8b96a5;
        }


        /* =====================================================
           PASSWORD
        ===================================================== */

        .input-icon {
            position: relative;
        }


        .input-icon input {
            padding-right: 42px;
        }


        .input-icon .toggle-eye {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            cursor: pointer;
            stroke: #9aa6b5;
        }


        .input-icon .toggle-eye:hover {
            stroke: #b51f2a;
        }


        /* =====================================================
           PASSWORD SECTION
        ===================================================== */

        .password-title {
            font-size: 13px;
            font-weight: 700;
            color: #1c2636;
            margin-top: 24px;
            margin-bottom: 14px;
            padding-top: 20px;
            border-top: 1px solid #edf1f6;
        }


        .password-note {
            font-size: 11.5px;
            color: #9aa6b5;
            margin-top: -7px;
            margin-bottom: 16px;
        }


        /* =====================================================
           BUTTON
        ===================================================== */

        .form-action {
            display: flex;
            justify-content: flex-end;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #edf1f6;
        }


        .btn-simpan {
            background: #b51f2a;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }


        .btn-simpan:hover {
            background: #961923;
        }


        /* =====================================================
           PESAN
        ===================================================== */

        .pesan {
            max-width: 850px;
            padding: 11px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 12px;
            font-weight: 600;
        }


        .pesan.success {
            background: #e6f6ec;
            color: #2f9e5b;
            border: 1px solid #ccebd8;
        }


        .pesan.error {
            background: #fde9e8;
            color: #d9534f;
            border: 1px solid #f5d0ce;
        }


        /* =====================================================
           FOOTER
        ===================================================== */

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: #a3adba;
            padding: 26px 0 6px;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 800px) {

            .sidebar {
                display: none;
            }


            .main {
                margin-left: 0;
                width: 100%;
            }


            .content {
                padding: 20px;
            }


            .topbar {
                padding: 14px 18px;
            }


            .setting-card {
                max-width: 100%;
            }

        }

    </style>

</head>


<body>


<!-- =====================================================
     SIDEBAR
===================================================== -->

<div class="sidebar">


    <!-- BRAND -->

    <div class="sidebar-brand">

        <img
            src="../assets/img/logo-simo.jpeg"
            alt="SIMO"
            class="sidebar-logo"
        >

        <h1>
            SIMO
        </h1>

        <p>
            Sistem Informasi Monitoring
        </p>

    </div>


    <!-- NAVIGATION -->

    <div class="nav">

        <div class="nav-title">
            Menu Utama
        </div>


        <!-- DASHBOARD -->

        <a href="dashboard.php">

            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">

                <rect
                    x="3"
                    y="3"
                    width="7"
                    height="7"
                    rx="1"
                ></rect>

                <rect
                    x="14"
                    y="3"
                    width="7"
                    height="7"
                    rx="1"
                ></rect>

                <rect
                    x="3"
                    y="14"
                    width="7"
                    height="7"
                    rx="1"
                ></rect>

                <rect
                    x="14"
                    y="14"
                    width="7"
                    height="7"
                    rx="1"
                ></rect>

            </svg>

            <span>
                Dashboard
            </span>

        </a>


        <!-- PEKERJAAN -->

        <a href="pekerjaan.php">

            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">

                <path d="M9 5h6"></path>

                <path d="M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1z"></path>

                <rect
                    x="4"
                    y="6"
                    width="16"
                    height="14"
                    rx="2"
                ></rect>

                <path d="M8 11h8"></path>

                <path d="M8 15h5"></path>

            </svg>

            <span>
                Pekerjaan
            </span>

        </a>


        <!-- DIVISI -->

        <a href="divisi.php">

            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">

                <path d="M3 21h18"></path>

                <path d="M5 21V8l7-4 7 4v13"></path>

                <path d="M9 21v-6h6v6"></path>

                <path d="M9 10h1"></path>

                <path d="M14 10h1"></path>

            </svg>

            <span>
                Divisi
            </span>

        </a>


        <!-- MONITORING -->

        <a href="monitoring.php">

            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">

                <path d="M3 3v18h18"></path>

                <path d="M7 16l4-5 3 3 5-7"></path>

            </svg>

            <span>
                Monitoring
            </span>

        </a>


        <!-- LAPORAN -->

        <a href="laporan.php">

            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">

                <path d="M6 2h9l5 5v15H6z"></path>

                <path d="M14 2v6h6"></path>

                <path d="M9 13h6"></path>

                <path d="M9 17h6"></path>

            </svg>

            <span>
                Laporan
            </span>

        </a>


        <!-- PENGGUNA -->

        <a href="pengguna.php">

            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">

                <circle
                    cx="9"
                    cy="7"
                    r="4"
                ></circle>

                <path
                    d="M3 21v-2a6 6 0 0 1 12 0v2"
                ></path>

                <path
                    d="M16 11a4 4 0 1 0 0-8"
                ></path>

                <path
                    d="M17 13a6 6 0 0 1 4 6v2"
                ></path>

            </svg>

            <span>
                Pengguna
            </span>

        </a>


        <!-- PENGATURAN -->

        <a
            href="pengaturan.php"
            class="active"
        >

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >

                <circle
                    cx="12"
                    cy="12"
                    r="3"
                />

                <path
                    d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"
                />

            </svg>
            <span>
                Pengaturan
            </span>

        </a>

    </div>


    <!-- KELUAR -->

    <div class="sidebar-bottom">

        <a
            href="../logout.php"
            class="logout"
        >

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke-width="2"
            >

                <path d="M10 17l5-5-5-5"></path>

                <path d="M15 12H3"></path>

                <path d="M21 19V5a2 2 0 0 0-2-2h-5"></path>

            </svg>

            <span>
                Keluar
            </span>

        </a>

    </div>

</div>


<!-- =====================================================
     MAIN
===================================================== -->

<div class="main">


    <!-- TOPBAR -->

    <div class="topbar">


        <div class="topbar-left">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke-width="2"
            >

                <path d="M4 6h16"></path>

                <path d="M4 12h16"></path>

                <path d="M4 18h16"></path>

            </svg>

            <h2>
                Pengaturan
            </h2>

        </div>


        <div class="topbar-right">


            <!-- NOTIFICATION -->

            <div class="bell">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke-width="2"
                >

                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>

                    <path d="M13.7 21a2 2 0 0 1-3.4 0"></path>

                </svg>

                <span class="dot">
                    8
                </span>

            </div>


            <!-- USER -->

            <div class="user-chip">


                <div class="avatar">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                    >

                        <path d="M12 2l8 4v5c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6z"></path>

                    </svg>

                </div>


                <div>

                    <div class="name">

                        <?= htmlspecialchars($_SESSION["nama"]); ?>

                    </div>

                    <div class="role">

                        <?= htmlspecialchars($_SESSION["role"]); ?>

                    </div>

                </div>


                <svg
                    class="chevron"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke-width="2"
                >

                    <path d="M6 9l6 6 6-6"></path>

                </svg>

            </div>

        </div>

    </div>


    <!-- =====================================================
         CONTENT
    ===================================================== -->

    <div class="content">


        <!-- PAGE TITLE -->

        <div class="page-title">

            <h2>
                Pengaturan Akun
            </h2>

            <p>
                Kelola informasi akun Super Admin dan password.
            </p>

        </div>


        <!-- PESAN -->

        <?php if ($pesan != "") { ?>

            <div class="pesan <?= $jenis_pesan; ?>">

                <?= htmlspecialchars($pesan); ?>

            </div>

        <?php } ?>


        <!-- SETTING CARD -->

        <div class="setting-card">


            <div class="setting-head">

                <h3>
                    Informasi Akun
                </h3>

                <p>
                    Perbarui informasi akun yang digunakan untuk masuk ke sistem.
                </p>

            </div>


            <form method="POST">


                <!-- NAMA -->

                <div class="form-group">

                    <label>
                        Nama Lengkap
                    </label>

                    <input
                        type="text"
                        name="nama"
                        value="<?= htmlspecialchars($user["nama"]); ?>"
                        required
                    >

                </div>


                <!-- USERNAME -->

                <div class="form-group">

                    <label>
                        Username
                    </label>

                    <input
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($user["username"]); ?>"
                        required
                    >

                </div>


                <!-- PASSWORD -->

                <div class="password-title">
                    Ubah Password
                </div>


                <div class="password-note">
                    Kosongkan bagian password jika tidak ingin mengganti password.
                </div>


                <!-- PASSWORD LAMA -->

                <div class="form-group">

                    <label>
                        Password Lama
                    </label>

                    <div class="input-icon">

                        <input
                            type="password"
                            name="password_lama"
                            id="password_lama"
                            placeholder="Masukkan password lama"
                        >

                        <svg
                            class="toggle-eye"
                            data-target="password_lama"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>

                            <circle
                                cx="12"
                                cy="12"
                                r="3"
                            ></circle>

                        </svg>

                    </div>

                </div>


                <!-- PASSWORD BARU -->

                <div class="form-group">

                    <label>
                        Password Baru
                    </label>

                    <div class="input-icon">

                        <input
                            type="password"
                            name="password_baru"
                            id="password_baru"
                            placeholder="Masukkan password baru"
                        >

                        <svg
                            class="toggle-eye"
                            data-target="password_baru"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>

                            <circle
                                cx="12"
                                cy="12"
                                r="3"
                            ></circle>

                        </svg>

                    </div>

                </div>


                <!-- KONFIRMASI -->

                <div class="form-group">

                    <label>
                        Konfirmasi Password Baru
                    </label>

                    <div class="input-icon">

                        <input
                            type="password"
                            name="konfirmasi"
                            id="konfirmasi"
                            placeholder="Masukkan kembali password baru"
                        >

                        <svg
                            class="toggle-eye"
                            data-target="konfirmasi"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>

                            <circle
                                cx="12"
                                cy="12"
                                r="3"
                            ></circle>

                        </svg>

                    </div>

                </div>


                <!-- BUTTON -->

                <div class="form-action">

                    <button
                        type="submit"
                        name="simpan"
                        class="btn-simpan"
                    >
                        Simpan Perubahan
                    </button>

                </div>


            </form>

        </div>


        <!-- FOOTER -->

        <div class="footer-note">

            &copy;
            <?= date("Y"); ?>
            SIMO METRP. All rights reserved.

        </div>


    </div>

</div>


<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

    document
        .querySelectorAll(".toggle-eye")
        .forEach(function (eye) {

            eye.addEventListener("click", function () {

                var input = document.getElementById(
                    eye.getAttribute("data-target")
                );

                if (!input) {
                    return;
                }

                input.type =
                    input.type === "password"
                        ? "text"
                        : "password";

            });

        });

</script>


</body>

</html>
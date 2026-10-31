<?php

include "config/session.php";
include "config/koneksi.php";

$error = "";

if (isset($_POST['login'])) {

    /* trim(): buang spasi nyasar (sering ditambah keyboard HP) */
    $username = mysqli_real_escape_string(
        $koneksi,
        trim($_POST['username'])
    );

    $password = mysqli_real_escape_string(
        $koneksi,
        trim($_POST['password'])
    );

    /* Status TIDAK dicek di query, supaya akun nonaktif
       tetap ditemukan dan bisa diberi pesan yang jelas. */
    $query = mysqli_query(
        $koneksi,
        "SELECT * FROM users
         WHERE BINARY username = '$username'
         AND BINARY password = '$password'
         LIMIT 1"
    );

    if ($query && mysqli_num_rows($query) > 0) {

        $user = mysqli_fetch_assoc($query);

        $status_akun = trim($user['status']);

        if ($status_akun === 'Tidak Aktif') {

            $error = "Akun Anda telah dinonaktifkan. Silakan hubungi Super Admin.";

        } elseif ($status_akun === 'Dihapus') {

            $error = "Akun Anda tidak terdaftar. Silakan hubungi Super Admin.";

        } elseif ($status_akun !== 'Aktif') {

            $error = "Akun Anda tidak dapat digunakan.";

        } else {

            session_regenerate_id(true);

            $_SESSION['id_user']   = $user['id_user'];
            $_SESSION['nama']      = $user['nama'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['id_divisi'] = $user['id_divisi'];

            /* =====================================================
               HAPUS FOTO RIWAYAT YANG SUDAH KADALUARSA
            ===================================================== */

            $query_foto_expired = mysqli_query(
                $koneksi,
                "
                SELECT id_riwayat, foto
                FROM riwayat_pekerjaan
                WHERE foto IS NOT NULL
                AND foto != ''
                AND tanggal_hapus_foto IS NOT NULL
                AND tanggal_hapus_foto <= NOW()
                "
            );

            if ($query_foto_expired) {

                while ($foto_row = mysqli_fetch_assoc($query_foto_expired)) {

                    $nama_foto = $foto_row['foto'];

                    $file_foto =
                        __DIR__ .
                        "/uploads/bukti/" .
                        $nama_foto;

                    if (file_exists($file_foto)) {
                        unlink($file_foto);
                    }

                    mysqli_query(
                        $koneksi,
                        "
                        UPDATE riwayat_pekerjaan
                        SET
                            foto = NULL,
                            tanggal_hapus_foto = NULL
                        WHERE id_riwayat = '"
                        . intval($foto_row['id_riwayat'])
                        . "'
                        "
                    );
                }
            }

            /* =====================================================
               REDIRECT BERDASARKAN ROLE
            ===================================================== */

            if ($user['role'] == 'Super Admin') {

                header("Location: super_admin/dashboard.php");
                exit;

            } elseif ($user['role'] == 'Admin') {

                header("Location: admin_divisi/dashboard.php");
                exit;

            } elseif ($user['role'] == 'Karyawan') {

                header("Location: karyawan/dashboard.php");
                exit;

            } elseif ($user['role'] == 'Departemen') {

                header("Location: departemen/dashboard.php");
                exit;

            } else {

                $error = "Role pengguna tidak dikenali.";
            }
        }

    } else {

        /* Login gagal: cek apakah username-nya masih ada.
           Ada  -> password yang salah.
           Tidak -> akun sudah dihapus / tidak terdaftar. */
        $cek_username = mysqli_query(
            $koneksi,
            "SELECT id_user FROM users
             WHERE BINARY username = '$username'
             LIMIT 1"
        );

        if ($cek_username && mysqli_num_rows($cek_username) > 0) {

            $error = "Username atau password salah.";

        } else {

            $error = "Akun Anda tidak terdaftar. Silakan hubungi Super Admin.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Login - SIMANTAU</title>

<style>

/* =========================================================
   ROOT
========================================================= */

:root {
    --sosro-red: #a9272e;
    --sosro-dark: #821d23;
    --sosro-light: #c7444b;
    --white: #ffffff;
    --black: #202124;
    --gray-1: #f7f7f8;
    --gray-2: #eceef0;
    --gray-3: #858b93;
}

/* =========================================================
   RESET
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Segoe UI", Arial, sans-serif;
}

html,
body {
    width: 100%;
    height: 100%;
    overflow: hidden;
}

body {
    background: #f7f7f8;
}

/* =========================================================
   MAIN
========================================================= */

.login-page {
    position: relative;
    width: 100%;
    min-height: 100vh;
    overflow: hidden;
    background: #f7f7f8;
}

/* =========================================================
   BACKGROUND KANAN
========================================================= */

.right-background {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #ffffff 0%, #fafafa 60%, #f4f4f5 100%);
    z-index: 1;
}

/* =========================================================
   DEKORASI HALUS KANAN
========================================================= */

.right-decoration {
    position: absolute;
    right: -100px;
    bottom: -120px;
    width: 350px;
    height: 350px;
    opacity: .13;
    z-index: 2;
    pointer-events: none;
}

.right-decoration::before {
    content: "";
    position: absolute;
    width: 270px;
    height: 270px;
    right: 30px;
    bottom: 30px;
    border: 1px solid rgba(169, 39, 46, .28);
    border-radius: 50%;
}

.right-decoration::after {
    content: "";
    position: absolute;
    width: 185px;
    height: 185px;
    right: 72px;
    bottom: 72px;
    border: 1px solid rgba(169, 39, 46, .22);
    border-radius: 50%;
}

/* =========================================================
   PANEL KIRI
========================================================= */

.left-panel {
    position: absolute;
    top: 0;
    left: 0;
    width: 58%;
    height: 100vh;
    z-index: 5;
    overflow: hidden;
    color: white;
    background:
        linear-gradient(
            120deg,
            rgba(83, 10, 16, .91) 0%,
            rgba(125, 22, 29, .86) 50%,
            rgba(155, 39, 46, .76) 100%
        ),
        url("assets/img/bg-login.avif");
    background-size: cover, cover;
    background-position: center, center;
    background-repeat: no-repeat;
    box-shadow: 10px 0 30px rgba(0, 0, 0, .08);
    clip-path: ellipse(100% 115% at 0% 50%);
    transform: translateX(-110%);
    transition: transform 1s cubic-bezier(.65, 0, .35, 1);
}

.login-page.loaded .left-panel {
    transform: translateX(0);
}

/* =========================================================
   KONTEN KIRI
========================================================= */

.left-content {
    position: relative;
    z-index: 10;
    width: 100%;
    height: 100%;
    padding: 50px 76px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

/* =========================================================
   BRAND
========================================================= */

.brand {
    display: flex;
    align-items: center;
    gap: 17px;
    opacity: 0;
    transform: translateX(-60px);
    transition: all .8s ease;
    transition-delay: .65s;
}

.login-page.loaded .brand {
    opacity: 1;
    transform: translateX(0);
}

.brand-logo {
    width: 70px;
    height: 70px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.brand-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.brand-name {
    font-family: Georgia, "Times New Roman", serif;
    font-size: 26px;
    line-height: 1.18;
    letter-spacing: .3px;
}

.brand-company {
    margin-top: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, .82);
}

.brand-company strong {
    color: #fff;
}

/* =========================================================
   HERO KIRI
========================================================= */

.left-middle {
    max-width: 560px;
    margin-top: -10px;
    opacity: 0;
    transform: translateX(-80px);
    transition: all .9s ease;
    transition-delay: .85s;
}

.login-page.loaded .left-middle {
    opacity: 1;
    transform: translateX(0);
}

.eyebrow {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 4px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, .76);
    margin-bottom: 14px;
}

.left-middle h1 {
    font-family: Georgia, "Times New Roman", serif;
    font-size: clamp(42px, 4vw, 61px);
    font-weight: 500;
    line-height: 1.08;
    margin-bottom: 23px;
}

.hero-line {
    width: 58px;
    height: 3px;
    background: white;
    margin-bottom: 20px;
}

.left-middle p {
    max-width: 510px;
    font-size: 14.5px;
    line-height: 1.75;
    color: rgba(255, 255, 255, .86);
}

/* =========================================================
   FEATURES
========================================================= */

.features {
    display: flex;
    gap: 55px;
    margin-top: 35px;
    opacity: 0;
    transform: translateY(30px);
    transition: all .8s ease;
    transition-delay: 1.05s;
}

.login-page.loaded .features {
    opacity: 1;
    transform: translateY(0);
}

.feature {
    min-width: 105px;
}

.feature-icon {
    width: 28px;
    height: 28px;
    margin-bottom: 10px;
    stroke: #fff;
}

.feature strong {
    display: block;
    font-size: 13.5px;
    margin-bottom: 4px;
}

.feature span {
    display: block;
    font-size: 10.5px;
    line-height: 1.5;
    color: rgba(255, 255, 255, .72);
}

/* =========================================================
   FOOTER KIRI
========================================================= */

.left-footer {
    font-size: 9.5px;
    letter-spacing: 1.2px;
    color: rgba(255, 255, 255, .72);
    opacity: 0;
    transform: translateX(-40px);
    transition: all .8s ease;
    transition-delay: 1.25s;
}

.login-page.loaded .left-footer {
    opacity: 1;
    transform: translateX(0);
}

.left-footer strong {
    color: white;
}

/* =========================================================
   AREA LOGIN
========================================================= */

.login-area {
    position: absolute;
    top: 0;
    right: 0;
    width: 44%;
    height: 100vh;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 45px;
}

/* =========================================================
   LABEL SIMANTAU
========================================================= */

.system-label {
    position: absolute;
    top: 45px;
    right: 55px;
    display: flex;
    align-items: flex-start;
    gap: 11px;
    color: #92979e;
    font-size: 9.5px;
    letter-spacing: 1.8px;
    text-align: right;
    opacity: 0;
    transform: translateY(-15px);
    transition: all .7s ease;
    transition-delay: 1.2s;
}

.login-page.loaded .system-label {
    opacity: 1;
    transform: translateY(0);
}

.system-label .line {
    width: 30px;
    height: 2px;
    margin-top: 6px;
    background: var(--sosro-red);
}

.system-label strong {
    display: block;
    color: #33383e;
    font-size: 11px;
    margin-top: 4px;
    letter-spacing: 1.5px;
}

/* =========================================================
   LOGIN CARD
========================================================= */

.login-card {
    position: relative;
    width: 100%;
    max-width: 450px;
    background: rgba(255, 255, 255, .97);
    border: 1px solid rgba(0, 0, 0, .035);
    border-radius: 21px;
    padding: 42px 40px;
    box-shadow: 0 22px 55px rgba(0, 0, 0, .10);
    opacity: 0;
    transform: scale(.82) translateY(25px);
    transition: all .65s cubic-bezier(.34, 1.56, .64, 1);
    transition-delay: .95s;
}

.login-page.loaded .login-card {
    opacity: 1;
    transform: scale(1) translateY(0);
}

/* =========================================================
   HEADER LOGIN
========================================================= */

.login-header {
    text-align: center;
    margin-bottom: 27px;
}

.login-header .welcome {
    color: #9aa0a7;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    margin-bottom: 10px;
}

/* Judul SIMANTAU: lebih ramping, berjarak, dan bergradasi merah */
.login-header h2 {
    display: inline-block;
    font-size: 30px;
    line-height: 1.15;
    font-weight: 800;
    letter-spacing: 8px;
    padding-left: 8px; /* mengimbangi letter-spacing supaya tetap di tengah */
    background: linear-gradient(135deg, var(--sosro-light) 0%, var(--sosro-red) 45%, var(--sosro-dark) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    color: var(--sosro-red); /* cadangan kalau browser tidak mendukung */
}

.login-header p {
    max-width: 290px;
    margin: 8px auto 0;
    color: #858b93;
    font-size: 12px;
    line-height: 1.55;
    letter-spacing: .2px;
}

/* Garis pemisah: garis pendek dengan titik di tengah */
.login-line {
    position: relative;
    width: 46px;
    height: 3px;
    margin: 16px auto 0;
    border-radius: 3px;
    background: linear-gradient(90deg, transparent, var(--sosro-red), transparent);
}

/* =========================================================
   ERROR
========================================================= */

.error-message {
    background: #fff1f2;
    border: 1px solid #efc9cc;
    color: #a32128;
    padding: 10px 13px;
    border-radius: 8px;
    text-align: center;
    font-size: 12px;
    margin-bottom: 15px;
}

/* =========================================================
   INPUT
========================================================= */

.form-group {
    margin-bottom: 15px;
}

.input-box {
    position: relative;
}

.input-box > svg {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    stroke: #858c94;
    pointer-events: none;
}

.input-box input {
    width: 100%;
    height: 53px;
    border: 1px solid #dfe2e5;
    border-radius: 10px;
    background: #fafafa;
    padding: 0 48px;
    outline: none;
    color: #333;
    font-size: 13.5px;
    transition: .2s ease;
}

.input-box input:focus {
    background: white;
    border-color: var(--sosro-red);
    box-shadow: 0 0 0 3px rgba(169, 39, 46, .08);
}

.input-box input::placeholder {
    color: #9298a0;
}

/* =========================================================
   EYE
========================================================= */

.eye-button {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 35px;
    height: 35px;
    border: none;
    background: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.eye-button svg {
    width: 18px;
    height: 18px;
    stroke: #858b93;
}

.eye-button:hover svg {
    stroke: var(--sosro-red);
}

/* =========================================================
   OPTIONS
========================================================= */

.login-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 5px 0 20px;
}

.remember {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: #777;
}

.remember input {
    width: 16px;
    height: 16px;
    accent-color: var(--sosro-red);
}

.forgot {
    color: var(--sosro-red);
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.forgot:hover {
    text-decoration: underline;
}

/* =========================================================
   BUTTON
========================================================= */

.login-button {
    width: 100%;
    height: 52px;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--sosro-red), var(--sosro-dark));
    color: white;
    font-size: 14.5px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    box-shadow: 0 8px 18px rgba(169, 39, 46, .18);
    transition: .2s ease;
}

.login-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 11px 22px rgba(169, 39, 46, .24);
}

.login-button:active {
    transform: translateY(0);
}

.login-button .arrow {
    font-size: 18px;
}

/* =========================================================
   FOOTER CARD
========================================================= */

.login-footer {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 23px;
}

.login-footer .line {
    flex: 1;
    height: 1px;
    background: #e6e6e6;
}

.login-footer span {
    color: #9ba0a6;
    font-size: 7.5px;
    letter-spacing: 1.2px;
    white-space: nowrap;
}

/* =========================================================
   FOOTER KANAN
========================================================= */

.right-footer {
    position: absolute;
    right: 55px;
    bottom: 36px;
    display: flex;
    align-items: center;
    gap: 11px;
    color: #9ba0a6;
    font-size: 8.5px;
    letter-spacing: 1px;
    text-align: right;
    opacity: 0;
    transform: translateY(15px);
    transition: all .7s ease;
    transition-delay: 1.35s;
}

.login-page.loaded .right-footer {
    opacity: 1;
    transform: translateY(0);
}

.right-footer .line {
    width: 30px;
    height: 2px;
    background: var(--sosro-red);
}

.right-footer strong {
    color: #656b73;
}

/* =========================================================
   TABLET
========================================================= */

@media (max-width: 1050px) {

    .left-panel {
        width: 56%;
    }

    .login-area {
        width: 44%;
        padding: 25px;
    }

    .left-content {
        padding: 40px 55px;
    }

    .brand-name {
        font-size: 22px;
    }

    .brand-logo {
        width: 62px;
        height: 62px;
    }

    .left-middle h1 {
        font-size: 43px;
    }

    .features {
        gap: 28px;
    }
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {

    html,
    body {
        overflow: auto;
    }

    .login-page {
        min-height: 100vh;
        overflow: hidden;
    }

    .left-panel {
        position: absolute;
        width: 100%;
        height: 295px;
        clip-path: none;
        border-radius: 0 0 48% 0;
        transform: translateY(-110%);
        transition: transform 1s cubic-bezier(.65, 0, .35, 1);
    }

    .login-page.loaded .left-panel {
        transform: translateY(0);
    }

    .left-content {
        padding: 25px;
    }

    .brand {
        gap: 10px;
    }

    .brand-logo {
        width: 54px;
        height: 54px;
    }

    .brand-name {
        font-size: 18px;
    }

    .brand-company {
        font-size: 10px;
    }

    .left-middle {
        margin-top: 0;
    }

    .eyebrow {
        font-size: 8px;
        letter-spacing: 2.5px;
        margin-bottom: 6px;
    }

    .left-middle h1 {
        font-size: 27px;
        margin-bottom: 10px;
    }

    .hero-line {
        width: 35px;
        height: 2px;
        margin-bottom: 9px;
    }

    .left-middle p,
    .features,
    .left-footer {
        display: none;
    }

    .login-area {
        position: relative;
        width: 100%;
        height: auto;
        min-height: 100vh;
        padding: 325px 20px 30px;
        align-items: flex-start;
    }

    .system-label {
        display: none;
    }

    .login-card {
        max-width: 440px;
        padding: 32px 26px;
        border-radius: 18px;
    }

    .login-header {
        margin-bottom: 23px;
    }

    .login-header h2 {
        font-size: 26px;
        letter-spacing: 7px;
        padding-left: 7px;
    }

    .right-footer {
        display: none;
    }
}

/* =========================================================
   HP KECIL
========================================================= */

@media (max-width: 420px) {

    .left-panel {
        height: 255px;
        border-radius: 0 0 45% 0;
    }

    .left-content {
        padding: 20px;
    }

    .brand-logo {
        width: 47px;
        height: 47px;
    }

    .brand-name {
        font-size: 16px;
    }

    .brand-company {
        font-size: 9px;
    }

    .left-middle h1 {
        font-size: 23px;
    }

    .login-area {
        padding: 285px 15px 25px;
    }

    .login-card {
        padding: 27px 20px;
    }

    .login-header h2 {
        font-size: 23px;
        letter-spacing: 6px;
        padding-left: 6px;
    }

    .input-box input {
        height: 51px;
    }

    .forgot,
    .remember {
        font-size: 11px;
    }
}

/* =========================================================
   REDUCE MOTION
========================================================= */

@media (prefers-reduced-motion: reduce) {

    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}

/* =========================================================
   HENTIKAN ANIMASI SAAT LOGIN GAGAL / ADA PESAN
========================================================= */

.login-page.no-animation *,
.login-page.no-animation .left-panel,
.login-page.no-animation .brand,
.login-page.no-animation .left-middle,
.login-page.no-animation .features,
.login-page.no-animation .left-footer,
.login-page.no-animation .system-label,
.login-page.no-animation .login-card,
.login-page.no-animation .right-footer {
    animation: none !important;
    transition: none !important;
    opacity: 1 !important;
}

</style>

</head>

<body>

<div
    id="loginPage"
    class="login-page<?= $error != "" ? " loaded no-animation" : "" ?>"
>

    <!-- BACKGROUND -->
    <div class="right-background"></div>
    <div class="right-decoration"></div>

    <!-- =====================================================
         PANEL KIRI
    ====================================================== -->

    <section class="left-panel">

        <div class="left-content">

            <!-- BRAND -->
            <div class="brand">

                <div class="brand-logo">
                    <img
                        src="assets/img/logo-login.jpeg"
                        alt="Logo Sinar Sosro Gunung Slamat"
                    >
                </div>

                <div>

                    <div class="brand-name">
                        SINAR SOSRO
                        <br>
                        GUNUNG SLAMAT
                    </div>

                    <div class="brand-company">
                        a
                        <strong>REKSO</strong>
                        Company
                    </div>

                </div>

            </div>

            <!-- HERO -->
            <div class="left-middle">

                <div class="eyebrow">
                    Tradisi Kualitas
                </div>

                <h1>
                    Tradisi Kualitas
                    <br>
                    untuk Masa Depan
                </h1>

                <div class="hero-line"></div>

                <p>
                    Bersama, menjaga kinerja lebih baik
                    melalui inovasi, integritas, dan
                    komitmen berkelanjutan.
                </p>

                <!-- FEATURES -->
                <div class="features">

                    <!-- INTEGRITAS -->
                    <div class="feature">

                        <svg
                            class="feature-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="9" cy="8" r="3"></circle>
                            <path d="M2.5 20c0-3.5 2.8-5.5 6.5-5.5s6.5 2 6.5 5.5"></path>
                            <circle cx="18" cy="9" r="2.5"></circle>
                            <path d="M15.7 14.8c2.8.4 4.8 2.3 4.8 5.2"></path>
                        </svg>

                        <strong>Integritas</strong>
                        <span>Dalam setiap langkah</span>

                    </div>

                    <!-- INOVASI -->
                    <div class="feature">

                        <svg
                            class="feature-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M4 20V10"></path>
                            <path d="M10 20V4"></path>
                            <path d="M16 20v-7"></path>
                            <path d="M22 20v-3"></path>
                        </svg>

                        <strong>Inovasi</strong>
                        <span>Untuk hasil lebih baik</span>

                    </div>

                    <!-- KEBERLANJUTAN -->
                    <div class="feature">

                        <svg
                            class="feature-icon"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 20 3c-.4 6-1 9.8-4 13-1.6 1.6-3 2-5 4z"></path>
                            <path d="M2 21c0-3 1.85-5.36 5.08-6"></path>
                        </svg>

                        <strong>Keberlanjutan</strong>
                        <span>Untuk generasi mendatang</span>

                    </div>

                </div>

            </div>

            <!-- FOOTER -->
            <div class="left-footer">

                <strong>SINAR SOSRO GUNUNG SLAMAT</strong>

                &nbsp; | &nbsp;

                a REKSO Company

            </div>

        </div>

    </section>

    <!-- =====================================================
         LOGIN AREA
    ====================================================== -->

    <main class="login-area">

        <!-- SYSTEM LABEL -->
        <div class="system-label">

            <div class="line"></div>

            <div>
                SISTEM INFORMASI MONITORING DAN PENUGASAN
                <strong>SIMANTAU</strong>
            </div>

        </div>

        <!-- LOGIN CARD -->
        <div class="login-card">

            <!-- HEADER -->
            <div class="login-header">

                <div class="welcome">
                    Selamat Datang di
                </div>

                <h2>SIMANTAU</h2>

                <p>Sistem Informasi Monitoring dan Penugasan</p>

                <div class="login-line"></div>

            </div>

            <!-- ERROR -->
            <?php if ($error != "") { ?>

                <div class="error-message">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>

            <?php } ?>

            <!-- FORM -->
            <form method="POST" autocomplete="off">

                <!-- USERNAME -->
                <div class="form-group">

                    <div class="input-box">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <circle cx="12" cy="8" r="4"></circle>
                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                        </svg>

                        <input
                            type="text"
                            name="username"
                            placeholder="Username"
                            autocomplete="username"
                            autocapitalize="off"
                            autocorrect="off"
                            spellcheck="false"
                            required
                        >

                    </div>

                </div>

                <!-- PASSWORD -->
                <div class="form-group">

                    <div class="input-box">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <rect x="5" y="10" width="14" height="11" rx="2"></rect>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        </svg>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            placeholder="Password"
                            autocomplete="current-password"
                            autocapitalize="off"
                            autocorrect="off"
                            spellcheck="false"
                            required
                        >

                        <button
                            type="button"
                            class="eye-button"
                            onclick="togglePassword()"
                            aria-label="Tampilkan password"
                        >

                            <svg
                                id="eyeIcon"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path>
                                <circle cx="12" cy="12" r="2.5"></circle>
                            </svg>

                        </button>

                    </div>

                </div>

                <!-- OPTIONS -->
                <div class="login-options">

                    <label class="remember">
                        <input type="checkbox" name="remember">
                        <span>Ingat saya</span>
                    </label>

                    <a
                        href="#"
                        class="forgot"
                        onclick="return false;"
                    >
                        Lupa password?
                    </a>

                </div>

                <!-- BUTTON -->
                <button
                    type="submit"
                    name="login"
                    class="login-button"
                >
                    <span>Masuk</span>
                </button>

            </form>

            <!-- FOOTER CARD -->
            <div class="login-footer">

                <span class="line"></span>

                <span>
                    SATU LANGKAH UNTUK KINERJA
                    YANG LEBIH BAIK
                </span>

                <span class="line"></span>

            </div>

        </div>

        <!-- FOOTER KANAN -->
        <div class="right-footer">

            <div class="line"></div>

            <div>
                SINAR SOSRO GUNUNG SLAMAT
                <br>
                a
                <strong>REKSO</strong>
                Company
            </div>

        </div>

    </main>

</div>

<script>

/* =========================================================
   ANIMASI LOGIN
========================================================= */

window.addEventListener("load", function () {
    document.getElementById("loginPage").classList.add("loaded");
});

/* =========================================================
   SHOW / HIDE PASSWORD
========================================================= */

function togglePassword() {

    const password = document.getElementById("password");
    const eyeIcon = document.getElementById("eyeIcon");

    if (password.type === "password") {

        password.type = "text";

        eyeIcon.innerHTML = `
            <path d="M3 3l18 18"></path>
            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>
            <path d="M9.9 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3.1 3.9"></path>
            <path d="M6.1 6.1C3.6 8 2 12 2 12s3.5 7 10 7c1.5 0 2.8-.3 4-.8"></path>
        `;

    } else {

        password.type = "password";

        eyeIcon.innerHTML = `
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path>
            <circle cx="12" cy="12" r="2.5"></circle>
        `;

    }

}

</script>

</body>

</html>
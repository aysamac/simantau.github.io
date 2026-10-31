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

$id_karyawan = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id_karyawan <= 0) {
    header("Location: pengguna.php");
    exit;
}

$query = "
    SELECT
        u.id_user,
        u.nama,
        u.username,
        u.status,
        u.role,
        u.id_divisi,
        d.nama_divisi
    FROM users u
    LEFT JOIN divisi d
        ON u.id_divisi = d.id_divisi
    WHERE u.id_user = ?
    AND u.role = 'Karyawan'
    LIMIT 1
";

$stmt = mysqli_prepare($koneksi, $query);

if (!$stmt) {
    die("Query Detail Karyawan gagal: " . mysqli_error($koneksi));
}

mysqli_stmt_bind_param($stmt, "i", $id_karyawan);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$karyawan = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$karyawan) {
    header("Location: pengguna.php");
    exit;
}

$status_aktif = trim($karyawan["status"] ?? "") === "Aktif";

$nama_karyawan = $karyawan["nama"] ?? "-";
$username_karyawan = $karyawan["username"] ?? "-";
$divisi_karyawan = $karyawan["nama_divisi"] ?? "-";
$role_karyawan = $karyawan["role"] ?? "Karyawan";
$status_karyawan = $karyawan["status"] ?? "-";

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Detail Karyawan - SIMO</title>

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

        a {
            text-decoration: none;
            color: inherit;
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
            background: #fff1f2;
            color: #a8212b;
            font-size: 14px;
            font-weight: 600;
            text-align: left;
            cursor: default;
            margin-bottom: 4px;
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
            background: #ffffff;
            color: #344054;
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

        .status-badge.nonaktif {
            background: #eeeeef;
            color: #999fa7;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #43a05f;
        }

        .detail-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 22px;
            padding-top: 2px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .btn-back:hover {
            background: #96272e;
        }

        .detail-note {
            margin-top: 20px;
            padding-top: 17px;
            border-top: 1px solid #e4e8ed;
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
            }

            .settings-nav {
                border-right: 0;
                border-bottom: 1px solid #e2e6eb;
                padding: 0 0 12px;
                margin-bottom: 20px;
            }

            .settings-nav-title {
                display: none;
            }

            .settings-content {
                padding: 0;
            }
        }

        @media (max-width: 620px) {
            .profile-info-grid {
                grid-template-columns: 1fr;
            }
        }
    body.dark-mode .field-value,body.dark-mode .field-value.readonly{background:#20242a!important;color:#f1f3f5!important;border-color:#343942!important;box-shadow:none!important}
body.dark-mode .field-label{color:#aeb7c5!important}
body.dark-mode .settings-card,body.dark-mode .settings-content{background:#181b20!important;color:#eef1f5!important;border-color:#343942!important}
body.dark-mode .settings-content{border-left-color:#343942!important}
body.dark-mode .panel-header{border-bottom-color:#343942!important}
body.dark-mode .panel-header h2{color:#f1f3f5!important}
body.dark-mode .panel-header p{color:#929baa!important}
body.dark-mode .role-badge{background:#302126!important;color:#f07a80!important;border-color:#51363b!important}
body.dark-mode .status-badge{background:#243329!important;color:#62d174!important;border-color:#34523b!important}
body.dark-mode .status-badge.nonaktif{background:#302326!important;color:#f08a90!important;border-color:#493338!important}
body.dark-mode .detail-note{color:#929baa!important;border-top-color:#343942!important}
body.dark-mode .btn-back{background:#b51f2a!important;color:#fff!important}
body.dark-mode .settings-nav{border-right-color:#343942!important}
body.dark-mode .settings-nav-title{color:#929baa!important}
body.dark-mode .settings-link:not(.active){color:#f1f3f5!important;background:transparent!important}
body.dark-mode .settings-link.active{background:#302126!important;color:#f07a80!important}
html.dark-mode,body.dark-mode{background:#181b20!important;color:#eef1f5!important}
body.dark-mode .main,body.dark-mode .content{background:#181b20!important;color:#eef1f5!important}
body.dark-mode .page-title h1{color:#f1f3f5!important}
body.dark-mode .page-title p{color:#929baa!important}
body.dark-mode .settings-card{background:#181b20!important}
body.dark-mode .settings-nav{background:#181b20!important;border-right-color:#343942!important}
body.dark-mode .settings-nav-title{color:#929baa!important}
body.dark-mode .settings-link:not(.active){background:transparent!important;color:#f1f3f5!important}
body.dark-mode .settings-link.active{background:#302126!important;color:#f07a80!important}
body.dark-mode .settings-content{background:#1d2025!important;color:#eef1f5!important;border-left-color:#343942!important}
body.dark-mode .panel-header{border-bottom-color:#343942!important}
body.dark-mode .panel-header h2{color:#f1f3f5!important}
body.dark-mode .panel-header p{color:#929baa!important}
body.dark-mode .field-label{color:#aeb7c5!important}
body.dark-mode .field-value,body.dark-mode .field-value.readonly{background:#20242a!important;color:#f1f3f5!important;border-color:#343942!important;box-shadow:none!important}
body.dark-mode .role-badge{background:#302126!important;color:#f07a80!important;border-color:#51363b!important}
body.dark-mode .status-badge{background:#243329!important;color:#62d174!important;border-color:#34523b!important}
body.dark-mode .status-badge.nonaktif{background:#302326!important;color:#f08a90!important;border-color:#493338!important}
body.dark-mode .detail-note{color:#929baa!important;border-top-color:#343942!important}
body.dark-mode .btn-back{background:#b51f2a!important;color:#fff!important}
body.dark-mode .footer{background:#181b20!important;color:#858e9d!important}
body.dark-mode .settings-card{background:transparent!important;border:none!important;box-shadow:none!important}
body.dark-mode .settings-content{background:transparent!important;border-left-color:#343942!important}
body.dark-mode .panel-header{background:transparent!important}
</style>
</head>
<body>

    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">

            <div class="page-title">
                <h1>Detail Karyawan</h1>
                <p>Informasi data karyawan.</p>
            </div>

            <div class="settings-card">

                <aside class="settings-nav">
                    <div class="settings-nav-title">
                        Pengaturan
                    </div>

                    <div class="settings-link">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="8" r="3.5"></circle>
                            <path d="M5 20c0-3.4 2.8-6 7-6s7 2.6 7 6"></path>
                        </svg>
                        <span>Profil</span>
                    </div>
                </aside>

                <section class="settings-content">

                    <div class="panel-header">
                        <h2>Detail Karyawan</h2>
                        <p>Informasi pengguna yang ditampilkan pada sistem SIMO.</p>
                    </div>

                    <div class="profile-info-grid">

                        <div class="field">
                            <span class="field-label">Nama Lengkap</span>
                            <div class="field-value readonly">
                                <?= htmlspecialchars($nama_karyawan, ENT_QUOTES, "UTF-8"); ?>
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">Username</span>
                            <div class="field-value readonly">
                                <?= htmlspecialchars($username_karyawan, ENT_QUOTES, "UTF-8"); ?>
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">Divisi</span>
                            <div class="field-value readonly">
                                <?= htmlspecialchars($divisi_karyawan, ENT_QUOTES, "UTF-8"); ?>
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">Role</span>
                            <div class="field-value readonly">
                                <span class="role-badge">
                                    <?= htmlspecialchars($role_karyawan, ENT_QUOTES, "UTF-8"); ?>
                                </span>
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">Status Akun</span>
                            <div class="field-value readonly">
                                <?php if ($status_aktif): ?>
                                    <span class="status-badge">
                                        <span class="status-dot"></span>
                                        Aktif
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge nonaktif">
                                        <?= htmlspecialchars($status_karyawan, ENT_QUOTES, "UTF-8"); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                    <div class="detail-actions">
                        <a href="pengguna.php" class="btn-back">
                            ←&nbsp; Kembali
                        </a>
                    </div>

                    <div class="detail-note">
                        Data karyawan ditampilkan berdasarkan akun yang dipilih dari menu Pengguna.
                    </div>

                </section>
            </div>

            <div class="footer">
                © <?= date("Y"); ?> SIMO METRP. All rights reserved.
            </div>

        </div>
    </div>

</body>
</html>

<?php
include "config/session.php";

if (!isset($_SESSION["id_user"])) {
    header("Location: index.php");
    exit;
}

include "config/koneksi.php";

$id_user = (int) $_SESSION["id_user"];

$query_user = mysqli_query(
    $koneksi,
    "SELECT u.id_user, u.nama, u.role, u.id_divisi, d.nama_divisi
    FROM users u
    LEFT JOIN divisi d ON u.id_divisi = d.id_divisi
    WHERE u.id_user='$id_user'
    LIMIT 1"
);

if (!$query_user || mysqli_num_rows($query_user) == 0) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$user = mysqli_fetch_assoc($query_user);
$nama_user = $user["nama"];
$role_user = $user["role"];
$id_divisi = (int) ($user["id_divisi"] ?? 0);
$nama_divisi = $user["nama_divisi"] ?? "-";

/* Variabel yang biasanya dipakai topbar di masing-masing dashboard */
$id_admin = $id_user;
$id_karyawan = $id_user;
$nama_admin = $nama_user;
$nama_karyawan = $nama_user;

/* Folder sesuai role (topbar & halaman tujuan) */
if ($role_user == "Admin") {
    $folder_role = "admin_divisi";
} elseif ($role_user == "Super Admin") {
    $folder_role = "super_admin";
} else {
    $folder_role = "karyawan";
}

/* Dipakai topbar: false = tombol garis 3 (menu sidebar) tidak ditampilkan */
$tampil_menu_sidebar = false;

/* Dipakai topbar: false = menu/ikon notifikasi tidak ditampilkan */
$tampil_notifikasi = false;

if (isset($_GET["baca"]) && is_numeric($_GET["baca"])) {
    $id_notifikasi = (int) $_GET["baca"];

    $query_cek_notif = mysqli_query(
        $koneksi,
        "SELECT id_notifikasi, status_baca
        FROM notifikasi
        WHERE id_notifikasi='$id_notifikasi'
        AND id_user='$id_user'
        LIMIT 1"
    );

    if ($query_cek_notif && mysqli_num_rows($query_cek_notif) > 0) {
        $notif_dipilih = mysqli_fetch_assoc($query_cek_notif);

        if ($notif_dipilih["status_baca"] == "Belum Dibaca") {
            mysqli_query(
                $koneksi,
                "UPDATE notifikasi
                SET status_baca='Sudah Dibaca'
                WHERE id_notifikasi='$id_notifikasi'
                AND id_user='$id_user'"
            );
        }

        if ($role_user == "Admin") {
            header("Location: admin_divisi/monitoring.php");
            exit;
        }

        if ($role_user == "Super Admin") {
            header("Location: super_admin/monitoring.php");
            exit;
        }

        if ($role_user == "Karyawan") {
            header("Location: karyawan/tugas.php");
            exit;
        }
    }
}

$notifikasi_list = [];

$query_notifikasi = mysqli_query(
    $koneksi,
    "SELECT id_notifikasi, pesan, status_baca, tanggal
    FROM notifikasi
    WHERE id_user='$id_user'
    ORDER BY tanggal DESC"
);

if ($query_notifikasi) {
    while ($notif = mysqli_fetch_assoc($query_notifikasi)) {
        $notifikasi_list[] = $notif;
    }
}

$jumlah_belum_dibaca = 0;

$query_belum_dibaca = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
    FROM notifikasi
    WHERE id_user='$id_user'
    AND status_baca='Belum Dibaca'"
);

if ($query_belum_dibaca) {
    $data_belum_dibaca = mysqli_fetch_assoc($query_belum_dibaca);
    $jumlah_belum_dibaca = (int) ($data_belum_dibaca["total"] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<!-- Topbar ditulis untuk berada di dalam folder role, jadi link relatifnya
     (profil, logout, dll.) diarahkan ke folder role. Link halaman ini
     memakai path "../" supaya tetap benar. -->
<base href="<?= htmlspecialchars($folder_role, ENT_QUOTES, 'UTF-8'); ?>/">
<title>Notifikasi - SIMO</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:#f2f5f9;color:#1c2636}
a{text-decoration:none}

.layout{min-height:100vh;width:100%}
.main{margin-left:0;width:100%;min-width:0;min-height:100vh}
.content{padding:28px 32px 40px}
.container{max-width:1000px;margin:0 auto}
.notification-message{word-break:break-word;overflow-wrap:anywhere}

.page-header{display:flex;align-items:center;justify-content:space-between;gap:14px;background:#fff;border-radius:13px;padding:22px 26px;margin-bottom:20px;border:1px solid #edf0f4;box-shadow:0 2px 10px rgba(20,40,70,.04)}
.page-header h1{margin:0 0 7px;font-size:21px;color:#1c2636}
.page-header p{margin:0;font-size:13px;color:#7c8796}
.btn-kembali{flex-shrink:0;display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border:1px solid #d9dee5;border-radius:8px;background:#fff;color:#475467;font-size:12.5px;font-weight:600;transition:background .15s ease}
.btn-kembali:hover{background:#f5f6f8}
.btn-kembali svg{width:15px;height:15px}

.summary{display:flex;align-items:center;justify-content:space-between;background:#fff;border-radius:13px;padding:17px 20px;margin-bottom:15px;border:1px solid #edf0f4}
.summary-title{font-size:13px;color:#526176}
.summary-count{font-size:13px;font-weight:700;color:#b51f2a}
.notification-list{background:#fff;border-radius:13px;border:1px solid #edf0f4;overflow:hidden;box-shadow:0 2px 10px rgba(20,40,70,.04)}
.notification-item{display:flex;align-items:flex-start;gap:15px;padding:19px 20px;border-bottom:1px solid #edf0f4;background:#fff;transition:background .15s ease;color:inherit}
.notification-item:last-child{border-bottom:none}
.notification-item.unread{background:#fff4f4;border-left:4px solid #b51f2a}
.notification-item:hover{background:#f8f9fb}
.notification-item.unread:hover{background:#ffeded}
.notification-icon{width:42px;height:42px;min-width:42px;border-radius:50%;display:flex;align-items:center;justify-content:center}
.notification-icon svg{width:19px;height:19px}
.notification-icon.repair{background:#fde3e3;color:#b51f2a}
.notification-icon.new{background:#e4efff;color:#2675d8}
.notification-icon.success{background:#e2f5e8;color:#168044}
.notification-icon.waiting{background:#fff0d8;color:#c77b00}
.notification-icon.default{background:#f4e8e9;color:#b51f2a}
.notification-content{flex:1;min-width:0}
.notification-message{margin:0 0 7px;font-size:13px;line-height:1.5;color:#394555;font-weight:500}
.notification-time{font-size:10.5px;color:#929baa}
.notification-label{display:inline-flex;align-items:center;padding:4px 10px;margin-left:8px;border-radius:20px;font-size:9.5px;font-weight:700;vertical-align:middle}
.notification-label.repair{background:#f9d4d4;color:#b51f2a}
.notification-label.new{background:#dcecff;color:#2675d8}
.notification-label.success{background:#d9f0e0;color:#168044}
.notification-label.waiting{background:#ffebc7;color:#a86600}
.notification-label.default{background:#eeeef0;color:#626b78}
.unread-label{display:inline-block;margin-left:7px;padding:4px 9px;border-radius:20px;background:#b51f2a;color:#fff;font-size:9px;font-weight:600;vertical-align:middle}
.empty{padding:55px 20px;text-align:center;color:#929baa;font-size:12px}

/* ===== RESPONSIVE ===== */
@media(max-width:850px){
.content{padding:20px}
}
@media(max-width:700px){
.page-header{padding:16px}
.page-header h1{font-size:18px;margin-bottom:4px}
.page-header p{font-size:12px}
.btn-kembali{height:34px;padding:0 12px;font-size:12px}
.summary{padding:14px 16px}
.notification-item{padding:16px 14px;gap:12px}
.notification-icon{width:36px;height:36px;min-width:36px}
.notification-icon svg{width:17px;height:17px}
.notification-label,.unread-label{margin-left:0;margin-right:5px;margin-top:6px}
.notification-message{font-size:12.5px}
}
@media(max-width:420px){
.content{padding:14px 12px 28px}
.page-header{padding:16px 14px}
.notification-item.unread{border-left-width:3px}
}

/* Cadangan: sembunyikan menu notifikasi di topbar
   (cara bersih: bungkus menu itu dengan if $tampil_notifikasi di topbar.php) */
.notif-topbar-hidden{display:none!important}

/* Cadangan: sembunyikan tombol garis 3 (menu sidebar) di topbar
   (cara bersih: bungkus tombolnya dengan if $tampil_menu_sidebar di topbar.php) */
.menu-toggle,.menu-btn,.menu-button,.hamburger,.hamburger-btn,.sidebar-toggle,.toggle-sidebar,.nav-toggle,
#menuToggle,#menuBtn,#hamburger,#sidebarToggle,#toggleSidebar,#openSidebar{display:none!important}

/* DARK MODE (warna disamakan dengan dashboard) */
body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
body.dark-mode .layout,body.dark-mode .main,body.dark-mode .content{background:#0f141b!important}
body.dark-mode .page-header,body.dark-mode .summary,body.dark-mode .notification-list{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
body.dark-mode .page-header h1{color:#edf2f7!important}
body.dark-mode .page-header p{color:#8f9bac!important}
body.dark-mode .btn-kembali{background:#202733!important;border-color:#2c3542!important;color:#cbd2dc!important}
body.dark-mode .btn-kembali:hover{background:#28303d!important}
body.dark-mode .summary-title{color:#c8ced7!important}
body.dark-mode .summary-count{color:#ff7b84!important}
body.dark-mode .notification-item{background:#151b23!important;border-bottom-color:#2c3542!important}
body.dark-mode .notification-item:hover{background:#202733!important}
body.dark-mode .notification-item.unread{background:#2a1f22!important;border-left-color:#b51f2a!important}
body.dark-mode .notification-item.unread:hover{background:#34252a!important}
body.dark-mode .notification-message{color:#e2e6ec!important}
body.dark-mode .notification-time{color:#8f9bac!important}
body.dark-mode .notification-icon.repair{background:#42272b!important;color:#ff7b84!important}
body.dark-mode .notification-icon.new{background:#24344d!important;color:#79aef5!important}
body.dark-mode .notification-icon.success{background:#213b2b!important;color:#83d29b!important}
body.dark-mode .notification-icon.waiting{background:#443721!important;color:#e0aa57!important}
body.dark-mode .notification-icon.default{background:#3a2a2d!important;color:#ff8b92!important}
body.dark-mode .notification-label.repair{background:#492a2e!important;color:#ff8b92!important}
body.dark-mode .notification-label.new{background:#273a54!important;color:#8db9f7!important}
body.dark-mode .notification-label.success{background:#24432f!important;color:#8fd3a5!important}
body.dark-mode .notification-label.waiting{background:#493a22!important;color:#e3b05f!important}
body.dark-mode .notification-label.default{background:#34373d!important;color:#c8ced7!important}
body.dark-mode .unread-label{background:#b51f2a!important;color:#fff!important}
body.dark-mode .empty{color:#8f9bac!important}
</style>
</head>
<body>

<div class="layout">

<main class="main">

<?php include "includes/" . $folder_role . "/topbar.php"; ?>

<div class="content">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Notifikasi</h1>
                <p>Informasi dan pemberitahuan terbaru dari sistem SIMO.</p>
            </div>
            <a href="dashboard.php" class="btn-kembali">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
                Kembali
            </a>
        </div>

        <div class="summary">
            <div class="summary-title">Belum Dibaca</div>
            <div class="summary-count"><?= $jumlah_belum_dibaca; ?> notifikasi</div>
        </div>

        <div class="notification-list">
            <?php if (!empty($notifikasi_list)): ?>
                <?php foreach ($notifikasi_list as $notif): ?>
                    <?php
                    $belum_dibaca = $notif["status_baca"] == "Belum Dibaca";
                    $pesan_lower = strtolower($notif["pesan"]);
                    $jenis_notif = "default";
                    $label_notif = "Notifikasi";

                    if (strpos($pesan_lower, "perlu diperbaiki") !== false) {
                        $jenis_notif = "repair";
                        $label_notif = "Perlu Perbaikan";
                    } elseif (
                        strpos($pesan_lower, "diberikan kepada") !== false ||
                        strpos($pesan_lower, "mendapatkan pekerjaan") !== false
                    ) {
                        $jenis_notif = "new";
                        $label_notif = "Pekerjaan Baru";
                    } elseif (
                        strpos($pesan_lower, "dikonfirmasi selesai") !== false ||
                        strpos($pesan_lower, "telah selesai") !== false
                    ) {
                        $jenis_notif = "success";
                        $label_notif = "Disetujui / Selesai";
                    } elseif (strpos($pesan_lower, "menunggu konfirmasi") !== false) {
                        $jenis_notif = "waiting";
                        $label_notif = "Menunggu Konfirmasi";
                    }
                    ?>

                    <a href="../notifikasi.php?baca=<?= (int) $notif["id_notifikasi"]; ?>" class="notification-item <?= $belum_dibaca ? "unread" : ""; ?>">
                        <div class="notification-icon <?= $jenis_notif; ?>">
                            <?php if ($jenis_notif == "repair"): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.1 2.1-2-2 2.1-2.1z"></path>
                                </svg>
                            <?php elseif ($jenis_notif == "new"): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="8" y1="13" x2="16" y2="13"></line>
                                    <line x1="8" y1="17" x2="13" y2="17"></line>
                                </svg>
                            <?php elseif ($jenis_notif == "success"): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="9"></circle>
                                    <polyline points="8 12 11 15 16 9"></polyline>
                                </svg>
                            <?php elseif ($jenis_notif == "waiting"): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="9"></circle>
                                    <polyline points="12 7 12 12 15 14"></polyline>
                                </svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                    <path d="M13.7 21a2 2 0 0 1-3.4 0"></path>
                                </svg>
                            <?php endif; ?>
                        </div>

                        <div class="notification-content">
                            <div class="notification-message">
                                <?= htmlspecialchars($notif["pesan"], ENT_QUOTES, "UTF-8"); ?>
                                <span class="notification-label <?= $jenis_notif; ?>"><?= $label_notif; ?></span>
                                <?php if ($belum_dibaca): ?>
                                    <span class="unread-label">Belum Dibaca</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($notif["tanggal"])): ?>
                                <div class="notification-time">
                                    <?= date("d M Y, H:i", strtotime($notif["tanggal"])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty">Tidak ada notifikasi.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

</main>

</div>

<script>
(function () {
    function terapkanTema() {
        const tema = localStorage.getItem("simo-theme");
        document.body.classList.toggle("dark-mode", tema === "dark");
    }

    terapkanTema();

    window.addEventListener("storage", function (event) {
        if (event.key === "simo-theme") {
            terapkanTema();
        }
    });

    /* Cadangan: sembunyikan ikon/menu notifikasi yang ada di topbar
       (semua tautan ke notifikasi.php di luar daftar notifikasi) */
    document.querySelectorAll('a[href*="notifikasi.php"]').forEach(function (a) {
        if (a.closest(".notification-list")) return;
        const pembungkus = a.closest(".notif-wrapper, .notification-wrapper, .notif-menu, .notif-dropdown, .notif-box");
        (pembungkus || a).classList.add("notif-topbar-hidden");
    });
})();
</script>
</body>
</html>
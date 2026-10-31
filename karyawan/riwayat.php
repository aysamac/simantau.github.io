<?php
include "../config/session.php";
if (
    !isset($_SESSION["id_user"]) ||
    ($_SESSION["role"] ?? "") != "Karyawan"
) {
    header("Location: ../index.php");
    exit;
}
include "../config/koneksi.php";
$id_karyawan = (int) $_SESSION["id_user"];
$query_karyawan = mysqli_query(
    $koneksi,
    "
    SELECT *
    FROM users
    WHERE id_user='$id_karyawan'
    LIMIT 1
    "
);
if (!$query_karyawan) {
    die("Data karyawan gagal dimuat: " . mysqli_error($koneksi));
}
$karyawan = mysqli_fetch_assoc($query_karyawan);
if (!$karyawan) {
    header("Location: dashboard.php");
    exit;
}
$nama_karyawan = $karyawan["nama"];
$id_divisi = (int) $karyawan["id_divisi"];
$query_divisi = mysqli_query(
    $koneksi,
    "
    SELECT *
    FROM divisi
    WHERE id_divisi='$id_divisi'
    LIMIT 1
    "
);
if (!$query_divisi) {
    die("Data divisi gagal dimuat: " . mysqli_error($koneksi));
}
$divisi = mysqli_fetch_assoc($query_divisi);
$nama_divisi = $divisi["nama_divisi"] ?? "-";
/* ===== FILTER ===== */
$jenis = $_GET["jenis"] ?? "Individu";
if (!in_array($jenis, ["Individu", "Tim"], true)) {
    $jenis = "Individu";
}
$filter_tipe_order = trim($_GET["tipe_order"] ?? "");
$filter_bulan = trim($_GET["bulan"] ?? "");
$filter_tahun = trim($_GET["tahun"] ?? "");
$filter_cari = trim($_GET["cari"] ?? "");
$bulan_indonesia = [
    1 => "Januari",
    2 => "Februari",
    3 => "Maret",
    4 => "April",
    5 => "Mei",
    6 => "Juni",
    7 => "Juli",
    8 => "Agustus",
    9 => "September",
    10 => "Oktober",
    11 => "November",
    12 => "Desember"
];
$tahun_sekarang = (int) date("Y");
$where_filter = "";
if ($filter_tipe_order !== "") {
    $tipe_order_sql = mysqli_real_escape_string($koneksi, $filter_tipe_order);
    $where_filter .= " AND p.tipe_order = '$tipe_order_sql' ";
}
if (
    $filter_bulan !== "" &&
    ctype_digit($filter_bulan) &&
    (int) $filter_bulan >= 1 &&
    (int) $filter_bulan <= 12
) {
    $where_filter .= " AND MONTH(p.tanggal_mulai) = " . (int) $filter_bulan . " ";
}
if (
    $filter_tahun !== "" &&
    ctype_digit($filter_tahun) &&
    (int) $filter_tahun >= 2000 &&
    (int) $filter_tahun <= 2100
) {
    $where_filter .= " AND YEAR(p.tanggal_mulai) = " . (int) $filter_tahun . " ";
}
if ($filter_cari !== "") {
    $filter_cari_sql = mysqli_real_escape_string($koneksi, $filter_cari);
    $where_filter .= "
        AND (
            p.nama_pekerjaan LIKE '%$filter_cari_sql%'
            OR p.jenis_pekerjaan LIKE '%$filter_cari_sql%'
            OR p.tipe_order LIKE '%$filter_cari_sql%'
            OR p.nomor_order LIKE '%$filter_cari_sql%'
        )
    ";
}
/* ===== PEKERJAAN INDIVIDU / TIM =====
   Tim = pekerjaan yang dikerjakan lebih dari 1 karyawan.
   Status penugasan yang dihitung bisa disesuaikan di sini. */
$hitung_anggota_sql = "
    (
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_aktif
                WHERE pk_aktif.id_pekerjaan = p.id_pekerjaan
                    AND pk_aktif.status_penugasan IN ('Aktif','Peralihan')
            ) THEN (
                SELECT COUNT(DISTINCT pk_aktif_count.id_user)
                FROM pekerjaan_karyawan pk_aktif_count
                WHERE pk_aktif_count.id_pekerjaan = p.id_pekerjaan
                    AND pk_aktif_count.status_penugasan IN ('Aktif','Peralihan')
            )
            ELSE (
                SELECT COUNT(DISTINCT pk_semua.id_user)
                FROM pekerjaan_karyawan pk_semua
                WHERE pk_semua.id_pekerjaan = p.id_pekerjaan
            )
        END
    ) +
    CASE
        WHEN NOT EXISTS (
            SELECT 1
            FROM pekerjaan_karyawan pk_legacy
            WHERE pk_legacy.id_pekerjaan = p.id_pekerjaan
        ) AND COALESCE(p.id_karyawan, 0) > 0
        THEN 1
        ELSE 0
    END
";
$kondisi_individu = " AND $hitung_anggota_sql <= 1 ";
$kondisi_tim = " AND $hitung_anggota_sql > 1 ";
$kondisi_jenis = ($jenis == "Tim") ? $kondisi_tim : $kondisi_individu;
$sql_dasar = "
    FROM pekerjaan p
    LEFT JOIN pekerjaan_karyawan pk
        ON pk.id_pekerjaan=p.id_pekerjaan
        AND pk.id_user='$id_karyawan'
    LEFT JOIN pengalihan_pekerjaan pp
        ON pp.id_pekerjaan=p.id_pekerjaan
        AND pp.id_karyawan_pengaju='$id_karyawan'
        AND pp.status_pengalihan='Berhasil'
    WHERE
        p.id_divisi='$id_divisi'
        AND
        (
            p.id_karyawan='$id_karyawan'
            OR
            pk.id_user='$id_karyawan'
            OR
            pp.id_pengalihan IS NOT NULL
        )
        AND (p.status='Selesai' OR pp.id_pengalihan IS NOT NULL OR pk.status_penugasan='Selesai')
";
/* ===== URUTAN: AKTIVITAS / UPDATE TERBARU PALING ATAS =====
   waktu_terbaru diambil dari bukti terakhir yang dikirim dan
   peralihan terakhir yang dilakukan karyawan ini. */
$query_riwayat = mysqli_query(
    $koneksi,
    "
    SELECT DISTINCT
        p.id_pekerjaan,
        p.nama_pekerjaan,
        p.jenis_pekerjaan,
        p.tipe_order,
        p.nomor_order,
        p.tanggal_mulai,
        p.target_selesai,
        (
            SELECT GROUP_CONCAT(DISTINCT u.nama ORDER BY u.nama SEPARATOR '\n')
            FROM pekerjaan_karyawan pk_nama
            INNER JOIN users u ON u.id_user=pk_nama.id_user
            WHERE pk_nama.id_pekerjaan=p.id_pekerjaan
        ) AS nama_karyawan_tim,
        GREATEST(
            COALESCE(
                (
                    SELECT MAX(bp_terbaru.tanggal_upload)
                    FROM bukti_pekerjaan bp_terbaru
                    WHERE bp_terbaru.id_pekerjaan = p.id_pekerjaan
                ),
                '1970-01-01 00:00:00'
            ),
            COALESCE(
                (
                    SELECT MAX(pp_terbaru.tanggal_update)
                    FROM pengalihan_pekerjaan pp_terbaru
                    WHERE pp_terbaru.id_pekerjaan = p.id_pekerjaan
                    AND pp_terbaru.id_karyawan_pengaju = '$id_karyawan'
                    AND pp_terbaru.status_pengalihan = 'Berhasil'
                ),
                '1970-01-01 00:00:00'
            )
        ) AS waktu_terbaru,
        CASE
            WHEN pp.id_pengalihan IS NOT NULL OR pk.status_penugasan='Selesai'
                THEN 'Selesai'
            WHEN p.status='Menunggu Konfirmasi'
                THEN 'Menunggu Konfirmasi'
            WHEN p.status='Selesai'
                THEN 'Selesai'
            ELSE p.status
        END AS status
    $sql_dasar
        $kondisi_jenis
        $where_filter
    ORDER BY
        waktu_terbaru DESC,
        p.id_pekerjaan DESC
    "
);
if (!$query_riwayat) {
    die(
        "Data riwayat gagal dimuat: " .
        mysqli_error($koneksi)
    );
}
$jumlah_data = mysqli_num_rows($query_riwayat);
/* Jumlah di tab (tanpa filter lain) */
$count_individu = 0;
$count_tim = 0;
$query_count_individu = mysqli_query(
    $koneksi,
    "SELECT COUNT(DISTINCT p.id_pekerjaan) AS total $sql_dasar $kondisi_individu"
);
if ($query_count_individu) {
    $count_individu = (int) (mysqli_fetch_assoc($query_count_individu)["total"] ?? 0);
}
$query_count_tim = mysqli_query(
    $koneksi,
    "SELECT COUNT(DISTINCT p.id_pekerjaan) AS total $sql_dasar $kondisi_tim"
);
if ($query_count_tim) {
    $count_tim = (int) (mysqli_fetch_assoc($query_count_tim)["total"] ?? 0);
}
$params_tab = $_GET;
$params_tab["jenis"] = "Individu";
$url_individu = "riwayat.php?" . http_build_query($params_tab);
$params_tab["jenis"] = "Tim";
$url_tim = "riwayat.php?" . http_build_query($params_tab);
$total_notif = 0;
$query_notif = mysqli_query(
    $koneksi,
    "
    SELECT COUNT(*) AS total
    FROM notifikasi
    WHERE id_user='$id_karyawan'
    AND status_baca='Belum Dibaca'
    "
);
if ($query_notif) {
    $data_notif = mysqli_fetch_assoc($query_notif);
    $total_notif = $data_notif["total"] ?? 0;
}
$inisial = strtoupper(
    substr($nama_karyawan, 0, 1)
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Riwayat Pekerjaan - SIMO</title>
<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI',Arial,sans-serif;
}
body{
    background:#fff;
    min-height:100vh;
    color:#2c3644;
}
a{
    text-decoration:none;
    color:inherit;
}
.main{
    margin-left:240px;
    width:calc(100% - 240px);
    min-width:0;
    min-height:100vh;
}
.content{
    padding:26px 32px 40px;
}
.page-header{
    margin-bottom:22px;
}
.page-header h1{
    font-size:25px;
    color:#1c2636;
    margin-bottom:5px;
}
.page-header p{
    font-size:13px;
    color:#667488;
}
.card{
    background:#fff;
    border:1px solid #e1e6ed;
    border-radius:13px;
    padding:0;
    overflow:hidden;
    box-shadow:none;
}
.card-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:20px;
    margin-bottom:0;
}
.card-title h3{
    font-size:16px;
    color:#1c2636;
}
.card-title p{
    font-size:12px;
    color:#8b96a5;
    margin-top:4px;
}
.total-data{
    font-size:12px;
    color:#8b96a5;
    white-space:nowrap;
}
.table-wrapper{
    width:100%;
    overflow-x:auto;
}
table{
    width:100%;
    min-width:1000px;
    border-collapse:collapse;
}
th{
    background:#b51f2a;
    padding:12px 15px;
    text-align:left;
    font-size:12px;
    color:#526176;
    font-weight:700;
    white-space:nowrap;
    border:0;
}
td{
    padding:13px 15px;
    border-bottom:1px solid #edf1f5;
    font-size:13.5px;
    color:#405067;
    vertical-align:middle;
}
tbody tr:last-child td{
    border-bottom:none;
}
.nowrap{
    white-space:nowrap;
}
.clickable-row{
    cursor:pointer;
    transition:background .15s ease;
}
.clickable-row:hover td{
    background:#f9fafb !important;
}
.job-name{
    font-size:14px;
    font-weight:400;
    color:#26364e;
}
.job-type{
    font-size:11px;
    color:#8793a4;
    margin-top:4px;
}
.order-type{
    font-size:12.5px;
    font-weight:600;
    color:#526176;
}
.order-number{
    font-size:11px;
    color:#8793a4;
    margin-top:4px;
}
.employee-names{
    white-space:pre-line;
    font-size:14px;
    line-height:1.7;
    color:#405067;
}
.status-badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:20px;
    font-size:11px;
    font-weight:650;
    white-space:nowrap;
}
.badge-selesai{
    background:#dff5e8;
    color:#29965a;
}
.badge-menunggu{
    background:#eee5ff;
    color:#7650a8;
}
.empty{
    text-align:center;
    padding:40px 20px;
    color:#7d8998;
    font-size:13px;
}
.footer-note{
    text-align:center;
    font-size:12px;
    color:#a3adba;
    padding:26px 0 6px;
}
/* ===== TAB PEKERJAAN INDIVIDU / TIM ===== */
.job-type-tabs{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:18px;
}
.job-type-tab{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    width:350px;
    max-width:100%;
    min-height:50px;
    padding:8px 16px;
    border:1px solid #e1e6ed;
    border-radius:12px;
    background:#fff;
    color:#526176;
    font-size:13px;
    font-weight:700;
    transition:.15s ease;
}
.job-type-tab:hover{
    border-color:#b51f2a;
    color:#b51f2a;
}
.job-type-tab.active{
    background:#b51f2a;
    border-color:#b51f2a;
    color:#fff;
}
.job-type-icon{
    display:inline-flex;
    width:20px;
    height:20px;
    align-items:center;
    justify-content:center;
    flex:0 0 20px;
}
.job-type-icon svg{
    width:20px;
    height:20px;
    fill:none;
    stroke:currentColor;
    stroke-width:1.8;
    stroke-linecap:round;
    stroke-linejoin:round;
}
.job-type-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:26px;
    height:26px;
    padding:0 7px;
    border-radius:50%;
    background:#eef1f5;
    color:#526176;
    font-size:11px;
    font-weight:700;
}
.job-type-tab.active .job-type-count{
    background:rgba(255,255,255,.22);
    color:#fff;
}
/* ===== FILTER ===== */
.filter-card{background:#fff;border:1px solid #e1e6ed;border-radius:11px;padding:14px;margin-bottom:18px}
.filter-heading h3{display:none}
.filter-heading p{display:none}
.filter-form{display:grid;grid-template-columns:minmax(240px,1.7fr) repeat(3,minmax(130px,1fr)) auto;align-items:end;gap:10px;margin-top:0}
.filter-field label{display:none}
.filter-form input,.filter-form select{width:100%;height:42px;padding:0 12px;border:1px solid #dfe3e8;border-radius:8px;background:#fff;color:#405067;font-size:12px;outline:none}
.filter-form input:focus,
.filter-form select:focus{
    border-color:#c96f75;
    box-shadow:0 0 0 3px rgba(201,111,117,.10);
}
.filter-actions{
    display:flex;
    gap:8px;
}
.btn-filter{height:42px;padding:0 20px;
    border-radius:8px;
    font-size:12px;
    font-weight:650;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
    cursor:pointer;
}
.btn-filter{border:1px solid #b51f2a;background:#b51f2a;color:#fff}
.btn-filter:hover{background:#8f1821}
tbody tr td{
    background:#fff !important;
}
tbody tr:hover td{
    background:#fafbfc !important;
}

/* =========================================================
   RESPONSIVE
========================================================= */
@media(max-width:1100px){
    .filter-form{
        grid-template-columns:1fr 1fr;
    }
    .filter-actions{
        grid-column:1 / -1;
    }
}
@media(max-width:800px){
    .main{
        margin-left:0;
        width:100%;
    }
    .content{
        padding:20px;
    }
    .job-type-tab{
        flex:1;
        width:auto;
    }
}
@media(max-width:700px){
    .filter-card{padding:16px}
    .filter-form{grid-template-columns:1fr;gap:12px}
    .filter-form input,.filter-form select{height:44px;font-size:14px}
    .filter-actions{width:100%}
    .btn-filter{flex:1;height:44px;font-size:13.5px}
    .job-type-tabs{flex-direction:column;align-items:stretch}
    .job-type-tab{width:100%;flex:none}
}
@media(max-width:600px){
    .content{
        padding:18px 14px 30px;
    }
    .page-header h1{
        font-size:22px;
    }
}

/* ===== HP: tabel berubah jadi kartu per pekerjaan ===== */
@media(max-width:700px){
    .card-title{padding:16px 14px;flex-wrap:wrap}
    .card-title h3{font-size:15px}
    .table-wrapper{overflow-x:visible;padding:0 14px 6px}

    table{min-width:0;display:block;background:transparent}
    table thead{display:none}
    table tbody{display:block}

    table tbody tr{
        display:block;
        background:#fff;
        border:1px solid #c9cfd8;
        border-radius:12px;
        padding:4px 14px;
        margin-bottom:12px;
    }
    table tbody tr:last-child td{border-bottom:1px solid #edf1f5}
    table tbody tr td:last-child{border-bottom:none!important}

    table tbody tr td{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:16px;
        padding:12px 0;
        text-align:right;
        font-size:14.5px;
        font-weight:500;
        line-height:1.4;
        color:#111827;
        border-bottom:1px solid #edf1f5;
    }
    table tbody tr td:before{
        content:attr(data-label);
        flex-shrink:0;
        font-size:13px;
        font-weight:700;
        color:#526176;
        text-align:left;
    }

    /* kolom No disembunyikan di HP */
    table tbody tr td.col-no{display:none}

    table tbody tr td.nowrap{white-space:normal}
    table tbody tr td .job-name{font-size:14.5px;font-weight:700;color:#111827}
    .employee-names{text-align:right;line-height:1.5;color:#111827}
    .status-badge{font-size:12px;padding:6px 11px}

    /* tidak ada perubahan warna latar saat kursor di atas kartu */
    table tbody tr.clickable-row:hover td,
    table tbody tr.clickable-row td{background:#fff!important}
    table tbody tr.clickable-row:hover{border-color:#b51f2a}

    /* baris kosong */
    table tbody tr td.empty{display:block;text-align:center;border-bottom:none!important;padding:30px 10px;font-size:13.5px}
    table tbody tr td.empty:before{display:none}
}

/* ===== DARK MODE ===== */
body.dark-mode{background:#181b20!important;color:#eef1f5!important}
body.dark-mode .main,body.dark-mode .content{background:#181b20!important}
body.dark-mode .page-header h1,body.dark-mode .card-title h3,body.dark-mode .filter-heading h3{color:#f1f3f5!important}
body.dark-mode .page-header p,body.dark-mode .card-title p,body.dark-mode .total-data,body.dark-mode .filter-heading p{color:#9da6b4!important}
body.dark-mode .card,body.dark-mode .filter-card{background:#202329!important;border-color:#343942!important}
body.dark-mode th{background:#b51f2a!important;color:#fff!important}
body.dark-mode td{background:#202329!important;color:#fff!important;border-bottom-color:#30353d!important}
body.dark-mode tbody tr td{background:#202329!important;color:#fff!important}
body.dark-mode .clickable-row:hover td,body.dark-mode tbody tr:hover td{background:#292d34!important}
body.dark-mode .job-name{color:#edf0f4!important}
body.dark-mode .job-type,body.dark-mode .order-number{color:#858e9d!important}
body.dark-mode .employee-names{color:#fff!important}
body.dark-mode .order-type{color:#c8ced7!important}
body.dark-mode .badge-selesai{background:#1f4b31!important;color:#8fd3a5!important}
body.dark-mode .badge-menunggu{background:#392b50!important;color:#c7a8ef!important}
body.dark-mode .empty{color:#8d96a5!important}
body.dark-mode .footer-note{color:#707a89!important}
body.dark-mode .filter-field label{color:#c8ced7!important}
body.dark-mode .filter-form input,body.dark-mode .filter-form select{background:#1a1d22!important;color:#e5e8ed!important;border-color:#45404a!important}
body.dark-mode .filter-form select option{background:#1a1d22!important;color:#e5e8ed!important}
body.dark-mode .filter-form input::placeholder{color:#737c8b!important}
body.dark-mode .filter-form input:focus,body.dark-mode .filter-form select:focus{border-color:#8b4b50!important;box-shadow:0 0 0 3px rgba(181,31,42,.12)!important}
body.dark-mode .btn-filter{background:#b51f2a!important;border-color:#b51f2a!important;color:#fff!important}
body.dark-mode .btn-filter:hover{background:#8f1821!important}
body.dark-mode .table-wrapper{border-color:#343942!important}
body.dark-mode .job-type-tab{background:#20242a!important;border-color:#343942!important;color:#f1f3f5!important}
body.dark-mode .job-type-tab:hover{background:#292e36!important;border-color:#b51f2a!important}
body.dark-mode .job-type-tab.active{background:#b51f2a!important;border-color:#b51f2a!important;color:#fff!important}
body.dark-mode .job-type-count{background:#343942!important;color:#fff!important}
body.dark-mode .job-type-tab.active .job-type-count{background:rgba(255,255,255,.2)!important}
table thead th{background:#b51f2a!important;color:#fff!important;border-color:#b51f2a!important}
body.dark-mode table thead th{background:#b51f2a!important;color:#fff!important;border-color:#b51f2a!important}

/* dark mode: kartu di HP */
@media(max-width:700px){
    html body.dark-mode table tbody tr{background:#202329!important;border-color:#3d4756!important}
    html body.dark-mode table tbody tr td{border-bottom-color:#30353d!important;color:#fff!important}
    html body.dark-mode table tbody tr td:last-child{border-bottom:none!important}
    html body.dark-mode table tbody tr td:before{color:#d5dbe5!important}
    html body.dark-mode table tbody tr td .job-name{color:#fff!important}
    html body.dark-mode table tbody tr.clickable-row:hover td,
    html body.dark-mode table tbody tr.clickable-row td{background:#202329!important}
    html body.dark-mode table tbody tr.clickable-row:hover{border-color:#b51f2a!important}
    html body.dark-mode table tbody tr td.empty{color:#8d96a5!important}
}
</style>
</head>
<body>
<?php
include "../includes/karyawan/sidebar.php";
?>
<main class="main">
<?php
include "../includes/karyawan/topbar.php";
?>
<div class="content">
<section class="page-header">
    <h1>Riwayat Pekerjaan</h1>
    <p>Daftar pekerjaan yang telah selesai atau menunggu konfirmasi.</p>
</section>
<div class="job-type-tabs">
    <a href="<?= htmlspecialchars($url_individu, ENT_QUOTES, 'UTF-8'); ?>" class="job-type-tab <?= $jenis == 'Individu' ? 'active' : ''; ?>">
        <span class="job-type-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg></span>
        <span>Pekerjaan Individu</span>
        <span class="job-type-count"><?= $count_individu; ?></span>
    </a>
    <a href="<?= htmlspecialchars($url_tim, ENT_QUOTES, 'UTF-8'); ?>" class="job-type-tab <?= $jenis == 'Tim' ? 'active' : ''; ?>">
        <span class="job-type-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-1a4 4 0 0 0-3-3.87M16 4.13a4 4 0 0 1 0 7.75"/></svg></span>
        <span>Pekerjaan Tim</span>
        <span class="job-type-count"><?= $count_tim; ?></span>
    </a>
</div>
<div class="filter-card">
    <div class="filter-heading">
        <h3>Filter Riwayat</h3>
        <p>Cari pekerjaan yang sudah selesai.</p>
    </div>
    <form method="GET" class="filter-form">
        <input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="filter-field filter-search">
            <label>Cari Pekerjaan</label>
            <input type="text" name="cari"
                value="<?= htmlspecialchars($filter_cari, ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Nama pekerjaan / deskripsi...">
        </div>
        <div class="filter-field">
            <label>Tipe Order</label>
            <select name="tipe_order">
                <option value="">Semua Tipe Order</option>
                <option value="Order Perbaikan" <?= $filter_tipe_order == "Order Perbaikan" ? "selected" : ""; ?>>Order Perbaikan</option>
                <option value="Non Order" <?= $filter_tipe_order == "Non Order" ? "selected" : ""; ?>>Non Order</option>
                <option value="Maintenance" <?= $filter_tipe_order == "Maintenance" ? "selected" : ""; ?>>Maintenance</option>
            </select>
        </div>
        <div class="filter-field">
            <label>Bulan</label>
            <select name="bulan">
                <option value="">Semua Bulan</option>
                <?php foreach ($bulan_indonesia as $nomor_bulan => $nama_bulan): ?>
                    <option value="<?= $nomor_bulan; ?>" <?= ((string) $filter_bulan === (string) $nomor_bulan) ? "selected" : ""; ?>><?= $nama_bulan; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Tahun</label>
            <select name="tahun">
                <option value="">Semua Tahun</option>
                <?php for ($th = $tahun_sekarang + 1; $th >= $tahun_sekarang - 5; $th--): ?>
                    <option value="<?= $th; ?>" <?= ((string) $filter_tahun === (string) $th) ? "selected" : ""; ?>><?= $th; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-filter">Tampilkan</button>
        </div>
    </form>
</div>
<div class="card">
<div class="card-title">
    <div>
        <h3>Daftar Riwayat Pekerjaan <?= $jenis == 'Tim' ? 'Tim' : 'Individu'; ?></h3>
        <p>Pekerjaan yang sudah selesai atau menunggu konfirmasi.</p>
    </div>
    <div class="total-data">
        <?= $jumlah_data; ?> pekerjaan
    </div>
</div>
<div class="table-wrapper">
<table>
<thead>
<tr>
    <th>No</th>
    <th>Nama Pekerjaan</th>
    <th>Jenis</th>
    <th>Tipe Order</th>
    <th>No. Order</th>
    <?php if ($jenis == 'Tim'): ?>
        <th>Karyawan</th>
    <?php endif; ?>
    <th>Tanggal Mulai</th>
    <th>Target Selesai</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
<?php
$no = 1;
if ($jumlah_data > 0):
while ($row = mysqli_fetch_assoc($query_riwayat)):
    $id_pekerjaan = (int)$row["id_pekerjaan"];
    $status = $row["status"] ?? "Selesai";
    if ($status == "Selesai") {
        $class_status = "badge-selesai";
    } elseif ($status == "Menunggu Konfirmasi") {
        $class_status = "badge-menunggu";
    } else {
        $class_status = "badge-selesai";
    }
?>
<tr
    class="clickable-row"
    onclick="window.location.href='detail_riwayat.php?id=<?= $id_pekerjaan; ?>';"
>
<td class="col-no" data-label="No"><?= $no++; ?></td>
<td data-label="Pekerjaan">
    <div class="job-name">
        <?= htmlspecialchars($row["nama_pekerjaan"], ENT_QUOTES, "UTF-8"); ?>
    </div>
</td>
<td data-label="Jenis">
    <?= htmlspecialchars($row["jenis_pekerjaan"] ?? "-", ENT_QUOTES, "UTF-8"); ?>
</td>
<td data-label="Tipe Order">
    <?= htmlspecialchars($row["tipe_order"] ?? "-", ENT_QUOTES, "UTF-8"); ?>
</td>
<td data-label="No. Order">
    <?= !empty($row["nomor_order"]) ? htmlspecialchars($row["nomor_order"], ENT_QUOTES, "UTF-8") : "-"; ?>
</td>
<?php if ($jenis == 'Tim'): ?>
<td data-label="Karyawan">
    <div class="employee-names">
        <?= !empty($row["nama_karyawan_tim"]) ? htmlspecialchars($row["nama_karyawan_tim"], ENT_QUOTES, "UTF-8") : "-"; ?>
    </div>
</td>
<?php endif; ?>
<td class="nowrap" data-label="Mulai">
    <?= !empty($row["tanggal_mulai"]) ? date("d M Y, H:i", strtotime($row["tanggal_mulai"])) : "-"; ?>
</td>
<td class="nowrap" data-label="Target">
    <?= !empty($row["target_selesai"]) ? date("d M Y, H:i", strtotime($row["target_selesai"])) : "-"; ?>
</td>
<td data-label="Status">
    <span class="status-badge <?= $class_status; ?>">
        <?= htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>
    </span>
</td>
</tr>
<?php
endwhile;
else:
?>
<tr>
<td colspan="<?= $jenis == 'Tim' ? '9' : '8'; ?>" class="empty">
    Belum ada riwayat pekerjaan <?= $jenis == 'Tim' ? 'tim' : 'individu'; ?>.
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<div class="footer-note">
    &copy;
    <?= date("Y"); ?>
    SIMO METRP. All rights reserved.
</div>
</div>
</main>
</body>
</html>
<?php

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Karyawan") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_karyawan = (int) $_SESSION["id_user"];

$query_karyawan = mysqli_query($koneksi, "
    SELECT *
    FROM users
    WHERE id_user='$id_karyawan'
    AND role='Karyawan'
");

if (
    !$query_karyawan ||
    mysqli_num_rows($query_karyawan) == 0
) {
    header("Location: dashboard.php");
    exit;
}

$karyawan = mysqli_fetch_assoc($query_karyawan);

$nama_karyawan = $karyawan["nama"];

$id_divisi = (int) $karyawan["id_divisi"];

$query_divisi = mysqli_query($koneksi, "
    SELECT *
    FROM divisi
    WHERE id_divisi='$id_divisi'
");

$divisi = mysqli_fetch_assoc($query_divisi);

$nama_divisi = $divisi["nama_divisi"] ?? "-";

$data_pekerjaan = [
    "Order Perbaikan" => [],
    "Non Order"       => [],
    "Maintenance"     => []
];

$query_pekerjaan = mysqli_query($koneksi, "
    SELECT p.id_pekerjaan, p.nama_pekerjaan, p.jenis_pekerjaan, p.tipe_order, p.nomor_order, p.lokasi, p.tanggal_mulai, p.target_selesai, p.deskripsi, p.status, GROUP_CONCAT(DISTINCT u.nama ORDER BY u.nama ASC SEPARATOR ', ') AS nama_karyawan
    FROM pekerjaan p
    INNER JOIN pekerjaan_karyawan pk_owner ON pk_owner.id_pekerjaan=p.id_pekerjaan AND pk_owner.status_penugasan IN ('Aktif','Peralihan','Menunggu Konfirmasi') AND pk_owner.id_user!='$id_karyawan'
    LEFT JOIN users u ON u.id_user=pk_owner.id_user
    WHERE p.id_divisi='$id_divisi'
    AND TRIM(LOWER(COALESCE(p.status,''))) IN ('belum dikerjakan','belum','peralihan','menunggu konfirmasi')
    AND p.tipe_order IN ('Order Perbaikan','Non Order','Maintenance')
    GROUP BY p.id_pekerjaan, p.nama_pekerjaan, p.jenis_pekerjaan, p.tipe_order, p.nomor_order, p.lokasi, p.tanggal_mulai, p.target_selesai, p.deskripsi, p.status
    ORDER BY p.tanggal_mulai DESC, p.id_pekerjaan DESC
");

if (!$query_pekerjaan) {
    die(
        "Data pekerjaan gagal dimuat: "
        . mysqli_error($koneksi)
    );
}

while ($row = mysqli_fetch_assoc($query_pekerjaan)) {

    $tipe_order = trim($row["tipe_order"]);

    if (empty($row["nama_karyawan"])) {

        $id_pekerjaan = (int) $row["id_pekerjaan"];

        $query_legacy = mysqli_query($koneksi, "
            SELECT u.nama
            FROM pekerjaan p
            LEFT JOIN users u
                ON u.id_user = p.id_karyawan
            WHERE p.id_pekerjaan='$id_pekerjaan'
            LIMIT 1
        ");

        if ($query_legacy) {

            $legacy = mysqli_fetch_assoc($query_legacy);

            if ($legacy) {
                $row["nama_karyawan"] = $legacy["nama"];
            }
        }
    }

    if (isset($data_pekerjaan[$tipe_order])) {
        $data_pekerjaan[$tipe_order][] = $row;
    }
}

$jumlah_order_perbaikan = count($data_pekerjaan["Order Perbaikan"]);

$jumlah_non_order = count($data_pekerjaan["Non Order"]);

$jumlah_maintenance = count($data_pekerjaan["Maintenance"]);

/* Tanggal + jam, contoh: 14 Oct 2026, 08:30 */
function formatTanggal($tanggal)
{
    if (
        empty($tanggal) ||
        $tanggal == "0000-00-00" ||
        $tanggal == "0000-00-00 00:00:00"
    ) {
        return "-";
    }

    $timestamp = strtotime($tanggal);

    if (!$timestamp) {
        return "-";
    }

    return date("d M Y, H:i", $timestamp);
}

/*
 * Teks status yang DITAMPILKAN di tabel.
 * Status "Peralihan" tidak ditampilkan; dianggap "Belum Dikerjakan".
 * Info peralihan hanya ada di halaman detail.
 */
function simo_teks_status($status)
{
    $s = strtolower(trim((string) $status));

    if ($s === "" || $s === "belum" || $s === "peralihan") {
        return "Belum Dikerjakan";
    }

    return trim($status);
}

/* Kelas badge status (sama dengan halaman Pekerjaan admin) */
function simo_badge_status($status)
{
    switch (trim($status)) {
        case "Sedang Dikerjakan":
            return "badge-sedang";
        case "Menunggu Konfirmasi":
            return "badge-menunggu";
        case "Perlu Perbaikan":
            return "badge-perbaikan";
        case "Selesai":
            return "badge-selesai";
        default:
            return "badge-belum";
    }
}

/* Daftar section: id, tipe, judul, jumlah */
$sections = [
    ["id" => "order-perbaikan", "key" => "Order Perbaikan", "label" => "Order Perbaikan", "jumlah" => $jumlah_order_perbaikan],
    ["id" => "non-order",       "key" => "Non Order",       "label" => "Non Order",       "jumlah" => $jumlah_non_order],
    ["id" => "maintenance",     "key" => "Maintenance",     "label" => "Maintenance",     "jumlah" => $jumlah_maintenance],
];

?>
<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pekerjaan Karyawan Lain - SIMO</title>

<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Arial,sans-serif}
body{background:#fff;min-height:100vh;color:#2c3644}
a{text-decoration:none;color:inherit}
.layout{min-height:100vh;width:100%}
.main{margin-left:240px;width:calc(100% - 240px);min-width:0;min-height:100vh;transition:margin-left .2s ease,width .2s ease}
.content{padding:26px 32px 40px;background:#fff}

/* HEADER HALAMAN */
.page-header{margin-bottom:22px}
.page-header h1{font-size:22px;font-weight:700;color:#1c2636;margin-bottom:5px}
.page-header p{font-size:13px;color:#667488}
.section-title{font-size:15px;font-weight:700;color:#1c2636;margin-bottom:14px}

/* KARTU TIPE ORDER */
.tipe-order-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:24px}
.tipe-card{position:relative;background:#fff;border:1px solid #dfe3e8;border-radius:11px;padding:20px 48px 18px 20px;min-height:112px;cursor:pointer;transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}
.tipe-card:hover{transform:translateY(-2px);box-shadow:0 5px 15px rgba(20,40,70,.07)}
.tipe-icon{display:none}
.tipe-card h3{font-size:15px;font-weight:700;margin:0 0 7px}
.tipe-card p{font-size:11.5px;color:#7a8596;line-height:1.5}
.jumlah-badge{position:absolute;top:20px;right:42px;min-width:28px;height:28px;padding:0 8px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
.tipe-card:after{content:'›';position:absolute;right:18px;top:50%;transform:translateY(-50%);font-size:22px;font-weight:300}

.tipe-card:nth-child(1){background:#fff4f4;border-color:#f0caca}
.tipe-card:nth-child(1) h3,.tipe-card:nth-child(1):after{color:#a61f2b}
.tipe-card:nth-child(1) .jumlah-badge{background:#f9dddd;color:#a61f2b}
.tipe-card:nth-child(2){background:#f4f8ff;border-color:#cddcf2}
.tipe-card:nth-child(2) h3,.tipe-card:nth-child(2):after{color:#3d6fae}
.tipe-card:nth-child(2) .jumlah-badge{background:#dfeafb;color:#3d6fae}
.tipe-card:nth-child(3){background:#f3faf4;border-color:#cfe4d2}
.tipe-card:nth-child(3) h3,.tipe-card:nth-child(3):after{color:#4b8a57}
.tipe-card:nth-child(3) .jumlah-badge{background:#dfeee1;color:#4b8a57}
.tipe-card.active:nth-child(1){border-color:#a61f2b;box-shadow:0 0 0 1px rgba(166,31,43,.10)}
.tipe-card.active:nth-child(2){border-color:#3d6fae;box-shadow:0 0 0 1px rgba(61,111,174,.10)}
.tipe-card.active:nth-child(3){border-color:#4b8a57;box-shadow:0 0 0 1px rgba(75,138,87,.10)}

/* SECTION DAFTAR */
.daftar-section{display:none}
.daftar-section.show{display:block}

.card{background:#fff;border:1px solid #d9dde2;border-radius:13px;padding:20px;box-shadow:0 1px 3px rgba(16,24,40,.04)}
.card-title{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px;flex-wrap:wrap}
.card-title h3{font-size:15px;font-weight:700;color:#1c2636}
.card-title p{font-size:11.5px;color:#8b96a5;margin-top:3px}
.card-title-right{display:flex;align-items:center;gap:12px}
.total-data{font-size:11.5px;color:#8b96a5}
.btn-tutup{height:34px;padding:0 14px;border:1px solid #d9dde2;background:#fff;color:#526176;border-radius:8px;font-size:11.5px;font-weight:600;cursor:pointer}
.btn-tutup:hover{background:#f5f7fa}

.search-box{position:relative;margin-bottom:16px}
.search-box svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;stroke:#9aa3af;fill:none;stroke-width:2;stroke-linecap:round}
.search-box input{width:390px;max-width:100%;padding:9px 12px 9px 34px;border:1px solid #d9dde2;border-radius:9px;font-size:12.5px;color:#405067;background:#fff;outline:none}
.search-box input:focus{border-color:#b51f2a}

/* =========================================================
   TABEL (desain sama dengan halaman Pekerjaan admin)
========================================================= */
.table-wrapper{width:100%;overflow-x:auto;background:transparent}

.tabel-pekerjaan{width:100%;min-width:1050px;border-collapse:collapse;background:#fff}

.tabel-pekerjaan th{
    background:#b51f2a;
    padding:14px 18px;
    text-align:left;
    font-size:12px;
    color:#fff;
    font-weight:700;
    white-space:nowrap;
    border-bottom:1px solid #b51f2a;
}
.tabel-pekerjaan th:first-child{border-top-left-radius:8px}
.tabel-pekerjaan th:last-child{border-top-right-radius:8px}

.tabel-pekerjaan td{
    padding:16px 18px;
    text-align:left;
    font-size:13.5px;
    color:#172033;
    border-bottom:1px solid #eef0f3;
    background:#fff;
    vertical-align:middle;
}

.tabel-pekerjaan tbody tr{transition:background .15s ease}
.tabel-pekerjaan tbody tr:hover td{background:#f8f9fb}

.tabel-pekerjaan .job-name{font-weight:600;color:#172033}
.tabel-pekerjaan .nowrap{white-space:nowrap}
.tabel-pekerjaan .karyawan-list{max-width:220px;line-height:1.45;white-space:normal;overflow-wrap:anywhere}

/* BADGE */
.status-badge,.order-badge{
    display:inline-block;
    padding:5px 9px;
    border-radius:6px;
    font-size:10.5px;
    font-weight:700;
    white-space:nowrap;
}
.badge-belum{background:#eef0f3;color:#667085}
.badge-sedang{background:#edf3ff;color:#3f65b5}
.badge-menunggu{background:#fff5dc;color:#a46b00}
.badge-perbaikan{background:#fde5e5;color:#c94242}
.badge-selesai{background:#e9f8f0;color:#168353}
.order-perbaikan{background:#f9e9eb;color:#b51f2a}
.non-order{background:#eef0f3;color:#667085}
.maintenance{background:#e9f8f0;color:#168353}

/* AKSI */
.btn-lihat{
    display:inline-flex;align-items:center;justify-content:center;
    padding:7px 14px;background:#fff8f8;color:#b51f2a;
    border:1px solid #d7a4aa;border-radius:7px;
    font-size:11px;font-weight:600;transition:.15s ease;
}
.btn-lihat:hover{background:#b51f2a;color:#fff;border-color:#b51f2a}

.empty{text-align:center;padding:40px 20px;color:#8a94a5;font-size:13px}

.footer-note{text-align:center;font-size:12px;color:#a3adba;padding:26px 0 6px}

/* RESPONSIVE */
@media(max-width:1000px){
    .tipe-card{min-width:0;min-height:96px;padding:16px 30px 14px 14px}
    .tipe-card h3{font-size:13px;line-height:1.3}
    .tipe-card p{font-size:10px;line-height:1.35}
    .jumlah-badge{top:12px;right:10px;min-width:22px;width:22px;height:22px;padding:0;font-size:9px}
    .tipe-card:after{display:none}
    .tipe-order-grid{gap:10px}
}
@media(max-width:800px){
    .main{margin-left:0;width:100%}
    .content{padding:20px}
    .search-box input{width:100%}
}
@media(max-width:600px){
    .content{padding:18px 14px 30px}
    .page-header h1{font-size:20px}
    .tipe-order-grid{gap:7px}
    .tipe-card{min-height:88px;padding:13px 8px 10px;border-radius:9px}
    .tipe-card h3{font-size:10.5px;margin:0 0 5px;padding-right:12px}
    .tipe-card p{font-size:8.5px;line-height:1.3}
    .jumlah-badge{top:8px;right:7px;min-width:18px;width:18px;height:18px;font-size:8px}
    .card-title-right{flex-direction:column;align-items:flex-end;gap:8px}
}

/* =========================================================
   DARK MODE (palet sama dengan halaman Pekerjaan admin)
========================================================= */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .main,html body.dark-mode .content{background:#0f141b!important}
html body.dark-mode .page-header h1,
html body.dark-mode .section-title,
html body.dark-mode .card-title h3{color:#edf2f7!important}
html body.dark-mode .page-header p,
html body.dark-mode .card-title p,
html body.dark-mode .total-data,
html body.dark-mode .empty{color:#8f9bac!important}

html body.dark-mode .tipe-card{box-shadow:none!important}
html body.dark-mode .tipe-card h3{color:#fff!important}
html body.dark-mode .tipe-card p{color:rgba(255,255,255,.78)!important}
html body.dark-mode .tipe-card:after{color:#fff!important}
html body.dark-mode .tipe-card:nth-child(1){background:#7f252d!important;border-color:#a33a44!important}
html body.dark-mode .tipe-card:nth-child(2){background:#234b83!important;border-color:#3b69a0!important}
html body.dark-mode .tipe-card:nth-child(3){background:#275638!important;border-color:#4a805b!important}
html body.dark-mode .tipe-card:nth-child(1) .jumlah-badge{background:#5a1b22!important;color:#fff!important}
html body.dark-mode .tipe-card:nth-child(2) .jumlah-badge{background:#193962!important;color:#fff!important}
html body.dark-mode .tipe-card:nth-child(3) .jumlah-badge{background:#1d412a!important;color:#fff!important}
html body.dark-mode .tipe-card.active{background:#151b23!important;border-color:#555b64!important}
html body.dark-mode .tipe-card.active p{color:#aeb5bf!important}
html body.dark-mode .tipe-card.active .jumlah-badge{background:#b51f2a!important;color:#fff!important}

html body.dark-mode .card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
html body.dark-mode .btn-tutup{background:#202733!important;border-color:#2c3542!important;color:#cbd2dc!important}
html body.dark-mode .btn-tutup:hover{background:#2a3342!important}
html body.dark-mode .search-box input{background:#0f141b!important;color:#e8edf4!important;border-color:#2c3542!important}
html body.dark-mode .search-box input::placeholder{color:#6f7a8a!important}
html body.dark-mode .search-box input:focus{border-color:#b51f2a!important}
html body.dark-mode .search-box svg{stroke:#8f9bac!important}

html body.dark-mode .tabel-pekerjaan{background:#151b23!important}
html body.dark-mode .tabel-pekerjaan th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#b51f2a!important}
html body.dark-mode .tabel-pekerjaan td{background:#151b23!important;color:#fff!important;border-bottom-color:#2c3542!important}
html body.dark-mode .tabel-pekerjaan tbody tr:hover td{background:#202733!important}
html body.dark-mode .tabel-pekerjaan .job-name,
html body.dark-mode .tabel-pekerjaan .karyawan-list{color:#fff!important}

html body.dark-mode .badge-belum{background:#34383e!important;color:#c5cbd3!important}
html body.dark-mode .badge-sedang{background:#1d3158!important;color:#8eb5ff!important}
html body.dark-mode .badge-menunggu{background:#3c3924!important;color:#f2d77d!important}
html body.dark-mode .badge-perbaikan{background:#4b2d32!important;color:#ff8b92!important}
html body.dark-mode .badge-selesai{background:#183d2a!important;color:#72d89a!important}
html body.dark-mode .order-perbaikan{background:#382326!important;color:#f07a80!important}
html body.dark-mode .non-order{background:#202733!important;color:#cbd2dc!important}
html body.dark-mode .maintenance{background:#183d2a!important;color:#72d89a!important}

html body.dark-mode .btn-lihat{background:#382326!important;border-color:#51363b!important;color:#f07a80!important}
html body.dark-mode .btn-lihat:hover{background:#b51f2a!important;border-color:#b51f2a!important;color:#fff!important}
html body.dark-mode .footer-note{color:#8f9bac!important}
/* =========================================================
   TAMPILAN HP: tabel berubah jadi kartu per pekerjaan
========================================================= */
@media(max-width:700px){
    .card{padding:16px 14px}
    .table-wrapper{overflow-x:visible}
    .tabel-pekerjaan{min-width:0;display:block;background:transparent}
    .tabel-pekerjaan thead{display:none}
    .tabel-pekerjaan tbody{display:block}
    .tabel-pekerjaan tr{display:block;background:#fff;border:1px solid #d9dde2;border-radius:12px;padding:4px 14px;margin-bottom:12px}
    .tabel-pekerjaan td{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:10px 0;text-align:right;border-bottom:1px solid #eef0f3;font-size:13px}
    .tabel-pekerjaan td:last-child{border-bottom:none}
    .tabel-pekerjaan td:before{content:attr(data-label);flex-shrink:0;font-size:11.5px;font-weight:600;color:#8b96a5;text-align:left}
    .tabel-pekerjaan .nowrap{white-space:normal}
    .tabel-pekerjaan .karyawan-list{max-width:none;text-align:right}
    .tabel-pekerjaan tbody tr:hover td{background:#fff}
    html body.dark-mode .tabel-pekerjaan tr{background:#151b23!important;border-color:#2c3542!important}
    html body.dark-mode .tabel-pekerjaan td:before{color:#8f9bac!important}
    html body.dark-mode .tabel-pekerjaan tbody tr:hover td{background:#151b23!important}

    /* kolom No disembunyikan di HP */
    .tabel-pekerjaan td:first-child{display:none}

    /* tulisan lebih jelas */
    .tabel-pekerjaan tr{border-color:#c9cfd8}
    .tabel-pekerjaan td{align-items:center;font-size:14.5px;font-weight:500;color:#111827;padding:12px 0;line-height:1.4}
    .tabel-pekerjaan td:before{font-size:13px;font-weight:700;color:#526176}
    .tabel-pekerjaan td.job-name{font-weight:700}
    .status-badge{font-size:12px;padding:6px 11px}
    .btn-lihat{font-size:13px;padding:8px 18px}
    html body.dark-mode .tabel-pekerjaan tr{border-color:#3d4756!important}
    html body.dark-mode .tabel-pekerjaan td{color:#fff!important;border-bottom-color:#2f3947!important}
    html body.dark-mode .tabel-pekerjaan td:before{color:#d5dbe5!important}
    html body.dark-mode .tabel-pekerjaan .karyawan-list{color:#fff!important}
}
</style>

</head>

<body>

<div class="layout">

    <?php include "../includes/karyawan/sidebar.php"; ?>

    <main class="main">

        <?php include "../includes/karyawan/topbar.php"; ?>

        <div class="content">

            <section class="page-header">
                <h1>Pekerjaan Karyawan Lain</h1>
                <p>Lihat pekerjaan karyawan lain dalam divisi <?= htmlspecialchars($nama_divisi); ?>.</p>
            </section>

            <h3 class="section-title">Pilih Tipe Order</h3>

            <div class="tipe-order-grid">

                <?php foreach ($sections as $sec): ?>
                    <div class="tipe-card" onclick="bukaTipe('<?= $sec["id"]; ?>', this)">
                        <div class="jumlah-badge"><?= $sec["jumlah"]; ?></div>
                        <h3><?= $sec["label"]; ?></h3>
                        <p>Lihat pekerjaan <?= $sec["label"]; ?>.</p>
                    </div>
                <?php endforeach; ?>

            </div>

            <?php foreach ($sections as $sec): ?>

                <?php
                $daftar   = $data_pekerjaan[$sec["key"]];
                $jumlah   = $sec["jumlah"];
                $table_id = "table-" . $sec["id"];
                ?>

                <section id="<?= $sec["id"]; ?>" class="daftar-section">

                    <div class="card">

                        <div class="card-title">
                            <div>
                                <h3><?= $sec["label"]; ?></h3>
                                <p>Pekerjaan <?= $sec["label"]; ?> yang belum dikerjakan.</p>
                            </div>

                            <div class="card-title-right">
                                <div class="total-data"><?= $jumlah; ?> pekerjaan</div>
                                <button type="button" class="btn-tutup" onclick="tutupDaftar()">Tutup</button>
                            </div>
                        </div>

                        <?php if ($jumlah > 0): ?>

                            <div class="search-box">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7"/>
                                    <path d="M21 21l-4-4"/>
                                </svg>
                                <input
                                    type="text"
                                    placeholder="Cari pekerjaan, nomor order, atau karyawan..."
                                    onkeyup="cariPekerjaan(this, '<?= $table_id; ?>')"
                                >
                            </div>

                            <div class="table-wrapper">

                                <table class="tabel-pekerjaan">

                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Pekerjaan</th>
                                            <th>Jenis</th>
                                            <th>No. Order</th>
                                            <th>Karyawan</th>
                                            <th>Mulai</th>
                                            <th>Target</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>

                                    <tbody id="<?= $table_id; ?>">

                                    <?php $no = 1; ?>

                                    <?php foreach ($daftar as $row): ?>

                                        <?php
                                        $teks_nomor = !empty($row["nomor_order"]) ? $row["nomor_order"] : "-";

                                        /* "Peralihan" ditampilkan sebagai "Belum Dikerjakan" */
                                        $teks_status  = simo_teks_status($row["status"] ?? "");
                                        $class_status = simo_badge_status($teks_status);
                                        ?>

                                        <tr data-search="<?= htmlspecialchars(strtolower($row["nama_pekerjaan"] . " " . ($row["nama_karyawan"] ?? "") . " " . ($row["nomor_order"] ?? ""))); ?>">

                                            <td data-label="No"><?= $no++; ?></td>

                                            <td class="job-name" data-label="Pekerjaan"><?= htmlspecialchars($row["nama_pekerjaan"]); ?></td>

                                            <td data-label="Jenis"><?= htmlspecialchars($row["jenis_pekerjaan"] ?: "-"); ?></td>

                                            <td data-label="No. Order"><?= htmlspecialchars($teks_nomor); ?></td>

                                            <td data-label="Karyawan">
                                                <div class="karyawan-list"><?= htmlspecialchars($row["nama_karyawan"] ?: "-"); ?></div>
                                            </td>

                                            <td class="nowrap" data-label="Mulai"><?= formatTanggal($row["tanggal_mulai"]); ?></td>

                                            <td class="nowrap" data-label="Target"><?= formatTanggal($row["target_selesai"]); ?></td>

                                            <td data-label="Status">
                                                <span class="status-badge <?= $class_status; ?>">
                                                    <?= htmlspecialchars($teks_status); ?>
                                                </span>
                                            </td>

                                            <td data-label="Aksi">
                                                <a href="detail_pekerjaan_karyawan_lain.php?id=<?= (int) $row["id_pekerjaan"]; ?>" class="btn-lihat">Lihat</a>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php else: ?>

                            <div class="empty">
                                Belum ada pekerjaan <?= $sec["label"]; ?> yang tersedia.
                            </div>

                        <?php endif; ?>

                    </div>

                </section>

            <?php endforeach; ?>

            <div class="footer-note">
                &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
            </div>

        </div>

    </main>

</div>

<script>

function bukaTipe(id, element)
{
    document
        .querySelectorAll(".tipe-card")
        .forEach(function(card)
        {
            card.classList.remove("active");
        });

    document
        .querySelectorAll(".daftar-section")
        .forEach(function(section)
        {
            section.classList.remove("show");
        });

    element.classList.add("active");

    const section = document.getElementById(id);

    if (section)
    {
        section.classList.add("show");

        setTimeout(function()
        {
            section.scrollIntoView({
                behavior: "smooth",
                block: "start"
            });
        }, 50);
    }
}

function tutupDaftar()
{
    document
        .querySelectorAll(".daftar-section")
        .forEach(function(section)
        {
            section.classList.remove("show");
        });

    document
        .querySelectorAll(".tipe-card")
        .forEach(function(card)
        {
            card.classList.remove("active");
        });

    window.scrollTo({
        top: 0,
        behavior: "smooth"
    });
}

function cariPekerjaan(input, tableId)
{
    const keyword = input.value.toLowerCase().trim();

    const table = document.getElementById(tableId);

    if (!table) {
        return;
    }

    const rows = table.querySelectorAll("tr");

    rows.forEach(function(row)
    {
        const searchData = (row.getAttribute("data-search") || "").toLowerCase();

        if (searchData.includes(keyword))
        {
            row.style.display = "";
        }
        else
        {
            row.style.display = "none";
        }
    });
}

</script>

</body>

</html>
<?php

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Admin") {

    header("Location: ../index.php");

    exit;

}

include "../config/koneksi.php";

$id_admin = $_SESSION["id_user"];

$query_admin = mysqli_query(

    $koneksi,

    "SELECT * FROM users WHERE id_user='$id_admin'"

);

$admin = mysqli_fetch_assoc($query_admin);

$id_divisi = $admin["id_divisi"];

$nama_admin = $admin["nama"];

$query_divisi = mysqli_query(

    $koneksi,

    "SELECT * FROM divisi WHERE id_divisi='$id_divisi'"

);

$divisi = mysqli_fetch_assoc($query_divisi);

$nama_divisi = $divisi["nama_divisi"];

$bulan = isset($_GET["bulan"])

    ? (int) $_GET["bulan"]

    : (int) date("m");

$tahun = isset($_GET["tahun"])

    ? (int) $_GET["tahun"]

    : (int) date("Y");

$id_karyawan = isset($_GET["id_karyawan"])

    ? (int) $_GET["id_karyawan"]

    : 0;

$nama_bulan = [

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

$periode_bulan = $nama_bulan[$bulan] ?? "Bulan Tidak Valid";

$filter_karyawan = "";

if ($id_karyawan > 0) {

    $filter_karyawan = "

        AND pekerjaan.id_karyawan='$id_karyawan'

    ";

}

$query_laporan = mysqli_query(

    $koneksi,

    "

    SELECT

        pekerjaan.*,

        users.nama AS nama_karyawan

    FROM pekerjaan

    LEFT JOIN users

        ON pekerjaan.id_karyawan = users.id_user

    WHERE pekerjaan.id_divisi='$id_divisi'

    AND MONTH(pekerjaan.tanggal_mulai)='$bulan'

    AND YEAR(pekerjaan.tanggal_mulai)='$tahun'

    $filter_karyawan

    ORDER BY pekerjaan.tanggal_mulai ASC

    "

);

$nama_karyawan = "Semua Karyawan";

if ($id_karyawan > 0) {

    $query_karyawan = mysqli_query(

        $koneksi,

        "

        SELECT nama

        FROM users

        WHERE id_user='$id_karyawan'

        AND id_divisi='$id_divisi'

        AND role='Karyawan'

        "

    );

    if ($query_karyawan && mysqli_num_rows($query_karyawan) > 0) {

        $data_karyawan = mysqli_fetch_assoc($query_karyawan);

        $nama_karyawan = $data_karyawan["nama"];

    }

}

$total=0;
$selesai=0;
$sedang=0;
$menunggu=0;
$perbaikan=0;
if($query_laporan){
    $rows_rekap=[];
    while($r=mysqli_fetch_assoc($query_laporan)){
        $rows_rekap[]=$r;
        $total++;
        $st=$r["status"] ?? "";
        if($st==="Selesai")$selesai++;
        elseif($st==="Sedang Dikerjakan")$sedang++;
        elseif($st==="Menunggu Konfirmasi")$menunggu++;
        elseif($st==="Perlu Perbaikan")$perbaikan++;
    }
    mysqli_data_seek($query_laporan,0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Pekerjaan Divisi - SIMO</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:14px 26px;background:#fff;color:#222;font-family:Arial,Helvetica,sans-serif;font-size:12px}
.report{width:100%;max-width:1200px;margin:auto}
.top{display:grid;grid-template-columns:240px 1fr 90px;align-items:start;min-height:82px;padding-bottom:8px;border-bottom:1px solid #222}
.logo-sosro{width:175px;height:auto;object-fit:contain;object-position:left top}
.logo-r{width:50px;height:50px;object-fit:contain;justify-self:end}
.title{text-align:center;padding-top:7px}
.title h1{margin:0;font-size:20px;font-weight:700;text-transform:uppercase}
.title h2{margin:4px 0 0;font-size:16px;font-weight:700;text-transform:uppercase}
.title p{margin:3px 0 0;font-size:11px}
.info{margin-top:10px}
.info-table{width:100%;border-collapse:collapse}
.info-table td{padding:4px 7px;border:0}
.info-table tr:last-child td{border:0}
.info-label{width:90px;font-weight:700}
.info-sep{width:12px;text-align:center}
.table-wrap{margin-top:10px}
table.data{width:100%;border-collapse:collapse;table-layout:fixed}
.data th{background:#e9e9e9;border:1px solid #777;padding:7px 5px;text-align:center;font-size:10px;font-weight:700}
.data td{border:1px solid #888;padding:7px 5px;font-size:10px;vertical-align:middle}
.center{text-align:center}
.status{font-weight:700;text-align:center}
.footer{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:18px;min-height:120px}
.footer-col{font-size:10px}
.footer-col.center{text-align:center}
.footer-col.right{text-align:right}
.sign-title{font-weight:700;margin-bottom:2px}
.sign-space{height:55px}
.sign-name{font-weight:700;text-decoration:underline}
.print-button{position:fixed;right:24px;top:18px;border:0;background:#b51f2a;color:#fff;padding:9px 18px;border-radius:5px;font-size:11px;font-weight:700;cursor:pointer}
@media print{
@page{size:A4 landscape;margin:10mm}
body{padding:0;font-size:11px}
.report{max-width:none}
.no-print{display:none!important}
.top{min-height:62px}
.logo-sosro{width:165px}
.logo-r{width:48px;height:48px}
.title{padding-top:4px}
.title h1{font-size:18px}
.title h2{font-size:14px}
.info{margin-top:7px;border:0}
.info-table td{padding:3px 6px;border:0}
.table-wrap{margin-top:7px}
.data th{padding:6px 4px;font-size:9.5px}
.data td{padding:6px 4px;font-size:9.5px}
.footer{margin-top:12px;min-height:85px}
}
</style>
</head>
<body>
<button class="print-button no-print" onclick="window.print()">Cetak</button>
<div class="report">
<div class="top">
<div><img src="../assets/img/logo-sosro.jpeg" alt="Sinar Sosro Gunung Slamat" class="logo-sosro"></div>
<div class="title">
<h1>SISTEM INFORMASI MONITORING</h1>
<h2>LAPORAN PEKERJAAN DIVISI</h2>
<p>SIMO METRP</p>
</div>
<div><img src="../assets/img/logo-login.jpeg" alt="Logo R" class="logo-r"></div>
</div>
<div class="info">
<table class="info-table">
<tr><td class="info-label">Divisi</td><td class="info-sep">:</td><td><?= htmlspecialchars($nama_divisi); ?></td><td class="info-label">Dicetak Oleh</td><td class="info-sep">:</td><td><?= htmlspecialchars($nama_admin); ?></td></tr>
<tr><td class="info-label">Periode</td><td class="info-sep">:</td><td><?= htmlspecialchars($periode_bulan); ?> <?= $tahun; ?></td><td class="info-label">Tanggal Cetak</td><td class="info-sep">:</td><td><?= date("d F Y"); ?></td></tr>
<tr><td class="info-label">Karyawan</td><td class="info-sep">:</td><td><?= htmlspecialchars($nama_karyawan); ?></td><td class="info-label">Jumlah Data</td><td class="info-sep">:</td><td><?= (int)$total; ?> Pekerjaan</td></tr>
</table>
</div>
<div class="table-wrap">
<table class="data">
<thead><tr>
<th style="width:4%">NO</th><th style="width:17%">NAMA PEKERJAAN</th><th style="width:13%">KARYAWAN</th><th style="width:12%">TIPE ORDER</th><th style="width:11%">NOMOR ORDER</th><th style="width:12%">TANGGAL MULAI</th><th style="width:12%">TARGET SELESAI</th><th style="width:9%">STATUS</th>
</tr></thead>
<tbody>
<?php
$no=1;
if($query_laporan && mysqli_num_rows($query_laporan)>0):
while($row=mysqli_fetch_assoc($query_laporan)):
?>
<tr>
<td class="center"><?= $no++; ?></td>
<td><?= htmlspecialchars($row["nama_pekerjaan"] ?? "-"); ?></td>
<td><?= htmlspecialchars($row["nama_karyawan"] ?? "-"); ?></td>
<td class="center"><?= htmlspecialchars($row["tipe_order"] ?? "-"); ?></td>
<td class="center"><?= !empty($row["nomor_order"]) ? htmlspecialchars($row["nomor_order"]) : "-"; ?></td>
<td class="center"><?= !empty($row["tanggal_mulai"]) ? date("d-m-Y",strtotime($row["tanggal_mulai"])) : "-"; ?></td>
<td class="center"><?= !empty($row["target_selesai"]) ? date("d-m-Y",strtotime($row["target_selesai"])) : "-"; ?></td>
<td class="status"><?= htmlspecialchars($row["status"] ?? "-"); ?></td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="8" class="center" style="padding:18px">Tidak ada data pekerjaan pada periode yang dipilih.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<div class="footer">
<div class="footer-col"><div>Dicetak melalui Sistem Informasi Monitoring (SIMO)</div><div style="margin-top:4px">Tanggal: <?= date("d F Y"); ?></div></div>
<div class="footer-col center"><div class="sign-title">DIBUAT OLEH :</div><div class="sign-space"></div><div class="sign-name"><?= htmlspecialchars($nama_admin); ?></div><div>Admin Divisi <?= htmlspecialchars($nama_divisi); ?></div></div>
<div class="footer-col right"><div class="sign-title">DISETUJUI OLEH :</div><div class="sign-space"></div><div class="sign-name">Admin Divisi</div><div><?= htmlspecialchars($nama_divisi); ?></div></div>
</div>
</div>
</body>
</html>

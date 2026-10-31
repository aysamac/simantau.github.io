<?php

if (session_status() === PHP_SESSION_NONE) {
    include "../config/session.php";
}

if (
    !isset($_SESSION["id_user"]) ||
    $_SESSION["role"] != "Admin"
) {
    header("Location: ../login.php");
    exit;
}

include "../config/koneksi.php";

$id_admin = intval($_SESSION["id_user"]);

$query_admin = mysqli_query(
    $koneksi,
    "
    SELECT
        u.id_user,
        u.nama,
        u.username,
        u.role,
        u.id_divisi,
        d.nama_divisi
    FROM users u
    LEFT JOIN divisi d
        ON u.id_divisi = d.id_divisi
    WHERE u.id_user = '$id_admin'
    LIMIT 1
    "
);

$admin = mysqli_fetch_assoc($query_admin);

if (!$admin) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$id_divisi = intval($admin["id_divisi"]);
$nama_divisi = $admin["nama_divisi"] ?? "-";

if ($id_divisi <= 0) {
    die("Data divisi Admin tidak ditemukan.");
}

$bulan = isset($_GET["bulan"])
    ? intval($_GET["bulan"])
    : intval(date("n"));

$tahun = isset($_GET["tahun"])
    ? intval($_GET["tahun"])
    : intval(date("Y"));

$id_karyawan = isset($_GET["id_karyawan"])
    ? intval($_GET["id_karyawan"])
    : 0;

if ($bulan < 1 || $bulan > 12) {
    $bulan = intval(date("n"));
}

if ($tahun < 2000 || $tahun > 2100) {
    $tahun = intval(date("Y"));
}

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

$nilai_selesai = 100;
$nilai_menunggu = 85;
$nilai_sedang = 70;
$nilai_perbaikan = 40;
$nilai_belum = 0;

$query_karyawan = mysqli_query(
    $koneksi,
    "
    SELECT
        id_user,
        nama
    FROM users
    WHERE id_divisi='$id_divisi'
    AND role='Karyawan'
    ORDER BY nama ASC
    "
);

$nama_filter = "Semua Karyawan";

if ($id_karyawan > 0) {

    $query_nama = mysqli_query(
        $koneksi,
        "
        SELECT nama
        FROM users
        WHERE id_user='$id_karyawan'
        AND id_divisi='$id_divisi'
        AND role='Karyawan'
        LIMIT 1
        "
    );

    if (
        $query_nama &&
        mysqli_num_rows($query_nama) > 0
    ) {
        $data_nama = mysqli_fetch_assoc($query_nama);
        $nama_filter = $data_nama["nama"];
    }
}

$filter_karyawan = "";

if ($id_karyawan > 0) {
    $filter_karyawan = "AND p.id_karyawan='$id_karyawan'";
}

$periode = sprintf("%04d-%02d", $tahun, $bulan);

/* Status "Peralihan" dihitung sebagai "Belum Dikerjakan" */
$query_rekap = mysqli_query(
    $koneksi,
    "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN p.status='Selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN p.status='Sedang Dikerjakan' THEN 1 ELSE 0 END) AS sedang,
        SUM(CASE WHEN p.status='Menunggu Konfirmasi' THEN 1 ELSE 0 END) AS menunggu,
        SUM(CASE WHEN p.status='Perlu Perbaikan' THEN 1 ELSE 0 END) AS perbaikan,
        SUM(CASE WHEN p.status IN ('Belum Dikerjakan','Peralihan') THEN 1 ELSE 0 END) AS belum
    FROM pekerjaan p
    WHERE p.id_divisi='$id_divisi'
    AND DATE_FORMAT(p.tanggal_mulai, '%Y-%m')='$periode'
    $filter_karyawan
    "
);

$rekap = mysqli_fetch_assoc($query_rekap);

$total = intval($rekap["total"] ?? 0);
$selesai = intval($rekap["selesai"] ?? 0);
$sedang = intval($rekap["sedang"] ?? 0);
$menunggu = intval($rekap["menunggu"] ?? 0);
$perbaikan = intval($rekap["perbaikan"] ?? 0);
$belum = intval($rekap["belum"] ?? 0);

$total_nilai =
    ($selesai * $nilai_selesai) +
    ($menunggu * $nilai_menunggu) +
    ($sedang * $nilai_sedang) +
    ($perbaikan * $nilai_perbaikan) +
    ($belum * $nilai_belum);

$kinerja = $total > 0
    ? round($total_nilai / $total)
    : 0;

$kinerja_karyawan = [];

$filter_user_chart = "";

if ($id_karyawan > 0) {
    $filter_user_chart = "AND u.id_user='$id_karyawan'";
}

$query_perbandingan = mysqli_query(
    $koneksi,
    "
    SELECT
        u.id_user,
        u.nama,
        COUNT(p.id_pekerjaan) AS jumlah,
        COALESCE(
            ROUND(
                AVG(
                    CASE
                        WHEN p.status='Selesai' THEN 100
                        WHEN p.status='Menunggu Konfirmasi' THEN 85
                        WHEN p.status='Sedang Dikerjakan' THEN 70
                        WHEN p.status='Perlu Perbaikan' THEN 40
                        WHEN p.status IN ('Belum Dikerjakan','Peralihan') THEN 0
                        ELSE 0
                    END
                )
            ),
            0
        ) AS nilai
    FROM users u
    LEFT JOIN pekerjaan p
        ON p.id_karyawan = u.id_user
        AND p.id_divisi='$id_divisi'
        AND DATE_FORMAT(p.tanggal_mulai, '%Y-%m')='$periode'
    WHERE u.id_divisi='$id_divisi'
    AND u.role='Karyawan'
    $filter_user_chart
    GROUP BY u.id_user, u.nama
    HAVING jumlah > 0
    ORDER BY nilai DESC, jumlah DESC, u.nama ASC
    "
);

if ($query_perbandingan) {
    while ($row = mysqli_fetch_assoc($query_perbandingan)) {
        $kinerja_karyawan[] = $row;
    }
}

$kategori_kualitas = [
    "Sangat Baik" => 0,
    "Baik" => 0,
    "Perlu Ditingkatkan" => 0,
    "Kurang" => 0
];

foreach ($kinerja_karyawan as $data_kinerja) {

    $nilai = intval($data_kinerja["nilai"]);

    if ($nilai >= 90) {
        $kategori_kualitas["Sangat Baik"]++;
    } elseif ($nilai >= 75) {
        $kategori_kualitas["Baik"]++;
    } elseif ($nilai >= 60) {
        $kategori_kualitas["Perlu Ditingkatkan"]++;
    } else {
        $kategori_kualitas["Kurang"]++;
    }
}

$total_kategori = array_sum($kategori_kualitas);

$query_riwayat = mysqli_query(
    $koneksi,
    "
    SELECT
        p.id_pekerjaan,
        p.nama_pekerjaan,
        p.jenis_pekerjaan,
        p.tipe_order,
        p.nomor_order,
        p.status,
        p.tanggal_mulai,
        p.target_selesai,
        u.nama AS nama_karyawan
    FROM pekerjaan p
    LEFT JOIN users u
        ON p.id_karyawan = u.id_user
    WHERE p.id_divisi='$id_divisi'
    AND DATE_FORMAT(p.tanggal_mulai, '%Y-%m')='$periode'
    $filter_karyawan
    ORDER BY p.id_pekerjaan DESC
    "
);

$nilai_donut = max(0, min(100, $kinerja));
$derajat_kinerja = $nilai_donut * 3.6;

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/* Status "Peralihan" ditampilkan sebagai "Belum Dikerjakan" */
function statusTampil($status)
{
    return $status == "Peralihan" ? "Belum Dikerjakan" : $status;
}

function getStatusClass($status)
{
    if ($status == "Selesai") {
        return "status-selesai";
    }

    if ($status == "Sedang Dikerjakan") {
        return "status-sedang";
    }

    if ($status == "Menunggu Konfirmasi") {
        return "status-menunggu";
    }

    if ($status == "Perlu Perbaikan") {
        return "status-perbaikan";
    }

    if ($status == "Peralihan") {
        return "status-peralihan";
    }

    return "status-belum";
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kinerja Karyawan - SIMO</title>
<style>

/* =========================================================
   VARIABEL WARNA
   (dark mode memakai palet yang sama dengan halaman karyawan)
========================================================= */
:root {
    --bg: #ffffff;
    --card: #ffffff;
    --card-border: #d9dee5;
    --text: #1c2636;
    --text-strong: #252d3a;
    --muted: #7c8796;
    --input-bg: #ffffff;
    --input-border: #d9dde3;
    --track: #e9edf0;
    --soft: #fafbfc;
    --soft-border: #edf0f4;
    --line: #eeeeee;
    --row-line: #f0f0f0;
    --row-hover: #fafafa;
    --th-bg: #b51f2a;
    --th-color: #ffffff;
    --th-line: #8f1821;
    --td-color: #444444;
    --name-color: #222222;
    --chip-bg: #f1f3f5;
    --chip-color: #555555;
    --bar-value: #1f2937;
    --bar-name: #424b56;
    --bar-jobs: #999999;
    --quality-track: #eef1f4;
    --quality-value: #374151;
    --score-hole: #ffffff;
    --score-number: #b51f2a;
    --shadow: 0 3px 10px rgba(31, 45, 61, .045);
}

body.dark-mode {
    --bg: #0f141b;
    --card: #151b23;
    --card-border: #2c3542;
    --text: #edf2f7;
    --text-strong: #edf2f7;
    --muted: #8f9bac;
    --input-bg: #202733;
    --input-border: #303947;
    --track: #2c3542;
    --soft: #202733;
    --soft-border: #303947;
    --line: #2c3542;
    --row-line: #2c3542;
    --row-hover: #202733;
    --th-bg: #b51f2a;
    --th-color: #ffffff;
    --th-line: #b51f2a;
    --td-color: #ffffff;
    --name-color: #ffffff;
    --chip-bg: #202733;
    --chip-color: #e8edf4;
    --bar-value: #ff8b91;
    --bar-name: #e8edf4;
    --bar-jobs: #8f9bac;
    --quality-track: #2c3542;
    --quality-value: #ff8b91;
    --score-hole: #151b23;
    --score-number: #ff5d66;
    --shadow: none;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html:has(body.dark-mode),
html:has(body.dark-mode) body {
    background: #0f141b !important;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
}

.main {
    margin-left: 240px;
    width: calc(100% - 240px);
    min-height: 100vh;
    min-width: 0;
    background: var(--bg) !important;
}

.kinerja-page,
.kinerja-page .content {
    background: var(--bg) !important;
}

.kinerja-page .content {
    padding: 24px 30px 35px;
}

.page-title {
    margin-bottom: 20px;
}

.page-title h2 {
    font-size: 24px;
    color: var(--text);
}

.page-title p {
    margin-top: 5px;
    color: var(--muted);
    font-size: 13px;
}

/* =========================================================
   CARD
========================================================= */
.filter-card,
.performance-card,
.chart-card,
.history-card {
    background: var(--card);
    border: 1px solid var(--card-border);
    border-radius: 12px;
    box-shadow: var(--shadow);
}

/* kartu muncul halus saat halaman dibuka */
.filter-card,
.performance-card,
.chart-card,
.history-card {
    animation: cardIn .55s ease both;
}

.performance-card { animation-delay: .06s; }
.chart-grid .chart-card:nth-child(1) { animation-delay: .12s; }
.chart-grid .chart-card:nth-child(2) { animation-delay: .18s; }
.history-card { animation-delay: .24s; }

@keyframes cardIn {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.filter-card {
    padding: 18px;
    margin-bottom: 18px;
}

.filter-title {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 13px;
    color: var(--text);
}

.filter-form {
    display: grid;
    grid-template-columns: 1fr 1fr 1.5fr auto;
    gap: 10px;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
}

.form-group select {
    width: 100%;
    height: 38px;
    padding: 0 11px;
    border: 1px solid var(--input-border);
    border-radius: 8px;
    background: var(--input-bg);
    color: var(--text);
    outline: none;
}

.form-group select:focus {
    border-color: #b51f2a;
}

.btn-filter {
    height: 38px;
    border: none;
    border-radius: 8px;
    padding: 0 18px;
    background: #b51f2a;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background .18s ease, transform .18s ease;
}

.btn-filter:hover {
    background: #941923;
    transform: translateY(-1px);
}

/* =========================================================
   NILAI KINERJA
========================================================= */
.performance-card {
    padding: 18px 20px;
    margin-bottom: 18px;
}

.performance-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.performance-head h3 {
    font-size: 19px;
    color: var(--text-strong);
}

.performance-head p {
    margin-top: 3px;
    color: var(--muted);
    font-size: 12px;
}

.period-label {
    font-size: 12px;
    color: var(--muted);
}

.performance-body {
    display: flex;
    align-items: center;
    gap: 22px;
    min-height: 135px;
}

.score-circle {
    width: 110px;
    height: 110px;
    min-width: 110px;
    border-radius: 50%;
    background: conic-gradient(
        #b51f2a 0deg,
        #e85d68 0deg,
        var(--track) 0deg,
        var(--track) 360deg
    );
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

.score-circle::before {
    content: "";
    position: absolute;
    width: 78px;
    height: 78px;
    border-radius: 50%;
    background: var(--score-hole);
}

.score-number {
    position: relative;
    z-index: 2;
    font-size: 24px;
    font-weight: 800;
    color: var(--score-number);
}

.score-info {
    min-width: 210px;
    max-width: 400px;
}

.score-info h4 {
    font-size: 17px;
    margin-bottom: 5px;
    color: var(--text-strong);
}

.score-info p {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.55;
}

.performance-status {
    margin-left: auto;
    display: grid;
    grid-template-columns: repeat(4, minmax(100px, 1fr));
    gap: 8px;
    width: 100%;
    max-width: 520px;
}

.small-status {
    padding: 10px 11px;
    border: 1px solid var(--soft-border);
    border-radius: 9px;
    background: var(--soft);
    min-height: 66px;
    transition: transform .18s ease, border-color .18s ease;
}

.small-status:hover {
    transform: translateY(-2px);
    border-color: #b51f2a;
}

.small-status-label {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.25;
}

.small-status-value {
    margin-top: 5px;
    font-size: 18px;
    font-weight: 700;
    color: var(--text-strong);
}

/* =========================================================
   GRAFIK
========================================================= */
.chart-grid {
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    gap: 15px;
    margin-bottom: 18px;
}

.chart-card {
    padding: 20px;
    min-width: 0;
}

.chart-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.chart-head h3 {
    font-size: 16px;
    color: var(--text);
}

.chart-head p {
    margin-top: 4px;
    color: var(--muted);
    font-size: 11px;
}

.bar-chart {
    height: 230px;
    display: flex;
    align-items: flex-end;
    gap: 15px;
    padding: 8px 6px 0;
    border-bottom: 1px solid var(--line);
}

.bar-item {
    flex: 1;
    min-width: 50px;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    animation: fadeUp .6s ease both;
    animation-delay: calc(var(--i, 0) * 90ms + 250ms);
}

.bar-value {
    margin-bottom: 5px;
    font-size: 10px;
    font-weight: 700;
    color: var(--bar-value);
}

.bar-area {
    width: 100%;
    max-width: 48px;
    height: 160px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.bar {
    width: 34px;
    min-height: 4px;
    border-radius: 7px 7px 2px 2px;
    background: #b51f2a;
    transform-origin: bottom;
    animation: growBar 1.1s cubic-bezier(.22, 1, .36, 1) both;
    animation-delay: calc(var(--i, 0) * 90ms + 250ms);
    transition: filter .18s ease, transform .18s ease;
}

.bar-item:hover .bar {
    filter: brightness(1.18);
    transform: scaleX(1.08);
}

.bar-name {
    margin-top: 7px;
    max-width: 80px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: 10px;
    font-weight: 600;
    color: var(--bar-name);
    text-align: center;
}

.bar-jobs {
    margin-top: 2px;
    font-size: 9px;
    color: var(--bar-jobs);
}

@keyframes growBar {
    from {
        height: 0;
    }
}

@keyframes growWidth {
    from {
        width: 0;
    }
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.quality-chart {
    height: 230px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 13px;
}

.quality-item {
    display: grid;
    grid-template-columns: 105px 1fr 42px;
    align-items: center;
    gap: 9px;
    animation: fadeUp .6s ease both;
    animation-delay: calc(var(--i, 0) * 100ms + 250ms);
}

.quality-name {
    font-size: 10px;
    font-weight: 600;
    color: var(--bar-name);
}

.quality-track {
    height: 10px;
    background: var(--quality-track);
    border-radius: 10px;
    overflow: hidden;
}

.quality-bar {
    height: 100%;
    border-radius: 10px;
    background: #b51f2a;
    animation: growWidth 1.1s cubic-bezier(.22, 1, .36, 1) both;
    animation-delay: calc(var(--i, 0) * 100ms + 300ms);
}

.quality-item:nth-child(4n+1) .quality-bar { background: #222222; }
.quality-item:nth-child(4n+2) .quality-bar { background: #b51f2a; }
.quality-item:nth-child(4n+3) .quality-bar { background: #8b929a; }
.quality-item:nth-child(4n+4) .quality-bar { background: #222222; }

body.dark-mode .quality-item:nth-child(1) .quality-bar { background: #35b86b; }
body.dark-mode .quality-item:nth-child(2) .quality-bar { background: #f2c94c; }
body.dark-mode .quality-item:nth-child(3) .quality-bar { background: #f2994a; }
body.dark-mode .quality-item:nth-child(4) .quality-bar { background: #e04b4b; }

.quality-value {
    font-size: 10px;
    font-weight: 700;
    color: var(--quality-value);
    text-align: right;
}

.quality-note {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.4;
    margin-top: 2px;
}

/* =========================================================
   RIWAYAT
========================================================= */
.history-card {
    overflow: hidden;
}

.history-head {
    padding: 18px 20px;
    border-bottom: 1px solid var(--line);
}

.history-head h3 {
    font-size: 16px;
    color: var(--text);
}

.history-head p {
    margin-top: 4px;
    color: var(--muted);
    font-size: 12px;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
    background: var(--card);
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

/* ===== BARIS JUDUL TABEL: sama dengan Laporan ===== */
th {
    background: var(--th-bg);
    color: var(--th-color);
    font-size: 13.5px;
    font-weight: 700;
    text-align: center;
    padding: 14px 16px;
    border-bottom: 1px solid var(--th-line);
    white-space: nowrap;
}

td {
    padding: 15px 16px;
    border-bottom: 1px solid var(--row-line);
    font-size: 13.5px;
    color: var(--td-color);
    background: var(--card);
    vertical-align: middle;
}

tbody tr:hover td {
    background: var(--row-hover);
}

tr:last-child td {
    border-bottom: none;
}

.job-name,
.employee-name {
    font-weight: 600;
    color: var(--name-color);
}

.order-type {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 6px;
    background: var(--chip-bg);
    color: var(--chip-color);
    font-size: 12px;
    font-weight: 600;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}

.status-belum     { background: #eef0f3; color: #667085; }
.status-sedang    { background: #edf3ff; color: #3f65b5; }
.status-menunggu  { background: #fff5dc; color: #a46b00; }
.status-perbaikan { background: #fde5e5; color: #c94242; }
.status-peralihan { background: #eee7ff; color: #7252b8; }
.status-selesai   { background: #e9f8f0; color: #168353; }

body.dark-mode .status-belum     { background: #34383e; color: #c5cbd3; }
body.dark-mode .status-sedang    { background: #1d3158; color: #8eb5ff; }
body.dark-mode .status-menunggu  { background: #3c3924; color: #f2d77d; }
body.dark-mode .status-perbaikan { background: #4b2d32; color: #ff8b92; }
body.dark-mode .status-peralihan { background: #2f2748; color: #b9a2f0; }
body.dark-mode .status-selesai   { background: #183d2a; color: #72d89a; }

.empty {
    padding: 35px 20px;
    text-align: center;
    color: var(--muted);
    font-size: 13px;
}

.footer {
    padding: 18px 30px 25px;
    color: #999999;
    font-size: 11px;
    text-align: center;
}

body.dark-mode .footer {
    color: #747e8c;
}

/* =========================================================
   RESPONSIVE
========================================================= */
@media (max-width: 1200px) {
    .chart-grid {
        grid-template-columns: 1fr;
    }
    .performance-body {
        flex-wrap: wrap;
    }
    .performance-status {
        margin-left: 0;
        max-width: none;
    }
}

@media (max-width: 1000px) {
    .filter-form {
        grid-template-columns: 1fr 1fr;
    }
    .performance-status {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 800px) {
    .main {
        margin-left: 0;
        width: 100%;
    }
    .kinerja-page .content {
        padding: 18px;
    }
    .filter-form {
        grid-template-columns: 1fr;
    }
    .performance-body {
        flex-direction: column;
        align-items: flex-start;
    }
    .performance-status {
        width: 100%;
    }
}

@media (max-width: 600px) {
    .performance-status {
        grid-template-columns: 1fr 1fr;
    }
    .performance-head {
        flex-direction: column;
    }
    .period-label {
        text-align: left;
    }
    .bar-chart {
        gap: 8px;
    }
    .bar {
        width: 28px;
    }
    .footer {
        padding-left: 18px;
        padding-right: 18px;
    }
}

/* hormati pengaturan "kurangi gerakan" */
@media (prefers-reduced-motion: reduce) {
    .filter-card,
    .performance-card,
    .chart-card,
    .history-card,
    .bar-item,
    .bar,
    .quality-item,
    .quality-bar {
        animation: none !important;
    }
}

</style>
</head>
<body>

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="kinerja-page">

<div class="content">

<div class="page-title">
    <h2>Kinerja Karyawan</h2>
    <p>Melihat kinerja dan kualitas hasil kerja karyawan dalam divisi.</p>
</div>

<div class="filter-card">

    <div class="filter-title">Filter Kinerja</div>

    <form method="GET" class="filter-form">

        <div class="form-group">
            <label>Bulan</label>
            <select name="bulan">
                <?php foreach ($nama_bulan as $nomor => $nama): ?>
                    <option value="<?= $nomor; ?>" <?= $bulan == $nomor ? "selected" : ""; ?>>
                        <?= h($nama); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Tahun</label>
            <select name="tahun">
                <?php
                $tahun_sekarang = intval(date("Y"));
                for ($i = $tahun_sekarang - 2; $i <= $tahun_sekarang + 1; $i++):
                ?>
                    <option value="<?= $i; ?>" <?= $tahun == $i ? "selected" : ""; ?>>
                        <?= $i; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Karyawan</label>
            <select name="id_karyawan">
                <option value="0">Semua Karyawan</option>
                <?php if ($query_karyawan): ?>
                    <?php while ($karyawan = mysqli_fetch_assoc($query_karyawan)): ?>
                        <option
                            value="<?= intval($karyawan["id_user"]); ?>"
                            <?= $id_karyawan == intval($karyawan["id_user"]) ? "selected" : ""; ?>
                        >
                            <?= h($karyawan["nama"]); ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
        </div>

        <button type="submit" class="btn-filter">Tampilkan</button>

    </form>

</div>

<div class="performance-card">

    <div class="performance-head">
        <div>
            <h3>Kinerja <?= h($nama_filter); ?></h3>
            <p><?= h($nama_divisi); ?></p>
        </div>
        <div class="period-label">
            <?= h($nama_bulan[$bulan]); ?> <?= $tahun; ?>
        </div>
    </div>

    <div class="performance-body">

        <div class="score-circle" id="scoreCircle">
            <div class="score-number" id="scoreNumber" data-score="<?= $kinerja; ?>">0%</div>
        </div>

        <div class="score-info">
            <h4>Nilai Kinerja</h4>
            <p>Nilai rata-rata kinerja berdasarkan pekerjaan pada periode yang dipilih.</p>
        </div>

        <div class="performance-status">

            <div class="small-status">
                <div class="small-status-label">Selesai</div>
                <div class="small-status-value" data-count="<?= $selesai; ?>"><?= $selesai; ?></div>
            </div>

            <div class="small-status">
                <div class="small-status-label">Menunggu Konfirmasi</div>
                <div class="small-status-value" data-count="<?= $menunggu; ?>"><?= $menunggu; ?></div>
            </div>

            <div class="small-status">
                <div class="small-status-label">Perlu Perbaikan</div>
                <div class="small-status-value" data-count="<?= $perbaikan; ?>"><?= $perbaikan; ?></div>
            </div>

            <div class="small-status">
                <div class="small-status-label">Belum Dikerjakan</div>
                <div class="small-status-value" data-count="<?= $belum; ?>"><?= $belum; ?></div>
            </div>

        </div>

    </div>

</div>

<div class="chart-grid">

<div class="chart-card">

    <div class="chart-head">
        <div>
            <h3>Nilai Kinerja Karyawan</h3>
            <p>Perbandingan nilai kinerja pada periode terpilih.</p>
        </div>
    </div>

    <?php if (count($kinerja_karyawan) > 0): ?>

        <div class="bar-chart">

            <?php foreach ($kinerja_karyawan as $index => $data_kinerja): ?>

                <?php $nilai = max(0, min(100, intval($data_kinerja["nilai"]))); ?>

                <div class="bar-item" style="--i: <?= $index; ?>;">

                    <div class="bar-value" data-count="<?= $nilai; ?>" data-suffix="%"><?= $nilai; ?>%</div>

                    <div class="bar-area">
                        <div class="bar" style="height: <?= max(4, $nilai); ?>%; --i: <?= $index; ?>;"></div>
                    </div>

                    <div class="bar-name" title="<?= h($data_kinerja["nama"]); ?>">
                        <?= h($data_kinerja["nama"]); ?>
                    </div>

                    <div class="bar-jobs"><?= intval($data_kinerja["jumlah"]); ?> pekerjaan</div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">Belum ada data kinerja karyawan pada periode ini.</div>

    <?php endif; ?>

</div>

<div class="chart-card">

    <div class="chart-head">
        <div>
            <h3>Kualitas Hasil Kerja</h3>
            <p>Kategori kualitas berdasarkan nilai kinerja karyawan.</p>
        </div>
    </div>

    <?php if ($total_kategori > 0): ?>

        <div class="quality-chart">

            <?php $urutan = 0; ?>

            <?php foreach ($kategori_kualitas as $nama_kategori => $jumlah_kategori): ?>

                <?php $persen_kategori = round(($jumlah_kategori / $total_kategori) * 100); ?>

                <div class="quality-item" style="--i: <?= $urutan; ?>;">

                    <div class="quality-name"><?= h($nama_kategori); ?></div>

                    <div class="quality-track">
                        <div class="quality-bar" style="width: <?= $persen_kategori; ?>%; --i: <?= $urutan; ?>;"></div>
                    </div>

                    <div class="quality-value" data-count="<?= $jumlah_kategori; ?>"><?= $jumlah_kategori; ?></div>

                </div>

                <?php $urutan++; ?>

            <?php endforeach; ?>

            <div class="quality-note">
                Sangat Baik: 90–100% · Baik: 75–89% · Perlu Ditingkatkan: 60–74% · Kurang: &lt;60%.
            </div>

        </div>

    <?php else: ?>

        <div class="empty">Belum ada data kualitas hasil kerja pada periode ini.</div>

    <?php endif; ?>

</div>

</div>

<div class="history-card">

    <div class="history-head">
        <h3>Riwayat Pekerjaan</h3>
        <p>
            <?= h($nama_filter); ?> · <?= h($nama_bulan[$bulan]); ?> <?= $tahun; ?>
        </p>
    </div>

    <div class="table-wrap">

        <?php if ($query_riwayat && mysqli_num_rows($query_riwayat) > 0): ?>

            <table>

                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Pekerjaan</th>
                        <th>Jenis</th>
                        <th>Tipe Order</th>
                        <th>No. Order</th>
                        <th>Karyawan</th>
                        <th>Tanggal Mulai</th>
                        <th>Target Selesai</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php
                    $no = 1;
                    while ($row = mysqli_fetch_assoc($query_riwayat)):
                        $status = statusTampil($row["status"]);
                    ?>

                        <tr>

                            <td><?= $no++; ?></td>

                            <td>
                                <span class="job-name"><?= h($row["nama_pekerjaan"]); ?></span>
                            </td>

                            <td>
                                <?= h($row["jenis_pekerjaan"] ?? "-"); ?>
                            </td>

                            <td>
                                <span class="order-type"><?= h($row["tipe_order"] ?? "-"); ?></span>
                            </td>

                            <td>
                                <?= !empty($row["nomor_order"]) ? h($row["nomor_order"]) : "-"; ?>
                            </td>

                            <td>
                                <span class="employee-name"><?= h($row["nama_karyawan"] ?? "-"); ?></span>
                            </td>

                            <td>
                                <?= !empty($row["tanggal_mulai"])
                                    ? date("d/m/Y", strtotime($row["tanggal_mulai"]))
                                    : "-"; ?>
                            </td>

                            <td>
                                <?= !empty($row["target_selesai"])
                                    ? date("d/m/Y", strtotime($row["target_selesai"]))
                                    : "-"; ?>
                            </td>

                            <td>
                                <span class="status <?= getStatusClass($status); ?>">
                                    <?= h($status); ?>
                                </span>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                </tbody>

            </table>

        <?php else: ?>

            <div class="empty">Tidak ada riwayat pekerjaan pada periode yang dipilih.</div>

        <?php endif; ?>

    </div>

</div>

</div>

<div class="footer">
    SIMO — Sistem Informasi Monitoring
</div>

</div>

</div>

<script>

/* tema (sama dengan halaman lain) */
const savedTheme = localStorage.getItem("simo-theme") || "dark";
document.body.classList.toggle("dark-mode", savedTheme === "dark");

document.addEventListener("DOMContentLoaded", function () {

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function easeOut(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    /* ---------- DONUT NILAI KINERJA ---------- */
    const circle = document.getElementById("scoreCircle");
    const number = document.getElementById("scoreNumber");

    if (circle && number) {

        const target = parseInt(number.dataset.score || "0", 10);
        const duration = reduceMotion ? 1 : 1200;
        const delay = 150;
        let startTime = null;

        function trackColor() {
            return getComputedStyle(document.body).getPropertyValue("--track").trim() || "#e9edf0";
        }

        function drawDonut(current) {
            const degree = current * 3.6;
            circle.style.background =
                "conic-gradient(#b51f2a 0deg, #e85d68 " + degree + "deg, " +
                trackColor() + " " + degree + "deg 360deg)";
            number.textContent = current + "%";
        }

        function animateDonut(now) {

            if (startTime === null) {
                startTime = now + delay;
            }

            if (now < startTime) {
                requestAnimationFrame(animateDonut);
                return;
            }

            const progress = Math.min((now - startTime) / duration, 1);
            drawDonut(Math.round(target * easeOut(progress)));

            if (progress < 1) {
                requestAnimationFrame(animateDonut);
            }
        }

        drawDonut(0);
        requestAnimationFrame(animateDonut);
    }

    /* ---------- ANGKA BERJALAN (count-up) ---------- */
    document.querySelectorAll("[data-count]").forEach(function (el, index) {

        const target = parseInt(el.dataset.count || "0", 10);
        const suffix = el.dataset.suffix || "";

        if (reduceMotion) {
            return;
        }

        const duration = 1100;
        const delay = 250 + (index % 8) * 70;
        let startTime = null;

        el.textContent = "0" + suffix;

        function step(now) {

            if (startTime === null) {
                startTime = now + delay;
            }

            if (now < startTime) {
                requestAnimationFrame(step);
                return;
            }

            const progress = Math.min((now - startTime) / duration, 1);
            el.textContent = Math.round(target * easeOut(progress)) + suffix;

            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    });

});

</script>

</body>
</html>
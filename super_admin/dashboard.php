<?php

include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "Super Admin"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

function h($value)
{
    return htmlspecialchars((string)($value ?? "-"), ENT_QUOTES, "UTF-8");
}

function get_count($koneksi, $sql)
{
    $q = mysqli_query($koneksi, $sql);

    if (!$q) return 0;

    $r = mysqli_fetch_assoc($q);

    return (int)($r["total"] ?? 0);
}

function relative_time($date)
{
    if (empty($date)) return "-";

    $time = strtotime($date);

    if (!$time) return "-";

    $diff = time() - $time;

    if ($diff < 60) return "Baru saja";
    if ($diff < 3600) return floor($diff / 60) . " menit lalu";
    if ($diff < 86400) return floor($diff / 3600) . " jam lalu";
    if ($diff < 2592000) return floor($diff / 86400) . " hari lalu";

    return date("d/m/Y", $time);
}

function format_tanggal_waktu($tanggal)
{
    if (empty($tanggal)) return "-";

    $time = strtotime($tanggal);

    if (!$time) return "-";

    return date("d-m-Y, H:i", $time);
}

function persen_perubahan($sekarang, $sebelumnya)
{
    if ((int)$sebelumnya === 0) {
        return $sekarang > 0 ? 100 : 0;
    }

    return round((($sekarang - $sebelumnya) / $sebelumnya) * 100);
}

$hari = [
    "Sunday" => "Minggu", "Monday" => "Senin", "Tuesday" => "Selasa",
    "Wednesday" => "Rabu", "Thursday" => "Kamis", "Friday" => "Jumat",
    "Saturday" => "Sabtu"
];

$bulan = [
    "01" => "Januari", "02" => "Februari", "03" => "Maret", "04" => "April",
    "05" => "Mei", "06" => "Juni", "07" => "Juli", "08" => "Agustus",
    "09" => "September", "10" => "Oktober", "11" => "November", "12" => "Desember"
];

$bulan_singkat = [
    "01" => "Jan", "02" => "Feb", "03" => "Mar", "04" => "Apr",
    "05" => "Mei", "06" => "Jun", "07" => "Jul", "08" => "Agu",
    "09" => "Sep", "10" => "Okt", "11" => "Nov", "12" => "Des"
];

$tanggal_hari_ini =
    $hari[date("l")] . ", " . date("j") . " " .
    $bulan[date("m")] . " " . date("Y");

/* =========================================================
   HELPER DROPDOWN PERIODE
========================================================= */

function parse_periode($str)
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$str, $mm)) return null;

    $y = (int)$mm[1];
    $m = (int)$mm[2];

    if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) return null;

    return [$y, str_pad($m, 2, "0", STR_PAD_LEFT)];
}

function hitung_pekerjaan($koneksi, $y, $m, $selesai = false)
{
    $y = (int)$y;
    $m = (int)$m;

    $sql = "SELECT COUNT(*) AS total FROM pekerjaan
            WHERE MONTH(tanggal_mulai)='$m'
            AND YEAR(tanggal_mulai)='$y'";

    if ($selesai) {
        $sql .= " AND status='Selesai'";
    }

    return get_count($koneksi, $sql);
}

function periode_select($id, $opsi, $aria)
{
    $html = '<select class="period-select" id="' . h($id) . '"'
          . ' aria-label="' . h($aria) . '"'
          . ' data-current="' . h($opsi[0]["value"]) . '">';

    foreach ($opsi as $o) {
        $html .= '<option value="' . h($o["value"]) . '">'
               . h($o["label"]) . '</option>';
    }

    return $html . '</select>';
}

/* =========================================================
   ENDPOINT AJAX (dropdown periode pada grafik)
========================================================= */

if (isset($_GET["ajax"])) {

    header("Content-Type: application/json; charset=UTF-8");

    $jenis = $_GET["ajax"];
    $out = ["ok" => false];

    if ($jenis === "overview") {

        $y = (int)($_GET["tahun"] ?? 0);

        if ($y >= 2000 && $y <= 2100) {

            $values = array_fill(0, 12, 0);

            $q = mysqli_query(
                $koneksi,
                "SELECT MONTH(tanggal_mulai) AS bln, COUNT(*) AS total
                 FROM pekerjaan
                 WHERE YEAR(tanggal_mulai)='$y'
                 GROUP BY MONTH(tanggal_mulai)"
            );

            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    $idx = (int)$r["bln"] - 1;
                    if ($idx >= 0 && $idx < 12) {
                        $values[$idx] = (int)$r["total"];
                    }
                }
            }

            $out = ["ok" => true, "tahun" => $y, "values" => $values];
        }

    } else {

        $p = parse_periode($_GET["periode"] ?? "");

        if ($p) {

            [$y, $m] = $p;

            $label = $bulan[$m] . " " . $y;

            if ($jenis === "progress") {

                $dibuat  = hitung_pekerjaan($koneksi, $y, $m);
                $selesai = hitung_pekerjaan($koneksi, $y, $m, true);

                $tp = mktime(0, 0, 0, (int)$m - 1, 1, $y);
                $py = (int)date("Y", $tp);
                $pm = (int)date("n", $tp);

                $dibuat_p  = hitung_pekerjaan($koneksi, $py, $pm);
                $selesai_p = hitung_pekerjaan($koneksi, $py, $pm, true);

                $out = [
                    "ok" => true,
                    "label" => $label,
                    "dibuat" => $dibuat,
                    "selesai" => $selesai,
                    "persen" => $dibuat > 0 ? round(($selesai / $dibuat) * 100) : 0,
                    "perubahan_dibuat" => persen_perubahan($dibuat, $dibuat_p),
                    "perubahan_selesai" => persen_perubahan($selesai, $selesai_p)
                ];

            } elseif ($jenis === "divisi") {

                $items = [];

                $q = mysqli_query(
                    $koneksi,
                    "SELECT d.nama_divisi, COUNT(p.id_pekerjaan) AS jumlah
                     FROM divisi d
                     LEFT JOIN pekerjaan p
                        ON p.id_divisi=d.id_divisi
                        AND MONTH(p.tanggal_mulai)='" . (int)$m . "'
                        AND YEAR(p.tanggal_mulai)='" . (int)$y . "'
                     GROUP BY d.id_divisi, d.nama_divisi
                     ORDER BY jumlah DESC, d.nama_divisi ASC"
                );

                if ($q) {
                    while ($r = mysqli_fetch_assoc($q)) {
                        $items[] = [
                            "nama" => $r["nama_divisi"],
                            "jumlah" => (int)$r["jumlah"]
                        ];
                    }
                }

                $out = ["ok" => true, "label" => $label, "items" => $items];

            } elseif ($jenis === "status") {

                $st = [
                    "Belum Dikerjakan" => 0,
                    "Sedang Dikerjakan" => 0,
                    "Menunggu Konfirmasi" => 0,
                    "Perlu Perbaikan" => 0,
                    "Peralihan" => 0,
                    "Selesai" => 0
                ];

                $q = mysqli_query(
                    $koneksi,
                    "SELECT status, COUNT(*) AS jumlah
                     FROM pekerjaan
                     WHERE MONTH(tanggal_mulai)='" . (int)$m . "'
                     AND YEAR(tanggal_mulai)='" . (int)$y . "'
                     GROUP BY status"
                );

                if ($q) {
                    while ($r = mysqli_fetch_assoc($q)) {
                        if (isset($st[$r["status"]])) {
                            $st[$r["status"]] = (int)$r["jumlah"];
                        }
                    }
                }

                $out = [
                    "ok" => true,
                    "label" => $label,
                    "status" => $st,
                    "total" => array_sum($st)
                ];
            }
        }
    }

    echo json_encode($out);
    exit;
}

/* =========================================================
   PERIODE BERJALAN
   Grafik (kecuali Overview tahunan) hanya menghitung
   bulan berjalan, sehingga otomatis kembali dari 0
   setiap pergantian bulan.
========================================================= */

$bulan_ini = date("m");
$tahun_ini = date("Y");

$bulan_lalu = date("m", strtotime("-1 month"));
$tahun_lalu = date("Y", strtotime("-1 month"));

$label_bulan = $bulan[$bulan_ini] . " " . $tahun_ini;

/* Pilihan dropdown: dimulai dari bulan/tahun data paling awal
   yang ada di tabel pekerjaan, sampai bulan/tahun berjalan. */
$ts_awal = 0;

$q_awal = mysqli_query(
    $koneksi,
    "SELECT MIN(tanggal_mulai) AS awal
     FROM pekerjaan
     WHERE tanggal_mulai IS NOT NULL
     AND tanggal_mulai > '2000-01-01'"
);

if ($q_awal) {
    $r_awal = mysqli_fetch_assoc($q_awal);
    $ts_awal = !empty($r_awal["awal"]) ? (int)strtotime($r_awal["awal"]) : 0;
}

if ($ts_awal <= 0 || $ts_awal > time()) {
    $ts_awal = time();
}

$tahun_awal = (int)date("Y", $ts_awal);
$bulan_awal = (int)date("n", $ts_awal);

$jumlah_bulan = ((int)date("Y") - $tahun_awal) * 12
              + ((int)date("n") - $bulan_awal);

$jumlah_bulan = max(0, min($jumlah_bulan, 240));

$opsi_periode = [];

for ($i = 0; $i <= $jumlah_bulan; $i++) {
    $t = mktime(0, 0, 0, (int)date("n") - $i, 1, (int)date("Y"));
    $opsi_periode[] = [
        "value" => date("Y-m", $t),
        "label" => $bulan[date("m", $t)] . " " . date("Y", $t)
    ];
}

$opsi_tahun = [];

for ($y = (int)$tahun_ini; $y >= $tahun_awal; $y--) {
    $opsi_tahun[] = ["value" => $y, "label" => $y];
}

/* =========================================================
   USER LOGIN
========================================================= */

$id_user = (int)$_SESSION["id_user"];

$q_user = mysqli_query(
    $koneksi,
    "SELECT nama FROM users WHERE id_user='$id_user' LIMIT 1"
);

if (!$q_user || mysqli_num_rows($q_user) === 0) {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$user_login = mysqli_fetch_assoc($q_user);

$nama_super_admin = $user_login["nama"] ?? "Super Admin";

/* =========================================================
   KPI
========================================================= */

$total_divisi = get_count($koneksi, "SELECT COUNT(*) AS total FROM divisi");

$total_karyawan = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM users
     WHERE role='Karyawan' AND status='Aktif'"
);

$total_pekerjaan = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan"
);

$total_selesai = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan WHERE status='Selesai'"
);

$total_belum_selesai = max(0, $total_pekerjaan - $total_selesai);

/* =========================================================
   PERUBAHAN BULAN INI
========================================================= */

$dibuat_ini = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan
     WHERE MONTH(tanggal_mulai)='$bulan_ini'
     AND YEAR(tanggal_mulai)='$tahun_ini'"
);

$dibuat_lalu = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan
     WHERE MONTH(tanggal_mulai)='$bulan_lalu'
     AND YEAR(tanggal_mulai)='$tahun_lalu'"
);

$selesai_ini = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan
     WHERE status='Selesai'
     AND MONTH(tanggal_mulai)='$bulan_ini'
     AND YEAR(tanggal_mulai)='$tahun_ini'"
);

$selesai_lalu = get_count(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan
     WHERE status='Selesai'
     AND MONTH(tanggal_mulai)='$bulan_lalu'
     AND YEAR(tanggal_mulai)='$tahun_lalu'"
);

$perubahan_dibuat = persen_perubahan($dibuat_ini, $dibuat_lalu);
$perubahan_selesai = persen_perubahan($selesai_ini, $selesai_lalu);

/* =========================================================
   PROGRESS BULAN INI (donut)
========================================================= */

$persentase_selesai = $dibuat_ini > 0
    ? round(($selesai_ini / $dibuat_ini) * 100)
    : 0;

/* =========================================================
   OVERVIEW JAN-DES TAHUN BERJALAN
   (1 query saja, sebelumnya 12 query)
========================================================= */

$overview_labels = [];
$overview_values = array_fill(0, 12, 0);

for ($i = 1; $i <= 12; $i++) {
    $m = str_pad($i, 2, "0", STR_PAD_LEFT);
    $overview_labels[] = $bulan_singkat[$m];
}

$q_overview = mysqli_query(
    $koneksi,
    "SELECT MONTH(tanggal_mulai) AS bln, COUNT(*) AS total
     FROM pekerjaan
     WHERE YEAR(tanggal_mulai)='" . (int)$tahun_ini . "'
     GROUP BY MONTH(tanggal_mulai)"
);

if ($q_overview) {
    while ($r = mysqli_fetch_assoc($q_overview)) {
        $idx = (int)$r["bln"] - 1;
        if ($idx >= 0 && $idx < 12) {
            $overview_values[$idx] = (int)$r["total"];
        }
    }
}

/* =========================================================
   PEKERJAAN PER DIVISI (BULAN BERJALAN)
========================================================= */

$divisi_data = [];

$q_divisi = mysqli_query(
    $koneksi,
    "SELECT
        d.nama_divisi,
        COUNT(p.id_pekerjaan) AS jumlah
     FROM divisi d
     LEFT JOIN pekerjaan p
        ON p.id_divisi=d.id_divisi
        AND MONTH(p.tanggal_mulai)='$bulan_ini'
        AND YEAR(p.tanggal_mulai)='$tahun_ini'
     GROUP BY d.id_divisi, d.nama_divisi
     ORDER BY jumlah DESC, d.nama_divisi ASC"
);

if ($q_divisi) {
    while ($r = mysqli_fetch_assoc($q_divisi)) {
        $divisi_data[] = [
            "nama" => $r["nama_divisi"],
            "jumlah" => (int)$r["jumlah"]
        ];
    }
}

$max_divisi = 1;

foreach ($divisi_data as $item) {
    $max_divisi = max($max_divisi, $item["jumlah"]);
}

/* =========================================================
   KARYAWAN PER DIVISI
========================================================= */

$karyawan_data = [];

$q_karyawan = mysqli_query(
    $koneksi,
    "SELECT
        d.nama_divisi,
        COUNT(u.id_user) AS jumlah
     FROM divisi d
     LEFT JOIN users u
        ON u.id_divisi=d.id_divisi
        AND u.role='Karyawan'
        AND u.status='Aktif'
     GROUP BY d.id_divisi, d.nama_divisi
     ORDER BY jumlah DESC, d.nama_divisi ASC"
);

if ($q_karyawan) {
    while ($r = mysqli_fetch_assoc($q_karyawan)) {
        $karyawan_data[] = [
            "nama" => $r["nama_divisi"],
            "jumlah" => (int)$r["jumlah"]
        ];
    }
}

$max_karyawan = 1;

foreach ($karyawan_data as $item) {
    $max_karyawan = max($max_karyawan, $item["jumlah"]);
}

/* =========================================================
   STATUS PEKERJAAN (BULAN BERJALAN)
========================================================= */

$status = [
    "Belum Dikerjakan" => 0,
    "Sedang Dikerjakan" => 0,
    "Menunggu Konfirmasi" => 0,
    "Perlu Perbaikan" => 0,
    "Peralihan" => 0,
    "Selesai" => 0
];

$q_status = mysqli_query(
    $koneksi,
    "SELECT status, COUNT(*) AS jumlah
     FROM pekerjaan
     WHERE MONTH(tanggal_mulai)='$bulan_ini'
     AND YEAR(tanggal_mulai)='$tahun_ini'
     GROUP BY status"
);

if ($q_status) {
    while ($r = mysqli_fetch_assoc($q_status)) {
        if (isset($status[$r["status"]])) {
            $status[$r["status"]] = (int)$r["jumlah"];
        }
    }
}

$total_status_bulan = array_sum($status);

/* =========================================================
   AKTIVITAS TERBARU
========================================================= */

$aktivitas = [];

$q_aktivitas = mysqli_query(
    $koneksi,
    "SELECT
        p.id_pekerjaan,
        p.nama_pekerjaan,
        p.jenis_pekerjaan,
        p.tipe_order,
        p.nomor_order,
        p.status,
        p.tanggal_mulai,
        p.target_selesai,
        d.nama_divisi,
        GROUP_CONCAT(
            DISTINCT u.nama
            ORDER BY pk.id ASC
            SEPARATOR ', '
        ) AS nama_tim
     FROM pekerjaan p
     LEFT JOIN divisi d ON d.id_divisi=p.id_divisi
     LEFT JOIN pekerjaan_karyawan pk
        ON pk.id_pekerjaan=p.id_pekerjaan
        AND pk.status_penugasan IN ('Aktif','Peralihan')
     LEFT JOIN users u ON u.id_user=pk.id_user
     GROUP BY
        p.id_pekerjaan,
        p.nama_pekerjaan,
        p.jenis_pekerjaan,
        p.tipe_order,
        p.nomor_order,
        p.status,
        p.tanggal_mulai,
        p.target_selesai,
        d.nama_divisi
     ORDER BY p.id_pekerjaan DESC
     LIMIT 5"
);

if ($q_aktivitas) {
    while ($r = mysqli_fetch_assoc($q_aktivitas)) {
        $aktivitas[] = $r;
    }
}

function status_badge_class($status)
{
    switch ($status) {
        case "Selesai": return "selesai";
        case "Peralihan": return "peralihan";
        case "Menunggu Konfirmasi": return "menunggu";
        case "Perlu Perbaikan": return "perbaikan";
        case "Sedang Dikerjakan": return "sedang";
        default: return "belum";
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Super Admin - SIMOME</title>

<!-- defer: tidak memblokir tampilan halaman saat Chart.js diunduh -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>

<style>

*{box-sizing:border-box;margin:0;padding:0}

body{
    font-family:"Segoe UI",Arial,sans-serif;
    background:#fff;
    color:#172033;
}

a{text-decoration:none;color:inherit}

.main{
    margin-left:240px;
    width:calc(100% - 240px);
    min-height:100vh;
}

.dashboard-content{padding:24px 26px 36px}

/* HEADER */
.dashboard-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
}

.dashboard-head h1{
    font-size:28px;
    font-weight:700;
    line-height:1.2;
    color:#172033;
}

.dashboard-head p{display:none}

.date-box{
    background:#fff;
    border:1px solid #d9dde2;
    border-radius:10px;
    padding:10px 14px;
    font-size:13px;
    color:#667085;
}

/* KPI */
.kpi-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:16px;
}

.kpi-card{
    background:#fff;
    border:1px solid #f0c3c7; /* mode terang: merah soft */
    border-radius:12px;
    padding:16px 18px;
    height:108px;
    min-height:108px;
    position:relative;
    box-shadow:0 1px 4px rgba(16,24,40,.04);
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
}

.kpi-card:hover{
    transform:translateY(-2px);
    border-color:#e5a0a6;
    box-shadow:0 5px 14px rgba(16,24,40,.08);
}

.kpi-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.kpi-label{
    font-size:14px;
    font-weight:500;
    color:#344054;
}

.kpi-icon{
    width:28px;
    height:28px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#b51f2a;
}

.kpi-icon svg{width:21px;height:21px}

.kpi-bottom{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:10px;
}

.kpi-number{
    font-size:28px;
    font-weight:700;
    color:#172033;
    line-height:1;
    letter-spacing:-.5px;
    margin:0;
}

.kpi-foot{
    font-size:11px;
    color:#7a8596;
    margin:0 0 1px 0;
    white-space:nowrap;
}

.kpi-foot .up{
    color:#b51f2a;
    font-weight:600;
}

/* CARD UMUM */
.card{
    background:#fff;
    border:1px solid #d9dde2;
    border-radius:14px;
    box-shadow:0 1px 3px rgba(16,24,40,.04);
    overflow:hidden;
}

.card-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    padding:18px 20px 7px;
}

.card-title{min-width:0}

.card-title h2{
    font-size:17px;
    font-weight:700;
    color:#172033;
    line-height:1.25;
}

.card-title p{
    font-size:12px;
    color:#7a8596;
    margin-top:4px;
}

.card-menu{
    font-size:21px;
    color:#9aa3b1;
    line-height:1;
}

/* BARIS GRAFIK ATAS */
.top-chart-grid{
    display:grid;
    grid-template-columns:minmax(0,1.8fr) minmax(330px,.9fr);
    gap:14px;
    margin-bottom:14px;
}

.overview-card{min-height:300px}

.overview-chart-wrap{
    height:220px;
    padding:5px 17px 16px;
}

/* PROGRESS */
.progress-card{min-height:300px}

.progress-wrap{
    display:grid;
    grid-template-columns:1fr 1fr;
    align-items:center;
    min-height:226px;
    padding:14px 22px 24px;
    gap:10px;
}

.progress-chart{
    height:190px;
    position:relative;
}

.progress-chart canvas{
    width:100%!important;
    height:100%!important;
}

.progress-center{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    pointer-events:none;
}

.progress-center strong{
    font-size:32px;
    font-weight:800;
    color:#172033;
    line-height:1;
}

.progress-center span{
    font-size:12px;
    color:#8a93a3;
    margin-top:6px;
}

.progress-info{
    border-left:1px solid #edeff2;
    padding-left:22px;
    display:flex;
    flex-direction:column;
}

.progress-stat{
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px 0;
}

.progress-stat:not(:last-child){border-bottom:1px solid #f1f2f5}

.progress-stat-dot{
    width:11px;
    height:11px;
    border-radius:4px;
    flex-shrink:0;
}

.progress-stat-body strong{
    display:block;
    font-size:23px;
    font-weight:800;
    line-height:1;
    color:#172033;
}

.progress-stat-body span{
    display:block;
    font-size:12px;
    color:#8a93a3;
    margin-top:5px;
}

.green-badge{
    display:inline-flex!important;
    align-items:center;
    gap:2px;
    background:#e6f8ee;
    color:#16a36a!important;
    border-radius:20px;
    font-size:10.5px!important;
    font-weight:700;
    padding:4px 10px;
    margin-top:8px;
}

/* 3 KOLOM BAWAH */
.three-grid{
    display:grid;
    grid-template-columns:1.05fr 1.05fr .95fr;
    gap:14px;
    margin-bottom:14px;
}

.list-card{min-height:295px}

.rank-list{padding:10px 18px 18px}

.rank-row{
    display:grid;
    grid-template-columns:112px 1fr 30px;
    gap:9px;
    align-items:center;
    margin:10px 0;
}

.rank-name{
    font-size:12px;
    color:#344054;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.rank-track{
    height:9px;
    background:#f0f2f4;
    border-radius:999px;
    overflow:hidden;
}

.rank-fill{
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#172033 0%,#3b4350 100%);
}

.rank-value{
    text-align:right;
    font-size:12px;
    color:#344054;
    font-weight:600;
}

/* STATUS */
.status-card{min-height:295px}

.status-wrap{padding:15px 18px 18px}

.status-stack{
    display:flex;
    height:25px;
    border-radius:8px;
    overflow:hidden;
    background:#f0f2f4;
    margin:10px 0 16px;
}

.status-segment{
    height:100%;
    min-width:2px;
}

.status-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:12px 10px;
}

.status-item{min-width:0}

.status-percent{
    font-size:17px;
    font-weight:700;
    color:#172033;
}

.status-label{
    font-size:10px;
    color:#778195;
    margin-top:2px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.status-bottom{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    border-top:1px solid #edf0f3;
    margin-top:15px;
    padding-top:13px;
}

.status-count{
    display:flex;
    align-items:center;
    gap:5px;
    font-size:10px;
    color:#667085;
}

.status-dot{
    width:8px;
    height:8px;
    border-radius:50%;
    display:inline-block;
}

/* AKTIVITAS */
.activity-card{margin-bottom:14px}

.activity-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:17px 18px 11px;
}

.view-all{
    font-size:11px;
    color:#b31824;
    border:1px solid #d9dde2;
    border-radius:7px;
    padding:7px 10px;
    background:#fff8f8;
}

.activity-table-wrap{
    overflow-x:auto;
    padding:0 14px 14px;
}

.activity-table{
    width:100%;
    border-collapse:collapse;
    min-width:980px;
}

.activity-table td.nowrap{white-space:nowrap}

/* HEADER TABEL: MERAH (mode terang & gelap) */
.activity-table th{
    background:#b51f2a;
    color:#fff;
    font-size:12px;
    font-weight:600;
    text-align:left;
    padding:10px;
    border-bottom:1px solid #b51f2a;
}

.activity-table th:first-child{border-top-left-radius:8px}
.activity-table th:last-child{border-top-right-radius:8px}

.activity-table td{
    font-size:13.5px;
    color:#172033;
    padding:11px 10px;
    border-bottom:1px solid #eef0f3;
    vertical-align:middle;
}

.activity-table tr:last-child td{border-bottom:none}

.activity-no{
    width:35px;
    color:#172033!important;
}

/* teks "No" di header tetap putih */
.activity-table th.activity-no{color:#fff!important}

.activity-name{
    font-weight:600;
    color:#172033!important;
}

.activity-team{
    max-width:270px;
    line-height:1.4;
}

.badge{
    display:inline-flex;
    padding:4px 8px;
    border-radius:6px;
    font-size:11px;
    font-weight:600;
    white-space:nowrap;
}

.badge.selesai{background:#e9f8f0;color:#168353}
.badge.peralihan{background:#eee7ff;color:#7252b8}
.badge.menunggu{background:#fff5dc;color:#a46b00}
.badge.perbaikan{background:#fde5e5;color:#c94242}
.badge.sedang{background:#edf3ff;color:#3f65b5}
.badge.belum{background:#eef0f3;color:#667085}

/* DROPDOWN PERIODE */
.card-head{gap:10px}

.period-select{
    font:inherit;
    font-size:12px;
    color:#344054;
    background-color:#fff;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23667085' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:right 9px center;
    border:1px solid #f0c3c7;
    border-radius:8px;
    padding:6px 28px 6px 10px;
    cursor:pointer;
    appearance:none;
    -webkit-appearance:none;
    max-width:150px;
    flex-shrink:0;
    transition:border-color .15s ease,box-shadow .15s ease;
}

.period-select:hover{border-color:#e5a0a6}

.period-select:focus-visible{
    outline:none;
    border-color:#b51f2a;
    box-shadow:0 0 0 3px rgba(181,31,42,.15);
}

.period-select:disabled{opacity:.55;cursor:wait}

.green-badge.down{background:#fde5e5;color:#c94242!important}

/* RESPONSIVE */
@media(max-width:1200px){
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
    .top-chart-grid{grid-template-columns:1fr}
    .three-grid{grid-template-columns:1fr 1fr}
    .status-card{grid-column:span 2}
}

@media(max-width:850px){
    .main{margin-left:0;width:100%}
    .dashboard-content{padding:18px}
    .three-grid{grid-template-columns:1fr}
    .status-card{grid-column:auto}
}

@media(max-width:600px){
    .dashboard-head{
        align-items:flex-start;
        flex-direction:column;
        gap:10px;
    }
    .kpi-grid{grid-template-columns:1fr}
    .kpi-card{
        height:115px;
        min-height:115px;
        padding:16px 18px;
    }
    .kpi-number{margin-top:15px;font-size:28px}
    .progress-wrap{grid-template-columns:1fr}
    .progress-info{
        border-left:0;
        border-top:1px solid #e7e9ee;
        padding:15px 0 0;
        margin-top:5px;
        display:grid;
        grid-template-columns:1fr 1fr;
    }
}

/* ANIMASI ISI DASHBOARD
   Dijalankan murni lewat CSS (tanpa menunggu JavaScript),
   sehingga isi halaman langsung muncul tanpa kedip. */
.dashboard-content{opacity:1;transform:none}

.dashboard-content > *{
    animation:contentRise .55s cubic-bezier(.22,1,.36,1) both;
}

.dashboard-content > *:nth-child(1){animation-delay:0ms}
.dashboard-content > *:nth-child(2){animation-delay:50ms}
.dashboard-content > *:nth-child(3){animation-delay:100ms}
.dashboard-content > *:nth-child(4){animation-delay:150ms}
.dashboard-content > *:nth-child(5){animation-delay:200ms}
.dashboard-content > *:nth-child(6){animation-delay:250ms}

@keyframes contentRise{
    from{opacity:0;transform:translateY(18px)}
    to{opacity:1;transform:translateY(0)}
}

.kpi-card,.card{transition:transform .28s ease,box-shadow .28s ease,border-color .28s ease}

.kpi-card:hover,.card:hover{
    transform:translateY(-3px);
    box-shadow:0 7px 18px rgba(16,24,40,.08);
}

@media(prefers-reduced-motion:reduce){
    .dashboard-content > *{animation:none}
    .kpi-card,.card{transition:none}
}

/* =========================================================
   DARK MODE (palet sama dengan halaman karyawan)
========================================================= */
html:has(body.dark-mode),
html:has(body.dark-mode) body{background:#0f141b!important}

html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .main,
html body.dark-mode .dashboard-content{background:#0f141b!important}

/* header */
html body.dark-mode .dashboard-head h1{color:#edf2f7!important}
html body.dark-mode .date-box{background:#151b23!important;border-color:#2c3542!important;color:#8f9bac!important}

/* card */
html body.dark-mode .kpi-card,
html body.dark-mode .card,
html body.dark-mode .overview-card,
html body.dark-mode .progress-card,
html body.dark-mode .list-card,
html body.dark-mode .status-card,
html body.dark-mode .activity-card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}

/* teks utama */
html body.dark-mode .kpi-label,
html body.dark-mode .card-title h2,
html body.dark-mode .kpi-number,
html body.dark-mode .progress-center strong,
html body.dark-mode .progress-stat-body strong,
html body.dark-mode .status-percent{color:#edf2f7!important}

/* teks sekunder */
html body.dark-mode .kpi-foot,
html body.dark-mode .card-title p,
html body.dark-mode .progress-center span,
html body.dark-mode .progress-stat-body span,
html body.dark-mode .status-label,
html body.dark-mode .status-count,
html body.dark-mode .card-menu{color:#8f9bac!important}

html body.dark-mode .kpi-icon{color:#f07a80!important}

/* garis & track */
html body.dark-mode .progress-info{border-left-color:#2c3542!important}
html body.dark-mode .progress-stat:not(:last-child){border-bottom-color:#2c3542!important}
html body.dark-mode .progress-stat-dot[style*="eef0f3"]{background:#2c3542!important}
html body.dark-mode .rank-track,
html body.dark-mode .status-stack{background:#2c3542!important}
html body.dark-mode .status-bottom{border-top-color:#2c3542!important}
html body.dark-mode .rank-name,
html body.dark-mode .rank-value{color:#cbd2dc!important}
html body.dark-mode .rank-fill{background:linear-gradient(90deg,#b51f2a 0%,#e85d68 100%)!important}

/* tabel aktivitas */
html body.dark-mode .activity-table th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#b51f2a!important}
html body.dark-mode .activity-table th.activity-no{color:#fff!important}
html body.dark-mode .activity-table td{background:#151b23!important;color:#fff!important;border-bottom-color:#2c3542!important}
html body.dark-mode .activity-table tr:hover td{background:#202733!important}
html body.dark-mode .activity-name{color:#fff!important}
html body.dark-mode .activity-no{color:#fff!important}
html body.dark-mode .activity-table th.activity-no{color:#fff!important}

html body.dark-mode .view-all{background:#382326!important;color:#f07a80!important;border-color:#51363b!important}
html body.dark-mode .view-all:hover{background:#b51f2a!important;color:#fff!important}

/* badge status */
html body.dark-mode .badge.belum{background:#34383e!important;color:#c5cbd3!important}
html body.dark-mode .badge.sedang{background:#1d3158!important;color:#8eb5ff!important}
html body.dark-mode .badge.menunggu{background:#3c3924!important;color:#f2d77d!important}
html body.dark-mode .badge.perbaikan{background:#4b2d32!important;color:#ff8b92!important}
html body.dark-mode .badge.peralihan{background:#2f2748!important;color:#b9a2f0!important}
html body.dark-mode .badge.selesai{background:#183d2a!important;color:#72d89a!important}
html body.dark-mode .green-badge{background:#183d2a!important;color:#72d89a!important}

/* dropdown periode */
html body.dark-mode .period-select{
    background-color:#1c232d!important;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238f9bac' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E")!important;
    color:#cbd2dc!important;
    border-color:#2c3542!important;
}
html body.dark-mode .period-select:hover{border-color:#51363b!important}
html body.dark-mode .period-select option{background:#151b23;color:#cbd2dc}
html body.dark-mode .green-badge.down{background:#4b2d32!important;color:#ff8b92!important}

</style>
</head>

<body>

<script>
/* Terapkan tema paling awal, sebelum sidebar/isi dirender,
   supaya tidak ada kedip terang -> gelap. */
try {
    document.body.classList.toggle(
        "dark-mode",
        (localStorage.getItem("simo-theme") || "dark") === "dark"
    );
} catch (e) {}
</script>

<?php include "../includes/super_admin/sidebar.php"; ?>

<div class="main">

<?php include "../includes/super_admin/topbar.php"; ?>

<div class="dashboard-content">

    <!-- HEADER SEDERHANA -->
    <div class="dashboard-head">
        <div>
            <h1>Dashboard</h1>
        </div>
        <div class="date-box">
            📅 <?= h($tanggal_hari_ini); ?>
        </div>
    </div>

    <!-- KPI -->
    <div class="kpi-grid">

        <!-- Total Divisi -->
        <div class="kpi-card">
            <div class="kpi-top">
                <div class="kpi-label">Total Divisi</div>
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16"/>
                        <path d="M2 21h20"/>
                        <path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2"/>
                    </svg>
                </div>
            </div>
            <div class="kpi-bottom">
                <div class="kpi-number"><?= $total_divisi; ?></div>
                <div class="kpi-foot">Terdaftar di SIMOME</div>
            </div>
        </div>

        <!-- Karyawan Aktif -->
        <div class="kpi-card">
            <div class="kpi-top">
                <div class="kpi-label">Karyawan Aktif</div>
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="9" cy="8" r="3.5"/>
                        <path d="M2.8 20a6.2 6.2 0 0 1 12.4 0"/>
                        <circle cx="17" cy="8" r="2.5"/>
                        <path d="M15 15.5a5 5 0 0 1 6 4.5"/>
                    </svg>
                </div>
            </div>
            <div class="kpi-bottom">
                <div class="kpi-number"><?= $total_karyawan; ?></div>
                <div class="kpi-foot">
                    <span class="up">↑ <?= abs($perubahan_dibuat); ?>%</span>
                    &nbsp;dari bulan lalu
                </div>
            </div>
        </div>

        <!-- Total Pekerjaan -->
        <div class="kpi-card">
            <div class="kpi-top">
                <div class="kpi-label">Total Pekerjaan</div>
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="5" y="3" width="14" height="18" rx="2"/>
                        <path d="M8 8h8M8 12h8M8 16h5"/>
                    </svg>
                </div>
            </div>
            <div class="kpi-bottom">
                <div class="kpi-number"><?= $total_pekerjaan; ?></div>
                <div class="kpi-foot">
                    <span class="up">↑ <?= abs($perubahan_dibuat); ?>%</span>
                    &nbsp;dari bulan lalu
                </div>
            </div>
        </div>

        <!-- Pekerjaan Selesai -->
        <div class="kpi-card">
            <div class="kpi-top">
                <div class="kpi-label">Pekerjaan Selesai</div>
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="m8 12 2.7 2.7L16.5 9"/>
                    </svg>
                </div>
            </div>
            <div class="kpi-bottom">
                <div class="kpi-number"><?= $total_selesai; ?></div>
                <div class="kpi-foot">
                    <span class="up">↑ <?= abs($perubahan_selesai); ?>%</span>
                    &nbsp;dari bulan lalu
                </div>
            </div>
        </div>

    </div>

    <!-- OVERVIEW + PROGRESS -->
    <div class="top-chart-grid">

        <div class="card overview-card">
            <div class="card-head">
                <div class="card-title">
                    <h2>Overview Pekerjaan</h2>
                    <p>Jumlah pekerjaan yang dibuat setiap bulan pada tahun <span id="overviewYear"><?= h($tahun_ini); ?></span>.</p>
                </div>
                <?= periode_select("selOverview", $opsi_tahun, "Pilih tahun"); ?>
            </div>
            <div class="overview-chart-wrap">
                <canvas id="overviewChart"></canvas>
            </div>
        </div>

        <div class="card progress-card">
            <div class="card-head">
                <div class="card-title">
                    <h2>Progress Pekerjaan</h2>
                    <p>Penyelesaian pekerjaan bulan <span id="progressLabel"><?= h($label_bulan); ?></span>.</p>
                </div>
                <?= periode_select("selProgress", $opsi_periode, "Pilih bulan progress"); ?>
            </div>

            <div class="progress-wrap">

                <div class="progress-chart">
                    <canvas id="progressChart"></canvas>
                    <div class="progress-center">
                        <strong id="progressPct"><?= $persentase_selesai; ?>%</strong>
                        <span>Selesai</span>
                    </div>
                </div>

                <div class="progress-info">

                    <div class="progress-stat">
                        <span class="progress-stat-dot" style="background:#cf1f2d"></span>
                        <div class="progress-stat-body">
                            <strong id="progressSelesai"><?= $selesai_ini; ?></strong>
                            <span>Pekerjaan Selesai</span>
                            <span class="green-badge<?= $perubahan_selesai < 0 ? ' down' : ''; ?>" id="badgeSelesai"><?= $perubahan_selesai < 0 ? '↘' : '↗'; ?> <?= abs($perubahan_selesai); ?>%</span>
                        </div>
                    </div>

                    <div class="progress-stat">
                        <span class="progress-stat-dot" style="background:#eef0f3"></span>
                        <div class="progress-stat-body">
                            <strong id="progressDibuat"><?= $dibuat_ini; ?></strong>
                            <span>Pekerjaan Bulan Ini</span>
                            <span class="green-badge<?= $perubahan_dibuat < 0 ? ' down' : ''; ?>" id="badgeDibuat"><?= $perubahan_dibuat < 0 ? '↘' : '↗'; ?> <?= abs($perubahan_dibuat); ?>%</span>
                        </div>
                    </div>

                </div>

            </div>
        </div>

    </div>

    <!-- PEKERJAAN DIVISI + KARYAWAN DIVISI + STATUS -->
    <div class="three-grid">

        <!-- PEKERJAAN PER DIVISI -->
        <div class="card list-card">

            <div class="card-head">
                <div class="card-title">
                    <h2>Pekerjaan per Divisi</h2>
                    <p>Pekerjaan setiap divisi pada <span id="divisiLabel"><?= h($label_bulan); ?></span>.</p>
                </div>
                <?= periode_select("selDivisi", $opsi_periode, "Pilih bulan pekerjaan per divisi"); ?>
            </div>

            <div class="rank-list" id="divisiList">

                <?php if (!empty($divisi_data)): ?>

                    <?php foreach ($divisi_data as $item): ?>

                        <?php $width = round(($item["jumlah"] / $max_divisi) * 100); ?>

                        <div class="rank-row">
                            <div class="rank-name"><?= h($item["nama"]); ?></div>
                            <div class="rank-track">
                                <div class="rank-fill" style="width:<?= $width; ?>%"></div>
                            </div>
                            <div class="rank-value"><?= $item["jumlah"]; ?></div>
                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div style="font-size:12px;color:#7a8596;padding:20px 0">
                        Belum ada data divisi.
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- KARYAWAN PER DIVISI -->
        <div class="card list-card">

            <div class="card-head">
                <div class="card-title">
                    <h2>Karyawan per Divisi</h2>
                    <p>Total karyawan aktif pada setiap divisi.</p>
                </div>
                <div class="card-menu">⋮</div>
            </div>

            <div class="rank-list">

                <?php if (!empty($karyawan_data)): ?>

                    <?php foreach ($karyawan_data as $item): ?>

                        <?php $width = round(($item["jumlah"] / $max_karyawan) * 100); ?>

                        <div class="rank-row">
                            <div class="rank-name"><?= h($item["nama"]); ?></div>
                            <div class="rank-track">
                                <div class="rank-fill" style="width:<?= $width; ?>%"></div>
                            </div>
                            <div class="rank-value"><?= $item["jumlah"]; ?></div>
                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div style="font-size:12px;color:#7a8596;padding:20px 0">
                        Belum ada data karyawan.
                    </div>

                <?php endif; ?>

            </div>

        </div>

        <!-- STATUS PEKERJAAN -->
        <div class="card status-card">

            <div class="card-head">
                <div class="card-title">
                    <h2>Status Pekerjaan</h2>
                    <p>Distribusi status pekerjaan <span id="statusLabel"><?= h($label_bulan); ?></span>.</p>
                </div>
                <?= periode_select("selStatus", $opsi_periode, "Pilih bulan status pekerjaan"); ?>
            </div>

            <?php

            $status_colors = [
                "Belum Dikerjakan" => "#98a2b3",
                "Sedang Dikerjakan" => "#4f7be0",
                "Menunggu Konfirmasi" => "#e0a21a",
                "Perlu Perbaikan" => "#d9534f",
                "Peralihan" => "#8b6bd6",
                "Selesai" => "#29a35f"
            ];

            $status_short = [
                "Belum Dikerjakan" => "Belum",
                "Sedang Dikerjakan" => "Dalam Proses",
                "Menunggu Konfirmasi" => "Menunggu",
                "Perlu Perbaikan" => "Perbaikan",
                "Peralihan" => "Peralihan",
                "Selesai" => "Selesai"
            ];

            ?>

            <div class="status-wrap">

                <div class="status-stack" id="statusStack">

                    <?php foreach ($status as $nama_status => $jumlah): ?>

                        <?php
                        $pct = $total_status_bulan > 0
                            ? ($jumlah / $total_status_bulan) * 100
                            : 0;
                        ?>

                        <?php if ($jumlah > 0): ?>

                            <div
                                class="status-segment"
                                title="<?= h($nama_status); ?>: <?= $jumlah; ?>"
                                style="width:<?= $pct; ?>%;background:<?= $status_colors[$nama_status]; ?>">
                            </div>

                        <?php endif; ?>

                    <?php endforeach; ?>

                </div>

                <div class="status-grid" id="statusGrid">

                    <?php foreach ($status as $nama_status => $jumlah): ?>

                        <?php
                        $pct = $total_status_bulan > 0
                            ? round(($jumlah / $total_status_bulan) * 100)
                            : 0;
                        ?>

                        <div class="status-item">
                            <div class="status-percent"><?= $pct; ?>%</div>
                            <div class="status-label"><?= h($status_short[$nama_status]); ?></div>
                        </div>

                    <?php endforeach; ?>

                </div>

                <div class="status-bottom" id="statusBottom">

                    <?php foreach ($status as $nama_status => $jumlah): ?>

                        <div class="status-count">
                            <span
                                class="status-dot"
                                style="background:<?= $status_colors[$nama_status]; ?>">
                            </span>
                            <?= $jumlah; ?>
                            <?= h($status_short[$nama_status]); ?>
                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

    </div>

    <!-- AKTIVITAS TERBARU -->
    <div class="card activity-card">

        <div class="activity-head">
            <div class="card-title">
                <h2>Pekerjaan Terbaru</h2>
                <p>5 pekerjaan yang terakhir dibuat.</p>
            </div>
            <a href="pekerjaan.php" class="view-all">Lihat Semua</a>
        </div>

        <div class="activity-table-wrap">

            <table class="activity-table">

                <thead>
                    <tr>
                        <th class="activity-no">No</th>
                        <th>Nama Pekerjaan</th>
                        <th>Karyawan</th>
                        <th>Jenis</th>
                        <th>Tipe Order</th>
                        <th>No. Order</th>
                        <th>Tanggal Mulai</th>
                        <th>Target Selesai</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!empty($aktivitas)): ?>

                        <?php foreach ($aktivitas as $i => $item): ?>

                            <tr>
                                <td class="activity-no"><?= $i + 1; ?></td>
                                <td class="activity-name"><?= h($item["nama_pekerjaan"]); ?></td>
                                <td class="activity-team"><?= h($item["nama_tim"] ?? "Belum ada penugasan aktif"); ?></td>
                                <td><?= h($item["jenis_pekerjaan"] ?? "-"); ?></td>
                                <td><?= h($item["tipe_order"] ?? "-"); ?></td>
                                <td><?= !empty($item["nomor_order"]) ? h($item["nomor_order"]) : "-"; ?></td>
                                <td class="nowrap"><?= h(format_tanggal_waktu($item["tanggal_mulai"] ?? null)); ?></td>
                                <td class="nowrap"><?= h(format_tanggal_waktu($item["target_selesai"] ?? null)); ?></td>
                                <td>
                                    <span class="badge <?= status_badge_class($item["status"] ?? ""); ?>">
                                        <?= h($item["status"] ?? "-"); ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="9" style="text-align:center;color:#7a8596;padding:25px">
                                Belum ada data pekerjaan.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</div>

<script>

/* Dijalankan setelah halaman siap dan Chart.js (defer) selesai dimuat */
document.addEventListener("DOMContentLoaded", function () {

    const isDark = document.body.classList.contains("dark-mode");

    /* warna grafik mengikuti tema */
    const chartGrid  = isDark ? "#2c3542" : "#edf0f3";
    const chartTick  = isDark ? "#8f9bac" : "#667085";
    const chartEmpty = isDark ? "#2c3542" : "#e5e7eb";

    const statusColors = <?= json_encode($status_colors); ?>;
    const statusShort  = <?= json_encode($status_short); ?>;

    function makeEl(tag, cls, text) {
        const el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined) el.textContent = text;
        return el;
    }

    function loadData(params) {
        const url = window.location.pathname + "?" + new URLSearchParams(params).toString();

        return fetch(url, { credentials: "same-origin" })
            .then(function (r) {
                if (!r.ok) throw new Error("http");
                return r.json();
            })
            .then(function (d) {
                if (!d.ok) throw new Error("data");
                return d;
            });
    }

    function setBadge(id, value) {
        const el = document.getElementById(id);
        el.textContent = (value < 0 ? "↘ " : "↗ ") + Math.abs(value) + "%";
        el.classList.toggle("down", value < 0);
    }

    /* Bagian yang tidak butuh Chart.js (dropdown divisi & status)
       tetap jalan walaupun Chart.js gagal dimuat. */
    const chartReady = (typeof Chart !== "undefined");

    let overviewChart = null;
    let progressChart = null;

    /* =====================================================
       OVERVIEW BAR
    ===================================================== */

    if (chartReady) {

        const overviewValues = <?= json_encode($overview_values); ?>;

        const maxOverviewValue = Math.max(0, ...overviewValues);
        const overviewStep = maxOverviewValue <= 10 ? 2 : maxOverviewValue <= 50 ? 10 : maxOverviewValue <= 100 ? 20 : Math.ceil(maxOverviewValue / 5 / 10) * 10;
        const overviewMax = maxOverviewValue === 0 ? 10 : Math.ceil(maxOverviewValue / overviewStep) * overviewStep;

        const overviewColors = overviewValues.map(function (value) {
            if (value === 0) return chartEmpty;
            return value >= maxOverviewValue * 0.5 ? "#67b36f" : "#c95a5a";
        });

        overviewChart = new Chart(document.getElementById("overviewChart"), {

            type: "bar",

            data: {
                labels: <?= json_encode($overview_labels); ?>,
                datasets: [{
                    data: overviewValues,
                    backgroundColor: overviewColors,
                    borderRadius: 5,
                    borderSkipped: false,
                    barThickness: 16,
                    maxBarThickness: 20
                }]
            },

            options: {

                responsive: true,
                maintainAspectRatio: false,

                animation: {
                    duration: 800,
                    easing: "easeOutCubic",
                    delay: function (context) {
                        return context.dataIndex * 40;
                    }
                },

                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: isDark ? "#202733" : "#172033",
                        padding: 10,
                        cornerRadius: 7,
                        displayColors: false,
                        callbacks: {
                            label: function (context) {
                                return " " + context.raw + " pekerjaan";
                            }
                        }
                    }
                },

                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        border: {
                            display: false
                        },
                        ticks: {
                            color: chartTick,
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: overviewMax,
                        border: {
                            display: false
                        },
                        grid: {
                            color: chartGrid,
                            borderDash: [4, 4]
                        },
                        ticks: {
                            color: chartTick,
                            font: {
                                size: 10
                            },
                            precision: 0,
                            stepSize: overviewStep
                        }
                    }
                }
            }
        });

        /* =================================================
           PROGRESS DONUT
        ================================================= */

        const progressPct = <?= $persentase_selesai; ?>;
        const progressRadius = progressPct < 8 || progressPct > 92 ? 3 : 6;

        progressChart = new Chart(document.getElementById("progressChart"), {

            type: "doughnut",

            data: {
                labels: [
                    "Selesai",
                    "Belum selesai"
                ],
                datasets: [{
                    data: [
                        progressPct,
                        Math.max(0, 100 - progressPct)
                    ],
                    backgroundColor: [
                        "#b51f2a",
                        chartEmpty
                    ],
                    borderWidth: 0,
                    borderRadius: progressRadius,
                    spacing: 2,
                    hoverOffset: 6
                }]
            },

            options: {

                responsive: true,
                maintainAspectRatio: false,

                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 900,
                    easing: "easeOutCubic"
                },

                cutout: "72%",
                rotation: -90,

                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: false
                    }
                }
            }
        });
    }

    /* =====================================================
       DROPDOWN PERIODE PADA GRAFIK
    ===================================================== */

    function bindPeriod(id, buildParams, apply) {

        const sel = document.getElementById(id);

        if (!sel) return;

        sel.addEventListener("change", function () {

            sel.disabled = true;

            loadData(buildParams(sel.value))
                .then(function (d) {
                    apply(d);
                    sel.dataset.current = sel.value;
                })
                .catch(function () {
                    sel.value = sel.dataset.current;
                    alert("Data periode ini gagal dimuat. Silakan coba lagi.");
                })
                .then(function () {
                    sel.disabled = false;
                });
        });
    }

    /* ---------- Overview (per tahun) ---------- */

    bindPeriod(
        "selOverview",
        function (v) { return { ajax: "overview", tahun: v }; },
        function (d) {

            const vals = d.values;
            const mx   = Math.max(0, ...vals);
            const step = mx <= 10 ? 2 : mx <= 50 ? 10 : mx <= 100 ? 20 : Math.ceil(mx / 5 / 10) * 10;
            const top  = mx === 0 ? 10 : Math.ceil(mx / step) * step;

            if (overviewChart) {
                overviewChart.data.datasets[0].data = vals;
                overviewChart.data.datasets[0].backgroundColor = vals.map(function (v) {
                    if (v === 0) return chartEmpty;
                    return v >= mx * 0.5 ? "#67b36f" : "#c95a5a";
                });
                overviewChart.options.scales.y.ticks.stepSize = step;
                overviewChart.options.scales.y.max = top;
                overviewChart.update();
            }

            document.getElementById("overviewYear").textContent = d.tahun;
        }
    );

    /* ---------- Progress (per bulan) ---------- */

    bindPeriod(
        "selProgress",
        function (v) { return { ajax: "progress", periode: v }; },
        function (d) {

            if (progressChart) {
                progressChart.data.datasets[0].data = [d.persen, Math.max(0, 100 - d.persen)];
                progressChart.data.datasets[0].borderRadius = d.persen < 8 || d.persen > 92 ? 3 : 6;
                progressChart.update();
            }

            document.getElementById("progressPct").textContent = d.persen + "%";
            document.getElementById("progressSelesai").textContent = d.selesai;
            document.getElementById("progressDibuat").textContent = d.dibuat;
            document.getElementById("progressLabel").textContent = d.label;

            setBadge("badgeSelesai", d.perubahan_selesai);
            setBadge("badgeDibuat", d.perubahan_dibuat);
        }
    );

    /* ---------- Pekerjaan per divisi (per bulan) ---------- */

    bindPeriod(
        "selDivisi",
        function (v) { return { ajax: "divisi", periode: v }; },
        function (d) {

            const list = document.getElementById("divisiList");
            list.replaceChildren();

            document.getElementById("divisiLabel").textContent = d.label;

            if (!d.items.length) {
                const empty = makeEl("div", "", "Belum ada data divisi.");
                empty.style.cssText = "font-size:12px;color:#7a8596;padding:20px 0";
                list.appendChild(empty);
                return;
            }

            const mx = Math.max(1, ...d.items.map(function (i) { return i.jumlah; }));

            d.items.forEach(function (item) {

                const row   = makeEl("div", "rank-row");
                const name  = makeEl("div", "rank-name", item.nama);
                const track = makeEl("div", "rank-track");
                const fill  = makeEl("div", "rank-fill");
                const value = makeEl("div", "rank-value", item.jumlah);

                fill.style.width = Math.round((item.jumlah / mx) * 100) + "%";

                track.appendChild(fill);
                row.append(name, track, value);
                list.appendChild(row);
            });
        }
    );

    /* ---------- Status pekerjaan (per bulan) ---------- */

    bindPeriod(
        "selStatus",
        function (v) { return { ajax: "status", periode: v }; },
        function (d) {

            const stack  = document.getElementById("statusStack");
            const grid   = document.getElementById("statusGrid");
            const bottom = document.getElementById("statusBottom");

            stack.replaceChildren();
            grid.replaceChildren();
            bottom.replaceChildren();

            document.getElementById("statusLabel").textContent = d.label;

            Object.keys(d.status).forEach(function (nama) {

                const jumlah = d.status[nama];
                const pct    = d.total > 0 ? (jumlah / d.total) * 100 : 0;

                if (jumlah > 0) {
                    const seg = makeEl("div", "status-segment");
                    seg.title = nama + ": " + jumlah;
                    seg.style.width = pct + "%";
                    seg.style.background = statusColors[nama];
                    stack.appendChild(seg);
                }

                const item = makeEl("div", "status-item");
                item.append(
                    makeEl("div", "status-percent", Math.round(pct) + "%"),
                    makeEl("div", "status-label", statusShort[nama])
                );
                grid.appendChild(item);

                const count = makeEl("div", "status-count");
                const dot   = makeEl("span", "status-dot");
                dot.style.background = statusColors[nama];
                count.append(dot, " " + jumlah + " " + statusShort[nama]);
                bottom.appendChild(count);
            });
        }
    );

});

</script>

</body>
</html>
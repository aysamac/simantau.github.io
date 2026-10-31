<?php
include "../config/session.php";
include "../config/koneksi.php";

if (!isset($_SESSION['id_user'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'Admin') {
    header("Location: ../login.php");
    exit;
}

$id_admin = intval($_SESSION['id_user']);

$query_admin = mysqli_query($koneksi, "
    SELECT u.id_user, u.nama, u.username, u.role, u.id_divisi, d.nama_divisi
    FROM users u
    LEFT JOIN divisi d ON u.id_divisi = d.id_divisi
    WHERE u.id_user = '$id_admin'
    LIMIT 1
");

$admin = mysqli_fetch_assoc($query_admin);

if (!$admin) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$nama_admin = $admin['nama'];
$id_divisi = intval($admin['id_divisi']);
$nama_divisi = $admin['nama_divisi'] ?? '-';

$nama_bulan = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
    7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
];

$nama_bulan_penuh = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$nama_hari = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
];

$hari_ini_teks = $nama_hari[date('l')]
    . ', ' . date('d')
    . ' ' . $nama_bulan[intval(date('n'))]
    . ' ' . date('Y');

/* =========================================================
   HELPER DROPDOWN PERIODE & DATA GRAFIK
   Grafik (kecuali tren tahunan) hanya menghitung bulan
   yang dipilih; default-nya bulan berjalan, sehingga
   otomatis kembali dari 0 setiap pergantian bulan.
========================================================= */

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)($value ?? '-'), ENT_QUOTES, 'UTF-8');
    }
}

function parse_periode($str)
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$str, $mm)) return null;

    $y = (int)$mm[1];
    $m = (int)$mm[2];

    if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) return null;

    return [$y, $m];
}

function periode_select($id, $opsi, $aria)
{
    $html = '<select class="period-select" id="' . h($id) . '"'
          . ' aria-label="' . h($aria) . '"'
          . ' data-current="' . h($opsi[0]['value']) . '">';

    foreach ($opsi as $o) {
        $html .= '<option value="' . h($o['value']) . '">'
               . h($o['label']) . '</option>';
    }

    return $html . '</select>';
}

/* Tren: jumlah pekerjaan per bulan (Jan-Des) pada satu tahun */
function admin_tren($koneksi, $id_divisi, $y)
{
    $id_divisi = (int)$id_divisi;
    $y = (int)$y;

    $values = array_fill(0, 12, 0);

    $q = mysqli_query($koneksi, "
        SELECT MONTH(tanggal_mulai) AS bln, COUNT(*) AS total
        FROM pekerjaan
        WHERE id_divisi = '$id_divisi'
        AND YEAR(tanggal_mulai) = '$y'
        GROUP BY MONTH(tanggal_mulai)
    ");

    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $idx = (int)$r['bln'] - 1;
            if ($idx >= 0 && $idx < 12) {
                $values[$idx] = (int)$r['total'];
            }
        }
    }

    return $values;
}

/* Warna tetap per tipe order (stabil antar bulan) */
function warna_tipe_palet()
{
    return ['#b51f2a', '#2f6fed', '#16a36a', '#f59e0b', '#7c4dff', '#0ea5b7', '#e0529c', '#64748b'];
}

function warna_tipe_index($label)
{
    $key = strtolower(trim((string)$label));

    if ($key === 'non order') return 0;          // selalu merah

    return 1 + (crc32($key) % 7);                // tipe lain: 1..7
}

/* Tipe order: 3 tipe terbanyak + total/selesai/belum pada satu bulan */
function admin_tipe_data($koneksi, $id_divisi, $y, $m)
{
    $id_divisi = (int)$id_divisi;
    $y = (int)$y;
    $m = (int)$m;

    $gabung = [];
    $total = 0;
    $selesai = 0;

    $q = mysqli_query($koneksi, "
        SELECT
            tipe_order,
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) AS selesai
        FROM pekerjaan
        WHERE id_divisi = '$id_divisi'
        AND MONTH(tanggal_mulai) = '$m'
        AND YEAR(tanggal_mulai) = '$y'
        GROUP BY tipe_order
    ");

    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {

            $label = trim($r['tipe_order'] ?? '');

            if ($label === '') {
                $label = 'Tanpa Tipe';
            }

            $gabung[$label] = ($gabung[$label] ?? 0) + (int)$r['total'];
            $total += (int)$r['total'];
            $selesai += (int)$r['selesai'];
        }
    }

    arsort($gabung);

    $ring = [];
    $palet = warna_tipe_palet();
    $dipakai = [];

    foreach ($gabung as $label => $jml) {

        if (count($ring) >= 3) break;

        $idx = warna_tipe_index($label);

        // hindari 2 tipe dengan warna sama di satu ring
        while (in_array($idx, $dipakai, true)) {
            $idx = ($idx + 1) % count($palet);
        }
        $dipakai[] = $idx;

        $ring[] = [
            'label' => (string)$label,
            'total' => (int)$jml,
            'pct'   => $total > 0 ? (int)round(($jml / $total) * 100) : 0,
            'color' => $palet[$idx]
        ];
    }

    while (count($ring) < 3) {
        $ring[] = ['label' => '-', 'total' => 0, 'pct' => 0, 'color' => '#8f9bac'];
    }

    return [
        'ring'    => $ring,
        'total'   => $total,
        'selesai' => $selesai,
        'belum'   => $total - $selesai
    ];
}

/* Pekerjaan per karyawan (selesai vs belum selesai) pada satu bulan */
function admin_workload($koneksi, $id_divisi, $y, $m)
{
    $id_divisi = (int)$id_divisi;
    $y = (int)$y;
    $m = (int)$m;

    $out = ['labels' => [], 'selesai' => [], 'belum' => []];

    $q = mysqli_query($koneksi, "
        SELECT
            u.id_user,
            u.nama,
            (
                SELECT COUNT(*)
                FROM pekerjaan_karyawan pk
                INNER JOIN pekerjaan p2 ON p2.id_pekerjaan = pk.id_pekerjaan
                WHERE pk.id_user = u.id_user
                AND p2.id_divisi = '$id_divisi'
                AND MONTH(p2.tanggal_mulai) = '$m'
                AND YEAR(p2.tanggal_mulai) = '$y'
                AND pk.status_penugasan = 'Selesai'
            )
            +
            (
                SELECT COUNT(*)
                FROM pekerjaan p3
                WHERE p3.id_divisi = '$id_divisi'
                AND MONTH(p3.tanggal_mulai) = '$m'
                AND YEAR(p3.tanggal_mulai) = '$y'
                AND p3.id_karyawan = u.id_user
                AND p3.status = 'Selesai'
                AND NOT EXISTS (
                    SELECT 1 FROM pekerjaan_karyawan pk3
                    WHERE pk3.id_pekerjaan = p3.id_pekerjaan
                    AND pk3.id_user = u.id_user
                )
            ) AS jumlah_selesai,
            (
                SELECT COUNT(*)
                FROM pekerjaan_karyawan pk4
                INNER JOIN pekerjaan p4 ON p4.id_pekerjaan = pk4.id_pekerjaan
                WHERE pk4.id_user = u.id_user
                AND p4.id_divisi = '$id_divisi'
                AND MONTH(p4.tanggal_mulai) = '$m'
                AND YEAR(p4.tanggal_mulai) = '$y'
                AND pk4.status_penugasan IN ('Aktif', 'Peralihan')
                AND p4.status <> 'Selesai'
            )
            +
            (
                SELECT COUNT(*)
                FROM pekerjaan p5
                WHERE p5.id_divisi = '$id_divisi'
                AND MONTH(p5.tanggal_mulai) = '$m'
                AND YEAR(p5.tanggal_mulai) = '$y'
                AND p5.id_karyawan = u.id_user
                AND p5.status <> 'Selesai'
                AND NOT EXISTS (
                    SELECT 1 FROM pekerjaan_karyawan pk5
                    WHERE pk5.id_pekerjaan = p5.id_pekerjaan
                    AND pk5.id_user = u.id_user
                )
            ) AS jumlah_belum
        FROM users u
        WHERE u.id_divisi = '$id_divisi'
        AND u.role = 'Karyawan'
        AND TRIM(u.status) = 'Aktif'
        ORDER BY (jumlah_selesai + jumlah_belum) DESC, u.nama ASC
    ");

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $out['labels'][]  = $row['nama'];
            $out['selesai'][] = intval($row['jumlah_selesai'] ?? 0);
            $out['belum'][]   = intval($row['jumlah_belum'] ?? 0);
        }
    }

    return $out;
}

/* =========================================================
   ENDPOINT AJAX (dropdown periode pada grafik)
========================================================= */

if (isset($_GET['ajax'])) {

    header('Content-Type: application/json; charset=UTF-8');

    $jenis = $_GET['ajax'];
    $out = ['ok' => false];

    if ($jenis === 'tren') {

        $y = (int)($_GET['tahun'] ?? 0);

        if ($y >= 2000 && $y <= 2100) {
            $out = [
                'ok'     => true,
                'tahun'  => $y,
                'values' => admin_tren($koneksi, $id_divisi, $y)
            ];
        }

    } else {

        $p = parse_periode($_GET['periode'] ?? '');

        if ($p) {

            [$y, $m] = $p;

            $label = $nama_bulan_penuh[$m] . ' ' . $y;

            if ($jenis === 'tipe') {

                $out = ['ok' => true, 'label' => $label]
                     + admin_tipe_data($koneksi, $id_divisi, $y, $m);

            } elseif ($jenis === 'workload') {

                $out = ['ok' => true, 'label' => $label]
                     + admin_workload($koneksi, $id_divisi, $y, $m);
            }
        }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   PERIODE BERJALAN & PILIHAN DROPDOWN
========================================================= */

$bulan_ini = (int)date('n');
$tahun_ini = (int)date('Y');

$label_bulan = $nama_bulan_penuh[$bulan_ini] . ' ' . $tahun_ini;

/* Dropdown dimulai dari data paling awal pada divisi ini
   sampai bulan/tahun berjalan. */
$ts_awal = 0;

$q_awal = mysqli_query($koneksi, "
    SELECT MIN(tanggal_mulai) AS awal
    FROM pekerjaan
    WHERE id_divisi = '$id_divisi'
    AND tanggal_mulai IS NOT NULL
    AND tanggal_mulai > '2000-01-01'
");

if ($q_awal) {
    $r_awal = mysqli_fetch_assoc($q_awal);
    $ts_awal = !empty($r_awal['awal']) ? (int)strtotime($r_awal['awal']) : 0;
}

if ($ts_awal <= 0 || $ts_awal > time()) {
    $ts_awal = time();
}

$tahun_awal = (int)date('Y', $ts_awal);
$bulan_awal = (int)date('n', $ts_awal);

$jumlah_bulan = ($tahun_ini - $tahun_awal) * 12 + ($bulan_ini - $bulan_awal);
$jumlah_bulan = max(0, min($jumlah_bulan, 240));

$opsi_periode = [];

for ($i = 0; $i <= $jumlah_bulan; $i++) {
    $t = mktime(0, 0, 0, $bulan_ini - $i, 1, $tahun_ini);
    $opsi_periode[] = [
        'value' => date('Y-m', $t),
        'label' => $nama_bulan_penuh[(int)date('n', $t)] . ' ' . date('Y', $t)
    ];
}

$opsi_tahun = [];

for ($y = $tahun_ini; $y >= $tahun_awal; $y--) {
    $opsi_tahun[] = ['value' => $y, 'label' => $y];
}

/* =========================================================
   KPI (total keseluruhan divisi)
========================================================= */

$query_total = mysqli_query($koneksi, "
    SELECT COUNT(*) AS total FROM pekerjaan WHERE id_divisi = '$id_divisi'
");
$data_total = mysqli_fetch_assoc($query_total);
$total_pekerjaan = intval($data_total['total']);

$query_selesai = mysqli_query($koneksi, "
    SELECT COUNT(*) AS total FROM pekerjaan
    WHERE id_divisi = '$id_divisi' AND status = 'Selesai'
");
$data_selesai = mysqli_fetch_assoc($query_selesai);
$selesai = intval($data_selesai['total']);

$query_karyawan_aktif = mysqli_query($koneksi, "
    SELECT COUNT(*) AS total FROM users
    WHERE id_divisi = '$id_divisi' AND role = 'Karyawan' AND TRIM(status) = 'Aktif'
");
$data_karyawan_aktif = mysqli_fetch_assoc($query_karyawan_aktif);
$karyawan_aktif = intval($data_karyawan_aktif['total']);

$query_perbaikan = mysqli_query($koneksi, "
    SELECT COUNT(*) AS total FROM pekerjaan
    WHERE id_divisi = '$id_divisi' AND status = 'Perlu Perbaikan'
");
$data_perbaikan = mysqli_fetch_assoc($query_perbaikan);
$perbaikan = intval($data_perbaikan['total']);

/* =========================================================
   DATA GRAFIK (tampilan awal = bulan / tahun berjalan)
========================================================= */

/* Pekerjaan per karyawan - bulan berjalan */
$wl = admin_workload($koneksi, $id_divisi, $tahun_ini, $bulan_ini);

$grafik_karyawan_labels  = $wl['labels'];
$grafik_karyawan_selesai = $wl['selesai'];
$grafik_karyawan_belum   = $wl['belum'];

/* Tren pekerjaan - tahun berjalan (Jan-Des) */
$grafik_bulan_labels = array_values($nama_bulan);
$grafik_tren_data    = admin_tren($koneksi, $id_divisi, $tahun_ini);

/* Pekerjaan per tipe order - bulan berjalan */
$tipe = admin_tipe_data($koneksi, $id_divisi, $tahun_ini, $bulan_ini);

$ring_tipe   = $tipe['ring'];
$ring_radius = [86, 64, 42];
$ring_stroke = 15;

for ($k = 0; $k < 3; $k++) {
    $ring_tipe[$k]['keliling'] = 2 * M_PI * $ring_radius[$k];
    $ring_tipe[$k]['panjang']  = $ring_tipe[$k]['keliling'] * $ring_tipe[$k]['pct'] / 100;
}

$total_tipe_bulan = $tipe['total'];
$selesai_bulan    = $tipe['selesai'];
$belum_bulan      = $tipe['belum'];

/* =========================================================
   POP-UP PEKERJAAN PERLU DIKONFIRMASI (sekali setelah login)
   Hanya muncul pada kunjungan dashboard pertama di sesi login
   ini dan hanya bila ada pekerjaan "Menunggu Konfirmasi".
   Yang ditampilkan hanya jumlahnya.
========================================================= */
$tampil_popup_konfirmasi = false;
$jumlah_konfirmasi = 0;

if (empty($_SESSION['popup_konfirmasi_ditampilkan'])) {

    $_SESSION['popup_konfirmasi_ditampilkan'] = true;

    $q_konf = mysqli_query($koneksi, "
        SELECT COUNT(*) AS total
        FROM pekerjaan
        WHERE id_divisi = '$id_divisi'
        AND status = 'Menunggu Konfirmasi'
    ");

    if ($q_konf) {
        $r_konf = mysqli_fetch_assoc($q_konf);
        $jumlah_konfirmasi = (int)($r_konf['total'] ?? 0);
    }

    if ($jumlah_konfirmasi > 0) {
        $tampil_popup_konfirmasi = true;
    }
}

function formatTanggalWaktu($tanggal)
{
    if (empty($tanggal) || $tanggal == "0000-00-00" || $tanggal == "0000-00-00 00:00:00") {
        return "-";
    }
    $t = strtotime($tanggal);
    return $t ? date("d M Y, H:i", $t) : "-";
}

$query_pekerjaan = mysqli_query($koneksi, "
    SELECT
        p.id_pekerjaan, p.nama_pekerjaan, p.jenis_pekerjaan, p.tipe_order, p.nomor_order,
        p.tanggal_mulai, p.target_selesai, p.status,
        u.nama AS nama_karyawan
    FROM pekerjaan p
    LEFT JOIN users u ON p.id_karyawan = u.id_user
    WHERE p.id_divisi = '$id_divisi'
    ORDER BY p.id_pekerjaan DESC
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Admin Divisi - SIMO</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#fff;color:#172033}
a{text-decoration:none;color:inherit}
.main{margin-left:240px;width:calc(100% - 240px);min-height:100vh}
.dashboard-content{padding:24px 26px 36px}
.dashboard-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.dashboard-head h1{font-size:28px;font-weight:700;line-height:1.2;color:#172033}
.dashboard-head p{display:none}
.date-box{background:#fff;border:1px solid #f0c9cd;border-radius:10px;padding:10px 14px;font-size:13px;color:#667085}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.kpi-card{background:#fff;border:1px solid #e6b7bb!important;border-radius:12px;padding:16px 18px;height:108px;min-height:108px;position:relative;box-shadow:0 1px 4px rgba(16,24,40,.04);display:flex;flex-direction:column;justify-content:space-between;transition:transform .2s ease,box-shadow .2s ease}
.kpi-card:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(16,24,40,.08)}
.kpi-top{display:flex;align-items:center;justify-content:space-between}
.kpi-label{font-size:14px;font-weight:500;color:#344054}
.kpi-icon{width:28px;height:28px;display:flex;align-items:center;justify-content:center;color:#b51f2a}
.kpi-icon svg{width:21px;height:21px}
.kpi-menu{display:none}
.kpi-bottom{display:flex;align-items:flex-end;justify-content:space-between;gap:10px}
.kpi-number{font-size:28px;font-weight:700;color:#172033;line-height:1;letter-spacing:-.5px;margin:0}
.kpi-foot{font-size:11px;color:#7a8596;margin:0 0 1px 0;white-space:nowrap}
.kpi-foot .up{color:#b51f2a;font-weight:600}
.card{background:#fff;border:1px solid #f0c9cd;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.04);overflow:hidden}
.card-head{display:flex;justify-content:space-between;align-items:flex-start;padding:18px 20px 7px}
.card-title{min-width:0}
.card-title h2{font-size:17px;font-weight:700;color:#172033;line-height:1.25}
.card-title p{font-size:12px;color:#7a8596;margin-top:4px}
.card-menu{font-size:21px;color:#9aa3b1;line-height:1}
.top-chart-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:14px;margin-bottom:14px}
.overview-card{min-height:300px}
.overview-chart-wrap{height:220px;padding:5px 17px 16px}
.progress-card{min-height:300px}
.progress-wrap{display:grid;grid-template-columns:1fr 1fr;align-items:center;min-height:226px;padding:14px 22px 24px;gap:10px}
.progress-chart{height:190px;position:relative}
.progress-chart canvas{width:100%!important;height:100%!important}
.progress-center{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;pointer-events:none}
.progress-center strong{font-size:32px;font-weight:800;color:#172033;line-height:1}
.progress-center span{font-size:12px;color:#8a93a3;margin-top:6px}
.progress-info{border-left:1px solid #edeff2;padding-left:22px;display:flex;flex-direction:column}
.progress-stat{display:flex;align-items:center;gap:12px;padding:14px 0}
.progress-stat:not(:last-child){border-bottom:1px solid #f1f2f5}
.progress-stat-dot{width:11px;height:11px;border-radius:4px;flex-shrink:0}
.progress-stat-body strong{display:block;font-size:23px;font-weight:800;line-height:1;color:#172033}
.progress-stat-body span{display:block;font-size:12px;color:#8a93a3;margin-top:5px}
.green-badge{display:inline-flex!important;align-items:center;gap:2px;background:#e6f8ee;color:#16a36a!important;border-radius:20px;font-size:10.5px!important;font-weight:700;padding:4px 10px;margin-top:8px}
.three-grid{display:grid;grid-template-columns:1.05fr 1.05fr .95fr;gap:14px;margin-bottom:14px}
.list-card{min-height:295px}
.rank-list{padding:10px 18px 18px}
.rank-row{display:grid;grid-template-columns:112px 1fr 30px;gap:9px;align-items:center;margin:10px 0}
.rank-name{font-size:12px;color:#344054;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-track{height:9px;background:#f0f2f4;border-radius:999px;overflow:hidden}
.rank-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#f22f3a 0%,#a80e18 100%)}
.rank-value{text-align:right;font-size:12px;color:#344054;font-weight:600}
.status-card{min-height:295px}
.status-wrap{padding:15px 18px 18px}
.status-stack{display:flex;height:25px;border-radius:8px;overflow:hidden;background:#f0f2f4;margin:10px 0 16px}
.status-segment{height:100%;min-width:2px}
.status-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px 10px}
.status-item{min-width:0}
.status-percent{font-size:17px;font-weight:700;color:#172033}
.status-label{font-size:10px;color:#778195;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.status-bottom{display:flex;gap:12px;flex-wrap:wrap;border-top:1px solid #edf0f3;margin-top:15px;padding-top:13px}
.status-count{display:flex;align-items:center;gap:5px;font-size:10px;color:#667085}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.activity-card{margin-bottom:14px}
.activity-head{display:flex;justify-content:space-between;align-items:center;padding:17px 18px 11px}
.view-all{font-size:11px;color:#b31824;border:1px solid #f0c7ca;border-radius:7px;padding:7px 10px;background:#fff8f8}
.activity-table-wrap{overflow-x:auto;padding:0 14px 14px}
.activity-table{width:100%;border-collapse:collapse;min-width:700px}
.activity-table th{background:#f8f9fb;color:#667085;font-size:10px;font-weight:600;text-align:left;padding:10px;border-bottom:1px solid #e8ebef}
.activity-table td{font-size:11px;color:#344054;padding:11px 10px;border-bottom:1px solid #eef0f3;vertical-align:middle}
.activity-table tr:last-child td{border-bottom:none}
.activity-no{width:35px;color:#8a94a5!important}
.activity-name{font-weight:600;color:#172033!important}
.activity-team{max-width:270px;line-height:1.4}
.badge{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:9px;font-weight:600;white-space:nowrap}
.badge.selesai{background:#e9f8f0;color:#168353}
.badge.peralihan{background:#fff0f1;color:#b31824}
.badge.menunggu{background:#fff5dc;color:#a46b00}
.badge.perbaikan{background:#fff0ed;color:#c34b27}
.badge.sedang{background:#edf3ff;color:#3f65b5}
.badge.belum{background:#eef0f3;color:#667085}

/* =========================================================
   RESPONSIVE
========================================================= */
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
    .dashboard-head{align-items:flex-start;flex-direction:column;gap:10px}
    .kpi-grid{grid-template-columns:1fr}
    .kpi-card{height:115px;min-height:115px;padding:16px 18px}
    .kpi-number{margin-top:15px;font-size:28px}
    .progress-wrap{grid-template-columns:1fr}
    .progress-info{border-left:0;border-top:1px solid #e7e9ee;padding:15px 0 0;margin-top:5px;display:grid;grid-template-columns:1fr 1fr}
}
.workload-card{margin-bottom:14px;min-height:360px}
.workload-chart-wrap{height:285px;padding:0 18px 16px}
.workload-chart-wrap canvas{width:100%!important;height:100%!important}
.status-main-admin{display:grid;grid-template-columns:180px minmax(0,1fr);gap:18px;align-items:center;min-height:225px;padding:10px 20px 20px}
.status-donut-admin{width:165px;height:165px;position:relative;justify-self:center}
.status-donut-admin canvas{width:165px!important;height:165px!important}
.status-center-admin{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;pointer-events:none}
.status-center-admin strong{font-size:28px;font-weight:800;color:#172033;line-height:1}
.status-center-admin span{font-size:11px;color:#8a93a3;margin-top:6px}
.admin-legend{display:flex;flex-direction:column;gap:11px}
.admin-legend-item{display:flex;align-items:center;justify-content:space-between;gap:10px}
.admin-legend-left{display:flex;align-items:center;gap:8px;min-width:0}
.admin-legend-dot{width:9px;height:9px;border-radius:50%;flex:0 0 9px}
.admin-legend-label{font-size:11px;color:#4b5666;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-legend-value{font-size:12px;color:#172033;font-weight:700}

/* ===== Kartu Pekerjaan per Tipe Order (cincin) ===== */
.tipe-body{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 18px 14px}
.tipe-ring{width:160px;height:160px;flex:0 0 160px}
.tipe-ring svg{width:100%;height:100%;display:block}
.tipe-ring circle{transition:stroke-dasharray 1.1s cubic-bezier(.22,1,.36,1),opacity .4s ease}
.tipe-stats{display:flex;align-items:center;flex:1;min-width:0}
.tipe-stat{flex:1;min-width:0;text-align:center;padding:0 6px}
.tipe-divider{width:1px;height:72px;background:#e6e8ec;flex:0 0 1px}
.tipe-num{font-size:30px;font-weight:500;color:#172033;line-height:1.1}
.tipe-name{font-size:13px;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:color .4s ease}
.tipe-badge{display:inline-block;color:#fff;border-radius:6px;font-size:10.5px;font-weight:700;padding:3px 8px;margin-top:8px;transition:background .4s ease}
.tipe-bottom{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid #edf0f3;margin:0 16px;padding:14px 0 16px}
.tipe-bottom-item{text-align:center;border-left:1px solid #e6e8ec;min-width:0}
.tipe-bottom-item:first-child{border-left:0}
.tipe-bottom-item strong{display:block;font-size:24px;font-weight:800;color:#172033;line-height:1.1}
.tipe-bottom-item span{display:block;font-size:12px;color:#667085;margin-top:6px}
@media(max-width:600px){
    .tipe-body{flex-direction:column}
    .tipe-stats{width:100%}
}

/* ===== Warna kartu grafik ===== */
.overview-card{background:#fff!important;border:1px solid #d9dde2!important}
.progress-card{background:#fff!important;border:1px solid #d9dde2!important}
.workload-card{background:#fff!important;border:1px solid #d9dde2!important}
@media(max-width:1200px){
    .status-main-admin{grid-template-columns:1fr}
    .status-donut-admin{margin:0 auto}
}
@media(max-width:850px){
    .workload-chart-wrap{height:260px}
}

/* =========================================================
   DROPDOWN PERIODE PADA GRAFIK
========================================================= */
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
.period-select:focus-visible{outline:none;border-color:#b51f2a;box-shadow:0 0 0 3px rgba(181,31,42,.15)}
.period-select:disabled{opacity:.55;cursor:wait}

/* =========================================================
   ANIMASI DASHBOARD SAAT DIBUKA
========================================================= */
.dashboard-content{opacity:1;transform:none}
.content-enter{opacity:0;transform:translateY(26px);animation:contentRise .9s cubic-bezier(.22,1,.36,1) forwards;animation-delay:var(--delay,0ms)}
@keyframes contentRise{
    from{opacity:0;transform:translateY(26px)}
    to{opacity:1;transform:translateY(0)}
}
@media(prefers-reduced-motion:reduce){
    .content-enter{opacity:1;transform:none;animation:none}
    .tipe-ring circle{transition:none}
}

/* =========================================================
   TABEL PEKERJAAN TERBARU
   Ukuran & bentuk disamakan dengan tabel pada halaman Laporan
   (header merah, font 13.5px, padding sama, scroll horizontal di HP)
========================================================= */
.main{min-width:0}

.admin-table-card{
    margin-bottom:14px;
    min-width:0;
    background:#ffffff!important;
    border:1px solid #d8dde4!important;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    overflow:hidden;
}

.activity-head{
    padding:18px 22px;
    border-bottom:1px solid #edf1f6;
}

.activity-head .card-title h2{
    font-size:14px;
    font-weight:700;
    color:#1c2636;
}

.activity-table-wrap{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    padding:0;
}

.activity-table{
    width:100%;
    min-width:1000px;
    border-collapse:collapse;
}

.activity-table th,
.activity-table td{
    vertical-align:middle;
}

.activity-table th{
    background:#b51f2a;
    color:#ffffff;
    font-size:13.5px;
    font-weight:700;
    text-align:center;
    padding:14px 16px;
    border:0;
    border-bottom:1px solid #8f1821;
    white-space:nowrap;
}

.activity-table td{
    font-size:13.5px;
    color:#4c5b6e;
    text-align:left;
    padding:15px 16px;
    border-bottom:1px solid #f0f3f7;
    white-space:nowrap;
}

.activity-table td:first-child,
.activity-table td:last-child{
    text-align:center;
}

.activity-table tbody tr:hover td{
    background:#fafbfd;
}

.activity-table tr:last-child td{
    border-bottom:1px solid #f0f3f7;
}

.activity-table th.activity-no{
    width:auto;
    color:#ffffff!important;
}

.activity-table td.activity-no{
    width:auto;
    color:#667488!important;
}

.activity-name{
    font-weight:400;
    color:#1c2636!important;
}

.activity-table .badge{
    display:inline-block;
    padding:5px 9px;
    border-radius:6px;
    font-size:10.5px;
    font-weight:700;
    white-space:nowrap;
}

.activity-table .badge.belum     { background:#eef0f3; color:#667085; }
.activity-table .badge.sedang    { background:#edf3ff; color:#3f65b5; }
.activity-table .badge.menunggu  { background:#fff5dc; color:#a46b00; }
.activity-table .badge.perbaikan { background:#fde5e5; color:#c94242; }
.activity-table .badge.peralihan { background:#eee7ff; color:#7252b8; }
.activity-table .badge.selesai   { background:#e9f8f0; color:#168353; }

.activity-table .empty{
    text-align:center;
    padding:40px 20px;
    color:#8b96a5;
    font-size:13px;
}

@media(max-width:600px){
    .activity-head{padding:16px 14px 12px}
}

/* =========================================================
   DARK MODE (warna disamakan dengan dashboard karyawan)
   bg #0f141b | card #151b23 | border #2c3542 | hover #202733
========================================================= */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .main,html body.dark-mode .dashboard-content{background:#0f141b!important}
html body.dark-mode .dashboard-head h1{color:#edf2f7!important}
html body.dark-mode .date-box{background:#151b23!important;border-color:#303947!important;color:#aeb8c6!important}
html body.dark-mode .kpi-card,html body.dark-mode .card,html body.dark-mode .overview-card,html body.dark-mode .progress-card,html body.dark-mode .workload-card,html body.dark-mode .admin-table-card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
html body.dark-mode .kpi-card:hover{box-shadow:0 5px 14px rgba(0,0,0,.28)!important}
html body.dark-mode .kpi-label,html body.dark-mode .card-title h2{color:#edf2f7!important}
html body.dark-mode .kpi-number,html body.dark-mode .progress-center strong,html body.dark-mode .progress-stat-body strong,html body.dark-mode .status-percent,html body.dark-mode .tipe-num,html body.dark-mode .tipe-bottom-item strong,html body.dark-mode .status-center-admin strong,html body.dark-mode .admin-legend-value{color:#edf2f7!important}
html body.dark-mode .kpi-foot,html body.dark-mode .card-title p,html body.dark-mode .progress-center span,html body.dark-mode .progress-stat-body span,html body.dark-mode .status-label,html body.dark-mode .status-count,html body.dark-mode .admin-legend-label,html body.dark-mode .tipe-bottom-item span{color:#8f9bac!important}
html body.dark-mode .card-menu{color:#8f9bac!important}
html body.dark-mode .progress-info{border-left-color:#2c3542!important}
html body.dark-mode .progress-stat:not(:last-child){border-bottom-color:#2c3542!important}
html body.dark-mode .rank-name,html body.dark-mode .rank-value{color:#cbd2dc!important}
html body.dark-mode .rank-track,html body.dark-mode .status-stack{background:#202733!important}
html body.dark-mode .status-bottom,html body.dark-mode .tipe-bottom{border-color:#2c3542!important}
html body.dark-mode .tipe-divider{background:#2c3542!important}
html body.dark-mode .tipe-bottom-item{border-color:#2c3542!important}
html body.dark-mode .tipe-ring circle[stroke="#e5e8ec"]{stroke:#2c3542!important}

/* tabel pekerjaan terbaru (dark) - sama dengan tabel Laporan */
html body.dark-mode .activity-head{border-bottom-color:#2c3542!important}
html body.dark-mode .activity-head .card-title h2{color:#edf2f7!important}
html body.dark-mode .activity-table-wrap{background:#151b23!important}
html body.dark-mode .activity-table{background:#151b23!important}
html body.dark-mode .activity-table th,html body.dark-mode .activity-table th.activity-no{background:#b51f2a!important;color:#fff!important;border-bottom-color:#b51f2a!important}
html body.dark-mode .activity-table td{background:#151b23!important;color:#fff!important;border-bottom-color:#2c3542!important}
html body.dark-mode .activity-table tbody tr:hover td{background:#202733!important}
html body.dark-mode .activity-table td.activity-no{color:#cbd2dc!important}
html body.dark-mode .activity-name{color:#fff!important;font-weight:400!important}
html body.dark-mode .activity-table .empty{color:#8f9bac!important}
html body.dark-mode .activity-table .badge.belum     {background:#34383e!important;color:#c5cbd3!important}
html body.dark-mode .activity-table .badge.sedang    {background:#1d3158!important;color:#8eb5ff!important}
html body.dark-mode .activity-table .badge.menunggu  {background:#3c3924!important;color:#f2d77d!important}
html body.dark-mode .activity-table .badge.perbaikan {background:#4b2d32!important;color:#ff8b92!important}
html body.dark-mode .activity-table .badge.peralihan {background:#2f2748!important;color:#b9a2f0!important}
html body.dark-mode .activity-table .badge.selesai   {background:#183d2a!important;color:#72d89a!important}

html body.dark-mode .order-type{color:#cbd2dc!important}
html body.dark-mode .order-number{color:#8f9bac!important}
html body.dark-mode .view-all{background:#382326!important;color:#f07a80!important;border-color:#51363b!important}
html body.dark-mode .view-all:hover{background:#4a292d!important;color:#ff9298!important}
html body.dark-mode .empty{color:#8f9bac!important}
html body.dark-mode .theme-toggle{background:#202733!important;color:#f0b5b8!important;border-color:#303947!important}
html body.dark-mode .period-select{background-color:#1c232d!important;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238f9bac' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E")!important;color:#cbd2dc!important;border-color:#2c3542!important}
html body.dark-mode .period-select:hover{border-color:#51363b!important}
html body.dark-mode .period-select option{background:#151b23;color:#cbd2dc}

/* =========================================================
   POP-UP PEKERJAAN PERLU DIKONFIRMASI
   Warna mengikuti mode terang / gelap lewat variabel --pb-*
========================================================= */
.popup-overlay{
    position:fixed;inset:0;z-index:1000;
    background:rgba(20,27,38,.35);
    display:flex;align-items:center;justify-content:center;padding:20px;
}
.popup-box{
    --pb-bg:#ffffff;
    --pb-border:#e6e9ee;
    --pb-title:#172033;
    --pb-text:#667085;
    --pb-accent:#b51f2a;
    --pb-btn-bg:#ffffff;
    --pb-btn-text:#475467;
    --pb-btn-border:#d9dee5;
    --pb-btn-hover:#f5f6f8;
    width:100%;max-width:320px;
    background:var(--pb-bg);
    border:1px solid var(--pb-border);
    border-radius:12px;
    box-shadow:0 10px 30px rgba(16,24,40,.16);
    padding:20px 20px 16px;
    text-align:left;
}
.popup-box h3{font-size:15px;font-weight:700;color:var(--pb-title);margin:0 0 6px}
.popup-box p{font-size:13px;color:var(--pb-text);line-height:1.5;margin:0}
.popup-box p strong{color:var(--pb-accent);font-weight:700}
.popup-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.popup-btn{
    font:inherit;font-size:12.5px;font-weight:600;
    border-radius:8px;padding:8px 14px;cursor:pointer;text-align:center;
    transition:background .15s ease,border-color .15s ease;
}
.popup-btn.secondary{background:var(--pb-btn-bg);color:var(--pb-btn-text);border:1px solid var(--pb-btn-border)}
.popup-btn.secondary:hover{background:var(--pb-btn-hover)}
.popup-btn.primary{background:var(--pb-accent);color:#fff;border:1px solid var(--pb-accent)}
.popup-btn.primary:hover{background:#9b1a24;border-color:#9b1a24}

/* mode gelap */
html body.dark-mode .popup-overlay{background:rgba(0,0,0,.55)}
html body.dark-mode .popup-box{
    --pb-bg:#151b23;
    --pb-border:#2c3542;
    --pb-title:#edf2f7;
    --pb-text:#9eabbc;
    --pb-accent:#e5424d;
    --pb-btn-bg:#202733;
    --pb-btn-text:#cbd2dc;
    --pb-btn-border:#2c3542;
    --pb-btn-hover:#28303d;
    box-shadow:0 10px 30px rgba(0,0,0,.5);
}
html body.dark-mode .popup-btn.primary{background:#b51f2a;border-color:#b51f2a;color:#fff}
html body.dark-mode .popup-btn.primary:hover{background:#c92834;border-color:#c92834}
</style>
</head>
<body>

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="dashboard-content">

    <div class="dashboard-head">
        <div><h1>Dashboard</h1></div>
        <div class="date-box">📅 <?= htmlspecialchars($hari_ini_teks, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <div class="kpi-grid">

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
                <div class="kpi-foot">Semua pekerjaan pada divisi</div>
            </div>
        </div>

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
                <div class="kpi-number"><?= $selesai; ?></div>
                <div class="kpi-foot">Sudah dikonfirmasi selesai</div>
            </div>
        </div>

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
                <div class="kpi-number"><?= $karyawan_aktif; ?></div>
                <div class="kpi-foot">Karyawan aktif pada divisi</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-top">
                <div class="kpi-label">Perlu Perbaikan</div>
                <div class="kpi-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M12 3 2.7 20h18.6L12 3Z"/>
                        <path d="M12 9v5M12 17h.01"/>
                    </svg>
                </div>
            </div>
            <div class="kpi-bottom">
                <div class="kpi-number"><?= $perbaikan; ?></div>
                <div class="kpi-foot">Menunggu tindak lanjut</div>
            </div>
        </div>

    </div>

    <div class="top-chart-grid">

        <div class="card overview-card">
            <div class="card-head">
                <div class="card-title">
                    <h2>Tren Pekerjaan per Bulan</h2>
                    <p>Jumlah pekerjaan yang tercatat setiap bulan pada tahun <span id="trenYear"><?= h($tahun_ini); ?></span>.</p>
                </div>
                <?= periode_select('selTren', $opsi_tahun, 'Pilih tahun tren pekerjaan'); ?>
            </div>
            <div class="overview-chart-wrap"><canvas id="trendChart"></canvas></div>
        </div>

        <div class="card progress-card">
            <div class="card-head">
                <div class="card-title">
                    <h2>Pekerjaan per Tipe Order</h2>
                    <p>Jumlah pekerjaan berdasarkan tipe order pada <span id="tipeLabel"><?= h($label_bulan); ?></span>.</p>
                </div>
                <?= periode_select('selTipe', $opsi_periode, 'Pilih bulan tipe order'); ?>
            </div>

            <div class="tipe-body">
                <div class="tipe-ring">
                    <svg viewBox="0 0 200 200" role="img" aria-label="Pekerjaan per tipe order">
                        <?php for ($k = 0; $k < 3; $k++): ?>
                        <circle cx="100" cy="100" r="<?= $ring_radius[$k]; ?>" fill="none" stroke="#e5e8ec" stroke-width="<?= $ring_stroke; ?>"/>
                        <circle id="ringProg<?= $k; ?>" cx="100" cy="100" r="<?= $ring_radius[$k]; ?>" fill="none"
                                stroke="<?= h($ring_tipe[$k]['color']); ?>" stroke-width="<?= $ring_stroke; ?>" stroke-linecap="round"
                                data-target="<?= round($ring_tipe[$k]['panjang'], 2); ?>"
                                data-circ="<?= round($ring_tipe[$k]['keliling'], 2); ?>"
                                style="stroke-dasharray:0 <?= round($ring_tipe[$k]['keliling'], 2); ?>;opacity:<?= $ring_tipe[$k]['pct'] > 0 ? 1 : 0; ?>"
                                transform="rotate(-90 100 100)"/>
                        <?php endfor; ?>
                    </svg>
                </div>

                <div class="tipe-stats">
                    <?php for ($k = 0; $k < 3; $k++): ?>
                    <?php if ($k > 0): ?><div class="tipe-divider"></div><?php endif; ?>
                    <div class="tipe-stat">
                        <div class="tipe-num" id="tipeNum<?= $k; ?>"><?= $ring_tipe[$k]['total']; ?></div>
                        <div class="tipe-name" id="tipeName<?= $k; ?>" style="color:<?= h($ring_tipe[$k]['color']); ?>" title="<?= h($ring_tipe[$k]['label']); ?>"><?= h($ring_tipe[$k]['label']); ?></div>
                        <span class="tipe-badge" id="tipeBadge<?= $k; ?>" style="background:<?= h($ring_tipe[$k]['color']); ?>">&#8598; <?= $ring_tipe[$k]['pct']; ?>%</span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="tipe-bottom">
                <div class="tipe-bottom-item"><strong id="tipeTotal"><?= $total_tipe_bulan; ?></strong><span>Total Pekerjaan</span></div>
                <div class="tipe-bottom-item"><strong id="tipeBelum"><?= $belum_bulan; ?></strong><span>Belum Selesai</span></div>
                <div class="tipe-bottom-item"><strong id="tipeSelesai"><?= $selesai_bulan; ?></strong><span>Selesai</span></div>
            </div>
        </div>

    </div>

    <div class="card workload-card">
        <div class="card-head">
            <div class="card-title">
                <h2>Pekerjaan per Karyawan</h2>
                <p>Perbandingan pekerjaan selesai dan belum selesai setiap karyawan pada <span id="workloadLabel"><?= h($label_bulan); ?></span>.</p>
            </div>
            <?= periode_select('selWorkload', $opsi_periode, 'Pilih bulan pekerjaan per karyawan'); ?>
        </div>
        <div class="workload-chart-wrap"><canvas id="workloadChart"></canvas></div>
    </div>

    <div class="card activity-card admin-table-card">
        <div class="activity-head">
            <div class="card-title">
                <h2>Pekerjaan Terbaru</h2>
                <p>Lima pekerjaan terakhir yang tercatat pada divisi <?= htmlspecialchars($nama_divisi, ENT_QUOTES, 'UTF-8'); ?>.</p>
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
                <?php if ($query_pekerjaan && mysqli_num_rows($query_pekerjaan) > 0): ?>
                    <?php $no = 1; while ($pekerjaan = mysqli_fetch_assoc($query_pekerjaan)): ?>
                        <?php
                        $status = $pekerjaan['status'];
                        $badge_class = 'belum';
                        if ($status === 'Menunggu Konfirmasi') $badge_class = 'menunggu';
                        elseif ($status === 'Perlu Perbaikan') $badge_class = 'perbaikan';
                        elseif ($status === 'Peralihan') $badge_class = 'peralihan';
                        elseif ($status === 'Selesai') $badge_class = 'selesai';
                        elseif ($status === 'Sedang Dikerjakan') $badge_class = 'sedang';
                        ?>
                        <tr>
                            <td class="activity-no"><?= $no++; ?></td>
                            <td class="activity-name"><?= htmlspecialchars($pekerjaan['nama_pekerjaan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($pekerjaan['nama_karyawan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= !empty($pekerjaan['jenis_pekerjaan']) ? htmlspecialchars($pekerjaan['jenis_pekerjaan'], ENT_QUOTES, 'UTF-8') : '-'; ?></td>
                            <td><?= !empty($pekerjaan['tipe_order']) ? htmlspecialchars($pekerjaan['tipe_order'], ENT_QUOTES, 'UTF-8') : '-'; ?></td>
                            <td><?= !empty($pekerjaan['nomor_order']) ? htmlspecialchars($pekerjaan['nomor_order'], ENT_QUOTES, 'UTF-8') : '-'; ?></td>
                            <td><?= formatTanggalWaktu($pekerjaan['tanggal_mulai'] ?? ''); ?></td>
                            <td><?= formatTanggalWaktu($pekerjaan['target_selesai'] ?? ''); ?></td>
                            <td><span class="badge <?= $badge_class; ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="empty">Belum ada pekerjaan.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<?php if ($tampil_popup_konfirmasi): ?>
<div class="popup-overlay" id="popupKonfirmasi" role="dialog" aria-modal="true" aria-labelledby="popupKonfirmasiJudul">
    <div class="popup-box">
        <h3 id="popupKonfirmasiJudul">Konfirmasi Pekerjaan</h3>
        <p>Ada <strong><?= $jumlah_konfirmasi; ?> pekerjaan</strong> yang perlu dikonfirmasi.</p>
        <div class="popup-actions">
            <button type="button" class="popup-btn secondary" id="popupTutup">Nanti</button>
            <a href="monitoring.php" class="popup-btn primary">Lihat</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const monthLabels = <?= json_encode($grafik_bulan_labels, JSON_UNESCAPED_UNICODE); ?>;
const trendData = <?= json_encode($grafik_tren_data); ?>;
const workloadLabels = <?= json_encode($grafik_karyawan_labels, JSON_UNESCAPED_UNICODE); ?>;
const workloadSelesaiData = <?= json_encode($grafik_karyawan_selesai); ?>;
const workloadBelumData = <?= json_encode($grafik_karyawan_belum); ?>;

/* Warna grafik mengikuti mode terang / gelap
   (dibaca ulang setiap kali mode berubah) */
function isDarkNow() {
    return document.body.classList.contains("dark-mode");
}

let isDark = isDarkNow();
let warnaGrid  = isDark ? "#2c3542" : "#edf0f3";
let warnaGridB = isDark ? "#2c3542" : "#e6e6e6";
let warnaTeks  = isDark ? "#8f9bac" : "#667085";
let warnaTeksB = isDark ? "#8f9bac" : "#b3b3b3";
let warnaGaris = isDark ? "#2c3542" : "#dfe3e8";

function refreshWarna() {
    isDark = isDarkNow();
    warnaGrid  = isDark ? "#2c3542" : "#edf0f3";
    warnaGridB = isDark ? "#2c3542" : "#e6e6e6";
    warnaTeks  = isDark ? "#8f9bac" : "#667085";
    warnaTeksB = isDark ? "#8f9bac" : "#b3b3b3";
    warnaGaris = isDark ? "#2c3542" : "#dfe3e8";
}

/* Warna batang tren: di mode gelap tidak memakai warna hitam/biru tua
   supaya batang tetap terlihat jelas. */
function trendColors(values) {
    const terang = isDarkNow();

    return values.map((v, i) => {
        if (v === 0) return terang ? "#3a4556" : "#e5e7eb";
        const colors = [terang ? "#cbd2dc" : "#172033", terang ? "#8f9bac" : "#9aa1aa", "#b51f2a"];
        return colors[i % colors.length];
    });
}

const trendCanvas = document.getElementById("trendChart");
let trendChart = null;

if (trendCanvas) {
    trendChart = new Chart(trendCanvas, {
        type: "bar",
        data: {
            labels: monthLabels,
            datasets: [{
                label: "Jumlah Pekerjaan",
                data: trendData,
                backgroundColor: trendColors(trendData),
                borderWidth: 0,
                borderRadius: 0,
                categoryPercentage: 0.9,
                barPercentage: 0.55
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 6, right: 14 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: "#172033",
                    padding: 10,
                    displayColors: false,
                    callbacks: { label: ctx => " " + ctx.raw + " pekerjaan" }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: warnaGaris },
                    ticks: { color: warnaTeks, font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grace: "12%",
                    border: { display: false },
                    grid: { color: warnaGrid, lineWidth: 1 },
                    ticks: { color: warnaTeks, font: { size: 10 }, precision: 0 }
                }
            }
        }
    });
}

/* ===== Plugin batang 3D ===== */
const bar3D = {
    id: 'bar3D',
    beforeUpdate(chart) {
        const n = chart.data.labels.length || 1;
        const catW = Math.max(chart.width - 60, 100) / n;
        const bw = Math.min(56, catW * 0.3);
        const cp = Math.min(0.95, (2 * bw) / catW);
        chart.data.datasets.forEach(ds => {
            ds.categoryPercentage = cp;
            ds.barPercentage = 1;
        });
    },
    afterDatasetsDraw(chart) {
        const ctx = chart.ctx;
        const palettes = [
            { front: '#b51f2a', side: '#7f1820', top: '#df6670' }, // Selesai
            { front: '#7b838e', side: '#59616c', top: '#aeb4bb' }  // Belum Selesai
        ];

        chart.data.datasets.forEach((ds, i) => {
            if (!chart.isDatasetVisible(i)) return;
            const c = palettes[i % palettes.length];
            const meta = chart.getDatasetMeta(i);

            meta.data.forEach(bar => {
                const p = bar.getProps(['x', 'y', 'base', 'width'], true);
                p.y = Math.min(p.y, p.base - 5);     // nilai 0 tetap tampil sebagai batang pendek
                const h = p.base - p.y;
                const w = p.width;
                const fw = w * 0.68;                 // lebar sisi depan
                const d = w - fw;                    // kedalaman 3D
                const dy = Math.min(d * 0.7, h / 2); // kemiringan atas
                const left = p.x - w / 2;
                const right = left + fw;

                ctx.fillStyle = c.front;
                ctx.beginPath();
                ctx.moveTo(left, p.y + dy);
                ctx.lineTo(right, p.y + dy);
                ctx.lineTo(right, p.base);
                ctx.lineTo(left, p.base);
                ctx.closePath();
                ctx.fill();

                ctx.fillStyle = c.side;
                ctx.beginPath();
                ctx.moveTo(right, p.y + dy);
                ctx.lineTo(right + d, p.y);
                ctx.lineTo(right + d, p.base - dy);
                ctx.lineTo(right, p.base);
                ctx.closePath();
                ctx.fill();

                ctx.fillStyle = c.top;
                ctx.beginPath();
                ctx.moveTo(left, p.y + dy);
                ctx.lineTo(left + d, p.y);
                ctx.lineTo(right + d, p.y);
                ctx.lineTo(right, p.y + dy);
                ctx.closePath();
                ctx.fill();
            });
        });
    }
};

const workloadCanvas = document.getElementById("workloadChart");
let workloadChart = null;

if (workloadCanvas) {
    workloadChart = new Chart(workloadCanvas, {
        type: "bar",
        plugins: [bar3D],
        data: {
            labels: workloadLabels,
            datasets: [
                {
                    label: "Selesai",
                    data: workloadSelesaiData,
                    backgroundColor: "rgba(0,0,0,0)",   // digambar oleh plugin
                    borderWidth: 0,
                    categoryPercentage: .7,
                    barPercentage: 1
                },
                {
                    label: "Belum Selesai",
                    data: workloadBelumData,
                    backgroundColor: "rgba(0,0,0,0)",
                    borderWidth: 0,
                    categoryPercentage: .7,
                    barPercentage: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 10, right: 12 } },
            animation: { duration: 900, easing: "easeOutQuart" },
            plugins: {
                legend: {
                    display: true,
                    position: "bottom",
                    labels: {
                        usePointStyle: true,
                        pointStyle: "circle",
                        boxWidth: 8,
                        boxHeight: 8,
                        padding: 18,
                        color: warnaTeks,
                        font: { size: 10, weight: "500" },
                        generateLabels: chart =>
                            chart.data.datasets.map((ds, i) => ({
                                text: ds.label,
                                fillStyle: i === 0 ? "#b51f2a" : "#7b838e",
                                strokeStyle: i === 0 ? "#b51f2a" : "#7b838e",
                                fontColor: warnaTeks,
                                hidden: !chart.isDatasetVisible(i),
                                datasetIndex: i
                            }))
                    }
                },
                tooltip: {
                    backgroundColor: "#172033",
                    padding: 10,
                    callbacks: {
                        label: ctx => " " + ctx.dataset.label + ": " + ctx.parsed.y
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: warnaTeksB,
                        font: { size: 12 },
                        maxRotation: 0,
                        minRotation: 0,
                        autoSkip: false
                    }
                },
                y: {
                    beginAtZero: true,
                    grace: "8%",
                    border: { display: false },
                    grid: { color: warnaGridB, lineWidth: 1 },
                    ticks: {
                        precision: 0,
                        color: warnaTeksB,
                        font: { size: 13 },
                        padding: 8
                    }
                }
            }
        }
    });
}

/* Perbarui warna kedua grafik saat mode terang/gelap berubah
   (juga saat halaman selesai dimuat, kalau mode gelap baru diterapkan
   setelah grafik dibuat) */
function terapkanTemaGrafik() {

    refreshWarna();

    if (trendChart) {
        const ds = trendChart.data.datasets[0];
        ds.backgroundColor = trendColors(ds.data);
        trendChart.options.scales.x.border.color = warnaGaris;
        trendChart.options.scales.x.ticks.color = warnaTeks;
        trendChart.options.scales.y.grid.color = warnaGrid;
        trendChart.options.scales.y.ticks.color = warnaTeks;
        trendChart.update("none");
    }

    if (workloadChart) {
        workloadChart.options.plugins.legend.labels.color = warnaTeks;
        workloadChart.options.scales.x.ticks.color = warnaTeksB;
        workloadChart.options.scales.y.grid.color = warnaGridB;
        workloadChart.options.scales.y.ticks.color = warnaTeksB;
        workloadChart.update("none");
    }
}

new MutationObserver(terapkanTemaGrafik).observe(document.body, {
    attributes: true,
    attributeFilter: ["class"]
});

window.addEventListener("load", terapkanTemaGrafik);

/* =========================================================
   ANIMASI RING TIPE ORDER & ANGKA
========================================================= */

/* Hitung naik angka */
function animasiAngka(el, ke) {
    const dari = parseInt(el.textContent, 10) || 0;
    const mulai = performance.now();
    const durasi = 800;

    function tick(now) {
        const t = Math.min((now - mulai) / durasi, 1);
        const e = 1 - Math.pow(1 - t, 3);
        el.textContent = Math.round(dari + (ke - dari) * e);
        if (t < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
}

/* Ring tumbuh dari 0 saat halaman dibuka */
window.addEventListener("load", function () {
    setTimeout(function () {
        for (let k = 0; k < 3; k++) {
            const ring = document.getElementById("ringProg" + k);
            ring.style.strokeDasharray = ring.dataset.target + " " + ring.dataset.circ;

            const num = document.getElementById("tipeNum" + k);
            const target = parseInt(num.textContent, 10) || 0;
            num.textContent = 0;
            animasiAngka(num, target);
        }
    }, 450);
});

/* =========================================================
   DROPDOWN PERIODE PADA GRAFIK
========================================================= */

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

/* ---------- Tren (per tahun) ---------- */

bindPeriod(
    "selTren",
    function (v) { return { ajax: "tren", tahun: v }; },
    function (d) {
        if (!trendChart) return;
        trendChart.data.datasets[0].data = d.values;
        trendChart.data.datasets[0].backgroundColor = trendColors(d.values);
        trendChart.update();
        document.getElementById("trenYear").textContent = d.tahun;
    }
);

/* ---------- Tipe order (per bulan) ---------- */

const ringRadius = [86, 64, 42];

bindPeriod(
    "selTipe",
    function (v) { return { ajax: "tipe", periode: v }; },
    function (d) {

        for (let k = 0; k < 3; k++) {

            const r    = d.ring[k];
            const ring = document.getElementById("ringProg" + k);
            const circ = 2 * Math.PI * ringRadius[k];

            ring.setAttribute("stroke", r.color);
            ring.style.strokeDasharray = (circ * r.pct / 100).toFixed(2) + " " + circ.toFixed(2);
            ring.style.opacity = r.pct > 0 ? 1 : 0;

            animasiAngka(document.getElementById("tipeNum" + k), r.total);

            const name = document.getElementById("tipeName" + k);
            name.textContent = r.label;
            name.title = r.label;
            name.style.color = r.color;

            const badge = document.getElementById("tipeBadge" + k);
            badge.textContent = "\u2196 " + r.pct + "%";
            badge.style.background = r.color;
        }

        animasiAngka(document.getElementById("tipeTotal"), d.total);
        animasiAngka(document.getElementById("tipeBelum"), d.belum);
        animasiAngka(document.getElementById("tipeSelesai"), d.selesai);
        document.getElementById("tipeLabel").textContent = d.label;
    }
);

/* ---------- Pekerjaan per karyawan (per bulan) ---------- */

bindPeriod(
    "selWorkload",
    function (v) { return { ajax: "workload", periode: v }; },
    function (d) {
        if (!workloadChart) return;
        workloadChart.data.labels = d.labels;
        workloadChart.data.datasets[0].data = d.selesai;
        workloadChart.data.datasets[1].data = d.belum;
        workloadChart.update();
        document.getElementById("workloadLabel").textContent = d.label;
    }
);

/* Animasi isi dashboard seperti Super Admin */
document.addEventListener("DOMContentLoaded", function(){
    const items = document.querySelectorAll(".dashboard-content > *");
    items.forEach(function(item, index){
        item.style.setProperty("--delay", (index * 90) + "ms");
        item.classList.add("content-enter");
    });
});

/* Pop-up pekerjaan perlu dikonfirmasi */
(function () {
    const popup = document.getElementById("popupKonfirmasi");
    if (!popup) return;

    function tutupPopup() {
        popup.remove();
        document.removeEventListener("keydown", tekanEsc);
    }

    function tekanEsc(e) {
        if (e.key === "Escape") tutupPopup();
    }

    document.getElementById("popupTutup").addEventListener("click", tutupPopup);

    popup.addEventListener("click", function (e) {
        if (e.target === popup) tutupPopup();
    });

    document.addEventListener("keydown", tekanEsc);
})();
</script>

</body>
</html>
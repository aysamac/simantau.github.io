
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

$id_user = (int) $_SESSION["id_user"];

$query_user = mysqli_query(
    $koneksi,
    "SELECT nama, role FROM users WHERE id_user = $id_user LIMIT 1"
);

if (!$query_user || mysqli_num_rows($query_user) == 0) {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$user_login = mysqli_fetch_assoc($query_user);
$nama_super_admin = $user_login["nama"] ?? "Super Admin";

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT id_divisi, nama_divisi FROM divisi ORDER BY id_divisi ASC"
);

$divisi_list = [];

if ($query_divisi) {
    while ($d = mysqli_fetch_assoc($query_divisi)) {
        $divisi_list[] = $d;
    }
}

$id_divisi = isset($_GET["divisi"]) ? intval($_GET["divisi"]) : 0;
$status = isset($_GET["status"]) ? trim($_GET["status"]) : "";
$tipe_order = isset($_GET["tipe_order"]) ? trim($_GET["tipe_order"]) : "";
$jenis = isset($_GET["jenis"]) ? trim($_GET["jenis"]) : "Individu";
$filter_bulan = isset($_GET["bulan"]) ? trim($_GET["bulan"]) : "";
$filter_tahun = isset($_GET["tahun"]) ? trim($_GET["tahun"]) : "";
$keyword = isset($_GET["keyword"]) ? trim($_GET["keyword"]) : "";

$bulan_indonesia = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember'
];

$tahun_sekarang = (int) date('Y');
$where = [];

if ($id_divisi > 0) {
    $where[] = "p.id_divisi = $id_divisi";
}

if ($status != "") {
    $status_safe = mysqli_real_escape_string($koneksi, $status);
    $where[] = "p.status = '$status_safe'";
}

if ($tipe_order != "") {
    $tipe_order_safe = mysqli_real_escape_string($koneksi, $tipe_order);
    $where[] = "p.tipe_order = '$tipe_order_safe'";
}

if ($jenis == "Individu") {
    $where[] = "(SELECT COUNT(DISTINCT pk_filter.id_user) FROM pekerjaan_karyawan pk_filter WHERE pk_filter.id_pekerjaan = p.id_pekerjaan AND pk_filter.status_penugasan IN ('Aktif', 'Peralihan')) <= 1";
} elseif ($jenis == "Tim") {
    $where[] = "(SELECT COUNT(DISTINCT pk_filter.id_user) FROM pekerjaan_karyawan pk_filter WHERE pk_filter.id_pekerjaan = p.id_pekerjaan AND pk_filter.status_penugasan IN ('Aktif', 'Peralihan')) > 1";
}

if (
    $filter_bulan != "" &&
    ctype_digit($filter_bulan) &&
    (int) $filter_bulan >= 1 &&
    (int) $filter_bulan <= 12
) {
    $where[] = "MONTH(p.tanggal_mulai) = " . (int) $filter_bulan;
}

if (
    $filter_tahun != "" &&
    ctype_digit($filter_tahun) &&
    (int) $filter_tahun >= 2000 &&
    (int) $filter_tahun <= 2100
) {
    $where[] = "YEAR(p.tanggal_mulai) = " . (int) $filter_tahun;
}

if ($keyword != "") {
    $keyword_safe = mysqli_real_escape_string($koneksi, $keyword);
    $where[] = "(p.nama_pekerjaan LIKE '%$keyword_safe%' OR u.nama LIKE '%$keyword_safe%')";
}

$where_sql = "";

if (!empty($where)) {
    $where_sql = "WHERE " . implode(" AND ", $where);
}

$query_pekerjaan = mysqli_query(
    $koneksi,
    "SELECT
        p.id_pekerjaan,
        p.nama_pekerjaan,
        p.tipe_order,
        p.nomor_order,
        p.tanggal_mulai,
        p.target_selesai,
        p.status,
        d.nama_divisi,
        u.nama AS nama_karyawan
    FROM pekerjaan p
    LEFT JOIN divisi d ON p.id_divisi = d.id_divisi
    LEFT JOIN users u ON p.id_karyawan = u.id_user
    $where_sql
    ORDER BY p.id_pekerjaan DESC"
);

if (!$query_pekerjaan) {
    die("Query pekerjaan gagal: " . mysqli_error($koneksi));
}

$total_data = mysqli_num_rows($query_pekerjaan);
$count_divisi_sql = $id_divisi > 0 ? " WHERE p.id_divisi = $id_divisi" : "";

$query_count_individu = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan p $count_divisi_sql " .
    ($count_divisi_sql ? "AND" : "WHERE") .
    " (SELECT COUNT(DISTINCT pk.id_user) FROM pekerjaan_karyawan pk WHERE pk.id_pekerjaan = p.id_pekerjaan AND pk.status_penugasan IN ('Aktif', 'Peralihan')) <= 1"
);

$query_count_tim = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM pekerjaan p $count_divisi_sql " .
    ($count_divisi_sql ? "AND" : "WHERE") .
    " (SELECT COUNT(DISTINCT pk.id_user) FROM pekerjaan_karyawan pk WHERE pk.id_pekerjaan = p.id_pekerjaan AND pk.status_penugasan IN ('Aktif', 'Peralihan')) > 1"
);

$count_individu = $query_count_individu ? (int) mysqli_fetch_assoc($query_count_individu)['total'] : 0;
$count_tim = $query_count_tim ? (int) mysqli_fetch_assoc($query_count_tim)['total'] : 0;

$nama_divisi_pilih = "Semua Divisi";

if ($id_divisi > 0) {
    $query_nama_divisi = mysqli_query(
        $koneksi,
        "SELECT nama_divisi FROM divisi WHERE id_divisi = $id_divisi LIMIT 1"
    );

    if ($query_nama_divisi && mysqli_num_rows($query_nama_divisi) > 0) {
        $data_nama = mysqli_fetch_assoc($query_nama_divisi);
        $nama_divisi_pilih = $data_nama["nama_divisi"];
    }
}

function statusBadge($status)
{
    switch ($status) {
        case "Sedang Dikerjakan":
            return "badge-sedang";
        case "Menunggu Konfirmasi":
            return "badge-menunggu";
        case "Perlu Perbaikan":
            return "badge-perbaikan";
        case "Peralihan":
            return "badge-peralihan";
        case "Selesai":
            return "badge-selesai";
        default:
            return "badge-belum";
    }
}

function formatTanggal($tanggal)
{
    if (!$tanggal || $tanggal == "0000-00-00") {
        return "-";
    }

    return date("d M Y", strtotime($tanggal));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pekerjaan - SIMO</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #172033;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .main {
            margin-left: 280px;
            width: calc(100% - 280px);
            min-height: 100vh;
            min-width: 0;
        }

        .content {
            padding: 26px 32px 40px;
            min-width: 0;
        }

        .page-title {
            margin-bottom: 22px;
        }

        .page-title h1 {
            font-size: 22px;
            color: #172033;
            margin-bottom: 5px;
        }

        .page-title p {
            font-size: 13px;
            color: #7a8596;
        }

        .job-filter-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
        }

        .job-type-tabs {
            display: flex;
            gap: 10px;
            flex: 0 0 620px;
            min-width: 0;
        }

        .job-type-tab {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex: 1;
            width: auto;
            min-height: 48px;
            padding: 8px 16px;
            border: 1px solid #d9dde2;
            border-radius: 12px;
            background: #ffffff;
            color: #344054;
            font-size: 13px;
            font-weight: 700;
            transition: background .15s ease, border-color .15s ease, color .15s ease;
        }

        .job-type-tab:hover {
            background: #f8f9fb;
            border-color: #b51f2a;
        }

        .job-type-tab.active {
            background: #b51f2a;
            border-color: #b51f2a;
            color: #fff;
        }

        .job-type-icon {
            display: inline-flex;
            width: 20px;
            height: 20px;
            align-items: center;
            justify-content: center;
            flex: 0 0 20px;
        }

        .job-type-icon svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .job-type-tab .job-type-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            height: 26px;
            padding: 0 7px;
            border-radius: 50%;
            background: #eef0f3;
            color: #344054;
            font-size: 11px;
            font-weight: 700;
        }

        .job-type-tab.active .job-type-count {
            background: rgba(255,255,255,.22);
            color: #fff;
        }

        .job-type-arrow {
            color: #9aa3b1;
            font-size: 20px;
            flex: 0 0 auto;
            margin-left: 0;
            margin-right: 0;
        }

        .division-select-wrap {
            width: 220px;
            flex: 0 0 220px;
            transform: translateY(-10px);
        }

        .division-select-label {
            display: block;
            margin-bottom: 6px;
            color: #344054;
            font-size: 12px;
            font-weight: 700;
        }

        .division-select {
            width: 100%;
            height: 48px;
            padding: 0 12px;
            border: 1px solid #d9dde2;
            border-radius: 10px;
            background: #ffffff;
            color: #344054;
            font-size: 12.5px;
            outline: none;
        }

        .division-select:focus {
            border-color: #b51f2a;
        }

        .filter-box {
            background: transparent;
            border-radius: 0;
            padding: 0 0 24px;
            margin-bottom: 26px;
            border-bottom: 1px solid #e8ebef;
            box-shadow: none;
        }

        .filter-title {
            font-size: 14px;
            font-weight: 700;
            color: #172033;
            margin-bottom: 14px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .filter-group label {
            font-size: 11.5px;
            font-weight: 600;
            color: #667085;
        }

        .filter-row .form-control,
        .filter-row .btn-filter {
            height: 38px;
        }

        .form-control {
            width: 100%;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid #d9dde2;
            border-radius: 8px;
            background: #ffffff;
            color: #344054;
            font-size: 12.5px;
            outline: none;
        }

        .form-control:focus {
            border-color: #b51f2a;
        }

        .btn-filter {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            background: #b51f2a;
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-filter:hover {
            background: #941923;
        }

        .btn-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 1px solid #d9dde2;
            border-radius: 8px;
            background: #ffffff;
            color: #667085;
            font-size: 12.5px;
            font-weight: 600;
        }

        .btn-reset:hover {
            background: #f8f9fb;
        }

        .table-card {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
            min-width: 0;
        }

        .table-head {
            padding: 0 0 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e8ebef;
            gap: 12px;
        }

        .table-head h3 {
            font-size: 15px;
            color: #172033;
        }

        .table-head h3 span {
            color: #b51f2a;
        }

        .total-data {
            font-size: 12px;
            color: #7a8596;
            white-space: nowrap;
        }

        .table-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
            background: #ffffff;
        }

        th {
            background: #b51f2a;
            padding: 14px 18px;
            text-align: left;
            font-size: 12px;
            color: #ffffff;
            font-weight: 700;
            border-bottom: 1px solid #b51f2a;
        }

        th:first-child {
            border-top-left-radius: 8px;
        }

        th:last-child {
            border-top-right-radius: 8px;
        }

        td {
            padding: 16px 18px;
            font-size: 13.5px;
            color: #172033;
            border-bottom: 1px solid #eef0f3;
            background: #ffffff;
        }

        tbody tr {
            transition: background 0.15s ease;
        }

        tbody tr:hover td {
            background: #f8f9fb;
        }

        .job-name {
            font-weight: 600;
            color: #172033;
        }

        .employee {
            color: #172033;
        }

        .order-number {
            display: block;
            margin-top: 3px;
            font-size: 11.5px;
            color: #172033;
        }

        .order-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 700;
            white-space: nowrap;
        }

        .order-perbaikan {
            background: #f9e9eb;
            color: #b51f2a;
        }

        .non-order {
            background: #eef0f3;
            color: #667085;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-belum {
            background: #eef0f3;
            color: #667085;
        }

        .badge-sedang {
            background: #edf3ff;
            color: #3f65b5;
        }

        .badge-menunggu {
            background: #fff5dc;
            color: #a46b00;
        }

        .badge-perbaikan {
            background: #fde5e5;
            color: #c94242;
        }

        .badge-peralihan {
            background: #eee7ff;
            color: #7252b8;
        }

        .badge-selesai {
            background: #e9f8f0;
            color: #168353;
        }

        .btn-detail {
            display: inline-block;
            padding: 7px 12px;
            border: 1px solid #d9dde2;
            border-radius: 7px;
            color: #b31824;
            font-size: 11px;
            font-weight: 600;
            background: #fff8f8;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            white-space: nowrap;
        }

        .btn-detail:hover {
            background: #b51f2a;
            border-color: #b51f2a;
            color: #ffffff;
        }

        .empty-data {
            text-align: center;
            padding: 45px 20px;
            color: #8a94a5;
            font-size: 13px;
        }

        .empty-data strong {
            display: block;
            color: #667085;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: #9aa3b1;
            padding: 26px 0 6px;
        }

        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .content {
                padding: 24px;
            }

            .job-filter-top {
                flex-wrap: wrap;
                align-items: flex-start;
            }

            .job-type-tabs {
                flex: 1 1 100%;
                width: 100%;
            }

            .division-select-wrap {
                width: 240px;
                flex: 0 1 240px;
                transform: none;
            }

            .job-type-arrow {
                display: none;
            }

            .filter-row {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .filter-row .filter-group:first-child {
                grid-column: span 2;
            }

            .filter-row .btn-filter {
                width: 100%;
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
            }

            .content {
                padding: 20px 16px 30px;
            }

            .job-filter-top {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .job-type-tabs {
                display: flex;
                width: 100%;
                flex: 1 1 auto;
                gap: 8px;
            }

            .job-type-tab {
                flex: 1 1 0;
                width: auto;
                min-width: 0;
                padding: 8px;
                font-size: 12px;
                gap: 6px;
            }

            .job-type-arrow {
                display: none;
            }

            .division-select-wrap {
                width: 100%;
                flex: 1 1 auto;
                transform: none;
            }

            .filter-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .filter-row .filter-group:first-child {
                grid-column: 1 / -1;
            }

            .filter-row .form-control,
            .filter-row .btn-filter {
                width: 100%;
                min-width: 0;
            }

            .table-head {
                flex-wrap: wrap;
                align-items: flex-start;
            }

            table {
                min-width: 950px;
            }
        }

        @media (max-width: 480px) {
            .content {
                padding: 16px 12px 26px;
            }

            .page-title h1 {
                font-size: 20px;
            }

            .page-title p {
                line-height: 1.5;
            }

            .job-type-tabs {
                flex-direction: column;
                width: 100%;
            }

            .job-type-tab {
                width: 100%;
                min-height: 44px;
                padding: 10px 12px;
            }

            .division-select-wrap {
                width: 100%;
            }

            .filter-row {
                grid-template-columns: minmax(0, 1fr);
            }

            .filter-row .filter-group:first-child {
                grid-column: auto;
            }

            .filter-row .btn-filter,
            .btn-reset {
                width: 100%;
            }

            .table-head {
                align-items: flex-start;
            }

            .table-wrapper {
                max-width: 100%;
                overflow-x: auto;
            }

            table {
                min-width: 850px;
            }

            .empty-data {
                padding: 35px 14px;
            }
        }

        /* DARK MODE */
        html:has(body.dark-mode),
        html:has(body.dark-mode) body {
            background: #0f141b !important;
        }

        html body.dark-mode {
            background: #0f141b !important;
            color: #e8edf4 !important;
        }

        html body.dark-mode .main,
        html body.dark-mode .content {
            background: #0f141b !important;
        }

        html body.dark-mode .page-title h1 {
            color: #edf2f7 !important;
        }

        html body.dark-mode .page-title p {
            color: #8f9bac !important;
        }

        html body.dark-mode .job-type-tab {
            background: #151b23 !important;
            border-color: #2c3542 !important;
            color: #cbd2dc !important;
        }

        html body.dark-mode .job-type-tab:hover {
            background: #202733 !important;
            border-color: #b51f2a !important;
        }

        html body.dark-mode .job-type-tab.active {
            background: #b51f2a !important;
            border-color: #b51f2a !important;
            color: #fff !important;
        }

        html body.dark-mode .job-type-tab .job-type-count {
            background: #2c3542 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .job-type-tab.active .job-type-count {
            background: rgba(255,255,255,.22) !important;
            color: #fff !important;
        }

        html body.dark-mode .job-type-arrow {
            color: #8f9bac !important;
        }

        html body.dark-mode .division-select-label {
            color: #cbd2dc !important;
        }

        html body.dark-mode .division-select {
            background: #151b23 !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .division-select option {
            background: #151b23 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .filter-box {
            border-bottom-color: #2c3542 !important;
        }

        html body.dark-mode .filter-title {
            color: #edf2f7 !important;
        }

        html body.dark-mode .filter-group label {
            color: #cbd2dc !important;
        }

        html body.dark-mode .form-control {
            background: #151b23 !important;
            border-color: #2c3542 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .form-control::placeholder {
            color: #8f9bac !important;
        }

        html body.dark-mode .form-control option {
            background: #151b23 !important;
            color: #edf2f7 !important;
        }

        html body.dark-mode .btn-reset {
            background: #151b23 !important;
            border-color: #2c3542 !important;
            color: #cbd2dc !important;
        }

        html body.dark-mode .btn-reset:hover {
            background: #202733 !important;
            color: #f07a80 !important;
        }

        html body.dark-mode .table-head {
            border-bottom-color: #2c3542 !important;
        }

        html body.dark-mode .table-head h3 {
            color: #edf2f7 !important;
        }

        html body.dark-mode .table-head h3 span {
            color: #f07a80 !important;
        }

        html body.dark-mode .total-data {
            color: #8f9bac !important;
        }

        html body.dark-mode table {
            background: #151b23 !important;
        }

        html body.dark-mode th {
            background: #b51f2a !important;
            color: #fff !important;
            border-bottom-color: #b51f2a !important;
        }

        html body.dark-mode td {
            background: #151b23 !important;
            color: #fff !important;
            border-bottom-color: #2c3542 !important;
        }

        html body.dark-mode tbody tr:hover td {
            background: #202733 !important;
        }

        html body.dark-mode .job-name,
        html body.dark-mode .employee,
        html body.dark-mode .order-number {
            color: #fff !important;
        }

        html body.dark-mode .order-perbaikan {
            background: #382326 !important;
            color: #f07a80 !important;
        }

        html body.dark-mode .non-order {
            background: #202733 !important;
            color: #cbd2dc !important;
        }

        html body.dark-mode .badge-belum {
            background: #34383e !important;
            color: #c5cbd3 !important;
        }

        html body.dark-mode .badge-sedang {
            background: #1d3158 !important;
            color: #8eb5ff !important;
        }

        html body.dark-mode .badge-menunggu {
            background: #3c3924 !important;
            color: #f2d77d !important;
        }

        html body.dark-mode .badge-perbaikan {
            background: #4b2d32 !important;
            color: #ff8b92 !important;
        }

        html body.dark-mode .badge-peralihan {
            background: #2f2748 !important;
            color: #b9a2f0 !important;
        }

        html body.dark-mode .badge-selesai {
            background: #183d2a !important;
            color: #72d89a !important;
        }

        html body.dark-mode .btn-detail {
            background: #382326 !important;
            border-color: #51363b !important;
            color: #f07a80 !important;
        }

        html body.dark-mode .btn-detail:hover {
            background: #b51f2a !important;
            border-color: #b51f2a !important;
            color: #fff !important;
        }

        html body.dark-mode .empty-data {
            color: #8f9bac !important;
        }

        html body.dark-mode .empty-data strong {
            color: #cbd2dc !important;
        }

        html body.dark-mode .footer-note {
            color: #8f9bac !important;
        }
    </style>
</head>
<body>
    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">
        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">
            <div class="page-title">
                <h1>Data Pekerjaan</h1>
                <p>Pilih divisi untuk melihat pekerjaan pada masing-masing divisi.</p>
            </div>

            <div class="job-filter-top">
                <div class="job-type-tabs">
                    <?php
                    $params = $_GET;
                    $params['jenis'] = 'Individu';
                    $url_individu = 'pekerjaan.php?' . http_build_query($params);
                    $params['jenis'] = 'Tim';
                    $url_tim = 'pekerjaan.php?' . http_build_query($params);
                    ?>

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

                <span class="job-type-arrow">›</span>

                <div class="division-select-wrap">
                    <label class="division-select-label">Divisi</label>
                    <select class="division-select" onchange="window.location.href=this.value">
                        <option value="pekerjaan.php<?= $jenis != '' ? '?jenis=' . urlencode($jenis) : ''; ?>" <?= $id_divisi == 0 ? 'selected' : ''; ?>>Semua Divisi</option>
                        <?php foreach ($divisi_list as $divisi): ?>
                            <?php $divisi_url = 'pekerjaan.php?divisi=' . (int)$divisi['id_divisi'] . ($jenis != '' ? '&jenis=' . urlencode($jenis) : ''); ?>
                            <option value="<?= htmlspecialchars($divisi_url, ENT_QUOTES, 'UTF-8'); ?>" <?= $id_divisi == $divisi['id_divisi'] ? 'selected' : ''; ?>><?= htmlspecialchars($divisi['nama_divisi'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <form method="GET" class="filter-box">
                <input type="hidden" name="jenis" value="<?= htmlspecialchars($jenis, ENT_QUOTES, "UTF-8"); ?>">

                <?php if ($id_divisi > 0): ?>
                    <input type="hidden" name="divisi" value="<?= $id_divisi; ?>">
                <?php endif; ?>

                <div class="filter-title">Filter Pekerjaan</div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label>Pencarian</label>
                        <input type="text" name="keyword" class="form-control" placeholder="Cari nama pekerjaan atau karyawan..." value="<?= htmlspecialchars($keyword, ENT_QUOTES, "UTF-8"); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">Semua Status</option>
                            <option value="Belum Dikerjakan" <?= $status == "Belum Dikerjakan" ? "selected" : ""; ?>>Belum Dikerjakan</option>
                            <option value="Menunggu Konfirmasi" <?= $status == "Menunggu Konfirmasi" ? "selected" : ""; ?>>Menunggu Konfirmasi</option>
                            <option value="Perlu Perbaikan" <?= $status == "Perlu Perbaikan" ? "selected" : ""; ?>>Perlu Perbaikan</option>
                            <option value="Peralihan" <?= $status == "Peralihan" ? "selected" : ""; ?>>Peralihan</option>
                            <option value="Selesai" <?= $status == "Selesai" ? "selected" : ""; ?>>Selesai</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Tipe Order</label>
                        <select name="tipe_order" class="form-control">
                            <option value="">Semua Tipe Order</option>
                            <option value="Order Perbaikan" <?= $tipe_order == "Order Perbaikan" ? "selected" : ""; ?>>Order Perbaikan</option>
                            <option value="Non Order" <?= $tipe_order == "Non Order" ? "selected" : ""; ?>>Non Order</option>
                            <option value="Maintenance" <?= $tipe_order == "Maintenance" ? "selected" : ""; ?>>Maintenance</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Bulan</label>
                        <select name="bulan" class="form-control">
                            <option value="">Semua Bulan</option>
                            <?php foreach ($bulan_indonesia as $nomor_bulan => $nama_bulan): ?>
                                <option value="<?= $nomor_bulan; ?>" <?= ((string) $filter_bulan === (string) $nomor_bulan) ? "selected" : ""; ?>><?= $nama_bulan; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Tahun</label>
                        <select name="tahun" class="form-control">
                            <option value="">Semua Tahun</option>
                            <?php for ($th = $tahun_sekarang + 1; $th >= $tahun_sekarang - 5; $th--): ?>
                                <option value="<?= $th; ?>" <?= ((string) $filter_tahun === (string) $th) ? "selected" : ""; ?>><?= $th; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-filter">Cari</button>
                </div>

                <?php if ($keyword != "" || $status != "" || $tipe_order != "" || $filter_bulan != "" || $filter_tahun != "" || $jenis != "Individu"): ?>
                    <div style="margin-top:12px;">
                        <a href="<?= $id_divisi > 0 ? 'pekerjaan.php?divisi=' . $id_divisi . '&jenis=Individu' : 'pekerjaan.php?jenis=Individu'; ?>" class="btn-reset">Reset Filter</a>
                    </div>
                <?php endif; ?>
            </form>

            <div class="table-card">
                <div class="table-head">
                    <h3>
                        Data Pekerjaan
                        <?php if ($id_divisi > 0): ?>
                            <span>— <?= htmlspecialchars($nama_divisi_pilih, ENT_QUOTES, "UTF-8"); ?></span>
                        <?php endif; ?>
                    </h3>
                    <span class="total-data"><?= $total_data; ?> pekerjaan</span>
                </div>

                <div class="table-wrapper">
                    <?php if ($total_data > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Pekerjaan</th>
                                    <th>Karyawan</th>
                                    <th>Tipe Order</th>
                                    <th>Tanggal Mulai</th>
                                    <th>Target Selesai</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                while ($row = mysqli_fetch_assoc($query_pekerjaan)):
                                ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="job-name"><?= htmlspecialchars($row["nama_pekerjaan"], ENT_QUOTES, "UTF-8"); ?></div>
                                        </td>
                                        <td class="employee"><?= htmlspecialchars($row["nama_karyawan"] ?? "-", ENT_QUOTES, "UTF-8"); ?></td>
                                        <td>
                                            <?php if ($row["tipe_order"] == "Order Perbaikan"): ?>
                                                <span class="order-badge order-perbaikan">Order Perbaikan</span>
                                                <?php if (!empty($row["nomor_order"])): ?>
                                                    <span class="order-number">
                                                        No. Order: <?= htmlspecialchars($row["nomor_order"], ENT_QUOTES, "UTF-8"); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="order-badge non-order">Non Order</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= formatTanggal($row["tanggal_mulai"]); ?></td>
                                        <td><?= formatTanggal($row["target_selesai"]); ?></td>
                                        <td>
                                            <span class="badge <?= statusBadge($row["status"]); ?>">
                                                <?= htmlspecialchars($row["status"], ENT_QUOTES, "UTF-8"); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="detail_pekerjaan.php?id=<?= $row['id_pekerjaan']; ?>" class="btn-detail">Lihat Detail</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-data">
                            <strong>Belum ada data pekerjaan</strong>
                            <?php if ($id_divisi > 0): ?>
                                Belum ada pekerjaan pada divisi <?= htmlspecialchars($nama_divisi_pilih, ENT_QUOTES, "UTF-8"); ?>.
                            <?php else: ?>
                                Belum ada pekerjaan yang tersimpan dalam sistem.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="footer-note">
                &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>
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

/* ===================== PASTIKAN STATUS 'Dihapus' DIDUKUNG ===================== */
function pastikan_status_dihapus($koneksi)
{
    try {
        $cek = mysqli_query($koneksi, "SHOW COLUMNS FROM `users` LIKE 'status'");
        $c = $cek ? mysqli_fetch_assoc($cek) : null;

        /* hanya perlu diubah jika kolom bertipe ENUM */
        if (!$c || stripos($c["Type"], "enum(") !== 0) {
            return;
        }

        preg_match_all("/'((?:[^']|'')*)'/", $c["Type"], $m);
        $ada = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);

        if (in_array("Dihapus", $ada, true)) {
            return;
        }

        $ada[] = "Dihapus";

        $literal = implode(",", array_map(function ($v) use ($koneksi) {
            return "'" . mysqli_real_escape_string($koneksi, $v) . "'";
        }, $ada));

        $null = $c["Null"] === "YES" ? "NULL" : "NOT NULL";
        $default = "";
        if ($c["Default"] !== null) {
            $default = " DEFAULT '" . mysqli_real_escape_string($koneksi, $c["Default"]) . "'";
        } elseif ($c["Null"] === "YES") {
            $default = " DEFAULT NULL";
        }

        mysqli_query($koneksi, "ALTER TABLE `users` MODIFY `status` ENUM($literal) $null$default");
    } catch (Throwable $e) {
        error_log("Gagal menambah status Dihapus: " . $e->getMessage());
    }
}

/* ===================== ADMIN DIVISI: TOGGLE ===================== */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["toggle_admin"])
) {
    $id_toggle = (int) $_POST["toggle_admin"];

    if ($id_toggle > 0) {
        try {
            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET status = IF(TRIM(status) = 'Aktif', 'Tidak Aktif', 'Aktif')
                 WHERE id_user = ?
                 AND role = 'Admin'"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $id_toggle);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } catch (Throwable $e) {
            error_log("Toggle admin gagal: " . $e->getMessage());
        }
    }

    header("Location: pengguna.php?tab=admin");
    exit;
}

/* ===================== ADMIN DIVISI: HAPUS ===================== */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["hapus_admin"])
) {
    $id_hapus = (int) $_POST["hapus_admin"];
    $hasil_hapus = "admin_gagal";

    if ($id_hapus > 0) {
        try {
            pastikan_status_dihapus($koneksi);

            /* Soft delete: baris tetap ada, status diubah jadi 'Dihapus'
               supaya saat login muncul pesan "akun telah dihapus". */
            $stmt_hapus = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET status = 'Dihapus'
                 WHERE id_user = ?
                 AND role = 'Admin'"
            );

            if ($stmt_hapus) {
                mysqli_stmt_bind_param($stmt_hapus, "i", $id_hapus);

                if (mysqli_stmt_execute($stmt_hapus)) {
                    $hasil_hapus = "admin_dihapus";
                }

                mysqli_stmt_close($stmt_hapus);
            }
        } catch (Throwable $e) {
            error_log("Hapus admin gagal: " . $e->getMessage());
        }
    }

    header("Location: pengguna.php?tab=admin&pesan=" . $hasil_hapus);
    exit;
}

/* ===================== DEPARTEMEN: TOGGLE ===================== */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["toggle_departemen"])
) {
    $id_toggle = (int) $_POST["toggle_departemen"];

    if ($id_toggle > 0) {
        try {
            $stmt = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET status = IF(TRIM(status) = 'Aktif', 'Tidak Aktif', 'Aktif')
                 WHERE id_user = ?
                 AND role = 'Departemen'"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $id_toggle);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } catch (Throwable $e) {
            error_log("Toggle departemen gagal: " . $e->getMessage());
        }
    }

    header("Location: pengguna.php?tab=departemen");
    exit;
}

/* ===================== DEPARTEMEN: HAPUS ===================== */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["hapus_departemen"])
) {
    $id_hapus = (int) $_POST["hapus_departemen"];
    $hasil_hapus = "departemen_gagal";

    if ($id_hapus > 0) {
        try {
            pastikan_status_dihapus($koneksi);

            $stmt_hapus = mysqli_prepare(
                $koneksi,
                "UPDATE users
                 SET status = 'Dihapus'
                 WHERE id_user = ?
                 AND role = 'Departemen'"
            );

            if ($stmt_hapus) {
                mysqli_stmt_bind_param($stmt_hapus, "i", $id_hapus);

                if (mysqli_stmt_execute($stmt_hapus)) {
                    $hasil_hapus = "departemen_dihapus";
                }

                mysqli_stmt_close($stmt_hapus);
            }
        } catch (Throwable $e) {
            error_log("Hapus departemen gagal: " . $e->getMessage());
        }
    }

    header("Location: pengguna.php?tab=departemen&pesan=" . $hasil_hapus);
    exit;
}

$daftar_pesan = [
    "admin_dihapus"       => ["success", "Admin Divisi berhasil dihapus."],
    "admin_diubah"        => ["success", "Data Admin Divisi berhasil diperbarui."],
    "admin_berhasil"      => ["success", "Admin Divisi berhasil ditambahkan."],
    "admin_gagal"         => ["error", "Aksi gagal diproses. Pastikan kolom status mendukung nilai 'Dihapus'."],
    "departemen_dihapus"  => ["success", "Akun Departemen berhasil dihapus."],
    "departemen_diubah"   => ["success", "Data akun Departemen berhasil diperbarui."],
    "departemen_berhasil" => ["success", "Akun Departemen berhasil ditambahkan."],
    "departemen_gagal"    => ["error", "Aksi gagal diproses. Pastikan kolom status mendukung nilai 'Dihapus'."],
];

$pesan_kode = $_GET["pesan"] ?? "";
$pesan_tampil = $daftar_pesan[$pesan_kode] ?? null;

// Tab aktif: "admin" (default), "karyawan", atau "departemen"
$tab_dipilih = "admin";

if (
    isset($_GET["tab"]) &&
    in_array($_GET["tab"], ["karyawan", "departemen"], true)
) {
    $tab_dipilih = $_GET["tab"];
}

$divisi_dipilih = isset($_GET["divisi"])
    ? (int) $_GET["divisi"]
    : 0;

$status_dipilih = isset($_GET["status"])
    ? $_GET["status"]
    : "";

if (
    !in_array(
        $status_dipilih,
        ["Aktif", "Tidak Aktif", ""],
        true
    )
) {
    $status_dipilih = "";
}

$query_admin = "
    SELECT
        u.id_user,
        u.nama,
        u.username,
        u.status,
        u.id_divisi,
        d.nama_divisi
    FROM users u
    LEFT JOIN divisi d
        ON u.id_divisi = d.id_divisi
    WHERE u.role = 'Admin'
    AND COALESCE(TRIM(u.status), '') <> 'Dihapus'
    ORDER BY
        CASE
            WHEN TRIM(u.status) = 'Aktif' THEN 0
            ELSE 1
        END ASC,
        u.nama ASC
";

$result_admin = mysqli_query($koneksi, $query_admin);

if (!$result_admin) {
    die(
        "Query Admin gagal: " .
        mysqli_error($koneksi)
    );
}

$query_divisi = "
    SELECT
        id_divisi,
        nama_divisi
    FROM divisi
    ORDER BY id_divisi ASC
";

$result_divisi = mysqli_query($koneksi, $query_divisi);

if (!$result_divisi) {
    die(
        "Query Divisi gagal: " .
        mysqli_error($koneksi)
    );
}

$query_karyawan = "
    SELECT
        u.id_user,
        u.nama,
        u.username,
        u.status,
        u.id_divisi,
        d.nama_divisi
    FROM users u
    LEFT JOIN divisi d
        ON u.id_divisi = d.id_divisi
    WHERE u.role = 'Karyawan'
";

if ($divisi_dipilih > 0) {
    $query_karyawan .= "
        AND u.id_divisi = $divisi_dipilih
    ";
}

if ($status_dipilih != "") {
    $status_sql = mysqli_real_escape_string(
        $koneksi,
        $status_dipilih
    );

    $query_karyawan .= "
        AND u.status = '$status_sql'
    ";
}

$query_karyawan .= "
    ORDER BY u.nama ASC
";

$result_karyawan = mysqli_query($koneksi, $query_karyawan);

if (!$result_karyawan) {
    die(
        "Query Karyawan gagal: " .
        mysqli_error($koneksi)
    );
}

$nama_divisi_terpilih = "Daftar Karyawan";

if ($divisi_dipilih > 0) {
    mysqli_data_seek($result_divisi, 0);

    while ($d = mysqli_fetch_assoc($result_divisi)) {
        if (
            (int) $d["id_divisi"] ===
            $divisi_dipilih
        ) {
            $nama_divisi_terpilih =
                "Karyawan " .
                $d["nama_divisi"];

            break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengguna - SIMO</title>

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: "Segoe UI", Arial, sans-serif;
        background: #ffffff;
        color: #253044;
    }

    a {
        text-decoration: none;
        color: inherit;
    }

    .main {
        min-height: 100vh;
        margin-left: 240px;
        width: calc(100% - 240px);
        min-width: 0;
        background: #ffffff;
    }

    .content {
        padding: 28px 32px 34px;
    }

    .page-title {
        margin-bottom: 20px;
    }

    .page-title h1 {
        font-size: 23px;
        line-height: 1.3;
        font-weight: 700;
        color: #1d2939;
        margin-bottom: 5px;
    }

    .page-title p {
        font-size: 13px;
        line-height: 1.5;
        color: #8a95a5;
    }

    .section {
        margin-bottom: 34px;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
    }

    .section-title-area {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .section-title {
        font-size: 16px;
        line-height: 1.35;
        font-weight: 700;
        color: #1d2939;
    }

    .section-description {
        font-size: 12px;
        line-height: 1.5;
        color: #8a95a5;
        margin-top: 3px;
    }

    .admin-panel {
        background: #ffffff;
        border: 1px solid #d9dee5;
        border-radius: 11px;
        overflow: hidden;
    }

    .admin-panel .section-header {
        padding: 15px 17px;
        margin: 0;
        border-bottom: 1px solid #d9dee5;
    }

    .admin-panel .table-card,
    .employee-panel .table-card {
        border: 0;
        border-top: 1px solid #d9dee5;
        border-radius: 0;
        box-shadow: none;
        background: transparent;
        overflow: visible;
        margin: 0;
    }

    .btn-tambah {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 0 16px;
        border: 0;
        border-radius: 8px;
        background: #b52a31;
        color: #ffffff;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        transition: background .15s ease, transform .15s ease;
    }

    .btn-tambah:hover {
        background: #9d232a;
        transform: translateY(-1px);
    }

    .employee-panel {
        background: #ffffff;
        border: 1px solid #d9dee5;
        border-radius: 11px;
        overflow: hidden;
    }

    .employee-controls-card {
        background: #ffffff;
        border: 0;
        border-radius: 0;
        padding: 15px 16px 16px;
        margin: 0;
    }

    .employee-controls-card .section-header {
        margin-bottom: 12px;
    }

    .divisi-buttons {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .btn-divisi {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 62px;
        height: 36px;
        padding: 0 14px;
        border-radius: 8px;
        border: 1px solid #d7dde4;
        background: #ffffff;
        color: #667085;
        font-size: 12px;
        font-weight: 600;
        transition: background .15s ease, color .15s ease, border-color .15s ease;
    }

    .btn-divisi:hover {
        background: #fff8f8;
        color: #b52a31;
        border-color: #dfb8bc;
    }

    .btn-divisi.active {
        background: #b52a31;
        border-color: #b52a31;
        color: #ffffff;
    }

    .filter-card {
        background: transparent;
        border: 0;
        border-radius: 0;
        padding: 0;
        margin-top: 14px;
        box-shadow: none;
    }

    .filter-row {
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        width: 300px;
    }

    .filter-group label {
        font-size: 11.5px;
        color: #7b8797;
    }

    .filter-group select {
        width: 100%;
        height: 38px;
        padding: 0 11px;
        border: 1px solid #d1d7de;
        border-radius: 8px;
        background: #ffffff;
        color: #344054;
        font-family: "Segoe UI", Arial, sans-serif;
        font-size: 12px;
        outline: none;
    }

    .filter-group select:focus {
        border-color: #b52a31;
    }

    .btn-filter,
    .btn-reset {
        height: 38px;
        border-radius: 8px;
        font-family: "Segoe UI", Arial, sans-serif;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .btn-filter {
        padding: 0 17px;
        border: 0;
        background: #b52a31;
        color: #ffffff;
        transition: background .15s ease;
    }

    .btn-filter:hover {
        background: #9d232a;
    }

    .btn-reset {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 15px;
        border: 1px solid #d1d7de;
        background: #ffffff;
        color: #667085;
    }

    .btn-reset:hover {
        background: #f8f9fa;
    }

    .table-card {
        background: transparent;
        border: 0;
        border-radius: 0;
        overflow: visible;
        box-shadow: none;
    }

    .employee-panel .table-card {
        margin-top: 22px;
        border-top: 1px solid #d9dee5;
    }

    .table-head {
        padding: 14px 16px 12px;
        border-bottom: 1px solid #d9dee5;
    }

    .table-head h3 {
        font-size: 15px;
        line-height: 1.35;
        font-weight: 700;
        color: #1d2939;
    }

    .table-wrapper {
        width: 100%;
        overflow-x: auto;
    }

    .employee-panel .table-wrapper {
        width: 100%;
        margin: 0;
        padding: 0 0 14px;
    }

    table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
        border-spacing: 0;
        table-layout: fixed;
    }

    .admin-table th:nth-child(1),
    .admin-table td:nth-child(1) { width: 7%; }

    .admin-table th:nth-child(2),
    .admin-table td:nth-child(2) { width: 18%; }

    .admin-table th:nth-child(3),
    .admin-table td:nth-child(3) { width: 17%; }

    .admin-table th:nth-child(4),
    .admin-table td:nth-child(4) { width: 14%; }

    .admin-table th:nth-child(5),
    .admin-table td:nth-child(5) { width: 18%; }

    .admin-table th:nth-child(6),
    .admin-table td:nth-child(6) { width: 11%; }

    .admin-table th:nth-child(7),
    .admin-table td:nth-child(7) { width: 15%; }

    th {
        background: #f8f9fb;
        color: #667085;
        font-size: 11.5px;
        font-weight: 700;
        text-align: center;
        padding: 11px 9px;
        border: 0;
        border-bottom: 1px solid #e1e5ea;
        white-space: nowrap;
    }

    td {
        padding: 13px 9px;
        font-size: 12px;
        color: #4b5870;
        text-align: center;
        vertical-align: middle;
        line-height: 1.4;
        border: 0;
        border-bottom: 1px solid #edf0f3;
    }

    .employee-panel table th,
    .employee-panel table td {
        text-align: left;
    }

    .admin-table th,
    .admin-table td {
        text-align: left;
    }

    .employee-panel .table-card,
    .employee-panel .table-wrapper,
    .employee-panel table {
        width: 100%;
        margin-left: 0;
        margin-right: 0;
    }

    .employee-panel .table-head {
        padding-left: 22px;
        padding-right: 22px;
    }

    .employee-panel table th:first-child,
    .employee-panel table td:first-child {
        padding-left: 22px;
    }

    .employee-panel table th:last-child,
    .employee-panel table td:last-child {
        padding-right: 22px;
    }

    tbody tr {
        background: #ffffff;
    }

    tbody tr:nth-child(odd) {
        background: #f8f9fa;
    }

    tbody tr:nth-child(even) {
        background: #ffffff;
    }

    tbody tr:hover {
        background: #fff4f5 !important;
    }

    tbody tr:last-child td {
        border-bottom-color: #e1e5ea;
    }

    .admin-inactive {
        background: inherit;
    }

    .admin-inactive td {
        color: #a1a8b0 !important;
    }

    .admin-inactive:hover {
        background: #fff4f5 !important;
    }

    .admin-inactive .admin-name {
        color: #969da6 !important;
        font-weight: 500;
    }

    .admin-inactive .admin-username,
    .admin-inactive .admin-divisi {
        color: #a7adb5 !important;
    }

    .admin-inactive .user-role-admin,
    .admin-inactive .btn-edit {
        background: #eeeeef;
        color: #999fa7;
    }

    .admin-inactive .btn-edit:hover {
        background: #e5e6e8;
    }

    .user-role-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 9px;
        border-radius: 6px;
        font-size: 10.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .user-role-admin {
        background: #f8e8ea;
        color: #b52a31;
    }

    .user-role-karyawan {
        background: #eef1f4;
        color: #667085;
    }

    .status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 9px;
        border-radius: 6px;
        font-size: 10.5px;
        font-weight: 700;
        white-space: nowrap;
    }

    .status-aktif {
        background: #e6f5ea;
        color: #2f8f50;
    }

    .status-nonaktif {
        background: #eeeeef;
        color: #999fa7;
    }

    .action-cell {
        text-align: center !important;
        vertical-align: middle !important;
        white-space: nowrap;
    }

    .action-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        width: 100%;
    }

    .btn-edit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        padding: 0;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: #b52a31;
        cursor: pointer;
        transition: background .15s ease, color .15s ease;
    }

    .btn-edit svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.9;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .btn-edit:hover {
        background: #fff0f2;
    }

    .toggle-form {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 22px;
        margin: 0;
        padding: 0;
    }

    .toggle {
        position: relative;
        display: block;
        width: 38px;
        height: 22px;
    }

    .toggle input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .toggle-slider {
        position: absolute;
        inset: 0;
        cursor: pointer;
        background: #cbd2da;
        border-radius: 30px;
        transition: background .2s ease;
    }

    .toggle-slider::before {
        content: "";
        position: absolute;
        width: 16px;
        height: 16px;
        left: 3px;
        top: 3px;
        background: #ffffff;
        border-radius: 50%;
        box-shadow: 0 1px 3px rgba(0,0,0,.10);
        transition: transform .2s ease;
    }

    .toggle input:checked + .toggle-slider {
        background: #5fa66a;
    }

    .toggle input:checked + .toggle-slider::before {
        transform: translateX(16px);
    }

    .toggle-label {
        display: inline-block;
        min-width: 47px;
        font-size: 10px;
        font-weight: 600;
        text-align: left;
    }

    .toggle-label.aktif {
        color: #2f8f50;
    }

    .toggle-label.nonaktif {
        color: #999fa7;
    }

    .employee-row {
        cursor: pointer;
        transition: background .15s ease;
    }

    .employee-row:hover {
        background: #fff8f8 !important;
    }

    .employee-row td {
        cursor: pointer;
    }

    .empty-data {
        text-align: center !important;
        padding: 28px 20px;
        color: #8b96a5;
        font-size: 12px;
    }

    .footer-note {
        text-align: center;
        font-size: 11.5px;
        color: #a2abb7;
        padding: 20px 0 4px;
    }

    .employee-top {
        display: flex;
        align-items: center;
        gap: 18px;
        width: 100%;
    }

    .employee-top .section-title-area {
        flex: 0 0 210px;
    }

    .employee-top .section-title {
        color: #1d2939;
        font-size: 16px;
        font-weight: 700;
    }

    .employee-top .section-description {
        color: #8a95a5;
        font-size: 12px;
        margin-top: 3px;
    }

    .status-inline-form {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        margin: 0;
    }

    .status-inline {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .status-inline label {
        font-size: 10.5px;
        color: #7b8797;
        font-weight: 500;
    }

    .status-inline select {
        width: 175px;
        height: 36px;
        padding: 0 10px;
        border: 1px solid #d1d7de;
        border-radius: 8px;
        background: #fff;
        color: #344054;
        font-family: "Segoe UI", Arial, sans-serif;
        font-size: 11.5px;
        outline: none;
    }

    .status-inline select:focus {
        border-color: #b52a31;
    }

    .btn-filter-inline {
        height: 36px;
        padding: 0 15px;
        border: 0;
        border-radius: 8px;
        background: #b52a31;
        color: #fff;
        font-family: "Segoe UI", Arial, sans-serif;
        font-size: 11.5px;
        font-weight: 700;
        cursor: pointer;
    }

    .btn-filter-inline:hover {
        background: #9d232a;
    }

    .btn-reset-inline {
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 12px;
        border: 1px solid #d1d7de;
        border-radius: 8px;
        background: #fff;
        color: #667085;
        font-size: 11.5px;
        font-weight: 600;
    }

    .btn-reset-inline:hover {
        color: #b52a31;
        border-color: #dfb8bc;
    }

    .employee-top .divisi-buttons {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 7px;
        flex-wrap: wrap;
        margin-left: auto;
    }

    .employee-top .btn-divisi {
        height: 36px;
        min-width: 58px;
        padding: 0 13px;
    }

    .employee-panel .employee-controls-card {
        padding: 17px 17px;
    }

    @media (max-width: 1200px) {
        .employee-top {
            flex-wrap: wrap;
        }

        .employee-top .divisi-buttons {
            width: 100%;
            justify-content: flex-start;
            margin-left: 0;
        }
    }

    @media (max-width: 900px) {
        .employee-controls-card .section-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .divisi-buttons {
            width: 100%;
            justify-content: flex-start;
        }
    }

    @media (max-width: 800px) {
        .main {
            margin-left: 0;
            width: 100%;
        }

        .content {
            padding: 20px;
        }

        .admin-panel .section-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .btn-tambah {
            width: 100%;
        }

        .filter-row {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            width: 100%;
        }

        .btn-filter,
        .btn-reset {
            width: 100%;
        }

        table {
            min-width: 700px;
        }
    }

body{
    background:#fff !important;
    color:#253044;
}

.content{
    padding:30px 34px 40px !important;
    background:#fff !important;
}

.page-title{margin-bottom:24px !important;}

.page-title h1{
    font-size:25px !important;
    font-weight:700 !important;
    color:#182230 !important;
    letter-spacing:-.3px;
    margin-bottom:6px !important;
}

.page-title p{
    font-size:13px !important;
    color:#8a95a5 !important;
}

.admin-panel,.employee-panel{
    background:#fff !important;
    border:1px solid #d9dee5 !important;
    border-radius:14px !important;
    box-shadow:0 2px 10px rgba(16,24,40,.035) !important;
    overflow:hidden !important;
}

.admin-panel{margin-bottom:24px !important;}

.admin-panel .section-header{
    padding:20px !important;
    border-bottom:1px solid #e7eaee !important;
    background:#fff !important;
}

.section-title,
.employee-panel .section-title{
    font-size:17px !important;
    font-weight:700 !important;
    color:#182230 !important;
    line-height:1.4;
}

.section-description{
    font-size:12px !important;
    color:#8b96a5 !important;
    margin-top:4px !important;
}

.btn-tambah{
    min-height:40px !important;
    padding:0 17px !important;
    border-radius:8px !important;
    background:#b52f35 !important;
    color:#fff !important;
    font-size:12px !important;
    font-weight:700 !important;
    box-shadow:none !important;
}

.btn-tambah:hover{background:#9f272d !important;transform:none !important;}

.admin-panel .table-card,.employee-panel .table-card{
    border:0 !important;
    border-radius:0 !important;
    box-shadow:none !important;
    background:#fff !important;
}

table{width:100%;border-collapse:collapse;table-layout:fixed;}

th{
    background:#f7f8fa !important;
    color:#667085 !important;
    font-size:11.5px !important;
    font-weight:700 !important;
    padding:13px 12px !important;
    border-bottom:1px solid #e1e5ea !important;
    border-top:0 !important;
}

td{
    background:#fff !important;
    color:#4b5870 !important;
    font-size:12px !important;
    padding:14px 12px !important;
    border-bottom:1px solid #edf0f3 !important;
}

tbody tr:nth-child(odd),tbody tr:nth-child(even){background:#fff !important;}

tbody tr:hover{background:#fafbfc !important;}

tbody tr:last-child td{border-bottom:0 !important;}

.admin-name{color:#344054 !important;font-weight:500 !important;}

.admin-username,.admin-divisi{color:#667085 !important;}

.user-role-badge{padding:5px 9px !important;border-radius:6px !important;font-size:10.5px !important;font-weight:700 !important;}

.user-role-admin{background:#eef1f4 !important;color:#667085 !important;}

.user-role-karyawan{background:#edf5fb !important;color:#3b78a8 !important;}

.status{padding:5px 9px !important;border-radius:6px !important;font-size:10.5px !important;font-weight:700 !important;}

.status-aktif{background:#e9f7ee !important;color:#318653 !important;}

.status-nonaktif{background:#f0f1f2 !important;color:#9299a1 !important;}

.action-wrapper{gap:8px !important;}

.btn-edit{width:30px !important;height:30px !important;border-radius:7px !important;color:#b52f35 !important;}

.btn-edit:hover{background:#fff1f2 !important;}

.toggle{width:38px !important;height:22px !important;}

.toggle-slider{background:#cbd2da !important;}

.toggle input:checked + .toggle-slider{background:#70a873 !important;}

.toggle-label{font-size:10.5px !important;}

.employee-section{margin-bottom:24px !important;}

.employee-controls-card{padding:20px !important;background:#fff !important;}

.employee-controls-card .section-header{padding:0 !important;margin-bottom:18px !important;border:0 !important;}

.divisi-buttons{gap:8px !important;}

.btn-divisi{
    min-width:64px !important;
    height:36px !important;
    padding:0 14px !important;
    border:1px solid #d9dee5 !important;
    border-radius:8px !important;
    background:#fff !important;
    color:#667085 !important;
    font-size:12px !important;
    font-weight:600 !important;
}

.btn-divisi:hover{background:#fff8f8 !important;border-color:#dfb8bc !important;color:#b52f35 !important;}

.btn-divisi.active{background:#b52f35 !important;border-color:#b52f35 !important;color:#fff !important;}

.filter-card{margin-top:18px !important;padding:0 !important;background:transparent !important;}

.filter-row{gap:12px !important;}

.filter-group{width:300px !important;}

.filter-group label{font-size:11.5px !important;font-weight:500 !important;color:#7b8797 !important;}

.filter-group select{
    height:39px !important;
    border:1px solid #d5dbe2 !important;
    border-radius:8px !important;
    color:#344054 !important;
    background:#fff !important;
}

.filter-group select:focus{border-color:#b52f35 !important;box-shadow:0 0 0 3px rgba(181,47,53,.07);}

.btn-filter{height:39px !important;padding:0 18px !important;border-radius:8px !important;background:#b52f35 !important;font-size:12px !important;}

.btn-filter:hover{background:#9f272d !important;}

.btn-reset{height:39px !important;border-radius:8px !important;}

.employee-panel .table-card{margin-top:0 !important;border-top:1px solid #e7eaee !important;}

.employee-panel .table-head{padding:19px 20px 14px !important;border-bottom:1px solid #e7eaee !important;}

.employee-panel .table-head h3{font-size:15px !important;font-weight:700 !important;color:#182230 !important;}

.employee-panel .table-wrapper{padding:0 0 8px !important;}

.employee-panel table th{background:#f7f8fa !important;color:#667085 !important;}

.employee-panel table td{padding:14px 12px !important;}

.employee-row:hover{background:#fafbfc !important;}

.admin-inactive td{color:#a1a8b0 !important;}

.admin-inactive .admin-name{color:#969da6 !important;}

.admin-inactive .admin-username,.admin-inactive .admin-divisi{color:#a7adb5 !important;}

.footer-note{padding:22px 0 8px !important;color:#a2abb7 !important;font-size:11px !important;}

@media(max-width:900px){
    .content{padding:24px 20px 30px !important;}
    .employee-controls-card .section-header{gap:15px;}
    .divisi-buttons{justify-content:flex-start !important;}
}

@media(max-width:800px){
    .content{padding:20px !important;}
    .admin-panel .section-header,.employee-controls-card .section-header{align-items:flex-start !important;flex-direction:column !important;}
    .btn-tambah{width:auto !important;}
    .filter-row{flex-direction:column !important;align-items:stretch !important;}
    .filter-group{width:100% !important;}
    .btn-filter,.btn-reset{width:auto !important;}
}

    .employee-controls-card .employee-top{
        display:flex !important;
        align-items:center !important;
        width:100% !important;
        gap:20px !important;
    }

    .employee-controls-card .section-title-area{
        flex:1 1 auto !important;
        min-width:220px !important;
    }

    .employee-controls-right{
        display:flex !important;
        align-items:flex-end !important;
        justify-content:flex-end !important;
        gap:8px !important;
        flex:0 0 auto !important;
        margin-left:auto !important;
    }

    .employee-controls-right .status-inline-form{
        display:flex !important;
        align-items:flex-end !important;
        gap:8px !important;
        margin:0 !important;
        padding:0 !important;
        white-space:nowrap !important;
    }

    .employee-controls-right .status-inline{
        display:flex !important;
        flex-direction:column !important;
        gap:5px !important;
    }

    .employee-controls-right .status-inline label{
        font-size:10.5px !important;
        color:#7b8797 !important;
        line-height:1 !important;
    }

    .employee-controls-right .status-inline select{
        width:175px !important;
        height:36px !important;
        padding:0 10px !important;
        border:1px solid #d1d7de !important;
        border-radius:8px !important;
        background:#fff !important;
        color:#344054 !important;
        font-size:11.5px !important;
        outline:none !important;
    }

    .employee-controls-right .btn-filter-inline,
    .employee-controls-right .btn-reset-inline{
        height:36px !important;
    }

    .employee-controls-right .divisi-buttons{
        display:flex !important;
        align-items:center !important;
        justify-content:flex-start !important;
        gap:7px !important;
        margin:0 !important;
        flex-wrap:nowrap !important;
    }

    .employee-controls-right .btn-divisi{
        height:36px !important;
        min-width:58px !important;
        padding:0 13px !important;
    }

    @media(max-width:1250px){
        .employee-controls-card .employee-top{
            align-items:flex-start !important;
        }

        .employee-controls-right{
            flex-wrap:wrap !important;
            justify-content:flex-end !important;
        }

        .employee-controls-right .divisi-buttons{
            width:100% !important;
            justify-content:flex-end !important;
        }
    }

    @media(max-width:900px){
        .employee-controls-card .employee-top{
            flex-direction:column !important;
            align-items:stretch !important;
        }

        .employee-controls-right{
            margin-left:0 !important;
            justify-content:flex-start !important;
            flex-wrap:wrap !important;
        }

        .employee-controls-right .divisi-buttons{
            width:auto !important;
            justify-content:flex-start !important;
            flex-wrap:wrap !important;
        }
    }

.employee-controls-right{
    display:flex !important;
    align-items:flex-end !important;
    gap:14px !important;
    flex:0 0 auto !important;
    margin-left:auto !important;
}

.employee-controls-right .status-inline-form{
    display:flex !important;
    align-items:flex-end !important;
    gap:8px !important;
    margin:0 !important;
}

.employee-controls-right .status-inline{
    display:flex !important;
    flex-direction:column !important;
    gap:5px !important;
}

.employee-controls-right .status-inline label{
    font-size:10.5px !important;
    color:#7b8797 !important;
    line-height:1 !important;
}

.employee-controls-right .status-inline select{
    width:175px !important;
    height:38px !important;
    border:1px solid #d2d8df !important;
    border-radius:8px !important;
    background:#fff !important;
    color:#344054 !important;
    padding:0 10px !important;
    font-size:11.5px !important;
}

.employee-controls-right .btn-filter-inline{
    height:38px !important;
    padding:0 16px !important;
    border:1px solid #b52a31 !important;
    border-radius:8px !important;
    background:#b52a31 !important;
    color:#fff !important;
    font-size:11.5px !important;
    font-weight:700 !important;
}

.employee-controls-right .btn-filter-inline:hover{
    background:#9f232a !important;
}

.employee-controls-right .btn-reset-inline{
    height:38px !important;
    padding:0 12px !important;
}

.employee-controls-right .divisi-buttons{
    display:flex !important;
    align-items:center !important;
    gap:7px !important;
    margin:0 !important;
    padding-left:14px !important;
    border-left:1px solid #e2e6eb !important;
    flex-wrap:nowrap !important;
}

.employee-controls-right .btn-divisi{
    height:38px !important;
    min-width:58px !important;
    padding:0 14px !important;
    border:1px solid #d6dce3 !important;
    border-radius:8px !important;
    background:#fff !important;
    color:#667085 !important;
    font-size:11.5px !important;
    font-weight:600 !important;
    transition:.15s ease !important;
}

.employee-controls-right .btn-divisi:hover{
    background:#fff8f8 !important;
    border-color:#c9878c !important;
    color:#b52a31 !important;
}

.employee-controls-right .btn-divisi.active{
    background:#b52a31 !important;
    border-color:#b52a31 !important;
    color:#fff !important;
}

@media(max-width:1250px){
    .employee-controls-card .employee-top{
        align-items:flex-start !important;
    }

    .employee-controls-right{
        flex-wrap:wrap !important;
        justify-content:flex-end !important;
    }

    .employee-controls-right .divisi-buttons{
        border-left:0 !important;
        padding-left:0 !important;
    }
}

@media(max-width:900px){
    .employee-controls-card .employee-top{
        flex-direction:column !important;
        align-items:stretch !important;
    }

    .employee-controls-right{
        margin-left:0 !important;
        justify-content:flex-start !important;
    }

    .employee-controls-right .divisi-buttons{
        flex-wrap:wrap !important;
        border-left:0 !important;
        padding-left:0 !important;
    }
}

.employee-panel .employee-controls-card{
    border-bottom:0 !important;
    box-shadow:none !important;
}

.employee-panel .employee-data-table{
    margin-top:0 !important;
    border-top:0 !important;
    border-radius:0 0 14px 14px !important;
    box-shadow:none !important;
}

.employee-panel .employee-data-table .table-wrapper{
    border-top:0 !important;
    padding-top:0 !important;
}

.employee-panel .employee-data-table table{
    margin-top:0 !important;
}

body.dark-mode{background:#181b20!important;color:#eef1f5!important}

body.dark-mode .main,body.dark-mode .content{background:#181b20!important}

body.dark-mode .page-title h1,body.dark-mode .section-title{color:#f1f3f5!important}

body.dark-mode .page-title p,body.dark-mode .section-description{color:#929baa!important}

body.dark-mode .admin-panel,body.dark-mode .employee-panel,body.dark-mode .employee-controls-card,body.dark-mode .filter-card,body.dark-mode .table-card{background:#1d2025!important;border-color:#343942!important;box-shadow:none!important}

body.dark-mode .admin-panel .section-header,body.dark-mode .employee-panel .section-header,body.dark-mode .table-head{border-color:#343942!important}

body.dark-mode .btn-tambah{background:#b51f2a!important;color:#fff!important}

body.dark-mode .btn-tambah:hover{background:#d12b36!important}

body.dark-mode .btn-divisi{background:#20242a!important;color:#bfc7d2!important;border-color:#343942!important}

body.dark-mode .btn-divisi:hover,body.dark-mode .btn-divisi.active{background:#302126!important;color:#f07a80!important;border-color:#51363b!important}

body.dark-mode .filter-group label{color:#cbd2dc!important}

body.dark-mode .filter-group select{background:#20242a!important;color:#f1f3f5!important;border-color:#343942!important}

body.dark-mode .btn-filter,body.dark-mode .btn-filter-inline{background:#b51f2a!important;color:#fff!important}

body.dark-mode .btn-reset,body.dark-mode .btn-reset-inline{background:#292e36!important;color:#dce2ea!important;border-color:#3b424d!important}

body.dark-mode .table-wrapper,body.dark-mode table{background:#1d2025!important}

body.dark-mode th{background:#23272e!important;color:#f1f3f5!important;border-bottom-color:#343942!important}

body.dark-mode td{background:#1d2025!important;color:#eef1f5!important;border-bottom-color:#2d323a!important}

body.dark-mode tbody tr:hover td{background:#21252b!important}

body.dark-mode .admin-name,body.dark-mode .employee-row td:nth-child(2){color:#f1f3f5!important}

body.dark-mode .admin-divisi,body.dark-mode .employee-row td:nth-child(3),body.dark-mode .employee-row td:nth-child(5){color:#b8c1ce!important}

body.dark-mode .btn-edit{background:#292e36!important;color:#dce2ea!important;border-color:#3b424d!important}

body.dark-mode .btn-edit:hover{background:#353b45!important;color:#fff!important}

body.dark-mode .user-role-admin,body.dark-mode .user-role-karyawan{background:#302126!important;color:#f07a80!important;border-color:#51363b!important}

body.dark-mode .status-aktif{background:#243329!important;color:#62d174!important;border-color:#34523b!important}

body.dark-mode .status-nonaktif{background:#302326!important;color:#f08a90!important;border-color:#493338!important}

body.dark-mode .toggle-slider{background:#343942!important}

body.dark-mode .toggle-label{color:#cbd2dc!important}

body.dark-mode .empty-data{color:#929baa!important;background:#1d2025!important}

body.dark-mode .footer-note{color:#858e9d!important}

th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#8f1821!important}

body.dark-mode th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#8f1821!important}

body.dark-mode .admin-panel,body.dark-mode .admin-card,body.dark-mode .section-card{background:#1d2025!important;color:#eef1f5!important;border-color:#343942!important}

body.dark-mode .admin-panel .section-header,body.dark-mode .admin-card .section-header{background:#1d2025!important;color:#eef1f5!important}

body.dark-mode .admin-panel h2,body.dark-mode .admin-panel h3,body.dark-mode .admin-card h2,body.dark-mode .admin-card h3{color:#f1f3f5!important}

body.dark-mode .admin-panel p,body.dark-mode .admin-card p{color:#929baa!important}

body.dark-mode .filter-group select,body.dark-mode .status-select{background:#20242a!important;color:#f1f3f5!important;border-color:#343942!important}

body.dark-mode .filter-group select option,body.dark-mode .status-select option{background:#20242a!important;color:#f1f3f5!important}

body.dark-mode select{background-color:#20242a!important;color:#f1f3f5!important;border-color:#343942!important;color-scheme:dark!important}

body.dark-mode select option{background-color:#20242a!important;color:#f1f3f5!important}

body.dark-mode select:focus{background-color:#20242a!important;color:#f1f3f5!important;border-color:#b51f2a!important;outline:none!important}

body.dark-mode .employee-controls-right .status-inline select{background:#20242a!important;color:#f1f3f5!important;border-color:#343942!important;color-scheme:dark!important}

body.dark-mode .employee-controls-right .status-inline select option{background:#20242a!important;color:#f1f3f5!important}

body.dark-mode .employee-controls-right .status-inline select:focus{background:#20242a!important;color:#f1f3f5!important;border-color:#b51f2a!important;outline:none!important}

.admin-panel .table-wrapper,
.employee-panel .table-wrapper {
    width:100% !important;
    padding:0 !important;
}
.admin-panel .admin-table,
.employee-panel table {
    width:100% !important;
    margin:0 !important;
    table-layout:fixed;
}
.admin-panel .admin-table th,
.admin-panel .admin-table td,
.employee-panel table th,
.employee-panel table td {
    text-align:center !important;
    padding-left:10px !important;
    padding-right:10px !important;
}
.employee-panel table th:first-child,
.employee-panel table td:first-child,
.employee-panel table th:last-child,
.employee-panel table td:last-child {
    padding-left:10px !important;
    padding-right:10px !important;
}

/* Warna badge Role: Admin Divisi = biru, Karyawan = hijau tosca */
.user-role-admin{background:#e6eefc !important;color:#2f5fb3 !important;}
.user-role-karyawan{background:#e2f5f2 !important;color:#1f8a7a !important;}

body.dark-mode .user-role-admin{background:#1f2c45 !important;color:#8db4ff !important;border-color:#2f4570 !important;}
body.dark-mode .user-role-karyawan{background:#1c3a37 !important;color:#6fd6c8 !important;border-color:#2b5651 !important;}

.admin-inactive .user-role-admin{opacity:.6;}

/* ===== Tab switch: Admin Divisi / Karyawan / Departemen ===== */
.tab-switch{
    display:inline-flex;
    gap:6px;
    padding:5px;
    margin-bottom:22px;
    background:#f1f3f6;
    border:1px solid #e1e5ea;
    border-radius:11px;
}

.tab-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:140px;
    height:38px;
    padding:0 18px;
    border-radius:8px;
    font-size:12.5px;
    font-weight:700;
    color:#667085;
    transition:.15s ease;
}

.tab-btn:hover{color:#b52a31;}

.tab-btn.active{
    background:#b52a31;
    color:#fff;
    box-shadow:0 1px 4px rgba(181,42,49,.25);
}

body.dark-mode .tab-switch{background:#20242a;border-color:#343942;}
body.dark-mode .tab-btn{color:#bfc7d2;}
body.dark-mode .tab-btn:hover{color:#f07a80;}
body.dark-mode .tab-btn.active{background:#b51f2a;color:#fff;}

/* ===== Samakan warna background & card dengan Dashboard ===== */

/* LIGHT */
.admin-panel,.employee-panel{
    border-color:#d9dde2 !important;
    box-shadow:0 1px 3px rgba(16,24,40,.04) !important;
}
td{color:#172033 !important;border-bottom-color:#eef0f3 !important;}
.admin-name{color:#172033 !important;}

/* DARK */
html:has(body.dark-mode),
html:has(body.dark-mode) body{background:#0f141b !important}

html body.dark-mode{background:#0f141b !important;color:#e8edf4 !important}
html body.dark-mode .main,
html body.dark-mode .content{background:#0f141b !important}

html body.dark-mode .page-title h1,
html body.dark-mode .section-title,
html body.dark-mode .employee-panel .section-title{color:#edf2f7 !important}
html body.dark-mode .page-title p,
html body.dark-mode .section-description{color:#8f9bac !important}

/* card / panel */
html body.dark-mode .admin-panel,
html body.dark-mode .employee-panel,
html body.dark-mode .employee-controls-card,
html body.dark-mode .filter-card,
html body.dark-mode .table-card,
html body.dark-mode .admin-panel .section-header,
html body.dark-mode .table-wrapper,
html body.dark-mode table{background:#151b23 !important;border-color:#2c3542 !important;box-shadow:none !important}
html body.dark-mode .admin-panel .section-header,
html body.dark-mode .employee-panel .table-card,
html body.dark-mode .table-head{border-color:#2c3542 !important}

/* tabel */
html body.dark-mode th{background:#b51f2a !important;color:#fff !important;border-bottom-color:#b51f2a !important}
html body.dark-mode td{background:#151b23 !important;color:#edf2f7 !important;border-bottom-color:#2c3542 !important}
html body.dark-mode tbody tr:hover td{background:#202733 !important}
html body.dark-mode .employee-row:hover td{background:#202733 !important}
html body.dark-mode .admin-name,
html body.dark-mode .employee-row td:nth-child(2){color:#fff !important}
html body.dark-mode .admin-username,
html body.dark-mode .admin-divisi,
html body.dark-mode .employee-row td:nth-child(3),
html body.dark-mode .employee-row td:nth-child(5){color:#cbd2dc !important}
html body.dark-mode .admin-inactive td{color:#7f8a9a !important}
html body.dark-mode .empty-data{background:#151b23 !important;color:#8f9bac !important}

/* tab */
html body.dark-mode .tab-switch{background:#151b23 !important;border-color:#2c3542 !important}
html body.dark-mode .tab-btn{color:#8f9bac !important}
html body.dark-mode .tab-btn:hover{color:#f07a80 !important}
html body.dark-mode .tab-btn.active{background:#b51f2a !important;color:#fff !important}

/* tombol & input */
html body.dark-mode .btn-divisi,
html body.dark-mode .employee-controls-right .btn-divisi{background:#151b23 !important;border-color:#2c3542 !important;color:#cbd2dc !important}
html body.dark-mode .btn-divisi:hover,
html body.dark-mode .employee-controls-right .btn-divisi:hover{background:#382326 !important;border-color:#51363b !important;color:#f07a80 !important}
html body.dark-mode .btn-divisi.active,
html body.dark-mode .employee-controls-right .btn-divisi.active{background:#b51f2a !important;border-color:#b51f2a !important;color:#fff !important}
html body.dark-mode .employee-controls-right .divisi-buttons{border-left-color:#2c3542 !important}
html body.dark-mode select,
html body.dark-mode .employee-controls-right .status-inline select{background:#151b23 !important;border-color:#2c3542 !important;color:#edf2f7 !important;color-scheme:dark !important}
html body.dark-mode .btn-reset,
html body.dark-mode .btn-reset-inline{background:#202733 !important;border-color:#2c3542 !important;color:#cbd2dc !important}
html body.dark-mode .btn-edit{background:#202733 !important;border-color:#2c3542 !important;color:#dce2ea !important}
html body.dark-mode .btn-edit:hover{background:#2a3342 !important;color:#fff !important}
html body.dark-mode .toggle-slider{background:#2c3542 !important}
html body.dark-mode .toggle input:checked + .toggle-slider{background:#29a35f !important}
html body.dark-mode .toggle-label{color:#8f9bac !important}
html body.dark-mode .footer-note{color:#8f9bac !important}

/* badge */
html body.dark-mode .user-role-admin{background:#1d3158 !important;color:#8eb5ff !important;border-color:transparent !important}
html body.dark-mode .user-role-karyawan{background:#1c3a37 !important;color:#6fd6c8 !important;border-color:transparent !important}
html body.dark-mode .status-aktif{background:#183d2a !important;color:#72d89a !important;border-color:transparent !important}
html body.dark-mode .status-nonaktif{background:#34383e !important;color:#c5cbd3 !important;border-color:transparent !important}

/* ===== Rata kiri: Nama, Username, Divisi (header + isi) ===== */
.admin-panel .admin-table th:nth-child(2),
.admin-panel .admin-table td:nth-child(2),
.admin-panel .admin-table th:nth-child(3),
.admin-panel .admin-table td:nth-child(3),
.admin-panel .admin-table th:nth-child(5),
.admin-panel .admin-table td:nth-child(5),
.employee-panel table th:nth-child(2),
.employee-panel table td:nth-child(2),
.employee-panel table th:nth-child(3),
.employee-panel table td:nth-child(3),
.employee-panel table th:nth-child(5),
.employee-panel table td:nth-child(5){
    text-align:left !important;
    padding-left:18px !important;
}

/* ===== Rapatkan tabel (kurangi jarak antar kolom & tinggi baris) ===== */
.admin-panel .admin-table th,
.admin-panel .admin-table td,
.employee-panel table th,
.employee-panel table td{
    padding-top:10px !important;
    padding-bottom:10px !important;
}

/* Tabel Admin Divisi */
.admin-panel .admin-table th:nth-child(1),.admin-panel .admin-table td:nth-child(1){width:6% !important;}
.admin-panel .admin-table th:nth-child(2),.admin-panel .admin-table td:nth-child(2){width:18% !important;}
.admin-panel .admin-table th:nth-child(3),.admin-panel .admin-table td:nth-child(3){width:17% !important;}
.admin-panel .admin-table th:nth-child(4),.admin-panel .admin-table td:nth-child(4){width:14% !important;}
.admin-panel .admin-table th:nth-child(5),.admin-panel .admin-table td:nth-child(5){width:18% !important;}
.admin-panel .admin-table th:nth-child(6),.admin-panel .admin-table td:nth-child(6){width:11% !important;}
.admin-panel .admin-table th:nth-child(7),.admin-panel .admin-table td:nth-child(7){width:16% !important;}

/* Tabel Karyawan */
.employee-panel table th:nth-child(1),.employee-panel table td:nth-child(1){width:6% !important;}
.employee-panel table th:nth-child(2),.employee-panel table td:nth-child(2){width:24% !important;}
.employee-panel table th:nth-child(3),.employee-panel table td:nth-child(3){width:20% !important;}
.employee-panel table th:nth-child(4),.employee-panel table td:nth-child(4){width:14% !important;}
.employee-panel table th:nth-child(5),.employee-panel table td:nth-child(5){width:24% !important;}
.employee-panel table th:nth-child(6),.employee-panel table td:nth-child(6){width:12% !important;}

/* Rata kiri: jarak awal dari tepi lebih kecil */
.admin-panel .admin-table th:nth-child(2),
.admin-panel .admin-table td:nth-child(2),
.employee-panel table th:nth-child(2),
.employee-panel table td:nth-child(2){
    padding-left:14px !important;
}
.admin-panel .admin-table th:nth-child(3),
.admin-panel .admin-table td:nth-child(3),
.admin-panel .admin-table th:nth-child(5),
.admin-panel .admin-table td:nth-child(5),
.employee-panel table th:nth-child(3),
.employee-panel table td:nth-child(3),
.employee-panel table th:nth-child(5),
.employee-panel table td:nth-child(5){
    padding-left:10px !important;
}

/* ===== Ukuran font tabel 13.5px & teks putih (dark mode) ===== */
.content table td{font-size:13.5px !important;}

html body.dark-mode .content table tr:not(.admin-inactive) td:not(.empty-data),
html body.dark-mode .content table tr:not(.admin-inactive) td.admin-name,
html body.dark-mode .content table tr:not(.admin-inactive) td.admin-username,
html body.dark-mode .content table tr:not(.admin-inactive) td.admin-divisi,
html body.dark-mode .content table tr.employee-row td:nth-child(n){
    color:#ffffff !important;
}

html body.dark-mode .content table tr:not(.admin-inactive) .toggle-label{
    color:#ffffff !important;
}

/* ===== Tabel Karyawan: rapat, semua kolom (selain No) rata kiri ===== */
.employee-panel table th:nth-child(1),.employee-panel table td:nth-child(1){width:6% !important;text-align:center !important;}
.employee-panel table th:nth-child(2),.employee-panel table td:nth-child(2){width:24% !important;}
.employee-panel table th:nth-child(3),.employee-panel table td:nth-child(3){width:22% !important;}
.employee-panel table th:nth-child(4),.employee-panel table td:nth-child(4){width:16% !important;}
.employee-panel table th:nth-child(5),.employee-panel table td:nth-child(5){width:20% !important;}
.employee-panel table th:nth-child(6),.employee-panel table td:nth-child(6){width:12% !important;}

.employee-panel table th:nth-child(n+2),
.employee-panel table td:nth-child(n+2){
    text-align:left !important;
    padding-left:14px !important;
    padding-right:8px !important;
}
.employee-panel table td.empty-data{text-align:center !important;}

/* ===== Notifikasi ===== */
.alert-msg{padding:12px 15px;border-radius:9px;margin-bottom:18px;font-size:13px;font-weight:500}
.alert-success{background:#edf8f0;border:1px solid #d6ecd9;color:#2f8650}
.alert-error{background:#fff2f3;border:1px solid #efd4d7;color:#b51f2a}
html body.dark-mode .alert-success{background:#183d2a !important;border-color:#2b5a3d !important;color:#72d89a !important}
html body.dark-mode .alert-error{background:#302326 !important;border-color:#493338 !important;color:#f08a90 !important}

/* ===== Kolom Aksi: rapi & netral (toggle | edit | hapus) ===== */
.admin-panel .admin-table td.action-cell{padding-left:12px !important;padding-right:12px !important}

.action-wrapper{
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:10px !important;
}

/* urutan: toggle, label, | edit, hapus */
.action-wrapper .toggle-form{order:1}
.action-wrapper .toggle-label{
    order:2;
    min-width:46px !important;
    padding-right:12px;
    margin-right:2px;
    border-right:1px solid #e1e5ea;
    line-height:22px;
}
.action-wrapper .btn-edit{order:3}
.action-wrapper .btn-hapus{order:4}

/* tombol ikon seragam */
.action-wrapper .btn-edit,
.action-wrapper .btn-hapus{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    width:32px !important;
    height:32px !important;
    padding:0;
    border:1px solid #d9dde2 !important;
    border-radius:8px !important;
    background:#ffffff !important;
    color:#667085 !important;
    cursor:pointer;
    transition:background .15s ease,border-color .15s ease,color .15s ease;
}
.action-wrapper .btn-edit svg,
.action-wrapper .btn-hapus svg{
    width:16px;height:16px;fill:none;stroke:currentColor;
    stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;
}
.action-wrapper .btn-edit:hover{background:#fff8f8 !important;border-color:#dfb8bc !important;color:#b52a31 !important}
.action-wrapper .btn-hapus:hover{background:#fef3f2 !important;border-color:#fecdca !important;color:#d92d20 !important}

/* baris nonaktif: tombol tetap terbaca normal */
.admin-inactive .action-wrapper .btn-edit,
.admin-inactive .action-wrapper .btn-hapus{background:#ffffff !important;color:#667085 !important}

/* dark mode */
html body.dark-mode .action-wrapper .toggle-label{border-right-color:#2c3542 !important}
html body.dark-mode .action-wrapper .btn-edit,
html body.dark-mode .action-wrapper .btn-hapus,
html body.dark-mode .admin-inactive .action-wrapper .btn-edit,
html body.dark-mode .admin-inactive .action-wrapper .btn-hapus{
    background:#151b23 !important;
    border-color:#2c3542 !important;
    color:#aeb8c6 !important;
}
html body.dark-mode .action-wrapper .btn-edit:hover{background:#382326 !important;border-color:#51363b !important;color:#f07a80 !important}
html body.dark-mode .action-wrapper .btn-hapus:hover{background:#3a2226 !important;border-color:#70444b !important;color:#ff8b92 !important}

/* lebar kolom (tabel Admin Divisi) */
.admin-panel .admin-table th:nth-child(3),.admin-panel .admin-table td:nth-child(3){width:16% !important}
.admin-panel .admin-table th:nth-child(5),.admin-panel .admin-table td:nth-child(5){width:17% !important}
.admin-panel .admin-table th:nth-child(7),.admin-panel .admin-table td:nth-child(7){width:18% !important}

/* ===== Warna ikon Edit & Hapus ===== */
.action-wrapper .btn-edit,
.admin-inactive .action-wrapper .btn-edit{color:#2f6fd0 !important}
.action-wrapper .btn-hapus,
.admin-inactive .action-wrapper .btn-hapus{color:#d92d20 !important}

.action-wrapper .btn-edit:hover{background:#eef4ff !important;border-color:#b9d0f7 !important;color:#1f5bbf !important}
.action-wrapper .btn-hapus:hover{background:#fef3f2 !important;border-color:#fecdca !important;color:#b42318 !important}

html body.dark-mode .action-wrapper .btn-edit,
html body.dark-mode .admin-inactive .action-wrapper .btn-edit{color:#8eb5ff !important}
html body.dark-mode .action-wrapper .btn-hapus,
html body.dark-mode .admin-inactive .action-wrapper .btn-hapus{color:#ff8b92 !important}

html body.dark-mode .action-wrapper .btn-edit:hover{background:#1d3158 !important;border-color:#2f4570 !important;color:#b6cdff !important}
html body.dark-mode .action-wrapper .btn-hapus:hover{background:#4b2d32 !important;border-color:#70444b !important;color:#ffb1b6 !important}

/* ===== Popup Hapus ===== */
.delete-modal-overlay{position:fixed;inset:0;background:rgba(28,34,44,.42);display:none;align-items:center;justify-content:center;padding:20px;z-index:10000;backdrop-filter:blur(2px)}
.delete-modal-overlay.show{display:flex}
.delete-modal-box{width:380px;max-width:100%;background:#fff;border-radius:16px;padding:28px 26px 24px;text-align:center;box-shadow:0 18px 45px rgba(20,28,40,.18)}
.delete-modal-icon{width:50px;height:50px;margin:0 auto 15px;border-radius:50%;background:#f7e3e5;display:flex;align-items:center;justify-content:center;color:#b51f2a}
.delete-modal-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.delete-modal-box h3{margin-bottom:7px;font-size:17px;color:#1c2636}
.delete-modal-box p{margin:0 auto 22px;max-width:310px;font-size:12.5px;line-height:1.6;color:#7e8897}
.delete-modal-box p strong{color:#1c2636}
.delete-modal-actions{display:flex;justify-content:center;gap:10px}
.delete-modal-btn{min-width:104px;padding:10px 16px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.delete-modal-cancel{border:1px solid #dde3ea;background:#f7f8fa;color:#697587}
.delete-modal-cancel:hover{background:#eef1f4}
.delete-modal-confirm{border:1px solid #b51f2a;background:#b51f2a;color:#fff}
.delete-modal-confirm:hover{background:#941923;border-color:#941923}
html body.dark-mode .delete-modal-box{background:#151b23 !important;border:1px solid #2c3542}
html body.dark-mode .delete-modal-box h3,
html body.dark-mode .delete-modal-box p strong{color:#edf2f7 !important}
html body.dark-mode .delete-modal-box p{color:#8f9bac !important}
html body.dark-mode .delete-modal-icon{background:#382326 !important;color:#f07a80 !important}
html body.dark-mode .delete-modal-cancel{background:#202733 !important;border-color:#2c3542 !important;color:#cbd2dc !important}
html body.dark-mode .delete-modal-cancel:hover{background:#2a3342 !important}

/* ===== Jarak kolom konsisten (judul & isi sejajar di tepi kiri yang sama) ===== */

/* Lebar kolom: Admin Divisi */
.admin-panel .admin-table th:nth-child(1),.admin-panel .admin-table td:nth-child(1){width:5% !important}
.admin-panel .admin-table th:nth-child(2),.admin-panel .admin-table td:nth-child(2){width:16% !important}
.admin-panel .admin-table th:nth-child(3),.admin-panel .admin-table td:nth-child(3){width:16% !important}
.admin-panel .admin-table th:nth-child(4),.admin-panel .admin-table td:nth-child(4){width:14% !important}
.admin-panel .admin-table th:nth-child(5),.admin-panel .admin-table td:nth-child(5){width:18% !important}
.admin-panel .admin-table th:nth-child(6),.admin-panel .admin-table td:nth-child(6){width:12% !important}
.admin-panel .admin-table th:nth-child(7),.admin-panel .admin-table td:nth-child(7){width:19% !important}

/* Lebar kolom: Karyawan */
.employee-panel table th:nth-child(1),.employee-panel table td:nth-child(1){width:5% !important}
.employee-panel table th:nth-child(2),.employee-panel table td:nth-child(2){width:22% !important}
.employee-panel table th:nth-child(3),.employee-panel table td:nth-child(3){width:20% !important}
.employee-panel table th:nth-child(4),.employee-panel table td:nth-child(4){width:15% !important}
.employee-panel table th:nth-child(5),.employee-panel table td:nth-child(5){width:23% !important}
.employee-panel table th:nth-child(6),.employee-panel table td:nth-child(6){width:15% !important}

/* Kolom No: tengah. Kolom lain: rata kiri dengan jarak tepi yang sama */
.admin-panel .admin-table th:nth-child(1),
.admin-panel .admin-table td:nth-child(1),
.employee-panel table th:nth-child(1),
.employee-panel table td:nth-child(1){
    text-align:center !important;
    padding-left:8px !important;
    padding-right:8px !important;
}

.admin-panel .admin-table th:nth-child(n+2),
.admin-panel .admin-table td:nth-child(n+2),
.employee-panel table th:nth-child(n+2),
.employee-panel table td:nth-child(n+2){
    text-align:left !important;
    padding-left:16px !important;
    padding-right:8px !important;
}

/* Kolom Aksi: mulai dari tepi kiri yang sama dengan judulnya */
.admin-panel .admin-table td.action-cell{
    text-align:left !important;
    padding-left:16px !important;
    padding-right:8px !important;
}
.action-wrapper{justify-content:flex-start !important}

.employee-panel table td.empty-data,
.admin-panel .admin-table td.empty-data{text-align:center !important;padding-left:16px !important}
    </style>
</head>

<body>

    <?php
    include "../includes/super_admin/sidebar.php";
    ?>

    <div class="main">

        <?php
        include "../includes/super_admin/topbar.php";
        ?>

        <div class="content">

            <div class="page-title">
                <h1>Data Pengguna</h1>
                <p>Kelola Pimpinan Divisi, akun Departemen, dan lihat data karyawan dalam sistem SIMO.</p>
            </div>

            <?php if ($pesan_tampil): ?>
                <div class="alert-msg alert-<?= $pesan_tampil[0]; ?>">
                    <?= htmlspecialchars($pesan_tampil[1]); ?>
                </div>
            <?php endif; ?>

            <div class="tab-switch">
                <a href="pengguna.php?tab=admin"
                   class="tab-btn <?= $tab_dipilih === "admin" ? "active" : "" ?>">
                    Admin Divisi
                </a>
                <a href="pengguna.php?tab=karyawan"
                   class="tab-btn <?= $tab_dipilih === "karyawan" ? "active" : "" ?>">
                    Karyawan
                </a>
                <a href="pengguna.php?tab=departemen"
                   class="tab-btn <?= $tab_dipilih === "departemen" ? "active" : "" ?>">
                    Departemen
                </a>
            </div>

            <?php if ($tab_dipilih === "admin"): ?>

            <div class="section admin-panel">

                <div class="section-header">

                    <div class="section-title-area">
                        <div class="section-title">Admin Divisi</div>
                        <div class="section-description">Data Pimpinan Divisi yang dikelola oleh Super Admin.</div>
                    </div>

                    <a href="tambah_admin.php" class="btn-tambah">
                        + Tambah Pimpinan Divisi
                    </a>

                </div>

                <div class="table-card">

                    <div class="table-wrapper">

                        <table class="admin-table">

                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Divisi</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (mysqli_num_rows($result_admin) > 0): ?>

                                    <?php $no_admin = 1; ?>

                                    <?php while ($admin = mysqli_fetch_assoc($result_admin)): ?>

                                        <?php
                                        $admin_aktif =
                                            trim($admin["status"]) === "Aktif";
                                        ?>

                                        <tr class="<?= !$admin_aktif ? "admin-inactive" : "" ?>">

                                            <td><?= $no_admin++; ?></td>

                                            <td class="admin-name">
                                                <?= htmlspecialchars($admin["nama"]); ?>
                                            </td>

                                            <td class="admin-username">
                                                <?= htmlspecialchars($admin["username"]); ?>
                                            </td>

                                            <td>
                                                <span class="user-role-badge user-role-admin">
                                                    Admin Divisi
                                                </span>
                                            </td>

                                            <td class="admin-divisi">
                                                <?= htmlspecialchars($admin["nama_divisi"] ?? "-"); ?>
                                            </td>

                                            <td>
                                                <?php if ($admin_aktif): ?>
                                                    <span class="status status-aktif">Aktif</span>
                                                <?php else: ?>
                                                    <span class="status status-nonaktif">Tidak Aktif</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="action-cell">

                                                <div class="action-wrapper">

                                                    <a
                                                        href="edit_admin.php?id=<?= (int) $admin["id_user"]; ?>"
                                                        class="btn-edit"
                                                        title="Edit Admin Divisi"
                                                        aria-label="Edit Admin Divisi"
                                                    >
                                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                                            <path d="M4 20h4.2L19 9.2a1.8 1.8 0 0 0 0-2.5L17.3 5a1.8 1.8 0 0 0-2.5 0L5.7 14.1V20Z"></path>
                                                            <path d="M13.5 7.5 16.5 10.5"></path>
                                                        </svg>
                                                    </a>

                                                    <form
                                                        method="POST"
                                                        class="toggle-form"
                                                        onsubmit="return confirmToggle(<?= (int) $admin["id_user"] ?>, <?= $admin_aktif ? 'true' : 'false'; ?>);"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="toggle_admin"
                                                            value="<?= (int) $admin["id_user"]; ?>"
                                                        >

                                                        <label
                                                            class="toggle"
                                                            title="<?= $admin_aktif ? "Nonaktifkan Admin" : "Aktifkan Admin"; ?>"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                <?= $admin_aktif ? "checked" : "" ?>
                                                                onchange="this.form.submit();"
                                                            >
                                                            <span class="toggle-slider"></span>
                                                        </label>

                                                    </form>

                                                    <span class="toggle-label <?= $admin_aktif ? "aktif" : "nonaktif"; ?>">
                                                        <?= $admin_aktif ? "Aktif" : "Nonaktif"; ?>
                                                    </span>

                                                    <button
                                                        type="button"
                                                        class="btn-hapus"
                                                        title="Hapus Admin Divisi"
                                                        aria-label="Hapus Admin Divisi"
                                                        data-id="<?= (int) $admin["id_user"]; ?>"
                                                        data-nama="<?= htmlspecialchars($admin["nama"], ENT_QUOTES); ?>"
                                                    >
                                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                                            <path d="M3 6h18"></path>
                                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                                        </svg>
                                                    </button>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endwhile; ?>

                                <?php else: ?>

                                    <tr>
                                        <td colspan="7" class="empty-data">
                                            Belum ada Admin Divisi.
                                        </td>
                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

            <?php elseif ($tab_dipilih === "departemen"): ?>

            <?php include __DIR__ . "/departemen_panel.php"; ?>

            <?php else: ?>

            <div class="section employee-section">

                <div class="employee-panel">

                    <div class="employee-controls-card">

                        <div class="employee-top">

                            <div class="section-title-area">
                                <div class="section-title">Karyawan</div>
                                <div class="section-description">Pilih divisi untuk melihat data karyawan.</div>
                            </div>

                            <div class="employee-controls-right">

                                <form method="GET" class="status-inline-form">

                                    <input type="hidden" name="tab" value="karyawan">

                                    <?php if ($divisi_dipilih > 0): ?>
                                        <input type="hidden" name="divisi" value="<?= $divisi_dipilih; ?>">
                                    <?php endif; ?>

                                    <div class="status-inline">

                                        <label>Status Karyawan</label>

                                        <select name="status">
                                            <option value="" <?= $status_dipilih == "" ? "selected" : "" ?>>Semua Status</option>
                                            <option value="Aktif" <?= $status_dipilih == "Aktif" ? "selected" : "" ?>>Aktif</option>
                                            <option value="Tidak Aktif" <?= $status_dipilih == "Tidak Aktif" ? "selected" : "" ?>>Tidak Aktif</option>
                                        </select>

                                    </div>

                                    <button type="submit" class="btn-filter-inline">
                                        Tampilkan
                                    </button>

                                    <?php if ($status_dipilih != ""): ?>

                                        <a href="pengguna.php?tab=karyawan<?= $divisi_dipilih > 0 ? "&divisi=" . $divisi_dipilih : "" ?>"
                                           class="btn-reset-inline">
                                            Reset
                                        </a>

                                    <?php endif; ?>

                                </form>

                                <div class="divisi-buttons">

                                    <a
                                        href="pengguna.php?tab=karyawan<?= $status_dipilih != "" ? "&status=" . urlencode($status_dipilih) : "" ?>"
                                        class="btn-divisi <?= $divisi_dipilih == 0 ? "active" : "" ?>"
                                    >
                                        Semua
                                    </a>

                                    <?php
                                    mysqli_data_seek($result_divisi, 0);

                                    while ($divisi = mysqli_fetch_assoc($result_divisi)):

                                        $id = (int) $divisi["id_divisi"];

                                        $nama = $divisi["nama_divisi"];

                                        if ($nama == "Mekanikal Infrastruktur") {
                                            $label = "ME";
                                        } elseif ($nama == "Elektrikal/Komunikasi") {
                                            $label = "EK";
                                        } elseif ($nama == "ITAS") {
                                            $label = "ITAS";
                                        } elseif ($nama == "Transportasi") {
                                            $label = "Transportasi";
                                        } else {
                                            $label = $nama;
                                        }
                                    ?>

                                        <a
                                            href="pengguna.php?tab=karyawan&divisi=<?= $id; ?><?= $status_dipilih != "" ? "&status=" . urlencode($status_dipilih) : "" ?>"
                                            class="btn-divisi <?= $divisi_dipilih == $id ? "active" : "" ?>"
                                        >
                                            <?= htmlspecialchars($label); ?>
                                        </a>

                                    <?php endwhile; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="table-card employee-data-table">

                        <div class="table-wrapper">

                            <table>

                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Nama</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Divisi</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php if (mysqli_num_rows($result_karyawan) > 0): ?>

                                        <?php $no_karyawan = 1; ?>

                                        <?php while ($karyawan = mysqli_fetch_assoc($result_karyawan)): ?>

                                            <tr
                                                class="employee-row"
                                                onclick="window.location.href='detail_karyawan.php?id=<?= (int) $karyawan["id_user"] ?>';"
                                                title="Klik untuk melihat detail karyawan"
                                            >

                                                <td><?= $no_karyawan++; ?></td>

                                                <td>
                                                    <?= htmlspecialchars($karyawan["nama"]); ?>
                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($karyawan["username"]); ?>
                                                </td>

                                                <td>
                                                    <span class="user-role-badge user-role-karyawan">
                                                        Karyawan
                                                    </span>
                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($karyawan["nama_divisi"] ?? "-"); ?>
                                                </td>

                                                <td>
                                                    <?php if (trim($karyawan["status"]) === "Aktif"): ?>
                                                        <span class="status status-aktif">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="status status-nonaktif">Tidak Aktif</span>
                                                    <?php endif; ?>
                                                </td>

                                            </tr>

                                        <?php endwhile; ?>

                                    <?php else: ?>

                                        <tr>
                                            <td colspan="6" class="empty-data">

                                                <?php if ($status_dipilih == "Tidak Aktif"): ?>

                                                    Tidak ada karyawan yang tidak aktif pada divisi ini.

                                                <?php elseif ($status_dipilih == "Aktif"): ?>

                                                    Tidak ada karyawan yang aktif pada divisi ini.

                                                <?php else: ?>

                                                    Belum ada data karyawan pada divisi ini.

                                                <?php endif; ?>

                                            </td>
                                        </tr>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

            <?php endif; ?>

            <div class="footer-note">
                © <?= date("Y"); ?>
                SIMO METRP. All rights reserved.
            </div>

        </div>

    </div>


    <!-- POPUP KONFIRMASI HAPUS (ADMIN DIVISI) -->
    <div class="delete-modal-overlay" id="deleteModal">
        <div class="delete-modal-box">

            <div class="delete-modal-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 6h18"></path>
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                    <path d="M10 11v5"></path>
                    <path d="M14 11v5"></path>
                </svg>
            </div>

            <h3>Hapus Admin Divisi?</h3>

            <p>
                Akun <strong id="deleteNama"></strong> akan dihapus dan tidak
                dapat digunakan untuk login lagi.
            </p>

            <form method="POST" class="delete-modal-actions">
                <input type="hidden" name="hapus_admin" id="deleteId" value="">
                <button type="button" class="delete-modal-btn delete-modal-cancel" id="batalHapus">Batal</button>
                <button type="submit" class="delete-modal-btn delete-modal-confirm">Ya, Hapus</button>
            </form>

        </div>
    </div>

    <script>
    (function () {

        var modal = document.getElementById("deleteModal");
        var inputId = document.getElementById("deleteId");
        var teksNama = document.getElementById("deleteNama");

        function tutup() {
            modal.classList.remove("show");
            inputId.value = "";
        }

        document.querySelectorAll(".btn-hapus").forEach(function (tombol) {
            tombol.addEventListener("click", function () {
                inputId.value = this.getAttribute("data-id") || "";
                teksNama.textContent = this.getAttribute("data-nama") || "";
                modal.classList.add("show");
            });
        });

        document.getElementById("batalHapus").addEventListener("click", tutup);

        modal.addEventListener("click", function (e) {
            if (e.target === modal) { tutup(); }
        });

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") { tutup(); }
        });

    })();
    </script>

</body>

</html>
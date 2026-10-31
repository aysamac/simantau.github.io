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
    SELECT
        users.*,
        divisi.nama_divisi
    FROM users
    LEFT JOIN divisi
        ON users.id_divisi = divisi.id_divisi
    WHERE users.id_user='$id_karyawan'
    AND users.role='Karyawan'
    LIMIT 1
    "
);

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
$nama_divisi = $karyawan["nama_divisi"] ?? "-";

function h($t)
{
    return htmlspecialchars((string) $t, ENT_QUOTES, "UTF-8");
}

/* =========================================================
   TAB TIPE ORDER (kode di URL => nama tipe order di database)
========================================================= */
$peta_tab = [
    "order-perbaikan" => "Order Perbaikan",
    "non-order"       => "Non Order",
    "maintenance"     => "Maintenance",
];

$label_uraian_tab = [
    "order-perbaikan" => "Uraian Kerusakan",
    "non-order"       => "Uraian Pekerjaan",
    "maintenance"     => "Uraian Maintenance",
];

$tab_aktif = $_GET["tab"] ?? "order-perbaikan";
if (!isset($peta_tab[$tab_aktif])) {
    $tab_aktif = "order-perbaikan";
}

$tipe_aktif = $peta_tab[$tab_aktif];
$tipe_aman = mysqli_real_escape_string($koneksi, $tipe_aktif);
$label_uraian = $label_uraian_tab[$tab_aktif];

/* Order Perbaikan: kolom lengkap (No. Order, Peralatan, Lokasi). Lainnya: kolom ringkas + Dibuat Oleh */
$tabel_lengkap = ($tab_aktif === "order-perbaikan");
$jumlah_kolom = $tabel_lengkap ? 14 : 12;

$jenis_tugas = isset($_GET["jenis"])
    ? trim($_GET["jenis"])
    : "individu";

if (
    $jenis_tugas != "individu" &&
    $jenis_tugas != "tim"
) {
    $jenis_tugas = "individu";
}

$filter_status = isset($_GET["status"])
    ? trim($_GET["status"])
    : "";

/*
 * Status "Peralihan" TIDAK ditampilkan sebagai status di daftar.
 * Pekerjaan hasil peralihan dianggap "Belum Dikerjakan" bagi
 * karyawan pengganti. Info peralihan hanya tampil di halaman detail.
 */
$status_valid = [
    "Belum Dikerjakan",
    "Menunggu Konfirmasi",
    "Perlu Perbaikan",
    "Selesai"
];

$status_tugas_aktif = "
    'Belum Dikerjakan',
    'Menunggu Konfirmasi',
    'Perlu Perbaikan',
    'Peralihan'
";

/*
 * JUMLAH ANGGOTA PENANGGUNG JAWAB AKTIF
 * -----------------------------------
 * Penentu tugas INDIVIDU atau TIM:
 *   - 0 atau 1 karyawan aktif     => tugas INDIVIDU
 *   - 2 karyawan aktif atau lebih => tugas TIM
 *
 * Status 'Menunggu Konfirmasi' dan 'Perlu Perbaikan' tetap dihitung,
 * supaya tugas tim tidak berubah menjadi tugas individu setelah
 * bukti dikirim.
 */
$jumlah_anggota_aktif = "
    (
        SELECT COUNT(DISTINCT pk_aktif.id_user)
        FROM pekerjaan_karyawan pk_aktif
        WHERE
            pk_aktif.id_pekerjaan=p.id_pekerjaan
            AND pk_aktif.status_penugasan IN (
                'Aktif',
                'Peralihan',
                'Menunggu Konfirmasi',
                'Perlu Perbaikan'
            )
    )
";

/* Jalankan query hitung yang dikelompokkan per tipe order => [tipe => total] */
function hitung_per_tipe($koneksi, $sql)
{
    $hasil = [];
    $q = mysqli_query($koneksi, $sql);

    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $hasil[$r["tipe_order"]] = (int) $r["total"];
        }
    }

    return $hasil;
}

/* ===== JUMLAH TUGAS INDIVIDU (per tipe order) ===== */
$jml_individu = hitung_per_tipe(
    $koneksi,
    "
    SELECT
        p.tipe_order,
        COUNT(DISTINCT p.id_pekerjaan) AS total
    FROM pekerjaan p
    WHERE
        p.id_divisi='$id_divisi'
        AND p.status IN ($status_tugas_aktif)
        AND
        (
            EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_user
                WHERE
                    pk_user.id_pekerjaan=p.id_pekerjaan
                    AND pk_user.id_user='$id_karyawan'
            )
            OR
            (
                p.id_karyawan='$id_karyawan'
                AND NOT EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_lama
                    WHERE
                        pk_lama.id_pekerjaan=p.id_pekerjaan
                )
            )
        )
        AND
        (
            $jumlah_anggota_aktif < 2
            OR
            (
                p.id_karyawan='$id_karyawan'
                AND NOT EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk3
                    WHERE
                        pk3.id_pekerjaan=p.id_pekerjaan
                )
            )
        )
        AND
        (
            EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_normal
                WHERE
                    pk_normal.id_pekerjaan=p.id_pekerjaan
                    AND pk_normal.id_user='$id_karyawan'
                    AND pk_normal.status_penugasan
                        IN ('Aktif','Peralihan')
            )
            OR
            (
                p.status='Perlu Perbaikan'
                AND EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_repair
                    WHERE
                        pk_repair.id_pekerjaan=p.id_pekerjaan
                        AND pk_repair.id_user='$id_karyawan'
                )
            )
            OR
            (
                p.id_karyawan='$id_karyawan'
                AND NOT EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_old
                    WHERE
                        pk_old.id_pekerjaan=p.id_pekerjaan
                )
            )
        )
    GROUP BY p.tipe_order
    "
);

/* ===== JUMLAH TUGAS TIM (per tipe order) ===== */
$jml_tim = hitung_per_tipe(
    $koneksi,
    "
    SELECT
        p.tipe_order,
        COUNT(DISTINCT p.id_pekerjaan) AS total
    FROM pekerjaan p
    WHERE
        p.id_divisi='$id_divisi'
        AND p.status IN ($status_tugas_aktif)
        AND EXISTS (
            SELECT 1
            FROM pekerjaan_karyawan pk_user
            WHERE
                pk_user.id_pekerjaan=p.id_pekerjaan
                AND pk_user.id_user='$id_karyawan'
        )
        AND $jumlah_anggota_aktif >= 2
        AND
        (
            EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_active
                WHERE
                    pk_active.id_pekerjaan=p.id_pekerjaan
                    AND pk_active.id_user='$id_karyawan'
                    AND pk_active.status_penugasan
                        IN ('Aktif','Peralihan')
            )
            OR
            (
                p.status IN ('Menunggu Konfirmasi','Perlu Perbaikan')
                AND EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_repair
                    WHERE
                        pk_repair.id_pekerjaan=p.id_pekerjaan
                        AND pk_repair.id_user='$id_karyawan'
                )
            )
        )
    GROUP BY p.tipe_order
    "
);

/* ===== BADGE TUGAS BARU INDIVIDU (per tipe order) ===== */
$baru_individu_tipe = hitung_per_tipe(
    $koneksi,
    "
    SELECT
        p.tipe_order,
        COUNT(DISTINCT p.id_pekerjaan) AS total
    FROM pekerjaan p
    WHERE
        p.id_divisi='$id_divisi'
        AND p.status IN (
            'Belum Dikerjakan',
            'Peralihan'
        )
        AND
        (
            EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_user
                WHERE
                    pk_user.id_pekerjaan=p.id_pekerjaan
                    AND pk_user.id_user='$id_karyawan'
                    AND pk_user.status_penugasan
                        IN ('Aktif','Peralihan')
            )
            OR
            (
                p.id_karyawan='$id_karyawan'
                AND NOT EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_lama
                    WHERE
                        pk_lama.id_pekerjaan=p.id_pekerjaan
                )
            )
        )
        AND $jumlah_anggota_aktif < 2
    GROUP BY p.tipe_order
    "
);

/* ===== BADGE TUGAS BARU TIM (per tipe order) ===== */
$baru_tim_tipe = hitung_per_tipe(
    $koneksi,
    "
    SELECT
        p.tipe_order,
        COUNT(DISTINCT p.id_pekerjaan) AS total
    FROM pekerjaan p
    WHERE
        p.id_divisi='$id_divisi'
        AND p.status IN (
            'Belum Dikerjakan',
            'Peralihan'
        )
        AND EXISTS (
            SELECT 1
            FROM pekerjaan_karyawan pk_user
            WHERE
                pk_user.id_pekerjaan=p.id_pekerjaan
                AND pk_user.id_user='$id_karyawan'
                AND pk_user.status_penugasan
                    IN ('Aktif','Peralihan')
        )
        AND $jumlah_anggota_aktif >= 2
    GROUP BY p.tipe_order
    "
);

/* nilai untuk tab tipe order yang sedang dibuka */
$total_individu = $jml_individu[$tipe_aktif] ?? 0;
$total_tim = $jml_tim[$tipe_aktif] ?? 0;
$baru_individu = $baru_individu_tipe[$tipe_aktif] ?? 0;
$baru_tim = $baru_tim_tipe[$tipe_aktif] ?? 0;

/* kolom tambahan untuk tabel (sama untuk tugas individu dan tim) */
$kolom_tambahan = "
    p.nama_pelapor,
    p.departemen_asal,
    p.nama_peralatan,
    p.uraian_kerusakan,
    p.lokasi,
    (
        SELECT GROUP_CONCAT(DISTINCT u_rel.nama ORDER BY u_rel.nama ASC SEPARATOR ', ')
        FROM pekerjaan_karyawan pk_rel
        INNER JOIN users u_rel ON u_rel.id_user=pk_rel.id_user
        WHERE
            pk_rel.id_pekerjaan=p.id_pekerjaan
            AND pk_rel.status_penugasan IN (
                'Aktif',
                'Peralihan',
                'Menunggu Konfirmasi',
                'Perlu Perbaikan'
            )
    ) AS nama_karyawan_relasi,
    $jumlah_anggota_aktif AS jumlah_anggota,
    (
        SELECT u_leg.nama
        FROM users u_leg
        WHERE u_leg.id_user=p.id_karyawan
        LIMIT 1
    ) AS nama_karyawan_legacy,
    (
        SELECT u_buat.nama
        FROM users u_buat
        WHERE u_buat.id_user=p.id_pembuat
        LIMIT 1
    ) AS nama_pembuat
";

/* ===== QUERY DAFTAR PEKERJAAN ===== */
if ($jenis_tugas == "tim") {

    $query_pekerjaan = "
        SELECT
            p.id_pekerjaan,
            p.nama_pekerjaan,
            p.jenis_pekerjaan,
            p.tipe_order,
            p.nomor_order,
            p.tanggal_mulai,
            p.target_selesai,
            p.deskripsi,
            p.status,
            $kolom_tambahan,
            CASE p.status
                WHEN 'Perlu Perbaikan' THEN 1
                WHEN 'Belum Dikerjakan' THEN 2
                WHEN 'Peralihan' THEN 2
                WHEN 'Menunggu Konfirmasi' THEN 3
                WHEN 'Selesai' THEN 4
                ELSE 5
            END AS urutan_status
        FROM pekerjaan p
        LEFT JOIN pekerjaan_karyawan pk_login
            ON pk_login.id_pekerjaan=p.id_pekerjaan
            AND pk_login.id_user='$id_karyawan'
        WHERE
            p.id_divisi='$id_divisi'
            AND p.tipe_order='$tipe_aman'
            AND p.status IN ($status_tugas_aktif)
            AND pk_login.id IS NOT NULL
            AND $jumlah_anggota_aktif >= 2
            AND
            (
                (
                    p.status IN ('Menunggu Konfirmasi','Perlu Perbaikan')
                    AND
                    (
                        p.id_karyawan='$id_karyawan'
                        OR pk_login.id IS NOT NULL
                    )
                )
                OR
                (
                    p.status NOT IN ('Menunggu Konfirmasi','Perlu Perbaikan')
                    AND pk_login.status_penugasan IN ('Aktif','Peralihan')
                )
            )
    ";

} else {

    $query_pekerjaan = "
        SELECT DISTINCT
            p.id_pekerjaan,
            p.nama_pekerjaan,
            p.jenis_pekerjaan,
            p.tipe_order,
            p.nomor_order,
            p.tanggal_mulai,
            p.target_selesai,
            p.deskripsi,
            p.status,
            $kolom_tambahan,
            CASE p.status
                WHEN 'Perlu Perbaikan' THEN 1
                WHEN 'Belum Dikerjakan' THEN 2
                WHEN 'Peralihan' THEN 2
                WHEN 'Menunggu Konfirmasi' THEN 3
                WHEN 'Selesai' THEN 4
                ELSE 5
            END AS urutan_status
        FROM pekerjaan p
        WHERE
            p.id_divisi='$id_divisi'
            AND p.tipe_order='$tipe_aman'
            AND p.status IN ($status_tugas_aktif)
            AND
            (
                EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_user
                    WHERE
                        pk_user.id_pekerjaan=p.id_pekerjaan
                        AND pk_user.id_user='$id_karyawan'
                )
                OR
                (
                    p.id_karyawan='$id_karyawan'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM pekerjaan_karyawan pk_lama
                        WHERE
                            pk_lama.id_pekerjaan=p.id_pekerjaan
                    )
                )
            )
            AND $jumlah_anggota_aktif < 2
            AND
            (
                (
                    p.status IN ('Menunggu Konfirmasi','Perlu Perbaikan')
                    AND
                    (
                        p.id_karyawan='$id_karyawan'
                        OR EXISTS (
                            SELECT 1
                            FROM pekerjaan_karyawan pk_status
                            WHERE
                                pk_status.id_pekerjaan=p.id_pekerjaan
                                AND pk_status.id_user='$id_karyawan'
                        )
                    )
                )
                OR
                (
                    p.status NOT IN ('Menunggu Konfirmasi','Perlu Perbaikan')
                    AND
                    (
                        EXISTS (
                            SELECT 1
                            FROM pekerjaan_karyawan pk_normal
                            WHERE
                                pk_normal.id_pekerjaan=p.id_pekerjaan
                                AND pk_normal.id_user='$id_karyawan'
                                AND pk_normal.status_penugasan IN ('Aktif','Peralihan')
                        )
                        OR
                        (
                            p.id_karyawan='$id_karyawan'
                            AND NOT EXISTS (
                                SELECT 1
                                FROM pekerjaan_karyawan pk_old
                                WHERE
                                    pk_old.id_pekerjaan=p.id_pekerjaan
                            )
                        )
                    )
                )
            )
    ";
}

if (
    $filter_status != "" &&
    in_array($filter_status, $status_valid, true)
) {
    if ($filter_status == "Belum Dikerjakan") {
        /* pekerjaan hasil peralihan juga dianggap "Belum Dikerjakan" */
        $query_pekerjaan .= "
            AND p.status IN ('Belum Dikerjakan','Peralihan')
        ";
    } else {
        $filter_status_aman = mysqli_real_escape_string(
            $koneksi,
            $filter_status
        );

        $query_pekerjaan .= "
            AND p.status='$filter_status_aman'
        ";
    }
}

/*
 * URUTAN DAFTAR:
 * 1. Perlu Perbaikan      (harus kirim bukti lagi)
 * 2. Belum Dikerjakan     (termasuk hasil peralihan)
 * 3. Menunggu Konfirmasi  (menunggu admin)
 * 4. Selesai
 * Di dalam kelompok yang sama: tanggal mulai terbaru di atas.
 */
$query_pekerjaan .= "
    ORDER BY
        urutan_status ASC,
        p.tanggal_mulai DESC,
        p.id_pekerjaan DESC
";

$data_pekerjaan = mysqli_query(
    $koneksi,
    $query_pekerjaan
);

if (!$data_pekerjaan) {
    die(
        "Data pekerjaan gagal dimuat: "
        . mysqli_error($koneksi)
    );
}

$jumlah_data = mysqli_num_rows($data_pekerjaan);

/* Status "Peralihan" ditampilkan sebagai "Belum Dikerjakan" di daftar */
function statusTampil($status)
{
    return $status == "Peralihan" ? "Belum Dikerjakan" : $status;
}

function classStatus($status)
{
    if ($status == "Selesai") {
        return "badge-selesai";
    }

    if ($status == "Menunggu Konfirmasi") {
        return "badge-menunggu";
    }

    if ($status == "Perlu Perbaikan") {
        return "badge-perbaikan";
    }

    return "badge-belum";
}

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

/* URL tab: tipe order + jenis (individu/tim) + filter status */
function url_tugas($tab, $jenis, $status)
{
    $q = ["tab" => $tab, "jenis" => $jenis];

    if ($status != "") {
        $q["status"] = $status;
    }

    return "tugas.php?" . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Tugas Saya - SIMO</title>

<style>

* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }

body { background: #ffffff; min-height: 100vh; color: #2c3644; }

a { text-decoration: none; color: inherit; }

.layout { min-height: 100vh; width: 100%; }

.main { margin-left: 240px; width: calc(100% - 240px); min-width: 0; min-height: 100vh; }

.content { padding: 26px 32px 40px; }

.page-header { margin-bottom: 22px; }
.page-header h1 { font-size: 22px; color: #1c2636; margin-bottom: 5px; }
.page-header p { font-size: 13.5px; color: #4f5b6c; }

/* ===== TAB TIPE ORDER ===== */
.tabs { display: flex; gap: 6px; border-bottom: 1px solid #d9dde2; margin-bottom: 18px; overflow-x: auto; }
.tab { display: flex; align-items: center; gap: 8px; padding: 11px 16px; font-size: 13px; font-weight: 600; color: #667488; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; transition: color .15s, border-color .15s; }
.tab:hover { color: #b51f2a; }
.tab.aktif { color: #b51f2a; border-bottom-color: #b51f2a; }
.tab .jml { min-width: 22px; padding: 2px 7px; border-radius: 20px; background: #eef1f5; color: #667488; font-size: 11px; font-weight: 700; text-align: center; }
.tab.aktif .jml { background: #f7e3e5; color: #b51f2a; }

/* ===== TAB INDIVIDU / TIM ===== */
.task-tabs { display: flex; gap: 10px; margin-bottom: 18px; }

.task-tab {
    position: relative; display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    min-width: 160px; height: 42px; padding: 0 18px; background: #fff; border: 1px solid #d8dde4;
    border-radius: 8px; color: #667488; font-size: 12px; font-weight: 600;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.task-tab:hover { background: #fafbfc; color: #526176; }
.task-tab.active { background: #b51f2a; border-color: #b51f2a; color: #fff; }

.new-count {
    min-width: 18px; height: 18px; padding: 0 5px; display: inline-flex; align-items: center; justify-content: center;
    background: #d92f3d; color: #fff; border-radius: 20px; font-size: 9px; font-weight: 700;
    box-shadow: 0 2px 5px rgba(217,47,61,.18);
}
.task-tab.active .new-count { background: #fff; color: #b51f2a; }

/* ===== FILTER STATUS ===== */
.filter-bar { display: flex; align-items: center; gap: 10px; padding: 12px 22px; background: #f7f8fa; border-bottom: 1px solid #edf1f6; }
.filter-bar .filter-label { font-size: 13px; font-weight: 700; color: #1f2a3a; }
.filter-bar select {
    height: 38px; padding: 0 12px; border: 1px solid #cfd7e2; border-radius: 8px; background: #fff;
    color: #1f2a3a; font-size: 13px; font-weight: 600; outline: none; min-width: 190px; font-family: "Segoe UI", Arial, sans-serif;
}
.filter-bar select:focus { border-color: #b51f2a; }
.filter-reset { font-size: 13px; font-weight: 700; color: #b51f2a; text-decoration: underline; padding: 6px 4px; }

.btn-filter { height: 38px; padding: 0 18px; border: none; border-radius: 8px; background: #b51f2a; color: #fff; font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; }
.btn-filter:hover { background: #961923; }

/* ===== KARTU & TABEL ===== */
.card { background: #ffffff; border: 1px solid #d8dde4; border-radius: 12px; padding: 0; box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden; }

.card-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0; padding: 18px 22px; border-bottom: 1px solid #edf1f6; }
.card-title h3 { font-size: 15px; font-weight: 700; color: #111a28; }
.card-title p { font-size: 12.5px; color: #4f5b6c; margin-top: 3px; }
.total-data { font-size: 12.5px; font-weight: 600; color: #4f5b6c; }

.table-wrapper { width: 100%; overflow-x: auto; padding: 0; -webkit-overflow-scrolling: touch; }

table { width: 100%; min-width: 1400px; border-collapse: collapse; table-layout: auto; }
table.lengkap { min-width: 1700px; }

th, td { vertical-align: middle; }

th { background: #b51f2a; padding: 13px 12px; text-align: left; font-size: 12px; color: #fff; font-weight: 700; white-space: nowrap; border: 0; border-bottom: 1px solid #8f1821; }

td { padding: 12px; border-bottom: 1px solid #f0f3f7; font-size: 13px; color: #172033; text-align: left; white-space: nowrap; height: 58px; }

.row-pekerjaan { cursor: pointer; transition: background .15s ease; }

.job-name { font-size: 13px; font-weight: 600; color: #172033; max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.txt { max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.txt.uraian { max-width: 240px; }
.txt.karyawan { max-width: 220px; }

.nomor-order { display: inline-block; font-weight: 700; color: #1d4ed8; letter-spacing: .2px; font-variant-numeric: tabular-nums; }
.nomor-kosong { color: #9aa3af; font-weight: 400; }

.jenis-chips { display: flex; flex-wrap: nowrap; gap: 5px; min-width: 0; }
.jenis-chip { display: inline-block; padding: 4px 8px; border-radius: 6px; background: #f9e9eb; color: #b51f2a; font-size: 10.5px; font-weight: 700; line-height: 1.3; white-space: nowrap; }
.jumlah-karyawan-table { display: inline-block; padding: 5px 9px; border-radius: 6px; background: #f9e9eb; color: #b51f2a; font-size: 10.5px; font-weight: 700; white-space: nowrap; }

.status-badge { display: inline-block; padding: 5px 9px; border-radius: 6px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }

.badge-belum { background: #e3eaf4; color: #3d4a5c; }
.badge-sedang { background: #fff0d5; color: #c87b0c; }
.badge-menunggu { background: #e3eeff; color: #1f5bb5; }
.badge-perbaikan { background: #fde2e2; color: #b92f2f; }
.badge-selesai { background: #d8f3e3; color: #1c7a47; }

.aksi-cell { position: relative; z-index: 5; }

.btn-kirim {
    position: relative; z-index: 10; display: inline-flex; align-items: center; justify-content: center;
    padding: 7px 11px; background: #f9e8ea; color: #b51f2a; border-radius: 7px; font-size: 10px; font-weight: 600;
    white-space: nowrap; cursor: pointer; pointer-events: auto; transition: background .15s ease;
}
.btn-kirim:hover { background: #f5dadd; color: #981923; }

.btn-kirim-perbaikan { background: #fff0eb; color: #c35c32; }
.btn-kirim-perbaikan:hover { background: #fbe0d7; color: #a94725; }

.aksi-selesai { font-size: 11px; color: #1c7a47; font-weight: 600; white-space: nowrap; }

.empty { text-align: center; padding: 40px 20px; color: #8b96a5; font-size: 13px; white-space: normal; }

.footer-note { text-align: center; font-size: 12px; color: #a3adba; padding: 26px 0 6px; }

.btn-edit-bukti { background: #e3eeff; color: #1f5bb5; }
.btn-edit-bukti:hover { background: #dfeaff; color: #2866b8; }

tbody tr td { background: #fff !important; }
tbody tr:hover td { background: #fafbfd !important; }

/* ===== HP / LAYAR SEMPIT: tiap baris jadi kartu ===== */
@media (max-width: 1000px) {
    .table-wrapper { overflow-x: visible; }
    table, table.lengkap { min-width: 0; }
    .card table, .card tbody, .card tr, .card td { display: block; width: 100%; }
    .card thead { display: none; }
    .card tbody { padding: 12px; }

    .card tbody tr {
        display: grid;
        grid-template-columns: 1fr 1fr;
        border: 1px solid #e3e8ee;
        border-radius: 12px;
        margin-bottom: 12px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 1px 3px rgba(16,24,40,.05);
    }
    .card tbody tr:last-child { margin-bottom: 0; }

    .card tbody tr td {
        display: block;
        width: auto;
        min-width: 0;
        height: auto;
        padding: 10px 14px;
        text-align: left;
        white-space: normal !important;
        border-bottom: 0;
        font-size: 15px;
        font-weight: 500;
        line-height: 1.4;
        color: #111a28;
        overflow-wrap: anywhere;
    }

    .card td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 3px;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: .3px;
        text-transform: uppercase;
        color: #4f5b6c;
    }

    .job-name, .txt, .txt.uraian, .txt.karyawan { max-width: none; white-space: normal; overflow: visible; text-overflow: clip; }
    .jenis-chips { flex-wrap: wrap; }

    /* nomor disembunyikan */
    .card tbody tr td.c-no { display: none; }

    /* judul pekerjaan = header kartu */
    .card tbody tr td.c-nama {
        grid-column: 1 / -1;
        background: #fdf3f4 !important;
        padding: 13px 14px;
    }
    .card tbody tr td.c-nama::before { display: none; }
    .card tbody tr td.c-nama .job-name { font-size: 16px; font-weight: 700; line-height: 1.4; color: #111a28; }

    /* teks panjang selebar kartu */
    .card tbody tr td.c-lebar { grid-column: 1 / -1; }

    /* baris bawah: status kiri, tombol kanan */
    .card tbody tr td.c-status,
    .card tbody tr td.c-aksi {
        display: flex;
        align-items: center;
        border-top: 1px solid #edf1f6;
        padding: 12px 14px;
    }
    .card tbody tr td.c-status::before,
    .card tbody tr td.c-aksi::before { display: none; }
    .card tbody tr td.c-aksi { justify-content: flex-end; }

    .card tbody tr td.c-aksi .btn-kirim {
        display: inline-flex;
        width: auto;
        padding: 10px 18px;
        font-size: 13.5px;
        font-weight: 700;
        border-radius: 8px;
    }
    .card tbody tr td.c-aksi .aksi-selesai { display: inline-block; font-size: 14px; }

    .status-badge { font-size: 12px; padding: 7px 11px; }

    .card tbody tr td.empty { display: block; grid-column: 1 / -1; text-align: center; border: 0; padding: 30px 16px; }
    .card tbody tr td.empty::before { display: none; }
}

@media (max-width: 800px) {
    .main { margin-left: 0; width: 100%; }
    .content { padding: 20px; }
}

@media (max-width: 600px) {
    .content { padding: 16px 12px 28px; }
    .page-header { margin-bottom: 16px; }
    .page-header h1 { font-size: 20px; }
    .page-header p { font-size: 12.5px; }
    .task-tabs { width: 100%; }
    .task-tab { flex: 1; min-width: 0; padding: 0 10px; }
    .card-title { padding: 14px; }

    .filter-bar { padding: 10px 14px; gap: 8px; }
    .filter-bar select { flex: 0 1 220px; min-width: 0; height: 40px; font-size: 14px; }
}

/* ===== DARK MODE ===== */

html:has(body.dark-mode), html:has(body.dark-mode) body { background: #0f141b !important; }
body.dark-mode { background: #0f141b; color: #e8edf4; }
body.dark-mode .content { background: #0f141b; }
body.dark-mode .page-header h1 { color: #edf2f7; }
body.dark-mode .page-header p { color: #c3ccd8; }

body.dark-mode .tabs { border-bottom-color: #2c3542; }
body.dark-mode .tab { color: #8f9bac; }
body.dark-mode .tab:hover { color: #f07a80; }
body.dark-mode .tab.aktif { color: #f07a80; border-bottom-color: #b51f2a; }
body.dark-mode .tab .jml { background: #202733; color: #c3ccd8; }
body.dark-mode .tab.aktif .jml { background: #382326; color: #f07a80; }

body.dark-mode .task-tab { background: #151b23; border-color: #303947; color: #aeb8c6; box-shadow: none; }
body.dark-mode .task-tab:hover { background: #202733; color: #dce4ed; }
body.dark-mode .task-tab.active { background: #b51f2a; border-color: #b51f2a; color: #fff; }

body.dark-mode .new-count { background: #d92f3d; color: #fff; }
body.dark-mode .task-tab.active .new-count { background: #fff; color: #b51f2a; }

body.dark-mode .filter-bar { background: #1b222c; border-bottom-color: #2c3542; }
body.dark-mode .filter-bar .filter-label { color: #ffffff; }
body.dark-mode .filter-bar select { background: #202733; border-color: #3a4553; color: #ffffff; }
body.dark-mode .filter-bar select option { background: #202733; color: #ffffff; }
body.dark-mode .filter-reset { color: #ff9298; }

body.dark-mode .btn-filter { background: #b51f2a; color: #fff; }
body.dark-mode .btn-filter:hover { background: #941923; }

body.dark-mode .card { background: #151b23; border-color: #2c3542; box-shadow: none; }
body.dark-mode .card-title { border-bottom-color: #2c3542; }
body.dark-mode .card-title h3 { color: #ffffff; }
body.dark-mode .card-title p { color: #c3ccd8; }
body.dark-mode .total-data { color: #c3ccd8; }
body.dark-mode .table-wrapper { background: #151b23; }

body.dark-mode th { background: #b51f2a !important; color: #fff !important; border-bottom-color: #b51f2a; }

body.dark-mode td { background: #151b23 !important; color: #fff; border-bottom-color: #2c3542; }
body.dark-mode tbody tr td { background: #151b23 !important; color: #fff; }
body.dark-mode tbody tr:hover td { background: #202733 !important; }
body.dark-mode .row-pekerjaan:hover td { background: #202733 !important; }

body.dark-mode .job-name,
body.dark-mode .txt { color: #fff; }
body.dark-mode .nomor-order { color: #8eb5ff; }
body.dark-mode .nomor-kosong { color: #6f7a8a; }
body.dark-mode .jenis-chip { background: #382326; color: #f07a80; }
body.dark-mode .jumlah-karyawan-table { background: #382326; color: #f07a80; }

body.dark-mode .btn-kirim { background: #4a292d; color: #ffa3a8; }
body.dark-mode .btn-kirim:hover { background: #5a3035; color: #ffb8bc; }
body.dark-mode .btn-kirim-perbaikan { background: #4a3328; color: #ffa982; }
body.dark-mode .btn-kirim-perbaikan:hover { background: #493128; color: #f09b78; }
body.dark-mode .btn-edit-bukti { background: #243650; color: #a9ccff; }
body.dark-mode .btn-edit-bukti:hover { background: #2d4463; color: #c2dcff; }

body.dark-mode .aksi-selesai { color: #86e0ab; }
body.dark-mode .empty { color: #8f9bac; }
body.dark-mode .footer-note { color: #747e8c; }

body.dark-mode .badge-belum { background: #2d3644; color: #d5dce6; }
body.dark-mode .badge-sedang { background: #3b3020; color: #e4aa4f; }
body.dark-mode .badge-menunggu { background: #243650; color: #a9ccff; }
body.dark-mode .badge-perbaikan { background: #4d2b2f; color: #ffa0a0; }
body.dark-mode .badge-selesai { background: #244a35; color: #8fe5b3; }

table thead th { background: #b51f2a !important; color: #fff !important; }
body.dark-mode table thead th { background: #b51f2a !important; color: #fff !important; }

@media (max-width: 1000px) {
    body.dark-mode .card tbody tr { background: #151b23; border-color: #2c3542; box-shadow: none; }
    body.dark-mode .card tbody tr td.c-nama { background: #3a2226 !important; border-bottom: 1px solid #5a2d33; box-shadow: none; }
    body.dark-mode .card td::before { color: #c3ccd8; }
    body.dark-mode .card tbody tr td { color: #ffffff; }
    body.dark-mode .card tbody tr td.c-nama .job-name { color: #ffffff; }
    body.dark-mode .card tbody tr td.c-status,
    body.dark-mode .card tbody tr td.c-aksi { border-top-color: #2c3542; }
}

</style>
</head>

<body>

<div class="layout">

<?php
include "../includes/karyawan/sidebar.php";
?>

<main class="main">

<?php
include "../includes/karyawan/topbar.php";
?>

<div class="content">

<section class="page-header">
    <h1>Tugas Saya</h1>
    <p>Daftar pekerjaan yang diberikan kepada Anda.</p>
</section>

<!-- TAB TIPE ORDER -->
<div class="tabs" role="tablist">
    <?php foreach ($peta_tab as $kode => $nama_tipe): ?>
        <a
            href="<?= h(url_tugas($kode, $jenis_tugas, $filter_status)); ?>"
            class="tab <?= $kode === $tab_aktif ? "aktif" : ""; ?>"
            role="tab"
            aria-selected="<?= $kode === $tab_aktif ? "true" : "false"; ?>"
        >
            <?= h($nama_tipe); ?>
            <span class="jml"><?= (int) (($jml_individu[$nama_tipe] ?? 0) + ($jml_tim[$nama_tipe] ?? 0)); ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- TAB INDIVIDU / TIM -->
<div class="task-tabs">

    <a
        href="<?= h(url_tugas($tab_aktif, "individu", $filter_status)); ?>"
        class="task-tab <?= $jenis_tugas == "individu" ? "active" : ""; ?>"
    >
        <span>Tugas Individu</span>

        <?php if ($baru_individu > 0): ?>
            <span class="new-count"><?= $baru_individu; ?></span>
        <?php endif; ?>
    </a>

    <a
        href="<?= h(url_tugas($tab_aktif, "tim", $filter_status)); ?>"
        class="task-tab <?= $jenis_tugas == "tim" ? "active" : ""; ?>"
    >
        <span>Tugas Tim</span>

        <?php if ($baru_tim > 0): ?>
            <span class="new-count"><?= $baru_tim; ?></span>
        <?php endif; ?>
    </a>

</div>

<div class="card">

<div class="card-title">

    <div>
        <?php if ($jenis_tugas == "tim"): ?>
            <h3>Daftar Tugas Tim &ndash; <?= h($tipe_aktif); ?></h3>
            <p>Pekerjaan yang dikerjakan bersama anggota tim.</p>
        <?php else: ?>
            <h3>Daftar Tugas Individu &ndash; <?= h($tipe_aktif); ?></h3>
            <p>Pekerjaan yang diberikan secara individu.</p>
        <?php endif; ?>
    </div>

    <div class="total-data">
        <?= $jumlah_data; ?> pekerjaan
    </div>

</div>

<form method="GET" class="filter-bar">

    <input type="hidden" name="tab" value="<?= h($tab_aktif); ?>">
    <input type="hidden" name="jenis" value="<?= h($jenis_tugas); ?>">

    <label for="filterStatus" class="filter-label">Status</label>

    <select name="status" id="filterStatus" onchange="this.form.submit()">
        <option value="">Semua Status</option>

        <?php foreach ($status_valid as $opsi_status): ?>
            <option value="<?= h($opsi_status); ?>" <?= $filter_status == $opsi_status ? "selected" : ""; ?>>
                <?= h($opsi_status); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if ($filter_status != ""): ?>
        <a href="<?= h(url_tugas($tab_aktif, $jenis_tugas, "")); ?>" class="filter-reset">Reset</a>
    <?php endif; ?>

    <noscript><button type="submit" class="btn-filter">Tampilkan</button></noscript>

</form>

<div class="table-wrapper">

<table class="<?= $tabel_lengkap ? "lengkap" : ""; ?>">

<thead>
<tr>
    <th>No</th>
    <?php if ($tabel_lengkap): ?>
        <th>No. Order</th>
    <?php endif; ?>
    <th>Nama Pekerjaan</th>
    <th>Pelapor</th>
    <?php if (!$tabel_lengkap): ?>
        <th>Dibuat Oleh</th>
    <?php endif; ?>
    <th>Dept</th>
    <th>Jenis</th>
    <?php if ($tabel_lengkap): ?>
        <th>Peralatan</th>
    <?php endif; ?>
    <th><?= h($label_uraian); ?></th>
    <?php if ($tabel_lengkap): ?>
        <th>Lokasi</th>
    <?php endif; ?>
    <?php if ($jenis_tugas == "tim"): ?>
        <th>Jumlah</th>
    <?php else: ?>
        <th>Dikerjakan Oleh</th>
    <?php endif; ?>
    <th>Mulai</th>
    <th>Selesai</th>
    <th>Status</th>
    <th>Aksi</th>
</tr>
</thead>

<tbody>

<?php
$no = 1;

if ($jumlah_data > 0):

    while ($row = mysqli_fetch_assoc($data_pekerjaan)):

        $status        = $row["status"];
        $status_label  = statusTampil($status);          // "Peralihan" => "Belum Dikerjakan"
        $class_status  = classStatus($status_label);
        $id_row        = (int) $row["id_pekerjaan"];

        $teks_nomor     = !empty($row["nomor_order"]) ? $row["nomor_order"] : "-";
        $teks_pelapor   = !empty($row["nama_pelapor"]) ? $row["nama_pelapor"] : "-";
        $teks_pembuat   = !empty($row["nama_pembuat"]) ? $row["nama_pembuat"] : "-";
        $teks_peralatan = !empty($row["nama_peralatan"]) ? $row["nama_peralatan"] : "-";
        $teks_uraian    = !empty($row["uraian_kerusakan"]) ? $row["uraian_kerusakan"] : "-";
        $teks_lokasi    = !empty($row["lokasi"]) ? $row["lokasi"] : "-";

        if (!empty($row["departemen_asal"])) {
            $teks_departemen = $row["departemen_asal"];
        } else {
            $teks_departemen = $tabel_lengkap ? "-" : $nama_divisi;
        }

        if (!empty($row["nama_karyawan_relasi"])) {
            $teks_karyawan = $row["nama_karyawan_relasi"];
        } else {
            $teks_karyawan = $row["nama_karyawan_legacy"] ?? "-";
        }

        $jumlah_anggota = (int) ($row["jumlah_anggota"] ?? 0);
        if ($jumlah_anggota < 1) {
            $jumlah_anggota = 1;
        }

        $daftar_jenis_row = array_filter(
            array_map("trim", explode(",", (string) ($row["jenis_pekerjaan"] ?? "")))
        );
?>

<tr
    class="row-pekerjaan"
    onclick="window.location.href='detail_tugas.php?id=<?= $id_row; ?>';"
>

<td class="c-no" data-label="No"><?= $no++; ?></td>

<?php if ($tabel_lengkap): ?>
<td data-label="No. Order">
    <?php if ($teks_nomor !== "-"): ?>
        <span class="nomor-order"><?= h($teks_nomor); ?></span>
    <?php else: ?>
        <span class="nomor-kosong">-</span>
    <?php endif; ?>
</td>
<?php endif; ?>

<td class="c-nama" data-label="Nama Pekerjaan">
    <div class="job-name" title="<?= h($row["nama_pekerjaan"]); ?>"><?= h($row["nama_pekerjaan"]); ?></div>
</td>

<td data-label="Pelapor">
    <div class="txt" title="<?= h($teks_pelapor); ?>"><?= h($teks_pelapor); ?></div>
</td>

<?php if (!$tabel_lengkap): ?>
<td data-label="Dibuat Oleh">
    <div class="txt" title="<?= h($teks_pembuat); ?>"><?= h($teks_pembuat); ?></div>
</td>
<?php endif; ?>

<td data-label="Dept">
    <div class="txt" title="<?= h($teks_departemen); ?>"><?= h($teks_departemen); ?></div>
</td>

<td data-label="Jenis">
    <div class="jenis-chips">
        <?php if ($daftar_jenis_row): foreach ($daftar_jenis_row as $jn): ?>
            <span class="jenis-chip"><?= h($jn); ?></span>
        <?php endforeach; else: ?>
            -
        <?php endif; ?>
    </div>
</td>

<?php if ($tabel_lengkap): ?>
<td data-label="Peralatan">
    <div class="txt" title="<?= h($teks_peralatan); ?>"><?= h($teks_peralatan); ?></div>
</td>
<?php endif; ?>

<td class="c-lebar" data-label="<?= h($label_uraian); ?>">
    <div class="txt uraian" title="<?= h($teks_uraian); ?>"><?= h($teks_uraian); ?></div>
</td>

<?php if ($tabel_lengkap): ?>
<td data-label="Lokasi">
    <div class="txt" title="<?= h($teks_lokasi); ?>"><?= h($teks_lokasi); ?></div>
</td>
<?php endif; ?>

<?php if ($jenis_tugas == "tim"): ?>
<td data-label="Jumlah">
    <span class="jumlah-karyawan-table"><?= $jumlah_anggota; ?> Karyawan</span>
</td>
<?php else: ?>
<td class="c-lebar" data-label="Dikerjakan Oleh">
    <div class="txt karyawan" title="<?= h($teks_karyawan); ?>"><?= h($teks_karyawan); ?></div>
</td>
<?php endif; ?>

<td data-label="Mulai"><?= h(formatTanggal($row["tanggal_mulai"])); ?></td>

<td data-label="Selesai"><?= h(formatTanggal($row["target_selesai"])); ?></td>

<td class="c-status" data-label="Status">
    <span class="status-badge <?= $class_status; ?>">
        <?= h($status_label); ?>
    </span>
</td>

<td class="aksi-cell c-aksi" data-label="Aksi">

<?php if ($status_label == "Belum Dikerjakan"): ?>

    <a
        href="kirim_bukti.php?id=<?= $id_row; ?>"
        class="btn-kirim"
        onclick="event.stopPropagation();"
        onmousedown="event.stopPropagation();"
    >
        Kirim Bukti
    </a>

<?php elseif ($status_label == "Perlu Perbaikan"): ?>

    <a
        href="kirim_bukti.php?id=<?= $id_row; ?>"
        class="btn-kirim btn-kirim-perbaikan"
        onclick="event.stopPropagation();"
        onmousedown="event.stopPropagation();"
    >
        Kirim Bukti Lagi
    </a>

<?php elseif ($status_label == "Menunggu Konfirmasi"): ?>

    <a
        href="detail_tugas.php?id=<?= $id_row; ?>"
        class="btn-kirim btn-edit-bukti"
        onclick="event.stopPropagation();"
        onmousedown="event.stopPropagation();"
    >
        Edit Bukti
    </a>

<?php elseif ($status_label == "Selesai"): ?>

    <span class="aksi-selesai">Selesai</span>

<?php else: ?>

    <a
        href="kirim_bukti.php?id=<?= $id_row; ?>"
        class="btn-kirim"
        onclick="event.stopPropagation();"
        onmousedown="event.stopPropagation();"
    >
        Kirim Bukti
    </a>

<?php endif; ?>

</td>

</tr>

<?php
    endwhile;

else:
?>

<tr>
    <td colspan="<?= (int) $jumlah_kolom; ?>" class="empty">

        <?php if ($filter_status == "Perlu Perbaikan"): ?>
            Tidak ada pekerjaan <?= h($tipe_aktif); ?> yang perlu diperbaiki.
        <?php elseif ($jenis_tugas == "tim"): ?>
            Belum ada tugas tim <?= h($tipe_aktif); ?> yang diberikan kepada Anda.
        <?php else: ?>
            Belum ada tugas individu <?= h($tipe_aktif); ?> yang diberikan kepada Anda.
        <?php endif; ?>

    </td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<div class="footer-note">
    &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
</div>

</div>

</main>

</div>

</body>
</html>
<?php

include "../config/session.php";

/* ================= CEK LOGIN ================= */

if (!isset($_SESSION["id_user"]) || ($_SESSION["role"] ?? "") != "Karyawan") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/* ================= DATA KARYAWAN ================= */

$id_karyawan = (int) $_SESSION["id_user"];

$query_karyawan = mysqli_query($koneksi, "
    SELECT u.id_user, u.nama, u.jabatan, u.id_divisi, d.nama_divisi
    FROM users u
    LEFT JOIN divisi d ON u.id_divisi = d.id_divisi
    WHERE u.id_user = '$id_karyawan'
    LIMIT 1
");

if (!$query_karyawan || mysqli_num_rows($query_karyawan) == 0) {
    header("Location: tugas.php");
    exit;
}

$karyawan    = mysqli_fetch_assoc($query_karyawan);
$id_divisi   = (int) ($karyawan["id_divisi"] ?? 0);
$nama_divisi = $karyawan["nama_divisi"] ?? "-";

/* ================= CEK ID PEKERJAAN ================= */

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: tugas.php");
    exit;
}

$id_pekerjaan = (int) $_GET["id"];

/* ================= DATA PEKERJAAN ================= */

$query_pekerjaan = mysqli_query($koneksi, "
    SELECT p.*, d.nama_divisi, u.nama AS nama_penanggung_jawab
    FROM pekerjaan p
    LEFT JOIN divisi d ON p.id_divisi = d.id_divisi
    LEFT JOIN users u ON u.id_user = p.id_karyawan
    WHERE p.id_pekerjaan = '$id_pekerjaan'
        AND p.id_divisi = '$id_divisi'
        AND (
            EXISTS (
                SELECT 1 FROM pekerjaan_karyawan pk_login
                WHERE pk_login.id_pekerjaan = p.id_pekerjaan
                    AND pk_login.id_user = '$id_karyawan'
            )
            OR (
                p.id_karyawan = '$id_karyawan'
                AND NOT EXISTS (
                    SELECT 1 FROM pekerjaan_karyawan pk_lama
                    WHERE pk_lama.id_pekerjaan = p.id_pekerjaan
                )
            )
        )
    LIMIT 1
");

if (!$query_pekerjaan || mysqli_num_rows($query_pekerjaan) == 0) {
    header("Location: tugas.php");
    exit;
}

$pekerjaan = mysqli_fetch_assoc($query_pekerjaan);

/* status asli di database (dipakai untuk logika tombol) */
$status = $pekerjaan["status"] ?? "Belum Dikerjakan";

/* status yang DITAMPILKAN: "Peralihan" tampil sebagai "Belum Dikerjakan" */
$status_tampil = ($status == "Peralihan") ? "Belum Dikerjakan" : $status;

$id_penanggung_jawab = (int) ($pekerjaan["id_karyawan"] ?? 0);
$is_penanggung_jawab = ($id_penanggung_jawab === $id_karyawan);

/* ================= RIWAYAT PERALIHAN (yang sudah berhasil) ================= */

$pengalihan_list = [];

$query_pengalihan = mysqli_query($koneksi, "
    SELECT pp.*, u1.nama AS nama_pengaju, u2.nama AS nama_pengganti
    FROM pengalihan_pekerjaan pp
    LEFT JOIN users u1 ON pp.id_karyawan_pengaju = u1.id_user
    LEFT JOIN users u2 ON pp.id_karyawan_pengganti = u2.id_user
    WHERE pp.id_pekerjaan = '$id_pekerjaan'
        AND pp.status_pengalihan = 'Berhasil'
    ORDER BY pp.tanggal_pengajuan ASC, pp.id_pengalihan ASC
");

if ($query_pengalihan) {
    while ($row = mysqli_fetch_assoc($query_pengalihan)) {
        $pengalihan_list[] = $row;
    }
}

/* ================= HELPER ================= */

function e($value)
{
    return htmlspecialchars((string) ($value ?? "-"), ENT_QUOTES, "UTF-8");
}

/*
 * Cari URL foto dari admin.
 * Folder tujuan upload admin belum pasti, jadi dicek ke beberapa folder umum.
 * Kalau folder admin kamu beda, tambahkan namanya di array $folder_kandidat.
 * Return: URL relatif jika file ketemu, atau null jika tidak ada.
 */
function urlFotoAdmin($nama_file)
{
    $nama_file = trim((string) $nama_file);
    if ($nama_file === "") {
        return null;
    }

    /* kalau di database tersimpan lengkap dengan path (mis. uploads/pekerjaan/a.jpg) */
    $relatif = ltrim(str_replace("\\", "/", $nama_file), "/");
    if (strpos($relatif, "/") !== false && file_exists(__DIR__ . "/../" . $relatif)) {
        return "../" . implode("/", array_map("rawurlencode", explode("/", $relatif)));
    }

    $nama_dasar = basename($relatif);

    $folder_kandidat = [
        "pekerjaan",
        "foto_pekerjaan",
        "tugas",
        "pekerjaan_foto",
        "bukti"
    ];

    foreach ($folder_kandidat as $folder) {
        if (file_exists(__DIR__ . "/../uploads/" . $folder . "/" . $nama_dasar)) {
            return "../uploads/" . $folder . "/" . rawurlencode($nama_dasar);
        }
    }

    /* terakhir: langsung di folder uploads */
    if (file_exists(__DIR__ . "/../uploads/" . $nama_dasar)) {
        return "../uploads/" . rawurlencode($nama_dasar);
    }

    return null;
}

/*
 * Susun anggota yang SAAT INI memegang pekerjaan.
 * - Karyawan yang sudah mengalihkan pekerjaannya tidak ikut ditampilkan.
 * - Karyawan pengganti diberi keterangan "(sebelumnya ...)".
 * Return: [daftar_anggota, peta_sebelumnya]
 *   peta_sebelumnya[id_user] = "Nama Lama 1, Nama Lama 2"
 */
function susunAnggotaTim(array $anggota, array $pengalihan): array
{
    $dihapus    = [];
    $sebelumnya = [];
    $nama       = [];

    foreach ($pengalihan as $p) {
        $a = (int) ($p["id_karyawan_pengaju"] ?? 0);
        $b = (int) ($p["id_karyawan_pengganti"] ?? 0);

        if ($a <= 0 || $b <= 0 || $a === $b) {
            continue;
        }

        $nama[$a] = $p["nama_pengaju"] ?? "-";
        $nama[$b] = $p["nama_pengganti"] ?? "-";

        $dihapus[$a] = true;
        unset($dihapus[$b]);

        $riwayat   = $sebelumnya[$a] ?? [];
        $riwayat[] = $nama[$a];
        $riwayat   = array_values(array_unique(array_filter(
            $riwayat,
            fn($n) => $n !== $nama[$b]
        )));

        $sebelumnya[$b] = $riwayat;
        unset($sebelumnya[$a]);
    }

    $hasil = [];
    $ada   = [];

    foreach ($anggota as $a) {
        $id = (int) $a["id_user"];
        if (isset($dihapus[$id])) {
            continue;
        }
        $hasil[] = $a;
        $ada[$id] = true;
    }

    /* pengganti yang belum tercatat di pekerjaan_karyawan */
    foreach ($sebelumnya as $id => $riwayat) {
        if (!isset($ada[$id]) && !isset($dihapus[$id])) {
            $hasil[] = [
                "id_user" => $id,
                "nama"    => $nama[$id] ?? "-",
                "jabatan" => ""
            ];
        }
    }

    $peta = [];
    foreach ($sebelumnya as $id => $riwayat) {
        if (!empty($riwayat)) {
            $peta[$id] = implode(", ", $riwayat);
        }
    }

    return [$hasil, $peta];
}

/* ================= ANGGOTA TIM ================= */

$data_tim_semua = [];

$query_tim = mysqli_query($koneksi, "
    SELECT u.id_user, u.nama, u.jabatan, MIN(pk.id) AS urutan
    FROM pekerjaan_karyawan pk
    INNER JOIN users u ON u.id_user = pk.id_user
    WHERE pk.id_pekerjaan = '$id_pekerjaan'
    GROUP BY u.id_user, u.nama, u.jabatan
    ORDER BY urutan ASC
");

if ($query_tim) {
    while ($row = mysqli_fetch_assoc($query_tim)) {
        $data_tim_semua[] = $row;
    }
}

/* data lama tanpa pekerjaan_karyawan */
if (count($data_tim_semua) == 0 && $id_penanggung_jawab > 0) {

    $query_utama = mysqli_query($koneksi, "
        SELECT id_user, nama, jabatan FROM users
        WHERE id_user = '$id_penanggung_jawab' LIMIT 1
    ");

    if ($query_utama && mysqli_num_rows($query_utama) > 0) {
        $data_tim_semua[] = mysqli_fetch_assoc($query_utama);
    }
}

[$data_tim, $peta_sebelumnya] = susunAnggotaTim($data_tim_semua, $pengalihan_list);

/* 1 orang aktif = individu, 2 orang atau lebih = tim */
$is_tugas_tim = count($data_tim) >= 2;

/* nama penanggung jawab untuk tugas individu */
$id_pj_tampil   = $id_penanggung_jawab;
$nama_pj_tampil = $pekerjaan["nama_penanggung_jawab"] ?? "-";

if (count($data_tim) == 1) {
    $id_pj_tampil   = (int) $data_tim[0]["id_user"];
    $nama_pj_tampil = $data_tim[0]["nama"];
}

/* ================= KELUAR / GABUNG LAGI =================
 * Tombol toggle: klik "Keluar" mencatat jam keluar,
 * klik "Gabung lagi" mencatat jam gabung. Bisa berulang kali.
 */

$id_anggota_aktif = array_map(fn($a) => (int) $a["id_user"], $data_tim);

$bisa_keluar = in_array($id_karyawan, $id_anggota_aktif, true)
    && !in_array($status, ["Selesai", "Menunggu Konfirmasi"], true);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["aksi_keluar"]) && $bisa_keluar) {

    $stmt_cek = mysqli_prepare($koneksi, "
        SELECT id_log FROM log_keluar_pekerjaan
        WHERE id_pekerjaan = ? AND id_user = ? AND waktu_gabung IS NULL
        ORDER BY id_log DESC LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_cek, "ii", $id_pekerjaan, $id_karyawan);
    mysqli_stmt_execute($stmt_cek);
    $hasil_cek   = mysqli_stmt_get_result($stmt_cek);
    $log_terbuka = $hasil_cek ? mysqli_fetch_assoc($hasil_cek) : null;
    mysqli_stmt_close($stmt_cek);

    if ($log_terbuka) {
        /* sedang keluar -> catat gabung lagi */
        $id_log = (int) $log_terbuka["id_log"];
        $stmt = mysqli_prepare($koneksi, "UPDATE log_keluar_pekerjaan SET waktu_gabung = NOW() WHERE id_log = ?");
        mysqli_stmt_bind_param($stmt, "i", $id_log);
    } else {
        /* sedang bekerja -> catat keluar */
        $stmt = mysqli_prepare($koneksi, "INSERT INTO log_keluar_pekerjaan (id_pekerjaan, id_user, waktu_keluar) VALUES (?, ?, NOW())");
        mysqli_stmt_bind_param($stmt, "ii", $id_pekerjaan, $id_karyawan);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    header("Location: detail_tugas.php?id=" . $id_pekerjaan);
    exit;
}

/* riwayat keluar/gabung semua anggota, dikelompokkan per user */
$log_keluar = [];

$query_log = mysqli_query($koneksi, "
    SELECT * FROM log_keluar_pekerjaan
    WHERE id_pekerjaan = '$id_pekerjaan'
    ORDER BY id_log ASC
");

if ($query_log) {
    while ($row = mysqli_fetch_assoc($query_log)) {
        $log_keluar[(int) $row["id_user"]][] = $row;
    }
}

function sedangKeluar(array $logs): bool
{
    if (empty($logs)) {
        return false;
    }
    $terakhir = end($logs);
    return empty($terakhir["waktu_gabung"]);
}

function jamLog($waktu): string
{
    $t = strtotime((string) $waktu);
    if (!$t) {
        return "-";
    }
    return date("Y-m-d", $t) === date("Y-m-d") ? date("H:i", $t) : date("d M, H:i", $t);
}

function durasiLog($dari, $sampai): string
{
    $detik = max(0, strtotime((string) $sampai) - strtotime((string) $dari));
    $jam   = intdiv($detik, 3600);
    $menit = intdiv($detik % 3600, 60);

    if ($jam == 0 && $menit == 0) {
        return "kurang dari 1 menit";
    }
    return ($jam > 0 ? $jam . " jam " : "") . $menit . " menit";
}

/* HTML status + riwayat keluar/gabung yang ditampilkan di bawah nama */
function tampilLogKeluar(array $logs): string
{
    if (empty($logs)) {
        return "";
    }

    $html = '<div class="leave-log">';

    if (sedangKeluar($logs)) {
        $terakhir = end($logs);
        $html .= '<span class="leave-badge">Sedang keluar sejak ' . e(jamLog($terakhir["waktu_keluar"])) . '</span>';
    }

    $html .= '<ul class="leave-history">';

    foreach ($logs as $log) {
        $html .= '<li class="leave-item">';
        $html .= '<div class="leave-times">';

        $html .= '<span class="leave-time"><small>Keluar</small>' . e(jamLog($log["waktu_keluar"])) . '</span>';

        if (!empty($log["waktu_gabung"])) {
            $html .= '<span class="leave-arrow">&rarr;</span>';
            $html .= '<span class="leave-time"><small>Gabung lagi</small>' . e(jamLog($log["waktu_gabung"])) . '</span>';
            $html .= '</div>';
            $html .= '<span class="leave-duration">' . e(durasiLog($log["waktu_keluar"], $log["waktu_gabung"])) . '</span>';
        } else {
            $html .= '</div>';
            $html .= '<span class="leave-duration pending">Belum gabung lagi</span>';
        }

        $html .= '</li>';
    }

    $html .= '</ul></div>';

    return $html;
}

$saya_sedang_keluar = sedangKeluar($log_keluar[$id_karyawan] ?? []);

/* ================= FOTO DARI ADMIN =================
 * Foto yang diinput admin saat membuat pekerjaan
 * (tabel foto_pekerjaan: id, id_pekerjaan, nama_file, created_at).
 */

$foto_admin         = [];
$jumlah_foto_admin  = 0;

$query_foto_admin = mysqli_query($koneksi, "
    SELECT nama_file FROM foto_pekerjaan
    WHERE id_pekerjaan = '$id_pekerjaan'
    ORDER BY id ASC
");

$nama_foto_admin = [];

/* sumber 1: kolom foto di tabel pekerjaan (form admin yang baru), bisa lebih dari satu dipisah koma */
if (!empty($pekerjaan["foto"])) {
    foreach (explode(",", $pekerjaan["foto"]) as $f) {
        $f = trim($f);
        if ($f !== "") {
            $nama_foto_admin[] = $f;
        }
    }
}

/* sumber 2: tabel foto_pekerjaan */
if ($query_foto_admin) {
    while ($row = mysqli_fetch_assoc($query_foto_admin)) {
        $f = trim((string) ($row["nama_file"] ?? ""));
        if ($f !== "") {
            $nama_foto_admin[] = $f;
        }
    }
}

$nama_foto_admin   = array_values(array_unique($nama_foto_admin));
$jumlah_foto_admin = count($nama_foto_admin);

foreach ($nama_foto_admin as $f) {
    $url = urlFotoAdmin($f);
    if ($url !== null) {
        $foto_admin[] = $url;
    }
}

/* ================= SEMUA BUKTI PEKERJAAN =================
 * Bukti dari SEMUA karyawan yang pernah mengerjakan pekerjaan ini
 * (termasuk karyawan sebelum peralihan) tetap ditampilkan.
 * Bukti setiap karyawan tersimpan di baris terpisah, jadi edit
 * oleh satu karyawan hanya mengubah bukti miliknya sendiri.
 */

$bukti_list = [];

$query_bukti = mysqli_query($koneksi, "
    SELECT bp.*, u.nama AS nama_pengirim_bukti
    FROM bukti_pekerjaan bp
    LEFT JOIN users u ON u.id_user = bp.id_user
    WHERE bp.id_pekerjaan = '$id_pekerjaan'
    ORDER BY bp.id_bukti ASC
");

if ($query_bukti) {
    while ($row = mysqli_fetch_assoc($query_bukti)) {

        $foto_semua = [];

        if (!empty($row["foto"])) {
            $foto_semua = array_values(array_filter(array_map("trim", explode(",", $row["foto"]))));
            $foto_semua = array_slice($foto_semua, 0, 5);
        }

        $jumlah_tercatat = count($foto_semua);

        /* Foto dihapus otomatis H+1 setelah dikonfirmasi selesai, cek file masih ada */
        $foto_ada = array_values(array_filter($foto_semua, function ($nama_foto) {
            return file_exists(__DIR__ . "/../uploads/bukti/" . $nama_foto);
        }));

        $row["foto_list"]            = $foto_ada;
        $row["jumlah_foto_tercatat"] = $jumlah_tercatat;
        $row["foto_sudah_terhapus"]  = (
            $jumlah_tercatat > 0 &&
            count($foto_ada) == 0 &&
            $status == "Selesai"
        );

        /* foto sebelum pengerjaan (dikirim karyawan bersama bukti) */
        $foto_sebelum_semua = [];

        if (!empty($row["foto_sebelum"])) {
            $foto_sebelum_semua = array_values(array_filter(array_map("trim", explode(",", $row["foto_sebelum"]))));
            $foto_sebelum_semua = array_slice($foto_sebelum_semua, 0, 5);
        }

        $row["foto_sebelum_list"] = array_values(array_filter($foto_sebelum_semua, function ($nama_foto) {
            return file_exists(__DIR__ . "/../uploads/bukti/" . $nama_foto);
        }));

        $bukti_list[] = $row;
    }
}

/* ================= CATATAN PERBAIKAN ================= */

$catatan_perbaikan = "";

$query_catatan = mysqli_query($koneksi, "
    SELECT catatan_admin FROM bukti_pekerjaan
    WHERE id_pekerjaan = '$id_pekerjaan'
        AND catatan_admin IS NOT NULL
        AND catatan_admin != ''
    ORDER BY id_bukti DESC
    LIMIT 1
");

if ($query_catatan) {
    $data_catatan = mysqli_fetch_assoc($query_catatan);

    if ($data_catatan) {
        $catatan_perbaikan = $data_catatan["catatan_admin"];
    }
}

/* ================= STATUS CLASS ================= */

function status_class($status)
{
    return match ($status) {
        "Selesai"             => "badge-selesai",
        "Sedang Dikerjakan"   => "badge-sedang",
        "Menunggu Konfirmasi" => "badge-menunggu",
        "Perlu Perbaikan"     => "badge-perbaikan",
        default               => "badge-belum"
    };
}

$status_class = status_class($status_tampil);

/* ================= URAIAN (label mengikuti tipe pekerjaan) =================
 * Order perbaikan => Uraian Kerusakan
 * Maintenance     => Uraian Maintenance
 * Non order/lain  => Uraian Pekerjaan
 * Isi diambil dari kolom uraian_kerusakan; data lama yang hanya punya
 * deskripsi tetap tampil lewat kolom deskripsi.
 */
$tipe_lower = strtolower((string) ($pekerjaan["tipe_order"] ?? ""));

if (strpos($tipe_lower, "perbaikan") !== false) {
    $label_uraian = "Uraian Kerusakan";
} elseif (strpos($tipe_lower, "maint") !== false || strpos($tipe_lower, "pelihara") !== false) {
    $label_uraian = "Uraian Maintenance";
} else {
    $label_uraian = "Uraian Pekerjaan";
}

$isi_uraian = trim((string) ($pekerjaan["uraian_kerusakan"] ?? ""));

if ($isi_uraian === "") {
    $isi_uraian = trim((string) ($pekerjaan["deskripsi"] ?? ""));
}

/* ================= FORMAT TANGGAL ================= */

function formatTanggalWaktu($tanggal)
{
    if (empty($tanggal) || $tanggal == "0000-00-00" || $tanggal == "0000-00-00 00:00:00") {
        return "-";
    }

    $timestamp = strtotime($tanggal);

    return $timestamp ? date("d M Y, H:i", $timestamp) : "-";
}

/* ================= TOMBOL AKSI ================= */

$query_anggota_terlibat_alih = mysqli_query($koneksi, "SELECT id FROM pekerjaan_karyawan WHERE id_pekerjaan='$id_pekerjaan' AND id_user='$id_karyawan' LIMIT 1");
$is_anggota_terlibat_alih = $query_anggota_terlibat_alih && mysqli_num_rows($query_anggota_terlibat_alih) > 0;
$tampil_alih = (($is_penanggung_jawab || $is_anggota_terlibat_alih) && $status != "Selesai" && $status != "Menunggu Konfirmasi");

$tampil_kirim = in_array($status, ["Belum Dikerjakan", "Perlu Perbaikan", "Peralihan"], true);

$tampil_edit  = ($status == "Menunggu Konfirmasi");

$tampil_selesai = ($status == "Selesai");

$ada_aksi = $bisa_keluar || $tampil_alih || $tampil_kirim || $tampil_edit || $tampil_selesai;

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Detail Pekerjaan - SIMO</title>

<style>

*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}

body{background:#ffffff;color:#263345;min-height:100vh}

a{text-decoration:none;color:inherit}

.main{margin-left:240px;width:calc(100% - 240px);min-height:100vh}

.content{padding:24px 32px 40px;max-width:1400px;margin:auto}

/* JUDUL HALAMAN */

.page-title{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:18px}

.page-title h2{font-size:26px;font-weight:700;color:#1c2636;margin-bottom:4px}

.page-title p{font-size:15px;color:#7e8998}

.btn-back{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border:1px solid #e4e8ed;border-radius:8px;background:#f7f8fa;color:#596678;font-size:12px;font-weight:600;white-space:nowrap}

.btn-back:hover{background:#eef1f4}

.btn-back svg{width:15px;height:15px}

/* CARD */

.card{background:#fff;border:1px solid #edf1f5;border-radius:14px;padding:20px;box-shadow:0 3px 14px rgba(20,40,70,.035)}

.detail-card{margin-top:16px;border:1px solid #d9dde2;padding:28px}

/* HEADER PEKERJAAN */

.job-top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:16px;border-bottom:1px solid #edf1f5}

.job-name{font-size:28px;font-weight:700;color:#1c2636}

.job-status{flex-shrink:0;padding:7px 11px;border-radius:20px;font-size:10px;font-weight:700}

.badge-selesai{background:#e8f5ed;color:#29965a}

.badge-sedang{background:#fff0d5;color:#c87b0c}

.badge-menunggu{background:#eee5ff;color:#7650a8}

.badge-perbaikan{background:#fde2d8;color:#c35c32}

.badge-belum{background:#eaf0f8;color:#607087}

/* INFORMASI PERALIHAN */

.transfer-info{margin-top:14px;padding:13px 15px;border:1px solid #cbdaf5;border-radius:9px;background:#f3f7ff}

.transfer-info-title{font-size:13px;font-weight:700;color:#365b9d;margin-bottom:7px}

.transfer-info p{font-size:13px;color:#40516b;line-height:1.6;overflow-wrap:anywhere}

.transfer-info strong{font-weight:700}

/* DETAIL */

.detail-grid{display:flex;flex-direction:column;margin-top:4px}

.detail-item{display:grid;grid-template-columns:190px 1fr;align-items:start;column-gap:18px;padding:11px 0;border-bottom:1px solid #edf1f5}

.detail-label{font-size:12.5px;color:#748197;margin:0}

.detail-label{display:flex;justify-content:space-between;gap:6px}

.detail-label::after,.description-title::after{content:":"}

.detail-value{font-size:14px;font-weight:600;color:#263345}

.person-value{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.you-badge,.team-you{display:inline-block;padding:3px 8px;border-radius:10px;background:#f9e8ea;color:#b51f2a;font-size:11px;font-weight:600}

.prev-note{font-size:12px;font-weight:400;color:#748197}

.employee-list{display:flex;flex-direction:column;gap:7px}

.employee{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.employee-number{width:7px;height:7px;border-radius:50%;background:#a5212b;flex-shrink:0;font-size:0}

.employee-name{font-size:13.5px;color:#263345;font-weight:600}

/* RIWAYAT KELUAR / GABUNG */

.leave-log{flex-basis:100%;width:100%;margin-top:6px}

.leave-badge{display:inline-block;margin-bottom:8px;padding:4px 10px;border-radius:12px;background:#fff0d5;color:#9a5b00;font-size:11px;font-weight:600}

.leave-history{list-style:none;margin:0;padding:0 0 0 12px;border-left:2px solid #e4e8ed;display:flex;flex-direction:column;gap:8px}

.leave-item{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:8px 12px;border:1px solid #edf1f5;border-radius:8px;background:#f9fafc}

.leave-times{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

.leave-time{display:flex;flex-direction:column;font-size:12.5px;font-weight:600;color:#263345;line-height:1.3}

.leave-time small{font-size:10px;font-weight:500;color:#8b96a5;text-transform:uppercase;letter-spacing:.4px}

.leave-arrow{color:#a3adba;font-size:14px}

.leave-duration{padding:3px 9px;border-radius:10px;background:#e8f5ed;color:#29965a;font-size:11px;font-weight:600;white-space:nowrap}

.leave-duration.pending{background:#fff0d5;color:#9a5b00}

/* DESKRIPSI */

.description-area{padding-top:18px}

.description-title{font-size:13px;font-weight:700;color:#263345;margin-bottom:8px}

.description-text{font-size:13.5px;font-weight:400;color:#526176;line-height:1.6}

/* FOTO DARI ADMIN */

.admin-photos{display:flex;flex-wrap:wrap;gap:10px}

.admin-photos .evidence-photo-item{width:160px;height:100px}

/* CATATAN PERBAIKAN */

.repair-note{margin-top:14px;padding:12px 14px;border:1px solid #f0d8d3;border-radius:9px;background:#fff7f5}

.repair-note-title{font-size:12px;font-weight:700;color:#b94b3e;margin-bottom:5px}

.repair-note-text{font-size:12.5px;font-weight:400;color:#667488;line-height:1.6}

/* SECTION */

.section-heading{font-size:16px;font-weight:700;color:#1c2636;margin-bottom:3px}

.section-desc{font-size:10.5px;color:#8b96a5;margin-bottom:14px}

/* BUKTI */

.evidence-list{display:flex;flex-direction:column;gap:10px}

.evidence{border:1px solid #e6ebf0;border-radius:10px;padding:12px}

.evidence-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:9px}

.evidence-person{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:11.5px;font-weight:700;color:#263345}

.evidence-date{font-size:9.5px;color:#8b96a5}

.evidence-body{display:grid;grid-template-columns:160px 1fr;gap:12px}

.evidence-photos{display:flex;flex-direction:column;gap:10px}

.evidence-photo-item{width:160px;height:100px;border-radius:7px;border:1px solid #e7ebef;overflow:hidden;background:#fff}

.evidence-photo-item img{width:100%;height:100%;object-fit:cover;display:block;cursor:pointer}

.evidence-photo-hint{margin-top:7px;font-size:10px;color:#8b96a5}

.evidence-note{background:#f7f9fb;border-radius:8px;padding:10px}

.evidence-note-title{font-size:12px;color:#6f7b8c;font-weight:700;margin-bottom:4px}

.evidence-note-text{font-size:12.5px;font-weight:400;color:#526176;line-height:1.55}

.empty-evidence{padding:18px;text-align:center;border:1px dashed #d9e0e7;border-radius:9px;color:#8b96a5;font-size:10.5px}

/* TOMBOL AKSI */

.action-area{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap}

/* tombol dasar: ukuran, ikon, fokus, dan animasi sama untuk semua tombol */

.btn-alihkan,.btn-kirim,.btn-keluar,.btn-gabung{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 20px;border:1px solid transparent;border-radius:10px;font-family:inherit;font-size:13px;font-weight:600;letter-spacing:.2px;line-height:1;white-space:nowrap;cursor:pointer;transition:background .18s ease,border-color .18s ease,color .18s ease,box-shadow .18s ease,transform .18s ease}

.btn-alihkan svg,.btn-kirim svg,.btn-keluar svg,.btn-gabung svg{width:16px;height:16px;flex-shrink:0}

.btn-alihkan:hover,.btn-kirim:hover,.btn-keluar:hover,.btn-gabung:hover{transform:translateY(-1px)}

.btn-alihkan:active,.btn-kirim:active,.btn-keluar:active,.btn-gabung:active{transform:translateY(0)}

.btn-alihkan:focus-visible,.btn-kirim:focus-visible,.btn-keluar:focus-visible,.btn-gabung:focus-visible{outline:3px solid rgba(181,31,42,.28);outline-offset:2px}

/* utama: Kirim Bukti / Edit Bukti */

.btn-kirim{background:linear-gradient(180deg,#bb2430 0%,#a01c27 100%);color:#fff;border-color:#8f1923;box-shadow:0 1px 2px rgba(120,16,24,.3),0 4px 10px rgba(181,31,42,.18)}

.btn-kirim:hover{background:linear-gradient(180deg,#a81f2a 0%,#8f1923 100%);box-shadow:0 2px 4px rgba(120,16,24,.3),0 8px 16px rgba(181,31,42,.25)}

/* sekunder: Alihkan Pekerjaan */

.btn-alihkan{background:#fff;color:#3d4a5c;border-color:#d5dbe3;box-shadow:0 1px 2px rgba(20,40,70,.06)}

.btn-alihkan:hover{background:#f5f7fa;border-color:#b9c2cf;color:#263345;box-shadow:0 3px 8px rgba(20,40,70,.1)}

/* Keluar (amber lembut) */

.btn-keluar{background:#fffaf0;color:#9a5b00;border-color:#ecd3a0;box-shadow:0 1px 2px rgba(154,91,0,.08)}

.btn-keluar:hover{background:#fff3dc;border-color:#e0bd78;box-shadow:0 3px 8px rgba(154,91,0,.15)}

/* Gabung lagi (hijau lembut) */

.btn-gabung{background:#f4fbf7;color:#1f7a4a;border-color:#b7dfc7;box-shadow:0 1px 2px rgba(31,122,74,.08)}

.btn-gabung:hover{background:#e6f6ed;border-color:#8fcca8;box-shadow:0 3px 8px rgba(31,122,74,.15)}

.action-info{font-size:11px;color:#697586}

.action-info.success{color:#29965a;font-weight:700}

.footer-note{text-align:center;padding:24px 0 6px;color:#a3adba;font-size:11px}

/* MODAL FOTO */

.foto-modal{display:none;position:fixed;z-index:9999;inset:0;background:rgba(0,0,0,.82);align-items:center;justify-content:center;padding:30px}

.foto-modal.active{display:flex}

.foto-modal img{max-width:95%;max-height:90vh;width:auto;height:auto;object-fit:contain;border-radius:6px}

.close-modal{position:absolute;top:20px;right:28px;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.15);color:#fff;font-size:25px;cursor:pointer}

/* RESPONSIVE */

@media(max-width:900px){

    .detail-item{grid-template-columns:150px 1fr}

}

@media(max-width:800px){

    .main{margin-left:0;width:100%}

    .content{padding:18px}

    .job-top{flex-direction:column}

    .detail-card{padding:20px}

}

@media(max-width:600px){

    .page-title{align-items:flex-start;flex-direction:column}

    .page-title h2{font-size:22px}

    .detail-item{grid-template-columns:118px 1fr;column-gap:12px}

    .detail-label{font-size:14px;font-weight:600;color:#3d4a5c;line-height:1.4}

    .detail-value{font-size:15px;overflow-wrap:anywhere}

    .leave-item{padding:7px 10px}

    .leave-times{gap:8px}

    .leave-time{font-size:12px}

    .job-name{font-size:22px}

    .evidence-body{grid-template-columns:1fr}

    .leave-item{flex-direction:column;align-items:flex-start;gap:6px}

    .leave-history{padding-left:8px}

}

/* DARK MODE */

body.dark-mode{background:#181b20!important;color:#eef1f5!important}

body.dark-mode .main,body.dark-mode .content{background:#181b20!important}

body.dark-mode h1,body.dark-mode h2,body.dark-mode h3,body.dark-mode label{color:#f1f3f5!important}

body.dark-mode p{color:#929baa!important}

body.dark-mode .page-title h2{color:#f1f3f5!important}

body.dark-mode .page-title p{color:#929baa!important}

body.dark-mode .btn-back{background:#292e36!important;border-color:#3b424d!important;color:#d8dee7!important}

body.dark-mode .btn-back:hover{background:#323842!important}

body.dark-mode .card,body.dark-mode .detail-card{background:#1f2227!important;border-color:#3a3f47!important;color:#eef1f5!important;box-shadow:none!important}

body.dark-mode .job-name{color:#f5f6f8!important;font-size:28px!important}

body.dark-mode .job-top{border-bottom-color:#3a3f47!important}

body.dark-mode .job-status{font-size:11px!important;padding:8px 12px!important}

body.dark-mode .transfer-info{background:#202a39!important;border-color:#3b4b63!important}

body.dark-mode .transfer-info-title{color:#a9c4ff!important}

body.dark-mode .transfer-info p{color:#e0e7f1!important}

body.dark-mode .detail-item{background:transparent!important;border-color:#363b43!important;padding:13px 0!important}

body.dark-mode .detail-label{color:#f1f3f5!important;font-size:12.5px!important}

body.dark-mode .detail-value{color:#f3f5f7!important;font-size:15px!important;font-weight:600!important}

body.dark-mode .prev-note{color:#aeb7c5!important}

body.dark-mode .employee-list{color:#f3f5f7!important;gap:8px!important}

body.dark-mode .employee{color:#f3f5f7!important}

body.dark-mode .employee-name{color:#f3f5f7!important;font-size:13.5px!important}

body.dark-mode .employee-number{background:#a5212b!important;width:8px!important;height:8px!important}

body.dark-mode .you-badge,body.dark-mode .team-you{background:#382326!important;color:#f07a80!important}

body.dark-mode .description-area{border-color:#3a3f47!important;padding-top:20px!important}

body.dark-mode .description-title{color:#f3f5f7!important;font-size:13px!important}

body.dark-mode .description-text{color:#dce1e8!important;font-size:13.5px!important}

body.dark-mode .repair-note{background:#292329!important;border-color:#4a3438!important}

body.dark-mode .repair-note-title{color:#e36b71!important}

body.dark-mode .repair-note-text{color:#d8dee7!important}

body.dark-mode .section-heading{color:#f1f3f5!important}

body.dark-mode .section-desc,body.dark-mode .evidence-photo-hint{color:#929baa!important}

body.dark-mode .evidence{background:#1d2025!important;border-color:#343942!important}

body.dark-mode .evidence-person{color:#f1f3f5!important}

body.dark-mode .evidence-date{color:#929baa!important}

body.dark-mode .evidence-photo-item{background:#20242a!important;border-color:#343942!important}

body.dark-mode .evidence-note{background:#20242a!important;border-color:#343942!important}

body.dark-mode .evidence-note-title{color:#f1f3f5!important}

body.dark-mode .evidence-note-text{color:#cbd2dc!important}

body.dark-mode .empty-evidence{background:#20242a!important;color:#cbd2dc!important;border-color:#343942!important}

body.dark-mode .btn-alihkan{background:#292e36!important;color:#d8dee7!important;border-color:#3b424d!important;box-shadow:none!important}

body.dark-mode .btn-alihkan:hover{background:#323842!important;border-color:#4d5766!important;color:#fff!important}

body.dark-mode .btn-keluar{background:#2f2820!important;color:#f0b35a!important;border-color:#5a4724!important;box-shadow:none!important}

body.dark-mode .btn-keluar:hover{background:#3a2f1c!important;border-color:#7a6030!important}

body.dark-mode .btn-gabung{background:#1b3226!important;color:#4fc183!important;border-color:#2c5a40!important;box-shadow:none!important}

body.dark-mode .btn-gabung:hover{background:#21402f!important;border-color:#3a7a56!important}

body.dark-mode .action-info{color:#aeb7c5!important}

body.dark-mode .action-info.success{color:#4fc183!important}

body.dark-mode .footer-note{color:#747e8c!important}

/* dark mode: riwayat keluar / gabung */

body.dark-mode .leave-badge{background:#2f2820!important;color:#f0b35a!important}

body.dark-mode .leave-history{border-left-color:#3b424d!important}

body.dark-mode .leave-item{background:#20242a!important;border-color:#343942!important}

body.dark-mode .leave-time{color:#f3f5f7!important}

body.dark-mode .leave-time small{color:#929baa!important}

body.dark-mode .leave-arrow{color:#747e8c!important}

body.dark-mode .leave-duration{background:#1b3226!important;color:#4fc183!important}

body.dark-mode .leave-duration.pending{background:#2f2820!important;color:#f0b35a!important}

/* dark mode di HP: samakan ukuran font dengan mode terang */

@media(max-width:600px){

    body.dark-mode .detail-label{font-size:14px!important;font-weight:600!important;color:#ffffff!important}

    body.dark-mode .detail-value{font-size:15px!important}

    body.dark-mode .detail-item{padding:11px 0!important}

}

</style>
</head>

<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<main class="main">

<?php include "../includes/karyawan/topbar.php"; ?>

<div class="content">

    <!-- JUDUL -->
    <div class="page-title">
        <div>
            <h2>Detail Pekerjaan</h2>
            <p>Informasi pekerjaan yang diberikan kepada Anda.</p>
        </div>

        <a href="tugas.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5"></path>
                <path d="M12 19l-7-7 7-7"></path>
            </svg>
            Kembali
        </a>
    </div>

    <!-- DETAIL PEKERJAAN -->
    <div class="card detail-card">

        <div class="job-top">
            <div class="job-name"><?= e($pekerjaan["nama_pekerjaan"] ?? "-"); ?></div>
            <div class="job-status <?= $status_class; ?>"><?= e($status_tampil); ?></div>
        </div>

        <!-- INFORMASI PERALIHAN (tampil selama pekerjaan pernah dialihkan) -->
        <?php foreach ($pengalihan_list as $index => $p): ?>
            <div class="transfer-info">
                <div class="transfer-info-title">
                    Informasi Peralihan Pekerjaan<?= count($pengalihan_list) > 1 ? " (" . ($index + 1) . ")" : ""; ?>
                </div>

                <p>
                    Pekerjaan ini dialihkan oleh <strong><?= e($p["nama_pengaju"] ?? "-"); ?></strong>
                    kepada <strong><?= e($p["nama_pengganti"] ?? "-"); ?></strong>.
                </p>

                <?php if (!empty($p["tanggal_pengajuan"])): ?>
                    <p>Tanggal peralihan: <?= formatTanggalWaktu($p["tanggal_pengajuan"]); ?></p>
                <?php endif; ?>

                <?php if (!empty($p["deskripsi_pengalihan"])): ?>
                    <p>Alasan peralihan: <?= nl2br(e($p["deskripsi_pengalihan"])); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="detail-grid">

            <div class="detail-item">
                <div class="detail-label">Jenis Pekerjaan</div>
                <div class="detail-value"><?= !empty($pekerjaan["jenis_pekerjaan"]) ? e($pekerjaan["jenis_pekerjaan"]) : "-"; ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label"><?= e($label_uraian); ?></div>
                <div class="detail-value"><?= $isi_uraian !== "" ? nl2br(e($isi_uraian)) : "-"; ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Tipe Order</div>
                <div class="detail-value"><?= !empty($pekerjaan["tipe_order"]) ? e($pekerjaan["tipe_order"]) : "-"; ?></div>
            </div>

            <?php if (($pekerjaan["tipe_order"] ?? "") == "Order Perbaikan" && !empty($pekerjaan["nomor_order"])): ?>
            <div class="detail-item">
                <div class="detail-label">Nomor Order</div>
                <div class="detail-value"><?= e($pekerjaan["nomor_order"]); ?></div>
            </div>
            <?php endif; ?>

            <div class="detail-item">
                <div class="detail-label">Divisi</div>
                <div class="detail-value"><?= e($nama_divisi); ?></div>
            </div>

            <?php if ($is_tugas_tim): ?>

            <div class="detail-item">
                <div class="detail-label">Anggota Tim</div>
                <div class="employee-list">

                    <?php foreach ($data_tim as $anggota): ?>
                        <?php $id_anggota = (int) $anggota["id_user"]; ?>
                        <div class="employee">
                            <span class="employee-number"></span>
                            <span class="employee-name"><?= e($anggota["nama"]); ?></span>

                            <?php if (isset($peta_sebelumnya[$id_anggota])): ?>
                                <span class="prev-note">(sebelumnya <?= e($peta_sebelumnya[$id_anggota]); ?>)</span>
                            <?php endif; ?>

                            <?php if ($id_anggota === $id_karyawan): ?>
                                <span class="team-you">Anda</span>
                            <?php endif; ?>

                            <?= tampilLogKeluar($log_keluar[$id_anggota] ?? []); ?>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>

            <?php else: ?>

            <div class="detail-item">
                <div class="detail-label">Penanggung Jawab</div>

                <div class="detail-value person-value">
                    <span><?= e($nama_pj_tampil); ?></span>

                    <?php if (isset($peta_sebelumnya[$id_pj_tampil])): ?>
                        <span class="prev-note">(sebelumnya <?= e($peta_sebelumnya[$id_pj_tampil]); ?>)</span>
                    <?php endif; ?>

                    <?php if ($id_pj_tampil === $id_karyawan): ?>
                        <span class="you-badge">Anda</span>
                    <?php endif; ?>

                    <?= tampilLogKeluar($log_keluar[$id_pj_tampil] ?? []); ?>
                </div>
            </div>

            <?php endif; ?>

            <div class="detail-item">
                <div class="detail-label">Tanggal Mulai</div>
                <div class="detail-value"><?= formatTanggalWaktu($pekerjaan["tanggal_mulai"] ?? ""); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Target Selesai</div>
                <div class="detail-value"><?= formatTanggalWaktu($pekerjaan["target_selesai"] ?? ""); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">Lokasi</div>
                <div class="detail-value"><?= !empty($pekerjaan["lokasi"]) ? e($pekerjaan["lokasi"]) : "-"; ?></div>
            </div>

        </div>

        <!-- FOTO DARI ADMIN -->
        <?php if ($jumlah_foto_admin > 0): ?>
            <div class="description-area">
                <div class="description-title">Foto dari Admin</div>

                <?php if (!empty($foto_admin)): ?>
                    <div class="admin-photos">
                        <?php foreach ($foto_admin as $url_foto): ?>
                            <div class="evidence-photo-item">
                                <img src="<?= e($url_foto); ?>" alt="Foto pekerjaan" onclick="bukaFotoBukti(this.src)">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="evidence-photo-hint">Klik foto untuk melihat ukuran penuh.</div>
                <?php else: ?>
                    <div class="empty-evidence">Foto pekerjaan tercatat di database, tetapi file fotonya tidak ditemukan di folder uploads.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($catatan_perbaikan)): ?>
            <div class="repair-note">
                <div class="repair-note-title">Catatan Perbaikan</div>
                <div class="repair-note-text"><?= nl2br(e($catatan_perbaikan)); ?></div>
            </div>
        <?php endif; ?>

    </div>

    <!-- BUKTI PEKERJAAN -->
    <div class="card detail-card">

        <h3 class="section-heading">Bukti Pekerjaan</h3>

        <p class="section-desc">Foto sebelum dan sesudah pengerjaan yang dikirim karyawan, termasuk dari karyawan sebelumnya.</p>

        <?php if (!empty($bukti_list)): ?>

            <div class="evidence-list">

                <?php foreach ($bukti_list as $bukti): ?>

                <div class="evidence">

                    <div class="evidence-head">
                        <div class="evidence-person">
                            <span><?= e($bukti["nama_pengirim_bukti"] ?? "Karyawan"); ?></span>

                            <?php if ((int) ($bukti["id_user"] ?? 0) === $id_karyawan): ?>
                                <span class="you-badge">Anda</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($bukti["tanggal_upload"])): ?>
                            <div class="evidence-date"><?= formatTanggalWaktu($bukti["tanggal_upload"]); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="evidence-body">

                        <div>
                            <?php if (!empty($bukti["foto_sebelum_list"])): ?>

                                <div class="evidence-note-title">Foto Sebelum</div>

                                <div class="evidence-photos" style="margin-bottom:12px">
                                    <?php foreach ($bukti["foto_sebelum_list"] as $foto_sebelum): ?>
                                        <div class="evidence-photo-item">
                                            <img src="../uploads/bukti/<?= e($foto_sebelum); ?>" alt="Foto sebelum" onclick="bukaFotoBukti(this.src)">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($bukti["foto_list"])): ?>

                                <div class="evidence-note-title">Foto Sesudah</div>

                                <div class="evidence-photos">
                                    <?php foreach ($bukti["foto_list"] as $foto_bukti): ?>
                                        <div class="evidence-photo-item">
                                            <img src="../uploads/bukti/<?= e($foto_bukti); ?>" alt="Bukti pekerjaan" onclick="bukaFotoBukti(this.src)">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="evidence-photo-hint">Klik foto untuk melihat ukuran penuh.</div>

                            <?php else: ?>

                                <div class="empty-evidence">
                                    <?php if ($bukti["foto_sudah_terhapus"]): ?>
                                        Foto bukti sudah dihapus otomatis oleh sistem (1 hari setelah pekerjaan dikonfirmasi selesai).
                                    <?php else: ?>
                                        <?= $bukti["jumlah_foto_tercatat"] > 0 ? "Foto bukti tidak tersedia." : "Tidak ada foto bukti pekerjaan."; ?>
                                    <?php endif; ?>
                                </div>

                            <?php endif; ?>
                        </div>

                        <div class="evidence-note">
                            <div class="evidence-note-title">Catatan Karyawan</div>

                            <div class="evidence-note-text">
                                <?= !empty($bukti["catatan_karyawan"]) ? nl2br(e($bukti["catatan_karyawan"])) : "Tidak ada catatan."; ?>
                            </div>
                        </div>

                    </div>

                </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-evidence">Belum ada bukti pekerjaan yang diunggah.</div>

        <?php endif; ?>

    </div>

    <!-- AKSI -->
    <?php if ($ada_aksi): ?>

    <div class="card detail-card">

        <div class="action-area">

            <?php if ($bisa_keluar): ?>

                <form method="post" style="display:inline"
                      onsubmit="return confirm('<?= $saya_sedang_keluar ? "Catat bahwa Anda gabung lagi ke pekerjaan ini?" : "Catat bahwa Anda keluar dari pekerjaan ini?"; ?>');">
                    <input type="hidden" name="aksi_keluar" value="1">

                    <?php if ($saya_sedang_keluar): ?>
                        <button type="submit" class="btn-gabung">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                                <path d="M10 17l5-5-5-5"></path>
                                <path d="M15 12H3"></path>
                            </svg>
                            Gabung Lagi
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn-keluar">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <path d="M16 17l5-5-5-5"></path>
                                <path d="M21 12H9"></path>
                            </svg>
                            Keluar
                        </button>
                    <?php endif; ?>
                </form>

            <?php endif; ?>

            <?php if ($tampil_alih): ?>

                <a href="alih_tugas.php?id=<?= (int) $pekerjaan["id_pekerjaan"]; ?>" class="btn-alihkan">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 7h10"></path>
                        <path d="M13 3l4 4-4 4"></path>
                        <path d="M17 17H7"></path>
                        <path d="M11 13l-4 4 4 4"></path>
                    </svg>
                    Alihkan Pekerjaan
                </a>

            <?php endif; ?>

            <?php if ($tampil_kirim): ?>

                <a href="kirim_bukti.php?id=<?= (int) $pekerjaan["id_pekerjaan"]; ?>" class="btn-kirim">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 16V4"></path>
                        <path d="M8 8l4-4 4 4"></path>
                        <path d="M5 13v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"></path>
                    </svg>
                    Kirim Bukti
                </a>

            <?php elseif ($tampil_edit): ?>

                <a href="kirim_bukti.php?id=<?= (int) $pekerjaan["id_pekerjaan"]; ?>" class="btn-kirim">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>
                    </svg>
                    Edit Bukti
                </a>

            <?php elseif ($tampil_selesai): ?>

                <span class="action-info success">Pekerjaan telah selesai.</span>

            <?php endif; ?>

        </div>

    </div>

    <?php endif; ?>

    <div class="footer-note">&copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.</div>

</div>

</main>

<div class="foto-modal" id="fotoModal" onclick="tutupFotoBukti(event)">
    <button type="button" class="close-modal" onclick="tutupFotoBukti(event)">&times;</button>
    <img id="fotoModalImage" src="" alt="Foto Ukuran Penuh">
</div>

<script>
function bukaFotoBukti(src){
    document.getElementById("fotoModalImage").src = src;
    document.getElementById("fotoModal").classList.add("active");
    document.body.style.overflow = "hidden";
}

function tutupFotoBukti(event){
    if (event && event.target && event.target.id === "fotoModalImage") return;
    document.getElementById("fotoModal").classList.remove("active");
    document.getElementById("fotoModalImage").src = "";
    document.body.style.overflow = "";
}

document.addEventListener("keydown", function(event){
    if (event.key === "Escape") tutupFotoBukti(null);
});
</script>

</body>
</html>
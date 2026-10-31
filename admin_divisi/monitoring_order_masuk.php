<?php
/* =========================================================
   MONITORING - ORDER MASUK
   Order yang masuk dari pengorder (tabel order_perbaikan).
   Halaman ini berdiri sendiri (tidak memakai file monitoring lain).
========================================================= */
include "../config/session.php";
if (!isset($_SESSION["id_user"]) || ($_SESSION["role"] ?? "") !== "Admin") {
    header("Location: ../index.php");
    exit;
}
include "../config/koneksi.php";

/* file ini opsional: kalau belum ada, halaman tetap jalan (tanpa error) */
if (is_file(__DIR__ . "/../config/siapkan_order.php")) {
    include __DIR__ . "/../config/siapkan_order.php";
}

$id_admin = intval($_SESSION["id_user"]);
$admin = mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT * FROM users WHERE id_user='$id_admin' LIMIT 1"
));
if (!$admin) {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$id_divisi  = intval($admin["id_divisi"]);
$nama_admin = $admin["nama"] ?? "-";
$divisi = mysqli_fetch_assoc(mysqli_query(
    $koneksi,
    "SELECT * FROM divisi WHERE id_divisi='$id_divisi' LIMIT 1"
));
$nama_divisi = $divisi["nama_divisi"] ?? "-";

$pesan_sukses = "";
$pesan_error  = "";
$id_detail    = intval($_GET["id"] ?? 0);
$halaman      = "monitoring_order_masuk.php";

/* Individu (1 pekerja) / Tim (lebih dari 1 pekerja) */
$jenis = $_GET["jenis"] ?? "Individu";
if (!in_array($jenis, ["Individu", "Tim"], true)) {
    $jenis = "Individu";
}

/* Kirim notifikasi ke satu user */
function kirim_notif($koneksi, $id_user, $pesan)
{
    $id_user = (int) $id_user;
    if ($id_user <= 0) {
        return;
    }
    $st = $koneksi->prepare(
        "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal)
         VALUES (?, ?, 'Belum Dibaca', NOW())"
    );
    $st->bind_param("is", $id_user, $pesan);
    $st->execute();
}

/* =========================================================
   PROSES AKSI
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $aksi = $_POST["aksi"] ?? "";

    /* ---------------------------------------------------------
       ISI / UBAH NOMOR ORDER
    --------------------------------------------------------- */
    if ($aksi === "order_nomor") {

        $id_order_nomor = intval($_POST["id_order"] ?? 0);
        $nomor_baru     = trim($_POST["nomor_order"] ?? "");
        $id_detail      = $id_order_nomor;

        $st = $koneksi->prepare(
            "SELECT id_order FROM order_perbaikan WHERE id_order = ? AND id_divisi = ? LIMIT 1"
        );
        $st->bind_param("ii", $id_order_nomor, $id_divisi);
        $st->execute();

        if (!$st->get_result()->fetch_assoc()) {

            $pesan_error = "Order tidak ditemukan atau bukan bagian dari divisi Anda.";

        } elseif ($nomor_baru === "") {

            $pesan_error = "Nomor order tidak boleh kosong.";

        } elseif (mb_strlen($nomor_baru) > 50) {

            $pesan_error = "Nomor order maksimal 50 karakter.";

        } else {

            $st = $koneksi->prepare(
                "SELECT id_order FROM order_perbaikan WHERE nomor_order = ? AND id_order <> ? LIMIT 1"
            );
            $st->bind_param("si", $nomor_baru, $id_order_nomor);
            $st->execute();

            if ($st->get_result()->fetch_assoc()) {

                $pesan_error = "Nomor order sudah dipakai order lain.";

            } else {

                $st = $koneksi->prepare(
                    "UPDATE order_perbaikan SET nomor_order = ? WHERE id_order = ? AND id_divisi = ?"
                );
                $st->bind_param("sii", $nomor_baru, $id_order_nomor, $id_divisi);

                if ($st->execute()) {
                    $pesan_sukses = "Nomor order berhasil disimpan.";
                } else {
                    $pesan_error = "Gagal menyimpan nomor order.";
                }
            }
        }

    /* ---------------------------------------------------------
       KONFIRMASI SELESAI / PERLU PERBAIKAN
    --------------------------------------------------------- */
    } elseif ($aksi === "order_konfirmasi" || $aksi === "order_perbaikan") {

        $id_order_proses = intval($_POST["id_order"] ?? 0);

        $st = $koneksi->prepare(
            "SELECT * FROM order_perbaikan WHERE id_order = ? AND id_divisi = ? LIMIT 1"
        );
        $st->bind_param("ii", $id_order_proses, $id_divisi);
        $st->execute();
        $order_proses = $st->get_result()->fetch_assoc();

        if (!$order_proses) {

            $pesan_error = "Order tidak ditemukan atau bukan bagian dari divisi Anda.";

        } elseif (!in_array($order_proses["status"], ["Menunggu Konfirmasi", "Perlu Perbaikan"], true)) {

            $pesan_error = "Order tidak dapat diubah pada status ini.";

        } elseif ($aksi === "order_konfirmasi" && trim($order_proses["nomor_order"] ?? "") === "") {

            $pesan_error = "Isi nomor order terlebih dahulu sebelum mengonfirmasi order selesai.";
            $id_detail   = $id_order_proses;

        } elseif ($aksi === "order_konfirmasi") {

            $st = $koneksi->prepare(
                "UPDATE order_perbaikan
                 SET status = 'Selesai', tanggal_selesai = NOW()
                 WHERE id_order = ? AND id_divisi = ?
                   AND status IN ('Menunggu Konfirmasi', 'Perlu Perbaikan')"
            );
            $st->bind_param("ii", $id_order_proses, $id_divisi);
            $st->execute();

            if ($st->affected_rows > 0) {

                $label = trim(($order_proses["nomor_order"] ?? "") . " (" . $order_proses["judul"] . ")");

                kirim_notif(
                    $koneksi,
                    $order_proses["id_karyawan"],
                    "Order perbaikan $label telah dikonfirmasi selesai oleh Admin Divisi."
                );
                kirim_notif(
                    $koneksi,
                    $order_proses["id_pemesan"],
                    "Order perbaikan $label yang Anda ajukan telah selesai dikerjakan."
                );

                $pesan_sukses = "Order berhasil dikonfirmasi sebagai selesai.";
                $id_detail    = $id_order_proses;

            } else {
                $pesan_error = "Gagal mengubah status order.";
            }

        } else { /* order_perbaikan */

            $catatan = trim($_POST["catatan_perbaikan"] ?? "");

            if ($catatan === "") {

                $pesan_error = "Catatan perbaikan wajib diisi.";

            } else {

                $st = $koneksi->prepare(
                    "SELECT id_bukti FROM bukti_order
                     WHERE id_order = ? ORDER BY id_bukti DESC LIMIT 1"
                );
                $st->bind_param("i", $id_order_proses);
                $st->execute();
                $bukti_terakhir = $st->get_result()->fetch_assoc();

                if (!$bukti_terakhir) {

                    $pesan_error = "Bukti order tidak ditemukan.";

                } else {

                    $id_bukti_order = (int) $bukti_terakhir["id_bukti"];

                    $st = $koneksi->prepare("UPDATE bukti_order SET catatan_admin = ? WHERE id_bukti = ?");
                    $st->bind_param("si", $catatan, $id_bukti_order);
                    $ok_bukti = $st->execute();

                    $st = $koneksi->prepare(
                        "UPDATE order_perbaikan
                         SET status = 'Perlu Perbaikan'
                         WHERE id_order = ? AND id_divisi = ?
                           AND status IN ('Menunggu Konfirmasi', 'Perlu Perbaikan')"
                    );
                    $st->bind_param("ii", $id_order_proses, $id_divisi);
                    $ok_order = $st->execute();

                    if ($ok_bukti && $ok_order) {

                        $label = trim(($order_proses["nomor_order"] ?? "") . " (" . $order_proses["judul"] . ")");

                        kirim_notif(
                            $koneksi,
                            $order_proses["id_karyawan"],
                            "Order perbaikan $label perlu diperbaiki. Silakan lihat catatan Admin Divisi."
                        );

                        $pesan_sukses = "Order dikembalikan untuk diperbaiki.";
                        $id_detail    = $id_order_proses;

                    } else {
                        $pesan_error = "Gagal menyimpan catatan perbaikan.";
                    }
                }
            }
        }
    }
}

/* =========================================================
   FILTER & DAFTAR
========================================================= */
$filter_status = $_GET["status"] ?? "menunggu";
$keyword       = trim($_GET["keyword"] ?? "");
$keyword_sql   = mysqli_real_escape_string($koneksi, $keyword);

if (!in_array($filter_status, ["menunggu", "perbaikan", "berjalan", "selesai", "semua"], true)) {
    $filter_status = "menunggu";
}

function hitung($koneksi, $sql)
{
    $q = mysqli_query($koneksi, $sql);
    return $q ? (int)(mysqli_fetch_assoc($q)["total"] ?? 0) : 0;
}

/* 1 pekerja = Individu, lebih dari 1 pekerja = Tim (dari tabel order_pekerja) */
$jumlah_pekerja_order_sql = "(SELECT COUNT(DISTINCT op.id_user) FROM order_pekerja op WHERE op.id_order=o.id_order)";
$filter_jenis_order_sql   = $jenis === "Tim"
    ? " AND $jumlah_pekerja_order_sql > 1"
    : " AND $jumlah_pekerja_order_sql <= 1";

$filter_status_sql = "";
if ($filter_status === "menunggu") {
    $filter_status_sql = " AND o.status='Menunggu Konfirmasi'";
} elseif ($filter_status === "perbaikan") {
    $filter_status_sql = " AND o.status='Perlu Perbaikan'";
} elseif ($filter_status === "berjalan") {
    $filter_status_sql = " AND o.status IN ('Menunggu','Diproses')";
} elseif ($filter_status === "selesai") {
    $filter_status_sql = " AND o.status='Selesai'";
}

$filter_keyword_sql = "";
if ($keyword !== "") {
    $filter_keyword_sql = " AND (o.judul LIKE '%$keyword_sql%'
        OR o.deskripsi LIKE '%$keyword_sql%'
        OR o.nomor_order LIKE '%$keyword_sql%'
        OR o.lokasi LIKE '%$keyword_sql%'
        OR uk.nama LIKE '%$keyword_sql%'
        OR up.nama LIKE '%$keyword_sql%'
        OR da.nama_divisi LIKE '%$keyword_sql%')";
}

$query_monitoring = mysqli_query(
    $koneksi,
    "SELECT o.*, uk.nama AS nama_karyawan, up.nama AS nama_pelapor,
            COALESCE(NULLIF(up.departemen, ''), da.nama_divisi) AS divisi_asal,
            $jumlah_pekerja_order_sql AS jumlah_pekerja,
            (SELECT MAX(bo2.tanggal_upload) FROM bukti_order bo2 WHERE bo2.id_order=o.id_order) AS jam_bukti_terakhir,
            COALESCE(
                (SELECT GROUP_CONCAT(DISTINCT u_o.nama ORDER BY u_o.nama ASC SEPARATOR '|||')
                 FROM order_pekerja op2
                 INNER JOIN users u_o ON u_o.id_user=op2.id_user
                 WHERE op2.id_order=o.id_order),
                uk.nama
            ) AS nama_pekerja_order
     FROM order_perbaikan o
     LEFT JOIN users uk ON o.id_karyawan = uk.id_user
     LEFT JOIN users up ON o.id_pemesan = up.id_user
     LEFT JOIN divisi da ON o.id_divisi_asal = da.id_divisi
     WHERE o.id_divisi='$id_divisi' $filter_status_sql $filter_jenis_order_sql $filter_keyword_sql
     ORDER BY o.id_order DESC"
);
$total_baris = $query_monitoring ? mysqli_num_rows($query_monitoring) : 0;

/* hitungan status mengikuti jenis (Individu / Tim) yang dipilih */
$count_menunggu  = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi'$filter_jenis_order_sql AND o.status='Menunggu Konfirmasi'");
$count_perbaikan = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi'$filter_jenis_order_sql AND o.status='Perlu Perbaikan'");
$count_berjalan  = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi'$filter_jenis_order_sql AND o.status IN ('Menunggu','Diproses')");
$count_selesai   = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi'$filter_jenis_order_sql AND o.status='Selesai'");
$count_semua     = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi'$filter_jenis_order_sql");

/* hitungan Individu / Tim tidak dipengaruhi status */
$count_individu = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi' AND $jumlah_pekerja_order_sql <= 1");
$count_tim      = hitung($koneksi, "SELECT COUNT(*) AS total FROM order_perbaikan o WHERE o.id_divisi='$id_divisi' AND $jumlah_pekerja_order_sql > 1");

/* =========================================================
   DETAIL ORDER
========================================================= */
$order_detail         = null;
$bukti_order_list     = [];
$daftar_pekerja_order = [];

if ($id_detail > 0) {

    $st = $koneksi->prepare(
        "SELECT o.*, uk.nama AS nama_karyawan, up.nama AS nama_pelapor,
                COALESCE(NULLIF(up.departemen, ''), da.nama_divisi) AS divisi_asal, dt.nama_divisi AS divisi_tujuan
         FROM order_perbaikan o
         LEFT JOIN users uk ON o.id_karyawan = uk.id_user
         LEFT JOIN users up ON o.id_pemesan = up.id_user
         LEFT JOIN divisi da ON o.id_divisi_asal = da.id_divisi
         LEFT JOIN divisi dt ON o.id_divisi = dt.id_divisi
         WHERE o.id_order = ? AND o.id_divisi = ?
         LIMIT 1"
    );
    $st->bind_param("ii", $id_detail, $id_divisi);
    $st->execute();
    $order_detail = $st->get_result()->fetch_assoc();

    if (!$order_detail) {

        $pesan_error = "Detail order tidak ditemukan.";
        $id_detail   = 0;

    } else {

        /* bo.* sudah ikut mengambil kolom foto (sesudah) dan foto_sebelum */
        $st = $koneksi->prepare(
            "SELECT bo.*, u.nama AS nama_karyawan
             FROM bukti_order bo
             LEFT JOIN users u ON bo.id_user = u.id_user
             WHERE bo.id_order = ?
             ORDER BY bo.tanggal_upload ASC, bo.id_bukti ASC"
        );
        $st->bind_param("i", $id_detail);
        $st->execute();
        $hasil_bukti = $st->get_result();
        while ($r = $hasil_bukti->fetch_assoc()) {
            $bukti_order_list[] = $r;
        }

        $st = $koneksi->prepare(
            "SELECT u.nama
             FROM order_pekerja op
             INNER JOIN users u ON u.id_user = op.id_user
             WHERE op.id_order = ?
             ORDER BY op.id ASC"
        );
        $st->bind_param("i", $id_detail);
        $st->execute();
        $hasil_pekerja = $st->get_result();
        while ($r = $hasil_pekerja->fetch_assoc()) {
            $daftar_pekerja_order[] = $r["nama"];
        }
    }
}

/* =========================================================
   FUNGSI BANTU
========================================================= */
function e($value)
{
    return htmlspecialchars($value ?? "-", ENT_QUOTES, "UTF-8");
}

/* Cari file foto bukti di beberapa kemungkinan folder.
   Return URL kalau ketemu, string kosong kalau tidak. */
function cari_foto_bukti($nilai_db)
{
    $nilai_db = trim((string)$nilai_db);
    if ($nilai_db === "") {
        return "";
    }

    $root     = str_replace("\\", "/", dirname(__DIR__));
    $base_url = rtrim(str_replace("\\", "/", dirname(dirname($_SERVER["SCRIPT_NAME"]))), "/");
    $nama     = basename(str_replace("\\", "/", $nilai_db));

    /* 1) kalau di DB tersimpan path relatif (mis. uploads/bukti/a.jpg) */
    $relatif = ltrim(str_replace("\\", "/", $nilai_db), "/");
    if (strpos($relatif, "/") !== false && is_file("$root/$relatif")) {
        return $base_url . "/" . implode("/", array_map("rawurlencode", explode("/", $relatif)));
    }

    /* 2) cek folder-folder umum */
    $folder = [
        "uploads/bukti",
        "uploads/bukti_pekerjaan",
        "uploads/bukti_order",
        "uploads/foto",
        "uploads",
        "karyawan/uploads/bukti",
        "karyawan/uploads",
        "assets/uploads/bukti",
    ];
    foreach ($folder as $dir) {
        if (is_file("$root/$dir/$nama")) {
            return $base_url . "/$dir/" . rawurlencode($nama);
        }
    }

    /* 3) cadangan: cari rekursif di dalam folder uploads */
    $dir_uploads = "$root/uploads";
    if (is_dir($dir_uploads)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_uploads, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() === $nama) {
                $rel = ltrim(str_replace($root, "", str_replace("\\", "/", $file->getPathname())), "/");
                return $base_url . "/" . implode("/", array_map("rawurlencode", explode("/", $rel)));
            }
        }
    }

    return "";
}

/* pecah kolom foto (nama file dipisah koma) jadi daftar, maksimal 5 */
function daftar_foto_bukti($csv)
{
    return array_slice(array_values(array_filter(array_map(
        "trim",
        explode(",", (string) $csv)
    ))), 0, 5);
}

/* nama pekerja dipisah "|||" jadi satu baris teks, contoh: Andi, Budi */
function nama_satu_baris($nama)
{
    $list = array_filter(array_map('trim', explode('|||', (string) $nama)));
    return empty($list) ? "-" : implode(", ", $list);
}

/* hanya tanggal, contoh: 19 Okt 2026 */
function format_tgl_saja($t)
{
    return !empty($t) && strtotime($t) ? date("d M Y", strtotime($t)) : "-";
}

/* hanya jam, contoh: 13:00 */
function format_jam_saja($t)
{
    return !empty($t) && strtotime($t) ? date("H:i", strtotime($t)) : "-";
}

/* tanggal + jam satu baris, format sama seperti Laporan, contoh: 19-10-2026, 13:00 */
function format_tgl_jam($t)
{
    if (empty($t) || !strtotime($t)) {
        return "-";
    }
    return e(date("d-m-Y, H:i", strtotime($t)));
}

/* status order yang belum diambil ditampilkan sebagai "Belum Diambil" */
function status_order_label($status)
{
    return $status === "Menunggu" ? "Belum Diambil" : $status;
}

function format_tgl_order($t)
{
    return !empty($t) ? date("d M Y, H:i", strtotime($t)) : "-";
}

function format_durasi_order($mulai, $akhir)
{
    if (empty($mulai) || empty($akhir)) {
        return "-";
    }
    $menit = max(0, (int) round((strtotime($akhir) - strtotime($mulai)) / 60));
    $jam   = intdiv($menit, 60);
    $sisa  = $menit % 60;
    return $jam > 0 ? $jam . " jam " . $sisa . " menit" : $sisa . " menit";
}

function status_class($status)
{
    return match ($status) {
        "Selesai"             => "badge-selesai",
        "Diproses"            => "badge-sedang",
        "Menunggu Konfirmasi" => "badge-menunggu",
        "Perlu Perbaikan"     => "badge-perbaikan",
        default               => "badge-belum"
    };
}

/* =========================================================
   NOTIFIKASI (dipakai topbar)
========================================================= */
$jumlah_notifikasi = 0;
$notifikasi_list   = [];

$q_jumlah = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total FROM notifikasi
     WHERE id_user='$id_admin' AND status_baca='Belum Dibaca'"
);
if ($q_jumlah) {
    $r = mysqli_fetch_assoc($q_jumlah);
    $jumlah_notifikasi = intval($r["total"]);
}

$q_notif = mysqli_query(
    $koneksi,
    "SELECT id_notifikasi, pesan, status_baca, tanggal
     FROM notifikasi
     WHERE id_user='$id_admin'
     ORDER BY tanggal DESC
     LIMIT 5"
);
if ($q_notif) {
    while ($r = mysqli_fetch_assoc($q_notif)) {
        $notifikasi_list[] = $r;
    }
}

/* tombol Kembali: ke tab status & jenis sesuai order saat ini */
$peta_status = [
    "Menunggu Konfirmasi" => "menunggu",
    "Perlu Perbaikan"     => "perbaikan",
    "Selesai"             => "selesai",
    "Diproses"            => "berjalan",
    "Menunggu"            => "berjalan"
];

if ($order_detail) {
    $status_kembali = $peta_status[$order_detail["status"]] ?? "semua";
    $jenis_kembali  = count($daftar_pekerja_order) > 1 ? "Tim" : "Individu";
} else {
    $status_kembali = $filter_status;
    $jenis_kembali  = $jenis;
}

/* foto kerusakan dari pelapor */
$url_foto_kerusakan = "";
if ($order_detail && !empty($order_detail["foto"])) {
    $nama_foto_order = basename($order_detail["foto"]);
    if (is_file(__DIR__ . "/../uploads/order/" . $nama_foto_order)) {
        $url_foto_kerusakan = "../uploads/order/" . rawurlencode($nama_foto_order);
    }
}

/* data untuk tab status & ikon */
$qs_keyword = $keyword !== "" ? "&keyword=" . urlencode($keyword) : "";

$ikon_individu = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg>';
$ikon_tim      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-1a4 4 0 0 0-3-3.87M16 4.13a4 4 0 0 1 0 7.75"/></svg>';

$daftar_status = [
    "menunggu"  => ["Menunggu konfirmasi", $count_menunggu, "Menunggu"],
    "perbaikan" => ["Perlu perbaikan", $count_perbaikan, "Perbaikan"],
    "berjalan"  => ["Berjalan", $count_berjalan, "Berjalan"],
    "selesai"   => ["Selesai", $count_selesai, "Selesai"],
    "semua"     => ["Semua", $count_semua, "Semua"],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="format-detection" content="telephone=no">
<title>Monitoring Order Masuk - SIMO</title>
<style>

/* =========================================================
   TOKEN WARNA (terang & gelap)
========================================================= */
body {
    --bg: #ffffff;
    --surface: #ffffff;
    --surface-2: #f7f8fa;
    --line: #e8ebf0;
    --line-soft: #f1f3f6;
    --text: #1a2230;
    --text-2: #4b576b;
    --text-3: #7b8699;

    --brand: #b51f2a;
    --brand-hover: #9a1a24;
    --brand-ink: #b51f2a;
    --brand-soft: #fbeced;
    --brand-ring: rgba(181, 31, 42, .18);

    --ok: #1f8a4f;
    --ok-hover: #197a45;

    --b-belum-bg: #eef0f3;     --b-belum: #5b6679;
    --b-sedang-bg: #e8f0ff;    --b-sedang: #2f5fb3;
    --b-menunggu-bg: #fff3d6;  --b-menunggu: #94600a;
    --b-perbaikan-bg: #fde8e8; --b-perbaikan: #b93232;
    --b-peralihan-bg: #efe9ff; --b-peralihan: #6a4bb0;
    --b-selesai-bg: #e5f6ec;   --b-selesai: #17794a;

    --info-bg: #f3f7ff;
    --info-line: #cbdaf5;
    --info-ink: #365b9d;

    --shadow-modal: 0 24px 60px rgba(15, 23, 36, .22);
}

body.dark-mode {
    --bg: #0f141b;
    --surface: #151b23;
    --surface-2: #1a212b;
    --line: #2c3542;
    --line-soft: #232b36;
    --text: #edf2f7;
    --text-2: #cbd2dc;
    --text-3: #8f9bac;

    --brand-ink: #f07a80;
    --brand-soft: #382326;
    --brand-ring: rgba(240, 122, 128, .25);

    --b-belum-bg: #2b3039;     --b-belum: #c5cbd3;
    --b-sedang-bg: #1d3158;    --b-sedang: #8eb5ff;
    --b-menunggu-bg: #3c3924;  --b-menunggu: #f2d77d;
    --b-perbaikan-bg: #4b2d32; --b-perbaikan: #ff8b92;
    --b-peralihan-bg: #2f2748; --b-peralihan: #b9a2f0;
    --b-selesai-bg: #183d2a;   --b-selesai: #72d89a;

    --info-bg: #202a39;
    --info-line: #3b4b63;
    --info-ink: #a9c4ff;

    --shadow-modal: 0 24px 60px rgba(0, 0, 0, .5);
}

html:has(body.dark-mode) { background: #0f141b; }

/* =========================================================
   DASAR
========================================================= */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
}

body {
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
}

a { text-decoration: none; color: inherit; }

:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

.main {
    margin-left: 260px;
    width: calc(100% - 260px);
    min-width: 0;
    min-height: 100vh;
}

.content {
    max-width: 1280px;
    margin: 0 auto;
    padding: 32px 36px 48px;
}

/* =========================================================
   JUDUL HALAMAN
========================================================= */
.page-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
}

.page-title h2 {
    font-size: 24px;
    font-weight: 700;
    letter-spacing: -.2px;
    line-height: 1.25;
}

.page-title p { margin-top: 3px; font-size: 14px; color: var(--text-3); }

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--surface);
    color: var(--text-2);
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}

.btn-back:hover { background: var(--surface-2); }
.btn-back svg { width: 15px; height: 15px; }

/* PESAN */
.alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    margin-bottom: 16px;
    border-radius: 10px;
    border: 1px solid;
    font-size: 13.5px;
    font-weight: 500;
}

.alert svg { width: 18px; height: 18px; flex-shrink: 0; }
.alert-success { background: var(--b-selesai-bg); color: var(--b-selesai); border-color: color-mix(in srgb, var(--b-selesai) 25%, transparent); }
.alert-error   { background: var(--b-perbaikan-bg); color: var(--b-perbaikan); border-color: color-mix(in srgb, var(--b-perbaikan) 25%, transparent); }

/* KARTU: datar, tanpa bayangan */
.card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 14px;
}

/* LENCANA STATUS */
.badge-belum     { background: var(--b-belum-bg);     color: var(--b-belum); }
.badge-sedang    { background: var(--b-sedang-bg);    color: var(--b-sedang); }
.badge-menunggu  { background: var(--b-menunggu-bg);  color: var(--b-menunggu); }
.badge-perbaikan { background: var(--b-perbaikan-bg); color: var(--b-perbaikan); }
.badge-peralihan { background: var(--b-peralihan-bg); color: var(--b-peralihan); }
.badge-selesai   { background: var(--b-selesai-bg);   color: var(--b-selesai); }

/* =========================================================
   DAFTAR MONITORING
========================================================= */
.list-card { overflow: hidden; }

.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px 20px;
    padding: 18px 20px 8px;
}

.filter-group { display: flex; flex-direction: column; gap: 6px; }

.status-section { background: var(--surface); }

/* Individu / Tim: pill */
.seg { display: inline-flex; gap: 8px; }

.seg a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 36px;
    padding: 0 14px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--surface);
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-2);
    white-space: nowrap;
    transition: background .15s, color .15s;
}

.seg a svg { width: 16px; height: 16px; }
.seg a:hover { background: var(--surface-2); color: var(--text); }
.seg a.active,
.seg a.active:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand-ink); }

.cnt {
    min-width: 22px;
    height: 20px;
    padding: 0 7px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    background: var(--line-soft);
    color: var(--text-3);
}

.seg a.active .cnt { background: var(--brand); color: #fff; }

/* PENCARIAN */
.search-box { position: relative; }
.list-header-right { margin-left: auto; }

.search-box svg {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    stroke: var(--text-3);
    pointer-events: none;
}

.search-box input {
    width: 320px;
    max-width: 100%;
    height: 40px;
    padding: 0 14px 0 38px;
    border: 1px solid transparent;
    border-radius: 999px;
    background: var(--surface-2);
    color: var(--text);
    font-size: 13.5px;
    outline: none;
    transition: border-color .15s, box-shadow .15s, background .15s;
}

.search-box input::placeholder { color: var(--text-3); }
.search-box input:focus { background: var(--surface); border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }

/* TAB STATUS */
.status-tabs {
    display: flex;
    gap: 26px;
    flex-wrap: wrap;
    padding: 0 20px;
    border-bottom: 1px solid var(--line);
}

.status-tabs a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 2px;
    margin-bottom: -1px;
    border-bottom: 2px solid transparent;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-3);
    transition: color .15s;
}

.lbl-short { display: none; }

.status-tabs a:hover { color: var(--text); }
.status-tabs a.active { color: var(--brand-ink); border-bottom-color: var(--brand); font-weight: 600; }
.status-tabs a.active .cnt { background: var(--brand-soft); color: var(--brand-ink); }

/* TABEL */
.table-wrapper {
    overflow-x: auto;
    margin: 18px 20px 8px;
    border: 1px solid var(--line);
    border-radius: 12px;
}

.tabel-pekerjaan { width: max-content; border-collapse: collapse; }
.tabel-pekerjaan:has(.empty-row) { width: 100%; }

.tabel-pekerjaan th {
    padding: 10px 14px;
    text-align: left;
    white-space: nowrap;
    background: var(--brand);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .3px;
    text-transform: none;
    border-bottom: 0;
}

.tabel-pekerjaan th:first-child { border-top-left-radius: 10px; }
.tabel-pekerjaan th:last-child  { border-top-right-radius: 10px; }

.tabel-pekerjaan td {
    padding: 8px 14px;
    vertical-align: middle;
    white-space: normal;
    border-bottom: 1px solid var(--line-soft);
    color: var(--text-2);
    font-size: 13px;
    line-height: 1.4;
}

.tabel-pekerjaan th:first-child,
.tabel-pekerjaan td:first-child { padding-left: 20px; }
.tabel-pekerjaan th:last-child,
.tabel-pekerjaan td:last-child { padding-right: 20px; }

.tabel-pekerjaan td.col-no { color: var(--text-3); width: 40px; }
.tabel-pekerjaan td a { color: inherit; text-decoration: none; }
.tabel-pekerjaan td.col-nama { color: var(--text); font-weight: 600; }
.ket-teks { max-width: 180px; overflow-wrap: anywhere; }
.tabel-pekerjaan td[data-label="Status"],
.tabel-pekerjaan td[data-label="Aksi"],
.tabel-pekerjaan td[data-label="No. Order"],
.tabel-pekerjaan td[data-label="Tgl/Jam Dibuat"],
.tabel-pekerjaan td[data-label="Diambil"],
.tabel-pekerjaan td[data-label="Selesai Dikerjakan"] { white-space: nowrap; }

/* teks pendek dipotong satu baris supaya tabel tidak terlalu lebar */
.teks-potong {
    max-width: 160px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* kolom Uraian: dipotong 2 baris supaya tabel tidak terlalu tinggi */
.uraian-teks {
    max-width: 240px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.5;
}

/* jumlah anggota (tab Tim) */
.jumlah-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    background: var(--brand-soft);
    color: var(--brand-ink);
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}

.tabel-pekerjaan tbody tr:last-child td { border-bottom: none; }
.tabel-pekerjaan tbody tr.row-klik { cursor: pointer; }
.tabel-pekerjaan tbody tr.row-klik:hover td { background: var(--surface-2); }
tr.row-klik:focus { outline: none; }
tr.row-klik:focus-visible td { background: var(--brand-soft); }

/* status pada tabel: kotak kecil rounded, sama seperti halaman Laporan */
.status-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}

.status-badge.badge-belum     { background: #eef0f3; color: #667085; }
.status-badge.badge-sedang    { background: #edf3ff; color: #3f65b5; }
.status-badge.badge-menunggu  { background: #fff5dc; color: #a46b00; }
.status-badge.badge-perbaikan { background: #fde5e5; color: #c94242; }
.status-badge.badge-peralihan { background: #efe9ff; color: #6a4bb0; }
.status-badge.badge-selesai   { background: #e9f8f0; color: #168353; }

body.dark-mode .status-badge.badge-belum     { background: #34383e; color: #c5cbd3; }
body.dark-mode .status-badge.badge-sedang    { background: #1d3158; color: #8eb5ff; }
body.dark-mode .status-badge.badge-menunggu  { background: #3c3924; color: #f2d77d; }
body.dark-mode .status-badge.badge-perbaikan { background: #4b2d32; color: #ff8b92; }
body.dark-mode .status-badge.badge-peralihan { background: #2f2748; color: #b9a2f0; }
body.dark-mode .status-badge.badge-selesai   { background: #183d2a; color: #72d89a; }

.empty-row { padding: 56px 20px !important; text-align: center; }

.empty-state { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.empty-state svg { width: 40px; height: 40px; margin-bottom: 8px; stroke: var(--text-3); opacity: .7; }
.empty-state strong { font-size: 14.5px; color: var(--text); }
.empty-state span { font-size: 13px; color: var(--text-3); }

.table-foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 20px;
    border-top: 1px solid var(--line-soft);
    font-size: 12.5px;
    color: var(--text-3);
}

.table-foot strong { color: var(--text-2); font-weight: 600; }

/* TOMBOL AKSI: ikon polos */
.aksi-group { display: flex; align-items: center; gap: 4px; }

.btn-aksi {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--text-3);
    cursor: pointer;
    flex-shrink: 0;
    transition: background .15s, color .15s;
}

.btn-aksi svg { width: 16px; height: 16px; stroke: currentColor; }
.btn-detail:hover { background: var(--brand-soft); color: var(--brand-ink); }
.btn-hapus:hover { background: var(--b-perbaikan-bg); color: var(--b-perbaikan); }

/* =========================================================
   DETAIL
========================================================= */
.detail-card { margin-top: 16px; padding: 26px 28px; }

.job-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--line-soft);
}

.job-name { font-size: 22px; font-weight: 700; color: var(--text); }

.job-status {
    flex-shrink: 0;
    padding: 6px 13px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.detail-grid { display: flex; flex-direction: column; margin-top: 4px; }

.detail-item {
    display: grid;
    grid-template-columns: 180px 1fr;
    align-items: start;
    column-gap: 18px;
    padding: 12px 0;
    border-bottom: 1px solid var(--line-soft);
}

.detail-label { font-size: 13px; color: var(--text-3); }
.detail-value { font-size: 14px; font-weight: 500; color: var(--text); }

.employee-list { display: flex; flex-direction: column; gap: 8px; }
.employee { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.employee-number {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--brand);
    flex-shrink: 0;
    font-size: 0;
}

.employee-name { font-size: 14px; color: var(--text); font-weight: 500; }

.description-area { padding-top: 18px; }
.description-title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.description-text  { font-size: 14px; color: var(--text-2); line-height: 1.6; }

.section-heading { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 3px; }
.section-desc    { font-size: 12.5px; color: var(--text-3); margin-bottom: 14px; }

/* NOMOR ORDER (form isi nomor) */
.nomor-form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 4px; }
.nomor-form input {
    flex: 1;
    min-width: 130px;
    padding: 7px 10px;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 13px;
    font-family: inherit;
    background: transparent;
    color: inherit;
}
.nomor-form input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }
.nomor-form button {
    padding: 7px 14px;
    border: 0;
    border-radius: 8px;
    background: var(--brand);
    color: #fff;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
}
.nomor-form button:hover { background: var(--brand-hover); }

/* BUKTI */
.evidence-list { display: flex; flex-direction: column; gap: 10px; }

.evidence {
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 12px;
    background: var(--surface);
}

.evidence-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 9px;
}

.evidence-person {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
}

.evidence-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 20px;
    background: var(--brand);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
}

.evidence-date { font-size: 12px; color: var(--text-3); }

.evidence-body {
    display: grid;
    grid-template-columns: 160px minmax(0, 1fr);
    gap: 12px;
}

.evidence-photos { display: flex; flex-direction: column; gap: 8px; }

/* kelompok foto: sebelum / sesudah */
.evidence-group + .evidence-group { margin-top: 14px; }
.evidence-group-title {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 6px;
}
.evidence-group .empty-evidence { padding: 12px 8px; }

.evidence-photo {
    display: block;
    width: 160px;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--line);
    cursor: pointer;
}

.evidence-note { background: var(--surface-2); border-radius: 10px; padding: 10px 12px; }
.evidence-note-title { font-size: 12px; color: var(--text-3); font-weight: 700; margin-bottom: 4px; }
.evidence-note-text  { font-size: 13px; color: var(--text-2); line-height: 1.55; }

.evidence-note-admin { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); }

.empty-evidence {
    padding: 18px;
    text-align: center;
    border: 1px dashed var(--line);
    border-radius: 10px;
    background: var(--surface-2);
    color: var(--text-3);
    font-size: 12.5px;
    line-height: 1.5;
}

/* AKSI KONFIRMASI */
.detail-header { padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid var(--line-soft); }
.detail-header h3 { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 3px; }
.detail-header p  { font-size: 12.5px; color: var(--text-3); }

.action-area { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.btn-confirm,
.btn-repair {
    min-height: 40px;
    padding: 11px 20px;
    border: 1px solid transparent;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1;
    color: #fff;
    cursor: pointer;
    transition: background .15s;
}

.btn-confirm { background: var(--ok); }
.btn-confirm:hover { background: var(--ok-hover); }
.btn-repair { background: var(--brand); }
.btn-repair:hover { background: var(--brand-hover); }

.action-note {
    width: 100%;
    min-height: 80px;
    margin-bottom: 8px;
    padding: 11px 12px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--surface);
    color: var(--text);
    resize: vertical;
    outline: none;
    font-size: 13px;
}

.action-note:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }
.action-note::placeholder { color: var(--text-3); }

.status-info {
    padding: 12px 14px;
    border: 1px dashed var(--line);
    border-radius: 10px;
    background: var(--surface-2);
    color: var(--text-2);
    font-size: 13px;
}

/* =========================================================
   MODAL
========================================================= */
.modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(18, 24, 34, .5);
    z-index: 10000;
}

.modal.show { display: flex; }

.modal-box {
    width: 400px;
    max-width: 100%;
    padding: 28px 26px 24px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    text-align: center;
    box-shadow: var(--shadow-modal);
}

.modal-box h3 { margin-bottom: 8px; font-size: 17px; font-weight: 700; color: var(--text); }

.modal-box p {
    margin: 0 auto 22px;
    max-width: 320px;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--text-3);
}

.modal-actions { display: flex; gap: 10px; }

.modal-btn {
    flex: 1;
    min-height: 42px;
    padding: 10px 16px;
    border-radius: 999px;
    border: 1px solid var(--line);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
}

.modal-cancel { background: var(--surface); color: var(--text-2); }
.modal-cancel:hover { background: var(--surface-2); }

.modal-ok { background: var(--ok); color: #fff; border-color: var(--ok); }
.modal-ok:hover { background: var(--ok-hover); border-color: var(--ok-hover); }

/* FOTO PENUH */
.foto-modal {
    display: none;
    position: fixed;
    z-index: 10001;
    inset: 0;
    background: rgba(0, 0, 0, .85);
    align-items: center;
    justify-content: center;
    padding: 30px;
}

.foto-modal.active { display: flex; }

.foto-modal img {
    max-width: 95%;
    max-height: 90vh;
    object-fit: contain;
    border-radius: 8px;
}

.close-modal {
    position: absolute;
    top: 20px;
    right: 28px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, .16);
    color: #fff;
    font-size: 26px;
    cursor: pointer;
}

.close-modal:hover { background: rgba(255, 255, 255, .28); }

.footer-note {
    text-align: center;
    padding: 28px 0 4px;
    color: var(--text-3);
    font-size: 12px;
}

/* =========================================================
   RESPONSIF
========================================================= */
@media (max-width: 800px) {
    .main { margin-left: 0; width: 100%; }
    .content { padding: 20px 16px 32px; }
    .page-title { align-items: flex-start; }

    /* ---------- DETAIL DI HP ---------- */
    .detail-card { padding: 18px 16px; }

    .job-top {
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-start;
        gap: 8px 12px;
    }
    .job-name { font-size: 19px; line-height: 1.3; word-break: break-word; min-width: 0; }
    .job-status { align-self: center; }

    .detail-item {
        grid-template-columns: 150px minmax(0, 1fr);
        column-gap: 12px;
        row-gap: 0;
        padding: 13px 0;
    }

    .detail-label {
        display: flex;
        justify-content: space-between;
        gap: 6px;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.4;
        color: var(--text);
    }

    .detail-label::after { content: ":"; flex-shrink: 0; }

    .detail-value,
    .employee-name {
        font-size: 15px;
        font-weight: 600;
        line-height: 1.4;
        word-break: break-word;
    }

    .employee { align-items: baseline; }

    .description-title { font-size: 13px; }
    .description-text  { font-size: 14px; }

    .evidence-body { grid-template-columns: 120px minmax(0, 1fr); gap: 10px; }
    .evidence-photo { width: 120px; height: 90px; }
    .empty-evidence { padding: 12px 8px; }
    .search-box, .search-box input, .list-header-right { width: 100%; }

    .filter-bar { align-items: stretch; flex-direction: column; padding: 14px 16px 8px; }
    .seg { display: flex; width: 100%; }
    .seg a { flex: 1; justify-content: center; padding: 0 8px; }
    .filter-group { width: 100%; }

    /* tab status: satu baris, dibagi rata */
    .status-tabs { flex-wrap: nowrap; gap: 0; padding: 0 16px; overflow: visible; }
    .status-tabs a { flex: 1; justify-content: center; gap: 5px; padding: 12px 2px; font-size: 13px; white-space: nowrap; }
    .status-tabs a .cnt { min-width: 20px; height: 20px; padding: 0 5px; font-size: 11.5px; }
    .lbl-full { display: none; }
    .lbl-short { display: inline; }

    /* tabel jadi kartu di HP */
    .table-wrapper { overflow: visible; margin: 0; border: 0; border-radius: 0; padding: 16px 16px 6px; }
    .tabel-pekerjaan, .tabel-pekerjaan tbody { display: block; width: 100%; }
    .tabel-pekerjaan thead { display: none; }

    .tabel-pekerjaan tbody tr {
        display: flex;
        flex-direction: column;
        margin-bottom: 12px;
        border: 1px solid var(--line);
        border-radius: 12px;
        background: var(--surface);
        overflow: hidden;
    }

    .tabel-pekerjaan tbody tr.row-klik:hover td,
    .tabel-pekerjaan tbody tr.row-klik:focus-visible td { background: transparent; }
    .tabel-pekerjaan tbody tr.row-klik:active { background: var(--surface-2); }

    .tabel-pekerjaan td,
    .tabel-pekerjaan td:first-child,
    .tabel-pekerjaan td:last-child,
    .tabel-pekerjaan td[data-label="Status"],
    .tabel-pekerjaan td[data-label="Aksi"] {
        display: flex;
        align-items: baseline;
        justify-content: flex-start;
        width: auto;
        padding: 13px 16px;
        border-bottom: 1px solid var(--line-soft);
        background: transparent;
        white-space: normal;
        text-align: left;
        font-size: 15px;
        font-weight: 600;
        line-height: 1.45;
        color: var(--text);
        overflow-wrap: anywhere;
    }

    .tabel-pekerjaan td::before {
        content: attr(data-label);
        order: -2;
        flex: 0 0 118px;
        font-size: 14px;
        font-weight: 700;
        color: var(--text);
    }

    .tabel-pekerjaan td::after {
        content: ":";
        order: -1;
        flex: 0 0 18px;
        font-size: 14px;
        font-weight: 700;
        color: var(--text);
    }

    .tabel-pekerjaan td[data-label="Status"],
    .tabel-pekerjaan td[data-label="Aksi"] { align-items: center; }

    .tabel-pekerjaan td:last-child { border-bottom: none; }

    .tabel-pekerjaan td.col-nama {
        order: -1;
        justify-content: flex-start;
        padding: 13px 14px;
        background: var(--surface-2);
        text-align: left;
        font-size: 15px;
        font-weight: 600;
    }
    .tabel-pekerjaan td.col-nama::before,
    .tabel-pekerjaan td.col-nama::after { display: none; }

    .tabel-pekerjaan td.empty-row { display: block; text-align: center; border: 0; padding: 36px 14px !important; }
    .tabel-pekerjaan td.empty-row::before,
    .tabel-pekerjaan td.empty-row::after { display: none; }
    .tabel-pekerjaan tbody tr:has(.empty-row) { border: 0; }

    .tabel-pekerjaan td.col-no { display: none !important; }

    .uraian-teks { max-width: none; -webkit-line-clamp: 3; }
    .ket-teks { max-width: none; }
    .teks-potong { max-width: none; white-space: normal; overflow: visible; }

    .table-foot { padding: 12px 16px; }
}

@media (max-width: 380px) {
    .detail-item { grid-template-columns: 128px minmax(0, 1fr); column-gap: 8px; }
    .status-tabs a { font-size: 12px; gap: 4px; }
    .status-tabs a .cnt { min-width: 18px; padding: 0 4px; }
}

</style>
</head>
<body>

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="content">

<div class="page-title">
<?php if ($order_detail): ?>
    <div>
        <h2>Detail Order</h2>
        <p>Informasi lengkap order masuk pada divisi <?= e($nama_divisi); ?>.</p>
    </div>
    <a href="<?= $halaman; ?>?status=<?= e($status_kembali); ?>&jenis=<?= e($jenis_kembali); ?>" class="btn-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5"></path>
            <path d="M12 19l-7-7 7-7"></path>
        </svg>
        Kembali
    </a>
<?php else: ?>
    <div>
        <h2>Monitoring: Order Masuk</h2>
        <p>Pantau dan konfirmasi order yang masuk ke divisi <?= e($nama_divisi); ?>.</p>
    </div>
<?php endif; ?>
</div>

<?php if ($pesan_sukses !== ""): ?>
    <div class="alert alert-success" role="status">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/></svg>
        <?= e($pesan_sukses); ?>
    </div>
<?php endif; ?>

<?php if ($pesan_error !== ""): ?>
    <div class="alert alert-error" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>
        <?= e($pesan_error); ?>
    </div>
<?php endif; ?>

<?php if ($order_detail): ?>

<!-- ===================== DETAIL ORDER ===================== -->
<div class="card detail-card">

    <div class="job-top">
        <div class="job-name"><?= e($order_detail["judul"]); ?></div>
        <div class="job-status <?= status_class($order_detail["status"]); ?>"><?= e(status_order_label($order_detail["status"])); ?></div>
    </div>

    <div class="detail-grid">

        <div class="detail-item">
            <div class="detail-label">Nomor Order</div>
            <form method="POST" class="nomor-form">
                <input type="hidden" name="aksi" value="order_nomor">
                <input type="hidden" name="id_order" value="<?= intval($order_detail["id_order"]); ?>">
                <input type="text" name="nomor_order" maxlength="50" required
                       value="<?= e($order_detail["nomor_order"] ?? ""); ?>"
                       placeholder="Isi nomor order">
                <button type="submit">Simpan</button>
            </form>
        </div>

        <div class="detail-item">
            <div class="detail-label">Pelapor</div>
            <div class="detail-value"><?= !empty($order_detail["nama_pelapor"]) ? e($order_detail["nama_pelapor"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Departemen Asal</div>
            <div class="detail-value"><?= !empty($order_detail["divisi_asal"]) ? e($order_detail["divisi_asal"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Divisi Tujuan</div>
            <div class="detail-value"><?= !empty($order_detail["divisi_tujuan"]) ? e($order_detail["divisi_tujuan"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label"><?= count($daftar_pekerja_order) > 1 ? "Anggota Tim" : "Dikerjakan Oleh"; ?></div>
            <?php if (count($daftar_pekerja_order) > 1): ?>
                <div class="employee-list">
                    <?php foreach ($daftar_pekerja_order as $i => $nama_pekerja): ?>
                        <div class="employee">
                            <span class="employee-number"><?= $i + 1; ?></span>
                            <span class="employee-name"><?= e($nama_pekerja); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="detail-value">
                    <?php
                    $nama_tunggal = $daftar_pekerja_order[0] ?? ($order_detail["nama_karyawan"] ?? "");
                    echo $nama_tunggal !== "" ? e($nama_tunggal) : "Belum diambil";
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-item">
            <div class="detail-label">Lokasi</div>
            <div class="detail-value"><?= !empty($order_detail["lokasi"]) ? e($order_detail["lokasi"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Order Masuk</div>
            <div class="detail-value"><?= format_tgl_order($order_detail["created_at"] ?? null); ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Diambil</div>
            <div class="detail-value"><?= format_tgl_order($order_detail["tanggal_diambil"] ?? null); ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Selesai</div>
            <div class="detail-value"><?= format_tgl_order($order_detail["tanggal_selesai"] ?? null); ?></div>
        </div>

        <?php if ($order_detail["status"] === "Selesai"): ?>
        <div class="detail-item">
            <div class="detail-label">Durasi Pengerjaan</div>
            <div class="detail-value"><?= format_durasi_order($order_detail["tanggal_diambil"] ?? null, $order_detail["tanggal_selesai"] ?? null); ?></div>
        </div>
        <?php endif; ?>

    </div>

    <div class="description-area">
        <div class="description-title">Deskripsi Kerusakan</div>
        <div class="description-text">
            <?= !empty($order_detail["deskripsi"]) ? nl2br(e($order_detail["deskripsi"])) : "Tidak ada deskripsi."; ?>
        </div>
    </div>

    <?php if (!empty($order_detail["foto"])): ?>
    <div class="description-area">
        <div class="description-title">Foto Kerusakan</div>
        <?php if ($url_foto_kerusakan !== ""): ?>
            <img src="<?= e($url_foto_kerusakan); ?>" class="evidence-photo" alt="Foto kerusakan" onclick="bukaFoto(this.src)">
        <?php else: ?>
            <div class="empty-evidence">File foto kerusakan tidak ditemukan.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- BUKTI PEKERJAAN ORDER -->
<div class="card detail-card">

    <h3 class="section-heading">Bukti Pekerjaan</h3>
    <p class="section-desc">Bukti pekerjaan yang dikirim karyawan, diurutkan dari pengiriman pertama (nomor 1). Foto sebelum dan sesudah pengerjaan ditampilkan terpisah.</p>

    <?php if (!empty($bukti_order_list)): ?>
        <div class="evidence-list">
            <?php foreach ($bukti_order_list as $i => $bukti): ?>
                <?php
                /* dua kelompok foto: sebelum (kolom foto_sebelum) & sesudah (kolom foto) */
                $grup_foto = [
                    "Foto Sebelum" => daftar_foto_bukti($bukti["foto_sebelum"] ?? ""),
                    "Foto Sesudah" => daftar_foto_bukti($bukti["foto"] ?? ""),
                ];
                $ada_foto_tercatat = !empty($grup_foto["Foto Sebelum"]) || !empty($grup_foto["Foto Sesudah"]);
                ?>
                <div class="evidence">
                    <div class="evidence-head">
                        <div class="evidence-person">
                            <span class="evidence-number"><?= $i + 1; ?></span>
                            <?= e($bukti["nama_karyawan"] ?? "Karyawan"); ?>
                        </div>
                        <div class="evidence-date">
                            <?= !empty($bukti["tanggal_upload"]) ? date("d M Y, H:i", strtotime($bukti["tanggal_upload"])) : "-"; ?>
                        </div>
                    </div>

                    <div class="evidence-body">
                        <div>
                            <?php if (!$ada_foto_tercatat): ?>

                                <div class="empty-evidence">Tidak ada foto bukti pekerjaan.</div>

                            <?php else: ?>

                                <?php foreach ($grup_foto as $judul_grup => $nama_list): ?>
                                    <?php if (empty($nama_list)) { continue; } ?>

                                    <?php
                                    $url_grup = [];
                                    foreach ($nama_list as $satu_foto) {
                                        $u = cari_foto_bukti($satu_foto);
                                        if ($u !== "") {
                                            $url_grup[] = $u;
                                        }
                                    }
                                    ?>

                                    <div class="evidence-group">
                                        <div class="evidence-group-title"><?= e($judul_grup); ?></div>

                                        <?php if (!empty($url_grup)): ?>
                                            <div class="evidence-photos">
                                                <?php foreach ($url_grup as $u): ?>
                                                    <img src="<?= e($u); ?>" class="evidence-photo" alt="<?= e($judul_grup); ?>" onclick="bukaFoto(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="empty-evidence">Foto tercatat di sistem, tetapi file fotonya tidak ditemukan.</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                            <?php endif; ?>
                        </div>

                        <div class="evidence-note">
                            <div class="evidence-note-title">Catatan Karyawan</div>
                            <div class="evidence-note-text">
                                <?= !empty($bukti["catatan_karyawan"]) ? nl2br(e($bukti["catatan_karyawan"])) : "Tidak ada catatan."; ?>
                            </div>

                            <?php if (!empty($bukti["catatan_admin"])): ?>
                                <div class="evidence-note-admin">
                                    <div class="evidence-note-title">Catatan Admin</div>
                                    <div class="evidence-note-text"><?= nl2br(e($bukti["catatan_admin"])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-evidence">Belum ada bukti pekerjaan yang dikirim.</div>
    <?php endif; ?>

</div>

<!-- KONFIRMASI ORDER -->
<div class="card detail-card">

<?php if ($order_detail["status"] === "Menunggu Konfirmasi"): ?>

    <div class="detail-header">
        <h3>Konfirmasi</h3>
        <p>Periksa bukti sebelum menentukan hasil pengerjaan order.</p>
    </div>

    <div class="action-area">
        <form method="POST" class="form-konfirmasi">
            <input type="hidden" name="aksi" value="order_konfirmasi">
            <input type="hidden" name="id_order" value="<?= intval($order_detail["id_order"]); ?>">
            <button type="submit" class="btn-confirm">Konfirmasi Selesai</button>
        </form>
        <button type="button" class="btn-repair" onclick="showRepair()">Perlu Perbaikan</button>
    </div>

    <div id="repairForm" style="display:none;margin-top:12px;">
        <form method="POST">
            <input type="hidden" name="aksi" value="order_perbaikan">
            <input type="hidden" name="id_order" value="<?= intval($order_detail["id_order"]); ?>">
            <textarea name="catatan_perbaikan" class="action-note" placeholder="Tuliskan catatan perbaikan..." required></textarea>
            <button type="submit" class="btn-repair">Kirim Perbaikan</button>
        </form>
    </div>

<?php elseif ($order_detail["status"] === "Perlu Perbaikan"): ?>

    <div class="detail-header">
        <h3>Konfirmasi</h3>
        <p>Order sedang diperbaiki. Konfirmasi bila perbaikan sudah sesuai.</p>
    </div>

    <div class="action-area">
        <form method="POST" class="form-konfirmasi">
            <input type="hidden" name="aksi" value="order_konfirmasi">
            <input type="hidden" name="id_order" value="<?= intval($order_detail["id_order"]); ?>">
            <button type="submit" class="btn-confirm">Konfirmasi Selesai</button>
        </form>
    </div>

<?php elseif (in_array($order_detail["status"], ["Menunggu", "Diproses"], true)): ?>

    <div class="status-info">
        <?= $order_detail["status"] === "Menunggu"
            ? "Order ini belum diambil oleh karyawan."
            : "Order sedang dikerjakan dan belum mengirim bukti."; ?>
    </div>

<?php else: ?>

    <div class="status-info">Order ini sudah selesai dan tidak sedang menunggu konfirmasi.</div>

<?php endif; ?>

</div>

<?php else: ?>

<!-- ===================== DAFTAR ORDER MASUK ===================== -->
<div class="card list-card">

    <div class="filter-bar">

        <div class="filter-group">
            <div class="seg">
                <a href="<?= $halaman; ?>?status=<?= e($filter_status); ?>&jenis=Individu<?= $qs_keyword; ?>" class="<?= $jenis === "Individu" ? "active" : ""; ?>">
                    <?= $ikon_individu; ?> Individu <span class="cnt"><?= $count_individu; ?></span>
                </a>
                <a href="<?= $halaman; ?>?status=<?= e($filter_status); ?>&jenis=Tim<?= $qs_keyword; ?>" class="<?= $jenis === "Tim" ? "active" : ""; ?>">
                    <?= $ikon_tim; ?> Tim <span class="cnt"><?= $count_tim; ?></span>
                </a>
            </div>
        </div>

        <form method="GET" class="list-header-right">
            <input type="hidden" name="status" value="<?= e($filter_status); ?>">
            <input type="hidden" name="jenis" value="<?= e($jenis); ?>">
            <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
                <input type="text" name="keyword" value="<?= e($keyword); ?>" placeholder="Cari order, pelapor, karyawan, atau nomor order" aria-label="Cari">
            </div>
        </form>

    </div>

    <div class="status-section">
        <div class="status-tabs">
            <?php foreach ($daftar_status as $kode => [$label, $jumlah, $pendek]): ?>
                <a href="<?= $halaman; ?>?status=<?= $kode . $qs_keyword; ?>&jenis=<?= urlencode($jenis); ?>" class="<?= $filter_status === $kode ? "active" : ""; ?>">
                    <span class="lbl-full"><?= $label; ?></span><span class="lbl-short"><?= $pendek; ?></span> <span class="cnt"><?= $jumlah; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="table-wrapper">
        <table class="tabel-pekerjaan">

        <thead>
            <tr>
                <th>No</th>
                <th>No. Order</th>
                <th>Nama Order</th>
                <th>Pelapor</th>
                <th>Dept Asal</th>
                <th>Lokasi</th>
                <th>Uraian Kerusakan</th>
                <?php if ($jenis === "Tim"): ?>
                    <th>Jumlah</th>
                <?php else: ?>
                    <th>Dikerjakan Oleh</th>
                <?php endif; ?>
                <th>Tgl/Jam Dibuat</th>
                <th>Diambil</th>
                <th>Selesai Dikerjakan</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>

        <?php
        $no = 1;
        if ($query_monitoring && $total_baris > 0):
            while ($row = mysqli_fetch_assoc($query_monitoring)):

                /* waktu selesai: tanggal selesai (sudah dikonfirmasi) atau bukti terakhir yang dikirim */
                $selesai_order = !empty($row["tanggal_selesai"]) ? $row["tanggal_selesai"] : null;
                if (!$selesai_order && in_array($row["status"], ["Menunggu Konfirmasi", "Perlu Perbaikan"], true)) {
                    $selesai_order = $row["jam_bukti_terakhir"] ?? null;
                }

                $teks_nomor    = !empty($row["nomor_order"]) ? $row["nomor_order"] : "-";
                $teks_pelapor  = !empty($row["nama_pelapor"]) ? $row["nama_pelapor"] : "-";
                $teks_dept     = !empty($row["divisi_asal"]) ? $row["divisi_asal"] : "-";
                $teks_lokasi   = !empty($row["lokasi"]) ? $row["lokasi"] : "-";
                $teks_uraian   = !empty($row["deskripsi"]) ? $row["deskripsi"] : "-";
                $teks_pekerja  = nama_satu_baris($row["nama_pekerja_order"] ?? "");
        ?>
            <tr class="row-klik" tabindex="0" title="Klik untuk melihat detail" data-id="<?= intval($row["id_order"]); ?>">
                <td class="col-no" data-label="No"><?= $no++; ?></td>

                <td data-label="No. Order"><?= e($teks_nomor); ?></td>

                <td class="col-nama" data-label="Nama Order"><div class="ket-teks"><?= e($row["judul"]); ?></div></td>

                <td data-label="Pelapor"><div class="teks-potong" title="<?= e($teks_pelapor); ?>"><?= e($teks_pelapor); ?></div></td>

                <td data-label="Dept Asal"><div class="teks-potong" title="<?= e($teks_dept); ?>"><?= e($teks_dept); ?></div></td>

                <td data-label="Lokasi"><div class="teks-potong" title="<?= e($teks_lokasi); ?>"><?= e($teks_lokasi); ?></div></td>

                <td data-label="Uraian Kerusakan"><div class="uraian-teks" title="<?= e($teks_uraian); ?>"><?= e($teks_uraian); ?></div></td>

                <?php if ($jenis === "Tim"): ?>
                    <td data-label="Jumlah"><span class="jumlah-badge"><?= (int) ($row["jumlah_pekerja"] ?? 0); ?> Karyawan</span></td>
                <?php else: ?>
                    <td data-label="Dikerjakan Oleh"><div class="teks-potong" title="<?= e($teks_pekerja); ?>"><?= e($teks_pekerja); ?></div></td>
                <?php endif; ?>

                <td data-label="Tgl/Jam Dibuat"><?= format_tgl_jam($row["created_at"] ?? null); ?></td>
                <td data-label="Diambil"><?= format_tgl_jam($row["tanggal_diambil"] ?? null); ?></td>
                <td data-label="Selesai Dikerjakan"><?= format_tgl_jam($selesai_order); ?></td>

                <td data-label="Status"><span class="status-badge <?= status_class($row["status"]); ?>"><?= e(status_order_label($row["status"])); ?></span></td>

                <td data-label="Aksi">
                    <div class="aksi-group">
                        <a href="<?= $halaman; ?>?id=<?= intval($row["id_order"]); ?>&status=<?= e($filter_status); ?>&jenis=<?= e($jenis); ?>" class="btn-aksi btn-detail" title="Lihat detail" aria-label="Lihat detail">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </a>
                    </div>
                </td>
            </tr>
        <?php
            endwhile;
        else:
        ?>
            <tr>
                <td colspan="13" class="empty-row">
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/></svg>
                        <strong>Tidak ada order yang ditemukan</strong>
                        <span>Coba ganti status atau kata kunci pencarian.</span>
                    </div>
                </td>
            </tr>
        <?php endif; ?>

        </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>
            Menampilkan <strong><?= $total_baris; ?></strong> order masuk
            <strong><?= e(strtolower($jenis)); ?></strong>
            dengan status <strong><?= e($daftar_status[$filter_status][0] ?? "Semua"); ?></strong>
            di divisi <?= e($nama_divisi); ?>
        </span>
    </div>

</div>
<?php endif; ?>

<div class="footer-note">
    &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
</div>

</div>
</div>

<!-- MODAL FOTO -->
<div class="foto-modal" id="fotoModal" onclick="tutupFoto(event)">
    <button type="button" class="close-modal" onclick="tutupFoto(event)" aria-label="Tutup foto">&times;</button>
    <img id="fotoModalImage" src="" alt="Foto ukuran penuh">
</div>

<!-- MODAL KONFIRMASI SELESAI -->
<div class="modal" id="confirmModal">
    <div class="modal-box">
        <h3>Konfirmasi Order?</h3>
        <p>Order akan ditandai sebagai selesai dan pelapor akan mendapat pemberitahuan.</p>
        <div class="modal-actions">
            <button type="button" class="modal-btn modal-cancel" onclick="closeConfirm()">Batal</button>
            <button type="button" class="modal-btn modal-ok" onclick="submitConfirm()">Ya, Selesai</button>
        </div>
    </div>
</div>

<script>

/* KONFIRMASI SELESAI (dipakai di status Menunggu & Perlu Perbaikan) */
const confirmModal = document.getElementById("confirmModal");
let formKonfirmasiAktif = null;

document.querySelectorAll(".form-konfirmasi").forEach(function (form) {
    form.addEventListener("submit", function (e) {
        e.preventDefault();
        formKonfirmasiAktif = form;
        confirmModal.classList.add("show");
    });
});

function submitConfirm() {
    if (formKonfirmasiAktif) {
        formKonfirmasiAktif.submit();
    }
}

function closeConfirm() {
    confirmModal.classList.remove("show");
    formKonfirmasiAktif = null;
}

confirmModal.addEventListener("click", function (e) {
    if (e.target === confirmModal) {
        closeConfirm();
    }
});

/* FORM PERBAIKAN */
function showRepair() {
    const form = document.getElementById("repairForm");
    if (form) {
        form.style.display = "block";
        const textarea = form.querySelector("textarea");
        if (textarea) { textarea.focus(); }
    }
}

/* KLIK BARIS -> DETAIL */
document.querySelectorAll("tr.row-klik").forEach(function (baris) {
    function buka() {
        window.location.href = "<?= $halaman; ?>?id=" + encodeURIComponent(baris.dataset.id)
            + "&status=<?= urlencode($filter_status); ?>&jenis=<?= urlencode($jenis); ?>";
    }
    baris.addEventListener("click", function (event) {
        if (event.target.closest(".aksi-group")) { return; }
        buka();
    });
    baris.addEventListener("keydown", function (event) {
        if (event.target !== baris) { return; }
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            buka();
        }
    });
});

/* MODAL FOTO BUKTI */
function bukaFoto(src) {
    document.getElementById("fotoModalImage").src = src;
    document.getElementById("fotoModal").classList.add("active");
    document.body.style.overflow = "hidden";
}

function tutupFoto(event) {
    if (event && event.target && event.target.id === "fotoModalImage") { return; }
    document.getElementById("fotoModal").classList.remove("active");
    document.getElementById("fotoModalImage").src = "";
    document.body.style.overflow = "";
}

/* ESC menutup semua popup */
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeConfirm();
        tutupFoto(null);
    }
});

</script>

</body>
</html>
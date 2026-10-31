<?php
/* =========================================================
   MONITORING - Order Perbaikan
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

/* halaman ini hanya menampilkan satu tipe pekerjaan */
$halaman    = "monitoring_order_perbaikan.php";
$label_page = "Order Perbaikan";
$kond_tipe  = "p.tipe_order='Order Perbaikan'";

/* Individu / Tim */
$jenis = $_GET["jenis"] ?? "Individu";
if (!in_array($jenis, ["Individu", "Tim"], true)) {
    $jenis = "Individu";
}

if (($_GET["pesan"] ?? "") === "hapus") {
    $pesan_sukses = "Pekerjaan berhasil dihapus.";
}

/* status penugasan yang tidak dihitung sebagai anggota
   (cek isi kolom: SELECT DISTINCT status_penugasan FROM pekerjaan_karyawan;) */
$status_dikeluarkan = "'Dialihkan','Dibatalkan','Ditolak','Tidak Aktif'";

/* kirim notifikasi ke semua anggota pekerjaan */
function notif_anggota($koneksi, $id_pekerjaan, $pekerjaan, $teks)
{
    $id_pekerjaan = (int) $id_pekerjaan;
    $ids = [];

    $anggota = mysqli_query(
        $koneksi,
        "SELECT DISTINCT id_user FROM pekerjaan_karyawan WHERE id_pekerjaan='$id_pekerjaan'"
    );
    if ($anggota) {
        while ($a = mysqli_fetch_assoc($anggota)) {
            $ids[] = intval($a["id_user"]);
        }
    }
    if (empty($ids) && !empty($pekerjaan["id_karyawan"])) {
        $ids[] = intval($pekerjaan["id_karyawan"]);
    }

    $pesan_db = mysqli_real_escape_string($koneksi, $teks);
    foreach (array_unique($ids) as $uid) {
        mysqli_query(
            $koneksi,
            "INSERT INTO notifikasi (id_user,pesan,status_baca,tanggal)
             VALUES ('$uid','$pesan_db','Belum Dibaca',NOW())"
        );
    }
}

/* =========================================================
   PROSES AKSI
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $aksi         = $_POST["aksi"] ?? "";
    $id_pekerjaan = intval($_POST["id_pekerjaan"] ?? 0);

    $cek = mysqli_query(
        $koneksi,
        "SELECT p.*, u.nama AS nama_karyawan
         FROM pekerjaan p
         LEFT JOIN users u ON p.id_karyawan = u.id_user
         WHERE p.id_pekerjaan='$id_pekerjaan'
         AND p.id_divisi='$id_divisi'
         AND $kond_tipe
         LIMIT 1"
    );
    $pekerjaan_proses = mysqli_fetch_assoc($cek);

    if (!$pekerjaan_proses) {

        $pesan_error = "Pekerjaan tidak ditemukan atau bukan bagian dari halaman ini.";

    } elseif ($aksi === "hapus") {

        mysqli_begin_transaction($koneksi);

        try {
            $tabel_terkait = [
                "bukti_pekerjaan",
                "riwayat_pekerjaan",
                "pengalihan_pekerjaan",
                "pekerjaan_karyawan"
            ];

            foreach ($tabel_terkait as $tabel) {
                if (!mysqli_query($koneksi, "DELETE FROM $tabel WHERE id_pekerjaan='$id_pekerjaan'")) {
                    throw new Exception("Gagal menghapus data terkait.");
                }
            }

            if (!mysqli_query($koneksi, "DELETE FROM pekerjaan WHERE id_pekerjaan='$id_pekerjaan' AND id_divisi='$id_divisi'")) {
                throw new Exception("Gagal menghapus pekerjaan.");
            }

            mysqli_commit($koneksi);

            $status_kembali = urlencode($_GET["status"] ?? "menunggu");
            $jenis_kembali  = urlencode($_GET["jenis"] ?? "Individu");
            header("Location: $halaman?status=$status_kembali&jenis=$jenis_kembali&pesan=hapus");
            exit;

        } catch (Exception $e) {
            mysqli_rollback($koneksi);
            $pesan_error = "Pekerjaan gagal dihapus.";
        }

    } elseif ($aksi === "konfirmasi") {

        if (!in_array($pekerjaan_proses["status"], ["Menunggu Konfirmasi", "Perlu Perbaikan"], true)) {

            $pesan_error = "Pekerjaan tidak dapat diubah pada status ini.";

        } else {

            $update = mysqli_query(
                $koneksi,
                "UPDATE pekerjaan
                 SET status='Selesai'
                 WHERE id_pekerjaan='$id_pekerjaan'
                 AND id_divisi='$id_divisi'
                 AND status IN ('Menunggu Konfirmasi','Perlu Perbaikan')"
            );

            if ($update) {

                mysqli_query(
                    $koneksi,
                    "UPDATE pekerjaan_karyawan
                     SET status_penugasan='Selesai'
                     WHERE id_pekerjaan='$id_pekerjaan'
                     AND status_penugasan IN ('Aktif','Peralihan','Menunggu Konfirmasi')"
                );

                mysqli_query(
                    $koneksi,
                    "UPDATE riwayat_pekerjaan
                     SET tanggal_hapus_foto=DATE_ADD(NOW(), INTERVAL 1 DAY)
                     WHERE id_pekerjaan='$id_pekerjaan'
                     AND status='Menunggu Konfirmasi'
                     ORDER BY id_riwayat DESC
                     LIMIT 1"
                );

                notif_anggota(
                    $koneksi,
                    $id_pekerjaan,
                    $pekerjaan_proses,
                    "Pekerjaan \"" . $pekerjaan_proses["nama_pekerjaan"] . "\" telah dikonfirmasi selesai oleh Admin Divisi."
                );

                $pesan_sukses = "Pekerjaan berhasil dikonfirmasi sebagai selesai.";
                $id_detail    = $id_pekerjaan;

            } else {
                $pesan_error = "Gagal mengubah status pekerjaan.";
            }
        }

    } elseif ($aksi === "perbaikan") {

        $catatan = trim($_POST["catatan_perbaikan"] ?? "");

        if (!in_array($pekerjaan_proses["status"], ["Menunggu Konfirmasi", "Perlu Perbaikan"], true)) {

            $pesan_error = "Pekerjaan tidak dapat diubah pada status ini.";

        } elseif ($catatan === "") {

            $pesan_error = "Catatan perbaikan wajib diisi.";

        } else {

            $catatan_db = mysqli_real_escape_string($koneksi, $catatan);

            $bukti = mysqli_fetch_assoc(mysqli_query(
                $koneksi,
                "SELECT id_bukti FROM bukti_pekerjaan
                 WHERE id_pekerjaan='$id_pekerjaan'
                 ORDER BY id_bukti DESC LIMIT 1"
            ));

            if (!$bukti) {

                $pesan_error = "Bukti pekerjaan tidak ditemukan.";

            } else {

                $id_bukti = intval($bukti["id_bukti"]);

                $update_bukti = mysqli_query(
                    $koneksi,
                    "UPDATE bukti_pekerjaan SET catatan_admin='$catatan_db' WHERE id_bukti='$id_bukti'"
                );

                $update_pekerjaan = mysqli_query(
                    $koneksi,
                    "UPDATE pekerjaan
                     SET status='Perlu Perbaikan'
                     WHERE id_pekerjaan='$id_pekerjaan'
                     AND id_divisi='$id_divisi'
                     AND status IN ('Menunggu Konfirmasi','Perlu Perbaikan')"
                );

                if ($update_bukti && $update_pekerjaan) {

                    notif_anggota(
                        $koneksi,
                        $id_pekerjaan,
                        $pekerjaan_proses,
                        "Pekerjaan \"" . $pekerjaan_proses["nama_pekerjaan"] . "\" perlu diperbaiki. Silakan lihat catatan Admin Divisi."
                    );

                    $pesan_sukses = "Pekerjaan dikembalikan untuk diperbaiki.";
                    $id_detail    = $id_pekerjaan;

                } else {
                    $pesan_error = "Gagal menyimpan catatan perbaikan.";
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

if (!in_array($filter_status, ["menunggu", "perbaikan", "selesai", "semua"], true)) {
    $filter_status = "menunggu";
}

/* semua anggota dihitung, kecuali yang sudah keluar dari pekerjaan */
$jumlah_anggota_sql = "(SELECT COUNT(DISTINCT pk_j.id_user)
    FROM pekerjaan_karyawan pk_j
    WHERE pk_j.id_pekerjaan=p.id_pekerjaan
    AND COALESCE(pk_j.status_penugasan,'') NOT IN ($status_dikeluarkan)
    AND NOT EXISTS (
        SELECT 1 FROM pengalihan_pekerjaan pp_j
        WHERE pp_j.id_pekerjaan=pk_j.id_pekerjaan
          AND pp_j.id_karyawan_pengaju=pk_j.id_user
    ))";

function hitung($koneksi, $sql)
{
    $q = mysqli_query($koneksi, $sql);
    return $q ? (int)(mysqli_fetch_assoc($q)["total"] ?? 0) : 0;
}

/* daftar kolom tabel pekerjaan (dipakai untuk mendeteksi kolom opsional) */
$kolom_pekerjaan = [];
$q_kolom = mysqli_query($koneksi, "SHOW COLUMNS FROM pekerjaan");
if ($q_kolom) {
    while ($k = mysqli_fetch_assoc($q_kolom)) {
        $kolom_pekerjaan[] = $k["Field"];
    }
}

/* hubungan pekerjaan -> order: pakai id_order kalau ada, kalau tidak pakai nomor order */
$join_order_on = in_array("id_order", $kolom_pekerjaan, true)
    ? "op_f.id_order = p.id_order"
    : "op_f.nomor_order COLLATE utf8mb4_general_ci = p.nomor_order COLLATE utf8mb4_general_ci AND p.nomor_order <> '' AND op_f.id_divisi = p.id_divisi";


$filter_status_sql = "";
if ($filter_status === "menunggu") {
    $filter_status_sql = " AND p.status='Menunggu Konfirmasi'";
} elseif ($filter_status === "perbaikan") {
    $filter_status_sql = " AND p.status='Perlu Perbaikan'";
} elseif ($filter_status === "selesai") {
    $filter_status_sql = " AND p.status='Selesai'";
}

$filter_keyword_sql = "";
if ($keyword !== "") {
    $filter_keyword_sql = " AND (p.nama_pekerjaan LIKE '%$keyword_sql%'
            OR p.deskripsi LIKE '%$keyword_sql%'
            OR p.nomor_order LIKE '%$keyword_sql%'
            OR p.uraian_kerusakan LIKE '%$keyword_sql%'
            OR p.nama_pelapor LIKE '%$keyword_sql%'
            OR p.departemen_asal LIKE '%$keyword_sql%'
            OR u.nama LIKE '%$keyword_sql%')";
}

$filter_jenis_sql = ($jenis === "Tim"
    ? " AND $jumlah_anggota_sql > 1"
    : " AND $jumlah_anggota_sql <= 1")
    . " AND " . $kond_tipe;

$query_monitoring = mysqli_query(
    $koneksi,
    "SELECT p.*,
            up_f.nama AS nama_pelapor_f,
            COALESCE(NULLIF(up_f.departemen, ''), da_f.nama_divisi) AS dept_f,
            (SELECT MAX(bp2.tanggal_upload) FROM bukti_pekerjaan bp2 WHERE bp2.id_pekerjaan=p.id_pekerjaan) AS jam_bukti_terakhir,
            (SELECT bp3.catatan_karyawan FROM bukti_pekerjaan bp3 WHERE bp3.id_pekerjaan=p.id_pekerjaan ORDER BY bp3.id_bukti DESC LIMIT 1) AS item_terakhir,
            COALESCE(
                (SELECT GROUP_CONCAT(DISTINCT u_t.nama ORDER BY u_t.nama ASC SEPARATOR '|||')
                 FROM pekerjaan_karyawan pk_t
                 INNER JOIN users u_t ON u_t.id_user=pk_t.id_user
                 WHERE pk_t.id_pekerjaan=p.id_pekerjaan
                 AND COALESCE(pk_t.status_penugasan,'') NOT IN ($status_dikeluarkan)
                 AND NOT EXISTS (
                     SELECT 1 FROM pengalihan_pekerjaan pp_t
                     WHERE pp_t.id_pekerjaan=pk_t.id_pekerjaan
                       AND pp_t.id_karyawan_pengaju=pk_t.id_user
                 )),
                u.nama
            ) AS nama_karyawan
     FROM pekerjaan p
     LEFT JOIN users u ON p.id_karyawan=u.id_user
     LEFT JOIN order_perbaikan op_f ON $join_order_on
     LEFT JOIN users up_f ON up_f.id_user = op_f.id_pemesan
     LEFT JOIN divisi da_f ON da_f.id_divisi = op_f.id_divisi_asal
     WHERE p.id_divisi='$id_divisi' $filter_status_sql $filter_jenis_sql $filter_keyword_sql
     ORDER BY p.id_pekerjaan DESC"
);
$total_baris = $query_monitoring ? mysqli_num_rows($query_monitoring) : 0;

/* hitungan status mengikuti jenis (Individu / Tim) yang dipilih */
$count_menunggu  = hitung($koneksi, "SELECT COUNT(*) AS total FROM pekerjaan p WHERE p.id_divisi='$id_divisi' AND p.status='Menunggu Konfirmasi' $filter_jenis_sql");
$count_perbaikan = hitung($koneksi, "SELECT COUNT(*) AS total FROM pekerjaan p WHERE p.id_divisi='$id_divisi' AND p.status='Perlu Perbaikan' $filter_jenis_sql");
$count_selesai   = hitung($koneksi, "SELECT COUNT(*) AS total FROM pekerjaan p WHERE p.id_divisi='$id_divisi' AND p.status='Selesai' $filter_jenis_sql");
$count_semua     = hitung($koneksi, "SELECT COUNT(*) AS total FROM pekerjaan p WHERE p.id_divisi='$id_divisi' $filter_jenis_sql");

/* hitungan Individu / Tim tidak dipengaruhi status */
$count_individu = hitung($koneksi, "SELECT COUNT(*) AS total FROM pekerjaan p WHERE p.id_divisi='$id_divisi' AND $kond_tipe AND $jumlah_anggota_sql <= 1");
$count_tim      = hitung($koneksi, "SELECT COUNT(*) AS total FROM pekerjaan p WHERE p.id_divisi='$id_divisi' AND $kond_tipe AND $jumlah_anggota_sql > 1");

/* =========================================================
   DETAIL
========================================================= */
$detail          = null;
$daftar_karyawan = [];
$bukti_list      = [];
$pengalihan_list = [];

if ($id_detail > 0) {

    $detail = mysqli_fetch_assoc(mysqli_query(
        $koneksi,
        "SELECT p.*, u.nama AS nama_karyawan, u.no_hp AS no_hp_karyawan, d.nama_divisi
         FROM pekerjaan p
         LEFT JOIN users u ON p.id_karyawan=u.id_user
         LEFT JOIN divisi d ON p.id_divisi=d.id_divisi
         WHERE p.id_pekerjaan='$id_detail'
         AND p.id_divisi='$id_divisi'
         AND $kond_tipe
         LIMIT 1"
    ));

    if (!$detail) {

        $pesan_error = "Detail pekerjaan tidak ditemukan.";
        $id_detail   = 0;

    } else {

        $q_karyawan = mysqli_query(
            $koneksi,
            "SELECT pk.id, pk.id_user, pk.status_penugasan,
                    CONCAT(
                        u.nama,
                        COALESCE((
                            SELECT CONCAT(' (sebelumnya ', GROUP_CONCAT(DISTINCT u_lama.nama ORDER BY u_lama.nama SEPARATOR ', '), ')')
                            FROM pengalihan_pekerjaan pp_l
                            INNER JOIN users u_lama ON u_lama.id_user=pp_l.id_karyawan_pengaju
                            WHERE pp_l.id_pekerjaan=pk.id_pekerjaan
                              AND pp_l.id_karyawan_pengganti=pk.id_user
                        ), '')
                    ) AS nama
             FROM pekerjaan_karyawan pk
             INNER JOIN users u ON u.id_user=pk.id_user
             WHERE pk.id_pekerjaan='$id_detail'
               AND COALESCE(pk.status_penugasan,'') NOT IN ($status_dikeluarkan)
               AND NOT EXISTS (
                   SELECT 1 FROM pengalihan_pekerjaan pp_keluar
                   WHERE pp_keluar.id_pekerjaan=pk.id_pekerjaan
                     AND pp_keluar.id_karyawan_pengaju=pk.id_user
               )
             ORDER BY pk.id ASC"
        );
        if ($q_karyawan) {
            while ($r = mysqli_fetch_assoc($q_karyawan)) {
                $daftar_karyawan[] = $r;
            }
        }
        if (empty($daftar_karyawan) && !empty($detail["id_karyawan"])) {
            $daftar_karyawan[] = [
                "id" => 0,
                "id_user" => $detail["id_karyawan"],
                "status_penugasan" => "Aktif",
                "nama" => $detail["nama_karyawan"]
            ];
        }

        /* bp.* sudah ikut mengambil kolom foto (sesudah) dan foto_sebelum */
        $q_bukti = mysqli_query(
            $koneksi,
            "SELECT bp.*, u.nama AS nama_karyawan
             FROM bukti_pekerjaan bp
             LEFT JOIN users u ON bp.id_user=u.id_user
             WHERE bp.id_pekerjaan='$id_detail'
             ORDER BY bp.tanggal_upload ASC, bp.id_bukti ASC"
        );
        if ($q_bukti) {
            while ($r = mysqli_fetch_assoc($q_bukti)) {
                $bukti_list[] = $r;
            }
        }

        $q_pengalihan = mysqli_query(
            $koneksi,
            "SELECT pp.*, u1.nama AS nama_pengaju, u2.nama AS nama_pengganti
             FROM pengalihan_pekerjaan pp
             LEFT JOIN users u1 ON pp.id_karyawan_pengaju=u1.id_user
             LEFT JOIN users u2 ON pp.id_karyawan_pengganti=u2.id_user
             WHERE pp.id_pekerjaan='$id_detail'
             ORDER BY pp.tanggal_pengajuan ASC, pp.id_pengalihan ASC"
        );
        if ($q_pengalihan) {
            while ($r = mysqli_fetch_assoc($q_pengalihan)) {
                $pengalihan_list[] = $r;
            }
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

/* foto yang diunggah admin saat membuat pekerjaan.
   Nama kolomnya dideteksi otomatis dari kolom tabel pekerjaan (foto, gambar, image, lampiran, dll). */
function foto_pekerjaan_admin(array $pekerjaan)
{
    $hasil = [];
    foreach ($pekerjaan as $kolom => $nilai) {
        if (!preg_match('/foto|gambar|image|img|lampiran|attachment/i', (string) $kolom)) {
            continue;
        }
        if (preg_match('/hapus|tanggal|status|^id_/i', (string) $kolom)) {
            continue;
        }
        foreach (daftar_foto_bukti($nilai) as $nama) {
            $hasil[] = $nama;
        }
    }
    return array_values(array_unique($hasil));
}

function daftar_nama($nama)
{
    $list = array_filter(array_map('trim', explode('|||', (string)$nama)));
    if (empty($list)) {
        return "-";
    }
    $html = "";
    foreach ($list as $satu) {
        $html .= '<div class="nama-karyawan">' . e($satu) . '</div>';
    }
    return $html;
}

/* tanggal + jam jadi satu, sama dengan laporan: 19-10-2026, 13:00 */
function format_tgl_jam($t)
{
    return !empty($t) && strtotime($t) ? date("d-m-Y, H:i", strtotime($t)) : "-";
}

/* di tabel, status "Peralihan" hanya ditampilkan di halaman detail */
function status_tabel($status)
{
    return $status === "Peralihan" ? "Belum Dikerjakan" : $status;
}

function status_class($status)
{
    return match ($status) {
        "Selesai"             => "badge-selesai",
        "Sedang Dikerjakan"   => "badge-sedang",
        "Diproses"            => "badge-sedang",
        "Peralihan"           => "badge-peralihan",
        "Menunggu Konfirmasi" => "badge-menunggu",
        "Perlu Perbaikan"     => "badge-perbaikan",
        default               => "badge-belum"
    };
}

/* =========================================================
   JAM KERJA PER HARI (detail pekerjaan)
   - Jam kerja 08:00 - 16:00, Senin - Jumat (Sabtu & Minggu 0 jam)
   - Hari pertama dihitung dari jam pekerjaan mulai, hari berikutnya dari 08:00
   - Berhenti di jam bukti terakhir dikirim; kalau belum, sampai sekarang
========================================================= */
function jk_hitung_harian($mulai, $akhir, $jam_masuk = 8, $jam_pulang = 16)
{
    $hasil = ["hari" => [], "total_menit" => 0];

    $t_mulai = strtotime((string) $mulai);
    $t_akhir = strtotime((string) $akhir);

    if (!$t_mulai || !$t_akhir || $t_akhir <= $t_mulai) {
        return $hasil;
    }

    $hari       = strtotime(date("Y-m-d", $t_mulai));
    $hari_akhir = strtotime(date("Y-m-d", $t_akhir));
    $batas      = 0;

    while ($hari <= $hari_akhir && $batas < 366) {

        $y = (int) date("Y", $hari);
        $m = (int) date("n", $hari);
        $d = (int) date("j", $hari);

        $buka  = mktime($jam_masuk, 0, 0, $m, $d, $y);
        $tutup = mktime($jam_pulang, 0, 0, $m, $d, $y);

        $akhir_pekan = ((int) date("N", $hari) >= 6);

        $dari   = max($buka, $t_mulai);
        $sampai = min($tutup, $t_akhir);

        $menit = 0;
        if (!$akhir_pekan && $sampai > $dari) {
            $menit = intdiv($sampai - $dari, 60);
        }

        $hasil["hari"][] = [
            "tanggal"     => $hari,
            "akhir_pekan" => $akhir_pekan,
            "dari"        => $menit > 0 ? date("H:i", $dari) : "",
            "sampai"      => $menit > 0 ? date("H:i", $sampai) : "",
            "menit"       => $menit,
            "dari_ts"     => $dari,
            "sampai_ts"   => $sampai,
        ];

        $hasil["total_menit"] += $menit;

        $hari = strtotime("+1 day", $hari);
        $batas++;
    }

    return $hasil;
}

function jk_format($menit)
{
    $menit = max(0, (int) $menit);
    $jam   = intdiv($menit, 60);
    $sisa  = $menit % 60;

    if ($jam > 0 && $sisa > 0) {
        return $jam . " jam " . $sisa . " menit";
    }
    if ($jam > 0) {
        return $jam . " jam";
    }
    return $sisa . " menit";
}

/* lama keterlambatan dari selisih waktu nyata, contoh: 3 hari 21 jam */
function jk_lama_telat($detik)
{
    $detik = max(0, (int) $detik);
    $hari  = intdiv($detik, 86400);
    $jam   = intdiv($detik % 86400, 3600);
    $menit = intdiv($detik % 3600, 60);

    $bagian = [];
    if ($hari > 0) { $bagian[] = $hari . " hari"; }
    if ($jam > 0)  { $bagian[] = $jam . " jam"; }
    if ($hari == 0 && $menit > 0) { $bagian[] = $menit . " menit"; }

    return $bagian ? implode(" ", $bagian) : "kurang dari 1 menit";
}

/* contoh: 19 Okt, 13:00 */
function jk_waktu($timestamp)
{
    $bulan = ["", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];

    return date("j", $timestamp) . " " . $bulan[(int) date("n", $timestamp)] . ", " . date("H:i", $timestamp);
}

function jk_tanggal($timestamp)
{
    $hari  = ["", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];
    $bulan = ["", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];

    return $hari[(int) date("N", $timestamp)] . ", "
        . date("j", $timestamp) . " "
        . $bulan[(int) date("n", $timestamp)] . " "
        . date("Y", $timestamp);
}

/* menit dalam rentang kerja yang SEMUA anggota sedang keluar (untuk dikurangi dari jam kerja) */
function jk_menit_keluar_serentak($dari_ts, $sampai_ts, array $interval_per_user)
{
    if (empty($interval_per_user)) {
        return 0;
    }
    foreach ($interval_per_user as $iv) {
        if (empty($iv)) {
            return 0;
        }
    }

    $kurang = 0;
    for ($t = $dari_ts; $t + 60 <= $sampai_ts; $t += 60) {
        $semua_keluar = true;
        foreach ($interval_per_user as $iv) {
            $dalam = false;
            foreach ($iv as $rentang) {
                if ($t >= $rentang[0] && $t < $rentang[1]) {
                    $dalam = true;
                    break;
                }
            }
            if (!$dalam) {
                $semua_keluar = false;
                break;
            }
        }
        if ($semua_keluar) {
            $kurang++;
        }
    }

    return $kurang;
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

/* tombol Kembali: ke tab status sesuai status pekerjaan saat ini */
$peta_status = [
    "Menunggu Konfirmasi" => "menunggu",
    "Perlu Perbaikan"     => "perbaikan",
    "Selesai"             => "selesai"
];
$status_kembali = $detail ? ($peta_status[$detail["status"]] ?? "semua") : $filter_status;

/* ---------- riwayat keluar / gabung lagi + jam kerja ---------- */
$log_keluar = [];

$query_log = $detail ? mysqli_query($koneksi, "
    SELECT * FROM log_keluar_pekerjaan
    WHERE id_pekerjaan = '" . (int) $detail["id_pekerjaan"] . "'
    ORDER BY id_log ASC
") : false;

if ($query_log) {
    while ($row_log = mysqli_fetch_assoc($query_log)) {
        $log_keluar[(int) $row_log["id_user"]][] = $row_log;
    }
}

$jk_berjalan     = false;
$jk_belum_mulai  = false;
$jk_mulai        = "";
$jk_akhir        = null;
$jk_data         = ["hari" => [], "total_menit" => 0];
$jk_telat        = false;
$jk_telat_detik  = 0;
$jk_baris        = [];
$jk_total_bersih = 0;
$jk_tampil_nama  = false;
$jk_t_target     = 0;
$jk_t_akhir      = 0;

if ($detail) {

    $jk_mulai = $detail["tanggal_mulai"] ?? "";

    if (in_array($detail["status"], ["Menunggu Konfirmasi", "Selesai"], true)) {
        if (!empty($bukti_list)) {
            $jk_bukti_terakhir = $bukti_list[array_key_last($bukti_list)];
            $jk_akhir = $jk_bukti_terakhir["tanggal_upload"] ?? null;
        }
    } else {
        $jk_akhir    = date("Y-m-d H:i:s");
        $jk_berjalan = true;
    }

    $jk_belum_mulai = (!empty($jk_mulai) && strtotime($jk_mulai) > time());

    if ($jk_akhir && !empty($jk_mulai) && !$jk_belum_mulai) {
        $jk_data = jk_hitung_harian($jk_mulai, $jk_akhir);

        /* terlambat = bukti dikirim (atau sekarang) melewati target selesai */
        $jk_t_target = strtotime((string) ($detail["target_selesai"] ?? ""));
        $jk_t_akhir  = strtotime((string) $jk_akhir);

        if ($jk_t_target && $jk_t_target > strtotime($jk_mulai) && $jk_t_akhir > $jk_t_target) {
            $jk_telat       = true;
            $jk_telat_detik = $jk_t_akhir - $jk_t_target;
        }

        /* riwayat keluar / gabung lagi per anggota */
        $jk_interval = [];
        $jk_event    = [];

        foreach ($daftar_karyawan as $anggota_jk) {
            $uid_jk = (int) ($anggota_jk["id_user"] ?? 0);
            $jk_interval[$uid_jk] = [];

            foreach (($log_keluar[$uid_jk] ?? []) as $lg) {
                $t_keluar = strtotime((string) $lg["waktu_keluar"]);
                if (!$t_keluar) {
                    continue;
                }
                $t_gabung = !empty($lg["waktu_gabung"]) ? strtotime((string) $lg["waktu_gabung"]) : 0;

                $jk_interval[$uid_jk][] = [$t_keluar, $t_gabung ? $t_gabung : PHP_INT_MAX];
                $jk_event[date("Y-m-d", $t_keluar)][] = [
                    "nama"   => $anggota_jk["nama"] ?? "-",
                    "keluar" => $t_keluar,
                    "gabung" => $t_gabung,
                ];
            }
        }

        /* satu baris per hari: jam kerja bersih (sudah dikurangi waktu keluar) */
        $jk_tampil_nama = count($daftar_karyawan) > 1;
        $kunci_target   = $jk_t_target ? date("Y-m-d", $jk_t_target) : "";

        foreach ($jk_data["hari"] as $hari_jk) {
            if ($hari_jk["menit"] <= 0) {
                continue;
            }

            $kunci  = date("Y-m-d", $hari_jk["tanggal"]);
            $kurang = jk_menit_keluar_serentak($hari_jk["dari_ts"], $hari_jk["sampai_ts"], $jk_interval);
            $bersih = max(0, $hari_jk["menit"] - $kurang);

            $event_hari = $jk_event[$kunci] ?? [];
            usort($event_hari, function ($a, $b) {
                return $a["keluar"] <=> $b["keluar"];
            });

            $jk_baris[] = [
                "tanggal" => $hari_jk["tanggal"],
                "dari"    => $hari_jk["dari"],
                "sampai"  => $hari_jk["sampai"],
                "bersih"  => $bersih,
                "event"   => $event_hari,
                "lewat"   => ($jk_telat && $kunci > $kunci_target),
            ];
            $jk_total_bersih += $bersih;
        }
    }
}

/* data untuk tab status & ikon */
$qs_keyword = $keyword !== "" ? "&keyword=" . urlencode($keyword) : "";

$ikon_individu = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg>';
$ikon_tim      = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-1a4 4 0 0 0-3-3.87M16 4.13a4 4 0 0 1 0 7.75"/></svg>';

$daftar_status = [
    "menunggu"  => ["Menunggu konfirmasi", $count_menunggu, "Menunggu"],
    "perbaikan" => ["Perlu perbaikan", $count_perbaikan, "Perbaikan"],
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
<title>Monitoring Order Perbaikan - SIMO</title>
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

    /* warna status disamakan dengan halaman laporan */
    --b-belum-bg: #eef0f3;     --b-belum: #667085;
    --b-sedang-bg: #edf3ff;    --b-sedang: #3f65b5;
    --b-menunggu-bg: #fff5dc;  --b-menunggu: #a46b00;
    --b-perbaikan-bg: #fde5e5; --b-perbaikan: #c94242;
    --b-peralihan-bg: #efe9ff; --b-peralihan: #6a4bb0;
    --b-selesai-bg: #e9f8f0;   --b-selesai: #168353;

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

    --b-belum-bg: #34383e;     --b-belum: #c5cbd3;
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
.tabel-pekerjaan td[data-label="Order Masuk"],
.tabel-pekerjaan td[data-label="Rencana Dikerjakan"],
.tabel-pekerjaan td[data-label="Target Selesai"],
.tabel-pekerjaan td[data-label="Selesai Dikerjakan"] { white-space: nowrap; }
.nama-karyawan { line-height: 1.4; white-space: nowrap; }

/* kolom Uraian: dipotong 2 baris supaya tabel tidak terlalu tinggi */
.uraian-teks {
    max-width: 220px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.5;
}

.item-menunggu { font-size: 12.5px; font-style: italic; color: var(--text-3); white-space: nowrap; }

.tabel-pekerjaan tbody tr:last-child td { border-bottom: none; }
.tabel-pekerjaan tbody tr.row-klik { cursor: pointer; }
.tabel-pekerjaan tbody tr.row-klik:hover td { background: var(--surface-2); }
tr.row-klik:focus { outline: none; }
tr.row-klik:focus-visible td { background: var(--brand-soft); }

/* status pada tabel: sama dengan halaman laporan (kotak kecil, tanpa titik) */
.status-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}

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

/* JAM KERJA PER HARI */
.jk-badge { display: inline-block; margin-bottom: 12px; padding: 4px 11px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--b-sedang-bg); color: var(--b-sedang); }

.jk-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.jk-table th { padding: 10px 0; text-align: left; font-size: 12.5px; font-weight: 600; color: var(--text-3); border-bottom: 1px solid var(--line); }
.jk-table td { padding: 13px 0; color: var(--text-2); border-bottom: 1px solid var(--line-soft); }
.jk-table th:last-child,
.jk-table td:last-child { text-align: right; }
.jk-table td.jk-durasi { font-weight: 600; color: var(--text); white-space: nowrap; }
.jk-table tfoot td { padding-top: 16px; border-bottom: 0; font-size: 15px; font-weight: 700; color: var(--text); }
.jk-table tfoot td.jk-total { color: var(--brand-ink); }
.jk-table td.jk-tgl small { display: block; margin-top: 2px; font-size: 12.5px; font-weight: 400; color: var(--text-3); }
.jk-table tr.jk-lewat td.jk-tgl { color: var(--b-perbaikan); }
.jk-table th.jk-col-ev, .jk-table td.jk-col-ev { text-align: left; padding-left: 12px; }
.jk-ev { white-space: nowrap; }
.jk-ev + .jk-ev { margin-top: 2px; }
.jk-ev-nama { color: var(--text-3); }
.jk-table tfoot tr.jk-telat-foot td { padding-top: 0; font-size: 14px; font-weight: 600; color: var(--b-perbaikan); }

@media (max-width: 640px) {
    .jk-table { font-size: 13.5px; }
    .jk-table td, .jk-table th { padding-right: 8px; }
    .jk-table th:last-child, .jk-table td:last-child { padding-right: 0; }
}

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

.foto-admin { display: flex; flex-wrap: wrap; gap: 8px; }

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

/* RIWAYAT PERALIHAN */
.transfer-list { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; margin-bottom: 6px; }

.transfer-info {
    padding: 13px 15px;
    border: 1px solid var(--info-line);
    border-radius: 10px;
    background: var(--info-bg);
}

.transfer-info-title { font-size: 13px; font-weight: 700; color: var(--info-ink); margin-bottom: 7px; }
.transfer-info p { font-size: 13px; color: var(--text-2); line-height: 1.6; }
.transfer-info strong { font-weight: 700; color: var(--text); }

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
.modal,
.delete-modal-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(18, 24, 34, .5);
    z-index: 10000;
}

.modal.show,
.delete-modal-overlay.show { display: flex; }

.modal-box,
.delete-modal-box {
    width: 400px;
    max-width: 100%;
    padding: 28px 26px 24px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    text-align: center;
    box-shadow: var(--shadow-modal);
}

.delete-modal-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--b-perbaikan-bg);
    color: var(--b-perbaikan);
}

.delete-modal-icon svg { width: 24px; height: 24px; stroke: currentColor; }

.modal-box h3,
.delete-modal-box h3 { margin-bottom: 8px; font-size: 17px; font-weight: 700; color: var(--text); }

.modal-box p,
.delete-modal-box p {
    margin: 0 auto 22px;
    max-width: 320px;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--text-3);
}

.modal-actions,
.delete-modal-actions { display: flex; gap: 10px; }

.modal-btn,
.delete-modal-btn {
    flex: 1;
    min-height: 42px;
    padding: 10px 16px;
    border-radius: 999px;
    border: 1px solid var(--line);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
}

.modal-cancel,
.delete-modal-cancel { background: var(--surface); color: var(--text-2); }
.modal-cancel:hover,
.delete-modal-cancel:hover { background: var(--surface-2); }

.modal-ok { background: var(--ok); color: #fff; border-color: var(--ok); }
.modal-ok:hover { background: var(--ok-hover); border-color: var(--ok-hover); }

.delete-modal-confirm { background: var(--brand); color: #fff; border-color: var(--brand); }
.delete-modal-confirm:hover { background: var(--brand-hover); border-color: var(--brand-hover); }

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
    .nama-list { text-align: left; }

    .uraian-teks { max-width: none; -webkit-line-clamp: 3; }
    .ket-teks { max-width: none; }
    .item-menunggu { white-space: normal; }

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
<?php if ($detail): ?>
    <div>
        <h2>Detail Pekerjaan</h2>
        <p>Informasi lengkap pekerjaan order perbaikan pada divisi <?= e($nama_divisi); ?>.</p>
    </div>
    <a href="<?= $halaman; ?>?status=<?= e($status_kembali); ?>&jenis=<?= e($jenis); ?>" class="btn-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5"></path>
            <path d="M12 19l-7-7 7-7"></path>
        </svg>
        Kembali
    </a>
<?php else: ?>
    <div>
        <h2>Monitoring: Order Perbaikan</h2>
        <p>Pantau dan konfirmasi pekerjaan order perbaikan pada divisi <?= e($nama_divisi); ?>.</p>
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

<?php if ($detail): ?>

<!-- ===================== DETAIL PEKERJAAN ===================== -->
<div class="card detail-card">

    <div class="job-top">
        <div class="job-name"><?= e($detail["nama_pekerjaan"]); ?></div>
        <div class="job-status <?= status_class($detail["status"]); ?>"><?= e($detail["status"]); ?></div>
    </div>

    <?php if (!empty($pengalihan_list)): ?>
    <div class="transfer-list">
    <?php foreach ($pengalihan_list as $index => $p): ?>
        <div class="transfer-info">
            <div class="transfer-info-title"><?= count($pengalihan_list) > 1 ? "Peralihan ke-" . ($index + 1) : "Informasi Peralihan Pekerjaan"; ?></div>
            <p>Pekerjaan ini dialihkan oleh <strong><?= e($p["nama_pengaju"] ?? "-"); ?></strong> kepada <strong><?= e($p["nama_pengganti"] ?? "-"); ?></strong>.</p>
            <?php if (!empty($p["tanggal_pengajuan"])): ?>
                <p>Tanggal peralihan: <?= date("d M Y, H:i", strtotime($p["tanggal_pengajuan"])); ?></p>
            <?php endif; ?>
            <?php if (!empty($p["deskripsi_pengalihan"])): ?>
                <p>Alasan: <?= nl2br(e($p["deskripsi_pengalihan"])); ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="detail-grid">

        <div class="detail-item">
            <div class="detail-label">Nomor Order</div>
            <div class="detail-value"><?= !empty($detail["nomor_order"]) ? e($detail["nomor_order"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Pelapor</div>
            <div class="detail-value"><?= !empty($detail["nama_pelapor"]) ? e($detail["nama_pelapor"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Departemen Asal</div>
            <div class="detail-value"><?= !empty($detail["departemen_asal"]) ? e($detail["departemen_asal"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Jenis Pekerjaan</div>
            <div class="detail-value"><?= !empty($detail["jenis_pekerjaan"]) ? e($detail["jenis_pekerjaan"]) : "-"; ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Divisi</div>
            <div class="detail-value"><?= e($detail["nama_divisi"]); ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Anggota Tim</div>
            <?php if (!empty($daftar_karyawan)): ?>
                <div class="employee-list">
                    <?php foreach ($daftar_karyawan as $i => $karyawan): ?>
                        <div class="employee">
                            <span class="employee-number"><?= $i + 1; ?></span>
                            <span class="employee-name"><?= e($karyawan["nama"]); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="detail-value">-</div>
            <?php endif; ?>
        </div>

        <div class="detail-item">
            <div class="detail-label">Order Masuk</div>
            <div class="detail-value"><?= e(format_tgl_jam($detail["tanggal_order_masuk"] ?? null)); ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Rencana Dikerjakan</div>
            <div class="detail-value"><?= e(format_tgl_jam($detail["tanggal_mulai"] ?? null)); ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Target Selesai</div>
            <div class="detail-value"><?= e(format_tgl_jam($detail["target_selesai"] ?? null)); ?></div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Lokasi</div>
            <div class="detail-value"><?= !empty($detail["lokasi"]) ? e($detail["lokasi"]) : "-"; ?></div>
        </div>

    </div>

    <div class="description-area">
        <div class="description-title">Deskripsi</div>
        <div class="description-text">
            <?= !empty($detail["deskripsi"]) ? nl2br(e($detail["deskripsi"])) : "Tidak ada deskripsi pekerjaan."; ?>
        </div>
    </div>

    <?php if (!empty($detail["uraian_kerusakan"])): ?>
    <div class="description-area">
        <div class="description-title">Uraian Kerusakan</div>
        <div class="description-text"><?= nl2br(e($detail["uraian_kerusakan"])); ?></div>
    </div>
    <?php endif; ?>

    <?php
    /* foto yang diunggah admin saat membuat pekerjaan */
    $nama_foto_admin = foto_pekerjaan_admin($detail);
    $url_foto_admin  = [];
    foreach ($nama_foto_admin as $satu_foto) {
        $u = cari_foto_bukti($satu_foto);
        if ($u !== "") {
            $url_foto_admin[] = $u;
        }
    }
    ?>
    <?php if (!empty($nama_foto_admin)): ?>
    <div class="description-area">
        <div class="description-title">Foto Pekerjaan</div>
        <?php if (!empty($url_foto_admin)): ?>
            <div class="foto-admin">
                <?php foreach ($url_foto_admin as $u): ?>
                    <img src="<?= e($u); ?>" class="evidence-photo" alt="Foto pekerjaan" onclick="bukaFoto(this.src)">
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-evidence">Foto tercatat di sistem, tetapi file fotonya tidak ditemukan.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- JAM KERJA PER HARI -->
<div class="card detail-card">

    <h3 class="section-heading">Jam Kerja</h3>
    <p class="section-desc">Dihitung pada jam kerja 08:00 sampai 16:00, Senin sampai Jumat, dan sudah dikurangi waktu karyawan keluar.</p>

    <?php if (empty($jk_mulai)): ?>

        <div class="empty-evidence">Tanggal mulai pekerjaan belum diisi.</div>

    <?php elseif ($jk_belum_mulai): ?>

        <div class="empty-evidence">Pekerjaan belum dimulai (mulai <?= e(date("d M Y, H:i", strtotime($jk_mulai))); ?>).</div>

    <?php elseif (!$jk_akhir): ?>

        <div class="empty-evidence">Belum ada bukti yang dikirim, jam kerja belum bisa dihitung.</div>

    <?php elseif (empty($jk_data["hari"])): ?>

        <div class="empty-evidence">Belum ada jam kerja yang tercatat.</div>

    <?php else: ?>

        <?php if ($jk_berjalan): ?>
            <span class="jk-badge">Masih berjalan, dihitung sampai sekarang</span>
        <?php endif; ?>

        <table class="jk-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th class="jk-col-ev">Keluar &rarr; gabung lagi</th>
                    <th>Jam kerja</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jk_baris as $baris_jk): ?>
                <tr class="<?= $baris_jk["lewat"] ? "jk-lewat" : ""; ?>">
                    <td class="jk-tgl">
                        <?= e(jk_tanggal($baris_jk["tanggal"])); ?>
                        <small><?= e($baris_jk["dari"]); ?> - <?= e($baris_jk["sampai"]); ?></small>
                    </td>
                    <td class="jk-col-ev">
                        <?php if (empty($baris_jk["event"])): ?>
                            -
                        <?php else: ?>
                            <?php foreach ($baris_jk["event"] as $ev): ?>
                                <?php
                                $ev_gabung = "belum gabung";
                                if ($ev["gabung"]) {
                                    $ev_gabung = date("Y-m-d", $ev["gabung"]) === date("Y-m-d", $ev["keluar"])
                                        ? date("H:i", $ev["gabung"])
                                        : jk_waktu($ev["gabung"]);
                                }
                                ?>
                                <div class="jk-ev">
                                    <?php if ($jk_tampil_nama): ?><span class="jk-ev-nama"><?= e($ev["nama"]); ?></span> <?php endif; ?>
                                    <?= e(date("H:i", $ev["keluar"])); ?> &rarr; <?= e($ev_gabung); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="jk-durasi"><?= e(jk_format($baris_jk["bersih"])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total jam kerja</td>
                    <td class="jk-total"><?= e(jk_format($jk_total_bersih)); ?></td>
                </tr>
                <?php if ($jk_telat): ?>
                <tr class="jk-telat-foot">
                    <td colspan="2">Terlambat dari target selesai (<?= e(jk_waktu($jk_t_target)); ?>)</td>
                    <td><?= e(jk_lama_telat($jk_telat_detik)); ?></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>

    <?php endif; ?>

</div>

<!-- BUKTI PEKERJAAN -->
<div class="card detail-card">

    <h3 class="section-heading">Bukti Pekerjaan</h3>
    <p class="section-desc">Bukti pekerjaan yang dikirim oleh karyawan, diurutkan dari pengiriman pertama (nomor 1). Foto sebelum dan sesudah pengerjaan ditampilkan terpisah.</p>

    <?php if (!empty($bukti_list)): ?>
        <div class="evidence-list">
            <?php foreach ($bukti_list as $i => $bukti): ?>
                <?php
                /* foto hanya disimpan 24 jam sejak bukti dikirim */
                $waktu_upload        = !empty($bukti["tanggal_upload"]) ? strtotime($bukti["tanggal_upload"]) : 0;
                $foto_masih_tersedia = $waktu_upload && time() < ($waktu_upload + 24 * 60 * 60);

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
                                    if ($foto_masih_tersedia) {
                                        foreach ($nama_list as $satu_foto) {
                                            $u = cari_foto_bukti($satu_foto);
                                            if ($u !== "") {
                                                $url_grup[] = $u;
                                            }
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
                                        <?php elseif (!$foto_masih_tersedia): ?>
                                            <div class="empty-evidence">Foto sudah melewati masa penyimpanan 24 jam.</div>
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

<!-- KONFIRMASI -->
<div class="card detail-card">

<?php if ($detail["status"] === "Menunggu Konfirmasi"): ?>

    <div class="detail-header">
        <h3>Konfirmasi</h3>
        <p>Periksa bukti sebelum menentukan hasil pekerjaan.</p>
    </div>

    <div class="action-area">
        <form method="POST" class="form-konfirmasi">
            <input type="hidden" name="aksi" value="konfirmasi">
            <input type="hidden" name="id_pekerjaan" value="<?= intval($detail["id_pekerjaan"]); ?>">
            <button type="submit" class="btn-confirm">Konfirmasi Selesai</button>
        </form>
        <button type="button" class="btn-repair" onclick="showRepair()">Perlu Perbaikan</button>
    </div>

    <div id="repairForm" style="display:none;margin-top:12px;">
        <form method="POST">
            <input type="hidden" name="aksi" value="perbaikan">
            <input type="hidden" name="id_pekerjaan" value="<?= intval($detail["id_pekerjaan"]); ?>">
            <textarea name="catatan_perbaikan" class="action-note" placeholder="Tuliskan catatan perbaikan..." required></textarea>
            <button type="submit" class="btn-repair">Kirim Perbaikan</button>
        </form>
    </div>

<?php elseif ($detail["status"] === "Perlu Perbaikan"): ?>

    <div class="detail-header">
        <h3>Konfirmasi</h3>
        <p>Pekerjaan sedang diperbaiki. Konfirmasi bila perbaikan sudah sesuai.</p>
    </div>

    <div class="action-area">
        <form method="POST" class="form-konfirmasi">
            <input type="hidden" name="aksi" value="konfirmasi">
            <input type="hidden" name="id_pekerjaan" value="<?= intval($detail["id_pekerjaan"]); ?>">
            <button type="submit" class="btn-confirm">Konfirmasi Selesai</button>
        </form>
    </div>

<?php else: ?>

    <div class="status-info">Pekerjaan ini tidak sedang menunggu konfirmasi.</div>

<?php endif; ?>

</div>

<?php else: ?>

<!-- ===================== DAFTAR ===================== -->
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
                <input type="text" name="keyword" value="<?= e($keyword); ?>" placeholder="Cari pekerjaan, pelapor, karyawan, atau nomor order" aria-label="Cari">
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
                <th>Nama Pekerjaan</th>
                <th>Pelapor</th>
                <th>Dept</th>
                <th>Uraian Kerusakan</th>
                <th>Dikerjakan Oleh</th>
                <th>Order Masuk</th>
                <th>Rencana Dikerjakan</th>
                <th>Target Selesai</th>
                <th>Selesai Dikerjakan</th>
                <th>Status</th>
                <th>Item Pekerjaan</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>

        <?php
        $no = 1;
        if ($query_monitoring && $total_baris > 0):
            while ($row = mysqli_fetch_assoc($query_monitoring)):

                /* Selesai Dikerjakan = jam bukti terakhir dikirim, hanya jika pekerjaan sudah dikirim / selesai */
                $selesai_pekerjaan = in_array($row["status"], ["Menunggu Konfirmasi", "Selesai"], true)
                    ? ($row["jam_bukti_terakhir"] ?? null)
                    : null;

        ?>
            <tr class="row-klik" tabindex="0" title="Klik untuk melihat detail" data-id="<?= intval($row["id_pekerjaan"]); ?>">
                <td class="col-no" data-label="No"><?= $no++; ?></td>
                <td data-label="No. Order"><?= !empty($row["nomor_order"]) ? e($row["nomor_order"]) : "-"; ?></td>
                <td class="col-nama" data-label="Nama Pekerjaan"><div class="ket-teks"><?= e($row["nama_pekerjaan"]); ?></div></td>
                <td data-label="Pelapor"><?= !empty($row["nama_pelapor"]) ? e($row["nama_pelapor"]) : (!empty($row["nama_pelapor_f"]) ? e($row["nama_pelapor_f"]) : "-"); ?></td>
                <td data-label="Dept"><?= !empty($row["departemen_asal"]) ? e($row["departemen_asal"]) : (!empty($row["dept_f"]) ? e($row["dept_f"]) : "-"); ?></td>
                <td data-label="Uraian Kerusakan"><div class="uraian-teks"><?= !empty($row["uraian_kerusakan"]) ? e($row["uraian_kerusakan"]) : (!empty($row["deskripsi"]) ? e($row["deskripsi"]) : "-"); ?></div></td>
                <td data-label="Dikerjakan Oleh"><div class="nama-list"><?= daftar_nama($row["nama_karyawan"] ?? ""); ?></div></td>
                <td data-label="Order Masuk"><?= e(format_tgl_jam($row["tanggal_order_masuk"] ?? null)); ?></td>
                <td data-label="Rencana Dikerjakan"><?= e(format_tgl_jam($row["tanggal_mulai"] ?? null)); ?></td>
                <td data-label="Target Selesai"><?= e(format_tgl_jam($row["target_selesai"] ?? null)); ?></td>
                <td data-label="Selesai Dikerjakan"><?= e(format_tgl_jam($selesai_pekerjaan)); ?></td>
                <td data-label="Status"><span class="status-badge <?= status_class(status_tabel($row["status"])); ?>"><?= e(status_tabel($row["status"])); ?></span></td>
                <td data-label="Item Pekerjaan"><?php if (!empty($row["item_terakhir"])): ?><div class="uraian-teks"><?= e($row["item_terakhir"]); ?></div><?php else: ?><span class="item-menunggu">Belum ada</span><?php endif; ?></td>
                <td data-label="Aksi">
                    <div class="aksi-group">
                        <a href="<?= $halaman; ?>?id=<?= intval($row["id_pekerjaan"]); ?>&status=<?= e($filter_status); ?>&jenis=<?= e($jenis); ?>" class="btn-aksi btn-detail" title="Lihat detail" aria-label="Lihat detail">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </a>
                        <button type="button" class="btn-aksi btn-hapus tombolHapus" title="Hapus pekerjaan" aria-label="Hapus pekerjaan" data-id="<?= intval($row["id_pekerjaan"]); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6h18"/>
                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            </svg>
                        </button>
                    </div>
                </td>
            </tr>
        <?php
            endwhile;
        else:
        ?>
            <tr>
                <td colspan="14" class="empty-row">
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 13h6M9 17h4"/></svg>
                        <strong>Tidak ada pekerjaan yang ditemukan</strong>
                        <span>Coba ganti status, jenis pekerjaan, atau kata kunci pencarian.</span>
                    </div>
                </td>
            </tr>
        <?php endif; ?>

        </tbody>
        </table>
    </div>

    <div class="table-foot">
        <span>
            Menampilkan <strong><?= $total_baris; ?></strong> pekerjaan
            <strong>order perbaikan <?= e(strtolower($jenis)); ?></strong>
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

<!-- FORM HAPUS -->
<form method="POST" id="formHapus" style="display:none">
    <input type="hidden" name="aksi" value="hapus">
    <input type="hidden" name="id_pekerjaan" id="idPekerjaanHapus" value="">
</form>

<!-- MODAL HAPUS -->
<div class="delete-modal-overlay" id="deleteModal">
    <div class="delete-modal-box" role="dialog" aria-modal="true" aria-labelledby="judulHapus">
        <div class="delete-modal-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18"/>
                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v5"/>
                <path d="M14 11v5"/>
            </svg>
        </div>
        <h3 id="judulHapus">Hapus pekerjaan?</h3>
        <p>Pekerjaan ini beserta bukti, riwayat, dan data peralihannya akan dihapus permanen.</p>
        <div class="delete-modal-actions">
            <button type="button" class="delete-modal-btn delete-modal-cancel" id="batalHapus">Batal</button>
            <button type="button" class="delete-modal-btn delete-modal-confirm" id="konfirmasiHapus">Ya, hapus</button>
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
        <h3>Konfirmasi Pekerjaan?</h3>
        <p>Pekerjaan akan ditandai sebagai selesai dan seluruh anggota tim akan berstatus selesai.</p>
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

/* HAPUS PEKERJAAN */
(function () {
    const deleteModal = document.getElementById("deleteModal");
    const inputId = document.getElementById("idPekerjaanHapus");
    const formHapus = document.getElementById("formHapus");

    function tutup() {
        deleteModal.classList.remove("show");
        inputId.value = "";
    }

    document.querySelectorAll(".tombolHapus").forEach(function (tombol) {
        tombol.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            inputId.value = this.dataset.id || "";
            deleteModal.classList.add("show");
        });
    });

    document.getElementById("batalHapus").addEventListener("click", tutup);
    document.getElementById("konfirmasiHapus").addEventListener("click", function () {
        if (inputId.value !== "") { formHapus.submit(); }
    });
    deleteModal.addEventListener("click", function (event) {
        if (event.target === deleteModal) { tutup(); }
    });
})();

/* ESC menutup semua popup */
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeConfirm();
        tutupFoto(null);
        document.getElementById("deleteModal").classList.remove("show");
    }
});

</script>

</body>
</html>
<?php
/* HALAMAN Non Order - berdiri sendiri, form & tabel bisa diubah tanpa memengaruhi halaman lain */
$tipe_order_halaman = "Non Order";
$file_halaman = "non_order.php";

include "../config/session.php";

if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Admin") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/* true = pesan error database asli tampil di layar */
$mode_debug = false;

/* Nomor order hanya dipakai untuk Order Perbaikan */
$pakai_nomor = ($tipe_order_halaman === "Order Perbaikan");

/* =========================================================
   DATA ADMIN
========================================================= */
$id_admin = (int) $_SESSION["id_user"];

$query_admin = mysqli_query($koneksi, "SELECT * FROM users WHERE id_user='$id_admin' LIMIT 1");

if (!$query_admin || mysqli_num_rows($query_admin) == 0) {
    header("Location: ../index.php");
    exit;
}

$admin = mysqli_fetch_assoc($query_admin);
$id_divisi = (int) $admin["id_divisi"];
$nama_admin = $admin["nama"];

/* =========================================================
   DATA DIVISI
========================================================= */
$query_divisi = mysqli_query($koneksi, "SELECT * FROM divisi WHERE id_divisi='$id_divisi' LIMIT 1");
$divisi = mysqli_fetch_assoc($query_divisi);
$nama_divisi = $divisi["nama_divisi"] ?? "-";

/* =========================================================
   PASTIKAN KOLOM PENDUKUNG ADA
========================================================= */
function simo_pastikan_kolom($koneksi, $tabel, $kolom, $definisi)
{
    try {
        $k = mysqli_real_escape_string($koneksi, $kolom);
        $cek = mysqli_query($koneksi, "SHOW COLUMNS FROM `$tabel` LIKE '$k'");
        if ($cek && mysqli_num_rows($cek) === 0) {
            mysqli_query($koneksi, "ALTER TABLE `$tabel` ADD COLUMN `$kolom` $definisi");
        }
    } catch (Throwable $e) {
        error_log("Gagal menambah kolom $tabel.$kolom: " . $e->getMessage());
    }
}

simo_pastikan_kolom($koneksi, "pekerjaan", "uraian_kerusakan", "TEXT NULL");
simo_pastikan_kolom($koneksi, "pekerjaan", "nama_pelapor", "VARCHAR(100) NULL");
simo_pastikan_kolom($koneksi, "pekerjaan", "departemen_asal", "VARCHAR(100) NULL");
simo_pastikan_kolom($koneksi, "pekerjaan", "foto", "VARCHAR(255) NULL");
simo_pastikan_kolom($koneksi, "pekerjaan", "nama_peralatan", "VARCHAR(150) NULL");
simo_pastikan_kolom($koneksi, "pekerjaan", "tanggal_order_masuk", "DATETIME NULL");

function simo_lebarkan_jenis($koneksi)
{
    try {
        $cek = mysqli_query($koneksi, "SHOW COLUMNS FROM `pekerjaan` LIKE 'jenis_pekerjaan'");
        $c = $cek ? mysqli_fetch_assoc($cek) : null;
        if ($c && stripos($c["Type"], "varchar(255)") === false) {
            mysqli_query($koneksi, "ALTER TABLE `pekerjaan` MODIFY `jenis_pekerjaan` VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
        error_log("Gagal melebarkan jenis_pekerjaan: " . $e->getMessage());
    }
}

simo_lebarkan_jenis($koneksi);

function simo_e($t)
{
    return htmlspecialchars((string) $t, ENT_QUOTES, "UTF-8");
}

/* =========================================================
   PESAN
========================================================= */
$pesan = "";
$tipe_pesan = "";

$dir_foto_pekerjaan = __DIR__ . "/../uploads/pekerjaan/";

$daftar_jenis = ["Mesin", "Infrastruktur", "Peralatan", "Armada Nopol"];

$old = [
    "nomor_order" => "", "nama_pekerjaan" => "", "nama_pelapor" => "",
    "departemen_asal" => "", "lokasi" => "", "nama_peralatan" => "", "uraian_kerusakan" => "",
    "tanggal_order_masuk" => "", "tanggal_mulai" => "", "target_selesai" => "",
    "jumlah_karyawan" => "1",
];
$id_terpilih_old = [];
$jenis_old = [];

/* =========================================================
   TAMBAH PEKERJAAN
========================================================= */
if (isset($_POST["simpan_pekerjaan"])) {

    foreach ($old as $k => $v) {
        $old[$k] = trim($_POST[$k] ?? $v);
    }

    /* tipe ditentukan oleh halaman, bukan dari form */
    $tipe_order = $tipe_order_halaman;

    $nama_pekerjaan = mysqli_real_escape_string($koneksi, $old["nama_pekerjaan"]);
    $nama_pelapor = mysqli_real_escape_string($koneksi, $old["nama_pelapor"]);
    $departemen_asal = mysqli_real_escape_string($koneksi, $old["departemen_asal"]);
    $nomor_order = $pakai_nomor ? $old["nomor_order"] : "";
    $lokasi = mysqli_real_escape_string($koneksi, $old["lokasi"]);
    $nama_peralatan = mysqli_real_escape_string($koneksi, $old["nama_peralatan"]);
    $uraian_kerusakan = mysqli_real_escape_string($koneksi, $old["uraian_kerusakan"]);

    /* JENIS PERBAIKAN */
    $jenis_post = $_POST["jenis_perbaikan"] ?? [];
    if (!is_array($jenis_post)) {
        $jenis_post = [];
    }
    $jenis_terpilih = array_values(array_intersect($daftar_jenis, array_map("strval", $jenis_post)));
    $jenis_old = $jenis_terpilih;
    $jenis_pekerjaan = implode(", ", $jenis_terpilih);

    /* input datetime-local mengirim format Y-m-dTH:i */
    $order_masuk_obj = DateTime::createFromFormat('Y-m-d\TH:i', $old["tanggal_order_masuk"]);
    $tanggal_mulai_obj = DateTime::createFromFormat('Y-m-d\TH:i', $old["tanggal_mulai"]);
    $target_selesai_obj = DateTime::createFromFormat('Y-m-d\TH:i', $old["target_selesai"]);

    $tanggal_order_masuk = "";
    $tanggal_mulai = "";
    $target_selesai = "";

    if ($order_masuk_obj && $tanggal_mulai_obj && $target_selesai_obj) {
        $tanggal_order_masuk = mysqli_real_escape_string($koneksi, $order_masuk_obj->format('Y-m-d H:i:s'));
        $tanggal_mulai = mysqli_real_escape_string($koneksi, $tanggal_mulai_obj->format('Y-m-d H:i:s'));
        $target_selesai = mysqli_real_escape_string($koneksi, $target_selesai_obj->format('Y-m-d H:i:s'));
    }

    $deskripsi = "";

    /* FOTO (opsional) */
    $foto_error = "";
    $foto_ext = "";

    if (isset($_FILES["foto"]) && $_FILES["foto"]["error"] !== UPLOAD_ERR_NO_FILE) {

        $f = $_FILES["foto"];

        if ($f["error"] !== UPLOAD_ERR_OK) {
            $foto_error = "Foto gagal diunggah (kemungkinan terlalu besar). Coba foto lain.";
        } elseif ($f["size"] > 5 * 1024 * 1024) {
            $foto_error = "Ukuran foto maksimal 5 MB.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($f["tmp_name"]);
            $boleh = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

            if (!isset($boleh[$mime])) {
                $foto_error = "Format foto harus JPG, PNG, atau WEBP.";
            } else {
                $foto_ext = $boleh[$mime];
            }
        }
    }

    /* KARYAWAN */
    $jumlah_karyawan = (int) $old["jumlah_karyawan"];
    $id_karyawan_post = $_POST["id_karyawan"] ?? [];

    if (!is_array($id_karyawan_post)) {
        $id_karyawan_post = [];
    }

    $id_karyawan = [];

    foreach ($id_karyawan_post as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $id_karyawan[] = $id;
        }
    }

    $id_karyawan = array_values(array_unique($id_karyawan));
    $id_terpilih_old = array_map("strval", $id_karyawan);

    $post_kosong = empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0;

    /* VALIDASI */
    if ($post_kosong) {
        $pesan = "Ukuran data terlalu besar. Kurangi ukuran foto.";
        $tipe_pesan = "error";

    } elseif ($old["nama_pekerjaan"] === "" || mb_strlen($old["nama_pekerjaan"]) > 150) {
        $pesan = "Nama pekerjaan wajib diisi (maksimal 150 karakter).";
        $tipe_pesan = "error";

    } elseif (mb_strlen($old["nama_pelapor"]) > 100) {
        $pesan = "Nama pelapor maksimal 100 karakter.";
        $tipe_pesan = "error";

    } elseif (mb_strlen($old["departemen_asal"]) > 100) {
        $pesan = "Nama departemen maksimal 100 karakter.";
        $tipe_pesan = "error";

    } elseif (mb_strlen($nomor_order) > 50) {
        $pesan = "Nomor order maksimal 50 karakter.";
        $tipe_pesan = "error";

    } elseif ($pakai_nomor && $nomor_order === "") {
        $pesan = "Nomor order wajib diisi.";
        $tipe_pesan = "error";

    } elseif (count($jenis_terpilih) !== 1) {
        $pesan = "Pilih satu jenis perbaikan.";
        $tipe_pesan = "error";

    } elseif (mb_strlen($old["nama_peralatan"]) > 150) {
        $pesan = "Nama peralatan maksimal 150 karakter.";
        $tipe_pesan = "error";

    } elseif ($old["lokasi"] === "") {
        $pesan = "Lokasi wajib diisi.";
        $tipe_pesan = "error";

    } elseif ($old["uraian_kerusakan"] === "") {
        $pesan = "Uraian Pekerjaan wajib diisi.";
        $tipe_pesan = "error";

    } elseif ($jumlah_karyawan < 1 || $jumlah_karyawan > 10) {
        $pesan = "Jumlah karyawan harus antara 1 sampai 10.";
        $tipe_pesan = "error";

    } elseif (count($id_karyawan) != $jumlah_karyawan) {
        $pesan = "Silakan pilih nama karyawan sesuai jumlah yang ditentukan.";
        $tipe_pesan = "error";

    } elseif (!$order_masuk_obj || !$tanggal_mulai_obj || !$target_selesai_obj) {
        $pesan = "Waktu dilaporkan, rencana dikerjakan, serta target selesai tidak valid.";
        $tipe_pesan = "error";

    } elseif ($tanggal_mulai_obj < $order_masuk_obj) {
        $pesan = "Rencana dikerjakan tidak boleh sebelum waktu dilaporkan.";
        $tipe_pesan = "error";

    } elseif ($target_selesai_obj <= $tanggal_mulai_obj) {
        $pesan = "Tanggal selesai harus setelah rencana dikerjakan.";
        $tipe_pesan = "error";

    } elseif ($foto_error != "") {
        $pesan = $foto_error;
        $tipe_pesan = "error";

    } else {

        $nomor_order_sql = mysqli_real_escape_string($koneksi, $nomor_order);
        $jenis_pekerjaan_sql = mysqli_real_escape_string($koneksi, $jenis_pekerjaan);
        $tipe_order_sql = mysqli_real_escape_string($koneksi, $tipe_order);

        /* CEK SEMUA KARYAWAN */
        $karyawan_valid = true;

        foreach ($id_karyawan as $id_user) {
            $id_user = (int) $id_user;

            $cek_karyawan = mysqli_query(
                $koneksi,
                "SELECT id_user FROM users
                 WHERE id_user='$id_user' AND role='Karyawan'
                 AND id_divisi='$id_divisi' AND status='Aktif' LIMIT 1"
            );

            if (!$cek_karyawan || mysqli_num_rows($cek_karyawan) == 0) {
                $karyawan_valid = false;
                break;
            }
        }

        if (!$karyawan_valid) {
            $pesan = "Terdapat karyawan yang tidak valid.";
            $tipe_pesan = "error";

        } else {

            mysqli_begin_transaction($koneksi);

            $path_foto = "";

            try {

                $nama_foto = "";

                if ($foto_ext !== "") {

                    if (!is_dir($dir_foto_pekerjaan) && !mkdir($dir_foto_pekerjaan, 0755, true)) {
                        throw new Exception("Folder foto tidak bisa dibuat.");
                    }

                    $nama_foto = "pkj_" . date("YmdHis") . "_" . bin2hex(random_bytes(4)) . "." . $foto_ext;
                    $path_foto = $dir_foto_pekerjaan . $nama_foto;

                    if (!move_uploaded_file($_FILES["foto"]["tmp_name"], $path_foto)) {
                        $path_foto = "";
                        throw new Exception("Gagal menyimpan foto.");
                    }
                }

                $foto_sql = $nama_foto === ""
                    ? "NULL"
                    : "'" . mysqli_real_escape_string($koneksi, $nama_foto) . "'";

                $id_karyawan_utama = (int) $id_karyawan[0];

                $query_simpan = mysqli_query(
                    $koneksi,
                    "INSERT INTO pekerjaan
                    (nama_pekerjaan, nama_pelapor, departemen_asal, lokasi, jenis_pekerjaan, nama_peralatan, uraian_kerusakan, tipe_order, nomor_order,
                     id_divisi, id_karyawan, id_pembuat, tanggal_order_masuk, tanggal_mulai, target_selesai, deskripsi, foto, status)
                    VALUES
                    ('$nama_pekerjaan', '$nama_pelapor', '$departemen_asal', '$lokasi', '$jenis_pekerjaan_sql', '$nama_peralatan', '$uraian_kerusakan',
                     '$tipe_order_sql', '$nomor_order_sql',
                     '$id_divisi', '$id_karyawan_utama', '$id_admin', '$tanggal_order_masuk', '$tanggal_mulai', '$target_selesai',
                     '$deskripsi', $foto_sql, 'Belum Dikerjakan')"
                );

                if (!$query_simpan) {
                    throw new Exception("Gagal menyimpan pekerjaan: " . mysqli_error($koneksi));
                }

                $id_pekerjaan_baru = mysqli_insert_id($koneksi);

                foreach ($id_karyawan as $id_user) {
                    $id_user = (int) $id_user;

                    $query_relasi = mysqli_query(
                        $koneksi,
                        "INSERT INTO pekerjaan_karyawan (id_pekerjaan, id_user, status_penugasan)
                         VALUES ('$id_pekerjaan_baru', '$id_user', 'Aktif')"
                    );

                    if (!$query_relasi) {
                        throw new Exception("Gagal menyimpan karyawan pekerjaan.");
                    }
                }

                $pesan_notifikasi = mysqli_real_escape_string(
                    $koneksi,
                    "Anda mendapat pekerjaan baru: " . $old["nama_pekerjaan"]
                );

                foreach ($id_karyawan as $id_user) {
                    $id_user = (int) $id_user;

                    mysqli_query(
                        $koneksi,
                        "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal)
                         VALUES ('$id_user', '$pesan_notifikasi', 'Belum Dibaca', NOW())"
                    );
                }

                mysqli_commit($koneksi);

                $view_baru = (count($id_karyawan) > 1) ? "tim" : "individu";

                header("Location: " . $file_halaman . "?pesan=tambah&baru=" . (int) $id_pekerjaan_baru . "&view=" . $view_baru);
                exit;

            } catch (Throwable $e) {
                mysqli_rollback($koneksi);

                if ($path_foto !== "" && is_file($path_foto)) {
                    unlink($path_foto);
                }

                error_log("Tambah pekerjaan (admin) gagal: " . $e->getMessage());

                $pesan = "Pekerjaan gagal ditambahkan."
                    . ($mode_debug ? " Penyebab: " . $e->getMessage() : "");
                $tipe_pesan = "error";
            }
        }
    }
}

/* =========================================================
   HAPUS PEKERJAAN (hanya yang bertipe halaman ini)
========================================================= */
if (isset($_GET["hapus"])) {

    $id_hapus = (int) $_GET["hapus"];
    $tipe_hapus_aman = mysqli_real_escape_string($koneksi, $tipe_order_halaman);

    $cek_pekerjaan_hapus = mysqli_query(
        $koneksi,
        "SELECT id_pekerjaan, foto FROM pekerjaan
         WHERE id_pekerjaan='$id_hapus' AND id_divisi='$id_divisi'
         AND tipe_order='$tipe_hapus_aman' LIMIT 1"
    );

    if ($cek_pekerjaan_hapus && mysqli_num_rows($cek_pekerjaan_hapus) > 0) {

        $data_hapus = mysqli_fetch_assoc($cek_pekerjaan_hapus);

        if (!empty($data_hapus["foto"])) {
            foreach (array_filter(array_map("trim", explode(",", $data_hapus["foto"]))) as $nama_foto_hapus) {
                $file_foto = $dir_foto_pekerjaan . basename($nama_foto_hapus);
                if (is_file($file_foto)) {
                    unlink($file_foto);
                }
            }
        }

        mysqli_query($koneksi, "DELETE FROM bukti_pekerjaan WHERE id_pekerjaan='$id_hapus'");
        mysqli_query($koneksi, "DELETE FROM riwayat_pekerjaan WHERE id_pekerjaan='$id_hapus'");
        mysqli_query($koneksi, "DELETE FROM pekerjaan_karyawan WHERE id_pekerjaan='$id_hapus'");

        mysqli_query(
            $koneksi,
            "DELETE FROM pekerjaan WHERE id_pekerjaan='$id_hapus' AND id_divisi='$id_divisi'"
        );
    }

    header("Location: " . $file_halaman . "?pesan=hapus");
    exit;
}

if (isset($_GET["pesan"]) && $_GET["pesan"] == "tambah") {
    $pesan = "Pekerjaan berhasil ditambahkan.";
    $tipe_pesan = "success";
}

$id_baru = (int) ($_GET["baru"] ?? 0);

if (isset($_GET["pesan"]) && $_GET["pesan"] == "hapus") {
    $pesan = "Pekerjaan berhasil dihapus.";
    $tipe_pesan = "success";
}

/* =========================================================
   DATA KARYAWAN AKTIF
========================================================= */
$query_karyawan = mysqli_query(
    $koneksi,
    "SELECT id_user, nama FROM users
     WHERE id_divisi='$id_divisi' AND role='Karyawan' AND status='Aktif'
     ORDER BY nama ASC"
);

$data_karyawan = [];

if ($query_karyawan) {
    while ($karyawan = mysqli_fetch_assoc($query_karyawan)) {
        $data_karyawan[] = $karyawan;
    }
}

/* =========================================================
   DAFTAR DEPARTEMEN (saran saat mengetik)
========================================================= */
$data_departemen = [];

$query_departemen = mysqli_query(
    $koneksi,
    "SELECT DISTINCT TRIM(departemen) AS departemen FROM users
     WHERE departemen IS NOT NULL AND TRIM(departemen) <> ''
     ORDER BY departemen ASC"
);

if ($query_departemen) {
    while ($d = mysqli_fetch_assoc($query_departemen)) {
        $data_departemen[] = $d["departemen"];
    }
}

/* =========================================================
   SEARCH + FILTER
========================================================= */
$cari = trim($_GET["cari"] ?? "");
$filter_status = trim($_GET["status"] ?? "");

if ($filter_status == "Peralihan") {
    $filter_status = "Belum Dikerjakan";
}

$view_pekerjaan = trim($_GET["view"] ?? "individu");

if (!in_array($view_pekerjaan, ["individu", "tim"], true)) {
    $view_pekerjaan = "individu";
}

/* KONDISI: selalu dibatasi tipe halaman ini */
$tipe_aman = mysqli_real_escape_string($koneksi, $tipe_order_halaman);

$kondisi = "pekerjaan.id_divisi = '$id_divisi'
    AND pekerjaan.tipe_order = '$tipe_aman'
    AND (pekerjaan.status IN ('Belum Dikerjakan', 'Peralihan', 'Menunggu Konfirmasi')
         OR pekerjaan.status IS NULL OR pekerjaan.status = '')";

if ($cari != "") {
    $cari_aman = mysqli_real_escape_string($koneksi, $cari);

    $kondisi .= "
        AND (
            pekerjaan.nama_pekerjaan LIKE '%$cari_aman%'
            OR pekerjaan.nomor_order LIKE '%$cari_aman%'
            OR pekerjaan.nama_pelapor LIKE '%$cari_aman%'
            OR pekerjaan.departemen_asal LIKE '%$cari_aman%'
            OR pekerjaan.jenis_pekerjaan LIKE '%$cari_aman%'
            OR pekerjaan.nama_peralatan LIKE '%$cari_aman%'
            OR pekerjaan.lokasi LIKE '%$cari_aman%'
            OR pekerjaan.uraian_kerusakan LIKE '%$cari_aman%'
            OR EXISTS (
                SELECT 1 FROM pekerjaan_karyawan pk_search
                INNER JOIN users u_search ON pk_search.id_user = u_search.id_user
                WHERE pk_search.id_pekerjaan = pekerjaan.id_pekerjaan
                AND u_search.nama LIKE '%$cari_aman%'
            )
            OR EXISTS (
                SELECT 1 FROM users u_legacy
                WHERE u_legacy.id_user = pekerjaan.id_karyawan
                AND u_legacy.nama LIKE '%$cari_aman%'
            )
        )
    ";
}

if ($filter_status == "Belum Dikerjakan") {
    $kondisi .= " AND pekerjaan.status IN ('Belum Dikerjakan', 'Peralihan')";
} elseif ($filter_status != "" && $filter_status != "Semua Status") {
    $filter_status_aman = mysqli_real_escape_string($koneksi, $filter_status);
    $kondisi .= " AND pekerjaan.status = '$filter_status_aman'";
}

/* =========================================================
   DATA PEKERJAAN
========================================================= */
$query_pekerjaan = mysqli_query(
    $koneksi,
    "SELECT
        pekerjaan.*,

        GROUP_CONCAT(
            DISTINCT CASE
                WHEN pk.status_penugasan IN ('Aktif', 'Peralihan') THEN u.nama
            END
            ORDER BY u.nama ASC
            SEPARATOR ', '
        ) AS nama_karyawan_relasi,

        COUNT(
            DISTINCT CASE
                WHEN pk.status_penugasan IN ('Aktif', 'Peralihan') THEN pk.id_user
            END
        ) AS jumlah_karyawan_relasi,

        u_legacy.nama AS nama_karyawan_legacy,
        u_pembuat.nama AS nama_pembuat

     FROM pekerjaan
     LEFT JOIN pekerjaan_karyawan pk ON pk.id_pekerjaan = pekerjaan.id_pekerjaan
     LEFT JOIN users u ON u.id_user = pk.id_user
     LEFT JOIN users u_legacy ON u_legacy.id_user = pekerjaan.id_karyawan
     LEFT JOIN users u_pembuat ON u_pembuat.id_user = pekerjaan.id_pembuat
     WHERE $kondisi
     GROUP BY pekerjaan.id_pekerjaan
     ORDER BY pekerjaan.id_pekerjaan DESC"
);

$data_individu = [];
$data_tim = [];

if ($query_pekerjaan) {

    while ($row = mysqli_fetch_assoc($query_pekerjaan)) {

        if (!empty($row["nama_karyawan_relasi"])) {
            $row["nama_karyawan"] = $row["nama_karyawan_relasi"];
        } else {
            $row["nama_karyawan"] = $row["nama_karyawan_legacy"] ?? "-";
        }

        $row["jumlah_karyawan"] = (int) ($row["jumlah_karyawan_relasi"] ?? 0);

        if ($row["jumlah_karyawan"] < 1 && !empty($row["id_karyawan"])) {
            $row["jumlah_karyawan"] = 1;
        }

        if ($row["jumlah_karyawan"] > 1) {
            $data_tim[] = $row;
        } else {
            $data_individu[] = $row;
        }
    }
}

/* =========================================================
   PAGINATION
========================================================= */
$per_halaman = 5;

$halaman_individu = max(1, (int) ($_GET["halaman_individu"] ?? 1));
$halaman_tim = max(1, (int) ($_GET["halaman_tim"] ?? 1));

$total_individu = count($data_individu);
$total_tim = count($data_tim);

$total_halaman_individu = max(1, (int) ceil($total_individu / $per_halaman));
$total_halaman_tim = max(1, (int) ceil($total_tim / $per_halaman));

if ($halaman_individu > $total_halaman_individu) {
    $halaman_individu = $total_halaman_individu;
}

if ($halaman_tim > $total_halaman_tim) {
    $halaman_tim = $total_halaman_tim;
}

$offset_individu = ($halaman_individu - 1) * $per_halaman;
$offset_tim = ($halaman_tim - 1) * $per_halaman;

$data_individu_tampil = array_slice($data_individu, $offset_individu, $per_halaman);
$data_tim_tampil = array_slice($data_tim, $offset_tim, $per_halaman);

$mulai_individu = $total_individu > 0 ? $offset_individu + 1 : 0;
$akhir_individu = min($offset_individu + $per_halaman, $total_individu);

$mulai_tim = $total_tim > 0 ? $offset_tim + 1 : 0;
$akhir_tim = min($offset_tim + $per_halaman, $total_tim);

if ($view_pekerjaan === "tim") {
    $data_aktif = $data_tim_tampil;
    $total_aktif = $total_tim;
    $mulai_aktif = $mulai_tim;
    $akhir_aktif = $akhir_tim;
    $halaman_aktif = $halaman_tim;
    $total_halaman_aktif = $total_halaman_tim;
    $parameter_halaman_aktif = "halaman_tim";
    $pesan_kosong_aktif = "Tidak ada pekerjaan tim.";
} else {
    $data_aktif = $data_individu_tampil;
    $total_aktif = $total_individu;
    $mulai_aktif = $mulai_individu;
    $akhir_aktif = $akhir_individu;
    $halaman_aktif = $halaman_individu;
    $total_halaman_aktif = $total_halaman_individu;
    $parameter_halaman_aktif = "halaman_individu";
    $pesan_kosong_aktif = "Tidak ada pekerjaan individu.";
}

$query_string_dasar = [];

if ($cari != "") {
    $query_string_dasar["cari"] = $cari;
}

if ($filter_status != "") {
    $query_string_dasar["status"] = $filter_status;
}

$query_string_dasar["view"] = $view_pekerjaan;

function simo_url_halaman($file, $halaman_tujuan, $query_dasar, $parameter_halaman)
{
    $query_dasar[$parameter_halaman] = $halaman_tujuan;
    return $file . "?" . http_build_query($query_dasar);
}

function simo_teks_status($status)
{
    $status = trim((string) $status);

    if ($status === "" || $status === "Peralihan") {
        return "Belum Dikerjakan";
    }

    return $status;
}

function simo_badge_status($status)
{
    switch ($status) {
        case "Sedang Dikerjakan":
            return "badge-sedang";
        case "Menunggu Konfirmasi":
            return "badge-menunggu";
        case "Perlu Perbaikan":
            return "badge-perbaikan";
        case "Selesai":
            return "badge-selesai";
        default:
            return "badge-belum";
    }
}

/* tanggal untuk tabel: satu baris, contoh 26-10-2026, 08:36 */
function simo_tanggal_html($ts)
{
    if (!$ts) {
        return '<span class="nomor-kosong">-</span>';
    }

    return simo_e(date("d-m-Y, H:i", $ts));
}

/* jumlah kolom tabel (untuk baris kosong) */
$jumlah_kolom = $pakai_nomor ? 16 : 15;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= simo_e($tipe_order_halaman); ?> - SIMO</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Arial,sans-serif}
body{background:#fff;min-height:100vh;color:#2c3644}
a{text-decoration:none;color:inherit}
.main{margin-left:240px;width:calc(100% - 240px);min-width:0;transition:margin-left .25s ease,width .25s ease}
.content{padding:26px 32px 40px}

.welcome{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px;gap:15px;flex-wrap:wrap}
.welcome h2{font-size:22px;color:#1c2636;margin-bottom:5px}
.welcome p{font-size:13px;color:#667488}

.alert{padding:12px 15px;border-radius:9px;margin-bottom:18px;font-size:13px;line-height:1.55}
.alert.success{background:#dff5e8;color:#29965a}
.alert.error{background:#fde2e2;color:#d94b4b}

.card{background:#fff;border:1px solid #d9dde2;border-radius:13px;padding:20px;box-shadow:0 1px 3px rgba(16,24,40,.04);margin-bottom:18px}
.card-title{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.card-title-icon{width:34px;height:34px;border-radius:50%;background:#f7e3e5;color:#b51f2a;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
.card-title-icon svg{width:16px;height:16px;stroke:#b51f2a}
.card-title h3{font-size:15px;color:#1c2636}

/* TOMBOL TAMBAH PEKERJAAN */
.btn-tambah{display:inline-flex;align-items:center;gap:8px;border:none;background:#b51f2a;color:#fff;padding:11px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s,transform .15s}
.btn-tambah svg{width:16px;height:16px;stroke:#fff}
.btn-tambah:hover{background:#941923;transform:translateY(-1px)}
#cardForm{display:none}
#cardForm.show{display:block}
.btn-tutup-form{margin-left:auto;width:32px;height:32px;border-radius:8px;border:1px solid #d9dde2;background:#fff;color:#667488;font-size:20px;line-height:1;cursor:pointer}
.btn-tutup-form:hover{border-color:#b51f2a;color:#b51f2a;background:#fdf3f4}
html body.dark-mode .btn-tutup-form{background:#0f141b!important;border-color:#2c3542!important;color:#cbd2dc!important}
html body.dark-mode .btn-tutup-form:hover{background:#382326!important;border-color:#b51f2a!important;color:#f07a80!important}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-group{width:100%}
.form-group.full{grid-column:1 / -1}
.form-group label{display:block;font-size:13px;font-weight:700;color:#000;margin-bottom:8px}
.form-group label .opsional{font-weight:400;color:#5b6573}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;outline:none}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#b51f2a}
.form-group textarea{min-height:90px;resize:vertical}
.date-time-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tiga-tanggal{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.date-time-field span{display:block;font-size:12px;font-weight:600;color:#1c2636;margin-bottom:6px}
.date-time-field input{width:100%}

/* FOTO */
.foto-actions{display:flex;gap:10px;flex-wrap:wrap}
.btn-foto{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s,color .15s}
.btn-foto svg{width:16px;height:16px;stroke:currentColor}
.btn-foto:hover{border-color:#b51f2a;color:#b51f2a;background:#fdf3f4}
@media(max-width:800px){.foto-actions .btn-foto{flex:1;justify-content:center}}

/* KOTAK KETIK DENGAN SARAN */
.combo{position:relative}
.combo-list{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:50;max-height:220px;overflow-y:auto;margin:0;padding:4px;list-style:none;background:#fff;border:1px solid #dce3ec;border-radius:8px;box-shadow:0 8px 24px rgba(16,24,40,.12)}
.combo-list.show{display:block}
.combo-list li{padding:9px 10px;border-radius:6px;font-size:13px;color:#405067;cursor:pointer}
.combo-list li.aktif,.combo-list li:hover{background:#fbeced;color:#b51f2a}
.combo-list li mark{background:none;color:inherit;font-weight:700}
.combo-list li.kosong{color:#9aa3af;cursor:default;font-size:12px}
.combo-list li.kosong:hover{background:none;color:#9aa3af}

/* JENIS PERBAIKAN */
.pilih-jenis{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-group .opsi{position:relative;display:flex;align-items:center;gap:10px;margin:0;padding:12px 14px;min-height:48px;min-width:0;border:1px solid #dde2ea;border-radius:9px;background:#fbfaf9;cursor:pointer;font-size:14px;font-weight:600;color:#1c2636;transition:border-color .15s,background .15s}
.form-group .opsi:hover{border-color:#b51f2a}
.form-group .opsi input{position:absolute;opacity:0;width:1px;height:1px;padding:0;margin:0;border:0;pointer-events:none}
.form-group .opsi .kotak{width:20px;height:20px;border:2px solid #b9c0cb;border-radius:5px;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s,border-color .15s}
.form-group .opsi .kotak svg{width:13px;height:13px;stroke:#fff;opacity:0}
.form-group .opsi:has(input:checked){border-color:#b51f2a;background:#fdf3f4}
.form-group .opsi input:checked + .kotak{background:#b51f2a;border-color:#b51f2a}
.form-group .opsi input:checked + .kotak svg{opacity:1}
.form-group .opsi input:focus-visible + .kotak{box-shadow:0 0 0 3px rgba(181,31,42,.2)}
@media(max-width:800px){.form-group .opsi{padding:14px;min-height:52px}}

/* KAMERA */
.kamera-modal{position:fixed;inset:0;z-index:10002;display:none;background:#000}
.kamera-modal.show{display:block}
.kamera-modal video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000}
.kamera-modal video.cermin{transform:scaleX(-1)}
.kamera-tutup{position:absolute;top:max(16px,env(safe-area-inset-top));right:16px;width:42px;height:42px;border-radius:50%;border:none;background:rgba(0,0,0,.45);color:#fff;font-size:26px;line-height:1;cursor:pointer}
.kamera-bar{position:absolute;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:space-between;padding:22px 32px calc(22px + env(safe-area-inset-bottom));background:linear-gradient(to top,rgba(0,0,0,.55),transparent)}
.kamera-spacer{width:48px;height:48px}
.kamera-shutter{width:72px;height:72px;border-radius:50%;border:4px solid #fff;background:rgba(255,255,255,.25);cursor:pointer;position:relative}
.kamera-shutter::after{content:"";position:absolute;inset:6px;border-radius:50%;background:#fff}
.kamera-shutter:active::after{inset:9px}
.kamera-ganti{width:48px;height:48px;border-radius:50%;border:none;background:rgba(0,0,0,.45);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
.kamera-ganti svg{width:22px;height:22px;stroke:#fff}
.foto-hint{margin-top:6px;font-size:11px;color:#9aa3af}
.foto-preview{display:none;margin-top:10px;position:relative;width:160px}
.foto-preview.show{display:block}
.foto-preview img{display:block;width:160px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #dce3ec}
.foto-preview button{position:absolute;top:-8px;right:-8px;width:24px;height:24px;border-radius:50%;border:none;background:#b51f2a;color:#fff;font-size:15px;line-height:1;cursor:pointer}

/* KARYAWAN */
.jumlah-karyawan-box{background:#f7f8fa;border:1px solid #edf1f5;border-radius:10px;padding:14px;margin-bottom:12px}
.jumlah-karyawan-box label{margin-bottom:7px}
.jumlah-karyawan-box select{max-width:180px;background:#fff}
.karyawan-container{display:flex;flex-direction:column;gap:10px}
.karyawan-item-row{display:grid;grid-template-columns:105px 1fr;align-items:center;gap:10px}
.karyawan-number{font-size:12px;font-weight:600;color:#667488}
.karyawan-select{width:100%}
.karyawan-hint{margin-top:8px;font-size:11px;color:#9aa3af}
.form-button{display:flex;justify-content:flex-end;margin-top:20px}
.btn-simpan{border:none;background:#b51f2a;color:#fff;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
.btn-simpan:hover{background:#941923}
.btn-simpan:disabled{opacity:.6;cursor:not-allowed}

/* LIST */
.list-header{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:20px;flex-wrap:wrap}
.list-header-left{display:flex;align-items:center;gap:12px}
.list-header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.daftar-pekerjaan-label{display:block;font-size:15px;font-weight:700;color:#1c2636}
.daftar-pekerjaan-desc{margin-top:3px;font-size:11.5px;color:#8b96a5;font-weight:400}
.section-pagination{margin-top:14px}

.pekerjaan-tabs{display:grid;grid-template-columns:minmax(280px,320px) minmax(280px,320px);gap:10px;margin-bottom:16px}
.pekerjaan-tab{display:flex;align-items:center;justify-content:center;gap:9px;min-height:44px;padding:8px 14px;border:1px solid #d9dde2;border-radius:11px;background:#fff;color:#526176;font-size:13px;font-weight:700;transition:all .2s ease}
.pekerjaan-tab svg{width:17px;height:17px;stroke:currentColor}
.pekerjaan-tab:hover{border-color:#b51f2a;color:#a5212b;background:#f8f9fb}
.pekerjaan-tab.active{background:#b51f2a;border-color:#b51f2a;color:#fff}
.pekerjaan-tab-count{min-width:22px;height:22px;padding:0 6px;border-radius:20px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;background:#eef0f3;color:#344054}
.pekerjaan-tab.active .pekerjaan-tab-count{background:rgba(255,255,255,.22);color:#fff}

.search-box{position:relative}
.search-box svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;stroke:#9aa3af}
.search-box input{width:390px;max-width:100%;padding:9px 12px 9px 34px;border:1px solid #d9dde2;border-radius:9px;font-size:12.5px;color:#405067;background:#fff;outline:none}
.search-box input:focus{border-color:#b51f2a}
.filter-select{min-width:145px;padding:9px 12px;border:1px solid #d9dde2;border-radius:9px;font-size:12.5px;color:#405067;background:#fff;outline:none;cursor:pointer}
.filter-select:focus{border-color:#b51f2a}

/* TABEL */
.table-wrapper{width:100%;overflow-x:auto;background:transparent}
.tabel-pekerjaan{width:100%;min-width:1750px;border-collapse:collapse;background:#fff}
.tabel-pekerjaan th{background:#b51f2a;padding:12px;text-align:left;font-size:12px;color:#fff;font-weight:700;white-space:nowrap;border-bottom:1px solid #b51f2a}
.tabel-pekerjaan th:first-child{border-top-left-radius:8px}
.tabel-pekerjaan th:last-child{border-top-right-radius:8px}
.tabel-pekerjaan td{padding:12px;font-size:13px;color:#172033;border-bottom:1px solid #eef0f3;background:#fff;vertical-align:middle;height:58px;white-space:nowrap}
.tabel-pekerjaan tbody tr{transition:background .15s ease;height:58px}
.tabel-pekerjaan tbody tr:hover td{background:#f8f9fb}
.tabel-pekerjaan .job-name{font-weight:600;color:#172033;max-width:260px;line-height:1.4;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tabel-pekerjaan .nowrap{white-space:nowrap}

.nomor-order{display:inline-block;font-weight:700;color:#1d4ed8;letter-spacing:.2px;font-variant-numeric:tabular-nums}
.nomor-kosong{color:#9aa3af;font-weight:400}

.tabel-pekerjaan .karyawan-list{max-width:220px;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabel-pekerjaan .pelapor-text{max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabel-pekerjaan .uraian-text{max-width:240px;line-height:1.4;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.jenis-chips{display:flex;flex-wrap:nowrap;gap:5px;min-width:0}
.jenis-chip{display:inline-block;padding:4px 8px;border-radius:6px;background:#f9e9eb;color:#b51f2a;font-size:10.5px;font-weight:700;line-height:1.3;white-space:nowrap}

.tabel-pekerjaan .sub-text{margin-top:2px;max-width:260px;font-size:11.5px;color:#667488;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabel-pekerjaan .tgl-atas{font-weight:600;color:#172033;font-size:12.5px}
.tabel-pekerjaan .tgl-jam{margin-top:2px;font-size:11.5px;color:#667488}

tr.row-klik{cursor:pointer}
tr.row-klik:focus{outline:none}
tr.row-klik:focus-visible td{background:#fdf3f4}

.tabel-pekerjaan td{transition:background-color .8s ease,box-shadow .8s ease}
.tabel-pekerjaan tbody tr.row-baru td{background:#dff5e8!important}
.tabel-pekerjaan tbody tr.row-baru td:first-child{box-shadow:inset 4px 0 0 #29965a}

.status-badge,.jumlah-karyawan-table{display:inline-block;padding:5px 9px;border-radius:6px;font-size:10.5px;font-weight:700;line-height:1.3;white-space:nowrap}
.badge-belum{background:#eef0f3;color:#667085}
.badge-sedang{background:#edf3ff;color:#3f65b5}
.badge-menunggu{background:#fff5dc;color:#a46b00}
.badge-perbaikan{background:#fde5e5;color:#c94242}
.badge-selesai{background:#e9f8f0;color:#168353}
.jumlah-karyawan-table{background:#f9e9eb;color:#b51f2a}

.aksi-group{display:flex;align-items:center;gap:8px}
.btn-aksi{width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;border:1px solid #d9dde2;background:#fff8f8;cursor:pointer;flex-shrink:0;transition:background .15s ease,border-color .15s ease}
.btn-aksi svg{width:15px;height:15px;stroke:#b31824;transition:stroke .15s ease}
.btn-aksi:hover{background:#b51f2a;border-color:#b51f2a}
.btn-aksi:hover svg{stroke:#fff}
.btn-edit-disabled,.btn-edit-disabled:hover{background:#eef0f3;border-color:#e1e4e8;cursor:not-allowed}
.btn-edit-disabled svg,.btn-edit-disabled:hover svg{stroke:#9aa3af}
.empty-row{text-align:center!important;padding:40px 20px!important;color:#8a94a5;font-size:13px}

.pagination-row{display:flex;align-items:center;justify-content:space-between;margin-top:14px;gap:15px;flex-wrap:wrap}
.pagination-info{font-size:12px;color:#8b96a5}
.pagination{display:flex;align-items:center;gap:6px}
.pagination a,.pagination span{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#4d5969;border:1px solid #e2e8f0}
.pagination a:hover{background:#f5f7fa}
.pagination .active{background:#b51f2a;color:#fff;border-color:#b51f2a;font-weight:700}
.pagination .disabled{opacity:.4;pointer-events:none}

/* MODAL HAPUS */
.delete-modal-overlay{position:fixed;inset:0;background:rgba(28,34,44,.42);display:none;align-items:center;justify-content:center;padding:20px;z-index:10000;backdrop-filter:blur(2px)}
.delete-modal-overlay.show{display:flex}
.delete-modal-box{width:380px;max-width:100%;background:#fff;border-radius:16px;padding:28px 26px 24px;text-align:center;box-shadow:0 18px 45px rgba(20,28,40,.18)}
.delete-modal-icon{width:50px;height:50px;margin:0 auto 15px;border-radius:50%;background:#f7e3e5;display:flex;align-items:center;justify-content:center;color:#b51f2a}
.delete-modal-icon svg{width:22px;height:22px;stroke:currentColor}
.delete-modal-box h3{margin-bottom:7px;font-size:17px;color:#1c2636}
.delete-modal-box p{margin:0 auto 22px;max-width:300px;font-size:12px;line-height:1.6;color:#7e8897}
.delete-modal-actions{display:flex;justify-content:center;gap:10px}
.delete-modal-btn{min-width:104px;padding:10px 16px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.delete-modal-cancel{border:1px solid #dde3ea;background:#f7f8fa;color:#697587}
.delete-modal-cancel:hover{background:#eef1f4}
.delete-modal-confirm{border:1px solid #b51f2a;background:#b51f2a;color:#fff}
.delete-modal-confirm:hover{background:#941923;border-color:#941923}

.footer-note{text-align:center;font-size:12px;color:#a3adba;padding:26px 0 6px}

@media(max-width:1100px){
    .tiga-tanggal{grid-template-columns:1fr}
    .form-grid{grid-template-columns:1fr}
    .form-group.full{grid-column:auto}
}
@media(max-width:800px){
    .main{margin-left:0;width:100%}
    .content{padding:20px}
    .list-header{flex-direction:column;align-items:flex-start}
    .pekerjaan-tabs{grid-template-columns:1fr}
    .search-box input{width:100%}
    .list-header-right{width:100%}
    .list-header-right .search-box{width:100%}
    .list-header-right .filter-select{flex:1;min-width:0}
    .karyawan-item-row{grid-template-columns:1fr;gap:5px}
}

/* DARK MODE */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .main,html body.dark-mode .content{background:#0f141b!important}

html body.dark-mode .welcome h2,
html body.dark-mode .card-title h3,
html body.dark-mode .daftar-pekerjaan-label,
html body.dark-mode .form-group label,
html body.dark-mode .date-time-field span,
html body.dark-mode .jumlah-karyawan-box label{color:#edf2f7!important}
html body.dark-mode .welcome p,
html body.dark-mode .daftar-pekerjaan-desc,
html body.dark-mode .karyawan-hint,
html body.dark-mode .foto-hint,
html body.dark-mode .form-group label .opsional,
html body.dark-mode .pagination-info,
html body.dark-mode .empty-row{color:#8f9bac!important}

html body.dark-mode .card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
html body.dark-mode .card-title-icon{background:#382326!important;color:#f07a80!important}
html body.dark-mode .card-title-icon svg{stroke:#f07a80!important}

html body.dark-mode input,
html body.dark-mode select,
html body.dark-mode textarea,
html body.dark-mode .search-box input,
html body.dark-mode .filter-select,
html body.dark-mode .jumlah-karyawan-box select{background:#0f141b!important;color:#e8edf4!important;border-color:#2c3542!important;box-shadow:none!important;color-scheme:dark}
html body.dark-mode input::placeholder,
html body.dark-mode textarea::placeholder{color:#6f7a8a!important}
html body.dark-mode select option{background:#151b23!important;color:#e8edf4!important}
html body.dark-mode input:focus,
html body.dark-mode select:focus,
html body.dark-mode textarea:focus{border-color:#b51f2a!important}
html body.dark-mode .foto-preview img{border-color:#2c3542!important}
html body.dark-mode .btn-foto{background:#0f141b!important;border-color:#2c3542!important;color:#e8edf4!important}
html body.dark-mode .btn-foto:hover{background:#382326!important;border-color:#b51f2a!important;color:#f07a80!important}

html body.dark-mode .combo-list{background:#151b23!important;border-color:#2c3542!important;box-shadow:0 8px 24px rgba(0,0,0,.4)!important}
html body.dark-mode .combo-list li{color:#cbd2dc!important}
html body.dark-mode .combo-list li.aktif,html body.dark-mode .combo-list li:hover{background:#382326!important;color:#f07a80!important}
html body.dark-mode .combo-list li.kosong,html body.dark-mode .combo-list li.kosong:hover{background:none!important;color:#6f7a8a!important}

html body.dark-mode .form-group .opsi{background:#1a212b!important;border-color:#303947!important;color:#e8edf4!important}
html body.dark-mode .form-group .opsi:hover{border-color:#b51f2a!important}
html body.dark-mode .form-group .opsi .kotak{background:#0f141b!important;border-color:#4a5566!important}
html body.dark-mode .form-group .opsi:has(input:checked){background:#302126!important;border-color:#b51f2a!important}
html body.dark-mode .form-group .opsi input:checked + .kotak{background:#b51f2a!important;border-color:#b51f2a!important}

html body.dark-mode .jumlah-karyawan-box,
html body.dark-mode .karyawan-item-row{background:#1b222c!important;border-color:#2c3542!important}
html body.dark-mode .karyawan-number{color:#aeb8c6!important}
html body.dark-mode .search-box svg{stroke:#8f9bac!important}

html body.dark-mode .btn-simpan{background:#b51f2a!important;color:#fff!important}
html body.dark-mode .btn-simpan:hover{background:#d12b36!important}

html body.dark-mode .alert.success{background:#183d2a!important;color:#72d89a!important}
html body.dark-mode .alert.error{background:#302326!important;color:#f08a90!important}

html body.dark-mode .pekerjaan-tab{background:#151b23!important;border-color:#2c3542!important;color:#cbd2dc!important}
html body.dark-mode .pekerjaan-tab svg{stroke:#cbd2dc!important}
html body.dark-mode .pekerjaan-tab:hover{background:#202733!important;border-color:#b51f2a!important}
html body.dark-mode .pekerjaan-tab-count{background:#2c3542!important;color:#edf2f7!important}
html body.dark-mode .pekerjaan-tab.active{background:#b51f2a!important;border-color:#b51f2a!important;color:#fff!important}
html body.dark-mode .pekerjaan-tab.active svg{stroke:#fff!important}
html body.dark-mode .pekerjaan-tab.active .pekerjaan-tab-count{background:rgba(255,255,255,.22)!important;color:#fff!important}

html body.dark-mode .tabel-pekerjaan{background:#151b23!important}
html body.dark-mode .tabel-pekerjaan th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#b51f2a!important}
html body.dark-mode .tabel-pekerjaan td{background:#151b23!important;color:#fff!important;border-bottom-color:#2c3542!important}
html body.dark-mode .tabel-pekerjaan tbody tr:hover td{background:#202733!important}
html body.dark-mode tr.row-klik:focus-visible td{background:#2a2024!important}
html body.dark-mode .tabel-pekerjaan .job-name,
html body.dark-mode .tabel-pekerjaan .karyawan-list,
html body.dark-mode .tabel-pekerjaan .pelapor-text,
html body.dark-mode .tabel-pekerjaan .uraian-text{color:#fff!important}
html body.dark-mode .tabel-pekerjaan tbody tr.row-baru td{background:#183d2a!important}
html body.dark-mode .tabel-pekerjaan tbody tr.row-baru td:first-child{box-shadow:inset 4px 0 0 #72d89a}
html body.dark-mode .jenis-chip{background:#382326!important;color:#f07a80!important}
html body.dark-mode .tabel-pekerjaan .nomor-order{color:#8eb5ff!important}
html body.dark-mode .tabel-pekerjaan .nomor-kosong{color:#6f7a8a!important}

html body.dark-mode .badge-belum{background:#34383e!important;color:#c5cbd3!important}
html body.dark-mode .badge-sedang{background:#1d3158!important;color:#8eb5ff!important}
html body.dark-mode .badge-menunggu{background:#3c3924!important;color:#f2d77d!important}
html body.dark-mode .badge-perbaikan{background:#4b2d32!important;color:#ff8b92!important}
html body.dark-mode .badge-selesai{background:#183d2a!important;color:#72d89a!important}
html body.dark-mode .jumlah-karyawan-table{background:#382326!important;color:#f07a80!important}

html body.dark-mode .btn-aksi{background:#382326!important;border-color:#51363b!important}
html body.dark-mode .btn-aksi svg{stroke:#f07a80!important}
html body.dark-mode .btn-aksi:hover{background:#b51f2a!important;border-color:#b51f2a!important}
html body.dark-mode .btn-aksi:hover svg{stroke:#fff!important}
html body.dark-mode .btn-edit-disabled,
html body.dark-mode .btn-edit-disabled:hover{background:#202733!important;border-color:#2c3542!important}
html body.dark-mode .btn-edit-disabled svg,
html body.dark-mode .btn-edit-disabled:hover svg{stroke:#6f7a8a!important}

html body.dark-mode .pagination a,
html body.dark-mode .pagination span{background:#151b23!important;color:#cbd2dc!important;border-color:#2c3542!important}
html body.dark-mode .pagination a:hover{background:#202733!important}
html body.dark-mode .pagination .active{background:#b51f2a!important;color:#fff!important;border-color:#b51f2a!important}

html body.dark-mode .delete-modal-box{background:#151b23!important;border:1px solid #2c3542!important}
html body.dark-mode .delete-modal-box h3{color:#edf2f7!important}
html body.dark-mode .delete-modal-box p{color:#8f9bac!important}
html body.dark-mode .delete-modal-icon{background:#382326!important;color:#f07a80!important}
html body.dark-mode .delete-modal-cancel{background:#202733!important;border-color:#2c3542!important;color:#cbd2dc!important}
html body.dark-mode .delete-modal-cancel:hover{background:#2a3342!important}

html body.dark-mode .tabel-pekerjaan .sub-text,
html body.dark-mode .tabel-pekerjaan .tgl-jam{color:#8f9bac!important}
html body.dark-mode .tabel-pekerjaan .tgl-atas{color:#fff!important}

html body.dark-mode .footer-note{color:#8f9bac!important}
</style>
</head>

<body>

<?php include "../includes/admin_divisi/sidebar.php"; ?>

<div class="main">

<?php include "../includes/admin_divisi/topbar.php"; ?>

<div class="content">

    <!-- JUDUL -->
    <div class="welcome">
        <div>
            <h2><?= simo_e($tipe_order_halaman); ?></h2>
            <p>Kelola pekerjaan <?= simo_e($tipe_order_halaman); ?> pada divisi <?= simo_e($nama_divisi); ?>.</p>
        </div>

        <button type="button" class="btn-tambah" id="btnTambah">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 5v14"/>
                <path d="M5 12h14"/>
            </svg>
            Tambah Pekerjaan
        </button>
    </div>

    <?php if ($pesan != ""): ?>
        <div class="alert <?= $tipe_pesan; ?>">
            <?= simo_e($pesan); ?>
        </div>
    <?php endif; ?>

    <!-- FORM TAMBAH PEKERJAAN (terbuka lewat tombol; otomatis terbuka kalau simpan gagal) -->
    <div class="card <?= ($tipe_pesan === "error" && isset($_POST["simpan_pekerjaan"])) ? "show" : ""; ?>" id="cardForm">

        <div class="card-title">
            <div class="card-title-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
            </div>
            <h3>Tambah Pekerjaan <?= simo_e($tipe_order_halaman); ?></h3>
            <button type="button" class="btn-tutup-form" id="btnTutupForm" aria-label="Tutup form">&times;</button>
        </div>

        <form method="POST" id="formPekerjaan" enctype="multipart/form-data" action="<?= simo_e($file_halaman); ?>">

            <input type="hidden" name="simpan_pekerjaan" value="1">

            <div class="form-grid">

                <?php if ($pakai_nomor): ?>
                <div class="form-group">
                    <label for="nomorOrder">Nomor Order</label>
                    <input type="text" name="nomor_order" id="nomorOrder" maxlength="50" placeholder="Masukkan nomor order" required value="<?= simo_e($old["nomor_order"]); ?>">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="namaPekerjaan">Nama Pekerjaan</label>
                    <input type="text" name="nama_pekerjaan" id="namaPekerjaan" maxlength="150" placeholder="Contoh: Ganti bearing mesin packing 2" required value="<?= simo_e($old["nama_pekerjaan"]); ?>">
                </div>

                <div class="form-group">
                    <label for="namaPelapor">Nama Pelapor <span class="opsional">(opsional)</span></label>
                    <input type="text" name="nama_pelapor" id="namaPelapor" placeholder="Masukkan nama pelapor" maxlength="100" value="<?= simo_e($old["nama_pelapor"]); ?>">
                </div>

                <div class="form-group">
                    <label for="departemenAsal">Departemen yang Diperbaiki <span class="opsional">(opsional)</span></label>
                    <div class="combo" id="comboDepartemen">
                        <input type="text" name="departemen_asal" id="departemenAsal" placeholder="Ketik departemen, kosongkan jika di luar pabrik" maxlength="100" autocomplete="off" value="<?= simo_e($old["departemen_asal"]); ?>">
                        <ul class="combo-list" id="comboList" role="listbox"></ul>
                    </div>
                </div>

                <!-- JENIS PERBAIKAN -->
                <div class="form-group full">
                    <label>Jenis Perbaikan <span class="opsional">(pilih salah satu)</span></label>
                    <div class="pilih-jenis" id="jenisPerbaikan">
                        <?php foreach ($daftar_jenis as $i => $j): ?>
                            <label class="opsi">
                                <input type="radio" name="jenis_perbaikan[]" value="<?= simo_e($j); ?>" <?= in_array($j, $jenis_old, true) ? "checked" : ""; ?> <?= $i === 0 ? "required" : ""; ?>>
                                <span class="kotak">
                                    <svg viewBox="0 0 24 24" fill="none" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"></path></svg>
                                </span>
                                <?= simo_e($j); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="namaPeralatan">Nama Peralatan <span class="opsional">(opsional)</span></label>
                    <input type="text" name="nama_peralatan" id="namaPeralatan" maxlength="150" placeholder="Contoh: Mesin Packing 2, Forklift FL-03" value="<?= simo_e($old["nama_peralatan"]); ?>">
                </div>

                <div class="form-group">
                    <label for="lokasi">Lokasi</label>
                    <input type="text" name="lokasi" id="lokasi" placeholder="Contoh: Gudang B, Lantai 2" required value="<?= simo_e($old["lokasi"]); ?>">
                </div>

                <!-- KARYAWAN -->
                <div class="form-group full" id="karyawanGroup">

                    <label>Karyawan yang Dituju</label>

                    <div class="jumlah-karyawan-box">
                        <label for="jumlahKaryawan">Jumlah Karyawan</label>
                        <select name="jumlah_karyawan" id="jumlahKaryawan">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i; ?>" <?= (int) $old["jumlah_karyawan"] === $i ? "selected" : ""; ?>><?= $i; ?> Karyawan</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="karyawan-container" id="karyawanContainer"></div>

                    <div class="karyawan-hint">Pilih nama karyawan sesuai jumlah karyawan yang dituju.</div>

                </div>

                <div class="form-group full">
                    <div class="tiga-tanggal">
                        <div class="form-group">
                            <label for="tanggalOrderMasuk">Waktu Dilaporkan</label>
                            <input type="datetime-local" name="tanggal_order_masuk" id="tanggalOrderMasuk" required value="<?= simo_e($old["tanggal_order_masuk"]); ?>">
                        </div>

                        <div class="form-group">
                            <label for="tanggalMulai">Rencana Dikerjakan</label>
                            <input type="datetime-local" name="tanggal_mulai" id="tanggalMulai" required value="<?= simo_e($old["tanggal_mulai"]); ?>">
                        </div>

                        <div class="form-group">
                            <label for="targetSelesai">Target Selesai</label>
                            <input type="datetime-local" name="target_selesai" id="targetSelesai" required value="<?= simo_e($old["target_selesai"]); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="uraianKerusakan">Uraian Pekerjaan</label>
                    <textarea name="uraian_kerusakan" id="uraianKerusakan" placeholder="Jelaskan pekerjaan yang akan dilakukan" required><?= simo_e($old["uraian_kerusakan"]); ?></textarea>
                </div>

                <!-- FOTO -->
                <div class="form-group full">
                    <label for="fotoPekerjaan">Foto <span class="opsional">(opsional)</span></label>

                    <input type="file" name="foto" id="fotoPekerjaan" accept="image/jpeg,image/png,image/webp" hidden>
                    <input type="file" id="fotoKamera" accept="image/*" capture="environment" hidden>
                    <input type="file" id="fotoGaleri" accept="image/jpeg,image/png,image/webp" hidden>

                    <div class="foto-actions">
                        <button type="button" class="btn-foto" id="btnKamera">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                <circle cx="12" cy="13" r="4"/>
                            </svg>
                            Ambil dari Kamera
                        </button>
                        <button type="button" class="btn-foto" id="btnGaleri">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <path d="M21 15l-5-5L5 21"/>
                            </svg>
                            Pilih dari Galeri
                        </button>
                    </div>
                    <div class="foto-hint">Format JPG, PNG, atau WEBP. Maksimal 5 MB.</div>
                    <div class="foto-preview" id="fotoPreview">
                        <img id="fotoPreviewImg" src="" alt="Pratinjau foto">
                        <button type="button" id="fotoHapusBtn" aria-label="Hapus foto">&times;</button>
                    </div>
                </div>

            </div>

            <div class="form-button">
                <button type="submit" class="btn-simpan" id="btnSimpan">Simpan Pekerjaan</button>
            </div>

        </form>

    </div>

    <!-- TAB INDIVIDU / TIM -->
    <div class="pekerjaan-tabs">

        <a
            href="<?= simo_url_halaman($file_halaman, 1, array_merge($query_string_dasar, ["view" => "individu"]), "halaman_individu"); ?>"
            class="pekerjaan-tab <?= $view_pekerjaan === "individu" ? "active" : ""; ?>"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21a8 8 0 0 0-16 0"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span>Pekerjaan Individu</span>
            <span class="pekerjaan-tab-count"><?= $total_individu; ?></span>
        </a>

        <a
            href="<?= simo_url_halaman($file_halaman, 1, array_merge($query_string_dasar, ["view" => "tim"]), "halaman_tim"); ?>"
            class="pekerjaan-tab <?= $view_pekerjaan === "tim" ? "active" : ""; ?>"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span>Pekerjaan Tim</span>
            <span class="pekerjaan-tab-count"><?= $total_tim; ?></span>
        </a>

    </div>

    <!-- DAFTAR PEKERJAAN -->
    <div class="card">

        <div class="list-header">

            <div class="list-header-left">
                <div>
                    <strong class="daftar-pekerjaan-label">Daftar Pekerjaan <?= simo_e($tipe_order_halaman); ?></strong>
                    <p class="daftar-pekerjaan-desc">
                        Klik baris pekerjaan untuk melihat detail. Divisi <?= simo_e($nama_divisi); ?>.
                    </p>
                </div>
            </div>

            <form method="GET" action="<?= simo_e($file_halaman); ?>" class="list-header-right" id="filterForm">

                <input type="hidden" name="view" value="<?= simo_e($view_pekerjaan); ?>">

                <div class="search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="M21 21l-4-4"/>
                    </svg>
                    <input
                        type="text"
                        name="cari"
                        placeholder="Cari pekerjaan, pelapor, lokasi, atau karyawan..."
                        value="<?= simo_e($cari); ?>"
                    >
                </div>

                <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="" <?= $filter_status == "" ? "selected" : ""; ?>>Semua Status</option>
                    <option value="Belum Dikerjakan" <?= $filter_status == "Belum Dikerjakan" ? "selected" : ""; ?>>Belum Dikerjakan</option>
                    <option value="Menunggu Konfirmasi" <?= $filter_status == "Menunggu Konfirmasi" ? "selected" : ""; ?>>Menunggu Konfirmasi</option>
                </select>

            </form>

        </div>

        <div class="table-wrapper">

            <table class="tabel-pekerjaan">
                <thead>
                    <tr>
                        <th>No</th>
                        <?php if ($pakai_nomor): ?>
                            <th>No. Order</th>
                        <?php endif; ?>
                        <th>Pekerjaan</th>
                        <th>Peralatan</th>
                        <th>Pelapor</th>
                        <th>Departemen</th>
                        <th>Dibuat Oleh</th>
                        <th>Jenis</th>
                        <th>Uraian Pekerjaan</th>
                        <th>Lokasi</th>
                        <?php if ($view_pekerjaan === "tim"): ?>
                            <th>Jumlah</th>
                        <?php else: ?>
                            <th>Dikerjakan Oleh</th>
                        <?php endif; ?>
                        <th>Dilaporkan</th>
                        <th>Rencana Dikerjakan</th>
                        <th>Target Selesai</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($data_aktif) > 0): ?>

                    <?php $no_aktif = $mulai_aktif; ?>

                    <?php foreach ($data_aktif as $row): ?>

                        <?php
                            $status = $row["status"] ?? "";

                            $teks_status = simo_teks_status($status);
                            $class_status = simo_badge_status($teks_status);

                            $teks_nomor = !empty($row["nomor_order"]) ? $row["nomor_order"] : "-";
                            $teks_pelapor = !empty($row["nama_pelapor"]) ? $row["nama_pelapor"] : "-";
                            $teks_departemen = !empty($row["departemen_asal"]) ? $row["departemen_asal"] : $nama_divisi;
                            $teks_pembuat = !empty($row["nama_pembuat"]) ? $row["nama_pembuat"] : "Admin";
                            $teks_peralatan = !empty($row["nama_peralatan"]) ? $row["nama_peralatan"] : "";
                            $teks_uraian = !empty($row["uraian_kerusakan"]) ? $row["uraian_kerusakan"] : "-";
                            $teks_lokasi = !empty($row["lokasi"]) ? $row["lokasi"] : "-";

                            $daftar_jenis_row = array_filter(array_map("trim", explode(",", (string) ($row["jenis_pekerjaan"] ?? ""))));

                            $ts_order = !empty($row["tanggal_order_masuk"]) ? strtotime($row["tanggal_order_masuk"]) : false;
                            $ts_mulai = !empty($row["tanggal_mulai"]) ? strtotime($row["tanggal_mulai"]) : false;
                            $ts_target = !empty($row["target_selesai"]) ? strtotime($row["target_selesai"]) : false;
                        ?>

                        <tr
                            class="row-klik <?= ($id_baru > 0 && (int) $row["id_pekerjaan"] === $id_baru) ? "row-baru" : ""; ?>"
                            tabindex="0"
                            title="Klik untuk melihat detail"
                            data-id="<?= (int) $row["id_pekerjaan"]; ?>"
                        >
                            <td><?= $no_aktif++; ?></td>

                            <?php if ($pakai_nomor): ?>
                            <td class="nowrap">
                                <?php if ($teks_nomor !== "-"): ?>
                                    <span class="nomor-order"><?= simo_e($teks_nomor); ?></span>
                                <?php else: ?>
                                    <span class="nomor-kosong">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>

                            <td>
                                <div class="job-name" title="<?= simo_e($row["nama_pekerjaan"] ?? "-"); ?>"><?= simo_e($row["nama_pekerjaan"] ?? "-"); ?></div>
                            </td>

                            <td>
                                <div class="pelapor-text" title="<?= simo_e($teks_peralatan !== "" ? $teks_peralatan : "-"); ?>"><?= simo_e($teks_peralatan !== "" ? $teks_peralatan : "-"); ?></div>
                            </td>

                            <td>
                                <div class="pelapor-text" title="<?= simo_e($teks_pelapor); ?>"><?= simo_e($teks_pelapor); ?></div>
                            </td>

                            <td>
                                <div class="pelapor-text" title="<?= simo_e($teks_departemen !== "" ? $teks_departemen : "-"); ?>"><?= simo_e($teks_departemen !== "" ? $teks_departemen : "-"); ?></div>
                            </td>

                            <td>
                                <div class="pelapor-text" title="<?= simo_e($teks_pembuat); ?>"><?= simo_e($teks_pembuat); ?></div>
                            </td>

                            <td>
                                <div class="jenis-chips">
                                    <?php if ($daftar_jenis_row): foreach ($daftar_jenis_row as $jn): ?>
                                        <span class="jenis-chip"><?= simo_e($jn); ?></span>
                                    <?php endforeach; else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <div class="uraian-text" title="<?= simo_e($teks_uraian); ?>"><?= simo_e($teks_uraian); ?></div>
                            </td>

                            <td>
                                <div class="pelapor-text" title="<?= simo_e($teks_lokasi); ?>"><?= simo_e($teks_lokasi); ?></div>
                            </td>

                            <?php if ($view_pekerjaan === "tim"): ?>
                                <td>
                                    <span class="jumlah-karyawan-table"><?= (int) ($row["jumlah_karyawan"] ?? 0); ?> Karyawan</span>
                                </td>
                            <?php else: ?>
                                <td>
                                    <div class="karyawan-list" title="<?= simo_e($row["nama_karyawan"] ?? "-"); ?>"><?= simo_e($row["nama_karyawan"] ?? "-"); ?></div>
                                </td>
                            <?php endif; ?>

                            <td class="nowrap"><?= simo_tanggal_html($ts_order); ?></td>
                            <td class="nowrap"><?= simo_tanggal_html($ts_mulai); ?></td>
                            <td class="nowrap"><?= simo_tanggal_html($ts_target); ?></td>

                            <td>
                                <span class="status-badge <?= $class_status; ?>"><?= simo_e($teks_status); ?></span>
                            </td>

                            <td>
                                <div class="aksi-group">

                                    <?php if ($status === "Selesai"): ?>
                                        <button
                                            type="button"
                                            class="btn-aksi btn-edit btn-edit-disabled"
                                            title="Tidak dapat diedit karena pekerjaan sudah selesai"
                                            aria-disabled="true"
                                            disabled
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
                                            </svg>
                                        </button>
                                    <?php else: ?>
                                        <a
                                            href="edit_pekerjaan.php?id=<?= (int) $row["id_pekerjaan"]; ?>"
                                            class="btn-aksi btn-edit"
                                            title="Edit"
                                        >
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <a
                                        href="<?= simo_e($file_halaman); ?>?hapus=<?= (int) $row["id_pekerjaan"]; ?>"
                                        class="btn-aksi btn-hapus tombolHapus"
                                        title="Hapus"
                                        data-id="<?= (int) $row["id_pekerjaan"]; ?>"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18"/>
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                        </svg>
                                    </a>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="<?= (int) $jumlah_kolom; ?>" class="empty-row">
                            <?= simo_e($pesan_kosong_aktif); ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>

        </div>

        <div class="pagination-row section-pagination">

            <div class="pagination-info">
                <?php if ($total_aktif > 0): ?>
                    Menampilkan <?= $mulai_aktif; ?>-<?= $akhir_aktif; ?> dari <?= $total_aktif; ?> data
                <?php else: ?>
                    Tidak ada data
                <?php endif; ?>
            </div>

            <div class="pagination">

                <a
                    href="<?= simo_url_halaman($file_halaman, max(1, $halaman_aktif - 1), $query_string_dasar, $parameter_halaman_aktif); ?>"
                    class="<?= $halaman_aktif <= 1 ? "disabled" : ""; ?>"
                >‹</a>

                <?php for ($p = 1; $p <= $total_halaman_aktif; $p++): ?>
                    <a
                        href="<?= simo_url_halaman($file_halaman, $p, $query_string_dasar, $parameter_halaman_aktif); ?>"
                        class="<?= $p == $halaman_aktif ? "active" : ""; ?>"
                    ><?= $p; ?></a>
                <?php endfor; ?>

                <a
                    href="<?= simo_url_halaman($file_halaman, min($total_halaman_aktif, $halaman_aktif + 1), $query_string_dasar, $parameter_halaman_aktif); ?>"
                    class="<?= $halaman_aktif >= $total_halaman_aktif ? "disabled" : ""; ?>"
                >›</a>

            </div>

        </div>

    </div>

    <div class="footer-note">
        &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
    </div>

</div>

</div>

<!-- MODAL HAPUS -->
<div class="delete-modal-overlay" id="deleteModal">
    <div class="delete-modal-box">

        <div class="delete-modal-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18"/>
                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                <path d="M10 11v5"/>
                <path d="M14 11v5"/>
            </svg>
        </div>

        <h3>Hapus Pekerjaan?</h3>

        <p>Pekerjaan yang dihapus akan menghapus data terkait pekerjaan tersebut.</p>

        <div class="delete-modal-actions">
            <button type="button" class="delete-modal-btn delete-modal-cancel" id="batalHapus">Batal</button>
            <button type="button" class="delete-modal-btn delete-modal-confirm" id="konfirmasiHapus">Ya, Hapus</button>
        </div>

    </div>
</div>

<!-- MODAL KAMERA -->
<div class="kamera-modal" id="kameraModal" role="dialog" aria-modal="true" aria-label="Ambil foto">
    <video id="kameraVideo" playsinline muted autoplay></video>
    <canvas id="kameraCanvas" hidden></canvas>

    <button type="button" class="kamera-tutup" id="kameraTutup" aria-label="Tutup kamera">&times;</button>

    <div class="kamera-bar">
        <span class="kamera-spacer"></span>
        <button type="button" class="kamera-shutter" id="kameraJepret" aria-label="Ambil foto"></button>
        <button type="button" class="kamera-ganti" id="kameraGanti" aria-label="Ganti kamera">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 0 0-15.5-6.2L3 8"/>
                <path d="M3 3v5h5"/>
                <path d="M3 12a9 9 0 0 0 15.5 6.2L21 16"/>
                <path d="M21 21v-5h-5"/>
            </svg>
        </button>
    </div>
</div>

<script>
/* DATA DARI PHP */
const daftarKaryawan = <?= json_encode($data_karyawan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const pilihanAwal = <?= json_encode($id_terpilih_old); ?>;
const daftarDepartemen = <?= json_encode($data_departemen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const fileHalaman = <?= json_encode($file_halaman); ?>;

/* ELEMENT */
const formPekerjaan = document.getElementById("formPekerjaan");
const jumlahKaryawan = document.getElementById("jumlahKaryawan");
const karyawanContainer = document.getElementById("karyawanContainer");

const tanggalOrderMasuk = document.getElementById("tanggalOrderMasuk");
const tanggalMulai = document.getElementById("tanggalMulai");
const targetSelesai = document.getElementById("targetSelesai");

const fotoInput = document.getElementById("fotoPekerjaan");
const fotoPreview = document.getElementById("fotoPreview");
const fotoPreviewImg = document.getElementById("fotoPreviewImg");
const fotoHapusBtn = document.getElementById("fotoHapusBtn");

/* TANGGAL & JAM otomatis (dilaporkan & rencana = sekarang, selesai = +2 jam) */
function dua(n) { return (n < 10 ? "0" : "") + n; }
function fmtTgl(d) { return d.getFullYear() + "-" + dua(d.getMonth() + 1) + "-" + dua(d.getDate()); }
function fmtJam(d) { return dua(d.getHours()) + ":" + dua(d.getMinutes()); }
function fmtDT(d) { return fmtTgl(d) + "T" + fmtJam(d); }

function isiWaktuAwal() {
    const sekarang = new Date();
    const target = new Date(sekarang.getTime() + 2 * 3600 * 1000);

    if (!tanggalOrderMasuk.value) tanggalOrderMasuk.value = fmtDT(sekarang);
    if (!tanggalMulai.value) tanggalMulai.value = fmtDT(sekarang);
    if (!targetSelesai.value) targetSelesai.value = fmtDT(target);
}

isiWaktuAwal();

/* FOTO */
function resetFoto() {
    fotoInput.value = "";
    fotoPreviewImg.src = "";
    fotoPreview.classList.remove("show");
}

const fotoKamera = document.getElementById("fotoKamera");
const fotoGaleri = document.getElementById("fotoGaleri");

/* KAMERA LANGSUNG (butuh HTTPS atau localhost) */
const kameraModal = document.getElementById("kameraModal");
const kameraVideo = document.getElementById("kameraVideo");
const kameraCanvas = document.getElementById("kameraCanvas");
const kameraGanti = document.getElementById("kameraGanti");

let kameraStream = null;
let kameraArah = "environment";

function kameraLangsungTersedia() {
    return window.isSecureContext &&
        navigator.mediaDevices &&
        typeof navigator.mediaDevices.getUserMedia === "function";
}

function hentikanKamera() {
    if (kameraStream) {
        kameraStream.getTracks().forEach(function (t) { t.stop(); });
        kameraStream = null;
    }
    kameraVideo.srcObject = null;
}

function tutupKamera() {
    hentikanKamera();
    kameraModal.classList.remove("show");
    document.body.style.overflow = "";
}

async function mulaiKamera() {

    hentikanKamera();

    try {
        kameraStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: { ideal: kameraArah },
                width: { ideal: 1920 },
                height: { ideal: 1080 }
            },
            audio: false
        });

        kameraVideo.srcObject = kameraStream;
        kameraVideo.classList.toggle("cermin", kameraArah === "user");
        await kameraVideo.play();

        const perangkat = await navigator.mediaDevices.enumerateDevices();
        const jumlahKamera = perangkat.filter(function (d) { return d.kind === "videoinput"; }).length;
        kameraGanti.style.visibility = jumlahKamera > 1 ? "visible" : "hidden";

    } catch (err) {

        tutupKamera();

        if (err && (err.name === "NotAllowedError" || err.name === "SecurityError")) {
            alert("Izin kamera ditolak. Aktifkan izin kamera untuk situs ini di pengaturan browser, lalu coba lagi.");
        } else {
            fotoKamera.click();
        }
    }
}

document.getElementById("btnKamera").addEventListener("click", function () {

    if (!kameraLangsungTersedia()) {
        fotoKamera.click();
        return;
    }

    kameraArah = "environment";
    kameraModal.classList.add("show");
    document.body.style.overflow = "hidden";
    mulaiKamera();
});

kameraGanti.addEventListener("click", function () {
    kameraArah = kameraArah === "environment" ? "user" : "environment";
    mulaiKamera();
});

document.getElementById("kameraTutup").addEventListener("click", tutupKamera);

document.getElementById("kameraJepret").addEventListener("click", function () {

    const lebar = kameraVideo.videoWidth;
    const tinggi = kameraVideo.videoHeight;

    if (!lebar || !tinggi) {
        return;
    }

    kameraCanvas.width = lebar;
    kameraCanvas.height = tinggi;
    kameraCanvas.getContext("2d").drawImage(kameraVideo, 0, 0, lebar, tinggi);

    const kualitas = [0.9, 0.8, 0.7, 0.6, 0.5];

    function coba(i) {
        kameraCanvas.toBlob(function (blob) {

            if (!blob) {
                alert("Gagal mengambil foto. Coba lagi.");
                return;
            }

            if (blob.size > 5 * 1024 * 1024 && i < kualitas.length - 1) {
                coba(i + 1);
                return;
            }

            if (blob.size > 5 * 1024 * 1024) {
                alert("Ukuran foto maksimal 5 MB.");
                return;
            }

            const file = new File([blob], "kamera_" + Date.now() + ".jpg", { type: "image/jpeg" });
            const dt = new DataTransfer();
            dt.items.add(file);
            fotoInput.files = dt.files;

            fotoPreviewImg.src = URL.createObjectURL(blob);
            fotoPreview.classList.add("show");

            tutupKamera();

        }, "image/jpeg", kualitas[i]);
    }

    coba(0);
});

window.addEventListener("pagehide", hentikanKamera);

document.getElementById("btnGaleri").addEventListener("click", function () {
    fotoGaleri.click();
});

function pilihFoto(sumber) {

    const file = sumber.files[0];

    if (!file) {
        return;
    }

    const tipeBoleh = ["image/jpeg", "image/png", "image/webp"];

    if (!tipeBoleh.includes(file.type)) {
        alert("Format foto harus JPG, PNG, atau WEBP.");
        sumber.value = "";
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        alert("Ukuran foto maksimal 5 MB.");
        sumber.value = "";
        return;
    }

    const dt = new DataTransfer();
    dt.items.add(file);
    fotoInput.files = dt.files;

    sumber.value = "";

    const reader = new FileReader();

    reader.onload = function (e) {
        fotoPreviewImg.src = e.target.result;
        fotoPreview.classList.add("show");
    };

    reader.readAsDataURL(file);
}

fotoKamera.addEventListener("change", function () { pilihFoto(this); });
fotoGaleri.addEventListener("change", function () { pilihFoto(this); });

fotoHapusBtn.addEventListener("click", resetFoto);

/* DEPARTEMEN: ketik, saran muncul */
(function () {

    const input = document.getElementById("departemenAsal");
    const list = document.getElementById("comboList");
    const combo = document.getElementById("comboDepartemen");

    let aktif = -1;

    function tutup() {
        list.classList.remove("show");
        aktif = -1;
    }

    function pilih(nilai) {
        input.value = nilai;
        tutup();
    }

    function tandai() {
        const item = list.querySelectorAll("li[data-nilai]");
        item.forEach(function (li, i) {
            li.classList.toggle("aktif", i === aktif);
            if (i === aktif) { li.scrollIntoView({ block: "nearest" }); }
        });
    }

    function render() {

        const kata = input.value.trim().toLowerCase();

        const hasil = daftarDepartemen.filter(function (d) {
            return kata === "" || d.toLowerCase().includes(kata);
        });

        list.innerHTML = "";
        aktif = -1;

        if (hasil.length === 0) {
            const li = document.createElement("li");
            li.className = "kosong";
            li.textContent = daftarDepartemen.length === 0
                ? "Belum ada data departemen. Ketik manual."
                : "Tidak ada saran. Tulisan Anda akan dipakai apa adanya.";
            list.appendChild(li);
        } else {
            hasil.forEach(function (d) {

                const li = document.createElement("li");
                li.dataset.nilai = d;
                li.setAttribute("role", "option");

                const posisi = kata === "" ? -1 : d.toLowerCase().indexOf(kata);

                if (posisi >= 0) {
                    li.appendChild(document.createTextNode(d.slice(0, posisi)));
                    const tebal = document.createElement("mark");
                    tebal.textContent = d.slice(posisi, posisi + kata.length);
                    li.appendChild(tebal);
                    li.appendChild(document.createTextNode(d.slice(posisi + kata.length)));
                } else {
                    li.textContent = d;
                }

                li.addEventListener("mousedown", function (e) {
                    e.preventDefault();
                    pilih(d);
                });

                list.appendChild(li);
            });
        }

        list.classList.add("show");
    }

    input.addEventListener("input", render);
    input.addEventListener("focus", render);

    input.addEventListener("keydown", function (e) {

        const item = list.querySelectorAll("li[data-nilai]");

        if (e.key === "ArrowDown" && item.length) {
            e.preventDefault();
            if (!list.classList.contains("show")) { render(); return; }
            aktif = (aktif + 1) % item.length;
            tandai();
        } else if (e.key === "ArrowUp" && item.length) {
            e.preventDefault();
            aktif = (aktif - 1 + item.length) % item.length;
            tandai();
        } else if (e.key === "Enter" && aktif >= 0 && item[aktif]) {
            e.preventDefault();
            pilih(item[aktif].dataset.nilai);
        } else if (e.key === "Escape") {
            tutup();
        }
    });

    document.addEventListener("click", function (e) {
        if (!combo.contains(e.target)) { tutup(); }
    });

    input.addEventListener("blur", function () {
        setTimeout(tutup, 120);
    });
})();

/* SELECT KARYAWAN */
function buatKaryawanSelect(nomor, nilaiAwal) {

    const wrapper = document.createElement("div");
    wrapper.className = "karyawan-item-row";

    const label = document.createElement("div");
    label.className = "karyawan-number";
    label.textContent = "Karyawan " + nomor;

    const select = document.createElement("select");
    select.className = "karyawan-select";
    select.name = "id_karyawan[]";
    select.required = true;

    const optionAwal = document.createElement("option");
    optionAwal.value = "";
    optionAwal.textContent = "Pilih nama karyawan";
    select.appendChild(optionAwal);

    daftarKaryawan.forEach(function (karyawan) {
        const option = document.createElement("option");
        option.value = karyawan.id_user;
        option.textContent = karyawan.nama;
        select.appendChild(option);
    });

    if (nilaiAwal) {
        select.value = nilaiAwal;
    }

    select.addEventListener("change", updatePilihanKaryawan);

    wrapper.appendChild(label);
    wrapper.appendChild(select);

    return wrapper;
}

function pilihanSekarang() {
    const hasil = [];
    karyawanContainer.querySelectorAll(".karyawan-select").forEach(function (s) { hasil.push(s.value); });
    return hasil;
}

function renderKaryawan(awal) {

    const jumlah = parseInt(jumlahKaryawan.value) || 1;

    karyawanContainer.innerHTML = "";

    for (let i = 1; i <= jumlah; i++) {
        karyawanContainer.appendChild(buatKaryawanSelect(i, awal[i - 1]));
    }

    updatePilihanKaryawan();
}

function updatePilihanKaryawan() {

    const semuaSelect = karyawanContainer.querySelectorAll(".karyawan-select");
    const nilaiDipilih = [];

    semuaSelect.forEach(function (select) {
        if (select.value !== "") {
            nilaiDipilih.push(select.value);
        }
    });

    semuaSelect.forEach(function (select) {

        const nilaiSaatIni = select.value;

        Array.from(select.options).forEach(function (option) {

            if (option.value === "") {
                return;
            }

            option.disabled =
                nilaiDipilih.includes(option.value) &&
                option.value !== nilaiSaatIni;
        });
    });
}

jumlahKaryawan.addEventListener("change", function () { renderKaryawan(pilihanSekarang()); });

renderKaryawan(pilihanAwal);

/* VALIDASI SUBMIT */
formPekerjaan.addEventListener("submit", function (event) {

    if (document.querySelectorAll('input[name="jenis_perbaikan[]"]:checked').length === 0) {
        alert("Pilih satu jenis perbaikan.");
        event.preventDefault();
        return;
    }

    const dipilih = pilihanSekarang().filter(function (v) { return v !== ""; });

    if (dipilih.length !== parseInt(jumlahKaryawan.value)) {
        alert("Silakan pilih semua nama karyawan sesuai jumlah yang ditentukan.");
        event.preventDefault();
        return;
    }

    if (new Set(dipilih).size !== dipilih.length) {
        alert("Karyawan yang dituju tidak boleh sama.");
        event.preventDefault();
        return;
    }

    if (
        tanggalOrderMasuk.value === "" ||
        tanggalMulai.value === "" ||
        targetSelesai.value === ""
    ) {
        alert("Waktu dilaporkan, rencana dikerjakan, serta target selesai wajib diisi.");
        event.preventDefault();
        return;
    }

    const waktuOrder = new Date(tanggalOrderMasuk.value);
    const waktuMulai = new Date(tanggalMulai.value);
    const waktuSelesai = new Date(targetSelesai.value);

    if (waktuMulai < waktuOrder) {
        alert("Rencana dikerjakan tidak boleh sebelum waktu dilaporkan.");
        event.preventDefault();
        return;
    }

    if (waktuSelesai <= waktuMulai) {
        alert("Tanggal selesai harus setelah rencana dikerjakan.");
        event.preventDefault();
        return;
    }

    tutupKamera();

    const simpan = document.getElementById("btnSimpan");
    simpan.disabled = true;
    simpan.textContent = "Menyimpan...";
});

/* KLIK BARIS -> DETAIL */
function bukaDetail(baris) {
    window.location.href = "detail_pekerjaan.php?id=" + encodeURIComponent(baris.dataset.id);
}

document.querySelectorAll("tr.row-klik").forEach(function (baris) {

    baris.addEventListener("click", function (event) {

        if (event.target.closest(".aksi-group")) {
            return;
        }

        bukaDetail(baris);
    });

    baris.addEventListener("keydown", function (event) {

        if (event.target !== baris) {
            return;
        }

        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            bukaDetail(baris);
        }
    });
});

/* POPUP HAPUS */
const deleteModal = document.getElementById("deleteModal");
const batalHapus = document.getElementById("batalHapus");
const konfirmasiHapus = document.getElementById("konfirmasiHapus");

let idPekerjaanHapus = "";

document.querySelectorAll(".tombolHapus").forEach(function (tombol) {

    tombol.addEventListener("click", function (event) {

        event.preventDefault();

        idPekerjaanHapus = this.dataset.id || "";

        deleteModal.classList.add("show");
    });
});

batalHapus.addEventListener("click", function () {
    deleteModal.classList.remove("show");
    idPekerjaanHapus = "";
});

konfirmasiHapus.addEventListener("click", function () {

    if (idPekerjaanHapus === "") {
        return;
    }

    window.location.href = fileHalaman + "?hapus=" + encodeURIComponent(idPekerjaanHapus);
});

deleteModal.addEventListener("click", function (event) {
    if (event.target === deleteModal) {
        deleteModal.classList.remove("show");
        idPekerjaanHapus = "";
    }
});

document.addEventListener("keydown", function (event) {

    if (event.key === "Escape") {
        tutupKamera();
        deleteModal.classList.remove("show");
        idPekerjaanHapus = "";
    }
});

/* TANDAI BARIS BARU */
(function () {

    const url = new URL(window.location.href);

    if (url.searchParams.has("baru") || url.searchParams.has("pesan")) {
        url.searchParams.delete("baru");
        url.searchParams.delete("pesan");
        history.replaceState(null, "", url.pathname + url.search + url.hash);
    }

    const barisBaru = document.querySelector("tr.row-baru");

    if (!barisBaru) return;

    setTimeout(function () {
        barisBaru.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 300);

    setTimeout(function () {
        barisBaru.classList.remove("row-baru");
    }, 4500);
})();
/* TOMBOL TAMBAH PEKERJAAN: buka form lalu langsung geser ke form */
(function () {

    const cardForm = document.getElementById("cardForm");
    const btnTambah = document.getElementById("btnTambah");
    const btnTutupForm = document.getElementById("btnTutupForm");

    function bukaForm() {
        cardForm.classList.add("show");
        cardForm.scrollIntoView({ behavior: "smooth", block: "start" });

        const pertama = cardForm.querySelector("input[type=text]:not([hidden])");
        if (pertama) {
            setTimeout(function () { pertama.focus({ preventScroll: true }); }, 350);
        }
    }

    function tutupForm() {
        cardForm.classList.remove("show");
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    btnTambah.addEventListener("click", bukaForm);
    btnTutupForm.addEventListener("click", tutupForm);
})();

</script>

</body>
</html>
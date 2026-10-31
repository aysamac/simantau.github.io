<?php

include "../config/session.php";

/* =========================================================
   CEK LOGIN
========================================================= */
if (
    !isset($_SESSION["id_user"]) ||
    $_SESSION["role"] != "Karyawan"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/* =========================================================
   PASTIKAN BUKTI MEMILIKI IDENTITAS PENGIRIM
========================================================= */
$cek_kolom_bukti = mysqli_query(
    $koneksi,
    "SHOW COLUMNS FROM bukti_pekerjaan LIKE 'id_user'"
);

if ($cek_kolom_bukti && mysqli_num_rows($cek_kolom_bukti) == 0) {
    mysqli_query(
        $koneksi,
        "ALTER TABLE bukti_pekerjaan
         ADD COLUMN id_user INT NULL
         AFTER id_pekerjaan"
    );
}

/* =========================================================
   DATA LOGIN
========================================================= */
$id_karyawan = (int) $_SESSION["id_user"];

/* =========================================================
   CEK ID PEKERJAAN
========================================================= */
if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: tugas.php");
    exit;
}

$id_pekerjaan = (int) $_GET["id"];

/* =========================================================
   DATA KARYAWAN LOGIN
========================================================= */
$query_karyawan = mysqli_query(
    $koneksi,
    "
    SELECT
        u.id_user,
        u.nama,
        u.jabatan,
        u.id_divisi,
        u.status,
        d.nama_divisi
    FROM users u
    LEFT JOIN divisi d
        ON u.id_divisi = d.id_divisi
    WHERE u.id_user = '$id_karyawan'
    LIMIT 1
    "
);

if (!$query_karyawan || mysqli_num_rows($query_karyawan) == 0) {
    header("Location: tugas.php");
    exit;
}

$karyawan      = mysqli_fetch_assoc($query_karyawan);
$id_divisi     = (int) ($karyawan["id_divisi"] ?? 0);
$nama_karyawan = $karyawan["nama"] ?? "-";
$nama_divisi   = $karyawan["nama_divisi"] ?? "-";

/* =========================================================
   DATA PEKERJAAN
========================================================= */
$query_pekerjaan = mysqli_query(
    $koneksi,
    "
    SELECT
        p.*,
        d.nama_divisi,
        u.nama AS nama_penanggung_jawab
    FROM pekerjaan p
    LEFT JOIN divisi d
        ON p.id_divisi = d.id_divisi
    LEFT JOIN users u
        ON u.id_user = p.id_karyawan
    LEFT JOIN pekerjaan_karyawan pk
        ON pk.id_pekerjaan = p.id_pekerjaan
        AND pk.id_user = '$id_karyawan'
    WHERE p.id_pekerjaan = '$id_pekerjaan'
    AND p.id_divisi = '$id_divisi'
    AND (
        pk.id_user = '$id_karyawan'
        OR
        (
            pk.id_user IS NULL
            AND p.id_karyawan = '$id_karyawan'
        )
    )
    LIMIT 1
    "
);

if (!$query_pekerjaan || mysqli_num_rows($query_pekerjaan) == 0) {
    header("Location: tugas.php");
    exit;
}

$pekerjaan = mysqli_fetch_assoc($query_pekerjaan);

$status_sekarang = $pekerjaan["status"] ?? "Belum Dikerjakan";

/* =========================================================
   STATUS YANG TIDAK DAPAT DIALIHKAN
========================================================= */
if (
    $status_sekarang == "Selesai" ||
    $status_sekarang == "Menunggu Konfirmasi"
) {
    header("Location: detail_tugas.php?id=" . $id_pekerjaan);
    exit;
}

/* =========================================================
   DATA CALON KARYAWAN
   Hanya: divisi sama, bukan diri sendiri, status aktif, bukan Admin
========================================================= */
$data_karyawan = [];

$query_calon = mysqli_query(
    $koneksi,
    "
    SELECT
        id_user,
        nama,
        jabatan
    FROM users
    WHERE id_divisi = '$id_divisi'
    AND id_user != '$id_karyawan'
    AND status = 'Aktif'
    AND (
        jabatan IS NULL
        OR jabatan NOT LIKE '%Admin%'
    )
    ORDER BY nama ASC
    "
);

if ($query_calon) {
    while ($row = mysqli_fetch_assoc($query_calon)) {
        $data_karyawan[] = $row;
    }
}

/* =========================================================
   PESAN ERROR
========================================================= */
$error = "";

/* =========================================================
   PROSES ALIH PEKERJAAN
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    /* ---------- DATA FORM ---------- */

    $jumlah_karyawan_pengganti = isset($_POST["jumlah_karyawan_pengganti"]) ? (int) $_POST["jumlah_karyawan_pengganti"] : 1;
    $id_karyawan_pengganti_list = $_POST["id_karyawan_pengganti"] ?? [];
    if (!is_array($id_karyawan_pengganti_list)) $id_karyawan_pengganti_list = [$id_karyawan_pengganti_list];
    $id_karyawan_pengganti_list = array_values(array_filter(array_map("intval", $id_karyawan_pengganti_list), function ($id) { return $id > 0; }));
    $id_karyawan_pengganti = $id_karyawan_pengganti_list[0] ?? 0;

    $deskripsi_pengalihan = trim($_POST["deskripsi_pengalihan"] ?? "");

    $keterangan_progres = trim($_POST["keterangan_progres"] ?? "");

    $foto_progres_data_list = $_POST["foto_progres_data"] ?? [];

    if (!is_array($foto_progres_data_list)) $foto_progres_data_list = [$foto_progres_data_list];

    $foto_progres_data_list = array_values(array_filter($foto_progres_data_list, function ($foto) { return is_string($foto) && trim($foto) !== ""; }));

    $ada_foto = count($foto_progres_data_list) > 0;

    if ($jumlah_karyawan_pengganti < 1 || $jumlah_karyawan_pengganti > min(10, count($data_karyawan))) {
        $error = "Jumlah karyawan penerima tidak valid.";
    } elseif (count($id_karyawan_pengganti_list) !== $jumlah_karyawan_pengganti) {
        $error = "Silakan pilih tepat " . $jumlah_karyawan_pengganti . " karyawan penerima.";
    } elseif (count(array_unique($id_karyawan_pengganti_list)) !== count($id_karyawan_pengganti_list)) {
        $error = "Karyawan tidak boleh dipilih lebih dari satu kali.";
    } elseif (in_array($id_karyawan, $id_karyawan_pengganti_list, true)) {
        $error = "Pekerjaan tidak dapat dialihkan kepada diri sendiri.";
    } elseif ($deskripsi_pengalihan === "") {
        $error = "Alasan atau keterangan pengalihan wajib diisi.";
    } elseif (strlen($deskripsi_pengalihan) > 2000) {
        $error = "Keterangan pengalihan maksimal 2000 karakter.";
    } elseif (strlen($keterangan_progres) > 2000) {
        $error = "Keterangan progres maksimal 2000 karakter.";
    } elseif (count($foto_progres_data_list) > 5) {
        $error = "Maksimal 5 foto progres pekerjaan.";
    } elseif ($ada_foto) {
        foreach ($foto_progres_data_list as $foto_data) {
            if (!preg_match('#^data:image/(jpeg|png|webp);base64,#i', $foto_data)) {
                $error = "Format foto harus JPG, JPEG, PNG, atau WEBP.";
                break;
            }
            $parts = explode(",", $foto_data, 2);
            $gambar_validasi = count($parts) === 2 ? base64_decode($parts[1], true) : false;
            if ($gambar_validasi === false || strlen($gambar_validasi) > 5 * 1024 * 1024) {
                $error = "Foto progres gagal diproses atau ukurannya melebihi 5 MB.";
                break;
            }
        }
    }

    if ($error == "") {
        $id_penerima_sql = implode(",", $id_karyawan_pengganti_list);
        $query_penerima = mysqli_query(
            $koneksi,
            "SELECT id_user FROM users WHERE id_user IN ($id_penerima_sql) AND id_divisi = '$id_divisi' AND status = 'Aktif' AND (jabatan IS NULL OR jabatan NOT LIKE '%Admin%')"
        );
        if (!$query_penerima || mysqli_num_rows($query_penerima) !== $jumlah_karyawan_pengganti) {
            $error = "Karyawan yang dipilih harus aktif, berasal dari divisi yang sama, dan bukan Admin.";
        }
    }

    /* ---------- CEK PEKERJAAN ULANG ---------- */

    if ($error == "") {

        $query_cek = mysqli_query(
            $koneksi,
            "
            SELECT
                id_pekerjaan,
                id_karyawan,
                status
            FROM pekerjaan
            WHERE id_pekerjaan = '$id_pekerjaan'
            AND id_divisi = '$id_divisi'
            LIMIT 1
            "
        );

        if (!$query_cek || mysqli_num_rows($query_cek) == 0) {

            $error = "Data pekerjaan tidak ditemukan.";

        } else {

            $data_cek = mysqli_fetch_assoc($query_cek);

            $query_anggota_terlibat_cek = mysqli_query($koneksi, "SELECT id FROM pekerjaan_karyawan WHERE id_pekerjaan='$id_pekerjaan' AND id_user='$id_karyawan' LIMIT 1");

            $is_anggota_terlibat_cek = $query_anggota_terlibat_cek && mysqli_num_rows($query_anggota_terlibat_cek) > 0;

            if ((int) $data_cek["id_karyawan"] !== $id_karyawan && !$is_anggota_terlibat_cek) {

                $error = "Anda bukan anggota aktif pekerjaan ini.";

            } elseif (
                $data_cek["status"] == "Selesai" ||
                $data_cek["status"] == "Menunggu Konfirmasi"
            ) {

                $error = "Pekerjaan dengan status tersebut tidak dapat dialihkan.";

            }
        }
    }

    /* ---------- PROSES DATABASE ---------- */

    if ($error == "") {

        $nama_file_foto      = [];
        $file_foto_tersimpan = [];

        mysqli_begin_transaction($koneksi);

        try {

            /* ESCAPE DATA */

            $deskripsi_db = mysqli_real_escape_string($koneksi, $deskripsi_pengalihan);

            $keterangan_progres_db = mysqli_real_escape_string($koneksi, $keterangan_progres);

            if ($ada_foto) {

                $folder_upload = "../uploads/bukti/";

                if (!is_dir($folder_upload) && !mkdir($folder_upload, 0777, true)) {
                    throw new Exception("Folder uploads/bukti tidak dapat dibuat.");
                }

                foreach ($foto_progres_data_list as $index => $foto_data) {

                    $parts = explode(",", $foto_data, 2);

                    $gambar = count($parts) === 2 ? base64_decode($parts[1], true) : false;

                    if ($gambar === false || !getimagesizefromstring($gambar)) {
                        throw new Exception("Foto progres gagal diproses.");
                    }

                    if (strlen($gambar) > 5 * 1024 * 1024) {
                        throw new Exception("Ukuran setiap foto maksimal 5 MB.");
                    }

                    $nama_file = "progres_" . $id_pekerjaan . "_" . $id_karyawan . "_" . time() . "_" . ($index + 1) . "_" . bin2hex(random_bytes(3)) . ".jpg";

                    $path_upload = $folder_upload . $nama_file;

                    if (file_put_contents($path_upload, $gambar) === false || !file_exists($path_upload)) {
                        throw new Exception("Foto progres gagal disimpan.");
                    }

                    $nama_file_foto[]      = $nama_file;
                    $file_foto_tersimpan[] = $path_upload;
                }
            }

            $id_penerima_sql = implode(",", $id_karyawan_pengganti_list);

            $query_relasi_penerima = mysqli_query(
                $koneksi,
                "SELECT id, id_user FROM pekerjaan_karyawan WHERE id_pekerjaan = '$id_pekerjaan' AND id_user IN ($id_penerima_sql)"
            );

            if (!$query_relasi_penerima) {
                throw new Exception("Gagal memeriksa data karyawan penerima.");
            }

            $relasi_penerima_by_user = [];

            while ($row_relasi_penerima = mysqli_fetch_assoc($query_relasi_penerima)) {
                $relasi_penerima_by_user[(int) $row_relasi_penerima["id_user"]] = $row_relasi_penerima;
            }

            /* CEK RELASI KARYAWAN LAMA */

            $query_relasi_lama = mysqli_query(
                $koneksi,
                "
                SELECT id
                FROM pekerjaan_karyawan
                WHERE id_pekerjaan = '$id_pekerjaan'
                AND id_user = '$id_karyawan'
                LIMIT 1
                "
            );

            $relasi_lama = null;

            if ($query_relasi_lama && mysqli_num_rows($query_relasi_lama) > 0) {
                $relasi_lama = mysqli_fetch_assoc($query_relasi_lama);
            }

            /* 1. KARYAWAN LAMA: STATUS = SELESAI */

            if ($relasi_lama) {

                $update_lama = mysqli_query(
                    $koneksi,
                    "
                    UPDATE pekerjaan_karyawan
                    SET status_penugasan = 'Selesai'
                    WHERE id = '" . (int) $relasi_lama["id"] . "'
                    "
                );

                if (!$update_lama) {
                    throw new Exception("Gagal memperbarui status karyawan lama.");
                }
            }

            /* 2. JIKA KARYAWAN LAMA BELUM ADA RELASI: BUAT HISTORI SELESAI */

            if (!$relasi_lama) {

                $insert_lama = mysqli_query(
                    $koneksi,
                    "
                    INSERT INTO pekerjaan_karyawan
                    (
                        id_pekerjaan,
                        id_user,
                        status_penugasan
                    )
                    VALUES
                    (
                        '$id_pekerjaan',
                        '$id_karyawan',
                        'Selesai'
                    )
                    "
                );

                if (!$insert_lama) {
                    throw new Exception("Gagal menyimpan status selesai karyawan lama.");
                }
            }

            /* 3. KARYAWAN PENERIMA */

            foreach ($id_karyawan_pengganti_list as $id_penerima) {
                if (isset($relasi_penerima_by_user[$id_penerima])) {
                    $id_relasi_penerima = (int) $relasi_penerima_by_user[$id_penerima]["id"];
                    $update_penerima = mysqli_query(
                        $koneksi,
                        "UPDATE pekerjaan_karyawan SET status_penugasan = 'Peralihan' WHERE id = '$id_relasi_penerima'"
                    );
                    if (!$update_penerima) {
                        throw new Exception("Gagal mengubah status karyawan penerima.");
                    }
                } else {
                    $insert_penerima = mysqli_query(
                        $koneksi,
                        "INSERT INTO pekerjaan_karyawan (id_pekerjaan, id_user, status_penugasan) VALUES ('$id_pekerjaan', '$id_penerima', 'Peralihan')"
                    );
                    if (!$insert_penerima) {
                        throw new Exception("Gagal menambahkan karyawan penerima.");
                    }
                }
            }

            /* 4. PINDAHKAN PENANGGUNG JAWAB, STATUS PEKERJAAN = PERALIHAN */

            $update_pekerjaan = mysqli_query(
                $koneksi,
                "
                UPDATE pekerjaan
                SET
                    id_karyawan = '$id_karyawan_pengganti',
                       status = IF(status = 'Perlu Perbaikan', 'Perlu Perbaikan', 'Peralihan')
                WHERE id_pekerjaan = '$id_pekerjaan'
                "
            );

            if (!$update_pekerjaan) {
                throw new Exception("Gagal mengubah penanggung jawab pekerjaan.");
            }

            /* 5. SIMPAN BUKTI PROGRES (tabel bukti_pekerjaan, foto di uploads/bukti/) */

            if ($ada_foto || $keterangan_progres !== "") {

                $foto_db = mysqli_real_escape_string($koneksi, implode(",", $nama_file_foto));

                $insert_bukti = mysqli_query(
                    $koneksi,
                    "
                    INSERT INTO bukti_pekerjaan
                    (
                        id_pekerjaan,
                        id_user,
                        foto,
                        catatan_karyawan
                    )
                    VALUES
                    (
                        '$id_pekerjaan',
                        '$id_karyawan',
                        '$foto_db',
                        '$keterangan_progres_db'
                    )
                    "
                );

                if (!$insert_bukti) {
                    throw new Exception("Gagal menyimpan bukti progres pekerjaan: " . mysqli_error($koneksi));
                }
            }

            /* 6. RIWAYAT PENGALIHAN */

            foreach ($id_karyawan_pengganti_list as $id_penerima) {
                $insert_riwayat = mysqli_query(
                    $koneksi,
                    "INSERT INTO pengalihan_pekerjaan (id_pekerjaan, id_karyawan_pengaju, id_karyawan_pengganti, deskripsi_pengalihan, status_pengalihan, tanggal_pengajuan, tanggal_update) VALUES ('$id_pekerjaan', '$id_karyawan', '$id_penerima', '$deskripsi_db', 'Berhasil', NOW(), NOW())"
                );
                if (!$insert_riwayat) {
                    throw new Exception("Gagal menyimpan riwayat pengalihan.");
                }
            }

            /* 7. NOTIFIKASI */

            $cek_notifikasi = mysqli_query($koneksi, "SHOW TABLES LIKE 'notifikasi'");

            if ($cek_notifikasi && mysqli_num_rows($cek_notifikasi) > 0) {
                $nama_pengaju_db   = mysqli_real_escape_string($koneksi, $nama_karyawan);
                $nama_pekerjaan_db = mysqli_real_escape_string($koneksi, $pekerjaan["nama_pekerjaan"] ?? "Pekerjaan");
                $pesan             = "Pekerjaan " . $nama_pekerjaan_db . " telah dialihkan kepada Anda oleh " . $nama_pengaju_db . ".";
                $pesan_db          = mysqli_real_escape_string($koneksi, $pesan);
                foreach ($id_karyawan_pengganti_list as $id_penerima) {
                    mysqli_query(
                        $koneksi,
                        "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal) VALUES ('$id_penerima', '$pesan_db', 'Belum Dibaca', NOW())"
                    );
                }
            }

            /* COMMIT */

            mysqli_commit($koneksi);

            header("Location: tugas.php");
            exit;

        } catch (Exception $e) {

            mysqli_rollback($koneksi);

            /* HAPUS FOTO JIKA TRANSAKSI GAGAL */

            foreach ($file_foto_tersimpan as $file_baru) {
                if (file_exists($file_baru)) @unlink($file_baru);
            }

            $error = $e->getMessage();
        }
    }
}

/* =========================================================
   FORMAT TANGGAL
========================================================= */
function formatTanggalAlih($tanggal)
{
    if (empty($tanggal) || $tanggal == "0000-00-00") {
        return "-";
    }

    $timestamp = strtotime($tanggal);

    if (!$timestamp) {
        return "-";
    }

    return date("d M Y", $timestamp);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Alihkan Pekerjaan - SIMO</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #fff;
            color: #2c3644;
            min-height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .main {
            margin-left: 240px;
            width: calc(100% - 240px);
            min-height: 100vh;
        }

        .content {
            padding: 26px 32px 40px;
        }

        /* HEADER */

        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #1c2636;
            margin-bottom: 5px;
        }

        .page-header p {
            font-size: 13px;
            font-weight: 400;
            color: #667488;
        }

        /* CARD */

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 22px 25px;
            margin-bottom: 18px;
            box-shadow: 0 2px 10px rgba(20, 40, 70, .04);
        }

        /* PEKERJAAN */

        .job-header {
            padding-bottom: 15px;
            border-bottom: 1px solid #edf1f5;
        }

        .job-name {
            font-size: 18px;
            font-weight: 600;
            color: #1c2636;
            margin-bottom: 7px;
        }

        .job-info {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .job-info-item {
            font-size: 11px;
            color: #667488;
        }

        .job-info-item strong {
            color: #526176;
            font-weight: 600;
        }

        /* TITLE */

        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #26364d;
            margin-bottom: 16px;
        }

        /* FORM */

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #526176;
            margin-bottom: 7px;
        }

        .required {
            color: #b51f2a;
        }

        .optional {
            color: #8b96a5;
            font-size: 10px;
            font-weight: 400;
        }

        .form-select,
        .form-textarea,
        .form-file {
            width: 100%;
            border: 1px solid #d7dee7;
            border-radius: 8px;
            background: #fff;
            color: #26364d;
            font-size: 12px;
            outline: none;
        }

        .form-select {
            height: 42px;
            padding: 0 12px;
            cursor: pointer;
        }

        .form-textarea {
            min-height: 100px;
            padding: 11px 12px;
            resize: vertical;
            line-height: 1.6;
        }

        .form-file {
            padding: 10px 12px;
            cursor: pointer;
        }

        .form-select:focus,
        .form-textarea:focus,
        .form-file:focus {
            border-color: #b51f2a;
            box-shadow: 0 0 0 2px rgba(181, 31, 42, .08);
        }

        .form-help {
            margin-top: 6px;
            font-size: 10px;
            color: #8b96a5;
            line-height: 1.5;
        }

        /* ALERT */

        .alert {
            padding: 11px 13px;
            border-radius: 8px;
            margin-bottom: 17px;
            font-size: 11px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fff0f0;
            border: 1px solid #f0cccc;
            color: #bd3d3d;
        }

        /* PROGRESS BOX */

        .progress-box {
            padding: 15px;
            background: #fafbfc;
            border: 1px solid #edf1f5;
            border-radius: 9px;
            margin-bottom: 18px;
        }

        .progress-title {
            font-size: 13px;
            font-weight: 600;
            color: #26364d;
            margin-bottom: 4px;
        }

        .progress-description {
            font-size: 10px;
            color: #8b96a5;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        /* INFO */

        .info-box {
            margin-top: 16px;
            padding: 12px 14px;
            background: #f7f9fc;
            border-radius: 8px;
            color: #667488;
            font-size: 11px;
            line-height: 1.6;
        }

        .info-box strong {
            color: #526176;
            font-weight: 600;
        }

        /* ACTION */

        .action {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 39px;
            padding: 0 15px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel {
            background: #fff;
            border: 1px solid #d7dee7;
            color: #526176;
        }

        .btn-cancel:hover {
            background: #f8fafc;
        }

        .btn-submit {
            background: #b51f2a;
            border: 1px solid #b51f2a;
            color: #fff;
        }

        .btn-submit:hover {
            background: #991923;
        }

        .btn-submit svg {
            width: 15px;
            height: 15px;
            stroke: #fff;
        }

        /* FOOTER */

        .footer-note {
            text-align: center;
            font-size: 11px;
            color: #a3adba;
            padding: 18px 0 5px;
        }

        /* RESPONSIVE */

        @media (max-width: 800px) {

            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 20px;
            }

        }

        @media (max-width: 600px) {

            .content {
                padding: 16px;
            }

            .card {
                padding: 19px;
            }

            .job-info {
                flex-direction: column;
                gap: 5px;
            }

            .action {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                width: 100%;
            }

        }

.main,
.content{
    background:#fff !important;
}

.card{
    background:#fff !important;
    border:1px solid #d9dde3 !important;
    box-shadow:none !important;
    border-radius:10px;
}

.page-header h1{
    font-size:26px;
    font-weight:700;
    color:#1c2636;
}

.page-header p{
    font-size:14px;
    color:#667488;
}

.job-name{
    font-size:20px;
    font-weight:700;
    color:#172238;
}

.job-info{
    gap:20px;
}

.job-info-item{
    font-size:13px;
    color:#667488;
}

.job-info-item strong{
    color:#26364d;
    font-weight:700;
}

.section-title{
    font-size:16px;
    font-weight:700;
    color:#1c2636;
    margin-bottom:18px;
}

.form-label{
    font-size:13px;
    font-weight:700;
    color:#26364d;
    margin-bottom:8px;
}

.form-select,
.form-textarea,
.form-file{
    font-size:14px;
    border:1px solid #cfd6df;
    border-radius:8px;
}

.form-select{
    height:46px;
    padding:0 13px;
}

.form-textarea{
    min-height:110px;
    padding:12px 13px;
}

.form-help{
    font-size:11.5px;
    color:#7a8797;
}

.progress-box{
    background:#fff !important;
    border:1px solid #d9dde3 !important;
    border-radius:9px;
}

.progress-title{
    font-size:14px;
    font-weight:700;
    color:#26364d;
}

.progress-description{
    font-size:12px;
    color:#718096;
}

.info-box{
    background:#f8fafc !important;
    border:1px solid #d9dde3;
    border-radius:9px;
    color:#5f6d7d;
    font-size:12.5px;
}

.info-box strong{
    color:#26364d;
}

.action{
    border-top:1px solid #e5e8ec;
    padding-top:16px;
}

.btn{
    min-height:42px;
    font-size:13px;
    border-radius:8px;
}

.btn-cancel{
    border:1px solid #cfd6df;
    background:#fff;
    color:#526176;
}

.btn-submit{
    background:#b51f2a;
    border:1px solid #b51f2a;
    color:#fff;
}

@media (max-width:600px){

    .content{padding:16px;}

    .page-header h1{font-size:22px;}

    .page-header p{font-size:13px;line-height:1.5;}

    .card{padding:18px !important;}

    .job-name{font-size:18px;}

    .job-info{gap:7px;}

    .job-info-item{font-size:12.5px;}

    .section-title{font-size:15px;}

    .form-label{font-size:13px;}

    .form-select,.form-textarea,.form-file{font-size:13px;}

    .action{flex-direction:row;align-items:center;justify-content:space-between;}

    .btn{width:auto;min-height:42px;font-size:12.5px;}

}

body.dark-mode,body.dark-mode .main,body.dark-mode .content{background:#181b20!important;color:#eef1f5!important}

body.dark-mode .content{padding-top:48px!important}

body.dark-mode .page-header h1,body.dark-mode .job-name,body.dark-mode .section-title,body.dark-mode .form-label,body.dark-mode .progress-title{color:#f1f3f5!important}

body.dark-mode .page-header p,body.dark-mode .job-info-item,body.dark-mode .form-help,body.dark-mode .progress-description{color:#aeb7c5!important}

body.dark-mode .job-info-item strong,body.dark-mode .info-box strong{color:#e8edf5!important}

body.dark-mode .card{background:#1f2227!important;border-color:#3a3f47!important;box-shadow:none!important;color:#eef1f5!important}

body.dark-mode .form-select,body.dark-mode .form-textarea,body.dark-mode .form-file{background:#242830!important;color:#f1f3f5!important;border-color:#434a56!important}

body.dark-mode .form-select option{background:#242830!important;color:#f1f3f5!important}

body.dark-mode .progress-box{background:#20242a!important;border-color:#3a414c!important}

body.dark-mode .info-box{background:#20242a!important;border-color:#3a414c!important;color:#d5dce6!important}

body.dark-mode .action{border-top-color:#3a414c!important}

body.dark-mode .btn-cancel{background:#292e36!important;color:#e1e6ee!important;border-color:#444c59!important}

body.dark-mode .alert-error{background:#332426!important;border-color:#684047!important;color:#ffb8bf!important}

body.dark-mode .form-select:focus,body.dark-mode .form-textarea:focus,body.dark-mode .form-file:focus{border-color:#b51f2a!important;box-shadow:0 0 0 2px rgba(181,31,42,.18)!important}

.foto-progres-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:12px}

.foto-progres-slot{position:relative;aspect-ratio:1/1;border:1px dashed #cfd6df;border-radius:9px;background:#fafbfc;overflow:hidden;display:flex;align-items:center;justify-content:center;cursor:pointer}

.foto-progres-slot.empty:hover{background:#fff7f7;border-color:#b51f2a}

.foto-progres-slot img{width:100%;height:100%;object-fit:cover;display:block}

.foto-progres-plus{font-size:18px;line-height:1;color:#526176}

.foto-progres-remove{position:absolute;top:5px;right:5px;width:24px;height:24px;border:0;border-radius:50%;background:rgba(181,31,42,.92);color:#fff;font-size:15px;line-height:24px;cursor:pointer;z-index:2}

.foto-progres-input{display:none!important}

.foto-progres-name{margin-top:7px;font-size:11px;color:#7a8797;overflow-wrap:anywhere}

body.dark-mode .foto-progres-slot{background:#1d2026;border-color:#414854}

body.dark-mode .foto-progres-slot.empty{background:#20242b}

body.dark-mode .foto-progres-plus{color:#f1f3f5}

body.dark-mode .foto-progres-name{color:#b8c0cf}

@media(max-width:600px){.foto-progres-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}}

.foto-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:12px;max-width:840px;width:100%}

.foto-slot{position:relative;aspect-ratio:1/1;border:1px dashed #cfd6df;border-radius:9px;background:#fafbfc;overflow:hidden;display:flex;align-items:center;justify-content:center;cursor:pointer;min-width:0}

.foto-slot.empty:hover{background:#fff7f7;border-color:#b51f2a}

.foto-slot img{width:100%;height:100%;object-fit:cover;display:block}

.foto-plus{font-size:18px;line-height:1;color:#526176}

.foto-remove{position:absolute;top:5px;right:5px;width:24px;height:24px;border:0;border-radius:50%;background:rgba(181,31,42,.92);color:#fff;font-size:15px;line-height:24px;cursor:pointer;z-index:2}

.camera-box{width:100%;margin-top:12px;background:#101827;border-radius:10px;overflow:hidden;position:relative}

#video{width:100%;display:block;max-height:500px;object-fit:cover}

#canvas{display:none}

.camera-placeholder{min-height:220px;display:flex;align-items:center;justify-content:center;color:#aeb9c9;text-align:center;padding:30px;font-size:12px}

.camera-actions{display:flex;justify-content:center;gap:10px;padding:14px;background:#101827;flex-wrap:wrap}

.hidden{display:none!important}

.btn-camera{background:#b51f2a;color:#fff}

.btn-retake{background:#e9edf3;color:#34445a;display:none}

.sumber-overlay{position:fixed;inset:0;background:rgba(16,24,39,.55);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px}

.sumber-box{width:100%;max-width:360px;background:#fff;border-radius:14px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.25)}

.sumber-title{font-size:13px;font-weight:600;color:#26364d;margin-bottom:12px;text-align:center}

.sumber-btn{display:block;width:100%;border:1px solid #dce3ec;background:#f5f7fa;color:#26364d;border-radius:9px;padding:12px;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:8px}

.sumber-btn:hover{border-color:#b51f2a;background:#fff7f7}

.sumber-btn.sumber-batal{background:transparent!important;border:0!important;box-shadow:none!important;color:#aeb7c5;padding:8px 0;margin:2px 0 0}.sumber-btn.sumber-batal:hover{background:transparent!important;border:0!important;color:#f1f3f5}

@media(min-width:601px){.sumber-overlay{align-items:center}}

body.dark-mode .foto-slot{background:#1d2026;border-color:#414854}

body.dark-mode .foto-slot.empty{background:#20242b}

body.dark-mode .foto-plus{color:#f1f3f5}

body.dark-mode .camera-box,body.dark-mode .camera-actions{background:#171a1f}

body.dark-mode .sumber-box{background:#24272d;border:1px solid #383e48}

body.dark-mode .sumber-title{color:#f1f3f5}

body.dark-mode .sumber-btn{background:#2b3038;border-color:#424955;color:#e9edf3}

body.dark-mode .sumber-btn:hover{background:#343a44;border-color:#b51f2a}

@media(max-width:600px){.foto-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:6px}.foto-remove{width:22px;height:22px;line-height:22px}}

/* MODAL KONFIRMASI */

.confirm-overlay{position:fixed;inset:0;background:rgba(16,24,39,.55);display:none;align-items:center;justify-content:center;z-index:10000;padding:16px}

.confirm-overlay.active{display:flex}

.confirm-box{width:100%;max-width:400px;background:#fff;border-radius:14px;padding:26px 24px 20px;text-align:center;box-shadow:0 12px 36px rgba(0,0,0,.25);animation:confirmIn .18s ease}

@keyframes confirmIn{from{opacity:0;transform:translateY(8px) scale(.97)}to{opacity:1;transform:none}}

.confirm-icon{width:54px;height:54px;margin:0 auto 14px;border-radius:50%;background:#f9e8ea;display:flex;align-items:center;justify-content:center}

.confirm-icon svg{width:26px;height:26px;stroke:#b51f2a}

.confirm-title{font-size:17px;font-weight:700;color:#1c2636;margin-bottom:7px}

.confirm-text{font-size:13px;color:#667488;line-height:1.6}

.confirm-text strong{color:#26364d;font-weight:700}

.confirm-actions{display:flex;gap:10px;margin-top:20px}

.confirm-actions .btn{flex:1;min-height:42px}

.confirm-actions .btn-cancel{cursor:pointer}

body.dark-mode .confirm-box{background:#24272d;border:1px solid #383e48}

body.dark-mode .confirm-icon{background:#382326}

body.dark-mode .confirm-icon svg{stroke:#f07a80}

body.dark-mode .confirm-title{color:#f1f3f5}

body.dark-mode .confirm-text{color:#aeb7c5}

body.dark-mode .confirm-text strong{color:#e8edf5}

    </style>

</head>

<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<main class="main">

    <?php include "../includes/karyawan/topbar.php"; ?>

    <div class="content">

        <!-- HEADER -->

        <div class="page-header">

            <h1>Alihkan Pekerjaan</h1>

            <p>Pindahkan penanggung jawab pekerjaan kepada karyawan lain.</p>

        </div>

        <!-- INFORMASI PEKERJAAN -->

        <div class="card">

            <div class="job-header">

                <div class="job-name">
                    <?= htmlspecialchars($pekerjaan["nama_pekerjaan"] ?? "-"); ?>
                </div>

                <div class="job-info">

                    <div class="job-info-item">
                        Jenis:
                        <strong><?= htmlspecialchars($pekerjaan["jenis_pekerjaan"] ?? "-"); ?></strong>
                    </div>

                    <div class="job-info-item">
                        Divisi:
                        <strong><?= htmlspecialchars($nama_divisi); ?></strong>
                    </div>

                    <div class="job-info-item">
                        Target:
                        <strong><?= formatTanggalAlih($pekerjaan["target_selesai"] ?? ""); ?></strong>
                    </div>

                </div>

            </div>

        </div>

        <!-- FORM -->

        <div class="card">

            <?php if ($error != ""): ?>

                <div class="alert alert-error">
                    <?= htmlspecialchars($error); ?>
                </div>

            <?php endif; ?>

            <div class="section-title">Pengalihan Pekerjaan</div>

            <form method="POST" action="" enctype="multipart/form-data" id="formAlih">

                <!-- KARYAWAN PENERIMA -->

                <div class="form-group">
                    <label for="jumlah_karyawan_pengganti" class="form-label">Jumlah Karyawan <span class="required">*</span></label>
                    <select name="jumlah_karyawan_pengganti" id="jumlah_karyawan_pengganti" class="form-select" required>
                        <?php for ($i = 1; $i <= max(1, min(10, count($data_karyawan))); $i++): ?>
                            <option value="<?= $i; ?>" <?= ((int) ($_POST["jumlah_karyawan_pengganti"] ?? 1) === $i) ? "selected" : ""; ?>><?= $i; ?> Karyawan</option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group" id="daftar_karyawan_penerima">
                    <?php $pilihan_penerima_post = $_POST["id_karyawan_pengganti"] ?? []; if (!is_array($pilihan_penerima_post)) $pilihan_penerima_post = [$pilihan_penerima_post]; ?>
                    <?php $jumlah_penerima_tampil = max(1, min((int) ($_POST["jumlah_karyawan_pengganti"] ?? 1), max(1, min(10, count($data_karyawan))))); ?>
                    <?php for ($i = 0; $i < $jumlah_penerima_tampil; $i++): ?>
                        <label for="id_karyawan_pengganti_<?= $i; ?>" class="form-label">Karyawan <?= $i + 1; ?> <span class="required">*</span></label>
                        <select name="id_karyawan_pengganti[]" id="id_karyawan_pengganti_<?= $i; ?>" class="form-select karyawan-penerima-select" required style="margin-bottom:12px;">
                            <option value="">-- Pilih Karyawan --</option>
                            <?php foreach ($data_karyawan as $calon): ?>
                                <option value="<?= (int) $calon["id_user"]; ?>" <?= isset($pilihan_penerima_post[$i]) && (int) $pilihan_penerima_post[$i] === (int) $calon["id_user"] ? "selected" : ""; ?>><?= htmlspecialchars($calon["nama"]); ?><?= !empty($calon["jabatan"]) ? " - " . htmlspecialchars($calon["jabatan"]) : ""; ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endfor; ?>
                    <div class="form-help">Pilih karyawan aktif dari divisi <?= htmlspecialchars($nama_divisi); ?>. Karyawan yang dipilih harus berbeda.</div>
                </div>

                <!-- BUKTI PROGRES -->

                <div class="progress-box">

                    <div class="progress-title">
                        Bukti Progres Pekerjaan
                        <span class="optional">(Opsional)</span>
                    </div>

                    <div class="progress-description">
                        Lampirkan foto dan keterangan progres jika pekerjaan
                        sudah dikerjakan sebagian. Bukti akan tersimpan
                        sebagai riwayat pekerjaan.
                    </div>

                    <!-- FOTO -->

                    <div class="form-group">

                        <label for="foto_progres" class="form-label">
                            Foto Pekerjaan
                            <span class="optional">(Opsional)</span>
                        </label>

                        <div class="foto-grid" id="fotoGrid">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <div class="foto-slot empty" onclick="pilihSlotFoto(this)"><span class="foto-plus">+</span></div>
                            <?php endfor; ?>
                        </div>

                        <div class="camera-box hidden" id="cameraBox">
                            <div class="camera-placeholder" id="placeholder">Kamera belum dibuka</div>
                            <video id="video" autoplay playsinline class="hidden"></video>
                            <canvas id="canvas"></canvas>
                            <div class="camera-actions">
                                <button type="button" class="btn btn-camera" id="openCamera">Buka Kamera</button>
                                <button type="button" class="btn btn-camera hidden" id="takePhoto">Ambil Foto</button>
                                <button type="button" class="btn btn-retake" id="retake">Foto Ulang</button>
                                <button type="button" class="btn btn-retake" id="closeCamera">Tutup</button>
                            </div>
                        </div>

                        <input type="file" id="inputGaleri" accept="image/*" multiple class="hidden">

                        <div class="sumber-overlay hidden" id="sumberFoto">
                            <div class="sumber-box">
                                <div class="sumber-title">Tambah Foto Pekerjaan</div>
                                <button type="button" class="sumber-btn" id="pilihKamera">Ambil dari Kamera</button>
                                <button type="button" class="sumber-btn" id="pilihGaleri">Pilih dari Galeri</button>
                                <button type="button" class="sumber-btn sumber-batal" id="batalSumber">Batal</button>
                            </div>
                        </div>

                        <div class="form-help">Format JPG, JPEG, PNG, atau WEBP. Maksimal 5 foto, masing-masing 5 MB.</div>

                    </div>

                    <!-- KETERANGAN PROGRES -->

                    <div class="form-group" style="margin-bottom:0;">

                        <label for="keterangan_progres" class="form-label">
                            Keterangan Progres
                            <span class="optional">(Opsional)</span>
                        </label>

                        <textarea
                            name="keterangan_progres"
                            id="keterangan_progres"
                            class="form-textarea"
                            maxlength="2000"
                            placeholder="Contoh: Pemeriksaan awal dan penggantian komponen sudah dilakukan."
                        ><?= htmlspecialchars($_POST["keterangan_progres"] ?? ""); ?></textarea>

                        <div class="form-help">
                            Jelaskan pekerjaan yang sudah dilakukan
                            apabila ingin mencatat progres.
                        </div>

                    </div>

                </div>

                <!-- ALASAN ALIH TUGAS -->

                <div class="form-group">

                    <label for="deskripsi_pengalihan" class="form-label">
                        Alasan / Keterangan Pengalihan
                        <span class="required">*</span>
                    </label>

                    <textarea
                        name="deskripsi_pengalihan"
                        id="deskripsi_pengalihan"
                        class="form-textarea"
                        maxlength="2000"
                        placeholder="Tuliskan alasan pekerjaan dialihkan..."
                        required
                    ><?= htmlspecialchars($_POST["deskripsi_pengalihan"] ?? ""); ?></textarea>

                    <div class="form-help">Maksimal 2000 karakter.</div>

                </div>

                <!-- INFO -->

                <div class="info-box">
                    <strong>Informasi:</strong>
                    Tugas masuk ke Tugas Saya penerima; pengalih tercatat
                    <strong>Selesai</strong>. Bukti opsional, dengan tanggal,
                    waktu, dan identitas pengirim.
                </div>

                <!-- BUTTON -->

                <div class="action">

                    <a href="detail_tugas.php?id=<?= $id_pekerjaan; ?>" class="btn btn-cancel">
                        Batal
                    </a>

                    <button type="submit" class="btn btn-submit">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M7 7h10"></path>
                            <path d="M13 3l4 4-4 4"></path>
                            <path d="M17 17H7"></path>
                            <path d="M11 13l-4 4 4 4"></path>
                        </svg>

                        Alihkan Pekerjaan

                    </button>

                </div>

            </form>

        </div>

        <!-- FOOTER -->

        <div class="footer-note">
            &copy; <?= date("Y"); ?>
            SIMO METRP. All rights reserved.
        </div>

    </div>

</main>

<!-- MODAL KONFIRMASI -->

<div class="confirm-overlay" id="confirmAlih">
    <div class="confirm-box">
        <div class="confirm-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 7h10"></path>
                <path d="M13 3l4 4-4 4"></path>
                <path d="M17 17H7"></path>
                <path d="M11 13l-4 4 4 4"></path>
            </svg>
        </div>

        <div class="confirm-title">Alihkan Pekerjaan?</div>

        <div class="confirm-text">
            Pekerjaan <strong><?= htmlspecialchars($pekerjaan["nama_pekerjaan"] ?? "-"); ?></strong>
            akan dialihkan dan statusmu pada pekerjaan ini menjadi <strong>Selesai</strong>.
            Tindakan ini tidak dapat dibatalkan.
        </div>

        <div class="confirm-actions">
            <button type="button" class="btn btn-cancel" id="confirmBatal">Batal</button>
            <button type="button" class="btn btn-submit" id="confirmYa">Ya, Alihkan</button>
        </div>
    </div>
</div>

<script>
const jumlahKaryawanPengganti=document.getElementById("jumlah_karyawan_pengganti");
const daftarPenerima=document.getElementById("daftar_karyawan_penerima");
const dataKaryawanPenerima=<?= json_encode(array_map(function ($item) { return ["id" => (int) $item["id_user"], "nama" => $item["nama"], "jabatan" => $item["jabatan"] ?? ""]; }, $data_karyawan), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const pilihanPenerimaAwal=<?= json_encode(is_array($_POST["id_karyawan_pengganti"] ?? null) ? array_values($_POST["id_karyawan_pengganti"]) : (isset($_POST["id_karyawan_pengganti"]) ? [$_POST["id_karyawan_pengganti"]] : []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
function buatPilihanPenerima(pilihanLama=[]){
    const jumlah=parseInt(jumlahKaryawanPengganti.value,10)||1;
    daftarPenerima.innerHTML="";
    for(let i=0;i<jumlah;i++){
        const label=document.createElement("label");
        label.className="form-label";
        label.htmlFor="id_karyawan_pengganti_"+i;
        label.innerHTML="Karyawan "+(i+1)+' <span class="required">*</span>';
        const select=document.createElement("select");
        select.name="id_karyawan_pengganti[]";
        select.id="id_karyawan_pengganti_"+i;
        select.className="form-select karyawan-penerima-select";
        select.required=true;
        select.style.marginBottom="12px";
        const awal=document.createElement("option");
        awal.value="";
        awal.textContent="-- Pilih Karyawan --";
        select.appendChild(awal);
        dataKaryawanPenerima.forEach(function(karyawan){
            const option=document.createElement("option");
            option.value=String(karyawan.id);
            option.textContent=karyawan.nama+(karyawan.jabatan?" - "+karyawan.jabatan:"");
            select.appendChild(option);
        });
        if(pilihanLama[i])select.value=String(pilihanLama[i]);
        select.addEventListener("change",validasiPenerima);
        daftarPenerima.appendChild(label);
        daftarPenerima.appendChild(select);
    }
    const bantuan=document.createElement("div");
    bantuan.className="form-help";
    bantuan.textContent="Pilih karyawan aktif dari divisi <?= htmlspecialchars($nama_divisi, ENT_QUOTES); ?>. Karyawan yang dipilih harus berbeda.";
    daftarPenerima.appendChild(bantuan);
    validasiPenerima();
}
function validasiPenerima(){
    const selects=Array.from(document.querySelectorAll(".karyawan-penerima-select"));
    const terpilih=selects.map(function(select){return select.value;}).filter(Boolean);
    selects.forEach(function(select){
        Array.from(select.options).forEach(function(option){
            option.disabled=option.value!==""&&terpilih.includes(option.value)&&select.value!==option.value;
        });
    });
}
buatPilihanPenerima(pilihanPenerimaAwal);
jumlahKaryawanPengganti.addEventListener("change",function(){
    const pilihan=Array.from(document.querySelectorAll(".karyawan-penerima-select")).map(function(select){return select.value;});
    buatPilihanPenerima(pilihan.slice(0,parseInt(this.value,10)||1));
});
document.querySelector("form").addEventListener("submit",function(event){
    const selects=Array.from(document.querySelectorAll(".karyawan-penerima-select"));
    const pilihan=selects.map(function(select){return select.value;});
    if(pilihan.some(function(id){return !id;})){
        event.preventDefault();
        alert("Silakan pilih semua karyawan penerima.");
        return;
    }
    if(new Set(pilihan).size!==pilihan.length){
        event.preventDefault();
        alert("Karyawan tidak boleh dipilih lebih dari satu kali.");
        return;
    }
});
const video=document.getElementById("video");

const canvas=document.getElementById("canvas");

const openCamera=document.getElementById("openCamera");

const takePhoto=document.getElementById("takePhoto");

const retake=document.getElementById("retake");

const closeCamera=document.getElementById("closeCamera");

const placeholder=document.getElementById("placeholder");

const cameraBox=document.getElementById("cameraBox");

const fotoGrid=document.getElementById("fotoGrid");

const sumberFoto=document.getElementById("sumberFoto");

const inputGaleri=document.getElementById("inputGaleri");

let stream=null;

let slotAktif=null;

function jumlahFoto(){return fotoGrid.querySelectorAll(".foto-slot:not(.empty)").length;}

function pilihSlotFoto(slot){if(jumlahFoto()>=5)return;slotAktif=slot;sumberFoto.classList.remove("hidden");}

document.getElementById("batalSumber").addEventListener("click",function(){sumberFoto.classList.add("hidden");slotAktif=null;});

sumberFoto.addEventListener("click",function(e){if(e.target===sumberFoto){sumberFoto.classList.add("hidden");slotAktif=null;}});

document.getElementById("pilihKamera").addEventListener("click",function(){sumberFoto.classList.add("hidden");tampilkanKamera();});

document.getElementById("pilihGaleri").addEventListener("click",function(){sumberFoto.classList.add("hidden");inputGaleri.value="";inputGaleri.click();});

function isiSlotFoto(slot,imageData){slot.classList.remove("empty");slot.onclick=null;slot.innerHTML='<img src="'+imageData+'" alt=""><button type="button" class="foto-remove" onclick="hapusFotoBaru(this)">×</button>';const input=document.createElement("input");input.type="hidden";input.name="foto_progres_data[]";input.value=imageData;slot.appendChild(input);}

function prosesFileGaleri(file){return new Promise(function(resolve,reject){const reader=new FileReader();reader.onerror=reject;reader.onload=function(){const img=new Image();img.onerror=reject;img.onload=function(){const maxSisi=1600;let w=img.width;let h=img.height;if(Math.max(w,h)>maxSisi){const skala=maxSisi/Math.max(w,h);w=Math.round(w*skala);h=Math.round(h*skala);}canvas.width=w;canvas.height=h;canvas.getContext("2d").drawImage(img,0,0,w,h);resolve(canvas.toDataURL("image/jpeg",0.85));};img.src=reader.result;};reader.readAsDataURL(file);});}

inputGaleri.addEventListener("change",async function(){const files=Array.from(inputGaleri.files||[]);if(!files.length){slotAktif=null;return;}const sisa=5-jumlahFoto();if(files.length>sisa)alert("Maksimal 5 foto. Hanya "+sisa+" foto pertama yang akan ditambahkan.");for(const file of files.slice(0,sisa)){if(!file.type.startsWith("image/"))continue;const slot=fotoGrid.querySelector(".foto-slot.empty");if(!slot)break;try{const imageData=await prosesFileGaleri(file);isiSlotFoto(slot,imageData);}catch(err){alert("Foto "+file.name+" gagal diproses.");}}slotAktif=null;inputGaleri.value="";});

function tampilkanKamera(){cameraBox.classList.remove("hidden");placeholder.classList.remove("hidden");video.classList.add("hidden");openCamera.classList.remove("hidden");takePhoto.classList.add("hidden");retake.style.display="none";closeCamera.style.display="inline-flex";cameraBox.scrollIntoView({behavior:"smooth",block:"center"});}

async function bukaKamera(){try{if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){alert("Browser tidak mendukung akses kamera.");return;}stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}},audio:false});video.srcObject=stream;placeholder.classList.add("hidden");video.classList.remove("hidden");openCamera.classList.add("hidden");takePhoto.classList.remove("hidden");retake.style.display="inline-flex";}catch(error){alert("Kamera tidak dapat dibuka. Silakan izinkan akses kamera pada browser.");}}

openCamera.addEventListener("click",bukaKamera);

takePhoto.addEventListener("click",function(){if(!slotAktif||!video.videoWidth||!video.videoHeight){alert("Kamera belum siap.");return;}canvas.width=video.videoWidth;canvas.height=video.videoHeight;canvas.getContext("2d").drawImage(video,0,0,canvas.width,canvas.height);isiSlotFoto(slotAktif,canvas.toDataURL("image/jpeg",0.85));matikanKamera();cameraBox.classList.add("hidden");slotAktif=null;});

retake.addEventListener("click",async function(){matikanKamera();await bukaKamera();});

closeCamera.addEventListener("click",function(){matikanKamera();cameraBox.classList.add("hidden");slotAktif=null;});

function hapusFotoBaru(button){button.closest(".foto-slot").remove();tambahSlotKosong();}

function tambahSlotKosong(){if(fotoGrid.querySelectorAll(".foto-slot").length>=5)return;const slot=document.createElement("div");slot.className="foto-slot empty";slot.onclick=function(){pilihSlotFoto(this);};slot.innerHTML='<span class="foto-plus">+</span>';fotoGrid.appendChild(slot);}

function matikanKamera(){if(stream){stream.getTracks().forEach(function(track){track.stop();});stream=null;}if(video)video.srcObject=null;}

document.querySelector("form").addEventListener("submit",function(){matikanKamera();});

window.addEventListener("beforeunload",function(){matikanKamera();});

/* MODAL KONFIRMASI ALIH PEKERJAAN */

const formAlih=document.getElementById("formAlih");

const confirmAlih=document.getElementById("confirmAlih");

formAlih.addEventListener("submit",function(event){
    if(event.defaultPrevented)return;
    event.preventDefault();
    confirmAlih.classList.add("active");
});

function tutupConfirmAlih(){
    confirmAlih.classList.remove("active");
}

document.getElementById("confirmBatal").addEventListener("click",tutupConfirmAlih);

confirmAlih.addEventListener("click",function(e){
    if(e.target===confirmAlih)tutupConfirmAlih();
});

document.addEventListener("keydown",function(e){
    if(e.key==="Escape")tutupConfirmAlih();
});

document.getElementById("confirmYa").addEventListener("click",function(){
    this.disabled=true;
    this.textContent="Memproses...";
    matikanKamera();
    formAlih.submit();
});

</script>

</body>
</html>
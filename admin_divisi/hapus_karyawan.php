<?php

ob_start();
include "../config/session.php";

header("Content-Type: application/json; charset=UTF-8");

function response_json($success, $message, $extra = [])
{
    ob_clean();

    echo json_encode(
        array_merge(
            [
                "success" => $success,
                "message" => $message
            ],
            $extra
        )
    );

    exit;
}

if (
    !isset($_SESSION["id_user"]) ||
    $_SESSION["role"] != "Admin"
) {
    response_json(
        false,
        "Anda tidak memiliki akses."
    );
}

include "../config/koneksi.php";

if (!$koneksi) {
    response_json(
        false,
        "Koneksi database gagal."
    );
}

$aksi = $_POST["aksi"] ?? "";
$id_user = intval($_POST["id_user"] ?? 0);

if ($id_user <= 0) {
    response_json(
        false,
        "ID karyawan tidak valid."
    );
}

if (
    $aksi !== "cek" &&
    $aksi !== "nonaktifkan" &&
    $aksi !== "hapus"
) {
    response_json(
        false,
        "Aksi tidak valid."
    );
}


/* =========================================================
   DATA ADMIN
========================================================= */

$id_admin = intval($_SESSION["id_user"]);

$query_admin = mysqli_query(
    $koneksi,
    "SELECT id_divisi
     FROM users
     WHERE id_user='$id_admin'
     AND role='Admin'
     LIMIT 1"
);

$admin = mysqli_fetch_assoc($query_admin);

if (!$admin) {
    response_json(
        false,
        "Data admin tidak ditemukan."
    );
}

$id_divisi = intval($admin["id_divisi"]);


/* =========================================================
   CEK KARYAWAN
========================================================= */

$query_karyawan = mysqli_query(
    $koneksi,
    "SELECT id_user, nama, status
     FROM users
     WHERE id_user='$id_user'
     AND id_divisi='$id_divisi'
     AND role='Karyawan'
     LIMIT 1"
);

$karyawan = mysqli_fetch_assoc($query_karyawan);

if (!$karyawan) {
    response_json(
        false,
        "Karyawan tidak ditemukan."
    );
}


/* =========================================================
   CEK RIWAYAT PEKERJAAN
========================================================= */

$query_riwayat = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS total
     FROM riwayat_pekerjaan rp
     INNER JOIN pekerjaan p
        ON rp.id_pekerjaan = p.id_pekerjaan
     WHERE p.id_karyawan='$id_user'
     AND p.id_divisi='$id_divisi'"
);

if (!$query_riwayat) {
    response_json(
        false,
        "Gagal memeriksa riwayat pekerjaan: " .
        mysqli_error($koneksi)
    );
}

$data_riwayat = mysqli_fetch_assoc($query_riwayat);
$jumlah_riwayat = intval(
    $data_riwayat["total"] ?? 0
);

$ada_riwayat = $jumlah_riwayat > 0;


/* =========================================================
   AKSI CEK
========================================================= */

if ($aksi === "cek") {

    response_json(
        true,
        "Data berhasil diperiksa.",
        [
            "ada_riwayat" => $ada_riwayat,
            "jumlah_riwayat" => $jumlah_riwayat
        ]
    );
}


/* =========================================================
   AKSI NONAKTIFKAN
========================================================= */

if ($aksi === "nonaktifkan") {

    $update = mysqli_query(
        $koneksi,
        "UPDATE users
         SET status='Tidak Aktif'
         WHERE id_user='$id_user'
         AND id_divisi='$id_divisi'
         AND role='Karyawan'"
    );

    if (!$update) {

        response_json(
            false,
            "Gagal menonaktifkan karyawan: " .
            mysqli_error($koneksi)
        );
    }

    response_json(
        true,
        "Karyawan berhasil dinonaktifkan."
    );
}


/* =========================================================
   AKSI HAPUS PERMANEN
========================================================= */

mysqli_begin_transaction($koneksi);

try {

    $foto_list = [];
    $pekerjaan_list = [];


    /* -----------------------------------------------------
       AMBIL FOTO RIWAYAT
    ----------------------------------------------------- */

    $query_foto = mysqli_query(
        $koneksi,
        "SELECT rp.foto
         FROM riwayat_pekerjaan rp
         INNER JOIN pekerjaan p
            ON rp.id_pekerjaan = p.id_pekerjaan
         WHERE p.id_karyawan='$id_user'
         AND p.id_divisi='$id_divisi'
         AND rp.foto IS NOT NULL
         AND rp.foto != ''"
    );

    if (!$query_foto) {
        throw new Exception(
            "Gagal mengambil foto riwayat."
        );
    }

    while ($foto = mysqli_fetch_assoc($query_foto)) {

        if (!empty($foto["foto"])) {
            $foto_list[] = $foto["foto"];
        }
    }


    /* -----------------------------------------------------
       AMBIL ID PEKERJAAN
    ----------------------------------------------------- */

    $query_pekerjaan = mysqli_query(
        $koneksi,
        "SELECT id_pekerjaan
         FROM pekerjaan
         WHERE id_karyawan='$id_user'
         AND id_divisi='$id_divisi'"
    );

    if (!$query_pekerjaan) {
        throw new Exception(
            "Gagal mengambil pekerjaan."
        );
    }

    while (
        $pekerjaan = mysqli_fetch_assoc(
            $query_pekerjaan
        )
    ) {

        $pekerjaan_list[] =
            intval($pekerjaan["id_pekerjaan"]);
    }


    /* -----------------------------------------------------
       HAPUS RIWAYAT
    ----------------------------------------------------- */

    $hapus_riwayat = mysqli_query(
        $koneksi,
        "DELETE rp
         FROM riwayat_pekerjaan rp
         INNER JOIN pekerjaan p
            ON rp.id_pekerjaan = p.id_pekerjaan
         WHERE p.id_karyawan='$id_user'
         AND p.id_divisi='$id_divisi'"
    );

    if (!$hapus_riwayat) {
        throw new Exception(
            "Gagal menghapus riwayat pekerjaan: " .
            mysqli_error($koneksi)
        );
    }


    /* -----------------------------------------------------
       HAPUS BUKTI PEKERJAAN
    ----------------------------------------------------- */

    $hapus_bukti = mysqli_query(
        $koneksi,
        "DELETE bp
         FROM bukti_pekerjaan bp
         INNER JOIN pekerjaan p
            ON bp.id_pekerjaan = p.id_pekerjaan
         WHERE p.id_karyawan='$id_user'
         AND p.id_divisi='$id_divisi'"
    );

    if (!$hapus_bukti) {
        throw new Exception(
            "Gagal menghapus bukti pekerjaan: " .
            mysqli_error($koneksi)
        );
    }


    /* -----------------------------------------------------
       HAPUS PEKERJAAN
    ----------------------------------------------------- */

    $hapus_pekerjaan = mysqli_query(
        $koneksi,
        "DELETE FROM pekerjaan
         WHERE id_karyawan='$id_user'
         AND id_divisi='$id_divisi'"
    );

    if (!$hapus_pekerjaan) {
        throw new Exception(
            "Gagal menghapus pekerjaan: " .
            mysqli_error($koneksi)
        );
    }


    /* -----------------------------------------------------
       HAPUS NOTIFIKASI KARYAWAN
    ----------------------------------------------------- */

    $hapus_notifikasi = mysqli_query(
        $koneksi,
        "DELETE FROM notifikasi
         WHERE id_user='$id_user'"
    );

    if (!$hapus_notifikasi) {
        throw new Exception(
            "Gagal menghapus notifikasi: " .
            mysqli_error($koneksi)
        );
    }


    /* -----------------------------------------------------
       HAPUS USER
    ----------------------------------------------------- */

    $hapus_user = mysqli_query(
        $koneksi,
        "DELETE FROM users
         WHERE id_user='$id_user'
         AND id_divisi='$id_divisi'
         AND role='Karyawan'"
    );

    if (!$hapus_user) {
        throw new Exception(
            "Gagal menghapus karyawan: " .
            mysqli_error($koneksi)
        );
    }


    mysqli_commit($koneksi);


    /* -----------------------------------------------------
       HAPUS FILE FOTO FISIK
    ----------------------------------------------------- */

    foreach ($foto_list as $nama_foto) {

        $nama_foto = basename($nama_foto);

        $file_foto =
            dirname(__DIR__) .
            "/uploads/bukti/" .
            $nama_foto;

        if (file_exists($file_foto)) {
            @unlink($file_foto);
        }
    }


    response_json(
        true,
        "Karyawan berhasil dihapus secara permanen."
    );

}
catch (Throwable $e) {

    mysqli_rollback($koneksi);

    response_json(
        false,
        $e->getMessage()
    );
}
?>

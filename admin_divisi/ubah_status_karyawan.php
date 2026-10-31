<?php

/* =========================================================
   PASTIKAN OUTPUT HANYA JSON
========================================================= */

ob_start();

include "../config/session.php";

header("Content-Type: application/json; charset=UTF-8");


/* =========================================================
   FUNGSI RESPONSE
========================================================= */

function response_json($success, $message, $status = null)
{
    ob_clean();

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "status"  => $status
    ]);

    exit;
}


/* =========================================================
   CEK LOGIN
========================================================= */

if (
    !isset($_SESSION["id_user"]) ||
    $_SESSION["role"] != "Admin"
) {

    response_json(
        false,
        "Anda tidak memiliki akses."
    );
}


/* =========================================================
   KONEKSI DATABASE
========================================================= */

include "../config/koneksi.php";


/* =========================================================
   CEK KONEKSI
========================================================= */

if (!$koneksi) {

    response_json(
        false,
        "Koneksi database gagal."
    );
}


/* =========================================================
   CEK DATA POST
========================================================= */

if (
    !isset($_POST["id_user"]) ||
    !isset($_POST["status"])
) {

    response_json(
        false,
        "Data tidak lengkap."
    );
}


/* =========================================================
   AMBIL DATA
========================================================= */

$id_user = mysqli_real_escape_string(
    $koneksi,
    $_POST["id_user"]
);

$status = mysqli_real_escape_string(
    $koneksi,
    $_POST["status"]
);


/* =========================================================
   VALIDASI STATUS
========================================================= */

if (
    $status !== "Aktif" &&
    $status !== "Tidak Aktif"
) {

    response_json(
        false,
        "Status tidak valid."
    );
}


/* =========================================================
   DATA ADMIN
========================================================= */

$id_admin = $_SESSION["id_user"];


$query_admin = mysqli_query(
    $koneksi,
    "SELECT id_divisi
     FROM users
     WHERE id_user='$id_admin'
     AND role='Admin'"
);


if (!$query_admin) {

    response_json(
        false,
        "Gagal mengambil data admin: " . mysqli_error($koneksi)
    );
}


$admin = mysqli_fetch_assoc($query_admin);


if (!$admin) {

    response_json(
        false,
        "Data admin tidak ditemukan."
    );
}


$id_divisi = $admin["id_divisi"];


/* =========================================================
   CEK KARYAWAN
========================================================= */

$query_cek = mysqli_query(
    $koneksi,
    "SELECT id_user
     FROM users
     WHERE id_user='$id_user'
     AND id_divisi='$id_divisi'
     AND role='Karyawan'"
);


if (!$query_cek) {

    response_json(
        false,
        "Gagal mengecek data karyawan: " .
        mysqli_error($koneksi)
    );
}


if (mysqli_num_rows($query_cek) == 0) {

    response_json(
        false,
        "Karyawan tidak ditemukan atau bukan bagian dari divisi Anda."
    );
}


/* =========================================================
   UPDATE STATUS
========================================================= */

$query_update = mysqli_query(
    $koneksi,
    "UPDATE users
     SET status='$status'
     WHERE id_user='$id_user'
     AND id_divisi='$id_divisi'
     AND role='Karyawan'"
);


/* =========================================================
   HASIL UPDATE
========================================================= */

if (!$query_update) {

    response_json(
        false,
        "Gagal mengubah status: " .
        mysqli_error($koneksi)
    );
}


/* =========================================================
   BERHASIL
========================================================= */

response_json(
    true,
    "Status karyawan berhasil diubah menjadi $status.",
    $status
);

?>
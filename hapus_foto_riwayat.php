<?php

include "config/koneksi.php";

/* =========================================
   HAPUS FOTO RIWAYAT YANG SUDAH KADALUARSA
========================================= */

$query = mysqli_query(
    $koneksi,
    "
    SELECT
        id_riwayat,
        foto
    FROM riwayat_pekerjaan
    WHERE foto IS NOT NULL
    AND foto != ''
    AND tanggal_hapus_foto IS NOT NULL
    AND tanggal_hapus_foto <= NOW()
    "
);

if (!$query) {
    die("Gagal mengambil data foto.");
}

while ($row = mysqli_fetch_assoc($query)) {

    $foto = $row["foto"];

    /* Lokasi file foto */
    $file_foto = __DIR__ . "/uploads/bukti/" . $foto;

    /* Hapus file fisik */
    if (file_exists($file_foto)) {
        unlink($file_foto);
    }

    /* Hapus referensi foto dari database
       RIWAYAT PEKERJAAN TETAP ADA */
    mysqli_query(
        $koneksi,
        "
        UPDATE riwayat_pekerjaan
        SET
            foto = NULL,
            tanggal_hapus_foto = NULL
        WHERE id_riwayat = '" . intval($row["id_riwayat"]) . "'
        "
    );
}

echo "Proses penghapusan foto selesai.";

?>
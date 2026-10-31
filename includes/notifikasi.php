<?php

/* =========================================================
   KOMPONEN TOMBOL NOTIFIKASI SIMO
========================================================= */

if (!isset($_SESSION["id_user"])) {
    return;
}

$id_user_notifikasi = (int) $_SESSION["id_user"];


/* =========================================================
   JUMLAH NOTIFIKASI BELUM DIBACA
========================================================= */

$jumlah_notifikasi = 0;

$query_jumlah_notifikasi = mysqli_query(
    $koneksi,
    "
    SELECT COUNT(*) AS total
    FROM notifikasi
    WHERE id_user='$id_user_notifikasi'
    AND status_baca='Belum Dibaca'
    "
);

if ($query_jumlah_notifikasi) {

    $data_jumlah_notifikasi =
        mysqli_fetch_assoc($query_jumlah_notifikasi);

    $jumlah_notifikasi =
        (int) ($data_jumlah_notifikasi["total"] ?? 0);
}


/* =========================================================
   LINK HALAMAN NOTIFIKASI
========================================================= */

$folder_sekarang = basename(
    dirname($_SERVER["PHP_SELF"])
);

if (
    $folder_sekarang == "admin_divisi" ||
    $folder_sekarang == "karyawan" ||
    $folder_sekarang == "super_admin"
) {

    $link_notifikasi = "../notifikasi.php";

} else {

    $link_notifikasi = "notifikasi.php";

}

?>


<!-- =========================================================
     TOMBOL NOTIFIKASI
========================================================= -->

<a
    href="<?= $link_notifikasi; ?>"
    class="notification-wrapper"
    title="Notifikasi"
    aria-label="Notifikasi"
>

    <div class="notification">

        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >

            <path
                d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"
            ></path>

            <path
                d="M13.7 21a2 2 0 0 1-3.4 0"
            ></path>

        </svg>


        <?php if ($jumlah_notifikasi > 0): ?>

            <span class="notification-badge">

                <?= $jumlah_notifikasi > 99
                    ? "99+"
                    : $jumlah_notifikasi;
                ?>

            </span>

        <?php endif; ?>

    </div>

</a>
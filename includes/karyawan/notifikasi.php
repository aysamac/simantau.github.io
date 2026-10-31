<?php

if (session_status() === PHP_SESSION_NONE) {
    include "../config/session.php";
}

if (!isset($_SESSION["id_user"])) {
    return;
}

$id_user = (int) $_SESSION["id_user"];

$query_notifikasi = mysqli_query(
    $koneksi,
    "
    SELECT *
    FROM notifikasi
    WHERE id_user='$id_user'
    ORDER BY tanggal DESC
    "
);

?>

<div class="notification-page">

    <h2>Notifikasi</h2>

    <?php if ($query_notifikasi && mysqli_num_rows($query_notifikasi) > 0): ?>

        <?php while ($notif = mysqli_fetch_assoc($query_notifikasi)): ?>

            <div class="notification-item">

                <div class="notification-title">
                    <?= htmlspecialchars($notif["pesan"]); ?>
                </div>

                <div class="notification-date">
                    <?= date(
                        "d M Y, H:i",
                        strtotime($notif["tanggal"])
                    ); ?>
                </div>

            </div>

        <?php endwhile; ?>

    <?php else: ?>

        <div class="notification-empty">
            Belum ada notifikasi.
        </div>

    <?php endif; ?>

</div>
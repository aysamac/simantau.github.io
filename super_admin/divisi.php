<?php

include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "Super Admin"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$file_halaman = "divisi.php";

/* Token pengaman untuk aksi hapus */
if (empty($_SESSION["csrf_divisi"])) {
    $_SESSION["csrf_divisi"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["csrf_divisi"];

function e($t)
{
    return htmlspecialchars((string) $t, ENT_QUOTES, "UTF-8");
}

/* Hitung jumlah baris dengan satu parameter id (aman jika tabel/kolom tidak ada) */
function hitung_baris($koneksi, $sql, $id)
{
    try {
        $st = $koneksi->prepare($sql);
        if (!$st) {
            return 0;
        }
        $st->bind_param("i", $id);
        $st->execute();
        $baris = $st->get_result()->fetch_row();
        return (int) ($baris[0] ?? 0);
    } catch (Throwable $e) {
        error_log("Hitung relasi divisi gagal: " . $e->getMessage());
        return 0;
    }
}

/* =========================================================
   HAPUS DIVISI (hanya jika divisi benar-benar kosong)
   Pengguna berstatus 'Dihapus' (soft delete) tidak dihitung.
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["aksi"] ?? "") === "hapus") {

    $id_hapus = (int) ($_POST["id_divisi"] ?? 0);
    $token = (string) ($_POST["csrf"] ?? "");

    if (!hash_equals($csrf, $token)) {
        $_SESSION["flash_divisi_error"] = "Permintaan tidak valid. Muat ulang halaman lalu coba lagi.";
    } else {

        $nama_hapus = null;

        try {
            $st = $koneksi->prepare("SELECT nama_divisi FROM divisi WHERE id_divisi = ? LIMIT 1");
            $st->bind_param("i", $id_hapus);
            $st->execute();
            $r = $st->get_result()->fetch_assoc();
            $nama_hapus = $r["nama_divisi"] ?? null;
        } catch (Throwable $ex) {
            error_log("Cek divisi gagal: " . $ex->getMessage());
        }

        if ($nama_hapus === null) {
            $_SESSION["flash_divisi_error"] = "Divisi tidak ditemukan.";
        } else {

            /* Abaikan pengguna yang sudah dihapus (status 'Dihapus') */
            $n_user = hitung_baris(
                $koneksi,
                "SELECT COUNT(*) FROM users WHERE id_divisi = ? AND COALESCE(TRIM(status), '') <> 'Dihapus'",
                $id_hapus
            );
            $n_pekerjaan = hitung_baris($koneksi, "SELECT COUNT(*) FROM pekerjaan WHERE id_divisi = ?", $id_hapus);
            $n_order = hitung_baris($koneksi, "SELECT COUNT(*) FROM order_perbaikan WHERE id_divisi = ?", $id_hapus)
                     + hitung_baris($koneksi, "SELECT COUNT(*) FROM order_perbaikan WHERE id_divisi_asal = ?", $id_hapus);

            $alasan = [];
            if ($n_user > 0) { $alasan[] = $n_user . " pengguna"; }
            if ($n_pekerjaan > 0) { $alasan[] = $n_pekerjaan . " pekerjaan"; }
            if ($n_order > 0) { $alasan[] = $n_order . " order perbaikan"; }

            if ($alasan) {
                $_SESSION["flash_divisi_error"] = "Divisi \"" . $nama_hapus . "\" tidak bisa dihapus karena masih memiliki "
                    . implode(", ", $alasan) . ". Pindahkan atau hapus data tersebut terlebih dahulu.";
            } else {
                try {
                    $koneksi->begin_transaction();

                    /* Lepaskan akun 'Dihapus' dari divisi ini agar tidak
                       terhalang foreign key. Butuh kolom users.id_divisi
                       yang boleh NULL; jika tidak, transaksi dibatalkan. */
                    $st = $koneksi->prepare(
                        "UPDATE users SET id_divisi = NULL WHERE id_divisi = ? AND TRIM(status) = 'Dihapus'"
                    );
                    $st->bind_param("i", $id_hapus);
                    if (!$st->execute()) {
                        throw new Exception($st->error);
                    }
                    $st->close();

                    $st = $koneksi->prepare("DELETE FROM divisi WHERE id_divisi = ?");
                    $st->bind_param("i", $id_hapus);
                    if (!$st->execute()) {
                        throw new Exception($st->error);
                    }

                    if ($st->affected_rows > 0) {
                        $koneksi->commit();
                        $_SESSION["flash_divisi_ok"] = "Divisi \"" . $nama_hapus . "\" berhasil dihapus.";
                    } else {
                        $koneksi->rollback();
                        $_SESSION["flash_divisi_error"] = "Divisi gagal dihapus.";
                    }
                } catch (Throwable $ex) {
                    try { $koneksi->rollback(); } catch (Throwable $ignore) {}
                    error_log("Hapus divisi gagal: " . $ex->getMessage());
                    $_SESSION["flash_divisi_error"] = "Divisi gagal dihapus karena masih terhubung dengan data lain.";
                }
            }
        }
    }

    header("Location: " . $file_halaman);
    exit;
}

$pesan_ok = $_SESSION["flash_divisi_ok"] ?? "";
$pesan_err = $_SESSION["flash_divisi_error"] ?? "";
unset($_SESSION["flash_divisi_ok"], $_SESSION["flash_divisi_error"]);

/* =========================================================
   DATA DIVISI
   (pengguna berstatus 'Dihapus' tidak dihitung)
========================================================= */
$query = mysqli_query(
    $koneksi,
    "
    SELECT
        d.id_divisi,
        d.nama_divisi,
        COUNT(DISTINCT u.id_user) AS jumlah_karyawan,
        (SELECT COUNT(*) FROM users ux
            WHERE ux.id_divisi = d.id_divisi
            AND COALESCE(TRIM(ux.status), '') <> 'Dihapus') AS total_user,
        (SELECT COUNT(*) FROM pekerjaan px WHERE px.id_divisi = d.id_divisi) AS total_pekerjaan
    FROM divisi d
    LEFT JOIN users u
        ON d.id_divisi = u.id_divisi
        AND u.role = 'Karyawan'
        AND COALESCE(TRIM(u.status), '') <> 'Dihapus'
    GROUP BY
        d.id_divisi,
        d.nama_divisi
    ORDER BY d.id_divisi ASC
    "
);

if (!$query) {
    die(
        "Query divisi gagal: " .
        mysqli_error($koneksi)
    );
}

/* Jumlah order perbaikan per divisi (diabaikan jika tabelnya tidak ada) */
$total_order = [];
try {
    foreach (["id_divisi", "id_divisi_asal"] as $kolom_order) {
        $qo = mysqli_query($koneksi, "SELECT `$kolom_order` AS id_d, COUNT(*) AS jml FROM order_perbaikan WHERE `$kolom_order` IS NOT NULL GROUP BY `$kolom_order`");
        if ($qo) {
            while ($ro = mysqli_fetch_assoc($qo)) {
                $kunci = (int) $ro["id_d"];
                $total_order[$kunci] = ($total_order[$kunci] ?? 0) + (int) $ro["jml"];
            }
        }
    }
} catch (Throwable $ex) {
    error_log("Hitung order per divisi gagal: " . $ex->getMessage());
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Divisi - SIMO</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #172033;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .main {
            margin-left: 240px;
            width: calc(100% - 240px);
            min-height: 100vh;
            min-width: 0;
            background: #ffffff;
        }

        .content {
            padding: 28px 34px 34px;
        }

        /* HEADER */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-title h1 {
            font-size: 24px;
            line-height: 1.2;
            font-weight: 700;
            color: #172033;
            margin-bottom: 5px;
        }

        .page-title p {
            font-size: 13px;
            line-height: 1.4;
            color: #7a8596;
        }

        .btn-tambah {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 38px;
            padding: 0 15px;
            border: none;
            border-radius: 8px;
            background: #b51f2a;
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s ease, transform .18s ease;
        }

        .btn-tambah:hover {
            background: #941923;
            transform: translateY(-1px);
        }

        /* PESAN */
        .alert {
            padding: 12px 15px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.55;
        }

        .alert.success {
            background: #dff5e8;
            color: #29965a;
        }

        .alert.error {
            background: #fde2e2;
            color: #d94b4b;
        }

        /* GRID */
        .divisi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        /* CARD */
        .divisi-card {
            background: #ffffff;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            padding: 16px 18px 12px;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .04);
            transition: transform .28s ease, box-shadow .28s ease;
        }

        .divisi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 18px rgba(16, 24, 40, .08);
        }

        .divisi-header {
            margin-bottom: 11px;
        }

        .divisi-name {
            font-size: 16px;
            line-height: 1.3;
            font-weight: 700;
            color: #172033;
        }

        .divider {
            width: 100%;
            height: 1px;
            background: #edf0f3;
            margin-bottom: 12px;
        }

        .divisi-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 43px;
            background: #f8f9fb;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .info-item {
            padding-right: 15px;
        }

        .info-item + .info-item {
            padding-left: 10px;
            border-left: 1px solid #e8ebef;
        }

        .info-label {
            font-size: 11px;
            color: #7a8596;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 13px;
            font-weight: 600;
            color: #172033;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #344054;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #29a35f;
        }

        .card-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #edf0f3;
        }

        .card-note {
            font-size: 11px;
            color: #9aa3b1;
            line-height: 1.4;
        }

        .card-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .btn-edit,
        .btn-hapus {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 30px;
            padding: 0 12px;
            border: 1px solid #d9dde2;
            border-radius: 7px;
            background: #fff8f8;
            color: #b31824;
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s ease, border-color .18s ease, color .18s ease;
        }

        .btn-edit:hover,
        .btn-hapus:hover {
            background: #b51f2a;
            border-color: #b51f2a;
            color: #ffffff;
        }

        .btn-hapus:disabled,
        .btn-hapus:disabled:hover {
            background: #eef0f3;
            border-color: #e1e4e8;
            color: #9aa3af;
            cursor: not-allowed;
        }

        .empty-data {
            grid-column: 1 / -1;
            border: 1px solid #d9dde2;
            border-radius: 14px;
            padding: 35px 20px;
            text-align: center;
            color: #7a8596;
            font-size: 13px;
        }

        .footer-note {
            text-align: center;
            padding: 24px 0 4px;
            font-size: 11.5px;
            color: #9aa3b1;
        }

        /* MODAL HAPUS */
        .delete-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(28, 34, 44, .42);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 10000;
            backdrop-filter: blur(2px);
        }

        .delete-modal-overlay.show {
            display: flex;
        }

        .delete-modal-box {
            width: 380px;
            max-width: 100%;
            background: #ffffff;
            border-radius: 16px;
            padding: 28px 26px 24px;
            text-align: center;
            box-shadow: 0 18px 45px rgba(20, 28, 40, .18);
        }

        .delete-modal-icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 15px;
            border-radius: 50%;
            background: #f7e3e5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #b51f2a;
        }

        .delete-modal-icon svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
        }

        .delete-modal-box h3 {
            margin-bottom: 7px;
            font-size: 17px;
            color: #1c2636;
        }

        .delete-modal-box p {
            margin: 0 auto 22px;
            max-width: 300px;
            font-size: 12px;
            line-height: 1.6;
            color: #7e8897;
        }

        .delete-modal-box p strong {
            color: #1c2636;
        }

        .delete-modal-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .delete-modal-btn {
            min-width: 104px;
            padding: 10px 16px;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }

        .delete-modal-cancel {
            border: 1px solid #dde3ea;
            background: #f7f8fa;
            color: #697587;
        }

        .delete-modal-cancel:hover {
            background: #eef1f4;
        }

        .delete-modal-confirm {
            border: 1px solid #b51f2a;
            background: #b51f2a;
            color: #ffffff;
        }

        .delete-modal-confirm:hover {
            background: #941923;
            border-color: #941923;
        }

        .delete-modal-confirm:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        @media (max-width: 1050px) {
            .divisi-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 800px) {
            .main {
                margin-left: 0;
                width: 100%;
            }

            .content {
                padding: 22px 20px 30px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 520px) {
            .divisi-card {
                padding: 15px;
            }

            .divisi-info {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .info-item {
                padding-right: 15px;
            }

            .info-item + .info-item {
                padding-left: 10px;
                padding-top: 10px;
                border-left: none;
                border-top: 1px solid #e8ebef;
            }

            .card-bottom {
                flex-direction: column;
                align-items: flex-end;
            }

            .card-note {
                align-self: flex-start;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .divisi-card,
            .btn-tambah {
                transition: none;
            }
        }

        /* =========================================================
           DARK MODE (palet sama dengan dashboard)
        ========================================================= */
        html:has(body.dark-mode),
        html:has(body.dark-mode) body{background:#0f141b!important}

        html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
        html body.dark-mode .main,
        html body.dark-mode .content{background:#0f141b!important}

        html body.dark-mode .page-title h1{color:#edf2f7!important}
        html body.dark-mode .page-title p{color:#8f9bac!important}

        html body.dark-mode .alert.success{background:#183d2a!important;color:#72d89a!important}
        html body.dark-mode .alert.error{background:#302326!important;color:#f08a90!important}

        html body.dark-mode .divisi-card,
        html body.dark-mode .empty-data{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}

        html body.dark-mode .divisi-name,
        html body.dark-mode .info-value,
        html body.dark-mode .status{color:#edf2f7!important}

        html body.dark-mode .info-label,
        html body.dark-mode .empty-data,
        html body.dark-mode .card-note,
        html body.dark-mode .footer-note{color:#8f9bac!important}

        html body.dark-mode .divider{background:#2c3542!important}
        html body.dark-mode .divisi-info{background:#202733!important}
        html body.dark-mode .info-item + .info-item{border-left-color:#2c3542!important;border-top-color:#2c3542!important}
        html body.dark-mode .card-bottom{border-top-color:#2c3542!important}

        html body.dark-mode .btn-edit,
        html body.dark-mode .btn-hapus{background:#382326!important;border-color:#51363b!important;color:#f07a80!important}
        html body.dark-mode .btn-edit:hover,
        html body.dark-mode .btn-hapus:hover{background:#b51f2a!important;border-color:#b51f2a!important;color:#fff!important}
        html body.dark-mode .btn-hapus:disabled,
        html body.dark-mode .btn-hapus:disabled:hover{background:#202733!important;border-color:#2c3542!important;color:#6f7a8a!important}

        html body.dark-mode .delete-modal-box{background:#151b23!important;border:1px solid #2c3542!important}
        html body.dark-mode .delete-modal-box h3,
        html body.dark-mode .delete-modal-box p strong{color:#edf2f7!important}
        html body.dark-mode .delete-modal-box p{color:#8f9bac!important}
        html body.dark-mode .delete-modal-icon{background:#382326!important;color:#f07a80!important}
        html body.dark-mode .delete-modal-cancel{background:#202733!important;border-color:#2c3542!important;color:#cbd2dc!important}
        html body.dark-mode .delete-modal-cancel:hover{background:#2a3342!important}

    </style>

</head>

<body>

    <?php include "../includes/super_admin/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/super_admin/topbar.php"; ?>

        <div class="content">

            <div class="page-header">

                <div class="page-title">
                    <h1>Divisi</h1>
                    <p>Kelola data divisi yang terdapat dalam sistem SIMO.</p>
                </div>

                <button
                    type="button"
                    class="btn-tambah"
                    onclick="window.location.href='tambah_divisi.php'"
                >
                    + Tambah Divisi
                </button>

            </div>

            <?php if ($pesan_ok != ""): ?>
                <div class="alert success"><?= e($pesan_ok); ?></div>
            <?php endif; ?>

            <?php if ($pesan_err != ""): ?>
                <div class="alert error" role="alert"><?= e($pesan_err); ?></div>
            <?php endif; ?>

            <div class="divisi-grid">

                <?php if (mysqli_num_rows($query) > 0): ?>

                    <?php while ($divisi = mysqli_fetch_assoc($query)): ?>

                        <?php
                            $id_d = (int) $divisi["id_divisi"];
                            $n_user_d = (int) $divisi["total_user"];
                            $n_pekerjaan_d = (int) $divisi["total_pekerjaan"];
                            $n_order_d = (int) ($total_order[$id_d] ?? 0);

                            $alasan_d = [];
                            if ($n_user_d > 0) { $alasan_d[] = $n_user_d . " pengguna"; }
                            if ($n_pekerjaan_d > 0) { $alasan_d[] = $n_pekerjaan_d . " pekerjaan"; }
                            if ($n_order_d > 0) { $alasan_d[] = $n_order_d . " order"; }

                            $bisa_hapus = count($alasan_d) === 0;
                        ?>

                        <div class="divisi-card">

                            <div class="divisi-header">

                                <div class="divisi-name">
                                    <?= e($divisi["nama_divisi"]); ?>
                                </div>

                            </div>

                            <div class="divider"></div>

                            <div class="divisi-info">

                                <div class="info-item">
                                    <div class="info-label">Jumlah Karyawan</div>
                                    <div class="info-value">
                                        <?= number_format((int) $divisi["jumlah_karyawan"]); ?> Orang
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Status</div>
                                    <div class="status">
                                        <span class="status-dot"></span>
                                        Aktif
                                    </div>
                                </div>

                            </div>

                            <div class="card-bottom">

                                <?php if (!$bisa_hapus): ?>
                                    <div class="card-note">Belum bisa dihapus: masih ada <?= e(implode(", ", $alasan_d)); ?>.</div>
                                <?php endif; ?>

                                <div class="card-actions">

                                    <button
                                        type="button"
                                        class="btn-edit"
                                        onclick="window.location.href='edit_divisi.php?id=<?= $id_d; ?>'"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="btn-hapus"
                                        data-id="<?= $id_d; ?>"
                                        data-nama="<?= e($divisi["nama_divisi"]); ?>"
                                        <?php if (!$bisa_hapus): ?>
                                            disabled
                                            title="Tidak bisa dihapus karena masih ada <?= e(implode(", ", $alasan_d)); ?>"
                                        <?php else: ?>
                                            title="Hapus divisi"
                                        <?php endif; ?>
                                    >
                                        Hapus
                                    </button>

                                </div>

                            </div>

                        </div>

                    <?php endwhile; ?>

                <?php else: ?>

                    <div class="empty-data">
                        Belum ada data divisi.
                    </div>

                <?php endif; ?>

            </div>

            <div class="footer-note">
                &copy; <?= date("Y"); ?>
                SIMO METRP. All rights reserved.
            </div>

        </div>

    </div>

    <!-- FORM HAPUS (disubmit lewat JavaScript) -->
    <form method="POST" action="<?= e($file_halaman); ?>" id="formHapus" hidden>
        <input type="hidden" name="aksi" value="hapus">
        <input type="hidden" name="csrf" value="<?= e($csrf); ?>">
        <input type="hidden" name="id_divisi" id="hapusId" value="">
    </form>

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

            <h3>Hapus Divisi?</h3>

            <p>Divisi <strong id="hapusNama"></strong> akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.</p>

            <div class="delete-modal-actions">
                <button type="button" class="delete-modal-btn delete-modal-cancel" id="batalHapus">Batal</button>
                <button type="button" class="delete-modal-btn delete-modal-confirm" id="konfirmasiHapus">Ya, Hapus</button>
            </div>

        </div>
    </div>

    <script>
        const deleteModal = document.getElementById("deleteModal");
        const hapusNama = document.getElementById("hapusNama");
        const hapusId = document.getElementById("hapusId");
        const konfirmasiHapus = document.getElementById("konfirmasiHapus");

        let idDivisiHapus = "";

        function tutupHapus() {
            deleteModal.classList.remove("show");
            idDivisiHapus = "";
        }

        document.querySelectorAll(".btn-hapus").forEach(function (tombol) {
            tombol.addEventListener("click", function () {

                if (this.disabled) {
                    return;
                }

                idDivisiHapus = this.dataset.id || "";
                hapusNama.textContent = this.dataset.nama || "";
                deleteModal.classList.add("show");
            });
        });

        document.getElementById("batalHapus").addEventListener("click", tutupHapus);

        konfirmasiHapus.addEventListener("click", function () {

            if (idDivisiHapus === "") {
                return;
            }

            konfirmasiHapus.disabled = true;
            hapusId.value = idDivisiHapus;
            document.getElementById("formHapus").submit();
        });

        deleteModal.addEventListener("click", function (event) {
            if (event.target === deleteModal) {
                tutupHapus();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                tutupHapus();
            }
        });
    </script>

</body>

</html>
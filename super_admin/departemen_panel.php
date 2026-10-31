<?php
// Di-include dari pengguna.php (tab=departemen). $koneksi sudah tersedia.

$query_departemen = "
    SELECT id_user, nama, username, departemen, status
    FROM users
    WHERE role = 'Departemen'
    AND COALESCE(TRIM(status), '') <> 'Dihapus'
    ORDER BY
        CASE WHEN TRIM(status) = 'Aktif' THEN 0 ELSE 1 END ASC,
        nama ASC
";

$result_departemen = mysqli_query($koneksi, $query_departemen);

if (!$result_departemen) {
    die("Query Departemen gagal: " . mysqli_error($koneksi));
}
?>

<style>
.admin-panel .admin-table.dep-table th:nth-child(1),
.admin-panel .admin-table.dep-table td:nth-child(1){width:6% !important}
.admin-panel .admin-table.dep-table th:nth-child(2),
.admin-panel .admin-table.dep-table td:nth-child(2){width:24% !important}
.admin-panel .admin-table.dep-table th:nth-child(3),
.admin-panel .admin-table.dep-table td:nth-child(3){width:20% !important}
.admin-panel .admin-table.dep-table th:nth-child(4),
.admin-panel .admin-table.dep-table td:nth-child(4){width:14% !important}
.admin-panel .admin-table.dep-table th:nth-child(5),
.admin-panel .admin-table.dep-table td:nth-child(5){width:14% !important}
.admin-panel .admin-table.dep-table th:nth-child(6),
.admin-panel .admin-table.dep-table td:nth-child(6){width:22% !important}

.action-wrapper .btn-hapus-dep{
    order:4;
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    width:32px !important;
    height:32px !important;
    padding:0;
    border:1px solid #d9dde2 !important;
    border-radius:8px !important;
    background:#ffffff !important;
    color:#d92d20 !important;
    cursor:pointer;
    transition:background .15s ease,border-color .15s ease,color .15s ease;
}
.action-wrapper .btn-hapus-dep svg{
    width:16px;height:16px;fill:none;stroke:currentColor;
    stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;
}
.action-wrapper .btn-hapus-dep:hover{background:#fef3f2 !important;border-color:#fecdca !important;color:#b42318 !important}

html body.dark-mode .action-wrapper .btn-hapus-dep{background:#151b23 !important;border-color:#2c3542 !important;color:#ff8b92 !important}
html body.dark-mode .action-wrapper .btn-hapus-dep:hover{background:#4b2d32 !important;border-color:#70444b !important;color:#ffb1b6 !important}
</style>

<div class="section admin-panel">

    <div class="section-header">
        <div class="section-title-area">
            <div class="section-title">Departemen</div>
            <div class="section-description">Data akun Departemen yang dikelola oleh Super Admin.</div>
        </div>

        <a href="tambah_departemen.php" class="btn-tambah">
            + Tambah Departemen
        </a>
    </div>

    <div class="table-card">
        <div class="table-wrapper">

            <table class="admin-table dep-table">

                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Departemen</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (mysqli_num_rows($result_departemen) > 0): ?>

                    <?php $no_dep = 1; ?>

                    <?php while ($dep = mysqli_fetch_assoc($result_departemen)): ?>

                        <?php $dep_aktif = trim($dep["status"]) === "Aktif"; ?>

                        <tr class="<?= !$dep_aktif ? "admin-inactive" : "" ?>">

                            <td><?= $no_dep++; ?></td>

                            <td class="admin-name">
                                <?= htmlspecialchars($dep["nama"]); ?>
                            </td>

                            <td class="admin-username">
                                <?= htmlspecialchars($dep["username"]); ?>
                            </td>

                            <td>
                                <span class="user-role-badge user-role-karyawan"><?= htmlspecialchars($dep["departemen"] ?? "-"); ?></span>
                            </td>

                            <td>
                                <?php if ($dep_aktif): ?>
                                    <span class="status status-aktif">Aktif</span>
                                <?php else: ?>
                                    <span class="status status-nonaktif">Tidak Aktif</span>
                                <?php endif; ?>
                            </td>

                            <td class="action-cell">

                                <div class="action-wrapper">

                                    <a
                                        href="tambah_departemen.php?id=<?= (int) $dep["id_user"]; ?>"
                                        class="btn-edit"
                                        title="Edit Departemen"
                                        aria-label="Edit Departemen"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M4 20h4.2L19 9.2a1.8 1.8 0 0 0 0-2.5L17.3 5a1.8 1.8 0 0 0-2.5 0L5.7 14.1V20Z"></path>
                                            <path d="M13.5 7.5 16.5 10.5"></path>
                                        </svg>
                                    </a>

                                    <form method="POST" class="toggle-form">
                                        <input type="hidden" name="toggle_departemen" value="<?= (int) $dep["id_user"]; ?>">

                                        <label class="toggle" title="<?= $dep_aktif ? "Nonaktifkan Departemen" : "Aktifkan Departemen"; ?>">
                                            <input
                                                type="checkbox"
                                                <?= $dep_aktif ? "checked" : "" ?>
                                                onchange="this.form.submit();"
                                            >
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </form>

                                    <span class="toggle-label <?= $dep_aktif ? "aktif" : "nonaktif"; ?>">
                                        <?= $dep_aktif ? "Aktif" : "Nonaktif"; ?>
                                    </span>

                                    <button
                                        type="button"
                                        class="btn-hapus-dep"
                                        title="Hapus Departemen"
                                        aria-label="Hapus Departemen"
                                        data-id="<?= (int) $dep["id_user"]; ?>"
                                        data-nama="<?= htmlspecialchars($dep["nama"], ENT_QUOTES); ?>"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M3 6h18"></path>
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                        </svg>
                                    </button>

                                </div>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty-data">Belum ada akun Departemen.</td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>
    </div>

</div>

<!-- POPUP KONFIRMASI HAPUS DEPARTEMEN -->
<div class="delete-modal-overlay" id="deleteModalDep">
    <div class="delete-modal-box">

        <div class="delete-modal-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 6h18"></path>
                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                <path d="M10 11v5"></path>
                <path d="M14 11v5"></path>
            </svg>
        </div>

        <h3>Hapus Akun Departemen?</h3>

        <p>
            Akun <strong id="deleteNamaDep"></strong> akan dihapus dan tidak
            dapat digunakan untuk login lagi.
        </p>

        <form method="POST" class="delete-modal-actions">
            <input type="hidden" name="hapus_departemen" id="deleteIdDep" value="">
            <button type="button" class="delete-modal-btn delete-modal-cancel" id="batalHapusDep">Batal</button>
            <button type="submit" class="delete-modal-btn delete-modal-confirm">Ya, Hapus</button>
        </form>

    </div>
</div>

<script>
(function () {
    var modal = document.getElementById("deleteModalDep");
    var inputId = document.getElementById("deleteIdDep");
    var teksNama = document.getElementById("deleteNamaDep");

    function tutup() {
        modal.classList.remove("show");
        inputId.value = "";
    }

    document.querySelectorAll(".btn-hapus-dep").forEach(function (tombol) {
        tombol.addEventListener("click", function () {
            inputId.value = this.getAttribute("data-id") || "";
            teksNama.textContent = this.getAttribute("data-nama") || "";
            modal.classList.add("show");
        });
    });

    document.getElementById("batalHapusDep").addEventListener("click", tutup);

    modal.addEventListener("click", function (e) {
        if (e.target === modal) { tutup(); }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { tutup(); }
    });
})();
</script>
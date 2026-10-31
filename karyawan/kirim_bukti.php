<?php
include "../config/session.php";

/* Waktu server mengikuti WIB (bukan UTC) */
date_default_timezone_set("Asia/Jakarta");

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
   BANTUAN: tambah kolom otomatis kalau belum ada
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

/* =========================================================
   BANTUAN: proses satu kelompok foto (sebelum / sesudah)
   - $data_list  : foto baru (base64) dari form
   - $lama_post  : foto lama yang dipertahankan (dari form)
   - $lama_boleh : foto lama milik karyawan ini (validasi server)
   Return: daftar nama file akhir (lama + baru)
========================================================= */
function simo_proses_foto_grup($data_list, $lama_post, $lama_boleh, $prefix, &$pesan, &$file_baru_list)
{
    if (!is_array($data_list)) {
        $data_list = [$data_list];
    }

    if (!is_array($lama_post)) {
        $lama_post = [$lama_post];
    }

    $lama_post = array_values(array_filter(array_map("trim", $lama_post)));

    /* hanya foto milik karyawan ini yang boleh dipertahankan */
    $lama_post = array_values(array_intersect($lama_post, $lama_boleh));
    $lama_post = array_slice($lama_post, 0, 5);

    $baru = [];

    foreach ($data_list as $foto_data) {

        if (!empty($pesan)) {
            break;
        }

        if (empty($foto_data)) {
            continue;
        }

        if (strpos($foto_data, "data:image/") !== 0) {
            $pesan = "Format foto tidak valid.";
            break;
        }

        $foto_parts = explode(",", $foto_data, 2);

        if (count($foto_parts) != 2) {
            $pesan = "Data foto tidak valid.";
            break;
        }

        $gambar = base64_decode($foto_parts[1]);

        if ($gambar === false) {
            $pesan = "Foto gagal diproses.";
            break;
        }

        $folder = "../uploads/bukti/";

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        if (count($lama_post) + count($baru) >= 5) {
            $pesan = "Maksimal 5 foto untuk setiap kelompok (sebelum / sesudah).";
            break;
        }

        $nama_file   = $prefix . "_" . time() . "_" . (count($baru) + 1) . ".jpg";
        $lokasi_file = $folder . $nama_file;

        if (file_put_contents($lokasi_file, $gambar) === false) {
            $pesan = "Foto gagal disimpan.";
            break;
        }

        $baru[]            = $nama_file;
        $file_baru_list[]  = $lokasi_file;
    }

    return array_values(array_unique(array_merge($lama_post, $baru)));
}

/* =========================================================
   MODE: PEKERJAAN BIASA ATAU ORDER PERBAIKAN
   - kirim_bukti.php?id=ID                  => pekerjaan (tabel pekerjaan)
   - kirim_bukti.php?id=ID_ORDER&tipe=order => order perbaikan
========================================================= */
$is_order = (($_GET["tipe"] ?? "") === "order");

/* =========================================================
   PASTIKAN BUKTI MEMILIKI IDENTITAS PENGIRIM
========================================================= */
if (!$is_order) {
    $cek_kolom_bukti = mysqli_query(
        $koneksi,
        "SHOW COLUMNS FROM bukti_pekerjaan LIKE 'id_user'"
    );

    if ($cek_kolom_bukti && mysqli_num_rows($cek_kolom_bukti) == 0) {
        mysqli_query(
            $koneksi,
            "ALTER TABLE bukti_pekerjaan ADD COLUMN id_user INT NULL AFTER id_pekerjaan"
        );
    }

    /* Waktu selesai sebenarnya (diisi karyawan saat kirim bukti) */
    simo_pastikan_kolom($koneksi, "bukti_pekerjaan", "waktu_selesai", "DATETIME NULL");
    simo_pastikan_kolom($koneksi, "pekerjaan", "waktu_selesai", "DATETIME NULL");

    /* Foto sebelum pengerjaan (foto sesudah tetap di kolom foto) */
    simo_pastikan_kolom($koneksi, "bukti_pekerjaan", "foto_sebelum", "TEXT NULL");
} else {
    /* Tabel bukti khusus order perbaikan (dibuat otomatis kalau belum ada) */
    mysqli_query(
        $koneksi,
        "CREATE TABLE IF NOT EXISTS bukti_order (
            id_bukti INT AUTO_INCREMENT PRIMARY KEY,
            id_order INT NOT NULL,
            id_user INT NOT NULL,
            foto TEXT NULL,
            catatan_karyawan TEXT NULL,
            tanggal_upload DATETIME NOT NULL,
            INDEX idx_bukti_order (id_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    /* Tabel pekerja per order (PIC + anggota tim) */
    mysqli_query(
        $koneksi,
        "CREATE TABLE IF NOT EXISTS order_pekerja (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_order INT NOT NULL,
            id_user INT NOT NULL,
            UNIQUE KEY uq_order_user (id_order, id_user)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    /* Kolom tipe kerja (Individu / Tim) */
    $cek_tipe = mysqli_query(
        $koneksi,
        "SHOW COLUMNS FROM order_perbaikan LIKE 'tipe_kerja'"
    );

    if ($cek_tipe && mysqli_num_rows($cek_tipe) == 0) {
        mysqli_query(
            $koneksi,
            "ALTER TABLE order_perbaikan ADD COLUMN tipe_kerja VARCHAR(10) NULL"
        );
    }

    /* Waktu selesai sebenarnya (diisi karyawan saat kirim bukti) */
    simo_pastikan_kolom($koneksi, "bukti_order", "waktu_selesai", "DATETIME NULL");
    simo_pastikan_kolom($koneksi, "order_perbaikan", "waktu_selesai", "DATETIME NULL");

    /* Foto sebelum pengerjaan (foto sesudah tetap di kolom foto) */
    simo_pastikan_kolom($koneksi, "bukti_order", "foto_sebelum", "TEXT NULL");
}

/* =========================================================
   DATA KARYAWAN
========================================================= */
$id_karyawan = (int) $_SESSION["id_user"];

$query_karyawan = mysqli_query(
    $koneksi,
    "
    SELECT
        users.*,
        divisi.nama_divisi
    FROM users
    LEFT JOIN divisi
        ON users.id_divisi = divisi.id_divisi
    WHERE users.id_user='$id_karyawan'
    AND users.role='Karyawan'
    LIMIT 1
    "
);

if (!$query_karyawan || mysqli_num_rows($query_karyawan) == 0) {
    header("Location: dashboard.php");
    exit;
}

$karyawan      = mysqli_fetch_assoc($query_karyawan);
$nama_karyawan = $karyawan["nama"];
$id_divisi     = (int) $karyawan["id_divisi"];
$nama_divisi   = $karyawan["nama_divisi"] ?? "-";

/* =========================================================
   CEK ID
========================================================= */
$halaman_asal = $is_order ? "order_masuk.php?tab=saya" : "tugas.php";

if (!isset($_GET["id"]) || !is_numeric($_GET["id"])) {
    header("Location: " . $halaman_asal);
    exit;
}

$id_ref       = (int) $_GET["id"];
$id_pekerjaan = $id_ref;   // dipakai mode pekerjaan
$id_order     = $id_ref;   // dipakai mode order

/* =========================================================
   DATA PEKERJAAN / ORDER
========================================================= */
$bukti_lama              = null;
$foto_lama_list          = [];   // foto sesudah milik karyawan ini
$foto_sebelum_lama_list  = [];   // foto sebelum milik karyawan ini
$bukti_sebelumnya        = [];
$daftar_karyawan         = [];   // dipakai mode order (pilihan anggota tim)

if ($is_order) {

    /*
     * Order hanya boleh dikirimi bukti oleh karyawan yang
     * mengambilnya, dan hanya saat statusnya masih Diproses.
     */
    $st = $koneksi->prepare(
        "SELECT o.*, COALESCE(NULLIF(up.departemen, ''), da.nama_divisi) AS divisi_asal
         FROM order_perbaikan o
         LEFT JOIN users up ON o.id_pemesan = up.id_user
         LEFT JOIN divisi da ON o.id_divisi_asal = da.id_divisi
         WHERE o.id_order = ?
           AND o.id_karyawan = ?
           AND o.status = 'Diproses'
         LIMIT 1"
    );
    $st->bind_param("ii", $id_order, $id_karyawan);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();

    if (!$order) {
        header("Location: order_masuk.php?tab=saya");
        exit;
    }

    /* Samakan bentuk data dengan mode pekerjaan */
    $pekerjaan = [
        "nama_pekerjaan" => $order["judul"],
        "tipe_order"     => "Order Perbaikan",
        "nomor_order"    => $order["nomor_order"],
        "id_divisi"      => $order["id_divisi"],
        "lokasi"         => $order["lokasi"] ?? ""
    ];
    $status = "Diproses";

    /* Daftar rekan satu divisi yang bisa dipilih sebagai anggota.
       PIC (karyawan ini) dikecualikan karena otomatis ikut. */
    $st = $koneksi->prepare(
        "SELECT id_user, nama
         FROM users
         WHERE role = 'Karyawan'
           AND status = 'Aktif'
           AND id_divisi = ?
           AND id_user <> ?
         ORDER BY nama"
    );
    $id_divisi_order = (int) $order["id_divisi"];
    $st->bind_param("ii", $id_divisi_order, $id_karyawan);
    $st->execute();
    $daftar_karyawan = $st->get_result()->fetch_all(MYSQLI_ASSOC);

} else {

    /*
     * Status yang boleh mengirim / mengubah bukti:
     * Belum Dikerjakan, Perlu Perbaikan, Peralihan, Menunggu Konfirmasi.
     * Kondisi akses disamakan dengan detail_tugas.php.
     */
    $query = mysqli_query(
        $koneksi,
        "
        SELECT
            p.*,
            d.nama_divisi
        FROM pekerjaan p
        LEFT JOIN divisi d
            ON p.id_divisi = d.id_divisi
        WHERE p.id_pekerjaan='$id_pekerjaan'
        AND p.id_divisi='$id_divisi'
        AND (
            EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_login
                WHERE pk_login.id_pekerjaan = p.id_pekerjaan
                AND pk_login.id_user='$id_karyawan'
            )
            OR
            (
                p.id_karyawan='$id_karyawan'
                AND NOT EXISTS (
                    SELECT 1
                    FROM pekerjaan_karyawan pk_lama
                    WHERE pk_lama.id_pekerjaan = p.id_pekerjaan
                )
            )
        )
        AND p.status IN (
            'Belum Dikerjakan',
            'Perlu Perbaikan',
            'Peralihan',
            'Menunggu Konfirmasi'
        )
        LIMIT 1
        "
    );

    if (!$query || mysqli_num_rows($query) == 0) {
        header("Location: tugas.php");
        exit;
    }

    $pekerjaan = mysqli_fetch_assoc($query);
    $status    = $pekerjaan["status"] ?? "";

    /*
     * Bukti lama HANYA yang dikirim oleh karyawan yang sedang login.
     * Foto & catatan milik anggota lain tidak ikut dimuat ke form,
     * sehingga bukti setiap karyawan tersimpan terpisah (tidak menumpuk).
     */
    $query_bukti_lama = mysqli_query(
        $koneksi,
        "
        SELECT *
        FROM bukti_pekerjaan
        WHERE id_pekerjaan='$id_pekerjaan'
        AND id_user='$id_karyawan'
        ORDER BY id_bukti DESC
        LIMIT 1
        "
    );

    $bukti_lama = $query_bukti_lama ? mysqli_fetch_assoc($query_bukti_lama) : null;

    if (!empty($bukti_lama["foto"])) {
        $foto_lama_list = array_values(
            array_filter(array_map("trim", explode(",", $bukti_lama["foto"])))
        );
        $foto_lama_list = array_slice($foto_lama_list, 0, 5);
    }

    if (!empty($bukti_lama["foto_sebelum"])) {
        $foto_sebelum_lama_list = array_values(
            array_filter(array_map("trim", explode(",", $bukti_lama["foto_sebelum"])))
        );
        $foto_sebelum_lama_list = array_slice($foto_sebelum_lama_list, 0, 5);
    }

    /*
     * Bukti dari karyawan LAIN / karyawan sebelumnya (misalnya sebelum
     * pekerjaan dialihkan). Hanya ditampilkan (read-only).
     */
    $query_bukti_lain = mysqli_query(
        $koneksi,
        "
        SELECT bp.*, u.nama AS nama_pengirim
        FROM bukti_pekerjaan bp
        LEFT JOIN users u ON u.id_user = bp.id_user
        WHERE bp.id_pekerjaan='$id_pekerjaan'
        AND (bp.id_user IS NULL OR bp.id_user != '$id_karyawan')
        ORDER BY bp.id_bukti ASC
        "
    );

    if ($query_bukti_lain) {
        while ($row_lain = mysqli_fetch_assoc($query_bukti_lain)) {

            $foto_lain         = [];
            $foto_sebelum_lain = [];

            $cek_ada = function ($nama_foto) {
                return file_exists(__DIR__ . "/../uploads/bukti/" . $nama_foto);
            };

            if (!empty($row_lain["foto"])) {
                $foto_lain = array_values(
                    array_filter(array_map("trim", explode(",", $row_lain["foto"])))
                );
                $foto_lain = array_values(array_filter($foto_lain, $cek_ada));
            }

            if (!empty($row_lain["foto_sebelum"])) {
                $foto_sebelum_lain = array_values(
                    array_filter(array_map("trim", explode(",", $row_lain["foto_sebelum"])))
                );
                $foto_sebelum_lain = array_values(array_filter($foto_sebelum_lain, $cek_ada));
            }

            $catatan_lain = trim($row_lain["catatan_karyawan"] ?? "");

            if (empty($foto_lain) && empty($foto_sebelum_lain) && $catatan_lain === "") {
                continue;
            }

            $bukti_sebelumnya[] = [
                "nama"          => $row_lain["nama_pengirim"] ?? "Karyawan sebelumnya",
                "tanggal"       => $row_lain["tanggal_upload"] ?? "",
                "catatan"       => $catatan_lain,
                "foto"          => $foto_lain,
                "foto_sebelum"  => $foto_sebelum_lain
            ];
        }
    }
}

/* =========================================================
   PESAN
========================================================= */
$pesan = "";

/* =========================================================
   PROSES KIRIM BUKTI
========================================================= */
if (isset($_POST["kirim_bukti"])) {

    /* ---------- ITEM PEKERJAAN (disimpan di kolom catatan) ---------- */
    $catatan_raw = trim($_POST["catatan"] ?? "");
    $catatan     = mysqli_real_escape_string($koneksi, $catatan_raw);

    if ($catatan_raw === "") {
        $pesan = "Item pekerjaan wajib diisi.";
    }

    /* ---------- WAKTU SELESAI ---------- */
    $waktu_selesai_input = trim($_POST["waktu_selesai"] ?? "");
    $waktu_selesai_ts    = $waktu_selesai_input !== "" ? strtotime($waktu_selesai_input) : false;
    $waktu_selesai_db    = "";

    if ($pesan === "") {
        if ($waktu_selesai_ts === false) {
            $pesan = "Tanggal dan jam selesai tidak valid.";
        } elseif ($waktu_selesai_ts > time() + 300) {
            $pesan = "Tanggal dan jam selesai tidak boleh melebihi waktu sekarang.";
        } else {
            $waktu_selesai_db = date("Y-m-d H:i:s", $waktu_selesai_ts);
        }
    }

    /* ---------- NOMOR ORDER (khusus order, diisi karyawan) ---------- */
    $nomor_order_input = "";

    if ($is_order) {
        $nomor_order_input = trim($_POST["nomor_order"] ?? "");

        if ($pesan === "") {
            if ($nomor_order_input === "") {
                $pesan = "Nomor order wajib diisi.";
            } elseif (mb_strlen($nomor_order_input) > 50) {
                $pesan = "Nomor order maksimal 50 karakter.";
            } else {
                $st_dup = $koneksi->prepare(
                    "SELECT id_order FROM order_perbaikan
                     WHERE nomor_order = ? AND id_order <> ? LIMIT 1"
                );
                $st_dup->bind_param("si", $nomor_order_input, $id_order);
                $st_dup->execute();

                if ($st_dup->get_result()->fetch_assoc()) {
                    $pesan = "Nomor order sudah dipakai order lain.";
                }
            }
        }
    }

    /* ---------- FOTO SEBELUM & SESUDAH (opsional, maks 5 masing-masing) ---------- */
    $file_baru_list = [];

    $prefix_file = $is_order ? "bukti_order_" . $id_order : "bukti_" . $id_pekerjaan;

    /* foto sesudah */
    $foto_list = simo_proses_foto_grup(
        $_POST["foto_data"] ?? [],
        $_POST["foto_lama"] ?? [],
        $foto_lama_list,
        $prefix_file,
        $pesan,
        $file_baru_list
    );

    /* foto sebelum */
    $foto_sebelum_list = simo_proses_foto_grup(
        $_POST["foto_sebelum_data"] ?? [],
        $_POST["foto_sebelum_lama"] ?? [],
        $foto_sebelum_lama_list,
        "sebelum_" . $prefix_file,
        $pesan,
        $file_baru_list
    );

    $foto_db          = mysqli_real_escape_string($koneksi, implode(",", $foto_list));
    $foto_sebelum_db  = mysqli_real_escape_string($koneksi, implode(",", $foto_sebelum_list));

    /* Kalau ada error, hapus foto baru yang sempat tersimpan */
    if (!empty($pesan)) {
        foreach ($file_baru_list as $file_baru) {
            @unlink($file_baru);
        }
    }

    /* ---------- VALIDASI PEKERJA (khusus order) ---------- */
    if (empty($pesan) && $is_order) {
        $jumlah_form  = max(1, (int) ($_POST["jumlah_karyawan"] ?? 1));
        $id_valid_cek = array_map("intval", array_column($daftar_karyawan, "id_user"));
        $pilih_cek    = array_values(array_unique(array_intersect(
            array_map("intval", (array) ($_POST["pekerja"] ?? [])),
            $id_valid_cek
        )));

        if (count($pilih_cek) !== $jumlah_form - 1) {
            $pesan = "Pilih nama untuk setiap karyawan yang ikut mengerjakan, tanpa nama dobel.";

            foreach ($file_baru_list as $file_baru) {
                @unlink($file_baru);
            }
        }
    }

    /* ---------- JIKA TIDAK ADA ERROR ---------- */
    if (empty($pesan)) {

        /* =====================================================
           MODE ORDER PERBAIKAN
           Simpan bukti, catat pekerja (individu / tim),
           lalu ubah status order jadi Menunggu Konfirmasi
           (Selesai baru setelah dikonfirmasi pemesan atau
           Admin Divisi).
        ===================================================== */
        if ($is_order) {

            $koneksi->begin_transaction();
            $berhasil = false;

            $st = $koneksi->prepare(
                "INSERT INTO bukti_order (id_order, id_user, foto, foto_sebelum, catatan_karyawan, tanggal_upload, waktu_selesai)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?)"
            );
            $foto_simpan          = implode(",", $foto_list);
            $foto_sebelum_simpan  = implode(",", $foto_sebelum_list);
            $st->bind_param("iissss", $id_order, $id_karyawan, $foto_simpan, $foto_sebelum_simpan, $catatan_raw, $waktu_selesai_db);

            if ($st->execute()) {

                $st2 = $koneksi->prepare(
                    "UPDATE order_perbaikan
                     SET status = 'Menunggu Konfirmasi', nomor_order = ?, waktu_selesai = ?
                     WHERE id_order = ? AND id_karyawan = ? AND status = 'Diproses'"
                );
                $st2->bind_param("ssii", $nomor_order_input, $waktu_selesai_db, $id_order, $id_karyawan);
                $st2->execute();

                if ($st2->affected_rows > 0) {

                    /* Validasi di server: hanya id dari daftar divisi yang diterima */
                    $id_valid = array_map("intval", array_column($daftar_karyawan, "id_user"));
                    $dipilih  = array_map("intval", (array) ($_POST["pekerja"] ?? []));
                    $dipilih  = array_intersect($dipilih, $id_valid);

                    /* PIC otomatis ikut, tidak ada nama dobel */
                    $semua_pekerja = array_values(array_unique(array_merge([$id_karyawan], $dipilih)));
                    $tipe_kerja    = count($semua_pekerja) > 1 ? "Tim" : "Individu";

                    $ok = true;

                    /* Kirim ulang setelah dikembalikan: reset daftar pekerja lama */
                    $st_hapus = $koneksi->prepare("DELETE FROM order_pekerja WHERE id_order = ?");
                    $st_hapus->bind_param("i", $id_order);
                    $st_hapus->execute();

                    $st4 = $koneksi->prepare(
                        "INSERT INTO order_pekerja (id_order, id_user) VALUES (?, ?)"
                    );
                    foreach ($semua_pekerja as $id_p) {
                        $st4->bind_param("ii", $id_order, $id_p);
                        if (!$st4->execute()) {
                            $ok = false;
                            break;
                        }
                    }

                    if ($ok) {
                        $st5 = $koneksi->prepare(
                            "UPDATE order_perbaikan SET tipe_kerja = ? WHERE id_order = ?"
                        );
                        $st5->bind_param("si", $tipe_kerja, $id_order);
                        $ok = $st5->execute();
                    }

                    $berhasil = $ok;
                }
            }

            if ($berhasil) {

                $koneksi->commit();

                /* Beri tahu Admin Divisi bahwa ada bukti yang perlu dikonfirmasi */
                $id_divisi_order_notif = (int) ($order["id_divisi"] ?? 0);

                $st3 = $koneksi->prepare(
                    "SELECT id_user FROM users
                     WHERE role = 'Admin' AND id_divisi = ? AND status = 'Aktif'
                     LIMIT 1"
                );
                $st3->bind_param("i", $id_divisi_order_notif);
                $st3->execute();
                $admin_order = $st3->get_result()->fetch_assoc();

                if ($admin_order) {
                    $pesan_notif = "Karyawan mengirim bukti order perbaikan " . $nomor_order_input . " (" . $order["judul"] . "), menunggu konfirmasi.";

                    $st6 = $koneksi->prepare(
                        "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal)
                         VALUES (?, ?, 'Belum Dibaca', NOW())"
                    );
                    $id_admin_order = (int) $admin_order["id_user"];
                    $st6->bind_param("is", $id_admin_order, $pesan_notif);
                    $st6->execute();
                }

                /* Beri tahu pengguna yang membuat order supaya bisa memeriksa & mengonfirmasi */
                $id_pemesan_order = (int) ($order["id_pemesan"] ?? 0);

                if ($id_pemesan_order > 0) {
                    $pesan_pemesan = "Order Anda " . $nomor_order_input . " (" . $order["judul"] . ") sudah dikerjakan. Silakan periksa hasilnya dan konfirmasi di Detail Order.";

                    $st7 = $koneksi->prepare(
                        "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal)
                         VALUES (?, ?, 'Belum Dibaca', NOW())"
                    );
                    $st7->bind_param("is", $id_pemesan_order, $pesan_pemesan);
                    $st7->execute();
                }

                $_SESSION["flash_order"] = "Bukti terkirim ("
                    . ($tipe_kerja === "Tim" ? "dikerjakan tim " . count($semua_pekerja) . " orang" : "dikerjakan individu")
                    . "). Order menunggu konfirmasi pemesan atau Admin Divisi.";
                unset($_SESSION["flash_order_error"]);

                header("Location: order_masuk.php?tab=saya");
                exit;

            } else {

                $koneksi->rollback();

                foreach ($file_baru_list as $file_baru) {
                    @unlink($file_baru);
                }

                $pesan = "Bukti order gagal disimpan, atau order sudah tidak berstatus Diproses.";
            }

        /* =====================================================
           MODE PEKERJAAN BIASA
        ===================================================== */
        } else {

            /*
             * Edit bukti (status Menunggu Konfirmasi) dan karyawan ini sudah
             * punya bukti: perbarui baris miliknya, jangan buat baris baru.
             * Selain itu: simpan sebagai bukti baru.
             */
            if ($status == "Menunggu Konfirmasi" && $bukti_lama) {

                $id_bukti_lama = (int) $bukti_lama["id_bukti"];

                $simpan_bukti = mysqli_query(
                    $koneksi,
                    "
                    UPDATE bukti_pekerjaan
                    SET
                        foto='$foto_db',
                        foto_sebelum='$foto_sebelum_db',
                        catatan_karyawan='$catatan',
                        waktu_selesai='$waktu_selesai_db',
                        tanggal_upload=NOW()
                    WHERE id_bukti='$id_bukti_lama'
                    AND id_pekerjaan='$id_pekerjaan'
                    AND id_user='$id_karyawan'
                    "
                );

            } else {

                $simpan_bukti = mysqli_query(
                    $koneksi,
                    "
                    INSERT INTO bukti_pekerjaan
                    (
                        id_pekerjaan,
                        id_user,
                        foto,
                        foto_sebelum,
                        catatan_karyawan,
                        catatan_admin,
                        waktu_selesai,
                        tanggal_upload
                    )
                    VALUES
                    (
                        '$id_pekerjaan',
                        '$id_karyawan',
                        '$foto_db',
                        '$foto_sebelum_db',
                        '$catatan',
                        '',
                        '$waktu_selesai_db',
                        NOW()
                    )
                    "
                );
            }

            if (!$simpan_bukti) {

                /* Hapus foto baru jika database gagal */
                foreach ($file_baru_list as $file_baru) {
                    @unlink($file_baru);
                }

                $pesan = "Bukti pekerjaan gagal disimpan: " . mysqli_error($koneksi);

            } else {

                /*
                 * Hitung anggota yang SEDANG memegang pekerjaan ini.
                 * Status 'Menunggu Konfirmasi' / 'Perlu Perbaikan' tetap
                 * dihitung supaya pekerjaan tim tidak terbaca individu.
                 */
                $query_jumlah_anggota = mysqli_query(
                    $koneksi,
                    "
                    SELECT COUNT(DISTINCT id_user) AS jumlah_anggota
                    FROM pekerjaan_karyawan
                    WHERE id_pekerjaan='$id_pekerjaan'
                    AND status_penugasan IN (
                        'Aktif',
                        'Peralihan',
                        'Menunggu Konfirmasi',
                        'Perlu Perbaikan'
                    )
                    "
                );

                $jumlah_anggota = 0;

                if ($query_jumlah_anggota) {
                    $data_jumlah_anggota = mysqli_fetch_assoc($query_jumlah_anggota);
                    $jumlah_anggota = (int) ($data_jumlah_anggota["jumlah_anggota"] ?? 0);
                }

                /* Tidak ada relasi (data legacy) = pekerjaan individu */
                if ($jumlah_anggota < 1) {
                    $jumlah_anggota = 1;
                }

                $is_pekerjaan_tim = ($jumlah_anggota > 1);

                /* ---------- UPDATE STATUS PEKERJAAN + WAKTU SELESAI ---------- */
                $update_status = mysqli_query(
                    $koneksi,
                    "
                    UPDATE pekerjaan
                    SET status='Menunggu Konfirmasi',
                        waktu_selesai='$waktu_selesai_db'
                    WHERE id_pekerjaan='$id_pekerjaan'
                    AND id_divisi='$id_divisi'
                    "
                );

                if (!$update_status) {

                    $pesan = "Status pekerjaan gagal diperbarui: " . mysqli_error($koneksi);

                } else {

                    /*
                     * Pekerjaan tim: cukup satu anggota yang mengirim bukti.
                     * Semua anggota yang sedang memegang pekerjaan ikut
                     * Menunggu Konfirmasi.
                     */
                    if ($is_pekerjaan_tim) {

                        $update_anggota_tim = mysqli_query(
                            $koneksi,
                            "
                            UPDATE pekerjaan_karyawan
                            SET status_penugasan='Menunggu Konfirmasi'
                            WHERE id_pekerjaan='$id_pekerjaan'
                            AND status_penugasan IN (
                                'Aktif',
                                'Peralihan',
                                'Perlu Perbaikan'
                            )
                            "
                        );

                        if (!$update_anggota_tim) {
                            $pesan = "Status anggota tim gagal diperbarui: " . mysqli_error($koneksi);
                        }
                    }

                    /* ---------- SIMPAN RIWAYAT ---------- */
                    $tanggal_hapus_foto = date("Y-m-d H:i:s", strtotime("+1 day"));

                    mysqli_query(
                        $koneksi,
                        "
                        INSERT INTO riwayat_pekerjaan
                        (
                            id_pekerjaan,
                            status,
                            catatan,
                            foto,
                            tanggal,
                            tanggal_hapus_foto
                        )
                        VALUES
                        (
                            '$id_pekerjaan',
                            'Menunggu Konfirmasi',
                            '$catatan',
                            '$foto_db',
                            NOW(),
                            '$tanggal_hapus_foto'
                        )
                        "
                    );

                    /* ---------- CARI ADMIN DIVISI ---------- */
                    $id_divisi_pekerjaan = (int) $pekerjaan["id_divisi"];

                    $admin_query = mysqli_query(
                        $koneksi,
                        "
                        SELECT id_user
                        FROM users
                        WHERE role='Admin'
                        AND id_divisi='$id_divisi_pekerjaan'
                        AND status='Aktif'
                        LIMIT 1
                        "
                    );

                    if ($admin_query && mysqli_num_rows($admin_query) > 0) {

                        $admin = mysqli_fetch_assoc($admin_query);

                        $pesan_notif = "Karyawan mengirim bukti pekerjaan: " . $pekerjaan["nama_pekerjaan"];
                        $pesan_notif = mysqli_real_escape_string($koneksi, $pesan_notif);

                        mysqli_query(
                            $koneksi,
                            "
                            INSERT INTO notifikasi
                            (
                                id_user,
                                pesan,
                                status_baca,
                                tanggal
                            )
                            VALUES
                            (
                                '{$admin["id_user"]}',
                                '$pesan_notif',
                                'Belum Dibaca',
                                NOW()
                            )
                            "
                        );
                    }

                    /* ---------- KEMBALI KE DETAIL ---------- */
                    if (empty($pesan)) {
                        header("Location: detail_tugas.php?id=" . $id_pekerjaan);
                        exit;
                    }
                }
            }
        }
    }
}

/* =========================================================
   TIPE ORDER & TOMBOL KEMBALI
========================================================= */
$tipe_order = $pekerjaan["tipe_order"] ?? "-";
$url_kembali = $is_order
    ? "order_masuk.php?tab=saya"
    : "detail_tugas.php?id=" . (int) $id_pekerjaan;

/* Pilihan pekerja yang tercentang ulang kalau form gagal dikirim */
$pekerja_terpilih = array_map("intval", (array) ($_POST["pekerja"] ?? []));
$jumlah_post      = max(1, (int) ($_POST["jumlah_karyawan"] ?? 1));

/*
 * Nilai awal Waktu Selesai:
 * - form gagal dikirim  -> pakai isian tadi
 * - edit bukti lama     -> pakai waktu selesai yang tersimpan
 * - bukti baru          -> waktu sekarang (boleh diubah)
 */
$waktu_sekarang_input = date("Y-m-d\TH:i");

if (isset($_POST["waktu_selesai"])) {
    $nilai_waktu_selesai = $_POST["waktu_selesai"];
    $waktu_sudah_diisi   = true;
} elseif (!empty($bukti_lama["waktu_selesai"])) {
    $nilai_waktu_selesai = date("Y-m-d\TH:i", strtotime($bukti_lama["waktu_selesai"]));
    $waktu_sudah_diisi   = true;
} else {
    $nilai_waktu_selesai = $waktu_sekarang_input;
    $waktu_sudah_diisi   = false;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirim Bukti Pekerjaan - SIMO</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #f2f5f9;
            min-height: 100vh;
            color: #2c3644;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* MAIN */
        .main {
            margin-left: 240px;
            width: calc(100% - 240px);
            min-width: 0;
            min-height: 100vh;
        }

        .content {
            padding: 26px 32px 40px;
        }

        /* CARD */
        .card {
            background: #ffffff;
            border-radius: 13px;
            box-shadow: 0 2px 10px rgba(20,40,70,.04);
            padding: 26px;
            max-width: 850px;
        }

        .card h2 {
            margin: 0 0 7px;
            font-size: 22px;
            color: #1c2636;
        }

        .nama-pekerjaan {
            color: #7a8799;
            font-size: 13px;
            margin-bottom: 24px;
        }

        /* INFO ORDER */
        .info-order {
            width: 100%;
            background: #f5f7fa;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 22px;
        }

        .info-label {
            display: block;
            font-size: 11px;
            color: #8b96a5;
            margin-bottom: 6px;
        }

        .info-value {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #26364d;
        }

        .info-nomor {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #e1e6ed;
        }

        /* ALERT */
        .alert {
            background: #fff1f1;
            color: #b42318;
            border: 1px solid #f4cccc;
            border-radius: 8px;
            padding: 11px 13px;
            margin-bottom: 18px;
            font-size: 12px;
        }

        /* FOTO */
        .foto-section {
            margin-top: 20px;
        }

        .foto-title {
            font-size: 13px;
            font-weight: 600;
            color: #26364d;
            margin-bottom: 7px;
        }

        .foto-info {
            font-size: 11px;
            color: #8b96a5;
            margin-bottom: 12px;
        }

        .foto-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-top: 12px;
        }

        .foto-slot {
            position: relative;
            aspect-ratio: 1 / 1;
            border: 1px dashed #cfd6df;
            border-radius: 9px;
            background: #fafbfc;
            overflow: hidden;
        }

        .foto-slot.empty {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
            cursor: pointer;
        }

        .foto-slot.empty:hover {
            background: #fff7f7;
            border-color: #b51f2a;
        }

        .foto-slot img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .foto-plus {
            position: static !important;
            transform: none !important;
            margin: 0 !important;
            left: auto !important;
            top: auto !important;
        }

        .foto-remove {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 24px;
            height: 24px;
            border: 0;
            border-radius: 50%;
            background: rgba(181,31,42,.92);
            color: #fff;
            font-size: 15px;
            line-height: 24px;
            cursor: pointer;
            z-index: 2;
        }

        /* BUKTI SEBELUMNYA (read-only) */
        .prev-evidence {
            margin-top: 20px;
            padding: 14px;
            border: 1px solid #e1e6ed;
            border-radius: 10px;
            background: #f9fafc;
        }

        .prev-evidence-title {
            font-size: 13px;
            font-weight: 600;
            color: #26364d;
            margin-bottom: 4px;
        }

        .prev-evidence-info {
            font-size: 11px;
            color: #8b96a5;
            margin-bottom: 12px;
        }

        .prev-item {
            padding: 12px 0;
            border-top: 1px solid #e8ecf1;
        }

        .prev-item:first-of-type {
            border-top: 0;
            padding-top: 0;
        }

        .prev-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .prev-name { font-weight: 700; color: #26364d; }
        .prev-date { font-size: 10px; color: #8b96a5; }

        .prev-label {
            font-size: 10px;
            font-weight: 700;
            color: #8b96a5;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 5px;
        }

        .prev-photos {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 8px;
        }

        .prev-photos img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e1e6ed;
            display: block;
        }

        .prev-note {
            font-size: 12px;
            color: #526176;
            line-height: 1.55;
        }

        /* CAMERA */
        .camera-box {
            width: 100%;
            margin-top: 12px;
            background: #101827;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        #video {
            width: 100%;
            display: block;
            max-height: 500px;
            object-fit: cover;
        }

        #canvas {
            display: none;
        }

        .camera-placeholder {
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #aeb9c9;
            text-align: center;
            padding: 30px;
            font-size: 12px;
        }

        .camera-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 14px;
            background: #101827;
        }

        /* BUTTON */
        .btn {
            border: none;
            border-radius: 8px;
            padding: 10px 17px;
            font-size: 12px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-camera {
            background: #b51f2a;
            color: #ffffff;
        }

        .btn-camera:hover {
            background: #981923;
        }

        .btn-retake {
            background: #e9edf3;
            color: #34445a;
            display: none;
        }

        .btn-retake:hover {
            background: #dfe5ed;
        }

        /* FORM */
        .form-group {
            margin-top: 22px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 600;
            color: #26364d;
        }

        .required-info {
            font-size: 10px;
            color: #8b96a5;
            font-weight: 400;
            margin-left: 4px;
        }

        .form-group textarea {
            width: 100%;
            min-height: 110px;
            border: 1px solid #d8dee8;
            border-radius: 8px;
            padding: 12px;
            font-family: inherit;
            resize: vertical;
            outline: none;
            font-size: 12px;
            color: #34445a;
        }

        .form-group textarea:focus {
            border-color: #b51f2a;
        }

        .form-group .input-nomor {
            width: 100%;
            border: 1px solid #d8dee8;
            border-radius: 8px;
            padding: 12px;
            font-family: inherit;
            outline: none;
            font-size: 12px;
            color: #34445a;
        }

        .form-group .input-nomor:focus {
            border-color: #b51f2a;
        }

        /* WAKTU SELESAI */
        .form-group .input-waktu {
            width: 100%;
            max-width: 300px;
            border: 1px solid #d8dee8;
            border-radius: 8px;
            padding: 12px;
            font-family: inherit;
            outline: none;
            font-size: 13px;
            color: #34445a;
            background: #fff;
        }

        .form-group .input-waktu:focus {
            border-color: #b51f2a;
        }

        .waktu-hint {
            margin-top: 7px;
            font-size: 11px;
            color: #8b96a5;
        }

        .form-group textarea::placeholder {
            color: #9ba5b2;
        }

        /* KARYAWAN YANG IKUT MENGERJAKAN */
        .tim-box {
            background: #f5f7fa;
            border: 1px solid #e6eaf0;
            border-radius: 12px;
            padding: 16px;
        }

        .tim-sub {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #26364d;
            margin-bottom: 9px;
        }

        .tim-select {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
            max-width: 245px;
            padding: 14px 40px 14px 16px;
            border: 1px solid #d8dee8;
            border-radius: 12px;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2326364d' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 16px center;
            font-size: 14px;
            color: #34445a;
            outline: none;
            cursor: pointer;
        }

        .tim-select:focus {
            border-color: #b51f2a;
        }

        .tim-select:disabled {
            background-color: #f1f3f6;
            color: #667085;
            cursor: not-allowed;
        }

        .tim-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 14px;
        }

        .tim-row .tim-lbl {
            flex: 0 0 120px;
            margin: 0;
            font-size: 12px;
            font-weight: 600;
            color: #26364d;
        }

        .tim-row .tim-select {
            flex: 1;
            max-width: none;
        }

        .tim-hint {
            margin-top: 10px;
            font-size: 11px;
            color: #8b96a5;
        }

        /* SUBMIT */
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .btn-kembali {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dce3ec;
            background: #ffffff;
            color: #526176;
            padding: 10px 17px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-kembali:hover {
            background: #f5f7fa;
        }

        .btn-submit {
            flex: 1;
            background: #b51f2a;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 10px 17px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: #981923;
        }

        /* PILIHAN SUMBER FOTO */
        .sumber-overlay {
            position: fixed;
            inset: 0;
            background: rgba(16,24,39,.55);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 9999;
            padding: 16px;
        }

        .sumber-box {
            width: 100%;
            max-width: 360px;
            background: #ffffff;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,.25);
        }

        .sumber-title {
            font-size: 13px;
            font-weight: 600;
            color: #26364d;
            margin-bottom: 12px;
            text-align: center;
        }

        .sumber-btn {
            display: block;
            width: 100%;
            border: 1px solid #dce3ec;
            background: #f5f7fa;
            color: #26364d;
            border-radius: 9px;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 8px;
        }

        .sumber-btn:hover {
            border-color: #b51f2a;
            background: #fff7f7;
        }

        .sumber-batal {
            background: transparent;
            border: 0;
            color: #8b96a5;
            margin-bottom: 0;
        }

        @media (min-width: 601px) {
            .sumber-overlay {
                align-items: center;
            }
        }

        /* FOOTER */
        .footer-note {
            margin-top: 22px;
            font-size: 11px;
            color: #8995a6;
            line-height: 1.6;
        }

        .hidden {
            display: none !important;
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
                padding: 18px 14px 30px;
            }

            .card {
                padding: 20px;
            }

            .camera-actions {
                flex-wrap: wrap;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-kembali,
            .btn-submit {
                width: 100%;
            }

            .form-group .input-waktu {
                max-width: none;
            }
        }

        /* DARK MODE */
        body.dark-mode { background: #181b21; color: #e8ebf0; }
        body.dark-mode .card { background: #24272d; border: 1px solid #383e48; box-shadow: none; color: #e8ebf0; }
        body.dark-mode .card h2 { color: #f5f7fa; }
        body.dark-mode .nama-pekerjaan { color: #b8c0cf; }
        body.dark-mode .info-order { background: #1d2026; border: 1px solid #383e48; }
        body.dark-mode .info-label { color: #b8c0cf; }
        body.dark-mode .info-value { color: #f5f7fa; }
        body.dark-mode .info-nomor { border-top-color: #3a404a; }
        body.dark-mode .foto-section,
        body.dark-mode .foto-title { color: #f1f3f5; }
        body.dark-mode .foto-info,
        body.dark-mode .footer-note,
        body.dark-mode .waktu-hint,
        body.dark-mode .required-info { color: #b8c0cf; }
        body.dark-mode .form-group label { color: #d8dde6; }
        body.dark-mode input,
        body.dark-mode textarea,
        body.dark-mode select { background: #1d2026; color: #f1f3f5; border-color: #3a404a; }
        body.dark-mode .form-group .input-waktu { background: #1d2026; color: #f1f3f5; border-color: #3a404a; color-scheme: dark; }
        body.dark-mode input::placeholder,
        body.dark-mode textarea::placeholder { color: #858e9d; }
        body.dark-mode .tim-box { background: #1d2026; border-color: #383e48; }
        body.dark-mode .tim-sub,
        body.dark-mode .tim-row .tim-lbl { color: #d8dde6; }
        body.dark-mode .tim-select { background-color: #24272d; border-color: #3a404a; color: #f1f3f5; }
        body.dark-mode .tim-select:disabled { background-color: #1a1d22; color: #98a2b3; }
        body.dark-mode .tim-hint { color: #b8c0cf; }
        body.dark-mode .foto-slot { background: #1d2026; border-color: #414854; }
        body.dark-mode .foto-slot.empty { background: #20242b; }
        body.dark-mode .camera-box { background: #171a1f; border-color: #3a404a; }
        body.dark-mode .camera-actions { background: #171a1f; }
        body.dark-mode .camera-placeholder { color: #aeb6c4; }
        body.dark-mode .alert { background: #302a1f; border-color: #5b4b2b; color: #e6d6a8; }
        body.dark-mode .btn-kembali { background: #2b3038; border-color: #424955; color: #e9edf3; }
        body.dark-mode .btn-kembali:hover { background: #343a44; }
        body.dark-mode .sumber-box { background: #24272d; border: 1px solid #383e48; }
        body.dark-mode .sumber-title { color: #f1f3f5; }
        body.dark-mode .sumber-btn { background: #1d2026; border-color: #3a404a; color: #e9edf3; }
        body.dark-mode .sumber-btn:hover { background: #2b3038; border-color: #b51f2a; }
        body.dark-mode .sumber-batal { background: transparent; color: #b8c0cf; }
        body.dark-mode .prev-evidence { background: #1d2026; border-color: #383e48; }
        body.dark-mode .prev-evidence-title,
        body.dark-mode .prev-name { color: #f1f3f5; }
        body.dark-mode .prev-evidence-info,
        body.dark-mode .prev-date,
        body.dark-mode .prev-label { color: #b8c0cf; }
        body.dark-mode .prev-item { border-top-color: #383e48; }
        body.dark-mode .prev-photos img { border-color: #414854; }
        body.dark-mode .prev-note { color: #d8dde6; }
    </style>
</head>
<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<main class="main">

    <?php include "../includes/karyawan/topbar.php"; ?>

    <div class="content">
        <div class="card">

            <h2>Kirim Bukti Pekerjaan</h2>

            <div class="nama-pekerjaan">
                <?= htmlspecialchars($pekerjaan["nama_pekerjaan"]); ?>
            </div>

            <!-- INFO ORDER -->
            <div class="info-order">
                <span class="info-label">Tipe Order</span>
                <span class="info-value"><?= htmlspecialchars($tipe_order); ?></span>

                <?php if ($tipe_order == "Order Perbaikan" && !empty($pekerjaan["nomor_order"])): ?>
                    <div class="info-nomor">
                        <span class="info-label">Nomor Order</span>
                        <span class="info-value"><?= htmlspecialchars($pekerjaan["nomor_order"]); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($is_order && !empty($pekerjaan["lokasi"])): ?>
                    <div class="info-nomor">
                        <span class="info-label">Lokasi</span>
                        <span class="info-value"><?= htmlspecialchars($pekerjaan["lokasi"]); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ALERT -->
            <?php if (!empty($pesan)): ?>
                <div class="alert">
                    <?= htmlspecialchars($pesan); ?>
                </div>
            <?php endif; ?>

            <!-- BUKTI SEBELUMNYA (READ-ONLY) -->
            <?php if (!empty($bukti_sebelumnya)): ?>
                <div class="prev-evidence">
                    <div class="prev-evidence-title">Bukti Sebelumnya</div>
                    <div class="prev-evidence-info">
                        Bukti yang sudah dikirim sebelumnya tetap tersimpan dan tidak akan berubah.
                        Bukti yang Anda kirim di bawah ini tersimpan terpisah atas nama Anda.
                    </div>

                    <?php foreach ($bukti_sebelumnya as $b): ?>
                        <div class="prev-item">
                            <div class="prev-head">
                                <span class="prev-name"><?= htmlspecialchars($b["nama"]); ?></span>
                                <?php if (!empty($b["tanggal"])): ?>
                                    <span class="prev-date"><?= htmlspecialchars(date("d M Y, H:i", strtotime($b["tanggal"]))); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($b["foto_sebelum"])): ?>
                                <div class="prev-label">Foto Sebelum</div>
                                <div class="prev-photos">
                                    <?php foreach ($b["foto_sebelum"] as $f): ?>
                                        <img src="../uploads/bukti/<?= htmlspecialchars($f, ENT_QUOTES, "UTF-8"); ?>" alt="Foto sebelum">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($b["foto"])): ?>
                                <div class="prev-label">Foto Sesudah</div>
                                <div class="prev-photos">
                                    <?php foreach ($b["foto"] as $f): ?>
                                        <img src="../uploads/bukti/<?= htmlspecialchars($f, ENT_QUOTES, "UTF-8"); ?>" alt="Foto sesudah">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($b["catatan"] !== ""): ?>
                                <div class="prev-note"><?= nl2br(htmlspecialchars($b["catatan"])); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- FORM -->
            <form method="POST" id="formBukti">

                <!-- FOTO SEBELUM -->
                <div class="foto-section">
                    <div class="foto-title">
                        Foto Sebelum Pengerjaan
                        <span class="required-info">(Opsional, maksimal 5 foto)</span>
                    </div>

                    <div class="foto-info">
                        Foto kondisi awal sebelum pekerjaan dimulai. Klik tanda + untuk menambahkan foto.
                    </div>

                    <div class="foto-grid" id="fotoGridSebelum" data-nama="foto_sebelum_data[]">

                        <?php foreach ($foto_sebelum_lama_list as $foto_lama): ?>
                            <div class="foto-slot">
                                <img src="../uploads/bukti/<?= htmlspecialchars($foto_lama, ENT_QUOTES, "UTF-8"); ?>" alt="">
                                <button type="button" class="foto-remove" onclick="hapusFoto(this)">×</button>
                                <input type="hidden" name="foto_sebelum_lama[]" value="<?= htmlspecialchars($foto_lama, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                        <?php endforeach; ?>

                        <?php for ($i = count($foto_sebelum_lama_list); $i < 5; $i++): ?>
                            <div class="foto-slot empty" onclick="pilihSlotFoto(this)">
                                <span class="foto-plus">+</span>
                            </div>
                        <?php endfor; ?>

                    </div>
                </div>

                <!-- FOTO SESUDAH -->
                <div class="foto-section">
                    <div class="foto-title">
                        Foto Sesudah Pengerjaan
                        <span class="required-info">(Opsional, maksimal 5 foto)</span>
                    </div>

                    <div class="foto-info">
                        Foto hasil pekerjaan setelah selesai dikerjakan. Klik tanda + untuk menambahkan foto.
                    </div>

                    <div class="foto-grid" id="fotoGrid" data-nama="foto_data[]">

                        <?php foreach ($foto_lama_list as $foto_lama): ?>
                            <div class="foto-slot" data-old-photo="<?= htmlspecialchars($foto_lama, ENT_QUOTES, "UTF-8"); ?>">
                                <img src="../uploads/bukti/<?= htmlspecialchars($foto_lama, ENT_QUOTES, "UTF-8"); ?>" alt="">
                                <button type="button" class="foto-remove" onclick="hapusFoto(this)">×</button>
                                <input type="hidden" name="foto_lama[]" value="<?= htmlspecialchars($foto_lama, ENT_QUOTES, "UTF-8"); ?>">
                            </div>
                        <?php endforeach; ?>

                        <?php for ($i = count($foto_lama_list); $i < 5; $i++): ?>
                            <div class="foto-slot empty" onclick="pilihSlotFoto(this)">
                                <span class="foto-plus">+</span>
                            </div>
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
                            <div class="sumber-title" id="sumberJudul">Tambah Foto</div>
                            <button type="button" class="sumber-btn" id="pilihKamera">Ambil dari Kamera</button>
                            <button type="button" class="sumber-btn" id="pilihGaleri">Pilih dari Galeri</button>
                            <button type="button" class="sumber-btn sumber-batal" id="batalSumber">Batal</button>
                        </div>
                    </div>
                </div>

                <?php if ($is_order): ?>
                <div class="form-group">
                    <label for="nomorOrder">
                        Nomor Order
                        <span class="required-info">(Wajib)</span>
                    </label>

                    <input
                        type="text"
                        name="nomor_order"
                        id="nomorOrder"
                        class="input-nomor"
                        maxlength="50"
                        required
                        placeholder="Masukkan nomor order"
                        value="<?= htmlspecialchars($_POST["nomor_order"] ?? ($order["nomor_order"] ?? ""), ENT_QUOTES, "UTF-8"); ?>"
                    >
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="catatan">
                        Item Pekerjaan
                        <span class="required-info">(Wajib)</span>
                    </label>

                    <textarea
                        name="catatan"
                        id="catatan"
                        required
                        placeholder="Tulis pekerjaan yang dilakukan, mis. Ganti elemen solder dan baut"
                    ><?= htmlspecialchars($_POST["catatan"] ?? ($bukti_lama["catatan_karyawan"] ?? "")); ?></textarea>
                </div>

                <!-- WAKTU SELESAI -->
                <div class="form-group">
                    <label for="waktuSelesai">
                        Tanggal &amp; Jam Selesai
                        <span class="required-info">(Otomatis, bisa diubah)</span>
                    </label>

                    <input
                        type="datetime-local"
                        name="waktu_selesai"
                        id="waktuSelesai"
                        class="input-waktu"
                        required
                        value="<?= htmlspecialchars($nilai_waktu_selesai, ENT_QUOTES, "UTF-8"); ?>"
                        max="<?= htmlspecialchars($waktu_sekarang_input, ENT_QUOTES, "UTF-8"); ?>"
                    >

                    <div class="waktu-hint">Isi sesuai jam pekerjaan benar-benar selesai. Tidak boleh melebihi waktu sekarang.</div>
                </div>

                <?php if ($is_order): ?>
                <!-- KARYAWAN YANG IKUT MENGERJAKAN (khusus order) -->
                <div class="form-group">
                    <label>
                        Karyawan yang Ikut Mengerjakan
                        <span class="required-info">(Opsional)</span>
                    </label>

                    <div class="tim-box">
                        <label class="tim-sub" for="jumlahKaryawan">Jumlah Karyawan</label>
                        <select id="jumlahKaryawan" name="jumlah_karyawan" class="tim-select">
                            <?php for ($n = 1; $n <= count($daftar_karyawan) + 1; $n++): ?>
                                <option value="<?= $n ?>" <?= $n === $jumlah_post ? "selected" : "" ?>><?= $n ?> Karyawan</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div id="barisKaryawan"></div>

                    <div class="tim-hint">Pilih karyawan yang akan mengerjakan pekerjaan ini. Anda otomatis menjadi Karyawan 1.</div>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <a href="<?= htmlspecialchars($url_kembali, ENT_QUOTES, "UTF-8"); ?>" class="btn-kembali">
                        Kembali
                    </a>

                    <button type="submit" name="kirim_bukti" class="btn-submit">
                        <?= $status == "Menunggu Konfirmasi" ? "Simpan Perubahan" : "Kirim Bukti"; ?>
                    </button>
                </div>

            </form>

            <div class="footer-note">
                Foto sebelum dan sesudah bersifat opsional.
                <?php if ($is_order): ?>
                    Item pekerjaan dan jam selesai wajib diisi. Setelah bukti dikirim, order menunggu konfirmasi pemesan atau Admin Divisi sebelum ditandai selesai.
                <?php else: ?>
                    Item pekerjaan dan jam selesai wajib diisi sebelum
                    mengirim bukti kepada Admin Divisi.
                <?php endif; ?>
            </div>

        </div>
    </div>
</main>

<script>
const video = document.getElementById("video");
const canvas = document.getElementById("canvas");
const openCamera = document.getElementById("openCamera");
const takePhoto = document.getElementById("takePhoto");
const retake = document.getElementById("retake");
const closeCamera = document.getElementById("closeCamera");
const placeholder = document.getElementById("placeholder");
const cameraBox = document.getElementById("cameraBox");
const formBukti = document.getElementById("formBukti");

let stream = null;
let slotAktif = null;   // slot kosong yang sedang diisi (bisa di grid sebelum / sesudah)

/* jumlah foto yang sudah terisi di satu grid */
function jumlahFoto(grid) {
    return grid.querySelectorAll(".foto-slot:not(.empty)").length;
}

const sumberFoto = document.getElementById("sumberFoto");
const inputGaleri = document.getElementById("inputGaleri");

function pilihSlotFoto(slot) {
    const grid = slot.parentElement;

    if (jumlahFoto(grid) >= 5) return;

    slotAktif = slot;

    document.getElementById("sumberJudul").textContent =
        grid.id === "fotoGridSebelum" ? "Tambah Foto Sebelum" : "Tambah Foto Sesudah";

    sumberFoto.classList.remove("hidden");
}

document.getElementById("batalSumber").addEventListener("click", function () {
    sumberFoto.classList.add("hidden");
    slotAktif = null;
});

sumberFoto.addEventListener("click", function (e) {
    if (e.target === sumberFoto) {
        sumberFoto.classList.add("hidden");
        slotAktif = null;
    }
});

document.getElementById("pilihKamera").addEventListener("click", function () {
    sumberFoto.classList.add("hidden");
    tampilkanKamera();
});

document.getElementById("pilihGaleri").addEventListener("click", function () {
    sumberFoto.classList.add("hidden");
    inputGaleri.value = "";
    inputGaleri.click();
});

/* Isi slot dengan foto; nama input mengikuti grid (sebelum / sesudah) */
function isiSlotFoto(slot, imageData) {
    const grid = slot.parentElement;

    slot.classList.remove("empty");
    slot.onclick = null;
    slot.innerHTML =
        '<img src="' + imageData + '" alt="">' +
        '<button type="button" class="foto-remove" onclick="hapusFoto(this)">×</button>';

    const input = document.createElement("input");
    input.type = "hidden";
    input.name = grid.dataset.nama;
    input.value = imageData;
    slot.appendChild(input);
}

/* Perkecil gambar dari galeri agar ukuran kiriman tidak terlalu besar */
function prosesFileGaleri(file) {
    return new Promise(function (resolve, reject) {
        const reader = new FileReader();

        reader.onerror = reject;
        reader.onload = function () {
            const img = new Image();

            img.onerror = reject;
            img.onload = function () {
                const maxSisi = 1600;
                let w = img.width;
                let h = img.height;

                if (Math.max(w, h) > maxSisi) {
                    const skala = maxSisi / Math.max(w, h);
                    w = Math.round(w * skala);
                    h = Math.round(h * skala);
                }

                canvas.width = w;
                canvas.height = h;
                canvas.getContext("2d").drawImage(img, 0, 0, w, h);
                resolve(canvas.toDataURL("image/jpeg", 0.85));
            };

            img.src = reader.result;
        };

        reader.readAsDataURL(file);
    });
}

inputGaleri.addEventListener("change", async function () {
    const files = Array.from(inputGaleri.files || []);
    const grid = slotAktif ? slotAktif.parentElement : null;

    if (!files.length || !grid) {
        slotAktif = null;
        return;
    }

    let sisa = 5 - jumlahFoto(grid);

    if (files.length > sisa) {
        alert("Maksimal 5 foto. Hanya " + sisa + " foto pertama yang akan ditambahkan.");
    }

    for (const file of files.slice(0, sisa)) {
        if (!file.type.startsWith("image/")) continue;

        const slot = grid.querySelector(".foto-slot.empty");
        if (!slot) break;

        try {
            const imageData = await prosesFileGaleri(file);
            isiSlotFoto(slot, imageData);
        } catch (err) {
            console.log(err);
            alert("Foto " + file.name + " gagal diproses.");
        }
    }

    slotAktif = null;
    inputGaleri.value = "";
});

function tampilkanKamera() {
    cameraBox.classList.remove("hidden");
    placeholder.classList.remove("hidden");
    video.classList.add("hidden");
    openCamera.classList.remove("hidden");
    takePhoto.classList.add("hidden");
    retake.style.display = "none";

    cameraBox.scrollIntoView({ behavior: "smooth", block: "center" });
}

async function bukaKamera() {
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert("Browser tidak mendukung akses kamera.");
            return;
        }

        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: "environment" } },
            audio: false
        });

        video.srcObject = stream;

        placeholder.classList.add("hidden");
        video.classList.remove("hidden");
        openCamera.classList.add("hidden");
        takePhoto.classList.remove("hidden");

    } catch (error) {
        console.log(error);
        alert("Kamera tidak dapat dibuka. Silakan izinkan akses kamera pada browser.");
    }
}

openCamera.addEventListener("click", bukaKamera);

takePhoto.addEventListener("click", function () {

    if (!slotAktif || !video.videoWidth || !video.videoHeight) {
        alert("Kamera belum siap.");
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext("2d").drawImage(video, 0, 0, canvas.width, canvas.height);

    const imageData = canvas.toDataURL("image/jpeg", 0.9);

    isiSlotFoto(slotAktif, imageData);

    matikanKamera();
    cameraBox.classList.add("hidden");
    slotAktif = null;
});

retake.addEventListener("click", async function () {
    matikanKamera();
    await bukaKamera();
});

closeCamera.addEventListener("click", function () {
    matikanKamera();
    cameraBox.classList.add("hidden");
    slotAktif = null;
});

/* hapus foto (lama maupun baru) lalu kembalikan slot kosong di grid yang sama */
function hapusFoto(button) {
    const slot = button.closest(".foto-slot");
    const grid = slot.parentElement;

    slot.remove();
    tambahSlotKosong(grid);
}

function tambahSlotKosong(grid) {
    if (grid.querySelectorAll(".foto-slot").length >= 5) return;

    const slot = document.createElement("div");
    slot.className = "foto-slot empty";
    slot.onclick = function () { pilihSlotFoto(this); };
    slot.innerHTML = '<span class="foto-plus">+</span>';
    grid.appendChild(slot);
}

function matikanKamera() {
    if (stream) {
        stream.getTracks().forEach(function (track) { track.stop(); });
        stream = null;
    }

    if (video) video.srcObject = null;
}

/* ---------- TANGGAL & JAM SELESAI OTOMATIS ---------- */
const waktuSelesai = document.getElementById("waktuSelesai");
let waktuDiubah = <?= $waktu_sudah_diisi ? "true" : "false" ?>;

function dua(n) { return (n < 10 ? "0" : "") + n; }

function formatWaktu(d) {
    return d.getFullYear() + "-" + dua(d.getMonth() + 1) + "-" + dua(d.getDate()) +
           "T" + dua(d.getHours()) + ":" + dua(d.getMinutes());
}

function perbaruiWaktu() {
    const sekarang = formatWaktu(new Date());
    waktuSelesai.max = sekarang;
    if (!waktuDiubah) { waktuSelesai.value = sekarang; }
}

/* kalau diubah manual (atau sedang edit bukti lama), berhenti mengikuti jam otomatis */
waktuSelesai.addEventListener("input", function () { waktuDiubah = true; });
waktuSelesai.addEventListener("change", function () { waktuDiubah = true; });

perbaruiWaktu();
setInterval(perbaruiWaktu, 15000);

formBukti.addEventListener("submit", function (event) {
    const catatan = document.getElementById("catatan").value.trim();

    const inputNomor = document.getElementById("nomorOrder");

    if (inputNomor && !inputNomor.value.trim()) {
        event.preventDefault();
        alert("Nomor order wajib diisi.");
        inputNomor.focus();
        return;
    }

    if (!catatan) {
        event.preventDefault();
        alert("Item pekerjaan wajib diisi.");
        document.getElementById("catatan").focus();
        return;
    }

    /* kalau tidak diubah, pakai jam saat tombol ditekan */
    if (!waktuDiubah) { waktuSelesai.value = formatWaktu(new Date()); }

    if (!waktuSelesai.value) {
        event.preventDefault();
        alert("Tanggal dan jam selesai wajib diisi.");
        waktuSelesai.focus();
        return;
    }

    matikanKamera();
});

<?php if ($is_order): ?>
/* ---------- KARYAWAN YANG IKUT MENGERJAKAN ---------- */
(function () {
    const daftar   = <?= json_encode($daftar_karyawan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const terpilih = <?= json_encode(array_map("strval", $pekerja_terpilih)) ?>;
    const namaPic  = <?= json_encode($nama_karyawan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const jumlahEl = document.getElementById("jumlahKaryawan");
    const wadah    = document.getElementById("barisKaryawan");

    function buatBaris(label, select) {
        const row = document.createElement("div");
        row.className = "tim-row";

        const lbl = document.createElement("label");
        lbl.className = "tim-lbl";
        lbl.textContent = label;

        row.appendChild(lbl);
        row.appendChild(select);
        return row;
    }

    function cegahDobel() {
        const pilihan = Array.from(wadah.querySelectorAll("select.pilih-pekerja"));
        const dipakai = pilihan.map(function (s) { return s.value; }).filter(Boolean);

        pilihan.forEach(function (sel) {
            Array.from(sel.options).forEach(function (opt) {
                opt.disabled = opt.value !== "" && opt.value !== sel.value && dipakai.indexOf(opt.value) !== -1;
            });
        });
    }

    function bangun() {
        const n = parseInt(jumlahEl.value, 10) || 1;

        /* pertahankan pilihan yang sudah ada saat jumlah diubah */
        const lama = Array.from(wadah.querySelectorAll("select.pilih-pekerja")).map(function (s) { return s.value; });
        const pakai = lama.length ? lama : terpilih;

        wadah.innerHTML = "";

        /* Karyawan 1 = pengirim bukti (otomatis, terkunci) */
        const pic = document.createElement("select");
        pic.className = "tim-select";
        pic.disabled = true;
        pic.innerHTML = "<option></option>";
        pic.options[0].textContent = namaPic + " (Anda)";
        wadah.appendChild(buatBaris("Karyawan 1", pic));

        for (let i = 2; i <= n; i++) {
            const sel = document.createElement("select");
            sel.className = "tim-select pilih-pekerja";
            sel.name = "pekerja[]";
            sel.required = true;

            const kosong = document.createElement("option");
            kosong.value = "";
            kosong.textContent = "Pilih nama karyawan";
            sel.appendChild(kosong);

            daftar.forEach(function (k) {
                const opt = document.createElement("option");
                opt.value = String(k.id_user);
                opt.textContent = k.nama;
                sel.appendChild(opt);
            });

            sel.value = pakai[i - 2] || "";
            sel.addEventListener("change", cegahDobel);

            wadah.appendChild(buatBaris("Karyawan " + i, sel));
        }

        cegahDobel();
    }

    jumlahEl.addEventListener("change", bangun);
    bangun();
})();
<?php endif; ?>

window.addEventListener("beforeunload", function () { matikanKamera(); });
</script>

</body>
</html>
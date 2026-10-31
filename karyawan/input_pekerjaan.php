<?php
include "../config/session.php";

// Hanya role Karyawan yang boleh masuk
if (!isset($_SESSION["id_user"]) || $_SESSION["role"] != "Karyawan") {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

/* true = pesan error database asli tampil di layar (untuk mencari penyebab). Ubah ke false setelah beres. */
$mode_debug = false;

$id_user = (int) $_SESSION["id_user"];

/* =========================================================
   ALUR STATUS PEKERJAAN
   Belum Dikerjakan -> Sedang Dikerjakan -> Menunggu Konfirmasi
   -> (Selesai) atau (Perlu Perbaikan -> Sedang Dikerjakan -> ...)
========================================================= */
$daftar_status = ["Belum Dikerjakan", "Sedang Dikerjakan", "Menunggu Konfirmasi", "Perlu Perbaikan", "Selesai"];

/* =========================================================
   PASTIKAN KOLOM PENDUKUNG ADA
========================================================= */
function pastikan_kolom($koneksi, $tabel, $kolom, $definisi)
{
    try {
        $k = $koneksi->real_escape_string($kolom);
        $cek = $koneksi->query("SHOW COLUMNS FROM `$tabel` LIKE '$k'");
        if ($cek && $cek->num_rows === 0) {
            $koneksi->query("ALTER TABLE `$tabel` ADD COLUMN `$kolom` $definisi");
        }
    } catch (Throwable $e) {
        error_log("Gagal menambah kolom $tabel.$kolom: " . $e->getMessage());
    }
}

/* Jika kolom status bertipe ENUM, pastikan semua status alur tersedia */
function pastikan_status($koneksi, $daftar)
{
    try {
        $cek = $koneksi->query("SHOW COLUMNS FROM `pekerjaan` LIKE 'status'");
        $c = $cek ? $cek->fetch_assoc() : null;

        if (!$c || stripos($c["Type"], "enum(") !== 0) {
            return;
        }

        preg_match_all("/'((?:[^']|'')*)'/", $c["Type"], $m);
        $ada = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
        $baru = array_values(array_unique(array_merge($ada, $daftar)));

        if (count($baru) === count($ada)) {
            return;
        }

        $literal = implode(",", array_map(function ($v) use ($koneksi) {
            return "'" . $koneksi->real_escape_string($v) . "'";
        }, $baru));

        $null = $c["Null"] === "YES" ? "NULL" : "NOT NULL";
        $default = "";
        if ($c["Default"] !== null) {
            $default = " DEFAULT '" . $koneksi->real_escape_string($c["Default"]) . "'";
        } elseif ($c["Null"] === "YES") {
            $default = " DEFAULT NULL";
        }

        $koneksi->query("ALTER TABLE `pekerjaan` MODIFY `status` ENUM($literal) $null$default");
    } catch (Throwable $e) {
        error_log("Gagal memperbarui daftar status: " . $e->getMessage());
    }
}

pastikan_kolom($koneksi, "pekerjaan", "uraian_kerusakan", "TEXT NULL");
pastikan_kolom($koneksi, "pekerjaan", "nama_pelapor", "VARCHAR(100) NULL");
pastikan_kolom($koneksi, "pekerjaan", "departemen_asal", "VARCHAR(100) NULL");
pastikan_kolom($koneksi, "pekerjaan", "foto", "VARCHAR(255) NULL");
pastikan_kolom($koneksi, "pekerjaan", "nama_peralatan", "VARCHAR(150) NULL");
pastikan_kolom($koneksi, "pekerjaan", "catatan_perbaikan", "TEXT NULL");
pastikan_status($koneksi, $daftar_status);

try {
    $cek = $koneksi->query("SHOW COLUMNS FROM `pekerjaan` LIKE 'jenis_pekerjaan'");
    $c = $cek ? $cek->fetch_assoc() : null;
    if ($c && stripos($c["Type"], "varchar(255)") === false) {
        $koneksi->query("ALTER TABLE `pekerjaan` MODIFY `jenis_pekerjaan` VARCHAR(255) NULL");
    }
} catch (Throwable $e) {
    error_log("Gagal melebarkan jenis_pekerjaan: " . $e->getMessage());
}

function e($t) { return htmlspecialchars((string) $t, ENT_QUOTES, "UTF-8"); }

/* Status kosong / Peralihan dianggap "Belum Dikerjakan" */
function teks_status($status)
{
    $status = trim((string) $status);
    if ($status === "" || $status === "Peralihan") {
        return "Belum Dikerjakan";
    }
    return $status;
}

/* Daftar id karyawan yang ditugaskan pada sebuah pekerjaan */
function ambil_peserta($koneksi, $id_pkj, $id_legacy = 0)
{
    $hasil = [];

    $st = $koneksi->prepare(
        "SELECT DISTINCT id_user FROM pekerjaan_karyawan
         WHERE id_pekerjaan = ? AND status_penugasan IN ('Aktif', 'Peralihan')"
    );
    $st->bind_param("i", $id_pkj);
    $st->execute();
    $rs = $st->get_result();
    while ($r = $rs->fetch_assoc()) {
        $hasil[] = (int) $r["id_user"];
    }

    if (!$hasil && (int) $id_legacy > 0) {
        $hasil[] = (int) $id_legacy;
    }

    return $hasil;
}

/* Kirim notifikasi ke beberapa user (gagal kirim tidak menggagalkan proses utama) */
function kirim_notifikasi($koneksi, $daftar_id, $pesan, $kecuali = 0)
{
    try {
        $stn = $koneksi->prepare(
            "INSERT INTO notifikasi (id_user, pesan, status_baca, tanggal) VALUES (?, ?, 'Belum Dibaca', NOW())"
        );
        foreach ($daftar_id as $id_tujuan) {
            $id_tujuan = (int) $id_tujuan;
            if ($id_tujuan <= 0 || $id_tujuan === (int) $kecuali) {
                continue;
            }
            $stn->bind_param("is", $id_tujuan, $pesan);
            $stn->execute();
        }
    } catch (Throwable $e) {
        error_log("Notifikasi gagal: " . $e->getMessage());
    }
}

/* =========================================================
   DATA KARYAWAN & DIVISI
========================================================= */
$st = $koneksi->prepare("SELECT id_divisi, nama FROM users WHERE id_user = ? AND role = 'Karyawan' AND status = 'Aktif' LIMIT 1");
$st->bind_param("i", $id_user);
$st->execute();
$me = $st->get_result()->fetch_assoc();

if (!$me) {
    header("Location: ../index.php");
    exit;
}
$id_divisi = (int) $me["id_divisi"];
$nama_saya = $me["nama"];

$nama_divisi = "-";
$st = $koneksi->prepare("SELECT nama_divisi FROM divisi WHERE id_divisi = ? LIMIT 1");
$st->bind_param("i", $id_divisi);
$st->execute();
$rd = $st->get_result()->fetch_assoc();
if ($rd) {
    $nama_divisi = $rd["nama_divisi"];
}

/* Semua karyawan aktif satu divisi (termasuk diri sendiri) */
$daftar_karyawan = [];
$st = $koneksi->prepare(
    "SELECT id_user, nama FROM users
     WHERE id_divisi = ? AND role = 'Karyawan' AND status = 'Aktif'
     ORDER BY nama ASC"
);
$st->bind_param("i", $id_divisi);
$st->execute();
$hasil_karyawan = $st->get_result();
while ($r = $hasil_karyawan->fetch_assoc()) {
    $daftar_karyawan[(int) $r["id_user"]] = $r["nama"];
}

$karyawan_js = [];
foreach ($daftar_karyawan as $id_k => $nama_k) {
    $karyawan_js[] = ["id_user" => $id_k, "nama" => $nama_k . ($id_k === $id_user ? " (Anda)" : "")];
}

/* Saran departemen */
$data_departemen = [];
$qd = $koneksi->query(
    "SELECT DISTINCT TRIM(departemen) AS departemen FROM users
     WHERE departemen IS NOT NULL AND TRIM(departemen) <> ''
     ORDER BY departemen ASC"
);
if ($qd) {
    while ($d = $qd->fetch_assoc()) {
        $data_departemen[] = $d["departemen"];
    }
}

$maks_ukuran = 5 * 1024 * 1024;
$folder_foto = __DIR__ . "/../uploads/pekerjaan/";
$daftar_jenis = ["Mesin", "Infrastruktur", "Peralatan", "Armada Nopol"];

/* =========================================================
   KONFIGURASI PER TIPE ORDER (mengikuti halaman admin)
========================================================= */
$konfig = [
    "order-perbaikan" => [
        "tipe"            => "Order Perbaikan",
        "pakai_nomor"     => true,   /* nomor order wajib */
        "pakai_pelapor"   => true,
        "pakai_dept"      => true,
        "peralatan_wajib" => false,
        "label_uraian"    => "Uraian Kerusakan",
        "ph_uraian"       => "Jelaskan kerusakan yang terjadi",
        "tabel_lengkap"   => true,   /* tabel: No. Order, Peralatan, Lokasi */
    ],
    "non-order" => [
        "tipe"            => "Non Order",
        "pakai_nomor"     => false,
        "pakai_pelapor"   => true,
        "pakai_dept"      => true,
        "peralatan_wajib" => false,
        "label_uraian"    => "Uraian Pekerjaan",
        "ph_uraian"       => "Jelaskan pekerjaan yang akan dilakukan",
        "tabel_lengkap"   => false,
    ],
    "maintenance" => [
        "tipe"            => "Maintenance",
        "pakai_nomor"     => false,
        "pakai_pelapor"   => false,
        "pakai_dept"      => false,
        "peralatan_wajib" => true,
        "label_uraian"    => "Uraian Maintenance",
        "ph_uraian"       => "Jelaskan maintenance yang akan dilakukan",
        "tabel_lengkap"   => false,
    ],
];

$tab_aktif = $_GET["tab"] ?? "order-perbaikan";
if (!isset($konfig[$tab_aktif])) {
    $tab_aktif = "order-perbaikan";
}
$cfg = $konfig[$tab_aktif];
$tipe_aktif = $cfg["tipe"];
$pakai_nomor = $cfg["pakai_nomor"];
$pakai_pelapor = $cfg["pakai_pelapor"];
$pakai_dept = $cfg["pakai_dept"];
$tabel_lengkap = $cfg["tabel_lengkap"];

$view_pekerjaan = trim($_GET["view"] ?? "individu");
if (!in_array($view_pekerjaan, ["individu", "tim"], true)) {
    $view_pekerjaan = "individu";
}

$file_halaman = "input_pekerjaan.php";
$url_aksi = $file_halaman . "?tab=" . $tab_aktif . "&view=" . $view_pekerjaan;

$error = "";
$old = [
    "nomor_order" => "", "nama_pekerjaan" => "", "nama_pelapor" => "",
    "departemen_asal" => "", "lokasi" => "", "nama_peralatan" => "", "uraian_kerusakan" => "",
    "tanggal_mulai" => "", "jam_mulai" => "", "target_selesai" => "", "jam_selesai" => "",
    "jumlah_karyawan" => "1",
];
$id_terpilih = [];
$jenis_old = "";

/* =========================================================
   HAPUS PEKERJAAN (hanya "Belum Dikerjakan" di divisi ini)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["aksi"] ?? "") === "hapus") {

    $id_pkj = (int) ($_POST["id_pekerjaan"] ?? 0);

    $st = $koneksi->prepare("SELECT foto, status, tipe_order FROM pekerjaan WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ? LIMIT 1");
    $st->bind_param("iii", $id_pkj, $id_divisi, $id_user);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    if (!$row) {
        $_SESSION["flash_tabel_error"] = "Pekerjaan tidak ditemukan.";
    } elseif (teks_status($row["status"]) !== "Belum Dikerjakan") {
        $_SESSION["flash_tabel_error"] = "Pekerjaan yang sudah berjalan tidak bisa dihapus.";
    } else {
        try {
            $koneksi->begin_transaction();

            foreach (["bukti_pekerjaan", "riwayat_pekerjaan", "pekerjaan_karyawan"] as $tbl) {
                $st = $koneksi->prepare("DELETE FROM `$tbl` WHERE id_pekerjaan = ?");
                $st->bind_param("i", $id_pkj);
                $st->execute();
            }

            $st = $koneksi->prepare("DELETE FROM pekerjaan WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ?");
            $st->bind_param("iii", $id_pkj, $id_divisi, $id_user);
            $st->execute();

            $koneksi->commit();

            if (!empty($row["foto"])) {
                foreach (array_filter(array_map("trim", explode(",", $row["foto"]))) as $nf) {
                    @unlink($folder_foto . basename($nf));
                }
            }
            $_SESSION["flash_tabel_ok"] = "Pekerjaan berhasil dihapus.";
        } catch (Throwable $e) {
            $koneksi->rollback();
            error_log("Hapus pekerjaan gagal: " . $e->getMessage());
            $_SESSION["flash_tabel_error"] = "Pekerjaan gagal dihapus."
                . ($mode_debug ? " (" . $e->getMessage() . ")" : "");
        }
    }

    header("Location: $url_aksi");
    exit;
}

/* =========================================================
   KONFIRMASI HASIL PEKERJAAN
   Hanya pembuat pekerjaan, hanya saat status "Menunggu Konfirmasi":
   - selesai    -> status "Selesai"
   - perbaikan  -> status "Perlu Perbaikan" (catatan wajib)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["aksi"] ?? "") === "konfirmasi") {

    $id_pkj = (int) ($_POST["id_pekerjaan"] ?? 0);
    $keputusan = trim($_POST["keputusan"] ?? "");
    $catatan = trim($_POST["catatan"] ?? "");

    $st = $koneksi->prepare("SELECT status, nama_pekerjaan, id_karyawan FROM pekerjaan WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ? LIMIT 1");
    $st->bind_param("iii", $id_pkj, $id_divisi, $id_user);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    $err = "";
    if (!$row) {
        $err = "Pekerjaan tidak ditemukan.";
    } elseif (teks_status($row["status"]) !== "Menunggu Konfirmasi") {
        $err = "Pekerjaan ini tidak sedang menunggu konfirmasi.";
    } elseif (!in_array($keputusan, ["selesai", "perbaikan"], true)) {
        $err = "Pilihan konfirmasi tidak valid.";
    } elseif ($keputusan === "perbaikan" && $catatan === "") {
        $err = "Catatan perbaikan wajib diisi.";
    } elseif (mb_strlen($catatan) > 500) {
        $err = "Catatan maksimal 500 karakter.";
    }

    if ($err === "") {
        try {
            if ($keputusan === "selesai") {
                $st = $koneksi->prepare("UPDATE pekerjaan SET status = 'Selesai', catatan_perbaikan = NULL WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ? AND status = 'Menunggu Konfirmasi'");
                $st->bind_param("iii", $id_pkj, $id_divisi, $id_user);
            } else {
                $st = $koneksi->prepare("UPDATE pekerjaan SET status = 'Perlu Perbaikan', catatan_perbaikan = ? WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ? AND status = 'Menunggu Konfirmasi'");
                $st->bind_param("siii", $catatan, $id_pkj, $id_divisi, $id_user);
            }
            $st->execute();

            if ($st->affected_rows > 0) {
                $peserta = ambil_peserta($koneksi, $id_pkj, (int) $row["id_karyawan"]);

                if ($keputusan === "selesai") {
                    $pesan_notif = "Pekerjaan \"" . $row["nama_pekerjaan"] . "\" dinyatakan selesai oleh " . $nama_saya . ".";
                    $_SESSION["flash_tabel_ok"] = "Pekerjaan dikonfirmasi selesai.";
                } else {
                    $pesan_notif = "Pekerjaan \"" . $row["nama_pekerjaan"] . "\" perlu diperbaiki. Catatan dari " . $nama_saya . ": " . $catatan;
                    $_SESSION["flash_tabel_ok"] = "Pekerjaan dikembalikan untuk diperbaiki.";
                }

                kirim_notifikasi($koneksi, $peserta, $pesan_notif, $id_user);
            } else {
                $_SESSION["flash_tabel_error"] = "Status pekerjaan sudah berubah. Muat ulang halaman.";
            }
        } catch (Throwable $e) {
            error_log("Konfirmasi pekerjaan gagal: " . $e->getMessage());
            $_SESSION["flash_tabel_error"] = "Konfirmasi gagal disimpan."
                . ($mode_debug ? " (" . $e->getMessage() . ")" : "");
        }
    } else {
        $_SESSION["flash_tabel_error"] = $err;
    }

    header("Location: $url_aksi");
    exit;
}

/* =========================================================
   EDIT PEKERJAAN (hanya "Belum Dikerjakan" di divisi ini)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["aksi"] ?? "") === "edit") {

    $id_pkj = (int) ($_POST["id_pekerjaan"] ?? 0);

    $f = [];
    foreach ([
        "nomor_order", "nama_pekerjaan", "nama_pelapor", "departemen_asal", "jenis_pekerjaan", "nama_peralatan",
        "lokasi", "uraian_kerusakan", "tanggal_mulai", "jam_mulai", "target_selesai", "jam_selesai",
    ] as $k) {
        $f[$k] = trim($_POST[$k] ?? "");
    }

    /* karyawan yang dituju */
    $jumlah_e = (int) ($_POST["jumlah_karyawan"] ?? 0);
    $id_e = [];
    $kiriman = $_POST["id_karyawan"] ?? [];
    if (!is_array($kiriman)) {
        $kiriman = [];
    }
    foreach ($kiriman as $rid) {
        $rid = (int) $rid;
        if ($rid > 0) {
            $id_e[] = $rid;
        }
    }
    $id_e = array_values(array_unique($id_e));

    $m = DateTime::createFromFormat('Y-m-d H:i', $f["tanggal_mulai"] . " " . $f["jam_mulai"]);
    $t = DateTime::createFromFormat('Y-m-d H:i', $f["target_selesai"] . " " . $f["jam_selesai"]);

    $st = $koneksi->prepare("SELECT status, tipe_order, id_karyawan, nama_pekerjaan FROM pekerjaan WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ? LIMIT 1");
    $st->bind_param("iii", $id_pkj, $id_divisi, $id_user);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    /* aturan form mengikuti tipe order pekerjaan itu sendiri */
    $cfg_edit = $cfg;
    $tab_edit = $tab_aktif;
    if ($row) {
        $kunci = array_search($row["tipe_order"], array_column($konfig, "tipe"), true);
        if ($kunci !== false) {
            $tab_edit = array_keys($konfig)[$kunci];
            $cfg_edit = $konfig[$tab_edit];
        }
    }

    $err = "";
    if (!$row) {
        $err = "Pekerjaan tidak ditemukan.";
    } elseif (teks_status($row["status"]) !== "Belum Dikerjakan") {
        $err = "Pekerjaan yang sudah berjalan tidak bisa diedit.";
    } elseif ($f["nama_pekerjaan"] === "" || mb_strlen($f["nama_pekerjaan"]) > 150) {
        $err = "Nama pekerjaan wajib diisi (maksimal 150 karakter).";
    } elseif ($cfg_edit["pakai_nomor"] && $f["nomor_order"] === "") {
        $err = "Nomor order wajib diisi.";
    } elseif (mb_strlen($f["nomor_order"]) > 50) {
        $err = "Nomor order maksimal 50 karakter.";
    } elseif (mb_strlen($f["nama_pelapor"]) > 100 || mb_strlen($f["departemen_asal"]) > 100) {
        $err = "Nama pelapor / departemen maksimal 100 karakter.";
    } elseif (!in_array($f["jenis_pekerjaan"], $daftar_jenis, true)) {
        $err = "Pilih jenis pekerjaan.";
    } elseif ($cfg_edit["peralatan_wajib"] && $f["nama_peralatan"] === "") {
        $err = "Nama peralatan wajib diisi.";
    } elseif (mb_strlen($f["nama_peralatan"]) > 150) {
        $err = "Nama peralatan maksimal 150 karakter.";
    } elseif ($f["lokasi"] === "") {
        $err = "Lokasi wajib diisi.";
    } elseif ($f["uraian_kerusakan"] === "") {
        $err = $cfg_edit["label_uraian"] . " wajib diisi.";
    } elseif ($jumlah_e < 1 || $jumlah_e > 10 || count($id_e) != $jumlah_e) {
        $err = "Silakan pilih nama karyawan sesuai jumlah yang ditentukan.";
    } elseif (array_diff($id_e, array_keys($daftar_karyawan))) {
        $err = "Terdapat karyawan yang tidak valid.";
    } elseif (!$m || !$t) {
        $err = "Tanggal dan jam pekerjaan tidak valid.";
    } elseif ($t <= $m) {
        $err = "Target selesai harus setelah tanggal dan jam mulai.";
    }

    if ($err === "") {
        $tm = $m->format("Y-m-d H:i:s");
        $tt = $t->format("Y-m-d H:i:s");
        $id_utama_e = (int) $id_e[0];

        $set = ["nama_pekerjaan = ?", "jenis_pekerjaan = ?", "nama_peralatan = ?", "lokasi = ?", "uraian_kerusakan = ?", "tanggal_mulai = ?", "target_selesai = ?", "id_karyawan = ?"];
        $tipe_bind = "sssssssi";
        $nilai = [$f["nama_pekerjaan"], $f["jenis_pekerjaan"], $f["nama_peralatan"], $f["lokasi"], $f["uraian_kerusakan"], $tm, $tt, $id_utama_e];

        if ($cfg_edit["pakai_nomor"]) {
            $set[] = "nomor_order = ?"; $tipe_bind .= "s"; $nilai[] = $f["nomor_order"];
        }
        if ($cfg_edit["pakai_pelapor"]) {
            $set[] = "nama_pelapor = ?"; $tipe_bind .= "s"; $nilai[] = $f["nama_pelapor"];
        }
        if ($cfg_edit["pakai_dept"]) {
            $set[] = "departemen_asal = ?"; $tipe_bind .= "s"; $nilai[] = $f["departemen_asal"];
        }

        $tipe_bind .= "iii";
        $nilai[] = $id_pkj;
        $nilai[] = $id_divisi;
        $nilai[] = $id_user;

        try {
            $peserta_lama = ambil_peserta($koneksi, $id_pkj, (int) $row["id_karyawan"]);

            $koneksi->begin_transaction();

            $st = $koneksi->prepare("UPDATE pekerjaan SET " . implode(", ", $set) . " WHERE id_pekerjaan = ? AND id_divisi = ? AND id_pembuat = ?");
            $st->bind_param($tipe_bind, ...$nilai);
            $st->execute();

            $st = $koneksi->prepare("DELETE FROM pekerjaan_karyawan WHERE id_pekerjaan = ?");
            $st->bind_param("i", $id_pkj);
            $st->execute();

            $st = $koneksi->prepare("INSERT INTO pekerjaan_karyawan (id_pekerjaan, id_user, status_penugasan) VALUES (?, ?, 'Aktif')");
            foreach ($id_e as $id_peserta) {
                $st->bind_param("ii", $id_pkj, $id_peserta);
                $st->execute();
            }

            $koneksi->commit();

            /* karyawan yang baru ditambahkan mendapat notifikasi */
            $peserta_baru = array_diff($id_e, $peserta_lama);
            if ($peserta_baru) {
                kirim_notifikasi(
                    $koneksi,
                    $peserta_baru,
                    "Anda mendapat pekerjaan baru dari " . $nama_saya . ": " . $f["nama_pekerjaan"],
                    $id_user
                );
            }

            $_SESSION["flash_tabel_ok"] = "Pekerjaan berhasil diperbarui.";
        } catch (Throwable $ex) {
            $koneksi->rollback();
            error_log("Edit pekerjaan gagal: " . $ex->getMessage());
            $_SESSION["flash_tabel_error"] = "Pekerjaan gagal diperbarui."
                . ($mode_debug ? " (" . $ex->getMessage() . ")" : "");
        }
    } else {
        $_SESSION["flash_tabel_error"] = $err;
    }

    header("Location: $file_halaman?tab=" . $tab_edit . "&view=" . $view_pekerjaan);
    exit;
}

/* =========================================================
   TAMBAH PEKERJAAN
========================================================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["aksi"] ?? "") === "tambah") {

    foreach ($old as $k => $v) {
        $old[$k] = trim($_POST[$k] ?? $v);
    }

    /* field yang tidak dipakai tipe ini dikosongkan */
    if (!$pakai_nomor) { $old["nomor_order"] = ""; }
    if (!$pakai_pelapor) { $old["nama_pelapor"] = ""; }
    if (!$pakai_dept) { $old["departemen_asal"] = ""; }

    $jenis_old = trim($_POST["jenis_pekerjaan"] ?? "");

    $jumlah = (int) $old["jumlah_karyawan"];
    $id_post = $_POST["id_karyawan"] ?? [];
    if (!is_array($id_post)) {
        $id_post = [];
    }
    foreach ($id_post as $rid) {
        $rid = (int) $rid;
        if ($rid > 0) {
            $id_terpilih[] = $rid;
        }
    }
    $id_terpilih = array_values(array_unique($id_terpilih));

    /* Foto: opsional */
    $post_kosong = empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0;
    $foto_error = "";
    $foto_ext = "";

    if (isset($_FILES["foto"]) && $_FILES["foto"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $fl = $_FILES["foto"];

        if ($fl["error"] !== UPLOAD_ERR_OK) {
            $foto_error = "Foto gagal diunggah (kemungkinan terlalu besar). Coba foto lain.";
        } elseif ($fl["size"] > $maks_ukuran) {
            $foto_error = "Ukuran foto maksimal 5 MB.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fl["tmp_name"]);
            $boleh = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

            if (!isset($boleh[$mime])) {
                $foto_error = "Format foto harus JPG, PNG, atau WEBP.";
            } else {
                $foto_ext = $boleh[$mime];
            }
        }
    }

    $mulai  = DateTime::createFromFormat('Y-m-d H:i', $old["tanggal_mulai"] . " " . $old["jam_mulai"]);
    $target = DateTime::createFromFormat('Y-m-d H:i', $old["target_selesai"] . " " . $old["jam_selesai"]);

    if ($post_kosong) {
        $error = "Ukuran data terlalu besar. Kurangi ukuran foto.";
    } elseif ($old["nama_pekerjaan"] === "" || mb_strlen($old["nama_pekerjaan"]) > 150) {
        $error = "Nama pekerjaan wajib diisi (maksimal 150 karakter).";
    } elseif (mb_strlen($old["nama_pelapor"]) > 100) {
        $error = "Nama pelapor maksimal 100 karakter.";
    } elseif (mb_strlen($old["departemen_asal"]) > 100) {
        $error = "Nama departemen maksimal 100 karakter.";
    } elseif (mb_strlen($old["nomor_order"]) > 50) {
        $error = "Nomor order maksimal 50 karakter.";
    } elseif ($pakai_nomor && $old["nomor_order"] === "") {
        $error = "Nomor order wajib diisi.";
    } elseif (!in_array($jenis_old, $daftar_jenis, true)) {
        $error = "Pilih satu jenis perbaikan.";
    } elseif ($cfg["peralatan_wajib"] && $old["nama_peralatan"] === "") {
        $error = "Nama peralatan wajib diisi.";
    } elseif (mb_strlen($old["nama_peralatan"]) > 150) {
        $error = "Nama peralatan maksimal 150 karakter.";
    } elseif ($old["lokasi"] === "") {
        $error = "Lokasi wajib diisi.";
    } elseif ($old["uraian_kerusakan"] === "") {
        $error = $cfg["label_uraian"] . " wajib diisi.";
    } elseif ($jumlah < 1 || $jumlah > 10) {
        $error = "Jumlah karyawan harus antara 1 sampai 10.";
    } elseif (count($id_terpilih) != $jumlah) {
        $error = "Silakan pilih nama karyawan sesuai jumlah yang ditentukan.";
    } elseif (array_diff($id_terpilih, array_keys($daftar_karyawan))) {
        $error = "Terdapat karyawan yang tidak valid.";
    } elseif (!$mulai || !$target) {
        $error = "Tanggal dan jam pekerjaan tidak valid.";
    } elseif ($target <= $mulai) {
        $error = "Target selesai harus setelah tanggal dan jam mulai.";
    } elseif ($foto_error !== "") {
        $error = $foto_error;
    } else {
        $t_mulai  = $mulai->format("Y-m-d H:i:s");
        $t_target = $target->format("Y-m-d H:i:s");
        $deskripsi_kosong = "";
        $status_awal = "Belum Dikerjakan";
        $file_foto_tersimpan = "";
        $id_utama = (int) $id_terpilih[0];

        try {
            $koneksi->begin_transaction();

            $foto_db = null;

            if ($foto_ext !== "") {
                if (!is_dir($folder_foto) && !mkdir($folder_foto, 0755, true)) {
                    throw new Exception("Folder foto tidak bisa dibuat.");
                }
                $nama_foto = "pkj_" . date("YmdHis") . "_" . bin2hex(random_bytes(4)) . "." . $foto_ext;
                if (!move_uploaded_file($_FILES["foto"]["tmp_name"], $folder_foto . $nama_foto)) {
                    throw new Exception("Foto gagal disimpan.");
                }
                $file_foto_tersimpan = $folder_foto . $nama_foto;
                $foto_db = $nama_foto;
            }

            $st = $koneksi->prepare(
                "INSERT INTO pekerjaan
                 (nama_pekerjaan, nama_pelapor, departemen_asal, lokasi, jenis_pekerjaan, nama_peralatan, uraian_kerusakan, tipe_order, nomor_order,
                  id_divisi, id_karyawan, id_pembuat, tanggal_mulai, target_selesai, deskripsi, foto, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st->bind_param(
                "sssssssssiiisssss",
                $old["nama_pekerjaan"], $old["nama_pelapor"], $old["departemen_asal"], $old["lokasi"],
                $jenis_old, $old["nama_peralatan"], $old["uraian_kerusakan"], $tipe_aktif, $old["nomor_order"],
                $id_divisi, $id_utama, $id_user, $t_mulai, $t_target, $deskripsi_kosong, $foto_db, $status_awal
            );
            $st->execute();
            $id_baru_simpan = $koneksi->insert_id;

            $st = $koneksi->prepare(
                "INSERT INTO pekerjaan_karyawan (id_pekerjaan, id_user, status_penugasan) VALUES (?, ?, 'Aktif')"
            );
            foreach ($id_terpilih as $id_peserta) {
                $st->bind_param("ii", $id_baru_simpan, $id_peserta);
                $st->execute();
            }

            $koneksi->commit();

            /* Notifikasi ke karyawan yang dipilih (selain diri sendiri) */
            kirim_notifikasi(
                $koneksi,
                $id_terpilih,
                "Anda mendapat pekerjaan baru dari " . $nama_saya . ": " . $old["nama_pekerjaan"],
                $id_user
            );

            $_SESSION["flash_input_pekerjaan"] = "Pekerjaan berhasil disimpan dan diteruskan ke karyawan yang dipilih.";
            $_SESSION["flash_input_untuk_saya"] = in_array($id_user, $id_terpilih, true);

            $view_baru = (count($id_terpilih) > 1) ? "tim" : "individu";
            header("Location: $file_halaman?tab=" . $tab_aktif . "&baru=" . (int) $id_baru_simpan . "&view=" . $view_baru);
            exit;
        } catch (Throwable $e) {
            $koneksi->rollback();
            if ($file_foto_tersimpan !== "") {
                @unlink($file_foto_tersimpan);
            }
            error_log("Input pekerjaan gagal: " . $e->getMessage());
            $error = "Pekerjaan gagal ditambahkan. Silakan coba lagi."
                . ($mode_debug ? " Penyebab: " . $e->getMessage() : "");
        }
    }
}

$pesan = $_SESSION["flash_input_pekerjaan"] ?? "";
$pesan_untuk_saya = !empty($_SESSION["flash_input_untuk_saya"]);
unset($_SESSION["flash_input_pekerjaan"], $_SESSION["flash_input_untuk_saya"]);

$pesan_tabel_ok  = $_SESSION["flash_tabel_ok"] ?? "";
$pesan_tabel_err = $_SESSION["flash_tabel_error"] ?? "";
unset($_SESSION["flash_tabel_ok"], $_SESSION["flash_tabel_error"]);

$id_baru = (int) ($_GET["baru"] ?? 0);

$form_terbuka = ($error !== "");

/* =========================================================
   JUMLAH PEKERJAAN PER TIPE (badge tab)
========================================================= */
$jumlah_per_tipe = [];
$st = $koneksi->prepare("SELECT tipe_order, COUNT(*) AS jml FROM pekerjaan WHERE id_divisi = ? AND id_pembuat = ? GROUP BY tipe_order");
$st->bind_param("ii", $id_divisi, $id_user);
$st->execute();
$rs = $st->get_result();
while ($r = $rs->fetch_assoc()) {
    $jumlah_per_tipe[$r["tipe_order"]] = (int) $r["jml"];
}

/* =========================================================
   SEARCH + FILTER
========================================================= */
$cari = trim($_GET["cari"] ?? "");
$filter_status = trim($_GET["status"] ?? "");

if ($filter_status == "Peralihan") {
    $filter_status = "Belum Dikerjakan";
}
if ($filter_status == "Semua Status") {
    $filter_status = "";
}

$tipe_aman = $koneksi->real_escape_string($tipe_aktif);

/* Semua pekerjaan yang dibuat user ini pada tipe aktif, apa pun statusnya */
$kondisi = "pekerjaan.id_divisi = '$id_divisi'
    AND pekerjaan.id_pembuat = '$id_user'
    AND pekerjaan.tipe_order = '$tipe_aman'";

if ($cari != "") {
    $cari_aman = $koneksi->real_escape_string($cari);

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
    $kondisi .= " AND (pekerjaan.status IN ('Belum Dikerjakan', 'Peralihan') OR pekerjaan.status IS NULL OR pekerjaan.status = '')";
} elseif (in_array($filter_status, $daftar_status, true)) {
    $filter_status_aman = $koneksi->real_escape_string($filter_status);
    $kondisi .= " AND pekerjaan.status = '$filter_status_aman'";
} else {
    $filter_status = "";
}

/* =========================================================
   DATA PEKERJAAN
========================================================= */
$query_pekerjaan = $koneksi->query(
    "SELECT
        pekerjaan.*,

        GROUP_CONCAT(
            DISTINCT CASE
                WHEN pk.status_penugasan IN ('Aktif', 'Peralihan') THEN u.nama
            END
            ORDER BY u.nama ASC
            SEPARATOR ', '
        ) AS nama_karyawan_relasi,

        GROUP_CONCAT(
            DISTINCT CASE
                WHEN pk.status_penugasan IN ('Aktif', 'Peralihan') THEN pk.id_user
            END
            ORDER BY pk.id_user ASC
            SEPARATOR ','
        ) AS id_karyawan_relasi,

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
    while ($row = $query_pekerjaan->fetch_assoc()) {

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

if ($halaman_individu > $total_halaman_individu) { $halaman_individu = $total_halaman_individu; }
if ($halaman_tim > $total_halaman_tim) { $halaman_tim = $total_halaman_tim; }

$offset_individu = ($halaman_individu - 1) * $per_halaman;
$offset_tim = ($halaman_tim - 1) * $per_halaman;

$data_individu_tampil = array_slice($data_individu, $offset_individu, $per_halaman);
$data_tim_tampil = array_slice($data_tim, $offset_tim, $per_halaman);

if ($view_pekerjaan === "tim") {
    $data_aktif = $data_tim_tampil;
    $total_aktif = $total_tim;
    $offset_aktif = $offset_tim;
    $halaman_aktif = $halaman_tim;
    $total_halaman_aktif = $total_halaman_tim;
    $parameter_halaman_aktif = "halaman_tim";
    $pesan_kosong_aktif = "Tidak ada pekerjaan tim.";
} else {
    $data_aktif = $data_individu_tampil;
    $total_aktif = $total_individu;
    $offset_aktif = $offset_individu;
    $halaman_aktif = $halaman_individu;
    $total_halaman_aktif = $total_halaman_individu;
    $parameter_halaman_aktif = "halaman_individu";
    $pesan_kosong_aktif = "Tidak ada pekerjaan individu.";
}

$mulai_aktif = $total_aktif > 0 ? $offset_aktif + 1 : 0;
$akhir_aktif = min($offset_aktif + $per_halaman, $total_aktif);

$query_string_dasar = ["tab" => $tab_aktif];
if ($cari != "") { $query_string_dasar["cari"] = $cari; }
if ($filter_status != "") { $query_string_dasar["status"] = $filter_status; }
$query_string_dasar["view"] = $view_pekerjaan;

function url_halaman($file, $halaman_tujuan, $query_dasar, $parameter_halaman)
{
    $query_dasar[$parameter_halaman] = $halaman_tujuan;
    return $file . "?" . http_build_query($query_dasar);
}

function badge_status($status)
{
    switch ($status) {
        case "Sedang Dikerjakan": return "badge-sedang";
        case "Menunggu Konfirmasi": return "badge-menunggu";
        case "Perlu Perbaikan": return "badge-perbaikan";
        case "Selesai": return "badge-selesai";
        default: return "badge-belum";
    }
}

$jumlah_kolom = $tabel_lengkap ? 14 : 12;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Input Pekerjaan - SIMO</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Arial,sans-serif}
body{background:#fff;min-height:100vh;color:#2c3644}
a{text-decoration:none;color:inherit}
.content{padding:26px 32px 40px}

.welcome{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px;gap:15px;flex-wrap:wrap}
.welcome h2{font-size:22px;color:#1c2636;margin-bottom:5px}
.welcome p{font-size:13px;color:#667488}

.alert{padding:12px 15px;border-radius:9px;margin-bottom:18px;font-size:13px;line-height:1.55}
.alert.success{background:#dff5e8;color:#29965a}
.alert.success a{font-weight:700;text-decoration:underline}
.alert.error{background:#fde2e2;color:#d94b4b}

/* TAB TIPE ORDER */
.tabs{display:flex;gap:6px;border-bottom:1px solid #d9dde2;margin-bottom:18px;overflow-x:auto}
.tab{display:flex;align-items:center;gap:8px;padding:11px 16px;font-size:13px;font-weight:600;color:#667488;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;transition:color .15s,border-color .15s}
.tab:hover{color:#b51f2a}
.tab.aktif{color:#b51f2a;border-bottom-color:#b51f2a}
.tab .jml{min-width:22px;padding:2px 7px;border-radius:20px;background:#eef1f5;color:#667488;font-size:11px;font-weight:700;text-align:center}
.tab.aktif .jml{background:#f7e3e5;color:#b51f2a}

.card{background:#fff;border:1px solid #d9dde2;border-radius:13px;padding:20px;box-shadow:0 1px 3px rgba(16,24,40,.04);margin-bottom:18px}
.card-title{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.card-title-icon{width:34px;height:34px;border-radius:50%;background:#f7e3e5;color:#b51f2a;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0}
.card-title-icon svg{width:16px;height:16px;stroke:#b51f2a}
.card-title h3{font-size:15px;color:#1c2636}

/* TOMBOL TAMBAH */
.btn-tambah{display:inline-flex;align-items:center;gap:8px;border:none;background:#b51f2a;color:#fff;padding:11px 18px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s,transform .15s}
.btn-tambah svg{width:16px;height:16px;stroke:#fff}
.btn-tambah:hover{background:#941923;transform:translateY(-1px)}
#cardForm{display:none}
#cardForm.show{display:block}
.btn-tutup-form{margin-left:auto;width:32px;height:32px;border-radius:8px;border:1px solid #d9dde2;background:#fff;color:#667488;font-size:20px;line-height:1;cursor:pointer}
.btn-tutup-form:hover{border-color:#b51f2a;color:#b51f2a;background:#fdf3f4}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-group{width:100%}
.form-group.full{grid-column:1 / -1}
.form-group label{display:block;font-size:12px;font-weight:600;color:#526176;margin-bottom:8px}
.form-group label .opsional{font-weight:400;color:#9aa3af}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;outline:none}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#b51f2a}
.form-group textarea{min-height:90px;resize:vertical}
.date-time-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.date-time-field span{display:block;font-size:11px;color:#8b96a5;margin-bottom:6px}
.date-time-field input{width:100%}

/* FOTO */
.foto-actions{display:flex;gap:10px;flex-wrap:wrap}
.btn-foto{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s,color .15s}
.btn-foto svg{width:16px;height:16px;stroke:currentColor}
.btn-foto:hover{border-color:#b51f2a;color:#b51f2a;background:#fdf3f4}
.foto-hint{margin-top:6px;font-size:11px;color:#9aa3af}
.foto-preview{display:none;margin-top:10px;position:relative;width:160px}
.foto-preview.show{display:block}
.foto-preview img{display:block;width:160px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #dce3ec}
.foto-preview button{position:absolute;top:-8px;right:-8px;width:24px;height:24px;border-radius:50%;border:none;background:#b51f2a;color:#fff;font-size:15px;line-height:1;cursor:pointer}

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

/* KARYAWAN */
.jumlah-karyawan-box{background:#f7f8fa;border:1px solid #edf1f5;border-radius:10px;padding:14px;margin-bottom:12px}
.jumlah-karyawan-box label{margin-bottom:7px}
.jumlah-karyawan-box select{max-width:180px;background:#fff}
.karyawan-container{display:flex;flex-direction:column;gap:10px}
.karyawan-item-row{display:grid;grid-template-columns:105px 1fr;align-items:center;gap:10px}
.karyawan-number{font-size:12px;font-weight:600;color:#667488}
.karyawan-select{width:100%}
.karyawan-hint{margin-top:8px;font-size:11px;color:#9aa3af}
.form-button{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
.btn-simpan{border:none;background:#b51f2a;color:#fff;padding:11px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-simpan:hover{background:#941923}
.btn-simpan:disabled{opacity:.6;cursor:not-allowed}
.btn-batal{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#526176;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-batal:hover{background:#f4f6f9}

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
.tabel-pekerjaan{width:100%;min-width:1400px;border-collapse:collapse;background:#fff}
.tabel-pekerjaan.lengkap{min-width:1600px}
.tabel-pekerjaan th{background:#b51f2a;padding:12px;text-align:left;font-size:12px;color:#fff;font-weight:700;white-space:nowrap;border-bottom:1px solid #b51f2a}
.tabel-pekerjaan th:first-child{border-top-left-radius:8px}
.tabel-pekerjaan th:last-child{border-top-right-radius:8px}
.tabel-pekerjaan td{padding:12px;font-size:13px;color:#172033;border-bottom:1px solid #eef0f3;background:#fff;vertical-align:middle;height:58px;white-space:nowrap}
.tabel-pekerjaan tbody tr{transition:background .15s ease;height:58px}
.tabel-pekerjaan tbody tr:hover td{background:#f8f9fb}
.tabel-pekerjaan .job-name{font-weight:600;color:#172033;max-width:240px;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabel-pekerjaan .nowrap{white-space:nowrap}
.nomor-order{display:inline-block;font-weight:700;color:#1d4ed8;letter-spacing:.2px;font-variant-numeric:tabular-nums}
.nomor-kosong{color:#9aa3af;font-weight:400}
.tabel-pekerjaan .karyawan-list{max-width:220px;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabel-pekerjaan .pelapor-text{max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabel-pekerjaan .uraian-text{max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jenis-chips{display:flex;flex-wrap:nowrap;gap:5px;min-width:0}
.jenis-chip{display:inline-block;padding:4px 8px;border-radius:6px;background:#f9e9eb;color:#b51f2a;font-size:10.5px;font-weight:700;line-height:1.3;white-space:nowrap}

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
.catatan-status{display:block;margin-top:5px;max-width:190px;font-size:11px;color:#c94242;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.aksi-group{display:flex;align-items:center;gap:8px}
.btn-aksi{width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;border:1px solid #d9dde2;background:#fff8f8;cursor:pointer;flex-shrink:0;transition:background .15s ease,border-color .15s ease;padding:0}
.btn-aksi svg{width:15px;height:15px;stroke:#b31824;transition:stroke .15s ease}
.btn-aksi:hover{background:#b51f2a;border-color:#b51f2a}
.btn-aksi:hover svg{stroke:#fff}
.btn-aksi.btn-setuju{background:#f1fbf5;border-color:#bfe6cf}
.btn-aksi.btn-setuju svg{stroke:#168353}
.btn-aksi.btn-setuju:hover{background:#168353;border-color:#168353}
.btn-aksi.btn-revisi{background:#fff8ec;border-color:#f1dba8}
.btn-aksi.btn-revisi svg{stroke:#a46b00}
.btn-aksi.btn-revisi:hover{background:#a46b00;border-color:#a46b00}
.btn-aksi.btn-setuju:hover svg,.btn-aksi.btn-revisi:hover svg{stroke:#fff}
.aksi-terkunci{font-size:11px;color:#9aa3af;white-space:nowrap}
.empty-row{text-align:center!important;padding:40px 20px!important;color:#8a94a5;font-size:13px}

.pagination-row{display:flex;align-items:center;justify-content:space-between;margin-top:14px;gap:15px;flex-wrap:wrap}
.pagination-info{font-size:12px;color:#8b96a5}
.pagination{display:flex;align-items:center;gap:6px}
.pagination a,.pagination span{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#4d5969;border:1px solid #e2e8f0}
.pagination a:hover{background:#f5f7fa}
.pagination .active{background:#b51f2a;color:#fff;border-color:#b51f2a;font-weight:700}
.pagination .disabled{opacity:.4;pointer-events:none}

/* MODAL EDIT */
.edit-modal{position:fixed;inset:0;z-index:10001;display:none;align-items:center;justify-content:center;background:rgba(16,24,40,.5);padding:16px}
.edit-modal.show{display:flex}
.edit-box{background:#fff;border-radius:13px;width:100%;max-width:760px;max-height:92vh;overflow-y:auto;padding:22px}
.edit-box h3{font-size:16px;color:#1c2636;margin-bottom:16px}
.edit-box .form-button{margin-top:18px}

/* MODAL HAPUS & KONFIRMASI */
.delete-modal-overlay{position:fixed;inset:0;background:rgba(28,34,44,.42);display:none;align-items:center;justify-content:center;padding:20px;z-index:10000;backdrop-filter:blur(2px)}
.delete-modal-overlay.show{display:flex}
.delete-modal-box{width:380px;max-width:100%;background:#fff;border-radius:16px;padding:28px 26px 24px;text-align:center;box-shadow:0 18px 45px rgba(20,28,40,.18)}
.delete-modal-icon{width:50px;height:50px;margin:0 auto 15px;border-radius:50%;background:#f7e3e5;display:flex;align-items:center;justify-content:center;color:#b51f2a}
.delete-modal-icon svg{width:22px;height:22px;stroke:currentColor}
.delete-modal-icon.hijau{background:#e9f8f0;color:#168353}
.delete-modal-icon.kuning{background:#fff5dc;color:#a46b00}
.delete-modal-box h3{margin-bottom:7px;font-size:17px;color:#1c2636}
.delete-modal-box p{margin:0 auto 22px;max-width:300px;font-size:12px;line-height:1.6;color:#7e8897}
.delete-modal-actions{display:flex;justify-content:center;gap:10px}
.delete-modal-btn{min-width:104px;padding:10px 16px;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.delete-modal-cancel{border:1px solid #dde3ea;background:#f7f8fa;color:#697587}
.delete-modal-cancel:hover{background:#eef1f4}
.delete-modal-confirm{border:1px solid #b51f2a;background:#b51f2a;color:#fff}
.delete-modal-confirm:hover{background:#941923;border-color:#941923}
.delete-modal-confirm.hijau{border-color:#168353;background:#168353}
.delete-modal-confirm.hijau:hover{border-color:#106b42;background:#106b42}
.delete-modal-confirm.kuning{border-color:#a46b00;background:#a46b00}
.delete-modal-confirm.kuning:hover{border-color:#845500;background:#845500}
.konfirm-catatan{display:none;width:100%;min-height:90px;margin:0 0 20px;padding:11px 12px;border:1px solid #dce3ec;border-radius:8px;background:#fff;color:#405067;font-size:13px;font-family:inherit;text-align:left;resize:vertical;outline:none}
.konfirm-catatan.show{display:block}
.konfirm-catatan:focus{border-color:#b51f2a}

.footer-note{text-align:center;font-size:12px;color:#a3adba;padding:26px 0 6px}

@media(max-width:1100px){
    .form-grid{grid-template-columns:1fr}
    .form-group.full{grid-column:auto}
}
@media(max-width:800px){
    .content{padding:20px}
    .list-header{flex-direction:column;align-items:flex-start}
    .pekerjaan-tabs{grid-template-columns:1fr}
    .search-box input{width:100%}
    .list-header-right{width:100%}
    .list-header-right .search-box{width:100%}
    .list-header-right .filter-select{flex:1;min-width:0}
    .karyawan-item-row{grid-template-columns:1fr;gap:5px}
    .foto-actions .btn-foto{flex:1;justify-content:center}
    .form-group .opsi{padding:14px;min-height:52px}
}

/* DARK MODE */
html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
html body.dark-mode .main,html body.dark-mode .content{background:#0f141b!important}

html body.dark-mode .welcome h2,
html body.dark-mode .card-title h3,
html body.dark-mode .daftar-pekerjaan-label,
html body.dark-mode .form-group label,
html body.dark-mode .jumlah-karyawan-box label,
html body.dark-mode .edit-box h3{color:#edf2f7!important}
html body.dark-mode .welcome p,
html body.dark-mode .daftar-pekerjaan-desc,
html body.dark-mode .karyawan-hint,
html body.dark-mode .foto-hint,
html body.dark-mode .form-group label .opsional,
html body.dark-mode .date-time-field span,
html body.dark-mode .pagination-info,
html body.dark-mode .empty-row{color:#8f9bac!important}

html body.dark-mode .tabs{border-bottom-color:#2c3542!important}
html body.dark-mode .tab{color:#8f9bac!important}
html body.dark-mode .tab:hover{color:#f07a80!important}
html body.dark-mode .tab.aktif{color:#f07a80!important;border-bottom-color:#b51f2a!important}
html body.dark-mode .tab .jml{background:#202733!important;color:#c3ccd8!important}
html body.dark-mode .tab.aktif .jml{background:#382326!important;color:#f07a80!important}

html body.dark-mode .card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important}
html body.dark-mode .card-title-icon{background:#382326!important;color:#f07a80!important}
html body.dark-mode .card-title-icon svg{stroke:#f07a80!important}
html body.dark-mode .btn-tutup-form{background:#0f141b!important;border-color:#2c3542!important;color:#cbd2dc!important}
html body.dark-mode .btn-tutup-form:hover{background:#382326!important;border-color:#b51f2a!important;color:#f07a80!important}

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

html body.dark-mode .jumlah-karyawan-box{background:#1b222c!important;border-color:#2c3542!important}
html body.dark-mode .karyawan-number{color:#aeb8c6!important}
html body.dark-mode .search-box svg{stroke:#8f9bac!important}

html body.dark-mode .btn-tambah,
html body.dark-mode .btn-simpan{background:#b51f2a!important;color:#fff!important}
html body.dark-mode .btn-tambah:hover,
html body.dark-mode .btn-simpan:hover{background:#d12b36!important}
html body.dark-mode .btn-batal{background:#202733!important;color:#c3ccd8!important;border-color:#353e4c!important}

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
html body.dark-mode .tabel-pekerjaan .job-name,
html body.dark-mode .tabel-pekerjaan .karyawan-list,
html body.dark-mode .tabel-pekerjaan .pelapor-text,
html body.dark-mode .tabel-pekerjaan .uraian-text{color:#fff!important}
html body.dark-mode .tabel-pekerjaan tbody tr.row-baru td{background:#183d2a!important}
html body.dark-mode .tabel-pekerjaan tbody tr.row-baru td:first-child{box-shadow:inset 4px 0 0 #72d89a}
html body.dark-mode .jenis-chip{background:#382326!important;color:#f07a80!important}
html body.dark-mode .tabel-pekerjaan .nomor-order{color:#8eb5ff!important}
html body.dark-mode .tabel-pekerjaan .nomor-kosong,
html body.dark-mode .aksi-terkunci{color:#6f7a8a!important}
html body.dark-mode .catatan-status{color:#ff8b92!important}

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
html body.dark-mode .btn-aksi.btn-setuju{background:#183d2a!important;border-color:#2c5a41!important}
html body.dark-mode .btn-aksi.btn-setuju svg{stroke:#72d89a!important}
html body.dark-mode .btn-aksi.btn-setuju:hover{background:#168353!important;border-color:#168353!important}
html body.dark-mode .btn-aksi.btn-revisi{background:#3c3924!important;border-color:#5a5536!important}
html body.dark-mode .btn-aksi.btn-revisi svg{stroke:#f2d77d!important}
html body.dark-mode .btn-aksi.btn-revisi:hover{background:#a46b00!important;border-color:#a46b00!important}
html body.dark-mode .btn-aksi.btn-setuju:hover svg,
html body.dark-mode .btn-aksi.btn-revisi:hover svg{stroke:#fff!important}

html body.dark-mode .pagination a,
html body.dark-mode .pagination span{background:#151b23!important;color:#cbd2dc!important;border-color:#2c3542!important}
html body.dark-mode .pagination a:hover{background:#202733!important}
html body.dark-mode .pagination .active{background:#b51f2a!important;color:#fff!important;border-color:#b51f2a!important}

html body.dark-mode .edit-modal{background:rgba(0,0,0,.65)!important}
html body.dark-mode .edit-box{background:#151b23!important;border:1px solid #2c3542}

html body.dark-mode .delete-modal-box{background:#151b23!important;border:1px solid #2c3542!important}
html body.dark-mode .delete-modal-box h3{color:#edf2f7!important}
html body.dark-mode .delete-modal-box p{color:#8f9bac!important}
html body.dark-mode .delete-modal-icon{background:#382326!important;color:#f07a80!important}
html body.dark-mode .delete-modal-icon.hijau{background:#183d2a!important;color:#72d89a!important}
html body.dark-mode .delete-modal-icon.kuning{background:#3c3924!important;color:#f2d77d!important}
html body.dark-mode .delete-modal-cancel{background:#202733!important;border-color:#2c3542!important;color:#cbd2dc!important}
html body.dark-mode .delete-modal-cancel:hover{background:#2a3342!important}
html body.dark-mode .delete-modal-confirm.hijau{background:#168353!important;border-color:#168353!important}
html body.dark-mode .delete-modal-confirm.kuning{background:#a46b00!important;border-color:#a46b00!important}

html body.dark-mode .footer-note{color:#8f9bac!important}
</style>
</head>
<body>

<?php include "../includes/karyawan/sidebar.php"; ?>

<div class="main">

<?php include "../includes/karyawan/topbar.php"; ?>

<div class="content">

    <!-- JUDUL -->
    <div class="welcome">
        <div>
            <h2>Input Pekerjaan</h2>
            <p>Kelola pekerjaan <?= e($tipe_aktif) ?> pada divisi <?= e($nama_divisi) ?>.</p>
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
        <div class="alert success"><?= e($pesan) ?><?php if ($pesan_untuk_saya): ?> <a href="tugas.php">Lihat di Tugas Saya</a><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($pesan_tabel_ok != ""): ?>
        <div class="alert success"><?= e($pesan_tabel_ok) ?></div>
    <?php endif; ?>

    <?php if ($pesan_tabel_err != ""): ?>
        <div class="alert error" role="alert"><?= e($pesan_tabel_err) ?></div>
    <?php endif; ?>

    <?php if ($error != ""): ?>
        <div class="alert error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- TAB TIPE ORDER -->
    <div class="tabs" role="tablist">
        <?php foreach ($konfig as $kode => $kf): ?>
            <a href="<?= $file_halaman ?>?tab=<?= e($kode) ?>"
               class="tab <?= $kode === $tab_aktif ? "aktif" : "" ?>"
               role="tab"
               aria-selected="<?= $kode === $tab_aktif ? "true" : "false" ?>">
                <?= e($kf["tipe"]) ?>
                <span class="jml"><?= (int) ($jumlah_per_tipe[$kf["tipe"]] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- FORM TAMBAH PEKERJAAN -->
    <div class="card <?= $form_terbuka ? "show" : "" ?>" id="cardForm">

        <div class="card-title">
            <div class="card-title-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
            </div>
            <h3>Tambah Pekerjaan <?= e($tipe_aktif) ?></h3>
            <button type="button" class="btn-tutup-form" id="btnTutupForm" aria-label="Tutup form">&times;</button>
        </div>

        <form method="POST" action="<?= $file_halaman ?>?tab=<?= e($tab_aktif) ?>" id="formPekerjaan" enctype="multipart/form-data">

            <input type="hidden" name="aksi" value="tambah">

            <div class="form-grid">

                <?php if ($pakai_nomor): ?>
                <div class="form-group">
                    <label for="nomorOrder">Nomor Order</label>
                    <input type="text" name="nomor_order" id="nomorOrder" maxlength="50" placeholder="Masukkan nomor order" required value="<?= e($old["nomor_order"]) ?>">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="namaPekerjaan">Nama Pekerjaan</label>
                    <input type="text" name="nama_pekerjaan" id="namaPekerjaan" maxlength="150" placeholder="Contoh: Ganti bearing mesin packing 2" required value="<?= e($old["nama_pekerjaan"]) ?>">
                </div>

                <?php if ($pakai_pelapor): ?>
                <div class="form-group">
                    <label for="namaPelapor">Nama Pelapor <span class="opsional">(opsional)</span></label>
                    <input type="text" name="nama_pelapor" id="namaPelapor" placeholder="Masukkan nama pelapor" maxlength="100" value="<?= e($old["nama_pelapor"]) ?>">
                </div>
                <?php endif; ?>

                <?php if ($pakai_dept): ?>
                <div class="form-group">
                    <label for="departemenAsal">Departemen yang Diperbaiki <span class="opsional">(opsional)</span></label>
                    <div class="combo" id="comboDepartemen">
                        <input type="text" name="departemen_asal" id="departemenAsal" placeholder="Ketik departemen, kosongkan jika di luar pabrik" maxlength="100" autocomplete="off" value="<?= e($old["departemen_asal"]) ?>">
                        <ul class="combo-list" id="comboList" role="listbox"></ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- JENIS PERBAIKAN -->
                <div class="form-group full">
                    <label>Jenis Perbaikan <span class="opsional">(pilih salah satu)</span></label>
                    <div class="pilih-jenis" id="jenisPerbaikan">
                        <?php foreach ($daftar_jenis as $i => $j): ?>
                            <label class="opsi">
                                <input type="radio" name="jenis_pekerjaan" value="<?= e($j) ?>" <?= $jenis_old === $j ? "checked" : "" ?> <?= $i === 0 ? "required" : "" ?>>
                                <span class="kotak">
                                    <svg viewBox="0 0 24 24" fill="none" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"></path></svg>
                                </span>
                                <?= e($j) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="namaPeralatan">Nama Peralatan<?php if (!$cfg["peralatan_wajib"]): ?> <span class="opsional">(opsional)</span><?php endif; ?></label>
                    <input type="text" name="nama_peralatan" id="namaPeralatan" maxlength="150" <?= $cfg["peralatan_wajib"] ? "required" : "" ?> placeholder="Contoh: Mesin Packing 2, Forklift FL-03" value="<?= e($old["nama_peralatan"]) ?>">
                </div>

                <div class="form-group">
                    <label for="lokasi">Lokasi</label>
                    <input type="text" name="lokasi" id="lokasi" placeholder="Contoh: Gudang B, Lantai 2" required value="<?= e($old["lokasi"]) ?>">
                </div>

                <!-- KARYAWAN YANG DITUJU -->
                <div class="form-group full" id="karyawanGroup">

                    <label>Karyawan yang Dituju</label>

                    <div class="jumlah-karyawan-box">
                        <label for="jumlahKaryawan">Jumlah Karyawan</label>
                        <select name="jumlah_karyawan" id="jumlahKaryawan">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i ?>" <?= (int) $old["jumlah_karyawan"] === $i ? "selected" : "" ?>><?= $i ?> Karyawan</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="karyawan-container" id="karyawanContainer"></div>

                    <div class="karyawan-hint">Pilih rekan satu divisi atau diri Anda sendiri (ditandai "Anda"). Pekerjaan untuk diri sendiri akan muncul di Tugas Saya.</div>

                </div>

                <div class="form-group">
                    <label>Tanggal Mulai</label>
                    <div class="date-time-row">
                        <div class="date-time-field">
                            <span>Tanggal</span>
                            <input type="date" name="tanggal_mulai" id="tanggalMulai" required value="<?= e($old["tanggal_mulai"]) ?>">
                        </div>
                        <div class="date-time-field">
                            <span>Jam Mulai</span>
                            <input type="time" name="jam_mulai" id="jamMulai" required value="<?= e($old["jam_mulai"]) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Target Selesai</label>
                    <div class="date-time-row">
                        <div class="date-time-field">
                            <span>Tanggal</span>
                            <input type="date" name="target_selesai" id="targetSelesai" required value="<?= e($old["target_selesai"]) ?>">
                        </div>
                        <div class="date-time-field">
                            <span>Jam Selesai</span>
                            <input type="time" name="jam_selesai" id="jamSelesai" required value="<?= e($old["jam_selesai"]) ?>">
                        </div>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="uraianKerusakan"><?= e($cfg["label_uraian"]) ?></label>
                    <textarea name="uraian_kerusakan" id="uraianKerusakan" placeholder="<?= e($cfg["ph_uraian"]) ?>" required><?= e($old["uraian_kerusakan"]) ?></textarea>
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
                <button type="button" class="btn-batal" id="btnBatalForm">Batal</button>
                <button type="submit" class="btn-simpan" id="btnSimpan">Simpan Pekerjaan</button>
            </div>

        </form>

    </div>

    <!-- TAB INDIVIDU / TIM -->
    <div class="pekerjaan-tabs">

        <a href="<?= e(url_halaman($file_halaman, 1, array_merge($query_string_dasar, ["view" => "individu"]), "halaman_individu")) ?>"
           class="pekerjaan-tab <?= $view_pekerjaan === "individu" ? "active" : "" ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21a8 8 0 0 0-16 0"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span>Pekerjaan Individu</span>
            <span class="pekerjaan-tab-count"><?= $total_individu ?></span>
        </a>

        <a href="<?= e(url_halaman($file_halaman, 1, array_merge($query_string_dasar, ["view" => "tim"]), "halaman_tim")) ?>"
           class="pekerjaan-tab <?= $view_pekerjaan === "tim" ? "active" : "" ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <span>Pekerjaan Tim</span>
            <span class="pekerjaan-tab-count"><?= $total_tim ?></span>
        </a>

    </div>

    <!-- DAFTAR PEKERJAAN -->
    <div class="card">

        <div class="list-header">

            <div class="list-header-left">
                <div>
                    <strong class="daftar-pekerjaan-label">Daftar Pekerjaan <?= e($tipe_aktif) ?></strong>
                    <p class="daftar-pekerjaan-desc">Semua pekerjaan yang Anda input, dari belum dikerjakan hingga selesai. Edit &amp; hapus hanya saat belum dikerjakan; konfirmasi hasil saat menunggu konfirmasi. Divisi <?= e($nama_divisi) ?>.</p>
                </div>
            </div>

            <form method="GET" action="<?= $file_halaman ?>" class="list-header-right" id="filterForm">

                <input type="hidden" name="tab" value="<?= e($tab_aktif) ?>">
                <input type="hidden" name="view" value="<?= e($view_pekerjaan) ?>">

                <div class="search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="M21 21l-4-4"/>
                    </svg>
                    <input type="text" name="cari" placeholder="Cari pekerjaan, pelapor, lokasi, atau karyawan..." value="<?= e($cari) ?>">
                </div>

                <select name="status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="" <?= $filter_status == "" ? "selected" : "" ?>>Semua Status</option>
                    <?php foreach ($daftar_status as $ds): ?>
                        <option value="<?= e($ds) ?>" <?= $filter_status == $ds ? "selected" : "" ?>><?= e($ds) ?></option>
                    <?php endforeach; ?>
                </select>

            </form>

        </div>

        <div class="table-wrapper">

            <table class="tabel-pekerjaan <?= $tabel_lengkap ? "lengkap" : "" ?>">
                <thead>
                    <tr>
                        <th>No</th>
                        <?php if ($tabel_lengkap): ?>
                            <th>No. Order</th>
                        <?php endif; ?>
                        <th>Nama Pekerjaan</th>
                        <th>Pelapor</th>
                        <?php if (!$tabel_lengkap): ?>
                            <th>Dibuat Oleh</th>
                        <?php endif; ?>
                        <th>Dept</th>
                        <th>Jenis</th>
                        <?php if ($tabel_lengkap): ?>
                            <th>Peralatan</th>
                        <?php endif; ?>
                        <th><?= e($cfg["label_uraian"]) ?></th>
                        <?php if ($tabel_lengkap): ?>
                            <th>Lokasi</th>
                        <?php endif; ?>
                        <?php if ($view_pekerjaan === "tim"): ?>
                            <th>Jumlah</th>
                        <?php else: ?>
                            <th>Dikerjakan Oleh</th>
                        <?php endif; ?>
                        <th>Mulai</th>
                        <th>Selesai</th>
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
                            $teks_st = teks_status($status);
                            $class_status = badge_status($teks_st);
                            $boleh_ubah = ($teks_st === "Belum Dikerjakan");
                            $menunggu_konfirmasi = ($teks_st === "Menunggu Konfirmasi");

                            $teks_nomor = !empty($row["nomor_order"]) ? $row["nomor_order"] : "-";
                            $teks_pelapor = !empty($row["nama_pelapor"]) ? $row["nama_pelapor"] : "-";
                            $teks_pembuat = !empty($row["nama_pembuat"]) ? $row["nama_pembuat"] : "-";
                            $teks_peralatan = !empty($row["nama_peralatan"]) ? $row["nama_peralatan"] : "-";
                            $teks_uraian = !empty($row["uraian_kerusakan"]) ? $row["uraian_kerusakan"] : "-";
                            $teks_lokasi = !empty($row["lokasi"]) ? $row["lokasi"] : "-";
                            $teks_catatan = trim((string) ($row["catatan_perbaikan"] ?? ""));

                            if (!empty($row["departemen_asal"])) {
                                $teks_departemen = $row["departemen_asal"];
                            } else {
                                $teks_departemen = $tabel_lengkap ? "-" : $nama_divisi;
                            }

                            $daftar_jenis_row = array_filter(array_map("trim", explode(",", (string) ($row["jenis_pekerjaan"] ?? ""))));

                            $ts_mulai = !empty($row["tanggal_mulai"]) ? strtotime($row["tanggal_mulai"]) : false;
                            $ts_target = !empty($row["target_selesai"]) ? strtotime($row["target_selesai"]) : false;

                            $teks_mulai = $ts_mulai ? date("d M Y, H:i", $ts_mulai) : "-";
                            $teks_selesai = $ts_target ? date("d M Y, H:i", $ts_target) : "-";

                            $jenis_pertama = $daftar_jenis_row ? reset($daftar_jenis_row) : "";

                            /* id karyawan yang sedang ditugaskan (untuk form edit) */
                            $id_peserta_row = [];
                            if (!empty($row["id_karyawan_relasi"])) {
                                foreach (explode(",", $row["id_karyawan_relasi"]) as $ip) {
                                    if ((int) $ip > 0) { $id_peserta_row[] = (string) (int) $ip; }
                                }
                            } elseif (!empty($row["id_karyawan"])) {
                                $id_peserta_row[] = (string) (int) $row["id_karyawan"];
                            }

                            $data_edit = [
                                "id"              => (int) $row["id_pekerjaan"],
                                "nomor_order"     => $row["nomor_order"] ?? "",
                                "nama_pekerjaan"  => $row["nama_pekerjaan"] ?? "",
                                "nama_pelapor"    => $row["nama_pelapor"] ?? "",
                                "departemen_asal" => $row["departemen_asal"] ?? "",
                                "jenis_pekerjaan" => $jenis_pertama,
                                "nama_peralatan"  => $row["nama_peralatan"] ?? "",
                                "lokasi"          => $row["lokasi"] ?? "",
                                "uraian"          => $row["uraian_kerusakan"] ?? "",
                                "tanggal_mulai"   => $ts_mulai ? date("Y-m-d", $ts_mulai) : "",
                                "jam_mulai"       => $ts_mulai ? date("H:i", $ts_mulai) : "",
                                "target_selesai"  => $ts_target ? date("Y-m-d", $ts_target) : "",
                                "jam_selesai"     => $ts_target ? date("H:i", $ts_target) : "",
                                "karyawan"        => $id_peserta_row,
                            ];
                        ?>

                        <tr class="<?= ($id_baru > 0 && (int) $row["id_pekerjaan"] === $id_baru) ? "row-baru" : "" ?>">
                            <td><?= $no_aktif++ ?></td>

                            <?php if ($tabel_lengkap): ?>
                            <td class="nowrap">
                                <?php if ($teks_nomor !== "-"): ?>
                                    <span class="nomor-order"><?= e($teks_nomor) ?></span>
                                <?php else: ?>
                                    <span class="nomor-kosong">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>

                            <td><div class="job-name" title="<?= e($row["nama_pekerjaan"] ?? "-") ?>"><?= e($row["nama_pekerjaan"] ?? "-") ?></div></td>

                            <td><div class="pelapor-text" title="<?= e($teks_pelapor) ?>"><?= e($teks_pelapor) ?></div></td>

                            <?php if (!$tabel_lengkap): ?>
                            <td><div class="pelapor-text" title="<?= e($teks_pembuat) ?>"><?= e($teks_pembuat) ?></div></td>
                            <?php endif; ?>

                            <td><div class="pelapor-text" title="<?= e($teks_departemen) ?>"><?= e($teks_departemen) ?></div></td>

                            <td>
                                <div class="jenis-chips">
                                    <?php if ($daftar_jenis_row): foreach ($daftar_jenis_row as $jn): ?>
                                        <span class="jenis-chip"><?= e($jn) ?></span>
                                    <?php endforeach; else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                            </td>

                            <?php if ($tabel_lengkap): ?>
                            <td><div class="pelapor-text" title="<?= e($teks_peralatan) ?>"><?= e($teks_peralatan) ?></div></td>
                            <?php endif; ?>

                            <td><div class="uraian-text" title="<?= e($teks_uraian) ?>"><?= e($teks_uraian) ?></div></td>

                            <?php if ($tabel_lengkap): ?>
                            <td><div class="pelapor-text" title="<?= e($teks_lokasi) ?>"><?= e($teks_lokasi) ?></div></td>
                            <?php endif; ?>

                            <?php if ($view_pekerjaan === "tim"): ?>
                                <td><span class="jumlah-karyawan-table"><?= (int) ($row["jumlah_karyawan"] ?? 0) ?> Karyawan</span></td>
                            <?php else: ?>
                                <td><div class="karyawan-list" title="<?= e($row["nama_karyawan"] ?? "-") ?>"><?= e($row["nama_karyawan"] ?? "-") ?></div></td>
                            <?php endif; ?>

                            <td class="nowrap"><?= e($teks_mulai) ?></td>
                            <td class="nowrap"><?= e($teks_selesai) ?></td>

                            <td>
                                <span class="status-badge <?= $class_status ?>"><?= e($teks_st) ?></span>
                                <?php if ($teks_st === "Perlu Perbaikan" && $teks_catatan !== ""): ?>
                                    <span class="catatan-status" title="<?= e($teks_catatan) ?>">Catatan: <?= e($teks_catatan) ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($boleh_ubah): ?>
                                    <div class="aksi-group">
                                        <button type="button" class="btn-aksi btn-edit" title="Edit" data-pkj="<?= e(json_encode($data_edit, JSON_UNESCAPED_UNICODE)) ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 20h9"/>
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="btn-aksi btn-hapus" title="Hapus" data-id="<?= (int) $row["id_pekerjaan"] ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 6h18"/>
                                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                            </svg>
                                        </button>
                                    </div>
                                <?php elseif ($menunggu_konfirmasi): ?>
                                    <div class="aksi-group">
                                        <button type="button" class="btn-aksi btn-setuju" title="Konfirmasi selesai"
                                                data-id="<?= (int) $row["id_pekerjaan"] ?>" data-nama="<?= e($row["nama_pekerjaan"] ?? "") ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M5 12l5 5L20 7"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="btn-aksi btn-revisi" title="Minta perbaikan"
                                                data-id="<?= (int) $row["id_pekerjaan"] ?>" data-nama="<?= e($row["nama_pekerjaan"] ?? "") ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/>
                                                <path d="M21 3v5h-5"/>
                                                <path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/>
                                                <path d="M3 21v-5h5"/>
                                            </svg>
                                        </button>
                                    </div>
                                <?php elseif ($teks_st === "Selesai"): ?>
                                    <span class="aksi-terkunci">Sudah selesai</span>
                                <?php elseif ($teks_st === "Perlu Perbaikan"): ?>
                                    <span class="aksi-terkunci">Menunggu perbaikan</span>
                                <?php else: ?>
                                    <span class="aksi-terkunci">Sedang berjalan</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="<?= (int) $jumlah_kolom ?>" class="empty-row"><?= e($pesan_kosong_aktif) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>

        </div>

        <div class="pagination-row section-pagination">

            <div class="pagination-info">
                <?php if ($total_aktif > 0): ?>
                    Menampilkan <?= $mulai_aktif ?>-<?= $akhir_aktif ?> dari <?= $total_aktif ?> data
                <?php else: ?>
                    Tidak ada data
                <?php endif; ?>
            </div>

            <div class="pagination">

                <a href="<?= e(url_halaman($file_halaman, max(1, $halaman_aktif - 1), $query_string_dasar, $parameter_halaman_aktif)) ?>"
                   class="<?= $halaman_aktif <= 1 ? "disabled" : "" ?>">‹</a>

                <?php for ($p = 1; $p <= $total_halaman_aktif; $p++): ?>
                    <a href="<?= e(url_halaman($file_halaman, $p, $query_string_dasar, $parameter_halaman_aktif)) ?>"
                       class="<?= $p == $halaman_aktif ? "active" : "" ?>"><?= $p ?></a>
                <?php endfor; ?>

                <a href="<?= e(url_halaman($file_halaman, min($total_halaman_aktif, $halaman_aktif + 1), $query_string_dasar, $parameter_halaman_aktif)) ?>"
                   class="<?= $halaman_aktif >= $total_halaman_aktif ? "disabled" : "" ?>">›</a>

            </div>

        </div>

    </div>

    <div class="footer-note">
        &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
    </div>

</div>

</div>

<!-- FORM HAPUS (disubmit lewat JavaScript) -->
<form method="POST" action="<?= e($url_aksi) ?>" id="formHapus" hidden>
    <input type="hidden" name="aksi" value="hapus">
    <input type="hidden" name="id_pekerjaan" id="hapusId" value="">
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

        <h3>Hapus Pekerjaan?</h3>

        <p>Pekerjaan yang dihapus akan menghapus data terkait pekerjaan tersebut.</p>

        <div class="delete-modal-actions">
            <button type="button" class="delete-modal-btn delete-modal-cancel" id="batalHapus">Batal</button>
            <button type="button" class="delete-modal-btn delete-modal-confirm" id="konfirmasiHapus">Ya, Hapus</button>
        </div>

    </div>
</div>

<!-- MODAL KONFIRMASI HASIL PEKERJAAN -->
<div class="delete-modal-overlay" id="konfirmModal">
    <div class="delete-modal-box">

        <form method="POST" action="<?= e($url_aksi) ?>" id="formKonfirm">
            <input type="hidden" name="aksi" value="konfirmasi">
            <input type="hidden" name="id_pekerjaan" id="konfirmId" value="">
            <input type="hidden" name="keputusan" id="konfirmKeputusan" value="">

            <div class="delete-modal-icon hijau" id="konfirmIcon"></div>

            <h3 id="konfirmJudul"></h3>

            <p id="konfirmTeks"></p>

            <textarea name="catatan" id="konfirmCatatan" class="konfirm-catatan" maxlength="500" placeholder="Tulis bagian yang perlu diperbaiki"></textarea>

            <div class="delete-modal-actions">
                <button type="button" class="delete-modal-btn delete-modal-cancel" id="konfirmBatal">Batal</button>
                <button type="submit" class="delete-modal-btn delete-modal-confirm hijau" id="konfirmKirim">Ya</button>
            </div>
        </form>

    </div>
</div>

<!-- MODAL EDIT -->
<div class="edit-modal" id="editModal" role="dialog" aria-modal="true" aria-label="Edit pekerjaan">
    <div class="edit-box">
        <h3>Edit Pekerjaan <?= e($tipe_aktif) ?></h3>

        <form method="POST" action="<?= e($url_aksi) ?>" id="formEdit">
            <input type="hidden" name="aksi" value="edit">
            <input type="hidden" name="id_pekerjaan" id="editId" value="">

            <div class="form-grid">

                <?php if ($pakai_nomor): ?>
                <div class="form-group">
                    <label for="editNomor">Nomor Order</label>
                    <input type="text" name="nomor_order" id="editNomor" maxlength="50" required>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="editNama">Nama Pekerjaan</label>
                    <input type="text" name="nama_pekerjaan" id="editNama" maxlength="150" required>
                </div>

                <?php if ($pakai_pelapor): ?>
                <div class="form-group">
                    <label for="editPelapor">Nama Pelapor <span class="opsional">(opsional)</span></label>
                    <input type="text" name="nama_pelapor" id="editPelapor" maxlength="100">
                </div>
                <?php endif; ?>

                <?php if ($pakai_dept): ?>
                <div class="form-group">
                    <label for="editDept">Departemen yang Diperbaiki <span class="opsional">(opsional)</span></label>
                    <input type="text" name="departemen_asal" id="editDept" maxlength="100" list="listDepartemenEdit" autocomplete="off">
                    <datalist id="listDepartemenEdit">
                        <?php foreach ($data_departemen as $d): ?>
                            <option value="<?= e($d) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="editJenis">Jenis Perbaikan</label>
                    <select name="jenis_pekerjaan" id="editJenis" required>
                        <?php foreach ($daftar_jenis as $j): ?>
                            <option value="<?= e($j) ?>"><?= e($j) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="editPeralatan">Nama Peralatan<?php if (!$cfg["peralatan_wajib"]): ?> <span class="opsional">(opsional)</span><?php endif; ?></label>
                    <input type="text" name="nama_peralatan" id="editPeralatan" maxlength="150" <?= $cfg["peralatan_wajib"] ? "required" : "" ?>>
                </div>

                <div class="form-group full">
                    <label for="editLokasi">Lokasi</label>
                    <input type="text" name="lokasi" id="editLokasi" required>
                </div>

                <!-- KARYAWAN YANG DITUJU (EDIT) -->
                <div class="form-group full">

                    <label>Karyawan yang Dituju</label>

                    <div class="jumlah-karyawan-box">
                        <label for="editJumlah">Jumlah Karyawan</label>
                        <select name="jumlah_karyawan" id="editJumlah">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?> Karyawan</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="karyawan-container" id="editKaryawanContainer"></div>

                </div>

                <div class="form-group">
                    <label>Tanggal Mulai</label>
                    <div class="date-time-row">
                        <div class="date-time-field">
                            <span>Tanggal</span>
                            <input type="date" name="tanggal_mulai" id="editTglMulai" required>
                        </div>
                        <div class="date-time-field">
                            <span>Jam Mulai</span>
                            <input type="time" name="jam_mulai" id="editJamMulai" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Target Selesai</label>
                    <div class="date-time-row">
                        <div class="date-time-field">
                            <span>Tanggal</span>
                            <input type="date" name="target_selesai" id="editTglSelesai" required>
                        </div>
                        <div class="date-time-field">
                            <span>Jam Selesai</span>
                            <input type="time" name="jam_selesai" id="editJamSelesai" required>
                        </div>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="editUraian"><?= e($cfg["label_uraian"]) ?></label>
                    <textarea name="uraian_kerusakan" id="editUraian" required></textarea>
                </div>

            </div>

            <div class="form-button">
                <button type="button" class="btn-batal" id="editBatal">Batal</button>
                <button type="submit" class="btn-simpan">Simpan Perubahan</button>
            </div>
        </form>
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
const daftarKaryawan = <?= json_encode($karyawan_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const pilihanAwal = <?= json_encode(array_map("strval", $id_terpilih)); ?>;
const daftarDepartemen = <?= json_encode($data_departemen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

/* ELEMENT */
const cardForm = document.getElementById("cardForm");
const formPekerjaan = document.getElementById("formPekerjaan");
const namaPekerjaan = document.getElementById("namaPekerjaan");
const jumlahKaryawan = document.getElementById("jumlahKaryawan");
const karyawanContainer = document.getElementById("karyawanContainer");

const tanggalMulai = document.getElementById("tanggalMulai");
const jamMulai = document.getElementById("jamMulai");
const targetSelesai = document.getElementById("targetSelesai");
const jamSelesai = document.getElementById("jamSelesai");

const fotoInput = document.getElementById("fotoPekerjaan");
const fotoPreview = document.getElementById("fotoPreview");
const fotoPreviewImg = document.getElementById("fotoPreviewImg");
const fotoHapusBtn = document.getElementById("fotoHapusBtn");

/* TANGGAL & JAM otomatis (mulai = sekarang, target = +2 jam) */
function dua(n) { return (n < 10 ? "0" : "") + n; }
function fmtTgl(d) { return d.getFullYear() + "-" + dua(d.getMonth() + 1) + "-" + dua(d.getDate()); }
function fmtJam(d) { return dua(d.getHours()) + ":" + dua(d.getMinutes()); }

function isiWaktuAwal() {
    const sekarang = new Date();
    const target = new Date(sekarang.getTime() + 2 * 3600 * 1000);

    if (!tanggalMulai.value) tanggalMulai.value = fmtTgl(sekarang);
    if (!jamMulai.value) jamMulai.value = fmtJam(sekarang);
    if (!targetSelesai.value) targetSelesai.value = fmtTgl(target);
    if (!jamSelesai.value) jamSelesai.value = fmtJam(target);
}

isiWaktuAwal();

/* BUKA / TUTUP FORM TAMBAH */
function bukaForm() {
    cardForm.classList.add("show");
    isiWaktuAwal();
    cardForm.scrollIntoView({ behavior: "smooth", block: "start" });
    setTimeout(function () { namaPekerjaan.focus({ preventScroll: true }); }, 350);
}

function tutupForm() {
    tutupKamera();
    cardForm.classList.remove("show");
}

document.getElementById("btnTambah").addEventListener("click", function () {
    if (cardForm.classList.contains("show")) { tutupForm(); } else { bukaForm(); }
});
document.getElementById("btnTutupForm").addEventListener("click", tutupForm);
document.getElementById("btnBatalForm").addEventListener("click", tutupForm);

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

/* DEPARTEMEN: ketik, saran muncul (hanya ada di tipe yang memakai departemen) */
(function () {

    const input = document.getElementById("departemenAsal");
    const list = document.getElementById("comboList");
    const combo = document.getElementById("comboDepartemen");

    if (!input || !list || !combo) {
        return;
    }

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

/* SELECT KARYAWAN (dipakai form tambah dan form edit) */
function buatKaryawanSelect(nomor, nilaiAwal, container) {

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

    select.addEventListener("change", function () { updatePilihanKaryawan(container); });

    wrapper.appendChild(label);
    wrapper.appendChild(select);

    return wrapper;
}

function pilihanDi(container) {
    const hasil = [];
    container.querySelectorAll(".karyawan-select").forEach(function (s) { hasil.push(s.value); });
    return hasil;
}

function renderKaryawanDi(container, jumlahEl, awal) {

    const jumlah = parseInt(jumlahEl.value) || 1;

    container.innerHTML = "";

    for (let i = 1; i <= jumlah; i++) {
        container.appendChild(buatKaryawanSelect(i, awal[i - 1], container));
    }

    updatePilihanKaryawan(container);
}

/* CEGAH KARYAWAN DUPLIKAT */
function updatePilihanKaryawan(container) {

    const semuaSelect = container.querySelectorAll(".karyawan-select");
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

jumlahKaryawan.addEventListener("change", function () {
    renderKaryawanDi(karyawanContainer, jumlahKaryawan, pilihanDi(karyawanContainer));
});

renderKaryawanDi(karyawanContainer, jumlahKaryawan, pilihanAwal);

/* VALIDASI SUBMIT */
formPekerjaan.addEventListener("submit", function (event) {

    if (document.querySelectorAll('input[name="jenis_pekerjaan"]:checked').length === 0) {
        alert("Pilih satu jenis perbaikan.");
        event.preventDefault();
        return;
    }

    const dipilih = pilihanDi(karyawanContainer).filter(function (v) { return v !== ""; });

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
        tanggalMulai.value === "" ||
        jamMulai.value === "" ||
        targetSelesai.value === "" ||
        jamSelesai.value === ""
    ) {
        alert("Tanggal dan jam mulai serta target selesai wajib diisi.");
        event.preventDefault();
        return;
    }

    const waktuMulai = new Date(tanggalMulai.value + "T" + jamMulai.value);
    const waktuSelesai = new Date(targetSelesai.value + "T" + jamSelesai.value);

    if (waktuSelesai <= waktuMulai) {
        alert("Target selesai harus setelah tanggal dan jam mulai.");
        event.preventDefault();
        return;
    }

    tutupKamera();

    const simpan = document.getElementById("btnSimpan");
    simpan.disabled = true;
    simpan.textContent = "Menyimpan...";
});

/* EDIT */
const editModal = document.getElementById("editModal");
const editJumlah = document.getElementById("editJumlah");
const editKaryawanContainer = document.getElementById("editKaryawanContainer");

function setNilai(id, nilai) {
    const el = document.getElementById(id);
    if (el) { el.value = nilai || ""; }
}

function tutupEdit() {
    editModal.classList.remove("show");
    document.body.style.overflow = "";
}

editJumlah.addEventListener("change", function () {
    renderKaryawanDi(editKaryawanContainer, editJumlah, pilihanDi(editKaryawanContainer));
});

document.querySelectorAll(".btn-edit").forEach(function (tombol) {
    tombol.addEventListener("click", function () {

        let d;
        try {
            d = JSON.parse(tombol.dataset.pkj);
        } catch (err) {
            return;
        }

        setNilai("editId", d.id);
        setNilai("editNomor", d.nomor_order);
        setNilai("editNama", d.nama_pekerjaan);
        setNilai("editPelapor", d.nama_pelapor);
        setNilai("editDept", d.departemen_asal);
        setNilai("editJenis", d.jenis_pekerjaan);
        setNilai("editPeralatan", d.nama_peralatan);
        setNilai("editLokasi", d.lokasi);
        setNilai("editUraian", d.uraian);
        setNilai("editTglMulai", d.tanggal_mulai);
        setNilai("editJamMulai", d.jam_mulai);
        setNilai("editTglSelesai", d.target_selesai);
        setNilai("editJamSelesai", d.jam_selesai);

        const peserta = Array.isArray(d.karyawan) ? d.karyawan : [];
        editJumlah.value = String(Math.min(10, Math.max(1, peserta.length)));
        renderKaryawanDi(editKaryawanContainer, editJumlah, peserta);

        editModal.classList.add("show");
        document.body.style.overflow = "hidden";
    });
});

document.getElementById("editBatal").addEventListener("click", tutupEdit);

editModal.addEventListener("mousedown", function (e) {
    if (e.target === editModal) { tutupEdit(); }
});

document.getElementById("formEdit").addEventListener("submit", function (event) {

    const dipilih = pilihanDi(editKaryawanContainer).filter(function (v) { return v !== ""; });

    if (dipilih.length !== parseInt(editJumlah.value)) {
        alert("Silakan pilih semua nama karyawan sesuai jumlah yang ditentukan.");
        event.preventDefault();
        return;
    }

    if (new Set(dipilih).size !== dipilih.length) {
        alert("Karyawan yang dituju tidak boleh sama.");
        event.preventDefault();
        return;
    }

    const mulai = new Date(
        document.getElementById("editTglMulai").value + "T" + document.getElementById("editJamMulai").value
    );
    const selesai = new Date(
        document.getElementById("editTglSelesai").value + "T" + document.getElementById("editJamSelesai").value
    );

    if (selesai <= mulai) {
        alert("Target selesai harus setelah tanggal dan jam mulai.");
        event.preventDefault();
    }
});

/* POPUP HAPUS */
const deleteModal = document.getElementById("deleteModal");
let idPekerjaanHapus = "";

function tutupHapus() {
    deleteModal.classList.remove("show");
    idPekerjaanHapus = "";
}

document.querySelectorAll(".btn-hapus").forEach(function (tombol) {
    tombol.addEventListener("click", function () {
        idPekerjaanHapus = this.dataset.id || "";
        deleteModal.classList.add("show");
    });
});

document.getElementById("batalHapus").addEventListener("click", tutupHapus);

document.getElementById("konfirmasiHapus").addEventListener("click", function () {

    if (idPekerjaanHapus === "") {
        return;
    }

    document.getElementById("hapusId").value = idPekerjaanHapus;
    document.getElementById("formHapus").submit();
});

deleteModal.addEventListener("click", function (event) {
    if (event.target === deleteModal) { tutupHapus(); }
});

/* POPUP KONFIRMASI HASIL (Selesai / Perlu Perbaikan) */
const konfirmModal = document.getElementById("konfirmModal");
const konfirmIcon = document.getElementById("konfirmIcon");
const konfirmJudul = document.getElementById("konfirmJudul");
const konfirmTeks = document.getElementById("konfirmTeks");
const konfirmCatatan = document.getElementById("konfirmCatatan");
const konfirmKirim = document.getElementById("konfirmKirim");

const ikonSelesai = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>';
const ikonRevisi = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/></svg>';

function bukaKonfirm(mode, id, nama) {

    document.getElementById("konfirmId").value = id;
    document.getElementById("konfirmKeputusan").value = mode;
    konfirmCatatan.value = "";

    if (mode === "selesai") {
        konfirmIcon.className = "delete-modal-icon hijau";
        konfirmIcon.innerHTML = ikonSelesai;
        konfirmJudul.textContent = "Konfirmasi Selesai?";
        konfirmTeks.textContent = "Pekerjaan \"" + nama + "\" akan ditandai Selesai dan karyawan terkait diberi tahu.";
        konfirmCatatan.classList.remove("show");
        konfirmCatatan.required = false;
        konfirmKirim.className = "delete-modal-btn delete-modal-confirm hijau";
        konfirmKirim.textContent = "Ya, Selesai";
    } else {
        konfirmIcon.className = "delete-modal-icon kuning";
        konfirmIcon.innerHTML = ikonRevisi;
        konfirmJudul.textContent = "Minta Perbaikan?";
        konfirmTeks.textContent = "Pekerjaan \"" + nama + "\" dikembalikan ke karyawan dengan status Perlu Perbaikan. Tulis catatannya.";
        konfirmCatatan.classList.add("show");
        konfirmCatatan.required = true;
        konfirmKirim.className = "delete-modal-btn delete-modal-confirm kuning";
        konfirmKirim.textContent = "Kirim Perbaikan";
    }

    konfirmModal.classList.add("show");

    if (mode !== "selesai") {
        setTimeout(function () { konfirmCatatan.focus(); }, 50);
    }
}

function tutupKonfirm() {
    konfirmModal.classList.remove("show");
}

document.querySelectorAll(".btn-setuju").forEach(function (tombol) {
    tombol.addEventListener("click", function () {
        bukaKonfirm("selesai", this.dataset.id || "", this.dataset.nama || "");
    });
});

document.querySelectorAll(".btn-revisi").forEach(function (tombol) {
    tombol.addEventListener("click", function () {
        bukaKonfirm("perbaikan", this.dataset.id || "", this.dataset.nama || "");
    });
});

document.getElementById("konfirmBatal").addEventListener("click", tutupKonfirm);

konfirmModal.addEventListener("click", function (event) {
    if (event.target === konfirmModal) { tutupKonfirm(); }
});

document.getElementById("formKonfirm").addEventListener("submit", function (event) {

    if (document.getElementById("konfirmKeputusan").value === "perbaikan" && konfirmCatatan.value.trim() === "") {
        alert("Catatan perbaikan wajib diisi.");
        event.preventDefault();
        return;
    }

    konfirmKirim.disabled = true;
});

/* ESCAPE menutup kamera, modal edit, hapus, dan konfirmasi */
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        tutupKamera();
        tutupEdit();
        tutupHapus();
        tutupKonfirm();
    }
});

/* TANDAI BARIS BARU */
(function () {

    const url = new URL(window.location.href);

    if (url.searchParams.has("baru")) {
        url.searchParams.delete("baru");
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
</script>

</body>
</html>
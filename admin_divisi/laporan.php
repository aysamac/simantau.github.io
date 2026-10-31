<?php
include "../config/session.php";

if (
    !isset($_SESSION["id_user"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "Admin"
) {
    header("Location: ../index.php");
    exit;
}

include "../config/koneksi.php";

$id_admin = (int) $_SESSION["id_user"];

$query_admin = mysqli_query(
    $koneksi,
    "SELECT * FROM users WHERE id_user='$id_admin'"
);

$admin = mysqli_fetch_assoc($query_admin);

$id_divisi  = $admin["id_divisi"];
$nama_admin = $admin["nama"];

$query_notifikasi = mysqli_query(
    $koneksi,
    "SELECT COUNT(*) AS jumlah
     FROM notifikasi
     WHERE id_user='$id_admin'
     AND status_baca='Belum Dibaca'"
);

$jumlah_notifikasi = 0;

if ($query_notifikasi) {
    $data_notifikasi   = mysqli_fetch_assoc($query_notifikasi);
    $jumlah_notifikasi = (int) ($data_notifikasi["jumlah"] ?? 0);
}

$id_divisi_aman = mysqli_real_escape_string($koneksi, $id_divisi);

$query_divisi = mysqli_query(
    $koneksi,
    "SELECT * FROM divisi WHERE id_divisi='$id_divisi_aman'"
);

$divisi      = mysqli_fetch_assoc($query_divisi);
$nama_divisi = $divisi["nama_divisi"] ?? '-';

/* =====================================================
   FILTER
   Tanggal awal & akhir default = tanggal 1 s/d akhir bulan berjalan
   ===================================================== */
$filter_karyawan   = isset($_GET['id_karyawan']) ? $_GET['id_karyawan'] : '';
$filter_status     = isset($_GET['status']) ? $_GET['status'] : '';
$filter_tipe_order = isset($_GET['tipe_order']) ? $_GET['tipe_order'] : '';
$filter_jenis      = isset($_GET['jenis']) ? $_GET['jenis'] : '';
$filter_tgl_awal   = isset($_GET['tgl_awal']) ? trim($_GET['tgl_awal']) : date('Y-m-01');
$filter_tgl_akhir  = isset($_GET['tgl_akhir']) ? trim($_GET['tgl_akhir']) : date('Y-m-t');

/* "Peralihan" dianggap "Belum Dikerjakan" (tidak ada filter Peralihan) */
if ($filter_status == 'Peralihan') {
    $filter_status = 'Belum Dikerjakan';
}

/* "Sedang Dikerjakan" sudah tidak ada di pilihan filter (abaikan jika masih ada di URL lama) */
if ($filter_status == 'Sedang Dikerjakan') {
    $filter_status = '';
}

/* Validasi format tanggal (YYYY-MM-DD), jika tidak valid dikosongkan */
function tanggalValid($tgl)
{
    if ($tgl === '') {
        return false;
    }

    $d = DateTime::createFromFormat('Y-m-d', $tgl);

    return $d && $d->format('Y-m-d') === $tgl;
}

if (!tanggalValid($filter_tgl_awal)) {
    $filter_tgl_awal = '';
}

if (!tanggalValid($filter_tgl_akhir)) {
    $filter_tgl_akhir = '';
}

/* Jika tanggal awal lebih besar dari tanggal akhir, tukar */
if ($filter_tgl_awal !== '' && $filter_tgl_akhir !== '' && $filter_tgl_awal > $filter_tgl_akhir) {
    $tmp              = $filter_tgl_awal;
    $filter_tgl_awal  = $filter_tgl_akhir;
    $filter_tgl_akhir = $tmp;
}

$bulan_indonesia = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember'
];

$query_karyawan = mysqli_query(
    $koneksi,
    "SELECT id_user, nama
     FROM users
     WHERE role = 'Karyawan'
     AND id_divisi = '$id_divisi_aman'
     ORDER BY nama ASC"
);

/* Ambil kolom pembuat dan data pendukung dengan sumber yang sama seperti Monitoring */
$kolom_pekerjaan = [];
$q_kolom_pekerjaan = mysqli_query($koneksi, "SHOW COLUMNS FROM pekerjaan");
if ($q_kolom_pekerjaan) {
    while ($kolom = mysqli_fetch_assoc($q_kolom_pekerjaan)) {
        $kolom_pekerjaan[] = $kolom["Field"];
    }
}
$kolom_pembuat = "";
foreach (["id_pembuat", "dibuat_oleh", "created_by", "id_admin", "id_pelapor", "id_pemesan", "id_user_pembuat", "pembuat"] as $kandidat) {
    if (in_array($kandidat, $kolom_pekerjaan, true)) {
        $kolom_pembuat = $kandidat;
        break;
    }
}
$select_pembuat = $kolom_pembuat !== "" ? "p.`$kolom_pembuat` AS pembuat_mentah" : "NULL AS pembuat_mentah";
$join_pembuat = $kolom_pembuat !== "" ? "LEFT JOIN users uc ON uc.id_user = p.`$kolom_pembuat`" : "LEFT JOIN users uc ON 1=0";
$join_order_on = in_array("id_order", $kolom_pekerjaan, true)
    ? "op_f.id_order = p.id_order"
    : "op_f.nomor_order COLLATE utf8mb4_general_ci = p.nomor_order COLLATE utf8mb4_general_ci AND p.nomor_order <> '' AND op_f.id_divisi = p.id_divisi";

$sql = "
    SELECT
        p.*,
        $select_pembuat,
        uc.role AS role_pembuat,
        COALESCE(uc.nama, up_f.nama) AS nama_pembuat_db,
        COALESCE(NULLIF(uc.departemen, ''), dc.nama_divisi, NULLIF(up_f.departemen, ''), da_f.nama_divisi) AS dept_pembuat,
        up_f.nama AS nama_pelapor_f,
        (SELECT MAX(bp2.tanggal_upload) FROM bukti_pekerjaan bp2 WHERE bp2.id_pekerjaan=p.id_pekerjaan) AS jam_bukti_terakhir,
        (SELECT bp3.catatan_karyawan FROM bukti_pekerjaan bp3 WHERE bp3.id_pekerjaan=p.id_pekerjaan ORDER BY bp3.id_bukti DESC LIMIT 1) AS item_terakhir,
        d.nama_divisi,

        COALESCE(
            (
                SELECT GROUP_CONCAT(
                    DISTINCT u_team.nama
                    ORDER BY u_team.nama ASC
                    SEPARATOR '|||'
                )
                FROM pekerjaan_karyawan pk_team
                INNER JOIN users u_team
                    ON u_team.id_user = pk_team.id_user
                WHERE pk_team.id_pekerjaan = p.id_pekerjaan
                AND pk_team.status_penugasan IN ('Aktif', 'Peralihan')
            ),
            u.nama
        ) AS nama_karyawan,

        CASE
            WHEN (
                SELECT COUNT(DISTINCT pk_filter.id_user)
                FROM pekerjaan_karyawan pk_filter
                WHERE pk_filter.id_pekerjaan = p.id_pekerjaan
                AND pk_filter.status_penugasan IN ('Aktif', 'Peralihan')
            ) > 1 THEN 'Tim'
            ELSE 'Individu'
        END AS kategori_pekerjaan

    FROM pekerjaan p

    LEFT JOIN divisi d
        ON p.id_divisi = d.id_divisi

    LEFT JOIN users u
        ON p.id_karyawan = u.id_user
    $join_pembuat
    LEFT JOIN divisi dc ON dc.id_divisi = uc.id_divisi
    LEFT JOIN order_perbaikan op_f ON $join_order_on
    LEFT JOIN users up_f ON up_f.id_user = op_f.id_pemesan
    LEFT JOIN divisi da_f ON da_f.id_divisi = op_f.id_divisi_asal

    WHERE p.id_divisi = '$id_divisi_aman'
";

if ($filter_karyawan != '' && ctype_digit((string) $filter_karyawan)) {
    $karyawan_aman = (int) $filter_karyawan;

    $sql .= "
        AND (
            p.id_karyawan = $karyawan_aman
            OR EXISTS (
                SELECT 1
                FROM pekerjaan_karyawan pk_kar
                WHERE pk_kar.id_pekerjaan = p.id_pekerjaan
                AND pk_kar.id_user = $karyawan_aman
                AND pk_kar.status_penugasan IN ('Aktif', 'Peralihan')
            )
        )
    ";
}

if ($filter_status == 'Belum Dikerjakan') {
    /* pekerjaan hasil peralihan ikut dihitung sebagai "Belum Dikerjakan" */
    $sql .= " AND p.status IN ('Belum Dikerjakan', 'Peralihan')";
} elseif ($filter_status != '') {
    $status_aman = mysqli_real_escape_string($koneksi, $filter_status);
    $sql .= " AND p.status = '$status_aman'";
}

if ($filter_tipe_order != '') {
    $tipe_aman = mysqli_real_escape_string($koneksi, $filter_tipe_order);
    $sql .= " AND p.tipe_order = '$tipe_aman'";
}

if ($filter_jenis == 'Individu') {
    $sql .= "
        AND (
            SELECT COUNT(DISTINCT pk_filter.id_user)
            FROM pekerjaan_karyawan pk_filter
            WHERE pk_filter.id_pekerjaan = p.id_pekerjaan
            AND pk_filter.status_penugasan IN ('Aktif', 'Peralihan')
        ) <= 1
    ";
} elseif ($filter_jenis == 'Tim') {
    $sql .= "
        AND (
            SELECT COUNT(DISTINCT pk_filter.id_user)
            FROM pekerjaan_karyawan pk_filter
            WHERE pk_filter.id_pekerjaan = p.id_pekerjaan
            AND pk_filter.status_penugasan IN ('Aktif', 'Peralihan')
        ) > 1
    ";
}

/* Filter rentang tanggal berdasarkan tanggal_mulai (sudah termasuk tahun) */
if ($filter_tgl_awal !== '') {
    $tgl_awal_aman = mysqli_real_escape_string($koneksi, $filter_tgl_awal);
    $sql .= " AND DATE(p.tanggal_mulai) >= '$tgl_awal_aman'";
}

if ($filter_tgl_akhir !== '') {
    $tgl_akhir_aman = mysqli_real_escape_string($koneksi, $filter_tgl_akhir);
    $sql .= " AND DATE(p.tanggal_mulai) <= '$tgl_akhir_aman'";
}

$sql .= "
    ORDER BY
        p.tanggal_mulai DESC,
        p.id_pekerjaan DESC
";

$query_laporan = mysqli_query($koneksi, $sql);
$query_print   = mysqli_query($koneksi, $sql);

$total_data = $query_print ? mysqli_num_rows($query_print) : 0;

/* =====================================================
   EXPORT EXCEL (.xlsx) - desain kop & header merah seperti cetak
   Butuh: composer require phpoffice/phpspreadsheet
   ===================================================== */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($autoload)) {
        http_response_code(500);
        exit('PhpSpreadsheet belum terpasang. Jalankan di folder project: composer require phpoffice/phpspreadsheet');
    }

    require_once $autoload;

    $B_MEDIUM = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM;
    $B_THIN   = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
    $H_CENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $H_LEFT   = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT;
    $V_CENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
    $T_STRING = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Laporan Pekerjaan');
    $sheet->setShowGridlines(false);
    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

    /* Lebar kolom (A - N) */
    $kolom = range('A', 'N');
    $lebar = [5, 14, 17, 26, 16, 14, 16, 12, 30, 22, 20, 20, 18, 28];
    foreach ($kolom as $i => $huruf) {
        $sheet->getColumnDimension($huruf)->setWidth($lebar[$i]);
    }

    /* ===== KOP: logo kiri, judul tengah, logo kanan ===== */
    $sheet->mergeCells('D1:L1');
    $sheet->mergeCells('D2:L2');
    $sheet->mergeCells('D3:L3');

    $sheet->setCellValue('D1', 'LAPORAN PEKERJAAN');
    $sheet->setCellValue('D2', 'Sistem Informasi Monitoring Pekerjaan (SIMO)');
    $sheet->setCellValue('D3', 'PT Sinar Sosro Gunung Slamat');

    $sheet->getStyle('D1:L3')->getAlignment()->setHorizontal($H_CENTER)->setVertical($V_CENTER);
    $sheet->getStyle('D1')->getFont()->setBold(true)->setSize(20);
    $sheet->getStyle('D2:D3')->getFont()->setSize(12);

    $sheet->getRowDimension(1)->setRowHeight(40);
    $sheet->getRowDimension(2)->setRowHeight(26);
    $sheet->getRowDimension(3)->setRowHeight(26);
    $sheet->getRowDimension(4)->setRowHeight(6);
    $sheet->getRowDimension(5)->setRowHeight(10);

    /* Garis bawah kop */
    $sheet->getStyle('A4:N4')->getBorders()->getBottom()->setBorderStyle($B_THIN)->getColor()->setRGB('333333');

    $logo_kiri  = __DIR__ . '/../assets/img/logo-sosro.jpeg';
    $logo_kanan = __DIR__ . '/../assets/img/logo-login.jpeg';

    if (file_exists($logo_kiri)) {
        $d1 = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $d1->setName('Logo Sosro');
        $d1->setPath($logo_kiri);
        $d1->setCoordinates('A1');
        $d1->setHeight(92);
        $d1->setOffsetX(5);
        $d1->setOffsetY(5);
        $d1->setWorksheet($sheet);
    }

    if (file_exists($logo_kanan)) {
        $d2 = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $d2->setName('Logo');
        $d2->setPath($logo_kanan);
        $d2->setCoordinates('N1');
        $d2->setHeight(86);
        $d2->setOffsetX(105);
        $d2->setOffsetY(7);
        $d2->setWorksheet($sheet);
    }

    /* ===== HEADER TABEL (baris merah) ===== */
    $baris_header = 6;
    $judul_kolom = [
        'No', 'No. Order', 'Tipe Order', 'Nama Pekerjaan', 'Pelapor', 'Dibuat Oleh',
        'Dept', 'Jenis', 'Uraian Kerusakan', 'Dikerjakan Oleh',
        'Tgl/Jam Mulai', 'Tgl/Jam Selesai', 'Status', 'Item Pekerjaan'
    ];

    foreach ($judul_kolom as $i => $teks) {
        $sheet->setCellValueExplicit($kolom[$i] . $baris_header, $teks, $T_STRING);
    }

    $range_header = 'A' . $baris_header . ':N' . $baris_header;

    $sheet->getStyle($range_header)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '9B2F2F'],
        ],
        'alignment' => [
            'horizontal' => $H_CENTER,
            'vertical'   => $V_CENTER,
            'wrapText'   => true,
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => $B_MEDIUM, 'color' => ['rgb' => '666666']],
        ],
    ]);

    /* Pemisah antar kolom header berwarna merah muda, seperti cetak */
    foreach (array_slice($kolom, 0, 13) as $huruf) {
        $sheet->getStyle($huruf . $baris_header)->getBorders()->getRight()->getColor()->setRGB('C98F8F');
    }

    $sheet->getRowDimension($baris_header)->setRowHeight(30);

    /* ===== ISI TABEL ===== */
    $r = $baris_header + 1;
    $no_x = 1;

    if ($query_laporan) {
        while ($row_x = mysqli_fetch_assoc($query_laporan)) {
            $selesai_x = in_array(($row_x['status'] ?? ''), ['Menunggu Konfirmasi', 'Selesai'], true)
                ? formatTanggalWaktu($row_x['jam_bukti_terakhir'])
                : '-';

            $tim_x = array_filter(array_map('trim', explode('|||', (string) ($row_x['nama_karyawan'] ?? ''))));
            $dikerjakan_x = count($tim_x) ? implode("\n", $tim_x) : '-';

            $isi = [
                $no_x++,
                !empty($row_x['nomor_order']) ? $row_x['nomor_order'] : '-',
                $row_x['tipe_order'] ?? '-',
                $row_x['nama_pekerjaan'] ?? '-',
                !empty($row_x['nama_pelapor']) ? $row_x['nama_pelapor'] : (!empty($row_x['nama_pelapor_f']) ? $row_x['nama_pelapor_f'] : '-'),
                pembuatTampil($row_x),
                !empty($row_x['departemen_asal']) ? $row_x['departemen_asal'] : (!empty($row_x['dept_pembuat']) ? $row_x['dept_pembuat'] : ($row_x['nama_divisi'] ?? '-')),
                $row_x['jenis_pekerjaan'] ?? '-',
                $row_x['uraian_kerusakan'] ?? $row_x['deskripsi'] ?? '-',
                $dikerjakan_x,
                formatTanggalWaktu($row_x['tanggal_mulai']),
                $selesai_x,
                statusTampil($row_x['status'] ?? ''),
                !empty($row_x['item_terakhir']) ? $row_x['item_terakhir'] : 'Belum ada',
            ];

            foreach ($isi as $i => $nilai) {
                if ($i === 0) {
                    $sheet->setCellValue($kolom[$i] . $r, $nilai);
                } else {
                    /* diset sebagai teks agar tidak dibaca sebagai rumus/angka */
                    $sheet->setCellValueExplicit($kolom[$i] . $r, (string) $nilai, $T_STRING);
                }
            }

            $r++;
        }
    }

    $baris_terakhir = $r - 1;

    if ($baris_terakhir < $baris_header + 1) {
        $sheet->mergeCells('A' . $r . ':N' . $r);
        $sheet->setCellValue('A' . $r, 'Tidak ada data pekerjaan.');
        $sheet->getRowDimension($r)->setRowHeight(32);
        $baris_terakhir = $r;
        $r++;
    }

    $range_data = 'A' . ($baris_header + 1) . ':N' . $baris_terakhir;

    $sheet->getStyle($range_data)->applyFromArray([
        'font' => ['color' => ['rgb' => '111111']],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FFFFFF'],
        ],
        'alignment' => [
            'horizontal' => $H_LEFT,
            'vertical'   => $V_CENTER,
            'wrapText'   => true,
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => $B_MEDIUM, 'color' => ['rgb' => '666666']],
        ],
    ]);

    $sheet->getStyle('A' . ($baris_header + 1) . ':A' . $baris_terakhir)->getAlignment()->setHorizontal($H_CENTER);

    /* ===== TANGGAL & TOTAL ===== */
    $r += 1;

    $sheet->setCellValueExplicit('A' . $r, 'Dibuat pada : ' . tanggalIndonesia(date('Y-m-d')), $T_STRING);
    $sheet->getStyle('A' . $r)->getFont()->setSize(11);
    $r++;

    $rt = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
    $run1 = $rt->createTextRun('Total Data :');
    $run1->getFont()->setName('Arial')->setSize(11)->setBold(true);
    $run2 = $rt->createTextRun(' ' . $total_data . ' Pekerjaan');
    $run2->getFont()->setName('Arial')->setSize(11);
    $sheet->setCellValue('A' . $r, $rt);
    $r += 2;

    /* ===== TANDA TANGAN ===== */
    $sheet->mergeCells('A' . $r . ':G' . $r);
    $sheet->mergeCells('H' . $r . ':N' . $r);
    $sheet->setCellValueExplicit('A' . $r, 'Dibuat Oleh :', $T_STRING);
    $sheet->setCellValueExplicit('H' . $r, 'Diperiksa Oleh :', $T_STRING);
    $r++;

    $sheet->getRowDimension($r)->setRowHeight(42);
    $r++;

    $sheet->mergeCells('A' . $r . ':G' . $r);
    $sheet->mergeCells('H' . $r . ':N' . $r);
    $sheet->setCellValueExplicit('A' . $r, '( ............................................................ )', $T_STRING);
    $sheet->setCellValueExplicit('H' . $r, '( ............................................................ )', $T_STRING);
    $r++;

    $sheet->mergeCells('A' . $r . ':G' . $r);
    $sheet->mergeCells('H' . $r . ':N' . $r);
    $sheet->setCellValueExplicit('A' . $r, 'Admin Divisi ' . $nama_divisi, $T_STRING);
    $sheet->setCellValueExplicit('H' . $r, 'Nama :', $T_STRING);
    $r++;

    $sheet->mergeCells('H' . $r . ':N' . $r);
    $sheet->setCellValueExplicit('H' . $r, 'Jabatan :', $T_STRING);

    $sheet->getStyle('A' . ($r - 4) . ':N' . $r)->getAlignment()->setHorizontal($H_CENTER)->setVertical($V_CENTER);
    $sheet->getStyle('A' . ($r - 4) . ':N' . $r)->getFont()->setSize(11);

    /* ===== PENGATURAN CETAK: A4 landscape, 1 halaman lebar ===== */
    $ps = $sheet->getPageSetup();
    $ps->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $ps->setFitToPage(true);
    $ps->setFitToWidth(1);
    $ps->setFitToHeight(0);
    $ps->setHorizontalCentered(true);
    $ps->setRowsToRepeatAtTopByStartAndEnd($baris_header, $baris_header);

    $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);

    /* ===== KIRIM FILE ===== */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $nama_file_x = 'laporan-pekerjaan-' . date('Ymd-His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nama_file_x . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/* Link download Excel dengan filter yang sedang aktif */
$param_export = [
    'id_karyawan' => $filter_karyawan,
    'status'      => $filter_status,
    'tipe_order'  => $filter_tipe_order,
    'jenis'       => $filter_jenis,
    'tgl_awal'    => $filter_tgl_awal,
    'tgl_akhir'   => $filter_tgl_akhir,
];

$link_excel = 'laporan.php?' . http_build_query($param_export + ['export' => 'excel']);

function tanggalIndonesia($tanggal)
{
    global $bulan_indonesia;

    if (empty($tanggal)) {
        return '-';
    }

    $time = strtotime($tanggal);

    if (!$time) {
        return '-';
    }

    return date('d', $time) . ' ' . $bulan_indonesia[(int)date('m', $time)] . ' ' . date('Y', $time);
}

function formatTanggalWaktu($tanggal)
{
    if (empty($tanggal)) {
        return '-';
    }

    $time = strtotime($tanggal);

    if (!$time) {
        return '-';
    }

    return date('d-m-Y, H:i', $time);
}

function tampilkanKaryawan($nama)
{
    if (empty($nama) || $nama === '-') {
        return '-';
    }

    $nama_list = array_filter(array_map('trim', explode('|||', $nama)));

    if (count($nama_list) <= 1) {
        return htmlspecialchars($nama_list[0] ?? '-', ENT_QUOTES, 'UTF-8');
    }

    $html = '';

    foreach ($nama_list as $nama_satu) {
        $html .= '<div class="nama-karyawan">' . htmlspecialchars($nama_satu, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    return $html;
}

function pembuatTampil($row)
{
    $pembuat = '-';

    if (!empty($row['pembuat_mentah']) && stripos((string) ($row['role_pembuat'] ?? ''), 'admin') !== false) {
        $pembuat = 'Admin';
    } elseif (!empty($row['nama_pembuat_db']) && !empty($row['pembuat_mentah'])) {
        $pembuat = $row['nama_pembuat_db'];
    } elseif (!empty($row['pembuat_mentah']) && !is_numeric($row['pembuat_mentah'])) {
        $pembuat = $row['pembuat_mentah'];
    }

    return $pembuat;
}

/* Status "Peralihan" ditampilkan sebagai "Belum Dikerjakan" */
function statusTampil($status)
{
    $status = trim((string) $status);

    if ($status === '' || $status == 'Peralihan') {
        return 'Belum Dikerjakan';
    }

    return $status;
}

function statusClassPrint($status)
{
    if ($status == 'Selesai') {
        return 'print-status selesai';
    }

    if ($status == 'Sedang Dikerjakan') {
        return 'print-status sedang';
    }

    if ($status == 'Menunggu Konfirmasi') {
        return 'print-status menunggu';
    }

    if ($status == 'Perlu Perbaikan') {
        return 'print-status perbaikan';
    }

    return 'print-status belum';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - SIMO</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #2c3644;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .main {
            min-height: 100vh;
            margin-left: 240px;
            width: calc(100% - 240px);
            min-width: 0;
        }

        .content {
            padding: 26px 32px 40px;
        }

        .page-title {
            margin-bottom: 22px;
        }

        .page-title h2 {
            font-size: 22px;
            color: #1c2636;
            margin-bottom: 5px;
        }

        .page-title p {
            font-size: 13px;
            color: #8b96a5;
        }

        .filter-card {
            background: #ffffff;
            border: 1px solid #d8dde4;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 16px;
            font-weight: 700;
            color: #1c2636;
            margin-bottom: 16px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr)) auto;
            gap: 14px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .filter-group label {
            font-size: 11.5px;
            color: #9aa6b5;
            font-weight: 400;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            height: 38px;
            border: 1px solid #dce3ec;
            border-radius: 8px;
            padding: 0 11px;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 12px;
            color: #344054;
            background: #ffffff;
            outline: none;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #b51f2a;
        }

        .btn-filter {
            height: 38px;
            border: none;
            border-radius: 8px;
            padding: 0 18px;
            background: #b51f2a;
            color: #ffffff;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-filter:hover {
            background: #961923;
        }

        .table-card {
            background: #ffffff;
            border: 1px solid #d8dde4;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            overflow: hidden;
        }

        .table-head {
            padding: 18px 22px;
            border-bottom: 1px solid #edf1f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-head h3 {
            font-size: 14px;
            font-weight: 700;
            color: #1c2636;
        }

        .btn-print {
            border: 1px solid #dce3ec;
            background: #ffffff;
            color: #4c5b6e;
            border-radius: 8px;
            padding: 8px 14px;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-print:hover {
            background: #f5f7fa;
        }

        a.btn-print {
            display: inline-block;
            line-height: 1.2;
        }

        .head-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        table th, table td {
            vertical-align: middle;
        }

        table th {
            text-align: center;
        }

        table td {
            text-align: left;
        }

        table td:first-child {
            text-align: center;
        }

        table .job-name {
            text-align: left;
        }

        table .table-text {
            display: inline-block;
            text-align: left;
        }

        /* ===== FONT TABEL (diperbesar sedikit) ===== */
        th {
            background: #b51f2a;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 700;
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid #8f1821;
            white-space: nowrap;
        }

        td {
            padding: 15px 16px;
            font-size: 13.5px;
            color: #4c5b6e;
            border-bottom: 1px solid #f0f3f7;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafbfd;
        }

        table td:first-child {
            color: #667488;
        }

        .job-name {
            font-size: 13.5px;
            font-weight: 400;
            color: #1c2636;
        }

        .order-number {
            font-size: 12px;
            color: #9aa6b5;
            margin-top: 3px;
        }

        .table-text {
            font-size: 13.5px;
            color: #4c5b6e;
            white-space: nowrap;
        }

        .nama-karyawan {
            line-height: 1.55;
            white-space: nowrap;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-belum     { background: #eef0f3; color: #667085; }
        .status-sedang    { background: #edf3ff; color: #3f65b5; }
        .status-menunggu  { background: #fff5dc; color: #a46b00; }
        .status-perbaikan { background: #fde5e5; color: #c94242; }
        .status-selesai   { background: #e9f8f0; color: #168353; }

        body.dark-mode .status-belum     { background: #34383e; color: #c5cbd3; }
        body.dark-mode .status-sedang    { background: #1d3158; color: #8eb5ff; }
        body.dark-mode .status-menunggu  { background: #3c3924; color: #f2d77d; }
        body.dark-mode .status-perbaikan { background: #4b2d32; color: #ff8b92; }
        body.dark-mode .status-selesai   { background: #183d2a; color: #72d89a; }

        .empty-data {
            text-align: center;
            padding: 40px 20px;
            color: #8b96a5;
            font-size: 13px;
        }

        .footer-note {
            text-align: center;
            font-size: 12px;
            color: #a3adba;
            padding: 26px 0 6px;
        }

        .print-report {
            display: none;
        }

        /* =====================================================
           DESAIN CETAK LAPORAN
           ===================================================== */
        @media print {
            @page {
                size: A4 landscape;
                margin: 0;
            }

            html, body {
                width: 100%;
                height: auto;
                margin: 0;
                background: #fff !important;
                color: #111 !important;
            }

            body {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body.dark-mode, body.dark-mode .print-report, body.dark-mode .print-table td {
                background: #fff !important;
                color: #111 !important;
            }

            html body .print-report .print-table td,
            html body.dark-mode .print-report .print-table td {
                background: #fff !important;
                color: #111 !important;
            }

            html body .print-report .print-table td .print-status {
                background: none !important;
                color: #111 !important;
            }

            .sidebar, .main {
                display: none !important;
            }

            .print-report {
                display: block !important;
                width: 100%;
                padding: 8mm 10mm 0 10mm;
                box-sizing: border-box;
                background: #fff !important;
                color: #111 !important;
                font-family: Arial, Helvetica, sans-serif;
            }

            /* ===== HEADER ===== */
            .print-header {
                width: 100%;
                display: grid;
                grid-template-columns: 240px 1fr 240px;
                align-items: center;
                padding-bottom: 8px;
                border-bottom: 1px solid #333;
            }

            .print-logo {
                display: flex;
                align-items: center;
                justify-content: flex-start;
            }

            .print-logo img {
                width: 210px;
                height: auto;
                max-height: 70px;
                object-fit: contain;
                object-position: left center;
            }

            .print-company {
                text-align: center;
                line-height: 1.35;
            }

            .print-company h1 {
                font-size: 28px;
                font-weight: 700;
                margin: 0 0 4px;
                text-transform: uppercase;
                letter-spacing: .3px;
            }

            .print-company p {
                font-size: 16px;
                margin: 2px 0;
                font-weight: 400;
            }

            .print-motto {
                display: flex;
                justify-content: flex-end;
                align-items: center;
            }

            .print-motto img {
                width: 64px;
                height: 64px;
                object-fit: contain;
            }

            .print-line,
            .print-title,
            .print-info,
            .print-footer-line,
            .print-footer {
                display: none;
            }

            /* ===== TABEL (14 kolom, font diperkecil agar muat di A4 landscape) ===== */
            .print-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                table-layout: fixed;
                margin-top: 16px;
                border-top: 2px solid #666;
                border-left: 2px solid #666;
            }

            .print-table th {
                background: #9b2f2f !important;
                color: #fff !important;
                border: 0 !important;
                border-right: 2px solid #c98f8f !important;
                border-bottom: 2px solid #666 !important;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 11px !important;
                font-weight: 700 !important;
                text-align: center !important;
                padding: 6px 4px !important;
                line-height: 15px;
                white-space: normal !important;
            }

            .print-table td {
                background: #fff !important;
                border: 0 !important;
                border-right: 2px solid #666 !important;
                border-bottom: 2px solid #666 !important;
                padding: 5px 5px !important;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 11px !important;
                color: #111 !important;
                vertical-align: middle !important;
                text-align: left !important;
                line-height: 15px;
                word-wrap: break-word;
                overflow-wrap: anywhere;
            }

            .print-table thead { display: table-header-group; }

            .print-table tr {
                page-break-inside: avoid;
            }

            .print-table th:last-child { border-right: 2px solid #666 !important; }

            .print-table th:nth-child(1),  .print-table td:nth-child(1)  { width: 3%; text-align: center !important; }
            .print-table th:nth-child(2),  .print-table td:nth-child(2)  { width: 7%; }
            .print-table th:nth-child(3),  .print-table td:nth-child(3)  { width: 6%; }
            .print-table th:nth-child(4),  .print-table td:nth-child(4)  { width: 10%; }
            .print-table th:nth-child(5),  .print-table td:nth-child(5)  { width: 6%; }
            .print-table th:nth-child(6),  .print-table td:nth-child(6)  { width: 6%; }
            .print-table th:nth-child(7),  .print-table td:nth-child(7)  { width: 6%; }
            .print-table th:nth-child(8),  .print-table td:nth-child(8)  { width: 6%; }
            .print-table th:nth-child(9),  .print-table td:nth-child(9)  { width: 11%; }
            .print-table th:nth-child(10), .print-table td:nth-child(10) { width: 9%; }
            .print-table th:nth-child(11), .print-table td:nth-child(11) { width: 8%; }
            .print-table th:nth-child(12), .print-table td:nth-child(12) { width: 8%; }
            .print-table th:nth-child(13), .print-table td:nth-child(13) { width: 7%; }
            .print-table th:nth-child(14), .print-table td:nth-child(14) { width: 7%; }

            .print-table .nama-karyawan {
                line-height: 15px;
                white-space: normal;
            }

            .print-job-name {
                font-weight: 400;
                font-size: 11px;
            }

            .print-order-number {
                font-size: 10px;
                color: #333;
                margin-top: 1px;
            }

            .print-status {
                font-size: 11px;
                font-weight: 400;
                white-space: normal;
                display: inline;
                color: #111 !important;
                background: none !important;
                padding: 0 !important;
            }

            .print-empty {
                text-align: center !important;
                padding: 18px !important;
            }

            /* ===== TANGGAL & TOTAL ===== */
            .print-date {
                margin-top: 10px;
                font-size: 15px;
                font-weight: 400;
            }

            .print-total {
                margin-top: 4px;
                font-size: 15px;
                font-weight: 700;
            }

            .print-total span {
                font-weight: 400;
                margin-left: 4px;
            }

            /* ===== TANDA TANGAN ===== */
            .print-signature {
                display: grid;
                grid-template-columns: 1fr 1fr;
                margin-top: 12px;
                page-break-inside: avoid;
            }

            .signature-box {
                text-align: center;
                font-size: 15px;
                line-height: 1.5;
            }

            .signature-space {
                height: 24px;
            }

            .signature-line {
                font-size: 15px;
                margin-bottom: 3px;
            }

            .signature-name {
                font-size: 15px;
            }
        }

        @media (max-width: 1000px) {
            .filter-row {
                grid-template-columns: 1fr 1fr;
            }
        }

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
            .filter-row {
                grid-template-columns: 1fr;
            }

            .table-head {
                align-items: flex-start;
                gap: 12px;
                flex-direction: column;
            }
        }

        /* =====================================================
           DARK MODE (palet sama dengan halaman karyawan)
           ===================================================== */
        @media screen {
        html:has(body.dark-mode),html:has(body.dark-mode) body{background:#0f141b!important}
        html body.dark-mode{background:#0f141b!important;color:#e8edf4!important}
        html body.dark-mode .main,html body.dark-mode .content{background:#0f141b!important}

        html body.dark-mode .page-title h2,
        html body.dark-mode .filter-title,
        html body.dark-mode .table-head h3{color:#edf2f7!important}
        html body.dark-mode .page-title p,
        html body.dark-mode .empty-data{color:#8f9bac!important}

        html body.dark-mode .filter-card,
        html body.dark-mode .table-card{background:#151b23!important;border-color:#2c3542!important;box-shadow:none!important;color:#e8edf4!important}
        html body.dark-mode .table-head{border-bottom-color:#2c3542!important}
        html body.dark-mode .table-wrapper{background:#151b23!important}

        html body.dark-mode .filter-group label{color:#8f9bac!important}
        html body.dark-mode .filter-group select,
        html body.dark-mode .filter-group input{background:#202733!important;color:#e8edf4!important;border-color:#303947!important;color-scheme:dark}
        html body.dark-mode .filter-group select option{background:#202733!important;color:#e8edf4!important}
        html body.dark-mode .filter-group select:focus,
        html body.dark-mode .filter-group input:focus{border-color:#b51f2a!important}
        html body.dark-mode input::placeholder{color:#7f8998!important}

        html body.dark-mode .btn-filter{background:#b51f2a!important;color:#fff!important}
        html body.dark-mode .btn-filter:hover{background:#941923!important}
        html body.dark-mode .btn-print{background:#202733!important;color:#e8edf4!important;border-color:#303947!important}
        html body.dark-mode .btn-print:hover{background:#2a3342!important}

        html body.dark-mode table{background:#151b23!important}
        html body.dark-mode th{background:#b51f2a!important;color:#fff!important;border-bottom-color:#b51f2a!important}
        html body.dark-mode td{background:#151b23!important;color:#fff!important;border-bottom-color:#2c3542!important}
        html body.dark-mode tbody tr:hover td{background:#202733!important}
        html body.dark-mode table td:first-child{color:#cbd2dc!important}
        html body.dark-mode .job-name{color:#fff!important;font-weight:400!important}
        html body.dark-mode .order-number{color:#8f9bac!important}
        html body.dark-mode .table-text{color:#fff!important}

        html body.dark-mode .footer-note{color:#747e8c!important}
        }
    </style>
</head>

<body>

    <?php include "../includes/admin_divisi/sidebar.php"; ?>

    <div class="main">

        <?php include "../includes/admin_divisi/topbar.php"; ?>

        <div class="content">

            <div class="page-title">
                <h2>Laporan Pekerjaan</h2>
                <p>Menampilkan laporan pekerjaan divisi <?= htmlspecialchars($nama_divisi, ENT_QUOTES, 'UTF-8'); ?>.</p>
            </div>

            <form method="GET" action="laporan.php">

                <div class="filter-card">

                    <div class="filter-title">Filter Laporan</div>

                    <div class="filter-row">

                        <div class="filter-group">
                            <label>Karyawan</label>
                            <select name="id_karyawan">
                                <option value="">Semua Karyawan</option>
                                <?php while ($k = mysqli_fetch_assoc($query_karyawan)): ?>
                                    <option
                                        value="<?= $k['id_user']; ?>"
                                        <?= ((string) $filter_karyawan === (string) $k['id_user']) ? 'selected' : ''; ?>
                                    >
                                        <?= htmlspecialchars($k['nama'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">Semua Status</option>
                                <option value="Belum Dikerjakan" <?= ($filter_status == 'Belum Dikerjakan') ? 'selected' : ''; ?>>Belum Dikerjakan</option>
                                <option value="Menunggu Konfirmasi" <?= ($filter_status == 'Menunggu Konfirmasi') ? 'selected' : ''; ?>>Menunggu Konfirmasi</option>
                                <option value="Perlu Perbaikan" <?= ($filter_status == 'Perlu Perbaikan') ? 'selected' : ''; ?>>Perlu Perbaikan</option>
                                <option value="Selesai" <?= ($filter_status == 'Selesai') ? 'selected' : ''; ?>>Selesai</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Tipe Order</label>
                            <select name="tipe_order">
                                <option value="">Semua Tipe Order</option>
                                <option value="Order Masuk" <?= ($filter_tipe_order == 'Order Masuk') ? 'selected' : ''; ?>>Order Masuk</option>
                                <option value="Order Perbaikan" <?= ($filter_tipe_order == 'Order Perbaikan') ? 'selected' : ''; ?>>Order Perbaikan</option>
                                <option value="Non Order" <?= ($filter_tipe_order == 'Non Order') ? 'selected' : ''; ?>>Non Order</option>
                                <option value="Maintenance" <?= ($filter_tipe_order == 'Maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Jenis Pekerjaan</label>
                            <select name="jenis">
                                <option value="">Semua Jenis</option>
                                <option value="Individu" <?= ($filter_jenis == 'Individu') ? 'selected' : ''; ?>>Individu</option>
                                <option value="Tim" <?= ($filter_jenis == 'Tim') ? 'selected' : ''; ?>>Tim</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Tanggal Awal</label>
                            <input type="date" name="tgl_awal" value="<?= htmlspecialchars($filter_tgl_awal, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="filter-group">
                            <label>Tanggal Akhir</label>
                            <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($filter_tgl_akhir, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <button type="submit" class="btn-filter">Tampilkan</button>

                    </div>

                </div>

            </form>

            <div class="table-card">

                <div class="table-head">
                    <h3>Data Laporan Pekerjaan</h3>
                    <div class="head-actions">
                        <a href="<?= htmlspecialchars($link_excel, ENT_QUOTES, 'UTF-8'); ?>" class="btn-print">Cetak Excel</a>
                        <button type="button" class="btn-print" onclick="window.print()">Cetak Laporan</button>
                    </div>
                </div>

                <div class="table-wrapper">

                    <table>

                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No. Order</th>
                                <th>Tipe Order</th>
                                <th>Nama Pekerjaan</th>
                                <th>Pelapor</th>
                                <th>Dibuat Oleh</th>
                                <th>Dept</th>
                                <th>Jenis</th>
                                <th>Uraian Kerusakan</th>
                                <th>Dikerjakan Oleh</th>
                                <th>Tgl/Jam Mulai</th>
                                <th>Tgl/Jam Selesai</th>
                                <th>Status</th>
                                <th>Item Pekerjaan</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php
                            $no = 1;

                            if ($query_laporan && mysqli_num_rows($query_laporan) > 0):

                                while ($row = mysqli_fetch_assoc($query_laporan)):

                                    $status_label = statusTampil($row['status'] ?? '');
                                    $status_class = 'status-belum';

                                    if ($status_label == 'Sedang Dikerjakan') {
                                        $status_class = 'status-sedang';
                                    } elseif ($status_label == 'Menunggu Konfirmasi') {
                                        $status_class = 'status-menunggu';
                                    } elseif ($status_label == 'Perlu Perbaikan') {
                                        $status_class = 'status-perbaikan';
                                    } elseif ($status_label == 'Selesai') {
                                        $status_class = 'status-selesai';
                                    }
                            ?>

                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td><span class="table-text"><?= !empty($row['nomor_order']) ? htmlspecialchars($row['nomor_order'], ENT_QUOTES, 'UTF-8') : '-'; ?></span></td>
                                    <td><span class="table-text"><?= htmlspecialchars($row['tipe_order'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><div class="job-name"><?= htmlspecialchars($row['nama_pekerjaan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div></td>
                                    <td><span class="table-text"><?= htmlspecialchars(!empty($row['nama_pelapor']) ? $row['nama_pelapor'] : (!empty($row['nama_pelapor_f']) ? $row['nama_pelapor_f'] : '-'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><span class="table-text"><?= htmlspecialchars(pembuatTampil($row), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><span class="table-text"><?= htmlspecialchars(!empty($row['departemen_asal']) ? $row['departemen_asal'] : (!empty($row['dept_pembuat']) ? $row['dept_pembuat'] : ($row['nama_divisi'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><span class="table-text"><?= htmlspecialchars($row['jenis_pekerjaan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><span class="table-text"><?= htmlspecialchars($row['uraian_kerusakan'] ?? $row['deskripsi'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?= tampilkanKaryawan($row['nama_karyawan'] ?? '-'); ?></td>
                                    <td><span class="table-text"><?= formatTanggalWaktu($row['tanggal_mulai']); ?></span></td>
                                    <td><span class="table-text"><?= in_array(($row['status'] ?? ''), ['Menunggu Konfirmasi', 'Selesai'], true) ? formatTanggalWaktu($row['jam_bukti_terakhir']) : '-'; ?></span></td>
                                    <td><span class="status <?= $status_class; ?>"><?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><span class="table-text"><?= !empty($row['item_terakhir']) ? htmlspecialchars($row['item_terakhir'], ENT_QUOTES, 'UTF-8') : 'Belum ada'; ?></span></td>
                                </tr>

                            <?php endwhile; else: ?>

                                <tr>
                                    <td colspan="14" class="empty-data">Tidak ada data laporan yang ditemukan.</td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <div class="footer-note">
                &copy; <?= date("Y"); ?> SIMO METRP. All rights reserved.
            </div>

        </div>

    </div>

    <!-- =====================================================
         AREA CETAK LAPORAN
         ===================================================== -->
    <div class="print-report">

        <div class="print-header">
            <div class="print-logo">
                <img src="../assets/img/logo-sosro.jpeg" alt="Sinar Sosro Gunung Slamat">
            </div>
            <div class="print-company">
                <h1>LAPORAN PEKERJAAN</h1>
                <p>Sistem Informasi Monitoring Pekerjaan (SIMO)</p>
                <p>PT Sinar Sosro Gunung Slamat</p>
            </div>
            <div class="print-motto">
                <img src="../assets/img/logo-login.jpeg" alt="Logo">
            </div>
        </div>

        <table class="print-table">

            <thead>
                <tr>
                    <th>No</th>
                    <th>No. Order</th>
                    <th>Tipe Order</th>
                    <th>Nama Pekerjaan</th>
                    <th>Pelapor</th>
                    <th>Dibuat Oleh</th>
                    <th>Dept</th>
                    <th>Jenis</th>
                    <th>Uraian Kerusakan</th>
                    <th>Dikerjakan Oleh</th>
                    <th>Tgl/Jam Mulai</th>
                    <th>Tgl/Jam Selesai</th>
                    <th>Status</th>
                    <th>Item Pekerjaan</th>
                </tr>
            </thead>

            <tbody>

                <?php
                $no_print = 1;

                if ($query_print && mysqli_num_rows($query_print) > 0):

                    while ($row_print = mysqli_fetch_assoc($query_print)):

                        $status_print = statusTampil($row_print['status'] ?? '');
                ?>

                    <tr>
                        <td><?= $no_print++; ?></td>
                        <td><?= !empty($row_print['nomor_order']) ? htmlspecialchars($row_print['nomor_order'], ENT_QUOTES, 'UTF-8') : '-'; ?></td>
                        <td><?= htmlspecialchars($row_print['tipe_order'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><div class="print-job-name"><?= htmlspecialchars($row_print['nama_pekerjaan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div></td>
                        <td><?= htmlspecialchars(!empty($row_print['nama_pelapor']) ? $row_print['nama_pelapor'] : (!empty($row_print['nama_pelapor_f']) ? $row_print['nama_pelapor_f'] : '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars(pembuatTampil($row_print), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars(!empty($row_print['departemen_asal']) ? $row_print['departemen_asal'] : (!empty($row_print['dept_pembuat']) ? $row_print['dept_pembuat'] : ($row_print['nama_divisi'] ?? '-')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row_print['jenis_pekerjaan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($row_print['uraian_kerusakan'] ?? $row_print['deskripsi'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= tampilkanKaryawan($row_print['nama_karyawan'] ?? '-'); ?></td>
                        <td><?= formatTanggalWaktu($row_print['tanggal_mulai']); ?></td>
                        <td><?= in_array(($row_print['status'] ?? ''), ['Menunggu Konfirmasi', 'Selesai'], true) ? formatTanggalWaktu($row_print['jam_bukti_terakhir']) : '-'; ?></td>
                        <td><span class="<?= statusClassPrint($status_print); ?>"><?= htmlspecialchars($status_print, ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><?= !empty($row_print['item_terakhir']) ? htmlspecialchars($row_print['item_terakhir'], ENT_QUOTES, 'UTF-8') : 'Belum ada'; ?></td>
                    </tr>

                <?php endwhile; else: ?>

                    <tr>
                        <td colspan="14" class="print-empty">Tidak ada data pekerjaan.</td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

        <div class="print-date">
            Dibuat pada : <?= tanggalIndonesia(date('Y-m-d')); ?>
        </div>

        <div class="print-total">
            Total Data : <span><?= $total_data; ?> Pekerjaan</span>
        </div>

        <div class="print-signature">
            <div class="signature-box">
                Dibuat Oleh :
                <div class="signature-space"></div>
                <div class="signature-line">( ............................................................ )</div>
                <div class="signature-name">Admin Divisi <?= htmlspecialchars($nama_divisi, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="signature-box">
                Diperiksa Oleh :
                <div class="signature-space"></div>
                <div class="signature-line">( ............................................................ )</div>
                <div class="signature-name">Nama :<br>Jabatan :</div>
            </div>
        </div>

    </div>

</body>

</html>
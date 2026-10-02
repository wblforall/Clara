<?php
require_once __DIR__ . '/vendor/autoload.php';

$mysqli = new mysqli("127.0.0.1", "root", "", "clara_unified");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Summary query
$summaryQuery = "SELECT created_by, COUNT(*) as total, MIN(start_date) as first_date, MAX(start_date) as last_date FROM transactions WHERE recurring_flag = 1 GROUP BY created_by";
$summaryResult = $mysqli->query($summaryQuery);

$summaryRows = [];
while($row = $summaryResult->fetch_assoc()) {
    $summaryRows[] = $row;
}

// Detail query
$detailQuery = "SELECT id, module, master_code, start_date, created_by, created_at FROM transactions WHERE recurring_flag = 1 ORDER BY start_date ASC";
$detailResult = $mysqli->query($detailQuery);

$detailRows = [];
while($row = $detailResult->fetch_assoc()) {
    $detailRows[] = $row;
}

$html = '
<style>
    body { font-family: sans-serif; font-size: 12pt; }
    h1 { color: #333; text-align: center; }
    h2 { color: #555; margin-top: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>
<h1>Laporan Transaksi Recurring (Manual)</h1>
<p>Berdasarkan pengecekan di database, terdapat data transaksi yang flag "recurring"-nya dicentang secara manual.</p>

<h2>Ringkasan (Siapa Saja)</h2>
<table>
    <tr>
        <th>Nama / User</th>
        <th>Total Transaksi</th>
        <th>Tanggal Mulai (Paling Awal)</th>
        <th>Tanggal Mulai (Terakhir)</th>
    </tr>';

foreach ($summaryRows as $row) {
    $html .= '<tr>
        <td>' . htmlspecialchars($row['created_by']) . '</td>
        <td>' . htmlspecialchars($row['total']) . '</td>
        <td>' . htmlspecialchars($row['first_date']) . '</td>
        <td>' . htmlspecialchars($row['last_date']) . '</td>
    </tr>';
}

$html .= '</table>

<h2>Detail Transaksi</h2>
<table>
    <tr>
        <th>ID</th>
        <th>Module</th>
        <th>Kode Master</th>
        <th>Start Date</th>
        <th>Diinput Oleh</th>
        <th>Waktu Input</th>
    </tr>';

foreach ($detailRows as $row) {
    $html .= '<tr>
        <td>' . htmlspecialchars($row['id']) . '</td>
        <td>' . htmlspecialchars($row['module']) . '</td>
        <td>' . htmlspecialchars($row['master_code']) . '</td>
        <td>' . htmlspecialchars($row['start_date']) . '</td>
        <td>' . htmlspecialchars($row['created_by']) . '</td>
        <td>' . htmlspecialchars($row['created_at']) . '</td>
    </tr>';
}

$html .= '</table>';

$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML($html);
$mpdf->Output(__DIR__ . '/public/report_recurring.pdf', \Mpdf\Output\Destination::FILE);

echo "PDF created successfully!";

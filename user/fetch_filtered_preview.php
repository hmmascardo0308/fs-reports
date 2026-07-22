<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$mainzone = trim($data['mainzone'] ?? '');
$zone = trim($data['zone'] ?? '');
$region = trim($data['region'] ?? '');
$transaction_month = trim($data['transaction_month'] ?? '');
$transaction_year = trim($data['transaction_year'] ?? '');

$where = [];
$params = [];
$types = '';

if ($mainzone !== '') {
    $where[] = "cr.mainzone = ?";
    $types .= 's';
    $params[] = $mainzone;
}

if ($zone !== '') {
    $where[] = "cr.zone = ?";
    $types .= 's';
    $params[] = $zone;
}

if ($region !== '') {
    $where[] = "cr.region = ?";
    $types .= 's';
    $params[] = $region;
}

if ($transaction_year !== '' && is_numeric($transaction_year)) {
    $where[] = "cr.transaction_year = ?";
    $types .= 'i';
    $params[] = (int)$transaction_year;
}

if ($transaction_month !== '') {
    $where[] = "cr.transaction_month = ?";
    $types .= 's';
    $params[] = $transaction_month . '-01';
}

// Exclude voided transactions
$where[] = "(cr.status_void IS NULL OR cr.status_void != 'Void')";
$where[] = "gc.gl_description_comparative IS NOT NULL";
$where[] = "gc.gl_description_comparative != ''";

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        gc.sort_order,
        gc.sub_order,
        gc.gl_description_comparative AS comp,
        SUM(CASE WHEN cr.transaction_type = 'Branch' THEN cr.amount ELSE 0 END) AS mlfsi,
        SUM(CASE WHEN cr.transaction_type = 'Showroom' THEN cr.amount ELSE 0 END) AS jewelers
    FROM fs_reports.gl_codes gc
    JOIN fs_reports.comparative_report cr
      ON cr.gl_code = gc.gl_code
    $where_sql
    GROUP BY gc.sort_order, gc.sub_order, gc.gl_description_comparative
    ORDER BY gc.sort_order ASC, gc.sub_order ASC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Prepare failed']);
    exit;
}

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $mlfsi = (float)($row['mlfsi'] ?? 0);
    $jewelers = (float)($row['jewelers'] ?? 0);
    $rows[] = [
        'sort_order' => (string)($row['sort_order'] ?? ''),
        'sub_order' => (string)($row['sub_order'] ?? ''),
        'comp' => $row['comp'] ?? '',
        'mlfsi' => $mlfsi,
        'jewelers' => $jewelers,
        'total' => $mlfsi + $jewelers
    ];
}

mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'rows' => $rows
]);
?>

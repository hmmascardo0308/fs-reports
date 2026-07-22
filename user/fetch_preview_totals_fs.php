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

$years = $data['years'] ?? [];
$month1 = trim($data['month1'] ?? '');
$month2 = trim($data['month2'] ?? '');
$month3 = trim($data['month3'] ?? '');

if (!is_array($years)) $years = [];

$years = array_map('intval', array_filter($years, fn($y) => is_numeric($y)));

$use_month = $month1 !== '' || $month2 !== '' || $month3 !== '';
$periods = [];
if ($use_month) {
    if ($month1 !== '') $periods[] = $month1;
    if ($month2 !== '') $periods[] = $month2;
    if ($month3 !== '') $periods[] = $month3;
    // Remove any duplicates
    $periods = array_unique($periods);
}

$where = [];
$params = [];
$types = '';

if (!empty($years)) {
    $placeholders = implode(',', array_fill(0, count($years), '?'));
    $where[] = "cr.transaction_year IN ($placeholders)";
    $types .= str_repeat('i', count($years));
    $params = array_merge($params, $years);
}

$period_select = "cr.transaction_year AS period_key,";
$group_period = "cr.transaction_year,";
$current_period = null;
$period2 = null;
$period3 = null;
$current_label = '(Primary Period)';
$period2_label = '(Period 2)';
$period3_label = '(Period 3)';

if ($use_month && !empty($periods)) {
    $placeholders = implode(',', array_fill(0, count($periods), '?'));
    // Optimize: Compare dates directly (YYYY-MM-01) instead of using DATE_FORMAT function
    $where[] = "cr.transaction_month IN ($placeholders)";
    $types .= str_repeat('s', count($periods));
    // Append '-01' to each period (YYYY-MM) to match the stored DATE format
    $params = array_merge($params, array_map(fn($p) => $p . '-01', $periods));

    $period_select = "DATE_FORMAT(cr.transaction_month, '%Y-%m') AS period_key,";
    $group_period = "DATE_FORMAT(cr.transaction_month, '%Y-%m'),";

    usort($periods, fn($a, $b) => $b <=> $a); // newest first
    $current_period = $periods[0] ?? null;
    $period2 = $periods[1] ?? null;
    $period3 = $periods[2] ?? null;

    if ($current_period) $current_label = date('F Y', strtotime($current_period . '-01'));
    if ($period2) $period2_label = date('F Y', strtotime($period2 . '-01'));
    if ($period3) $period3_label = date('F Y', strtotime($period3 . '-01'));
} else if (!empty($years)) {
    usort($years, fn($a, $b) => $b - $a);
    $current_period = $years[0] ?? null;
    $period2 = $years[1] ?? null;
    $period3 = $years[2] ?? null;
    $current_label = $current_period ? (string)$current_period : '(Current Year)';
    $period2_label = $period2 ? (string)$period2 : '(Year 2)';
    $period3_label = $period3 ? (string)$period3 : '(Year 3)';
}

// Exclude voided transactions
$where[] = "(cr.status_void IS NULL OR cr.status_void != 'Void')";

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        gc.map_key AS map_key,
        gc.gl_description_comparative AS comp,
        '_all' AS area,
        $period_select
        CASE
            WHEN cr.transaction_type = 'Branch' THEN 'mlfsi'
            WHEN cr.transaction_type = 'Showroom' THEN 'jewelers'
            ELSE 'mlfsi'
        END AS branch_type,
        SUM(cr.amount) AS total
    FROM (
        SELECT DISTINCT
            COALESCE(NULLIF(gl_mapping, ''), gl_description_comparative) AS map_key,
            gl_description_comparative,
            gl_code
        FROM fs_reports.gl_codes_ho_new
        WHERE gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
          AND gl_code IS NOT NULL
          AND gl_code != ''
    ) gc
    JOIN fs_reports.comparative_report cr
      ON cr.gl_code = gc.gl_code
    $where_sql
    GROUP BY map_key, comp, $group_period branch_type
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
$totals = [];
while ($row = mysqli_fetch_assoc($result)) {
    $area = $row['area'] ?? '_all';
    $map_key = $row['map_key'] ?? '';
    $period = $row['period_key'] ?? '';
    $branch = $row['branch_type'] ?? 'mlfsi';
    $total = (float)($row['total'] ?? 0);

    $totals[$area][$map_key][$period][$branch] = $total;
}

mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'totals' => $totals,
    'current_period' => $current_period,
    'period2' => $period2,
    'period3' => $period3,
    'current_label' => $current_label,
    'period2_label' => $period2_label,
    'period3_label' => $period3_label
]);
?>
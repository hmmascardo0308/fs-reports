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
$start_month = $data['start_month'] ?? '';
$end_month = $data['end_month'] ?? '';

if (!is_array($years)) $years = [];

$years = array_map('intval', array_filter($years, fn($y) => is_numeric($y)));

$start_month = is_numeric($start_month) ? (int)$start_month : 0;
$end_month = is_numeric($end_month) ? (int)$end_month : 0;
if ($start_month < 1 || $start_month > 12) $start_month = 0;
if ($end_month < 1 || $end_month > 12) $end_month = 0;

if ($start_month > 0 && $end_month === 0) $end_month = $start_month;
if ($end_month > 0 && $start_month === 0) $start_month = $end_month;
if ($start_month > 0 && $end_month > 0 && $end_month < $start_month) {
    $tmp = $start_month;
    $start_month = $end_month;
    $end_month = $tmp;
}

$use_month_range = $start_month > 0 && $end_month > 0;

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

if ($use_month_range) {
    $where[] = "MONTH(cr.transaction_month) BETWEEN ? AND ?";
    $types .= 'ii';
    $params[] = $start_month;
    $params[] = $end_month;

    $range_label = date('F', mktime(0, 0, 0, $start_month, 1)) . " - " . date('F', mktime(0, 0, 0, $end_month, 1));
    if (!empty($years)) {
        usort($years, fn($a, $b) => $b - $a);
        $current_period = $years[0] ?? null;
        $period2 = $years[1] ?? null;
        $period3 = $years[2] ?? null;
    }

    if ($current_period) $current_label = $range_label . ' ' . $current_period;
    if ($period2) $period2_label = $range_label . ' ' . $period2;
    if ($period3) $period3_label = $range_label . ' ' . $period3;
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

$getYear = fn($p) => $p ? substr((string)$p, 0, 4) : '';
$y1 = $getYear($current_period);
$y2 = $getYear($period2);
$y3 = $getYear($period3);

$variance1_label = ($y1 && $y2) ? "$y1 vs $y2" : "YEAR VS YEAR";
$variance2_label = ($y1 && $y3) ? "$y1 vs $y3" : "YEAR VS YEAR";

echo json_encode([
    'ok' => true,
    'totals' => $totals,
    'current_period' => $current_period,
    'period2' => $period2,
    'period3' => $period3,
    'current_label' => $current_label,
    'period2_label' => $period2_label,
    'period3_label' => $period3_label,
    'variance1_label' => $variance1_label,
    'variance2_label' => $variance2_label
]);
?>

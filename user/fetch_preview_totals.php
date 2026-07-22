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
$areas = $data['areas'] ?? [];
$years = $data['years'] ?? [];
$month1 = trim($data['month1'] ?? '');
$month2 = trim($data['month2'] ?? '');

if (!is_array($areas)) $areas = [];
if (!is_array($years)) $years = [];

$areas = array_values(array_filter($areas, fn($a) => trim((string)$a) !== ''));
$years = array_map('intval', array_filter($years, fn($y) => is_numeric($y)));

$use_month = $month1 !== '' || $month2 !== '';
$periods = [];
if ($use_month) {
    if ($month1 !== '') $periods[] = $month1;
    if ($month2 !== '') $periods[] = $month2;
    // Remove any duplicates if both months are the same
    $periods = array_unique($periods);
}

$area_keys = !empty($areas) ? $areas : ['_all'];
$area_labels = empty($areas) ? ['_all' => 'All Areas'] : array_combine($areas, $areas);

$where = [];
$params = [];
$types = '';

if ($region !== '') {
    $where[] = "cr.region = ?";
    $types .= 's';
    $params[] = $region;
}

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

if (!empty($areas)) {
    $placeholders = implode(',', array_fill(0, count($areas), '?'));
    $where[] = "cr.area IN ($placeholders)";
    $types .= str_repeat('s', count($areas));
    $params = array_merge($params, $areas);
}

if (!empty($years)) {
    $placeholders = implode(',', array_fill(0, count($years), '?'));
    $where[] = "cr.transaction_year IN ($placeholders)";
    $types .= str_repeat('i', count($years));
    $params = array_merge($params, $years);
}

$period_select = "cr.transaction_year AS period_key,";
$group_period = "cr.transaction_year,";
$current_period = null;
$previous_period = null;
$current_label = '(Primary Period)';
$previous_label = '(Previous Period)';

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
    $previous_period = $periods[1] ?? null;

    if ($current_period) $current_label = date('F Y', strtotime($current_period . '-01'));
    if ($previous_period) $previous_label = date('F Y', strtotime($previous_period . '-01'));
    else if ($current_period) $previous_label = '(No Previous Period)';
} else if (!empty($years)) {
    usort($years, fn($a, $b) => $b - $a);
    $current_period = $years[0] ?? null;
    $previous_period = $years[1] ?? null;
    $current_label = $current_period ? (string)$current_period : '(Primary Period)';
    $previous_label = $previous_period ? (string)$previous_period : '(Previous Period)';
}

// Exclude voided transactions
$where[] = "(cr.status_void IS NULL OR cr.status_void != 'Void')";

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$select_area = !empty($areas) ? "cr.area AS area," : "'_all' AS area,";
$group_area = !empty($areas) ? "cr.area," : "";

$sql = "
    SELECT
        gc.map_key AS map_key,
        gc.gl_description_comparative AS comp,
        $select_area
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
        FROM fs_reports.gl_codes_new
        WHERE gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
          AND gl_code IS NOT NULL
          AND gl_code != ''
    ) gc
    JOIN fs_reports.comparative_report cr
      ON cr.gl_code = gc.gl_code
    $where_sql
    GROUP BY map_key, comp, $group_area $group_period branch_type
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
    'area_keys' => $area_keys,
    'area_labels' => $area_labels,
    'current_period' => $current_period,
    'previous_period' => $previous_period,
    'current_label' => $current_label,
    'previous_label' => $previous_label
]);
?>

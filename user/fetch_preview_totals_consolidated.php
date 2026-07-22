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

$zone = trim($data['zone'] ?? '');
$branch_type = trim($data['branch_type'] ?? '');
$month = trim($data['month'] ?? '');
$year = trim($data['year'] ?? '');

// Build WHERE conditions
$where = [];
$params = [];
$types = '';

// Zone filter
if ($zone !== '') {
    $where[] = "cr.zone = ?";
    $types .= 's';
    $params[] = $zone;
}

// Branch type filter - MODIFIED: Handle the filtering logic
$branch_condition = '';
if ($branch_type !== '') {
    if ($branch_type === 'Branch') {
        // Only MLFSI branches
        $where[] = "cr.transaction_type = 'Branch'";
    } elseif ($branch_type === 'Showroom') {
        // Only Showrooms (JEWELERS)
        $where[] = "cr.transaction_type = 'Showroom'";
    }
    // If 'All Branch Types' is selected, no branch type filter is applied
}

// Month filter
if ($month !== '') {
    $where[] = "DATE_FORMAT(cr.transaction_month, '%Y-%m') = ?";
    $types .= 's';
    $params[] = $month;
}

// Year filter (if month not selected)
if ($month === '' && $year !== '') {
    $where[] = "cr.transaction_year = ?";
    $types .= 'i';
    $params[] = $year;
}

// Determine current and previous periods
$current_period = null;
$previous_period = null;
$current_label = '(Transaction Period)';
$previous_label = '(Previous Period)';

if ($month !== '') {
    // If specific month selected, get previous month
    $current_period = $month;
    $date_obj = DateTime::createFromFormat('Y-m', $month);
    if ($date_obj) {
        $date_obj->modify('-1 month');
        $previous_period = $date_obj->format('Y-m');
        $previous_label = date('F Y', strtotime($previous_period . '-01'));
    }
    $current_label = date('F Y', strtotime($current_period . '-01'));
} elseif ($year !== '') {
    // If year selected, get previous year
    $current_period = $year;
    $previous_period = $year - 1;
    $current_label = (string)$year;
    $previous_label = (string)($year - 1);
}

// Exclude voided transactions
$where[] = "(cr.status_void IS NULL OR cr.status_void != 'Void')";

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch regions for the selected zone
$regions = [];
if ($zone !== '') {
    $region_query = "SELECT DISTINCT region FROM fs_reports.comparative_report WHERE zone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
    $region_stmt = mysqli_prepare($conn, $region_query);
    if ($region_stmt) {
        mysqli_stmt_bind_param($region_stmt, 's', $zone);
        mysqli_stmt_execute($region_stmt);
        $region_result = mysqli_stmt_get_result($region_stmt);
        while ($region_row = mysqli_fetch_assoc($region_result)) {
            $regions[] = $region_row['region'];
        }
        mysqli_stmt_close($region_stmt);
    }
}

// Determine period grouping
$period_select = "";
$group_period = "";

if ($month !== '') {
    $period_select = "DATE_FORMAT(cr.transaction_month, '%Y-%m') AS period_key,";
    $group_period = "DATE_FORMAT(cr.transaction_month, '%Y-%m'),";
} else {
    $period_select = "cr.transaction_year AS period_key,";
    $group_period = "cr.transaction_year,";
}

$sql = "
    SELECT
        COALESCE(NULLIF(gc.gl_mapping, ''), gc.gl_description_comparative) AS map_key,
        gc.gl_description_comparative AS comp,
        $period_select
        cr.region,
        SUM(cr.amount) AS total
    FROM (
        SELECT DISTINCT
            gl_mapping,
            gl_description_comparative,
            gl_code
        FROM fs_reports.gl_codes_new
        WHERE gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
          AND gl_code IS NOT NULL
          AND gl_code != ''
    ) gc
    INNER JOIN fs_reports.comparative_report cr
        ON cr.gl_code = gc.gl_code
    $where_sql
    GROUP BY map_key, comp, $group_period cr.region
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Prepare failed: ' . mysqli_error($conn)]);
    exit;
}

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed: ' . mysqli_error($conn)]);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$totals = [];

while ($row = mysqli_fetch_assoc($result)) {
    $map_key = $row['map_key'] ?? '';
    $period = $row['period_key'] ?? '';
    $region = $row['region'] ?? '';
    $total = (float)($row['total'] ?? 0);
    
    // Store totals by map_key, period, and region (no branch type separation)
    if (!isset($totals[$map_key])) {
        $totals[$map_key] = [];
    }
    if (!isset($totals[$map_key][$period])) {
        $totals[$map_key][$period] = [];
    }
    if (!isset($totals[$map_key][$period][$region])) {
        $totals[$map_key][$period][$region] = 0;
    }
    $totals[$map_key][$period][$region] += $total;
}

mysqli_stmt_close($stmt);

echo json_encode([
    'ok' => true,
    'totals' => $totals,
    'regions' => $regions,
    'current_period' => $current_period,
    'previous_period' => $previous_period,
    'current_label' => $current_label,
    'previous_label' => $previous_label,
    'has_previous' => ($previous_period !== null),
    'branch_type' => $branch_type // Pass back the branch type for JavaScript to handle column display
]);
?>
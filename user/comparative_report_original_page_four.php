<?php
// comparative_report_original_page_four.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Filters
$mainzone = $_GET['mainzone'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$selected_period = $_GET['selected_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Error messages for validation
$error_message = '';

// Helper function to check if a month is March 2026 or earlier
function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

// Helper function to check if a month is April 2026 or later
function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

// Validate GL code mode
$show_error = false;
$valid_filters = false;

// Only validate if period is provided
if (!empty($selected_period)) {
    if ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($selected_period)) {
            $error_message = 'Old GL Code is only available for March 2026 and earlier. Selected period must be March 2026 or earlier.';
            $show_error = true;
        }
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($selected_period)) {
            $error_message = 'New GL Code is only available for April 2026 onwards. Selected period must be April 2026 or later.';
            $show_error = true;
        }
    }
    
    if (!$show_error) {
        $valid_filters = true;
    }
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: comparative_report_original_page_four.php");
    exit;
}

// Get distinct mainzones from database for the three tables
$distinct_mainzones = [];

$mainzone_query = "
    SELECT DISTINCT mainzone
    FROM fs_reports.comparative_report
    WHERE mainzone IS NOT NULL AND mainzone != ''
    ORDER BY mainzone
";
$mainzone_res = mysqli_query($conn, $mainzone_query);
if ($mainzone_res) {
    while ($m = mysqli_fetch_assoc($mainzone_res)) {
        $mz = trim((string)($m['mainzone'] ?? ''));
        if ($mz !== '' && !in_array($mz, $distinct_mainzones, true)) {
            $distinct_mainzones[] = $mz;
        }
    }
}

// If we have at least 3 mainzones, use the first 3, otherwise use what we have
// If we have fewer than 3, we'll use what's available
$selected_mainzones = array_slice($distinct_mainzones, 0, 3);

// If we have no mainzones in the database, use defaults
if (empty($selected_mainzones)) {
    $selected_mainzones = ['NATIONWIDE', 'LNCR', 'VISMIN'];
}

// Dropdown options - only years now since mainzone filter is removed
$distinct_years = [];

$years_query = "
    SELECT DISTINCT transaction_year
    FROM fs_reports.comparative_report
    WHERE transaction_year IS NOT NULL
    ORDER BY transaction_year DESC
";
$years_res = mysqli_query($conn, $years_query);
if ($years_res) {
    while ($y = mysqli_fetch_assoc($years_res)) {
        $val = trim((string)($y['transaction_year'] ?? ''));
        if ($val !== '' && !in_array($val, $distinct_years, true)) $distinct_years[] = $val;
    }
}

// ============================================================
// GET GL MAPPING based on GL Code Mode
// ============================================================
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];

$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes' : 'fs_reports.new_gl_codes';

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, gl_description_comparative, description
    FROM {$table_name}
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        $gl_id = $row['gl_id'] ?? '';

        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        $code = trim((string)($row['gl_code'] ?? ''));
        if ($code !== '' && !in_array($code, $gl_mapping[$key], true)) {
            $gl_mapping[$key][] = $code;
        }
        
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

/**
 * Fetch Head Office (manual adjustment) value for sort_order=26, sub_order=1
 * for a given mainzone and period.
 * - Empty mainzone => sum of NATIONWIDE (NATIONWIDE)
 * - Specific mainzone => that mainzone only
 */
function get_head_office_manual_adjustment(mysqli $conn, string $mainzone, string $selected_period): float {
    if (empty($selected_period)) {
        return 0.0;
    }

    $transaction_month = $selected_period . '-01';

    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
        // Total of both (or all) for the NATIONWIDE table
        $sql = "
            SELECT SUM(mlfsi + jewelers) AS total
            FROM fs_reports.manual_adjustment
            WHERE transaction_month = ?
              AND sort_order = 26
              AND sub_order = 1
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0.0;
        mysqli_stmt_bind_param($stmt, "s", $transaction_month);
    } else {
        // Specific mainzone (case-insensitive match)
        $sql = "
            SELECT SUM(mlfsi + jewelers) AS total
            FROM fs_reports.manual_adjustment
            WHERE transaction_month = ?
              AND sort_order = 26
              AND sub_order = 1
              AND LOWER(mainzone) = LOWER(?)
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0.0;
        mysqli_stmt_bind_param($stmt, "ss", $transaction_month, $mainzone);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $row && $row['total'] !== null ? floatval($row['total']) : 0.0;
}

function compute_table_rows_for_mainzone(mysqli $conn, string $mainzone, string $transaction_year, string $selected_period, string $gl_code_mode, array $gl_mapping, array $gl_descriptions, array $special_keys, array $sort_order_descriptions, bool $use_real_data = true): array {
    $where_conditions = [];
    $params = [];
    $types = "";

    if (!empty($mainzone)) {
        $where_conditions[] = "mainzone = ?";
        $params[] = $mainzone;
        $types .= "s";
    }
    if (!empty($transaction_year)) {
        $where_conditions[] = "transaction_year = ?";
        $params[] = $transaction_year;
        $types .= "s";
    }

    $base_where = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE 1=1";
    $base_where .= " AND (status_void IS NULL OR status_void != 'Void')";

    $period_data = [];
    if ($use_real_data && !empty($selected_period)) {
        $p_parts = explode('-', $selected_period);
        $p_year = $p_parts[0];
        $p_month_val = $selected_period . '-01';
        
        $period_sql = "
            SELECT 
                gl_code,
                SUM(CASE WHEN transaction_type = 'Branch' THEN amount ELSE 0 END) as branch_amount,
                SUM(CASE WHEN transaction_type = 'Showroom' THEN amount ELSE 0 END) as showroom_amount
            FROM fs_reports.comparative_report
            $base_where
            AND transaction_year = ? AND transaction_month = ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";
        $period_params = array_merge($params, [$p_year, $p_month_val]);
        $period_types = $types . "ss";
        
        $period_stmt = mysqli_prepare($conn, $period_sql);
        if ($period_stmt) {
            if (!empty($period_params)) {
                mysqli_stmt_bind_param($period_stmt, $period_types, ...$period_params);
            }
            mysqli_stmt_execute($period_stmt);
            $period_result = mysqli_stmt_get_result($period_stmt);
            while ($row = mysqli_fetch_assoc($period_result)) {
                $period_data[$row['gl_code']] = [
                    'mlfsi' => floatval($row['branch_amount']),
                    'jewelers' => floatval($row['showroom_amount']),
                    'total' => floatval($row['branch_amount']) + floatval($row['showroom_amount'])
                ];
            }
            mysqli_stmt_close($period_stmt);
        }
    }

    // Fetch Head Office value for sort_order=26 / sub_order=1 from manual_adjustment
    $head_office_26_1 = 0.0;
    if ($use_real_data && !empty($selected_period)) {
        $head_office_26_1 = get_head_office_manual_adjustment($conn, $mainzone, $selected_period);
    }

    $table_rows = [];

    foreach ($gl_mapping as $key => $codes) {
        [$sort_order, $sub_order] = explode('|', $key);

        // Hide sort_order = 23, sub_order = 23
        if ((string)$sort_order === '23' && (string)$sub_order === '23') {
            continue;
        }

        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys);

        $period_mlfsi = 0;
        $period_jewelers = 0;
        
        foreach ($codes as $gl_code) {
            if (isset($period_data[$gl_code])) {
                $period_mlfsi += $period_data[$gl_code]['mlfsi'];
                $period_jewelers += $period_data[$gl_code]['jewelers'];
            }
        }
        
        // For sort_order=26 / sub_order=1, put the manual adjustment into Head Office
        $head_office = 0.0;
        if ((string)$sort_order === '26' && (string)$sub_order === '1') {
            $head_office = $head_office_26_1;
        }
        
        // TOTAL = MLFSI + JEWELERS + HEAD OFFICE
        $period_total = $period_mlfsi + $period_jewelers + $head_office;
        
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_description' => $gl_description,
            'is_section_header' => false,
            'is_summary_row' => false,
            'period_mlfsi' => $period_mlfsi,
            'period_jewelers' => $period_jewelers,
            'period_total' => $period_total,
            'head_office' => $head_office,
            'is_inj2' => $is_inj2
        ];
    }

    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }

    $final_table_rows = [];
    $rev_mlfsi = 0; $rev_jew = 0; $rev_tot = 0; $rev_head = 0;
    $sa_mlfsi = 0; $sa_jew = 0; $sa_tot = 0; $sa_head = 0;
    $gp_mlfsi = 0; $gp_jew = 0; $gp_tot = 0; $gp_head = 0;
    $ebitda_mlfsi = 0; $ebitda_jew = 0; $ebitda_tot = 0; $ebitda_head = 0;
    $ebit_mlfsi = 0; $ebit_jew = 0; $ebit_tot = 0; $ebit_head = 0;
    $ebt_mlfsi = 0; $ebt_jew = 0; $ebt_tot = 0; $ebt_head = 0;
    $net_mlfsi = 0; $net_jew = 0; $net_tot = 0; $net_head = 0;
    $cos_mlfsi = 0; $cos_jew = 0; $cos_tot = 0; $cos_head = 0;
    $other_income_mlfsi = 0; $other_income_jew = 0; $other_income_tot = 0; $other_income_head = 0;

    foreach ($grouped_rows as $sort_order => $rows) {
        if (!in_array((int)$sort_order, [6, 8, 11])) {
            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }
        
        $total_period_mlfsi = array_sum(array_column($rows, 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($rows, 'period_jewelers'));
        $total_period_total = array_sum(array_column($rows, 'period_total'));
        $total_head_office = array_sum(array_column($rows, 'head_office'));

        // Calculate totals including head office
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;

        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
            $rev_mlfsi += $total_period_mlfsi;
            $rev_jew += $total_period_jewelers;
            $rev_tot += $group_total_with_head;
            $rev_head += $total_head_office;
        }
        
        // Cost of Sales (sort_order 21)
        if ((int)$sort_order == 21) {
            $cos_mlfsi = $total_period_mlfsi;
            $cos_jew = $total_period_jewelers;
            $cos_tot = $group_total_with_head;
            $cos_head = $total_head_office;
        }
        
        // Other Income (sort_order 22)
        if ((int)$sort_order == 22) {
            $other_income_mlfsi = $total_period_mlfsi;
            $other_income_jew = $total_period_jewelers;
            $other_income_tot = $group_total_with_head;
            $other_income_head = $total_head_office;
        }
        
        if ((int)$sort_order == 22 || (int)$sort_order == 23) {
            $sa_mlfsi += $total_period_mlfsi;
            $sa_jew += $total_period_jewelers;
            $sa_tot += $group_total_with_head;
            $sa_head += $total_head_office;
        }
        
        $description = isset($sort_order_descriptions[$sort_order]) 
            ? $sort_order_descriptions[$sort_order] 
            : "Total for Sort Order " . $sort_order;
        
        if (!in_array((int)$sort_order, [24, 25, 26])) {
            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'period_mlfsi' => $total_period_mlfsi,
                'period_jewelers' => $total_period_jewelers,
                'period_total' => $group_total_with_head,
                'head_office' => $total_head_office
            ];
        }

        if ((int)$sort_order == 20) {
            // TOTAL REVENUES = sum of sort_order 1-20
            $final_table_rows[] = [
                'sort_order' => 'TOTAL REVENUES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'period_mlfsi' => $rev_mlfsi,
                'period_jewelers' => $rev_jew,
                'period_total' => $rev_tot,
                'head_office' => $rev_head
            ];

            $final_table_rows[] = [
                'sort_order' => '',
                'sub_order' => 'Cost of Sales/Service',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true,
                'period_mlfsi' => null,
                'period_jewelers' => null,
                'period_total' => null,
                'head_office' => null
            ];
        }

        if ((int)$sort_order == 21) {
            // GROSS PROFIT = Revenues - Cost of Sales
            $gp_mlfsi = $rev_mlfsi - $cos_mlfsi;
            $gp_jew = $rev_jew - $cos_jew;
            $gp_tot = $rev_tot - $cos_tot;
            $gp_head = $rev_head - $cos_head;

            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'period_mlfsi' => $gp_mlfsi,
                'period_jewelers' => $gp_jew,
                'period_total' => $gp_tot,
                'head_office' => $gp_head
            ];

            $final_table_rows[] = [
                'sort_order' => 'SELLING & ADMIN EXPENSE',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true,
                'period_mlfsi' => null,
                'period_jewelers' => null,
                'period_total' => null,
                'head_office' => null
            ];
        }

        if ((int)$sort_order == 23) {
            $final_table_rows[] = [
                'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'period_mlfsi' => $sa_mlfsi,
                'period_jewelers' => $sa_jew,
                'period_total' => $sa_tot,
                'head_office' => $sa_head
            ];

            // EBITDA = Gross Profit - Selling & Admin + Other Income
            $ebitda_mlfsi = $gp_mlfsi - $sa_mlfsi;
            $ebitda_jew = $gp_jew - $sa_jew;
            $ebitda_tot = $gp_tot - $sa_tot;
            $ebitda_head = $gp_head - $sa_head;

            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'period_mlfsi' => $ebitda_mlfsi,
                'period_jewelers' => $ebitda_jew,
                'period_total' => $ebitda_tot,
                'head_office' => $ebitda_head
            ];
        }

        if ((int)$sort_order == 24) {
            // Depreciation & Amortization (sort_order 24)
            $dep_mlfsi = $total_period_mlfsi;
            $dep_jew = $total_period_jewelers;
            $dep_tot = $group_total_with_head;
            $dep_head = $total_head_office;
            
            // EBIT = EBITDA - Depreciation
            $ebit_mlfsi = $ebitda_mlfsi - $dep_mlfsi;
            $ebit_jew = $ebitda_jew - $dep_jew;
            $ebit_tot = $ebitda_tot - $dep_tot;
            $ebit_head = $ebitda_head - $dep_head;

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'period_mlfsi' => $ebit_mlfsi,
                'period_jewelers' => $ebit_jew,
                'period_total' => $ebit_tot,
                'head_office' => $ebit_head
            ];
        }

        if ((int)$sort_order == 25) {
            // Interest Expense (sort_order 25)
            $interest_mlfsi = $total_period_mlfsi;
            $interest_jew = $total_period_jewelers;
            $interest_tot = $group_total_with_head;
            $interest_head = $total_head_office;
            
            // EBT = EBIT - Interest
            $ebt_mlfsi = $ebit_mlfsi - $interest_mlfsi;
            $ebt_jew = $ebit_jew - $interest_jew;
            $ebt_tot = $ebit_tot - $interest_tot;
            $ebt_head = $ebit_head - $interest_head;

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'period_mlfsi' => $ebt_mlfsi,
                'period_jewelers' => $ebt_jew,
                'period_total' => $ebt_tot,
                'head_office' => $ebt_head
            ];
        }

        if ((int)$sort_order == 26) {
            // Income Tax (sort_order 26) - includes head office manual adjustment
            $tax_mlfsi = $total_period_mlfsi;
            $tax_jew = $total_period_jewelers;
            $tax_tot = $group_total_with_head;
            $tax_head = $total_head_office;
            
            // NET INCOME = EBT - Tax
            $net_mlfsi = $ebt_mlfsi - $tax_mlfsi;
            $net_jew = $ebt_jew - $tax_jew;
            $net_tot = $ebt_tot - $tax_tot;
            $net_head = $ebt_head - $tax_head;

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order' => 'TOTAL NET INCOME/LOSS',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'period_mlfsi' => $net_mlfsi,
                'period_jewelers' => $net_jew,
                'period_total' => $net_tot,
                'head_office' => $net_head
            ];
        }
    }

    return $final_table_rows;
}

// ============================================================
// BUILD THREE TABLES FROM MAINZONES LIST
// ============================================================
$tables = [];

// The first table should always be NATIONWIDE (empty string filter)
$all_mainzones_name = 'NATIONWIDE';

// Build NATIONWIDE table
$tables[] = [
    'mainzone' => $all_mainzones_name,
    'rows' => compute_table_rows_for_mainzone(
        $conn,
        '', // Empty string = NATIONWIDE
        $transaction_year,
        $selected_period,
        $gl_code_mode,
        $gl_mapping,
        $gl_descriptions,
        $special_keys,
        $sort_order_descriptions,
        $valid_filters
    ),
];

// Then add the first 2 specific mainzones (or whatever is available)
$specific_mainzones = array_slice($distinct_mainzones, 0, 2);

// If we don't have at least 2 specific mainzones, use defaults
if (count($specific_mainzones) < 2) {
    $defaults = ['LNCR', 'VISMIN'];
    foreach ($defaults as $default) {
        if (!in_array($default, $specific_mainzones, true)) {
            $specific_mainzones[] = $default;
        }
    }
    // Ensure we only have 2
    $specific_mainzones = array_slice($specific_mainzones, 0, 2);
}

// Build tables for specific mainzones
foreach ($specific_mainzones as $mz_name) {
    $tables[] = [
        'mainzone' => $mz_name,
        'rows' => compute_table_rows_for_mainzone(
            $conn,
            $mz_name,
            $transaction_year,
            $selected_period,
            $gl_code_mode,
            $gl_mapping,
            $gl_descriptions,
            $special_keys,
            $sort_order_descriptions,
            $valid_filters
        ),
    ];
}

// ============================================================
// PRE-CALCULATE TOTAL REVENUES FOR EACH TABLE
// ============================================================
$total_revenues_by_table = [];
foreach ($tables as $table_index => $table) {
    $total_revenues = 0;
    // First, try to find the TOTAL REVENUES summary row
    foreach ($table['rows'] as $row) {
        if (isset($row['is_summary_row']) && $row['is_summary_row'] === true && 
            isset($row['sort_order']) && $row['sort_order'] === 'TOTAL REVENUES') {
            $total_revenues = $row['period_total'] ?? 0;
            break;
        }
    }
    // If TOTAL REVENUES not found, fallback to sum of detail rows
    if ($total_revenues == 0) {
        foreach ($table['rows'] as $row) {
            if (!isset($row['is_summary_row']) || $row['is_summary_row'] !== true) {
                $total_revenues += abs($row['period_total'] ?? 0);
            }
        }
    }
    $total_revenues_by_table[$table_index] = $total_revenues;
}

// Predefined colors for mainzone headers (will cycle through if more than 3)
$header_colors = [
    '#ad1111', // Red
    '#ad1111', // Red
    '#ad1111', // Red
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Loss Statement - Three Regions</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Additional styles for three-table layout */
        .three-tables-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: auto;
            padding-bottom: 10px;
        }

        .three-tables-grid {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: nowrap;
            width: max-content;
            padding: 4px 2px 10px 2px;
        }

        .three-tables-grid .table-container {
            flex: 0 0 1200px;
            width: 1200px;
            max-height: 60vh;
            display: flex;
            flex-direction: column;
        }

        .three-tables-grid .data-table {
            width: 1190px;
            border-collapse: collapse;
        }

        /* Sticky header container */
        .table-sticky-header {
            position: sticky;
            top: 0;
            z-index: 20;
            background: white;
        }

        /* Table header title - sticky */
        .table-header-title {
            padding: 10px 15px;
            font-weight: 700;
            font-size: 14px;
            text-align: center;
            border-bottom: 2px solid #e0e0e0;
            letter-spacing: 0.5px;
            color: white !important;
            position: sticky;
            top: 0;
            z-index: 25;
        }

        /* Table header row - sticky */
        .data-table thead {
            position: sticky;
            top: 38px; /* Height of the title bar */
            z-index: 20;
        }

        .data-table thead th {
            padding: 8px 10px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            background: linear-gradient(45deg, #ff170f, #b50006);
            white-space: nowrap;
        }

        .three-tables-grid .table-container::-webkit-scrollbar {
            height: 8px;
            width: 8px;
        }

        .three-tables-grid .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .three-tables-grid .table-container::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .three-tables-grid .table-container::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .data-table td:first-child {
            text-align: center;
        }

        .data-table td:nth-child(4) {
            text-align: center;
        }

        /* Compact table for three columns */
        .three-tables-grid .data-table th,
        .three-tables-grid .data-table td {
            padding: 4px 8px;
            font-size: 12px;
        }

        .three-tables-grid .data-table .summary-row td {
            font-size: 12px;
        }

        @media (max-width: 1400px) {
            .three-tables-grid .table-container {
                flex: 0 0 900px;
                width: 900px;
            }
            .three-tables-grid .data-table {
                width: 890px;
            }
        }

        @media (max-width: 1024px) {
            .three-tables-grid .table-container {
                flex: 0 0 700px;
                width: 700px;
            }
            .three-tables-grid .data-table {
                width: 690px;
            }
            .three-tables-grid .data-table th,
            .three-tables-grid .data-table td {
                padding: 3px 5px;
                font-size: 10px;
            }
            .data-table thead {
                top: 38px; /* Smaller title height on mobile */
            }
        }

        .btn-collapse-three {
            background: linear-gradient(135deg, #5f43ff 0%, #002a79 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            margin-left: auto;
        }
        .btn-collapse-three:hover {
            transform: translateY(-2px);
            background-color: #001f50;
        }

        .filter-actions-three {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Remove mainzone filter group */
        .filter-group--hidden {
            display: none !important;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="settings.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-title">CONSOLIDATED PROFIT & LOSS STATEMENT</div>
            <div style="text-align: center; color: #666; font-size: 14px; margin-bottom: 20px;">
                <i class="fa-solid fa-layer-group"></i> Showing: <?php 
                    $display_names = array_map(function($t) { return $t['mainzone']; }, $tables);
                    echo implode(' &bull; ', $display_names);
                ?>
            </div>

            <!-- Error Banner for validation issues -->
            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <form method="GET" class="filter-form" id="filterForm" onsubmit="return validateForm()">
                <!-- Main Zone filter is hidden since the three tables are already displayed -->
                <div class="filter-group filter-group--hidden">
                    <label>Main Zone</label>
                    <select name="mainzone" id="mainzoneSelect">
                        <option value="">All Main Zones</option>
                    </select>
                </div>

                <div class="filter-group filter-group--gl-mode">
                    <label>GL Code</label>
                    <div class="radio-group" role="radiogroup" aria-label="GL Code Mode">
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="old" id="glOldRadio" <?= $gl_code_mode === 'old' ? 'checked' : '' ?>>
                            <span>Old GL Code</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="new" id="glNewRadio" <?= $gl_code_mode === 'new' ? 'checked' : '' ?>>
                            <span>New GL Code</span>
                        </label>
                    </div>
                </div>

                <div class="filter-group">
                    <label>Period</label>
                    <input type="month" name="selected_period" id="selectedPeriodSelect" value="<?= htmlspecialchars($selected_period) ?>">
                </div>

                <div class="filter-actions-three">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <a href="export_comparative_page_four.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                    <button type="button" class="btn-collapse-three" id="collapseBtnThree">
                        <i class="fa-solid fa-compress"></i> Collapse
                    </button>
                </div>
            </form>

            <!-- Three Tables Display -->
            <div class="three-tables-scroll">
                <div class="three-tables-grid">
                    <?php foreach ($tables as $index => $table): 
                        $mainzone_name = $table['mainzone'];
                        $color = $header_colors[$index % count($header_colors)];
                        // Get the pre-calculated total revenues for this table
                        $total_revenues = $total_revenues_by_table[$index] ?? 1;
                    ?>
                        <div class="table-container">
                            <!-- Sticky Title -->
                            <div class="table-header-title" style="background: <?= $color ?> !important;">
                                <i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($mainzone_name); ?>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                        <th>MLFSI</th>
                                        <th>JEWELERS</th>
                                        <th>HEAD OFFICE</th>
                                        <th></th>
                                        <th>TOTAL</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody class="report-tbody">
                                    <tr class="initial-spacer">
                                        <td colspan="10"></td>
                                    </tr>
                                    <tr class="revenues-header-row">
                                        <td style="background-color: #ff7f29; font-weight: bold;" colspan="10">REVENUES</td>
                                    </tr>
                                    <?php if (empty($table['rows'])): ?>
                                        <tr>
                                            <td colspan="10" style="text-align: center;">No data structure available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($table['rows'] as $row): 
                                            if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                                echo '<tr class="spacer-row" style="height: 15px;"><td colspan="10"></td></tr>';
                                                continue;
                                            }
                                            $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
                                            $is_header = !empty($row['is_section_header']);
                                            
                                            $period_mlfsi = $row['period_mlfsi'] ?? 0;
                                            $period_jewelers = $row['period_jewelers'] ?? 0;
                                            $period_total = $row['period_total'] ?? 0;
                                            $head_office = $row['head_office'] ?? 0;
                                            
                                            if (!$is_summary_row && !empty($row['is_inj2'])) {
                                                $period_mlfsi = -$period_mlfsi;
                                                $period_jewelers = -$period_jewelers;
                                                $period_total = -$period_total;
                                                $head_office = -$head_office;
                                            }
                                            
                                            // Calculate percentage based on TOTAL REVENUES
                                            $percentage = ($total_revenues > 0 && $period_total != 0) 
                                                ? ($period_total / $total_revenues) * 100 
                                                : 0;
                                        ?>
                                            <tr class="<?= $is_summary_row ? 'summary-row' : 'data-row' ?>"
                                                data-sort-order="<?= htmlspecialchars($row['sort_order'] ?? '') ?>"
                                                <?php if (!$is_summary_row): ?>
                                                    data-is-detail="true"
                                                <?php endif; ?>>
                                                <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"><?= $is_summary_row ? htmlspecialchars($row['sort_order']) : '' ?></td>
                                                <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>">
                                                    <?php if ($is_header): ?><strong><?= htmlspecialchars($row['sub_order']) ?></strong>
                                                    <?php elseif ($is_summary_row): ?><strong><?= htmlspecialchars($row['gl_description']) ?></strong>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="<?= $is_summary_row ? 'summary-cell summary-description' : '' ?>"><?= !$is_summary_row ? htmlspecialchars($row['gl_description']) : '' ?></td>
                                                <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                
                                                <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($period_mlfsi < 0) ? 'color: red;' : '' ?>">
                                                    <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($period_mlfsi, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                </td>
                                                <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($period_jewelers < 0) ? 'color: red;' : '' ?>">
                                                    <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($period_jewelers, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                </td>
                                                <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($head_office < 0) ? 'color: red;' : '' ?>">
                                                    <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($head_office, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                </td>
                                                <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($period_total < 0) ? 'color: red;' : '' ?>">
                                                    <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($period_total, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                </td>
                                                <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>">
                                                    <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($percentage, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                </td>
                                            </tr>
                                            <?php 
                                            if ($is_summary_row && !$is_header && empty($row['skip_spacer'])): 
                                            ?>
                                                <tr class="spacer-row" data-spacer-for="<?= htmlspecialchars($row['sort_order'] ?? '') ?>" style="height: 15px;"><td colspan="10"></td></tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <script>
                // Helper functions
                function isMarch2026OrEarlier(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-03-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate <= cutoff;
                }

                function isApril2026OrLater(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-04-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate >= cutoff;
                }

                let activeModal = null;

                function showModal(message) {
                    if (activeModal) {
                        activeModal.remove();
                    }
                    
                    const modalOverlay = document.createElement('div');
                    modalOverlay.className = 'modal-overlay';
                    modalOverlay.innerHTML = `
                        <div class="modal-container">
                            <div class="modal-header">
                                <h3><i class="fa-solid fa-triangle-exclamation"></i> Validation Error</h3>
                            </div>
                            <div class="modal-body">
                                <p>${escapeHtml(message)}</p>
                            </div>
                            <div class="modal-footer">
                                <button onclick="closeModal()">OK</button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modalOverlay);
                    activeModal = modalOverlay;
                }

                window.closeModal = function() {
                    if (activeModal) {
                        activeModal.remove();
                        activeModal = null;
                    }
                };

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                function validateForm() {
                    const selectedPeriod = document.getElementById('selectedPeriodSelect').value;
                    const glOldRadio = document.getElementById('glOldRadio');
                    const glNewRadio = document.getElementById('glNewRadio');
                    const glCodeMode = glOldRadio.checked ? 'old' : (glNewRadio.checked ? 'new' : 'old');

                    if (!selectedPeriod) {
                        return true;
                    }

                    if (glCodeMode === 'old') {
                        if (!isMarch2026OrEarlier(selectedPeriod)) {
                            showModal('Old GL Code is only available for March 2026 and earlier. Selected period must be March 2026 or earlier.');
                            return false;
                        }
                    } else if (glCodeMode === 'new') {
                        if (!isApril2026OrLater(selectedPeriod)) {
                            showModal('New GL Code is only available for April 2026 onwards. Selected period must be April 2026 or later.');
                            return false;
                        }
                    }

                    return true;
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const collapseBtn = document.getElementById('collapseBtnThree');
                    let isCollapsed = false;

                    if (collapseBtn) {
                        collapseBtn.addEventListener('click', function() {
                            isCollapsed = !isCollapsed;
                            
                            const tbodies = document.querySelectorAll('.report-tbody');
                            tbodies.forEach(tbody => {
                                const rows = Array.from(tbody.rows);
                                rows.forEach(row => {
                                    const sortOrder = row.getAttribute('data-sort-order');
                                    const isDetail = row.getAttribute('data-is-detail') === 'true';
                                    const spacerFor = row.getAttribute('data-spacer-for');
                                    
                                    const sortNum = parseInt(sortOrder);
                                    const is1To20 = !isNaN(sortNum) && sortNum >= 1 && sortNum <= 20;
                                    
                                    if (is1To20 && isDetail) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                    
                                    if (spacerFor) {
                                        const spacerNum = parseInt(spacerFor);
                                        if (!isNaN(spacerNum) && spacerNum >= 1 && spacerNum <= 20) {
                                            row.style.display = isCollapsed ? 'none' : '';
                                        }
                                    }

                                    if (row.classList.contains('revenues-header-row')) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }

                                    if (row.classList.contains('initial-spacer')) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                });
                            });

                            collapseBtn.innerHTML = isCollapsed 
                                ? '<i class="fa-solid fa-expand"></i> Uncollapse' 
                                : '<i class="fa-solid fa-compress"></i> Collapse';
                            
                            collapseBtn.style.backgroundColor = isCollapsed ? '#1f2937' : '#4b5563';
                        });
                    }
                });
            </script>

            <!-- Modal Styles (included in page) -->
            <style>
                .modal-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 1000;
                }
                .modal-container {
                    background: white;
                    border-radius: 12px;
                    width: 90%;
                    max-width: 450px;
                    box-shadow: 0 20px 35px rgba(0,0,0,0.2);
                    animation: modalSlideIn 0.2s ease-out;
                }
                @keyframes modalSlideIn {
                    from { opacity: 0; transform: translateY(-20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .modal-header {
                    padding: 20px 24px 12px 24px;
                    border-bottom: 1px solid #e5e7eb;
                }
                .modal-header h3 {
                    margin: 0;
                    font-size: 1.25rem;
                    font-weight: 600;
                    color: #dc2626;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .modal-header h3 i {
                    font-size: 1.5rem;
                }
                .modal-body {
                    padding: 20px 24px;
                    color: #374151;
                    font-size: 0.95rem;
                    line-height: 1.5;
                }
                .modal-footer {
                    padding: 12px 24px 20px 24px;
                    display: flex;
                    justify-content: flex-end;
                    border-top: 1px solid #e5e7eb;
                }
                .modal-footer button {
                    background: #dc2626;
                    border: none;
                    padding: 8px 20px;
                    border-radius: 6px;
                    color: white;
                    font-weight: 500;
                    cursor: pointer;
                    transition: background 0.2s;
                }
                .modal-footer button:hover {
                    background: #b91c1c;
                }
                .error-banner {
                    background: #fee2e2;
                    border: 1px solid #fecaca;
                    border-radius: 8px;
                    padding: 12px 16px;
                    margin-bottom: 20px;
                    color: #991b1b;
                    font-size: 0.9rem;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .error-banner i {
                    font-size: 1.2rem;
                }
            </style>

        </div>
    </main>

    <?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
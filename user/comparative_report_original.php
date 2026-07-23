<?php
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

// Filters (fs_reports.comparative_report)
$mainzone = $_GET['mainzone'] ?? '';
$zone = $_GET['zone'] ?? '';
$selected_regions = $_GET['region'] ?? [];
$selected_areas = $_GET['area'] ?? [];
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old'; // old|new|mixed
$gl_code_mode = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

// Error messages for validation
$error_message = '';

// Helper function to compare months (format: YYYY-MM)
function compareMonths(string $month1, string $month2): int {
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

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

// Validate periods and GL code mode
$show_error = false;
$valid_filters = false;

// Only validate if both periods are provided
if (!empty($primary_period) && !empty($previous_period)) {
    // Validation 1: Primary period must be greater than previous period
    if (compareMonths($primary_period, $previous_period) <= 0) {
        $error_message = 'Primary period must be later than the Previous period.';
        $show_error = true;
    }
    
    // Validation 2: GL code mode restrictions based on periods
    if (!$show_error) {
        if ($gl_code_mode === 'old') {
            // Old GL Code is only available for March 2026 and earlier
            if (!isMarch2026OrEarlier($primary_period) || !isMarch2026OrEarlier($previous_period)) {
                $error_message = 'Old GL Code is only available for March 2026 and earlier. Both selected periods must be March 2026 or earlier.';
                $show_error = true;
            }
        } elseif ($gl_code_mode === 'new') {
            // New GL Code is only available for April 2026 and later
            if (!isApril2026OrLater($primary_period) || !isApril2026OrLater($previous_period)) {
                $error_message = 'New GL Code is only available for April 2026 onwards. Both selected periods must be April 2026 or later.';
                $show_error = true;
            }
        }
    }
    
    // If no errors, set valid_filters to true
    if (!$show_error) {
        $valid_filters = true;
    }
}

if (!is_array($selected_regions)) {
    $selected_regions = $selected_regions !== '' ? [$selected_regions] : [];
}
if (!is_array($selected_areas)) {
    $selected_areas = $selected_areas !== '' ? [$selected_areas] : [];
}
$selected_regions = array_values(array_filter(array_map('trim', $selected_regions), fn($v) => $v !== ''));
$selected_areas = array_values(array_filter(array_map('trim', $selected_areas), fn($v) => $v !== ''));

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: comparative_report_original.php");
    exit;
}

// Dropdown options + hierarchy maps
$distinct_mz = [];
$distinct_zn = [];
$distinct_reg = [];
$distinct_area = [];
$distinct_years = [];
$month_options = [];

$mz_to_zn = [];
$mz_to_reg = []; // Add mainzone to region mapping
$zn_to_reg = [];
$reg_to_area = [];

$hierarchy_query = "
    SELECT DISTINCT mainzone, zone, region, area
    FROM fs_reports.comparative_report
    WHERE mainzone IS NOT NULL AND mainzone != ''
    ORDER BY mainzone, zone, region, area
";
$hierarchy_res = mysqli_query($conn, $hierarchy_query);
if ($hierarchy_res) {
    while ($h = mysqli_fetch_assoc($hierarchy_res)) {
        $mz = trim((string)($h['mainzone'] ?? ''));
        $zn = trim((string)($h['zone'] ?? ''));
        $rg = trim((string)($h['region'] ?? ''));
        $ar = trim((string)($h['area'] ?? ''));

        if ($mz !== '' && !in_array($mz, $distinct_mz, true)) $distinct_mz[] = $mz;
        if ($zn !== '' && !in_array($zn, $distinct_zn, true)) $distinct_zn[] = $zn;
        if ($rg !== '' && !in_array($rg, $distinct_reg, true)) $distinct_reg[] = $rg;
        if ($ar !== '' && !in_array($ar, $distinct_area, true)) $distinct_area[] = $ar;

        if ($mz !== '' && $zn !== '') {
            $mz_to_zn[$mz] = $mz_to_zn[$mz] ?? [];
            if (!in_array($zn, $mz_to_zn[$mz], true)) $mz_to_zn[$mz][] = $zn;
        }
        
        // Add mainzone to region mapping
        if ($mz !== '' && $rg !== '') {
            $mz_to_reg[$mz] = $mz_to_reg[$mz] ?? [];
            if (!in_array($rg, $mz_to_reg[$mz], true)) $mz_to_reg[$mz][] = $rg;
        }
        
        if ($zn !== '' && $rg !== '') {
            $zn_to_reg[$zn] = $zn_to_reg[$zn] ?? [];
            if (!in_array($rg, $zn_to_reg[$zn], true)) $zn_to_reg[$zn][] = $rg;
        }
        if ($rg !== '' && $ar !== '') {
            $reg_to_area[$rg] = $reg_to_area[$rg] ?? [];
            if (!in_array($ar, $reg_to_area[$rg], true)) $reg_to_area[$rg][] = $ar;
        }
    }
}
sort($distinct_mz);
sort($distinct_zn);
sort($distinct_reg);
sort($distinct_area);

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
$gl_mapping = []; // sort_order|sub_order -> ['old' => [gl_codes], 'new' => [gl_codes]]
$gl_descriptions = []; // sort_order|sub_order -> gl_description_comparative
$sort_order_descriptions = []; // sort_order -> description
$special_keys = []; // To track keys that have gl_id = 'INJ-2'

// Build lookup maps if mixed mode is active
$old_gl_id_to_codes = [];
$mixed_id_map = [];
if ($gl_code_mode === 'mixed') {
    // Load all old codes for lookup by gl_id
    $res = mysqli_query($conn, "SELECT gl_id, gl_code FROM fs_reports.gl_codes WHERE gl_code IS NOT NULL AND gl_code != ''");
    while ($row = mysqli_fetch_assoc($res)) {
        $old_gl_id_to_codes[$row['gl_id']][] = trim($row['gl_code']);
    }

    // Define mappings for mixed mode: new_gl_id => [old_gl_ids]
    $mixed_id_map = [
        'INS-1' => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
        'INS-2' => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
        'INS-3' => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
        'INS-4' => ['INS-27', 'INS-46'],
        'INS-5' => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
        'INS-6' => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
        'INS-7' => ['INS-15', 'INS-16','INS-17','INS-18','INS-19'],
        'INS-8' => ['INS-38'],
        'INS-9' => ['INS-48'],
        'INS-10' => ['INS-49']
    ];
}

// Determine which table to use for report structure
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

        // Check for INJ-2 in gl_id
        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        // Store gl_codes for this combination
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['old' => [], 'new' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        $code = trim((string)($row['gl_code'] ?? ''));

        if ($gl_code_mode === 'mixed') {
            // Mixed mode: new codes come from current row (new_gl_codes table)
            if ($code !== '' && !in_array($code, $gl_mapping[$key]['new'], true)) {
                $gl_mapping[$key]['new'][] = $code;
            }
            
            // Old codes come from ID mapping and lookup in old table
            $target_old_ids = $mixed_id_map[$gl_id] ?? [$gl_id];
            foreach ($target_old_ids as $oid) {
                if (isset($old_gl_id_to_codes[$oid])) {
                    foreach ($old_gl_id_to_codes[$oid] as $oc) {
                        if (!in_array($oc, $gl_mapping[$key]['old'], true)) {
                            $gl_mapping[$key]['old'][] = $oc;
                        }
                    }
                }
            }
        } else {
            // Old or New mode: 1:1 behavior (codes populate both buckets)
            if ($code !== '') {
                if (!in_array($code, $gl_mapping[$key]['old'], true)) {
                    $gl_mapping[$key]['old'][] = $code;
                }
                if (!in_array($code, $gl_mapping[$key]['new'], true)) {
                    $gl_mapping[$key]['new'][] = $code;
                }
            }
        }
        
        // Store sort_order description (for summary rows)
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

function compute_table_rows_for_region_area(mysqli $conn, string $mainzone, string $zone, string $transaction_year, string $primary_period, string $previous_period, string $gl_code_mode, array $gl_mapping, array $gl_descriptions, array $special_keys, array $sort_order_descriptions, string $region, string $area, bool $use_real_data = true): array {
// ============================================================
// BUILD WHERE CLAUSE FOR FILTERS
// ============================================================
$where_conditions = [];
$params = [];
$types = "";

if (!empty($mainzone)) {
    $where_conditions[] = "mainzone = ?";
    $params[] = $mainzone;
    $types .= "s";
}
if (!empty($zone)) {
    $where_conditions[] = "zone = ?";
    $params[] = $zone;
    $types .= "s";
}
if (!empty($region)) {
    $where_conditions[] = "region = ?";
    $params[] = $region;
    $types .= "s";
}
if (!empty($area)) {
    $where_conditions[] = "area = ?";
    $params[] = $area;
    $types .= "s";
}
if (!empty($transaction_year)) {
    $where_conditions[] = "transaction_year = ?";
    $params[] = $transaction_year;
    $types .= "s";
}

// Base filters for common conditions (Region, Area, etc.)
$base_where = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "WHERE 1=1";
// Exclude voided transactions
$base_where .= " AND (status_void IS NULL OR status_void != 'Void')";

// ============================================================
// FETCH PRIMARY PERIOD DATA
// ============================================================
$primary_data = []; // gl_code -> [branch_amount, showroom_amount, total]
if ($use_real_data && !empty($primary_period)) {
    $p_parts = explode('-', $primary_period);
    $p_year = $p_parts[0];
    $p_month_val = $primary_period . '-01'; // Match DATE format YYYY-MM-01 in DB
    
    $primary_sql = "
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
    $primary_params = array_merge($params, [$p_year, $p_month_val]);
    $primary_types = $types . "ss";
    
    $primary_stmt = mysqli_prepare($conn, $primary_sql);
    if ($primary_stmt) {
        if (!empty($primary_params)) {
            mysqli_stmt_bind_param($primary_stmt, $primary_types, ...$primary_params);
        }
        mysqli_stmt_execute($primary_stmt);
        $primary_result = mysqli_stmt_get_result($primary_stmt);
        while ($row = mysqli_fetch_assoc($primary_result)) {
            $primary_data[$row['gl_code']] = [
                'mlfsi' => floatval($row['branch_amount']),
                'jewelers' => floatval($row['showroom_amount']),
                'total' => floatval($row['branch_amount']) + floatval($row['showroom_amount'])
            ];
        }
        mysqli_stmt_close($primary_stmt);
    }
}

// ============================================================
// FETCH PREVIOUS PERIOD DATA
// ============================================================
$previous_data = []; // gl_code -> [branch_amount, showroom_amount, total]
if ($use_real_data && !empty($previous_period)) {
    $prev_parts = explode('-', $previous_period);
    $prev_year_val = $prev_parts[0];
    $prev_month_val = $previous_period . '-01';
    
    $previous_sql = "
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
    $previous_params = array_merge($params, [$prev_year_val, $prev_month_val]);
    $previous_types = $types . "ss";
    
    $previous_stmt = mysqli_prepare($conn, $previous_sql);
    if ($previous_stmt) {
        if (!empty($previous_params)) {
            mysqli_stmt_bind_param($previous_stmt, $previous_types, ...$previous_params);
        }
        mysqli_stmt_execute($previous_stmt);
        $previous_result = mysqli_stmt_get_result($previous_stmt);
        while ($row = mysqli_fetch_assoc($previous_result)) {
            $previous_data[$row['gl_code']] = [
                'mlfsi' => floatval($row['branch_amount']),
                'jewelers' => floatval($row['showroom_amount']),
                'total' => floatval($row['branch_amount']) + floatval($row['showroom_amount'])
            ];
        }
        mysqli_stmt_close($previous_stmt);
    }
}

// ============================================================
// BUILD TABLE ROWS BASED ON GL MAPPING
// ============================================================
$table_rows = [];

foreach ($gl_mapping as $key => $codes_detailed) {
    [$sort_order, $sub_order] = explode('|', $key);
    $gl_description = $gl_descriptions[$key] ?? '';
    $is_inj2 = in_array($key, $special_keys);
    
    // Determine which codes to use based on mode
    $p_codes = [];
    $prev_codes = [];
    
    if ($gl_code_mode === 'old') {
        // Use old codes for both periods
        $p_codes = $codes_detailed['old'];
        $prev_codes = $codes_detailed['old'];
    } elseif ($gl_code_mode === 'new') {
        // Use new codes for both periods
        $p_codes = $codes_detailed['new'];
        $prev_codes = $codes_detailed['new'];
    } else { // mixed
        $p_mode = isApril2026OrLater($primary_period) ? 'new' : 'old';
        $prev_mode = isApril2026OrLater($previous_period) ? 'new' : 'old';
        $p_codes = $codes_detailed[$p_mode];
        $prev_codes = $codes_detailed[$prev_mode];
    }

    // Initialize totals for this GL combination
    $primary_mlfsi = 0;
    $primary_jewelers = 0;
    $previous_mlfsi = 0;
    $previous_jewelers = 0;
    
    // Sum up amounts for all gl_codes in this combination
    foreach ($p_codes as $gl_code) {
        if (isset($primary_data[$gl_code])) {
            $primary_mlfsi += $primary_data[$gl_code]['mlfsi'];
            $primary_jewelers += $primary_data[$gl_code]['jewelers'];
        }
    }
    foreach ($prev_codes as $gl_code) {
        if (isset($previous_data[$gl_code])) {
            $previous_mlfsi += $previous_data[$gl_code]['mlfsi'];
            $previous_jewelers += $previous_data[$gl_code]['jewelers'];
        }
    }
    $primary_total = $primary_mlfsi + $primary_jewelers;
    $previous_total = $previous_mlfsi + $previous_jewelers;
    
    // Add the detail row for this GL combination
    $table_rows[] = [
        'sort_order' => $sort_order,
        'sub_order' => $sub_order,
        'gl_description' => $gl_description,
        'is_section_header' => false,
        'is_summary_row' => false,
        'primary_mlfsi' => $primary_mlfsi,
        'primary_jewelers' => $primary_jewelers,
        'primary_total' => $primary_total,
        'previous_mlfsi' => $previous_mlfsi,
        'previous_jewelers' => $previous_jewelers,
        'previous_total' => $previous_total,
        'is_inj2' => $is_inj2
    ];
}

// ============================================================
// GROUP BY SORT_ORDER AND ADD SUMMARY ROWS
// ============================================================
$grouped_rows = [];
foreach ($table_rows as $row) {
    $sort_order = $row['sort_order'];
    if (!isset($grouped_rows[$sort_order])) {
        $grouped_rows[$sort_order] = [];
    }
    $grouped_rows[$sort_order][] = $row;
}

$final_table_rows = [];
// Initialize cumulative revenue counters
$rev_mlfsi_p = 0; $rev_jew_p = 0; $rev_tot_p = 0;
$rev_mlfsi_prev = 0; $rev_jew_prev = 0; $rev_tot_prev = 0;
$sa_mlfsi_p = 0; $sa_jew_p = 0; $sa_tot_p = 0;
$sa_mlfsi_prev = 0; $sa_jew_prev = 0; $sa_tot_prev = 0;
$gp_mlfsi_p = 0; $gp_jew_p = 0; $gp_tot_p = 0;
$gp_mlfsi_prev = 0; $gp_jew_prev = 0; $gp_tot_prev = 0;
$ebitda_mlfsi_p = 0; $ebitda_jew_p = 0; $ebitda_tot_p = 0;
$ebitda_mlfsi_prev = 0; $ebitda_jew_prev = 0; $ebitda_tot_prev = 0;
$ebit_mlfsi_p = 0; $ebit_jew_p = 0; $ebit_tot_p = 0;
$ebit_mlfsi_prev = 0; $ebit_jew_prev = 0; $ebit_tot_prev = 0;
$ebt_mlfsi_p = 0; $ebt_jew_p = 0; $ebt_tot_p = 0;
$ebt_mlfsi_prev = 0; $ebt_jew_prev = 0; $ebt_tot_prev = 0;
$net_mlfsi_p = 0; $net_jew_p = 0; $net_tot_p = 0;
$net_mlfsi_prev = 0; $net_jew_prev = 0; $net_tot_prev = 0;

foreach ($grouped_rows as $sort_order => $rows) {
    // Add all detail rows for this sort_order
    // Skip details for sort orders 6, 8, and 11 to show only the summary row
    if (!in_array((int)$sort_order, [6, 8, 11])) {
        foreach ($rows as $row) {
            $final_table_rows[] = $row;
        }
    }
    
    // Calculate totals for summary row
    $total_primary_mlfsi = array_sum(array_column($rows, 'primary_mlfsi'));
    $total_primary_jewelers = array_sum(array_column($rows, 'primary_jewelers'));
    $total_primary_total = array_sum(array_column($rows, 'primary_total'));
    $total_previous_mlfsi = array_sum(array_column($rows, 'previous_mlfsi'));
    $total_previous_jewelers = array_sum(array_column($rows, 'previous_jewelers'));
    $total_previous_total = array_sum(array_column($rows, 'previous_total'));

    // Accumulate for Total Revenues if sort_order is within the revenue range (1-20)
    if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
        $rev_mlfsi_p += $total_primary_mlfsi;
        $rev_jew_p += $total_primary_jewelers;
        $rev_tot_p += $total_primary_total;
        $rev_mlfsi_prev += $total_previous_mlfsi;
        $rev_jew_prev += $total_previous_jewelers;
        $rev_tot_prev += $total_previous_total;
    }
    
    // Accumulate for Total Selling and Admin Expenses if sort_order is 22 or 23
    if ((int)$sort_order == 22 || (int)$sort_order == 23) {
        $sa_mlfsi_p += $total_primary_mlfsi;
        $sa_jew_p += $total_primary_jewelers;
        $sa_tot_p += $total_primary_total;
        $sa_mlfsi_prev += $total_previous_mlfsi;
        $sa_jew_prev += $total_previous_jewelers;
        $sa_tot_prev += $total_previous_total;
    }
    
    $inc_dec = $total_primary_total - $total_previous_total;
    $percentage = 0;
    if ($total_previous_total != 0) {
        $percentage = ($inc_dec / $total_previous_total) * 100;
    } elseif ($total_primary_total != 0) {
        $percentage = 100;
    }
    
    $description = isset($sort_order_descriptions[$sort_order]) 
        ? $sort_order_descriptions[$sort_order] 
        : "Total for Sort Order " . $sort_order;
    
    // Add summary row
    // Hide summary row for sort orders 24, 25, and 26, but keep calculations for subsequent computed rows
    if (!in_array((int)$sort_order, [24, 25, 26])) {
        $final_table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => '',
            'gl_description' => $description,
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_mlfsi' => $total_primary_mlfsi,
            'primary_jewelers' => $total_primary_jewelers,
            'primary_total' => $total_primary_total,
            'previous_mlfsi' => $total_previous_mlfsi,
            'previous_jewelers' => $total_previous_jewelers,
            'previous_total' => $total_previous_total,
            'inc_dec' => $inc_dec,
            'percentage' => $percentage
        ];
    }

    // Insert TOTAL REVENUES summary row after the sort_order 20 block
    if ((int)$sort_order == 20) {
        $inc_dec_rev = $rev_tot_p - $rev_tot_prev;
        $pct_rev = ($rev_tot_prev != 0) ? ($inc_dec_rev / abs($rev_tot_prev)) * 100 : ($rev_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = [
            'sort_order' => 'TOTAL REVENUES',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_mlfsi' => $rev_mlfsi_p,
            'primary_jewelers' => $rev_jew_p,
            'primary_total' => $rev_tot_p,
            'previous_mlfsi' => $rev_mlfsi_prev,
            'previous_jewelers' => $rev_jew_prev,
            'previous_total' => $rev_tot_prev,
            'inc_dec' => $inc_dec_rev,
            'percentage' => $pct_rev
        ];

        // Insert Cost of Sales/Service header row
        $final_table_rows[] = [
            'sort_order' => '',
            'sub_order' => 'Cost of Sales/Service',
            'gl_description' => '',
            'is_section_header' => true,
            'is_summary_row' => true,
            'primary_mlfsi' => null,
            'primary_jewelers' => null,
            'primary_total' => null,
            'previous_mlfsi' => null,
            'previous_jewelers' => null,
            'previous_total' => null,
            'inc_dec' => null,
            'percentage' => null
        ];
    }

    // Insert GROSS PROFIT summary row after sort_order 21 (Cost of Sales)
    if ((int)$sort_order == 21) {
        $gp_mlfsi_p = $rev_mlfsi_p - $total_primary_mlfsi;
        $gp_jew_p = $rev_jew_p - $total_primary_jewelers;
        $gp_tot_p = $rev_tot_p - $total_primary_total;
        $gp_mlfsi_prev = $rev_mlfsi_prev - $total_previous_mlfsi;
        $gp_jew_prev = $rev_jew_prev - $total_previous_jewelers;
        $gp_tot_prev = $rev_tot_prev - $total_previous_total;
        
        $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
        $pct_gp = ($gp_tot_prev != 0) ? ($inc_dec_gp / abs($gp_tot_prev)) * 100 : ($gp_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = [
            'sort_order' => 'GROSS PROFIT',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_mlfsi' => $gp_mlfsi_p,
            'primary_jewelers' => $gp_jew_p,
            'primary_total' => $gp_tot_p,
            'previous_mlfsi' => $gp_mlfsi_prev,
            'previous_jewelers' => $gp_jew_prev,
            'previous_total' => $gp_tot_prev,
            'inc_dec' => $inc_dec_gp,
            'percentage' => $pct_gp
        ];

        // Add SELLING & ADMIN EXPENSE header row
        $final_table_rows[] = [
            'sort_order' => 'SELLING & ADMIN EXPENSE',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => true,
            'is_summary_row' => true,
            'primary_mlfsi' => null,
            'primary_jewelers' => null,
            'primary_total' => null,
            'previous_mlfsi' => null,
            'previous_jewelers' => null,
            'previous_total' => null,
            'inc_dec' => null,
            'percentage' => null
        ];
    }

    // Insert TOTAL SELLING AND ADMIN EXPENSES summary row after sort_order 23
    if ((int)$sort_order == 23) {
        $inc_dec_sa = $sa_tot_p - $sa_tot_prev;
        $pct_sa = ($sa_tot_prev != 0) ? ($inc_dec_sa / abs($sa_tot_prev)) * 100 : ($sa_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = [
            'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_mlfsi' => $sa_mlfsi_p,
            'primary_jewelers' => $sa_jew_p,
            'primary_total' => $sa_tot_p,
            'previous_mlfsi' => $sa_mlfsi_prev,
            'previous_jewelers' => $sa_jew_prev,
            'previous_total' => $sa_tot_prev,
            'inc_dec' => $inc_dec_sa,
            'percentage' => $pct_sa
        ];

        // Calculate EBITDA: Gross Profit - Total Selling and Admin Expenses
        $ebitda_mlfsi_p = $gp_mlfsi_p - $sa_mlfsi_p;
        $ebitda_jew_p = $gp_jew_p - $sa_jew_p;
        $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
        $ebitda_mlfsi_prev = $gp_mlfsi_prev - $sa_mlfsi_prev;
        $ebitda_jew_prev = $gp_jew_prev - $sa_jew_prev;
        $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;

        $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
        $pct_ebitda = ($ebitda_tot_prev != 0) ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100 : ($ebitda_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = [
            'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'skip_spacer' => true,
            'primary_mlfsi' => $ebitda_mlfsi_p,
            'primary_jewelers' => $ebitda_jew_p,
            'primary_total' => $ebitda_tot_p,
            'previous_mlfsi' => $ebitda_mlfsi_prev,
            'previous_jewelers' => $ebitda_jew_prev,
            'previous_total' => $ebitda_tot_prev,
            'inc_dec' => $inc_dec_ebitda,
            'percentage' => $pct_ebitda
        ];
    }

    // Insert EARNINGS BEFORE INTEREST & TAXES summary row after sort_order 24
    if ((int)$sort_order == 24) {
        $ebit_mlfsi_p = $ebitda_mlfsi_p - $total_primary_mlfsi;
        $ebit_jew_p = $ebitda_jew_p - $total_primary_jewelers;
        $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
        $ebit_mlfsi_prev = $ebitda_mlfsi_prev - $total_previous_mlfsi;
        $ebit_jew_prev = $ebitda_jew_prev - $total_previous_jewelers;
        $ebit_tot_prev = $ebitda_tot_prev - $total_previous_total;

        $inc_dec_ebit = $ebit_tot_p - $ebit_tot_prev;
        $pct_ebit = ($ebit_tot_prev != 0) ? ($inc_dec_ebit / abs($ebit_tot_prev)) * 100 : ($ebit_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = ['is_manual_spacer' => true];

        $final_table_rows[] = [
            'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'skip_spacer' => true,
            'primary_mlfsi' => $ebit_mlfsi_p,
            'primary_jewelers' => $ebit_jew_p,
            'primary_total' => $ebit_tot_p,
            'previous_mlfsi' => $ebit_mlfsi_prev,
            'previous_jewelers' => $ebit_jew_prev,
            'previous_total' => $ebit_tot_prev,
            'inc_dec' => $inc_dec_ebit,
            'percentage' => $pct_ebit
        ];
    }

    // Insert EARNINGS BEFORE TAXES summary row after sort_order 25
    if ((int)$sort_order == 25) {
        $ebt_mlfsi_p = $ebit_mlfsi_p - $total_primary_mlfsi;
        $ebt_jew_p = $ebit_jew_p - $total_primary_jewelers;
        $ebt_tot_p = $ebit_tot_p - $total_primary_total;
        $ebt_mlfsi_prev = $ebit_mlfsi_prev - $total_previous_mlfsi;
        $ebt_jew_prev = $ebit_jew_prev - $total_previous_jewelers;
        $ebt_tot_prev = $ebit_tot_prev - $total_previous_total;

        $inc_dec_ebt = $ebt_tot_p - $ebt_tot_prev;
        $pct_ebt = ($ebt_tot_prev != 0) ? ($inc_dec_ebt / abs($ebt_tot_prev)) * 100 : ($ebt_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = ['is_manual_spacer' => true];

        $final_table_rows[] = [
            'sort_order' => 'EARNINGS BEFORE TAXES',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'skip_spacer' => true,
            'primary_mlfsi' => $ebt_mlfsi_p,
            'primary_jewelers' => $ebt_jew_p,
            'primary_total' => $ebt_tot_p,
            'previous_mlfsi' => $ebt_mlfsi_prev,
            'previous_jewelers' => $ebt_jew_prev,
            'previous_total' => $ebt_tot_prev,
            'inc_dec' => $inc_dec_ebt,
            'percentage' => $pct_ebt
        ];
    }

    // Insert TOTAL NET INCOME/LOSS summary row after sort_order 26
    if ((int)$sort_order == 26) {
        $net_mlfsi_p = $ebt_mlfsi_p - $total_primary_mlfsi;
        $net_jew_p = $ebt_jew_p - $total_primary_jewelers;
        $net_tot_p = $ebt_tot_p - $total_primary_total;
        $net_mlfsi_prev = $ebt_mlfsi_prev - $total_previous_mlfsi;
        $net_jew_prev = $ebt_jew_prev - $total_previous_jewelers;
        $net_tot_prev = $ebt_tot_prev - $total_previous_total;

        $inc_dec_net = $net_tot_p - $net_tot_prev;
        $pct_net = ($net_tot_prev != 0) ? ($inc_dec_net / abs($net_tot_prev)) * 100 : ($net_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = ['is_manual_spacer' => true];

        $final_table_rows[] = [
            'sort_order' => 'TOTAL NET INCOME/LOSS',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'skip_spacer' => true,
            'primary_mlfsi' => $net_mlfsi_p,
            'primary_jewelers' => $net_jew_p,
            'primary_total' => $net_tot_p,
            'previous_mlfsi' => $net_mlfsi_prev,
            'previous_jewelers' => $net_jew_prev,
            'previous_total' => $net_tot_prev,
            'inc_dec' => $inc_dec_net,
            'percentage' => $pct_net
        ];
    }
}

return $final_table_rows;
}

// ============================================================
// BUILD TABLE(S) FOR SELECTED REGION/AREA COMBINATIONS
// ============================================================
$tables_by_region = [];

// Always build the table structure, even with empty/zero data
if (!empty($selected_regions)) {
    foreach ($selected_regions as $rg) {
        $allowed_areas = $reg_to_area[$rg] ?? [];
        $areas_for_region = [];

        if (!empty($selected_areas)) {
            foreach ($selected_areas as $ar) {
                if (in_array($ar, $allowed_areas, true)) {
                    $areas_for_region[] = $ar;
                }
            }
        }

        if (empty($areas_for_region)) {
            $areas_for_region = ['']; // All Areas for this region
        }

        foreach ($areas_for_region as $ar) {
            $tables_by_region[$rg] = $tables_by_region[$rg] ?? [];
            $tables_by_region[$rg][] = [
                'area' => $ar,
                'rows' => compute_table_rows_for_region_area(
                    $conn,
                    $mainzone,
                    $zone,
                    $transaction_year,
                    $primary_period,
                    $previous_period,
                    $gl_code_mode,
                    $gl_mapping,
                    $gl_descriptions,
                    $special_keys,
                    $sort_order_descriptions,
                    $rg,
                    $ar,
                    $valid_filters // Use real data only if filters are valid
                ),
            ];
        }
    }
} else {
    // No region selected: render a single "All Regions/All Areas" table.
    $tables_by_region[''] = [[
        'area' => '',
        'rows' => compute_table_rows_for_region_area(
            $conn,
            $mainzone,
            $zone,
            $transaction_year,
            $primary_period,
            $previous_period,
            $gl_code_mode,
            $gl_mapping,
            $gl_descriptions,
            $special_keys,
            $sort_order_descriptions,
            '',
            '',
            $valid_filters // Use real data only if filters are valid
        ),
    ]];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report Original Data</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            <div class="page-title">Comparative Report (No Manual Adjustment)</div>

            <!-- Error Banner for validation issues -->
            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <form method="GET" class="filter-form" id="filterForm" onsubmit="return validateForm()">
                <div class="filter-group">
                    <label>Main Zone</label>
                    <select name="mainzone" id="mainzoneSelect">
                        <option value="">Main Zones</option>
                        <?php foreach($distinct_mz as $mz_val): ?>
                            <option value="<?= htmlspecialchars($mz_val) ?>" <?= $mainzone === $mz_val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mz_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Zone</label>
                    <select name="zone" id="zoneSelect">
                        <option value="">Zones</option>
                        <?php foreach($distinct_zn as $zn_val): ?>
                            <option value="<?= htmlspecialchars($zn_val) ?>" <?= $zone === $zn_val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($zn_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Region</label>
                    <div class="multi-select" id="regionMulti">
                        <button type="button" class="multi-select__button" id="regionMultiBtn" aria-expanded="false">Regions</button>
                        <div class="multi-select__panel" id="regionPanel"></div>
                    </div>
                </div>

                <div class="filter-group">
                    <label>Area</label>
                    <div class="multi-select" id="areaMulti">
                        <button type="button" class="multi-select__button" id="areaMultiBtn" aria-expanded="false">Areas</button>
                        <div class="multi-select__panel" id="areaPanel"></div>
                    </div>
                </div>

                <!-- <div class="filter-group">
                    <label>Transaction Year</label>
                    <select name="transaction_year" id="yearSelect">
                        <option value="">Years</option>
                        <?php foreach($distinct_years as $yr): ?>
                            <option value="<?= htmlspecialchars($yr) ?>" <?= $transaction_year === $yr ? 'selected' : '' ?>>
                                <?= htmlspecialchars($yr) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div> -->

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
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="mixed" id="glMixedRadio" <?= $gl_code_mode === 'mixed' ? 'checked' : '' ?>>
                            <span>Mix</span>
                        </label>
                    </div>
                </div>

                <div class="filter-group">
                    <label>Primary Period</label>
                    <input type="month" name="primary_period" id="primaryPeriodSelect" value="<?= htmlspecialchars($primary_period) ?>">
                </div>

                <p style="color:red; font-weight: bold;">VS</p>

                <div class="filter-group">
                    <label>Previous Period</label>
                    <input type="month" name="previous_period" id="previousPeriodSelect" value="<?= htmlspecialchars($previous_period) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <button type="button" class="btn-collapse" id="collapseBtn"><i class="fa-solid fa-compress"></i> Collapse</button>
                    <a href="export_comparative.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                </div>
            </form>

            <!-- Always display tables, even with zero data -->
            <?php foreach ($tables_by_region as $region_name => $tables): ?>
                <div class="region-block">
                    <div class="tables-scroll">
                        <div class="tables-grid">
                            <?php foreach ($tables as $t): ?>
                                <?php
                                    $table_rows = $t['rows'];
                                    $display_region = $region_name;
                                    $display_area = $t['area'];
                                ?>
                                <div class="table-container">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th colspan="4">Region: <?php echo !empty($display_region) ? htmlspecialchars($display_region) : 'All Regions'; ?></th>
                                                <th colspan="11">Area: <?php echo !empty($display_area) ? htmlspecialchars($display_area) : 'All Areas'; ?></th>
                                            </tr>
                                            <tr>
                                                <th colspan="4"></th>
                                                <th colspan="3"><?php echo !empty($primary_period) ? strtoupper(date('F Y', strtotime($primary_period . '-01'))) : '(Primary Period)'; ?></th>
                                                <th></th>
                                                <th colspan="3"><?php echo !empty($previous_period) ? strtoupper(date('F Y', strtotime($previous_period . '-01'))) : '(Previous Period)'; ?></th>
                                                <th colspan="4"></th>
                                            </tr>
                                            <tr>
                                                <th></th>
                                                <th></th>
                                                <th></th>
                                                <th></th>
                                                <th>MLFSI</th>
                                                <th>JEWELERS</th>
                                                <th>TOTAL</th>
                                                <th></th>
                                                <th>MLFSI</th>
                                                <th>JEWELERS</th>
                                                <th>TOTAL</th>
                                                <th></th>  
                                                <th>Inc./Dec.</th>  
                                                <th>%</th>
                                                <th></th>  
                                            </tr>
                                        </thead>
                                        <tbody class="report-tbody">

                                        <tr class="initial-spacer">
                                            <td colspan="15"></td>
                                        </tr>
                                        <tr class="revenues-header-row">
                                            <td style="background-color: #ff7f29; font-weight: bold;">REVENUES</td>
                                            <td colspan="14" style="background-color: #ff7f29; font-weight: bold;"></td>
                                        </tr>
                                            <?php if (empty($table_rows)): ?>
                                                <tr>
                                                    <td colspan="15" style="text-align: center;">No data structure available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($table_rows as $row): 
                                                    if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                                        echo '<tr class="spacer-row" style="height: 20px;"><td colspan="15"></td></tr>';
                                                        continue;
                                                    }
                                                    $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
                                                    $is_header = !empty($row['is_section_header']);
                                                    
                                                    $primary_mlfsi = $row['primary_mlfsi'] ?? 0;
                                                    $primary_jewelers = $row['primary_jewelers'] ?? 0;
                                                    $primary_total = $row['primary_total'] ?? 0;
                                                    $previous_mlfsi = $row['previous_mlfsi'] ?? 0;
                                                    $previous_jewelers = $row['previous_jewelers'] ?? 0;
                                                    $previous_total = $row['previous_total'] ?? 0;
                                                    
                                                    if (!$is_summary_row && !empty($row['is_inj2'])) {
                                                        $primary_mlfsi = -$primary_mlfsi;
                                                        $primary_jewelers = -$primary_jewelers;
                                                        $primary_total = -$primary_total;
                                                        $previous_mlfsi = -$previous_mlfsi;
                                                        $previous_jewelers = -$previous_jewelers;
                                                        $previous_total = -$previous_total;
                                                    }
                                                    
                                                    $inc_dec = isset($row['inc_dec']) ? $row['inc_dec'] : ($primary_total - $previous_total);
                                                    
                                                    if (isset($row['percentage'])) {
                                                        $percentage = $row['percentage'];
                                                    } else {
                                                        $percentage = ($previous_total != 0) ? ($inc_dec / abs($previous_total)) * 100 : (($primary_total != 0) ? 100 : 0);
                                                    }
                                                    
                                                    $inc_dec_class = $inc_dec > 0 ? 'positive' : (($inc_dec < 0) ? 'negative' : '');
                                                    $percentage_class = $percentage > 0 ? 'positive' : ($percentage < 0 ? 'negative' : '');
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
                                                        
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($primary_mlfsi < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($primary_mlfsi, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($primary_jewelers < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($primary_jewelers, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($primary_total < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($primary_total, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                        
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($previous_mlfsi < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($previous_mlfsi, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($previous_jewelers < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($previous_jewelers, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($previous_total < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($previous_total, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                        
                                                        <td class="numeric-cell <?= $inc_dec_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($inc_dec < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : number_format($inc_dec, 2) ?>
                                                        </td>
                                                        <td class="percentage-cell <?= $percentage_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= $percentage < 0 ? 'color: red;' : '' ?>">
                                                            <?php 
                                                                if ($is_header) {
                                                                    echo '';
                                                                } elseif ($percentage >= 1000 || $percentage <= -1000) {
                                                                    echo 'mat';
                                                                } else {
                                                                    echo number_format($percentage, 2) . '%';
                                                                }
                                                            ?>
                                                        </td>
                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                    </tr>
                                                    <?php 
                                                    // Spacer row after summary rows, EXCEPT after EBITDA, EBIT, EBT, and NET rows
                                                    if ($is_summary_row && !$is_header && empty($row['skip_spacer'])): 
                                                    ?>
                                                        <tr class="spacer-row" data-spacer-for="<?= htmlspecialchars($row['sort_order'] ?? '') ?>" style="height: 20px;"><td colspan="15"></td></tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <script>
                // Helper function to compare months (format: YYYY-MM)
                function compareMonths(month1, month2) {
                    if (!month1 || !month2) return 0;
                    return new Date(month1 + '-01') - new Date(month2 + '-01');
                }

                // Helper to check if month is March 2026 or earlier
                function isMarch2026OrEarlier(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-03-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate <= cutoff;
                }

                // Helper to check if month is April 2026 or later
                function isApril2026OrLater(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-04-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate >= cutoff;
                }

                let activeModal = null;

                function showModal(message) {
                    // Remove any existing modal
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
                    const primaryPeriod = document.getElementById('primaryPeriodSelect').value;
                    const previousPeriod = document.getElementById('previousPeriodSelect').value;
                    const glOldRadio = document.getElementById('glOldRadio');
                    const glNewRadio = document.getElementById('glNewRadio');
                    const glMixedRadio = document.getElementById('glMixedRadio');
                    const glCodeMode = glOldRadio.checked ? 'old' : (glNewRadio.checked ? 'new' : (glMixedRadio.checked ? 'mixed' : 'old'));

                    // Only validate if both periods have values
                    if (!primaryPeriod || !previousPeriod) {
                        // Allow form submission with empty periods - will show zero data
                        return true;
                    }

                    // Validation 1: Primary period must be greater than previous period
                    if (compareMonths(primaryPeriod, previousPeriod) <= 0) {
                        showModal('Primary period must be later than the Previous period.');
                        return false;
                    }

                    // Validation 2: GL code mode restrictions
                    if (glCodeMode === 'old') {
                        if (!isMarch2026OrEarlier(primaryPeriod) || !isMarch2026OrEarlier(previousPeriod)) {
                            showModal('Old GL Code is only available for March 2026 and earlier. Both selected periods must be March 2026 or earlier.');
                            return false;
                        }
                    } else if (glCodeMode === 'new') {
                        if (!isApril2026OrLater(primaryPeriod) || !isApril2026OrLater(previousPeriod)) {
                            showModal('New GL Code is only available for April 2026 onwards. Both selected periods must be April 2026 or later.');
                            return false;
                        }
                    }

                    return true;
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const collapseBtn = document.getElementById('collapseBtn');
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
                                    
                                    // 1. Hide detail rows for sort 1-20
                                    if (is1To20 && isDetail) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                    
                                    // 2. Hide spacer rows for sort 1-20
                                    if (spacerFor) {
                                        const spacerNum = parseInt(spacerFor);
                                        if (!isNaN(spacerNum) && spacerNum >= 1 && spacerNum <= 20) {
                                            row.style.display = isCollapsed ? 'none' : '';
                                        }
                                    }

                                    // 3. Hide REVENUES header row
                                    if (row.classList.contains('revenues-header-row')) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }

                                    // 4. Hide initial spacer row
                                    if (row.classList.contains('initial-spacer')) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                });
                            });

                            collapseBtn.innerHTML = isCollapsed 
                                ? '<i class="fa-solid fa-expand"></i> Uncollapse' 
                                : '<i class="fa-solid fa-compress"></i> Collapse';
                            
                            // Visual feedback for active state
                            collapseBtn.style.backgroundColor = isCollapsed ? '#1f2937' : '#4b5563';
                        });
                    }
                });

                (function () {
                    const mzToZn = <?= json_encode($mz_to_zn) ?>;
                    const mzToReg = <?= json_encode($mz_to_reg) ?>;
                    const znToReg = <?= json_encode($zn_to_reg) ?>;
                    const regToArea = <?= json_encode($reg_to_area) ?>;
                    const allRegions = <?= json_encode(array_values($distinct_reg)) ?>;
                    const allAreas = <?= json_encode(array_values($distinct_area)) ?>;
                    const selectedRegions = <?= json_encode(array_values($selected_regions)) ?>;
                    const selectedAreas = <?= json_encode(array_values($selected_areas)) ?>;

                    const mainzoneSelect = document.getElementById('mainzoneSelect');
                    const zoneSelect = document.getElementById('zoneSelect');
                    const regionBtn = document.getElementById('regionMultiBtn');
                    const regionPanel = document.getElementById('regionPanel');
                    const areaBtn = document.getElementById('areaMultiBtn');
                    const areaPanel = document.getElementById('areaPanel');

                    const allZones = Array.from(zoneSelect.options).map(o => ({ value: o.value, label: o.text }));

                    function rebuildOptions(selectEl, options, allLabel) {
                        const current = selectEl.value;
                        selectEl.innerHTML = '';
                        const optAll = document.createElement('option');
                        optAll.value = '';
                        optAll.textContent = allLabel;
                        selectEl.appendChild(optAll);
                        options.forEach(o => {
                            const opt = document.createElement('option');
                            opt.value = o.value;
                            opt.textContent = o.label;
                            selectEl.appendChild(opt);
                        });
                        if ([...selectEl.options].some(o => o.value === current)) {
                            selectEl.value = current;
                        } else {
                            selectEl.value = '';
                        }
                    }

                    function updateZones() {
                        const mz = mainzoneSelect.value;
                        if (!mz) {
                            rebuildOptions(zoneSelect, allZones.filter(o => o.value !== ''), 'Zones');
                            return;
                        }
                        const zones = (mzToZn[mz] || []).slice().sort().map(z => ({ value: z, label: z }));
                        rebuildOptions(zoneSelect, zones, 'Zones');
                    }

                    function togglePanel(btn, panel) {
                        const isOpen = panel.classList.toggle('is-open');
                        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    }

                    function closePanels() {
                        [regionPanel, areaPanel].forEach(p => p.classList.remove('is-open'));
                        [regionBtn, areaBtn].forEach(b => b.setAttribute('aria-expanded', 'false'));
                    }

                    function setButtonLabel(btn, checkedValues, allLabel, singularLabel) {
                        if (!checkedValues.length) {
                            btn.textContent = allLabel;
                            return;
                        }
                        if (checkedValues.length === 1) {
                            btn.textContent = `${singularLabel}: ${checkedValues[0]}`;
                            return;
                        }
                        btn.textContent = `${singularLabel}s (${checkedValues.length})`;
                    }

                    function getCheckedValues(panelEl) {
                        return Array.from(panelEl.querySelectorAll('input[type="checkbox"]:not(.select-all-checkbox):checked')).map(i => i.value);
                    }

                    function buildCheckboxList(panelEl, name, values, checkedSet) {
                        panelEl.innerHTML = '';
                        values.forEach(v => {
                            const label = document.createElement('label');
                            label.className = 'multi-select__option';

                            const input = document.createElement('input');
                            input.type = 'checkbox';
                            input.name = name;
                            input.value = v;
                            input.checked = checkedSet.has(v);

                            const span = document.createElement('span');
                            span.textContent = v;

                            label.appendChild(input);
                            label.appendChild(span);
                            panelEl.appendChild(label);
                        });
                    }

                    function rebuildRegionCheckboxes() {
                        const mz = mainzoneSelect.value;
                        const zn = zoneSelect.value;
                        
                        let regions = [];
                        
                        // Priority: If mainzone is selected, filter regions by mainzone
                        if (mz && mzToReg[mz]) {
                            regions = mzToReg[mz];
                        } 
                        // If zone is selected (and no mainzone or mainzone doesn't filter further), filter by zone
                        else if (zn && znToReg[zn]) {
                            regions = znToReg[zn];
                        } 
                        // Otherwise show all regions
                        else {
                            regions = allRegions;
                        }
                        
                        // Further filter regions if both mainzone and zone are selected
                        if (mz && zn && mzToReg[mz] && znToReg[zn]) {
                            const mzRegions = new Set(mzToReg[mz]);
                            const znRegions = new Set(znToReg[zn]);
                            regions = Array.from(mzRegions).filter(r => znRegions.has(r));
                        }
                        
                        regions.sort();

                        const currentChecked = regionPanel.querySelectorAll('input[type="checkbox"]').length
                            ? getCheckedValues(regionPanel)
                            : selectedRegions;
                        const checked = new Set(currentChecked);
                        Array.from(checked).forEach(v => { if (!regions.includes(v)) checked.delete(v); });

                        buildCheckboxList(regionPanel, 'region[]', regions, checked);

                        if (regions.length > 0) {
                            const allOption = document.createElement('label');
                            allOption.className = 'multi-select__option';
                            allOption.style.fontWeight = 'bold';
                            allOption.style.borderBottom = '1px solid #edf2f7';
                            allOption.style.marginBottom = '4px';
                            allOption.style.paddingBottom = '4px';
                            allOption.style.position = 'sticky';
                            allOption.style.top = '0';
                            allOption.style.backgroundColor = '#fff';
                            allOption.style.zIndex = '2';

                            const allInput = document.createElement('input');
                            allInput.type = 'checkbox';
                            allInput.className = 'select-all-checkbox';
                            allInput.checked = regions.length > 0 && regions.every(r => checked.has(r));

                            const allSpan = document.createElement('span');
                            allSpan.textContent = 'All Regions';

                            allOption.appendChild(allInput);
                            allOption.appendChild(allSpan);
                            regionPanel.insertBefore(allOption, regionPanel.firstChild);

                            allInput.addEventListener('change', function() {
                                const isChecked = this.checked;
                                regionPanel.querySelectorAll('input[type="checkbox"]:not(.select-all-checkbox)').forEach(cb => {
                                    cb.checked = isChecked;
                                });
                                const regs = getCheckedValues(regionPanel);
                                setButtonLabel(regionBtn, regs, 'All Regions', 'Region');
                                rebuildAreaCheckboxes();
                            });
                        }

                        setButtonLabel(regionBtn, Array.from(checked), 'Regions', 'Region');
                    }

                    function rebuildAreaCheckboxes() {
                        const regs = getCheckedValues(regionPanel);
                        const areasSet = new Set();
                        if (regs.length) {
                            regs.forEach(r => (regToArea[r] || []).forEach(a => areasSet.add(a)));
                        } else {
                            allAreas.forEach(a => areasSet.add(a));
                        }
                        const areas = Array.from(areasSet).sort();

                        const currentChecked = areaPanel.querySelectorAll('input[type="checkbox"]').length
                            ? getCheckedValues(areaPanel)
                            : selectedAreas;
                        const checked = new Set(currentChecked);
                        Array.from(checked).forEach(v => { if (!areas.includes(v)) checked.delete(v); });

                        buildCheckboxList(areaPanel, 'area[]', areas, checked);

                        if (areas.length > 0) {
                            const allOption = document.createElement('label');
                            allOption.className = 'multi-select__option';
                            allOption.style.fontWeight = 'bold';
                            allOption.style.borderBottom = '1px solid #edf2f7';
                            allOption.style.marginBottom = '4px';
                            allOption.style.paddingBottom = '4px';
                            allOption.style.position = 'sticky';
                            allOption.style.top = '0';
                            allOption.style.backgroundColor = '#fff';
                            allOption.style.zIndex = '2';

                            const allInput = document.createElement('input');
                            allInput.type = 'checkbox';
                            allInput.className = 'select-all-checkbox';
                            allInput.checked = areas.length > 0 && areas.every(a => checked.has(a));

                            const allSpan = document.createElement('span');
                            allSpan.textContent = 'All Areas';

                            allOption.appendChild(allInput);
                            allOption.appendChild(allSpan);
                            areaPanel.insertBefore(allOption, areaPanel.firstChild);

                            allInput.addEventListener('change', function() {
                                const isChecked = this.checked;
                                areaPanel.querySelectorAll('input[type="checkbox"]:not(.select-all-checkbox)').forEach(cb => {
                                    cb.checked = isChecked;
                                });
                                const checkedAreas = getCheckedValues(areaPanel);
                                setButtonLabel(areaBtn, checkedAreas, 'All Areas', 'Area');
                            });
                        }

                        setButtonLabel(areaBtn, Array.from(checked), 'Areas', 'Area');
                    }

                    mainzoneSelect.addEventListener('change', () => {
                        updateZones();
                        rebuildRegionCheckboxes();
                        rebuildAreaCheckboxes();
                    });

                    zoneSelect.addEventListener('change', () => {
                        rebuildRegionCheckboxes();
                        rebuildAreaCheckboxes();
                    });

                    regionBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        togglePanel(regionBtn, regionPanel);
                        areaPanel.classList.remove('is-open');
                    });

                    areaBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        togglePanel(areaBtn, areaPanel);
                        regionPanel.classList.remove('is-open');
                    });

                    regionPanel.addEventListener('change', (e) => {
                        if (e.target && e.target.matches('input[type="checkbox"]') && !e.target.classList.contains('select-all-checkbox')) {
                            const allCb = regionPanel.querySelector('.select-all-checkbox');
                            if (allCb) {
                                const others = Array.from(regionPanel.querySelectorAll('input[type="checkbox"]:not(.select-all-checkbox)'));
                                allCb.checked = others.length > 0 && others.every(cb => cb.checked);
                            }

                            const regs = getCheckedValues(regionPanel);
                            setButtonLabel(regionBtn, regs, 'All Regions', 'Region');
                            rebuildAreaCheckboxes();
                        }
                    });

                    areaPanel.addEventListener('change', (e) => {
                        if (e.target && e.target.matches('input[type="checkbox"]') && !e.target.classList.contains('select-all-checkbox')) {
                            const allCb = areaPanel.querySelector('.select-all-checkbox');
                            if (allCb) {
                                const others = Array.from(areaPanel.querySelectorAll('input[type="checkbox"]:not(.select-all-checkbox)'));
                                allCb.checked = others.length > 0 && others.every(cb => cb.checked);
                            }

                            const areas = getCheckedValues(areaPanel);
                            setButtonLabel(areaBtn, areas, 'All Areas', 'Area');
                        }
                    });

                    document.addEventListener('click', (e) => {
                        const regionWrap = document.getElementById('regionMulti');
                        const areaWrap = document.getElementById('areaMulti');
                        if (regionWrap && regionWrap.contains(e.target)) return;
                        if (areaWrap && areaWrap.contains(e.target)) return;
                        closePanels();
                    });

                    // initialize dependent selects + checkbox panels on load
                    updateZones();
                    rebuildRegionCheckboxes();
                    rebuildAreaCheckboxes();
                })();
            </script>

        </div>

    </main>

<?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
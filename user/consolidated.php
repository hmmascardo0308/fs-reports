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
$mainzone = '';
$selected_regions = [];
$selected_areas = [];
$zone = $_GET['zone'] ?? '';
$transaction_type = $_GET['transaction_type'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old'; // old|new
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Fetch regions if zone is selected
$regions_in_zone = [];
if (!empty($zone)) {
    $r_query = "SELECT DISTINCT region FROM fs_reports.comparative_report WHERE zone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
    $r_stmt = mysqli_prepare($conn, $r_query);
    if ($r_stmt) {
        mysqli_stmt_bind_param($r_stmt, 's', $zone);
        mysqli_stmt_execute($r_stmt);
        $r_res = mysqli_stmt_get_result($r_stmt);
        while ($r_row = mysqli_fetch_assoc($r_res)) {
            $regions_in_zone[] = $r_row['region'];
        }
        mysqli_stmt_close($r_stmt);
    }
}
$num_regions = count($regions_in_zone);
$has_regions = $num_regions > 0;

// Error messages for validation
$error_message = '';

// Auto-calculate previous period if only primary is provided to maintain report functionality
if (!empty($primary_period) && empty($previous_period)) {
    $date_obj = DateTime::createFromFormat('Y-m', $primary_period);
    if ($date_obj) {
        $date_obj->modify('-1 month');
        $previous_period = $date_obj->format('Y-m');
    }
}

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

// Only validate if Transaction Month is provided
if (!empty($primary_period)) {
    if ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($primary_period)) {
            $error_message = 'Old GL Code is only available for March 2026 and earlier.';
            $show_error = true;
        }
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($primary_period)) {
            $error_message = 'New GL Code is only available for April 2026 onwards.';
            $show_error = true;
        }
    }
    if (!$show_error) {
        $valid_filters = true;
    }
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: consolidated.php");
    exit;
}

// Dropdown options + hierarchy maps
$distinct_zn = [];
$distinct_years = [];
$distinct_tt = [];
$month_options = [];

$hierarchy_query = "
    SELECT DISTINCT zone
    FROM fs_reports.comparative_report
    WHERE zone IS NOT NULL AND zone != ''
    ORDER BY zone
";
$hierarchy_res = mysqli_query($conn, $hierarchy_query);
if ($hierarchy_res) {
    while ($h = mysqli_fetch_assoc($hierarchy_res)) {
        $zn = trim((string)($h['zone'] ?? ''));
        if ($zn !== '' && !in_array($zn, $distinct_zn, true)) $distinct_zn[] = $zn;
    }
}
sort($distinct_zn);

$tt_query = "SELECT DISTINCT transaction_type FROM fs_reports.comparative_report WHERE transaction_type IS NOT NULL AND transaction_type != '' ORDER BY transaction_type";
$tt_res = mysqli_query($conn, $tt_query);
if ($tt_res) {
    while ($row = mysqli_fetch_assoc($tt_res)) {
        $distinct_tt[] = $row['transaction_type'];
    }
}

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
// GET GL MAPPING: sort_order + sub_order -> gl_id -> gl_codes
// ============================================================
$gl_mapping = []; // sort_order|sub_order -> [gl_codes]
$gl_descriptions = []; // sort_order|sub_order -> gl_description_comparative
$sort_order_descriptions = []; // sort_order -> description
$special_keys = []; // To track keys that have gl_id = 'INJ-2'

// Determine which table to use based on gl_code_mode
$gl_table = ($gl_code_mode === 'new') ? 'fs_reports.new_gl_codes' : 'fs_reports.gl_codes';

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, gl_description_comparative, description
    FROM $gl_table
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";
$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        
        if ($row['gl_id'] === 'INJ-2') {
            $special_keys[] = $key;
        }

        // Store gl_codes for this combination
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [];
            $gl_descriptions[$key] = $row['gl_description_comparative'];
        }

        $resolved_gl_code = trim((string)($row['gl_code'] ?? ''));
        if ($resolved_gl_code !== '' && !in_array($resolved_gl_code, $gl_mapping[$key], true)) {
            $gl_mapping[$key][] = $resolved_gl_code;
        }
        
        // Store sort_order description (for summary rows)
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

function compute_table_rows_for_region_area(mysqli $conn, string $mainzone, string $zone, string $transaction_type, string $transaction_year, string $primary_period, string $previous_period, array $gl_mapping, array $gl_descriptions, array $special_keys, array $sort_order_descriptions, string $region, string $area, array $regions_in_zone, bool $use_real_data = true): array {
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
if (!empty($transaction_type)) {
    $where_conditions[] = "transaction_type = ?";
    $params[] = $transaction_type;
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
// FETCH Transaction Month DATA
// ============================================================
$primary_data = []; // gl_code -> [branch_amount, showroom_amount, total]
if ($use_real_data && !empty($primary_period)) {
    $p_parts = explode('-', $primary_period);
    $p_year = $p_parts[0];
    $p_month_val = $primary_period . '-01'; // Match DATE format YYYY-MM-01 in DB
    
    $primary_sql = "
        SELECT 
            gl_code,
            region,
            SUM(amount) as total_amount
        FROM fs_reports.comparative_report
        $base_where
        AND transaction_year = ? AND transaction_month = ?
        AND gl_code IS NOT NULL AND gl_code != ''
        GROUP BY gl_code, region
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
            $primary_data[$row['gl_code']][$row['region']] = floatval($row['total_amount']);
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
            region,
            SUM(amount) as total_amount
        FROM fs_reports.comparative_report
        $base_where
        AND transaction_year = ? AND transaction_month = ?
        AND gl_code IS NOT NULL AND gl_code != ''
        GROUP BY gl_code, region
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
            $previous_data[$row['gl_code']][$row['region']] = floatval($row['total_amount']);
        }
        mysqli_stmt_close($previous_stmt);
    }
}

// ============================================================
// BUILD TABLE ROWS BASED ON GL MAPPING
// ============================================================
$table_rows = [];

foreach ($gl_mapping as $key => $gl_codes) {
    [$sort_order, $sub_order] = explode('|', $key);
    $gl_description = $gl_descriptions[$key];
    $is_inj2 = in_array($key, $special_keys);
    
    $row_region_totals = [];
    foreach ($regions_in_zone as $r_name) {
        $row_region_totals[$r_name] = 0;
    }
    $primary_total = 0;
    $previous_total = 0;
    
    // Sum up amounts for all gl_codes in this combination
    foreach ($gl_codes as $gl_code) {
        if (isset($primary_data[$gl_code])) {
            foreach ($primary_data[$gl_code] as $r_name => $amt) {
                if (empty($regions_in_zone)) {
                    $primary_total += $amt;
                } else if (isset($row_region_totals[$r_name])) {
                    $row_region_totals[$r_name] += $amt;
                    $primary_total += $amt;
                }
            }
        }
        if (isset($previous_data[$gl_code])) {
            foreach ($previous_data[$gl_code] as $r_name => $amt) {
                if (empty($regions_in_zone)) {
                    $previous_total += $amt;
                } else if (in_array($r_name, $regions_in_zone)) {
                    $previous_total += $amt;
                }
            }
        }
    }
    
    // Add the detail row for this GL combination
    $table_rows[] = [
        'sort_order' => $sort_order,
        'sub_order' => $sub_order,
        'gl_description' => $gl_description,
        'is_section_header' => false,
        'is_summary_row' => false,
        'primary_total' => $primary_total,
        'previous_total' => $previous_total,
        'region_totals' => $row_region_totals,
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
    $total_primary_total = array_sum(array_column($rows, 'primary_total'));
    $total_previous_total = array_sum(array_column($rows, 'previous_total'));

    $summary_region_totals = [];
    foreach ($regions_in_zone as $r_name) {
        $summary_region_totals[$r_name] = 0;
        foreach ($rows as $row) {
            $summary_region_totals[$r_name] += $row['region_totals'][$r_name] ?? 0;
        }
    }

    // Accumulate for Total Revenues if sort_order is within the revenue range (1-20)
    if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
        $rev_tot_p += $total_primary_total;
        $rev_tot_prev += $total_previous_total;

        if (!isset($rev_reg_p)) $rev_reg_p = array_fill_keys($regions_in_zone, 0);
        foreach ($regions_in_zone as $rn) $rev_reg_p[$rn] += $summary_region_totals[$rn];
    }
    
    // Accumulate for Total Selling and Admin Expenses if sort_order is 22 or 23
    if ((int)$sort_order == 22 || (int)$sort_order == 23) {
        $sa_tot_p += $total_primary_total;
        $sa_tot_prev += $total_previous_total;

        if (!isset($sa_reg_p)) $sa_reg_p = array_fill_keys($regions_in_zone, 0);
        foreach ($regions_in_zone as $rn) $sa_reg_p[$rn] += $summary_region_totals[$rn];
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
            'primary_total' => $total_primary_total,
            'previous_total' => $total_previous_total,
            'region_totals' => $summary_region_totals,
            'inc_dec' => $inc_dec,
            'percentage' => $percentage
        ];
        if (in_array((int)$sort_order, [22, 23])) {
            $final_table_rows[] = ['is_manual_spacer' => true];
        }
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
            'primary_total' => $rev_tot_p,
            'previous_total' => $rev_tot_prev,
            'region_totals' => $rev_reg_p ?? [],
            'inc_dec' => $inc_dec_rev,
            'percentage' => $pct_rev
        ];

        $final_table_rows[] = ['is_manual_spacer' => true];

        // Insert Cost of Sales/Service header row
        $final_table_rows[] = [
            'sort_order' => '',
            'sub_order' => 'Cost of Sales/Service',
            'gl_description' => '',
            'is_section_header' => true,
            'is_summary_row' => true,
            'inc_dec' => null,
            'percentage' => null
        ];
    }

    // Insert GROSS PROFIT summary row after sort_order 21 (Cost of Sales)
    if ((int)$sort_order == 21) {
        $gp_tot_p = $rev_tot_p - $total_primary_total;
        $gp_tot_prev = $rev_tot_prev - $total_previous_total;

        $gp_reg_p = [];
        foreach ($regions_in_zone as $rn) $gp_reg_p[$rn] = ($rev_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);
        
        $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
        $pct_gp = ($gp_tot_prev != 0) ? ($inc_dec_gp / abs($gp_tot_prev)) * 100 : ($gp_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = [
            'sort_order' => 'GROSS PROFIT',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_total' => $gp_tot_p,
            'previous_total' => $gp_tot_prev,
            'region_totals' => $gp_reg_p,
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
            'primary_total' => $sa_tot_p,
            'previous_total' => $sa_tot_prev,
            'region_totals' => $sa_reg_p ?? [],
            'inc_dec' => $inc_dec_sa,
            'percentage' => $pct_sa
        ];

        $final_table_rows[] = ['is_manual_spacer' => true];

        // Calculate EBITDA: Gross Profit - Total Selling and Admin Expenses
        $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
        $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;

        $ebitda_reg_p = [];
        foreach ($regions_in_zone as $rn) $ebitda_reg_p[$rn] = ($gp_reg_p[$rn] ?? 0) - ($sa_reg_p[$rn] ?? 0);

        $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
        $pct_ebitda = ($ebitda_tot_prev != 0) ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100 : ($ebitda_tot_p != 0 ? 100 : 0);

        $final_table_rows[] = [
            'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'skip_spacer' => true,
            'primary_total' => $ebitda_tot_p,
            'previous_total' => $ebitda_tot_prev,
            'region_totals' => $ebitda_reg_p,
            'inc_dec' => $inc_dec_ebitda,
            'percentage' => $pct_ebitda
        ];
    }

    // Insert EARNINGS BEFORE INTEREST & TAXES summary row after sort_order 24
    if ((int)$sort_order == 24) {
        $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
        $ebit_tot_prev = $ebitda_tot_prev - $total_previous_total;

        $ebit_reg_p = [];
        foreach ($regions_in_zone as $rn) $ebit_reg_p[$rn] = ($ebitda_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);

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
            'primary_total' => $ebit_tot_p,
            'previous_total' => $ebit_tot_prev,
            'region_totals' => $ebit_reg_p,
            'inc_dec' => $inc_dec_ebit,
            'percentage' => $pct_ebit
        ];
    }

    // Insert EARNINGS BEFORE TAXES summary row after sort_order 25
    if ((int)$sort_order == 25) {
        $ebt_tot_p = $ebit_tot_p - $total_primary_total;
        $ebt_tot_prev = $ebit_tot_prev - $total_previous_total;

        $ebt_reg_p = [];
        foreach ($regions_in_zone as $rn) $ebt_reg_p[$rn] = ($ebit_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);

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
            'primary_total' => $ebt_tot_p,
            'previous_total' => $ebt_tot_prev,
            'region_totals' => $ebt_reg_p,
            'inc_dec' => $inc_dec_ebt,
            'percentage' => $pct_ebt
        ];
    }

    // Insert TOTAL NET INCOME/LOSS summary row after sort_order 26
    if ((int)$sort_order == 26) {
        $net_tot_p = $ebt_tot_p - $total_primary_total;
        $net_tot_prev = $ebt_tot_prev - $total_previous_total;

        $net_reg_p = [];
        foreach ($regions_in_zone as $rn) $net_reg_p[$rn] = ($ebt_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);

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
            'primary_total' => $net_tot_p,
            'previous_total' => $net_tot_prev,
            'region_totals' => $net_reg_p,
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
                    $transaction_type,
                    $transaction_year,
                    $primary_period,
                    $previous_period,
                    $gl_mapping,
                    $gl_descriptions,
                    $special_keys,
                    $sort_order_descriptions,
                    $rg,
                    $ar,
                    $regions_in_zone,
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
            $transaction_type,
            $transaction_year,
            $primary_period,
            $previous_period,
            $gl_mapping,
            $gl_descriptions,
            $special_keys,
            $sort_order_descriptions,
            '',
            '',
            $regions_in_zone,
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
    <title>Consolidated Report</title>
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
            <div class="page-title">Consolidated Report</div>

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
                    <label>Transaction Type</label>
                    <select name="transaction_type" id="transactionTypeSelect">
                        <option value="">All Types</option>
                        <?php foreach($distinct_tt as $tt_val): ?>
                            <option value="<?= htmlspecialchars($tt_val) ?>" <?= $transaction_type === $tt_val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tt_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Transaction Year</label>
                    <select name="transaction_year" id="yearSelect">
                        <option value="">Years</option>
                        <?php foreach($distinct_years as $yr): ?>
                            <option value="<?= htmlspecialchars($yr) ?>" <?= $transaction_year === $yr ? 'selected' : '' ?>>
                                <?= htmlspecialchars($yr) ?>
                            </option>
                        <?php endforeach; ?>
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
                    <label>Transaction Month</label>
                    <input type="month" name="primary_period" id="primaryPeriodSelect" value="<?= htmlspecialchars($primary_period) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <button type="button" class="btn-collapse" id="collapseBtn"><i class="fa-solid fa-compress"></i> Collapse</button>
                    <a href="export_consolidated.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
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
                                                <th colspan="4"><?php echo !empty($zone) ? 'Zone: ' . htmlspecialchars($zone) : 'All Zones'; ?></th>
                                                <th colspan="<?php echo $has_regions ? ($num_regions + 2) : 2; ?>">CONSOLIDATED PROFIT & LOSS STATEMENT</th>
                                            </tr>
                                            <tr>
                                                <th colspan="4"></th>
                                                <?php 
                                                $period_display = !empty($primary_period) ? strtoupper(date('F Y', strtotime($primary_period . '-01'))) : '(Transaction Month)';
                                                ?>
                                                <th colspan="<?php echo $has_regions ? ($num_regions + 2) : 2; ?>"><?php echo $period_display; ?></th>
                                            </tr>
                                            <tr>
                                                <th colspan="4"></th>
                                                <?php if ($has_regions): 
                                                    foreach ($regions_in_zone as $r): ?>
                                                        <th><?php echo htmlspecialchars($r); ?></th>
                                                    <?php endforeach; ?>
                                                    <th colspan="2">GRAND TOTAL</th>
                                                <?php else: ?>
                                                    <th colspan="2">TOTAL</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody class="report-tbody">

                                        <tr class="initial-spacer">
                                            <td colspan="<?php echo $has_regions ? ($num_regions + 6) : 6; ?>"></td>
                                        </tr>
                                        <tr class="revenues-header-row">
                                            <td style="background-color: #ff7f29; font-weight: bold;">REVENUES</td>
                                            <td colspan="<?php echo $has_regions ? ($num_regions + 5) : 5; ?>" style="background-color: #ff7f29; font-weight: bold;"></td>
                                        </tr>
                                            <?php if (empty($table_rows)): ?>
                                                <tr>
                                                    <td colspan="<?php echo $has_regions ? ($num_regions + 6) : 6; ?>" style="text-align: center;">No data structure available</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($table_rows as $row): 
                                                    if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                                        echo '<tr class="spacer-row" style="height: 20px;"><td colspan="' . ($has_regions ? ($num_regions + 6) : 6) . '"></td></tr>';
                                                        continue;
                                                    }
                                                    $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
                                                    $is_header = !empty($row['is_section_header']);
                                                    
                                                    $primary_total = $row['primary_total'] ?? 0;
                                                    
                                                    if (!$is_summary_row && !empty($row['is_inj2'])) {
                                                        $primary_total = -$primary_total;
                                                    }
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
                                                        
                                                        <?php if ($has_regions): ?>
                                                            <?php foreach ($regions_in_zone as $rn): 
                                                                $r_amt = $row['region_totals'][$rn] ?? 0;
                                                                if (!$is_summary_row && !empty($row['is_inj2'])) $r_amt = -$r_amt;
                                                            ?>
                                                                <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="text-align: right;<?= ($r_amt < 0) ? ' color: red;' : '' ?>">
                                                                    <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($r_amt, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>

                                                        <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($primary_total < 0) ? 'color: red;' : '' ?>">
                                                            <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($primary_total, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                                        </td>
                                                        <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                                    </tr>
                                                    <?php 
                                                    // Spacer row after summary rows, EXCEPT after EBITDA, EBIT, EBT, and NET rows
                                                    if ($is_summary_row && !$is_header && empty($row['skip_spacer'])): 
                                                    ?>
                                                        <tr class="spacer-row" data-spacer-for="<?= htmlspecialchars($row['sort_order'] ?? '') ?>" style="height: 20px;"><td colspan="<?php echo $has_regions ? ($num_regions + 6) : 6; ?>"></td></tr>
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
                    const glOldRadio = document.getElementById('glOldRadio');
                    const glNewRadio = document.getElementById('glNewRadio');
                    const glCodeMode = glOldRadio.checked ? 'old' : (glNewRadio.checked ? 'new' : 'old');

                    // Validation 2: GL code mode restrictions
                    if (glCodeMode === 'old') {
                        if (!isMarch2026OrEarlier(primaryPeriod)) {
                            showModal('Old GL Code is only available for March 2026 and earlier.');
                            return false;
                        }
                    } else if (glCodeMode === 'new') {
                        if (!isApril2026OrLater(primaryPeriod)) {
                            showModal('New GL Code is only available for April 2026 onwards.');
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

            </script>

        </div>
    </main>

<?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
<?php
// consolidated_with_adjustment.php
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

// ============================================================
// FILTERS
// ============================================================
$mainzone = '';
$selected_regions = [];
$selected_areas = [];

$zone = $_GET['zone'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

// ============================================================
// FETCH REGIONS IN SELECTED ZONE + YEAR + MONTH
// ============================================================
$regions_in_zone = [];

if (!empty($zone) && !empty($transaction_year) && !empty($primary_period)) {
    $month_value = $primary_period . '-01';
    
    $r_query = "
        SELECT DISTINCT region
        FROM fs_reports.manual_adjustment
        WHERE zone = ?
          AND transaction_year = ?
          AND transaction_month = ?
          AND region IS NOT NULL
          AND region != ''
        ORDER BY region
    ";

    $r_stmt = mysqli_prepare($conn, $r_query);

    if ($r_stmt) {
        mysqli_stmt_bind_param($r_stmt, 'sss', $zone, $transaction_year, $month_value);
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

// ============================================================
// ERROR / VALIDATION
// ============================================================
$error_message = '';

if (!empty($primary_period) && empty($previous_period)) {
    $date_obj = DateTime::createFromFormat('Y-m', $primary_period);

    if ($date_obj) {
        $date_obj->modify('-1 month');
        $previous_period = $date_obj->format('Y-m');
    }
}

function compareMonths(string $month1, string $month2): int
{
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

// No GL-code/month validation required.
$show_error = false;
$valid_filters = true;

// ============================================================
// RESET
// ============================================================
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: consolidated_with_adjustment.php");
    exit;
}

// ============================================================
// DROPDOWN OPTIONS
// ============================================================
$distinct_zn = [];
$distinct_years = [];
$month_options = [];

// Zones
$hierarchy_query = "
    SELECT DISTINCT zone
    FROM fs_reports.manual_adjustment
    WHERE zone IS NOT NULL
      AND zone != ''
    ORDER BY zone
";

$hierarchy_res = mysqli_query($conn, $hierarchy_query);

if ($hierarchy_res) {
    while ($h = mysqli_fetch_assoc($hierarchy_res)) {
        $zn = trim((string)($h['zone'] ?? ''));

        if ($zn !== '' && !in_array($zn, $distinct_zn, true)) {
            $distinct_zn[] = $zn;
        }
    }
}

sort($distinct_zn);

// Years
$years_query = "
    SELECT DISTINCT transaction_year
    FROM fs_reports.manual_adjustment
    WHERE transaction_year IS NOT NULL
    ORDER BY transaction_year DESC
";

$years_res = mysqli_query($conn, $years_query);

if ($years_res) {
    while ($y = mysqli_fetch_assoc($years_res)) {
        $val = trim((string)($y['transaction_year'] ?? ''));

        if ($val !== '' && !in_array($val, $distinct_years, true)) {
            $distinct_years[] = $val;
        }
    }
}

// ============================================================
// REPORT STRUCTURE
//
// Uses only:
// sort_order
// description
// sub_order
// gl_description_comparative
//
// FILTERED BY: zone + transaction_year + transaction_month
// ============================================================
$report_structure = [];
$sort_order_descriptions = [];

// Build WHERE clause for structure query
$structure_where = "WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL";
$structure_params = [];
$structure_types = "";

if (!empty($zone)) {
    $structure_where .= " AND zone = ?";
    $structure_params[] = $zone;
    $structure_types .= "s";
}

if (!empty($transaction_year)) {
    $structure_where .= " AND transaction_year = ?";
    $structure_params[] = $transaction_year;
    $structure_types .= "s";
}

if (!empty($primary_period)) {
    $month_value = $primary_period . '-01';
    $structure_where .= " AND transaction_month = ?";
    $structure_params[] = $month_value;
    $structure_types .= "s";
}

// Detail rows - filtered by zone + year + month
$structure_query = "
    SELECT DISTINCT
        sort_order,
        description,
        sub_order,
        gl_description_comparative
    FROM fs_reports.manual_adjustment
    $structure_where
    ORDER BY sort_order ASC, sub_order ASC
";

$structure_stmt = mysqli_prepare($conn, $structure_query);

if ($structure_stmt) {
    if (!empty($structure_params)) {
        mysqli_stmt_bind_param($structure_stmt, $structure_types, ...$structure_params);
    }

    mysqli_stmt_execute($structure_stmt);
    $structure_result = mysqli_stmt_get_result($structure_stmt);

    if ($structure_result) {
        while ($row = mysqli_fetch_assoc($structure_result)) {

            $key = $row['sort_order'] . '|' . $row['sub_order'];

            if (!isset($report_structure[$key])) {
                $report_structure[$key] = [
                    'sort_order' => $row['sort_order'],
                    'description' => $row['description'],
                    'sub_order' => $row['sub_order'],
                    'gl_description_comparative' => $row['gl_description_comparative']
                ];
            }

            if (
                !isset($sort_order_descriptions[$row['sort_order']])
                && !empty($row['description'])
            ) {
                $sort_order_descriptions[$row['sort_order']] = $row['description'];
            }
        }
    }

    mysqli_stmt_close($structure_stmt);
}

// ============================================================
// DIRECT TOTAL ROWS
//
// Sort orders 6, 8 and 11 are DIRECT TOTAL ROWS.
//
// Their database records have:
// sub_order = NULL
// gl_description_comparative = NULL
//
// FILTERED BY: zone + transaction_year + transaction_month
// ============================================================
$direct_total_where = "WHERE sort_order IN (6, 8, 11) AND sub_order IS NULL AND gl_description_comparative IS NULL";
$direct_total_params = [];
$direct_total_types = "";

if (!empty($zone)) {
    $direct_total_where .= " AND zone = ?";
    $direct_total_params[] = $zone;
    $direct_total_types .= "s";
}

if (!empty($transaction_year)) {
    $direct_total_where .= " AND transaction_year = ?";
    $direct_total_params[] = $transaction_year;
    $direct_total_types .= "s";
}

if (!empty($primary_period)) {
    $month_value = $primary_period . '-01';
    $direct_total_where .= " AND transaction_month = ?";
    $direct_total_params[] = $month_value;
    $direct_total_types .= "s";
}

$direct_total_query = "
    SELECT DISTINCT
        sort_order,
        description
    FROM fs_reports.manual_adjustment
    $direct_total_where
    ORDER BY sort_order ASC
";

$direct_total_stmt = mysqli_prepare($conn, $direct_total_query);

if ($direct_total_stmt) {
    if (!empty($direct_total_params)) {
        mysqli_stmt_bind_param($direct_total_stmt, $direct_total_types, ...$direct_total_params);
    }

    mysqli_stmt_execute($direct_total_stmt);
    $direct_total_result = mysqli_stmt_get_result($direct_total_stmt);

    if ($direct_total_result) {
        while ($row = mysqli_fetch_assoc($direct_total_result)) {

            // NULL sub_order becomes an empty string in the PHP key.
            $key = $row['sort_order'] . '|';

            if (!isset($report_structure[$key])) {

                $report_structure[$key] = [
                    'sort_order' => $row['sort_order'],
                    'description' => $row['description'],
                    'sub_order' => null,
                    'gl_description_comparative' => null
                ];
            }

            if (
                !isset($sort_order_descriptions[$row['sort_order']])
                && !empty($row['description'])
            ) {
                $sort_order_descriptions[$row['sort_order']] = $row['description'];
            }
        }
    }

    mysqli_stmt_close($direct_total_stmt);
}

// ============================================================
// SORT REPORT STRUCTURE
// ============================================================
uksort($report_structure, function ($a, $b) {

    [$aSort, $aSub] = array_pad(explode('|', $a, 2), 2, '');
    [$bSort, $bSub] = array_pad(explode('|', $b, 2), 2, '');

    $sortCompare = (int)$aSort <=> (int)$bSort;

    if ($sortCompare !== 0) {
        return $sortCompare;
    }

    // Direct total rows with NULL sub_order first
    if ($aSub === '' && $bSub !== '') {
        return -1;
    }

    if ($aSub !== '' && $bSub === '') {
        return 1;
    }

    return (int)$aSub <=> (int)$bSub;
});

// ============================================================
// COMPUTE TABLE ROWS
// ============================================================
function compute_table_rows_for_region_area(
    mysqli $conn,
    string $mainzone,
    string $zone,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    array $report_structure,
    array $sort_order_descriptions,
    string $region,
    string $area,
    array $regions_in_zone,
    bool $use_real_data = true
): array {

    // ========================================================
    // BUILD WHERE CLAUSE
    // ========================================================
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

    $base_where = !empty($where_conditions)
        ? "WHERE " . implode(" AND ", $where_conditions)
        : "WHERE 1=1";

    // ========================================================
    // PRIMARY PERIOD DATA
    //
    // Keep MLFSi and Jewelers separately internally.
    // Display will use MLFSi + Jewelers.
    // ========================================================
    $primary_data = [];

    if ($use_real_data && !empty($primary_period)) {

        $p_parts = explode('-', $primary_period);
        $p_year = $p_parts[0];
        $p_month_val = $primary_period . '-01';

        $primary_sql = "
            SELECT
                sort_order,
                sub_order,
                region,
                SUM(mlfsi) AS mlfsi_amount,
                SUM(jewelers) AS jewelers_amount,
                SUM(mlfsi + jewelers) AS total_amount
            FROM fs_reports.manual_adjustment
            $base_where
            AND transaction_year = ?
            AND transaction_month = ?
            GROUP BY sort_order, sub_order, region
        ";

        $primary_params = array_merge(
            $params,
            [$p_year, $p_month_val]
        );

        $primary_types = $types . "ss";

        $primary_stmt = mysqli_prepare($conn, $primary_sql);

        if ($primary_stmt) {

            if (!empty($primary_params)) {
                mysqli_stmt_bind_param(
                    $primary_stmt,
                    $primary_types,
                    ...$primary_params
                );
            }

            mysqli_stmt_execute($primary_stmt);

            $primary_result = mysqli_stmt_get_result($primary_stmt);

            while ($row = mysqli_fetch_assoc($primary_result)) {

                $key = $row['sort_order'] . '|' . $row['sub_order'];

                $primary_data[$key][$row['region']] = [
                    'mlfsi' => floatval($row['mlfsi_amount']),
                    'jewelers' => floatval($row['jewelers_amount']),
                    'total' => floatval($row['total_amount'])
                ];
            }

            mysqli_stmt_close($primary_stmt);
        }
    }

    // ========================================================
    // PREVIOUS PERIOD DATA
    // ========================================================
    $previous_data = [];

    if ($use_real_data && !empty($previous_period)) {

        $prev_parts = explode('-', $previous_period);
        $prev_year_val = $prev_parts[0];
        $prev_month_val = $previous_period . '-01';

        $previous_sql = "
            SELECT
                sort_order,
                sub_order,
                region,
                SUM(mlfsi) AS mlfsi_amount,
                SUM(jewelers) AS jewelers_amount,
                SUM(mlfsi + jewelers) AS total_amount
            FROM fs_reports.manual_adjustment
            $base_where
            AND transaction_year = ?
            AND transaction_month = ?
            GROUP BY sort_order, sub_order, region
        ";

        $previous_params = array_merge(
            $params,
            [$prev_year_val, $prev_month_val]
        );

        $previous_types = $types . "ss";

        $previous_stmt = mysqli_prepare($conn, $previous_sql);

        if ($previous_stmt) {

            if (!empty($previous_params)) {
                mysqli_stmt_bind_param(
                    $previous_stmt,
                    $previous_types,
                    ...$previous_params
                );
            }

            mysqli_stmt_execute($previous_stmt);

            $previous_result = mysqli_stmt_get_result($previous_stmt);

            while ($row = mysqli_fetch_assoc($previous_result)) {

                $key = $row['sort_order'] . '|' . $row['sub_order'];

                $previous_data[$key][$row['region']] = [
                    'mlfsi' => floatval($row['mlfsi_amount']),
                    'jewelers' => floatval($row['jewelers_amount']),
                    'total' => floatval($row['total_amount'])
                ];
            }

            mysqli_stmt_close($previous_stmt);
        }
    }

    // ========================================================
    // BUILD TABLE ROWS
    // ========================================================
    $table_rows = [];

    foreach ($report_structure as $key => $structure) {

        $sort_order = $structure['sort_order'];
        $sub_order = $structure['sub_order'];
        $gl_description = $structure['gl_description_comparative'];

        $is_inj2 = false;

        $row_region_totals = [];

        foreach ($regions_in_zone as $r_name) {

            $row_region_totals[$r_name] = [
                'mlfsi' => 0,
                'jewelers' => 0,
                'total' => 0
            ];
        }

        $primary_total = [
            'mlfsi' => 0,
            'jewelers' => 0,
            'total' => 0
        ];

        $previous_total = [
            'mlfsi' => 0,
            'jewelers' => 0,
            'total' => 0
        ];

        // ====================================================
        // PRIMARY TOTALS
        // ====================================================
        if (isset($primary_data[$key])) {

            foreach ($primary_data[$key] as $r_name => $amounts) {

                if (empty($regions_in_zone)) {

                    $primary_total['mlfsi'] += $amounts['mlfsi'];
                    $primary_total['jewelers'] += $amounts['jewelers'];
                    $primary_total['total'] += $amounts['total'];

                } elseif (isset($row_region_totals[$r_name])) {

                    $row_region_totals[$r_name]['mlfsi'] += $amounts['mlfsi'];
                    $row_region_totals[$r_name]['jewelers'] += $amounts['jewelers'];
                    $row_region_totals[$r_name]['total'] += $amounts['total'];

                    $primary_total['mlfsi'] += $amounts['mlfsi'];
                    $primary_total['jewelers'] += $amounts['jewelers'];
                    $primary_total['total'] += $amounts['total'];
                }
            }
        }

        // ====================================================
        // PREVIOUS TOTALS
        // ====================================================
        if (isset($previous_data[$key])) {

            foreach ($previous_data[$key] as $r_name => $amounts) {

                if (
                    empty($regions_in_zone)
                    || in_array($r_name, $regions_in_zone, true)
                ) {

                    $previous_total['mlfsi'] += $amounts['mlfsi'];
                    $previous_total['jewelers'] += $amounts['jewelers'];
                    $previous_total['total'] += $amounts['total'];
                }
            }
        }

        // ====================================================
        // ADD DETAIL ROW
        //
        // Sort orders 6, 8, 11 are handled as direct total
        // rows later, but the actual database values are still
        // loaded here.
        // ====================================================
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

    // ========================================================
    // GROUP BY SORT ORDER
    // ========================================================
    $grouped_rows = [];

    foreach ($table_rows as $row) {

        $sort_order = $row['sort_order'];

        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }

        $grouped_rows[$sort_order][] = $row;
    }

    $final_table_rows = [];

    // ========================================================
    // CUMULATIVE COUNTERS
    // ========================================================
    $rev_mlfsi_p = 0;
    $rev_jew_p = 0;
    $rev_tot_p = 0;

    $rev_mlfsi_prev = 0;
    $rev_jew_prev = 0;
    $rev_tot_prev = 0;

    $sa_mlfsi_p = 0;
    $sa_jew_p = 0;
    $sa_tot_p = 0;

    $sa_mlfsi_prev = 0;
    $sa_jew_prev = 0;
    $sa_tot_prev = 0;

    $gp_mlfsi_p = 0;
    $gp_jew_p = 0;
    $gp_tot_p = 0;

    $gp_mlfsi_prev = 0;
    $gp_jew_prev = 0;
    $gp_tot_prev = 0;

    $ebitda_mlfsi_p = 0;
    $ebitda_jew_p = 0;
    $ebitda_tot_p = 0;

    $ebitda_mlfsi_prev = 0;
    $ebitda_jew_prev = 0;
    $ebitda_tot_prev = 0;

    $ebit_mlfsi_p = 0;
    $ebit_jew_p = 0;
    $ebit_tot_p = 0;

    $ebit_mlfsi_prev = 0;
    $ebit_jew_prev = 0;
    $ebit_tot_prev = 0;

    $ebt_mlfsi_p = 0;
    $ebt_jew_p = 0;
    $ebt_tot_p = 0;

    $ebt_mlfsi_prev = 0;
    $ebt_jew_prev = 0;
    $ebt_tot_prev = 0;

    $net_mlfsi_p = 0;
    $net_jew_p = 0;
    $net_tot_p = 0;

    $net_mlfsi_prev = 0;
    $net_jew_prev = 0;
    $net_tot_prev = 0;

    // ========================================================
    // REGION CUMULATIVE ARRAYS
    // ========================================================
    $rev_reg_p = [];
    $sa_reg_p = [];
    $gp_reg_p = [];
    $ebitda_reg_p = [];
    $ebit_reg_p = [];
    $ebt_reg_p = [];
    $net_reg_p = [];

    // ========================================================
    // PROCESS SORT ORDERS
    // ========================================================
    foreach ($grouped_rows as $sort_order => $rows) {

        // ====================================================
        // DETAIL ROWS
        //
        // Sort orders 6, 8 and 11 are direct total rows.
        // Therefore, do NOT display them as detail rows.
        // ====================================================
        if (!in_array((int)$sort_order, [6, 8, 11])) {

            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }

        // ====================================================
        // CALCULATE TOTAL FOR SORT ORDER
        // ====================================================
        $total_primary = [
            'mlfsi' => 0,
            'jewelers' => 0,
            'total' => 0
        ];

        $total_previous = [
            'mlfsi' => 0,
            'jewelers' => 0,
            'total' => 0
        ];

        foreach ($rows as $row) {

            $total_primary['mlfsi'] += $row['primary_total']['mlfsi'];
            $total_primary['jewelers'] += $row['primary_total']['jewelers'];
            $total_primary['total'] += $row['primary_total']['total'];

            $total_previous['mlfsi'] += $row['previous_total']['mlfsi'];
            $total_previous['jewelers'] += $row['previous_total']['jewelers'];
            $total_previous['total'] += $row['previous_total']['total'];
        }

        // ====================================================
        // REGION TOTALS
        //
        // Internally keep MLFSi/Jewelers.
        // Display will combine them.
        // ====================================================
        $summary_region_totals = [];

        foreach ($regions_in_zone as $r_name) {

            $summary_region_totals[$r_name] = [
                'mlfsi' => 0,
                'jewelers' => 0,
                'total' => 0
            ];

            foreach ($rows as $row) {

                if (isset($row['region_totals'][$r_name])) {

                    $summary_region_totals[$r_name]['mlfsi']
                        += $row['region_totals'][$r_name]['mlfsi'];

                    $summary_region_totals[$r_name]['jewelers']
                        += $row['region_totals'][$r_name]['jewelers'];

                    $summary_region_totals[$r_name]['total']
                        += $row['region_totals'][$r_name]['total'];
                }
            }
        }

        // ====================================================
        // TOTAL REVENUES
        // ====================================================
        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {

            $rev_tot_p += $total_primary['total'];
            $rev_tot_prev += $total_previous['total'];

            $rev_mlfsi_p += $total_primary['mlfsi'];
            $rev_mlfsi_prev += $total_previous['mlfsi'];

            $rev_jew_p += $total_primary['jewelers'];
            $rev_jew_prev += $total_previous['jewelers'];

            if (empty($rev_reg_p)) {

                $rev_reg_p = array_fill_keys(
                    $regions_in_zone,
                    [
                        'mlfsi' => 0,
                        'jewelers' => 0,
                        'total' => 0
                    ]
                );
            }

            foreach ($regions_in_zone as $rn) {

                $rev_reg_p[$rn]['mlfsi']
                    += $summary_region_totals[$rn]['mlfsi'];

                $rev_reg_p[$rn]['jewelers']
                    += $summary_region_totals[$rn]['jewelers'];

                $rev_reg_p[$rn]['total']
                    += $summary_region_totals[$rn]['total'];
            }
        }

        // ====================================================
        // SELLING & ADMIN EXPENSES
        // ====================================================
        if ((int)$sort_order == 22 || (int)$sort_order == 23) {

            $sa_tot_p += $total_primary['total'];
            $sa_tot_prev += $total_previous['total'];

            $sa_mlfsi_p += $total_primary['mlfsi'];
            $sa_mlfsi_prev += $total_previous['mlfsi'];

            $sa_jew_p += $total_primary['jewelers'];
            $sa_jew_prev += $total_previous['jewelers'];

            if (empty($sa_reg_p)) {

                $sa_reg_p = array_fill_keys(
                    $regions_in_zone,
                    [
                        'mlfsi' => 0,
                        'jewelers' => 0,
                        'total' => 0
                    ]
                );
            }

            foreach ($regions_in_zone as $rn) {

                $sa_reg_p[$rn]['mlfsi']
                    += $summary_region_totals[$rn]['mlfsi'];

                $sa_reg_p[$rn]['jewelers']
                    += $summary_region_totals[$rn]['jewelers'];

                $sa_reg_p[$rn]['total']
                    += $summary_region_totals[$rn]['total'];
            }
        }

        // ====================================================
        // INCREASE / DECREASE
        // ====================================================
        $inc_dec = $total_primary['total'] - $total_previous['total'];

        $percentage = 0;

        if ($total_previous['total'] != 0) {

            $percentage =
                ($inc_dec / $total_previous['total']) * 100;

        } elseif ($total_primary['total'] != 0) {

            $percentage = 100;
        }

        $description =
            isset($sort_order_descriptions[$sort_order])
            ? $sort_order_descriptions[$sort_order]
            : "Total for Sort Order " . $sort_order;

        // ====================================================
        // ADD SUMMARY ROW
        //
        // For 6, 8, 11 this is the DIRECT TOTAL ROW.
        // There are no detail rows displayed.
        // ====================================================
        if (!in_array((int)$sort_order, [24, 25, 26])) {

            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,

                'primary_total' => $total_primary,
                'previous_total' => $total_previous,

                'region_totals' => $summary_region_totals,

                'inc_dec' => $inc_dec,
                'percentage' => $percentage
            ];

            if (in_array((int)$sort_order, [22, 23])) {
                $final_table_rows[] = [
                    'is_manual_spacer' => true
                ];
            }
        }

        // ====================================================
        // TOTAL REVENUES AFTER SORT ORDER 20
        // ====================================================
        if ((int)$sort_order == 20) {

            $inc_dec_rev = $rev_tot_p - $rev_tot_prev;

            $pct_rev =
                ($rev_tot_prev != 0)
                ? ($inc_dec_rev / abs($rev_tot_prev)) * 100
                : ($rev_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => 'TOTAL REVENUES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,

                'primary_total' => [
                    'mlfsi' => $rev_mlfsi_p,
                    'jewelers' => $rev_jew_p,
                    'total' => $rev_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $rev_mlfsi_prev,
                    'jewelers' => $rev_jew_prev,
                    'total' => $rev_tot_prev
                ],

                'region_totals' => $rev_reg_p,

                'inc_dec' => $inc_dec_rev,
                'percentage' => $pct_rev
            ];

            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

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

        // ====================================================
        // GROSS PROFIT AFTER SORT ORDER 21
        // ====================================================
        if ((int)$sort_order == 21) {

            $gp_tot_p = $rev_tot_p - $total_primary['total'];
            $gp_tot_prev = $rev_tot_prev - $total_previous['total'];

            $gp_mlfsi_p =
                $rev_mlfsi_p - $total_primary['mlfsi'];

            $gp_mlfsi_prev =
                $rev_mlfsi_prev - $total_previous['mlfsi'];

            $gp_jew_p =
                $rev_jew_p - $total_primary['jewelers'];

            $gp_jew_prev =
                $rev_jew_prev - $total_previous['jewelers'];

            $gp_reg_p = [];

            foreach ($regions_in_zone as $rn) {

                $gp_reg_p[$rn] = [
                    'mlfsi' =>
                        ($rev_reg_p[$rn]['mlfsi'] ?? 0)
                        - ($summary_region_totals[$rn]['mlfsi'] ?? 0),

                    'jewelers' =>
                        ($rev_reg_p[$rn]['jewelers'] ?? 0)
                        - ($summary_region_totals[$rn]['jewelers'] ?? 0),

                    'total' =>
                        ($rev_reg_p[$rn]['total'] ?? 0)
                        - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }

            $inc_dec_gp =
                $gp_tot_p - $gp_tot_prev;

            $pct_gp =
                ($gp_tot_prev != 0)
                ? ($inc_dec_gp / abs($gp_tot_prev)) * 100
                : ($gp_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,

                'primary_total' => [
                    'mlfsi' => $gp_mlfsi_p,
                    'jewelers' => $gp_jew_p,
                    'total' => $gp_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $gp_mlfsi_prev,
                    'jewelers' => $gp_jew_prev,
                    'total' => $gp_tot_prev
                ],

                'region_totals' => $gp_reg_p,

                'inc_dec' => $inc_dec_gp,
                'percentage' => $pct_gp
            ];

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

        // ====================================================
        // EBITDA AFTER SORT ORDER 23
        // ====================================================
        if ((int)$sort_order == 23) {

            $inc_dec_sa =
                $sa_tot_p - $sa_tot_prev;

            $pct_sa =
                ($sa_tot_prev != 0)
                ? ($inc_dec_sa / abs($sa_tot_prev)) * 100
                : ($sa_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,

                'primary_total' => [
                    'mlfsi' => $sa_mlfsi_p,
                    'jewelers' => $sa_jew_p,
                    'total' => $sa_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $sa_mlfsi_prev,
                    'jewelers' => $sa_jew_prev,
                    'total' => $sa_tot_prev
                ],

                'region_totals' => $sa_reg_p,

                'inc_dec' => $inc_dec_sa,
                'percentage' => $pct_sa
            ];

            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            // EBITDA
            $ebitda_tot_p =
                $gp_tot_p - $sa_tot_p;

            $ebitda_tot_prev =
                $gp_tot_prev - $sa_tot_prev;

            $ebitda_mlfsi_p =
                $gp_mlfsi_p - $sa_mlfsi_p;

            $ebitda_mlfsi_prev =
                $gp_mlfsi_prev - $sa_mlfsi_prev;

            $ebitda_jew_p =
                $gp_jew_p - $sa_jew_p;

            $ebitda_jew_prev =
                $gp_jew_prev - $sa_jew_prev;

            $ebitda_reg_p = [];

            foreach ($regions_in_zone as $rn) {

                $ebitda_reg_p[$rn] = [
                    'mlfsi' =>
                        ($gp_reg_p[$rn]['mlfsi'] ?? 0)
                        - ($sa_reg_p[$rn]['mlfsi'] ?? 0),

                    'jewelers' =>
                        ($gp_reg_p[$rn]['jewelers'] ?? 0)
                        - ($sa_reg_p[$rn]['jewelers'] ?? 0),

                    'total' =>
                        ($gp_reg_p[$rn]['total'] ?? 0)
                        - ($sa_reg_p[$rn]['total'] ?? 0)
                ];
            }

            $inc_dec_ebitda =
                $ebitda_tot_p - $ebitda_tot_prev;

            $pct_ebitda =
                ($ebitda_tot_prev != 0)
                ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100
                : ($ebitda_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,

                'primary_total' => [
                    'mlfsi' => $ebitda_mlfsi_p,
                    'jewelers' => $ebitda_jew_p,
                    'total' => $ebitda_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $ebitda_mlfsi_prev,
                    'jewelers' => $ebitda_jew_prev,
                    'total' => $ebitda_tot_prev
                ],

                'region_totals' => $ebitda_reg_p,

                'inc_dec' => $inc_dec_ebitda,
                'percentage' => $pct_ebitda
            ];
        }

        // ====================================================
        // EBIT AFTER SORT ORDER 24
        // ====================================================
        if ((int)$sort_order == 24) {

            $ebit_tot_p =
                $ebitda_tot_p - $total_primary['total'];

            $ebit_tot_prev =
                $ebitda_tot_prev - $total_previous['total'];

            $ebit_mlfsi_p =
                $ebitda_mlfsi_p - $total_primary['mlfsi'];

            $ebit_mlfsi_prev =
                $ebitda_mlfsi_prev - $total_previous['mlfsi'];

            $ebit_jew_p =
                $ebitda_jew_p - $total_primary['jewelers'];

            $ebit_jew_prev =
                $ebitda_jew_prev - $total_previous['jewelers'];

            $ebit_reg_p = [];

            foreach ($regions_in_zone as $rn) {

                $ebit_reg_p[$rn] = [
                    'mlfsi' =>
                        ($ebitda_reg_p[$rn]['mlfsi'] ?? 0)
                        - ($summary_region_totals[$rn]['mlfsi'] ?? 0),

                    'jewelers' =>
                        ($ebitda_reg_p[$rn]['jewelers'] ?? 0)
                        - ($summary_region_totals[$rn]['jewelers'] ?? 0),

                    'total' =>
                        ($ebitda_reg_p[$rn]['total'] ?? 0)
                        - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }

            $inc_dec_ebit =
                $ebit_tot_p - $ebit_tot_prev;

            $pct_ebit =
                ($ebit_tot_prev != 0)
                ? ($inc_dec_ebit / abs($ebit_tot_prev)) * 100
                : ($ebit_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,

                'primary_total' => [
                    'mlfsi' => $ebit_mlfsi_p,
                    'jewelers' => $ebit_jew_p,
                    'total' => $ebit_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $ebit_mlfsi_prev,
                    'jewelers' => $ebit_jew_prev,
                    'total' => $ebit_tot_prev
                ],

                'region_totals' => $ebit_reg_p,

                'inc_dec' => $inc_dec_ebit,
                'percentage' => $pct_ebit
            ];
        }

        // ====================================================
        // EBT AFTER SORT ORDER 25
        // ====================================================
        if ((int)$sort_order == 25) {

            $ebt_tot_p =
                $ebit_tot_p - $total_primary['total'];

            $ebt_tot_prev =
                $ebit_tot_prev - $total_previous['total'];

            $ebt_mlfsi_p =
                $ebit_mlfsi_p - $total_primary['mlfsi'];

            $ebt_mlfsi_prev =
                $ebit_mlfsi_prev - $total_previous['mlfsi'];

            $ebt_jew_p =
                $ebit_jew_p - $total_primary['jewelers'];

            $ebt_jew_prev =
                $ebit_jew_prev - $total_previous['jewelers'];

            $ebt_reg_p = [];

            foreach ($regions_in_zone as $rn) {

                $ebt_reg_p[$rn] = [
                    'mlfsi' =>
                        ($ebit_reg_p[$rn]['mlfsi'] ?? 0)
                        - ($summary_region_totals[$rn]['mlfsi'] ?? 0),

                    'jewelers' =>
                        ($ebit_reg_p[$rn]['jewelers'] ?? 0)
                        - ($summary_region_totals[$rn]['jewelers'] ?? 0),

                    'total' =>
                        ($ebit_reg_p[$rn]['total'] ?? 0)
                        - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }

            $inc_dec_ebt =
                $ebt_tot_p - $ebt_tot_prev;

            $pct_ebt =
                ($ebt_tot_prev != 0)
                ? ($inc_dec_ebt / abs($ebt_tot_prev)) * 100
                : ($ebt_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,

                'primary_total' => [
                    'mlfsi' => $ebt_mlfsi_p,
                    'jewelers' => $ebt_jew_p,
                    'total' => $ebt_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $ebt_mlfsi_prev,
                    'jewelers' => $ebt_jew_prev,
                    'total' => $ebt_tot_prev
                ],

                'region_totals' => $ebt_reg_p,

                'inc_dec' => $inc_dec_ebt,
                'percentage' => $pct_ebt
            ];
        }

        // ====================================================
        // NET INCOME AFTER SORT ORDER 26
        // ====================================================
        if ((int)$sort_order == 26) {

            $net_tot_p =
                $ebt_tot_p - $total_primary['total'];

            $net_tot_prev =
                $ebt_tot_prev - $total_previous['total'];

            $net_mlfsi_p =
                $ebt_mlfsi_p - $total_primary['mlfsi'];

            $net_mlfsi_prev =
                $ebt_mlfsi_prev - $total_previous['mlfsi'];

            $net_jew_p =
                $ebt_jew_p - $total_primary['jewelers'];

            $net_jew_prev =
                $ebt_jew_prev - $total_previous['jewelers'];

            $net_reg_p = [];

            foreach ($regions_in_zone as $rn) {

                $net_reg_p[$rn] = [
                    'mlfsi' =>
                        ($ebt_reg_p[$rn]['mlfsi'] ?? 0)
                        - ($summary_region_totals[$rn]['mlfsi'] ?? 0),

                    'jewelers' =>
                        ($ebt_reg_p[$rn]['jewelers'] ?? 0)
                        - ($summary_region_totals[$rn]['jewelers'] ?? 0),

                    'total' =>
                        ($ebt_reg_p[$rn]['total'] ?? 0)
                        - ($summary_region_totals[$rn]['total'] ?? 0)
                ];
            }

            $inc_dec_net =
                $net_tot_p - $net_tot_prev;

            $pct_net =
                ($net_tot_prev != 0)
                ? ($inc_dec_net / abs($net_tot_prev)) * 100
                : ($net_tot_p != 0 ? 100 : 0);

            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            $final_table_rows[] = [
                'sort_order' => 'TOTAL NET INCOME/LOSS',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,

                'primary_total' => [
                    'mlfsi' => $net_mlfsi_p,
                    'jewelers' => $net_jew_p,
                    'total' => $net_tot_p
                ],

                'previous_total' => [
                    'mlfsi' => $net_mlfsi_prev,
                    'jewelers' => $net_jew_prev,
                    'total' => $net_tot_prev
                ],

                'region_totals' => $net_reg_p,

                'inc_dec' => $inc_dec_net,
                'percentage' => $pct_net
            ];
        }
    }

    return $final_table_rows;
}

// ============================================================
// BUILD TABLES FOR SELECTED REGION / AREA
// ============================================================
$tables_by_region = [];

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
            $areas_for_region = [''];
        }

        foreach ($areas_for_region as $ar) {

            $tables_by_region[$rg] =
                $tables_by_region[$rg] ?? [];

            $tables_by_region[$rg][] = [
                'area' => $ar,

                'rows' =>
                    compute_table_rows_for_region_area(
                        $conn,
                        $mainzone,
                        $zone,
                        $transaction_year,
                        $primary_period,
                        $previous_period,
                        $report_structure,
                        $sort_order_descriptions,
                        $rg,
                        $ar,
                        $regions_in_zone,
                        $valid_filters
                    )
            ];
        }
    }

} else {

    // No region selected:
    // All Regions / All Areas
    $tables_by_region[''] = [
        [
            'area' => '',

            'rows' =>
                compute_table_rows_for_region_area(
                    $conn,
                    $mainzone,
                    $zone,
                    $transaction_year,
                    $primary_period,
                    $previous_period,
                    $report_structure,
                    $sort_order_descriptions,
                    '',
                    '',
                    $regions_in_zone,
                    $valid_filters
                )
        ]
    ];
}

// ============================================================
// COLUMN COUNT
//
// 4 fixed columns:
// 1. sort_order
// 2. description
// 3. gl_description
// 4. blank
//
// Then:
// 1 amount column per region
// + 1 Total column
// ============================================================
$total_columns = $has_regions
    ? ($num_regions + 5)
    : 5;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Consolidated Report with Adjustment</title>

    <link
        rel="icon"
        href="../images/MLW%20Logo.png"
        type="image/png"
    />

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="css/comparative_original.css?v=<?= time(); ?>"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    >

</head>

<body>

<main class="main-content">

    <header class="top-bar">

        <h2>
            <a
                href="settings.php"
                style="font-size: 16px; text-decoration: none;"
            >
                ⬅ Back
            </a>
        </h2>

        <div class="user-badge">

            <span>
                <?php echo htmlspecialchars($username); ?>
                (<?php echo htmlspecialchars($user_type); ?>)
            </span>

            <div class="avatar">
                <?php echo strtoupper(substr($full_name, 0, 1)); ?>
            </div>

        </div>

    </header>

    <div class="content-wrapper">

        <div class="page-title">
            Consolidated Report with Adjustment
        </div>

        <!-- ====================================================
             ERROR BANNER
        ===================================================== -->
        <?php if ($show_error && !empty($error_message)): ?>

            <div class="error-banner">

                <i class="fa-solid fa-circle-exclamation"></i>

                <span>
                    <?php echo htmlspecialchars($error_message); ?>
                </span>

            </div>

        <?php endif; ?>

        <!-- ====================================================
             FILTER FORM
        ===================================================== -->
        <form
            method="GET"
            class="filter-form"
            id="filterForm"
        >

            <div class="filter-group">

                <label>Zone</label>

                <select
                    name="zone"
                    id="zoneSelect"
                >

                    <option value="">
                        Zones
                    </option>

                    <?php foreach ($distinct_zn as $zn_val): ?>

                        <option
                            value="<?= htmlspecialchars($zn_val) ?>"
                            <?= $zone === $zn_val ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($zn_val) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="filter-group">

                <label>
                    Transaction Year
                </label>

                <select
                    name="transaction_year"
                    id="yearSelect"
                >

                    <option value="">
                        Years
                    </option>

                    <?php foreach ($distinct_years as $yr): ?>

                        <option
                            value="<?= htmlspecialchars($yr) ?>"
                            <?= $transaction_year === $yr ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($yr) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="filter-group">

                <label>
                    Transaction Month
                </label>

                <input
                    type="month"
                    name="primary_period"
                    id="primaryPeriodSelect"
                    value="<?= htmlspecialchars($primary_period) ?>"
                >

            </div>

            <div class="filter-actions">

                <button
                    type="submit"
                    class="btn-filter"
                >
                    <i class="fa-solid fa-filter"></i>
                    Filter
                </button>

                <button
                    type="button"
                    class="btn-collapse"
                    id="collapseBtn"
                >
                    <i class="fa-solid fa-compress"></i>
                    Collapse
                </button>

                <a
                    href="export_consolidated_with_adjustment.php?<?= htmlspecialchars(http_build_query($_GET)) ?>"
                    class="btn-export"
                >
                    <i class="fa-solid fa-file-excel"></i>
                    Export Excel
                </a>

                <a
                    href="?reset=1"
                    class="btn-reset"
                >
                    <i class="fa-solid fa-rotate-left"></i>
                    Clear
                </a>

            </div>

        </form>

        <!-- ====================================================
             TABLES
        ===================================================== -->
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

                                    <!-- ====================================================
                                         TABLE HEADER
                                    ===================================================== -->
                                    <thead>

                                        <!-- Report title -->
                                        <tr>

                                            <th colspan="4">
                                                <?php
                                                echo !empty($zone)
                                                    ? 'Zone: ' . htmlspecialchars($zone)
                                                    : 'All Zones';
                                                ?>
                                            </th>

                                            <th colspan="<?= $has_regions ? ($num_regions + 1) : 1 ?>">
                                                CONSOLIDATED PROFIT & LOSS STATEMENT
                                            </th>

                                        </tr>

                                        <!-- Period -->
                                        <tr>

                                            <th colspan="4"></th>

                                            <?php

                                            $period_display =
                                                !empty($primary_period)
                                                ? strtoupper(
                                                    date(
                                                        'F Y',
                                                        strtotime($primary_period . '-01')
                                                    )
                                                )
                                                : '(Transaction Month)';

                                            ?>

                                            <th colspan="<?= $has_regions ? ($num_regions + 1) : 1 ?>">
                                                <?= $period_display ?>
                                            </th>

                                        </tr>

                                        <!-- Region names -->
                                        <tr>

                                            <th colspan="4"></th>

                                            <?php if ($has_regions): ?>

                                                <?php foreach ($regions_in_zone as $r): ?>

                                                    <th>
                                                        <?= htmlspecialchars($r) ?>
                                                    </th>

                                                <?php endforeach; ?>

                                                <th>
                                                    Total
                                                </th>

                                            <?php else: ?>

                                                <th>
                                                    Total
                                                </th>

                                            <?php endif; ?>

                                        </tr>

                                        <!-- Amount label -->
                                        <tr>

                                            <th colspan="4"></th>

                                            <?php if ($has_regions): ?>

                                                <?php foreach ($regions_in_zone as $r): ?>

                                                    <th>
                                                        Amount
                                                    </th>

                                                <?php endforeach; ?>

                                                <th>
                                                    Amount
                                                </th>

                                            <?php else: ?>

                                                <th>
                                                    Amount
                                                </th>

                                            <?php endif; ?>

                                        </tr>

                                    </thead>

                                    <!-- ====================================================
                                         TABLE BODY
                                    ===================================================== -->
                                    <tbody class="report-tbody">

                                        <!-- Initial spacer -->
                                        <tr class="initial-spacer">

                                            <td colspan="<?= $total_columns ?>"></td>

                                        </tr>

                                        <!-- Revenues header -->
                                        <tr class="revenues-header-row">

                                            <td
                                                style="
                                                    background-color: #ff7f29;
                                                    font-weight: bold;
                                                "
                                            >
                                                REVENUES
                                            </td>

                                            <td
                                                colspan="<?= $total_columns - 1 ?>"
                                                style="
                                                    background-color: #ff7f29;
                                                    font-weight: bold;
                                                "
                                            ></td>

                                        </tr>

                                        <?php if (empty($table_rows)): ?>

                                            <tr>

                                                <td
                                                    colspan="<?= $total_columns ?>"
                                                    style="text-align: center;"
                                                >
                                                    No data structure available
                                                </td>

                                            </tr>

                                        <?php else: ?>

                                            <?php foreach ($table_rows as $row): ?>

                                                <!-- ====================================================
                                                     MANUAL SPACER
                                                ===================================================== -->
                                                <?php if (
                                                    isset($row['is_manual_spacer'])
                                                    && $row['is_manual_spacer']
                                                ): ?>

                                                    <tr
                                                        class="spacer-row"
                                                        style="height: 20px;"
                                                    >

                                                        <td colspan="<?= $total_columns ?>"></td>

                                                    </tr>

                                                    <?php continue; ?>

                                                <?php endif; ?>

                                                <?php

                                                $is_summary_row =
                                                    isset($row['is_summary_row'])
                                                    && $row['is_summary_row'] === true;

                                                $is_header =
                                                    !empty($row['is_section_header']);

                                                $primary_total =
                                                    $row['primary_total']
                                                    ?? [
                                                        'mlfsi' => 0,
                                                        'jewelers' => 0,
                                                        'total' => 0
                                                    ];

                                                // ====================================================
                                                // INTERNAL TOTAL
                                                //
                                                // MLFSi + Jewelers
                                                // ====================================================
                                                $primary_amount =
                                                    $primary_total['mlfsi']
                                                    + $primary_total['jewelers'];

                                                if (
                                                    !$is_summary_row
                                                    && !empty($row['is_inj2'])
                                                ) {

                                                    $primary_total['mlfsi'] =
                                                        -$primary_total['mlfsi'];

                                                    $primary_total['jewelers'] =
                                                        -$primary_total['jewelers'];

                                                    $primary_total['total'] =
                                                        -$primary_total['total'];

                                                    $primary_amount =
                                                        -$primary_amount;
                                                }

                                                ?>

                                                <!-- ====================================================
                                                     REPORT ROW
                                                ===================================================== -->
                                                <tr
                                                    class="<?= $is_summary_row
                                                        ? 'summary-row'
                                                        : 'data-row' ?>"
                                                    data-sort-order="<?= htmlspecialchars(
                                                        $row['sort_order'] ?? ''
                                                    ) ?>"
                                                    <?php if (!$is_summary_row): ?>
                                                        data-is-detail="true"
                                                    <?php endif; ?>
                                                >

                                                    <!-- Sort order -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell'
                                                            : '' ?>"
                                                    >
                                                        <?=
                                                            $is_summary_row
                                                            ? htmlspecialchars($row['sort_order'])
                                                            : ''
                                                        ?>
                                                    </td>

                                                    <!-- Description -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell'
                                                            : '' ?>"
                                                    >

                                                        <?php if ($is_header): ?>

                                                            <strong>
                                                                <?= htmlspecialchars(
                                                                    $row['sub_order']
                                                                ) ?>
                                                            </strong>

                                                        <?php elseif ($is_summary_row): ?>

                                                            <strong>
                                                                <?= htmlspecialchars(
                                                                    $row['gl_description']
                                                                ) ?>
                                                            </strong>

                                                        <?php endif; ?>

                                                    </td>

                                                    <!-- GL description -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell summary-description'
                                                            : '' ?>"
                                                    >

                                                        <?=
                                                            !$is_summary_row
                                                            ? htmlspecialchars(
                                                                $row['gl_description']
                                                            )
                                                            : ''
                                                        ?>

                                                    </td>

                                                    <!-- Blank -->
                                                    <td
                                                        class="<?= $is_summary_row
                                                            ? 'summary-cell'
                                                            : '' ?>"
                                                    ></td>

                                                    <!-- ====================================================
                                                         ONE AMOUNT COLUMN PER REGION
                                                         Amount = MLFSi + Jewelers
                                                    ===================================================== -->
                                                    <?php if ($has_regions): ?>

                                                        <?php foreach ($regions_in_zone as $rn): ?>

                                                            <?php

                                                            $r_amt =
                                                                $row['region_totals'][$rn]
                                                                ?? [
                                                                    'mlfsi' => 0,
                                                                    'jewelers' => 0,
                                                                    'total' => 0
                                                                ];

                                                            $region_amount =
                                                                $r_amt['mlfsi']
                                                                + $r_amt['jewelers'];

                                                            if (
                                                                !$is_summary_row
                                                                && !empty($row['is_inj2'])
                                                            ) {
                                                                $region_amount =
                                                                    -$region_amount;
                                                            }

                                                            ?>

                                                            <td
                                                                class="
                                                                    numeric-cell
                                                                    <?= $is_summary_row
                                                                        ? 'summary-cell'
                                                                        : '' ?>
                                                                "
                                                                style="
                                                                    text-align: right;
                                                                    <?= $region_amount < 0
                                                                        ? 'color: red;'
                                                                        : '' ?>
                                                                "
                                                            >

                                                                <?php if (!$is_header): ?>

                                                                    <?php if ($is_summary_row): ?>

                                                                        <strong>
                                                                            <?= number_format(
                                                                                $region_amount,
                                                                                2
                                                                            ) ?>
                                                                        </strong>

                                                                    <?php else: ?>

                                                                        <?= number_format(
                                                                            $region_amount,
                                                                            2
                                                                        ) ?>

                                                                    <?php endif; ?>

                                                                <?php endif; ?>

                                                            </td>

                                                        <?php endforeach; ?>

                                                    <?php endif; ?>

                                                    <!-- ====================================================
                                                         FINAL TOTAL COLUMN
                                                    ===================================================== -->
                                                    <td
                                                        class="
                                                            numeric-cell
                                                            <?= $is_summary_row
                                                                ? 'summary-cell'
                                                                : '' ?>
                                                        "
                                                        style="
                                                            text-align: right;
                                                            <?= $primary_amount < 0
                                                                ? 'color: red;'
                                                                : '' ?>
                                                        "
                                                    >

                                                        <?php if (!$is_header): ?>

                                                            <?php if ($is_summary_row): ?>

                                                                <strong>
                                                                    <?= number_format(
                                                                        $primary_amount,
                                                                        2
                                                                    ) ?>
                                                                </strong>

                                                            <?php else: ?>

                                                                <?= number_format(
                                                                    $primary_amount,
                                                                    2
                                                                ) ?>

                                                            <?php endif; ?>

                                                        <?php endif; ?>

                                                    </td>

                                                </tr>

                                                <!-- ====================================================
                                                     SPACER AFTER SUMMARY
                                                ===================================================== -->
                                                <?php if (
                                                    $is_summary_row
                                                    && !$is_header
                                                    && empty($row['skip_spacer'])
                                                ): ?>

                                                    <tr
                                                        class="spacer-row"
                                                        data-spacer-for="<?= htmlspecialchars(
                                                            $row['sort_order'] ?? ''
                                                        ) ?>"
                                                        style="height: 20px;"
                                                    >

                                                        <td colspan="<?= $total_columns ?>"></td>

                                                    </tr>

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

        <!-- ====================================================
             JAVASCRIPT
        ===================================================== -->
        <script>

            function compareMonths(month1, month2) {

                if (!month1 || !month2) {
                    return 0;
                }

                return new Date(month1 + '-01')
                    - new Date(month2 + '-01');
            }

            let activeModal = null;

            function showModal(message) {

                if (activeModal) {
                    activeModal.remove();
                }

                const modalOverlay =
                    document.createElement('div');

                modalOverlay.className =
                    'modal-overlay';

                modalOverlay.innerHTML = `
                    <div class="modal-container">

                        <div class="modal-header">
                            <h3>
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                Validation Error
                            </h3>
                        </div>

                        <div class="modal-body">
                            <p>${escapeHtml(message)}</p>
                        </div>

                        <div class="modal-footer">
                            <button onclick="closeModal()">
                                OK
                            </button>
                        </div>

                    </div>
                `;

                document.body.appendChild(
                    modalOverlay
                );

                activeModal = modalOverlay;
            }

            window.closeModal = function() {

                if (activeModal) {

                    activeModal.remove();

                    activeModal = null;
                }
            };

            function escapeHtml(text) {

                const div =
                    document.createElement('div');

                div.textContent = text;

                return div.innerHTML;
            }

            function validateForm() {
                return true;
            }

            // ====================================================
            // COLLAPSE / UNCOLLAPSE
            // ====================================================
            document.addEventListener(
                'DOMContentLoaded',
                function() {

                    const collapseBtn =
                        document.getElementById(
                            'collapseBtn'
                        );

                    let isCollapsed = false;

                    if (collapseBtn) {

                        collapseBtn.addEventListener(
                            'click',
                            function() {

                                isCollapsed =
                                    !isCollapsed;

                                const tbodies =
                                    document.querySelectorAll(
                                        '.report-tbody'
                                    );

                                tbodies.forEach(
                                    tbody => {

                                        const rows =
                                            Array.from(
                                                tbody.rows
                                            );

                                        rows.forEach(
                                            row => {

                                                const sortOrder =
                                                    row.getAttribute(
                                                        'data-sort-order'
                                                    );

                                                const isDetail =
                                                    row.getAttribute(
                                                        'data-is-detail'
                                                    ) === 'true';

                                                const spacerFor =
                                                    row.getAttribute(
                                                        'data-spacer-for'
                                                    );

                                                const sortNum =
                                                    parseInt(
                                                        sortOrder
                                                    );

                                                const is1To20 =
                                                    !isNaN(sortNum)
                                                    &&
                                                    sortNum >= 1
                                                    &&
                                                    sortNum <= 20;

                                                // --------------------------------------------
                                                // Hide detail rows
                                                // --------------------------------------------
                                                if (
                                                    is1To20
                                                    && isDetail
                                                ) {

                                                    row.style.display =
                                                        isCollapsed
                                                        ? 'none'
                                                        : '';
                                                }

                                                // --------------------------------------------
                                                // Hide spacers
                                                // --------------------------------------------
                                                if (spacerFor) {

                                                    const spacerNum =
                                                        parseInt(
                                                            spacerFor
                                                        );

                                                    if (
                                                        !isNaN(spacerNum)
                                                        &&
                                                        spacerNum >= 1
                                                        &&
                                                        spacerNum <= 20
                                                    ) {

                                                        row.style.display =
                                                            isCollapsed
                                                            ? 'none'
                                                            : '';
                                                    }
                                                }

                                                // --------------------------------------------
                                                // Hide revenues header
                                                // --------------------------------------------
                                                if (
                                                    row.classList.contains(
                                                        'revenues-header-row'
                                                    )
                                                ) {

                                                    row.style.display =
                                                        isCollapsed
                                                        ? 'none'
                                                        : '';
                                                }

                                                // --------------------------------------------
                                                // Hide initial spacer
                                                // --------------------------------------------
                                                if (
                                                    row.classList.contains(
                                                        'initial-spacer'
                                                    )
                                                ) {

                                                    row.style.display =
                                                        isCollapsed
                                                        ? 'none'
                                                        : '';
                                                }

                                            }
                                        );
                                    }
                                );

                                collapseBtn.innerHTML =
                                    isCollapsed
                                    ? '<i class="fa-solid fa-expand"></i> Uncollapse'
                                    : '<i class="fa-solid fa-compress"></i> Collapse';

                                collapseBtn.style.backgroundColor =
                                    isCollapsed
                                    ? '#1f2937'
                                    : '#4b5563';

                            }
                        );
                    }

                }
            );

        </script>

    </div>

</main>

<?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
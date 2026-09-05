<?php
// export_comparative_page_four.php
// Fixed: three tables written SIDE-BY-SIDE with aligned rows
// Updated: Manual column widths (no borders)
// Updated: Logo for each table positioned using column anchors
// Updated: Spacer rows after every sort_order
// Updated: Sort_order summary rows colored FDE9D9 (even sort_order only)
// Updated: Specific summary rows with custom background colors
// Updated: COS-4 = COS-1+2+3; section total = COS-4 through COS-9 (no double-count)
// Updated: Hide sort_order numbers in first column, keep labels for summary rows
// Updated: Added Excel grouping/collapse functionality - ONLY for revenue section
// Updated: Two views only - grouped (collapsed) and normal (expanded)
// Updated: Alternating row colors for sort_order summaries (even = color, odd = no color)
// Updated: GL ID mapping with source-to-target for manual adjustments
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

// Filters
$mainzone = $_GET['mainzone'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$selected_period = $_GET['selected_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

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

$show_error = false;
$valid_filters = false;

if (!empty($selected_period)) {
    if ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($selected_period)) {
            $show_error = true;
        }
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($selected_period)) {
            $show_error = true;
        }
    }
    
    if (!$show_error) {
        $valid_filters = true;
    }
}

// ============================================================
// HARDCODED GL ID MAPPINGS (same as HO comparative report)
// SOURCE gl_id in manual_adjustment → TARGET gl_id on the report
// No mixed mode — only old or new depending on gl_code_mode
// ============================================================

$old_gl_mapping = [
    // COS (Cost of Sales)
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',

    // MLE
    'MLE-2' => 'MLE-3',

    // TAE
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,

    // TOI
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'MLE-2',
    'TOI-22' => 'INJ-5',
    'TOI-23' => 'INJ-4',
    'TOI-24' => null,

    // VEH
    'VEH-5' => 'VEH-6',
    'VEH-6' => 'VEH-7',
    'VEH-7' => 'VEH-8',
    'VEH-8' => 'VEH-9',
    'VEH-9' => 'VEH-10',
];

$new_gl_mapping = [
    // COS
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',

    // TAE
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,

    // TOI
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'COS-8',
    'TOI-22' => null,
    'TOI-23' => null,
    'TOI-24' => null,

    // VEH
    'VEH-5' => 'VEH-7',
    'VEH-6' => 'VEH-8',
    'VEH-7' => 'VEH-9',
    'VEH-8' => 'VEH-10',
    'VEH-9' => 'VEH-11',

    // INS — TARGET => list of SOURCE gl_ids
    'INS-1'  => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
    'INS-2'  => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
    'INS-3'  => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
    'INS-4'  => ['INS-27', 'INS-46'],
    'INS-5'  => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
    'INS-6'  => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
    'INS-7'  => ['INS-15', 'INS-16', 'INS-17', 'INS-18', 'INS-19'],
    'INS-8'  => ['INS-38'],
    'INS-9'  => ['INS-48'],
    'INS-10' => ['INS-49'],
    'INS-11' => [],
    'INS-12' => [],
];

// ============================================================
// GET GL MAPPING based on GL Code Mode
// ============================================================
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];   // sort_order|sub_order => gl_id

$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes_ho' : 'fs_reports.new_gl_codes_ho';

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

        $gl_id_by_key[$key] = $gl_id;

        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        // Keep numerical gl_code for any residual lookups (rarely used now)
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
 * - NATIONWIDE / empty => sum of LNCR + VISMIN only
 * - Specific mainzone  => that mainzone only
 */
function get_head_office_manual_adjustment(mysqli $conn, string $mainzone, string $selected_period): float {
    if (empty($selected_period)) {
        return 0.0;
    }

    $transaction_month = $selected_period . '-01';

    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
        // NATIONWIDE = LNCR + VISMIN only
        $sql = "
            SELECT SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total
            FROM fs_reports.manual_adjustment
            WHERE transaction_month = ?
              AND sort_order = 26
              AND sub_order = 1
              AND UPPER(TRIM(mainzone)) IN ('LNCR', 'VISMIN')
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0.0;
        mysqli_stmt_bind_param($stmt, "s", $transaction_month);
    } else {
        // Specific mainzone (case-insensitive, trimmed match)
        $sql = "
            SELECT SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total
            FROM fs_reports.manual_adjustment
            WHERE transaction_month = ?
              AND sort_order = 26
              AND sub_order = 1
              AND LOWER(TRIM(mainzone)) = LOWER(?)
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

/**
 * Fetch period data from manual_adjustment, applying SOURCE→TARGET gl_id mapping
 * (same logic as HO comparative report, but keeps mlfsi / jewelers separate).
 *
 * Returns [ target_gl_id => ['mlfsi' => float, 'jewelers' => float, 'total' => float] ]
 */
function get_manual_adjustment_period_data(
    mysqli $conn,
    string $mainzone,
    string $selected_period,
    array $gl_id_by_key,
    string $gl_code_mode = 'old',
    array $old_gl_mapping = [],
    array $new_gl_mapping = []
): array {
    $data = [];

    if (empty($selected_period)) {
        return $data;
    }

    $parts = explode('-', $selected_period);
    $year_val = $parts[0];
    $month_val = $selected_period . '-01';

    // --------------------------------------------------------
    // Build list of gl_ids to query (structure IDs + mapping sources)
    // --------------------------------------------------------
    $gl_ids_to_query = array_values(array_unique(array_filter(array_values($gl_id_by_key))));

    if ($gl_code_mode === 'old') {
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }
    } else {
        // new mode
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') {
                        $gl_ids_to_query[] = $src_id;
                    }
                }
            } elseif ($key !== '') {
                // Scalar: SOURCE => TARGET — query the SOURCE
                $gl_ids_to_query[] = $key;
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, function ($id) {
        return $id !== null && $id !== '';
    })));

    if (empty($gl_ids_to_query)) {
        return $data;
    }

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));

    // Params order matches SQL: year, month, gl_ids..., [mainzone]
    $params = [$year_val, $month_val];
    $types  = 'ss';
    $params = array_merge($params, $gl_ids_to_query);
    $types .= str_repeat('s', count($gl_ids_to_query));

    $where_mainzone = '';
    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
        $where_mainzone = " AND UPPER(TRIM(mainzone)) IN ('LNCR', 'VISMIN')";
    } else {
        $where_mainzone = ' AND LOWER(TRIM(mainzone)) = LOWER(?)';
        $params[] = $mainzone;
        $types .= 's';
    }

    $sql = "
        SELECT
            gl_id,
            SUM(COALESCE(mlfsi, 0)) AS mlfsi_amount,
            SUM(COALESCE(jewelers, 0)) AS jewelers_amount
        FROM fs_reports.manual_adjustment
        WHERE transaction_year = ?
          AND transaction_month = ?
          AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL
          AND gl_id != ''
          {$where_mainzone}
        GROUP BY gl_id
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $data;
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $raw = []; // gl_id => [mlfsi, jewelers]
    while ($row = mysqli_fetch_assoc($result)) {
        $raw[$row['gl_id']] = [
            'mlfsi'    => floatval($row['mlfsi_amount']),
            'jewelers' => floatval($row['jewelers_amount']),
        ];
    }
    mysqli_stmt_close($stmt);

    // --------------------------------------------------------
    // Map raw SOURCE amounts onto TARGET report gl_ids
    // --------------------------------------------------------
    $add_to = function (string $target, float $mlfsi, float $jewelers) use (&$data) {
        if ($target === '') {
            return;
        }
        if (!isset($data[$target])) {
            $data[$target] = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        }
        $data[$target]['mlfsi']    += $mlfsi;
        $data[$target]['jewelers'] += $jewelers;
        $data[$target]['total']     = $data[$target]['mlfsi'] + $data[$target]['jewelers'];
    };

    if ($gl_code_mode === 'old') {
        // SOURCE => TARGET (or direct match)
        foreach ($raw as $src_id => $amt) {
            if (array_key_exists($src_id, $old_gl_mapping)) {
                $target = $old_gl_mapping[$src_id];
                if ($target !== null && $target !== '') {
                    $add_to($target, $amt['mlfsi'], $amt['jewelers']);
                }
                // null target = intentionally dropped
            } else {
                $add_to($src_id, $amt['mlfsi'], $amt['jewelers']);
            }
        }
    } else {
        // NEW mode
        // 1) Array mappings: TARGET => [SOURCES]
        // 2) Scalar mappings: SOURCE => TARGET
        // 3) Direct match for everything else (not a scalar source)

        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target = $key;
                $direct_mlfsi = isset($raw[$target]) ? $raw[$target]['mlfsi'] : null;
                $direct_jew   = isset($raw[$target]) ? $raw[$target]['jewelers'] : null;

                $sum_mlfsi = 0.0;
                $sum_jew   = 0.0;
                foreach ($mapping as $src_id) {
                    if ($src_id !== '' && isset($raw[$src_id])) {
                        $sum_mlfsi += $raw[$src_id]['mlfsi'];
                        $sum_jew   += $raw[$src_id]['jewelers'];
                    }
                }

                // Prefer non-zero direct TARGET amount (post-April new-ID rows)
                if ($direct_mlfsi !== null && ($direct_mlfsi != 0.0 || $direct_jew != 0.0)) {
                    $add_to($target, (float)$direct_mlfsi, (float)$direct_jew);
                } elseif ($sum_mlfsi != 0.0 || $sum_jew != 0.0) {
                    $add_to($target, $sum_mlfsi, $sum_jew);
                } elseif ($direct_mlfsi !== null) {
                    $add_to($target, (float)$direct_mlfsi, (float)$direct_jew);
                }
            } else {
                // Scalar SOURCE => TARGET
                if ($key !== '') {
                    $scalar_source_ids[$key] = true;
                }
                $target = $mapping;
                if (
                    $key !== '' &&
                    $target !== null &&
                    $target !== '' &&
                    isset($raw[$key])
                ) {
                    $add_to($target, $raw[$key]['mlfsi'], $raw[$key]['jewelers']);
                }
            }
        }

        // Direct-match remaining structure IDs that were not scalar sources
        foreach ($gl_id_by_key as $gid) {
            if (
                $gid !== '' &&
                !isset($data[$gid]) &&
                isset($raw[$gid]) &&
                !isset($scalar_source_ids[$gid])
            ) {
                $add_to($gid, $raw[$gid]['mlfsi'], $raw[$gid]['jewelers']);
            }
        }
    }

    return $data;
}

function compute_table_rows_for_mainzone(
    mysqli $conn,
    string $mainzone,
    string $transaction_year,
    string $selected_period,
    string $gl_code_mode,
    array $gl_mapping,
    array $gl_descriptions,
    array $special_keys,
    array $sort_order_descriptions,
    array $gl_id_by_key,
    array $old_gl_mapping = [],
    array $new_gl_mapping = [],
    bool $use_real_data = true
): array {
    $period_data = [];
    if ($use_real_data && !empty($selected_period)) {
        $period_data = get_manual_adjustment_period_data(
            $conn,
            $mainzone,
            $selected_period,
            $gl_id_by_key,
            $gl_code_mode,
            $old_gl_mapping,
            $new_gl_mapping
        );
    }

    // Fetch Head Office value for sort_order=28/sub_order=1 (old GL) or sort_order=29/sub_order=1 (new GL)
    $head_office_tax = 0.0;
    if ($use_real_data && !empty($selected_period)) {
        $head_office_tax = get_head_office_manual_adjustment($conn, $mainzone, $selected_period);
    }

    $table_rows = [];

    foreach ($gl_mapping as $key => $codes) {
        [$sort_order, $sub_order] = explode('|', $key);

        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys);
        $current_gl_id = $gl_id_by_key[$key] ?? '';

        $period_mlfsi = 0.0;
        $period_jewelers = 0.0;

        // Primary lookup by gl_id (correct source for manual_adjustment)
        if ($current_gl_id !== '' && isset($period_data[$current_gl_id])) {
            $period_mlfsi = $period_data[$current_gl_id]['mlfsi'];
            $period_jewelers = $period_data[$current_gl_id]['jewelers'];
        }

        // Income Tax (old: sort 28/1, new: sort 29/1): put the FULL amount in HEAD OFFICE only.
        // Do not break it down into MLFSI / JEWELERS.
        $head_office = 0.0;
        $is_tax_head_office_row = (
            ((string)$sort_order === '28' && (string)$sub_order === '1' && $gl_code_mode === 'old') ||
            ((string)$sort_order === '29' && (string)$sub_order === '1' && $gl_code_mode === 'new')
        );
        if ($is_tax_head_office_row) {
            // Prefer the dedicated HO adjustment (sort_order 26 / sub_order 1);
            // if that is zero, fall back to the row's own mlfsi+jewelers total.
            $tax_total = $head_office_tax;
            if ($tax_total == 0.0) {
                $tax_total = $period_mlfsi + $period_jewelers;
            }
            $period_mlfsi    = 0.0;
            $period_jewelers = 0.0;
            $head_office     = $tax_total;
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

    // ============================================================
    // Special Cost of Sales calculation (old: sort_order 23, new: 24)
    // COS-4 (sub_order 4) = COS-1 + COS-2 + COS-3
    // Section total = COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
    // (i.e. do not double-count COS-1/2/3 in the total)
    // ============================================================
    $cost_of_sales_sort_order_early = ($gl_code_mode === 'old') ? 23 : 24;
    if (isset($grouped_rows[$cost_of_sales_sort_order_early])) {
        $cos_rows = &$grouped_rows[$cost_of_sales_sort_order_early];

        // Index rows by sub_order for easy lookup
        $by_sub = [];
        foreach ($cos_rows as $idx => $r) {
            $by_sub[(string)$r['sub_order']] = $idx;
        }

        // Sum COS-1 + COS-2 + COS-3 (sub_orders 1,2,3)
        $sum1_3_mlfsi = 0.0;
        $sum1_3_jew   = 0.0;
        $sum1_3_head  = 0.0;
        foreach (['1', '2', '3'] as $so) {
            if (isset($by_sub[$so])) {
                $r = $cos_rows[$by_sub[$so]];
                $sum1_3_mlfsi += (float)($r['period_mlfsi'] ?? 0);
                $sum1_3_jew   += (float)($r['period_jewelers'] ?? 0);
                $sum1_3_head  += (float)($r['head_office'] ?? 0);
            }
        }
        $sum1_3_total = $sum1_3_mlfsi + $sum1_3_jew + $sum1_3_head;

        // Override COS-4 (sub_order 4) with the sum of 1+2+3
        if (isset($by_sub['4'])) {
            $idx4 = $by_sub['4'];
            $cos_rows[$idx4]['period_mlfsi']    = $sum1_3_mlfsi;
            $cos_rows[$idx4]['period_jewelers'] = $sum1_3_jew;
            $cos_rows[$idx4]['head_office']    = $sum1_3_head;
            $cos_rows[$idx4]['period_total']   = $sum1_3_total;
        }

        unset($cos_rows); // break reference
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
    
    // Variables for expenses after TOTAL REVENUES
    $personnel_mlfsi = 0; $personnel_jew = 0; $personnel_tot = 0; $personnel_head = 0;
    $admin_mlfsi = 0; $admin_jew = 0; $admin_tot = 0; $admin_head = 0;
    $dep_mlfsi = 0; $dep_jew = 0; $dep_tot = 0; $dep_head = 0;
    $interest_mlfsi = 0; $interest_jew = 0; $interest_tot = 0; $interest_head = 0;
    $tax_mlfsi = 0; $tax_jew = 0; $tax_tot = 0; $tax_head = 0;

    // Determine the max sort_order for revenue based on GL code mode
    // Old GL: 1-22, New GL: 1-23
    $revenue_max_sort_order = ($gl_code_mode === 'old') ? 22 : 23;
    
    // Determine the sort_order for Cost of Sales based on GL code mode
    // Old GL: 23, New GL: 24
    $cost_of_sales_sort_order = ($gl_code_mode === 'old') ? 23 : 24;
    
    // Determine the sort_order for Personnel Expenses based on GL code mode
    // Old GL: 24, New GL: 25
    $personnel_sort_order = ($gl_code_mode === 'old') ? 24 : 25;
    
    // Determine the sort_order for Administrative Expenses based on GL code mode
    // Old GL: 25, New GL: 26
    $admin_sort_order = ($gl_code_mode === 'old') ? 25 : 26;

    // First pass: collect all detail rows and calculate totals
    foreach ($grouped_rows as $sort_order => $rows) {
        $total_period_mlfsi = array_sum(array_column($rows, 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($rows, 'period_jewelers'));
        $total_period_total = array_sum(array_column($rows, 'period_total'));
        $total_head_office = array_sum(array_column($rows, 'head_office'));
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;

        // Revenue detail rows (1 to revenue_max_sort_order)
        if ((int)$sort_order >= 1 && (int)$sort_order <= $revenue_max_sort_order) {
            $rev_mlfsi += $total_period_mlfsi;
            $rev_jew += $total_period_jewelers;
            $rev_tot += $group_total_with_head;
            $rev_head += $total_head_office;
        }
        
        // Cost of Sales — total = COS-4 + COS-5 + ... + COS-9 only
        // (COS-4 already holds the sum of COS-1+2+3, so exclude 1/2/3 to avoid double-counting)
        if ((int)$sort_order == $cost_of_sales_sort_order) {
            $cos_mlfsi = 0.0;
            $cos_jew   = 0.0;
            $cos_head  = 0.0;
            foreach ($rows as $r) {
                $sub = (string)($r['sub_order'] ?? '');
                if ($sub === '1' || $sub === '2' || $sub === '3') {
                    continue; // skip the detail lines that are already rolled into COS-4
                }
                $cos_mlfsi += (float)($r['period_mlfsi'] ?? 0);
                $cos_jew   += (float)($r['period_jewelers'] ?? 0);
                $cos_head  += (float)($r['head_office'] ?? 0);
            }
            $cos_tot = $cos_mlfsi + $cos_jew + $cos_head;
        }
        
        // Other Income (sort_order 22 for old, 23 for new)
        if ($gl_code_mode === 'old') {
            if ((int)$sort_order == 22) {
                $other_income_mlfsi = $total_period_mlfsi;
                $other_income_jew = $total_period_jewelers;
                $other_income_tot = $group_total_with_head;
                $other_income_head = $total_head_office;
            }
        } else {
            if ((int)$sort_order == 22) {
                $other_income_mlfsi = $total_period_mlfsi;
                $other_income_jew = $total_period_jewelers;
                $other_income_tot = $group_total_with_head;
                $other_income_head = $total_head_office;
            }
        }
        
        // Personnel Expenses
        if ((int)$sort_order == $personnel_sort_order) {
            $personnel_mlfsi = $total_period_mlfsi;
            $personnel_jew = $total_period_jewelers;
            $personnel_tot = $group_total_with_head;
            $personnel_head = $total_head_office;
            // Add to S&A total
            $sa_mlfsi += $total_period_mlfsi;
            $sa_jew += $total_period_jewelers;
            $sa_tot += $group_total_with_head;
            $sa_head += $total_head_office;
        }
        
        // Administrative Expenses
        if ((int)$sort_order == $admin_sort_order) {
            $admin_mlfsi = $total_period_mlfsi;
            $admin_jew = $total_period_jewelers;
            $admin_tot = $group_total_with_head;
            $admin_head = $total_head_office;
            // Add to S&A total
            $sa_mlfsi += $total_period_mlfsi;
            $sa_jew += $total_period_jewelers;
            $sa_tot += $group_total_with_head;
            $sa_head += $total_head_office;
        }
        
        // Depreciation (sort_order 26 for old, 27 for new)
        $dep_sort_order = ($gl_code_mode === 'old') ? 26 : 27;
        if ((int)$sort_order == $dep_sort_order) {
            $dep_mlfsi = $total_period_mlfsi;
            $dep_jew = $total_period_jewelers;
            $dep_tot = $group_total_with_head;
            $dep_head = $total_head_office;
        }
        
        // Interest Expense (sort_order 27 for old, 28 for new)
        $interest_sort_order = ($gl_code_mode === 'old') ? 27 : 28;
        if ((int)$sort_order == $interest_sort_order) {
            $interest_mlfsi = $total_period_mlfsi;
            $interest_jew = $total_period_jewelers;
            $interest_tot = $group_total_with_head;
            $interest_head = $total_head_office;
        }
        
        // Income Tax (sort_order 28 for old, 29 for new)
        $tax_sort_order = ($gl_code_mode === 'old') ? 28 : 29;
        if ((int)$sort_order == $tax_sort_order) {
            $tax_mlfsi = $total_period_mlfsi;
            $tax_jew = $total_period_jewelers;
            $tax_tot = $group_total_with_head;
            $tax_head = $total_head_office;
        }
    }

    // GROSS PROFIT = Revenues - Cost of Sales
    $gp_mlfsi = $rev_mlfsi - $cos_mlfsi;
    $gp_jew = $rev_jew - $cos_jew;
    $gp_tot = $rev_tot - $cos_tot;
    $gp_head = $rev_head - $cos_head;

    // EBITDA = Gross Profit - Selling & Admin + Other Income
    $ebitda_mlfsi = $gp_mlfsi - $sa_mlfsi;
    $ebitda_jew = $gp_jew - $sa_jew;
    $ebitda_tot = $gp_tot - $sa_tot;
    $ebitda_head = $gp_head - $sa_head;

    // EBIT = EBITDA - Depreciation
    $ebit_mlfsi = $ebitda_mlfsi - $dep_mlfsi;
    $ebit_jew = $ebitda_jew - $dep_jew;
    $ebit_tot = $ebitda_tot - $dep_tot;
    $ebit_head = $ebitda_head - $dep_head;

    // EBT = EBIT - Interest
    $ebt_mlfsi = $ebit_mlfsi - $interest_mlfsi;
    $ebt_jew = $ebit_jew - $interest_jew;
    $ebt_tot = $ebit_tot - $interest_tot;
    $ebt_head = $ebit_head - $interest_head;

    // NET INCOME = EBT - Tax
    $net_mlfsi = $ebt_mlfsi - $tax_mlfsi;
    $net_jew = $ebt_jew - $tax_jew;
    $net_tot = $ebt_tot - $tax_tot;
    $net_head = $ebt_head - $tax_head;

    // ============================================================
    // BUILD FINAL TABLE IN CORRECT ORDER - FIXED
    // ============================================================
    
    // STEP 1: Revenue detail rows (sort_order 1 to revenue_max_sort_order)
    foreach ($grouped_rows as $sort_order => $rows) {
        if ((int)$sort_order >= 1 && (int)$sort_order <= $revenue_max_sort_order) {
            // Check if this sort order should hide detail rows
            $hide_details = false;
            if ($gl_code_mode === 'old' && ((int)$sort_order == 10 || (int)$sort_order == 13)) {
                $hide_details = true;
            } elseif ($gl_code_mode === 'new' && ((int)$sort_order == 11 || (int)$sort_order == 14)) {
                $hide_details = true;
            }
            
            // Only add detail rows if not hidden
            if (!$hide_details) {
                foreach ($rows as $row) {
                    $final_table_rows[] = $row;
                }
            }
            
            // Add summary row for this sort order (always show)
            $total_period_mlfsi = array_sum(array_column($rows, 'period_mlfsi'));
            $total_period_jewelers = array_sum(array_column($rows, 'period_jewelers'));
            $total_period_total = array_sum(array_column($rows, 'period_total'));
            $total_head_office = array_sum(array_column($rows, 'head_office'));
            $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
            
            $description = isset($sort_order_descriptions[$sort_order]) 
                ? $sort_order_descriptions[$sort_order] 
                : "Total for Sort Order " . $sort_order;
            
            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'is_sort_order_summary' => true,
                'period_mlfsi' => $total_period_mlfsi,
                'period_jewelers' => $total_period_jewelers,
                'period_total' => $group_total_with_head,
                'head_office' => $total_head_office
            ];
            
            // Add spacer after each revenue sort order (will be hidden when grouped)
            $final_table_rows[] = ['is_spacer' => true];
        }
    }

    // STEP 2: "TOTAL REVENUES"
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

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 3: "Cost of Sales/Service" header
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

    // STEP 4: Cost of Sales detail rows with total
    // Total = COS-4 + COS-5 + ... + COS-9 (COS-4 already = COS-1+2+3)
    if (isset($grouped_rows[$cost_of_sales_sort_order])) {
        foreach ($grouped_rows[$cost_of_sales_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
        // Add total row for Cost of Sales — exclude sub_orders 1,2,3 to avoid double-counting
        $total_period_mlfsi = 0.0;
        $total_period_jewelers = 0.0;
        $total_head_office = 0.0;
        foreach ($grouped_rows[$cost_of_sales_sort_order] as $r) {
            $sub = (string)($r['sub_order'] ?? '');
            if ($sub === '1' || $sub === '2' || $sub === '3') {
                continue;
            }
            $total_period_mlfsi += (float)($r['period_mlfsi'] ?? 0);
            $total_period_jewelers += (float)($r['period_jewelers'] ?? 0);
            $total_head_office += (float)($r['head_office'] ?? 0);
        }
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
        
        $final_table_rows[] = [
            'sort_order' => $cost_of_sales_sort_order,
            'sub_order' => '',
            'gl_description' => 'Total Cost of Sales/Service',
            'is_section_header' => false,
            'is_summary_row' => true,
            'is_sort_order_summary' => true,
            'period_mlfsi' => $total_period_mlfsi,
            'period_jewelers' => $total_period_jewelers,
            'period_total' => $group_total_with_head,
            'head_office' => $total_head_office
        ];
    }

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 5: "GROSS PROFIT" = TOTAL REVENUES - Cost of Sales total
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

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 6: "SELLING & ADMIN EXPENSE" header
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

    // STEP 7: Personnel Expenses detail rows with total
    if (isset($grouped_rows[$personnel_sort_order])) {
        foreach ($grouped_rows[$personnel_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
        // Add total row for Personnel Expenses
        $total_period_mlfsi = array_sum(array_column($grouped_rows[$personnel_sort_order], 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($grouped_rows[$personnel_sort_order], 'period_jewelers'));
        $total_period_total = array_sum(array_column($grouped_rows[$personnel_sort_order], 'period_total'));
        $total_head_office = array_sum(array_column($grouped_rows[$personnel_sort_order], 'head_office'));
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
        
        $final_table_rows[] = [
            'sort_order' => $personnel_sort_order,
            'sub_order' => '',
            'gl_description' => 'Total Personnel Expenses',
            'is_section_header' => false,
            'is_summary_row' => true,
            'is_sort_order_summary' => true,
            'period_mlfsi' => $total_period_mlfsi,
            'period_jewelers' => $total_period_jewelers,
            'period_total' => $group_total_with_head,
            'head_office' => $total_head_office
        ];
    }

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 8: Administrative Expenses detail rows with total
    if (isset($grouped_rows[$admin_sort_order])) {
        foreach ($grouped_rows[$admin_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
        // Add total row for Administrative Expenses
        $total_period_mlfsi = array_sum(array_column($grouped_rows[$admin_sort_order], 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($grouped_rows[$admin_sort_order], 'period_jewelers'));
        $total_period_total = array_sum(array_column($grouped_rows[$admin_sort_order], 'period_total'));
        $total_head_office = array_sum(array_column($grouped_rows[$admin_sort_order], 'head_office'));
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
        
        $final_table_rows[] = [
            'sort_order' => $admin_sort_order,
            'sub_order' => '',
            'gl_description' => 'Total Administrative Expenses',
            'is_section_header' => false,
            'is_summary_row' => true,
            'is_sort_order_summary' => true,
            'period_mlfsi' => $total_period_mlfsi,
            'period_jewelers' => $total_period_jewelers,
            'period_total' => $group_total_with_head,
            'head_office' => $total_head_office
        ];
    }

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 9: "TOTAL SELLING AND ADMIN EXPENSES" = Personnel + Administrative
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

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 10: "EBITDA"
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

    // STEP 11: Depreciation detail rows (sort_order 26 for old, 27 for new)
    $dep_sort_order = ($gl_code_mode === 'old') ? 26 : 27;
    if (isset($grouped_rows[$dep_sort_order])) {
        foreach ($grouped_rows[$dep_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
    }

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 12: "EARNINGS BEFORE INTEREST & TAXES"
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

    // STEP 13: Interest Expense detail rows (sort_order 27 for old, 28 for new)
    $interest_sort_order = ($gl_code_mode === 'old') ? 27 : 28;
    if (isset($grouped_rows[$interest_sort_order])) {
        foreach ($grouped_rows[$interest_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
    }

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 14: "EARNINGS BEFORE TAXES"
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

    // STEP 15: Income Tax detail rows (sort_order 28 for old, 29 for new)
    $tax_sort_order = ($gl_code_mode === 'old') ? 28 : 29;
    if (isset($grouped_rows[$tax_sort_order])) {
        foreach ($grouped_rows[$tax_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
    }

    $final_table_rows[] = ['is_spacer' => true];

    // STEP 16: "TOTAL NET INCOME/LOSS"
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

    return $final_table_rows;
}

// ============================================================
// BUILD THREE TABLES
// ============================================================
$tables = [];

$all_mainzones_name = 'NATIONWIDE';

$tables[] = [
    'mainzone' => $all_mainzones_name,
    'rows' => compute_table_rows_for_mainzone(
        $conn,
        '',
        $transaction_year,
        $selected_period,
        $gl_code_mode,
        $gl_mapping,
        $gl_descriptions,
        $special_keys,
        $sort_order_descriptions,
        $gl_id_by_key,
        $old_gl_mapping,
        $new_gl_mapping,
        $valid_filters
    ),
];

$specific_mainzones = ['LNCR', 'VISMIN'];

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
            $gl_id_by_key,
            $old_gl_mapping,
            $new_gl_mapping,
            $valid_filters
        ),
    ];
}

// Pre-calculate TOTAL REVENUES for each table
$total_revenues_by_table = [];
foreach ($tables as $table_index => $table) {
    $total_revenues = 0;
    foreach ($table['rows'] as $row) {
        if (isset($row['is_summary_row']) && $row['is_summary_row'] === true && 
            isset($row['sort_order']) && $row['sort_order'] === 'TOTAL REVENUES') {
            $total_revenues = $row['period_total'] ?? 0;
            break;
        }
    }
    if ($total_revenues == 0) {
        foreach ($table['rows'] as $row) {
            if (!isset($row['is_summary_row']) || $row['is_summary_row'] !== true) {
                $total_revenues += abs($row['period_total'] ?? 0);
            }
        }
    }
    $total_revenues_by_table[$table_index] = $total_revenues;
}

// ============================================================
// CREATE EXCEL FILE - SIDE-BY-SIDE LAYOUT
// ============================================================
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Define column ranges for each table (13 columns each)
// Table 0 (NATIONWIDE): A-M
// Table 1 (LNCR)      : N-X
// Table 2 (VISMIN)    : Y-AI
$table_ranges = [
    0 => ['start_num' => 1,  'end_num' => 13],
    1 => ['start_num' => 14, 'end_num' => 24],
    2 => ['start_num' => 25, 'end_num' => 35],
];

// ============================================================
// COLUMN WIDTH SETUP - MANUAL WIDTHS
// ============================================================

// Set manual widths for all columns
$column_widths = [
    // Table 1: A-M
    'A' => 3, 
    'B' => 3, 
    'C' => 55,
    'D' => 3, 
    'E' => 3, 
    'F' => 3,       // hidden spacers
    'G' => 20, 
    'H' => 20, 
    'I' => 20,
    'J' => 3, 
    'K' => 16, 
    'L' => 10, 
    'M' => 3,

    // Table 2: N-X
    'N' => 3, 
    'O' => 3, 
    'P' => 55, 
    'Q' => 3,
    'R' => 20, 
    'S' => 20, 
    'T' => 20, 
    'U' => 3,
    'V' => 16, 
    'W' => 10, 
    'X' => 3,

    // Table 3: Y-AI
    'Y' => 3, 
    'Z' => 3, 
    'AA' => 55, 
    'AB' => 3,
    'AC' => 20, 
    'AD' => 20, 
    'AE' => 20, 
    'AF' => 3,
    'AG' => 16, 
    'AH' => 10, 
    'AI' => 3,
];

foreach ($column_widths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// Hide D-F spacer columns.
foreach (['D', 'E', 'F'] as $col) {
    $sheet->getColumnDimension($col)->setVisible(false);
}

// Set row height for row 1 (logo row)
$sheet->getRowDimension(1)->setRowHeight(30);

// Dynamic titles based on actual mainzones
$titles = [];
foreach ($tables as $idx => $t) {
    $mz = $t['mainzone'];
    if (strtoupper($mz) === 'NATIONWIDE') {
        $titles[$idx] = 'NATIONWIDE w/ ALLOCATED HEAD OFFICE';
    } else {
        $titles[$idx] = strtoupper($mz) . ' ALLOCATED HEAD OFFICE';
    }
}

// ============================================================
// ADD LOGO FOR EACH TABLE WITH CUSTOM POSITIONING USING COLUMN ANCHORS
// ============================================================
$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
$logo_exists = file_exists($logo_path);

// Define which column offset (0-indexed within each table) the logo should anchor to
// 0 = first column, higher = further right
$logo_anchor_offsets = [
    0 => 6,   // Table 0 (NATIONWIDE): Anchor to column A (far left)
    1 => 4,   // Table 1 (LNCR): Anchor to column R (center-left)
    2 => 4,   // Table 2 (VISMIN): Anchor to column AG (far right)
];

foreach ($table_ranges as $index => $range) {
    $start_num = $range['start_num'];
    $end_num = $range['end_num'];
    $start_col = Coordinate::stringFromColumnIndex($start_num);
    $end_col = Coordinate::stringFromColumnIndex($end_num);
    
    // Merge cells for logo in row 1
    $sheet->mergeCells($start_col . '1:' . $end_col . '1');
    
    if ($logo_exists) {
        // Calculate which column to anchor the logo to
        $offset = $logo_anchor_offsets[$index] ?? 0;
        $anchor_col_num = $start_num + $offset;
        $anchor_col = Coordinate::stringFromColumnIndex($anchor_col_num);
        
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('MLhuillier Logo');
        $drawing->setPath($logo_path);
        $drawing->setHeight(40);
        $drawing->setCoordinates($anchor_col . '1');
        $drawing->setWorksheet($sheet);
        
        // Small offset for fine-tuning (optional - keep at 0 or use small values)
        $drawing->setOffsetX(0);
        $drawing->setOffsetY(0);
    } else {
        // If logo doesn't exist, leave the merged cells empty
        $sheet->setCellValue($start_col . '1', '');
    }
}

// ============================================================
// ADD TABLE HEADERS (starting from row 2)
// ============================================================

// Row 2: Table titles (side-by-side)
foreach ($table_ranges as $index => $range) {
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    
    $sheet->mergeCells($start_col . '2:' . $end_col . '2');
    $sheet->setCellValue($start_col . '2', $titles[$index] ?? '');
    $sheet->getStyle($start_col . '2')->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '000000']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
}

// Row 3: Subtitle
foreach ($table_ranges as $index => $range) {
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    
    $sheet->mergeCells($start_col . '3:' . $end_col . '3');
    $sheet->setCellValue($start_col . '3', 'CONSOLIDATED PROFIT & LOSS STATEMENT');
    $sheet->getStyle($start_col . '3')->applyFromArray([
        'font' => ['bold' => true, 'size' => 10],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
}

// Row 4: Period
$period_display = '';
if (!empty($selected_period)) {
    $date = DateTime::createFromFormat('Y-m', $selected_period);
    if ($date) {
        // Get last day of month
        $lastDay = $date->format('t');
        $period_display = 'FOR THE MONTH ENDED ' . strtoupper($date->format('F d, Y'));
        // Replace the day with the last day of the month
        $period_display = preg_replace('/\d{2},/', $lastDay . ',', $period_display);
    }
}

foreach ($table_ranges as $index => $range) {
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    
    $sheet->mergeCells($start_col . '4:' . $end_col . '4');
    $sheet->setCellValue($start_col . '4', $period_display);
    $sheet->getStyle($start_col . '4')->applyFromArray([
        'font' => ['italic' => true, 'size' => 10],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
}

// Rows 5-6: empty spacers
foreach ([5, 6] as $r) {
    foreach ($table_ranges as $range) {
        $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
        $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
        $sheet->mergeCells($start_col . $r . ':' . $end_col . $r);
    }
}

// Row 7: Column headers
$sheet->getRowDimension(7)->setRowHeight(25);

$table_headers = [
    0 => ['', '', '', '', '', '', 'MLFSI', 'JEWELERS', 'HEAD OFFICE', '', 'TOTAL', '%', ''],
    1 => ['', '', '', '', 'MLFSI', 'JEWELERS', 'HEAD OFFICE', '', 'TOTAL', '%', ''],
    2 => ['', '', '', '', 'MLFSI', 'JEWELERS', 'HEAD OFFICE', '', 'TOTAL', '%', ''],
];

// Define which columns should have background color (1-indexed within each table)
$colored_columns = [7, 8, 9, 11, 12, 18, 19, 20, 22, 23, 29, 30, 31, 33, 34];

foreach ($table_ranges as $index => $range) {
    $start_num = $range['start_num'];
    foreach ($table_headers[$index] as $i => $header) {
        $col = Coordinate::stringFromColumnIndex($start_num + $i);
        $cell = $col . '7';
        $sheet->setCellValue($cell, $header);
        
        if (!empty($header) && in_array($start_num + $i, $colored_columns)) {
            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '000000']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FABF8F']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
            ]);
        } else {
            $sheet->getStyle($cell)->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
            ]);
        }
    }
}

// Row 8: empty spacer
foreach ($table_ranges as $range) {
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    $sheet->mergeCells($start_col . '8:' . $end_col . '8');
}

// ============================================================
// DATA ROWS - SIDE BY SIDE (aligned)
// ============================================================

// Find the maximum number of rows across all tables so we stay aligned
$max_data_rows = 0;
foreach ($tables as $t) {
    $max_data_rows = max($max_data_rows, count($t['rows']));
}

$current_row = 9;

// Write REVENUES header on the same row for all three tables
foreach ($table_ranges as $index => $range) {
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    $sheet->mergeCells($start_col . $current_row . ':' . $end_col . $current_row);
    $sheet->setCellValue($start_col . $current_row, 'REVENUES');
    $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '000000']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F79646']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
}
$current_row++;

// Exact column layout for each table.
$table_data_columns = [
    0 => ['sort' => 'A', 'description' => 'B', 'gl' => 'C', 'mlfsi' => 'G', 'jewelers' => 'H', 'head' => 'I', 'total' => 'K', 'percent' => 'L'],
    1 => ['sort' => 'N', 'description' => 'O', 'gl' => 'P', 'mlfsi' => 'R', 'jewelers' => 'S', 'head' => 'T', 'total' => 'V', 'percent' => 'W'],
    2 => ['sort' => 'Y', 'description' => 'Z', 'gl' => 'AA', 'mlfsi' => 'AC', 'jewelers' => 'AD', 'head' => 'AE', 'total' => 'AG', 'percent' => 'AH'],
];

// Define special labels that should appear in the sort column
$special_labels = [
    'TOTAL REVENUES',
    'GROSS PROFIT',
    'TOTAL SELLING AND ADMIN EXPENSES',
    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
    'EARNINGS BEFORE INTEREST & TAXES',
    'EARNINGS BEFORE TAXES',
    'TOTAL NET INCOME/LOSS',
    'SELLING & ADMIN EXPENSE'
];

// Track row types for grouping (only revenue section - above TOTAL REVENUES)
$row_types = [];
$found_total_revenues = false;

// Track sort_order for alternating colors
$sort_order_counter = 0;

for ($r = 0; $r < $max_data_rows; $r++) {

    foreach ($tables as $table_index => $table) {
        $range     = $table_ranges[$table_index];
        $start_num = $range['start_num'];
        $total_revenues = $total_revenues_by_table[$table_index] ?? 1;

        if (!isset($table['rows'][$r])) {
            continue;
        }

        $row = $table['rows'][$r];

        // Check if this is a spacer row
        $is_spacer = isset($row['is_spacer']) && $row['is_spacer'] === true;
        $is_manual_spacer = isset($row['is_manual_spacer']) && $row['is_manual_spacer'] === true;
        
        // Skip spacer rows for data output but track them for grouping
        if ($is_spacer || $is_manual_spacer) {
            // Track spacer for grouping (only before TOTAL REVENUES)
            if ($table_index === 0 && !$found_total_revenues) {
                $row_types[$current_row] = 'spacer';
            }
            continue;
        }

        $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
        $is_header      = !empty($row['is_section_header']);
        $is_sort_order_summary = isset($row['is_sort_order_summary']) && $row['is_sort_order_summary'] === true;

        $period_mlfsi    = $row['period_mlfsi'] ?? 0;
        $period_jewelers = $row['period_jewelers'] ?? 0;
        $period_total    = $row['period_total'] ?? 0;
        $head_office     = $row['head_office'] ?? 0;

        if (!$is_summary_row && !empty($row['is_inj2'])) {
            $period_mlfsi    = -$period_mlfsi;
            $period_jewelers = -$period_jewelers;
            $period_total    = -$period_total;
            $head_office     = -$head_office;
        }

        $percentage = ($total_revenues > 0 && $period_total != 0)
            ? ($period_total / $total_revenues) * 100
            : 0;

        $cols = $table_data_columns[$table_index];

        // Determine if this is a special label
        $sort_order_value = $row['sort_order'] ?? '';
        $is_special_label = $is_summary_row && in_array($sort_order_value, $special_labels);

        // Check if we've found TOTAL REVENUES
        if ($is_special_label && $sort_order_value === 'TOTAL REVENUES') {
            $found_total_revenues = true;
        }

        // Sort order column - show special labels, hide numeric sort orders
        if ($is_special_label) {
            $sheet->setCellValue($cols['sort'] . $current_row, $sort_order_value);
        } else {
            $sheet->setCellValue($cols['sort'] . $current_row, '');
        }

        // Description column
        if ($is_header) {
            $sheet->setCellValue($cols['description'] . $current_row, $row['sub_order'] ?? '');
        } elseif ($is_summary_row) {
            if (!in_array($sort_order_value, $special_labels)) {
                $sheet->setCellValue($cols['description'] . $current_row, $row['gl_description'] ?? '');
            }
        }

        // GL description (detail rows only)
        if (!$is_summary_row) {
            $sheet->setCellValue($cols['gl'] . $current_row, $row['gl_description'] ?? '');
        }

        // MLFSI
        $col7 = $cols['mlfsi'];
        if (!$is_header && $period_mlfsi !== null) {
            $sheet->setCellValue($col7 . $current_row, $period_mlfsi);
            $sheet->getStyle($col7 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($period_mlfsi < 0) {
                $sheet->getStyle($col7 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // JEWELERS
        $col8 = $cols['jewelers'];
        if (!$is_header && $period_jewelers !== null) {
            $sheet->setCellValue($col8 . $current_row, $period_jewelers);
            $sheet->getStyle($col8 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($period_jewelers < 0) {
                $sheet->getStyle($col8 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // HEAD OFFICE
        $col9 = $cols['head'];
        if (!$is_header && $head_office !== null) {
            $sheet->setCellValue($col9 . $current_row, $head_office);
            $sheet->getStyle($col9 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($head_office < 0) {
                $sheet->getStyle($col9 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // TOTAL
        $col11 = $cols['total'];
        if (!$is_header && $period_total !== null) {
            $sheet->setCellValue($col11 . $current_row, $period_total);
            $sheet->getStyle($col11 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($period_total < 0) {
                $sheet->getStyle($col11 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // %
        $col12 = $cols['percent'];
        if (!$is_header && $percentage !== null) {
            $sheet->setCellValue($col12 . $current_row, $percentage);
            $sheet->getStyle($col12 . $current_row)->getNumberFormat()->setFormatCode('0.00');
        }

        // Styling
        $start_col = Coordinate::stringFromColumnIndex($start_num);
        $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);

        if ($is_summary_row) {
            $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
                'font' => ['bold' => true]
            ]);

            // Check if this is a sort_order summary row (not TOTAL REVENUES, GROSS PROFIT, etc.)
            if ($is_sort_order_summary) {
                // Get the sort_order number
                $sort_order_num = (int)$row['sort_order'];
                
                // Only apply background for even sort_order numbers
                if ($sort_order_num % 2 == 0) {
                    $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDE9D9']]
                    ]);
                }
                // Odd sort_order numbers get no background (default)
            }

            $sub_order_value = $row['sub_order'] ?? '';
            $should_color = false;
            $color_code = '';

            if ($sort_order_value === 'TOTAL REVENUES') {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === 'GROSS PROFIT') {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === 'TOTAL SELLING AND ADMIN EXPENSES') {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT") {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === 'EARNINGS BEFORE INTEREST & TAXES') {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === 'EARNINGS BEFORE TAXES') {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === 'TOTAL NET INCOME/LOSS') {
                $should_color = true;
                $color_code = 'FCD5B4';
            } elseif ($sort_order_value === 'SELLING & ADMIN EXPENSE' || $sub_order_value === 'SELLING & ADMIN EXPENSE') {
                $should_color = true;
                $color_code = 'F79646';
            } elseif ($sub_order_value === 'Cost of Sales/Service') {
                $should_color = true;
                $color_code = 'FCD5B4';
            }

            if ($should_color) {
                $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color_code]]
                ]);
            }
        }

        // Track row type for grouping (only for revenue section - before TOTAL REVENUES)
        if ($table_index === 0 && !$found_total_revenues) {
            if ($is_special_label && $sort_order_value === 'TOTAL REVENUES') {
                $row_types[$current_row] = 'total_revenues';
            } elseif ($is_special_label || ($sort_order_value === 'GROSS PROFIT') || ($sort_order_value === 'TOTAL SELLING AND ADMIN EXPENSES')) {
                $row_types[$current_row] = 'special';
            } elseif ($is_header) {
                $row_types[$current_row] = 'header';
            } elseif ($is_summary_row && $is_sort_order_summary) {
                $row_types[$current_row] = 'summary_end';
            } elseif (!$is_summary_row && !$is_header) {
                $row_types[$current_row] = 'detail';
            }
        }
    }

    $current_row++;
}

// ============================================================
// APPLY EXCEL OUTLINE GROUPING - ONLY FOR REVENUE SECTION
// ============================================================

$all_rows = $current_row;

// Group revenue detail rows under the REVENUES header (Level 2)
// This is the primary grouping - only for rows above TOTAL REVENUES
$row_num = 10;
$in_revenue_section = false;

while ($row_num < $all_rows) {
    $type = $row_types[$row_num] ?? '';
    
    // Check if we're in the revenue section (before TOTAL REVENUES)
    $is_before_total_revenues = true;
    for ($check = 10; $check < $row_num; $check++) {
        if (isset($row_types[$check]) && $row_types[$check] === 'total_revenues') {
            $is_before_total_revenues = false;
            break;
        }
    }
    
    if ($is_before_total_revenues && ($type === 'detail' || $type === 'spacer')) {
        // This is a revenue detail or spacer row, set to outline level 1
        $sheet->getRowDimension($row_num)->setOutlineLevel(1);
        $in_revenue_section = true;
    } elseif ($type === 'summary_end' || $type === 'special' || $type === 'total_revenues') {
        // End of revenue section details
        $in_revenue_section = false;
    }
    
    $row_num++;
}

// Make sure all revenue detail rows and spacers are at level 1
// and ensure TOTAL REVENUES row is NOT grouped
for ($row_num = 10; $row_num < $all_rows; $row_num++) {
    $type = $row_types[$row_num] ?? '';
    
    // Check if this row is before TOTAL REVENUES
    $is_before_total = true;
    for ($check = 10; $check < $row_num; $check++) {
        if (isset($row_types[$check]) && $row_types[$check] === 'total_revenues') {
            $is_before_total = false;
            break;
        }
    }
    
    if ($is_before_total && ($type === 'detail' || $type === 'spacer')) {
        // Set to outline level 1 (will be hidden when collapsed)
        $sheet->getRowDimension($row_num)->setOutlineLevel(1);
    } elseif ($type === 'summary_end' && $is_before_total) {
        // Summary rows should be visible (level 0)
        $sheet->getRowDimension($row_num)->setOutlineLevel(0);
    } elseif ($type === 'total_revenues') {
        // TOTAL REVENUES should always be visible (level 0)
        $sheet->getRowDimension($row_num)->setOutlineLevel(0);
    }
}

// Set print area / freeze panes for convenience
$sheet->freezePane('A10');

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Profit_Loss_Statement_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;
?>
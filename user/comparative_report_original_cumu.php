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

// Filters
$selected_years = $_GET['transaction_year'] ?? [];
if (!is_array($selected_years)) {
    $selected_years = $selected_years !== '' ? [$selected_years] : [];
}
rsort($selected_years);

$start_month = $_GET['start_month'] ?? '';
$end_month = $_GET['end_month'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old'; // old|new|mixed
$gl_code_mode = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

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

if (!empty($selected_years) && !empty($start_month) && !empty($end_month)) {
    if ((int)$end_month < (int)$start_month) {
        $error_message = 'End month must be later than or equal to the start month.';
        $show_error = true;
    } else {
        $valid_filters = true;
    }
} else if (!empty($_GET) && !isset($_GET['reset'])) {
    $error_message = 'Please select at least one year and a valid month range.';
    $show_error = true;
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: comparative_report_original_cumu.php");
    exit;
}

// Dropdown options
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
// GET GL MAPPING: sort_order + sub_order -> gl_id -> gl_codes
// ============================================================
$gl_mapping = []; // sort_order|sub_order -> [gl_codes]
$gl_descriptions = []; // sort_order|sub_order -> gl_description_comparative
$sort_order_descriptions = []; // sort_order -> description
$special_keys = []; // To track keys that have gl_id = 'INJ-2'

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, new_gl_code, gl_description_comparative, description
    FROM fs_reports.gl_codes_ho_new
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

        // Store gl_codes for this combination (old and new)
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['old' => [], 'new' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'];
        }

        $old_code = trim((string)($row['gl_code'] ?? ''));
        $new_code = trim((string)($row['new_gl_code'] ?? ''));
        if ($new_code === '') $new_code = $old_code;

        if ($old_code !== '' && !in_array($old_code, $gl_mapping[$key]['old'], true)) {
            $gl_mapping[$key]['old'][] = $old_code;
        }
        if ($new_code !== '' && !in_array($new_code, $gl_mapping[$key]['new'], true)) {
            $gl_mapping[$key]['new'][] = $new_code;
        }

        // Store sort_order description
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

function compute_table_rows(mysqli $conn, array $years, string $start_month, string $end_month, string $gl_code_mode, array $gl_mapping, array $gl_descriptions, array $special_keys, array $sort_order_descriptions, bool $use_real_data = true): array {
// ============================================================
// BUILD WHERE CLAUSE FOR FILTERS (no region/area filters)
// ============================================================
// Base filters (no region/area filters)
$base_where = "WHERE (status_void IS NULL OR status_void != 'Void')";

// Helper to fetch data for a specific year and month range
$fetch_period = function($year, $s_month, $e_month) use ($conn, $base_where) {
    $data = []; // month -> gl_code -> total_amount
    $sql = "
        SELECT 
            gl_code,
            MONTH(transaction_month) as m,
            SUM(amount) as total_amount
        FROM fs_reports.comparative_report
        $base_where
        AND transaction_year = ? 
        AND MONTH(transaction_month) BETWEEN ? AND ?
        AND gl_code IS NOT NULL AND gl_code != ''
        GROUP BY gl_code, m
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iii', $year, $s_month, $e_month);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $m = (int)$row['m'];
            $gl = $row['gl_code'];
            $data[$m][$gl] = floatval($row['total_amount']);
        }
        mysqli_stmt_close($stmt);
    }
    return $data;
};

// Exclude voided transactions

// ============================================================
// FETCH DATA FOR SELECTED YEARS
// ============================================================
$y1 = $years[0] ?? null;
$y2 = $years[1] ?? null;
$y3 = $years[2] ?? null;

$primary_data  = ($use_real_data && $y1) ? $fetch_period($y1, $start_month, $end_month) : [];
$previous_data = ($use_real_data && $y2) ? $fetch_period($y2, $start_month, $end_month) : [];
$third_data    = ($use_real_data && $y3) ? $fetch_period($y3, $start_month, $end_month) : [];

// ============================================================
// BUILD TABLE ROWS BASED ON GL MAPPING
// ============================================================
$table_rows = [];
$y1 = $years[0] ?? null;
$y2 = $years[1] ?? null;
$y3 = $years[2] ?? null;

foreach ($gl_mapping as $key => $codes_detailed) {
    [$sort_order, $sub_order] = explode('|', $key);
    $gl_description = $gl_descriptions[$key];
    $is_inj2 = in_array($key, $special_keys);
    
    $calc_total = function($year_data, $year) use ($gl_code_mode, $start_month, $end_month, $codes_detailed) {
        $total = 0;
        if (empty($year_data)) return 0;
        for ($m = (int)$start_month; $m <= (int)$end_month; $m++) {
            if (!isset($year_data[$m])) continue;
            $mode = $gl_code_mode;
            if ($gl_code_mode === 'mixed') {
                $period_str = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                $mode = isApril2026OrLater($period_str) ? 'new' : 'old';
            }
            $target_codes = $codes_detailed[$mode];
            foreach ($target_codes as $gl_code) {
                if (isset($year_data[$m][$gl_code])) {
                    $total += $year_data[$m][$gl_code];
                }
            }
        }
        return $total;
    };

    $primary_total = $calc_total($primary_data, $y1);
    $previous_total = $calc_total($previous_data, $y2);
    $third_total = $calc_total($third_data, $y3);
    
    // Add the detail row for this GL combination
    $table_rows[] = [
        'sort_order' => $sort_order,
        'sub_order' => $sub_order,
        'gl_description' => $gl_description,
        'is_section_header' => false,
        'is_summary_row' => false,
        'primary_total' => $primary_total,
        'previous_total' => $previous_total,
        'third_total' => $third_total,
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
$rev_tot_p = 0;
$rev_tot_prev = 0;
$rev_tot_third = 0;
$sa_tot_p = 0;
$sa_tot_prev = 0;
$sa_tot_third = 0;
$gp_tot_p = 0;
$gp_tot_prev = 0;
$gp_tot_third = 0;
$ebitda_tot_p = 0;
$ebitda_tot_prev = 0;
$ebitda_tot_third = 0;
$ebit_tot_p = 0;
$ebit_tot_prev = 0;
$ebit_tot_third = 0;
$ebt_tot_p = 0;
$ebt_tot_prev = 0;
$ebt_tot_third = 0;
$net_tot_p = 0;
$net_tot_prev = 0;
$net_tot_third = 0;

foreach ($grouped_rows as $sort_order => $rows) {
    // Add all detail rows for this sort_order
    // Skip details for sort orders 10,13 to show only the summary row
    if (!in_array((int)$sort_order, [10, 13])) {
        foreach ($rows as $row) {
            $final_table_rows[] = $row;
        }
    }
    
    // Calculate totals for summary row
    $total_primary_total = array_sum(array_column($rows, 'primary_total'));
    $total_previous_total = array_sum(array_column($rows, 'previous_total'));
    $total_third_total = array_sum(array_column($rows, 'third_total'));

    // Accumulate for Total Revenues if sort_order is within the revenue range (1-22)
    if ((int)$sort_order >= 1 && (int)$sort_order <= 22) {
        $rev_tot_p += $total_primary_total;
        $rev_tot_prev += $total_previous_total;
        $rev_tot_third += $total_third_total;
    }
    
    // Accumulate for Total Selling and Admin Expenses if sort_order is 24 or 25
    if ((int)$sort_order == 24 || (int)$sort_order == 25) {
        $sa_tot_p += $total_primary_total;
        $sa_tot_prev += $total_previous_total;
        $sa_tot_third += $total_third_total;
    }
    
    $inc_dec = $total_primary_total - $total_previous_total;
    $percentage = 0;
    if ($total_previous_total != 0) {
        $percentage = ($inc_dec / $total_previous_total) * 100;
    } elseif ($total_primary_total > 0) {
        $percentage = 100;
    } elseif ($total_primary_total < 0) {
        $percentage = -100;
    } else {
        $percentage = 0;
    }

    $inc_dec_third = $total_primary_total - $total_third_total;
    if ($y3 && $total_third_total != 0) {
        $percentage_third = ($inc_dec_third / $total_third_total) * 100;
    } elseif ($y3 && $total_primary_total > 0) {
        $percentage_third = 100;
    } elseif ($y3 && $total_primary_total < 0) {
        $percentage_third = -100;
    } else {
        $percentage_third = 0;
    }
    
    $description = isset($sort_order_descriptions[$sort_order]) 
        ? $sort_order_descriptions[$sort_order] 
        : "Total for Sort Order " . $sort_order;
    
    // Add summary row
    // Hide summary row for sort orders 26,27,28 but keep calculations for subsequent computed rows
    if (!in_array((int)$sort_order, [26,27,28])) {
        $final_table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => '',
            'gl_description' => $description,
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_total' => $total_primary_total,
            'previous_total' => $total_previous_total,
            'third_total' => $total_third_total,
            'inc_dec' => $inc_dec,
            'percentage' => $percentage,
            'inc_dec_third' => $inc_dec_third,
            'percentage_third' => $percentage_third
        ];
    }

    // Insert TOTAL REVENUES summary row after the sort_order 22 block
    if ((int)$sort_order == 22) {
        $inc_dec_rev = $rev_tot_p - $rev_tot_prev;
        $pct_rev = ($rev_tot_prev != 0) ? ($inc_dec_rev / $rev_tot_prev) * 100 : ($rev_tot_p > 0 ? 100 : ($rev_tot_p < 0 ? -100 : 0));
        $inc_dec_rev_third = $rev_tot_p - $rev_tot_third;
        $pct_rev_third = ($y3 && $rev_tot_third != 0) ? ($inc_dec_rev_third / $rev_tot_third) * 100 : ($y3 ? ($rev_tot_p > 0 ? 100 : ($rev_tot_p < 0 ? -100 : 0)) : 0);

        $final_table_rows[] = [
            'sort_order' => 'TOTAL REVENUES',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_total' => $rev_tot_p,
            'previous_total' => $rev_tot_prev,
            'third_total' => $rev_tot_third,
            'inc_dec' => $inc_dec_rev,
            'percentage' => $pct_rev,
            'inc_dec_third' => $inc_dec_rev_third,
            'percentage_third' => $pct_rev_third
        ];

        // Insert Cost of Sales/Service header row
        $final_table_rows[] = [
            'sort_order' => '',
            'sub_order' => 'Cost of Sales/Service',
            'gl_description' => '',
            'is_section_header' => true,
            'is_summary_row' => true,
            'primary_total' => null,
            'previous_total' => null,
            'third_total' => null,
            'inc_dec' => null,
            'percentage' => null,
            'inc_dec_third' => null,
            'percentage_third' => null
        ];
    }

    // Insert GROSS PROFIT summary row after sort_order 23 (Cost of Sales)
    if ((int)$sort_order == 23) {
        $gp_tot_p = $rev_tot_p - $total_primary_total;
        $gp_tot_prev = $rev_tot_prev - $total_previous_total;
        $gp_tot_third = $rev_tot_third - $total_third_total;
        
        $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
        $pct_gp = ($gp_tot_prev != 0) ? ($inc_dec_gp / $gp_tot_prev) * 100 : ($gp_tot_p > 0 ? 100 : ($gp_tot_p < 0 ? -100 : 0));
        $inc_dec_gp_third = $gp_tot_p - $gp_tot_third;
        $pct_gp_third = ($y3 && $gp_tot_third != 0) ? ($inc_dec_gp_third / $gp_tot_third) * 100 : ($y3 ? ($gp_tot_p > 0 ? 100 : ($gp_tot_p < 0 ? -100 : 0)) : 0);

        $final_table_rows[] = [
            'sort_order' => 'GROSS PROFIT',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_total' => $gp_tot_p,
            'previous_total' => $gp_tot_prev,
            'third_total' => $gp_tot_third,
            'inc_dec' => $inc_dec_gp,
            'percentage' => $pct_gp,
            'inc_dec_third' => $inc_dec_gp_third,
            'percentage_third' => $pct_gp_third
        ];

        // Add SELLING & ADMIN EXPENSE header row
        $final_table_rows[] = [
            'sort_order' => 'SELLING & ADMIN EXPENSE',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => true,
            'is_summary_row' => true,
            'primary_total' => null,
            'previous_total' => null,
            'third_total' => null,
            'inc_dec' => null,
            'percentage' => null,
            'inc_dec_third' => null,
            'percentage_third' => null
        ];
    }

    // Insert TOTAL SELLING AND ADMIN EXPENSES summary row after sort_order 25
    if ((int)$sort_order == 25) {
        $inc_dec_sa = $sa_tot_p - $sa_tot_prev;
        $pct_sa = ($sa_tot_prev != 0) ? ($inc_dec_sa / $sa_tot_prev) * 100 : ($sa_tot_p > 0 ? 100 : ($sa_tot_p < 0 ? -100 : 0));
        $inc_dec_sa_third = $sa_tot_p - $sa_tot_third;
        $pct_sa_third = ($y3 && $sa_tot_third != 0) ? ($inc_dec_sa_third / $sa_tot_third) * 100 : ($y3 ? ($sa_tot_p > 0 ? 100 : ($sa_tot_p < 0 ? -100 : 0)) : 0);

        $final_table_rows[] = [
            'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'primary_total' => $sa_tot_p,
            'previous_total' => $sa_tot_prev,
            'third_total' => $sa_tot_third,
            'inc_dec' => $inc_dec_sa,
            'percentage' => $pct_sa,
            'inc_dec_third' => $inc_dec_sa_third,
            'percentage_third' => $pct_sa_third
        ];

        // Calculate EBITDA: Gross Profit - Total Selling and Admin Expenses
        $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
        $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;
        $ebitda_tot_third = $gp_tot_third - $sa_tot_third;

        $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
        $pct_ebitda = ($ebitda_tot_prev != 0) ? ($inc_dec_ebitda / $ebitda_tot_prev) * 100 : ($ebitda_tot_p > 0 ? 100 : ($ebitda_tot_p < 0 ? -100 : 0));
        $inc_dec_ebitda_third = $ebitda_tot_p - $ebitda_tot_third;
        $pct_ebitda_third = ($y3 && $ebitda_tot_third != 0) ? ($inc_dec_ebitda_third / $ebitda_tot_third) * 100 : ($y3 ? ($ebitda_tot_p > 0 ? 100 : ($ebitda_tot_p < 0 ? -100 : 0)) : 0);

        $final_table_rows[] = [
            'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
            'sub_order' => '',
            'gl_description' => '',
            'is_section_header' => false,
            'is_summary_row' => true,
            'skip_spacer' => true,
            'primary_total' => $ebitda_tot_p,
            'previous_total' => $ebitda_tot_prev,
            'third_total' => $ebitda_tot_third,
            'inc_dec' => $inc_dec_ebitda,
            'percentage' => $pct_ebitda,
            'inc_dec_third' => $inc_dec_ebitda_third,
            'percentage_third' => $pct_ebitda_third
        ];
    }

    // Insert EARNINGS BEFORE INTEREST & TAXES summary row after sort_order 26
    if ((int)$sort_order == 26) {
        $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
        $ebit_tot_prev = $ebitda_tot_prev - $total_previous_total;
        $ebit_tot_third = $ebitda_tot_third - $total_third_total;

        $inc_dec_ebit = $ebit_tot_p - $ebit_tot_prev;
        $pct_ebit = ($ebit_tot_prev != 0) ? ($inc_dec_ebit / $ebit_tot_prev) * 100 : ($ebit_tot_p > 0 ? 100 : ($ebit_tot_p < 0 ? -100 : 0));
        $inc_dec_ebit_third = $ebit_tot_p - $ebit_tot_third;
        $pct_ebit_third = ($y3 && $ebit_tot_third != 0) ? ($inc_dec_ebit_third / $ebit_tot_third) * 100 : ($y3 ? ($ebit_tot_p > 0 ? 100 : ($ebit_tot_p < 0 ? -100 : 0)) : 0);

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
            'third_total' => $ebit_tot_third,
            'inc_dec' => $inc_dec_ebit,
            'percentage' => $pct_ebit,
            'inc_dec_third' => $inc_dec_ebit_third,
            'percentage_third' => $pct_ebit_third
        ];
    }

    // Insert EARNINGS BEFORE TAXES summary row after sort_order 27
    if ((int)$sort_order == 27) {
        $ebt_tot_p = $ebit_tot_p - $total_primary_total;
        $ebt_tot_prev = $ebit_tot_prev - $total_previous_total;
        $ebt_tot_third = $ebit_tot_third - $total_third_total;

        $inc_dec_ebt = $ebt_tot_p - $ebt_tot_prev;
        $pct_ebt = ($ebt_tot_prev != 0) ? ($inc_dec_ebt / $ebt_tot_prev) * 100 : ($ebt_tot_p > 0 ? 100 : ($ebt_tot_p < 0 ? -100 : 0));
        $inc_dec_ebt_third = $ebt_tot_p - $ebt_tot_third;
        $pct_ebt_third = ($y3 && $ebt_tot_third != 0) ? ($inc_dec_ebt_third / $ebt_tot_third) * 100 : ($y3 ? ($ebt_tot_p > 0 ? 100 : ($ebt_tot_p < 0 ? -100 : 0)) : 0);

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
            'third_total' => $ebt_tot_third,
            'inc_dec' => $inc_dec_ebt,
            'percentage' => $pct_ebt,
            'inc_dec_third' => $inc_dec_ebt_third,
            'percentage_third' => $pct_ebt_third
        ];
    }

    // Insert TOTAL NET INCOME/LOSS summary row after sort_order 28
    if ((int)$sort_order == 28) {
        $net_tot_p = $ebt_tot_p - $total_primary_total;
        $net_tot_prev = $ebt_tot_prev - $total_previous_total;
        $net_tot_third = $ebt_tot_third - $total_third_total;

        $inc_dec_net = $net_tot_p - $net_tot_prev;
        $pct_net = ($net_tot_prev != 0) ? ($inc_dec_net / $net_tot_prev) * 100 : ($net_tot_p > 0 ? 100 : ($net_tot_p < 0 ? -100 : 0));
        $inc_dec_net_third = $net_tot_p - $net_tot_third;
        $pct_net_third = ($y3 && $net_tot_third != 0) ? ($inc_dec_net_third / $net_tot_third) * 100 : ($y3 ? ($net_tot_p > 0 ? 100 : ($net_tot_p < 0 ? -100 : 0)) : 0);

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
            'third_total' => $net_tot_third,
            'inc_dec' => $inc_dec_net,
            'percentage' => $pct_net,
            'inc_dec_third' => $inc_dec_net_third,
            'percentage_third' => $pct_net_third
        ];
    }
}

return $final_table_rows;
}

// ============================================================
// BUILD SINGLE TABLE FOR ALL DATA
// ============================================================
$table_rows = compute_table_rows(
    $conn,
    $selected_years,
    $start_month,
    $end_month,
    $gl_code_mode,
    $gl_mapping,
    $gl_descriptions,
    $special_keys,
    $sort_order_descriptions,
    $valid_filters // Use real data only if filters are valid
);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cumulative Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="fs_reports.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-title">Cumulative Report</div>

            <!-- Error Banner for validation issues -->
            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <form method="GET" class="filter-form" id="filterForm" onsubmit="return validateForm()">
                

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
                    <label>Month Range</label>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <select name="start_month" id="startMonthSelect" style="flex: 1;">
                            <option value="">Start Month</option>
                            <?php foreach ($month_names as $num => $label): ?>
                                <option value="<?= $num ?>" <?= (string)$start_month === (string)$num ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span style="color:red; font-weight:bold;">&nbsp;&nbsp;&nbsp;TO&nbsp;&nbsp;&nbsp;</span>
                        <select name="end_month" id="endMonthSelect" style="flex: 1;">
                            <option value="">End Month</option>
                            <?php foreach ($month_names as $num => $label): ?>
                                <option value="<?= $num ?>" <?= (string)$end_month === (string)$num ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-group">
                    <label>Transaction Year</label>
                    <div class="custom-select-wrapper">
                        <select name="transaction_year[]" id="yearSelect" multiple size="1" style="width: 100%;">
                            <?php foreach($distinct_years as $yr): ?>
                                <option value="<?= htmlspecialchars($yr) ?>" <?= in_array($yr, $selected_years) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($yr) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- <small style="color: #666; font-size: 11px;">Select up to 3 years to compare.</small> -->
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <button type="button" class="btn-collapse" id="collapseBtn"><i class="fa-solid fa-compress"></i> Collapse</button>
                    <a href="export_comparative_cumu.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                </div>
            </form>

            <!-- Single Table for All Data -->
            <?php
                $y1 = $selected_years[0] ?? null;
                $y2 = $selected_years[1] ?? null;
                $y3 = $selected_years[2] ?? null;
                
                $range_label = "";
                if ($valid_filters) {
                    $start_name = substr($month_names[(int)$start_month], 0, 3);
                    $end_name = substr($month_names[(int)$end_month], 0, 3);
                    $range_label = ($start_month == $end_month) 
                        ? strtoupper($month_names[(int)$start_month]) 
                        : strtoupper($start_name . " - " . $end_name);
                }
            ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th colspan="4">Cumulative Report</th>
                            <th colspan="11">Nationwide</th>
                        </tr>
                        <tr>
                            <th colspan="4"></th>
                            <th colspan="1"><?php echo $y1 ? strtoupper($range_label . ' ' . $y1) : '(Primary Period)'; ?></th>
                            <th></th>
                            <th colspan="1"><?php echo $y2 ? strtoupper($range_label . ' ' . $y2) : '(Period 2)'; ?></th>
                            <th></th>
                            <th colspan="1"><?php echo $y3 ? strtoupper($range_label . ' ' . $y3) : '(Period 3)'; ?></th>
                            <th></th>
                            <th colspan="4">INCREASE / DECREASE</th>
                            <th></th>
                        </tr>
                        <tr>
                            <th colspan="4"></th>
                            <th colspan="6"></th>
                            <th style="text-align: center;"><?php echo ($y1 && $y2) ? "$y1 vs $y2" : "YEAR VS YEAR"; ?></th>
                            <th style="text-align: center;">%</th>
                            <th style="text-align: center;"><?php echo ($y1 && $y3) ? "$y1 vs $y3" : "YEAR VS YEAR"; ?></th>
                            <th style="text-align: center;">%</th>
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
                                
                                $primary_total = $row['primary_total'] ?? 0;
                                $previous_total = $row['previous_total'] ?? 0;
                                $third_total = $row['third_total'] ?? 0;
                                $has_third = !empty($y3);
                                
                                if (!$is_summary_row && !empty($row['is_inj2'])) {
                                    $primary_total = -$primary_total;
                                    $previous_total = -$previous_total;
                                    $third_total = -$third_total;
                                }
                                
                                $inc_dec = isset($row['inc_dec']) ? $row['inc_dec'] : ($primary_total - $previous_total);
                                
                                if (isset($row['percentage'])) {
                                    $percentage = $row['percentage'];
                                } else {
                                    $percentage = ($previous_total != 0) ? ($inc_dec / abs($previous_total)) * 100 : ($primary_total > 0 ? 100 : ($primary_total < 0 ? -100 : 0));
                                }
                                if ($previous_total == 0 && $primary_total != 0) {
                                    $percentage = $primary_total > 0 ? 100 : -100;
                                }
                                
                                $inc_dec_class = $inc_dec > 0 ? 'positive' : (($inc_dec < 0) ? 'negative' : '');
                                $percentage_class = $percentage > 0 ? 'positive' : ($percentage < 0 ? 'negative' : '');

                                $inc_dec_third = array_key_exists('inc_dec_third', $row) ? $row['inc_dec_third'] : ($primary_total - $third_total);
                                if (array_key_exists('percentage_third', $row)) {
                                    $percentage_third = $row['percentage_third'];
                                } else {
                                                if ($has_third && $third_total != 0) {
                                                    $percentage_third = ($inc_dec_third / $third_total) * 100;
                                                } elseif ($primary_total > 0) {
                                                    $percentage_third = 100;
                                                } elseif ($primary_total < 0) {
                                                    $percentage_third = -100;
                                                } else {
                                                    $percentage_third = 0;
                                                }
                                }
                                if ($third_total == 0 && $primary_total != 0) {
                                    $percentage_third = $primary_total > 0 ? 100 : -100;
                                }

                                $inc_dec_third_class = ($inc_dec_third !== null && $inc_dec_third > 0) ? 'positive' : ((($inc_dec_third !== null && $inc_dec_third < 0) ? 'negative' : ''));
                                $percentage_third_class = ($percentage_third !== null && $percentage_third > 0) ? 'positive' : ((($percentage_third !== null && $percentage_third < 0) ? 'negative' : ''));
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
                                        <?php elseif ((int)($row['sort_order'] ?? 0) === 17 && in_array((int)($row['sub_order'] ?? 0), [3, 4, 5, 6])): ?>
                                            <?= htmlspecialchars($row['gl_description']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= $is_summary_row ? 'summary-cell summary-description' : '' ?>">
                                        <?php 
                                            if (!$is_summary_row) {
                                                if ((int)($row['sort_order'] ?? 0) === 17 && in_array((int)($row['sub_order'] ?? 0), [3, 4, 5, 6])) {
                                                    echo '';
                                                } else {
                                                    echo htmlspecialchars($row['gl_description']);
                                                }
                                            }
                                        ?>
                                    </td>
                                    <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                    
                                    <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($primary_total < 0) ? 'color: red;' : '' ?>">
                                        <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($primary_total, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                    </td>
                                    <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>
                                    
                                    <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($previous_total < 0) ? 'color: red;' : '' ?>">
                                        <?= $is_header ? '' : (($is_summary_row ? '<strong>' : '') . number_format($previous_total, 2) . ($is_summary_row ? '</strong>' : '')) ?>
                                    </td>
                                    <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>

                                    <td class="numeric-cell <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($third_total < 0) ? 'color: red;' : '' ?>">
                                        <?php
                                        if ($is_header) {
                                            echo '';
                                        } else {
                                            echo (($is_summary_row ? '<strong>' : '') . number_format($third_total, 2) . ($is_summary_row ? '</strong>' : ''));
                                        }
                                        ?>
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
                                    <td class="numeric-cell <?= $inc_dec_third_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($inc_dec_third < 0) ? 'color: red;' : '' ?>"><?= $is_header ? '' : number_format((float)$inc_dec_third, 2) ?>
                                    </td>
                                    <td class="percentage-cell <?= $percentage_third_class ?> <?= $is_summary_row ? 'summary-cell' : '' ?>" style="<?= ($percentage_third < 0) ? 'color: red;' : '' ?>">
                                        <?php
                                    if ($is_header) {
                                        echo '';
                                    } elseif ($percentage_third >= 1000 || $percentage_third <= -1000) {
                                        echo 'mat';
                                    } else {
                                        echo number_format((float)$percentage_third, 2) . '%';
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

                const yearSelect = document.getElementById('yearSelect');
                const enforceYearLimit = () => {
                    if (!yearSelect) return;
                    const options = Array.from(yearSelect.options);
                    const selectedCount = options.filter(opt => opt.selected).length;
                    options.forEach((opt) => {
                        if (!opt.selected) {
                            opt.disabled = selectedCount >= 3;
                        } else {
                            opt.disabled = false;
                        }
                    });
                };

                if (yearSelect) {
                    yearSelect.addEventListener('change', enforceYearLimit);
                    enforceYearLimit();
                }

                function validateForm() {
                    const selectedYearsCount = Array.from(yearSelect.selectedOptions).filter(opt => opt.value !== "").length;
                    const startMonth = document.getElementById('startMonthSelect').value;
                    const endMonth = document.getElementById('endMonthSelect').value;

                    if (selectedYearsCount === 0) {
                        showModal('Please select at least one Transaction Year.');
                        return false;
                    }

                    if (!startMonth || !endMonth) {
                        showModal('Please select a valid Month Range (From and To).');
                        return false;
                    }

                    if (parseInt(endMonth) < parseInt(startMonth)) {
                        showModal('End month must be the same as or later than start month.');
                        return false;
                    }

                    return true;
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const collapseBtn = document.getElementById('collapseBtn');
                    let isCollapsed = false;

                    if (collapseBtn) {
                        collapseBtn.addEventListener('click', function() {
                            isCollapsed = !isCollapsed;
                            
                            const tbody = document.querySelector('.report-tbody');
                            if (tbody) {
                                const rows = Array.from(tbody.rows);
                                rows.forEach(row => {
                                    const sortOrder = row.getAttribute('data-sort-order');
                                    const isDetail = row.getAttribute('data-is-detail') === 'true';
                                    const spacerFor = row.getAttribute('data-spacer-for');
                                    
                                    const sortNum = parseInt(sortOrder);
                                    const is1To22 = !isNaN(sortNum) && sortNum >= 1 && sortNum <= 22;
                                    
                                    // 1. Hide detail rows for sort 1-22
                                    if (is1To22 && isDetail) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                    
                                    // 2. Hide spacer rows for sort 1-22
                                    if (spacerFor) {
                                        const spacerNum = parseInt(spacerFor);
                                        if (!isNaN(spacerNum) && spacerNum >= 1 && spacerNum <= 22) {
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
                            }

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
<?php include '../footer.php'; ?>

    </main>
</body>
</html>

<?php
$conn->close();
?>

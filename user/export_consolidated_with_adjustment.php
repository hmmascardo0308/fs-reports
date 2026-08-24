<?php
// export_consolidated_with_adjustment.php

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (!isset($_SESSION['username'])) {
    die("Unauthorized access");
}

// ============================================================
// GET FILTERS
// ============================================================
$zone = $_GET['zone'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

// Auto-calculate previous period if not provided
if (!empty($primary_period) && empty($previous_period)) {
    $date_obj = DateTime::createFromFormat('Y-m', $primary_period);
    if ($date_obj) {
        $date_obj->modify('-1 month');
        $previous_period = $date_obj->format('Y-m');
    }
}

// ============================================================
// FETCH REGIONS IN SELECTED ZONE
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
// REPORT STRUCTURE
// ============================================================
$report_structure = [];
$sort_order_descriptions = [];

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

            if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
                $sort_order_descriptions[$row['sort_order']] = $row['description'];
            }
        }
    }
    mysqli_stmt_close($structure_stmt);
}

// ============================================================
// DIRECT TOTAL ROWS (6, 8, 11)
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
            $key = $row['sort_order'] . '|';

            if (!isset($report_structure[$key])) {
                $report_structure[$key] = [
                    'sort_order' => $row['sort_order'],
                    'description' => $row['description'],
                    'sub_order' => null,
                    'gl_description_comparative' => null
                ];
            }

            if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
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

    if ($aSub === '' && $bSub !== '') {
        return -1;
    }

    if ($aSub !== '' && $bSub === '') {
        return 1;
    }

    return (int)$aSub <=> (int)$bSub;
});

// ============================================================
// COMPUTE TABLE ROWS (same logic as consolidated_with_adjustment.php)
// ============================================================
function compute_export_rows_for_adjustment(
    mysqli $conn,
    string $zone,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    array $report_structure,
    array $sort_order_descriptions,
    array $regions_in_zone,
    bool $use_real_data = true
): array {

    $where_conditions = [];
    $params = [];
    $types = "";

    if (!empty($zone)) {
        $where_conditions[] = "zone = ?";
        $params[] = $zone;
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

        // PRIMARY TOTALS
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

        // PREVIOUS TOTALS
        if (isset($previous_data[$key])) {
            foreach ($previous_data[$key] as $r_name => $amounts) {
                if (empty($regions_in_zone) || in_array($r_name, $regions_in_zone, true)) {
                    $previous_total['mlfsi'] += $amounts['mlfsi'];
                    $previous_total['jewelers'] += $amounts['jewelers'];
                    $previous_total['total'] += $amounts['total'];
                }
            }
        }

        // Add detail row (direct totals 6, 8, 11 excluded from detail display)
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_description' => $gl_description,
            'is_section_header' => false,
            'is_summary_row' => false,
            'primary_total' => $primary_total,
            'previous_total' => $previous_total,
            'region_totals' => $row_region_totals,
            'is_inj2' => false
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
    $rev_tot_p = 0;
    $rev_tot_prev = 0;
    $sa_tot_p = 0;
    $sa_tot_prev = 0;
    $gp_tot_p = 0;
    $gp_tot_prev = 0;
    $ebitda_tot_p = 0;
    $ebitda_tot_prev = 0;
    $ebit_tot_p = 0;
    $ebit_tot_prev = 0;
    $ebt_tot_p = 0;
    $ebt_tot_prev = 0;
    $net_tot_p = 0;
    $net_tot_prev = 0;

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
        // Detail rows - exclude direct totals 6, 8, 11
        if (!in_array((int)$sort_order, [6, 8, 11])) {
            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }

        // Calculate totals for sort order
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

        // Region totals - store as scalar values (not arrays)
        $summary_region_totals = [];

        foreach ($regions_in_zone as $r_name) {
            $region_total = 0;
            foreach ($rows as $row) {
                if (isset($row['region_totals'][$r_name])) {
                    $region_total += $row['region_totals'][$r_name]['total'];
                }
            }
            $summary_region_totals[$r_name] = $region_total;
        }

        // TOTAL REVENUES (sort orders 1-20)
        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
            $rev_tot_p += $total_primary['total'];
            $rev_tot_prev += $total_previous['total'];

            if (empty($rev_reg_p)) {
                $rev_reg_p = array_fill_keys($regions_in_zone, 0);
            }

            foreach ($regions_in_zone as $rn) {
                $rev_reg_p[$rn] += $summary_region_totals[$rn];
            }
        }

        // SELLING & ADMIN EXPENSES (sort orders 22, 23)
        if ((int)$sort_order == 22 || (int)$sort_order == 23) {
            $sa_tot_p += $total_primary['total'];
            $sa_tot_prev += $total_previous['total'];

            if (empty($sa_reg_p)) {
                $sa_reg_p = array_fill_keys($regions_in_zone, 0);
            }

            foreach ($regions_in_zone as $rn) {
                $sa_reg_p[$rn] += $summary_region_totals[$rn];
            }
        }

        // Add summary row (exclude 24, 25, 26 - these are handled separately)
        if (!in_array((int)$sort_order, [24, 25, 26])) {
            $description = isset($sort_order_descriptions[$sort_order])
                ? $sort_order_descriptions[$sort_order]
                : "Total for Sort Order " . $sort_order;

            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $total_primary['total'],
                'previous_total' => $total_previous['total'],
                'region_totals' => $summary_region_totals
            ];

            if (in_array((int)$sort_order, [22, 23])) {
                $final_table_rows[] = ['is_manual_spacer' => true];
            }
        }

        // TOTAL REVENUES after sort order 20
        if ((int)$sort_order == 20) {
            $final_table_rows[] = [
                'sort_order' => 'TOTAL REVENUES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $rev_tot_p,
                'previous_total' => $rev_tot_prev,
                'region_totals' => $rev_reg_p
            ];

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order' => '',
                'sub_order' => 'Cost of Sales/Service',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true
            ];
        }

        // GROSS PROFIT after sort order 21
        if ((int)$sort_order == 21) {
            $gp_tot_p = $rev_tot_p - $total_primary['total'];
            $gp_tot_prev = $rev_tot_prev - $total_previous['total'];

            $gp_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $gp_reg_p[$rn] = ($rev_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);
            }

            // Add spacer above GROSS PROFIT
            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $gp_tot_p,
                'previous_total' => $gp_tot_prev,
                'region_totals' => $gp_reg_p
            ];

            // Add spacer below GROSS PROFIT
            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order' => 'SELLING & ADMIN EXPENSE',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true
            ];
        }

        // EBITDA after sort order 23
        if ((int)$sort_order == 23) {
            $final_table_rows[] = [
                'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $sa_tot_p,
                'previous_total' => $sa_tot_prev,
                'region_totals' => $sa_reg_p
            ];

            $final_table_rows[] = ['is_manual_spacer' => true];

            // EBITDA
            $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
            $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;

            $ebitda_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $ebitda_reg_p[$rn] = ($gp_reg_p[$rn] ?? 0) - ($sa_reg_p[$rn] ?? 0);
            }

            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebitda_tot_p,
                'previous_total' => $ebitda_tot_prev,
                'region_totals' => $ebitda_reg_p
            ];
        }

        // EBIT after sort order 24
        if ((int)$sort_order == 24) {
            $ebit_tot_p = $ebitda_tot_p - $total_primary['total'];
            $ebit_tot_prev = $ebitda_tot_prev - $total_previous['total'];

            $ebit_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $ebit_reg_p[$rn] = ($ebitda_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);
            }

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
                'region_totals' => $ebit_reg_p
            ];
        }

        // EBT after sort order 25
        if ((int)$sort_order == 25) {
            $ebt_tot_p = $ebit_tot_p - $total_primary['total'];
            $ebt_tot_prev = $ebit_tot_prev - $total_previous['total'];

            $ebt_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $ebt_reg_p[$rn] = ($ebit_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);
            }

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
                'region_totals' => $ebt_reg_p
            ];
        }

        // NET INCOME after sort order 26
        if ((int)$sort_order == 26) {
            $net_tot_p = $ebt_tot_p - $total_primary['total'];
            $net_tot_prev = $ebt_tot_prev - $total_previous['total'];

            $net_reg_p = [];
            foreach ($regions_in_zone as $rn) {
                $net_reg_p[$rn] = ($ebt_reg_p[$rn] ?? 0) - ($summary_region_totals[$rn] ?? 0);
            }

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
                'region_totals' => $net_reg_p
            ];
        }
    }

    return $final_table_rows;
}

// ============================================================
// COMPUTE DATA
// ============================================================
$data_rows = compute_export_rows_for_adjustment(
    $conn,
    $zone,
    $transaction_year,
    $primary_period,
    $previous_period,
    $report_structure,
    $sort_order_descriptions,
    $regions_in_zone,
    true
);

// ============================================================
// SPREADSHEET SETUP
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Consolidated Report');
$sheet->freezePane('E12');

function colLetter(int $col): string
{
    return Coordinate::stringFromColumnIndex($col);
}

// Header layout
$row = 1;
$lastColIdx = 5 + $num_regions;
$lastCol = colLetter($lastColIdx);

// Logo
$logoColIdx = max(1, (int) floor($lastColIdx / 2));
$logoColLetter = colLetter($logoColIdx);

$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $sheet->getRowDimension($row)->setRowHeight(55);
    $drawing = new Drawing();
    $drawing->setPath($logo_path);
    $drawing->setHeight(60);
    $drawing->setCoordinates($logoColLetter . '1');
    $drawing->setWorksheet($sheet);
}
$row++;

// Title
$zone_display = 'All Zones';
if (!empty($zone)) {
    $zone_map = [
        'VIS' => 'VISAYAS',
        'LZN' => 'LUZON',
        'NCR' => 'NCR',
        'MIN' => 'MINDANAO'
    ];
    $zone_display = isset($zone_map[$zone]) ? $zone_map[$zone] : $zone;
}
$sheet->setCellValue("A$row", ($zone ? $zone_display : "All Zones") . " CONSOLIDATED PROFIT & LOSS STATEMENT");
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Branch type header
$branchTitle = 'MLFSI & JEWELERS - PER REGION';
$sheet->setCellValue("A$row", $branchTitle);
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Period display
if (!empty($primary_period)) {
    $ts = strtotime($primary_period . '-01');
    $period_display = "FOR THE MONTH ENDED " . strtoupper(date('F', $ts)) . " " . date('t', $ts) . ", " . date('Y', $ts);
} else {
    $period_display = '(PRIMARY PERIOD)';
}
$sheet->setCellValue("A$row", $period_display);
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Region headers
$row = 8;

$sheet->getStyle("E$row:{$lastCol}$row")->getFont()->setBold(true);
$sheet->getStyle("E$row:{$lastCol}$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("E$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF0000');
$sheet->getStyle("E$row:{$lastCol}$row")->getFont()->getColor()->setARGB(Color::COLOR_WHITE);

for ($i = 0; $i < $num_regions; $i++) {
    $sheet->setCellValue(colLetter(5 + $i) . $row, $regions_in_zone[$i]);
}
$sheet->setCellValue(colLetter(5 + $num_regions) . $row, "GRAND TOTAL");

// Sub-header for amounts
$row++;

$row++;

// REVENUES header
$sheet->setCellValue("A$row", "REVENUES");
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$row++;

// ============================================================
// DATA ROWS
// ============================================================
$highlight_labels = [
    'TOTAL REVENUES',
    'GROSS PROFIT',
    'TOTAL SELLING AND ADMIN EXPENSES',
    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
    'EARNINGS BEFORE INTEREST & TAXES',
    'EARNINGS BEFORE TAXES',
    'TOTAL NET INCOME/LOSS'
];

foreach ($data_rows as $item) {
    if (isset($item['is_manual_spacer'])) {
        $row++;
        continue;
    }

    if (!empty($item['is_section_header'])) {
        $label = $item['sub_order'] ?: $item['sort_order'];
        $sheet->setCellValue("A$row", $label);
        $sheet->mergeCells("A$row:{$lastCol}$row");
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFA973');
        $row++;
        continue;
    }

    $is_sum = $item['is_summary_row'] ?? false;

    if ($is_sum) {
        $label = $item['sort_order'];
        
        // Sort order column
        $sheet->setCellValue("A$row", (is_numeric($label) && (int)$label <= 25) ? '' : $label);
        
        // Description column (B)
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        
        // Amount columns - region_totals is now an array of scalar values
        $colIdx = 5;
        $primary_total = $item['primary_total'] ?? 0;
        
        foreach ($regions_in_zone as $rn) {
            $val = $item['region_totals'][$rn] ?? 0;
            $sheet->setCellValue(colLetter($colIdx) . $row, $val);
            $colIdx++;
        }
        $sheet->setCellValue(colLetter($colIdx) . $row, $primary_total);

        // Bold and background
        $sheet->getStyle("A$row:{$lastCol}$row")->getFont()->setBold(true);
        
        $bg = in_array($label, $highlight_labels, true) 
            ? 'FFFFA973' 
            : (is_numeric($label) && (int)$label % 2 != 0 ? null : 'FFFDE9D9');
        
        if ($bg) {
            $sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        }

        // Borders
        if ($label == '21') {
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }

        if (in_array($label, ["EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES'])) {
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }

        if ($label === 'TOTAL NET INCOME/LOSS') {
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle(colLetter(5) . "$row:{$lastCol}$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        }

    } else {
        // Detail row
        $sheet->setCellValue("C$row", $item['gl_description'] ?? '');
        
        $colIdx = 5;
        $primary_total = $item['primary_total']['total'] ?? 0;
        $is_inj2 = $item['is_inj2'] ?? false;

        foreach ($regions_in_zone as $rn) {
            $val = $item['region_totals'][$rn]['total'] ?? 0;
            if ($is_inj2) $val = -$val;
            $sheet->setCellValue(colLetter($colIdx) . $row, $val);
            $colIdx++;
        }
        
        if ($is_inj2) $primary_total = -$primary_total;
        $sheet->setCellValue(colLetter($colIdx) . $row, $primary_total);

        // Outline for revenues (1-20)
        if (is_numeric($item['sort_order']) && (int)$item['sort_order'] >= 1 && (int)$item['sort_order'] <= 20) {
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
        }
    }

    // Formatting
    $dataRange = colLetter(5) . "$row:{$lastCol}$row";
    $sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // Handle the extra spacer row for revenue categories after formatting the data row
    if ($is_sum && is_numeric($label) && (int)$label >= 1 && (int)$label <= 20) {
        $row++;
        $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
    }

    // Conditional formatting for negative values
    $conditional = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
    $conditional->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS)
                ->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_LESSTHAN)
                ->addCondition('0');
    $conditional->getStyle()->getFont()->getColor()->setARGB(Color::COLOR_RED);
    $sheet->getStyle($dataRange)->setConditionalStyles([$conditional]);

    $row++;
}

// ============================================================
// COLUMN WIDTHS
// ============================================================
$sheet->getColumnDimension('A')->setWidth(2);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(30);
$sheet->getColumnDimension('D')->setWidth(2);

for ($i = 5; $i <= $lastColIdx; $i++) {
    $sheet->getColumnDimension(colLetter($i))->setAutoSize(true);
}

// ============================================================
// OUTPUT
// ============================================================
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Consolidated_With_Adjustment_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
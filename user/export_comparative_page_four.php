<?php
// export_comparative_three.php
// Fixed: three tables written SIDE-BY-SIDE with aligned rows
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

// Get distinct mainzones
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

// ============================================================
// GET GL MAPPING
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

function get_head_office_manual_adjustment(mysqli $conn, string $mainzone, string $selected_period): float {
    if (empty($selected_period)) {
        return 0.0;
    }

    $transaction_month = $selected_period . '-01';

    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
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

    $head_office_26_1 = 0.0;
    if ($use_real_data && !empty($selected_period)) {
        $head_office_26_1 = get_head_office_manual_adjustment($conn, $mainzone, $selected_period);
    }

    $table_rows = [];

    foreach ($gl_mapping as $key => $codes) {
        [$sort_order, $sub_order] = explode('|', $key);

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
        
        $head_office = 0.0;
        if ((string)$sort_order === '26' && (string)$sub_order === '1') {
            $head_office = $head_office_26_1;
        }
        
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

        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;

        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
            $rev_mlfsi += $total_period_mlfsi;
            $rev_jew += $total_period_jewelers;
            $rev_tot += $group_total_with_head;
            $rev_head += $total_head_office;
        }
        
        if ((int)$sort_order == 21) {
            $cos_mlfsi = $total_period_mlfsi;
            $cos_jew = $total_period_jewelers;
            $cos_tot = $group_total_with_head;
            $cos_head = $total_head_office;
        }
        
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
            $dep_mlfsi = $total_period_mlfsi;
            $dep_jew = $total_period_jewelers;
            $dep_tot = $group_total_with_head;
            $dep_head = $total_head_office;
            
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
            $interest_mlfsi = $total_period_mlfsi;
            $interest_jew = $total_period_jewelers;
            $interest_tot = $group_total_with_head;
            $interest_head = $total_head_office;
            
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
            $tax_mlfsi = $total_period_mlfsi;
            $tax_jew = $total_period_jewelers;
            $tax_tot = $group_total_with_head;
            $tax_head = $total_head_office;
            
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
        $valid_filters
    ),
];

$specific_mainzones = array_slice($distinct_mainzones, 0, 2);

if (count($specific_mainzones) < 2) {
    $defaults = ['LNCR', 'VISMIN'];
    foreach ($defaults as $default) {
        if (!in_array($default, $specific_mainzones, true)) {
            $specific_mainzones[] = $default;
        }
    }
    $specific_mainzones = array_slice($specific_mainzones, 0, 2);
}

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

// Define column ranges for each table (10 data columns each)
// Table 0 (NATIONWIDE): cols 1-10  (A-J)
// Table 1 (LNCR)      : cols 13-22 (M-V)
// Table 2 (VISMIN)    : cols 25-34 (Y-AH)
$table_ranges = [
    0 => ['start_num' => 1,  'end_num' => 10],
    1 => ['start_num' => 13, 'end_num' => 22],
    2 => ['start_num' => 25, 'end_num' => 34],
];

// Set column widths
foreach ($table_ranges as $range) {
    for ($i = $range['start_num']; $i <= $range['end_num']; $i++) {
        $col = Coordinate::stringFromColumnIndex($i);
        // First column narrower, others standard
        $sheet->getColumnDimension($col)->setWidth($i === $range['start_num'] ? 6 : 13);
    }
}
// Spacer columns
$sheet->getColumnDimension('K')->setWidth(2);
$sheet->getColumnDimension('L')->setWidth(2);
$sheet->getColumnDimension('W')->setWidth(2);
$sheet->getColumnDimension('X')->setWidth(2);

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

// Row 1: Logo (only on first table)
$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setDescription('MLhuillier Logo');
    $drawing->setPath($logo_path);
    $drawing->setHeight(60);
    $drawing->setCoordinates('A1');
    $drawing->setWorksheet($sheet);
}

// Row 2: Table titles (side-by-side)
foreach ($table_ranges as $index => $range) {
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    
    $sheet->mergeCells($start_col . '2:' . $end_col . '2');
    $sheet->setCellValue($start_col . '2', $titles[$index] ?? '');
    $sheet->getStyle($start_col . '2')->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ad1111']],
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
        $period_display = 'FOR THE MONTH ENDED ' . strtoupper($date->format('F d, Y'));
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
$headers = ['', '', '', '', 'MLFSI', 'JEWELERS', 'HEAD OFFICE', '', 'TOTAL', '%'];

foreach ($table_ranges as $index => $range) {
    $start_num = $range['start_num'];
    for ($i = 0; $i < 10; $i++) {
        $col = Coordinate::stringFromColumnIndex($start_num + $i);
        $sheet->setCellValue($col . '7', $headers[$i]);
    }
    
    $start_col = Coordinate::stringFromColumnIndex($range['start_num']);
    $end_col   = Coordinate::stringFromColumnIndex($range['end_num']);
    $sheet->getStyle($start_col . '7:' . $end_col . '7')->applyFromArray([
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF170F']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);
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
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF7F29']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]
    ]);
}
$current_row++;

// Now write every logical data row across all three tables on the SAME Excel row
for ($r = 0; $r < $max_data_rows; $r++) {

    foreach ($tables as $table_index => $table) {
        $range     = $table_ranges[$table_index];
        $start_num = $range['start_num'];
        $total_revenues = $total_revenues_by_table[$table_index] ?? 1;

        // If this table has fewer rows, leave the cells empty for this row
        if (!isset($table['rows'][$r])) {
            continue;
        }

        $row = $table['rows'][$r];

        // Manual spacer
        if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
            // leave blank (already empty)
            continue;
        }

        $is_summary_row = isset($row['is_summary_row']) && $row['is_summary_row'] === true;
        $is_header      = !empty($row['is_section_header']);

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

        // Col 0: sort_order (only on summary)
        $col1 = Coordinate::stringFromColumnIndex($start_num);
        $sheet->setCellValue($col1 . $current_row, $is_summary_row ? ($row['sort_order'] ?? '') : '');

        // Col 1: sub_order / description
        $col2 = Coordinate::stringFromColumnIndex($start_num + 1);
        if ($is_header) {
            $sheet->setCellValue($col2 . $current_row, $row['sub_order'] ?? '');
        } elseif ($is_summary_row) {
            $sheet->setCellValue($col2 . $current_row, $row['gl_description'] ?? '');
        }

        // Col 2: gl_description (detail rows only)
        $col3 = Coordinate::stringFromColumnIndex($start_num + 2);
        if (!$is_summary_row) {
            $sheet->setCellValue($col3 . $current_row, $row['gl_description'] ?? '');
        }

        // Col 3: empty

        // Col 4: MLFSI
        $col5 = Coordinate::stringFromColumnIndex($start_num + 4);
        if (!$is_header) {
            $sheet->setCellValue($col5 . $current_row, $period_mlfsi);
            $sheet->getStyle($col5 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($period_mlfsi < 0) {
                $sheet->getStyle($col5 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // Col 5: JEWELERS
        $col6 = Coordinate::stringFromColumnIndex($start_num + 5);
        if (!$is_header) {
            $sheet->setCellValue($col6 . $current_row, $period_jewelers);
            $sheet->getStyle($col6 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($period_jewelers < 0) {
                $sheet->getStyle($col6 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // Col 6: HEAD OFFICE
        $col7 = Coordinate::stringFromColumnIndex($start_num + 6);
        if (!$is_header) {
            $sheet->setCellValue($col7 . $current_row, $head_office);
            $sheet->getStyle($col7 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($head_office < 0) {
                $sheet->getStyle($col7 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // Col 7: empty

        // Col 8: TOTAL
        $col9 = Coordinate::stringFromColumnIndex($start_num + 8);
        if (!$is_header) {
            $sheet->setCellValue($col9 . $current_row, $period_total);
            $sheet->getStyle($col9 . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
            if ($period_total < 0) {
                $sheet->getStyle($col9 . $current_row)->getFont()->setColor(new Color('FF0000'));
            }
        }

        // Col 9: %
        $col10 = Coordinate::stringFromColumnIndex($start_num + 9);
        if (!$is_header) {
            $sheet->setCellValue($col10 . $current_row, $percentage);
            $sheet->getStyle($col10 . $current_row)->getNumberFormat()->setFormatCode('0.00');
        }

        // Styling
        $start_col = Coordinate::stringFromColumnIndex($start_num);
        $end_col   = Coordinate::stringFromColumnIndex($start_num + 9);

        if ($is_summary_row) {
            $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);

            // Highlight TOTAL REVENUES
            if (isset($row['sort_order']) && $row['sort_order'] === 'TOTAL REVENUES') {
                $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']]
                ]);
            }
        } elseif (!$is_header) {
            $sheet->getStyle($start_col . $current_row . ':' . $end_col . $current_row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);
        }
    }

    // After writing all three tables for this logical row, move to next Excel row
    $current_row++;
}

// Set print area / freeze panes for convenience
$sheet->freezePane('A9');

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Profit_Loss_Statement_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;
?>
<?php
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

$zone = $_GET['zone'] ?? '';
$transaction_type = $_GET['transaction_type'] ?? '';
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';

// Fetch regions for the zone
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

// GL Mapping - Determine which table to use based on gl_code_mode
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];

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
        if ($row['gl_id'] === 'INJ-2') $special_keys[] = $key;
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [];
            $gl_descriptions[$key] = $row['gl_description_comparative'];
        }
        $resolved = trim((string)($row['gl_code'] ?? ''));
        if ($resolved !== '' && !in_array($resolved, $gl_mapping[$key], true)) {
            $gl_mapping[$key][] = $resolved;
        }
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

function compute_export_rows(
    mysqli $conn,
    string $zone,
    string $transaction_type,
    string $transaction_year,
    string $primary_period,
    array $gl_mapping,
    array $gl_descriptions,
    array $special_keys,
    array $sort_order_descriptions,
    array $regions_in_zone
): array {
    $where = ["(status_void IS NULL OR status_void != 'Void')"];
    $params = [];
    $types = "";

    if (!empty($zone)) { $where[] = "zone = ?"; $params[] = $zone; $types .= "s"; }
    if (!empty($transaction_type)) { $where[] = "transaction_type = ?"; $params[] = $transaction_type; $types .= "s"; }
    if (!empty($transaction_year)) { $where[] = "transaction_year = ?"; $params[] = $transaction_year; $types .= "s"; }

    $base_where = "WHERE " . implode(" AND ", $where);
    $primary_data = [];
    if (!empty($primary_period)) {
        $p_parts = explode('-', $primary_period);
        $p_year = $p_parts[0];
        $p_month_val = $primary_period . '-01';
        $sql = "SELECT gl_code, region, SUM(amount) as total_amount FROM fs_reports.comparative_report $base_where AND transaction_year = ? AND transaction_month = ? AND gl_code IS NOT NULL AND gl_code != '' GROUP BY gl_code, region";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            $p_bind = array_merge($params, [$p_year, $p_month_val]);
            mysqli_stmt_bind_param($stmt, $types . "ss", ...$p_bind);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) { $primary_data[$row['gl_code']][$row['region']] = floatval($row['total_amount']); }
            mysqli_stmt_close($stmt);
        }
    }

    $table_rows = [];
    foreach ($gl_mapping as $key => $gl_codes) {
        [$sort_order, $sub_order] = explode('|', $key);
        $row_reg_totals = array_fill_keys($regions_in_zone, 0);
        $primary_total = 0;
        foreach ($gl_codes as $code) {
            if (isset($primary_data[$code])) {
                foreach ($primary_data[$code] as $rn => $amt) {
                    if (isset($row_reg_totals[$rn])) { $row_reg_totals[$rn] += $amt; $primary_total += $amt; }
                }
            }
        }
        $table_rows[] = ['sort_order' => $sort_order, 'sub_order' => $sub_order, 'gl_description' => $gl_descriptions[$key], 'is_section_header' => false, 'is_summary_row' => false, 'primary_total' => $primary_total, 'region_totals' => $row_reg_totals, 'is_inj2' => in_array($key, $special_keys)];
    }

    $grouped = [];
    foreach ($table_rows as $row) { $grouped[$row['sort_order']][] = $row; }

    $final = [];
    $rev_tot = 0; $sa_tot = 0; $gp_tot = 0; $ebitda_tot = 0; $ebit_tot = 0; $ebt_tot = 0;
    $rev_reg = array_fill_keys($regions_in_zone, 0);
    $sa_reg = array_fill_keys($regions_in_zone, 0);
    $gp_reg = array_fill_keys($regions_in_zone, 0);
    $ebitda_reg = array_fill_keys($regions_in_zone, 0);
    $ebit_reg = array_fill_keys($regions_in_zone, 0);
    $ebt_reg = array_fill_keys($regions_in_zone, 0);

    foreach ($grouped as $so => $rows) {
        if (!in_array((int)$so, [6, 8, 11])) {
            foreach ($rows as $row) { $final[] = $row; }
        }
        $total_p = array_sum(array_column($rows, 'primary_total'));
        $sum_reg = array_fill_keys($regions_in_zone, 0);
        foreach ($rows as $row) { foreach ($regions_in_zone as $rn) $sum_reg[$rn] += $row['region_totals'][$rn]; }

        if ((int)$so >= 1 && (int)$so <= 20) {
            $rev_tot += $total_p;
            foreach ($regions_in_zone as $rn) $rev_reg[$rn] += $sum_reg[$rn];
        }
        if ((int)$so == 22 || (int)$so == 23) {
            $sa_tot += $total_p;
            foreach ($regions_in_zone as $rn) $sa_reg[$rn] += $sum_reg[$rn];
        }

        if (!in_array((int)$so, [24, 25, 26])) {
            $final[] = ['sort_order' => $so, 'sub_order' => '', 'gl_description' => $sort_order_descriptions[$so] ?? "Total $so", 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $total_p, 'region_totals' => $sum_reg];
        }
        
        if (in_array((int)$so, [22, 23])) {
            $final[] = ['is_manual_spacer' => true];
        }

        if ((int)$so == 20) {
            $final[] = ['sort_order' => 'TOTAL REVENUES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $rev_tot, 'region_totals' => $rev_reg];
            $final[] = ['is_manual_spacer' => true];
            $final[] = ['sort_order' => '', 'sub_order' => 'Cost of Sales/Service', 'gl_description' => '', 'is_section_header' => true, 'is_summary_row' => true];
        }
        if ((int)$so == 21) {
            $gp_tot = $rev_tot - $total_p;
            foreach ($regions_in_zone as $rn) $gp_reg[$rn] = $rev_reg[$rn] - $sum_reg[$rn];
            $final[] = ['is_manual_spacer' => true];
            $final[] = ['sort_order' => 'GROSS PROFIT', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $gp_tot, 'region_totals' => $gp_reg];
            $final[] = ['is_manual_spacer' => true];
            $final[] = ['sort_order' => 'SELLING & ADMIN EXPENSE', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => true, 'is_summary_row' => true];
        }
        if ((int)$so == 23) {
            $final[] = ['sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $sa_tot, 'region_totals' => $sa_reg];
            $final[] = ['is_manual_spacer' => true];
            $ebitda_tot = $gp_tot - $sa_tot;
            foreach ($regions_in_zone as $rn) $ebitda_reg[$rn] = $gp_reg[$rn] - $sa_reg[$rn];
            $final[] = ['sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $ebitda_tot, 'region_totals' => $ebitda_reg];
        }
        if ((int)$so == 24) {
            $ebit_tot = $ebitda_tot - $total_p;
            foreach ($regions_in_zone as $rn) $ebit_reg[$rn] = $ebitda_reg[$rn] - $sum_reg[$rn];
            $final[] = ['is_manual_spacer' => true];
            $final[] = ['sort_order' => 'EARNINGS BEFORE INTEREST & TAXES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $ebit_tot, 'region_totals' => $ebit_reg];
        }
        if ((int)$so == 25) {
            $ebt_tot = $ebit_tot - $total_p;
            foreach ($regions_in_zone as $rn) $ebt_reg[$rn] = $ebit_reg[$rn] - $sum_reg[$rn];
            $final[] = ['is_manual_spacer' => true];
            $final[] = ['sort_order' => 'EARNINGS BEFORE TAXES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $ebt_tot, 'region_totals' => $ebt_reg];
        }
        if ((int)$so == 26) {
            $net_tot = $ebt_tot - $total_p;
            $net_reg = [];
            foreach ($regions_in_zone as $rn) $net_reg[$rn] = $ebt_reg[$rn] - $sum_reg[$rn];
            $final[] = ['is_manual_spacer' => true];
            $final[] = ['sort_order' => 'TOTAL NET INCOME/LOSS', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $net_tot, 'region_totals' => $net_reg];
        }
    }
    return $final;
}

$data_rows = compute_export_rows($conn, $zone, $transaction_type, $transaction_year, $primary_period, $gl_mapping, $gl_descriptions, $special_keys, $sort_order_descriptions, $regions_in_zone);

// Spreadsheet Setup
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Consolidated Report');
$sheet->freezePane('E9');

function colLetter(int $col): string
{
    return Coordinate::stringFromColumnIndex($col);
}

// Header layout
$row = 1;
$lastColIdx = 5 + $num_regions;
$lastCol = colLetter($lastColIdx);

// Calculate dynamic center column for the logo
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

// Row 3: Branch type header
$branchTitle = 'MLFSI & JEWELERS - PER REGION';
if ($transaction_type === 'Branch') {
    $branchTitle = 'MLFSI - PER REGION';
} elseif ($transaction_type === 'Showroom') {
    $branchTitle = 'JEWELERS - PER REGION';
}
$sheet->setCellValue("A$row", $branchTitle);
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

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

$row = 8;

$sheet->getStyle("E$row:{$lastCol}$row")->getFont()->setBold(true);
$sheet->getStyle("E$row:{$lastCol}$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("E$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF0000');
$sheet->getStyle("E$row:{$lastCol}$row")->getFont()->getColor()->setARGB(Color::COLOR_WHITE);

for ($i = 0; $i < $num_regions; $i++) {
    $sheet->setCellValue(colLetter(5 + $i) . $row, $regions_in_zone[$i] );
}
$sheet->setCellValue(colLetter(5 + $num_regions) . $row, "GRAND TOTAL");

$row = 10;
$sheet->setCellValue("A$row", "REVENUES");
$sheet->mergeCells("A$row:{$lastCol}$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$row++;

// Data Rows
$highlight_labels = ['TOTAL REVENUES', 'GROSS PROFIT', 'TOTAL SELLING AND ADMIN EXPENSES', "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES', 'TOTAL NET INCOME/LOSS'];

foreach ($data_rows as $item) {
    if (isset($item['is_manual_spacer'])) { $row++; continue; }
    
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
        $sheet->setCellValue("A$row", (is_numeric($label) && (int)$label <= 25) ? '' : $label);
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        
        $colIdx = 5;
        foreach ($regions_in_zone as $rn) {
            $sheet->setCellValue(colLetter($colIdx) . $row, $item['region_totals'][$rn]);
            $colIdx++;
        }
        $sheet->setCellValue(colLetter($colIdx) . $row, $item['primary_total']);
        
        $sheet->getStyle("A$row:{$lastCol}$row")->getFont()->setBold(true);
        $bg = in_array($label, $highlight_labels, true) ? 'FFFFA973' : (is_numeric($label) && (int)$label % 2 != 0 ? null : 'FFFDE9D9');
        if ($bg) $sheet->getStyle("A$row:{$lastCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        
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
        $sheet->setCellValue("C$row", $item['gl_description'] ?? '');
        $colIdx = 5;
        foreach ($regions_in_zone as $rn) {
            $val = $item['region_totals'][$rn];
            if ($item['is_inj2']) $val = -$val;
            $sheet->setCellValue(colLetter($colIdx) . $row, $val);
            $colIdx++;
        }
        $tot = $item['primary_total'];
        if ($item['is_inj2']) $tot = -$tot;
        $sheet->setCellValue(colLetter($colIdx) . $row, $tot);
        
        if (is_numeric($item['sort_order']) && (int)$item['sort_order'] >= 1 && (int)$item['sort_order'] <= 20) {
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
        }
    }
    
    // Formatting for the row
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

// Auto-size columns
$sheet->getColumnDimension('A')->setWidth(2);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(30);
$sheet->getColumnDimension('D')->setWidth(2);

// Auto-size E onwards
for ($i = 5; $i <= $lastColIdx; $i++) {
    $sheet->getColumnDimension(colLetter($i))->setAutoSize(true);
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Consolidated_Report_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
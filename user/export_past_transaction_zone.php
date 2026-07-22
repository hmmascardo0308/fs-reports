<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

if (!isset($_SESSION['username'])) {
    die("Unauthorized access");
}

$selected_month = $_GET['transaction_month'] ?? '';
if (!$selected_month) {
    die("Month is required");
}

// Format the period date for display
$period_date = date_create($selected_month . '-01');
$formatted_month = $period_date->format('F Y');
$last_day = date('t', strtotime($selected_month . '-01'));
$period_text = "FOR THE MONTH ENDED " . strtoupper(date('F', strtotime($selected_month . '-01'))) . " $last_day, " . date('Y', strtotime($selected_month . '-01'));

// 1. Fetch distinct regions and zones to identify showrooms and regions per zone
$regions_query = "SELECT DISTINCT region, zone FROM fs_reports.past_transaction WHERE region != ''";
$regions_result = $conn->query($regions_query);
$regions_by_zone = ['LZN' => [], 'NCR' => [], 'VIS' => [], 'MIN' => []];
$showrooms = ['LNCR Showroom' => false, 'VISMIN Showroom' => false];

if ($regions_result) {
    while ($r = $regions_result->fetch_assoc()) {
        $reg = $r['region'];
        $zn = $r['zone'];
        if ($reg == 'LNCR Showroom') {
            $showrooms['LNCR Showroom'] = true;
        } elseif ($reg == 'VISMIN Showroom') {
            $showrooms['VISMIN Showroom'] = true;
        } elseif (isset($regions_by_zone[$zn])) {
            $regions_by_zone[$zn][] = $reg;
        }
    }
}

// 2. Fetch Data Amounts
$past_amounts = [];
if ($selected_month) {
    $data_sql = "SELECT region, sort_order, sub_order, SUM(amount) as total 
                 FROM fs_reports.past_transaction 
                 WHERE DATE_FORMAT(transaction_month, '%Y-%m') = ? 
                 GROUP BY region, sort_order, sub_order";
    $stmt = $conn->prepare($data_sql);
    $stmt->bind_param("s", $selected_month);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $past_amounts[$r['sort_order']][$r['sub_order']][$r['region']] = (float)$r['total'];
    }
    $stmt->close();
}

// 3. Fetch GL Structure
$gl_sql = "SELECT sort_order, description, sub_order, gl_description_comparative 
           FROM gl_codes_past_tranx 
           ORDER BY sort_order ASC, sub_order ASC";
$gl_result = $conn->query($gl_sql);
$groupedTransactions = [];
if ($gl_result && $gl_result->num_rows > 0) {
    while ($row = $gl_result->fetch_assoc()) {
        $key = $row['sort_order'] . '||' . $row['description'];
        $groupedTransactions[$key][] = $row;
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Zone Totals');
$sheet->freezePane('D7');

// --- Styles ---
$centerBold = ['font' => ['bold' => true], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
$totalsRowStyle = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAF8C']], 'font' => ['bold' => true, 'color' => ['rgb' => '000000']]];
$borderAll = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

// Setup Columns
$headerRow = 5;
$regionRow = $headerRow + 1;
$col = 4;
$columnMap = [];

// Map mandatory zone/total columns
$columnMap['LUZON'] = $col++;
$columnMap['NCR'] = $col++;
if ($showrooms['LNCR Showroom']) $columnMap['LNCR Showroom'] = $col++;
$columnMap['VISAYAS'] = $col++;
$columnMap['MINDANAO'] = $col++;
if ($showrooms['VISMIN Showroom']) $columnMap['VISMIN Showroom'] = $col++;

$columnMap['LNCR'] = $col++;
$columnMap['ALL LNCR'] = $col++;
$columnMap['VISMIN'] = $col++;
$columnMap['ALL VISMIN'] = $col++;
$columnMap['MLFSI'] = $col++;
$columnMap['JEWELERS'] = $col++;
$columnMap['NATIONWIDE'] = $col++;

$lastCol = Coordinate::stringFromColumnIndex($col - 1);

// Column headers (Row 6)
foreach ($columnMap as $label => $cIdx) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $regionRow, $label);
}

// Company and Report Info (Rows 1-4)
$sheet->mergeCells("A1:C1");

$richText = new RichText();

// Red: "M Lhuillier "
$redText = $richText->createTextRun("M Lhuillier ");
$redText->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FF0000');

// Black: "Financial Services, Inc."
$blackText = $richText->createTextRun("Financial Services, Inc.");
$blackText->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('000000');

$sheet->setCellValue("A1", $richText);

$sheet->mergeCells("A2:C2");
$sheet->setCellValue("A2", "PROFIT AND LOSS STATEMENT (ZONE TOTALS)");
$sheet->getStyle("A2")->getFont()->setBold(true)->setSize(11);

$sheet->mergeCells("A3:C3");
$sheet->setCellValue("A3", $period_text);
$sheet->getStyle("A3")->getFont()->setBold(true)->setSize(10);

// Main Header Groupings (Row 5)
$lncrEndCol = $columnMap['NCR'] + ($showrooms['LNCR Showroom'] ? 1 : 0);
$sheet->mergeCells("D{$headerRow}:" . Coordinate::stringFromColumnIndex($lncrEndCol) . $headerRow);
$sheet->setCellValue("D{$headerRow}", "LUZON & NCR");
$sheet->getStyle("D{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AE0000');
$sheet->getStyle("D{$headerRow}")->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_WHITE);

$visminStartCol = $lncrEndCol + 1;
$visminEndCol = $columnMap['MINDANAO'] + ($showrooms['VISMIN Showroom'] ? 1 : 0);
$sheet->mergeCells(Coordinate::stringFromColumnIndex($visminStartCol) . $headerRow . ":" . Coordinate::stringFromColumnIndex($visminEndCol) . $headerRow);
$sheet->setCellValue(Coordinate::stringFromColumnIndex($visminStartCol) . $headerRow, "VISAYAS & MINDANAO");
$sheet->getStyle(Coordinate::stringFromColumnIndex($visminStartCol) . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('630000');
$sheet->getStyle(Coordinate::stringFromColumnIndex($visminStartCol) . $headerRow)->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_WHITE);

$totalsStartCol = $visminEndCol + 1;
$sheet->mergeCells(Coordinate::stringFromColumnIndex($totalsStartCol) . $headerRow . ":" . $lastCol . $headerRow);
$sheet->setCellValue(Coordinate::stringFromColumnIndex($totalsStartCol) . $headerRow, "TOTALS");
$sheet->getStyle(Coordinate::stringFromColumnIndex($totalsStartCol) . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF8030');

// Apply formatting to headers
$sheet->getStyle("A{$headerRow}:{$lastCol}{$regionRow}")->applyFromArray($centerBold);

// Initialize overall totals
$initTotals = function() {
    return ['lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0, 'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0];
};

$revenue_overall = $initTotals();
$cost_overall = $initTotals();
$selling_admin_overall = $initTotals();
$operating_overall = $initTotals();
$interest_overall = $initTotals();
$tax_overall = $initTotals();

$writeTotalsToRow = function($row, $data) use ($sheet, $columnMap, $showrooms) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['LUZON']) . $row, $data['lzn']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['NCR']) . $row, $data['ncr']);
    if($showrooms['LNCR Showroom']) $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['LNCR Showroom']) . $row, $data['lncr_sh']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['VISAYAS']) . $row, $data['vis']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['MINDANAO']) . $row, $data['min']);
    if($showrooms['VISMIN Showroom']) $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['VISMIN Showroom']) . $row, $data['vismin_sh']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['LNCR']) . $row, $data['lncr']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['ALL LNCR']) . $row, $data['all_lncr']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['VISMIN']) . $row, $data['vismin']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['ALL VISMIN']) . $row, $data['all_vismin']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['MLFSI']) . $row, $data['mlfsi']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['JEWELERS']) . $row, $data['jewelers']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnMap['NATIONWIDE']) . $row, $data['nationwide']);
};

$rowNum = $regionRow + 1;
$sheet->setCellValue("A{$rowNum}", "REVENUES");
$sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAF8C']], 'font' => ['bold' => true]]);
$rowNum++;

foreach ($groupedTransactions as $group_key => $rows) {
    $parts = explode('||', $group_key);
    $s = (int)$parts[0];
    $desc = $parts[1];
    $hide_sub_rows = in_array($s, [6, 8, 11], true);
    $group_t = $initTotals();

    foreach ($rows as $tx) {
        $sub = (int)$tx['sub_order'];
        $sign = ($s === 15 && $sub === 2) ? -1 : 1;
        
        $z = ['lzn' => 0, 'ncr' => 0, 'vis' => 0, 'min' => 0, 'lncr_sh' => 0, 'vismin_sh' => 0];
        foreach ($regions_by_zone['LZN'] as $r) $z['lzn'] += $past_amounts[$s][$sub][$r] ?? 0;
        foreach ($regions_by_zone['NCR'] as $r) $z['ncr'] += $past_amounts[$s][$sub][$r] ?? 0;
        foreach ($regions_by_zone['VIS'] as $r) $z['vis'] += $past_amounts[$s][$sub][$r] ?? 0;
        foreach ($regions_by_zone['MIN'] as $r) $z['min'] += $past_amounts[$s][$sub][$r] ?? 0;
        if ($showrooms['LNCR Showroom']) $z['lncr_sh'] = $past_amounts[$s][$sub]['LNCR Showroom'] ?? 0;
        if ($showrooms['VISMIN Showroom']) $z['vismin_sh'] = $past_amounts[$s][$sub]['VISMIN Showroom'] ?? 0;
        
        $calc = [];
        $calc['lncr'] = $z['lzn'] + $z['ncr'];
        $calc['all_lncr'] = $calc['lncr'] + $z['lncr_sh'];
        $calc['vismin'] = $z['vis'] + $z['min'];
        $calc['all_vismin'] = $calc['vismin'] + $z['vismin_sh'];
        $calc['mlfsi'] = $calc['lncr'] + $calc['vismin'];
        $calc['jewelers'] = $z['lncr_sh'] + $z['vismin_sh'];
        $calc['nationwide'] = $calc['mlfsi'] + $calc['jewelers'];

        // Accumulate to group totals
        foreach ($z as $k => $v) $group_t[$k] += $sign * $v;
        foreach ($calc as $k => $v) $group_t[$k] += $sign * $v;
        
        if (!$hide_sub_rows) {
            $sheet->setCellValue('C' . $rowNum, $tx['gl_description_comparative']);
            $writeTotalsToRow($rowNum, array_merge($z, $calc));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($columnMap['LUZON']) . $rowNum . ':' . $lastCol . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($s >= 1 && $s <= 20) $sheet->getRowDimension($rowNum)->setOutlineLevel(1)->setVisible(false);
            $rowNum++;
        }
    }
    
    if (!in_array($s, [24, 25, 26], true)) {
        $sheet->setCellValue('B' . $rowNum, $desc);
        $writeTotalsToRow($rowNum, $group_t);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->getFont()->setBold(true);
        $sheet->getStyle("D$rowNum:{$lastCol}$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($s >= 1 && $s <= 20) $sheet->getRowDimension($rowNum)->setCollapsed(true);
        $rowNum++;
    }
    
    $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
    $sheet->getStyle("A$rowNum")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E6E6E6');
    if ($s >= 1 && $s <= 20) $sheet->getRowDimension($rowNum)->setOutlineLevel(1)->setVisible(false);
    $rowNum++;
    
    if ($s >= 1 && $s <= 20) foreach($revenue_overall as $k => &$v) $v += $group_t[$k];
    elseif ($s == 21) foreach($cost_overall as $k => &$v) $v += $group_t[$k];
    elseif ($s == 22 || $s == 23) foreach($selling_admin_overall as $k => &$v) $v += $group_t[$k];
    elseif ($s == 24) foreach($operating_overall as $k => &$v) $v += $group_t[$k];
    elseif ($s == 25) foreach($interest_overall as $k => &$v) $v += $group_t[$k];
    elseif ($s == 26) foreach($tax_overall as $k => &$v) $v += $group_t[$k];
    
    if ($s == 20) {
        $sheet->setCellValue("A$rowNum", "TOTAL REVENUES");
        $writeTotalsToRow($rowNum, $revenue_overall);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum += 2;
        $sheet->setCellValue("A$rowNum", "COST OF SALES/SERVICE");
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum++;
    } elseif ($s == 21) {
        $gp = $initTotals(); foreach ($gp as $k => &$v) $v = $revenue_overall[$k] - $cost_overall[$k];
        $sheet->setCellValue("A$rowNum", "GROSS PROFIT");
        $writeTotalsToRow($rowNum, $gp);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum += 2;
        $sheet->setCellValue("A$rowNum", "SELLING & ADMIN EXPENSES");
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum++;
    } elseif ($s == 23) {
        $sheet->setCellValue("A$rowNum", "TOTAL SELLING AND ADMIN EXPENSES");
        $writeTotalsToRow($rowNum, $selling_admin_overall);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum += 2;
        $ebitda = $initTotals(); foreach ($ebitda as $k => &$v) $v = $revenue_overall[$k] - $cost_overall[$k] - $selling_admin_overall[$k];
        $sheet->setCellValue("A$rowNum", "EBITDA");
        $writeTotalsToRow($rowNum, $ebitda);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum++;
    } elseif ($s == 24) {
        $ebit = $initTotals(); foreach ($ebit as $k => &$v) $v = $revenue_overall[$k] - $cost_overall[$k] - $selling_admin_overall[$k] - $operating_overall[$k];
        $sheet->setCellValue("A$rowNum", "EBIT");
        $writeTotalsToRow($rowNum, $ebit);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum++;
    } elseif ($s == 25) {
        $ebt = $initTotals(); foreach ($ebt as $k => &$v) $v = $revenue_overall[$k] - $cost_overall[$k] - $selling_admin_overall[$k] - $operating_overall[$k] - $interest_overall[$k];
        $sheet->setCellValue("A$rowNum", "EBT");
        $writeTotalsToRow($rowNum, $ebt);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum++;
    } elseif ($s == 26) {
        $net = $initTotals(); foreach ($net as $k => &$v) $v = $revenue_overall[$k] - $cost_overall[$k] - $selling_admin_overall[$k] - $operating_overall[$k] - $interest_overall[$k] - $tax_overall[$k];
        $sheet->setCellValue("A$rowNum", "NET INCOME/LOSS");
        $writeTotalsToRow($rowNum, $net);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $rowNum++;
    }
}

// Apply borders and number formatting
$sheet->getStyle("D5:{$lastCol}" . ($rowNum - 1))->applyFromArray($borderAll);
// Apply specific borders for NET INCOME/LOSS row
$sheet->getStyle("D" . ($rowNum - 1) . ":{$lastCol}" . ($rowNum - 1))->applyFromArray([
    'borders' => [
        'top' => ['borderStyle' => Border::BORDER_THIN],
        'bottom' => ['borderStyle' => Border::BORDER_DOUBLE],
    ],
]);

$sheet->getStyle("D2:{$lastCol}" . ($rowNum - 1))->getNumberFormat()->setFormatCode('#,##0.00');

// Conditional Red for Negative Values
$negativeCondition = new Conditional();
$negativeCondition->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_LESSTHAN)->addCondition('0');
$negativeCondition->getStyle()->getFont()->getColor()->setARGB(Color::COLOR_RED);
$sheet->getStyle("D2:{$lastCol}" . ($rowNum - 1))->setConditionalStyles([$negativeCondition]);

// Auto-size columns
for ($c = 4; $colIdx = Coordinate::stringFromColumnIndex($c); $c++) {
    if ($c > Coordinate::columnIndexFromString($lastCol)) break;
    $sheet->getColumnDimension($colIdx)->setAutoSize(true);
}
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(30);
$sheet->getColumnDimension('C')->setWidth(45);

// Output
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Past_Transactions_Zone_Summary_' . $selected_month . '.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
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

// 1. Fetch distinct regions and zones
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
foreach ($regions_by_zone as &$regs) sort($regs);

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
$sheet->setTitle('Past Transactions');
$sheet->freezePane('D7');

// --- Styles ---
$centerBold = ['font' => ['bold' => true], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];


$rightStyle = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]];
$orangeFill = [
    'font' => ['bold' => true]
];
$totalsRowStyle = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAF8C']], 'font' => ['bold' => true, 'color' => ['rgb' => '000000']]];
$subtotalColor = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFAF0']], 'font' => ['bold' => true]];
$borderAll = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

// Setup Columns mapping - STORE column indexes (Row 6 for region names)
$headerRow = 5; // Main headers start at row 5
$regionRow = $headerRow + 1; // Row 6 for region names

$col = 1;
$sheet->setCellValue('A' . $headerRow, '');
$sheet->setCellValue('B' . $headerRow, '');
$sheet->setCellValue('C' . $headerRow, '');
$col = 4;
$regionMap = [];
$regionToZoneMap = [];

// Build region to zone mapping
foreach ($regions_by_zone as $zone => $regions) {
    foreach ($regions as $region) {
        $regionToZoneMap[$region] = $zone;
    }
}

// Header Row 1 colspans (Row 5 is now the header row for regions)
$lncrColCount = count($regions_by_zone['LZN']) + 1 + count($regions_by_zone['NCR']) + 1 + ($showrooms['LNCR Showroom'] ? 1 : 0);
$visminColCount = count($regions_by_zone['VIS']) + 1 + count($regions_by_zone['MIN']) + 1 + ($showrooms['VISMIN Showroom'] ? 1 : 0);

// Setup region columns
$lznRegionsCols = [];
$ncrRegionsCols = [];
$visRegionsCols = [];
$minRegionsCols = [];

foreach ($regions_by_zone['LZN'] as $r) { 
    $regionMap[$r] = $col; 
    $lznRegionsCols[$r] = $col;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $regionRow, $r); 
}
$lznTotalCol = $col++; 
$sheet->setCellValue(Coordinate::stringFromColumnIndex($lznTotalCol) . $regionRow, 'LUZON');

foreach ($regions_by_zone['NCR'] as $r) { 
    $regionMap[$r] = $col; 
    $ncrRegionsCols[$r] = $col;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $regionRow, $r); 
}
$ncrTotalCol = $col++; 
$sheet->setCellValue(Coordinate::stringFromColumnIndex($ncrTotalCol) . $regionRow, 'NCR');

if ($showrooms['LNCR Showroom']) { 
    $lncrShCol = $col; 
    $regionMap['LNCR Showroom'] = $col++; 
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($lncrShCol) . $regionRow, 'LNCR Showroom'); 
}

foreach ($regions_by_zone['VIS'] as $r) { 
    $regionMap[$r] = $col; 
    $visRegionsCols[$r] = $col;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $regionRow, $r); 
}
$visTotalCol = $col++; 
$sheet->setCellValue(Coordinate::stringFromColumnIndex($visTotalCol) . $regionRow, 'VISAYAS');

foreach ($regions_by_zone['MIN'] as $r) { 
    $regionMap[$r] = $col; 
    $minRegionsCols[$r] = $col;
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++) . $regionRow, $r); 
}
$minTotalCol = $col++; 
$sheet->setCellValue(Coordinate::stringFromColumnIndex($minTotalCol) . $regionRow, 'MINDANAO');

if ($showrooms['VISMIN Showroom']) { 
    $visminShCol = $col; 
    $regionMap['VISMIN Showroom'] = $col++; 
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($visminShCol) . $regionRow, 'VISMIN Showroom'); 
}

$lncrCalcCol = $col++; 
$allLncrCalcCol = $col++; 
$visminCalcCol = $col++; 
$allVisminCalcCol = $col++; 
$mlfsiCalcCol = $col++; 
$jewelersCalcCol = $col++; 
$nationwideCalcCol = $col++;

$sheet->setCellValue(Coordinate::stringFromColumnIndex($lncrCalcCol) . $regionRow, 'LNCR');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($allLncrCalcCol) . $regionRow, 'ALL LNCR');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($visminCalcCol) . $regionRow, 'VISMIN');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($allVisminCalcCol) . $regionRow, 'ALL VISMIN');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($mlfsiCalcCol) . $regionRow, 'MLFSI');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($jewelersCalcCol) . $regionRow, 'JEWELERS');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($nationwideCalcCol) . $regionRow, 'NATIONWIDE');

// Define last column AFTER all columns are set up
$lastCol = Coordinate::stringFromColumnIndex($nationwideCalcCol);

// --- NEW HEADER ROWS (Rows 1-4) ---

// Row 1: Company Name
$sheet->mergeCells("A1:C1");

$richText = new RichText();

// Red part
$redText = $richText->createTextRun("M LHUILLIER ");
$redText->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FF0000');

// Black part
$blackText = $richText->createTextRun("FINANCIAL SERVICES, INC.");
$blackText->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('000000');

$sheet->setCellValue("A1", $richText);

$sheet->getStyle("A1")->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 2: Report Title
$sheet->mergeCells("A2:C2");
$sheet->setCellValue("A2", "PROFIT AND LOSS STATEMENT");
$sheet->getStyle("A2")->getFont()->setBold(true)->setSize(11);
$sheet->getStyle("A2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 3: Period
$sheet->mergeCells("A3:C3");
$sheet->setCellValue("A3", $period_text);
$sheet->getStyle("A3")->getFont()->setBold(true)->setSize(10);
$sheet->getStyle("A3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Row 4: Blank spacer row
$sheet->mergeCells("A4:{$lastCol}4");

// Now create the main header row (Row 5) with zone groupings
$sheet->mergeCells("D{$headerRow}:" . Coordinate::stringFromColumnIndex(3 + $lncrColCount) . $headerRow);
$sheet->setCellValue("D{$headerRow}", "LUZON & NCR");
$sheet->getStyle("D{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('AE0000');
$sheet->getStyle("D{$headerRow}")->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_WHITE);
$sheet->getStyle("D{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$visminStart = 4 + $lncrColCount;
$sheet->mergeCells(Coordinate::stringFromColumnIndex($visminStart) . $headerRow . ":" . Coordinate::stringFromColumnIndex($visminStart + $visminColCount - 1) . $headerRow);
$sheet->setCellValue(Coordinate::stringFromColumnIndex($visminStart) . $headerRow, "VISAYAS & MINDANAO");
$sheet->getStyle(Coordinate::stringFromColumnIndex($visminStart) . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('630000');
$sheet->getStyle(Coordinate::stringFromColumnIndex($visminStart) . $headerRow)->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_WHITE);
$sheet->getStyle(Coordinate::stringFromColumnIndex($visminStart) . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$totalsStart = $visminStart + $visminColCount;
$sheet->mergeCells(Coordinate::stringFromColumnIndex($totalsStart) . $headerRow . ":" . Coordinate::stringFromColumnIndex($totalsStart + 6) . $headerRow);
$sheet->getStyle(Coordinate::stringFromColumnIndex($totalsStart) . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF8030');
$sheet->setCellValue(Coordinate::stringFromColumnIndex($totalsStart) . $headerRow, "TOTALS");
$sheet->getStyle(Coordinate::stringFromColumnIndex($totalsStart) . $headerRow)->getFont()->setBold(true);
$sheet->getStyle(Coordinate::stringFromColumnIndex($totalsStart) . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Apply styles to header rows
$sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray($centerBold);
$sheet->getStyle("A{$regionRow}:{$lastCol}{$regionRow}")->applyFromArray($centerBold);

// --- Helper function to initialize totals ---
$initTotals = function() use ($regionMap) {
    $totals = [
        'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 
        'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
        'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 
        'all_vismin' => 0, 'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
    ];
    
    // Initialize regions array with all region names as keys
    $totals['regions'] = array_fill_keys(array_keys($regionMap), 0);
    
    return $totals;
};

// --- Helper function to calculate derived totals from region data ---
$calculateDerivedTotals = function($regionTotals, $lznTotal, $ncrTotal, $visTotal, $minTotal, $lncrShTotal, $visminShTotal) {
    $lncrTotal = $lznTotal + $ncrTotal;
    $allLncrTotal = $lncrTotal + $lncrShTotal;
    $visminTotal = $visTotal + $minTotal;
    $allVisminTotal = $visminTotal + $visminShTotal;
    $mlfsiTotal = $lncrTotal + $visminTotal;
    $jewelersTotal = $lncrShTotal + $visminShTotal;
    $nationwideTotal = $mlfsiTotal + $jewelersTotal;
    
    return [
        'lncr' => $lncrTotal, 'all_lncr' => $allLncrTotal,
        'vismin' => $visminTotal, 'all_vismin' => $allVisminTotal,
        'mlfsi' => $mlfsiTotal, 'jewelers' => $jewelersTotal,
        'nationwide' => $nationwideTotal
    ];
};

// Initialize overall totals
$revenue_overall = $initTotals();
$cost_overall = $initTotals();
$selling_admin_overall = $initTotals();
$operating_overall = $initTotals();
$interest_overall = $initTotals();
$tax_overall = $initTotals();

// Function to write totals to row
$writeTotalsToRow = function($row, $data) use ($sheet, $regionMap, $lznTotalCol, $ncrTotalCol, $visTotalCol, $minTotalCol, 
    $lncrCalcCol, $allLncrCalcCol, $visminCalcCol, $allVisminCalcCol, $mlfsiCalcCol, $jewelersCalcCol, $nationwideCalcCol, $showrooms) {
    
    foreach($regionMap as $reg => $cIdx) { 
        $val = $data['regions'][$reg] ?? 0;
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($cIdx) . $row, $val);
    }
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($lznTotalCol) . $row, $data['lzn']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($ncrTotalCol) . $row, $data['ncr']);
    if($showrooms['LNCR Showroom']) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($regionMap['LNCR Showroom']) . $row, $data['lncr_sh']);
    }
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($visTotalCol) . $row, $data['vis']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($minTotalCol) . $row, $data['min']);
    if($showrooms['VISMIN Showroom']) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($regionMap['VISMIN Showroom']) . $row, $data['vismin_sh']);
    }
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($lncrCalcCol) . $row, $data['lncr']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($allLncrCalcCol) . $row, $data['all_lncr']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($visminCalcCol) . $row, $data['vismin']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($allVisminCalcCol) . $row, $data['all_vismin']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($mlfsiCalcCol) . $row, $data['mlfsi']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($jewelersCalcCol) . $row, $data['jewelers']);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($nationwideCalcCol) . $row, $data['nationwide']);
};

$rowNum = $regionRow + 1; // Start data rows after headers (row 7)
// $sheet->mergeCells("A{$rowNum}:{$lastCol}{$rowNum}");
// $sheet->getStyle("A{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E6E6E6');
// $rowNum++;

// $sheet->mergeCells("A{$rowNum}:{$lastCol}{$rowNum}");
$sheet->setCellValue("A{$rowNum}", "REVENUES");
$sheet->getStyle("A{$rowNum}:{$lastCol}{$rowNum}")->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAF8C']], 
    'font' => ['bold' => true]
]);
$rowNum++;

// Process each group
foreach ($groupedTransactions as $group_key => $rows) {
    $parts = explode('||', $group_key);
    $s = (int)$parts[0];
    $desc = $parts[1];
    $hide_sub_rows = in_array($s, [6, 8, 11], true);
    $group_t = $initTotals();

    foreach ($rows as $tx) {
        $sub = (int)$tx['sub_order'];
        // Determine sign: for sort_order 15, sub_order 2, it's negative (revenue reversal)
        $sign = ($s === 15 && $sub === 2) ? -1 : 1;
        
        // Calculate regional totals for this row
        $row_lzn_total = 0;
        $row_ncr_total = 0;
        $row_vis_total = 0;
        $row_min_total = 0;
        $row_lncr_sh_total = 0;
        $row_vismin_sh_total = 0;
        
        // Process each region
        foreach ($regionMap as $reg => $colIdx) {
            $val = $past_amounts[$s][$sub][$reg] ?? 0;
            $signed_val = $sign * $val;
            
            // Accumulate to group totals
            $group_t['regions'][$reg] += $signed_val;
            
            // Calculate zone totals for this row
            if (in_array($reg, $regions_by_zone['LZN'])) {
                $row_lzn_total += $val;
            } elseif (in_array($reg, $regions_by_zone['NCR'])) {
                $row_ncr_total += $val;
            } elseif (in_array($reg, $regions_by_zone['VIS'])) {
                $row_vis_total += $val;
            } elseif (in_array($reg, $regions_by_zone['MIN'])) {
                $row_min_total += $val;
            } elseif ($reg == 'LNCR Showroom') {
                $row_lncr_sh_total = $val;
            } elseif ($reg == 'VISMIN Showroom') {
                $row_vismin_sh_total = $val;
            }
            
            // Write individual cell value if not hidden
            if (!$hide_sub_rows) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIdx) . $rowNum, $val);
            }
        }
        
        // Add signed values to group totals
        $group_t['lzn'] += $sign * $row_lzn_total;
        $group_t['ncr'] += $sign * $row_ncr_total;
        $group_t['vis'] += $sign * $row_vis_total;
        $group_t['min'] += $sign * $row_min_total;
        $group_t['lncr_sh'] += $sign * $row_lncr_sh_total;
        $group_t['vismin_sh'] += $sign * $row_vismin_sh_total;
        
        // Calculate derived totals for this row
        $row_lncr = $row_lzn_total + $row_ncr_total;
        $row_all_lncr = $row_lncr + $row_lncr_sh_total;
        $row_vismin = $row_vis_total + $row_min_total;
        $row_all_vismin = $row_vismin + $row_vismin_sh_total;
        $row_mlfsi = $row_lncr + $row_vismin;
        $row_jewelers = $row_lncr_sh_total + $row_vismin_sh_total;
        $row_nationwide = $row_mlfsi + $row_jewelers;
        
        // Add to group totals
        $group_t['lncr'] += $sign * $row_lncr;
        $group_t['all_lncr'] += $sign * $row_all_lncr;
        $group_t['vismin'] += $sign * $row_vismin;
        $group_t['all_vismin'] += $sign * $row_all_vismin;
        $group_t['mlfsi'] += $sign * $row_mlfsi;
        $group_t['jewelers'] += $sign * $row_jewelers;
        $group_t['nationwide'] += $sign * $row_nationwide;
        
        // Write sub-order row if not hidden
        if (!$hide_sub_rows) {
            // $sheet->setCellValue('A' . $rowNum, $s);
            $sheet->setCellValue('C' . $rowNum, $tx['gl_description_comparative']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($lznTotalCol) . $rowNum, $row_lzn_total);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($ncrTotalCol) . $rowNum, $row_ncr_total);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($visTotalCol) . $rowNum, $row_vis_total);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($minTotalCol) . $rowNum, $row_min_total);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($lncrCalcCol) . $rowNum, $row_lncr);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($allLncrCalcCol) . $rowNum, $row_all_lncr);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($visminCalcCol) . $rowNum, $row_vismin);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($allVisminCalcCol) . $rowNum, $row_all_vismin);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($mlfsiCalcCol) . $rowNum, $row_mlfsi);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($jewelersCalcCol) . $rowNum, $row_jewelers);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($nationwideCalcCol) . $rowNum, $row_nationwide);
            
            // Apply right alignment for numeric cells
            $sheet->getStyle(Coordinate::stringFromColumnIndex($lznTotalCol) . $rowNum . ':' . $lastCol . $rowNum)
                  ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            
            // Apply left alignment for Description column (Column B)
            $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            
            if ($s >= 1 && $s <= 20) {
                $sheet->getRowDimension($rowNum)->setOutlineLevel(1)->setVisible(false);
            }
            $rowNum++;
        }
    }
    
    // Write category total row (skip for specific sort orders)
    if (!in_array($s, [24, 25, 26], true)) {
        // $sheet->setCellValue('A' . $rowNum, $s);
        $sheet->setCellValue('B' . $rowNum, $desc);
        $writeTotalsToRow($rowNum, $group_t);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($orangeFill);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        if ($s >= 1 && $s <= 20) {
            $sheet->getRowDimension($rowNum)->setCollapsed(true);
        }
        $rowNum++;
    }
    
    // Spacer row
    $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
    $sheet->getStyle("A$rowNum")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E6E6E6');
    if ($s >= 1 && $s <= 20) {
        $sheet->getRowDimension($rowNum)->setOutlineLevel(1)->setVisible(false);
    }
    $rowNum++;
    
    // Accumulate into overall totals based on sort_order
    $accumulate = function(&$target, $source) use ($regionMap) {
        foreach ($regionMap as $region => $col) {
            $target['regions'][$region] += $source['regions'][$region];
        }
        $target['lzn'] += $source['lzn'];
        $target['ncr'] += $source['ncr'];
        $target['lncr_sh'] += $source['lncr_sh'];
        $target['vis'] += $source['vis'];
        $target['min'] += $source['min'];
        $target['vismin_sh'] += $source['vismin_sh'];
        $target['lncr'] += $source['lncr'];
        $target['all_lncr'] += $source['all_lncr'];
        $target['vismin'] += $source['vismin'];
        $target['all_vismin'] += $source['all_vismin'];
        $target['mlfsi'] += $source['mlfsi'];
        $target['jewelers'] += $source['jewelers'];
        $target['nationwide'] += $source['nationwide'];
    };
    
    if ($s >= 1 && $s <= 20) {
        $accumulate($revenue_overall, $group_t);
    } elseif ($s == 21) {
        $accumulate($cost_overall, $group_t);
    } elseif ($s == 22 || $s == 23) {
        $accumulate($selling_admin_overall, $group_t);
    } elseif ($s == 24) {
        $accumulate($operating_overall, $group_t);
    } elseif ($s == 25) {
        $accumulate($interest_overall, $group_t);
    } elseif ($s == 26) {
        $accumulate($tax_overall, $group_t);
    }
    
    // Insert TOTAL REVENUES row after sort_order 20
    if ($s == 20) {
        $sheet->setCellValue("A$rowNum", "TOTAL REVENUES");
        $writeTotalsToRow($rowNum, $revenue_overall);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension($rowNum)->setCollapsed(true);
        $rowNum++;
        
        // Spacer
        $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
        $sheet->getStyle("A$rowNum")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E6E6E6');
        $rowNum++;
        
        // Cost of Sales header
        $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
        $sheet->setCellValue("A$rowNum", "COST OF SALES/SERVICE");
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAF8C']], 
            'font' => ['bold' => true]
        ]);
        $rowNum++;
        
    } elseif ($s == 21) {
        // Calculate GROSS PROFIT
        $gp = $initTotals();
        foreach ($regionMap as $region => $col) {
            $gp['regions'][$region] = $revenue_overall['regions'][$region] - $cost_overall['regions'][$region];
        }
        $gp['lzn'] = $revenue_overall['lzn'] - $cost_overall['lzn'];
        $gp['ncr'] = $revenue_overall['ncr'] - $cost_overall['ncr'];
        $gp['lncr_sh'] = $revenue_overall['lncr_sh'] - $cost_overall['lncr_sh'];
        $gp['vis'] = $revenue_overall['vis'] - $cost_overall['vis'];
        $gp['min'] = $revenue_overall['min'] - $cost_overall['min'];
        $gp['vismin_sh'] = $revenue_overall['vismin_sh'] - $cost_overall['vismin_sh'];
        $gp['lncr'] = $revenue_overall['lncr'] - $cost_overall['lncr'];
        $gp['all_lncr'] = $revenue_overall['all_lncr'] - $cost_overall['all_lncr'];
        $gp['vismin'] = $revenue_overall['vismin'] - $cost_overall['vismin'];
        $gp['all_vismin'] = $revenue_overall['all_vismin'] - $cost_overall['all_vismin'];
        $gp['mlfsi'] = $revenue_overall['mlfsi'] - $cost_overall['mlfsi'];
        $gp['jewelers'] = $revenue_overall['jewelers'] - $cost_overall['jewelers'];
        $gp['nationwide'] = $revenue_overall['nationwide'] - $cost_overall['nationwide'];
        
        $sheet->setCellValue("A$rowNum", "GROSS PROFIT");
        $writeTotalsToRow($rowNum, $gp);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $rowNum++;
        
        // Spacer
        $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
        $sheet->getStyle("A$rowNum")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E6E6E6');
        $rowNum++;
        
        // Selling & Admin header
        $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
        $sheet->setCellValue("A$rowNum", "SELLING & ADMIN EXPENSES");
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAF8C']], 
            'font' => ['bold' => true]
        ]);
        $rowNum++;
        
    } elseif ($s == 23) {
        // TOTAL SELLING AND ADMIN EXPENSES
        $sheet->setCellValue("A$rowNum", "TOTAL SELLING AND ADMIN EXPENSES");
        $writeTotalsToRow($rowNum, $selling_admin_overall);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $rowNum++;
          // Spacer
        $sheet->mergeCells("A$rowNum:{$lastCol}$rowNum");
        $sheet->getStyle("A$rowNum")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('E6E6E6');
        $rowNum++;
        
        // Calculate EBITDA
        $ebitda = $initTotals();
        foreach ($regionMap as $region => $col) {
            $ebitda['regions'][$region] = $revenue_overall['regions'][$region] - $cost_overall['regions'][$region] - $selling_admin_overall['regions'][$region];
        }
        $ebitda['lzn'] = $revenue_overall['lzn'] - $cost_overall['lzn'] - $selling_admin_overall['lzn'];
        $ebitda['ncr'] = $revenue_overall['ncr'] - $cost_overall['ncr'] - $selling_admin_overall['ncr'];
        $ebitda['lncr_sh'] = $revenue_overall['lncr_sh'] - $cost_overall['lncr_sh'] - $selling_admin_overall['lncr_sh'];
        $ebitda['vis'] = $revenue_overall['vis'] - $cost_overall['vis'] - $selling_admin_overall['vis'];
        $ebitda['min'] = $revenue_overall['min'] - $cost_overall['min'] - $selling_admin_overall['min'];
        $ebitda['vismin_sh'] = $revenue_overall['vismin_sh'] - $cost_overall['vismin_sh'] - $selling_admin_overall['vismin_sh'];
        $ebitda['lncr'] = $revenue_overall['lncr'] - $cost_overall['lncr'] - $selling_admin_overall['lncr'];
        $ebitda['all_lncr'] = $revenue_overall['all_lncr'] - $cost_overall['all_lncr'] - $selling_admin_overall['all_lncr'];
        $ebitda['vismin'] = $revenue_overall['vismin'] - $cost_overall['vismin'] - $selling_admin_overall['vismin'];
        $ebitda['all_vismin'] = $revenue_overall['all_vismin'] - $cost_overall['all_vismin'] - $selling_admin_overall['all_vismin'];
        $ebitda['mlfsi'] = $revenue_overall['mlfsi'] - $cost_overall['mlfsi'] - $selling_admin_overall['mlfsi'];
        $ebitda['jewelers'] = $revenue_overall['jewelers'] - $cost_overall['jewelers'] - $selling_admin_overall['jewelers'];
        $ebitda['nationwide'] = $revenue_overall['nationwide'] - $cost_overall['nationwide'] - $selling_admin_overall['nationwide'];
        
        $sheet->setCellValue("A$rowNum", "EBITDA");
        $writeTotalsToRow($rowNum, $ebitda);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $rowNum++;
        
    } elseif ($s == 24) {
        // Calculate EBIT (Earnings Before Interest & Taxes)
        $ebit = $initTotals();
        foreach ($regionMap as $region => $col) {
            $ebit['regions'][$region] = $revenue_overall['regions'][$region] - $cost_overall['regions'][$region] 
                                       - $selling_admin_overall['regions'][$region] - $operating_overall['regions'][$region];
        }
        $ebit['lzn'] = $revenue_overall['lzn'] - $cost_overall['lzn'] - $selling_admin_overall['lzn'] - $operating_overall['lzn'];
        $ebit['ncr'] = $revenue_overall['ncr'] - $cost_overall['ncr'] - $selling_admin_overall['ncr'] - $operating_overall['ncr'];
        $ebit['lncr_sh'] = $revenue_overall['lncr_sh'] - $cost_overall['lncr_sh'] - $selling_admin_overall['lncr_sh'] - $operating_overall['lncr_sh'];
        $ebit['vis'] = $revenue_overall['vis'] - $cost_overall['vis'] - $selling_admin_overall['vis'] - $operating_overall['vis'];
        $ebit['min'] = $revenue_overall['min'] - $cost_overall['min'] - $selling_admin_overall['min'] - $operating_overall['min'];
        $ebit['vismin_sh'] = $revenue_overall['vismin_sh'] - $cost_overall['vismin_sh'] - $selling_admin_overall['vismin_sh'] - $operating_overall['vismin_sh'];
        $ebit['lncr'] = $revenue_overall['lncr'] - $cost_overall['lncr'] - $selling_admin_overall['lncr'] - $operating_overall['lncr'];
        $ebit['all_lncr'] = $revenue_overall['all_lncr'] - $cost_overall['all_lncr'] - $selling_admin_overall['all_lncr'] - $operating_overall['all_lncr'];
        $ebit['vismin'] = $revenue_overall['vismin'] - $cost_overall['vismin'] - $selling_admin_overall['vismin'] - $operating_overall['vismin'];
        $ebit['all_vismin'] = $revenue_overall['all_vismin'] - $cost_overall['all_vismin'] - $selling_admin_overall['all_vismin'] - $operating_overall['all_vismin'];
        $ebit['mlfsi'] = $revenue_overall['mlfsi'] - $cost_overall['mlfsi'] - $selling_admin_overall['mlfsi'] - $operating_overall['mlfsi'];
        $ebit['jewelers'] = $revenue_overall['jewelers'] - $cost_overall['jewelers'] - $selling_admin_overall['jewelers'] - $operating_overall['jewelers'];
        $ebit['nationwide'] = $revenue_overall['nationwide'] - $cost_overall['nationwide'] - $selling_admin_overall['nationwide'] - $operating_overall['nationwide'];
        
        $sheet->setCellValue("A$rowNum", "EBIT");
        $writeTotalsToRow($rowNum, $ebit);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $rowNum++;
        
    } elseif ($s == 25) {
        // Calculate EBT (Earnings Before Taxes)
        $ebt = $initTotals();
        foreach ($regionMap as $region => $col) {
            $ebt['regions'][$region] = $revenue_overall['regions'][$region] - $cost_overall['regions'][$region] 
                                      - $selling_admin_overall['regions'][$region] - $operating_overall['regions'][$region]
                                      - $interest_overall['regions'][$region];
        }
        $ebt['lzn'] = $revenue_overall['lzn'] - $cost_overall['lzn'] - $selling_admin_overall['lzn'] - $operating_overall['lzn'] - $interest_overall['lzn'];
        $ebt['ncr'] = $revenue_overall['ncr'] - $cost_overall['ncr'] - $selling_admin_overall['ncr'] - $operating_overall['ncr'] - $interest_overall['ncr'];
        $ebt['lncr_sh'] = $revenue_overall['lncr_sh'] - $cost_overall['lncr_sh'] - $selling_admin_overall['lncr_sh'] - $operating_overall['lncr_sh'] - $interest_overall['lncr_sh'];
        $ebt['vis'] = $revenue_overall['vis'] - $cost_overall['vis'] - $selling_admin_overall['vis'] - $operating_overall['vis'] - $interest_overall['vis'];
        $ebt['min'] = $revenue_overall['min'] - $cost_overall['min'] - $selling_admin_overall['min'] - $operating_overall['min'] - $interest_overall['min'];
        $ebt['vismin_sh'] = $revenue_overall['vismin_sh'] - $cost_overall['vismin_sh'] - $selling_admin_overall['vismin_sh'] - $operating_overall['vismin_sh'] - $interest_overall['vismin_sh'];
        $ebt['lncr'] = $revenue_overall['lncr'] - $cost_overall['lncr'] - $selling_admin_overall['lncr'] - $operating_overall['lncr'] - $interest_overall['lncr'];
        $ebt['all_lncr'] = $revenue_overall['all_lncr'] - $cost_overall['all_lncr'] - $selling_admin_overall['all_lncr'] - $operating_overall['all_lncr'] - $interest_overall['all_lncr'];
        $ebt['vismin'] = $revenue_overall['vismin'] - $cost_overall['vismin'] - $selling_admin_overall['vismin'] - $operating_overall['vismin'] - $interest_overall['vismin'];
        $ebt['all_vismin'] = $revenue_overall['all_vismin'] - $cost_overall['all_vismin'] - $selling_admin_overall['all_vismin'] - $operating_overall['all_vismin'] - $interest_overall['all_vismin'];
        $ebt['mlfsi'] = $revenue_overall['mlfsi'] - $cost_overall['mlfsi'] - $selling_admin_overall['mlfsi'] - $operating_overall['mlfsi'] - $interest_overall['mlfsi'];
        $ebt['jewelers'] = $revenue_overall['jewelers'] - $cost_overall['jewelers'] - $selling_admin_overall['jewelers'] - $operating_overall['jewelers'] - $interest_overall['jewelers'];
        $ebt['nationwide'] = $revenue_overall['nationwide'] - $cost_overall['nationwide'] - $selling_admin_overall['nationwide'] - $operating_overall['nationwide'] - $interest_overall['nationwide'];
        
        $sheet->setCellValue("A$rowNum", "EBT");
        $writeTotalsToRow($rowNum, $ebt);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $rowNum++;
        
    } elseif ($s == 26) {
        // Calculate NET INCOME/LOSS
        $net = $initTotals();
        foreach ($regionMap as $region => $col) {
            $net['regions'][$region] = $revenue_overall['regions'][$region] - $cost_overall['regions'][$region] 
                                      - $selling_admin_overall['regions'][$region] - $operating_overall['regions'][$region]
                                      - $interest_overall['regions'][$region] - $tax_overall['regions'][$region];
        }
        $net['lzn'] = $revenue_overall['lzn'] - $cost_overall['lzn'] - $selling_admin_overall['lzn'] - $operating_overall['lzn'] - $interest_overall['lzn'] - $tax_overall['lzn'];
        $net['ncr'] = $revenue_overall['ncr'] - $cost_overall['ncr'] - $selling_admin_overall['ncr'] - $operating_overall['ncr'] - $interest_overall['ncr'] - $tax_overall['ncr'];
        $net['lncr_sh'] = $revenue_overall['lncr_sh'] - $cost_overall['lncr_sh'] - $selling_admin_overall['lncr_sh'] - $operating_overall['lncr_sh'] - $interest_overall['lncr_sh'] - $tax_overall['lncr_sh'];
        $net['vis'] = $revenue_overall['vis'] - $cost_overall['vis'] - $selling_admin_overall['vis'] - $operating_overall['vis'] - $interest_overall['vis'] - $tax_overall['vis'];
        $net['min'] = $revenue_overall['min'] - $cost_overall['min'] - $selling_admin_overall['min'] - $operating_overall['min'] - $interest_overall['min'] - $tax_overall['min'];
        $net['vismin_sh'] = $revenue_overall['vismin_sh'] - $cost_overall['vismin_sh'] - $selling_admin_overall['vismin_sh'] - $operating_overall['vismin_sh'] - $interest_overall['vismin_sh'] - $tax_overall['vismin_sh'];
        $net['lncr'] = $revenue_overall['lncr'] - $cost_overall['lncr'] - $selling_admin_overall['lncr'] - $operating_overall['lncr'] - $interest_overall['lncr'] - $tax_overall['lncr'];
        $net['all_lncr'] = $revenue_overall['all_lncr'] - $cost_overall['all_lncr'] - $selling_admin_overall['all_lncr'] - $operating_overall['all_lncr'] - $interest_overall['all_lncr'] - $tax_overall['all_lncr'];
        $net['vismin'] = $revenue_overall['vismin'] - $cost_overall['vismin'] - $selling_admin_overall['vismin'] - $operating_overall['vismin'] - $interest_overall['vismin'] - $tax_overall['vismin'];
        $net['all_vismin'] = $revenue_overall['all_vismin'] - $cost_overall['all_vismin'] - $selling_admin_overall['all_vismin'] - $operating_overall['all_vismin'] - $interest_overall['all_vismin'] - $tax_overall['all_vismin'];
        $net['mlfsi'] = $revenue_overall['mlfsi'] - $cost_overall['mlfsi'] - $selling_admin_overall['mlfsi'] - $operating_overall['mlfsi'] - $interest_overall['mlfsi'] - $tax_overall['mlfsi'];
        $net['jewelers'] = $revenue_overall['jewelers'] - $cost_overall['jewelers'] - $selling_admin_overall['jewelers'] - $operating_overall['jewelers'] - $interest_overall['jewelers'] - $tax_overall['jewelers'];
        $net['nationwide'] = $revenue_overall['nationwide'] - $cost_overall['nationwide'] - $selling_admin_overall['nationwide'] - $operating_overall['nationwide'] - $interest_overall['nationwide'] - $tax_overall['nationwide'];
        
        $sheet->setCellValue("A$rowNum", "NET INCOME/LOSS");
        $writeTotalsToRow($rowNum, $net);
        $sheet->getStyle("A$rowNum:{$lastCol}$rowNum")->applyFromArray($totalsRowStyle);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $rowNum++;
    }
}

// Apply borders
$sheet->getStyle("D5:{$lastCol}" . ($rowNum - 1))->applyFromArray($borderAll);

// Apply specific borders for NET INCOME/LOSS row
$sheet->getStyle("D" . ($rowNum - 1) . ":{$lastCol}" . ($rowNum - 1))->applyFromArray([
    'borders' => [
        'top' => ['borderStyle' => Border::BORDER_THIN],
        'bottom' => ['borderStyle' => Border::BORDER_DOUBLE],
    ],
]);

// Format numbers and apply red font color for negative values
$dataRange = "D2:{$lastCol}" . ($rowNum - 1);
$sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode('#,##0.00');

$negativeCondition = new Conditional();
$negativeCondition->setConditionType(Conditional::CONDITION_CELLIS);
$negativeCondition->setOperatorType(Conditional::OPERATOR_LESSTHAN);
$negativeCondition->addCondition('0');
$negativeCondition->getStyle()->getFont()->getColor()->setARGB(Color::COLOR_RED);

$conditionalStyles = $sheet->getStyle($dataRange)->getConditionalStyles();
$conditionalStyles[] = $negativeCondition;
$sheet->getStyle($dataRange)->setConditionalStyles($conditionalStyles);

// Apply auto-width to all columns
$lastColIndex = Coordinate::columnIndexFromString($lastCol);
for ($col = 4; $col <= $lastColIndex; $col++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
}

// Special handling for column C (GL Description Comparative) - wider
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(30);
$sheet->getColumnDimension('C')->setWidth(45);

$sheet->setShowSummaryBelow(true);

// Output file
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Past_Transactions_' . $selected_month . '.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
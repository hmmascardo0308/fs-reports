<?php
session_start();
require '../vendor/autoload.php'; 
require_once __DIR__ . '/../config/config.php';
include('fetch_data.php');

// Initialize variables that might be undefined if fetch_data.php doesn't set them under certain conditions
$current_year = $current_year ?? '';
// Initialize variables that might be undefined if fetch_data.php doesn't set them under certain conditions
$areas_to_display = $areas_to_display ?? [];
$has_area_filter = $has_area_filter ?? false;
$multi_area = $multi_area ?? false;
$current_year_label = $current_year_label ?? '';
$previous_year_label = $previous_year_label ?? '';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Determine if top area header should be shown
$is_single = count($areas_to_display) === 1;
$show_area_header = $has_area_filter;
$show_top_header = $multi_area || ($show_area_header && $is_single);

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
// Freeze columns A, B, C (Adjusted to D10 because we added more header rows)
$sheet->freezePane('D10');

$sheet->setTitle('Comparative Report');

// --- START NEW HEADER FORMAT ---

// Set the height for the logo row once
$sheet->getRowDimension(1)->setRowHeight(55); 

$col = 1;
$columnsPerArea = 15;

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $startColLetter = Coordinate::stringFromColumnIndex($baseCol);
    $endColLetter = Coordinate::stringFromColumnIndex($baseCol + $columnsPerArea - 1);
    
    // Calculate a center column for the logo (roughly the 6th column of the 15-column block)
    $logoColLetter = Coordinate::stringFromColumnIndex($baseCol + 5.8);
    
    $area_display_name = ($area_key === '_all') ? 'CONSOLIDATED' : strtoupper($area_key);

    // 1. Logo for THIS specific area block
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setName('Logo_' . $area_idx);
    $drawing->setPath('C:\xampp\htdocs\fs-reports\images\mlhuillier.jpg'); 
    $drawing->setHeight(70);
    
    // Anchor to the middle-ish column to appear centered
    $drawing->setCoordinates($logoColLetter . '1');
    $drawing->setWorksheet($sheet);

    // 2. Row 2: Region + Title
    $row = 2;
    $regionTitle = (!empty($selected_region) ? strtoupper($selected_region) . ' ' : '') . 'COMPARATIVE PROFIT & LOSS STATEMENT';
    $sheet->setCellValue($startColLetter . $row, $regionTitle);
    
    // 3. Row 3: Entity Label
    $row = 3;
    $sheet->setCellValue($startColLetter . $row, 'MLFSI & JEWELERS');

    // 4. Row 4: Comparison Years
    $row = 4;
    $sheet->setCellValue($startColLetter . $row, "JANUARY - DECEMBER {$current_year_label} vs {$previous_year_label} PER AREA");

    // 5. Row 5: Specific Area Name
    $row = 5;
    $sheet->setCellValue($startColLetter . $row, "AREA " . $area_display_name);

    // Styling and Centering logic for rows 2-5
    for ($i = 2; $i <= 5; $i++) {
        $cellRange = "{$startColLetter}{$i}:{$endColLetter}{$i}";
        $sheet->mergeCells($cellRange);
        
        $style = $sheet->getStyle($startColLetter . $i);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFont()->setBold(true);
        
        // Specific font sizes/italics per row
        if ($i === 2 || $i === 3 || $i === 4 || $i === 5) $style->getFont()->setSize(16);
        // if ($i === 5) $style->getFont()->setItalic(true);
    }
}

// Reset $row for data table
$row = 7; 

// --- END NEW HEADER FORMAT ---

$tableStartRow = $row;
$col = 1;
$columnsPerArea = 15;

// === Top Area Header (if applicable) ===
if ($show_top_header) {
    foreach ($areas_to_display as $i => $area_key) {
        $area_name = $area_key === '_all' ? 'Consolidated' : $area_key;
        $bg = ($i % 2 === 0) ? 'FFF200' : '229B00'; // yellow / green
        $startCol = $col + ($i * $columnsPerArea);
        $endCol   = $startCol + $columnsPerArea - 1;


// Convert column numbers to letters
$startCell = Coordinate::stringFromColumnIndex($startCol) . $row;
$endCell   = Coordinate::stringFromColumnIndex($endCol) . $row;

// Merge cells
$sheet->mergeCells("$startCell:$endCell");

// Set value in the first cell of the merged range
$sheet->setCellValue($startCell, $area_name);

// Apply fill color to the merged range
$sheet->getStyle("$startCell:$endCell")
      ->getFill()->setFillType(Fill::FILL_SOLID)
      ->getStartColor()->setARGB($bg);

// Align text horizontally in the first cell
$sheet->getStyle($startCell)
      ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Make font bold and white in the first cell
$sheet->getStyle($startCell)
      ->getFont()->setBold(true)
      ->getColor()->setARGB('000000');

    }
    $row++;
}

// === Year Header Row ===
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 3 empty columns
    $startCell = Coordinate::stringFromColumnIndex($baseCol) . $row;
    $endCell   = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
    $sheet->mergeCells("$startCell:$endCell");

    // Black separator column
    $blackCell = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $sheet->getStyle($blackCell)
          ->getFill()->setFillType(Fill::FILL_SOLID)
          ->getStartColor()->setARGB('000000');

    // === Current year ===
    $startCur = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $endCur   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;
    $sheet->mergeCells("$startCur:$endCur");
    $sheet->setCellValue($startCur, $current_year_label);

    $sheet->getStyle("$startCur:$endCur")->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FCB251'],
        ],
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    // === Previous year ===
    $startPrev = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $endPrev   = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;
    $sheet->mergeCells("$startPrev:$endPrev");
    $sheet->setCellValue($startPrev, $previous_year_label);

    $sheet->getStyle("$startPrev:$endPrev")->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FC950F'],
        ],
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    // Final black separator column
    $finalBlack = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;
    $sheet->getStyle($finalBlack)
          ->getFill()->setFillType(Fill::FILL_SOLID)
          ->getStartColor()->setARGB('000000');
}

$row++;

// === Sub Header Row (MLFSI / JEWELERS / TOTAL etc.) ===
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 3 empty columns
    $startEmpty = Coordinate::stringFromColumnIndex($baseCol) . $row;
    $endEmpty   = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
    $sheet->mergeCells("$startEmpty:$endEmpty");

    // Black separator
    $blackCell = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $sheet->getStyle($blackCell)
        ->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('000000');

    // === Current subheaders ===
    $currentStart = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $currentEnd   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $sheet->setCellValue($currentStart, 'MLFSI');
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, 'JEWELERS');
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, 'TOTAL');

    $sheet->getStyle("$currentStart:$currentEnd")->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FC950F'],
        ],
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    // === Previous subheaders ===
    $prevStart = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevEnd   = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $sheet->setCellValue($prevStart, 'MLFSI');
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, 'JEWELERS');
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, 'TOTAL');

    $sheet->getStyle("$prevStart:$prevEnd")->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FCB251'],
        ],
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    // === Inc./Dec + % (bold, centered, colored) ===
    $richText = new RichText();
    $inc = $richText->createTextRun('Inc./');
    $inc->getFont()->setBold(true);

    $dec = $richText->createTextRun('Dec.');
    $dec->getFont()->setBold(true);
    $dec->getFont()->setColor(new Color(Color::COLOR_RED));

    $incDecCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell    = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($incDecCell, $richText);
    $sheet->setCellValue($pctCell, '%');

    $sheet->getStyle("$incDecCell:$pctCell")->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'E2A14D'],
        ],
        'font' => [
            'bold' => true,
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
        ],
    ]);

    // Final black separator
    $finalBlack = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;
    $sheet->getStyle($finalBlack)
        ->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('000000');
}

$row++;

// Black separator row
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    for ($i = 0; $i < 15; $i++) {
        $cell = Coordinate::stringFromColumnIndex($baseCol + $i) . $row;
        $sheet->getStyle($cell)
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('ffffff');
    }
}
$row++;

// REVENUES header row
// REVENUES header row
$revenuesRow = $row;

// 1. STYLE THE WHOLE ROW ORANGE FIRST
// This acts as your "base coat" so it doesn't overwrite specific cell colors later
$lastColIndex = $col + (count($areas_to_display) * 15) - 1;
$entireRowRange = 'A' . $row . ':' . Coordinate::stringFromColumnIndex($lastColIndex) . $row;

$sheet->getStyle($entireRowRange)->applyFromArray([
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FF8408'] // Orange
    ]
]);

// 2. LOOP TO APPLY SPECIFIC OVERRIDES (REVENUES text and Black columns)
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * 15); // Using 15 directly or $columnsPerArea
    
    for ($local = 0; $local < 15; $local++) {
        $currentCol = $baseCol + $local;
        $cell = Coordinate::stringFromColumnIndex($currentCol) . $row;

        // Set the text for the first column of the area
        if ($local === 0) {
            $sheet->setCellValue($cell, 'REVENUES');
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        // Set 4th (index 3) and 15th (index 14) columns to BLACK
        if ($local === 3 || $local === 14) {
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('000000');
            
            // Optional: Set font to white so it's readable against black
            $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFF');
        }
    }
}

$row++;

// Revenue data rows + sums
$revenue_rows = [
    'Total Interest Income'         => 'total_interest_income',
    'Service Charge'                => 'service_charge',
    'Liquidated Damages'            => 'liquidated_damages',
    'Gain / Loss on Auction Sale'   => 'gain_loss',
    'Storage Fee'                   => 'storage_fee',
    'Income fr Appraisal'           => 'appraisal',
    'Counter Receipts'              => 'counter',
];

$sumMlfsiCurrent   = array_fill_keys($areas_to_display, 0);
$sumJewCurrent     = array_fill_keys($areas_to_display, 0);
$sumMlfsiPrevious  = array_fill_keys($areas_to_display, 0);
$sumJewPrevious    = array_fill_keys($areas_to_display, 0);

foreach ($revenue_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumMlfsiCurrent[$area_key]   += $mlfsi_cur;
        $sumJewCurrent[$area_key]     += $jew_cur;
        $sumMlfsiPrevious[$area_key]  += $mlfsi_prev;
        $sumJewPrevious[$area_key]    += $jew_prev;

        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

// Label
$sheet->setCellValue($labelCell, $label);
$sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Black separators
$sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
$sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

// Current values
$sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
$sheet->setCellValue($jewCurCell, $jew_cur);
$sheet->setCellValue($totalCurCell, $total_cur);
$sheet->getStyle($totalCurCell)->getFont()->setBold(true);

// Previous values
$sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
$sheet->setCellValue($jewPrevCell, $jew_prev);
$sheet->setCellValue($totalPrevCell, $total_prev);
$sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

// Diff & %
$sheet->setCellValue($diffCell, $diff);
$sheet->setCellValue($pctCell, $pct / 100);

// Conditional formatting: negative values in red
if ($diff < 0) {
    $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
if ($pct < 0) {
    $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
    }
    $row++;
}

// Money Lending Income total row
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumMlfsiCurrent[$area_key];
    $sc_jew   = $sumJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumMlfsiPrevious[$area_key];
    $sp_jew   = $sumJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
$blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
$blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

$curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
$curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
$curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

$prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
$prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
$prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

$diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
$pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

// Label
$sheet->setCellValue($labelCell, 'Money Lending Income');
$sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Black separators
$sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
$sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

// Current values
$sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
$sheet->setCellValue($curJewCell, $sc_jew);
$sheet->setCellValue($curTotalCell, $sc_total);
$sheet->getStyle($curTotalCell)->getFont()->setBold(true);

// Previous values
$sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
$sheet->setCellValue($prevJewCell, $sp_jew);
$sheet->setCellValue($prevTotalCell, $sp_total);
$sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

// Diff & %
$sheet->setCellValue($diffCell, $diff);
$sheet->setCellValue($pctCell, $pct / 100);

// Conditional formatting for negative values
if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;


// --- SPACER ROW ---

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    
    // Only apply black fill to columns 4 and 15 (zero-indexed 3 and 14)
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;


// Vehicle Loans data rows + sums
$vehicle_rows = [
    'Motor Loan'                            => 'motor_loan',
    'Motor Loan - H.O.'                     => 'motor_loan_ho',
    'Interest Income - ML Autoloan'         => 'interest_income_mlautoloan',
    'Car Loan - H.O.'                       => 'car_loan_ho',
    'Application Fee - ML Autoloan'         => 'appli_fee_mlautoloan',
    'Appraisal Fee - ML Autoloan'           => 'apprai_fee_mlautoloan',
    'Penalty & Other Charges - ML Autoloan' => 'pen_and_other_charges_mlautoloan',
    'Chattel Mortgage Income - ML Autoloan' => 'chattel_mort_income_mlautoloan',
    'Notarial Income - ML Autoloan'         => 'notarial_income',
];

$sumVehMlfsiCurrent   = array_fill_keys($areas_to_display, 0);
$sumVehJewCurrent     = array_fill_keys($areas_to_display, 0);
$sumVehMlfsiPrevious  = array_fill_keys($areas_to_display, 0);
$sumVehJewPrevious    = array_fill_keys($areas_to_display, 0);

foreach ($vehicle_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate Totals
        $sumVehMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumVehJewCurrent[$area_key]    += $jew_cur;
        $sumVehMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumVehJewPrevious[$area_key]   += $jew_prev;

        // Cell Mapping
        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Set Values
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data Injection
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Red color for negative performance
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- VEHICLE LOANS TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumVehMlfsiCurrent[$area_key];
    $sc_jew   = $sumVehJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumVehMlfsiPrevious[$area_key];
    $sp_jew   = $sumVehJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'Vehicle Loans');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;


// Home Loans data rows + sums
$home_loan_rows = [
    'Interest Income - ML Homeloan'         => 'interest_income_mlhomeloan',
    'Real Property - H.O'                   => 'real_property_ho',
    'Appraisal Fee - ML Homeloan'           => 'apprai_fee_mlhomeloan',
    'Penalty & Other Charges - ML Homeloan' => 'pen_and_other_charges_mlhomeloan',
    'Chattel Mortgage Income - ML Homeloan' => 'chattel_mort_income_mlhomeloan',
    'Notarial Income - ML Homeloan'         => 'notarial_income_mlhomeloan',
];

$sumHomeMlfsiCurrent   = array_fill_keys($areas_to_display, 0);
$sumHomeJewCurrent     = array_fill_keys($areas_to_display, 0);
$sumHomeMlfsiPrevious  = array_fill_keys($areas_to_display, 0);
$sumHomeJewPrevious    = array_fill_keys($areas_to_display, 0);

foreach ($home_loan_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumHomeMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumHomeJewCurrent[$area_key]    += $jew_cur;
        $sumHomeMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumHomeJewPrevious[$area_key]   += $jew_prev;

        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Set Values
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Conditional formatting: negative red
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// Home Loans Total Row
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumHomeMlfsiCurrent[$area_key];
    $sc_jew   = $sumHomeJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumHomeMlfsiPrevious[$area_key];
    $sp_jew   = $sumHomeJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    // Label
    $sheet->setCellValue($labelCell, 'Home Loans');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    // Separators
    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Values
    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// Spacer Row
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;

// Commercial Loans data rows + sums
$commercial_loan_rows = [
    'Interest Income - Short Term Extended / SBL' => 'interest_income_ste_sbl',
    'SBL'                                         => 'sbl',
    "Penalty Fee - ML SBL / Pensioner's Loan"     => 'penalty_fee_mlsbl',
    "Interest Income - ML Pensioner's Loan"       => 'interest_income_ml_pen_loan',
    "Service Fees - ML Pensioner's Loan"          => 'service_fees_ml_pen_loan',
];

$sumCommMlfsiCurrent   = array_fill_keys($areas_to_display, 0);
$sumCommJewCurrent     = array_fill_keys($areas_to_display, 0);
$sumCommMlfsiPrevious  = array_fill_keys($areas_to_display, 0);
$sumCommJewPrevious    = array_fill_keys($areas_to_display, 0);

foreach ($commercial_loan_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumCommMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumCommJewCurrent[$area_key]    += $jew_cur;
        $sumCommMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumCommJewPrevious[$area_key]   += $jew_prev;

        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Set Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data injection
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Negative values in red
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- COMMERCIAL LOANS TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumCommMlfsiCurrent[$area_key];
    $sc_jew   = $sumCommJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumCommMlfsiPrevious[$area_key];
    $sp_jew   = $sumCommJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'Commercial Loans');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;

// Kwarta Padala Income data rows + sums
$kwarta_padala_rows = [
    'KP - Regular'                      => 'kp_regular',
    'KP to Go'                          => 'kp_to_go',
    'KP Other Income'                   => 'kp_other_income',
    'KP Certification Fee'               => 'kp_cert_fee',
    'Kiosk Tellering Machine - MT'      => 'kiosk_mt',
    'KP Sendout Special Accounts - H.O' => 'kp_sendout_spec_acc_ho',
];

$sumKpMlfsiCurrent   = array_fill_keys($areas_to_display, 0);
$sumKpJewCurrent     = array_fill_keys($areas_to_display, 0);
$sumKpMlfsiPrevious  = array_fill_keys($areas_to_display, 0);
$sumKpJewPrevious    = array_fill_keys($areas_to_display, 0);

foreach ($kwarta_padala_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumKpMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumKpJewCurrent[$area_key]    += $jew_cur;
        $sumKpMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumKpJewPrevious[$area_key]   += $jew_prev;

        // Cell Mapping
        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Set Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data injection
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Negative values in red
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- KWARTA PADALA TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumKpMlfsiCurrent[$area_key];
    $sc_jew   = $sumKpJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumKpMlfsiPrevious[$area_key];
    $sp_jew   = $sumKpJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'Kwarta Padala Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;



// --- PAYMENT SOLUTION SECTION ---
$sumPSOLMlfsiCurrent   = array_fill_keys($areas_to_display, 0);
$sumPSOLJewCurrent     = array_fill_keys($areas_to_display, 0);
$sumPSOLMlfsiPrevious  = array_fill_keys($areas_to_display, 0);
$sumPSOLJewPrevious    = array_fill_keys($areas_to_display, 0);

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // Data Fetching
    $mlfsi_cur  = $totals[$area_key]['mlfsi']['payment_solution'][$current_year] ?? 0;
    $jew_cur    = $totals[$area_key]['jewelers']['payment_solution'][$current_year] ?? 0;
    $total_cur  = $mlfsi_cur + $jew_cur;

    $mlfsi_prev = $totals[$area_key]['mlfsi']['payment_solution'][$previous_year] ?? 0;
    $jew_prev   = $totals[$area_key]['jewelers']['payment_solution'][$previous_year] ?? 0;
    $total_prev = $mlfsi_prev + $jew_prev;

    $diff = $total_cur - $total_prev;
    $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

    // Accumulate for potential Grand Totals
    $sumPSOLMlfsiCurrent[$area_key]  += $mlfsi_cur;
    $sumPSOLJewCurrent[$area_key]    += $jew_cur;
    $sumPSOLMlfsiPrevious[$area_key] += $mlfsi_prev;
    $sumPSOLJewPrevious[$area_key]   += $jew_prev;

    // Cell Mapping
    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    // Label & Formatting
    $sheet->setCellValue($labelCell, 'Payment Solution');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    // Black separators
    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Values injection
    $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
    $sheet->setCellValue($jewCurCell, $jew_cur);
    $sheet->setCellValue($totalCurCell, $total_cur);
    $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

    $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
    $sheet->setCellValue($jewPrevCell, $jew_prev);
    $sheet->setCellValue($totalPrevCell, $total_prev);
    $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    // Color logic
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;


// Domestic Partners data rows + sums
$domestic_partner_rows = [
    'Express Pay'                          => 'express_pay',
    'BPI to cash'                          => 'bpi_to_cash',
    'LBC'                                  => 'lbc',
    'Rural Net'                            => 'rural_net',
    'PRIME BREAD AND BUTTER BAKESHOP CORP' => 'prime_bread',
    'Star Pay'                             => 'starpay',
    'Gcash Commission'                     => 'gcash_comm',
    'Domestic Partner - H.O'               => 'domestic_partner_ho',
];

$sumDpMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumDpJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumDpMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumDpJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($domestic_partner_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate Totals
        $sumDpMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumDpJewCurrent[$area_key]    += $jew_cur;
        $sumDpMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumDpJewPrevious[$area_key]   += $jew_prev;

        // Cell Mapping
        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Write Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Write Values
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Red color for negative performance
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- DOMESTIC PARTNERS TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumDpMlfsiCurrent[$area_key];
    $sc_jew   = $sumDpJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumDpMlfsiPrevious[$area_key];
    $sp_jew   = $sumDpJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'Domestic Partners Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;


// --- POS COMMISSION SECTION (Single Total Row) ---
$sumPosMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumPosJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumPosMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumPosJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $key = 'pos_comm'; // Direct key access

    // Data Fetching
    $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
    $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
    $total_cur  = $mlfsi_cur + $jew_cur;

    $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
    $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    $total_prev = $mlfsi_prev + $jew_prev;

    $diff = $total_cur - $total_prev;
    $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

    // Accumulate for Grand Totals
    $sumPosMlfsiCurrent[$area_key]  += $mlfsi_cur;
    $sumPosJewCurrent[$area_key]    += $jew_cur;
    $sumPosMlfsiPrevious[$area_key] += $mlfsi_prev;
    $sumPosJewPrevious[$area_key]   += $jew_prev;

    // Cell Mapping
    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row; // Column 2
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row; // Column 4
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row; // Column 15

    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;
    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    // Set Label & Bold
    $sheet->setCellValue($labelCell, 'POS Commission Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    // Black separators
    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Current Year Data
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $mlfsi_cur);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
    $sheet->setCellValue($curTotalCell, $total_cur);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    // Previous Year Data
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $mlfsi_prev);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
    $sheet->setCellValue($prevTotalCell, $total_prev);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    // Variance & Percentage
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    // Conditional Red
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    }
}
$row++;


// Mcash Income data rows + sums
$mcash_rows = [
    'Mcash Income - Operations' => 'mcash_op',
    'Mcash Income - H.O'        => 'mcash_ho',
];

$sumMcashMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumMcashJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumMcashMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumMcashJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($mcash_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumMcashMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumMcashJewCurrent[$area_key]    += $jew_cur;
        $sumMcashMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumMcashJewPrevious[$area_key]   += $jew_prev;

        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data injection
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Conditional red font
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- MCASH TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumMcashMlfsiCurrent[$area_key];
    $sc_jew   = $sumMcashJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumMcashMlfsiPrevious[$area_key];
    $sp_jew   = $sumMcashJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'Mcash Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;


// ML Express Income data rows + sums
$ml_express_rows = [
    'ML Express Income - Operations' => 'ml_express_op',
    'ML Express Income - H.O'        => 'ml_express_ho',
];

$sumMlExpMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumMlExpJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumMlExpMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumMlExpJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($ml_express_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate Totals
        $sumMlExpMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumMlExpJewCurrent[$area_key]    += $jew_cur;
        $sumMlExpMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumMlExpJewPrevious[$area_key]   += $jew_prev;

        // Cell Mapping
        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Set Values
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- ML EXPRESS TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumMlExpMlfsiCurrent[$area_key];
    $sc_jew   = $sumMlExpJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumMlExpMlfsiPrevious[$area_key];
    $sp_jew   = $sumMlExpJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;
    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'ML Express Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sc_mlfsi);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sp_mlfsi);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    }
}
$row++;


// --- ML EPAY COMMISSION SECTION (Single Total Row) ---
$sumEpayMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumEpayJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumEpayMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumEpayJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $key = 'epay'; // Direct key access

    // Data Fetching
    $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
    $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
    $total_cur  = $mlfsi_cur + $jew_cur;

    $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
    $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    $total_prev = $mlfsi_prev + $jew_prev;

    $diff = $total_cur - $total_prev;
    $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

    // Accumulate for Grand Totals
    $sumEpayMlfsiCurrent[$area_key]  += $mlfsi_cur;
    $sumEpayJewCurrent[$area_key]    += $jew_cur;
    $sumEpayMlfsiPrevious[$area_key] += $mlfsi_prev;
    $sumEpayJewPrevious[$area_key]   += $jew_prev;

    // Cell Mapping
    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row; // Column 2
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row; // Column 4
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row; // Column 15

    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;
    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    // Set Label & Style
    $sheet->setCellValue($labelCell, 'ML EPay Commission Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    // Black separators
    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Current Year Data
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $mlfsi_cur);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
    $sheet->setCellValue($curTotalCell, $total_cur);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    // Previous Year Data
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $mlfsi_prev);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
    $sheet->setCellValue($prevTotalCell, $total_prev);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    // Variance
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    // Conditional Red
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    }
}
$row++;



// Corporate Partners data rows + sums
$corporate_partners_rows = [
    'Total Corporate Partners'    => 'total_corp_partners',
    'Corporate Commissions - H.O' => 'corp_comm_ho',
];

$sumCorpMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumCorpJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumCorpMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumCorpJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($corporate_partners_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumCorpMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumCorpJewCurrent[$area_key]    += $jew_cur;
        $sumCorpMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumCorpJewPrevious[$area_key]   += $jew_prev;

        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        $mlfsiCurCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
        $jewCurCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
        $totalCurCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

        $mlfsiPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
        $jewPrevCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
        $totalPrevCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

        $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        // Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data injection
        $sheet->setCellValue($mlfsiCurCell, $mlfsi_cur);
        $sheet->setCellValue($jewCurCell, $jew_cur);
        $sheet->setCellValue($totalCurCell, $total_cur);
        $sheet->getStyle($totalCurCell)->getFont()->setBold(true);

        $sheet->setCellValue($mlfsiPrevCell, $mlfsi_prev);
        $sheet->setCellValue($jewPrevCell, $jew_prev);
        $sheet->setCellValue($totalPrevCell, $total_prev);
        $sheet->getStyle($totalPrevCell)->getFont()->setBold(true);

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        // Conditional red font
        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- CORPORATE PARTNERS TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumCorpMlfsiCurrent[$area_key];
    $sc_jew   = $sumCorpJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumCorpMlfsiPrevious[$area_key];
    $sp_jew   = $sumCorpJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
    $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $curMlfsiCell   = Coordinate::stringFromColumnIndex($baseCol + 4) . $row;
    $curJewCell     = Coordinate::stringFromColumnIndex($baseCol + 5) . $row;
    $curTotalCell   = Coordinate::stringFromColumnIndex($baseCol + 6) . $row;

    $prevMlfsiCell  = Coordinate::stringFromColumnIndex($baseCol + 8) . $row;
    $prevJewCell    = Coordinate::stringFromColumnIndex($baseCol + 9) . $row;
    $prevTotalCell  = Coordinate::stringFromColumnIndex($baseCol + 10) . $row;

    $diffCell       = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell        = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

    $sheet->setCellValue($labelCell, 'Corporate Partners Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue($curMlfsiCell, $sc_mlfsi);
    $sheet->setCellValue($curJewCell, $sc_jew);
    $sheet->setCellValue($curTotalCell, $sc_total);
    $sheet->getStyle($curTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($prevMlfsiCell, $sp_mlfsi);
    $sheet->setCellValue($prevJewCell, $sp_jew);
    $sheet->setCellValue($prevTotalCell, $sp_total);
    $sheet->getStyle($prevTotalCell)->getFont()->setBold(true);

    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;


// --- BILLS PAYMENT SECTION ---
$billspayment_rows = [
    'Total Bills Payment'            => 'total_billspayment',
    'Billspayment Commissions - H.O' => 'billspayment_comm_ho',
];

$sumBpMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumBpJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumBpMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumBpJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($billspayment_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate Totals
        $sumBpMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumBpJewCurrent[$area_key]    += $jew_cur;
        $sumBpMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumBpJewPrevious[$area_key]   += $jew_prev;

        $labelCell      = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $blackLeftCell  = Coordinate::stringFromColumnIndex($baseCol + 3) . $row;
        $blackRightCell = Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

        // Set Label
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle($blackLeftCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle($blackRightCell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data injection
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $mlfsi_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $total_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $mlfsi_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $total_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;

        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- BILLS PAYMENT TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumBpMlfsiCurrent[$area_key] + $sumBpJewCurrent[$area_key];
    $sp_total = $sumBpMlfsiPrevious[$area_key] + $sumBpJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Bills Payment Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumBpMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumBpJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumBpMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumBpJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;



// --- INSURANCE COMMISSION SECTION ---
$insurance_commission_rows = [
    'Commission MLi Pawner Protect'      => 'comm_mli_pawner_protect',
    'Commission MLi PPlus'               => 'comm_mli_pplus',
    'Commission MLi OFW Individual'      => 'comm_mli_ofw',
    'Commission MLi Family Protect'      => 'comm_mli_fam',
    'Commission MLi KP Protect'          => 'comm_mli_kp',
    'Commission MLi Pinoy Protect Plus'  => 'comm_mli_pinoy',
    'Commission MLi Family Protect Plus' => 'comm_mli_fam_plus',
    'Commission - MAA PP Insurance'      => 'comm_maa_pp',
    'Commission - MAA FP Insurance'      => 'comm_maa_fp',
    'Commission - MAA PPP Insurance'     => 'comm_maa_ppp',
    'Commission - MAA PPPF Insurance'    => 'comm_maa_pppf',
    'Commission - MAA FPP Insurance'     => 'comm_maa_fpp',
    'Commission - MAA FPPT Insurance'    => 'comm_maa_fppt',
    'Commission - MAA KPP Insurance'     => 'comm_maa_kpp',
    'Commission - PARA PP Insurance'     => 'comm_para_pp',
    'Commission - PARA FP Insurance'     => 'comm_para_fp',
    'Commission - PARA PPP Insurance'    => 'comm_para_ppp',
    'Commission - PARA FPP Insurance'    => 'comm_para_fpp',
    'Commission - PARA KPP Insurance'    => 'comm_para_kpp',
    'Commission - MALA PP Insurance'     => 'comm_mala_pp',
    'Commission - MALA FP Insurance'     => 'comm_mala_fp',
    'Commission - MALA PPP Insurance'    => 'comm_mala_ppp',
    'Commission - MALA FPP Insurance'    => 'comm_mala_fpp',
    'Commission - MALA KPP Insurance'    => 'comm_mala_kpp',
    'Commission - Dengue 150'            => 'comm_deng_150',
    'Commission - Dengue 500'            => 'comm_deng_500',
    'Commission - CTPL'                  => 'comm_ctpl',
    'Commission - GTTP Local'            => 'comm_gttp_local',
    'Commission - GTTP International'    => 'comm_gttp_intl',
    'Commission - OFW'                   => 'comm_ofw',
    'Commission - Comprehensive Insurance' => 'comm_compre_insu',
    'Commission - ER Guard'              => 'comm_er_guard',
    'Commission - ER Guard Plus'         => 'comm_er_guard_plus',
    'Commission - Mediphone'             => 'comm_mediphone',
    'Commission - MAA PP5'               => 'comm_maa_pp5',
    'Commission - MAA FP10'              => 'comm_maa_fp10',
    'Commission - MALA PP5'              => 'comm_mala_pp5',
    'Commission - MALA FP10'             => 'comm_mala_fp10',
    'ML General Insurance Commission'    => 'ml_gen_insu_comm',
    'MAA Customers Protect 20 Commission' => 'maa_cust_protect_20',
    'MICO Customers Protect 40 Commission' => 'mico_cust_protect_40',
    'OKDOK Quarterly Plan Commission'    => 'okdok_quarterly',
    'OKDOK Annual Plan Commission'       => 'okdok_annual',
    'Moskibite Commission'               => 'moskibite',
    'OKDOK Monthly Plan Commission'      => 'okdok_monthly',
    'CTPL Ins. Commission'               => 'ctpl_insu',
    'PhilLife Commission'                => 'phillife',
    'PHILAM LIFE Insurance - H.O'        => 'philam_life',
    'Group Personal Accident - H.O'      => 'group_personal_accident_ho',
];

$sumInsMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumInsJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumInsMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumInsJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($insurance_commission_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumInsMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumInsJewCurrent[$area_key]    += $jew_cur;
        $sumInsMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumInsJewPrevious[$area_key]   += $jew_prev;

        $labelCell = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black Bars
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $mlfsi_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $total_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $mlfsi_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $total_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- INSURANCE TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumInsMlfsiCurrent[$area_key] + $sumInsJewCurrent[$area_key];
    $sp_total = $sumInsMlfsiPrevious[$area_key] + $sumInsJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Insurance Commission Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumInsMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumInsJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumInsMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumInsJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;



// --- JEWELRY SECTION ---
$jewelry_rows = [
    'Sales from Jewelry'            => 'sales_jewelry',
    'Less: Sales Return & Discount' => 'less_sales_return_discount',
];

$sumJwlMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumJwlJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumJwlMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumJwlJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($jewelry_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        // 1. Data Retrieval
        $ml_cur   = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur  = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;

        // 2. Accumulation (Raw values for correct math)
        $sumJwlMlfsiCurrent[$area_key]  += $ml_cur;
        $sumJwlJewCurrent[$area_key]    += $jew_cur;
        $sumJwlMlfsiPrevious[$area_key] += $ml_prev;
        $sumJwlJewPrevious[$area_key]   += $jew_prev;

        $total_cur  = $ml_cur + $jew_cur;
        $total_prev = $ml_prev + $jew_prev;
        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // 3. Display Logic (Absolute values for "Less" row)
        $is_discount_row = ($key === 'less_sales_return_discount');
        $show_ml_cur   = $is_discount_row ? abs($ml_cur) : $ml_cur;
        $show_jew_cur  = $is_discount_row ? abs($jew_cur) : $jew_cur;
        $show_tot_cur  = $is_discount_row ? abs($total_cur) : $total_cur;
        
        $show_ml_prev  = $is_discount_row ? abs($ml_prev) : $ml_prev;
        $show_jew_prev = $is_discount_row ? abs($jew_prev) : $jew_prev;
        $show_tot_prev = $is_discount_row ? abs($total_prev) : $total_prev;

        // 4. Cell Mapping & Writing
        $labelCell = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $sheet->setCellValue($labelCell, $label);
        
        // Black bars
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Current Year Values
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $show_ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $show_jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $show_tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        // Previous Year Values
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $show_ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $show_jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $show_tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        // Variance & Pct
        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- JEWELRY TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_mlfsi = $sumJwlMlfsiCurrent[$area_key];
    $sc_jew   = $sumJwlJewCurrent[$area_key];
    $sc_total = $sc_mlfsi + $sc_jew;
    $sp_mlfsi = $sumJwlMlfsiPrevious[$area_key];
    $sp_jew   = $sumJwlJewPrevious[$area_key];
    $sp_total = $sp_mlfsi + $sp_jew;
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Income from Jewelry');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sc_mlfsi);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sc_jew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sp_mlfsi);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sp_jew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    }
}
$row++;



// --- SPECIAL PRODUCTS SECTION ---
$special_prod_rows = [
    'Dried Fruits'           => 'dried_fruits',
    'Other Special Products' => 'other_spec_prod',
];

$sumSpecMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumSpecJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumSpecMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumSpecJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($special_prod_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumSpecMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumSpecJewCurrent[$area_key]    += $jew_cur;
        $sumSpecMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumSpecJewPrevious[$area_key]   += $jew_prev;

        $labelCell = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data injection
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $mlfsi_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $total_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $mlfsi_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $total_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- SPECIAL PRODUCTS TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumSpecMlfsiCurrent[$area_key] + $sumSpecJewCurrent[$area_key];
    $sp_total = $sumSpecMlfsiPrevious[$area_key] + $sumSpecJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Income from Special Products');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumSpecMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumSpecJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumSpecMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumSpecJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    }
}
$row++;


// --- TELECOMMUNICATION SECTION ---
$telecom_rows = [
    'Telecommunication'                      => 'telecom',
    'Discount on Purchase of Telecom - H.O' => 'discount_on_purchase_of_telecom_ho',
];

$sumTelMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumTelJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumTelMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumTelJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($telecom_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $mlfsi_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur    = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $total_cur  = $mlfsi_cur + $jew_cur;

        $mlfsi_prev = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev   = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $total_prev = $mlfsi_prev + $jew_prev;

        $diff = $total_cur - $total_prev;
        $pct  = $total_prev != 0 ? ($diff / $total_prev) * 100 : 0;

        // Accumulate
        $sumTelMlfsiCurrent[$area_key]  += $mlfsi_cur;
        $sumTelJewCurrent[$area_key]    += $jew_cur;
        $sumTelMlfsiPrevious[$area_key] += $mlfsi_prev;
        $sumTelJewPrevious[$area_key]   += $jew_prev;

        // Label
        $labelCell = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Current Year Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $mlfsi_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $total_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        // Previous Year Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $mlfsi_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $total_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        // Variance
        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- TELECOMMUNICATION TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumTelMlfsiCurrent[$area_key] + $sumTelJewCurrent[$area_key];
    $sp_total = $sumTelMlfsiPrevious[$area_key] + $sumTelJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Income from Telecommunication');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumTelMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumTelJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumTelMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumTelJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- OTHER SERVICES SECTION ---
$other_services_rows = [
    'Travel & Tours'              => 'travel_and_tours',
    'Travellers Commission - H.O' => 'travellers_comm_ho',
    'NSO'                         => 'nso',
];

$sumOtherMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumOtherJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumOtherMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumOtherJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($other_services_rows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) * 100 : 0;

        // Accumulate
        $sumOtherMlfsiCurrent[$area_key]  += $ml_cur;
        $sumOtherJewCurrent[$area_key]    += $jew_cur;
        $sumOtherMlfsiPrevious[$area_key] += $ml_prev;
        $sumOtherJewPrevious[$area_key]   += $jew_prev;

        // Write Label
        $labelCell = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $sheet->setCellValue($labelCell, $label);
        $sheet->getStyle($labelCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Black separators
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data (Current)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        // Data (Previous)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        // Variance
        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- OTHER SERVICES TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumOtherMlfsiCurrent[$area_key] + $sumOtherJewCurrent[$area_key];
    $sp_total = $sumOtherMlfsiPrevious[$area_key] + $sumOtherJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Income from Other Services');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumOtherMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumOtherJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumOtherMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumOtherJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;



// --- ML KARGO SECTION ---
$mlKargoRows = [
    'ML Kargo Padala'                  => 'ml_kargo_padala',
    'ML Kargo Padala Commission - H.O' => 'ml_kargo_padala_comm_ho',
];

$sumKargoMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumKargoJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumKargoMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumKargoJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($mlKargoRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) * 100 : 0;

        // Accumulate
        $sumKargoMlfsiCurrent[$area_key]  += $ml_cur;
        $sumKargoJewCurrent[$area_key]    += $jew_cur;
        $sumKargoMlfsiPrevious[$area_key] += $ml_prev;
        $sumKargoJewPrevious[$area_key]   += $jew_prev;

        // Label Column 3
        $labelCell = Coordinate::stringFromColumnIndex($baseCol + 2) . $row;
        $sheet->setCellValue($labelCell, $label);

        // Black Columns (4 and 15)
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data Entry
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        // Variance
        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- ML KARGO TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumKargoMlfsiCurrent[$area_key] + $sumKargoJewCurrent[$area_key];
    $sp_total = $sumKargoMlfsiPrevious[$area_key] + $sumKargoJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Income from ML Kargo Padala');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumKargoMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumKargoJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumKargoMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumKargoJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;



// --- OTHER INCOME SECTION ---
$otherIncomeRows = [
    'Gain/Loss in MC-FX Currency'                 => 'gain_loss_mcfx',
    'Forex Share from Corporate Partners'         => 'forex_from_corp',
    'Dollars Sold - H.O'                          => 'dollars_sold_ho',
    'Interest in Bank'                            => 'interest_in_bank',
    'PLDT Dividend'                               => 'pldt_dividend',
    'Rental Income'                               => 'rental_income',
    'Rental Income - H.O'                         => 'rental_income_ho',
    'Other Income'                                => 'other_income',
    'Awards/Prizes - H.O'                         => 'awards_prizes_ho',
    'Towing Fee'                                  => 'towing_fee',
    'Credit Surcharge Sales'                      => 'credit_surcharge',
    'Gain/Loss Sales Account'                     => 'gain_loss_sales_acc',
    'Scrap Gold Bar - H.O'                        => 'scrap_gold_bar_ho',
    'St. Peter Life Plan - H.O'                   => 'stpeter_life_plan_ho',
    'MAA Insurance - Loss on Robbery Claims'       => 'maa_insu_robbery',
    'MAA Insurance Commission - H.O'              => 'maa_insu_comm_ho',
    'MMD SALES - H.O'                             => 'mmd_sales_ho',
    'Richmedia LED Billboard Rental Income - H.O' => 'richmedia_ho',
    'La Vie Parisienne Rental Income - H.O'       => 'lavie_ho',
    'ML Express Franchise Fee - H.O'              => 'ml_express_francise_ho',
    'Interest Income from Monique Lhuillier'      => 'interest_monique',
    'Workbench Service Income'                    => 'workbench',
    'ML Shop - Jewelry sales'                     => 'mlshop_jewelry',
    'ML Shop - OPI Sales'                         => 'mlshop_opi',
];

$sumOtherIncMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumOtherIncJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumOtherIncMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumOtherIncJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($otherIncomeRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) * 100 : 0;

        // Accumulate
        $sumOtherIncMlfsiCurrent[$area_key]  += $ml_cur;
        $sumOtherIncJewCurrent[$area_key]    += $jew_cur;
        $sumOtherIncMlfsiPrevious[$area_key] += $ml_prev;
        $sumOtherIncJewPrevious[$area_key]   += $jew_prev;

        // Label
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);

        // Black separators
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Current Year Values
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        // Previous Year Values
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        // Variance
        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- TOTAL OTHER INCOME ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumOtherIncMlfsiCurrent[$area_key] + $sumOtherIncJewCurrent[$area_key];
    $sp_total = $sumOtherIncMlfsiPrevious[$area_key] + $sumOtherIncJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'Total Other Income');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumOtherIncMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumOtherIncJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumOtherIncMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumOtherIncJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- TOTAL REVENUES SECTION (EXCEL) ---

// Define the comprehensive list of income keys as per your updated logic
$income_keys = [
    'total_interest_income', 'service_charge', 'liquidated_damages', 'gain_loss', 'storage_fee', 'appraisal', 'counter',
    'motor_loan', 'motor_loan_ho', 'interest_income_mlautoloan', 'car_loan_ho', 'appli_fee_mlautoloan', 'apprai_fee_mlautoloan',
    'pen_and_other_charges_mlautoloan', 'chattel_mort_income_mlautoloan', 'notarial_income',
    'interest_income_mlhomeloan', 'real_property_ho', 'apprai_fee_mlhomeloan', 'pen_and_other_charges_mlhomeloan',
    'chattel_mort_income_mlhomeloan', 'notarial_income_mlhomeloan',
    'interest_income_ste_sbl', 'sbl', 'penalty_fee_mlsbl', 'interest_income_ml_pen_loan', 'service_fees_ml_pen_loan',
    'kp_regular', 'kp_to_go', 'kp_other_income', 'kp_cert_fee', 'kiosk_mt', 'kp_sendout_spec_acc_ho',
    'payment_solution', 'express_pay', 'bpi_to_cash', 'lbc', 'rural_net', 'prime_bread', 'starpay', 'gcash_comm', 'domestic_partner_ho',
    'pos_comm', 'mcash_op', 'mcash_ho', 'ml_express_op', 'ml_express_ho', 'epay', 'total_corp_partners', 'corp_comm_ho',
    'total_billspayment', 'billspayment_comm_ho', 'comm_mli_pawner_protect', 'comm_mli_pplus', 'comm_mli_ofw', 'comm_mli_fam', 
    'comm_mli_kp', 'comm_mli_pinoy', 'comm_mli_fam_plus', 'comm_maa_pp', 'comm_maa_fp', 'comm_maa_ppp', 'comm_maa_pppf', 
    'comm_maa_fpp', 'comm_maa_fppt', 'comm_maa_kpp', 'comm_para_pp', 'comm_para_fp', 'comm_para_ppp', 'comm_para_fpp', 
    'comm_para_kpp', 'comm_mala_pp', 'comm_mala_fp', 'comm_mala_ppp', 'comm_mala_fpp', 'comm_mala_kpp', 'comm_deng_150', 
    'comm_deng_500', 'comm_ctpl', 'comm_gttp_local', 'comm_gttp_intl', 'comm_ofw', 'comm_compre_insu', 'comm_er_guard', 
    'comm_er_guard_plus', 'comm_mediphone', 'comm_maa_pp5', 'comm_maa_fp10', 'comm_mala_pp5', 'comm_mala_fp10', 
    'ml_gen_insu_comm', 'maa_cust_protect_20', 'mico_cust_protect_40', 'okdok_quarterly', 'okdok_annual', 'moskibite', 
    'okdok_monthly', 'ctpl_insu', 'phillife', 'philam_life', 'group_personal_accident_ho', 'sales_jewelry', 
    'less_sales_return_discount', 'dried_fruits', 'other_spec_prod', 'telecom', 'discount_on_purchase_of_telecom_ho', 
    'travel_and_tours', 'travellers_comm_ho', 'nso', 'ml_kargo_padala', 'ml_kargo_padala_comm_ho', 'gain_loss_mcfx', 
    'forex_from_corp', 'dollars_sold_ho', 'interest_in_bank', 'pldt_dividend', 'rental_income', 'rental_income_ho', 
    'other_income', 'awards_prizes_ho', 'towing_fee', 'credit_surcharge', 'gain_loss_sales_acc', 'scrap_gold_bar_ho', 
    'stpeter_life_plan_ho', 'maa_insu_robbery', 'maa_insu_comm_ho', 'mmd_sales_ho', 'richmedia_ho', 'lavie_ho', 
    'ml_express_francise_ho', 'interest_monique', 'workbench', 'mlshop_jewelry', 'mlshop_opi'
];

// Initialize storage for Gross Profit calculations later
$areaRevMlfsiCur = []; $areaRevJewCur = []; $areaRevTotalCur = [];
$areaRevMlfsiPrev = []; $areaRevJewPrev = []; $areaRevTotalPrev = [];

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // Initialize area-specific totals
    $areaTotalCurMlfsi  = 0;
    $areaTotalCurJew    = 0;
    $areaTotalPrevMlfsi = 0;
    $areaTotalPrevJew   = 0;

    // Summing logic based on the $totals array and $income_keys
    foreach ($income_keys as $key) {
        $areaTotalCurMlfsi  += $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $areaTotalPrevMlfsi += $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $areaTotalCurJew    += $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $areaTotalPrevJew   += $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    }

    $areaTotalCur  = $areaTotalCurMlfsi + $areaTotalCurJew;
    $areaTotalPrev = $areaTotalPrevMlfsi + $areaTotalPrevJew;
    $areaDiff      = $areaTotalCur - $areaTotalPrev;
    $areaPct       = $areaTotalPrev != 0 ? ($areaDiff / $areaTotalPrev) * 100 : 0;

    // --- STORE FOR GROSS PROFIT CALCULATION ---
    $areaRevMlfsiCur[$area_key]  = $areaTotalCurMlfsi;
    $areaRevJewCur[$area_key]    = $areaTotalCurJew;
    $areaRevTotalCur[$area_key]  = $areaTotalCur;
    $areaRevMlfsiPrev[$area_key] = $areaTotalPrevMlfsi;
    $areaRevJewPrev[$area_key]   = $areaTotalPrevJew;
    $areaRevTotalPrev[$area_key] = $areaTotalPrev;

    // --- WRITING TO EXCEL ---
    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 0) . $row;
    $sheet->setCellValue($labelCell, 'TOTAL REVENUES');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    // Black spacers
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $areaTotalCurMlfsi);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $areaTotalCurJew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $areaTotalCur);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $areaTotalPrevMlfsi);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $areaTotalPrevJew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $areaTotalPrev);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    
    $sheet->setCellValue($diffCell, $areaDiff);
    $sheet->setCellValue($pctCell, $areaPct / 100); // Excel percentage format expects decimal

    // Formatting
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 4) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row)->getFont()->setBold(true);

    if ($areaDiff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($areaPct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- FINAL SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;

// --- COST OF SALES/SERVICE HEADER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $range = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 14) . $row;

    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_BLACK]],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF8408']],
    ]);

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 1) . $row, 'Cost of Sales/Service');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;

// --- COST OF SALES ITEMS ---
$costOfSalesRows = [
    'Jewelry'           => 'jewelry',
    'Special Products'  => 'special_prod',
    'Telecommunication' => 'telecommunication',
    'ML Kargo'          => 'ml_kargo',
];

$sumCostMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumCostJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumCostMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumCostJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($costOfSalesRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) * 100 : 0;

        $sumCostMlfsiCurrent[$area_key]  += $ml_cur;
        $sumCostJewCurrent[$area_key]    += $jew_cur;
        $sumCostMlfsiPrevious[$area_key] += $ml_prev;
        $sumCostJewPrevious[$area_key]   += $jew_prev;

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- COST OF SALES TOTAL ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sc_total = $sumCostMlfsiCurrent[$area_key] + $sumCostJewCurrent[$area_key];
    $sp_total = $sumCostMlfsiPrevious[$area_key] + $sumCostJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    $labelCell = Coordinate::stringFromColumnIndex($baseCol) . $row;
    $sheet->setCellValue($labelCell, 'Cost of Sales / Service');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);
    
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumCostMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumCostJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumCostMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumCostJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 4) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row)->getFont()->setBold(true);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;

// --- GROSS PROFIT SECTION ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 1. Recalculate Revenue (MLFSI and JEWELERS) for Current and Previous years
    // Note: If you already ran the 'TOTAL REVENUES' loop and kept the $areaRev... arrays,
    // you can use those. Otherwise, we recalculate here for accuracy:
    $area_rev_current_mlfsi  = 0;
    $area_rev_current_jew    = 0;
    $area_rev_previous_mlfsi = 0;
    $area_rev_previous_jew   = 0;

    foreach ($income_keys as $key) {
        $area_rev_current_mlfsi  += $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $area_rev_previous_mlfsi += $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $area_rev_current_jew    += $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $area_rev_previous_jew   += $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    }

    $area_rev_current  = $area_rev_current_mlfsi + $area_rev_current_jew;
    $area_rev_previous = $area_rev_previous_mlfsi + $area_rev_previous_jew;

    // 2. Calculate Gross Profit (Revenue - Cost)
    $gc_ml  = $area_rev_current_mlfsi - ($sumCostMlfsiCurrent[$area_key] ?? 0);
    $gc_jew = $area_rev_current_jew   - ($sumCostJewCurrent[$area_key] ?? 0);
    $gc_tot = $area_rev_current - (($sumCostMlfsiCurrent[$area_key] ?? 0) + ($sumCostJewCurrent[$area_key] ?? 0));

    $gp_ml  = $area_rev_previous_mlfsi - ($sumCostMlfsiPrevious[$area_key] ?? 0);
    $gp_jew = $area_rev_previous_jew   - ($sumCostJewPrevious[$area_key] ?? 0);
    $gp_tot = $area_rev_previous - (($sumCostMlfsiPrevious[$area_key] ?? 0) + ($sumCostJewPrevious[$area_key] ?? 0));

    // 3. Difference and Percentage
    $diff = $gc_tot - $gp_tot;
    $pct  = $gp_tot != 0 ? ($diff / $gp_tot) : 0;

    // --- WRITING TO EXCEL ---
    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 0) . $row;
    $sheet->setCellValue($labelCell, 'GROSS PROFIT');
    $sheet->getStyle($labelCell)->getFont()->setBold(true);

    // Current Year Columns
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $gc_ml);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $gc_jew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $gc_tot);

    // Previous Year Columns
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $gp_ml);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $gp_jew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $gp_tot);
    
    // Growth/Variance Columns
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct); // Excel will handle the % formatting

    // Styling: Bold and Black Spacers
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 4) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row)->getFont()->setBold(true);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Negative Value Highlighting (Red)
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;
// --- 3. FINAL SPACER AND SELLING & ADMIN HEADER ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    
    // Black bar spacer
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;

// Orange Selling & Admin Header
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $range = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 14) . $row;
    
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF8408']]
    ]);
    
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol) . $row, 'SELLING & ADMIN EXPENSE');
    
    // Maintain black bars
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->getStartColor()->setARGB('000000');
}
$row++;

// sapacer
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    
    // Black bar spacer
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- PERSONNEL EXPENSES SECTION ---
$personnelExpensesRows = [
    'Salaries & Wages' => 'salaries_wages',
    'Staff Benefits'   => 'staff_benefits',
    'SSS/EC Benefits'  => 'sss_ec',
    'Philhealth'       => 'philhealth',
    'Pag-Ibig Expense' => 'pagibig_expense',
    'A.L.L. Bonus'     => 'all_bonus',
    '13th Month Pay'   => '13th_month',
];

$sumPersMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumPersJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumPersMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumPersJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($personnelExpensesRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) * 100 : 0;

        // Accumulate for Summary
        $sumPersMlfsiCurrent[$area_key]  += $ml_cur;
        $sumPersJewCurrent[$area_key]    += $jew_cur;
        $sumPersMlfsiPrevious[$area_key] += $ml_prev;
        $sumPersJewPrevious[$area_key]   += $jew_prev;

        // Label (Column 3)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);

        // Black Spacer Columns
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data Entry
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct / 100);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- TOTAL PERSONNEL EXPENSES ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumPersMlfsiCurrent[$area_key] + $sumPersJewCurrent[$area_key];
    $sp_total = $sumPersMlfsiPrevious[$area_key] + $sumPersJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) * 100 : 0;

    // Summary Label (Column 2)
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 1) . $row, 'Total Personnel Expenses');

    // Black Spacers
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Totals
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumPersMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumPersJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumPersMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumPersJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);

    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct / 100);

    // Bold the entire summary row
    $range = Coordinate::stringFromColumnIndex($baseCol + 1) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($range)->getFont()->setBold(true);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- ADMINISTRATIVE EXPENSES SECTION ---
$adminExpenseRows = [
    'Management & Prof. Fee'               => 'management_prof',
    'Taxes & Licences'                     => 'taxes_licenses',
    'Utilities'                            => 'utilities',
    'Communication Expenses'               => 'communication_expenses',
    'Stationeries & Office Supplies'       => 'stationaries',
    'Repair & Maintenance'                 => 'repair',
    'Rent Expense'                         => 'rent_expense',
    'Sec. Messenger & Janitorial'          => 'sec_messenger',
    'Transportation Expense'               => 'transportation_expense',
    'Delivery & Freight Charges'           => 'delivery',
    'Travelling Expense'                   => 'travelling_expense',
    'Fuel & Oil Expense'                   => 'fuel_oil',
    'Other Charges'                        => 'other_charges',
    'Advertising & Promotion'               => 'advertising',
    'Rep. & Entertainment Exp'             => 'rep_entertainment',
    'Store, Appraisal & Cleaning Supplies' => 'store',
    "Finder's Fee"                         => 'finders_fee',
    'Insurance Incentive'                  => 'insu_incentive',
    'Insurance Expense'                    => 'insu_expense',
    'Miscellaneous Expense'                => 'miscellaneous',
    'Software Maintenance'                 => 'software',
    'Loss on Robbery/Fire/Theft'           => 'loss_robbery',
    'H.O Expense'                          => 'ho_expense',
    'Bad Debts Expense H.O'                => 'bad_debts_ho',
    'Agent Share H.O'                      => 'agent_share_ho',
];

$sumAdminMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumAdminJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumAdminMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumAdminJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($adminExpenseRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        // Data Retrieval
        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) : 0;

        // Accumulate
        $sumAdminMlfsiCurrent[$area_key]  += $ml_cur;
        $sumAdminJewCurrent[$area_key]    += $jew_cur;
        $sumAdminMlfsiPrevious[$area_key] += $ml_prev;
        $sumAdminJewPrevious[$area_key]   += $jew_prev;

        // Label (Column 3)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);

        // Black Columns
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Current Year Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        // Previous Year Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        // Variance
        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- TOTAL ADMINISTRATIVE EXPENSES ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    $sc_total = $sumAdminMlfsiCurrent[$area_key] + $sumAdminJewCurrent[$area_key];
    $sp_total = $sumAdminMlfsiPrevious[$area_key] + $sumAdminJewPrevious[$area_key];
    $diff = $sc_total - $sp_total;
    $pct  = $sp_total != 0 ? ($diff / $sp_total) : 0;

    // Summary Label (Column 2)
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 1) . $row, 'Total Administrative Expenses');

    // Black Columns
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Totals Current
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumAdminMlfsiCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumAdminJewCurrent[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);

    // Totals Previous
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumAdminMlfsiPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumAdminJewPrevious[$area_key]);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);

    // Variance
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct);

    // Style Summary Row
    $range = Coordinate::stringFromColumnIndex($baseCol + 1) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($range)->getFont()->setBold(true);

    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- TOTAL SELLING AND ADMIN EXPENSES SECTION ---

// Initialize storage for Net Income calculations
$sellingAdminMlfsiCur = []; $sellingAdminJewCur = []; $sellingAdminTotalCur = [];
$sellingAdminMlfsiPrev = []; $sellingAdminJewPrev = []; $sellingAdminTotalPrev = [];

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // Current Year: Personnel + Admin
    $cur_ml  = ($sumPersMlfsiCurrent[$area_key] ?? 0) + ($sumAdminMlfsiCurrent[$area_key] ?? 0);
    $cur_jew = ($sumPersJewCurrent[$area_key] ?? 0)   + ($sumAdminJewCurrent[$area_key] ?? 0);
    $cur_tot = $cur_ml + $cur_jew;

    // Previous Year: Personnel + Admin
    $prev_ml  = ($sumPersMlfsiPrevious[$area_key] ?? 0) + ($sumAdminMlfsiPrevious[$area_key] ?? 0);
    $prev_jew = ($sumPersJewPrevious[$area_key] ?? 0)   + ($sumAdminJewPrevious[$area_key] ?? 0);
    $prev_tot = $prev_ml + $prev_jew;

    // Store values for Net Income section
    $sellingAdminMlfsiCur[$area_key]  = $cur_ml;
    $sellingAdminJewCur[$area_key]    = $cur_jew;
    $sellingAdminTotalCur[$area_key]  = $cur_tot;
    $sellingAdminMlfsiPrev[$area_key] = $prev_ml;
    $sellingAdminJewPrev[$area_key]   = $prev_jew;
    $sellingAdminTotalPrev[$area_key] = $prev_tot;

    $diff = $cur_tot - $prev_tot;
    $pct  = $prev_tot != 0 ? ($diff / $prev_tot) : 0;

    // --- Excel Writing ---

    // Label (Column 1)
    $labelCell = Coordinate::stringFromColumnIndex($baseCol) . $row;
    $sheet->setCellValue($labelCell, 'TOTAL SELLING AND ADMIN EXPENSES');

    // Values Current
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $cur_ml);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $cur_jew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $cur_tot);

    // Values Previous
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $prev_ml);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $prev_jew);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $prev_tot);

    // Variance
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct);

    // --- Styling ---
    $rowRange = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($rowRange)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FFE9ECEF'] // Light gray background
        ]
    ]);

    // Apply red color for negative variance
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);

    // Maintain Black Bars
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;

// --- FINAL SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- EBITDA SECTION ---

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 1. RE-CALCULATE GROSS PROFIT (Revenue - Cost of Sales)
    // We use the variables stored from the previous Revenue section
    $gp_ml_cur  = ($areaRevMlfsiCur[$area_key] ?? 0)  - ($sumCostMlfsiCurrent[$area_key] ?? 0);
    $gp_jew_cur = ($areaRevJewCur[$area_key] ?? 0)    - ($sumCostJewCurrent[$area_key] ?? 0);
    $gp_tot_cur = $gp_ml_cur + $gp_jew_cur;

    $gp_ml_prev  = ($areaRevMlfsiPrev[$area_key] ?? 0) - ($sumCostMlfsiPrevious[$area_key] ?? 0);
    $gp_jew_prev = ($areaRevJewPrev[$area_key] ?? 0)   - ($sumCostJewPrevious[$area_key] ?? 0);
    $gp_tot_prev = $gp_ml_prev + $gp_jew_prev;

    // 2. CALCULATE EBITDA (Gross Profit - Total Selling & Admin)
    $e_ml_c  = $gp_ml_cur  - ($sellingAdminMlfsiCur[$area_key] ?? 0);
    $e_jew_c = $gp_jew_cur - ($sellingAdminJewCur[$area_key] ?? 0);
    $e_tot_c = $e_ml_c + $e_jew_c;

    $e_ml_p  = $gp_ml_prev  - ($sellingAdminMlfsiPrev[$area_key] ?? 0);
    $e_jew_p = $gp_jew_prev - ($sellingAdminJewPrev[$area_key] ?? 0);
    $e_tot_p = $e_ml_p + $e_jew_p;

    $diff = $e_tot_c - $e_tot_p;
    $pct  = $e_tot_p != 0 ? ($diff / $e_tot_p) : 0;

    // --- Excel Writing ---
    
    // Label (Column 1)
    $labelCell = Coordinate::stringFromColumnIndex($baseCol) . $row;
    $sheet->setCellValue($labelCell, "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT");

    // Current Year Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $e_ml_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $e_jew_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $e_tot_c);

    // Previous Year Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $e_ml_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $e_jew_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $e_tot_p);

    // Variance
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct);

    // --- Styling (Teal Background & Dark Borders) ---
    $rowRange = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($rowRange)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FFD1ECF1'] // Light teal
        ],
        'borders' => [
            'top'    => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF0C5460']],
            'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF0C5460']]
        ]
    ]);

    // Variance colors
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);

    // Overwrite black bars
   $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- DEPRECIATION AND AMORTIZATION SECTION ---
$deprAmortRows = [
    'Depreciation Expense' => 'depreciation',
    'Amortization Expense' => 'amortization',
];

$sumDAMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumDAJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumDAMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumDAJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($deprAmortRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        // Data Retrieval
        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) : 0;

        // Accumulate for Total Row
        $sumDAMlfsiCurrent[$area_key]  += $ml_cur;
        $sumDAJewCurrent[$area_key]    += $jew_cur;
        $sumDAMlfsiPrevious[$area_key] += $ml_prev;
        $sumDAJewPrevious[$area_key]   += $jew_prev;

        // Write Label (Column 3)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);

        // Black Spacer Columns
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Data Entry
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- SPACER ROW ---

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    
    // Only apply black fill to columns 4 and 15 (zero-indexed 3 and 14)
    $colsToFill = [$baseCol + 3, $baseCol + 14];
    foreach ($colsToFill as $c) {
        $cell = Coordinate::stringFromColumnIndex($c) . $row;
        $sheet->getStyle($cell)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('000000');
    }
}
$row++;

// // --- TOTAL DEPRECIATION & AMORTIZATION ROW ---
// foreach ($areas_to_display as $area_idx => $area_key) {
//     $baseCol = $col + ($area_idx * $columnsPerArea);

//     $sc_total = $sumDAMlfsiCurrent[$area_key] + $sumDAJewCurrent[$area_key];
//     $sp_total = $sumDAMlfsiPrevious[$area_key] + $sumDAJewPrevious[$area_key];
//     $diff = $sc_total - $sp_total;
//     $pct  = $sp_total != 0 ? ($diff / $sp_total) : 0;

//     // Total Label (Column 2)
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 1) . $row, 'Total Depreciation & Amortization');

//     // Black Spacers
//     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
//     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

//     // Summarized Values
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumDAMlfsiCurrent[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumDAJewCurrent[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumDAMlfsiPrevious[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumDAJewPrevious[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);

//     $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
//     $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
//     $sheet->setCellValue($diffCell, $diff);
//     $sheet->setCellValue($pctCell, $pct);

//     // Style the Summary Row
//     $range = Coordinate::stringFromColumnIndex($baseCol + 1) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
//     $sheet->getStyle($range)->getFont()->setBold(true);

//     if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
//     if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
// }
// $row++;

// // --- SPACER ROW ---
// foreach ($areas_to_display as $area_idx => $area_key) {
//     $baseCol = $col + ($area_idx * $columnsPerArea);
//     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
//     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
// }
//$row++;

// --- FINAL EBIT CALCULATION ROW: EARNINGS BEFORE INTEREST & TAXES ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 1. RECALCULATE REVENUES (Consistent with previous sections)
    $area_rev_c_ml  = 0; $area_rev_c_jew = 0;
    $area_rev_p_ml  = 0; $area_rev_p_jew = 0;

    foreach ($income_keys as $key) {
        $area_rev_c_ml  += $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $area_rev_p_ml  += $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $area_rev_c_jew += $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $area_rev_p_jew += $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    }

    // 2. GROSS PROFIT (Revenue - Cost)
    $gp_c_ml  = $area_rev_c_ml - ($sumCostMlfsiCurrent[$area_key] ?? 0);
    $gp_c_jew = $area_rev_c_jew - ($sumCostJewCurrent[$area_key] ?? 0);
    $gp_p_ml  = $area_rev_p_ml - ($sumCostMlfsiPrevious[$area_key] ?? 0);
    $gp_p_jew = $area_rev_p_jew - ($sumCostJewPrevious[$area_key] ?? 0);

    // 3. EBITDA (Gross Profit - Operating Expenses/Selling Admin)
    $ebitda_c_ml  = $gp_c_ml - ($sellingAdminMlfsiCur[$area_key] ?? 0);
    $ebitda_c_jew = $gp_c_jew - ($sellingAdminJewCur[$area_key] ?? 0);
    $ebitda_p_ml  = $gp_p_ml - ($sellingAdminMlfsiPrev[$area_key] ?? 0);
    $ebitda_p_jew = $gp_p_jew - ($sellingAdminJewPrev[$area_key] ?? 0);

    // 4. EBIT (EBITDA - Depreciation & Amortization)
    $final_ebit_ml_c  = $ebitda_c_ml - ($sumDAMlfsiCurrent[$area_key] ?? 0);
    $final_ebit_jew_c = $ebitda_c_jew - ($sumDAJewCurrent[$area_key] ?? 0);
    $final_ebit_tot_c = $final_ebit_ml_c + $final_ebit_jew_c;

    $final_ebit_ml_p  = $ebitda_p_ml - ($sumDAMlfsiPrevious[$area_key] ?? 0);
    $final_ebit_jew_p = $ebitda_p_jew - ($sumDAJewPrevious[$area_key] ?? 0);
    $final_ebit_tot_p = $final_ebit_ml_p + $final_ebit_jew_p;

    $diff = $final_ebit_tot_c - $final_ebit_tot_p;
    $pct  = $final_ebit_tot_p != 0 ? ($diff / $final_ebit_tot_p) : 0;

    // --- WRITE TO EXCEL ---
    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 0) . $row;
    $sheet->setCellValue($labelCell, "EARNINGS BEFORE INTEREST & TAXES");

    // Current Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $final_ebit_ml_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $final_ebit_jew_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $final_ebit_tot_c);

    // Previous Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $final_ebit_ml_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $final_ebit_jew_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $final_ebit_tot_p);

    // Variance
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct);

    // Black spacers
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Styling (Green background and bold to match HTML)
    $rowRange = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($rowRange)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD4EDDA']],
        'borders' => [
            'top' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['argb' => 'FF28A745']]
        ]
    ]);

    // Red font for negative growth
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;




// --- INTEREST EXPENSE SECTION ---
$interestRows = [
    'Interest Expense' => 'interest_expense',
];

$sumIntMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumIntJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumIntMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumIntJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($interestRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        // Data Retrieval
        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) : 0;

        // Accumulate
        $sumIntMlfsiCurrent[$area_key]  += $ml_cur;
        $sumIntJewCurrent[$area_key]    += $jew_cur;
        $sumIntMlfsiPrevious[$area_key] += $ml_prev;
        $sumIntJewPrevious[$area_key]   += $jew_prev;

        // Label (Column 3)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);

        // Black Spacer Columns
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Writing Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// // --- TOTAL INTEREST EXPENSE ROW ---
// foreach ($areas_to_display as $area_idx => $area_key) {
//     $baseCol = $col + ($area_idx * $columnsPerArea);

//     $sc_total = $sumIntMlfsiCurrent[$area_key] + $sumIntJewCurrent[$area_key];
//     $sp_total = $sumIntMlfsiPrevious[$area_key] + $sumIntJewPrevious[$area_key];
//     $diff = $sc_total - $sp_total;
//     $pct  = $sp_total != 0 ? ($diff / $sp_total) : 0;

//     // Total Label (Column 2)
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 1) . $row, 'Total Interest Expense');

//     // Styling & Black Spacers
//     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
//     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

//     // Values
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumIntMlfsiCurrent[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumIntJewCurrent[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumIntMlfsiPrevious[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumIntJewPrevious[$area_key]);
//     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);

//     $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
//     $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
//     $sheet->setCellValue($diffCell, $diff);
//     $sheet->setCellValue($pctCell, $pct);

//     // Apply Bold to the whole summary row for this area
//     $range = Coordinate::stringFromColumnIndex($baseCol + 1) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
//     $sheet->getStyle($range)->getFont()->setBold(true);

//     if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
//     if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
// }
// $row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;


// --- FINAL EBT CALCULATION ROW: EARNINGS BEFORE TAXES ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 1. RECALCULATE REVENUES
    $rev_c_ml = 0; $rev_c_jew = 0; $rev_p_ml = 0; $rev_p_jew = 0;
    foreach ($income_keys as $key) {
        $rev_c_ml  += $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $rev_p_ml  += $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $rev_c_jew += $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $rev_p_jew += $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    }

    // 2. GROSS PROFIT (Rev - Cost)
    $gp_c_ml  = $rev_c_ml - ($sumCostMlfsiCurrent[$area_key] ?? 0);
    $gp_c_jew = $rev_c_jew - ($sumCostJewCurrent[$area_key] ?? 0);
    $gp_p_ml  = $rev_p_ml - ($sumCostMlfsiPrevious[$area_key] ?? 0);
    $gp_p_jew = $rev_p_jew - ($sumCostJewPrevious[$area_key] ?? 0);

    // 3. EBITDA (GP - Operating/Selling Admin)
    $ebitda_c_ml  = $gp_c_ml - ($sellingAdminMlfsiCur[$area_key] ?? 0);
    $ebitda_c_jew = $gp_c_jew - ($sellingAdminJewCur[$area_key] ?? 0);
    $ebitda_p_ml  = $gp_p_ml - ($sellingAdminMlfsiPrev[$area_key] ?? 0);
    $ebitda_p_jew = $gp_p_jew - ($sellingAdminJewPrev[$area_key] ?? 0);

    // 4. EBIT (EBITDA - Depr/Amort)
    $ebit_c_ml = $ebitda_c_ml - ($sumDAMlfsiCurrent[$area_key] ?? 0);
    $ebit_c_jew = $ebitda_c_jew - ($sumDAJewCurrent[$area_key] ?? 0);
    $ebit_p_ml = $ebitda_p_ml - ($sumDAMlfsiPrevious[$area_key] ?? 0);
    $ebit_p_jew = $ebitda_p_jew - ($sumDAJewPrevious[$area_key] ?? 0);

    // 5. EBT (EBIT - Interest Expense)
    $final_ebt_ml_c  = $ebit_c_ml - ($sumIntMlfsiCurrent[$area_key] ?? 0);
    $final_ebt_jew_c = $ebit_c_jew - ($sumIntJewCurrent[$area_key] ?? 0);
    $final_ebt_tot_c = $final_ebt_ml_c + $final_ebt_jew_c;

    $final_ebt_ml_p  = $ebit_p_ml - ($sumIntMlfsiPrevious[$area_key] ?? 0);
    $final_ebt_jew_p = $ebit_p_jew - ($sumIntJewPrevious[$area_key] ?? 0);
    $final_ebt_tot_p = $final_ebt_ml_p + $final_ebt_jew_p;

    $diff = $final_ebt_tot_c - $final_ebt_tot_p;
    $pct  = $final_ebt_tot_p != 0 ? ($diff / $final_ebt_tot_p) : 0;

    // --- WRITE TO EXCEL ---
    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 0) . $row;
    $sheet->setCellValue($labelCell, "EARNINGS BEFORE TAXES");

    // Current Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $final_ebt_ml_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $final_ebt_jew_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $final_ebt_tot_c);

    // Previous Values
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $final_ebt_ml_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $final_ebt_jew_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $final_ebt_tot_p);
    
    // Variance
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct);

    // Black spacers
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

    // Styling (Yellow Background to match HTML total-row style)
    $rowRange = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($rowRange)->applyFromArray([
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FFFFF3CD'] // Light yellow
        ],
        'borders' => [
            'top'    => ['borderStyle' => Border::BORDER_THICK, 'color' => ['argb' => 'FFFFC107']], // Gold
            'bottom' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['argb' => 'FFFFC107']]
        ]
    ]);
    
    // Variance Colors (Red for negative)
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
}
$row++;




// --- PROVISION ON INCOME TAX SECTION ---
$taxRows = [
    'Provision on Income Tax' => 'provision',
];

$sumTaxMlfsiCurrent  = array_fill_keys($areas_to_display, 0);
$sumTaxJewCurrent    = array_fill_keys($areas_to_display, 0);
$sumTaxMlfsiPrevious = array_fill_keys($areas_to_display, 0);
$sumTaxJewPrevious   = array_fill_keys($areas_to_display, 0);

foreach ($taxRows as $label => $key) {
    foreach ($areas_to_display as $area_idx => $area_key) {
        $baseCol = $col + ($area_idx * $columnsPerArea);

        // Data Retrieval
        $ml_cur  = $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $jew_cur = $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $tot_cur = $ml_cur + $jew_cur;

        $ml_prev  = $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $jew_prev = $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
        $tot_prev = $ml_prev + $jew_prev;

        $diff = $tot_cur - $tot_prev;
        $pct  = $tot_prev != 0 ? ($diff / $tot_prev) : 0;

        // Accumulate for Total Row
        $sumTaxMlfsiCurrent[$area_key]  += $ml_cur;
        $sumTaxJewCurrent[$area_key]    += $jew_cur;
        $sumTaxMlfsiPrevious[$area_key] += $ml_prev;
        $sumTaxJewPrevious[$area_key]   += $jew_prev;

        // Label (Column 3)
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 2) . $row, $label);

        // Black Spacer Columns
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

        // Writing Data
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $ml_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $jew_cur);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $tot_cur);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 6) . $row)->getFont()->setBold(true);

        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $ml_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $jew_prev);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $tot_prev);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 10) . $row)->getFont()->setBold(true);

        $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
        $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
        $sheet->setCellValue($diffCell, $diff);
        $sheet->setCellValue($pctCell, $pct);

        if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    }
    $row++;
}

// --- TOTAL PROVISION ON INCOME TAX ROW ---
// // foreach ($areas_to_display as $area_idx => $area_key) {
// //     $baseCol = $col + ($area_idx * $columnsPerArea);

// //     $sc_total = $sumTaxMlfsiCurrent[$area_key] + $sumTaxJewCurrent[$area_key];
// //     $sp_total = $sumTaxMlfsiPrevious[$area_key] + $sumTaxJewPrevious[$area_key];
// //     $diff = $sc_total - $sp_total;
// //     $pct  = $sp_total != 0 ? ($diff / $sp_total) : 0;

// //     // Total Label (Column 2)
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 1) . $row, 'Total Provision on Income Tax');

// //     // Black Spacers
// //     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
// //     $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');

// //     // Values
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $sumTaxMlfsiCurrent[$area_key]);
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $sumTaxJewCurrent[$area_key]);
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $sc_total);
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $sumTaxMlfsiPrevious[$area_key]);
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $sumTaxJewPrevious[$area_key]);
// //     $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $sp_total);

// //     $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
// //     $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
// //     $sheet->setCellValue($diffCell, $diff);
// //     $sheet->setCellValue($pctCell, $pct);

// //     // Style the Summary Row
// //     $range = Coordinate::stringFromColumnIndex($baseCol + 1) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
// //     $sheet->getStyle($range)->getFont()->setBold(true);

// //     if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
// //     if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
// // }
// $row++;

// --- SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;



// --- FINAL NET INCOME CALCULATION ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // 1. RECALCULATE REVENUES
    $rev_c_ml = 0; $rev_c_jew = 0; $rev_p_ml = 0; $rev_p_jew = 0;
    foreach ($income_keys as $key) {
        $rev_c_ml  += $totals[$area_key]['mlfsi'][$key][$current_year] ?? 0;
        $rev_p_ml  += $totals[$area_key]['mlfsi'][$key][$previous_year] ?? 0;
        $rev_c_jew += $totals[$area_key]['jewelers'][$key][$current_year] ?? 0;
        $rev_p_jew += $totals[$area_key]['jewelers'][$key][$previous_year] ?? 0;
    }

    // 2. GROSS PROFIT (Rev - Cost)
    $gp_c_ml  = $rev_c_ml - ($sumCostMlfsiCurrent[$area_key] ?? 0);
    $gp_c_jew = $rev_c_jew - ($sumCostJewCurrent[$area_key] ?? 0);
    $gp_p_ml  = $rev_p_ml - ($sumCostMlfsiPrevious[$area_key] ?? 0);
    $gp_p_jew = $rev_p_jew - ($sumCostJewPrevious[$area_key] ?? 0);

    // 3. EBITDA (GP - Operating/Selling Admin)
    $ebitda_c_ml  = $gp_c_ml - ($sellingAdminMlfsiCur[$area_key] ?? 0);
    $ebitda_c_jew = $gp_c_jew - ($sellingAdminJewCur[$area_key] ?? 0);
    $ebitda_p_ml  = $gp_p_ml - ($sellingAdminMlfsiPrev[$area_key] ?? 0);
    $ebitda_p_jew = $gp_p_jew - ($sellingAdminJewPrev[$area_key] ?? 0);

    // 4. EBIT (EBITDA - Depr/Amort)
    $ebit_c_ml = $ebitda_c_ml - ($sumDAMlfsiCurrent[$area_key] ?? 0);
    $ebit_c_jew = $ebitda_c_jew - ($sumDAJewCurrent[$area_key] ?? 0);
    $ebit_p_ml = $ebitda_p_ml - ($sumDAMlfsiPrevious[$area_key] ?? 0);
    $ebit_p_jew = $ebitda_p_jew - ($sumDAJewPrevious[$area_key] ?? 0);

    // 5. EBT (EBIT - Interest)
    $ebt_c_ml = $ebit_c_ml - ($sumIntMlfsiCurrent[$area_key] ?? 0);
    $ebt_c_jew = $ebit_c_jew - ($sumIntJewCurrent[$area_key] ?? 0);
    $ebt_p_ml = $ebit_p_ml - ($sumIntMlfsiPrevious[$area_key] ?? 0);
    $ebt_p_jew = $ebit_p_jew - ($sumIntJewPrevious[$area_key] ?? 0);

    // 6. NET INCOME (EBT - Provision for Income Tax)
    $final_net_ml_c  = $ebt_c_ml - ($sumTaxMlfsiCurrent[$area_key] ?? 0);
    $final_net_jew_c = $ebt_c_jew - ($sumTaxJewCurrent[$area_key] ?? 0);
    $final_net_tot_c = $final_net_ml_c + $final_net_jew_c;

    $final_net_ml_p  = $ebt_p_ml - ($sumTaxMlfsiPrevious[$area_key] ?? 0);
    $final_net_jew_p = $ebt_p_jew - ($sumTaxJewPrevious[$area_key] ?? 0);
    $final_net_tot_p = $final_net_ml_p + $final_net_jew_p;

    $diff = $final_net_tot_c - $final_net_tot_p;
    $pct  = $final_net_tot_p != 0 ? ($diff / $final_net_tot_p) : 0;

    // --- WRITE TO EXCEL ---
    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 0) . $row;
    $sheet->setCellValue($labelCell, "Total Net Income / (Loss)");

    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 4) . $row, $final_net_ml_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 5) . $row, $final_net_jew_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $final_net_tot_c);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 8) . $row, $final_net_ml_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 9) . $row, $final_net_jew_p);
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 10) . $row, $final_net_tot_p);
    
    $diffCell = Coordinate::stringFromColumnIndex($baseCol + 12) . $row;
    $pctCell  = Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->setCellValue($diffCell, $diff);
    $sheet->setCellValue($pctCell, $pct);

    // Styling (Teal/Blue Background and Double Borders)
    $rowRange = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($rowRange)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FFD1ECF1'] // Light teal
        ],
        'borders' => [
            'top'    => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['argb' => 'FF0C5460']],
            'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['argb' => 'FF0C5460']]
        ]
    ]);
    
    // Variance Colors
    if ($diff < 0) $sheet->getStyle($diffCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);
    if ($pct < 0)  $sheet->getStyle($pctCell)->getFont()->getColor()->setARGB(Color::COLOR_RED);

    // Black spacers
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}
$row++;




// --- NO. OF BRANCHES SECTION ---

foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);

    // Label: "No. of Branches" (Placed in Column 2 to match your $col === 2 logic)
    $labelCell = Coordinate::stringFromColumnIndex($baseCol + 1) . $row;
    $sheet->setCellValue($labelCell, 'No. of Branches');

    // --- Styling the Row ---
    $rowRange = Coordinate::stringFromColumnIndex($baseCol) . $row . ':' . Coordinate::stringFromColumnIndex($baseCol + 13) . $row;
    $sheet->getStyle($rowRange)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['argb' => 'FF000000']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT
        ]
    ]);

    // Apply Black Bars (Column 4 and Column 15 in 1-based index = index 3 and 14)
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    
    // Note: If you have a variable for the actual branch count, 
    // you can set it here, e.g., $sheet->setCellValue(Coordinate::stringFromColumnIndex($baseCol + 6) . $row, $branchCount[$area_key]);
}
$row++;

// --- FINAL SPACER ROW ---
foreach ($areas_to_display as $area_idx => $area_key) {
    $baseCol = $col + ($area_idx * $columnsPerArea);
    
    // Maintain Black Bars in the empty spacer row
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 3) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
    $sheet->getStyle(Coordinate::stringFromColumnIndex($baseCol + 14) . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('000000');
}




$row++;
// === Styling: number formats, alignment, borders ===
$lastColLetter = Coordinate::stringFromColumnIndex($col + count($areas_to_display) * 15 - 1);
$tableRange = 'A' . $tableStartRow . ':' . $lastColLetter . ($row - 1);

// Number format for amount columns (5-7 and 9-11 per group)
foreach ($areas_to_display as $area_idx => $area_key) {
    $base = $col + ($area_idx * 15);
    $amountCols = [$base+4, $base+5, $base+6, $base+8, $base+9, $base+10, $base+12];
    foreach ($amountCols as $c) {
        $colLetter = Coordinate::stringFromColumnIndex($c);
        $sheet->getStyle($colLetter . $revenuesRow . ':' . $colLetter . ($row - 1))
            ->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($colLetter . $revenuesRow . ':' . $colLetter . ($row - 1))
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    // % column
    $pctCol = Coordinate::stringFromColumnIndex($base + 13);
    $sheet->getStyle($pctCol . $revenuesRow . ':' . $pctCol . ($row - 1))
        ->getNumberFormat()->setFormatCode('0.00%');
}

// Borders
$borderStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ],
    ],
];
$sheet->getStyle($tableRange)->applyFromArray($borderStyle);

$startColumns   = ['B', 'Q', 'AF', 'AU', 'BJ', 'BY'];
$columnsPerBlock = 15;
$defaultWidth   = 18;

$widthMap = [
    -1 => 3,   // A, P, AE, AT, BI, BX
     0 => 3,   // B, Q, AF, AU, BJ, BY
     1 => 50,  // C, R, AG, AV, BK, BZ
     2 => 1,
     6 => 1,
    10 => 1,
    13 => 1,
];

foreach ($startColumns as $startCol) {
    $startIndex = Coordinate::columnIndexFromString($startCol);

    // 1️⃣ Set ALL columns in the block to default width (15)
    for ($i = -1; $i < $columnsPerBlock; $i++) {
        $colIndex = $startIndex + $i;

        if ($colIndex < 1) {
            continue; // safety guard
        }

        $colLetter = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->getColumnDimension($colLetter)->setWidth($defaultWidth);
    }

    // 2️⃣ Override specific columns
    foreach ($widthMap as $offset => $width) {
        $colIndex = $startIndex + $offset;

        if ($colIndex < 1) {
            continue;
        }

        $colLetter = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->getColumnDimension($colLetter)->setWidth($width);
    }
}

// Adjust others as needed

// Output file
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header(
    'Content-Disposition: attachment;filename="Comparative_Report_' 
    . date('Ymd_His') 
    . '.xlsx"'
);
header('Cache-Control: max-age=0');


$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
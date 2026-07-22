<?php

error_reporting(0);
ini_set('display_errors', 0);

// Check if Composer autoload exists
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    die('Please run: composer require phpoffice/phpspreadsheet');
}

require $composerAutoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

// Start session and include config
session_start();

// Set time limit for large files
set_time_limit(300);

// Include config
require_once __DIR__ . '/../config/config.php';

// Function to get preview totals - FIXED VERSION
function getPreviewTotals(\mysqli $conn, array $years, mixed $start_month, mixed $end_month) {
    $start_month = is_numeric($start_month) ? (int)$start_month : 0;
    $end_month = is_numeric($end_month) ? (int)$end_month : 0;
    if ($start_month < 1 || $start_month > 12) $start_month = 0;
    if ($end_month < 1 || $end_month > 12) $end_month = 0;

    if ($start_month > 0 && $end_month === 0) $end_month = $start_month;
    if ($end_month > 0 && $start_month === 0) $start_month = $end_month;
    if ($start_month > 0 && $end_month > 0 && $end_month < $start_month) {
        $tmp = $start_month;
        $start_month = $end_month;
        $end_month = $tmp;
    }

    $use_month_range = $start_month > 0 && $end_month > 0;
    
    // Build WHERE conditions for JOIN ON clause
    $joinConditions = [];
    $params = [];
    $types = '';
    
    if (!empty($years) && !in_array('', $years)) {
        $placeholders = implode(',', array_fill(0, count($years), '?'));
        $joinConditions[] = "cr.transaction_year IN ($placeholders)";
        $types .= str_repeat('i', count($years));
        $params = array_merge($params, $years);
    }
    
    $period_select = "cr.transaction_year AS period_key,";
    $group_period = "cr.transaction_year,";
    $current_period = null;
    $period2 = null;
    $period3 = null;
    $current_label = '(Primary Period)';
    $period2_label = '(Period 2)';
    $period3_label = '(Period 3)';
    
    if ($use_month_range) {
        $joinConditions[] = "MONTH(cr.transaction_month) BETWEEN ? AND ?";
        $types .= 'ii';
        $params[] = $start_month;
        $params[] = $end_month;

        $range_label = date('F', mktime(0, 0, 0, $start_month, 1)) . " - " . date('F', mktime(0, 0, 0, $end_month, 1));
        if (!empty($years)) {
            usort($years, function($a, $b) { return $b - $a; });
            $current_period = $years[0] ?? null;
            $period2 = $years[1] ?? null;
            $period3 = $years[2] ?? null;
        }

        if ($current_period) $current_label = $range_label . ' ' . $current_period;
        if ($period2) $period2_label = $range_label . ' ' . $period2;
        if ($period3) $period3_label = $range_label . ' ' . $period3;
    } else if (!empty($years)) {
        usort($years, function($a, $b) { return $b - $a; });
        $current_period = $years[0] ?? null;
        $period2 = $years[1] ?? null;
        $period3 = $years[2] ?? null;
        $current_label = $current_period ? (string)$current_period : '(Current Year)';
        $period2_label = $period2 ? (string)$period2 : '(Year 2)';
        $period3_label = $period3 ? (string)$period3 : '(Year 3)';
    }
    
    $joinConditionsSql = !empty($joinConditions) ? ' AND ' . implode(' AND ', $joinConditions) : '';
    $select_area = "'_all' AS area,";
    $group_area = "";
    
    // MODIFIED SQL QUERY - Using JOIN to match fetch_preview_totals_fs.php logic
    $sql = "
        SELECT
            gc.map_key AS map_key,
            gc.gl_description_comparative AS comp,
            $select_area
            $period_select
            COALESCE(
                CASE
                    WHEN cr.transaction_type = 'Branch' THEN 'mlfsi'
                    WHEN cr.transaction_type = 'Showroom' THEN 'jewelers'
                    ELSE 'mlfsi'
                END,
                'mlfsi'
            ) AS branch_type,
            COALESCE(SUM(cr.amount), 0) AS total
        FROM (
            SELECT DISTINCT
                COALESCE(NULLIF(gl_mapping, ''), gl_description_comparative) AS map_key,
                gl_description_comparative,
                gl_code
            FROM fs_reports.gl_codes_ho_new
            WHERE gl_description_comparative IS NOT NULL
              AND gl_description_comparative != ''
              AND gl_code IS NOT NULL
              AND gl_code != ''
        ) gc
        JOIN fs_reports.comparative_report cr
          ON cr.gl_code = gc.gl_code
          $joinConditionsSql
        WHERE (cr.status_void IS NULL OR cr.status_void != 'Void')
        GROUP BY gc.map_key, gc.gl_description_comparative, $group_area $group_period branch_type
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['error' => 'Prepare failed: ' . mysqli_error($conn)];
    }
    
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        return ['error' => 'Query failed: ' . $error];
    }
    
    $result = mysqli_stmt_get_result($stmt);
    $totals = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $area = $row['area'] ?? null;
        if ($area === null || $area === '') {
            $area = '_all';
        }
        $map_key = $row['map_key'] ?? '';
        $period = $row['period_key'] ?? '';
        $branch = $row['branch_type'] ?? 'mlfsi';
        $total = (float)($row['total'] ?? 0);
        
        if (!isset($totals[$area])) {
            $totals[$area] = [];
        }
        if (!isset($totals[$area][$map_key])) {
            $totals[$area][$map_key] = [];
        }
        if (!isset($totals[$area][$map_key][$period])) {
            $totals[$area][$map_key][$period] = ['mlfsi' => 0, 'jewelers' => 0];
        }
        
        $totals[$area][$map_key][$period][$branch] = $total;
    }
    
    mysqli_stmt_close($stmt);
    
    return [
        'totals' => $totals,
        'current_period' => $current_period,
        'period2' => $period2,
        'period3' => $period3,
        'current_label' => $current_label,
        'period2_label' => $period2_label,
        'period3_label' => $period3_label
    ];
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    // Try regular POST if JSON fails
    $data = $_POST;
}

$years = $data['years'] ?? [];
$start_month = $data['start_month'] ?? '';
$end_month = $data['end_month'] ?? '';
if ($start_month === '' && isset($data['month1'])) {
    $start_month = $data['month1'];
}
if ($end_month === '' && isset($data['month2'])) {
    $end_month = $data['month2'];
}
$exportMode = strtolower(trim((string)($data['export_mode'] ?? 'full')));
$isSummaryExport = in_array($exportMode, ['summary', 'summarized'], true);

// Validate and clean data
if (!is_array($years)) $years = [$years];

$years = array_values(array_map('intval', array_filter($years, function($y) { return is_numeric($y); })));

// Get GL data for structure - GROUP BY map_key to avoid duplicates
$glQuery = "SELECT 
                MIN(id) as id, 
                sort_order, 
                MIN(sub_order) as sub_order, 
                description, 
                gl_description_comparative, 
                MIN(gl_code) as gl_code, 
                MIN(gl_description) as gl_description,
                COALESCE(NULLIF(gl_mapping, ''), gl_description_comparative) as map_key
            FROM fs_reports.gl_codes_ho_new 
            WHERE gl_description_comparative IS NOT NULL 
            AND gl_description_comparative != ''
            GROUP BY description, gl_description_comparative, map_key, sort_order
            ORDER BY sort_order, sub_order, id";
$glResult = mysqli_query($conn, $glQuery);
$glRows = [];
if ($glResult) {
    while ($row = mysqli_fetch_assoc($glResult)) {
        $glRows[] = $row;
    }
} else {
    die('Error fetching GL data: ' . mysqli_error($conn));
}


// Create spreadsheet
$spreadsheet = new Spreadsheet();

// Remove default sheet
$defaultSheet = $spreadsheet->getActiveSheet();
$spreadsheet->removeSheetByIndex(0);

// Fetch consolidated totals
$data = getPreviewTotals($conn, $years, $start_month, $end_month);

if (isset($data['error'])) {
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Error');
    $spreadsheet->addSheet($worksheet);
    $worksheet->setCellValue('A1', 'Error loading data');
    $worksheet->setCellValue('A2', $data['error']);
} else {
    // Create worksheet
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Cumulative Report');
    $spreadsheet->addSheet($worksheet);
    $worksheet->setShowSummaryBelow(true);

    // Freeze columns A-C and rows 1-8
  $worksheet->freezePane('A9');

    // Logo
    $worksheet->getRowDimension(1)->setRowHeight(45);
    $logoPath = $_SERVER['DOCUMENT_ROOT'] . '/fs-reports/images/mlhuillier.jpg';
    if (file_exists($logoPath)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('MLHUILLIER Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(40);
        $drawing->setOffsetX(120);
        $drawing->setOffsetY(10);
        $drawing->setCoordinates('E1'); // Place near description
        $drawing->setWorksheet($worksheet);
    }

    // Headers
    $r = 1;
    // Row 1 is just the logo
    $r++;

   // Row 2: COMPARATIVE PROFIT & LOSS STATEMENT
    $worksheet->mergeCells("A$r:N$r");
    $worksheet->setCellValue("A$r", "NATIONWIDE (MLFSI & JEWELERS)");
    $worksheet->getStyle("A$r")->getFont()->setBold(true)->setSize(16);
    $worksheet->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $r++;

    // Row 2: COMPARATIVE PROFIT & LOSS STATEMENT
    $worksheet->mergeCells("A$r:N$r");
    $worksheet->setCellValue("A$r", "COMPARATIVE PROFIT & LOSS STATEMENT");
    $worksheet->getStyle("A$r")->getFont()->setBold(true)->setSize(16);
    $worksheet->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $r++;

 // Row 3: month year vs month year vs month year
$currentLabelRaw = (string)($data['current_label'] ?? '');
$period2LabelRaw = (string)($data['period2_label'] ?? '');
$period3LabelRaw = (string)($data['period3_label'] ?? '');

$parseRangeLabel = function(string $label) {
    $label = trim($label);
    if (preg_match('/^([A-Za-z]+)\s*-\s*([A-Za-z]+)\s+(\d{4})$/', $label, $m)) {
        return [
            'start' => $m[1],
            'end' => $m[2],
            'year' => $m[3],
        ];
    }
    return null;
};

$formatShortRangeLabel = function(string $label) {
    if (preg_match('/^([A-Za-z]+)\s*-\s*([A-Za-z]+)\s+(\d{4})$/', trim($label), $m)) {
        $start = strtoupper(substr($m[1], 0, 3));
        $end = strtoupper(substr($m[2], 0, 3));
        $year = $m[3];
        return $start . ' - ' . $end . ' ' . $year;
    }
    return strtoupper($label);
};

$currentParsed = $parseRangeLabel($currentLabelRaw);
$period2Parsed = $parseRangeLabel($period2LabelRaw);
$period3Parsed = $parseRangeLabel($period3LabelRaw);

if ($currentParsed && $period2Parsed && $period3Parsed) {
    $sameRange = (
        strcasecmp($currentParsed['start'], $period2Parsed['start']) === 0 &&
        strcasecmp($currentParsed['end'], $period2Parsed['end']) === 0 &&
        strcasecmp($currentParsed['start'], $period3Parsed['start']) === 0 &&
        strcasecmp($currentParsed['end'], $period3Parsed['end']) === 0
    );
    if ($sameRange) {
        $rangeText = strtoupper($currentParsed['start'] . ' - ' . $currentParsed['end']);
        $comparisonString = $rangeText . ' ' . $currentParsed['year'] . ' VS ' . $period2Parsed['year'] . ' VS ' . $period3Parsed['year'];
    } else {
        $comparisonString = strtoupper($currentLabelRaw) . ' VS ' . strtoupper($period2LabelRaw) . ' VS ' . strtoupper($period3LabelRaw);
    }
} else {
    $comparisonString = strtoupper($currentLabelRaw) . ' VS ' . strtoupper($period2LabelRaw) . ' VS ' . strtoupper($period3LabelRaw);
}

$worksheet->mergeCells("A$r:N$r");
$worksheet->setCellValue("A$r", $comparisonString);
$worksheet->getStyle("A$r")->getFont()->setBold(true)->setSize(16);
$worksheet->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$r++;

    // // Row 4: Empty
    // $r++;

    // // Row 5: Empty
    // $r++;

    // Row 6:

    $r++;

$worksheet->mergeCells("A$r:D$r");
$worksheet->setCellValue("A$r", "NATIONWIDE");

$worksheet->getStyle("A$r")->getFont()->setBold(true);
$worksheet->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$worksheet->getStyle("A$r")->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFF4B183');




// Row 7: Dates and Variance Headers
$currentLabel = $formatShortRangeLabel($currentLabelRaw);
$period2Label = $formatShortRangeLabel($period2LabelRaw);
$period3Label = $formatShortRangeLabel($period3LabelRaw);

$worksheet->setCellValue("E$r", $currentLabel);
$worksheet->setCellValue("F$r", $period2Label);
$worksheet->setCellValue("G$r", $period3Label);

$getYear = function($p) { return $p ? substr((string)$p, 0, 4) : ''; };
$y1 = $getYear($data['current_period']);
$y2 = $getYear($data['period2']);
$y3 = $getYear($data['period3']);

$variance1_label = ($y1 && $y2) ? strtoupper("$y1 VS $y2") : "YEAR VS YEAR";
$variance2_label = ($y1 && $y3) ? strtoupper("$y1 VS $y3") : "YEAR VS YEAR";

$worksheet->setCellValue("I$r", $variance1_label);
$worksheet->setCellValue("J$r", "%");
$worksheet->setCellValue("K$r", $variance2_label);
$worksheet->setCellValue("L$r", "%");

$worksheet->getStyle("E$r:L$r")->getFont()->setBold(true);
$worksheet->getStyle("E$r:L$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Orangish background
$worksheet->getStyle("E$r:G$r")->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFF4B183');

    $worksheet->getStyle("I$r:N$r")->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF808080');

$r++;
// row 8

 $worksheet->getStyle("A$r:G$r")->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFF4B183');


    $worksheet->setCellValue("I$r", "INC/DEC");
    $worksheet->getStyle("I$r")->getFont()->setBold(true);
    $worksheet->getStyle("I$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $worksheet->getStyle("I$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');

    $worksheet->setCellValue("J$r", "");
    $worksheet->getStyle("J$r")->getFont()->setBold(true);
    $worksheet->getStyle("J$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $worksheet->getStyle("J$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');

    $worksheet->setCellValue("K$r", "INC/DEC");
    $worksheet->getStyle("K$r")->getFont()->setBold(true);
    $worksheet->getStyle("K$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $worksheet->getStyle("K$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');

    $worksheet->setCellValue("L$r", "");
    $worksheet->getStyle("L$r")->getFont()->setBold(true);
    $worksheet->getStyle("L$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $worksheet->getStyle("L$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');

    $worksheet->setCellValue("M$r", "");
    $worksheet->getStyle("M$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');

    $worksheet->setCellValue("N$r", "");
    $worksheet->getStyle("N$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');
    $r++;

    // Row 9: Empty
    // $r++;

    // Row 10: Revenues Header
    $worksheet->setCellValue("A$r", "Revenues");
    $worksheet->getStyle("A$r:N$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF808080');
    $worksheet->getStyle("A$r")->getFont()->setBold(true);
    $r++;
    $tableStartRow = $r;

    // Data Processing
    $areaTotals = $data['totals']['_all'] ?? [];
    $current_period = $data['current_period'];
    $period2 = $data['period2'];
    $period3 = $data['period3'];
    $has_period2 = $period2 != null;
    $has_period3 = $period3 != null;

    // Helper functions
    $cell = fn(int $colIdx, int $rowNum) => Coordinate::stringFromColumnIndex($colIdx) . $rowNum;
    $rowRange = fn(int $rowNum) => "A$rowNum:N$rowNum";

        $setFontColor = function(string $cellAddress, string $argb) use ($worksheet) {
            $worksheet->getStyle($cellAddress)->getFont()->getColor()->setARGB($argb);
        };

    $setPctValueAndStyle = function(
        int $rowNum,
        int $colIdx,
        float $pct,
        float $baseVal,
        float $diffVal,
        bool $isDeduction,
        bool $hasCompare
    ) use ($worksheet, $cell, $setFontColor) {
        $pctCell = $cell($colIdx, $rowNum);

        if (!$hasCompare) {
            $worksheet->setCellValue($pctCell, 0.0);
            $worksheet->getStyle($pctCell)->getNumberFormat()->setFormatCode('0.00');
            $setFontColor($pctCell, Color::COLOR_BLACK);
            return;
        }
        
        if ($baseVal == 0) {
            if ($diffVal > 0) {
                $worksheet->setCellValue($pctCell, 100.00);
                $setFontColor($pctCell, Color::COLOR_BLACK);
            } elseif ($diffVal < 0) {
                $worksheet->setCellValue($pctCell, -100.00);
                $setFontColor($pctCell, Color::COLOR_RED);
            } else {
        $worksheet->setCellValue($pctCell, 0.0);
        $setFontColor($pctCell, Color::COLOR_BLACK);
            }
            return;
        }

        $pctValueForDisplay = $pct * 100;

        if (abs($pctValueForDisplay) > 1000) {
            $worksheet->setCellValue($pctCell, 'mat');
            $color = $pctValueForDisplay < 0 ? Color::COLOR_RED : Color::COLOR_BLACK;
            $setFontColor($pctCell, $color);
            $worksheet->getStyle($pctCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            return;
        }

        $worksheet->setCellValue($pctCell, (float)$pctValueForDisplay);
        $color = $isDeduction ? ($pctValueForDisplay > 0 ? Color::COLOR_BLACK : Color::COLOR_RED) : ($pctValueForDisplay < 0 ? Color::COLOR_RED : Color::COLOR_BLACK);
        $setFontColor($pctCell, $color);
    };

    // Init totals
    $initTotals = fn() => ['p1' => 0, 'p2' => 0, 'p3' => 0];

        $currentDesc = null;
        $currentDescSortOrder = null;
        $mainCounter = 0;
    $categoryTotals = $initTotals();

        // Section totals (used for computed rows like TOTAL REVENUES, GROSS PROFIT, etc.)
        $revenueInserted = false;
        $grossProfitInserted = false;
        $sellingAdminInserted = false;
        $ebitInserted = false;
        $ebtInserted = false;
        $netIncomeInserted = false;

    $revenueTotals = $initTotals();
    $costTotals = $initTotals();
    $sellingAdminTotals = $initTotals();
    $operatingTotals = $initTotals();
    $interestTotals = $initTotals();
    $taxTotals = $initTotals();

        $currentDetailStartRow = null;
        $pendingSpacerForNextRevenueGroup = null;

        $collapseDetailRows = function(int $startRow, int $endRow, int $summaryRow) use ($worksheet) {
            if ($startRow > $endRow) {
                return;
            }
            for ($detailRow = $startRow; $detailRow <= $endRow; $detailRow++) {
                $worksheet->getRowDimension($detailRow)->setOutlineLevel(1);
                $worksheet->getRowDimension($detailRow)->setVisible(false);
            }
            $worksheet->getRowDimension($summaryRow)->setCollapsed(true);
        };

    $writeSectionHeaderRow = function(string $label) use ($worksheet, &$r, $cell, $rowRange) {
    // Put label in first cell to match preview columns
        $worksheet->setCellValue("A$r", $label);

    // Apply background to whole row (if that's what you still want)
    $worksheet->getStyle($rowRange($r))->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF79646');

    // Bold the label cell
        $worksheet->getStyle("A$r")->getFont()->setBold(true);

    $r++;
};

    $writeLabelRow = function(string $label) use ($worksheet, &$r, $cell, $rowRange) {
        $worksheet->setCellValue("A$r", $label);
            $worksheet->getStyle($rowRange($r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFF79646');
        $worksheet->getStyle("A$r")->getFont()->setBold(true);
            $r++;
        };

        $writeComputedTotalsRow = function(string $label, array $totals, string $fillArgb) use (
            $worksheet,
            &$r,
            $rowRange,
        $has_period2,
        $has_period3,
        $setFontColor,
            $setPctValueAndStyle,
        $cell,
        ) {
        $base2 = $has_period2 ? $totals['p2'] : 0;
        $base3 = $has_period3 ? $totals['p3'] : 0;

        $hasCompare1 = $has_period2;
        $hasCompare2 = $has_period3;

        $incDec1 = $totals['p1'] - $base2;
        $pct1 = $base2 != 0 ? ($incDec1 / $base2)  : 0;
        
        $incDec2 = $totals['p1'] - $base3;
        $pct2 = $base3 != 0 ? ($incDec2 / $base3)  : 0;

        $worksheet->setCellValue("A$r", $label);
        $worksheet->setCellValue("E$r", (float)$totals['p1']);
        $worksheet->setCellValue("F$r", (float)$totals['p2']);
        $worksheet->setCellValue("G$r", (float)$totals['p3']);
        
        $worksheet->setCellValue("I$r", (float)$incDec1);
        $setPctValueAndStyle($r, 10, (float)$pct1, (float)$base2, (float)$incDec1, false, $hasCompare1);
        
        $worksheet->setCellValue("K$r", (float)$incDec2);
        $setPctValueAndStyle($r, 12, (float)$pct2, (float)$base3, (float)$incDec2, false, $hasCompare2);

        // Colors for amounts
        $color1 = $incDec1 < 0 ? Color::COLOR_RED : Color::COLOR_BLACK;
        $setFontColor("I$r", $color1);
        
        $color2 = $incDec2 < 0 ? Color::COLOR_RED : Color::COLOR_BLACK;
        $setFontColor("K$r", $color2);

        // Keep variance cells zero when no comparison period is selected

            $worksheet->getStyle($rowRange($r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB($fillArgb);
            $worksheet->getStyle($rowRange($r))->getFont()->setBold(true);

            $labelTopBorderRows = [
                "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'EARNINGS BEFORE INTEREST & TAXES',
                'EARNINGS BEFORE TAXES',
            ];
            if (in_array($label, $labelTopBorderRows, true)) {
                $worksheet->getStyle($cell(5, $r) . ':' . $cell(12, $r))->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
            }
            if ($label === 'TOTAL NET INCOME/LOSS') {
                $worksheet->getStyle($cell(5, $r) . ':' . $cell(12, $r))->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
                $worksheet->getStyle($cell(5, $r) . ':' . $cell(12, $r))->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_DOUBLE)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
            }

            $r++;
        };
        
        // Keep track of processed groups to avoid duplicates (match preview logic: description + mapping)
        $processedGroups = [];

        $revenueCategoryToggle = false; // false = no fill, true = with fill
        $summaryOnlySortOrders = [10, 13]; // show only category total row
        $detailOnlySortOrders = [26, 27, 28]; // show only detail rows (column C)

        $shouldRenderCategoryTotal = static function (?int $sortOrder) use ($detailOnlySortOrders): bool {
            return $sortOrder !== null && !in_array((int)$sortOrder, $detailOnlySortOrders, true);
        };

        $shouldRenderDetailRow = static function (int $sortOrder) use ($summaryOnlySortOrders): bool {
            return !in_array($sortOrder, $summaryOnlySortOrders, true);
        };

        
        foreach ($glRows as $glRow) {
            $desc = $glRow['description'] ?? '';
            $comp = $glRow['gl_description_comparative'] ?? '';
            $mapKey = $glRow['map_key'] ?? $comp;
            $sortOrderVal = (int)($glRow['sort_order'] ?? 0);
            $subOrderVal = (int)($glRow['sub_order'] ?? 0);

            $groupKey = $desc . '||' . $mapKey;
            
            // Skip if we've already processed this group (to avoid duplicates)
            if (isset($processedGroups[$groupKey])) {
                continue;
            }
            
            $processedGroups[$groupKey] = true;
            
            // Check if this mapKey exists in totals for this area
            $hasData = isset($areaTotals[$mapKey]);
            
            // New description group
            if ($desc !== $currentDesc) {
                if ($currentDesc !== null) {
                    if ($shouldRenderCategoryTotal($currentDescSortOrder)) {
                    // Category Total Row
                    $incDec1 = $categoryTotals['p1'] - $categoryTotals['p2'];
                    $pct1 = $categoryTotals['p2'] != 0 ? ($incDec1 / $categoryTotals['p2'])  : 0;
                    
                    $incDec2 = $categoryTotals['p1'] - $categoryTotals['p3'];
                    $pct2 = $categoryTotals['p3'] != 0 ? ($incDec2 / $categoryTotals['p3'])  : 0;
                        
                    // $worksheet->setCellValue("A$r", $mainCounter);
                    $worksheet->setCellValue("B$r", $currentDesc);
                    $worksheet->setCellValue("E$r", (float)$categoryTotals['p1']);
                    $worksheet->setCellValue("F$r", (float)$categoryTotals['p2']);
                    $worksheet->setCellValue("G$r", (float)$categoryTotals['p3']);
                    
                    $worksheet->setCellValue("I$r", (float)$incDec1);
            $setPctValueAndStyle($r, 10, (float)$pct1, (float)$categoryTotals['p2'], (float)$incDec1, false, $has_period2);
                    
                    $worksheet->setCellValue("K$r", (float)$incDec2);
            $setPctValueAndStyle($r, 12, (float)$pct2, (float)$categoryTotals['p3'], (float)$incDec2, false, $has_period3);
                        
                        // Style
                        if ($currentDescSortOrder >= 1 && $currentDescSortOrder <= 22) {
                            // Alternate background
                            if ($revenueCategoryToggle) {
                                $worksheet->getStyle($rowRange($r))->getFill()
                                    ->setFillType(Fill::FILL_SOLID)
                                    ->getStartColor()->setARGB('FFFDE9D9');
                            } else {
                                $worksheet->getStyle($rowRange($r))->getFill()
                                    ->setFillType(Fill::FILL_NONE);
                            }

                            // Flip toggle for next category
                            $revenueCategoryToggle = !$revenueCategoryToggle;
                        } else {
                            // Keep default styling for non-revenue sections
                            $worksheet->getStyle($rowRange($r))->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFFDE9D9');
                        }


                        if ($currentDescSortOrder === 23) {
                            $worksheet->getStyle($cell(5, $r) . ':' . $cell(12, $r))->getBorders()->getBottom()
                                ->setBorderStyle(Border::BORDER_THIN)
                                ->getColor()->setARGB(Color::COLOR_BLACK);
                        }


                        $summaryRow = $r;
                        if (
                            $isSummaryExport &&
                            $currentDescSortOrder !== null &&
                            $currentDescSortOrder >= 1 &&
                            $currentDescSortOrder <= 22 &&
                            $currentDetailStartRow === null &&
                            $pendingSpacerForNextRevenueGroup !== null
                        ) {
                            // Summary-only categories (e.g., sort_order 6/8/11): collapse the spacer row above them.
                            $currentDetailStartRow = $pendingSpacerForNextRevenueGroup;
                            $pendingSpacerForNextRevenueGroup = null;
                        }
                        if (
                            $isSummaryExport &&
                            $currentDescSortOrder !== null &&
                            $currentDescSortOrder >= 1 &&
                            $currentDescSortOrder <= 22 &&
                            $currentDetailStartRow !== null
                        ) {
                            $collapseDetailRows($currentDetailStartRow, $summaryRow - 1, $summaryRow);
                        }

                        $r++; // Move to next row after summary row

                        // Keep spacer rows in summary export for sort_order 1..23 so they remain visible after uncollapse.
                        if (
                            !$isSummaryExport ||
                            ($currentDescSortOrder !== null && $currentDescSortOrder >= 1 && $currentDescSortOrder <= 25)
                        ) {
                            $r++; // Spacer between categories

                            // For revenue groups, make this spacer belong to the next group's collapse range.
                            if (
                                $isSummaryExport &&
                                $currentDescSortOrder !== null &&
                                $currentDescSortOrder >= 1 &&
                                $currentDescSortOrder <= 22
                            ) {
                                $pendingSpacerForNextRevenueGroup = $r - 1;
                            }
                        }
                    }
                
                // Reset
                $categoryTotals = $initTotals();
                $currentDetailStartRow = null;

                // Insert computed rows when crossing sort_order thresholds (matches preview)
                if (!$revenueInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 22 && $sortOrderVal > 22) {
                    $totalRevenuesRow = $r;
                    $writeComputedTotalsRow('TOTAL REVENUES', $revenueTotals, 'FFFCD5B4');
                    if (
                        $isSummaryExport &&
                        $pendingSpacerForNextRevenueGroup !== null
                    ) {
                        // TOTAL REVENUES: collapse the spacer row above it (after sort_order 22).
                        $collapseDetailRows($pendingSpacerForNextRevenueGroup, $pendingSpacerForNextRevenueGroup, $totalRevenuesRow);
                        $pendingSpacerForNextRevenueGroup = null;
                    }
                    $r++; // Spacer
                    $writeSectionHeaderRow('Cost of Sales/Service');
                    $revenueInserted = true;
                }
                if (!$grossProfitInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 23 && $sortOrderVal > 23) {
                    // $r++; // Spacer
                    $gross = [
                        'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                        'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                        'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                    ];
                    $writeComputedTotalsRow('GROSS PROFIT', $gross, 'FFFCD5B4');
                    $r++; // Spacer
                    $writeLabelRow('SELLING & ADMIN EXPENSE');
                    $grossProfitInserted = true;
                }
                if (!$sellingAdminInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 25 && $sortOrderVal > 25) {
                    // $r++; // Spacer
                    $writeComputedTotalsRow('TOTAL SELLING AND ADMIN EXPENSES', $sellingAdminTotals, 'FFFCD5B4');
                    $r++; // Spacer
                    $gross = [
                        'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                        'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                        'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                    ];
                    $ebitda = [
                        'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                        'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                        'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                    ];
                    $writeComputedTotalsRow("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", $ebitda, 'FFFCD5B4');
                    $sellingAdminInserted = true;
                }
                if (!$ebitInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 26 && $sortOrderVal > 26) {
                    $r++; // Spacer before EARNINGS BEFORE INTEREST & TAXES
                    $gross = [
                        'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                        'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                        'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                    ];
                    $ebitda = [
                        'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                        'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                        'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                    ];
                    $ebit = [
                        'p1' => $ebitda['p1'] - $operatingTotals['p1'],
                        'p2' => $ebitda['p2'] - $operatingTotals['p2'],
                        'p3' => $ebitda['p3'] - $operatingTotals['p3'],
                    ];
                    $writeComputedTotalsRow('EARNINGS BEFORE INTEREST & TAXES', $ebit, 'FFFCD5B4');
                    $ebitInserted = true;
                }
                if (!$ebtInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 27 && $sortOrderVal > 27) {
                    $r++; // Spacer before EARNINGS BEFORE TAXES
                    $gross = [
                        'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                        'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                        'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                    ];
                    $ebitda = [
                        'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                        'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                        'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                    ];
                    $ebit = [
                        'p1' => $ebitda['p1'] - $operatingTotals['p1'],
                        'p2' => $ebitda['p2'] - $operatingTotals['p2'],
                        'p3' => $ebitda['p3'] - $operatingTotals['p3'],
                    ];
                    $ebt = [
                        'p1' => $ebit['p1'] - $interestTotals['p1'],
                        'p2' => $ebit['p2'] - $interestTotals['p2'],
                        'p3' => $ebit['p3'] - $interestTotals['p3'],
                    ];
                    $writeComputedTotalsRow('EARNINGS BEFORE TAXES', $ebt, 'FFFCD5B4');
                    $ebtInserted = true;
                }
                if (!$netIncomeInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 28 && $sortOrderVal > 28) {
                    $gross = [
                        'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                        'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                        'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                    ];
                    $ebitda = [
                        'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                        'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                        'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                    ];
                    $ebit = [
                        'p1' => $ebitda['p1'] - $operatingTotals['p1'],
                        'p2' => $ebitda['p2'] - $operatingTotals['p2'],
                        'p3' => $ebitda['p3'] - $operatingTotals['p3'],
                    ];
                    $ebt = [
                        'p1' => $ebit['p1'] - $interestTotals['p1'],
                        'p2' => $ebit['p2'] - $interestTotals['p2'],
                        'p3' => $ebit['p3'] - $interestTotals['p3'],
                    ];
                    $net = [
                        'p1' => $ebt['p1'] - $taxTotals['p1'],
                        'p2' => $ebt['p2'] - $taxTotals['p2'],
                        'p3' => $ebt['p3'] - $taxTotals['p3'],
                    ];
                    $r++; // Spacer (matches preview spacing)
                    $writeComputedTotalsRow('TOTAL NET INCOME/LOSS', $net, 'FFFCD5B4');
                    $netIncomeInserted = true;
                }
                }
            }
            
            if ($desc !== $currentDesc) {
                $currentDesc = $desc;
                if ($sortOrderVal > 0) {
                    $currentDescSortOrder = $sortOrderVal;
                }
                $mainCounter++;
                $currentDetailStartRow = null;
                if ($sortOrderVal < 1 || $sortOrderVal > 22) {
                    $pendingSpacerForNextRevenueGroup = null;
                }
            }
            
            // Get values - handle case where there's no data
            $p1M = $hasData && $current_period ? ($areaTotals[$mapKey][$current_period]['mlfsi'] ?? 0) : 0;
            $p1J = $hasData && $current_period ? ($areaTotals[$mapKey][$current_period]['jewelers'] ?? 0) : 0;
            $p1T = $p1M + $p1J;
            
            $p2M = $has_period2 && $hasData && $period2 ? ($areaTotals[$mapKey][$period2]['mlfsi'] ?? 0) : 0;
            $p2J = $has_period2 && $hasData && $period2 ? ($areaTotals[$mapKey][$period2]['jewelers'] ?? 0) : 0;
            $p2T = $p2M + $p2J;

            $p3M = $has_period3 && $hasData && $period3 ? ($areaTotals[$mapKey][$period3]['mlfsi'] ?? 0) : 0;
            $p3J = $has_period3 && $hasData && $period3 ? ($areaTotals[$mapKey][$period3]['jewelers'] ?? 0) : 0;
            $p3T = $p3M + $p3J;
            
            // Check if deduction
            $isDeduction = (strpos(strtolower($mapKey), 'less') !== false || strpos(strtolower($mapKey), 'discount') !== false);
            
            // Display values
            $displayP1 = $isDeduction ? abs($p1T) : $p1T;
            $displayP2 = $isDeduction ? abs($p2T) : $p2T;
            $displayP3 = $isDeduction ? abs($p3T) : $p3T;
            
            // Calculations
            $incDec1 = $isDeduction ? ($p2T - $p1T) : ($p1T - $p2T);
            $pct1 = $p2T != 0 ? ($incDec1 / ($isDeduction ? abs($p2T) : $p2T))  : 0;

            $incDec2 = $isDeduction ? ($p3T - $p1T) : ($p1T - $p3T);
            $pct2 = $p3T != 0 ? ($incDec2 / ($isDeduction ? abs($p3T) : $p3T))  : 0;
            
            // Add to totals
            $categoryTotals['p1'] += $p1T;
            $categoryTotals['p2'] += $p2T;
            $categoryTotals['p3'] += $p3T;

            $addToSection = function(&$target) use ($p1T, $p2T, $p3T) {
                $target['p1'] += $p1T;
                $target['p2'] += $p2T;
                $target['p3'] += $p3T;
            };

            // Add to section totals (always raw values)
            if ($sortOrderVal >= 1 && $sortOrderVal <= 22) {
                $addToSection($revenueTotals);
            } elseif ($sortOrderVal === 23) {
                $addToSection($costTotals);
            } elseif ($sortOrderVal === 24 || $sortOrderVal === 25) {
                $addToSection($sellingAdminTotals);
            } elseif ($sortOrderVal === 26) {
                $addToSection($operatingTotals);
            } elseif ($sortOrderVal === 27) {
                $addToSection($interestTotals);
            } elseif ($sortOrderVal === 28) {
                $addToSection($taxTotals);
            }
            
            // Add detail row (show all GL mappings even if they have zero values), unless configured as summary-only.
            if ($shouldRenderDetailRow($sortOrderVal)) {
                if ($currentDetailStartRow === null) {
                    if (
                        $isSummaryExport &&
                        $sortOrderVal >= 1 &&
                        $sortOrderVal <= 22 &&
                        $pendingSpacerForNextRevenueGroup !== null
                    ) {
                        $currentDetailStartRow = $pendingSpacerForNextRevenueGroup;
                        $pendingSpacerForNextRevenueGroup = null;
                    } else {
                        $currentDetailStartRow = $r;
                    }
                }
                $useColumn2ForComp = $sortOrderVal === 17 && $subOrderVal >= 3 && $subOrderVal <= 6;
                $compCol2 = $useColumn2ForComp ? $comp : '';
                $compCol3 = $useColumn2ForComp ? '' : $comp;

                $worksheet->setCellValue("B$r", $compCol2);
                $worksheet->setCellValue("C$r", $compCol3);
                $worksheet->setCellValue("E$r", (float)$displayP1);
                $worksheet->setCellValue("F$r", (float)$displayP2);
                $worksheet->setCellValue("G$r", (float)$displayP3);
                
                $worksheet->setCellValue("I$r", (float)$incDec1);
                $setPctValueAndStyle($r, 10, (float)$pct1, (float)$p2T, (float)$incDec1, $isDeduction, $has_period2);
                
                $worksheet->setCellValue("K$r", (float)$incDec2);
                $setPctValueAndStyle($r, 12, (float)$pct2, (float)$p3T, (float)$incDec2, $isDeduction, $has_period3);

                $setFontColor("I$r", $isDeduction ? ($incDec1 > 0 ? Color::COLOR_BLACK : Color::COLOR_RED) : ($incDec1 < 0 ? Color::COLOR_RED : Color::COLOR_BLACK));
                $setFontColor("K$r", $isDeduction ? ($incDec2 > 0 ? Color::COLOR_BLACK : Color::COLOR_RED) : ($incDec2 < 0 ? Color::COLOR_RED : Color::COLOR_BLACK));
                
                $r++;
            }
        }
        
        // Add final category total if we have data
        if ($currentDesc !== null && !empty($processedGroups) && $shouldRenderCategoryTotal($currentDescSortOrder)) {
            $incDec1 = $categoryTotals['p1'] - $categoryTotals['p2'];
            $pct1 = $categoryTotals['p2'] != 0 ? ($incDec1 / $categoryTotals['p2'])  : 0;
            $incDec2 = $categoryTotals['p1'] - $categoryTotals['p3'];
            $pct2 = $categoryTotals['p3'] != 0 ? ($incDec2 / $categoryTotals['p3'])  : 0;
            
            $worksheet->setCellValue("A$r", $mainCounter);
            $worksheet->setCellValue("B$r", $currentDesc);
            $worksheet->setCellValue("E$r", (float)$categoryTotals['p1']);
            $worksheet->setCellValue("F$r", (float)$categoryTotals['p2']);
            $worksheet->setCellValue("G$r", (float)$categoryTotals['p3']);
            $worksheet->setCellValue("I$r", (float)$incDec1);
            $setPctValueAndStyle($r, 10, (float)$pct1, (float)$categoryTotals['p2'], (float)$incDec1, false, $has_period2);
            $worksheet->setCellValue("K$r", (float)$incDec2);
            $setPctValueAndStyle($r, 12, (float)$pct2, (float)$categoryTotals['p3'], (float)$incDec2, false, $has_period3);
            
            $worksheet->getStyle($rowRange($r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFCD5B4');
            $worksheet->getStyle($rowRange($r))->getFont()->setBold(true);

             if ($currentDescSortOrder === 23) {
                $worksheet->getStyle($cell(5, $r) . ':' . $cell(12, $r))->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
            }

            
            $summaryRow = $r;
            if (
                $isSummaryExport &&
                $currentDescSortOrder !== null &&
                $currentDescSortOrder >= 1 &&
                $currentDescSortOrder <= 22 &&
                $currentDetailStartRow === null &&
                $pendingSpacerForNextRevenueGroup !== null
            ) {
                // Summary-only categories (e.g., sort_order 6/8/11): collapse the spacer row above them.
                $currentDetailStartRow = $pendingSpacerForNextRevenueGroup;
                $pendingSpacerForNextRevenueGroup = null;
            }
            if (
                $isSummaryExport &&
                $currentDescSortOrder !== null &&
                $currentDescSortOrder >= 1 &&
                $currentDescSortOrder <= 22 &&
                $currentDetailStartRow !== null
            ) {
                $collapseDetailRows($currentDetailStartRow, $summaryRow - 1, $summaryRow);
            }

            $r++; // Move to next row after summary row
            if (
                !$isSummaryExport ||
                ($currentDescSortOrder !== null && $currentDescSortOrder >= 1 && $currentDescSortOrder <= 25)
            ) {
                $r++; // Spacer between categories
            }
        }

        // Final inserts for computed rows (in case the last group doesn't cross a threshold)
        if ($currentDescSortOrder !== null) {
            if (!$revenueInserted && $currentDescSortOrder <= 22) {
                $totalRevenuesRow = $r;
                $writeComputedTotalsRow('TOTAL REVENUES', $revenueTotals, 'FFFCD5B4');
                if (
                    $isSummaryExport &&
                    $pendingSpacerForNextRevenueGroup !== null
                ) {
                    // TOTAL REVENUES: collapse the spacer row above it (after sort_order 22).
                    $collapseDetailRows($pendingSpacerForNextRevenueGroup, $pendingSpacerForNextRevenueGroup, $totalRevenuesRow);
                    $pendingSpacerForNextRevenueGroup = null;
                }
                $r++; // Spacer
                $writeSectionHeaderRow('Cost of Sales/Service');
                $revenueInserted = true;
            }
            if (!$grossProfitInserted && $currentDescSortOrder <= 23) {
                // $r++; // Spacer
                $gross = [
                    'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                    'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                    'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                ];
                $writeComputedTotalsRow('GROSS PROFIT', $gross, 'FFFF7F3A');
                $r++; // Spacer
                $writeLabelRow('SELLING & ADMIN EXPENSE');
                $grossProfitInserted = true;
            }
            if (!$sellingAdminInserted && $currentDescSortOrder <= 25) {
                // $r++; // Spacer
                $writeComputedTotalsRow('TOTAL SELLING AND ADMIN EXPENSES', $sellingAdminTotals, 'FFFF7F3A');
                $r++; // Spacer
                $gross = [
                    'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                    'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                    'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                ];
                $ebitda = [
                    'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                    'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                    'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                ];
                $writeComputedTotalsRow("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", $ebitda, 'FFFF7F3A');
                $sellingAdminInserted = true;
            }
            if (!$ebitInserted && $currentDescSortOrder <= 26) {
                $r++; // Spacer
                $gross = [
                    'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                    'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                    'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                ];
                $ebitda = [
                    'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                    'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                    'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                ];
                $ebit = [
                    'p1' => $ebitda['p1'] - $operatingTotals['p1'],
                    'p2' => $ebitda['p2'] - $operatingTotals['p2'],
                    'p3' => $ebitda['p3'] - $operatingTotals['p3'],
                ];
                $writeComputedTotalsRow('EARNINGS BEFORE INTEREST & TAXES', $ebit, 'FFFF7F3A');
                $ebitInserted = true;
            }
            if (!$ebtInserted && $currentDescSortOrder <= 27) {
                $r++; // Spacer
                $gross = [
                    'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                    'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                    'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                ];
                $ebitda = [
                    'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                    'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                    'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                ];
                $ebit = [
                    'p1' => $ebitda['p1'] - $operatingTotals['p1'],
                    'p2' => $ebitda['p2'] - $operatingTotals['p2'],
                    'p3' => $ebitda['p3'] - $operatingTotals['p3'],
                ];
                $ebt = [
                    'p1' => $ebit['p1'] - $interestTotals['p1'],
                    'p2' => $ebit['p2'] - $interestTotals['p2'],
                    'p3' => $ebit['p3'] - $interestTotals['p3'],
                ];
                $writeComputedTotalsRow('EARNINGS BEFORE TAXES', $ebt, 'FFFF7F3A');
                $ebtInserted = true;
            }
            if (!$netIncomeInserted && $currentDescSortOrder <= 28) {
                $r++; // Spacer before TOTAL NET INCOME/LOSS
                $gross = [
                    'p1' => $revenueTotals['p1'] - $costTotals['p1'],
                    'p2' => $revenueTotals['p2'] - $costTotals['p2'],
                    'p3' => $revenueTotals['p3'] - $costTotals['p3'],
                ];
                $ebitda = [
                    'p1' => $gross['p1'] - $sellingAdminTotals['p1'],
                    'p2' => $gross['p2'] - $sellingAdminTotals['p2'],
                    'p3' => $gross['p3'] - $sellingAdminTotals['p3'],
                ];
                $ebit = [
                    'p1' => $ebitda['p1'] - $operatingTotals['p1'],
                    'p2' => $ebitda['p2'] - $operatingTotals['p2'],
                    'p3' => $ebitda['p3'] - $operatingTotals['p3'],
                ];
                $ebt = [
                    'p1' => $ebit['p1'] - $interestTotals['p1'],
                    'p2' => $ebit['p2'] - $interestTotals['p2'],
                    'p3' => $ebit['p3'] - $interestTotals['p3'],
                ];
                $net = [
                    'p1' => $ebt['p1'] - $taxTotals['p1'],
                    'p2' => $ebt['p2'] - $taxTotals['p2'],
                    'p3' => $ebt['p3'] - $taxTotals['p3'],
                ];
                $writeComputedTotalsRow('TOTAL NET INCOME/LOSS', $net, 'FFFCD5B4');
                $netIncomeInserted = true;
            }
        }

    // Set column widths
    $worksheet->getColumnDimension('A')->setWidth(3);
    $worksheet->getColumnDimension('B')->setWidth(3);
    $worksheet->getColumnDimension('C')->setWidth(50);
    $worksheet->getColumnDimension('D')->setWidth(1);
    $worksheet->getColumnDimension('E')->setWidth(20);
    $worksheet->getColumnDimension('F')->setWidth(20);
    $worksheet->getColumnDimension('G')->setWidth(20);
    $worksheet->getColumnDimension('H')->setWidth(1);
    $worksheet->getColumnDimension('I')->setWidth(20);
    $worksheet->getColumnDimension('J')->setWidth(15);
    $worksheet->getColumnDimension('K')->setWidth(20);
    $worksheet->getColumnDimension('L')->setWidth(15);
    $worksheet->getColumnDimension('M')->setWidth(1);
    $worksheet->getColumnDimension('N')->setWidth(1);

    // Set number formats
    $lastRow = $worksheet->getHighestRow();
    if ($lastRow >= $tableStartRow) {
        $worksheet->getStyle("E$tableStartRow:G$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $worksheet->getStyle("I$tableStartRow:I$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $worksheet->getStyle("K$tableStartRow:K$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $worksheet->getStyle("J$tableStartRow:J$lastRow")->getNumberFormat()->setFormatCode('0.00');
        $worksheet->getStyle("L$tableStartRow:L$lastRow")->getNumberFormat()->setFormatCode('0.00');
    }
}

// Set first sheet as active
if ($spreadsheet->getSheetCount() > 0) {
    $spreadsheet->setActiveSheetIndex(0);
} else {
    // Create empty sheet if no data
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'No Data');
    $spreadsheet->addSheet($worksheet);
    $worksheet->setCellValue('A1', 'No data available for the selected filters.');
}

// Generate filename
$filenamePrefix = $isSummaryExport ? 'Cumulative_Report_Summary_' : 'Cumulative_Report_';
$filename = $filenamePrefix . date('Y-m-d_His') . '.xlsx';

// Clear any previous output
if (ob_get_length()) {
    ob_end_clean();
}

// Set headers
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Write to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

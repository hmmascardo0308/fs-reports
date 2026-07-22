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

require_once __DIR__ . '/../config/config.php';

// Function to get preview totals - FIXED VERSION
function getPreviewTotals(\mysqli $conn, string $mainzone, string $zone, string $region, array $areas, array $years, mixed $month1, mixed $month2) {
    $use_month = $month1 !== '' || $month2 !== '';
    $periods = [];
    if ($use_month) {
        if ($month1 !== '') $periods[$month1] = true;
        if ($month2 !== '') $periods[$month2] = true;
        $periods = array_keys($periods);
    }
    
    // Build WHERE conditions for JOIN ON clause
    $joinConditions = [];
    $params = [];
    $types = '';
    
    if ($mainzone !== '') {
        $joinConditions[] = "cr.mainzone = ?";
        $types .= 's';
        $params[] = $mainzone;
    }

    if ($zone !== '') {
        $joinConditions[] = "cr.zone = ?";
        $types .= 's';
        $params[] = $zone;
    }

    if ($region !== '' && $region !== 'all_regions') {
        $joinConditions[] = "cr.region = ?";
        $types .= 's';
        $params[] = $region;
    }
    
    if (!empty($areas) && !in_array('', $areas)) {
        $placeholders = implode(',', array_fill(0, count($areas), '?'));
        $joinConditions[] = "cr.area IN ($placeholders)";
        $types .= str_repeat('s', count($areas));
        $params = array_merge($params, $areas);
    }
    
    if (!empty($years) && !in_array('', $years)) {
        $placeholders = implode(',', array_fill(0, count($years), '?'));
        $joinConditions[] = "cr.transaction_year IN ($placeholders)";
        $types .= str_repeat('i', count($years));
        $params = array_merge($params, $years);
    }
    
    $period_select = "cr.transaction_year AS period_key,";
    $group_period = "cr.transaction_year,";
    $current_period = null;
    $previous_period = null;
    $current_label = '(Primary Period)';
    $previous_label = '(Previous Period)';
    
    if ($use_month && !empty($periods)) {
        $placeholders = implode(',', array_fill(0, count($periods), '?'));
        // Optimize: Compare dates directly (YYYY-MM-01) instead of using DATE_FORMAT function
        $joinConditions[] = "cr.transaction_month IN ($placeholders)";
        $types .= str_repeat('s', count($periods));
        // Append '-01' to each period (YYYY-MM) to match the stored DATE format
        $params = array_merge($params, array_map(fn($p) => $p . '-01', $periods));
        
        $period_select = "DATE_FORMAT(cr.transaction_month, '%Y-%m') AS period_key,";
        $group_period = "DATE_FORMAT(cr.transaction_month, '%Y-%m'),";
        
        usort($periods, function($a, $b) { return $b <=> $a; });
        $current_period = $periods[0] ?? null;
        $previous_period = $periods[1] ?? null;
        
        if ($current_period) {
            $current_label = date('F Y', strtotime($current_period . '-01'));
        }
        if ($previous_period) {
            $previous_label = date('F Y', strtotime($previous_period . '-01'));
        } else if ($current_period) {
            $previous_label = '(No Comparison Period)';
        }
    } else if (!empty($years)) {
        usort($years, function($a, $b) { return $b - $a; });
        $current_period = $years[0] ?? null;
        $previous_period = $years[1] ?? null;
        $current_label = $current_period ? (string)$current_period : '(Primary Period)';
        $previous_label = $previous_period ? (string)$previous_period : '(Previous Period)';
    }
    
    $joinConditionsSql = !empty($joinConditions) ? ' AND ' . implode(' AND ', $joinConditions) : '';
    
    $hasAreaFilter = !empty($areas) && !in_array('', $areas);
    // When filtering by specific areas, don't COALESCE NULL areas to "_all" (it creates an extra "All Areas" block).
    $select_area = $hasAreaFilter ? "cr.area AS area," : "'_all' AS area,";
    $group_area = $hasAreaFilter ? "cr.area," : "";
    
    // MODIFIED SQL QUERY - Using LEFT JOIN to include all GL mappings
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
            FROM fs_reports.gl_codes_new
            WHERE gl_description_comparative IS NOT NULL
              AND gl_description_comparative != ''
        ) gc
        LEFT JOIN fs_reports.comparative_report cr
          ON cr.gl_code = gc.gl_code
          AND cr.gl_code IS NOT NULL
          AND cr.gl_code != ''
          AND (cr.status_void IS NULL OR cr.status_void != 'Void')
          $joinConditionsSql
        GROUP BY gc.map_key, gc.gl_description_comparative, $group_area $group_period branch_type
        ORDER BY gc.map_key
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
        if ($hasAreaFilter && ($area === null || $area === '')) {
            continue;
        }
        if (!$hasAreaFilter && ($area === null || $area === '')) {
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
        'previous_period' => $previous_period,
        'current_label' => $current_label,
        'previous_label' => $previous_label
    ];
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    // Try regular POST if JSON fails
    $data = $_POST;
}

$region = $data['region'] ?? '';
$mainzone = trim((string)($data['mainzone'] ?? ''));
$zone = trim((string)($data['zone'] ?? ''));
$areas = $data['areas'] ?? [];
$years = $data['years'] ?? [];
$month1 = $data['month1'] ?? '';
$month2 = $data['month2'] ?? '';
$exportMode = strtolower(trim((string)($data['export_mode'] ?? 'full')));
$isSummaryExport = in_array($exportMode, ['summary', 'summarized'], true);

// Validate and clean data
if (!is_array($areas)) $areas = [$areas];
if (!is_array($years)) $years = [$years];

$areas = array_values(array_filter($areas, function($a) { return trim((string)$a) !== ''; }));
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
            FROM fs_reports.gl_codes_new 
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

// Normalize region input:
// - "all_regions" or empty => query all regions
// - comma-separated string => split into multiple regions
// - array input (future-safe) => use values directly
$regions = [];
$regionTokens = [];
if (is_array($region)) {
    $regionTokens = $region;
} else {
    $regionStr = trim((string)$region);
    if ($regionStr !== '') {
        $regionTokens = explode(',', $regionStr);
    }
}
$regionTokens = array_values(array_unique(array_filter(array_map('trim', $regionTokens), function($r) {
    return $r !== '' && $r !== 'all_regions';
})));
$wantsAllRegions = (is_string($region) && trim($region) === 'all_regions') || empty($regionTokens);

// Handle multiple regions
if ($wantsAllRegions) {
    // Get all distinct regions, optionally scoped by Mainzone/Zone
    $regionWhere = ["region IS NOT NULL", "TRIM(region) != ''"];
    $regionTypes = '';
    $regionParams = [];

    if ($mainzone !== '') {
        $regionWhere[] = "mainzone = ?";
        $regionTypes .= 's';
        $regionParams[] = $mainzone;
    }
    if ($zone !== '') {
        $regionWhere[] = "zone = ?";
        $regionTypes .= 's';
        $regionParams[] = $zone;
    }

    $regionQuery = "SELECT DISTINCT region
                    FROM fs_reports.comparative_report
                    WHERE " . implode(' AND ', $regionWhere) . "
                    ORDER BY region";
    $regionStmt = mysqli_prepare($conn, $regionQuery);
    if ($regionStmt) {
        if (!empty($regionParams)) {
            mysqli_stmt_bind_param($regionStmt, $regionTypes, ...$regionParams);
        }
        if (mysqli_stmt_execute($regionStmt)) {
            $regionResult = mysqli_stmt_get_result($regionStmt);
            while ($row = mysqli_fetch_assoc($regionResult)) {
                $regions[] = $row['region'];
            }
        }
        mysqli_stmt_close($regionStmt);
    }
} else {
    $regions = $regionTokens;
}

// If no regions found, use empty array
if (empty($regions)) {
    $regions = [''];
}

// Create spreadsheet
$spreadsheet = new Spreadsheet();

// Remove default sheet
$defaultSheet = $spreadsheet->getActiveSheet();
$spreadsheet->removeSheetByIndex(0);

// Process each region
foreach ($regions as $regionIndex => $currentRegion) {
    // Get data for this region
    $data = getPreviewTotals($conn, $mainzone, $zone, $currentRegion, $areas, $years, $month1, $month2);
    
    if (isset($data['error'])) {
        // Create worksheet with error message
        $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Error ' . ($regionIndex + 1));
        $spreadsheet->addSheet($worksheet);
        $worksheet->setCellValue('A1', 'Error for region: ' . $currentRegion);
        $worksheet->setCellValue('A2', $data['error']);
        continue;
    }
    
    // --- Side-by-side area layout (15 columns per area) ---
    $columnsPerArea = 15;
    $areaTotals = $data['totals'] ?? [];

    // Areas to display: respect the filter selection if provided, otherwise use query result keys or fallback to "_all".
    if (!empty($areas)) {
        // Only include selected areas that actually exist for this region (avoid showing 0.00-only tables).
        $availableAreas = array_fill_keys(array_keys($areaTotals), true);
        $areasToDisplay = [];
        foreach ($areas as $a) {
            if (isset($availableAreas[$a])) {
                $areasToDisplay[] = $a;
            }
        }
    } else if (!empty($areaTotals)) {
        $areasToDisplay = array_values(array_keys($areaTotals));
    } else {
        $areasToDisplay = ['_all'];
        $areaTotals['_all'] = [];
    }
    $areasToDisplay = array_values(array_unique(array_filter($areasToDisplay, fn($a) => $a !== null && $a !== '')));
    if (!empty($areas) && empty($areasToDisplay)) {
        // No matching areas for this region based on the selected area filters.
        continue;
    }
    if (empty($areasToDisplay)) {
        $areasToDisplay = ['_all'];
        $areaTotals['_all'] = $areaTotals['_all'] ?? [];
    }

    // Create worksheet for this region (only if it has at least one area to show)
    $worksheetName = substr($currentRegion ?: 'All Regions', 0, 31);
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $worksheetName);
    $spreadsheet->addSheet($worksheet);
    $worksheet->setShowSummaryBelow(true);

    // Freeze columns A, B, C
    $worksheet->freezePane('A10');

    $regionName = $currentRegion ?: 'ALL REGIONS';
    $periodText = $data['current_label'] . ' vs. ' . $data['previous_label'];

// Detect FULL YEAR comparison (no month filter used)
$isFullYearComparison = empty($month1) && empty($month2) 
    && !empty($years) 
    && count($years) >= 1;

// If FULL YEAR (like 2025 vs 2024), override format
if ($isFullYearComparison) {

    $currentYear  = $data['current_label'];
    $previousYear = $data['previous_label'];

    // Determine area label
    $areaLabelText = (!empty($areas)) ? 'PER AREA' : ''; //ALL AREAS

    $periodText = "JANUARY - DECEMBER {$currentYear} VS {$previousYear} {$areaLabelText}";
}

    // Logo uses only one row (row 1); increase its height.
    $worksheet->getRowDimension(1)->setRowHeight(55);
    $logoPath = $_SERVER['DOCUMENT_ROOT'] . '/fs-reports/images/mlhuillier.jpg';
    $hasLogo = file_exists($logoPath);

    $writeMergedHeaderRow = function(int $baseCol, int $rowNum, string $text, int $fontSize) use ($worksheet, $columnsPerArea) {
        $start = Coordinate::stringFromColumnIndex($baseCol) . $rowNum;
        $end = Coordinate::stringFromColumnIndex($baseCol + $columnsPerArea - 1) . $rowNum;
        $worksheet->setCellValue($start, $text);
        $worksheet->mergeCells("$start:$end");
        $worksheet->getStyle($start)->getFont()->setBold(true)->setSize($fontSize);
        $worksheet->getStyle($start)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    };

    foreach ($areasToDisplay as $areaIdx => $areaKey) {
        $baseCol = 1 + ($areaIdx * $columnsPerArea);

        // Logo centered-ish within the 15-column block.
        if ($hasLogo) {
            try {
                $logoCol = Coordinate::stringFromColumnIndex($baseCol + 5);
                $drawing = new Drawing();
                $drawing->setName('Logo_' . $areaIdx);
                $drawing->setDescription('MLHUILLIER Logo');
                $drawing->setPath($logoPath);
                $drawing->setHeight(50);
                $drawing->setOffsetX(30);
                $drawing->setOffsetY(10);

                $drawing->setCoordinates($logoCol . '1');
                $drawing->setWorksheet($worksheet);
            } catch (Exception $e) {
                // Ignore drawing errors; keep generating the sheet.
            }
        }

        $areaDisplay = ($areaKey === '_all') ? '' : 'AREA ' . strtoupper((string)$areaKey);
        $writeMergedHeaderRow($baseCol, 2, strtoupper($regionName) . ' COMPARATIVE PROFIT & LOSS STATEMENT', 20);
        $writeMergedHeaderRow($baseCol, 3, 'MLFSI & JEWELERS', 20);
        $writeMergedHeaderRow($baseCol, 4, strtoupper($periodText), 20);
        $writeMergedHeaderRow($baseCol, 5, $areaDisplay, 20);
    }

    $tableStartRow = 7;

    foreach ($areasToDisplay as $areaIdx => $areaKey) {
        $baseCol = 1 + ($areaIdx * $columnsPerArea);
        $r = $tableStartRow;

        $cell = fn(int $offset, int $rowNum) => Coordinate::stringFromColumnIndex($baseCol + $offset) . $rowNum;
        $rowRange = fn(int $rowNum) => Coordinate::stringFromColumnIndex($baseCol) . $rowNum . ':' . Coordinate::stringFromColumnIndex($baseCol + $columnsPerArea - 1) . $rowNum;
        $mergeRow = function(int $rowNum, int $startOffset, int $endOffset) use ($worksheet, $cell) {
            $worksheet->mergeCells($cell($startOffset, $rowNum) . ':' . $cell($endOffset, $rowNum));
        };

        $has_previous = $data['previous_period'] != null;
        $current_period = $data['current_period'];
        $previous_period = $data['previous_period'];

        $setFontColor = function(string $cellAddress, string $argb) use ($worksheet) {
            $worksheet->getStyle($cellAddress)->getFont()->getColor()->setARGB($argb);
        };

        $setIncDecStyle = function(int $rowNum, float $incDec) use ($cell, $setFontColor) {
            $setFontColor($cell(12, $rowNum), $incDec < 0 ? Color::COLOR_RED : Color::COLOR_BLACK);
        };

        $setPctValueAndStyle = function(int $rowNum, float $pct, float $previousT) use ($worksheet, $cell, $setFontColor, $has_previous) {
    $pctCell = $cell(13, $rowNum);
    
    if (!$has_previous) {
        $worksheet->setCellValue($pctCell, 0.0);
        $setFontColor($pctCell, Color::COLOR_BLACK);
        return;
    }
    
    // Handle case when previous total is zero
    if ($previousT == 0) {
        $incDec = $worksheet->getCell($cell(12, $rowNum))->getValue();
        if ($incDec > 0) {
            $worksheet->setCellValue($pctCell, 100.00);
            $setFontColor($pctCell, Color::COLOR_BLACK);
        } else if ($incDec < 0) {
            $worksheet->setCellValue($pctCell, -100.00);
            $setFontColor($pctCell, Color::COLOR_RED);
        } else {
            $worksheet->setCellValue($pctCell, 0.0);
            $setFontColor($pctCell, Color::COLOR_BLACK);
        }
        return;
    }
    
    // Normal calculation
    if ($pct > 1000) {
        $worksheet->setCellValue($pctCell, 'mat');
        $setFontColor($pctCell, Color::COLOR_BLACK);
        $worksheet->getStyle($pctCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        return;
    }

    if ($pct < -1000) {
        $worksheet->setCellValue($pctCell, 'mat');
        $setFontColor($pctCell, Color::COLOR_RED);
        $worksheet->getStyle($pctCell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        return;
    }

    $worksheet->setCellValue($pctCell, (float)$pct);
    $setFontColor($pctCell, $pct < 0 ? Color::COLOR_RED : Color::COLOR_BLACK);
};

       // Prepare period labels (uppercase month only if month-based, otherwise as is)
        $currLabelVal = (string)$data['current_label'];
        $prevLabelVal = (string)$data['previous_label'];

        // Check if current_period indicates a month (YYYY-MM format)
        if (!empty($data['current_period']) && strpos((string)$data['current_period'], '-') !== false) {
            $currLabelVal = strtoupper(date('F', strtotime($data['current_period'] . '-01')));
        }
        // Check if previous_period indicates a month (YYYY-MM format)
        if (!empty($data['previous_period']) && strpos((string)$data['previous_period'], '-') !== false) {
            $prevLabelVal = strtoupper(date('F', strtotime($data['previous_period'] . '-01')));
        }

       // Period headers (centered inside the area block)
            $worksheet->setCellValueExplicit($cell(4, $r), $currLabelVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $mergeRow($r, 4, 6);
            $worksheet->getStyle($cell(4, $r))->getFont()->setBold(true);
            $worksheet->getStyle($cell(4, $r))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Add background color for Current Label
            $worksheet->getStyle($cell(4, $r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFF7F3A');

            $worksheet->setCellValueExplicit($cell(8, $r), $prevLabelVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $mergeRow($r, 8, 10);
            $worksheet->getStyle($cell(8, $r))->getFont()->setBold(true);
            $worksheet->getStyle($cell(8, $r))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Add background color for Previous Label
            $worksheet->getStyle($cell(8, $r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFF7F3A');

            $r++;

        // Column headers
       $headers = ['', '', '', '', 'MLFSI', 'JEWELERS', 'TOTAL', '', 'MLFSI', 'JEWELERS', 'TOTAL', '', 'INC./DEC.', '%', ''];

       for ($offset = 0; $offset < count($headers); $offset++) {
    $currentCell = $cell($offset, $r);
    
    if ($headers[$offset] === 'INC./DEC.') {
        $richText = new RichText();
        
        // Part 1: "INC./" (Make sure it's bold)
        $incPart = $richText->createTextRun('INC./');
        $incPart->getFont()->setBold(true);
        
        // Part 2: "DEC." (Bold AND Red)
        $decPart = $richText->createTextRun('DEC.');
        $decPart->getFont()->setBold(true);
        $decPart->getFont()->setColor(new Color(Color::COLOR_RED));
        
        $worksheet->setCellValue($currentCell, $richText);
    } else {
        $worksheet->setCellValue($currentCell, $headers[$offset]);
        // Normal bolding for other cells
        $worksheet->getStyle($currentCell)->getFont()->setBold(true);
    }

    // Apply the rest of your shared styles
    $style = $worksheet->getStyle($currentCell);
    $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
    $style->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFFCBB5');
}

        $r++;


        // Empty row before Revenues row (as requested)
        $r++;

    // Revenues section header
$worksheet->setCellValue($cell(0, $r), 'REVENUES');

// Apply background color to the whole row (if needed)
$worksheet->getStyle($rowRange($r))->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFF79646');

// Bold the first cell
$worksheet->getStyle($cell(0, $r))->getFont()->setBold(true);

$r++;

        // Add GL data rows
        $currentDesc = null;
        $currentDescSortOrder = null;
        $mainCounter = 0;
        $categoryTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];

        // Section totals (used for computed rows like TOTAL REVENUES, GROSS PROFIT, etc.)
        $revenueInserted = false;
        $grossProfitInserted = false;
        $sellingAdminInserted = false;
        $ebitInserted = false;
        $ebtInserted = false;
        $netIncomeInserted = false;

        $revenueTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
        $costTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
        $sellingAdminTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
        $operatingTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
        $interestTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
        $taxTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
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
    // Put label in second cell (column 1)
    $worksheet->setCellValue($cell(0, $r), $label);

    // Apply background to whole row (if that's what you still want)
    $worksheet->getStyle($rowRange($r))->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFF79646');

    // Bold the label cell
    $worksheet->getStyle($cell(0, $r))->getFont()->setBold(true);

    $r++;
};

        $writeLabelRow = function(string $label) use ($worksheet, &$r, $cell, $rowRange) {
            $worksheet->setCellValue($cell(0, $r), $label);
            $worksheet->getStyle($rowRange($r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFF79646');
            $worksheet->getStyle($cell(0, $r))->getFont()->setBold(true);
            $r++;
        };

        $writeComputedTotalsRow = function(string $label, array $totals, string $fillArgb) use (
            $worksheet,
            &$r,
            $cell,
            $rowRange,
            $has_previous,
            $setIncDecStyle,
            $setPctValueAndStyle
        ) {
            $incDec = $totals['currentT'] - $totals['previousT'];

if ($totals['previousT'] == 0) {
    $pct = $incDec > 0 ? 100.00 : ($incDec < 0 ? -100.00 : 0.00);
} else {
    $pct = ($incDec / $totals['previousT']) * 100;
}

            $worksheet->setCellValue($cell(0, $r), $label);
            $worksheet->setCellValue($cell(4, $r), (float)$totals['currentM']);
            $worksheet->setCellValue($cell(5, $r), (float)$totals['currentJ']);
            $worksheet->setCellValue($cell(6, $r), (float)$totals['currentT']);
            $worksheet->setCellValue($cell(8, $r), (float)$totals['previousM']);
            $worksheet->setCellValue($cell(9, $r), (float)$totals['previousJ']);
            $worksheet->setCellValue($cell(10, $r), (float)$totals['previousT']);
            $worksheet->setCellValue($cell(12, $r), (float)$incDec);
            $setIncDecStyle($r, (float)$incDec);
            $setPctValueAndStyle($r, (float)$pct, (float)$totals['previousT']);

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
                $worksheet->getStyle($cell(4, $r) . ':' . $cell(13, $r))->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
            }
            if ($label === 'TOTAL NET INCOME/LOSS') {
                $worksheet->getStyle($cell(4, $r) . ':' . $cell(13, $r))->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
                $worksheet->getStyle($cell(4, $r) . ':' . $cell(13, $r))->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_DOUBLE)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
            }
            $r++;
        };
        
        // Keep track of processed groups to avoid duplicates (match preview logic: description + mapping)
        $processedGroups = [];

        $revenueCategoryToggle = false; // false = no fill, true = with fill
        $summaryOnlySortOrders = [6, 8, 11]; // show only category total row
        $detailOnlySortOrders = [24, 25, 26]; // show only detail rows (column C)

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

            $groupKey = $desc . '||' . $mapKey;
            
            // Skip if we've already processed this group (to avoid duplicates)
            if (isset($processedGroups[$groupKey])) {
                continue;
            }
            
            $processedGroups[$groupKey] = true;
            
            // Check if this mapKey exists in totals for this area
            $hasData = isset($areaTotals[$areaKey][$mapKey]);
            
            // New description group
            if ($desc !== $currentDesc) {
                if ($currentDesc !== null) {
                    if ($shouldRenderCategoryTotal($currentDescSortOrder)) {
                        // Add category total row
                        $incDec = $categoryTotals['currentT'] - $categoryTotals['previousT'];
                        $pct = $categoryTotals['previousT'] != 0 ? ($incDec / abs($categoryTotals['previousT'])) * 100 : 0;
                        
                        $worksheet->setCellValue($cell(0, $r), '');
                        $worksheet->setCellValue($cell(1, $r), $currentDesc);
                        $worksheet->setCellValue($cell(4, $r), (float)$categoryTotals['currentM']);
                        $worksheet->setCellValue($cell(5, $r), (float)$categoryTotals['currentJ']);
                        $worksheet->setCellValue($cell(6, $r), (float)$categoryTotals['currentT']);
                        $worksheet->setCellValue($cell(8, $r), (float)$categoryTotals['previousM']);
                        $worksheet->setCellValue($cell(9, $r), (float)$categoryTotals['previousJ']);
                        $worksheet->setCellValue($cell(10, $r), (float)$categoryTotals['previousT']);
                        $worksheet->setCellValue($cell(12, $r), (float)$incDec);
                        $setIncDecStyle($r, (float)$incDec);
                        $setPctValueAndStyle($r, (float)$pct, (float)$categoryTotals['previousT']);
                        
                        // Style
                        if ($currentDescSortOrder >= 1 && $currentDescSortOrder <= 20) {
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

                        if ($currentDescSortOrder === 21) {
                            $worksheet->getStyle($cell(4, $r) . ':' . $cell(13, $r))->getBorders()->getBottom()
                                ->setBorderStyle(Border::BORDER_THIN)
                                ->getColor()->setARGB(Color::COLOR_BLACK);
                        }

                        $summaryRow = $r;
                        if (
                            $isSummaryExport &&
                            $currentDescSortOrder !== null &&
                            $currentDescSortOrder >= 1 &&
                            $currentDescSortOrder <= 20 &&
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
                            $currentDescSortOrder <= 20 &&
                            $currentDetailStartRow !== null
                        ) {
                            $collapseDetailRows($currentDetailStartRow, $summaryRow - 1, $summaryRow);
                        }

                        $r++; // Move to next row after summary row

                        // Keep spacer rows in summary export for sort_order 1..23 so they remain visible after uncollapse.
                        if (
                            !$isSummaryExport ||
                            ($currentDescSortOrder !== null && $currentDescSortOrder >= 1 && $currentDescSortOrder <= 23)
                        ) {
                            $r++; // Spacer between categories

                            // For revenue groups, make this spacer belong to the next group's collapse range.
                            if (
                                $isSummaryExport &&
                                $currentDescSortOrder !== null &&
                                $currentDescSortOrder >= 1 &&
                                $currentDescSortOrder <= 20
                            ) {
                                $pendingSpacerForNextRevenueGroup = $r - 1;
                            }
                        }
                    }
                
                // Reset
                $categoryTotals = ['currentM' => 0, 'currentJ' => 0, 'currentT' => 0, 'previousM' => 0, 'previousJ' => 0, 'previousT' => 0];
                $currentDetailStartRow = null;

                // Insert computed rows when crossing sort_order thresholds (matches preview)
                if (!$revenueInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 20 && $sortOrderVal > 20) {
                    $totalRevenuesRow = $r;
                    $writeComputedTotalsRow('TOTAL REVENUES', $revenueTotals, 'FFFCD5B4');
                    if (
                        $isSummaryExport &&
                        $pendingSpacerForNextRevenueGroup !== null
                    ) {
                        // TOTAL REVENUES: collapse the spacer row above it (after sort_order 20).
                        $collapseDetailRows($pendingSpacerForNextRevenueGroup, $pendingSpacerForNextRevenueGroup, $totalRevenuesRow);
                        $pendingSpacerForNextRevenueGroup = null;
                    }
                    $r++; // Spacer
                    $writeSectionHeaderRow('Cost of Sales/Service');
                    $revenueInserted = true;
                }
                if (!$grossProfitInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 21 && $sortOrderVal > 21) {
                    // $r++; // Spacer
                    $gross = [
                        'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                        'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                        'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                        'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                        'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                        'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                    ];
                    $writeComputedTotalsRow('GROSS PROFIT', $gross, 'FFFCD5B4');
                    $r++; // Spacer
                    $writeLabelRow('SELLING & ADMIN EXPENSE');
                    $grossProfitInserted = true;
                }
                if (!$sellingAdminInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 23 && $sortOrderVal > 23) {
                    $writeComputedTotalsRow('TOTAL SELLING AND ADMIN EXPENSES', $sellingAdminTotals, 'FFFCD5B4');
                    $r++; // Spacer
                    $gross = [
                        'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                        'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                        'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                        'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                        'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                        'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                    ];
                    $ebitda = [
                        'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                        'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                        'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                        'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                        'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                        'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                    ];
                    $writeComputedTotalsRow("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", $ebitda, 'FFFCD5B4');
                    $sellingAdminInserted = true;
                }
                if (!$ebitInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 24 && $sortOrderVal > 24) {
                    $r++; // Spacer before EARNINGS BEFORE INTEREST & TAXES
                    $gross = [
                        'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                        'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                        'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                        'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                        'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                        'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                    ];
                    $ebitda = [
                        'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                        'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                        'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                        'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                        'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                        'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                    ];
                    $ebit = [
                        'currentM' => $ebitda['currentM'] - $operatingTotals['currentM'],
                        'currentJ' => $ebitda['currentJ'] - $operatingTotals['currentJ'],
                        'currentT' => $ebitda['currentT'] - $operatingTotals['currentT'],
                        'previousM' => $ebitda['previousM'] - $operatingTotals['previousM'],
                        'previousJ' => $ebitda['previousJ'] - $operatingTotals['previousJ'],
                        'previousT' => $ebitda['previousT'] - $operatingTotals['previousT'],
                    ];
                    $writeComputedTotalsRow('EARNINGS BEFORE INTEREST & TAXES', $ebit, 'FFFCD5B4');
                    $ebitInserted = true;
                }
                if (!$ebtInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 25 && $sortOrderVal > 25) {
                    $r++; // Spacer before EARNINGS BEFORE TAXES
                    $gross = [
                        'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                        'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                        'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                        'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                        'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                        'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                    ];
                    $ebitda = [
                        'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                        'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                        'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                        'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                        'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                        'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                    ];
                    $ebit = [
                        'currentM' => $ebitda['currentM'] - $operatingTotals['currentM'],
                        'currentJ' => $ebitda['currentJ'] - $operatingTotals['currentJ'],
                        'currentT' => $ebitda['currentT'] - $operatingTotals['currentT'],
                        'previousM' => $ebitda['previousM'] - $operatingTotals['previousM'],
                        'previousJ' => $ebitda['previousJ'] - $operatingTotals['previousJ'],
                        'previousT' => $ebitda['previousT'] - $operatingTotals['previousT'],
                    ];
                    $ebt = [
                        'currentM' => $ebit['currentM'] - $interestTotals['currentM'],
                        'currentJ' => $ebit['currentJ'] - $interestTotals['currentJ'],
                        'currentT' => $ebit['currentT'] - $interestTotals['currentT'],
                        'previousM' => $ebit['previousM'] - $interestTotals['previousM'],
                        'previousJ' => $ebit['previousJ'] - $interestTotals['previousJ'],
                        'previousT' => $ebit['previousT'] - $interestTotals['previousT'],
                    ];
                    $writeComputedTotalsRow('EARNINGS BEFORE TAXES', $ebt, 'FFFCD5B4');
                    $ebtInserted = true;
                }
                if (!$netIncomeInserted && $currentDescSortOrder !== null && $currentDescSortOrder <= 26 && $sortOrderVal > 26) {
                    $gross = [
                        'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                        'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                        'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                        'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                        'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                        'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                    ];
                    $ebitda = [
                        'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                        'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                        'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                        'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                        'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                        'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                    ];
                    $ebit = [
                        'currentM' => $ebitda['currentM'] - $operatingTotals['currentM'],
                        'currentJ' => $ebitda['currentJ'] - $operatingTotals['currentJ'],
                        'currentT' => $ebitda['currentT'] - $operatingTotals['currentT'],
                        'previousM' => $ebitda['previousM'] - $operatingTotals['previousM'],
                        'previousJ' => $ebitda['previousJ'] - $operatingTotals['previousJ'],
                        'previousT' => $ebitda['previousT'] - $operatingTotals['previousT'],
                    ];
                    $ebt = [
                        'currentM' => $ebit['currentM'] - $interestTotals['currentM'],
                        'currentJ' => $ebit['currentJ'] - $interestTotals['currentJ'],
                        'currentT' => $ebit['currentT'] - $interestTotals['currentT'],
                        'previousM' => $ebit['previousM'] - $interestTotals['previousM'],
                        'previousJ' => $ebit['previousJ'] - $interestTotals['previousJ'],
                        'previousT' => $ebit['previousT'] - $interestTotals['previousT'],
                    ];
                    $net = [
                        'currentM' => $ebt['currentM'] - $taxTotals['currentM'],
                        'currentJ' => $ebt['currentJ'] - $taxTotals['currentJ'],
                        'currentT' => $ebt['currentT'] - $taxTotals['currentT'],
                        'previousM' => $ebt['previousM'] - $taxTotals['previousM'],
                        'previousJ' => $ebt['previousJ'] - $taxTotals['previousJ'],
                        'previousT' => $ebt['previousT'] - $taxTotals['previousT'],
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
                if ($sortOrderVal < 1 || $sortOrderVal > 20) {
                    $pendingSpacerForNextRevenueGroup = null;
                }
            }
            
            // Get values - handle case where there's no data
            $currentM = $hasData && $current_period ? ($areaTotals[$areaKey][$mapKey][$current_period]['mlfsi'] ?? 0) : 0;
            $currentJ = $hasData && $current_period ? ($areaTotals[$areaKey][$mapKey][$current_period]['jewelers'] ?? 0) : 0;
            $currentT = $currentM + $currentJ;
            
            $previousM = $has_previous && $hasData && $previous_period ? ($areaTotals[$areaKey][$mapKey][$previous_period]['mlfsi'] ?? 0) : 0;
            $previousJ = $has_previous && $hasData && $previous_period ? ($areaTotals[$areaKey][$mapKey][$previous_period]['jewelers'] ?? 0) : 0;
            $previousT = $previousM + $previousJ;
            
            // Check if deduction
            $isDeduction = (strpos(strtolower($mapKey), 'less') !== false || strpos(strtolower($mapKey), 'discount') !== false);
            
            // Display values
            $displayCurrentM = $isDeduction ? abs($currentM) : $currentM;
            $displayCurrentJ = $isDeduction ? abs($currentJ) : $currentJ;
            $displayCurrentT = $isDeduction ? abs($currentT) : $currentT;
            $displayPreviousM = $isDeduction ? abs($previousM) : $previousM;
            $displayPreviousJ = $isDeduction ? abs($previousJ) : $previousJ;
            $displayPreviousT = $isDeduction ? abs($previousT) : $previousT;
            
            // Calculations
            // Calculations
$incDec = $isDeduction ? ($previousT - $currentT) : ($currentT - $previousT);

if ($previousT == 0) {
    $pct = $incDec > 0 ? 100.00 : ($incDec < 0 ? -100.00 : 0.00);
} else {
    $pct = ($incDec / ($isDeduction ? abs($previousT) : $previousT)) * 100;
}
            
            // Add to totals
            $categoryTotals['currentM'] += $currentM;
            $categoryTotals['currentJ'] += $currentJ;
            $categoryTotals['currentT'] += $currentT;
            $categoryTotals['previousM'] += $previousM;
            $categoryTotals['previousJ'] += $previousJ;
            $categoryTotals['previousT'] += $previousT;

            // Add to section totals (always raw values)
            if ($sortOrderVal >= 1 && $sortOrderVal <= 20) {
                $revenueTotals['currentM'] += $currentM;
                $revenueTotals['currentJ'] += $currentJ;
                $revenueTotals['currentT'] += $currentT;
                $revenueTotals['previousM'] += $previousM;
                $revenueTotals['previousJ'] += $previousJ;
                $revenueTotals['previousT'] += $previousT;
            } elseif ($sortOrderVal === 21) {
                $costTotals['currentM'] += $currentM;
                $costTotals['currentJ'] += $currentJ;
                $costTotals['currentT'] += $currentT;
                $costTotals['previousM'] += $previousM;
                $costTotals['previousJ'] += $previousJ;
                $costTotals['previousT'] += $previousT;
            } elseif ($sortOrderVal === 22 || $sortOrderVal === 23) {
                $sellingAdminTotals['currentM'] += $currentM;
                $sellingAdminTotals['currentJ'] += $currentJ;
                $sellingAdminTotals['currentT'] += $currentT;
                $sellingAdminTotals['previousM'] += $previousM;
                $sellingAdminTotals['previousJ'] += $previousJ;
                $sellingAdminTotals['previousT'] += $previousT;
            } elseif ($sortOrderVal === 24) {
                $operatingTotals['currentM'] += $currentM;
                $operatingTotals['currentJ'] += $currentJ;
                $operatingTotals['currentT'] += $currentT;
                $operatingTotals['previousM'] += $previousM;
                $operatingTotals['previousJ'] += $previousJ;
                $operatingTotals['previousT'] += $previousT;
            } elseif ($sortOrderVal === 25) {
                $interestTotals['currentM'] += $currentM;
                $interestTotals['currentJ'] += $currentJ;
                $interestTotals['currentT'] += $currentT;
                $interestTotals['previousM'] += $previousM;
                $interestTotals['previousJ'] += $previousJ;
                $interestTotals['previousT'] += $previousT;
            } elseif ($sortOrderVal === 26) {
                $taxTotals['currentM'] += $currentM;
                $taxTotals['currentJ'] += $currentJ;
                $taxTotals['currentT'] += $currentT;
                $taxTotals['previousM'] += $previousM;
                $taxTotals['previousJ'] += $previousJ;
                $taxTotals['previousT'] += $previousT;
            }
            
            // Add detail row (show all GL mappings even if they have zero values), unless configured as summary-only.
            if ($shouldRenderDetailRow($sortOrderVal)) {
                if ($currentDetailStartRow === null) {
                    if (
                        $isSummaryExport &&
                        $sortOrderVal >= 1 &&
                        $sortOrderVal <= 20 &&
                        $pendingSpacerForNextRevenueGroup !== null
                    ) {
                        $currentDetailStartRow = $pendingSpacerForNextRevenueGroup;
                        $pendingSpacerForNextRevenueGroup = null;
                    } else {
                        $currentDetailStartRow = $r;
                    }
                }
                $worksheet->setCellValue($cell(2, $r), $comp);
                $worksheet->setCellValue($cell(4, $r), (float)$displayCurrentM);
                $worksheet->setCellValue($cell(5, $r), (float)$displayCurrentJ);
                $worksheet->setCellValue($cell(6, $r), (float)$displayCurrentT);
                $worksheet->setCellValue($cell(8, $r), (float)$displayPreviousM);
                $worksheet->setCellValue($cell(9, $r), (float)$displayPreviousJ);
                $worksheet->setCellValue($cell(10, $r), (float)$displayPreviousT);
                $worksheet->setCellValue($cell(12, $r), (float)$incDec);
                $setIncDecStyle($r, (float)$incDec);
                $setPctValueAndStyle($r, (float)$pct, (float)$previousT);
                
                $r++;
            }
        }
        
        // Add final category total if we have data
        if ($currentDesc !== null && !empty($processedGroups) && $shouldRenderCategoryTotal($currentDescSortOrder)) {
            $incDec = $categoryTotals['currentT'] - $categoryTotals['previousT'];
            $pct = $categoryTotals['previousT'] != 0 ? ($incDec / abs($categoryTotals['previousT'])) * 100 : 0;
            
            $worksheet->setCellValue($cell(0, $r), '');
            $worksheet->setCellValue($cell(1, $r), $currentDesc);
            $worksheet->setCellValue($cell(4, $r), (float)$categoryTotals['currentM']);
            $worksheet->setCellValue($cell(5, $r), (float)$categoryTotals['currentJ']);
            $worksheet->setCellValue($cell(6, $r), (float)$categoryTotals['currentT']);
            $worksheet->setCellValue($cell(8, $r), (float)$categoryTotals['previousM']);
            $worksheet->setCellValue($cell(9, $r), (float)$categoryTotals['previousJ']);
            $worksheet->setCellValue($cell(10, $r), (float)$categoryTotals['previousT']);
            $worksheet->setCellValue($cell(12, $r), (float)$incDec);
            $setIncDecStyle($r, (float)$incDec);
            $setPctValueAndStyle($r, (float)$pct, (float)$categoryTotals['previousT']);
            
            $worksheet->getStyle($rowRange($r))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFCD5B4');
            $worksheet->getStyle($rowRange($r))->getFont()->setBold(true);
            if ($currentDescSortOrder === 21) {
                $worksheet->getStyle($cell(4, $r) . ':' . $cell(13, $r))->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB(Color::COLOR_BLACK);
            }

            $summaryRow = $r;
            if (
                $isSummaryExport &&
                $currentDescSortOrder !== null &&
                $currentDescSortOrder >= 1 &&
                $currentDescSortOrder <= 20 &&
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
                $currentDescSortOrder <= 20 &&
                $currentDetailStartRow !== null
            ) {
                $collapseDetailRows($currentDetailStartRow, $summaryRow - 1, $summaryRow);
            }

            $r++; // Move to next row after summary row
            if (
                !$isSummaryExport ||
                ($currentDescSortOrder !== null && $currentDescSortOrder >= 1 && $currentDescSortOrder <= 23)
            ) {
                $r++; // Spacer between categories
            }
        }

        // Final inserts for computed rows (in case the last group doesn't cross a threshold)
        if ($currentDescSortOrder !== null) {
            if (!$revenueInserted && $currentDescSortOrder <= 20) {
                $totalRevenuesRow = $r;
                $writeComputedTotalsRow('TOTAL REVENUES', $revenueTotals, 'FFFCD5B4');
                if (
                    $isSummaryExport &&
                    $pendingSpacerForNextRevenueGroup !== null
                ) {
                    // TOTAL REVENUES: collapse the spacer row above it (after sort_order 20).
                    $collapseDetailRows($pendingSpacerForNextRevenueGroup, $pendingSpacerForNextRevenueGroup, $totalRevenuesRow);
                    $pendingSpacerForNextRevenueGroup = null;
                }
                $r++; // Spacer
                $writeSectionHeaderRow('Cost of Sales/Service');
                $revenueInserted = true;
            }
            if (!$grossProfitInserted && $currentDescSortOrder <= 21) {
                // $r++; // Spacer
                $gross = [
                    'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                    'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                    'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                    'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                    'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                    'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                ];
                $writeComputedTotalsRow('GROSS PROFIT', $gross, 'FFFF7F3A');
                $r++; // Spacer
                $writeLabelRow('SELLING & ADMIN EXPENSE');
                $grossProfitInserted = true;
            }
            if (!$sellingAdminInserted && $currentDescSortOrder <= 23) {
                $r++; // Spacer
                $writeComputedTotalsRow('TOTAL SELLING AND ADMIN EXPENSES', $sellingAdminTotals, 'FFFF7F3A');
                $r++; // Spacer
                $gross = [
                    'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                    'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                    'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                    'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                    'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                    'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                ];
                $ebitda = [
                    'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                    'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                    'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                    'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                    'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                    'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                ];
                $writeComputedTotalsRow("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", $ebitda, 'FFFF7F3A');
                $sellingAdminInserted = true;
            }
            if (!$ebitInserted && $currentDescSortOrder <= 24) {
                $r++; // Spacer
                $gross = [
                    'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                    'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                    'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                    'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                    'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                    'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                ];
                $ebitda = [
                    'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                    'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                    'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                    'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                    'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                    'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                ];
                $ebit = [
                    'currentM' => $ebitda['currentM'] - $operatingTotals['currentM'],
                    'currentJ' => $ebitda['currentJ'] - $operatingTotals['currentJ'],
                    'currentT' => $ebitda['currentT'] - $operatingTotals['currentT'],
                    'previousM' => $ebitda['previousM'] - $operatingTotals['previousM'],
                    'previousJ' => $ebitda['previousJ'] - $operatingTotals['previousJ'],
                    'previousT' => $ebitda['previousT'] - $operatingTotals['previousT'],
                ];
                $writeComputedTotalsRow('EARNINGS BEFORE INTEREST & TAXES', $ebit, 'FFFF7F3A');
                $ebitInserted = true;
            }
            if (!$ebtInserted && $currentDescSortOrder <= 25) {
                $r++; // Spacer
                $gross = [
                    'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                    'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                    'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                    'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                    'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                    'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                ];
                $ebitda = [
                    'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                    'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                    'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                    'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                    'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                    'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                ];
                $ebit = [
                    'currentM' => $ebitda['currentM'] - $operatingTotals['currentM'],
                    'currentJ' => $ebitda['currentJ'] - $operatingTotals['currentJ'],
                    'currentT' => $ebitda['currentT'] - $operatingTotals['currentT'],
                    'previousM' => $ebitda['previousM'] - $operatingTotals['previousM'],
                    'previousJ' => $ebitda['previousJ'] - $operatingTotals['previousJ'],
                    'previousT' => $ebitda['previousT'] - $operatingTotals['previousT'],
                ];
                $ebt = [
                    'currentM' => $ebit['currentM'] - $interestTotals['currentM'],
                    'currentJ' => $ebit['currentJ'] - $interestTotals['currentJ'],
                    'currentT' => $ebit['currentT'] - $interestTotals['currentT'],
                    'previousM' => $ebit['previousM'] - $interestTotals['previousM'],
                    'previousJ' => $ebit['previousJ'] - $interestTotals['previousJ'],
                    'previousT' => $ebit['previousT'] - $interestTotals['previousT'],
                ];
                $writeComputedTotalsRow('EARNINGS BEFORE TAXES', $ebt, 'FFFF7F3A');
                $ebtInserted = true;
            }
            if (!$netIncomeInserted && $currentDescSortOrder <= 26) {
                $r++; // Spacer before TOTAL NET INCOME/LOSS
                $gross = [
                    'currentM' => $revenueTotals['currentM'] - $costTotals['currentM'],
                    'currentJ' => $revenueTotals['currentJ'] - $costTotals['currentJ'],
                    'currentT' => $revenueTotals['currentT'] - $costTotals['currentT'],
                    'previousM' => $revenueTotals['previousM'] - $costTotals['previousM'],
                    'previousJ' => $revenueTotals['previousJ'] - $costTotals['previousJ'],
                    'previousT' => $revenueTotals['previousT'] - $costTotals['previousT'],
                ];
                $ebitda = [
                    'currentM' => $gross['currentM'] - $sellingAdminTotals['currentM'],
                    'currentJ' => $gross['currentJ'] - $sellingAdminTotals['currentJ'],
                    'currentT' => $gross['currentT'] - $sellingAdminTotals['currentT'],
                    'previousM' => $gross['previousM'] - $sellingAdminTotals['previousM'],
                    'previousJ' => $gross['previousJ'] - $sellingAdminTotals['previousJ'],
                    'previousT' => $gross['previousT'] - $sellingAdminTotals['previousT'],
                ];
                $ebit = [
                    'currentM' => $ebitda['currentM'] - $operatingTotals['currentM'],
                    'currentJ' => $ebitda['currentJ'] - $operatingTotals['currentJ'],
                    'currentT' => $ebitda['currentT'] - $operatingTotals['currentT'],
                    'previousM' => $ebitda['previousM'] - $operatingTotals['previousM'],
                    'previousJ' => $ebitda['previousJ'] - $operatingTotals['previousJ'],
                    'previousT' => $ebitda['previousT'] - $operatingTotals['previousT'],
                ];
                $ebt = [
                    'currentM' => $ebit['currentM'] - $interestTotals['currentM'],
                    'currentJ' => $ebit['currentJ'] - $interestTotals['currentJ'],
                    'currentT' => $ebit['currentT'] - $interestTotals['currentT'],
                    'previousM' => $ebit['previousM'] - $interestTotals['previousM'],
                    'previousJ' => $ebit['previousJ'] - $interestTotals['previousJ'],
                    'previousT' => $ebit['previousT'] - $interestTotals['previousT'],
                ];
                $net = [
                    'currentM' => $ebt['currentM'] - $taxTotals['currentM'],
                    'currentJ' => $ebt['currentJ'] - $taxTotals['currentJ'],
                    'currentT' => $ebt['currentT'] - $taxTotals['currentT'],
                    'previousM' => $ebt['previousM'] - $taxTotals['previousM'],
                    'previousJ' => $ebt['previousJ'] - $taxTotals['previousJ'],
                    'previousT' => $ebt['previousT'] - $taxTotals['previousT'],
                ];
                $writeComputedTotalsRow('TOTAL NET INCOME/LOSS', $net, 'FFFCD5B4');
                $netIncomeInserted = true;
            }
        }
    }
    
    // Set column widths (repeat per 15-column area block)
    $widths = [2, 3, 50, 1, 20, 20, 20, 2, 20, 20, 20, 2, 20, 15, 2];
    foreach ($areasToDisplay as $areaIdx => $_areaKey) {
        $baseCol = 1 + ($areaIdx * $columnsPerArea);
        foreach ($widths as $offset => $width) {
            $worksheet->getColumnDimension(Coordinate::stringFromColumnIndex($baseCol + $offset))->setWidth($width);
        }
    }
    
    // Set number formats per area block
    $lastRow = $worksheet->getHighestRow();
    if ($lastRow >= $tableStartRow) {
        // Red font for all negative AMOUNTS (not including the % column)
        $negativeAmountConditional = new Conditional();
        $negativeAmountConditional->setConditionType(Conditional::CONDITION_CELLIS);
        $negativeAmountConditional->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $negativeAmountConditional->addCondition('0');
        $negativeAmountConditional->getStyle()->getFont()->getColor()->setARGB(Color::COLOR_RED);

        foreach ($areasToDisplay as $areaIdx => $_areaKey) {
            $baseCol = 1 + ($areaIdx * $columnsPerArea);
            $curStart = Coordinate::stringFromColumnIndex($baseCol + 4);
            $curEnd = Coordinate::stringFromColumnIndex($baseCol + 6);
            $prevStart = Coordinate::stringFromColumnIndex($baseCol + 8);
            $prevEnd = Coordinate::stringFromColumnIndex($baseCol + 10);
            $incDecCol = Coordinate::stringFromColumnIndex($baseCol + 12);
            $pctCol = Coordinate::stringFromColumnIndex($baseCol + 13);
            $breakCol = Coordinate::stringFromColumnIndex($baseCol + 14);

            $worksheet->getStyle($curStart . $tableStartRow . ':' . $curEnd . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $worksheet->getStyle($prevStart . $tableStartRow . ':' . $prevEnd . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $worksheet->getStyle($incDecCol . $tableStartRow . ':' . $incDecCol . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
            $worksheet->getStyle($pctCol . $tableStartRow . ':' . $pctCol . $lastRow)->getNumberFormat()->setFormatCode('0.00');

            // Apply negative amount conditional formatting to amount columns
            foreach ([
                $curStart . $tableStartRow . ':' . $curEnd . $lastRow,
                $prevStart . $tableStartRow . ':' . $prevEnd . $lastRow,
                $incDecCol . $tableStartRow . ':' . $incDecCol . $lastRow,
            ] as $amountRange) {
                $existing = $worksheet->getStyle($amountRange)->getConditionalStyles();
                $existing[] = $negativeAmountConditional;
                $worksheet->getStyle($amountRange)->setConditionalStyles($existing);
            }

            // Every 15th column (last column of the area block) as a black break indicator
            $worksheet->getStyle($breakCol . $tableStartRow . ':' . $breakCol . $lastRow)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF000000');
        }
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
$filenamePrefix = $isSummaryExport ? 'Comparative_Report_Summary_' : 'Comparative_Report_';
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

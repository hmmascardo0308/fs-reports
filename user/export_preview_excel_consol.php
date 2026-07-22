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

session_start();

set_time_limit(300);

require_once __DIR__ . '/../config/config.php';

function getPreviewTotalsConsolidated(\mysqli $conn, ?string $zone, ?string $branch_type, ?string $month, ?string $year) {
    $zone = trim((string)$zone);
    $branch_type = trim((string)$branch_type);
    $month = trim((string)$month);
    $year = trim((string)$year);

    $where = [];
    $params = [];
    $types = '';

    if ($zone !== '') {
        $where[] = "cr.zone = ?";
        $types .= 's';
        $params[] = $zone;
    }

    if ($branch_type !== '') {
        if ($branch_type === 'Branch') {
            $where[] = "cr.transaction_type = 'Branch'";
        } elseif ($branch_type === 'Showroom') {
            $where[] = "cr.transaction_type = 'Showroom'";
        }
    }

    if ($month !== '') {
        $where[] = "DATE_FORMAT(cr.transaction_month, '%Y-%m') = ?";
        $types .= 's';
        $params[] = $month;
    }

    if ($month === '' && $year !== '') {
        $where[] = "cr.transaction_year = ?";
        $types .= 'i';
        $params[] = (int)$year;
    }

    $current_period = null;
    $previous_period = null;
    $current_label = '(Transaction Period)';
    $previous_label = '(Previous Period)';

    if ($month !== '') {
        $current_period = $month;
        $date_obj = DateTime::createFromFormat('Y-m', $month);
        if ($date_obj) {
            $date_obj->modify('-1 month');
            $previous_period = $date_obj->format('Y-m');
            $previous_label = date('F Y', strtotime($previous_period . '-01'));
        }
        $current_label = date('F Y', strtotime($current_period . '-01'));
    } elseif ($year !== '') {
        $current_period = (string)$year;
        $previous_period = (string)((int)$year - 1);
        $current_label = (string)$year;
        $previous_label = (string)((int)$year - 1);
    }

    $where[] = "(cr.status_void IS NULL OR cr.status_void != 'Void')";
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $regions = [];
    if ($zone !== '') {
        $region_query = "SELECT DISTINCT region FROM fs_reports.comparative_report WHERE zone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
        $region_stmt = mysqli_prepare($conn, $region_query);
        if ($region_stmt) {
            mysqli_stmt_bind_param($region_stmt, 's', $zone);
            mysqli_stmt_execute($region_stmt);
            $region_result = mysqli_stmt_get_result($region_stmt);
            while ($region_row = mysqli_fetch_assoc($region_result)) {
                $regions[] = $region_row['region'];
            }
            mysqli_stmt_close($region_stmt);
        }
    }

    $period_select = "";
    $group_period = "";
    if ($month !== '') {
        $period_select = "DATE_FORMAT(cr.transaction_month, '%Y-%m') AS period_key,";
        $group_period = "DATE_FORMAT(cr.transaction_month, '%Y-%m'),";
    } else {
        $period_select = "cr.transaction_year AS period_key,";
        $group_period = "cr.transaction_year,";
    }

    $sql = "
        SELECT
            COALESCE(NULLIF(gc.gl_mapping, ''), gc.gl_description_comparative) AS map_key,
            gc.gl_description_comparative AS comp,
            $period_select
            cr.region,
            SUM(cr.amount) AS total
        FROM (
            SELECT DISTINCT
                gl_mapping,
                gl_description_comparative,
                gl_code
            FROM fs_reports.gl_codes_new
            WHERE gl_description_comparative IS NOT NULL
              AND gl_description_comparative != ''
              AND gl_code IS NOT NULL
              AND gl_code != ''
        ) gc
        INNER JOIN fs_reports.comparative_report cr
            ON cr.gl_code = gc.gl_code
        $where_sql
        GROUP BY map_key, comp, $group_period cr.region
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
        $map_key = $row['map_key'] ?? '';
        $period = $row['period_key'] ?? '';
        $region = $row['region'] ?? '';
        $total = (float)($row['total'] ?? 0);

        if (!isset($totals[$map_key])) {
            $totals[$map_key] = [];
        }
        if (!isset($totals[$map_key][$period])) {
            $totals[$map_key][$period] = [];
        }
        if (!isset($totals[$map_key][$period][$region])) {
            $totals[$map_key][$period][$region] = 0;
        }
        $totals[$map_key][$period][$region] += $total;
    }

    mysqli_stmt_close($stmt);

    return [
        'totals' => $totals,
        'regions' => $regions,
        'current_period' => $current_period,
        'previous_period' => $previous_period,
        'current_label' => $current_label,
        'previous_label' => $previous_label,
        'has_previous' => ($previous_period !== null),
        'branch_type' => $branch_type
    ];
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $data = $_POST;
}

$zone = trim((string)($data['zone'] ?? ''));
$branch_type = trim((string)($data['branch_type'] ?? ''));
$month = trim((string)($data['month'] ?? ''));
$year = trim((string)($data['year'] ?? ($data['transaction_year'] ?? '')));

// Get GL data for structure - GROUP BY map_key to avoid duplicates
$has_sort_order = false;
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM fs_reports.gl_codes_new LIKE 'sort_order'");
if ($col_check && mysqli_num_rows($col_check) > 0) {
    $has_sort_order = true;
}
$sortSelect = $has_sort_order ? "sort_order" : "0 AS sort_order";
$sortGroup = $has_sort_order ? "sort_order," : "";
$orderBy = $has_sort_order ? "ORDER BY sort_order, sub_order, id" : "ORDER BY id";

$glQuery = "SELECT 
                MIN(id) as id, 
                $sortSelect, 
                MIN(sub_order) as sub_order, 
                description, 
                gl_description_comparative, 
                COALESCE(NULLIF(gl_mapping, ''), gl_description_comparative) as map_key
            FROM fs_reports.gl_codes_new 
            WHERE gl_description_comparative IS NOT NULL 
            AND gl_description_comparative != ''
            GROUP BY description, gl_description_comparative, map_key" . ($has_sort_order ? ", sort_order" : "") . "
            $orderBy";
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
$defaultSheet = $spreadsheet->getActiveSheet();
$spreadsheet->removeSheetByIndex(0);

// Fetch consolidated totals
$previewData = getPreviewTotalsConsolidated($conn, $zone, $branch_type, $month, $year);

if (isset($previewData['error'])) {
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Error');
    $spreadsheet->addSheet($worksheet);
    $worksheet->setCellValue('A1', 'Error loading data');
    $worksheet->setCellValue('A2', $previewData['error']);
} else {
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Consolidated Report');
    $spreadsheet->addSheet($worksheet);
    $worksheet->setShowSummaryBelow(true);

    $regions = $previewData['regions'] ?? [];
    $hasRegions = count($regions) > 0;
    $startRegionColIndex = 5; // Column E
    $regionCount = $hasRegions ? count($regions) : 0;
    $totalColIndex = $startRegionColIndex + $regionCount;
    $lastColIndex = $totalColIndex;
    $lastCol = Coordinate::stringFromColumnIndex($lastColIndex);

    // Freeze columns A-D and rows 1-9
    $worksheet->freezePane('E10');

    // Logo (Row 1)
    $worksheet->getRowDimension(1)->setRowHeight(55);
    $logoPath = $_SERVER['DOCUMENT_ROOT'] . '/fs-reports/images/mlhuillier.jpg';
    if (file_exists($logoPath)) {
        try {
            $logoColIndex = max(1, floor($lastColIndex / 2));
            $logoCol = Coordinate::stringFromColumnIndex($logoColIndex);
            $drawing = new Drawing();
            $drawing->setName('Logo');
            $drawing->setDescription('MLHUILLIER Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(50);
            $drawing->setOffsetX(0);
            $drawing->setOffsetY(10);
            $drawing->setCoordinates($logoCol . '1');
            $drawing->setWorksheet($worksheet);
        } catch (Exception $e) {
            // Ignore drawing errors
        }
    }

    $mergeAcross = function(int $rowNum) use ($worksheet, $lastCol) {
        $worksheet->mergeCells("A{$rowNum}:{$lastCol}{$rowNum}");
        $worksheet->getStyle("A{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $worksheet->getStyle("A{$rowNum}")->getFont()->setBold(true)->setSize(16);
    };

    // Row 2: Zone header
    $zoneKey = strtoupper($zone);
    $zoneLabelMap = [
        'LZN' => 'LUZON',
        'NCR' => 'NCR',
        'VIS' => 'VISAYAS',
        'MIN' => 'MINDANAO',
    ];
    $zoneDisplay = $zoneLabelMap[$zoneKey] ?? '';
    $title = $zoneDisplay !== ''
        ? $zoneDisplay . ' CONSOLIDATED PROFIT & LOSS STATEMENT'
        : 'CONSOLIDATED PROFIT & LOSS STATEMENT';
    $worksheet->setCellValue('A2', $title);
    $mergeAcross(2);

    // Row 3: Branch type header
    $branchTitle = 'MLFSI & JEWELERS - PER REGION';
    if ($branch_type === 'Branch') {
        $branchTitle = 'MLFSI - PER REGION';
    } elseif ($branch_type === 'Showroom') {
        $branchTitle = 'JEWELERS - PER REGION';
    }
    $worksheet->setCellValue('A3', $branchTitle);
    $mergeAcross(3);

    // Row 4: Period header
    $periodText = 'FOR THE PERIOD ENDED';
    if ($month !== '') {
        $dateObj = DateTime::createFromFormat('Y-m', $month);
        if ($dateObj) {
            $lastDay = $dateObj->format('t');
            $periodText = 'FOR THE MONTH ENDED ' . strtoupper($dateObj->format('F')) . ' ' . $lastDay . ', ' . $dateObj->format('Y');
        }
    } elseif ($year !== '') {
        $periodText = 'FOR THE YEAR ENDED DECEMBER 31, ' . $year;
    }
    $worksheet->setCellValue('A4', $periodText);
    $mergeAcross(4);

    // Rows 5-7: Empty
    $worksheet->setCellValue('A5', '');
    $worksheet->setCellValue('A6', '');
    $worksheet->setCellValue('A7', '');

    // Row 8: Region headers (start at E8)
    $headerRow = 8;
    $worksheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFFF0000');
    $worksheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_WHITE);
    $worksheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    for ($i = 0; $i < $regionCount; $i++) {
        $col = Coordinate::stringFromColumnIndex($startRegionColIndex + $i);
        $worksheet->setCellValue($col . $headerRow, $regions[$i]);
    }

    $totalLabel = 'GRAND TOTAL';
    if ($zoneKey === 'LZN') {
        $totalLabel = 'TOTAL LUZON';
    } elseif ($zoneKey === 'NCR') {
        $totalLabel = 'TOTAL NCR';
    } elseif ($zoneKey === 'VIS') {
        $totalLabel = 'TOTAL VISAYAS';
    } elseif ($zoneKey === 'MIN') {
        $totalLabel = 'TOTAL MINDANAO';
    }
    $totalCol = Coordinate::stringFromColumnIndex($totalColIndex);
    $worksheet->setCellValue($totalCol . $headerRow, $totalLabel);

    // Row 9: Empty
    $worksheet->setCellValue('A9', '');

    // Row 10: REVENUES header
    $worksheet->mergeCells("A10:D10");
    $worksheet->setCellValue('A10', 'REVENUES');
    $worksheet->getStyle("A10:{$lastCol}10")->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFB58E');
    $worksheet->getStyle("A10:{$lastCol}10")->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_BLACK);
    $worksheet->getStyle("A10:{$lastCol}10")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $r = 11;
    $tableStartRow = $r;

    $totals = $previewData['totals'] ?? [];
    $current_period = $previewData['current_period'];

    $initTotalsObject = function() use ($hasRegions, $regions) {
        if ($hasRegions) {
            $totals = ['current' => [], 'currentTotal' => 0];
            foreach ($regions as $region) {
                $totals['current'][$region] = 0;
            }
            return $totals;
        }
        return ['current' => 0];
    };

    $getValuesForMapKey = function(array $totalsMap, string $mapKey, $period) use ($hasRegions, $regions) {
        if ($period === null || $period === '' || !isset($totalsMap[$mapKey]) || !isset($totalsMap[$mapKey][$period])) {
            if ($hasRegions) {
                $empty = [];
                foreach ($regions as $region) {
                    $empty[$region] = 0;
                }
                $empty['total'] = 0;
                return $empty;
            }
            return 0;
        }
        if ($hasRegions) {
            $result = [];
            $total = 0;
            foreach ($regions as $region) {
                $value = $totalsMap[$mapKey][$period][$region] ?? 0;
                $result[$region] = $value;
                $total += $value;
            }
            $result['total'] = $total;
            return $result;
        }
        $sum = 0;
        foreach ($totalsMap[$mapKey][$period] as $value) {
            $sum += $value ?? 0;
        }
        return $sum;
    };

    $addToTotals = function(array &$target, $source) use ($hasRegions, $regions) {
        if ($hasRegions) {
            foreach ($regions as $region) {
                $target['current'][$region] += $source[$region] ?? 0;
            }
            $target['currentTotal'] += $source['total'] ?? 0;
        } else {
            $target['current'] += $source;
        }
    };

    $rowRange = function(int $rowNum) use ($lastCol) {
        return "A{$rowNum}:{$lastCol}{$rowNum}";
    };

    $writeSpacerRow = function() use (&$r) {
        $r++;
    };

    $applyCategoryTotals = function(int $rowNum, array $totals) use ($worksheet, $hasRegions, $regions, $startRegionColIndex, $totalColIndex) {
        if ($hasRegions) {
            $colIndex = $startRegionColIndex;
            foreach ($regions as $region) {
                $worksheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . $rowNum, (float)($totals['current'][$region] ?? 0));
                $colIndex++;
            }
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $rowNum, (float)($totals['currentTotal'] ?? 0));
        } else {
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $rowNum, (float)($totals['current'] ?? 0));
        }
    };

    // -----------------------------------------------------------------------
    // Border helper: apply a border to the data columns (E to lastCol) only
    // -----------------------------------------------------------------------
    $applyBorderToDataRange = function(int $rowNum, string $borderPosition, string $borderStyle) use ($worksheet, $startRegionColIndex, $totalColIndex) {
        $startCol = Coordinate::stringFromColumnIndex($startRegionColIndex);
        $endCol   = Coordinate::stringFromColumnIndex($totalColIndex);
        $range    = "{$startCol}{$rowNum}:{$endCol}{$rowNum}";

        $borderDef = [
            'borderStyle' => $borderStyle,
            'color'       => ['argb' => 'FF000000'],
        ];

        $borders = $worksheet->getStyle($range)->getBorders();
        if ($borderPosition === 'top') {
            $borders->getTop()->applyFromArray($borderDef);
        } elseif ($borderPosition === 'bottom') {
            $borders->getBottom()->applyFromArray($borderDef);
        } elseif ($borderPosition === 'bottom_double') {
            $borders->getBottom()->applyFromArray([
                'borderStyle' => Border::BORDER_DOUBLE,
                'color'       => ['argb' => 'FF000000'],
            ]);
        }
    };

    $writeCategoryRow = function(int $rowNum, int $counter, string $desc) use ($worksheet, $rowRange) {
        // 1. Set the values
        $worksheet->setCellValue("A{$rowNum}", ""); //$counter
        $worksheet->setCellValue("B{$rowNum}", $desc);

// 2. Apply background color ONLY (remove bold)
$worksheet->getStyle($rowRange($rowNum))->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FFFFE3D4');

// 3. Set alignment of columns A and B to LEFT (not center)
$worksheet->getStyle("A{$rowNum}:B{$rowNum}")->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Note: Column E and beyond will now keep their default alignment (usually right for numbers)
    };

    $writeDataRow = function(int $rowNum, string $comp, $values, bool $isDeduction) use (
        $worksheet,
        $hasRegions,
        $regions,
        $startRegionColIndex,
        $totalColIndex
    ) {
        $worksheet->setCellValue("C{$rowNum}", $comp);
        if ($hasRegions) {
            $colIndex = $startRegionColIndex;
            foreach ($regions as $region) {
                $value = $values[$region] ?? 0;
                if ($isDeduction) {
                    $value = abs($value);
                }
                $worksheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . $rowNum, (float)$value);
                $colIndex++;
            }
            $total = $values['total'] ?? 0;
            if ($isDeduction) {
                $total = abs($total);
            }
            $totalCell = Coordinate::stringFromColumnIndex($totalColIndex) . $rowNum;
            $worksheet->setCellValue($totalCell, (float)$total);
            $worksheet->getStyle($totalCell)->getFont()->setBold(true);
            $worksheet->getStyle($totalCell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFB58E');
        } else {
            $value = $values ?? 0;
            if ($isDeduction) {
                $value = abs($value);
            }
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $rowNum, (float)$value);
        }
    };

    $writeSummaryRow = function(string $label, array $totals, string $fillArgb) use (
        $worksheet,
        &$r,
        $rowRange,
        $hasRegions,
        $regions,
        $startRegionColIndex,
        $totalColIndex
    ) {
        $worksheet->setCellValue("A{$r}", $label);
        $worksheet->getStyle($rowRange($r))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($fillArgb);
        $worksheet->getStyle($rowRange($r))->getFont()->setBold(true);
        if ($hasRegions) {
            $colIndex = $startRegionColIndex;
            foreach ($regions as $region) {
                $worksheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . $r, (float)($totals['current'][$region] ?? 0));
                $colIndex++;
            }
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $r, (float)($totals['currentTotal'] ?? 0));
        } else {
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $r, (float)($totals['current'] ?? 0));
        }
        $r++;
    };

    $writeSectionHeaderRow = function(string $label, string $fillArgb) use ($worksheet, &$r, $rowRange) {
        $worksheet->mergeCells("A{$r}:D{$r}");
        $worksheet->setCellValue("A{$r}", $label);
        $worksheet->getStyle($rowRange($r))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($fillArgb);
        $worksheet->getStyle($rowRange($r))->getFont()->setBold(true);
        $r++;
    };

    $applyOutline = function(int $rowNum, int $level, bool $hidden, bool $collapsed) use ($worksheet) {
        $dim = $worksheet->getRowDimension($rowNum);
        $dim->setOutlineLevel($level);
        if ($hidden) {
            $dim->setVisible(false);
        }
        if ($collapsed) {
            $dim->setCollapsed(true);
        }
    };

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

    $applyRevenueCategoryFill = function(int $rowNum, int $sortOrder) use ($worksheet, $rowRange) {
        if ($sortOrder < 1 || $sortOrder > 20) {
            return;
        }
        if ($sortOrder % 2 === 0) {
            $worksheet->getStyle($rowRange($rowNum))->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFE3D4');
        } else {
            $worksheet->getStyle($rowRange($rowNum))->getFill()
                ->setFillType(Fill::FILL_NONE);
        }
    };

    $revenueTotals = $initTotalsObject();
    $costTotals = $initTotalsObject();
    $sellingAdminTotals = $initTotalsObject();
    $operatingTotals = $initTotalsObject();
    $interestTotals = $initTotalsObject();
    $taxTotals = $initTotalsObject();

    $revenueInserted = false;
    $grossProfitInserted = false;
    $sellingAdminInserted = false;
    $ebitInserted = false;
    $ebtInserted = false;
    $netIncomeInserted = false;

    $summaryOnlySortOrders = [6, 8, 11];
    $detailOnlySortOrders = [24, 25, 26];   // These will show details but NO category total row
    $collapseRevenueDetails = true;         // Collapse detail rows for sort_order 1-20
    $processedGroups = [];

    $insertTotalRevenues = function() use (&$r, $writeSummaryRow, &$revenueTotals) {
        $rowNum = $r;
        $writeSummaryRow('TOTAL REVENUES', $revenueTotals, 'FFB58E');
        return $rowNum;
    };

    $insertGrossProfit = function() use ($hasRegions, $regions, &$revenueTotals, &$costTotals, $writeSummaryRow) {
        if ($hasRegions) {
            $gross = ['current' => [], 'currentTotal' => 0];
            foreach ($regions as $region) {
                $gross['current'][$region] = ($revenueTotals['current'][$region] ?? 0) - ($costTotals['current'][$region] ?? 0);
                $gross['currentTotal'] += $gross['current'][$region];
            }
        } else {
            $gross = ['current' => ($revenueTotals['current'] ?? 0) - ($costTotals['current'] ?? 0)];
        }
        $writeSummaryRow('GROSS PROFIT', $gross, 'FFFFC2A4');
    };

    $insertTotalSellingAdmin = function() use (&$r, $writeSummaryRow, $worksheet, $rowRange, $hasRegions, $regions, $startRegionColIndex, $totalColIndex, &$sellingAdminTotals, $mergeAcross) {

        // Create the total row with proper category styling
        $worksheet->setCellValue("A{$r}", ""); // Leave counter empty
        $worksheet->setCellValue("B{$r}", "");
        $worksheet->getStyle($rowRange($r))->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFC8A8'); // Orange color for totals
        $worksheet->getStyle($rowRange($r))->getFont()->setBold(true);

        // Apply the totals values
        if ($hasRegions) {
            $colIndex = $startRegionColIndex;
            foreach ($regions as $region) {
                $worksheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . $r, (float)($sellingAdminTotals['current'][$region] ?? 0));
                $colIndex++;
            }
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $r, (float)($sellingAdminTotals['currentTotal'] ?? 0));
        } else {
            $worksheet->setCellValue(Coordinate::stringFromColumnIndex($totalColIndex) . $r, (float)($sellingAdminTotals['current'] ?? 0));
        }

        $r++; // Move to next row for spacing
    };

    // -----------------------------------------------------------------------
    // EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT
    // Border: top single on data columns (E to lastCol)
    // -----------------------------------------------------------------------
    $insertEarningsBefore = function() use ($hasRegions, $regions, &$revenueTotals, &$costTotals, &$sellingAdminTotals, $writeSummaryRow, $applyBorderToDataRange, &$r) {
        if ($hasRegions) {
            $ebitda = ['current' => [], 'currentTotal' => 0];
            foreach ($regions as $region) {
                $gross = ($revenueTotals['current'][$region] ?? 0) - ($costTotals['current'][$region] ?? 0);
                $ebitda['current'][$region] = $gross - ($sellingAdminTotals['current'][$region] ?? 0);
                $ebitda['currentTotal'] += $ebitda['current'][$region];
            }
        } else {
            $gross = ($revenueTotals['current'] ?? 0) - ($costTotals['current'] ?? 0);
            $ebitda = ['current' => $gross - ($sellingAdminTotals['current'] ?? 0)];
        }
        $writeSummaryRow("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", $ebitda, 'FFFFC8A8');
        $applyBorderToDataRange($r - 1, 'top', Border::BORDER_THIN);
    };

    // -----------------------------------------------------------------------
    // EARNINGS BEFORE INTEREST & TAXES
    // Border: top single on data columns (E to lastCol)
    // -----------------------------------------------------------------------
    $insertEarningsBeforeInterestTaxes = function() use ($hasRegions, $regions, &$revenueTotals, &$costTotals, &$sellingAdminTotals, &$operatingTotals, $writeSummaryRow, $applyBorderToDataRange, &$r) {
        if ($hasRegions) {
            $ebit = ['current' => [], 'currentTotal' => 0];
            foreach ($regions as $region) {
                $gross = ($revenueTotals['current'][$region] ?? 0) - ($costTotals['current'][$region] ?? 0);
                $ebitda = $gross - ($sellingAdminTotals['current'][$region] ?? 0);
                $ebit['current'][$region] = $ebitda - ($operatingTotals['current'][$region] ?? 0);
                $ebit['currentTotal'] += $ebit['current'][$region];
            }
        } else {
            $gross = ($revenueTotals['current'] ?? 0) - ($costTotals['current'] ?? 0);
            $ebitda = $gross - ($sellingAdminTotals['current'] ?? 0);
            $ebit = ['current' => $ebitda - ($operatingTotals['current'] ?? 0)];
        }
        $writeSummaryRow('EARNINGS BEFORE INTEREST & TAXES', $ebit, 'FFFFCCB0');
        $applyBorderToDataRange($r - 1, 'top', Border::BORDER_THIN);
    };

    // -----------------------------------------------------------------------
    // EARNINGS BEFORE TAXES
    // Border: top single on data columns (E to lastCol)
    // -----------------------------------------------------------------------
    $insertEarningsBeforeTaxes = function() use ($hasRegions, $regions, &$revenueTotals, &$costTotals, &$sellingAdminTotals, &$operatingTotals, &$interestTotals, $writeSummaryRow, $applyBorderToDataRange, &$r) {
        if ($hasRegions) {
            $ebt = ['current' => [], 'currentTotal' => 0];
            foreach ($regions as $region) {
                $gross = ($revenueTotals['current'][$region] ?? 0) - ($costTotals['current'][$region] ?? 0);
                $ebitda = $gross - ($sellingAdminTotals['current'][$region] ?? 0);
                $ebit = $ebitda - ($operatingTotals['current'][$region] ?? 0);
                $ebt['current'][$region] = $ebit - ($interestTotals['current'][$region] ?? 0);
                $ebt['currentTotal'] += $ebt['current'][$region];
            }
        } else {
            $gross = ($revenueTotals['current'] ?? 0) - ($costTotals['current'] ?? 0);
            $ebitda = $gross - ($sellingAdminTotals['current'] ?? 0);
            $ebit = $ebitda - ($operatingTotals['current'] ?? 0);
            $ebt = ['current' => $ebit - ($interestTotals['current'] ?? 0)];
        }
        $writeSummaryRow('EARNINGS BEFORE TAXES', $ebt, 'FFFDCBB4');
        $applyBorderToDataRange($r - 1, 'top', Border::BORDER_THIN);
    };

    // -----------------------------------------------------------------------
    // TOTAL NET INCOME/LOSS
    // Border: top single + bottom double on data columns (E to lastCol)
    // -----------------------------------------------------------------------
    $insertTotalNetIncome = function() use ($hasRegions, $regions, &$revenueTotals, &$costTotals, &$sellingAdminTotals, &$operatingTotals, &$interestTotals, &$taxTotals, $writeSummaryRow, $applyBorderToDataRange, &$r) {
        if ($hasRegions) {
            $net = ['current' => [], 'currentTotal' => 0];
            foreach ($regions as $region) {
                $gross = ($revenueTotals['current'][$region] ?? 0) - ($costTotals['current'][$region] ?? 0);
                $ebitda = $gross - ($sellingAdminTotals['current'][$region] ?? 0);
                $ebit = $ebitda - ($operatingTotals['current'][$region] ?? 0);
                $ebt = $ebit - ($interestTotals['current'][$region] ?? 0);
                $net['current'][$region] = $ebt - ($taxTotals['current'][$region] ?? 0);
                $net['currentTotal'] += $net['current'][$region];
            }
        } else {
            $gross = ($revenueTotals['current'] ?? 0) - ($costTotals['current'] ?? 0);
            $ebitda = $gross - ($sellingAdminTotals['current'] ?? 0);
            $ebit = $ebitda - ($operatingTotals['current'] ?? 0);
            $ebt = $ebit - ($interestTotals['current'] ?? 0);
            $net = ['current' => $ebt - ($taxTotals['current'] ?? 0)];
        }
        $writeSummaryRow('TOTAL NET INCOME/LOSS', $net, 'FFB58E');
        $applyBorderToDataRange($r - 1, 'top',           Border::BORDER_THIN);
        $applyBorderToDataRange($r - 1, 'bottom_double', Border::BORDER_DOUBLE);
    };

    $insertSectionTotals = function($currentSortOrder, $newSortOrder) use (
        &$revenueInserted,
        &$grossProfitInserted,
        &$sellingAdminInserted,
        &$ebitInserted,
        &$ebtInserted,
        &$netIncomeInserted,
        $insertTotalRevenues,
        $insertGrossProfit,
        $insertTotalSellingAdmin,
        $insertEarningsBefore,
        $insertEarningsBeforeInterestTaxes,
        $insertEarningsBeforeTaxes,
        $insertTotalNetIncome,
        $writeSectionHeaderRow,
        $writeSpacerRow,
        $collapseDetailRows,
        &$pendingSpacerForNextRevenueGroup,
        $collapseRevenueDetails
    ) {
        if (!$revenueInserted && $currentSortOrder !== null && $currentSortOrder <= 20 && $newSortOrder > 20) {
            $totalRevenuesRow = $insertTotalRevenues();
            if ($collapseRevenueDetails && $pendingSpacerForNextRevenueGroup !== null) {
                $collapseDetailRows($pendingSpacerForNextRevenueGroup, $pendingSpacerForNextRevenueGroup, $totalRevenuesRow);
                $pendingSpacerForNextRevenueGroup = null;
            }
            $writeSpacerRow();
            $writeSectionHeaderRow('Cost of Sales/Service', 'FFFFE3D4');
            $revenueInserted = true;
        }
        if (!$grossProfitInserted && $currentSortOrder !== null && $currentSortOrder <= 21 && $newSortOrder > 21) {
            $insertGrossProfit();
            $writeSpacerRow();
            $writeSectionHeaderRow('SELLING & ADMIN EXPENSE', 'FFFFC2A4');
            $grossProfitInserted = true;
        }
        if (!$sellingAdminInserted && $currentSortOrder !== null && $currentSortOrder <= 23 && $newSortOrder > 23) {
            $insertTotalSellingAdmin();
            $writeSpacerRow();
            $insertEarningsBefore();
            $sellingAdminInserted = true;
        }
        if (!$ebitInserted && $currentSortOrder !== null && $currentSortOrder <= 24 && $newSortOrder > 24) {
            $writeSpacerRow();
            $insertEarningsBeforeInterestTaxes();
            $ebitInserted = true;
        }
        if (!$ebtInserted && $currentSortOrder !== null && $currentSortOrder <= 25 && $newSortOrder > 25) {
            $writeSpacerRow();
            $insertEarningsBeforeTaxes();
            $ebtInserted = true;
        }
        if (!$netIncomeInserted && $currentSortOrder !== null && $currentSortOrder <= 26 && $newSortOrder > 26) {
            $writeSpacerRow();
            $insertTotalNetIncome();
            $netIncomeInserted = true;
        }
    };

    $insertFinalSectionTotals = function($currentSortOrder) use (
        &$revenueInserted,
        &$grossProfitInserted,
        &$sellingAdminInserted,
        &$ebitInserted,
        &$ebtInserted,
        &$netIncomeInserted,
        $insertTotalRevenues,
        $insertGrossProfit,
        $insertTotalSellingAdmin,
        $insertEarningsBefore,
        $insertEarningsBeforeInterestTaxes,
        $insertEarningsBeforeTaxes,
        $insertTotalNetIncome,
        $writeSectionHeaderRow,
        $writeSpacerRow,
        $collapseDetailRows,
        &$pendingSpacerForNextRevenueGroup,
        $collapseRevenueDetails
    ) {
        if (!$revenueInserted && $currentSortOrder !== null && $currentSortOrder <= 20) {
            $totalRevenuesRow = $insertTotalRevenues();
            if ($collapseRevenueDetails && $pendingSpacerForNextRevenueGroup !== null) {
                $collapseDetailRows($pendingSpacerForNextRevenueGroup, $pendingSpacerForNextRevenueGroup, $totalRevenuesRow);
                $pendingSpacerForNextRevenueGroup = null;
            }
            $writeSpacerRow();
            $writeSectionHeaderRow('Cost of Sales/Service', 'FFFFE3D4');
        }
        if (!$grossProfitInserted && $currentSortOrder !== null && $currentSortOrder <= 21) {
            $insertGrossProfit();
            $writeSpacerRow();
            $writeSectionHeaderRow('SELLING & ADMIN EXPENSE', 'FFFFC2A4');
        }
        if (!$sellingAdminInserted && $currentSortOrder !== null && $currentSortOrder <= 23) {
            $writeSpacerRow();
            $insertTotalSellingAdmin();
            $writeSpacerRow();
            $insertEarningsBefore();
        }
        if (!$ebitInserted && $currentSortOrder !== null && $currentSortOrder <= 24) {
            $writeSpacerRow();
            $insertEarningsBeforeInterestTaxes();
        }
        if (!$ebtInserted && $currentSortOrder !== null && $currentSortOrder <= 25) {
            $writeSpacerRow();
            $insertEarningsBeforeTaxes();
        }
        if (!$netIncomeInserted && $currentSortOrder !== null && $currentSortOrder <= 26) {
            $writeSpacerRow();
            $insertTotalNetIncome();
        }
    };

    $mainCounter = 0;
    $currentDesc = null;
    $currentSortOrder = null;
    $pendingCategoryValues = null;
    $pendingDetailRows = [];
    $pendingCategoryNumber = null;
    $pendingCategoryDesc = null;
    $currentDetailStartRow = null;
    $pendingSpacerForNextRevenueGroup = null;

    foreach ($glRows as $index => $row) {
        $desc = $row['description'] ?? '';
        $comp = $row['gl_description_comparative'] ?? '';
        $mapKey = $row['map_key'] ?? $comp;
        $sortOrder = (int)($row['sort_order'] ?? 0);
        $groupKey = $desc . '||' . $mapKey;
        $isDeduction = ($mapKey === 'less_sales_return_discount');

        if (isset($processedGroups[$groupKey])) {
            continue;
        }
        $processedGroups[$groupKey] = true;

        $currentValues = $getValuesForMapKey($totals, $mapKey, $current_period);

        // When category changes, output previous category's details (and total only if not in detailOnlySortOrders)
        if ($desc !== $currentDesc) {
            $isPrevRevenueGroup = ($currentSortOrder !== null && $currentSortOrder >= 1 && $currentSortOrder <= 20);
            $detailStartRow = null;
            $detailEndRow = null;

            // Output all stored detail rows for the previous category
            foreach ($pendingDetailRows as $detailRow) {
                $writeDataRow($r, $detailRow['comp'], $detailRow['values'], $detailRow['isDeduction']);
                if ($collapseRevenueDetails && ($detailRow['sortOrder'] ?? 0) >= 1 && ($detailRow['sortOrder'] ?? 0) <= 20) {
                    $applyOutline($r, 1, true, false);
                }
                if ($isPrevRevenueGroup) {
                    if ($detailStartRow === null) {
                        $detailStartRow = $r;
                    }
                    $detailEndRow = $r;
                }
                $r++;
            }

            // Output category total row ONLY if NOT in detailOnlySortOrders
            if ($pendingCategoryValues !== null && $currentDesc !== null && !in_array($currentSortOrder, $detailOnlySortOrders, true)) {

                $writeCategoryRow($r, $pendingCategoryNumber, $pendingCategoryDesc);
                $applyCategoryTotals($r, $pendingCategoryValues);
                $applyRevenueCategoryFill($r, (int)$currentSortOrder);

                // -----------------------------------------------------------------
                // Bottom single border for sort_order 21 (Cost of Sales total row)
                // -----------------------------------------------------------------
                if ((int)$currentSortOrder === 21) {
                    $applyBorderToDataRange($r, 'bottom', Border::BORDER_THIN);
                }

                if ($collapseRevenueDetails && $currentSortOrder >= 1 && $currentSortOrder <= 20) {
                    if ($pendingSpacerForNextRevenueGroup !== null) {
                        $currentDetailStartRow = $pendingSpacerForNextRevenueGroup;
                        $pendingSpacerForNextRevenueGroup = null;
                    } else {
                        $currentDetailStartRow = $detailStartRow;
                    }
                    if ($currentDetailStartRow !== null) {
                        $collapseDetailRows($currentDetailStartRow, $r - 1, $r);
                    }
                    $currentDetailStartRow = null;
                }

                $r++;
                if ($currentSortOrder < 1 || $currentSortOrder > 20) {
                    $writeSpacerRow();
                } else {
                    $writeSpacerRow();
                    $spacerRowNum = $r - 1;
                    $applyOutline($spacerRowNum, 1, true, false);
                    $pendingSpacerForNextRevenueGroup = $spacerRowNum;
                }
            }

            if ($currentSortOrder !== null) {
                $insertSectionTotals($currentSortOrder, $sortOrder);
            }

            // Reset for new category
            $currentDesc = $desc;
            $currentSortOrder = $sortOrder ?: $currentSortOrder;
            $pendingCategoryValues = $initTotalsObject();
            $pendingDetailRows = [];

            if ($desc !== '' && !in_array($sortOrder, $detailOnlySortOrders, true)) {
                $mainCounter++;
                $pendingCategoryNumber = $mainCounter;
                $pendingCategoryDesc = $desc;
            } else {
                $pendingCategoryNumber = null;
                $pendingCategoryDesc = null;
            }
        }

        // Accumulate totals for the current category
        if ($pendingCategoryValues !== null) {
            $addToTotals($pendingCategoryValues, $currentValues);
        }

        // Accumulate for major section totals (Revenue, Cost, etc.)
        if ($sortOrder >= 1 && $sortOrder <= 20) {
            $addToTotals($revenueTotals, $currentValues);
        }
        if ($sortOrder === 21) {
            $addToTotals($costTotals, $currentValues);
        }
        if ($sortOrder === 22 || $sortOrder === 23) {
            $addToTotals($sellingAdminTotals, $currentValues);
        }
        if ($sortOrder === 24) {
            $addToTotals($operatingTotals, $currentValues);
        }
        if ($sortOrder === 25) {
            $addToTotals($interestTotals, $currentValues);
        }
        if ($sortOrder === 26) {
            $addToTotals($taxTotals, $currentValues);
        }

        // Store detail row (except summary-only items)
        $hideDetailRow = in_array($sortOrder, $summaryOnlySortOrders, true);
        if (!$hideDetailRow) {
            if ($desc !== '' && !in_array($sortOrder, $detailOnlySortOrders, true)) {
                // Normal detail under a category
                $pendingDetailRows[] = [
                    'comp' => $comp,
                    'values' => $currentValues,
                    'isDeduction' => $isDeduction,
                    'sortOrder' => $sortOrder
                ];
            } else {
                // Items with no category or detail-only sort orders (24,25,26)
                $writeDataRow($r, $comp, $currentValues, $isDeduction);
                if ($collapseRevenueDetails && $sortOrder >= 1 && $sortOrder <= 20) {
                    $applyOutline($r, 1, true, false);
                }
                $r++;
            }
        }

        // Process final category
        if ($index === count($glRows) - 1) {
            $isPrevRevenueGroup = ($currentSortOrder !== null && $currentSortOrder >= 1 && $currentSortOrder <= 20);
            $detailStartRow = null;

            // Output remaining detail rows
            foreach ($pendingDetailRows as $detailRow) {
                $writeDataRow($r, $detailRow['comp'], $detailRow['values'], $detailRow['isDeduction']);
                if ($collapseRevenueDetails && ($detailRow['sortOrder'] ?? 0) >= 1 && ($detailRow['sortOrder'] ?? 0) <= 20) {
                    $applyOutline($r, 1, true, false);
                }
                if ($isPrevRevenueGroup && $detailStartRow === null) {
                    $detailStartRow = $r;
                }
                $r++;
            }

            // Output final category total (only if not detailOnly)
            if ($pendingCategoryValues !== null && $currentDesc !== null && !in_array($currentSortOrder, $detailOnlySortOrders, true)) {
                $worksheet->setCellValue("A{$r}", $pendingCategoryNumber);
                $worksheet->setCellValue("B{$r}", $pendingCategoryDesc);
                $applyCategoryTotals($r, $pendingCategoryValues);
                $applyRevenueCategoryFill($r, (int)$currentSortOrder);

                // -----------------------------------------------------------------
                // Bottom single border for sort_order 21 (Cost of Sales total row)
                // -----------------------------------------------------------------
                if ((int)$currentSortOrder === 21) {
                    $applyBorderToDataRange($r, 'bottom', Border::BORDER_THIN);
                }

                if ($collapseRevenueDetails && $currentSortOrder >= 1 && $currentSortOrder <= 20) {
                    if ($pendingSpacerForNextRevenueGroup !== null) {
                        $currentDetailStartRow = $pendingSpacerForNextRevenueGroup;
                        $pendingSpacerForNextRevenueGroup = null;
                    } else {
                        $currentDetailStartRow = $detailStartRow;
                    }
                    if ($currentDetailStartRow !== null) {
                        $collapseDetailRows($currentDetailStartRow, $r - 1, $r);
                    }
                    $currentDetailStartRow = null;
                }
                $r++;
                $writeSpacerRow();
                if ($collapseRevenueDetails && $currentSortOrder >= 1 && $currentSortOrder <= 20) {
                    $spacerRowNum = $r - 1;
                    $applyOutline($spacerRowNum, 1, true, false);
                    $pendingSpacerForNextRevenueGroup = $spacerRowNum;
                }
            }

            $insertFinalSectionTotals($currentSortOrder);
        }
    }

    // Column widths
    $worksheet->getColumnDimension('A')->setWidth(2);
    $worksheet->getColumnDimension('B')->setWidth(36);
    $worksheet->getColumnDimension('C')->setWidth(40);
    $worksheet->getColumnDimension('D')->setWidth(2);

    // Loop through Column E (index 5) onwards to enable AutoSize
    for ($i = 0; $i <= $regionCount; $i++) {
        $col = Coordinate::stringFromColumnIndex($startRegionColIndex + $i);
        $worksheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Number formats + negative amount formatting
    $lastRow = $worksheet->getHighestRow();
    if ($lastRow >= $tableStartRow) {
        $startCol = Coordinate::stringFromColumnIndex($startRegionColIndex);
        $worksheet->getStyle("{$startCol}{$tableStartRow}:{$lastCol}{$lastRow}")
            ->getNumberFormat()->setFormatCode('#,##0.00');

        $negativeConditional = new Conditional();
        $negativeConditional->setConditionType(Conditional::CONDITION_CELLIS);
        $negativeConditional->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $negativeConditional->addCondition('0');
        $negativeConditional->getStyle()->getFont()->getColor()->setARGB(Color::COLOR_RED);

        $range = "{$startCol}{$tableStartRow}:{$lastCol}{$lastRow}";
        $existing = $worksheet->getStyle($range)->getConditionalStyles();
        $existing[] = $negativeConditional;
        $worksheet->getStyle($range)->setConditionalStyles($existing);
    }
}

// Set first sheet as active
if ($spreadsheet->getSheetCount() > 0) {
    $spreadsheet->setActiveSheetIndex(0);
} else {
    $worksheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'No Data');
    $spreadsheet->addSheet($worksheet);
    $worksheet->setCellValue('A1', 'No data available for the selected filters.');
}

$filename = 'Consolidated_Report_' . date('Y-m-d_His') . '.xlsx';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

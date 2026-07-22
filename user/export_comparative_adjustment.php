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
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Check authorization
if (!isset($_SESSION['username'])) {
    die("Unauthorized access");
}

// Initialize filter variables
$mainzone = $_GET['mainzone'] ?? '';
$zone = $_GET['zone'] ?? '';
$region = $_GET['region'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';

if (!empty($primary_period) && preg_match('/^\d{4}-\d{2}$/', $primary_period)) {
    $primary_year = (int)substr($primary_period, 0, 4);
    $primary_month = $primary_period . '-01';
} else {
    die("Primary period is required");
}

if (!empty($previous_period) && preg_match('/^\d{4}-\d{2}$/', $previous_period)) {
    $previous_year = (int)substr($previous_period, 0, 4);
    $previous_month = $previous_period . '-01';
} else {
    die("Previous period is required");
}

// Helper function to calculate percentage
function calculatePercentage(float $current, float $previous): float {
    if ($previous != 0) {
        return (($current - $previous) / $previous) * 100;
    } else {
        // Previous period total is 0
        if ($current > 0) {
            return 100.00;  // Positive growth from zero
        } elseif ($current < 0) {
            return -100.00; // Negative from zero
        } else {
            return 0.00;    // Both are zero
        }
    }
}

// Helper function to format percentage with MAT handling
function formatPercentage(float $pct, bool &$isMat = false, bool &$isNegative = false): float|string {
    $isMat = false;
    $isNegative = false;
    
    if (abs($pct) > 1000) {
        $isMat = true;
        $isNegative = ($pct < 0);
        return 'mat';
    }
    return $pct;
}

// Data fetching functions
function getRegionsToDisplay(mysqli $conn, string $mainzone, string $zone, string $region): array {
    $regions = [];
    if (!empty($region)) {
        $regions[] = $region;
    } elseif (!empty($zone)) {
        $sql = "SELECT DISTINCT region FROM manual_adjustment WHERE zone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $zone);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $regions[] = $row['region'];
        }
        $stmt->close();
    } elseif (!empty($mainzone)) {
        $sql = "SELECT DISTINCT region FROM manual_adjustment WHERE mainzone = ? AND region IS NOT NULL AND region != '' ORDER BY region";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $mainzone);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $regions[] = $row['region'];
        }
        $stmt->close();
    } else {
        $sql = "SELECT DISTINCT region FROM manual_adjustment WHERE region IS NOT NULL AND region != '' ORDER BY region LIMIT 20";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $regions[] = $row['region'];
        }
    }
    return $regions;
}

function fetchRegionData(mysqli $conn, string $region, string $month, int $year, string $mainzone, string $zone): array {
    $data = [];
    $where_conditions = ["region = ?", "transaction_month = ?", "transaction_year = ?"];
    $params = [$region, $month, $year];
    $types = "ssi";
    if (!empty($mainzone)) {
        $where_conditions[] = "mainzone = ?";
        $params[] = $mainzone; $types .= "s";
    }
    if (!empty($zone)) {
        $where_conditions[] = "zone = ?";
        $params[] = $zone; $types .= "s";
    }
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    $sql = "SELECT sort_order, description, sub_order, gl_description_comparative, mlfsi, jewelers FROM manual_adjustment $where_clause ORDER BY sort_order, sub_order";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $key = $row['sort_order'] . '|' . $row['sub_order'];
            $data[$key] = $row;
        }
        $stmt->close();
    }
    return $data;
}

$regions_to_display = getRegionsToDisplay($conn, $mainzone, $zone, $region);

if (empty($regions_to_display)) {
    die("No data found for the selected filters.");
}

$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

// Styles
$centerBold = ['font' => ['bold' => true], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
$categoryTotalStyle = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFDBC7']]];
$sectionTotalStyle = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFAD76']], 'font' => ['bold' => true]];
$ebitdaStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFAD76']
    ],
    'font' => ['bold' => true],
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$ebitStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFAD76']
    ],
    'font' => ['bold' => true],
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THICK,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$ebtStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFAD76']
    ],
    'font' => ['bold' => true],
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THICK,
            'color' => ['rgb' => '000000']
        ]
    ]
];
$netStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFAD76']
    ],
    'font' => ['bold' => true],
    'borders' => [
        'top' => [
            'borderStyle' => Border::BORDER_THICK, // single line
            'color' => ['rgb' => '000000']
        ],
        'bottom' => [
            'borderStyle' => Border::BORDER_DOUBLE, // double line
            'color' => ['rgb' => '000000']
        ]
    ]
];

// Add right alignment style for MAT values
$matRightAlign = ['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT]];

foreach ($regions_to_display as $current_region) {
    $primary_data = fetchRegionData($conn, $current_region, $primary_month, $primary_year, $mainzone, $zone);
    $previous_data = fetchRegionData($conn, $current_region, $previous_month, $previous_year, $mainzone, $zone);
    
    if (empty($primary_data) && empty($previous_data)) continue;

    $sheet = $spreadsheet->createSheet();
    $sheetTitle = substr(preg_replace('/[^A-Za-z0-9 ]/', '', $current_region), 0, 31);
    $sheet->setTitle($sheetTitle);
    $sheet->freezePane('D10');

    // Headers
    // Row 1: Logo
    $sheet->getRowDimension(1)->setRowHeight(40);
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setPath('C:\xampp\htdocs\fs-reports\images\mlhuillier.jpg');
    $drawing->setHeight(50);
    $drawing->setCoordinates('E1');
    $drawing->setWorksheet($sheet);

    // Row 2: Title
    $sheet->mergeCells('A2:N2');
    $sheet->setCellValue('A2', strtoupper($current_region) . " COMPARATIVE PROFIT & LOSS STATEMENT");
    $sheet->getStyle('A2')->applyFromArray($centerBold)->getFont()->setSize(14);

    // Row 3: MLFSI & JEWELERS
    $sheet->mergeCells('A3:N3');
    $sheet->setCellValue('A3', "MLFSI & JEWELERS");
    $sheet->getStyle('A3')->applyFromArray($centerBold)->getFont()->setSize(14);

    // Row 4: Period Comparison
    $pLabel = strtoupper(date('F Y', strtotime($primary_month)));
    $prevLabel = strtoupper(date('F Y', strtotime($previous_month)));
    $sheet->mergeCells('A4:N4');
    $sheet->setCellValue('A4', "$pLabel VS $prevLabel");
    $sheet->getStyle('A4')->applyFromArray($centerBold)->getFont()->setSize(14);

    // Row 7: Month names

    // Background for E7:G7
    $sheet->getStyle('E7:G7')->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => [
                'argb' => 'F29741', // Yellow (change this as needed)
            ],
        ],
    ]);

    // Background for I7:K7
    $sheet->getStyle('I7:K7')->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => [
                'argb' => 'F29741', // Light orange (change this as needed)
            ],
        ],
    ]);
    $sheet->mergeCells('E7:G7');
    $sheet->setCellValue('E7', strtoupper(date('F', strtotime($primary_month))));
    $sheet->mergeCells('I7:K7');
    $sheet->setCellValue('I7', strtoupper(date('F', strtotime($previous_month))));
    $sheet->getStyle('E7:K7')->applyFromArray($centerBold);

    // Row 8: Headers
    $highlightCells = [
        'E8','F8','G8', // MLFSI, JEWELERS, TOTAL (current)
        'I8','J8','K8', // MLFSI, JEWELERS, TOTAL (previous)
        'M8','N8'       // Inc./Dec., %
    ];

    foreach ($highlightCells as $cell) {
        $sheet->getStyle($cell)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'argb' => 'FFAD76',
                ],
            ],
        ]);
    }
    $headers = ['', '', '', '', 'MLFSI', 'JEWELERS', 'TOTAL', '', 'MLFSI', 'JEWELERS', 'TOTAL', '', 'Inc./Dec.', '%'];
    foreach ($headers as $colIdx => $header) {
        $cellAddress = Coordinate::stringFromColumnIndex($colIdx + 1) . '8';
        if ($header === 'Inc./Dec.') {
            $richText = new RichText();
            $incPart = $richText->createTextRun('Inc./');
            $incPart->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_BLACK);

            $decPart = $richText->createTextRun('Dec.');
            $decPart->getFont()->setBold(true)->getColor()->setARGB(Color::COLOR_RED);

            $sheet->setCellValue($cellAddress, $richText);
        } else {
            $sheet->setCellValue($cellAddress, $header);
        }
    }
    $sheet->getStyle('A8:N8')->applyFromArray($centerBold);

    $rowNum = 10;
    
    // Init calculation variables
    $total_rev_mlfsi_primary = 0; $total_rev_jewelers_primary = 0; $total_rev_total_primary = 0;
    $total_rev_mlfsi_previous = 0; $total_rev_jewelers_previous = 0; $total_rev_total_previous = 0;
    $total_sa_mlfsi_primary = 0; $total_sa_jewelers_primary = 0; $total_sa_total_primary = 0;
    $total_sa_mlfsi_previous = 0; $total_sa_jewelers_previous = 0; $total_sa_total_previous = 0;
    $gp_mlfsi_primary = 0; $gp_mlfsi_previous = 0; $gp_jewelers_primary = 0; $gp_jewelers_previous = 0; $gp_total_primary = 0; $gp_total_previous = 0;
    $ebitda_mlfsi_primary = 0; $ebitda_mlfsi_previous = 0; $ebitda_jewelers_primary = 0; $ebitda_jewelers_previous = 0; $ebitda_total_primary = 0; $ebitda_total_previous = 0;

    $all_keys = array_unique(array_merge(array_keys($primary_data), array_keys($previous_data)));
    usort($all_keys, function($a, $b) {
        $partsA = explode('|', $a); $partsB = explode('|', $b);
        if ($partsA[0] != $partsB[0]) return (int)$partsA[0] - (int)$partsB[0];
        return (int)$partsA[1] - (int)$partsB[1];
    });

    $all_rows_grouped = [];
    foreach ($all_keys as $key) {
        $row = $primary_data[$key] ?? null; $prev = $previous_data[$key] ?? null;
        $display_row = $row ?: $prev;
        $all_rows_grouped[$display_row['sort_order']][] = ['primary' => $row, 'previous' => $prev];
    }

    $writeRow = function($sheet, $rowNum, $label, $ml1, $j1, $t1, $ml2, $j2, $t2, $diff, $pct, $style = null, $isMatNegative = false) use ($ebitdaStyle, $ebitStyle, $ebtStyle, $netStyle, $matRightAlign) {
        $sheet->setCellValue('C' . $rowNum, $label);
        $sheet->setCellValue('E' . $rowNum, $ml1);
        $sheet->setCellValue('F' . $rowNum, $j1);
        $sheet->setCellValue('G' . $rowNum, $t1);
        $sheet->setCellValue('I' . $rowNum, $ml2);
        $sheet->setCellValue('J' . $rowNum, $j2);
        $sheet->setCellValue('K' . $rowNum, $t2);
        $sheet->setCellValue('M' . $rowNum, $diff);
        
        if ($pct == 'mat') {
            $sheet->setCellValue('N' . $rowNum, 'mat');
            // Apply right alignment for MAT text
            $sheet->getStyle('N' . $rowNum)->applyFromArray($matRightAlign);
            // Set font color based on whether it's negative MAT
            if ($isMatNegative) {
                $sheet->getStyle('N' . $rowNum)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            } else {
                $sheet->getStyle('N' . $rowNum)->getFont()->getColor()->setARGB(Color::COLOR_BLACK);
            }
        } else {
            $sheet->setCellValue('N' . $rowNum, $pct);
            $sheet->getStyle('N' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
            // Apply right alignment for numbers
            $sheet->getStyle('N' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            // Apply red color for negative percentages
            if ($pct < 0) {
                $sheet->getStyle('N' . $rowNum)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            } else {
                $sheet->getStyle('N' . $rowNum)->getFont()->getColor()->setARGB(Color::COLOR_BLACK);
            }
        }
        
        if ($style) {
            $rangeStart = in_array($style, [$ebitdaStyle, $ebitStyle, $ebtStyle, $netStyle], true) ? 'E' : 'A';
            $sheet->getStyle($rangeStart . $rowNum . ':N' . $rowNum)->applyFromArray($style);
        }
        foreach (['E','F','G','I','J','K','M'] as $col) {
            $val = $sheet->getCell($col . $rowNum)->getValue();
            if (is_numeric($val) && $val < 0) $sheet->getStyle($col . $rowNum)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        }
    };

    $sheet->setCellValue('A' . $rowNum, 'REVENUES');
    $sheet->getStyle('A' . $rowNum . ':N' . $rowNum)->applyFromArray($sectionTotalStyle);
    $rowNum++;

    foreach ($all_rows_grouped as $sort_order => $rows) {
        $g_ml1 = 0; $g_j1 = 0; $g_t1 = 0; $g_ml2 = 0; $g_j2 = 0; $g_t2 = 0;
        foreach ($rows as $item) {
            $r1 = $item['primary']; $r2 = $item['previous'];
            $display_row = $r1 ?: $r2;
            $s = (int)$display_row['sort_order'];
            $o = (int)$display_row['sub_order'];
            $is_special = ($s === 15 && $o === 2);

            // Get original values
            $ml1_orig = $r1 ? $r1['mlfsi'] : 0;
            $j1_orig = $r1 ? $r1['jewelers'] : 0;
            $t1_orig = $ml1_orig + $j1_orig;
            $ml2_orig = $r2 ? $r2['mlfsi'] : 0;
            $j2_orig = $r2 ? $r2['jewelers'] : 0;
            $t2_orig = $ml2_orig + $j2_orig;

           if ($is_special) {
    // For special case: FLIP THE SIGNS FOR DISPLAY only
    // The original values are kept for group total calculations
    
    // For group totals: use original values (already handled by adding directly)
    // This keeps category totals correct
    $g_ml1 += $ml1_orig;
    $g_j1 += $j1_orig;
    $g_t1 += $t1_orig;
    $g_ml2 += $ml2_orig;
    $g_j2 += $j2_orig;
    $g_t2 += $t2_orig;
    
    // FOR DISPLAY ONLY: Flip the signs (negative becomes positive, positive becomes negative)
    $w_ml1 = -$ml1_orig;
    $w_j1 = -$j1_orig;
    $w_t1 = -$t1_orig;
    $w_ml2 = -$ml2_orig;
    $w_j2 = -$j2_orig;
    $w_t2 = -$t2_orig;
    
    // Calculate difference using flipped values
    $diff = $w_t1 - $w_t2;
    // Calculate percentage using flipped values to get correct percentage
    $pct = calculatePercentage($w_t1, $w_t2);
} else {
    // Normal case: add values directly
    $g_ml1 += $ml1_orig;
    $g_j1 += $j1_orig;
    $g_t1 += $t1_orig;
    $g_ml2 += $ml2_orig;
    $g_j2 += $j2_orig;
    $g_t2 += $t2_orig;
    
    $w_ml1 = $ml1_orig;
    $w_j1 = $j1_orig;
    $w_t1 = $t1_orig;
    $w_ml2 = $ml2_orig;
    $w_j2 = $j2_orig;
    $w_t2 = $t2_orig;
    
    $diff = $t1_orig - $t2_orig;
    // Calculate percentage with new logic
    $pct = calculatePercentage($t1_orig, $t2_orig);
}

            // Write detail rows (skip for certain sort orders)
            if (!in_array((int)$sort_order, [6, 8, 11])) {
                $isMat = false;
                $isMatNegative = false;
                $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
                if ($isMat) {
                    $writeRow($sheet, $rowNum, $display_row['gl_description_comparative'], $w_ml1, $w_j1, $w_t1, $w_ml2, $w_j2, $w_t2, $diff, 'mat', null, $isMatNegative);
                } else {
                    $writeRow($sheet, $rowNum, $display_row['gl_description_comparative'], $w_ml1, $w_j1, $w_t1, $w_ml2, $w_j2, $w_t2, $diff, $formattedPct);
                }
                if ((int)$sort_order >= 1 && (int)$sort_order <= 20) $sheet->getRowDimension($rowNum)->setOutlineLevel(1)->setVisible(false);
                $rowNum++;
            }
        }
        
        // Accumulate totals
        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
            $total_rev_mlfsi_primary += $g_ml1; $total_rev_jewelers_primary += $g_j1; $total_rev_total_primary += $g_t1;
            $total_rev_mlfsi_previous += $g_ml2; $total_rev_jewelers_previous += $g_j2; $total_rev_total_previous += $g_t2;
        }
        if ((int)$sort_order == 22 || (int)$sort_order == 23) {
            $total_sa_mlfsi_primary += $g_ml1; $total_sa_jewelers_primary += $g_j1; $total_sa_total_primary += $g_t1;
            $total_sa_mlfsi_previous += $g_ml2; $total_sa_jewelers_previous += $g_j2; $total_sa_total_previous += $g_t2;
        }

        // Write category total rows
        if (!in_array((int)$sort_order, [24, 25, 26])) {
            $g_diff = $g_t1 - $g_t2;
            // Calculate percentage with new logic
            $g_pct = calculatePercentage($g_t1, $g_t2);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedGPct = formatPercentage($g_pct, $isMat, $isMatNegative);
            
            $appliedStyle = $categoryTotalStyle;
            $intSort = (int)$sort_order;
            if ($intSort >= 1 && $intSort <= 20 && ($intSort % 2 !== 0)) {
                $appliedStyle = null;
            }
            $sheet->setCellValue('A' . $rowNum, '');  // $sort_order
            $sheet->setCellValue('B' . $rowNum, $rows[0]['primary'] ? $rows[0]['primary']['description'] : $rows[0]['previous']['description']);
            
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $g_ml1, $g_j1, $g_t1, $g_ml2, $g_j2, $g_t2, $g_diff, 'mat', $appliedStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $g_ml1, $g_j1, $g_t1, $g_ml2, $g_j2, $g_t2, $g_diff, $formattedGPct, $appliedStyle);
            }
            
            if ((int)$sort_order == 21) {
                $sheet->getStyle('E' . $rowNum . ':N' . $rowNum)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
            }
            if ((int)$sort_order >= 1 && (int)$sort_order <= 20) $sheet->getRowDimension($rowNum)->setCollapsed(true);
            $rowNum++;
        }
        if ((int)$sort_order >= 1 && (int)$sort_order <= 20) $sheet->getRowDimension($rowNum)->setOutlineLevel(1)->setVisible(false);
        $rowNum++;

        // Special section totals
        if ((int)$sort_order == 20) {
            $diff = $total_rev_total_primary - $total_rev_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($total_rev_total_primary, $total_rev_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, 'TOTAL REVENUES');
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $total_rev_mlfsi_primary, $total_rev_jewelers_primary, $total_rev_total_primary, $total_rev_mlfsi_previous, $total_rev_jewelers_previous, $total_rev_total_previous, $diff, 'mat', $sectionTotalStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $total_rev_mlfsi_primary, $total_rev_jewelers_primary, $total_rev_total_primary, $total_rev_mlfsi_previous, $total_rev_jewelers_previous, $total_rev_total_previous, $diff, $formattedPct, $sectionTotalStyle);
            }
            $sheet->getRowDimension($rowNum)->setCollapsed(true);
            $rowNum += 2; 
            $sheet->setCellValue('A' . $rowNum, 'Cost of Sales/Service'); 
            $sheet->getStyle('A' . $rowNum . ':N' . $rowNum)->applyFromArray($sectionTotalStyle); 
            $rowNum++;
        } elseif ((int)$sort_order == 21) {
            $gp_mlfsi_primary = $total_rev_mlfsi_primary - $g_ml1; 
            $gp_jewelers_primary = $total_rev_jewelers_primary - $g_j1; 
            $gp_total_primary = $total_rev_total_primary - $g_t1;
            $gp_mlfsi_previous = $total_rev_mlfsi_previous - $g_ml2; 
            $gp_jewelers_previous = $total_rev_jewelers_previous - $g_j2; 
            $gp_total_previous = $total_rev_total_previous - $g_t2;
            $diff = $gp_total_primary - $gp_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($gp_total_primary, $gp_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, 'GROSS PROFIT');
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $gp_mlfsi_primary, $gp_jewelers_primary, $gp_total_primary, $gp_mlfsi_previous, $gp_jewelers_previous, $gp_total_previous, $diff, 'mat', $sectionTotalStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $gp_mlfsi_primary, $gp_jewelers_primary, $gp_total_primary, $gp_mlfsi_previous, $gp_jewelers_previous, $gp_total_previous, $diff, $formattedPct, $sectionTotalStyle);
            }
            $rowNum += 2; 
            $sheet->setCellValue('A' . $rowNum, 'SELLING & ADMIN EXPENSE'); 
            $sheet->getStyle('A' . $rowNum . ':N' . $rowNum)->applyFromArray($sectionTotalStyle); 
            $rowNum++;
        } elseif ((int)$sort_order == 23) {
            $diff = $total_sa_total_primary - $total_sa_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($total_sa_total_primary, $total_sa_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, 'TOTAL SELLING AND ADMIN EXPENSES');
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $total_sa_mlfsi_primary, $total_sa_jewelers_primary, $total_sa_total_primary, $total_sa_mlfsi_previous, $total_sa_jewelers_previous, $total_sa_total_previous, $diff, 'mat', $sectionTotalStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $total_sa_mlfsi_primary, $total_sa_jewelers_primary, $total_sa_total_primary, $total_sa_mlfsi_previous, $total_sa_jewelers_previous, $total_sa_total_previous, $diff, $formattedPct, $sectionTotalStyle);
            }
            $rowNum += 2;
            $ebitda_mlfsi_primary = $gp_mlfsi_primary - $total_sa_mlfsi_primary; 
            $ebitda_jewelers_primary = $gp_jewelers_primary - $total_sa_jewelers_primary; 
            $ebitda_total_primary = $gp_total_primary - $total_sa_total_primary;
            $ebitda_mlfsi_previous = $gp_mlfsi_previous - $total_sa_mlfsi_previous; 
            $ebitda_jewelers_previous = $gp_jewelers_previous - $total_sa_jewelers_previous; 
            $ebitda_total_previous = $gp_total_previous - $total_sa_total_previous;
            $diff = $ebitda_total_primary - $ebitda_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($ebitda_total_primary, $ebitda_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT");
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $ebitda_mlfsi_primary, $ebitda_jewelers_primary, $ebitda_total_primary, $ebitda_mlfsi_previous, $ebitda_jewelers_previous, $ebitda_total_previous, $diff, 'mat', $ebitdaStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $ebitda_mlfsi_primary, $ebitda_jewelers_primary, $ebitda_total_primary, $ebitda_mlfsi_previous, $ebitda_jewelers_previous, $ebitda_total_previous, $diff, $formattedPct, $ebitdaStyle);
            }
            $rowNum++;
        } elseif ((int)$sort_order == 24) {
            $ebit_mlfsi_primary = $ebitda_mlfsi_primary - $g_ml1; 
            $ebit_jewelers_primary = $ebitda_jewelers_primary - $g_j1; 
            $ebit_total_primary = $ebitda_total_primary - $g_t1;
            $ebit_mlfsi_previous = $ebitda_mlfsi_previous - $g_ml2; 
            $ebit_jewelers_previous = $ebitda_jewelers_previous - $g_j2; 
            $ebit_total_previous = $ebitda_total_previous - $g_t2;
            $diff = $ebit_total_primary - $ebit_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($ebit_total_primary, $ebit_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, 'EARNINGS BEFORE INTEREST & TAXES');
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $ebit_mlfsi_primary, $ebit_jewelers_primary, $ebit_total_primary, $ebit_mlfsi_previous, $ebit_jewelers_previous, $ebit_total_previous, $diff, 'mat', $ebitStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $ebit_mlfsi_primary, $ebit_jewelers_primary, $ebit_total_primary, $ebit_mlfsi_previous, $ebit_jewelers_previous, $ebit_total_previous, $diff, $formattedPct, $ebitStyle);
            }
            $rowNum++;
        } elseif ((int)$sort_order == 25) {
            $ebt_mlfsi_primary = $ebit_mlfsi_primary - $g_ml1; 
            $ebt_jewelers_primary = $ebit_jewelers_primary - $g_j1; 
            $ebt_total_primary = $ebit_total_primary - $g_t1;
            $ebt_mlfsi_previous = $ebit_mlfsi_previous - $g_ml2; 
            $ebt_jewelers_previous = $ebit_jewelers_previous - $g_j2; 
            $ebt_total_previous = $ebit_total_previous - $g_t2;
            $diff = $ebt_total_primary - $ebt_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($ebt_total_primary, $ebt_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, 'EARNINGS BEFORE TAXES');
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $ebt_mlfsi_primary, $ebt_jewelers_primary, $ebt_total_primary, $ebt_mlfsi_previous, $ebt_jewelers_previous, $ebt_total_previous, $diff, 'mat', $ebtStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $ebt_mlfsi_primary, $ebt_jewelers_primary, $ebt_total_primary, $ebt_mlfsi_previous, $ebt_jewelers_previous, $ebt_total_previous, $diff, $formattedPct, $ebtStyle);
            }
            $rowNum++;
        } elseif ((int)$sort_order == 26) {
            $net_mlfsi_primary = $ebt_mlfsi_primary - $g_ml1; 
            $net_jewelers_primary = $ebt_jewelers_primary - $g_j1; 
            $net_total_primary = $ebt_total_primary - $g_t1;
            $net_mlfsi_previous = $ebt_mlfsi_previous - $g_ml2; 
            $net_jewelers_previous = $ebt_jewelers_previous - $g_j2; 
            $net_total_previous = $ebt_total_previous - $g_t2;
            $diff = $net_total_primary - $net_total_previous;
            // Calculate percentage with new logic
            $pct = calculatePercentage($net_total_primary, $net_total_previous);
            
            $isMat = false;
            $isMatNegative = false;
            $formattedPct = formatPercentage($pct, $isMat, $isMatNegative);
            
            $sheet->setCellValue('A' . $rowNum, 'TOTAL NET INCOME/LOSS');
            if ($isMat) {
                $writeRow($sheet, $rowNum, '', $net_mlfsi_primary, $net_jewelers_primary, $net_total_primary, $net_mlfsi_previous, $net_jewelers_previous, $net_total_previous, $diff, 'mat', $netStyle, $isMatNegative);
            } else {
                $writeRow($sheet, $rowNum, '', $net_mlfsi_primary, $net_jewelers_primary, $net_total_primary, $net_mlfsi_previous, $net_jewelers_previous, $net_total_previous, $diff, $formattedPct, $netStyle);
            }
            $rowNum++;
        }
    }

    $sheet->getColumnDimension('A')->setWidth(2); 
    $sheet->getColumnDimension('B')->setWidth(25); 
    $sheet->getColumnDimension('C')->setWidth(40);
    $sheet->getColumnDimension('D')->setWidth(2); 
    $sheet->getColumnDimension('H')->setWidth(2); 
    $sheet->getColumnDimension('L')->setWidth(2); 

    $excludedCols = ['H', 'L'];

    foreach (range('E', 'N') as $colLetter) {
        if (!in_array($colLetter, $excludedCols)) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    $sheet->getStyle('E4:N' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
}

if ($spreadsheet->getSheetCount() == 0) die("No data found.");
$spreadsheet->setActiveSheetIndex(0);
$filename = 'Comparative_Report_Adjustment_' . date('Y-m-d_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
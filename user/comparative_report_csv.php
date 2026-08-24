<?php
session_start();

$uploadMessage = '';
if (isset($_SESSION['upload_message'])) {
    $uploadMessage = $_SESSION['upload_message'];
    unset($_SESSION['upload_message']);
}

require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['id_number'] = '00000000';
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? "unknown";
$id_number = $_SESSION['id_number'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

$previewTable = '';
$tempFilePaths = $_POST['temp_file_paths'] ?? [];

// Get the selected month from POST - NO DEFAULT VALUE
$selectedMonth = $_POST['transaction_month'] ?? '';

function stripCsvBom(mixed $value): mixed
{
    if (is_string($value)) {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value);
    }

    return $value;
}

function detectDelimiter(string $line): string
{
    $delimiters = [',', '|', "\t", ';'];
    $counts = [];
    
    foreach ($delimiters as $delimiter) {
        $counts[$delimiter] = substr_count($line, $delimiter);
    }
    
    arsort($counts);
    return key($counts);
}

function readCsvRows(string $path): array
{
    $rows = [];
    $handle = fopen($path, 'r');

    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file.');
    }

    $firstLine = fgets($handle);
    rewind($handle);
    
    if ($firstLine === false) {
        fclose($handle);
        return [];
    }
    
    $delimiter = detectDelimiter($firstLine);
    
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        foreach ($row as &$cell) {
            $cell = trim($cell);
            $cell = trim($cell, '"');
            $cell = stripCsvBom($cell);
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function maxCsvColumns(array $rows): int
{
    $maxCols = 0;
    foreach ($rows as $row) {
        $maxCols = max($maxCols, count($row));
    }
    return $maxCols;
}

// Helper function to clean CSV values - COMPLETELY REWORKED
function cleanCsvValue(mixed $value, array $prefixes = []): mixed {
    if (!is_string($value)) {
        return $value;
    }

    $value = trim($value);

    // Remove BOM
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

    // Remove quotes
    $value = str_replace(['"', "'"], '', $value);

    // If there's a colon, the real value is everything AFTER the first colon.
    // This handles "Label : Value", "Label:Value", "Label :Value", etc.
    if (strpos($value, ':') !== false) {
        [, $value] = explode(':', $value, 2);
    } else {
        // No colon present — fall back to stripping the label by prefix
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if ($prefix === '') continue;
            $value = preg_replace(
                '/^' . preg_quote($prefix, '/') . '\s*[-|]?\s*/i',
                '',
                $value
            );
        }
    }

    return trim($value);
}

// Enhanced function to extract numeric amount from various formats
function extractNumericAmount(mixed $value): float {
    if (!is_string($value) && !is_numeric($value)) {
        return 0;
    }
    
    $value = trim((string)$value);
    
    // Handle negative numbers in parentheses
    if (strpos($value, '(') !== false && strpos($value, ')') !== false) {
        $value = str_replace(['(', ')', ' ', '₱', '$', ','], '', $value);
        return -(float)$value;
    }
    
    // Remove currency symbols, commas, spaces
    $value = str_replace([' ', '₱', '$', ','], '', $value);
    
    // Handle negative sign
    $isNegative = false;
    if (strpos($value, '-') === 0) {
        $isNegative = true;
        $value = substr($value, 1);
    }
    
    $amount = (float)$value;
    return $isNegative ? -$amount : $amount;
}

// --- STAGE 1: UPLOAD & PREVIEW ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Get the combined month input
    $transactionMonthYear = $_POST['transaction_month'] ?? '';
    $transactionMonth = '';
    $transactionYear = '';
    
    // Parse the combined month input (format: YYYY-MM)
    if (!empty($transactionMonthYear)) {
        $parts = explode('-', $transactionMonthYear);
        if (count($parts) === 2) {
            $transactionYear = $parts[0];
            $transactionMonth = $parts[1];
        }
    }
    
    $file = $_FILES['csv_file'];

    if (empty($transactionMonthYear)) {
        $uploadMessage = '<div class="error">⚠️ Please select a Transaction Month.</div>';
    } else {
        $fileList = [];
        if (is_array($file['name'])) {
            foreach ($file['name'] as $i => $name) {
                $fileList[] = [
                    'name' => $file['name'][$i],
                    'tmp_name' => $file['tmp_name'][$i],
                    'error' => $file['error'][$i]
                ];
            }
        } else {
            $fileList[] = $file;
        }

        foreach ($fileList as $singleFile) {
            if ($singleFile['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $ext = strtolower(pathinfo($singleFile['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                continue;
            }

            $tempDir = 'uploads/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $currentTempPath = $tempDir . uniqid() . '_' . basename($singleFile['name']);
            move_uploaded_file($singleFile['tmp_name'], $currentTempPath);
            $tempFilePaths[] = $currentTempPath;

            try {
                $rows = readCsvRows($currentTempPath);
                if (empty($rows)) {
                    continue;
                }

                // Extract metadata from rows 0, 1, 2 with improved cleaning
                $regionID = cleanCsvValue($rows[0][0] ?? '', ['Region ID']);
                $regionID = preg_replace('/[^0-9]/', '', $regionID);
                
                $regionDescription = cleanCsvValue($rows[1][0] ?? '', ['Region Description']);
                
                $area = cleanCsvValue($rows[2][0] ?? '', ['Area']);

                // Find where data actually starts
                $glCodeCol = null;
                $descriptionCol = null;
                $dataStartRow = null;
                $branchAmountCol = null;
                $showroomAmountCol = null;
                $percentageCol = null;
                $headerRowIndex = null;

                // First, find the header row with "GLCode" and "Description"
                for ($i = 0; $i < min(20, count($rows)); $i++) {
                    $row = $rows[$i] ?? [];
                    $hasGLCode = false;
                    $hasDescription = false;
                    
                    foreach ($row as $colIndex => $cell) {
                        $cellClean = trim((string)$cell);
                        if (stripos($cellClean, 'GLCode') !== false || stripos($cellClean, 'GL Code') !== false) {
                            $glCodeCol = $colIndex;
                            $hasGLCode = true;
                        }
                        if (stripos($cellClean, 'Description') !== false) {
                            $descriptionCol = $colIndex;
                            $hasDescription = true;
                        }
                        if (stripos($cellClean, 'Branch Amount') !== false || stripos($cellClean, 'Branch') !== false) {
                            $branchAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, 'Showroom Amount') !== false || stripos($cellClean, 'Showroom') !== false) {
                            $showroomAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, '%') !== false) {
                            $percentageCol = $colIndex;
                        }
                    }
                    
                    if ($hasGLCode && $hasDescription) {
                        $headerRowIndex = $i;
                        $dataStartRow = $i + 1;
                        break;
                    }
                }

                // If we still couldn't find the header, use default
                if ($headerRowIndex === null) {
                    for ($i = 0; $i < min(15, count($rows)); $i++) {
                        $row = $rows[$i] ?? [];
                        foreach ($row as $colIndex => $cell) {
                            $cellClean = trim((string)$cell);
                            if (stripos($cellClean, 'Category') !== false) {
                                if (isset($rows[$i + 1])) {
                                    $nextRow = $rows[$i + 1];
                                    foreach ($nextRow as $colIdx => $cellVal) {
                                        $cellValClean = trim((string)$cellVal);
                                        if (stripos($cellValClean, 'GLCode') !== false || stripos($cellValClean, 'GL Code') !== false) {
                                            $glCodeCol = $colIdx;
                                        }
                                        if (stripos($cellValClean, 'Description') !== false) {
                                            $descriptionCol = $colIdx;
                                        }
                                    }
                                    if ($glCodeCol !== null && $descriptionCol !== null) {
                                        $headerRowIndex = $i + 1;
                                        $dataStartRow = $i + 2;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($headerRowIndex !== null) {
                            break;
                        }
                    }
                }

                // If still not found, use defaults
                if ($headerRowIndex === null) {
                    $headerRowIndex = 4;
                    $dataStartRow = 5;
                }
                if ($glCodeCol === null) {
                    $glCodeCol = 1;
                }
                if ($descriptionCol === null) {
                    $descriptionCol = 2;
                }

                // Find NET Income row - we'll use it for display but NOT as cutoff
                $netIncomeRowIndex = null;
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $cell) {
                        if (stripos(trim((string)$cell), 'NET Income') !== false) {
                            $netIncomeRowIndex = $rowIndex;
                            break 2;
                        }
                    }
                }

                // Build preview - include ALL rows including NET Income
                $previewTable .= '<div class="file-preview-container">';
                $previewTable .= '<h4 class="file-name">📄 Preview: ' . htmlspecialchars($singleFile['name']) . '</h4>';
                
                // Metadata section - show cleaned values
                $previewTable .= '<div class="metadata-section" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #4a90d9;">';
                $previewTable .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">';
                $previewTable .= '<div><strong><i class="fa-solid fa-tag"></i> Region ID:</strong> <span style="color: #2d3748;">' . htmlspecialchars($regionID) . '</span></div>';
                $previewTable .= '<div><strong><i class="fa-solid fa-location-pin"></i> Region Description:</strong> <span style="color: #2d3748;">' . htmlspecialchars($regionDescription) . '</span></div>';
                $previewTable .= '<div><strong><i class="fa-solid fa-location-arrow"></i> Area:</strong> <span style="color: #2d3748;">' . htmlspecialchars($area) . '</span></div>';
                if ($netIncomeRowIndex !== null) {
                    $previewTable .= '<div><strong><i class="fa-solid fa-money-bill"></i> NET Income found at row:</strong> <span style="color: #2d3748; font-weight: bold;">' . ($netIncomeRowIndex + 1) . '</span></div>';
                }
                $previewTable .= '</div>';
                $previewTable .= '</div>';

                // DATA TABLE SECTION
                $previewTable .= '<div class="table-container" style="max-height: 600px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">';
                $previewTable .= '<table class="excel-preview" style="width: 100%; border-collapse: collapse;">';

                // Show the header row first
                if (isset($rows[$headerRowIndex])) {
                    $headerRow = $rows[$headerRowIndex];
                    $previewTable .= '<thead><tr class="sticky-row-header" style="background: #ff0000;">';
                    foreach ($headerRow as $colKey => $cell) {
                        $previewTable .= '<th style="padding: 10px 12px; border: 1px solid #e2e8f0; font-weight: 600; text-align: left; white-space: nowrap; position: sticky; top: 0; background: #ff0000; z-index: 10;">' . htmlspecialchars($cell ?? '') . '</th>';
                    }
                    $previewTable .= '</tr></thead>';
                }

                // Show data rows - include ALL rows from dataStartRow to the end
                $previewTable .= '<tbody>';
                $rowCount = 0;
                $dataRowCount = 0;
                $netIncomeRowDisplayed = false;
                
                for ($rowIndex = $dataStartRow; $rowIndex < count($rows); $rowIndex++) {
                    $row = $rows[$rowIndex];
                    
                    // Check if this is the NET Income row
                    $isNetIncome = false;
                    foreach ($row as $cell) {
                        if (stripos(trim((string)$cell), 'NET Income') !== false) {
                            $isNetIncome = true;
                            break;
                        }
                    }
                    
                    $isEmpty = true;
                    foreach ($row as $cell) {
                        if (!empty(trim($cell))) {
                            $isEmpty = false;
                            break;
                        }
                    }
                    if ($isEmpty) {
                        continue;
                    }

                    $rowCount++;
                    if (!$isNetIncome) {
                        $dataRowCount++;
                    }
                    
                    // Apply special styling for NET Income row
                    $rowStyle = '';
                    if ($isNetIncome) {
                        $rowStyle = ' style="background: #fff5f5; font-weight: bold; border-top: 3px solid #e53e3e;"';
                        $netIncomeRowDisplayed = true;
                    }
                    
                    $previewTable .= '<tr' . $rowStyle . '>';
                    
                    foreach ($row as $colKey => $cell) {
                        $style = 'padding: 8px 12px; border: 1px solid #e2e8f0;';
                        $cellValue = trim($cell ?? '');
                        if (is_numeric(str_replace(',', '', $cellValue))) {
                            $style .= ' text-align: right;';
                        }
                        if ($colKey == $glCodeCol && !empty($cellValue) && is_numeric($cellValue)) {
                            $style .= ' font-weight: 500; color: #2b6cb0;';
                        }
                        if ($isNetIncome) {
                            $style .= ' background: #fff5f5;';
                            if (is_numeric(str_replace(',', '', $cellValue))) {
                                $style .= ' color: #e53e3e; font-weight: bold;';
                            }
                        }
                        $previewTable .= "<td style='$style'>" . htmlspecialchars($cell ?? '') . "</td>";
                    }
                    
                    if (isset($rows[$headerRowIndex])) {
                        $headerCount = count($rows[$headerRowIndex]);
                        $currentCount = count($row);
                        if ($currentCount < $headerCount) {
                            for ($i = $currentCount; $i < $headerCount; $i++) {
                                $previewTable .= "<td style='padding: 8px 12px; border: 1px solid #e2e8f0;" . ($isNetIncome ? " background: #fff5f5;" : "") . "'></td>";
                            }
                        }
                    }
                    
                    $previewTable .= '</tr>';
                }
                $previewTable .= '</tbody>';
                $previewTable .= '</table>';
                $previewTable .= '</div>';
                
                // Summary section
                $totalRows = count($rows);
                $previewTable .= '<div class="summary-bar" style="padding: 10px; background: #f8f9fa; border-radius: 4px; margin-top: 10px; font-size: 14px; color: #4a5568;">';
                $previewTable .= '<small>📊 <strong>Summary:</strong> Total rows in file: ' . $totalRows . ' | Rows displayed: ' . $rowCount . ' | Data rows: ' . $dataRowCount . ' | Header at row: ' . ($headerRowIndex + 1);
                if ($netIncomeRowIndex !== null) {
                    $previewTable .= ' | <span style="color: #e53e3e; font-weight: bold;">NET Income at row: ' . ($netIncomeRowIndex + 1) . '</span>';
                }
                $previewTable .= '</small>';
                $previewTable .= '</div>';
                $previewTable .= '</div>';

            } catch (Exception $e) {
                $uploadMessage = '<div class="error">Error: ' . $e->getMessage() . '</div>';
            }
        }
    }
}

// --- STAGE 2: ACTUAL INSERTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_insert'])) {
    // Get the combined month input
    $transactionMonthYear = $_POST['transaction_month'] ?? '';
    $transactionMonth = '';
    $transactionYear = '';
    
    // Parse the combined month input (format: YYYY-MM)
    if (!empty($transactionMonthYear)) {
        $parts = explode('-', $transactionMonthYear);
        if (count($parts) === 2) {
            $transactionYear = $parts[0];
            $transactionMonth = $parts[1];
        }
    }
    
    $paths = $_POST['temp_file_paths'] ?? [];
    $uploadedBy = $_SESSION['username'];
    $forceInsert = isset($_POST['force_insert']);
    date_default_timezone_set('Asia/Manila');
    $uploadedDate = date('Y-m-d H:i:s');

    $dbTransactionMonth = null;
    $dbTransactionYear = null;

    if (!empty($transactionYear)) {
        $dbTransactionYear = $transactionYear;

        if (!empty($transactionMonth)) {
            $dbTransactionMonth = $transactionYear . '-' . str_pad($transactionMonth, 2, '0', STR_PAD_LEFT) . '-01';
        }
    }

    if (!empty($paths)) {
        $conn->begin_transaction();
        $blockedRegions = [];
        $existingRegions = [];
        $voidedGroups = [];
        $checkedGroups = [];
        $insertCount = 0;
        $debugInfo = [];
        $skippedRows = [];
        $processedRows = [];
        $blockedReasons = [];

        try {
            // Prepare the INSERT statement with correct column mapping
            $stmt = $conn->prepare("
                INSERT INTO comparative_report
                (gl_code, gl_description, amount, region, area, mainzone, zone, region_code,
                 transaction_type, transaction_month, transaction_year, uploaded_by, region_id,
                 uploaded_date, gl_region)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // MODIFIED: Check for status_void and reported_status
            $checkStatusStmt = $conn->prepare("
                SELECT status_void, reported_status, unlock_by 
                FROM comparative_report 
                WHERE region_id = ? AND area = ? AND transaction_type = ? 
                AND transaction_month <=> ? AND status_void IS NULL 
                LIMIT 1
            ");
            
            // MODIFIED: Only set void status, no need for 'Locked' status
            $voidStmt = $conn->prepare("
                UPDATE comparative_report 
                SET status_void = 'Void', 
                    voided_by = ?, 
                    voided_at = ? 
                WHERE region_id = ? AND area = ? AND transaction_type = ? 
                AND transaction_month <=> ? AND status_void IS NULL
            ");

            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    $debugInfo[] = "✗ File not found: $path";
                    continue;
                }

                $rows = readCsvRows($path);
                if (empty($rows)) {
                    $debugInfo[] = "✗ Empty file: $path";
                    continue;
                }

                // Extract and CLEAN metadata from rows 0, 1, 2 with improved cleaning
                $regionID = cleanCsvValue($rows[0][0] ?? '', ['Region ID']);
                $regionID = preg_replace('/[^0-9]/', '', $regionID);
                
                // CLEAN glRegion - remove all variations of "Region Description" prefix
                $glRegion = cleanCsvValue($rows[1][0] ?? '', ['Region Description']);
                
                // CLEAN area - remove all variations of "Area" prefix
                $area = cleanCsvValue($rows[2][0] ?? '', ['Area']);

                // DEBUG: Log the extracted values
                $debugInfo[] = "=== Processing File: " . basename($path) . " ===";
                $debugInfo[] = "Region ID extracted: '$regionID'";
                $debugInfo[] = "Area extracted: '$area'";
                $debugInfo[] = "Region Description extracted: '$glRegion'";

                // Lookup region details from masterdata.branch_profile
                $region = null;
                $region_code = null;
                $mainzone = null;
                $zone = null;

                // Try multiple lookup strategies
                $lookupSuccess = false;

                // Strategy 1: Try exact match on regionID_MLmatic and area
                if (!empty($regionID) && !empty($area)) {
                    $debugInfo[] = "Strategy 1: Looking up regionID_MLmatic='$regionID' AND area='$area'";
                    
                    $lookup_sql = "SELECT region, region_code, mainzone, zone
                                   FROM masterdata.branch_profile 
                                   WHERE regionID_MLmatic = ? AND area = ?
                                   LIMIT 1";

                    $lookup_stmt = $conn->prepare($lookup_sql);

                    if ($lookup_stmt) {
                        $lookup_stmt->bind_param("ss", $regionID, $area);
                        $lookup_stmt->execute();

                        $lookup_region = null;
                        $lookup_region_code = null;
                        $lookup_mainzone = null;
                        $lookup_zone = null;

                        $lookup_stmt->bind_result(
                            $lookup_region,
                            $lookup_region_code,
                            $lookup_mainzone,
                            $lookup_zone
                        );

                        if ($lookup_stmt->fetch()) {
                            $region = $lookup_region;
                            $region_code = $lookup_region_code;
                            $mainzone = $lookup_mainzone;
                            $zone = $lookup_zone;
                            $lookupSuccess = true;
                            $debugInfo[] = "✓ Strategy 1 SUCCESS - Found record: region='$region', region_code='$region_code'";
                        } else {
                            $debugInfo[] = "✗ Strategy 1 FAILED - No matching record found";
                        }

                        $lookup_stmt->close();
                    }
                }

                // Strategy 2: If area lookup failed, try using only regionID_MLmatic
                if (!$lookupSuccess && !empty($regionID)) {
                    $debugInfo[] = "Strategy 2: Looking up regionID_MLmatic='$regionID' (without area filter)";
                    
                    $lookup_sql2 = "SELECT region, region_code, mainzone, zone
                                   FROM masterdata.branch_profile 
                                   WHERE regionID_MLmatic = ?
                                   LIMIT 1";

                    $lookup_stmt2 = $conn->prepare($lookup_sql2);

                    if ($lookup_stmt2) {
                        $lookup_stmt2->bind_param("s", $regionID);
                        $lookup_stmt2->execute();

                        $lookup_region = null;
                        $lookup_region_code = null;
                        $lookup_mainzone = null;
                        $lookup_zone = null;

                        $lookup_stmt2->bind_result(
                            $lookup_region,
                            $lookup_region_code,
                            $lookup_mainzone,
                            $lookup_zone
                        );

                        if ($lookup_stmt2->fetch()) {
                            $region = $lookup_region;
                            $region_code = $lookup_region_code;
                            $mainzone = $lookup_mainzone;
                            $zone = $lookup_zone;
                            $lookupSuccess = true;
                            $debugInfo[] = "✓ Strategy 2 SUCCESS - Found record: region='$region', region_code='$region_code'";
                        } else {
                            $debugInfo[] = "✗ Strategy 2 FAILED - No matching record found";
                        }

                        $lookup_stmt2->close();
                    }
                }

                // Strategy 3: Try using region description from region_masterfile
                if (!$lookupSuccess && !empty($glRegion)) {
                    $debugInfo[] = "Strategy 3: Looking up region_description='$glRegion' in region_masterfile";
                    
                    $rc_sql = "SELECT region_code FROM masterdata.region_masterfile WHERE region_description = ? LIMIT 1";
                    $rc_stmt = $conn->prepare($rc_sql);
                    if ($rc_stmt) {
                        $found_region_code = null;
                        $rc_stmt->bind_param("s", $glRegion);
                        $rc_stmt->execute();
                        $rc_stmt->bind_result($found_region_code);
                        if ($rc_stmt->fetch()) {
                            $region_code = $found_region_code;
                            if (empty($region)) {
                                $region = $glRegion;
                            }
                            $lookupSuccess = true;
                            $debugInfo[] = "✓ Strategy 3 SUCCESS - Found region_code='$region_code'";
                        } else {
                            $debugInfo[] = "✗ Strategy 3 FAILED - No matching region description found";
                        }
                        $rc_stmt->close();
                    }
                }

                // Strategy 4: If all lookups fail, use default values
                if (!$lookupSuccess) {
                    $debugInfo[] = "Strategy 4: Using fallback/default values";
                    if (empty($region)) {
                        $region = $glRegion;
                    }
                    if (empty($region_code)) {
                        $region_code = $regionID;
                    }
                    if (empty($mainzone)) {
                        $mainzone = '';
                    }
                    if (empty($zone)) {
                        $zone = '';
                    }
                    $debugInfo[] = "✓ Strategy 4 (FALLBACK) - Using: region='$region', region_code='$region_code'";
                }

                $debugInfo[] = "Final values - region: '$region', region_code: '$region_code', mainzone: '$mainzone', zone: '$zone'";
                $debugInfo[] = "Values to insert - area: '$area', gl_region: '$glRegion'";

                // Skip if required fields are missing
                if (empty($region) || empty($area) || empty($region_code)) {
                    $debugInfo[] = "✗ SKIPPING FILE - Required fields missing (region, area, or region_code is empty)";
                    $debugInfo[] = "  - region: '" . ($region ?? 'NULL') . "'";
                    $debugInfo[] = "  - area: '" . ($area ?? 'NULL') . "'";
                    $debugInfo[] = "  - region_code: '" . ($region_code ?? 'NULL') . "'";
                    continue;
                }

                // Find where the data starts - MORE ROBUST DETECTION
                $glCodeCol = null;
                $descriptionCol = null;
                $branchAmountCol = null;
                $showroomAmountCol = null;
                $percentageCol = null;
                $dataStartRow = null;
                $headerRowFound = false;

                // Try to find header row with GL Code and Description
                for ($i = 0; $i < min(20, count($rows)); $i++) {
                    $row = $rows[$i] ?? [];
                    $foundGL = false;
                    $foundDesc = false;
                    
                    foreach ($row as $colIndex => $cell) {
                        $cellClean = trim((string)$cell);
                        // Look for GL Code variations
                        if (stripos($cellClean, 'GLCode') !== false || 
                            stripos($cellClean, 'GL Code') !== false ||
                            stripos($cellClean, 'G/L Code') !== false ||
                            stripos($cellClean, 'Account Code') !== false ||
                            stripos($cellClean, 'Code') !== false) {
                            $glCodeCol = $colIndex;
                            $foundGL = true;
                        }
                        // Look for Description variations
                        if (stripos($cellClean, 'Description') !== false || 
                            stripos($cellClean, 'Account Description') !== false ||
                            stripos($cellClean, 'Particulars') !== false) {
                            $descriptionCol = $colIndex;
                            $foundDesc = true;
                        }
                        // Look for amount columns
                        if (stripos($cellClean, 'Branch Amount') !== false || 
                            stripos($cellClean, 'Branch') !== false) {
                            $branchAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, 'Showroom Amount') !== false || 
                            stripos($cellClean, 'Showroom') !== false) {
                            $showroomAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, '%') !== false) {
                            $percentageCol = $colIndex;
                        }
                    }
                    
                    if ($foundGL && $foundDesc) {
                        $headerRowFound = true;
                        $dataStartRow = $i + 1;
                        $debugInfo[] = "✓ Header found at row $i - GL Code col: $glCodeCol, Description col: $descriptionCol";
                        break;
                    }
                }

                // If still not found, try to find by looking for numeric values in a column
                if (!$headerRowFound) {
                    $debugInfo[] = "Header not found with GL/Description, trying to detect data patterns...";
                    
                    // Look for first row that has numbers in multiple columns
                    for ($i = 0; $i < min(30, count($rows)); $i++) {
                        $row = $rows[$i] ?? [];
                        $numericCount = 0;
                        $numericCols = [];
                        
                        foreach ($row as $colIndex => $cell) {
                            $cleaned = str_replace([',', ' ', '₱', '$', '(', ')'], '', trim($cell));
                            if (is_numeric($cleaned) && $cleaned != '') {
                                $numericCount++;
                                $numericCols[] = $colIndex;
                            }
                        }
                        
                        // If we have multiple numeric columns, this is likely a data row
                        if ($numericCount >= 2) {
                            // The previous row might be the header
                            if ($i > 0) {
                                $headerRowIndex = $i - 1;
                                $dataStartRow = $i;
                                
                                // Try to find GL Code and Description in the header
                                $headerRow = $rows[$headerRowIndex];
                                foreach ($headerRow as $colIndex => $cell) {
                                    $cellClean = trim((string)$cell);
                                    if (stripos($cellClean, 'Code') !== false && $glCodeCol === null) {
                                        $glCodeCol = $colIndex;
                                    }
                                    if ((stripos($cellClean, 'Description') !== false || stripos($cellClean, 'Particulars') !== false) && $descriptionCol === null) {
                                        $descriptionCol = $colIndex;
                                    }
                                }
                                
                                // If still not found, use the first numeric column as GL Code and second as Description
                                if ($glCodeCol === null && !empty($numericCols)) {
                                    $glCodeCol = $numericCols[0];
                                }
                                if ($descriptionCol === null && count($numericCols) > 1) {
                                    $descriptionCol = $numericCols[1];
                                }
                                
                                $headerRowFound = true;
                                $debugInfo[] = "✓ Data pattern detected - Header at row $headerRowIndex, data starts at row $dataStartRow";
                                $debugInfo[] = "GL Code col: $glCodeCol, Description col: $descriptionCol";
                                break;
                            }
                        }
                    }
                }

                // If still not found, use defaults
                if (!$headerRowFound || $dataStartRow === null) {
                    $debugInfo[] = "Using default column mappings";
                    $dataStartRow = 5;
                    $glCodeCol = 1;
                    $descriptionCol = 2;
                    $branchAmountCol = 3;
                    $showroomAmountCol = 4;
                }

                // If amount columns not found, try to auto-detect them
                if ($branchAmountCol === null && $showroomAmountCol === null) {
                    $debugInfo[] = "Looking for amount columns by checking data patterns...";
                    // Check the first few data rows to find columns with numeric values
                    $numericColumnCounts = [];
                    for ($i = $dataStartRow; $i < min($dataStartRow + 10, count($rows)); $i++) {
                        $row = $rows[$i] ?? [];
                        foreach ($row as $colIndex => $cell) {
                            $cleaned = str_replace([',', ' ', '₱', '$', '(', ')'], '', trim($cell));
                            if (is_numeric($cleaned) && $cleaned != '') {
                                if (!isset($numericColumnCounts[$colIndex])) {
                                    $numericColumnCounts[$colIndex] = 0;
                                }
                                $numericColumnCounts[$colIndex]++;
                            }
                        }
                    }
                    
                    // Get columns that have numeric values in most rows
                    arsort($numericColumnCounts);
                    $numericCols = array_keys($numericColumnCounts);
                    
                    // Exclude GL Code column if it's numeric
                    $numericCols = array_filter($numericCols, function($col) use ($glCodeCol) {
                        return $col != $glCodeCol;
                    });
                    
                    // Use the first two numeric columns as Branch and Showroom
                    $numericCols = array_values($numericCols);
                    if (count($numericCols) >= 1) {
                        $branchAmountCol = $numericCols[0];
                        $debugInfo[] = "✓ Auto-detected Branch Amount column: $branchAmountCol";
                    }
                    if (count($numericCols) >= 2) {
                        $showroomAmountCol = $numericCols[1];
                        $debugInfo[] = "✓ Auto-detected Showroom Amount column: $showroomAmountCol";
                    }
                }

                $debugInfo[] = "Final column detection: glCodeCol=$glCodeCol, descriptionCol=$descriptionCol, branchAmountCol=$branchAmountCol, showroomAmountCol=$showroomAmountCol, dataStartRow=$dataStartRow";

                // Find NET Income row - we'll use it for display but NOT as cutoff for insertion
                $netIncomeRowIndex = null;
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $cell) {
                        $cellClean = trim((string)$cell);
                        if (stripos($cellClean, 'NET Income') !== false || 
                            stripos($cellClean, 'Net Income') !== false ||
                            (stripos($cellClean, 'Net') !== false && stripos($cellClean, 'Income') !== false)) {
                            $netIncomeRowIndex = $rowIndex;
                            $debugInfo[] = "✓ Found NET Income at row " . ($rowIndex + 1) . " (will NOT be inserted)";
                            break 2;
                        }
                    }
                }
                
                if ($netIncomeRowIndex === null) {
                    $debugInfo[] = "⚠ NET Income not found in file";
                }

                $debugInfo[] = "Data detection: glCodeCol=$glCodeCol, descriptionCol=$descriptionCol, dataStartRow=$dataStartRow";

                // Determine which amount columns are available
                $transactionTypes = [];
                if ($branchAmountCol !== null) {
                    $transactionTypes[] = ['type' => 'Branch', 'col' => $branchAmountCol];
                }
                if ($showroomAmountCol !== null) {
                    $transactionTypes[] = ['type' => 'Showroom', 'col' => $showroomAmountCol];
                }

                if (empty($transactionTypes)) {
                    $debugInfo[] = "✗ SKIPPING - No transaction types found (Branch Amount or Showroom Amount columns not found)";
                    continue;
                }

                $debugInfo[] = "Transaction types: " . implode(', ', array_column($transactionTypes, 'type'));

                // MODIFIED: Check for existing records with enhanced status checking
                foreach ($transactionTypes as $tt) {
                    $groupKey = $regionID . '|' . $area . '|' . $tt['type'] . '|' . ($dbTransactionMonth ?? 'NULL');
                    
                    if (!in_array($groupKey, $checkedGroups)) {
                        $existingStatusVoid = null;
                        $existingReportedStatus = null;
                        $existingUnlockedBy = null;
                        
                        $checkStatusStmt->bind_param("ssss", $regionID, $area, $tt['type'], $dbTransactionMonth);
                        $checkStatusStmt->execute();
                        $checkStatusStmt->store_result();

                        if ($checkStatusStmt->num_rows > 0) {
                            $checkStatusStmt->bind_result($existingStatusVoid, $existingReportedStatus, $existingUnlockedBy);
                            $checkStatusStmt->fetch();

                            // MODIFIED: Block if status_void is 'Void' OR reported_status is 'Reported'
                            if ($existingStatusVoid === 'Void' || $existingReportedStatus === 'Reported') {
                                $blockedRegions[] = $regionID . '-' . $area . '-' . $tt['type'];
                                $blockedReasons[] = "Region $regionID-$area-{$tt['type']} is BLOCKED - Status Void: '$existingStatusVoid', Reported Status: '$existingReportedStatus'";
                                $debugInfo[] = "🚫 Region is BLOCKED - Status Void: '$existingStatusVoid', Reported Status: '$existingReportedStatus'";
                            } 
                            // MODIFIED: Allow if status_void is NULL/empty AND unlock_by is NULL/empty/Unlocked AND reported_status is NULL/empty
                            else if ((empty($existingStatusVoid) || $existingStatusVoid === '') && 
                                    (empty($existingReportedStatus) || $existingReportedStatus === '') && 
                                    (empty($existingUnlockedBy) || $existingUnlockedBy === 'Unlocked' || $existingUnlockedBy === '')) {
                                // This is the condition where we allow uploading
                                $existingRegions[] = $regionID . '-' . $area . '-' . $tt['type'];
                                $debugInfo[] = "✓ Records exist but can be replaced - Status Void: '$existingStatusVoid', Unlocked By: '$existingUnlockedBy', Reported Status: '$existingReportedStatus'";
                            } else {
                                // Any other state - block by default for safety
                                $blockedRegions[] = $regionID . '-' . $area . '-' . $tt['type'];
                                $blockedReasons[] = "Region $regionID-$area-{$tt['type']} is BLOCKED - Unknown state - Status Void: '$existingStatusVoid', Unlocked By: '$existingUnlockedBy', Reported Status: '$existingReportedStatus'";
                                $debugInfo[] = "🚫 Region is BLOCKED - Unknown state - Status Void: '$existingStatusVoid', Unlocked By: '$existingUnlockedBy', Reported Status: '$existingReportedStatus'";
                            }
                        } else {
                            $debugInfo[] = "No existing records found for " . $tt['type'];
                        }
                        $checkStatusStmt->free_result();
                        $checkedGroups[] = $groupKey;
                    }
                }

                // Check if any region is blocked
                $regionBlocked = false;
                foreach ($transactionTypes as $tt) {
                    if (in_array($regionID . '-' . $area . '-' . $tt['type'], $blockedRegions)) {
                        $regionBlocked = true;
                        break;
                    }
                }
                if ($regionBlocked) {
                    $debugInfo[] = "✗ SKIPPING - Region is blocked (Voided or Reported)";
                    continue;
                }

                // MODIFIED: Handle force insert - void existing records
                if ($forceInsert) {
                    foreach ($transactionTypes as $tt) {
                        $groupKey = $regionID . '|' . $area . '|' . $tt['type'] . '|' . ($dbTransactionMonth ?? 'NULL');
                        if (!in_array($groupKey, $voidedGroups)) {
                            // Check if records exist before voiding
                            $checkExists = $conn->prepare("SELECT COUNT(*) FROM comparative_report WHERE region_id = ? AND area = ? AND transaction_type = ? AND transaction_month <=> ? AND status_void IS NULL");
                            $checkExists->bind_param("ssss", $regionID, $area, $tt['type'], $dbTransactionMonth);
                            $checkExists->execute();
                            $count = 0;
                            $checkExists->bind_result($count);
                            $checkExists->fetch();
                            $checkExists->close();
                            
                            if ($count > 0) {
                                $voidStmt->bind_param(
                                    "ssssss",
                                    $uploadedBy,
                                    $uploadedDate,
                                    $regionID,
                                    $area,
                                    $tt['type'],
                                    $dbTransactionMonth
                                );
                                $voidStmt->execute();
                                $voidedGroups[] = $groupKey;
                                $debugInfo[] = "✓ Voided existing records for " . $tt['type'] . " (force insert enabled)";
                            }
                        }
                    }
                } else {
                    // Check if any existing regions exist without force insert
                    $hasExisting = false;
                    foreach ($transactionTypes as $tt) {
                        if (in_array($regionID . '-' . $area . '-' . $tt['type'], $existingRegions)) {
                            $hasExisting = true;
                            break;
                        }
                    }
                    if ($hasExisting) {
                        $debugInfo[] = "⚠ Records already exist and force_insert is not enabled - will ask for confirmation";
                        // Don't skip - let it go to the confirmation modal
                    }
                }

                $fileInsertCount = 0;
                $fileSkippedCount = 0;
                
                // Process data rows - STOP before NET Income row
                $processUntilRow = ($netIncomeRowIndex !== null) ? $netIncomeRowIndex - 1 : count($rows) - 1;
                $debugInfo[] = "Processing rows from $dataStartRow to $processUntilRow (stopping before NET Income)";
                
                for ($rowIndex = $dataStartRow; $rowIndex <= $processUntilRow && $rowIndex < count($rows); $rowIndex++) {
                    $row = $rows[$rowIndex] ?? [];
                    
                    $rowString = implode(' ', $row);
                    // Skip summary rows - but be more specific
                    if (stripos($rowString, 'NET Income') !== false || 
                        stripos($rowString, 'Net Income') !== false ||
                        (stripos($rowString, 'TOTAL') !== false && stripos($rowString, 'NET') !== false)) {
                        $skippedRows[] = "Row $rowIndex: Summary row (NET Income or TOTAL) - SKIPPED from insertion";
                        $fileSkippedCount++;
                        continue;
                    }

                    $isEmpty = true;
                    foreach ($row as $cell) {
                        if (!empty(trim($cell))) {
                            $isEmpty = false;
                            break;
                        }
                    }
                    if ($isEmpty) {
                        $skippedRows[] = "Row $rowIndex: Empty row";
                        $fileSkippedCount++;
                        continue;
                    }

                    $glCode = isset($row[$glCodeCol]) ? trim($row[$glCodeCol]) : '';
                    $description = isset($row[$descriptionCol]) ? trim($row[$descriptionCol]) : '';

                    // Skip rows without valid GL code - but be more flexible
                    if (empty($glCode) || empty($description)) {
                        $skippedRows[] = "Row $rowIndex: Missing GL Code or Description (glCode='$glCode', desc='$description')";
                        $fileSkippedCount++;
                        continue;
                    }

                    // Try to handle non-numeric GL codes that might be valid (like "GL001")
                    $glCodeNumeric = $glCode;
                    if (!is_numeric($glCode)) {
                        // Try to extract numbers from GL code
                        preg_match('/\d+/', $glCode, $matches);
                        if (!empty($matches)) {
                            $glCodeNumeric = $matches[0];
                        } else {
                            $skippedRows[] = "Row $rowIndex: Non-numeric GL Code (glCode='$glCode')";
                            $fileSkippedCount++;
                            continue;
                        }
                    }

                    // Insert each transaction type (Branch and Showroom) as separate records
                    foreach ($transactionTypes as $tt) {
                        if (isset($row[$tt['col']])) {
                            $amountValue = trim($row[$tt['col']] ?? '0');
                            
                            // Extract numeric amount using the enhanced function
                            $amount = extractNumericAmount($amountValue);
                            
                            // Allow zero amounts to be inserted
                            // Only skip if the value is truly empty (no value at all)
                            if ($amountValue === '') {
                                $skippedRows[] = "Row $rowIndex, {$tt['type']}: Empty amount value";
                                $fileSkippedCount++;
                                continue;
                            }
                            
                            // Insert the record with correct column mapping
                            $stmt->bind_param(
                                "ssdssssssssssss",
                                $glCodeNumeric,    // gl_code
                                $description,      // gl_description
                                $amount,          // amount (including 0.00)
                                $region,          // region (from lookup)
                                $area,            // area (CLEANED - just the letter)
                                $mainzone,        // mainzone
                                $zone,            // zone
                                $region_code,     // region_code
                                $tt['type'],      // transaction_type
                                $dbTransactionMonth, // transaction_month
                                $dbTransactionYear,  // transaction_year
                                $uploadedBy,      // uploaded_by
                                $regionID,        // region_id
                                $uploadedDate,    // uploaded_date
                                $glRegion         // gl_region (CLEANED - just the description)
                            );

                            if ($stmt->execute()) {
                                $insertCount++;
                                $fileInsertCount++;
                                $processedRows[] = "Row $rowIndex, {$tt['type']}: Inserted GL Code $glCodeNumeric, Amount " . number_format($amount, 2);
                            } else {
                                $skippedRows[] = "Row $rowIndex, {$tt['type']}: Insert failed - " . $stmt->error;
                                $fileSkippedCount++;
                            }
                        } else {
                            $skippedRows[] = "Row $rowIndex, {$tt['type']}: Column index {$tt['col']} not found in row";
                            $fileSkippedCount++;
                        }
                    }
                }
                $debugInfo[] = "✓ Inserted $fileInsertCount records from this file";
                $debugInfo[] = "✗ Skipped $fileSkippedCount rows from this file";
                $debugInfo[] = "--- End of file processing ---";
            }

            $stmt->close();
            $checkStatusStmt->close();
            $voidStmt->close();

            $dateObj = ($dbTransactionMonth) ? DateTime::createFromFormat('Y-m-d', $dbTransactionMonth) : false;
            $monthDisplay = $dateObj ? $dateObj->format('F Y') : ($transactionMonth ? date('F', mktime(0, 0, 0, (int)$transactionMonth, 1)) . ' ' . $transactionYear : $transactionYear);

            // MODIFIED: Check if any regions are blocked
            if (!empty($blockedRegions)) {
                $conn->rollback();
                $blockedRegions = array_unique($blockedRegions);
                $regionList = implode(', ', $blockedRegions);
                $blockedReasonsList = implode('<br>', array_unique($blockedReasons));
                
                $_SESSION['upload_message'] = "<div class='error'>
                    <strong>⛔ Upload Blocked!</strong><br><br>
                    The following regions cannot be updated: <strong>{$regionList}</strong>. They are either <strong>VOIDED</strong> or have been <strong>REPORTED</strong>.<br><br>
                </div>";

                foreach ($paths as $p) {
                    if (file_exists($p)) {
                        unlink($p);
                    }
                }
                header("Location: comparative_report_csv.php");
                exit;
            } 
            // MODIFIED: Check if we have any existing regions that can be replaced
            else if (!empty($existingRegions) && !$forceInsert) {
                $conn->rollback();
                $existingRegions = array_unique($existingRegions);
                $regionList = implode(', ', $existingRegions);
                $showConfirmModal = true;
                $confirmMonth = $transactionMonthYear; // Pass the combined month
                $confirmYear = $transactionYear;
                $confirmPaths = $paths;
                $duplicateMessage = "<i class='fa-solid fa-circle-info'></i> <strong>Existing Records Found</strong><br>
                    Transactions for the following regions already exist for {$monthDisplay}:<br>
                    <strong>{$regionList}</strong><br><br>
                    These records are currently in an <strong>editable state</strong>.";
            } else {
                $conn->commit();
                foreach ($paths as $p) {
                    if (file_exists($p)) {
                        unlink($p);
                    }
                }

                $periodDisplay = '';
                if (!empty($transactionMonth) && !empty($transactionYear)) {
                    $monthName = date('F', mktime(0, 0, 0, (int)$transactionMonth, 1));
                    $periodDisplay = "$monthName $transactionYear";
                } elseif (!empty($transactionYear)) {
                    $periodDisplay = $transactionYear;
                }

                // Add detailed debug info
                $debugOutput = implode("\n", $debugInfo);
                $skippedOutput = implode("\n", $skippedRows);
                $processedOutput = implode("\n", $processedRows);
                
                $_SESSION['upload_message'] = "<div class='success'><i class='fa-solid fa-check'></i> Success! Processed all CSV files. <strong>$insertCount</strong> total records inserted for $periodDisplay.<br><br>
                    <div class='debug-details'>
                        <details style='margin-top:10px;'>
                            <summary style='cursor:pointer;font-weight:bold;color:#2d3748;'><i class='fa-solid fa-list'></i> Processing Summary</summary>
                            <div style='margin-top:10px;'>
                                <p><strong>Total Records Inserted:</strong> $insertCount</p>
                                <p><strong>Total Rows Skipped:</strong> " . count($skippedRows) . "</p>
                            </div>
                        </details>
                        " . (count($skippedRows) > 0 ? "
                        <details style='margin-top:10px;'>
                            <summary style='cursor:pointer;font-weight:bold;color:#e53e3e;'><i class='fa-solid fa-triangle-exclamation'></i> Skipped Rows Details (" . count($skippedRows) . ")</summary>
                            <pre style='background:#fff5f5;padding:15px;border-radius:5px;font-size:12px;text-align:left;white-space:pre-wrap;word-wrap:break-word;max-height:300px;overflow-y:auto;margin:10px 0 0 0;color:#c53030;'>" . htmlspecialchars($skippedOutput) . "</pre>
                        </details>
                        " : "") . "
                        <details style='margin-top:10px;'>
                            <summary style='cursor:pointer;font-weight:bold;color:#2d3748;'><i class='fa-solid fa-circle-info'></i> Detailed Debug Log</summary>
                            <pre style='background:#f5f5f5;padding:15px;border-radius:5px;font-size:12px;text-align:left;white-space:pre-wrap;word-wrap:break-word;max-height:500px;overflow-y:auto;margin:10px 0 0 0;'>" . htmlspecialchars($debugOutput) . "</pre>
                        </details>
                        " . (count($processedRows) > 0 ? "
                        <details style='margin-top:10px;'>
                            <summary style='cursor:pointer;font-weight:bold;color:#38a169;'><i class='fa-solid fa-check-double'></i> Inserted Rows Details (" . count($processedRows) . ")</summary>
                            <pre style='background:#f0fff4;padding:15px;border-radius:5px;font-size:12px;text-align:left;white-space:pre-wrap;word-wrap:break-word;max-height:300px;overflow-y:auto;margin:10px 0 0 0;color:#276749;'>" . htmlspecialchars($processedOutput) . "</pre>
                        </details>
                        " : "") . "
                    </div>
                </div>";
                header("Location: comparative_report_csv.php");
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['upload_message'] = '<div class="error">Database Error: ' . $e->getMessage() . '</div>';
            header("Location: comparative_report_csv.php");
            exit;
        }
    } else {
        $_SESSION['upload_message'] = '<div class="error">Session expired or file missing.</div>';
        header("Location: comparative_report_csv.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report CSV</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/comparative_csv.css?v=<?= time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .debug-details {
            background: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        .debug-details summary {
            cursor: pointer;
            font-weight: 600;
            color: #2d3748;
        }
        .debug-details pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            font-size: 12px;
            text-align: left;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 500px;
            overflow-y: auto;
            margin: 10px 0 0 0;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 30px;
            border-radius: 8px;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: #000;
        }
        .modal-icon {
            text-align: center;
            margin-bottom: 15px;
        }
        .modal-icon i {
            font-size: 3rem;
        }
        .fa-check-circle {
            color: #28a745;
        }
        .fa-exclamation-circle {
            color: #dc3545;
        }
        .fa-exclamation-triangle {
            color: #f0ad4e;
        }
        .modal-title {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 15px;
        }
        .modal-message {
            text-align: left;
            font-size: 1em;
            margin-bottom: 20px;
        }
        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        .btn-confirm {
            padding: 10px 25px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-cancel {
            padding: 10px 25px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-confirm:hover {
            background: #218838;
        }
        .btn-cancel:hover {
            background: #5a6268;
        }
        .modal-message ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .modal-message ul li {
            margin: 5px 0;
        }
        .month-picker-container {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        .month-picker-container label {
            font-weight: 600;
            margin-bottom: 5px;
        }
        .month-picker-container input[type="month"] {
            padding: 10px;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            width: 220px;
            font-size: 14px;
        }
        /* Style for empty month input when no value selected */
        .month-picker-container input[type="month"]:invalid {
            border-color: #e53e3e;
        }
        .month-picker-container input[type="month"]:invalid:focus {
            border-color: #e53e3e;
            box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.15);
        }
        .required-star {
            color: #e53e3e;
            margin-left: 2px;
        }
        /* Highlight NET Income row in table */
        .net-income-row {
            background: #fff5f5 !important;
            font-weight: bold !important;
            border-top: 3px solid #e53e3e !important;
        }
        .net-income-row td {
            background: #fff5f5 !important;
        }
        .net-income-row td.numeric-amount {
            color: #e53e3e !important;
            font-weight: bold !important;
        }
        /* Status Legend Styles */
        .status-legend {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .status-legend h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
        }
        .status-legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            font-size: 13px;
        }
        .status-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .status-dot-active {
            background: #38a169;
        }
        .status-dot-voided {
            background: #e53e3e;
        }
        .status-dot-reported {
            background: #f6ad55;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <a href="user_dashboard.php" style="font-size: 16px; text-decoration: none; font-weight: bold;">⬅ Back</a>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="card">
                <h3>Upload Raw CSV Report (Per Zone)</h3>

                <!-- Status Legend -->
                <!-- <div class="status-legend">
                    <h4><i class="fa-solid fa-circle-info"></i> Status Definitions:</h4>
                    <div class="status-legend-grid">
                        <div class="status-legend-item">
                            <span class="status-dot status-dot-active"></span>
                            <strong>Active</strong> - Current valid record (status_void = NULL)
                        </div>
                        <div class="status-legend-item">
                            <span class="status-dot status-dot-voided"></span>
                            <strong>Voided</strong> - Superseded by newer upload (status_void = 'Void')
                        </div>
                        <div class="status-legend-item">
                            <span class="status-dot status-dot-reported"></span>
                            <strong>Reported</strong> - Finalized, cannot be modified (reported_status = 'Reported')
                        </div>
                    </div>
                </div> -->

                <form class="upload-form" method="post" enctype="multipart/form-data" id="uploadForm">
                    <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                        <div class="month-picker-container" style="margin-bottom: 0;">
                            <label for="transaction_month">Transaction Month: <span class="required-star">*</span></label>
                            <input type="month" name="transaction_month" id="transaction_month" required value="<?php echo htmlspecialchars($selectedMonth); ?>" placeholder="Select month">
                        </div>

                        <div style="display: flex; flex-direction: column; flex: 1;">
                            <label style="font-weight: 600; margin-bottom: 5px;">Choose CSV File(s): <span class="required-star">*</span></label>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                                <input type="file" name="csv_file[]" accept=".csv,text/csv" required multiple style="padding: 9px; border: 1px solid #cbd5e0; border-radius: 8px; flex: 1; min-width: 200px;">
                                <button type="submit" style="padding: 9px 20px; background: #cf0101; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; white-space: nowrap;"><i class="fa-solid fa-eye"></i> Preview</button>
                                <a href="report.php" style="padding: 7px 20px; background: #217346; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; white-space: nowrap; "><i class="fa-regular fa-file"></i> View Report</a>
                                <a href="comparative_report_csv.php" style="padding: 9px 20px; background: #e2e8f0; color: #4a5568; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px;"><i class="fa-solid fa-rotate"></i> Refresh</a>
                            </div>
                        </div>
                    </div>
                </form>

                <?php if (!empty($previewTable)): ?>
                    <div style="background: #ffebeb; border: 1px solid #e14242; padding: 15px; border-radius: 8px; margin-bottom: 5px; margin-top: 20px;">
                        <p style="margin-top:0;"><strong>Review and Save</strong></p>
                        <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                            <input type="hidden" name="do_insert" value="1">
                            <input type="hidden" name="transaction_month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                            <?php foreach ($tempFilePaths as $path): ?>
                                <input type="hidden" name="temp_file_paths[]" value="<?php echo htmlspecialchars($path); ?>">
                            <?php endforeach; ?>

                            <button type="submit" style="padding:10px 25px; background: linear-gradient(45deg, #ff524c, #8e0005); color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:bold;">
                                <i class="fa-solid fa-arrow-up-from-bracket"></i> Proceed
                            </button>
                            <a href="?" style="margin-left:10px; color:#f56565; text-decoration:none;">Cancel</a>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($uploadMessage)): ?>
                    <div id="messageModal" class="modal" style="display: block;">
                        <div class="modal-content" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
                            <span class="close" onclick="closeMessageModal()">&times;</span>
                            <div class="modal-icon" style="text-align: center; margin-bottom: 10px;">
                                <?php if (strpos($uploadMessage, 'Success') !== false || strpos($uploadMessage, '✅') !== false): ?>
                                    <i class="fas fa-check-circle" style="color: #28a745; font-size: 3rem;"></i>
                                <?php else: ?>
                                    <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 3rem;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="modal-message" style="text-align: left; font-size: 1em;">
                                <?php echo $uploadMessage; ?>
                            </div>
                            <div class="modal-actions" style="justify-content: center; margin-top: 20px; display: flex;">
                                <button type="button" class="btn-cancel" onclick="closeMessageModal()" style="padding: 8px 25px; cursor: pointer; background-color: #6c757d; color: white; border: none; border-radius: 4px;">Close</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php echo $previewTable; ?>
            </div>
        </div>
    </main>

    <?php if (isset($showConfirmModal) && $showConfirmModal): ?>
    <div id="confirmModal" class="modal" style="display: block;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <div class="modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="modal-title">Confirm Replacement?</div>
            <div class="modal-message">
                <?php echo isset($duplicateMessage) ? $duplicateMessage : "Some of these transactions are already recorded."; ?>
                <br><br>
                <strong>What will happen:</strong>
                <ul>
                    <li>New records will be inserted with the updated data</li>
                    <li>Old records will be marked as <strong>VOID</strong></li>
                    <!-- <li>Old records will have <strong>voided_by</strong> and <strong>voided_at</strong> set</li> -->
                </ul>
                <strong style="color:#e53e3e;">Do you want to insert new records and void the previous?</strong>
            </div>
            <form method="post">
                <input type="hidden" name="do_insert" value="1">
                <input type="hidden" name="transaction_month" value="<?php echo htmlspecialchars($confirmMonth); ?>">
                <?php foreach ($confirmPaths as $path): ?>
                    <input type="hidden" name="temp_file_paths[]" value="<?php echo htmlspecialchars($path); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="force_insert" value="1">
                <div class="modal-actions">
                    <button type="submit" class="btn-confirm">Yes</button>
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function closeModal() {
            window.location.href = 'comparative_report_csv.php';
        }

        function closeMessageModal() {
            const modal = document.getElementById('messageModal');
            if (modal) {
                modal.style.display = 'none';
                // Refresh page if it's a success message
                const messageContent = document.querySelector('.modal-message');
                if (messageContent && messageContent.textContent.includes('Success')) {
                    setTimeout(function() {
                        window.location.href = 'comparative_report_csv.php';
                    }, 500);
                }
            }
        }

        document.addEventListener("DOMContentLoaded", function () {
            const form = document.getElementById('uploadForm');
            const monthInput = document.getElementById('transaction_month');

            form.addEventListener('submit', function(e) {
                const monthValue = monthInput.value;
                if (!monthValue) {
                    e.preventDefault();
                    alert('⚠️ Please select a Transaction Month before uploading.');
                    monthInput.focus();
                    return false;
                }
            });
        });
    </script>

<?php include '../footer.php'; ?>

</body>
</html>
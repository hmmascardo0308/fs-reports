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
    
    // Return the delimiter with the highest count
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

    // Read first line to detect delimiter
    $firstLine = fgets($handle);
    rewind($handle);
    
    if ($firstLine === false) {
        fclose($handle);
        return [];
    }
    
    $delimiter = detectDelimiter($firstLine);
    
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        // Clean up each cell - remove quotes, trim whitespace
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

// --- STAGE 1: UPLOAD & PREVIEW ---
// --- STAGE 1: UPLOAD & PREVIEW ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $transactionMonth = $_POST['transaction_month'] ?? '';
    $transactionYear = $_POST['transaction_year'] ?? '';
    $file = $_FILES['csv_file'];

    if (!empty($transactionMonth) && empty($transactionYear)) {
        $uploadMessage = '<div class="error">Transaction Year is required when Transaction Month is selected.</div>';
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

                // Extract metadata from rows 0, 1, 2
                $regionID = trim($rows[0][0] ?? '');
                $regionDescription = trim($rows[1][0] ?? '');
                $area = trim($rows[2][0] ?? '');

                // Clean up metadata
                $regionID = str_replace(['Region ID:', 'Region ID :', '"', "'", ' '], '', $regionID);
                $regionID = trim($regionID);
                
                $regionDescription = str_replace(['Region Description:', 'Region Description :', '"', "'"], '', $regionDescription);
                $regionDescription = trim($regionDescription);
                
                $area = str_replace(['Area:', 'Area :', '"', "'"], '', $area);
                $area = trim($area);

                // Find where data actually starts
                $glCodeCol = null;
                $descriptionCol = null;
                $dataStartRow = null;
                $branchAmountCol = null;
                $showroomAmountCol = null;
                $percentageCol = null;
                $headerRowIndex = null;

                // First, find the header row with "GLCode" and "Description"
                for ($i = 0; $i < min(15, count($rows)); $i++) {
                    $row = $rows[$i] ?? [];
                    $hasGLCode = false;
                    $hasDescription = false;
                    
                    foreach ($row as $colIndex => $cell) {
                        $cellClean = trim((string)$cell);
                        if (stripos($cellClean, 'GLCode') !== false) {
                            $glCodeCol = $colIndex;
                            $hasGLCode = true;
                        }
                        if (stripos($cellClean, 'Description') !== false) {
                            $descriptionCol = $colIndex;
                            $hasDescription = true;
                        }
                        if (stripos($cellClean, 'Branch Amount') !== false) {
                            $branchAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, 'Showroom Amount') !== false) {
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
                    // Look for "Category" row
                    for ($i = 0; $i < min(10, count($rows)); $i++) {
                        $row = $rows[$i] ?? [];
                        foreach ($row as $colIndex => $cell) {
                            $cellClean = trim((string)$cell);
                            if (stripos($cellClean, 'Category') !== false) {
                                // The next row might have GLCode and Description
                                if (isset($rows[$i + 1])) {
                                    $nextRow = $rows[$i + 1];
                                    foreach ($nextRow as $colIdx => $cellVal) {
                                        $cellValClean = trim((string)$cellVal);
                                        if (stripos($cellValClean, 'GLCode') !== false) {
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
                    $headerRowIndex = 4; // Assuming row 4 is the header (0-indexed)
                    $dataStartRow = 5;
                }
                if ($glCodeCol === null) {
                    $glCodeCol = 1;
                }
                if ($descriptionCol === null) {
                    $descriptionCol = 2;
                }

                // Find where NET Income appears to stop
                $cutoffRow = count($rows) - 1;
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $cell) {
                        if (stripos(trim((string)$cell), 'NET Income') !== false) {
                            $cutoffRow = $rowIndex;
                            break 2;
                        }
                    }
                }

                // Build preview - METADATA SECTION
                $previewTable .= '<div class="file-preview-container">';
                $previewTable .= '<h4 class="file-name" style="colpr: white;">📄 Preview: ' . htmlspecialchars($singleFile['name']) . '</h4>';
                
                // Metadata section
                $previewTable .= '<div class="metadata-section" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #4a90d9;">';
                $previewTable .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">';
                $previewTable .= '<div><strong>🏷️ Region ID:</strong> <span style="color: #2d3748;">' . htmlspecialchars($regionID) . '</span></div>';
                $previewTable .= '<div><strong>📍 Region Description:</strong> <span style="color: #2d3748;">' . htmlspecialchars($regionDescription) . '</span></div>';
                $previewTable .= '<div><strong>📌 Area:</strong> <span style="color: #2d3748;">' . htmlspecialchars($area) . '</span></div>';
                $previewTable .= '</div>';
                $previewTable .= '</div>';

                // DATA TABLE SECTION
                $previewTable .= '<div class="table-container" style="max-height: 600px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">';
                $previewTable .= '<table class="excel-preview" style="width: 100%; border-collapse: collapse;">';

                // Show the header row first (with sticky positioning)
                if (isset($rows[$headerRowIndex])) {
                    $headerRow = $rows[$headerRowIndex];
                    $previewTable .= '<thead><tr class="sticky-row-header" style="background: #ff0000;">';
                    foreach ($headerRow as $colKey => $cell) {
                        $previewTable .= '<th style="padding: 10px 12px; border: 1px solid #e2e8f0; font-weight: 600; text-align: left; white-space: nowrap; position: sticky; top: 0; background: #ff0000; z-index: 10;">' . htmlspecialchars($cell ?? '') . '</th>';
                    }
                    $previewTable .= '</tr></thead>';
                }

                // Show ALL data rows from dataStartRow to cutoffRow
                $previewTable .= '<tbody>';
                $rowCount = 0;
                for ($rowIndex = $dataStartRow; $rowIndex <= $cutoffRow && $rowIndex < count($rows); $rowIndex++) {
                    $row = $rows[$rowIndex];
                    
                    // Skip empty rows
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
                    $previewTable .= '<tr>';
                    
                    // Display all columns in the row
                    foreach ($row as $colKey => $cell) {
                        $style = 'padding: 8px 12px; border: 1px solid #e2e8f0;';
                        // Style based on content
                        $cellValue = trim($cell ?? '');
                        if (is_numeric(str_replace(',', '', $cellValue))) {
                            $style .= ' text-align: right;';
                        }
                        // Highlight GL Code column if it's numeric
                        if ($colKey == $glCodeCol && !empty($cellValue) && is_numeric($cellValue)) {
                            $style .= ' font-weight: 500; color: #2b6cb0;';
                        }
                        $previewTable .= "<td style='$style'>" . htmlspecialchars($cell ?? '') . "</td>";
                    }
                    
                    // If row has fewer columns than header, add empty cells
                    if (isset($rows[$headerRowIndex])) {
                        $headerCount = count($rows[$headerRowIndex]);
                        $currentCount = count($row);
                        if ($currentCount < $headerCount) {
                            for ($i = $currentCount; $i < $headerCount; $i++) {
                                $previewTable .= "<td style='padding: 8px 12px; border: 1px solid #e2e8f0;'></td>";
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
                $previewTable .= '<small>📊 <strong>Summary:</strong> Total rows: ' . $totalRows . ' | Data rows: ' . $rowCount . ' | Header at row: ' . ($headerRowIndex + 1) . ' | Metadata rows: 3 (Region ID, Region Description, Area)</small>';
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
    $transactionMonth = $_POST['transaction_month'] ?? '';
    $transactionYear = $_POST['transaction_year'] ?? '';
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
        $lockedRegions = [];
        $existingRegions = [];
        $voidedGroups = [];
        $checkedGroups = [];
        $insertCount = 0;

        try {
            $stmt = $conn->prepare("
                INSERT INTO comparative_report
                (gl_code, gl_description, amount, percentage, region, area, mainzone, zone, region_code,
                 transaction_type, transaction_month, transaction_year, uploaded_by, region_id,
                 uploaded_date, gl_region)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $checkStatusStmt = $conn->prepare("SELECT status FROM comparative_report WHERE region_id = ? AND area = ? AND transaction_type = ? AND transaction_month <=> ? AND status_void IS NULL LIMIT 1");
            
            $voidStmt = $conn->prepare("UPDATE comparative_report SET status = 'Locked', locked_by = ?, locked_date = ?, status_void = 'Void', voided_by = ?, voided_at = ? WHERE region_id = ? AND area = ? AND transaction_type = ? AND transaction_month <=> ? AND status_void IS NULL");

            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    continue;
                }

                $rows = readCsvRows($path);
                if (empty($rows)) {
                    continue;
                }

                $maxCols = maxCsvColumns($rows);

                // Extract Region ID from A1 (row 0, col 0)
                $regionID = trim($rows[0][0] ?? '');
                $regionID = str_replace(['Region ID:', 'Region ID :', '"', "'", ' '], '', $regionID);
                $regionID = trim($regionID);
                $regionID = preg_replace('/[^0-9]/', '', $regionID);
                
                // Extract Region Description from row 1, col 0
                $glRegion = trim($rows[1][0] ?? '');
                $glRegion = str_replace(['Region Description:', 'Region Description :', '"', "'"], '', $glRegion);
                $glRegion = trim($glRegion);
                
                // Extract Area from row 2, col 0
                $area = trim($rows[2][0] ?? '');
                $area = str_replace(['Area:', 'Area :', '"', "'"], '', $area);
                $area = trim($area);

                // Lookup region details from masterdata.branch_profile
                $region = $region_code = $mainzone = $zone = null;

if (!empty($regionID) && !empty($area)) {
    $lookup_sql = "SELECT region, region_code, mainzone, zone
                   FROM masterdata.branch_profile
                   WHERE regionID_MLmatic = ? AND area = ?
                   LIMIT 1";

    $lookup_stmt = $conn->prepare($lookup_sql);

    if ($lookup_stmt) {
        $lookup_stmt->bind_param("ss", $regionID, $area);

        $lookup_stmt->execute();

        // Initialize variables first
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
        }

        $lookup_stmt->close();
    }
}

                // If lookup failed, try to find region_code from region_masterfile
                if (empty($region_code) && !empty($glRegion)) {
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
                        }
                        $rc_stmt->close();
                    }
                }

                if ($region === null || $area === null || $region_code === null) {
                    continue;
                }

                // Find where the data starts
                $glCodeCol = null;
                $descriptionCol = null;
                $branchAmountCol = null;
                $showroomAmountCol = null;
                $percentageCol = null;
                $dataStartRow = null;

                for ($i = 0; $i < min(10, count($rows)); $i++) {
                    $row = $rows[$i] ?? [];
                    foreach ($row as $colIndex => $cell) {
                        $cellClean = trim((string)$cell);
                        if (stripos($cellClean, 'GLCode') !== false) {
                            $glCodeCol = $colIndex;
                        }
                        if (stripos($cellClean, 'Description') !== false) {
                            $descriptionCol = $colIndex;
                        }
                        if (stripos($cellClean, 'Branch Amount') !== false) {
                            $branchAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, 'Showroom Amount') !== false) {
                            $showroomAmountCol = $colIndex;
                        }
                        if (stripos($cellClean, '%') !== false) {
                            $percentageCol = $colIndex;
                        }
                    }
                    
                    if ($glCodeCol !== null && $descriptionCol !== null) {
                        $dataStartRow = $i + 1;
                        break;
                    }
                }

                if ($dataStartRow === null) {
                    for ($i = 0; $i < min(10, count($rows)); $i++) {
                        $row = $rows[$i] ?? [];
                        foreach ($row as $colIndex => $cell) {
                            $cellClean = trim((string)$cell);
                            if (stripos($cellClean, 'Category') !== false) {
                                if (isset($rows[$i + 1])) {
                                    $nextRow = $rows[$i + 1];
                                    foreach ($nextRow as $colIdx => $cellVal) {
                                        $cellValClean = trim((string)$cellVal);
                                        if (stripos($cellValClean, 'GLCode') !== false) {
                                            $glCodeCol = $colIdx;
                                        }
                                        if (stripos($cellValClean, 'Description') !== false) {
                                            $descriptionCol = $colIdx;
                                        }
                                    }
                                    if ($glCodeCol !== null && $descriptionCol !== null) {
                                        $dataStartRow = $i + 2;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($dataStartRow !== null) {
                            break;
                        }
                    }
                }

                if ($glCodeCol === null) {
                    $glCodeCol = 1;
                }
                if ($descriptionCol === null) {
                    $descriptionCol = 2;
                }
                if ($dataStartRow === null) {
                    $dataStartRow = 5;
                }

                // Find where NET Income appears
                $cutoffRow = count($rows) - 1;
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $cell) {
                        if (stripos(trim((string)$cell), 'NET Income') !== false) {
                            $cutoffRow = $rowIndex;
                            break 2;
                        }
                    }
                }

                // Process data rows
                $transactionTypes = [];
                if ($branchAmountCol !== null) {
                    $transactionTypes[] = ['type' => 'Branch', 'col' => $branchAmountCol];
                }
                if ($showroomAmountCol !== null) {
                    $transactionTypes[] = ['type' => 'Showroom', 'col' => $showroomAmountCol];
                }

                if (empty($transactionTypes)) {
                    continue;
                }

                // Check for existing records
                foreach ($transactionTypes as $tt) {
                    $groupKey = $regionID . '|' . $area . '|' . $tt['type'] . '|' . ($dbTransactionMonth ?? 'NULL');
                    
                    if (!in_array($groupKey, $checkedGroups)) {
                        $existingStatus = null;
                        $checkStatusStmt->bind_param("ssss", $regionID, $area, $tt['type'], $dbTransactionMonth);
                        $checkStatusStmt->execute();
                        $checkStatusStmt->store_result();

                        if ($checkStatusStmt->num_rows > 0) {
                            $checkStatusStmt->bind_result($existingStatus);
                            $checkStatusStmt->fetch();

                            if ($existingStatus === 'Locked') {
                                $lockedRegions[] = $regionID . '-' . $area . '-' . $tt['type'];
                            } elseif (!$forceInsert) {
                                $existingRegions[] = $regionID . '-' . $area . '-' . $tt['type'];
                            }
                        }
                        $checkStatusStmt->free_result();
                        $checkedGroups[] = $groupKey;
                    }
                }

                $regionLocked = false;
                foreach ($transactionTypes as $tt) {
                    if (in_array($regionID . '-' . $area . '-' . $tt['type'], $lockedRegions)) {
                        $regionLocked = true;
                        break;
                    }
                }
                if ($regionLocked) {
                    continue;
                }

                if ($forceInsert) {
                    foreach ($transactionTypes as $tt) {
                        $groupKey = $regionID . '|' . $area . '|' . $tt['type'] . '|' . ($dbTransactionMonth ?? 'NULL');
                        if (!in_array($groupKey, $voidedGroups)) {
                            $voidStmt->bind_param(
                                "ssssssss",
                                $uploadedBy,
                                $uploadedDate,
                                $uploadedBy,
                                $uploadedDate,
                                $regionID,
                                $area,
                                $tt['type'],
                                $dbTransactionMonth
                            );
                            $voidStmt->execute();
                            $voidedGroups[] = $groupKey;
                        }
                    }
                } else {
                    $hasExisting = false;
                    foreach ($transactionTypes as $tt) {
                        if (in_array($regionID . '-' . $area . '-' . $tt['type'], $existingRegions)) {
                            $hasExisting = true;
                            break;
                        }
                    }
                    if ($hasExisting) {
                        continue;
                    }
                }

                // Process data rows
                for ($rowIndex = $dataStartRow; $rowIndex < count($rows) && $rowIndex <= $cutoffRow; $rowIndex++) {
                    $row = $rows[$rowIndex] ?? [];
                    
                    $rowString = implode(' ', $row);
                    if (stripos($rowString, 'NET Income') !== false) {
                        break;
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

                    $glCode = isset($row[$glCodeCol]) ? trim($row[$glCodeCol]) : '';
                    $description = isset($row[$descriptionCol]) ? trim($row[$descriptionCol]) : '';

                    if (empty($glCode) || !is_numeric($glCode) || empty($description)) {
                        continue;
                    }

                    foreach ($transactionTypes as $tt) {
                        if (isset($row[$tt['col']])) {
                            $amountValue = trim($row[$tt['col']] ?? '0');
                            $amount = (float)str_replace([',', ' ', '₱', '$', '(', ')'], '', $amountValue);
                            
                            if (strpos($amountValue, '(') !== false && strpos($amountValue, ')') !== false) {
                                $amount = -$amount;
                            }
                            
                            $percentage = $percentageCol !== null ? trim($row[$percentageCol] ?? '0') : '0';
                            
                            $stmt->bind_param(
                                "ssdsssssssssssss",
                                $glCode,
                                $description,
                                $amount,
                                $percentage,
                                $region,
                                $area,
                                $mainzone,
                                $zone,
                                $region_code,
                                $tt['type'],
                                $dbTransactionMonth,
                                $dbTransactionYear,
                                $uploadedBy,
                                $regionID,
                                $uploadedDate,
                                $glRegion
                            );

                            if ($stmt->execute()) {
                                $insertCount++;
                            }
                        }
                    }
                }
            }

            $stmt->close();
            $checkStatusStmt->close();
            $voidStmt->close();

            $dateObj = ($dbTransactionMonth) ? DateTime::createFromFormat('Y-m-d', $dbTransactionMonth) : false;
            $monthDisplay = $dateObj ? $dateObj->format('F Y') : ($transactionMonth ? date('F', mktime(0, 0, 0, (int)$transactionMonth, 1)) . ' ' . $transactionYear : $transactionYear);

            if (!empty($lockedRegions)) {
                $conn->rollback();
                $lockedRegions = array_unique($lockedRegions);
                $regionList = implode(', ', $lockedRegions);
                $_SESSION['upload_message'] = "<div class='error'>The following regions are locked for {$monthDisplay}: {$regionList}. Please unlock to upload.</div>";
                foreach ($paths as $p) {
                    if (file_exists($p)) {
                        unlink($p);
                    }
                }
                header("Location: comparative_report_csv.php");
                exit;
            } elseif (!empty($existingRegions)) {
                $conn->rollback();
                $existingRegions = array_unique($existingRegions);
                $regionList = implode(', ', $existingRegions);
                $showConfirmModal = true;
                $confirmMonth = $transactionMonth;
                $confirmYear = $transactionYear;
                $confirmPaths = $paths;
                $duplicateMessage = "Transactions for the following regions already exist for {$monthDisplay}: {$regionList}.";
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

                $_SESSION['upload_message'] = "<div class='success'>Success! Processed all CSV files. $insertCount total records inserted for $periodDisplay.</div>";
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

</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <a href="user_dashboard.php" style="font-size: 16px; text-decoration: none; font-weight: bold;">Back</a>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="card">
                <h3>Upload Raw CSV Report (Per Zone)</h3>

                <form class="upload-form" method="post" enctype="multipart/form-data" id="uploadForm">
                    <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                        <div style="display: flex; flex-direction: column;">
                            <label for="transaction_month" style="font-weight: 600; margin-bottom: 5px;">Transaction Month:</label>
                            <select name="transaction_month" id="transaction_month" style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 8px; width: 160px;">
                                <option value="">-- Select Month --</option>
                                <option value="01">January</option>
                                <option value="02">February</option>
                                <option value="03">March</option>
                                <option value="04">April</option>
                                <option value="05">May</option>
                                <option value="06">June</option>
                                <option value="07">July</option>
                                <option value="08">August</option>
                                <option value="09">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>

                        <div style="display: flex; flex-direction: column;">
                            <label for="transaction_year" style="font-weight: 600; margin-bottom: 5px;">Transaction Year: <span id="year_required" style="color: #e53e3e; display: none;">*</span></label>
                            <select name="transaction_year" id="transaction_year" style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 8px; width: 160px;">
                                <option value="">-- Select Year --</option>
                                <?php
                                    $currentYear = date("Y");
                                    for ($i = $currentYear; $i >= $currentYear - 5; $i--):
                                ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div style="display: flex; flex-direction: column;">
                            <label style="font-weight: 600; margin-bottom: 5px;">Choose CSV File:</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="file" name="csv_file[]" accept=".csv,text/csv" required multiple style="padding: 9px; border: 1px solid #cbd5e0; border-radius: 8px;">
                                <button type="submit" style="margin-left: 0;"><i class="fa-solid fa-eye"></i> Preview</button>
                                <a href="report.php" class="btn-generate"><i class="fa-regular fa-file"></i> View Report</a>
                                <a href="comparative_report_csv.php" class="btn-reset"><i class="fa-solid fa-rotate"></i> Refresh</a>
                            </div>
                        </div>
                    </div>
                </form>

                <?php if (!empty($previewTable)): ?>
                    <div style="background: #ffebeb; border: 1px solid #e14242; padding: 15px; border-radius: 8px; margin-bottom: 5px;">
                        <p style="margin-top:0;"><strong>Review and Save</strong></p>
                        <form method="post">
                            <input type="hidden" name="do_insert" value="1">
                            <input type="hidden" name="transaction_month" value="<?php echo htmlspecialchars($transactionMonth); ?>">
                            <input type="hidden" name="transaction_year" value="<?php echo htmlspecialchars($transactionYear); ?>">
                            <?php foreach ($tempFilePaths as $path): ?>
                                <input type="hidden" name="temp_file_paths[]" value="<?php echo htmlspecialchars($path); ?>">
                            <?php endforeach; ?>

                            <button type="submit" style="padding:12px 25px; background: linear-gradient(45deg, #ff524c, #8e0005); color:#fff; border:none; border-radius:8px; cursor:pointer; font-weight:bold;">
                                <i class="fa-solid fa-arrow-up-from-bracket"></i> Proceed
                            </button>
                            <a href="?" style="margin-left:10px; color:#f56565; text-decoration:none;">Cancel</a>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($uploadMessage)): ?>
                    <div id="messageModal" class="modal" style="display: block;">
                        <div class="modal-content">
                            <span class="close" onclick="closeMessageModal()">&times;</span>
                            <div class="modal-icon" style="text-align: center; margin-bottom: 10px;">
                                <?php if (strpos($uploadMessage, 'success') !== false): ?>
                                    <i class="fas fa-check-circle" style="color: #28a745; font-size: 3rem;"></i>
                                <?php else: ?>
                                    <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 3rem;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="modal-message" style="text-align: center; font-size: 1.1em;">
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
            <div class="modal-title">Duplicate Transactions Detected</div>
            <div class="modal-message">
                <?php echo isset($duplicateMessage) ? htmlspecialchars($duplicateMessage) : "Some of these transactions are already recorded."; ?> <br>
                Proceed anyway?
            </div>
            <form method="post">
                <input type="hidden" name="do_insert" value="1">
                <input type="hidden" name="transaction_month" value="<?php echo htmlspecialchars($confirmMonth); ?>">
                <input type="hidden" name="transaction_year" value="<?php echo htmlspecialchars($confirmYear); ?>">
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
            }
        }

        document.addEventListener("DOMContentLoaded", function () {
            const form = document.getElementById('uploadForm');
            const monthSelect = document.getElementById('transaction_month');
            const yearSelect = document.getElementById('transaction_year');
            const yearRequired = document.getElementById('year_required');

            monthSelect.addEventListener('change', function() {
                if (this.value) {
                    yearRequired.style.display = 'inline';
                    yearSelect.setAttribute('required', 'required');
                } else {
                    yearRequired.style.display = 'none';
                    yearSelect.removeAttribute('required');
                }
            });

            form.addEventListener('submit', function(e) {
                const monthValue = monthSelect.value;
                const yearValue = yearSelect.value;

                if (monthValue && !yearValue) {
                    e.preventDefault();
                    alert('Transaction Year is required when Transaction Month is selected.');
                    yearSelect.focus();
                    return false;
                }

                if (!monthValue && !yearValue) {
                    e.preventDefault();
                    alert('Please select at least a Transaction Year.');
                    yearSelect.focus();
                    return false;
                }
            });
        });
    </script>

<?php include '../footer.php'; ?>

</body>
</html>
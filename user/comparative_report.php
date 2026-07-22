<?php
session_start();

// Flash message handling
$uploadMessage = '';
if (isset($_SESSION['upload_message'])) {
    $uploadMessage = $_SESSION['upload_message'];
    unset($_SESSION['upload_message']);
}

require_once __DIR__ . '/../config/config.php'; 
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

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

// --- STAGE 1: UPLOAD & PREVIEW ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $transactionMonth = $_POST['transaction_month'] ?? '';
    $transactionYear = $_POST['transaction_year'] ?? '';
    $file = $_FILES['excel_file'];

    // Validation: If month is selected, year is required
    if (!empty($transactionMonth) && empty($transactionYear)) {
        $uploadMessage = '<div class="error">Transaction Year is required when Transaction Month is selected.</div>';
    } else {
        // Normalize file array
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
            if ($singleFile['error'] !== UPLOAD_ERR_OK) continue;
            
            $ext = strtolower(pathinfo($singleFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xls', 'xlsx'])) continue;

            $tempDir = 'uploads/temp/';
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            
            $currentTempPath = $tempDir . uniqid() . '_' . $singleFile['name'];
            move_uploaded_file($singleFile['tmp_name'], $currentTempPath);
            $tempFilePaths[] = $currentTempPath;

            try {
                $spreadsheet = IOFactory::load($currentTempPath);
                $previewTable .= '<h4 class="file-name">Preview: ' . htmlspecialchars($singleFile['name']) . '</h4>';
                $previewTable .= '<div class="table-container">';

                foreach ($spreadsheet->getAllSheets() as $sheet) {
                    $data = $sheet->toArray(null, true, true, true);
                    if (empty($data)) continue;

                    $previewTable .= '<table class="excel-preview" style="margin-bottom: 20px; width: 100%; border-collapse: collapse;">';

                    // 1. Find the highest row index with 'NET Income'
                    $cutoffRow = count($data);
                    foreach ($data as $rowIndex => $row) {
                        foreach ($row as $cell) {
                            if (stripos(trim((string)$cell), 'NET Income') !== false) {
                                $cutoffRow = $rowIndex;
                                break; 
                            }
                        }
                    }

                    // 2. Find columns to align right (Amount, %) from Row 2
                    $alignRightCols = [];
                    if (isset($data[2])) {
                        foreach ($data[2] as $colKey => $cellValue) {
                            $val = trim((string)$cellValue);
                            if (stripos($val, 'Amount') !== false || $val === '%') {
                                $alignRightCols[$colKey] = true;
                            }
                        }
                    }

                    foreach ($data as $rowIndex => $row) {
                        if ($rowIndex > $cutoffRow) break;
                        $rowClass = ($rowIndex <= 2) ? 'sticky-row-' . $rowIndex : '';
                        $previewTable .= '<tr class="' . $rowClass . '">';
                        foreach ($row as $colKey => $cell) {
                            $tag = ($rowIndex === 1) ? 'th' : 'td';
                            $style = isset($alignRightCols[$colKey]) ? 'text-align: right;' : '';
                            $previewTable .= "<$tag style='$style'>" . htmlspecialchars($cell ?? '') . "</$tag>";
                        }
                        $previewTable .= '</tr>';
                    }
                    $previewTable .= '</table>';
                }
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

    //Populate both columns appropriately ---
    $dbTransactionMonth = null;
    $dbTransactionYear = null;

    if (!empty($transactionYear)) {
        // Always populate transaction_year when year is selected
        $dbTransactionYear = $transactionYear;
        
        // If month is also selected, populate transaction_month as YYYY-MM-01
        if (!empty($transactionMonth)) {
            $dbTransactionMonth = $transactionYear . '-' . str_pad($transactionMonth, 2, '0', STR_PAD_LEFT) . '-01';
        }
    }
    // If no year selected at all, both remain null (form validation prevents this)

if (!empty($paths)) {
    $conn->begin_transaction();
    $lockedRegions = [];
    $existingRegions = [];
    $voidedGroups = []; // Track voided groups to avoid re-voiding or voiding new inserts
    $checkedGroups = []; // Track checked groups to avoid flagging own inserts as duplicates
    $insertCount = 0;

    try {
        // Updated INSERT query with branch_id column
        $stmt = $conn->prepare("
    INSERT INTO comparative_report 
    (gl_code, gl_description, amount, percentage, region, area, mainzone, zone, region_code,
     transaction_type, transaction_month, transaction_year, uploaded_by, branch_name, 
     cost_center, bp_cost_center, branch_id, uploaded_date) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
        
        $checkStatusStmt = $conn->prepare("SELECT status FROM comparative_report WHERE region = ? AND mainzone <=> ? AND zone <=> ? AND transaction_type = ? AND transaction_month <=> ? AND status_void IS NULL LIMIT 1");

        // Prepare Void Statement
        $voidStmt = $conn->prepare("UPDATE comparative_report SET status = 'Locked', locked_by = ?, locked_date = ?, status_void = 'Void', voided_by = ?, voided_at = ? WHERE region = ? AND mainzone <=> ? AND zone <=> ? AND transaction_type = ? AND transaction_month <=> ? AND status_void IS NULL");

        foreach ($paths as $path) {
            if (!file_exists($path)) continue;
            $spreadsheet = IOFactory::load($path);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            if (empty($rows)) continue;

            $maxCols = count($rows[0] ?? []);

            for ($colStart = 0; $colStart < $maxCols; $colStart += 6) {
                
                $headerString = $rows[0][$colStart] ?? '';
                $region = $area = $branch_name = $cost_center = $bp_cost_center = $mainzone = $zone = null;
                $region_code = null;
                $transaction_type = null;
                $branch_id = null; // Initialize branch_id

                // --- OLD FORMAT: Region | Area ---
                if (preg_match('/Region\s*:\s*(.*?)\s*\|\s*Area\s*:\s*(.*)/i', $headerString, $matches)) {
                    $region = trim($matches[1]);
                    $area   = strtoupper(trim($matches[2]));
                    $transaction_type = 'Branch';
                    
                    // Lookup mainzone and zone from branch_profile using region
                    if (!empty($region)) {
                        $lookup_sql = "SELECT mainzone, zone FROM masterdata.branch_profile WHERE region = ? LIMIT 1";
                        $lookup_stmt = $conn->prepare($lookup_sql);
                        if ($lookup_stmt) {
                            $lookup_mainzone = $lookup_zone = null;
                            $lookup_stmt->bind_param("s", $region);
                            $lookup_stmt->execute();
                            $lookup_stmt->bind_result($lookup_mainzone, $lookup_zone);
                            if ($lookup_stmt->fetch()) {
                                $mainzone = $lookup_mainzone;
                                $zone = $lookup_zone;
                            }
                            $lookup_stmt->close();
                        }
                    }

                // --- NEW FORMAT: Branch | Cost Center ---
} elseif (preg_match('/Branch\s*:\s*(.*?)\s*\|\s*Cost\s*Center\s*:\s*(.*)/i', $headerString, $matches)) {
    $branch_name = trim($matches[1]);
    $cc_raw      = trim($matches[2]);
    $transaction_type = 'Showroom';

    // Validate cost center is numeric
    if (!preg_match('/^\d+$/', $cc_raw)) {
        continue; // skip invalid cost center
    }

    $cost_center = $cc_raw;
    $cc_padded   = str_pad($cc_raw, 3, '0', STR_PAD_LEFT);
    $bp_cost_center = '0001-' . $cc_padded;

    // HARDCODED LOGIC FOR COST CENTER 844
    if ($cc_raw == 844) {
        if (strpos($branch_name, 'ML SM CITY NAGA') !== false) {
            // Case: ML SM CITY NAGA
            $region = 'Camacat Region';
            $area = 'A';
            $mainzone = 'LNCR';
            $zone = 'LZN';
            $branch_id = 2160;
        } elseif (strpos($branch_name, 'ML IL CORSO CEBU JEWELLERS') !== false) {
            // Case: ML IL CORSO CEBU JEWELLERS
            $region = 'Cebu Central Region A';
            $area = 'A';
            $mainzone = 'VISMIN';
            $zone = 'VIS';
            $branch_id = 5001;
        } else {
            // Fallback to database lookup for other branch names with cc 844
            $lookup_sql = "SELECT region, area, mainzone, zone, branch_id FROM masterdata.branch_profile WHERE cost_center = ? LIMIT 1";
            $lookup_stmt = $conn->prepare($lookup_sql);
            if ($lookup_stmt) {
                $lookup_region = $lookup_area = $lookup_mainzone = $lookup_zone = $lookup_branch_id = null;
                $lookup_stmt->bind_param("s", $bp_cost_center);
                $lookup_stmt->execute();
                $lookup_stmt->bind_result($lookup_region, $lookup_area, $lookup_mainzone, $lookup_zone, $lookup_branch_id);
                if ($lookup_stmt->fetch()) {
                    $region = $lookup_region;
                    $area   = strtoupper($lookup_area ?? '');
                    $mainzone = $lookup_mainzone;
                    $zone   = $lookup_zone;
                    $branch_id = $lookup_branch_id;
                }
                $lookup_stmt->close();
            }
        }
    } else {
        // For all other cost centers, use database lookup
        $lookup_sql = "SELECT region, area, mainzone, zone, branch_id FROM masterdata.branch_profile WHERE cost_center = ? LIMIT 1";
        $lookup_stmt = $conn->prepare($lookup_sql);
        if ($lookup_stmt) {
            $lookup_region = $lookup_area = $lookup_mainzone = $lookup_zone = $lookup_branch_id = null;
            $lookup_stmt->bind_param("s", $bp_cost_center);
            $lookup_stmt->execute();
            $lookup_stmt->bind_result($lookup_region, $lookup_area, $lookup_mainzone, $lookup_zone, $lookup_branch_id);
            if ($lookup_stmt->fetch()) {
                $region = $lookup_region;
                $area   = strtoupper($lookup_area ?? '');
                $mainzone = $lookup_mainzone;
                $zone   = $lookup_zone;
                $branch_id = $lookup_branch_id;
            }
            $lookup_stmt->close();
        }
    }
}

                // Look up region_code based on region value (region_description)
                if (!empty($region)) {
                    $rc_sql = "SELECT region_code FROM masterdata.region_masterfile WHERE region_description = ? LIMIT 1";
                    $rc_stmt = $conn->prepare($rc_sql);
                    if ($rc_stmt) {
                        $found_region_code = null;
                        $rc_stmt->bind_param("s", $region);
                        $rc_stmt->execute();
                        $rc_stmt->bind_result($found_region_code);
                        if ($rc_stmt->fetch()) {
                            $region_code = $found_region_code;
                        }
                        $rc_stmt->close();
                    }
                }

                // Skip this column group if we don't have valid region & area
                if ($region === null || $area === null) {
                    continue;
                }
                
                // Create a unique key for this group
                $groupKey = $region . '|' . ($mainzone ?? 'NULL') . '|' . ($zone ?? 'NULL') . '|' . $transaction_type . '|' . ($dbTransactionMonth ?? 'NULL');

                // Check status only if not already checked in this batch
                if (!in_array($groupKey, $checkedGroups)) {
                    $existingStatus = null;
                    $checkStatusStmt->bind_param("sssss", $region, $mainzone, $zone, $transaction_type, $dbTransactionMonth);
                    $checkStatusStmt->execute();
                    $checkStatusStmt->store_result();
                    
                    if ($checkStatusStmt->num_rows > 0) {
                        $checkStatusStmt->bind_result($existingStatus);
                        $checkStatusStmt->fetch();
                        
                        if ($existingStatus === 'Locked') {
                            $lockedRegions[] = $region;
                        } elseif (!$forceInsert) {
                            $existingRegions[] = $region;
                        }
                    }
                    $checkedGroups[] = $groupKey;
                }
                $checkStatusStmt->free_result();

                if (in_array($region, $lockedRegions) || (in_array($region, $existingRegions) && !$forceInsert)) {
                    continue;
                }

                // Handle Force Insert (Void previous records)
                if ($forceInsert) {
                    if (!in_array($groupKey, $voidedGroups)) {
                        $voidStmt->bind_param("sssssssss", 
                            $uploadedBy, $uploadedDate, // locked_by, locked_date
                            $uploadedBy, $uploadedDate, // voided_by, voided_at
                            $region, $mainzone, $zone, $transaction_type, $dbTransactionMonth // WHERE clause
                        );
                        $voidStmt->execute();
                        
                        // Mark as voided so we don't void the records we are about to insert if the loop encounters this region again
                        $voidedGroups[] = $groupKey;
                    }
                }

                for ($rowIndex = 2; $rowIndex < count($rows); $rowIndex++) {
                    $row = $rows[$rowIndex] ?? [];
                    
                    $glCode = trim($row[$colStart + 1] ?? '');
                    $description = trim($row[$colStart + 2] ?? '');
                    
                    // Skip if glCode is empty
                    if (empty($glCode)) {
                        continue;
                    }
                    
                    // Check if glCode is numeric (including decimals)
                    if (!is_numeric($glCode)) {
                        continue; // Skip this row if glCode is not a number
                    }
                    
                    $amount = (float)str_replace([',', ' '], '', $row[$colStart + 3] ?? '0');
                    
                    $percentage = trim($row[$colStart + 4] ?? '0');
                    
                    // You might also want to validate description is not empty
                    if (empty($description)) {
                        continue;
                    }
                    
                    // Bind parameters - including branch_id
                 $stmt->bind_param("ssdsssssssssssssss", 
    $glCode, $description, $amount, $percentage, $region, $area, 
    $mainzone, $zone, $region_code, $transaction_type, $dbTransactionMonth,
    $dbTransactionYear, $uploadedBy, $branch_name, $cost_center, 
    $bp_cost_center, $branch_id, $uploadedDate
);
                    
                    if ($stmt->execute()) $insertCount++;
                }
            }
        }
        } // End foreach paths

        $stmt->close();
        $checkStatusStmt->close();
        $voidStmt->close();
            
        // Prepare month display string
        $dateObj = ($dbTransactionMonth) ? DateTime::createFromFormat('Y-m-d', $dbTransactionMonth) : false;
        $monthDisplay = $dateObj ? $dateObj->format('F Y') : ($transactionMonth ? date('F', mktime(0, 0, 0, (int)$transactionMonth, 1)) . ' ' . $transactionYear : $transactionYear);

        if (!empty($lockedRegions)) {
            $conn->rollback();
            $lockedRegions = array_unique($lockedRegions);
            $regionList = implode(', ', $lockedRegions);
            $_SESSION['upload_message'] = "<div class='error'>The following regions are locked for {$monthDisplay}: {$regionList}. Please unlock to upload.</div>";
            foreach ($paths as $p) { if (file_exists($p)) unlink($p); }
            header("Location: comparative_report.php");
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
            foreach ($paths as $p) { if (file_exists($p)) unlink($p); }
            
            $periodDisplay = '';
            if (!empty($transactionMonth) && !empty($transactionYear)) {
                $monthName = date('F', mktime(0, 0, 0, $transactionMonth, 1));
                $periodDisplay = "$monthName $transactionYear";
            } elseif (!empty($transactionYear)) {
                $periodDisplay = $transactionYear;
            }
            
            $_SESSION['upload_message'] = "<div class='success'>✅ Success! Processed all sheets. $insertCount total records inserted for $periodDisplay.</div>";
            header("Location: comparative_report.php");
            exit;
        }

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['upload_message'] = '<div class="error">Database Error: ' . $e->getMessage() . '</div>';
            header("Location: comparative_report.php");
            exit;
        }
    } else {
        $_SESSION['upload_message'] = '<div class="error">Session expired or file missing.</div>';
        header("Location: comparative_report.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <link rel="stylesheet" href="css/comparative.css?v=<?= time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

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
            <h3>Upload Raw Excel Report (Per Zone)</h3>

            <form class="upload-form" method="post" enctype="multipart/form-data" id="uploadForm">
                <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                    <div style="display: flex; flex-direction: column;">
                        <label for="transaction_month" style="font-weight: 600; margin-bottom: 5px;">Transaction Month:</label>
                        <select name="transaction_month" id="transaction_month" 
                                style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 8px; width: 160px;">
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
                        <select name="transaction_year" id="transaction_year" 
                                style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 8px; width: 160px;">
                            <option value="">-- Select Year --</option>
                            <?php 
                                $currentYear = date("Y");
                                for($i = $currentYear; $i >= $currentYear - 5; $i--): 
                            ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div style="display: flex; flex-direction: column;">
                        <label style="font-weight: 600; margin-bottom: 5px;">Choose Excel File:</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="file" name="excel_file[]" accept=".xls,.xlsx" required multiple
                                   style="padding: 9px; border: 1px solid #cbd5e0; border-radius: 8px;">
                            <button type="submit" style="margin-left: 0;"><i class="fa-solid fa-eye"></i> Preview</button>
                            <a href="report.php" class="btn-generate"><i class="fa-regular fa-file"></i> View Report</a>
                            <a href="comparative_report.php" class="btn-reset"><i class="fa-solid fa-rotate"></i> Refresh</a>

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

    <!-- Confirmation Modal -->
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
            window.location.href = 'comparative_report.php';
        }

        function closeMessageModal() {
            const modal = document.getElementById('messageModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // Close modal when clicking outside
        // window.onclick = function(event) {
        //     const modal = document.getElementById('confirmModal');
        //     if (event.target == modal) {
        //         closeModal();
        //     }
        // }

        document.addEventListener("DOMContentLoaded", function () {
            const messageModal = document.getElementById('messageModal');
   

            // Form validation
            const form = document.getElementById('uploadForm');
            const monthSelect = document.getElementById('transaction_month');
            const yearSelect = document.getElementById('transaction_year');
            const yearRequired = document.getElementById('year_required');

            // Show/hide required indicator
            monthSelect.addEventListener('change', function() {
                if (this.value) {
                    yearRequired.style.display = 'inline';
                    yearSelect.setAttribute('required', 'required');
                } else {
                    yearRequired.style.display = 'none';
                    yearSelect.removeAttribute('required');
                }
            });

            // Validate on form submit
            form.addEventListener('submit', function(e) {
                const monthValue = monthSelect.value;
                const yearValue = yearSelect.value;

                // If month is selected but year is not
                if (monthValue && !yearValue) {
                    e.preventDefault();
                    alert('Transaction Year is required when Transaction Month is selected.');
                    yearSelect.focus();
                    return false;
                }

                // At least year must be selected if month is not
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
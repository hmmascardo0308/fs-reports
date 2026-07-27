<?php
session_start();

// Session timeout after 30 minutes of inactivity
$inactivity_timeout = 1800; // 30 seconds for testing, change to 1800 for production

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactivity_timeout) {
    // Clear all session data
    session_unset();
    session_destroy();
    session_start();
}

// Update last activity time
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../config/config.php'; 
require_once '../vendor/autoload.php';

// Check if reset is requested
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['parsed_data']);
    unset($_SESSION['uploaded_headers']);
    unset($_SESSION['total_rows']);
    unset($_SESSION['file_name']);
    unset($_SESSION['success_message']);
    unset($_SESSION['error_message']);
    unset($_SESSION['summary_data']);
    unset($_SESSION['column_mapping']);
    unset($_SESSION['remarks_data']);
    unset($_SESSION['skipped_data']);
    unset($_SESSION['last_activity']);
    header("Location: upload_raw_data.php");
    exit;
}

// Clear session when coming from another page (optional)
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $referer = $_SERVER['HTTP_REFERER'];
    // Check if referer is from a different page (not upload_raw_data.php)
    if (strpos($referer, 'upload_raw_data.php') === false && !isset($_POST['file_upload']) && !isset($_GET['view'])) {
        // Only clear if data exists and we're not in the middle of an upload
        if (isset($_SESSION['parsed_data'])) {
            // Don't clear immediately, let the user see the data
            // But mark it for clearing on next visit
            $_SESSION['clear_on_next_load'] = true;
        }
    }
}

// Check if we need to clear data - ONLY when navigating to a different page
if (isset($_SESSION['clear_on_next_load']) && $_SESSION['clear_on_next_load'] === true) {
    // Check if we're still on the same page (pagination or view change)
    $current_page = isset($_GET['page']) ? $_GET['page'] : '';
    $current_view = isset($_GET['view']) ? $_GET['view'] : '';
    
    // Only clear if not on upload_raw_data.php with view/page parameters
    if (empty($current_page) && empty($current_view)) {
        unset($_SESSION['parsed_data']);
        unset($_SESSION['uploaded_headers']);
        unset($_SESSION['total_rows']);
        unset($_SESSION['file_name']);
        unset($_SESSION['success_message']);
        unset($_SESSION['error_message']);
        unset($_SESSION['summary_data']);
        unset($_SESSION['column_mapping']);
        unset($_SESSION['remarks_data']);
        unset($_SESSION['skipped_data']);
        unset($_SESSION['clear_on_next_load']);
    } else {
        // We're still on the same page, don't clear
        unset($_SESSION['clear_on_next_load']);
    }
}

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

// Hardcoded column headers for display only
$display_headers = [
    'Date', 'Zone', 'Region', 'Area', 'Branch Name', 
    'Branch ID', 'GL Code', 'GL Description', 'Total Amount'
];

// Fixed column positions (0-indexed)
// A1=0, B1=1, C1=2, D1=3, E1=4, F1=5, G1=6, H1=7, I1=8
define('COL_DATE', 0);
define('COL_ZONE', 1);
define('COL_REGION', 2);
define('COL_AREA', 3);
define('COL_BRANCH', 4);
define('COL_BRANCH_ID', 5);
define('COL_CODE', 6);
define('COL_DESCRIPTION', 7);
define('COL_AMOUNT', 8);

// Helper: normalize a raw amount string into a rounded float.
// Centralizing this ensures every view (Detailed, Summary, Remarks)
// parses and rounds amounts identically, so totals never drift apart
// due to floating-point summation order.
function parseAmount(string $amount_str): float
{
    $amount_str = trim($amount_str ?? '0');
    $amount_str = str_replace(['₱', 'PHP', '$', ',', ' '], '', $amount_str);
    return round(floatval($amount_str), 2);
}
// Function to get branch type from masterdata
function getBranchType(string $branch_id): string
{
    global $conn; // Use MySQLi connection
    static $branch_cache = [];

    if (empty($branch_id)) {
        return 'Unknown';
    }

    // Check cache first
    if (isset($branch_cache[$branch_id])) {
        return $branch_cache[$branch_id];
    }

    try {
        // Query the branch_profile table
        $query = "SELECT branch_type FROM masterdata.branch_profile WHERE branch_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $branch_type = $row['branch_type'] ?? 'Unknown';
            $branch_cache[$branch_id] = $branch_type;
            return $branch_type;
        }

        $branch_cache[$branch_id] = 'Unknown';
        return 'Unknown';
    } catch (Exception $e) {
        error_log("Error fetching branch type for ID $branch_id: " . $e->getMessage());
        return 'Unknown';
    }
}

// Function to clean malformed CSV fields with backslash and quotes
function cleanCsvField(string $field): string
{
    // Remove extra quotes and backslashes
    $field = trim($field);
    // Remove leading/trailing quotes if present
    $field = preg_replace('/^"|"$/', '', $field);
    // Remove backslashes before quotes (fix for \" pattern)
    $field = str_replace('\\"', '"', $field);
    return $field;
}

// Function to parse CSV with better handling of quoted fields
function parseCsvFile(string $filePath): array
{
    $rows = [];
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        // Detect delimiter (comma or semicolon)
        $firstLine = fgets($handle);
        rewind($handle);
        
        // Check if delimiter is comma or semicolon
        $delimiter = ',';
        if (strpos($firstLine, ';') !== false && strpos($firstLine, ',') === false) {
            $delimiter = ';';
        }
        
        // Read CSV with proper handling.
        // NOTE: the escape parameter is intentionally set to "\0" (a byte that
        // won't appear in the file) instead of '\\'. PHP's backslash-escape
        // handling in fgetcsv() is non-standard and can silently corrupt a row
        // when a field contains a literal quote that isn't meant as CSV
        // escaping -- e.g. a description like `Standard 16"` (an inch mark).
        // Disabling it means a quote is only ever treated specially when it
        // truly opens/closes a quoted field (RFC4180 behavior), so a stray "
        // in the middle of a field no longer shifts or swallows later columns.
        while (($data = fgetcsv($handle, 0, $delimiter, '"', "\0")) !== FALSE) {
            // Clean each field
            $cleaned = array_map('cleanCsvField', $data);
            $rows[] = $cleaned;
        }
        fclose($handle);
    }
    return $rows;
}

// Function to clean a row and ensure it has the right number of columns
function cleanRowData(array $row, int $expectedColumns = 9): array
{
    // If row has fewer columns, pad with empty strings
    while (count($row) < $expectedColumns) {
        $row[] = '';
    }
    // If row has more columns, trim to expected count
    if (count($row) > $expectedColumns) {
        $row = array_slice($row, 0, $expectedColumns);
    }
    return $row;
}

$parsed_data = [];
$uploaded_headers = [];
$error_message = '';
$success_message = '';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'raw'; // raw, summary, or remarks
$summary_data = [];
$remarks_data = [];
$skipped_data = [];
$column_mapping = [];

// Pagination variables
$rows_per_page = 50;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page);

// Handle File Processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload'])) {
    $file = $_FILES['file_upload'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $file['tmp_name'];
        $file_name = $file['name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Only allow CSV files
        if ($file_ext === 'csv') {
            try {
                $rows = [];
                
                // Use custom CSV parser
                $rows = parseCsvFile($file_tmp);

                if (!empty($rows)) {
                    // Get headers from first row
                    $first_row = array_shift($rows);
                    // Clean headers
                    $uploaded_headers = array_map('trim', array_filter($first_row, function($val) {
                        return !empty(trim($val));
                    }));
                    $uploaded_headers = array_values($uploaded_headers);
                    
                    // Ensure headers match expected count
                    if (count($uploaded_headers) < 9) {
                        // Pad headers if needed
                        while (count($uploaded_headers) < 9) {
                            $uploaded_headers[] = 'Column ' . (count($uploaded_headers) + 1);
                        }
                    }

                    // Fixed column mapping based on position
                    $region_idx = COL_REGION;  // Column C (index 2)
                    $area_idx = COL_AREA;       // Column D (index 3)
                    $code_idx = COL_CODE;       // Column G (index 6)
                    $amount_idx = COL_AMOUNT;   // Column I (index 8)
                    $branch_id_idx = COL_BRANCH_ID; // Column F (index 5)
                    
                    $column_mapping = [
                        'region' => $region_idx,
                        'area' => $area_idx,
                        'code' => $code_idx,
                        'amount' => $amount_idx,
                        'branch_id' => $branch_id_idx,
                        'date' => COL_DATE,
                        'zone' => COL_ZONE,
                        'branch' => COL_BRANCH,
                        'description' => COL_DESCRIPTION
                    ];
                    
                    error_log("Fixed Column Mapping: " . print_r($column_mapping, true));
                    error_log("Headers: " . print_r($uploaded_headers, true));

                    $skipped_rows = 0;
                    $malformed_rows = 0;
                    $processed_rows = 0;
                    $skipped_data = [];

                    foreach ($rows as $row_index => $row) {
                        // Remember the column count BEFORE padding/truncating --
                        // a count that doesn't match 9 is a strong signal that a
                        // stray character (commonly an unescaped ") in the source
                        // file shifted the columns during parsing.
                        $original_col_count = count($row);

                        // Clean and normalize the row
                        $row = cleanRowData($row);
                        
                        // Check if row has any actual data (not just empty strings)
                        $hasData = false;
                        foreach ($row as $cell) {
                            if (!empty(trim($cell))) {
                                $hasData = true;
                                break;
                            }
                        }
                        
                        if (!$hasData) {
                            $skipped_rows++;
                            error_log("Skipping empty row " . ($row_index + 2));
                            $skipped_data[] = [
                                'row_number' => $row_index + 2,
                                'reason' => 'Empty row (no data in any column)',
                                'raw_data' => array_pad(array_slice(array_map('trim', $row), 0, 9), 9, '')
                            ];
                            continue;
                        }
                        
                        // Build row data with proper column mapping
                        $row_data = [];
                        for ($i = 0; $i < 9; $i++) {
                            $row_data[] = isset($row[$i]) ? trim($row[$i]) : '';
                        }
                        
                        // Validate that we have the minimum required data
                        // At minimum, we need Amount (index 8) and Branch ID (index 5)
                        if (empty($row_data[COL_AMOUNT]) && empty($row_data[COL_BRANCH_ID])) {
                            $malformed_rows++;
                            error_log("Skipping malformed row " . ($row_index + 2) . " - missing amount and branch ID: " . print_r($row_data, true));

                            $reason = 'Missing both Amount and Branch ID';
                            if ($original_col_count !== 9) {
                                $reason .= " (row had {$original_col_count} columns instead of 9 -- likely an unescaped \" character in a field, e.g. a description like Standard 16\", shifted the remaining columns)";
                            }

                            $skipped_data[] = [
                                'row_number' => $row_index + 2,
                                'reason' => $reason,
                                'raw_data' => $row_data
                            ];
                            continue;
                        }
                        
                        $parsed_data[] = $row_data;
                        $processed_rows++;
                    }

                    error_log("Total rows from file: " . count($rows));
                    error_log("Processed rows: " . $processed_rows);
                    error_log("Skipped empty rows: " . $skipped_rows);
                    error_log("Malformed rows: " . $malformed_rows);

                    // Build summary data from ALL rows - Group by Region, Area, Code, Branch Type
                    $summary_data = [];
                    $remarks_data = [];
                    
                    foreach ($parsed_data as $row_index => $row) {
                        // Get values using fixed column positions
                        $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
                        $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
                        $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
                        $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
                        $branch_name = isset($row[COL_BRANCH]) ? trim($row[COL_BRANCH]) : '';
                        $date = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
                        // Use the shared helper so every view rounds amounts identically
                        $amount = parseAmount($row[COL_AMOUNT] ?? '0');
                        
                        // Get branch type from masterdata
                        $branch_type = getBranchType($branch_id);
                        
                        // If branch type is Unknown, add to remarks data
                        if ($branch_type === 'Unknown' && !empty($branch_id)) {
                            $key = $region . '|' . $area . '|' . $code . '|' . $branch_id;
                            
                            if (!isset($remarks_data[$key])) {
                                $remarks_data[$key] = [
                                    'region' => $region,
                                    'area' => $area,
                                    'code' => $code,
                                    'branch_id' => $branch_id,
                                    'branch_name' => $branch_name,
                                    'date' => $date,
                                    'total_amount' => 0,
                                    'row_count' => 0,
                                    'transactions' => [] // Store individual transactions for reference
                                ];
                            }
                            
                            $remarks_data[$key]['total_amount'] += $amount;
                            $remarks_data[$key]['row_count']++;
                            $remarks_data[$key]['transactions'][] = [
                                'date' => $date,
                                'branch_name' => $branch_name,
                                'amount' => $amount
                            ];
                        }

                        // Continue with summary data for non-unknown branch types
                        $summary_key = $region . '|' . $area . '|' . $code . '|' . $branch_type;
                        
                        if (!isset($summary_data[$summary_key])) {
                            $summary_data[$summary_key] = [
                                'region' => $region,
                                'area' => $area,
                                'code' => $code,
                                'branch_type' => $branch_type,
                                'branch_total' => 0,
                                'branch_count' => 0,
                                'showroom_total' => 0,
                                'showroom_count' => 0,
                                'total_amount' => 0,
                                'total_count' => 0
                            ];
                        }
                        
                        // Add to appropriate totals based on branch type
                        if (strtolower($branch_type) === 'branch' || $branch_type === 'Branch') {
                            $summary_data[$summary_key]['branch_total'] += $amount;
                            $summary_data[$summary_key]['branch_count']++;
                        } elseif (strtolower($branch_type) === 'showroom' || $branch_type === 'Showroom') {
                            $summary_data[$summary_key]['showroom_total'] += $amount;
                            $summary_data[$summary_key]['showroom_count']++;
                        }
                        
                        // Always add to total
                        $summary_data[$summary_key]['total_amount'] += $amount;
                        $summary_data[$summary_key]['total_count']++;
                    }

                    // Sort summary by Region, then Area, then Code, then Branch Type
                    usort($summary_data, function($a, $b) {
                        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
                        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
                        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
                        return strcmp($a['branch_type'], $b['branch_type']);
                    });
                    
                    // Sort remarks data by Region, Area, Code
                    usort($remarks_data, function($a, $b) {
                        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
                        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
                        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
                        return strcmp($a['branch_id'], $b['branch_id']);
                    });

                    $_SESSION['parsed_data'] = $parsed_data;
                    $_SESSION['uploaded_headers'] = $uploaded_headers;
                    $_SESSION['total_rows'] = count($parsed_data);
                    $_SESSION['file_name'] = $file_name;
                    $_SESSION['summary_data'] = $summary_data;
                    $_SESSION['remarks_data'] = $remarks_data;
                    $_SESSION['skipped_data'] = $skipped_data;
                    $_SESSION['column_mapping'] = $column_mapping;
                    
                    // Show debug info
                    $debug_info = "Fixed column mapping: Region=Column C (index 2), Area=Column D (index 3), Code=Column G (index 6), Amount=Column I (index 8), Branch ID=Column F (index 5)";
                    $unknown_count = count($remarks_data);
                    $skipped_count = count($skipped_data);
                    $_SESSION['success_message'] = "File <strong>" . htmlspecialchars($file_name) . "</strong> parsed successfully! Previewing " . count($parsed_data) . " rows. Found <strong>$unknown_count</strong> unknown branch types" . ($skipped_count > 0 ? " and <strong>$skipped_count</strong> skipped rows" : "") . ". " . $debug_info;

                    $current_page = 1;
                    $success_message = $_SESSION['success_message'];
                } else {
                    $error_message = "The uploaded file appears to be empty.";
                    $_SESSION['error_message'] = $error_message;
                }
            } catch (Exception $e) {
                $error_message = "Error parsing file: " . $e->getMessage();
                $_SESSION['error_message'] = $error_message;
                error_log("File parsing error: " . $e->getMessage());
            }
        } else {
            $error_message = "Invalid file type. Please upload a .csv file only.";
            $_SESSION['error_message'] = $error_message;
        }
    } else {
        $error_message = "File upload failed with error code: " . $file['error'];
        $_SESSION['error_message'] = $error_message;
    }
}

// Load data from session if exists
if (isset($_SESSION['parsed_data']) && empty($parsed_data)) {
    $parsed_data = $_SESSION['parsed_data'];
    $uploaded_headers = $_SESSION['uploaded_headers'] ?? [];
    $success_message = $_SESSION['success_message'] ?? '';
    $error_message = $_SESSION['error_message'] ?? '';
    $summary_data = $_SESSION['summary_data'] ?? [];
    $remarks_data = $_SESSION['remarks_data'] ?? [];
    $skipped_data = $_SESSION['skipped_data'] ?? [];
    $column_mapping = $_SESSION['column_mapping'] ?? [];
}

// If summary_data has old structure (without branch keys), regenerate it
if (!empty($summary_data) && !isset($summary_data[0]['branch_total'])) {
    // Regenerate summary data with branch breakdown
    $new_summary_data = [];
    foreach ($parsed_data as $row) {
        $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
        $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
        $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
        $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
        $branch_type = getBranchType($branch_id);
        
        // Use the shared helper so this legacy-regeneration path matches the main one
        $amount = parseAmount($row[COL_AMOUNT] ?? '0');

        $key = $region . '|' . $area . '|' . $code . '|' . $branch_type;
        
        if (!isset($new_summary_data[$key])) {
            $new_summary_data[$key] = [
                'region' => $region,
                'area' => $area,
                'code' => $code,
                'branch_type' => $branch_type,
                'branch_total' => 0,
                'branch_count' => 0,
                'showroom_total' => 0,
                'showroom_count' => 0,
                'total_amount' => 0,
                'total_count' => 0
            ];
        }
        
        if (strtolower($branch_type) === 'branch' || $branch_type === 'Branch') {
            $new_summary_data[$key]['branch_total'] += $amount;
            $new_summary_data[$key]['branch_count']++;
        } elseif (strtolower($branch_type) === 'showroom' || $branch_type === 'Showroom') {
            $new_summary_data[$key]['showroom_total'] += $amount;
            $new_summary_data[$key]['showroom_count']++;
        }
        
        $new_summary_data[$key]['total_amount'] += $amount;
        $new_summary_data[$key]['total_count']++;
    }
    
    usort($new_summary_data, function($a, $b) {
        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
        return strcmp($a['branch_type'], $b['branch_type']);
    });
    
    $summary_data = $new_summary_data;
    $_SESSION['summary_data'] = $summary_data;
}

// Calculate pagination for raw data
$total_rows = count($parsed_data);
$total_pages = ceil($total_rows / $rows_per_page);
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $rows_per_page;
$page_rows = array_slice($parsed_data, $offset, $rows_per_page);

// For summary view, we want to show all data without pagination
$summary_total_rows = count($summary_data);

// Calculate grand total for raw data view (single pass over parsed_data,
// using the same rounding helper as every other total in this page)
$grand_total_amount = 0;
foreach ($parsed_data as $row) {
    $grand_total_amount += parseAmount($row[COL_AMOUNT] ?? '0');
}

// Direct branch/showroom/overall breakdown computed straight from parsed_data
// in a single pass -- this is what the Summary view's "OVERALL GRAND TOTAL"
// row now uses instead of re-summing already-aggregated region subtotals.
// Because it walks the same rows, in the same order, with the same rounding
// as $grand_total_amount above, its Total will always exactly equal the
// Detailed view's Grand Total -- no floating-point drift possible.
$grand_branch_total_direct = 0;
$grand_showroom_total_direct = 0;
foreach ($parsed_data as $row) {
    $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
    $branch_type = getBranchType($branch_id);
    $amount = parseAmount($row[COL_AMOUNT] ?? '0');

    if (strtolower($branch_type) === 'branch') {
        $grand_branch_total_direct += $amount;
    } elseif (strtolower($branch_type) === 'showroom') {
        $grand_showroom_total_direct += $amount;
    }
}

// Clear session messages after displaying
if (isset($_SESSION['success_message']) && empty($_POST)) {
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message']) && empty($_POST)) {
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Data Upload</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/upload_raw.css?v=<?= time(); ?>">
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
            <div class="upload-container">
                <h2>Upload Raw Data File</h2>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger" id="errorAlert">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success" id="successAlert">
                        <i class="fa-solid fa-circle-check"></i> <?= $success_message ?>
                    </div>
                <?php endif; ?>

                <!-- Drag & Drop Upload Form -->
                <form id="uploadForm" action="" method="POST" enctype="multipart/form-data">
                    <div class="drop-zone" id="dropZone">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p><strong>Drag & drop your CSV file here</strong></p>
                        <p style="font-size: 13px; color: #94a3b8;">or click to browse from your computer (CSV files only)</p>
                        <span id="file-name-display" style="margin-top: 10px; display: block; font-weight: 600; color: #2563eb;"></span>
                        <input type="file" name="file_upload" id="fileInput" accept=".csv" required>
                    </div>
                    <div class="button-group">
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="fa-solid fa-magnifying-glass"></i> Preview Data
                        </button>
                        <a href="?reset=1" class="btn-reset" id="resetBtn">
                            <i class="fa-solid fa-rotate"></i> Reset
                        </a>
                    </div>
                </form>

                <!-- View Toggle Buttons -->
                <?php if (!empty($parsed_data)): ?>
                <div class="view-toggle">
                    <span class="toggle-label"><i class="fa-solid fa-eye"></i> View Mode:</span>
                    <a href="?view=raw&page=<?= $current_page ?>" class="btn-toggle <?= $view_mode === 'raw' ? 'active' : '' ?>">
                        <i class="fa-solid fa-table"></i> Detailed
                    </a>
                    <a href="?view=summary" class="btn-toggle <?= $view_mode === 'summary' ? 'active' : '' ?>">
                        <i class="fa-solid fa-chart-pie"></i> Summary (Region & Area)
                    </a>
                    <a href="?view=remarks" class="btn-toggle remarks-tab <?= $view_mode === 'remarks' ? 'active' : '' ?>">
                        <i class="fa-solid fa-triangle-exclamation"></i> Remarks 
                        <?php if (!empty($remarks_data)): ?>
                            <span class="badge" style="background: #fec7c7; color: #ff0000; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                                <?= count($remarks_data) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <a href="?view=skipped" class="btn-toggle skipped-tab <?= $view_mode === 'skipped' ? 'active' : '' ?>">
                        <i class="fa-solid fa-ban"></i> Skipped Rows
                        <?php if (!empty($skipped_data)): ?>
                            <span class="badge" style="background: #fde3b0; color: #b45309; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                                <?= count($skipped_data) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endif; ?>

                <!-- Data Preview -->
                <div id="dataPreview" class="<?= empty($parsed_data) ? 'hidden' : '' ?>">
                    
                    <?php if ($view_mode === 'raw' && !empty($parsed_data)): ?>
                        <!-- RAW DATA VIEW -->
                        <div class="summary-stats">
                            <span class="stat-item"><i class="fa-solid fa-file-lines"></i> <strong><?= $total_rows ?></strong> total rows</span>
                            <span class="stat-item"><i class="fa-solid fa-columns"></i> <strong><?= count($display_headers) ?></strong> columns</span>
                            <span class="stat-item"><i class="fa-solid fa-file"></i> File: <strong><?= htmlspecialchars($_SESSION['file_name'] ?? '') ?></strong></span>
                            <span class="stat-item"><i class="fa-solid fa-calculator"></i> Grand Total: <strong class="grand-total-value">₱<?= number_format($grand_total_amount, 2) ?></strong></span>
                        </div>

                        <div class="table-wrapper">
                            <table class="preview-table">
                                <thead>
                                    <tr>
                                        <th class="row-number">#</th>
                                        <?php foreach ($display_headers as $col_header): ?>
                                            <th>
                                                <?= htmlspecialchars($col_header) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $row_counter = $offset + 1;
                                    foreach ($page_rows as $row): 
                                    ?>
                                        <tr>
                                            <td class="row-number"><?= $row_counter ?></td>
                                            <?php foreach ($row as $val): ?>
                                                <td><?= htmlspecialchars($val ?? '') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php 
                                        $row_counter++;
                                    endforeach; 
                                    ?>
                                </tbody>
                                <tfoot>
                                    <!-- Grand Total Row -->
                                    <tr class="raw-grand-total">
                                        <td class="row-number"></td>
                                        <?php 
                                        // Empty cells for all columns except the last one (Total Amount)
                                        $total_columns = count($display_headers);
                                        for ($i = 0; $i < $total_columns - 1; $i++): 
                                        ?>
                                            <td></td>
                                        <?php endfor; ?>
                                        <td style="text-align: right; font-weight: 700; color: #dc2626; font-size: 15px;">
                                            ₱<?= number_format($grand_total_amount, 2) ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Raw Pagination Controls - FIXED -->
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Showing <strong><?= $offset + 1 ?></strong> - 
                                <strong><?= min($offset + $rows_per_page, $total_rows) ?></strong> 
                                of <strong><?= $total_rows ?></strong> rows
                            </div>
                            
                            <div class="pagination-controls">
                                <?php if ($current_page > 1): ?>
                                    <a href="?view=raw&page=1" class="pagination-link">
                                        <i class="fa-solid fa-angles-left"></i>
                                    </a>
                                    <a href="?view=raw&page=<?= $current_page - 1 ?>" class="pagination-link">
                                        <i class="fa-solid fa-chevron-left"></i> Previous
                                    </a>
                                <?php else: ?>
                                    <span class="pagination-disabled"><i class="fa-solid fa-angles-left"></i></span>
                                    <span class="pagination-disabled"><i class="fa-solid fa-chevron-left"></i> Previous</span>
                                <?php endif; ?>
                                
                                <span class="page-indicator">Page <?= $current_page ?> of <?= $total_pages ?></span>
                                
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="?view=raw&page=<?= $current_page + 1 ?>" class="pagination-link">
                                        Next <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                    <a href="?view=raw&page=<?= $total_pages ?>" class="pagination-link">
                                        <i class="fa-solid fa-angles-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="pagination-disabled">Next <i class="fa-solid fa-chevron-right"></i></span>
                                    <span class="pagination-disabled"><i class="fa-solid fa-angles-right"></i></span>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($view_mode === 'summary' && !empty($summary_data)): ?>
                        <!-- SUMMARY VIEW - Grouped by Region, Area, Code with Branch Type Breakdown -->
                        <div class="summary-stats">
                            <span class="stat-item"><i class="fa-solid fa-layer-group"></i> <strong><?= count(array_unique(array_column($summary_data, 'region'))) ?></strong> regions</span>
                            <span class="stat-item"><i class="fa-solid fa-cubes"></i> <strong><?= count(array_unique(array_column($summary_data, 'area'))) ?></strong> areas</span>
                            <span class="stat-item"><i class="fa-solid fa-tag"></i> <strong><?= $summary_total_rows ?></strong> unique Region-Area-Code-BranchType combinations</span>
                            <span class="stat-item"><i class="fa-solid fa-file"></i> File: <strong><?= htmlspecialchars($_SESSION['file_name'] ?? '') ?></strong></span>
                        </div>

                        <div class="table-wrapper">
                            <table class="preview-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="width: 12%;">Region</th>
                                        <th style="width: 12%;">Area</th>
                                        <th style="width: 12%;">Code</th>
                                        <th style="width: 10%;">Branch Type</th>
                                        <th style="width: 13%;">Branch Amount</th>
                                        <th style="width: 8%;">Branch Count</th>
                                        <th style="width: 13%;">Showroom Amount</th>
                                        <th style="width: 8%;">Showroom Count</th>
                                        <th style="width: 12%;">Total Amount</th>
                                        <th style="width: 8%;">Total Rows</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (!empty($summary_data)):
                                        // Group data by Region, then Area, then Code
                                        $grouped_data = [];
                                        foreach ($summary_data as $item) {
                                            // Ensure all required keys exist
                                            $region = isset($item['region']) ? $item['region'] : 'Unknown Region';
                                            $area = isset($item['area']) ? $item['area'] : 'Unknown Area';
                                            $code = isset($item['code']) ? $item['code'] : 'Unknown Code';
                                            
                                            if (!isset($grouped_data[$region])) {
                                                $grouped_data[$region] = [];
                                            }
                                            if (!isset($grouped_data[$region][$area])) {
                                                $grouped_data[$region][$area] = [];
                                            }
                                            if (!isset($grouped_data[$region][$area][$code])) {
                                                $grouped_data[$region][$area][$code] = [];
                                            }
                                            $grouped_data[$region][$area][$code][] = $item;
                                        }

                                        // Sort regions
                                        ksort($grouped_data);
                                        
                                        $row_counter = 1;
                                        $grand_total_all = 0;
                                        $grand_branch_total = 0;
                                        $grand_showroom_total = 0;
                                        
                                        foreach ($grouped_data as $region => $areas):
                                            // Sort areas within region
                                            ksort($areas);
                                            
                                            // Calculate region totals
                                            $region_branch_total = 0;
                                            $region_showroom_total = 0;
                                            $region_total = 0;
                                            $region_branch_count = 0;
                                            $region_showroom_count = 0;
                                            $region_total_count = 0;
                                            
                                            foreach ($areas as $area_items) {
                                                foreach ($area_items as $code_items) {
                                                    foreach ($code_items as $item) {
                                                        $region_branch_total += isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                        $region_showroom_total += isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                        $region_total += isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                        $region_branch_count += isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                        $region_showroom_count += isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                        $region_total_count += isset($item['total_count']) ? $item['total_count'] : 0;
                                                    }
                                                }
                                            }
                                    ?>
                                            <!-- Region Header Row -->
                                            <tr class="region-group-header">
                                                <td colspan="11" style="font-size: 16px; color: #0f172a;">
                                                    <i class="fa-solid fa-folder-open"></i> 
                                                    <strong>REGION: <?= htmlspecialchars($region) ?></strong>
                                                    <span style="float: right; font-weight: 600; color: #1e293b;">
                                                        Branch: ₱<?= number_format($region_branch_total, 2) ?> (<?= $region_branch_count ?> rows) | 
                                                        Showroom: ₱<?= number_format($region_showroom_total, 2) ?> (<?= $region_showroom_count ?> rows) | 
                                                        Total: ₱<?= number_format($region_total, 2) ?> (<?= $region_total_count ?> rows)
                                                    </span>
                                                </td>
                                            </tr>
                                            
                                            <?php 
                                            foreach ($areas as $area => $code_items):
                                                // Calculate area totals
                                                $area_branch_total = 0;
                                                $area_showroom_total = 0;
                                                $area_total = 0;
                                                $area_branch_count = 0;
                                                $area_showroom_count = 0;
                                                $area_total_count = 0;
                                                
                                                foreach ($code_items as $items) {
                                                    foreach ($items as $item) {
                                                        $area_branch_total += isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                        $area_showroom_total += isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                        $area_total += isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                        $area_branch_count += isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                        $area_showroom_count += isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                        $area_total_count += isset($item['total_count']) ? $item['total_count'] : 0;
                                                    }
                                                }
                                            ?>
                                                <!-- Area Subtotal Row -->
                                                <tr class="area-subtotal-row">
                                                    <td colspan="11" style="font-weight: 700; color: #0f172a; padding: 8px 15px !important;">
                                                        <i class="fa-solid fa-caret-right"></i> 
                                                        <strong>AREA: <?= htmlspecialchars($area) ?></strong>
                                                        <span style="float: right; font-weight: 600; color: #1e293b;">
                                                            Branch: ₱<?= number_format($area_branch_total, 2) ?> (<?= $area_branch_count ?> rows) | 
                                                            Showroom: ₱<?= number_format($area_showroom_total, 2) ?> (<?= $area_showroom_count ?> rows) | 
                                                            Total: ₱<?= number_format($area_total, 2) ?> (<?= $area_total_count ?> rows)
                                                        </span>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Code Rows for this Area -->
                                                <?php 
                                                ksort($code_items);
                                                foreach ($code_items as $code => $items):
                                                    // Sort items by branch type
                                                    usort($items, function($a, $b) {
                                                        return strcmp($a['branch_type'], $b['branch_type']);
                                                    });
                                                    
                                                    foreach ($items as $idx => $item):
                                                        $branch_type_class = strtolower(isset($item['branch_type']) ? $item['branch_type'] : 'unknown');
                                                        $branch_total = isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                        $branch_count = isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                        $showroom_total = isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                        $showroom_count = isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                        $total_amount = isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                        $total_count = isset($item['total_count']) ? $item['total_count'] : 0;
                                                        
                                                        // Check if amounts are negative for styling
                                                        $branch_negative = $branch_total < 0;
                                                        $showroom_negative = $showroom_total < 0;
                                                        $total_negative = $total_amount < 0;
                                                ?>
                                                    <tr class="code-row">
                                                        <td style="text-align: center; color: #94a3b8; font-weight: 400;"><?= $row_counter ?></td>
                                                        <td></td>
                                                        <td></td>
                                                        <td style="padding-left: 30px;">
                                                            <span class="code-value"><?= htmlspecialchars($code) ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="branch-type-badge <?= $branch_type_class ?>">
                                                                <?= htmlspecialchars($item['branch_type'] ?? 'Unknown') ?>
                                                            </span>
                                                        </td>
                                                        <td class="amount-column subtotal-amount-branch <?= $branch_negative ? 'negative-amount-branch' : '' ?>">
                                                            <?= ($branch_total != 0) ? '₱' . number_format($branch_total, 2) : '-' ?>
                                                        </td>
                                                        <td class="count-column">
                                                            <?= $branch_count > 0 ? $branch_count : '-' ?>
                                                        </td>
                                                        <td class="amount-column subtotal-amount-showroom <?= $showroom_negative ? 'negative-amount-showroom' : '' ?>">
                                                            <?= ($showroom_total != 0) ? '₱' . number_format($showroom_total, 2) : '-' ?>
                                                        </td>
                                                        <td class="count-column">
                                                            <?= $showroom_count > 0 ? $showroom_count : '-' ?>
                                                        </td>
                                                        <td class="amount-column <?= $total_negative ? 'negative-amount' : '' ?>" style="font-weight: 700; color: #0f172a;">
                                                            ₱<?= number_format($total_amount, 2) ?>
                                                        </td>
                                                        <td class="count-column" style="font-weight: 600;">
                                                            <?= $total_count ?>
                                                        </td>
                                                    </tr>
                                                    <?php 
                                                    $row_counter++;
                                                    endforeach; 
                                                endforeach; 
                                                ?>
                                            <?php 
                                            endforeach; // End areas
                                            
                                            // Region Grand Total
                                            ?>
                                            <tr class="region-grand-total">
                                                <td colspan="11" style="font-size: 15px; padding: 12px 15px !important;">
                                                    <i class="fa-solid fa-calculator"></i> 
                                                    <strong>GRAND TOTAL - <?= htmlspecialchars($region) ?></strong>
                                                    <span style="float: right; font-weight: 800; color: #000;">
                                                        Branch: ₱<?= number_format($region_branch_total, 2) ?> | 
                                                        Showroom: ₱<?= number_format($region_showroom_total, 2) ?> | 
                                                        Total: ₱<?= number_format($region_total, 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <!-- Spacer row -->
                                            <tr style="height: 5px;"><td colspan="11" style="padding: 0; border: none;"></td></tr>
                                            
                                            <?php 
                                            $grand_branch_total += $region_branch_total;
                                            $grand_showroom_total += $region_showroom_total;
                                            $grand_total_all += $region_total;
                                        endforeach; // End regions
                                        
                                        // Overall Grand Total (all regions)
                                        if (count($grouped_data) > 1):
                                        ?>
                                            <tr style="background-color: #ff5656 !important;">
                                                <td colspan="11" style="padding: 15px 15px !important; color: white !important; font-size: 17px; font-weight: 800;">
                                                    <i class="fa-solid fa-crown"></i> 
                                                    OVERALL GRAND TOTAL (All Regions)
                                                    <span style="float: right; font-weight: 800; color: #ffffff;">
                                                        Branch: ₱<?= number_format($grand_branch_total_direct, 2) ?> | 
                                                        Showroom: ₱<?= number_format($grand_showroom_total_direct, 2) ?> | 
                                                        Total: ₱<?= number_format($grand_total_amount, 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php 
                                        endif;
                                    else: 
                                        ?>
                                        <tr>
                                            <td colspan="11" style="text-align: center; padding: 40px; color: #94a3b8;">
                                                <i class="fa-solid fa-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                                No summary data available.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 15px; padding: 10px 15px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <span style="color: #64748b; font-size: 13px;">
                                <i class="fa-solid fa-info-circle"></i> Each code is broken down by Branch Type (Branch vs Showroom).
                            </span>
                        </div>

                    <?php elseif ($view_mode === 'remarks' && !empty($remarks_data)): ?>
                        <!-- REMARKS VIEW - Unknown Branch Types (Display Only) -->
                        <div style="background: #ffebeb; border: 1px solid #ffabab; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-triangle-exclamation" style="color: #ad0000; font-size: 20px;"></i>
                                <div>
                                    <strong style="color: #ff0000;">Unknown Branch Types</strong>
                                </div>
                            </div>
                        </div>

                        <div class="summary-stats">
                            <span class="stat-item"><i class="fa-solid fa-question-circle"></i> <strong><?= count($remarks_data) ?></strong> unique unknown branch ID combinations</span>
                            <span class="stat-item"><i class="fa-solid fa-layer-group"></i> <strong><?= count(array_unique(array_column($remarks_data, 'region'))) ?></strong> regions affected</span>
                            <span class="stat-item"><i class="fa-solid fa-cubes"></i> <strong><?= count(array_unique(array_column($remarks_data, 'area'))) ?></strong> areas affected</span>
                            <span class="stat-item"><i class="fa-solid fa-file"></i> File: <strong><?= htmlspecialchars($_SESSION['file_name'] ?? '') ?></strong></span>
                        </div>

                        <div class="table-wrapper">
                            <table class="preview-table remarks-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="width: 12%;">Region</th>
                                        <th style="width: 12%;">Area</th>
                                        <th style="width: 12%;">GL Code</th>
                                        <th style="width: 15%;">Branch ID</th>
                                        <th style="width: 12%;">Branch Name</th>
                                        <th style="width: 10%;">Total Amount</th>
                                        <th style="width: 8%;">Transactions</th>
                                        <th style="width: 18%;">Transaction Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (!empty($remarks_data)):
                                        $row_counter = 1;
                                        $grand_unknown_total = 0;
                                        $grand_unknown_count = 0;
                                        
                                        foreach ($remarks_data as $index => $item):
                                            $grand_unknown_total += $item['total_amount'];
                                            $grand_unknown_count += $item['row_count'];
                                            $transaction_count = count($item['transactions']);
                                    ?>
                                        <tr>
                                            <td style="text-align: center; color: #94a3b8;"><?= $row_counter ?></td>
                                            <td><strong><?= htmlspecialchars($item['region']) ?></strong></td>
                                            <td><?= htmlspecialchars($item['area']) ?></td>
                                            <td><span class="code-value"><?= htmlspecialchars($item['code']) ?></span></td>
                                            <td>
                                                <span class="remarks-unknown-badge">
                                                    <i class="fa-solid fa-question-circle"></i> 
                                                    <?= htmlspecialchars($item['branch_id']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($item['branch_name'] ?: '-') ?></td>
                                            <td class="amount-column" style="font-weight: 600; color: #ff0000;">
                                                ₱<?= number_format($item['total_amount'], 2) ?>
                                            </td>
                                            <td class="count-column" style="font-weight: 600;">
                                                <?= $transaction_count ?>
                                            </td>
                                            <td>
                                                <button class="expand-btn" onclick="toggleTransactions(<?= $index ?>)">
                                                    <i class="fa-solid fa-chevron-down" id="icon_<?= $index ?>"></i> 
                                                    View
                                                </button>
                                                <div id="transactions_<?= $index ?>" style="display: none; margin-top: 5px;">
                                                    <?php foreach ($item['transactions'] as $t): ?>
                                                        <div style="font-size: 12px; color: #6b7280; padding: 2px 0;">
                                                            <?= htmlspecialchars($t['date']) ?> - 
                                                            <span class="amount-detail">₱<?= number_format($t['amount'], 2) ?></span>
                                                            <?php if (!empty($t['branch_name'])): ?>
                                                                - <?= htmlspecialchars($t['branch_name']) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php 
                                        $row_counter++;
                                        endforeach; 
                                    ?>
                                        <!-- Grand Total for Unknown Branch Types -->
                                        <tr style="background-color: #ffdede !important; border-top: 3px solid #ff0000;">
                                            <td colspan="6" style="text-align: right; font-weight: 800; color: #ae0000; font-size: 14px; padding: 12px;">
                                                <i class="fa-solid fa-calculator"></i> TOTAL UNKNOWN:
                                            </td>
                                            <td class="amount-column" style="font-weight: 800; color: #ae0000; font-size: 15px;">
                                                ₱<?= number_format($grand_unknown_total, 2) ?>
                                            </td>
                                            <td style="text-align: center; font-weight: 600; color: #ae0000;">
                                                <?= $grand_unknown_count ?>
                                            </td>
                                            <td></td>
                                        </tr>
                                    <?php 
                                    else: 
                                    ?>
                                        <tr>
                                            <td colspan="9" style="text-align: center; padding: 40px; color: #94a3b8;">
                                                <i class="fa-solid fa-check-circle" style="font-size: 24px; color: #10b981; display: block; margin-bottom: 10px;"></i>
                                                No unknown branch types found! All Branch IDs are recognized in the masterdata.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php elseif ($view_mode === 'skipped'): ?>
                        <!-- SKIPPED ROWS VIEW -->
                        <div style="background: #ffe5e5; border: 1px solid #c80000; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-ban" style="color: #c20c0c; font-size: 20px;"></i>
                                <div>
                                    <strong style="color: #c20c0c;">Skipped Rows</strong>
                                    <div style="font-size: 13px; color: #d00202; margin-top: 2px;">
                                        Rows excluded from the preview due to some conditions.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($skipped_data)): ?>
                        <div class="summary-stats">
                            <span class="stat-item"><i class="fa-solid fa-ban"></i> <strong><?= count($skipped_data) ?></strong> skipped rows</span>
                            <span class="stat-item"><i class="fa-solid fa-file"></i> File: <strong><?= htmlspecialchars($_SESSION['file_name'] ?? '') ?></strong></span>
                        </div>

                        <div class="table-wrapper">
                            <table class="preview-table skipped-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Row # base on CSV file</th>
                                        <th style="width: 28%;">Reason</th>
                                        <?php foreach ($display_headers as $col_header): ?>
                                            <th><?= htmlspecialchars($col_header) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($skipped_data as $item): ?>
                                        <tr>
                                            <td style="text-align: center; color: #94a3b8;"><?= $item['row_number'] ?></td>
                                            <td>
                                                <span class="remarks-unknown-badge" style="background: #ffedd5; color: #c2410c;">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($item['reason']) ?>
                                                </span>
                                            </td>
                                            <?php foreach ($item['raw_data'] as $val): ?>
                                                <td><?= htmlspecialchars($val ?? '') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="table-wrapper">
                            <table class="preview-table">
                                <tbody>
                                    <tr>
                                        <td style="text-align: center; padding: 40px; color: #484848;">
                                            <i class="fa-solid fa-check-circle" style="font-size: 24px; color: #10b981; display: block; margin-bottom: 10px;"></i>
                                            No rows were skipped during the preview.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>

<?php include '../footer.php'; ?>

<script>
    // Pagination functions - FIXED
    function changePage(pageNumber, view) {
        if (pageNumber < 1) return;
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', pageNumber);
        urlParams.set('view', view || 'raw');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    function changeSummaryPage(pageNumber) {
        if (pageNumber < 1) return;
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('spage', pageNumber);
        urlParams.set('view', 'summary');
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    }

    // Toggle transaction details in Remarks view
    function toggleTransactions(index) {
        const container = document.getElementById('transactions_' + index);
        const icon = document.getElementById('icon_' + index);
        
        if (container.style.display === 'none') {
            container.style.display = 'block';
            icon.className = 'fa-solid fa-chevron-up';
        } else {
            container.style.display = 'none';
            icon.className = 'fa-solid fa-chevron-down';
        }
    }

    // Drag and drop functionality
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileNameDisplay = document.getElementById('file-name-display');

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        }, false);
    });

    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length) {
            const file = files[0];
            const fileExt = file.name.split('.').pop().toLowerCase();
            if (fileExt !== 'csv') {
                alert('Please upload a CSV file only.');
                return;
            }
            fileInput.files = files;
            updateFileName(file.name);
            setTimeout(() => {
                if (document.getElementById('uploadForm').checkValidity()) {
                    document.getElementById('uploadForm').submit();
                }
            }, 500);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const fileExt = file.name.split('.').pop().toLowerCase();
            if (fileExt !== 'csv') {
                alert('Please upload a CSV file only.');
                fileInput.value = '';
                fileNameDisplay.textContent = '';
                fileNameDisplay.style.color = '#2563eb';
                return;
            }
            updateFileName(file.name);
        }
    });

    function updateFileName(name) {
        fileNameDisplay.textContent = `Selected File: ${name}`;
        fileNameDisplay.style.color = '#16a34a';
    }

    document.getElementById('resetBtn').addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to clear all uploaded data?')) {
            e.preventDefault();
        }
    });

    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 500);
        });
    }, 10000);

    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Preview Data';
        }, 30000);
    });

    // ============================================
    // SESSION MANAGEMENT - IMPROVED
    // ============================================
    
    // REMOVED: Auto-clear on beforeunload - was causing issues with pagination
    // The session is now only cleared when explicitly reset or when navigating
    // to a completely different page (handled by PHP)
    
    console.log('Raw Data Upload page loaded successfully');
    console.log('Session data persists for pagination and view switching');
    console.log('Session will timeout after 30 minutes of inactivity');

    // ============================================
    // SESSION TIMEOUT WITH INACTIVITY
    // ============================================
    
    let inactivityTimer;
    const TIMEOUT_MINUTES = 30; // 30 minutes
    
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(function() {
            // Show a warning and redirect to reset
            const shouldReset = confirm(
                'Your session has been inactive for ' + TIMEOUT_MINUTES + ' minutes.\n\n' +
                'Click OK to reset the page and clear uploaded data, or Cancel to stay.'
            );
            if (shouldReset) {
                window.location.href = '?reset=1';
            } else {
                // Reset the timer if user cancels
                resetInactivityTimer();
            }
        }, TIMEOUT_MINUTES * 60 * 1000);
    }

    // Reset timer on user activity
    const activityEvents = ['click', 'keypress', 'scroll', 'mousemove', 'touchstart', 'touchmove'];
    activityEvents.forEach(event => {
        document.addEventListener(event, resetInactivityTimer);
    });

    // Start the timer when page loads
    resetInactivityTimer();

    // Add CSS for pagination links
    const style = document.createElement('style');
    style.textContent = `
        .pagination-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 14px;
            background: #f1f5f9;
            color: #1e293b;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .pagination-link:hover {
            background: #eb2525;
            color: white;
        }
        .pagination-disabled {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 14px;
            background: #e2e8f0;
            color: #94a3b8;
            border-radius: 6px;
            font-size: 14px;
            cursor: not-allowed;
        }
    `;
    document.head.appendChild(style);
</script>
</body>
</html>
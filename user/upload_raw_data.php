<?php
session_start();

// Session timeout after 30 minutes of inactivity
$inactivity_timeout = 1800; // 30 minutes for production

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
    unset($_SESSION['file_names']);
    
    // Clear duplicate-related session variables
    unset($_SESSION['pending_save']);
    unset($_SESSION['duplicate_check']);
    unset($_SESSION['parsed_data_for_save']);
    unset($_SESSION['summary_data_for_save']);
    unset($_SESSION['duplicate_pending']);
    unset($_SESSION['duplicate_blocked']);
    
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
        unset($_SESSION['file_names']);
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

// Hardcoded column headers for display only - ADD Category
$display_headers = [
    'Date', 'Zone', 'Region', 'Area', 'Branch Name', 
    'Branch ID', 'GL Code', 'GL Description', 'Total Amount', 'Category'
];

// Fixed column positions (0-indexed)
// A1=0, B1=1, C1=2, D1=3, E1=4, F1=5, G1=6, H1=7, I1=8, J1=9
define('COL_DATE', 0);
define('COL_ZONE', 1);
define('COL_REGION', 2);
define('COL_AREA', 3);
define('COL_BRANCH', 4);
define('COL_BRANCH_ID', 5);
define('COL_CODE', 6);
define('COL_DESCRIPTION', 7);
define('COL_AMOUNT', 8);
define('COL_CATEGORY', 9);

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

// Function to get branch profile data by branch_id
function getBranchProfile(string $branch_id): array
{
    global $conn;
    static $profile_cache = [];

    if (empty($branch_id)) {
        return [];
    }

    if (isset($profile_cache[$branch_id])) {
        return $profile_cache[$branch_id];
    }

    try {
        $query = "SELECT mainzone, zone, region, area, region_code, regionID_MLmatic, branch_type 
                  FROM masterdata.branch_profile WHERE branch_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $profile_cache[$branch_id] = $row;
            return $row;
        }

        $profile_cache[$branch_id] = [];
        return [];
    } catch (Exception $e) {
        error_log("Error fetching branch profile for ID $branch_id: " . $e->getMessage());
        return [];
    }
}

// Function to get region profile data by gl_region
function getRegionProfile(string $gl_region): array
{
    global $conn;
    static $region_cache = [];

    if (empty($gl_region)) {
        return [];
    }

    if (isset($region_cache[$gl_region])) {
        return $region_cache[$gl_region];
    }

    try {
        $query = "SELECT mainzone, zone, region, region_code, regionID_MLmatic 
                  FROM masterdata.branch_profile WHERE gl_region = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $gl_region);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $region_cache[$gl_region] = $row;
            return $row;
        }

        $region_cache[$gl_region] = [];
        return [];
    } catch (Exception $e) {
        error_log("Error fetching region profile for gl_region $gl_region: " . $e->getMessage());
        return [];
    }
}

// Function to get zone from masterdata.branch_profile using gl_region
function getZoneFromGlRegion(string $gl_region): string
{
    global $conn;
    static $zone_cache = [];

    if (empty($gl_region)) {
        return '';
    }

    // Check cache first
    if (isset($zone_cache[$gl_region])) {
        return $zone_cache[$gl_region];
    }

    try {
        // Query to get zone from branch_profile using gl_region
        $query = "SELECT DISTINCT zone FROM masterdata.branch_profile WHERE gl_region = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param("s", $gl_region);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row && !empty($row['zone'])) {
            $zone_cache[$gl_region] = trim($row['zone']);
            return $zone_cache[$gl_region];
        }

        $zone_cache[$gl_region] = '';
        return '';
    } catch (Exception $e) {
        error_log("Error fetching zone for gl_region $gl_region: " . $e->getMessage());
        return '';
    }
}

// Function to parse date and extract month/year
function parseTransactionDate(string $date_str): array
{
    $date_str = trim($date_str);
    if (empty($date_str)) {
        return ['date' => null, 'month' => null, 'year' => null];
    }

    // Try different date formats
    $formats = ['m/d/Y', 'm-d-Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $date_str);
        if ($date) {
            return [
                'date' => $date->format('Y-m-d'),
                'month' => $date->format('Y-m-01'),
                'year' => $date->format('Y')
            ];
        }
    }

    // Try to parse with strtotime as fallback
    $timestamp = strtotime($date_str);
    if ($timestamp !== false) {
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        return [
            'date' => $date->format('Y-m-d'),
            'month' => $date->format('Y-m-01'),
            'year' => $date->format('Y')
        ];
    }

    return ['date' => null, 'month' => null, 'year' => null];
}

// Function to get area last letter
function getAreaCode(string $area): string
{
    $area = trim($area);
    if (empty($area)) {
        return '';
    }
    return substr($area, -1);
}

// Function to insert detailed data into fs_reports.fs_raw_data - ADD Category
function insertDetailedData(array $parsed_data, string $uploaded_by): array
{
    global $conn;
    
    $inserted_count = 0;
    $error_count = 0;
    $errors = [];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get current time in Asia/Manila timezone
        $timezone = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $timezone);
        $uploaded_date = $now->format('Y-m-d H:i:s');

        // Prepare the insert statement - ADD category column
        $query = "INSERT INTO fs_reports.fs_raw_data (
            transaction_date, transaction_month, transaction_year,
            mainzone, mlmatic_zone, zone,
            branch_id, branch_name,
            region, gl_region,
            area, mlmatic_area,
            region_code, region_id,
            gl_code, gl_desc,
            amount, transaction_type,
            category,
            uploaded_by, uploaded_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        foreach ($parsed_data as $row) {
            // Extract data from row
            $date_str = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
            $zone = isset($row[COL_ZONE]) ? trim($row[COL_ZONE]) : '';
            $gl_region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : '';
            $mlmatic_area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : '';
            $branch_name = isset($row[COL_BRANCH]) ? trim($row[COL_BRANCH]) : '';
            $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
            $gl_code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : '';
            $gl_desc = isset($row[COL_DESCRIPTION]) ? trim($row[COL_DESCRIPTION]) : '';
            $category = isset($row[COL_CATEGORY]) ? trim($row[COL_CATEGORY]) : '';
            $amount = parseAmount($row[COL_AMOUNT] ?? '0');

            // Parse date
            $date_info = parseTransactionDate($date_str);
            $transaction_date = $date_info['date'];
            $transaction_month = $date_info['month'];
            $transaction_year = $date_info['year'];

            // Get branch profile
            $branch_profile = getBranchProfile($branch_id);
            $mainzone = $branch_profile['mainzone'] ?? '';
            $zone_from_profile = $branch_profile['zone'] ?? '';
            $region = $branch_profile['region'] ?? '';
            $area = $branch_profile['area'] ?? '';
            $region_code = $branch_profile['region_code'] ?? '';
            $region_id = $branch_profile['regionID_MLmatic'] ?? '';
            $transaction_type = $branch_profile['branch_type'] ?? '';

            // Bind parameters - ADD category
            $stmt->bind_param(
                "ssssssssssssssssdssss",
                $transaction_date,
                $transaction_month,
                $transaction_year,
                $mainzone,
                $zone,
                $zone_from_profile,
                $branch_id,
                $branch_name,
                $region,
                $gl_region,
                $area,
                $mlmatic_area,
                $region_code,
                $region_id,
                $gl_code,
                $gl_desc,
                $amount,
                $transaction_type,
                $category,
                $uploaded_by,
                $uploaded_date
            );

            if ($stmt->execute()) {
                $inserted_count++;
            } else {
                $error_count++;
                $errors[] = "Failed to insert row: " . $stmt->error;
            }
        }

        // Commit transaction
        $conn->commit();
        $stmt->close();

    } catch (Exception $e) {
        $conn->rollback();
        $error_count++;
        $errors[] = "Transaction failed: " . $e->getMessage();
        error_log("Detailed data insertion error: " . $e->getMessage());
    }

    return [
        'inserted' => $inserted_count,
        'errors' => $error_count,
        'error_messages' => $errors
    ];
}

// Function to insert summary data into fs_reports.fs_raw_data_summary
function insertSummaryData(array $summary_data, string $uploaded_by): array
{
    global $conn;
    
    $inserted_count = 0;
    $error_count = 0;
    $errors = [];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get current time in Asia/Manila timezone
        $timezone = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $timezone);
        $uploaded_date = $now->format('Y-m-d H:i:s');

        // Prepare the insert statement - ADD branch_id and branch_name columns
        $query = "INSERT INTO fs_reports.fs_raw_data_summary (
            transaction_month, transaction_year,
            mainzone, mlmatic_zone, zone,
            region, gl_region,
            area, mlmatic_area,
            region_code, region_id,
            gl_code, gl_desc,
            amount, row_counts,
            transaction_type,
            branch_id, branch_name,
            uploaded_by, uploaded_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        foreach ($summary_data as $item) {
            // Extract data from summary item
            $gl_region = isset($item['region']) ? trim($item['region']) : '';
            $mlmatic_area = isset($item['area']) ? trim($item['area']) : '';
            $gl_code = isset($item['code']) ? trim($item['code']) : '';
            $gl_desc = isset($item['gl_description']) ? trim($item['gl_description']) : '';
            $transaction_type = isset($item['branch_type']) ? trim($item['branch_type']) : '';
            $amount = isset($item['total_amount']) ? $item['total_amount'] : 0;
            $row_count = isset($item['total_count']) ? $item['total_count'] : 0;
            $branch_id = isset($item['branch_id']) ? trim($item['branch_id']) : '';
            $branch_name = isset($item['branch_name']) ? trim($item['branch_name']) : '';
            
            // Get the zone from the item (should be from the uploaded data)
            $zone_from_upload = isset($item['zone']) ? trim($item['zone']) : '';

            // Get area code (last letter of area)
            $area = getAreaCode($mlmatic_area);

            // Get region profile using gl_region
            $region_profile = getRegionProfile($gl_region);
            $mainzone = $region_profile['mainzone'] ?? '';
            $region = $region_profile['region'] ?? '';
            $region_code = $region_profile['region_code'] ?? '';
            $region_id = $region_profile['regionID_MLmatic'] ?? '';
            
            // CRITICAL: Get the zone from masterdata.branch_profile using gl_region
            $zone_from_masterdata = getZoneFromGlRegion($gl_region);
            
            // Use zone from masterdata if available, otherwise use the zone from the uploaded data
            $final_zone = !empty($zone_from_masterdata) ? $zone_from_masterdata : $zone_from_upload;
            
            // If zone is still empty, try to get from region profile
            if (empty($final_zone) && !empty($region_profile['zone'])) {
                $final_zone = $region_profile['zone'];
            }

            // Get transaction month/year from item or use current
            $transaction_month = isset($item['transaction_month']) ? $item['transaction_month'] : date('Y-m-01');
            $transaction_year = isset($item['transaction_year']) ? $item['transaction_year'] : date('Y');

            // Create variables for bind_param (don't pass literal strings)
            $mlmatic_zone = $zone_from_upload; // Use the zone from the raw data as mlmatic_zone
            
            // Bind parameters - ADD branch_id and branch_name
            $stmt->bind_param(
                "ssssssssssssssdsssss",
                $transaction_month,
                $transaction_year,
                $mainzone,
                $mlmatic_zone,
                $final_zone,  // This is the zone from masterdata.branch_profile using gl_region
                $region,
                $gl_region,
                $area,
                $mlmatic_area,
                $region_code,
                $region_id,
                $gl_code,
                $gl_desc,
                $amount,
                $row_count,
                $transaction_type,
                $branch_id,
                $branch_name,
                $uploaded_by,
                $uploaded_date
            );

            if ($stmt->execute()) {
                $inserted_count++;
            } else {
                $error_count++;
                $errors[] = "Failed to insert summary row: " . $stmt->error;
            }
        }

        // Commit transaction
        $conn->commit();
        $stmt->close();

    } catch (Exception $e) {
        $conn->rollback();
        $error_count++;
        $errors[] = "Transaction failed: " . $e->getMessage();
        error_log("Summary data insertion error: " . $e->getMessage());
    }

    return [
        'inserted' => $inserted_count,
        'errors' => $error_count,
        'error_messages' => $errors
    ];
}

// UPDATED: Function to lock and void existing data and replace summary data - SKIP LOCKED/REPORTED
function lockAndVoidExistingData(array $existing_data, string $username, array $new_summary_data): array
{
    global $conn;
    
    $updated_count = 0;
    $error_count = 0;
    $errors = [];
    $updated_summary = [];
    $skipped_locked_reported = [];

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Get current time in Asia/Manila timezone
        $timezone = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $timezone);
        $current_datetime = $now->format('Y-m-d H:i:s');
        $status_void = 'Void';
        
        foreach ($existing_data as $item) {
            $zone = $item['zone'];
            $transaction_month = $item['transaction_month'];
            $record_count = $item['count'];
            
            // Check if this combination has Locked or Reported records
            if ($item['has_locked'] || $item['has_reported']) {
                // Skip this combination - cannot overwrite
                $skipped_locked_reported[] = [
                    'zone' => $zone,
                    'month' => date('F Y', strtotime($transaction_month)),
                    'reason' => ($item['has_locked'] ? 'LOCKED' : '') . 
                                ($item['has_locked'] && $item['has_reported'] ? ' & ' : '') . 
                                ($item['has_reported'] ? 'REPORTED' : '')
                ];
                continue; // Skip to next combination
            }
            
            try {
                // STEP 1: Update fs_raw_data table - Lock and Void existing records (only non-locked/non-reported)
                $query = "UPDATE fs_reports.fs_raw_data 
                          SET 
                            status = 'Locked',
                            locked_date = ?,
                            locked_by = ?,
                            status_void = ?,
                            voided_by = ?,
                            voided_at = ?
                          WHERE mlmatic_zone = ? 
                            AND transaction_month = ? 
                            AND (status IS NULL OR status != 'Locked')
                            AND (reported_status IS NULL OR reported_status != 'Reported')";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sssssss", 
                    $current_datetime,   // locked_date
                    $username,           // locked_by
                    $status_void,        // status_void
                    $username,           // voided_by
                    $current_datetime,   // voided_at
                    $zone,               // mlmatic_zone
                    $transaction_month   // transaction_month
                );
                $stmt->execute();
                $raw_updated = $stmt->affected_rows;
                
                // STEP 2: Delete existing summary records for this zone + transaction_month (only non-locked/non-reported)
                $delete_query = "DELETE FROM fs_reports.fs_raw_data_summary 
                                WHERE mlmatic_zone = ? 
                                  AND transaction_month = ?
                                  AND NOT EXISTS (
                                    SELECT 1 FROM fs_reports.fs_raw_data 
                                    WHERE fs_reports.fs_raw_data.mlmatic_zone = fs_reports.fs_raw_data_summary.mlmatic_zone
                                    AND fs_reports.fs_raw_data.transaction_month = fs_reports.fs_raw_data_summary.transaction_month
                                    AND (fs_reports.fs_raw_data.status = 'Locked' OR fs_reports.fs_raw_data.reported_status = 'Reported')
                                  )";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("ss", $zone, $transaction_month);
                $delete_stmt->execute();
                $deleted_count = $delete_stmt->affected_rows;
                
                // STEP 3: Insert new summary data for this zone + transaction_month
                $new_summary_inserted = 0;
                foreach ($new_summary_data as $summary_item) {
                    // Check if this summary item belongs to this zone and month
                    $item_zone = isset($summary_item['zone']) ? trim($summary_item['zone']) : '';
                    $item_month = isset($summary_item['transaction_month']) ? $summary_item['transaction_month'] : '';
                    
                    if ($item_zone === $zone && $item_month === $transaction_month) {
                        // Insert this summary item
                        $insert_result = insertSingleSummaryItem($summary_item, $username, $current_datetime, $conn);
                        if ($insert_result['success']) {
                            $new_summary_inserted++;
                        } else {
                            $error_count++;
                            $errors[] = "Failed to insert summary item for zone $zone: " . $insert_result['error'];
                        }
                    }
                }
                
                $total_updated = $raw_updated + $deleted_count + $new_summary_inserted;
                $updated_count += $total_updated;
                
                $updated_summary[] = [
                    'zone' => $zone,
                    'month' => date('F Y', strtotime($transaction_month)),
                    'raw_updated' => $raw_updated,
                    'summary_deleted' => $deleted_count,
                    'summary_inserted' => $new_summary_inserted,
                    'total_updated' => $total_updated,
                    'record_count' => $record_count
                ];
                
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "Failed to update records for zone $zone, month $transaction_month: " . $e->getMessage();
                error_log("Error locking/voiding data: " . $e->getMessage());
            }
        }

        // Commit transaction
        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_count++;
        $errors[] = "Transaction failed: " . $e->getMessage();
        error_log("Lock and void transaction error: " . $e->getMessage());
    }

    return [
        'updated' => $updated_count,
        'updated_summary' => $updated_summary,
        'skipped_locked_reported' => $skipped_locked_reported,
        'errors' => $error_count,
        'error_messages' => $errors
    ];
}

// Helper function to insert a single summary item
function insertSingleSummaryItem(array $summary_item, string $uploaded_by, string $uploaded_date, $conn): array
{
    try {
        // Extract data from summary item
        $gl_region = isset($summary_item['region']) ? trim($summary_item['region']) : '';
        $mlmatic_area = isset($summary_item['area']) ? trim($summary_item['area']) : '';
        $gl_code = isset($summary_item['code']) ? trim($summary_item['code']) : '';
        $gl_desc = isset($summary_item['gl_description']) ? trim($summary_item['gl_description']) : '';
        $transaction_type = isset($summary_item['branch_type']) ? trim($summary_item['branch_type']) : '';
        $amount = isset($summary_item['total_amount']) ? $summary_item['total_amount'] : 0;
        $row_count = isset($summary_item['total_count']) ? $summary_item['total_count'] : 0;
        $branch_id = isset($summary_item['branch_id']) ? trim($summary_item['branch_id']) : '';
        $branch_name = isset($summary_item['branch_name']) ? trim($summary_item['branch_name']) : '';
        $zone_from_upload = isset($summary_item['zone']) ? trim($summary_item['zone']) : '';
        $transaction_month = isset($summary_item['transaction_month']) ? $summary_item['transaction_month'] : date('Y-m-01');
        $transaction_year = isset($summary_item['transaction_year']) ? $summary_item['transaction_year'] : date('Y');

        // Get area code (last letter of area)
        $area = getAreaCode($mlmatic_area);

        // Get region profile using gl_region
        $region_profile = getRegionProfile($gl_region);
        $mainzone = $region_profile['mainzone'] ?? '';
        $region = $region_profile['region'] ?? '';
        $region_code = $region_profile['region_code'] ?? '';
        $region_id = $region_profile['regionID_MLmatic'] ?? '';
        
        // Get the zone from masterdata.branch_profile using gl_region
        $zone_from_masterdata = getZoneFromGlRegion($gl_region);
        
        // Use zone from masterdata if available, otherwise use the zone from the uploaded data
        $final_zone = !empty($zone_from_masterdata) ? $zone_from_masterdata : $zone_from_upload;
        
        // If zone is still empty, try to get from region profile
        if (empty($final_zone) && !empty($region_profile['zone'])) {
            $final_zone = $region_profile['zone'];
        }

        // Prepare insert statement for fs_raw_data_summary
        $query = "INSERT INTO fs_reports.fs_raw_data_summary (
            transaction_month, transaction_year,
            mainzone, mlmatic_zone, zone,
            region, gl_region,
            area, mlmatic_area,
            region_code, region_id,
            gl_code, gl_desc,
            amount, row_counts,
            transaction_type,
            branch_id, branch_name,
            uploaded_by, uploaded_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return ['success' => false, 'error' => $conn->error];
        }

        $mlmatic_zone = $zone_from_upload;
        
        $stmt->bind_param(
            "ssssssssssssssdsssss",
            $transaction_month,
            $transaction_year,
            $mainzone,
            $mlmatic_zone,
            $final_zone,
            $region,
            $gl_region,
            $area,
            $mlmatic_area,
            $region_code,
            $region_id,
            $gl_code,
            $gl_desc,
            $amount,
            $row_count,
            $transaction_type,
            $branch_id,
            $branch_name,
            $uploaded_by,
            $uploaded_date
        );

        if ($stmt->execute()) {
            return ['success' => true, 'inserted_id' => $conn->insert_id];
        } else {
            return ['success' => false, 'error' => $stmt->error];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Function to prepare summary data with month/year
function prepareSummaryDataForInsertion(array $summary_data, array $parsed_data): array
{
    $prepared_data = [];
    
    // Get transaction month/year from parsed data
    $transaction_month = date('Y-m-01');
    $transaction_year = date('Y');
    
    if (!empty($parsed_data)) {
        foreach ($parsed_data as $row) {
            $date_str = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
            if (!empty($date_str)) {
                $date_info = parseTransactionDate($date_str);
                if ($date_info['month'] && $date_info['year']) {
                    $transaction_month = $date_info['month'];
                    $transaction_year = $date_info['year'];
                    break;
                }
            }
        }
    }
    
    // Add month/year to each summary item
    foreach ($summary_data as $item) {
        $item['transaction_month'] = $transaction_month;
        $item['transaction_year'] = $transaction_year;
        $prepared_data[] = $item;
    }
    
    return $prepared_data;
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
        while (($data = fgetcsv($handle, 0, $delimiter, '"', "\0")) !== FALSE) {
            // Clean each field
            $cleaned = array_map('cleanCsvField', $data);
            $rows[] = $cleaned;
        }
        fclose($handle);
    }
    return $rows;
}

// Function to clean a row and ensure it has the right number of columns - UPDATE to 10 columns
function cleanRowData(array $row, int $expectedColumns = 10): array
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

// Function to process a single file and return its data - UPDATE to include Category, Branch ID, Branch Name in summary
function processSingleFile(string $filePath, string $fileName): array
{
    $result = [
        'parsed_data' => [],
        'uploaded_headers' => [],
        'skipped_data' => [],
        'summary_data' => [],
        'remarks_data' => [],
        'column_mapping' => [],
        'total_rows' => 0,
        'error' => null
    ];
    
    try {
        $rows = parseCsvFile($filePath);

        if (!empty($rows)) {
            // Get headers from first row
            $first_row = array_shift($rows);
            // Clean headers
            $uploaded_headers = array_map('trim', array_filter($first_row, function($val) {
                return !empty(trim($val));
            }));
            $uploaded_headers = array_values($uploaded_headers);
            
            // Ensure headers match expected count - UPDATE to 10
            if (count($uploaded_headers) < 10) {
                while (count($uploaded_headers) < 10) {
                    $uploaded_headers[] = 'Column ' . (count($uploaded_headers) + 1);
                }
            }

            // Fixed column mapping based on position - ADD category
            $region_idx = COL_REGION;
            $area_idx = COL_AREA;
            $code_idx = COL_CODE;
            $amount_idx = COL_AMOUNT;
            $branch_id_idx = COL_BRANCH_ID;
            $category_idx = COL_CATEGORY;
            
            $column_mapping = [
                'region' => $region_idx,
                'area' => $area_idx,
                'code' => $code_idx,
                'amount' => $amount_idx,
                'branch_id' => $branch_id_idx,
                'date' => COL_DATE,
                'zone' => COL_ZONE,
                'branch' => COL_BRANCH,
                'description' => COL_DESCRIPTION,
                'category' => $category_idx
            ];
            
            $parsed_data = [];
            $skipped_data = [];
            $skipped_rows = 0;
            $malformed_rows = 0;
            $processed_rows = 0;

            foreach ($rows as $row_index => $row) {
                $original_col_count = count($row);
                $row = cleanRowData($row);
                
                $hasData = false;
                foreach ($row as $cell) {
                    if (!empty(trim($cell))) {
                        $hasData = true;
                        break;
                    }
                }
                
                if (!$hasData) {
                    $skipped_rows++;
                    $skipped_data[] = [
                        'row_number' => $row_index + 2,
                        'reason' => 'Empty row (no data in any column)',
                        'raw_data' => array_pad(array_slice(array_map('trim', $row), 0, 10), 10, '')
                    ];
                    continue;
                }
                
                $row_data = [];
                for ($i = 0; $i < 10; $i++) {
                    $row_data[] = isset($row[$i]) ? trim($row[$i]) : '';
                }
                
                if (empty($row_data[COL_AMOUNT]) && empty($row_data[COL_BRANCH_ID])) {
                    $malformed_rows++;
                    $reason = 'Missing both Amount and Branch ID';
                    if ($original_col_count !== 10) {
                        $reason .= " (row had {$original_col_count} columns instead of 10)";
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

            // Build summary data with zone included - ADD branch_id and branch_name
            $summary_data = [];
            $remarks_data = [];
            
            foreach ($parsed_data as $row_index => $row) {
                $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
                $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
                $zone = isset($row[COL_ZONE]) ? trim($row[COL_ZONE]) : '';
                $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
                $gl_description = isset($row[COL_DESCRIPTION]) ? trim($row[COL_DESCRIPTION]) : '';
                $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
                $branch_name = isset($row[COL_BRANCH]) ? trim($row[COL_BRANCH]) : '';
                $date = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
                $category = isset($row[COL_CATEGORY]) ? trim($row[COL_CATEGORY]) : '';
                $amount = parseAmount($row[COL_AMOUNT] ?? '0');
                
                $branch_type = getBranchType($branch_id);
                
                if ($branch_type === 'Unknown' && !empty($branch_id)) {
                    $key = $region . '|' . $area . '|' . $code . '|' . $branch_id;
                    
                    if (!isset($remarks_data[$key])) {
                        $remarks_data[$key] = [
                            'region' => $region,
                            'area' => $area,
                            'zone' => $zone,
                            'code' => $code,
                            'branch_id' => $branch_id,
                            'branch_name' => $branch_name,
                            'date' => $date,
                            'total_amount' => 0,
                            'row_count' => 0,
                            'transactions' => []
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

                // Summary key now includes branch_id and branch_name
                $summary_key = $region . '|' . $area . '|' . $zone . '|' . $code . '|' . $branch_type . '|' . $branch_id . '|' . $branch_name;
                
                if (!isset($summary_data[$summary_key])) {
                    $summary_data[$summary_key] = [
                        'region' => $region,
                        'area' => $area,
                        'zone' => $zone,
                        'code' => $code,
                        'gl_description' => $gl_description,
                        'branch_id' => $branch_id,
                        'branch_name' => $branch_name,
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
                    $summary_data[$summary_key]['branch_total'] += $amount;
                    $summary_data[$summary_key]['branch_count']++;
                } elseif (strtolower($branch_type) === 'showroom' || $branch_type === 'Showroom') {
                    $summary_data[$summary_key]['showroom_total'] += $amount;
                    $summary_data[$summary_key]['showroom_count']++;
                }
                
                $summary_data[$summary_key]['total_amount'] += $amount;
                $summary_data[$summary_key]['total_count']++;
            }

            // Sort summary with additional sorting by branch_id
            usort($summary_data, function($a, $b) {
                if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
                if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
                if ($a['zone'] != $b['zone']) return strcmp($a['zone'], $b['zone']);
                if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
                if ($a['branch_type'] != $b['branch_type']) return strcmp($a['branch_type'], $b['branch_type']);
                return strcmp($a['branch_id'], $b['branch_id']);
            });
            
            usort($remarks_data, function($a, $b) {
                if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
                if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
                if ($a['zone'] != $b['zone']) return strcmp($a['zone'], $b['zone']);
                if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
                return strcmp($a['branch_id'], $b['branch_id']);
            });

            $result['parsed_data'] = $parsed_data;
            $result['uploaded_headers'] = $uploaded_headers;
            $result['skipped_data'] = $skipped_data;
            $result['summary_data'] = $summary_data;
            $result['remarks_data'] = $remarks_data;
            $result['column_mapping'] = $column_mapping;
            $result['total_rows'] = count($parsed_data);
        }
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        error_log("File parsing error for $fileName: " . $e->getMessage());
    }
    
    return $result;
}

// Function to merge multiple parsed data sets
function mergeParsedData(array $all_data): array
{
    $merged = [
        'parsed_data' => [],
        'skipped_data' => [],
        'summary_data' => [],
        'remarks_data' => [],
        'total_rows' => 0,
        'file_names' => [],
        'uploaded_headers' => []
    ];
    
    // Collect all parsed data and skipped data
    foreach ($all_data as $file_result) {
        if (!empty($file_result['parsed_data'])) {
            $merged['parsed_data'] = array_merge($merged['parsed_data'], $file_result['parsed_data']);
        }
        if (!empty($file_result['skipped_data'])) {
            $merged['skipped_data'] = array_merge($merged['skipped_data'], $file_result['skipped_data']);
        }
        if (!empty($file_result['uploaded_headers'])) {
            $merged['uploaded_headers'] = $file_result['uploaded_headers'];
        }
    }
    
    // Rebuild summary data from all merged parsed data with zone included - ADD branch_id and branch_name
    $summary_data = [];
    $remarks_data = [];
    
    foreach ($merged['parsed_data'] as $row) {
        $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
        $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
        $zone = isset($row[COL_ZONE]) ? trim($row[COL_ZONE]) : '';
        $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
        $gl_description = isset($row[COL_DESCRIPTION]) ? trim($row[COL_DESCRIPTION]) : '';
        $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
        $branch_name = isset($row[COL_BRANCH]) ? trim($row[COL_BRANCH]) : '';
        $date = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
        $amount = parseAmount($row[COL_AMOUNT] ?? '0');
        
        $branch_type = getBranchType($branch_id);
        
        if ($branch_type === 'Unknown' && !empty($branch_id)) {
            $key = $region . '|' . $area . '|' . $zone . '|' . $code . '|' . $branch_id;
            
            if (!isset($remarks_data[$key])) {
                $remarks_data[$key] = [
                    'region' => $region,
                    'area' => $area,
                    'zone' => $zone,
                    'code' => $code,
                    'branch_id' => $branch_id,
                    'branch_name' => $branch_name,
                    'date' => $date,
                    'total_amount' => 0,
                    'row_count' => 0,
                    'transactions' => []
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

        // Summary key with branch_id and branch_name
        $summary_key = $region . '|' . $area . '|' . $zone . '|' . $code . '|' . $branch_type . '|' . $branch_id . '|' . $branch_name;
        
        if (!isset($summary_data[$summary_key])) {
            $summary_data[$summary_key] = [
                'region' => $region,
                'area' => $area,
                'zone' => $zone,
                'code' => $code,
                'gl_description' => $gl_description,
                'branch_id' => $branch_id,
                'branch_name' => $branch_name,
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
            $summary_data[$summary_key]['branch_total'] += $amount;
            $summary_data[$summary_key]['branch_count']++;
        } elseif (strtolower($branch_type) === 'showroom' || $branch_type === 'Showroom') {
            $summary_data[$summary_key]['showroom_total'] += $amount;
            $summary_data[$summary_key]['showroom_count']++;
        }
        
        $summary_data[$summary_key]['total_amount'] += $amount;
        $summary_data[$summary_key]['total_count']++;
    }
    
    // Sort summary with branch_id
    usort($summary_data, function($a, $b) {
        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
        if ($a['zone'] != $b['zone']) return strcmp($a['zone'], $b['zone']);
        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
        if ($a['branch_type'] != $b['branch_type']) return strcmp($a['branch_type'], $b['branch_type']);
        return strcmp($a['branch_id'], $b['branch_id']);
    });
    
    usort($remarks_data, function($a, $b) {
        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
        if ($a['zone'] != $b['zone']) return strcmp($a['zone'], $b['zone']);
        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
        return strcmp($a['branch_id'], $b['branch_id']);
    });
    
    $merged['summary_data'] = $summary_data;
    $merged['remarks_data'] = $remarks_data;
    $merged['total_rows'] = count($merged['parsed_data']);
    
    return $merged;
}

// UPDATED: Function to check if data already exists for a given zone and month
function checkExistingData(array $parsed_data): array
{
    global $conn;
    
    $existing_records = [];
    $checked_combinations = [];
    
    // Get unique combinations of zone and transaction_month from parsed data
    foreach ($parsed_data as $row) {
        $zone = isset($row[COL_ZONE]) ? trim($row[COL_ZONE]) : '';
        $date_str = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
        
        if (empty($zone) || empty($date_str)) {
            continue;
        }
        
        $date_info = parseTransactionDate($date_str);
        $transaction_month = $date_info['month'];
        
        if (empty($transaction_month)) {
            continue;
        }
        
        $key = $zone . '|' . $transaction_month;
        
        // Only check each unique combination once
        if (in_array($key, $checked_combinations)) {
            continue;
        }
        
        $checked_combinations[] = $key;
        
        // Check if this combination exists in the database - CHECK BOTH status AND reported_status
        try {
            // Get count and the upload date(s) - Check if ANY records exist (regardless of status)
            // But also check specifically for Locked or Reported status
            $query = "SELECT 
                        COUNT(*) as count,
                        MAX(uploaded_date) as last_uploaded_date,
                        MIN(uploaded_date) as first_uploaded_date,
                        GROUP_CONCAT(DISTINCT DATE_FORMAT(uploaded_date, '%Y-%m-%d %H:%i:%s') ORDER BY uploaded_date DESC SEPARATOR ', ') as all_upload_dates,
                        SUM(CASE WHEN status = 'Locked' THEN 1 ELSE 0 END) as locked_count,
                        SUM(CASE WHEN status = 'Void' THEN 1 ELSE 0 END) as void_count,
                        SUM(CASE WHEN reported_status = 'Reported' THEN 1 ELSE 0 END) as reported_count
                      FROM fs_reports.fs_raw_data 
                      WHERE mlmatic_zone = ? AND transaction_month = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ss", $zone, $transaction_month);
            $stmt->execute();
            $result = $stmt->get_result();
            $row_data = $result->fetch_assoc();
            
            if ($row_data && $row_data['count'] > 0) {
                // Check if there are Locked or Reported records
                $has_locked = ($row_data['locked_count'] ?? 0) > 0;
                $has_reported = ($row_data['reported_count'] ?? 0) > 0;
                
                $upload_date_display = $row_data['last_uploaded_date'];
                if ($row_data['first_uploaded_date'] !== $row_data['last_uploaded_date']) {
                    $upload_date_display = "Latest: " . $row_data['last_uploaded_date'] . " (First: " . $row_data['first_uploaded_date'] . ")";
                }
                
                $existing_records[] = [
                    'zone' => $zone,
                    'transaction_month' => $transaction_month,
                    'count' => $row_data['count'],
                    'uploaded_date' => $upload_date_display,
                    'last_uploaded_date' => $row_data['last_uploaded_date'],
                    'first_uploaded_date' => $row_data['first_uploaded_date'],
                    'all_upload_dates' => $row_data['all_upload_dates'],
                    'locked_count' => $row_data['locked_count'] ?? 0,
                    'void_count' => $row_data['void_count'] ?? 0,
                    'reported_count' => $row_data['reported_count'] ?? 0,
                    'has_locked' => $has_locked,
                    'has_reported' => $has_reported,
                    'can_overwrite' => !$has_locked && !$has_reported // Can only overwrite if NOT locked AND NOT reported
                ];
            }
        } catch (Exception $e) {
            error_log("Error checking existing data: " . $e->getMessage());
        }
    }
    
    return $existing_records;
}

$parsed_data = [];
$uploaded_headers = [];
$error_message = '';
$success_message = '';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'raw';
$summary_data = [];
$remarks_data = [];
$skipped_data = [];
$column_mapping = [];
$file_names = [];

// Pagination variables
$rows_per_page = 50;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page);

// Handle File Processing - Multiple Files
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload'])) {
    $files = $_FILES['file_upload'];
    $all_results = [];
    $uploaded_files = [];
    $has_errors = false;
    
    // Check if multiple files were uploaded
    if (is_array($files['name'])) {
        // Multiple files
        $file_count = count($files['name']);
        
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file_tmp = $files['tmp_name'][$i];
                $file_name = $files['name'][$i];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if ($file_ext === 'csv') {
                    $result = processSingleFile($file_tmp, $file_name);
                    if ($result['error']) {
                        $has_errors = true;
                        $error_message .= "Error processing '$file_name': " . $result['error'] . "<br>";
                    } else {
                        $all_results[] = $result;
                        $uploaded_files[] = $file_name;
                    }
                } else {
                    $has_errors = true;
                    $error_message .= "Invalid file type: '$file_name'. Please upload CSV files only.<br>";
                }
            } else {
                $has_errors = true;
                $error_message .= "File upload failed for file " . ($i + 1) . " with error code: " . $files['error'][$i] . "<br>";
            }
        }
    } else {
        // Single file
        if ($files['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $files['tmp_name'];
            $file_name = $files['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if ($file_ext === 'csv') {
                $result = processSingleFile($file_tmp, $file_name);
                if ($result['error']) {
                    $has_errors = true;
                    $error_message = "Error processing file: " . $result['error'];
                } else {
                    $all_results[] = $result;
                    $uploaded_files[] = $file_name;
                }
            } else {
                $has_errors = true;
                $error_message = "Invalid file type. Please upload a .csv file only.";
            }
        } else {
            $has_errors = true;
            $error_message = "File upload failed with error code: " . $files['error'];
        }
    }
    
    // Merge all results
    if (!empty($all_results)) {
        $merged = mergeParsedData($all_results);
        
        // Store in session
        $_SESSION['parsed_data'] = $merged['parsed_data'];
        $_SESSION['uploaded_headers'] = $merged['uploaded_headers'];
        $_SESSION['total_rows'] = $merged['total_rows'];
        $_SESSION['summary_data'] = $merged['summary_data'];
        $_SESSION['remarks_data'] = $merged['remarks_data'];
        $_SESSION['skipped_data'] = $merged['skipped_data'];
        $_SESSION['column_mapping'] = isset($all_results[0]['column_mapping']) ? $all_results[0]['column_mapping'] : [];
        $_SESSION['file_names'] = $uploaded_files;
        
        $file_count = count($uploaded_files);
        $unknown_count = count($merged['remarks_data']);
        $skipped_count = count($merged['skipped_data']);
        $success_message = "Successfully processed <strong>$file_count</strong> file(s)! Total: " . $merged['total_rows'] . " rows. Found <strong>$unknown_count</strong> unknown branch types" . ($skipped_count > 0 ? " and <strong>$skipped_count</strong> skipped rows" : "") . ".";
        $_SESSION['success_message'] = $success_message;
        
        $current_page = 1;
    } else {
        if (empty($error_message)) {
            $error_message = "No data was processed. Please check your files.";
            $_SESSION['error_message'] = $error_message;
        }
    }
}

// Handle duplicate actions (Cancel, Proceed) - UPDATED
if (isset($_POST['duplicate_action']) && isset($_SESSION['pending_save']) && $_SESSION['pending_save'] === true) {
    $action = $_POST['duplicate_action'];
    $parsed_data = $_SESSION['parsed_data_for_save'] ?? [];
    $summary_data = $_SESSION['summary_data_for_save'] ?? [];
    $existing_data = $_SESSION['duplicate_check'] ?? [];
    
    if (empty($parsed_data)) {
        $_SESSION['error_message'] = "No data to save. Please upload a file first.";
        header("Location: upload_raw_data.php");
        exit;
    }
    
    if ($action === 'cancel') {
        // Cancel - clear everything and return
        unset($_SESSION['pending_save']);
        unset($_SESSION['duplicate_check']);
        unset($_SESSION['parsed_data_for_save']);
        unset($_SESSION['summary_data_for_save']);
        unset($_SESSION['duplicate_pending']);
        unset($_SESSION['duplicate_blocked']);
        $_SESSION['error_message'] = "Save operation cancelled.";
        header("Location: upload_raw_data.php");
        exit;
    }
    
    if ($action === 'proceed') {
        // Prepare summary data with month/year
        $prepared_summary_data = prepareSummaryDataForInsertion($summary_data, $parsed_data);
        
        // Lock and void existing data in fs_raw_data AND update fs_raw_data_summary
        $lock_result = lockAndVoidExistingData($existing_data, $username, $prepared_summary_data);
        
        // Insert new detailed data into fs_raw_data (only for non-locked/non-reported)
        // First filter out data that belongs to locked/reported zones
        $filtered_parsed_data = [];
        foreach ($parsed_data as $row) {
            $zone = isset($row[COL_ZONE]) ? trim($row[COL_ZONE]) : '';
            $date_str = isset($row[COL_DATE]) ? trim($row[COL_DATE]) : '';
            $date_info = parseTransactionDate($date_str);
            $transaction_month = $date_info['month'];
            
            // Check if this zone+month is locked or reported
            $is_locked_reported = false;
            foreach ($existing_data as $item) {
                if ($item['zone'] === $zone && $item['transaction_month'] === $transaction_month) {
                    if ($item['has_locked'] || $item['has_reported']) {
                        $is_locked_reported = true;
                        break;
                    }
                }
            }
            
            if (!$is_locked_reported) {
                $filtered_parsed_data[] = $row;
            }
        }
        
        $detailed_result = insertDetailedData($filtered_parsed_data, $username);
        
        if ($detailed_result['inserted'] > 0 || $lock_result['updated'] > 0) {
            $success_message = "Data saved to database!<br>";
            
            if (!empty($lock_result['skipped_locked_reported'])) {
                $success_message .= "<div style='background: #fef3c7; padding: 10px; border-radius: 6px; margin: 10px 0; border: 1px solid #f59e0b;'>";
                $success_message .= "<strong>⚠️ Skipped (Locked/Reported):</strong><br>";
                foreach ($lock_result['skipped_locked_reported'] as $skipped) {
                    $success_message .= "• " . htmlspecialchars($skipped['zone']) . " - " . htmlspecialchars($skipped['month']) . 
                                       " (Reason: " . htmlspecialchars($skipped['reason']) . ")<br>";
                }
                $success_message .= "</div>";
            }
            
            $success_message .= "<li>Locked and voided existing records.</li>";
            if (!empty($lock_result['updated_summary'])) {
                $success_message .= "<ul>";
                foreach ($lock_result['updated_summary'] as $item) {
                    $success_message .= "<li><strong>" . htmlspecialchars($item['zone']) . "</strong> - " . htmlspecialchars($item['month']) . 
                                       " (Locked/Voided " . $item['record_count'] . " records, Replaced " . $item['summary_inserted'] . " summary records)</li>";
                }
                $success_message .= "</ul>";
            }
            if ($lock_result['errors'] > 0) {
                $success_message .= "⚠️ " . $lock_result['errors'] . " errors occurred while processing records.<br>";
            }
            $success_message .= "<li> Inserted " . $detailed_result['inserted'] . " new detailed rows.</li>";
            if ($detailed_result['errors'] > 0) {
                $success_message .= " (⚠️ " . $detailed_result['errors'] . " errors)";
            }
            $_SESSION['success_message'] = $success_message;
        } else {
            $_SESSION['error_message'] = "❌ Failed to save data to database.";
        }
        
        // Clear pending flags
        unset($_SESSION['pending_save']);
        unset($_SESSION['duplicate_check']);
        unset($_SESSION['parsed_data_for_save']);
        unset($_SESSION['summary_data_for_save']);
        unset($_SESSION['duplicate_pending']);
        unset($_SESSION['duplicate_blocked']);
        
        header("Location: upload_raw_data.php?view=raw");
        exit;
    }
}

// ===== UPDATED: Handle Save to Database =====
if (isset($_POST['save_to_database']) && !empty($_SESSION['parsed_data'])) {
    // Check if there are unknown branch types (remarks)
    $remarks_data_check = $_SESSION['remarks_data'] ?? [];
    $skipped_data_check = $_SESSION['skipped_data'] ?? [];
    
    $has_remarks = !empty($remarks_data_check);
    $has_skipped = !empty($skipped_data_check);
    
    // Prevent saving if there are remarks OR skipped rows
    if ($has_remarks || $has_skipped) {
        $error_message = "❌ Cannot save to database. ";
        $error_messages = [];
        
        if ($has_remarks) {
            $unknown_count = count($remarks_data_check);
            $error_messages[] = "Found <strong>$unknown_count</strong> unknown branch type(s) that are not in the masterdata branch profile.";
        }
        
        if ($has_skipped) {
            $skipped_count = count($skipped_data_check);
            $error_messages[] = "Found <strong>$skipped_count</strong> skipped row(s) with missing or invalid data.";
        }
        
        $error_message .= implode(" ", $error_messages);
        $error_message .= "<br><br>Please fix these issues before saving:";
        $error_message .= "<ul style='margin: 10px 0 0 20px;'>";
        
        if ($has_remarks) {
            $error_message .= "<li>Check the <strong>Remarks</strong> tab for unknown branch IDs</li>";
        }
        if ($has_skipped) {
            $error_message .= "<li>Check the <strong>Skipped Rows</strong> tab for rows with missing/invalid data</li>";
        }
        $error_message .= "</ul>";
        
        $_SESSION['error_message'] = $error_message;
        
        // Redirect to the most relevant tab
        if ($has_remarks) {
            header("Location: upload_raw_data.php?view=remarks");
        } elseif ($has_skipped) {
            header("Location: upload_raw_data.php?view=skipped");
        } else {
            header("Location: upload_raw_data.php?view=raw");
        }
        exit;
    }
    
    // If no remarks and no skipped rows, proceed with duplicate check
    $parsed_data = $_SESSION['parsed_data'];
    $summary_data = $_SESSION['summary_data'] ?? [];
    
    // Prepare summary data with month/year
    $prepared_summary_data = prepareSummaryDataForInsertion($summary_data, $parsed_data);
    
    $existing_data = checkExistingData($parsed_data);
    
    // Check if any existing data has Locked or Reported status
    $has_locked_or_reported = false;
    $locked_or_reported_details = [];
    
    foreach ($existing_data as $item) {
        if ($item['has_locked'] || $item['has_reported']) {
            $has_locked_or_reported = true;
            $locked_or_reported_details[] = $item;
        }
    }
    
    if ($has_locked_or_reported) {
        // Build a detailed message showing what data already exists and cannot be overwritten
        $duplicate_message = "<strong>⚠️ Cannot Overwrite - Data is Locked or Reported!</strong><br><br>";
        $duplicate_message .= "The following Zone and Month combinations already exist in the database and are <strong>LOCKED</strong> or <strong>REPORTED</strong>:<br><br>";
        $duplicate_message .= "<div style='background: #fef2f2; padding: 15px; border-radius: 8px; margin: 10px 0; border: 2px solid #dc2626; overflow-x: auto;'>";
        $duplicate_message .= "<table style='width: 100%; border-collapse: collapse; min-width: 500px;'>";
        $duplicate_message .= "<tr style='background: #fecaca;'>";
        $duplicate_message .= "<th style='padding: 10px 12px; text-align: left; font-weight: 700; color: #1e293b;'>Zone</th>";
        $duplicate_message .= "<th style='padding: 10px 12px; text-align: left; font-weight: 700; color: #1e293b;'>Transaction Month</th>";
        $duplicate_message .= "<th style='padding: 10px 12px; text-align: left; font-weight: 700; color: #1e293b;'>Existing Records</th>";
        $duplicate_message .= "<th style='padding: 10px 12px; text-align: left; font-weight: 700; color: #1e293b;'>Status</th>";
        $duplicate_message .= "<th style='padding: 10px 12px; text-align: left; font-weight: 700; color: #1e293b;'>Uploaded Date</th>";
        $duplicate_message .= "</tr>";
        
        foreach ($locked_or_reported_details as $item) {
            $month_display = date('F Y', strtotime($item['transaction_month']));
            
            // Determine status display
            $status_parts = [];
            if ($item['has_locked']) {
                $status_parts[] = "<span style='color: #dc2626; font-weight: 700;'>🔒 LOCKED ({$item['locked_count']})</span>";
            }
            if ($item['has_reported']) {
                $status_parts[] = "<span style='color: #b45309; font-weight: 700;'>📊 REPORTED ({$item['reported_count']})</span>";
            }
            $status_display = implode(' | ', $status_parts);
            
            // Format the upload date nicely
            $upload_date_display = 'N/A';
            if (isset($item['last_uploaded_date']) && !empty($item['last_uploaded_date'])) {
                $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $item['last_uploaded_date']);
                if ($date_obj) {
                    $upload_date_display = $date_obj->format('F d, Y h:i:s A');
                }
            }
            
            $duplicate_message .= "<tr>";
            $duplicate_message .= "<td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0;'><strong>" . htmlspecialchars($item['zone']) . "</strong></td>";
            $duplicate_message .= "<td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0;'>" . htmlspecialchars($month_display) . "</td>";
            $duplicate_message .= "<td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; text-align: center; font-weight: 600;'>" . number_format($item['count']) . " records</td>";
            $duplicate_message .= "<td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0;'>" . $status_display . "</td>";
            $duplicate_message .= "<td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #1e293b;'>" . $upload_date_display . "</td>";
            $duplicate_message .= "</tr>";
        }
        
        $duplicate_message .= "</table></div><br>";
        
        // Check if ALL existing records are locked/reported or just some
        $all_blocked = true;
        foreach ($existing_data as $item) {
            if (!$item['has_locked'] && !$item['has_reported']) {
                $all_blocked = false;
                break;
            }
        }
        
        if ($all_blocked) {
            // ALL combinations are locked/reported - cannot proceed at all
            $duplicate_message .= "<div style='background: #fecaca; padding: 15px; border-radius: 8px; border: 2px solid #dc2626; margin: 15px 0;'>";
            $duplicate_message .= "<strong style='color: #dc2626; font-size: 16px;'>❌ Cannot Proceed</strong><br>";
            $duplicate_message .= "<span style='color: #dc2626;'>All Zone + Month combinations have existing data that is <strong>LOCKED</strong> or <strong>REPORTED</strong>. These cannot be overwritten.</span>";
            $duplicate_message .= "</div>";
            
            // Show only Cancel option (no Proceed)
            $_SESSION['error_message'] = $duplicate_message;
            $_SESSION['duplicate_blocked'] = true;
            $_SESSION['pending_save'] = false;
            
            header("Location: upload_raw_data.php?view=raw&duplicate_check=1");
            exit;
        } else {
            // Some are blocked, some are not - show warning with option to proceed
            $duplicate_message .= "<strong>⚠️ Some data cannot be overwritten:</strong><br>";
            $duplicate_message .= "<ul style='margin: 10px 0 10px 20px;'>";
            $duplicate_message .= "<li>🔒 <strong>LOCKED</strong> or <strong>REPORTED</strong> data (<span style='color: #dc2626;'>" . count($locked_or_reported_details) . " combinations</span>) - <strong>WILL BE SKIPPED</strong></li>";
            
            $overwritable = array_filter($existing_data, function($item) {
                return $item['can_overwrite'];
            });
            if (!empty($overwritable)) {
                $duplicate_message .= "<li>✅ <strong>Available</strong> data (" . count($overwritable) . " combinations) - <strong>WILL BE OVERWRITTEN</strong></li>";
            }
            $duplicate_message .= "</ul><br>";
            $duplicate_message .= "Do you want to proceed with overwriting the available data?<br><br>";
            $duplicate_message .= "<div style='display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px;'>";
            $duplicate_message .= "<span style='background: #e2e8f0; padding: 6px 14px; border-radius: 4px;'><strong>Cancel</strong> - Return to upload page without saving</span>";
            $duplicate_message .= "<span style='background: #fbbf24; padding: 6px 14px; border-radius: 4px;'><strong>Proceed</strong> - Overwrite available data, skip locked/reported</span>";
            $duplicate_message .= "</div>";
            
            // Store the duplicate check in session for the confirmation
            $_SESSION['duplicate_check'] = $existing_data;
            $_SESSION['pending_save'] = true;
            $_SESSION['duplicate_action'] = 'pending_partial';
            
            // Store the parsed data to use later
            $_SESSION['parsed_data_for_save'] = $parsed_data;
            $_SESSION['summary_data_for_save'] = $prepared_summary_data;
            
            // Show the duplicate warning
            $_SESSION['error_message'] = $duplicate_message;
            $_SESSION['duplicate_pending'] = true;
            
            // Redirect to show the warning
            header("Location: upload_raw_data.php?view=raw&duplicate_check=1");
            exit;
        }
    }
    
    // If no duplicates found (or only overwritable data), proceed with saving
    // Insert detailed data
    $detailed_result = insertDetailedData($parsed_data, $username);
    
    // Insert summary data using the prepared data
    $summary_result = insertSummaryData($prepared_summary_data, $username);
    
    // Set success/error messages
    if ($detailed_result['inserted'] > 0 || $summary_result['inserted'] > 0) {
        $success_message = "✅ Data successfully saved to database!<br>";
        $success_message .= "✅ Detailed: " . $detailed_result['inserted'] . " rows inserted";
        if ($detailed_result['errors'] > 0) {
            $success_message .= " (⚠️ " . $detailed_result['errors'] . " errors)";
        }
        $success_message .= "<br>";
        $success_message .= "✅ Summary: " . $summary_result['inserted'] . " rows inserted";
        if ($summary_result['errors'] > 0) {
            $success_message .= " (⚠️ " . $summary_result['errors'] . " errors)";
        }
        $_SESSION['success_message'] = $success_message;
    } else {
        $error_message = "❌ Failed to save data to database.<br>";
        if (!empty($detailed_result['error_messages'])) {
            $error_message .= "Detailed errors: " . implode("<br>", $detailed_result['error_messages']);
        }
        if (!empty($summary_result['error_messages'])) {
            $error_message .= "Summary errors: " . implode("<br>", $summary_result['error_messages']);
        }
        $_SESSION['error_message'] = $error_message;
    }
    
    // Redirect to refresh the page
    header("Location: upload_raw_data.php?view=raw");
    exit;
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
    $file_names = $_SESSION['file_names'] ?? [];
}

// If summary_data has old structure (without zone and branch keys), regenerate it
if (!empty($summary_data) && !isset($summary_data[0]['branch_total'])) {
    // Regenerate summary data with branch breakdown, zone, branch_id, and branch_name
    $new_summary_data = [];
    foreach ($parsed_data as $row) {
        $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
        $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
        $zone = isset($row[COL_ZONE]) ? trim($row[COL_ZONE]) : '';
        $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
        $gl_description = isset($row[COL_DESCRIPTION]) ? trim($row[COL_DESCRIPTION]) : '';
        $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
        $branch_name = isset($row[COL_BRANCH]) ? trim($row[COL_BRANCH]) : '';
        $branch_type = getBranchType($branch_id);
        
        $amount = parseAmount($row[COL_AMOUNT] ?? '0');

        $key = $region . '|' . $area . '|' . $zone . '|' . $code . '|' . $branch_type . '|' . $branch_id . '|' . $branch_name;
        
        if (!isset($new_summary_data[$key])) {
            $new_summary_data[$key] = [
                'region' => $region,
                'area' => $area,
                'zone' => $zone,
                'code' => $code,
                'gl_description' => $gl_description,
                'branch_id' => $branch_id,
                'branch_name' => $branch_name,
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
        if ($a['zone'] != $b['zone']) return strcmp($a['zone'], $b['zone']);
        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
        if ($a['branch_type'] != $b['branch_type']) return strcmp($a['branch_type'], $b['branch_type']);
        return strcmp($a['branch_id'], $b['branch_id']);
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

// Calculate grand total for raw data view
$grand_total_amount = 0;
foreach ($parsed_data as $row) {
    $grand_total_amount += parseAmount($row[COL_AMOUNT] ?? '0');
}

// Direct branch/showroom/overall breakdown computed straight from parsed_data
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

// Check if there are unknown branches for UI display
$has_unknown_branches = !empty($remarks_data);
$has_skipped_rows = !empty($skipped_data);
$can_save = !$has_unknown_branches && !$has_skipped_rows;
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

    <style>
        /* Additional styles for hide/show functionality */
        .upload-form-wrapper {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .upload-form-wrapper.hidden {
            opacity: 0;
            transform: translateY(-10px);
            display: none;
        }
        .upload-form-wrapper.visible {
            opacity: 1;
            transform: translateY(0);
            display: block;
        }
        
        /* Data preview container */
        .data-preview-wrapper {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .data-preview-wrapper.hidden {
            opacity: 0;
            transform: translateY(10px);
            display: none;
        }
        .data-preview-wrapper.visible {
            opacity: 1;
            transform: translateY(0);
            display: block;
        }
        
        /* Reset button style when data is loaded */
        .btn-reset-with-data {
            background: #5d5d5d !important;
            color: white !important;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn-reset-with-data:hover {
            background: #b91c1c !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
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
            <div class="upload-container">
                <h2>Upload Raw Data Files (Per Zone)</h2>

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

                <!-- ===== WRAPPER FOR UPLOAD FORM ===== -->
                <div id="uploadFormWrapper" class="upload-form-wrapper <?= !empty($parsed_data) ? 'hidden' : 'visible' ?>">
                    <!-- Drag & Drop Upload Form - Multiple Files -->
                    <form id="uploadForm" action="" method="POST" enctype="multipart/form-data">
                        <div class="drop-zone" id="dropZone">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p><strong>Drag & drop your CSV files here</strong></p>
                            <p style="font-size: 13px; color: #94a3b8;">or click to browse from your computer (CSV files only, multiple allowed)</p>
                            <div id="file-list-display" style="margin-top: 10px; display: block; font-weight: 600; color: #2563eb;"></div>
                            <input type="file" name="file_upload[]" id="fileInput" accept=".csv" multiple required>
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
                </div>

                <!-- ===== DATA PREVIEW WRAPPER ===== -->
                <div id="dataPreviewWrapper" class="data-preview-wrapper <?= !empty($parsed_data) ? 'visible' : 'hidden' ?>">
                    
                    <!-- Show Reset button at top when data is loaded -->
                    <?php if (!empty($parsed_data)): ?>
                    <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
                        <a href="?reset=1" class="btn-reset-with-data" id="resetBtnTop">
                            <i class="fa-solid fa-rotate"></i> Clear Data & Upload New
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- ===== Save to Database Button ===== -->
                    <?php if (!empty($parsed_data) && $view_mode === 'raw'): ?>
                    <form action="" method="POST" style="margin: 15px 0;" id="saveForm">
                        <input type="hidden" name="save_to_database" value="1">
                        <?php 
                        $block_reason = [];
                        if ($has_unknown_branches) $block_reason[] = 'unknown branch types (Remarks tab)';
                        if ($has_skipped_rows) $block_reason[] = 'skipped rows (Skipped Rows tab)';
                        ?>
                        <button type="submit" class="btn-save-database" <?= !$can_save ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?> 
                            onclick="<?= !$can_save ? 'alert(\'❌ Cannot save to database. Please fix the following issues first:\n\n• ' . implode('\n• ', $block_reason) . '\n\nCheck the corresponding tabs for details.\'); return false;' : 'return confirm(\'Are you sure you want to save this data to the database? This will insert ' . $total_rows . ' detailed rows and ' . $summary_total_rows . ' summary rows.\');' ?>">
                            <i class="fa-solid fa-database"></i> 
                            <?= !$can_save ? 'Saving Disabled (Fix issues first)' : 'Save to Database' ?>
                        </button>
                        <?php if (!$can_save): ?>
                            <div style="margin-top: 8px; padding: 10px; background: #ffebeb; border-radius: 6px; border: 1px solid #ffabab;">
                                <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i>
                                <span style="color: #dc2626; font-size: 14px;">
                                    <strong>Cannot save:</strong> 
                                    <?php if ($has_unknown_branches && $has_skipped_rows): ?>
                                        Found <strong><?= count($remarks_data) ?></strong> unknown branch type(s) and <strong><?= count($skipped_data) ?></strong> skipped row(s).
                                    <?php elseif ($has_unknown_branches): ?>
                                        Found <strong><?= count($remarks_data) ?></strong> unknown branch type(s).
                                    <?php elseif ($has_skipped_rows): ?>
                                        Found <strong><?= count($skipped_data) ?></strong> skipped row(s).
                                    <?php endif; ?>
                                    Please check the 
                                    <?php if ($has_unknown_branches): ?>
                                        <a href="?view=remarks" style="color: #dc2626; font-weight: 600; text-decoration: underline;">Remarks tab</a>
                                        <?php if ($has_skipped_rows): ?> and <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($has_skipped_rows): ?>
                                        <a href="?view=skipped" style="color: #dc2626; font-weight: 600; text-decoration: underline;">Skipped Rows tab</a>
                                    <?php endif; ?>
                                    for details.
                                </span>
                            </div>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>

                    <!-- Duplicate Action Buttons -->
                    <?php if (isset($_SESSION['duplicate_pending']) && $_SESSION['duplicate_pending'] === true): ?>
                    <div style="margin-top: 15px; padding: 20px; background: #ffebeb; border: 2px solid #ff0000; border-radius: 8px;">
                        <div style="display: flex; gap: 15px; flex-wrap: wrap; justify-content: center;">
                            <form action="" method="POST" style="display: inline;">
                                <input type="hidden" name="duplicate_action" value="cancel">
                                <button type="submit" class="btn-cancel" style="background: #e2e8f0; color: #1e293b; padding: 10px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                                    <i class="fa-solid fa-times"></i> Cancel
                                </button>
                            </form>
                            <?php if (!isset($_SESSION['duplicate_blocked']) || $_SESSION['duplicate_blocked'] !== true): ?>
                            <form action="" method="POST" style="display: inline;">
                                <input type="hidden" name="duplicate_action" value="proceed">
                                <button type="submit" class="btn-proceed" style="background: #fbbf24; color: #1e293b; padding: 10px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                                    <i class="fa-solid fa-check"></i> Proceed (Overwrite Available)
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

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
                                <span class="badge" style="background: #ffe3e3; color: #5e0000; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                                    <?= count($skipped_data) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Data Preview Content -->
                    <div id="dataPreview" class="<?= empty($parsed_data) ? 'hidden' : '' ?>">
                        
                        <?php if ($view_mode === 'raw' && !empty($parsed_data)): ?>
                            <!-- RAW DATA VIEW - WITH CATEGORY -->
                            <div class="summary-stats">
                                <span class="stat-item"><i class="fa-solid fa-file-lines"></i> <strong><?= $total_rows ?></strong> total rows</span>
                                <span class="stat-item"><i class="fa-solid fa-columns"></i> <strong><?= count($display_headers) ?></strong> columns</span>
                                <span class="stat-item"><i class="fa-solid fa-files"></i> Files: <strong><?= count($file_names) ?></strong></span>
                                <span class="stat-item"><i class="fa-solid fa-calculator"></i> Grand Total: <strong class="grand-total-value">₱<?= number_format($grand_total_amount, 2) ?></strong></span>
                                <span class="stat-item"><?php if (!empty($file_names)): ?>
                                <div><i class="fa-solid fa-file"></i> Processed files: <?= implode(', ', array_map('htmlspecialchars', $file_names)) ?></div>
                                    <?php endif; ?>
                                </span> 
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
                                            $total_columns = count($display_headers);
                                            for ($i = 0; $i < $total_columns - 2; $i++): 
                                            ?>
                                                <td></td>
                                            <?php endfor; ?>
                                            <td style="text-align: right; font-weight: 700; color: #dc2626; font-size: 15px;">
                                                ₱<?= number_format($grand_total_amount, 2) ?>
                                            </td>
                                                <td></td>

                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Raw Pagination Controls -->
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
                            <!-- SUMMARY VIEW - Grouped by Region, Area, Zone, Code, Branch Type, Branch ID, Branch Name -->
                            <div class="summary-stats">
                                <span class="stat-item"><i class="fa-solid fa-layer-group"></i> <strong><?= count(array_unique(array_column($summary_data, 'region'))) ?></strong> regions</span>
                                <span class="stat-item"><i class="fa-solid fa-cubes"></i> <strong><?= count(array_unique(array_column($summary_data, 'area'))) ?></strong> areas</span>
                                <span class="stat-item"><i class="fa-solid fa-map-pin"></i> <strong><?= count(array_unique(array_column($summary_data, 'zone'))) ?></strong> zones</span>
                                <span class="stat-item"><i class="fa-solid fa-tag"></i> <strong><?= $summary_total_rows ?></strong> unique combinations</span>
                                <span class="stat-item"><i class="fa-solid fa-file-lines"></i> <strong><?= count(array_unique(array_column($summary_data, 'gl_description'))) ?></strong> unique GL Descriptions</span>
                                <span class="stat-item"><i class="fa-solid fa-files"></i> Files: <strong><?= count($file_names) ?></strong></span>
                                <span class="stat-item"><?php if (!empty($file_names)): ?>
                                <div><i class="fa-solid fa-file"></i> Processed files: <?= implode(', ', array_map('htmlspecialchars', $file_names)) ?></div>
                                    <?php endif; ?>
                                </span> 
                            </div>
                          
                            <div class="table-wrapper">
                                <table class="preview-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">#</th>
                                            <th style="width: 7%;">Region</th>
                                            <th style="width: 7%;">Area</th>
                                            <th style="width: 7%;">Zone</th>
                                            <th style="width: 4%;">Code</th>
                                            <th style="width: 14%;">GL Description</th>
                                            <th style="width: 8%;">Branch ID</th>
                                            <th style="width: 10%;">Branch Name</th>
                                            <th style="width: 8%;">Branch Type</th>
                                            <th style="width: 9%;">Branch Amount</th>
                                            <th style="width: 4%;">Branch Count</th>
                                            <th style="width: 9%;">Showroom Amount</th>
                                            <th style="width: 4%;">Showroom Count</th>
                                            <th style="width: 9%;">Total Amount</th>
                                            <th style="width: 7%;">Total Rows</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        if (!empty($summary_data)):
                                            // Group data by Region, then Area, then Zone, then Code
                                            $grouped_data = [];
                                            foreach ($summary_data as $item) {
                                                $region = isset($item['region']) ? $item['region'] : 'Unknown Region';
                                                $area = isset($item['area']) ? $item['area'] : 'Unknown Area';
                                                $zone = isset($item['zone']) ? $item['zone'] : '';
                                                $code = isset($item['code']) ? $item['code'] : 'Unknown Code';
                                                
                                                if (!isset($grouped_data[$region])) {
                                                    $grouped_data[$region] = [];
                                                }
                                                if (!isset($grouped_data[$region][$area])) {
                                                    $grouped_data[$region][$area] = [];
                                                }
                                                if (!isset($grouped_data[$region][$area][$zone])) {
                                                    $grouped_data[$region][$area][$zone] = [];
                                                }
                                                if (!isset($grouped_data[$region][$area][$zone][$code])) {
                                                    $grouped_data[$region][$area][$zone][$code] = [];
                                                }
                                                $grouped_data[$region][$area][$zone][$code][] = $item;
                                            }

                                            ksort($grouped_data);
                                            
                                            $row_counter = 1;
                                            $grand_total_all = 0;
                                            $grand_branch_total = 0;
                                            $grand_showroom_total = 0;
                                            
                                            foreach ($grouped_data as $region => $areas):
                                                ksort($areas);
                                                
                                                $region_branch_total = 0;
                                                $region_showroom_total = 0;
                                                $region_total = 0;
                                                $region_branch_count = 0;
                                                $region_showroom_count = 0;
                                                $region_total_count = 0;
                                                
                                                foreach ($areas as $area_items) {
                                                    foreach ($area_items as $zone_items) {
                                                        foreach ($zone_items as $code_items) {
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
                                                }
                                        ?>
                                                <tr class="region-group-header">
                                                    <td colspan="15" style="font-size: 16px; color: #0f172a;">
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
                                                foreach ($areas as $area => $zone_items):
                                                    $area_branch_total = 0;
                                                    $area_showroom_total = 0;
                                                    $area_total = 0;
                                                    $area_branch_count = 0;
                                                    $area_showroom_count = 0;
                                                    $area_total_count = 0;
                                                    
                                                    foreach ($zone_items as $code_items) {
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
                                                    }
                                                ?>
                                                    <tr class="area-subtotal-row">
                                                        <td colspan="15" style="font-weight: 700; color: #0f172a; padding: 8px 15px !important;">
                                                            <i class="fa-solid fa-caret-right"></i> 
                                                            <strong>AREA: <?= htmlspecialchars($area) ?></strong>
                                                            <span style="float: right; font-weight: 600; color: #1e293b;">
                                                                Branch: ₱<?= number_format($area_branch_total, 2) ?> (<?= $area_branch_count ?> rows) | 
                                                                Showroom: ₱<?= number_format($area_showroom_total, 2) ?> (<?= $area_showroom_count ?> rows) | 
                                                                Total: ₱<?= number_format($area_total, 2) ?> (<?= $area_total_count ?> rows)
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    
                                                    <?php 
                                                    ksort($zone_items);
                                                    foreach ($zone_items as $zone => $code_items):
                                                        $zone_branch_total = 0;
                                                        $zone_showroom_total = 0;
                                                        $zone_total = 0;
                                                        $zone_branch_count = 0;
                                                        $zone_showroom_count = 0;
                                                        $zone_total_count = 0;
                                                        
                                                        foreach ($code_items as $items) {
                                                            foreach ($items as $item) {
                                                                $zone_branch_total += isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                                $zone_showroom_total += isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                                $zone_total += isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                                $zone_branch_count += isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                                $zone_showroom_count += isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                                $zone_total_count += isset($item['total_count']) ? $item['total_count'] : 0;
                                                            }
                                                        }
                                                        if (!empty($zone)):
                                                    ?>
                                                        <tr class="zone-subtotal-row">
                                                            <td colspan="15" style="font-weight: 600; color: #0f172a; padding: 6px 15px !important; background: #f1f5f9;">
                                                                <i class="fa-solid fa-location-dot"></i> 
                                                                <strong>ZONE: <?= htmlspecialchars($zone) ?></strong>
                                                                <span style="float: right; font-weight: 600; color: #1e293b;">
                                                                    Branch: ₱<?= number_format($zone_branch_total, 2) ?> (<?= $zone_branch_count ?> rows) | 
                                                                    Showroom: ₱<?= number_format($zone_showroom_total, 2) ?> (<?= $zone_showroom_count ?> rows) | 
                                                                    Total: ₱<?= number_format($zone_total, 2) ?> (<?= $zone_total_count ?> rows)
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php 
                                                        endif;
                                                        ksort($code_items);
                                                        foreach ($code_items as $code => $items):
                                                            usort($items, function($a, $b) {
                                                                if ($a['branch_type'] != $b['branch_type']) return strcmp($a['branch_type'], $b['branch_type']);
                                                                return strcmp($a['branch_id'], $b['branch_id']);
                                                            });
                                                            
                                                            foreach ($items as $idx => $item):
                                                                $branch_type_class = strtolower(isset($item['branch_type']) ? $item['branch_type'] : 'unknown');
                                                                $branch_total = isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                                $branch_count = isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                                $showroom_total = isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                                $showroom_count = isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                                $total_amount = isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                                $total_count = isset($item['total_count']) ? $item['total_count'] : 0;
                                                                $gl_description = isset($item['gl_description']) ? $item['gl_description'] : '';
                                                                $branch_id = isset($item['branch_id']) ? $item['branch_id'] : '';
                                                                $branch_name = isset($item['branch_name']) ? $item['branch_name'] : '';
                                                                
                                                                $branch_negative = $branch_total < 0;
                                                                $showroom_negative = $showroom_total < 0;
                                                                $total_negative = $total_amount < 0;
                                                        ?>
                                                            <tr class="code-row">
                                                                <td style="text-align: center; color: #94a3b8; font-weight: 400;"><?= $row_counter ?></td>
                                                                <td></td>
                                                                <td></td>
                                                                <td></td>
                                                                <td style="padding-left: 30px;">
                                                                    <span class="code-value"><?= htmlspecialchars($code) ?></span>
                                                                </td>
                                                                <td style="
                                                                    max-width: 150px;
                                                                    width: 200px;
                                                                    white-space: normal;
                                                                    overflow-wrap: break-word;
                                                                    word-break: break-word;
                                                                    font-size: 12px;
                                                                    color: #475569;
                                                                ">
                                                                    <?= htmlspecialchars($gl_description ?: '-') ?>
                                                                </td>
                                                                <td style="font-size: 12px; font-weight: 600; color: #1e293b;">
                                                                    <?= htmlspecialchars($branch_id ?: '-') ?>
                                                                </td>
                                                                <td style="font-size: 12px; color: #475569;">
                                                                    <?= htmlspecialchars($branch_name ?: '-') ?>
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
                                                    endforeach; 
                                                    ?>
                                                <?php 
                                                endforeach;
                                                
                                                ?>
                                                <tr class="region-grand-total">
                                                    <td colspan="15" style="font-size: 15px; padding: 12px 15px !important;">
                                                        <i class="fa-solid fa-calculator"></i> 
                                                        <strong>GRAND TOTAL - <?= htmlspecialchars($region) ?></strong>
                                                        <span style="float: right; font-weight: 800; color: #000;">
                                                            Branch: ₱<?= number_format($region_branch_total, 2) ?> | 
                                                            Showroom: ₱<?= number_format($region_showroom_total, 2) ?> | 
                                                            Total: ₱<?= number_format($region_total, 2) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr style="height: 5px;"><td colspan="15" style="padding: 0; border: none;"></td></tr>
                                                
                                                <?php 
                                                $grand_branch_total += $region_branch_total;
                                                $grand_showroom_total += $region_showroom_total;
                                                $grand_total_all += $region_total;
                                            endforeach;
                                            
                                            if (count($grouped_data) > 1):
                                            ?>
                                                <tr style="background-color: #ff5656 !important;">
                                                    <td colspan="15" style="padding: 15px 15px !important; color: white !important; font-size: 17px; font-weight: 800;">
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
                                                <td colspan="15" style="text-align: center; padding: 40px; color: #94a3b8;">
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
                                    <i class="fa-solid fa-info-circle"></i> Each code is broken down by Branch Type, Branch ID, and Branch Name.
                                </span>
                            </div>

                        <?php elseif ($view_mode === 'remarks'): ?>
                            <!-- ===== REMARKS VIEW ===== -->
                            <?php if (!empty($remarks_data)): ?>
                                <div style="background: #ffebeb; border: 1px solid #ffabab; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <i class="fa-solid fa-triangle-exclamation" style="color: #ad0000; font-size: 20px;"></i>
                                        <div>
                                            <strong style="color: #ff0000;">Unknown Branch Types</strong>
                                            <div style="font-size: 13px; color: #ad0000; margin-top: 2px;">
                                                These Branch IDs were not found in the masterdata branch profile.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-stats">
                                    <span class="stat-item"><i class="fa-solid fa-question-circle"></i> <strong><?= count($remarks_data) ?></strong> unique unknown branch ID combinations</span>
                                    <span class="stat-item"><i class="fa-solid fa-layer-group"></i> <strong><?= count(array_unique(array_column($remarks_data, 'region'))) ?></strong> regions affected</span>
                                    <span class="stat-item"><i class="fa-solid fa-cubes"></i> <strong><?= count(array_unique(array_column($remarks_data, 'area'))) ?></strong> areas affected</span>
                                    <span class="stat-item"><i class="fa-solid fa-map-pin"></i> <strong><?= count(array_unique(array_column($remarks_data, 'zone'))) ?></strong> zones affected</span>
                                    <?php if (!empty($skipped_data)): ?>
                                        <span class="stat-item" style="background: #fde3b0; padding: 4px 12px; border-radius: 12px;">
                                            <i class="fa-solid fa-ban" style="color: #b45309;"></i> 
                                            <strong style="color: #b45309;"><?= count($skipped_data) ?></strong> skipped rows also found
                                        </span>
                                    <?php endif; ?>
                                    <span class="stat-item"><i class="fa-solid fa-files"></i> Files: <strong><?= count($file_names) ?></strong></span>
                                    <span class="stat-item"><?php if (!empty($file_names)): ?>
                                        <div><i class="fa-solid fa-file"></i> Processed files: <?= implode(', ', array_map('htmlspecialchars', $file_names)) ?></div>
                                    <?php endif; ?></span> 
                                </div>

                                <div class="table-wrapper">
                                    <table class="preview-table remarks-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;">#</th>
                                                <th style="width: 10%;">Region</th>
                                                <th style="width: 10%;">Area</th>
                                                <th style="width: 10%;">Zone</th>
                                                <th style="width: 10%;">GL Code</th>
                                                <th style="width: 13%;">Branch ID</th>
                                                <th style="width: 12%;">Branch Name</th>
                                                <th style="width: 10%;">Total Amount</th>
                                                <th style="width: 8%;">Transactions</th>
                                                <th style="width: 18%;">Transaction Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
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
                                                    <td><?= htmlspecialchars($item['zone'] ?: '-') ?></td>
                                                    <td><span class="code-value"><?= htmlspecialchars($item['code']) ?></span></td>
                                                    <td>
                                                        <span class="remarks-unknown-badge" style="background: #ffedd5; color: #c2410c; padding: 4px 10px; border-radius: 12px; font-size: 12px;">
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
                                                        <button class="expand-btn" onclick="toggleTransactions(<?= $index ?>)" style="background: #f1f5f9; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; color: #1e293b;">
                                                            <i class="fa-solid fa-chevron-down" id="icon_<?= $index ?>"></i> 
                                                            View
                                                        </button>
                                                        <div id="transactions_<?= $index ?>" style="display: none; margin-top: 8px; max-height: 200px; overflow-y: auto; background: #f8fafc; padding: 8px; border-radius: 4px;">
                                                            <?php foreach ($item['transactions'] as $t): ?>
                                                                <div style="font-size: 12px; color: #6b7280; padding: 3px 0; border-bottom: 1px solid #e2e8f0;">
                                                                    <?= htmlspecialchars($t['date']) ?> - 
                                                                    <span class="amount-detail" style="font-weight: 600; color: #dc2626;">₱<?= number_format($t['amount'], 2) ?></span>
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
                                                <tr style="background-color: #ffdede !important; border-top: 3px solid #ff0000;">
                                                    <td colspan="7" style="text-align: right; font-weight: 800; color: #ae0000; font-size: 14px; padding: 12px;">
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
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <!-- No remarks found - Success message -->
                                <div style="background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px; padding: 40px 30px; text-align: center; margin: 20px 0;">
                                    <div style="font-size: 48px; margin-bottom: 15px; color: #22c55e;">
                                        <i class="fa-solid fa-check-circle"></i>
                                    </div>
                                    <h3 style="color: #15803d; font-size: 24px; margin-bottom: 10px;">All Branch IDs Recognized!</h3>
                                    <p style="color: #166534; font-size: 16px; max-width: 500px; margin: 0 auto;">
                                        All Branch IDs in the uploaded data are successfully matched with the masterdata branch profile.
                                    </p>
                                    <div style="margin-top: 20px; display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                                        <span style="background: #dcfce7; color: #15803d; padding: 8px 16px; border-radius: 20px; font-size: 14px;">
                                            <i class="fa-solid fa-check"></i> All branches verified
                                        </span>
                                        <span style="background: #dcfce7; color: #15803d; padding: 8px 16px; border-radius: 20px; font-size: 14px;">
                                            <i class="fa-solid fa-database"></i> Masterdata up to date
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Show summary stats even when no remarks -->
                                <div class="summary-stats" style="margin-top: 20px;">
                                    <span class="stat-item"><i class="fa-solid fa-check-circle" style="color: #22c55e;"></i> <strong>0</strong> unknown branch IDs</span>
                                    <span class="stat-item"><i class="fa-solid fa-thumbs-up" style="color: #22c55e;"></i> All <strong><?= $total_rows ?></strong> rows processed successfully</span>
                                    <?php if (!empty($skipped_data)): ?>
                                        <span class="stat-item" style="background: #fde3b0; padding: 4px 12px; border-radius: 12px;">
                                            <i class="fa-solid fa-ban" style="color: #b45309;"></i> 
                                            <strong style="color: #b45309;"><?= count($skipped_data) ?></strong> skipped rows found
                                        </span>
                                    <?php endif; ?>
                                    <span class="stat-item"><i class="fa-solid fa-files"></i> Files: <strong><?= count($file_names) ?></strong></span>
                                    <?php if (!empty($file_names)): ?>
                                        <span class="stat-item">
                                            <i class="fa-solid fa-file"></i> Processed files: <?= implode(', ', array_map('htmlspecialchars', $file_names)) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Show a mini preview of the data -->
                                <div style="margin-top: 20px; background: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0;">
                                    <p style="color: #64748b; text-align: center; font-size: 14px;">
                                        <i class="fa-solid fa-info-circle"></i> All uploaded data has been successfully matched with the masterdata.
                                        <br>Switch to <a href="?view=raw" style="color: #2563eb; text-decoration: none; font-weight: 600;">Detailed View</a> or 
                                        <a href="?view=summary" style="color: #2563eb; text-decoration: none; font-weight: 600;">Summary View</a> to explore the data.
                                    </p>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($view_mode === 'skipped'): ?>
                            <!-- ===== SKIPPED ROWS VIEW ===== -->
                            <?php if (!empty($skipped_data)): ?>
                                <div style="background: #ffebeb; border: 1px solid #ffabab; border-radius: 8px; padding: 15px 20px; margin-bottom: 15px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <i class="fa-solid fa-ban" style="color: #dc2626; font-size: 20px;"></i>
                                        <div>
                                            <strong style="color: #dc2626;">Skipped Rows</strong>
                                            <div style="font-size: 13px; color: #dc2626; margin-top: 2px;">
                                                Rows excluded from the preview due to missing or invalid data.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-stats">
                                    <span class="stat-item"><i class="fa-solid fa-ban"></i> <strong><?= count($skipped_data) ?></strong> skipped rows</span>
                                    <?php if (!empty($remarks_data)): ?>
                                        <span class="stat-item" style="background: #ffebeb; padding: 4px 12px; border-radius: 12px;">
                                            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> 
                                            <strong style="color: #dc2626;"><?= count($remarks_data) ?></strong> unknown branch types also found
                                        </span>
                                    <?php endif; ?>
                                    <span class="stat-item"><i class="fa-solid fa-files"></i> Files: <strong><?= count($file_names) ?></strong></span>
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
                                                        <span class="remarks-unknown-badge" style="background: #ffedd5; color: #c2410c; padding: 4px 10px; border-radius: 12px; font-size: 12px;">
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
                                <!-- No skipped rows found - Success message -->
                                <div style="background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px; padding: 40px 30px; text-align: center; margin: 20px 0;">
                                    <div style="font-size: 48px; margin-bottom: 15px; color: #22c55e;">
                                        <i class="fa-solid fa-check-circle"></i>
                                    </div>
                                    <h3 style="color: #15803d; font-size: 24px; margin-bottom: 10px;">No Rows Skipped!</h3>
                                    <p style="color: #166534; font-size: 16px; max-width: 500px; margin: 0 auto;">
                                        All rows from the uploaded files were successfully processed. No rows were skipped due to missing or invalid data.
                                    </p>
                                    <div style="margin-top: 20px; display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                                        <span style="background: #dcfce7; color: #15803d; padding: 8px 16px; border-radius: 20px; font-size: 14px;">
                                            <i class="fa-solid fa-check"></i> All <?= $total_rows ?> rows processed
                                        </span>
                                        <span style="background: #dcfce7; color: #15803d; padding: 8px 16px; border-radius: 20px; font-size: 14px;">
                                            <i class="fa-solid fa-file-lines"></i> Clean data set
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Show summary stats even when no skipped rows -->
                                <div class="summary-stats" style="margin-top: 20px;">
                                    <span class="stat-item"><i class="fa-solid fa-check-circle" style="color: #22c55e;"></i> <strong>0</strong> skipped rows</span>
                                    <span class="stat-item"><i class="fa-solid fa-thumbs-up" style="color: #22c55e;"></i> All <strong><?= $total_rows ?></strong> rows processed successfully</span>
                                    <?php if (!empty($remarks_data)): ?>
                                        <span class="stat-item" style="background: #ffebeb; padding: 4px 12px; border-radius: 12px;">
                                            <i class="fa-solid fa-triangle-exclamation" style="color: #dc2626;"></i> 
                                            <strong style="color: #dc2626;"><?= count($remarks_data) ?></strong> unknown branch types found
                                        </span>
                                    <?php endif; ?>
                                    <span class="stat-item"><i class="fa-solid fa-files"></i> Files: <strong><?= count($file_names) ?></strong></span>
                                    <?php if (!empty($file_names)): ?>
                                        <span class="stat-item">
                                            <i class="fa-solid fa-file"></i> Processed files: <?= implode(', ', array_map('htmlspecialchars', $file_names)) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Show a mini preview of the data -->
                                <div style="margin-top: 20px; background: #f8fafc; border-radius: 8px; padding: 20px; border: 1px solid #e2e8f0;">
                                    <p style="color: #64748b; text-align: center; font-size: 14px;">
                                        <i class="fa-solid fa-info-circle"></i> All uploaded data has been successfully processed with no skipped rows.
                                        <br>Switch to <a href="?view=raw" style="color: #2563eb; text-decoration: none; font-weight: 600;">Detailed View</a> or 
                                        <a href="?view=summary" style="color: #2563eb; text-decoration: none; font-weight: 600;">Summary View</a> to explore the data.
                                    </p>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php include '../footer.php'; ?>

<script>
    // Pagination functions
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

    // Drag and drop functionality - Multiple Files
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileListDisplay = document.getElementById('file-list-display');

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
            let allValid = true;
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileExt = file.name.split('.').pop().toLowerCase();
                if (fileExt !== 'csv') {
                    alert('Please upload CSV files only. Invalid file: ' + file.name);
                    allValid = false;
                    break;
                }
            }
            if (allValid) {
                fileInput.files = files;
                updateFileList(files);
                setTimeout(() => {
                    if (document.getElementById('uploadForm').checkValidity()) {
                        document.getElementById('uploadForm').submit();
                    }
                }, 500);
            }
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            const files = fileInput.files;
            let allValid = true;
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileExt = file.name.split('.').pop().toLowerCase();
                if (fileExt !== 'csv') {
                    alert('Please upload CSV files only. Invalid file: ' + file.name);
                    allValid = false;
                    break;
                }
            }
            if (allValid) {
                updateFileList(files);
            } else {
                fileInput.value = '';
                fileListDisplay.textContent = '';
                fileListDisplay.style.color = '#2563eb';
            }
        } else {
            fileListDisplay.textContent = '';
            fileListDisplay.style.color = '#2563eb';
        }
    });

    function updateFileList(files) {
        if (files.length === 0) {
            fileListDisplay.textContent = '';
            return;
        }
        
        let fileNames = [];
        for (let i = 0; i < files.length; i++) {
            fileNames.push(files[i].name);
        }
        
        fileListDisplay.innerHTML = `<i class="fa-solid fa-check-circle" style="color: #16a34a;"></i> ${files.length} file(s) selected: ${fileNames.join(', ')}`;
        fileListDisplay.style.color = '#16a34a';
    }

    // Reset button handlers - show upload form again
    document.querySelectorAll('#resetBtn, #resetBtnTop').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to clear all uploaded data?')) {
                e.preventDefault();
                return;
            }
            // Allow the reset to proceed - page will reload with reset=1
        });
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
    }, 15000);

    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Preview Data';
        }, 30000);
    });

    // Prevent saving if there are unknown branches OR skipped rows
    document.addEventListener('DOMContentLoaded', function() {
        const saveForm = document.getElementById('saveForm');
        if (saveForm) {
            saveForm.addEventListener('submit', function(e) {
                const hasUnknownBranches = <?= $has_unknown_branches ? 'true' : 'false' ?>;
                const hasSkippedRows = <?= $has_skipped_rows ? 'true' : 'false' ?>;
                
                if (hasUnknownBranches || hasSkippedRows) {
                    e.preventDefault();
                    let message = 'Cannot save to database. Please fix the following issues:\n\n';
                    if (hasUnknownBranches) {
                        message += '• ' + <?= count($remarks_data) ?> + ' unknown branch types (Remarks tab)\n';
                    }
                    if (hasSkippedRows) {
                        message += '• ' + <?= count($skipped_data) ?> + ' skipped rows (Skipped Rows tab)\n';
                    }
                    message += '\nCheck the corresponding tabs for details.';
                    alert(message);
                    
                    // Redirect to the most relevant tab
                    <?php if ($has_unknown_branches): ?>
                        window.location.href = '?view=remarks';
                    <?php elseif ($has_skipped_rows): ?>
                        window.location.href = '?view=skipped';
                    <?php endif; ?>
                    return false;
                }
            });
        }
    });

    console.log('Raw Data Upload page loaded successfully - Multiple file support enabled');
    console.log('Session data persists for pagination and view switching');
    console.log('Session will timeout after 30 minutes of inactivity');

    // Session timeout with inactivity
    let inactivityTimer;
    const TIMEOUT_MINUTES = 30;
    
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(function() {
            const shouldReset = confirm(
                'Your session has been inactive for ' + TIMEOUT_MINUTES + ' minutes.\n\n' +
                'Click OK to reset the page and clear uploaded data, or Cancel to stay.'
            );
            if (shouldReset) {
                window.location.href = '?reset=1';
            } else {
                resetInactivityTimer();
            }
        }, TIMEOUT_MINUTES * 60 * 1000);
    }

    const activityEvents = ['click', 'keypress', 'scroll', 'mousemove', 'touchstart', 'touchmove'];
    activityEvents.forEach(event => {
        document.addEventListener(event, resetInactivityTimer);
    });

    resetInactivityTimer();

    // Add CSS for pagination links and Proceed button
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
        #file-list-display {
            font-size: 13px;
            line-height: 1.6;
        }
        #file-list-display i {
            margin-right: 5px;
        }
       
      
        .amount-detail {
            font-weight: 600;
            color: #dc2626;
        }

        /* Save to Database Button */
        .btn-save-database {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #a70707, #49010a);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
            width: 100%;
            justify-content: center;
        }
        .btn-save-database:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            background: linear-gradient(135deg, #671010, #4c0404);
        }
        .btn-save-database:active:not(:disabled) {
            transform: translateY(0);
        }
        .btn-save-database:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .zone-subtotal-row {
            background-color: #f8fafc !important;
        }
        .zone-subtotal-row td {
            padding: 6px 15px !important;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .btn-cancel:hover {
            background: #cbd5e1 !important;
        }

        .btn-proceed:hover {
            background: #d97706 !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }
    `;
    document.head.appendChild(style);
</script>
</body>
</html>
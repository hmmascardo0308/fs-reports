<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$adjustments = $input['adjustments'] ?? [];
$filters = $input['filters'] ?? [];
$hasChanges = $input['hasChanges'] ?? false;
$includeZeros = $input['includeZeros'] ?? false;

// Validate required fields
if (!$hasChanges) {
    echo json_encode(['success' => false, 'error' => 'No changes detected. Please adjust amounts before saving.']);
    exit;
}

if (empty($adjustments)) {
    echo json_encode(['success' => false, 'error' => 'No adjustment data to save']);
    exit;
}

if (empty($filters['region'])) {
    echo json_encode(['success' => false, 'error' => 'Region filter is required']);
    exit;
}

try {
    // Set timezone to Asia/Manila
    date_default_timezone_set('Asia/Manila');
    $adjusted_at = date('Y-m-d H:i:s');
    $adjusted_by = $_SESSION['username'];
    
    // Get transaction month and year
    $transaction_month = null;
    $transaction_year = null;
    
    if (!empty($filters['transaction_month'])) {
        $transaction_month = $filters['transaction_month'] . '-01'; // Convert YYYY-MM to YYYY-MM-01
        $transaction_year = date('Y', strtotime($transaction_month));
    } elseif (!empty($filters['transaction_year'])) {
        $transaction_year = $filters['transaction_year'];
        // If only year is provided, use January as default month
        $transaction_month = $transaction_year . '-01-01';
    }
    
    // Get mainzone and zone based on region from comparative_report
    $mainzone = null;
    $zone = null;
    
    if (!empty($filters['region'])) {
        $regionQuery = "SELECT mainzone, zone FROM fs_reports.comparative_report WHERE region = ? LIMIT 1";
        $stmt = $conn->prepare($regionQuery);
        $stmt->bind_param("s", $filters['region']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $mainzone = $row['mainzone'];
            $zone = $row['zone'];
        }
        $stmt->close();
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    // First, delete existing adjustments for the same filter combination
    $deleteSql = "DELETE FROM fs_reports.manual_adjustment 
                  WHERE region = ? 
                  AND ((transaction_month = ? AND transaction_year = ?) OR (transaction_month IS NULL AND ? IS NULL))
                  AND adjusted_by = ?";
    
    $deleteStmt = $conn->prepare($deleteSql);
    $monthParam = $transaction_month;
    $yearParam = $transaction_year;
    $deleteStmt->bind_param("sssss", $filters['region'], $monthParam, $yearParam, $transaction_month, $adjusted_by);
    $deleteStmt->execute();
    $deleteStmt->close();
    
    // Insert new adjustments (including zeros and null values for sort_order 6,8,11)
    $insertSql = "INSERT INTO fs_reports.manual_adjustment 
                  (sort_order, description, sub_order, gl_description_comparative, mlfsi, jewelers, 
                   mainzone, zone, region, transaction_month, transaction_year, adjusted_by, adjusted_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $conn->prepare($insertSql);
    
    $successCount = 0;
    $errorCount = 0;
    $zeroCount = 0;
    $nullSubOrderCount = 0;
    
    foreach ($adjustments as $adjustment) {
        $mlfsi = floatval($adjustment['mlfsi'] ?? 0);
        $jewelers = floatval($adjustment['jewelers'] ?? 0);
        
        // Handle sub_order and gl_description_comparative (may be null for sort_order 6,8,11)
        $sub_order = $adjustment['sub_order'];
        $gl_description_comparative = $adjustment['gl_description_comparative'];
        
        // Count zeros for reporting
        if ($mlfsi == 0 && $jewelers == 0) {
            $zeroCount++;
        }
        
        // Count null sub_order entries
        if ($sub_order === null || $sub_order === '') {
            $nullSubOrderCount++;
        }
        
        // Bind parameters (sub_order and gl_description_comparative can be null)
        $insertStmt->bind_param(
            "isisddsssssss",
            $adjustment['sort_order'],
            $adjustment['description'],
            $sub_order,
            $gl_description_comparative,
            $mlfsi,
            $jewelers,
            $mainzone,
            $zone,
            $filters['region'],
            $transaction_month,
            $transaction_year,
            $adjusted_by,
            $adjusted_at
        );
        
        if ($insertStmt->execute()) {
            $successCount++;
        } else {
            $errorCount++;
            error_log("Failed to insert adjustment: " . $insertStmt->error);
        }
    }
    
    $insertStmt->close();
    
    // Commit transaction
    $conn->commit();
    
    if ($successCount > 0) {
        $message = "Successfully saved adjustments."; //  {$successCount} adjustment(s)
        if ($zeroCount > 0) {
            $message .= ""; // (including {$zeroCount} zero-value rows)
        }
        if ($nullSubOrderCount > 0) {
            $message .= ""; //  (including {$nullSubOrderCount} rows with empty sub_order/comparative description)
        }
        if ($errorCount > 0) {
            $message .= " with {$errorCount} error(s)";
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'saved_count' => $successCount,
            'zero_count' => $zeroCount,
            'null_sub_order_count' => $nullSubOrderCount,
            'error_count' => $errorCount
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save adjustments. No rows were inserted.'
        ]);
    }
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Error saving manual adjustments: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
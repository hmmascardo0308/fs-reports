<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['orderData']) || !is_array($data['orderData'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$orderData = $data['orderData'];

if (count($orderData) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No order data provided']);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // Map to hold prefix per description to avoid repeated DB calls
    $prefixes = [];

    // Helper to get prefix from existing gl_id for a description
    $get_prefix = function($desc) use ($conn, &$prefixes) {
        if (isset($prefixes[$desc])) return $prefixes[$desc];
        
        $stmt = mysqli_prepare($conn, "SELECT gl_id FROM fs_reports.gl_codes_ho_new WHERE description = ? AND gl_id IS NOT NULL AND gl_id != '' LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $desc);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $gl_id);
        if (mysqli_stmt_fetch($stmt) && $gl_id) {
            $parts = explode('-', $gl_id);
            $prefixes[$desc] = $parts[0];
            mysqli_stmt_close($stmt);
            return $prefixes[$desc];
        }
        mysqli_stmt_close($stmt);
        return null;
    };

    // Group by description and gl_description_comparative to assign sub_order
    $subOrderMap = [];
    
    foreach ($orderData as $item) {
        $desc = $item['description'] ?? '';
        $comp = $item['gl_description_comparative'] ?? '';
        $subOrder = $item['sub_order'] ?? 1;
        
        $key = $desc . '||' . $comp;
        if (!isset($subOrderMap[$key])) {
            $subOrderMap[$key] = $subOrder;
        }
    }
    
    // Update each row's sub_order and gl_id
    $update_stmt = mysqli_prepare($conn, 
        "UPDATE fs_reports.gl_codes_ho_new
         SET sub_order = ?, gl_id = ?
         WHERE description = ? AND gl_description_comparative = ?"
    );
    
    if (!$update_stmt) {
        throw new Exception('Failed to prepare statement');
    }
    
    foreach ($subOrderMap as $key => $subOrder) {
        list($desc, $comp) = explode('||', $key, 2);
        
        $prefix = $get_prefix($desc);
        $gl_id = ($prefix ?: 'XXX') . '-' . $subOrder;
        
        mysqli_stmt_bind_param($update_stmt, 'isss', $subOrder, $gl_id, $desc, $comp);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Failed to update sub_order and gl_id');
        }
    }
    
    mysqli_stmt_close($update_stmt);
    mysqli_commit($conn);
    
    echo json_encode(['ok' => true, 'message' => 'Order and GL IDs updated successfully']);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>
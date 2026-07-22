<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$ids = array_values(array_filter($data['ids'], fn($v) => $v !== null && $v !== ''));
if (count($ids) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No ids provided']);
    exit;
}

mysqli_begin_transaction($conn);

$col_check = mysqli_query($conn, "SHOW COLUMNS FROM fs_reports.gl_codes LIKE 'sort_order'");
if (!$col_check || mysqli_num_rows($col_check) === 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "Missing column 'sort_order'. Add it to persist sub-order."]);
    exit;
}

$update_stmt = mysqli_prepare($conn, "UPDATE fs_reports.gl_codes SET sort_order = ? WHERE id = ?");
$row_stmt = mysqli_prepare($conn, "SELECT sort_order, description, gl_description_comparative FROM fs_reports.gl_codes WHERE id = ?");

if (!$update_stmt || !$row_stmt) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Prepare failed']);
    exit;
}

$current_description = null;
$current_gl_desc_comp = null;
$sub_index = 0;
// Initialize variables to prevent "Undefined variable" warnings
$sort_order = null;
$desc = null;
$gl_desc_comp = null;
$current_sort_order = null; 

foreach ($ids as $id) {
    $id_int = (int)$id;

    // Get row info for dynamic sub-ordering
    mysqli_stmt_bind_param($row_stmt, 'i', $id_int);
    mysqli_stmt_execute($row_stmt);
    mysqli_stmt_bind_result($row_stmt, $sort_order, $desc, $gl_desc_comp);
    if (!mysqli_stmt_fetch($row_stmt)) {
        mysqli_rollback($conn);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Fetch row failed']);
        exit;
    }

    if ($desc !== $current_description) {
        $current_description = $desc;
        $current_gl_desc_comp = null;
        $sub_index = 0;
        $current_sort_order = $sort_order;
    }

    if ($gl_desc_comp !== $current_gl_desc_comp) {
        $current_gl_desc_comp = $gl_desc_comp;
        $sub_index++;
    }

    $sort_order = $current_sort_order . '.' . $sub_index;
    mysqli_stmt_bind_param($update_stmt, 'si', $sort_order, $id_int);
    if (!mysqli_stmt_execute($update_stmt)) {
        mysqli_rollback($conn);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Update failed']);
        exit;
    }
}

mysqli_stmt_close($update_stmt);
mysqli_stmt_close($row_stmt);
mysqli_commit($conn);

echo json_encode(['ok' => true]);
?>

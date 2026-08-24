<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode([
        'success' => false,
        'error' => 'User not authenticated'
    ]);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request data'
    ]);
    exit;
}

$adjustments = $input['adjustments'] ?? [];
$filters = $input['filters'] ?? [];
$hasChanges = $input['hasChanges'] ?? false;
$includeZeros = $input['includeZeros'] ?? false;

// Validate required fields
if (!$hasChanges) {
    echo json_encode([
        'success' => false,
        'error' => 'No changes detected. Please adjust amounts before saving.'
    ]);
    exit;
}

if (empty($adjustments)) {
    echo json_encode([
        'success' => false,
        'error' => 'No adjustment data to save'
    ]);
    exit;
}

if (empty($filters['region'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Region filter is required'
    ]);
    exit;
}

try {
    // Set timezone to Asia/Manila
    date_default_timezone_set('Asia/Manila');

    $adjusted_at = date('Y-m-d H:i:s');
    $adjusted_by = $_SESSION['username'];

    // ------------------------------------------------------------
    // Get transaction month and year
    // ------------------------------------------------------------
    $transaction_month = null;
    $transaction_year = null;

    if (!empty($filters['transaction_month'])) {
        $transaction_month = $filters['transaction_month'] . '-01';
        $transaction_year = date('Y', strtotime($transaction_month));
    } elseif (!empty($filters['transaction_year'])) {
        $transaction_year = $filters['transaction_year'];
        $transaction_month = $transaction_year . '-01-01';
    }

    // ------------------------------------------------------------
    // Get mainzone and zone based on selected region
    // ------------------------------------------------------------
    $mainzone = null;
    $zone = null;

    $regionQuery = "
        SELECT mainzone, zone
        FROM fs_reports.comparative_report
        WHERE region = ?
        LIMIT 1
    ";

    $regionStmt = $conn->prepare($regionQuery);

    if (!$regionStmt) {
        throw new Exception(
            'Failed to prepare region query: ' . $conn->error
        );
    }

    $regionStmt->bind_param("s", $filters['region']);
    $regionStmt->execute();

    $regionResult = $regionStmt->get_result();

    if ($row = $regionResult->fetch_assoc()) {
        $mainzone = $row['mainzone'];
        $zone = $row['zone'];
    }

    $regionStmt->close();

    // ------------------------------------------------------------
    // Begin transaction
    // ------------------------------------------------------------
    $conn->begin_transaction();

    // ------------------------------------------------------------
    // Delete existing adjustments for the same region/month/user
    // ------------------------------------------------------------
    $deleteSql = "
        DELETE FROM fs_reports.manual_adjustment
        WHERE region = ?
          AND (
                (transaction_month = ? AND transaction_year = ?)
                OR
                (transaction_month IS NULL AND ? IS NULL)
              )
          AND adjusted_by = ?
    ";

    $deleteStmt = $conn->prepare($deleteSql);

    if (!$deleteStmt) {
        throw new Exception(
            'Failed to prepare delete query: ' . $conn->error
        );
    }

    $deleteStmt->bind_param(
        "sssss",
        $filters['region'],
        $transaction_month,
        $transaction_year,
        $transaction_month,
        $adjusted_by
    );

    if (!$deleteStmt->execute()) {
        throw new Exception(
            'Failed to delete existing adjustments: ' . $deleteStmt->error
        );
    }

    $deleteStmt->close();

    // ------------------------------------------------------------
    // Prepare INSERT statements
    //
    // There are TWO types of rows:
    //
    // 1. Normal detail rows:
    //    sort_order + sub_order + gl_description_comparative
    //
    // 2. Direct total rows:
    //    sort_order 6, 8, 11
    //    sub_order = NULL
    //    gl_description_comparative = NULL
    //
    // For 6/8/11, NULL is written directly in SQL so there is
    // no ambiguity with mysqli bind_param().
    // ------------------------------------------------------------

    // Normal detail-row insert
    $detailInsertSql = "
        INSERT INTO fs_reports.manual_adjustment
        (
            sort_order,
            description,
            sub_order,
            gl_description_comparative,
            mlfsi,
            jewelers,
            mainzone,
            zone,
            region,
            transaction_month,
            transaction_year,
            adjusted_by,
            adjusted_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $detailInsertStmt = $conn->prepare($detailInsertSql);

    if (!$detailInsertStmt) {
        throw new Exception(
            'Failed to prepare detail INSERT: ' . $conn->error
        );
    }

    // Direct total-row insert for sort_order 6, 8, 11.
    // sub_order and gl_description_comparative are explicitly NULL.
    $totalInsertSql = "
        INSERT INTO fs_reports.manual_adjustment
        (
            sort_order,
            description,
            sub_order,
            gl_description_comparative,
            mlfsi,
            jewelers,
            mainzone,
            zone,
            region,
            transaction_month,
            transaction_year,
            adjusted_by,
            adjusted_at
        )
        VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $totalInsertStmt = $conn->prepare($totalInsertSql);

    if (!$totalInsertStmt) {
        throw new Exception(
            'Failed to prepare total-row INSERT: ' . $conn->error
        );
    }

    $successCount = 0;
    $errorCount = 0;
    $zeroCount = 0;
    $nullSubOrderCount = 0;
    $specialTotalCount = 0;

    // ------------------------------------------------------------
    // Insert adjustments
    // ------------------------------------------------------------
    foreach ($adjustments as $adjustment) {

        $sort_order = (int)($adjustment['sort_order'] ?? 0);
        $description = (string)($adjustment['description'] ?? '');

        $mlfsi = (float)($adjustment['mlfsi'] ?? 0);
        $jewelers = (float)($adjustment['jewelers'] ?? 0);

        // Count zero-value rows
        if ($mlfsi == 0.0 && $jewelers == 0.0) {
            $zeroCount++;
        }

        // --------------------------------------------------------
        // SPECIAL TOTAL ROWS: 6, 8, 11
        // --------------------------------------------------------
        if (in_array($sort_order, [6, 8, 11], true)) {

            $nullSubOrderCount++;
            $specialTotalCount++;

            $totalInsertStmt->bind_param(
                "isddsssssss",
                $sort_order,
                $description,
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

            if (!$totalInsertStmt->execute()) {
                $errorCount++;

                throw new Exception(
                    "Failed to insert total row sort_order {$sort_order}: "
                    . $totalInsertStmt->error
                );
            }

            $successCount++;
            continue;
        }

        // --------------------------------------------------------
        // NORMAL DETAIL ROW
        // --------------------------------------------------------
        $sub_order = $adjustment['sub_order'] ?? null;
        $gl_description_comparative =
            $adjustment['gl_description_comparative'] ?? null;

        if ($sub_order === null || $sub_order === '') {
            $nullSubOrderCount++;
        }

        $detailInsertStmt->bind_param(
            "isisddsssssss",
            $sort_order,
            $description,
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

        if (!$detailInsertStmt->execute()) {
            $errorCount++;

            throw new Exception(
                "Failed to insert sort_order {$sort_order}: "
                . $detailInsertStmt->error
            );
        }

        $successCount++;
    }

    $detailInsertStmt->close();
    $totalInsertStmt->close();

    // ------------------------------------------------------------
    // Commit only when ALL rows were successfully inserted
    // ------------------------------------------------------------
    $conn->commit();

    if ($successCount > 0) {

        $message = "Successfully saved adjustments.";

        if ($errorCount > 0) {
            $message .= " with {$errorCount} error(s)";
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'saved_count' => $successCount,
            'zero_count' => $zeroCount,
            'null_sub_order_count' => $nullSubOrderCount,
            'special_total_count' => $specialTotalCount,
            'error_count' => $errorCount
        ]);

    } else {

        echo json_encode([
            'success' => false,
            'error' => 'Failed to save adjustments. No rows were inserted.'
        ]);
    }

} catch (Throwable $e) {

    // Roll back the entire save if anything fails.
    // This prevents a partially saved adjustment set.
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log(
            "Rollback failed: " . $rollbackError->getMessage()
        );
    }

    error_log(
        "Error saving manual adjustments: " . $e->getMessage()
    );

    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
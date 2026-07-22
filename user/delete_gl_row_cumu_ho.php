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
if (!is_array($data) || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$id = (int)$data['id'];
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}

mysqli_begin_transaction($conn);

try {
    // Fetch description before delete (needed to recompute sub_order)
    $desc = null;
    $prefix = null;
    $desc_stmt = mysqli_prepare($conn, "SELECT description, gl_id FROM fs_reports.gl_codes_ho_new WHERE id = ? LIMIT 1");
    if (!$desc_stmt) {
        throw new Exception('Prepare failed');
    }
    mysqli_stmt_bind_param($desc_stmt, 'i', $id);
    mysqli_stmt_execute($desc_stmt);
    mysqli_stmt_bind_result($desc_stmt, $desc, $gl_id_val);
    if (mysqli_stmt_fetch($desc_stmt) && $gl_id_val) {
        $parts = explode('-', $gl_id_val);
        $prefix = $parts[0];
    }
    mysqli_stmt_close($desc_stmt);

    // Delete the row
    $stmt = mysqli_prepare($conn, "DELETE FROM fs_reports.gl_codes_ho_new WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed');
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception('Delete failed');
    }
    mysqli_stmt_close($stmt);

    // Recompute sub_order for remaining rows within the same description
    if ($desc !== null && $desc !== '') {
        $groups_stmt = mysqli_prepare(
            $conn,
            "SELECT gl_description_comparative
             FROM fs_reports.gl_codes_ho_new
             WHERE description = ?
             GROUP BY gl_description_comparative
             ORDER BY MIN(sub_order + 0) ASC, MIN(id) ASC"
        );
        if (!$groups_stmt) {
            throw new Exception('Prepare failed');
        }
        mysqli_stmt_bind_param($groups_stmt, 's', $desc);
        mysqli_stmt_execute($groups_stmt);
        $result = mysqli_stmt_get_result($groups_stmt);
        $comps = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $comps[] = $row['gl_description_comparative'];
            }
        }
        mysqli_stmt_close($groups_stmt);

        if (count($comps) > 0) {
            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE fs_reports.gl_codes_ho_new
                 SET sub_order = ?, gl_id = ?
                 WHERE description = ? AND gl_description_comparative = ?"
            );
            if (!$update_stmt) {
                throw new Exception('Prepare failed');
            }

            $new_sub = 1;
            foreach ($comps as $comp) {
                $new_gl_id = ($prefix ?: 'GLX') . '-' . $new_sub;
                mysqli_stmt_bind_param($update_stmt, 'isss', $new_sub, $new_gl_id, $desc, $comp);
                if (!mysqli_stmt_execute($update_stmt)) {
                    mysqli_stmt_close($update_stmt);
                    throw new Exception('Failed to update sub_order');
                }
                $new_sub++;
            }
            mysqli_stmt_close($update_stmt);
        }
    }

    mysqli_commit($conn);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>

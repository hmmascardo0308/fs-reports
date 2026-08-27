<?php

// delete_gl_row.php

session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';


/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed'
    ]);

    exit;
}


$raw =
    file_get_contents(
        'php://input'
    );


$data =
    json_decode(
        $raw,
        true
    );


if (
    !is_array($data) ||
    !isset($data['id'])
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid payload'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| ID validation
|--------------------------------------------------------------------------
*/

$id =
    (int)$data['id'];


if ($id <= 0) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid id'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GL Type / Table
|--------------------------------------------------------------------------
*/

$gl_type =
    $data['gl_type'] ?? 'new';


if (
    !in_array(
        $gl_type,
        ['old', 'new'],
        true
    )
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid GL type'
    ]);

    exit;
}


$gl_table =
    ($gl_type === 'old')
        ? 'fs_reports.gl_codes'
        : 'fs_reports.new_gl_codes';


/*
|--------------------------------------------------------------------------
| Transaction
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);


try {

    /*
    |--------------------------------------------------------------------------
    | Fetch row information before deleting
    |--------------------------------------------------------------------------
    */

    $desc = null;
    $gl_id_val = null;
    $prefix = null;


    $desc_stmt =
        mysqli_prepare(
            $conn,
            "SELECT
                description,
                gl_id
             FROM {$gl_table}
             WHERE id = ?
             LIMIT 1"
        );


    if (!$desc_stmt) {

        throw new Exception(
            'Prepare failed: ' .
            mysqli_error($conn)
        );

    }


    mysqli_stmt_bind_param(
        $desc_stmt,
        'i',
        $id
    );


    if (
        !mysqli_stmt_execute(
            $desc_stmt
        )
    ) {

        mysqli_stmt_close(
            $desc_stmt
        );

        throw new Exception(
            'Failed to fetch row'
        );

    }


    mysqli_stmt_bind_result(
        $desc_stmt,
        $desc,
        $gl_id_val
    );


    $row_exists =
        mysqli_stmt_fetch(
            $desc_stmt
        );


    mysqli_stmt_close(
        $desc_stmt
    );


    if (!$row_exists) {

        throw new Exception(
            'GL row not found'
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Get GL ID prefix
    |--------------------------------------------------------------------------
    */

    if (
        $gl_id_val !== null &&
        $gl_id_val !== ''
    ) {

        $parts =
            explode(
                '-',
                $gl_id_val
            );

        $prefix =
            $parts[0];

    }


    /*
    |--------------------------------------------------------------------------
    | Delete row
    |--------------------------------------------------------------------------
    */

    $stmt =
        mysqli_prepare(
            $conn,
            "DELETE FROM {$gl_table}
             WHERE id = ?"
        );


    if (!$stmt) {

        throw new Exception(
            'Prepare failed: ' .
            mysqli_error($conn)
        );

    }


    mysqli_stmt_bind_param(
        $stmt,
        'i',
        $id
    );


    if (
        !mysqli_stmt_execute(
            $stmt
        )
    ) {

        $error =
            mysqli_stmt_error(
                $stmt
            );

        mysqli_stmt_close(
            $stmt
        );

        throw new Exception(
            'Delete failed: ' .
            $error
        );

    }


    mysqli_stmt_close(
        $stmt
    );


    /*
    |--------------------------------------------------------------------------
    | Recompute sub_order
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | MON-1
    | MON-2
    | MON-3
    |
    | If MON-2 is deleted:
    |
    | MON-1
    | MON-2
    |
    |--------------------------------------------------------------------------
    */

    if (
        $desc !== null &&
        $desc !== ''
    ) {

        $groups_stmt =
            mysqli_prepare(
                $conn,
                "SELECT
                    gl_description_comparative
                 FROM {$gl_table}
                 WHERE description = ?
                 GROUP BY
                    gl_description_comparative
                 ORDER BY
                    MIN(sub_order + 0) ASC,
                    MIN(id) ASC"
            );


        if (!$groups_stmt) {

            throw new Exception(
                'Prepare failed: ' .
                mysqli_error($conn)
            );

        }


        mysqli_stmt_bind_param(
            $groups_stmt,
            's',
            $desc
        );


        mysqli_stmt_execute(
            $groups_stmt
        );


        $result =
            mysqli_stmt_get_result(
                $groups_stmt
            );


        $comps = [];


        if ($result) {

            while (
                $row =
                mysqli_fetch_assoc(
                    $result
                )
            ) {

                $comps[] =
                    $row[
                        'gl_description_comparative'
                    ];

            }

        }


        mysqli_stmt_close(
            $groups_stmt
        );


        /*
        |--------------------------------------------------------------------------
        | Update remaining groups
        |--------------------------------------------------------------------------
        */

        if (
            count($comps) > 0 &&
            $prefix !== null &&
            $prefix !== ''
        ) {

            $update_stmt =
                mysqli_prepare(
                    $conn,
                    "UPDATE {$gl_table}
                     SET
                        sub_order = ?,
                        gl_id = ?
                     WHERE
                        description = ?
                        AND gl_description_comparative = ?"
                );


            if (!$update_stmt) {

                throw new Exception(
                    'Prepare failed: ' .
                    mysqli_error($conn)
                );

            }


            $new_sub = 1;


            foreach (
                $comps
                as $comp
            ) {

                $new_gl_id =
                    $prefix .
                    '-' .
                    $new_sub;


                mysqli_stmt_bind_param(
                    $update_stmt,
                    'isss',
                    $new_sub,
                    $new_gl_id,
                    $desc,
                    $comp
                );


                if (
                    !mysqli_stmt_execute(
                        $update_stmt
                    )
                ) {

                    $error =
                        mysqli_stmt_error(
                            $update_stmt
                        );

                    mysqli_stmt_close(
                        $update_stmt
                    );

                    throw new Exception(
                        'Failed to update sub_order: ' .
                        $error
                    );

                }


                $new_sub++;

            }


            mysqli_stmt_close(
                $update_stmt
            );

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    mysqli_commit(
        $conn
    );


    echo json_encode([
        'ok' => true,
        'message' => 'Row deleted successfully'
    ]);


} catch (Exception $e) {

    mysqli_rollback(
        $conn
    );


    http_response_code(500);


    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);

}

?>
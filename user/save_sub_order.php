<?php

// save_sub_order.php

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


/*
|--------------------------------------------------------------------------
| Read JSON payload
|--------------------------------------------------------------------------
*/

$raw = file_get_contents('php://input');

$data = json_decode($raw, true);


if (
    !is_array($data) ||
    !isset($data['orderData']) ||
    !is_array($data['orderData'])
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid payload'
    ]);

    exit;
}


$orderData = $data['orderData'];


if (count($orderData) === 0) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'No order data provided'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GL Type / Table
|--------------------------------------------------------------------------
|
| old = fs_reports.gl_codes
| new = fs_reports.new_gl_codes
|
*/

$gl_type = $data['gl_type'] ?? 'new';


if (!in_array($gl_type, ['old', 'new'], true)) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid GL type'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Select GL table
|--------------------------------------------------------------------------
*/

$gl_table =
    ($gl_type === 'old')
        ? 'fs_reports.gl_codes'
        : 'fs_reports.new_gl_codes';


/*
|--------------------------------------------------------------------------
| Start transaction
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);


try {

    /*
    |--------------------------------------------------------------------------
    | Prefix cache
    |--------------------------------------------------------------------------
    |
    | Stores the GL prefix for each description so we don't repeatedly
    | query the same description.
    |
    */

    $prefixes = [];


    /*
    |--------------------------------------------------------------------------
    | Get GL prefix for description
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | Existing GL ID:
    | MON-1
    |
    | Prefix:
    | MON
    |
    | If sub_order = 5:
    | MON-5
    |
    */

    $get_prefix = function ($desc)
        use (
            $conn,
            $gl_table,
            &$prefixes
        ) {

            /*
            |------------------------------------------------------------------
            | Return cached prefix if already retrieved
            |------------------------------------------------------------------
            */

            if (isset($prefixes[$desc])) {

                return $prefixes[$desc];

            }


            /*
            |------------------------------------------------------------------
            | Prepare query
            |------------------------------------------------------------------
            */

            $stmt = mysqli_prepare(
                $conn,
                "SELECT gl_id
                 FROM {$gl_table}
                 WHERE description = ?
                   AND gl_id IS NOT NULL
                   AND gl_id != ''
                 ORDER BY id ASC
                 LIMIT 1"
            );


            if (!$stmt) {

                return null;

            }


            /*
            |------------------------------------------------------------------
            | Bind description
            |------------------------------------------------------------------
            */

            mysqli_stmt_bind_param(
                $stmt,
                's',
                $desc
            );


            /*
            |------------------------------------------------------------------
            | Execute query
            |------------------------------------------------------------------
            */

            if (!mysqli_stmt_execute($stmt)) {

                mysqli_stmt_close($stmt);

                return null;

            }


            /*
            |------------------------------------------------------------------
            | Initialize GL ID
            |------------------------------------------------------------------
            |
            | This prevents:
            |
            | Use of unassigned variable '$gl_id'
            |
            */

            $gl_id = '';


            /*
            |------------------------------------------------------------------
            | Bind result
            |------------------------------------------------------------------
            */

            mysqli_stmt_bind_result(
                $stmt,
                $gl_id
            );


            /*
            |------------------------------------------------------------------
            | Fetch result
            |------------------------------------------------------------------
            */

            $found = mysqli_stmt_fetch($stmt);


            /*
            |------------------------------------------------------------------
            | Extract prefix
            |------------------------------------------------------------------
            */

            if (
                $found &&
                $gl_id !== ''
            ) {

                $parts = explode(
                    '-',
                    $gl_id,
                    2
                );


                $prefix = trim(
                    $parts[0] ?? ''
                );


                if ($prefix !== '') {

                    $prefixes[$desc] = $prefix;


                    mysqli_stmt_close(
                        $stmt
                    );


                    return $prefix;

                }

            }


            /*
            |------------------------------------------------------------------
            | No valid GL ID found
            |------------------------------------------------------------------
            */

            mysqli_stmt_close(
                $stmt
            );


            return null;

        };


    /*
    |--------------------------------------------------------------------------
    | Build Sub-Order Map
    |--------------------------------------------------------------------------
    |
    | Only the FIRST occurrence of each:
    |
    | description + gl_description_comparative
    |
    | combination determines its sub_order.
    |
    */

    $subOrderMap = [];


    foreach ($orderData as $item) {

        /*
        |----------------------------------------------------------------------
        | Get description
        |----------------------------------------------------------------------
        */

        $desc = trim(
            $item['description'] ?? ''
        );


        /*
        |----------------------------------------------------------------------
        | Get comparative description
        |----------------------------------------------------------------------
        */

        $comp = trim(
            $item['gl_description_comparative'] ?? ''
        );


        /*
        |----------------------------------------------------------------------
        | Skip incomplete records
        |----------------------------------------------------------------------
        */

        if (
            $desc === '' ||
            $comp === ''
        ) {

            continue;

        }


        /*
        |----------------------------------------------------------------------
        | Get sub-order
        |----------------------------------------------------------------------
        */

        $subOrder = (int) (
            $item['sub_order'] ?? 1
        );


        /*
        |----------------------------------------------------------------------
        | Minimum sub-order is 1
        |----------------------------------------------------------------------
        */

        if ($subOrder < 1) {

            $subOrder = 1;

        }


        /*
        |----------------------------------------------------------------------
        | Create unique description/comparative key
        |----------------------------------------------------------------------
        */

        $key =
            $desc .
            '||' .
            $comp;


        /*
        |----------------------------------------------------------------------
        | Only use FIRST occurrence
        |----------------------------------------------------------------------
        */

        if (!isset($subOrderMap[$key])) {

            $subOrderMap[$key] = $subOrder;

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Prepare UPDATE statement
    |--------------------------------------------------------------------------
    |
    | Updates:
    |
    | sub_order
    | gl_id
    |
    | Based on:
    |
    | description
    | gl_description_comparative
    |
    */

    $update_stmt = mysqli_prepare(
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
            'Failed to prepare update statement: ' .
            mysqli_error($conn)
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Update each comparative group
    |--------------------------------------------------------------------------
    */

    foreach (
        $subOrderMap as $key => $subOrder
    ) {

        /*
        |----------------------------------------------------------------------
        | Split description and comparative description
        |----------------------------------------------------------------------
        */

        $parts = explode(
            '||',
            $key,
            2
        );


        $desc = trim(
            $parts[0] ?? ''
        );


        $comp = trim(
            $parts[1] ?? ''
        );


        /*
        |----------------------------------------------------------------------
        | Skip invalid values
        |----------------------------------------------------------------------
        */

        if (
            $desc === '' ||
            $comp === ''
        ) {

            continue;

        }


        /*
        |----------------------------------------------------------------------
        | Get GL prefix
        |----------------------------------------------------------------------
        */

        $prefix = $get_prefix(
            $desc
        );


        /*
        |----------------------------------------------------------------------
        | Skip if no prefix exists
        |----------------------------------------------------------------------
        */

        if (
            $prefix === null ||
            $prefix === ''
        ) {

            continue;

        }


        /*
        |----------------------------------------------------------------------
        | Generate GL ID
        |----------------------------------------------------------------------
        |
        | Example:
        |
        | Prefix = MON
        | Sub Order = 5
        |
        | Result:
        | MON-5
        |
        */

        $gl_id =
            $prefix .
            '-' .
            $subOrder;


        /*
        |----------------------------------------------------------------------
        | Bind update parameters
        |----------------------------------------------------------------------
        */

        mysqli_stmt_bind_param(
            $update_stmt,
            'isss',
            $subOrder,
            $gl_id,
            $desc,
            $comp
        );


        /*
        |----------------------------------------------------------------------
        | Execute update
        |----------------------------------------------------------------------
        */

        if (!mysqli_stmt_execute($update_stmt)) {

            throw new Exception(
                'Failed to update sub_order and gl_id: ' .
                mysqli_stmt_error($update_stmt)
            );

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Close update statement
    |--------------------------------------------------------------------------
    */

    mysqli_stmt_close(
        $update_stmt
    );


    /*
    |--------------------------------------------------------------------------
    | Commit transaction
    |--------------------------------------------------------------------------
    */

    if (!mysqli_commit($conn)) {

        throw new Exception(
            'Failed to commit transaction: ' .
            mysqli_error($conn)
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'ok' => true,
        'message' => 'Order and GL IDs updated successfully'
    ]);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Rollback transaction
    |--------------------------------------------------------------------------
    */

    mysqli_rollback(
        $conn
    );


    /*
    |--------------------------------------------------------------------------
    | Error response
    |--------------------------------------------------------------------------
    */

    http_response_code(500);


    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);

}

?>

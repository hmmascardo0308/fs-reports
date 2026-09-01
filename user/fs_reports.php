<?php

// fs_reports.php
session_start();

require_once __DIR__ . '/../config/config.php';

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/
$status_message = null;
$status_type = 'success';

if (isset($_SESSION['flash_message'])) {
    $status_message = $_SESSION['flash_message'];
    $status_type = $_SESSION['flash_type'] ?? 'success';

    unset(
        $_SESSION['flash_message'],
        $_SESSION['flash_type']
    );
}

/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (
    isset($_GET['action']) &&
    $_GET['action'] === 'logout'
) {
    session_destroy();

    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? 'unknown';
$full_name = $_SESSION['full_name'] ?? 'unknown';
$user_type = $_SESSION['user_type'] ?? 'unknown';

/*
|--------------------------------------------------------------------------
| GL TYPE
|--------------------------------------------------------------------------
| old = fs_reports.gl_codes_ho
| new = fs_reports.new_gl_codes_ho
|--------------------------------------------------------------------------
*/

$gl_type = $_GET['gl_type'] ?? 'new';

if (!in_array($gl_type, ['old', 'new'], true)) {
    $gl_type = 'new';
}

$gl_table = (
    $gl_type === 'old'
        ? 'fs_reports.gl_codes_ho'
        : 'fs_reports.new_gl_codes_ho'
);

$gl_title = (
    $gl_type === 'old'
        ? 'Old GL Code'
        : 'New GL Code'
);

/*
|--------------------------------------------------------------------------
| POST - ADD NEW ROW
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_gl_type = $_POST['gl_type'] ?? 'new';

    if (!in_array($posted_gl_type, ['old', 'new'], true)) {
        $posted_gl_type = 'new';
    }

    /*
    |--------------------------------------------------------------------------
    | IMPORTANT:
    | Determine the table again from POST.
    |--------------------------------------------------------------------------
    */

    $post_gl_table = (
        $posted_gl_type === 'old'
            ? 'fs_reports.gl_codes_ho'
            : 'fs_reports.new_gl_codes_ho'
    );

    $redirect_url =
        'fs_reports.php?gl_type=' .
        urlencode($posted_gl_type);

    /*
    |--------------------------------------------------------------------------
    | POST VALUES
    |--------------------------------------------------------------------------
    */

    $desc_type = $_POST['desc_type'] ?? 'new';
    $row_desc_type = $_POST['row_desc_type'] ?? 'new';

    if (!in_array($desc_type, ['new', 'existing'], true)) {
        $desc_type = 'new';
    }

    if (!in_array($row_desc_type, ['new', 'existing'], true)) {
        $row_desc_type = 'new';
    }

    $description = trim(
        $_POST['description'] ?? ''
    );

    $gl_description_comparative = trim(
        $_POST['gl_description_comparative'] ?? ''
    );

    $gl_code = trim(
        $_POST['gl_code'] ?? ''
    );

    $gl_description = trim(
        $_POST['gl_description'] ?? ''
    );

    $gl_mapping = strtolower(
        trim($_POST['gl_mapping'] ?? '')
    );

    $gl_id = null;
    $sort_order = null;
    $sub_order = null;

    $has_error = false;

    $status_message = 'Saved successfully.';
    $status_type = 'success';

    /*
    |--------------------------------------------------------------------------
    | BASIC VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($description === '') {
        $status_message = 'Description is required.';
        $status_type = 'error';
        $has_error = true;
    }

    if (
        !$has_error &&
        $gl_description_comparative === ''
    ) {
        $status_message =
            'Comparative Report Description is required.';

        $status_type = 'error';
        $has_error = true;
    }

    /*
    |--------------------------------------------------------------------------
    | GET NEXT SORT ORDER
    |--------------------------------------------------------------------------
    */

    $next_sort = 1;

    if (!$has_error) {

        $max_sort_res = mysqli_query(
            $conn,
            "
            SELECT MAX(CAST(sort_order AS UNSIGNED)) AS max_sort
            FROM {$post_gl_table}
            "
        );

        if ($max_sort_res) {

            $max_sort_row =
                mysqli_fetch_assoc($max_sort_res);

            if (
                $max_sort_row &&
                $max_sort_row['max_sort'] !== null
            ) {
                $next_sort =
                    ((int) $max_sort_row['max_sort']) + 1;
            }

            mysqli_free_result($max_sort_res);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET EXISTING DESCRIPTION INFO
    |--------------------------------------------------------------------------
    |
    | Returns:
    |   sort_order
    |   prefix
    |
    */

    $get_existing_desc_info = function ($desc)
        use ($conn, $post_gl_table) {

        $info = [
            'sort_order' => null,
            'prefix' => null
        ];

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT sort_order, gl_id
            FROM {$post_gl_table}
            WHERE description = ?
            ORDER BY id ASC
            LIMIT 1
            "
        );

        if (!$stmt) {
            return $info;
        }

        mysqli_stmt_bind_param(
            $stmt,
            's',
            $desc
        );

        mysqli_stmt_execute($stmt);

        $existing_sort_order = null;
        $existing_gl_id = null;

        mysqli_stmt_bind_result(
            $stmt,
            $existing_sort_order,
            $existing_gl_id
        );

        if (mysqli_stmt_fetch($stmt)) {

            $info['sort_order'] =
                $existing_sort_order;

            if (
                $existing_gl_id !== null &&
                $existing_gl_id !== ''
            ) {

                $parts =
                    explode('-', $existing_gl_id);

                $info['prefix'] =
                    $parts[0] ?? null;
            }
        }

        mysqli_stmt_close($stmt);

        return $info;
    };

    /*
    |--------------------------------------------------------------------------
    | GET MAX SUB ORDER
    |--------------------------------------------------------------------------
    */

    $get_max_sub_order = function ($desc)
        use ($conn, $post_gl_table) {

        $value = 0;

        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT MAX(CAST(sub_order AS UNSIGNED)) AS max_sub
            FROM {$post_gl_table}
            WHERE description = ?
            "
        );

        if (!$stmt) {
            return $value;
        }

        mysqli_stmt_bind_param(
            $stmt,
            's',
            $desc
        );

        mysqli_stmt_execute($stmt);

        $result =
            mysqli_stmt_get_result($stmt);

        if ($result) {

            $row =
                mysqli_fetch_assoc($result);

            if (
                $row &&
                $row['max_sub'] !== null
            ) {
                $value =
                    (int) $row['max_sub'];
            }

            mysqli_free_result($result);
        }

        mysqli_stmt_close($stmt);

        return $value;
    };

    /*
    |--------------------------------------------------------------------------
    | GENERATE UNIQUE PREFIX
    |--------------------------------------------------------------------------
    */

    $generate_new_prefix = function ($desc)
        use ($conn, $post_gl_table) {

        $clean = strtoupper(
            preg_replace(
                '/[^A-Za-z]/',
                '',
                $desc
            )
        );

        if (strlen($clean) < 3) {
            $clean =
                str_pad(
                    $clean,
                    3,
                    'X'
                );
        }

        /*
        | First attempt:
        | first + other characters
        */

        $chars =
            str_split($clean);

        $len =
            count($chars);

        for ($i = 1; $i < $len - 1; $i++) {

            for ($j = $i + 1; $j < $len; $j++) {

                $prefix =
                    $chars[0] .
                    $chars[$i] .
                    $chars[$j];

                $pattern =
                    $prefix . '-%';

                $check = mysqli_prepare(
                    $conn,
                    "
                    SELECT id
                    FROM {$post_gl_table}
                    WHERE gl_id LIKE ?
                    LIMIT 1
                    "
                );

                if (!$check) {
                    continue;
                }

                mysqli_stmt_bind_param(
                    $check,
                    's',
                    $pattern
                );

                mysqli_stmt_execute($check);

                mysqli_stmt_store_result($check);

                $exists =
                    mysqli_stmt_num_rows($check) > 0;

                mysqli_stmt_close($check);

                if (!$exists) {
                    return $prefix;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback prefix
        |--------------------------------------------------------------------------
        */

        $base =
            substr($clean, 0, 3);

        /*
        |--------------------------------------------------------------------------
        | Make absolutely sure fallback is unique
        |--------------------------------------------------------------------------
        */

        $prefix = $base;
        $counter = 1;

        while (true) {

            $pattern =
                $prefix . '-%';

            $check = mysqli_prepare(
                $conn,
                "
                SELECT id
                FROM {$post_gl_table}
                WHERE gl_id LIKE ?
                LIMIT 1
                "
            );

            if (!$check) {
                break;
            }

            mysqli_stmt_bind_param(
                $check,
                's',
                $pattern
            );

            mysqli_stmt_execute($check);

            mysqli_stmt_store_result($check);

            $exists =
                mysqli_stmt_num_rows($check) > 0;

            mysqli_stmt_close($check);

            if (!$exists) {
                break;
            }

            $prefix =
                substr(
                    $base,
                    0,
                    2
                ) .
                ($counter % 10);

            $counter++;
        }

        return $prefix;
    };

    /*
    |--------------------------------------------------------------------------
    | RESOLVE DESCRIPTION / SUB ORDER / GL ID
    |--------------------------------------------------------------------------
    */

    if (!$has_error) {

        /*
        |--------------------------------------------------------------------------
        | NEW DESCRIPTION
        |--------------------------------------------------------------------------
        */

        if ($desc_type === 'new') {

            $sort_order = $next_sort;
            $sub_order = 1;

            $prefix =
                $generate_new_prefix(
                    $description
                );

            $gl_id =
                $prefix . '-1';

        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING DESCRIPTION
        |--------------------------------------------------------------------------
        */

        else {

            $desc_info =
                $get_existing_desc_info(
                    $description
                );

            $sort_order =
                $desc_info['sort_order'];

            $prefix =
                $desc_info['prefix'];

            /*
            |--------------------------------------------------------------------------
            | Existing description not found
            |--------------------------------------------------------------------------
            */

            if ($sort_order === null) {

                $sort_order =
                    $next_sort;

                $sub_order = 1;

                $prefix =
                    $generate_new_prefix(
                        $description
                    );

                $gl_id =
                    $prefix . '-1';

            }

            /*
            |--------------------------------------------------------------------------
            | Existing description found
            |--------------------------------------------------------------------------
            */

            else {

                /*
                |--------------------------------------------------------------------------
                | NEW ROW DESCRIPTION
                |--------------------------------------------------------------------------
                */

                if ($row_desc_type === 'new') {

                    $sub_order =
                        $get_max_sub_order(
                            $description
                        ) + 1;

                    $gl_id =
                        $prefix .
                        '-' .
                        $sub_order;
                }

                /*
                |--------------------------------------------------------------------------
                | EXISTING ROW DESCRIPTION
                |--------------------------------------------------------------------------
                */

                else {

                    $existing_sub = null;
                    $existing_gl_id = null;

                    $row_stmt = mysqli_prepare(
                        $conn,
                        "
                        SELECT sub_order, gl_id
                        FROM {$post_gl_table}
                        WHERE description = ?
                          AND gl_description_comparative = ?
                        ORDER BY id ASC
                        LIMIT 1
                        "
                    );

                    if ($row_stmt) {

                        mysqli_stmt_bind_param(
                            $row_stmt,
                            'ss',
                            $description,
                            $gl_description_comparative
                        );

                        mysqli_stmt_execute(
                            $row_stmt
                        );

                        mysqli_stmt_bind_result(
                            $row_stmt,
                            $existing_sub,
                            $existing_gl_id
                        );

                        if (
                            mysqli_stmt_fetch(
                                $row_stmt
                            )
                        ) {

                            $sub_order =
                                $existing_sub;

                            $gl_id =
                                $existing_gl_id;
                        }

                        mysqli_stmt_close(
                            $row_stmt
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Existing row description was not found
                    |--------------------------------------------------------------------------
                    */

                    if ($sub_order === null) {

                        $sub_order =
                            $get_max_sub_order(
                                $description
                            ) + 1;

                        $gl_id =
                            $prefix .
                            '-' .
                            $sub_order;
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO-FETCH GL MAPPING
    |--------------------------------------------------------------------------
    |
    | If user selects an existing comparative description,
    | automatically use its existing mapping.
    |--------------------------------------------------------------------------
    */

    if (
        !$has_error &&
        $row_desc_type === 'existing' &&
        $gl_description_comparative !== ''
    ) {

        $map_stmt = mysqli_prepare(
            $conn,
            "
            SELECT gl_mapping
            FROM {$post_gl_table}
            WHERE gl_description_comparative = ?
              AND gl_mapping IS NOT NULL
              AND gl_mapping != ''
            ORDER BY id DESC
            LIMIT 1
            "
        );

        if ($map_stmt) {

            mysqli_stmt_bind_param(
                $map_stmt,
                's',
                $gl_description_comparative
            );

            mysqli_stmt_execute(
                $map_stmt
            );

            $fetched_mapping = null;

            mysqli_stmt_bind_result(
                $map_stmt,
                $fetched_mapping
            );

            if (
                mysqli_stmt_fetch(
                    $map_stmt
                ) &&
                $fetched_mapping !== null
            ) {

                $gl_mapping =
                    strtolower(
                        trim(
                            $fetched_mapping
                        )
                    );
            }

            mysqli_stmt_close(
                $map_stmt
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE GL MAPPING
    |--------------------------------------------------------------------------
    */

    if (
        !$has_error &&
        $gl_mapping !== ''
    ) {

        /*
        | Convert spaces to underscore automatically.
        */

        if (preg_match('/\s/', $gl_mapping)) {

            $status_message =
                'GL Mapping must use lowercase letters and underscores instead of spaces.';

            $status_type = 'error';
            $has_error = true;
        }

        /*
        | Only allow:
        | a-z
        | 0-9
        | underscore
        */

        if (
            !$has_error &&
            !preg_match(
                '/^[a-z0-9_]+$/',
                $gl_mapping
            )
        ) {

            $status_message =
                'GL Mapping may only contain lowercase letters, numbers, and underscores.';

            $status_type = 'error';
            $has_error = true;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK GL MAPPING UNIQUENESS
    |--------------------------------------------------------------------------
    |
    | Same mapping is allowed only if it belongs to the same
    | comparative description.
    |--------------------------------------------------------------------------
    */

    if (
        !$has_error &&
        $gl_mapping !== ''
    ) {

        $check = mysqli_prepare(
            $conn,
            "
            SELECT gl_description_comparative
            FROM {$post_gl_table}
            WHERE gl_mapping = ?
              AND gl_description_comparative <> ?
            LIMIT 1
            "
        );

        if ($check) {

            mysqli_stmt_bind_param(
                $check,
                'ss',
                $gl_mapping,
                $gl_description_comparative
            );

            mysqli_stmt_execute(
                $check
            );

            $existing_gl_desc_comp = '';

            mysqli_stmt_bind_result(
                $check,
                $existing_gl_desc_comp
            );

            if (
                mysqli_stmt_fetch(
                    $check
                )
            ) {

                $status_message =
                    "GL Mapping '{$gl_mapping}' already exists for '{$existing_gl_desc_comp}'.";

                $status_type = 'error';
                $has_error = true;
            }

            mysqli_stmt_close(
                $check
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    if (!$has_error) {

        $insert = mysqli_prepare(
            $conn,
            "
            INSERT INTO {$post_gl_table}
            (
                gl_id,
                sort_order,
                sub_order,
                description,
                gl_description_comparative,
                gl_code,
                gl_description,
                gl_mapping
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            "
        );

        if (!$insert) {

            $status_message =
                'Unable to prepare insert statement: ' .
                mysqli_error($conn);

            $status_type = 'error';

        } else {

            mysqli_stmt_bind_param(
                $insert,
                'siisssss',
                $gl_id,
                $sort_order,
                $sub_order,
                $description,
                $gl_description_comparative,
                $gl_code,
                $gl_description,
                $gl_mapping
            );

            if (
                !mysqli_stmt_execute(
                    $insert
                )
            ) {

                $status_message =
                    'Failed to save row: ' .
                    mysqli_stmt_error($insert);

                $status_type = 'error';

            } else {

                $status_message =
                    'Saved successfully.';

                $status_type =
                    'success';
            }

            mysqli_stmt_close(
                $insert
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FLASH + REDIRECT
    |--------------------------------------------------------------------------
    */

    $_SESSION['flash_message'] =
        $status_message;

    $_SESSION['flash_type'] =
        $status_type;

    header(
        "Location: {$redirect_url}"
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| DISPLAY DATA
|--------------------------------------------------------------------------
*/

$desc_list = [];
$grouped = [];
$sub_orders = [];
$sub_order_map = [];
$gl_id_map = [];

$distinct_desc_res = null;
$distinct_comp_res = null;

$distinct_rows = [];

$error_message = null;

/*
|--------------------------------------------------------------------------
| LOAD TABLE
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | Verify required columns
    |--------------------------------------------------------------------------
    */

    $required_columns = [
        'id',
        'gl_id',
        'sort_order',
        'sub_order',
        'description',
        'gl_description_comparative',
        'gl_code',
        'gl_description',
        'gl_mapping'
    ];

    foreach ($required_columns as $column) {

        $column_check = mysqli_query(
            $conn,
            "
            SHOW COLUMNS
            FROM {$gl_table}
            LIKE '" . mysqli_real_escape_string(
                $conn,
                $column
            ) . "'
            "
        );

        if (
            !$column_check ||
            mysqli_num_rows($column_check) === 0
        ) {

            throw new Exception(
                "Required column '{$column}' does not exist in {$gl_table}."
            );
        }

        mysqli_free_result(
            $column_check
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Main table query
    |--------------------------------------------------------------------------
    */

    $query = "
        SELECT
            id,
            gl_id,
            sort_order,
            sub_order,
            description,
            gl_description_comparative,
            gl_code,
            gl_description,
            gl_mapping
        FROM {$gl_table}
        ORDER BY
            CAST(sort_order AS UNSIGNED),
            CAST(sub_order AS UNSIGNED),
            id
    ";

    $result =
        mysqli_query(
            $conn,
            $query
        );

    if (!$result) {

        throw new Exception(
            mysqli_error($conn)
        );
    }

    $rows = [];

    while (
        $row =
        mysqli_fetch_assoc($result)
    ) {

        $rows[] = $row;
    }

    mysqli_free_result($result);

    /*
    |--------------------------------------------------------------------------
    | GROUP ROWS
    |--------------------------------------------------------------------------
    */

    $grouped = [];
    $main_order = [];
    $sub_orders = [];
    $sub_order_map = [];
    $gl_id_map = [];

    foreach ($rows as $row) {

        $desc =
            $row['description'] ?? '';

        $comp =
            $row['gl_description_comparative'] ?? '';

        /*
        | Group by Description + Comparative Description
        */

        $grouped[$desc][$comp][] =
            $row;

        /*
        | Store sub order
        */

        if (
            !isset(
                $sub_order_map[$desc][$comp]
            )
        ) {

            $sub_order_map[$desc][$comp] =
                $row['sub_order'] ?? '';
        }

        /*
        | Store GL ID
        */

        if (
            !isset(
                $gl_id_map[$desc][$comp]
            )
        ) {

            $gl_id_map[$desc][$comp] =
                $row['gl_id'] ?? '';
        }

        /*
        | Main description order
        */

        if (
            !array_key_exists(
                $desc,
                $main_order
            )
        ) {

            $main_order[$desc] =
                is_numeric(
                    $row['sort_order'] ?? null
                )
                    ? (int) $row['sort_order']
                    : PHP_INT_MAX;
        }

        /*
        | Initialize comparative list
        */

        if (
            !isset(
                $sub_orders[$desc]
            )
        ) {

            $sub_orders[$desc] = [];
        }

        /*
        | Add comparative description only once
        */

        if (
            !in_array(
                $comp,
                $sub_orders[$desc],
                true
            )
        ) {

            $sub_orders[$desc][] =
                $comp;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SORT MAIN DESCRIPTIONS
    |--------------------------------------------------------------------------
    */

    $desc_list =
        array_keys(
            $main_order
        );

    usort(
        $desc_list,
        function ($a, $b)
            use ($main_order) {

            return (
                $main_order[$a]
                <=>
                $main_order[$b]
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | DISTINCT DESCRIPTIONS
    |--------------------------------------------------------------------------
    */

    $distinct_desc_query = "
        SELECT
            MIN(sort_order) AS sort_order,
            description
        FROM {$gl_table}
        WHERE description IS NOT NULL
          AND description != ''
        GROUP BY description
        ORDER BY
            CAST(MIN(sort_order) AS UNSIGNED),
            description
    ";

    $distinct_desc_res =
        mysqli_query(
            $conn,
            $distinct_desc_query
        );

    /*
    |--------------------------------------------------------------------------
    | DISTINCT COMPARATIVE DESCRIPTIONS
    |--------------------------------------------------------------------------
    */

    $distinct_comp_query = "
        SELECT
            MIN(sort_order) AS sort_order,
            MIN(sub_order) AS sub_order,
            gl_description_comparative
        FROM {$gl_table}
        WHERE gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
        GROUP BY gl_description_comparative
        ORDER BY
            CAST(MIN(sort_order) AS UNSIGNED),
            CAST(MIN(sub_order) AS UNSIGNED),
            gl_description_comparative
    ";

    $distinct_comp_res =
        mysqli_query(
            $conn,
            $distinct_comp_query
        );

    /*
    |--------------------------------------------------------------------------
    | DISTINCT DESCRIPTION + COMPARATIVE PAIRS
    |--------------------------------------------------------------------------
    */

    $distinct_rows_query = "
        SELECT
            description,
            gl_description_comparative
        FROM {$gl_table}
        WHERE description IS NOT NULL
          AND description != ''
          AND gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
        GROUP BY
            description,
            gl_description_comparative
        ORDER BY
            description,
            gl_description_comparative
    ";

    $distinct_rows_res =
        mysqli_query(
            $conn,
            $distinct_rows_query
        );

    if ($distinct_rows_res) {

        while (
            $drow =
            mysqli_fetch_assoc(
                $distinct_rows_res
            )
        ) {

            $distinct_rows[] =
                $drow;
        }

        mysqli_free_result(
            $distinct_rows_res
        );
    }

} catch (Throwable $e) {

    $error_message =
        'Error: ' .
        $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| MAPPING LOOKUP
|--------------------------------------------------------------------------
*/

$mapping_lookup = [];

$lookup_query = "
    SELECT
        gl_description_comparative,
        gl_mapping
    FROM {$gl_table}
    WHERE gl_description_comparative IS NOT NULL
      AND gl_description_comparative != ''
    ORDER BY id DESC
";

$lookup_res =
    mysqli_query(
        $conn,
        $lookup_query
    );

if ($lookup_res) {

    while (
        $row =
        mysqli_fetch_assoc($lookup_res)
    ) {

        $comp =
            $row['gl_description_comparative'];

        /*
        | Keep the latest mapping.
        */

        if (
            !array_key_exists(
                $comp,
                $mapping_lookup
            )
        ) {

            $mapping_lookup[$comp] =
                $row['gl_mapping'] ?? '';
        }
    }

    mysqli_free_result(
        $lookup_res
    );
}

$mapping_json =
    json_encode(
        $mapping_lookup,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    );

/*
|--------------------------------------------------------------------------
| DESCRIPTION -> COMPARATIVE DESCRIPTIONS
|--------------------------------------------------------------------------
*/

$comps_by_desc = [];

$comp_by_desc_query = "
    SELECT
        description,
        gl_description_comparative
    FROM {$gl_table}
    WHERE description IS NOT NULL
      AND description != ''
      AND gl_description_comparative IS NOT NULL
      AND gl_description_comparative != ''
    GROUP BY
        description,
        gl_description_comparative
    ORDER BY
        CAST(MIN(sort_order) AS UNSIGNED),
        CAST(MIN(sub_order) AS UNSIGNED),
        description,
        gl_description_comparative
";

$comp_by_desc_res =
    mysqli_query(
        $conn,
        $comp_by_desc_query
    );

if ($comp_by_desc_res) {

    while (
        $row =
        mysqli_fetch_assoc(
            $comp_by_desc_res
        )
    ) {

        $d =
            $row['description'];

        $c =
            $row['gl_description_comparative'];

        if (
            !isset(
                $comps_by_desc[$d]
            )
        ) {

            $comps_by_desc[$d] = [];
        }

        if (
            !in_array(
                $c,
                $comps_by_desc[$d],
                true
            )
        ) {

            $comps_by_desc[$d][] =
                $c;
        }
    }

    mysqli_free_result(
        $comp_by_desc_res
    );
}

$comps_by_desc_json =
    json_encode(
        $comps_by_desc,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    );

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Financial Statement
    </title>

    <link
        rel="icon"
        href="../images/MLW%20Logo.png"
        type="image/png"
    />

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="css/fs_reports.css?v=<?= time(); ?>"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    >


</head>

<body>

<main class="main-content">

    <!-- ==========================================================
         TOP BAR
         ========================================================== -->

    <header class="top-bar">

        <h2>

            <a
                href="user_dashboard.php"
                style="
                    font-size: 16px;
                    text-decoration: none;
                "
            >
                ⬅ Back
            </a>

        </h2>

        <div class="user-badge">

            <span>
                <?= htmlspecialchars($username) ?>
                (<?= htmlspecialchars($user_type) ?>)
            </span>

            <div class="avatar">

                <?= strtoupper(
                    substr(
                        $full_name,
                        0,
                        1
                    )
                ) ?>

            </div>

        </div>

    </header>


    <div class="content-wrapper">

        <!-- ======================================================
             TITLE
             ====================================================== -->

        <h2
            style="
                text-align: center;
                margin-top: -2%;
            "
        >
            List of GL Codes (for HO and Cumulative Report)
        </h2>


        <!-- ======================================================
             TOOLBAR
             ====================================================== -->

        <div class="gl-settings-toolbar">

            <div class="gl-toggle">

                <a
                    href="fs_reports.php?gl_type=old"
                    class="<?= $gl_type === 'old' ? 'active' : '' ?>"
                >
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Old GL Code
                </a>

                <a
                    href="fs_reports.php?gl_type=new"
                    class="<?= $gl_type === 'new' ? 'active' : '' ?>"
                >
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    New GL Code
                </a>

            </div>


            <button
                id="openModalBtn"
                class="btn-add"
                type="button"
            >
                <i class="fas fa-plus"></i>
                Add Row
            </button>


            <input
                type="text"
                id="glSearchInput"
                class="gl-search-input"
                placeholder="Search description, row description, GL code, GL description, mapping"
                autocomplete="off"
            >

        </div>


        <!-- ======================================================
             TABLE TITLE
             ====================================================== -->

        <h3
            style="
                text-align: center;
            "
        >
            <?= htmlspecialchars($gl_title) ?>s
        </h3>


        <!-- ======================================================
             TABLE
             ====================================================== -->

        <section class="table-container">

            <?php if ($error_message !== null): ?>

                <p style="color: red;">
                    <?= htmlspecialchars($error_message) ?>
                </p>

            <?php else: ?>

                <table
                    class="gl-table"
                    id="glTable"
                >

                    <thead>

                        <tr>

                            <th>
                                <i class="fa-solid fa-up-down-left-right"></i>
                                Drag
                            </th>

                            <th>
                                <i class="fa-solid fa-hashtag"></i>
                                GL ID
                            </th>

                            <th>
                                <i class="fa-solid fa-sort-numeric-down"></i>
                                Sort Order
                            </th>

                            <th>
                                <i class="fa-solid fa-file-lines"></i>
                                Description
                            </th>

                            <th>
                                <i class="fa-solid fa-arrow-down-short-wide"></i>
                                Sub Order
                            </th>

                            <th>
                                <i class="fa-solid fa-chart-column"></i>
                                Comparative Report Description
                            </th>

                            <th>
                                <i class="fa-solid fa-barcode"></i>
                                GL Code
                            </th>

                            <th>
                                <i class="fa-solid fa-book"></i>
                                GL Description
                            </th>

                            <th>
                                <i class="fa-solid fa-link"></i>
                                GL Mapping/Shortcut
                            </th>

                            <th>
                                <i class="fa-solid fa-gears"></i>
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php

                    $group_counter = 0;

                    foreach ($desc_list as $desc):

                        $show_header =
                            ($desc !== '');

                        if ($show_header) {
                            $group_counter++;
                        }

                        $subgroup_index = 0;

                        foreach (
                            $sub_orders[$desc]
                            as $comp
                        ):

                            $subgroup_index++;

                            $sub_order_str =
                                $sub_order_map[$desc][$comp]
                                ?? '';

                            $gl_id_str =
                                $gl_id_map[$desc][$comp]
                                ?? '';

                            $sub_rows =
                                $grouped[$desc][$comp]
                                ?? [];

                            /*
                            | Sort rows by ID.
                            */

                            usort(
                                $sub_rows,
                                function (
                                    $a,
                                    $b
                                ) {

                                    return (
                                        ($a['id'] ?? 0)
                                        <=>
                                        ($b['id'] ?? 0)
                                    );
                                }
                            );

                            foreach (
                                $sub_rows
                                as $sidx => $row
                            ):

                                $is_first =
                                    ($sidx === 0);

                                $show_sub_order =
                                    $is_first
                                        ? $sub_order_str
                                        : '';

                                $show_gl_id =
                                    $is_first
                                        ? $gl_id_str
                                        : '';

                                $show_drag =
                                    $is_first &&
                                    $show_header;

                                $group_key =
                                    $desc .
                                    '||' .
                                    $comp;

                    ?>

                    <tr
                        data-id="<?= htmlspecialchars($row['id']) ?>"
                        data-description="<?= htmlspecialchars($desc) ?>"
                        data-glcomp="<?= htmlspecialchars($comp) ?>"
                        data-glmap="<?= htmlspecialchars($row['gl_mapping'] ?? '') ?>"
                        data-sortorder="<?= htmlspecialchars($row['sort_order'] ?? '') ?>"
                        data-suborder="<?= htmlspecialchars($row['sub_order'] ?? '') ?>"
                        data-group="<?= htmlspecialchars($group_key) ?>"
                    >

                        <!-- Drag -->

                        <td class="drag-cell">

                            <?php if ($show_drag): ?>

                                <span
                                    class="drag-handle"
                                    title="Drag to reorder"
                                >
                                    <i
                                        class="fa fa-arrows"
                                        aria-hidden="true"
                                    ></i>
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- GL ID -->

                        <td
                            style="
                                text-align: center;
                                color: #000000;
                            "
                        >
                            <?= htmlspecialchars($show_gl_id) ?>
                        </td>


                        <!-- Sort Order -->

                        <td></td>


                        <!-- Description -->

                        <td></td>


                        <!-- Sub Order -->

                        <td
                            style="
                                text-align: center;
                                color: #000000;
                            "
                        >
                            <?= htmlspecialchars($show_sub_order) ?>
                        </td>


                        <!-- Comparative Description -->

                        <td>
                            <?= htmlspecialchars($comp) ?>
                        </td>


                        <!-- GL Code -->

                        <td>
                            <strong>
                                <?= htmlspecialchars(
                                    $row['gl_code'] ?? ''
                                ) ?>
                            </strong>
                        </td>


                        <!-- GL Description -->

                        <td>
                            <?= htmlspecialchars(
                                $row['gl_description'] ?? ''
                            ) ?>
                        </td>


                        <!-- GL Mapping -->

                        <td>
                            <?= htmlspecialchars(
                                $row['gl_mapping'] ?? ''
                            ) ?>
                        </td>


                        <!-- Action -->

                        <td
                            style="
                                text-align: center;
                            "
                        >

                            <button
                                type="button"
                                class="btn-delete-row"
                                data-id="<?= htmlspecialchars($row['id']) ?>"
                                data-table="<?= htmlspecialchars($gl_type) ?>"
                            >

                                <i
                                    class="fa-solid fa-trash-can"
                                    style="color: white;"
                                ></i>

                            </button>

                        </td>

                    </tr>

                    <?php

                            endforeach;

                        endforeach;


                        /*
                        |--------------------------------------------------------------------------
                        | CATEGORY HEADER
                        |--------------------------------------------------------------------------
                        */

                        if ($show_header):

                    ?>

                    <tr
                        class="category-header-row"
                    >

                        <td class="drag-cell"></td>

                        <td></td>

                        <td
                            style="
                                text-align: center;
                                font-weight: bold;
                            "
                        >
                            <?= $group_counter ?>
                        </td>

                        <td
                            style="
                                text-align: center;
                                font-weight: bold;
                                color: #2c3e50;
                                letter-spacing: 1px;
                            "
                        >
                            <?= htmlspecialchars($desc) ?>
                        </td>

                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>

                    </tr>

                    <?php

                        endif;

                    endforeach;

                    ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </section>


        <!-- ======================================================
             REPORT LINKS
             ====================================================== -->

        <div
            style="
                margin-top: 20px;
                text-align: right;
                display: flex;
                gap: 10px;
                justify-content: center;
            "
        >

        <a
                href="comparative_report_original_page_four.php"
                style="text-decoration: none;"
                class="btn-preview"
            >
                <i class="fa-solid fa-file"></i>
                Consolidated Report (Page 4)
            </a>


            <a
                href="comparative_report_original_ho_with_past_and_adjustment.php"
                style="text-decoration: none;"
                class="btn-preview"
            >
                <i class="fa-solid fa-file"></i>
                Comparative Report (with HO allocated - Page 5)
            </a>


            <a
                href="comparative_report_original_cumu.php"
                style="text-decoration: none;"
                class="btn-preview"
            >
                <i class="fa-solid fa-file"></i>
                Cumulative Report
            </a>

        </div>

    </div>

</main>


<!-- ============================================================
     ADD ROW MODAL
     ============================================================ -->

<div
    id="glModal"
    class="modal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h3>
                Add New Row
            </h3>

            <span class="close-btn">
                &times;
            </span>

        </div>


        <form
            id="addGlForm"
            method="post"
            action=""
        >

            <input
                type="hidden"
                name="gl_type"
                value="<?= htmlspecialchars($gl_type) ?>"
            >


            <!-- Description -->

            <div class="form-section">

                <label>
                    Description
                </label>

                <div class="radio-group">

                    <label>

                        <input
                            type="radio"
                            name="desc_type"
                            value="new"
                            checked
                        >

                        Create new description

                    </label>


                    <label>

                        <input
                            type="radio"
                            name="desc_type"
                            value="existing"
                        >

                        Use existing description

                    </label>

                </div>


                <div id="desc_input_container">

                    <input
                        type="text"
                        name="description"
                        placeholder="Enter new description"
                        required
                    >

                </div>

            </div>


            <!-- Comparative Description -->

            <div class="form-section">

                <label>
                    Row Description
                    (Comparative Report Description)
                </label>

                <div class="radio-group">

                    <label>

                        <input
                            type="radio"
                            name="row_desc_type"
                            value="new"
                            checked
                        >

                        Create new row description

                    </label>


                    <label>

                        <input
                            type="radio"
                            name="row_desc_type"
                            value="existing"
                        >

                        Use existing row description

                    </label>

                </div>


                <div id="row_desc_input_container">

                    <input
                        type="text"
                        name="gl_description_comparative"
                        placeholder="Enter new row description"
                        required
                    >

                </div>

            </div>


            <!-- GL Code -->

            <div class="form-section">

                <label>
                    GL Code
                </label>

                <input
                    type="text"
                    name="gl_code"
                >

            </div>


            <!-- GL Description -->

            <div class="form-section">

                <label>
                    GL Description
                </label>

                <input
                    type="text"
                    name="gl_description"
                >

            </div>


            <!-- GL Mapping -->

            <div class="form-section">

                <label>
                    GL Mapping/Shortcut
                </label>

                <input
                    type="text"
                    name="gl_mapping"
                    placeholder="Example: vehicle_loans"
                >

                <small>
                    The combination of Row Description +
                    GL Mapping must be unique.
                    The same GL Mapping cannot be used
                    for different Row Descriptions.
                </small>

            </div>


            <!-- Footer -->

            <div class="modal-footer">

                <a
                    href="fs_reports.php?gl_type=<?= urlencode($gl_type) ?>"
                    class="btn-cancel"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="btn-save"
                >
                    Save
                </button>

            </div>

        </form>

    </div>

</div>


<!-- ============================================================
     STATUS MODAL
     ============================================================ -->

<div
    id="statusModal"
    class="status-modal"
    aria-hidden="true"
>

    <div class="status-modal-content">

        <div class="status-modal-header">

            <h3 id="statusModalTitle">
                Notice
            </h3>

            <span class="status-close-btn">
                &times;
            </span>

        </div>


        <div
            class="status-modal-body"
            id="statusModalBody"
        ></div>


        <div class="status-modal-footer">

            <button
                type="button"
                class="btn-ok"
                id="statusOkBtn"
            >
                OK
            </button>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| PHP -> JAVASCRIPT
|--------------------------------------------------------------------------
*/

const currentGlType =
    <?= json_encode($gl_type) ?>;

const mappingData =
    <?= $mapping_json ?: '{}' ?>;

const compsByDesc =
    <?= $comps_by_desc_json ?: '{}' ?>;


/*
|--------------------------------------------------------------------------
| MODAL
|--------------------------------------------------------------------------
*/

const modal =
    document.getElementById('glModal');

const btn =
    document.getElementById('openModalBtn');

const closeBtn =
    document.querySelector('.close-btn');

if (btn) {

    btn.addEventListener(
        'click',
        () => {
            modal.style.display = 'flex';
        }
    );
}

if (closeBtn) {

    closeBtn.addEventListener(
        'click',
        () => {
            modal.style.display = 'none';
        }
    );
}


/*
|--------------------------------------------------------------------------
| CLOSE MODAL WHEN CLICKING OUTSIDE
|--------------------------------------------------------------------------
*/

window.addEventListener(
    'click',
    (event) => {

        if (event.target === modal) {
            modal.style.display = 'none';
        }

    }
);


/*
|--------------------------------------------------------------------------
| DESCRIPTION TOGGLE
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        'input[name="desc_type"]'
    )
    .forEach(radio => {

        radio.addEventListener(
            'change',
            function () {

                const rowDescRadio =
                    document.querySelector(
                        'input[name="row_desc_type"]:checked'
                    );

                const rowDescType =
                    rowDescRadio
                        ? rowDescRadio.value
                        : 'new';

                /*
                |--------------------------------------------------------------------------
                | New description cannot use existing row description
                |--------------------------------------------------------------------------
                */

                if (
                    this.value === 'new' &&
                    rowDescType === 'existing'
                ) {

                    alert(
                        'You cannot use existing row description for a new description.'
                    );

                    const rowNewRadio =
                        document.querySelector(
                            'input[name="row_desc_type"][value="new"]'
                        );

                    if (rowNewRadio) {

                        rowNewRadio.checked =
                            true;

                        rowNewRadio.dispatchEvent(
                            new Event('change')
                        );
                    }

                    return;
                }


                const container =
                    document.getElementById(
                        'desc_input_container'
                    );


                /*
                |--------------------------------------------------------------------------
                | Existing description
                |--------------------------------------------------------------------------
                */

                if (this.value === 'existing') {

                    container.innerHTML = `
                        <select
                            name="description"
                            id="existing_desc"
                            required
                        >
                            <option value="">
                                Select Existing...
                            </option>

                            <?php

                            if ($distinct_desc_res) {

                                mysqli_data_seek(
                                    $distinct_desc_res,
                                    0
                                );

                                while (
                                    $d =
                                    mysqli_fetch_assoc(
                                        $distinct_desc_res
                                    )
                                ):

                            ?>

                            <option
                                value="<?= htmlspecialchars(
                                    $d['description'],
                                    ENT_QUOTES
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    $d['description']
                                ) ?>
                            </option>

                            <?php

                                endwhile;
                            }

                            ?>

                        </select>
                    `;


                    const existingDesc =
                        document.getElementById(
                            'existing_desc'
                        );

                    if (existingDesc) {

                        existingDesc.addEventListener(
                            'change',
                            function () {

                                renderExistingRowDescOptions(
                                    this.value
                                );

                            }
                        );
                    }

                }

                /*
                |--------------------------------------------------------------------------
                | New description
                |--------------------------------------------------------------------------
                */

                else {

                    container.innerHTML = `
                        <input
                            type="text"
                            name="description"
                            placeholder="Enter new description"
                            required
                        >
                    `;

                    renderExistingRowDescOptions('');
                }

            }
        );

    });


/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

}


/*
|--------------------------------------------------------------------------
| RENDER EXISTING ROW DESCRIPTION
|--------------------------------------------------------------------------
*/

function renderExistingRowDescOptions(
    selectedDescription = ''
) {

    const container =
        document.getElementById(
            'row_desc_input_container'
        );

    const rowDescRadio =
        document.querySelector(
            'input[name="row_desc_type"]:checked'
        );

    if (
        !container ||
        !rowDescRadio ||
        rowDescRadio.value !== 'existing'
    ) {
        return;
    }


    let optionsHtml = `
        <option value="">
            Select Existing...
        </option>
    `;


    /*
    |--------------------------------------------------------------------------
    | Filter by selected Description
    |--------------------------------------------------------------------------
    */

    if (selectedDescription) {

        const list =
            compsByDesc[selectedDescription] || [];

        const distinctList =
            [...new Set(list)];

        distinctList.forEach(
            value => {

                optionsHtml += `
                    <option value="${escapeHtml(value)}">
                        ${escapeHtml(value)}
                    </option>
                `;

            }
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Show all comparative descriptions
    |--------------------------------------------------------------------------
    */

    else {

        <?php

        if ($distinct_comp_res) {

            mysqli_data_seek(
                $distinct_comp_res,
                0
            );

            while (
                $c =
                mysqli_fetch_assoc(
                    $distinct_comp_res
                )
            ):

        ?>

        optionsHtml += `
            <option value="<?= htmlspecialchars(
                $c['gl_description_comparative'],
                ENT_QUOTES
            ) ?>">
                <?= htmlspecialchars(
                    $c['gl_description_comparative']
                ) ?>
            </option>
        `;

        <?php

            endwhile;
        }

        ?>

    }


    container.innerHTML = `
        <select
            name="gl_description_comparative"
            id="existing_row_desc"
            required
        >
            ${optionsHtml}
        </select>
    `;


    /*
    |--------------------------------------------------------------------------
    | Mapping becomes readonly
    |--------------------------------------------------------------------------
    */

    const mappingInput =
        document.querySelector(
            'input[name="gl_mapping"]'
        );

    if (mappingInput) {

        mappingInput.readOnly = true;

        mappingInput.style.backgroundColor =
            '#e9ecef';

        mappingInput.style.cursor =
            'not-allowed';
    }


    /*
    |--------------------------------------------------------------------------
    | Auto-fill mapping when comparative description changes
    |--------------------------------------------------------------------------
    */

    const dropdown =
        document.getElementById(
            'existing_row_desc'
        );

    if (
        dropdown &&
        mappingInput
    ) {

        dropdown.addEventListener(
            'change',
            function () {

                const selectedVal =
                    this.value;

                mappingInput.value =
                    mappingData[selectedVal] || '';

            }
        );

    }

}


/*
|--------------------------------------------------------------------------
| ROW DESCRIPTION TOGGLE
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        'input[name="row_desc_type"]'
    )
    .forEach(radio => {

        radio.addEventListener(
            'change',
            function () {

                const container =
                    document.getElementById(
                        'row_desc_input_container'
                    );

                const mappingInput =
                    document.querySelector(
                        'input[name="gl_mapping"]'
                    );

                const descRadio =
                    document.querySelector(
                        'input[name="desc_type"]:checked'
                    );

                const descType =
                    descRadio
                        ? descRadio.value
                        : 'new';


                /*
                |--------------------------------------------------------------------------
                | Existing row + new description = invalid
                |--------------------------------------------------------------------------
                */

                if (
                    this.value === 'existing' &&
                    descType === 'new'
                ) {

                    alert(
                        'You cannot use existing row description for a new description.'
                    );

                    const rowNewRadio =
                        document.querySelector(
                            'input[name="row_desc_type"][value="new"]'
                        );

                    if (rowNewRadio) {

                        rowNewRadio.checked =
                            true;

                        rowNewRadio.dispatchEvent(
                            new Event('change')
                        );
                    }

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | Existing comparative description
                |--------------------------------------------------------------------------
                */

                if (
                    this.value === 'existing'
                ) {

                    const descSelect =
                        document.getElementById(
                            'existing_desc'
                        );

                    const selectedDesc =
                        descSelect
                            ? descSelect.value
                            : '';

                    renderExistingRowDescOptions(
                        selectedDesc
                    );

                }

                /*
                |--------------------------------------------------------------------------
                | New comparative description
                |--------------------------------------------------------------------------
                */

                else {

                    container.innerHTML = `
                        <input
                            type="text"
                            name="gl_description_comparative"
                            placeholder="Enter new row description"
                            required
                        >
                    `;

                    if (mappingInput) {

                        mappingInput.value =
                            '';

                        mappingInput.readOnly =
                            false;

                        mappingInput.style.backgroundColor =
                            '';

                        mappingInput.style.cursor =
                            'text';
                    }
                }

            }
        );

    });


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

const tbody =
    document.querySelector(
        '#glTable tbody'
    );

const glSearchInput =
    document.getElementById(
        'glSearchInput'
    );


function applyGlSearchFilter() {

    if (
        !glSearchInput ||
        !tbody
    ) {
        return;
    }


    const q =
        glSearchInput.value
            .trim()
            .toLowerCase();


    const dataRows =
        Array.from(
            tbody.querySelectorAll(
                'tr[data-id]'
            )
        );


    const headerRows =
        Array.from(
            tbody.querySelectorAll(
                'tr.category-header-row'
            )
        );


    /*
    |--------------------------------------------------------------------------
    | Show everything when search is empty
    |--------------------------------------------------------------------------
    */

    if (!q) {

        dataRows.forEach(
            row => {
                row.style.display = '';
            }
        );

        headerRows.forEach(
            row => {
                row.style.display = '';
            }
        );

        return;
    }


    const matchedDescs =
        new Set();


    dataRows.forEach(
        row => {

            const desc =
                row.dataset.description || '';

            const comp =
                row.dataset.glcomp || '';

            const glCode =
                (
                    row.querySelector(
                        'td:nth-child(7)'
                    )?.textContent || ''
                ).trim();

            const glDesc =
                (
                    row.querySelector(
                        'td:nth-child(8)'
                    )?.textContent || ''
                ).trim();

            const glMapping =
                (
                    row.querySelector(
                        'td:nth-child(9)'
                    )?.textContent || ''
                ).trim();


            const haystack =
                `
                ${desc}
                ${comp}
                ${glCode}
                ${glDesc}
                ${glMapping}
                `.toLowerCase();


            const hit =
                haystack.includes(q);


            row.style.display =
                hit ? '' : 'none';


            if (
                hit &&
                desc
            ) {

                matchedDescs.add(
                    desc
                );
            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Show category header only if something under it matched
    |--------------------------------------------------------------------------
    */

    headerRows.forEach(
        header => {

            const descCell =
                header.querySelector(
                    'td:nth-child(4)'
                );

            const desc =
                descCell
                    ? descCell.textContent.trim()
                    : '';


            header.style.display =
                matchedDescs.has(desc)
                    ? ''
                    : 'none';

        }
    );

}


if (glSearchInput) {

    glSearchInput.addEventListener(
        'input',
        applyGlSearchFilter
    );

}


/*
|--------------------------------------------------------------------------
| DRAG AND DROP
|--------------------------------------------------------------------------
*/

let draggingGroupKey = null;
let draggingDescription = null;

let initialOrderIds = null;
let initialGroupOrderSignature = null;


function getGroupOrderSignature() {

    if (!tbody) {
        return '';
    }

    const rows =
        Array.from(
            tbody.querySelectorAll(
                'tr[data-id]'
            )
        );

    const seen =
        new Set();

    const order = [];


    rows.forEach(
        row => {

            const group =
                row.dataset.group || '';

            if (!seen.has(group)) {

                seen.add(group);

                order.push(group);
            }

        }
    );


    return order.join('|');

}


function getDataRowIds() {

    if (!tbody) {
        return [];
    }

    return Array.from(
        tbody.querySelectorAll(
            'tr[data-id]'
        )
    ).map(
        row => row.dataset.id
    );

}


function getGroupRows(
    groupKey
) {

    if (!tbody) {
        return [];
    }

    return Array.from(
        tbody.querySelectorAll(
            'tr[data-id]'
        )
    ).filter(
        row =>
            row.dataset.group === groupKey
    );

}


/*
|--------------------------------------------------------------------------
| RESTORE ORDER
|--------------------------------------------------------------------------
*/

function restoreRowOrder(ids) {

    if (
        !tbody ||
        !Array.isArray(ids) ||
        ids.length === 0
    ) {
        return;
    }


    const rowMap =
        new Map();


    tbody
        .querySelectorAll(
            'tr[data-id]'
        )
        .forEach(
            row => {

                rowMap.set(
                    String(row.dataset.id),
                    row
                );

            }
        );


    ids.forEach(
        id => {

            const row =
                rowMap.get(
                    String(id)
                );

            if (row) {
                tbody.appendChild(row);
            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Reposition category headers
    |--------------------------------------------------------------------------
    */

    const headerRows =
        Array.from(
            tbody.querySelectorAll(
                'tr.category-header-row'
            )
        );


    headerRows.forEach(
        header => {

            const descCell =
                header.querySelector(
                    'td:nth-child(4)'
                );

            const desc =
                descCell
                    ? descCell.textContent.trim()
                    : '';


            if (!desc) {
                return;
            }


            const dataRows =
                Array.from(
                    tbody.querySelectorAll(
                        'tr[data-id]'
                    )
                ).filter(
                    row =>
                        row.dataset.description === desc
                );


            if (
                dataRows.length === 0
            ) {
                return;
            }


            const last =
                dataRows[
                    dataRows.length - 1
                ];


            tbody.insertBefore(
                header,
                last.nextSibling
            );

        }
    );

}


/*
|--------------------------------------------------------------------------
| FIND TARGET GROUP
|--------------------------------------------------------------------------
*/

function getTargetGroupElement(
    container,
    y,
    draggingGroup,
    draggingDesc
) {

    const rows =
        [
            ...container.querySelectorAll(
                'tr[data-id]:not(.dragging)'
            )
        ].filter(
            row =>
                row.dataset.group !== draggingGroup &&
                row.dataset.description === draggingDesc
        );


    if (
        rows.length === 0
    ) {
        return null;
    }


    let closest = null;
    let closestDist = Infinity;


    rows.forEach(
        row => {

            const box =
                row.getBoundingClientRect();

            const center =
                box.top +
                box.height / 2;

            const dist =
                Math.abs(
                    y - center
                );


            if (
                dist < closestDist
            ) {

                closestDist = dist;

                closest = row;
            }

        }
    );


    if (!closest) {
        return null;
    }


    const targetGroup =
        closest.dataset.group;


    const targetRows =
        getGroupRows(
            targetGroup
        );


    if (
        targetRows.length === 0
    ) {
        return null;
    }


    const first =
        targetRows[0];

    const last =
        targetRows[
            targetRows.length - 1
        ];


    const lastBox =
        last.getBoundingClientRect();


    const insertAfter =
        y >
        (
            lastBox.top +
            lastBox.height / 2
        );


    return {
        first,
        last,
        insertAfter
    };

}


/*
|--------------------------------------------------------------------------
| ENABLE DRAGGING
|--------------------------------------------------------------------------
*/

if (tbody) {

    tbody
        .querySelectorAll(
            'tr[data-id]'
        )
        .forEach(
            row => {

                const handle =
                    row.querySelector(
                        '.drag-handle'
                    );


                if (!handle) {
                    return;
                }


                handle.setAttribute(
                    'draggable',
                    'true'
                );


                handle.addEventListener(
                    'dragstart',
                    event => {

                        draggingGroupKey =
                            row.dataset.group || '';

                        draggingDescription =
                            row.dataset.description || '';


                        initialOrderIds =
                            getDataRowIds();

                        initialGroupOrderSignature =
                            getGroupOrderSignature();


                        getGroupRows(
                            draggingGroupKey
                        ).forEach(
                            groupRow => {

                                groupRow.classList.add(
                                    'dragging'
                                );

                            }
                        );


                        event.dataTransfer.effectAllowed =
                            'move';

                    }
                );


                handle.addEventListener(
                    'dragend',
                    () => {

                        getGroupRows(
                            draggingGroupKey
                        ).forEach(
                            groupRow => {

                                groupRow.classList.remove(
                                    'dragging'
                                );

                            }
                        );


                        draggingGroupKey = null;

                        draggingDescription = null;

                    }
                );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | DRAG OVER
    |--------------------------------------------------------------------------
    */

    tbody.addEventListener(
        'dragover',
        event => {

            event.preventDefault();

            if (!draggingGroupKey) {
                return;
            }


            const groupRows =
                getGroupRows(
                    draggingGroupKey
                );


            if (
                groupRows.length === 0
            ) {
                return;
            }


            const target =
                getTargetGroupElement(
                    tbody,
                    event.clientY,
                    draggingGroupKey,
                    draggingDescription
                );


            if (!target) {
                return;
            }


            if (target.insertAfter) {

                groupRows.forEach(
                    row => {

                        tbody.insertBefore(
                            row,
                            target.last.nextSibling
                        );

                    }
                );

            } else {

                groupRows.forEach(
                    row => {

                        tbody.insertBefore(
                            row,
                            target.first
                        );

                    }
                );

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | DROP
    |--------------------------------------------------------------------------
    */

    tbody.addEventListener(
        'drop',
        () => {

            if (!draggingGroupKey) {
                return;
            }


            const currentSignature =
                getGroupOrderSignature();


            if (
                !initialGroupOrderSignature ||
                currentSignature ===
                    initialGroupOrderSignature
            ) {
                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Confirm
            |--------------------------------------------------------------------------
            */

            if (
                !confirm(
                    'Save New Order?'
                )
            ) {

                restoreRowOrder(
                    initialOrderIds
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Build order data
            |--------------------------------------------------------------------------
            */

            const dataRows =
                Array.from(
                    tbody.querySelectorAll(
                        'tr[data-id]'
                    )
                );


            const orderData = [];


            let currentDesc = null;
            let subCounter = 0;

            const seenGroups =
                new Set();


            dataRows.forEach(
                row => {

                    const desc =
                        row.dataset.description || '';

                    const comp =
                        row.dataset.glcomp || '';

                    const id =
                        row.dataset.id;


                    /*
                    |--------------------------------------------------------------------------
                    | Reset counter when Description changes
                    |--------------------------------------------------------------------------
                    */

                    if (
                        desc !== currentDesc
                    ) {

                        currentDesc =
                            desc;

                        subCounter = 0;

                        seenGroups.clear();
                    }


                    const groupKey =
                        `${desc}||${comp}`;


                    /*
                    |--------------------------------------------------------------------------
                    | Increase sub order only once per
                    | Description + Comparative Description
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !seenGroups.has(
                            groupKey
                        )
                    ) {

                        subCounter++;

                        seenGroups.add(
                            groupKey
                        );
                    }


                    orderData.push({

                        id: id,

                        description: desc,

                        gl_description_comparative:
                            comp,

                        sub_order:
                            subCounter

                    });

                }
            );


            /*
            |--------------------------------------------------------------------------
            | SAVE
            |--------------------------------------------------------------------------
            */

            fetch(
                'save_sub_order_fs_ho.php',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body: JSON.stringify({
                        orderData,
                        gl_type:
                            currentGlType
                    })
                }
            )

            .then(
                response => {

                    if (!response.ok) {
                        throw new Error(
                            'HTTP error ' +
                            response.status
                        );
                    }

                    return response.json();

                }
            )

            .then(
                data => {

                    if (data.ok) {

                        showStatusModal(
                            'Order saved successfully!',
                            'success',
                            true
                        );

                    } else {

                        showStatusModal(
                            data.error ||
                            'Failed to save order.',
                            'error'
                        );

                    }

                }
            )

            .catch(
                error => {

                    console.error(
                        error
                    );

                    showStatusModal(
                        'Failed to save order.',
                        'error'
                    );

                }
            );

        }
    );

}


/*
|--------------------------------------------------------------------------
| DELETE ROW
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        '.btn-delete-row'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    const id =
                        button.dataset.id;


                    if (!id) {
                        return;
                    }


                    if (
                        !confirm(
                            'Delete this row?'
                        )
                    ) {
                        return;
                    }


                    fetch(
                        'delete_gl_row_fs_ho.php',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json'
                            },

                            body: JSON.stringify({
                                id: id,
                                gl_type:
                                    currentGlType
                            })
                        }
                    )

                    .then(
                        response => {

                            if (!response.ok) {
                                throw new Error(
                                    'HTTP error ' +
                                    response.status
                                );
                            }

                            return response.json();

                        }
                    )

                    .then(
                        data => {

                            if (data.ok) {

                                showStatusModal(
                                    'Deleted successfully.',
                                    'success',
                                    true
                                );

                            } else {

                                showStatusModal(
                                    data.error ||
                                    'Delete failed.',
                                    'error'
                                );

                            }

                        }
                    )

                    .catch(
                        error => {

                            console.error(
                                error
                            );

                            showStatusModal(
                                'Delete failed.',
                                'error'
                            );

                        }
                    );

                }
            );

        }
    );


/*
|--------------------------------------------------------------------------
| GL MAPPING INPUT
|--------------------------------------------------------------------------
*/

const glMappingInput =
    document.querySelector(
        'input[name="gl_mapping"]'
    );

const addGlForm =
    document.getElementById(
        'addGlForm'
    );


if (glMappingInput) {

    glMappingInput.addEventListener(
        'input',
        () => {

            const cursor =
                glMappingInput.selectionStart;


            /*
            | Lowercase
            */

            glMappingInput.value =
                glMappingInput.value
                    .toLowerCase()
                    .replace(/\s+/g, '_');


            /*
            | Only allowed characters
            */

            glMappingInput.value =
                glMappingInput.value
                    .replace(
                        /[^a-z0-9_]/g,
                        ''
                    );


            try {

                glMappingInput.setSelectionRange(
                    cursor,
                    cursor
                );

            } catch (e) {
                // Ignore cursor errors
            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| FORM SUBMIT
|--------------------------------------------------------------------------
*/

if (addGlForm) {

    addGlForm.addEventListener(
        'submit',
        event => {

            const value =
                (
                    glMappingInput?.value ||
                    ''
                ).trim();


            /*
            |--------------------------------------------------------------------------
            | Mapping validation
            |--------------------------------------------------------------------------
            */

            if (
                value !== '' &&
                !/^[a-z0-9_]+$/.test(value)
            ) {

                event.preventDefault();

                showStatusModal(
                    'GL Mapping may only contain lowercase letters, numbers, and underscores.',
                    'error'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Description validation
            |--------------------------------------------------------------------------
            */

            const descInput =
                addGlForm.querySelector(
                    '[name="description"]'
                );


            if (
                descInput &&
                !descInput.value.trim()
            ) {

                event.preventDefault();

                showStatusModal(
                    'Description is required.',
                    'error'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Comparative description validation
            |--------------------------------------------------------------------------
            */

            const compInput =
                addGlForm.querySelector(
                    '[name="gl_description_comparative"]'
                );


            if (
                compInput &&
                !compInput.value.trim()
            ) {

                event.preventDefault();

                showStatusModal(
                    'Comparative Report Description is required.',
                    'error'
                );

                return;
            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| STATUS MODAL
|--------------------------------------------------------------------------
*/

const statusModal =
    document.getElementById(
        'statusModal'
    );

const statusModalTitle =
    document.getElementById(
        'statusModalTitle'
    );

const statusModalBody =
    document.getElementById(
        'statusModalBody'
    );

const statusCloseBtn =
    document.querySelector(
        '.status-close-btn'
    );

const statusOkBtn =
    document.getElementById(
        'statusOkBtn'
    );


let reloadOnClose = false;


function showStatusModal(
    message,
    type = 'success',
    shouldReload = false
) {

    if (
        !statusModal ||
        !statusModalTitle ||
        !statusModalBody
    ) {
        alert(message);
        return;
    }


    statusModalTitle.textContent =
        type === 'error'
            ? 'Error'
            : 'Success';


    statusModalBody.textContent =
        message;


    statusModal.classList.add(
        'open'
    );


    statusModal.setAttribute(
        'aria-hidden',
        'false'
    );


    reloadOnClose =
        shouldReload;

}


function closeStatusModal() {

    if (!statusModal) {
        return;
    }


    statusModal.classList.remove(
        'open'
    );


    statusModal.setAttribute(
        'aria-hidden',
        'true'
    );


    if (reloadOnClose) {

        location.reload();

    }

}


if (statusCloseBtn) {

    statusCloseBtn.addEventListener(
        'click',
        closeStatusModal
    );

}


if (statusOkBtn) {

    statusOkBtn.addEventListener(
        'click',
        closeStatusModal
    );

}


if (statusModal) {

    statusModal.addEventListener(
        'click',
        event => {

            if (
                event.target === statusModal
            ) {

                closeStatusModal();

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| ESC KEY
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    event => {

        if (
            event.key === 'Escape'
        ) {

            if (
                modal &&
                modal.style.display === 'flex'
            ) {

                modal.style.display =
                    'none';

            }


            if (
                statusModal &&
                statusModal.classList.contains(
                    'open'
                )
            ) {

                closeStatusModal();

            }

        }

    }
);


/*
|--------------------------------------------------------------------------
| INITIAL FLASH MESSAGE
|--------------------------------------------------------------------------
*/

const initialStatusMessage =
    <?= json_encode($status_message) ?>;

const initialStatusType =
    <?= json_encode($status_type) ?>;


if (initialStatusMessage) {

    showStatusModal(
        initialStatusMessage,
        initialStatusType
    );

}

</script>


<?php include '../footer.php'; ?>

</body>

</html>

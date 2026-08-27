<?php

// settings.php
session_start();
require_once __DIR__ . '/../config/config.php';

/*
|--------------------------------------------------------------------------
| Flash message handling
|--------------------------------------------------------------------------
*/
$status_message = null;
$status_type = 'success';

if (isset($_SESSION['flash_message'])) {
    $status_message = $_SESSION['flash_message'];
    $status_type = $_SESSION['flash_type'] ?? 'success';

    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

/*
|--------------------------------------------------------------------------
| Session Management
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? 'unknown';
$full_name = $_SESSION['full_name'] ?? 'unknown';
$user_type = $_SESSION['user_type'] ?? 'unknown';

/*
|--------------------------------------------------------------------------
| GL TYPE / TABLE SELECTOR
|--------------------------------------------------------------------------
|
| old = fs_reports.gl_codes
| new = fs_reports.new_gl_codes
|
*/
$gl_type = $_GET['gl_type'] ?? 'new';

if (!in_array($gl_type, ['old', 'new'], true)) {
    $gl_type = 'new';
}

$gl_table = ($gl_type === 'old')
    ? 'fs_reports.gl_codes'
    : 'fs_reports.new_gl_codes';

$gl_title = ($gl_type === 'old')
    ? 'Old GL Code'
    : 'New GL Code';

/*
|--------------------------------------------------------------------------
| Handle Add New Row
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_gl_type = $_POST['gl_type'] ?? 'new';

    if (!in_array($posted_gl_type, ['old', 'new'], true)) {
        $posted_gl_type = 'new';
    }

    $gl_table = ($posted_gl_type === 'old')
        ? 'fs_reports.gl_codes'
        : 'fs_reports.new_gl_codes';

    $redirect_url = 'settings.php?gl_type=' . urlencode($posted_gl_type);

    $desc_type = $_POST['desc_type'] ?? 'new';
    $row_desc_type = $_POST['row_desc_type'] ?? 'new';

    $description = trim($_POST['description'] ?? '');
    $gl_description_comparative = trim($_POST['gl_description_comparative'] ?? '');
    $gl_code = trim($_POST['gl_code'] ?? '');
    $gl_description = trim($_POST['gl_description'] ?? '');
    $gl_mapping = strtolower(trim($_POST['gl_mapping'] ?? ''));

    $gl_id = null;
    $sort_order = null;
    $sub_order = null;

    $has_error = false;
    $status_message = 'Saved successfully.';
    $status_type = 'success';

    /*
    |--------------------------------------------------------------------------
    | Validate required fields
    |--------------------------------------------------------------------------
    */
    if ($description === '') {
        $status_message = 'Description is required.';
        $status_type = 'error';
        $has_error = true;
    }

    if (!$has_error && $gl_description_comparative === '') {
        $status_message = 'GL Description Comparative is required.';
        $status_type = 'error';
        $has_error = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    // Get next sort order
    $max_sort_res = mysqli_query(
        $conn,
        "SELECT MAX(sort_order + 0) AS max_sort
         FROM {$gl_table}"
    );

    $max_sort_row = $max_sort_res
        ? mysqli_fetch_assoc($max_sort_res)
        : null;

    $next_sort = (
        $max_sort_row &&
        $max_sort_row['max_sort'] !== null
    )
        ? ((int)$max_sort_row['max_sort'] + 1)
        : 1;


    // Get existing description information
    $get_existing_desc_info = function ($desc) use ($conn, $gl_table) {

        $info = [
            'sort_order' => null,
            'prefix' => null
        ];

        $stmt = mysqli_prepare(
            $conn,
            "SELECT sort_order, gl_id
             FROM {$gl_table}
             WHERE description = ?
             ORDER BY id ASC
             LIMIT 1"
        );

        if (!$stmt) {
            return $info;
        }

        mysqli_stmt_bind_param($stmt, 's', $desc);
        mysqli_stmt_execute($stmt);

        $sort_order_value = null;
        $existing_gl_id = null;

        mysqli_stmt_bind_result(
            $stmt,
            $sort_order_value,
            $existing_gl_id
        );

        if (mysqli_stmt_fetch($stmt)) {

            if ($sort_order_value !== null) {
                $info['sort_order'] = $sort_order_value;
            }

            if ($existing_gl_id !== null && $existing_gl_id !== '') {
                $parts = explode('-', $existing_gl_id);
                $info['prefix'] = $parts[0];
            }
        }

        mysqli_stmt_close($stmt);

        return $info;
    };


    // Get maximum sub order for description
    $get_max_sub_order = function ($desc) use ($conn, $gl_table) {

        $value = 0;

        $stmt = mysqli_prepare(
            $conn,
            "SELECT MAX(sub_order + 0) AS max_sub
             FROM {$gl_table}
             WHERE description = ?"
        );

        if (!$stmt) {
            return $value;
        }

        mysqli_stmt_bind_param($stmt, 's', $desc);
        mysqli_stmt_execute($stmt);

        $row = null;
        $result = mysqli_stmt_get_result($stmt);

        if ($result) {
            $row = mysqli_fetch_assoc($result);
        }

        mysqli_stmt_close($stmt);

        if ($row && $row['max_sub'] !== null) {
            $value = (int)$row['max_sub'];
        }

        return $value;
    };


    // Get existing comparative row
    $get_existing_row_info = function ($desc, $comp) use ($conn, $gl_table) {

        $info = [
            'sub_order' => null,
            'gl_id' => null
        ];

        $stmt = mysqli_prepare(
            $conn,
            "SELECT sub_order, gl_id
             FROM {$gl_table}
             WHERE description = ?
               AND gl_description_comparative = ?
             ORDER BY id ASC
             LIMIT 1"
        );

        if (!$stmt) {
            return $info;
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ss',
            $desc,
            $comp
        );

        mysqli_stmt_execute($stmt);

        $existing_sub = null;
        $existing_gl_id = null;

        mysqli_stmt_bind_result(
            $stmt,
            $existing_sub,
            $existing_gl_id
        );

        if (mysqli_stmt_fetch($stmt)) {
            $info['sub_order'] = $existing_sub;
            $info['gl_id'] = $existing_gl_id;
        }

        mysqli_stmt_close($stmt);

        return $info;
    };


    // Generate unique GL ID prefix
    $generate_new_prefix = function ($description) use ($conn, $gl_table) {

        $clean = strtoupper(
            preg_replace('/[^A-Za-z]/', '', $description)
        );

        if (strlen($clean) < 3) {
            $clean = str_pad($clean, 3, 'X');
        }

        $chars = str_split($clean);
        $len = count($chars);

        /*
        | First try combinations using the first character.
        */
        for ($i = 1; $i < $len - 1; $i++) {

            for ($j = $i + 1; $j < $len; $j++) {

                $prefix =
                    $chars[0] .
                    $chars[$i] .
                    $chars[$j];

                $check = mysqli_prepare(
                    $conn,
                    "SELECT id
                     FROM {$gl_table}
                     WHERE gl_id LIKE ?
                     LIMIT 1"
                );

                if (!$check) {
                    continue;
                }

                $pattern = $prefix . '-%';

                mysqli_stmt_bind_param(
                    $check,
                    's',
                    $pattern
                );

                mysqli_stmt_execute($check);
                mysqli_stmt_store_result($check);

                $is_unique =
                    mysqli_stmt_num_rows($check) === 0;

                mysqli_stmt_close($check);

                if ($is_unique) {
                    return $prefix;
                }
            }
        }

        /*
        | Fallback
        */
        $fallback = substr($clean, 0, 3);

        /*
        | Make sure fallback is unique too.
        */
        $counter = 1;
        $base = $fallback;

        while (true) {

            $check = mysqli_prepare(
                $conn,
                "SELECT id
                 FROM {$gl_table}
                 WHERE gl_id LIKE ?
                 LIMIT 1"
            );

            if (!$check) {
                break;
            }

            $candidate = $fallback . '-%';

            mysqli_stmt_bind_param(
                $check,
                's',
                $candidate
            );

            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);

            $exists =
                mysqli_stmt_num_rows($check) > 0;

            mysqli_stmt_close($check);

            if (!$exists) {
                break;
            }

            $fallback =
                substr($base, 0, 2) .
                chr(65 + (($counter - 1) % 26));

            $counter++;
        }

        return $fallback;
    };


    /*
    |--------------------------------------------------------------------------
    | Resolve sort_order / sub_order / gl_id
    |--------------------------------------------------------------------------
    */

    if (!$has_error) {

        if ($desc_type === 'new') {

            // New description
            $sort_order = $next_sort;
            $sub_order = 1;

            $prefix = $generate_new_prefix($description);

            $gl_id = $prefix . '-1';

        } else {

            // Existing description
            $desc_info = $get_existing_desc_info($description);

            $sort_order = $desc_info['sort_order'];
            $prefix = $desc_info['prefix'];

            /*
            | If description does not exist, treat it as new.
            */
            if ($sort_order === null) {

                $sort_order = $next_sort;
                $sub_order = 1;

                $prefix =
                    $generate_new_prefix($description);

                $gl_id = $prefix . '-1';

            } else {

                /*
                | Existing description + new comparative description
                */
                if ($row_desc_type === 'new') {

                    $sub_order =
                        $get_max_sub_order($description) + 1;

                    $gl_id =
                        $prefix . '-' . $sub_order;

                } else {

                    /*
                    | Existing description + existing comparative
                    */
                    $existing_row =
                        $get_existing_row_info(
                            $description,
                            $gl_description_comparative
                        );

                    if (
                        $existing_row['sub_order'] !== null &&
                        $existing_row['gl_id'] !== null
                    ) {

                        $sub_order =
                            $existing_row['sub_order'];

                        $gl_id =
                            $existing_row['gl_id'];

                    } else {

                        $sub_order =
                            $get_max_sub_order($description) + 1;

                        $gl_id =
                            $prefix . '-' . $sub_order;
                    }
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Auto-fetch mapping when using existing comparative description
    |--------------------------------------------------------------------------
    */
    if (
        !$has_error &&
        $row_desc_type === 'existing' &&
        $gl_description_comparative !== ''
    ) {

        $map_stmt = mysqli_prepare(
            $conn,
            "SELECT gl_mapping
             FROM {$gl_table}
             WHERE gl_description_comparative = ?
               AND gl_mapping IS NOT NULL
               AND gl_mapping != ''
             ORDER BY id DESC
             LIMIT 1"
        );

        if ($map_stmt) {

            mysqli_stmt_bind_param(
                $map_stmt,
                's',
                $gl_description_comparative
            );

            mysqli_stmt_execute($map_stmt);

            $fetched_mapping = null;

            mysqli_stmt_bind_result(
                $map_stmt,
                $fetched_mapping
            );

            if (
                mysqli_stmt_fetch($map_stmt) &&
                $fetched_mapping !== null
            ) {

                $gl_mapping =
                    strtolower(trim($fetched_mapping));
            }

            mysqli_stmt_close($map_stmt);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validate GL Mapping
    |--------------------------------------------------------------------------
    */

    if (
        !$has_error &&
        $gl_mapping !== '' &&
        preg_match('/\s/', $gl_mapping)
    ) {

        $status_message =
            "GL Mapping must be lowercase and use underscores instead of spaces.";

        $status_type = 'error';
        $has_error = true;
    }


    /*
    |--------------------------------------------------------------------------
    | Validate unique GL Mapping
    |--------------------------------------------------------------------------
    |
    | Same mapping can be used for the same comparative description.
    | Different comparative descriptions cannot share it.
    |
    */
    if (!$has_error && $gl_mapping !== '') {

        $check = mysqli_prepare(
            $conn,
            "SELECT gl_description_comparative
             FROM {$gl_table}
             WHERE gl_mapping = ?
               AND gl_description_comparative <> ?
             LIMIT 1"
        );

        if ($check) {

            mysqli_stmt_bind_param(
                $check,
                'ss',
                $gl_mapping,
                $gl_description_comparative
            );

            mysqli_stmt_execute($check);

            $existing_gl_desc_comp = '';

            mysqli_stmt_bind_result(
                $check,
                $existing_gl_desc_comp
            );

            if (mysqli_stmt_fetch($check)) {

                $status_message =
                    "GL Mapping '" .
                    htmlspecialchars($gl_mapping) .
                    "' already exists for '" .
                    htmlspecialchars($existing_gl_desc_comp) .
                    "'.";

                $status_type = 'error';
                $has_error = true;
            }

            mysqli_stmt_close($check);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Insert
    |--------------------------------------------------------------------------
    |
    | Both gl_codes and new_gl_codes have:
    |
    | gl_id
    | sort_order
    | description
    | sub_order
    | gl_description_comparative
    | gl_code
    | gl_description
    | gl_mapping
    |
    */
    if (!$has_error) {

        $insert = mysqli_prepare(
            $conn,
            "INSERT INTO {$gl_table}
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
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$insert) {

            $status_message =
                "Failed to prepare insert: " .
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

            if (!mysqli_stmt_execute($insert)) {

                $status_message =
                    "Failed to save: " .
                    mysqli_stmt_error($insert);

                $status_type = 'error';

            } else {

                $status_message =
                    $gl_title . " row saved successfully.";

                $status_type = 'success';
            }

            mysqli_stmt_close($insert);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Flash + Redirect
    |--------------------------------------------------------------------------
    */
    $_SESSION['flash_message'] = $status_message;
    $_SESSION['flash_type'] = $status_type;

    header("Location: {$redirect_url}");
    exit;
}


/*
|--------------------------------------------------------------------------
| Fetch GL rows
|--------------------------------------------------------------------------
*/
try {

    $query = "
        SELECT
            id,
            gl_id,
            sort_order,
            description,
            sub_order,
            gl_description_comparative,
            gl_code,
            gl_description,
            gl_mapping
        FROM {$gl_table}
        ORDER BY
            sort_order + 0 ASC,
            sub_order + 0 ASC,
            id ASC
    ";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }


    /*
    |--------------------------------------------------------------------------
    | Group rows
    |--------------------------------------------------------------------------
    */
    $grouped = [];
    $main_order = [];
    $sub_orders = [];
    $sub_order_map = [];
    $gl_id_map = [];

    foreach ($rows as $row) {

        $desc = $row['description'] ?? '';
        $comp = $row['gl_description_comparative'] ?? '';

        if (!isset($grouped[$desc])) {
            $grouped[$desc] = [];
        }

        if (!isset($grouped[$desc][$comp])) {
            $grouped[$desc][$comp] = [];
        }

        $grouped[$desc][$comp][] = $row;


        if (!isset($sub_order_map[$desc][$comp])) {
            $sub_order_map[$desc][$comp] =
                $row['sub_order'] ?? '';
        }


        if (!isset($gl_id_map[$desc][$comp])) {
            $gl_id_map[$desc][$comp] =
                $row['gl_id'] ?? '';
        }


        if (!array_key_exists($desc, $main_order)) {

            $main_order[$desc] =
                $row['sort_order'] ?? PHP_INT_MAX;
        }


        if (!isset($sub_orders[$desc])) {
            $sub_orders[$desc] = [];
        }


        if (
            !in_array(
                $comp,
                $sub_orders[$desc],
                true
            )
        ) {

            $sub_orders[$desc][] = $comp;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Sort descriptions by sort_order
    |--------------------------------------------------------------------------
    */
    $desc_list = array_keys($main_order);

    usort(
        $desc_list,
        function ($a, $b) use ($main_order) {

            $oa = $main_order[$a] ?? PHP_INT_MAX;
            $ob = $main_order[$b] ?? PHP_INT_MAX;

            return $oa <=> $ob;
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Description dropdown
    |--------------------------------------------------------------------------
    */
    $distinct_desc_query = "
        SELECT DISTINCT description
        FROM {$gl_table}
        WHERE description IS NOT NULL
          AND description != ''
        ORDER BY description ASC
    ";

    $distinct_desc_res =
        mysqli_query(
            $conn,
            $distinct_desc_query
        );


    /*
    |--------------------------------------------------------------------------
    | Comparative description dropdown
    |--------------------------------------------------------------------------
    */
    $distinct_comp_query = "
        SELECT DISTINCT gl_description_comparative
        FROM {$gl_table}
        WHERE gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
        ORDER BY gl_description_comparative ASC
    ";

    $distinct_comp_res =
        mysqli_query(
            $conn,
            $distinct_comp_query
        );

} catch (Exception $e) {

    $error_message =
        "Error: " . $e->getMessage();
}


/*
|--------------------------------------------------------------------------
| Mapping lookup
|--------------------------------------------------------------------------
*/
$mapping_lookup = [];

$lookup_query = "
    SELECT
        gl_description_comparative,
        gl_mapping
    FROM {$gl_table}
    WHERE gl_description_comparative IS NOT NULL
";

$lookup_res =
    mysqli_query(
        $conn,
        $lookup_query
    );

if ($lookup_res) {

    while ($row = mysqli_fetch_assoc($lookup_res)) {

        $mapping_lookup[
            $row['gl_description_comparative']
        ] = $row['gl_mapping'];
    }
}

$mapping_json =
    json_encode(
        $mapping_lookup,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );


/*
|--------------------------------------------------------------------------
| Description -> Comparative list
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
    ORDER BY description ASC
";

$comp_by_desc_res =
    mysqli_query(
        $conn,
        $comp_by_desc_query
    );

if ($comp_by_desc_res) {

    while ($row = mysqli_fetch_assoc($comp_by_desc_res)) {

        $d = $row['description'];
        $c = $row['gl_description_comparative'];

        if (!isset($comps_by_desc[$d])) {
            $comps_by_desc[$d] = [];
        }

        if (
            !in_array(
                $c,
                $comps_by_desc[$d],
                true
            )
        ) {

            $comps_by_desc[$d][] = $c;
        }
    }
}

$comps_by_desc_json =
    json_encode(
        $comps_by_desc,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
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

    <title>List of GL Codes</title>

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
        href="css/settings.css?v=<?= time(); ?>"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    >

</head>

<body>

<main class="main-content">

    <header class="top-bar">

        <h2>
            <a
                href="user_dashboard.php"
                style="font-size: 16px; text-decoration: none;"
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
                    substr($full_name, 0, 1)
                ) ?>
            </div>

        </div>

    </header>


    <div class="content-wrapper">

        <h2
            style="
                text-align: center;
                margin-top: -2%;
            "
        >
            List of GL Codes
        </h2>


        <!-- =========================================================
             GL TYPE TOGGLE
        ========================================================== -->

        <div class="gl-settings-toolbar">

            <div class="gl-toggle">

                <a
                    href="settings.php?gl_type=old"
                    class="<?= $gl_type === 'old' ? 'active' : '' ?>"
                >
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Old GL Code
                </a>

                <a
                    href="settings.php?gl_type=new"
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
                placeholder="Search description, comparative description, GL code, GL description..."
                class="gl-search-input"
            >

        </div>


        <h3 style="text-align: center;">

            <?= $gl_type === 'old'
                ? 'Old GL Codes'
                : 'New GL Codes'
            ?>

        </h3>


        <!-- =========================================================
             TABLE
        ========================================================== -->

        <section class="table-container">

            <?php if (isset($error_message)): ?>

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

                    $desc_list =
                        isset($desc_list) &&
                        is_array($desc_list)
                            ? $desc_list
                            : [];

                    $sub_orders =
                        isset($sub_orders) &&
                        is_array($sub_orders)
                            ? $sub_orders
                            : [];


                    if (empty($desc_list)):

                    ?>

                        <tr>

                            <td
                                colspan="10"
                                style="text-align: center;"
                            >
                                No GL codes found.
                                Click "Add Row" to create entries.
                            </td>

                        </tr>

                    <?php

                    else:

                        $group_counter = 0;

                        foreach ($desc_list as $desc):

                            $show_header =
                                ($desc !== '');

                            if ($show_header) {
                                $group_counter++;
                            }


                            $sub_order_items =
                                isset($sub_orders[$desc]) &&
                                is_array($sub_orders[$desc])
                                    ? $sub_orders[$desc]
                                    : [];


                            $subgroup_index = 0;

                            foreach (
                                $sub_order_items
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
                                | Sort rows by ID
                                */
                                usort(
                                    $sub_rows,
                                    function ($a, $b) {

                                        return
                                            ($a['id'] ?? 0)
                                            <=>
                                            ($b['id'] ?? 0);
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


                            <td
                                style="
                                    text-align: left;
                                    color: #000000;
                                "
                            >
                                <?= htmlspecialchars($show_gl_id) ?>
                            </td>


                            <td></td>


                            <td></td>


                            <td
                                style="
                                    text-align: center;
                                    color: #000000;
                                "
                            >
                                <?= htmlspecialchars($show_sub_order) ?>
                            </td>


                            <td>
                                <?= htmlspecialchars($comp) ?>
                            </td>


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $row['gl_code'] ?? ''
                                    ) ?>
                                </strong>

                            </td>


                            <td>
                                <?= htmlspecialchars(
                                    $row['gl_description'] ?? ''
                                ) ?>
                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $row['gl_mapping'] ?? ''
                                ) ?>

                            </td>


                            <td
                                style="text-align: center;"
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
                            | Category header
                            |--------------------------------------------------------------------------
                            */

                            if ($show_header):

                    ?>

                        <tr
                            class="category-header-row"
                            style="
                                background-color: #f2f4f6;
                                border-bottom: 2px solid #de0000;
                                border-top: 2px solid #de0000;
                            "
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

                    endif;

                    ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </section>


        <!-- =========================================================
             LINKS
        ========================================================== -->

        <div
            style="
                margin-top: 20px;
                text-align: right;
                display: flex;
                gap: 10px;
                justify-content: center;
                flex-wrap: wrap;
            "
        >

            <a
                href="comparative_report_original.php"
                class="btn-preview"
                style="text-decoration: none;"
            >
                <i class="fa-solid fa-file"></i>
                Comparative Report (Original Data)
            </a>


            <a
                href="manual_adjustment_new.php"
                class="btn-preview"
                style="text-decoration: none;"
            >
                <i class="fa-solid fa-file"></i>
                Comparative Report (Manual Adjustment)
            </a>


            <a
                href="consolidated_with_adjustment.php"
                class="btn-preview"
                style="text-decoration: none;"
            >
                <i class="fa-solid fa-file"></i>
                Consolidated Report (Per Zone)
            </a>

        </div>

    </div>

</main>


<!-- =============================================================
     ADD ROW MODAL
============================================================== -->

<div
    id="glModal"
    class="modal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h3>
                Add <?= htmlspecialchars($gl_title) ?> Row
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


            <!-- DESCRIPTION -->

            <div class="form-section">

                <label>Description</label>

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


            <!-- COMPARATIVE DESCRIPTION -->

            <div class="form-section">

                <label>
                    GL Description Comparative
                </label>

                <div class="radio-group">

                    <label>
                        <input
                            type="radio"
                            name="row_desc_type"
                            value="new"
                            checked
                        >
                        Create new GL description comparative
                    </label>

                    <label>
                        <input
                            type="radio"
                            name="row_desc_type"
                            value="existing"
                        >
                        Use existing GL description comparative
                    </label>

                </div>


                <div id="row_desc_input_container">

                    <input
                        type="text"
                        name="gl_description_comparative"
                        placeholder="Enter new GL description comparative"
                        required
                    >

                </div>

            </div>


            <!-- GL CODE -->

            <div class="form-section">

                <label>
                    GL Code
                </label>

                <input
                    type="text"
                    name="gl_code"
                >

            </div>


            <!-- GL DESCRIPTION -->

            <div class="form-section">

                <label>
                    GL Description
                </label>

                <input
                    type="text"
                    name="gl_description"
                >

            </div>


            <!-- GL MAPPING -->

            <div class="form-section">

                <label>
                    GL Mapping/Shortcut
                </label>

                <input
                    type="text"
                    name="gl_mapping"
                    placeholder="Enter shortcut. Use underscores instead of spaces."
                >

                <small>
                    The same GL Mapping cannot be used for different
                    Comparative Report Descriptions.
                </small>

            </div>


            <div class="modal-footer">

                <a
                    href="settings.php?gl_type=<?= urlencode($gl_type) ?>"
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


<!-- =============================================================
     STATUS MODAL
============================================================== -->

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
| PHP values
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
| Add modal
|--------------------------------------------------------------------------
*/

const modal =
    document.getElementById("glModal");

const btn =
    document.getElementById("openModalBtn");

const spans =
    document.getElementsByClassName("close-btn");


if (btn) {

    btn.onclick = () => {
        modal.style.display = "flex";
    };

}


for (let span of spans) {

    span.onclick = () => {
        modal.style.display = "none";
    };

}


window.addEventListener("click", function(e) {

    if (e.target === modal) {
        modal.style.display = "none";
    }

});


/*
|--------------------------------------------------------------------------
| Description toggle
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll('input[name="desc_type"]')
    .forEach(radio => {

        radio.addEventListener('change', function() {

            const rowDescType =
                document.querySelector(
                    'input[name="row_desc_type"]:checked'
                ).value;


            if (
                this.value === 'new' &&
                rowDescType === 'existing'
            ) {

                alert(
                    'You cannot use existing GL Description Comparative for a New Description'
                );

                const rowNewRadio =
                    document.querySelector(
                        'input[name="row_desc_type"][value="new"]'
                    );

                rowNewRadio.checked = true;

                rowNewRadio.dispatchEvent(
                    new Event('change')
                );

                return;
            }


            const container =
                document.getElementById(
                    'desc_input_container'
                );


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

                            <option value="<?= htmlspecialchars($d['description']) ?>">
                                <?= htmlspecialchars($d['description']) ?>
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
                        function() {

                            renderExistingRowDescOptions(
                                this.value
                            );

                        }
                    );

                }

            } else {

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

        });

    });


/*
|--------------------------------------------------------------------------
| Render existing comparative descriptions
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


    let optionsHtml =
        '<option value="">Select Existing...</option>';


    if (selectedDescription) {

        const list =
            compsByDesc[selectedDescription] || [];


        list.forEach(value => {

            optionsHtml += `
                <option value="${escapeHtml(value)}">
                    ${escapeHtml(value)}
                </option>
            `;

        });

    } else {

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
                <option value="<?= htmlspecialchars($c['gl_description_comparative']) ?>">
                    <?= htmlspecialchars($c['gl_description_comparative']) ?>
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


    const mappingInput =
        document.querySelector(
            'input[name="gl_mapping"]'
        );


    if (mappingInput) {

        mappingInput.readOnly = true;

        mappingInput.style.backgroundColor =
            "#e9ecef";

        mappingInput.style.cursor =
            "not-allowed";
    }


    const dropdown =
        document.getElementById(
            'existing_row_desc'
        );


    if (dropdown && mappingInput) {

        dropdown.addEventListener(
            'change',
            function() {

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
| Row description toggle
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        'input[name="row_desc_type"]'
    )
    .forEach(radio => {

        radio.addEventListener(
            'change',
            function() {

                const container =
                    document.getElementById(
                        'row_desc_input_container'
                    );

                const mappingInput =
                    document.querySelector(
                        'input[name="gl_mapping"]'
                    );

                const descType =
                    document.querySelector(
                        'input[name="desc_type"]:checked'
                    ).value;


                if (
                    this.value === 'existing' &&
                    descType === 'new'
                ) {

                    alert(
                        'You cannot use existing GL Description Comparative for a New Description'
                    );

                    const rowNewRadio =
                        document.querySelector(
                            'input[name="row_desc_type"][value="new"]'
                        );

                    rowNewRadio.checked = true;

                    rowNewRadio.dispatchEvent(
                        new Event('change')
                    );

                    return;
                }


                if (this.value === 'existing') {

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

                } else {

                    container.innerHTML = `
                        <input
                            type="text"
                            name="gl_description_comparative"
                            placeholder="Enter new row description"
                            required
                        >
                    `;


                    mappingInput.value = '';

                    mappingInput.readOnly = false;

                    mappingInput.style.backgroundColor =
                        "";

                    mappingInput.style.cursor =
                        "text";

                }

            }
        );

    });


/*
|--------------------------------------------------------------------------
| Search
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

    if (!glSearchInput || !tbody) {
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


    if (!q) {

        dataRows.forEach(row => {
            row.style.display = '';
        });

        headerRows.forEach(row => {
            row.style.display = '';
        });

        return;
    }


    const matchedDescs =
        new Set();


    dataRows.forEach(row => {

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

        const mapping =
            (
                row.querySelector(
                    'td:nth-child(9)'
                )?.textContent || ''
            ).trim();


        const hay =
            `${desc} ${comp} ${glCode} ${glDesc} ${mapping}`
                .toLowerCase();


        const hit =
            hay.includes(q);


        row.style.display =
            hit ? '' : 'none';


        if (hit && desc) {
            matchedDescs.add(desc);
        }

    });


    headerRows.forEach(header => {

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

    });

}


if (glSearchInput) {

    glSearchInput.addEventListener(
        'input',
        applyGlSearchFilter
    );

}


/*
|--------------------------------------------------------------------------
| Drag and drop
|--------------------------------------------------------------------------
*/

let draggingGroupKey = null;
let draggingDescription = null;
let initialOrderIds = null;
let initialGroupOrderSignature = null;


function getGroupOrderSignature() {

    const rows =
        Array.from(
            tbody.querySelectorAll(
                'tr[data-id]'
            )
        );


    const seen =
        new Set();

    const order = [];


    rows.forEach(row => {

        const group =
            row.dataset.group || '';


        if (!seen.has(group)) {

            seen.add(group);

            order.push(group);
        }

    });


    return order.join('|');
}


function getDataRowIds() {

    return Array.from(
        tbody.querySelectorAll(
            'tr[data-id]'
        )
    ).map(
        row => row.dataset.id
    );

}


function getGroupRows(groupKey) {

    return Array.from(
        tbody.querySelectorAll(
            'tr[data-id]'
        )
    ).filter(
        row => row.dataset.group === groupKey
    );

}


function restoreRowOrder(ids) {

    if (
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
        .forEach(row => {

            rowMap.set(
                row.dataset.id,
                row
            );

        });


    ids.forEach(id => {

        const row =
            rowMap.get(
                String(id)
            );

        if (row) {
            tbody.appendChild(row);
        }

    });


    /*
    | Re-position category headers
    */
    const headerRows =
        Array.from(
            tbody.querySelectorAll(
                'tr.category-header-row'
            )
        );


    headerRows.forEach(header => {

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


        if (dataRows.length === 0) {
            return;
        }


        const last =
            dataRows[dataRows.length - 1];


        tbody.insertBefore(
            header,
            last.nextSibling
        );

    });

}


/*
|--------------------------------------------------------------------------
| Find target group
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
        ]
        .filter(
            tr =>
                tr.dataset.group !== draggingGroup &&
                tr.dataset.description === draggingDesc
        );


    if (rows.length === 0) {
        return null;
    }


    let closest = null;
    let closestDist = Infinity;


    rows.forEach(row => {

        const box =
            row.getBoundingClientRect();

        const center =
            box.top +
            box.height / 2;

        const dist =
            Math.abs(
                y - center
            );


        if (dist < closestDist) {

            closestDist = dist;
            closest = row;

        }

    });


    if (!closest) {
        return null;
    }


    const targetGroup =
        closest.dataset.group;


    const targetRows =
        getGroupRows(
            targetGroup
        );


    if (targetRows.length === 0) {
        return null;
    }


    const first =
        targetRows[0];

    const last =
        targetRows[targetRows.length - 1];


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
| Initialize drag handles
|--------------------------------------------------------------------------
*/

if (tbody) {

    tbody
        .querySelectorAll(
            'tr[data-id]'
        )
        .forEach(row => {

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
                e => {

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
                    ).forEach(r => {

                        r.classList.add(
                            'dragging'
                        );

                    });


                    e.dataTransfer.effectAllowed =
                        'move';

                }
            );


            handle.addEventListener(
                'dragend',
                () => {

                    getGroupRows(
                        draggingGroupKey
                    ).forEach(r => {

                        r.classList.remove(
                            'dragging'
                        );

                    });


                    draggingGroupKey =
                        null;

                    draggingDescription =
                        null;

                }
            );

        });


    tbody.addEventListener(
        'dragover',
        e => {

            e.preventDefault();


            if (!draggingGroupKey) {
                return;
            }


            const groupRows =
                getGroupRows(
                    draggingGroupKey
                );


            if (groupRows.length === 0) {
                return;
            }


            const target =
                getTargetGroupElement(
                    tbody,
                    e.clientY,
                    draggingGroupKey,
                    draggingDescription
                );


            if (!target) {
                return;
            }


            if (target.insertAfter) {

                groupRows.forEach(row => {

                    tbody.insertBefore(
                        row,
                        target.last.nextSibling
                    );

                });

            } else {

                groupRows.forEach(row => {

                    tbody.insertBefore(
                        row,
                        target.first
                    );

                });

            }

        }
    );


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


            const dataRows =
                Array.from(
                    tbody.querySelectorAll(
                        'tr[data-id]'
                    )
                );


            const orderData = [];


            let currentDesc = null;
            let subCounter = 0;


            dataRows.forEach(row => {

                const desc =
                    row.dataset.description || '';

                const comp =
                    row.dataset.glcomp || '';

                const id =
                    row.dataset.id;


                if (desc !== currentDesc) {

                    currentDesc = desc;

                    subCounter = 0;

                }


                const existingInDesc =
                    orderData.filter(
                        item =>
                            item.description === desc &&
                            item.gl_description_comparative === comp
                    );


                if (
                    existingInDesc.length === 0
                ) {

                    subCounter++;

                }


                orderData.push({

                    id: id,

                    description: desc,

                    gl_description_comparative:
                        comp,

                    sub_order:
                        subCounter

                });

            });


            fetch(
                'save_sub_order.php',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body: JSON.stringify({

                        orderData:

                            orderData,

                        gl_type:

                            currentGlType

                    })
                }
            )
            .then(res => res.json())
            .then(data => {

                if (data.ok) {

                    showStatusModal(
                        'Order saved successfully!',
                        'success',
                        true
                    );

                } else {

                    showStatusModal(
                        data.error ||
                            'Failed to save order',
                        'error'
                    );

                }

            })
            .catch(() => {

                showStatusModal(
                    'Failed to save order',
                    'error'
                );

            });

        }
    );

}


/*
|--------------------------------------------------------------------------
| Delete row
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        '.btn-delete-row'
    )
    .forEach(button => {

        button.addEventListener(
            'click',
            () => {

                const id =
                    button.dataset.id;

                const glType =
                    button.dataset.table;


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
                    'delete_gl_row.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body: JSON.stringify({

                            id: id,

                            gl_type:
                                glType

                        })
                    }
                )
                .then(res => res.json())
                .then(data => {

                    if (data.ok) {

                        showStatusModal(
                            'Deleted successfully.',
                            'success',
                            true
                        );

                    } else {

                        showStatusModal(
                            data.error ||
                                'Delete failed',
                            'error'
                        );

                    }

                })
                .catch(() => {

                    showStatusModal(
                        'Delete failed',
                        'error'
                    );

                });

            }
        );

    });


/*
|--------------------------------------------------------------------------
| GL Mapping
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


            glMappingInput.value =
                glMappingInput.value
                    .toLowerCase()
                    .replace(/\s+/g, '_');


            glMappingInput.setSelectionRange(
                cursor,
                cursor
            );

        }
    );

}


if (addGlForm) {

    addGlForm.addEventListener(
        'submit',
        e => {

            const value =
                (
                    glMappingInput?.value ||
                    ''
                ).trim();


            if (/\s/.test(value)) {

                e.preventDefault();

                showStatusModal(
                    'GL Mapping must be lowercase and use underscores instead of spaces.',
                    'error'
                );

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Status modal
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
        e => {

            if (
                e.target === statusModal
            ) {

                closeStatusModal();

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| Initial status message
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
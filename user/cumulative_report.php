<?php
session_start();
require_once __DIR__ . '/../config/config.php';

$status_message = null;
$status_type = 'success';

if (isset($_SESSION['flash_message'])) {
    $status_message = $_SESSION['flash_message'];
    $status_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

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

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Handle Add New Row (save)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $desc_type = $_POST['desc_type'] ?? 'new';
    $row_desc_type = $_POST['row_desc_type'] ?? 'new';

    $description = trim($_POST['description'] ?? '');
    $gl_description_comparative = trim($_POST['gl_description_comparative'] ?? '');
    $gl_code = trim($_POST['gl_code'] ?? '');
    $new_gl_code = trim($_POST['new_gl_code'] ?? '');
    $gl_description = trim($_POST['gl_description'] ?? '');
    $new_gl_description = trim($_POST['new_gl_description'] ?? '');
    $gl_mapping = strtolower(trim($_POST['gl_mapping'] ?? ''));

    $gl_id = null;
    $sort_order = null;
    $sub_order = null;
    $has_error = false;
    $status_message = "Saved successfully.";
    $status_type = 'success';

    // Helpers for ordering
    $max_sort_res = mysqli_query($conn, "SELECT MAX(sort_order + 0) AS max_sort FROM fs_reports.gl_codes_ho_new");
    $max_sort_row = $max_sort_res ? mysqli_fetch_assoc($max_sort_res) : null;
    $next_sort = ($max_sort_row && $max_sort_row['max_sort'] !== null) ? ((int)$max_sort_row['max_sort'] + 1) : 1;

    $get_sort_order = function($desc) use ($conn) {
        $value = null;
        $stmt = mysqli_prepare($conn, "SELECT sort_order FROM fs_reports.gl_codes_ho_new WHERE description = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $desc);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $value);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
        }
        return $value;
    };

    $get_max_sub_order = function($desc) use ($conn) {
        $value = 0;
        $stmt = mysqli_prepare($conn, "SELECT MAX(sub_order + 0) AS max_sub FROM fs_reports.gl_codes_ho_new WHERE description = ?");
        if ($stmt) {
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
        }
        return $value;
    };

    $get_sub_order_for_comp = function($desc, $comp) use ($conn) {
        $value = null;
        $stmt = mysqli_prepare(
            $conn,
            "SELECT sub_order FROM fs_reports.gl_codes_ho_new WHERE description = ? AND gl_description_comparative = ? LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ss', $desc, $comp);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $value);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
        }
        return $value;
    };

    $get_existing_desc_info = function($desc) use ($conn) {
        $info = ['sort_order' => null, 'prefix' => null];
        $stmt = mysqli_prepare($conn, "SELECT sort_order, gl_id FROM fs_reports.gl_codes_ho_new WHERE description = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $desc);
            mysqli_stmt_execute($stmt);
            $existing_gl_id = null;
            mysqli_stmt_bind_result($stmt, $info['sort_order'], $existing_gl_id);
            if (mysqli_stmt_fetch($stmt) && $existing_gl_id) {
                $parts = explode('-', $existing_gl_id);
                $info['prefix'] = $parts[0];
            }
            mysqli_stmt_close($stmt);
        }
        return $info;
    };

    $generate_new_prefix = function($description) use ($conn) {
        $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', $description));
        if (strlen($clean) < 3) $clean = str_pad($clean, 3, 'X');
        $chars = str_split($clean);
        $len = count($chars);
        for ($i = 1; $i < $len - 1; $i++) {
            for ($j = $i + 1; $j < $len; $j++) {
                $prefix = $chars[0] . $chars[$i] . $chars[$j];
                $check = mysqli_prepare($conn, "SELECT id FROM fs_reports.gl_codes_ho_new WHERE gl_id LIKE ? AND description != ? LIMIT 1");
                $p = $prefix . '-%';
                mysqli_stmt_bind_param($check, 'ss', $p, $description);
                mysqli_stmt_execute($check);
                mysqli_stmt_store_result($check);
                $is_unique = mysqli_stmt_num_rows($check) === 0;
                mysqli_stmt_close($check);
                if ($is_unique) return $prefix;
            }
        }
        return substr($clean, 0, 3);
    };

    // Resolve sort_order and sub_order based on description + row description choice
    if ($desc_type === 'new') {
        $sort_order = $next_sort;
        $sub_order = 1;
        $prefix = $generate_new_prefix($description);
        $gl_id = $prefix . '-1';
    } else {
        $desc_info = $get_existing_desc_info($description);
        $sort_order = $desc_info['sort_order'];
        $prefix = $desc_info['prefix'];

        if ($sort_order === null) {
            // Fallback: treat as new description
            $sort_order = $next_sort;
            $sub_order = 1;
            $prefix = $generate_new_prefix($description);
            $gl_id = $prefix . '-1';
        } else {
            if ($row_desc_type === 'new') {
                $sub_order = $get_max_sub_order($description) + 1;
                $gl_id = ($prefix ?: 'GLX') . '-' . $sub_order;
            } else {
                $existing_sub = null;
                $existing_gl_id = null;
                $row_stmt = mysqli_prepare($conn, "SELECT sub_order, gl_id FROM fs_reports.gl_codes_ho_new WHERE description = ? AND gl_description_comparative = ? LIMIT 1");
                if ($row_stmt) {
                    mysqli_stmt_bind_param($row_stmt, 'ss', $description, $gl_description_comparative);
                    mysqli_stmt_execute($row_stmt);
                    mysqli_stmt_bind_result($row_stmt, $existing_sub, $existing_gl_id);
                    if (mysqli_stmt_fetch($row_stmt)) {
                        $sub_order = $existing_sub;
                        $gl_id = $existing_gl_id;
                    }
                    mysqli_stmt_close($row_stmt);
                }

                if ($sub_order === null) {
                    $sub_order = $get_max_sub_order($description) + 1;
                    $gl_id = ($prefix ?: 'GLX') . '-' . $sub_order;
                }
            }
        }
    }

    // Auto-fetch mapping when using existing row description
    if ($row_desc_type === 'existing' && $gl_description_comparative !== '') {
        $map_stmt = mysqli_prepare(
            $conn,
            "SELECT gl_mapping FROM fs_reports.gl_codes_ho_new WHERE gl_description_comparative = ? AND gl_mapping IS NOT NULL AND gl_mapping != '' ORDER BY id DESC LIMIT 1"
        );
        if ($map_stmt) {
            mysqli_stmt_bind_param($map_stmt, 's', $gl_description_comparative);
            mysqli_stmt_execute($map_stmt);
            mysqli_stmt_bind_result($map_stmt, $fetched_mapping);
            if (mysqli_stmt_fetch($map_stmt) && $fetched_mapping !== null) {
                $gl_mapping = strtolower(trim($fetched_mapping));
            }
            mysqli_stmt_close($map_stmt);
        }
    }

    // Validate gl_mapping format (no spaces)
    if ($gl_mapping !== '' && preg_match('/\s/', $gl_mapping)) {
        $status_message = "GL Mapping must be lowercase and use underscores instead of spaces.";
        $status_type = 'error';
        $has_error = true;
    }

    // Validate unique gl_mapping (allow if same gl_description_comparative)
    if (!$has_error && $gl_mapping !== '') {
        $check = mysqli_prepare(
            $conn,
            "SELECT gl_description_comparative 
             FROM fs_reports.gl_codes_ho_new 
             WHERE gl_mapping = ?
               AND gl_description_comparative <> ?
             LIMIT 1"
        );
        if ($check) {
            mysqli_stmt_bind_param($check, 'ss', $gl_mapping, $gl_description_comparative);
            mysqli_stmt_execute($check);
            $existing_gl_desc_comp = '';
            mysqli_stmt_bind_result($check, $existing_gl_desc_comp);
            if (mysqli_stmt_fetch($check)) {
                $status_message = "GL Mapping '" . htmlspecialchars($gl_mapping) . "' already exists for '" . htmlspecialchars($existing_gl_desc_comp) . "'.";
                $status_type = 'error';
                $has_error = true;
            }
            mysqli_stmt_close($check);
        }
    }

    if (!$has_error) {
        // Insert the row (unchanged)
        $insert = mysqli_prepare($conn, "INSERT INTO fs_reports.gl_codes_ho_new
            (gl_id, sort_order, sub_order, description, gl_description_comparative, gl_code, new_gl_code, gl_description, new_gl_description, gl_mapping)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($insert) {
            mysqli_stmt_bind_param($insert, 'siisssssss', $gl_id, $sort_order, $sub_order, $description, $gl_description_comparative, $gl_code, $new_gl_code, $gl_description, $new_gl_description, $gl_mapping);
            mysqli_stmt_execute($insert);
            mysqli_stmt_close($insert);
        }
    }

    // Always set flash and redirect (success or error)
    $_SESSION['flash_message'] = $status_message;
    $_SESSION['flash_type'] = $status_type;

    header("Location: cumulative_report.php");
    exit;
}

// Initialize variables used in display logic to avoid undefined variable notices
$desc_list = [];
$grouped = [];
$sub_orders = [];
$sub_order_map = [];
$distinct_desc_res = null;
$distinct_comp_res = null;
$error_message = null;

try {
    $has_sort_order = false;
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM fs_reports.gl_codes_ho_new LIKE 'sort_order'");
if ($col_check && mysqli_num_rows($col_check) > 0) {
    $has_sort_order = true;
}
$order_by = $has_sort_order
    ? "ORDER BY CAST(sort_order AS UNSIGNED), CAST(sub_order AS UNSIGNED), id"
    : "ORDER BY sort_order, id";

    $query = "SELECT id, gl_id, sort_order, sub_order, description, gl_description_comparative, gl_code, new_gl_code, gl_description, new_gl_description, gl_mapping 
          FROM fs_reports.gl_codes_ho_new
          $order_by";
    $result = mysqli_query($conn, $query);

    // Fetch all rows into array for safer processing
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }


    // Group rows for proper display and sub-order assignment
$grouped = [];
$main_order = [];
$sub_orders = []; // Preserves historical order of first appearance of each comparative
$sub_order_map = []; // sub_order per description + comparative
$gl_id_map = []; // gl_id per description + comparative

foreach ($rows as $row) {
    $desc = $row['description'] ?? '';
    $comp = $row['gl_description_comparative'] ?? '';

    $grouped[$desc][$comp][] = $row;

    if (!isset($sub_order_map[$desc][$comp])) {
        $sub_order_map[$desc][$comp] = $row['sub_order'] ?? '';
    }
    if (!isset($gl_id_map[$desc][$comp])) {
        $gl_id_map[$desc][$comp] = $row['gl_id'] ?? '';
    }

    // Track sort_order for main group ordering (use first encountered)
    if (!array_key_exists($desc, $main_order)) {
        $main_order[$desc] = $row['sort_order'] ?? PHP_INT_MAX;
    }

    // Initialize sub-order tracking for this main group
    if (!isset($sub_orders[$desc])) {
        $sub_orders[$desc] = [];
    }

    // Add comparative only on first encounter (preserves historical order)
    if (!in_array($comp, $sub_orders[$desc], true)) {
        $sub_orders[$desc][] = $comp;
    }
}

// Sort main descriptions by their sort_order
$desc_list = array_keys($main_order);
usort($desc_list, function($a, $b) use ($main_order) {
    $oa = $main_order[$a] ?? PHP_INT_MAX;
    $ob = $main_order[$b] ?? PHP_INT_MAX;
    return $oa <=> $ob;
});

    // Fetch distinct descriptions for the modal
    $distinct_desc_query = "SELECT DISTINCT sort_order, description FROM fs_reports.gl_codes_ho_new WHERE description IS NOT NULL AND description != '' order by sort_order asc";
    $distinct_desc_res = mysqli_query($conn, $distinct_desc_query);

    // Fetch distinct comparative descriptions for the modal
    $distinct_comp_query = "SELECT DISTINCT sort_order, sub_order, gl_description_comparative FROM fs_reports.gl_codes_ho_new WHERE gl_description_comparative IS NOT NULL AND gl_description_comparative != '' order by sort_order asc, sub_order asc";
    $distinct_comp_res = mysqli_query($conn, $distinct_comp_query);

} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
}

// Fetch mapping of Comparative Description -> GL Mapping
$mapping_lookup = [];
$lookup_query = "SELECT gl_description_comparative, gl_mapping 
                 FROM fs_reports.gl_codes_ho_new 
                 WHERE gl_description_comparative IS NOT NULL";
$lookup_res = mysqli_query($conn, $lookup_query);

while ($row = mysqli_fetch_assoc($lookup_res)) {
    $mapping_lookup[$row['gl_description_comparative']] = $row['gl_mapping'];
}
$mapping_json = json_encode($mapping_lookup);

// Build Description -> Comparative list for filtered dropdowns
$comps_by_desc = [];
$comp_by_desc_query = "SELECT description, gl_description_comparative
                       FROM fs_reports.gl_codes_ho_new
                       WHERE description IS NOT NULL
                         AND description != ''
                         AND gl_description_comparative IS NOT NULL
                         AND gl_description_comparative != ''
                       ORDER BY sort_order ASC, sub_order ASC";
$comp_by_desc_res = mysqli_query($conn, $comp_by_desc_query);
while ($row = mysqli_fetch_assoc($comp_by_desc_res)) {
    $d = $row['description'];
    $c = $row['gl_description_comparative'];
    if (!isset($comps_by_desc[$d])) {
        $comps_by_desc[$d] = [];
    }
    if (!in_array($c, $comps_by_desc[$d], true)) {
        $comps_by_desc[$d][] = $c;
    }
}
$comps_by_desc_json = json_encode($comps_by_desc);

// if (isset($_GET['status']) && $_GET['status'] === 'save_success') {
//     $status_message = "Saved successfully.";
//     $status_type = 'success';
// }

// Fetch distinct regions, areas, and transaction years for filters
$regions_query = "SELECT DISTINCT region FROM fs_reports.comparative_report WHERE region IS NOT NULL ORDER BY region";
$regions_res = mysqli_query($conn, $regions_query);

// Build region array with "All Regions" option
$regions_with_all = [];
if ($regions_res) {
    while ($row = mysqli_fetch_assoc($regions_res)) {
        $regions_with_all[] = $row['region'];
    }
}

$areas_query = "SELECT DISTINCT area, region FROM fs_reports.comparative_report WHERE area IS NOT NULL ORDER BY region, area";
$areas_res = mysqli_query($conn, $areas_query);

// Build area mapping by region
$areas_by_region = [];
while ($area_row = mysqli_fetch_assoc($areas_res)) {
    $region = $area_row['region'];
    $area = $area_row['area'];
    if (!isset($areas_by_region[$region])) {
        $areas_by_region[$region] = [];
    }
    $areas_by_region[$region][] = $area;
}

$years_query = "SELECT DISTINCT transaction_year FROM fs_reports.comparative_report WHERE transaction_year IS NOT NULL ORDER BY transaction_year DESC";
$years_res = mysqli_query($conn, $years_query);


// Fetch distinct month-years for the new Monthly Comparison filter
$month_options = [];
$months_query = "
    SELECT DATE_FORMAT(MAX(transaction_month), '%Y-%m') AS ym,
           DATE_FORMAT(MAX(transaction_month), '%M %Y') AS label
    FROM fs_reports.comparative_report
    WHERE transaction_month IS NOT NULL
      AND transaction_month >= '1000-01-01'
    GROUP BY DATE_FORMAT(transaction_month, '%Y-%m')
    ORDER BY MAX(transaction_month) DESC
";

$months_res = mysqli_query($conn, $months_query);

if ($months_res) {
    while ($row = mysqli_fetch_assoc($months_res)) {
        $month_options[] = $row;
    }
}

$month_names = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cumulative Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/cumu.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
        <h2 style="text-align: center; margin-top: -2%;">Comparative Report (Cumulative Report)</h3>


        <div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button id="openModalBtn" class="btn-add"><i class="fas fa-plus"></i> Add Row</button>
            <input type="text" id="glSearchInput" placeholder="Search description, row description, GL code, GL description" style="flex: 1; min-width: 260px; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px;">
        </div>

        <h3 style="text-align: center;">GL Codes Overview</h3>

        <section class="table-container">
            <?php if (isset($error_message)): ?>
                <p style="color: red;"><?php echo $error_message; ?></p>
            <?php else: ?>
                <table class="gl-table" id="glTable">
                    <thead>
                        <tr>
                            <th>Drag</th>
                            <th>GL ID</th>
                            <th>Sort Order</th>
                            <th>Description</th>
                            <th>Sub Order</th>
                            <th>Comparative Report Description</th>
                            <th>GL Code</th>
                            <th>GL Description</th>
                            <th>New GL Code</th>
                            <th>New GL Description</th>
                            <th>GL Mapping/Shortcut</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
    <?php
    $group_counter = 0;
    foreach ($desc_list as $desc) {
        $show_header = ($desc !== '');
        if ($show_header) {
            $group_counter++;
        }

        $subgroup_index = 0;
        foreach ($sub_orders[$desc] as $comp) {
            $subgroup_index++;
            $sub_order_str = $sub_order_map[$desc][$comp] ?? '';
            $gl_id_str = $gl_id_map[$desc][$comp] ?? '';

            $sub_rows = $grouped[$desc][$comp] ?? [];

            // Sort individual GL rows within the sub-group by ID (oldest first, newest last)
            usort($sub_rows, function($a, $b) {
                return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
            });

            foreach ($sub_rows as $sidx => $row) {
                $is_first = ($sidx === 0);
                $show_sub_order = $is_first ? $sub_order_str : '';
                $show_gl_id = $is_first ? $gl_id_str : '';
                $show_drag = $is_first && $show_header;

                $group_key = $desc . '||' . $comp;
                ?>
                <tr data-id="<?php echo htmlspecialchars($row['id']); ?>"
                    data-description="<?php echo htmlspecialchars($desc); ?>"
                    data-glcomp="<?php echo htmlspecialchars($comp); ?>"
                    data-glmap="<?php echo htmlspecialchars($row['gl_mapping'] ?? ''); ?>"
                    data-sortorder="<?php echo htmlspecialchars($row['sort_order'] ?? ''); ?>"
                    data-suborder="<?php echo htmlspecialchars($row['sub_order'] ?? ''); ?>"
                    data-group="<?php echo htmlspecialchars($group_key); ?>">
                    <td class="drag-cell">
                        <?php if ($show_drag): ?>
                            <span class="drag-handle" title="Drag to reorder"><i class="fa fa-arrows" aria-hidden="true"></i></span>
                        <?php endif; ?>
                    </td>
                        <td style="text-align: center; color: #000000;"><?php echo htmlspecialchars($show_gl_id); ?></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: center; color: #000000;"><?php echo htmlspecialchars($show_sub_order); ?></td>
                    <td><?php echo htmlspecialchars($comp); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['gl_code'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['gl_description'] ?? ''); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['new_gl_code'] ?? ''); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['new_gl_description'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['gl_mapping'] ?? ''); ?></td>
                    <td style="text-align: center;">
                        <button type="button" class="btn-delete-row" data-id="<?php echo htmlspecialchars($row['id']); ?>"><i class="fa-solid fa-trash-can" style="color: white;"></i></button>
                    </td>
                </tr>
                <?php
            }
        }

        // Print the category header row after its sub-orders
        if ($show_header) {
            ?>
            <tr class="category-header-row" style="background-color: #f2f4f6; border-bottom: 2px solid #dee2e6;">
                <td class="drag-cell"></td>
                <td></td>
                <td style="text-align: center; font-weight: bold;"><?php echo $group_counter; ?></td>
                <td style="text-align: center; font-weight: bold; color: #2c3e50; letter-spacing: 1px;">
                    <?php echo htmlspecialchars($desc); ?>
                </td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php
        }
    }
    ?>
</tbody>
                </table>
            <?php endif; ?>
        </section>

        <div style="margin-top: 20px; text-align: right; display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn-preview" id="previewTableBtn">
                <i class="fa-solid fa-eye"></i> Preview Table
            </button>
            <a href="comparative_report_original_cumu.php" style="text-decoration: none;" class="btn-preview">Generate Report</a>

        </div>
        </div>

    </main>

    <!-- Modal (unchanged) -->
    <div id="glModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Row</h3>
                <span class="close-btn">&times;</span>
            </div>
            <form id="addGlForm" method="post" action="">
                <div class="form-section">
                    <label>Description</label>
                    <div class="radio-group">
                        <label><input type="radio" name="desc_type" value="new" checked> Create new description</label>
                        <label><input type="radio" name="desc_type" value="existing"> Use existing description</label>
                    </div>
                    <div id="desc_input_container">
                        <input type="text" name="description" placeholder="Enter new description" required>
                    </div>
                </div>

                <div class="form-section">
                    <label>Row Description (Comparative Report Description)</label>
                    <div class="radio-group">
                        <label><input type="radio" name="row_desc_type" value="new" checked> Create new row description</label>
                        <label><input type="radio" name="row_desc_type" value="existing"> Use existing row description</label>
                    </div>
                    <div id="row_desc_input_container">
                        <input type="text" name="gl_description_comparative" placeholder="Enter new row description" required>
                    </div>
                </div>

                <div class="form-section">
                    <label>GL Code</label>
                    <input type="text" name="gl_code">
                </div>

                <div class="form-section">
                    <label>GL Description</label>
                    <input type="text" name="gl_description">
                </div>

                <div class="form-section">
                    <label>New GL Code</label>
                    <input type="text" name="new_gl_code">
                </div>

                <div class="form-section">
                    <label>New GL Description</label>
                    <input type="text" name="new_gl_description">
                </div>

                <div class="form-section">
                    <label>GL Mapping/Shortcut</label>
                    <input type="text" name="gl_mapping" placeholder="Enter shortcut version. Words may be separated by underscore ( _ ).">
                    <small>The combination of Row Description + GL Mapping must be unique. The same GL Mapping cannot be used for different Row Descriptions.</small>
                </div>

                <div class="modal-footer">
                    <a href="fs_reports.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-save">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Modal -->
    <div id="statusModal" class="status-modal" aria-hidden="true">
        <div class="status-modal-content">
            <div class="status-modal-header">
                <h3 id="statusModalTitle">Notice</h3>
                <span class="status-close-btn">&times;</span>
            </div>
            <div class="status-modal-body" id="statusModalBody"></div>
            <div class="status-modal-footer">
                <button type="button" class="btn-ok" id="statusOkBtn">OK</button>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
<div id="previewModal" class="preview-modal" aria-hidden="true">
    <div class="preview-modal-content">
        <div class="preview-modal-header">
            <h3>Comparative Report (CUMULATIVE REPORT)</h3>
             <button type="button" class="btn-collapse" id="previewCollapseBtn">
                    <i class="fa-solid fa-bars"></i> Collapse View
                </button>
            <button class="preview-close-btn">&times;</button>
        </div>
        
        <!-- Filter Form -->
        <div class="preview-filter-section">
    <form id="previewFilterForm" class="preview-filter-form">
        <div class="filter-row">
          <div class="filter-group">
    <label style="display: block; text-align: center;">Month Range  <span style="font-size: 10px; font-style: italic; color: red;"> (e.g. January - March)</span> </label>
    <div style="display: flex; gap: 10px;">
        <div style="flex: 1;">
            <select name="start_month"
                    class="filter-select"
                    style="width: 90%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">Start Month</option>
                <?php foreach ($month_names as $num => $label): ?>
                    <option value="<?= $num ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <small style="color: #ff0000; display: block; text-align: center; margin-top: 10px; font-weight: bold;">TO</small>
        <div style="flex: 1;">
            <select name="end_month"
                    class="filter-select"
                    style="width: 90%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="">End Month</option>
                <?php foreach ($month_names as $num => $label): ?>
                    <option value="<?= $num ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

            <!-- Transaction Year + Filter Actions -->
            <div class="filter-group transaction-year-group">
                <label>Transaction Year</label>
                <div class="transaction-year-row">
                    <div class="custom-select-wrapper" style="flex:1;">
                        <select name="transaction_year[]" id="transaction_year" multiple size="1" style="width: 100%;">
                            <?php 
                            if ($years_res) {
                                mysqli_data_seek($years_res, 0);
                                while($y = mysqli_fetch_assoc($years_res)): 
                            ?>
                                <option value="<?= htmlspecialchars($y['transaction_year']) ?>">
                                    <?= htmlspecialchars($y['transaction_year']) ?>
                                </option>
                            <?php 
                                endwhile; 
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-actions" style="margin-top:0; flex-shrink:0;">
                        <button type="submit" class="btn-apply-filter">
                            <i class="fa-solid fa-filter"></i> Apply Filter
                        </button>
                        <button type="button" class="btn-reset-filter" id="resetFilterBtn">
                            <i class="fa-solid fa-rotate-left"></i> Clear
                        </button>
                       
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
        
        <div class="preview-modal-body" id="previewTablesContainer"></div>

        <template id="previewTableTemplate">
            <table class="preview-table">
                <thead>
                    <!-- <tr>
                        <th colspan="14">NATIONWIDE</th>
                        <th></th>
                        <th></th>
                    </tr>
                    <tr>
                        <th colspan="8"></th>
                        <th colspan="4" style="text-align: center; background-color: #ff0000;">INCREASE/DECREASE</th>
                        <th></th>
                        <th></th>
                    </tr> -->
                    <tr>
                        <th colspan="4">NATIONWIDE</th>
                        <th class="preview-current-year-header">-</th>
                        <th class="preview-period2-header">-</th>
                        <th class="preview-period3-header">-</th>
                        <th></th>
                        <th style="text-align: center;" class="preview-variance1-header">YEAR VS YEAR<br>INC. / DEC.</th>
                        <th style="text-align: center;">%</th>
                        <th style="text-align: center;" class="preview-variance2-header">YEAR VS YEAR<br>INC. / DEC.</th>
                        <th style="text-align: center;">%</th>
                        <th></th>
                        <th></th>
                    </tr>
                    
                </thead>
                <tbody class="preview-table-spacer-body">
                    <tr>
                        <?php for ($i = 0; $i < 14; $i++): ?>
                            <th></th>
                        <?php endfor; ?>
                    </tr>
                    <tr style="background-color: #ff7f3a; font-weight: bold;">
                        <td colspan="2">Revenues</td>
                        <?php for ($i = 0; $i < 12; $i++): ?>
                            <td></td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
                <tbody class="preview-table-body"></tbody>
            </table>
        </template>
        <div class="preview-modal-footer">
             <!-- <button type="button" class="btn-excel" id="exportExcelBtn">
                            <i class="fa-solid fa-file-excel"></i> Export to Excel
                        </button> -->
                        <button type="button" class="btn-excel" id="exportSummaryExcelBtn">
                            <i class="fa-solid fa-file-excel"></i> Export to Excel
                        </button>
                           
            <button type="button" class="btn-ok" id="previewCloseBtn"><i class="fa-solid fa-x"></i> Close</button>
            
        </div>
    </div>
</div>

   <script>
    const modal = document.getElementById("glModal");
    const btn = document.getElementById("openModalBtn");
    const spans = document.getElementsByClassName("close-btn");

    // Open modal
    btn.onclick = () => modal.style.display = "flex";

    // Close modal
    for (let span of spans) {
        span.onclick = () => modal.style.display = "none";
    }
    // window.onclick = (event) => {
    //     if (event.target == modal) modal.style.display = "none";
    // }

    // Description toggle
    document.querySelectorAll('input[name="desc_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const rowDescType = document.querySelector('input[name="row_desc_type"]:checked').value;
            if (this.value === 'new' && rowDescType === 'existing') {
                alert('You cannot use exisiting row description for a new description.');
                const rowNewRadio = document.querySelector('input[name="row_desc_type"][value="new"]');
                rowNewRadio.checked = true;
                rowNewRadio.dispatchEvent(new Event('change'));
                return;
            }

            const container = document.getElementById('desc_input_container');
            if (this.value === 'existing') {
                container.innerHTML = `
                    <select name="description" id="existing_desc" required>
                        <option value="">Select Existing...</option>
                        <?php 
                        if ($distinct_desc_res) {
                            mysqli_data_seek($distinct_desc_res, 0);
                            while($d = mysqli_fetch_assoc($distinct_desc_res)): 
                        ?>
                            <option value="<?= htmlspecialchars($d['description']) ?>"><?= htmlspecialchars($d['description']) ?></option>
                        <?php 
                            endwhile; 
                        }
                        ?>
                    </select>`;

                const existingDesc = document.getElementById('existing_desc');
                if (existingDesc) {
                    existingDesc.addEventListener('change', function() {
                        renderExistingRowDescOptions(this.value);
                    });
                }
            } else {
                container.innerHTML = '<input type="text" name="description" placeholder="Enter new description" required>';
                renderExistingRowDescOptions('');
            }
        });
    });

    // Pass the PHP array to JavaScript
    const mappingData = <?php echo $mapping_json; ?>;
    const compsByDesc = <?php echo $comps_by_desc_json; ?>;

    function renderExistingRowDescOptions(selectedDescription = '') {
        const container = document.getElementById('row_desc_input_container');
        const rowDescRadio = document.querySelector('input[name="row_desc_type"]:checked');
        if (!container || !rowDescRadio || rowDescRadio.value !== 'existing') return;

        let optionsHtml = '<option value="">Select Existing...</option>';

        if (selectedDescription) {
            const list = compsByDesc[selectedDescription] || [];
            list.forEach((val) => {
                optionsHtml += `<option value="${val.replace(/"/g, '&quot;')}">${val}</option>`;
            });
        } else {
            <?php 
            if ($distinct_comp_res) {
                mysqli_data_seek($distinct_comp_res, 0);
                while($c = mysqli_fetch_assoc($distinct_comp_res)): 
            ?>
                optionsHtml += `<option value="<?= htmlspecialchars($c['gl_description_comparative']) ?>"><?= htmlspecialchars($c['gl_description_comparative']) ?></option>`;
            <?php 
                endwhile; 
            }
            ?>
        }

        container.innerHTML = `
            <select name="gl_description_comparative" id="existing_row_desc" required>
                ${optionsHtml}
            </select>`;

        const mappingInput = document.querySelector('input[name="gl_mapping"]');
        if (mappingInput) {
            mappingInput.readOnly = true;
            mappingInput.style.backgroundColor = "#e9ecef";
            mappingInput.style.cursor = "not-allowed";
        }

        const dropdown = document.getElementById('existing_row_desc');
        if (dropdown && mappingInput) {
            dropdown.addEventListener('change', function() {
                const selectedVal = this.value;
                mappingInput.value = mappingData[selectedVal] || '';
            });
        }
    }

    document.querySelectorAll('input[name="row_desc_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const container = document.getElementById('row_desc_input_container');
            const mappingInput = document.querySelector('input[name="gl_mapping"]');
            
            const descType = document.querySelector('input[name="desc_type"]:checked').value;
            if (this.value === 'existing' && descType === 'new') {
                alert('You cannot use exisiting row description for a new description.');
                const rowNewRadio = document.querySelector('input[name="row_desc_type"][value="new"]');
                rowNewRadio.checked = true;
                rowNewRadio.dispatchEvent(new Event('change'));
                return;
            }

            if (this.value === 'existing') {
                const descSelect = document.getElementById('existing_desc');
                const selectedDesc = descSelect ? descSelect.value : '';
                renderExistingRowDescOptions(selectedDesc);
            } else {
                container.innerHTML = '<input type="text" name="gl_description_comparative" placeholder="Enter new row description" required>';
                mappingInput.value = ''; 
                mappingInput.readOnly = false;
                mappingInput.style.backgroundColor = ""; 
                mappingInput.style.cursor = "text";
            }
        });
    });

    // Drag-and-drop reordering (grouped by distinct gl_description_comparative)
    const tbody = document.querySelector('#glTable tbody');
    const glSearchInput = document.getElementById('glSearchInput');
    let draggingGroupKey = null;
    let draggingDescription = null;
    let initialOrderIds = null;
    let initialGroupOrderSignature = null;

    function getGroupOrderSignature() {
        const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        const seen = new Set();
        const order = [];
        rows.forEach((row) => {
            const group = row.dataset.group || '';
            if (!seen.has(group)) {
                seen.add(group);
                order.push(group);
            }
        });
        return order.join('|');
    }

    function getDataRowIds() {
        return Array.from(tbody.querySelectorAll('tr[data-id]')).map(r => r.dataset.id);
    }

    function restoreRowOrder(ids) {
        if (!Array.isArray(ids) || ids.length === 0) return;
        const rowMap = new Map();
        tbody.querySelectorAll('tr[data-id]').forEach(r => rowMap.set(r.dataset.id, r));
        ids.forEach(id => {
            const row = rowMap.get(String(id));
            if (row) tbody.appendChild(row);
        });

        // Keep category headers positioned after the last row of each description
        const headerRows = Array.from(tbody.querySelectorAll('tr.category-header-row'));
        headerRows.forEach(header => {
            const descCell = header.querySelector('td:nth-child(3)');
            const desc = descCell ? descCell.textContent.trim() : '';
            if (!desc) return;
            const dataRows = Array.from(tbody.querySelectorAll(`tr[data-id][data-description="${desc}"]`));
            if (dataRows.length === 0) return;
            const last = dataRows[dataRows.length - 1];
            tbody.insertBefore(header, last.nextSibling);
        });
    }

    function getGroupRows(groupKey) {
        return Array.from(tbody.querySelectorAll(`tr[data-id][data-group="${groupKey}"]`));
    }

    function applyGlSearchFilter() {
        if (!glSearchInput || !tbody) return;
        const q = glSearchInput.value.trim().toLowerCase();
        const dataRows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        const headerRows = Array.from(tbody.querySelectorAll('tr.category-header-row'));

        if (!q) {
            dataRows.forEach(r => r.style.display = '');
            headerRows.forEach(r => r.style.display = '');
            return;
        }

        const matchedDescs = new Set();
        dataRows.forEach(row => {
            const desc = row.dataset.description || '';
            const comp = row.dataset.glcomp || '';
            const glCode = (row.querySelector('td:nth-child(6)')?.textContent || '').trim();
            const glDesc = (row.querySelector('td:nth-child(7)')?.textContent || '').trim();
            const hay = `${desc} ${comp} ${glCode} ${glDesc}`.toLowerCase();
            const hit = hay.includes(q);
            row.style.display = hit ? '' : 'none';
            if (hit && desc) matchedDescs.add(desc);
        });

        headerRows.forEach(header => {
            const descCell = header.querySelector('td:nth-child(3)');
            const desc = descCell ? descCell.textContent.trim() : '';
            header.style.display = matchedDescs.has(desc) ? '' : 'none';
        });
    }

    tbody.querySelectorAll('tr[data-id]').forEach(row => {
        const handle = row.querySelector('.drag-handle');
        if (handle) {
            handle.setAttribute('draggable', 'true');
            handle.addEventListener('dragstart', (e) => {
                draggingGroupKey = row.dataset.group || '';
                draggingDescription = row.dataset.description || '';
                initialOrderIds = getDataRowIds();
                initialGroupOrderSignature = getGroupOrderSignature();
                getGroupRows(draggingGroupKey).forEach(r => r.classList.add('dragging'));
                e.dataTransfer.effectAllowed = 'move';
            });
            handle.addEventListener('dragend', () => {
                getGroupRows(draggingGroupKey).forEach(r => r.classList.remove('dragging'));
                draggingGroupKey = null;
                draggingDescription = null;
            });
        }
    });

    if (glSearchInput) {
        glSearchInput.addEventListener('input', applyGlSearchFilter);
    }

    tbody.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!draggingGroupKey) return;

        const groupRows = getGroupRows(draggingGroupKey);
        if (groupRows.length === 0) return;

        const target = getTargetGroupElement(tbody, e.clientY, draggingGroupKey, draggingDescription);
        if (!target) return;

        if (target.insertAfter) {
            groupRows.forEach(r => tbody.insertBefore(r, target.last.nextSibling));
        } else {
            groupRows.forEach(r => tbody.insertBefore(r, target.first));
        }
    });

    tbody.addEventListener('drop', () => {
        if (!draggingGroupKey) return;

        const currentSignature = getGroupOrderSignature();
        if (!initialGroupOrderSignature || currentSignature === initialGroupOrderSignature) {
            return;
        }

        if (!confirm('Save New Order?')) {
            restoreRowOrder(initialOrderIds);
            return;
        }

        // Collect the new order of rows
        const dataRows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        const orderData = [];

        let currentDesc = null;
        let subCounter = 0;

        dataRows.forEach(row => {
            const desc = row.dataset.description || '';
            const comp = row.dataset.glcomp || '';
            const id = row.dataset.id;

            if (desc !== currentDesc) {
                currentDesc = desc;
                subCounter = 0;
            }

            const existingInDesc = orderData.filter(item =>
                item.description === desc && item.gl_description_comparative === comp
            );

            if (existingInDesc.length === 0) {
                subCounter++;
            }

            orderData.push({
                id: id,
                description: desc,
                gl_description_comparative: comp,
                sub_order: subCounter
            });
        });

        fetch('save_sub_order_cumu_ho.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ orderData })
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok) {
                showStatusModal('Order saved successfully!', 'success', true);
            } else {
                showStatusModal(data.error || 'Failed to save order', 'error');
            }
        })
        .catch(() => showStatusModal('Failed to save order', 'error'));
    });

    function getTargetGroupElement(container, y, draggingGroup, draggingDesc) {
        const rows = [...container.querySelectorAll('tr[data-id]:not(.dragging)')]
            .filter(tr => tr.dataset.group !== draggingGroup && tr.dataset.description === draggingDesc);
        if (rows.length === 0) return null;

        let closest = null;
        let closestDist = Infinity;
        rows.forEach(row => {
            const box = row.getBoundingClientRect();
            const center = box.top + box.height / 2;
            const dist = Math.abs(y - center);
            if (dist < closestDist) {
                closestDist = dist;
                closest = row;
            }
        });

        if (!closest) return null;

        const targetGroup = closest.dataset.group;
        const targetRows = getGroupRows(targetGroup);
        if (targetRows.length === 0) return null;

        const first = targetRows[0];
        const last = targetRows[targetRows.length - 1];
        const lastBox = last.getBoundingClientRect();
        const insertAfter = y > (lastBox.top + lastBox.height / 2);

        return { first, last, insertAfter };
    }

    // Delete row
    document.querySelectorAll('.btn-delete-row').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!id) return;
            if (!confirm('Delete this row?')) return;
            fetch('delete_gl_row_cumu_ho.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    showStatusModal('Deleted successfully.', 'success', true);
                } else {
                    showStatusModal(data.error || 'Delete failed', 'error');
                }
            })
            .catch(() => showStatusModal('Delete failed', 'error'));
        });
    });

    // GL Mapping: force lowercase, block spaces on submit
    const glMappingInput = document.querySelector('input[name="gl_mapping"]');
    const addGlForm = document.getElementById('addGlForm');

    if (glMappingInput) {
        glMappingInput.addEventListener('input', () => {
            const cursor = glMappingInput.selectionStart;
            glMappingInput.value = glMappingInput.value.toLowerCase();
            glMappingInput.setSelectionRange(cursor, cursor);
        });
    }

    if (addGlForm) {
        addGlForm.addEventListener('submit', (e) => {
            const value = (glMappingInput?.value || '').trim();
            if (/\s/.test(value)) {
                e.preventDefault();
                showStatusModal('GL Mapping must be lowercase and use underscores instead of spaces.', 'error');
            }
        });
    }

    // Status modal helpers
    const statusModal = document.getElementById('statusModal');
    const statusModalTitle = document.getElementById('statusModalTitle');
    const statusModalBody = document.getElementById('statusModalBody');
    const statusCloseBtn = document.querySelector('.status-close-btn');
    const statusOkBtn = document.getElementById('statusOkBtn');
    let reloadOnClose = false;

    function showStatusModal(message, type = 'success', shouldReload = false) {
        statusModalTitle.textContent = type === 'error' ? 'Error' : 'Success';
        statusModalBody.textContent = message;
        statusModal.classList.add('open');
        statusModal.setAttribute('aria-hidden', 'false');
        reloadOnClose = shouldReload;
    }

    function closeStatusModal() {
        statusModal.classList.remove('open');
        statusModal.setAttribute('aria-hidden', 'true');
        if (reloadOnClose) {
            location.reload();
        }
    }

    statusCloseBtn.addEventListener('click', closeStatusModal);
    statusOkBtn.addEventListener('click', closeStatusModal);
    statusModal.addEventListener('click', (e) => {
        if (e.target === statusModal) closeStatusModal();
    });

    const initialStatusMessage = <?php echo json_encode($status_message); ?>;
    const initialStatusType = <?php echo json_encode($status_type); ?>;
    if (initialStatusMessage) {
        showStatusModal(initialStatusMessage, initialStatusType);
    }

    // Preview Modal Functionality
    const previewModal = document.getElementById('previewModal');
    const previewTableBtn = document.getElementById('previewTableBtn');
    const previewCloseBtn = document.getElementById('previewCloseBtn');
    const previewCloseBtnX = document.querySelector('.preview-close-btn');
    const previewTablesContainer = document.getElementById('previewTablesContainer');
    const previewTableTemplate = document.getElementById('previewTableTemplate');

    function getCurrentPreviewFilters() {
    const form = document.getElementById('previewFilterForm');
    if (!form) return { years: [], start_month: '', end_month: '' };
    const formData = new FormData(form);
    
    return {
        years: formData.getAll('transaction_year[]'),
        start_month: formData.get('start_month') || '',
        end_month: formData.get('end_month') || ''
    };
}

    // Open preview modal
    if (previewTableBtn) {
        previewTableBtn.addEventListener('click', () => {
            const { years, start_month, end_month } = getCurrentPreviewFilters();
            generatePreviewTable(years, start_month, end_month);
            previewModal.classList.add('open');
            previewModal.setAttribute('aria-hidden', 'false');
        });
    }

    // Close preview modal
    function closePreviewModal() {
        previewModal.classList.remove('open');
        previewModal.setAttribute('aria-hidden', 'true');
    }

    if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreviewModal);
    if (previewCloseBtnX) previewCloseBtnX.addEventListener('click', closePreviewModal);
    // previewModal.addEventListener('click', (e) => {
    //     if (e.target === previewModal) closePreviewModal();
    // });

// Modified generatePreviewTable function
async function generatePreviewTable(years = [], start_month = '', end_month = '') {
    if (!previewTablesContainer || !previewTableTemplate) return;

    // Show loading indicator immediately
    previewTablesContainer.innerHTML = `
        <div class="loading-container">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <p>Loading excel preview...</p>
        </div>
    `;

    try {
        // Fetch consolidated totals (no region/area loop)
        const data = await fetchPreviewTotals(years, start_month, end_month);
        const totals = data.totals || {};
        const areaKey = '_all';

        // Clear loading and display content
        previewTablesContainer.innerHTML = '';

        if (!totals[areaKey] || Object.keys(totals[areaKey]).length === 0) {
            previewTablesContainer.innerHTML = `
                <div class="no-data-message">
                    <i class="fa-solid fa-database" style="font-size: 48px; color: #999; margin-bottom: 15px;"></i>
                    <p style="font-size: 18px; color: #666; margin: 0;">No data found for this filter.</p>
                    <p style="font-size: 14px; color: #999; margin-top: 10px;">Or there no records found.</p>
                </div>
            `;
        } else {
            const fragment = previewTableTemplate.content.cloneNode(true);
            const table = fragment.querySelector('table');
            const tbodyEl = fragment.querySelector('.preview-table-body');

            if (table && tbodyEl) {
                updatePreviewHeadersForTable(table, data);
                buildPreviewTableBody(tbodyEl, totals[areaKey] || {}, data);
                applyNegativeAmountStyling(tbodyEl);

                const tableContainer = document.createElement('div');
                tableContainer.className = 'table-container';
                tableContainer.appendChild(table);

                const tableWrapper = document.createElement('div');
                tableWrapper.className = 'table-wrapper';
                tableWrapper.style.width = '100%'; // Full width
                tableWrapper.appendChild(tableContainer);

                previewTablesContainer.appendChild(tableWrapper);
            }
        }
    } catch (err) {
        // Show error instead of loading
        previewTablesContainer.innerHTML = `
            <div class="no-data-message">
                <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: #ff6b6b; margin-bottom: 15px;"></i>
                <p style="font-size: 18px; color: #666; margin: 0;">Error loading data</p>
                <p style="font-size: 14px; color: #999; margin-top: 10px;">${err.message || 'Please try again'}</p>
            </div>
        `;
        console.error('Preview generation error:', err);
    }
}

    async function fetchPreviewTotals(years, start_month = '', end_month = '') {
        const res = await fetch('fetch_preview_totals_cumu.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ years, start_month, end_month })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Failed to load preview data');
        }
        return data;
    }

    function updatePreviewHeadersForTable(table, data) {
        const currentHeader = table.querySelector('.preview-current-year-header');
        const period2Header = table.querySelector('.preview-period2-header');
        const period3Header = table.querySelector('.preview-period3-header');
        const variance1Header = table.querySelector('.preview-variance1-header');
        const variance2Header = table.querySelector('.preview-variance2-header');

        if (currentHeader) currentHeader.textContent = data.current_label || '(Primary Period)';
        if (period2Header) period2Header.textContent = data.period2_label || '(Period 2)';
        if (period3Header) period3Header.textContent = data.period3_label || '(Period 3)';
        if (variance1Header) variance1Header.innerHTML = (data.variance1_label || 'YEAR VS YEAR') + '<br>INC. / DEC.';
        if (variance2Header) variance2Header.innerHTML = (data.variance2_label || 'YEAR VS YEAR') + '<br>INC. / DEC.';
    }

    function buildPreviewTableBody(previewTableBody, totalsForArea, data) {
        const dataRows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        const processedGroups = new Set();

        let mainCounter = 0;
        let currentDesc = null;
        let currentDescSortOrder = null;
        let subCounter = 0;

        let pendingCategoryRow = null;
        let pendingTotals = null;

        const current_period = data.current_period;
        const period2 = data.period2;
        const period3 = data.period3;
        const has_period2 = period2 != null;
        const has_period3 = period3 != null;

        // Helper to init totals object
        const initTotals = () => ({
            p1: 0, p2: 0, p3: 0
        });

        let revenueInserted = false;
        let grossProfitInserted = false;
        let sellingAdminInserted = false;
        let ebitInserted = false;
        let ebtInserted = false;
        let netIncomeInserted = false;

        const revenueTotals = initTotals();
        const costTotals = initTotals();
        const sellingAdminTotals = initTotals();
        const operatingTotals = initTotals();
        const interestTotals = initTotals();
        const taxTotals = initTotals();

        const summaryOnlySortOrders = new Set([10,13]); // show only category total row
        const detailOnlySortOrders = new Set([26, 27, 28]); // show only column C detail rows

        dataRows.forEach((row, index) => {
            const desc = row.dataset.description || '';
            const comp = row.dataset.glcomp || '';
            const mapKey = row.dataset.glmap || comp;
            const group = desc + '||' + mapKey;
            const sortOrderVal = Number(row.dataset.sortorder || 0);
            const subOrderVal = Number(row.dataset.suborder || 0);


            if (processedGroups.has(group)) return;
            processedGroups.add(group);

            const isDeduction = (mapKey === "less_sales_return_discount");

            if (desc !== currentDesc) {
                if (pendingCategoryRow) {
                    applyCategoryTotals(pendingCategoryRow, pendingTotals, has_period2, has_period3);
                    previewTableBody.appendChild(pendingCategoryRow);
                    const spacerRow = document.createElement('tr');
                    spacerRow.className = 'preview-category-spacer-row';
                    spacerRow.innerHTML = '<td colspan="14">&nbsp;</td>';
                    if (currentDescSortOrder >= 1 && currentDescSortOrder <= 22) {
                        spacerRow.classList.add('collapsible-detail');
                    }
                    previewTableBody.appendChild(spacerRow);
                }

                // Insert section totals when crossing thresholds
                if (!revenueInserted && currentDescSortOrder !== null && currentDescSortOrder <= 22 && sortOrderVal > 22) {
                    previewTableBody.appendChild(buildTotalRevenuesRow(revenueTotals, has_period2, has_period3));
                    const spacerRow = document.createElement('tr');
                    spacerRow.className = 'preview-category-spacer-row';
                    spacerRow.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRow);
                    previewTableBody.appendChild(buildSectionHeaderRow('Cost of Sales/Service'));
                    revenueInserted = true;
                }
                if (!grossProfitInserted && currentDescSortOrder !== null && currentDescSortOrder <= 23 && sortOrderVal > 23) {
                    const spacerRowGp = document.createElement('tr');
                    spacerRowGp.className = 'preview-category-spacer-row';
                    previewTableBody.appendChild(spacerRowGp);
                    previewTableBody.appendChild(buildGrossProfitRow(revenueTotals, costTotals, has_period2, has_period3));
                    const spacerRowGpAfter = document.createElement('tr');
                    spacerRowGpAfter.className = 'preview-category-spacer-row';
                    spacerRowGpAfter.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowGpAfter);
                    previewTableBody.appendChild(buildLabelRow('SELLING & ADMIN EXPENSE'));
                    grossProfitInserted = true;
                }
                if (!sellingAdminInserted && currentDescSortOrder !== null && currentDescSortOrder <= 25 && sortOrderVal > 25) {
                    const spacerRowSa = document.createElement('tr');
                    spacerRowSa.className = 'preview-category-spacer-row';
                    previewTableBody.appendChild(spacerRowSa);
                    previewTableBody.appendChild(buildTotalSellingAdminRow(sellingAdminTotals, has_period2, has_period3));
                    const spacerRowEbitda = document.createElement('tr');
                    spacerRowEbitda.className = 'preview-category-spacer-row';
                    spacerRowEbitda.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowEbitda);
                    previewTableBody.appendChild(buildEarningsBeforeRow(revenueTotals, costTotals, sellingAdminTotals, has_period2, has_period3));
                    sellingAdminInserted = true;
                }
               if (!ebitInserted && currentDescSortOrder !== null && currentDescSortOrder <= 26 && sortOrderVal > 26) {
                    const spacerRowEbit = document.createElement('tr');
                    spacerRowEbit.className = 'preview-category-spacer-row';
                    spacerRowEbit.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowEbit);
                    previewTableBody.appendChild(buildEarningsBeforeInterestTaxesRow(revenueTotals, costTotals, sellingAdminTotals, operatingTotals, has_period2, has_period3));
                    ebitInserted = true;
                }
                                if (!ebtInserted && currentDescSortOrder !== null && currentDescSortOrder <= 27 && sortOrderVal > 27) {
                    const spacerRowEbt = document.createElement('tr');
                    spacerRowEbt.className = 'preview-category-spacer-row';
                    spacerRowEbt.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowEbt);
                    previewTableBody.appendChild(buildEarningsBeforeTaxesRow(revenueTotals, costTotals, sellingAdminTotals, operatingTotals, interestTotals, has_period2, has_period3));
                    ebtInserted = true;
                }
                                if (!netIncomeInserted && currentDescSortOrder !== null && currentDescSortOrder <= 28 && sortOrderVal > 28) {
                    const spacerRowNet = document.createElement('tr');
                    spacerRowNet.className = 'preview-category-spacer-row';
                    spacerRowNet.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowNet);
                    previewTableBody.appendChild(buildTotalNetIncomeLossRow(revenueTotals, costTotals, sellingAdminTotals, operatingTotals, interestTotals, taxTotals, has_period2, has_period3));
                    netIncomeInserted = true;
                }

                currentDesc = desc;
                currentDescSortOrder = sortOrderVal || currentDescSortOrder;
                subCounter = 0;
                pendingTotals = initTotals();

                if (desc !== '' && !detailOnlySortOrders.has(sortOrderVal)) {
                    mainCounter++;
                    pendingCategoryRow = document.createElement('tr');
                    pendingCategoryRow.className = 'preview-category-row';
                    pendingCategoryRow.innerHTML = `
                        <td style="text-align: center;">${mainCounter}</td>
                        <td>${escapeHtml(desc)}</td>
                        <td></td>
                        <td></td>
                        <td style="text-align: right;" data-cat="p1">0.00</td>
                        <td style="text-align: right;" data-cat="p2">0.00</td>
                        <td style="text-align: right;" data-cat="p3">0.00</td>
                        <td></td>
                        <td style="text-align: right;" data-cat="incDec1">0.00</td>
                        <td style="text-align: right;" data-cat="pct1">0.00%</td>
                        <td style="text-align: right;" data-cat="incDec2">0.00</td>
                        <td style="text-align: right;" data-cat="pct2">0.00%</td>
                        <td></td>
                        <td></td>
                    `;
                } else {
                    pendingCategoryRow = null;
                }
            }

            subCounter++;

            const dataRow = document.createElement('tr');
            const hideDetailRow = summaryOnlySortOrders.has(sortOrderVal);

            if (!hideDetailRow && sortOrderVal >= 1 && sortOrderVal <= 22) {
    dataRow.classList.add('collapsible-detail');
}

            // Values
            const p1M = current_period != null ? (totalsForArea?.[mapKey]?.[current_period]?.mlfsi ?? 0) : 0;
            const p1J = current_period != null ? (totalsForArea?.[mapKey]?.[current_period]?.jewelers ?? 0) : 0;
            const p1T = p1M + p1J;

            const p2M = has_period2 ? (totalsForArea?.[mapKey]?.[period2]?.mlfsi ?? 0) : 0;
            const p2J = has_period2 ? (totalsForArea?.[mapKey]?.[period2]?.jewelers ?? 0) : 0;
            const p2T = p2M + p2J;

            const p3M = has_period3 ? (totalsForArea?.[mapKey]?.[period3]?.mlfsi ?? 0) : 0;
            const p3J = has_period3 ? (totalsForArea?.[mapKey]?.[period3]?.jewelers ?? 0) : 0;
            const p3T = p3M + p3J;

            // Display (absolute for deduction rows)
            const displayP1 = isDeduction ? Math.abs(p1T) : p1T;
            const displayP2 = isDeduction ? Math.abs(p2T) : p2T;
            const displayP3 = isDeduction ? Math.abs(p3T) : p3T;

            // INC. / DEC. & % calculation
            // Variance 1: P1 vs P2
            let incDec1 = isDeduction ? (p2T - p1T) : (p1T - p2T);
            let pct1 = p2T !== 0 ? (incDec1 / (isDeduction ? Math.abs(p2T) : p2T)) * 100 : 0;
            
            // Variance 2: P1 vs P3
            let incDec2 = isDeduction ? (p3T - p1T) : (p1T - p3T);
            let pct2 = p3T !== 0 ? (incDec2 / (isDeduction ? Math.abs(p3T) : p3T)) * 100 : 0;

            const incDecDisplay1 = formatAmount(incDec1);
            const incDecDisplay2 = formatAmount(incDec2);

            // Helper for pct display
            const getPctDisplay = (pctVal, baseVal, diffVal) => {
                if (baseVal === 0) {
                    if (diffVal > 0) return { text: '100.00%', color: '' };
                    if (diffVal < 0) return { text: '-100.00%', color: 'color: #c0392b;' };
                    return { text: '0.00%', color: '' };
                }
                if (Math.abs(pctVal) > 1000) {
                    return { text: 'mat', color: pctVal < 0 ? 'color: #c0392b;' : 'color: #000000;' };
                }
                return { 
                    text: formatPercent(pctVal), 
                    color: isDeduction ? (pctVal > 0 ? '' : 'color: #c0392b;') : (pctVal < 0 ? 'color: #c0392b;' : '') 
                };
            };

            const p1Data = has_period2 ? getPctDisplay(pct1, p2T, incDec1) : { text: '0.00%', color: '' };
            const p2Data = has_period3 ? getPctDisplay(pct2, p3T, incDec2) : { text: '0.00%', color: '' };

            const incDecColor1 = has_period2 ? (isDeduction ? (incDec1 > 0 ? '' : 'color: #c0392b;') : (incDec1 < 0 ? 'color: #c0392b;' : '')) : '';
            const incDecColor2 = has_period3 ? (isDeduction ? (incDec2 > 0 ? '' : 'color: #c0392b;') : (incDec2 < 0 ? 'color: #c0392b;' : '')) : '';

            // Add to pending & section totals (always raw values)
            if (pendingTotals) {
                pendingTotals.p1 += p1T;
                pendingTotals.p2 += p2T;
                pendingTotals.p3 += p3T;
            }

            // Helper to add to section totals
            const addToSection = (target) => {
                target.p1 += p1T;
                target.p2 += p2T;
                target.p3 += p3T;
            };

            if (sortOrderVal >= 1 && sortOrderVal <= 22) {
                addToSection(revenueTotals);
            }
            if (sortOrderVal === 23) {
                addToSection(costTotals);
            }
            if (sortOrderVal === 24 || sortOrderVal === 25) {
                addToSection(sellingAdminTotals);
            }
            if (sortOrderVal === 26) {
                addToSection(operatingTotals);
            }
            if (sortOrderVal === 27) {
                addToSection(interestTotals);
            }
            if (sortOrderVal === 28) {
                addToSection(taxTotals);
            }

         const useColumn2ForComp = sortOrderVal === 17 && subOrderVal >= 3 && subOrderVal <= 6;
            const compCol2 = useColumn2ForComp ? escapeHtml(comp) : '';
            const compCol3 = useColumn2ForComp ? '' : escapeHtml(comp);

            dataRow.innerHTML = `
                <td></td>
                <td>${compCol2}</td>
                <td>${compCol3}</td>
                <td></td>
                <td style="text-align: right;">${formatAmount(displayP1)}</td>
                <td style="text-align: right;">${formatAmount(displayP2)}</td>
                <td style="text-align: right;">${formatAmount(displayP3)}</td>
                <td></td>
                <td style="text-align: right; ${incDecColor1}">${incDecDisplay1}</td>
                <td style="text-align: right; ${p1Data.color}">${p1Data.text}</td>
                <td style="text-align: right; ${incDecColor2}">${incDecDisplay2}</td>
                <td style="text-align: right; ${p2Data.color}">${p2Data.text}</td>
                <td></td>
                <td></td>
            `;
              if (!hideDetailRow) {
                previewTableBody.appendChild(dataRow);
            }

            // Final append for last group
            if (index === dataRows.length - 1) {
                if (pendingCategoryRow) {
                    applyCategoryTotals(pendingCategoryRow, pendingTotals, has_period2, has_period3);
                    previewTableBody.appendChild(pendingCategoryRow);
                    const spacerRow = document.createElement('tr');
                    spacerRow.className = 'preview-category-spacer-row';
                    spacerRow.innerHTML = '<td colspan="14">&nbsp;</td>';
                    if (currentDescSortOrder >= 1 && currentDescSortOrder <= 22) {
                        spacerRow.classList.add('collapsible-detail');
                    }
                    previewTableBody.appendChild(spacerRow);
                }

                // Repeat section inserts for final group (in case last group crosses a threshold)
                if (!revenueInserted && currentDescSortOrder !== null && currentDescSortOrder <= 22) {
                    previewTableBody.appendChild(buildTotalRevenuesRow(revenueTotals, has_period2, has_period3));
                    const spacerRow2 = document.createElement('tr');
                    spacerRow2.className = 'preview-category-spacer-row';
                    spacerRow2.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRow2);
                    previewTableBody.appendChild(buildSectionHeaderRow('Cost of Sales/Service'));
                }
                if (!grossProfitInserted && currentDescSortOrder !== null && currentDescSortOrder <= 23) {
                    const spacerRowGp2 = document.createElement('tr');
                    spacerRowGp2.className = 'preview-category-spacer-row';
                    spacerRowGp2.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowGp2);
                    previewTableBody.appendChild(buildGrossProfitRow(revenueTotals, costTotals, has_period2, has_period3));
                    const spacerRowGpAfter2 = document.createElement('tr');
                    spacerRowGpAfter2.className = 'preview-category-spacer-row';
                    spacerRowGpAfter2.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowGpAfter2);
                    previewTableBody.appendChild(buildLabelRow('SELLING & ADMIN EXPENSE'));
                }
                if (!sellingAdminInserted && currentDescSortOrder !== null && currentDescSortOrder <= 25) {
                    const spacerRowSa2 = document.createElement('tr');
                    spacerRowSa2.className = 'preview-category-spacer-row';
                    spacerRowSa2.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowSa2);
                    previewTableBody.appendChild(buildTotalSellingAdminRow(sellingAdminTotals, has_period2, has_period3));
                    const spacerRowEbitda2 = document.createElement('tr');
                    spacerRowEbitda2.className = 'preview-category-spacer-row';
                    spacerRowEbitda2.innerHTML = '<td colspan="14">&nbsp;</td>';
                    previewTableBody.appendChild(spacerRowEbitda2);
                    previewTableBody.appendChild(buildEarningsBeforeRow(revenueTotals, costTotals, sellingAdminTotals, has_period2, has_period3));
                }
                if (!ebitInserted && currentDescSortOrder !== null && currentDescSortOrder <= 26) {
                const spacerRowEbit2 = document.createElement('tr');
                spacerRowEbit2.className = 'preview-category-spacer-row';
                spacerRowEbit2.innerHTML = '<td colspan="14">&nbsp;</td>';  // ADD THIS LINE
                previewTableBody.appendChild(spacerRowEbit2);
                previewTableBody.appendChild(buildEarningsBeforeInterestTaxesRow(revenueTotals, costTotals, sellingAdminTotals, operatingTotals, has_period2, has_period3));
            }
                            if (!ebtInserted && currentDescSortOrder !== null && currentDescSortOrder <= 27) {
                const spacerRowEbt2 = document.createElement('tr');
                spacerRowEbt2.className = 'preview-category-spacer-row';
                spacerRowEbt2.innerHTML = '<td colspan="14">&nbsp;</td>';  // ADD THIS LINE
                previewTableBody.appendChild(spacerRowEbt2);
                previewTableBody.appendChild(buildEarningsBeforeTaxesRow(revenueTotals, costTotals, sellingAdminTotals, operatingTotals, interestTotals, has_period2, has_period3));
            }
                            if (!netIncomeInserted && currentDescSortOrder !== null && currentDescSortOrder <= 28) {
                const spacerRowNet2 = document.createElement('tr');
                spacerRowNet2.className = 'preview-category-spacer-row';
                spacerRowNet2.innerHTML = '<td colspan="14">&nbsp;</td>';  // ADD THIS LINE
                previewTableBody.appendChild(spacerRowNet2);
                previewTableBody.appendChild(buildTotalNetIncomeLossRow(revenueTotals, costTotals, sellingAdminTotals, operatingTotals, interestTotals, taxTotals, has_period2, has_period3));
            }
                        }
                    });
                }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatAmount(value) {
        const num = Number(value || 0);
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function applyNegativeAmountStyling(tableBodyEl) {
        if (!tableBodyEl) return;

        const cells = tableBodyEl.querySelectorAll('td');
        cells.forEach((cell) => {
            const text = (cell.textContent || '').trim();
            if (!text) return;

            const cleaned = text.replace(/,/g, '').replace('%', '');
            const value = Number(cleaned);

            if (!Number.isFinite(value)) return;
            if (value < 0) {
                cell.style.color = '#c0392b';
            }
        });
    }

    function formatPercent(value) {
        const num = Number(value || 0);
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
    }

    function applyCategoryTotals(rowEl, totals, has_period2 = true, has_period3 = true) {
        if (!rowEl || !totals) return;
        
        const incDec1 = totals.p1 - totals.p2;
        const pct1 = totals.p2 !== 0 ? (incDec1 / totals.p2) * 100 : 0;
        
        const incDec2 = totals.p1 - totals.p3;
        const pct2 = totals.p3 !== 0 ? (incDec2 / totals.p3) * 100 : 0;

        const getPctDisplay = (pctVal, baseVal, diffVal) => {
            if (baseVal === 0) {
                if (diffVal > 0) return { text: '100.00%', color: '' };
                if (diffVal < 0) return { text: '-100.00%', color: 'color: #c0392b;' };
                return { text: '0.00%', color: '' };
            }
            if (Math.abs(pctVal) > 1000) {
                return { text: 'mat', color: pctVal < 0 ? 'color: #c0392b;' : 'color: #000000;' };
            }
            return { 
                text: formatPercent(pctVal), 
                color: pctVal < 0 ? 'color: #c0392b;' : '' 
            };
        };

        const p1Data = has_period2 ? getPctDisplay(pct1, totals.p2, incDec1) : { text: '0.00%', color: '' };
        const p2Data = has_period3 ? getPctDisplay(pct2, totals.p3, incDec2) : { text: '0.00%', color: '' };
        
        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';

        const map = {
            p1: formatAmount(totals.p1),
            p2: formatAmount(totals.p2),
            p3: formatAmount(totals.p3),
            incDec1: formatAmount(incDec1),
            pct1: p1Data.text,
            incDec2: formatAmount(incDec2),
            pct2: p2Data.text
        };

        Object.keys(map).forEach((key) => {
            const cell = rowEl.querySelector(`[data-cat="${key}"]`);
            if (cell) {
                cell.textContent = map[key];
                if (key === 'incDec1') cell.style.color = incDecColor1;
                if (key === 'pct1') cell.style.color = p1Data.color;
                if (key === 'incDec2') cell.style.color = incDecColor2;
                if (key === 'pct2') cell.style.color = p2Data.color;
            }
        });
    }

    function buildTotalRevenuesRow(totals, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#ff7f3a';
        row.style.fontWeight = 'bold';

        const incDec1 = totals.p1 - totals.p2;
        const pct1 = totals.p2 !== 0 ? (incDec1 / totals.p2) * 100 : 0;
        const incDec2 = totals.p1 - totals.p3;
        const pct2 = totals.p3 !== 0 ? (incDec2 / totals.p3) * 100 : 0;

        const getPctDisplay = (pctVal, baseVal, diffVal) => {
            if (baseVal === 0) {
                if (diffVal > 0) return { text: '100.00%', color: '' };
                if (diffVal < 0) return { text: '-100.00%', color: 'color: #c0392b;' };
                return { text: '0.00%', color: '' };
            }
            if (Math.abs(pctVal) > 1000) {
                return { text: 'mat', color: pctVal < 0 ? 'color: #c0392b;' : 'color: #000000;' };
            }
            return { text: formatPercent(pctVal), color: pctVal < 0 ? 'color: #c0392b;' : '' };
        };

        const p1Data = has_period2 ? getPctDisplay(pct1, totals.p2, incDec1) : { text: '0.00%', color: '' };
        const p2Data = has_period3 ? getPctDisplay(pct2, totals.p3, incDec2) : { text: '0.00%', color: '' };
        
        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';

        row.innerHTML = `
            <td>TOTAL REVENUES</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(totals.p1)}</td>
            <td style="text-align: right;">${formatAmount(totals.p2)}</td>
            <td style="text-align: right;">${formatAmount(totals.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${p1Data.color}">${p1Data.text}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${p2Data.color}">${p2Data.text}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    function buildTotalSellingAdminRow(totals, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#fecbb0';
        row.style.fontWeight = 'bold';

        const incDec1 = totals.p1 - totals.p2;
        const pct1 = totals.p2 !== 0 ? (incDec1 / totals.p2) * 100 : 0;
        const incDec2 = totals.p1 - totals.p3;
        const pct2 = totals.p3 !== 0 ? (incDec2 / totals.p3) * 100 : 0;

        const incDecColor1 = has_period2 ? (incDec1 > 0 ? 'color: #c0392b;' : '') : ''; // Expense increase is bad
        let pctColor1 = has_period2 && totals.p2 !== 0 ? (pct1 > 0 ? 'color: #c0392b;' : '') : '';
        let pctText1 = has_period2 && totals.p2 !== 0 ? formatPercent(pct1) : '0.00%';

        if (has_period2 && totals.p2 !== 0 && Math.abs(pct1) > 1000) {
            pctText1 = 'mat';
            pctColor1 = pct1 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }
        
        const incDecColor2 = has_period3 ? (incDec2 > 0 ? 'color: #c0392b;' : '') : '';
        let pctColor2 = has_period3 && totals.p3 !== 0 ? (pct2 > 0 ? 'color: #c0392b;' : '') : '';
        let pctText2 = has_period3 && totals.p3 !== 0 ? formatPercent(pct2) : '0.00%';

        if (has_period3 && totals.p3 !== 0 && Math.abs(pct2) > 1000) {
            pctText2 = 'mat';
            pctColor2 = pct2 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        row.innerHTML = `
            <td>TOTAL SELLING AND ADMIN EXPENSES</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(totals.p1)}</td>
            <td style="text-align: right;">${formatAmount(totals.p2)}</td>
            <td style="text-align: right;">${formatAmount(totals.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${pctColor1}">${pctText1}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${pctColor2}">${pctText2}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    function buildEarningsBeforeRow(revenue, cost, sellingAdmin, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#ffc8a8';
        row.style.fontWeight = 'bold';

        const gross = {
            p1: revenue.p1 - cost.p1,
            p2: revenue.p2 - cost.p2,
            p3: revenue.p3 - cost.p3
        };
        const earnings = {
            p1: gross.p1 - sellingAdmin.p1,
            p2: gross.p2 - sellingAdmin.p2,
            p3: gross.p3 - sellingAdmin.p3
        };
        
        const incDec1 = earnings.p1 - earnings.p2;
        const pct1 = earnings.p2 !== 0 ? (incDec1 / earnings.p2) * 100 : 0;
        const incDec2 = earnings.p1 - earnings.p3;
        const pct2 = earnings.p3 !== 0 ? (incDec2 / earnings.p3) * 100 : 0;

        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor1 = has_period2 && earnings.p2 !== 0 ? (pct1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText1 = has_period2 && earnings.p2 !== 0 ? formatPercent(pct1) : '0.00%';

        if (has_period2 && earnings.p2 !== 0 && Math.abs(pct1) > 1000) {
            pctText1 = 'mat';
            pctColor1 = pct1 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor2 = has_period3 && earnings.p3 !== 0 ? (pct2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText2 = has_period3 && earnings.p3 !== 0 ? formatPercent(pct2) : '0.00%';

        if (has_period3 && earnings.p3 !== 0 && Math.abs(pct2) > 1000) {
            pctText2 = 'mat';
            pctColor2 = pct2 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        row.innerHTML = `
            <td>EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(earnings.p1)}</td>
            <td style="text-align: right;">${formatAmount(earnings.p2)}</td>
            <td style="text-align: right;">${formatAmount(earnings.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${pctColor1}">${pctText1}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${pctColor2}">${pctText2}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    function buildEarningsBeforeInterestTaxesRow(revenue, cost, sellingAdmin, operating, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#ffccb0';
        row.style.fontWeight = 'bold';

        const gross = {
            p1: revenue.p1 - cost.p1,
            p2: revenue.p2 - cost.p2,
            p3: revenue.p3 - cost.p3
        };
        const ebitda = {
            p1: gross.p1 - sellingAdmin.p1,
            p2: gross.p2 - sellingAdmin.p2,
            p3: gross.p3 - sellingAdmin.p3
        };
        const ebit = {
            p1: ebitda.p1 - operating.p1,
            p2: ebitda.p2 - operating.p2,
            p3: ebitda.p3 - operating.p3
        };
        
        const incDec1 = ebit.p1 - ebit.p2;
        const pct1 = ebit.p2 !== 0 ? (incDec1 / ebit.p2) * 100 : 0;
        const incDec2 = ebit.p1 - ebit.p3;
        const pct2 = ebit.p3 !== 0 ? (incDec2 / ebit.p3) * 100 : 0;

        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor1 = has_period2 && ebit.p2 !== 0 ? (pct1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText1 = has_period2 && ebit.p2 !== 0 ? formatPercent(pct1) : '0.00%';

        if (has_period2 && ebit.p2 !== 0 && Math.abs(pct1) > 1000) {
            pctText1 = 'mat';
            pctColor1 = pct1 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor2 = has_period3 && ebit.p3 !== 0 ? (pct2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText2 = has_period3 && ebit.p3 !== 0 ? formatPercent(pct2) : '0.00%';

        if (has_period3 && ebit.p3 !== 0 && Math.abs(pct2) > 1000) {
            pctText2 = 'mat';
            pctColor2 = pct2 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        row.innerHTML = `
            <td>EARNINGS BEFORE INTEREST & TAXES</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(ebit.p1)}</td>
            <td style="text-align: right;">${formatAmount(ebit.p2)}</td>
            <td style="text-align: right;">${formatAmount(ebit.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${pctColor1}">${pctText1}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${pctColor2}">${pctText2}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    function buildEarningsBeforeTaxesRow(revenue, cost, sellingAdmin, operating, interest, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#fdcbb4';
        row.style.fontWeight = 'bold';

        const gross = {
            p1: revenue.p1 - cost.p1,
            p2: revenue.p2 - cost.p2,
            p3: revenue.p3 - cost.p3
        };
        const ebitda = {
            p1: gross.p1 - sellingAdmin.p1,
            p2: gross.p2 - sellingAdmin.p2,
            p3: gross.p3 - sellingAdmin.p3
        };
        const ebit = {
            p1: ebitda.p1 - operating.p1,
            p2: ebitda.p2 - operating.p2,
            p3: ebitda.p3 - operating.p3
        };
        const ebt = {
            p1: ebit.p1 - interest.p1,
            p2: ebit.p2 - interest.p2,
            p3: ebit.p3 - interest.p3
        };
        
        const incDec1 = ebt.p1 - ebt.p2;
        const pct1 = ebt.p2 !== 0 ? (incDec1 / ebt.p2) * 100 : 0;
        const incDec2 = ebt.p1 - ebt.p3;
        const pct2 = ebt.p3 !== 0 ? (incDec2 / ebt.p3) * 100 : 0;

        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor1 = has_period2 && ebt.p2 !== 0 ? (pct1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText1 = has_period2 && ebt.p2 !== 0 ? formatPercent(pct1) : '0.00%';

        if (has_period2 && ebt.p2 !== 0 && Math.abs(pct1) > 1000) {
            pctText1 = 'mat';
            pctColor1 = pct1 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor2 = has_period3 && ebt.p3 !== 0 ? (pct2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText2 = has_period3 && ebt.p3 !== 0 ? formatPercent(pct2) : '0.00%';

        if (has_period3 && ebt.p3 !== 0 && Math.abs(pct2) > 1000) {
            pctText2 = 'mat';
            pctColor2 = pct2 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        row.innerHTML = `
            <td>EARNINGS BEFORE TAXES</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(ebt.p1)}</td>
            <td style="text-align: right;">${formatAmount(ebt.p2)}</td>
            <td style="text-align: right;">${formatAmount(ebt.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${pctColor1}">${pctText1}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${pctColor2}">${pctText2}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    function buildTotalNetIncomeLossRow(revenue, cost, sellingAdmin, operating, interest, tax, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#ff7f3a';
        row.style.fontWeight = 'bold';

        const gross = {
            p1: revenue.p1 - cost.p1,
            p2: revenue.p2 - cost.p2,
            p3: revenue.p3 - cost.p3
        };
        const ebitda = {
            p1: gross.p1 - sellingAdmin.p1,
            p2: gross.p2 - sellingAdmin.p2,
            p3: gross.p3 - sellingAdmin.p3
        };
        const ebit = {
            p1: ebitda.p1 - operating.p1,
            p2: ebitda.p2 - operating.p2,
            p3: ebitda.p3 - operating.p3
        };
        const ebt = {
            p1: ebit.p1 - interest.p1,
            p2: ebit.p2 - interest.p2,
            p3: ebit.p3 - interest.p3
        };
        const netIncome = {
            p1: ebt.p1 - tax.p1,
            p2: ebt.p2 - tax.p2,
            p3: ebt.p3 - tax.p3
        };
        
        const incDec1 = netIncome.p1 - netIncome.p2;
        const pct1 = netIncome.p2 !== 0 ? (incDec1 / netIncome.p2) * 100 : 0;
        const incDec2 = netIncome.p1 - netIncome.p3;
        const pct2 = netIncome.p3 !== 0 ? (incDec2 / netIncome.p3) * 100 : 0;

        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor1 = has_period2 && netIncome.p2 !== 0 ? (pct1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText1 = has_period2 && netIncome.p2 !== 0 ? formatPercent(pct1) : '0.00%';

        if (has_period2 && netIncome.p2 !== 0 && Math.abs(pct1) > 1000) {
            pctText1 = 'mat';
            pctColor1 = pct1 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor2 = has_period3 && netIncome.p3 !== 0 ? (pct2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText2 = has_period3 && netIncome.p3 !== 0 ? formatPercent(pct2) : '0.00%';

        if (has_period3 && netIncome.p3 !== 0 && Math.abs(pct2) > 1000) {
            pctText2 = 'mat';
            pctColor2 = pct2 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        row.innerHTML = `
            <td>TOTAL NET INCOME/LOSS</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(netIncome.p1)}</td>
            <td style="text-align: right;">${formatAmount(netIncome.p2)}</td>
            <td style="text-align: right;">${formatAmount(netIncome.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${pctColor1}">${pctText1}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${pctColor2}">${pctText2}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    function buildSectionHeaderRow(label) {
        const row = document.createElement('tr');
        row.className = 'preview-category-row';
        row.style.backgroundColor = '#ffb6b6';
        row.style.fontWeight = 'bold';
        row.innerHTML = `
            <td colspan="2">${escapeHtml(label)}</td>
            ${'<td></td>'.repeat(12)}
        `;
        return row;
    }

    function buildLabelRow(label) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#ffc2a4';
        row.style.fontWeight = 'bold';
        row.innerHTML = `
            <td>${escapeHtml(label)}</td>
            ${'<td></td>'.repeat(13)}
        `;
        return row;
    }

    function buildGrossProfitRow(revenue, cost, has_period2 = true, has_period3 = true) {
        const row = document.createElement('tr');
        // row.className = 'preview-category-row';
        row.style.backgroundColor = '#ffc2a4';
        row.style.fontWeight = 'bold';

        const gross = {
            p1: revenue.p1 - cost.p1,
            p2: revenue.p2 - cost.p2,
            p3: revenue.p3 - cost.p3
        };
        
        const incDec1 = gross.p1 - gross.p2;
        const pct1 = gross.p2 !== 0 ? (incDec1 / gross.p2) * 100 : 0;
        const incDec2 = gross.p1 - gross.p3;
        const pct2 = gross.p3 !== 0 ? (incDec2 / gross.p3) * 100 : 0;

        const incDecColor1 = has_period2 ? (incDec1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor1 = has_period2 && gross.p2 !== 0 ? (pct1 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText1 = has_period2 && gross.p2 !== 0 ? formatPercent(pct1) : '0.00%';

        if (has_period2 && gross.p2 !== 0 && Math.abs(pct1) > 1000) {
            pctText1 = 'mat';
            pctColor1 = pct1 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        const incDecColor2 = has_period3 ? (incDec2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctColor2 = has_period3 && gross.p3 !== 0 ? (pct2 < 0 ? 'color: #c0392b;' : '') : '';
        let pctText2 = has_period3 && gross.p3 !== 0 ? formatPercent(pct2) : '0.00%';

        if (has_period3 && gross.p3 !== 0 && Math.abs(pct2) > 1000) {
            pctText2 = 'mat';
            pctColor2 = pct2 < 0 ? 'color: #c0392b;' : 'color: #000000;';
        }

        row.innerHTML = `
            <td>GROSS PROFIT</td>
            <td></td>
            <td></td>
            <td></td>
            <td style="text-align: right;">${formatAmount(gross.p1)}</td>
            <td style="text-align: right;">${formatAmount(gross.p2)}</td>
            <td style="text-align: right;">${formatAmount(gross.p3)}</td>
            <td></td>
            <td style="text-align: right; ${incDecColor1}">${formatAmount(incDec1)}</td>
            <td style="text-align: right; ${pctColor1}">${pctText1}</td>
            <td style="text-align: right; ${incDecColor2}">${formatAmount(incDec2)}</td>
            <td style="text-align: right; ${pctColor2}">${pctText2}</td>
            <td></td>
            <td></td>
        `;
        return row;
    }

    // Transaction year select limit (max 3)
    const yearSelect = document.getElementById('transaction_year');
    const enforceYearLimit = () => {
        if (!yearSelect) return;
        const options = Array.from(yearSelect.options);
        const selectedCount = options.filter(opt => opt.selected).length;
        options.forEach((opt) => {
            if (!opt.selected) {
                opt.disabled = selectedCount >= 3;
            } else {
                opt.disabled = false;
            }
        });
    };

    if (yearSelect) {
        yearSelect.addEventListener('change', enforceYearLimit);
        enforceYearLimit();
    }

  // Reset filter button
const resetFilterBtn = document.getElementById('resetFilterBtn');
if (resetFilterBtn) {
    resetFilterBtn.addEventListener('click', function() {
        document.getElementById('previewFilterForm').reset();
        
        enforceYearLimit();
        generatePreviewTable([], '', '');
    });
}

    // Filter form submission
    const previewFilterForm = document.getElementById('previewFilterForm');
    if (previewFilterForm) {
        previewFilterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const { years, start_month, end_month } = getCurrentPreviewFilters();
            generatePreviewTable(years, start_month, end_month);
        });
    }

    // Month range validation
    const startMonthInput = document.querySelector('#previewFilterForm select[name="start_month"]');
    const endMonthInput = document.querySelector('#previewFilterForm select[name="end_month"]');

    function validateMonthRange() {
        if (!startMonthInput || !endMonthInput) return;
        const startVal = startMonthInput.value;
        const endVal = endMonthInput.value;
        if (startVal && endVal) {
            const start = parseInt(startVal, 10);
            const end = parseInt(endVal, 10);
            if (!Number.isNaN(start) && !Number.isNaN(end) && end < start) {
                showStatusModal('End month must be the same or later than start month. Please adjust your selection.', 'error');
                endMonthInput.value = '';
            }
        }
    }

    if (startMonthInput && endMonthInput) {
        startMonthInput.addEventListener('change', validateMonthRange);
        endMonthInput.addEventListener('change', validateMonthRange);
    }

    const exportExcelBtn = document.getElementById('exportExcelBtn');
    const exportSummaryExcelBtn = document.getElementById('exportSummaryExcelBtn');

    async function runExcelExport(mode = 'full') {
        // Get current filters
        const form = document.getElementById('previewFilterForm');
        if (!form) return;
        
        const formData = new FormData(form);
        
        // Get years
        const years = Array.from(document.getElementById('transaction_year').selectedOptions)
            .map(opt => opt.value)
            .filter(y => String(y).trim() !== '');
        
        // Get month range
        const start_month = formData.get('start_month') || '';
        const end_month = formData.get('end_month') || '';
        
        // Show loading
        const activeBtn = mode === 'summary' ? exportSummaryExcelBtn : exportExcelBtn;
        if (!activeBtn) return;

        const originalText = activeBtn.innerHTML;
        activeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting...';
        activeBtn.disabled = true;
        
        try {
            // Create form data for POST
            const postData = new FormData();
            years.forEach(year => postData.append('years[]', year));
            postData.append('start_month', start_month);
            postData.append('end_month', end_month);
            postData.append('export_mode', mode);
            
            // Send request
            const response = await fetch('export_preview_excel_cumu.php', {
                method: 'POST',
                body: postData
            });
            
            if (response.ok) {
                // Get blob
                const blob = await response.blob();
                
                // Check if blob is valid
                if (blob.size === 0) {
                    throw new Error('Empty file received');
                }
                
                // Create download link
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                const suffix = mode === 'summary' ? 'Summary' : 'Full';
                a.download = `Cumulative_Report_${suffix}_${new Date().toISOString().slice(0,10)}.xlsx`;
                document.body.appendChild(a);
                a.click();
                
                // Cleanup
                setTimeout(() => {
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }, 100);
                
                showStatusModal(
                    mode === 'summary'
                        ? 'Cumulative Report exported successfully!'
                        : 'Excel file exported successfully!',
                    'success'
                );
            } else {
                const errorText = await response.text();
                console.error('Export error:', errorText);
                throw new Error('Export failed with status: ' + response.status);
            }
        } catch (error) {
            console.error('Export error:', error);
            showStatusModal('Failed to export Excel file. Please check console for details.', 'error');
        } finally {
            // Reset button
            activeBtn.innerHTML = originalText;
            activeBtn.disabled = false;
        }
    }

    if (exportExcelBtn) {
        exportExcelBtn.addEventListener('click', async () => {
            await runExcelExport('full');
        });
    }

    if (exportSummaryExcelBtn) {
        exportSummaryExcelBtn.addEventListener('click', async () => {
            await runExcelExport('summary');
        });
    }

    // for collapse uncollapse excel

    const previewCollapseBtn = document.getElementById('previewCollapseBtn');
    let previewCollapsed = false;

previewCollapseBtn.addEventListener('click', () => {
    previewCollapsed = !previewCollapsed;

    const detailRows = document.querySelectorAll('.collapsible-detail');

    detailRows.forEach(row => {
        row.style.display = previewCollapsed ? 'none' : '';
    });

    previewCollapseBtn.innerHTML = previewCollapsed
        ? '<i class="fa-solid fa-bars"></i> Uncollapse View'
        : '<i class="fa-solid fa-bars"></i> Collapse View';
});

</script>    

</body>
</html>

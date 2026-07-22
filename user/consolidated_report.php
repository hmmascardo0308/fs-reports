<?php
session_start();
require_once __DIR__ . '/../config/config.php'; 

// Flash message handling
$status_message = null;
$status_type = 'success';

if (isset($_SESSION['flash_message'])) {
    $status_message = $_SESSION['flash_message'];
    $status_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Session Management (Simplified for clarity)
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
    $max_sort_res = mysqli_query($conn, "SELECT MAX(sort_order + 0) AS max_sort FROM fs_reports.gl_codes_new");
    $max_sort_row = $max_sort_res ? mysqli_fetch_assoc($max_sort_res) : null;
    $next_sort = ($max_sort_row && $max_sort_row['max_sort'] !== null) ? ((int)$max_sort_row['max_sort'] + 1) : 1;

    $get_sort_order = function($desc) use ($conn) {
        $value = null;
        $stmt = mysqli_prepare($conn, "SELECT sort_order FROM fs_reports.gl_codes_new WHERE description = ? LIMIT 1");
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
        $stmt = mysqli_prepare($conn, "SELECT MAX(sub_order + 0) AS max_sub FROM fs_reports.gl_codes_new WHERE description = ?");
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
            "SELECT sub_order FROM fs_reports.gl_codes_new WHERE description = ? AND gl_description_comparative = ? LIMIT 1"
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
        $stmt = mysqli_prepare($conn, "SELECT sort_order, gl_id FROM fs_reports.gl_codes_new WHERE description = ? LIMIT 1");
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
                $check = mysqli_prepare($conn, "SELECT id FROM fs_reports.gl_codes_new WHERE gl_id LIKE ? AND description != ? LIMIT 1");
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
                $row_stmt = mysqli_prepare($conn, "SELECT sub_order, gl_id FROM fs_reports.gl_codes_new WHERE description = ? AND gl_description_comparative = ? LIMIT 1");
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
            "SELECT gl_mapping FROM fs_reports.gl_codes_new WHERE gl_description_comparative = ? AND gl_mapping IS NOT NULL AND gl_mapping != '' ORDER BY id DESC LIMIT 1"
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
             FROM fs_reports.gl_codes_new 
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
        $insert = mysqli_prepare($conn, "INSERT INTO fs_reports.gl_codes_new
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

    header("Location: consolidated_report.php");
    exit;
}

// Initialize variables used in display logic to avoid undefined variable notices
$desc_list = [];
$grouped = [];
$sub_orders = [];
$sub_order_map = [];
$gl_id_map = [];
$distinct_desc_res = null;
$distinct_comp_res = null;
$error_message = null;

try {
    $has_sort_order = false;
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM fs_reports.gl_codes_new LIKE 'sort_order'");
if ($col_check && mysqli_num_rows($col_check) > 0) {
    $has_sort_order = true;
}
$order_by = $has_sort_order
    ? "ORDER BY CAST(sort_order AS UNSIGNED), CAST(sub_order AS UNSIGNED), id"
    : "ORDER BY sort_order, id";

    $query = "SELECT id, gl_id, sort_order, sub_order, description, gl_description_comparative, gl_code, gl_description, new_gl_code, new_gl_description, gl_mapping 
          FROM fs_reports.gl_codes_new
          $order_by";
    $result = mysqli_query($conn, $query);

    // Fetch all rows into array for safer processing
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    // Group rows for proper display and sub-order assignment
$main_order = [];

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
    $distinct_desc_query = "SELECT DISTINCT description FROM fs_reports.gl_codes_new WHERE description IS NOT NULL AND description != ''";
    $distinct_desc_res = mysqli_query($conn, $distinct_desc_query);

    // Fetch distinct comparative descriptions for the modal
    $distinct_comp_query = "SELECT DISTINCT gl_description_comparative FROM fs_reports.gl_codes_new WHERE gl_description_comparative IS NOT NULL AND gl_description_comparative != ''";
    $distinct_comp_res = mysqli_query($conn, $distinct_comp_query);

} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
}

// Fetch mapping of Comparative Description -> GL Mapping
$mapping_lookup = [];
$lookup_query = "SELECT gl_description_comparative, gl_mapping 
                 FROM fs_reports.gl_codes_new 
                 WHERE gl_description_comparative IS NOT NULL";
$lookup_res = mysqli_query($conn, $lookup_query);

while ($row = mysqli_fetch_assoc($lookup_res)) {
    $mapping_lookup[$row['gl_description_comparative']] = $row['gl_mapping'];
}
$mapping_json = json_encode($mapping_lookup);

// Build Description -> Comparative list for filtered dropdowns
$comps_by_desc = [];
$comp_by_desc_query = "SELECT description, gl_description_comparative
                       FROM fs_reports.gl_codes_new
                       WHERE description IS NOT NULL
                         AND description != ''
                         AND gl_description_comparative IS NOT NULL
                         AND gl_description_comparative != ''";
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

// Fetch distinct Zones
$zones = [];
$z_query = "SELECT DISTINCT zone FROM fs_reports.comparative_report WHERE zone IS NOT NULL AND zone != '' ORDER BY zone";
$z_res = mysqli_query($conn, $z_query);
while ($row = mysqli_fetch_assoc($z_res)) {
    $zones[] = $row['zone'];
}

// Fetch distinct Branch Types
$branch_types = [];
$bt_query = "SELECT DISTINCT transaction_type FROM fs_reports.comparative_report WHERE transaction_type IS NOT NULL AND transaction_type != '' ORDER BY transaction_type";
$bt_res = mysqli_query($conn, $bt_query);
while ($row = mysqli_fetch_assoc($bt_res)) {
    $branch_types[] = $row['transaction_type'];
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidated Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/consol.css?v=<?= time(); ?>">
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

        <h2 style="text-align: center; margin-top: -2%;">Consolidated Report</h3>


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

            <a href="consolidated.php" style="text-decoration: none;" class="btn-preview">Generate Report</a>


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
                    <a href="consolidated_report.php" class="btn-cancel">Cancel</a>
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
            <h3>Consolidated Report (Per Zone)</h3>
             <button type="button" class="btn-collapse" id="previewCollapseBtn">
                    <i class="fa-solid fa-bars"></i> Collapse View
                </button>
            <button class="preview-close-btn">&times;</button>
        </div>
        
        <!-- Filter Form -->
        <div class="preview-filter-section">
    <form id="previewFilterForm" class="preview-filter-form">
        <div class="filter-row">
           <!-- Zone -->
    <div class="filter-group">
        <label>Zone:</label>
        <select name="zone" id="zoneFilter" class="filter-select">
            <option value="">All Zones</option>
            <?php foreach ($zones as $z): ?>
                <option value="<?= htmlspecialchars($z) ?>"><?= htmlspecialchars($z) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Branch Type -->
    <div class="filter-group">
        <label>Branch Type:</label>
        <select name="branch_type" id="branchTypeFilter" class="filter-select">
            <option value="">All Branch Types</option>
            <?php foreach ($branch_types as $bt): ?>
                <option value="<?= htmlspecialchars($bt) ?>"><?= htmlspecialchars($bt) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Month -->
    <div class="filter-group">
        <label>Month:</label>
            <input type="month" 
                   name="month" 
                   class="filter-select" 
                   style="width: 90%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
    </div>


            <!-- Transaction Year + Filter Actions -->
            <div class="filter-group transaction-year-group">
                <label>Transaction Year</label>
                <div class="transaction-year-row">
                    <div class="custom-select-wrapper" style="flex:1;">
                        <select name="transaction_year" id="transaction_year" class="filter-select" style="width: 100%;">
                            <option value="">Select Year</option>
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

                    <div class="filter-actions" style="margin-top:0; flex-shrink:0; margin-left: 10px;">
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
            <!-- Main Header -->
            <tr>
                <th colspan="4" class="preview-region-header">Zone: All Zones</th>
                <th colspan="3" class="preview-area-header">CONSOLIDATED PROFIT & LOSS STATEMENT</th>
            </tr>

            <!-- Period Header -->
            <tr>
                <th colspan="4"></th>
                <th colspan="1" class="preview-current-year-header">(Transaction Period)</th>
            </tr>

            <!-- Region/Column Labels - will be dynamically filled -->
            <tr>
                <th colspan="5"></th>
                <!-- This will be populated by JavaScript -->
            </tr>
        </thead>

        <tbody class="preview-table-spacer-body">
            <!-- Placeholder Revenues row (looks nice even with no data) -->
            <tr style="background-color: #ff7f3a; font-weight: bold; color: #000000;">
                <td colspan="2">REVENUES</td>
                <td colspan="5"></td>
            </tr>
        </tbody>

        <tbody class="preview-table-body">
            <!-- This will be populated dynamically by JavaScript -->
        </tbody>
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
            const glCode = (row.querySelector('td:nth-child(7)')?.textContent || '').trim();
            const glDesc = (row.querySelector('td:nth-child(8)')?.textContent || '').trim();
            const newGlCode = (row.querySelector('td:nth-child(9)')?.textContent || '').trim();
            const hay = `${desc} ${comp} ${glCode} ${glDesc} ${newGlCode}`.toLowerCase();
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
    window.location.href = 'consolidated_report.php';
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

        fetch('save_sub_order.php', {
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
            fetch('delete_gl_row.php', {
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
    if (!form) {
        return { zone: '', branch_type: '', month: '', year: '' };
    }

    const formData = new FormData(form);

    return {
        zone: formData.get('zone') || '',
        branch_type: formData.get('branch_type') || '',
        month: formData.get('month') || '',
        year: formData.get('transaction_year') || ''
    };
}

// Open preview modal
if (previewTableBtn) {
    previewTableBtn.addEventListener('click', () => {
        const filters = getCurrentPreviewFilters();
        generatePreviewTable(filters);
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

// Section tracking variables
let revenueInserted = false;
let grossProfitInserted = false;
let sellingAdminInserted = false;
let ebitInserted = false;
let ebtInserted = false;
let netIncomeInserted = false;

function resetSectionFlags() {
    revenueInserted = false;
    grossProfitInserted = false;
    sellingAdminInserted = false;
    ebitInserted = false;
    ebtInserted = false;
    netIncomeInserted = false;
}

// Main generate preview function
async function generatePreviewTable(filters) {
    if (!previewTablesContainer || !previewTableTemplate) return;
    
    resetSectionFlags();
    previewCollapsed = true;

    // Show loading indicator
    previewTablesContainer.innerHTML = `
        <div class="loading-container">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <p>Loading preview...</p>
        </div>
    `;

    try {
        const data = await fetchPreviewTotals(filters);
        
        previewTablesContainer.innerHTML = '';

        if (!data.totals || Object.keys(data.totals).length === 0) {
            previewTablesContainer.innerHTML = `
                <div class="no-data-message">
                    <i class="fa-solid fa-database" style="font-size: 48px; color: #999; margin-bottom: 15px;"></i>
                    <p style="font-size: 18px; color: #666; margin: 0;">No data found for this filter.</p>
                    <p style="font-size: 14px; color: #999; margin-top: 10px;">Try adjusting your filters.</p>
                </div>
            `;
        } else {
            const fragment = previewTableTemplate.content.cloneNode(true);
            const table = fragment.querySelector('table');
            const tbodyEl = fragment.querySelector('.preview-table-body');

            if (table && tbodyEl) {
                updatePreviewHeaders(table, filters.zone, data);

                // Dynamically adjust colspan for REVENUES row
                const revenuesRow = fragment.querySelector('.preview-table-spacer-body tr');
                if (revenuesRow) {
                    const secondCell = revenuesRow.querySelector('td:nth-child(2)');
                    if (secondCell) {
                        const regions = data.regions || [];
                        if (regions.length > 0) {
                            const totalCols = 4 + regions.length + 1;
                            secondCell.colSpan = totalCols - 2;
                        } else {
                            secondCell.colSpan = 4; // Default when no regions
                        }
                    }
                }

                buildTableBody(tbodyEl, data.totals, data);
                applyNegativeAmountStyling(tbodyEl);

                const tableContainer = document.createElement('div');
                tableContainer.className = 'table-container';
                tableContainer.appendChild(table);
                previewTablesContainer.appendChild(tableContainer);

                // Apply collapsed view (default) for sort_order 1-20 details
                applyPreviewCollapseState();
            }
        }
    } catch (err) {
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

async function fetchPreviewTotals(filters) {
    const res = await fetch('fetch_preview_totals_consolidated.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(filters)
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Failed to load preview data');
    }
    return data;
}

function updatePreviewHeaders(table, zoneName, data) {
    const regionHeader = table.querySelector('.preview-region-header');
    const currentHeader = table.querySelector('.preview-current-year-header');
    const headerRow2 = table.querySelector('thead tr:nth-child(2)');
    const headerRow3 = table.querySelector('thead tr:nth-child(3)');
    const regions = data.regions || [];
    const hasRegions = regions.length > 0;
    const branchType = data.branch_type || '';

    if (regionHeader) regionHeader.textContent = zoneName ? `Zone: ${zoneName}` : 'All Zones';
    if (currentHeader) currentHeader.textContent = data.current_label || 'Transaction Period';

    if (hasRegions) {
        const numRegions = regions.length;
        const totalCols = 4 + numRegions + 1; // 4 fixed + region columns + 1 total column
        
        // Update area header colspan
        const areaHeader = table.querySelector('.preview-area-header');
        if (areaHeader) {
            areaHeader.colSpan = totalCols - 4;
        }

        // Rebuild second header row (period row)
        if (headerRow2) {
            headerRow2.innerHTML = '';
            const thSpacer = document.createElement('th');
            thSpacer.colSpan = 4;
            headerRow2.appendChild(thSpacer);
            
            for (let i = 0; i < numRegions; i++) {
                const th = document.createElement('th');
                th.textContent = data.current_label || 'Transaction Period';
                th.style.backgroundColor = '#ff0000';
                headerRow2.appendChild(th);
            }
            
            const thGrandTotal = document.createElement('th');
            thGrandTotal.textContent = data.current_label || 'Transaction Period';
            thGrandTotal.style.backgroundColor = '#ff0000';
            headerRow2.appendChild(thGrandTotal);
        }

        // Rebuild third header row (region names row)
        if (headerRow3) {
            headerRow3.innerHTML = '';
            
            const thSpacer = document.createElement('th');
            thSpacer.colSpan = 4;
            headerRow3.appendChild(thSpacer);
            
            regions.forEach(region => {
                const th = document.createElement('th');
                let displayName = region;
                if (branchType === 'Branch') {
                    displayName = `${region} (MLFSI)`;
                } else if (branchType === 'Showroom') {
                    displayName = `${region} (JEWELERS)`;
                } else {
                    displayName = `${region} (MLFSI & JEWELERS)`;
                }
                th.textContent = displayName;
                headerRow3.appendChild(th);
            });
            
            const thGrandTotal = document.createElement('th');
            thGrandTotal.textContent = 'GRAND TOTAL';
            thGrandTotal.style.backgroundColor = '#ff0000';
            headerRow3.appendChild(thGrandTotal);
        }
    }
}

function buildTableBody(tbodyEl, totals, data) {
    const glTableRows = Array.from(document.querySelectorAll('#glTable tbody tr[data-id]'));
    const processedGroups = new Set();
    
    const regions = data.regions || [];
    const hasRegions = regions.length > 0;
    const currentPeriod = data.current_period;
    const previousPeriod = data.previous_period;
    const hasPrevious = data.has_previous;
    const branchType = data.branch_type || '';

    let mainCounter = 0;
    let currentDesc = null;
    let currentSortOrder = null;
    let pendingCategoryRow = null;
    let pendingTotals = null;

    // Initialize section totals
    let revenueTotals = initTotalsObject(hasRegions, regions);
    let costTotals = initTotalsObject(hasRegions, regions);
    let sellingAdminTotals = initTotalsObject(hasRegions, regions);
    let operatingTotals = initTotalsObject(hasRegions, regions);
    let interestTotals = initTotalsObject(hasRegions, regions);
    let taxTotals = initTotalsObject(hasRegions, regions);

    const summaryOnlySortOrders = new Set([6, 8, 11]);
    const detailOnlySortOrders = new Set([24, 25, 26]);

    glTableRows.forEach((row, index) => {
        const desc = row.dataset.description || '';
        const comp = row.dataset.glcomp || '';
        const mapKey = row.dataset.glmap || comp;
        const group = desc + '||' + mapKey;
        const sortOrder = Number(row.dataset.sortorder || 0);
        const isDeduction = (mapKey === "less_sales_return_discount");

        if (processedGroups.has(group)) return;
        processedGroups.add(group);

        // Get values for this map key
        const currentValues = getValuesForMapKey(totals, mapKey, currentPeriod, hasRegions, regions, branchType);
        const previousValues = getValuesForMapKey(totals, mapKey, previousPeriod, hasRegions, regions, branchType);

        // Handle description change
        if (desc !== currentDesc) {
            if (pendingCategoryRow) {
                applyCategoryTotals(pendingCategoryRow, pendingTotals, hasRegions, regions);
                tbodyEl.appendChild(pendingCategoryRow);
                const colspan = hasRegions ? (4 + regions.length + 1) : 5;
                const spacer = createSpacerRow(colspan, currentSortOrder);
                tbodyEl.appendChild(spacer);
            }

            // Insert section totals when crossing thresholds
            insertSectionTotals(tbodyEl, revenueTotals, costTotals, sellingAdminTotals, operatingTotals, 
                interestTotals, taxTotals, hasRegions, regions, data, currentSortOrder, sortOrder);

            currentDesc = desc;
            currentSortOrder = sortOrder || currentSortOrder;
            pendingTotals = initTotalsObject(hasRegions, regions);

            if (desc !== '' && !detailOnlySortOrders.has(sortOrder)) {
                mainCounter++;
                pendingCategoryRow = createCategoryRow(mainCounter, desc, hasRegions, regions, branchType);
            } else {
                pendingCategoryRow = null;
            }
        }

        // Update pending totals
        if (pendingTotals) {
            addToTotals(pendingTotals, currentValues, hasRegions, regions);
        }

        // Update section totals based on sort order
        if (sortOrder >= 1 && sortOrder <= 20) {
            addToTotals(revenueTotals, currentValues, hasRegions, regions);
        }
        if (sortOrder === 21) {
            addToTotals(costTotals, currentValues, hasRegions, regions);
        }
        if (sortOrder === 22 || sortOrder === 23) {
            addToTotals(sellingAdminTotals, currentValues, hasRegions, regions);
        }
        if (sortOrder === 24) {
            addToTotals(operatingTotals, currentValues, hasRegions, regions);
        }
        if (sortOrder === 25) {
            addToTotals(interestTotals, currentValues, hasRegions, regions);
        }
        if (sortOrder === 26) {
            addToTotals(taxTotals, currentValues, hasRegions, regions);
        }

        // Create data row
        const hideDetailRow = summaryOnlySortOrders.has(sortOrder);
        const dataRow = createDataRow(comp, currentValues, previousValues, hasRegions, regions, 
            isDeduction, hasPrevious, data, branchType);
        
        if (!hideDetailRow) {
            if (sortOrder >= 1 && sortOrder <= 20) {
                dataRow.classList.add('collapsible-detail');
            }
            tbodyEl.appendChild(dataRow);
        }

        // Final processing for last row
        if (index === glTableRows.length - 1) {
            if (pendingCategoryRow) {
                applyCategoryTotals(pendingCategoryRow, pendingTotals, hasRegions, regions);
                tbodyEl.appendChild(pendingCategoryRow);
                const colspan = hasRegions ? (4 + regions.length + 1) : 5;
                const spacer = createSpacerRow(colspan, currentSortOrder);
                tbodyEl.appendChild(spacer);
            }
            
            insertFinalSectionTotals(tbodyEl, revenueTotals, costTotals, sellingAdminTotals, operatingTotals,
                interestTotals, taxTotals, hasRegions, regions, data, currentSortOrder);
        }
    });
}


// Helper functions for totals objects
function initTotalsObject(hasRegions, regions) {
    if (hasRegions) {
        const totals = { current: {}, previous: {} };
        regions.forEach(region => {
            totals.current[region] = 0;
            totals.previous[region] = 0;
        });
        totals.currentTotal = 0;
        totals.previousTotal = 0;
        return totals;
    }
    return { current: 0, previous: 0 };
}


function getValuesForMapKey(totals, mapKey, period, hasRegions, regions, branchType) {
    if (!period || !totals[mapKey] || !totals[mapKey][period]) {
        if (hasRegions) {
            const result = {};
            regions.forEach(region => {
                result[region] = 0;
            });
            result.total = 0;
            return result;
        }
        return 0;
    }
    
    if (hasRegions) {
        const result = {};
        let total = 0;
        regions.forEach(region => {
            const value = totals[mapKey][period][region] || 0;
            result[region] = value;
            total += value;
        });
        result.total = total;
        return result;
    }
    
    // No regions - sum across all regions
    let total = 0;
    for (const region in totals[mapKey][period]) {
        total += totals[mapKey][period][region] || 0;
    }
    return total;
}

function addToTotals(target, source, hasRegions, regions) {
    if (hasRegions) {
        regions.forEach(region => {
            target.current[region] += source[region];
            target.previous[region] += source[region];
        });
        target.currentTotal += source.total;
        target.previousTotal += source.total;
    } else {
        target.current += source;
        target.previous += source;
    }
}


function createSpacerRow(colspan, sortOrder) {
    const spacer = document.createElement('tr');
    spacer.className = 'preview-category-spacer-row';
    spacer.innerHTML = `<td colspan="${colspan}">&nbsp;<\/td>`;
    if (sortOrder >= 1 && sortOrder <= 20) {
        spacer.classList.add('collapsible-detail');
    }
    return spacer;
}

function createCategoryRow(counter, desc, hasRegions, regions, branchType) {
    const row = document.createElement('tr');
    row.className = 'preview-category-row';
    
    if (hasRegions) {
        // For regions: show total per region (no MLFSI/JEWELERS split)
        const regionCols = regions.map(() => '<td style="text-align: right;">0.00<\/td>').join('');
        row.innerHTML = `
            <td style="text-align: center;">${counter}<\/td>
            <td>${escapeHtml(desc)}<\/td>
            <td><\/td>
            <td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">0.00<\/td>
        `;
    } else {
        row.innerHTML = `
            <td style="text-align: center;">${counter}<\/td>
            <td>${escapeHtml(desc)}<\/td>
            <td><\/td>
            <td><\/td>
            <td style="text-align: right;">0.00<\/td>
        `;
    }
    return row;
}

function applyCategoryTotals(rowEl, totals, hasRegions, regions) {
    if (!rowEl || !totals) return;
    
    if (hasRegions) {
        const cells = rowEl.cells;
        let colIndex = 4;
        regions.forEach(region => {
            if (cells[colIndex]) cells[colIndex].textContent = formatAmount(totals.current[region]);
            colIndex += 1;
        });
        if (cells[colIndex]) cells[colIndex].textContent = formatAmount(totals.currentTotal);
    } else {
        const cells = rowEl.cells;
        if (cells[4]) cells[4].textContent = formatAmount(totals.current);
    }
}

function createDataRow(comp, currentValues, previousValues, hasRegions, regions, isDeduction, hasPrevious, data, branchType) {
    const row = document.createElement('tr');
    
    if (hasRegions) {
        let html = `<td><\/td><td><\/td><td>${escapeHtml(comp)}<\/td><td><\/td>`;
        
        // Region columns - single column per region (total amount)
        regions.forEach(region => {
            let value = currentValues[region] || 0;
            if (isDeduction) {
                value = Math.abs(value);
            }
            html += `<td style="text-align: right;">${formatAmount(value)}<\/td>`;
        });
        
        // Grand total
        let total = currentValues.total || 0;
        if (isDeduction) {
            total = Math.abs(total);
        }
        html += `<td style="text-align: right; background-color: #ff7f3a; font-weight: bold;">${formatAmount(total)}<\/td>`;
        
        row.innerHTML = html;
    } else {
        let current = currentValues || 0;
        let previous = previousValues || 0;
        
        if (isDeduction) {
            current = Math.abs(current);
            previous = Math.abs(previous);
        }
        
        let calcIncDec = current - previous;
        let calcPct = previous !== 0 ? (calcIncDec / previous) * 100 : 0;
        
        if (isDeduction) {
            calcIncDec = previous - current;
            calcPct = previous !== 0 ? (calcIncDec / Math.abs(previous)) * 100 : 0;
        }
        
        const incDecDisplay = formatAmount(calcIncDec);
        let pctDisplay;
        let pctColor = '';
        
        if (hasPrevious) {
            if (previous === 0) {
                if (calcIncDec > 0) {
                    pctDisplay = '100.00%';
                } else if (calcIncDec < 0) {
                    pctDisplay = '-100.00%';
                    pctColor = 'color: #c0392b;';
                } else {
                    pctDisplay = '0.00%';
                }
            } else {
                if (Math.abs(calcPct) > 1000) {
                    pctDisplay = 'mat';
                    pctColor = calcPct < 0 ? 'color: #c0392b;' : 'color: #000000;';
                } else {
                    pctDisplay = formatPercent(calcPct);
                    pctColor = isDeduction ? (calcPct > 0) : (calcPct < 0) ? 'color: #c0392b;' : '';
                }
            }
        } else {
            pctDisplay = '0.00%';
        }
        
        const incDecColor = hasPrevious ? (isDeduction ? (calcIncDec > 0) : (calcIncDec < 0)) ? 'color: #c0392b;' : '' : '';
        
        row.innerHTML = `
            <td><\/td><td><\/td><td>${escapeHtml(comp)}<\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(current)}<\/td>
        `;
    }
    
    return row;
}


function insertSectionTotals(container, revenue, cost, sellingAdmin, operating, interest, tax, hasRegions, regions, data, currentSortOrder, newSortOrder) {
    const colspan = hasRegions ? (4 + (regions.length * 2) + 3) : 7;
    
    if (!revenueInserted && currentSortOrder !== null && currentSortOrder <= 20 && newSortOrder > 20) {
        container.appendChild(buildTotalRevenuesRow(revenue, hasRegions, regions, data));
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildSectionHeaderRow('Cost of Sales/Service', hasRegions, regions));
        revenueInserted = true;
    }
    if (!grossProfitInserted && currentSortOrder !== null && currentSortOrder <= 21 && newSortOrder > 21) {
        container.appendChild(buildGrossProfitRow(revenue, cost, hasRegions, regions, data));
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildLabelRow('SELLING & ADMIN EXPENSE', hasRegions, regions));
        grossProfitInserted = true;
    }
    if (!sellingAdminInserted && currentSortOrder !== null && currentSortOrder <= 23 && newSortOrder > 23) {
        // container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildTotalSellingAdminRow(sellingAdmin, hasRegions, regions, data));
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildEarningsBeforeRow(revenue, cost, sellingAdmin, hasRegions, regions, data));
        sellingAdminInserted = true;
    }
    if (!ebitInserted && currentSortOrder !== null && currentSortOrder <= 24 && newSortOrder > 24) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildEarningsBeforeInterestTaxesRow(revenue, cost, sellingAdmin, operating, hasRegions, regions, data));
        ebitInserted = true;
    }
    if (!ebtInserted && currentSortOrder !== null && currentSortOrder <= 25 && newSortOrder > 25) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildEarningsBeforeTaxesRow(revenue, cost, sellingAdmin, operating, interest, hasRegions, regions, data));
        ebtInserted = true;
    }
    if (!netIncomeInserted && currentSortOrder !== null && currentSortOrder <= 26 && newSortOrder > 26) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildTotalNetIncomeLossRow(revenue, cost, sellingAdmin, operating, interest, tax, hasRegions, regions, data));
        netIncomeInserted = true;
    }
}

function insertFinalSectionTotals(container, revenue, cost, sellingAdmin, operating, interest, tax, hasRegions, regions, data, currentSortOrder) {
    const colspan = hasRegions ? (4 + (regions.length * 2) + 3) : 7;
    
    if (!revenueInserted && currentSortOrder !== null && currentSortOrder <= 20) {
        container.appendChild(buildTotalRevenuesRow(revenue, hasRegions, regions, data));
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildSectionHeaderRow('Cost of Sales/Service', hasRegions, regions));
    }
    if (!grossProfitInserted && currentSortOrder !== null && currentSortOrder <= 21) {
        container.appendChild(buildGrossProfitRow(revenue, cost, hasRegions, regions, data));
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildLabelRow('SELLING & ADMIN EXPENSE', hasRegions, regions));
    }
    if (!sellingAdminInserted && currentSortOrder !== null && currentSortOrder <= 23) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildTotalSellingAdminRow(sellingAdmin, hasRegions, regions, data));
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildEarningsBeforeRow(revenue, cost, sellingAdmin, hasRegions, regions, data));
    }
    if (!ebitInserted && currentSortOrder !== null && currentSortOrder <= 24) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildEarningsBeforeInterestTaxesRow(revenue, cost, sellingAdmin, operating, hasRegions, regions, data));
    }
    if (!ebtInserted && currentSortOrder !== null && currentSortOrder <= 25) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildEarningsBeforeTaxesRow(revenue, cost, sellingAdmin, operating, interest, hasRegions, regions, data));
    }
    if (!netIncomeInserted && currentSortOrder !== null && currentSortOrder <= 26) {
        container.appendChild(createSpacerRow(colspan, null));
        container.appendChild(buildTotalNetIncomeLossRow(revenue, cost, sellingAdmin, operating, interest, tax, hasRegions, regions, data));
    }
}

// Summary row builders
function buildTotalRevenuesRow(totals, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ff7f3a';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            return `<td style="text-align: right;">${formatAmount(totals.current[region] || 0)}<\/td>`;
        }).join('');
        
        row.innerHTML = `
            <td colspan="2">TOTAL REVENUES<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totals.currentTotal)}<\/td>
        `;
    } else {
        row.innerHTML = `
            <td colspan="2">TOTAL REVENUES<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(totals.current)}<\/td>
        `;
    }
    return row;
}


function buildTotalSellingAdminRow(totals, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#fecbb0';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            return `<td style="text-align: right;">${formatAmount(totals.current[region] || 0)}<\/td>`;
        }).join('');
        
        row.innerHTML = `
            <td colspan="2">TOTAL SELLING AND ADMIN EXPENSES<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totals.currentTotal)}<\/td>
        `;
    } else {
        row.innerHTML = `
            <td colspan="2">TOTAL SELLING AND ADMIN EXPENSES<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(totals.current)}<\/td>
        `;
    }
    return row;
}

function buildGrossProfitRow(revenue, cost, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ffc2a4';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            const gross = (revenue.current[region] || 0) - (cost.current[region] || 0);
            return `<td style="text-align: right;">${formatAmount(gross)}<\/td>`;
        }).join('');
        
        const totalGross = revenue.currentTotal - cost.currentTotal;
        
        row.innerHTML = `
            <td colspan="2">GROSS PROFIT<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totalGross)}<\/td>
        `;
    } else {
        const gross = revenue.current - cost.current;
        row.innerHTML = `
            <td colspan="2">GROSS PROFIT<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(gross)}<\/td>
        `;
    }
    return row;
}

function buildEarningsBeforeRow(revenue, cost, sellingAdmin, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ffc8a8';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            const gross = (revenue.current[region] || 0) - (cost.current[region] || 0);
            const ebitda = gross - (sellingAdmin.current[region] || 0);
            return `<td style="text-align: right;">${formatAmount(ebitda)}<\/td>`;
        }).join('');
        
        const totalGross = revenue.currentTotal - cost.currentTotal;
        const totalEbitda = totalGross - sellingAdmin.currentTotal;
        
        row.innerHTML = `
            <td colspan="2">EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totalEbitda)}<\/td>
        `;
    } else {
        const gross = revenue.current - cost.current;
        const ebitda = gross - sellingAdmin.current;
        row.innerHTML = `
            <td colspan="2">EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(ebitda)}<\/td>
        `;
    }
    return row;
}

function buildEarningsBeforeInterestTaxesRow(revenue, cost, sellingAdmin, operating, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ffccb0';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            const gross = (revenue.current[region] || 0) - (cost.current[region] || 0);
            const ebitda = gross - (sellingAdmin.current[region] || 0);
            const ebit = ebitda - (operating.current[region] || 0);
            return `<td style="text-align: right;">${formatAmount(ebit)}<\/td>`;
        }).join('');
        
        const totalGross = revenue.currentTotal - cost.currentTotal;
        const totalEbitda = totalGross - sellingAdmin.currentTotal;
        const totalEbit = totalEbitda - operating.currentTotal;
        
        row.innerHTML = `
            <td colspan="2">EARNINGS BEFORE INTEREST & TAXES<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totalEbit)}<\/td>
        `;
    } else {
        const gross = revenue.current - cost.current;
        const ebitda = gross - sellingAdmin.current;
        const ebit = ebitda - operating.current;
        row.innerHTML = `
            <td colspan="2">EARNINGS BEFORE INTEREST & TAXES<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(ebit)}<\/td>
        `;
    }
    return row;
}

function buildEarningsBeforeTaxesRow(revenue, cost, sellingAdmin, operating, interest, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#fdcbb4';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            const gross = (revenue.current[region] || 0) - (cost.current[region] || 0);
            const ebitda = gross - (sellingAdmin.current[region] || 0);
            const ebit = ebitda - (operating.current[region] || 0);
            const ebt = ebit - (interest.current[region] || 0);
            return `<td style="text-align: right;">${formatAmount(ebt)}<\/td>`;
        }).join('');
        
        const totalGross = revenue.currentTotal - cost.currentTotal;
        const totalEbitda = totalGross - sellingAdmin.currentTotal;
        const totalEbit = totalEbitda - operating.currentTotal;
        const totalEbt = totalEbit - interest.currentTotal;
        
        row.innerHTML = `
            <td colspan="2">EARNINGS BEFORE TAXES<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totalEbt)}<\/td>
        `;
    } else {
        const gross = revenue.current - cost.current;
        const ebitda = gross - sellingAdmin.current;
        const ebit = ebitda - operating.current;
        const ebt = ebit - interest.current;
        row.innerHTML = `
            <td colspan="2">EARNINGS BEFORE TAXES<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(ebt)}<\/td>
        `;
    }
    return row;
}

function buildTotalNetIncomeLossRow(revenue, cost, sellingAdmin, operating, interest, tax, hasRegions, regions, data) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ff7f3a';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const regionCols = regions.map(region => {
            const gross = (revenue.current[region] || 0) - (cost.current[region] || 0);
            const ebitda = gross - (sellingAdmin.current[region] || 0);
            const ebit = ebitda - (operating.current[region] || 0);
            const ebt = ebit - (interest.current[region] || 0);
            const net = ebt - (tax.current[region] || 0);
            return `<td style="text-align: right;">${formatAmount(net)}<\/td>`;
        }).join('');
        
        const totalGross = revenue.currentTotal - cost.currentTotal;
        const totalEbitda = totalGross - sellingAdmin.currentTotal;
        const totalEbit = totalEbitda - operating.currentTotal;
        const totalEbt = totalEbit - interest.currentTotal;
        const totalNet = totalEbt - tax.currentTotal;
        
        row.innerHTML = `
            <td colspan="2">TOTAL NET INCOME/LOSS<\/td><td><\/td><td><\/td>
            ${regionCols}
            <td style="text-align: right; background-color: #ff7f3a;">${formatAmount(totalNet)}<\/td>
        `;
    } else {
        const gross = revenue.current - cost.current;
        const ebitda = gross - sellingAdmin.current;
        const ebit = ebitda - operating.current;
        const ebt = ebit - interest.current;
        const net = ebt - tax.current;
        row.innerHTML = `
            <td colspan="2">TOTAL NET INCOME/LOSS<\/td><td><\/td><td><\/td>
            <td style="text-align: right;">${formatAmount(net)}<\/td>
        `;
    }
    return row;
}

function buildSectionHeaderRow(label, hasRegions, regions) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ffe3d4';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const colspan = 4 + regions.length + 1;
        row.innerHTML = `<td colspan="${colspan}">${escapeHtml(label)}<\/td>`;
    } else {
        row.innerHTML = `<td colspan="2">${escapeHtml(label)}<\/td>${'<td><\/td>'.repeat(3)}`;
    }
    return row;
}

function buildLabelRow(label, hasRegions, regions) {
    const row = document.createElement('tr');
    row.style.backgroundColor = '#ffc2a4';
    row.style.fontWeight = 'bold';
    
    if (hasRegions) {
        const colspan = 4 + regions.length + 1;
        row.innerHTML = `<td colspan="${colspan}">${escapeHtml(label)}<\/td>`;
    } else {
        row.innerHTML = `<td colspan="2">${escapeHtml(label)}<\/td>${'<td><\/td>'.repeat(3)}`;
    }
    return row;
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatAmount(value) {
    const num = Number(value || 0);
    return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatPercent(value) {
    const num = Number(value || 0);
    return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
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

// Reset filter button
const resetFilterBtn = document.getElementById('resetFilterBtn');
if (resetFilterBtn) {
    resetFilterBtn.addEventListener('click', function() {
        document.getElementById('previewFilterForm').reset();
        generatePreviewTable({});
    });
}

// Filter form submission
const previewFilterForm = document.getElementById('previewFilterForm');
if (previewFilterForm) {
    previewFilterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const filters = getCurrentPreviewFilters();
        generatePreviewTable(filters);
    });
}

// Collapse/Uncollapse functionality
const previewCollapseBtn = document.getElementById('previewCollapseBtn');
let previewCollapsed = true;

function applyPreviewCollapseState() {
    const detailRows = document.querySelectorAll('.collapsible-detail');
    detailRows.forEach(row => {
        row.style.display = previewCollapsed ? 'none' : '';
    });
    if (previewCollapseBtn) {
        previewCollapseBtn.innerHTML = previewCollapsed
            ? '<i class="fa-solid fa-bars"></i> Uncollapse View'
            : '<i class="fa-solid fa-bars"></i> Collapse View';
    }
}

if (previewCollapseBtn) {
    previewCollapseBtn.addEventListener('click', () => {
        previewCollapsed = !previewCollapsed;
        applyPreviewCollapseState();
    });
}

// Year select limit (max 3)
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


    const exportExcelBtn = document.getElementById('exportExcelBtn');
    const exportSummaryExcelBtn = document.getElementById('exportSummaryExcelBtn');

    async function runExcelExport(mode = 'full') {
        // Get current filters
        const form = document.getElementById('previewFilterForm');
        if (!form) return;
        
        const formData = new FormData(form);
        
        // Get current preview filters (consolidated filters only)
        const { zone, branch_type, month, year } = getCurrentPreviewFilters();
        
        // Show loading
        const activeBtn = mode === 'summary' ? exportSummaryExcelBtn : exportExcelBtn;
        if (!activeBtn) return;

        const originalText = activeBtn.innerHTML;
        activeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting...';
        activeBtn.disabled = true;
        
        try {
            // Create form data for POST
            const postData = new FormData();
            postData.append('zone', zone);
            postData.append('branch_type', branch_type);
            postData.append('month', month);
            postData.append('year', year);
            postData.append('export_mode', mode);
            
            // Send request
            const response = await fetch('export_preview_excel_consol.php', {
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
                a.download = `Consolidated_Report_${suffix}_${new Date().toISOString().slice(0,10)}.xlsx`;
                document.body.appendChild(a);
                a.click();
                
                // Cleanup
                setTimeout(() => {
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }, 100);
                
                showStatusModal(
                    mode === 'summary'
                        ? 'Consolidated Report file exported successfully!'
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

</script>    

</body>
</html>

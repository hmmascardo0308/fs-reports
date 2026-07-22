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
                $gl_id = $prefix . '-' . $sub_order;
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
                    $gl_id = $prefix . '-' . $sub_order;
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

    header("Location: fs_reports.php");
    exit;
}

// Initialize variables used in display logic to avoid undefined variable notices
$desc_list = [];
$grouped = [];
$sub_orders = [];
$sub_order_map = [];
$distinct_desc_res = null;
$distinct_comp_res = null;
$distinct_rows = [];
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
    $sub_orders = [];
    $sub_order_map = [];
    $gl_id_map = [];

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

    // Fetch distinct description + comparative pairs for Check Rows modal
    $distinct_rows = [];
    $distinct_rows_query = "
        SELECT DISTINCT description, gl_description_comparative
        FROM fs_reports.gl_codes_ho_new
        WHERE description IS NOT NULL
          AND description != ''
          AND gl_description_comparative IS NOT NULL
          AND gl_description_comparative != ''
        ORDER BY description, gl_description_comparative
    ";
    $distinct_rows_res = mysqli_query($conn, $distinct_rows_query);
    if ($distinct_rows_res) {
        while ($drow = mysqli_fetch_assoc($distinct_rows_res)) {
            $distinct_rows[] = $drow;
        }
    }

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

$seen_pairs = [];

while ($row = mysqli_fetch_assoc($comp_by_desc_res)) {
    $d = $row['description'];
    $c = $row['gl_description_comparative'];
    
    $pair_key = $d . '|' . $c;
    
    if (!isset($seen_pairs[$pair_key])) {
        $distinct_rows[] = [
            'description' => $d,
            'gl_description_comparative' => $c
        ];
        $seen_pairs[$pair_key] = true;
    }

    $comps_by_desc[$d][] = $c;
}
$comps_by_desc_json = json_encode($comps_by_desc);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Statement</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/fs_reports.css?v=<?= time(); ?>">
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
        <h2 style="text-align: center; margin-top: -2%;">Comparative Report (FS Report w/ HO allocated and Cumulative Report)</h3>

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
                            <th><i class="fa-solid fa-up-down-left-right"></i> Drag</th>
                            <th><i class="fa-solid fa-hashtag"></i> GL ID</th>
                            <th><i class="fa-solid fa-sort-numeric-down"></i> Sort Order</th>
                            <th><i class="fa-solid fa-file-lines"></i> Description</th>
                            <th><i class="fa-solid fa-arrow-down-short-wide"></i> Sub Order</th>
                            <th><i class="fa-solid fa-chart-column"></i> Comparative Report Description</th>
                            <th><i class="fa-solid fa-barcode"></i> GL Code</th>
                            <th><i class="fa-solid fa-book"></i> GL Description</th>
                            <th><i class="fa-solid fa-code-compare"></i> New GL Code</th>
                            <th><i class="fa-solid fa-book-open"></i> New GL Description</th>
                            <th><i class="fa-solid fa-link"></i> GL Mapping/Shortcut</th>
                            <th><i class="fa-solid fa-gears"></i> Action</th>
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
                                        </td><td style="text-align: center; color: #000000;"><?php echo htmlspecialchars($show_gl_id); ?></td>
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
                                <tr class="category-header-row" style="background-color: #f2f4f6; border-bottom: 2px solid #dee2e6;  border-bottom: 2px solid #de6000; border-top: 2px solid #de6000;">
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
            <a href="comparative_report_original_ho.php" style="text-decoration: none;" class="btn-preview"><i class="fa-solid fa-file"></i> Comparative Report (with HO allocated)</a>
            <a href="comparative_report_original_cumu.php" style="text-decoration: none;" class="btn-preview"><i class="fa-solid fa-file"></i> Cumulative Report</a>
        </div>
        </div>

    </main>

    <!-- Modal -->
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

    // Description toggle
    document.querySelectorAll('input[name="desc_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const rowDescType = document.querySelector('input[name="row_desc_type"]:checked').value;
            if (this.value === 'new' && rowDescType === 'existing') {
                alert('You cannot use existing row description for a new description.');
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
            const distinctList = [...new Set(list)];
            distinctList.forEach((val) => {
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
                alert('You cannot use existing row description for a new description.');
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

    // Drag-and-drop reordering
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

        const headerRows = Array.from(tbody.querySelectorAll('tr.category-header-row'));
        headerRows.forEach(header => {
            const descCell = header.querySelector('td:nth-child(4)');
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
            const glDesc = (row.querySelector('td:nth-child(9)')?.textContent || '').trim();
            const hay = `${desc} ${comp} ${glCode} ${glDesc}`.toLowerCase();
            const hit = hay.includes(q);
            row.style.display = hit ? '' : 'none';
            if (hit && desc) matchedDescs.add(desc);
        });

        headerRows.forEach(header => {
            const descCell = header.querySelector('td:nth-child(4)');
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

        fetch('save_sub_order_fs_ho.php', {
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
            fetch('delete_gl_row_fs_ho.php', {
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
    </script>    

    <?php include '../footer.php'; ?>
</body>
</html>
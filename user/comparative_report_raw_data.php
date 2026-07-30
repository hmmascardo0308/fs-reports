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

try {
    $has_sort_order = false;
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM fs_reports.gl_codes_new LIKE 'sort_order'");
    if ($col_check && mysqli_num_rows($col_check) > 0) {
        $has_sort_order = true;
    }
    $order_by = $has_sort_order
        ? "ORDER BY sub_order"
        : "ORDER BY sort_order, id";

    $query = "SELECT id, gl_id, sort_order, sub_order, description, gl_description_comparative, gl_code, new_gl_code, gl_description, new_gl_description, gl_mapping 
          FROM fs_reports.gl_codes_new
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

} catch (Exception $e) {
    $error_message = "Error: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/settings.css?v=<?= time(); ?>">
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

        <h2 style="text-align: center; margin-top: -2%;">Comparative Report</h3>

        <div style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
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
                        </tr>
                    </thead>
<tbody>
    <?php
    // Check if variables exist and are arrays before using them
    $desc_list = isset($desc_list) && is_array($desc_list) ? $desc_list : [];
    $sub_orders = isset($sub_orders) && is_array($sub_orders) ? $sub_orders : [];
    
    if (empty($desc_list)) {
        // No data to display
        echo '<tr><td colspan="10" style="text-align: center;">No GL codes found.</td></tr>';
    } else {
        $group_counter = 0;
        foreach ($desc_list as $desc) {
            $show_header = ($desc !== '');
            if ($show_header) {
                $group_counter++;
            }
            
            // Check if $sub_orders[$desc] exists
            $sub_order_items = isset($sub_orders[$desc]) && is_array($sub_orders[$desc]) ? $sub_orders[$desc] : [];
            
            $subgroup_index = 0;
            foreach ($sub_order_items as $comp) {
                $subgroup_index++;
                $sub_order_str = isset($sub_order_map[$desc][$comp]) ? $sub_order_map[$desc][$comp] : '';
                $gl_id_str = isset($gl_id_map[$desc][$comp]) ? $gl_id_map[$desc][$comp] : '';
                
                $sub_rows = isset($grouped[$desc][$comp]) && is_array($grouped[$desc][$comp]) ? $grouped[$desc][$comp] : [];
                
                // Sort individual GL rows within the sub-group by ID (oldest first, newest last)
                usort($sub_rows, function($a, $b) {
                    return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
                });
                
                foreach ($sub_rows as $sidx => $row) {
                    $is_first = ($sidx === 0);
                    $show_sub_order = $is_first ? $sub_order_str : '';
                    $show_gl_id = $is_first ? $gl_id_str : '';
                    
                    $group_key = $desc . '||' . $comp;
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($row['id']); ?>"
                        data-description="<?php echo htmlspecialchars($desc); ?>"
                        data-glcomp="<?php echo htmlspecialchars($comp); ?>"
                        data-glmap="<?php echo htmlspecialchars($row['gl_mapping'] ?? ''); ?>"
                        data-sortorder="<?php echo htmlspecialchars($row['sort_order'] ?? ''); ?>"
                        data-suborder="<?php echo htmlspecialchars($row['sub_order'] ?? ''); ?>"
                        data-group="<?php echo htmlspecialchars($group_key); ?>">
                        <td style="text-align: left; color: #000000;"><?php echo htmlspecialchars($show_gl_id); ?></td>
                        <td></td>
                        <td></td>
                        <td style="text-align: center; color: #000000;"><?php echo htmlspecialchars($show_sub_order); ?></td>
                        <td><?php echo htmlspecialchars($comp); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['gl_code'] ?? ''); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['gl_description'] ?? ''); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['new_gl_code'] ?? ''); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['new_gl_description'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['gl_mapping'] ?? ''); ?></td>
                    </tr>
                    <?php
                }
            }
            
            // Print the category header row after its sub-orders
            if ($show_header) {
                ?>
                <tr class="category-header-row" style="background-color: #f2f4f6; border-bottom: 2px solid #de6000; border-top: 2px solid #de6000;">
                    <td></td>
                    <td style="text-align: center; font-weight: bold;"><?php echo $group_counter; ?></td>
                    <td style="text-align: center; font-weight: bold; color: #2c3e50; letter-spacing: 1px;">
                        <?php echo htmlspecialchars($desc); ?>
                    </td>
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
                <?php
            }
        }
    }
    ?>
</tbody>
                </table>
            <?php endif; ?>
        </section>

        <div style="margin-top: 20px; text-align: right; display: flex; gap: 10px; justify-content: center;">
            <a href="comparative_report_original_raw.php" style="text-decoration: none;" class="btn-preview"><i class="fa-solid fa-file"></i> Comparative Report (Raw Data)</a>
            <a href="manual_adjustment_new_raw.php" style="text-decoration: none;" class="btn-preview"><i class="fa-solid fa-file"></i> Comparative Report (Manual Adjustment) Raw Data</a>
            <a href="consolidated_raw.php" style="text-decoration: none;" class="btn-preview"><i class="fa-solid fa-file"></i> Consolidated Report (Per Zone) Raw Data</a>
        </div>
        </div>

    </main>

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
    // Search filter functionality
    const glSearchInput = document.getElementById('glSearchInput');
    const tbody = document.querySelector('#glTable tbody');

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
            const glDesc = (row.querySelector('td:nth-child(8)')?.textContent || '').trim();
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

    if (glSearchInput) {
        glSearchInput.addEventListener('input', applyGlSearchFilter);
    }

    // Status modal helpers
    const statusModal = document.getElementById('statusModal');
    const statusModalTitle = document.getElementById('statusModalTitle');
    const statusModalBody = document.getElementById('statusModalBody');
    const statusCloseBtn = document.querySelector('.status-close-btn');
    const statusOkBtn = document.getElementById('statusOkBtn');

    function showStatusModal(message, type = 'success', shouldReload = false) {
        statusModalTitle.textContent = type === 'error' ? 'Error' : 'Success';
        statusModalBody.textContent = message;
        statusModal.classList.add('open');
        statusModal.setAttribute('aria-hidden', 'false');
        if (shouldReload) {
            setTimeout(() => location.reload(), 1500);
        }
    }

    function closeStatusModal() {
        statusModal.classList.remove('open');
        statusModal.setAttribute('aria-hidden', 'true');
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
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

// --- FETCH GL CODES DATA ---
$gl_codes_data = [];
$table_error = null;

try {
    // Check if connection exists
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }
    
    // Query to fetch all gl_codes data ordered by sort_order and sub_order
    $sql = "SELECT gl_id, sort_order, description, sub_order, gl_description_comparative, gl_code, gl_description, new_gl_code, new_gl_description, gl_mapping 
            FROM fs_reports.gl_codes_new 
            ORDER BY sort_order ASC, sub_order ASC";
            
    $result = $conn->query($sql);
    
    if ($result === false) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $gl_codes_data[] = $row;
        }
    }
    
    $result->free();
    
} catch (Exception $e) {
    $table_error = "Error loading GL Codes data: " . $e->getMessage();
    error_log($e->getMessage());
}

// --- FETCH PREVIEW DATA FOR MODAL (Grouped format) ---
$preview_groups = [];
$preview_error = null;

try {
    // Query for distinct preview data grouped by description
    $preview_sql = "SELECT DISTINCT gl_id, sort_order, sub_order, description, gl_description_comparative 
                    FROM fs_reports.gl_codes_new 
                    ORDER BY sort_order ASC, sub_order ASC";
            
    $preview_result = $conn->query($preview_sql);
    
    if ($preview_result === false) {
        throw new Exception("Preview query failed: " . $conn->error);
    }
    
    if ($preview_result->num_rows > 0) {
        // Group data by description
        while ($row = $preview_result->fetch_assoc()) {
            $description = $row['description'] ?? '';
            $sort_order = $row['sort_order'] ?? '';
            
            if (!isset($preview_groups[$description])) {
                $preview_groups[$description] = [
                    'sort_order' => $sort_order,
                    'description' => $description,
                    'items' => []
                ];
            }
            
            $preview_groups[$description]['items'][] = [
                'sub_order' => $row['sub_order'] ?? '',
                'gl_description_comparative' => $row['gl_description_comparative'] ?? ''
            ];
        }
    }
    
    $preview_result->free();
    
} catch (Exception $e) {
    $preview_error = "Error loading preview data: " . $e->getMessage();
    error_log($e->getMessage());
}

// --- FETCH FILTER OPTIONS FOR PREVIEW MODAL ---
$filter_options = [
    'regions' => [],
    'months' => [],
    'years' => []
];
$filter_error = null;

try {
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    $distinct_queries = [
        'regions' => "SELECT DISTINCT region AS val FROM fs_reports.comparative_report WHERE region IS NOT NULL AND region != '' ORDER BY region ASC",
        'months' => "SELECT DISTINCT DATE_FORMAT(transaction_month, '%Y-%m') AS val FROM fs_reports.comparative_report WHERE transaction_month IS NOT NULL ORDER BY val DESC",
        'years' => "SELECT DISTINCT transaction_year AS val FROM fs_reports.comparative_report WHERE transaction_year IS NOT NULL ORDER BY val DESC"
    ];

    foreach ($distinct_queries as $key => $sql) {
        $res = $conn->query($sql);
        if ($res === false) {
            throw new Exception("Filter query failed for {$key}: " . $conn->error);
        }

        while ($row = $res->fetch_assoc()) {
            $val = $row['val'];
            if ($val === null || $val === '') {
                continue;
            }

            if ($key === 'months') {
                $label = date('F Y', strtotime($val . '-01'));
                $filter_options['months'][] = [
                    'value' => $val,
                    'label' => $label
                ];
            } else {
                $filter_options[$key][] = $val;
            }
        }

        $res->free();
    }
} catch (Exception $e) {
    $filter_error = "Error loading filter options: " . $e->getMessage();
    error_log($e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Adjustment</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/manual.css?v=<?= time(); ?>">
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
            <div class="page-title">Manual Adjustment</div>
            
            <?php if ($status_message): ?>
                <div class="alert alert-<?php echo $status_type; ?>" style="background: <?php echo $status_type === 'success' ? '#c6f6d5' : '#fed7d7'; ?>; color: <?php echo $status_type === 'success' ? '#276749' : '#c53030'; ?>; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($status_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($table_error): ?>
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($table_error); ?>
                </div>
            <?php else: ?>
                
                <div class="data-table-container">
                    <?php if (empty($gl_codes_data)): ?>
                        <div class="empty-state">
                            <i class="fas fa-table"></i>
                            <p>No GL Codes data found in the database.</p>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>GL ID</th>
                                    <th class="sort-order">Sort Order</th>
                                    <th>Description</th>
                                    <th class="sub-order">Sub Order</th>
                                    <th>Comparative Description</th>
                                    <th>GL Code</th>
                                    <th>GL Description</th>
                                    <th>New GL Code</th>
                                    <th>New GL Description</th>
                                    <th>GL Mapping</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $current_desc = null;
                                $current_comp = null;
                                $last_sort = null;

                                foreach ($gl_codes_data as $row): 
                                    $desc = $row['description'] ?? '';
                                    $comp = $row['gl_description_comparative'] ?? '';
                                    $sort = $row['sort_order'] ?? '';
                                    $sub = $row['sub_order'] ?? '';
                                    $gl_id = $row['gl_id'] ?? '';

                                    if ($current_desc !== null && $desc !== $current_desc): ?>
                                        <tr style="background-color: #ffdcc5; font-weight: bold;">
                                            <td></td>
                                            <td class="sort-order"><?php echo htmlspecialchars($last_sort); ?></td>
                                            <td style="text-align: center;"><?php echo htmlspecialchars($current_desc); ?></td>
                                            <td colspan="7"></td>
                                        </tr>
                                    <?php 
                                        $current_comp = null;
                                    endif;

                                    $is_new_comp = ($comp !== $current_comp);
                                    $show_sub = $is_new_comp ? $sub : '';
                                    $show_gl_id = $is_new_comp ? $gl_id : '';
                                    
                                    $current_desc = $desc;
                                    $current_comp = $comp;
                                    $last_sort = $sort;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($show_gl_id); ?></td>
                                        <td class="sort-order"></td>
                                        <td></td>
                                        <td class="sub-order"><?php echo htmlspecialchars($show_sub); ?></td>
                                        <td><?php echo htmlspecialchars($comp); ?></td>
                                        <td class="gl-code"><?php echo htmlspecialchars($row['gl_code'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['gl_description'] ?? ''); ?></td>
                                        <td class="gl-code"><?php echo htmlspecialchars($row['new_gl_code'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['new_gl_description'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['gl_mapping'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if ($current_desc !== null): ?>
                                    <tr style="background-color: #ffdcc5; font-weight: bold;">
                                        <td></td>
                                        <td class="sort-order"><?php echo htmlspecialchars($last_sort); ?></td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($current_desc); ?></td>
                                        <td colspan="7"></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- Preview Table Button -->
                <button class="btn-preview" id="previewBtn">
                    <i class="fas fa-eye"></i> Preview Table
                </button>
                <a href="manual_adjustment_new.php" class="btn-preview" style="text-decoration: none;">Manual Adjust</a>
                
            <?php endif; ?>
        </div>
    </main>
    
    <!-- Preview Modal -->
    <div id="previewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-table-list" style="margin-right: 8px;"></i> Manual Adjustment (per region)</h3>
                <button class="close-modal" id="closeModalBtn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="preview-filter-section">
                    <?php if ($filter_error): ?>
                        <div class="error-preview">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($filter_error); ?>
                        </div>
                    <?php endif; ?>
                    <form id="previewFilterForm" class="preview-filter-form">
                        <div class="filter-field">
                            <label for="previewRegion">Region</label>
                            <select id="previewRegion" name="region">
                                <option value="">All Regions</option>
                                <?php foreach ($filter_options['regions'] as $region): ?>
                                    <option value="<?php echo htmlspecialchars($region); ?>"><?php echo htmlspecialchars($region); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label for="previewMonth">Transaction Month</label>
                            <input type="month" id="previewMonth" name="transaction_month" />
                        </div>
                        <div class="filter-field">
                            <label for="previewYear">Transaction Year</label>
                            <select id="previewYear" name="transaction_year">
                                <option value="">All Years</option>
                                <?php foreach ($filter_options['years'] as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-apply-filters">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>

                            <button type="button" class="btn-manual-filters" id="saveManualAdjustmentBtn">
                                <i class="fa-solid fa-coins"></i> Save Adjustment
                            </button>

                            <button type="button" class="btn-collapse" id="toggleCollapseBtn">
                                <i class="fa-solid fa-compress"></i> Collapse View
                            </button>

                            <button type="button" id="resetPreviewFilters" class="btn-reset-filters">
                                <i class="fa-solid fa-rotate-left"></i> Clear
                            </button>
                        </div>
                    </form>
                    <div id="previewFilterStatus" class="preview-filter-status">Select filters, then click Apply Filters.</div>
                </div>
                <?php if ($preview_error): ?>
                    <div class="error-preview">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($preview_error); ?>
                    </div>
                <?php elseif (empty($preview_groups)): ?>
                    <div class="empty-preview">
                        <i class="fas fa-database" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>
                        <p>No preview data available.</p>
                    </div>
                <?php else: ?>
                    <div class="preview-table-container">
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>Sort Order</th>
                                    <th>Description</th>
                                    <th>Comparative Description</th>
                                    <th class="sub-order-col"></th>
                                    <th class="region-col" colspan="3"><span id="previewRegionLabel">All Regions</span></th>
                                </tr>
                                <tr>
                                    <th colspan="3"></th>
                                    <th></th>
                                    <th>MLFSI</th>
                                    <th>JEWELERS</th>
                                    <th>TOTAL</th>
                                </tr>
                                <tr style="height: 20px;">
                                    <td colspan="7"></td>
                                </tr>
                                <tr style="height: 20px; background-color: #ff8635; font-weight: bold;">
                                    <td colspan="7">REVENUES</td>
                                </tr>
                            </thead>
                            <tbody id="previewTableBody">
                                <?php foreach ($preview_groups as $group): ?>
                                    <?php
                                        $sortStr = (string)($group['sort_order'] ?? '');
                                        $hideSubRows = in_array($sortStr, ['6', '8', '11'], true);
                                        $hideGroupFooter = in_array($sortStr, ['24', '25', '26'], true);
                                    ?>
                                    <!-- Group Items (Sub Orders and Comparative Descriptions) - displayed FIRST -->
                                    <?php foreach ($group['items'] as $item): ?>
                                        <tr class="preview-item-row"
                                            data-sort="<?php echo htmlspecialchars($group['sort_order']); ?>"
                                            data-sub="<?php echo htmlspecialchars($item['sub_order']); ?>"
                                            data-comp="<?php echo htmlspecialchars($item['gl_description_comparative'], ENT_QUOTES); ?>"
                                            data-group="<?php echo htmlspecialchars($group['description'], ENT_QUOTES); ?>"
                                            <?php echo $hideSubRows ? 'style="display:none;"' : ''; ?>>
                                            <td></td> <!-- <?php echo htmlspecialchars($group['sort_order']); ?>-->
                                            <td></td> <!-- <?php echo htmlspecialchars($group['description']); ?>-->
                                            <td><?php echo htmlspecialchars($item['gl_description_comparative']); ?></td>
                                            <td style="text-align: center;"></td> <!--<?php echo htmlspecialchars($item['sub_order']); ?>  -->
                                            <td class="num-col amount-cell" data-branch="mlfsi">-</td>
                                            <td class="num-col amount-cell" data-branch="jewelers">-</td>
                                            <td class="num-col amount-cell" data-branch="total">-</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Group Footer Row (Summary) - displayed LAST -->
                                    <tr class="group-footer" data-group="<?php echo htmlspecialchars($group['description'], ENT_QUOTES); ?>" data-sort="<?php echo htmlspecialchars($group['sort_order']); ?>" <?php echo $hideGroupFooter ? 'style="display:none;"' : ''; ?>>
                                        <td class="sort-order-col" style="text-align: center;"><?php echo htmlspecialchars($group['sort_order']); ?></td>
                                        <td style="text-align: center;"><strong><?php echo htmlspecialchars($group['description']); ?></strong></td>
                                        <td></td>
                                        <td class="sub-order-col"></td>
                                        <td class="num-col group-total-cell" data-branch="mlfsi">-</td>
                                        <td class="num-col group-total-cell" data-branch="jewelers">-</td>
                                        <td class="num-col group-total-cell" data-branch="total">-</td>
                                    </tr>

                                    <tr class="spacer-row" style="height: 20px;" data-sort="<?php echo htmlspecialchars($group['sort_order']); ?>">
                                        <td colspan="7"></td>
                                    </tr>
                                    <?php if ((string)($group['sort_order'] ?? '') === '23'): ?>
                                        <tr class="total-selling-admin-row" style="background-color: #ff8635;">
                                            <td style="color: #000000;" colspan="2"><strong>TOTAL SELLING AND ADMIN EXPENSES</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col total-selling-admin-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col total-selling-admin-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col total-selling-admin-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                        <tr class="spacer-row" style="height: 20px;" data-sort="23">
                                            <td colspan="7"></td>
                                        </tr>
                                        <tr class="ebitda-row" style="background-color: #ff8635;">
                                            <td style="color: #000000;" colspan="2"><strong>EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col ebitda-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col ebitda-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col ebitda-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                      
                                    <?php endif; ?>
                                    <?php if ((string)($group['sort_order'] ?? '') === '24'): ?>
                                        <tr class="ebit-row" style="background-color: #ff8635;">
                                            <td style="color: #000000;" colspan="2"><strong>EARNINGS BEFORE INTEREST & TAXES</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col ebit-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col ebit-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col ebit-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                      
                                    <?php endif; ?>
                                    <?php if ((string)($group['sort_order'] ?? '') === '25'): ?>
                                        <tr class="ebt-row" style="background-color: #ff8635;">
                                            <td style="color: #000000;" colspan="2"><strong>EARNINGS BEFORE TAXES</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col ebt-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col ebt-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col ebt-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                    
                                    <?php endif; ?>
                                    <?php if ((string)($group['sort_order'] ?? '') === '26'): ?>
                                        <tr class="net-income-row" style="background-color: #ff8635;">
                                            <td style="color: #000000;" colspan="2"><strong>TOTAL NET INCOME/LOSS</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col net-income-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col net-income-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col net-income-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                        <tr class="spacer-row" style="height: 20px;" data-sort="26">
                                            <td colspan="7"></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ((string)($group['sort_order'] ?? '') === '20'): ?>
                                        <tr class="total-revenues-row" style="background-color: #ff8635;" >
                                            <td style="color: #000000;" colspan="2"><strong>TOTAL REVENUES</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col total-revenues-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col total-revenues-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col total-revenues-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                        <tr class="spacer-row" style="height: 20px;"> <!--  data-sort="20" -->
                                            <td colspan="7"></td>
                                        </tr>
                                        <tr  style="background-color: #ff8635; color: #000000; font-weight: bold;">
                                            <td colspan="2" > Cost of Sales/Service</td>
                                            <td colspan="5"></td>
                                        </tr>
                                        <tr class="spacer-row" style="height: 20px;" data-sort="20">
                                            <td colspan="7"></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ((string)($group['sort_order'] ?? '') === '21'): ?>
                                        <tr class="gross-profit-row" style="background-color: #ff8635;" >
                                            <td style="color: #000000;" colspan="2"><strong>GROSS PROFIT</strong></td>
                                            <td></td>
                                            <td class="sub-order-col"></td>
                                            <td class="num-col gross-profit-cell" style="color: #000000; font-weight: bold;" data-branch="mlfsi">-</td>
                                            <td class="num-col gross-profit-cell" style="color: #000000; font-weight: bold;" data-branch="jewelers">-</td>
                                            <td class="num-col gross-profit-cell" style="color: #000000; font-weight: bold;" data-branch="total">-</td>
                                        </tr>
                                        <tr class="spacer-row" style="height: 20px;" data-sort="21">
                                            <td colspan="7"></td>
                                        </tr>
                                         <tr  style="background-color: #ff8635; color: #000000; font-weight: bold;" >
                                            <td colspan="2"> SELLING & ADMIN EXPENSE</td>
                                            <td colspan="5"></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
           
             <div style="text-align: right; display: flex; gap: 5px; justify-content: center; margin-bottom: 10px;">
            <a href="comparative_with_adjustment.php" class="btn-preview" style=" text-decoration: none;">
                <i class="fa-solid fa-file-excel"></i> Generate Comparative Report
            </a>
            </div>

        </div>
        
    </div>

    <!-- Adjustment Modal -->
    <div id="adjustmentModal" class="modal">
        <div class="modal-content adjustment-content">
            <div class="modal-header">
                <h3><i class="fas fa-scale-balanced" style="margin-right: 8px;"></i> Reallocate Amount</h3>
                <button class="close-modal" id="adjustmentCloseBtn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="adjustment-info">
                    <div><strong>From:</strong> <span id="adjustmentSourceLabel">-</span></div>
                    <div class="adjustment-available">Available: <span id="adjustmentAvailableLabel">-</span></div>
                </div>
                <div class="adjustment-form">
                    <label for="adjustmentBranch">Column</label>
                    <select id="adjustmentBranch">
                        <option value="total">TOTAL</option>
                        <option value="mlfsi">MLFSI</option>
                        <option value="jewelers">JEWELERS</option>
                    </select>

                    <label for="adjustmentAmount">Amount to deduct</label>
                    <input id="adjustmentAmount" type="number" step="0.01" min="0" placeholder="0.00" />
                </div>
                <div class="adjustment-actions">
                    <button type="button" class="btn-reset-filters" id="adjustmentCancelBtn">Cancel</button>
                    <button type="button" class="btn-apply-filters" id="adjustmentConfirmBtn">Select Target Row</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Modal functionality
        const modal = document.getElementById('previewModal');
        const previewBtn = document.getElementById('previewBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const previewFilterForm = document.getElementById('previewFilterForm');
        const previewRegionLabel = document.getElementById('previewRegionLabel');
        const previewTableBody = document.getElementById('previewTableBody');
        const previewFilterStatus = document.getElementById('previewFilterStatus');
        const adjustmentModal = document.getElementById('adjustmentModal');
        const adjustmentCloseBtn = document.getElementById('adjustmentCloseBtn');
        const adjustmentCancelBtn = document.getElementById('adjustmentCancelBtn');
        const adjustmentConfirmBtn = document.getElementById('adjustmentConfirmBtn');
        const adjustmentAmountInput = document.getElementById('adjustmentAmount');
        const adjustmentBranchSelect = document.getElementById('adjustmentBranch');
        const adjustmentSourceLabel = document.getElementById('adjustmentSourceLabel');
        const adjustmentAvailableLabel = document.getElementById('adjustmentAvailableLabel');
        const saveManualAdjustmentBtn = document.getElementById('saveManualAdjustmentBtn');
        const previewMonthInput = document.getElementById('previewMonth');
        const previewYearSelect = document.getElementById('previewYear');

        // Mutual exclusivity for Month and Year filters
        if (previewMonthInput && previewYearSelect) {
            previewMonthInput.addEventListener('input', function() {
                if (this.value) {
                    previewYearSelect.disabled = true;
                    previewYearSelect.value = "";
                } else {
                    previewYearSelect.disabled = false;
                }
            });

            previewYearSelect.addEventListener('change', function() {
                if (this.value) {
                    previewMonthInput.disabled = true;
                    previewMonthInput.value = "";
                } else {
                    previewMonthInput.disabled = false;
                }
            });
        }

        let lastTotalsMap = new Map();
        let adjustmentsItem = new Map();
        let adjustmentsGroup = new Map();
        let lastGroupTotalsFinal = new Map();
        let pendingTransfer = null;
        let selectionMode = false;
        let selectedSourceRow = null;
        let lastPayloadKey = '';
        let originalBaseTotals = new Map(); // Store original fetched totals for change detection
        
        // Handle Clear Filters button
        const resetPreviewFilters = document.getElementById('resetPreviewFilters');
        if (resetPreviewFilters) {
            resetPreviewFilters.addEventListener('click', function() {
                previewFilterForm.reset();
                // Re-enable inputs on reset
                if (previewMonthInput) previewMonthInput.disabled = false;
                if (previewYearSelect) previewYearSelect.disabled = false;
                
                if (previewRegionLabel) {
                    previewRegionLabel.textContent = 'All Regions';
                }
                clearTotals();
                resetAdjustments();
                sessionStorage.removeItem('manualAdjustmentData');
                setFilterStatus('Select filters, then click Apply Filters.');
            });
        }

        // Open modal when preview button is clicked
        if (previewBtn) {
            previewBtn.onclick = function() {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            }
        }
        
        // Close modal when X is clicked
        if (closeModalBtn) {
            closeModalBtn.onclick = function() {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                selectionMode = false;
                pendingTransfer = null;
                if (selectedSourceRow) {
                    selectedSourceRow.classList.remove('selected-source');
                    selectedSourceRow = null;
                }
            }
        }

        if (adjustmentCloseBtn) {
            adjustmentCloseBtn.onclick = function() {
                closeAdjustmentModal();
            }
        }

        if (adjustmentCancelBtn) {
            adjustmentCancelBtn.onclick = function() {
                closeAdjustmentModal();
            }
        }

        if (adjustmentBranchSelect) {
            adjustmentBranchSelect.addEventListener('change', function() {
                if (selectedSourceRow) {
                    updateAdjustmentAvailable(selectedSourceRow);
                }
            });
        }

        if (adjustmentConfirmBtn) {
            adjustmentConfirmBtn.addEventListener('click', function() {
                if (!selectedSourceRow) return;
                const amount = Number(adjustmentAmountInput?.value || 0);
                if (!isFinite(amount) || amount <= 0) {
                    setFilterStatus('Please enter a valid amount.', true);
                    return;
                }
                const branch = adjustmentBranchSelect ? adjustmentBranchSelect.value : 'total';
                pendingTransfer = {
                    sourceRow: selectedSourceRow,
                    amount,
                    branch
                };
                selectionMode = true;
                closeAdjustmentModal();
                setFilterStatus('Select a target row to add the deducted amount.');
            });
        }

      if (saveManualAdjustmentBtn) {
    saveManualAdjustmentBtn.addEventListener('click', async function() {
        if (!lastPayloadKey) {
            setFilterStatus('Please apply filters first before saving adjustments.', true);
            return;
        }
        
        // Get current filters
        const formData = new FormData(previewFilterForm);
        const filters = {
            region: (formData.get('region') || '').toString().trim(),
            transaction_month: (formData.get('transaction_month') || '').toString().trim(),
            transaction_year: (formData.get('transaction_year') || '').toString().trim()
        };
        
        // Validate region is selected
        if (!filters.region) {
            setFilterStatus('Please select a specific region before saving adjustments.', true);
            return;
        }
        
        // Collect ALL current adjustment data (including zeros)
        const adjustments = collectAdjustmentData();
        
        if (adjustments.length === 0) {
            setFilterStatus('No data rows found to save.', true);
            return;
        }
        
        // Show summary of what will be saved
        const nonZeroCount = adjustments.filter(a => a.mlfsi !== 0 || a.jewelers !== 0).length;
        const zeroCount = adjustments.length - nonZeroCount;
        
        const confirmMessage = `Are you sure you want to save adjustments for ${filters.region}?\n\n` +
                              `Total rows to save: ${adjustments.length}\n` +
                              `Rows with amounts: ${nonZeroCount}\n` +
                              `Rows with zeros: ${zeroCount}\n\n` +
                              `This will overwrite any existing adjustments for this region and filter combination.`;
        
        if (!confirm(confirmMessage)) return;
        
        setFilterStatus('Saving adjustments...');
        saveManualAdjustmentBtn.disabled = true;
        saveManualAdjustmentBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        
        try {
            const response = await fetch('save_manual_adjustment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    adjustments: adjustments,
                    filters: filters,
                    hasChanges: true,
                    includeZeros: true // Flag to indicate we want to save all rows
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                setFilterStatus(result.message);
                alert(result.message);
                
                // Clear session storage to prevent double-saving
                sessionStorage.removeItem('manualAdjustmentData');
                
                // Reset adjustments tracking to indicate saved state
                adjustmentsItem = new Map();
                adjustmentsGroup = new Map();
                
                // Update original base totals to current values (including zeros)
                updateOriginalBaseTotals();
                
                // Optional: Reload the current view to show saved state
                // previewFilterForm.dispatchEvent(new Event('submit'));
                
            } else {
                setFilterStatus(result.error || 'Failed to save adjustments.', true);
                alert('Error: ' + (result.error || 'Failed to save adjustments'));
            }
        } catch (err) {
            setFilterStatus('Network error: ' + err.message, true);
            alert('Network error: ' + err.message);
        } finally {
            saveManualAdjustmentBtn.disabled = false;
            saveManualAdjustmentBtn.innerHTML = '<i class="fa-solid fa-coins"></i> Save Adjustment';
        }
    });
}

        if (previewTableBody) {
            previewTableBody.addEventListener('dblclick', function(e) {
                if (selectionMode) return;
                if (!lastTotalsMap || lastTotalsMap.size === 0) return;

                // Restriction: User can only edit if the filter is Region + Transaction Month
                const region = document.getElementById('previewRegion').value;
                const month = document.getElementById('previewMonth').value;

                if (!region || !month) {
                    alert('Amount adjustment is only allowed when filtering by a specific Region and Transaction Month.');
                    setFilterStatus('Select a Region and Transaction Month to enable adjustments.', true);
                    return;
                }

                const row = e.target.closest('tr.preview-item-row, tr.group-footer');
                if (!row) return;
                if (row.style.display === 'none') return;
                openAdjustmentModal(row);
            });

            previewTableBody.addEventListener('click', function(e) {
                if (!selectionMode || !pendingTransfer) return;
                const targetRow = e.target.closest('tr.preview-item-row, tr.group-footer');
                if (!targetRow) return;
                if (targetRow.style.display === 'none') return;
                if (pendingTransfer.sourceRow === targetRow) {
                    setFilterStatus('Please select a different target row.', true);
                    return;
                }

                const amount = pendingTransfer.amount;
                const branch = pendingTransfer.branch;
                const sourceRow = pendingTransfer.sourceRow;

                if (sourceRow.classList.contains('preview-item-row')) {
                    applyAdjustment(adjustmentsItem, getItemKey(sourceRow), branch, -amount);
                } else {
                    applyAdjustment(adjustmentsGroup, getGroupKey(sourceRow), branch, -amount);
                }

                if (targetRow.classList.contains('preview-item-row')) {
                    applyAdjustment(adjustmentsItem, getItemKey(targetRow), branch, amount);
                } else {
                    applyAdjustment(adjustmentsGroup, getGroupKey(targetRow), branch, amount);
                }

                selectionMode = false;
                pendingTransfer = null;
                if (selectedSourceRow) {
                    selectedSourceRow.classList.remove('selected-source');
                    selectedSourceRow = null;
                }
                renderTotalsFromData();
                persistAdjustments();
                setFilterStatus('Totals updated.');
            });
        }

        function setFilterStatus(message, isError = false) {
            if (!previewFilterStatus) return;
            previewFilterStatus.textContent = message || '';
            previewFilterStatus.style.color = isError ? '#ff0000' : '#000000';
        }

        function formatAmount(value) {
            const num = Number(value);
            if (!isFinite(num)) return '-';
            return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function parseAmount(text) {
            const num = Number(String(text || '').replace(/,/g, '').trim());
            return isFinite(num) ? num : 0;
        }

        function getItemKey(row) {
            const sort = row.getAttribute('data-sort') || '';
            const sub = row.getAttribute('data-sub') || '';
            const comp = row.getAttribute('data-comp') || '';
            return JSON.stringify([sort, sub, comp]);
        }

        function getGroupKey(row) {
            return row.getAttribute('data-group') || '';
        }

        function getRowLabel(row) {
            if (row.classList.contains('preview-item-row')) {
                const sort = row.getAttribute('data-sort') || '';
                const sub = row.getAttribute('data-sub') || '';
                const comp = row.getAttribute('data-comp') || '';
                const compLabel = comp ? ` - ${comp}` : '';
                return `SO ${sort} / Sub ${sub}${compLabel}`;
            }
            const sort = row.getAttribute('data-sort') || '';
            const group = row.getAttribute('data-group') || '';
            return `SO ${sort} - ${group}`;
        }

        function clearTotals() {
            if (!previewTableBody) return;
            previewTableBody.querySelectorAll('.amount-cell, .group-total-cell, .total-revenues-cell, .gross-profit-cell, .total-selling-admin-cell, .ebitda-cell, .ebit-cell, .ebt-cell, .net-income-cell').forEach(cell => {
                cell.textContent = '-';
            });
        }

        function resetAdjustments() {
            adjustmentsItem = new Map();
            adjustmentsGroup = new Map();
            lastGroupTotalsFinal = new Map();
            pendingTransfer = null;
            selectionMode = false;
            if (selectedSourceRow) {
                selectedSourceRow.classList.remove('selected-source');
            }
            selectedSourceRow = null;
        }

        function mapToObject(map) {
            const obj = {};
            map.forEach((value, key) => {
                obj[key] = value;
            });
            return obj;
        }

        function objectToMap(obj) {
            const map = new Map();
            if (!obj || typeof obj !== 'object') return map;
            Object.keys(obj).forEach(key => {
                map.set(key, obj[key]);
            });
            return map;
        }

        function getPayloadKey(payload) {
            return JSON.stringify([
                payload.region || '',
                payload.transaction_month || '',
                payload.transaction_year || ''
            ]);
        }

        function persistAdjustments() {
            if (!lastPayloadKey) return;
            const data = {
                key: lastPayloadKey,
                adjustmentsItem: mapToObject(adjustmentsItem),
                adjustmentsGroup: mapToObject(adjustmentsGroup)
            };
            sessionStorage.setItem('manualAdjustmentData', JSON.stringify(data));
        }

        function loadAdjustmentsForPayload(payloadKey) {
            const raw = sessionStorage.getItem('manualAdjustmentData');
            if (!raw) return false;
            try {
                const data = JSON.parse(raw);
                if (!data || data.key !== payloadKey) return false;
                adjustmentsItem = objectToMap(data.adjustmentsItem);
                adjustmentsGroup = objectToMap(data.adjustmentsGroup);
                return true;
            } catch (err) {
                return false;
            }
        }

        function applyAdjustment(map, key, branch, delta) {
            const current = map.get(key) || { mlfsi: 0, jewelers: 0, total: 0 };
            current[branch] += delta;
            map.set(key, current);
        }

        function getRowBranchValue(row, branch) {
            if (row.classList.contains('preview-item-row')) {
                const key = getItemKey(row);
                const base = lastTotalsMap.get(key) || { mlfsi: 0, jewelers: 0, total: 0 };
                const adj = adjustmentsItem.get(key) || { mlfsi: 0, jewelers: 0, total: 0 };
                return (base[branch] || 0) + (adj[branch] || 0);
            }
            const group = getGroupKey(row);
            const totals = lastGroupTotalsFinal.get(group) || { mlfsi: 0, jewelers: 0, total: 0 };
            return totals[branch] || 0;
        }

        function updateAdjustmentAvailable(row) {
            if (!adjustmentAvailableLabel) return;
            const branch = adjustmentBranchSelect ? adjustmentBranchSelect.value : 'total';
            const val = getRowBranchValue(row, branch);
            adjustmentAvailableLabel.textContent = formatAmount(val);
        }

        function openAdjustmentModal(row) {
            if (!adjustmentModal) return;
            if (selectedSourceRow) {
                selectedSourceRow.classList.remove('selected-source');
            }
            selectedSourceRow = row;
            selectedSourceRow.classList.add('selected-source');
            if (adjustmentSourceLabel) {
                adjustmentSourceLabel.textContent = getRowLabel(row);
            }
            if (adjustmentAmountInput) {
                adjustmentAmountInput.value = '';
            }
            if (adjustmentBranchSelect) {
                adjustmentBranchSelect.value = 'total';
            }
            updateAdjustmentAvailable(row);
            adjustmentModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAdjustmentModal() {
            if (!adjustmentModal) return;
            adjustmentModal.style.display = 'none';
            document.body.style.overflow = 'auto';
            if (!selectionMode && selectedSourceRow) {
                selectedSourceRow.classList.remove('selected-source');
                selectedSourceRow = null;
            }
        }

        // Function to collect all current adjustment data for saving
    // Function to collect all current adjustment data for saving (including zeros and hidden rows)
function collectAdjustmentData() {
    const adjustments = [];
    
    if (!previewTableBody) return adjustments;

    const groupFooterOnlySorts = new Set(['6']);
    
    // Collect item-level rows (including hidden ones)
    previewTableBody.querySelectorAll('tr.preview-item-row').forEach(row => {
        const sort_order = row.getAttribute('data-sort') || '';
        const description = row.getAttribute('data-group') || '';

        // For sort_order 6, we save from group-footer instead of item rows
        if (groupFooterOnlySorts.has(sort_order)) {
            return;
        }

        let sub_order = row.getAttribute('data-sub') || '';
        let gl_description_comparative = row.getAttribute('data-comp') || '';
        
        // For sort_order 8, 11, set sub_order and gl_description_comparative to empty/null
        if (sort_order === '8' || sort_order === '11') {
            sub_order = '';
            gl_description_comparative = '';
        }
        
        // Get current displayed values (even for hidden rows)
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        
        if (mlfsiCell && jewelersCell) {
            const mlfsi = parseAmount(mlfsiCell.textContent);
            const jewelers = parseAmount(jewelersCell.textContent);
            
            // Include ALL rows regardless of amount (including zeros)
            adjustments.push({
                sort_order: parseInt(sort_order) || 0,
                description: description,
                sub_order: sub_order === '' ? null : (parseInt(sub_order) || 0),
                gl_description_comparative: gl_description_comparative === '' ? null : gl_description_comparative,
                mlfsi: mlfsi,
                jewelers: jewelers
            });
        }
    });

    // Collect group-footer totals for sort_order 6
    previewTableBody.querySelectorAll('tr.group-footer').forEach(row => {
        const sort_order = row.getAttribute('data-sort') || '';
        if (!groupFooterOnlySorts.has(sort_order)) return;

        const description = row.getAttribute('data-group') || '';
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        if (!mlfsiCell || !jewelersCell) return;

        const mlfsi = parseAmount(mlfsiCell.textContent);
        const jewelers = parseAmount(jewelersCell.textContent);

        adjustments.push({
            sort_order: parseInt(sort_order) || 0,
            description: description,
            sub_order: null,
            gl_description_comparative: null,
            mlfsi: mlfsi,
            jewelers: jewelers
        });
    });
    
    return adjustments;
}

        // Function to check if there are any changes from original
  // Function to check if there are any changes from original (including hidden rows)
function hasChangesFromOriginal() {
    if (!originalBaseTotals || originalBaseTotals.size === 0) return true; // If no baseline, assume changes needed
    
    // Check all current rows against original baseline
    if (!previewTableBody) return false;
    
    let hasChanges = false;
    
    previewTableBody.querySelectorAll('tr.preview-item-row').forEach(row => {
        // Include ALL rows including hidden ones
        const key = getItemKey(row);
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        
        if (mlfsiCell && jewelersCell) {
            const currentMlfsi = parseAmount(mlfsiCell.textContent);
            const currentJewelers = parseAmount(jewelersCell.textContent);
            
            const original = originalBaseTotals.get(key);
            if (original) {
                if (currentMlfsi !== original.mlfsi || currentJewelers !== original.jewelers) {
                    hasChanges = true;
                }
            } else {
                // New row that wasn't in original
                if (currentMlfsi !== 0 || currentJewelers !== 0) {
                    hasChanges = true;
                }
            }
        }
    });
    
    return hasChanges;
}

        // Function to update original base totals from current displayed values
// Function to update original base totals from current displayed values (including hidden rows)
function updateOriginalBaseTotals() {
    if (!previewTableBody) return;
    
    originalBaseTotals.clear();
    
    previewTableBody.querySelectorAll('tr.preview-item-row').forEach(row => {
        // Include ALL rows including hidden ones for sort_order 6,8,11
        const key = getItemKey(row);
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        
        if (mlfsiCell && jewelersCell) {
            const mlfsi = parseAmount(mlfsiCell.textContent);
            const jewelers = parseAmount(jewelersCell.textContent);
            originalBaseTotals.set(key, { mlfsi, jewelers, total: mlfsi + jewelers });
        }
    });
}

      function renderTotalsFromData() {
    if (!previewTableBody) return;

    const updateCell = (cell, val) => {
        if (!cell) return;
        cell.textContent = formatAmount(val);
        cell.style.color = val < 0 ? 'red' : '';
    };

    const groupTotals = new Map();
    previewTableBody.querySelectorAll('tr.preview-item-row').forEach(row => {
        const key = getItemKey(row);
        const base = lastTotalsMap.get(key) || { mlfsi: 0, jewelers: 0, total: 0 };
        const adj = adjustmentsItem.get(key) || { mlfsi: 0, jewelers: 0, total: 0 };
        const values = {
            mlfsi: (base.mlfsi || 0) + (adj.mlfsi || 0),
            jewelers: (base.jewelers || 0) + (adj.jewelers || 0),
            total: 0
        };
        values.total = values.mlfsi + values.jewelers + (adj.total || 0);

        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, values.mlfsi);
        updateCell(jewelersCell, values.jewelers);
        updateCell(totalCell, values.total);
        
        // Store current values in originalBaseTotals if not already there (for new rows)
        if (!originalBaseTotals.has(key)) {
            originalBaseTotals.set(key, { mlfsi: values.mlfsi, jewelers: values.jewelers, total: values.total });
        }

        const group = row.getAttribute('data-group') || '';
        if (group !== '') {
            const current = groupTotals.get(group) || { mlfsi: 0, jewelers: 0, total: 0 };
            current.mlfsi += values.mlfsi;
            current.jewelers += values.jewelers;
            current.total += values.total;
            groupTotals.set(group, current);
        }
    });

    const finalGroupTotals = new Map();
    previewTableBody.querySelectorAll('tr.group-footer').forEach(row => {
        const group = row.getAttribute('data-group') || '';
        const totals = groupTotals.get(group) || { mlfsi: 0, jewelers: 0, total: 0 };
        const adj = adjustmentsGroup.get(group) || { mlfsi: 0, jewelers: 0, total: 0 };
        const finalTotals = {
            mlfsi: totals.mlfsi + adj.mlfsi,
            jewelers: totals.jewelers + adj.jewelers,
            total: 0
        };
        finalTotals.total = finalTotals.mlfsi + finalTotals.jewelers + (adj.total || 0);
        finalGroupTotals.set(group, finalTotals);

        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, finalTotals.mlfsi);
        updateCell(jewelersCell, finalTotals.jewelers);
        updateCell(totalCell, finalTotals.total);
    });

    lastGroupTotalsFinal = finalGroupTotals;

    // Calculate summary rows (total revenues, gross profit, etc.)
    let totalRevenues = { mlfsi: 0, jewelers: 0, total: 0 };
    let costOfSales = { mlfsi: 0, jewelers: 0, total: 0 };
    let totalSellingAdmin = { mlfsi: 0, jewelers: 0, total: 0 };
    let totalDepAmort = { mlfsi: 0, jewelers: 0, total: 0 };
    let totalInterest = { mlfsi: 0, jewelers: 0, total: 0 };
    let totalTaxes = { mlfsi: 0, jewelers: 0, total: 0 };

    previewTableBody.querySelectorAll('tr.group-footer').forEach(row => {
        const sort = Number(row.getAttribute('data-sort') || '0');
        const group = row.getAttribute('data-group') || '';
        const totals = finalGroupTotals.get(group) || { mlfsi: 0, jewelers: 0, total: 0 };

        if (sort >= 1 && sort <= 20) {
            totalRevenues.mlfsi += totals.mlfsi;
            totalRevenues.jewelers += totals.jewelers;
            totalRevenues.total += totals.total;
        }

        if (sort === 21) {
            costOfSales.mlfsi += totals.mlfsi;
            costOfSales.jewelers += totals.jewelers;
            costOfSales.total += totals.total;
        }

        if (sort === 22 || sort === 23) {
            totalSellingAdmin.mlfsi += totals.mlfsi;
            totalSellingAdmin.jewelers += totals.jewelers;
            totalSellingAdmin.total += totals.total;
        }

        if (sort === 24) {
            totalDepAmort.mlfsi += totals.mlfsi;
            totalDepAmort.jewelers += totals.jewelers;
            totalDepAmort.total += totals.total;
        }

        if (sort === 25) {
            totalInterest.mlfsi += totals.mlfsi;
            totalInterest.jewelers += totals.jewelers;
            totalInterest.total += totals.total;
        }

        if (sort === 26) {
            totalTaxes.mlfsi += totals.mlfsi;
            totalTaxes.jewelers += totals.jewelers;
            totalTaxes.total += totals.total;
        }
    });

    previewTableBody.querySelectorAll('tr.total-revenues-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, totalRevenues.mlfsi);
        updateCell(jewelersCell, totalRevenues.jewelers);
        updateCell(totalCell, totalRevenues.total);
    });

    const grossProfit = {
        mlfsi: totalRevenues.mlfsi - costOfSales.mlfsi,
        jewelers: totalRevenues.jewelers - costOfSales.jewelers,
        total: totalRevenues.total - costOfSales.total
    };

    previewTableBody.querySelectorAll('tr.gross-profit-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, grossProfit.mlfsi);
        updateCell(jewelersCell, grossProfit.jewelers);
        updateCell(totalCell, grossProfit.total);
    });

    previewTableBody.querySelectorAll('tr.total-selling-admin-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, totalSellingAdmin.mlfsi);
        updateCell(jewelersCell, totalSellingAdmin.jewelers);
        updateCell(totalCell, totalSellingAdmin.total);
    });

    const ebitda = {
        mlfsi: grossProfit.mlfsi - totalSellingAdmin.mlfsi,
        jewelers: grossProfit.jewelers - totalSellingAdmin.jewelers,
        total: grossProfit.total - totalSellingAdmin.total
    };

    previewTableBody.querySelectorAll('tr.ebitda-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, ebitda.mlfsi);
        updateCell(jewelersCell, ebitda.jewelers);
        updateCell(totalCell, ebitda.total);
    });

    const ebit = {
        mlfsi: ebitda.mlfsi - totalDepAmort.mlfsi,
        jewelers: ebitda.jewelers - totalDepAmort.jewelers,
        total: ebitda.total - totalDepAmort.total
    };

    previewTableBody.querySelectorAll('tr.ebit-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, ebit.mlfsi);
        updateCell(jewelersCell, ebit.jewelers);
        updateCell(totalCell, ebit.total);
    });

    const ebt = {
        mlfsi: ebit.mlfsi - totalInterest.mlfsi,
        jewelers: ebit.jewelers - totalInterest.jewelers,
        total: ebit.total - totalInterest.total
    };

    previewTableBody.querySelectorAll('tr.ebt-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, ebt.mlfsi);
        updateCell(jewelersCell, ebt.jewelers);
        updateCell(totalCell, ebt.total);
    });

    const netIncome = {
        mlfsi: ebt.mlfsi - totalTaxes.mlfsi,
        jewelers: ebt.jewelers - totalTaxes.jewelers,
        total: ebt.total - totalTaxes.total
    };

    previewTableBody.querySelectorAll('tr.net-income-row').forEach(row => {
        const mlfsiCell = row.querySelector('[data-branch="mlfsi"]');
        const jewelersCell = row.querySelector('[data-branch="jewelers"]');
        const totalCell = row.querySelector('[data-branch="total"]');

        updateCell(mlfsiCell, netIncome.mlfsi);
        updateCell(jewelersCell, netIncome.jewelers);
        updateCell(totalCell, netIncome.total);
    });

    previewTableBody.querySelectorAll('tr.preview-item-row, tr.group-footer').forEach(row => {
        row.classList.add('selectable-row');
    });
}

        if (previewFilterForm) {
            previewFilterForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                setFilterStatus('Loading totals...');
                clearTotals();
                resetAdjustments();
                lastTotalsMap = new Map();

                const formData = new FormData(previewFilterForm);
                const payload = {
                    region: (formData.get('region') || '').toString().trim(),
                    transaction_month: (formData.get('transaction_month') || '').toString().trim(),
                    transaction_year: (formData.get('transaction_year') || '').toString().trim()
                };
                lastPayloadKey = getPayloadKey(payload);

                const regionSelect = previewFilterForm.querySelector('select[name="region"]');
                if (previewRegionLabel) {
                    const regionText = payload.region
                        ? (regionSelect?.options[regionSelect.selectedIndex]?.textContent || payload.region)
                        : 'All Regions';
                    previewRegionLabel.textContent = regionText;
                }

                try {
                    const response = await fetch('fetch_filtered_preview.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });

                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        throw new Error(data.error || 'Failed to load preview totals.');
                    }

                    const totalsMap = new Map();
                    (data.rows || []).forEach(row => {
                        const key = JSON.stringify([row.sort_order, row.sub_order, row.comp]);
                        totalsMap.set(key, {
                            mlfsi: Number(row.mlfsi) || 0,
                            jewelers: Number(row.jewelers) || 0,
                            total: Number(row.total) || 0
                        });
                    });

                    lastTotalsMap = totalsMap;
                    
                    // Store original base totals for change detection
                    originalBaseTotals.clear();
                    totalsMap.forEach((value, key) => {
                        originalBaseTotals.set(key, { ...value });
                    });
                    
                    const restored = loadAdjustmentsForPayload(lastPayloadKey);
                    renderTotalsFromData();
                    setFilterStatus(restored ? 'Totals updated (manual adjustments restored).' : 'Totals updated. Double click row for any amount adjustment.');
                } catch (err) {
                    setFilterStatus(err.message || 'Failed to load totals.', true);
                }
            });
        }
    
        (function () {
            var isCollapsed = false;
            var toggleBtn = document.getElementById('toggleCollapseBtn');
            if (!toggleBtn) return;

            function setCollapsed(collapsed) {
                var subRows = document.querySelectorAll('tr.preview-item-row');
                var spacerRows = document.querySelectorAll('tr.spacer-row');
                
                for (var i = 0; i < subRows.length; i++) {
                    var sortOrder = parseInt(subRows[i].getAttribute('data-sort') || '0', 10);
                    if (sortOrder >= 1 && sortOrder <= 20) {
                        subRows[i].style.display = collapsed ? 'none' : '';
                    }
                }
                for (var j = 0; j < spacerRows.length; j++) {
                    var so = parseInt(spacerRows[j].getAttribute('data-sort') || '0', 10);
                    if (so >= 1 && so <= 20) {
                        spacerRows[j].style.display = collapsed ? 'none' : '';
                    }
                }
                isCollapsed = collapsed;
                toggleBtn.innerHTML = collapsed
                    ? '<i class="fa-solid fa-expand"></i> Uncollapse View'
                    : '<i class="fa-solid fa-compress"></i> Collapse View';
            }

            toggleBtn.addEventListener('click', function () {
                setCollapsed(!isCollapsed);
            });
        })();

    </script>
</body>
</html>

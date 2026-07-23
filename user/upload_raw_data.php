<?php
session_start();
require_once __DIR__ . '/../config/config.php'; 
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Check if reset is requested
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['parsed_data']);
    unset($_SESSION['uploaded_headers']);
    unset($_SESSION['total_rows']);
    unset($_SESSION['file_name']);
    unset($_SESSION['success_message']);
    unset($_SESSION['error_message']);
    unset($_SESSION['summary_data']);
    unset($_SESSION['column_mapping']);
    header("Location: upload_raw_data.php");
    exit;
}

if (!isset($_SESSION['username'])) {
    $_SESSION['user_id'] = 1; 
    $_SESSION['id_number'] = '00000000';
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
$id_number = $_SESSION['id_number'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Expected headers in Row 1 (for display only, not used for mapping)
$expected_headers = [
    'Date', 'Zone', 'Region', 'Area', 'Branch', 
    'BranchID', 'Code', 'Description', 'Total Amount'
];

// Fixed column positions (0-indexed)
// A1=0, B1=1, C1=2, D1=3, E1=4, F1=5, G1=6, H1=7, I1=8
define('COL_DATE', 0);
define('COL_ZONE', 1);
define('COL_REGION', 2);
define('COL_AREA', 3);
define('COL_BRANCH', 4);
define('COL_BRANCH_ID', 5);
define('COL_CODE', 6);
define('COL_DESCRIPTION', 7);
define('COL_AMOUNT', 8);

// Function to get branch type from masterdata
function getBranchType(string $branch_id): string
{
    global $conn; // Use MySQLi connection
    static $branch_cache = [];

    if (empty($branch_id)) {
        return 'Unknown';
    }

    // Check cache first
    if (isset($branch_cache[$branch_id])) {
        return $branch_cache[$branch_id];
    }

    try {
        // Query the branch_profile table
        $query = "SELECT branch_type FROM masterdata.branch_profile WHERE branch_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            $branch_type = $row['branch_type'] ?? 'Unknown';
            $branch_cache[$branch_id] = $branch_type;
            return $branch_type;
        }

        $branch_cache[$branch_id] = 'Unknown';
        return 'Unknown';
    } catch (Exception $e) {
        error_log("Error fetching branch type for ID $branch_id: " . $e->getMessage());
        return 'Unknown';
    }
}

$parsed_data = [];
$uploaded_headers = [];
$error_message = '';
$success_message = '';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'raw'; // raw or summary
$summary_data = [];
$column_mapping = [];

// Pagination variables
$rows_per_page = 50;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page);

// Handle File Processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload'])) {
    $file = $_FILES['file_upload'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $file['tmp_name'];
        $file_name = $file['name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_exts = ['csv', 'xlsx', 'xls'];

        if (in_array($file_ext, $allowed_exts)) {
            try {
                $spreadsheet = IOFactory::load($file_tmp);
                $worksheet   = $spreadsheet->getActiveSheet();
                $rows        = $worksheet->toArray(null, true, true, true);

                if (!empty($rows)) {
                    $first_row = array_shift($rows);
                    $uploaded_headers = array_map('trim', array_filter($first_row));

                    // Clean up headers - remove empty values and normalize
                    $uploaded_headers = array_values(array_filter($uploaded_headers, function($val) {
                        return !empty(trim($val));
                    }));

                    // Fixed column mapping based on position
                    $region_idx = COL_REGION;  // Column C (index 2)
                    $area_idx = COL_AREA;       // Column D (index 3)
                    $code_idx = COL_CODE;       // Column G (index 6)
                    $amount_idx = COL_AMOUNT;   // Column I (index 8)
                    $branch_id_idx = COL_BRANCH_ID; // Column F (index 5)
                    
                    $column_mapping = [
                        'region' => $region_idx,
                        'area' => $area_idx,
                        'code' => $code_idx,
                        'amount' => $amount_idx,
                        'branch_id' => $branch_id_idx,
                        'date' => COL_DATE,
                        'zone' => COL_ZONE,
                        'branch' => COL_BRANCH,
                        'description' => COL_DESCRIPTION
                    ];
                    
                    error_log("Fixed Column Mapping: " . print_r($column_mapping, true));
                    error_log("Headers: " . print_r($uploaded_headers, true));

                    foreach ($rows as $row) {
                        if (!array_filter($row)) continue;
                        $row_data = [];
                        foreach ($first_row as $key => $header_name) {
                            $row_data[] = $row[$key] ?? '';
                        }
                        $parsed_data[] = $row_data;
                    }

                    // Build summary data from ALL rows - Group by Region, Area, Code, Branch Type
                    $summary_data = [];
                    foreach ($parsed_data as $row) {
                        // Get values using fixed column positions
                        $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
                        $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
                        $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
                        $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
                        
                        // Get branch type from masterdata
                        $branch_type = getBranchType($branch_id);
                        
                        // Handle amount - remove currency symbols and commas
                        $amount_str = isset($row[COL_AMOUNT]) ? trim($row[COL_AMOUNT]) : '0';
                        $amount_str = str_replace(['₱', 'PHP', '$', ',', ' '], '', $amount_str);
                        $amount = floatval($amount_str);

                        $key = $region . '|' . $area . '|' . $code . '|' . $branch_type;
                        
                        if (!isset($summary_data[$key])) {
                            $summary_data[$key] = [
                                'region' => $region,
                                'area' => $area,
                                'code' => $code,
                                'branch_type' => $branch_type,
                                'branch_total' => 0,
                                'branch_count' => 0,
                                'showroom_total' => 0,
                                'showroom_count' => 0,
                                'total_amount' => 0,
                                'total_count' => 0
                            ];
                        }
                        
                        // Add to appropriate totals based on branch type
                        if (strtolower($branch_type) === 'branch' || $branch_type === 'Branch') {
                            $summary_data[$key]['branch_total'] += $amount;
                            $summary_data[$key]['branch_count']++;
                        } elseif (strtolower($branch_type) === 'showroom' || $branch_type === 'Showroom') {
                            $summary_data[$key]['showroom_total'] += $amount;
                            $summary_data[$key]['showroom_count']++;
                        }
                        
                        // Always add to total
                        $summary_data[$key]['total_amount'] += $amount;
                        $summary_data[$key]['total_count']++;
                    }

                    // Sort summary by Region, then Area, then Code, then Branch Type
                    usort($summary_data, function($a, $b) {
                        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
                        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
                        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
                        return strcmp($a['branch_type'], $b['branch_type']);
                    });

                    $_SESSION['parsed_data'] = $parsed_data;
                    $_SESSION['uploaded_headers'] = $uploaded_headers;
                    $_SESSION['total_rows'] = count($parsed_data);
                    $_SESSION['file_name'] = $file_name;
                    $_SESSION['summary_data'] = $summary_data;
                    $_SESSION['column_mapping'] = $column_mapping;
                    
                    // Show debug info
                    $debug_info = "Fixed column mapping: Region=Column C (index 2), Area=Column D (index 3), Code=Column G (index 6), Amount=Column I (index 8), Branch ID=Column F (index 5)";
                    $_SESSION['success_message'] = "File <strong>" . htmlspecialchars($file_name) . "</strong> parsed successfully! Previewing " . count($parsed_data) . " rows. " . $debug_info;

                    $current_page = 1;
                    $success_message = $_SESSION['success_message'];
                } else {
                    $error_message = "The uploaded file appears to be empty.";
                    $_SESSION['error_message'] = $error_message;
                }
            } catch (Exception $e) {
                $error_message = "Error parsing file: " . $e->getMessage();
                $_SESSION['error_message'] = $error_message;
            }
        } else {
            $error_message = "Invalid file type. Please upload a .csv, .xlsx, or .xls file.";
            $_SESSION['error_message'] = $error_message;
        }
    } else {
        $error_message = "File upload failed with error code: " . $file['error'];
        $_SESSION['error_message'] = $error_message;
    }
}

// Load data from session if exists
if (isset($_SESSION['parsed_data']) && empty($parsed_data)) {
    $parsed_data = $_SESSION['parsed_data'];
    $uploaded_headers = $_SESSION['uploaded_headers'] ?? [];
    $success_message = $_SESSION['success_message'] ?? '';
    $error_message = $_SESSION['error_message'] ?? '';
    $summary_data = $_SESSION['summary_data'] ?? [];
    $column_mapping = $_SESSION['column_mapping'] ?? [];
}

// If summary_data has old structure (without branch keys), regenerate it
if (!empty($summary_data) && !isset($summary_data[0]['branch_total'])) {
    // Regenerate summary data with branch breakdown
    $new_summary_data = [];
    foreach ($parsed_data as $row) {
        $region = isset($row[COL_REGION]) ? trim($row[COL_REGION]) : 'Unknown Region';
        $area = isset($row[COL_AREA]) ? trim($row[COL_AREA]) : 'Unknown Area';
        $code = isset($row[COL_CODE]) ? trim($row[COL_CODE]) : 'Unknown Code';
        $branch_id = isset($row[COL_BRANCH_ID]) ? trim($row[COL_BRANCH_ID]) : '';
        $branch_type = getBranchType($branch_id);
        
        $amount_str = isset($row[COL_AMOUNT]) ? trim($row[COL_AMOUNT]) : '0';
        $amount_str = str_replace(['₱', 'PHP', '$', ',', ' '], '', $amount_str);
        $amount = floatval($amount_str);

        $key = $region . '|' . $area . '|' . $code . '|' . $branch_type;
        
        if (!isset($new_summary_data[$key])) {
            $new_summary_data[$key] = [
                'region' => $region,
                'area' => $area,
                'code' => $code,
                'branch_type' => $branch_type,
                'branch_total' => 0,
                'branch_count' => 0,
                'showroom_total' => 0,
                'showroom_count' => 0,
                'total_amount' => 0,
                'total_count' => 0
            ];
        }
        
        if (strtolower($branch_type) === 'branch' || $branch_type === 'Branch') {
            $new_summary_data[$key]['branch_total'] += $amount;
            $new_summary_data[$key]['branch_count']++;
        } elseif (strtolower($branch_type) === 'showroom' || $branch_type === 'Showroom') {
            $new_summary_data[$key]['showroom_total'] += $amount;
            $new_summary_data[$key]['showroom_count']++;
        }
        
        $new_summary_data[$key]['total_amount'] += $amount;
        $new_summary_data[$key]['total_count']++;
    }
    
    usort($new_summary_data, function($a, $b) {
        if ($a['region'] != $b['region']) return strcmp($a['region'], $b['region']);
        if ($a['area'] != $b['area']) return strcmp($a['area'], $b['area']);
        if ($a['code'] != $b['code']) return strcmp($a['code'], $b['code']);
        return strcmp($a['branch_type'], $b['branch_type']);
    });
    
    $summary_data = $new_summary_data;
    $_SESSION['summary_data'] = $summary_data;
}

// Calculate pagination for raw data
$total_rows = count($parsed_data);
$total_pages = ceil($total_rows / $rows_per_page);
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $rows_per_page;
$page_rows = array_slice($parsed_data, $offset, $rows_per_page);

// For summary view, we want to show all data without pagination
$summary_total_rows = count($summary_data);

// Clear session messages after displaying
if (isset($_SESSION['success_message']) && empty($_POST)) {
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message']) && empty($_POST)) {
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Data Upload</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/comparative.css?v=<?= time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        .upload-container {
            max-width: 2000px;
            margin: -1.5% auto;
            padding: 0 15px;
        }

        .drop-zone {
            border: 2px dashed #f63b3b;
            border-radius: 12px;
            padding: 10px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            position: relative;
        }
        .drop-zone:hover, .drop-zone.dragover {
            background-color: #eff6ff;
            border-color: #c50000;
        }
        .drop-zone i {
            font-size: 30px;
            color: #f63b3b;
            margin-bottom: 12px;
        }
        .drop-zone p {
            font-size: 16px;
            color: #475569;
            margin: 4px 0;
        }
        .drop-zone input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .btn-submit {
            background-color: #ff0000;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
        }
        .btn-submit:hover {
            background-color: #aa0000;
        }

        .btn-reset {
            background-color: #4c4c4c;
            color: white;
            padding: 9px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .btn-reset:hover {
            background-color: #000000;
            color: white;
            text-decoration: none;
        }

        .button-group {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: -.5%;
            flex-wrap: wrap;
        }

        .view-toggle {
            display: flex;
            gap: 10px;
            margin: 15px 0 20px 0;
            align-items: center;
            flex-wrap: wrap;
        }
        .view-toggle .toggle-label {
            font-weight: 600;
            color: #1e293b;
            margin-right: 10px;
        }
        .view-toggle .btn-toggle {
            padding: 8px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            color: #475569;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .view-toggle .btn-toggle.active {
            background: #ff0000;
            color: white;
            border-color: #ff0000;
        }
        .view-toggle .btn-toggle:hover:not(.active) {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .pagination-controls button {
            padding: 8px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            color: #1e293b;
        }
        .pagination-controls button:hover:not(:disabled) {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .pagination-controls button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .pagination-info {
            color: #64748b;
            font-size: 14px;
        }
        .pagination-info strong {
            color: #1e293b;
        }
        .page-indicator {
            font-weight: 600;
            color: #1e293b;
            padding: 0 15px;
            font-size: 14px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-danger { background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background-color: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        .table-wrapper {
            margin-top: 20px;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            max-height: 550px;
            overflow-y: auto;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        .preview-table th {
            background-color: #f1f5f9;
            color: #ffffff;
            padding: 12px;
            font-weight: 600;
            border-bottom: 2px solid #cbd5e1;
            white-space: nowrap;
        }
        .preview-table thead th {
            position: sticky;
            top: 0;
            background-color: #ff0000;
            z-index: 10;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        }
        .preview-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
            white-space: nowrap;
        }
        .preview-table tr:hover {
            background-color: #f8fafc;
        }
        .preview-table tr.subtotal-row {
            background-color: #fef2f2 !important;
            font-weight: 600;
        }
        .preview-table tr.subtotal-row td {
            border-top: 2px solid #f63b3b;
            border-bottom: 2px solid #f63b3b;
            background-color: #fff5f5;
        }
        .preview-table tr.region-separator td {
            border-top: 3px solid #1e293b;
            background-color: #f1f5f9;
        }
        .preview-table tr.grand-total-row td {
            border-top: 3px solid #1e293b;
            background-color: #e5e7eb !important;
            font-weight: 700;
        }

        .badge-header {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin-left: 5px;
        }
        .badge-valid { background-color: #dcfce7; color: #15803d; }
        .badge-invalid { background-color: #fee2e2; color: #b91c1c; }
        
        .row-number {
            color: #94a3b8;
            font-weight: bold;
            width: 50px;
            min-width: 50px;
        }

        .hidden {
            display: none !important;
        }

        .summary-stats {
            background: #f8fafc;
            padding: 12px 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 15px;
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .summary-stats .stat-item {
            font-size: 14px;
            color: #475569;
        }
        .summary-stats .stat-item strong {
            color: #1e293b;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        .empty-state i {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 15px;
        }
        .empty-state h3 {
            color: #1e293b;
            margin-bottom: 10px;
        }
        
        .column-mapping-info {
            background: #f0f7ff;
            border: 1px solid #b3d4fc;
            border-radius: 6px;
            padding: 10px 15px;
            margin: 10px 0;
            font-size: 13px;
            color: #1e293b;
        }
        .column-mapping-info code {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        .region-group-header {
            background-color: #f8fafc !important;
            border-top: 3px solid #1e293b;
            border-bottom: 1px solid #cbd5e1;
        }
        .region-group-header td {
            font-weight: 700;
            font-size: 15px;
            color: #0f172a !important;
            padding: 12px 15px !important;
            background-color: #f1f5f9;
        }
        .area-subtotal-row {
            background-color: #fef2f2 !important;
        }
        .area-subtotal-row td {
            font-weight: 700;
            color: #991b1b !important;
            border-top: 2px solid #f63b3b;
            border-bottom: 2px solid #f63b3b;
            background-color: #fff5f5;
        }
        .region-grand-total {
            background-color: #e5e7eb !important;
        }
        .region-grand-total td {
            font-weight: 800;
            font-size: 15px;
            color: #000 !important;
            border-top: 3px solid #1e293b;
            border-bottom: 3px solid #1e293b;
            background-color: #d1d5db;
        }
        .code-row td {
            padding: 8px 12px !important;
        }
        .code-row .code-value {
            font-weight: 600;
            color: #1e293b;
        }
        .code-row .amount-value {
            font-weight: 600;
            color: #0f172a;
            text-align: right;
        }
        .area-name-cell {
            font-weight: 600;
            color: #0f172a;
        }
        .region-name-cell {
            font-weight: 700;
            color: #0f172a;
        }
        .subtotal-amount {
            color: #991b1b !important;
            font-weight: 700;
        }

        .branch-type-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .branch-type-badge.branch {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .branch-type-badge.showroom {
            background-color: #fce7f3;
            color: #9d174d;
        }
        .branch-type-badge.unknown {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        .amount-column {
            text-align: right;
            font-weight: 600;
        }
        .count-column {
            text-align: center;
        }
        .subtotal-amount-branch {
            color: #1e40af !important;
        }
        .subtotal-amount-showroom {
            color: #9d174d !important;
        }
        /* Style for negative amounts */
        .negative-amount {
            color: #dc2626 !important;
        }
        .negative-amount-branch {
            color: #dc2626 !important;
        }
        .negative-amount-showroom {
            color: #dc2626 !important;
        }

        @media (max-width: 768px) {
            .pagination-container {
                flex-direction: column;
                align-items: stretch;
            }
            .pagination-controls {
                justify-content: center;
                flex-wrap: wrap;
            }
            .button-group {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-submit, .btn-reset {
                width: 100%;
                text-align: center;
            }
            .view-toggle {
                flex-wrap: wrap;
            }
            .summary-stats {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <a href="user_dashboard.php" style="font-size: 16px; text-decoration: none; font-weight: bold;">⬅ Back</a>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="upload-container">
                <h2>Upload Raw Data File</h2>

                <!-- <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 15px; margin-bottom: 15px;"> -->
                    <!-- <i class="fa-solid fa-info-circle" style="color: #15803d;"></i> -->
                    <!-- <strong style="color: #166534;">File Format Required:</strong> -->
                    <!-- <span style="color: #166534;">Column A=Date, B=Zone, C=Region, D=Area, E=Branch, F=BranchID, G=Code, H=Description, I=Amount</span> -->
                <!-- </div> -->

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger" id="errorAlert">
                        <i class="fa-solid fa-triangle-exclamation"></i> <?= $error_message ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success" id="successAlert">
                        <i class="fa-solid fa-circle-check"></i> <?= $success_message ?>
                    </div>
                <?php endif; ?>

                <!-- Drag & Drop Upload Form -->
                <form id="uploadForm" action="" method="POST" enctype="multipart/form-data">
                    <div class="drop-zone" id="dropZone">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p><strong>Drag & drop your CSV or Excel file here</strong></p>
                        <p style="font-size: 13px; color: #94a3b8;">or click to browse from your computer</p>
                        <span id="file-name-display" style="margin-top: 10px; display: block; font-weight: 600; color: #2563eb;"></span>
                        <input type="file" name="file_upload" id="fileInput" accept=".csv, .xlsx, .xls" required>
                    </div>
                    <div class="button-group">
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="fa-solid fa-magnifying-glass"></i> Preview Data
                        </button>
                        <a href="?reset=1" class="btn-reset" id="resetBtn">
                            <i class="fa-solid fa-rotate"></i> Reset
                        </a>
                    </div>
                </form>

                <!-- View Toggle Buttons -->
                <?php if (!empty($parsed_data)): ?>
                <div class="view-toggle">
                    <span class="toggle-label"><i class="fa-solid fa-eye"></i> View Mode:</span>
                    <a href="?view=raw&page=<?= $current_page ?>" class="btn-toggle <?= $view_mode === 'raw' ? 'active' : '' ?>">
                        <i class="fa-solid fa-table"></i> Raw Data
                    </a>
                    <a href="?view=summary" class="btn-toggle <?= $view_mode === 'summary' ? 'active' : '' ?>">
                        <i class="fa-solid fa-chart-pie"></i> Summary by Region-Area & Code
                    </a>
                </div>
                <?php endif; ?>

                <!-- Data Preview -->
                <div id="dataPreview" class="<?= empty($parsed_data) ? 'hidden' : '' ?>">
                    
                    <?php if ($view_mode === 'raw' && !empty($parsed_data)): ?>
                        <!-- RAW DATA VIEW -->
                        <div class="summary-stats">
                            <span class="stat-item"><i class="fa-solid fa-file-lines"></i> <strong><?= $total_rows ?></strong> total rows</span>
                            <span class="stat-item"><i class="fa-solid fa-columns"></i> <strong><?= count($uploaded_headers) ?></strong> columns</span>
                            <span class="stat-item"><i class="fa-solid fa-file"></i> File: <strong><?= htmlspecialchars($_SESSION['file_name'] ?? '') ?></strong></span>
                        </div>

                        <div class="table-wrapper">
                            <table class="preview-table">
                                <thead>
                                    <tr>
                                        <th class="row-number">#</th>
                                        <?php 
                                        foreach ($uploaded_headers as $index => $col_header): 
                                            $is_matched = in_array(trim($col_header), $expected_headers);
                                        ?>
                                            <th>
                                                <?= htmlspecialchars($col_header) ?>
                                                <span class="badge-header <?= $is_matched ? 'badge-valid' : 'badge-invalid' ?>">
                                                    <?= $is_matched ? 'Valid' : 'Unknown' ?>
                                                </span>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $row_counter = $offset + 1;
                                    foreach ($page_rows as $row): 
                                    ?>
                                        <tr>
                                            <td class="row-number"><?= $row_counter ?></td>
                                            <?php foreach ($row as $val): ?>
                                                <td><?= htmlspecialchars($val ?? '') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php 
                                        $row_counter++;
                                    endforeach; 
                                    ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Raw Pagination Controls -->
                        <div class="pagination-container">
                            <div class="pagination-info">
                                Showing <strong><?= $offset + 1 ?></strong> - 
                                <strong><?= min($offset + $rows_per_page, $total_rows) ?></strong> 
                                of <strong><?= $total_rows ?></strong> rows
                            </div>
                            
                            <div class="pagination-controls">
                                <button onclick="changePage(1, 'raw')" <?= $current_page <= 1 ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-angles-left"></i>
                                </button>
                                <button onclick="changePage(<?= $current_page - 1 ?>, 'raw')" <?= $current_page <= 1 ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-chevron-left"></i> Previous
                                </button>
                                <span class="page-indicator">Page <?= $current_page ?> of <?= $total_pages ?></span>
                                <button onclick="changePage(<?= $current_page + 1 ?>, 'raw')" <?= $current_page >= $total_pages ? 'disabled' : '' ?>>
                                    Next <i class="fa-solid fa-chevron-right"></i>
                                </button>
                                <button onclick="changePage(<?= $total_pages ?>, 'raw')" <?= $current_page >= $total_pages ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-angles-right"></i>
                                </button>
                            </div>
                        </div>

                    <?php elseif ($view_mode === 'summary' && !empty($summary_data)): ?>
                        <!-- SUMMARY VIEW - Grouped by Region, Area, Code with Branch Type Breakdown -->
                        <div class="summary-stats">
                            <span class="stat-item"><i class="fa-solid fa-layer-group"></i> <strong><?= count(array_unique(array_column($summary_data, 'region'))) ?></strong> regions</span>
                            <span class="stat-item"><i class="fa-solid fa-cubes"></i> <strong><?= count(array_unique(array_column($summary_data, 'area'))) ?></strong> areas</span>
                            <span class="stat-item"><i class="fa-solid fa-tag"></i> <strong><?= $summary_total_rows ?></strong> unique Region-Area-Code-BranchType combinations</span>
                            <span class="stat-item"><i class="fa-solid fa-file"></i> File: <strong><?= htmlspecialchars($_SESSION['file_name'] ?? '') ?></strong></span>
                        </div>

                        <!-- Show column mapping for debugging -->
                        <?php if (!empty($column_mapping)): ?>
                        <!-- <div class="column-mapping-info">
                            <i class="fa-solid fa-info-circle"></i> 
                            <strong>Fixed column mapping (positions):</strong> 
                            Region → <code>Column C (index 2)</code>, 
                            Area → <code>Column D (index 3)</code>, 
                            Code → <code>Column G (index 6)</code>, 
                            Amount → <code>Column I (index 8)</code>,
                            Branch ID → <code>Column F (index 5)</code>
                            <br>
                            <span style="font-size: 12px; color: #64748b;">
                                <i class="fa-solid fa-arrow-right"></i> Branch type is determined from masterdata.branch_profile using Branch ID
                            </span>
                        </div> -->
                        <?php endif; ?>

                        <div class="table-wrapper">
                            <table class="preview-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th style="width: 12%;">Region</th>
                                        <th style="width: 12%;">Area</th>
                                        <th style="width: 12%;">Code</th>
                                        <th style="width: 10%;">Branch Type</th>
                                        <th style="width: 13%; text-align: right;">Branch Amount</th>
                                        <th style="width: 8%; text-align: center;">Branch Count</th>
                                        <th style="width: 13%; text-align: right;">Showroom Amount</th>
                                        <th style="width: 8%; text-align: center;">Showroom Count</th>
                                        <th style="width: 12%; text-align: right;">Total Amount</th>
                                        <th style="width: 8%; text-align: center;">Total Rows</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (!empty($summary_data)):
                                        // Group data by Region, then Area, then Code
                                        $grouped_data = [];
                                        foreach ($summary_data as $item) {
                                            // Ensure all required keys exist
                                            $region = isset($item['region']) ? $item['region'] : 'Unknown Region';
                                            $area = isset($item['area']) ? $item['area'] : 'Unknown Area';
                                            $code = isset($item['code']) ? $item['code'] : 'Unknown Code';
                                            
                                            if (!isset($grouped_data[$region])) {
                                                $grouped_data[$region] = [];
                                            }
                                            if (!isset($grouped_data[$region][$area])) {
                                                $grouped_data[$region][$area] = [];
                                            }
                                            if (!isset($grouped_data[$region][$area][$code])) {
                                                $grouped_data[$region][$area][$code] = [];
                                            }
                                            $grouped_data[$region][$area][$code][] = $item;
                                        }

                                        // Sort regions
                                        ksort($grouped_data);
                                        
                                        $row_counter = 1;
                                        $grand_total_all = 0;
                                        $grand_branch_total = 0;
                                        $grand_showroom_total = 0;
                                        
                                        foreach ($grouped_data as $region => $areas):
                                            // Sort areas within region
                                            ksort($areas);
                                            
                                            // Calculate region totals
                                            $region_branch_total = 0;
                                            $region_showroom_total = 0;
                                            $region_total = 0;
                                            $region_branch_count = 0;
                                            $region_showroom_count = 0;
                                            $region_total_count = 0;
                                            
                                            foreach ($areas as $area_items) {
                                                foreach ($area_items as $code_items) {
                                                    foreach ($code_items as $item) {
                                                        $region_branch_total += isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                        $region_showroom_total += isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                        $region_total += isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                        $region_branch_count += isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                        $region_showroom_count += isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                        $region_total_count += isset($item['total_count']) ? $item['total_count'] : 0;
                                                    }
                                                }
                                            }
                                    ?>
                                            <!-- Region Header Row -->
                                            <tr class="region-group-header">
                                                <td colspan="11" style="font-size: 16px; color: #0f172a;">
                                                    <i class="fa-solid fa-folder-open"></i> 
                                                    <strong>REGION: <?= htmlspecialchars($region) ?></strong>
                                                    <span style="float: right; font-weight: 600; color: #1e293b;">
                                                        Branch: ₱<?= number_format($region_branch_total, 2) ?> (<?= $region_branch_count ?> rows) | 
                                                        Showroom: ₱<?= number_format($region_showroom_total, 2) ?> (<?= $region_showroom_count ?> rows) | 
                                                        Total: ₱<?= number_format($region_total, 2) ?> (<?= $region_total_count ?> rows)
                                                    </span>
                                                </td>
                                            </tr>
                                            
                                            <?php 
                                            foreach ($areas as $area => $code_items):
                                                // Calculate area totals
                                                $area_branch_total = 0;
                                                $area_showroom_total = 0;
                                                $area_total = 0;
                                                $area_branch_count = 0;
                                                $area_showroom_count = 0;
                                                $area_total_count = 0;
                                                
                                                foreach ($code_items as $items) {
                                                    foreach ($items as $item) {
                                                        $area_branch_total += isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                        $area_showroom_total += isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                        $area_total += isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                        $area_branch_count += isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                        $area_showroom_count += isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                        $area_total_count += isset($item['total_count']) ? $item['total_count'] : 0;
                                                    }
                                                }
                                            ?>
                                                <!-- Area Subtotal Row -->
                                                <tr class="area-subtotal-row">
                                                    <td colspan="11" style="font-weight: 700; color: #0f172a; padding: 8px 15px !important;">
                                                        <i class="fa-solid fa-caret-right"></i> 
                                                        <strong>AREA: <?= htmlspecialchars($area) ?></strong>
                                                        <span style="float: right; font-weight: 600; color: #1e293b;">
                                                            Branch: ₱<?= number_format($area_branch_total, 2) ?> (<?= $area_branch_count ?> rows) | 
                                                            Showroom: ₱<?= number_format($area_showroom_total, 2) ?> (<?= $area_showroom_count ?> rows) | 
                                                            Total: ₱<?= number_format($area_total, 2) ?> (<?= $area_total_count ?> rows)
                                                        </span>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Code Rows for this Area -->
                                                <?php 
                                                ksort($code_items);
                                                foreach ($code_items as $code => $items):
                                                    // Sort items by branch type
                                                    usort($items, function($a, $b) {
                                                        return strcmp($a['branch_type'], $b['branch_type']);
                                                    });
                                                    
                                                    foreach ($items as $idx => $item):
                                                        $branch_type_class = strtolower(isset($item['branch_type']) ? $item['branch_type'] : 'unknown');
                                                        $branch_total = isset($item['branch_total']) ? $item['branch_total'] : 0;
                                                        $branch_count = isset($item['branch_count']) ? $item['branch_count'] : 0;
                                                        $showroom_total = isset($item['showroom_total']) ? $item['showroom_total'] : 0;
                                                        $showroom_count = isset($item['showroom_count']) ? $item['showroom_count'] : 0;
                                                        $total_amount = isset($item['total_amount']) ? $item['total_amount'] : 0;
                                                        $total_count = isset($item['total_count']) ? $item['total_count'] : 0;
                                                        
                                                        // Check if amounts are negative for styling
                                                        $branch_negative = $branch_total < 0;
                                                        $showroom_negative = $showroom_total < 0;
                                                        $total_negative = $total_amount < 0;
                                                ?>
                                                    <tr class="code-row">
                                                        <td style="text-align: center; color: #94a3b8; font-weight: 400;"><?= $row_counter ?></td>
                                                        <td></td>
                                                        <td></td>
                                                        <td style="padding-left: 30px;">
                                                            <span class="code-value"><?= htmlspecialchars($code) ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="branch-type-badge <?= $branch_type_class ?>">
                                                                <?= htmlspecialchars($item['branch_type'] ?? 'Unknown') ?>
                                                            </span>
                                                        </td>
                                                        <td class="amount-column subtotal-amount-branch <?= $branch_negative ? 'negative-amount-branch' : '' ?>">
                                                            <?= ($branch_total != 0) ? '₱' . number_format($branch_total, 2) : '-' ?>
                                                        </td>
                                                        <td class="count-column">
                                                            <?= $branch_count > 0 ? $branch_count : '-' ?>
                                                        </td>
                                                        <td class="amount-column subtotal-amount-showroom <?= $showroom_negative ? 'negative-amount-showroom' : '' ?>">
                                                            <?= ($showroom_total != 0) ? '₱' . number_format($showroom_total, 2) : '-' ?>
                                                        </td>
                                                        <td class="count-column">
                                                            <?= $showroom_count > 0 ? $showroom_count : '-' ?>
                                                        </td>
                                                        <td class="amount-column <?= $total_negative ? 'negative-amount' : '' ?>" style="font-weight: 700; color: #0f172a;">
                                                            ₱<?= number_format($total_amount, 2) ?>
                                                        </td>
                                                        <td class="count-column" style="font-weight: 600;">
                                                            <?= $total_count ?>
                                                        </td>
                                                    </tr>
                                                    <?php 
                                                    $row_counter++;
                                                    endforeach; 
                                                endforeach; 
                                                ?>
                                            <?php 
                                            endforeach; // End areas
                                            
                                            // Region Grand Total
                                            ?>
                                            <tr class="region-grand-total">
                                                <td colspan="11" style="font-size: 15px; padding: 12px 15px !important;">
                                                    <i class="fa-solid fa-calculator"></i> 
                                                    <strong>GRAND TOTAL - <?= htmlspecialchars($region) ?></strong>
                                                    <span style="float: right; font-weight: 800; color: #000;">
                                                        Branch: ₱<?= number_format($region_branch_total, 2) ?> | 
                                                        Showroom: ₱<?= number_format($region_showroom_total, 2) ?> | 
                                                        Total: ₱<?= number_format($region_total, 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <!-- Spacer row -->
                                            <tr style="height: 5px;"><td colspan="11" style="padding: 0; border: none;"></td></tr>
                                            
                                            <?php 
                                            $grand_branch_total += $region_branch_total;
                                            $grand_showroom_total += $region_showroom_total;
                                            $grand_total_all += $region_total;
                                        endforeach; // End regions
                                        
                                        // Overall Grand Total (all regions)
                                        if (count($grouped_data) > 1):
                                        ?>
                                            <tr style="background-color: #0f172a !important;">
                                                <td colspan="11" style="padding: 15px 15px !important; color: white !important; font-size: 17px; font-weight: 800;">
                                                    <i class="fa-solid fa-crown"></i> 
                                                    OVERALL GRAND TOTAL (All Regions)
                                                    <span style="float: right; font-weight: 800; color: #fbbf24;">
                                                        Branch: ₱<?= number_format($grand_branch_total, 2) ?> | 
                                                        Showroom: ₱<?= number_format($grand_showroom_total, 2) ?> | 
                                                        Total: ₱<?= number_format($grand_total_all, 2) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php 
                                        endif;
                                    else: 
                                        ?>
                                        <tr>
                                            <td colspan="11" style="text-align: center; padding: 40px; color: #94a3b8;">
                                                <i class="fa-solid fa-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                                No summary data available.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 15px; padding: 10px 15px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <span style="color: #64748b; font-size: 13px;">
                                <i class="fa-solid fa-list"></i> Showing <strong><?= isset($row_counter) ? $row_counter - 1 : 0 ?></strong> unique code-branch type entries across <strong><?= isset($grouped_data) ? count($grouped_data) : 0 ?></strong> regions
                            </span>
                            <span style="color: #64748b; font-size: 13px;">
                                <i class="fa-solid fa-info-circle"></i> Each code is broken down by Branch Type (Branch vs Showroom)
                            </span>
                        </div>

                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>

<?php include '../footer.php'; ?>

<script>
    // Pagination functions
    function changePage(pageNumber, view) {
        if (pageNumber < 1) return;
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('page', pageNumber);
        urlParams.set('view', view || 'raw');
        window.location.search = urlParams.toString();
    }

    function changeSummaryPage(pageNumber) {
        if (pageNumber < 1) return;
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('spage', pageNumber);
        urlParams.set('view', 'summary');
        window.location.search = urlParams.toString();
    }

    // Drag and drop functionality
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileNameDisplay = document.getElementById('file-name-display');

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        }, false);
    });

    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length) {
            fileInput.files = files;
            updateFileName(files[0].name);
            setTimeout(() => {
                if (document.getElementById('uploadForm').checkValidity()) {
                    document.getElementById('uploadForm').submit();
                }
            }, 500);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            updateFileName(fileInput.files[0].name);
        }
    });

    function updateFileName(name) {
        fileNameDisplay.textContent = `Selected File: ${name}`;
        fileNameDisplay.style.color = '#16a34a';
    }

    document.getElementById('resetBtn').addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to clear all uploaded data?')) {
            e.preventDefault();
        }
    });

    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 500);
        });
    }, 10000);

    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Preview Data';
        }, 30000);
    });

    console.log('Raw Data Upload page loaded successfully');
</script>
</body>
</html>
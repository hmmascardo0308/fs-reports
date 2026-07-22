<?php
session_start();

$uploadMessage = '';
if (isset($_SESSION['upload_message'])) {
    $uploadMessage = $_SESSION['upload_message'];
    unset($_SESSION['upload_message']);
}

require_once __DIR__ . '/../config/config.php'; 
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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

// Import data to database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_data'])) {
    if (!isset($_SESSION['preview_data'])) {
        $_SESSION['upload_message'] = '<div class="error-message">No preview data available to import.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $preview_data = $_SESSION['preview_data'];
    $transaction_period = $preview_data['transaction_period']; // This is 'YYYY-MM' format
    
    // Parse year and month from transaction_period
    $period_parts = explode('-', $transaction_period);
    $transaction_year = $period_parts[0];   // e.g., '2026'
    $transaction_month = $period_parts[1];   // e.g., '03'
    
    // Format transaction_date as YYYY-MM-01 (e.g., 2026-03-01)
    $transaction_date = $transaction_year . '-' . $transaction_month . '-01';
    
    // Check if data for this month already exists
    $check_exists_sql = "SELECT id FROM fs_reports.past_transaction WHERE transaction_month = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_exists_sql);
    $check_stmt->bind_param("s", $transaction_date);
    $check_stmt->execute();
    $check_stmt->store_result();
    if ($check_stmt->num_rows > 0) {
        $check_stmt->close();
        $_SESSION['upload_message'] = '<div class="error-message">Data for ' . date('F Y', strtotime($transaction_date)) . ' already exists in the database. Duplicate uploads are not allowed.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    $check_stmt->close();

    $uploaded_by = $_SESSION['username'];
    date_default_timezone_set('Asia/Manila');
    $uploaded_date = date('Y-m-d H:i:s');
    
    $filepath = $preview_data['filepath'];
    $import_success = true;
    $import_count = 0;
    $error_messages = [];
    
    try {
        // Reload the spreadsheet to get data for import
        $spreadsheet = IOFactory::load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Region mapping (same as preview)
        $region_mapping = [
            'ALMASOR' => ['region' => 'Almasor Region', 'zone' => 'LZN'],
            'BAZAM' => ['region' => 'Bazam Region', 'zone' => 'LZN'],
            'BULACAN' => ['region' => 'Bulacan Region', 'zone' => 'LZN'],
            'CAMACAT' => ['region' => 'Camacat Region', 'zone' => 'LZN'],
            'ILOCABRA' => ['region' => 'Ilocabra Region', 'zone' => 'LZN'],
            'LAGUNA' => ['region' => 'Laguna Region', 'zone' => 'LZN'],
            'NEL' => ['region' => 'North Eastern Luzon Region', 'zone' => 'LZN'],
            'NOL' => ['region' => 'Northern Luzon Region', 'zone' => 'LZN'],
            'NWL' => ['region' => 'North Western Luzon Region', 'zone' => 'LZN'],
            'PAMPANGA' => ['region' => 'Pampanga Region', 'zone' => 'LZN'],
            'QUIVISAGAO' => ['region' => 'Quivisagao Region', 'zone' => 'LZN'],
            'SEL' => ['region' => 'South Eastern Luzon Region', 'zone' => 'LZN'],
            'SOL' => ['region' => 'Southern Luzon Region', 'zone' => 'LZN'],
            'SWL' => ['region' => 'South Western Luzon Region', 'zone' => 'LZN'],
            'TARPAN' => ['region' => 'TARPAN Luzon Region', 'zone' => 'LZN'],
            'NCR BATANES' => ['region' => 'NCR Batanes Region', 'zone' => 'NCR'],
            'NCR CENTRAL' => ['region' => 'NCR Central Region', 'zone' => 'NCR'],
            'NCR NORTH' => ['region' => 'NCR North Region', 'zone' => 'NCR'],
            'NCR RIZAL' => ['region' => 'NCR Rizal Region', 'zone' => 'NCR'],
            'BOHOL' => ['region' => 'Bohol Region', 'zone' => 'VIS'],
            'CEBU CENTRAL A' => ['region' => 'Cebu Central Region A', 'zone' => 'VIS'],
            'CEBU CENTRAL B' => ['region' => 'Cebu Central Region B', 'zone' => 'VIS'],
            'CEBU NORTH A' => ['region' => 'Cebu North A', 'zone' => 'VIS'],
            'CEBU NORTH B' => ['region' => 'Cebu North B', 'zone' => 'VIS'],
            'CEBU SOUTH' => ['region' => 'Cebu South Region', 'zone' => 'VIS'],
            'LEYTE A' => ['region' => 'Leyte A', 'zone' => 'VIS'],
            'LEYTE B' => ['region' => 'Leyte B', 'zone' => 'VIS'],
            'NEG.OCC A' => ['region' => 'Negros Occidental A', 'zone' => 'VIS'],
            'NEG.OCC B' => ['region' => 'Negros Occidental B', 'zone' => 'VIS'],
            'NEG.OR' => ['region' => 'Neg. Or-Siquijor Region', 'zone' => 'VIS'],
            'PALAWAN' => ['region' => 'Palawan Region', 'zone' => 'VIS'],
            'PANAY CENTRAL' => ['region' => 'Panay Central Region', 'zone' => 'VIS'],
            'PANAY NORTH' => ['region' => 'Panay North Region', 'zone' => 'VIS'],
            'PANAY SOUTH' => ['region' => 'Panay South Region', 'zone' => 'VIS'],
            'SAMAR' => ['region' => 'Samar Region', 'zone' => 'VIS'],
            'BUKIDNON' => ['region' => 'Bukidnon Region', 'zone' => 'MIN'],
            'CARAGA NORTE' => ['region' => 'CARAGA Norte Region', 'zone' => 'MIN'],
            'CARAGA SUR' => ['region' => 'CARAGA Sur Region', 'zone' => 'MIN'],
            'CDO' => ['region' => 'CDO', 'zone' => 'MIN'],
            'COTMAG' => ['region' => 'Cotabato Maguindanao Region', 'zone' => 'MIN'],
            'DACODA' => ['region' => 'Dacoda Region', 'zone' => 'MIN'],
            'DAVAO' => ['region' => 'Davao Region', 'zone' => 'MIN'],
            'LANAO' => ['region' => 'Lanao Region', 'zone' => 'MIN'],
            'SARGEN' => ['region' => 'Sargen Mindanao Region', 'zone' => 'MIN'],
            'SOCKS' => ['region' => 'Socsk Mindanao Region', 'zone' => 'MIN'],
            'ZAMBAS' => ['region' => 'Zambas Region', 'zone' => 'MIN'],
            'ZAMSIBUGAY' => ['region' => 'Zamsibugay Mindanao Region', 'zone' => 'MIN'],
            'ZAMSULTA' => ['region' => 'Zamsulta Region', 'zone' => 'MIN'],
            'ZANORTE' => ['region' => 'Zanorte Region', 'zone' => 'MIN'],
            'ZASURMIS' => ['region' => 'Zasurmis Region', 'zone' => 'MIN']
        ];
        
        function getMainZoneForImport(?string $zone): string {
            if (empty($zone)) return 'LNCR';
            if (in_array($zone, ['LZN', 'NCR'])) return 'LNCR';
            if (in_array($zone, ['VIS', 'MIN'])) return 'VISMIN';
            return 'LNCR';
        }
        
        // Get region headers from row 6
        $skip_columns = [19, 24, 42, 58];
        $region_headers = [];
        
        for ($col = 4; $col <= 59; $col++) {
            if (in_array($col, $skip_columns)) {
                continue;
            }
            
            $region_cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($col) . '6');
            $region_code = trim($region_cell->getValue());
            
            if (!empty($region_code)) {
                $region_info = $region_mapping[$region_code] ?? null;
                
                if ($region_info) {
                    $region_headers[$col] = [
                        'code' => $region_code,
                        'region' => $region_info['region'],
                        'zone' => $region_info['zone'],
                        'mainzone' => getMainZoneForImport($region_info['zone'])
                    ];
                } elseif ($col === 25) { // Column Y
                    $region_headers[$col] = [
                        'code' => $region_code,
                        'region' => 'LNCR Showroom',
                        'zone' => '',
                        'mainzone' => 'LNCR'
                    ];
                } elseif ($col === 59) { // Column BG
                    $region_headers[$col] = [
                        'code' => $region_code,
                        'region' => 'VISMIN Showroom',
                        'zone' => '',
                        'mainzone' => 'VISMIN'
                    ];
                }
            }
        }
        
        // Start transaction
        mysqli_begin_transaction($conn);
        
        $current_row = 8; // Data starts from row 8
        
        while (true) {
            $sort_order_cell = $worksheet->getCell('A' . $current_row);
            $sort_order = trim($sort_order_cell->getValue());
            
            if ($sort_order === '') {
                break;
            }
            
            $sub_order_cell = $worksheet->getCell('B' . $current_row);
            $sub_order = trim($sub_order_cell->getValue());
            
            // Get description and gl_description_comparative from database
            $gl_sql = "SELECT description, gl_description_comparative 
                       FROM fs_reports.gl_codes_past_tranx 
                       WHERE sort_order = ? AND sub_order = ?";
            $gl_stmt = $conn->prepare($gl_sql);
            if ($gl_stmt) {
                $gl_stmt->bind_param("ii", $sort_order, $sub_order);
                $gl_stmt->execute();
                $gl_result = $gl_stmt->get_result();
            } else {
                $gl_result = null;
            }
            
            $description = '';
            $gl_description_comparative = '';
            
            if ($gl_result && $gl_result->num_rows > 0) {
                $gl_row = $gl_result->fetch_assoc();
                $description = $gl_row['description'];
                $gl_description_comparative = $gl_row['gl_description_comparative'];
            } else {
                // If no matching GL code found, use values from file
                $description_cell = $worksheet->getCell('C' . $current_row);
                $description = trim($description_cell->getValue());
                $gl_description_comparative = $description;
            }
            
            // Process amounts for each region
            foreach ($region_headers as $col => $region_info) {
                $amount_cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $current_row);
                $raw_amount = $amount_cell->getValue();
                
                $amount = 0.00;
                if (is_numeric($raw_amount)) {
                    $amount = (float) $raw_amount;
                } elseif (empty($raw_amount)) {
                    $amount = 0.00;
                } else {
                    // Skip non-numeric values
                    continue;
                }
                
                // Insert into past_transaction table
                $insert_sql = "INSERT INTO fs_reports.past_transaction 
                              (sort_order, sub_order, description, gl_description_comparative, 
                               amount, region, mainzone, zone, transaction_month, transaction_year, uploaded_by, uploaded_date) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $insert_stmt = $conn->prepare($insert_sql);
                if ($insert_stmt) {
                    // Bind parameters - note: transaction_month is date, transaction_year is year
                    $insert_stmt->bind_param("iisssssssiss", 
                        $sort_order, 
                        $sub_order, 
                        $description, 
                        $gl_description_comparative,
                        $amount, 
                        $region_info['region'], 
                        $region_info['mainzone'], 
                        $region_info['zone'], 
                        $transaction_date,  // This is YYYY-MM-01 format
                        $transaction_year,   // This is just the year (e.g., 2026)
                        $uploaded_by, 
                        $uploaded_date
                    );
                    
                    if ($insert_stmt->execute()) {
                        $import_count++;
                    } else {
                        $import_success = false;
                        $error_messages[] = "Error inserting row $current_row: " . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                } else {
                    $import_success = false;
                    $error_messages[] = "Error preparing statement: " . $conn->error;
                }
            }
            
            $current_row++;
            if (isset($gl_stmt)) {
                $gl_stmt->close();
            }
        }
        
        if ($import_success) {
            mysqli_commit($conn);
            $_SESSION['upload_message'] = '<div class="success-message">✓ Successfully imported ' . $import_count . ' records for ' . date('F Y', strtotime($transaction_date)) . '</div>';
            
            // Clean up temp file after successful import
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            unset($_SESSION['preview_data']);
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            mysqli_rollback($conn);
            $error_msg = implode("<br>", $error_messages);
            $_SESSION['upload_message'] = '<div class="error-message">Import failed: ' . $error_msg . '</div>';
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['upload_message'] = '<div class="error-message">Error during import: ' . $e->getMessage() . '</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Fetch default GL codes for display reference
$default_gl_codes = [];
$fetch_gl_sql = "SELECT sort_order, description, sub_order, gl_description_comparative, gl_mapping 
                 FROM fs_reports.gl_codes_past_tranx 
                 ORDER BY sort_order ASC, sub_order ASC";
$fetch_gl_res = mysqli_query($conn, $fetch_gl_sql);
if ($fetch_gl_res) {
    while ($gl_row = mysqli_fetch_assoc($fetch_gl_res)) {
        $group_key = $gl_row['sort_order'] . '||' . $gl_row['description'];
        $default_gl_codes[$group_key][] = $gl_row;
    }
}

// Preview file 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview'])) {
    $transaction_period = $_POST['transaction_period'] ?? '';
    $file = $_FILES['excel_file'] ?? null;
    
    // Validate transaction period
    if (empty($transaction_period)) {
        $_SESSION['upload_message'] = '<div class="error-message">Please select a transaction period.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Parse the transaction period to get year and month
    $period_parts = explode('-', $transaction_period);
    if (count($period_parts) != 2) {
        $_SESSION['upload_message'] = '<div class="error-message">Invalid transaction period format.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $transaction_year = $period_parts[0];
    $transaction_month = $period_parts[1];
    
    // Validate file
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['upload_message'] = '<div class="error-message">Please select a valid Excel file.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $allowed_extensions = ['xlsx', 'xls', 'csv'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        $_SESSION['upload_message'] = '<div class="error-message">Invalid file type. Please upload Excel (.xlsx, .xls) or CSV files only.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Create temporary file for preview
    $temp_dir = '../uploads/temp/';
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    $temp_filename = 'temp_' . time() . '_' . basename($file['name']);
    $temp_filepath = $temp_dir . $temp_filename;
    
    if (move_uploaded_file($file['tmp_name'], $temp_filepath)) {
        try {
            // Store preview data in session
            $_SESSION['preview_data'] = [
                'filepath' => $temp_filepath,
                'filename' => $file['name'],
                'transaction_period' => $transaction_period,
                'transaction_month' => $transaction_month,
                'transaction_year' => $transaction_year
            ];
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?preview=1");
            exit;
            
        } catch (Exception $e) {
            $_SESSION['upload_message'] = '<div class="error-message">Error processing file: ' . $e->getMessage() . '</div>';
            if (file_exists($temp_filepath)) {
                unlink($temp_filepath);
            }
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    } else {
        $_SESSION['upload_message'] = '<div class="error-message">Failed to upload file for preview.</div>';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Cancel preview
if (isset($_GET['cancel_preview'])) {
    if (isset($_SESSION['preview_data']) && file_exists($_SESSION['preview_data']['filepath'])) {
        unlink($_SESSION['preview_data']['filepath']);
    }
    unset($_SESSION['preview_data']);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$username = $_SESSION['username'] ?? "unknown";
$id_number = $_SESSION['id_number'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Get current month and year for default values
$current_month = date('m');
$current_year = date('Y');
$current_period = $current_year . '-' . $current_month;
$months = [
    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
];

// Preview data
$preview_mode = isset($_GET['preview']) && isset($_SESSION['preview_data']);
$preview_data = null;
$preview_rows = [];
$summary_data = []; // Store summary data by sort_order and sub_order

if ($preview_mode) {
    $preview_data = $_SESSION['preview_data'];
    $filepath = $preview_data['filepath'];
    
    try {
        $spreadsheet = IOFactory::load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Region mapping for preview
        $region_mapping = [
            'ALMASOR' => ['region' => 'Almasor Region', 'zone' => 'LZN'],
            'BAZAM' => ['region' => 'Bazam Region', 'zone' => 'LZN'],
            'BULACAN' => ['region' => 'Bulacan Region', 'zone' => 'LZN'],
            'CAMACAT' => ['region' => 'Camacat Region', 'zone' => 'LZN'],
            'ILOCABRA' => ['region' => 'Ilocabra Region', 'zone' => 'LZN'],
            'LAGUNA' => ['region' => 'Laguna Region', 'zone' => 'LZN'],
            'NEL' => ['region' => 'North Eastern Luzon Region', 'zone' => 'LZN'],
            'NOL' => ['region' => 'Northern Luzon Region', 'zone' => 'LZN'],
            'NWL' => ['region' => 'North Western Luzon Region', 'zone' => 'LZN'],
            'PAMPANGA' => ['region' => 'Pampanga Region', 'zone' => 'LZN'],
            'QUIVISAGAO' => ['region' => 'Quivisagao Region', 'zone' => 'LZN'],
            'SEL' => ['region' => 'South Eastern Luzon Region', 'zone' => 'LZN'],
            'SOL' => ['region' => 'Southern Luzon Region', 'zone' => 'LZN'],
            'SWL' => ['region' => 'South Western Luzon Region', 'zone' => 'LZN'],
            'TARPAN' => ['region' => 'TARPAN Luzon Region', 'zone' => 'LZN'],
            'NCR BATANES' => ['region' => 'NCR Batanes Region', 'zone' => 'NCR'],
            'NCR CENTRAL' => ['region' => 'NCR Central Region', 'zone' => 'NCR'],
            'NCR NORTH' => ['region' => 'NCR North Region', 'zone' => 'NCR'],
            'NCR RIZAL' => ['region' => 'NCR Rizal Region', 'zone' => 'NCR'],
            'BOHOL' => ['region' => 'Bohol Region', 'zone' => 'VIS'],
            'CEBU CENTRAL A' => ['region' => 'Cebu Central Region A', 'zone' => 'VIS'],
            'CEBU CENTRAL B' => ['region' => 'Cebu Central Region B', 'zone' => 'VIS'],
            'CEBU NORTH A' => ['region' => 'Cebu North A', 'zone' => 'VIS'],
            'CEBU NORTH B' => ['region' => 'Cebu North B', 'zone' => 'VIS'],
            'CEBU SOUTH' => ['region' => 'Cebu South Region', 'zone' => 'VIS'],
            'LEYTE A' => ['region' => 'Leyte A', 'zone' => 'VIS'],
            'LEYTE B' => ['region' => 'Leyte B', 'zone' => 'VIS'],
            'NEG.OCC A' => ['region' => 'Negros Occidental A', 'zone' => 'VIS'],
            'NEG.OCC B' => ['region' => 'Negros Occidental B', 'zone' => 'VIS'],
            'NEG.OR' => ['region' => 'Neg. Or-Siquijor Region', 'zone' => 'VIS'],
            'PALAWAN' => ['region' => 'Palawan Region', 'zone' => 'VIS'],
            'PANAY CENTRAL' => ['region' => 'Panay Central Region', 'zone' => 'VIS'],
            'PANAY NORTH' => ['region' => 'Panay North Region', 'zone' => 'VIS'],
            'PANAY SOUTH' => ['region' => 'Panay South Region', 'zone' => 'VIS'],
            'SAMAR' => ['region' => 'Samar Region', 'zone' => 'VIS'],
            'BUKIDNON' => ['region' => 'Bukidnon Region', 'zone' => 'MIN'],
            'CARAGA NORTE' => ['region' => 'CARAGA Norte Region', 'zone' => 'MIN'],
            'CARAGA SUR' => ['region' => 'CARAGA Sur Region', 'zone' => 'MIN'],
            'CDO' => ['region' => 'CDO', 'zone' => 'MIN'],
            'COTMAG' => ['region' => 'Cotabato Maguindanao Region', 'zone' => 'MIN'],
            'DACODA' => ['region' => 'Dacoda Region', 'zone' => 'MIN'],
            'DAVAO' => ['region' => 'Davao Region', 'zone' => 'MIN'],
            'LANAO' => ['region' => 'Lanao Region', 'zone' => 'MIN'],
            'SARGEN' => ['region' => 'Sargen Mindanao Region', 'zone' => 'MIN'],
            'SOCKS' => ['region' => 'Socsk Mindanao Region', 'zone' => 'MIN'],
            'ZAMBAS' => ['region' => 'Zambas Region', 'zone' => 'MIN'],
            'ZAMSIBUGAY' => ['region' => 'Zamsibugay Mindanao Region', 'zone' => 'MIN'],
            'ZAMSULTA' => ['region' => 'Zamsulta Region', 'zone' => 'MIN'],
            'ZANORTE' => ['region' => 'Zanorte Region', 'zone' => 'MIN'],
            'ZASURMIS' => ['region' => 'Zasurmis Region', 'zone' => 'MIN']
        ];
        
        function getMainZonePreview(?string $zone): string {
            if (empty($zone)) return 'LNCR';
            if (in_array($zone, ['LZN', 'NCR'])) return 'LNCR';
            if (in_array($zone, ['VIS', 'MIN'])) return 'VISMIN';
            return 'LNCR';
        }
        
        // Get region headers from row 6 (columns D onwards, skipping S, X, AP)
        $skip_columns = [19, 24, 42, 58];
        $region_headers = [];
        
        for ($col = 4; $col <= 59; $col++) {
            if (in_array($col, $skip_columns)) {
                continue;
            }
            
            $region_cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($col) . '6');
            $region_code = trim($region_cell->getValue());
            
            if (!empty($region_code)) {
                $region_info = $region_mapping[$region_code] ?? null;
                
                if ($region_info) {
                    $region_headers[$col] = [
                        'code' => $region_code,
                        'region' => $region_info['region'],
                        'zone' => $region_info['zone'],
                        'mainzone' => getMainZonePreview($region_info['zone'])
                    ];
                } elseif ($col === 25) { // Column Y
                    $region_headers[$col] = [
                        'code' => $region_code,
                        'region' => 'LNCR Showroom',
                        'zone' => '',
                        'mainzone' => 'LNCR'
                    ];
                } elseif ($col === 59) { // Column BG
                    $region_headers[$col] = [
                        'code' => $region_code,
                        'region' => 'VISMIN Showroom',
                        'zone' => '',
                        'mainzone' => 'VISMIN'
                    ];
                }
            }
        }
        
        $current_row = 8; // Data starts from row 8
        $temp_rows = []; // Buffer to group data points by region
        $preview_rows = [];
        
        while (true) { // Loop through data rows
            $sort_order_cell = $worksheet->getCell('A' . $current_row);
            $sort_order = trim($sort_order_cell->getValue());
            
            if ($sort_order === '') {
                break;
            }
            
            $sub_order_cell = $worksheet->getCell('B' . $current_row);
            $sub_order = trim($sub_order_cell->getValue());

            // Get description and gl_description_comparative from fs_reports.gl_codes_past_tranx
            $gl_sql = "SELECT description, gl_description_comparative 
                       FROM fs_reports.gl_codes_past_tranx 
                       WHERE sort_order = ? AND sub_order = ?";
            $gl_stmt = $conn->prepare($gl_sql);
            if ($gl_stmt) {
                $gl_stmt->bind_param("ii", $sort_order, $sub_order);
                $gl_stmt->execute();
                $gl_result = $gl_stmt->get_result();
            } else {
                $gl_result = null;
            }

            $description = '';
            $gl_description_comparative = '';

            if ($gl_result && $gl_result->num_rows > 0) {
                $gl_row = $gl_result->fetch_assoc();
                $description = $gl_row['description'];
                $gl_description_comparative = $gl_row['gl_description_comparative'];
            } else {
                // If no matching GL code found, use the values from the file
                $description_cell = $worksheet->getCell('C' . $current_row);
                $description = trim($description_cell->getValue());
                $gl_description_comparative = $description;
            }
            
            // Process amounts for each region
            foreach ($region_headers as $col => $region_info) {
                $amount_cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($col) . $current_row);
                $raw_amount = $amount_cell->getValue();
                
                $amount_to_display = 0.00;
                if (is_numeric($raw_amount)) {
                    $amount_to_display = (float) $raw_amount;
                } elseif (empty($raw_amount)) {
                    $amount_to_display = 0.00; // Treat empty as 0
                } else {
                    // If it's not numeric and not empty, skip this entry (e.g., text in amount column)
                    continue;
                }

                // Always include the row if we reached here, as amount_to_display is now a valid number
                $temp_rows[$col][] = [
                        'sort_order' => $sort_order,
                        'sub_order' => $sub_order,
                        'description' => $description,
                        'gl_description' => $gl_description_comparative,
                        'amount' => $amount_to_display, // Keep as float for summary calculations
                        'amount_formatted' => number_format($amount_to_display, 2),
                        'region' => $region_info['region'],
                        'mainzone' => $region_info['mainzone'],
                        'zone' => $region_info['zone']
                    ];
            }
            
            $current_row++;
            if (isset($gl_stmt)) {
                $gl_stmt->close();
            }
        }

        // Flatten temp_rows into preview_rows, ordered region by region (by column index)
        foreach ($region_headers as $col => $region_info) {
            if (isset($temp_rows[$col])) {
                foreach ($temp_rows[$col] as $r_data) {
                    $preview_rows[] = $r_data;
                }
            }
        }
        
        // Build summary data: aggregate amounts by (sort_order, sub_order, gl_description, description) and zone/mainzone categories
        $summary_aggregator = [];
        foreach ($preview_rows as $row) {
            $key = $row['sort_order'] . '|' . $row['sub_order'] . '|' . $row['gl_description'] . '|' . $row['description'];
            if (!isset($summary_aggregator[$key])) {
                $summary_aggregator[$key] = [
                    'sort_order' => $row['sort_order'],
                    'sub_order' => $row['sub_order'],
                    'description' => $row['description'],
                    'gl_description' => $row['gl_description'],
                    'zone_LZN' => 0,
                    'zone_NCR' => 0,
                    'region_LNCR_Showroom' => 0,
                    'zone_VIS' => 0,
                    'zone_MIN' => 0,
                    'region_VISMIN_Showroom' => 0
                ];
            }
            
            // Determine which column to add the amount to based on zone and region
            $region_name = $row['region'];
            if ($region_name === 'LNCR Showroom') {
                $summary_aggregator[$key]['region_LNCR_Showroom'] += $row['amount'];
            } elseif ($region_name === 'VISMIN Showroom') {
                $summary_aggregator[$key]['region_VISMIN_Showroom'] += $row['amount'];
            } else {
                // Regular regions based on zone
                $zone = $row['zone'];
                if ($zone === 'LZN') {
                    $summary_aggregator[$key]['zone_LZN'] += $row['amount'];
                } elseif ($zone === 'NCR') {
                    $summary_aggregator[$key]['zone_NCR'] += $row['amount'];
                } elseif ($zone === 'VIS') {
                    $summary_aggregator[$key]['zone_VIS'] += $row['amount'];
                } elseif ($zone === 'MIN') {
                    $summary_aggregator[$key]['zone_MIN'] += $row['amount'];
                }
            }
        }
        
        // Convert aggregator to sorted array for summary table
        $summary_data = array_values($summary_aggregator);
        // Sort by sort_order and sub_order
        usort($summary_data, function($a, $b) {
            if ($a['sort_order'] == $b['sort_order']) {
                return $a['sub_order'] - $b['sub_order'];
            }
            return $a['sort_order'] - $b['sort_order'];
        });
        
    } catch (Exception $e) {
        $preview_mode = false;
        $_SESSION['upload_message'] = '<div class="error-message">Error loading preview: ' . $e->getMessage() . '</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Past Transaction</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/upload_previous.css?v=<?= time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Additional styles for the month picker */
        .month-picker-wrapper {
            position: relative;
            width: 100%;
        }
        
        input[type="month"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }
        
        input[type="month"]:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
    </style>
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
                <div class="upload-header">
                    <h3>Preview and Upload Previous Transaction Data</h3>
                </div>
                
                <?php if ($uploadMessage): ?>
                    <?php echo $uploadMessage; ?>
                <?php endif; ?>
                
                <?php if (!$preview_mode): ?>
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="preview" value="1">
                    <div class="inline-form">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Transaction Period</label>
                            <div class="month-picker-wrapper">
                                <input type="month" 
                                       name="transaction_period" 
                                       id="transaction_period" 
                                       value="<?php echo $current_period; ?>"
                                       required>
                            </div>
                        </div>
                        
                        <div class="file-group">
                            <label><i class="fas fa-upload"></i> Excel File</label>
                            <div class="file-input-wrapper">
                                <div class="file-input-label" onclick="document.getElementById('excel_file').click()">
                                    <i class="fas fa-file-excel"></i>
                                    <span>Choose File</span>
                                </div>
                                <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls,.csv" required style="display: none;" onchange="updateFileName(this)">
                            </div>
                        </div>
                        
                        <div class="button-group">
                            <button type="submit" class="upload-button" id="previewBtn">
                                <i class="fas fa-eye"></i> Preview Data
                            </button>
                        </div>
                        <div class="button-group">
                            <a href="past_transaction.php" class="upload-button" style="text-decoration: none; display: flex; align-items: center; justify-content: center;     background: linear-gradient(45deg, #7f7f7f, #1c1c1c);
">
                                <i class="fas fa-history" style="margin-right: 8px;"></i> View Past Transaction
                            </a>
                        </div>
                    </div>
                </form>

                <div class="reference-section" style="margin-top: 40px;">
                    <div class="summary-section-title">
                        <i class="fas fa-book"></i> GL Mapping Reference
                    </div>
                    <div style="margin-bottom: 10px; display: flex; justify-content: flex-end;">
                        <input type="text" id="refSearchInput" placeholder="Search reference..." style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 300px; font-size: 14px;">
                    </div>
                    <div class="preview-table-wrapper">
                        <table class="preview-table" id="referenceTable">
                            <thead>
                                    <th>Sort Order</th>
                                    <th>Description</th>
                                    <th>Sub Order</th>
                                    <th>GL Description Comparative</th>
                                    <th>GL Mapping</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($default_gl_codes)): ?>
                                    <?php foreach ($default_gl_codes as $group_key => $rows): 
                                        $parts = explode('||', $group_key);
                                        $sort_order = $parts[0] ?? '';
                                        $description = $parts[1] ?? '';
                                    ?>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <td></td>
                                                <td></td>
                                                <td style="text-align: center;"><?php echo htmlspecialchars($row['sub_order']); ?></td>
                                                <td><?php echo htmlspecialchars($row['gl_description_comparative']); ?></td>
                                                <td><?php echo htmlspecialchars($row['gl_mapping']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr style="background-color: #ffdcc5; font-weight: bold; border-top: 1px solid #dee2e6;">
                                            <td style="text-align: center;"><?php echo htmlspecialchars($sort_order); ?></td>
                                            <td style="text-align: center;"><?php echo htmlspecialchars($description); ?></td>
                                            <td colspan="3"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 20px; color: #999;">
                                            No mapping data found in gl_codes_past_tranx table.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="preview-container">
                    <div class="preview-header">
                        <div class="preview-title">
                            <i class="fas fa-table"></i> Data Preview (Read-Only)
                        </div>
                        <div class="preview-actions">
                            <form method="POST" id="importForm" style="display: inline;">
                                <input type="hidden" name="import_data" value="1">
                                <button type="button" class="btn-import" id="importBtn" onclick="showImportConfirmation()">
                                    <i class="fas fa-database"></i> Upload Data
                                </button>
                                  <a href="?cancel_preview=1" class="btn-cancel">
                                <i class="fas fa-times"></i> Close Preview
                            </a>
                            </form>
                          
                        </div>
                    </div>
                    
                    <!-- Import Confirmation Modal -->
                    <div id="importModal" class="modal">
                        <div class="modal-content">
                            <div class="modal-header">
                                <i class="fas fa-exclamation-triangle"></i> Confirm Import
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to import this data?</p>
                                <p><strong>Transaction Period:</strong> <?php 
                                    $period = $preview_data['transaction_period'];
                                    $period_parts = explode('-', $period);
                                    echo date('F Y', strtotime($period_parts[0] . '-' . $period_parts[1] . '-01'));
                                ?></p>
                                <p><strong>Records to import:</strong> <?php echo count($preview_rows); ?> transaction records</p>
                                <p style="color: #dc2626; margin-top: 10px;"><strong>Warning:</strong> This action cannot be undone. Please verify the data before proceeding.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
                                <button type="button" class="modal-btn modal-btn-confirm" onclick="confirmImport()">Confirm Import</button>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($preview_rows)): ?>
                    
                    <!-- Detailed Preview Table Section -->
                    <div class="summary-section-title">
                        <i class="fas fa-list-ul"></i> Detailed Transaction Preview
                    </div>
                    <div class="preview-table-wrapper">
                        <table class="preview-table">
                            <thead>
                                <tr>
                                    <th>Sort Order</th>
                                    <th>Sub Order</th>
                                    <th>Description</th>
                                    <th>GL Description</th>
                                    <th>Amount</th>
                                    <th>Region</th>
                                    <th>Main Zone</th>
                                    <th>Zone</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_rows as $row): ?>
                                <tr>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($row['sort_order']); ?></td>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($row['sub_order']); ?></td>
                                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                                    <td><?php echo htmlspecialchars($row['gl_description']); ?></td>
                                    <td style="text-align: right;" class="<?php echo $row['amount'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo htmlspecialchars($row['amount_formatted']); ?>
                                    </td>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($row['region']); ?></td>
                                    <td style="text-align: center;">
                                        <span class="badge <?php echo $row['mainzone'] == 'LNCR' ? 'badge-lncr' : 'badge-vismin'; ?>">
                                            <?php echo htmlspecialchars($row['mainzone']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($row['zone']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary Table Section -->
                    <div class="summary-section-title">
                        <i class="fas fa-chart-line"></i> Summary by GL Account
                    </div>
                    <div class="summary-table-wrapper">
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th>Sort Order</th>
                                    <th>Sub Order</th>
                                    <th>Description</th>
                                    <th>GL Description</th>
                                    <th>LZN</th>
                                    <th>NCR</th>
                                    <th>LNCR Jew</th>
                                    <th>VIS</th>
                                    <th>MIN</th>
                                    <th>VISMIN Jew</th>
                                    <th>LNCR</th>
                                    <th>LNCR ALL</th>
                                    <th>VISMIN</th>
                                    <th>VISMIN ALL</th>
                                    <th>MLFSI</th>
                                    <th>JEWELERS</th>
                                    <th>NATIONWIDE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary_data as $summary_row): 
                                    // Calculate the additional columns
                                    $lncr = $summary_row['zone_LZN'] + $summary_row['zone_NCR'];
                                    $lncr_all = $lncr + $summary_row['region_LNCR_Showroom'];
                                    $vismin = $summary_row['zone_VIS'] + $summary_row['zone_MIN'];
                                    $vismin_all = $vismin + $summary_row['region_VISMIN_Showroom'];
                                    $mlfsi = $summary_row['zone_LZN'] + $summary_row['zone_NCR'] + $summary_row['zone_VIS'] + $summary_row['zone_MIN'];
                                    $jewelers = $summary_row['region_LNCR_Showroom'] + $summary_row['region_VISMIN_Showroom'];
                                    $nationwide = $mlfsi + $jewelers;
                                ?>
                                <tr>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($summary_row['sort_order']); ?></td>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($summary_row['sub_order']); ?></td>
                                    <td><?php echo htmlspecialchars($summary_row['description']); ?></td>
                                    <td><?php echo htmlspecialchars($summary_row['gl_description']); ?></td>
                                    <td class="<?php echo $summary_row['zone_LZN'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($summary_row['zone_LZN'], 2); ?>
                                    </td>
                                    <td class="<?php echo $summary_row['zone_NCR'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($summary_row['zone_NCR'], 2); ?>
                                    </td>
                                    <td class="<?php echo $summary_row['region_LNCR_Showroom'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($summary_row['region_LNCR_Showroom'], 2); ?>
                                    </td>
                                    <td class="<?php echo $summary_row['zone_VIS'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($summary_row['zone_VIS'], 2); ?>
                                    </td>
                                    <td class="<?php echo $summary_row['zone_MIN'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($summary_row['zone_MIN'], 2); ?>
                                    </td>
                                    <td class="<?php echo $summary_row['region_VISMIN_Showroom'] < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($summary_row['region_VISMIN_Showroom'], 2); ?>
                                    </td>
                                    <td class="<?php echo $lncr < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($lncr, 2); ?>
                                    </td>
                                    <td class="<?php echo $lncr_all < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($lncr_all, 2); ?>
                                    </td>
                                    <td class="<?php echo $vismin < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($vismin, 2); ?>
                                    </td>
                                    <td class="<?php echo $vismin_all < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($vismin_all, 2); ?>
                                    </td>
                                    <td class="<?php echo $mlfsi < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($mlfsi, 2); ?>
                                    </td>
                                    <td class="<?php echo $jewelers < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($jewelers, 2); ?>
                                    </td>
                                    <td class="<?php echo $nationwide < 0 ? 'amount-negative' : 'amount-positive'; ?>">
                                        <?php echo number_format($nationwide, 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> No valid data found in the file. Please check the file format.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
        </div>
    </main>
    
    <script>
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : 'No file chosen';
            document.getElementById('file_name_display').textContent = fileName;
        }
        
        document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
            const period = document.getElementById('transaction_period').value;
            const file = document.getElementById('excel_file').files[0];
            
            if (!period) {
                e.preventDefault();
                alert('Please select a transaction period.');
                return false;
            }
            
            if (!file) {
                e.preventDefault();
                alert('Please select an Excel file to upload.');
                return false;
            }
            
            document.getElementById('previewBtn').disabled = true;
            document.getElementById('previewBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading Preview...';
        });

        // Search functionality for the reference table
        document.getElementById('refSearchInput')?.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#referenceTable tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
        
        // Import functionality
        let modal = document.getElementById('importModal');
        
        function showImportConfirmation() {
            if (modal) {
                modal.style.display = 'block';
            }
        }
        
        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
            }
        }
        
        function confirmImport() {
            closeModal();
            const importBtn = document.getElementById('importBtn');
            const importForm = document.getElementById('importForm');
            
            // Show loading state
            importBtn.classList.add('loading');
            importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
            importBtn.disabled = true;
            
            // Submit the form
            importForm.submit();
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            const messages = document.querySelectorAll('.error-message, .success-message');
            messages.forEach(function(message) {
                message.style.transition = 'opacity 0.5s';
                message.style.opacity = '0';
                setTimeout(function() {
                    message.remove();
                }, 500);
            });
        }, 5000);
    </script>

<?php include '../footer.php'; ?>

</body>
</html>
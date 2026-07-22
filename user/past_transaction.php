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

$username = $_SESSION['username'] ?? "unknown";
$id_number = $_SESSION['id_number'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

$selected_month = $_GET['transaction_month'] ?? '';

// Data lookup array for actual amounts
$past_amounts = [];
if ($selected_month) {
    $data_sql = "SELECT region, sort_order, sub_order, SUM(amount) as total 
                 FROM fs_reports.past_transaction 
                 WHERE DATE_FORMAT(transaction_month, '%Y-%m') = ? 
                 GROUP BY region, sort_order, sub_order";
    $stmt = $conn->prepare($data_sql);
    $stmt->bind_param("s", $selected_month);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $past_amounts[$r['sort_order']][$r['sub_order']][$r['region']] = (float)$r['total'];
    }
    $stmt->close();
}

// Fetch data from gl_codes_past_tranx
$sql = "SELECT sort_order, description, sub_order, gl_description_comparative, gl_mapping 
        FROM gl_codes_past_tranx 
        ORDER BY sort_order ASC, sub_order ASC";
$result = $conn->query($sql);

$totalRecords = 0;
$groupedTransactions = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $totalRecords++;
        $key = $row['sort_order'] . '||' . $row['description'];
        $groupedTransactions[$key][] = $row;
    }
}

// Fetch distinct regions from the transaction table to create dynamic columns
$regions_query = "SELECT DISTINCT region, zone FROM fs_reports.past_transaction WHERE region != ''";
$regions_result = $conn->query($regions_query);
$regions_by_zone = [
    'LZN' => [],
    'NCR' => [],
    'VIS' => [],
    'MIN' => []
];
$showrooms = [
    'LNCR Showroom' => false,
    'VISMIN Showroom' => false
];

if ($regions_result) {
    while ($r = $regions_result->fetch_assoc()) {
        $reg = $r['region'];
        $zn = $r['zone'];
        
        if ($reg == 'LNCR Showroom') {
            $showrooms['LNCR Showroom'] = true;
        } elseif ($reg == 'VISMIN Showroom') {
            $showrooms['VISMIN Showroom'] = true;
        } elseif (isset($regions_by_zone[$zn])) {
            $regions_by_zone[$zn][] = $reg;
        }
    }
}
// Sort regions alphabetically within their zones
foreach ($regions_by_zone as $zn => &$regs) {
    sort($regs);
}

// Calculate total dynamic columns for colspan logic
$total_dynamic_cols = count($regions_by_zone['LZN']) + 1 
                    + count($regions_by_zone['NCR']) + 1 
                    + ($showrooms['LNCR Showroom'] ? 1 : 0)
                    + count($regions_by_zone['VIS']) + 1 
                    + count($regions_by_zone['MIN']) + 1
                    + ($showrooms['VISMIN Showroom'] ? 1 : 0)
                    + 7; // LNCR, ALL LNCR, VISMIN, ALL VISMIN, MLFSI, JEWELERS, NATIONWIDE

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Previous Transaction</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/past_transaction.css?v=<?= time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="upload_previous.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <h1 class="section-title">Previous Transactions</h1>

            <!-- Filter Section -->
            <div class="filter-container" style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px;">
                <form method="GET" action="" style="display: flex; align-items: flex-end; gap: 15px;">
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 13px; font-weight: 600; color: #4a5568;">Transaction Month</label>
                        <input type="month" name="transaction_month" value="<?= htmlspecialchars($selected_month) ?>" style="padding: 8px 12px; border: 1px solid #cbd5e0; border-radius: 6px; min-width: 200px;" required>
                    </div>

                    <button type="submit" style="padding: 9px 20px; background: linear-gradient(45deg, #ff524c, #8e0005); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>

                    <a href="past_transaction.php" style="padding: 6px 20px; background: linear-gradient(45deg, #a6a6a6, #535353); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none;"><i class="fa-solid fa-rotate"></i> Clear</a>

                    <button type="button" id="toggleCollapseBtn" style="padding: 9px 20px; background: linear-gradient(45deg, #4fb7cf, #004d62); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-compress"></i> Collapse View
                    </button>
                    
                    <button type="button" id="exportPastBtn" style="padding: 9px 20px; background: linear-gradient(45deg, #00c31d, #075220); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-file-excel"></i> Export File
                    </button>

                     <button type="button" id="exportZoneTotalsBtn" style="padding: 9px 20px; background: linear-gradient(45deg, #00c31d, #075220); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-file-excel"></i> Export File (Zone Totals)
                    </button>


                </form>
            </div>

            <div class="preview-actions">
                <div class="stats-badge">
                    <!-- <i class="fas fa-database"></i> Total Records: <?php echo $totalRecords; ?> -->
                </div>
            </div>
            <div class="table-container">
                <?php if (!empty($groupedTransactions)): ?>
                    <table class="data-table">
                        <?php
                        // Initialize running totals for Revenue (sort_order 1-20)
                        $revenue_overall_totals = [
                            'regions' => [],
                            'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
                            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0,
                            'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
                        ];
                        $cost_overall_totals = [
                            'regions' => [],
                            'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
                            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0,
                            'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
                        ];
                        $selling_admin_overall_totals = [
                            'regions' => [],
                            'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
                            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0,
                            'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
                        ];
                        $operating_overall_totals = [
                            'regions' => [],
                            'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
                            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0,
                            'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
                        ];
                        $interest_overall_totals = [
                            'regions' => [],
                            'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
                            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0,
                            'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
                        ];
                        $tax_overall_totals = [
                            'regions' => [],
                            'lzn' => 0, 'ncr' => 0, 'lncr_sh' => 0, 'vis' => 0, 'min' => 0, 'vismin_sh' => 0,
                            'lncr' => 0, 'all_lncr' => 0, 'vismin' => 0, 'all_vismin' => 0,
                            'mlfsi' => 0, 'jewelers' => 0, 'nationwide' => 0
                        ];
                        ?>
                        <thead>
                            <tr>
                                <th colspan="3"></th>
                                <th colspan="22" style="background-color: #ae0000">LUZON & NCR</th>
                                <th colspan="34" style="background-color: #630000">VISAYAS & MINDANAO</th>
                                <th colspan="7">TOTALS</th>
                            </tr>
                            <tr>
                                <th>Sort Order</th>
                                <th>Description</th>
                                <th>GL Description Comparative</th>
                                
                                <!-- LZN Section -->
                                <?php foreach ($regions_by_zone['LZN'] as $r): ?><th><?= htmlspecialchars($r) ?></th><?php endforeach; ?>
                                <th style="background-color: #ff8b8b; color: black;">LUZON</th>
                                
                                <!-- NCR Section -->
                                <?php foreach ($regions_by_zone['NCR'] as $r): ?><th><?= htmlspecialchars($r) ?></th><?php endforeach; ?>
                                <th style="background-color: #ff8b8b; color: black;">NCR</th>
                                
                                <!-- Showroom LNCR -->
                                <?php if($showrooms['LNCR Showroom']): ?>
                                    <th style="background-color: #ff8b8b; color: black;">LNCR Showroom</th>
                                <?php endif; ?>
                                
                                <!-- VIS Section -->
                                <?php foreach ($regions_by_zone['VIS'] as $r): ?><th><?= htmlspecialchars($r) ?></th><?php endforeach; ?>
                                <th style="background-color: #ff8b8b; color: black;">VISAYAS</th>
                                
                                <!-- MIN Section -->
                                <?php foreach ($regions_by_zone['MIN'] as $r): ?><th><?= htmlspecialchars($r) ?></th><?php endforeach; ?>
                                <th style="background-color: #ff8b8b; color: black;">MINDANAO</th>
                                
                                <!-- Showroom VISMIN -->
                                <?php if($showrooms['VISMIN Showroom']): ?>
                                    <th style="background-color: #ff8b8b; color: black;">VISMIN Showroom</th>
                                <?php endif; ?>

                                <!-- Calculated Columns -->
                                <th style="background-color: #ffa570; color: black;">LNCR</th>
                                <th style="background-color: #ffa570; color: black;">ALL LNCR</th>
                                <th style="background-color: #ffa570; color: black;">VISMIN</th>
                                <th style="background-color: #ffa570; color: black;">ALL VISMIN</th>
                                <th style="background-color: #ffa570; color: black;">MLFSI</th>
                                <th style="background-color: #ffa570; color: black;">JEWELERS</th>
                                <th style="background-color: #ffa570; color: black;">NATIONWIDE</th>

                                <!-- <th>GL Mapping</th> -->
                            </tr>
                        </thead>
                        <tbody>

                        <tr class="spacer-row" data-sort-order="<?= (int)$sort_order ?>">
                                    <td colspan="<?= 3 + $total_dynamic_cols ?>" style="height: 10px; background-color: #e6e6e6; border-top: 1px solid #979797;"></td>
                                </tr>

                        <tr class="spacer-row" data-sort-order="<?= (int)$sort_order ?>">
                                    <td colspan="<?= 3 + $total_dynamic_cols ?>" style="height: 10px; background-color: #f2b48f; border-top: 1px solid #979797; text-align: left; font-weight: bold;">REVENUES</td>
                                </tr>


                            <?php foreach ($groupedTransactions as $group_key => $rows): 
                                $parts = explode('||', $group_key);
                                $sort_order = $parts[0] ?? '';
                                $description = $parts[1] ?? '';
                                $hide_sub_rows = in_array((int)$sort_order, [6, 8, 11], true);

                                // Initialize group totals
                                $group_totals = [
                                    'regions' => [],
                                    'lzn' => 0,
                                    'ncr' => 0,
                                    'lncr_sh' => 0,
                                    'vis' => 0,
                                    'min' => 0,
                                    'vismin_sh' => 0,
                                    'lncr' => 0,
                                    'all_lncr' => 0,
                                    'vismin' => 0,
                                    'all_vismin' => 0,
                                    'mlfsi' => 0,
                                    'jewelers' => 0,
                                    'nationwide' => 0
                                ];
                            ?>
                                <?php foreach ($rows as $transaction): 
                                    $s = $transaction['sort_order'];
                                    $o = $transaction['sub_order'];
                                    $lncr_sh_total = 0;
                                    $vismin_sh_total = 0;
                                    $group_sign = ((int)$s === 15 && (int)$o === 2) ? -1 : 1;
                                ?>
                                    <?php ob_start(); ?>
                                    <tr class="sub-order-row" data-sort-order="<?= (int)$s ?>">
                                        <td></td>
                                        <td></td>
                                        <td style="text-align: left;"><?php echo htmlspecialchars($transaction['gl_description_comparative']); ?></td>
                                        
                                        <!-- LZN Regions & LUZON Total -->
                                        <?php 
                                        $lzn_total = 0;
                                        foreach ($regions_by_zone['LZN'] as $r): 
                                            $val = $past_amounts[$s][$o][$r] ?? 0;
                                            $lzn_total += $val;
                                            $group_totals['regions'][$r] = ($group_totals['regions'][$r] ?? 0) + ($group_sign * $val);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php $group_totals['lzn'] += $group_sign * $lzn_total; ?>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($lzn_total, 2) ?></td>
                                        
                                        <!-- NCR Regions & NCR Total -->
                                        <?php 
                                        $ncr_total = 0;
                                        foreach ($regions_by_zone['NCR'] as $r): 
                                            $val = $past_amounts[$s][$o][$r] ?? 0;
                                            $ncr_total += $val;
                                            $group_totals['regions'][$r] = ($group_totals['regions'][$r] ?? 0) + ($group_sign * $val);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php $group_totals['ncr'] += $group_sign * $ncr_total; ?>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($ncr_total, 2) ?></td>
                                        
                                        <!-- Showroom LNCR -->
                                        <?php if($showrooms['LNCR Showroom']): 
                                            $lncr_sh_total = $past_amounts[$s][$o]['LNCR Showroom'] ?? 0;
                                            $group_totals['lncr_sh'] += $group_sign * $lncr_sh_total;
                                        ?>
                                            <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($lncr_sh_total, 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <!-- VIS Regions & VISAYAS Total -->
                                        <?php 
                                        $vis_total = 0;
                                        foreach ($regions_by_zone['VIS'] as $r): 
                                            $val = $past_amounts[$s][$o][$r] ?? 0;
                                            $vis_total += $val;
                                            $group_totals['regions'][$r] = ($group_totals['regions'][$r] ?? 0) + ($group_sign * $val);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php $group_totals['vis'] += $group_sign * $vis_total; ?>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($vis_total, 2) ?></td>
                                        
                                        <!-- MIN Regions & MINDANAO Total -->
                                        <?php 
                                        $min_total = 0;
                                        foreach ($regions_by_zone['MIN'] as $r): 
                                            $val = $past_amounts[$s][$o][$r] ?? 0;
                                            $min_total += $val;
                                            $group_totals['regions'][$r] = ($group_totals['regions'][$r] ?? 0) + ($group_sign * $val);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php $group_totals['min'] += $group_sign * $min_total; ?>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($min_total, 2) ?></td>
                                        
                                        <!-- Showroom VISMIN -->
                                        <?php if($showrooms['VISMIN Showroom']): 
                                            $vismin_sh_total = $past_amounts[$s][$o]['VISMIN Showroom'] ?? 0;
                                            $group_totals['vismin_sh'] += $group_sign * $vismin_sh_total;
                                        ?>
                                            <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($vismin_sh_total, 2) ?></td>
                                        <?php endif; ?>

                                        <?php
                                        $lncr_total = $lzn_total + $ncr_total;
                                        $all_lncr_total = $lncr_total + $lncr_sh_total;
                                        $vismin_total = $vis_total + $min_total;
                                        $all_vismin_total = $vismin_total + $vismin_sh_total;
                                        $mlfsi_total = $lncr_total + $vismin_total;
                                        $jewelers_total = $lncr_sh_total + $vismin_sh_total;
                                        $nationwide_total = $mlfsi_total + $jewelers_total;

                                        $group_totals['lncr'] += $group_sign * $lncr_total;
                                        $group_totals['all_lncr'] += $group_sign * $all_lncr_total;
                                        $group_totals['vismin'] += $group_sign * $vismin_total;
                                        $group_totals['all_vismin'] += $group_sign * $all_vismin_total;
                                        $group_totals['mlfsi'] += $group_sign * $mlfsi_total;
                                        $group_totals['jewelers'] += $group_sign * $jewelers_total;
                                        $group_totals['nationwide'] += $group_sign * $nationwide_total;
                                        ?>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($lncr_total, 2) ?></td>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($all_lncr_total, 2) ?></td>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($vismin_total, 2) ?></td>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($all_vismin_total, 2) ?></td>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($mlfsi_total, 2) ?></td>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($jewelers_total, 2) ?></td>
                                        <td style="text-align: right; font-weight: bold; background-color: #fffaf0;"><?= number_format($nationwide_total, 2) ?></td>

                                        <!-- <td style="text-align: left;"><?php echo htmlspecialchars($transaction['gl_mapping']); ?></td> -->
                                    </tr>
                                    <?php 
                                        $row_html = ob_get_clean();
                                        if (!$hide_sub_rows) {
                                            echo $row_html;
                                        }
                                    ?>
                                <?php endforeach; ?>

                                <!-- category row total -->
                                <?php if (!in_array((int)$sort_order, [24, 25, 26], true)): ?>
                                <tr class="category-total-row" data-sort-order="<?= (int)$sort_order ?>" style="background-color: #ffdcc5; font-weight: bold; border-top: 1px solid #dee2e6;">
                                    <td style="text-align: center;"><?php echo htmlspecialchars($sort_order); ?></td>
                                    <td style="text-align: center;"><?php echo htmlspecialchars($description); ?></td>
                                    <td></td>
                                    
                                    <!-- LZN Subtotals -->
                                    <?php foreach ($regions_by_zone['LZN'] as $r): ?>
                                        <td style="text-align: right;"><?= number_format($group_totals['regions'][$r] ?? 0, 2) ?></td>
                                    <?php endforeach; ?>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['lzn'], 2) ?></td>
                                    
                                    <!-- NCR Subtotals -->
                                    <?php foreach ($regions_by_zone['NCR'] as $r): ?>
                                        <td style="text-align: right;"><?= number_format($group_totals['regions'][$r] ?? 0, 2) ?></td>
                                    <?php endforeach; ?>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['ncr'], 2) ?></td>
                                    
                                    <?php if($showrooms['LNCR Showroom']): ?>
                                        <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['lncr_sh'], 2) ?></td>
                                    <?php endif; ?>
                                    
                                    <!-- VIS Subtotals -->
                                    <?php foreach ($regions_by_zone['VIS'] as $r): ?>
                                        <td style="text-align: right;"><?= number_format($group_totals['regions'][$r] ?? 0, 2) ?></td>
                                    <?php endforeach; ?>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['vis'], 2) ?></td>
                                    
                                    <!-- MIN Subtotals -->
                                    <?php foreach ($regions_by_zone['MIN'] as $r): ?>
                                        <td style="text-align: right;"><?= number_format($group_totals['regions'][$r] ?? 0, 2) ?></td>
                                    <?php endforeach; ?>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['min'], 2) ?></td>
                                    
                                    <?php if($showrooms['VISMIN Showroom']): ?>
                                        <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['vismin_sh'], 2) ?></td>
                                    <?php endif; ?>

                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['lncr'], 2) ?></td>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['all_lncr'], 2) ?></td>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['vismin'], 2) ?></td>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['all_vismin'], 2) ?></td>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['mlfsi'], 2) ?></td>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['jewelers'], 2) ?></td>
                                    <td style="text-align: right; background-color: #ffdcc5;"><?= number_format($group_totals['nationwide'], 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <!-- Spacer Row after each category -->
                                <tr class="spacer-row" data-sort-order="<?= (int)$sort_order ?>">
                                    <td colspan="<?= 3 + $total_dynamic_cols ?>" style="height: 10px; background-color: #e6e6e6; border-top: 1px solid #979797;"></td>
                                </tr>

                                <?php
                                // Accumulate into Revenue Totals if sort_order is 1-20
                                if ((int)$sort_order >= 1 && (int)$sort_order <= 20) {
                                    foreach ($group_totals['regions'] as $reg_name => $val) {
                                        $revenue_overall_totals['regions'][$reg_name] = ($revenue_overall_totals['regions'][$reg_name] ?? 0) + $val;
                                    }
                                    $revenue_overall_totals['lzn'] += $group_totals['lzn'];
                                    $revenue_overall_totals['ncr'] += $group_totals['ncr'];
                                    $revenue_overall_totals['lncr_sh'] += $group_totals['lncr_sh'];
                                    $revenue_overall_totals['vis'] += $group_totals['vis'];
                                    $revenue_overall_totals['min'] += $group_totals['min'];
                                    $revenue_overall_totals['vismin_sh'] += $group_totals['vismin_sh'];
                                    $revenue_overall_totals['lncr'] += $group_totals['lncr'];
                                    $revenue_overall_totals['all_lncr'] += $group_totals['all_lncr'];
                                    $revenue_overall_totals['vismin'] += $group_totals['vismin'];
                                    $revenue_overall_totals['all_vismin'] += $group_totals['all_vismin'];
                                    $revenue_overall_totals['mlfsi'] += $group_totals['mlfsi'];
                                    $revenue_overall_totals['jewelers'] += $group_totals['jewelers'];
                                    $revenue_overall_totals['nationwide'] += $group_totals['nationwide'];
                                }

                                // Accumulate into Cost of Sales Totals if sort_order is 21
                                if ((int)$sort_order == 21) {
                                    foreach ($group_totals['regions'] as $reg_name => $val) {
                                        $cost_overall_totals['regions'][$reg_name] = ($cost_overall_totals['regions'][$reg_name] ?? 0) + $val;
                                    }
                                    $cost_overall_totals['lzn'] += $group_totals['lzn'];
                                    $cost_overall_totals['ncr'] += $group_totals['ncr'];
                                    $cost_overall_totals['lncr_sh'] += $group_totals['lncr_sh'];
                                    $cost_overall_totals['vis'] += $group_totals['vis'];
                                    $cost_overall_totals['min'] += $group_totals['min'];
                                    $cost_overall_totals['vismin_sh'] += $group_totals['vismin_sh'];
                                    $cost_overall_totals['lncr'] += $group_totals['lncr'];
                                    $cost_overall_totals['all_lncr'] += $group_totals['all_lncr'];
                                    $cost_overall_totals['vismin'] += $group_totals['vismin'];
                                    $cost_overall_totals['all_vismin'] += $group_totals['all_vismin'];
                                    $cost_overall_totals['mlfsi'] += $group_totals['mlfsi'];
                                    $cost_overall_totals['jewelers'] += $group_totals['jewelers'];
                                    $cost_overall_totals['nationwide'] += $group_totals['nationwide'];
                                }

                                // Accumulate into Selling & Admin Totals if sort_order is 22-23
                                if ((int)$sort_order == 22 || (int)$sort_order == 23) {
                                    foreach ($group_totals['regions'] as $reg_name => $val) {
                                        $selling_admin_overall_totals['regions'][$reg_name] = ($selling_admin_overall_totals['regions'][$reg_name] ?? 0) + $val;
                                    }
                                    $selling_admin_overall_totals['lzn'] += $group_totals['lzn'];
                                    $selling_admin_overall_totals['ncr'] += $group_totals['ncr'];
                                    $selling_admin_overall_totals['lncr_sh'] += $group_totals['lncr_sh'];
                                    $selling_admin_overall_totals['vis'] += $group_totals['vis'];
                                    $selling_admin_overall_totals['min'] += $group_totals['min'];
                                    $selling_admin_overall_totals['vismin_sh'] += $group_totals['vismin_sh'];
                                    $selling_admin_overall_totals['lncr'] += $group_totals['lncr'];
                                    $selling_admin_overall_totals['all_lncr'] += $group_totals['all_lncr'];
                                    $selling_admin_overall_totals['vismin'] += $group_totals['vismin'];
                                    $selling_admin_overall_totals['all_vismin'] += $group_totals['all_vismin'];
                                    $selling_admin_overall_totals['mlfsi'] += $group_totals['mlfsi'];
                                    $selling_admin_overall_totals['jewelers'] += $group_totals['jewelers'];
                                    $selling_admin_overall_totals['nationwide'] += $group_totals['nationwide'];
                                }

                                // Accumulate into Operating (Depreciation) Totals if sort_order is 24
                                if ((int)$sort_order == 24) {
                                    foreach ($group_totals['regions'] as $reg_name => $val) {
                                        $operating_overall_totals['regions'][$reg_name] = ($operating_overall_totals['regions'][$reg_name] ?? 0) + $val;
                                    }
                                    $operating_overall_totals['lzn'] += $group_totals['lzn'];
                                    $operating_overall_totals['ncr'] += $group_totals['ncr'];
                                    $operating_overall_totals['lncr_sh'] += $group_totals['lncr_sh'];
                                    $operating_overall_totals['vis'] += $group_totals['vis'];
                                    $operating_overall_totals['min'] += $group_totals['min'];
                                    $operating_overall_totals['vismin_sh'] += $group_totals['vismin_sh'];
                                    $operating_overall_totals['lncr'] += $group_totals['lncr'];
                                    $operating_overall_totals['all_lncr'] += $group_totals['all_lncr'];
                                    $operating_overall_totals['vismin'] += $group_totals['vismin'];
                                    $operating_overall_totals['all_vismin'] += $group_totals['all_vismin'];
                                    $operating_overall_totals['mlfsi'] += $group_totals['mlfsi'];
                                    $operating_overall_totals['jewelers'] += $group_totals['jewelers'];
                                    $operating_overall_totals['nationwide'] += $group_totals['nationwide'];
                                }

                                // Accumulate into Interest Totals if sort_order is 25
                                if ((int)$sort_order == 25) {
                                    foreach ($group_totals['regions'] as $reg_name => $val) {
                                        $interest_overall_totals['regions'][$reg_name] = ($interest_overall_totals['regions'][$reg_name] ?? 0) + $val;
                                    }
                                    $interest_overall_totals['lzn'] += $group_totals['lzn'];
                                    $interest_overall_totals['ncr'] += $group_totals['ncr'];
                                    $interest_overall_totals['lncr_sh'] += $group_totals['lncr_sh'];
                                    $interest_overall_totals['vis'] += $group_totals['vis'];
                                    $interest_overall_totals['min'] += $group_totals['min'];
                                    $interest_overall_totals['vismin_sh'] += $group_totals['vismin_sh'];
                                    $interest_overall_totals['lncr'] += $group_totals['lncr'];
                                    $interest_overall_totals['all_lncr'] += $group_totals['all_lncr'];
                                    $interest_overall_totals['vismin'] += $group_totals['vismin'];
                                    $interest_overall_totals['all_vismin'] += $group_totals['all_vismin'];
                                    $interest_overall_totals['mlfsi'] += $group_totals['mlfsi'];
                                    $interest_overall_totals['jewelers'] += $group_totals['jewelers'];
                                    $interest_overall_totals['nationwide'] += $group_totals['nationwide'];
                                }

                                // Accumulate into Tax Totals if sort_order is 26
                                if ((int)$sort_order == 26) {
                                    foreach ($group_totals['regions'] as $reg_name => $val) {
                                        $tax_overall_totals['regions'][$reg_name] = ($tax_overall_totals['regions'][$reg_name] ?? 0) + $val;
                                    }
                                    $tax_overall_totals['lzn'] += $group_totals['lzn'];
                                    $tax_overall_totals['ncr'] += $group_totals['ncr'];
                                    $tax_overall_totals['lncr_sh'] += $group_totals['lncr_sh'];
                                    $tax_overall_totals['vis'] += $group_totals['vis'];
                                    $tax_overall_totals['min'] += $group_totals['min'];
                                    $tax_overall_totals['vismin_sh'] += $group_totals['vismin_sh'];
                                    $tax_overall_totals['lncr'] += $group_totals['lncr'];
                                    $tax_overall_totals['all_lncr'] += $group_totals['all_lncr'];
                                    $tax_overall_totals['vismin'] += $group_totals['vismin'];
                                    $tax_overall_totals['all_vismin'] += $group_totals['all_vismin'];
                                    $tax_overall_totals['mlfsi'] += $group_totals['mlfsi'];
                                    $tax_overall_totals['jewelers'] += $group_totals['jewelers'];
                                    $tax_overall_totals['nationwide'] += $group_totals['nationwide'];
                                }

                                // Insert TOTAL REVENUES row after sort_order 20
                                if ((int)$sort_order == 20): ?>
                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">TOTAL REVENUES</td>
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['lzn'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['ncr'], 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['lncr_sh'], 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['vis'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['min'], 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['vismin_sh'], 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['nationwide'], 2) ?></td>
                                    </tr>
                                    <!-- Spacer Row after TOTAL REVENUES -->
                                    <tr class="spacer-row">
                                        <td colspan="<?= 3 + $total_dynamic_cols ?>" style="height: 10px; background-color: #e6e6e6; border: none;"></td>
                                    </tr>

                                    <tr class="spacer-row">
                                        <td colspan="<?= 3 + $total_dynamic_cols ?>" style="text-align: left; font-weight: bold; background-color: #f2b48f;">COST OF SALES/SERVICE</td>
                                    </tr>
                                <?php endif; ?>

                                <?php
                                // Insert GROSS PROFIT row after sort_order 21
                                if ((int)$sort_order == 21): ?>
                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">GROSS PROFIT</td>
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format(($revenue_overall_totals['regions'][$r] ?? 0) - ($group_totals['regions'][$r] ?? 0), 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['lzn'] - $group_totals['lzn'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format(($revenue_overall_totals['regions'][$r] ?? 0) - ($group_totals['regions'][$r] ?? 0), 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['ncr'] - $group_totals['ncr'], 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['lncr_sh'] - $group_totals['lncr_sh'], 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format(($revenue_overall_totals['regions'][$r] ?? 0) - ($group_totals['regions'][$r] ?? 0), 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['vis'] - $group_totals['vis'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format(($revenue_overall_totals['regions'][$r] ?? 0) - ($group_totals['regions'][$r] ?? 0), 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['min'] - $group_totals['min'], 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): ?>
                                            <td style="text-align: right;"><?= number_format($revenue_overall_totals['vismin_sh'] - $group_totals['vismin_sh'], 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['lncr'] - $group_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['all_lncr'] - $group_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['vismin'] - $group_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['all_vismin'] - $group_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['mlfsi'] - $group_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['jewelers'] - $group_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($revenue_overall_totals['nationwide'] - $group_totals['nationwide'], 2) ?></td>
                                    </tr>
                                    <!-- Spacer Row after GROSS PROFIT -->
                                    <tr class="spacer-row">
                                        <td colspan="<?= 3 + $total_dynamic_cols ?>" style="height: 10px; background-color: #e6e6e6; border: none;"></td>
                                    </tr>

                                     <tr class="spacer-row">
                                        <td colspan="<?= 3 + $total_dynamic_cols ?>" style="text-align: left; font-weight: bold; background-color: #f2b48f;">SELLING & ADMIN EXPENSES</td>
                                    </tr>
                                <?php endif; ?>

                                <?php
                                // Insert TOTAL SELLING AND ADMIN EXPENSES row after sort_order 23
                                if ((int)$sort_order == 23): ?>
                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">TOTAL SELLING AND ADMIN EXPENSES</td>
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['lzn'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['ncr'], 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): ?>
                                            <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['lncr_sh'], 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['vis'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): ?>
                                            <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['regions'][$r] ?? 0, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['min'], 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): ?>
                                            <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['vismin_sh'], 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format($selling_admin_overall_totals['nationwide'], 2) ?></td>
                                    </tr>
                                    <!-- Spacer Row after TOTAL SELLING AND ADMIN EXPENSES -->
                                    <tr class="spacer-row">
                                        <td colspan="<?= 3 + $total_dynamic_cols ?>" style="height: 10px; background-color: #e6e6e6; border: none;"></td>
                                    </tr>

                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">EBITDA</td> <!--EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT -->
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): 
                                            $val = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['lzn'] - $cost_overall_totals['lzn']) - $selling_admin_overall_totals['lzn'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): 
                                            $val = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['ncr'] - $cost_overall_totals['ncr']) - $selling_admin_overall_totals['ncr'], 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): 
                                            $val = ($revenue_overall_totals['regions']['LNCR Showroom'] ?? 0) - ($cost_overall_totals['regions']['LNCR Showroom'] ?? 0) - ($selling_admin_overall_totals['regions']['LNCR Showroom'] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): 
                                            $val = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['vis'] - $cost_overall_totals['vis']) - $selling_admin_overall_totals['vis'], 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): 
                                            $val = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['min'] - $cost_overall_totals['min']) - $selling_admin_overall_totals['min'], 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): 
                                            $val = ($revenue_overall_totals['regions']['VISMIN Showroom'] ?? 0) - ($cost_overall_totals['regions']['VISMIN Showroom'] ?? 0) - ($selling_admin_overall_totals['regions']['VISMIN Showroom'] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['lncr'] - $cost_overall_totals['lncr']) - $selling_admin_overall_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_lncr'] - $cost_overall_totals['all_lncr']) - $selling_admin_overall_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['vismin'] - $cost_overall_totals['vismin']) - $selling_admin_overall_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_vismin'] - $cost_overall_totals['all_vismin']) - $selling_admin_overall_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['mlfsi'] - $cost_overall_totals['mlfsi']) - $selling_admin_overall_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['jewelers'] - $cost_overall_totals['jewelers']) - $selling_admin_overall_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['nationwide'] - $cost_overall_totals['nationwide']) - $selling_admin_overall_totals['nationwide'], 2) ?></td>
                                    </tr>
                                 
                                <?php endif; ?>

                                <?php
                                // Insert EARNINGS BEFORE TAXES row after sort_order 25
                                if ((int)$sort_order == 25): ?>
                                
                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">EBT</td> <!--EARNINGS BEFORE TAXES -->
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_lzn = ($revenue_overall_totals['lzn'] - $cost_overall_totals['lzn']) - $selling_admin_overall_totals['lzn'];
                                            $ebit_lzn = $ebitda_lzn - $operating_overall_totals['lzn'];
                                            $val_lzn = $ebit_lzn - $interest_overall_totals['lzn'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_lzn, 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_ncr = ($revenue_overall_totals['ncr'] - $cost_overall_totals['ncr']) - $selling_admin_overall_totals['ncr'];
                                            $ebit_ncr = $ebitda_ncr - $operating_overall_totals['ncr'];
                                            $val_ncr = $ebit_ncr - $interest_overall_totals['ncr'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_ncr, 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): 
                                            $ebitda_sh = ($revenue_overall_totals['lncr_sh'] - $cost_overall_totals['lncr_sh']) - $selling_admin_overall_totals['lncr_sh'];
                                            $ebit_sh = $ebitda_sh - $operating_overall_totals['lncr_sh'];
                                            $val_sh = $ebit_sh - $interest_overall_totals['lncr_sh'];
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val_sh, 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_vis = ($revenue_overall_totals['vis'] - $cost_overall_totals['vis']) - $selling_admin_overall_totals['vis'];
                                            $ebit_vis = $ebitda_vis - $operating_overall_totals['vis'];
                                            $val_vis = $ebit_vis - $interest_overall_totals['vis'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_vis, 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_min = ($revenue_overall_totals['min'] - $cost_overall_totals['min']) - $selling_admin_overall_totals['min'];
                                            $ebit_min = $ebitda_min - $operating_overall_totals['min'];
                                            $val_min = $ebit_min - $interest_overall_totals['min'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_min, 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): 
                                            $ebitda_sh = ($revenue_overall_totals['vismin_sh'] - $cost_overall_totals['vismin_sh']) - $selling_admin_overall_totals['vismin_sh'];
                                            $ebit_sh = $ebitda_sh - $operating_overall_totals['vismin_sh'];
                                            $val_sh = $ebit_sh - $interest_overall_totals['vismin_sh'];
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val_sh, 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['lncr'] - $cost_overall_totals['lncr'] - $selling_admin_overall_totals['lncr'] - $operating_overall_totals['lncr']) - $interest_overall_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_lncr'] - $cost_overall_totals['all_lncr'] - $selling_admin_overall_totals['all_lncr'] - $operating_overall_totals['all_lncr']) - $interest_overall_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['vismin'] - $cost_overall_totals['vismin'] - $selling_admin_overall_totals['vismin'] - $operating_overall_totals['vismin']) - $interest_overall_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_vismin'] - $cost_overall_totals['all_vismin'] - $selling_admin_overall_totals['all_vismin'] - $operating_overall_totals['all_vismin']) - $interest_overall_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['mlfsi'] - $cost_overall_totals['mlfsi'] - $selling_admin_overall_totals['mlfsi'] - $operating_overall_totals['mlfsi']) - $interest_overall_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['jewelers'] - $cost_overall_totals['jewelers'] - $selling_admin_overall_totals['jewelers'] - $operating_overall_totals['jewelers']) - $interest_overall_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['nationwide'] - $cost_overall_totals['nationwide'] - $selling_admin_overall_totals['nationwide'] - $operating_overall_totals['nationwide']) - $interest_overall_totals['nationwide'], 2) ?></td>
                                    </tr>
                                 
                                <?php endif; ?>

                                <?php
                                // Insert EARNINGS BEFORE INTEREST & TAXES row after sort_order 24
                                if ((int)$sort_order == 24): ?>
                                
                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">EBIT</td> <!-- EARNINGS BEFORE INTEREST & TAXES-->
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_lzn = ($revenue_overall_totals['lzn'] - $cost_overall_totals['lzn']) - $selling_admin_overall_totals['lzn'];
                                            $val_lzn = $ebitda_lzn - $operating_overall_totals['lzn'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_lzn, 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_ncr = ($revenue_overall_totals['ncr'] - $cost_overall_totals['ncr']) - $selling_admin_overall_totals['ncr'];
                                            $val_ncr = $ebitda_ncr - $operating_overall_totals['ncr'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_ncr, 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): 
                                            $ebitda_sh = ($revenue_overall_totals['lncr_sh'] - $cost_overall_totals['lncr_sh']) - $selling_admin_overall_totals['lncr_sh'];
                                            $val_sh = $ebitda_sh - $operating_overall_totals['lncr_sh'];
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val_sh, 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_vis = ($revenue_overall_totals['vis'] - $cost_overall_totals['vis']) - $selling_admin_overall_totals['vis'];
                                            $val_vis = $ebitda_vis - $operating_overall_totals['vis'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_vis, 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_min = ($revenue_overall_totals['min'] - $cost_overall_totals['min']) - $selling_admin_overall_totals['min'];
                                            $val_min = $ebitda_min - $operating_overall_totals['min'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_min, 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): 
                                            $ebitda_sh = ($revenue_overall_totals['vismin_sh'] - $cost_overall_totals['vismin_sh']) - $selling_admin_overall_totals['vismin_sh'];
                                            $val_sh = $ebitda_sh - $operating_overall_totals['vismin_sh'];
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val_sh, 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['lncr'] - $cost_overall_totals['lncr'] - $selling_admin_overall_totals['lncr']) - $operating_overall_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_lncr'] - $cost_overall_totals['all_lncr'] - $selling_admin_overall_totals['all_lncr']) - $operating_overall_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['vismin'] - $cost_overall_totals['vismin'] - $selling_admin_overall_totals['vismin']) - $operating_overall_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_vismin'] - $cost_overall_totals['all_vismin'] - $selling_admin_overall_totals['all_vismin']) - $operating_overall_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['mlfsi'] - $cost_overall_totals['mlfsi'] - $selling_admin_overall_totals['mlfsi']) - $operating_overall_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['jewelers'] - $cost_overall_totals['jewelers'] - $selling_admin_overall_totals['jewelers']) - $operating_overall_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['nationwide'] - $cost_overall_totals['nationwide'] - $selling_admin_overall_totals['nationwide']) - $operating_overall_totals['nationwide'], 2) ?></td>
                                    </tr>
                                 
                                <?php endif; ?>

                                <?php
                                // Insert NET INCOME/LOSS row after sort_order 26
                                if ((int)$sort_order == 26): ?>
                              
                                    <tr style="background-color: #f2b48f; color: white; font-weight: bold;">
                                        <td colspan="3" style="text-align: left; padding-left: 20px;">NET INCOME/LOSS</td>
                                        
                                        <?php foreach ($regions_by_zone['LZN'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $ebt = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebt - ($tax_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_lzn = ($revenue_overall_totals['lzn'] - $cost_overall_totals['lzn']) - $selling_admin_overall_totals['lzn'];
                                            $ebit_lzn = $ebitda_lzn - $operating_overall_totals['lzn'];
                                            $ebt_lzn = $ebit_lzn - $interest_overall_totals['lzn'];
                                            $val_lzn = $ebt_lzn - $tax_overall_totals['lzn'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_lzn, 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['NCR'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $ebt = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebt - ($tax_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_ncr = ($revenue_overall_totals['ncr'] - $cost_overall_totals['ncr']) - $selling_admin_overall_totals['ncr'];
                                            $ebit_ncr = $ebitda_ncr - $operating_overall_totals['ncr'];
                                            $ebt_ncr = $ebit_ncr - $interest_overall_totals['ncr'];
                                            $val_ncr = $ebt_ncr - $tax_overall_totals['ncr'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_ncr, 2) ?></td>
                                        
                                        <?php if($showrooms['LNCR Showroom']): 
                                            $ebitda_sh = ($revenue_overall_totals['lncr_sh'] - $cost_overall_totals['lncr_sh']) - $selling_admin_overall_totals['lncr_sh'];
                                            $ebit_sh = $ebitda_sh - $operating_overall_totals['lncr_sh'];
                                            $ebt_sh = $ebit_sh - $interest_overall_totals['lncr_sh'];
                                            $val_sh = $ebt_sh - $tax_overall_totals['lncr_sh'];
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val_sh, 2) ?></td>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($regions_by_zone['VIS'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $ebt = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebt - ($tax_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_vis = ($revenue_overall_totals['vis'] - $cost_overall_totals['vis']) - $selling_admin_overall_totals['vis'];
                                            $ebit_vis = $ebitda_vis - $operating_overall_totals['vis'];
                                            $ebt_vis = $ebit_vis - $interest_overall_totals['vis'];
                                            $val_vis = $ebt_vis - $tax_overall_totals['vis'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_vis, 2) ?></td>
                                        
                                        <?php foreach ($regions_by_zone['MIN'] as $r): 
                                            $ebitda = ($revenue_overall_totals['regions'][$r] ?? 0) - ($cost_overall_totals['regions'][$r] ?? 0) - ($selling_admin_overall_totals['regions'][$r] ?? 0);
                                            $ebit = $ebitda - ($operating_overall_totals['regions'][$r] ?? 0);
                                            $ebt = $ebit - ($interest_overall_totals['regions'][$r] ?? 0);
                                            $val = $ebt - ($tax_overall_totals['regions'][$r] ?? 0);
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val, 2) ?></td>
                                        <?php endforeach; ?>
                                        <?php 
                                            $ebitda_min = ($revenue_overall_totals['min'] - $cost_overall_totals['min']) - $selling_admin_overall_totals['min'];
                                            $ebit_min = $ebitda_min - $operating_overall_totals['min'];
                                            $ebt_min = $ebit_min - $interest_overall_totals['min'];
                                            $val_min = $ebt_min - $tax_overall_totals['min'];
                                        ?>
                                        <td style="text-align: right;"><?= number_format($val_min, 2) ?></td>
                                        
                                        <?php if($showrooms['VISMIN Showroom']): 
                                            $ebitda_sh = ($revenue_overall_totals['vismin_sh'] - $cost_overall_totals['vismin_sh']) - $selling_admin_overall_totals['vismin_sh'];
                                            $ebit_sh = $ebitda_sh - $operating_overall_totals['vismin_sh'];
                                            $ebt_sh = $ebit_sh - $interest_overall_totals['vismin_sh'];
                                            $val_sh = $ebt_sh - $tax_overall_totals['vismin_sh'];
                                        ?>
                                            <td style="text-align: right;"><?= number_format($val_sh, 2) ?></td>
                                        <?php endif; ?>

                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['lncr'] - $cost_overall_totals['lncr'] - $selling_admin_overall_totals['lncr'] - $operating_overall_totals['lncr'] - $interest_overall_totals['lncr']) - $tax_overall_totals['lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_lncr'] - $cost_overall_totals['all_lncr'] - $selling_admin_overall_totals['all_lncr'] - $operating_overall_totals['all_lncr'] - $interest_overall_totals['all_lncr']) - $tax_overall_totals['all_lncr'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['vismin'] - $cost_overall_totals['vismin'] - $selling_admin_overall_totals['vismin'] - $operating_overall_totals['vismin'] - $interest_overall_totals['vismin']) - $tax_overall_totals['vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['all_vismin'] - $cost_overall_totals['all_vismin'] - $selling_admin_overall_totals['all_vismin'] - $operating_overall_totals['all_vismin'] - $interest_overall_totals['all_vismin']) - $tax_overall_totals['all_vismin'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['mlfsi'] - $cost_overall_totals['mlfsi'] - $selling_admin_overall_totals['mlfsi'] - $operating_overall_totals['mlfsi'] - $interest_overall_totals['mlfsi']) - $tax_overall_totals['mlfsi'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['jewelers'] - $cost_overall_totals['jewelers'] - $selling_admin_overall_totals['jewelers'] - $operating_overall_totals['jewelers'] - $interest_overall_totals['jewelers']) - $tax_overall_totals['jewelers'], 2) ?></td>
                                        <td style="text-align: right;"><?= number_format(($revenue_overall_totals['nationwide'] - $cost_overall_totals['nationwide'] - $selling_admin_overall_totals['nationwide'] - $operating_overall_totals['nationwide'] - $interest_overall_totals['nationwide']) - $tax_overall_totals['nationwide'], 2) ?></td>
                                    </tr>
                                 
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-table"></i>
                        <p>No previous transactions found</p>
                        <p style="font-size: 13px; margin-top: 8px;">Upload a file to populate the GL codes</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        (function () {
            function parseAmount(text) {
                var raw = text.trim();
                if (!raw) return NaN;
                var isParenNegative = raw[0] === '(' && raw[raw.length - 1] === ')';
                if (isParenNegative) {
                    raw = raw.slice(1, -1);
                }
                var normalized = raw.replace(/,/g, '');
                if (!/^-?\d+(\.\d+)?$/.test(normalized)) {
                    return NaN;
                }
                var value = parseFloat(normalized);
                return isParenNegative ? -Math.abs(value) : value;
            }

            var cells = document.querySelectorAll('td');
            for (var i = 0; i < cells.length; i++) {
                var value = parseAmount(cells[i].textContent);
                if (!isNaN(value) && value < 0) {
                    cells[i].style.color = 'red';
                }
            }
        })();
    </script>

    <script>
        (function () {
            var isCollapsed = false;
            var toggleBtn = document.getElementById('toggleCollapseBtn');
            if (!toggleBtn) return;

            function setCollapsed(collapsed) {
                var subRows = document.querySelectorAll('tr.sub-order-row');
                var spacerRows = document.querySelectorAll('tr.spacer-row');
                for (var i = 0; i < subRows.length; i++) {
                    var sortOrder = parseInt(subRows[i].getAttribute('data-sort-order') || '0', 10);
                    if (sortOrder >= 1 && sortOrder <= 20) {
                        subRows[i].style.display = collapsed ? 'none' : '';
                    }
                }
                for (var j = 0; j < spacerRows.length; j++) {
                    var so = parseInt(spacerRows[j].getAttribute('data-sort-order') || '0', 10);
                    if (so >= 1 && so <= 20) {
                        spacerRows[j].style.display = collapsed ? 'none' : '';
                    }
                }
                isCollapsed = collapsed;
                toggleBtn.innerHTML = collapsed
                    ? '<i class="fas fa-expand"></i> Uncollapse View'
                    : '<i class="fas fa-compress"></i> Collapse View';
            }

            toggleBtn.addEventListener('click', function () {
                setCollapsed(!isCollapsed);
            });
        })();
    </script>

    <script>
        document.getElementById('exportPastBtn')?.addEventListener('click', function() {
            const month = document.querySelector('input[name="transaction_month"]').value;
            if (!month) {
                alert('Please select a month first.');
                return;
            }
            window.location.href = `export_past_transaction.php?transaction_month=${month}`;
        });
    </script>

    <script>
        document.getElementById('exportZoneTotalsBtn')?.addEventListener('click', function() {
            const month = document.querySelector('input[name="transaction_month"]').value;
            if (!month) {
                alert('Please select a month first.');
                return;
            }
            window.location.href = `export_past_transaction_zone.php?transaction_month=${month}`;
        });
    </script>
<?php include '../footer.php'; ?>

</body>
</html>

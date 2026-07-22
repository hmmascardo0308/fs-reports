<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit;
}

// Filters
$selected_years = $_GET['transaction_year'] ?? [];
if (!is_array($selected_years)) {
    $selected_years = $selected_years !== '' ? [$selected_years] : [];
}
rsort($selected_years);

$start_month = $_GET['start_month'] ?? '';
$end_month = $_GET['end_month'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old'; // old|new|mixed
$gl_code_mode = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Helper functions
function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

function colLetter(int $col): string { return Coordinate::stringFromColumnIndex($col); }

// Validate filters
$valid_filters = false;
if (!empty($selected_years) && !empty($start_month) && !empty($end_month)) {
    if ((int)$end_month >= (int)$start_month) {
        $valid_filters = true;
    }
}

// GET GL MAPPING
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, new_gl_code, gl_description_comparative, description
    FROM fs_reports.gl_codes_ho_new
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";
$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        if ($row['gl_id'] === 'INJ-2') {
            $special_keys[] = $key;
        }
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['old' => [], 'new' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'];
        }

        $old_code = trim((string)($row['gl_code'] ?? ''));
        $new_code = trim((string)($row['new_gl_code'] ?? ''));
        if ($new_code === '') $new_code = $old_code;

        if ($old_code !== '' && !in_array($old_code, $gl_mapping[$key]['old'], true)) {
            $gl_mapping[$key]['old'][] = $old_code;
        }
        if ($new_code !== '' && !in_array($new_code, $gl_mapping[$key]['new'], true)) {
            $gl_mapping[$key]['new'][] = $new_code;
        }

        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

function compute_table_rows_for_export(mysqli $conn, array $years, string $start_month, string $end_month, string $gl_code_mode, array $gl_mapping, array $gl_descriptions, array $special_keys, array $sort_order_descriptions, bool $use_real_data = true): array {
    $base_where = "WHERE (status_void IS NULL OR status_void != 'Void')";
    $fetch_period = function($year, $s_month, $e_month) use ($conn, $base_where) {
        $data = []; // month -> gl_code -> total_amount
        $sql = "
            SELECT gl_code, MONTH(transaction_month) as m, SUM(amount) as total_amount
            FROM fs_reports.comparative_report
            $base_where
            AND transaction_year = ? 
            AND MONTH(transaction_month) BETWEEN ? AND ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code, m
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iii', $year, $s_month, $e_month);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $m = (int)$row['m'];
                $gl = $row['gl_code'];
                $data[$m][$gl] = floatval($row['total_amount']);
            }
            mysqli_stmt_close($stmt);
        }
        return $data;
    };

    $y1 = $years[0] ?? null; $y2 = $years[1] ?? null; $y3 = $years[2] ?? null;
    $primary_data  = ($use_real_data && $y1) ? $fetch_period($y1, $start_month, $end_month) : [];
    $previous_data = ($use_real_data && $y2) ? $fetch_period($y2, $start_month, $end_month) : [];
    $third_data    = ($use_real_data && $y3) ? $fetch_period($y3, $start_month, $end_month) : [];

    $table_rows = [];
    foreach ($gl_mapping as $key => $codes_detailed) {
        [$sort_order, $sub_order] = explode('|', $key);
        $gl_description = $gl_descriptions[$key];
        $is_inj2 = in_array($key, $special_keys);

        $calc_total = function($year_data, $year) use ($gl_code_mode, $start_month, $end_month, $codes_detailed) {
            $total = 0;
            if (empty($year_data)) return 0;
            for ($m = (int)$start_month; $m <= (int)$end_month; $m++) {
                if (!isset($year_data[$m])) continue;
                $mode = $gl_code_mode;
                if ($gl_code_mode === 'mixed') {
                    $period_str = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                    $mode = isApril2026OrLater($period_str) ? 'new' : 'old';
                }
                $target_codes = $codes_detailed[$mode];
                foreach ($target_codes as $gl_code) {
                    if (isset($year_data[$m][$gl_code])) {
                        $total += $year_data[$m][$gl_code];
                    }
                }
            }
            return $total;
        };

        $p_tot = $calc_total($primary_data, $y1);
        $prev_tot = $calc_total($previous_data, $y2);
        $t_tot = $calc_total($third_data, $y3);

        $table_rows[] = ['sort_order' => $sort_order, 'sub_order' => $sub_order, 'gl_description' => $gl_description, 'is_section_header' => false, 'is_summary_row' => false, 'primary_total' => $p_tot, 'previous_total' => $prev_tot, 'third_total' => $t_tot, 'is_inj2' => $is_inj2];
    }

    $grouped_rows = [];
    foreach ($table_rows as $row) { $grouped_rows[$row['sort_order']][] = $row; }
    $final_table_rows = [];
    $rev_tot_p = 0; $rev_tot_prev = 0; $rev_tot_third = 0;
    $sa_tot_p = 0; $sa_tot_prev = 0; $sa_tot_third = 0;
    $gp_tot_p = 0; $gp_tot_prev = 0; $gp_tot_third = 0;
    $ebitda_tot_p = 0; $ebitda_tot_prev = 0; $ebitda_tot_third = 0;
    $ebit_tot_p = 0; $ebit_tot_prev = 0; $ebit_tot_third = 0;
    $ebt_tot_p = 0; $ebt_tot_prev = 0; $ebt_tot_third = 0;
    $net_tot_p = 0; $net_tot_prev = 0; $net_tot_third = 0;

    foreach ($grouped_rows as $sort_order => $rows) {
        if (!in_array((int)$sort_order, [10, 13])) {
            foreach ($rows as $row) {
                $p = $row['primary_total']; $prev = $row['previous_total']; $t = $row['third_total'];
                if ($row['is_inj2']) { $p = -$p; $prev = -$prev; $t = -$t; }
                $final_table_rows[] = array_merge($row, ['primary_total' => $p, 'previous_total' => $prev, 'third_total' => $t]);
            }
        }
        $total_p = array_sum(array_column($rows, 'primary_total')); $total_prev = array_sum(array_column($rows, 'previous_total')); $total_t = array_sum(array_column($rows, 'third_total'));
        if ((int)$sort_order >= 1 && (int)$sort_order <= 22) { $rev_tot_p += $total_p; $rev_tot_prev += $total_prev; $rev_tot_third += $total_t; }
        if ((int)$sort_order == 24 || (int)$sort_order == 25) { $sa_tot_p += $total_p; $sa_tot_prev += $total_prev; $sa_tot_third += $total_t; }
        $make_variance = function ($p, $prev, $t) use ($y2, $y3): array {
            $inc_dec = $p - $prev;
            $pct = ($prev != 0) ? ($inc_dec / $prev) * 100 : ($p > 0 ? 100 : ($p < 0 ? -100 : 0));
            $inc_dec_t = $p - $t;
            $pct_t = ($y3 && $t != 0) ? ($inc_dec_t / $t) * 100 : ($p > 0 ? 100 : ($p < 0 ? -100 : 0));
            return ['inc_dec' => $inc_dec, 'percentage' => $pct, 'inc_dec_third' => $inc_dec_t, 'percentage_third' => $pct_t];
        };
        if (!in_array((int)$sort_order, [26, 27, 28])) {
            $description = $sort_order_descriptions[$sort_order] ?? "Total for Sort Order " . $sort_order;
            $final_table_rows[] = array_merge(['sort_order' => $sort_order, 'sub_order' => '', 'gl_description' => $description, 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $total_p, 'previous_total' => $total_prev, 'third_total' => $total_t], $make_variance($total_p, $total_prev, $total_t));
        }
        if ((int)$sort_order == 24) {
            $final_table_rows[] = ['is_manual_spacer' => true];
        }
        if ((int)$sort_order == 22) {
            $final_table_rows[] = array_merge(['sort_order' => 'TOTAL REVENUES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $rev_tot_p, 'previous_total' => $rev_tot_prev, 'third_total' => $rev_tot_third], $make_variance($rev_tot_p, $rev_tot_prev, $rev_tot_third));
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = ['sort_order' => '', 'sub_order' => 'Cost of Sales/Service', 'gl_description' => '', 'is_section_header' => true, 'is_summary_row' => true, 'primary_total' => null, 'previous_total' => null, 'third_total' => null, 'inc_dec' => null, 'percentage' => null, 'inc_dec_third' => null, 'percentage_third' => null];
        }
        if ((int)$sort_order == 23) {
            $gp_tot_p = $rev_tot_p - $total_p; $gp_tot_prev = $rev_tot_prev - $total_prev; $gp_tot_third = $rev_tot_third - $total_t;
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge(['sort_order' => 'GROSS PROFIT', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $gp_tot_p, 'previous_total' => $gp_tot_prev, 'third_total' => $gp_tot_third], $make_variance($gp_tot_p, $gp_tot_prev, $gp_tot_third));
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = ['sort_order' => 'SELLING & ADMIN EXPENSE', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => true, 'is_summary_row' => true, 'primary_total' => null, 'previous_total' => null, 'third_total' => null, 'inc_dec' => null, 'percentage' => null, 'inc_dec_third' => null, 'percentage_third' => null];
        }
        if ((int)$sort_order == 25) {
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge(['sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'primary_total' => $sa_tot_p, 'previous_total' => $sa_tot_prev, 'third_total' => $sa_tot_third], $make_variance($sa_tot_p, $sa_tot_prev, $sa_tot_third));
            $final_table_rows[] = ['is_manual_spacer' => true];
            $ebitda_tot_p = $gp_tot_p - $sa_tot_p; $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev; $ebitda_tot_third = $gp_tot_third - $sa_tot_third;
            $final_table_rows[] = array_merge(['sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $ebitda_tot_p, 'previous_total' => $ebitda_tot_prev, 'third_total' => $ebitda_tot_third], $make_variance($ebitda_tot_p, $ebitda_tot_prev, $ebitda_tot_third));
        }
        if ((int)$sort_order == 26) {
            $ebit_tot_p = $ebitda_tot_p - $total_p; $ebit_tot_prev = $ebitda_tot_prev - $total_prev; $ebit_tot_third = $ebitda_tot_third - $total_t;
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge(['sort_order' => 'EARNINGS BEFORE INTEREST & TAXES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $ebit_tot_p, 'previous_total' => $ebit_tot_prev, 'third_total' => $ebit_tot_third], $make_variance($ebit_tot_p, $ebit_tot_prev, $ebit_tot_third));
        }
        if ((int)$sort_order == 27) {
            $ebt_tot_p = $ebit_tot_p - $total_p; $ebt_tot_prev = $ebit_tot_prev - $total_prev; $ebt_tot_third = $ebit_tot_third - $total_t;
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge(['sort_order' => 'EARNINGS BEFORE TAXES', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $ebt_tot_p, 'previous_total' => $ebt_tot_prev, 'third_total' => $ebt_tot_third], $make_variance($ebt_tot_p, $ebt_tot_prev, $ebt_tot_third));
        }
        if ((int)$sort_order == 28) {
            $net_tot_p = $ebt_tot_p - $total_p; $net_tot_prev = $ebt_tot_prev - $total_prev; $net_tot_third = $ebt_tot_third - $total_t;
            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge(['sort_order' => 'TOTAL NET INCOME/LOSS', 'sub_order' => '', 'gl_description' => '', 'is_section_header' => false, 'is_summary_row' => true, 'skip_spacer' => true, 'primary_total' => $net_tot_p, 'previous_total' => $net_tot_prev, 'third_total' => $net_tot_third], $make_variance($net_tot_p, $net_tot_prev, $net_tot_third));
        }
    }
    return $final_table_rows;
}

// ── Build spreadsheet ─────────────────────────────────────────────────────────

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cumulative Comparative');
$sheet->freezePane('A10');
$widths = [2, 3, 50, 1, 20, 20, 20, 2, 20, 15, 20, 15, 2, 2];
foreach ($widths as $idx => $w) { $sheet->getColumnDimension(colLetter($idx + 1))->setWidth($w); }

$row = 1;
$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $sheet->getRowDimension($row)->setRowHeight(50);
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setPath($logo_path); $drawing->setHeight(60); $drawing->setCoordinates('E1'); $drawing->setOffsetX(20); $drawing->setWorksheet($sheet);
}
$row++;
$sheet->setCellValue("A$row", 'NATIONWIDE (MLFSI & JEWELERS)');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;
$sheet->setCellValue("A$row", 'COMPARATIVE PROFIT & LOSS STATEMENT');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$y1 = $selected_years[0] ?? null; 
$y2 = $selected_years[1] ?? null; 
$y3 = $selected_years[2] ?? null;

$full_range_label = "";
$short_range_label = "";
if ($valid_filters) {
    $start_f = strtoupper($month_names[(int)$start_month]);
    $end_f = strtoupper($month_names[(int)$end_month]);
    $full_range_label = ($start_month == $end_month) ? $start_f : "$start_f - $end_f";

    $start_s = strtoupper(substr($month_names[(int)$start_month], 0, 3));
    $end_s = strtoupper(substr($month_names[(int)$end_month], 0, 3));
    $short_range_label = ($start_month == $end_month) ? $start_s : "$start_s - $end_s";
}

$p_hdr_full = $y1 ? "$full_range_label $y1" : "(PRIMARY PERIOD)";
$v2_full = $y2 ? "$y2" : "(PERIOD 2)";
$v3_full = $y3 ? "$y3" : "(PERIOD 3)";
$comparison_line = $p_hdr_full . " VS " . $v2_full . " VS " . $v3_full;

$sheet->setCellValue("A$row", $comparison_line);
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$sheet->setCellValue("A$row", ""); // Row 5 empty
$row++;

// Row 6
$sheet->setCellValue("A$row", "NATIONWIDE");
$sheet->mergeCells("A$row:D$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A$row:D$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCA16F');

$sheet->setCellValue("E$row", $y1 ? "$short_range_label $y1" : "(PRIMARY PERIOD)");
$sheet->setCellValue("F$row", $y2 ? "$short_range_label $y2" : "(PERIOD 2)");
$sheet->setCellValue("G$row", $y3 ? "$short_range_label $y3" : "(PERIOD 3)");
$sheet->setCellValue("H$row", "");
$sheet->setCellValue("I$row", ($y1 && $y2) ? "$y1 VS $y2" : "YEAR VS YEAR");
$sheet->setCellValue("J$row", "%");
$sheet->setCellValue("K$row", ($y1 && $y3) ? "$y1 VS $y3" : "YEAR VS YEAR");
$sheet->setCellValue("L$row", "%");
$sheet->setCellValue("M$row", "");
$sheet->setCellValue("N$row", "");

foreach (['E', 'F', 'G'] as $c) {
    $sheet->getStyle($c . $row)->getFont()->setBold(true);
    $sheet->getStyle($c . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($c . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCA16F');
}

foreach (['I', 'J', 'K', 'L', 'M', 'N'] as $c) {
    $sheet->getStyle($c . $row)->getFont()->setBold(true);
    $sheet->getStyle($c . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($c . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('949392');
}
$row++;

// Row 7
$sheet->mergeCells("A$row:G$row");
$sheet->setCellValue("H$row", "");
$sheet->setCellValue("I$row", "INC/DEC");
$sheet->setCellValue("J$row", "");
$sheet->setCellValue("K$row", "INC/DEC");

$sheet->getStyle("I$row")->getFont()->setBold(true);
$sheet->getStyle("I$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->getStyle("K$row")->getFont()->setBold(true);
$sheet->getStyle("K$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Background color for A-G
$sheet->getStyle("A$row:G$row")
    ->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()
    ->setARGB('FFFCA16F');

// Background color for I-N
foreach (['I', 'J', 'K', 'L', 'M', 'N'] as $c) {
    $sheet->getStyle($c . $row)
        ->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()
        ->setARGB('949392');
}

$row++;
// Row 8
$sheet->setCellValue("A$row", 'REVENUES'); 
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('949392');
$row++;

$data_rows = compute_table_rows_for_export($conn, $selected_years, $start_month, $end_month, $gl_code_mode, $gl_mapping, $gl_descriptions, $special_keys, $sort_order_descriptions, $valid_filters);
$highlight_labels = ['TOTAL REVENUES', 'GROSS PROFIT', 'TOTAL SELLING AND ADMIN EXPENSES', "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES', 'TOTAL NET INCOME/LOSS'];

foreach ($data_rows as $item) {
    if (isset($item['is_manual_spacer'])) { $row++; continue; }
    if (!empty($item['is_section_header'])) {
        $label = $item['sub_order'] ?: $item['sort_order'];
        $sheet->setCellValue("A$row", $label); $sheet->mergeCells("A$row:N$row");
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFA973');
        $row++; continue;
    }
    $is_sum = $item['is_summary_row'] ?? false;
    if ($is_sum) {
        $label = $item['sort_order'];
        $sheet->setCellValue("A$row", (is_numeric($label) && (int)$label <= 25) ? '' : $label);
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        $sheet->setCellValue("E$row", $item['primary_total']);
        $sheet->setCellValue("F$row", $item['previous_total']);
        $sheet->setCellValue("G$row", $item['third_total']);
        $sheet->setCellValue("I$row", $item['inc_dec']);
        $sheet->setCellValue("K$row", $item['inc_dec_third']);
        foreach ([['J', 'percentage'], ['L', 'percentage_third']] as [$col, $field]) {
            $pct = $item[$field] ?? 0;
            if (abs($pct) >= 1000) { $sheet->setCellValue($col . $row, 'mat'); } else { $sheet->setCellValue($col . $row, $pct); }
            if ($pct < 0) $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        }
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $v = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($v) && $v < 0) $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
        }
        foreach (['J', 'L'] as $col) {
            $v = $sheet->getCell($col . $row)->getValue();
            if ($v === 'mat') { $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT); } else { $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00'); if (is_numeric($v) && $v < 0) $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED); }
        }
        $sheet->getStyle("A$row:N$row")->getFont()->setBold(true);
        $bg = in_array($label, $highlight_labels, true) ? 'FFFFA973' : (is_numeric($label) && (int)$label % 2 != 0 && (int)$label <= 22 ? null : 'FFFDE9D9');
        if ($bg) $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        if ($label == '23') $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        if (in_array($label, ["EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES'], true)) $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        if ($label === 'TOTAL NET INCOME/LOSS') { $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN); $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE); }
        if (is_numeric($label) && (int)$label >= 1 && (int)$label <= 22) { $row++; $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false); $row++; continue; }
    } else {
        if ((int)$item['sort_order'] === 17 && in_array((int)$item['sub_order'], [3, 4, 5, 6])) { $sheet->setCellValue("B$row", $item['gl_description'] ?? ''); } else { $sheet->setCellValue("C$row", $item['gl_description'] ?? ''); }
        $sheet->setCellValue("E$row", $item['primary_total']);
        $sheet->setCellValue("F$row", $item['previous_total']);
        $sheet->setCellValue("G$row", $item['third_total']);
        $p = floatval($item['primary_total']); $prev = floatval($item['previous_total']); $t = floatval($item['third_total']);
        $diff1 = $p - $prev; $pct1 = ($prev != 0) ? ($diff1 / abs($prev)) * 100 : ($p > 0 ? 100 : ($p < 0 ? -100 : 0));
        $sheet->setCellValue("I$row", $diff1);
        if (abs($pct1) >= 1000) { $sheet->setCellValue("J$row", 'mat'); } else { $sheet->setCellValue("J$row", $pct1); }
        if ($pct1 < 0) $sheet->getStyle("J$row")->getFont()->getColor()->setARGB(Color::COLOR_RED);
        $diff2 = $p - $t; $pct2 = ($y3 && $t != 0) ? ($diff2 / $t) * 100 : ($p > 0 ? 100 : ($p < 0 ? -100 : 0));
        $sheet->setCellValue("K$row", $diff2);
        if (abs($pct2) >= 1000) { $sheet->setCellValue("L$row", 'mat'); } else { $sheet->setCellValue("L$row", $pct2); }
        if ($pct2 < 0) $sheet->getStyle("L$row")->getFont()->getColor()->setARGB(Color::COLOR_RED);
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) { $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00'); $v = $sheet->getCell($col . $row)->getValue(); if (is_numeric($v) && $v < 0) $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED); }
        foreach (['J', 'L'] as $col) { $v = $sheet->getCell($col . $row)->getValue(); if ($v === 'mat') { $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT); } else { $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00'); if (is_numeric($v) && $v < 0) $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED); } }
        if (is_numeric($item['sort_order']) && (int)$item['sort_order'] >= 1 && (int)$item['sort_order'] <= 22) $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
    }
    $row++;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Cumulative_Report_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet); $writer->save('php://output'); exit;

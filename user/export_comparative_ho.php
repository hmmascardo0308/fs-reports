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

$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period   = $_GET['primary_period']   ?? '';
$previous_period  = $_GET['previous_period']  ?? '';
$third_period     = $_GET['third_period']     ?? '';
$gl_code_mode     = $_GET['gl_code_mode']     ?? 'old';
$gl_code_mode     = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

// ── helpers ───────────────────────────────────────────────────────────────────

function compareMonths(string $a, string $b): int {
    return strtotime($a . '-01') - strtotime($b . '-01');
}

function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2026-03-01');
}

function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') >= strtotime('2026-04-01');
}

function colLetter(int $col): string {
    return Coordinate::stringFromColumnIndex($col);
}

// ── validate filters (mirrors comparative_report_original.php exactly) ────────

$valid_filters = false;

if (!empty($primary_period) && !empty($previous_period)) {
    $ok = compareMonths($primary_period, $previous_period) > 0;

    // third period: primary must also be greater than third
    if ($ok && !empty($third_period)) {
        $ok = compareMonths($primary_period, $third_period) > 0;
    }

    if ($ok) {
        if ($gl_code_mode === 'old') {
            $ok = isMarch2026OrEarlier($primary_period)
               && isMarch2026OrEarlier($previous_period)
               && (empty($third_period) || isMarch2026OrEarlier($third_period));
        } elseif ($gl_code_mode === 'new') {
            $ok = isApril2026OrLater($primary_period)
               && isApril2026OrLater($previous_period)
               && (empty($third_period) || isApril2026OrLater($third_period));
        }
    } elseif ($gl_code_mode === 'mixed') {
        $valid_filters = true;
    }

    $valid_filters = $ok;
}

// ── GL mapping (identical to original) ───────────────────────────────────────

$gl_mapping             = [];
$gl_descriptions        = [];
$sort_order_descriptions = [];
$special_keys           = [];

$gl_structure_result = mysqli_query($conn, "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, new_gl_code,
                    gl_description_comparative, description
    FROM fs_reports.gl_codes_ho_new
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
");

if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];

        if ($row['gl_id'] === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key]      = ['old' => [], 'new' => []];
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

// ── compute rows (logic mirrors compute_table_rows_for_region_area exactly) ───

function compute_table_rows_for_export(
    mysqli $conn,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    string $third_period,
    string $gl_code_mode,
    array  $gl_mapping,
    array  $gl_descriptions,
    array  $special_keys,
    array  $sort_order_descriptions,
    bool   $use_real_data
): array {

    // Build base WHERE (same as original)
    $where_conditions = [];
    $params           = [];
    $types            = '';

    if (!empty($transaction_year)) {
        $where_conditions[] = 'transaction_year = ?';
        $params[]           = $transaction_year;
        $types             .= 's';
    }

    $base_where  = !empty($where_conditions)
        ? 'WHERE ' . implode(' AND ', $where_conditions)
        : 'WHERE 1=1';
    $base_where .= " AND (status_void IS NULL OR status_void != 'Void')";

    // Fetch one period's data
    $fetch_period = function (string $period) use ($conn, $base_where, $params, $types, $use_real_data): array {
        if (!$use_real_data || empty($period)) return [];

        $parts     = explode('-', $period);
        $year_val  = $parts[0];
        $month_val = $period . '-01';

        $sql = "
            SELECT gl_code, SUM(amount) AS total_amount
            FROM fs_reports.comparative_report
            $base_where
            AND transaction_year = ? AND transaction_month = ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];

        $bind = array_merge($params, [$year_val, $month_val]);
        mysqli_stmt_bind_param($stmt, $types . 'ss', ...$bind);
        mysqli_stmt_execute($stmt);
        $res  = mysqli_stmt_get_result($stmt);
        $data = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $data[$r['gl_code']] = floatval($r['total_amount']);
        }
        mysqli_stmt_close($stmt);
        return $data;
    };

    $primary_data  = $fetch_period($primary_period);
    $previous_data = $fetch_period($previous_period);
    $third_data    = $fetch_period($third_period);

    // Build raw detail rows (NO negation here — mirrors original)
    $table_rows = [];
    foreach ($gl_mapping as $key => $codes_detailed) {
        [$sort_order, $sub_order] = explode('|', $key);
        $is_inj2 = in_array($key, $special_keys, true);

        // Determine mode per period
        $p_mode = $gl_code_mode;
        $prev_mode = $gl_code_mode;
        $t_mode = $gl_code_mode;
        if ($gl_code_mode === 'mixed') {
            $p_mode = isApril2026OrLater($primary_period) ? 'new' : 'old';
            $prev_mode = isApril2026OrLater($previous_period) ? 'new' : 'old';
            $t_mode = (!empty($third_period) && isApril2026OrLater($third_period)) ? 'new' : 'old';
        }

        $p_codes = $codes_detailed[$p_mode];
        $prev_codes = $codes_detailed[$prev_mode];
        $t_codes = $codes_detailed[$t_mode];

        $p_tot = 0; $prev_tot = 0; $t_tot = 0;
        foreach ($p_codes as $code) {
            $p_tot    += $primary_data[$code]  ?? 0;
        }
        foreach ($prev_codes as $code) {
            $prev_tot += $previous_data[$code] ?? 0;
        }
        foreach ($t_codes as $code) {
            $t_tot    += $third_data[$code]    ?? 0;
        }

        $table_rows[] = [
            'sort_order'     => $sort_order,
            'sub_order'      => $sub_order,
            'gl_description' => $gl_descriptions[$key],
            'is_summary_row' => false,
            'is_section_header' => false,
            'primary_total'  => $p_tot,
            'previous_total' => $prev_tot,
            'third_total'    => $t_tot,
            'is_inj2'        => $is_inj2,
        ];
    }

    // Group by sort_order
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $grouped_rows[$row['sort_order']][] = $row;
    }

    // Accumulator variables (identical names/semantics as original)
    $final_table_rows = [];

    $rev_tot_p    = 0; $rev_tot_prev    = 0; $rev_tot_third    = 0;
    $sa_tot_p     = 0; $sa_tot_prev     = 0; $sa_tot_third     = 0;
    $gp_tot_p     = 0; $gp_tot_prev     = 0; $gp_tot_third     = 0;
    $ebitda_tot_p = 0; $ebitda_tot_prev = 0; $ebitda_tot_third = 0;
    $ebit_tot_p   = 0; $ebit_tot_prev   = 0; $ebit_tot_third   = 0;
    $ebt_tot_p    = 0; $ebt_tot_prev    = 0; $ebt_tot_third    = 0;
    $net_tot_p    = 0; $net_tot_prev    = 0; $net_tot_third    = 0;

    foreach ($grouped_rows as $sort_order => $rows) {

        // Skip detail rows for 10 and 13, same as original
        if (!in_array((int)$sort_order, [10, 13])) {
            foreach ($rows as $row) {
                // Apply INJ-2 negation only for detail rows (same as original render logic)
                $p    = $row['primary_total'];
                $prev = $row['previous_total'];
                $t    = $row['third_total'];
                if ($row['is_inj2']) {
                    $p = -$p; $prev = -$prev; $t = -$t;
                }
                $final_table_rows[] = array_merge($row, [
                    'primary_total'  => $p,
                    'previous_total' => $prev,
                    'third_total'    => $t,
                ]);
            }
        }

        // Summary totals use RAW values (before INJ-2 flip) — same as original
        // because original sums array_column which reads the stored (non-flipped) values
        $total_primary_total  = array_sum(array_column($rows, 'primary_total'));
        $total_previous_total = array_sum(array_column($rows, 'previous_total'));
        $total_third_total    = array_sum(array_column($rows, 'third_total'));

        // Revenue accumulation
        if ((int)$sort_order >= 1 && (int)$sort_order <= 22) {
            $rev_tot_p     += $total_primary_total;
            $rev_tot_prev  += $total_previous_total;
            $rev_tot_third += $total_third_total;
        }

        // Selling & Admin accumulation
        if ((int)$sort_order == 24 || (int)$sort_order == 25) {
            $sa_tot_p     += $total_primary_total;
            $sa_tot_prev  += $total_previous_total;
            $sa_tot_third += $total_third_total;
        }

        // Helper to build inc/dec and % fields (multiply by 100 for percentage)
        $make_variance = function (float $p, float $prev, float $t) use ($third_period): array {
            $inc_dec    = $p - $prev;
            $pct        = ($prev != 0) ? ($inc_dec / abs($prev)) * 100 : ($p != 0 ? 100 : 0);
            $inc_dec_t  = $p - $t;
            $pct_t      = (!empty($third_period) && $t != 0)
                            ? ($inc_dec_t / abs($t)) * 100
                            : (!empty($third_period) && $p != 0 ? 100 : 0);
            return [
                'inc_dec'          => $inc_dec,
                'percentage'       => $pct,
                'inc_dec_third'    => $inc_dec_t,
                'percentage_third' => $pct_t,
            ];
        };

        // Summary row (hidden for 26,27,28)
        if (!in_array((int)$sort_order, [26, 27, 28])) {
            $description = $sort_order_descriptions[$sort_order]
                         ?? ('Total for Sort Order ' . $sort_order);
            $final_table_rows[] = array_merge([
                'sort_order'        => $sort_order,
                'sub_order'         => '',
                'gl_description'    => $description,
                'is_section_header' => false,
                'is_summary_row'    => true,
                'primary_total'     => $total_primary_total,
                'previous_total'    => $total_previous_total,
                'third_total'       => $total_third_total,
            ], $make_variance($total_primary_total, $total_previous_total, $total_third_total));
        }

        // ── TOTAL REVENUES after sort_order 22 ───────────────────────────────
        if ((int)$sort_order == 22) {
            $final_table_rows[] = array_merge([
                'sort_order'        => 'TOTAL REVENUES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'primary_total'     => $rev_tot_p,
                'previous_total'    => $rev_tot_prev,
                'third_total'       => $rev_tot_third,
            ], $make_variance($rev_tot_p, $rev_tot_prev, $rev_tot_third));

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order'        => '',
                'sub_order'         => 'Cost of Sales/Service',
                'gl_description'    => '',
                'is_section_header' => true,
                'is_summary_row'    => true,
                'primary_total'     => null,
                'previous_total'    => null,
                'third_total'       => null,
                'inc_dec'           => null,
                'percentage'        => null,
                'inc_dec_third'     => null,
                'percentage_third'  => null,
            ];
        }

        // ── GROSS PROFIT after sort_order 23 ─────────────────────────────────
        if ((int)$sort_order == 23) {
            $gp_tot_p     = $rev_tot_p     - $total_primary_total;
            $gp_tot_prev  = $rev_tot_prev  - $total_previous_total;
            $gp_tot_third = $rev_tot_third - $total_third_total;

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = array_merge([
                'sort_order'        => 'GROSS PROFIT',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'primary_total'     => $gp_tot_p,
                'previous_total'    => $gp_tot_prev,
                'third_total'       => $gp_tot_third,
            ], $make_variance($gp_tot_p, $gp_tot_prev, $gp_tot_third));

            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = [
                'sort_order'        => 'SELLING & ADMIN EXPENSE',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => true,
                'is_summary_row'    => true,
                'primary_total'     => null,
                'previous_total'    => null,
                'third_total'       => null,
                'inc_dec'           => null,
                'percentage'        => null,
                'inc_dec_third'     => null,
                'percentage_third'  => null,
            ];
        }

        // Add spacer after sort_order 24
        if ((int)$sort_order == 24) {
            $final_table_rows[] = ['is_manual_spacer' => true];
        }

        // ── TOTAL S&A + EBITDA after sort_order 25 ───────────────────────────
        if ((int)$sort_order == 25) {
            $final_table_rows[] = ['is_manual_spacer' => true];

            $final_table_rows[] = array_merge([
                'sort_order'        => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'primary_total'     => $sa_tot_p,
                'previous_total'    => $sa_tot_prev,
                'third_total'       => $sa_tot_third,
            ], $make_variance($sa_tot_p, $sa_tot_prev, $sa_tot_third));

            $final_table_rows[] = ['is_manual_spacer' => true];

            $ebitda_tot_p     = $gp_tot_p     - $sa_tot_p;
            $ebitda_tot_prev  = $gp_tot_prev  - $sa_tot_prev;
            $ebitda_tot_third = $gp_tot_third - $sa_tot_third;

            $final_table_rows[] = array_merge([
                'sort_order'        => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'primary_total'     => $ebitda_tot_p,
                'previous_total'    => $ebitda_tot_prev,
                'third_total'       => $ebitda_tot_third,
            ], $make_variance($ebitda_tot_p, $ebitda_tot_prev, $ebitda_tot_third));
        }

        // ── EBIT after sort_order 26 ──────────────────────────────────────────
        if ((int)$sort_order == 26) {
            $ebit_tot_p     = $ebitda_tot_p     - $total_primary_total;
            $ebit_tot_prev  = $ebitda_tot_prev  - $total_previous_total;
            $ebit_tot_third = $ebitda_tot_third - $total_third_total;

            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge([
                'sort_order'        => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'primary_total'     => $ebit_tot_p,
                'previous_total'    => $ebit_tot_prev,
                'third_total'       => $ebit_tot_third,
            ], $make_variance($ebit_tot_p, $ebit_tot_prev, $ebit_tot_third));
        }

        // ── EBT after sort_order 27 ───────────────────────────────────────────
        if ((int)$sort_order == 27) {
            $ebt_tot_p     = $ebit_tot_p     - $total_primary_total;
            $ebt_tot_prev  = $ebit_tot_prev  - $total_previous_total;
            $ebt_tot_third = $ebit_tot_third - $total_third_total;

            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge([
                'sort_order'        => 'EARNINGS BEFORE TAXES',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'primary_total'     => $ebt_tot_p,
                'previous_total'    => $ebt_tot_prev,
                'third_total'       => $ebt_tot_third,
            ], $make_variance($ebt_tot_p, $ebt_tot_prev, $ebt_tot_third));
        }

        // ── TOTAL NET INCOME/LOSS after sort_order 28 ─────────────────────────
        if ((int)$sort_order == 28) {
            $net_tot_p     = $ebt_tot_p     - $total_primary_total;
            $net_tot_prev  = $ebt_tot_prev  - $total_previous_total;
            $net_tot_third = $ebt_tot_third - $total_third_total;

            $final_table_rows[] = ['is_manual_spacer' => true];
            $final_table_rows[] = array_merge([
                'sort_order'        => 'TOTAL NET INCOME/LOSS',
                'sub_order'         => '',
                'gl_description'    => '',
                'is_section_header' => false,
                'is_summary_row'    => true,
                'skip_spacer'       => true,
                'primary_total'     => $net_tot_p,
                'previous_total'    => $net_tot_prev,
                'third_total'       => $net_tot_third,
            ], $make_variance($net_tot_p, $net_tot_prev, $net_tot_third));
        }
    }

    return $final_table_rows;
}

// ── Build spreadsheet ─────────────────────────────────────────────────────────

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Head Office Comparative');
$sheet->freezePane('A10');

// Column widths
$widths = [2, 3, 50, 1, 20, 20, 20, 2, 20, 15, 20, 15, 1, 1, 1];
foreach ($widths as $idx => $w) {
    $sheet->getColumnDimension(colLetter($idx + 1))->setWidth($w);
}

$row = 1;

// Logo
$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $sheet->getRowDimension($row)->setRowHeight(50);
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setPath($logo_path);
    $drawing->setHeight(60);
    $drawing->setCoordinates('E1');
    $drawing->setOffsetX(20);
    $drawing->setWorksheet($sheet);
}
$row++;

// Title
$sheet->setCellValue("A$row", 'COMPARATIVE PROFIT & LOSS STATEMENT - w/ ALLOCATED HEAD OFFICE');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Period line
$p_disp    = !empty($primary_period)  ? date('F Y', strtotime($primary_period  . '-01')) : '(Primary)';
$prev_disp = !empty($previous_period) ? date('F Y', strtotime($previous_period . '-01')) : '(Previous)';
$third_disp = !empty($third_period)   ? date('F Y', strtotime($third_period    . '-01')) : '(Period 3)';
$period_line = strtoupper($p_disp . ' VS ' . $prev_disp . ' VS ' . $third_disp);

$sheet->setCellValue("A$row", $period_line);
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$row += 2; // rows 4-5 empty

// INCREASE/DECREASE header with red "DECREASE"
$richText = new RichText();
$rt1 = $richText->createTextRun('INCREASE/');
$rt1->getFont()->setBold(true)->setColor(new Color(Color::COLOR_BLACK));
$rt2 = $richText->createTextRun('DECREASE');
$rt2->getFont()->setBold(true)->setColor(new Color(Color::COLOR_RED));

$sheet->setCellValue("I$row", $richText);
$sheet->mergeCells("I$row:L$row");
$sheet->getStyle("I$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFDBAC');
$row++;

// Column sub-headers
$p_hdr    = strtoupper(!empty($primary_period)  ? date('F Y', strtotime($primary_period  . '-01')) : '(Primary Period)');
$prev_hdr = strtoupper(!empty($previous_period) ? date('F Y', strtotime($previous_period . '-01')) : '(Previous Period)');
$t_hdr    = strtoupper(!empty($third_period)    ? date('F Y', strtotime($third_period    . '-01')) : '(Period 3)');

$sheet->setCellValue("E$row", $p_hdr);
$sheet->setCellValue("F$row", $prev_hdr);
$sheet->setCellValue("G$row", $t_hdr);
$sheet->setCellValue("I$row", 'PREVIOUS MONTH');
$sheet->setCellValue("J$row", '%');
$sheet->setCellValue("K$row", 'PREVIOUS YEAR');
$sheet->setCellValue("L$row", '%');

foreach (['E', 'F', 'G', 'I', 'J', 'K', 'L'] as $c) {
    $sheet->getStyle($c . $row)->getFont()->setBold(true);
    $sheet->getStyle($c . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($c . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF9E5C');
}
$row++;

$row += 2; // rows 8-9 empty

// REVENUES section header row (always present, same as original)
$sheet->setCellValue("A$row", 'REVENUES');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$row++;

// ── Write data rows ───────────────────────────────────────────────────────────

$data_rows = compute_table_rows_for_export(
    $conn,
    $transaction_year,
    $primary_period,
    $previous_period,
    $third_period,
    $gl_code_mode,
    $gl_mapping,
    $gl_descriptions,
    $special_keys,
    $sort_order_descriptions,
    $valid_filters
);

$highlight_labels = [
    'TOTAL REVENUES',
    'GROSS PROFIT',
    'TOTAL SELLING AND ADMIN EXPENSES',
    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
    'EARNINGS BEFORE INTEREST & TAXES',
    'EARNINGS BEFORE TAXES',
    'TOTAL NET INCOME/LOSS',
];

foreach ($data_rows as $item) {

    // Manual spacer
    if (isset($item['is_manual_spacer']) && $item['is_manual_spacer']) {
        $sheet->getRowDimension($row)->setRowHeight(15);
        $row++;
        continue;
    }

    // Section header
    if (!empty($item['is_section_header'])) {
        $label = $item['sub_order'] ?: $item['sort_order'];
        $sheet->setCellValue("A$row", $label);
        $sheet->mergeCells("A$row:N$row");
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFA973');
        $row++;
        continue;
    }

    $is_sum = $item['is_summary_row'] ?? false;

    if ($is_sum) {
        $label = $item['sort_order'];

        // Col A: numeric sort orders ≤ 25 are blank (description goes in B), specials go in A
        $sheet->setCellValue("A$row", (is_numeric($label) && (int)$label <= 25) ? '' : $label);
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');

        // Set amount values
        $sheet->setCellValue("E$row", $item['primary_total']);
        $sheet->setCellValue("F$row", $item['previous_total']);
        $sheet->setCellValue("G$row", $item['third_total']);
        $sheet->setCellValue("I$row", $item['inc_dec']);
        $sheet->setCellValue("K$row", $item['inc_dec_third']);

        // Percentage columns - store as decimal for Excel percentage formatting
        foreach ([['J', 'percentage'], ['L', 'percentage_third']] as [$col, $field]) {
            $pct = $item[$field] ?? 0;
            if (abs($pct) >= 1000) {
                $sheet->setCellValue($col . $row, 'mat');
            } else {
                $sheet->setCellValue($col . $row, $pct);
            }
        }

        // Apply amount formatting (comma separated, 2 decimals)
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $val = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($val) && $val < 0) {
                $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }

        // Apply percentage formatting
        foreach (['J', 'L'] as $col) {
            $val = $sheet->getCell($col . $row)->getValue();
            if ($val === 'mat') {
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                if (is_numeric($val) && $val < 0) {
                    $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                }
            }
        }

        $sheet->getStyle("A$row:N$row")->getFont()->setBold(true);

        // Background
        if (in_array($label, $highlight_labels, true)) {
            $bg = 'FFFFA973';
        } elseif (is_numeric($label) && (int)$label % 2 != 0 && (int)$label <= 22) {
            $bg = null; // odd revenue rows: no background
        } else {
            $bg = 'FFFDE9D9';
        }
        if ($bg) {
            $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        }

        // Borders
        if ($label == '23') {
            $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }
        if (in_array($label, ["EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES'], true)) {
            $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }
        if ($label === 'TOTAL NET INCOME/LOSS') {
            $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        }

        // Outline grouping for revenue summary rows (sort 1-22)
        if (is_numeric($label) && (int)$label >= 1 && (int)$label <= 22) {
            $row++;
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
            // This spacer row is invisible; don't write content, just advance
            $row++;
            continue;
        }

    } else {
        // Detail row
        if ((int)$item['sort_order'] === 17 && in_array((int)$item['sub_order'], [3, 4, 5, 6])) {
            $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        } else {
            $sheet->setCellValue("C$row", $item['gl_description'] ?? '');
        }
        $sheet->setCellValue("E$row", $item['primary_total']);
        $sheet->setCellValue("F$row", $item['previous_total']);
        $sheet->setCellValue("G$row", $item['third_total']);

        // Variance computed from stored (already INJ-2-flipped) values
        $p    = floatval($item['primary_total']);
        $prev = floatval($item['previous_total']);
        $t    = floatval($item['third_total']);

        $diff1 = $p - $prev;
        $pct1  = ($prev != 0) ? ($diff1 / abs($prev)) * 100 : ($p != 0 ? 100 : 0);
        $sheet->setCellValue("I$row", $diff1);
        if (abs($pct1) >= 1000) {
            $sheet->setCellValue("J$row", 'mat');
        } else {
            $sheet->setCellValue("J$row", $pct1);
        }

        $diff2 = $p - $t;
        $pct2  = (!empty($third_period) && $t != 0) ? ($diff2 / abs($t)) * 100 : (!empty($third_period) && $p != 0 ? 100 : 0);
        $sheet->setCellValue("K$row", $diff2);
        if (abs($pct2) >= 1000) {
            $sheet->setCellValue("L$row", 'mat');
        } else {
            $sheet->setCellValue("L$row", $pct2);
        }

        // Apply amount formatting for detail rows
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $val = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($val) && $val < 0) {
                $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }

        // Apply percentage formatting for detail rows
        foreach (['J', 'L'] as $col) {
            $val = $sheet->getCell($col . $row)->getValue();
            if ($val === 'mat') {
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                if (is_numeric($val) && $val < 0) {
                    $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                }
            }
        }

        // Detail rows for sort 1-22 are grouped/hidden
        if (is_numeric($item['sort_order']) && (int)$item['sort_order'] >= 1 && (int)$item['sort_order'] <= 22) {
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
        }
    }

    // Black right-border column O (cosmetic separator)
    // $sheet->getStyle("O$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');

    $row++;
}

// ── Output ────────────────────────────────────────────────────────────────────

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Comparative_Report_With_HO_Allocated_' . date('Y-m-d') . '.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
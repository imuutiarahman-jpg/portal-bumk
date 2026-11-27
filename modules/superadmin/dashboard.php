<?php
require_once __DIR__ . '/../../config/app.php';
require_login();
require_role(['super_admin']);

/* Tampilkan pesan error yang jelas (bukan 500 kosong) bila masih ada masalah. */
set_exception_handler(static function (Throwable $ex): void {
    if (!headers_sent()) http_response_code(500);
    echo '<div class="alert alert-danger m-4"><b>Dashboard gagal dimuat.</b><br><small>' . e($ex->getMessage()) . '</small></div>';
});

$title = 'Ringkasan Omzet Gabungan';
$activeUnit = null;

/* ------------------------------------------------------------------
   Pengaman: bagian yang belum tersedia di DB hosting dilewati, bukan error.
   ------------------------------------------------------------------ */
$saIssues = [];
$saSafe = static function (string $label, callable $fn, $fallback) use (&$saIssues) {
    try {
        return $fn();
    } catch (Throwable $e) {
        $saIssues[] = $label . ': ' . $e->getMessage();
        return $fallback;
    }
};
$saTable = static function (string $table): bool {
    try {
        return (bool) val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    } catch (Throwable $e) {
        return false;
    }
};
$saColumn = static function (string $table, string $column): bool {
    try {
        return (bool) val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
    } catch (Throwable $e) {
        return false;
    }
};

/* ==================================================================
   Filter periode (default: tahun berjalan s/d hari ini)
   ================================================================== */
$today = date('Y-m-d');
$cy = (int) date('Y');
$yearStart = $cy . '-01-01';
$monthStart = date('Y-m-01');
$threeMonthStart = date('Y-m-d', strtotime('-2 months', strtotime($monthStart)));

$from = $yearStart;
$to = $today;
$fromRaw = (string) ($_GET['from'] ?? '');
$toRaw = (string) ($_GET['to'] ?? '');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
    $from = $fromRaw;
    $to = $toRaw;
    if ($from > $to) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }
}
if (!strtotime($from)) $from = $yearStart;
if (!strtotime($to)) $to = $today;

$presets = [
    'Bulan ini' => [$monthStart, $today],
    '3 Bulan'   => [$threeMonthStart, $today],
    'Tahun ini' => [$yearStart, $today],
    (string) ($cy - 1) => [($cy - 1) . '-01-01', ($cy - 1) . '-12-31'],
    (string) $cy       => [$cy . '-01-01', $cy . '-12-31'],
];

/* Sumber data tiap unit (dipakai berulang, aman per unit). */
$unitSources = [
    'qmas' => ['table' => 'qmas_transactions', 'date' => 'transaction_date', 'filter' => ''],
    'smcorner' => ['table' => 'sm_transactions', 'date' => 'transaction_date', 'filter' => ''],
    'daqam' => ['table' => 'daqam_orders', 'date' => 'order_date', 'filter' => "status='selesai' AND "],
];

/* ==================================================================
   Omzet & transaksi per unit
   ================================================================== */
$byCode = [];
foreach ($unitSources as $code => $src) {
    $o = $saSafe('Omzet ' . $code, function () use ($src, $from, $to) {
        return row('SELECT COALESCE(SUM(total_amount),0) omzet, COUNT(*) trx FROM ' . $src['table']
            . ' WHERE ' . $src['filter'] . 'DATE(' . $src['date'] . ') BETWEEN ? AND ?', [$from, $to]);
    }, ['omzet' => 0, 'trx' => 0]);
    $byCode[$code] = ['omzet' => (float) ($o['omzet'] ?? 0), 'trx' => (int) ($o['trx'] ?? 0)];
}
$grandOmzet = 0.0;
$grandTrx = 0;
foreach ($byCode as $v) {
    $grandOmzet += $v['omzet'];
    $grandTrx += $v['trx'];
}
$avgTrx = $grandTrx > 0 ? $grandOmzet / $grandTrx : 0.0;

$unitMeta = [];
foreach (['qmas', 'smcorner', 'daqam'] as $code) {
    $u = unit_by_code($code);
    $unitMeta[$code] = $u['name'] ?? $code;
}

$todayByCode = [];
foreach ($unitSources as $code => $src) {
    $todayByCode[$code] = (float) $saSafe('Omzet hari ini ' . $code, function () use ($src) {
        return val('SELECT COALESCE(SUM(total_amount),0) FROM ' . $src['table']
            . ' WHERE ' . $src['filter'] . 'DATE(' . $src['date'] . ')=CURDATE()');
    }, 0);
}
$todayOmzet = array_sum($todayByCode);

/* ==================================================================
   Laba (Qmas + SM Corner; dilewati bila kolom unit_cost belum ada)
   ================================================================== */
$labaQ = 0.0;
$labaS = 0.0;
if ($saColumn('qmas_transaction_items', 'unit_cost')) {
    $labaQ = (float) $saSafe('Laba Qmas', function () use ($from, $to) {
        return val("SELECT COALESCE(SUM(i.subtotal - i.quantity*i.unit_cost),0)
            FROM qmas_transaction_items i JOIN qmas_transactions t ON t.id=i.transaction_id
            WHERE DATE(t.transaction_date) BETWEEN ? AND ?", [$from, $to]);
    }, 0);
} else {
    $saIssues[] = 'Laba Qmas dilewati (kolom unit_cost belum ada)';
}
if ($saColumn('sm_transaction_items', 'unit_cost')) {
    $labaS = (float) $saSafe('Laba SM Corner', function () use ($from, $to) {
        return val("SELECT COALESCE(SUM(i.subtotal - i.quantity*i.unit_cost),0)
            FROM sm_transaction_items i JOIN sm_transactions t ON t.id=i.transaction_id
            WHERE DATE(t.transaction_date) BETWEEN ? AND ?", [$from, $to]);
    }, 0);
} else {
    $saIssues[] = 'Laba SM Corner dilewati (kolom unit_cost belum ada)';
}
$grandLaba = $labaQ + $labaS;
$margin = $grandOmzet > 0 ? ($grandLaba / $grandOmzet) * 100 : 0.0;

/* ==================================================================
   Piutang pelanggan & hutang supplier
   ================================================================== */
$piutang = (float) $saSafe('Piutang Qmas', function () use ($from, $to) {
    return val('SELECT COALESCE(SUM(total_amount),0) FROM qmas_transactions
        WHERE payment_status="piutang" AND DATE(transaction_date) BETWEEN ? AND ?', [$from, $to]);
}, 0);
$piutangNota = (int) $saSafe('Jumlah nota piutang', function () use ($from, $to) {
    return val('SELECT COUNT(*) FROM qmas_transactions
        WHERE payment_status="piutang" AND DATE(transaction_date) BETWEEN ? AND ?', [$from, $to]);
}, 0);

$hutang = 0.0;
$hutangNota = 0;
foreach (['qmas' => 'qmas_purchases', 'smcorner' => 'sm_purchases'] as $label => $table) {
    if (!$saTable($table)) {
        $saIssues[] = 'Hutang ' . $label . ' dilewati (tabel ' . $table . ' belum ada)';
        continue;
    }
    $hutang += (float) $saSafe('Hutang ' . $label, function () use ($table, $from, $to) {
        return val('SELECT COALESCE(SUM(total_amount),0) FROM ' . $table
            . ' WHERE payment_status="piutang" AND DATE(purchase_date) BETWEEN ? AND ?', [$from, $to]);
    }, 0);
    $hutangNota += (int) $saSafe('Nota hutang ' . $label, function () use ($table, $from, $to) {
        return val('SELECT COUNT(*) FROM ' . $table
            . ' WHERE payment_status="piutang" AND DATE(purchase_date) BETWEEN ? AND ?', [$from, $to]);
    }, 0);
}
$tagihan = $piutang + $hutang;

/* ==================================================================
   Tren omzet & laba
   ================================================================== */
$trendOmzet = $saSafe('Tren omzet', function () use ($from, $to) {
    return all("SELECT DATE_FORMAT(waktu,'%Y-%m') periode, SUM(total) omzet FROM (
          SELECT transaction_date waktu, total_amount total FROM qmas_transactions WHERE DATE(transaction_date) BETWEEN ? AND ?
          UNION ALL SELECT transaction_date, total_amount FROM sm_transactions WHERE DATE(transaction_date) BETWEEN ? AND ?
          UNION ALL SELECT order_date, total_amount FROM daqam_orders WHERE status='selesai' AND DATE(order_date) BETWEEN ? AND ?
        ) x GROUP BY periode ORDER BY periode", [$from, $to, $from, $to, $from, $to]);
}, []);

$trendLaba = [];
if ($grandLaba != 0 || ($saColumn('qmas_transaction_items', 'unit_cost') && $saColumn('sm_transaction_items', 'unit_cost'))) {
    $trendLaba = $saSafe('Tren laba', function () use ($from, $to) {
        return all("SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') periode, SUM(i.subtotal - i.quantity*i.unit_cost) laba
              FROM qmas_transaction_items i JOIN qmas_transactions t ON t.id=i.transaction_id
              WHERE DATE(t.transaction_date) BETWEEN ? AND ? GROUP BY periode
            UNION ALL
              SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') AS periode, SUM(i.subtotal - i.quantity*i.unit_cost) AS laba
              FROM sm_transaction_items i JOIN sm_transactions t ON t.id=i.transaction_id
              WHERE DATE(t.transaction_date) BETWEEN ? AND ? GROUP BY periode", [$from, $to, $from, $to]);
    }, []);
}

$trendMap = [];
foreach ($trendOmzet as $r) {
    $trendMap[$r['periode']] = ['omzet' => (float) $r['omzet'], 'laba' => 0.0];
}
foreach ($trendLaba as $r) {
    if (!isset($trendMap[$r['periode']])) $trendMap[$r['periode']] = ['omzet' => 0.0, 'laba' => 0.0];
    $trendMap[$r['periode']]['laba'] = (float) $r['laba'];
}
ksort($trendMap);
$trendLabels = [];
$trendOmzetData = [];
$trendLabaData = [];
foreach ($trendMap as $periode => $v) {
    $trendLabels[] = date('M Y', strtotime($periode . '-01'));
    $trendOmzetData[] = $v['omzet'];
    $trendLabaData[] = $v['laba'];
}

/* ==================================================================
   Komposisi pembayaran seluruh unit
   ================================================================== */
$methodLabel = static function (string $status): string {
    return $status === 'qris' ? 'QRIS' : ucfirst($status);
};

$payRows = $saSafe('Komposisi pembayaran', function () use ($from, $to) {
    return all("SELECT " . qmas_laporan_status_sql() . " status, bank, total_amount FROM qmas_transactions
            WHERE DATE(transaction_date) BETWEEN ? AND ?
        UNION ALL
        SELECT payment_method, NULL, total_amount FROM sm_transactions WHERE DATE(transaction_date) BETWEEN ? AND ?
        UNION ALL
        SELECT payment_method, NULL, total_amount FROM daqam_orders
            WHERE status='selesai' AND DATE(order_date) BETWEEN ? AND ?",
        [$from, $to, $from, $to, $from, $to]);
}, []);
$payMap = [];
foreach ($payRows as $r) {
    $label = $r['bank'] ? payment_label((string) $r['status'], $r['bank']) : $methodLabel((string) $r['status']);
    $payMap[$label] = ($payMap[$label] ?? 0) + (float) $r['total_amount'];
}
arsort($payMap);
$payLabels = array_keys($payMap);
$payData = array_values($payMap);

/* ==================================================================
   Transaksi terbaru seluruh unit (dalam rentang)
   ================================================================== */
$recent = $saSafe('Transaksi terbaru', function () use ($from, $to) {
    return all("SELECT * FROM (
          SELECT 'Qmas Air Mineral' unit, transaction_no no, transaction_date waktu, customer_name nama,
                 total_amount total, payment_status status, bank, 'qmas' code
            FROM qmas_transactions WHERE DATE(transaction_date) BETWEEN ? AND ?
          UNION ALL
          SELECT 'SM Corner', transaction_no, transaction_date, customer_name, total_amount,
                 payment_method, NULL, 'smcorner'
            FROM sm_transactions WHERE DATE(transaction_date) BETWEEN ? AND ?
          UNION ALL
          SELECT 'Daqam Caffe', order_no, order_date, CONCAT(order_type,' ',IFNULL(table_no,'')),
                 total_amount, payment_method, NULL, 'daqam'
            FROM daqam_orders WHERE status='selesai' AND DATE(order_date) BETWEEN ? AND ?
        ) x ORDER BY waktu DESC LIMIT 10", [$from, $to, $from, $to, $from, $to]);
}, []);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <div class="section-eyebrow">Pimpinan BUMK UMGO</div>
        <h1 class="section-title"><i class="bi bi-bar-chart-line text-success"></i> Ringkasan Omzet Gabungan</h1>
        <div class="page-sub"><i class="bi bi-calendar3"></i> <?= e($from) ?> s/d <?= e($to) ?> · Data seluruh unit usaha</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= base_url('/modules/pengaturan/akun.php') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-people"></i> Kelola Akun</a>
        <a href="<?= base_url('/modules/portal/index.php') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Portal</a>
    </div>
</div>

<?php if ($saIssues): ?>
<div class="alert alert-warning small">
    <b>Catatan data:</b> beberapa bagian tidak dapat dihitung dan ditampilkan sebagai 0.<br>
    <?= implode('<br>', array_map('e', $saIssues)) ?>
</div>
<?php endif; ?>

<div class="card content-card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold" for="tgl_from">Dari</label>
                <input type="date" id="tgl_from" name="from" class="form-control form-control-sm" value="<?= e($from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold" for="tgl_to">Sampai</label>
                <input type="date" id="tgl_to" name="to" class="form-control form-control-sm" value="<?= e($to) ?>">
            </div>
            <div class="col-md-auto"><button class="btn btn-sm btn-success"><i class="bi bi-funnel"></i> Tampilkan</button></div>
        </form>
        <div class="mt-3 d-flex flex-wrap gap-2">
            <?php foreach ($presets as $label => [$pFrom, $pTo]): ?>
                <a href="<?= base_url('/modules/superadmin/dashboard.php?from=' . $pFrom . '&to=' . $pTo) ?>"
                   class="btn btn-sm <?= ($from === $pFrom && $to === $pTo) ? 'btn-success' : 'btn-outline-secondary' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$kpiCard = static function (string $label, string $value, string $foot, string $icon, string $iconClass, string $valueClass, string $trend = '', bool $danger = false): void {
    echo '<div class="col-md-3"><div class="card kpi-card p-3">';
    echo '<div class="d-flex justify-content-between"><span class="kpi-label">' . e($label) . '</span>';
    echo '<span class="kpi-icon ' . e($iconClass) . '"><i class="bi ' . e($icon) . '"></i></span></div>';
    echo '<div class="kpi-value ' . e($valueClass) . '">' . e($value) . '</div>';
    if ($foot !== '') echo '<div class="kpi-foot">' . e($foot) . '</div>';
    if ($trend !== '') {
        echo '<div class="kpi-trend' . ($danger ? ' danger' : '') . '"><i class="bi ' . ($danger ? 'bi-exclamation-triangle' : 'bi-check-circle') . '"></i> ' . e($trend) . '</div>';
    }
    echo '</div></div>';
};
?>

<div class="row g-3 mb-4">
    <?php
    $kpiCard('OMZET PERIODE', rupiah($grandOmzet), 'Hari ini ' . rupiah($todayOmzet), 'bi-cash-coin', 'bg-success-subtle text-success', 'text-success');
    $kpiCard('LABA PERIODE', rupiah($grandLaba), 'Margin ' . number_format($margin, 1, ',', '.') . '% · Qmas + SM', 'bi-graph-up-arrow', 'bg-primary-subtle text-primary', 'text-success');
    $kpiCard('TOTAL TRANSAKSI', angka($grandTrx) . ' trx', 'Rata-rata ' . rupiah($avgTrx) . ' / transaksi', 'bi-receipt', 'bg-warning-subtle text-warning', '');
    $kpiCard('PIUTANG & HUTANG', rupiah($tagihan), 'Piutang ' . rupiah($piutang) . ' · Hutang ' . rupiah($hutang), 'bi-exclamation-triangle', 'bg-danger-subtle text-danger', 'text-danger', $tagihan > 0 ? ($piutangNota + $hutangNota) . ' nota perlu ditindaklanjuti' : 'Tidak ada tagihan', $tagihan > 0);
    ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-graph-up"></i> Tren Omzet & Laba</div>
            <div class="chart-box"><?= chart('line', $trendLabels, [
                ['label' => 'Omzet', 'data' => $trendOmzetData],
                ['label' => 'Laba', 'data' => $trendLabaData],
            ]) ?></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card content-card h-100"><div class="card-body">
            <div class="chart-title"><i class="bi bi-pie-chart"></i> Komposisi Pembayaran (Semua Unit)</div>
            <div class="chart-box"><?= chart('doughnut', $payLabels, [['label' => 'Total', 'data' => $payData]]) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card content-card h-100">
            <div class="card-header">Kontribusi Omzet per Unit</div>
            <div class="card-body p-4">
                <?php foreach (['qmas', 'smcorner', 'daqam'] as $code):
                    $v = $byCode[$code] ?? ['omzet' => 0.0, 'trx' => 0];
                    $pct = $grandOmzet > 0 ? ($v['omzet'] / $grandOmzet) * 100 : 0;
                ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-semibold"><?= e($unitMeta[$code]) ?></span>
                            <span><?= rupiah($v['omzet']) ?></span>
                        </div>
                        <div class="progress" style="height:10px">
                            <div class="progress-bar" role="progressbar" style="width:<?= min(100, $pct) ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1">Hari ini <?= rupiah($todayByCode[$code] ?? 0) ?> · <?= angka($v['trx']) ?> transaksi</div>
                    </div>
                <?php endforeach; ?>
                <hr>
                <div class="d-flex justify-content-between fw-bold"><span>Total Periode</span><span class="text-success"><?= rupiah($grandOmzet) ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card content-card">
            <div class="card-header d-flex justify-content-between align-items-center">Transaksi Terbaru (Seluruh Unit)<span class="badge bg-light text-dark">10 terakhir</span></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light"><tr><th>Unit</th><th>No. Transaksi</th><th>Waktu</th><th>Pelanggan</th><th class="text-end">Total</th><th>Ket.</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?><tr><td colspan="6" class="empty-state">Belum ada transaksi pada rentang ini.</td></tr><?php endif; ?>
                    <?php foreach ($recent as $r):
                        $ket = $r['code'] === 'qmas'
                            ? payment_label(qmas_laporan_status((string) $r['status'], $r['waktu']), $r['bank'])
                            : $methodLabel((string) $r['status']);
                    ?>
                        <tr>
                            <td><span class="badge text-white" style="background:var(--brand-teal)"><?= e(explode(' ', $r['unit'])[0]) ?></span></td>
                            <td class="small"><?= e($r['no']) ?></td>
                            <td class="small"><?= tgl($r['waktu'], true) ?></td>
                            <td class="small"><?= e($r['nama']) ?></td>
                            <td class="text-end fw-semibold"><?= rupiah($r['total']) ?></td>
                            <td class="small"><?= e($ket) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card content-card mt-3">
    <div class="card-header"><i class="bi bi-lightning-charge"></i> Akses Cepat</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-success" href="<?= base_url('/modules/qmas/dashboard.php') ?>"><i class="bi bi-droplet"></i> Dashboard Qmas</a>
        <a class="btn btn-sm btn-outline-success" href="<?= base_url('/modules/smcorner/kasir.php') ?>"><i class="bi bi-cart3"></i> Kasir SM Corner</a>
        <a class="btn btn-sm btn-outline-success" href="<?= base_url('/modules/daqam/kasir.php') ?>"><i class="bi bi-cup-hot"></i> Kasir Daqam</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= base_url('/modules/pengaturan/akun.php') ?>"><i class="bi bi-people"></i> Kelola Akun</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

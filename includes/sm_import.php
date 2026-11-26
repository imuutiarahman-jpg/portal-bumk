<?php
/**
 * Import penuh katalog SM Corner dari Google Sheets (semua tab).
 * Dipakai oleh modules/smcorner/import_katalog.php.
 */

/** Pastikan tabel & kolom pendukung tersedia (aman dijalankan berulang). */
function sm_import_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $tables = [
        'sm_product_variants' => "CREATE TABLE IF NOT EXISTS `sm_product_variants` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `import_batch` VARCHAR(80) NULL,
            `source_key` VARCHAR(60) NULL,
            `size_name` VARCHAR(30) NOT NULL DEFAULT '-',
            `color_hex` VARCHAR(20) NULL,
            `stock` INT NOT NULL DEFAULT 0,
            `price` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `min_stock` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_smv_product` (`product_id`),
            INDEX `idx_smv_batch` (`import_batch`),
            FOREIGN KEY (`product_id`) REFERENCES `sm_products`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'sm_stock_history' => "CREATE TABLE IF NOT EXISTS `sm_stock_history` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `import_batch` VARCHAR(80) NULL,
            `source_log_id` VARCHAR(40) NULL,
            `source_product_key` VARCHAR(60) NULL,
            `product_id` INT NULL,
            `log_time` DATETIME NULL,
            `size_name` VARCHAR(30) NULL,
            `change_qty` INT NOT NULL DEFAULT 0,
            `remaining_stock` INT NOT NULL DEFAULT 0,
            `note` VARCHAR(255) NULL,
            `actor` VARCHAR(60) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_smsh_batch` (`import_batch`),
            INDEX `idx_smsh_product` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'sm_import_batches' => "CREATE TABLE IF NOT EXISTS `sm_import_batches` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `import_batch` VARCHAR(80) NOT NULL UNIQUE,
            `sheet_id` VARCHAR(80) NULL,
            `export_date` VARCHAR(60) NULL,
            `filename` VARCHAR(200) NULL,
            `category_count` INT NOT NULL DEFAULT 0,
            `product_count` INT NOT NULL DEFAULT 0,
            `variant_count` INT NOT NULL DEFAULT 0,
            `summary_count` INT NOT NULL DEFAULT 0,
            `history_count` INT NOT NULL DEFAULT 0,
            `imported_by` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $name => $sql) {
        if (!val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name])) {
            $pdo->exec($sql);
        }
    }

    $columns = [
        'sm_categories' => [
            'source_key' => 'VARCHAR(60) NULL',
            'default_sizes' => 'VARCHAR(120) NULL',
            'import_batch' => 'VARCHAR(80) NULL',
        ],
        'sm_products' => [
            'source_key' => 'VARCHAR(60) NULL',
            'import_batch' => 'VARCHAR(80) NULL',
            'color' => 'VARCHAR(40) NULL',
            'sizes' => 'VARCHAR(120) NULL',
            'min_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'max_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'source_created_at' => 'DATETIME NULL',
        ],
        'sm_transaction_items' => ['variant_id' => 'BIGINT NULL'],
        'sm_purchase_items' => ['variant_id' => 'BIGINT NULL'],
    ];
    foreach ($columns as $table => $defs) {
        foreach ($defs as $col => $def) {
            $exists = val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $col]);
            if (!$exists) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
        }
    }

    $indexes = [
        ['sm_categories', 'idx_smc_source', 'source_key'],
        ['sm_products', 'idx_smp_source', 'source_key'],
        ['sm_products', 'idx_smp_batch', 'import_batch'],
    ];
    foreach ($indexes as [$table, $index, $col]) {
        $exists = val('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?', [$table, $index]);
        if (!$exists) $pdo->exec("CREATE INDEX `{$index}` ON `{$table}` (`{$col}`)");
    }
}

/** Cari tab berisi semua kata kunci pada satu baris header. */
function sm_import_find_tab(array $sheets, array $keywords): ?array
{
    foreach ($sheets as $sheet) {
        foreach ($sheet['rows'] as $i => $row) {
            $joined = strtoupper(implode(' ', array_map('strval', $row)));
            $ok = true;
            foreach ($keywords as $k) {
                if (strpos($joined, strtoupper($k)) === false) { $ok = false; break; }
            }
            if ($ok) return ['name' => $sheet['name'], 'rows' => $sheet['rows'], 'header_index' => $i, 'header' => $row];
        }
    }
    return null;
}

/** Cari huruf kolom berdasarkan nama header. */
function sm_import_col(array $header, array $aliases): ?string
{
    foreach ($aliases as $alias) {
        foreach ($header as $col => $value) {
            if (strtoupper(trim((string) $value)) === strtoupper($alias)) return (string) $col;
        }
    }
    return null;
}

function sm_import_cell(array $row, ?string $col): string
{
    return $col === null ? '' : trim((string) ($row[$col] ?? ''));
}

function sm_import_num($value): float
{
    return (float) preg_replace('/[^\d.\-]/', '', (string) $value);
}

/** Fallback CSV: hanya blok ringkasan (format lama). */
function sm_import_parse_csv(string $path, string $sourceName, string $sheetId, array &$errors): array
{
    $handle = fopen($path, 'r');
    if (!$handle) { $errors[] = 'File CSV tidak bisa dibaca.'; return []; }
    $firstLine = fgets($handle) ?: '';
    rewind($handle);
    $delim = substr_count($firstLine, ',') >= substr_count($firstLine, ';') ? ',' : ';';
    $rows = [];
    while (($cols = fgetcsv($handle, 0, $delim)) !== false) {
        $cols = array_map('trim', $cols);
        if (!$rows) $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0] ?? '');
        $rows[] = $cols;
    }
    fclose($handle);

    $exportDate = '';
    $catIdx = null;
    foreach ($rows as $row) {
        $joined = implode(' ', $row);
        if ($exportDate === '' && stripos($joined, 'Tanggal Ekspor:') !== false && preg_match('/Tanggal Ekspor:\s*(.+)$/i', $joined, $m)) {
            $exportDate = trim($m[1]);
        }
        foreach ($row as $i => $cell) {
            if (strtoupper($cell) === 'KATEGORI') $catIdx = $i;
        }
    }
    if ($catIdx === null) {
        $errors[] = 'Header "Kategori" tidak ditemukan pada file CSV.';
        return [];
    }

    $summary = [];
    $started = false;
    foreach ($rows as $row) {
        if (!$started) {
            if (stripos(implode(' ', $row), 'REKAPITULASI PRODUK & STOK PER KATEGORI') !== false) $started = true;
            continue;
        }
        $cat = trim((string) ($row[$catIdx] ?? ''));
        if ($cat === '' || strtoupper($cat) === 'KATEGORI') continue;
        $isTotal = stripos($cat, 'TOTAL KESELURUHAN') !== false;
        $summary[] = [
            'category' => $isTotal ? 'TOTAL KESELURUHAN' : $cat,
            'product_count' => sm_import_num($row[$catIdx + 1] ?? 0),
            'variant_count' => sm_import_num($row[$catIdx + 2] ?? 0),
            'stock_total' => sm_import_num($row[$catIdx + 3] ?? 0),
            'value' => sm_import_num($row[$catIdx + 4] ?? 0),
            'avg' => sm_import_num($row[$catIdx + 5] ?? 0),
            'is_total' => $isTotal ? 1 : 0,
        ];
    }
    if (!$summary) {
        $errors[] = 'Tidak ada baris kategori yang bisa dibaca dari CSV.';
        return [];
    }

    $categories = [];
    foreach ($summary as $s) {
        if (!$s['is_total']) $categories[] = ['source_key' => $s['category'], 'name' => $s['category'], 'default_sizes' => ''];
    }
    return [
        'sheet_id' => $sheetId, 'export_date' => $exportDate,
        'batch' => 'csv-' . $sheetId . '-' . md5($exportDate . count($summary)),
        'filename' => $sourceName, 'categories' => $categories,
        'products' => [], 'variants' => [], 'history' => [], 'summary' => $summary,
        'warnings' => ['CSV hanya memuat tab ringkasan. Gunakan link Google Sheets untuk mengambil semua tab.'],
        'counts' => ['categories' => count($categories), 'products' => 0, 'variants' => 0,
            'history' => 0, 'summary' => count($summary)],
    ];
}

/**
 * Ubah hasil sheet_read_xlsx menjadi data siap pratinjau/import.
 * Return: array terstruktur + warnings + counts.
 */
function sm_import_parse(array $sheets, string $sheetId): array
{
    $tabs = [
        'katalog' => sm_import_find_tab($sheets, ['Kode Barang', 'Nama Produk', 'Rincian Stok Ukuran']),
        'stok' => sm_import_find_tab($sheets, ['Harga Satuan', 'Stok Unit', 'Kode Hex']),
        'kategori' => sm_import_find_tab($sheets, ['Nama Kategori', 'Ukuran Default']),
        'riwayat' => sm_import_find_tab($sheets, ['Sisa Stok', 'Perubahan', 'Tanggal & Waktu']),
        'ringkasan' => sm_import_find_tab($sheets, ['Jumlah Produk', 'Rata-rata Harga Varian']),
    ];

    $exportDate = '';
    foreach ($sheets as $sheet) {
        foreach ($sheet['rows'] as $row) {
            $joined = implode(' ', array_map('strval', $row));
            if (stripos($joined, 'Tanggal Ekspor:') !== false && preg_match('/Tanggal Ekspor:\s*(.+)$/i', $joined, $m)) {
                $exportDate = trim($m[1]);
                break 2;
            }
        }
    }

    $result = [
        'sheet_id' => $sheetId,
        'export_date' => $exportDate,
        'batch' => 'sheet-' . $sheetId . '-' . md5($exportDate . '|' . count($sheets)),
        'categories' => [], 'products' => [], 'variants' => [],
        'history' => [], 'summary' => [], 'warnings' => [],
    ];

    $tabRows = [];
    foreach ($tabs as $key => $tab) {
        $tabRows[$key] = $tab ? array_slice($tab['rows'], $tab['header_index'] + 1) : [];
    }

    /* --- Master Kategori --- */
    if ($tabs['kategori']) {
        $h = $tabs['kategori']['header'];
        $cId = sm_import_col($h, ['ID Kategori']);
        $cName = sm_import_col($h, ['Nama Kategori']);
        $cSize = sm_import_col($h, ['Ukuran Default']);
        foreach ($tabRows['kategori'] as $row) {
            $name = sm_import_cell($row, $cName);
            if ($name === '' || stripos($name, 'TOTAL') === 0 || $name === 'Nama Kategori') continue;
            $result['categories'][] = [
                'source_key' => sm_import_cell($row, $cId) ?: $name,
                'name' => $name,
                'default_sizes' => sm_import_cell($row, $cSize),
            ];
        }
    }

    /* --- Katalog Produk --- */
    $productBySku = [];
    if ($tabs['katalog']) {
        $h = $tabs['katalog']['header'];
        $c = [
            'sku' => sm_import_col($h, ['Kode Barang']),
            'category' => sm_import_col($h, ['Kategori']),
            'name' => sm_import_col($h, ['Nama Produk']),
            'color' => sm_import_col($h, ['Warna']),
            'sizes' => sm_import_col($h, ['Daftar Ukuran']),
            'price' => sm_import_col($h, ['Harga Dasar']),
            'min' => sm_import_col($h, ['Harga Min']),
            'max' => sm_import_col($h, ['Harga Max']),
            'date' => sm_import_col($h, ['Tanggal Input']),
        ];
        foreach ($tabRows['katalog'] as $row) {
            $sku = sm_import_cell($row, $c['sku']);
            $name = sm_import_cell($row, $c['name']);
            if ($sku === '' || $name === '' || stripos($name, 'TOTAL') === 0) continue;
            $date = sm_import_cell($row, $c['date']);
            $item = [
                'source_key' => $sku, 'sku' => $sku,
                'category_name' => sm_import_cell($row, $c['category']),
                'name' => $name,
                'color' => sm_import_cell($row, $c['color']),
                'sizes' => sm_import_cell($row, $c['sizes']),
                'price' => sm_import_num(sm_import_cell($row, $c['price'])),
                'min_price' => sm_import_num(sm_import_cell($row, $c['min'])),
                'max_price' => sm_import_num(sm_import_cell($row, $c['max'])),
                'source_created_at' => preg_match('/^\d{4}-\d{2}-\d{2}/', $date) ? substr($date, 0, 19) : null,
                'stock' => 0,
            ];
            $result['products'][$sku] = $item;
            $productBySku[$sku] = true;
        }
    }

    /* --- Detail Stok & Harga --- */
    if ($tabs['stok']) {
        $h = $tabs['stok']['header'];
        $c = [
            'id' => sm_import_col($h, ['ID Stok']),
            'sku' => sm_import_col($h, ['Kode Barang']),
            'hex' => sm_import_col($h, ['Kode Hex']),
            'size' => sm_import_col($h, ['Ukuran']),
            'stock' => sm_import_col($h, ['Stok Unit']),
            'price' => sm_import_col($h, ['Harga Satuan']),
        ];
        foreach ($tabRows['stok'] as $row) {
            $sku = sm_import_cell($row, $c['sku']);
            $size = sm_import_cell($row, $c['size']);
            if ($sku === '' || $size === '' || stripos($size, 'TOTAL') === 0) continue;
            $stock = (int) sm_import_num(sm_import_cell($row, $c['stock']));
            $price = sm_import_num(sm_import_cell($row, $c['price']));
            $result['variants'][] = [
                'source_key' => sm_import_cell($row, $c['id']) ?: ($sku . '-' . $size),
                'product_key' => $sku,
                'size' => $size,
                'color_hex' => sm_import_cell($row, $c['hex']),
                'stock' => $stock,
                'price' => $price,
            ];
            if (isset($result['products'][$sku])) $result['products'][$sku]['stock'] += $stock;
        }
    }

    /* --- Riwayat Stok --- */
    if ($tabs['riwayat']) {
        $h = $tabs['riwayat']['header'];
        $c = [
            'id' => sm_import_col($h, ['ID Log']),
            'time' => sm_import_col($h, ['Tanggal & Waktu']),
            'product' => sm_import_col($h, ['ID Produk']),
            'label' => sm_import_col($h, ['Kode & Nama Produk']),
            'size' => sm_import_col($h, ['Ukuran']),
            'change' => sm_import_col($h, ['Perubahan']),
            'remaining' => sm_import_col($h, ['Sisa Stok']),
            'note' => sm_import_col($h, ['Keterangan']),
            'actor' => sm_import_col($h, ['Oleh']),
        ];
        foreach ($tabRows['riwayat'] as $row) {
            $label = sm_import_cell($row, $c['label']);
            if ($label === '' || stripos($label, 'ID Log') === 0) continue;
            $key = '';
            if (preg_match('/(BRG-\d+)/', $label, $m)) $key = $m[1];
            $time = sm_import_cell($row, $c['time']);
            $result['history'][] = [
                'source_log_id' => sm_import_cell($row, $c['id']),
                'source_product_key' => $key,
                'source_product_id' => sm_import_cell($row, $c['product']),
                'log_time' => preg_match('/^\d{4}-\d{2}-\d{2}/', $time) ? substr($time, 0, 19) : null,
                'size' => sm_import_cell($row, $c['size']),
                'change' => (int) sm_import_num(sm_import_cell($row, $c['change'])),
                'remaining' => (int) sm_import_num(sm_import_cell($row, $c['remaining'])),
                'note' => sm_import_cell($row, $c['note']),
                'actor' => sm_import_cell($row, $c['actor']),
            ];
        }
    }

    /* --- Ringkasan & Dashboard --- */
    if ($tabs['ringkasan']) {
        $h = $tabs['ringkasan']['header'];
        $c = [
            'category' => sm_import_col($h, ['Kategori']),
            'product' => sm_import_col($h, ['Jumlah Produk']),
            'variant' => sm_import_col($h, ['Jumlah Varian']),
            'stock' => sm_import_col($h, ['Total Unit Stok']),
            'value' => sm_import_col($h, ['Total Nilai Inventori']),
            'avg' => sm_import_col($h, ['Rata-rata Harga Varian']),
        ];
        foreach ($tabRows['ringkasan'] as $row) {
            $cat = sm_import_cell($row, $c['category']);
            if ($cat === '' || $cat === 'Kategori') continue;
            $isTotal = stripos($cat, 'TOTAL') !== false;
            $result['summary'][] = [
                'category' => $isTotal ? 'TOTAL KESELURUHAN' : $cat,
                'product_count' => sm_import_num(sm_import_cell($row, $c['product'])),
                'variant_count' => sm_import_num(sm_import_cell($row, $c['variant'])),
                'stock_total' => sm_import_num(sm_import_cell($row, $c['stock'])),
                'value' => sm_import_num(sm_import_cell($row, $c['value'])),
                'avg' => sm_import_num(sm_import_cell($row, $c['avg'])),
                'is_total' => $isTotal ? 1 : 0,
            ];
        }
    }

    /* --- Hitung ulang ringkasan dari data detail (sel rumus XLSX tidak punya cache) --- */
    if ($result['products'] && $result['variants']) {
        $agg = [];
        foreach ($result['categories'] as $c) {
            $agg[$c['name']] = ['p' => 0, 'v' => 0, 'stock' => 0, 'value' => 0, 'price_sum' => 0];
        }
        $catBySku = [];
        foreach ($result['products'] as $p) {
            $cat = $p['category_name'] ?: '(kosong)';
            $catBySku[$p['sku']] = $cat;
            if (!isset($agg[$cat])) $agg[$cat] = ['p' => 0, 'v' => 0, 'stock' => 0, 'value' => 0, 'price_sum' => 0];
            $agg[$cat]['p']++;
        }
        foreach ($result['variants'] as $v) {
            $cat = $catBySku[$v['product_key']] ?? '(kosong)';
            if (!isset($agg[$cat])) $agg[$cat] = ['p' => 0, 'v' => 0, 'stock' => 0, 'value' => 0, 'price_sum' => 0];
            $agg[$cat]['v']++;
            $agg[$cat]['stock'] += $v['stock'];
            $agg[$cat]['value'] += $v['stock'] * $v['price'];
            $agg[$cat]['price_sum'] += $v['price'];
        }
        ksort($agg);
        $computed = [];
        $tp = 0; $tv = 0; $ts = 0; $tval = 0; $tprice = 0;
        foreach ($agg as $cat => $a) {
            $computed[] = [
                'category' => $cat, 'product_count' => $a['p'], 'variant_count' => $a['v'],
                'stock_total' => $a['stock'], 'value' => $a['value'],
                'avg' => $a['v'] ? $a['price_sum'] / $a['v'] : 0, 'is_total' => 0,
            ];
            $tp += $a['p']; $tv += $a['v']; $ts += $a['stock']; $tval += $a['value']; $tprice += $a['price_sum'];
        }
        $computed[] = [
            'category' => 'TOTAL KESELURUHAN', 'product_count' => $tp, 'variant_count' => $tv,
            'stock_total' => $ts, 'value' => $tval, 'avg' => $tv ? $tprice / $tv : 0, 'is_total' => 1,
        ];
        $result['summary'] = $computed;
    }

    /* --- Peringatan kualitas data --- */
    foreach ($tabs as $key => $tab) {
        if (!$tab) $result['warnings'][] = 'Tab untuk ' . $key . ' tidak ditemukan; bagian itu dilewati.';
    }
    $zero = array_filter($result['variants'], fn($v) => $v['stock'] <= 0);
    if ($zero) $result['warnings'][] = count($zero) . ' varian memiliki stok 0.';
    $byProduct = [];
    foreach ($result['variants'] as $v) $byProduct[$v['product_key']][] = $v['price'];
    foreach ($byProduct as $sku => $prices) {
        $prices = array_filter($prices, fn($p) => $p > 0);
        if (!$prices) continue;
        $ratio = max($prices) / min($prices);
        if ($ratio >= 5) $result['warnings'][] = $sku . ': rentang harga ' . round($ratio) . 'x (kemungkinan typo).';
    }
    foreach ($result['variants'] as $v) {
        if (!isset($productBySku[$v['product_key']])) {
            $result['warnings'][] = 'Varian ' . $v['source_key'] . ' merujuk ke produk ' . $v['product_key'] . ' yang tidak ada.';
            break;
        }
    }
    foreach ($result['summary'] as $s) {
        if (!$s['is_total'] && $s['product_count'] == 0) $result['warnings'][] = 'Kategori ' . $s['category'] . ' kosong (0 produk).';
    }

    $result['counts'] = [
        'categories' => count($result['categories']),
        'products' => count($result['products']),
        'variants' => count($result['variants']),
        'history' => count($result['history']),
        'summary' => count($result['summary']),
    ];
    $result['products'] = array_values($result['products']);
    return $result;
}

/** Set stok produk dan sebar selisihnya ke varian ukuran. */
function sm_import_set_product_stock(int $productId, int $newStock): void
{
    $newStock = max(0, $newStock);
    $variants = all('SELECT id, stock FROM sm_product_variants WHERE product_id=? ORDER BY stock DESC, id', [$productId]);
    if (!$variants) {
        run('UPDATE sm_products SET stock=? WHERE id=?', [$newStock, $productId]);
        return;
    }

    $delta = $newStock - (int) array_sum(array_column($variants, 'stock'));
    if ($delta > 0) {
        $weights = array_map(fn($v) => (int) $v['stock'], $variants);
        if (array_sum($weights) <= 0) $weights = array_fill(0, count($variants), 1);
        $total = array_sum($weights);
        $alloc = []; $given = 0;
        foreach ($weights as $i => $w) {
            $alloc[$i] = (int) floor($delta * $w / $total);
            $given += $alloc[$i];
        }
        for ($i = 0, $rem = $delta - $given; $i < $rem; $i++) $alloc[$i % count($alloc)]++;
        foreach ($alloc as $i => $qty) {
            if ($qty > 0) run('UPDATE sm_product_variants SET stock=stock+? WHERE id=?', [$qty, $variants[$i]['id']]);
        }
    } elseif ($delta < 0) {
        $need = -$delta;
        foreach ($variants as $v) {
            if ($need <= 0) break;
            $take = min($need, (int) $v['stock']);
            if ($take > 0) {
                run('UPDATE sm_product_variants SET stock=stock-? WHERE id=?', [$take, $v['id']]);
                $need -= $take;
            }
        }
    }

    $actual = (int) val('SELECT COALESCE(SUM(stock),0) FROM sm_product_variants WHERE product_id=?', [$productId]);
    run('UPDATE sm_products SET stock=? WHERE id=?', [$actual, $productId]);
}

/** Simpan data hasil pratinjau ke database dalam satu transaksi. */
function sm_import_commit(array $data): void
{
    sm_import_ensure_schema();
    $batch = (string) $data['batch'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        run('DELETE FROM sm_stock_history WHERE import_batch=?', [$batch]);
        run('DELETE FROM sm_product_variants WHERE import_batch=?', [$batch]);
        run('DELETE FROM sm_catalog_summary WHERE import_batch=?', [$batch]);
        run('DELETE FROM sm_import_batches WHERE import_batch=?', [$batch]);
        run('DELETE FROM sm_products WHERE import_batch=? AND id NOT IN (SELECT product_id FROM sm_transaction_items) AND id NOT IN (SELECT product_id FROM sm_purchase_items)', [$batch]);

        $catIds = [];
        foreach ($data['categories'] as $c) {
            $id = val('SELECT id FROM sm_categories WHERE source_key=?', [$c['source_key']]);
            if (!$id) $id = val('SELECT id FROM sm_categories WHERE name=?', [$c['name']]);
            if ($id) {
                run('UPDATE sm_categories SET source_key=?, default_sizes=?, import_batch=? WHERE id=?', [$c['source_key'], $c['default_sizes'], $batch, $id]);
            } else {
                run('INSERT INTO sm_categories (name,source_key,default_sizes,import_batch) VALUES (?,?,?,?)', [$c['name'], $c['source_key'], $c['default_sizes'], $batch]);
                $id = last_id();
            }
            $catIds[$c['name']] = (int) $id;
        }

        $fallbackCat = (int) val('SELECT id FROM sm_categories ORDER BY id LIMIT 1');
        $productIds = [];
        foreach ($data['products'] as $p) {
            $catId = $catIds[$p['category_name']] ?? 0;
            if (!$catId) $catId = (int) val('SELECT id FROM sm_categories WHERE name=?', [$p['category_name']]);
            if (!$catId) $catId = $fallbackCat;
            $existing = row('SELECT * FROM sm_products WHERE sku=?', [$p['sku']]);
            $cost = $existing ? (float) $existing['cost'] : 0;
            $minStock = $existing ? (int) $existing['min_stock'] : 0;
            $params = [
                $catId, $p['name'], $p['sku'], $p['sizes'] ?: '-', $p['price'], $cost,
                (int) $p['stock'], $minStock, 'pcs', $batch, $p['source_key'],
                $p['color'], $p['sizes'], $p['min_price'], $p['max_price'], $p['source_created_at'],
            ];
            if ($existing) {
                run('UPDATE sm_products SET category_id=?, name=?, sku=?, size=?, price=?, cost=?, stock=?, min_stock=?, unit=?, import_batch=?, source_key=?, color=?, sizes=?, min_price=?, max_price=?, source_created_at=? WHERE id=?',
                    array_merge($params, [$existing['id']]));
                $pid = (int) $existing['id'];
            } else {
                run('INSERT INTO sm_products (category_id,name,sku,size,price,cost,stock,min_stock,unit,import_batch,source_key,color,sizes,min_price,max_price,source_created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $params);
                $pid = last_id();
            }
            $productIds[$p['sku']] = $pid;
        }

        foreach ($data['variants'] as $v) {
            $pid = $productIds[$v['product_key']] ?? 0;
            if (!$pid) continue;
            run('INSERT INTO sm_product_variants (product_id,import_batch,source_key,size_name,color_hex,stock,price,min_stock) VALUES (?,?,?,?,?,?,?,?)',
                [$pid, $batch, $v['source_key'], $v['size'], $v['color_hex'], (int) $v['stock'], $v['price'], 0]);
        }

        foreach ($data['summary'] as $s) {
            run('INSERT INTO sm_catalog_summary (import_batch,export_date,category_name,product_count,variant_count,stock_total,inventory_value,avg_price,is_total) VALUES (?,?,?,?,?,?,?,?,?)',
                [$batch, $data['export_date'], $s['category'], $s['product_count'], $s['variant_count'], $s['stock_total'], $s['value'], $s['avg'], $s['is_total']]);
        }

        foreach ($data['history'] as $h) {
            $pid = 0;
            if ($h['source_product_key'] !== '') $pid = $productIds[$h['source_product_key']] ?? 0;
            if (!$pid && $h['source_product_id'] !== '') $pid = (int) val('SELECT id FROM sm_products WHERE source_key=?', [$h['source_product_id']]);
            run('INSERT INTO sm_stock_history (import_batch,source_log_id,source_product_key,product_id,log_time,size_name,change_qty,remaining_stock,note,actor) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$batch, $h['source_log_id'], $h['source_product_key'] ?: $h['source_product_id'], $pid ?: null, $h['log_time'], $h['size'], $h['change'], $h['remaining'], $h['note'], $h['actor']]);
        }

        $counts = $data['counts'];
        run('INSERT INTO sm_import_batches (import_batch,sheet_id,export_date,filename,category_count,product_count,variant_count,summary_count,history_count,imported_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$batch, $data['sheet_id'], $data['export_date'], 'Google Sheets', $counts['categories'], $counts['products'], $counts['variants'], $counts['summary'], $counts['history'], current_user()['id'] ?? null]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

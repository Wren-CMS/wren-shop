<?php
// Turns a WooCommerce product export (Products > Export, all columns, CSV) into a
// Wren Shop product import file, plus a report of anything that doesn't map cleanly.
//
//   php woo-to-wren.php woocommerce-export.csv wren-products.csv [report.txt] [photos.csv]
//
// - Simple products become one row; variable products one row per variation.
// - A variation with two or more attributes (Colour and Size) becomes one choice
//   named "Red / Large" with the option "Colour / Size", and is listed in the report:
//   Wren Shop 1.2 has one option per product.
// - Grouped and external products are left out and listed.
// - Descriptions: HTML is turned into Wren's plain text (paragraphs, lists, bold, italic).
// - Photos aren't imported; photos.csv lists each product's image URLs for a copying script.
// - Woo's export has no web address column, so slugs are made from names (as Woo does by default).

if ($argc < 3) {
    fwrite(STDERR, "Usage: php woo-to-wren.php woocommerce-export.csv wren-products.csv [report.txt] [photos.csv]\n");
    exit(1);
}
[$in, $out] = [$argv[1], $argv[2]];
$reportFile = $argv[3] ?? preg_replace('/\.csv$/', '', $out) . '-report.txt';
$photoFile = $argv[4] ?? preg_replace('/\.csv$/', '', $out) . '-photos.csv';

$raw = file_get_contents($in);
if (str_starts_with($raw, "\xEF\xBB\xBF")) {
    $raw = substr($raw, 3);
}
$fh = fopen('php://temp', 'r+');
fwrite($fh, $raw);
rewind($fh);
$head = fgetcsv($fh, null, ',', '"', '');
$idx = array_flip(array_map('trim', $head));
$rows = [];
while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
    if ($r === [null]) {
        continue;
    }
    $row = [];
    foreach ($idx as $name => $i) {
        $row[$name] = trim((string)($r[$i] ?? ''));
    }
    $rows[] = $row;
}
$col = fn(array $r, string $k) => $r[$k] ?? '';

function slugify(string $s): string
{
    $s = strtolower(trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $s = strtr($s, ['&' => ' and ', "'" => '', '’' => '']);
    $s = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    return trim($s, '-') ?: 'item';
}

/* Woo's HTML description as Wren's plain text: paragraphs, "- " lists, **bold**, *italic*. */
function plain(string $html): string
{
    if ($html === '') {
        return '';
    }
    $s = str_replace(["\r\n", "\r"], "\n", $html);
    $s = preg_replace('#\\\\n#', "\n", $s);   // Woo writes newlines in some cells as a literal \n
    $s = preg_replace('#<\s*(script|style)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $s);
    $s = preg_replace('#<\s*li[^>]*>#i', "\n- ", $s);
    $s = preg_replace('#<\s*br\s*/?>#i', "\n", $s);
    $s = preg_replace('#<\s*/?\s*(p|div|ul|ol|h[1-6]|table|tr)[^>]*>#i', "\n\n", $s);
    $s = preg_replace('#<\s*(strong|b)\s*>(.*?)<\s*/\s*\1\s*>#is', '**$2**', $s);
    $s = preg_replace('#<\s*(em|i)\s*>(.*?)<\s*/\s*\1\s*>#is', '*$2*', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t\x{00A0}]+/u", ' ', $s);
    $s = preg_replace("/ *\n */", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    $s = preg_replace("/^(- .*)\n\n(?=- )/m", "$1\n", $s);   // list items close together
    $s = preg_replace("/^(- .*)\n\n(?=- )/m", "$1\n", $s);   // (again, for items that were both sides of a match)
    return trim($s);
}

function price(string $s): string
{
    $s = str_replace(',', '.', trim($s));
    return is_numeric($s) ? number_format((float)$s, 2, '.', '') : '';
}

function day(string $s): string
{
    return preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($s), $m) ? $m[1] : '';
}

/* "Cars > SRX2, Spare parts" with "\," for a comma inside a name: the list of paths. */
function cat_paths(string $s): array
{
    $parts = preg_split('/(?<!\\\\),/', $s);
    return array_values(array_filter(array_map(fn($x) => trim(str_replace('\\,', ',', $x)), $parts), fn($x) => $x !== ''));
}

$report = ['multi' => [], 'skipped' => [], 'shared_stock' => [], 'no_price' => [], 'orphans' => [], 'deep' => [], 'sku_dupes' => []];
$byId = [];
$bySku = [];
foreach ($rows as $r) {
    $byId[$col($r, 'ID')] = $r;
    if ($col($r, 'SKU') !== '') {
        $bySku[$col($r, 'SKU')] = $r;
    }
}
$kids = [];
foreach ($rows as $r) {
    if (str_contains(strtolower($col($r, 'Type')), 'variation')) {
        $parent = $col($r, 'Parent');
        $pid = str_starts_with($parent, 'id:') ? substr($parent, 3) : ($bySku[$parent]['ID'] ?? '');
        if ($pid === '' || !isset($byId[$pid])) {
            $report['orphans'][] = $col($r, 'Name') . ' (' . ($col($r, 'SKU') ?: 'ID ' . $col($r, 'ID')) . ')';
            continue;
        }
        $kids[$pid][] = $r;
    }
}

$cols = ['sku', 'slug', 'name', 'price', 'sale_price', 'sale_from', 'sale_to', 'stock', 'backorders', 'visible',
         'main_category', 'categories', 'option_name', 'choice', 'choice_sku', 'choice_price', 'choice_sale_price', 'description'];
$o = fopen($out, 'w');
fwrite($o, "\xEF\xBB\xBF");
fputcsv($o, $cols, ',', '"', '');
$ph = fopen($photoFile, 'w');
fputcsv($ph, ['sku', 'slug', 'image_urls'], ',', '"', '');
$slugs = [];
$skus = [];
$count = ['simple' => 0, 'variable' => 0, 'choices' => 0];
foreach ($rows as $r) {
    $type = strtolower($col($r, 'Type'));
    if (str_contains($type, 'variation')) {
        continue;
    }
    $name = html_entity_decode($col($r, 'Name'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_contains($type, 'grouped') || str_contains($type, 'external')) {
        $report['skipped'][] = $name . ' (' . $col($r, 'Type') . ')';
        continue;
    }
    $variable = str_contains($type, 'variable');
    $slug = slugify($name);
    for ($i = 2; isset($slugs[$slug]); $i++) {
        $slug = slugify($name) . '-' . $i;
    }
    $slugs[$slug] = true;
    $paths = array_map(fn($p) => preg_replace('/\s*>\s*/', ' > ', $p), cat_paths($col($r, 'Categories')));
    foreach ($paths as $p) {
        if (substr_count($p, '>') >= 3) {
            $report['deep'][] = $name . ': ' . $p;
        }
    }
    $paths = array_map(fn($p) => implode(' > ', array_slice(explode(' > ', $p), 0, 3)), $paths);   // three levels at most
    $visible = $col($r, 'Published') === '1' && !in_array($col($r, 'Visibility in catalog'), ['hidden'], true) ? '1' : '0';
    $short = plain($col($r, 'Short description'));
    $long = plain($col($r, 'Description'));
    $desc = trim($short . ($short !== '' && $long !== '' && $long !== $short ? "\n\n" : '') . ($long !== $short ? $long : ''));
    $bo = ['1' => 'yes', 'notify' => 'notify'][$col($r, 'Backorders allowed?')] ?? 'no';
    $sku = $col($r, 'SKU');
    if ($sku !== '' && isset($skus[strtolower($sku)])) {
        $report['sku_dupes'][] = $sku . ' (' . $name . ')';
        $sku = '';
    }
    if ($sku !== '') {
        $skus[strtolower($sku)] = true;
    }
    $base = ['sku' => $sku, 'slug' => $slug, 'name' => $name, 'price' => price($col($r, 'Regular price')),
             'sale_price' => price($col($r, 'Sale price')), 'sale_from' => day($col($r, 'Date sale price starts')),
             'sale_to' => day($col($r, 'Date sale price ends')), 'stock' => $col($r, 'Stock') === '' ? '' : (string)(int)$col($r, 'Stock'),
             'backorders' => $bo, 'visible' => $visible, 'main_category' => $paths[0] ?? '', 'categories' => implode(' | ', array_slice($paths, 1)),
             'option_name' => '', 'choice' => '', 'choice_sku' => '', 'choice_price' => '', 'choice_sale_price' => '', 'description' => $desc];
    if ($col($r, 'Images') !== '') {
        fputcsv($ph, [$sku, $slug, $col($r, 'Images')], ',', '"', '');
    }
    if (!$variable) {
        if ($base['price'] === '') {
            $report['no_price'][] = $name;
            $base['visible'] = '0';
            $base['price'] = '0.00';
        }
        if ($base['sale_price'] !== '' && (float)$base['sale_price'] >= (float)$base['price']) {
            $base['sale_price'] = '';
        }
        fputcsv($o, array_values($base), ',', '"', '');
        $count['simple']++;
        continue;
    }
    // A variable product: one row per variation.
    $vs = $kids[$col($r, 'ID')] ?? [];
    if (!$vs) {
        $report['no_price'][] = $name . ' (variable, with no variations)';
        continue;
    }
    $names = [];
    for ($a = 1; $col($r, "Attribute $a name") !== ''; $a++) {
        $names[] = $col($r, "Attribute $a name");
    }
    $prices = array_filter(array_map(fn($v) => price($col($v, 'Regular price')), $vs), fn($x) => $x !== '');
    $base['price'] = $prices ? min($prices) : '0.00';
    if (!$prices) {
        $report['no_price'][] = $name;
        $base['visible'] = '0';
    }
    $base['sale_price'] = $base['sale_from'] = $base['sale_to'] = '';
    $base['option_name'] = implode(' / ', $names) ?: 'Option';
    if (count($names) > 1) {
        $report['multi'][] = $name . ' (' . implode(' × ', $names) . ', ' . count($vs) . ' variations)';
    }
    $parentStock = $base['stock'];
    $base['stock'] = '';
    $seen = [];
    foreach ($vs as $v) {
        $vals = [];
        for ($a = 1; $col($v, "Attribute $a name") !== '' || $col($v, "Attribute $a value(s)") !== ''; $a++) {
            $vals[] = $col($v, "Attribute $a value(s)") !== '' ? html_entity_decode($col($v, "Attribute $a value(s)"), ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Any';
        }
        $choice = implode(' / ', $vals) ?: trim(str_replace($name . ' - ', '', html_entity_decode($col($v, 'Name'), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        for ($i = 2, $c = $choice; isset($seen[strtolower($c)]); $i++) {
            $c = $choice . ' ' . $i;
        }
        $seen[strtolower($c)] = true;
        $vsku = $col($v, 'SKU');
        if ($vsku !== '' && (isset($skus[strtolower($vsku)]))) {
            $report['sku_dupes'][] = $vsku . ' (' . $name . ': ' . $c . ')';
            $vsku = '';
        }
        if ($vsku !== '') {
            $skus[strtolower($vsku)] = true;
        }
        $vp = price($col($v, 'Regular price')) ?: $base['price'];
        $vsale = price($col($v, 'Sale price'));
        if ($vsale !== '' && (float)$vsale >= (float)$vp) {
            $vsale = '';
        }
        $stock = $col($v, 'Stock');
        if ($stock === '' && $parentStock !== '') {
            $report['shared_stock'][] = $name;
        }
        $row = $base;
        $row['stock'] = $stock === '' ? '' : (string)(int)$stock;
        $row['choice'] = $c;
        $row['choice_sku'] = $vsku;
        $row['choice_price'] = $vp;
        $row['choice_sale_price'] = $vsale;
        if ($vsale !== '' && day($col($v, 'Date sale price starts') . $col($v, 'Date sale price ends')) !== '') {
            $row['sale_from'] = day($col($v, 'Date sale price starts'));   // Wren keeps one set of sale dates per product
            $row['sale_to'] = day($col($v, 'Date sale price ends'));
            $base['sale_from'] = $row['sale_from'];
            $base['sale_to'] = $row['sale_to'];
        }
        fputcsv($o, array_values($row), ',', '"', '');
        $count['choices']++;
    }
    $count['variable']++;
}
fclose($o);
fclose($ph);

$lines = ['Wren Shop import made from ' . basename($in) . ' on ' . date('Y-m-d H:i'), '',
          $count['simple'] . ' simple products, ' . $count['variable'] . ' products with options (' . $count['choices'] . ' choices).', ''];
$sections = [
    'multi'        => 'Products with more than one option (Wren Shop 1.2 has one): their choices are combined, like "Red / Large". A case for 1.3.',
    'shared_stock' => 'Variable products whose stock is counted on the product rather than each variation: their choices\' stock isn\'t counted in Wren. Set it per choice.',
    'skipped'      => 'Left out (grouped or external products):',
    'no_price'     => 'No price in Woo: imported hidden at £0.00, so set a price before showing them:',
    'deep'         => 'Categories more than three levels deep, cut to three:',
    'orphans'      => 'Variations whose parent product isn\'t in the file:',
    'sku_dupes'    => 'SKUs used more than once in Woo: left empty on all but the first:',
];
foreach ($sections as $k => $title) {
    $list = array_values(array_unique($report[$k]));
    if ($list) {
        $lines[] = $title . ' (' . count($list) . ')';
        foreach ($list as $x) {
            $lines[] = '  - ' . $x;
        }
        $lines[] = '';
    }
}
file_put_contents($reportFile, implode("\n", $lines) . "\n");
echo implode("\n", array_slice($lines, 0, 3)), "\nWrote $out, $reportFile and $photoFile\n";

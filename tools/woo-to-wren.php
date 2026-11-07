<?php
// Turns a WooCommerce product export (Products > Export, all columns, CSV) into a
// Wren Shop product import file, plus a report of anything that doesn't map cleanly.
//
//   php woo-to-wren.php woocommerce-export.csv wren-products.csv [report.txt] [photos.csv]
//
// - Simple products become one row; variable products one row per variation.
// - A product with two attributes (Size and Colour) gets two options, each variation one
//   pair. A variation for "any" Colour becomes a row for each colour. With three or more
//   attributes, the second and later are combined ("Red / Cotton") and listed in the report.
// - Grouped and external products are left out and listed.
// - Descriptions: HTML is turned into Wren's plain text (paragraphs, lists, bold, italic).
// - Photos: each product's image addresses go in the photos column, and the shop fetches
//   them after the import. photos.csv lists them too, for anyone copying them another way.
// - Woo's export has no web address column, so slugs are made from names (as Woo does by default).

// Options (1.3), for moving a whole shop:
//   --users=users.csv      WordPress users (with their password hashes) -> <out>-customers.csv
//   --orders=orders.csv    WooCommerce orders (an order export, one row per order) -> <out>-orders.csv
//   --sitemap=sitemap.xml  the old shop's product sitemap, for each product's real old address (old_url)
$opt = [];
$pos = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(users|orders|sitemap)=(.+)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    } else {
        $pos[] = $a;
    }
}
if (count($pos) < 2) {
    fwrite(STDERR, "Usage: php woo-to-wren.php woocommerce-export.csv wren-products.csv [report.txt] [photos.csv] [--users=users.csv] [--orders=orders.csv] [--sitemap=sitemap.xml]\n");
    exit(1);
}
$argv = [$argv[0], ...$pos];
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

// Woo's weight in kg as Wren's import takes it ("" for none).
function kg(string $w): string
{
    $w = trim(str_replace(',', '.', $w));
    return is_numeric($w) && (float)$w > 0 ? rtrim(rtrim(number_format((float)$w, 3, '.', ''), '0'), '.') : '';
}

function slugify(string $s): string
{
    $s = strtolower(trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $s = strtr($s, ['&' => ' and ', "'" => '', '’' => '']);
    $s = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    return trim($s, '-') ?: 'item';
}

/* A name as WordPress makes it into an address (the & is dropped, not "and"). */
function wp_slug(string $s): string
{
    $s = strtolower(trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $s = strtr($s, ['&' => '', "'" => '', '’' => '']);
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

$report = ['old_missing' => [], 'old_unused' => [], 'users_skipped' => [], 'orders_left' => [], 'multi' => [], 'skipped' => [], 'shared_stock' => [], 'no_price' => [], 'orphans' => [], 'deep' => [], 'sku_dupes' => []];
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
         'main_category', 'categories', 'option_name', 'option2_name', 'choice', 'choice2', 'choice_sku', 'choice_price', 'choice_sale_price',
         'choice_active', 'weight', 'choice_weight', 'photos', 'old_url', 'description'];
// The old shop's product addresses: from its sitemap if given, else WooCommerce's default /product/<slug>/.
$oldBySlug = [];
if (isset($opt['sitemap'])) {
    foreach (preg_split('/\s*,\s*/', $opt['sitemap']) as $sm) {
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', (string)@file_get_contents($sm), $mm);
        foreach ($mm[1] as $u) {
            $path = (string)parse_url(html_entity_decode($u), PHP_URL_PATH);
            $seg = strtolower(rawurldecode(basename(rtrim($path, '/'))));
            if ($seg !== '' && !str_ends_with($path, '.xml')) {
                $oldBySlug[$seg] = $path;
            }
        }
    }
}
$oldUsed = [];
$oldUrl = function (string $name, string $slug) use (&$oldBySlug, &$oldUsed, $opt): string {
    if (!isset($opt['sitemap'])) {   // WooCommerce's default address, made from the name
        return '/product/' . wp_slug($name) . ($slug !== slugify($name) ? substr($slug, strlen(slugify($name))) : '') . '/';
    }
    foreach ([$slug, slugify($name), wp_slug($name)] as $k) {
        if (isset($oldBySlug[$k])) {
            $oldUsed[$k] = true;
            return $oldBySlug[$k];
        }
    }
    return '';
};
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
             'option_name' => '', 'option2_name' => '', 'choice' => '', 'choice2' => '', 'choice_sku' => '', 'choice_price' => '', 'choice_sale_price' => '',
             'choice_active' => '', 'weight' => kg($col($r, 'Weight (kg)')), 'choice_weight' => '',
             'photos' => implode(' | ', array_filter(array_map('trim', explode(',', $col($r, 'Images'))))), 'old_url' => $oldUrl($name, $slug), 'description' => $desc];
    if ($base['old_url'] === '') {
        $report['old_missing'][] = $name;
    }
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
    $all = [];   // each attribute's values on the parent, for variations that take "any"
    for ($a = 1; $col($r, "Attribute $a name") !== ''; $a++) {
        $names[] = $col($r, "Attribute $a name");
        $all[] = array_values(array_filter(array_map(fn($x) => html_entity_decode(trim($x), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            explode(',', $col($r, "Attribute $a value(s)"))), fn($x) => $x !== ''));
    }
    $prices = array_filter(array_map(fn($v) => price($col($v, 'Regular price')), $vs), fn($x) => $x !== '');
    $base['price'] = $prices ? min($prices) : '0.00';
    if (!$prices) {
        $report['no_price'][] = $name;
        $base['visible'] = '0';
    }
    $base['sale_price'] = $base['sale_from'] = $base['sale_to'] = '';
    $base['option_name'] = $names[0] ?? 'Option';
    $base['option2_name'] = count($names) > 1 ? implode(' / ', array_slice($names, 1)) : '';
    if (count($names) > 2) {
        $report['multi'][] = $name . ' (' . implode(' × ', $names) . ', ' . count($vs) . ' variations)';
    }
    $parentStock = $base['stock'];
    $base['stock'] = '';
    $seen = [];
    $two = $base['option2_name'] !== '';
    foreach ($vs as $v) {
        // Each attribute's value; "any" (empty) becomes every value the parent lists.
        $vals = [];
        for ($a = 1; $a <= max(count($names), 1) && ($a <= count($names) || $col($v, "Attribute $a value(s)") !== ''); $a++) {
            $x = $col($v, "Attribute $a value(s)") !== '' ? html_entity_decode($col($v, "Attribute $a value(s)"), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $vals[] = $x !== '' ? [$x] : ($all[$a - 1] ?? [] ?: ['Any']);
        }
        $pairs = [];
        if (!$two) {
            foreach ($vals[0] ?? [''] as $a1) {
                $pairs[] = [$a1, ''];
            }
        } else {
            $rest = [[]];   // the second and later attributes, combined
            foreach (array_slice($vals, 1) as $opts) {
                $next = [];
                foreach ($rest as $pre) {
                    foreach ($opts as $x) {
                        $next[] = [...$pre, $x];
                    }
                }
                $rest = $next;
            }
            foreach ($vals[0] as $a1) {
                foreach ($rest as $b) {
                    $pairs[] = [$a1, implode(' / ', $b)];
                }
            }
        }
        $first = true;
        foreach ($pairs as [$choice, $choice2]) {
        if ($choice === '') {
            $choice = trim(str_replace($name . ' - ', '', html_entity_decode($col($v, 'Name'), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        if ($two) {
            if (isset($seen[strtolower($choice . '|' . $choice2)])) {
                continue;   // Woo uses the first variation that matches
            }
            $c = $choice;
        } else {
            for ($i = 2, $c = $choice; isset($seen[strtolower($c . '|')]); $i++) {
                $c = $choice . ' ' . $i;
            }
        }
        $seen[strtolower($c . '|' . $choice2)] = true;
        $vsku = $first ? $col($v, 'SKU') : '';   // a variation made into several rows keeps its SKU on the first
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
        $row['choice2'] = $choice2;
        $row['choice_active'] = $col($v, 'Published') === '0' ? '0' : '1';
        $row['choice_weight'] = kg($col($v, 'Weight (kg)')) !== $base['weight'] ? kg($col($v, 'Weight (kg)')) : '';
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
        $first = false;
        }
    }
    $count['variable']++;
}
fclose($o);
fclose($ph);
if (isset($opt['sitemap'])) {
    foreach ($oldBySlug as $k => $path) {
        if (!isset($oldUsed[$k]) && str_contains($path, '/product/')) {
            $report['old_unused'][] = $path;
        }
    }
}

/* Read a CSV of the other kinds (users, orders) as header => value rows. */
function read_csv_assoc(string $file): array
{
    $raw = (string)file_get_contents($file);
    $raw = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $head = array_map(fn($h) => strtolower(trim((string)$h)), fgetcsv($fh, null, ',', '"', '') ?: []);
    $rows = [];
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if ($r !== [null]) {
            $rows[] = array_combine($head, array_map(fn($x) => trim((string)$x), array_pad(array_slice($r, 0, count($head)), count($head), '')));
        }
    }
    return $rows;
}
$pick = fn(array $r, string ...$ks) => array_reduce($ks, fn($c, $k) => $c !== '' ? $c : ($r[$k] ?? ''), '');
$extra = [];

// ---- customers: WordPress users (from a users export plugin, or the SQL in the guide)
if (isset($opt['users'])) {
    $cf = preg_replace('/\.csv$/', '', $out) . '-customers.csv';
    $cfh = fopen($cf, 'w');
    $ccols = ['email', 'name', 'phone', 'password_hash', 'created', 'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode',
              'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode'];
    fputcsv($cfh, $ccols, ',', '"', '');
    $nc = 0;
    foreach (read_csv_assoc($opt['users']) as $u) {
        $email = $pick($u, 'user_email', 'email', 'billing_email');
        $roles = strtolower($pick($u, 'roles', 'role', 'wp_capabilities'));
        if ($email === '') {
            continue;
        }
        if ($roles !== '' && !str_contains($roles, 'customer') && !str_contains($roles, 'subscriber')) {
            $report['users_skipped'][] = $email . ' (' . $roles . ')';   // staff accounts stay in WordPress
            continue;
        }
        $full = fn(string $p) => trim($pick($u, $p . '_first_name') . ' ' . $pick($u, $p . '_last_name'));
        $name = trim($pick($u, 'first_name') . ' ' . $pick($u, 'last_name')) ?: ($pick($u, 'display_name') ?: $full('billing'));
        $row = [$email, $name, $pick($u, 'billing_phone', 'phone'), $pick($u, 'user_pass', 'password_hash', 'password'), $pick($u, 'user_registered', 'registered'),
                $full('billing'), $pick($u, 'billing_address_1'), $pick($u, 'billing_address_2'), $pick($u, 'billing_city'), $pick($u, 'billing_postcode'),
                $full('shipping'), $pick($u, 'shipping_address_1'), $pick($u, 'shipping_address_2'), $pick($u, 'shipping_city'), $pick($u, 'shipping_postcode')];
        fputcsv($cfh, $row, ',', '"', '');
        $nc++;
    }
    fclose($cfh);
    $extra[] = $nc . ' customers in ' . basename($cf);
}

// ---- past orders: an order export with one row per order and items as line_item_1, line_item_2...
// ("name:Wishbone|product_id:12|sku:804501|quantity:2|total:15.83|tax:3.17"), as the common export plugins write.
if (isset($opt['orders'])) {
    $of = preg_replace('/\.csv$/', '', $out) . '-orders.csv';
    $ofh = fopen($of, 'w');
    fputcsv($ofh, ['number', 'date', 'status', 'email', 'phone', 'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode',
        'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode', 'delivery_method', 'postage', 'discount', 'discount_code', 'total', 'vat',
        'payment', 'note', 'item_name', 'item_option', 'item_sku', 'item_qty', 'item_price'], ',', '"', '');
    $no = 0;
    $m2 = fn($x) => number_format((float)$x, 2, '.', '');
    foreach (read_csv_assoc($opt['orders']) as $r) {
        $num = preg_replace('/\D/', '', $pick($r, 'order_number', 'order_id', 'id'));
        $status = strtolower(preg_replace('/^wc-/', '', $pick($r, 'status', 'order_status')));
        if ($num === '') {
            continue;
        }
        if (!in_array($status, ['completed', 'processing', 'refunded', 'cancelled', 'failed'], true)) {
            $report['orders_left'][] = '#' . $num . ' (' . ($status ?: 'no status') . ')';
        }
        $full = fn(string $p) => trim($pick($r, $p . '_first_name') . ' ' . $pick($r, $p . '_last_name'));
        $post = (float)$pick($r, 'shipping_total', 'order_shipping') + (float)$pick($r, 'shipping_tax_total', 'order_shipping_tax');
        $disc = (float)$pick($r, 'cart_discount', 'discount_total') + (float)$pick($r, 'cart_discount_tax', 'discount_tax');
        $head = [$num, $pick($r, 'order_date', 'date_created', 'date'), $status, $pick($r, 'customer_email', 'billing_email', 'email'), $pick($r, 'billing_phone'),
                 $full('billing'), $pick($r, 'billing_address_1'), $pick($r, 'billing_address_2'), $pick($r, 'billing_city'), $pick($r, 'billing_postcode'),
                 $full('shipping'), $pick($r, 'shipping_address_1'), $pick($r, 'shipping_address_2'), $pick($r, 'shipping_city'), $pick($r, 'shipping_postcode'),
                 preg_replace('/^.*?method_title:([^|]*).*$/', '$1', $pick($r, 'shipping_method_title', 'shipping_items', 'shipping_method')),
                 $m2($post), $disc ? $m2($disc) : '', $pick($r, 'coupon_items') !== '' ? preg_replace('/^code:([^|;]*).*$/', '$1', $pick($r, 'coupon_items')) : '',
                 $m2($pick($r, 'order_total')), $m2((float)$pick($r, 'order_tax', 'tax_total') + (float)$pick($r, 'shipping_tax_total', 'order_shipping_tax')),
                 $pick($r, 'payment_method_title', 'payment_method'), $pick($r, 'customer_note')];
        $any = false;
        for ($i = 1; isset($r['line_item_' . $i]); $i++) {
            if ($r['line_item_' . $i] === '') {
                continue;
            }
            $it = [];
            foreach (explode('|', $r['line_item_' . $i]) as $kv) {
                [$k, $v] = array_pad(explode(':', $kv, 2), 2, '');
                $it[trim($k)] = trim($v);
            }
            $qty = max(1, (int)($it['quantity'] ?? 1));
            $line = (float)($it['total'] ?? 0) + (float)($it['tax'] ?? $it['total_tax'] ?? 0);   // Woo keeps line totals without VAT
            $optLabel = trim(preg_replace('/^[^:]*:/', '', $it['meta'] ?? ''));
            fputcsv($ofh, [...$head, html_entity_decode($it['name'] ?? 'Item', ENT_QUOTES | ENT_HTML5, 'UTF-8'), $optLabel, $it['sku'] ?? '', $qty, $m2($line / $qty)], ',', '"', '');
            $any = true;
        }
        if (!$any) {
            fputcsv($ofh, [...$head, '', '', '', '', ''], ',', '"', '');
        }
        $no++;
    }
    fclose($ofh);
    $extra[] = $no . ' orders in ' . basename($of);
}

$lines = ['Wren Shop import made from ' . basename($in) . ' on ' . date('Y-m-d H:i'), '',
          $count['simple'] . ' simple products, ' . $count['variable'] . ' products with options (' . $count['choices'] . ' choices).', ''];
$sections = [
    'old_missing'  => 'Products not found in the sitemap, so with no old address to redirect (add old_url by hand if they had one):',
    'old_unused'   => 'Product addresses in the sitemap that match no product (redirect them by hand if they matter):',
    'users_skipped' => 'Users left out because they aren\'t customers (staff stay in WordPress):',
    'orders_left'  => 'Orders that won\'t come across (pending, on hold or drafts: not real orders yet):',
    'multi'        => 'Products with three or more attributes (Wren Shop has two options): the second and later are combined, like "Red / Cotton".',
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
echo implode("\n", array_slice($lines, 0, 3)), "\nWrote $out, $reportFile and $photoFile\n", $extra ? implode("\n", $extra) . "\n" : '';

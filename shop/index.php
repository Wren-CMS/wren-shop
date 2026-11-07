<?php
/*
 *  Wren Shop — a small shop that sits beside a Wren CMS site.
 *
 *  Lives in /shop/ next to an unchanged Wren install. Wren is never edited and
 *  its database is never opened. The shop borrows the site's look by asking
 *  Wren for its own "Not found" page (fully themed, with the live menu),
 *  turning that into a template, caching it, and dropping shop content into
 *  the space where Wren's 404 message was.
 *
 *  Products with photos and options, categories, stock, an editable basket with
 *  postage, collection and VAT, checkout with billing and delivery addresses,
 *  payment by PayPal, bank transfer or on collection, order emails and packing
 *  slips, customer accounts, discount codes, customer groups (e.g. reseller
 *  prices), low-stock emails, CSV export, a privacy tool, and the shop admin
 *  (two-factor sign-in, products, orders, customers, settings, getting-ready
 *  checklist). Owner's guide: https://github.com/Wren-CMS/wren-shop/wiki
 *
 *  Licence: MIT · Copyright (c) 2026 Forty Nine Systems Ltd · Developed by Alan Paynter, 49 Systems
 */

declare(strict_types=1);

/* Never show PHP's own error messages to visitors (they can reveal paths and
   SQL); log them, and show a plain apology instead. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $e): void {
    error_log('Wren Shop: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Sorry, something went wrong. Please try again in a moment.';
});

const SHOP_VERSION    = '1.3.2';
const SHOP_HTACCESS   = "# Wren Shop: its own rewrite rules. Because this file turns RewriteEngine on,\n"
    . "# Apache uses these rules for everything under /shop/ and Wren's root rules never apply here.\n"
    . "Options -Indexes\nRewriteEngine On\nRewriteRule ^data(/|\$) - [F]\n"
    . "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\n"
    . "RewriteRule ^(.*)\$ index.php?q=\$1 [QSA,L]\n";

const SHOP_HELP_URL   = 'https://github.com/Wren-CMS/wren-shop/wiki';   // the owner's guide
const SHELL_TTL       = 600;   // seconds a fetched frame is fresh
const SHELL_RETRY     = 300;   // after a failed fetch, wait this long before trying again
const SHELL_FRAME_Q   = 'wren-shop-frame';   // an address Wren has no page for
const CONTENT_MARK    = '<!--wrenshop:content-->';
const TITLE_MARK      = '<!--wrenshop:title-->';
const HEAD_MARK       = '<!--wrenshop:head-->';

const PHOTO_MAX       = 1600;  // longest side of a stored product photo, px
const THUMB_SIZE      = 600;   // square thumbnail for product cards, px
const UPLOAD_MAX      = 15 * 1024 * 1024;   // per photo, before resizing
const REMEMBER_DAYS   = 30;    // "keep me signed in"
const LOGIN_WINDOW    = 900;   // seconds
const LOGIN_MAX_FAILS = 5;     // per address within LOGIN_WINDOW

define('SHOP_DIR', __DIR__);
define('SHOP_DATA', SHOP_DIR . '/data');
define('SHOP_DB', SHOP_DATA . '/shop.db');
define('SHELL_FILE', SHOP_DATA . '/shell.json');
/* A .php file, so a web server runs it (it shows nothing) rather than serving the
   code, even where the data folder's .htaccess is ignored. Read it with a file manager. */
define('SETUP_FILE', SHOP_DATA . '/setup-code.php');
define('OLD_SETUP_FILE', SHOP_DATA . '/SETUP-CODE.txt');
define('MEDIA_DIR', SHOP_DIR . '/media');

/* ---------------------------------------------------------------- paths */

function shop_base(): string   // '/shop' or '/sub/shop'
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/shop/index.php')), '/');
}

function site_base(): string   // where Wren lives: '' or '/sub'
{
    $b = rtrim(str_replace('\\', '/', dirname(shop_base())), '/');
    return $b === '/' ? '' : $b;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || forwarded_https();   // or https at a trusted proxy
}

/* The site's address as this request names it. Only trustworthy on the admin's
   own signed-in requests: anyone can send any Host header. */
function request_origin(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$|^\[[0-9a-f:.]+\](:\d{1,5})?$/i', $host)) {
        $host = 'localhost';
    }
    return (is_https() ? 'https' : 'http') . '://' . strtolower($host);
}

/* The site's address for every link the shop makes (emails, the page frame,
   structured data). Saved from the admin's own sign-in, never taken from a
   visitor's request, so a forged Host header can't redirect links or the frame. */
function origin(): string
{
    $s = setting('site_url');
    return $s !== '' ? $s : request_origin();
}

/* A form or query value as a string; anything else (e.g. an array) is ''. */
function str_in(mixed $v): string
{
    return is_string($v) ? $v : '';
}

function shop_url(string $route = ''): string
{
    if ($route === '') {
        return shop_base() . '/';
    }
    if (setting('pretty_urls', '1') === '1') {
        return shop_base() . '/' . $route;
    }
    return shop_base() . '/?q=' . str_replace('%2F', '/', rawurlencode($route));
}

function abs_shop_url(string $route = ''): string
{
    return origin() . shop_url($route);
}

function admin_url(string $route = ''): string
{
    return shop_url('admin' . ($route !== '' ? '/' . $route : ''));
}

/* ------------------------------------------------------------- sessions */
/* Wren uses PHP's default session cookie and save path on the same domain.
   A different cookie name is NOT enough: PHP stores sessions by ID only, so a
   shop cookie carrying the same ID as Wren's would open Wren's admin session
   (proven in the PoC tests). So the shop keeps its sessions in its own folder
   and runs strict mode, which refuses any session ID it didn't create. */

function data_dir(): string
{
    if (!is_dir(SHOP_DATA)) {
        mkdir(SHOP_DATA, 0750, true);
    }
    if (!is_file(SHOP_DATA . '/.htaccess')) {
        file_put_contents(SHOP_DATA . '/.htaccess', "Require all denied\n");
    }
    // The shop's own address rules (Apache/LiteSpeed). Shipped in the package, and
    // put back if it was missed: without it /shop/p/... would fall through to Wren.
    if (!is_file(SHOP_DIR . '/.htaccess')) {
        @file_put_contents(SHOP_DIR . '/.htaccess', SHOP_HTACCESS);
    }
    if (!is_dir(SHOP_DATA . '/sessions')) {
        mkdir(SHOP_DATA . '/sessions', 0750, true);
    }
    return SHOP_DATA;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '172800');   // baskets last two days
ini_set('session.gc_probability', '1');        // our folder isn't cleaned by the host's cron
ini_set('session.gc_divisor', '100');
session_save_path(data_dir() . '/sessions');
session_name('wrenshop');
session_set_cookie_params([
    'path'     => shop_base() . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => is_https() || str_starts_with(setting('site_url'), 'https:'),   // also right behind an https proxy
]);
session_start();

/* ------------------------------------------------------------- database */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        data_dir();
        $fresh = !file_exists(SHOP_DB);
        $pdo = new PDO('sqlite:' . SHOP_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($fresh) {
            @chmod(SHOP_DB, 0600);   // only the shop itself reads it (its journal files copy this)
            shop_schema_v1($pdo);
        }
        shop_migrate($pdo);
    }
    return $pdo;
}

/* Version 1 is the 0.1/0.2 schema. Fresh installs create it and then run the
   same migrations as an upgrade, so there is only one path to the current schema. */
function shop_schema_v1(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT "");
        CREATE TABLE products (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            slug        TEXT NOT NULL UNIQUE,
            name        TEXT NOT NULL,
            price_pence INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            image       TEXT NOT NULL DEFAULT "",
            visible     INTEGER NOT NULL DEFAULT 1,
            created     TEXT NOT NULL,
            updated     TEXT NOT NULL
        );
    ');
    // The demo package ships with its pictures in media/demo: a fresh install
    // of it fills itself with sample products and starts in demo mode.
    if (is_dir(MEDIA_DIR . '/demo')) {
        $ins = $pdo->prepare('INSERT INTO products (slug, name, price_pence, description, image, created, updated)
                              VALUES (?, ?, ?, ?, ?, ?, ?)');
        $demo = [
            ['wren-mug', 'Wren mug', 1200, "Ceramic mug, 330 ml, with the wren. wordmark on both sides.\n\nDishwasher and microwave safe.", 'demo/mug.svg'],
            ['sticker-pack', 'Sticker pack', 400, "Five weatherproof vinyl stickers for laptops, notebooks and water bottles.", 'demo/stickers.svg'],
            ['wren-t-shirt', 'Wren T-shirt', 1800, "Charcoal organic cotton with a small orange wordmark on the chest.\n\nUnisex fit.", 'demo/tshirt.svg'],
            ['tote-bag', 'Tote bag', 1000, "Heavy natural cotton tote with long handles. Fits a laptop.", 'demo/tote.svg'],
            ['enamel-pin', 'Enamel pin', 600, "Hard enamel pin, 25 mm, with a rubber clutch back.", 'demo/pin.svg'],
            ['dot-grid-notebook', 'Dot-grid notebook', 800, "A5 notebook, 120 pages of dot-grid paper, lay-flat binding.", 'demo/notebook.svg'],
        ];
        foreach ($demo as $i => $d) {
            $t = gmdate('Y-m-d H:i:s', time() - $i * 60);   // keeps this order, newest first
            $ins->execute([...$d, $t, $t]);
        }
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('shop_title', 'Wren merch'), ('demo_mode', '1')");
    }
}

/* Forward-only migrations, tracked by settings.schema_version. Upgrading is
   just "replace index.php", exactly like Wren. */
function shop_migrate(PDO $pdo): void
{
    $v = (int)($pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() ?: 1);
    if ($v < 2) {
        $pdo->beginTransaction();
        $pdo->exec('
            CREATE TABLE categories (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT NOT NULL,
                slug     TEXT NOT NULL UNIQUE
            );
            CREATE TABLE photos (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                file       TEXT NOT NULL,
                thumb      TEXT NOT NULL,
                position   INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX idx_photos_product ON photos (product_id, position);
            ALTER TABLE products ADD COLUMN category_id INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE products ADD COLUMN stock INTEGER DEFAULT NULL;
            CREATE TABLE users (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                username    TEXT NOT NULL UNIQUE,
                password    TEXT NOT NULL,
                created     TEXT NOT NULL,
                totp_secret TEXT NOT NULL DEFAULT "",
                totp_last   INTEGER NOT NULL DEFAULT 0,
                recovery    TEXT NOT NULL DEFAULT ""
            );
            CREATE TABLE remember (
                selector   TEXT PRIMARY KEY,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                hash       TEXT NOT NULL,
                expires    INTEGER NOT NULL
            );
            CREATE TABLE login_fails (ip TEXT NOT NULL, at INTEGER NOT NULL);
            CREATE INDEX idx_login_fails ON login_fails (ip, at);
        ');
        // 0.2 kept one image per product in products.image; move it to photos.
        $pdo->exec("INSERT INTO photos (product_id, file, thumb, position)
                    SELECT id, image, image, 0 FROM products WHERE image != ''");
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('schema_version', '2')
                    ON CONFLICT(name) DO UPDATE SET value = '2'");
        $pdo->commit();
        $v = 2;
    }
    if ($v < 3) {
        // Categories get an order the shop owner controls (it's the shop menu).
        $pdo->beginTransaction();
        $pdo->exec('ALTER TABLE categories ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
        $i = 0;
        foreach ($pdo->query('SELECT id FROM categories ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare('UPDATE categories SET position = ? WHERE id = ?')->execute([$i++, $id]);
        }
        $pdo->exec("UPDATE settings SET value = '3' WHERE name = 'schema_version'");
        $pdo->commit();
        $v = 3;
    }
    if ($v < 4) {
        // Product options: one named set per product (e.g. Size), each choice
        // with an optional price of its own and optional stock of its own.
        $pdo->beginTransaction();
        $pdo->exec('
            ALTER TABLE products ADD COLUMN option_name TEXT NOT NULL DEFAULT "";
            CREATE TABLE variants (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id  INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                name        TEXT NOT NULL,
                price_pence INTEGER DEFAULT NULL,
                stock       INTEGER DEFAULT NULL,
                position    INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX idx_variants_product ON variants (product_id, position);
        ');
        if ($pdo->query("SELECT value FROM settings WHERE name = 'demo_mode'")->fetchColumn() === '1') {
            // Show options off in the demo: T-shirt sizes, XL a little dearer, Medium sold out.
            $tee = $pdo->query("SELECT id FROM products WHERE slug = 'wren-t-shirt'")->fetchColumn();
            if ($tee) {
                $pdo->prepare("UPDATE products SET option_name = 'Size', stock = NULL WHERE id = ?")->execute([$tee]);
                $ins = $pdo->prepare('INSERT INTO variants (product_id, name, price_pence, stock, position) VALUES (?, ?, ?, ?, ?)');
                foreach ([['Small', null, null], ['Medium', null, 0], ['Large', null, null], ['XL', 2000, null]] as $i => $d) {
                    $ins->execute([$tee, $d[0], $d[1], $d[2], $i]);
                }
            }
            foreach (['postage_delivery' => '1', 'postage_price' => '395', 'postage_free_over' => '4000'] as $k => $val) {
                $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO NOTHING')->execute([$k, $val]);
            }
        }
        $pdo->exec("UPDATE settings SET value = '4' WHERE name = 'schema_version'");
        $pdo->commit();
        $v = 4;
    }
    if ($v < 5) {
        // Orders. Items keep a copy of name, option and price as they were when
        // ordered, so editing or deleting a product never changes a past order.
        $pdo->beginTransaction();
        $pdo->exec('
            CREATE TABLE orders (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                number           INTEGER NOT NULL UNIQUE,
                token            TEXT NOT NULL UNIQUE,
                status           TEXT NOT NULL,          -- pending | awaiting_payment | pay_on_collection | paid | cancelled | refunded
                payment_method   TEXT NOT NULL,          -- paypal | bank | collection
                delivery         TEXT NOT NULL,          -- deliver | collect
                email            TEXT NOT NULL,
                phone            TEXT NOT NULL DEFAULT "",
                bill_name        TEXT NOT NULL,
                bill_line1       TEXT NOT NULL DEFAULT "",
                bill_line2       TEXT NOT NULL DEFAULT "",
                bill_town        TEXT NOT NULL DEFAULT "",
                bill_postcode    TEXT NOT NULL DEFAULT "",
                ship_name        TEXT NOT NULL DEFAULT "",
                ship_line1       TEXT NOT NULL DEFAULT "",
                ship_line2       TEXT NOT NULL DEFAULT "",
                ship_town        TEXT NOT NULL DEFAULT "",
                ship_postcode    TEXT NOT NULL DEFAULT "",
                same_address     INTEGER NOT NULL DEFAULT 1,
                customer_note    TEXT NOT NULL DEFAULT "",
                subtotal_pence   INTEGER NOT NULL,
                postage_pence    INTEGER NOT NULL,
                total_pence      INTEGER NOT NULL,
                vat_pence        INTEGER DEFAULT NULL,
                vat_number       TEXT NOT NULL DEFAULT "",
                paypal_order_id  TEXT NOT NULL DEFAULT "",
                paypal_capture_id TEXT NOT NULL DEFAULT "",
                paypal_link      TEXT NOT NULL DEFAULT "",
                fulfilled        TEXT NOT NULL DEFAULT "",   -- "" | sent | collected
                tracking         TEXT NOT NULL DEFAULT "",
                admin_note       TEXT NOT NULL DEFAULT "",
                created          TEXT NOT NULL,
                updated          TEXT NOT NULL,
                paid_at          TEXT NOT NULL DEFAULT "",
                fulfilled_at     TEXT NOT NULL DEFAULT "",
                cancelled_at     TEXT NOT NULL DEFAULT ""
            );
            CREATE INDEX idx_orders_status ON orders (status, created);
            CREATE INDEX idx_orders_paypal ON orders (paypal_order_id);
            CREATE TABLE order_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id     INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                product_id   INTEGER NOT NULL,
                variant_id   INTEGER DEFAULT NULL,
                name         TEXT NOT NULL,
                option_label TEXT NOT NULL DEFAULT "",
                unit_pence   INTEGER NOT NULL,
                qty          INTEGER NOT NULL
            );
            CREATE INDEX idx_order_items ON order_items (order_id);
        ');
        $pdo->exec("UPDATE settings SET value = '5' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ($v < 6) {   // 0.8.0: order emails
        $pdo->beginTransaction();
        $pdo->exec('
            CREATE TABLE emails (
                id        INTEGER PRIMARY KEY,
                order_id  INTEGER DEFAULT NULL,
                kind      TEXT NOT NULL,
                to_addr   TEXT NOT NULL,
                subject   TEXT NOT NULL,
                body_text TEXT NOT NULL,
                body_html TEXT NOT NULL,
                status    TEXT NOT NULL DEFAULT "queued",
                tries     INTEGER NOT NULL DEFAULT 0,
                error     TEXT NOT NULL DEFAULT "",
                created   TEXT NOT NULL,
                tried_at  TEXT NOT NULL DEFAULT "",
                sent_at   TEXT NOT NULL DEFAULT ""
            );
            CREATE INDEX idx_emails_order ON emails (order_id);
            CREATE INDEX idx_emails_status ON emails (status);
        ');
        $pdo->exec("UPDATE settings SET value = '6' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ($v < 7) {   // 0.9.0: customer accounts
        $pdo->beginTransaction();
        $pdo->exec('
            CREATE TABLE customers (
                id            INTEGER PRIMARY KEY,
                email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password      TEXT NOT NULL,
                name          TEXT NOT NULL DEFAULT "",
                phone         TEXT NOT NULL DEFAULT "",
                bill_name     TEXT NOT NULL DEFAULT "", bill_line1 TEXT NOT NULL DEFAULT "", bill_line2 TEXT NOT NULL DEFAULT "",
                bill_town     TEXT NOT NULL DEFAULT "", bill_postcode TEXT NOT NULL DEFAULT "",
                ship_name     TEXT NOT NULL DEFAULT "", ship_line1 TEXT NOT NULL DEFAULT "", ship_line2 TEXT NOT NULL DEFAULT "",
                ship_town     TEXT NOT NULL DEFAULT "", ship_postcode TEXT NOT NULL DEFAULT "",
                same_address  INTEGER NOT NULL DEFAULT 1,
                verified_at   TEXT NOT NULL DEFAULT "",
                session_gen   INTEGER NOT NULL DEFAULT 0,
                created       TEXT NOT NULL,
                last_login    TEXT NOT NULL DEFAULT ""
            );
            CREATE TABLE customer_tokens (
                hash        TEXT PRIMARY KEY,
                customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
                kind        TEXT NOT NULL,
                data        TEXT NOT NULL DEFAULT "",
                expires     INTEGER NOT NULL
            );
            CREATE TABLE customer_remember (
                selector    TEXT PRIMARY KEY,
                customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
                hash        TEXT NOT NULL,
                expires     INTEGER NOT NULL
            );
            CREATE TABLE throttle (k TEXT NOT NULL, at INTEGER NOT NULL);
            CREATE INDEX idx_throttle ON throttle (k, at);
            ALTER TABLE orders ADD COLUMN customer_id INTEGER DEFAULT NULL;
            CREATE INDEX idx_orders_customer ON orders (customer_id);
            CREATE INDEX idx_orders_email ON orders (email COLLATE NOCASE);
        ');
        $pdo->exec("UPDATE settings SET value = '7' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ($v < 8) {   // 0.10.0: discount codes, and demo mode becomes a setting
        $pdo->beginTransaction();
        $pdo->exec('
            CREATE TABLE discounts (
                id           INTEGER PRIMARY KEY,
                code         TEXT NOT NULL UNIQUE COLLATE NOCASE,
                percent      INTEGER NOT NULL DEFAULT 0,
                amount_pence INTEGER NOT NULL DEFAULT 0,
                free_postage INTEGER NOT NULL DEFAULT 0,
                min_spend    INTEGER NOT NULL DEFAULT 0,
                starts       TEXT NOT NULL DEFAULT "",
                ends         TEXT NOT NULL DEFAULT "",
                max_uses     INTEGER DEFAULT NULL,
                once_each    INTEGER NOT NULL DEFAULT 0,
                active       INTEGER NOT NULL DEFAULT 1,
                note         TEXT NOT NULL DEFAULT "",
                created      TEXT NOT NULL
            );
            ALTER TABLE orders ADD COLUMN discount_id INTEGER DEFAULT NULL;
            ALTER TABLE orders ADD COLUMN discount_code TEXT NOT NULL DEFAULT "";
            ALTER TABLE orders ADD COLUMN discount_pence INTEGER NOT NULL DEFAULT 0;
            CREATE INDEX idx_orders_discount ON orders (discount_id);
        ');
        // Up to 0.9.0 demo mode was fixed in the file. A shop still showing the
        // demo products is the demo; anything else is a real shop.
        $isDemo = (int)$pdo->query("SELECT COUNT(*) FROM photos WHERE file LIKE 'demo/%'")->fetchColumn() > 0;
        $pdo->prepare("INSERT INTO settings (name, value) VALUES ('demo_mode', ?) ON CONFLICT(name) DO NOTHING")->execute([$isDemo ? '1' : '0']);
        if ($pdo->query("SELECT value FROM settings WHERE name = 'demo_mode'")->fetchColumn() === '1') {
            $pdo->prepare("INSERT INTO discounts (code, percent, note, created) VALUES ('WREN10', 10, 'Demo code', ?)")->execute([gmdate('Y-m-d H:i:s')]);
        }
        $pdo->exec("UPDATE settings SET value = '8' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ($v < 9) {   // 0.11.0: customer groups (e.g. resellers) with their own discount
        $pdo->beginTransaction();
        $pdo->exec('
            CREATE TABLE customer_groups (
                id       INTEGER PRIMARY KEY,
                name     TEXT NOT NULL UNIQUE COLLATE NOCASE,
                percent  INTEGER NOT NULL DEFAULT 0,
                created  TEXT NOT NULL
            );
            CREATE TABLE group_rates (
                group_id    INTEGER NOT NULL REFERENCES customer_groups(id) ON DELETE CASCADE,
                category_id INTEGER NOT NULL,
                percent     INTEGER NOT NULL,
                PRIMARY KEY (group_id, category_id)
            );
            ALTER TABLE customers ADD COLUMN group_id INTEGER DEFAULT NULL;
            ALTER TABLE orders ADD COLUMN group_name TEXT NOT NULL DEFAULT "";
            ALTER TABLE orders ADD COLUMN group_pence INTEGER NOT NULL DEFAULT 0;
        ');
        $pdo->exec("UPDATE settings SET value = '9' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ($v < 10) {   // 0.12.1: "sign out everywhere" for the admin really signs out other sessions
        $pdo->beginTransaction();
        $pdo->exec('ALTER TABLE users ADD COLUMN session_gen INTEGER NOT NULL DEFAULT 0');
        // Unpaid orders lapsing is new: it only applies to orders placed from now on.
        $pdo->prepare("INSERT INTO settings (name, value) VALUES ('unpaid_since', ?) ON CONFLICT(name) DO NOTHING")->execute([gmdate('Y-m-d H:i:s')]);
        @unlink(SHELL_FILE);    // fetch the look again from the address the admin confirms
        @chmod(SHOP_DB, 0600);
        $pdo->exec("UPDATE settings SET value = '10' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ((int)$pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() < 11) {   // 1.1.0: downloads and theme licences
        $pdo->beginTransaction();
        $pdo->exec('
            ALTER TABLE products ADD COLUMN kind TEXT NOT NULL DEFAULT "physical";
            ALTER TABLE products ADD COLUMN licensed INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE products ADD COLUMN theme_id TEXT NOT NULL DEFAULT "";
            ALTER TABLE products ADD COLUMN renew_pence INTEGER DEFAULT NULL;
            ALTER TABLE products ADD COLUMN update_months INTEGER NOT NULL DEFAULT 12;
            CREATE TABLE product_files (
                id         INTEGER PRIMARY KEY,
                product_id INTEGER NOT NULL,
                version    TEXT NOT NULL,
                file       TEXT NOT NULL,
                name       TEXT NOT NULL,
                size       INTEGER NOT NULL DEFAULT 0,
                uploaded   TEXT NOT NULL
            );
            CREATE INDEX idx_product_files ON product_files (product_id, uploaded);
            CREATE TABLE licences (
                id            INTEGER PRIMARY KEY,
                lkey          TEXT NOT NULL UNIQUE,
                product_id    INTEGER NOT NULL,
                order_id      INTEGER NOT NULL,
                customer_id   INTEGER DEFAULT NULL,
                domain        TEXT NOT NULL DEFAULT "",
                status        TEXT NOT NULL DEFAULT "active",
                created       TEXT NOT NULL,
                updates_until TEXT NOT NULL
            );
            CREATE INDEX idx_licences_customer ON licences (customer_id);
            CREATE INDEX idx_licences_order ON licences (order_id);
            CREATE TABLE licence_log (
                id         INTEGER PRIMARY KEY,
                licence_id INTEGER NOT NULL,
                at         TEXT NOT NULL,
                action     TEXT NOT NULL,
                detail     TEXT NOT NULL DEFAULT ""
            );
            CREATE INDEX idx_licence_log ON licence_log (licence_id, at);
            ALTER TABLE order_items ADD COLUMN licence_id INTEGER DEFAULT NULL;
            ALTER TABLE orders ADD COLUMN digital_consent TEXT NOT NULL DEFAULT "";
        ');
        $pdo->exec("UPDATE settings SET value = '11' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ((int)$pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() < 12) {
        // 1.1.0: a deleted customer's number is never given to a new account (with it went
        // their licences, and any session still signed in elsewhere). Rebuild with AUTOINCREMENT.
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        $sql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'customers'")->fetchColumn();
        $idx = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'customers' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        if (!preg_match('/AUTOINCREMENT/i', $sql)) {
            $new = preg_replace('/^\s*CREATE TABLE\s+"?customers"?/i', 'CREATE TABLE customers_v12', $sql);
            $new = preg_replace('/\bid\s+INTEGER\s+PRIMARY\s+KEY\b/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $new, 1);
            $pdo->exec($new);
            $pdo->exec('INSERT INTO customers_v12 SELECT * FROM customers');
            $pdo->exec('DROP TABLE customers');
            $pdo->exec('ALTER TABLE customers_v12 RENAME TO customers');
            foreach ($idx as $i) {
                $pdo->exec($i);
            }
        }
        $pdo->exec('ALTER TABLE order_items ADD COLUMN months INTEGER NOT NULL DEFAULT 0');   // months a renewal added
        $pdo->exec("UPDATE settings SET value = '12' WHERE name = 'schema_version'");
        $pdo->commit();
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    if ((int)$pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() < 13) {
        // 1.2.0: catalogue foundations. Part numbers (SKUs), categories inside categories
        // (three levels), a product in several categories, backorders and sale prices.
        // products.category_id stays as the product's main category (its breadcrumb and
        // group rate); product_categories lists any other categories it also shows in.
        $pdo->beginTransaction();
        $pdo->exec('
            ALTER TABLE products ADD COLUMN sku TEXT NOT NULL DEFAULT "";
            ALTER TABLE products ADD COLUMN backorders TEXT NOT NULL DEFAULT "no";     -- no | yes | notify (tell the customer)
            ALTER TABLE products ADD COLUMN sale_pence INTEGER DEFAULT NULL;
            ALTER TABLE products ADD COLUMN sale_from TEXT NOT NULL DEFAULT "";
            ALTER TABLE products ADD COLUMN sale_to TEXT NOT NULL DEFAULT "";
            ALTER TABLE variants ADD COLUMN sku TEXT NOT NULL DEFAULT "";
            ALTER TABLE variants ADD COLUMN sale_pence INTEGER DEFAULT NULL;
            ALTER TABLE categories ADD COLUMN parent_id INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE order_items ADD COLUMN sku TEXT NOT NULL DEFAULT "";
            ALTER TABLE order_items ADD COLUMN backorder INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE product_categories (
                product_id  INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                category_id INTEGER NOT NULL,
                PRIMARY KEY (product_id, category_id)
            ) WITHOUT ROWID;
            CREATE INDEX idx_product_categories ON product_categories (category_id, product_id);
            CREATE INDEX idx_categories_parent ON categories (parent_id, position);
            CREATE INDEX idx_products_sku ON products (sku COLLATE NOCASE) WHERE sku != "";
            CREATE INDEX idx_variants_sku ON variants (sku COLLATE NOCASE) WHERE sku != "";
            CREATE INDEX idx_products_list ON products (visible, created);
            CREATE INDEX idx_products_category ON products (category_id);
        ');
        // The demo shows off 1.2: categories with one inside another, SKUs, a sale and a backorder.
        $demoSlugs = ['wren-mug', 'sticker-pack', 'wren-t-shirt', 'tote-bag', 'enamel-pin', 'dot-grid-notebook'];
        $isDemo = $pdo->query("SELECT value FROM settings WHERE name = 'demo_mode'")->fetchColumn() === '1'
               && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE slug IN ('" . implode("','", $demoSlugs) . "')")->fetchColumn() === 6
               && (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0;
        if ($isDemo) {
            $cat = function (string $name, string $slug, int $pos, int $parent = 0) use ($pdo): int {
                $pdo->prepare('INSERT INTO categories (name, slug, position, parent_id) VALUES (?, ?, ?, ?)')->execute([$name, $slug, $pos, $parent]);
                return (int)$pdo->lastInsertId();
            };
            $clothing = $cat('Clothing and bags', 'clothing-and-bags', 0);
            $ids = ['tees' => $cat('T-shirts', 't-shirts', 0, $clothing), 'bags' => $cat('Bags', 'bags', 1, $clothing),
                    'kitchen' => $cat('Kitchen', 'kitchen', 1), 'desk' => $cat('Stationery', 'stationery', 2), 'gifts' => $cat('Small gifts', 'small-gifts', 3)];
            $set = $pdo->prepare('UPDATE products SET category_id = ?, sku = ? WHERE slug = ?');
            foreach ([['wren-t-shirt', 'tees', 'WREN-TEE'], ['tote-bag', 'bags', 'WREN-TOTE'], ['wren-mug', 'kitchen', 'WREN-MUG'],
                      ['dot-grid-notebook', 'desk', 'WREN-NB-A5'], ['sticker-pack', 'desk', 'WREN-STK-5'], ['enamel-pin', 'gifts', 'WREN-PIN']] as [$slug, $c, $sku]) {
                $set->execute([$ids[$c], $sku, $slug]);
            }
            foreach ([['sticker-pack', 'gifts'], ['wren-mug', 'gifts'], ['enamel-pin', 'desk']] as [$slug, $c]) {   // also shown in
                $pdo->prepare('INSERT OR IGNORE INTO product_categories (product_id, category_id) SELECT id, ? FROM products WHERE slug = ?')->execute([$ids[$c], $slug]);
            }
            $pdo->exec("UPDATE variants SET sku = 'WREN-TEE-' || UPPER(SUBSTR(name, 1, 2)) WHERE product_id = (SELECT id FROM products WHERE slug = 'wren-t-shirt')");
            $pdo->exec("UPDATE products SET sale_pence = 300 WHERE slug = 'sticker-pack'");
            $pdo->exec("UPDATE products SET stock = 0, backorders = 'notify' WHERE slug = 'dot-grid-notebook'");
        }
        $pdo->exec("UPDATE settings SET value = '13' WHERE name = 'schema_version'");
        $pdo->commit();
    }
    if ((int)$pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() < 14) {
        // 1.3.0: products with two options (Colour × Size): each choice is one combination,
        // and one that isn't made can be switched off. Weights, for delivery priced by weight.
        $pdo->beginTransaction();
        $pdo->exec('
            ALTER TABLE products ADD COLUMN option2_name TEXT NOT NULL DEFAULT "";
            ALTER TABLE products ADD COLUMN weight_g INTEGER DEFAULT NULL;
            ALTER TABLE variants ADD COLUMN name2 TEXT NOT NULL DEFAULT "";
            ALTER TABLE variants ADD COLUMN active INTEGER NOT NULL DEFAULT 1;
            ALTER TABLE variants ADD COLUMN weight_g INTEGER DEFAULT NULL;
        ');
        // Delivery methods. The shop's own delivery price and collection stay in settings as the
        // first two methods ("deliver" and "collect"); these tables add more methods, price bands
        // and zones. Bands and zone rates name a method by its key: deliver, collect or m<id>.
        $pdo->exec('
            CREATE TABLE delivery_methods (
                id              INTEGER PRIMARY KEY,
                name            TEXT NOT NULL,
                kind            TEXT NOT NULL DEFAULT "deliver",   -- deliver | collect
                pricing         TEXT NOT NULL DEFAULT "flat",      -- flat | value | weight
                price_pence     INTEGER DEFAULT NULL,
                free_over_pence INTEGER DEFAULT NULL,
                note            TEXT NOT NULL DEFAULT "",
                position        INTEGER NOT NULL DEFAULT 0,
                active          INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE delivery_bands (
                id          INTEGER PRIMARY KEY,
                method      TEXT NOT NULL,
                up_to       INTEGER DEFAULT NULL,   -- pence or grams; NULL = anything above the last band
                price_pence INTEGER NOT NULL
            );
            CREATE INDEX delivery_bands_method ON delivery_bands (method, up_to);
            CREATE TABLE delivery_zones (
                id       INTEGER PRIMARY KEY,
                name     TEXT NOT NULL,
                areas    TEXT NOT NULL DEFAULT "",
                position INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE delivery_zone_rates (
                method      TEXT NOT NULL,
                zone_id     INTEGER NOT NULL,
                available   INTEGER NOT NULL DEFAULT 1,
                extra_pence INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (method, zone_id)
            );
            ALTER TABLE orders ADD COLUMN delivery_method TEXT NOT NULL DEFAULT "";
            ALTER TABLE orders ADD COLUMN delivery_zone TEXT NOT NULL DEFAULT "";
        ');
        // Trade accounts: a group can show prices without VAT, need a minimum order and offer
        // its own ways to pay; products (or choices) can have a fixed price for a group; and
        // customers can apply to join the group the shop chooses.
        $pdo->exec('
            ALTER TABLE customer_groups ADD COLUMN ex_vat INTEGER NOT NULL DEFAULT 0;
            ALTER TABLE customer_groups ADD COLUMN min_order_pence INTEGER DEFAULT NULL;
            ALTER TABLE customer_groups ADD COLUMN pay_methods TEXT NOT NULL DEFAULT "";   -- "" = as everyone else
            CREATE TABLE group_prices (
                group_id    INTEGER NOT NULL REFERENCES customer_groups(id) ON DELETE CASCADE,
                product_id  INTEGER NOT NULL,
                variant_id  INTEGER NOT NULL DEFAULT 0,   -- 0 = the product itself
                price_pence INTEGER NOT NULL,
                PRIMARY KEY (group_id, product_id, variant_id)
            );
            CREATE INDEX group_prices_product ON group_prices (product_id);
            CREATE TABLE trade_applications (
                id          INTEGER PRIMARY KEY,
                customer_id INTEGER NOT NULL,
                group_id    INTEGER NOT NULL,
                business    TEXT NOT NULL,
                vat_number  TEXT NOT NULL DEFAULT "",
                phone       TEXT NOT NULL DEFAULT "",
                note        TEXT NOT NULL DEFAULT "",
                status      TEXT NOT NULL DEFAULT "new",   -- new | approved | declined
                created     TEXT NOT NULL,
                decided     TEXT NOT NULL DEFAULT ""
            );
            CREATE INDEX trade_applications_customer ON trade_applications (customer_id);
        ');
        // Photo import: where each photo came from (so a re-run adds nothing twice), and
        // imports that run in batches, so a big one never hits the host's time limit.
        $pdo->exec('
            ALTER TABLE photos ADD COLUMN source TEXT NOT NULL DEFAULT "";
            CREATE INDEX photos_source ON photos (product_id, source);
            CREATE TABLE import_jobs (
                id       INTEGER PRIMARY KEY,
                kind     TEXT NOT NULL,            -- zip | urls
                label    TEXT NOT NULL DEFAULT "",
                items    TEXT NOT NULL,            -- JSON: what to fetch, for which product
                done     INTEGER NOT NULL DEFAULT 0,
                added    INTEGER NOT NULL DEFAULT 0,
                skipped  INTEGER NOT NULL DEFAULT 0,
                errors   TEXT NOT NULL DEFAULT "[]",
                replace_existing INTEGER NOT NULL DEFAULT 0,
                dir      TEXT NOT NULL DEFAULT "",
                created  TEXT NOT NULL,
                finished TEXT NOT NULL DEFAULT ""
            );
        ');
        // Moving in from another shop: past orders brought across (a record only), and the
        // old shop's product addresses, for redirects.
        $pdo->exec('
            ALTER TABLE orders ADD COLUMN imported INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE redirects (
                old_path   TEXT PRIMARY KEY,
                product_id INTEGER NOT NULL
            );
            CREATE TABLE product_related (
                product_id INTEGER NOT NULL,
                related_id INTEGER NOT NULL,
                position   INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (product_id, related_id)
            );
        ');
        $pdo->exec("UPDATE settings SET value = '14' WHERE name = 'schema_version'");
        $pdo->commit();
    }
}

/* Demo mode: a banner on every page, nothing indexed by search engines, no
   sitemap, and checkout and new accounts only for a signed-in admin. */
function shop_demo(): bool
{
    return setting('demo_mode', '0') === '1';
}

function setting(string $name, string $default = ''): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function set_setting(string $name, #[\SensitiveParameter] string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?)
                   ON CONFLICT(name) DO UPDATE SET value = excluded.value')->execute([$name, $value]);
}

/* -------------------------------------------------------------- helpers */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function money(int $pence): string
{
    return '£' . number_format($pence / 100, 2);
}

/* "12", "12.5", "£12.50", "1,200" -> pence; null if it isn't a price. */
function parse_price(string $s): ?int
{
    $s = str_replace(['£', ',', ' '], '', trim($s));
    if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
}

function price_input(int $pence): string
{
    return number_format($pence / 100, 2, '.', '');
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = strtr($s, ['&' => ' and ', "'" => '', '’' => '']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'item';
}

function unique_slug(string $table, string $slug, int $ignoreId = 0): string
{
    $base = $slug;
    for ($i = 2; ; $i++) {
        $st = db()->prepare("SELECT id FROM $table WHERE slug = ? AND id != ?");
        $st->execute([$slug, $ignoreId]);
        if (!$st->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i;
    }
}

function media_url(string $file): string
{
    return shop_base() . '/media/' . str_replace('%2F', '/', rawurlencode($file));
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['shop_flash'] = $msg;
        return null;
    }
    $m = $_SESSION['shop_flash'] ?? null;
    unset($_SESSION['shop_flash']);
    return $m;
}

function csrf_token(): string
{
    if (empty($_SESSION['shop_csrf'])) {
        $_SESSION['shop_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['shop_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('This form has expired. Go back, reload the page, and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $k): string
{
    $v = $_POST[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}

/* Simple formatting for product descriptions, written by shop owners who
   aren't web people: paragraphs, line breaks, **bold**, *italic*, [links](url)
   and "- " lists. HTML typed into the box is shown as text, never run. */
function md_lite(string $text): string
{
    $inline = function (string $s): string {
        $s = e($s);
        $s = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $s);
        return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES);
            if (!preg_match('#^(https?://|/|mailto:)#i', $url)) {
                return $m[0];
            }
            return '<a href="' . e($url) . '">' . $m[1] . '</a>';
        }, $s);
    };
    $out = [];
    foreach (preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [] as $block) {
        $lines = explode("\n", trim($block));
        if ($lines && !array_filter($lines, fn($l) => !preg_match('/^\s*[-*]\s+/', $l))) {
            $out[] = '<ul>' . implode('', array_map(
                fn($l) => '<li>' . $inline(preg_replace('/^\s*[-*]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
        } elseif (trim($block) !== '') {
            $out[] = '<p>' . implode('<br>', array_map($inline, $lines)) . '</p>';
        }
    }
    return implode("\n", $out);
}

function plain_summary(string $text, int $limit = 158): string
{
    $t = trim((string)preg_replace(['/\*\*|\*/', '/\[([^\]]+)\]\([^)]+\)/', '/^\s*-\s+/m', '/\s+/'],
                                   ['', '$1', '', ' '], $text));
    return mb_strlen($t) > $limit ? rtrim(mb_substr($t, 0, $limit - 1)) . '…' : $t;
}

/* ======================================================= the page frame */
/* shell_get() returns ['html' => template, 'site_title' => ..., 'source' =>
   'wren' | 'cache' | 'theme' | 'builtin']. The template holds three marks:
   TITLE_MARK, HEAD_MARK and CONTENT_MARK. */

function shell_read_cache(): ?array
{
    if (!is_file(SHELL_FILE)) {
        return null;
    }
    $c = json_decode((string)file_get_contents(SHELL_FILE), true);
    return is_array($c) && isset($c['html'], $c['fetched']) ? $c : null;
}

function shell_write_cache(array $c): void
{
    $tmp = SHELL_FILE . '.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($c));
    @chmod($tmp, 0600);
    rename($tmp, SHELL_FILE);   // atomic: readers never see half a file
}

function shell_get(): array
{
    $cache = shell_read_cache();
    $now = time();

    if ($cache && $now - $cache['fetched'] < SHELL_TTL) {
        return $cache + ['source' => 'cache'];
    }
    if ($cache && $now < ($cache['retry_after'] ?? 0)) {
        return $cache + ['source' => 'cache'];   // backing off after a failure
    }

    // Stale or missing: one request refreshes, others carry on with the stale copy.
    db(); // makes sure data/ exists
    $lock = fopen(SHELL_FILE . '.lock', 'c');
    $mine = $lock && flock($lock, LOCK_EX | ($cache ? LOCK_NB : 0));
    if (!$mine && $cache) {
        return $cache + ['source' => 'cache'];
    }

    $fresh = shell_fetch_from_wren();
    if ($fresh) {
        set_setting('shell_note', '');
        shell_write_cache($fresh);
        set_setting('last_site_title', $fresh['site_title']);
        $out = $fresh + ['source' => 'wren'];
    } elseif ($cache) {
        $cache['retry_after'] = $now + SHELL_RETRY;
        shell_write_cache($cache);
        $out = $cache + ['source' => 'cache'];
    } else {
        $out = shell_from_theme();
    }
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $out;
}

function shell_fetch_from_wren(): ?array
{
    if (!function_exists('curl_init')) {
        return shell_note('PHP\'s curl extension isn\'t available, so the shop can\'t fetch your site\'s look.');
    }
    if (setting('site_url') === '') {   // until the owner's visit tells the shop its address, never trust a visitor's
        return shell_note('The shop doesn\'t know its web address yet. Open the shop admin once and it will.');
    }
    // ?q= works with or without Wren's pretty URLs and mod_rewrite; with pretty
    // URLs on, Wren 301s it to the clean address, so follow one redirect.
    $url = origin() . site_base() . '/?q=' . SHELL_FRAME_Q;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_USERAGENT      => 'WrenShop/' . SHOP_VERSION . ' (page frame)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html'],
        // No cookies are sent: the frame is what any anonymous visitor sees.
    ]);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $bare = fn($u) => preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
    if ($final !== '' && $bare($final) !== $bare(origin())) {
        return shell_note('Your site sent the shop to ' . parse_url($final, PHP_URL_HOST) . ' instead of ' . parse_url(origin(), PHP_URL_HOST)
            . '. If that\'s the right address, set it under Web address.');   // another site: not this site's look
    }
    if (!is_string($html) || $status === 0) {
        return shell_note('The shop couldn\'t reach ' . origin() . ' to copy its look.');
    }
    if ($status !== 404) {   // only Wren's own 404 page is a valid frame
        return shell_note('Your site answered ' . $status . ' instead of its "Not found" page, so the shop couldn\'t copy its look.');
    }
    $frame = shell_from_wren_html($html);
    return $frame ?? shell_note('Your site\'s "Not found" page didn\'t look like a Wren page, so the shop couldn\'t copy its look.');
}

/* Turn Wren's rendered 404 page into a template. Returns null if it doesn't
   look like Wren, so a strange response is never cached as the frame. */
function shell_from_wren_html(string $html): ?array
{
    // Custom themes may drop Wren's generator tag, so the checks are
    // structural: a </head> and exactly one 404 article to replace.
    $headEnd = stripos($html, '</head>');
    if ($headEnd === false) {
        return null;
    }
    $head = substr($html, 0, $headEnd);
    $body = substr($html, $headEnd);

    // Content: Wren's 404 message is an <article> with a post-title "Not found".
    $n = 0;
    $body = preg_replace('#<article>\s*<h1 class="post-title">[^<]*</h1>.*?</article>#s', CONTENT_MARK, $body, 1, $n);
    if ($n !== 1) {
        $body = preg_replace('#<article\b[^>]*>.*?</article>#s', CONTENT_MARK, $body, 1, $n);
    }
    if ($n !== 1) {
        return null;
    }

    // Site title from "<title>Not found · Site</title>".
    $siteTitle = '';
    if (preg_match('#<title>(.*?)</title>#s', $head, $m)) {
        $t = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $siteTitle = preg_replace('/^Not found · /u', '', $t);
    }

    // Head: drop Wren's per-page tags; the shop writes its own.
    $head = preg_replace([
        '#<meta name="description"[^>]*>\s*#i',
        '#<meta name="robots"[^>]*>\s*#i',
        '#<link rel="canonical"[^>]*>\s*#i',
        '#<meta property="og:[^"]*"[^>]*>\s*#i',
        '#<meta name="twitter:[^"]*"[^>]*>\s*#i',
    ], '', $head);
    $head = preg_replace('#<title>.*?</title>#s', '<title>' . TITLE_MARK . '</title>', $head, 1);
    if (!str_contains($head, TITLE_MARK)) {
        $head .= '<title>' . TITLE_MARK . '</title>' . "\n";
    }
    $head .= HEAD_MARK . "\n";

    // Menu: Wren marks Home active on its 404 page. Clear every "active";
    // shell_render() marks the shop link instead.
    $body = preg_replace('#(<a\b[^>]*?) class="active"#', '$1', $body);

    return [
        'html'       => $head . $body,
        'site_title' => $siteTitle,
        'fetched'    => time(),
    ];
}

/* Last resort when Wren can't be reached and nothing is cached: the site's
   theme.html with a simple Home / Shop menu. */
/* Why the look couldn't be copied (shown in Settings); always returns null. */
function shell_note(string $why): ?array
{
    set_setting('shell_note', $why);
    return null;
}

function shell_from_theme(): array
{
    $siteTitle = setting('last_site_title', 'Shop');
    $themeFile = dirname(SHOP_DIR) . '/theme.html';
    $theme = is_file($themeFile) ? (string)file_get_contents($themeFile) : builtin_theme();
    $home = site_base() . '/';
    $menu = '<a href="' . e($home) . '">Home</a>' . "\n" . '<a href="' . e(shop_url()) . '">Shop</a>';
    $theme = preg_replace('/\[\[[a-z][a-z0-9_]*\]\]/', '', $theme);
    $theme = preg_replace_callback('/\{\{url:([a-z0-9-]*)\}\}/', fn($m) => e(site_base() . '/' . $m[1]), $theme);
    $html = strtr($theme, [
        '{{seo_head}}'   => HEAD_MARK,
        '{{site_title}}' => e($siteTitle),
        '{{tagline}}'    => '',
        '{{page_title}}' => TITLE_MARK,
        '{{menu}}'       => $menu,
        '{{content}}'    => CONTENT_MARK,
        '{{home_url}}'   => e($home),
        '{{rss_url}}'    => e(site_base() . '/?q=rss'),
        '{{year}}'       => gmdate('Y'),
        // Wren's own version isn't known here, so the footer says just "Wren" (plus the shop's version).
        '{{generator}}'  => 'Wren' . (setting('show_version', '1') === '1' ? ' · Wren Shop ' . SHOP_VERSION : ''),
    ]);
    if (!str_contains($html, HEAD_MARK)) {
        $html = preg_replace('#</head>#i', HEAD_MARK . "\n</head>", $html, 1);
    }
    return ['html' => $html, 'site_title' => $siteTitle, 'fetched' => 0,
            'source' => is_file($themeFile) ? 'theme' : 'builtin'];
}


function builtin_theme(): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<title>{{page_title}}</title>{{seo_head}}</head><body style="font-family:system-ui,sans-serif;'
         . 'max-width:860px;margin:0 auto;padding:1rem"><header><h1><a href="{{home_url}}">{{site_title}}</a></h1>'
         . '<nav>{{menu}}</nav></header><main>{{content}}</main></body></html>';
}

/* Mark the site's menu links that point at the shop as active. Runs on the
   frame only, before shop content goes in. */
function mark_shop_links(string $html): string
{
    $shop = rtrim(shop_base(), '/');
    return preg_replace_callback('#<a\b([^>]*?)href="([^"]*)"([^>]*)>#', function ($m) use ($shop) {
        $path = rtrim((string)parse_url(html_entity_decode($m[2]), PHP_URL_PATH), '/');
        $host = parse_url(html_entity_decode($m[2]), PHP_URL_HOST);
        if ($path !== $shop || ($host !== null && $host !== ($_SERVER['HTTP_HOST'] ?? ''))
            || str_contains($m[1] . $m[3], 'class=')) {
            return $m[0];
        }
        return '<a' . $m[1] . 'href="' . $m[2] . '"' . $m[3] . ' class="active">';
    }, $html);
}

function render(string $title, string $content, array $seo = [], int $status = 200): never
{
    $shell = shell_get();
    $site = $shell['site_title'] !== '' ? $shell['site_title'] : 'Shop';
    $full = $title !== '' ? $title . ' · ' . $site : $site;
    $desc = $seo['description'] ?? '';
    $canonical = url_with(abs_shop_url($seo['route'] ?? ''), $seo['query'] ?? []);
    // Every shop page starts with the shop menu and a breadcrumb trail.
    // $seo['crumbs'] lists what comes after Home › Shop: [label, route|null].
    $trail = array_merge([['Home', null, site_base() . '/'], ['Shop', '']],
                         array_map(fn($c) => [$c[0], $c[1]], $seo['crumbs'] ?? []));
    $content = shop_nav($seo['cat'] ?? null) . crumbs_html($trail) . $content;
    if (shop_demo()) {
        $seo['noindex'] = true;
        $content = demo_banner() . $content;
    }

    $head  = '<meta name="description" content="' . e($desc) . '">' . "\n";
    if (!empty($seo['noindex'])) {
        $head .= '<meta name="robots" content="noindex, follow">' . "\n";
    } else {
        $head .= '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
    }
    $head .= '<meta property="og:site_name" content="' . e($site) . '">' . "\n";
    $head .= '<meta property="og:type" content="' . e($seo['type'] ?? 'website') . '">' . "\n";
    $head .= '<meta property="og:title" content="' . e($full) . '">' . "\n";
    $head .= '<meta property="og:description" content="' . e($desc) . '">' . "\n";
    $head .= '<meta property="og:url" content="' . e($canonical) . '">' . "\n";
    if (!empty($seo['image'])) {
        $head .= '<meta property="og:image" content="' . e($seo['image']) . '">' . "\n";
    }
    $head .= '<meta name="twitter:card" content="summary">' . "\n";
    if (!empty($seo['jsonld'])) {
        $head .= '<script type="application/ld+json">'
               . json_encode(array_filter($seo['jsonld'], fn($v) => $v !== null),
                             JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
               . '</script>' . "\n";
    }
    $head .= '<script type="application/ld+json">' . json_encode(crumbs_jsonld($trail),
             JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
    $head .= shop_css();
    $head .= '<!-- frame: ' . e($shell['source']) . ' -->';

    $frame = mark_shop_links($shell['html']);
    if (setting('show_version', '1') === '1') {
        [$frame, $content] = add_shop_version($frame, $content);
    }
    $html = strtr($frame, [
        TITLE_MARK   => e($full),
        HEAD_MARK    => $head,
        CONTENT_MARK => shop_layout($content, $seo['cat'] ?? null),
    ]);
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/* "Powered by Wren 1.9.0" becomes "Powered by Wren 1.9.0 · Wren Shop 0.9.0".
   A theme without that wording gets it at the end of its footer, and one with
   no footer gets a small line under the shop content. */
function add_shop_version(string $frame, string $content): array
{
    $label = 'Wren Shop ' . SHOP_VERSION;
    if (str_contains($frame, $label)) {
        return [$frame, $content];   // the fallback theme already says it
    }
    $done = 0;
    $frame = preg_replace('#(Powered by\s+(?:<a\b[^>]*>)?\s*Wren(?:\s+CMS)?(?:\s*</a>)?\s+v?\d+(?:\.\d+)*(?:\s*</a>)?)#i', '$1 &middot; ' . $label, $frame, 1, $done);
    if ($done) {
        return [$frame, $content];
    }
    $at = strripos($frame, '</footer>');
    if ($at !== false) {
        return [substr($frame, 0, $at) . '<p class="shop-version" style="font-size:.85em;opacity:.8">' . $label . '</p>' . substr($frame, $at), $content];
    }
    return [$frame, $content . '<p class="shop-hint shop-version">' . $label . '</p>'];
}

function demo_banner(): string
{
    $d = discount_by_code('WREN10');
    return '<p class="shop-demo">This is a demo of <strong>Wren Shop</strong>. '
         . 'Nothing here is for sale and no payment is taken.'
         . ($d && discount_problem($d, PHP_INT_MAX) === null ? ' Try the discount code <strong>WREN10</strong> in the basket.' : '') . '</p>';
}

/* Crumb: [label, shop route or null for the current page, optional full URL]. */
function crumb_url(array $c): ?string
{
    return $c[2] ?? ($c[1] === null ? null : shop_url($c[1]));
}

function crumbs_html(array $trail): string
{
    $last = count($trail) - 1;
    $out = '<nav class="shop-crumbs" aria-label="Breadcrumb"><ol>';
    foreach ($trail as $i => $c) {
        $url = crumb_url($c);
        $out .= '<li>' . ($i === $last || $url === null
            ? '<span aria-current="page">' . e($c[0]) . '</span>'
            : '<a href="' . e($url) . '">' . e($c[0]) . '</a>') . '</li>';
    }
    return $out . '</ol></nav>';
}

function crumbs_jsonld(array $trail): array
{
    $items = [];
    foreach ($trail as $i => $c) {
        $url = crumb_url($c) ?? $_SERVER['REQUEST_URI'] ?? '';
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => origin() . $url];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/* The shop menu: the top-level categories that have something for sale, in the
   owner's order, plus account and basket. A category with others inside it opens
   a dropdown (on a phone, the whole menu folds into a Categories button and the
   dropdowns open in place). $active is a category id, 0 for "All products". */
function shop_nav(?int $active): string
{
    $live = cat_live();
    $top = array_values(array_filter(cat_children(0), fn($c) => isset($live[$c['id']])));
    $onTrail = $active ? array_column(cat_trail($active), 'id') : [];
    $link = fn($c, $label = null) => '<a href="' . e(shop_url('c/' . $c['slug'])) . '"'
        . ($c['id'] === $active ? ' class="on" aria-current="page"' : '') . '>' . e($label ?? $c['name']) . '</a>';
    $li = '';
    $deep = false;
    if ($top) {
        $li .= '<li><a href="' . e(shop_url()) . '"' . ($active === 0 ? ' class="on" aria-current="page"' : '') . '>All products</a></li>';
        foreach ($top as $c) {
            $kids = array_filter(cat_children($c['id']), fn($k) => isset($live[$k['id']]));
            $here = in_array($c['id'], $onTrail, true);
            if (!$kids) {
                $li .= '<li>' . $link($c) . '</li>';
                continue;
            }
            $deep = true;
            $sub = '<li class="shop-drop-all">' . $link($c, 'All ' . $c['name']) . '</li>';
            foreach ($kids as $k) {
                $grand = '';
                foreach (cat_children($k['id']) as $g) {
                    $grand .= isset($live[$g['id']]) ? '<li>' . $link($g) . '</li>' : '';
                }
                $sub .= '<li>' . $link($k) . ($grand !== '' ? '<ul>' . $grand . '</ul>' : '') . '</li>';
            }
            $li .= '<li class="shop-has-sub"><details><summary' . ($here ? ' class="on"' : '') . '>' . e($c['name']) . '</summary>'
                 . '<ul class="shop-drop' . (count($kids) > 4 ? ' shop-drop-wide' : '') . '">' . $sub . '</ul></details></li>';
        }
    }
    $n = basket_count();
    $acct = accounts_on() ? '<a class="shop-account-link" href="' . e(shop_url(current_customer() ? 'account' : 'account/login')) . '">'
          . (current_customer() ? 'Your account' : 'Sign in') . '</a>' : '';
    // Folding on a phone is only worth it for a longer menu.
    $fold = $deep || count($top) > 5;
    $side = $top && side_menu_on();
    return '<nav class="shop-nav' . ($fold ? ' shop-foldable' : '') . ($side ? ' shop-with-side' : '') . '" aria-label="Shop">'
         . ($li ? ($fold ? '<button type="button" class="shop-menu-btn" aria-expanded="false" aria-controls="shop-cats">Categories</button>' : '')
                  . '<ul class="shop-cats" id="shop-cats">' . $li . '</ul>' : '<span></span>')
         . '<span class="shop-nav-end">' . (search_box_wanted() ? search_box(($GLOBALS['shop_seg'][0] ?? '') === 'search' ? mb_substr(trim(str_in($_GET['s'] ?? '')), 0, 100) : '') : '')
         . $acct . '<a class="shop-basket-link" href="' . e(shop_url('basket')) . '">Basket' . ($n ? ' (' . $n . ')' : '') . '</a></span></nav>'
         . ($deep || $fold ? shop_nav_script() : '');
}

/* Categories down the side (the default) or along the top: Settings → Shop. */
function side_menu_on(): bool
{
    return setting('cat_menu', 'side') !== 'top';
}

/* The shop's content in the theme's card, with the categories in a card of their
   own down the left on wide screens. Both cards are <article>, as Wren themes
   style posts, so they look alike in any theme. On narrower screens the side
   card is hidden and the categories are in the menu along the top instead. */
function shop_layout(string $content, ?int $active): string
{
    $main = '<article class="wren-shop">' . $content . '</article>';
    $side = side_menu_on() ? shop_side($active) : '';
    return $side === '' ? $main
        : '<div class="wren-shop-layout"><aside class="wren-shop-side"><article class="wren-shop shop-side-card">' . $side . '</article></aside>' . $main . '</div>';
}

/* The side menu: every top-level category with something for sale, and what's
   inside the one you're in. A small shop (15 categories or fewer) shows them all. */
function shop_side(?int $active): string
{
    $live = cat_live();
    $top = array_values(array_filter(cat_children(0), fn($c) => isset($live[$c['id']])));
    if (!$top) {
        return '';
    }
    $trail = $active ? array_column(cat_trail($active), 'id') : [];
    $all = count($live) <= 15;
    $item = function (array $c, int $depth) use (&$item, $live, $trail, $all, $active): string {
        $kids = $depth < 3 && ($all || in_array($c['id'], $trail, true))
            ? array_filter(cat_children($c['id']), fn($k) => isset($live[$k['id']])) : [];
        $here = $c['id'] === $active;
        $sub = '';
        foreach ($kids as $k) {
            $sub .= $item($k, $depth + 1);
        }
        return '<li><a href="' . e(shop_url('c/' . $c['slug'])) . '"'
            . ($here ? ' class="on" aria-current="page"' : (in_array($c['id'], $trail, true) ? ' class="in"' : '')) . '>' . e($c['name']) . '</a>'
            . ($sub !== '' ? '<ul>' . $sub . '</ul>' : '') . '</li>';
    };
    $li = '<li><a href="' . e(shop_url()) . '"' . ($active === 0 ? ' class="on" aria-current="page"' : '') . '>All products</a></li>';
    foreach ($top as $c) {
        $li .= $item($c, 1);
    }
    return '<nav class="shop-side-nav" aria-label="Categories"><h2 class="shop-side-title">Categories</h2><ul>' . $li . '</ul></nav>';
}

/* One dropdown open at a time; Escape or a click elsewhere closes it; the menu
   button opens the folded menu on a phone. */
function shop_nav_script(): string
{
    return '<script>(function(){var n=document.querySelector(".shop-nav");if(!n)return;var d=n.querySelectorAll(".shop-has-sub details");'
        . 'd.forEach(function(x){x.addEventListener("toggle",function(){if(!x.open)return;d.forEach(function(y){if(y!==x)y.open=false;});'
        . 'var u=x.querySelector(".shop-drop");u.style.left="";var r=u.getBoundingClientRect(),w=document.documentElement.clientWidth;'
        . 'if(r.right>w-8)u.style.left=(parseFloat(getComputedStyle(u).left)-(r.right-w+8))+"px";});});'
        . 'document.addEventListener("click",function(e){if(!n.contains(e.target))d.forEach(function(y){y.open=false;});});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")d.forEach(function(y){if(y.open){y.open=false;y.querySelector("summary").focus();}});});'
        . 'var b=n.querySelector(".shop-menu-btn");if(b){n.classList.add("shop-js");b.addEventListener("click",function(){'
        . 'var o=b.getAttribute("aria-expanded")!=="true";b.setAttribute("aria-expanded",o);n.classList.toggle("shop-open",o);});}'
        . 'if(window.matchMedia("(min-width:641px)").matches)d.forEach(function(x){x.addEventListener("mouseleave",function(){x.open=false;});});})();</script>';
}

/* Shop layout only. Text, links and fonts come from the theme via Wren's own
   .post-title / .post-body classes. The accent follows the same variable chain
   Wren uses for its comment button; a theme can set --shop-accent to override. */
function shop_css(): string
{
    return '<style>
.wren-shop{--shop-acc:var(--shop-accent,var(--orange,var(--moss,#444)))}
.wren-shop .shop-demo{margin:0 0 1.2rem;padding:.6rem .9rem;border-left:4px solid var(--shop-acc);
  background:var(--paper,rgba(127,127,127,.08));border-radius:0 6px 6px 0;font-size:.92em}
.wren-shop .shop-nav{display:flex;flex-wrap:wrap-reverse;align-items:baseline;justify-content:space-between;gap:.6rem 1.25rem;
  padding:0 0 .7rem;margin:0 0 .8rem;border-bottom:1px solid var(--line,rgba(127,127,127,.3))}
.wren-shop .shop-cats{display:flex;flex-wrap:wrap;gap:.35rem 1.1rem;margin:0;padding:0;list-style:none;flex:1 1 auto;min-width:0}
.wren-shop .shop-cats a{color:inherit;font-weight:600}
.wren-shop .shop-cats a:hover,.wren-shop .shop-cats a.on{color:var(--shop-acc)}
.wren-shop .shop-cats a.on{text-decoration:underline;text-decoration-thickness:2px;text-underline-offset:5px}
.wren-shop .shop-basket-link{white-space:nowrap;font-weight:600;color:var(--shop-acc)}
.wren-shop .shop-cats>li{position:relative}
.wren-shop .shop-has-sub summary{cursor:pointer;font-weight:600;list-style:none;display:inline-flex;align-items:center;gap:.3em}
.wren-shop .shop-has-sub summary::-webkit-details-marker{display:none}
.wren-shop .shop-has-sub summary::after{content:"";width:.38em;height:.38em;border:solid currentColor;border-width:0 2px 2px 0;transform:translateY(-.15em) rotate(45deg);opacity:.6}
.wren-shop .shop-has-sub details[open] summary::after{transform:translateY(.1em) rotate(-135deg)}
.wren-shop .shop-has-sub summary:hover,.wren-shop .shop-has-sub summary.on{color:var(--shop-acc)}
.wren-shop .shop-drop{list-style:none;margin:0;padding:.5rem .9rem .7rem;position:absolute;z-index:20;top:calc(100% + .45rem);left:-.9rem;
  min-width:14rem;max-width:90vw;max-height:70vh;overflow-y:auto;background:var(--bg,var(--paper,#fff));
  border:1px solid var(--line,rgba(127,127,127,.3));border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.wren-shop .shop-drop-wide{display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr));gap:.2rem 1.4rem;width:min(44rem,90vw)}
.wren-shop .shop-drop li{padding:.2rem 0}
.wren-shop .shop-drop a{color:inherit;font-weight:600}
.wren-shop .shop-drop a:hover,.wren-shop .shop-drop a.on{color:var(--shop-acc)}
.wren-shop .shop-drop ul{list-style:none;margin:.15rem 0 .2rem;padding:0 0 0 .8rem;border-left:1px solid var(--line,rgba(127,127,127,.3))}
.wren-shop .shop-drop ul a{font-weight:400}
.wren-shop .shop-drop-all{grid-column:1/-1;padding-bottom:.4rem!important;margin-bottom:.3rem;border-bottom:1px solid var(--line,rgba(127,127,127,.3))}
.wren-shop .shop-menu-btn{display:none}
.wren-shop .shop-search{display:inline-flex;gap:.35rem;align-items:center;margin:0}
.wren-shop .shop-search input{width:11rem;font:inherit;font-size:.92em;padding:.3rem .55rem;border:1px solid var(--line,rgba(127,127,127,.4));border-radius:6px;background:var(--bg,#fff);color:inherit}
.wren-shop .shop-search .shop-button{padding:.3rem .7rem;font-size:.92em;margin:0}
.wren-shop .shop-nav-end{align-items:center;flex-wrap:nowrap;justify-content:flex-end;row-gap:.5rem;flex:0 0 auto;margin-left:auto}
.wren-shop .shop-search-page{margin:0 0 1.2rem}.wren-shop .shop-search-page input{width:min(22rem,60vw)}
.wren-shop .shop-subcats{display:flex;flex-wrap:wrap;gap:.45rem;list-style:none;margin:0 0 1.2rem;padding:0}
.wren-shop .shop-subcats a{display:inline-block;padding:.3rem .8rem;border:1px solid var(--line,rgba(127,127,127,.35));border-radius:99px;color:inherit;font-weight:600;font-size:.92em;text-decoration:none}
.wren-shop .shop-subcats a:hover{border-color:var(--shop-acc);color:var(--shop-acc)}
@media (max-width:640px){
  .wren-shop .shop-nav{flex-wrap:wrap}
  .wren-shop .shop-nav-end{flex-basis:100%;flex-wrap:wrap;justify-content:flex-end}
  .wren-shop .shop-nav-end .shop-search{flex:1 1 100%}
  .wren-shop .shop-nav-end .shop-search input{flex:1;width:auto;min-width:0}
  .wren-shop .shop-js .shop-menu-btn{display:inline-flex;align-items:center;gap:.4em;font:inherit;font-weight:600;background:none;color:inherit;border:1px solid var(--line,rgba(127,127,127,.35));border-radius:6px;padding:.3rem .7rem;cursor:pointer}
  .wren-shop .shop-js .shop-menu-btn::before{content:"";width:1em;height:.7em;border-top:2px solid;border-bottom:2px solid;background:linear-gradient(currentColor,currentColor) center/100% 2px no-repeat}
  .wren-shop .shop-js.shop-foldable .shop-cats{display:none;order:3;flex-basis:100%}
  .wren-shop .shop-js.shop-open .shop-cats{display:block}
  .wren-shop .shop-foldable .shop-cats>li{padding:.45rem 0;border-top:1px solid var(--line,rgba(127,127,127,.2))}
  .wren-shop .shop-drop{position:static;box-shadow:none;border:0;padding:.3rem 0 0 .9rem;max-height:none;min-width:0;width:auto;background:none;display:block}
  .wren-shop .shop-drop-all{border:0;margin:0}
}
.wren-shop .shop-nav-end{display:flex;gap:1.1rem;white-space:nowrap}
.wren-shop-side{display:none}
.wren-shop .shop-side-title{font-size:.8em;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.7;margin:0 0 .6rem}
.wren-shop .shop-side-nav ul{list-style:none;margin:0;padding:0}
.wren-shop .shop-side-nav li{margin:0}
.wren-shop .shop-side-nav a{display:block;padding:.3rem .55rem;margin:0 -.55rem;border-radius:6px;color:inherit;font-weight:600;text-decoration:none;line-height:1.35}
.wren-shop .shop-side-nav a:hover{color:var(--shop-acc);background:var(--paper,rgba(127,127,127,.08))}
.wren-shop .shop-side-nav a.on{color:var(--shop-acc);background:var(--paper,rgba(127,127,127,.08))}
.wren-shop .shop-side-nav a.in{color:var(--shop-acc)}
.wren-shop .shop-side-nav ul ul{margin:.1rem 0 .3rem .55rem;padding-left:.6rem;border-left:1px solid var(--line,rgba(127,127,127,.3))}
.wren-shop .shop-side-nav ul ul a{font-weight:400;font-size:.94em;padding-top:.2rem;padding-bottom:.2rem}
@media (min-width:800px){
  .wren-shop-layout{display:grid;grid-template-columns:14rem minmax(0,1fr);gap:22px;align-items:start}
  .wren-shop-layout>article{margin:0;min-width:0}
  .wren-shop-side{display:block;position:sticky;top:1rem;max-height:calc(100vh - 2rem);overflow-y:auto;border-radius:10px}
  .wren-shop-side>article.shop-side-card{margin:0;padding:18px 20px}
  .wren-shop .shop-with-side{justify-content:flex-end}
  .wren-shop .shop-with-side .shop-cats,.wren-shop .shop-with-side .shop-menu-btn{display:none!important}
  .wren-shop-layout .wren-shop .shop-grid{grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:1.25rem}
}
.wren-shop .shop-account-link{color:inherit;font-weight:600}
.wren-shop .shop-account-link:hover{color:var(--shop-acc)}
.wren-shop .shop-narrow{max-width:28rem}
.wren-shop .shop-button.shop-quiet{background:none;color:var(--shop-acc);border:1px solid var(--shop-acc)}
.wren-shop .shop-button.shop-danger{background:#b3261e}
.wren-shop .shop-inline{display:inline}
.wren-shop .shop-linkbutton{font:inherit;background:none;border:0;padding:0;color:var(--shop-acc);text-decoration:underline;cursor:pointer}
.wren-shop .shop-links{list-style:none;padding:0;margin:.4rem 0 1.2rem}.wren-shop .shop-links li{padding:.35rem 0}
.wren-shop .shop-links a{font-weight:600}
.wren-shop .shop-save{margin:1.2rem 0}
.wren-shop .shop-signedin{display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem}
.wren-shop .shop-crumbs ol{display:flex;flex-wrap:wrap;margin:0 0 .5rem;padding:0;list-style:none;font-size:.85em;opacity:.85}
.wren-shop .shop-crumbs li+li::before{content:"\203A";margin:0 .45em;opacity:.6}
.wren-shop .shop-crumbs span[aria-current]{opacity:.8}
.wren-shop .shop-related{margin-top:2.5rem}
.wren-shop .shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1.5rem;margin:1.5rem 0}
.wren-shop .shop-listbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem 1rem;margin:1rem 0 -.5rem}
.wren-shop .shop-count{margin:0;font-size:.92em;opacity:.85}
.wren-shop .shop-sort{margin:0}.wren-shop .shop-sort label{font-size:.92em}
.wren-shop .shop-sort select{font:inherit;margin-left:.4rem;padding:.25rem .4rem}
.wren-shop .shop-pager ul{display:flex;flex-wrap:wrap;justify-content:center;gap:.35rem;margin:1.5rem 0;padding:0;list-style:none}
.wren-shop .shop-pager a,.wren-shop .shop-pager span{display:inline-block;min-width:2.4rem;padding:.4rem .6rem;text-align:center;
  border:1px solid var(--line,rgba(127,127,127,.3));border-radius:6px;text-decoration:none}
.wren-shop .shop-pager span[aria-current]{background:var(--shop-acc);border-color:var(--shop-acc);color:#fff;font-weight:600}
.wren-shop .shop-pager .gap{padding:.4rem .2rem;opacity:.6}.wren-shop .shop-pager .step a{min-width:0}
.wren-shop .shop-card{display:flex;flex-direction:column;border:1px solid var(--line,rgba(127,127,127,.3));border-radius:8px;overflow:hidden;position:relative}
.wren-shop .shop-card>a{display:block;text-decoration:none}
.wren-shop .shop-card img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;border-radius:0}
.wren-shop .shop-card-text{padding:.8rem 1rem 1rem}
.wren-shop .shop-card h2{margin:0 0 .25rem;font-size:1.05rem}
.wren-shop .shop-card .shop-price{margin:0}
.wren-shop .shop-price{font-weight:bold}
.wren-shop .shop-soldout{display:inline-block;font-size:.8em;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;
  padding:.1em .5em;border-radius:4px;background:var(--ink,#333);color:#fff}
.wren-shop .shop-card .shop-soldout{position:absolute;top:.6rem;left:.6rem}
.wren-shop .shop-sale{display:inline-block;font-size:.8em;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;
  padding:.1em .5em;border-radius:4px;background:var(--shop-acc);color:#fff}
.wren-shop .shop-card .shop-sale{position:absolute;top:.6rem;left:.6rem}
.wren-shop .shop-sale-tag{font-weight:bold;color:var(--shop-acc)}
.wren-shop .shop-backorder{font-style:italic}
.wren-shop small.shop-backorder{display:block;font-style:normal;font-weight:600;color:var(--shop-acc)}
.wren-shop .shop-product{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:1.75rem;align-items:start}
.wren-shop .shop-photo{display:block;width:100%;height:auto;border-radius:8px;border:1px solid var(--line,rgba(127,127,127,.3))}
.wren-shop .shop-thumbs{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem}
.wren-shop .shop-thumbs button{padding:0;border:2px solid transparent;border-radius:6px;background:none;cursor:pointer;width:64px}
.wren-shop .shop-thumbs button.on{border-color:var(--shop-acc)}
.wren-shop .shop-thumbs img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;border-radius:4px}
@media (max-width:640px){.wren-shop .shop-product{grid-template-columns:1fr;gap:.9rem}}
.wren-shop .shop-button{margin-top:.8rem;font:inherit;font-weight:700;cursor:pointer;padding:.55rem 1.2rem;
  border:0;border-radius:6px;background:var(--shop-acc);color:#fff}
.wren-shop .shop-button[disabled]{opacity:.55;cursor:not-allowed}
.wren-shop .shop-buy{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.8rem}
.wren-shop .shop-buy .shop-button{margin-top:0}
.wren-shop .shop-qty{display:flex;flex-direction:column}
.wren-shop .shop-qty span{font-size:.85em;font-weight:600}
.wren-shop input[type=number]{width:4.5em;font:inherit;padding:.45rem .5rem;border:1px solid var(--line,rgba(127,127,127,.45));
  border-radius:6px;background:var(--card,#fff);color:inherit}
.wren-shop .shop-qty input{margin-top:.25rem;padding:.5rem .55rem}
.wren-shop .shop-note{padding:.55rem .8rem;border-radius:6px;background:var(--paper,rgba(127,127,127,.08))}
.wren-shop .shop-basket{width:100%;border-collapse:collapse;margin:.5rem 0 0}
.wren-shop .shop-basket th,.wren-shop .shop-basket td{padding:.65rem .5rem;border-bottom:1px solid var(--line,rgba(127,127,127,.3));text-align:left;vertical-align:middle}
.wren-shop .shop-basket thead th{font-size:.8em;text-transform:uppercase;letter-spacing:.04em;opacity:.75}
.wren-shop .shop-basket .shop-num{text-align:right;white-space:nowrap}
.wren-shop .shop-basket tfoot th,.wren-shop .shop-basket tfoot td{border-bottom:0;font-weight:bold}
.wren-shop .shop-basket tfoot th{text-align:right}
.wren-shop .shop-item>div{display:flex;align-items:center;gap:.75rem}
.wren-shop .shop-item small{display:block;opacity:.8}
.wren-shop .shop-opt select{margin-top:.25rem;font:inherit;padding:.5rem .55rem;border:1px solid var(--line,rgba(127,127,127,.45));
  border-radius:6px;background:var(--card,#fff);color:inherit;min-width:9em}
.wren-shop .shop-basket tfoot .shop-sub th,.wren-shop .shop-basket tfoot .shop-sub td{font-weight:normal;padding-top:.35rem;padding-bottom:.35rem}
.wren-shop .shop-basket tfoot .shop-grand th,.wren-shop .shop-basket tfoot .shop-grand td{font-size:1.1em;border-top:1px solid var(--line,rgba(127,127,127,.3))}
.wren-shop .shop-vat{font-size:.85em;opacity:.8}
.wren-shop .shop-was{font-weight:normal;opacity:.6;margin-left:.2rem}
.wren-shop .shop-yours{font-weight:normal;font-size:.8em;opacity:.8;margin-left:.2rem;white-space:nowrap;display:inline-block}
.wren-shop .shop-code{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem .6rem;margin-right:auto}
.wren-shop .shop-code label{font-weight:600}
.wren-shop .shop-code input{width:11rem;max-width:100%;text-transform:uppercase;padding:.45rem .6rem;font:inherit;
  border:1px solid var(--line,rgba(127,127,127,.45));border-radius:6px;background:var(--card,#fff);color:inherit}
.wren-shop .shop-postcode{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem .6rem;margin:1rem 0}
.wren-shop .shop-postcode label{font-weight:600}
.wren-shop .shop-postcode input{width:8rem;text-transform:uppercase;padding:.45rem .6rem;font:inherit;border:1px solid var(--line,rgba(127,127,127,.45));border-radius:6px;background:var(--card,#fff);color:inherit}
.wren-shop .shop-postcode p{flex-basis:100%;margin:0}
.wren-shop .shop-code-name{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.9em;letter-spacing:.03em}
.wren-shop .shop-delivery{border:1px solid var(--line,rgba(127,127,127,.3));border-radius:8px;padding:.6rem 1rem .8rem;margin:0 0 1rem}
.wren-shop .shop-delivery legend{font-weight:bold;padding:0 .3rem}
.wren-shop .shop-delivery label{display:block;margin-top:.35rem;cursor:pointer}
.wren-shop .shop-free{font-weight:600}
.wren-shop a.shop-button{display:inline-block;text-decoration:none}
.wren-shop .shop-form fieldset{border:1px solid var(--line,rgba(127,127,127,.3));border-radius:8px;padding:.4rem 1rem 1rem;margin:0 0 1.2rem}
.wren-shop .shop-form legend{font-weight:bold;padding:0 .3rem}
.wren-shop .shop-form label{display:block;margin-top:.75rem;font-weight:600;font-size:.95em}
.wren-shop .shop-form input[type=text],.wren-shop .shop-form input:not([type]),.wren-shop .shop-form input[type=email],
.wren-shop .shop-form input[type=tel],.wren-shop .shop-form input[type=password],.wren-shop .shop-form textarea{display:block;width:100%;margin-top:.25rem;font:inherit;font-weight:normal;
  padding:.55rem .65rem;border:1px solid var(--line,rgba(127,127,127,.45));border-radius:6px;background:var(--card,#fff);color:inherit;box-sizing:border-box}
.wren-shop .shop-form input.shop-short{max-width:10em}
.wren-shop .shop-form .shop-radio{display:flex;gap:.6rem;align-items:flex-start;font-weight:normal;cursor:pointer}
.wren-shop .shop-form .shop-radio input{margin-top:.3rem}
.wren-shop .shop-hint{font-weight:normal;font-size:.85em;opacity:.8}
.wren-shop .shop-errors ul{margin:.3rem 0 0;padding-left:1.2rem}
.wren-shop .shop-errors{border-left:4px solid #b3261e}
.wren-shop .shop-h2{font-size:1.15em;margin:1.6rem 0 .4rem}
.wren-shop .shop-summary small{opacity:.8}
.wren-shop .shop-seller{font-size:.9em;margin-top:1rem}
.wren-shop .shop-bank dl{display:grid;grid-template-columns:auto 1fr;gap:.25rem 1rem;margin:.6rem 0 0}
.wren-shop .shop-bank dt{opacity:.8}.wren-shop .shop-bank dd{margin:0;font-variant-numeric:tabular-nums}
.wren-shop [hidden]{display:none!important}
@media (max-width:640px){.wren-shop .shop-summary tr{display:table-row!important}.wren-shop .shop-summary td,.wren-shop .shop-summary th{display:table-cell!important;padding:.45rem .3rem!important}}
.wren-shop .shop-item img{width:56px;height:56px;object-fit:cover;border-radius:6px;display:block}
.wren-shop .shop-remove,.wren-shop .shop-update{font:inherit;cursor:pointer;background:none;border:1px solid var(--line,rgba(127,127,127,.45));
  border-radius:6px;padding:.3rem .65rem;color:inherit}
.wren-shop .shop-remove:hover,.wren-shop .shop-update:hover{border-color:var(--shop-acc);color:var(--shop-acc)}
.wren-shop .shop-basket-actions{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:flex-end;align-items:center;margin:.75rem 0 1rem}
.wren-shop .shop-default,.wren-shop .shop-vh{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
@media (max-width:640px){
  .wren-shop .shop-basket thead{display:none}
  .wren-shop .shop-basket tr.shop-line{display:grid;grid-template-columns:1fr auto;gap:.35rem .75rem;padding:.75rem 0;border-bottom:1px solid var(--line,rgba(127,127,127,.3))}
  .wren-shop .shop-basket tr.shop-line td{border:0;padding:0}
  .wren-shop .shop-basket tr.shop-line .shop-item{grid-column:1/-1}
  .wren-shop .shop-basket tr.shop-line td[data-label]::before{content:attr(data-label);font-size:.85em;opacity:.75;margin-right:.5rem}
  .wren-shop .shop-basket tr.shop-line td.shop-num{display:flex;justify-content:space-between;align-items:center;grid-column:1/-1}
  .wren-shop .shop-basket tr.shop-line td:last-child{justify-content:flex-end}
  .wren-shop .shop-basket tfoot tr{display:flex;justify-content:space-between}
  .wren-shop .shop-basket tfoot th,.wren-shop .shop-basket tfoot td{padding:.75rem 0}
  .wren-shop .shop-basket tfoot td:empty{display:none}
  .wren-shop .shop-basket tfoot .shop-discount{align-items:center;gap:.6rem}
  .wren-shop .shop-basket tfoot .shop-discount th{flex:1;text-align:left}
  .wren-shop .shop-basket tfoot .shop-discount td{padding:.4rem 0}
}
</style>' . "\n";
}

/* ------------------------------------------------------------- products */

function product_photos(int $id): array
{
    $st = db()->prepare('SELECT * FROM photos WHERE product_id = ? ORDER BY position, id');
    $st->execute([$id]);
    return $st->fetchAll();
}

/* product id => first photo, for lists */
function first_photos(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $out = [];
    foreach (db()->query("SELECT * FROM photos WHERE product_id IN ($in) ORDER BY position DESC, id DESC") as $ph) {
        $out[(int)$ph['product_id']] = $ph;   // last write wins = lowest position
    }
    return $out;
}

/* --------------------------------------------------------------- options */
/* A product can have one named set of choices (e.g. Size). Each choice may
   have its own price (else the product's) and its own stock (else not counted).
   When a product has choices, stock is only ever counted per choice. */

function product_variants(int $pid): array
{
    $st = db()->prepare('SELECT * FROM variants WHERE product_id = ? ORDER BY position, id');
    $st->execute([$pid]);
    return $st->fetchAll();
}

/* product id => its choices, for lists */
function variants_for(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $out = [];
    $in = implode(',', array_map('intval', $ids));
    foreach (db()->query("SELECT * FROM variants WHERE product_id IN ($in) ORDER BY position, id") as $v) {
        $out[(int)$v['product_id']][] = $v;
    }
    return $out;
}

function variant_price(array $p, ?array $v): int
{
    return $v && $v['price_pence'] !== null ? (int)$v['price_pence'] : (int)$p['price_pence'];
}

function variant_sold_out(array $v, ?array $p = null): bool
{
    return $v['stock'] !== null && (int)$v['stock'] <= 0 && !($p && backorders_on($p));
}

function sold_out(array $p, array $vars = []): bool
{
    if (backorders_on($p)) {   // keeps selling below zero
        return false;
    }
    if ($vars) {
        foreach ($vars as $v) {
            if (!variant_sold_out($v)) {
                return false;
            }
        }
        return true;
    }
    return $p['stock'] !== null && (int)$p['stock'] <= 0;
}

/* [lowest, highest] price for this product: full prices, or with any sale taken off. */
function price_range(array $p, array $vars, bool $sale = false): array
{
    if (!$vars) {
        $one = $sale ? unit_price($p, null) : (int)$p['price_pence'];
        return [$one, $one];
    }
    $prices = array_map(fn($v) => $sale ? unit_price($p, $v) : variant_price($p, $v), $vars);
    return [min($prices), max($prices)];
}

function price_label(array $p, array $vars): string
{
    [$lo, $hi] = price_range($p, $vars);
    return $lo === $hi ? money($lo) : 'From ' . money($lo);
}

function categories(): array
{
    return db()->query('SELECT * FROM categories ORDER BY position, name COLLATE NOCASE')->fetchAll();
}

/* --------------------------------------------------------------- paging */
/* Lists show a page at a time. A small shop never sees any of this: no page
   links until there's more than a page, and no sort menu until there are a
   dozen products. */

const SORTS = ['new' => 'Newest', 'price' => 'Price, low to high', 'price-desc' => 'Price, high to low', 'name' => 'Name'];
const SORT_MENU_FROM = 12;

function per_page(): int
{
    return max(6, min(96, (int)setting('per_page', '24')));
}

/* ?page=N, 1 if missing or nonsense. */
function list_page(): int
{
    $p = str_in($_GET['page'] ?? '');
    return ctype_digit($p) && (int)$p >= 1 ? min((int)$p, 100000) : 1;
}

/* ?sort=, from the list's own sort menu (the first one is its default). */
function list_sort(array $sorts = SORTS): string
{
    $s = str_in($_GET['sort'] ?? '');
    return isset($sorts[$s]) ? $s : (string)array_key_first($sorts);
}

/* A URL with extra query values, leaving out empty ones and defaults. Works for
   pretty URLs and for ?q= ones. */
function url_with(string $url, array $params): string
{
    $params = array_filter($params, fn($v, $k) => $v !== '' && $v !== null && !($k === 'page' && (int)$v === 1), ARRAY_FILTER_USE_BOTH);
    if (!$params) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
}

/* The lowest price a product can be bought for, as SQL: the sale price when it's
   on sale today, and this customer's group price when that's lower (for sorting). */
function price_sql(): string
{
    $today = db()->quote(uk_today());
    $on = "(p.sale_from <= $today AND (p.sale_to = '' OR p.sale_to >= $today))";
    $g = customer_group();
    $rate = '0';
    if ($g) {
        // The main category's rate, else the nearest category it sits in, else the group's own.
        $at = fn($cat) => '(SELECT gr.percent FROM group_rates gr WHERE gr.group_id = ' . (int)$g['id'] . ' AND gr.category_id = ' . $cat . ')';
        $up = fn($cat) => '(SELECT parent_id FROM categories WHERE id = ' . $cat . ')';
        $rate = 'COALESCE(' . $at('p.category_id') . ', ' . $at($up('p.category_id')) . ', ' . $at($up($up('p.category_id'))) . ', ' . (int)$g['percent'] . ')';
    }
    // One price: the lower of its sale price (if on sale) and the full price less the group rate.
    // A fixed group price, where the group has one, instead of its percentage.
    $fix = fn(string $vid) => $g ? '(SELECT gp.price_pence FROM group_prices gp WHERE gp.group_id = ' . (int)$g['id'] . ' AND gp.product_id = p.id AND gp.variant_id = ' . $vid . ')' : 'NULL';
    $own = fn(string $full, string $sale, string $fixed) => "MIN(CASE WHEN $on AND $sale IS NOT NULL AND $sale < $full THEN $sale ELSE $full END, COALESCE($fixed, ROUND($full * (100 - $rate) / 100.0)))";
    return 'COALESCE((SELECT MIN(CASE WHEN v.price_pence IS NULL THEN ' . $own('p.price_pence', 'p.sale_pence', 'COALESCE(' . $fix('v.id') . ', ' . $fix('0') . ')')
         . ' ELSE ' . $own('v.price_pence', 'v.sale_pence', $fix('v.id')) . ' END)'
         . ' FROM variants v WHERE v.product_id = p.id AND v.active = 1), ' . $own('p.price_pence', 'p.sale_pence', $fix('0')) . ')';
}

function sort_sql(string $sort): string
{
    return match ($sort) {
        'price'      => price_sql() . ' ASC, p.id DESC',
        'price-desc' => price_sql() . ' DESC, p.id DESC',
        'name'       => 'p.name COLLATE NOCASE ASC, p.id ASC',
        default      => 'p.created DESC, p.id DESC',
    };
}

/* One page of visible products matching $where (SQL on alias p, with ?
   placeholders filled from $args). Returns [rows, total]. */
function product_page(string $where, array $args, string $order, int $page, int $per): array
{
    $st = db()->prepare("SELECT COUNT(*) FROM products p WHERE p.visible = 1 AND ($where)");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT p.* FROM products p WHERE p.visible = 1 AND ($where) ORDER BY " . $order
                      . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($args);
    return [$st->fetchAll(), $total];
}

/* Page links: Previous 1 … 4 5 6 … 20 Next. $url makes the link for a page. */
function pager_html(int $page, int $pages, callable $url, string $class = 'shop-pager'): string
{
    if ($pages <= 1) {
        return '';
    }
    $show = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages],
                                      fn($n) => $n >= 1 && $n <= $pages));
    sort($show);
    $out = '';
    $last = 0;
    foreach ($show as $n) {
        if ($n > $last + 1) {
            $out .= '<li class="gap" aria-hidden="true">&hellip;</li>';
        }
        $out .= $n === $page
            ? '<li><span aria-current="page">' . $n . '</span></li>'
            : '<li><a href="' . e($url($n)) . '" aria-label="Page ' . $n . '">' . $n . '</a></li>';
        $last = $n;
    }
    $prev = $page > 1 ? '<li class="step"><a href="' . e($url($page - 1)) . '" rel="prev">&larr; Previous</a></li>' : '';
    $next = $page < $pages ? '<li class="step"><a href="' . e($url($page + 1)) . '" rel="next">Next &rarr;</a></li>' : '';
    return '<nav class="' . $class . '" aria-label="Pages"><ul>' . $prev . $out . $next . '</ul></nav>';
}

/* "Sort by" menu, a plain GET form that keeps ?q= for shops without pretty URLs. */
function sort_html(string $url, string $sort, array $keep = [], array $sorts = SORTS): string
{
    $hidden = '';
    $qs = parse_url($url, PHP_URL_QUERY);
    parse_str((string)$qs, $have);
    foreach (array_merge($have, $keep) as $k => $v) {
        if (is_string($v) && $v !== '' && !in_array($k, ['sort', 'page'], true)) {
            $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
    }
    $opts = '';
    foreach ($sorts as $k => $label) {
        $opts .= '<option value="' . $k . '"' . ($k === $sort ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return '<form class="shop-sort" method="get" action="' . e(strtok($url, '?')) . '">' . $hidden
         . '<label>Sort by <select name="sort" onchange="this.form.submit()">' . $opts . '</select></label>'
         . '<noscript><button class="shop-button shop-quiet">Sort</button></noscript></form>';
}

/* The product cards for a list of rows. */
function product_cards(array $rows): string
{
    $ids = array_column($rows, 'id');
    $photos = first_photos($ids);
    $vars = variants_for($ids);
    $cards = '';
    foreach ($rows as $p) {
        $all = $vars[(int)$p['id']] ?? [];
        $pv = live_choices($all);
        $off = $all && !$pv;   // every combination switched off
        $link = e(shop_url('p/' . $p['slug']));
        $ph = $photos[(int)$p['id']] ?? null;
        $img = $ph ? '<a href="' . $link . '" tabindex="-1" aria-hidden="true"><img src="' . e(media_url($ph['thumb']))
                   . '" alt="" width="400" height="400" loading="lazy"></a>' : '';
        $cards .= '<div class="shop-card">' . $img
                . ($off || sold_out($p, $pv) ? '<span class="shop-soldout">Sold out</span>' : (product_on_sale($p, $pv) ? '<span class="shop-sale">Sale</span>' : ''))
                . '<div class="shop-card-text"><h2><a href="' . $link . '">' . e($p['name']) . '</a></h2>'
                . '<p class="shop-price">' . shop_price_html($p, $pv) . '</p></div></div>';
    }
    return $cards;
}

/* A paged, sortable grid: the count line, sort menu, cards and page links.
   Returns [html, page, pages]; a page past the end is a 404. */
/* $o: 'sorts' (the sort menu, default first), 'best' (ORDER BY for a 'best' sort),
   'keep' (query values every link keeps, like the search words), 'noun' ("products"). */
/* ($o['count']: show how many even for a short list.) */
function product_grid(string $where, array $args, string $route, string $empty, array $o = []): array
{
    $page = list_page();
    $sorts = $o['sorts'] ?? SORTS;
    $sort = list_sort($sorts);
    $def = (string)array_key_first($sorts);
    $per = per_page();
    [$rows, $total] = product_page($where, $args, $sort === 'best' ? $o['best'] : sort_sql($sort), $page, $per);
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) {
        view_404();
    }
    if (!$rows) {
        return ['<p>' . $empty . '</p>', 1, 1];
    }
    $base = url_with(shop_url($route), $o['keep'] ?? []);
    $from = ($page - 1) * $per + 1;
    $to = $from + count($rows) - 1;
    $bar = '';
    if ($total >= SORT_MENU_FROM || !empty($o['count'])) {
        $bar = '<div class="shop-listbar"><p class="shop-count">'
             . ($pages > 1 ? number_format($from) . '&ndash;' . number_format($to) . ' of ' : '') . number_format($total) . ' ' . ($total === 1 ? ($o['noun1'] ?? 'product') : ($o['noun'] ?? 'products')) . '</p>'
             . ($total >= SORT_MENU_FROM ? sort_html($base, $sort, [], $sorts) : '') . '</div>';
    }
    $html = $bar . '<div class="shop-grid">' . product_cards($rows) . '</div>'
          . pager_html($page, $pages, fn($n) => url_with($base, ['sort' => $sort === $def ? '' : $sort, 'page' => $n]));
    return [$html, $page, $pages];
}

/* ---------------------------------------------------------------- views */

function view_list(?string $catSlug = null): never
{
    $cat = null;
    if ($catSlug !== null) {
        $st = db()->prepare('SELECT * FROM categories WHERE slug = ?');
        $st->execute([$catSlug]);
        $cat = $st->fetch() ?: null;
        if (!$cat) {
            view_404();
        }
    }
    $route = $cat ? 'c/' . $cat['slug'] : '';
    [$grid, $page, $pages] = $cat
        ? product_grid(cat_in_sql(cat_descendants((int)$cat['id'])), [], $route, 'Nothing for sale here yet.')
        : product_grid('1', [], $route, 'Nothing for sale here yet.');
    // The categories inside this one, to narrow it down (first page only).
    $subs = '';
    if ($cat && $page === 1) {
        foreach (cat_children((int)$cat['id']) as $c) {
            if (isset(cat_live()[$c['id']])) {
                $subs .= '<li><a href="' . e(shop_url('c/' . $c['slug'])) . '">' . e($c['name']) . '</a></li>';
            }
        }
        $subs = $subs !== '' ? '<ul class="shop-subcats" aria-label="In ' . e($cat['name']) . '">' . $subs . '</ul>' : '';
    }
    $trail = $cat ? cat_trail((int)$cat['id']) : [];

    $title = $cat ? $cat['name'] : setting('shop_title', 'Shop');
    // "Spares" alone says little in a search result or a browser tab: "SRX2 Spares".
    $tab = $cat && count($trail) > 1 ? $trail[count($trail) - 2]['name'] . ' ' . $cat['name'] : $title;
    render($tab . ($page > 1 ? ' (page ' . $page . ')' : ''),
        '<h1 class="post-title">' . e($title) . '</h1><div class="post-body">' . $subs . $grid . '</div>',
        ['description' => ($cat ? $tab . ' from ' : 'Everything for sale from ') . setting('last_site_title', 'us') . '.'
                        . ($page > 1 ? ' Page ' . $page . ' of ' . $pages . '.' : ''),
         'route' => $route,
         'query' => ['page' => $page],
         'noindex' => list_sort() !== 'new',   // sorted copies of the same list
         'cat' => $cat ? (int)$cat['id'] : 0,
         'crumbs' => array_map(fn($c) => [$c['name'], $c['id'] === (int)$cat['id'] ? null : 'c/' . $c['slug']], $trail)]);
}

function view_product(string $slug): never
{
    $st = db()->prepare('SELECT * FROM products WHERE slug = ? AND visible = 1');
    $st->execute([$slug]);
    $p = $st->fetch();
    if (!$p) {
        view_404();
    }
    $photos = product_photos((int)$p['id']);
    $allVars = product_variants((int)$p['id']);
    $vars = live_choices($allVars);   // switched-off combinations are never offered
    $gallery = '';
    if ($photos) {
        $gallery = '<div><img class="shop-photo" id="shop-main" src="' . e(media_url($photos[0]['file'])) . '" alt="' . e($p['name']) . '">';
        if (count($photos) > 1) {
            $gallery .= '<div class="shop-thumbs">';
            foreach ($photos as $i => $ph) {
                $gallery .= '<button type="button"' . ($i === 0 ? ' class="on"' : '') . ' data-src="' . e(media_url($ph['file']))
                          . '" aria-label="Photo ' . ($i + 1) . '"><img src="' . e(media_url($ph['thumb'])) . '" alt=""></button>';
            }
            $gallery .= '</div><script>document.querySelectorAll(".shop-thumbs button").forEach(function(b){b.onclick=function(){'
                      . 'document.getElementById("shop-main").src=b.dataset.src;'
                      . 'document.querySelectorAll(".shop-thumbs button").forEach(function(x){x.classList.toggle("on",x===b)})}})</script>';
        }
        $gallery .= '</div>';
    }
    $out = ($allVars && !$vars) || sold_out($p, $vars);
    $choose = '';
    if ($vars && !$out) {
        $label = option_title($p);
        $base = (int)$p['price_pence'];
        $opts = '<option value="">Choose ' . e(mb_strtolower($label)) . '</option>';
        foreach ($vars as $v) {
            $price = variant_price($p, $v);
            $gone = variant_sold_out($v, $p);
            $own = own_price($p, $v)[0];
            $wait = !$gone && backorder_told($p) && $v['stock'] !== null && (int)$v['stock'] <= 0;
            $opts .= '<option value="' . (int)$v['id'] . '" data-price="' . e(own_price_html($p, $v)) . '" data-max="' . qty_max($p, $v) . '"'
                   . ' data-sku="' . e($v['sku'] !== '' ? $v['sku'] : $p['sku']) . '"'
                   . ' data-a="' . e($v['name']) . '" data-b="' . e($v['name2'] ?? '') . '"'
                   . ($gone ? ' disabled' : '') . '>' . e(choice_name($v))
                   . ($price !== $base || $own < $price ? ' – ' . money($own) : '') . ($gone ? ' – sold out' : '') . ($wait ? ' – on backorder' : '') . '</option>';
        }
        $choose = '<label class="shop-qty shop-opt"><span>' . e($label) . '</span> <select name="variant" id="shop-variant" required>'
                . $opts . '</select></label>';
    }
    $max = $vars ? QTY_LIMIT : qty_max($p);
    $buy = $out
        ? '<p><span class="shop-soldout">Sold out</span></p>'
        : '<form class="shop-buy" method="post" action="' . e(shop_url('basket/add')) . '">' . csrf_field()
          . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">' . $choose
          . '<label class="shop-qty"><span>Quantity</span> <input type="number" name="qty" id="shop-qty" value="1" min="1" max="' . $max
          . '" step="1" inputmode="numeric" required></label>'
          . '<button class="shop-button">Add to basket</button></form>';
    if ($choose && ($p['option2_name'] ?? '') !== '' && array_filter($vars, fn($x) => ($x['name2'] ?? '') !== '')) {
        // Two options: two menus that pick the combination in the single menu (which works on its own without JavaScript).
        $buy .= '<script>(function(){var s=document.getElementById("shop-variant");if(!s)return;var w=s.closest("label"),N1=' . json_encode($p['option_name'] ?: 'Option')
              . ',N2=' . json_encode($p['option2_name']) . ',o=[].slice.call(s.options).filter(function(x){return x.value;});'
              . 'function uniq(k){var a=[];o.forEach(function(x){if(a.indexOf(x.dataset[k])<0)a.push(x.dataset[k]);});return a;}'
              . 'function mk(n,vals){var l=document.createElement("label");l.className="shop-qty shop-opt";var sp=document.createElement("span");sp.textContent=n;'
              . 'var sel=document.createElement("select");sel.required=true;var f=document.createElement("option");f.value="";f.textContent="Choose "+n.toLowerCase();sel.appendChild(f);'
              . 'vals.forEach(function(v){var x=document.createElement("option");x.value=v;x.textContent=v;sel.appendChild(x);});l.appendChild(sp);l.appendChild(document.createTextNode(" "));l.appendChild(sel);return [l,sel];}'
              . 'var A=mk(N1,uniq("a")),B=mk(N2,uniq("b"));w.hidden=true;s.required=false;w.parentNode.insertBefore(A[0],w);w.parentNode.insertBefore(B[0],w);'
              . 'function find(a,b){for(var i=0;i<o.length;i++)if(o[i].dataset.a===a&&o[i].dataset.b===b)return o[i];return null;}'
              . 'function sync(){var a=A[1].value;[].forEach.call(A[1].options,function(x){if(x.value)x.disabled=!o.some(function(y){return y.dataset.a===x.value&&!y.disabled;});});'
              . '[].forEach.call(B[1].options,function(x){if(!x.value)return;var m=a?find(a,x.value):null;'
              . 'x.disabled=a?(!m||m.disabled):!o.some(function(y){return y.dataset.b===x.value&&!y.disabled;});'
              . 'x.textContent=x.value+(a&&!m?" \u2013 not available":(m&&m.disabled?" \u2013 sold out":""));});'
              . 'if(B[1].value&&B[1].options[B[1].selectedIndex].disabled)B[1].value="";var m=A[1].value&&B[1].value?find(A[1].value,B[1].value):null;'
              . 's.value=m&&!m.disabled?m.value:"";s.dispatchEvent(new Event("change"));}'
              . 'A[1].addEventListener("change",sync);B[1].addEventListener("change",sync);sync();})();</script>';
    }
    if ($choose) {
        // Show the chosen option's price, and let the quantity go only as high as its stock.
        $buy .= '<script>(function(){var s=document.getElementById("shop-variant"),p=document.getElementById("shop-price"),'
              . 'q=document.getElementById("shop-qty"),d=p.innerHTML;s.addEventListener("change",function(){'
              . 'var o=s.options[s.selectedIndex],k=document.getElementById("shop-sku");p.innerHTML=o.value?o.dataset.price:d;'
              . 'if(k){k.textContent=o.value&&o.dataset.sku?o.dataset.sku:k.dataset.base;k.parentNode.hidden=!k.textContent;}'
              . 'q.max=o.value?o.dataset.max:"' . QTY_LIMIT . '";if(+q.value>+q.max)q.value=q.max;});})();</script>';
    }
    if (is_digital($p)) {
        $buy .= '<p class="shop-hint shop-digital">Download: yours to download from your account as soon as you\'ve paid'
              . ((int)$p['licensed'] ? '. Licensed for one website, with ' . (int)$p['update_months'] . ' months of updates included.' : '.') . '</p>';
    }
    if (!$out && !$vars && $p['stock'] !== null && (int)$p['stock'] <= 3) {
        if (!backorders_on($p)) {
            $buy .= '<p><em>Only ' . (int)$p['stock'] . ' left.</em></p>';
        } elseif (backorder_told($p)) {
            $buy .= '<p class="shop-hint shop-backorder">' . ((int)$p['stock'] > 0
                ? 'Only ' . (int)$p['stock'] . ' in stock; more can be ordered and will follow when they arrive.'
                : 'Available on backorder: we\'ll send it as soon as it\'s back in stock.') . '</p>';
        }
    }
    $summary = plain_summary($p['description'], 500);
    // Part number: the product's, or the chosen option's once one is picked.
    $anySku = $p['sku'] !== '' || array_filter($vars, fn($x) => $x['sku'] !== '');
    $skuHtml = $anySku ? '<p class="shop-hint shop-sku"' . ($p['sku'] === '' ? ' hidden' : '') . '>SKU <span id="shop-sku" data-base="' . e($p['sku']) . '">'
             . e($p['sku']) . '</span></p>' : '';
    $pc = null;
    if ((int)$p['category_id']) {
        $st = db()->prepare('SELECT * FROM categories WHERE id = ?');
        $st->execute([$p['category_id']]);
        $pc = $st->fetch() ?: null;
    }
    [$lo, $hi] = price_range($p, $vars, true);
    $none = $vars ? !array_filter($vars, fn($x) => $x['stock'] === null || (int)$x['stock'] > 0) : ($p['stock'] !== null && (int)$p['stock'] <= 0);
    $availability = 'https://schema.org/' . ($out ? 'OutOfStock' : ($none && backorders_on($p) ? 'BackOrder' : 'InStock'));
    $offers = $vars
        ? ['@type' => 'AggregateOffer', 'lowPrice' => price_input($lo), 'highPrice' => price_input($hi),
           'offerCount' => count($vars), 'priceCurrency' => 'GBP', 'availability' => $availability]
        : ['@type' => 'Offer', 'price' => price_input($lo), 'priceCurrency' => 'GBP', 'availability' => $availability];
    $f = flash();
    render($p['name'], '<h1 class="post-title">' . e($p['name']) . '</h1>'
        . ($f ? '<p class="shop-note" role="status">' . e($f) . '</p>' : '')
        . '<div class="post-body' . ($photos ? ' shop-product' : '') . '">' . $gallery
        . '<div><p class="shop-price" id="shop-price">' . shop_price_html($p, $vars) . '</p>'
        . $skuHtml . md_lite($p['description']) . $buy
        . '<p><a href="' . e(shop_url($pc ? 'c/' . $pc['slug'] : '')) . '">&larr; ' . ($pc ? 'More ' . e($pc['name']) : 'Back to the shop') . '</a></p></div></div>'
        . related_html($p),
        [
            'description' => plain_summary($p['description']),
            'route'       => 'p/' . $p['slug'],
            'type'        => 'product',
            'cat'         => $pc ? (int)$pc['id'] : null,
            'crumbs'      => array_merge(array_map(fn($c) => [$c['name'], 'c/' . $c['slug']], $pc ? cat_trail((int)$pc['id']) : []), [[$p['name'], null]]),
            'image'       => $photos ? origin() . media_url($photos[0]['file']) : null,
            'jsonld'      => [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $p['name'],
                'description' => $summary,
            ] + ($p['sku'] !== '' ? ['sku' => $p['sku']] : []) + [
                'url'         => abs_shop_url('p/' . $p['slug']),
                'image'       => $photos ? origin() . media_url($photos[0]['file']) : null,
                'offers'      => $offers,
            ],
        ]);
}

/* --------------------------------------------------------------- basket */
/* The basket lives in the shop's session as [key => quantity], where the key
   is "product id" or "product id:choice id". */

const QTY_LIMIT = 99;   // most of one product (or one choice) in one basket

function basket_count(): int
{
    return array_sum($_SESSION['shop_basket'] ?? []);
}

function basket_key(int $pid, ?int $vid): string
{
    return $vid ? $pid . ':' . $vid : (string)$pid;
}

/* What a basket key refers to now: [product, choice or null], or null if it
   has been hidden, deleted, or its choices have changed since. */
function basket_item(string|int $key): ?array
{
    if (str_starts_with((string)$key, 'r')) {
        return renewal_item((string)$key);
    }
    $parts = explode(':', (string)$key, 2);
    $pid = (int)$parts[0];
    $vid = isset($parts[1]) ? (int)$parts[1] : null;
    $st = db()->prepare('SELECT * FROM products WHERE id = ? AND visible = 1');
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $vars = product_variants($pid);
    if (!$vars) {
        return $vid ? null : [$p, null];
    }
    foreach ($vars as $v) {
        if ((int)$v['id'] === $vid) {
            return (int)($v['active'] ?? 1) === 1 ? [$p, $v] : null;   // switched off since it was added
        }
    }
    return null;
}

function item_name(array $p, ?array $v): string
{
    return $v ? $p['name'] . ' (' . choice_label($p, $v) . ')' : $p['name'];
}

/* The most of a product, or of one of its choices, one basket may hold. */
function qty_max(array $p, ?array $v = null): int
{
    if (is_digital($p)) {   // one copy of a download; a theme licence per website, so a few at most
        return isset($p['renew_licence']) || !(int)$p['licensed'] ? 1 : 10;
    }
    $stock = $v ? $v['stock'] : $p['stock'];
    return $stock === null || backorders_on($p) ? QTY_LIMIT : max(0, min(QTY_LIMIT, (int)$stock));
}

function qty_from(mixed $raw, int $min): int
{
    $n = is_string($raw) && preg_match('/^\s*\d{1,4}\s*$/', $raw) ? (int)$raw : $min;
    return max($min, min(QTY_LIMIT, $n));
}

function basket_add(): never
{
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM products WHERE id = ? AND visible = 1');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        redirect(shop_url('basket'));
    }
    $v = null;
    $vars = product_variants($id);
    if ($vars) {
        $vid = (int)($_POST['variant'] ?? 0);
        foreach ($vars as $x) {
            if ((int)$x['id'] === $vid) {
                $v = $x;
            }
        }
        if (!$v) {
            flash('Please choose ' . mb_strtolower($p['option_name'] !== '' ? option_title($p) : 'an option') . ' first.');
            redirect(shop_url('p/' . $p['slug']) . '#shop-variant');
        }
    }
    $key = basket_key($id, $v ? (int)$v['id'] : null);
    $name = item_name($p, $v);
    $have = (int)($_SESSION['shop_basket'][$key] ?? 0);
    $want = qty_from($_POST['qty'] ?? '1', 1);
    $max = qty_max($p, $v);
    $room = $max - $have;
    if ($room <= 0) {
        flash($max === 0 ? $name . ' has just sold out.'
            : 'Your basket already has ' . ($max === QTY_LIMIT ? 'the most allowed' : 'all ' . $max) . ' of ' . $name . '.');
    } elseif ($want > $room) {
        $_SESSION['shop_basket'][$key] = $max;
        flash('Only ' . $max . ' of ' . $name . ' left, so your basket has all ' . $max . '.');
    } else {
        $_SESSION['shop_basket'][$key] = $have + $want;
        flash('Added ' . ($want > 1 ? $want . ' × ' : '') . $name . ' to your basket.');
    }
    redirect(shop_url('basket'));
}

/* One form covers the whole basket, so changed quantities are kept whichever
   button is pressed: Update, or Remove on one line. 0 also removes a line. */
function basket_update(): never
{
    csrf_check();
    $basket = $_SESSION['shop_basket'] ?? [];
    $qtys = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $remove = str_in($_POST['remove'] ?? '');
    $notes = [];
    foreach ($basket as $key => $have) {
        $raw = $qtys[$key] ?? null;
        // A box left as nonsense keeps what was there; only a real number changes it.
        $n = is_string($raw) && preg_match('/^\s*\d{1,4}\s*$/', $raw) ? min(QTY_LIMIT, (int)$raw) : (int)$have;
        $item = basket_item($key);
        if ((string)$key === $remove || $n === 0 || !$item) {
            unset($basket[$key]);
            continue;
        }
        [$p, $v] = $item;
        $max = qty_max($p, $v);
        if ($n > $max) {
            $n = $max;
            $notes[] = $max === 0 ? item_name($p, $v) . ' has sold out.' : 'Only ' . $max . ' of ' . item_name($p, $v) . ' left.';
        }
        if ($n > 0) {
            $basket[$key] = $n;
        } else {
            unset($basket[$key]);
        }
    }
    $_SESSION['shop_basket'] = $basket;
    $how = str_in($_POST['delivery'] ?? '');
    if ($how === 'deliver' || $how === 'collect' || preg_match('/^m\d+$/', $how)) {
        $_SESSION['shop_delivery'] = $how;
    }
    if (isset($_POST['postcode'])) {   // to see delivery to a zone before checkout
        $pc = trim(str_in($_POST['postcode']));
        $ok = $pc === '' ? '' : uk_postcode($pc);
        if ($ok === null) {
            $notes[] = 'That postcode doesn\'t look right. Try one like BS49 4AB.';
        } else {
            $_SESSION['shop_postcode'] = $ok;
        }
    }
    $lines = basket_lines();
    $code = basket_discount_post(array_sum(array_map(fn($l) => $l['qty'] * $l['full'], $lines)));
    if ($code !== null) {
        $notes[] = $code;
    }
    flash($notes ? implode(' ', $notes) : ($remove !== '' ? 'Removed from your basket.' : 'Basket updated.'));
    redirect(shop_url('basket'));
}

/* ----------------------------------------------------- postage and totals */

function can_deliver(): bool
{
    return setting('postage_delivery', '1') === '1';
}

function can_collect(): bool
{
    return setting('postage_collect', '0') === '1';
}

/* Everything checkout needs to know about the money, from the goods total. */
/* $subtotal is the goods at the prices on the lines (sale prices where on sale);
   $saleSaving is what those sale prices take off the full prices. */
function basket_totals(int $subtotal, string $email = '', int $groupSaving = 0, int $saleSaving = 0): array
{
    $saleSub = $subtotal;
    $fullSub = $subtotal + $saleSaving;
    // Which way it's going: the method chosen in the basket if it can take this order,
    // else the first that can. Weight and the delivery postcode's zone decide that.
    $physical = basket_physical();
    $grams = $physical ? basket_weight() : 0;
    $zone = zone_for_postcode((string)($_SESSION['shop_postcode'] ?? ''));
    $quotes = [];
    $chosen = null;
    foreach (delivery_methods() as $m) {
        $quotes[$m['key']] = $m + ['quote' => method_quote($m, $saleSub, $grams, $zone)];
    }
    $want = (string)($_SESSION['shop_delivery'] ?? '');
    if (isset($quotes[$want]) && $quotes[$want]['quote']['ok']) {
        $chosen = $quotes[$want];
    }
    foreach ($chosen ? [] : $quotes as $q) {
        if ($q['quote']['ok']) {
            $chosen = $q;
            break;
        }
    }
    $chosen ??= $quotes[$want] ?? (reset($quotes) ?: null);
    $unavailable = $chosen && !$chosen['quote']['ok'] && $physical ? $chosen['name'] . ': ' . $chosen['quote']['why'] : '';
    $method = $chosen['kind'] ?? 'deliver';
    if (!$physical) {   // downloads only: nothing to post or collect
        $method = 'none';
    }
    // Delivery for goods worth $sub: [price or null if not set up yet, how much more for free delivery].
    $postFor = function (int $sub) use ($method, $chosen, $grams, $zone): array {
        if ($method === 'none' || !$chosen) {
            return [$method === 'none' ? 0 : null, null];
        }
        $q = method_quote($chosen, $sub, $grams, $zone);
        return $q['ok'] ? [$q['price'], $q['to_free']] : [null, null];
    };
    [$postage, $toFree] = $postFor($saleSub);
    // A discount code comes off the goods at their full prices; free delivery from a
    // code wins over the price.
    $disc = basket_discount($fullSub, $email);
    $d = $disc['d'];
    $note = $disc['note'];
    $freePost = $d && (int)$d['free_postage'] && $method === 'deliver';
    [$fullPost, $fullToFree] = $postFor($fullSub);
    $codePost = $freePost && $fullPost !== null ? 0 : $fullPost;
    // Sale prices, a customer group's prices and a code never add up: whichever total is lower wins.
    $beaten = false;
    $saleOff = false;
    if ($d && ($groupSaving > 0 || $saleSaving > 0)) {
        if ($saleSub - $groupSaving + ($postage ?? 0) <= $fullSub - $disc['pence'] + ($codePost ?? 0)) {
            $g = customer_group();
            $whose = $groupSaving > 0 && $saleSaving > 0 ? 'The sale and your ' . ($g['name'] ?? 'group') . ' prices save'
                   : ($groupSaving > 0 ? 'Your ' . ($g['name'] ?? 'group') . ' prices save' : 'The sale prices save');
            $note = $whose . ' you more than ' . $d['code'] . ', so the code isn\'t used.';
            $d = null;
            $disc['pence'] = 0;
            $freePost = false;
            $beaten = true;
        } else {
            // The code wins: everything at its full price, with the code taken off.
            if ($saleSaving > 0) {
                $note = $d['code'] . ' saves you more than the sale prices, so it\'s used instead (sale prices and codes don\'t add up).';
            }
            $groupSaving = 0;
            $saleOff = $saleSaving > 0;
            $subtotal = $fullSub;
            [$postage, $toFree] = [$fullPost, $fullToFree];
        }
    }
    if ($freePost && $postage !== null) {
        $postage = 0;
        $toFree = null;
    }
    $total = $subtotal - $disc['pence'] - $groupSaving + ($postage ?? 0);
    // Prices include VAT at 20%; delivery follows the goods' rate (standard).
    $vat = setting('vat_registered') === '1' ? (int)round($total / 6) : null;
    return ['subtotal' => $subtotal, 'method' => $method, 'postage' => $postage, 'to_free' => $toFree,
            'method_key' => $method === 'none' ? '' : ($chosen['key'] ?? ''), 'method_name' => $method === 'none' ? '' : ($chosen['name'] ?? 'UK delivery'),
            'method_note' => $chosen['note'] ?? '', 'methods' => $quotes, 'zone' => $method === 'deliver' && $zone ? $zone['name'] : '',
            'unavailable' => $unavailable, 'grams' => $grams,
            'total' => $total, 'vat' => $vat, 'discount' => $disc['pence'], 'code' => $d['code'] ?? '',
            'discount_id' => $d ? (int)$d['id'] : null, 'discount_note' => $note, 'free_post' => $freePost,
            'group' => $groupSaving > 0 ? (customer_group()['name'] ?? '') : '', 'group_pence' => $groupSaving, 'code_beaten' => $beaten,
            'sale_off' => $saleOff];
}

/* What a basket line costs each, once the totals are known (full price when a code beat the sale). */
function line_unit(array $l, array $t): int
{
    return !empty($t['sale_off']) ? $l['full'] : $l['price'];
}

function lines_sale_saving(array $lines): int
{
    return array_sum(array_map(fn($l) => $l['qty'] * ($l['full'] - $l['price']), $lines));
}

function view_basket(): never
{
    $items = $_SESSION['shop_basket'] ?? [];
    $rows = '';
    $subtotal = 0;
    $gsave = 0;
    $ssave = 0;
    $notes = [];
    $keep = [];
    $draw = [];   // rows are drawn once the totals say which prices apply
    if ($items) {
        $ids = array_map(fn($k) => (int)explode(':', (string)$k)[0], array_keys($items));
        $photos = first_photos($ids);
        foreach ($items as $key => $qty) {   // basket order: first added first
            $item = basket_item($key);
            if (!$item) {
                continue;   // hidden, deleted, or its choices changed since it was added
            }
            [$p, $v] = $item;
            $name = item_name($p, $v);
            $qty = (int)$qty;
            $max = qty_max($p, $v);
            if ($qty > $max) {   // stock went down since it was added
                $notes[] = $max === 0 ? $name . ' has sold out, so it\'s been taken out.'
                                      : 'Only ' . $max . ' of ' . $name . ' left now.';
                $qty = $max;
            }
            if ($qty <= 0) {
                continue;
            }
            $keep[$key] = $qty;
            $full = variant_price($p, $v);
            $price = unit_price($p, $v);
            $subtotal += $qty * $price;
            $ssave += $qty * ($full - $price);
            $gsave += lines_group_saving([['p' => $p, 'v' => $v, 'qty' => $qty, 'price' => $price, 'full' => $full]]);
            $draw[] = [$key, $p, $v, $qty, $price, $full, $max, $name, $photos[(int)$p['id']] ?? null];
        }
    }
    $_SESSION['shop_basket'] = $keep;
    $me = accounts_on() ? current_customer() : null;
    $t = $draw ? basket_totals($subtotal, $me['email'] ?? '', $gsave, $ssave) : null;
    foreach ($draw as [$key, $p, $v, $qty, $price, $full, $max, $name, $ph]) {
            $unit = $t['sale_off'] ? $full : $price;
            $line = $qty * $unit;
            $bo = backorder_told($p) ? backorder_qty($p, $v, $qty) : 0;
            $link = e(shop_url('p/' . $p['slug']));
            $rows .= '<tr class="shop-line">'
                . '<td class="shop-item"><div>' . ($ph ? '<a href="' . $link . '" tabindex="-1" aria-hidden="true"><img src="'
                    . e(media_url($ph['thumb'])) . '" alt="" width="64" height="64"></a>' : '')
                . '<span><a href="' . $link . '">' . e($p['name']) . '</a>'
                . ($v ? '<small>' . e(choice_label($p, $v)) . '</small>' : '')
                . (($sku = $v && $v['sku'] !== '' ? $v['sku'] : $p['sku']) !== '' ? '<small class="shop-sku">' . e($sku) . '</small>' : '')
                . ($bo ? '<small class="shop-backorder">' . backorder_note($bo, $qty) . '</small>' : '')
                . '</span></div></td>'
                . '<td class="shop-num" data-label="Price">' . shown_money($unit) . ($unit < $full ? ' <s class="shop-was">' . shown_money($full) . '</s>' : '') . '</td>'
                . '<td class="shop-num" data-label="Quantity"><input type="number" name="qty[' . e((string)$key) . ']" value="' . $qty
                . '" min="0" max="' . $max . '" step="1" inputmode="numeric" aria-label="Quantity of ' . e($name) . '"></td>'
                . '<td class="shop-num" data-label="Total">' . shown_money($line) . '</td>'
                . '<td class="shop-num"><button class="shop-remove" name="remove" value="' . e((string)$key)
                . '" aria-label="Remove ' . e($name) . '">Remove</button></td></tr>';
    }
    $f = flash();
    $msg = trim(($f ?? '') . ' ' . implode(' ', $notes));
    $body = '<h1 class="post-title">Basket</h1><div class="post-body">'
          . ($msg !== '' ? '<p class="shop-note" role="status">' . e($msg) . '</p>' : '');
    if ($rows) {
        if ($t['discount_note'] !== null && !str_contains($msg, $t['discount_note'])) {
            $body .= '<p class="shop-note" role="status">' . e($t['discount_note']) . '</p>';
        }
        $collectNote = trim($t['method_note']);
        $choice = '';
        if (count($t['methods']) > 1 && $t['method'] !== 'none') {
            // Each way to get it, with what it would cost for this basket.
            $choice = '<fieldset class="shop-delivery"><legend>How would you like it?</legend>';
            foreach ($t['methods'] as $k => $m) {
                $q = $m['quote'];
                $label = $k === 'collect' ? 'Collect (free)'
                       : $m['name'] . (!$q['ok'] ? ' – not available (' . $q['why'] . ')' : ($q['price'] === null ? '' : ($q['price'] === 0 ? ' (free)' : ' (' . money($q['price']) . ')')));
                $choice .= '<label><input type="radio" name="delivery" value="' . e($k) . '"' . ($t['method_key'] === $k ? ' checked' : '') . ($q['ok'] ? '' : ' disabled')
                    . ' onchange="this.form.requestSubmit(this.form.querySelector(\'.shop-default\'))"> ' . e($label) . '</label>';
            }
            $choice .= '</fieldset>';
        }
        if (delivery_zones() && $t['method'] !== 'none' && array_filter($t['methods'], fn($m) => $m['kind'] === 'deliver')) {
            $pc = (string)($_SESSION['shop_postcode'] ?? '');
            $choice .= '<div class="shop-postcode"><label for="shop-pc">Delivery postcode <span class="shop-hint">(some areas cost more)</span></label>'
                . '<input id="shop-pc" name="postcode" value="' . e($pc) . '" maxlength="10" autocomplete="shipping postal-code" autocapitalize="characters" class="shop-short">'
                . '<button class="shop-update" name="update" value="1">Check</button>'
                . ($t['zone'] !== '' ? '<p class="shop-hint">' . e($pc) . ' is in ' . e($t['zone']) . '.</p>' : '') . '</div>';
        }
        $postLabel = $t['method'] === 'none' ? '' : $t['method_name'];
        $postCell = $t['postage'] === null ? ($t['unavailable'] !== '' ? 'Not available' : 'Added at checkout') : ($t['postage'] === 0 ? 'Free' : shown_money($t['postage']));
        $body .= '<form method="post" action="' . e(shop_url('basket/update')) . '">' . csrf_field()
            // Pressing Enter in a quantity box uses the form's first button, so it must be Update.
            . '<button class="shop-default" name="update" value="1" tabindex="-1" aria-hidden="true">Update basket</button>'
            . '<table class="shop-basket"><thead><tr><th scope="col">Product</th><th scope="col" class="shop-num">Price</th>'
            . '<th scope="col" class="shop-num">Quantity</th><th scope="col" class="shop-num">Total</th>'
            . '<th scope="col"><span class="shop-vh">Remove</span></th></tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot>'
            . '<tr class="shop-sub"><th scope="row" colspan="3">Subtotal</th><td class="shop-num shop-subtotal">' . shown_money($t['subtotal']) . ex_vat_tag() . '</td><td></td></tr>'
            . ($t['code'] !== '' ? '<tr class="shop-sub shop-discount"><th scope="row" colspan="3">Discount <span class="shop-code-name">' . e($t['code']) . '</span></th>'
                . '<td class="shop-num">' . ($t['discount'] > 0 ? '−' . shown_money($t['discount']) : 'Free delivery') . '</td>'
                . '<td class="shop-num"><button class="shop-remove" name="discount_remove" value="1" aria-label="Remove discount code ' . e($t['code']) . '">Remove</button></td></tr>' : '')
            . ($t['group_pence'] > 0 ? '<tr class="shop-sub shop-discount"><th scope="row" colspan="3">' . e($t['group']) . ' prices</th>'
                . '<td class="shop-num">−' . shown_money($t['group_pence']) . '</td><td></td></tr>' : '')
            . ($postLabel !== '' ? '<tr class="shop-sub"><th scope="row" colspan="3">' . e($postLabel) . '</th><td class="shop-num shop-postage">' . $postCell . '</td><td></td></tr>' : '')
            . (prices_ex_vat() ? '<tr class="shop-sub"><th scope="row" colspan="3">Total ex VAT</th><td class="shop-num shop-exvat-total">' . money(ex_vat($t['total'])) . '</td><td></td></tr>'
                . '<tr class="shop-sub"><th scope="row" colspan="3">VAT at 20%</th><td class="shop-num shop-vat-line">' . money($t['total'] - ex_vat($t['total'])) . '</td><td></td></tr>' : '')
            . '<tr class="shop-grand"><th scope="row" colspan="3">Total</th><td class="shop-num shop-total">' . money($t['total']) . '</td><td></td></tr>'
            . ($t['vat'] !== null && !prices_ex_vat() ? '<tr class="shop-sub"><td colspan="4" class="shop-num shop-vat">Includes VAT of ' . money($t['vat']) . '</td><td></td></tr>' : '')
            . '</tfoot></table>'
            . '<div class="shop-basket-actions">'
            . ($t['code'] === '' && !empty($_SESSION['shop_discount']) ? '<div class="shop-code"><span>Discount code <span class="shop-code-name">'
                . e((string)$_SESSION['shop_discount']) . '</span> is saved for when it applies.</span>'
                . '<button class="shop-remove" name="discount_remove" value="1">Remove code</button></div>'
            : ($t['code'] === '' && discounts_live() ? '<div class="shop-code"><label for="shop-code-in">Discount code</label>'
                . '<input id="shop-code-in" name="discount_code" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false">'
                . '<button class="shop-update" name="update" value="1">Apply</button></div>' : ''))
            . '<button class="shop-update" name="update" value="1">Update basket</button></div>'
            . $choice
            . ($t['method'] === 'collect' && $collectNote !== '' ? '<div class="shop-note shop-collect"><strong>Collection</strong><br>' . nl2br(e($collectNote)) . '</div>' : '')
            . ($t['to_free'] !== null ? '<p class="shop-free">Spend ' . money($t['to_free']) . ' more for ' . ($t['method_name'] === 'UK delivery' ? 'free UK delivery.' : 'free delivery by ' . e($t['method_name']) . '.') . '</p>' : '')
            . '</form>';
        $why = checkout_blocked($t);
        $body .= $why === null
            ? '<p><a class="shop-button shop-checkout" href="' . e(shop_url('checkout')) . '">Checkout</a></p>'
            : '<button class="shop-button" disabled>Checkout</button><p><em>' . e($why) . '</em></p>';
    } else {
        $body .= '<p>Your basket is empty.</p>';
    }
    $body .= '<p><a href="' . e(shop_url()) . '">Continue shopping</a></p></div>';
    render('Basket', $body, ['route' => 'basket', 'noindex' => true, 'crumbs' => [['Basket', null]]]);
}

function view_sitemap(): never
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
       . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
       . '<url><loc>' . e(abs_shop_url()) . '</loc></url>' . "\n";
    foreach (array_intersect_key(cat_all(), cat_live()) as $c) {
        echo '<url><loc>' . e(abs_shop_url('c/' . $c['slug'])) . '</loc></url>' . "\n";
    }
    foreach (db()->query('SELECT slug, updated FROM products WHERE visible = 1') as $p) {
        echo '<url><loc>' . e(abs_shop_url('p/' . $p['slug'])) . '</loc><lastmod>'
           . gmdate('Y-m-d', strtotime($p['updated'])) . '</lastmod></url>' . "\n";
    }
    echo '</urlset>';
    exit;
}

function view_404(): never
{
    render('Not found', '<h1 class="post-title">Not found</h1><div class="post-body">'
        . '<p>That isn\'t in the shop. <a href="' . e(shop_url()) . '">See everything for sale</a>.</p></div>',
        ['noindex' => true, 'crumbs' => [['Not found', null]]], 404);
}


/* ================================================================ admin */

/* ---------------------------------------------- two-factor (TOTP), from Wren */
/* RFC 6238 time-based one-time passwords: 6 digits, 30-second steps, SHA-1.
   Same code as Wren 1.5+, so both admins behave the same way. */

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) {
            continue;
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secretB32, int $step): string
{
    $key = base32_decode($secretB32);
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hash[19]) & 0xf;
    $part = ((ord($hash[$offset]) & 0x7f) << 24)
          | ((ord($hash[$offset + 1]) & 0xff) << 16)
          | ((ord($hash[$offset + 2]) & 0xff) << 8)
          | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($part % 1000000), 6, '0', STR_PAD_LEFT);
}

/* Returns the matching step, or 0. One step of drift either way; a step that
   was already used is refused (replay protection). */
function totp_verify(string $secretB32, string $entered, int $lastUsed): int
{
    $entered = preg_replace('/\D/', '', $entered);
    if (strlen($entered) !== 6) {
        return 0;
    }
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $step = $now + $i;
        if ($step > $lastUsed && hash_equals(totp_code($secretB32, $step), $entered)) {
            return $step;
        }
    }
    return 0;
}

function recovery_generate(): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $raw = bin2hex(random_bytes(5));
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
    }
    return $codes;
}

function recovery_use(array $user, string $entered): bool
{
    $hashes = json_decode($user['recovery'] ?: '[]', true) ?: [];
    $hash = hash('sha256', strtolower(trim($entered)));
    foreach ($hashes as $i => $h) {
        if (hash_equals($h, $hash)) {
            unset($hashes[$i]);
            db()->prepare('UPDATE users SET recovery = ? WHERE id = ?')
                ->execute([json_encode(array_values($hashes)), $user['id']]);
            return true;
        }
    }
    return false;
}

function totp_uri(string $secret, string $user): string
{
    $issuer = rawurlencode(setting('last_site_title', 'Wren Shop') . ' shop');
    return 'otpauth://totp/' . $issuer . ':' . rawurlencode($user)
         . '?secret=' . $secret . '&issuer=' . $issuer . '&period=30&digits=6';
}

/* ------------------------------------------------------- sign-in plumbing */

/* The visitor's address for throttling. IPv6 is counted per /64, since one
   connection usually gets a whole /64 and could otherwise rotate through it. */
function client_ip(): string
{
    $ip = real_ip();
    if (str_contains($ip, ':') && ($bin = @inet_pton($ip)) !== false && strlen($bin) === 16) {
        return bin2hex(substr($bin, 0, 8)) . '::/64';
    }
    return $ip;
}

/* A hash to check against when there's no such account, so a wrong username
   takes as long as a wrong password and timing gives nothing away. */
function dummy_hash(): string
{
    $h = setting('dummy_hash');   // made once and kept, so checking it costs exactly what a real check costs
    if ($h === '' || password_needs_rehash($h, PASSWORD_DEFAULT)) {
        $h = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        set_setting('dummy_hash', $h);
    }
    return $h;
}

/* Lots of wrong passwords for one account (from anywhere): slow down, but never
   lock the real owner out, which anyone could otherwise do on purpose. */
function account_slowdown(string $key): void
{
    if (throttled($key, 10, LOGIN_WINDOW)) {
        sleep(2);
    }
}

/* Counted per address in the database, so clearing cookies doesn't reset it. */
function login_blocked(): bool
{
    db()->prepare('DELETE FROM login_fails WHERE at < ?')->execute([time() - LOGIN_WINDOW]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_fails WHERE ip = ?');
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= LOGIN_MAX_FAILS;
}

function login_failed(): void
{
    db()->prepare('INSERT INTO login_fails (ip, at) VALUES (?, ?)')->execute([client_ip(), time()]);
    usleep(400000);
}

function user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function remember_cookie(string $value, int $expires): void
{
    setcookie('wrenshop_keep', $value, [
        'expires' => $expires, 'path' => shop_base() . '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => is_https() || str_starts_with(origin(), 'https:'),
    ]);
}

/* Split token: the selector finds the row, the validator is only stored hashed. */
function remember_issue(int $uid): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    db()->prepare('INSERT INTO remember (selector, user_id, hash, expires) VALUES (?, ?, ?, ?)')
        ->execute([$selector, $uid, hash('sha256', $validator), $expires]);
    remember_cookie($selector . '.' . $validator, $expires);
}

function remember_forget(): void
{
    $c = str_in($_COOKIE['wrenshop_keep'] ?? '');
    if (str_contains($c, '.')) {
        db()->prepare('DELETE FROM remember WHERE selector = ?')->execute([explode('.', $c, 2)[0]]);
    }
    remember_cookie('', time() - 3600);
}

function login_complete(array $u, bool $remember): void
{
    session_regenerate_id(true);
    unset($_SESSION['shop_2fa_pending'], $_SESSION['shop_2fa_remember']);
    $_SESSION['shop_uid'] = (int)$u['id'];
    $_SESSION['shop_ugen'] = (int)($u['session_gen'] ?? 0);
    if ($remember) {
        remember_issue((int)$u['id']);
    }
}

/* Signs the admin out everywhere else: every other session and every remember
   cookie stops working. Returns the user as now stored, to sign this session back in. */
function admin_bump_sessions(int $uid): array
{
    db()->prepare('UPDATE users SET session_gen = session_gen + 1 WHERE id = ?')->execute([$uid]);
    db()->prepare('DELETE FROM remember WHERE user_id = ?')->execute([$uid]);
    return user_by_id($uid);
}

function current_admin(): ?array
{
    static $done = false, $user = null;
    if ($done) {
        return $user;
    }
    $done = true;
    if (!empty($_SESSION['shop_uid'])) {
        $u = user_by_id((int)$_SESSION['shop_uid']);
        if ($u && (int)$u['session_gen'] === (int)($_SESSION['shop_ugen'] ?? 0)) {
            return $user = $u;
        }
        unset($_SESSION['shop_uid'], $_SESSION['shop_ugen']);   // signed out from elsewhere
        return null;
    }
    $c = str_in($_COOKIE['wrenshop_keep'] ?? '');
    if (preg_match('/^([a-f0-9]{18})\.([a-f0-9]{64})$/', $c, $m)) {
        db()->prepare('DELETE FROM remember WHERE expires < ?')->execute([time()]);
        $st = db()->prepare('SELECT * FROM remember WHERE selector = ?');
        $st->execute([$m[1]]);
        $row = $st->fetch();
        if ($row && hash_equals($row['hash'], hash('sha256', $m[2])) && ($u = user_by_id((int)$row['user_id']))) {
            db()->prepare('DELETE FROM remember WHERE selector = ?')->execute([$m[1]]);
            login_complete($u, true);   // rotates the token
            $user = $u;
            return $user;
        }
        remember_cookie('', time() - 3600);
    }
    return null;
}

/* Admin pages can't be shown inside another site's frame (no click-tricking). */
function admin_headers(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
}

function require_admin(): array
{
    admin_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $u = current_admin();
    if (!$u) {
        $n = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        redirect(admin_url($n ? 'login' : 'setup'));
    }
    // The site's address comes from the admin's own signed-in visit, never a visitor's.
    $here = request_origin();
    $saved = setting('site_url');
    if ($saved === '' || ($saved === preg_replace('#^https:#', 'http:', $here) && str_starts_with($here, 'https:'))) {
        set_setting('site_url', $here);
        @unlink(SHELL_FILE);   // fetch the look again, from the right address
    }
    return $u;
}

/* -------------------------------------------------------------- admin shell */

/* The admin's main sections (the side menu) and each one's pages (the tabs along the top).
   Tab keys are admin addresses: admin_url($key). */
function admin_sections(): array
{
    return [
        'orders'    => ['Orders', '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
                        ['orders' => 'All orders', 'reports' => 'Reports', 'export' => 'Export']],
        'products'  => ['Products', '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
                        ['' => 'All products', 'new' => 'Add a product', 'categories' => 'Categories', 'import' => 'Import and export']],
        'customers' => ['Customers', '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
                        ['customers' => 'Customers', 'groups' => 'Groups']
                        + (trade_group() || trade_new_count() || admin_where()[1] === 'trade' ? ['trade' => 'Trade applications' . (($n = trade_new_count()) ? ' (' . $n . ')' : '')] : [])
                        + ['licences' => 'Downloads and licences', 'privacy' => 'Find a person\'s data', 'moving' => 'Import']],
        'discounts' => ['Discounts', '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
                        ['discounts' => 'Discount codes', 'discount/new' => 'Add a code']],
        'settings'  => ['Settings', '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
                        ['ready' => 'Getting ready'] + array_combine(
                            array_map(fn($t) => $t === 'general' ? 'settings' : 'settings/' . $t, array_keys(SETTINGS_TABS)),
                            array_values(SETTINGS_TABS))],
    ];
}

/* Where this admin page sits: [section, tab key]. Detail pages (one order, one product)
   sit under the list they came from. */
function admin_where(): array
{
    $seg = $GLOBALS['shop_seg'] ?? [];
    $sub = $seg[1] ?? '';
    return match ($sub) {
        '', 'edit'           => ['products', ''],
        'new'                => ['products', 'new'],
        'categories'         => ['products', 'categories'],
        'import'             => ['products', 'import'],
        'orders', 'order'    => ['orders', 'orders'],
        'export'             => ['orders', 'export'],
        'reports'            => ['orders', 'reports'],
        'customers', 'customer' => ['customers', 'customers'],
        'groups', 'group'    => ['customers', 'groups'],
        'trade'              => ['customers', 'trade'],
        'moving'             => ['customers', 'moving'],
        'licences', 'licence' => ['customers', 'licences'],
        'privacy'            => ['customers', 'privacy'],
        'discounts'          => ['discounts', 'discounts'],
        'discount'           => ['discounts', ($seg[2] ?? '') === 'new' ? 'discount/new' : 'discounts'],
        'ready'              => ['settings', 'ready'],
        'settings'           => ['settings', isset(SETTINGS_TABS[$seg[2] ?? '']) && $seg[2] !== 'general' ? 'settings/' . $seg[2] : 'settings'],
        default              => ['', ''],
    };
}

function admin_icon(string $paths): string
{
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

function admin_menu_html(): array
{
    [$here, $tab] = admin_where();
    $side = '';
    $tabs = '';
    foreach (admin_sections() as $key => [$label, $icon, $pages]) {
        $first = array_key_first($pages);
        $on = $key === $here;
        $badge = $key === 'orders' && ($n = orders_todo_count()) ? ' <span class="badge" title="' . $n . ' to do">' . $n . '</span>' : '';
        $side .= '<li><a href="' . e(admin_url((string)$first)) . '"' . ($on ? ' aria-current="page"' : '') . '>' . admin_icon($icon)
               . '<span>' . e($label) . '</span>' . $badge . '</a></li>';
        if ($on && count($pages) > 1) {
            foreach ($pages as $k => $t) {
                $tabs .= '<li><a href="' . e(admin_url((string)$k)) . '"' . ((string)$k === $tab ? ' aria-current="page"' : '') . '>' . e($t) . '</a></li>';
            }
        }
    }
    $util = '<li><a href="' . e(shop_url()) . '">' . admin_icon('<path d="M4 9 5 4h14l1 5M4 9v11h16V9M4 9h16M10 20v-5h4v5"/>') . '<span>View shop</span></a></li>
      <li><a href="' . e(SHOP_HELP_URL) . '" target="_blank" rel="noopener" title="Help: the owner\'s guide">' . admin_icon('<circle cx="12" cy="12" r="9"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.4c-.6.3-1 .8-1 1.5v.4M12 17h.01"/>') . '<span>Help</span></a></li>
      <li><form method="post" action="' . e(admin_url('logout')) . '" class="navform">' . csrf_field() . '<button>' . admin_icon('<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>') . '<span>Sign out</span></button></form></li>';
    return [
        '<aside class="side"><p class="brand"><a href="' . e(admin_url('orders')) . '">Wren Shop</a></p>
      <nav class="mainnav" aria-label="Admin"><ul>' . $side . '</ul></nav>
      <nav class="util" aria-label="Shop and account"><ul>' . $util . '</ul></nav></aside>',
        $tabs !== '' ? '<nav class="tabs" aria-label="' . e(admin_sections()[$here][0]) . '"><ul>' . $tabs . '</ul></nav>' : '',
    ];
}

function admin_shell(string $title, string $body, bool $nav = true): never
{
    admin_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    [$side, $tabs] = $nav ? admin_menu_html() : ['', ''];
    $f = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>' . e($title) . ' · Wren Shop</title>
<style>
:root { --paper:#FBFAF6; --ink:#262219; --quiet:#7C7669; --moss:#55613B; --moss-d:#414B2C;
        --straw:#EDE9DD; --line:#E2DDCF; --red:#8C3B2E; }
* { box-sizing:border-box; }
[hidden] { display:none !important; }
body { margin:0; background:var(--paper); color:var(--ink);
       font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size:16px; line-height:1.55; }
.shell { max-width: 46rem; margin: 0 auto; padding: 1.25rem 1rem 4rem; }
.brand { font-family: Georgia, serif; font-size: 1.3rem; margin: 0 0 .8rem; }
.brand a { color:inherit; text-decoration:none; }
.side .brand a { display:inline; width:auto; padding:0; font:inherit; background:none; }
.brand::after { content:"."; color: var(--moss); }
/* Signed in: the side menu on the left, the page using the rest of the width. */
.admin { display:grid; grid-template-columns: 13rem minmax(0, 1fr); min-height:100vh; }
.side { background:var(--straw); border-right:1px solid var(--line); padding:1.2rem .75rem; display:flex; flex-direction:column;
        position:sticky; top:0; height:100vh; overflow-y:auto; }
.side .brand { padding:0 .6rem; margin-bottom:1.3rem; }
.side ul { list-style:none; margin:0; padding:0; }
.side li { margin:0 0 .15rem; }
.side a, .side button { display:flex; align-items:center; gap:.65rem; width:100%; padding:.5rem .6rem; border-radius:7px; margin:0;
        color:var(--ink); text-decoration:none; font:inherit; font-size:.95rem; font-weight:500; background:none; border:0; cursor:pointer; text-align:left; }
.side svg { flex:none; color:var(--quiet); }
.side a:hover, .side button:hover { background:#E3DED0; color:var(--ink); }
.side a[aria-current] { background:var(--moss); color:#fff; }
.side a[aria-current] svg { color:#fff; }
.side .badge { margin-left:auto; }
.side a[aria-current] .badge { background:#fff; color:var(--moss-d); }
.side .util { margin-top:auto; padding-top:1rem; border-top:1px solid var(--line); }
.side .util a, .side .util button { font-size:.88rem; color:var(--quiet); padding:.4rem .6rem; }
.navform { margin:0; }
.page { padding:1.4rem clamp(1rem, 3vw, 2.5rem) 4rem; min-width:0; }
.tabs { border-bottom:1px solid var(--line); margin:0 0 1.6rem; overflow-x:auto; scrollbar-width:thin; }
.tabs ul { display:flex; gap:.2rem; list-style:none; margin:0; padding:0; }
.tabs a { display:block; padding:.55rem .85rem .6rem; white-space:nowrap; color:var(--quiet); text-decoration:none; font-size:.93rem;
          border-bottom:3px solid transparent; margin-bottom:-1px; }
.tabs a:hover { color:var(--ink); border-bottom-color:var(--line); }
.tabs a[aria-current] { color:var(--ink); font-weight:600; border-bottom-color:var(--moss); }
/* Forms and writing stay a comfortable width; lists and tables use it all. */
.page form:not(.search):not(.navform), .page .lede, .page > p, .page > .flash, .page > .err, .page details, .narrow { max-width:46rem; }
.page table form, .page .plist form { max-width:none; }
@media (max-width: 52rem) {
  .admin { display:block; }
  .side { position:static; height:auto; flex-direction:row; flex-wrap:wrap; align-items:center; padding:.6rem .75rem 0; border-right:0; border-bottom:1px solid var(--line); }
  .side .brand { margin:0; padding:0 .3rem; flex:1; font-size:1.15rem; }
  .side .util { order:2; margin:0; padding:0; border:0; }
  .side .util ul { display:flex; }
  .side .util span { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }
  .side .util a, .side .util button { padding:.5rem; }
  .side .mainnav { order:3; flex-basis:100%; margin-top:.4rem; }
  .side .mainnav ul { display:grid; grid-template-columns:repeat(5, 1fr); }
  .side .mainnav li { margin:0; }
  .side .mainnav a { flex-direction:column; gap:.15rem; font-size:.72rem; padding:.45rem .1rem .5rem; border-radius:7px 7px 0 0; position:relative; text-align:center; }
  .side .mainnav .badge { position:absolute; top:.15rem; right:calc(50% - 1.6rem); margin:0; }
  .tabs { margin-left:-1rem; margin-right:-1rem; padding:0 .5rem;
          -webkit-mask-image:linear-gradient(90deg, transparent 0, #000 1.2rem, #000 calc(100% - 1.6rem), transparent);
                  mask-image:linear-gradient(90deg, transparent 0, #000 1.2rem, #000 calc(100% - 1.6rem), transparent); }
}
h1 { font-family: Georgia, serif; font-weight:400; font-size:1.6rem; margin:0 0 .4rem; }
h2 { font-family: Georgia, serif; font-weight:400; font-size:1.2rem; margin:2rem 0 .4rem; }
.lede { color: var(--quiet); margin-top:0; }
a { color: var(--moss-d); }
form label { display:block; margin:1rem 0 0; font-weight:600; font-size:.9rem; }
form .hint { font-weight:400; color:var(--quiet); }
input[type=text], input[type=password], input[type=number], input[type=email], input[type=tel], input[type=url], input[type=search], input[type=date], input:not([type]), textarea, select {
  width:100%; margin-top:.25rem; padding:.65rem .7rem; font:inherit; font-size:16px;
  border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--ink); }
textarea { min-height: 9rem; }
input:focus, textarea:focus, select:focus { outline:2px solid var(--moss); outline-offset:1px; border-color:var(--moss); }
button, .btn { display:inline-block; margin-top:1.2rem; background:var(--moss); color:#fff; border:0; font:inherit; font-weight:600;
         padding:.7rem 1.4rem; border-radius:6px; cursor:pointer; text-decoration:none; }
button:hover, .btn:hover { background:var(--moss-d); }
button.quiet, .btn.quiet { background:transparent; color:var(--moss-d); border:1px solid var(--moss); padding:.45rem .9rem; margin-top:.9rem; }
button.quiet:hover { background:var(--moss); color:#fff; }
button.danger { background:transparent; color:var(--red); border:1px solid var(--red); padding:.45rem .9rem; margin-top:.9rem; }
button.danger:hover { background:var(--red); color:#fff; }
button.small { padding:.3rem .65rem; font-size:.85rem; margin-top:0; }
.err .quiet.small { margin-top:.2rem; }
.ready li { flex-wrap:nowrap; align-items:flex-start; }
.ready .tick { flex:none; width:1.5rem; height:1.5rem; border-radius:50%; border:2px solid var(--line); display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:.9rem; margin-top:.1rem; }
.ready .tick.ok { background:var(--moss); border-color:var(--moss); color:#fff; }
.ready .tick.todo { border-color:var(--red); }
.ready .tick.unk { border-color:#b07a1f; color:#8a5d10; }
.ready li.done strong { font-weight:600; color:var(--quiet); }
/* Buttons sitting beside things (in a row, a list or a table) need no space above. */
.plist button, .actions-row button, .topline .btn, td button, .search button { margin-top:0; }
.search { display:flex; gap:.5rem; align-items:stretch; margin:0 0 1rem; }
.search input, .search select { margin-top:0; flex:1; min-width:0; }
.search { flex-wrap:wrap; } .search input[type=search] { flex:2 1 12rem; } .search select { flex:1 1 9rem; }
.pager ul { display:flex; flex-wrap:wrap; gap:.3rem; list-style:none; padding:0; margin:1.2rem 0; }
.pager a, .pager span { display:inline-block; min-width:2.2rem; padding:.35rem .55rem; text-align:center; border:1px solid var(--line);
  border-radius:6px; text-decoration:none; background:#fff; }
.pager span[aria-current] { background:var(--moss); border-color:var(--moss); color:#fff; font-weight:700; }
.pager .gap { border:0; background:none; padding:.35rem .1rem; }
.price { display:flex; align-items:center; gap:.4rem; }
.price span { font-size:1.2rem; padding-top:.25rem; }
.flash { background:var(--straw); border-left:3px solid var(--moss); padding:.6rem .9rem; }
.err { background:#F6E7E4; border-left:3px solid var(--red); padding:.6rem .9rem; }
.err ul { margin:.2rem 0 0; padding-left:1.2rem; }
.muted { color:var(--quiet); font-size:.85rem; }
.topline { display:flex; align-items:baseline; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
.check { display:flex; gap:.6rem; align-items:center; font-weight:600; margin-top:1rem; }
.check input { width:1.2rem; height:1.2rem; margin:0; }
.plist { list-style:none; padding:0; margin:1rem 0; }
.plist li { display:flex; gap:.8rem; align-items:center; flex-wrap:wrap; padding:.75rem 0; border-bottom:1px solid var(--line); }
.plist img, .plist .noimg { width:56px; height:56px; object-fit:cover; border-radius:6px; background:var(--straw); flex:none; }
.plist .pname { flex:1 1 12rem; min-width:0; }
.plist .pname a { font-weight:600; text-decoration:none; }
.plist .pname small { display:block; color:var(--quiet); }
.sku { font-family:ui-monospace,Menlo,monospace; font-size:.92em; }
.plist form { display:flex; gap:.35rem; align-items:center; margin:0; }
.plist input[type=number] { width:4.6rem; margin:0; padding:.35rem .45rem; }
.pill { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:.05rem .45rem;
        border-radius:4px; border:1px solid var(--line); color:var(--quiet); margin-left:.3rem; }
.pill.out { border-color:var(--red); color:var(--red); }
.photos { display:grid; grid-template-columns:repeat(auto-fill, minmax(120px,1fr)); gap:.8rem; margin-top:.5rem; }
.photo { border:1px solid var(--line); border-radius:8px; background:#fff; overflow:hidden; }
.photo img { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; }
.photo .acts { display:flex; flex-wrap:wrap; gap:.25rem; padding:.4rem; }
.photo .acts button { margin:0; padding:.2rem .45rem; font-size:.78rem; font-weight:600; background:transparent;
                      color:var(--moss-d); border:1px solid var(--line); }
.photo .acts button.rm { color:var(--red); }
.photo .main { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--moss-d); padding:.35rem .45rem 0; }
.drop { margin-top:.5rem; padding:1rem; border:2px dashed var(--line); border-radius:8px; background:#fff; }
.drop input { width:100%; }
#previews { display:flex; flex-wrap:wrap; gap:.4rem; margin-top:.6rem; }
#previews img { width:64px; height:64px; object-fit:cover; border-radius:5px; }
details { margin-top:1.2rem; } summary { cursor:pointer; color:var(--moss-d); font-weight:600; }
.actions-row { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; margin-top:2rem; padding-top:1rem; border-top:1px solid var(--line); }
.actions-row form { margin:0; }
.optrows { margin-top:.6rem; }
.optrow { display:grid; grid-template-columns:1.6fr 1.4fr 1fr 1fr 1fr 1.3fr 2.2rem; gap:.4rem; margin-top:.4rem; align-items:center; }
.optrows.one .optrow { grid-template-columns:2fr 1fr 1fr 1fr 1.3fr 2.2rem; } .optrows.one .v2 { display:none; }
.optrows.withw .optrow { grid-template-columns:1.6fr 1.4fr 1fr 1fr 1fr .9fr 1.3fr 2.2rem; } .optrows.withw.one .optrow { grid-template-columns:2fr 1fr 1fr 1fr .9fr 1.3fr 2.2rem; }
.bandhead, .bandrow { display:grid; grid-template-columns:1fr 1fr; gap:.4rem; max-width:22rem; margin-top:.3rem; } .bandhead { font-size:.85rem; color:#6b7280; }
.zonerow { display:grid; grid-template-columns:1.5fr auto 8rem; gap:.6rem; align-items:center; margin:.3rem 0; max-width:34rem; } .gptable { overflow-x:auto; } .gptable td, .gptable th { padding:.3rem .5rem .3rem 0; text-align:left; white-space:nowrap; } .gptable th { font-weight:600; font-size:.9rem; }
.progress { height:.8rem; background:rgba(127,127,127,.18); border-radius:99px; overflow:hidden; margin:1rem 0 .5rem; max-width:40rem; } .progress span { display:block; height:100%; background:var(--moss); transition:width .3s; }
.chart { width:100%; max-width:46rem; height:auto; margin:1rem 0; } .chart rect { fill:var(--moss); } .chart g:hover rect { opacity:.8; } .chart text { font-size:11px; fill:var(--quiet); }
.rrange { display:flex; flex-wrap:wrap; gap:.6rem 1rem; align-items:end; } .rrange label { margin:0; } .rrange .muted a { margin-right:.2rem; }
.rsum { display:flex; flex-wrap:wrap; gap:1rem 2.5rem; margin:1.4rem 0 .5rem; } .rsum div { color:var(--quiet); font-size:.9rem; } .rsum span { display:block; color:var(--ink); font-size:1.5rem; font-family:Georgia,serif; }
.rwrap { overflow-x:auto; } .rtable { width:100%; border-collapse:collapse; } .rtable th, .rtable td { padding:.45rem .5rem; border-bottom:1px solid var(--line); text-align:left; white-space:nowrap; } .rtable .num { text-align:right; } .rtot td { font-weight:600; }
.rcols { display:grid; grid-template-columns:repeat(auto-fit,minmax(18rem,1fr)); gap:0 2rem; } .rhead { display:flex; align-items:baseline; gap:1rem; flex-wrap:wrap; } .rhead .rcsv { font-size:.85rem; }
.zonerow .check { margin:0; } .zonerow .price span { white-space:nowrap; }
.row-actions { display:flex; gap:1rem; margin:.4rem 0 1rem; } .row-actions form { margin:0; } button.link { background:none; border:0; padding:0; color:inherit; text-decoration:underline; cursor:pointer; font:inherit; } button.link.danger { color:#b42318; }
.optrow .on { margin:0; display:flex; justify-content:center; } .optrow .on input { width:1.2rem; height:1.2rem; margin:0; }
.optnames { display:flex; gap:.6rem; flex-wrap:wrap; align-items:flex-end; } .optnames > * { flex:1 1 12rem; } .optnames button { flex:0 0 auto; }
@media (max-width: 40rem) { .optrows { overflow-x:auto; } .optrow { min-width:40rem; } }
.saledates { display:flex; gap:.6rem; flex-wrap:wrap; } .saledates label { flex:1 1 10rem; }
table.cats th { text-align:left; font-size:.78rem; font-weight:600; color:var(--quiet); text-transform:uppercase; letter-spacing:.04em; padding:.3rem; border-bottom:1px solid var(--line); }
table.cats td.mv form { gap:.25rem; } table.cats .nomove { display:inline-block; width:2.05rem; }
table.cats td.cname input { min-width:12rem; }
table.cats tr.d2 td.cname { padding-left:2rem; } table.cats tr.d3 td.cname { padding-left:4rem; }
table.cats tr.d1 td { border-top:2px solid var(--line); }
table.cats select { margin:0; padding:.4rem .5rem; min-width:10rem; }
.addcat { display:flex; flex-wrap:wrap; gap:.4rem 1rem; align-items:flex-end; } .addcat label { flex:1 1 14rem; margin:0; } .addcat button { margin:0; }
table.cols td, table.errs td, table.changes td { vertical-align:top; } table.cols th, table.changes th { text-align:left; font-size:.85rem; }
table.cols td:first-child { white-space:nowrap; width:1%; }
table.errs { margin-top:.6rem; } table.errs td:first-child { white-space:nowrap; font-weight:600; width:1%; }
table.changes td.was { color:var(--quiet); text-decoration:line-through; text-decoration-color:rgba(0,0,0,.25); }
.report .flash, .report .err { max-width:none; }
.vh { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }
.page .table-wrap { overflow-x:auto; }
/* The product form: details on the left, a side panel (save, show, category, stock) that stays in view. */
.page form#pform { max-width:74rem; } .pgrid details { max-width:none; }
.pgrid { display:grid; grid-template-columns:minmax(0, 1fr) 19rem; gap:0 2.5rem; align-items:start; }
.pside { position:sticky; top:1rem; padding-top:.2rem; }
.pside .pbox { background:var(--straw); border:1px solid var(--line); border-radius:8px; padding:.2rem 1rem 1rem; }
.pside .pbox button { width:100%; }
.pside select, .pside input { font-size:15px; }
@media (max-width: 70rem) { .pgrid { grid-template-columns:minmax(0, 1fr); } .pside { position:static; margin-top:1.5rem; }
  .pside { display:flex; flex-direction:column; } .pside .pbox { order:9; margin-top:1.5rem; } }
.catpicks { max-height:18rem; overflow-y:auto; border:1px solid var(--line); border-radius:6px; padding:.2rem .7rem .6rem; background:var(--paper); }
.catpicks .check { margin-top:.45rem; font-weight:500; }
.optrow input { margin:0 !important; }
.opthead span { font-size:.8rem; font-weight:600; }
details.opts { border:1px solid var(--line); border-radius:8px; padding:.6rem .9rem .8rem; background:#fff; }
.badge { background:var(--moss); color:#fff; border-radius:9px; padding:0 .45rem; font-size:.7rem; font-weight:700; }
.pill.ok { border-color:var(--moss); color:var(--moss-d); }
.pill.wait { border-color:#b07a1f; color:#8a5d10; }
details.more { margin-top:.2rem; } details.more form { margin-top:.5rem; }
.actions-row form { display:flex; flex-wrap:wrap; gap:.4rem; align-items:flex-end; }
.actions-row form label { margin:0; }
.codes { display:flex; flex-wrap:wrap; gap:.5rem; margin:.9rem 0; }
.codes span { font-family:ui-monospace,Menlo,monospace; font-size:.95rem; letter-spacing:.05em;
              background:var(--straw); border:1px solid var(--line); border-radius:5px; padding:.35rem .7rem; }
table { width:100%; border-collapse:collapse; } td { padding:.5rem .3rem; border-bottom:1px solid var(--line); vertical-align:middle; }
td form { display:flex; gap:.4rem; margin:0; } td input { margin:0 !important; }
</style></head><body>' . ($nav ? '<div class="admin">' . $side . '<main class="page">' . $tabs : '<div class="shell"><p class="brand">Wren Shop</p>')
    . ($nav && data_exposed() === true ? data_warning_html() : '') . ($nav && shop_demo() ? '<p class="muted demo-on">Demo mode is on: visitors can\'t check out. <a href="' . e(settings_url('general', 'demo')) . '">Change in Settings</a></p>' : '')
    . ($f ? '<p class="flash">' . e($f) . '</p>' : '') . $body . ($nav ? '</main>' : '') . '</div>'
    . ($tabs !== '' ? '<script>(function(){var t=document.querySelector(".tabs [aria-current]");if(t){var n=t.closest(".tabs");n.scrollLeft=t.offsetLeft-(n.clientWidth-t.offsetWidth)/2;}})();</script>' : '')
    . '</body></html>';
    exit;
}

function errors_html(array $errors): string
{
    if (!$errors) {
        return '';
    }
    return '<div class="err"><b>Please check:</b><ul>'
         . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>';
}

/* ------------------------------------------------------- setup and sign-in */

function setup_code(): string
{
    @unlink(OLD_SETUP_FILE);   // 0.12.0 and earlier kept it in a .txt file
    if (!is_file(SETUP_FILE)) {
        $raw = strtoupper(substr(base32_encode(random_bytes(10)), 0, 8));
        file_put_contents(SETUP_FILE, "<?php http_response_code(404); exit; ?>\n"
            . "Wren Shop setup code: " . substr($raw, 0, 4) . '-' . substr($raw, 4) . "\n\n"
            . "Enter this code at " . admin_url('setup') . " to create the shop's admin account.\n"
            . "This file is deleted once the account exists.\n");
        @chmod(SETUP_FILE, 0600);
    }
    return preg_match('/setup code: ([A-Z2-7]{4}-[A-Z2-7]{4})/', (string)file_get_contents(SETUP_FILE), $m) ? $m[1] : '';
}

/* The shop is often added to a site that is already live, so the first
   person to visit /shop/admin must not be able to claim it. Creating the
   admin account needs a code that only someone with file access can read. */
function view_setup(): never
{
    if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        redirect(admin_url('login'));
    }
    $code = setup_code();
    $errors = [];
    if (is_post()) {
        csrf_check();
        if (login_blocked()) {
            $errors[] = 'Too many wrong attempts. Wait 15 minutes and try again.';
        } elseif (!hash_equals(str_replace('-', '', $code), strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code'))))) {
            login_failed();
            $errors[] = 'That setup code isn\'t right. It\'s in shop/data/setup-code.php on the server.';
        }
        $user = post('username');
        $pass = str_in($_POST['password'] ?? '');
        if ($user === '' || !preg_match('/^[\w.@-]{3,60}$/', $user)) {
            $errors[] = 'Choose a username of 3 or more letters or numbers.';
        }
        if (strlen($pass) < 10) {
            $errors[] = 'The password needs at least 10 characters.';
        } elseif ($pass !== str_in($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords don\'t match.';
        }
        if (!$errors) {
            db()->prepare('INSERT INTO users (username, password, created) VALUES (?, ?, ?)')
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT), now()]);
            $uid = (int)db()->lastInsertId();
            @unlink(SETUP_FILE);
            set_setting('site_url', request_origin());   // whoever has the setup code is the owner
            @unlink(SHELL_FILE);
            login_complete(user_by_id($uid), true);
            flash('Your shop admin is ready. Add your first product, or turn on two-factor in Settings.');
            redirect(admin_url());
        }
    }
    admin_shell('Set up', '<h1>Set up your shop admin</h1>
      <p class="lede">You need the setup code from the file <b>shop/data/setup-code.php</b> on your
      server. Open it with your hosting file manager or FTP (viewing it in a browser shows nothing, on purpose).
      This stops anyone else claiming the shop.</p>'
      . errors_html($errors) . '
      <form method="post">' . csrf_field() . '
        <label>Setup code <input name="code" autocomplete="off" autocapitalize="characters" required></label>
        <label>Username <input name="username" autocomplete="username" autocapitalize="none" value="' . e(post('username')) . '" required></label>
        <label>Password <span class="hint">(10+ characters)</span><input type="password" name="password" autocomplete="new-password" required></label>
        <label>Password again <input type="password" name="password2" autocomplete="new-password" required></label>
        <button>Create admin account</button>
      </form>', false);
}

function view_login(): never
{
    if (current_admin()) {
        redirect(admin_url());
    }
    if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        redirect(admin_url('setup'));
    }
    $err = '';
    if (is_post()) {
        csrf_check();
        $acct = 'alogin:' . mb_strtolower(mb_substr(post('username'), 0, 100));
        account_slowdown($acct);
        if (login_blocked()) {
            $err = 'Too many wrong attempts. Wait 15 minutes and try again.';
        } else {
            $st = db()->prepare('SELECT * FROM users WHERE username = ?');
            $st->execute([post('username')]);
            $u = $st->fetch();
            $pass = str_in($_POST['password'] ?? '');
            $ok = password_verify($pass, $u ? $u['password'] : dummy_hash());
            if ($u && $ok) {
                if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                }
                $remember = !empty($_POST['remember']);
                if ($u['totp_secret'] !== '') {
                    session_regenerate_id(true);
                    $_SESSION['shop_2fa_pending'] = (int)$u['id'];
                    $_SESSION['shop_2fa_gen'] = (int)$u['session_gen'];
                    $_SESSION['shop_2fa_remember'] = $remember;
                    redirect(admin_url('verify'));
                }
                login_complete($u, $remember);
                redirect(admin_url());
            }
            throttle_hit($acct);
            login_failed();
            $err = 'That username and password don\'t match.';
        }
    }
    admin_shell('Sign in', '<h1>Sign in</h1>'
      . ($err ? '<p class="err">' . e($err) . '</p>' : '') . '
      <form method="post">' . csrf_field() . '
        <label>Username <input name="username" autocomplete="username" autocapitalize="none" required></label>
        <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
        <label class="check"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device for ' . REMEMBER_DAYS . ' days</label>
        <button>Sign in</button>
      </form>', false);
}

function view_verify(): never
{
    $u = user_by_id((int)($_SESSION['shop_2fa_pending'] ?? 0));
    // Half-way through signing in when the password changed, or two-factor was switched off? Start again.
    if (!$u || $u['totp_secret'] === '' || (int)$u['session_gen'] !== (int)($_SESSION['shop_2fa_gen'] ?? -1)) {
        unset($_SESSION['shop_2fa_pending'], $_SESSION['shop_2fa_gen']);
        redirect(admin_url('login'));
    }
    $err = '';
    if (is_post()) {
        csrf_check();
        if (login_blocked()) {
            $err = 'Too many wrong attempts. Wait 15 minutes and try again.';
        } else {
            $entered = post('code');
            $step = totp_verify($u['totp_secret'], $entered, (int)$u['totp_last']);
            if ($step) {
                db()->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$step, $u['id']]);
            }
            if ($step || recovery_use($u, $entered)) {
                login_complete($u, !empty($_SESSION['shop_2fa_remember']));
                redirect(admin_url());
            }
            login_failed();
            $err = 'That code wasn\'t right. Codes change every 30 seconds, so try the current one.';
        }
    }
    admin_shell('Two-factor code', '<h1>Enter your code</h1>
      <p class="lede">Open your authenticator app and type the six-digit code for this shop.</p>'
      . ($err ? '<p class="err">' . e($err) . '</p>' : '') . '
      <form method="post">' . csrf_field() . '
        <label>Six-digit code <span class="hint">(or a recovery code)</span>
          <input name="code" autocomplete="one-time-code" inputmode="numeric" autofocus required></label>
        <button>Verify</button>
      </form>
      <form method="post" action="' . e(admin_url('logout')) . '" style="margin-top:1.5rem">' . csrf_field() . '<button class="quiet small">Cancel</button></form>', false);
}

function admin_logout(): never
{
    if (!is_post()) {   // signing out takes a button press, so another site can't sign you out
        admin_shell('Sign out', '<h1>Sign out?</h1><form method="post">' . csrf_field() . '<button>Sign out</button></form>'
            . '<p class="muted" style="margin-top:1.5rem"><a href="' . e(admin_url()) . '">Back to the shop admin</a></p>', false);
    }
    csrf_check();
    remember_forget();
    unset($_SESSION['shop_uid'], $_SESSION['shop_ugen'], $_SESSION['shop_2fa_pending']);
    session_regenerate_id(true);
    redirect(admin_url('login'));
}

/* ------------------------------------------------------------------ photos */

function media_htaccess(): void
{
    if (!is_dir(MEDIA_DIR . '/p')) {
        mkdir(MEDIA_DIR . '/p', 0755, true);
    }
    if (!is_file(MEDIA_DIR . '/.htaccess')) {
        file_put_contents(MEDIA_DIR . '/.htaccess', "# Photos only: nothing in here may run as a script.\n"
            . "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(?i:php\\d?|phtml|phar|pl|py|cgi|sh|shtml|html?|svgz)$\">\n"
            . "  Require all denied\n</FilesMatch>\n");
    }
}

function upload_limit_text(): string
{
    $f = ini_get('upload_max_filesize') ?: '?';
    $p = ini_get('post_max_size') ?: '?';
    return 'This server accepts up to ' . $f . ' per photo and ' . $p . ' per save.';
}

/* Uploaded files as a flat list of ['name','tmp','error','size']. */
function uploaded_photos(): array
{
    $f = $_FILES['photos'] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) {
        return [];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => (string)$name, 'tmp' => (string)$f['tmp_name'][$i],
                  'error' => (int)$f['error'][$i], 'size' => (int)$f['size'][$i]];
    }
    return $out;
}

/* Re-encodes every photo: whatever was uploaded, only a fresh JPEG made by GD
   is ever stored. Returns [file, thumb] relative to media/, or null + $err. */
function photo_store(array $up, ?string &$err, bool $local = false): ?array
{
    $name = $up['name'];
    if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE) {
        $err = "$name is too big for this server. " . upload_limit_text();
        return null;
    }
    // $local: a file the shop itself took from a zip or downloaded, not a browser upload.
    if ($up['error'] !== UPLOAD_ERR_OK || !$local && !is_uploaded_file($up['tmp']) && PHP_SAPI !== 'cli') {
        $err = "$name didn't upload properly. Please try it again.";
        return null;
    }
    if ($up['size'] > UPLOAD_MAX) {
        $err = "$name is over 15 MB. Please choose a smaller photo.";
        return null;
    }
    $info = @getimagesize($up['tmp']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        $err = "$name isn't a photo the shop can use. Please use JPEG, PNG or WebP.";
        return null;
    }
    if ($info[0] * $info[1] > 50_000_000) {
        $err = "$name is too large to process. Please use a photo under 50 megapixels.";
        return null;
    }
    @ini_set('memory_limit', '512M');
    $src = @imagecreatefromstring((string)file_get_contents($up['tmp']));
    if (!$src) {
        $err = "$name couldn't be opened. Please try a different photo.";
        return null;
    }
    // Phones store "which way up" separately; apply it so photos aren't sideways.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($up['tmp'])['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => 270, 8 => 90][$o] ?? 0;
        if ($rot) {
            $src = imagerotate($src, $rot, 0);
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);

    $s = min(1, PHOTO_MAX / max($w, $h));
    $mw = max(1, (int)round($w * $s));
    $mh = max(1, (int)round($h * $s));
    $main = imagecreatetruecolor($mw, $mh);
    imagefill($main, 0, 0, imagecolorallocate($main, 255, 255, 255));
    imagecopyresampled($main, $src, 0, 0, 0, 0, $mw, $mh, $w, $h);

    $side = min($w, $h);   // square crop from the centre for cards
    $thumb = imagecreatetruecolor(THUMB_SIZE, THUMB_SIZE);
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled($thumb, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2),
                       THUMB_SIZE, THUMB_SIZE, $side, $side);

    media_htaccess();
    $base = 'p/' . bin2hex(random_bytes(8));
    imageinterlace($main, true);
    imagejpeg($main, MEDIA_DIR . '/' . $base . '.jpg', 82);
    imagejpeg($thumb, MEDIA_DIR . '/' . $base . '-t.jpg', 80);
    imagedestroy($src);
    imagedestroy($main);
    imagedestroy($thumb);
    return [$base . '.jpg', $base . '-t.jpg'];
}

/* Only files the shop made itself are ever deleted (never the demo pictures). */
function photo_delete_files(array $ph): void
{
    foreach ([$ph['file'], $ph['thumb']] as $f) {
        if (preg_match('#^p/[a-f0-9]{16}(-t)?\.jpg$#', $f)) {
            @unlink(MEDIA_DIR . '/' . $f);
        }
    }
}

function photos_renumber(int $pid): void
{
    foreach (product_photos($pid) as $i => $ph) {
        db()->prepare('UPDATE photos SET position = ? WHERE id = ?')->execute([$i, $ph['id']]);
    }
}

/* ---------------------------------------------------------------- products */

function product_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* Counted stock at the low-stock level or below (the product, or any of its choices). */
function stock_low(array $p, array $vars): bool
{
    $level = (int)setting('low_stock_level', '3');
    foreach ($vars ?: [$p] as $x) {
        if ($x['stock'] !== null && (int)$x['stock'] <= $level) {
            return true;
        }
    }
    return false;
}

const ADMIN_PER_PAGE = 50;
const ADMIN_SHOWS = ['' => 'All products', 'visible' => 'In the shop', 'hidden' => 'Hidden', 'low' => 'Low or sold out', 'out' => 'Sold out',
                     'backorder' => 'On backorder', 'sale' => 'On sale', 'noweight' => 'No weight'];

/* The product list's search and filters, from a query string or from the
   'back' value a quick form sends. Only these keys are ever passed around. */
function admin_list_params(?array $from = null): array
{
    $from ??= $_GET;
    $find = trim(mb_substr(str_in($from['find'] ?? ''), 0, 100));
    $cat = str_in($from['cat'] ?? '');
    $show = str_in($from['show'] ?? '');
    $page = str_in($from['page'] ?? '');
    return ['find' => $find,
            'cat' => ctype_digit($cat) || $cat === 'none' ? $cat : '',
            'show' => isset(ADMIN_SHOWS[$show]) ? $show : '',
            'page' => ctype_digit($page) && (int)$page > 1 ? (int)$page : 1];
}

/* Hidden ?q= for a GET form on a shop without pretty URLs. */
function get_form_route(): string
{
    $q = str_in($_GET['q'] ?? '');
    return $q !== '' ? '<input type="hidden" name="q" value="' . e($q) . '">' : '';
}

function view_admin_products(): never
{
    require_admin();
    $f = admin_list_params();
    $where = ['1'];
    $args = [];
    if ($f['find'] !== '') {
        $like = '%' . strtr($f['find'], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        // Name, address, or part number (the product's or one of its choices').
        $where[] = "(p.name LIKE ? ESCAPE '\\' OR p.slug LIKE ? ESCAPE '\\' OR p.sku LIKE ? ESCAPE '\\'
                     OR EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.sku LIKE ? ESCAPE '\\'))";
        array_push($args, $like, $like, $like, $like);
    }
    if ($f['cat'] === 'none') {
        $where[] = 'p.category_id NOT IN (SELECT id FROM categories)';
    } elseif ($f['cat'] !== '') {   // the category and those inside it
        $where[] = cat_in_sql(cat_descendants((int)$f['cat']));
    }
    $level = (int)setting('low_stock_level', '3');
    $hasV = 'EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id)';
    $where[] = match ($f['show']) {
        'visible' => 'p.visible = 1',
        'hidden'  => 'p.visible = 0',
        'low'     => "p.backorders = 'no' AND ((NOT $hasV AND p.stock IS NOT NULL AND p.stock <= $level)
                       OR EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.stock IS NOT NULL AND v.stock <= $level))",
        'out'     => "p.backorders = 'no' AND ((NOT $hasV AND p.stock IS NOT NULL AND p.stock <= 0)
                       OR ($hasV AND NOT EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND (v.stock IS NULL OR v.stock > 0))))",
        // Something posted that counts as weighing nothing: no weight of its own, nor on every choice.
        'noweight' => "p.kind != 'digital' AND ((NOT $hasV AND p.weight_g IS NULL)
                       OR (p.weight_g IS NULL AND EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.active = 1 AND v.weight_g IS NULL)))",
        // Stock below zero: orders waiting for more to arrive.
        'backorder' => "((NOT $hasV AND p.stock < 0) OR EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.stock < 0))",
        'sale'    => (function () {
            $today = db()->quote(uk_today());
            return "(p.sale_from <= $today AND (p.sale_to = '' OR p.sale_to >= $today)) AND ((p.sale_pence IS NOT NULL AND p.sale_pence < p.price_pence)
                    OR EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.sale_pence IS NOT NULL AND v.sale_pence < v.price_pence))";
        })(),
        default   => '1',
    };
    $sqlWhere = implode(' AND ', $where);
    $st = db()->prepare("SELECT COUNT(*) FROM products p WHERE $sqlWhere");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $all = (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $pages = max(1, (int)ceil($total / ADMIN_PER_PAGE));
    $page = min($f['page'], $pages);
    $st = db()->prepare("SELECT p.*, c.name AS cat FROM products p LEFT JOIN categories c ON c.id = p.category_id
                         WHERE $sqlWhere ORDER BY p.created DESC, p.id DESC LIMIT " . ADMIN_PER_PAGE . ' OFFSET ' . (($page - 1) * ADMIN_PER_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    $photos = first_photos(array_column($rows, 'id'));
    $vars = variants_for(array_column($rows, 'id'));
    $back = '<input type="hidden" name="back" value="' . e(http_build_query(array_merge($f, ['page' => $page]))) . '">';
    $list = '';
    foreach ($rows as $p) {
        $ph = $photos[(int)$p['id']] ?? null;
        $pv = $vars[(int)$p['id']] ?? [];
        $edit = e(admin_url('edit/' . $p['id']));
        $stockForm = $pv
            ? '<span class="muted">' . count($pv) . ' ' . e(mb_strtolower($p['option_name'] ?: 'option')) . ' choices · <a href="' . $edit . '">stock per choice</a></span>'
            : '<form method="post" action="' . e(admin_url('quick')) . '">' . csrf_field() . $back
              . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">'
              . '<label class="muted" style="margin:0">Stock <input type="number" name="stock" min="0" inputmode="numeric" placeholder="—" value="'
              . ($p['stock'] === null ? '' : (int)$p['stock']) . '" aria-label="Stock for ' . e($p['name']) . '"></label>'
              . '<button class="quiet small" name="do" value="stock">Update</button></form>';
        $list .= '<li>'
            . ($ph ? '<img src="' . e(media_url($ph['thumb'])) . '" alt="" loading="lazy">' : '<span class="noimg"></span>')
            . '<div class="pname"><a href="' . $edit . '">' . e($p['name']) . '</a>'
            . (!$p['visible'] ? '<span class="pill">Hidden</span>' : '')
            . (is_digital($p) ? '<span class="pill">' . ((int)$p['licensed'] ? 'Theme' : 'Download') . '</span>' : '')
            . (sold_out($p, $pv) ? '<span class="pill out">Sold out</span>'
                : (backorders_on($p) ? (array_filter($pv ?: [$p], fn($x) => $x['stock'] !== null && (int)$x['stock'] < 0) ? '<span class="pill wait">On backorder</span>' : '')
                : (stock_low($p, $pv) ? '<span class="pill wait">Low stock</span>' : '')))
            . (product_on_sale($p, $pv) ? '<span class="pill ok">Sale</span>' : '')
            . '<small>' . price_label($p, $pv) . ($p['sku'] !== '' ? ' · <span class="sku">' . e($p['sku']) . '</span>' : '')
            . ($p['cat'] ? ' · ' . e(cat_path_name((int)$p['category_id'])) : '') . '</small></div>'
            . $stockForm
            . '<form method="post" action="' . e(admin_url('quick')) . '">' . csrf_field() . $back
            . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">'
            . '<button class="quiet small" name="do" value="' . ($p['visible'] ? 'hide' : 'show') . '">'
            . ($p['visible'] ? 'Hide' : 'Show') . '</button></form>'
            . '</li>';
    }
    $setup = can_deliver() && setting('postage_price') === ''
        ? '<p class="flash">Postage isn\'t set up yet, so the basket says "added at checkout". <a href="'
          . e(settings_url('delivery')) . '">Set postage in Settings</a>.</p>' : '';
    $filtering = $f['find'] !== '' || $f['cat'] !== '' || $f['show'] !== '';
    $tools = '';
    if ($all > 10 || $filtering) {   // a handful of products needs no search
        $cats = '<option value="">Any category</option>' . cat_options_html(ctype_digit($f['cat']) ? (int)$f['cat'] : -1, '');
        $cats .= '<option value="none"' . ($f['cat'] === 'none' ? ' selected' : '') . '>No category</option>';
        $shows = '';
        foreach (ADMIN_SHOWS as $k => $label) {
            if ($k === 'noweight' && !weights_used() && $f['show'] !== 'noweight') {
                continue;   // only matters to delivery priced by weight
            }
            $shows .= '<option value="' . $k . '"' . ($f['show'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $tools = '<form method="get" class="search">' . get_form_route()
            . '<input type="search" name="find" value="' . e($f['find']) . '" placeholder="Search by name or SKU" aria-label="Search products">'
            . '<select name="cat" aria-label="Category">' . $cats . '</select>'
            . '<select name="show" aria-label="Show">' . $shows . '</select>'
            . '<button class="quiet">Search</button></form>'
            . '<p class="muted">' . ($filtering
                ? number_format($total) . ' of ' . number_format($all) . ' products match. <a href="' . e(admin_url()) . '">Show all</a>'
                : number_format($all) . ' products')
            . ($pages > 1 ? ' · page ' . $page . ' of ' . $pages : '') . '</p>';
    }
    $link = fn($n) => url_with(admin_url(), ['find' => $f['find'], 'cat' => $f['cat'], 'show' => $f['show'], 'page' => $n]);
    $body = $list
        ? '<p class="muted">Leave stock empty if you don\'t want it counted.</p><ul class="plist">' . $list . '</ul>'
          . pager_html($page, $pages, $link, 'pager')
        : ($filtering ? '<p class="lede">No products match.</p>'
                      : '<p class="lede">No products yet. Add your first one: all it needs is a name, a price and a photo.</p>');
    admin_shell('Products', ready_reminder(current_admin()) . $setup . '<div class="topline"><h1>Products</h1><a class="btn" style="margin:0" href="'
        . e(admin_url('new')) . '">+ Add product</a></div>' . $tools . $body);
}

function admin_quick(): never
{
    require_admin();
    csrf_check();
    $p = product_by_id((int)($_POST['id'] ?? 0));
    if ($p) {
        $do = post('do');
        if ($do === 'stock' && product_variants((int)$p['id'])) {
            flash($p['name'] . ' has options, so its stock is set for each choice on its page.');
        } elseif ($do === 'stock') {
            $raw = post('stock');
            if ($raw === '' || ctype_digit($raw) || (backorders_on($p) && preg_match('/^-\d+$/', $raw))) {
                db()->prepare('UPDATE products SET stock = ?, updated = ? WHERE id = ?')
                    ->execute([$raw === '' ? null : (int)$raw, now(), $p['id']]);
                flash($p['name'] . ': stock ' . ($raw === '' ? 'not counted' : 'set to ' . (int)$raw) . '.');
            } else {
                flash('Stock must be a whole number, or empty.');
            }
        } elseif ($do === 'hide' || $do === 'show') {
            db()->prepare('UPDATE products SET visible = ?, updated = ? WHERE id = ?')
                ->execute([$do === 'show' ? 1 : 0, now(), $p['id']]);
            flash($p['name'] . ($do === 'show' ? ' is in the shop.' : ' is hidden from the shop.'));
        }
    }
    parse_str(post('back'), $back);
    $f = admin_list_params($back);
    redirect(url_with(admin_url(), $f));
}

function view_product_form(?array $p, array $v = [], array $errors = []): never
{
    require_admin();
    $isNew = $p === null;
    $v += $p ? [
        'name' => $p['name'], 'price' => price_input((int)$p['price_pence']), 'description' => $p['description'],
        'category_id' => (string)$p['category_id'], 'stock' => $p['stock'] === null ? '' : (string)$p['stock'],
        'visible' => (bool)$p['visible'], 'slug' => $p['slug'], 'sku' => $p['sku'],
        'extra_cats' => product_extra_cats((int)$p['id']), 'backorders' => $p['backorders'],
        'sale' => $p['sale_pence'] === null ? '' : price_input((int)$p['sale_pence']), 'sale_from' => $p['sale_from'], 'sale_to' => $p['sale_to'],
        'weight' => weight_input($p['weight_g'] === null ? null : (int)$p['weight_g']),
    ] : ['name' => '', 'price' => '', 'description' => '', 'category_id' => '0', 'stock' => '', 'visible' => true, 'slug' => '',
         'sku' => '', 'extra_cats' => [], 'backorders' => 'no', 'sale' => '', 'sale_from' => '', 'sale_to' => '', 'weight' => ''];
    $v += ['new_category' => ''];
    if (!isset($v['opts'])) {   // from the database, unless a failed save is being shown again
        $v['opt_name'] = $p['option_name'] ?? '';
        $v['opt_name2'] = $p['option2_name'] ?? '';
        $v['opts'] = [];
        foreach ($p ? product_variants((int)$p['id']) : [] as $x) {
            $v['opts'][] = ['id' => (string)$x['id'], 'name' => $x['name'], 'name2' => $x['name2'], 'on' => (int)$x['active'] === 1,
                            'price' => $x['price_pence'] === null ? '' : price_input((int)$x['price_pence']),
                            'stock' => $x['stock'] === null ? '' : (string)$x['stock'], 'sku' => $x['sku'],
                            'sale' => $x['sale_pence'] === null ? '' : price_input((int)$x['sale_pence']),
                            'weight' => weight_input($x['weight_g'] === null ? null : (int)$x['weight_g'])];
        }
    }
    $v += ['opt_name2' => '', 'grid_a' => '', 'grid_b' => ''];
    $two = $v['opt_name2'] !== '';
    $hasOpts = (bool)array_filter($v['opts'], fn($r) => $r['name'] !== '');
    $optRows = '';
    $blank = ['id' => '', 'name' => '', 'name2' => '', 'on' => true, 'price' => '', 'stock' => '', 'sku' => '', 'sale' => '', 'weight' => ''];
    // Weights only matter to delivery priced by weight, so they're only asked for then (or once set).
    $useW = weights_used() || ($v['weight'] ?? '') !== '' || array_filter($v['opts'], fn($r) => ($r['weight'] ?? '') !== '');
    foreach (array_merge($v['opts'], array_fill(0, 3, $blank)) as $n => $r) {
        $r += $blank;
        $k = $n + 1;
        // Rows are numbered so a switched-off row's empty On box still lines up with its other cells.
        $optRows .= '<div class="optrow"><input type="hidden" name="opt_id[' . $n . ']" value="' . e($r['id']) . '">'
            . '<input name="opt_val[' . $n . ']" value="' . e($r['name']) . '" maxlength="60" placeholder="' . ($n === 0 ? 'e.g. Small' : '') . '" aria-label="Choice ' . $k . '">'
            . '<input class="v2" name="opt_val2[' . $n . ']" value="' . e($r['name2']) . '" maxlength="60" aria-label="Second option for choice ' . $k . '">'
            . '<input name="opt_price[' . $n . ']" value="' . e($r['price']) . '" inputmode="decimal" placeholder="same" aria-label="Price for choice ' . $k . '">'
            . '<input name="opt_sale[' . $n . ']" value="' . e($r['sale']) . '" inputmode="decimal" placeholder="—" aria-label="Sale price for choice ' . $k . '">'
            . '<input type="number" name="opt_stock[' . $n . ']" value="' . e($r['stock']) . '" inputmode="numeric" placeholder="—" aria-label="Stock for choice ' . $k . '">'
            . ($useW ? '<input name="opt_weight[' . $n . ']" value="' . e($r['weight']) . '" inputmode="decimal" placeholder="same" aria-label="Weight in kg for choice ' . $k . '">' : '')
            . '<input name="opt_sku[' . $n . ']" value="' . e($r['sku']) . '" maxlength="40" autocapitalize="characters" placeholder="—" aria-label="SKU for choice ' . $k . '">'
            . '<label class="on"><input type="checkbox" name="opt_on[' . $n . ']" value="1"' . ($r['on'] ? ' checked' : '') . ' aria-label="Choice ' . $k . ' on sale"></label></div>';
    }
    $optionsHtml = '<details class="opts" id="opts"' . ($hasOpts || $v['opt_name'] !== '' ? ' open' : '') . '>'
        . '<summary>Options <span class="hint">(optional, e.g. sizes or colours)</span></summary>'
        . '<p class="muted">Use options when a customer has to choose, like a size, or a colour and a size. Each choice can have its own price, '
        . 'stock and SKU. Untick <b>On</b> for a combination you don\'t make; to remove a choice, clear its name.</p>'
        . '<div class="optnames"><label>Option name <input name="opt_name" value="' . e($v['opt_name']) . '" maxlength="40" placeholder="e.g. Size"></label>'
        . '<label>Second option <span class="hint">(optional)</span><input name="opt_name2" id="opt_name2" value="' . e($v['opt_name2']) . '" maxlength="40" placeholder="e.g. Colour"></label></div>'
        . '<div class="optgrid" id="optgrid"' . ($two ? '' : ' hidden') . '><p class="muted" style="margin:.8rem 0 .2rem">Type each option\'s values, separated by commas, to add every combination as a row:</p>'
        . '<div class="optnames"><input name="grid_a" value="' . e($v['grid_a']) . '" placeholder="e.g. Small, Medium, Large" aria-label="Values for the first option">'
        . '<input name="grid_b" value="' . e($v['grid_b']) . '" placeholder="e.g. Red, Blue" aria-label="Values for the second option">'
        . '<button class="quiet small" name="op" value="grid" formnovalidate>Make the combinations</button></div></div>'
        . '<div class="optrows' . ($two ? '' : ' one') . ($useW ? ' withw' : '') . '" id="optrows"><div class="optrow opthead"><span>Choice</span><span class="v2" id="v2head">' . e($v['opt_name2'] ?: 'Second') . '</span>'
        . '<span>Price <span class="hint">(blank: same)</span></span><span>Sale price <span class="hint">(own price only)</span></span>'
        . '<span>Stock <span class="hint">(blank: not counted)</span></span>' . ($useW ? '<span>Weight <span class="hint">(kg)</span></span>' : '') . '<span>SKU</span><span>On</span></div>' . $optRows . '</div>'
        . '<p class="muted">Need more rows? Save, and three more empty ones appear.</p></details>'
        . '<script>(function(){var n=document.getElementById("opt_name2"),r=document.getElementById("optrows"),g=document.getElementById("optgrid"),h=document.getElementById("v2head");'
        . 'function t(){var on=n.value.trim()!=="";r.classList.toggle("one",!on);g.hidden=!on;h.textContent=n.value.trim()||"Second";}n.addEventListener("input",t);})();</script>';

    $photos = $p ? product_photos((int)$p['id']) : [];
    $ph = '';
    foreach ($photos as $i => $x) {
        $last = $i === count($photos) - 1;
        $ph .= '<div class="photo"><img src="' . e(media_url($x['thumb'])) . '" alt="">'
             . ($i === 0 ? '<span class="main">Main photo</span>' : '')
             . '<div class="acts">'
             . ($i > 0 ? '<button name="op" value="first:' . (int)$x['id'] . '" title="Make this the main photo">Main</button>'
                       . '<button name="op" value="left:' . (int)$x['id'] . '" aria-label="Move earlier">&larr;</button>' : '')
             . (!$last ? '<button name="op" value="right:' . (int)$x['id'] . '" aria-label="Move later">&rarr;</button>' : '')
             . '<button class="rm" name="op" value="remove:' . (int)$x['id'] . '"'
             . ' onclick="return confirm(\'Remove this photo?\')">Remove</button></div></div>';
    }
    $cats = cat_options_html((int)$v['category_id']);
    $extra = array_flip(array_map('intval', $v['extra_cats']));
    $also = '';
    foreach (cat_tree() as $c) {
        $also .= '<label class="check catpick" style="padding-left:' . (($c['depth'] - 1) * 1.4) . 'rem"><input type="checkbox" name="extra_cats[]" value="' . $c['id'] . '"'
               . (isset($extra[$c['id']]) ? ' checked' : '') . '> ' . e($c['name']) . '</label>';
    }
    $action = e(admin_url('save'));
    $title = $isNew ? 'Add product' : 'Edit ' . $p['name'];

    $body = '<h1>' . e($isNew ? 'Add a product' : $p['name']) . '</h1>'
        . ($isNew ? '<p class="lede">Only the name, price and a photo are needed. Everything else can wait.</p>'
                  : '<p class="muted"><a href="' . e(shop_url('p/' . $p['slug'])) . '" target="_blank" rel="noopener">See it in the shop &rarr;</a>'
                    . (!$p['visible'] ? ' (hidden, so only you can see it while signed in)' : '') . '</p>')
        . errors_html($errors)
        . '<form method="post" action="' . $action . '" enctype="multipart/form-data" id="pform">' . csrf_field()
        . '<input type="hidden" name="id" value="' . ($p ? (int)$p['id'] : 0) . '">'
        . '<div class="pgrid"><div class="pmain">'
        . '<label>Name <input name="name" value="' . e($v['name']) . '" required maxlength="120"></label>'
        . '<label>Price <div class="price"><span>£</span><input name="price" value="' . e($v['price']) . '" inputmode="decimal"'
        . ' placeholder="12.50" required pattern="£?[0-9,]+(\.[0-9]{1,2})?" title="A price like 12 or 12.50"></div></label>'
        . '<details class="opts" id="sale"' . ($v['sale'] !== '' ? ' open' : '') . '><summary>Sale price <span class="hint">(optional'
        . ($p && $v['sale'] !== '' ? (sale_on($p) ? ': on sale now' : ': not on sale today') : '') . ')</span></summary>'
        . '<p class="muted">Customers see the sale price with the full price crossed out. Leave the dates empty to start now and run until you clear the price. '
        . 'A sale doesn\'t add up with group prices or discount codes: the customer gets whichever is cheapest.</p>'
        . '<label>Sale price <div class="price" style="max-width:14rem"><span>£</span><input name="sale" value="' . e($v['sale']) . '" inputmode="decimal" placeholder="9.99"></div></label>'
        . '<div class="saledates"><label>From <span class="hint">(first day)</span><input type="date" name="sale_from" value="' . e($v['sale_from']) . '"></label>'
        . '<label>Until <span class="hint">(last day)</span><input type="date" name="sale_to" value="' . e($v['sale_to']) . '"></label></div></details>'
        . '<label>Photos ' . ($isNew ? '' : '<span class="hint">(the first one is the main photo)</span>') . '</label>'
        . ($ph ? '<div class="photos">' . $ph . '</div>' : '')
        . '<div class="drop"><input type="file" name="photos[]" id="photos" accept="image/*" multiple'
        . ($isNew ? ' required' : '') . '><div id="previews"></div>'
        . '<p class="muted" style="margin:.4rem 0 0">' . ($isNew ? 'Take a photo or choose from your gallery. You can pick several.'
                                                               : 'Add more photos.') . '</p></div>'
        . '<label>Description <span class="hint">(optional)</span><textarea name="description" maxlength="5000">' . e($v['description']) . '</textarea></label>'
        . '<p class="muted" style="margin:.3rem 0 0">Leave a blank line between paragraphs. **bold**, *italic*, and lines starting "- " make a list.</p>'
        . $optionsHtml
        . group_prices_html($p, $v)
        . related_form_html($p, $v)
        . digital_fields_html($p, $v)
        . '</div><aside class="pside"><div class="pbox">'
        . '<label class="check"><input type="checkbox" name="visible" value="1"' . ($v['visible'] ? ' checked' : '') . '> Show in the shop</label>'
        . '<button id="savebtn">' . ($isNew ? 'Add product' : 'Save changes') . '</button>'
        . '</div>'
        . '<label>Category <span class="hint">(optional; its menu and breadcrumb)</span><select name="category_id">' . $cats . '</select></label>'
        . '<label><span class="hint">or a new category:</span><input name="new_category" value="' . e($v['new_category']) . '" maxlength="60" placeholder="e.g. Mugs"></label>'
        . ($also !== '' ? '<details class="opts" id="alsoin"' . ($extra ? ' open' : '') . '><summary>Also show in <span class="hint">(optional: other categories'
            . ($extra ? ', ' . count($extra) . ' chosen' : '') . ')</span></summary>'
            . '<p class="muted">For a part that fits several cars, say. It shows in each category you tick as well as its main one.</p>'
            . '<div class="catpicks">' . $also . '</div></details>' : '')
        . '<label>SKU <span class="hint">(optional: your part number or code, shown on orders and packing slips)</span>'
        . '<input name="sku" value="' . e($v['sku']) . '" maxlength="40" autocapitalize="characters"></label>'
        . '<label id="stockrow"' . ($hasOpts ? ' hidden' : '') . '>Stock <span class="hint">(optional: leave empty if you don\'t want it counted)</span>'
        . '<input type="number" name="stock" inputmode="numeric" value="' . e($v['stock']) . '"></label>'
        . '<p class="muted" id="stocknote"' . ($hasOpts ? '' : ' hidden') . '>Stock is counted for each choice in Options.</p>'
        . '<label>When counted stock runs out <select name="backorders">'
        . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . ($v['backorders'] === $k ? ' selected' : '') . '>' . e($l) . '</option>', array_keys(BACKORDER_CHOICES), BACKORDER_CHOICES))
        . '</select></label><p class="muted" style="margin:.3rem 0 0">Keep selling for things you can get more of: stock goes below zero and orders show how many were on backorder.</p>'
        . ($useW ? '<label>Weight <span class="hint">(kg, packed, for delivery priced by weight' . ($hasOpts ? '; a choice can have its own' : '') . ')</span>'
            . '<input name="weight" value="' . e($v['weight']) . '" inputmode="decimal" placeholder="0.5" style="max-width:8rem;display:block"></label>' : '')
        . (!$isNew ? '<details' . ($errors && $v['slug'] !== $p['slug'] ? ' open' : '') . '><summary>More</summary>'
            . '<label>Web address <span class="hint">(' . e(abs_shop_url('p/')) . '…) Changing it breaks old links.</span>'
            . '<input name="slug" value="' . e($v['slug']) . '" maxlength="80" autocapitalize="none"></label></details>' : '')
        . '</aside></div>'
        . '</form>';
    if (!$isNew) {
        $body .= '<div class="actions-row">'
            . '<form method="post" action="' . e(admin_url('duplicate')) . '">' . csrf_field()
            . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="quiet">Duplicate</button></form>'
            . '<form method="post" action="' . e(admin_url('delete')) . '" onsubmit="return confirm(\'Delete ' . e(addslashes($p['name']))
            . ' for good? This can\'t be undone.\')">' . csrf_field()
            . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="danger">Delete</button></form>'
            . '</div>';
    }
    $body .= '<p class="muted" style="margin-top:2rem">' . e(upload_limit_text()) . ' Photos are made smaller on your device before they upload.</p>'
        . photo_script()
        // With options, stock lives on each choice: swap the product's stock box for a note.
        . '<script>(function(){var f=document.getElementById("pform");function t(){var on=[].some.call(f.querySelectorAll("input[name=\'opt_val[]\']"),'
        . 'function(i){return i.value.trim()!==""});document.getElementById("stockrow").hidden=on;document.getElementById("stocknote").hidden=!on;}'
        . 'f.addEventListener("input",t);})();</script>';
    admin_shell($title, $body);
}

/* Shrinks photos in the browser before upload: phone photos are often 3–8 MB,
   above many hosts' upload limits, and slow on mobile data. If the browser
   can't do it, the original file goes up and the server resizes it. */
function photo_script(): string
{
    return <<<'JS'
<script>
(function () {
  var input = document.getElementById('photos'), box = document.getElementById('previews'),
      btn = document.getElementById('savebtn');
  if (!input || !window.DataTransfer || !window.createImageBitmap) return;
  input.addEventListener('change', async function () {
    var files = Array.from(input.files), dt = new DataTransfer();
    if (!files.length) return;
    btn.disabled = true; var label = btn.textContent; btn.textContent = 'Preparing photos…';
    box.innerHTML = '';
    for (var f of files) {
      var out = f;
      try {
        if (/^image\/(jpeg|png|webp|heic|heif)$/.test(f.type)) {
          var bmp = await createImageBitmap(f, { imageOrientation: 'from-image' });
          var s = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
          if (s < 1 || f.size > 1500000 || !/jpeg/.test(f.type)) {
            var c = document.createElement('canvas');
            c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
            var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            x.drawImage(bmp, 0, 0, c.width, c.height);
            var blob = await new Promise(function (r) { c.toBlob(r, 'image/jpeg', 0.86); });
            if (blob) out = new File([blob], f.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
          }
        }
      } catch (e) { out = f; }
      dt.items.add(out);
      var img = document.createElement('img'); img.src = URL.createObjectURL(out); box.appendChild(img);
    }
    input.files = dt.files;
    btn.disabled = false; btn.textContent = label;
  });
})();
</script>
JS;
}

function admin_save(): never
{
    require_admin();
    // A save bigger than post_max_size arrives with $_POST empty: say why.
    if (is_post() && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('Those photos were too big to upload in one go. ' . upload_limit_text() . ' Try fewer photos at a time.');
        redirect(admin_url());
    }
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $p = $id ? product_by_id($id) : null;
    if ($id && !$p) {
        redirect(admin_url());
    }
    $v = [
        'name' => post('name'), 'price' => post('price'), 'description' => post('description'),
        'category_id' => post('category_id'), 'new_category' => post('new_category'),
        'stock' => post('stock'), 'visible' => !empty($_POST['visible']), 'slug' => post('slug'),
        'sku' => sku_clean(post('sku')),
        'backorders' => isset(BACKORDER_CHOICES[post('backorders')]) ? post('backorders') : 'no',
        'sale' => post('sale'), 'sale_from' => post('sale_from'), 'sale_to' => post('sale_to'),
        'extra_cats' => array_map('intval', array_filter(is_array($_POST['extra_cats'] ?? null) ? $_POST['extra_cats'] : [], 'is_string')),
        'weight' => isset($_POST['weight']) ? post('weight') : weight_input(($p['weight_g'] ?? null) === null ? null : (int)$p['weight_g']),
    ];
    $errors = [];
    if ($v['weight'] !== '' && parse_weight($v['weight']) === null) {
        $errors[] = 'The weight should be in kg, like 0.5 or 2.';
    }
    if (!sku_valid($v['sku'])) {
        $errors[] = 'A SKU can have letters, numbers, spaces and . _ / - in it.';
    } elseif (($who = sku_taken($v['sku'], (int)($p['id'] ?? 0))) !== null) {
        $errors[] = 'The SKU ' . $v['sku'] . ' is already used by ' . $who . '.';
    }
    if ($v['name'] === '') {
        $errors[] = 'Give the product a name.';
    }
    $pence = parse_price($v['price']);
    if ($pence === null) {
        $errors[] = 'Type the price as a number, like 12 or 12.50.';
    }
    if ($v['stock'] !== '' && !preg_match('/^-?\d+$/', $v['stock'])) {
        $errors[] = 'Stock must be a whole number, or left empty.';
    } elseif ((int)$v['stock'] < 0 && $v['backorders'] === 'no') {
        $errors[] = 'Stock can only go below zero for a product that keeps selling when it runs out.';
    }
    $salePence = $v['sale'] === '' ? null : parse_price($v['sale']);
    if ($v['sale'] !== '' && $salePence === null) {
        $errors[] = 'Type the sale price as a number, like 9.99.';
    } elseif ($salePence !== null && $pence !== null && $salePence >= $pence) {
        $errors[] = 'The sale price should be less than the price (' . money($pence) . ').';
    }
    [$sf, $stTo] = [sale_date($v['sale_from']), sale_date($v['sale_to'])];
    if ($sf === null || $stTo === null) {
        $errors[] = 'Sale dates are days, like 2026-12-01.';
    } elseif ($sf !== '' && $stTo !== '' && $stTo < $sf) {
        $errors[] = 'The sale ends before it starts. Check the dates.';
    }
    // Options: rows of choice (and second option) / price / stock. Empty names are dropped.
    $v['opt_name'] = post('opt_name');
    $v['opt_name2'] = mb_substr(post('opt_name2'), 0, 40);
    $v['grid_a'] = post('grid_a');
    $v['grid_b'] = post('grid_b');
    $v['opts'] = [];
    $col = fn($k) => is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
    [$oid, $oval, $oval2, $oprice, $ostock, $osku, $osale, $oon, $owt] = [$col('opt_id'), $col('opt_val'), $col('opt_val2'), $col('opt_price'),
        $col('opt_stock'), $col('opt_sku'), $col('opt_sale'), $col('opt_on'), $col('opt_weight')];
    $wSent = isset($_POST['opt_weight']);   // weights are only on the form when delivery is priced by weight
    $oldW = [];
    foreach ($p ? product_variants((int)$p['id']) : [] as $x) {
        $oldW[(string)$x['id']] = weight_input($x['weight_g'] === null ? null : (int)$x['weight_g']);
    }
    $onSent = isset($_POST['opt_on']) || isset($_POST['opt_val2']);   // forms before 1.3 had no On boxes: every choice is on
    $str = fn(array $a, $i) => is_string($a[$i] ?? null) ? trim($a[$i]) : '';
    $seen = [];
    $skus = $v['sku'] !== '' ? [mb_strtolower($v['sku']) => true] : [];
    foreach ($oval as $i => $name) {
        $r = ['id' => $str($oid, $i), 'name' => is_string($name) ? trim($name) : '', 'name2' => $v['opt_name2'] !== '' ? mb_substr($str($oval2, $i), 0, 60) : '',
              'price' => $str($oprice, $i), 'stock' => $str($ostock, $i), 'sku' => sku_clean($str($osku, $i)), 'sale' => $str($osale, $i),
              'on' => !$onSent || !empty($oon[$i])];
        $r['weight'] = $wSent ? $str($owt, $i) : ($oldW[$r['id']] ?? '');
        if ($r['name'] === '') {
            continue;
        }
        $label = choice_name($r);
        if ($r['weight'] !== '' && parse_weight($r['weight']) === null) {
            $errors[] = 'The weight for ' . $label . ' should be in kg, like 0.5.';
        }
        if ($v['opt_name2'] !== '' && $r['name2'] === '') {
            $errors[] = 'Choose the ' . mb_strtolower($v['opt_name2']) . ' for ' . $r['name'] . ', or clear its name to remove it.';
        }
        if (!sku_valid($r['sku'])) {
            $errors[] = 'The SKU for ' . $label . ' can have letters, numbers, spaces and . _ / - in it.';
        } elseif ($r['sku'] !== '' && isset($skus[mb_strtolower($r['sku'])])) {
            $errors[] = 'The SKU ' . $r['sku'] . ' is used twice on this product.';
        } elseif (($who = sku_taken($r['sku'], (int)($p['id'] ?? 0))) !== null) {
            $errors[] = 'The SKU ' . $r['sku'] . ' (for ' . $label . ') is already used by ' . $who . '.';
        }
        if ($r['sku'] !== '') {
            $skus[mb_strtolower($r['sku'])] = true;
        }
        if ($r['price'] !== '' && parse_price($r['price']) === null) {
            $errors[] = 'The price for ' . $label . ' isn\'t a price. Leave it blank to use the product\'s price.';
        }
        if ($r['stock'] !== '' && !preg_match('/^-?\d+$/', $r['stock'])) {
            $errors[] = 'Stock for ' . $label . ' must be a whole number, or left empty.';
        } elseif ((int)$r['stock'] < 0 && $v['backorders'] === 'no') {
            $errors[] = 'Stock for ' . $label . ' can only go below zero if the product keeps selling when it runs out.';
        }
        if ($r['sale'] !== '') {
            $sp = parse_price($r['sale']);
            $own = $r['price'] === '' ? null : parse_price($r['price']);
            if ($sp === null) {
                $errors[] = 'The sale price for ' . $label . ' isn\'t a price.';
            } elseif ($own === null) {
                $errors[] = $label . ' has a sale price but no price of its own. Give it a price, or use the product\'s sale price.';
            } elseif ($sp >= $own) {
                $errors[] = 'The sale price for ' . $label . ' should be less than its price.';
            }
        }
        if (isset($seen[mb_strtolower($label)])) {
            $errors[] = 'There are two choices called ' . $label . '.';
        }
        $seen[mb_strtolower($label)] = true;
        $v['opts'][] = $r;
    }
    if ($v['opts'] && $v['opt_name'] === '') {
        $errors[] = 'Give the options a name, like Size or Colour.';
    }
    // Make the combinations: add a row for each pair of values not already there, and show the form
    // again to fill in (nothing is saved yet).
    if (post('op') === 'grid') {
        $vals = fn(string $s) => array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 60), explode(',', $s)), fn($x) => $x !== '')));
        [$as, $bs] = [$vals($v['grid_a']), $v['opt_name2'] !== '' ? $vals($v['grid_b']) : ['']];
        $have = array_flip(array_map(fn($r) => mb_strtolower($r['name'] . '|' . $r['name2']), $v['opts']));
        $added = 0;
        foreach ($as as $a) {
            foreach ($bs ?: [''] as $b) {
                if (!isset($have[mb_strtolower($a . '|' . $b)])) {
                    $v['opts'][] = ['id' => '', 'name' => $a, 'name2' => $b, 'price' => '', 'stock' => '', 'sku' => '', 'sale' => '', 'on' => true, 'weight' => ''];
                    $added++;
                }
            }
        }
        $v['grid_a'] = $v['grid_b'] = '';
        flash($added ? $added . ' combination' . ($added === 1 ? '' : 's') . ' added below. Fill in any prices and stock, then save.'
                       . (uploaded_photos() ? ' Please choose the new photos again.' : '')
                     : 'Every combination of those values is already there.');
        view_product_form($p, $v, []);
    }
    $uploads = uploaded_photos();
    $existing = $p ? product_photos((int)$p['id']) : [];
    if (!$existing && !$uploads) {
        $errors[] = 'Add at least one photo.';
    }
    $op = post('op');
    if ($op !== '' && str_starts_with($op, 'remove:') && count($existing) === 1 && !$uploads) {
        $errors[] = 'A product needs at least one photo. Add another before removing this one.';
        $op = '';
    }
    $dg = digital_input($p, $v, $errors);
    $gpIn = group_prices_read($errors);
    $relIn = isset($_POST['related']) ? related_parse(post('related'), (int)($p['id'] ?? 0), $errors) : null;
    $v['related'] = isset($_POST['related']) ? post('related') : null;
    $v['gp'] = is_array($_POST['gp'] ?? null) ? $_POST['gp'] : null;
    if ($errors) {
        view_product_form($p, $v, array_merge($errors, $dg['upload'] ? ['Please choose the files again.'] : ($uploads ? ['Please choose the new photos again.'] : [])));
    }

    $pdo = db();
    $pdo->beginTransaction();
    $catId = (int)$v['category_id'];
    if ($v['new_category'] !== '') {
        $st = $pdo->prepare('SELECT id FROM categories WHERE name = ? COLLATE NOCASE');
        $st->execute([$v['new_category']]);
        $catId = (int)($st->fetchColumn() ?: 0);
        if (!$catId) {
            $pdo->prepare('INSERT INTO categories (name, slug, position) VALUES (?, ?, ?)')
                ->execute([$v['new_category'], unique_slug('categories', slugify($v['new_category'])), cat_next_position(0)]);
            $catId = (int)$pdo->lastInsertId();
            cat_all(true);
        }
    }
    if ($catId && !cat_by_id($catId)) {
        $catId = 0;
    }
    $stock = $v['stock'] === '' ? null : (int)$v['stock'];
    if ($p) {
        $slug = $v['slug'] !== '' ? unique_slug('products', slugify($v['slug']), (int)$p['id']) : $p['slug'];
        $pdo->prepare('UPDATE products SET name = ?, slug = ?, price_pence = ?, description = ?, category_id = ?,
                       stock = ?, visible = ?, sku = ?, updated = ? WHERE id = ?')
            ->execute([$v['name'], $slug, $pence, $v['description'], $catId, $stock, $v['visible'] ? 1 : 0, $v['sku'], now(), $p['id']]);
        $pid = (int)$p['id'];
    } else {
        $pdo->prepare('INSERT INTO products (slug, name, price_pence, description, category_id, stock, visible, sku, created, updated)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([unique_slug('products', slugify($v['name'])), $v['name'], $pence, $v['description'],
                       $catId, $stock, $v['visible'] ? 1 : 0, $v['sku'], now(), now()]);
        $pid = (int)$pdo->lastInsertId();
    }
    product_set_extra_cats($pid, $v['extra_cats'], $catId);
    $pdo->prepare('UPDATE products SET backorders = ?, sale_pence = ?, sale_from = ?, sale_to = ?, weight_g = ? WHERE id = ?')
        ->execute([$v['backorders'], $salePence, $sf, $stTo, $v['weight'] === '' ? null : parse_weight($v['weight']), $pid]);

    // Choices: update the ones kept (by id), add new ones, delete the rest.
    $old = [];
    foreach (product_variants($pid) as $x) {
        $old[(int)$x['id']] = $x;
    }
    $kept = [];
    foreach ($v['opts'] as $n => $r) {
        $vp = $r['price'] === '' ? null : parse_price($r['price']);
        $vs = $r['stock'] === '' ? null : (int)$r['stock'];
        $vsale = $r['sale'] === '' || $vp === null ? null : parse_price($r['sale']);
        $xid = (int)$r['id'];
        $vw = ($r['weight'] ?? '') === '' ? null : parse_weight($r['weight']);
        if ($xid && isset($old[$xid])) {
            $pdo->prepare('UPDATE variants SET name = ?, price_pence = ?, stock = ?, position = ?, sku = ?, sale_pence = ?, name2 = ?, active = ?, weight_g = ? WHERE id = ?')
                ->execute([$r['name'], $vp, $vs, $n, $r['sku'], $vsale, $r['name2'], $r['on'] ? 1 : 0, $vw, $xid]);
            $kept[$xid] = true;
        } else {
            $pdo->prepare('INSERT INTO variants (product_id, name, price_pence, stock, position, sku, sale_pence, name2, active, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$pid, $r['name'], $vp, $vs, $n, $r['sku'], $vsale, $r['name2'], $r['on'] ? 1 : 0, $vw]);
        }
    }
    foreach ($old as $xid => $x) {
        if (!isset($kept[$xid])) {
            $pdo->prepare('DELETE FROM variants WHERE id = ?')->execute([$xid]);
        }
    }
    if ($v['opts']) {   // with choices, stock is only counted per choice
        $pdo->prepare('UPDATE products SET option_name = ?, option2_name = ?, stock = NULL WHERE id = ?')->execute([$v['opt_name'], $v['opt_name2'], $pid]);
    } else {
        $pdo->prepare("UPDATE products SET option_name = '', option2_name = '' WHERE id = ?")->execute([$pid]);
    }
    group_prices_save($pid, $gpIn);
    if ($relIn !== null) {
        related_save($pid, $relIn);
    }

    $msgs = [];
    $pos = count($existing);
    foreach ($uploads as $up) {
        $err = null;
        $files = photo_store($up, $err);
        if ($files) {
            $pdo->prepare('INSERT INTO photos (product_id, file, thumb, position) VALUES (?, ?, ?, ?)')
                ->execute([$pid, $files[0], $files[1], $pos++]);
        } else {
            $msgs[] = $err;
        }
    }

    // Photo buttons on the form submit the whole form, so typing isn't lost.
    if (preg_match('/^(first|left|right|remove):(\d+)$/', $op, $m)) {
        $list = product_photos($pid);
        $ids = array_map('intval', array_column($list, 'id'));
        $i = array_search((int)$m[2], $ids, true);
        if ($i !== false) {
            if ($m[1] === 'remove') {
                photo_delete_files($list[$i]);
                $pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([$ids[$i]]);
                array_splice($ids, $i, 1);
            } elseif ($m[1] === 'first') {
                array_unshift($ids, array_splice($ids, $i, 1)[0]);
            } else {
                $j = $m[1] === 'left' ? $i - 1 : $i + 1;
                if (isset($ids[$j])) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                }
            }
            foreach ($ids as $n => $phId) {
                $pdo->prepare('UPDATE photos SET position = ? WHERE id = ?')->execute([$n, $phId]);
            }
        }
    }

    digital_save($pid, $dg, $msgs);
    if ($dg['kind'] === 'digital' && !product_files($pid) && $v['visible']) {   // nothing to download yet
        $pdo->prepare('UPDATE products SET visible = 0 WHERE id = ?')->execute([$pid]);
        $msgs[] = 'It\'s hidden until it has a file to download.';
    }

    // Uploads can all fail (wrong file type): never leave a photo-less product on sale.
    $hidden = false;
    if (!product_photos($pid) && $v['visible']) {
        $pdo->prepare('UPDATE products SET visible = 0 WHERE id = ?')->execute([$pid]);
        $hidden = true;
    }
    $pdo->commit();

    $name = $v['name'];
    $msg = $p ? 'Saved.' : $name . ' added' . ($v['visible'] && !$hidden ? ' and in the shop.' : '.');
    if ($op !== '' && $p) {
        $msg = 'Photos updated and changes saved.';
    }
    if ($msgs) {
        $msg .= ' ' . implode(' ', $msgs);
    }
    if ($hidden) {
        $msg .= ' It\'s hidden until it has a photo.';
    }
    flash($msg);
    redirect(admin_url('edit/' . $pid));
}

function admin_duplicate(): never
{
    require_admin();
    csrf_check();
    $p = product_by_id((int)($_POST['id'] ?? 0));
    if (!$p) {
        redirect(admin_url());
    }
    $pdo = db();
    $pdo->beginTransaction();
    $name = $p['name'] . ' (copy)';
    $pdo->prepare('INSERT INTO products (slug, name, price_pence, description, category_id, stock, option_name, visible, created, updated)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')
        ->execute([unique_slug('products', slugify($name)), $name, $p['price_pence'], $p['description'],
                   $p['category_id'], $p['stock'], $p['option_name'], now(), now()]);
    $nid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE products SET kind = ?, licensed = ?, renew_pence = ?, update_months = ?, backorders = ?, sale_pence = ?, sale_from = ?, sale_to = ?, option2_name = ?, weight_g = ? WHERE id = ?')
        ->execute([$p['kind'], $p['licensed'], $p['renew_pence'], $p['update_months'], $p['backorders'], $p['sale_pence'], $p['sale_from'], $p['sale_to'], $p['option2_name'], $p['weight_g'], $nid]);
    // Same categories; not the SKUs, which have to be different (the copy is usually the next size or colour).
    $pdo->prepare('INSERT INTO product_categories (product_id, category_id) SELECT ?, category_id FROM product_categories WHERE product_id = ?')
        ->execute([$nid, $p['id']]);
    foreach (product_variants((int)$p['id']) as $x) {
        $pdo->prepare('INSERT INTO variants (product_id, name, price_pence, stock, position, sale_pence, name2, active, weight_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nid, $x['name'], $x['price_pence'], $x['stock'], $x['position'], $x['sale_pence'], $x['name2'], $x['active'], $x['weight_g']]);
        $pdo->prepare('INSERT INTO group_prices (group_id, product_id, variant_id, price_pence) SELECT group_id, ?, ?, price_pence FROM group_prices WHERE product_id = ? AND variant_id = ?')
            ->execute([$nid, (int)$pdo->lastInsertId(), $p['id'], $x['id']]);
    }
    $pdo->prepare('INSERT INTO group_prices (group_id, product_id, variant_id, price_pence) SELECT group_id, ?, 0, price_pence FROM group_prices WHERE product_id = ? AND variant_id = 0')
        ->execute([$nid, $p['id']]);
    $pdo->prepare('INSERT INTO product_related (product_id, related_id, position) SELECT ?, related_id, position FROM product_related WHERE product_id = ?')
        ->execute([$nid, $p['id']]);
    foreach (product_photos((int)$p['id']) as $ph) {
        $file = $ph['file'];
        $thumb = $ph['thumb'];
        if (str_starts_with($file, 'p/')) {   // own copy, so deleting one product never breaks the other
            $base = 'p/' . bin2hex(random_bytes(8));
            if (@copy(MEDIA_DIR . '/' . $file, MEDIA_DIR . '/' . $base . '.jpg')
                && @copy(MEDIA_DIR . '/' . $thumb, MEDIA_DIR . '/' . $base . '-t.jpg')) {
                [$file, $thumb] = [$base . '.jpg', $base . '-t.jpg'];
            }
        }
        $pdo->prepare('INSERT INTO photos (product_id, file, thumb, position) VALUES (?, ?, ?, ?)')
            ->execute([$nid, $file, $thumb, $ph['position']]);
    }
    $pdo->commit();
    flash('Copy made and hidden for now. Change what you need, tick "Show in the shop", and save.');
    redirect(admin_url('edit/' . $nid));
}

function admin_delete(): never
{
    require_admin();
    csrf_check();
    $p = product_by_id((int)($_POST['id'] ?? 0));
    $sold = $p ? (int)db()->query('SELECT COUNT(*) FROM licences WHERE product_id = ' . (int)$p['id'])->fetchColumn() : 0;
    if ($sold) {
        flash($p['name'] . ' has been bought as a download, and customers still need it. Hide it instead (untick "Show in the shop").');
        redirect(admin_url('edit/' . $p['id']));
    }
    if ($p) {
        foreach (product_photos((int)$p['id']) as $ph) {
            $st = db()->prepare('SELECT COUNT(*) FROM photos WHERE file = ? AND id != ?');
            $st->execute([$ph['file'], $ph['id']]);
            if (!(int)$st->fetchColumn()) {
                photo_delete_files($ph);
            }
        }
        foreach (product_files((int)$p['id']) as $f) {   // never bought, so nobody needs these
            @unlink(files_dir() . '/' . basename($f['file']));
        }
        db()->prepare('DELETE FROM product_files WHERE product_id = ?')->execute([$p['id']]);
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$p['id']]);
        db()->prepare('DELETE FROM group_prices WHERE product_id = ?')->execute([$p['id']]);
        db()->prepare('DELETE FROM product_related WHERE product_id = ? OR related_id = ?')->execute([$p['id'], $p['id']]);
        flash($p['name'] . ' deleted.');
    }
    redirect(admin_url());
}

/* -------------------------------------------------------------- categories */

function view_admin_categories(): never
{
    require_admin();
    if (is_post()) {
        csrf_check();
        $do = post('do');
        $name = mb_substr(post('name'), 0, 60);
        $id = (int)($_POST['id'] ?? 0);
        $c = $id ? cat_by_id($id) : null;
        $parent = (int)($_POST['parent'] ?? 0);
        $parentOk = fn($pid, $height) => $pid === 0 || (cat_by_id($pid) && cat_depth($pid) + $height <= CAT_DEPTH);
        if ($do === 'add' && $name !== '') {
            if (!$parentOk($parent, 1)) {
                flash('Categories go ' . CAT_DEPTH . ' levels deep at most, so it can\'t go there.');
            } else {
                $st = db()->prepare('SELECT id FROM categories WHERE name = ? COLLATE NOCASE AND parent_id = ?');
                $st->execute([$name, $parent]);
                if ($st->fetch()) {
                    flash('There\'s already a ' . $name . ' there.');
                } else {
                    db()->prepare('INSERT INTO categories (name, slug, position, parent_id) VALUES (?, ?, ?, ?)')
                        ->execute([$name, unique_slug('categories', slugify($parent ? cat_path_name($parent) . ' ' . $name : $name)), cat_next_position($parent), $parent]);
                    flash($name . ' added' . ($parent ? ' inside ' . cat_path_name($parent) : '') . '.');
                }
            }
        } elseif ($do === 'save' && $c && $name !== '') {
            if ($parent === $c['id'] || in_array($parent, cat_descendants($c['id']), true) || !$parentOk($parent, cat_height($c['id']))) {
                flash($c['name'] . ' can\'t go there: categories go ' . CAT_DEPTH . ' levels deep at most, and one can\'t go inside itself.');
            } else {
                $moved = $parent !== $c['parent_id'];
                db()->prepare('UPDATE categories SET name = ?, parent_id = ?' . ($moved ? ', position = ' . cat_next_position($parent) : '') . ' WHERE id = ?')
                    ->execute([$name, $parent, $c['id']]);
                flash($moved ? $name . ' moved' . ($parent ? ' into ' . cat_path_name($parent) : ' to the top level') . '.' : 'Saved.');
            }
        } elseif (($do === 'up' || $do === 'down') && $c) {
            $ids = array_column(cat_children($c['parent_id']), 'id');
            $i = array_search($c['id'], $ids, true);
            $j = $do === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }
            cat_renumber($ids);
            redirect(admin_url('categories') . '#cat-' . $c['id']);
        } elseif ($do === 'delete' && $c) {
            $inside = count(cat_children($c['id']));
            cat_delete($c['id']);
            flash($c['name'] . ' deleted. ' . ($inside ? 'The categories inside it moved up a level, and its' : 'Its')
                . ' products are still in the shop' . ($c['parent_id'] ? ', in ' . cat_path_name($c['parent_id']) : '') . '.');
        }
        redirect(admin_url('categories'));
    }
    // Products in each category: its own (main or extra), and with everything inside it.
    $own = [];
    foreach (db()->query('SELECT category_id c, COUNT(*) n FROM (SELECT category_id FROM products WHERE category_id > 0
                          UNION ALL SELECT category_id FROM product_categories) GROUP BY category_id') as $r) {
        $own[(int)$r['c']] = (int)$r['n'];
    }
    $tree = cat_tree();
    $rows = '';
    foreach ($tree as $c) {
        $sib = array_column(cat_children($c['parent_id']), 'id');
        $k = array_search($c['id'], $sib, true);
        $total = array_sum(array_map(fn($i) => $own[$i] ?? 0, cat_descendants($c['id'])));
        $n = $own[$c['id']] ?? 0;
        $height = cat_height($c['id']);
        $f = 'cf' . $c['id'];
        $move = '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . $c['id'] . '">'
              . ($k > 0 ? '<button class="quiet small" name="do" value="up" aria-label="Move ' . e($c['name']) . ' up">&uarr;</button>' : '<span class="nomove"></span>')
              . ($k < count($sib) - 1 ? '<button class="quiet small" name="do" value="down" aria-label="Move ' . e($c['name']) . ' down">&darr;</button>' : '')
              . '</form>';
        $rows .= '<tr id="cat-' . $c['id'] . '" class="d' . $c['depth'] . '"><td class="mv">' . $move . '</td>'
            . '<td class="cname"><form method="post" id="' . $f . '">' . csrf_field() . '<input type="hidden" name="id" value="' . $c['id'] . '"><input type="hidden" name="do" value="save"></form>'
            . '<input form="' . $f . '" name="name" value="' . e($c['name']) . '" maxlength="60" aria-label="Name of ' . e($c['name']) . '" required></td>'
            . '<td><select form="' . $f . '" name="parent" aria-label="Where ' . e($c['name']) . ' goes">'
            . cat_options_html($c['parent_id'], 'Top level', CAT_DEPTH - $height, $c['id']) . '</select></td>'
            . '<td><button form="' . $f . '" class="quiet small">Save</button></td>'
            . '<td class="muted"><a href="' . e(url_with(admin_url(), ['cat' => $c['id']])) . '">' . number_format($total) . ' product' . ($total === 1 ? '' : 's') . '</a>'
            . ($total !== $n ? ' <span title="' . $n . ' directly in it">(' . $n . ' here)</span>' : '') . '</td>'
            . '<td><a href="' . e(shop_url('c/' . $c['slug'])) . '" target="_blank" rel="noopener" class="muted">View</a></td>'
            . '<td><form method="post" onsubmit="return confirm(\'Delete ' . e(addslashes($c['name'])) . '? '
            . (cat_children($c['id']) ? 'The categories inside it move up a level. ' : '') . 'Its products stay in the shop.\')">' . csrf_field()
            . '<input type="hidden" name="id" value="' . $c['id'] . '"><button class="danger small" name="do" value="delete">Delete</button></form></td></tr>';
    }
    admin_shell('Categories', '<h1>Categories</h1>
      <p class="lede">Categories make the shop menu, in this order. They can go inside each other, up to ' . CAT_DEPTH . ' levels
      (for example Cars › SRX2 › Spares). A category only shows in the shop once it has something for sale.</p>'
      . ($rows ? '<div class="table-wrap"><table class="cats"><thead><tr><th><span class="vh">Order</span></th><th>Name</th><th>Goes in</th><th></th><th>Products</th><th></th><th></th></tr></thead>' . $rows . '</table></div>' : '') . '
      <h2>Add a category</h2>
      <form method="post" class="addcat">' . csrf_field() . '<input type="hidden" name="do" value="add">
        <label>Name <input name="name" required maxlength="60" placeholder="e.g. Mugs"></label>
        <label>Goes in <select name="parent">' . cat_options_html(0, 'Top level', CAT_DEPTH - 1) . '</select></label>
        <button>Add category</button></form>');
}


/* ---------------------------------------------------------------- settings */

/* Settings tabs, and which tab each form lives on (so a save comes back to it). */
const SETTINGS_TABS = ['general' => 'Shop', 'delivery' => 'Delivery', 'payments' => 'Payments', 'email' => 'Email',
                       'business' => 'Business and VAT', 'account' => 'Your sign-in', 'advanced' => 'Advanced'];
const SETTINGS_FORMS = ['shop' => 'general', 'search' => 'general', 'accounts' => 'general', 'stock' => 'general', 'demo' => 'general',
                        'postage' => 'delivery', 'payments' => 'payments', 'email' => 'email', 'email_test' => 'email',
                        'business' => 'business', 'vat' => 'business', 'legal' => 'business',
                        'password' => 'account', 'signout_all' => 'account', '2fa_start' => 'account', '2fa_confirm' => 'account', '2fa_off' => 'account',
                        'site_url' => 'advanced', 'proxy' => 'advanced', 'version' => 'advanced', 'refresh' => 'advanced'];

/* The settings page for a tab, with an optional #section: settings_url('payments'). */
function settings_url(string $tab = 'general', string $anchor = ''): string
{
    return admin_url($tab === 'general' ? 'settings' : 'settings/' . $tab) . ($anchor !== '' ? '#' . $anchor : '');
}

function view_admin_settings(string $tab = 'general'): never
{
    $tab = isset(SETTINGS_TABS[$tab]) ? $tab : 'general';
    $u = require_admin();
    if (is_post()) {
        csrf_check();
        switch (post('form')) {
            case 'shop':
                set_setting('shop_title', post('shop_title') ?: 'Shop');
                set_setting('cat_menu', post('cat_menu') === 'top' ? 'top' : 'side');
                $per = post('per_page');
                if (ctype_digit($per) && (int)$per >= 6 && (int)$per <= 96) {
                    set_setting('per_page', (string)(int)$per);
                    flash('Saved.');
                } else {
                    flash('Products per page must be a whole number from 6 to 96. The heading was saved.');
                }
                break;
            case 'search':
                $lines = array_filter(array_map('trim', preg_split('/\R/', mb_substr(str_in($_POST['search_synonyms'] ?? ''), 0, 5000))), fn($l) => $l !== '');
                set_setting('search_synonyms', implode("\n", $lines));
                flash($lines ? 'Saved. Searches now treat ' . count($lines) . ' group' . (count($lines) === 1 ? '' : 's') . ' of words as the same.' : 'Saved: no synonyms.');
                break;
            case 'postage':
                $deliver = !empty($_POST['deliver']);
                $collect = !empty($_POST['collect']);
                // Priced by weight or value, the form has no price boxes: those stay as they are.
                $priced = isset($_POST['postage_price']);
                $price = $priced ? post('postage_price') : setting('postage_price');
                $over = $priced ? post('postage_free_over') : setting('postage_free_over');
                if (!$priced) {
                    $price = $price === '' ? '' : price_input((int)$price);
                    $over = $over === '' ? '' : price_input((int)$over);
                }
                $pp = $price === '' ? null : parse_price($price);
                $op = $over === '' ? null : parse_price($over);
                if (!$deliver && !$collect) {
                    flash('Customers need at least one way to get their order: delivery or collection. Nothing changed.');
                } elseif ($price !== '' && $pp === null || $over !== '' && $op === null) {
                    flash('Prices need to be numbers like 3.95. Nothing changed.');
                } elseif ($deliver && $pp === null && (setting('postage_pricing') ?: 'flat') === 'flat') {
                    flash('Set a delivery price to offer delivery (0 for free delivery). Nothing changed.');
                } else {
                    set_setting('postage_delivery', $deliver ? '1' : '0');
                    set_setting('postage_price', $pp === null ? '' : (string)$pp);
                    set_setting('postage_free_over', $op === null ? '' : (string)$op);
                    set_setting('postage_collect', $collect ? '1' : '0');
                    set_setting('postage_collect_note', post('postage_collect_note'));
                    flash('Postage saved.');
                }
                break;
            case 'vat':
                $on = !empty($_POST['vat_registered']);
                $num = strtoupper(preg_replace('/[\s.-]/', '', post('vat_number')));
                if ($on && !preg_match('/^(GB)?(\d{9}|\d{12})$/', $num)) {
                    flash('That doesn\'t look like a UK VAT number (GB and 9 digits, like GB123456789). Nothing changed.');
                } else {
                    set_setting('vat_registered', $on ? '1' : '0');
                    set_setting('vat_number', $on ? 'GB' . preg_replace('/^GB/', '', $num) : '');
                    flash($on ? 'VAT saved. Baskets now show the VAT included.' : 'VAT is off: no VAT is shown anywhere.');
                }
                break;
            case 'business':
                $mail = post('business_email');
                if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                    flash('That email address doesn\'t look right. Nothing changed.');
                    break;
                }
                set_setting('business_name', post('business_name'));
                set_setting('business_email', $mail);
                set_setting('business_address', post('business_address'));
                flash('Business details saved.');
                break;
            case 'payments':
                $notes = [];
                $sort = preg_replace('/\D/', '', post('bank_sort_code'));
                $acct = preg_replace('/\D/', '', post('bank_account'));
                if ($sort !== '' && strlen($sort) !== 6) {
                    $notes[] = 'A sort code has 6 digits.';
                }
                if ($acct !== '' && strlen($acct) !== 8) {
                    $notes[] = 'A UK account number has 8 digits.';
                }
                if ($notes) {
                    flash(implode(' ', $notes) . ' Nothing changed.');
                    break;
                }
                set_setting('pay_paypal', !empty($_POST['pay_paypal']) ? '1' : '0');
                set_setting('paypal_mode', post('paypal_mode') === 'live' ? 'live' : 'sandbox');
                set_setting('paypal_client_id', post('paypal_client_id'));
                if (post('paypal_secret') !== '') {   // blank keeps the one already saved
                    set_setting('paypal_secret', post('paypal_secret'));
                }
                set_setting('pay_bank', !empty($_POST['pay_bank']) ? '1' : '0');
                set_setting('bank_account_name', post('bank_account_name'));
                set_setting('bank_sort_code', $sort === '' ? '' : implode('-', str_split($sort, 2)));
                set_setting('bank_account', $acct);
                set_setting('bank_note', post('bank_note'));
                set_setting('pay_collection', !empty($_POST['pay_collection']) ? '1' : '0');
                $ud = post('unpaid_days');
                set_setting('unpaid_days', ctype_digit($ud) && (int)$ud <= 365 ? (string)(int)$ud : '14');
                $msg = 'Payment settings saved.';
                if (setting('pay_paypal') === '1') {
                    $err = null;
                    $msg .= paypal_token($err) ? ' Connected to PayPal (' . setting('paypal_mode') . ').'
                                               : ' But PayPal isn\'t working yet: ' . $err . '.';
                }
                if (setting('pay_bank') === '1' && !pay_enabled('bank')) {
                    $msg .= ' Bank transfer needs a sort code and account number before it\'s offered.';
                }
                if (setting('pay_collection') === '1' && !can_collect()) {
                    $msg .= ' Pay on collection is only offered when collection is switched on in Postage.';
                }
                flash($msg);
                break;
            case 'email':
                $host = strtolower(trim(post('smtp_host')));
                $port = (int)post('smtp_port');
                $sec = in_array(post('smtp_security'), ['ssl', 'starttls', 'none'], true) ? post('smtp_security') : 'ssl';
                $from = trim(post('mail_from'));
                $notify = trim(post('mail_notify'));
                if ($host !== '' && !preg_match('/^[a-z0-9.-]+$/', $host)) {
                    flash('The mail server should be a name like mail.example.com. Nothing changed.');
                    break;
                }
                if ($port < 1 || $port > 65535) {
                    flash('The port is a number, usually 465 or 587. Nothing changed.');
                    break;
                }
                if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL) || $notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
                    flash('One of the email addresses doesn\'t look right. Nothing changed.');
                    break;
                }
                set_setting('smtp_host', $host);
                set_setting('smtp_port', (string)$port);
                set_setting('smtp_security', $sec);
                set_setting('smtp_user', trim(post('smtp_user')));
                if (str_in($_POST['smtp_pass'] ?? '') !== '') {   // blank keeps the one already saved
                    set_setting('smtp_pass', (string)$_POST['smtp_pass']);
                }
                set_setting('mail_from', $from);
                set_setting('mail_from_name', mail_clean(post('mail_from_name')));
                set_setting('mail_notify', $notify);
                if (!mail_ready()) {
                    flash('Saved. Emails are off until there\'s a mail server and a from address.');
                    break;
                }
                try {
                    smtp_connect()->close();
                    $msg = 'Saved, and the shop signed in to ' . $host . ' successfully. Send yourself a test email below.';
                } catch (SmtpError $e) {
                    $msg = 'Saved, but the shop couldn\'t send through ' . $host . ': ' . $e->getMessage() . '.';
                }
                flash($msg);
                break;
            case 'email_test':
                $to = trim(post('test_to'));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !mail_ready()) {
                    flash(mail_ready() ? 'Enter an address to send the test to.' : 'Set up the mail server first.');
                    break;
                }
                [$foot] = mail_footer();
                $html = mail_layout('Your shop can send email', ['This is a test from your shop. Order confirmations and dispatch emails will look like this and come from the same address.'],
                                    '', abs_shop_url(), 'Visit the shop', $foot);
                db()->prepare("INSERT INTO emails (order_id, kind, to_addr, subject, body_text, body_html, status, created) VALUES (NULL, 'test', ?, ?, ?, ?, 'queued', ?)")
                    ->execute([$to, 'Test email from ' . mail_from_name(), "Your shop can send email.\n\nThis is a test from your shop.\n", $html, now()]);
                $id = (int)db()->lastInsertId();
                $sent = mail_flush($id);
                $err = db()->query('SELECT error FROM emails WHERE id = ' . $id)->fetchColumn();
                flash($sent ? 'Test email sent to ' . $to . '. If it isn\'t in the inbox in a minute or two, check the spam folder.' : 'The test didn\'t go: ' . $err . '.');
                break;
            case 'accounts':
                set_setting('accounts_on', !empty($_POST['accounts_on']) ? '1' : '0');
                flash(accounts_on() ? 'Customer accounts are on: the shop shows a Sign in link.' : 'Customer accounts are off. Existing accounts are kept but can\'t sign in.');
                break;
            case 'legal':
                foreach (['terms_url', 'returns_url', 'privacy_url'] as $k) {
                    $u = post($k);
                    set_setting($k, preg_match('#^(https?://|/)#i', $u) ? $u : '');
                }
                flash('Links saved.');
                break;
            case 'stock':
                $lvl = trim(post('low_stock_level'));
                if (!ctype_digit($lvl) || (int)$lvl > 9999) {
                    flash('The low-stock level is a whole number, like 3. Nothing changed.');
                    break;
                }
                set_setting('low_stock_on', !empty($_POST['low_stock_on']) ? '1' : '0');
                set_setting('low_stock_level', (string)(int)$lvl);
                flash(setting('low_stock_on') === '1'
                    ? 'Saved. You\'ll get an email when an order takes anything down to ' . (int)$lvl . ' or fewer.'
                      . (mail_ready() ? '' : ' Set up email in the Email tab first, or the emails can\'t go.')
                    : 'Low-stock emails are off.');
                break;
            case 'demo':
                set_setting('demo_mode', !empty($_POST['demo_mode']) ? '1' : '0');
                flash(shop_demo() ? 'Demo mode is on: visitors see a banner and can\'t check out or create accounts. You still can, while signed in here.'
                                  : 'Demo mode is off. The shop is live: customers can check out, and search engines can list it.');
                break;
            case 'site_url':
                $url = strtolower(rtrim(trim(post('site_url')), '/'));
                if (!preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $url)) {
                    flash('The web address should be like https://www.example.com, with nothing after the name. Nothing changed.');
                } else {
                    set_setting('site_url', $url);
                    @unlink(SHELL_FILE);
                    flash('Web address saved. Links in emails now use ' . $url . '.');
                }
                break;
            case 'proxy':
                $mode = in_array(post('proxy_mode'), ['none', 'cloudflare', 'other'], true) ? post('proxy_mode') : 'none';
                $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', post('proxy_ips')))));
                $bad = array_filter($ips, fn($r) => !preg_match('#^[0-9a-f.:]+(/\d{1,3})?$#i', $r) || @inet_pton(explode('/', $r)[0]) === false);
                if ($mode === 'other' && (!$ips || $bad)) {
                    flash('List your proxy\'s addresses, like 10.0.0.5 or 10.0.0.0/24, one per line. Nothing changed.');
                    break;
                }
                set_setting('proxy_mode', $mode);
                set_setting('proxy_ips', implode("\n", $ips));
                flash($mode === 'none' ? 'Saved: no proxy.' : 'Saved. The shop now sees visitors\' own addresses when they come through ' . ($mode === 'cloudflare' ? 'Cloudflare' : 'your proxy') . '.');
                break;
            case 'version':
                set_setting('show_version', !empty($_POST['show_version']) ? '1' : '0');
                flash(setting('show_version') === '1' ? 'The shop\'s version now shows in the footer.' : 'The version is hidden from the footer.');
                break;
            case 'refresh':
                @unlink(SHELL_FILE);
                flash('The shop will pick up your site\'s latest menu and theme on its next page.');
                break;
            case 'password':
                if (login_blocked()) {
                    flash('Too many wrong attempts. Wait 15 minutes and try again.');
                } elseif (!password_verify(str_in($_POST['current'] ?? ''), $u['password'])) {
                    login_failed();
                    flash('Your current password wasn\'t right, so nothing changed.');
                } elseif (strlen(str_in($_POST['new'] ?? '')) < 10) {
                    flash('The new password needs at least 10 characters.');
                } else {
                    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([password_hash((string)$_POST['new'], PASSWORD_DEFAULT), $u['id']]);
                    login_complete(admin_bump_sessions((int)$u['id']), true);
                    flash('Password changed. Other devices have been signed out.');
                }
                break;
            case 'signout_all':
                login_complete(admin_bump_sessions((int)$u['id']), true);
                flash('Every other device has been signed out.');
                break;
            case '2fa_start':
                $_SESSION['shop_2fa_setup'] = base32_encode(random_bytes(20));
                break;
            case '2fa_confirm':
                $secret = (string)($_SESSION['shop_2fa_setup'] ?? '');
                $step = $secret !== '' ? totp_verify($secret, post('code'), 0) : 0;
                if ($step) {
                    $codes = recovery_generate();
                    db()->prepare('UPDATE users SET totp_secret = ?, totp_last = ?, recovery = ? WHERE id = ?')
                        ->execute([$secret, $step, json_encode(array_map(fn($c) => hash('sha256', $c), $codes)), $u['id']]);
                    unset($_SESSION['shop_2fa_setup']);
                    login_complete(admin_bump_sessions((int)$u['id']), true);   // older sessions didn't pass two-factor
                    $_SESSION['shop_2fa_codes'] = $codes;
                    flash('Two-factor is on.');
                } else {
                    flash('That code didn\'t match. Check the key was typed exactly, then try the current code.');
                }
                break;
            case '2fa_off':
                if (login_blocked()) {
                    flash('Too many wrong attempts. Wait 15 minutes and try again.');
                } elseif (password_verify(str_in($_POST['password'] ?? ''), $u['password'])) {
                    db()->prepare("UPDATE users SET totp_secret = '', totp_last = 0, recovery = '' WHERE id = ?")->execute([$u['id']]);
                    login_complete(admin_bump_sessions((int)$u['id']), true);
                    flash('Two-factor is off.');
                } else {
                    login_failed();
                    flash('Your password wasn\'t right, so two-factor is still on.');
                }
                break;
            default:
                if (($msg = delivery_admin_post(post('form'))) !== null) {
                    flash($msg);
                    redirect(settings_url('delivery', in_array(post('form'), ['zone', 'zone_delete', 'zone_presets'], true) ? 'zones'
                        : (post('form') === 'deliver_pricing' ? 'pricing' : 'methods')));
                }
        }
        redirect(settings_url(SETTINGS_FORMS[post('form')] ?? $tab));
    }
    $u = user_by_id((int)$u['id']);
    $s = array_fill_keys(array_keys(SETTINGS_TABS), '');
    $s['general'] .= '<h2 id="shop">Shop</h2>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="shop">
        <label>Shop heading <span class="hint">(the title on the main shop page)</span>
          <input name="shop_title" value="' . e(setting('shop_title', 'Shop')) . '" maxlength="80"></label>
        <label>Products per page <span class="hint">(6 to 96; the shop and each category show this many at a time)</span>
          <input type="number" name="per_page" min="6" max="96" inputmode="numeric" value="' . per_page() . '" style="max-width:7rem;display:block"></label>
        <fieldset style="border:0;padding:0;margin:1rem 0"><legend style="font-weight:600;padding:0">Categories menu</legend>
          <label class="check"><input type="radio" name="cat_menu" value="side"' . (side_menu_on() ? ' checked' : '') . '> Down the side, in a card of its own <span class="hint">(on wide screens; along the top on phones and tablets)</span></label>
          <label class="check"><input type="radio" name="cat_menu" value="top"' . (side_menu_on() ? '' : ' checked') . '> Along the top</label></fieldset>
        <button>Save</button></form>';
    $s['delivery'] .= '<h2 id="postage">Postage and collection</h2>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="postage">
        <label class="check"><input type="checkbox" name="deliver" value="1"' . (can_deliver() ? ' checked' : '') . '> Offer ' . e(delivery_methods(false)[0]['name']) . '</label>
' . ((setting('postage_pricing') ?: 'flat') === 'flat' ? '
        <label>Delivery price <div class="price"><span>£</span><input name="postage_price" inputmode="decimal" placeholder="3.95" value="'
        . e(setting('postage_price') === '' ? '' : price_input((int)setting('postage_price'))) . '"></div></label>
        <label>Free delivery on orders over <span class="hint">(optional)</span><div class="price"><span>£</span><input name="postage_free_over" inputmode="decimal" placeholder="40" value="'
        . e(setting('postage_free_over') === '' ? '' : price_input((int)setting('postage_free_over'))) . '"></div></label>
        ' : '<p class="muted">Priced ' . (setting('postage_pricing') === 'weight' ? 'by weight' : 'by order value') . ': ' . e(method_price_text(delivery_methods(false)[0]))
            . '. <a href="#pricing">Change</a></p>') . '
        <label class="check"><input type="checkbox" name="collect" value="1"' . (can_collect() ? ' checked' : '') . '> Offer free collection</label>
        <label>Collection details <span class="hint">(where and when, shown to customers who choose collection)</span>
          <textarea name="postage_collect_note" maxlength="500" style="min-height:5rem">' . e(setting('postage_collect_note')) . '</textarea></label>
        <button>Save postage</button></form>' . delivery_admin_html();
    $s['payments'] .= '<h2 id="payments">Payments</h2>
      <p class="muted">Switch on the ways customers can pay. Each is offered at checkout once it\'s set up.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="payments">
        <label class="check"><input type="checkbox" name="pay_paypal" value="1"' . (setting('pay_paypal') === '1' ? ' checked' : '') . '> PayPal</label>
        <p class="muted">Customers pay on PayPal\'s site, by PayPal account or card. You need the Client ID and Secret of a
        PayPal app from developer.paypal.com. Use Sandbox to test with pretend money first.</p>
        <label>Mode <select name="paypal_mode"><option value="sandbox"' . (setting('paypal_mode', 'sandbox') !== 'live' ? ' selected' : '')
        . '>Sandbox (testing, no real money)</option><option value="live"' . (setting('paypal_mode') === 'live' ? ' selected' : '')
        . '>Live (real payments)</option></select></label>
        <label>Client ID <input name="paypal_client_id" value="' . e(setting('paypal_client_id')) . '" autocomplete="off" autocapitalize="none"></label>
        <label>Secret <span class="hint">(' . (setting('paypal_secret') !== '' ? 'saved; leave empty to keep it' : 'not set') . ')</span>
          <input type="password" name="paypal_secret" autocomplete="new-password"></label>
        <label class="check" style="margin-top:1.6rem"><input type="checkbox" name="pay_bank" value="1"' . (setting('pay_bank') === '1' ? ' checked' : '') . '> Bank transfer</label>
        <p class="muted">The order is reserved and the customer sees these details, with the order number as the reference.
        Mark it paid when the money arrives.</p>
        <label>Account name <input name="bank_account_name" value="' . e(setting('bank_account_name')) . '" maxlength="80"></label>
        <div class="row"><label>Sort code <input name="bank_sort_code" value="' . e(setting('bank_sort_code')) . '" inputmode="numeric" placeholder="12-34-56"></label>
        <label>Account number <input name="bank_account" value="' . e(setting('bank_account')) . '" inputmode="numeric" placeholder="12345678"></label></div>
        <label>Note for customers <span class="hint">(optional, e.g. "Please pay within 3 days")</span>
          <textarea name="bank_note" maxlength="300" style="min-height:4rem">' . e(setting('bank_note')) . '</textarea></label>
        <label class="check" style="margin-top:1.6rem"><input type="checkbox" name="pay_collection" value="1"' . (setting('pay_collection') === '1' ? ' checked' : '') . '> Pay on collection</label>
        <p class="muted">Only offered to customers who choose collection.</p>
        <label>Cancel unpaid bank transfers after <span class="hint">(days; customers are told the date when they order, the stock goes back on sale, and you can restore an order if money arrives late; 0 = never)</span>
          <input type="number" name="unpaid_days" min="0" max="365" inputmode="numeric" value="' . e(setting('unpaid_days', '14')) . '" style="max-width:7rem;display:block"></label>
        <button>Save payments</button></form>';
    $s['email'] .= '<h2 id="email">Email</h2>
      <p class="muted">The shop emails customers when they order, when you mark a bank transfer paid, when you send their order,
      and if you cancel or refund it. It emails you about each new order. Use the details from your email provider or host.</p>' . ((($f = mail_failed_count()) > 0) ? '<p class="flash">' . $f . ' email' . ($f === 1 ? '' : 's') . ' couldn\'t be sent. Check the details below, then use Try now on the order.</p>' : '') . '
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="email">
        <div class="row"><label>Mail server <input name="smtp_host" value="' . e(setting('smtp_host')) . '" placeholder="mail.example.com" autocapitalize="none"></label>
        <label>Port <input name="smtp_port" value="' . e(setting('smtp_port', '465')) . '" inputmode="numeric" style="max-width:7rem;display:block"></label></div>
        <label>Security <select name="smtp_security">'
        . '<option value="ssl"' . (setting('smtp_security', 'ssl') === 'ssl' ? ' selected' : '') . '>SSL/TLS (usually port 465)</option>'
        . '<option value="starttls"' . (setting('smtp_security') === 'starttls' ? ' selected' : '') . '>STARTTLS (usually port 587)</option>'
        . '<option value="none"' . (setting('smtp_security') === 'none' ? ' selected' : '') . '>None (only for a mail server on this machine)</option></select></label>
        <label>Username <input name="smtp_user" value="' . e(setting('smtp_user')) . '" autocomplete="off" autocapitalize="none"></label>
        <label>Password <span class="hint">(' . (setting('smtp_pass') !== '' ? 'saved; leave empty to keep it' : 'not set') . ')</span>
          <input type="password" name="smtp_pass" autocomplete="new-password"></label>
        <div class="row"><label>From address <input type="email" name="mail_from" value="' . e(setting('mail_from')) . '" placeholder="shop@example.com"></label>
        <label>From name <span class="hint">(optional)</span><input name="mail_from_name" value="' . e(setting('mail_from_name')) . '" placeholder="' . e(mail_from_name()) . '" maxlength="80"></label></div>
        <label>Send new-order emails to <span class="hint">(optional; otherwise your business email)</span>
          <input type="email" name="mail_notify" value="' . e(setting('mail_notify')) . '" placeholder="' . e(setting('business_email') ?: setting('mail_from')) . '"></label>
        <p class="muted">Customers who reply to an email reach your business email' . (setting('business_email') !== '' ? ' (' . e(setting('business_email')) . ')' : '') . '.</p>
        <button>Save email</button></form>'
        . (mail_ready() ? '<form method="post" style="margin-top:1rem">' . csrf_field() . '<input type="hidden" name="form" value="email_test">
        <div class="row"><label>Send a test email to <input type="email" name="test_to" value="' . e(mail_shop_address()) . '"></label></div>
        <button class="quiet">Send test email</button></form>' : '') . '';
    $s['general'] .= '<h2 id="search">Search</h2>
      <p class="muted">The shop\'s search box appears once it has ' . SEARCH_BOX_FROM . ' or more products. It finds names, SKUs, categories and descriptions,
      and the start of a word is enough ("wish" finds "wishbone"). If customers use other words for the same thing, list them here, one group a line.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="search">
        <label>Words that mean the same <span class="hint">(e.g. <code>alloy = aluminium = alu</code>)</span>
          <textarea name="search_synonyms" style="min-height:6rem" placeholder="alloy = aluminium = alu&#10;fr = front&#10;diff = differential">' . e(setting('search_synonyms')) . '</textarea></label>
        <button>Save</button></form>';
    $s['general'] .= '<h2 id="accounts">Customer accounts</h2>
      <p class="muted">Customers can create an account to save their addresses and see their orders. Checking out as a guest always works too.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="accounts">
        <label class="check"><input type="checkbox" name="accounts_on" value="1"' . (accounts_on() ? ' checked' : '') . '> Let customers create accounts and sign in</label>
        ' . (accounts_on() && !mail_ready() ? '<p class="muted">Set up email too (<a href="' . e(settings_url('email')) . '">Email tab</a>), so customers can confirm their address and reset a forgotten password.</p>' : '') . '
        <button>Save</button></form>';
    $s['general'] .= '<h2 id="stock">Low stock</h2>
      <p class="muted">For products where you count stock. When an order takes something down to this level or below,
      the shop emails you at the new-order address in the <a href="' . e(settings_url('email')) . '">Email tab</a>.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="stock">
        <label class="check"><input type="checkbox" name="low_stock_on" value="1"' . (setting('low_stock_on', '1') === '1' ? ' checked' : '') . '> Email me when stock runs low</label>
        <label>Low means <span class="hint">(this many left, or fewer)</span>
          <input type="number" name="low_stock_level" min="0" max="9999" inputmode="numeric" value="' . e(setting('low_stock_level', '3')) . '" style="max-width:7rem;display:block"></label>
        <button>Save</button></form>';
    $s['business'] .= '<h2 id="business">Your business</h2>
      <p class="muted">Shown at checkout and on order pages. UK law expects online sellers to give their name, address and email.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="business">
        <label>Business name <input name="business_name" value="' . e(setting('business_name')) . '" maxlength="120"></label>
        <label>Email for customers <input type="email" name="business_email" value="' . e(setting('business_email')) . '" maxlength="200"></label>
        <label>Address <textarea name="business_address" maxlength="300" style="min-height:4rem">' . e(setting('business_address')) . '</textarea></label>
        <button>Save business details</button></form>';
    $s['advanced'] .= '<h2 id="site">Web address</h2>
      <p class="muted">The address used in links in emails (order pages, password resets) and to fetch your site\'s look. It\'s taken from your
      own visits here, so it only needs changing if the site moves, for example to https or to a new domain.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="site_url">
        <label>Site address <input type="url" name="site_url" value="' . e(setting('site_url')) . '" placeholder="https://www.example.com" autocapitalize="none"></label>
        <button>Save address</button></form>';
    $s['advanced'] .= '<h2 id="proxy">Proxy or CDN</h2>
      <p class="muted">Only if your site sits behind Cloudflare or another proxy. The shop limits how often each visitor can try passwords
      or place orders; behind a proxy every visitor looks the same unless the shop knows to trust the proxy\'s note of their real address.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="proxy">
        <label>Your site is reached through <select name="proxy_mode">'
        . '<option value="none"' . (setting('proxy_mode', 'none') === 'none' ? ' selected' : '') . '>No proxy (most sites)</option>'
        . '<option value="cloudflare"' . (setting('proxy_mode') === 'cloudflare' ? ' selected' : '') . '>Cloudflare</option>'
        . '<option value="other"' . (setting('proxy_mode') === 'other' ? ' selected' : '') . '>Another proxy or load balancer</option></select></label>
        <label>Its addresses <span class="hint">(only for "another proxy": one per line, ranges like 10.0.0.0/24 are fine)</span>
          <textarea name="proxy_ips" style="min-height:4rem" autocapitalize="none">' . e(setting('proxy_ips')) . '</textarea></label>
        <p class="muted">The shop sees you as ' . e(real_ip()) . ' (the server sees ' . e((string)($_SERVER['REMOTE_ADDR'] ?? '')) . ').</p>
        <button>Save</button></form>';
    $s['business'] .= '<h2 id="legal">Terms, returns and privacy</h2>
      <p class="muted">Links to your pages for these (usually Wren pages, like /terms). When set, customers tick to say they\'ve read them before paying.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="legal">
        <label>Terms of sale <input name="terms_url" value="' . e(setting('terms_url')) . '" placeholder="/terms-of-sale" autocapitalize="none"></label>
        <label>Delivery and returns <input name="returns_url" value="' . e(setting('returns_url')) . '" placeholder="/delivery-and-returns" autocapitalize="none"></label>
        <label>Privacy <input name="privacy_url" value="' . e(setting('privacy_url')) . '" placeholder="/privacy-policy" autocapitalize="none"></label>
        <button>Save links</button></form>';
    $s['business'] .= '<h2>VAT</h2>
      <p class="muted">Prices you type in are what customers pay. If you\'re VAT-registered, the basket, emails and packing slips
      show how much of that is VAT (20%) and your VAT number.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="vat">
        <label class="check"><input type="checkbox" name="vat_registered" value="1"' . (setting('vat_registered') === '1' ? ' checked' : '') . '> We are VAT-registered</label>
        <label>VAT number <input name="vat_number" value="' . e(setting('vat_number')) . '" placeholder="GB123456789" autocapitalize="characters"></label>
        <button>Save VAT</button></form>';
    $s['advanced'] .= '<h2>Look of the shop</h2>
      <p class="muted">The shop copies your site\'s menu and theme automatically every 10 minutes.
      Changed them just now? Refresh to see it straight away.</p>'
      . (($sn = setting('shell_note')) !== '' && !is_file(SHELL_FILE) ? '<p class="flash">The shop is using a plain copy of your theme for now. ' . e($sn) . '</p>' : '') . '
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="refresh">
        <button class="quiet">Refresh look</button></form>
      <form method="post" style="margin-top:1rem">' . csrf_field() . '<input type="hidden" name="form" value="version">
        <label class="check"><input type="checkbox" name="show_version" value="1"' . (setting('show_version', '1') === '1' ? ' checked' : '') . '> Show the Wren Shop version in the footer, after Wren\'s</label>
        <button class="quiet">Save</button></form>';
    $s['advanced'] .= licence_settings_html() . '';
    $s['general'] .= '<h2 id="demo">Demo mode</h2>
      <p class="muted">For showing the shop off rather than selling. Every page has a banner saying it\'s a demo, search engines are asked
      not to list it, and visitors can\'t check out or create accounts. While you\'re signed in here you still can, so you can test.
      Make sure this is off before a shop goes live.</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="demo">
        <label class="check"><input type="checkbox" name="demo_mode" value="1"' . (shop_demo() ? ' checked' : '') . '> This shop is a demo</label>
        <button class="quiet" style="margin-top:1rem">Save</button></form>';
    $s['account'] .= '<h2 id="twofactor">Two-factor sign-in</h2>' . twofactor_panel($u) . '

      <h2>Password</h2>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="password">
        <label>Current password <input type="password" name="current" autocomplete="current-password" required></label>
        <label>New password <span class="hint">(10+ characters)</span><input type="password" name="new" autocomplete="new-password" required></label>
        <button>Change password</button></form>

      <h2>Signed-in devices</h2>
      <p class="muted">Lost a phone, or signed in somewhere you shouldn\'t have stayed signed in?</p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="signout_all">
        <button class="quiet">Sign out all other devices</button></form>
      ';
    $s['advanced'] .= '<p class="muted" style="margin-top:2.5rem">Wren Shop ' . SHOP_VERSION . (shop_demo() ? ' (demo mode)' : '') . '</p>';
    // A section named like its tab doesn't need its own heading under the page's.
    $body = preg_replace('#^<h2[^>]*>' . preg_quote(e(SETTINGS_TABS[$tab]), '#') . '</h2>\s*#', '', $s[$tab]);
    admin_shell('Settings: ' . SETTINGS_TABS[$tab], '<h1>' . e(SETTINGS_TABS[$tab]) . '</h1>' . $body);
}

function twofactor_panel(array $u): string
{
    if (!empty($_SESSION['shop_2fa_codes'])) {
        $codes = $_SESSION['shop_2fa_codes'];
        unset($_SESSION['shop_2fa_codes']);
        return '<p class="flash">Save these recovery codes somewhere safe. Each one works once if you lose your
                phone, and they won\'t be shown again.</p>
                <div class="codes">' . implode('', array_map(fn($c) => '<span>' . e($c) . '</span>', $codes)) . '</div>';
    }
    if ($u['totp_secret'] !== '') {
        $left = count(json_decode($u['recovery'] ?: '[]', true) ?: []);
        return '<p>Two-factor is <b>on</b>: after your password you\'re asked for a code from your phone.
                ' . $left . ' unused recovery codes left.</p>
          <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="2fa_off">
            <label>Your password, to turn it off <input type="password" name="password" autocomplete="current-password" required></label>
            <button class="danger">Turn off two-factor</button></form>';
    }
    if (empty($_SESSION['shop_2fa_setup'])) {
        return '<p class="muted">Recommended. Signing in will need your password and a six-digit code from an
                authenticator app on your phone.</p>
          <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="2fa_start">
            <button class="quiet">Set up two-factor</button></form>';
    }
    $secret = (string)$_SESSION['shop_2fa_setup'];
    return '<p>In your authenticator app (Google Authenticator, Authy, 1Password or similar) add an account
            <b>by entering a key</b>, and type this one:</p>
      <div class="codes"><span>' . e(trim(chunk_split($secret, 4, ' '))) . '</span></div>
      <p class="muted">Account: ' . e($u['username']) . ' · time-based. Some apps accept this address instead:<br>
      <code style="font-size:.75rem;word-break:break-all">' . e(totp_uri($secret, $u['username'])) . '</code></p>
      <form method="post">' . csrf_field() . '<input type="hidden" name="form" value="2fa_confirm">
        <label>Then type the six-digit code it shows <input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
        <button>Turn on two-factor</button></form>';
}

/* ================================================================= orders */
/* An order is placed (and its stock held) before any money moves:
     PayPal      -> "pending" until PayPal confirms the capture, then "paid"
     bank        -> "awaiting_payment" until the owner marks it paid
     collection  -> "pay_on_collection" until the owner marks it paid
   Cancelling an unpaid order puts its stock back. */

const PENDING_MINUTES = 30;   // an unfinished PayPal payment holds stock this long

function unpaid_days(): int
{
    return (int)setting('unpaid_days', '14');
}

function pay_by_date(array $o): string
{
    return gmdate('j F', strtotime($o['created'] . ' UTC') + unpaid_days() * 86400);
}

function status_label(string $s): string
{
    return [
        'pending' => 'Waiting for PayPal', 'awaiting_payment' => 'Awaiting bank transfer',
        'pay_on_collection' => 'Pay on collection', 'paid' => 'Paid', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded',
    ][$s] ?? $s;
}

function method_label(string $m): string
{
    return ['paypal' => 'PayPal', 'bank' => 'Bank transfer', 'collection' => 'Pay on collection'][$m] ?? $m;
}

function pay_enabled(string $m): bool
{
    return match ($m) {
        'paypal'     => setting('pay_paypal') === '1' && setting('paypal_client_id') !== '' && setting('paypal_secret') !== '',
        'bank'       => setting('pay_bank') === '1' && setting('bank_sort_code') !== '' && setting('bank_account') !== '',
        'collection' => setting('pay_collection') === '1' && can_collect(),
        default      => false,
    };
}

/* Is a way to pay set up (its details filled in), whether or not it's offered to everyone? */
function pay_ready(string $m): bool
{
    return match ($m) {
        'paypal'     => setting('paypal_client_id') !== '' && setting('paypal_secret') !== '',
        'bank'       => setting('bank_sort_code') !== '' && setting('bank_account') !== '',
        'collection' => can_collect(),
        default      => false,
    };
}

/* Can this customer pay this way? A group with its own list of ways to pay uses that
   list (any that are set up); everyone else gets what the shop offers. */
function pay_allowed(string $m): bool
{
    $g = customer_group();
    if ($g && ($g['pay_methods'] ?? '') !== '') {
        return in_array($m, explode(',', $g['pay_methods']), true) && pay_ready($m);
    }
    return pay_enabled($m);
}

function order_by(string $col, string|int $val): ?array
{
    $st = db()->prepare("SELECT * FROM orders WHERE $col = ?");
    $st->execute([$val]);
    return $st->fetch() ?: null;
}

function order_items(int $oid): array
{
    $st = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $st->execute([$oid]);
    return $st->fetchAll();
}

function order_note(int $oid, string $note): void
{
    $line = gmdate('j M H:i') . ' ' . $note;
    db()->prepare("UPDATE orders SET admin_note = CASE WHEN admin_note = '' THEN ? ELSE admin_note || char(10) || ? END,
                   updated = ? WHERE id = ?")->execute([$line, $line, now(), $oid]);
}

/* Put an order's items back into stock (only where stock is counted). */
function order_restock(int $oid): void
{
    foreach (order_items($oid) as $it) {
        if ($it['variant_id'] !== null) {
            db()->prepare('UPDATE variants SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL')->execute([$it['qty'], $it['variant_id']]);
        } else {
            db()->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL')->execute([$it['qty'], $it['product_id']]);
        }
    }
}

/* Cancel an unpaid order and release its stock. Safe to call twice. */
function order_cancel(int $oid, string $why): bool
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $st = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $st->execute([$oid]);
    $done = in_array($st->fetchColumn(), ['pending', 'awaiting_payment', 'pay_on_collection'], true);
    if ($done) {
        $pdo->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = ?, updated = ? WHERE id = ?")->execute([now(), now(), $oid]);
        order_restock($oid);
        order_note($oid, 'Cancelled: ' . $why);
    }
    $pdo->exec('COMMIT');
    return $done;
}

/* Every change of state only happens if the order is still in the state it was
   in, so a double-click or two requests at once can't do anything twice. */
function order_mark_paid(int $oid, string $captureId, string $how): bool
{
    $st = db()->prepare("UPDATE orders SET status = 'paid', paid_at = ?, updated = ?,
                   paypal_capture_id = CASE WHEN ? != '' THEN ? ELSE paypal_capture_id END
                   WHERE id = ? AND status IN ('pending', 'awaiting_payment', 'pay_on_collection')");
    $st->execute([now(), now(), $captureId, $captureId, $oid]);
    if ($st->rowCount() !== 1) {
        return false;
    }
    order_note($oid, 'Paid: ' . $how);
    digital_on_paid($oid);
    $o = order_by('id', $oid);
    if ($o['payment_method'] === 'paypal') {
        mail_order_event($oid, 'paid');
        mail_order_event($oid, 'new_order');
        low_stock_check($oid);
    } elseif ($o['payment_method'] === 'bank') {
        mail_order_event($oid, 'payment_received');
    }
    return true;
}

/* Paid -> refunded, once. Returns false if something else got there first. */
function order_mark_refunded(int $oid, bool $restock, string $how): bool
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $st = $pdo->prepare("UPDATE orders SET status = 'refunded', updated = ? WHERE id = ? AND status = 'paid'");
    $st->execute([now(), $oid]);
    $done = $st->rowCount() === 1;
    if ($done) {
        if ($restock) {
            order_restock($oid);
        }
        order_note($oid, $how . ($restock ? ', stock put back' : ''));
        digital_on_refund($oid);
    }
    $pdo->exec('COMMIT');
    if ($done) {
        mail_order_event($oid, 'refunded');
    }
    return $done;
}

/* What a failed PayPal refund means, in plain English. */
function refund_error_text(string $code): string
{
    $why = match (true) {
        str_contains($code, 'INSUFFICIENT_FUNDS') => 'Your PayPal account doesn\'t have enough balance to cover it. Add funds in PayPal and try again, or pay the customer back another way and use Mark as refunded.',
        str_contains($code, 'TRANSACTION_REFUSED'), str_contains($code, 'REFUND_NOT_ALLOWED') => 'PayPal won\'t allow a refund on this payment. Check the payment in your PayPal account.',
        str_contains($code, 'TIME_LIMIT') || str_contains($code, 'EXPIRED') => 'The payment is too old to refund through PayPal (the limit is 180 days). Pay the customer back another way and use Mark as refunded.',
        str_contains($code, 'ALREADY_REFUNDED') || str_contains($code, 'CAPTURE_FULLY_REFUNDED') => 'PayPal says this payment has already been refunded. Check your PayPal account, then use Mark as refunded.',
        str_contains($code, 'AUTHENTICATION') || str_contains($code, 'PERMISSION') || str_contains($code, 'client ID') => 'The shop couldn\'t sign in to PayPal. Check the Client ID and Secret in Settings.',
        str_contains($code, 'couldn\'t connect') => 'The shop couldn\'t reach PayPal. Try again in a moment.',
        default => '',
    };
    return 'PayPal didn\'t make the refund. ' . ($why !== '' ? $why . ' ' : '') . 'Nothing changed.' . (preg_match('/^[A-Z_]+$/', $code) ? ' (PayPal\'s code: ' . $code . ')' : '');
}

/* ------------------------------------------------------------------ PayPal */

function paypal_base(): string
{
    // For the test suite only: ignored unless the server is started with WRENSHOP_TESTING=1.
    $override = getenv('WRENSHOP_TESTING') === '1' ? setting('paypal_api_base') : '';
    if ($override !== '') {
        return rtrim($override, '/');
    }
    return setting('paypal_mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

/* One HTTP call to PayPal. Returns [status code, decoded body]; status 0 = couldn't connect. */
function paypal_http(string $method, string $path, ?array $body, array $headers): array
{
    $ch = curl_init(paypal_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'WrenShop/' . SHOP_VERSION,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$raw === false ? 0 : $code, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}

function paypal_token(?string &$err = null): ?string
{
    static $token = null;
    if ($token) {
        return $token;
    }
    $ch = curl_init(paypal_base() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => setting('paypal_client_id') . ':' . setting('paypal_secret'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    if ($code === 200 && !empty($j['access_token'])) {
        return $token = (string)$j['access_token'];
    }
    $err = $raw === false ? 'couldn\'t connect to PayPal' : ($code === 401 ? 'PayPal didn\'t accept the client ID and secret' : 'PayPal answered ' . $code);
    return null;
}

function paypal_api(string $method, string $path, ?array $body = null, string $requestId = '', ?string &$err = null): ?array
{
    $t = paypal_token($err);
    if (!$t) {
        return null;
    }
    $h = ['Authorization: Bearer ' . $t, 'Content-Type: application/json', 'Accept: application/json', 'Prefer: return=representation'];
    if ($requestId !== '') {
        $h[] = 'PayPal-Request-Id: ' . $requestId;   // makes a repeated call safe: PayPal does it once
    }
    [$code, $j] = paypal_http($method, $path, $body, $h);
    if ($code >= 200 && $code < 300) {
        return $j;
    }
    $err = $code === 0 ? 'couldn\'t connect to PayPal' : (string)($j['details'][0]['issue'] ?? $j['name'] ?? ('PayPal answered ' . $code));
    return null;
}

function paypal_create(array $o, ?string &$err): ?array
{
    $items = [];
    foreach (order_items((int)$o['id']) as $it) {
        $items[] = [
            'name'        => mb_substr($it['name'] . ($it['option_label'] !== '' ? ' (' . $it['option_label'] . ')' : ''), 0, 127),
            'unit_amount' => ['currency_code' => 'GBP', 'value' => price_input((int)$it['unit_pence'])],
            'quantity'    => (string)$it['qty'],
            'category'    => 'PHYSICAL_GOODS',
        ];
    }
    $site = setting('business_name') ?: setting('last_site_title', 'Shop');
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'custom_id'   => (string)$o['number'],
            'invoice_id'  => 'WS-' . $o['number'] . '-' . substr($o['token'], 0, 6),
            'description' => 'Order #' . $o['number'] . ' from ' . mb_substr($site, 0, 80),
            'amount'      => [
                'currency_code' => 'GBP',
                'value'         => price_input((int)$o['total_pence']),
                'breakdown'     => [
                    'item_total' => ['currency_code' => 'GBP', 'value' => price_input((int)$o['subtotal_pence'])],
                    'shipping'   => ['currency_code' => 'GBP', 'value' => price_input((int)$o['postage_pence'])],
                ] + ((int)$o['discount_pence'] + (int)$o['group_pence'] > 0
                     ? ['discount' => ['currency_code' => 'GBP', 'value' => price_input((int)$o['discount_pence'] + (int)$o['group_pence'])]] : []),
            ],
            'items' => $items,
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'brand_name'          => mb_substr($site, 0, 127),
            'shipping_preference' => 'NO_SHIPPING',   // the shop already has the address
            'user_action'         => 'PAY_NOW',
            'landing_page'        => 'NO_PREFERENCE',
            'return_url'          => abs_shop_url('paypal/return'),
            'cancel_url'          => abs_shop_url('paypal/cancel'),
        ]]],
    ];
    $j = paypal_api('POST', '/v2/checkout/orders', $body, 'create-' . $o['token'], $err);
    if (!$j || empty($j['id'])) {
        return null;
    }
    foreach ($j['links'] ?? [] as $l) {
        if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
            return ['id' => (string)$j['id'], 'link' => (string)$l['href']];
        }
    }
    $err = 'PayPal gave no payment page';
    return null;
}

/* Settle an order from PayPal's view of it: "paid" only when a capture has
   completed for exactly this order's amount in pounds. */
function paypal_settle(array $o, array $pp): string
{
    $cap = $pp['purchase_units'][0]['payments']['captures'][0] ?? null;
    if (!$cap) {
        return 'none';
    }
    $amountOk = ($cap['amount']['currency_code'] ?? '') === 'GBP' && ($cap['amount']['value'] ?? '') === price_input((int)$o['total_pence']);
    $status = (string)($cap['status'] ?? '');
    if ($status === 'COMPLETED' && $amountOk) {
        if (!order_mark_paid((int)$o['id'], (string)($cap['id'] ?? ''), 'PayPal capture ' . ($cap['id'] ?? ''))) {
            $now = order_by('id', (int)$o['id']);
            if ($now && $now['status'] !== 'paid' && $now['paypal_capture_id'] === '') {
                // PayPal took the money but the order had been cancelled meanwhile: never lose track of that.
                db()->prepare('UPDATE orders SET paypal_capture_id = ? WHERE id = ?')->execute([(string)($cap['id'] ?? ''), $o['id']]);
                order_note((int)$o['id'], 'PayPal took ' . money((int)$o['total_pence']) . ' (capture ' . ($cap['id'] ?? '') . ') after this order was '
                    . status_label($now['status']) . '. Refund it in PayPal, or contact the customer and send the order.');
                mail_order_event((int)$o['id'], 'paid_after_cancel');
            }
            return $now && $now['status'] === 'paid' ? 'paid' : 'late';
        }
        return 'paid';
    }
    if ($status === 'COMPLETED') {
        if ($o['paypal_capture_id'] === (string)($cap['id'] ?? '')) {
            return 'mismatch';   // already noted
        }
        db()->prepare('UPDATE orders SET paypal_capture_id = ? WHERE id = ?')->execute([(string)($cap['id'] ?? ''), $o['id']]);
        order_note((int)$o['id'], 'PayPal captured ' . ($cap['amount']['value'] ?? '?') . ' ' . ($cap['amount']['currency_code'] ?? '')
                                . ', which doesn\'t match this order. Check PayPal before sending anything.');
        return 'mismatch';
    }
    if ($status === 'PENDING') {
        if ($o['paypal_capture_id'] === (string)($cap['id'] ?? '')) {
            return 'pending';   // already noted
        }
        db()->prepare('UPDATE orders SET paypal_capture_id = ? WHERE id = ?')->execute([(string)($cap['id'] ?? ''), $o['id']]);
        order_note((int)$o['id'], 'PayPal is holding the payment (' . ($cap['status_details']['reason'] ?? 'pending') . ').');
        return 'pending';
    }
    return 'failed';
}

function paypal_capture(array $o, ?string &$err): string
{
    $j = paypal_api('POST', '/v2/checkout/orders/' . rawurlencode($o['paypal_order_id']) . '/capture', [], 'capture-' . $o['token'], $err);
    if (!$j) {
        if ($err === 'ORDER_ALREADY_CAPTURED') {   // e.g. the return page loaded twice
            return paypal_reconcile($o);
        }
        return $err === 'INSTRUMENT_DECLINED' ? 'declined' : 'error';
    }
    return paypal_settle($o, $j);
}

/* Ask PayPal what happened to an unfinished payment, and act on it. */
function paypal_reconcile(array $o): string
{
    $err = null;
    $j = paypal_api('GET', '/v2/checkout/orders/' . rawurlencode($o['paypal_order_id']), null, '', $err);
    if (!$j) {
        return 'error';
    }
    $status = (string)($j['status'] ?? '');
    if ($status === 'COMPLETED') {
        return paypal_settle($o, $j);
    }
    if ($status === 'APPROVED') {
        return paypal_capture($o, $err);
    }
    $old = strtotime($o['created'] . ' UTC') < time() - PENDING_MINUTES * 60;
    if ($status === 'VOIDED' || $old) {
        order_cancel((int)$o['id'], $status === 'VOIDED' ? 'PayPal voided the payment.' : 'PayPal payment not finished within ' . PENDING_MINUTES . ' minutes.');
        return 'cancelled';
    }
    return 'waiting';
}

/* Unfinished PayPal payments older than PENDING_MINUTES are checked with
   PayPal and either completed or cancelled (releasing their stock). Runs at
   most every five minutes, on ordinary page loads. */
function orders_tidy(bool $force = false): void
{
    if (!$force && (int)setting('tidy_at', '0') > time() - 300) {
        return;
    }
    set_setting('tidy_at', (string)time());
    if (mail_ready() && (int)db()->query("SELECT COUNT(*) FROM emails WHERE status IN ('queued', 'failed') AND tries < " . MAIL_TRIES)->fetchColumn()) {
        mail_send_later();   // retry anything that didn't go
    }
    // Unpaid bank-transfer orders hold stock; after the time the customer was told, they lapse.
    // (Pay-on-collection orders are left to the shop.) The shop can still restore one if money arrives late.
    $days = unpaid_days();
    if ($days > 0) {
        $st = db()->prepare("SELECT id FROM orders WHERE status = 'awaiting_payment' AND created < ? AND created >= ?");
        $st->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400), setting('unpaid_since', '2100-01-01')]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            if (order_cancel((int)$oid, 'Not paid within ' . $days . ' days.')) {
                mail_order_event((int)$oid, 'cancelled');
            }
        }
    }
    // Keep the outbox lean: account emails (with their links) go once they're too old to
    // send; order emails keep their subject and status but lose their contents after 90 days.
    db()->prepare("UPDATE emails SET body_text = '', body_html = '' WHERE body_html != '' AND
                   ((kind LIKE 'account%' AND created < ?) OR created < ?)")
        ->execute([gmdate('Y-m-d H:i:s', time() - 2 * 86400), gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
    $st = db()->prepare("SELECT * FROM orders WHERE status = 'pending' AND created < ?");
    $st->execute([gmdate('Y-m-d H:i:s', time() - PENDING_MINUTES * 60)]);
    foreach ($st->fetchAll() as $o) {
        if ($o['paypal_order_id'] === '') {
            order_cancel((int)$o['id'], 'PayPal payment never started.');
        } elseif (setting('paypal_client_id') !== '') {
            paypal_reconcile($o);
        }
    }
}

/* ---------------------------------------------------------------- checkout */

/* Why checkout can't happen right now, or null if it can. */
function checkout_blocked(?array $t = null): ?string
{
    if (shop_demo() && !current_admin()) {
        return 'Checkout isn\'t switched on in the demo. In a live shop this button takes the customer to checkout.';
    }
    if (!pay_allowed('paypal') && !pay_allowed('bank') && !pay_allowed('collection')) {
        return 'This shop isn\'t taking orders online just yet.';
    }
    if (!accounts_on() && basket_digital()) {
        return 'Downloads need a customer account, and accounts are switched off in this shop.';
    }
    if (data_known_exposed()) {   // don't collect anyone's details until the owner fixes it
        return 'This shop isn\'t taking orders online just now. Please try again later.';
    }
    if ($t && ($t['unavailable'] ?? '') !== '') {
        return 'We can\'t send this order: ' . $t['unavailable'] . '. ' . (count($t['methods']) > 1 ? 'Choose another way in your basket, or get in touch with the shop.' : 'Please get in touch with the shop.');
    }
    if ($t && $t['method'] === 'deliver' && $t['postage'] === null) {
        return 'Delivery prices aren\'t set up yet, so this order can\'t be placed online.';
    }
    if ($t && ($short = min_order_short($t)) !== null) {
        $g = customer_group();
        return $g['name'] . ' orders start at ' . money((int)$g['min_order_pence']) . ' of goods. Add ' . money($short) . ' more to check out.';
    }
    if ($t && $t['total'] <= 0) {
        return 'This order comes to ' . money(0) . ', which can\'t be paid online. Please get in touch with the shop.';
    }
    if ($t && $t['method'] === 'deliver' && !pay_allowed('paypal') && !pay_allowed('bank')) {
        return 'Orders for delivery can\'t be paid online yet. Choose collection instead.';
    }
    return null;
}

/* The basket as order lines, exactly as it stands now. */
function basket_lines(): array
{
    $lines = [];
    foreach ($_SESSION['shop_basket'] ?? [] as $key => $qty) {
        $item = basket_item($key);
        if (!$item) {
            continue;
        }
        [$p, $v] = $item;
        $qty = min((int)$qty, qty_max($p, $v));
        if ($qty > 0) {
            // price: what one costs before any group price or code (the sale price if on sale)
            $lines[] = ['key' => (string)$key, 'p' => $p, 'v' => $v, 'qty' => $qty, 'price' => unit_price($p, $v), 'full' => variant_price($p, $v),
                        'backorder' => backorder_qty($p, $v, $qty),
                        'option' => $v ? choice_label($p, $v) : ''];
        }
    }
    return $lines;
}

function lines_changed(array $lines): bool
{
    $now = [];
    foreach ($lines as $l) {
        $now[$l['key']] = $l['qty'];
    }
    $was = array_map('intval', $_SESSION['shop_basket'] ?? []);
    ksort($now);
    ksort($was);
    return array_map('strval', array_keys($now)) !== array_map('strval', array_keys($was)) || array_values($now) !== array_values($was);
}

function uk_postcode(string $s): ?string
{
    $s = strtoupper(preg_replace('/\s+/', '', $s));
    if (!preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $s)) {
        return null;
    }
    return substr($s, 0, -3) . ' ' . substr($s, -3);
}

function summary_table(string $rows, int $sub, ?int $post, string $postLabel, int $total, ?int $vat, bool $vatShown = true,
                       string $code = '', int $disc = 0, string $group = '', int $gp = 0, bool $ex = false): string
{
    // $ex: show the amounts without VAT, with the VAT as its own line (a trade group's view).
    $m = fn(int $x) => money($ex ? ex_vat($x) : $x);
    $out = '<table class="shop-basket shop-summary"><tbody>' . $rows . '</tbody><tfoot>'
         . '<tr class="shop-sub"><th scope="row" colspan="2">Subtotal' . ($ex ? ' ex VAT' : '') . '</th><td class="shop-num">' . $m($sub) . '</td></tr>'
         . ($code !== '' ? '<tr class="shop-sub shop-discount"><th scope="row" colspan="2">Discount <span class="shop-code-name">' . e($code) . '</span></th><td class="shop-num">'
             . ($disc > 0 ? '−' . $m($disc) : 'Free delivery') . '</td></tr>' : '')
         . ($gp > 0 ? '<tr class="shop-sub shop-discount"><th scope="row" colspan="2">' . e($group) . ' prices</th><td class="shop-num">−' . $m($gp) . '</td></tr>' : '')
         . ($postLabel !== '' ? '<tr class="shop-sub"><th scope="row" colspan="2">' . e($postLabel) . '</th><td class="shop-num">'
         . ($post === null ? 'Added at checkout' : ($post === 0 ? 'Free' : $m($post))) . '</td></tr>' : '')
         . ($ex ? '<tr class="shop-sub"><th scope="row" colspan="2">Total ex VAT</th><td class="shop-num">' . money(ex_vat($total)) . '</td></tr>'
             . '<tr class="shop-sub"><th scope="row" colspan="2">VAT at 20%</th><td class="shop-num">' . money($total - ex_vat($total)) . '</td></tr>' : '')
         . '<tr class="shop-grand"><th scope="row" colspan="2">Total</th><td class="shop-num shop-total">' . money($total) . '</td></tr>';
    if ($vat !== null && $vatShown && !$ex) {
        $out .= '<tr class="shop-sub"><td colspan="3" class="shop-num shop-vat">Includes VAT of ' . money($vat) . '</td></tr>';
    }
    return $out . '</tfoot></table>';
}

function seller_html(): string
{
    $name = setting('business_name');
    $addr = setting('business_address');
    $mail = setting('business_email');
    if ($name === '' && $mail === '') {
        return '';
    }
    return '<p class="shop-seller">Sold by <strong>' . e($name ?: setting('last_site_title')) . '</strong>'
         . ($addr !== '' ? ', ' . e(preg_replace('/\s*\n\s*/', ', ', trim($addr))) : '')
         . ($mail !== '' ? '. Questions? <a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '') . '.'
         . (setting('vat_registered') === '1' ? ' VAT number ' . e(setting('vat_number')) . '.' : '') . '</p>';
}

function legal_links(bool $privacy = true): string
{
    $out = [];
    foreach (['terms_url' => 'Terms of sale', 'returns_url' => 'Delivery and returns'] + ($privacy ? ['privacy_url' => 'Privacy'] : []) as $k => $label) {
        if (setting($k) !== '') {
            $out[] = '<a href="' . e(setting($k)) . '" target="_blank" rel="noopener">' . $label . '</a>';
        }
    }
    return implode(' · ', $out);
}

function view_checkout(array $errors = [], ?array $v = null): never
{
    orders_tidy();
    $lines = basket_lines();
    if (!$lines || lines_changed($lines)) {
        if ($lines) {
            flash('Something in your basket changed. Please check it before checking out.');
        }
        redirect(shop_url('basket'));
    }
    $sub = array_sum(array_map(fn($l) => $l['qty'] * $l['price'], $lines));
    $me = current_customer();
    $t = basket_totals($sub, (string)($v['email'] ?? ($me['email'] ?? '')), lines_group_saving($lines), lines_sale_saving($lines));
    if ($why = checkout_blocked($t)) {
        flash($why);
        redirect(shop_url('basket'));
    }
    if ($t['discount_note'] !== null && !in_array($t['discount_note'], $errors, true)) {
        flash($t['discount_note']);
    }
    if ($v === null && empty($_SESSION['shop_checkout']) && $me) {   // fill in from the account
        $v = ['email' => $me['email'], 'phone' => $me['phone'], 'bill_name' => $me['bill_name'] ?: $me['name'],
              'same' => (string)(int)$me['same_address'], 'save' => '1'];
        foreach (ADDRESS_FIELDS as $k) {
            if ($k !== 'bill_name') {
                $v[$k] = $me[$k];
            }
        }
    }
    $v ??= $_SESSION['shop_checkout'] ?? [];
    $v += ['save' => '1', 'email' => '', 'phone' => '', 'bill_name' => '', 'bill_line1' => '', 'bill_line2' => '', 'bill_town' => '',
           'bill_postcode' => '', 'same' => '1', 'ship_name' => '', 'ship_line1' => '', 'ship_line2' => '', 'ship_town' => '',
           'ship_postcode' => '', 'note' => '', 'pay' => ''];
    $deliver = $t['method'] === 'deliver';
    $digital = basket_digital();
    if ($digital && !$me) {   // downloads live in the customer's account
        flash('Please sign in or create an account to buy downloads. They\'ll be waiting in your account once you\'ve paid.');
        redirect(shop_url('account/login') . '?next=checkout');
    }
    $methods = array_values(array_filter(['paypal', 'bank', 'collection'],
        fn($m) => pay_allowed($m) && ($m !== 'collection' || $t['method'] === 'collect')));
    if (!$methods) {
        flash('This order can\'t be paid online yet.');
        redirect(shop_url('basket'));
    }
    if (!in_array($v['pay'], $methods, true)) {
        $v['pay'] = $methods[0];
    }
    $field = fn(string $name, string $label, string $attrs = '', string $hint = '') => '<label>' . $label
        . ($hint !== '' ? ' <span class="shop-hint">' . $hint . '</span>' : '')
        . '<input name="' . $name . '" value="' . e($v[$name]) . '" ' . $attrs . '></label>';

    $rows = '';
    foreach ($lines as $l) {
        $rows .= '<tr><td>' . e($l['p']['name']) . ($l['option'] !== '' ? '<small> ' . e($l['option']) . '</small>' : '')
               . ($l['backorder'] && backorder_told($l['p']) ? '<small class="shop-backorder">' . backorder_note($l['backorder'], $l['qty']) . '</small>' : '') . '</td>'
               . '<td class="shop-num">× ' . $l['qty'] . '</td><td class="shop-num">' . shown_money($l['qty'] * line_unit($l, $t)) . '</td></tr>';
    }
    $payDesc = [
        'paypal'     => 'Pay with your PayPal account, or by card through PayPal.',
        'bank'       => 'Pay by bank transfer. You\'ll see our bank details next; we send your order once the money arrives.',
        'collection' => 'Pay when you collect your order.',
    ];
    $pay = '';
    foreach ($methods as $m) {
        $pay .= '<label class="shop-radio"><input type="radio" name="pay" value="' . $m . '"' . ($v['pay'] === $m ? ' checked' : '')
              . ' data-button="' . ($m === 'paypal' ? 'Continue to PayPal' : 'Place order') . '"> <span><strong>' . method_label($m)
              . '</strong><br><small>' . e($payDesc[$m]) . '</small></span></label>';
    }
    $terms = setting('terms_url') !== '' || setting('returns_url') !== '';
    $collectNote = trim($t['method_note']);

    $f = flash();
    $body = '<h1 class="post-title">Checkout</h1><div class="post-body">'
        . ($f ? '<p class="shop-note" role="status">' . e($f) . '</p>' : '')
        . ($errors ? '<div class="shop-note shop-errors" role="alert"><strong>Please check:</strong><ul>'
            . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '')
        . (accounts_on() ? ($me
            ? '<p class="shop-note shop-signedin"><span>Signed in as <strong>' . e($me['email']) . '</strong>.</span> <a href="' . e(shop_url('account')) . '">Your account</a></p>'
            : '<p class="shop-note">Have an account? <a href="' . e(shop_url('account/login') . '?next=checkout') . '">Sign in</a> to fill this in for you. Or just carry on as a guest.</p>') : '')
        . '<form class="shop-form" method="post" action="' . e(shop_url('checkout')) . '" id="shop-checkout">' . csrf_field()
        . '<fieldset><legend>Your details</legend>'
        . $field('email', 'Email', 'type="email" autocomplete="email" required maxlength="200"', 'for your order confirmation')
        . $field('phone', 'Phone', 'type="tel" autocomplete="tel" maxlength="40"', '(optional, only used if there\'s a problem)')
        . '</fieldset>'
        . '<fieldset><legend>Billing address</legend>'
        . $field('bill_name', 'Full name', 'autocomplete="billing name" required maxlength="120"')
        . $field('bill_line1', 'Address', 'autocomplete="billing address-line1" required maxlength="120"')
        . $field('bill_line2', '<span class="shop-vh">Address line 2</span>', 'autocomplete="billing address-line2" maxlength="120" aria-label="Address line 2"')
        . $field('bill_town', 'Town or city', 'autocomplete="billing address-level2" required maxlength="80"')
        . $field('bill_postcode', 'Postcode', 'autocomplete="billing postal-code" required maxlength="10" class="shop-short" autocapitalize="characters"')
        . '</fieldset>';
    if ($deliver) {
        $same = $v['same'] === '1';
        $body .= '<fieldset><legend>Delivery address</legend>'
            . '<label class="shop-radio"><input type="checkbox" name="same" value="1" id="shop-same"' . ($same ? ' checked' : '')
            . '> <span>Delivery address is the same as billing</span></label>'
            . '<div id="shop-ship"' . ($same ? ' hidden' : '') . '>'
            . $field('ship_name', 'Full name', 'autocomplete="shipping name" maxlength="120"')
            . $field('ship_line1', 'Address', 'autocomplete="shipping address-line1" maxlength="120"')
            . $field('ship_line2', '<span class="shop-vh">Address line 2</span>', 'autocomplete="shipping address-line2" maxlength="120" aria-label="Delivery address line 2"')
            . $field('ship_town', 'Town or city', 'autocomplete="shipping address-level2" maxlength="80"')
            . $field('ship_postcode', 'Postcode', 'autocomplete="shipping postal-code" maxlength="10" class="shop-short" autocapitalize="characters"')
            . '</div><p class="shop-hint">We deliver to UK addresses.' . (delivery_zones() ? ' Delivery to some areas costs more: if yours does, you\'ll see the new total before you pay.' : '') . '</p></fieldset>';
    } elseif ($t['method'] === 'none') {
        $body .= '';   // downloads only: nothing to deliver
    } else {
        $body .= '<fieldset><legend>Collection</legend><p>You\'ve chosen to collect your order.'
            . ($collectNote !== '' ? '</p><p>' . nl2br(e($collectNote)) : '') . '</p>'
            . '<p class="shop-hint"><a href="' . e(shop_url('basket')) . '">Change to delivery</a></p></fieldset>';
    }
    if ($me) {
        $body .= '<label class="shop-radio"><input type="checkbox" name="save" value="1"' . ($v['save'] === '1' ? ' checked' : '')
            . '> <span>Save these details to my account for next time</span></label>';
    }
    $body .= '<fieldset><legend>Payment</legend>' . $pay . '</fieldset>'
        . '<label>Note for the shop <span class="shop-hint">(optional)</span><textarea name="note" maxlength="500" rows="3">' . e($v['note']) . '</textarea></label>'
        . '<h2 class="shop-h2">Your order</h2>'
        . ($digital ? '<label class="shop-radio"><input type="checkbox" name="digital_ok" value="1" required> <span>I want my download'
            . ' straight away, and understand that once I\'ve started downloading I lose my right to cancel.</span></label>' : '')
        . summary_table($rows, $t['subtotal'], $t['postage'], $t['method'] === 'none' ? '' : $t['method_name'] . ($t['zone'] !== '' ? ' (' . $t['zone'] . ')' : ''), $t['total'], $t['vat'], true, $t['code'], $t['discount'], $t['group'], $t['group_pence'], prices_ex_vat())
        . '<p class="shop-hint"><a href="' . e(shop_url('basket')) . '">Change your basket</a>'
        . ($t['code'] === '' && discounts_live() ? ' · Have a discount code? Add it in your basket.' : '') . '</p>'
        . seller_html()
        // The privacy notice is information, not something to agree to, so it sits apart from the terms tick.
        . ($terms ? '<label class="shop-radio"><input type="checkbox" name="terms" value="1" required> <span>I\'ve read the '
            . legal_links(false) . '.</span></label>' : '')
        . (setting('privacy_url') !== '' ? '<p class="shop-hint">How we use your details: <a href="' . e(setting('privacy_url')) . '" target="_blank" rel="noopener">privacy notice</a>.</p>' : '')
        . '<button class="shop-button" id="shop-place">' . ($v['pay'] === 'paypal' ? 'Continue to PayPal' : 'Place order') . '</button>'
        . '<p class="shop-hint">By placing your order you agree to pay ' . money($t['total']) . '.</p>'
        . '</form></div>'
        . '<script>(function(){var f=document.getElementById("shop-checkout"),s=document.getElementById("shop-same"),'
        . 'd=document.getElementById("shop-ship"),b=document.getElementById("shop-place");'
        . 'if(s){s.addEventListener("change",function(){d.hidden=s.checked;});}'
        . 'f.addEventListener("change",function(e){if(e.target.name==="pay")b.textContent=e.target.dataset.button;});'
        . 'f.addEventListener("submit",function(){setTimeout(function(){b.disabled=true;b.textContent="Please wait…";},0);});})();</script>';
    render('Checkout', $body, ['route' => 'checkout', 'noindex' => true, 'crumbs' => [['Basket', 'basket'], ['Checkout', null]]]);
}

function checkout_post(): never
{
    csrf_check();
    orders_tidy();
    $lines = basket_lines();
    if (!$lines || lines_changed($lines)) {
        flash($lines ? 'Something in your basket changed. Please check it before checking out.' : 'Your basket is empty.');
        redirect(shop_url('basket'));
    }
    $sub = array_sum(array_map(fn($l) => $l['qty'] * $l['price'], $lines));
    $hadCode = (string)($_SESSION['shop_discount'] ?? '');
    $gsave = lines_group_saving($lines);
    $ssave = lines_sale_saving($lines);
    $t = basket_totals($sub, '', $gsave, $ssave);
    if ($why = checkout_blocked($t)) {
        flash($why);
        redirect(shop_url('basket'));
    }
    $deliver = $t['method'] === 'deliver';
    $v = [];
    foreach (['email', 'phone', 'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode',
              'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode', 'note', 'pay'] as $k) {
        $v[$k] = mb_substr(post($k), 0, $k === 'note' ? 500 : 200);
    }
    $v['same'] = !$deliver || !empty($_POST['same']) ? '1' : '0';
    $digital = basket_digital();
    $v['save'] = !empty($_POST['save']) ? '1' : '0';
    $me = current_customer();
    $_SESSION['shop_checkout'] = $v;   // so nothing typed is lost

    $errors = [];
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter an email address we can send your order confirmation to.';
    }
    foreach (['bill_name' => 'your name', 'bill_line1' => 'the first line of your address', 'bill_town' => 'your town or city'] as $k => $what) {
        if ($v[$k] === '') {
            $errors[] = 'Enter ' . $what . ' (billing address).';
        }
    }
    $pc = uk_postcode($v['bill_postcode']);
    if ($pc === null) {
        $errors[] = 'Enter a UK postcode for the billing address, like BS49 4AB.';
    } else {
        $v['bill_postcode'] = $pc;
    }
    if ($deliver && $v['same'] === '0') {
        foreach (['ship_name' => 'a name', 'ship_line1' => 'the first line of the address', 'ship_town' => 'the town or city'] as $k => $what) {
            if ($v[$k] === '') {
                $errors[] = 'Enter ' . $what . ' for delivery.';
            }
        }
        $sp = uk_postcode($v['ship_postcode']);
        if ($sp === null) {
            $errors[] = 'Enter a UK postcode for the delivery address.';
        } else {
            $v['ship_postcode'] = $sp;
        }
    }
    // Delivery can depend on the postcode's zone: if the price or method changes, the customer
    // sees the new total before anything is placed.
    if ($deliver && !$errors) {
        $pcNow = $v['same'] === '1' ? $v['bill_postcode'] : $v['ship_postcode'];
        $_SESSION['shop_postcode'] = $pcNow;
        $t2 = basket_totals($sub, '', $gsave, $ssave);
        $t2['discount_note'] ??= $t['discount_note'];   // the first pass may have dropped a used-up code and said why
        if ($t2['unavailable'] !== '') {
            $errors[] = 'We can\'t send this order to ' . $pcNow . ($t2['zone'] !== '' ? ' (' . $t2['zone'] . ')' : '') . ': ' . $t2['unavailable']
                      . '. ' . (array_filter($t2['methods'], fn($m) => $m['kind'] === 'collect') ? 'You could choose collection in your basket, or get in touch with the shop.' : 'Please get in touch with the shop.');
        } elseif ($t2['method_key'] !== $t['method_key'] || $t2['postage'] !== $t['postage']) {
            $errors[] = ($t2['method_key'] !== $t['method_key'] ? $t['method_name'] . ' isn\'t available to ' . $pcNow . ', so it\'s ' . $t2['method_name'] . ' instead. ' : '')
                      . $t2['method_name'] . ' to ' . $pcNow . ($t2['zone'] !== '' ? ' (' . $t2['zone'] . ')' : '') . ' is '
                      . ($t2['postage'] ? money((int)$t2['postage']) : 'free') . ', so your total is now ' . money($t2['total']) . '. Please check it and place your order again.';
        }
        $t = $t2;
    }
    $methodOk = pay_allowed($v['pay']) && ($v['pay'] !== 'collection' || $t['method'] === 'collect');
    if ($digital && !$me) {
        flash('Please sign in or create an account to buy downloads.');
        redirect(shop_url('account/login') . '?next=checkout');
    }
    if ($digital && empty($_POST['digital_ok'])) {
        $errors[] = 'Please tick to say you want your download straight away (you lose the right to cancel once you start downloading).';
    }
    if (!$methodOk) {
        $errors[] = 'Choose how you\'d like to pay.';
    }
    if ((setting('terms_url') !== '' || setting('returns_url') !== '') && empty($_POST['terms'])) {
        $errors[] = 'Please tick to say you\'ve read the terms.';
    }
    // The code on the basket must still stand (used up since, or once per customer,
    // which is only known now we have their email). If not, they see the new total first.
    if ($hadCode !== '' && !$errors) {
        $note = $t['discount_note'];
        if ($t['code'] !== '') {
            $t = basket_totals($sub, $v['email'], $gsave, $ssave);
            $note = $t['discount_note'];
        }
        if ($t['discount_id'] === null && !$t['code_beaten'] && empty($_SESSION['shop_discount'])) {
            $errors[] = ($note ?? 'Your discount code no longer applies.') . ' Your total has been updated.';
        }
    }
    $limits = getenv('WRENSHOP_TESTING') !== '1' || setting('test_order_limits') === '1';   // the test suite places lots of orders
    if (!$errors && !current_admin() && $limits) {   // stop scripts placing heaps of orders to hold stock or send emails
        if (throttled('order:' . client_ip(), 10, 3600) || throttled('order:' . email_key($v['email']), 5, 3600)) {
            $errors[] = 'Lots of orders have been placed from here just now. Please try again in an hour, or contact the shop.';
        }
    }
    if ($errors) {
        view_checkout($errors, $v);
    }
    throttle_hit('order:' . client_ip());
    throttle_hit('order:' . email_key($v['email']));
    if ($deliver && $v['same'] === '1') {
        foreach (['name', 'line1', 'line2', 'town', 'postcode'] as $f) {
            $v['ship_' . $f] = $v['bill_' . $f];
        }
    }

    // A PayPal payment this visitor started and abandoned: settle it first.
    if (!empty($_SESSION['shop_pending'])) {
        $prev = order_by('id', (int)$_SESSION['shop_pending']);
        unset($_SESSION['shop_pending']);
        if ($prev && $prev['status'] === 'pending') {
            $r = $prev['paypal_order_id'] !== '' ? paypal_reconcile($prev) : 'none';
            if ($r === 'paid') {
                unset($_SESSION['shop_basket'], $_SESSION['shop_discount']);
                redirect(shop_url('order/' . $prev['token']));
            }
            if (in_array($r, ['none', 'waiting'], true)) {   // PayPal confirms nothing was paid
                order_cancel((int)$prev['id'], 'Customer started checkout again.');
            }   // otherwise (PayPal holding it, or unreachable) leave it for the regular check
        }
    }

    // Place the order and hold its stock in one step, so two people can't buy the last one.
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    foreach ($lines as $i => $l) {
        $st = $l['v'] ? $pdo->prepare('SELECT stock FROM variants WHERE id = ?') : $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $st->execute([$l['v'] ? $l['v']['id'] : $l['p']['id']]);
        $stock = $st->fetchColumn();
        if (backorders_on($l['p'])) {   // keeps selling: count what's beyond the stock right now
            $lines[$i]['backorder'] = $stock === null ? 0 : max(0, $l['qty'] - max(0, (int)$stock));
            continue;
        }
        if ($stock !== null && (int)$stock < $l['qty']) {
            $pdo->exec('ROLLBACK');
            $name = item_name($l['p'], $l['v']);
            flash((int)$stock <= 0 ? 'Sorry, ' . $name . ' has just sold out.' : 'Sorry, only ' . (int)$stock . ' of ' . $name . ' left now.');
            redirect(shop_url('basket'));
        }
    }
    if ($t['discount_id'] !== null) {   // another order may have used it up a moment ago
        $d = discount_by_id($t['discount_id']);
        $why = $d ? discount_problem($d, $t['subtotal'], $v['email']) : ['That discount code has been withdrawn.', false];
        if ($why) {
            $pdo->exec('ROLLBACK');
            unset($_SESSION['shop_discount']);
            view_checkout([$why[0] . ' Your total has been updated.'], $v);
        }
    }
    $number = (int)$pdo->query('SELECT COALESCE(MAX(number), 1000) + 1 FROM orders')->fetchColumn();
    $token = bin2hex(random_bytes(16));
    $status = ['paypal' => 'pending', 'bank' => 'awaiting_payment', 'collection' => 'pay_on_collection'][$v['pay']];
    $pdo->prepare('INSERT INTO orders (number, token, status, payment_method, delivery, email, phone,
                   bill_name, bill_line1, bill_line2, bill_town, bill_postcode,
                   ship_name, ship_line1, ship_line2, ship_town, ship_postcode, same_address, customer_note,
                   subtotal_pence, postage_pence, total_pence, vat_pence, vat_number, created, updated, customer_id,
                   discount_id, discount_code, discount_pence, group_name, group_pence, digital_consent, delivery_method, delivery_zone)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$number, $token, $status, $v['pay'], $t['method'], $v['email'], $v['phone'],
                   $v['bill_name'], $v['bill_line1'], $v['bill_line2'], $v['bill_town'], $v['bill_postcode'],
                   $deliver ? $v['ship_name'] : '', $deliver ? $v['ship_line1'] : '', $deliver ? $v['ship_line2'] : '',
                   $deliver ? $v['ship_town'] : '', $deliver ? $v['ship_postcode'] : '', (int)$v['same'], $v['note'],
                   $t['subtotal'], (int)$t['postage'], $t['total'], $t['vat'],
                   $t['vat'] !== null ? setting('vat_number') : '', now(), now(), $me ? (int)$me['id'] : null,
                   $t['discount_id'], $t['code'], $t['discount'], $t['group'], $t['group_pence'], $digital ? now() : '', $t['method_name'], $t['zone']]);
    $oid = (int)$pdo->lastInsertId();
    foreach ($lines as $l) {
        // The part number as it was: the choice's own, else the product's.
        $sku = $l['v'] && ($l['v']['sku'] ?? '') !== '' ? $l['v']['sku'] : ($l['p']['sku'] ?? '');
        // The price actually charged (sale or full), and how many weren't in stock.
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, name, option_label, unit_pence, qty, licence_id, sku, backorder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$oid, $l['p']['id'], $l['v'] ? $l['v']['id'] : null, $l['p']['name'], $l['option'], line_unit($l, $t), $l['qty'], $l['p']['renew_licence'] ?? null, $sku, $l['backorder']]);
        if ($l['v']) {
            $pdo->prepare('UPDATE variants SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([$l['qty'], $l['v']['id']]);
        } else {
            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([$l['qty'], $l['p']['id']]);
        }
    }
    $pdo->exec('COMMIT');
    $o = order_by('id', $oid);
    if ($me && $v['save'] === '1') {
        $addr = [];
        foreach (ADDRESS_FIELDS as $k) {
            $addr[$k] = $deliver || str_starts_with($k, 'bill_') ? $v[$k] : $me[$k];   // collection: keep their saved delivery address
        }
        $addr['same_address'] = $deliver ? (int)$v['same'] : (int)$me['same_address'];
        customer_save_addresses((int)$me['id'], $addr);
        db()->prepare("UPDATE customers SET phone = CASE WHEN ? != '' THEN ? ELSE phone END WHERE id = ?")->execute([$v['phone'], $v['phone'], $me['id']]);
    }

    if ($v['pay'] === 'paypal') {
        $err = null;
        $pp = paypal_create($o, $err);
        if (!$pp) {
            order_cancel($oid, 'Couldn\'t start the PayPal payment: ' . $err);
            view_checkout(['We couldn\'t reach PayPal just now, so nothing has been charged. Please try again in a moment'
                . (pay_allowed('bank') ? ', or choose bank transfer.' : '.')], $v);
        }
        db()->prepare('UPDATE orders SET paypal_order_id = ?, paypal_link = ? WHERE id = ?')->execute([$pp['id'], $pp['link'], $oid]);
        $_SESSION['shop_pending'] = $oid;
        redirect($pp['link']);
    }
    unset($_SESSION['shop_basket'], $_SESSION['shop_checkout'], $_SESSION['shop_discount']);
    mail_order_event($oid, $v['pay'] === 'bank' ? 'placed_bank' : 'placed_collection');
    mail_order_event($oid, 'new_order');
    low_stock_check($oid);
    redirect(shop_url('order/' . $token));
}

/* PayPal sends the customer back here after they approve the payment. */
function paypal_return(): never
{
    $o = order_by('paypal_order_id', str_in($_GET['token'] ?? ''));
    if (!$o) {
        flash('We couldn\'t find that payment. If you were charged, please get in touch.');
        redirect(shop_url('basket'));
    }
    if ($o['status'] === 'pending') {
        $err = null;
        $r = paypal_capture($o, $err);
        if ($r === 'declined') {
            // PayPal's advice: send the customer back to choose another way to pay.
            redirect($o['paypal_link']);
        }
        if ($r === 'error' || $r === 'failed') {
            order_note((int)$o['id'], 'Capture on return failed (' . ($err ?? $r) . '); will check again.');
        }
        $o = order_by('id', (int)$o['id']);
    }
    if (($_SESSION['shop_pending'] ?? 0) != $o['id']) {
        // Someone else's link (or another browser): don't show the order's details.
        render('Thank you', '<h1 class="post-title">Thank you</h1><div class="post-body"><p>Your payment has been passed to the shop. '
            . 'Your order confirmation email has the link to your order.</p></div>', ['noindex' => true]);
    }
    if (in_array($o['status'], ['paid', 'pending'], true)) {
        unset($_SESSION['shop_basket'], $_SESSION['shop_checkout'], $_SESSION['shop_pending'], $_SESSION['shop_discount']);
    }
    redirect(shop_url('order/' . $o['token']));
}

/* ...or here if they cancel on PayPal's page. Their basket is still there. */
function paypal_cancel(): never
{
    $o = order_by('paypal_order_id', str_in($_GET['token'] ?? ''));
    if ($o && $o['status'] === 'pending' && ($_SESSION['shop_pending'] ?? 0) == $o['id']) {
        $r = paypal_reconcile($o);
        if ($r === 'paid') {   // they paid after all (e.g. in another tab)
            unset($_SESSION['shop_basket'], $_SESSION['shop_checkout'], $_SESSION['shop_pending'], $_SESSION['shop_discount']);
            redirect(shop_url('order/' . $o['token']));
        }
        if ($r === 'waiting') {
            order_cancel((int)$o['id'], 'Customer cancelled at PayPal.');
        }
        unset($_SESSION['shop_pending']);
    }
    flash('Payment cancelled. Nothing was charged and your basket is just as you left it.');
    redirect(shop_url('checkout'));
}

/* ------------------------------------------------------- the customer's order */

function address_html(string $prefix, array $o): string
{
    $parts = array_filter([$o[$prefix . 'name'], $o[$prefix . 'line1'], $o[$prefix . 'line2'], $o[$prefix . 'town'], $o[$prefix . 'postcode']],
                          fn($x) => trim((string)$x) !== '');
    return implode('<br>', array_map('e', $parts));
}

function order_rows(array $o): string
{
    $rows = '';
    foreach (order_items((int)$o['id']) as $it) {
        $rows .= '<tr><td>' . e($it['name']) . ($it['option_label'] !== '' ? '<small> ' . e($it['option_label']) . '</small>' : '')
               . (($it['sku'] ?? '') !== '' ? '<small class="shop-sku"> · ' . e($it['sku']) . '</small>' : '')
               . (((int)($it['backorder'] ?? 0) > 0 && product_tells_backorders((int)$it['product_id'])) ? '<small class="shop-backorder">' . backorder_note((int)$it['backorder'], (int)$it['qty']) . '</small>' : '')
               . '</td><td class="shop-num">× ' . (int)$it['qty'] . '</td><td class="shop-num">' . money((int)$it['unit_pence'] * (int)$it['qty']) . '</td></tr>';
    }
    return $rows;
}

function view_order(string $token): never
{
    header('Referrer-Policy: no-referrer');   // the address holds the order's secret token
    $o = preg_match('/^[a-f0-9]{32}$/', $token) ? order_by('token', $token) : null;
    if (!$o) {
        view_404();
    }
    if ($o['status'] === 'pending' && $o['paypal_order_id'] !== '' && strtotime($o['updated'] . ' UTC') < time() - 20) {
        paypal_reconcile($o);   // e.g. the customer came back later: see where PayPal got to
        $o = order_by('id', (int)$o['id']);
    }
    $n = '#' . (int)$o['number'];
    $head = match ($o['status']) {
        'paid'              => '<h1 class="post-title">Thank you. Your order is confirmed</h1>'
                             . '<p>Order <strong>' . $n . '</strong> is paid. ' . ($o['delivery'] === 'collect' ? 'We\'ll let you know when it\'s ready to collect.' : 'We\'ll send it to the address below.') . '</p>',
        'awaiting_payment'  => '<h1 class="post-title">Order placed: please pay by bank transfer</h1>'
                             . '<p>Order <strong>' . $n . '</strong> is reserved for you. We\'ll send it once your payment arrives.</p>',
        'pay_on_collection' => '<h1 class="post-title">Order placed: pay when you collect</h1>'
                             . '<p>Order <strong>' . $n . '</strong> is reserved for you. Please pay when you collect it.</p>',
        'pending'           => '<h1 class="post-title">We\'re waiting for PayPal</h1>'
                             . '<p>We haven\'t had confirmation of your PayPal payment for order <strong>' . $n . '</strong> yet. '
                             . 'If you finished paying, this page will update shortly. Nothing is charged until PayPal confirms.</p>'
                             . ($o['paypal_link'] !== '' ? '<p><a class="shop-button" href="' . e($o['paypal_link']) . '">Finish paying with PayPal</a></p>' : ''),
        'cancelled'         => '<h1 class="post-title">Order ' . $n . ' was cancelled</h1><p>Nothing was charged for this order.</p>',
        'refunded'          => '<h1 class="post-title">Order ' . $n . ' was refunded</h1>',
        default             => '<h1 class="post-title">Order ' . $n . '</h1>',
    };
    $bank = '';
    if ($o['status'] === 'awaiting_payment') {
        $bank = '<div class="shop-note shop-bank"><strong>Bank details</strong><dl>'
            . '<dt>Account name</dt><dd>' . e(setting('bank_account_name')) . '</dd>'
            . '<dt>Sort code</dt><dd>' . e(setting('bank_sort_code')) . '</dd>'
            . '<dt>Account number</dt><dd>' . e(setting('bank_account')) . '</dd>'
            . '<dt>Reference</dt><dd><strong>' . e((string)$o['number']) . '</strong></dd>'
            . '<dt>Amount</dt><dd><strong>' . money((int)$o['total_pence']) . '</strong></dd>'
            . (unpaid_days() > 0 && $o['created'] >= setting('unpaid_since', '2100-01-01') ? '<dt>Please pay by</dt><dd>' . e(pay_by_date($o)) . '</dd>' : '') . '</dl>'
            . (trim(setting('bank_note')) !== '' ? '<p>' . nl2br(e(setting('bank_note'))) . '</p>' : '') . '</div>';
    }
    $where = $o['delivery'] === 'none'
        ? '<h2 class="shop-h2">Your downloads</h2><p>' . ($o['status'] === 'paid' ? 'Ready in your account: <a href="' . e(shop_url('account/licences')) . '">Downloads and licences</a>.'
            : 'They\'ll be in your account under Downloads and licences as soon as payment arrives.') . '</p>'
        : ($o['delivery'] === 'collect'
        ? '<h2 class="shop-h2">Collection</h2>' . (($cn = collect_note_for($o)) !== '' ? '<p>' . nl2br(e($cn)) . '</p>' : '<p>We\'ll be in touch about collection.</p>')
        : '<h2 class="shop-h2">Delivery address</h2><p>' . address_html('ship_', $o) . '</p>');
    $body = $head . '<div class="post-body">' . $bank
        . ($o['fulfilled'] === 'sent' ? '<p class="shop-note">Sent on ' . e(gmdate('j F Y', strtotime($o['fulfilled_at'] . ' UTC')))
            . ($o['tracking'] !== '' ? '. Tracking: ' . e($o['tracking']) : '') . '.</p>' : '')
        . ($o['fulfilled'] === 'collected' ? '<p class="shop-note">Collected on ' . e(gmdate('j F Y', strtotime($o['fulfilled_at'] . ' UTC'))) . '.</p>' : '')
        . '<h2 class="shop-h2">Your order</h2>'
        . summary_table(order_rows($o), (int)$o['subtotal_pence'], (int)$o['postage_pence'],
                        order_post_label($o) . ($o['delivery_zone'] !== '' ? ' (' . $o['delivery_zone'] . ')' : ''), (int)$o['total_pence'],
                        $o['vat_pence'] === null ? null : (int)$o['vat_pence'], true, $o['discount_code'], (int)$o['discount_pence'], $o['group_name'], (int)$o['group_pence'])
        . '<p class="shop-hint">' . (in_array($o['status'], ['paid', 'refunded'], true) ? 'Paid by: ' : 'Paying by: ') . method_label($o['payment_method'])
        . ' · Placed ' . e(gmdate('j F Y, H:i', strtotime($o['created'] . ' UTC'))) . ' UTC'
        . ($o['vat_number'] !== '' ? ' · VAT number ' . e($o['vat_number']) : '') . '</p>'
        . (invoice_ready($o) ? '<p><a href="' . e(shop_url('order/' . $o['token'] . '/invoice')) . '" target="_blank" rel="noopener">'
            . ($o['vat_pence'] !== null ? 'Print a VAT invoice' : 'Print a receipt') . '</a></p>' : '')
        . $where
        . '<h2 class="shop-h2">Billing address</h2><p>' . address_html('bill_', $o) . '</p>'
        . order_account_box($o)
        . seller_html()
        . '<p class="shop-hint">Keep this page\'s address to check your order later. It\'s private to you.</p>'
        . (legal_links() !== '' ? '<p class="shop-hint">' . legal_links() . '</p>' : '')
        . '</div>';
    render('Order ' . $n, $body, ['noindex' => true, 'crumbs' => [['Order ' . $n, null]]]);
}

/* ============================================================ admin orders */

function orders_todo_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_payment', 'pay_on_collection')
                             OR (status = 'paid' AND fulfilled = '')")->fetchColumn();
}

function status_pill(array $o): string
{
    $cls = in_array($o['status'], ['cancelled', 'refunded'], true) ? '' : ($o['status'] === 'paid' ? ' ok' : ' wait');
    $out = '<span class="pill' . $cls . '">' . e(status_label($o['status'])) . '</span>';
    if ($o['fulfilled'] !== '') {
        $out .= '<span class="pill">' . (['sent' => 'Sent', 'digital' => 'Download'][$o['fulfilled']] ?? 'Collected') . '</span>';
    } elseif ($o['status'] === 'paid') {
        $out .= '<span class="pill out">' . ($o['delivery'] === 'collect' ? 'To collect' : 'To send') . '</span>';
    }
    if ((int)($o['imported'] ?? 0)) {
        $out .= '<span class="pill">Old shop</span>';
    }
    return $out;
}

function view_admin_orders(): never
{
    require_admin();
    orders_tidy();
    $all = ($_GET['show'] ?? '') === 'all';
    $sql = $all ? "SELECT * FROM orders ORDER BY id DESC LIMIT 300"
                : "SELECT * FROM orders WHERE status IN ('awaiting_payment', 'pay_on_collection', 'pending')
                   OR (status = 'paid' AND fulfilled = '') ORDER BY id DESC";
    $list = '';
    foreach (db()->query($sql) as $o) {
        $list .= '<li><div class="pname"><a href="' . e(admin_url('order/' . $o['id'])) . '">#' . (int)$o['number'] . ' · ' . e($o['bill_name']) . '</a>'
               . status_pill($o)
               . '<small>' . money((int)$o['total_pence']) . ' · ' . method_label($o['payment_method'])
               . ' · ' . ($o['delivery'] === 'collect' ? 'Collection' : ($o['delivery'] === 'none' ? 'Download' : 'Delivery'))
               . ($o['discount_code'] !== '' ? ' · ' . e($o['discount_code']) : '')
               . ($o['group_name'] !== '' ? ' · ' . e($o['group_name']) : '')
               . ' · ' . e(gmdate('j M H:i', strtotime($o['created'] . ' UTC'))) . '</small></div></li>';
    }
    admin_shell('Orders', ready_reminder(current_admin()) . '<div class="topline"><h1>Orders</h1><p class="muted">'
        . ($all ? '<a href="' . e(admin_url('orders')) . '">To do</a> · <b>All orders</b>'
                : '<b>To do</b> · <a href="' . e(admin_url('orders')) . '?show=all">All orders</a>') . ' · <a href="' . e(admin_url('export')) . '">Export CSV</a></p></div>'
        . ($list ? '<ul class="plist">' . $list . '</ul>'
                 : '<p class="lede">' . ($all ? 'No orders yet.' : 'Nothing to do. Every order is paid and sent or collected.') . '</p>'));
}

function view_admin_order(int $id): never
{
    require_admin();
    $o = order_by('id', $id);
    if (!$o) {
        redirect(admin_url('orders'));
    }
    $act = fn(string $do, string $label, string $extra = '', string $cls = '', string $confirm = '') =>
        '<form method="post" action="' . e(admin_url('order/' . $o['id'])) . '"'
        . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="do" value="' . $do . '">' . $extra . '<button class="' . ($cls ?: 'quiet') . '">' . $label . '</button></form>';
    $actions = '';
    $unpaid = in_array($o['status'], ['awaiting_payment', 'pay_on_collection'], true);
    if ($unpaid) {
        $actions .= $act('paid', $o['status'] === 'awaiting_payment' ? 'Mark as paid (transfer received)' : 'Mark as paid', '', '');
    }
    if ($o['status'] === 'paid' && $o['fulfilled'] === '') {
        $actions .= $o['delivery'] === 'collect'
            ? $act('collected', 'Mark as collected', '', '')
            : $act('sent', 'Mark as sent', '<label>Tracking number or link <span class="hint">(optional)</span><input name="tracking" maxlength="200"></label>', '');
    }
    if ($o['status'] === 'pay_on_collection' && $o['fulfilled'] === '') {
        $actions .= $act('paid_collected', 'Paid and collected', '', 'quiet');
    }
    if ($o['status'] === 'pending') {
        $actions .= $act('check', 'Check with PayPal now');
    }
    if ($unpaid || $o['status'] === 'pending') {
        $actions .= $act('cancel', 'Cancel order', '', 'danger', 'Cancel this order? Its stock goes back on sale.');
    }
    if ($o['status'] === 'cancelled' && $o['paid_at'] === '' && $o['payment_method'] !== 'paypal') {
        $actions .= $act('restore', 'Restore order', '', '', 'Restore this order? Its items are taken out of stock again.');
    }
    if ($o['status'] === 'paid') {
        $restock = '<label class="check"><input type="checkbox" name="restock" value="1"' . ($o['fulfilled'] === '' ? ' checked' : '') . '> Put the items back in stock</label>';
        if ($o['payment_method'] === 'paypal' && $o['paypal_capture_id'] !== '') {
            $actions .= $act('refund', 'Refund ' . money((int)$o['total_pence']) . ' through PayPal', $restock, 'danger', 'Refund the full amount to the customer through PayPal?');
            $actions .= '<details class="more"><summary>Paid them back another way?</summary>'
                . $act('refunded', 'Mark as refunded', $restock, 'quiet', 'Mark this order as refunded without going through PayPal?') . '</details>';
        } else {
            $actions .= $act('refunded', 'Mark as refunded', $restock, 'danger', 'Mark this order as refunded? (Pay the customer back yourself first.)');
        }
    }
    $mails = '';
    $ms = db()->prepare('SELECT * FROM emails WHERE order_id = ? ORDER BY id');
    $ms->execute([$o['id']]);
    foreach ($ms->fetchAll() as $m) {
        $state = match ($m['status']) {
            'sent'    => '<span class="pill ok">Sent ' . e(gmdate('j M H:i', strtotime($m['sent_at'] . ' UTC'))) . '</span>',
            'failed'  => '<span class="pill">' . ((int)$m['tries'] >= MAIL_TRIES ? 'Not sent' : 'Retrying') . '</span> <span class="muted">' . e($m['error']) . '</span>',
            default   => '<span class="pill wait">' . (mail_ready() ? 'Sending' : 'Not sent: email isn\'t set up') . '</span>',
        };
        $mails .= '<tr><td>' . e($m['subject']) . '<br><span class="muted">to ' . e($m['to_addr']) . '</span></td><td>' . $state . '</td><td style="text-align:right">'
            . (mail_ready() ? '<form method="post" action="' . e(admin_url('order/' . $o['id'])) . '">' . csrf_field()
                . '<input type="hidden" name="do" value="resend"><input type="hidden" name="email_id" value="' . (int)$m['id'] . '"><button class="quiet small">'
                . ($m['status'] === 'sent' ? 'Send again' : 'Try now') . '</button></form>' : '') . '</td></tr>';
    }
    if ((int)$o['imported']) {   // a record from the old shop: nothing to do with it here
        $actions = '<p class="flash">Brought across from your old shop. It\'s a record only: it can\'t be changed or refunded here.</p>';
    }
    $mails = '<h2>Emails</h2>' . ($mails !== '' ? '<table>' . $mails . '</table>'
        : '<p class="muted">' . (mail_ready() ? 'None yet.' : 'None: email isn\'t set up. <a href="' . e(settings_url('email')) . '">Set it up in Settings</a>.') . '</p>');
    $items = '';
    foreach (order_items((int)$o['id']) as $it) {
        $items .= '<tr><td>' . e($it['name']) . ($it['option_label'] !== '' ? '<br><span class="muted">' . e($it['option_label']) . '</span>' : '')
               . (($it['sku'] ?? '') !== '' ? '<br><span class="muted sku">' . e($it['sku']) . '</span>' : '')
               . ((int)$it['backorder'] > 0 ? ' <span class="pill wait">' . (int)$it['backorder'] . ' on backorder</span>' : '')
               . '</td><td>× ' . (int)$it['qty'] . '</td><td style="text-align:right">' . money((int)$it['unit_pence'] * (int)$it['qty']) . '</td></tr>';
    }
    $items .= '<tr><td colspan="2">Subtotal</td><td style="text-align:right">' . money((int)$o['subtotal_pence']) . '</td></tr>'
            . ($o['discount_code'] !== '' ? '<tr><td colspan="2">Discount <a href="' . e(admin_url('discount/' . (int)$o['discount_id'])) . '"><code>' . e($o['discount_code']) . '</code></a></td><td style="text-align:right">'
                . ((int)$o['discount_pence'] > 0 ? '−' . money((int)$o['discount_pence']) : 'Free delivery') . '</td></tr>' : '')
            . ((int)$o['group_pence'] > 0 ? '<tr><td colspan="2">' . e($o['group_name']) . ' prices</td><td style="text-align:right">−' . money((int)$o['group_pence']) . '</td></tr>' : '')
            . '<tr><td colspan="2">' . ($o['delivery'] === 'none' ? 'Delivery (download)' : e(order_post_label($o) . ($o['delivery_zone'] !== '' ? ' (' . $o['delivery_zone'] . ')' : ''))) . '</td><td style="text-align:right">'
            . ((int)$o['postage_pence'] ? money((int)$o['postage_pence']) : 'Free') . '</td></tr>'
            . '<tr><td colspan="2"><b>Total</b></td><td style="text-align:right"><b>' . money((int)$o['total_pence']) . '</b></td></tr>'
            . ($o['vat_pence'] !== null ? '<tr><td colspan="3" class="muted" style="text-align:right">Includes VAT of ' . money((int)$o['vat_pence']) . '</td></tr>' : '');
    $f = fn($t) => e(gmdate('j M Y H:i', strtotime($t . ' UTC')));
    admin_shell('Order #' . $o['number'], '<p class="muted"><a href="' . e(admin_url('orders')) . '">&larr; Orders</a></p>'
        . '<h1>Order #' . (int)$o['number'] . '</h1><p>' . status_pill($o) . '</p>'
        . '<p class="muted"><a href="' . e(admin_url('order/' . $o['id'] . '/slip')) . '" target="_blank" rel="noopener">Print packing slip</a>'
        . (invoice_ready($o) ? ' · <a href="' . e(admin_url('order/' . $o['id'] . '/invoice')) . '" target="_blank" rel="noopener">'
            . ($o['vat_pence'] !== null ? 'VAT invoice' : 'Receipt') . '</a>' : '') . '</p>'
        . '<p class="muted">' . method_label($o['payment_method']) . ' · placed ' . $f($o['created'])
        . ($o['paid_at'] !== '' ? ' · paid ' . $f($o['paid_at']) : '')
        . ($o['fulfilled_at'] !== '' ? ' · ' . $o['fulfilled'] . ' ' . $f($o['fulfilled_at']) : '')
        . ($o['tracking'] !== '' ? ' · tracking ' . e($o['tracking']) : '') . '</p>'
        . ($actions !== '' ? '<div class="actions-row" style="margin-top:1rem;border:0;padding:0">' . $actions . '</div>' : '')
        . '<h2>Items</h2><table>' . $items . '</table>' . order_licences_html((int)$o['id'])
        . '<h2>Customer</h2><p>' . e($o['bill_name']) . '<br><a href="mailto:' . e($o['email']) . '">' . e($o['email']) . '</a>'
        . (($cust = $o['customer_id'] !== null ? customer_by_id((int)$o['customer_id']) : customer_by_email($o['email']))
            ? '<br><a href="' . e(admin_url('customer/' . $cust['id'])) . '">Customer account &rarr;</a>' : '')
        . ($o['phone'] !== '' ? '<br>' . e($o['phone']) : '') . '</p>'
        . ($o['delivery'] === 'collect' ? '<h2>Collection</h2><p>The customer will collect.</p>'
            : '<h2>Send to</h2><p>' . address_html('ship_', $o) . '</p>')
        . '<h2>Billing address</h2><p>' . address_html('bill_', $o) . ($o['delivery'] === 'deliver' && $o['same_address'] ? '<br><span class="muted">(same as delivery)</span>' : '') . '</p>'
        . ($o['customer_note'] !== '' ? '<h2>Customer\'s note</h2><p>' . nl2br(e($o['customer_note'])) . '</p>' : '')
        . ($o['paypal_order_id'] !== '' ? '<p class="muted">PayPal order ' . e($o['paypal_order_id'])
            . ($o['paypal_capture_id'] !== '' ? ' · capture ' . e($o['paypal_capture_id']) : '') . '</p>' : '')
        . $mails
        . '<h2>History</h2><p class="muted">' . nl2br(e($o['admin_note'] ?: 'Nothing yet.')) . '</p>'
        . '<p class="muted"><a href="' . e(shop_url('order/' . $o['token'])) . '" target="_blank" rel="noopener">The customer\'s order page &rarr;</a></p>');
}

function admin_order_action(int $id): never
{
    require_admin();
    csrf_check();
    $o = order_by('id', $id);
    if (!$o) {
        redirect(admin_url('orders'));
    }
    $oid = (int)$o['id'];
    if ((int)$o['imported']) {
        flash('Orders from your old shop are a record only.');
        redirect(admin_url('order/' . $oid));
    }
    switch (post('do')) {
        case 'paid':
            if (order_mark_paid($oid, '', method_label($o['payment_method']) . ', marked by the shop')) {
                flash('Order #' . $o['number'] . ' marked as paid.');
            }
            break;
        case 'paid_collected':
            if (order_mark_paid($oid, '', 'paid on collection')) {
                db()->prepare("UPDATE orders SET fulfilled = 'collected', fulfilled_at = ?, updated = ? WHERE id = ? AND fulfilled = ''")->execute([now(), now(), $oid]);
                order_note($oid, 'Collected');
                flash('Order #' . $o['number'] . ' paid and collected.');
            }
            break;
        case 'sent':
        case 'collected':
            $st = db()->prepare("UPDATE orders SET fulfilled = ?, fulfilled_at = ?, tracking = ?, updated = ?
                                 WHERE id = ? AND status = 'paid' AND fulfilled = ''");
            $st->execute([post('do'), now(), post('tracking'), now(), $oid]);
            if ($st->rowCount() === 1) {
                order_note($oid, post('do') === 'sent' ? 'Sent' . (post('tracking') !== '' ? ' (' . post('tracking') . ')' : '') : 'Collected');
                if (post('do') === 'sent') {
                    mail_order_event($oid, 'sent');
                }
                flash('Order #' . $o['number'] . ' marked as ' . post('do') . '.'
                      . (post('do') === 'sent' ? (mail_ready() ? ' The customer has been emailed.' : ' (Email isn\'t set up, so the customer hasn\'t been told.)') : ''));
            }
            break;
        case 'cancel':
            if ($o['status'] === 'pending' && $o['paypal_order_id'] !== '') {   // ask PayPal first: it may have just been paid
                $r = paypal_reconcile($o);
                if ($r === 'paid') {
                    flash('PayPal says order #' . $o['number'] . ' has just been paid, so it wasn\'t cancelled.');
                    break;
                }
                if (!in_array($r, ['waiting', 'cancelled'], true)) {
                    flash('The shop couldn\'t confirm with PayPal that nothing was paid, so order #' . $o['number'] . ' wasn\'t cancelled. Try again in a moment.');
                    break;
                }
            }
            if (order_cancel($oid, 'Cancelled by the shop.')) {
                if ($o['status'] !== 'pending') {   // someone who never finished paying at PayPal doesn't need telling
                    mail_order_event($oid, 'cancelled');
                }
                flash('Order #' . $o['number'] . ' cancelled and its stock put back.');
            }
            break;
        case 'restore':   // e.g. a bank transfer arrived after the order lapsed
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            $cur = order_by('id', $oid);
            $short = [];
            foreach (order_items($oid) as $it) {
                $st = $it['variant_id'] !== null ? $pdo->prepare('SELECT stock FROM variants WHERE id = ?') : $pdo->prepare('SELECT stock FROM products WHERE id = ?');
                $st->execute([$it['variant_id'] ?? $it['product_id']]);
                $s = $st->fetchColumn();
                if ($s !== false && $s !== null && (int)$s < (int)$it['qty']) {
                    $short[] = $it['name'] . ($it['option_label'] !== '' ? ' (' . $it['option_label'] . ')' : '');
                }
            }
            if ($cur['status'] !== 'cancelled' || $cur['paid_at'] !== '' || $cur['payment_method'] === 'paypal' || $short) {
                $pdo->exec('ROLLBACK');
                flash($short ? 'Not enough stock left to restore it: ' . implode(', ', $short) . '. Add stock first.' : 'Only a cancelled, unpaid bank or collection order can be restored.');
                break;
            }
            foreach (order_items($oid) as $it) {
                $it['variant_id'] !== null
                    ? $pdo->prepare('UPDATE variants SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([$it['qty'], $it['variant_id']])
                    : $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([$it['qty'], $it['product_id']]);
            }
            $pdo->prepare("UPDATE orders SET status = ?, cancelled_at = '', updated = ? WHERE id = ?")
                ->execute([$cur['payment_method'] === 'bank' ? 'awaiting_payment' : 'pay_on_collection', now(), $oid]);
            order_note($oid, 'Restored by the shop, stock taken again.');
            $pdo->exec('COMMIT');
            flash('Order #' . $cur['number'] . ' restored. Mark it paid when the money arrives.');
            break;
        case 'resend':
            $m = db()->prepare('SELECT * FROM emails WHERE id = ? AND order_id = ?');
            $m->execute([(int)post('email_id'), $oid]);
            if ($m = $m->fetch()) {
                if ($m['body_html'] === '') {   // contents tidied away: write it afresh
                    $id2 = mail_order_event($oid, $m['kind']);
                    if (!$id2) {
                        flash('That email can\'t be written again.');
                        break;
                    }
                } elseif ($m['status'] === 'sent') {   // a fresh copy, so the history stays true
                    mail_queue($oid, $m['kind'], $m['to_addr'], $m['subject'], $m['body_text'], $m['body_html']);
                    $id2 = (int)db()->lastInsertId();
                } else {
                    db()->prepare("UPDATE emails SET status = 'queued', tries = 0, created = ? WHERE id = ?")->execute([now(), $m['id']]);
                    $id2 = (int)$m['id'];
                }
                $sent = mail_flush($id2);
                $err = db()->query('SELECT error FROM emails WHERE id = ' . $id2)->fetchColumn();
                flash($sent ? 'Email sent to ' . $m['to_addr'] . '.' : 'The email didn\'t go: ' . ($err ?: 'email isn\'t set up') . '.');
            }
            break;
        case 'check':
            $r = $o['paypal_order_id'] !== '' ? paypal_reconcile($o) : 'none';
            flash(['paid' => 'PayPal confirmed the payment: order marked paid.', 'cancelled' => 'PayPal has no finished payment, so the order was cancelled.',
                   'waiting' => 'The customer hasn\'t finished paying at PayPal yet.', 'error' => 'Couldn\'t reach PayPal. Try again shortly.'][$r]
                  ?? 'PayPal says: ' . $r . '.');
            break;
        case 'refund':
            if ($o['status'] === 'paid' && $o['paypal_capture_id'] !== '') {
                $err = null;
                $j = paypal_api('POST', '/v2/payments/captures/' . rawurlencode($o['paypal_capture_id']) . '/refund', [], 'refund-' . $o['token'], $err);
                if ($j && in_array($j['status'] ?? '', ['COMPLETED', 'PENDING'], true)) {
                    // PayPal only refunds once for this request id; this makes sure we only record it once too.
                    if (order_mark_refunded($oid, !empty($_POST['restock']), 'Refunded through PayPal (' . ($j['id'] ?? '') . ')')) {
                        flash('Refunded through PayPal.');
                    }
                } else {
                    flash(refund_error_text((string)($err ?? 'unknown')));
                }
            }
            break;
        case 'refunded':
            if (order_mark_refunded($oid, !empty($_POST['restock']), 'Marked as refunded')) {
                flash('Order #' . $o['number'] . ' marked as refunded.');
            }
            break;
    }
    redirect(admin_url('order/' . $oid));
}

/* =================================================================== email */
/* Order emails go into an outbox (the emails table) and are sent over SMTP
   after the page has been delivered, so a slow mail server never slows a
   customer down. Anything that fails is retried a few times, and every email
   is listed on the order in the admin with a Resend button. */

const MAIL_TRIES = 5;

function mail_ready(): bool
{
    return setting('smtp_host') !== '' && setting('mail_from') !== '';
}

function mail_clean(string $s): string   // header values: no line breaks, ever
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

function mail_encode_header(string $s): string
{
    $s = mail_clean($s);
    // RFC 2047: encoded words of at most 75 characters, folded onto continuation lines
    return preg_match('/[^\x20-\x7e]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n", 9) : $s;
}

function mail_from_name(): string
{
    return setting('mail_from_name') ?: (setting('business_name') ?: setting('shop_title', 'Shop'));
}

/* Build the full message (headers + multipart text/HTML body), CRLF line endings. */
function mail_build(string $to, string $subject, string $text, string $html): string
{
    $from = mail_clean(setting('mail_from'));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $b = 'b' . bin2hex(random_bytes(12));
    $replyTo = setting('business_email');
    $h = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mail_encode_header(mail_from_name()) . ' <' . $from . '>',
        'To: <' . mail_clean($to) . '>',
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
        'X-Mailer: Wren Shop ' . SHOP_VERSION,
    ];
    if ($replyTo !== '' && strcasecmp($replyTo, $from) !== 0) {
        $h[] = 'Reply-To: <' . mail_clean($replyTo) . '>';
    }
    $part = fn(string $type, string $body) => "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], "\r\n", $body)) . "\r\n";
    return implode("\r\n", $h) . "\r\n\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--$b--\r\n";
}

/* ------------------------------------------------------------- SMTP client */

final class SmtpError extends Exception {}

final class Smtp
{
    private $s;
    private array $ext = [];

    public function __construct(private string $host, private int $port, private string $security, private int $timeout = 15) {}

    private function read(): array
    {
        $lines = [];
        while (($line = fgets($this->s, 1024)) !== false) {
            $lines[] = rtrim($line);
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if (!$lines) {
            throw new SmtpError('the mail server closed the connection');
        }
        return [(int)substr($lines[0], 0, 3), $lines];
    }

    private function cmd(string $line, array $want, string $show = ''): array
    {
        fwrite($this->s, $line . "\r\n");
        [$code, $lines] = $this->read();
        if (!in_array($code, $want, true)) {
            $said = preg_replace('/^\d{3}[ -]/', '', end($lines));
            throw new SmtpError(($show ?: strtok($line, ' ')) . ' was refused: ' . $code . ' ' . mb_substr($said, 0, 200));
        }
        return $lines;
    }

    private function ehlo(): void
    {
        $name = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
        $this->ext = [];
        foreach ($this->cmd('EHLO ' . $name, [250]) as $i => $l) {
            if ($i > 0) {
                $this->ext[] = strtoupper(substr($l, 4));
            }
        }
    }

    private function has(string $ext): ?string
    {
        foreach ($this->ext as $e) {
            if ($e === $ext || str_starts_with($e, $ext . ' ')) {
                return $e;
            }
        }
        return null;
    }

    public function open(string $user, #[\SensitiveParameter] string $pass): void
    {
        $ctx = stream_context_create(['ssl' => ['peer_name' => $this->host, 'verify_peer' => true, 'verify_peer_name' => true,
                                                'SNI_enabled' => true]]);
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $s = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $no, $msg, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            if ($this->security === 'ssl' && !$no) {
                throw new SmtpError('couldn\'t make a secure connection to ' . $this->host . ':' . $this->port
                    . ' (the server name must match its certificate, often mail.yourdomain; for port 587 choose STARTTLS)');
            }
            throw new SmtpError('couldn\'t connect to ' . $this->host . ':' . $this->port . ' - ' . ($msg ?: 'no reply'));
        }
        $this->s = $s;
        stream_set_timeout($s, $this->timeout);
        [$code] = $this->read();
        if ($code !== 220) {
            throw new SmtpError('the mail server isn\'t ready (' . $code . ')');
        }
        $this->ehlo();
        if ($this->security === 'starttls') {
            if (!$this->has('STARTTLS')) {
                throw new SmtpError('the server doesn\'t offer STARTTLS on this port (for port 465 choose SSL/TLS)');
            }
            $this->cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new SmtpError('the secure connection couldn\'t be set up (certificate problem?)');
            }
            $this->ehlo();
        }
        if ($user !== '') {
            $auth = (string)$this->has('AUTH');
            if (str_contains($auth, 'PLAIN')) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235], 'Sign-in');
            } elseif (str_contains($auth, 'LOGIN')) {
                $this->cmd('AUTH LOGIN', [334], 'Sign-in');
                $this->cmd(base64_encode($user), [334], 'Sign-in');
                $this->cmd(base64_encode($pass), [235], 'Sign-in (username or password)');
            } else {
                throw new SmtpError('the server doesn\'t offer a way to sign in on this connection');
            }
        }
    }

    public function send(string $from, string $to, string $message): void
    {
        $this->cmd('MAIL FROM:<' . $from . '>', [250], 'The from address');
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251], 'The address ' . $to);
        $this->cmd('DATA', [354]);
        $message = preg_replace('/^\./m', '..', $message);      // dot-stuffing
        fwrite($this->s, $message . "\r\n.\r\n");
        [$code, $lines] = $this->read();
        if ($code !== 250) {
            throw new SmtpError('the server didn\'t accept the email: ' . $code . ' ' . mb_substr(end($lines), 4, 200));
        }
    }

    public function close(): void
    {
        if ($this->s) {
            @fwrite($this->s, "QUIT\r\n");
            @fclose($this->s);
            $this->s = null;
        }
    }
}

function smtp_connect(): Smtp
{
    $sec = setting('smtp_security', 'ssl');
    $smtp = new Smtp(setting('smtp_host'), (int)setting('smtp_port', '465'), in_array($sec, ['ssl', 'starttls', 'none'], true) ? $sec : 'ssl');
    $smtp->open(setting('smtp_user'), setting('smtp_pass'));
    return $smtp;
}

/* ------------------------------------------------------------------ outbox */

function mail_queue(?int $oid, string $kind, string $to, string $subject, string $text, string $html): ?int
{
    // Links in emails must point at the real site. Until the shop has learnt its address
    // (from the owner's own visit to the admin), it doesn't send any.
    if (setting('site_url') === '') {
        if ($oid) {
            order_note($oid, 'Email "' . $subject . '" not sent: open the shop admin once so the shop knows its web address.');
        }
        return null;
    }
    db()->prepare("INSERT INTO emails (order_id, kind, to_addr, subject, body_text, body_html, status, created)
                   VALUES (?, ?, ?, ?, ?, ?, 'queued', ?)")->execute([$oid, $kind, mail_clean($to), $subject, $text, $html, now()]);
    $id = (int)db()->lastInsertId();
    mail_send_later();
    return $id;
}

/* Send the outbox once the page has gone to the browser. */
function mail_send_later(): void
{
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(60);
        mail_flush();
    });
}

/* Send queued emails, and retry failed ones (spaced out). Returns how many went. */
function mail_flush(?int $onlyId = null): int
{
    if (!mail_ready()) {
        return 0;
    }
    $pdo = db();
    // never surprise a customer with something days out of date (e.g. queued before email was set up)
    $pdo->exec("UPDATE emails SET status = 'failed', tries = " . MAIL_TRIES . ", error = 'not sent: more than 2 days old'
                WHERE status IN ('queued', 'failed') AND created < datetime('now', '-2 days')");
    if ($onlyId !== null) {
        $st = $pdo->prepare("SELECT * FROM emails WHERE id = ? AND status != 'sent'");
        $st->execute([$onlyId]);
    } else {
        // retry a failure after 2, 4, 8, 16 minutes
        $st = $pdo->prepare("SELECT * FROM emails WHERE status = 'queued'
                             OR (status = 'failed' AND tries < ? AND tried_at < datetime('now', '-' || (1 << tries) || ' minutes'))
                             ORDER BY id LIMIT 20");
        $st->execute([MAIL_TRIES]);
    }
    $list = $st->fetchAll();
    if (!$list) {
        return 0;
    }
    $sent = 0;
    $smtp = null;
    foreach ($list as $m) {
        // claim it, so two requests flushing at once can't both send it
        $claim = $pdo->prepare("UPDATE emails SET status = 'sending', tried_at = ? WHERE id = ? AND status = ?");
        $claim->execute([now(), $m['id'], $m['status']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        try {
            $smtp ??= smtp_connect();
            $smtp->send(mail_clean(setting('mail_from')), $m['to_addr'], mail_build($m['to_addr'], $m['subject'], $m['body_text'], $m['body_html']));
            $pdo->prepare("UPDATE emails SET status = 'sent', sent_at = ?, error = '', tries = tries + 1 WHERE id = ?")->execute([now(), $m['id']]);
            if (str_starts_with($m['kind'], 'account')) {   // reset and confirmation links: never keep a working copy
                $pdo->prepare("UPDATE emails SET body_text = '', body_html = '' WHERE id = ?")->execute([$m['id']]);
            }
            $sent++;
        } catch (Throwable $e) {
            $msg = $e instanceof SmtpError ? $e->getMessage() : 'unexpected error';
            $pdo->prepare("UPDATE emails SET status = 'failed', error = ?, tries = tries + 1 WHERE id = ?")->execute([$msg, $m['id']]);
            $couldConnect = $smtp !== null;
            $smtp?->close();
            $smtp = null;                 // start a clean connection for the next one
            if (!$couldConnect) {
                break;                    // can't reach or sign in to the server: the rest wait for next time
            }
        }
    }
    $smtp?->close();
    // an email stuck in "sending" (the request died mid-send) goes back in the queue after 10 minutes
    $pdo->exec("UPDATE emails SET status = 'failed', error = 'interrupted while sending'
                WHERE status = 'sending' AND tried_at < datetime('now', '-10 minutes')");
    return $sent;
}

function mail_failed_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM emails WHERE status = 'failed' AND tries >= " . MAIL_TRIES)->fetchColumn();
}

/* --------------------------------------------------------- the order emails */

function mail_shop_address(): string
{
    return setting('mail_notify') ?: (setting('business_email') ?: setting('mail_from'));
}

/* One email layout for everything: a heading, some paragraphs, the order and a button. */
function mail_layout(string $heading, array $paras, string $orderHtml, string $buttonUrl, string $buttonText, string $footer): string
{
    $p = '';
    foreach ($paras as $x) {
        $p .= '<p style="margin:0 0 14px">' . $x . '</p>';
    }
    return '<!doctype html><html><body style="margin:0;padding:0;background:#f3f4f6">'
        . '<div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1f2328">'
        . '<div style="background:#fff;border-radius:10px;padding:28px 24px">'
        . '<div style="font-size:14px;color:#6b7280;margin-bottom:18px">' . e(mail_from_name()) . '</div>'
        . '<h1 style="font-size:22px;line-height:1.25;margin:0 0 16px">' . $heading . '</h1>' . $p
        . ($buttonUrl !== '' ? '<p style="margin:22px 0"><a href="' . e($buttonUrl) . '" style="display:inline-block;background:#1f2328;color:#fff;text-decoration:none;padding:11px 20px;border-radius:7px;font-weight:600">' . e($buttonText) . '</a></p>' : '')
        . $orderHtml . '</div>'
        . '<div style="font-size:13px;color:#6b7280;padding:16px 8px;line-height:1.5">' . $footer . '</div>'
        . '</div></body></html>';
}

function mail_order_table(array $o): array
{
    $rows = '';
    $text = '';
    $td = 'padding:8px 0;border-bottom:1px solid #e5e7eb;vertical-align:top';
    foreach (order_items((int)$o['id']) as $it) {
        $line = (int)$it['unit_pence'] * (int)$it['qty'];
        $bo = ((int)($it['backorder'] ?? 0) > 0 && product_tells_backorders((int)$it['product_id'])) ? backorder_note((int)$it['backorder'], (int)$it['qty']) : '';
        $name = $it['name'] . ($it['option_label'] !== '' ? ' (' . $it['option_label'] . ')' : '') . (($it['sku'] ?? '') !== '' ? ' [' . $it['sku'] . ']' : '')
              . ($bo !== '' ? ' - ' . $bo : '');
        $rows .= '<tr><td style="' . $td . '">' . e($it['name']) . ($it['option_label'] !== '' ? '<br><span style="color:#6b7280;font-size:14px">' . e($it['option_label']) . '</span>' : '')
               . (($it['sku'] ?? '') !== '' ? '<br><span style="color:#6b7280;font-size:13px">' . e($it['sku']) . '</span>' : '')
               . ($bo !== '' ? '<br><span style="color:#8a5d10;font-size:14px;font-weight:600">' . e($bo) . ': we\'ll send ' . ((int)$it['backorder'] >= (int)$it['qty'] ? 'it' : 'the rest') . ' when it\'s back in stock</span>' : '')
               . '</td><td style="' . $td . ';text-align:right;white-space:nowrap;padding-left:12px">× ' . (int)$it['qty']
               . '</td><td style="' . $td . ';text-align:right;white-space:nowrap;padding-left:12px">' . money($line) . '</td></tr>';
        $text .= (int)$it['qty'] . ' × ' . $name . '  ' . money($line) . "\n";
    }
    $post = (int)$o['postage_pence'];
    $postLabel = order_post_label($o) . ($o['delivery_zone'] !== '' ? ' (' . $o['delivery_zone'] . ')' : '');
    $sum = fn(string $l, string $v, bool $b = false) => '<tr><td colspan="2" style="padding:6px 0;text-align:right' . ($b ? ';font-weight:700' : '') . '">' . $l
        . '</td><td style="padding:6px 0 6px 12px;text-align:right;white-space:nowrap' . ($b ? ';font-weight:700' : '') . '">' . $v . '</td></tr>';
    $disc = $o['discount_code'] !== '' ? ((int)$o['discount_pence'] > 0 ? '−' . money((int)$o['discount_pence']) : 'Free delivery') : '';
    $rows .= $sum('Subtotal', money((int)$o['subtotal_pence']))
           . ($disc !== '' ? $sum('Discount (' . e($o['discount_code']) . ')', $disc) : '')
           . ((int)$o['group_pence'] > 0 ? $sum(e($o['group_name']) . ' prices', '−' . money((int)$o['group_pence'])) : '')
           . ($postLabel !== '' ? $sum(e($postLabel), $post ? money($post) : 'Free') : '')
           . $sum('Total', money((int)$o['total_pence']), true);
    $text .= "\nSubtotal  " . money((int)$o['subtotal_pence'])
           . ($disc !== '' ? "\nDiscount (" . $o['discount_code'] . ")  " . $disc : '')
           . ((int)$o['group_pence'] > 0 ? "\n" . $o['group_name'] . ' prices  −' . money((int)$o['group_pence']) : '')
           . ($postLabel !== '' ? "\n$postLabel  " . ($post ? money($post) : 'Free') : '')
           . "\nTotal  " . money((int)$o['total_pence']) . "\n";
    if ($o['vat_pence'] !== null) {
        $rows .= '<tr><td colspan="3" style="padding:2px 0;text-align:right;color:#6b7280;font-size:14px">Includes VAT of ' . money((int)$o['vat_pence'])
               . ($o['vat_number'] !== '' ? ' · VAT number ' . e($o['vat_number']) : '') . '</td></tr>';
        $text .= 'Includes VAT of ' . money((int)$o['vat_pence']) . ($o['vat_number'] !== '' ? ' (VAT number ' . $o['vat_number'] . ')' : '') . "\n";
    }
    return ['<table style="width:100%;border-collapse:collapse;font-size:15px;margin-top:8px">' . $rows . '</table>', $text];
}

function mail_address_lines(string $prefix, array $o): array
{
    return array_values(array_filter([$o[$prefix . 'name'], $o[$prefix . 'line1'], $o[$prefix . 'line2'], $o[$prefix . 'town'], $o[$prefix . 'postcode']],
                                     fn($x) => trim((string)$x) !== ''));
}

function mail_footer(): array
{
    $bits = array_filter([setting('business_name'), str_replace(["\r\n", "\n"], ', ', trim(setting('business_address')))]);
    $contact = setting('business_email');
    $html = e(implode(' · ', $bits)) . ($contact !== '' ? '<br>Questions? Just reply to this email or write to <a href="mailto:' . e($contact) . '" style="color:#6b7280">' . e($contact) . '</a>.' : '');
    $text = implode("\n", $bits) . ($contact !== '' ? "\nQuestions? Reply to this email or write to $contact." : '');
    return [$html, $text];
}

/* Queue the email(s) for something that just happened to an order. */
function mail_order_event(int $oid, string $event): ?int
{
    $o = order_by('id', $oid);
    if (!$o) {
        return null;
    }
    $n = '#' . $o['number'];
    $url = abs_shop_url('order/' . $o['token']);
    [$table, $tableText] = mail_order_table($o);
    [$foot, $footText] = mail_footer();
    $collect = $o['delivery'] === 'collect';
    $where = $o['delivery'] === 'none'
        ? ['Your downloads', [in_array($o['status'], ['paid'], true) ? 'Ready in your account: ' . abs_shop_url('account/licences')
                                                                    : 'In your account under Downloads and licences as soon as payment arrives.']]
        : ($collect
        ? ['Collection', collect_note_for($o) !== '' ? explode("\n", str_replace("\r", '', collect_note_for($o))) : ['We\'ll be in touch about collection.']]
        : ['Delivery address', mail_address_lines('ship_', $o)]);
    $whereHtml = '<h2 style="font-size:16px;margin:22px 0 6px">' . $where[0] . '</h2><p style="margin:0">' . implode('<br>', array_map('e', $where[1])) . '</p>';
    $whereText = "\n" . strtoupper($where[0]) . "\n" . implode("\n", $where[1]) . "\n";

    $paras = [];
    $extraHtml = '';
    $extraText = '';
    $customer = true;
    switch ($event) {
        case 'placed_bank':
            $subject = 'Order ' . $n . ': please pay by bank transfer';
            $heading = 'Thanks for your order';
            $paras[] = 'Order <strong>' . $n . '</strong> is reserved for you. Please pay <strong>' . money((int)$o['total_pence']) . '</strong> by bank transfer'
                     . (unpaid_days() > 0 ? ' by <strong>' . e(pay_by_date($o)) . '</strong>' : '') . ' and we\'ll send it once your payment arrives.'
                     . (unpaid_days() > 0 ? ' If it hasn\'t arrived by then, the order is cancelled.' : '');
            $bank = [['Account name', setting('bank_account_name')], ['Sort code', setting('bank_sort_code')], ['Account number', setting('bank_account')],
                     ['Reference', (string)$o['number']], ['Amount', money((int)$o['total_pence'])]];
            $extraHtml = '<table style="border-collapse:collapse;background:#f3f4f6;border-radius:8px;width:100%;margin:4px 0 8px">';
            foreach ($bank as [$k, $v]) {
                $extraHtml .= '<tr><td style="padding:6px 14px;color:#6b7280">' . $k . '</td><td style="padding:6px 14px;font-weight:600">' . e($v) . '</td></tr>';
                $extraText .= "$k: $v\n";
            }
            $extraHtml .= '</table>' . (trim(setting('bank_note')) !== '' ? '<p style="margin:8px 0 14px">' . nl2br(e(setting('bank_note'))) . '</p>' : '');
            $extraText .= trim(setting('bank_note')) !== '' ? trim(setting('bank_note')) . "\n" : '';
            break;
        case 'placed_collection':
            $subject = 'Order ' . $n . ' is reserved for you';
            $heading = 'Thanks for your order';
            $paras[] = 'Order <strong>' . $n . '</strong> is reserved for you. Please pay <strong>' . money((int)$o['total_pence']) . '</strong> when you collect it.';
            break;
        case 'paid':        // PayPal, confirmed
            $subject = 'Order ' . $n . ' confirmed';
            $heading = 'Thank you. Your order is confirmed';
            $paras[] = 'We\'ve received your payment for order <strong>' . $n . '</strong>. ' . ($o['delivery'] === 'none' ? 'Your downloads are ready in your account.' : ($collect ? 'We\'ll let you know when it\'s ready to collect.' : 'We\'ll email you when it\'s on its way.'));
            break;
        case 'payment_received':   // bank transfer marked paid
            $subject = 'Payment received for order ' . $n;
            $heading = 'Payment received';
            $paras[] = 'Thanks, your payment for order <strong>' . $n . '</strong> has arrived. ' . ($o['delivery'] === 'none' ? 'Your downloads are ready in your account.' : ($collect ? 'We\'ll let you know when it\'s ready to collect.' : 'We\'ll email you when it\'s on its way.'));
            break;
        case 'sent':
            $subject = 'Order ' . $n . ' is on its way';
            $heading = 'Your order is on its way';
            $paras[] = 'We\'ve sent order <strong>' . $n . '</strong>.';
            if ($o['tracking'] !== '') {
                $isLink = preg_match('#^https?://\S+$#i', $o['tracking']);
                $paras[] = 'Tracking: ' . ($isLink ? '<a href="' . e($o['tracking']) . '">' . e($o['tracking']) . '</a>' : '<strong>' . e($o['tracking']) . '</strong>');
            }
            break;
        case 'cancelled':
            $subject = 'Order ' . $n . ' has been cancelled';
            $heading = 'Your order has been cancelled';
            $paras[] = 'Order <strong>' . $n . '</strong> has been cancelled and nothing has been charged. If this is a surprise, please get in touch.';
            break;
        case 'refunded':
            $subject = 'Refund for order ' . $n;
            $heading = 'Your refund';
            $paras[] = 'We\'ve refunded <strong>' . money((int)$o['total_pence']) . '</strong> for order <strong>' . $n . '</strong>. '
                     . ($o['payment_method'] === 'paypal' ? 'It goes back to the PayPal account or card you paid with, usually within a few days.' : '');
            break;
        case 'paid_after_cancel':   // to the shop
            $customer = false;
            $subject = 'Check order ' . $n . ': PayPal took payment after it was cancelled';
            $heading = 'Payment on a cancelled order';
            $paras[] = 'PayPal took <strong>' . money((int)$o['total_pence']) . '</strong> for order ' . $n . ' from ' . e($o['bill_name'])
                     . ', but the order had already been cancelled (its stock went back on sale). Please refund it in PayPal, or contact the customer and send the order.';
            break;
        case 'new_order':   // to the shop
            $customer = false;
            $how = ['pending' => 'paid by PayPal', 'paid' => 'paid by PayPal', 'awaiting_payment' => 'waiting for a bank transfer',
                    'pay_on_collection' => 'to be paid on collection'][$o['status']] ?? $o['status'];
            $subject = 'New order ' . $n . ' - ' . money((int)$o['total_pence']) . ' (' . $how . ')';
            $heading = 'New order ' . $n;
            $paras[] = e($o['bill_name']) . ' ordered ' . money((int)$o['total_pence']) . ', ' . $how . '. ' . ($o['delivery'] === 'none' ? 'Downloads only.' : ($collect ? 'They\'ll collect.' : 'To be sent.'));
            $paras[] = 'Email: <a href="mailto:' . e($o['email']) . '">' . e($o['email']) . '</a>' . ($o['phone'] !== '' ? '<br>Phone: ' . e($o['phone']) : '');
            if ($o['customer_note'] !== '') {
                $paras[] = 'Their note: ' . nl2br(e($o['customer_note']));
            }
            break;
        default:
            return null;
    }
    if ($customer) {
        $btnUrl = $url;
        $btnText = 'View your order';
        $html = mail_layout(e($heading), $paras, $extraHtml . $table . $whereHtml, $btnUrl, $btnText, $foot);
        $text = $heading . "\n\n" . strip_tags(implode("\n\n", $paras)) . "\n\n" . $extraText . ($extraText !== '' ? "\n" : '')
              . "YOUR ORDER $n\n" . $tableText . $whereText . "\nView your order: $url\n\n" . $footText . "\n";
        return mail_queue($oid, $event, $o['email'], $subject, html_entity_decode($text, ENT_QUOTES, 'UTF-8'), $html);
    } else {
        $to = mail_shop_address();
        if ($to === '') {
            return null;
        }
        $admin = origin() . admin_url('order/' . $o['id']);
        $html = mail_layout(e($heading), $paras, $table . $whereHtml, $admin, 'Open the order', 'Sent by Wren Shop.');
        $text = $heading . "\n\n" . html_entity_decode(strip_tags(implode("\n\n", $paras)), ENT_QUOTES, 'UTF-8') . "\n\n" . $tableText . $whereText . "\nOpen the order: $admin\n";
        return mail_queue($oid, $event, $to, $subject, $text, $html);
    }
}

/* ------------------------------------------------------------ packing slip */

function view_packing_slip(int $id): never
{
    require_admin();
    $o = order_by('id', $id);
    if (!$o) {
        redirect(admin_url('orders'));
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $rows = '';
    $items = order_items((int)$o['id']);
    $skus = (bool)array_filter($items, fn($it) => ($it['sku'] ?? '') !== '');   // no SKU column in a shop without SKUs
    foreach ($items as $it) {
        $rows .= '<tr><td class="tick"></td><td class="qty">' . (int)$it['qty'] . '</td><td>' . e($it['name'])
               . ($it['option_label'] !== '' ? '<br><span class="muted">' . e($it['option_label']) . '</span>' : '')
               . ((int)($it['backorder'] ?? 0) > 0 ? '<br><b>' . (int)$it['backorder'] . ' on backorder when ordered</b>' : '') . '</td>' . ($skus ? '<td class="sku">' . e($it['sku'] ?? '') . '</td>' : '') . '</tr>';
    }
    $to = $o['delivery'] === 'collect' ? array_merge([$o['bill_name']], ['(collecting)']) : mail_address_lines('ship_', $o);
    $biz = array_filter(array_merge([setting('business_name') ?: setting('shop_title', 'Shop')], explode("\n", str_replace("\r", '', trim(setting('business_address')))),
                                    [setting('business_email')]));
    echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Packing slip #' . (int)$o['number'] . '</title><style>
body{font:15px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:0;padding:24px;background:#eee}
.page{background:#fff;max-width:720px;margin:0 auto;padding:40px}
.top{display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap}
h1{font-size:24px;margin:0 0 4px}.muted{color:#666;font-size:13px}
.to{border:1.5px solid #111;border-radius:6px;padding:14px 18px;font-size:17px;line-height:1.5;min-width:240px}
table{width:100%;border-collapse:collapse;margin:28px 0}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #ccc;vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#666}.qty{width:3em;font-weight:700;font-size:17px}
.tick{width:22px}.tick::before{content:"";display:inline-block;width:14px;height:14px;border:1.5px solid #111;border-radius:2px}
.sku{font-family:ui-monospace,Menlo,monospace;font-size:13px;white-space:nowrap}
.note{background:#f4f4f4;padding:12px 16px;border-radius:6px}.bar{max-width:720px;margin:0 auto 12px;text-align:right}
button{font:inherit;padding:8px 16px;border-radius:6px;border:1px solid #111;background:#111;color:#fff;cursor:pointer}
@media print{body{background:#fff;padding:0}.page{padding:0;max-width:none}.bar{display:none}}
</style></head><body><div class="bar"><button onclick="window.print()">Print</button></div><div class="page">
<div class="top"><div><h1>Order #' . (int)$o['number'] . '</h1><div class="muted">Placed ' . e(gmdate('j F Y', strtotime($o['created'] . ' UTC')))
        . ' · ' . e(order_kind_label($o)) . '</div></div>
<div class="to">' . implode('<br>', array_map('e', $to)) . '</div></div>
<table><thead><tr><th></th><th>Qty</th><th>Item</th>' . ($skus ? '<th>SKU</th>' : '') . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . ($o['customer_note'] !== '' ? '<p class="note"><strong>Customer\'s note:</strong> ' . nl2br(e($o['customer_note'])) . '</p>' : '')
        . '<p class="muted" style="margin-top:36px">' . implode(' · ', array_map('e', $biz)) . '</p>
<p class="muted">Thank you for your order.</p></div></body></html>';
    exit;
}
/* ======================================================= customer accounts */
/* Optional accounts for customers. Guests can always check out without one.
   Separate from the shop admin's sign-in in every way: own table, own session
   keys, own "keep me signed in" cookie. Passwords are only stored hashed.
   An account sees the orders placed while signed in to it, plus guest orders
   with its email address once that address has been confirmed (so nobody can
   register someone else's email and read their orders). */

const CUSTOMER_REMEMBER_DAYS = 60;
const CUSTOMER_PASS_MIN = 8;

function accounts_on(): bool
{
    return setting('accounts_on', '1') === '1';
}

/* ------------------------------------------------------------- throttling */

/* True when $key has had $max hits in the last $window seconds. */
function throttled(string $key, int $max, int $window): bool
{
    db()->prepare('DELETE FROM throttle WHERE at < ?')->execute([time() - 86400]);
    $st = db()->prepare('SELECT COUNT(*) FROM throttle WHERE k = ? AND at >= ?');
    $st->execute([$key, time() - $window]);
    return (int)$st->fetchColumn() >= $max;
}

function throttle_hit(string $key): void
{
    db()->prepare('INSERT INTO throttle (k, at) VALUES (?, ?)')->execute([$key, time()]);
}

/* ------------------------------------------------------------- the account */

function customer_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function customer_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM customers WHERE email = ? COLLATE NOCASE');
    $st->execute([trim($email)]);
    return $st->fetch() ?: null;
}

function customer_cookie(string $value, int $expires): void
{
    setcookie('wrenshop_me', $value, [
        'expires' => $expires, 'path' => shop_base() . '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => is_https() || str_starts_with(origin(), 'https:'),
    ]);
}

function customer_remember_issue(int $cid): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + CUSTOMER_REMEMBER_DAYS * 86400;
    db()->prepare('INSERT INTO customer_remember (selector, customer_id, hash, expires) VALUES (?, ?, ?, ?)')
        ->execute([$selector, $cid, hash('sha256', $validator), $expires]);
    customer_cookie($selector . '.' . $validator, $expires);
}

function customer_sign_in(array $c, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['shop_cid'] = (int)$c['id'];
    $_SESSION['shop_cgen'] = (int)$c['session_gen'];
    db()->prepare('UPDATE customers SET last_login = ? WHERE id = ?')->execute([now(), $c['id']]);
    if ($remember) {
        customer_remember_issue((int)$c['id']);
    }
}

function customer_sign_out(): void
{
    $c = str_in($_COOKIE['wrenshop_me'] ?? '');
    if (str_contains($c, '.')) {
        db()->prepare('DELETE FROM customer_remember WHERE selector = ?')->execute([explode('.', $c, 2)[0]]);
    }
    customer_cookie('', time() - 3600);
    unset($_SESSION['shop_cid'], $_SESSION['shop_cgen'], $_SESSION['shop_checkout']);
    session_regenerate_id(true);
}

/* Sign out every device: other sessions see the generation change, and all
   "keep me signed in" tokens go. */
function customer_sign_out_everywhere(int $cid): void
{
    db()->prepare('UPDATE customers SET session_gen = session_gen + 1 WHERE id = ?')->execute([$cid]);
    db()->prepare('DELETE FROM customer_remember WHERE customer_id = ?')->execute([$cid]);
}

function current_customer(bool $fresh = false): ?array
{
    static $done = false, $c = null;
    if ($done && !$fresh) {
        return $c;
    }
    $done = true;
    $c = null;
    if (!accounts_on()) {
        return null;
    }
    if (!empty($_SESSION['shop_cid'])) {
        $row = customer_by_id((int)$_SESSION['shop_cid']);
        if ($row && (int)$row['session_gen'] === (int)($_SESSION['shop_cgen'] ?? -1)) {
            return $c = $row;
        }
        unset($_SESSION['shop_cid'], $_SESSION['shop_cgen']);
    }
    $k = str_in($_COOKIE['wrenshop_me'] ?? '');
    if (preg_match('/^([a-f0-9]{18})\.([a-f0-9]{64})$/', $k, $m)) {
        db()->prepare('DELETE FROM customer_remember WHERE expires < ?')->execute([time()]);
        $st = db()->prepare('SELECT * FROM customer_remember WHERE selector = ?');
        $st->execute([$m[1]]);
        $r = $st->fetch();
        if ($r && hash_equals($r['hash'], hash('sha256', $m[2])) && ($row = customer_by_id((int)$r['customer_id']))) {
            db()->prepare('DELETE FROM customer_remember WHERE selector = ?')->execute([$m[1]]);
            customer_sign_in($row, true);   // rotates the token
            return $c = $row;
        }
        customer_cookie('', time() - 3600);
    }
    return null;
}

function require_customer(string $back = 'account'): array
{
    $c = current_customer();
    if (!$c) {
        redirect(shop_url('account/login') . '?next=' . rawurlencode($back));
    }
    return $c;
}

/* Where to go after signing in: only places inside the shop. */
function safe_next(string $raw, string $default = 'account'): string
{
    return preg_match('#^[a-z0-9][a-z0-9/_-]{0,80}$#', $raw) && !str_contains($raw, '..') ? $raw : $default;
}

function password_problem(string $pass, string $email = ''): ?string
{
    if (strlen($pass) < CUSTOMER_PASS_MIN) {
        return 'Choose a password of at least ' . CUSTOMER_PASS_MIN . ' characters.';
    }
    if (strlen($pass) > 200) {
        return 'That password is too long.';
    }
    $l = strtolower($pass);
    if ($email !== '' && ($l === strtolower($email) || $l === strtolower(strtok($email, '@')))) {
        return 'Choose a password that isn\'t your email address.';
    }
    if (in_array($l, ['password', 'password1', 'password123', '12345678', '123456789', '1234567890', 'qwerty123', 'qwertyuiop',
                      'iloveyou', 'letmein1', 'welcome1', '11111111', '00000000', 'abc12345', 'football', 'baseball', 'sunshine1'], true)) {
        return 'That password is too easy to guess. Try three random words.';
    }
    return null;
}

/* One-use links (password reset, email confirmation). Only a hash is stored. */
function ctoken_issue(int $cid, string $kind, int $ttl, string $data = ''): string
{
    $raw = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM customer_tokens WHERE customer_id = ? AND kind = ?')->execute([$cid, $kind]);
    db()->prepare('INSERT INTO customer_tokens (hash, customer_id, kind, data, expires) VALUES (?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $raw), $cid, $kind, $data, time() + $ttl]);
    return $raw;
}

function ctoken_find(string $raw, string $kind): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return null;
    }
    db()->prepare('DELETE FROM customer_tokens WHERE expires < ?')->execute([time()]);
    $st = db()->prepare('SELECT * FROM customer_tokens WHERE hash = ? AND kind = ?');
    $st->execute([hash('sha256', $raw), $kind]);
    return $st->fetch() ?: null;
}

function ctoken_spend(array $t): bool
{
    $st = db()->prepare('DELETE FROM customer_tokens WHERE hash = ?');
    $st->execute([$t['hash']]);
    return $st->rowCount() === 1;   // only the first of two simultaneous clicks wins
}

/* ------------------------------------------------------------- the emails */

function account_mail(array $c, string $kind, string $to = ''): void
{
    $name = $c['name'] !== '' ? $c['name'] : 'there';
    [$foot, $footText] = mail_footer();
    $shop = mail_from_name();
    $btn = $btnText = '';
    switch ($kind) {
        case 'account_welcome':
            $url = abs_shop_url('account/verify/' . ctoken_issue((int)$c['id'], 'verify', 7 * 86400, $c['email']));
            $subject = 'Confirm your email for ' . $shop;
            $heading = 'Welcome, ' . $name;
            $paras = ['Thanks for creating an account. Please confirm this is your email address. Once you have, any earlier orders you placed with it will show in your account too.'];
            [$btn, $btnText] = [$url, 'Confirm my email'];
            $paras2 = 'The link works for 7 days. If you didn\'t create an account, you can ignore this email.';
            break;
        case 'account_new_email':
            $url = abs_shop_url('account/verify/' . ctoken_issue((int)$c['id'], 'verify', 86400, $to));
            $subject = 'Confirm your new email for ' . $shop;
            $heading = 'Confirm your new email address';
            $paras = ['You asked to change the email address on your account to this one. Confirm it and we\'ll use it from now on.'];
            [$btn, $btnText] = [$url, 'Confirm my new email'];
            $paras2 = 'The link works for 24 hours. If you didn\'t ask for this, ignore this email and nothing changes.';
            break;
        case 'account_reset':
            $url = abs_shop_url('account/reset/' . ctoken_issue((int)$c['id'], 'reset', 3600));
            $subject = 'Reset your password for ' . $shop;
            $heading = 'Reset your password';
            $paras = ['Someone, hopefully you, asked to reset the password for your account. Use the button to choose a new one.'];
            [$btn, $btnText] = [$url, 'Choose a new password'];
            $paras2 = 'The link works once, for one hour. If you didn\'t ask for this, ignore this email: your password hasn\'t changed.';
            break;
        case 'account_password_changed':
            $subject = 'Your password was changed';
            $heading = 'Your password was changed';
            $paras = ['The password for your account at ' . e($shop) . ' was just changed, and any other devices were signed out.'];
            $paras2 = 'If this wasn\'t you, reset your password straight away and let us know.';
            [$btn, $btnText] = [abs_shop_url('account/forgot'), 'Reset my password'];
            break;
        case 'account_email_changed':
            $subject = 'The email on your account was changed';
            $heading = 'Your email address was changed';
            $paras = ['The email address on your account at ' . e($shop) . ' was changed to ' . e($to) . '. We\'ll use that one from now on.'];
            $paras2 = 'If this wasn\'t you, please get in touch straight away.';
            break;
        default:
            return;
    }
    $paras[] = $paras2;
    $html = mail_layout(e($heading), $paras, '', $btn, $btnText, $foot);
    $text = $heading . "\n\n" . html_entity_decode(strip_tags(implode("\n\n", $paras)), ENT_QUOTES, 'UTF-8') . "\n\n"
          . ($btn !== '' ? $btnText . ': ' . $btn . "\n\n" : '') . $footText . "\n";
    mail_queue(null, $kind, $to !== '' && $kind === 'account_new_email' ? $to : $c['email'], $subject, $text, $html);
}

/* ------------------------------------------------------------------- pages */

function account_page(string $title, string $body, array $crumbs): never
{
    header('Cache-Control: no-store');
    $f = flash();
    render($title, '<h1 class="post-title">' . e($title) . '</h1><div class="post-body shop-account">'
        . ($f ? '<p class="shop-note" role="status">' . e($f) . '</p>' : '') . $body . '</div>',
        ['noindex' => true, 'crumbs' => $crumbs]);
}

function account_errors(array $errors): string
{
    return $errors ? '<div class="shop-note shop-errors" role="alert"><strong>Please check:</strong><ul>'
        . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '';
}

function account_field(string $name, string $label, string $value, string $attrs = '', string $hint = ''): string
{
    return '<label>' . $label . ($hint !== '' ? ' <span class="shop-hint">' . $hint . '</span>' : '')
        . '<input name="' . $name . '" value="' . e($value) . '" ' . $attrs . '></label>';
}

function password_field(string $name, string $label, string $autocomplete, string $hint = ''): string
{
    return '<label>' . $label . ($hint !== '' ? ' <span class="shop-hint">' . $hint . '</span>' : '')
        . '<input type="password" name="' . $name . '" autocomplete="' . $autocomplete . '" required maxlength="200"></label>';
}

/* Creating accounts on the public demo is switched off, like checkout. */
function demo_blocks_accounts(): ?string
{
    if (data_known_exposed()) {
        return 'New accounts can\'t be created just now. Please try again later.';
    }
    return shop_demo() && !current_admin()
        ? 'Creating accounts isn\'t switched on in the demo. In a live shop, customers can register here.' : null;
}

function view_account_login(): never
{
    $next = safe_next(str_in($_GET['next'] ?? $_POST['next'] ?? ''));
    if (current_customer()) {
        redirect(shop_url($next));
    }
    $err = '';
    $email = '';
    if (is_post()) {
        csrf_check();
        $email = mb_substr(post('email'), 0, 200);
        $key = 'clogin:' . client_ip();
        $acct = 'cacct:' . mb_strtolower($email);
        account_slowdown($acct);
        if (throttled($key, 10, 900)) {
            $err = 'Too many attempts. Please wait 15 minutes and try again, or reset your password.';
        } else {
            $c = customer_by_email($email);
            $pass = str_in($_POST['password'] ?? '');
            // compare against something even when there's no account, so timing gives nothing away
            $ok = customer_password_ok($pass, $c);   // also takes a password brought across from WordPress
            if ($c && $ok) {
                if (!str_starts_with($c['password'], 'wp:') && password_needs_rehash($c['password'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE customers SET password = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $c['id']]);
                }
                customer_sign_in($c, !empty($_POST['remember']));
                redirect(shop_url($next));
            }
            throttle_hit($key);
            throttle_hit($acct);
            usleep(300000);
            $err = 'That email and password don\'t match an account.';
        }
    }
    account_page('Sign in', ($err ? account_errors([$err]) : '')
        . '<form class="shop-form shop-narrow" method="post" action="' . e(shop_url('account/login')) . '">' . csrf_field()
        . '<input type="hidden" name="next" value="' . e($next) . '">'
        . account_field('email', 'Email', $email, 'type="email" autocomplete="email" required maxlength="200" autofocus')
        . password_field('password', 'Password', 'current-password')
        . '<label class="shop-radio"><input type="checkbox" name="remember" value="1" checked> <span>Keep me signed in on this device</span></label>'
        . '<button class="shop-button">Sign in</button></form>'
        . '<p><a href="' . e(shop_url('account/forgot')) . '">Forgotten your password?</a></p>'
        . '<p>New here? <a href="' . e(shop_url('account/register') . ($next !== 'account' ? '?next=' . rawurlencode($next) : '')) . '">Create an account</a>'
        . ($next === 'checkout' ? ', or <a href="' . e(shop_url('checkout')) . '">check out as a guest</a>' : '') . '.</p>',
        [['Your account', null]]);
}

function view_account_register(): never
{
    $next = safe_next(str_in($_GET['next'] ?? $_POST['next'] ?? ''));
    if (current_customer()) {
        redirect(shop_url($next));
    }
    $errors = [];
    $v = ['name' => '', 'email' => ''];
    if (is_post()) {
        csrf_check();
        $v = ['name' => mb_substr(post('name'), 0, 120), 'email' => mb_substr(trim(post('email')), 0, 200)];
        $pass = str_in($_POST['password'] ?? '');
        if ($why = demo_blocks_accounts()) {
            $errors[] = $why;
        } elseif (throttled('creg:' . client_ip(), 5, 3600)) {
            $errors[] = 'Too many new accounts from here just now. Please try again later.';
        } else {
            throttle_hit('creg:' . client_ip());   // every attempt counts, so this form can't be used to test lots of emails
            if ($v['name'] === '') {
                $errors[] = 'Enter your name.';
            }
            if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter your email address.';
            } elseif (customer_by_email($v['email'])) {
                $errors[] = 'There\'s already an account for that email. Sign in, or reset your password if you\'ve forgotten it.';
            }
            if ($p = password_problem($pass, $v['email'])) {
                $errors[] = $p;
            }
        }
        if (!$errors) {
            db()->prepare('INSERT INTO customers (email, password, name, created) VALUES (?, ?, ?, ?)')
                ->execute([$v['email'], password_hash($pass, PASSWORD_DEFAULT), $v['name'], now()]);
            $c = customer_by_id((int)db()->lastInsertId());
            customer_sign_in($c, !empty($_POST['remember']));
            account_mail($c, 'account_welcome');
            flash('Welcome! Your account is ready. ' . (mail_ready() ? 'We\'ve sent you an email to confirm your address.' : ''));
            redirect(shop_url($next));
        }
    }
    $privacy = setting('privacy_url');
    account_page('Create an account', account_errors($errors)
        . '<p>Save your addresses for next time and see all your orders in one place. You can still check out without an account.</p>'
        . '<form class="shop-form shop-narrow" method="post" action="' . e(shop_url('account/register')) . '">' . csrf_field()
        . '<input type="hidden" name="next" value="' . e($next) . '">'
        . account_field('name', 'Your name', $v['name'], 'autocomplete="name" required maxlength="120"')
        . account_field('email', 'Email', $v['email'], 'type="email" autocomplete="email" required maxlength="200"')
        . password_field('password', 'Choose a password', 'new-password', '(at least ' . CUSTOMER_PASS_MIN . ' characters; three random words work well)')
        . '<label class="shop-radio"><input type="checkbox" name="remember" value="1" checked> <span>Keep me signed in on this device</span></label>'
        . '<button class="shop-button">Create account</button>'
        . '<p class="shop-hint">We use your details to handle your orders.'
        . ($privacy !== '' ? ' See our <a href="' . e($privacy) . '">privacy notice</a>.' : '') . ' You can delete your account at any time.</p></form>'
        . '<p>Already have an account? <a href="' . e(shop_url('account/login') . ($next !== 'account' ? '?next=' . rawurlencode($next) : '')) . '">Sign in</a>.</p>',
        [['Your account', 'account/login'], ['Create an account', null]]);
}

function account_logout(): never
{
    csrf_check();
    customer_sign_out();
    flash('You\'re signed out.');
    redirect(shop_url());
}

function view_account_forgot(): never
{
    $sent = '';
    $email = '';
    if (is_post()) {
        csrf_check();
        $email = mb_substr(trim(post('email')), 0, 200);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter the email address you signed up with.');
        } elseif (throttled('cforgot:' . client_ip(), 5, 3600) || throttled('cforgot:' . strtolower($email), 3, 3600)) {
            flash('We\'ve sent a few links already. Check your inbox (and spam folder), or try again in an hour.');
        } else {
            throttle_hit('cforgot:' . client_ip());
            throttle_hit('cforgot:' . strtolower($email));
            if ($c = customer_by_email($email)) {
                account_mail($c, 'account_reset');
            }
            // the same answer either way, so this page can't be used to find out who has an account
            $sent = $email;
        }
    }
    if (!mail_ready() || setting('site_url') === '') {
        $contact = setting('business_email');
        account_page('Forgotten your password?', '<p>This shop can\'t send password reset emails yet.'
            . ($contact !== '' ? ' Please email <a href="mailto:' . e($contact) . '">' . e($contact) . '</a> and we\'ll help.' : '') . '</p>',
            [['Your account', 'account/login'], ['Forgotten password', null]]);
    }
    if ($sent !== '') {
        account_page('Check your email', '<p>If there\'s an account for <strong>' . e($sent) . '</strong>, we\'ve sent it a link to choose a new password. '
            . 'The link works for one hour.</p><p class="shop-hint">Nothing there after a few minutes? Check your spam folder, or make sure it\'s the address you signed up with.</p>'
            . '<p><a href="' . e(shop_url('account/login')) . '">Back to sign in</a></p>',
            [['Your account', 'account/login'], ['Forgotten password', null]]);
    }
    account_page('Forgotten your password?', '<p>Enter your email address and we\'ll send you a link to choose a new password.</p>'
        . '<form class="shop-form shop-narrow" method="post" action="' . e(shop_url('account/forgot')) . '">' . csrf_field()
        . account_field('email', 'Email', $email, 'type="email" autocomplete="email" required maxlength="200" autofocus')
        . '<button class="shop-button">Send me a link</button></form>',
        [['Your account', 'account/login'], ['Forgotten password', null]]);
}

function view_account_reset(?string $raw): never
{
    header('Referrer-Policy: no-referrer');
    if ($raw !== null) {   // keep the token out of the address bar (and away from scripts and logs)
        $_SESSION['shop_reset'] = $raw;
        redirect(shop_url('account/reset'));
    }
    $raw = (string)($_SESSION['shop_reset'] ?? '');
    $t = ctoken_find($raw, 'reset');
    $c = $t ? customer_by_id((int)$t['customer_id']) : null;
    if (!$c) {
        account_page('That link has expired', '<p>Password reset links work once, for one hour. Please ask for a new one.</p>'
            . '<p><a class="shop-button" href="' . e(shop_url('account/forgot')) . '">Send me a new link</a></p>', [['Your account', 'account/login'], ['Reset password', null]]);
    }
    $err = null;
    if (is_post()) {
        csrf_check();
        $pass = str_in($_POST['password'] ?? '');
        $err = password_problem($pass, $c['email']);
        if (!hash_equals(substr(hash('sha256', $raw), 0, 16), post('for'))) {   // another reset link was opened meanwhile
            $err = 'Another reset link was opened in this browser. Please use the most recent email.';
        }
        if (!$err && ctoken_spend($t)) {
            // they've proved they can read this inbox, so the address counts as confirmed too
            db()->prepare("UPDATE customers SET password = ?, verified_at = CASE WHEN verified_at = '' THEN ? ELSE verified_at END WHERE id = ?")
                ->execute([password_hash($pass, PASSWORD_DEFAULT), now(), $c['id']]);
            db()->prepare('DELETE FROM customer_tokens WHERE customer_id = ?')->execute([$c['id']]);   // incl. any pending email change
            unset($_SESSION['shop_reset']);
            customer_claim_orders((int)$c['id'], $c['email']);
            customer_sign_out_everywhere((int)$c['id']);
            customer_sign_in(customer_by_id((int)$c['id']), false);
            account_mail($c, 'account_password_changed');
            flash('Your new password is set and you\'re signed in.');
            redirect(shop_url('account'));
        }
        $err ??= 'That link has just been used. Please ask for a new one.';
    }
    account_page('Choose a new password', ($err ? account_errors([$err]) : '') . '<p>For the account <strong>' . e($c['email']) . '</strong>.</p>'
        . '<form class="shop-form shop-narrow" method="post">' . csrf_field()
        . '<input type="hidden" name="for" value="' . e(substr(hash('sha256', $raw), 0, 16)) . '">'
        . '<input type="email" name="email" value="' . e($c['email']) . '" autocomplete="username" hidden>'
        . password_field('password', 'New password', 'new-password', '(at least ' . CUSTOMER_PASS_MIN . ' characters)')
        . '<button class="shop-button">Save new password</button></form>',
        [['Your account', 'account/login'], ['Reset password', null]]);
}

/* Confirming an email address (new account, or a change of address) takes the
   person signed in to that account pressing a button. A bare click on the link,
   or a mail scanner fetching it, does nothing; and nobody can confirm an
   address on an account they don't control. */
function view_account_verify(?string $raw): never
{
    header('Referrer-Policy: no-referrer');
    if ($raw !== null) {
        $_SESSION['shop_verify'] = $raw;
        redirect(shop_url('account/verify'));
    }
    $raw = (string)($_SESSION['shop_verify'] ?? '');
    $t = ctoken_find($raw, 'verify');
    $c = $t ? customer_by_id((int)$t['customer_id']) : null;
    $crumbs = [['Your account', 'account'], ['Confirm email', null]];
    if ($c) {
        $me = current_customer();
        if (!$me) {
            account_page('Confirm your email', '<p>To confirm <strong>' . e($t['data']) . '</strong>, please sign in to your account first.</p>'
                . '<p><a class="shop-button" href="' . e(shop_url('account/login') . '?next=account/verify') . '">Sign in</a></p>', $crumbs);
        }
        if ((int)$me['id'] !== (int)$c['id']) {
            account_page('Confirm your email', '<p>This link is for a different account from the one you\'re signed in to. '
                . 'If it\'s yours, sign out and sign in to that account, then open the link again.</p>'
                . '<p class="shop-hint">If you didn\'t ask for this, you can ignore the email: nothing changes unless it\'s confirmed.</p>', $crumbs);
        }
        if (!is_post()) {
            account_page('Confirm your email', '<form class="shop-form shop-narrow" method="post">' . csrf_field()
                . '<p>Confirm that <strong>' . e($t['data']) . '</strong> is your email address?</p>'
                . '<button class="shop-button">Yes, confirm it</button></form>', $crumbs);
        }
        csrf_check();
    }
    if (!$c || !ctoken_spend($t)) {
        account_page('That link has expired', '<p>Confirmation links only work once and expire after a while.'
            . (current_customer() ? ' You can send a new one from your account.' : ' Sign in and you can send a new one from your account.') . '</p>'
            . '<p><a class="shop-button" href="' . e(shop_url('account')) . '">Your account</a></p>', [['Your account', 'account'], ['Confirm email', null]]);
    }
    unset($_SESSION['shop_verify']);
    $new = $t['data'];
    if (strcasecmp($new, $c['email']) !== 0) {   // confirming a change of address
        if (($other = customer_by_email($new)) && (int)$other['id'] !== (int)$c['id']) {
            flash('Another account now uses ' . $new . ', so your email address wasn\'t changed.');
            redirect(shop_url('account'));
        }
        db()->prepare('UPDATE customers SET email = ?, verified_at = ? WHERE id = ?')->execute([$new, now(), $c['id']]);
        customer_claim_orders((int)$c['id'], $new);
        account_mail($c, 'account_email_changed', $new);   // tells the old address
        flash('Thanks. Your email address is now ' . $new . '.');
    } else {
        db()->prepare('UPDATE customers SET verified_at = ? WHERE id = ?')->execute([now(), $c['id']]);
        customer_claim_orders((int)$c['id'], $c['email']);
        flash('Thanks, your email address is confirmed.');
    }
    redirect(shop_url('account'));
}

/* Once an address is confirmed, guest orders placed with it belong to the
   account for good (so they stay if the email is changed later). */
function customer_claim_orders(int $cid, string $email): void
{
    db()->prepare('UPDATE orders SET customer_id = ? WHERE customer_id IS NULL AND email = ? COLLATE NOCASE')->execute([$cid, $email]);
}

/* Orders belonging to an account: placed while signed in, or (once the address
   is confirmed) placed as a guest with the same email. */
function customer_orders(array $c): array
{
    $st = db()->prepare("SELECT * FROM orders WHERE status != 'pending' AND (customer_id = ?
                         OR (? AND customer_id IS NULL AND email = ? COLLATE NOCASE)) ORDER BY created DESC, id DESC");
    $st->execute([$c['id'], $c['verified_at'] !== '' ? 1 : 0, $c['email']]);
    return $st->fetchAll();
}

function customer_status(array $o): string
{
    if ($o['status'] === 'paid') {
        return ['sent' => 'Sent', 'collected' => 'Collected', 'digital' => 'Paid: ready to download'][$o['fulfilled']] ?? ($o['delivery'] === 'collect' ? 'Paid, to collect' : 'Paid, being prepared');
    }
    return ['awaiting_payment' => 'Waiting for your bank transfer', 'pay_on_collection' => 'Pay when you collect',
            'cancelled' => 'Cancelled', 'refunded' => 'Refunded'][$o['status']] ?? status_label($o['status']);
}

function view_account(): never
{
    $c = require_customer();
    $orders = customer_orders($c);
    $rows = '';
    foreach ($orders as $o) {
        $rows .= '<tr><td><a href="' . e(shop_url('order/' . $o['token'])) . '">#' . (int)$o['number'] . '</a></td>'
               . '<td>' . e(gmdate('j M Y', strtotime($o['created'] . ' UTC'))) . '</td><td>' . e(customer_status($o)) . '</td>'
               . '<td class="shop-num">' . money((int)$o['total_pence']) . '</td></tr>';
    }
    $verify = $c['verified_at'] === ''
        ? '<div class="shop-note"><strong>Please confirm your email address.</strong> We sent a link to ' . e($c['email'])
          . '. Until it\'s confirmed, orders you placed before you had an account won\'t show here'
          . (($cg = $c['group_id'] !== null ? group_by_id((int)$c['group_id']) : null) ? ', and your ' . e($cg['name']) . ' prices won\'t apply.' : '.')
          . (mail_ready() ? '<form method="post" action="' . e(shop_url('account/verify-send')) . '" class="shop-inline">' . csrf_field()
             . '<button class="shop-linkbutton">Send the link again</button></form>' : '') . '</div>'
        : '';
    $addr = mail_address_lines('bill_', $c);
    $g = customer_group();
    account_page('Your account', $verify
        . ($g ? '<p class="shop-note">You get <strong>' . e($g['name']) . ' prices</strong> while you\'re signed in: ' . e(lcfirst(group_offer($g))) . '.</p>' : '')
        . trade_account_link($c)
        . '<p>Signed in as <strong>' . e($c['name'] ?: $c['email']) . '</strong>' . ($c['name'] !== '' ? ' (' . e($c['email']) . ')' : '') . '.</p>'
        . '<h2 class="shop-h2">Your orders</h2>'
        . ($rows !== '' ? '<table class="shop-basket shop-orders"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="shop-num">Total</th></tr></thead><tbody>'
            . $rows . '</tbody></table>' : '<p>No orders yet. <a href="' . e(shop_url()) . '">Have a look around the shop</a>.</p>')
        . '<h2 class="shop-h2">Your details</h2>'
        . '<ul class="shop-links">'
        . ((int)db()->query('SELECT COUNT(*) FROM licences WHERE customer_id = ' . (int)$c['id'])->fetchColumn()
            ? '<li><a href="' . e(shop_url('account/licences')) . '">Downloads and licences</a></li>' : '')
        . '<li><a href="' . e(shop_url('account/addresses')) . '">Addresses</a> <span class="shop-hint">' . ($addr ? e(implode(', ', $addr)) : 'none saved yet') . '</span></li>'
        . '<li><a href="' . e(shop_url('account/details')) . '">Name, phone and email</a></li>'
        . '<li><a href="' . e(shop_url('account/password')) . '">Change password</a></li>'
        . '<li><a href="' . e(shop_url('account/delete')) . '">Delete account</a></li></ul>'
        . '<form method="post" action="' . e(shop_url('account/logout')) . '">' . csrf_field() . '<button class="shop-button shop-quiet">Sign out</button></form>',
        [['Your account', null]]);
}

function account_verify_send(): never
{
    csrf_check();
    $c = require_customer();
    if ($c['verified_at'] === '' && !throttled('cverify:' . $c['id'], 3, 3600)) {
        throttle_hit('cverify:' . $c['id']);
        account_mail($c, 'account_welcome');
        flash('We\'ve sent the link again to ' . $c['email'] . '.');
    } elseif ($c['verified_at'] === '') {
        flash('We\'ve sent a few already. Please check your spam folder, or try again in an hour.');
    }
    redirect(shop_url('account'));
}

function view_account_details(): never
{
    $c = require_customer('account/details');
    $errors = [];
    if (is_post()) {
        csrf_check();
        if (post('form') === 'details') {
            $name = mb_substr(post('name'), 0, 120);
            if ($name === '') {
                $errors[] = 'Enter your name.';
            } else {
                db()->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?')->execute([$name, mb_substr(post('phone'), 0, 40), $c['id']]);
                flash('Saved.');
                redirect(shop_url('account/details'));
            }
        } elseif (post('form') === 'email') {
            $new = mb_substr(trim(post('email')), 0, 200);
            account_slowdown('cacct:' . mb_strtolower($c['email']));
            if (throttled('clogin:' . client_ip(), 10, 900) || throttled('cemail:' . $c['id'], 5, 3600)) {
                $errors[] = 'Too many attempts. Please wait a while and try again.';
            } elseif (!customer_password_ok(str_in($_POST['password'] ?? ''), $c)) {
                throttle_hit('cacct:' . mb_strtolower($c['email']));
                throttle_hit('clogin:' . client_ip());
                throttle_hit('clogin:' . client_ip());
                $errors[] = 'Your password wasn\'t right, so your email address wasn\'t changed.';
            } elseif (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter the new email address.';
            } elseif (strcasecmp($new, $c['email']) === 0) {
                $errors[] = 'That\'s already your email address.';
            } elseif (!mail_ready()) {
                $errors[] = 'This shop can\'t send emails yet, so the new address can\'t be confirmed. Please contact the shop.';
            } else {
                throttle_hit('cemail:' . $c['id']);   // every link sent counts: this can't be used to email lots of people
                if (!customer_by_email($new)) {   // same answer either way, so it can't be used to find out who has an account
                    account_mail($c, 'account_new_email', $new);
                }
                flash('Nearly done: we\'ve sent a link to ' . $new . '. Your email address changes when you click it.');
                redirect(shop_url('account/details'));
            }
        }
    }
    account_page('Name, phone and email', account_errors($errors)
        . '<form class="shop-form shop-narrow" method="post">' . csrf_field() . '<input type="hidden" name="form" value="details">'
        . account_field('name', 'Your name', $c['name'], 'autocomplete="name" required maxlength="120"')
        . account_field('phone', 'Phone', $c['phone'], 'type="tel" autocomplete="tel" maxlength="40"', '(optional)')
        . '<button class="shop-button">Save</button></form>'
        . '<h2 class="shop-h2">Email address</h2><p>Your email is <strong>' . e($c['email']) . '</strong>'
        . ($c['verified_at'] === '' ? ' (not confirmed yet)' : '') . '. To change it, enter the new one and your password. We\'ll send a link to the new address to confirm it.</p>'
        . '<form class="shop-form shop-narrow" method="post">' . csrf_field() . '<input type="hidden" name="form" value="email">'
        . account_field('email', 'New email address', '', 'type="email" autocomplete="email" required maxlength="200"')
        . password_field('password', 'Your password', 'current-password')
        . '<button class="shop-button">Change email</button></form>'
        . '<p><a href="' . e(shop_url('account')) . '">&larr; Your account</a></p>',
        [['Your account', 'account'], ['Details', null]]);
}

function view_account_password(): never
{
    $c = require_customer('account/password');
    $errors = [];
    if (is_post()) {
        csrf_check();
        $new = str_in($_POST['new'] ?? '');
        account_slowdown('cacct:' . mb_strtolower($c['email']));
        if (throttled('clogin:' . client_ip(), 10, 900)) {
            $errors[] = 'Too many attempts. Please wait 15 minutes and try again.';
        } elseif (!customer_password_ok(str_in($_POST['current'] ?? ''), $c)) {
            throttle_hit('cacct:' . mb_strtolower($c['email']));
            throttle_hit('clogin:' . client_ip());
            throttle_hit('clogin:' . client_ip());
            $errors[] = 'Your current password wasn\'t right.';
        } elseif ($p = password_problem($new, $c['email'])) {
            $errors[] = $p;
        } else {
            $kept = !empty($_COOKIE['wrenshop_me']);
            db()->prepare('UPDATE customers SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $c['id']]);
            db()->prepare('DELETE FROM customer_tokens WHERE customer_id = ?')->execute([$c['id']]);   // old links stop working
            customer_sign_out_everywhere((int)$c['id']);
            customer_sign_in(customer_by_id((int)$c['id']), $kept);   // this device stays signed in
            account_mail($c, 'account_password_changed');
            flash('Password changed. Any other devices have been signed out.');
            redirect(shop_url('account'));
        }
    }
    account_page('Change password', account_errors($errors)
        . '<form class="shop-form shop-narrow" method="post">' . csrf_field()
        . '<input type="email" name="email" value="' . e($c['email']) . '" autocomplete="username" hidden>'
        . password_field('current', 'Current password', 'current-password')
        . password_field('new', 'New password', 'new-password', '(at least ' . CUSTOMER_PASS_MIN . ' characters)')
        . '<button class="shop-button">Change password</button></form>'
        . '<p class="shop-hint">Changing your password signs you out on any other devices.</p>'
        . '<p><a href="' . e(shop_url('account')) . '">&larr; Your account</a></p>',
        [['Your account', 'account'], ['Password', null]]);
}

const ADDRESS_FIELDS = ['bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode',
                        'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode'];

/* Check and tidy an address form. Blank is fine (nothing saved). */
function address_input(array $src, array &$errors): array
{
    $v = [];
    foreach (ADDRESS_FIELDS as $k) {
        $v[$k] = mb_substr(trim((string)($src[$k] ?? '')), 0, $k === 'bill_town' || $k === 'ship_town' ? 80 : 120);
    }
    $v['same_address'] = !empty($src['same']) ? 1 : 0;
    foreach (['bill_' => 'billing', 'ship_' => 'delivery'] as $p => $what) {
        if ($p === 'ship_' && $v['same_address']) {
            continue;
        }
        $any = $v[$p . 'name'] . $v[$p . 'line1'] . $v[$p . 'town'] . $v[$p . 'postcode'] !== '';
        if (!$any) {
            continue;
        }
        if ($v[$p . 'name'] === '' || $v[$p . 'line1'] === '' || $v[$p . 'town'] === '') {
            $errors[] = 'The ' . $what . ' address needs a name, first line and town.';
        }
        $pc = uk_postcode($v[$p . 'postcode']);
        if ($pc === null) {
            $errors[] = 'Enter a UK postcode for the ' . $what . ' address, like BS49 4AB.';
        } else {
            $v[$p . 'postcode'] = $pc;
        }
    }
    if ($v['same_address']) {
        foreach (['name', 'line1', 'line2', 'town', 'postcode'] as $f) {
            $v['ship_' . $f] = $v['bill_' . $f];
        }
    }
    return $v;
}

function customer_save_addresses(int $cid, array $v): void
{
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_merge(ADDRESS_FIELDS, ['same_address'])));
    $vals = array_map(fn($k) => $v[$k], array_merge(ADDRESS_FIELDS, ['same_address']));
    db()->prepare("UPDATE customers SET $set WHERE id = ?")->execute([...$vals, $cid]);
}

function view_account_addresses(): never
{
    $c = require_customer('account/addresses');
    $errors = [];
    $v = $c;
    if (is_post()) {
        csrf_check();
        $v = address_input($_POST, $errors);
        if (!$errors) {
            customer_save_addresses((int)$c['id'], $v);
            flash('Addresses saved. They\'ll be filled in for you at checkout.');
            redirect(shop_url('account'));
        }
    }
    $f = fn($k, $label, $attrs = '') => account_field($k, $label, (string)$v[$k], $attrs);
    $same = (int)$v['same_address'] === 1;
    account_page('Your addresses', account_errors($errors)
        . '<form class="shop-form" method="post" id="shop-addr">' . csrf_field()
        . '<fieldset><legend>Billing address</legend>'
        . $f('bill_name', 'Full name', 'autocomplete="billing name" maxlength="120"')
        . $f('bill_line1', 'Address', 'autocomplete="billing address-line1" maxlength="120"')
        . $f('bill_line2', '<span class="shop-vh">Address line 2</span>', 'autocomplete="billing address-line2" maxlength="120" aria-label="Address line 2"')
        . $f('bill_town', 'Town or city', 'autocomplete="billing address-level2" maxlength="80"')
        . $f('bill_postcode', 'Postcode', 'autocomplete="billing postal-code" maxlength="10" class="shop-short" autocapitalize="characters"')
        . '</fieldset><fieldset><legend>Delivery address</legend>'
        . '<label class="shop-radio"><input type="checkbox" name="same" value="1" id="shop-same"' . ($same ? ' checked' : '') . '> <span>Same as billing</span></label>'
        . '<div id="shop-ship"' . ($same ? ' hidden' : '') . '>'
        . $f('ship_name', 'Full name', 'autocomplete="shipping name" maxlength="120"')
        . $f('ship_line1', 'Address', 'autocomplete="shipping address-line1" maxlength="120"')
        . $f('ship_line2', '<span class="shop-vh">Address line 2</span>', 'autocomplete="shipping address-line2" maxlength="120" aria-label="Delivery address line 2"')
        . $f('ship_town', 'Town or city', 'autocomplete="shipping address-level2" maxlength="80"')
        . $f('ship_postcode', 'Postcode', 'autocomplete="shipping postal-code" maxlength="10" class="shop-short" autocapitalize="characters"')
        . '</div></fieldset><button class="shop-button">Save addresses</button></form>'
        . '<p><a href="' . e(shop_url('account')) . '">&larr; Your account</a></p>'
        . '<script>(function(){var s=document.getElementById("shop-same"),d=document.getElementById("shop-ship");'
        . 's.addEventListener("change",function(){d.hidden=s.checked;});})();</script>',
        [['Your account', 'account'], ['Addresses', null]]);
}

function customer_delete(int $cid): void
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $pdo->prepare('UPDATE orders SET customer_id = NULL WHERE customer_id = ?')->execute([$cid]);
    $pdo->prepare('UPDATE licences SET customer_id = NULL WHERE customer_id = ?')->execute([$cid]);   // theirs no longer
    $pdo->prepare("DELETE FROM emails WHERE order_id IS NULL AND kind LIKE 'account%' AND to_addr IN
                   (SELECT email FROM customers WHERE id = ?)")->execute([$cid]);   // their account emails
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);   // tokens and devices go with it
    $pdo->exec('COMMIT');
}

function view_account_delete(): never
{
    $c = require_customer('account/delete');
    $err = '';
    if (is_post()) {
        csrf_check();
        account_slowdown('cacct:' . mb_strtolower($c['email']));
        if (throttled('clogin:' . client_ip(), 10, 900)) {
            $err = 'Too many attempts. Please wait 15 minutes and try again.';
        } elseif (!customer_password_ok(str_in($_POST['password'] ?? ''), $c)) {
            throttle_hit('clogin:' . client_ip());
            throttle_hit('cacct:' . mb_strtolower($c['email']));
            $err = 'Your password wasn\'t right, so nothing was deleted.';
        } else {
            customer_delete((int)$c['id']);
            customer_sign_out();
            flash('Your account has been deleted.');
            redirect(shop_url());
        }
    }
    account_page('Delete your account', ($err ? account_errors([$err]) : '')
        . '<p>This deletes your account, your saved addresses and your password straight away. It can\'t be undone.</p>'
        . '<p>Details of orders you\'ve placed are kept as the shop\'s sales records, which the law requires, but they\'re no longer linked to an account. '
        . 'You can still check out as a guest or create a new account later.</p>'
        . (customer_licences((int)$c['id']) ? '<div class="shop-note"><strong>You\'ll lose your downloads.</strong> Downloads and licences you\'ve bought live in your account, '
            . 'so you won\'t be able to download them or move a licence to another website afterwards.</div>' : '')
        . '<form class="shop-form shop-narrow" method="post">' . csrf_field()
        . password_field('password', 'Your password, to confirm', 'current-password')
        . '<button class="shop-button shop-danger">Delete my account</button></form>'
        . '<p><a href="' . e(shop_url('account')) . '">&larr; Keep my account</a></p>',
        [['Your account', 'account'], ['Delete account', null]]);
}

/* After a guest order: turn it into an account in one step. */
function account_from_order(): never
{
    csrf_check();
    $o = order_by('token', (string)post('token'));
    if (!$o || !preg_match('/^[a-f0-9]{32}$/', (string)post('token'))) {
        redirect(shop_url());
    }
    $back = shop_url('order/' . $o['token']);
    $pass = str_in($_POST['password'] ?? '');
    if ($why = demo_blocks_accounts()) {
        flash($why);
        redirect($back);
    }
    if ($o['customer_id'] !== null || customer_by_email($o['email'])) {
        flash('There\'s already an account for ' . $o['email'] . '. Sign in to see your orders.');
        redirect($back);
    }
    if (throttled('creg:' . client_ip(), 5, 3600)) {
        flash('Too many new accounts from here just now. Please try again later.');
        redirect($back);
    }
    if ($p = password_problem($pass, $o['email'])) {
        flash($p);
        redirect($back . '#shop-save');
    }
    throttle_hit('creg:' . client_ip());
    $pdo = db();
    $pdo->prepare('INSERT INTO customers (email, password, name, phone, created) VALUES (?, ?, ?, ?, ?)')
        ->execute([$o['email'], password_hash($pass, PASSWORD_DEFAULT), $o['bill_name'], $o['phone'], now()]);
    $cid = (int)$pdo->lastInsertId();
    $addr = [];
    foreach (ADDRESS_FIELDS as $k) {
        $addr[$k] = $o[$k];
    }
    $addr['same_address'] = $o['delivery'] === 'collect' ? 1 : (int)$o['same_address'];
    if ($o['delivery'] === 'collect') {
        foreach (['name', 'line1', 'line2', 'town', 'postcode'] as $f) {
            $addr['ship_' . $f] = $o['bill_' . $f];
        }
    }
    customer_save_addresses($cid, $addr);
    $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ? AND customer_id IS NULL')->execute([$cid, $o['id']]);
    $c = customer_by_id($cid);
    customer_sign_in($c, true);
    account_mail($c, 'account_welcome');
    flash('Your account is ready and this order is saved in it. ' . (mail_ready() ? 'We\'ve emailed you a link to confirm your address.' : ''));
    redirect(shop_url('account'));
}

/* The box on a guest's order page. */
function order_account_box(array $o): string
{
    if (!accounts_on() || $o['customer_id'] !== null || in_array($o['status'], ['pending', 'cancelled'], true)) {
        return '';
    }
    $me = current_customer();
    if ($me) {
        return '';
    }
    if (customer_by_email($o['email'])) {
        return '<div class="shop-note shop-save"><a href="' . e(shop_url('account/login') . '?next=account') . '">Sign in</a> to see all your orders in your account.</div>';
    }
    return '<form class="shop-form shop-note shop-save" id="shop-save" method="post" action="' . e(shop_url('account/from-order')) . '">' . csrf_field()
        . '<input type="hidden" name="token" value="' . e($o['token']) . '">'
        . '<strong>Save your details for next time?</strong><br>Choose a password to create an account with <strong>' . e($o['email'])
        . '</strong>. Your addresses are saved and you can follow this order there.'
        . '<input type="email" name="email" value="' . e($o['email']) . '" autocomplete="username" hidden>'
        . password_field('password', 'Choose a password', 'new-password', '(at least ' . CUSTOMER_PASS_MIN . ' characters)')
        . '<button class="shop-button">Create my account</button></form>';
}

/* ------------------------------------------------------------------ admin */

function view_admin_customers(): never
{
    require_admin();
    $q = trim(str_in($_GET['find'] ?? ''));
    $sql = "SELECT c.*, (SELECT name FROM customer_groups g WHERE g.id = c.group_id) AS gname, (SELECT COUNT(*) FROM orders o WHERE o.status != 'pending' AND (o.customer_id = c.id
             OR (c.verified_at != '' AND o.customer_id IS NULL AND o.email = c.email COLLATE NOCASE))) AS n
            FROM customers c";
    $args = [];
    if ($q !== '') {
        $sql .= " WHERE c.name LIKE ? ESCAPE '\\' OR c.email LIKE ? ESCAPE '\\' OR c.bill_postcode LIKE ? ESCAPE '\\'";
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $args = [$like, $like, $like];
    }
    $st = db()->prepare($sql . ' ORDER BY c.id DESC LIMIT 200');
    $st->execute($args);
    $rows = '';
    foreach ($st->fetchAll() as $c) {
        $rows .= '<tr><td><a href="' . e(admin_url('customer/' . $c['id'])) . '">' . e($c['name'] ?: '(no name)') . '</a><br><span class="muted">' . e($c['email'])
               . ($c['verified_at'] === '' ? ' · not confirmed' : '') . '</span>' . ($c['gname'] !== null ? ' <span class="pill">' . e($c['gname']) . '</span>' : '') . '</td><td>' . (int)$c['n'] . ' order' . ((int)$c['n'] === 1 ? '' : 's')
               . '</td><td class="muted">joined ' . e(gmdate('j M Y', strtotime($c['created'] . ' UTC'))) . '</td></tr>';
    }
    $total = (int)db()->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    admin_shell('Customers', '<h1>Customers</h1>'
        . '<p class="muted"><a href="' . e(admin_url('privacy')) . '">Find a person\'s data</a> (for requests to see or remove their details)</p>'
        . (!accounts_on() ? '<p class="flash">Customer accounts are switched off in Settings, so customers can\'t sign in.</p>' : '')
        . '<form method="get" class="search"><input name="find" value="' . e($q) . '" placeholder="Name, email or postcode" aria-label="Search customers"><button class="quiet">Search</button></form>'
        . ($rows !== '' ? '<table>' . $rows . '</table>'
            : '<p class="muted">' . ($q !== '' ? 'No customers match that.' : 'No customer accounts yet. Customers can create one when they order, or from the Sign in link in the shop.') . '</p>')
        . '<p class="muted">' . $total . ' account' . ($total === 1 ? '' : 's') . '. Customers who check out as guests don\'t appear here; they\'re in Orders.</p>');
}

function view_admin_customer(int $id): never
{
    require_admin();
    $c = customer_by_id($id);
    if (!$c) {
        redirect(admin_url('customers'));
    }
    if (is_post()) {
        csrf_check();
        if (post('do') === 'reset') {
            if (mail_ready()) {
                account_mail($c, 'account_reset');
                flash('Password reset email sent to ' . $c['email'] . '.');
            } else {
                flash('Email isn\'t set up, so a reset link can\'t be sent.');
            }
        } elseif (post('do') === 'group') {
            $gid = (int)post('group_id');
            $grp = $gid ? group_by_id($gid) : null;
            db()->prepare('UPDATE customers SET group_id = ? WHERE id = ?')->execute([$grp ? $grp['id'] : null, $id]);
            flash($grp ? $c['email'] . ' is in the ' . $grp['name'] . ' group.' . ($c['verified_at'] === '' ? ' Their prices apply once they confirm their email address.' : '')
                       : $c['email'] . ' pays normal prices.');
        } elseif (post('do') === 'delete') {
            customer_delete($id);
            flash('Account for ' . $c['email'] . ' deleted. Their orders are kept.');
            redirect(admin_url('customers'));
        }
        redirect(admin_url('customer/' . $id));
    }
    $orders = '';
    foreach (customer_orders($c) as $o) {
        $orders .= '<tr><td><a href="' . e(admin_url('order/' . $o['id'])) . '">#' . (int)$o['number'] . '</a></td><td>'
                 . e(gmdate('j M Y', strtotime($o['created'] . ' UTC'))) . '</td><td>' . status_pill($o) . '</td><td style="text-align:right">'
                 . money((int)$o['total_pence']) . '</td></tr>';
    }
    $bill = mail_address_lines('bill_', $c);
    $ship = mail_address_lines('ship_', $c);
    $act = fn(string $do, string $label, string $cls, string $confirm) => '<form method="post"' . ($confirm !== '' ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '')
        . '>' . csrf_field() . '<input type="hidden" name="do" value="' . $do . '"><button class="' . $cls . '">' . $label . '</button></form>';
    admin_shell($c['name'] ?: $c['email'], '<p class="muted"><a href="' . e(admin_url('customers')) . '">&larr; Customers</a></p>'
        . '<h1>' . e($c['name'] ?: '(no name)') . '</h1>'
        . '<p><a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' . ($c['verified_at'] === '' ? ' <span class="pill wait">Not confirmed</span>' : ' <span class="pill ok">Confirmed</span>')
        . ($c['phone'] !== '' ? '<br>' . e($c['phone']) : '') . '</p>'
        . '<p class="muted">Joined ' . e(gmdate('j M Y', strtotime($c['created'] . ' UTC')))
        . ($c['last_login'] !== '' ? ' · last signed in ' . e(gmdate('j M Y H:i', strtotime($c['last_login'] . ' UTC'))) : '') . '</p>'
        . '<h2>Group</h2>' . (($groups = all_groups())
            ? '<form method="post" class="search">' . csrf_field() . '<input type="hidden" name="do" value="group"><select name="group_id" aria-label="Customer group">'
              . '<option value="0">None (normal prices)</option>'
              . implode('', array_map(fn($g) => '<option value="' . (int)$g['id'] . '"' . ((int)$c['group_id'] === (int)$g['id'] ? ' selected' : '') . '>' . e($g['name']) . '</option>', $groups))
              . '</select><button class="quiet">Save</button></form>'
              . ($c['group_id'] !== null && $c['verified_at'] === '' ? '<p class="muted">Their group prices start once they confirm their email address.</p>' : '')
            : '<p class="muted">No customer groups yet. <a href="' . e(admin_url('group/new')) . '">Add one</a> to give resellers or trade customers their own prices.</p>')
        . '<h2>Orders</h2>' . ($orders !== '' ? '<table>' . $orders . '</table>' : '<p class="muted">None yet.</p>')
        . '<h2>Saved addresses</h2><p>' . ($bill ? '<b>Billing</b><br>' . implode('<br>', array_map('e', $bill)) : '<span class="muted">None saved.</span>') . '</p>'
        . ($ship && !$c['same_address'] ? '<p><b>Delivery</b><br>' . implode('<br>', array_map('e', $ship)) . '</p>' : '')
        . '<h2>Help them</h2><p class="muted">You can\'t see or set a customer\'s password. If they\'re stuck, send them a reset link.</p>'
        . '<div class="actions-row" style="border:0;padding:0">' . $act('reset', 'Send password reset email', 'quiet', '')
        . $act('delete', 'Delete account', 'danger', 'Delete this customer\'s account? Their orders are kept.') . '</div>');
}
/* ========================================================= discount codes */
/* A code takes a percentage or an amount off the goods, and/or makes delivery
   free. It can have a minimum spend, start and end dates (UK dates, both days
   included), a limit on how many orders can use it, and a limit of one order
   per customer email. An order that's cancelled before it's paid gives its use
   back. Orders keep a copy of the code and the amount it took off. */

const DISCOUNT_PERCENT_MAX = 99;   // 100% would make orders free, which online payment can't take

function uk_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->format('Y-m-d');
}

function uk_date(string $ymd): string   // '2026-12-01' -> '1 Dec 2026'
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d ? $d->format('j M Y') : $ymd;
}

function discount_code_clean(string $s): string
{
    return strtoupper(preg_replace('/\s+/', '', $s));
}

function discount_by_code(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM discounts WHERE code = ?');
    $st->execute([discount_code_clean($code)]);
    return $st->fetch() ?: null;
}

function discount_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM discounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* Orders using the code that still count: everything but cancelled ones. */
function discount_uses(int $id): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM orders WHERE discount_id = ? AND status != 'cancelled'");
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/* One mailbox, however it's written: case, "+anything" tags, and Gmail's dots. */
function email_key(string $email): string
{
    $email = strtolower(trim($email));
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $local = explode('+', $local, 2)[0];
    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        $local = str_replace('.', '', $local);
        $domain = 'gmail.com';
    }
    return $local . '@' . $domain;
}

/* Has this person already used the code? Checks the email typed at checkout and,
   when signed in, the account's own email. */
function discount_used_by(int $id, string $email): bool
{
    $keys = array_filter([$email !== '' ? email_key($email) : '']);
    if (accounts_on() && ($me = current_customer())) {
        $keys[] = email_key($me['email']);
    }
    if (!$keys) {
        return false;
    }
    $st = db()->prepare("SELECT email FROM orders WHERE discount_id = ? AND status != 'cancelled'");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $used) {
        if (in_array(email_key($used), $keys, true)) {
            return true;
        }
    }
    return false;
}

/* Is any code switched on? (If not, the basket doesn't show the code box.) */
function discounts_live(): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM discounts WHERE active = 1 AND (ends = '' OR ends >= ?)");
    $st->execute([uk_today()]);
    return (int)$st->fetchColumn() > 0;
}

/* What the code gives, in a few words: "10% off", "£5.00 off and free delivery". */
function discount_offer(array $d): string
{
    $bits = [];
    if ((int)$d['percent'] > 0) {
        $bits[] = (int)$d['percent'] . '% off';
    } elseif ((int)$d['amount_pence'] > 0) {
        $bits[] = money((int)$d['amount_pence']) . ' off';
    }
    if ((int)$d['free_postage']) {
        $bits[] = 'free delivery';
    }
    return ucfirst(implode(' and ', $bits));
}

/* Why the code can't be used right now, or null if it can. The second value
   says whether to keep it on the basket anyway (only for a minimum spend not
   reached yet, which more shopping can fix). */
function discount_problem(array $d, int $subtotal, string $email = ''): ?array
{
    $code = $d['code'];
    $today = uk_today();
    if (!(int)$d['active']) {
        return ['Sorry, ' . $code . ' isn\'t a code we recognise.', false];
    }
    if ($d['starts'] !== '' && $today < $d['starts']) {
        return [$code . ' can be used from ' . uk_date($d['starts']) . '.', false];
    }
    if ($d['ends'] !== '' && $today > $d['ends']) {
        return ['Sorry, ' . $code . ' has expired.', false];
    }
    if ($d['max_uses'] !== null && discount_uses((int)$d['id']) >= (int)$d['max_uses']) {
        return ['Sorry, ' . $code . ' has been used up.', false];
    }
    if ((int)$d['once_each'] && discount_used_by((int)$d['id'], $email)) {
        return ['You\'ve already used ' . $code . ' on an earlier order. It can be used once per customer.', false];
    }
    if ((int)$d['min_spend'] > 0 && $subtotal < (int)$d['min_spend']) {
        return ['Spend ' . money((int)$d['min_spend'] - $subtotal) . ' more to use ' . $code . ' (it\'s for orders of '
                . money((int)$d['min_spend']) . ' or more).', true];
    }
    return null;
}

/* How much the code takes off these goods. Never more than the goods cost. */
function discount_amount(array $d, int $subtotal): int
{
    if ((int)$d['percent'] > 0) {
        return min($subtotal, (int)round($subtotal * (int)$d['percent'] / 100));
    }
    return min($subtotal, (int)$d['amount_pence']);
}

/* The code on this visitor's basket, worked out against the goods total.
   A code that's stopped working comes off, with a note saying why. */
function basket_discount(int $subtotal, string $email = ''): array
{
    $none = ['d' => null, 'pence' => 0, 'note' => null];
    $code = (string)($_SESSION['shop_discount'] ?? '');
    if ($code === '') {
        return $none;
    }
    $d = discount_by_code($code);
    if (!$d) {
        unset($_SESSION['shop_discount']);
        return ['note' => 'Sorry, ' . $code . ' isn\'t a code we recognise any more, so it\'s been taken off.'] + $none;
    }
    if ($why = discount_problem($d, $subtotal, $email)) {
        if (!$why[1]) {
            unset($_SESSION['shop_discount']);
        }
        return ['note' => $why[0]] + $none;
    }
    return ['d' => $d, 'pence' => discount_amount($d, $subtotal), 'note' => null];
}

/* From the basket form: apply the code typed in, or take the current one off. */
function basket_discount_post(int $subtotal): ?string
{
    if (!empty($_POST['discount_remove'])) {
        unset($_SESSION['shop_discount']);
        return 'Discount code removed.';
    }
    $code = discount_code_clean(mb_substr(post('discount_code'), 0, 40));
    if ($code === '') {
        return null;
    }
    $key = 'dcode:' . client_ip();
    if (throttled($key, 20, 3600)) {
        return 'Too many codes tried. Please wait a while and try again.';
    }
    $d = discount_by_code($code);
    if (!$d || !(int)$d['active']) {
        throttle_hit($key);
        return 'Sorry, ' . $code . ' isn\'t a code we recognise.';
    }
    $me = function_exists('current_customer') ? current_customer() : null;
    $why = discount_problem($d, $subtotal, $me['email'] ?? '');
    if ($why && !$why[1]) {
        return $why[0];
    }
    $_SESSION['shop_discount'] = $d['code'];
    return $why ? 'Code ' . $d['code'] . ' saved. ' . $why[0] : 'Code ' . $d['code'] . ' applied: ' . lcfirst(discount_offer($d)) . '.';
}

/* --------------------------------------------------------- admin: discounts */

function discount_state(array $d): array   // [label, pill class]
{
    $today = uk_today();
    return match (true) {
        !(int)$d['active']                                              => ['Off', ''],
        $d['ends'] !== '' && $today > $d['ends']                        => ['Expired', ''],
        $d['max_uses'] !== null && discount_uses((int)$d['id']) >= (int)$d['max_uses'] => ['Used up', ''],
        $d['starts'] !== '' && $today < $d['starts']                    => ['Starts ' . uk_date($d['starts']), 'wait'],
        default                                                         => ['Live', 'ok'],
    };
}

function discount_rules(array $d): string
{
    $bits = [];
    if ((int)$d['min_spend'] > 0) {
        $bits[] = 'orders of ' . money((int)$d['min_spend']) . '+';
    }
    if ($d['starts'] !== '' || $d['ends'] !== '') {
        $bits[] = $d['starts'] !== '' && $d['ends'] !== '' ? uk_date($d['starts']) . ' to ' . uk_date($d['ends'])
                : ($d['ends'] !== '' ? 'until ' . uk_date($d['ends']) : 'from ' . uk_date($d['starts']));
    }
    if ((int)$d['once_each']) {
        $bits[] = 'once per customer';
    }
    return implode(' · ', $bits);
}

function view_admin_discounts(): never
{
    require_admin();
    if (is_post()) {
        csrf_check();
        $d = discount_by_id((int)post('id'));
        if ($d && post('do') === 'toggle') {
            db()->prepare('UPDATE discounts SET active = ? WHERE id = ?')->execute([(int)$d['active'] ? 0 : 1, $d['id']]);
            flash($d['code'] . ((int)$d['active'] ? ' is off. Customers can\'t use it.' : ' is on.'));
        }
        redirect(admin_url('discounts'));
    }
    $list = '';
    foreach (db()->query('SELECT * FROM discounts ORDER BY active DESC, id DESC') as $d) {
        [$state, $cls] = discount_state($d);
        $uses = discount_uses((int)$d['id']);
        $rules = discount_rules($d);
        $list .= '<li><div class="pname"><a href="' . e(admin_url('discount/' . $d['id'])) . '"><code>' . e($d['code']) . '</code></a>'
            . ' <span class="pill ' . $cls . '">' . e($state) . '</span>'
            . '<small>' . e(discount_offer($d)) . ($rules !== '' ? ' · ' . e($rules) : '') . '</small>'
            . '<small>Used ' . $uses . ' time' . ($uses === 1 ? '' : 's') . ($d['max_uses'] !== null ? ' of ' . (int)$d['max_uses'] : '')
            . ($d['note'] !== '' ? ' · ' . e($d['note']) : '') . '</small></div>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$d['id'] . '">'
            . '<button class="quiet small" name="do" value="toggle">' . ((int)$d['active'] ? 'Switch off' : 'Switch on') . '</button></form></li>';
    }
    admin_shell('Discounts', '<div class="topline"><h1>Discount codes</h1><a class="btn quiet" href="' . e(admin_url('discount/new')) . '">Add a code</a></div>'
        . '<p class="lede">Codes customers type in the basket for money off or free delivery.</p>'
        . ($list !== '' ? '<ul class="plist">' . $list . '</ul>'
            : '<p class="muted">No codes yet. Add one for a sale, a newsletter, or to say thank you to a regular.</p>'));
}

function view_admin_discount(string $which): never
{
    require_admin();
    $d = $which === 'new' ? null : discount_by_id((int)$which);
    if ($which !== 'new' && !$d) {
        redirect(admin_url('discounts'));
    }
    $uses = $d ? discount_uses((int)$d['id']) : 0;
    $errors = [];
    $v = $d ? [
        'code' => $d['code'], 'kind' => (int)$d['percent'] > 0 ? 'percent' : ((int)$d['amount_pence'] > 0 ? 'amount' : 'none'),
        'percent' => (int)$d['percent'] > 0 ? (string)(int)$d['percent'] : '',
        'amount' => (int)$d['amount_pence'] > 0 ? price_input((int)$d['amount_pence']) : '',
        'free_postage' => (bool)(int)$d['free_postage'], 'min_spend' => (int)$d['min_spend'] > 0 ? price_input((int)$d['min_spend']) : '',
        'starts' => $d['starts'], 'ends' => $d['ends'], 'max_uses' => $d['max_uses'] === null ? '' : (string)(int)$d['max_uses'],
        'once_each' => (bool)(int)$d['once_each'], 'active' => (bool)(int)$d['active'], 'note' => $d['note'],
    ] : ['code' => '', 'kind' => 'percent', 'percent' => '', 'amount' => '', 'free_postage' => false, 'min_spend' => '',
         'starts' => '', 'ends' => '', 'max_uses' => '', 'once_each' => false, 'active' => true, 'note' => ''];

    if (is_post()) {
        csrf_check();
        if ($d && post('do') === 'delete') {
            if ($uses > 0 || (int)db()->query('SELECT COUNT(*) FROM orders WHERE discount_id = ' . (int)$d['id'])->fetchColumn() > 0) {
                flash($d['code'] . ' has been used on orders, so it can\'t be deleted. Switch it off instead.');
                redirect(admin_url('discount/' . $d['id']));
            }
            db()->prepare('DELETE FROM discounts WHERE id = ?')->execute([$d['id']]);
            flash($d['code'] . ' deleted.');
            redirect(admin_url('discounts'));
        }
        $v = ['code' => discount_code_clean(post('code')), 'kind' => in_array(post('kind'), ['percent', 'amount', 'none'], true) ? post('kind') : 'percent',
              'percent' => post('percent'), 'amount' => post('amount'), 'free_postage' => !empty($_POST['free_postage']),
              'min_spend' => post('min_spend'), 'starts' => post('starts'), 'ends' => post('ends'), 'max_uses' => post('max_uses'),
              'once_each' => !empty($_POST['once_each']), 'active' => !empty($_POST['active']), 'note' => mb_substr(post('note'), 0, 120)];
        if (!preg_match('/^[A-Z0-9_-]{' . ($d && $d['code'] === $v['code'] ? 3 : 6) . ',30}$/', $v['code'])) {
            $errors[] = 'The code needs 6 to 30 letters or numbers (dashes are fine too), like SUMMER10. Short codes are easy to guess.';
        } else {
            $other = discount_by_code($v['code']);
            if ($other && (!$d || (int)$other['id'] !== (int)$d['id'])) {
                $errors[] = 'There\'s already a code called ' . $v['code'] . '.';
            }
        }
        $pct = 0;
        $amt = 0;
        if ($v['kind'] === 'percent') {
            if (!ctype_digit($v['percent']) || (int)$v['percent'] < 1 || (int)$v['percent'] > DISCOUNT_PERCENT_MAX) {
                $errors[] = 'The percentage off is a whole number from 1 to ' . DISCOUNT_PERCENT_MAX . '.';
            } else {
                $pct = (int)$v['percent'];
            }
        } elseif ($v['kind'] === 'amount') {
            $p = parse_price($v['amount']);
            if ($p === null || $p < 1) {
                $errors[] = 'The amount off needs to be a price, like 5 or 7.50.';
            } else {
                $amt = $p;
            }
        } elseif (!$v['free_postage']) {
            $errors[] = 'A code needs to give something: money off, free delivery, or both.';
        }
        $min = 0;
        if ($v['min_spend'] !== '') {
            $m = parse_price($v['min_spend']);
            $m === null ? $errors[] = 'The minimum spend needs to be a price, like 30.' : $min = $m;
        }
        foreach (['starts' => 'start', 'ends' => 'end'] as $k => $what) {
            if ($v[$k] !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $v[$k])) {
                $errors[] = 'The ' . $what . ' date doesn\'t look right.';
                $v[$k] = '';
            }
        }
        if ($v['starts'] !== '' && $v['ends'] !== '' && $v['ends'] < $v['starts']) {
            $errors[] = 'The end date is before the start date.';
        }
        if ($v['max_uses'] !== '' && (!ctype_digit($v['max_uses']) || (int)$v['max_uses'] < 1)) {
            $errors[] = 'The limit on orders is a whole number, like 50, or leave it empty for no limit.';
        }
        if (!$errors) {
            $row = [$v['code'], $pct, $amt, $v['free_postage'] ? 1 : 0, $min, $v['starts'], $v['ends'],
                    $v['max_uses'] === '' ? null : (int)$v['max_uses'], $v['once_each'] ? 1 : 0, $v['active'] ? 1 : 0, $v['note']];
            if ($d) {
                db()->prepare('UPDATE discounts SET code = ?, percent = ?, amount_pence = ?, free_postage = ?, min_spend = ?, starts = ?, ends = ?,
                               max_uses = ?, once_each = ?, active = ?, note = ? WHERE id = ?')->execute([...$row, $d['id']]);
            } else {
                db()->prepare('INSERT INTO discounts (code, percent, amount_pence, free_postage, min_spend, starts, ends, max_uses, once_each, active, note, created)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$row, now()]);
            }
            flash($v['code'] . ' saved' . ($v['active'] ? '. Customers can use it in the basket.' : ', switched off.'));
            redirect(admin_url('discounts'));
        }
    }

    $radio = fn(string $k, string $label, string $input) => '<label class="check"><input type="radio" name="kind" value="' . $k . '"'
        . ($v['kind'] === $k ? ' checked' : '') . '> ' . $label . '</label>' . $input;
    $orders = '';
    if ($d) {
        $st = db()->prepare('SELECT * FROM orders WHERE discount_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$d['id']]);
        foreach ($st->fetchAll() as $o) {
            $orders .= '<tr><td><a href="' . e(admin_url('order/' . $o['id'])) . '">#' . (int)$o['number'] . '</a> ' . e($o['bill_name']) . '</td><td>'
                . status_pill($o) . '</td><td style="text-align:right">−' . money((int)$o['discount_pence']) . '</td></tr>';
        }
    }
    admin_shell($d ? $d['code'] : 'New discount code', '<p class="muted"><a href="' . e(admin_url('discounts')) . '">&larr; Discount codes</a></p>'
        . '<h1>' . ($d ? e($d['code']) : 'New discount code') . '</h1>'
        . ($d ? '<p class="muted">Used ' . $uses . ' time' . ($uses === 1 ? '' : 's') . '. Cancelled orders don\'t count.</p>' : '')
        . ($errors ? '<div class="err"><strong>Please check:</strong><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '')
        . '<form method="post">' . csrf_field()
        . '<label>Code <span class="hint">(what customers type; capitals and spaces don\'t matter)</span>'
        . '<input name="code" value="' . e($v['code']) . '" maxlength="30" required autocapitalize="characters" autocomplete="off" placeholder="SUMMER10"></label>'
        . '<h2>What it gives</h2>'
        . $radio('percent', 'A percentage off the goods', '<div class="price" style="max-width:9rem"><input name="percent" inputmode="numeric" value="' . e($v['percent']) . '" placeholder="10"><span>%</span></div>')
        . $radio('amount', 'An amount off the goods', '<div class="price" style="max-width:9rem"><span>£</span><input name="amount" inputmode="decimal" value="' . e($v['amount']) . '" placeholder="5.00"></div>')
        . $radio('none', 'No money off', '')
        . '<label class="check" style="margin-top:1.4rem"><input type="checkbox" name="free_postage" value="1"' . ($v['free_postage'] ? ' checked' : '') . '> Free delivery as well</label>'
        . '<p class="muted">Money off comes off the goods, not delivery. Free delivery only matters to customers having it delivered.</p>'
        . '<h2>Rules <span class="hint" style="font-family:inherit;font-size:.85rem">(all optional)</span></h2>'
        . '<label>Minimum spend on goods <div class="price" style="max-width:9rem"><span>£</span><input name="min_spend" inputmode="decimal" value="' . e($v['min_spend']) . '" placeholder="30"></div></label>'
        . '<div class="row"><label>From <input type="date" name="starts" value="' . e($v['starts']) . '"></label>'
        . '<label>Until <span class="hint">(this day included)</span><input type="date" name="ends" value="' . e($v['ends']) . '"></label></div>'
        . '<label>Stop after this many orders <input type="number" name="max_uses" min="1" inputmode="numeric" value="' . e($v['max_uses']) . '" placeholder="No limit" style="max-width:9rem;display:block"></label>'
        . '<label class="check"><input type="checkbox" name="once_each" value="1"' . ($v['once_each'] ? ' checked' : '') . '> Once per customer <span class="hint">(by email address)</span></label>'
        . '<label>Note for you <span class="hint">(optional, e.g. "Newsletter, May")</span><input name="note" value="' . e($v['note']) . '" maxlength="120"></label>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ($v['active'] ? ' checked' : '') . '> Switched on</label>'
        . '<button>' . ($d ? 'Save code' : 'Add code') . '</button></form>'
        . ($d ? '<h2>Orders using it</h2>' . ($orders !== '' ? '<table>' . $orders . '</table>' : '<p class="muted">None yet.</p>')
            . ($uses === 0 && $orders === '' ? '<form method="post" onsubmit="return confirm(\'Delete ' . e($d['code']) . '?\')">' . csrf_field()
                . '<input type="hidden" name="do" value="delete"><button class="danger" style="margin-top:2rem">Delete code</button></form>' : '') : ''));
}

/* ================================================================ low stock */

/* After an order takes stock, email the shop about anything it has just
   brought down to the low-stock level or below. Each item is only mentioned
   when this order made it cross the line, so the shop isn't emailed again on
   every later order. Called when a bank or collection order is placed, and when
   a PayPal payment completes (an abandoned PayPal payment puts its stock back). */
function low_stock_check(int $oid): void
{
    if (setting('low_stock_on', '1') !== '1') {
        return;
    }
    $level = (int)setting('low_stock_level', '3');
    $hit = [];
    foreach (order_items($oid) as $it) {
        if ($it['variant_id'] !== null) {
            $st = db()->prepare('SELECT v.stock, v.name AS vname, v.name2 AS vname2, p.name, p.option_name, p.option2_name, p.id AS pid, p.backorders FROM variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?');
            $st->execute([$it['variant_id']]);
        } else {
            $st = db()->prepare('SELECT stock, name, id AS pid, backorders FROM products WHERE id = ?');
            $st->execute([$it['product_id']]);
        }
        $r = $st->fetch();
        if (!$r || $r['stock'] === null || $r['backorders'] !== 'no') {   // products that allow backorders never run out
            continue;
        }
        $now = (int)$r['stock'];
        if ($now + (int)$it['qty'] > $level && $now <= $level) {
            $hit[] = ['name' => $r['name'] . ($it['variant_id'] !== null ? ' (' . choice_label($r, ['name' => $r['vname'], 'name2' => $r['vname2']]) . ')' : ''),
                      'left' => $now, 'pid' => (int)$r['pid']];
        }
    }
    if (!$hit || ($to = mail_shop_address()) === '') {
        return;
    }
    $o = order_by('id', $oid);
    $out = array_filter($hit, fn($h) => $h['left'] <= 0);
    $subject = count($hit) === 1
        ? ($hit[0]['left'] <= 0 ? 'Sold out: ' : 'Low stock: ') . $hit[0]['name'] . ($hit[0]['left'] > 0 ? ' (' . $hit[0]['left'] . ' left)' : '')
        : ($out ? 'Sold out and low stock: ' : 'Low stock: ') . count($hit) . ' items';
    $rows = '';
    $text = '';
    $td = 'padding:8px 0;border-bottom:1px solid #e5e7eb';
    foreach ($hit as $h) {
        $left = $h['left'] <= 0 ? 'Sold out' : $h['left'] . ' left';
        $rows .= '<tr><td style="' . $td . '"><a href="' . e(origin() . admin_url('edit/' . $h['pid'])) . '" style="color:#1f2328">' . e($h['name']) . '</a></td>'
               . '<td style="' . $td . ';text-align:right;white-space:nowrap;padding-left:12px;font-weight:600' . ($h['left'] <= 0 ? ';color:#b42318' : '') . '">' . $left . '</td></tr>';
        $text .= $h['name'] . ': ' . $left . "\n";
    }
    $why = 'After order #' . ($o['number'] ?? '?') . ', ' . (count($hit) === 1 ? 'this is' : 'these are') . ' down to ' . $level . ' or fewer.';
    $html = mail_layout(count($hit) === 1 && $out ? 'Sold out' : 'Running low', [e($why)],
        '<table style="width:100%;border-collapse:collapse;font-size:15px;margin-top:8px">' . $rows . '</table>',
        origin() . admin_url(), 'Open products', 'Sent by Wren Shop. Change the level or switch these emails off in Settings, Low stock.');
    mail_queue($oid, 'low_stock', $to, $subject, $why . "\n\n" . $text . "\nOpen products: " . origin() . admin_url() . "\n", $html);
}
/* ======================================================== customer groups */
/* A group (Reseller, Trade, Staff...) has a main discount and optional rates
   for particular categories (0% leaves a category at full price). A customer
   in a group sees their prices across the shop, but only while signed in and
   only once their email address is confirmed, so nobody can register a
   reseller's address and shop at their prices. A group price and a discount
   code never add up: the basket uses whichever gives the lower total. */

const GROUP_PERCENT_MAX = 99;

function group_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM customer_groups WHERE id = ?');
    $st->execute([$id]);
    $g = $st->fetch();
    if (!$g) {
        return null;
    }
    $g['rates'] = [];
    $st = db()->prepare('SELECT category_id, percent FROM group_rates WHERE group_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) {
        $g['rates'][(int)$r['category_id']] = (int)$r['percent'];
    }
    return $g;
}

function all_groups(): array
{
    return db()->query('SELECT * FROM customer_groups ORDER BY name COLLATE NOCASE')->fetchAll();
}

/* The signed-in customer's group, if their prices apply right now. */
function customer_group(): ?array
{
    static $cache = [];
    if (!accounts_on()) {
        return null;
    }
    $c = current_customer();
    if (!$c || $c['group_id'] === null || $c['verified_at'] === '') {
        return null;
    }
    $id = (int)$c['group_id'];
    return $cache[$id] ??= group_by_id($id);
}

function group_rate(?array $g, array $p): int
{
    if (!$g) {
        return 0;
    }
    // The main category's own rate, else the nearest category it sits in that has one.
    foreach (array_reverse(cat_trail((int)$p['category_id'])) as $c) {
        if (isset($g['rates'][$c['id']])) {
            return $g['rates'][$c['id']];
        }
    }
    return (int)$g['percent'];
}

/* A group's fixed prices, "product:choice" => pence (choice 0 = the product itself). */
function group_fixed_prices(int $gid): array
{
    static $all = [];
    if (!isset($all[$gid])) {
        $all[$gid] = [];
        $st = db()->prepare('SELECT product_id, variant_id, price_pence FROM group_prices WHERE group_id = ?');
        $st->execute([$gid]);
        foreach ($st->fetchAll() as $r) {
            $all[$gid][$r['product_id'] . ':' . $r['variant_id']] = (int)$r['price_pence'];
        }
    }
    return $all[$gid];
}

/* The group's fixed price for one, if it has one: the choice's own, else the
   product's (for a choice at the product's price). */
function group_fixed(?array $g, array $p, ?array $v): ?int
{
    if (!$g) {
        return null;
    }
    $f = group_fixed_prices((int)$g['id']);
    if ($v && isset($f[$p['id'] . ':' . $v['id']])) {
        return $f[$p['id'] . ':' . $v['id']];
    }
    if (!$v || $v['price_pence'] === null) {
        return $f[$p['id'] . ':0'] ?? null;
    }
    return null;
}

/* What the group takes off one of these at its full price $unit: down to a fixed
   price where one is set, else the group's percentage. Never a price rise. */
function group_unit_saving(array $p, int $unit, ?array $v = null): int
{
    $g = customer_group();
    $fixed = group_fixed($g, $p, $v);
    if ($fixed !== null) {
        return max(0, $unit - $fixed);
    }
    $rate = group_rate($g, $p);
    return $rate > 0 ? (int)round($unit * $rate / 100) : 0;
}

/* What the group takes off these lines. On a line already on sale, only what the
   group price beats the sale price by (the two never add up). */
function lines_group_saving(array $lines): int
{
    $s = 0;
    foreach ($lines as $l) {
        $full = $l['full'] ?? $l['price'];
        $s += $l['qty'] * max(0, $l['price'] - ($full - group_unit_saving($l['p'], $full, $l['v'] ?? null)));
    }
    return $s;
}

function group_price_html(array $p, int $full, ?string $prefix = null): string
{
    $save = group_unit_saving($p, $full);
    if ($save <= 0) {
        return ($prefix ?? '') . money($full);
    }
    $g = customer_group();
    return ($prefix ?? '') . money($full - $save) . ' <s class="shop-was">' . money($full) . '</s> <small class="shop-yours">'
         . e($g['name']) . ' price</small>';
}

/* The price on product cards and pages: the customer's own price when it differs. */
function shop_price_html(array $p, array $vars): string
{
    if (!$vars) {
        return own_price_html($p, null);
    }
    // The cheapest choice for this customer (sale or group price), "From" when choices differ.
    $best = null;
    $prices = [];
    foreach ($vars as $v) {
        $own = own_price($p, $v)[0];
        $prices[$own] = true;
        if ($best === null || $own < $best[0]) {
            $best = [$own, $v];
        }
    }
    return own_price_html($p, $best[1], count($prices) > 1 ? 'From ' : '');
}

function group_offer(array $g): string
{
    $bits = [];
    if ((int)$g['percent'] > 0) {
        $bits[] = (int)$g['percent'] . '% off everything';
    }
    if (!empty($g['rates'])) {
        $names = [];
        foreach (cat_tree() as $c) {
            if (isset($g['rates'][(int)$c['id']])) {
                $names[] = cat_path_name($c['id']) . ' ' . $g['rates'][(int)$c['id']] . '%';
            }
        }
        if ($names) {
            $bits[] = ((int)$g['percent'] > 0 ? 'except ' : '') . implode(', ', $names);
        }
    }
    $fixed = (int)db()->query('SELECT COUNT(DISTINCT product_id) FROM group_prices WHERE group_id = ' . (int)$g['id'])->fetchColumn();
    if ($fixed) {
        $bits[] = ($bits ? 'and ' : '') . 'fixed prices on ' . $fixed . ' product' . ($fixed === 1 ? '' : 's');
    }
    return $bits ? implode(' ', $bits) : 'No discount set';
}

/* ------------------------------------------------------------ admin: groups */

function view_admin_groups(): never
{
    require_admin();
    $list = '';
    foreach (all_groups() as $row) {
        $g = group_by_id((int)$row['id']);
        $n = (int)db()->query('SELECT COUNT(*) FROM customers WHERE group_id = ' . (int)$g['id'])->fetchColumn();
        $list .= '<li><div class="pname"><a href="' . e(admin_url('group/' . $g['id'])) . '">' . e($g['name']) . '</a>'
            . '<small>' . e(group_offer($g)) . '</small><small>' . $n . ' customer' . ($n === 1 ? '' : 's') . '</small></div></li>';
    }
    admin_shell('Customer groups', ''
        . '<div class="topline"><h1>Customer groups</h1><a class="btn quiet" href="' . e(admin_url('group/new')) . '">Add a group</a></div>'
        . '<p class="lede">Give a group of customers, like resellers or trade, their own discount. They see their prices whenever they\'re signed in.</p>'
        . (!accounts_on() ? '<p class="flash">Customer accounts are switched off in Settings, so nobody can sign in to get group prices.</p>' : '')
        . ($list !== '' ? '<ul class="plist">' . $list . '</ul>' : '<p class="muted">No groups yet.</p>')
        . '<p class="muted">To put a customer in a group, open them in Customers. They need an account with a confirmed email address.</p>');
}

function view_admin_group(string $which): never
{
    require_admin();
    $g = $which === 'new' ? null : group_by_id((int)$which);
    if ($which !== 'new' && !$g) {
        redirect(admin_url('groups'));
    }
    $cats = cat_tree();
    $errors = [];
    $v = ['name' => $g['name'] ?? '', 'percent' => $g ? (string)(int)$g['percent'] : '', 'rates' => [],
          'ex_vat' => (bool)($g['ex_vat'] ?? false), 'min_order' => isset($g['min_order_pence']) ? price_input((int)$g['min_order_pence']) : '',
          'pay' => $g && $g['pay_methods'] !== '' ? explode(',', $g['pay_methods']) : [], 'apply' => $g && (int)setting('trade_apply_group') === (int)$g['id']];
    foreach ($cats as $c) {
        $v['rates'][(int)$c['id']] = isset($g['rates'][(int)$c['id']]) ? (string)$g['rates'][(int)$c['id']] : '';
    }
    if (is_post()) {
        csrf_check();
        if ($g && post('do') === 'delete') {
            db()->prepare('UPDATE customers SET group_id = NULL WHERE group_id = ?')->execute([$g['id']]);
            db()->prepare('DELETE FROM customer_groups WHERE id = ?')->execute([$g['id']]);
            db()->prepare('DELETE FROM group_prices WHERE group_id = ?')->execute([$g['id']]);
            if ((int)setting('trade_apply_group') === (int)$g['id']) {
                set_setting('trade_apply_group', '0');
            }
            flash('Group ' . $g['name'] . ' deleted. Its customers pay normal prices now.');
            redirect(admin_url('groups'));
        }
        $v['name'] = trim(mb_substr(post('name'), 0, 40));
        $v['percent'] = trim(post('percent'));
        $v['ex_vat'] = !empty($_POST['ex_vat']);
        $v['min_order'] = post('min_order');
        $v['pay'] = array_values(array_intersect(array_keys(PAY_LABELS), is_array($_POST['pay'] ?? null) ? $_POST['pay'] : []));
        $v['apply'] = !empty($_POST['apply']);
        $minP = $v['min_order'] === '' ? null : parse_price($v['min_order']);
        if ($v['min_order'] !== '' && $minP === null) {
            $errors[] = 'The minimum order should be an amount, like 100.';
        }
        $in = is_array($_POST['rate'] ?? null) ? $_POST['rate'] : [];
        foreach ($cats as $c) {
            $v['rates'][(int)$c['id']] = is_string($in[$c['id']] ?? null) ? trim($in[$c['id']]) : '';
        }
        if ($v['name'] === '') {
            $errors[] = 'Give the group a name, like Reseller.';
        } else {
            $st = db()->prepare('SELECT id FROM customer_groups WHERE name = ?');
            $st->execute([$v['name']]);
            $other = $st->fetchColumn();
            if ($other && (!$g || (int)$other !== (int)$g['id'])) {
                $errors[] = 'There\'s already a group called ' . $v['name'] . '.';
            }
        }
        $pct = fn(string $x) => $x !== '' && ctype_digit($x) && (int)$x <= GROUP_PERCENT_MAX;
        if ($v['percent'] === '') {
            $v['percent'] = '0';
        }
        if (!$pct($v['percent'])) {
            $errors[] = 'The discount is a whole number from 0 to ' . GROUP_PERCENT_MAX . '.';
        }
        foreach ($cats as $c) {
            $r = $v['rates'][(int)$c['id']];
            if ($r !== '' && !$pct($r)) {
                $errors[] = 'The rate for ' . $c['name'] . ' is a whole number from 0 to ' . GROUP_PERCENT_MAX . ', or leave it empty.';
            }
        }
        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            if ($g) {
                $pdo->prepare('UPDATE customer_groups SET name = ?, percent = ? WHERE id = ?')->execute([$v['name'], (int)$v['percent'], $g['id']]);
                $gid = (int)$g['id'];
            } else {
                $pdo->prepare('INSERT INTO customer_groups (name, percent, created) VALUES (?, ?, ?)')->execute([$v['name'], (int)$v['percent'], now()]);
                $gid = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE customer_groups SET ex_vat = ?, min_order_pence = ?, pay_methods = ? WHERE id = ?')
                ->execute([$v['ex_vat'] ? 1 : 0, $minP ?: null, implode(',', $v['pay']), $gid]);
            if ($v['apply']) {
                set_setting('trade_apply_group', (string)$gid);
            } elseif ((int)setting('trade_apply_group') === $gid) {
                set_setting('trade_apply_group', '0');
            }
            $pdo->prepare('DELETE FROM group_rates WHERE group_id = ?')->execute([$gid]);
            foreach ($cats as $c) {
                $r = $v['rates'][(int)$c['id']];
                // A category inside another can need the main rate set explicitly, to undo its parent's.
                if ($r !== '' && ((int)$r !== (int)$v['percent'] || $c['depth'] > 1)) {
                    $pdo->prepare('INSERT INTO group_rates (group_id, category_id, percent) VALUES (?, ?, ?)')->execute([$gid, $c['id'], (int)$r]);
                }
            }
            $pdo->commit();
            flash($v['name'] . ' saved.');
            redirect(admin_url('group/' . $gid));
        }
    }
    $rows = '';
    foreach ($cats as $c) {
        $rows .= '<tr><td style="padding-left:' . (.3 + ($c['depth'] - 1) * 1.5) . 'rem">' . ($c['depth'] > 1 ? '<span class="muted">&rsaquo;</span> ' : '') . e($c['name']) . '</td><td><div class="price" style="max-width:8rem"><input name="rate[' . (int)$c['id'] . ']" inputmode="numeric" value="'
               . e($v['rates'][(int)$c['id']]) . '" placeholder="' . e($v['percent'] === '' ? '0' : $v['percent']) . '" aria-label="Discount on ' . e($c['name']) . '"><span>%</span></div></td></tr>';
    }
    $members = '';
    if ($g) {
        $st = db()->prepare('SELECT * FROM customers WHERE group_id = ? ORDER BY name COLLATE NOCASE, email');
        $st->execute([$g['id']]);
        foreach ($st->fetchAll() as $c) {
            $members .= '<tr><td><a href="' . e(admin_url('customer/' . $c['id'])) . '">' . e($c['name'] ?: '(no name)') . '</a><br><span class="muted">' . e($c['email'])
                      . '</span></td><td>' . ($c['verified_at'] === '' ? '<span class="pill wait">Email not confirmed yet</span>' : '') . '</td></tr>';
        }
    }
    admin_shell($g ? $g['name'] : 'New group', ($g ? '<p class="muted"><a href="' . e(admin_url('groups')) . '">&larr; Customer groups</a></p>' : '')
        . '<h1>' . ($g ? e($g['name']) : 'New customer group') . '</h1>'
        . ($errors ? '<div class="err"><strong>Please check:</strong><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '')
        . '<form method="post">' . csrf_field()
        . '<label>Name <span class="hint">(customers in the group see it, e.g. "Reseller price")</span><input name="name" value="' . e($v['name']) . '" maxlength="40" required placeholder="Reseller"></label>'
        . '<label>Discount on everything <div class="price" style="max-width:8rem"><input name="percent" inputmode="numeric" value="' . e($v['percent']) . '" placeholder="25"><span>%</span></div></label>'
        . ($cats ? '<h2>Different rates for some categories <span class="hint" style="font-family:inherit;font-size:.85rem">(optional)</span></h2>'
            . '<p class="muted">Leave a category empty to use the rate above (or the rate of the category it sits in). Put 0 to keep a category at full price. To discount only certain categories, set the rate above to 0 and fill in just those.</p>'
            . '<table>' . $rows . '</table>' : '')
        . '<p class="muted">The discount comes off the goods, not delivery. If a customer also has a discount code, they get whichever saves them more, never both. '
        . 'For a fixed price on a particular product instead, open the product and use Group prices.</p>'
        . '<h2>Trade settings <span class="hint" style="font-family:inherit;font-size:.85rem">(optional)</span></h2>'
        . '<label class="check"><input type="checkbox" name="ex_vat" value="1"' . ($v['ex_vat'] ? ' checked' : '') . '> Show this group prices without VAT</label>'
        . '<p class="muted" style="margin-top:0">' . (setting('vat_registered') === '1' ? 'Prices show "ex VAT", with the VAT added as its own line in the basket. They pay the same total.'
            : 'Only once you\'re VAT-registered (Settings → Business and VAT): until then prices show as they are.') . '</p>'
        . '<label>Minimum order <span class="hint">(goods, after their prices; empty for none)</span><div class="price" style="max-width:10rem"><span>£</span><input name="min_order" inputmode="decimal" placeholder="100" value="' . e($v['min_order']) . '"></div></label>'
        . '<fieldset class="paypick"><legend>Ways to pay</legend><p class="muted" style="margin-top:0">Leave all unticked to pay the same ways as everyone else. Tick to choose this group\'s own, '
        . 'for example bank transfer for trade only. Each needs its details filled in under Settings → Payments, even if it\'s switched off for everyone else.</p>'
        . implode('', array_map(fn($m) => '<label class="check"><input type="checkbox" name="pay[]" value="' . $m . '"' . (in_array($m, $v['pay'], true) ? ' checked' : '') . '> '
            . PAY_LABELS[$m] . (pay_ready($m) ? '' : ' <span class="hint">(not set up yet)</span>') . '</label>', array_keys(PAY_LABELS))) . '</fieldset>'
        . '<label class="check"><input type="checkbox" name="apply" value="1"' . ($v['apply'] ? ' checked' : '') . '> Customers can apply to join this group</label>'
        . '<p class="muted" style="margin-top:0">Adds "Apply for a ' . e($v['name'] ?: 'trade') . ' account" to customers\' accounts. You approve each one in Customers → Trade applications. One group at a time takes applications.</p>'
        . '<button>' . ($g ? 'Save group' : 'Add group') . '</button></form>'
        . ($g ? '<h2>Customers in this group</h2>' . ($members !== '' ? '<table>' . $members . '</table>' : '<p class="muted">None yet. Open a customer in Customers to add them.</p>')
            . '<form method="post" onsubmit="return confirm(\'Delete the ' . e($g['name']) . ' group? Its customers go back to normal prices.\')">' . csrf_field()
            . '<input type="hidden" name="do" value="delete"><button class="danger" style="margin-top:2rem">Delete group</button></form>' : ''));
}
/* ============================================================ getting ready */
/* Two things that stand between a fresh install and a shop that's safe to open:

   1. The data folder (database, sessions, setup code) must not be reachable
      from the web. The shop ships an .htaccess for that, which Apache and
      LiteSpeed obey but nginx ignores. So the shop asks for its own database
      over HTTP now and then, and if it gets it, every admin page says so in red.

   2. A "Getting ready" checklist of everything a shop needs before it takes
      real orders. It doubles as the handover checklist for a client. */

const DATA_CHECK_TTL = 21600;   // re-check the data folder every 6 hours

/* true = anyone can download the database, false = protected, null = couldn't tell. */
function data_exposed(bool $force = false): ?bool
{
    $was = json_decode(setting('data_check'), true);
    if (!$force && is_array($was) && ($was['at'] ?? 0) > time() - DATA_CHECK_TTL) {
        return $was['result'];
    }
    // Two probes: the database itself, and a throwaway file with a random word in
    // it, which catches a server that blocks *.db but serves everything else here.
    $word = bin2hex(random_bytes(12));
    $probe = SHOP_DATA . '/probe.txt';
    @file_put_contents($probe, $word);
    $get = function (string $file, ?string $range) {
        $ch = curl_init(origin() . shop_base() . '/data/' . $file);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,   // e.g. http -> https on the same site
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 3,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_USERAGENT       => 'WrenShop/' . SHOP_VERSION . ' (self-check)',
        ] + ($range ? [CURLOPT_RANGE => $range] : []));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $body === false || $code === 0 ? null : (string)$body;
    };
    $db = $get('shop.db', '0-15');     // the first bytes are enough to recognise it
    $pr = $get('probe.txt', null);
    @unlink($probe);
    $result = $db === null && $pr === null ? null
            : str_starts_with((string)$db, 'SQLite format 3') || str_contains((string)$pr, $word);
    set_setting('data_check', json_encode(['at' => time(), 'result' => $result]));
    return $result;
}

/* The last check's answer, without checking again (for public pages). */
function data_known_exposed(): bool
{
    $was = json_decode(setting('data_check'), true);
    return is_array($was) && ($was['result'] ?? null) === true;
}

function data_warning_html(): string
{
    $dir = shop_base() . '/data/';
    return '<div class="err" id="data-warning"><strong>Your shop\'s private data can be downloaded from the web.</strong> '
        . 'Anyone who knows where to look could download your orders and your customers\' details. '
        . 'Until it\'s fixed, the shop isn\'t taking orders or new accounts.'
        . '<p style="margin:.5rem 0 0">Wren Shop protects <code>' . e($dir) . '</code> with an <code>.htaccess</code> file, but this server isn\'t '
        . 'following it (nginx servers ignore these files). Ask your host to block web access to <code>' . e($dir) . '</code>, or on nginx add:</p>'
        . '<pre style="background:#fff;padding:.5rem .7rem;border-radius:6px;overflow:auto;margin:.5rem 0">location ^~ ' . e($dir) . ' { deny all; }</pre>'
        . '<form method="post" action="' . e(admin_url('ready')) . '" style="margin:0">' . csrf_field()
        . '<input type="hidden" name="do" value="check"><button class="quiet small">Check again</button></form></div>';
}

/* Each item: [title, state, what to do, link, required]. state: true done, false to do, null couldn't check. */
function ready_items(array $u): array
{
    $items = [];
    $add = function (string $title, ?bool $state, string $todo, string $link, bool $required) use (&$items) {
        $items[] = compact('title', 'state', 'todo', 'link', 'required');
    };
    $add('Business name, email and address', setting('business_name') !== '' && setting('business_email') !== '' && trim(setting('business_address')) !== '',
         'UK law expects online sellers to show these. They appear at checkout, on orders and in emails.', settings_url('business', 'business'), true);
    $visible = (int)db()->query('SELECT COUNT(*) FROM products WHERE visible = 1')->fetchColumn();
    $add('At least one product in the shop', $visible > 0, 'Add a product with a name, a price and a photo.', admin_url('new'), true);
    $add('Delivery or collection set up', delivery_ready(),
         'Set a delivery price (0 for free) or offer collection.', settings_url('delivery'), true);
    if (weights_used()) {   // a product with no weight would go for the price of an empty parcel
        $hasV = 'EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id)';
        $nw = (int)db()->query("SELECT COUNT(*) FROM products p WHERE p.visible = 1 AND p.kind != 'digital' AND ((NOT $hasV AND p.weight_g IS NULL)
                 OR (p.weight_g IS NULL AND EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.active = 1 AND v.weight_g IS NULL)))")->fetchColumn();
        $add('Weights on products', $nw === 0, $nw . ' product' . ($nw === 1 ? ' has' : 's have') . ' no weight, so ' . ($nw === 1 ? 'it counts' : 'they count')
             . ' as weighing nothing for delivery priced by weight.', url_with(admin_url(), ['show' => 'noweight']), false);
    }
    $any = pay_enabled('paypal') || pay_enabled('bank') || pay_enabled('collection');
    $add('A way to pay', $any, 'Switch on PayPal, bank transfer or pay on collection, and fill in its details.', settings_url('payments'), true);
    if (setting('pay_paypal') === '1') {
        $add('PayPal taking real payments', setting('paypal_mode') === 'live',
             'PayPal is in Sandbox mode, so payments are pretend. Switch to Live with your live Client ID and Secret.', settings_url('payments'), true);
    }
    $sent = (int)db()->query("SELECT COUNT(*) FROM emails WHERE status = 'sent'")->fetchColumn();
    $add('Emails sending', mail_ready() && $sent > 0,
         mail_ready() ? 'Send yourself a test email to check it arrives.' : 'Fill in your mail server so customers get order confirmations.', settings_url('email'), true);
    $exposed = data_exposed();
    $add('Private data folder protected', $exposed === null ? null : !$exposed,
         $exposed === null ? 'The shop couldn\'t check this by itself. Open ' . origin() . shop_base() . '/data/shop.db in a browser: it should say Forbidden or Not found, never download.'
                           : 'Anyone can download the shop\'s database. See the red box above for the fix.', admin_url('ready'), true);
    $add('Demo mode off', !shop_demo(), 'Demo mode stops customers checking out.', settings_url('general', 'demo'), true);
    $add('Secure address (https)', str_starts_with(origin(), 'https:'),
         'Customers\' details and passwords should only travel encrypted. Turn on SSL with your host (it\'s usually free), then visit the admin over https:// once.', settings_url('advanced', 'site'), true);
    $add('Privacy notice linked', setting('privacy_url') !== '',
         'UK law expects you to tell customers what you do with their details. Link to your privacy page (usually a Wren page).', settings_url('business', 'legal'), true);
    $add('Terms of sale linked', setting('terms_url') !== '',
         'Link to your terms of sale. Customers tick to say they\'ve read them.', settings_url('business', 'legal'), false);
    $add('Two-factor sign-in for you', $u['totp_secret'] !== '', 'Protects the admin even if your password leaks.', settings_url('account', 'twofactor'), false);
    $orders = (int)db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $add('A test order placed', $orders > 0, 'Place an order yourself, check the emails, then cancel or refund it.', shop_url(), false);
    return $items;
}

/* [done, total] counting required items only. */
function ready_score(array $items): array
{
    $req = array_filter($items, fn($i) => $i['required']);
    return [count(array_filter($req, fn($i) => $i['state'] === true)), count($req)];
}

/* The reminder on the Orders and Products pages, until the shop is ready (or hidden). */
function ready_reminder(array $u): string
{
    if (shop_demo() || setting('ready_hidden') === '1') {
        return '';
    }
    [$done, $total] = ready_score(ready_items($u));
    return $done < $total
        ? '<p class="flash">Getting your shop ready: <strong>' . $done . ' of ' . $total . '</strong> essentials done. <a href="' . e(admin_url('ready')) . '">See what\'s left</a></p>'
        : '';
}

function view_admin_ready(): never
{
    $u = require_admin();
    if (is_post()) {
        csrf_check();
        $do = post('do');
        if ($do === 'check') {
            $r = data_exposed(true);
            flash($r === null ? 'The shop couldn\'t reach itself to check. Try the address in the checklist in your browser.'
                : ($r ? 'Still exposed: the database can be downloaded. See the fix in the red box.' : 'Checked: the data folder is protected.'));
        } elseif ($do === 'hide' || $do === 'show') {
            set_setting('ready_hidden', $do === 'hide' ? '1' : '0');
            flash($do === 'hide' ? 'The reminder is hidden. This checklist is always under Settings.' : 'The reminder will show on Orders and Products until the essentials are done.');
        }
        redirect(admin_url('ready'));
    }
    $items = ready_items($u);
    [$done, $total] = ready_score($items);
    $list = function (bool $required) use ($items) {
        $out = '';
        foreach ($items as $i) {
            if ($i['required'] !== $required) {
                continue;
            }
            $mark = $i['state'] === true ? '<span class="tick ok" aria-label="Done">✓</span>'
                  : ($i['state'] === null ? '<span class="tick unk" aria-label="Not checked">?</span>' : '<span class="tick ' . ($required ? 'todo' : 'opt') . '" aria-label="To do"></span>');
            $out .= '<li class="' . ($i['state'] === true ? 'done' : 'todo') . '">' . $mark . '<div class="pname"><strong>' . e($i['title']) . '</strong>'
                  . ($i['state'] !== true ? '<small>' . e($i['todo']) . ' <a href="' . e($i['link']) . '">' . ($i['link'] === shop_url() ? 'Go to the shop' : 'Do it') . '</a></small>' : '')
                  . '</div></li>';
        }
        return $out;
    };
    admin_shell('Getting ready', '<h1>Getting ready</h1>'
        . '<p class="lede">' . ($done === $total ? 'All the essentials are done. Your shop is ready for real orders.'
            : $done . ' of ' . $total . ' essentials done. Work down the list before the shop takes real orders.') . '</p>'
        . '<h2>Essentials</h2><ul class="plist ready">' . $list(true) . '</ul>'
        . '<h2>Recommended</h2><ul class="plist ready">' . $list(false) . '</ul>'
        . '<p class="muted">Handing a shop over to someone else? Go through this list with them, make sure the admin account is theirs, and show them the '
        . '<a href="' . e(SHOP_HELP_URL) . '" target="_blank" rel="noopener">owner\'s guide</a> (also under Help in the menu).</p>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="check"><button class="quiet">Check the data folder again</button></form>'
        . (!shop_demo() ? '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="' . (setting('ready_hidden') === '1' ? 'show' : 'hide') . '"><button class="quiet">'
            . (setting('ready_hidden') === '1' ? 'Show the reminder on Orders and Products' : 'Hide the reminder on Orders and Products') . '</button></form>' : ''));
}
/* ========================================================= proxies and CDNs */
/* Behind Cloudflare or another proxy, every request arrives from the proxy's
   address. The shop only believes the proxy's note of the visitor's real address
   (and of https) when the request really came from a proxy the owner trusts. */

/* A function, not a constant: the session is set up (and asks whether the request is
   https, which can depend on these) before a constant this far down would exist. */
function cloudflare_ranges(): array
{
    return [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton((string)$net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
}

function proxy_ranges(): array
{
    return match (setting('proxy_mode')) {
        'cloudflare' => cloudflare_ranges(),
        'other'      => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', setting('proxy_ips'))))),
        default      => [],
    };
}

function from_trusted_proxy(string $ip): bool
{
    foreach (proxy_ranges() as $r) {
        if (ip_in_cidr($ip, $r)) {
            return true;
        }
    }
    return false;
}

/* The visitor's own address: REMOTE_ADDR, or what a trusted proxy says it was. */
function real_ip(): string
{
    static $ip = null;
    if ($ip !== null) {
        return $ip;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (setting('proxy_mode', 'none') === 'none' || !from_trusted_proxy($ip)) {
        return $ip;
    }
    if (setting('proxy_mode') === 'cloudflare') {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        return $ip = filter_var($cf, FILTER_VALIDATE_IP) ? $cf : $ip;
    }
    // X-Forwarded-For: the right-most address that isn't one of our own proxies.
    $hops = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($hops as $h) {
        if (!filter_var($h, FILTER_VALIDATE_IP)) {
            break;
        }
        if (!from_trusted_proxy($h)) {
            return $ip = $h;
        }
    }
    return $ip;
}

function forwarded_https(): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (setting('proxy_mode', 'none') === 'none' || !from_trusted_proxy($ip)) {
        return false;
    }
    return strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'
        || str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"');
}

/* ============================================================== CSV export */

/* A spreadsheet cell: anything that could start a formula is made plain text. */
function csv_cell(mixed $v): string
{
    $v = (string)$v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

function csv_money(int $pence): string
{
    return number_format($pence / 100, 2, '.', '');
}

function view_admin_export(): never
{
    require_admin();
    $from = str_in($_GET['from'] ?? '');
    $to = str_in($_GET['to'] ?? '');
    $what = str_in($_GET['what'] ?? '');
    $which = str_in($_GET['which'] ?? 'paid');
    $ok = fn($d) => $d === '' || DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if ($what === '' || !$ok($from) || !$ok($to)) {
        $month = (new DateTimeImmutable('first day of last month', new DateTimeZone('Europe/London')));
        admin_shell('Export orders', '<h1>Export orders</h1>'
            . '<p class="lede">Download orders as a CSV file for a spreadsheet or your bookkeeping. Amounts include VAT, as charged.</p>'
            . ($what !== '' ? '<p class="err">Those dates don\'t look right.</p>' : '')
            . '<form method="get">'
            . '<div class="row"><label>From <input type="date" name="from" value="' . e($from ?: $month->format('Y-m-d')) . '"></label>'
            . '<label>To <span class="hint">(this day included)</span><input type="date" name="to" value="' . e($to ?: $month->modify('last day of this month')->format('Y-m-d')) . '"></label></div>'
            . '<label>Which orders <select name="which"><option value="paid"' . ($which === 'paid' ? ' selected' : '') . '>Paid and refunded orders (sales)</option>'
            . '<option value="all"' . ($which === 'all' ? ' selected' : '') . '>All orders, including unpaid and cancelled</option></select></label>'
            . '<label>What <select name="what"><option value="orders">One row per order</option><option value="lines">One row per item (for stock and product sales)</option></select></label>'
            . '<button>Download CSV</button></form>'
            . '<p class="muted">Dates are UK dates of when the order was placed. The file has customers\' personal details in it: keep it somewhere safe.</p>');
    }
    $tz = new DateTimeZone('Europe/London');
    $utc = fn(string $d, bool $end) => (new DateTimeImmutable($d . ($end ? ' 23:59:59' : ' 00:00:00'), $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $sql = 'SELECT * FROM orders WHERE 1';
    $args = [];
    if ($from !== '') { $sql .= ' AND created >= ?'; $args[] = $utc($from, false); }
    if ($to !== '')   { $sql .= ' AND created <= ?'; $args[] = $utc($to, true); }
    if ($which !== 'all') { $sql .= " AND status IN ('paid', 'refunded')"; }
    $st = db()->prepare($sql . ' ORDER BY number');
    $st->execute($args);
    $orders = $st->fetchAll();
    $local = fn(string $t) => $t === '' ? '' : (new DateTimeImmutable($t, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i');
    $rows = [];
    if ($what === 'lines') {
        $rows[] = ['Order', 'Date', 'Status', 'Product', 'Option', 'Quantity', 'Unit price', 'Line total', 'SKU'];
        foreach ($orders as $o) {
            foreach (order_items((int)$o['id']) as $it) {
                $rows[] = [$o['number'], $local($o['created']), status_label($o['status']), $it['name'], $it['option_label'],
                           $it['qty'], csv_money((int)$it['unit_pence']), csv_money((int)$it['unit_pence'] * (int)$it['qty']), $it['sku'] ?? ''];
            }
        }
    } else {
        $rows[] = ['Order', 'Date', 'Status', 'Payment', 'Paid', 'Name', 'Email', 'Phone', 'Delivery', 'Billing address', 'Delivery address',
                   'Items', 'Goods', 'Discount code', 'Discount', 'Group', 'Group saving', 'Delivery charge', 'Total', 'VAT included', 'VAT number',
                   'Sent or collected', 'Tracking', 'PayPal capture'];
        foreach ($orders as $o) {
            $items = implode('; ', array_map(fn($it) => $it['qty'] . ' x ' . $it['name'] . ($it['option_label'] !== '' ? ' (' . $it['option_label'] . ')' : ''),
                                             order_items((int)$o['id'])));
            $rows[] = [$o['number'], $local($o['created']), status_label($o['status']), method_label($o['payment_method']), $local($o['paid_at']),
                       $o['bill_name'], $o['email'], $o['phone'], ['collect' => 'Collect', 'none' => 'Download'][$o['delivery']] ?? 'Deliver',
                       implode(', ', mail_address_lines('bill_', $o)), $o['delivery'] !== 'deliver' ? '' : implode(', ', mail_address_lines('ship_', $o)),
                       $items, csv_money((int)$o['subtotal_pence']), $o['discount_code'], csv_money((int)$o['discount_pence']),
                       $o['group_name'], csv_money((int)$o['group_pence']), csv_money((int)$o['postage_pence']), csv_money((int)$o['total_pence']),
                       $o['vat_pence'] === null ? '' : csv_money((int)$o['vat_pence']), $o['vat_number'],
                       $o['fulfilled'] === '' ? '' : ucfirst($o['fulfilled']) . ' ' . $local($o['fulfilled_at']), $o['tracking'], $o['paypal_capture_id']];
        }
    }
    $name = 'orders-' . ($what === 'lines' ? 'items-' : '') . ($from ?: 'start') . '-to-' . ($to ?: gmdate('Y-m-d')) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";   // so Excel reads £ and accents correctly
    foreach ($rows as $r) {
        echo implode(',', array_map('csv_cell', $r)), "\r\n";
    }
    exit;
}

/* =========================================================== privacy tool */
/* For someone's request to see, or remove, what the shop holds about them. */

/* Orders with this email, or tied to their account. */
function person_orders(string $email, ?array $c): array
{
    $st = db()->prepare('SELECT * FROM orders WHERE email = ? COLLATE NOCASE OR (customer_id IS NOT NULL AND customer_id = ?) ORDER BY id');
    $st->execute([$email, $c ? (int)$c['id'] : -1]);
    return $st->fetchAll();
}

function person_emails(string $email, array $orders): array
{
    $ids = array_map(fn($o) => (int)$o['id'], $orders) ?: [0];
    $st = db()->prepare('SELECT id, order_id, kind, to_addr, subject, status, created, sent_at FROM emails
                         WHERE to_addr = ? COLLATE NOCASE OR (order_id IN (' . implode(',', $ids) . ') AND to_addr = ? COLLATE NOCASE) ORDER BY id');
    $st->execute([$email, $email]);
    return $st->fetchAll();
}

function order_is_open(array $o): bool
{
    return in_array($o['status'], ['pending', 'awaiting_payment', 'pay_on_collection'], true) || ($o['status'] === 'paid' && $o['fulfilled'] === '');
}

function view_admin_privacy(): never
{
    require_admin();
    $email = trim(str_in($_GET['email'] ?? $_POST['email'] ?? ''));
    $c = $email !== '' ? customer_by_email($email) : null;
    $orders = $email !== '' ? person_orders($email, $c) : [];
    if (is_post() && $email !== '') {
        csrf_check();
        if (post('do') === 'export') {
            $data = [
                'exported' => gmdate('c'), 'shop' => setting('business_name') ?: setting('last_site_title'), 'email' => $email,
                'account' => $c ? array_intersect_key($c, array_flip(['email', 'name', 'phone', 'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode',
                    'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode', 'verified_at', 'created', 'last_login'])) + ['group' => $c['group_id'] !== null ? (group_by_id((int)$c['group_id'])['name'] ?? '') : ''] : null,
                'orders' => array_map(fn($o) => array_intersect_key($o, array_flip(['number', 'status', 'payment_method', 'delivery', 'email', 'phone',
                    'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode', 'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode',
                    'customer_note', 'total_pence', 'discount_code', 'created', 'paid_at', 'fulfilled', 'fulfilled_at', 'tracking']))
                    + ['items' => array_map(fn($it) => ['name' => $it['name'], 'option' => $it['option_label'], 'sku' => $it['sku'] ?? '', 'qty' => (int)$it['qty'], 'unit_pence' => (int)$it['unit_pence']], order_items((int)$o['id']))],
                    $orders),
                'emails_sent' => array_map(fn($m) => ['subject' => $m['subject'], 'status' => $m['status'], 'created' => $m['created']], person_emails($email, $orders)),
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="personal-data-' . preg_replace('/[^a-z0-9.-]+/i', '-', $email) . '.json"');
            header('Cache-Control: no-store');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (post('do') === 'anonymise') {
            $keepPaid = empty($_POST['include_paid']);
            $done = 0;
            $kept = 0;
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            foreach ($orders as $o) {
                if (order_is_open($o) || ($keepPaid && in_array($o['status'], ['paid', 'refunded'], true))) {
                    $kept++;
                    continue;
                }
                $pdo->prepare("UPDATE orders SET email = ?, phone = '', bill_name = 'Removed', bill_line1 = '', bill_line2 = '', bill_town = '', bill_postcode = '',
                               ship_name = '', ship_line1 = '', ship_line2 = '', ship_town = '', ship_postcode = '', customer_note = '', tracking = '',
                               customer_id = NULL, admin_note = CASE WHEN admin_note = '' THEN ? ELSE admin_note || char(10) || ? END, updated = ? WHERE id = ?")
                    ->execute(['removed-' . $o['id'] . '@anonymised.invalid', gmdate('j M H:i') . ' Personal details removed on request',
                               gmdate('j M H:i') . ' Personal details removed on request', now(), $o['id']]);
                $pdo->prepare("UPDATE emails SET to_addr = 'removed', body_text = '', body_html = '' WHERE order_id = ?")->execute([$o['id']]);
                $done++;
            }
            $pdo->prepare("DELETE FROM emails WHERE order_id IS NULL AND to_addr = ? COLLATE NOCASE")->execute([$email]);
            $pdo->prepare('DELETE FROM throttle WHERE k LIKE ? OR k LIKE ?')->execute(['%:' . mb_strtolower($email), '%:' . email_key($email)]);
            $pdo->exec('COMMIT');
            if ($c && !empty($_POST['delete_account'])) {
                customer_delete((int)$c['id']);
            }
            flash('Done. ' . $done . ' order' . ($done === 1 ? '' : 's') . ' anonymised' . ($kept ? ', ' . $kept . ' kept as ' . ($kept === 1 ? 'it is' : 'they are') . ' still open or needed as sales records' : '')
                . ($c && !empty($_POST['delete_account']) ? ', and the account deleted' : '') . '.');
            redirect(admin_url('privacy') . '?email=' . rawurlencode($email));
        }
    }
    $body = '<p class="muted"><a href="' . e(admin_url('customers')) . '">&larr; Customers</a></p><h1>Find a person\'s data</h1>'
        . '<p class="lede">When someone asks what the shop holds about them, or asks for it to be removed.</p>'
        . '<form method="get" class="search"><input type="email" name="email" value="' . e($email) . '" placeholder="Their email address" aria-label="Email address" required><button class="quiet">Find</button></form>';
    if ($email !== '') {
        $mails = person_emails($email, $orders);
        $rows = '';
        foreach ($orders as $o) {
            $rows .= '<tr><td><a href="' . e(admin_url('order/' . $o['id'])) . '">#' . (int)$o['number'] . '</a></td><td>' . e(gmdate('j M Y', strtotime($o['created'] . ' UTC'))) . '</td><td>'
                   . status_pill($o) . '</td><td>' . (order_is_open($o) ? '<span class="muted">open</span>' : '') . '</td></tr>';
        }
        $nothing = !$c && !$orders && !$mails;
        $body .= $nothing ? '<p>Nothing found for <strong>' . e($email) . '</strong>.</p>'
            : '<h2>What the shop holds</h2><ul>'
            . '<li>' . ($c ? 'An account (<a href="' . e(admin_url('customer/' . $c['id'])) . '">' . e($c['name'] ?: $c['email']) . '</a>) with ' . (mail_address_lines('bill_', $c) ? 'saved addresses' : 'no saved addresses') : 'No account') . '</li>'
            . '<li>' . count($orders) . ' order' . (count($orders) === 1 ? '' : 's') . ' with names, addresses and anything they wrote in the note</li>'
            . '<li>' . count($mails) . ' email' . (count($mails) === 1 ? '' : 's') . ' in the outbox history</li></ul>'
            . ($rows !== '' ? '<table>' . $rows . '</table>' : '')
            . '<h2>Give them a copy</h2><form method="post">' . csrf_field() . '<input type="hidden" name="email" value="' . e($email) . '"><input type="hidden" name="do" value="export">'
            . '<button class="quiet">Download their data (JSON)</button></form>'
            . '<h2>Remove their details</h2>'
            . '<p class="muted">Orders keep their number, date, items and amounts; names, addresses, phone, email and notes are removed. Open orders (unpaid, or paid and not yet sent) are never touched. '
            . 'UK tax rules expect you to keep sales records for six years, so paid orders are kept unless you tick the box.</p>'
            . '<form method="post" onsubmit="return confirm(\'Remove this person\\\'s details? This can\\\'t be undone.\')">' . csrf_field()
            . '<input type="hidden" name="email" value="' . e($email) . '"><input type="hidden" name="do" value="anonymise">'
            . '<label class="check"><input type="checkbox" name="include_paid" value="1"> Include paid and refunded orders too</label>'
            . ($c ? '<label class="check"><input type="checkbox" name="delete_account" value="1" checked> Delete their account</label>' : '')
            . '<button class="danger">Remove their details</button></form>';
    }
    admin_shell('Find a person\'s data', $body);
}
/* ======================================================= digital products */
/* A product can be a download instead of a thing to post. Downloads need the
   customer signed in, because what they buy lives in their account: a
   "licence" row per copy bought, holding the download rights and, for Wren
   themes, the licence key, the website it's for and how long updates last.

   Wren themes are licensed per website. The shop signs a small licence
   document (Ed25519, with a key made on this server) naming the theme and the
   domain; Wren checks the signature with the shop's public key built into it.
   Customers change the domain in their account whenever they like; Wren then
   fetches a fresh document from this shop. */

const LICENCE_PREFIX     = 'WREN';
const DOMAIN_MOVES       = 3;      // per licence per 30 days, so one licence can't keep lots of sites going
const LICENCE_DAYS       = 30;     // a licence document lasts this long; Wren fetches a fresh one well before
const DOWNLOADS_DAY      = 20;     // per licence
const DOWNLOAD_MAX       = 100 * 1024 * 1024;

function is_digital(array $p): bool
{
    return ($p['kind'] ?? 'physical') === 'digital';
}

function files_dir(): string
{
    $d = data_dir() . '/files';
    if (!is_dir($d)) {
        mkdir($d, 0750, true);
    }
    return $d;
}

/* ------------------------------------------------------------- the basket */

/* Renewal lines in the basket have keys like "r12" (licence 12). */
function renewal_item(string $key): ?array
{
    if (!preg_match('/^r(\d+)$/', $key, $m) || !accounts_on() || !($me = current_customer())) {
        return null;
    }
    $l = licence_by_id((int)$m[1]);
    if (!$l || (int)$l['customer_id'] !== (int)$me['id'] || $l['status'] !== 'active') {
        return null;
    }
    $p = product_by_id((int)$l['product_id']);
    if (!$p || !is_digital($p)) {
        return null;
    }
    $p['price_pence'] = $p['renew_pence'] ?? $p['price_pence'];
    $p['name'] = $p['name'] . ': ' . (int)$p['update_months'] . ' more months of updates';
    $p['stock'] = null;
    $p['renew_licence'] = (int)$l['id'];
    return [$p, null];
}

/* Does the basket hold anything to post or collect? */
function basket_physical(): bool
{
    foreach (array_keys($_SESSION['shop_basket'] ?? []) as $key) {
        $item = basket_item((string)$key);
        if ($item && !is_digital($item[0])) {
            return true;
        }
    }
    return false;
}

function basket_digital(): bool
{
    foreach (array_keys($_SESSION['shop_basket'] ?? []) as $key) {
        $item = basket_item((string)$key);
        if ($item && is_digital($item[0])) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------- admin: the product */

function digital_input(?array $p, array &$v, array &$errors): array
{
    $d = [
        'kind'     => post('kind') === 'digital' ? 'digital' : 'physical',
        'licensed' => !empty($_POST['licensed']),
        'theme_id' => strtolower(trim(post('theme_id'))),
        'renew'    => post('renew_price'),
        'months'   => post('update_months') === '' ? '12' : post('update_months'),
        'version'  => mb_substr(post('dl_version'), 0, 30),
        'upload'   => null,
    ];
    $v['dg'] = $d;
    if ($d['kind'] !== 'digital') {
        return $d;
    }
    if ($v['opts']) {
        $errors[] = 'A download can\'t have options. Make a separate product for each one.';
    }
    if ($d['licensed'] && !function_exists('sodium_crypto_sign_detached')) {
        $errors[] = 'Selling licensed themes needs PHP\'s sodium extension, which this server doesn\'t have. Ask your host to turn it on.';
    } elseif ($d['licensed']) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $d['theme_id'])) {
            $errors[] = 'Give the theme a short ID of letters, numbers and dashes, like aurora. It must match the one in the theme\'s theme.html.';
        } else {
            $st = db()->prepare('SELECT id FROM products WHERE theme_id = ? AND id != ?');
            $st->execute([$d['theme_id'], $p ? (int)$p['id'] : 0]);
            if ($st->fetchColumn()) {
                $errors[] = 'Another product already uses the theme ID ' . $d['theme_id'] . '.';
            }
        }
        if ($d['renew'] !== '' && parse_price($d['renew']) === null) {
            $errors[] = 'The renewal price needs to be a price, like 25, or left empty to use the full price.';
        }
        if (!ctype_digit($d['months']) || (int)$d['months'] < 1 || (int)$d['months'] > 120) {
            $errors[] = 'Updates last a whole number of months, like 12.';
        }
    }
    $f = $_FILES['dl_file'] ?? null;
    if (is_array($f) && is_int($f['error'] ?? null) && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The download file didn\'t upload (' . ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'it\'s bigger than this server allows: ' . upload_limit_text() : 'error ' . $f['error']) . ').';
        } elseif (!preg_match('/\.zip$/i', (string)$f['name'])) {
            $errors[] = 'The download needs to be a .zip file.';
        } elseif ((int)$f['size'] > DOWNLOAD_MAX || file_get_contents($f['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") {
            $errors[] = 'That doesn\'t look like a zip file.';
        } elseif ($d['version'] === '') {
            $errors[] = 'Give the new file a version number, like 1.0.';
        } else {
            $d['upload'] = $f;
        }
    }
    $v['dg'] = $d;
    return $d;
}

function digital_save(int $pid, array $d, array &$msgs): void
{
    $pdo = db();
    $digital = $d['kind'] === 'digital';
    $pdo->prepare('UPDATE products SET kind = ?, licensed = ?, theme_id = ?, renew_pence = ?, update_months = ?' . ($digital ? ', stock = NULL, option_name = \'\'' : '') . ' WHERE id = ?')
        ->execute([$d['kind'], $digital && $d['licensed'] ? 1 : 0, $digital && $d['licensed'] ? $d['theme_id'] : '',
                   $digital && $d['licensed'] && $d['renew'] !== '' ? parse_price($d['renew']) : null,
                   $digital ? (int)$d['months'] : 12, $pid]);
    if ($digital && $d['upload']) {
        $name = bin2hex(random_bytes(12)) . '.zip';
        if (move_uploaded_file($d['upload']['tmp_name'], files_dir() . '/' . $name)
            || (getenv('WRENSHOP_TESTING') === '1' && @rename($d['upload']['tmp_name'], files_dir() . '/' . $name))) {
            @chmod(files_dir() . '/' . $name, 0600);
            $pdo->prepare('INSERT INTO product_files (product_id, version, file, name, size, uploaded) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$pid, $d['version'], $name, basename((string)$d['upload']['name']), (int)$d['upload']['size'], now()]);
            $msgs[] = 'Version ' . $d['version'] . ' uploaded. Customers with current updates can download it now.';
        } else {
            $msgs[] = 'The file couldn\'t be saved. Check the shop\'s data folder can be written to.';
        }
    }
}

function product_files(int $pid): array
{
    $st = db()->prepare('SELECT * FROM product_files WHERE product_id = ? ORDER BY uploaded DESC, id DESC');
    $st->execute([$pid]);
    return $st->fetchAll();
}

function digital_fields_html(?array $p, array $v): string
{
    $d = $v['dg'] ?? [
        'kind' => $p['kind'] ?? 'physical', 'licensed' => (bool)($p['licensed'] ?? 0), 'theme_id' => $p['theme_id'] ?? '',
        'renew' => isset($p['renew_pence']) ? price_input((int)$p['renew_pence']) : '', 'months' => (string)($p['update_months'] ?? 12), 'version' => '',
    ];
    $files = $p ? product_files((int)$p['id']) : [];
    $list = '';
    foreach ($files as $i => $f) {
        $list .= '<li>Version <strong>' . e($f['version']) . '</strong> · ' . e($f['name']) . ' · ' . max(1, (int)round((int)$f['size'] / 1024)) . ' KB · '
               . e(gmdate('j M Y', strtotime($f['uploaded'] . ' UTC'))) . ($i === 0 ? ' <span class="pill ok">Latest</span>' : '') . '</li>';
    }
    $dig = $d['kind'] === 'digital';
    return '<details class="opts" id="digital"' . ($dig ? ' open' : '') . '><summary>Download <span class="hint">(for files customers download, like Wren themes)</span></summary>'
        . '<label class="check"><input type="checkbox" name="kind" value="digital"' . ($dig ? ' checked' : '') . '> This is a download, not something to post</label>'
        . '<p class="muted">Customers need to be signed in to buy downloads. Their downloads, and any licences, appear in their account as soon as they\'ve paid.</p>'
        . ($list !== '' ? '<p><b>Files</b></p><ul class="muted" style="margin-top:0">' . $list . '</ul>' : '')
        . '<label>' . ($files ? 'Upload a new version' : 'The file') . ' <span class="hint">(.zip)</span><input type="file" name="dl_file" accept=".zip,application/zip"></label>'
        . '<label>Version <span class="hint">(of this file, e.g. 1.0)</span><input name="dl_version" value="' . e($d['version']) . '" maxlength="30" style="max-width:9rem;display:block"></label>'
        . '<label class="check" style="margin-top:1.4rem"><input type="checkbox" name="licensed" value="1"' . ($d['licensed'] ? ' checked' : '') . '> Wren theme, licensed for one website</label>'
        . '<label>Theme ID <span class="hint">(as in the theme\'s theme.html, e.g. aurora)</span><input name="theme_id" value="' . e($d['theme_id']) . '" maxlength="40" autocapitalize="none" style="max-width:14rem;display:block"></label>'
        . '<div class="row"><label>Updates for <span class="hint">(months)</span><input type="number" name="update_months" min="1" max="120" value="' . e($d['months']) . '" style="max-width:7rem;display:block"></label>'
        . '<label>Renewal price <span class="hint">(another period of updates; empty = full price)</span><div class="price" style="max-width:9rem"><span>£</span><input name="renew_price" inputmode="decimal" value="' . e($d['renew']) . '"></div></label></div>'
        . '</details>';
}

/* ---------------------------------------------------------------- licences */

function licence_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM licences WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function licence_by_key(string $key): ?array
{
    $st = db()->prepare('SELECT * FROM licences WHERE lkey = ?');
    $st->execute([strtoupper(trim($key))]);
    return $st->fetch() ?: null;
}

function licence_new_key(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O or 1/I
    do {
        $k = LICENCE_PREFIX;
        for ($g = 0; $g < 4; $g++) {
            $k .= '-';
            for ($i = 0; $i < 4; $i++) {
                $k .= $abc[random_int(0, 31)];
            }
        }
    } while (licence_by_key($k));
    return $k;
}

function licence_log(int $lid, string $action, string $detail = ''): void
{
    db()->prepare('INSERT INTO licence_log (licence_id, at, action, detail) VALUES (?, ?, ?, ?)')->execute([$lid, now(), $action, $detail]);
}

function add_months(string $from, int $months): string
{
    return (new DateTimeImmutable($from, new DateTimeZone('UTC')))->modify(sprintf('%+d months', $months))->format('Y-m-d H:i:s');
}

/* "https://www.Example.com/path" -> "example.com"; null if it isn't a domain. */
function bare_domain(string $s): ?string
{
    $s = strtolower(trim($s));
    $s = preg_replace('#^[a-z]+://#', '', $s);
    $s = preg_replace('#[/?\#].*$#', '', $s);
    $s = preg_replace('#:\d+$#', '', $s);
    $s = preg_replace('#^www\.#', '', rtrim($s, '.'));
    if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $s)) {
        $s = (string)idn_to_ascii($s, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);   // as browsers do: ß stays ß
    }
    return strlen($s) <= 253 && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $s) ? $s : null;
}

/* Paid: each download bought becomes a licence row; renewals extend theirs. */
function digital_on_paid(int $oid): void
{
    $o = order_by('id', $oid);
    if (!$o) {
        return;
    }
    $pdo = db();
    $any = false;
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->exec('BEGIN IMMEDIATE');
    }
    foreach (order_items($oid) as $it) {
        if ($it['licence_id'] !== null) {   // a renewal
            $l = licence_by_id((int)$it['licence_id']);
            $p = $l ? product_by_id((int)$l['product_id']) : null;
            if ($l) {
                $months = (int)($p['update_months'] ?? 12) * (int)$it['qty'];
                $from = max($l['updates_until'], now());
                $until = add_months($from, $months);
                $pdo->prepare('UPDATE licences SET updates_until = ? WHERE id = ?')->execute([$until, $l['id']]);
                $pdo->prepare('UPDATE order_items SET months = ? WHERE id = ?')->execute([$months, $it['id']]);
                licence_log((int)$l['id'], 'renewed', 'Updates until ' . substr($until, 0, 10) . ' (order #' . $o['number'] . ')');
                $any = true;
            }
            continue;
        }
        $p = product_by_id((int)$it['product_id']);
        if (!$p || !is_digital($p)) {
            continue;
        }
        $have = $pdo->prepare('SELECT COUNT(*) FROM licences WHERE order_id = ? AND product_id = ?');
        $have->execute([$oid, $p['id']]);
        for ($i = (int)$have->fetchColumn(); $i < (int)$it['qty']; $i++) {   // safe to run twice
            $pdo->prepare("INSERT INTO licences (lkey, product_id, order_id, customer_id, domain, status, created, updates_until)
                           VALUES (?, ?, ?, ?, '', 'active', ?, ?)")
                ->execute([licence_new_key(), $p['id'], $oid, $o['customer_id'], now(), add_months(now(), (int)$p['update_months'])]);
            licence_log((int)$pdo->lastInsertId(), 'issued', 'Order #' . $o['number']);
            $any = true;
        }
    }
    if ($any && $o['delivery'] === 'none') {   // nothing to post: done as soon as it's paid
        $pdo->prepare("UPDATE orders SET fulfilled = 'digital', fulfilled_at = ? WHERE id = ? AND fulfilled = ''")->execute([now(), $oid]);
    }
    if ($own) {
        $pdo->exec('COMMIT');
    }
}

function digital_on_refund(int $oid): void
{
    $st = db()->prepare("SELECT id FROM licences WHERE order_id = ? AND status = 'active'");
    $st->execute([$oid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        db()->prepare("UPDATE licences SET status = 'revoked' WHERE id = ?")->execute([$lid]);
        licence_log((int)$lid, 'revoked', 'Order refunded');
    }
    $st = db()->prepare('SELECT id, licence_id, months FROM order_items WHERE order_id = ? AND licence_id IS NOT NULL AND months > 0');
    $st->execute([$oid]);
    foreach ($st->fetchAll() as $it) {   // a refunded renewal takes its months back
        $l = licence_by_id((int)$it['licence_id']);
        if ($l) {
            $until = add_months($l['updates_until'], -(int)$it['months']);
            db()->prepare('UPDATE licences SET updates_until = ? WHERE id = ?')->execute([$until, $l['id']]);
            db()->prepare('UPDATE order_items SET months = 0 WHERE id = ?')->execute([$it['id']]);
            licence_log((int)$l['id'], 'renewal refunded', 'Updates until ' . substr($until, 0, 10));
        }
    }
}

/* ----------------------------------------------------------------- signing */

function licences_possible(): bool
{
    return function_exists('sodium_crypto_sign_detached');
}

/* [secret, public], made once on this server. Only the secret is the source of truth
   (two first visits at once can't leave a mismatched pair); the public half is derived. */
function licence_keypair(): array
{
    if (setting('licence_sk') === '') {
        db()->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (\'licence_sk\', ?)')
            ->execute([base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    }
    $sk = base64_decode(setting('licence_sk'));
    $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
    if (setting('licence_pk') !== base64_encode($pk)) {
        set_setting('licence_pk', base64_encode($pk));   // for reference only
    }
    return [$sk, $pk];
}

/* The document Wren keeps: a signed statement of which theme is licensed for
   which website. Base64 of the JSON, a dot, and the base64 signature. */
function licence_document(array $l): string
{
    $p = product_by_id((int)$l['product_id']);
    $payload = json_encode([
        'v' => 1, 'key' => $l['lkey'], 'theme' => $p['theme_id'] ?? '', 'name' => $p['name'] ?? '',
        'domain' => $l['domain'], 'issued' => gmdate('Y-m-d'), 'expires' => time() + LICENCE_DAYS * 86400,
        'updates_until' => substr($l['updates_until'], 0, 10),
        'issuer' => abs_shop_url(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    [$sk] = licence_keypair();
    $sig = sodium_crypto_sign_detached($payload, $sk);
    return "-----BEGIN WREN THEME LICENCE-----\n" . chunk_split(base64_encode($payload) . '.' . base64_encode($sig), 64, "\n")
         . "-----END WREN THEME LICENCE-----\n";
}

/* The address Wren asks for a fresh licence document: /shop/licence/KEY?domain=... */
function licence_api(string $key): never
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $fail = function (int $code, string $msg) {
        http_response_code($code);
        echo $msg, "\n";
        exit;
    };
    if (throttled('lapi:' . client_ip(), 60, 3600)) {
        $fail(429, 'Too many requests. Try again later.');
    }
    throttle_hit('lapi:' . client_ip());
    if (!licences_possible()) {
        $fail(503, 'This shop can\'t issue licences just now (its server needs PHP\'s sodium extension).');
    }
    $l = licence_by_key($key);
    $p = $l ? product_by_id((int)$l['product_id']) : null;
    if (!$l || !$p || !(int)$p['licensed']) {
        $fail(404, 'That licence key isn\'t recognised. Check it in your account at ' . abs_shop_url('account/licences') . '.');
    }
    if ($l['status'] !== 'active') {
        $fail(403, 'That licence has been cancelled.');
    }
    if ($l['domain'] === '') {
        $fail(409, 'This licence isn\'t set to a website yet. Choose one in your account: ' . abs_shop_url('account/licence/' . $l['id']));
    }
    $asked = bare_domain(str_in($_GET['domain'] ?? ''));
    if ($asked !== null && $asked !== $l['domain']) {
        $fail(409, 'This licence is for ' . $l['domain'] . ', not ' . $asked . '. To move it, change the website in your account: '
            . abs_shop_url('account/licence/' . $l['id']));
    }
    echo licence_document($l);
    exit;
}

/* ---------------------------------------------------- the customer's account */

function customer_licences(int $cid): array
{
    $st = db()->prepare("SELECT l.*, p.name AS pname, p.licensed, p.slug, p.update_months, p.renew_pence, p.price_pence, o.number AS onum
                         FROM licences l JOIN products p ON p.id = l.product_id LEFT JOIN orders o ON o.id = l.order_id
                         WHERE l.customer_id = ? ORDER BY l.status, l.id DESC");
    $st->execute([$cid]);
    return $st->fetchAll();
}

function own_licence(int $id): array
{
    $me = require_customer('account/licences');
    $l = licence_by_id($id);
    if (!$l || (int)$l['customer_id'] !== (int)$me['id']) {
        view_404();
    }
    return $l;
}

/* The file a licence may download: the latest, or the last one from within its updates. */
function licence_file(array $l): ?array
{
    $files = product_files((int)$l['product_id']);
    if (!$files) {
        return null;
    }
    if ($l['updates_until'] >= now()) {
        return $files[0];
    }
    foreach ($files as $f) {
        if ($f['uploaded'] <= $l['updates_until']) {
            return $f;
        }
    }
    return end($files);   // everything is newer: the first version they could have had
}

function view_account_licences(): never
{
    $me = require_customer('account/licences');
    $rows = '';
    foreach (customer_licences((int)$me['id']) as $l) {
        $f = licence_file($l);
        $state = $l['status'] !== 'active' ? 'Cancelled'
               : ((int)$l['licensed'] ? ($l['domain'] !== '' ? 'For ' . $l['domain'] : 'Choose its website') : 'Download');
        $rows .= '<tr><td><a href="' . e(shop_url('account/licence/' . $l['id'])) . '">' . e($l['pname']) . '</a>'
               . ((int)$l['licensed'] ? '<br><small class="shop-hint">' . e($l['lkey']) . '</small>' : '') . '</td>'
               . '<td>' . e($state) . '</td><td>' . ($f && $l['status'] === 'active' ? 'Version ' . e($f['version']) : '') . '</td></tr>';
    }
    account_page('Downloads and licences', $rows !== ''
        ? '<table class="shop-basket shop-orders"><thead><tr><th>Product</th><th>Licence</th><th>Download</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p>Nothing here yet. Downloads you buy appear here as soon as they\'re paid for.</p>', [['Your account', 'account'], ['Downloads and licences', null]]);
}

function view_account_licence(int $id): never
{
    $l = own_licence($id);
    $p = product_by_id((int)$l['product_id']);
    $me = current_customer();
    $errors = [];
    if (is_post()) {
        csrf_check();
        if (post('do') === 'domain' && $l['status'] === 'active' && (int)$p['licensed']) {
            $new = bare_domain(post('domain'));
            $since = db()->prepare("SELECT COUNT(*) FROM licence_log WHERE licence_id = ? AND action = 'domain' AND at > ?
                                    AND detail NOT LIKE '(none)%' AND detail NOT LIKE '%(by the shop)'");
            $since->execute([$l['id'], gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
            if ($new === null) {
                $errors[] = 'Enter the website\'s address, like example.com.';
            } elseif ($new === $l['domain']) {
                $errors[] = 'That\'s already its website.';
            } elseif ($l['domain'] !== '' && (int)$since->fetchColumn() >= DOMAIN_MOVES) {
                $errors[] = 'This licence has moved ' . DOMAIN_MOVES . ' times in the last 30 days, which is the most it can. Please contact us if you need to move it again.';
            } else {
                db()->prepare('UPDATE licences SET domain = ? WHERE id = ?')->execute([$new, $l['id']]);
                licence_log((int)$l['id'], 'domain', ($l['domain'] !== '' ? $l['domain'] : '(none)') . ' → ' . $new);
                flash('Now licensed for ' . $new . '. In Wren\'s admin on that site, press Get licence to update it.');
                redirect(shop_url('account/licence/' . $l['id']));
            }
        } elseif (post('do') === 'renew' && $l['status'] === 'active' && (int)$p['licensed']) {
            $_SESSION['shop_basket']['r' . $l['id']] = 1;
            flash('Renewal added to your basket.');
            redirect(shop_url('basket'));
        }
    }
    $f = licence_file($l);
    $active = $l['status'] === 'active';
    $updates = $l['updates_until'] >= now();
    $until = gmdate('j F Y', strtotime($l['updates_until'] . ' UTC'));
    $body = account_errors($errors)
        . (!$active ? '<div class="shop-note"><strong>This licence has been cancelled</strong>, so it can\'t be downloaded or used any more.</div>' : '')
        . '<p>Bought on order <a href="' . e(shop_url('order/' . (order_by('id', (int)$l['order_id'])['token'] ?? ''))) . '">#' . (int)(order_by('id', (int)$l['order_id'])['number'] ?? 0) . '</a>.</p>';
    if ($active && $f) {
        $body .= '<h2 class="shop-h2">Download</h2><p><a class="shop-button" href="' . e(shop_url('account/download/' . $l['id'])) . '">Download version ' . e($f['version']) . '</a></p>'
               . ((int)$p['licensed'] ? '<p class="shop-hint">' . ($updates ? 'Updates until ' . e($until) . ': you always get the latest version.'
                    : 'Updates ended on ' . e($until) . ', so this is the last version from within them. Renew to get the latest.') . '</p>' : '');
    }
    if ((int)$p['licensed']) {
        $body .= '<h2 class="shop-h2">Licence</h2><dl class="shop-licence"><dt>Licence key</dt><dd><code>' . e($l['lkey']) . '</code></dd>'
            . '<dt>Website</dt><dd>' . ($l['domain'] !== '' ? '<strong>' . e($l['domain']) . '</strong> <span class="shop-hint">(with or without www)</span>' : 'Not chosen yet') . '</dd></dl>';
        if ($active) {
            $body .= '<form class="shop-form shop-narrow" method="post">' . csrf_field() . '<input type="hidden" name="do" value="domain">'
                . account_field('domain', $l['domain'] !== '' ? 'Move it to another website' : 'Which website is it for?', '', 'required maxlength="253" autocapitalize="none" placeholder="example.com"')
                . '<button class="shop-button">' . ($l['domain'] !== '' ? 'Move licence' : 'Save') . '</button></form>'
                . '<p class="shop-hint">The theme works on its licensed website, and always on localhost while you build. After choosing or changing the website, '
                . 'open Wren\'s admin on that site, enter the licence key and press <strong>Get licence</strong>. A licence can move ' . DOMAIN_MOVES . ' times in 30 days.</p>'
                . '<h2 class="shop-h2">Updates</h2><p>' . ($updates ? 'Included until <strong>' . e($until) . '</strong>.' : 'Ended on <strong>' . e($until) . '</strong>. The theme keeps working; renewing gets you new versions again.') . '</p>'
                . '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="renew"><button class="shop-button shop-quiet">Renew: '
                . (int)$p['update_months'] . ' more months for ' . money((int)($p['renew_pence'] ?? $p['price_pence'])) . '</button></form>';
        }
    }
    $body .= '<p><a href="' . e(shop_url('account/licences')) . '">&larr; Downloads and licences</a></p>';
    account_page($p['name'] ?? 'Licence', $body, [['Your account', 'account'], ['Downloads and licences', 'account/licences'], [$p['name'] ?? 'Licence', null]]);
}

function account_download(int $id): never
{
    $l = own_licence($id);
    $f = $l['status'] === 'active' ? licence_file($l) : null;
    $path = $f ? files_dir() . '/' . basename($f['file']) : '';
    if (!$f || !is_file($path)) {
        flash('That download isn\'t available.');
        redirect(shop_url('account/licence/' . $id));
    }
    if (throttled('dl:' . $l['id'], DOWNLOADS_DAY, 86400)) {
        flash('That\'s a lot of downloads today. Please try again tomorrow.');
        redirect(shop_url('account/licence/' . $id));
    }
    throttle_hit('dl:' . $l['id']);
    licence_log((int)$l['id'], 'download', 'Version ' . $f['version']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $f['name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/* ------------------------------------------------------------ admin: licences */

function view_admin_licences(): never
{
    require_admin();
    $q = trim(str_in($_GET['find'] ?? ''));
    $sql = "SELECT l.*, p.name AS pname, p.licensed, c.email, c.name AS cname FROM licences l JOIN products p ON p.id = l.product_id
            LEFT JOIN customers c ON c.id = l.customer_id";
    $args = [];
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $sql .= " WHERE l.lkey LIKE ? ESCAPE '\\' OR l.domain LIKE ? ESCAPE '\\' OR c.email LIKE ? ESCAPE '\\' OR p.name LIKE ? ESCAPE '\\'";
        $args = [$like, $like, $like, $like];
    }
    $st = db()->prepare($sql . ' ORDER BY l.id DESC LIMIT 300');
    $st->execute($args);
    $rows = '';
    foreach ($st->fetchAll() as $l) {
        $rows .= '<tr><td><a href="' . e(admin_url('licence/' . $l['id'])) . '">' . e($l['pname']) . '</a>'
               . ((int)$l['licensed'] ? '<br><span class="muted"><code>' . e($l['lkey']) . '</code></span>' : '') . '</td>'
               . '<td>' . e($l['cname'] ?: ($l['email'] ?? '(account deleted)')) . '<br><span class="muted">' . e($l['domain']) . '</span></td>'
               . '<td>' . ($l['status'] === 'active' ? '<span class="pill ok">Active</span>' : '<span class="pill">Cancelled</span>') . '</td></tr>';
    }
    admin_shell('Licences', '<h1>Downloads and licences</h1>'
        . '<form method="get" class="search"><input name="find" value="' . e($q) . '" placeholder="Key, website, email or product" aria-label="Search licences"><button class="quiet">Search</button></form>'
        . ($rows !== '' ? '<table>' . $rows . '</table>' : '<p class="muted">' . ($q !== '' ? 'Nothing matches that.' : 'None yet. They appear when someone pays for a download.') . '</p>'));
}

function view_admin_licence(int $id): never
{
    require_admin();
    $l = licence_by_id($id);
    if (!$l) {
        redirect(admin_url('licences'));
    }
    $p = product_by_id((int)$l['product_id']);
    if (is_post()) {
        csrf_check();
        switch (post('do')) {
            case 'domain':
                $new = post('domain') === '' ? '' : bare_domain(post('domain'));
                if ($new === null) {
                    flash('That isn\'t a website address.');
                    break;
                }
                db()->prepare('UPDATE licences SET domain = ? WHERE id = ?')->execute([$new, $id]);
                licence_log($id, 'domain', ($l['domain'] ?: '(none)') . ' → ' . ($new ?: '(none)') . ' (by the shop)');
                flash('Website changed.');
                break;
            case 'extend':
                $until = add_months(max($l['updates_until'], now()), max(1, min(120, (int)post('months'))));
                db()->prepare('UPDATE licences SET updates_until = ? WHERE id = ?')->execute([$until, $id]);
                licence_log($id, 'renewed', 'Updates until ' . substr($until, 0, 10) . ' (by the shop)');
                flash('Updates now run until ' . gmdate('j M Y', strtotime($until . ' UTC')) . '.');
                break;
            case 'revoke':
            case 'reinstate':
                $s = post('do') === 'revoke' ? 'revoked' : 'active';
                db()->prepare('UPDATE licences SET status = ? WHERE id = ?')->execute([$s, $id]);
                licence_log($id, $s === 'revoked' ? 'revoked' : 'reinstated', 'By the shop');
                flash($s === 'revoked' ? 'Licence cancelled.' : 'Licence reinstated.');
                break;
        }
        redirect(admin_url('licence/' . $id));
    }
    $c = $l['customer_id'] !== null ? customer_by_id((int)$l['customer_id']) : null;
    $o = order_by('id', (int)$l['order_id']);
    $log = db()->prepare('SELECT * FROM licence_log WHERE licence_id = ? ORDER BY id DESC LIMIT 100');
    $log->execute([$id]);
    $logRows = '';
    foreach ($log->fetchAll() as $x) {
        $logRows .= '<tr><td class="muted">' . e(gmdate('j M Y H:i', strtotime($x['at'] . ' UTC'))) . '</td><td>' . e(ucfirst($x['action'])) . '</td><td>' . e($x['detail']) . '</td></tr>';
    }
    $act = fn(string $do, string $label, string $extra = '', string $cls = 'quiet') => '<form method="post">' . csrf_field()
        . '<input type="hidden" name="do" value="' . $do . '">' . $extra . '<button class="' . $cls . '">' . $label . '</button></form>';
    admin_shell('Licence', '<p class="muted"><a href="' . e(admin_url('licences')) . '">&larr; Licences</a></p>'
        . '<h1>' . e($p['name'] ?? 'Licence') . '</h1>'
        . '<p>' . ($l['status'] === 'active' ? '<span class="pill ok">Active</span>' : '<span class="pill">Cancelled</span>')
        . ((int)($p['licensed'] ?? 0) ? ' <code>' . e($l['lkey']) . '</code>' : '') . '</p>'
        . '<p>' . ($c ? '<a href="' . e(admin_url('customer/' . $c['id'])) . '">' . e($c['name'] ?: $c['email']) . '</a> · ' . e($c['email']) : 'Account deleted')
        . ($o ? ' · order <a href="' . e(admin_url('order/' . $o['id'])) . '">#' . (int)$o['number'] . '</a>' : '') . '</p>'
        . ((int)($p['licensed'] ?? 0) ? '<p>Website: <strong>' . e($l['domain'] ?: 'not chosen yet') . '</strong> · updates until '
            . e(gmdate('j M Y', strtotime($l['updates_until'] . ' UTC'))) . '</p>'
            . $act('domain', 'Change website', '<label>Website <input name="domain" value="' . e($l['domain']) . '" autocapitalize="none" placeholder="example.com"></label>')
            . $act('extend', 'Extend updates', '<label>By <span class="hint">(months)</span><input type="number" name="months" min="1" max="120" value="12" style="max-width:7rem;display:block"></label>') : '')
        . ($l['status'] === 'active' ? $act('revoke', 'Cancel licence', '', 'danger') : $act('reinstate', 'Reinstate licence'))
        . '<h2>History</h2>' . ($logRows !== '' ? '<table>' . $logRows . '</table>' : '<p class="muted">Nothing yet.</p>'));
}

function licence_settings_html(): string
{
    if (!licences_possible()) {
        return '<h2 id="licences">Theme licences</h2><p class="muted">Selling licensed Wren themes needs PHP\'s <b>sodium</b> extension, '
            . 'which this server doesn\'t have. Other downloads work without it.</p>';
    }
    [, $pk] = licence_keypair();
    return '<h2 id="licences">Theme licences</h2>'
        . '<p class="muted">For selling Wren themes. Your shop signs each licence with its own private key, kept only on this server. '
        . 'Wren checks licences with the public key below, which is built into Wren.</p>'
        . '<label>Public key <input value="' . e(base64_encode($pk)) . '" readonly onclick="this.select()"></label>'
        . '<p class="muted">Licence address for Wren: <code>' . e(abs_shop_url('licence/')) . '</code> · <a href="' . e(admin_url('licences')) . '">All licences</a></p>';
}

/* On the admin order page: what the order issued. */
function order_licences_html(int $oid): string
{
    $st = db()->prepare('SELECT l.*, p.name AS pname, p.licensed, (l.order_id != ?) AS renewal FROM licences l JOIN products p ON p.id = l.product_id
                         WHERE l.order_id = ? OR l.id IN (SELECT licence_id FROM order_items WHERE order_id = ? AND licence_id IS NOT NULL) ORDER BY l.id');
    $st->execute([$oid, $oid, $oid]);
    $rows = '';
    foreach ($st->fetchAll() as $l) {
        $rows .= '<tr><td><a href="' . e(admin_url('licence/' . $l['id'])) . '">' . e($l['pname']) . '</a>' . ((int)$l['renewal'] ? ' <span class="muted">(renewal)</span>' : '') . '</td><td>'
               . ((int)$l['licensed'] ? '<code>' . e($l['lkey']) . '</code> ' . e($l['domain']) : 'Download') . '</td><td>'
               . ($l['status'] === 'active' ? '<span class="pill ok">Active</span>' : '<span class="pill">Cancelled</span>') . '</td></tr>';
    }
    return $rows !== '' ? '<h2>Downloads and licences</h2><table>' . $rows . '</table>' : '';
}
/* --------------------------------------------------------- category tree */
/* 1.2.0: categories can sit inside categories, three levels deep (Cars › SRX2 ›
   Spares). A product has one main category (products.category_id: its breadcrumb
   and group rate) and can also show in others (product_categories). A category
   page shows everything in it and in the categories inside it. */

const CAT_DEPTH = 3;

/* Every category, id => row, in menu order, with parent_id as an int. A parent
   that has gone (or a loop) makes a category top-level. */
function cat_all(bool $fresh = false): array
{
    static $all = null;
    if ($all === null || $fresh) {
        $all = [];
        foreach (db()->query('SELECT * FROM categories ORDER BY position, name COLLATE NOCASE') as $r) {
            $r['id'] = (int)$r['id'];
            $r['parent_id'] = (int)$r['parent_id'];
            $all[$r['id']] = $r;
        }
        foreach ($all as $id => $r) {
            $seen = [$id => true];
            for ($p = $r['parent_id']; $p; $p = $all[$p]['parent_id'] ?? 0) {
                if (!isset($all[$p]) || isset($seen[$p]) || count($seen) > CAT_DEPTH + 2) {
                    $all[$id]['parent_id'] = 0;
                    break;
                }
                $seen[$p] = true;
            }
        }
    }
    return $all;
}

function cat_by_id(int $id): ?array
{
    return cat_all()[$id] ?? null;
}

function cat_children(int $parent): array
{
    return array_values(array_filter(cat_all(), fn($c) => $c['parent_id'] === $parent));
}

/* The category and the ones it sits in, outermost first. */
function cat_trail(int $id): array
{
    $all = cat_all();
    $trail = [];
    for ($c = $all[$id] ?? null; $c && count($trail) <= CAT_DEPTH + 2; $c = $all[$c['parent_id']] ?? null) {
        array_unshift($trail, $c);
    }
    return $trail;
}

function cat_depth(int $id): int
{
    return count(cat_trail($id));
}

/* The category's id and the ids of every category inside it. */
function cat_descendants(int $id): array
{
    $ids = [$id];
    for ($i = 0; $i < count($ids); $i++) {
        foreach (cat_children($ids[$i]) as $c) {
            $ids[] = $c['id'];
        }
    }
    return $ids;
}

/* How many levels the category and the ones inside it take up: 1 for one with none inside. */
function cat_height(int $id): int
{
    $h = 1;
    foreach (cat_children($id) as $c) {
        $h = max($h, 1 + cat_height($c['id']));
    }
    return $h;
}

/* Every category in tree order (each followed by the ones inside it), with 'depth' from 1. */
function cat_tree(int $parent = 0, int $depth = 1): array
{
    $out = [];
    if ($depth > CAT_DEPTH + 2) {
        return $out;
    }
    foreach (cat_children($parent) as $c) {
        $c['depth'] = $depth;
        $out[] = $c;
        array_push($out, ...cat_tree($c['id'], $depth + 1));
    }
    return $out;
}

/* SQL: product p is in any of these categories, as its main one or an extra one. */
function cat_in_sql(array $ids, string $p = 'p'): string
{
    $list = implode(',', array_map('intval', $ids ?: [0]));
    return "($p.category_id IN ($list) OR EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = $p.id AND pc.category_id IN ($list)))";
}

/* Categories with something for sale in them or inside them: id => true. Only
   these show in the shop's menu. */
function cat_live(): array
{
    static $live = null;
    if ($live === null) {
        $live = [];
        $ids = db()->query('SELECT category_id FROM products WHERE visible = 1 AND category_id > 0
                            UNION SELECT pc.category_id FROM product_categories pc JOIN products p ON p.id = pc.product_id WHERE p.visible = 1')
                   ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            foreach (cat_trail((int)$id) as $c) {
                $live[$c['id']] = true;
            }
        }
    }
    return $live;
}

/* <option>s for a category menu, in tree order, each named with its full path. $maxDepth leaves out
   categories too deep to hold what's being placed; $skip leaves out a category and
   everything inside it (a category can't go inside itself). */
function cat_options_html(int $selected, string $none = 'None', int $maxDepth = CAT_DEPTH, int $skip = 0): string
{
    $out = $none !== '' ? '<option value="0">' . e($none) . '</option>' : '';
    $skipIds = $skip ? array_flip(cat_descendants($skip)) : [];
    foreach (cat_tree() as $c) {
        if ($c['depth'] > $maxDepth || isset($skipIds[$c['id']])) {
            continue;
        }
        // The full path, so a closed menu still says which "Spares" it is.
        $out .= '<option value="' . $c['id'] . '"' . ($c['id'] === $selected ? ' selected' : '') . '>' . e(cat_path_name($c['id'])) . '</option>';
    }
    return $out;
}

/* A category's name with the ones it sits in: "Cars › SRX2 › Spares". */
function cat_path_name(int $id): string
{
    return implode(' › ', array_column(cat_trail($id), 'name'));
}

function product_extra_cats(int $pid): array
{
    $st = db()->prepare('SELECT category_id FROM product_categories WHERE product_id = ?');
    $st->execute([$pid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/* The other categories a product shows in (not its main one, and only ones that exist). */
function product_set_extra_cats(int $pid, array $ids, int $main): void
{
    $all = cat_all();
    $ids = array_unique(array_filter(array_map('intval', $ids), fn($i) => isset($all[$i]) && $i !== $main));
    db()->prepare('DELETE FROM product_categories WHERE product_id = ?')->execute([$pid]);
    $ins = db()->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)');
    foreach ($ids as $i) {
        $ins->execute([$pid, $i]);
    }
}

/* Deleting a category moves what was inside it up a level: its categories, and its
   products (as their main category, or simply dropped if it was an extra one). */
function cat_delete(int $id): void
{
    $c = cat_by_id($id);
    if (!$c) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?')->execute([$c['parent_id'], $id]);
    $pdo->prepare('UPDATE products SET category_id = ? WHERE category_id = ?')->execute([$c['parent_id'], $id]);
    $pdo->prepare('DELETE FROM product_categories WHERE category_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM product_categories WHERE category_id = ? AND product_id IN (SELECT id FROM products WHERE category_id = ?)')
        ->execute([$c['parent_id'], $c['parent_id']]);
    $pdo->prepare('DELETE FROM group_rates WHERE category_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    $pdo->commit();
    cat_all(true);
}

/* Number the categories inside one parent 0, 1, 2… in the order given. */
function cat_renumber(array $ids): void
{
    $st = db()->prepare('UPDATE categories SET position = ? WHERE id = ?');
    foreach (array_values($ids) as $n => $id) {
        $st->execute([$n, (int)$id]);
    }
}

function cat_next_position(int $parent): int
{
    $st = db()->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM categories WHERE parent_id = ?');
    $st->execute([$parent]);
    return (int)$st->fetchColumn();
}

/* ------------------------------------------------------------------ SKUs */

/* Part numbers are optional, but two products can't share one. Returns the name of
   another product (or another product's choice) that already has this SKU, or null.
   Repeats within one product are checked by its form. */
function sku_taken(string $sku, int $productId = 0): ?string
{
    if ($sku === '') {
        return null;
    }
    $st = db()->prepare("SELECT name FROM products WHERE sku = ? COLLATE NOCASE AND id != ?
                         UNION ALL SELECT p.name || ' (' || v.name || CASE WHEN v.name2 != '' THEN ' / ' || v.name2 ELSE '' END || ')' FROM variants v JOIN products p ON p.id = v.product_id
                         WHERE v.sku = ? COLLATE NOCASE AND v.product_id != ? LIMIT 1");
    $st->execute([$sku, $productId, $sku, $productId]);
    $n = $st->fetchColumn();
    return $n === false ? null : (string)$n;
}

/* SKUs are letters, numbers and - _ . / and up to 40 long. */
function sku_clean(string $s): string
{
    return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, 40);
}

function sku_valid(string $s): bool
{
    return $s === '' || preg_match('#^[A-Za-z0-9][A-Za-z0-9 ._/\-]*$#', $s) === 1;
}

/* ------------------------------------------------------------ sale prices */
/* A product can have a sale price, and so can each choice with a price of its
   own (a choice at the product's price follows the product's sale price). The
   optional dates are UK days, first and last day included, so a sale starts and
   ends by itself. A sale, a customer group's price and a discount code never add
   up: the customer gets whichever is cheapest (see basket_totals). */

function sale_on(array $p): bool
{
    $today = uk_today();
    return ($p['sale_from'] ?? '') <= $today && (($p['sale_to'] ?? '') === '' || $today <= $p['sale_to']);
}

/* The sale price of one of these, or null if it isn't on sale today. */
function sale_unit(array $p, ?array $v): ?int
{
    if (!sale_on($p)) {
        return null;
    }
    $full = variant_price($p, $v);
    $sale = $v && $v['price_pence'] !== null ? ($v['sale_pence'] ?? null) : ($p['sale_pence'] ?? null);
    return $sale !== null && (int)$sale < $full ? (int)$sale : null;
}

/* What one costs before any group price or code: the sale price, if on sale. */
function unit_price(array $p, ?array $v): int
{
    return sale_unit($p, $v) ?? variant_price($p, $v);
}

/* This customer's price for one: [price, full price, why], why being '', 'sale' or 'group'. */
function own_price(array $p, ?array $v): array
{
    $full = variant_price($p, $v);
    $sale = sale_unit($p, $v);
    $grp = $full - group_unit_saving($p, $full, $v);
    if ($sale !== null && $sale <= $grp) {
        return [$sale, $full, 'sale'];
    }
    return [$grp, $full, $grp < $full ? 'group' : ''];
}

function own_price_html(array $p, ?array $v, string $prefix = ''): string
{
    [$own, $full, $why] = own_price($p, $v);
    if ($why === '') {
        return $prefix . shown_money($full) . ex_vat_tag();
    }
    return $prefix . shown_money($own) . ex_vat_tag() . ' <s class="shop-was">' . shown_money($full) . '</s> '
         . ($why === 'group' ? '<small class="shop-yours">' . e(customer_group()['name'] ?? '') . ' price</small>' : '<small class="shop-sale-tag">Sale</small>');
}

/* Is anything about this product on sale today? */
function product_on_sale(array $p, array $vars): bool
{
    foreach ($vars ?: [null] as $v) {
        if (sale_unit($p, $v) !== null) {
            return true;
        }
    }
    return false;
}

/* What a day like 2026-12-01 typed into the admin means, or null if it isn't one. */
function sale_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s ? $s : null;
}

/* ------------------------------------------------------------- backorders */
/* A product can keep selling when its counted stock runs out: stock goes below
   zero and the product is never shown as sold out. "notify" also tells the
   customer which items are on backorder. */

const BACKORDER_CHOICES = ['no' => 'Stop selling (show Sold out)', 'yes' => 'Keep selling', 'notify' => 'Keep selling, and tell customers it\'s on backorder'];

function backorders_on(array $p): bool
{
    return ($p['backorders'] ?? 'no') !== 'no' && (($p['kind'] ?? 'physical') === 'physical');
}

function backorder_told(array $p): bool
{
    return ($p['backorders'] ?? 'no') === 'notify' && backorders_on($p);
}

/* How many of $qty are beyond the stock in hand (0 if stock isn't counted). */
function backorder_qty(array $p, ?array $v, int $qty): int
{
    if (!backorders_on($p)) {
        return 0;
    }
    $s = $v ? $v['stock'] : $p['stock'];
    return $s === null ? 0 : max(0, $qty - max(0, (int)$s));
}

/* "On backorder" or "2 on backorder", for a line the customer is told about. */
function backorder_note(int $n, int $qty): string
{
    return $n >= $qty ? 'On backorder' : $n . ' on backorder';
}

/* Whether customers are told about backorders for a product (old orders look the product up). */
function product_tells_backorders(int $pid): bool
{
    static $cache = [];
    if (!isset($cache[$pid])) {
        $st = db()->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$pid]);
        $p = $st->fetch();
        $cache[$pid] = $p ? backorder_told($p) : false;
    }
    return $cache[$pid];
}

/* ------------------------------------------------------- options (1.3.0) */
/* A product can have one option (Size) or two (Colour and Size). Each choice
   (a row in variants) is one value, or one combination of two; a combination
   that isn't made can be switched off (active = 0) and is never sold. */

/* "Size", or "Colour / Size". */
function option_title(array $p): string
{
    $one = $p['option_name'] !== '' ? $p['option_name'] : 'Option';
    return ($p['option2_name'] ?? '') !== '' ? $one . ' / ' . $p['option2_name'] : $one;
}

/* A choice's short name: "Large", or "Red / Large". */
function choice_name(array $v): string
{
    return $v['name'] . (($v['name2'] ?? '') !== '' ? ' / ' . $v['name2'] : '');
}

/* A choice as a basket or order line shows it: "Size: Large", or "Colour: Red, Size: Large". */
function choice_label(array $p, array $v): string
{
    $one = ($p['option_name'] !== '' ? $p['option_name'] : 'Option') . ': ' . $v['name'];
    return ($v['name2'] ?? '') !== '' ? $one . ', ' . (($p['option2_name'] ?? '') !== '' ? $p['option2_name'] : 'Option') . ': ' . $v['name2'] : $one;
}

/* The choices that can be bought (switched-off combinations left out). */
function live_choices(array $vars): array
{
    return array_values(array_filter($vars, fn($v) => (int)($v['active'] ?? 1) === 1));
}
/* ================================================ product import and export */
/* 1.2.0. A spreadsheet of products, for bulk price and stock changes from a
   supplier's list, or for moving in from another shop. Export writes one row per
   product, or one row per choice for a product with options. Import matches each
   row to a product by SKU, then by web address (slug), and otherwise adds a new,
   hidden product. A blank cell leaves that value as it is, so a file with just
   sku and price columns is a price update; a cell holding only "-" clears it.
   Every import is checked first: the same code runs inside a transaction that is
   rolled back, so the preview shows exactly what applying it will do. Applying
   is one transaction too, so it all goes in or none of it does. */

const PRODUCT_CSV_COLUMNS = ['sku', 'slug', 'name', 'price', 'sale_price', 'sale_from', 'sale_to', 'stock', 'backorders', 'visible',
                             'main_category', 'categories', 'option_name', 'option2_name', 'choice', 'choice2', 'choice_sku', 'choice_price', 'choice_sale_price',
                             'choice_active', 'weight', 'choice_weight', 'group_prices', 'photos', 'old_url', 'related', 'description'];
const IMPORT_MAX_ROWS = 20000;
const IMPORT_SHOW_CHANGES = 50;

/* "Cars > SRX2 > Spares" for a category id. */
function cat_csv_path(int $id): string
{
    return implode(' > ', array_column(cat_trail($id), 'name'));
}

/* --------------------------------------------------------------- export */

function admin_export_products(): never
{
    require_admin();
    $cats = fn(array $ids) => implode(' | ', array_map('cat_csv_path', array_filter($ids, fn($i) => cat_by_id($i))));
    $extra = [];
    foreach (db()->query('SELECT product_id, category_id FROM product_categories ORDER BY product_id, category_id') as $r) {
        $extra[(int)$r['product_id']][] = (int)$r['category_id'];
    }
    $vars = [];
    foreach (db()->query('SELECT * FROM variants ORDER BY product_id, position, id') as $v) {
        $vars[(int)$v['product_id']][] = $v;
    }
    $num = fn($x) => $x === null ? '' : (string)(int)$x;
    $money = fn($x) => $x === null ? '' : price_input((int)$x);
    $kg = fn($x) => weight_input($x === null ? null : (int)$x);
    $gnames = array_column(all_groups(), 'name', 'id');
    $gp = [];
    foreach (db()->query('SELECT * FROM group_prices ORDER BY group_id') as $r) {
        if (isset($gnames[$r['group_id']])) {
            $gp[$r['product_id'] . ':' . $r['variant_id']][] = $gnames[$r['group_id']] . '=' . price_input((int)$r['price_pence']);
        }
    }
    $gpc = fn(string $k) => implode(' | ', $gp[$k] ?? []);
    $rel = [];
    foreach (db()->query('SELECT r.product_id, x.sku, x.slug FROM product_related r JOIN products x ON x.id = r.related_id ORDER BY r.product_id, r.position') as $r) {
        $rel[(int)$r['product_id']][] = $r['sku'] !== '' ? $r['sku'] : $r['slug'];
    }
    $old = [];
    foreach (db()->query('SELECT product_id, old_path FROM redirects ORDER BY old_path') as $r) {
        $old[(int)$r['product_id']][] = $r['old_path'];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-' . gmdate('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF", implode(',', array_map('csv_cell', PRODUCT_CSV_COLUMNS)), "\r\n";
    foreach (db()->query('SELECT * FROM products ORDER BY id') as $p) {
        $base = [$p['sku'], $p['slug'], $p['name'], price_input((int)$p['price_pence']), $money($p['sale_pence']), $p['sale_from'], $p['sale_to'],
                 $num($p['stock']), $p['backorders'], (int)$p['visible'], (int)$p['category_id'] && cat_by_id((int)$p['category_id']) ? cat_csv_path((int)$p['category_id']) : '',
                 $cats(array_diff($extra[(int)$p['id']] ?? [], [(int)$p['category_id']])), $p['option_name'], $p['option2_name']];
        $rows = [];
        foreach ($vars[(int)$p['id']] ?? [] as $v) {   // a row per choice; stock is the choice's own
            $r = $base;
            $r[7] = $num($v['stock']);
            $rows[] = array_merge($r, [$v['name'], $v['name2'], $v['sku'], $money($v['price_pence']), $money($v['sale_pence']), (int)$v['active'],
                                       $kg($p['weight_g']), $kg($v['weight_g']),
                                       $gpc($p['id'] . ':' . ($v['price_pence'] === null && !isset($gp[$p['id'] . ':' . $v['id']]) ? 0 : $v['id'])), '', implode(' | ', $old[(int)$p['id']] ?? []), implode(' | ', $rel[(int)$p['id']] ?? []), $p['description']]);
        }
        $rows = $rows ?: [array_merge($base, ['', '', '', '', '', '', $kg($p['weight_g']), '', $gpc($p['id'] . ':0'), '', implode(' | ', $old[(int)$p['id']] ?? []), implode(' | ', $rel[(int)$p['id']] ?? []), $p['description']])];
        foreach ($rows as $r) {
            echo implode(',', array_map('csv_cell', $r)), "\r\n";
        }
    }
    exit;
}

/* --------------------------------------------------------------- reading */

/* The file as rows of column => text, keyed by spreadsheet row number (the
   header is row 1). Problems with the file as a whole go in $errors. */
function import_read(string $path, array &$errors, array &$warnings): array
{
    $raw = (string)@file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    if (trim($raw) === '') {
        $errors[] = [0, 'The file is empty.'];
        return [];
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {   // saved by older Excel: Windows-1252
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $first = strtok($raw, "\n");
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $head = fgetcsv($fh, null, $sep, '"', '');
    $cols = array_map(fn($h) => strtolower(str_replace([' ', '-'], '_', trim((string)$h))), $head ?: []);
    $known = array_flip(PRODUCT_CSV_COLUMNS);
    if (!array_intersect($cols, ['sku', 'slug', 'name'])) {
        $errors[] = [1, 'The first row should name the columns, with at least one of sku, slug or name. See the list of columns below.'];
        return [];
    }
    if ($odd = array_diff(array_filter($cols, fn($c) => $c !== ''), PRODUCT_CSV_COLUMNS)) {
        $warnings[] = 'These columns aren\'t ones the shop knows, so they were left out: ' . implode(', ', $odd) . '.';
    }
    if (count($cols) !== count(array_unique($cols))) {
        $errors[] = [1, 'A column name appears twice.'];
        return [];
    }
    $rows = [];
    $n = 1;
    while (($r = fgetcsv($fh, null, $sep, '"', '')) !== false) {
        $n++;
        if ($r === [null] || !array_filter($r, fn($x) => trim((string)$x) !== '')) {
            continue;   // a blank row
        }
        if (count($rows) >= IMPORT_MAX_ROWS) {
            $errors[] = [$n, 'The file has more than ' . number_format(IMPORT_MAX_ROWS) . ' rows. Split it into smaller files.'];
            break;
        }
        $row = [];
        foreach ($cols as $i => $c) {
            if (isset($known[$c])) {
                $v = (string)($r[$i] ?? '');
                // csv_cell() puts ' before text a spreadsheet would read as a formula; take it off again.
                if (strlen($v) > 1 && $v[0] === "'" && strpbrk($v[1], "=+-@\t\r") !== false) {
                    $v = substr($v, 1);
                }
                $row[$c] = $c === 'description' ? trim(str_replace("\r\n", "\n", $v)) : trim($v);
            }
        }
        $rows[$n] = $row;
    }
    fclose($fh);
    if (!$rows && !$errors) {
        $errors[] = [0, 'The file has a header row but no products.'];
    }
    return $rows;
}

/* A day typed into a spreadsheet: 2026-12-01, or 01/12/2026 as Excel shows it in the UK. */
function import_date(string $s): ?string
{
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
        $s = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return sale_date($s);
}

function import_bool(string $s): ?int
{
    $s = strtolower($s);
    return in_array($s, ['1', 'yes', 'y', 'true'], true) ? 1 : (in_array($s, ['0', 'no', 'n', 'false'], true) ? 0 : null);
}

/* A category path like "Cars > SRX2 > Spares": its id, made if it doesn't exist yet. */
function import_cat(string $path, array &$made, ?string &$err): int
{
    $names = array_values(array_filter(array_map('trim', explode('>', $path)), fn($x) => $x !== ''));
    if (!$names) {
        return 0;
    }
    if (count($names) > CAT_DEPTH) {
        $err = 'The category ' . $path . ' is more than ' . CAT_DEPTH . ' levels deep.';
        return 0;
    }
    $parent = 0;
    foreach ($names as $i => $name) {
        if (mb_strlen($name) > 60) {
            $err = 'The category name ' . $name . ' is longer than 60 characters.';
            return 0;
        }
        $found = 0;
        foreach (cat_children($parent) as $c) {
            if (mb_strtolower($c['name']) === mb_strtolower($name)) {
                $found = $c['id'];
                break;
            }
        }
        if (!$found) {
            db()->prepare('INSERT INTO categories (name, slug, position, parent_id) VALUES (?, ?, ?, ?)')
                ->execute([$name, unique_slug('categories', slugify(implode(' ', array_slice($names, 0, $i + 1)))), cat_next_position($parent), $parent]);
            $found = (int)db()->lastInsertId();
            cat_all(true);
            $made[] = implode(' > ', array_slice($names, 0, $i + 1));
        }
        $parent = $found;
    }
    return $parent;
}

/* --------------------------------------------------------------- importing */

/* Runs the import. With $apply false it's undone afterwards: a preview. Returns a
   report: counts, the changes (product label => [[field, before, after]...]), errors
   ([row, message]) and warnings. Nothing is kept if there are any errors. */
function import_run(array $rows, bool $apply): array
{
    $pdo = db();
    $rep = ['new' => 0, 'changed' => 0, 'same' => 0, 'choices' => 0, 'cats' => [], 'changes' => [], 'errors' => [], 'warnings' => [], 'hidden' => 0,
            'photo_items' => [], 'later' => 0];
    $queued = [];   // product|address, so a photo listed twice is fetched once
    $relLater = [];
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $touched = [];   // product id => 'new' | 'changed' | 'same'
        $firstRow = [];  // product id => [field => [value, row]] (rows for one product must agree)
        $byName = [];    // new products with no sku or slug, by name, so their choice rows find them
        $err = function (int $row, string $msg) use (&$rep) {
            $rep['errors'][] = [$row, $msg];
        };
        $note = function (int $pid, string $label, string $field, string $before, string $after) use (&$rep) {
            if ($before !== $after) {
                $rep['changes'][$pid]['label'] = $label;
                $rep['changes'][$pid]['diff'][] = [$field, $before, $after];
            }
        };
        $money = fn($x) => $x === null ? '' : price_input((int)$x);
        $findBy = function (string $col, string $val): ?array {
            $st = db()->prepare("SELECT * FROM products WHERE $col = ?" . ($col === 'sku' ? ' COLLATE NOCASE' : '') . ' LIMIT 1');
            $st->execute([$val]);
            return $st->fetch() ?: null;
        };
        foreach ($rows as $n => $r) {
            $g = fn(string $k) => $r[$k] ?? '';   // '' = column missing or cell blank: leave as it is
            $before = count($rep['errors']);
            $sku = sku_clean($g('sku'));
            $slug = $g('slug');
            // ---- which product
            $bySku = $sku !== '' && $sku !== '-' ? $findBy('sku', $sku) : null;
            $bySlug = $slug !== '' ? $findBy('slug', $slug) : null;
            if ($bySku && $bySlug && (int)$bySku['id'] !== (int)$bySlug['id']) {
                $err($n, 'SKU ' . $sku . ' belongs to ' . $bySku['name'] . ', but the web address ' . $slug . ' belongs to ' . $bySlug['name'] . '.');
                continue;
            }
            $p = $bySku ?? $bySlug;
            if (!$p && $sku === '' && $slug === '' && $g('name') !== '' && isset($byName[mb_strtolower($g('name'))])) {
                $p = product_by_id($byName[mb_strtolower($g('name'))]);
            }
            $isNew = false;
            if (!$p) {
                $price = parse_price($g('price'));
                if ($g('name') === '' || $price === null) {
                    $err($n, 'No product has ' . ($sku !== '' ? 'SKU ' . $sku : ($slug !== '' ? 'the web address ' . $slug : 'this name'))
                        . '. To add it as a new product, give it a name and a price.');
                    continue;
                }
                if ($sku !== '' && !sku_valid($sku)) {
                    $err($n, 'The SKU ' . $sku . ' can only have letters, numbers, spaces and . _ / - in it.');
                    continue;
                }
                if ($sku !== '' && ($who = sku_taken($sku, 0)) !== null) {
                    $err($n, 'The SKU ' . $sku . ' is already used by ' . $who . '.');
                    continue;
                }
                $pdo->prepare('INSERT INTO products (slug, name, price_pence, description, visible, sku, created, updated) VALUES (?, ?, ?, ?, 0, ?, ?, ?)')
                    ->execute([unique_slug('products', slugify($slug !== '' ? $slug : $g('name'))), mb_substr($g('name'), 0, 120), $price,
                               $g('description'), $sku === '-' ? '' : $sku, now(), now()]);
                $p = product_by_id((int)$pdo->lastInsertId());
                $isNew = true;
                if ($sku === '' && $slug === '') {
                    $byName[mb_strtolower($g('name'))] = (int)$p['id'];
                }
            }
            $pid = (int)$p['id'];
            $label = $p['name'] . ($p['sku'] !== '' ? ' (' . $p['sku'] . ')' : '');
            $touched[$pid] ??= $isNew ? 'new' : 'same';
            // ---- the product's own fields (every row for a product must agree on these)
            $set = [];
            foreach (['sku', 'slug', 'name', 'price', 'sale_price', 'sale_from', 'sale_to', 'backorders', 'visible', 'main_category', 'categories', 'option_name', 'option2_name', 'weight', 'description'] as $f) {
                if ($g($f) === '') {
                    continue;
                }
                if (isset($firstRow[$pid][$f]) && $firstRow[$pid][$f][0] !== $g($f)) {
                    $err($n, 'The ' . $f . ' differs from row ' . $firstRow[$pid][$f][1] . ', which is the same product.');
                    continue;
                }
                $firstRow[$pid][$f] ??= [$g($f), $n];
                if (!($isNew && in_array($f, ['name', 'price', 'description'], true))) {   // already saved when it was added
                    $set[$f] = $g($f);
                }
            }
            $isChoice = $g('choice') !== '';
            if (!$isChoice && $g('stock') !== '') {
                $set['stock'] = $g('stock');
            }
            $upd = [];
            if (isset($set['sku']) && !$isNew) {
                $v = $set['sku'] === '-' ? '' : sku_clean($set['sku']);
                if (!sku_valid($v)) {
                    $err($n, 'The SKU ' . $v . ' can only have letters, numbers, spaces and . _ / - in it.');
                } elseif (($who = sku_taken($v, $pid)) !== null) {
                    $err($n, 'The SKU ' . $v . ' is already used by ' . $who . '.');
                } else {
                    $upd['sku'] = [$p['sku'], $v, 'SKU'];
                }
            }
            if (isset($set['slug']) && !$isNew && $set['slug'] !== $p['slug']) {
                $v = slugify($set['slug']);
                if ($v !== $set['slug'] || unique_slug('products', $v, $pid) !== $v) {
                    $err($n, 'The web address ' . $set['slug'] . ' isn\'t free or isn\'t made of lower-case letters, numbers and dashes.');
                } else {
                    $upd['slug'] = [$p['slug'], $v, 'web address'];
                }
            }
            if (isset($set['name'])) {
                $upd['name'] = [$p['name'], mb_substr($set['name'], 0, 120), 'name'];
            }
            $price = (int)$p['price_pence'];
            if (isset($set['price'])) {
                $v = parse_price($set['price']);
                if ($v === null) {
                    $err($n, 'The price ' . $set['price'] . ' isn\'t a price like 12.50.');
                } else {
                    $upd['price_pence'] = [$p['price_pence'], $v, 'price'];
                    $price = $v;
                }
            }
            if (isset($set['sale_price'])) {
                $v = $set['sale_price'] === '-' ? null : parse_price($set['sale_price']);
                if ($set['sale_price'] !== '-' && $v === null) {
                    $err($n, 'The sale price ' . $set['sale_price'] . ' isn\'t a price like 9.99.');
                } else {
                    $upd['sale_pence'] = [$p['sale_pence'], $v, 'sale price'];
                }
            }
            $sale = array_key_exists('sale_pence', $upd) ? $upd['sale_pence'][1] : $p['sale_pence'];
            if ($sale !== null && (int)$sale >= $price && count($rep['errors']) === $before) {
                $err($n, 'The sale price (' . money((int)$sale) . ') should be less than the price (' . money($price) . ').');
            }
            foreach (['sale_from' => 'sale starts', 'sale_to' => 'sale ends'] as $f => $what) {
                if (isset($set[$f])) {
                    $v = $set[$f] === '-' ? '' : import_date($set[$f]);
                    if ($v === null) {
                        $err($n, 'The ' . $f . ' date ' . $set[$f] . ' isn\'t a day like 2026-12-01 or 01/12/2026.');
                    } else {
                        $upd[$f] = [$p[$f], $v, $what];
                    }
                }
            }
            $sf = $upd['sale_from'][1] ?? $p['sale_from'];
            $st = $upd['sale_to'][1] ?? $p['sale_to'];
            if ($sf !== '' && $st !== '' && $st < $sf) {
                $err($n, 'The sale ends before it starts.');
            }
            if (isset($set['backorders'])) {
                $v = strtolower($set['backorders']);
                if (!isset(BACKORDER_CHOICES[$v])) {
                    $err($n, 'backorders should be no, yes or notify, not ' . $set['backorders'] . '.');
                } else {
                    $upd['backorders'] = [$p['backorders'], $v, 'when stock runs out'];
                }
            }
            $bo = $upd['backorders'][1] ?? $p['backorders'];
            $stockOk = fn(string $s) => $s === '-' || (preg_match('/^-?\d{1,6}$/', $s) && ((int)$s >= 0 || $bo !== 'no'));
            if (isset($set['stock'])) {
                if (!$stockOk($set['stock'])) {
                    $err($n, 'Stock should be a whole number (below zero only for a product that keeps selling), or - to stop counting.');
                } else {
                    $upd['stock'] = [$p['stock'], $set['stock'] === '-' ? null : (int)$set['stock'], 'stock'];
                }
            }
            if (isset($set['visible'])) {
                $v = import_bool($set['visible']);
                if ($v === null) {
                    $err($n, 'visible should be 1 or 0 (or yes or no).');
                } else {
                    $upd['visible'] = [$p['visible'], $v, 'in the shop'];
                }
            }
            if (isset($set['option_name'])) {
                $upd['option_name'] = [$p['option_name'], $set['option_name'] === '-' ? '' : mb_substr($set['option_name'], 0, 40), 'option name'];
            }
            if (isset($set['option2_name'])) {
                $upd['option2_name'] = [$p['option2_name'], $set['option2_name'] === '-' ? '' : mb_substr($set['option2_name'], 0, 40), 'second option name'];
            }
            if (isset($set['weight'])) {
                $w = $set['weight'] === '-' ? null : parse_weight($set['weight']);
                if ($set['weight'] !== '-' && $w === null) {
                    $err($n, 'The weight ' . $set['weight'] . ' should be in kg, like 0.5, or - for none.');
                } else {
                    $upd['weight_g'] = [$p['weight_g'], $w, 'weight'];
                }
            }
            if (isset($set['description'])) {
                $upd['description'] = [$p['description'], $set['description'] === '-' ? '' : $set['description'], 'description'];
            }
            $main = (int)$p['category_id'];
            if (isset($set['main_category'])) {
                $e = null;
                $v = $set['main_category'] === '-' ? 0 : import_cat($set['main_category'], $rep['cats'], $e);
                if ($e !== null) {
                    $err($n, $e);
                } else {
                    $upd['category_id'] = [$main, $v, 'category'];
                    $main = $v;
                }
            }
            $extraNow = array_values(array_diff(product_extra_cats($pid), [$main]));   // the main one never counts twice
            $extraNew = null;
            if (isset($set['categories'])) {
                $extraNew = [];
                foreach ($set['categories'] === '-' ? [] : array_filter(array_map('trim', explode('|', $set['categories']))) as $path) {
                    $e = null;
                    $id = import_cat($path, $rep['cats'], $e);
                    if ($e !== null) {
                        $err($n, $e);
                    } elseif ($id && $id !== $main) {
                        $extraNew[] = $id;
                    }
                }
                $extraNew = array_values(array_unique($extraNew));
            }
            // ---- a choice
            $vrow = null;
            $vupd = [];
            if ($isChoice) {
                $cname = mb_substr($g('choice'), 0, 60);
                $c2 = mb_substr($g('choice2'), 0, 60);
                $csku = sku_clean($g('choice_sku'));
                $vars = product_variants($pid);
                foreach ($vars as $x) {
                    if ($csku !== '' && $csku !== '-' && strcasecmp($x['sku'], $csku) === 0) {
                        $vrow = $x;
                    }
                }
                if (!$vrow) {   // by name, and by the second choice when given
                    $same = array_values(array_filter($vars, fn($x) => mb_strtolower($x['name']) === mb_strtolower($cname)
                        && ($c2 === '' || mb_strtolower($x['name2']) === mb_strtolower($c2 === '-' ? '' : $c2))));
                    if (count($same) > 1 && $c2 === '') {
                        $err($n, $p['name'] . ' has several choices called ' . $cname . '. Fill in choice2 to say which.');
                    }
                    $vrow = $same[0] ?? null;
                }
                $opt2 = $upd['option2_name'][1] ?? $p['option2_name'];
                $n2 = $c2 === '' ? ($vrow['name2'] ?? '') : ($c2 === '-' ? '' : $c2);
                if ($opt2 !== '' && $n2 === '') {
                    $err($n, 'Fill in choice2 for ' . $cname . ': ' . $p['name'] . ' has a second option (' . $opt2 . ').');
                }
                $cw = $g('choice_weight');
                $cweight = $cw === '' ? ($vrow['weight_g'] ?? null) : ($cw === '-' ? null : parse_weight($cw));
                if ($cw !== '' && $cw !== '-' && $cweight === null) {
                    $err($n, 'The choice weight ' . $cw . ' should be in kg, like 0.5, or - for none.');
                }
                $active = $g('choice_active') === '' ? (int)($vrow['active'] ?? 1) : import_bool($g('choice_active'));
                if ($active === null) {
                    $err($n, 'choice_active should be 1 or 0 (or yes or no).');
                }
                $cname0 = $cname;
                $cname = choice_name(['name' => $cname, 'name2' => $opt2 !== '' ? $n2 : '']);
                if ($csku !== '' && $csku !== '-') {
                    if (!sku_valid($csku)) {
                        $err($n, 'The choice SKU ' . $csku . ' can only have letters, numbers, spaces and . _ / - in it.');
                    } elseif (($who = sku_taken($csku, $pid)) !== null) {
                        $err($n, 'The choice SKU ' . $csku . ' is already used by ' . $who . '.');
                    } elseif (strcasecmp($upd['sku'][1] ?? $p['sku'], $csku) === 0
                              || array_filter($vars, fn($x) => strcasecmp($x['sku'], $csku) === 0 && (!$vrow || $x['id'] !== $vrow['id']))) {
                        $err($n, 'The choice SKU ' . $csku . ' is used twice on ' . $p['name'] . '.');
                    }
                }
                $cprice = null;
                $cp = $g('choice_price');
                if ($cp !== '' && $cp !== '-' && ($cprice = parse_price($cp)) === null) {
                    $err($n, 'The choice price ' . $cp . ' isn\'t a price.');
                }
                $own = $cp === '' ? ($vrow['price_pence'] ?? null) : ($cp === '-' ? null : $cprice);
                $cs = $g('choice_sale_price');
                $csale = $cs === '' ? ($vrow['sale_pence'] ?? null) : ($cs === '-' ? null : parse_price($cs));
                if ($cs !== '' && $cs !== '-' && $csale === null) {
                    $err($n, 'The choice sale price ' . $cs . ' isn\'t a price.');
                } elseif ($csale !== null && $own === null) {
                    $err($n, 'The choice ' . $cname . ' has a sale price but no price of its own.');
                } elseif ($csale !== null && (int)$csale >= (int)$own) {
                    $err($n, 'The sale price for ' . $cname . ' should be less than its price.');
                }
                if ($g('stock') !== '' && !$stockOk($g('stock'))) {
                    $err($n, 'Stock for ' . $cname . ' should be a whole number (below zero only for a product that keeps selling), or - to stop counting.');
                }
                if (count($rep['errors']) === $before) {
                    $vals = ['name' => $cname0, 'name2' => $opt2 !== '' ? $n2 : '', 'active' => (int)$active, 'weight_g' => $cweight === null ? null : (int)$cweight, 'sku' => $csku === '' ? ($vrow['sku'] ?? '') : ($csku === '-' ? '' : $csku),
                             'price_pence' => $own, 'sale_pence' => $csale,
                             'stock' => $g('stock') === '' ? ($vrow['stock'] ?? null) : ($g('stock') === '-' ? null : (int)$g('stock'))];
                    if (!$vrow) {
                        $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM variants WHERE product_id = ' . $pid)->fetchColumn();
                        $pdo->prepare('INSERT INTO variants (product_id, name, name2, active, weight_g, sku, price_pence, sale_pence, stock, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$pid, $vals['name'], $vals['name2'], $vals['active'], $vals['weight_g'], $vals['sku'], $vals['price_pence'], $vals['sale_pence'], $vals['stock'], $pos]);
                        $vrow = ['id' => (int)$pdo->lastInsertId()] + $vals;
                        $rep['choices']++;
                        $note($pid, $label, 'choice ' . $cname, '', 'added');
                        if (!$vars) {   // its first choice: stock is counted per choice from now on
                            $upd['stock'] = [$p['stock'], null, 'stock'];
                            if (($upd['option_name'][1] ?? $p['option_name']) === '') {
                                $upd['option_name'] = [$p['option_name'], 'Option', 'option name'];
                            }
                        }
                    } else {
                        foreach ($vals as $k => $x) {
                            $was = $vrow[$k];
                            $fmt = in_array($k, ['price_pence', 'sale_pence'], true) ? $money : ($k === 'weight_g' ? fn($y) => $y === null ? '' : weight_text((int)$y) : fn($y) => $y === null ? '' : (string)$y);
                            if ($fmt($was) !== $fmt($x)) {
                                $vupd[$k] = $x;
                                $note($pid, $label, $cname . ' ' . ['name' => 'name', 'name2' => $opt2 !== '' ? mb_strtolower($opt2) : 'second choice', 'active' => 'on sale', 'weight_g' => 'weight', 'sku' => 'SKU', 'price_pence' => 'price', 'sale_pence' => 'sale price', 'stock' => 'stock'][$k], $fmt($was), $fmt($x));
                            }
                        }
                        if ($vupd) {
                            $pdo->prepare('UPDATE variants SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($vupd))) . ' WHERE id = ?')
                                ->execute([...array_values($vupd), $vrow['id']]);
                        }
                    }
                }
            }
            // ---- photos by web address: fetched in batches once the import is done
            $photosHere = false;
            if (($ph = $g('photos')) !== '' && $ph !== '-') {
                $have = photo_sources($pid);
                foreach (array_values(array_unique(array_filter(array_map('trim', explode('|', $ph))))) as $k => $url) {
                    if (!preg_match('#^https?://[^\s]+$#i', $url)) {
                        $err($n, 'The photo address ' . $url . ' should start with http:// or https://.');
                        continue;
                    }
                    $photosHere = true;
                    if (isset($have['url:' . $url]) || isset($queued[$pid . '|' . $url])) {
                        continue;   // added by an earlier import
                    }
                    $queued[$pid . '|' . $url] = true;
                    $rep['photo_items'][] = ['pid' => $pid, 'pos' => $k + 1, 'url' => $url, 'source' => 'url:' . $url,
                                             'name' => basename((string)parse_url($url, PHP_URL_PATH)) ?: 'photo', 'show' => import_bool($g('visible')) === 1];
                }
            }
            // ---- related products: set once every row is in (they may be further down the file)
            if ($g('related') !== '') {
                $relLater[$pid] = [$n, $g('related'), $label];
            }
            // ---- the old shop's addresses for it, for redirects
            foreach ($g('old_url') === '' ? [] : array_filter(array_map('trim', explode('|', $g('old_url')))) as $ou) {
                if (($e = redirect_set($ou, $pid)) !== null) {
                    $err($n, $e);
                }
            }
            if (count($rep['errors']) !== $before) {
                continue;
            }
            // ---- save the product's changes
            $fmt = fn($k, $x) => $x === null ? '' : (in_array($k, ['price_pence', 'sale_pence'], true) ? $money($x)
                 : ($k === 'weight_g' ? weight_text((int)$x) : ($k === 'category_id' ? ((int)$x ? cat_csv_path((int)$x) : '') : ($k === 'visible' ? ((int)$x ? 'yes' : 'no') : (string)$x))));
            $changes = [];
            if (isset($upd['visible']) && (int)$upd['visible'][1] === 1 && !(int)$p['visible'] && !product_photos($pid)) {
                unset($upd['visible']);   // never on sale without a photo
                $photosHere ? $rep['later']++ : $rep['hidden']++;   // ...shown once its photo arrives, if it has one coming
            }
            foreach ($upd as $k => [$was, $now, $what]) {
                if ($fmt($k, $was) !== $fmt($k, $now)) {
                    $changes[$k] = $now;
                    $note($pid, $label, $what, $k === 'description' ? mb_strimwidth((string)$was, 0, 60, '…') : $fmt($k, $was),
                          $k === 'description' ? mb_strimwidth((string)$now, 0, 60, '…') : $fmt($k, $now));
                }
            }
            if ($changes) {
                $changes['updated'] = now();
                $pdo->prepare('UPDATE products SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($changes))) . ' WHERE id = ?')
                    ->execute([...array_values($changes), $pid]);
            }
            if ($extraNew !== null && (array_diff($extraNew, $extraNow) || array_diff($extraNow, $extraNew))) {
                $note($pid, $label, 'also in', implode(' | ', array_map('cat_csv_path', $extraNow)), implode(' | ', array_map('cat_csv_path', $extraNew)));
                product_set_extra_cats($pid, $extraNew, $main);
            }
            // ---- group prices: "Trade=8.50 | Reseller=9"; on a choice's row, for that choice
            // (or, for a choice at the product's price, the product's), else the product's.
            if (($gpc = $g('group_prices')) !== '') {
                $gvid = $isChoice && $vrow && (($vupd['price_pence'] ?? $vrow['price_pence']) !== null) ? (int)$vrow['id'] : 0;
                $grpByName = [];
                foreach (all_groups() as $gr) {
                    $grpByName[mb_strtolower($gr['name'])] = $gr;
                }
                $gset = [];
                $bad = false;
                foreach ($gpc === '-' ? [] : array_filter(array_map('trim', explode('|', $gpc))) as $pair) {
                    [$gname, $gval] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
                    $gr = $grpByName[mb_strtolower($gname)] ?? null;
                    $gpp = parse_price($gval);
                    if (!$gr) {
                        $err($n, 'There\'s no customer group called ' . $gname . '. Add it in Customers → Groups first.');
                        $bad = true;
                    } elseif ($gpp === null) {
                        $err($n, 'The ' . $gname . ' price ' . $gval . ' isn\'t a price.');
                        $bad = true;
                    } else {
                        $gset[(int)$gr['id']] = $gpp;
                    }
                }
                if (!$bad) {
                    $st = $pdo->prepare('SELECT group_id, price_pence FROM group_prices WHERE product_id = ? AND variant_id = ?');
                    $st->execute([$pid, $gvid]);
                    $now = array_map('intval', array_column($st->fetchAll(), 'price_pence', 'group_id'));
                    ksort($now);
                    ksort($gset);
                    if ($now !== $gset) {
                        $pdo->prepare('DELETE FROM group_prices WHERE product_id = ? AND variant_id = ?')->execute([$pid, $gvid]);
                        foreach ($gset as $gid => $gpp) {
                            $pdo->prepare('INSERT INTO group_prices (group_id, product_id, variant_id, price_pence) VALUES (?, ?, ?, ?)')->execute([$gid, $pid, $gvid, $gpp]);
                        }
                        $byId = array_column(all_groups(), 'name', 'id');
                        $show = fn(array $a) => implode(' | ', array_map(fn($k, $x) => ($byId[$k] ?? '') . '=' . price_input($x), array_keys($a), $a));
                        $note($pid, $label, ($gvid ? choice_name($vrow) . ' ' : '') . 'group prices', $show($now), $show($gset));
                    }
                }
            }
            if ($touched[$pid] === 'same' && isset($rep['changes'][$pid])) {
                $touched[$pid] = 'changed';
            }
        }
        foreach ($relLater as $pid => [$n, $list, $label]) {
            $errs = [];
            $ids = $list === '-' ? [] : related_parse($list, $pid, $errs);
            foreach ($errs as $e) {
                $err($n, $e);
            }
            if (!$errs && $ids !== related_ids($pid)) {
                $show = fn(array $a) => implode(' | ', array_map(fn($i) => related_ref(product_by_id($i) ?? ['sku' => '', 'slug' => '?']), $a));
                $note($pid, $label, 'related', $show(related_ids($pid)), $show($ids));
                related_save($pid, $ids);
                if ($touched[$pid] === 'same') {
                    $touched[$pid] = 'changed';
                }
            }
        }
        foreach ($touched as $pid => $how) {
            $rep[$how]++;
            if ($how === 'new') {
                $rep['changes'][$pid]['new'] = true;
                $rep['changes'][$pid]['label'] ??= (string)product_by_id($pid)['name'];
            }
        }
        $rep['cats'] = array_values(array_unique($rep['cats']));
        if ($apply && !$rep['errors']) {
            $pdo->exec('COMMIT');
        } else {
            $pdo->exec('ROLLBACK');
        }
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    cat_all(true);
    return $rep;
}

/* ------------------------------------------------------------------- page */

function import_file(string $token): string
{
    return data_dir() . '/import-' . $token . '.csv';
}

function view_admin_import(): never
{
    require_admin();
    if (($_GET['export'] ?? '') === 'products') {
        admin_export_products();
    }
    $seg = $GLOBALS['shop_seg'] ?? [];
    if (($seg[2] ?? '') === 'job' && isset($seg[3])) {
        view_photo_job((int)$seg[3]);
    }
    foreach (glob(data_dir() . '/import-*.csv') ?: [] as $old) {   // checked but never applied
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    foreach (glob(data_dir() . '/photos-*', GLOB_ONLYDIR) ?: [] as $old) {   // photos checked but never added
        if (filemtime($old) < time() - 86400 && !db()->query("SELECT 1 FROM import_jobs WHERE finished = '' AND dir = " . db()->quote($old))->fetchColumn()) {
            photo_dir_remove($old);
        }
    }
    $report = '';
    if (is_post()) {
        csrf_check();
        $report = photo_import_post() ?? '';
    }
    if (is_post() && $report === '') {
        if (post('do') === 'apply') {
            $token = (string)($_SESSION['shop_import'] ?? '');
            if ($token === '' || !hash_equals($token, post('token')) || !is_file(import_file($token))) {
                flash('That check has expired. Please upload the file again.');
                redirect(admin_url('import'));
            }
            $errors = $warnings = [];
            $rows = import_read(import_file($token), $errors, $warnings);
            $rep = $errors ? null : import_run($rows, true);
            @unlink(import_file($token));
            unset($_SESSION['shop_import']);
            if ($errors || $rep['errors']) {
                flash('The file has problems now, so nothing was imported. Please check it again.');
                redirect(admin_url('import'));
            }
            if ($rep['photo_items']) {
                $items = photo_items_mark_first($rep['photo_items']);
                $id = photo_job_create('urls', count($items) . ' photo' . (count($items) === 1 ? '' : 's') . ' from the web, ' . date('j M H:i'), $items, !empty($_POST['replace']));
                flash('Imported: ' . import_counts($rep) . '. Now fetching the photos.');
                redirect(admin_url('import/job/' . $id));
            }
            flash('Imported: ' . import_counts($rep) . '.' . ($rep['new'] ? ' New products are hidden until you add photos and show them.' : ''));
            redirect(url_with(admin_url(), $rep['new'] ? ['show' => 'hidden'] : []));
        }
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is bigger than this server accepts. ' . upload_limit_text() : 'Choose a CSV file to check.');
            redirect(admin_url('import'));
        }
        $token = bin2hex(random_bytes(12));
        move_uploaded_file($f['tmp_name'], import_file($token));
        @chmod(import_file($token), 0600);
        $errors = $warnings = [];
        $started = microtime(true);
        $rows = import_read(import_file($token), $errors, $warnings);
        $rep = $errors ? ['errors' => $errors, 'warnings' => [], 'changes' => [], 'new' => 0, 'changed' => 0, 'same' => 0, 'choices' => 0, 'cats' => [], 'hidden' => 0]
                       : import_run($rows, false);
        $rep['warnings'] = array_merge($warnings, $rep['warnings']);
        $_SESSION['shop_import'] = $token;
        $report = import_report_html($rep, count($rows), (string)$f['name'], $token, microtime(true) - $started);
    }
    $cols = '<table class="cols"><tr><th>Column</th><th>What goes in it</th></tr>'
        . '<tr><td><code>sku</code></td><td>Your part number. Rows are matched to products by SKU first.</td></tr>'
        . '<tr><td><code>slug</code></td><td>The product\'s web address, the part after /shop/p/. Matched next.</td></tr>'
        . '<tr><td><code>name</code>, <code>price</code></td><td>Needed for a new product. Prices like 12.50.</td></tr>'
        . '<tr><td><code>sale_price</code>, <code>sale_from</code>, <code>sale_to</code></td><td>A sale, with optional first and last days (2026-12-01 or 01/12/2026).</td></tr>'
        . '<tr><td><code>stock</code></td><td>How many you have. On a choice\'s row, that choice\'s stock.</td></tr>'
        . '<tr><td><code>backorders</code></td><td><code>no</code>, <code>yes</code> or <code>notify</code>: what happens when stock runs out.</td></tr>'
        . '<tr><td><code>visible</code></td><td>1 to show in the shop, 0 to hide. A product without a photo stays hidden.</td></tr>'
        . '<tr><td><code>main_category</code></td><td>A path like <code>Cars &gt; SRX2 &gt; Spares</code>. Categories that don\'t exist yet are made.</td></tr>'
        . '<tr><td><code>categories</code></td><td>Other categories it shows in, separated by <code>|</code>.</td></tr>'
        . '<tr><td><code>option_name</code>, <code>choice</code>, <code>choice_sku</code>, <code>choice_price</code>, <code>choice_sale_price</code></td>'
        . '<td>For a product with options (like sizes), one row per choice. Leave choice_price empty for the product\'s price.</td></tr>'
        . '<tr><td><code>option2_name</code>, <code>choice2</code></td><td>A second option, like Colour as well as Size: each row is one pair, like Large and Red.</td></tr>'
        . '<tr><td><code>weight</code>, <code>choice_weight</code></td><td>Weight in kg, like 0.5, for delivery priced by weight. A choice\'s own weight, if it differs.</td></tr>'
        . '<tr><td><code>group_prices</code></td><td>Fixed prices for customer groups, like <code>Trade=8.50 | Reseller=9</code>. On a choice\'s row, for that choice. - clears them.</td></tr>'
        . '<tr><td><code>choice_active</code></td><td>1 to sell that choice, 0 to stop selling it (for a pair you don\'t make).</td></tr>'
        . '<tr><td><code>photos</code></td><td>Web addresses of its photos, separated by <code>|</code>. They\'re fetched after the import, in batches; one fetched before isn\'t fetched again.</td></tr>'
        . '<tr><td><code>old_url</code></td><td>Its address in your old shop, like <code>/product/wishbone-front/</code>, so it can redirect here (see Customers → Import).</td></tr>'
        . '<tr><td><code>related</code></td><td>Up to 8 related products ("You might also like"), by SKU or web address, separated by <code>|</code>. - clears them.</td></tr>'
        . '<tr><td><code>description</code></td><td>Paragraphs and lists as in the product form.</td></tr></table>';
    admin_shell('Import and export', '<h1>Import and export products</h1>' . $report
        . ($report === '' ? '<h2>Export</h2>
      <p class="muted">Every product as a spreadsheet: one row per product, or one row per choice for a product with options.
      Change it in Excel or Numbers, save it as CSV, and import it here.</p>
      <p><a class="btn quiet" href="' . e(admin_url('import') . (str_contains(admin_url('import'), '?') ? '&' : '?') . 'export=products') . '">Download products (CSV)</a></p>
      <h2>Import</h2>
      <p class="muted">Rows are matched to products by SKU, then by web address. Anything else becomes a new product, hidden until you
      add photos. A blank cell leaves that value as it is, so a file with just <code>sku</code> and <code>price</code> columns is a price
      update. To clear a value (a sale price, say), put a single <code>-</code> in its cell. Nothing is ever deleted.</p>
      <form method="post" enctype="multipart/form-data">' . csrf_field() . '
        <label>CSV file <input type="file" name="file" accept=".csv,text/csv" required></label>
        <button>Check this file</button></form>
      <p class="muted">You\'ll see what would change before anything does. This server accepts files up to ' . e(import_limit_text()) . '.</p>
      <details><summary>The columns</summary>' . $cols . '</details>' . photo_import_section() : ''));
}

/* "2M (about 6,000 products)": the upload limit, for a file of products (about 300 bytes a row). */
function import_limit_text(): string
{
    $raw = trim((string)ini_get('upload_max_filesize'));
    if (!preg_match('/^(\d+)\s*([kmg]?)/i', $raw, $m)) {
        return $raw;
    }
    $bytes = (int)$m[1] * ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])];
    return $raw . ' (about ' . number_format(max(1, intdiv($bytes, 300000)) * 1000) . ' products)';
}

function import_counts(array $rep): string
{
    $bits = [];
    foreach (['new' => 'new product', 'changed' => 'product changed', 'same' => 'product unchanged'] as $k => $w) {
        if ($rep[$k]) {
            $bits[] = number_format($rep[$k]) . ' ' . ($rep[$k] === 1 ? $w : str_replace('product', 'products', $w));
        }
    }
    if ($rep['choices']) {
        $bits[] = $rep['choices'] . ' new choice' . ($rep['choices'] === 1 ? '' : 's');
    }
    if ($rep['cats']) {
        $bits[] = count($rep['cats']) . ' new categor' . (count($rep['cats']) === 1 ? 'y' : 'ies');
    }
    return $bits ? implode(', ', $bits) : 'nothing to change';
}

function import_report_html(array $rep, int $rows, string $name, string $token, float $secs): string
{
    $out = '<div class="report"><p class="lede">Checked <b>' . e($name) . '</b>: ' . number_format($rows) . ' row' . ($rows === 1 ? '' : 's')
         . ' in ' . number_format($secs, 1) . ' seconds. Nothing has changed yet.</p>';
    if ($rep['errors']) {
        $out .= '<div class="err"><b>' . count($rep['errors']) . ' problem' . (count($rep['errors']) === 1 ? '' : 's')
              . '.</b> Nothing can be imported until ' . (count($rep['errors']) === 1 ? 'it\'s' : 'they\'re') . ' fixed: correct the file and check it again.'
              . '<table class="errs">' . implode('', array_map(fn($x) => '<tr><td>' . ($x[0] ? 'Row ' . $x[0] : 'File') . '</td><td>' . e($x[1]) . '</td></tr>',
                array_slice($rep['errors'], 0, 500))) . '</table>'
              . (count($rep['errors']) > 500 ? '<p>…and ' . (count($rep['errors']) - 500) . ' more.</p>' : '') . '</div>';
    } else {
        $out .= '<p class="flash"><b>' . e(ucfirst(import_counts($rep))) . '.</b>'
              . ($rep['cats'] ? ' New categories: ' . e(implode(', ', array_slice($rep['cats'], 0, 20))) . (count($rep['cats']) > 20 ? '…' : '') . '.' : '') . '</p>';
    }
    foreach ($rep['warnings'] as $w) {
        $out .= '<p class="muted">' . e($w) . '</p>';
    }
    if ($n = count($rep['photo_items'] ?? [])) {
        $out .= '<p class="flash">' . $n . ' photo' . ($n === 1 ? '' : 's') . ' to fetch from the web for ' . count(array_unique(array_column($rep['photo_items'], 'pid'))) . ' product'
              . (count(array_unique(array_column($rep['photo_items'], 'pid'))) === 1 ? '' : 's') . '. They\'re fetched in batches after the import, with a page showing how it\'s going.'
              . (($rep['later'] ?? 0) ? ' ' . $rep['later'] . ' product' . ($rep['later'] === 1 ? '' : 's') . ' will be shown once ' . ($rep['later'] === 1 ? 'its' : 'their') . ' first photo arrives.' : '') . '</p>';
    }
    if ($rep['hidden']) {
        $out .= '<p class="muted">' . $rep['hidden'] . ' product' . ($rep['hidden'] === 1 ? '' : 's') . ' will stay hidden: a product needs a photo before it can be shown.</p>';
    }
    if (!$rep['errors'] && ($rep['new'] || $rep['changed'] || !empty($rep['photo_items']))) {
        $out .= '<form method="post" class="actions-row" style="margin-top:1rem;border:0;padding:0;flex-wrap:wrap">' . csrf_field()
              . '<input type="hidden" name="do" value="apply"><input type="hidden" name="token" value="' . e($token) . '">'
              . (!empty($rep['photo_items']) ? '<label class="check" style="flex-basis:100%"><input type="checkbox" name="replace" value="1"> The photos replace each product\'s existing photos</label>' : '')
              . '<button>Apply these changes</button> <a href="' . e(admin_url('import')) . '">Cancel</a></form>';
    } else {
        $out .= '<p><a class="btn quiet" href="' . e(admin_url('import')) . '">Check another file</a></p>';
    }
    if ($rep['changes'] && !$rep['errors']) {
        $out .= '<h2>' . (count($rep['changes']) > IMPORT_SHOW_CHANGES ? 'The first ' . IMPORT_SHOW_CHANGES . ' of ' . number_format(count($rep['changes'])) . ' changes' : 'What will change') . '</h2>'
              . '<div class="table-wrap"><table class="changes"><tr><th>Product</th><th>What</th><th>Now</th><th>After</th></tr>';
        foreach (array_slice($rep['changes'], 0, IMPORT_SHOW_CHANGES, true) as $c) {
            if (!empty($c['new'])) {
                $out .= '<tr><td>' . e($c['label']) . '</td><td colspan="3"><span class="pill ok">New</span></td></tr>';
                continue;
            }
            foreach ($c['diff'] as $i => [$what, $was, $now]) {
                $out .= '<tr><td>' . ($i === 0 ? e($c['label']) : '') . '</td><td>' . e($what) . '</td><td class="was">' . e($was === '' ? '—' : $was)
                      . '</td><td>' . e($now === '' ? '—' : $now) . '</td></tr>';
            }
        }
        $out .= '</table></div>';
    }
    return $out . '</div>';
}
/* ================================================================== search */
/* 1.2.0. Searches product names, SKUs (the product's and its choices'), category
   names and descriptions. Uses SQLite's full-text search (FTS5) where the host
   has it, and a plainer LIKE search where it doesn't. Every word has to match,
   as the start of a word ("wish" finds "wishbone"), and an optional list of
   synonyms widens a word ("alu = alloy = aluminium"). An exact SKU comes first,
   and a search that is exactly one product's SKU goes straight to that product.

   The full-text index is a copy of the catalogue. Rather than every way of
   changing a product having to remember to update it, the index keeps a
   fingerprint of what it was built from and is rebuilt on the next search if
   the catalogue has changed since (a few tens of milliseconds for 2,000 products). */

const SEARCH_MAX_WORDS = 8;
const SEARCH_BOX_FROM = 12;   // the search box shows once the shop has this many products

function search_fts(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = setting('search_fts', '1') !== '0';   // can be switched off to use LIKE (tests use this)
        if ($ok) {
            try {
                db()->exec('CREATE VIRTUAL TABLE IF NOT EXISTS products_fts USING fts5(name, sku, cats, body,
                            tokenize = "unicode61 remove_diacritics 2", prefix = "2 3")');
            } catch (Throwable $e) {
                $ok = false;   // this host's SQLite has no FTS5
            }
        }
    }
    return $ok;
}

/* What the index was built from: changes whenever a product, choice or category does. */
function search_fingerprint(): string
{
    return (string)db()->query("SELECT
        (SELECT COUNT(*) || ':' || TOTAL(LENGTH(name) + LENGTH(sku) + LENGTH(description) + LENGTH(option_name) + LENGTH(option2_name)) || ':' || IFNULL(MAX(updated), '') || ':' || TOTAL(category_id) FROM products)
        || '|' || (SELECT COUNT(*) || ':' || TOTAL(LENGTH(name) + LENGTH(name2) + LENGTH(sku) + product_id + active) FROM variants)
        || '|' || (SELECT COUNT(*) || ':' || TOTAL(LENGTH(name) + parent_id) FROM categories)
        || '|' || (SELECT COUNT(*) || ':' || TOTAL(category_id * 7 + product_id) FROM product_categories)")->fetchColumn();
}

function search_index_fresh(): void
{
    $fp = search_fingerprint();
    if (setting('search_fp') === $fp) {
        return;
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('DELETE FROM products_fts');
        $extra = [];
        foreach ($pdo->query('SELECT product_id, category_id FROM product_categories') as $r) {
            $extra[(int)$r['product_id']][] = (int)$r['category_id'];
        }
        $vars = [];
        foreach ($pdo->query('SELECT product_id, name || \' \' || name2 AS name, sku FROM variants') as $v) {
            $vars[(int)$v['product_id']][] = $v;
        }
        $ins = $pdo->prepare('INSERT INTO products_fts (rowid, name, sku, cats, body) VALUES (?, ?, ?, ?, ?)');
        foreach ($pdo->query('SELECT id, name, sku, description, option_name || \' \' || option2_name AS option_name, category_id FROM products') as $p) {
            $id = (int)$p['id'];
            $cats = [];
            foreach (array_merge([(int)$p['category_id']], $extra[$id] ?? []) as $c) {
                array_push($cats, ...array_column(cat_trail($c), 'name'));
            }
            $vs = $vars[$id] ?? [];
            $ins->execute([$id, $p['name'], trim($p['sku'] . ' ' . implode(' ', array_column($vs, 'sku'))),
                           implode(' ', array_unique($cats)), $p['description'] . ' ' . $p['option_name'] . ' ' . implode(' ', array_column($vs, 'name'))]);
        }
        // Name matters most, then SKU, then category, then the description.
        $pdo->exec("INSERT INTO products_fts (products_fts, rank) VALUES ('rank', 'bm25(10.0, 8.0, 3.0, 1.0)')");
        set_setting('search_fp', $fp);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/* The synonyms setting as word => [every word it can mean]. One group a line:
   "alu = alloy = aluminium", or with commas. */
function search_synonyms(): array
{
    $map = [];
    foreach (preg_split('/\R/', setting('search_synonyms')) as $line) {
        $group = array_values(array_unique(array_filter(array_map(fn($w) => mb_strtolower(trim($w)), preg_split('/[=,]/', $line)), fn($w) => $w !== '')));
        if (count($group) > 1) {
            foreach ($group as $w) {
                $map[$w] = array_values(array_unique(array_merge($map[$w] ?? [], $group)));
            }
        }
    }
    return $map;
}

/* The search as a list of words, each with the words it may also be. */
function search_terms(string $s): array
{
    $syn = search_synonyms();
    $words = array_slice(array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($s))), fn($w) => $w !== '')), 0, SEARCH_MAX_WORDS);
    $terms = [];
    foreach ($words as $w) {
        $w = trim(preg_replace('/[^\p{L}\p{N}\-.\/]+/u', '', $w), '-./');
        if ($w !== '') {
            $terms[] = $syn[$w] ?? [$w];
        }
    }
    return $terms;
}

/* [where, args, order] for products matching the search. */
function search_sql(string $s): array
{
    $terms = search_terms($s);
    $exact = db()->quote(trim($s));
    $skuFirst = "CASE WHEN p.sku = $exact COLLATE NOCASE OR EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND v.sku = $exact COLLATE NOCASE) THEN 0 ELSE 1 END";
    if (!$terms) {
        return ['0', [], 'p.id'];
    }
    if (search_fts()) {
        search_index_fresh();
        // Each word as the start of a word, its synonyms as alternatives: ("alu"* OR "aluminium"*) AND ("wishbone"*)
        $match = implode(' AND ', array_map(fn($alts) => '(' . implode(' OR ', array_map(fn($w) => '"' . str_replace('"', '', $w) . '"*', $alts)) . ')', $terms));
        $m = db()->quote($match);
        return ["p.id IN (SELECT rowid FROM products_fts WHERE products_fts MATCH $m)", [],
                "$skuFirst, (SELECT rank FROM products_fts WHERE products_fts MATCH $m AND rowid = p.id), p.name COLLATE NOCASE"];
    }
    // No FTS5: every word somewhere in the name, SKUs, categories (and the ones they sit in) or description.
    $up = fn(string $id) => "(SELECT parent_id FROM categories WHERE id = $id)";
    $trail = fn(string $id) => "$id, " . $up($id) . ', ' . $up($up($id));
    $inCats = '(' . $trail('p.category_id') . ') OR c.id IN (SELECT pc.category_id FROM product_categories pc WHERE pc.product_id = p.id'
            . ' UNION SELECT parent_id FROM categories WHERE id IN (SELECT category_id FROM product_categories WHERE product_id = p.id)'
            . ' UNION SELECT parent_id FROM categories WHERE id IN (SELECT parent_id FROM categories WHERE id IN (SELECT category_id FROM product_categories WHERE product_id = p.id)))';
    $where = [];
    $args = [];
    $inName = [];
    foreach ($terms as $alts) {
        $any = [];
        foreach ($alts as $w) {
            $like = '%' . strtr($w, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $any[] = "(p.name LIKE ? ESCAPE '\\' OR p.sku LIKE ? ESCAPE '\\' OR p.description LIKE ? ESCAPE '\\'
                      OR EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.id AND (v.sku LIKE ? ESCAPE '\\' OR (v.name || ' ' || v.name2) LIKE ? ESCAPE '\\'))
                      OR EXISTS (SELECT 1 FROM categories c WHERE c.name LIKE ? ESCAPE '\\' AND (c.id IN $inCats)))";
            array_push($args, $like, $like, $like, $like, $like, $like);
            $inName[] = 'p.name LIKE ' . db()->quote($like) . " ESCAPE '\\'";
        }
        $where[] = '(' . implode(' OR ', $any) . ')';
    }
    return [implode(' AND ', $where), $args, "$skuFirst, CASE WHEN " . implode(' OR ', $inName) . ' THEN 0 ELSE 1 END, p.name COLLATE NOCASE'];
}

/* The shop's search box (in the shop menu once there are enough products to need it). */
function search_box(string $value = ''): string
{
    $route = setting('pretty_urls', '1') !== '1' ? '<input type="hidden" name="q" value="search">' : '';
    return '<form class="shop-search" role="search" method="get" action="' . e(strtok(shop_url('search'), '?')) . '">' . $route
         . '<input type="search" name="s" value="' . e($value) . '" placeholder="Search" aria-label="Search the shop" maxlength="100">'
         . '<button class="shop-button shop-quiet">Search</button></form>';
}

function search_box_wanted(): bool
{
    static $want = null;
    return $want ??= (int)db()->query('SELECT COUNT(*) FROM (SELECT 1 FROM products WHERE visible = 1 LIMIT ' . SEARCH_BOX_FROM . ')')->fetchColumn() >= SEARCH_BOX_FROM;
}

function view_search(): never
{
    $s = mb_substr(trim(str_in($_GET['s'] ?? '')), 0, 100);
    $body = '';
    $page = 1;
    if ($s !== '') {
        // Exactly one product's part number: go straight to it.
        if (!isset($_GET['page']) && !isset($_GET['sort'])) {
            $st = db()->prepare('SELECT DISTINCT p.slug FROM products p LEFT JOIN variants v ON v.product_id = p.id
                                 WHERE p.visible = 1 AND (p.sku = ? COLLATE NOCASE OR v.sku = ? COLLATE NOCASE) LIMIT 2');
            $st->execute([$s, $s]);
            $hit = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($hit) === 1) {
                redirect(shop_url('p/' . $hit[0]));
            }
        }
        [$where, $args, $order] = search_sql($s);
        [$grid, $page] = product_grid($where, $args, 'search', '', [
            'sorts' => ['best' => 'Best match'] + SORTS, 'best' => $order, 'keep' => ['s' => $s], 'noun' => 'products match', 'noun1' => 'product matches', 'count' => true]);
        $body = $grid !== '<p></p>' ? $grid
              : '<p>Nothing matches <strong>' . e($s) . '</strong>. Try fewer words, the start of a word, or a part number.</p>';
    }
    render('Search' . ($s !== '' ? ': ' . $s : '') . ($page > 1 ? ' (page ' . $page . ')' : ''),
        '<h1 class="post-title">' . ($s !== '' ? 'Search: ' . e($s) : 'Search') . '</h1><div class="post-body">'
        . ($s === '' || !search_box_wanted() ? '<div class="shop-search-page">' . search_box($s) . '</div>' : '') . $body . '</div>',
        ['route' => 'search', 'noindex' => true, 'crumbs' => [['Search', null]], 'search' => $s]);
}
/* ================================================================ invoices */
/* 1.2.0. A printable invoice for each paid order, from the admin order page and
   the customer's own order page; the browser's Print or Save as PDF does the rest.
   An order placed while the shop was VAT-registered gets a full UK VAT invoice
   (invoice number = order number); any other order gets a receipt. Prices in the
   shop include VAT, so each line's amount before VAT is worked out from what was
   charged, and the VAT is exactly what the order recorded: the invoice always adds
   up to the order total to the penny. */

const VAT_RATE = 20;

/* The order as invoice lines: [description, detail, qty, gross pence], items first,
   then discount, group saving and delivery as lines of their own. */
function invoice_lines(array $o): array
{
    $lines = [];
    foreach (order_items((int)$o['id']) as $it) {
        $lines[] = [$it['name'], trim(($it['option_label'] !== '' ? $it['option_label'] : '') . (($it['sku'] ?? '') !== '' ? ($it['option_label'] !== '' ? ' · ' : '') . 'SKU ' . $it['sku'] : '')),
                    (int)$it['qty'], (int)$it['unit_pence'] * (int)$it['qty']];
    }
    if ((int)$o['discount_pence'] > 0) {
        $lines[] = ['Discount', 'Code ' . $o['discount_code'], 1, -(int)$o['discount_pence']];
    }
    if ((int)$o['group_pence'] > 0) {
        $lines[] = [$o['group_name'] . ' prices', 'Customer group discount', 1, -(int)$o['group_pence']];
    }
    if ((int)$o['postage_pence'] > 0) {
        $lines[] = [order_kind_label($o), '', 1, (int)$o['postage_pence']];
    }
    // Anything an older order recorded differently: a line that makes it add up, never a wrong total.
    $diff = (int)$o['total_pence'] - array_sum(array_column($lines, 3));
    if ($diff !== 0) {
        $lines[] = ['Adjustment', '', 1, $diff];
    }
    return $lines;
}

/* Each line's amount before VAT, adding up exactly to the order's total less its VAT. */
function invoice_net(array $lines, int $vat, int $total): array
{
    $net = array_map(fn($l) => (int)round($l[3] * 100 / (100 + VAT_RATE)), $lines);
    $short = ($total - $vat) - array_sum($net);
    if ($short !== 0 && $net) {
        // A penny or two of rounding goes on the biggest single-item line (so its unit price
        // still times its quantity), or the biggest line if every line has several.
        $pick = array_filter(array_keys($lines), fn($i) => $lines[$i][2] === 1) ?: array_keys($lines);
        usort($pick, fn($a, $b) => abs($lines[$b][3]) <=> abs($lines[$a][3]));
        $net[$pick[0]] += $short;
    }
    return $net;
}

function uk_day(string $utc): string
{
    return $utc === '' ? '' : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('j F Y');
}

function invoice_html(array $o, string $back): string
{
    $vatInvoice = $o['vat_pence'] !== null;
    $lines = invoice_lines($o);
    $total = (int)$o['total_pence'];
    $vat = $vatInvoice ? (int)$o['vat_pence'] : 0;
    $net = $vatInvoice ? invoice_net($lines, $vat, $total) : [];
    $title = $vatInvoice ? 'VAT invoice' : 'Receipt';
    $day = uk_day($o['paid_at'] ?: $o['created']);
    $rows = '';
    foreach ($lines as $i => [$what, $detail, $qty, $gross]) {
        $rows .= '<tr><td>' . e($what) . ($detail !== '' ? '<div class="muted">' . e($detail) . '</div>' : '') . '</td><td class="n">' . $qty . '</td>'
               . ($vatInvoice
                   ? '<td class="n">' . unit_net($net[$i], $qty) . '</td><td class="n">' . VAT_RATE . '%</td><td class="n">' . money_signed($net[$i]) . '</td>'
                   : '<td class="n">' . money_signed((int)round($gross / max(1, $qty))) . '</td><td class="n">' . money_signed($gross) . '</td>')
               . '</tr>';
    }
    $foot = $vatInvoice
        ? '<tr><th colspan="4">Total before VAT</th><td class="n">' . money($total - $vat) . '</td></tr>'
          . '<tr><th colspan="4">VAT at ' . VAT_RATE . '%</th><td class="n">' . money($vat) . '</td></tr>'
          . '<tr class="grand"><th colspan="4">Total</th><td class="n">' . money($total) . '</td></tr>'
        : '<tr class="grand"><th colspan="3">Total paid</th><td class="n">' . money($total) . '</td></tr>';
    $seller = array_filter(array_merge([setting('business_name') ?: setting('shop_title', 'Shop')],
        explode("\n", str_replace("\r", '', trim(setting('business_address')))), [setting('business_email')]));
    $buyer = array_merge([$o['bill_name']], array_slice(mail_address_lines('bill_', $o), 1), [$o['email']]);
    $facts = $vatInvoice
        ? ['Invoice number' => (string)(int)$o['number'], 'Invoice date' => $day, 'Time of supply' => $day, 'VAT number' => $o['vat_number'],
           'Paid by' => method_label($o['payment_method'])]
        : ['Order number' => (string)(int)$o['number'], 'Date paid' => $day, 'Paid by' => method_label($o['payment_method'])];
    $factHtml = '';
    foreach ($facts as $k => $v) {
        $factHtml .= '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>';
    }
    return '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>' . $title . ' ' . (int)$o['number'] . '</title><style>
body{font:15px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:0;padding:24px;background:#eee}
.page{background:#fff;max-width:760px;margin:0 auto;padding:44px}
.top{display:flex;justify-content:space-between;gap:28px;flex-wrap:wrap;align-items:flex-start}
h1{font-size:26px;margin:0 0 14px}.muted{color:#666;font-size:13px}
.facts th{text-align:left;font-weight:600;padding:2px 14px 2px 0;color:#444;font-size:14px}.facts td{font-size:14px;padding:2px 0}
.parties{display:flex;gap:40px;flex-wrap:wrap;margin:26px 0 6px}.parties div{min-width:220px}
.parties h2{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#666;margin:0 0 4px}
table.lines{width:100%;border-collapse:collapse;margin:26px 0 0}
.lines th,.lines td{padding:9px 8px;border-bottom:1px solid #ddd;vertical-align:top;text-align:left}
.lines thead th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#666}
.lines .n{text-align:right;white-space:nowrap}.lines tfoot th{text-align:right;font-weight:600;border-bottom:0}.lines tfoot td{border-bottom:0}
.lines tr.grand th,.lines tr.grand td{font-size:17px;font-weight:700;border-top:2px solid #111}
.flag{display:inline-block;border:2px solid #b42318;color:#b42318;font-weight:700;padding:3px 10px;border-radius:4px;letter-spacing:.06em}
.bar{max-width:760px;margin:0 auto 12px;display:flex;justify-content:space-between;align-items:center}
.bar a{color:#111}
button{font:inherit;padding:8px 16px;border-radius:6px;border:1px solid #111;background:#111;color:#fff;cursor:pointer}
@media print{body{background:#fff;padding:0}.page{padding:0;max-width:none}.bar{display:none}}
</style></head><body><div class="bar"><a href="' . e($back) . '">&larr; Back to the order</a><button onclick="window.print()">Print or save as PDF</button></div>
<div class="page"><div class="top"><div><h1>' . $title . '</h1><table class="facts">' . $factHtml . '</table></div>'
        . ($o['status'] === 'refunded' ? '<div><span class="flag">REFUNDED</span><div class="muted" style="margin-top:6px">This order was refunded in full.</div></div>' : '') . '</div>
<div class="parties"><div><h2>From</h2>' . implode('<br>', array_map('e', $seller)) . '</div><div><h2>To</h2>' . implode('<br>', array_map('e', array_filter($buyer))) . '</div></div>
<table class="lines"><thead><tr><th>Description</th><th class="n">Qty</th>'
        . ($vatInvoice ? '<th class="n">Unit price<br>excl. VAT</th><th class="n">VAT rate</th><th class="n">Amount<br>excl. VAT</th>' : '<th class="n">Unit price</th><th class="n">Amount</th>')
        . '</tr></thead><tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table>'
        . ($vatInvoice ? '' : '<p class="muted" style="margin-top:22px">No VAT is charged: we are not registered for VAT.</p>')
        . '<p class="muted" style="margin-top:30px">Thank you for your order.</p></div></body></html>';
}

/* A unit price before VAT: £2.78, or £2.775 when it isn't a whole penny (prices include
   VAT, so 3 at £3.33 is £8.33 before VAT: £2.775 each). */
function unit_net(int $lineNet, int $qty): string
{
    $u = $lineNet / max(1, $qty);
    if (abs($u - round($u)) < 0.0001) {
        return money_signed((int)round($u));
    }
    return ($u < 0 ? '−' : '') . '£' . rtrim(number_format(abs($u) / 100, 3, '.', ','), '0');
}

/* −£1.50 for a discount line, £1.50 otherwise. */
function money_signed(int $pence): string
{
    return ($pence < 0 ? '−' : '') . money(abs($pence));
}

function invoice_ready(array $o): bool
{
    return in_array($o['status'], ['paid', 'refunded'], true);
}

function view_invoice_admin(int $id): never
{
    require_admin();
    $o = order_by('id', $id);
    if (!$o) {
        redirect(admin_url('orders'));
    }
    if (!invoice_ready($o)) {
        flash('An invoice is ready once the order is paid.');
        redirect(admin_url('order/' . $id));
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    echo invoice_html($o, admin_url('order/' . $id));
    exit;
}

/* The customer's copy, at their order's private address. */
function view_invoice_public(string $token): never
{
    $o = preg_match('/^[a-f0-9]{32}$/', $token) ? order_by('token', $token) : null;
    if (!$o || !invoice_ready($o)) {
        view_404();
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private');
    echo invoice_html($o, shop_url('order/' . $token));
    exit;
}

/* ================================================================ delivery
   Delivery methods (1.3). The shop's own delivery price and collection, from
   1.2's settings, are the first two methods, "deliver" and "collect"; more live
   in delivery_methods as m<id>. Each method is priced flat, by order value or by
   weight, and postcode zones can add a charge or take a method away. A shop with
   one price and no zones works exactly as it did. */

const DELIVERY_PRICING = ['flat' => 'One price', 'value' => 'By order value', 'weight' => 'By weight'];

/* Zones the owner can add in one go. Couriers draw these lines a little
   differently, so they're a starting point to edit. */
const ZONE_PRESETS = [
    'Highlands and Islands'          => 'IV KW HS ZE PA20-49 PA60-78 PH17-26 PH30-44 PH49-50 KA27-28',
    'Northern Ireland'               => 'BT',
    'Isles of Scilly'                => 'TR21-25',
    'Channel Islands and Isle of Man' => 'GY JE IM',
];

/* Every delivery method, in the order customers see them: the shop's own delivery,
   any others, then collection. $live: only those switched on. */
function delivery_methods(bool $live = true): array
{
    static $extra = null;
    $extra ??= db()->query('SELECT * FROM delivery_methods ORDER BY position, id')->fetchAll();
    $p = setting('postage_price');
    $o = setting('postage_free_over');
    $all = [['key' => 'deliver', 'name' => setting('postage_name') !== '' ? setting('postage_name') : 'UK delivery', 'kind' => 'deliver',
             'pricing' => setting('postage_pricing') ?: 'flat', 'price' => $p === '' ? null : (int)$p, 'free_over' => $o === '' ? null : (int)$o,
             'note' => '', 'active' => can_deliver(), 'own' => true]];
    foreach ($extra as $m) {
        $all[] = ['key' => 'm' . $m['id'], 'id' => (int)$m['id'], 'name' => $m['name'], 'kind' => $m['kind'], 'pricing' => $m['pricing'],
                  'price' => $m['price_pence'] === null ? null : (int)$m['price_pence'],
                  'free_over' => $m['free_over_pence'] === null ? null : (int)$m['free_over_pence'],
                  'note' => $m['note'], 'active' => (int)$m['active'] === 1, 'own' => false];
    }
    $all[] = ['key' => 'collect', 'name' => 'Collection', 'kind' => 'collect', 'pricing' => 'flat', 'price' => 0, 'free_over' => null,
              'note' => setting('postage_collect_note'), 'active' => can_collect(), 'own' => true];
    return $live ? array_values(array_filter($all, fn($m) => $m['active'])) : $all;
}

function delivery_method(string $key, bool $live = true): ?array
{
    foreach (delivery_methods($live) as $m) {
        if ($m['key'] === $key) {
            return $m;
        }
    }
    return null;
}

/* A method's price bands, lowest first, the open-ended one (up_to NULL) last. */
function delivery_bands(string $key): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT * FROM delivery_bands ORDER BY up_to IS NULL, up_to, id') as $b) {
            $all[$b['method']][] = ['up_to' => $b['up_to'] === null ? null : (int)$b['up_to'], 'price' => (int)$b['price_pence']];
        }
    }
    return $all[$key] ?? [];
}

function delivery_zones(): array
{
    static $z = null;
    return $z ??= db()->query('SELECT * FROM delivery_zones ORDER BY position, id')->fetchAll();
}

function zone_rate(string $key, int $zoneId): ?array
{
    static $r = null;
    if ($r === null) {
        $r = [];
        foreach (db()->query('SELECT * FROM delivery_zone_rates') as $x) {
            $r[$x['method'] . ':' . $x['zone_id']] = ['available' => (int)$x['available'] === 1, 'extra' => (int)$x['extra_pence']];
        }
    }
    return $r[$key . ':' . $zoneId] ?? null;
}

/* Does a postcode fall in a zone's areas? Areas are postcode areas (IV), districts
   (PA20) or district ranges (PA20-49), separated by spaces or commas. */
function postcode_in_areas(string $postcode, string $areas): bool
{
    $out = strtoupper(explode(' ', trim($postcode))[0] ?? '');
    if (!preg_match('/^([A-Z]{1,2})(\d{1,2})[A-Z]?$/', $out, $m)) {
        return false;
    }
    [$area, $district] = [$m[1], (int)$m[2]];
    foreach (preg_split('/[\s,]+/', strtoupper(trim($areas)), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (preg_match('/^([A-Z]{1,2})(?:(\d{1,2})(?:-(?:[A-Z]{1,2})?(\d{1,2}))?)?$/', $tok, $t)) {
            if ($t[1] !== $area) {
                continue;
            }
            if (!isset($t[2]) || $t[2] === '') {
                return true;
            }
            $lo = (int)$t[2];
            $hi = isset($t[3]) && $t[3] !== '' ? (int)$t[3] : $lo;
            if ($district >= $lo && $district <= $hi) {
                return true;
            }
        }
    }
    return false;
}

/* Area tokens that aren't postcode areas, districts or ranges, for the admin to fix. */
function zone_areas_bad(string $areas): array
{
    return array_values(array_filter(preg_split('/[\s,]+/', strtoupper(trim($areas)), -1, PREG_SPLIT_NO_EMPTY),
        fn($t) => !preg_match('/^[A-Z]{1,2}(\d{1,2}(-([A-Z]{1,2})?\d{1,2})?)?$/', $t)));
}

/* The first zone a postcode is in, or null for everywhere else. */
function zone_for_postcode(string $postcode): ?array
{
    if ($postcode === '') {
        return null;
    }
    foreach (delivery_zones() as $z) {
        if (postcode_in_areas($postcode, $z['areas'])) {
            return $z;
        }
    }
    return null;
}

/* What one of something weighs, in grams: its choice's weight, else the product's. */
function item_weight(array $p, ?array $v): int
{
    if ($v && ($v['weight_g'] ?? null) !== null) {
        return (int)$v['weight_g'];
    }
    return (int)($p['weight_g'] ?? 0);
}

/* What the basket weighs, in grams (downloads weigh nothing). */
function basket_weight(): int
{
    $g = 0;
    foreach ($_SESSION['shop_basket'] ?? [] as $key => $qty) {
        $item = basket_item((string)$key);
        if ($item && !is_digital($item[0])) {
            $g += (int)$qty * item_weight($item[0], $item[1]);
        }
    }
    return $g;
}

function weight_text(int $g): string
{
    if ($g < 1000) {
        return $g . ' g';
    }
    return rtrim(rtrim(number_format($g / 1000, 3, '.', ''), '0'), '.') . ' kg';
}

/* A weight as typed: "1.5" or "1.5 kg" (kilograms), or "500 g". Null if it isn't one. */
function parse_weight(string $s): ?int
{
    $s = strtolower(str_replace(',', '.', trim($s)));
    if (!preg_match('/^(\d{1,5}(?:\.\d{1,3})?)\s*(kg|g)?$/', $s, $m)) {
        return null;
    }
    return ($m[2] ?? '') === 'g' ? (int)round((float)$m[1]) : (int)round((float)$m[1] * 1000);
}

/* For a form box: grams as kilograms, "" for none. */
function weight_input(?int $g): string
{
    return $g === null ? '' : rtrim(rtrim(number_format($g / 1000, 3, '.', ''), '0'), '.');
}

/* What a method costs for goods worth $sub weighing $grams going to $zone:
   ok (can be used), price (null: no price set yet), to_free (how much more for free
   delivery), why (when it can't be used). */
function method_quote(array $m, int $sub, int $grams, ?array $zone): array
{
    $q = ['ok' => true, 'price' => null, 'to_free' => null, 'why' => '', 'extra' => 0];
    if ($m['kind'] === 'collect') {
        $q['price'] = $m['price'] ?? 0;
        return $q;
    }
    $rate = $zone ? zone_rate($m['key'], (int)$zone['id']) : null;
    if ($rate && !$rate['available']) {
        return ['ok' => false, 'price' => null, 'to_free' => null, 'why' => 'not to ' . $zone['name'], 'extra' => 0];
    }
    if ($m['pricing'] === 'flat') {
        $q['price'] = $m['price'];
    } else {
        $n = $m['pricing'] === 'weight' ? $grams : $sub;
        $found = null;
        foreach (delivery_bands($m['key']) as $b) {
            // Value bands run up to (not including) the amount; weight bands include it.
            if ($b['up_to'] === null || ($m['pricing'] === 'weight' ? $n <= $b['up_to'] : $n < $b['up_to'])) {
                $found = $b;
                break;
            }
        }
        if ($found === null) {
            $bands = delivery_bands($m['key']);
            if (!$bands) {
                return $q;   // no bands yet: like a price that isn't set
            }
            $top = end($bands)['up_to'];
            return ['ok' => false, 'price' => null, 'to_free' => null, 'extra' => 0,
                    'why' => $m['pricing'] === 'weight' ? 'not over ' . weight_text($top) : 'not for orders of ' . money($top) . ' or more'];
        }
        $q['price'] = $found['price'];
    }
    if ($q['price'] !== null && $m['free_over'] !== null) {
        if ($sub >= $m['free_over']) {
            $q['price'] = 0;
        } elseif ($q['price'] > 0) {
            $q['to_free'] = $m['free_over'] - $sub;
        }
    }
    if ($q['price'] !== null && $rate && $rate['extra'] > 0) {
        $q['price'] += $rate['extra'];
        $q['extra'] = $rate['extra'];
        $q['to_free'] = null;   // it's never free there, so don't promise it
    }
    return $q;
}

/* How an order's delivery is labelled: the method's name, as it was when ordered. */
function order_post_label(array $o): string
{
    if ($o['delivery'] === 'none') {
        return '';
    }
    if (($o['delivery_method'] ?? '') !== '') {
        return $o['delivery_method'];
    }
    return $o['delivery'] === 'collect' ? 'Collection' : 'UK delivery';
}

/* "Delivery" or "Collection", with the method's name when the shop has named it:
   "Delivery: Next working day". For packing slips and invoices. */
function order_kind_label(array $o): string
{
    $name = $o['delivery_method'] ?? '';
    if ($o['delivery'] === 'collect') {
        return $name !== '' && $name !== 'Collection' ? 'Collection: ' . $name : 'Collection';
    }
    return 'Delivery' . ($name !== '' && $name !== 'UK delivery' ? ': ' . $name : '') . (($o['delivery_zone'] ?? '') !== '' ? ' (' . $o['delivery_zone'] . ')' : '');
}

/* Where and when to collect an order: its method's details, else the shop's. */
function collect_note_for(array $o): string
{
    foreach (delivery_methods(false) as $m) {
        if ($m['kind'] === 'collect' && !$m['own'] && $m['name'] === ($o['delivery_method'] ?? '') && trim($m['note']) !== '') {
            return trim($m['note']);
        }
    }
    return trim(setting('postage_collect_note'));
}

/* Is any method that's switched on priced by weight? */
function weights_used(): bool
{
    foreach (delivery_methods() as $m) {
        if ($m['kind'] === 'deliver' && $m['pricing'] === 'weight') {
            return true;
        }
    }
    return false;
}

/* Is delivery set up enough to take orders? A method on with no price is not. */
function delivery_ready(): bool
{
    $live = delivery_methods();
    if (!$live) {
        return false;
    }
    foreach ($live as $m) {
        if ($m['kind'] === 'deliver' && ($m['pricing'] === 'flat' ? $m['price'] === null : !delivery_bands($m['key']))) {
            return false;
        }
    }
    return true;
}

/* -------------------------------------------------------------- admin */

/* A short description of how a method is priced, for the list. */
function method_price_text(array $m): string
{
    if ($m['kind'] === 'collect') {
        return $m['price'] ? money($m['price']) : 'Free';
    }
    if ($m['pricing'] === 'flat') {
        $t = $m['price'] === null ? 'No price yet' : ($m['price'] === 0 ? 'Free' : money($m['price']));
    } else {
        $bands = delivery_bands($m['key']);
        $t = !$bands ? 'No bands yet' : (DELIVERY_PRICING[$m['pricing']] . ': ' . implode(', ', array_map(function ($b) use ($m) {
            $p = $b['price'] === 0 ? 'free' : money($b['price']);
            if ($b['up_to'] === null) {
                return 'more: ' . $p;
            }
            return ($m['pricing'] === 'weight' ? 'to ' . weight_text($b['up_to']) : 'under ' . money($b['up_to'])) . ' ' . $p;
        }, $bands)));
    }
    return $t . ($m['free_over'] !== null ? '; free over ' . money($m['free_over']) : '');
}

/* The price fields of a method's form: how it's priced, the price or bands, free over. */
function method_price_fields(array $m): string
{
    $bands = delivery_bands($m['key']);
    $rows = '';
    $n = max(4, count($bands) + 2);
    for ($i = 0; $i < $n; $i++) {
        $b = $bands[$i] ?? null;
        $up = $b === null ? '' : ($b['up_to'] === null ? 'more' : ($m['pricing'] === 'weight' ? weight_input($b['up_to']) : price_input($b['up_to'])));
        $rows .= '<div class="bandrow"><input name="band_up[' . $i . ']" value="' . e($up) . '" aria-label="Up to, band ' . ($i + 1) . '" placeholder="'
               . ($i === 0 ? '2' : ($i === 1 ? '10' : '')) . '">'
               . '<div class="price"><span>£</span><input name="band_price[' . $i . ']" inputmode="decimal" value="'
               . e($b === null ? '' : price_input($b['price'])) . '" aria-label="Price, band ' . ($i + 1) . '"></div></div>';
    }
    $sel = '';
    foreach (DELIVERY_PRICING as $k => $label) {
        $sel .= '<option value="' . $k . '"' . ($m['pricing'] === $k ? ' selected' : '') . '>' . $label . '</option>';
    }
    return '<label>Priced <select name="pricing" class="pricing">' . $sel . '</select></label>'
        . '<div class="flat"' . ($m['pricing'] === 'flat' ? '' : ' hidden') . '><label>Price <div class="price"><span>£</span><input name="price" inputmode="decimal" placeholder="3.95" value="'
        . e($m['price'] === null ? '' : price_input($m['price'])) . '"></div></label></div>'
        . '<div class="bands"' . ($m['pricing'] === 'flat' ? ' hidden' : '') . '><p class="muted">One band a line: '
        . '<span class="by-weight"' . ($m['pricing'] === 'weight' ? '' : ' hidden') . '>the heaviest basket, in kg (2, or 0.5), and its price. Baskets heavier than the last band can\'t use this method, unless the last band says <code>more</code>.</span>'
        . '<span class="by-value"' . ($m['pricing'] === 'value' ? '' : ' hidden') . '>orders under this much (in £), and the price. Put <code>more</code> in the last band for anything above.</span></p>'
        . '<div class="bandhead"><span>Up to</span><span>Price</span></div>' . $rows . '</div>'
        . '<label>Free on orders over <span class="hint">(optional)</span><div class="price"><span>£</span><input name="free_over" inputmode="decimal" value="'
        . e($m['free_over'] === null ? '' : price_input($m['free_over'])) . '"></div></label>';
}

/* Read and check the price fields. Returns [values, error or null]. */
function method_price_post(string $pricing): array
{
    $pricing = isset(DELIVERY_PRICING[$pricing]) ? $pricing : 'flat';
    $price = post('price');
    $over = post('free_over');
    $vals = ['pricing' => $pricing, 'price' => $price === '' ? null : parse_price($price), 'free_over' => $over === '' ? null : parse_price($over), 'bands' => []];
    if ($price !== '' && $vals['price'] === null || $over !== '' && $vals['free_over'] === null) {
        return [$vals, 'Prices need to be numbers like 3.95.'];
    }
    if ($pricing === 'flat') {
        return [$vals, null];
    }
    $ups = is_array($_POST['band_up'] ?? null) ? $_POST['band_up'] : [];
    $prices = is_array($_POST['band_price'] ?? null) ? $_POST['band_price'] : [];
    $seen = [];
    foreach ($ups as $i => $up) {
        $up = is_string($up) ? trim($up) : '';
        $bp = is_string($prices[$i] ?? null) ? trim($prices[$i]) : '';
        if ($up === '' && $bp === '') {
            continue;
        }
        $pp = parse_price($bp);
        if ($pp === null) {
            return [$vals, 'Each band needs a price (0 for free).'];
        }
        if (in_array(strtolower($up), ['more', 'over', 'above', 'any', ''], true)) {
            $u = null;
        } else {
            $u = $pricing === 'weight' ? parse_weight($up) : parse_price($up);
            if ($u === null || $u <= 0) {
                return [$vals, $pricing === 'weight' ? '"' . $up . '" isn\'t a weight. Use kg, like 2 or 0.5.' : '"' . $up . '" isn\'t an amount, like 20.'];
            }
        }
        $k = $u ?? 'more';
        if (isset($seen[$k])) {
            return [$vals, 'Two bands have the same upper limit.'];
        }
        $seen[$k] = true;
        $vals['bands'][] = ['up_to' => $u, 'price' => $pp];
    }
    if (!$vals['bands']) {
        return [$vals, 'Add at least one band, like ' . ($pricing === 'weight' ? 'up to 2 (kg) for £4.50.' : 'under 20 (£) for £3.95.')];
    }
    return [$vals, null];
}

function method_bands_save(string $key, array $bands): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM delivery_bands WHERE method = ?')->execute([$key]);
    foreach ($bands as $b) {
        $pdo->prepare('INSERT INTO delivery_bands (method, up_to, price_pence) VALUES (?, ?, ?)')->execute([$key, $b['up_to'], $b['price']]);
    }
}

/* Handle a Delivery tab form. Returns the message to show, or null if it wasn't one of these. */
function delivery_admin_post(string $form): ?string
{
    $pdo = db();
    switch ($form) {
        case 'deliver_pricing':   // the shop's own delivery: its name and how it's priced
            [$vals, $err] = method_price_post(post('pricing'));
            if ($err) {
                return $err . ' Nothing changed.';
            }
            if ($vals['pricing'] === 'flat' && $vals['price'] === null && can_deliver()) {
                return 'Set a delivery price to offer delivery (0 for free delivery). Nothing changed.';
            }
            set_setting('postage_name', mb_substr(post('name'), 0, 60) === 'UK delivery' ? '' : mb_substr(post('name'), 0, 60));
            set_setting('postage_pricing', $vals['pricing'] === 'flat' ? '' : $vals['pricing']);
            set_setting('postage_price', $vals['price'] === null ? '' : (string)$vals['price']);
            set_setting('postage_free_over', $vals['free_over'] === null ? '' : (string)$vals['free_over']);
            method_bands_save('deliver', $vals['bands']);
            return 'Delivery saved.';
        case 'method':
            $id = (int)post('id');
            $name = mb_substr(post('name'), 0, 60);
            $kind = post('kind') === 'collect' ? 'collect' : 'deliver';
            [$vals, $err] = $kind === 'collect' ? [['pricing' => 'flat', 'price' => post('price') === '' ? 0 : parse_price(post('price')), 'free_over' => null, 'bands' => []], null]
                                                : method_price_post(post('pricing'));
            if ($name === '') {
                return 'Give the method a name customers will understand, like Next working day. Nothing changed.';
            }
            if ($err || $vals['price'] === null && $kind === 'collect') {
                return ($err ?? 'Prices need to be numbers like 3.95.') . ' Nothing changed.';
            }
            if ($kind === 'deliver' && $vals['pricing'] === 'flat' && $vals['price'] === null) {
                return 'Set a price for ' . $name . ' (0 for free). Nothing changed.';
            }
            $active = !empty($_POST['active']) ? 1 : 0;
            if ($id) {
                $pdo->prepare('UPDATE delivery_methods SET name = ?, kind = ?, pricing = ?, price_pence = ?, free_over_pence = ?, note = ?, active = ? WHERE id = ?')
                    ->execute([$name, $kind, $vals['pricing'], $vals['price'], $vals['free_over'], mb_substr(post('note'), 0, 500), $active, $id]);
            } else {
                $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM delivery_methods')->fetchColumn();
                $pdo->prepare('INSERT INTO delivery_methods (name, kind, pricing, price_pence, free_over_pence, note, active, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $kind, $vals['pricing'], $vals['price'], $vals['free_over'], mb_substr(post('note'), 0, 500), $active, $pos]);
                $id = (int)$pdo->lastInsertId();
            }
            method_bands_save('m' . $id, $vals['bands']);
            return $name . ' saved.' . ($active ? '' : ' It\'s switched off, so customers don\'t see it yet.');
        case 'method_delete':
            $id = (int)post('id');
            $pdo->prepare('DELETE FROM delivery_methods WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM delivery_bands WHERE method = ?')->execute(['m' . $id]);
            $pdo->prepare('DELETE FROM delivery_zone_rates WHERE method = ?')->execute(['m' . $id]);
            return 'Delivery method deleted. Past orders keep its name.';
        case 'method_move':
            $ids = $pdo->query('SELECT id FROM delivery_methods ORDER BY position, id')->fetchAll(PDO::FETCH_COLUMN);
            $i = array_search((int)post('id'), array_map('intval', $ids), true);
            $j = $i === false ? false : $i + (post('dir') === 'up' ? -1 : 1);
            if ($j !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                foreach ($ids as $pos => $mid) {
                    $pdo->prepare('UPDATE delivery_methods SET position = ? WHERE id = ?')->execute([$pos, $mid]);
                }
            }
            return 'Order changed.';
        case 'zone':
            $id = (int)post('id');
            $name = mb_substr(post('name'), 0, 60);
            $areas = strtoupper(trim(preg_replace('/[\s,]+/', ' ', mb_substr(post('areas'), 0, 2000))));
            if ($name === '' || $areas === '') {
                return 'A zone needs a name and at least one postcode area, like IV or PA20-49. Nothing changed.';
            }
            if ($bad = zone_areas_bad($areas)) {
                return 'These aren\'t postcode areas or districts: ' . implode(', ', $bad) . '. Use areas like IV, districts like PA20, or ranges like PA20-49. Nothing changed.';
            }
            $rates = [];
            foreach (delivery_methods(false) as $m) {
                if ($m['kind'] !== 'deliver') {
                    continue;
                }
                $x = str_in($_POST['extra'][$m['key']] ?? '');
                $xp = $x === '' ? 0 : parse_price($x);
                if ($xp === null) {
                    return 'Extra charges need to be numbers like 5.00. Nothing changed.';
                }
                $rates[$m['key']] = [!empty($_POST['avail'][$m['key']]) ? 1 : 0, $xp];
            }
            if ($id) {
                $pdo->prepare('UPDATE delivery_zones SET name = ?, areas = ? WHERE id = ?')->execute([$name, $areas, $id]);
            } else {
                $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM delivery_zones')->fetchColumn();
                $pdo->prepare('INSERT INTO delivery_zones (name, areas, position) VALUES (?, ?, ?)')->execute([$name, $areas, $pos]);
                $id = (int)$pdo->lastInsertId();
            }
            foreach ($rates as $key => [$avail, $extra]) {
                $pdo->prepare('INSERT OR REPLACE INTO delivery_zone_rates (method, zone_id, available, extra_pence) VALUES (?, ?, ?, ?)')
                    ->execute([$key, $id, $avail, $extra]);
            }
            return $name . ' saved.';
        case 'zone_delete':
            $id = (int)post('id');
            $pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM delivery_zone_rates WHERE zone_id = ?')->execute([$id]);
            return 'Zone deleted. Those postcodes now pay the usual prices.';
        case 'zone_presets':
            $have = array_map(fn($z) => mb_strtolower($z['name']), delivery_zones());
            $n = 0;
            foreach (ZONE_PRESETS as $name => $areas) {
                if (!in_array(mb_strtolower($name), $have, true)) {
                    $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM delivery_zones')->fetchColumn();
                    $pdo->prepare('INSERT INTO delivery_zones (name, areas, position) VALUES (?, ?, ?)')->execute([$name, $areas, $pos]);
                    $n++;
                }
            }
            return $n ? $n . ' zones added. They cost the same as everywhere else until you set an extra charge or switch a method off for them.'
                      : 'The usual UK zones are already there.';
    }
    return null;
}

/* The Delivery tab's sections below the postage form. */
function delivery_admin_html(): string
{
    $all = delivery_methods(false);
    $own = $all[0];
    $extra = array_values(array_filter($all, fn($m) => !$m['own']));
    $zones = delivery_zones();
    $script = '<script>document.querySelectorAll("form.method").forEach(function(f){var s=f.querySelector(".pricing");if(!s)return;'
        . 'function t(){var v=s.value;f.querySelector(".flat").hidden=v!=="flat";f.querySelector(".bands").hidden=v==="flat";'
        . 'f.querySelector(".by-weight").hidden=v!=="weight";f.querySelector(".by-value").hidden=v!=="value";}s.addEventListener("change",t);'
        . 'var k=f.querySelector("[name=kind]");if(k){function u(){f.querySelectorAll(".deliver-only").forEach(function(x){x.hidden=k.value==="collect";});'
        . 'f.querySelectorAll(".collect-only").forEach(function(x){x.hidden=k.value!=="collect";});}k.addEventListener("change",u);u();}});</script>';

    // The shop's own delivery: its name, and priced by weight or value.
    $out = '<details class="opts" id="pricing"' . ($own['pricing'] !== 'flat' || setting('postage_name') !== '' ? ' open' : '') . '><summary>'
        . e($own['name']) . ': name, and pricing by weight or order value</summary>'
        . '<form method="post" class="method">' . csrf_field() . '<input type="hidden" name="form" value="deliver_pricing">'
        . '<label>Name shown to customers <input name="name" maxlength="60" value="' . e($own['name']) . '"></label>'
        . method_price_fields($own) . '<button>Save</button></form></details>';

    // More methods.
    $list = '';
    foreach ($extra as $i => $m) {
        $list .= '<details class="opts method-item"><summary>' . e($m['name']) . ' <span class="muted">· ' . e(method_price_text($m)) . '</span>'
            . ($m['active'] ? '' : ' <span class="pill out">Off</span>') . '</summary>'
            . method_form_html($m)
            . '<div class="row-actions">'
            . ($i > 0 ? '<form method="post">' . csrf_field() . '<input type="hidden" name="form" value="method_move"><input type="hidden" name="id" value="' . $m['id'] . '"><input type="hidden" name="dir" value="up"><button class="link">Move up</button></form>' : '')
            . ($i < count($extra) - 1 ? '<form method="post">' . csrf_field() . '<input type="hidden" name="form" value="method_move"><input type="hidden" name="id" value="' . $m['id'] . '"><input type="hidden" name="dir" value="down"><button class="link">Move down</button></form>' : '')
            . '<form method="post" onsubmit="return confirm(\'Delete ' . e(addslashes($m['name'])) . '?\')">' . csrf_field() . '<input type="hidden" name="form" value="method_delete"><input type="hidden" name="id" value="' . $m['id'] . '"><button class="link danger">Delete</button></form>'
            . '</div></details>';
    }
    $out .= '<h2 id="methods">More ways to deliver</h2>'
        . '<p class="muted">For example next working day, a courier for big items, or a second collection point. Customers choose in their basket. '
        . 'Order of display: ' . e($own['name']) . ' first, these next, collection last.</p>'
        . $list
        . '<details class="opts"' . ($extra ? '' : '') . '><summary>Add a delivery method</summary>'
        . method_form_html(['key' => 'm0', 'id' => 0, 'name' => '', 'kind' => 'deliver', 'pricing' => 'flat', 'price' => null, 'free_over' => null, 'note' => '', 'active' => true, 'own' => false])
        . '</details>';

    // Zones.
    $deliverers = array_values(array_filter($all, fn($m) => $m['kind'] === 'deliver'));
    $zlist = '';
    foreach ($zones as $z) {
        $zlist .= '<details class="opts"><summary>' . e($z['name']) . ' <span class="muted">· ' . e($z['areas']) . '</span></summary>'
            . zone_form_html($z, $deliverers)
            . '<form method="post" class="row-actions" onsubmit="return confirm(\'Delete this zone?\')">' . csrf_field() . '<input type="hidden" name="form" value="zone_delete"><input type="hidden" name="id" value="' . (int)$z['id'] . '"><button class="link danger">Delete zone</button></form>'
            . '</details>';
    }
    $out .= '<h2 id="zones">Zones</h2>'
        . '<p class="muted">Charge more to some postcodes, or don\'t offer a method there. The checkout works out the zone from the delivery postcode, and shows the new price before the customer pays. '
        . 'Postcodes in no zone pay the usual prices.</p>'
        . $zlist
        . '<details class="opts"><summary>Add a zone</summary>' . zone_form_html(['id' => 0, 'name' => '', 'areas' => ''], $deliverers) . '</details>'
        . (count(array_intersect(array_map('mb_strtolower', array_keys(ZONE_PRESETS)), array_map(fn($z) => mb_strtolower($z['name']), $zones))) < count(ZONE_PRESETS)
            ? '<form method="post">' . csrf_field() . '<input type="hidden" name="form" value="zone_presets"><button class="secondary">Add the usual UK zones</button>'
              . ' <span class="hint">Highlands and Islands, Northern Ireland, Isles of Scilly, Channel Islands and Isle of Man</span></form>' : '');
    return $out . $script;
}

function method_form_html(array $m): string
{
    $new = !$m['id'];
    return '<form method="post" class="method">' . csrf_field() . '<input type="hidden" name="form" value="method"><input type="hidden" name="id" value="' . (int)$m['id'] . '">'
        . '<label>Name shown to customers <input name="name" maxlength="60" required placeholder="Next working day" value="' . e($m['name']) . '"></label>'
        . '<label>Kind <select name="kind"><option value="deliver"' . ($m['kind'] === 'deliver' ? ' selected' : '') . '>Delivery to the customer\'s address</option>'
        . '<option value="collect"' . ($m['kind'] === 'collect' ? ' selected' : '') . '>Collection</option></select></label>'
        . '<div class="deliver-only"' . ($m['kind'] === 'collect' ? ' hidden' : '') . '>' . method_price_fields($m) . '</div>'
        . '<div class="collect-only"' . ($m['kind'] === 'collect' ? '' : ' hidden') . '><label>Where and when <span class="hint">(shown to customers who choose it)</span>'
        . '<textarea name="note" maxlength="500" style="min-height:4rem">' . e($m['note']) . '</textarea></label></div>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . ($m['active'] ? ' checked' : '') . '> Offer it to customers</label>'
        . '<button>' . ($new ? 'Add method' : 'Save') . '</button></form>';
}

function zone_form_html(array $z, array $deliverers): string
{
    $grid = '';
    foreach ($deliverers as $m) {
        $r = $z['id'] ? zone_rate($m['key'], (int)$z['id']) : null;
        $grid .= '<div class="zonerow"><span>' . e($m['name']) . ($m['active'] ? '' : ' <span class="muted">(off)</span>') . '</span>'
            . '<label class="check"><input type="checkbox" name="avail[' . e($m['key']) . ']" value="1"' . (!$r || $r['available'] ? ' checked' : '') . '> Offered</label>'
            . '<div class="price"><span>+£</span><input name="extra[' . e($m['key']) . ']" inputmode="decimal" placeholder="0" aria-label="Extra charge for ' . e($m['name'])
            . '" value="' . e($r && $r['extra'] ? price_input($r['extra']) : '') . '"></div></div>';
    }
    return '<form method="post">' . csrf_field() . '<input type="hidden" name="form" value="zone"><input type="hidden" name="id" value="' . (int)$z['id'] . '">'
        . '<label>Zone name <input name="name" maxlength="60" required placeholder="Highlands and Islands" value="' . e($z['name']) . '"></label>'
        . '<label>Postcodes <span class="hint">(areas like IV, districts like PA20, ranges like PA20-49)</span>'
        . '<textarea name="areas" maxlength="2000" style="min-height:3rem" required>' . e($z['areas']) . '</textarea></label>'
        . '<p class="muted">For each delivery method: is it offered here, and any extra charge (added even when delivery is otherwise free).</p>'
        . $grid . '<button>' . ($z['id'] ? 'Save zone' : 'Add zone') . '</button></form>';
}

/* ================================================================== trade
   Trade accounts (1.3): a customer group can see prices without VAT, need a
   minimum order and pay its own ways (see pay_allowed). Customers can apply to
   join the group the shop picks; approving puts them in it in one click.
   Fixed prices per product live in group_prices (see group_unit_saving). */

const PAY_LABELS = ['paypal' => 'PayPal', 'bank' => 'Bank transfer', 'collection' => 'Pay on collection'];

/* Does this customer see prices without VAT? Only in a VAT-registered shop. */
function prices_ex_vat(): bool
{
    $g = customer_group();
    return $g && (int)($g['ex_vat'] ?? 0) === 1 && setting('vat_registered') === '1';
}

/* A price that includes VAT at 20%, without it. */
function ex_vat(int $pence): int
{
    return $pence - (int)round($pence / 6);
}

/* A price as this customer sees it: without VAT for a group that wants that. */
function shown_money(int $pence): string
{
    return money(prices_ex_vat() ? ex_vat($pence) : $pence);
}

function ex_vat_tag(): string
{
    return prices_ex_vat() ? ' <small class="shop-exvat">ex VAT</small>' : '';
}

/* How much more this basket needs to reach the group's minimum order, or null if it's fine. */
function min_order_short(?array $t): ?int
{
    $g = customer_group();
    if (!$g || $g['min_order_pence'] === null || !$t) {
        return null;
    }
    $goods = $t['subtotal'] - $t['discount'] - $t['group_pence'];
    $short = (int)$g['min_order_pence'] - $goods;
    return $short > 0 ? $short : null;
}

/* The group customers can apply to join, or null if applications are off. */
function trade_group(): ?array
{
    $id = (int)setting('trade_apply_group');
    return $id ? group_by_id($id) : null;
}

function trade_application_of(int $cid): ?array
{
    $st = db()->prepare('SELECT * FROM trade_applications WHERE customer_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

/* The account page's line about trade accounts. */
function trade_account_link(array $c): string
{
    $g = trade_group();
    if (!$g || $c['group_id'] !== null) {
        return '';
    }
    $a = trade_application_of((int)$c['id']);
    if ($a && $a['status'] === 'new') {
        return '<p class="shop-note">Thanks for applying for a ' . e($g['name']) . ' account. We\'ll email you once we\'ve looked at it.</p>';
    }
    return '<p><a href="' . e(shop_url('account/trade')) . '">Apply for a ' . e($g['name']) . ' account</a> <span class="shop-hint">for businesses</span></p>';
}

function view_account_trade(): never
{
    $c = require_customer('account/trade');
    $g = trade_group();
    if (!$g || $c['group_id'] !== null) {
        redirect(shop_url('account'));
    }
    $a = trade_application_of((int)$c['id']);
    $v = ['business' => '', 'vat_number' => '', 'phone' => $c['phone'], 'note' => ''];
    $errors = [];
    if (is_post() && !($a && $a['status'] === 'new')) {
        csrf_check();
        foreach ($v as $k => $_) {
            $v[$k] = mb_substr(post($k), 0, $k === 'note' ? 1000 : 120);
        }
        $vat = strtoupper(preg_replace('/[\s.-]/', '', $v['vat_number']));
        if ($v['business'] === '') {
            $errors[] = 'Enter your business name.';
        }
        if ($vat !== '' && !preg_match('/^(GB|XI)?(\d{9}|\d{12})$/', $vat)) {
            $errors[] = 'That doesn\'t look like a UK VAT number (like GB123456789). Leave it empty if you\'re not registered.';
        }
        if ($c['verified_at'] === '') {
            $errors[] = 'Please confirm your email address first: we\'ve sent you a link.';
        }
        if (!$errors && throttled('trade:' . $c['id'], 3, 86400)) {
            $errors[] = 'You\'ve applied a few times today. Please get in touch with the shop instead.';
        }
        if (!$errors) {
            throttle_hit('trade:' . $c['id']);
            db()->prepare('INSERT INTO trade_applications (customer_id, group_id, business, vat_number, phone, note, created) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$c['id'], $g['id'], $v['business'], $vat === '' ? '' : 'GB' . preg_replace('/^(GB|XI)/', '', $vat), $v['phone'], $v['note'], now()]);
            trade_notify_shop($c, $g, $v);
            flash('Thanks, your application has been sent. We\'ll email you once we\'ve looked at it.');
            redirect(shop_url('account'));
        }
    }
    $field = fn(string $k, string $label, string $attrs = '', string $hint = '') => '<label>' . $label . ($hint !== '' ? ' <span class="shop-hint">' . $hint . '</span>' : '')
        . '<input name="' . $k . '" value="' . e($v[$k]) . '" ' . $attrs . '></label>';
    account_page('Apply for a ' . $g['name'] . ' account',
        ($errors ? '<div class="shop-note shop-errors" role="alert"><strong>Please check:</strong><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '')
        . ($a && $a['status'] === 'new' ? '<p class="shop-note">You\'ve already applied. We\'ll email you once we\'ve looked at it.</p>'
        : '<p>For businesses buying from us. Once we\'ve approved it, you\'ll see your ' . e($g['name']) . ' prices whenever you\'re signed in.</p>'
            . '<form class="shop-form" method="post">' . csrf_field()
            . $field('business', 'Business name', 'required maxlength="120" autocomplete="organization"')
            . $field('vat_number', 'VAT number', 'maxlength="20" autocapitalize="characters"', '(if you\'re registered)')
            . $field('phone', 'Phone', 'type="tel" maxlength="40" autocomplete="tel"', '(optional)')
            . '<label>Anything we should know <span class="shop-hint">(optional: what you sell, your website)</span><textarea name="note" maxlength="1000" rows="4">' . e($v['note']) . '</textarea></label>'
            . '<button class="shop-button">Send application</button></form>'),
        [['Your account', 'account'], ['Trade account', null]]);
}

function trade_notify_shop(array $c, array $g, array $v): void
{
    $to = setting('mail_notify') !== '' ? setting('mail_notify') : setting('business_email');
    if ($to === '' || !mail_ready()) {
        return;
    }
    [$foot, $footText] = mail_footer();
    $url = abs_shop_url('admin/trade');
    $paras = [e($c['name'] ?: $c['email']) . ' (' . e($c['email']) . ') has applied for a ' . e($g['name']) . ' account.',
              '<strong>' . e($v['business']) . '</strong>' . ($v['vat_number'] !== '' ? ', VAT number ' . e($v['vat_number']) : '') . ($v['phone'] !== '' ? ', phone ' . e($v['phone']) : '')
              . ($v['note'] !== '' ? '<br>' . nl2br(e($v['note'])) : '')];
    $html = mail_layout('New trade application', $paras, '', $url, 'Look at it', $foot);
    $text = "New trade application\n\n" . html_entity_decode(strip_tags(implode("\n\n", $paras)), ENT_QUOTES, 'UTF-8') . "\n\nLook at it: $url\n\n" . $footText . "\n";
    mail_queue(null, 'trade_application', $to, 'Trade application from ' . $v['business'], $text, $html);
}

function trade_tell_customer(array $c, array $g, bool $yes): void
{
    if (!mail_ready()) {
        return;
    }
    [$foot, $footText] = mail_footer();
    $name = $c['name'] !== '' ? $c['name'] : 'there';
    $heading = $yes ? 'Your ' . $g['name'] . ' account is ready' : 'Your trade application';
    $paras = $yes ? ['Hello ' . e($name) . ', we\'ve approved your application. You\'ll see your ' . e($g['name']) . ' prices whenever you\'re signed in to your account.']
                  : ['Hello ' . e($name) . ', thanks for applying for a ' . e($g['name']) . ' account. We\'re not able to offer you one at the moment, but you\'re very welcome to keep shopping with your account as it is.'];
    $html = mail_layout(e($heading), $paras, '', $yes ? abs_shop_url() : '', $yes ? 'Go to the shop' : '', $foot);
    $text = $heading . "\n\n" . html_entity_decode(strip_tags(implode("\n\n", $paras)), ENT_QUOTES, 'UTF-8') . "\n\n" . $footText . "\n";
    mail_queue(null, $yes ? 'trade_approved' : 'trade_declined', $c['email'], $heading, $text, $html);
}

/* Customers → Trade applications. */
function view_admin_trade(): never
{
    require_admin();
    if (is_post()) {
        csrf_check();
        $st = db()->prepare("SELECT * FROM trade_applications WHERE id = ? AND status = 'new'");
        $st->execute([(int)post('id')]);
        $a = $st->fetch();
        $c = $a ? customer_by_id((int)$a['customer_id']) : null;
        $g = $a ? group_by_id((int)$a['group_id']) : null;
        if (!$a || !$c || !$g) {
            flash('That application has already been dealt with, or its customer or group has gone.');
        } elseif (post('do') === 'approve') {
            db()->prepare('UPDATE customers SET group_id = ? WHERE id = ?')->execute([$g['id'], $c['id']]);
            db()->prepare("UPDATE trade_applications SET status = 'approved', decided = ? WHERE id = ?")->execute([now(), $a['id']]);
            trade_tell_customer($c, $g, true);
            flash(($c['name'] ?: $c['email']) . ' is now in ' . $g['name'] . '. We\'ve emailed to let them know.');
        } elseif (post('do') === 'decline') {
            db()->prepare("UPDATE trade_applications SET status = 'declined', decided = ? WHERE id = ?")->execute([now(), $a['id']]);
            if (!empty($_POST['tell'])) {
                trade_tell_customer($c, $g, false);
            }
            flash('Application from ' . $a['business'] . ' declined.');
        }
        mail_send_later();
        redirect(admin_url('trade'));
    }
    $g = trade_group();
    $rows = db()->query("SELECT a.*, c.name, c.email, c.verified_at, g.name AS gname FROM trade_applications a
                         LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN customer_groups g ON g.id = a.group_id
                         ORDER BY a.status = 'new' DESC, a.id DESC LIMIT 200")->fetchAll();
    $list = '';
    foreach ($rows as $a) {
        $new = $a['status'] === 'new';
        $list .= '<li><div class="pname"><strong>' . e($a['business']) . '</strong>'
            . '<small>' . ($a['name'] !== null ? '<a href="' . e(admin_url('customer/' . $a['customer_id'])) . '">' . e($a['name'] ?: $a['email']) . '</a> · ' . e($a['email']) : 'Customer deleted')
            . ($a['vat_number'] !== '' ? ' · VAT ' . e($a['vat_number']) : '') . ($a['phone'] !== '' ? ' · ' . e($a['phone']) : '') . '</small>'
            . ($a['note'] !== '' ? '<small>' . nl2br(e($a['note'])) . '</small>' : '')
            . '<small class="muted">For ' . e($a['gname'] ?? 'a deleted group') . ' · ' . e(gmdate('j M Y', strtotime($a['created'] . ' UTC'))) . '</small></div>'
            . ($new && $a['name'] !== null
                ? '<div class="row-actions"><form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><button name="do" value="approve">Approve</button></form>'
                  . '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><label class="check" style="margin:0 .6rem 0 0"><input type="checkbox" name="tell" value="1" checked> Email them</label><button class="quiet" name="do" value="decline">Decline</button></form></div>'
                : '<span class="pill' . ($a['status'] === 'approved' ? ' ok' : '') . '">' . ($a['status'] === 'approved' ? 'Approved' : ($new ? 'New' : 'Declined')) . '</span>')
            . '</li>';
    }
    admin_shell('Trade applications', '<h1>Trade applications</h1>'
        . ($g ? '<p class="lede">Customers apply from their account. Approving puts them in <a href="' . e(admin_url('group/' . $g['id'])) . '">' . e($g['name']) . '</a> and emails them.</p>'
              : '<p class="flash">Applications are switched off. To take them, tick "Customers can apply to join" on a group in Groups.</p>')
        . ($list !== '' ? '<ul class="plist">' . $list . '</ul>' : '<p class="muted">No applications yet.</p>'));
}

function trade_new_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM trade_applications WHERE status = 'new'")->fetchColumn();
}

/* ------------------------------------------------- group prices on a product */

/* The product form's Group prices section: a fixed price per group, for the product
   and (for a product with choices) for each choice that has a price of its own. */
function group_prices_html(?array $p, array $v): string
{
    $groups = all_groups();
    if (!$groups || !$p) {
        return $groups ? '<p class="muted">Fixed group prices can be set once the product is saved.</p>' : '';
    }
    $have = [];
    $st = db()->prepare('SELECT group_id, variant_id, price_pence FROM group_prices WHERE product_id = ?');
    $st->execute([$p['id']]);
    foreach ($st->fetchAll() as $r) {
        $have[$r['group_id']][$r['variant_id']] = price_input((int)$r['price_pence']);
    }
    $posted = $v['gp'] ?? null;
    $val = fn(int $gid, int $vid) => is_array($posted) ? (string)($posted[$gid][$vid] ?? '') : ($have[$gid][$vid] ?? '');
    $vars = array_values(array_filter(product_variants((int)$p['id']), fn($x) => $x['price_pence'] !== null));
    $rows = [[0, $vars ? 'Choices at the product\'s price' : 'This product', (int)$p['price_pence']]];
    foreach ($vars as $x) {
        $rows[] = [(int)$x['id'], choice_name($x), (int)$x['price_pence']];
    }
    $head = '<tr><th></th>' . implode('', array_map(fn($g) => '<th>' . e($g['name']) . '</th>', $groups)) . '</tr>';
    $body = '';
    foreach ($rows as [$vid, $label, $full]) {
        $body .= '<tr><td>' . e($label) . ' <span class="muted">' . money($full) . '</span></td>';
        foreach ($groups as $g) {
            $body .= '<td><div class="price" style="max-width:8rem"><span>£</span><input name="gp[' . (int)$g['id'] . '][' . $vid . ']" inputmode="decimal" value="'
                   . e($val((int)$g['id'], $vid)) . '" placeholder="' . e(price_input($full - (int)round($full * group_rate(group_by_id((int)$g['id']), $p) / 100))) . '"'
                   . ' aria-label="' . e($g['name'] . ' price for ' . $label) . '"></div></td>';
        }
        $body .= '</tr>';
    }
    $any = (bool)array_filter($have) || is_array($posted) && array_filter(array_map('array_filter', $posted));
    return '<details class="opts" id="gprices"' . ($any ? ' open' : '') . '><summary>Group prices <span class="hint">(optional: a fixed price for a customer group)</span></summary>'
        . '<p class="muted">Leave empty to use the group\'s discount (shown faded). A fixed price wins where it\'s set. Sale prices and codes still never add up with it: the customer gets whichever is cheapest.'
        . ($vars ? ' A new choice with its own price can have group prices once it\'s saved.' : '') . '</p>'
        . '<div class="gptable"><table>' . $head . $body . '</table></div></details>';
}

/* Read the Group prices boxes. Null if the form had none (leave them as they are). */
function group_prices_read(array &$errors): ?array
{
    if (!is_array($_POST['gp'] ?? null)) {
        return null;
    }
    $out = [];
    foreach ($_POST['gp'] as $gid => $byVar) {
        if (!is_array($byVar) || !group_by_id((int)$gid)) {
            continue;
        }
        foreach ($byVar as $vid => $x) {
            $x = is_string($x) ? trim($x) : '';
            if ($x === '') {
                continue;
            }
            $pp = parse_price($x);
            if ($pp === null) {
                $errors[] = 'Group price "' . $x . '" isn\'t a price, like 8.50.';
                continue;
            }
            $out[(int)$gid][(int)$vid] = $pp;
        }
    }
    return $out;
}

function group_prices_save(int $pid, ?array $in): void
{
    $pdo = db();
    $live = array_map(fn($x) => (int)$x['id'], product_variants($pid));
    if ($in !== null) {
        $pdo->prepare('DELETE FROM group_prices WHERE product_id = ?')->execute([$pid]);
        foreach ($in as $gid => $byVar) {
            foreach ($byVar as $vid => $pp) {
                if ($vid === 0 || in_array($vid, $live, true)) {
                    $pdo->prepare('INSERT INTO group_prices (group_id, product_id, variant_id, price_pence) VALUES (?, ?, ?, ?)')->execute([$gid, $pid, $vid, $pp]);
                }
            }
        }
    }
    // Choices that have gone take their group prices with them.
    $pdo->prepare('DELETE FROM group_prices WHERE product_id = ? AND variant_id != 0' . ($live ? ' AND variant_id NOT IN (' . implode(',', $live) . ')' : ''))->execute([$pid]);
}

/* ========================================================== photo import
   Photos for many products at once (1.3): a zip (or the photos themselves)
   named by SKU or web address, or photo web addresses in the products CSV.
   Either way the work goes in an import job and is done a batch at a time, so a
   big import never hits the host's time limit; the progress page can be left
   and come back to. Each photo records where it came from, so running the same
   import again adds nothing twice. */

const PHOTO_BATCH_SECONDS = 8;
const PHOTO_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

/* SKUs (products' and choices') and web addresses, lowercased => product id. */
function photo_name_index(): array
{
    $idx = ['sku' => [], 'slug' => []];
    foreach (db()->query('SELECT id, sku, slug FROM products') as $p) {
        if ($p['sku'] !== '') {
            $idx['sku'][mb_strtolower($p['sku'])] = (int)$p['id'];
        }
        $idx['slug'][mb_strtolower($p['slug'])] = (int)$p['id'];
    }
    foreach (db()->query("SELECT product_id, sku FROM variants WHERE sku != ''") as $v) {
        $idx['sku'][mb_strtolower($v['sku'])] ??= (int)$v['product_id'];
    }
    return $idx;
}

/* Which product a file is for, and its place: "804501.jpg" => [id, 1], "804501-2.jpg" => [id, 2].
   Null if nothing matches. A name that is itself a SKU wins over reading "-2" as a number. */
function photo_match(string $file, array $idx): ?array
{
    $base = mb_strtolower(pathinfo(str_replace('\\', '/', $file), PATHINFO_FILENAME));
    $find = function (string $b) use ($idx): ?int {
        foreach ([$b, str_replace('_', '/', $b)] as $k) {   // a SKU's / can't be in a file name
            if (isset($idx['sku'][$k])) {
                return $idx['sku'][$k];
            }
        }
        return $idx['slug'][$b] ?? null;
    };
    if (($id = $find($base)) !== null) {
        return [$id, 1];
    }
    if (preg_match('/^(.+?)(?:[-_ ]|\s?\()(\d{1,2})\)?$/', $base, $m) && ($id = $find($m[1])) !== null) {
        return [$id, max(1, (int)$m[2])];
    }
    return null;
}

/* The photo sources a product already has, so nothing is added twice. */
function photo_sources(int $pid): array
{
    $st = db()->prepare("SELECT source FROM photos WHERE product_id = ? AND source != ''");
    $st->execute([$pid]);
    return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

function photo_import_dir(string $token): string
{
    return data_dir() . '/photos-' . $token;
}

/* Remove a job's working folder (the uploaded zip or photos). */
function photo_dir_remove(string $dir): void
{
    if ($dir === '' || !is_dir($dir) || !str_starts_with(basename($dir), 'photos-')) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

/* Look at an upload of a zip and/or photos. Returns the plan: items to add, files that
   match nothing, and files already on their product. */
function photo_upload_check(string $dir, array &$errors): array
{
    $plan = ['items' => [], 'unmatched' => [], 'already' => [], 'files' => 0];
    $f = $_FILES['photos'] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) {
        $errors[] = 'Choose a zip of photos, or the photos themselves.';
        return $plan;
    }
    @mkdir($dir, 0700, true);
    $idx = photo_name_index();
    $have = [];
    $consider = function (string $name, string $src, string $entry, string $source) use (&$plan, $idx, &$have) {
        $plan['files']++;
        $m = photo_match($name, $idx);
        if (!$m) {
            $plan['unmatched'][] = $name;
            return;
        }
        $have[$m[0]] ??= photo_sources($m[0]);
        if (isset($have[$m[0]][$source])) {
            $plan['already'][] = $name;
            return;
        }
        $have[$m[0]][$source] = true;
        $plan['items'][] = ['pid' => $m[0], 'pos' => $m[1], 'name' => $name, 'src' => $src, 'entry' => $entry, 'source' => $source];
    };
    foreach ($f['name'] as $i => $name) {
        $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $tmp = (string)$f['tmp_name'][$i];
        $name = basename(str_replace('\\', '/', (string)$name));
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
            $errors[] = $name . ($err === UPLOAD_ERR_INI_SIZE ? ' is bigger than this server accepts. ' . upload_limit_text() : ' didn\'t upload properly.');
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                $errors[] = 'This server can\'t open zip files. Choose the photos themselves instead.';
                continue;
            }
            $keep = $dir . '/upload-' . $i . '.zip';
            move_uploaded_file($tmp, $keep) || rename($tmp, $keep);
            $z = new ZipArchive();
            if ($z->open($keep) !== true) {
                $errors[] = $name . ' isn\'t a zip the shop can open.';
                continue;
            }
            for ($k = 0; $k < $z->numFiles; $k++) {
                $st = $z->statIndex($k);
                $en = (string)$st['name'];
                $bn = basename($en);
                if (str_ends_with($en, '/') || str_contains($en, '__MACOSX') || $bn === '' || $bn[0] === '.'
                    || !in_array(strtolower(pathinfo($bn, PATHINFO_EXTENSION)), PHOTO_EXTS, true)) {
                    continue;
                }
                $consider($bn, basename($keep), $en, 'crc:' . sprintf('%08x', $st['crc'] & 0xffffffff) . ':' . $st['size']);
            }
            $z->close();
        } elseif (in_array($ext, PHOTO_EXTS, true)) {
            $keep = $dir . '/f' . $i . '.' . $ext;
            move_uploaded_file($tmp, $keep) || rename($tmp, $keep);
            $consider($name, basename($keep), '', 'crc:' . hash_file('crc32b', $keep) . ':' . filesize($keep));
        } else {
            $errors[] = $name . ' isn\'t a zip or a photo (JPEG, PNG, WebP).';
        }
    }
    // Each product's photos in their numbered order.
    usort($plan['items'], fn($a, $b) => [$a['pid'], $a['pos'], $a['name']] <=> [$b['pid'], $b['pos'], $b['name']]);
    return $plan;
}

/* Start a job. Returns its id. */
function photo_job_create(string $kind, string $label, array $items, bool $replace, string $dir = ''): int
{
    db()->prepare('INSERT INTO import_jobs (kind, label, items, replace_existing, dir, created) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$kind, $label, json_encode(array_values($items)), $replace ? 1 : 0, $dir, now()]);
    return (int)db()->lastInsertId();
}

function photo_job(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM import_jobs WHERE id = ?');
    $st->execute([$id]);
    $j = $st->fetch();
    if (!$j) {
        return null;
    }
    $j['items'] = json_decode($j['items'], true) ?: [];
    $j['errors'] = json_decode($j['errors'], true) ?: [];
    return $j;
}

/* Fetch a photo from the web into a temporary file. Only http(s), only public
   addresses, at most 15 MB. Returns the file, or null and why. */
function photo_download(string $url, ?string &$err): ?string
{
    $u = parse_url($url);
    if (!$u || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || empty($u['host'])) {
        $err = 'isn\'t a web address';
        return null;
    }
    $host = $u['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        $err = 'couldn\'t be found (' . $host . ')';
        return null;
    }
    $testing = getenv('WRENSHOP_TESTING') === '1';
    foreach ($ips as $ip) {
        if (!$testing && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $err = 'is on a private network, so the shop won\'t fetch it';
            return null;
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'wsp');
    $fh = fopen($tmp, 'wb');
    $port = (int)($u['port'] ?? (strtolower($u['scheme']) === 'https' ? 443 : 80));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],   // the address that was checked, not a second lookup
        CURLOPT_USERAGENT => 'WrenShop/' . SHOP_VERSION . ' (photo import)',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($c, $dlTotal, $dl) => $dl > UPLOAD_MAX ? 1 : 0,
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
    }
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $cerr = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    static $hops = 0;
    if ($code >= 300 && $code < 400 && $loc !== '' && $hops < 3) {   // follow a redirect, checking where it goes
        @unlink($tmp);
        $hops++;
        $r = photo_download($loc, $err);
        $hops--;
        return $r;
    }
    if (!$ok || $code !== 200) {
        @unlink($tmp);
        $err = $ok ? 'gave ' . $code . ' (not found or not allowed)' : (str_contains($cerr, 'callback') ? 'is over 15 MB' : 'couldn\'t be fetched (' . $cerr . ')');
        return null;
    }
    return $tmp;
}

/* Do as much of a job as fits in a few seconds. */
function photo_job_step(int $id): void
{
    $j = photo_job($id);
    if (!$j || $j['finished'] !== '') {
        return;
    }
    @set_time_limit(60);
    $start = microtime(true);
    $pdo = db();
    $zips = [];
    $i = (int)$j['done'];
    $n = count($j['items']);
    // The test suite shortens batches to see an import carried over several of them.
    $budget = getenv('WRENSHOP_TESTING') === '1' && setting('test_photo_batch') !== '' ? (float)setting('test_photo_batch') : PHOTO_BATCH_SECONDS;
    while ($i < $n && microtime(true) - $start < $budget) {
        $it = $j['items'][$i];
        $i++;
        $p = product_by_id((int)$it['pid']);
        if (!$p) {
            $j['skipped']++;
            continue;
        }
        // Replacing: the product's old photos go before its first new one.
        if ((int)$j['replace_existing'] && ($it['first'] ?? false)) {
            foreach (product_photos((int)$p['id']) as $ph) {
                if ($ph['source'] !== $it['source']) {
                    photo_delete_files($ph);
                    $pdo->prepare('DELETE FROM photos WHERE id = ?')->execute([$ph['id']]);
                }
            }
        }
        if (isset(photo_sources((int)$p['id'])[$it['source']])) {
            $j['skipped']++;
            continue;
        }
        $file = null;
        $err = null;
        $temp = false;
        if ($j['kind'] === 'urls') {
            $file = photo_download($it['url'], $err);
            $temp = true;
            $err = $err !== null ? $it['url'] . ' ' . $err : null;
        } elseif ($it['entry'] !== '') {
            $zp = $j['dir'] . '/' . basename($it['src']);
            if (!isset($zips[$zp])) {
                $zips[$zp] = new ZipArchive();
                if ($zips[$zp]->open($zp) !== true) {
                    $zips[$zp] = null;
                }
            }
            $data = $zips[$zp] ? $zips[$zp]->getFromName($it['entry'], UPLOAD_MAX + 1) : false;
            if ($data === false || strlen($data) > UPLOAD_MAX) {
                $err = $it['name'] . ($data === false ? ' couldn\'t be read from the zip' : ' is over 15 MB');
            } else {
                $file = tempnam(sys_get_temp_dir(), 'wsp');
                file_put_contents($file, $data);
                $temp = true;
            }
        } else {
            $file = $j['dir'] . '/' . basename($it['src']);
            if (!is_file($file)) {
                $err = $it['name'] . ' has gone from the server';
                $file = null;
            }
        }
        if ($file !== null) {
            $stored = photo_store(['name' => $it['name'] ?? basename((string)($it['url'] ?? 'photo')), 'tmp' => $file, 'error' => UPLOAD_ERR_OK,
                                   'size' => (int)filesize($file)], $e2, true);
            if ($temp) {
                @unlink($file);
            }
            if ($stored) {
                $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM photos WHERE product_id = ' . (int)$p['id'])->fetchColumn();
                $pdo->prepare('INSERT INTO photos (product_id, file, thumb, position, source) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$p['id'], $stored[0], $stored[1], $pos, $it['source']]);
                $j['added']++;
                if (!empty($it['show']) && !(int)$p['visible']) {   // the import asked for it to be shown once it had a photo
                    $pdo->prepare('UPDATE products SET visible = 1 WHERE id = ?')->execute([$p['id']]);
                }
                continue;
            }
            $err = $e2;
        }
        $j['errors'][] = $p['name'] . ': ' . $err;
    }
    foreach ($zips as $z) {
        $z?->close();
    }
    $done = $i >= $n;
    $pdo->prepare('UPDATE import_jobs SET done = ?, added = ?, skipped = ?, errors = ?, finished = ? WHERE id = ?')
        ->execute([$i, $j['added'], $j['skipped'], json_encode(array_slice($j['errors'], -500)), $done ? now() : '', $id]);
    if ($done) {
        photo_dir_remove($j['dir']);
    }
}

/* Mark each product's first item, so replacing clears its old photos just once. */
function photo_items_mark_first(array $items): array
{
    $seen = [];
    foreach ($items as $k => $it) {
        $items[$k]['first'] = !isset($seen[$it['pid']]);
        $seen[$it['pid']] = true;
    }
    return $items;
}

/* The import page's Photos section. */
function photo_import_section(): string
{
    $jobs = db()->query("SELECT * FROM import_jobs WHERE finished = '' ORDER BY id")->fetchAll();
    $open = '';
    foreach ($jobs as $j) {
        $n = count(json_decode($j['items'], true) ?: []);
        $open .= '<p class="flash">' . e($j['label']) . ': ' . (int)$j['done'] . ' of ' . $n . ' done. <a href="' . e(admin_url('import/job/' . $j['id'])) . '">Carry on</a></p>';
    }
    return '<h2 id="photos">Photos</h2>' . $open
        . '<p class="muted">Name each photo after its product\'s SKU or web address: <code>804501.jpg</code>, then <code>804501-2.jpg</code> for a second. '
        . 'Put them in a zip, or choose the photos themselves. You\'ll see which photo goes on which product before anything is added. '
        . 'Photos are added after a product\'s own, and a photo already added is never added twice.</p>'
        . '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="photos">'
        . '<label>Zip or photos <input type="file" name="photos[]" accept=".zip,application/zip,image/jpeg,image/png,image/webp" multiple required></label>'
        . '<label class="check"><input type="checkbox" name="replace" value="1"> Replace each product\'s existing photos</label>'
        . '<button>Check these photos</button></form>'
        . '<p class="muted">This server accepts up to ' . e((string)ini_get('upload_max_filesize')) . ' a file and ' . e((string)ini_get('max_file_uploads')) . ' files at once, '
        . 'so for lots of photos use a zip (or several). Photo web addresses can go in a <code>photos</code> column of the products CSV instead.</p>';
}

/* The check before photos are added. */
function photo_plan_html(array $plan, string $token, bool $replace): string
{
    $names = [];
    $per = [];
    foreach ($plan['items'] as $it) {
        $per[$it['pid']][] = $it['name'];
    }
    $rows = '';
    foreach (array_slice($per, 0, 300, true) as $pid => $files) {
        $p = product_by_id((int)$pid);
        $rows .= '<tr><td>' . e($p['name']) . ($p['sku'] !== '' ? ' <span class="muted">' . e($p['sku']) . '</span>' : '') . '</td><td>' . e(implode(', ', $files)) . '</td></tr>';
    }
    $n = count($plan['items']);
    return '<div class="report"><p class="lede">Checked ' . $plan['files'] . ' photo' . ($plan['files'] === 1 ? '' : 's') . '. Nothing has been added yet.</p>'
        . ($n ? '<p class="flash"><b>' . $n . ' photo' . ($n === 1 ? '' : 's') . ' for ' . count($per) . ' product' . (count($per) === 1 ? '' : 's') . '.</b>'
            . ($replace ? ' Their existing photos will be replaced.' : '') . '</p>'
            . '<table class="cols"><tr><th>Product</th><th>Photos, in order</th></tr>' . $rows . '</table>'
            . (count($per) > 300 ? '<p class="muted">…and ' . (count($per) - 300) . ' more products.</p>' : '') : '<p class="flash">No photos to add.</p>')
        . ($plan['unmatched'] ? '<div class="err"><b>' . count($plan['unmatched']) . ' match no product</b> (no SKU or web address with that name), so they\'ll be left out: '
            . e(implode(', ', array_slice($plan['unmatched'], 0, 100))) . (count($plan['unmatched']) > 100 ? '…' : '') . '</div>' : '')
        . ($plan['already'] ? '<p class="muted">' . count($plan['already']) . ' already added before, so left out: ' . e(implode(', ', array_slice($plan['already'], 0, 50))) . '</p>' : '')
        . ($n ? '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="photos_apply"><input type="hidden" name="token" value="' . e($token) . '">'
            . '<button>Add ' . $n . ' photo' . ($n === 1 ? '' : 's') . '</button> <a href="' . e(admin_url('import')) . '">Cancel</a></form>'
            : '<p><a href="' . e(admin_url('import')) . '">Back</a></p>')
        . '</div>';
}

/* Handle the Photos forms on the import page. Returns the page to show, or null. */
function photo_import_post(): ?string
{
    if (post('do') === 'photos') {
        $token = bin2hex(random_bytes(12));
        $dir = photo_import_dir($token);
        $errors = [];
        $plan = photo_upload_check($dir, $errors);
        if ($errors && !$plan['files']) {
            photo_dir_remove($dir);
            flash(implode(' ', $errors));
            redirect(admin_url('import') . '#photos');
        }
        $replace = !empty($_POST['replace']);
        file_put_contents($dir . '/plan.json', json_encode(['plan' => $plan, 'replace' => $replace]));
        $_SESSION['shop_photos'] = $token;
        return ($errors ? '<div class="err">' . implode('<br>', array_map('e', $errors)) . '</div>' : '') . photo_plan_html($plan, $token, $replace);
    }
    if (post('do') === 'photos_apply') {
        $token = (string)($_SESSION['shop_photos'] ?? '');
        $dir = photo_import_dir($token);
        if ($token === '' || !hash_equals($token, post('token')) || !is_file($dir . '/plan.json')) {
            flash('That check has expired. Please choose the photos again.');
            redirect(admin_url('import') . '#photos');
        }
        unset($_SESSION['shop_photos']);
        $saved = json_decode((string)file_get_contents($dir . '/plan.json'), true);
        $items = photo_items_mark_first($saved['plan']['items']);
        $id = photo_job_create('zip', count($items) . ' photo' . (count($items) === 1 ? '' : 's') . ' from ' . date('j M H:i'), $items, (bool)$saved['replace'], $dir);
        redirect(admin_url('import/job/' . $id));
    }
    return null;
}

/* A job's progress page. Each visit by POST does a batch; the page carries on by itself. */
function view_photo_job(int $id): never
{
    require_admin();
    $j = photo_job($id);
    if (!$j) {
        redirect(admin_url('import'));
    }
    if (is_post()) {
        csrf_check();
        if (post('do') === 'stop' && $j['finished'] === '') {
            db()->prepare('UPDATE import_jobs SET finished = ? WHERE id = ?')->execute([now(), $id]);
            photo_dir_remove($j['dir']);
            flash('Stopped. The photos added so far are kept.');
        } else {
            photo_job_step($id);
        }
        redirect(admin_url('import/job/' . $id));
    }
    $n = count($j['items']);
    $pct = $n ? (int)floor($j['done'] * 100 / $n) : 100;
    $running = $j['finished'] === '';
    $errs = $j['errors'] ? '<div class="err"><b>' . count($j['errors']) . ' couldn\'t be added:</b><ul>'
        . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', array_slice($j['errors'], 0, 200))) . '</ul></div>' : '';
    admin_shell('Photo import', '<p class="muted"><a href="' . e(admin_url('import')) . '">&larr; Import and export</a></p>'
        . '<h1>' . ($running ? 'Adding photos' : 'Photos added') . '</h1>'
        . '<p class="lede">' . e($j['label']) . '</p>'
        . '<div class="progress" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><span style="width:' . $pct . '%"></span></div>'
        . '<p><b>' . (int)$j['done'] . ' of ' . $n . '</b> done: ' . (int)$j['added'] . ' added'
        . ($j['skipped'] ? ', ' . (int)$j['skipped'] . ' already there' : '') . ($j['errors'] ? ', ' . count($j['errors']) . ' couldn\'t be added' : '') . '.</p>'
        . ($running ? '<form method="post" id="go">' . csrf_field() . '<button>Carry on</button></form>'
            . '<p class="muted">This page carries on by itself. You can leave it and come back: the import waits where it got to (Products → Import and export).</p>'
            . '<form method="post" onsubmit="return confirm(\'Stop adding photos?\')">' . csrf_field() . '<input type="hidden" name="do" value="stop"><button class="quiet small">Stop</button></form>'
            . '<script>setTimeout(function(){var f=document.getElementById("go");f.querySelector("button").disabled=true;f.submit();},400);</script>'
            : '<p><a class="btn quiet" href="' . e(admin_url()) . '">See your products</a></p>')
        . $errs);
}

/* ============================================== moving in from another shop
   1.3: customers (keeping their WordPress passwords until they next sign in),
   past orders (read-only: they never touch stock, send emails or use up
   discount codes) and the old shop's addresses, for redirects. The converter in
   tools/woo-to-wren.php turns WooCommerce's exports into these files. */

/* ---------------------------------------------------------- passwords */

/* Check a password against a WordPress hash, as WordPress does (6.8's $wp bcrypt,
   phpass $P$/$H$, plain bcrypt, or very old MD5). */
function wp_password_check(string $pass, string $hash): bool
{
    if (strlen($pass) > 4096 || $hash === '') {
        return false;
    }
    if (strlen($hash) <= 32) {
        return ctype_xdigit($hash) && hash_equals(strtolower($hash), md5($pass));
    }
    if (str_starts_with($hash, '$wp')) {
        return password_verify(base64_encode(hash_hmac('sha384', $pass, 'wp-sha384', true)), substr($hash, 3));
    }
    if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
        return hash_equals($hash, phpass_crypt($pass, $hash));
    }
    return password_verify($pass, $hash);
}

/* phpass's portable hash (what WordPress used before 6.8). */
function phpass_crypt(string $pass, string $setting): string
{
    $itoa = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $count = strpos($itoa, $setting[3] ?? '');
    if ($count === false || $count < 7 || $count > 30 || strlen($setting) < 12) {
        return '*';
    }
    $salt = substr($setting, 4, 8);
    $h = md5($salt . $pass, true);
    for ($n = 1 << $count; $n > 0; $n--) {
        $h = md5($h . $pass, true);
    }
    $out = substr($setting, 0, 12);
    $i = 0;
    $len = 16;
    do {   // phpass's own base-64
        $v = ord($h[$i++]);
        $out .= $itoa[$v & 0x3f];
        if ($i < $len) {
            $v |= ord($h[$i]) << 8;
        }
        $out .= $itoa[($v >> 6) & 0x3f];
        if ($i++ >= $len) {
            break;
        }
        if ($i < $len) {
            $v |= ord($h[$i]) << 16;
        }
        $out .= $itoa[($v >> 12) & 0x3f];
        if ($i++ >= $len) {
            break;
        }
        $out .= $itoa[($v >> 18) & 0x3f];
    } while ($i < $len);
    return $out;
}

/* Is this a customer's password? Brought-across WordPress passwords ("wp:" + hash)
   are checked WordPress's way once, then replaced with the shop's own. */
function customer_password_ok(string $pass, ?array $c): bool
{
    $stored = (string)($c['password'] ?? '');
    if (!$c || !str_starts_with($stored, 'wp:')) {
        return password_verify($pass, $c ? $stored : dummy_hash());
    }
    if (!wp_password_check($pass, substr($stored, 3))) {
        return false;
    }
    $new = password_hash($pass, PASSWORD_DEFAULT);
    db()->prepare('UPDATE customers SET password = ? WHERE id = ?')->execute([$new, $c['id']]);
    $GLOBALS['shop_rehashed'][$c['id']] = $new;
    return true;
}

/* ---------------------------------------------------- reading the files */

/* A CSV as rows of header => value, keyed by spreadsheet row number. */
function moving_read(string $file, array $need, array &$errors): array
{
    $fh = fopen($file, 'r');
    $head = fgetcsv($fh, null, ',', '"', '');
    if ($head && isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);
    }
    $cols = array_map(fn($h) => strtolower(trim((string)$h)), $head ?: []);
    if ($miss = array_diff($need, $cols)) {
        $errors[] = 'The file needs ' . (count($miss) === 1 ? 'a column called ' : 'columns called ') . implode(', ', $miss) . '.';
        fclose($fh);
        return [];
    }
    $rows = [];
    $n = 1;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $n++;
        if ($r === [null] || !array_filter($r, fn($x) => trim((string)$x) !== '')) {
            continue;
        }
        $row = [];
        foreach ($cols as $i => $c) {
            $row[$c] = trim((string)($r[$i] ?? ''));
        }
        $rows[$n] = $row;
    }
    fclose($fh);
    return $rows;
}

/* --------------------------------------------------------- customers */

const CUSTOMER_CSV_COLUMNS = ['email', 'name', 'phone', 'password_hash', 'created',
    'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode', 'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode'];

/* Add customers. Existing accounts (by email) are never changed. Returns a report. */
function customers_import(array $rows, bool $apply): array
{
    $rep = ['new' => 0, 'existing' => 0, 'passwords' => 0, 'errors' => []];
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $seen = [];
    foreach ($rows as $n => $r) {
        $email = mb_strtolower($r['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $rep['errors'][] = [$n, '"' . ($r['email'] ?? '') . '" isn\'t an email address.'];
            continue;
        }
        if (isset($seen[$email])) {
            $rep['errors'][] = [$n, $email . ' is also on row ' . $seen[$email] . '.'];
            continue;
        }
        $seen[$email] = $n;
        if (customer_by_email($email)) {
            $rep['existing']++;
            continue;
        }
        $hash = $r['password_hash'] ?? '';
        if ($hash !== '' && !preg_match('/^(\$wp\$2[aby]\$|\$P\$|\$H\$|\$2[aby]\$|\$argon2)[^\s]{20,}$|^[a-f0-9]{32}$/i', $hash)) {
            $rep['errors'][] = [$n, 'The password for ' . $email . ' isn\'t a WordPress password hash.'];
            continue;
        }
        // No password: they set one with "Forgotten your password?". A random one stands in till then.
        $stored = $hash !== '' ? 'wp:' . $hash : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $rep['passwords'] += $hash !== '' ? 1 : 0;
        $created = strtotime($r['created'] ?? '') ? gmdate('Y-m-d H:i:s', strtotime($r['created'])) : now();
        $a = [];
        foreach (ADDRESS_FIELDS as $k) {
            $a[$k] = mb_substr($r[$k] ?? '', 0, 120);
        }
        foreach (['bill_postcode', 'ship_postcode'] as $k) {
            $a[$k] = uk_postcode($a[$k]) ?? $a[$k];
        }
        $same = $a['ship_line1'] === '' || ($a['ship_line1'] === $a['bill_line1'] && $a['ship_postcode'] === $a['bill_postcode']);
        // Their address came from the old shop's own customers, so it counts as confirmed.
        $pdo->prepare('INSERT INTO customers (email, password, name, phone, ' . implode(', ', ADDRESS_FIELDS) . ', same_address, verified_at, created)
                       VALUES (?, ?, ?, ?, ' . implode(', ', array_fill(0, count(ADDRESS_FIELDS), '?')) . ', ?, ?, ?)')
            ->execute([$email, $stored, mb_substr($r['name'] ?? '', 0, 120) ?: $a['bill_name'], mb_substr($r['phone'] ?? '', 0, 40), ...array_values($a), $same ? 1 : 0, now(), $created]);
        $rep['new']++;
    }
    $pdo->exec($apply && !$rep['errors'] ? 'COMMIT' : 'ROLLBACK');
    return $rep;
}

/* ------------------------------------------------------- past orders */

const ORDER_CSV_COLUMNS = ['number', 'date', 'status', 'email', 'phone', 'bill_name', 'bill_line1', 'bill_line2', 'bill_town', 'bill_postcode',
    'ship_name', 'ship_line1', 'ship_line2', 'ship_town', 'ship_postcode', 'delivery_method', 'postage', 'discount', 'discount_code', 'total', 'vat',
    'payment', 'note', 'item_name', 'item_option', 'item_sku', 'item_qty', 'item_price'];

/* WooCommerce's order statuses as Wren's: [status, fulfilled]. Null: left out. */
function moving_status(string $s): ?array
{
    $s = strtolower(preg_replace('/^wc-/', '', trim($s)));
    return match ($s) {
        'completed', 'complete', 'paid', 'sent' => ['paid', 'sent'],
        'processing'                            => ['paid', 'sent'],   // finish these in the old shop before moving
        'refunded'                              => ['refunded', 'sent'],
        'cancelled', 'canceled', 'failed'       => ['cancelled', ''],
        default                                 => null,               // pending, on-hold, draft: not real orders yet
    };
}

/* Add past orders, one CSV row per item. Orders already brought across are left alone. */
function orders_import(array $rows, bool $apply): array
{
    $rep = ['new' => 0, 'again' => 0, 'left' => [], 'items' => 0, 'errors' => [], 'linked' => 0];
    $orders = [];
    foreach ($rows as $n => $r) {
        $num = $r['number'] ?? '';
        if (!ctype_digit($num) || (int)$num < 1) {
            $rep['errors'][] = [$n, 'The order number "' . $num . '" should be a whole number.'];
            continue;
        }
        $orders[(int)$num][$n] = $r;
    }
    $money = function (string $x, int $n, string $what) use (&$rep): int {
        if ($x === '') {
            return 0;
        }
        $p = parse_price(ltrim($x, '-'));
        if ($p === null) {
            $rep['errors'][] = [$n, 'The ' . $what . ' "' . $x . '" isn\'t an amount.'];
            return 0;
        }
        return $p;
    };
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    foreach ($orders as $num => $lines) {
        $n = array_key_first($lines);
        $o = $lines[$n];
        $st = $pdo->prepare('SELECT imported FROM orders WHERE number = ?');
        $st->execute([$num]);
        $was = $st->fetchColumn();
        if ($was !== false) {
            if ((int)$was) {
                $rep['again']++;
            } else {
                $rep['errors'][] = [$n, 'Order ' . $num . ' is already a number in this shop. Old orders need numbers the shop hasn\'t used.'];
            }
            continue;
        }
        $state = moving_status($o['status'] ?? '');
        if ($state === null) {
            $rep['left'][$o['status'] ?? ''] = ($rep['left'][$o['status'] ?? ''] ?? 0) + 1;
            continue;
        }
        $when = strtotime($o['date'] ?? '');
        if (!$when) {
            $rep['errors'][] = [$n, 'Order ' . $num . ' has no date we can read ("' . ($o['date'] ?? '') . '").'];
            continue;
        }
        $email = mb_strtolower($o['email'] ?? '');
        $items = [];
        $sub = 0;
        foreach ($lines as $ln => $l) {
            if (($l['item_name'] ?? '') === '') {
                continue;
            }
            $qty = ctype_digit($l['item_qty'] ?? '') ? (int)$l['item_qty'] : 1;
            $unit = $money($l['item_price'] ?? '', $ln, 'item price');
            $items[] = [$l['item_name'], $l['item_option'] ?? '', $l['item_sku'] ?? '', max(1, $qty), $unit];
            $sub += max(1, $qty) * $unit;
        }
        $post = $money($o['postage'] ?? '', $n, 'postage');
        $disc = $money($o['discount'] ?? '', $n, 'discount');
        $total = ($o['total'] ?? '') !== '' ? $money($o['total'], $n, 'total') : $sub - $disc + $post;
        $vat = ($o['vat'] ?? '') !== '' ? $money($o['vat'], $n, 'VAT') : null;
        $c = filter_var($email, FILTER_VALIDATE_EMAIL) ? customer_by_email($email) : null;
        $rep['linked'] += $c ? 1 : 0;
        $date = gmdate('Y-m-d H:i:s', $when);
        $deliver = trim(($o['ship_line1'] ?? '') . ($o['ship_postcode'] ?? '')) !== '';
        $pdo->prepare('INSERT INTO orders (number, token, status, payment_method, delivery, email, phone,
                       bill_name, bill_line1, bill_line2, bill_town, bill_postcode, ship_name, ship_line1, ship_line2, ship_town, ship_postcode,
                       same_address, customer_note, subtotal_pence, postage_pence, total_pence, vat_pence, vat_number, created, updated, customer_id,
                       discount_code, discount_pence, paid_at, fulfilled, fulfilled_at, delivery_method, imported)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute([$num, bin2hex(random_bytes(16)), $state[0], mb_substr($o['payment'] ?? '', 0, 60) ?: 'old shop', $deliver ? 'deliver' : 'collect', $email,
                       $o['phone'] ?? '', $o['bill_name'] ?? '', $o['bill_line1'] ?? '', $o['bill_line2'] ?? '', $o['bill_town'] ?? '', $o['bill_postcode'] ?? '',
                       $o['ship_name'] ?? '', $o['ship_line1'] ?? '', $o['ship_line2'] ?? '', $o['ship_town'] ?? '', $o['ship_postcode'] ?? '',
                       mb_substr($o['note'] ?? '', 0, 500), $sub, $post, $total, $vat, '', $date, $date, $c ? (int)$c['id'] : null,
                       $o['discount_code'] ?? '', $disc, $state[0] === 'cancelled' ? '' : $date, $state[1], $state[1] !== '' ? $date : '',
                       mb_substr($o['delivery_method'] ?? '', 0, 60)]);
        $oid = (int)$pdo->lastInsertId();
        foreach ($items as [$name, $opt, $sku, $qty, $unit]) {
            $pid = 0;
            if ($sku !== '') {   // linked to the product where its SKU is known, for reports
                $s2 = $pdo->prepare('SELECT id FROM products WHERE sku = ? COLLATE NOCASE UNION SELECT product_id FROM variants WHERE sku = ? COLLATE NOCASE LIMIT 1');
                $s2->execute([$sku, $sku]);
                $pid = (int)$s2->fetchColumn();
            }
            $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, option_label, unit_pence, qty, sku) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$oid, $pid, mb_substr($name, 0, 200), mb_substr($opt, 0, 200), $unit, $qty, mb_substr($sku, 0, 40)]);
            $rep['items']++;
        }
        $rep['new']++;
    }
    $pdo->exec($apply && !$rep['errors'] ? 'COMMIT' : 'ROLLBACK');
    return $rep;
}

/* -------------------------------------------------------------- page */

/* Customers → Import: customers and past orders from the old shop. */
function view_admin_moving(): never
{
    require_admin();
    $report = '';
    if (is_post()) {
        csrf_check();
        $kind = post('kind') === 'orders' ? 'orders' : 'customers';
        $file = data_dir() . '/moving-' . $kind . '.csv';
        if (post('do') === 'apply') {
            if (!is_file($file) || !hash_equals((string)($_SESSION['shop_moving_' . $kind] ?? ''), post('token'))) {
                flash('That check has expired. Please choose the file again.');
                redirect(admin_url('moving'));
            }
        } else {
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                flash(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is bigger than this server accepts. ' . upload_limit_text() : 'Choose a CSV file to check.');
                redirect(admin_url('moving'));
            }
            move_uploaded_file($f['tmp_name'], $file);
            @chmod($file, 0600);
        }
        $errors = [];
        $rows = moving_read($file, $kind === 'orders' ? ['number', 'date', 'status'] : ['email'], $errors);
        $apply = post('do') === 'apply';
        $rep = $errors ? ['errors' => array_map(fn($x) => [0, $x], $errors)] : ($kind === 'orders' ? orders_import($rows, $apply) : customers_import($rows, $apply));
        if ($apply) {
            @unlink($file);
            unset($_SESSION['shop_moving_' . $kind]);
            flash($rep['errors'] ? 'The file has problems now, so nothing was imported. Please check it again.'
                : ($kind === 'orders' ? 'Brought across ' . $rep['new'] . ' past order' . ($rep['new'] === 1 ? '' : 's') . '.'
                                      : 'Brought across ' . $rep['new'] . ' customer' . ($rep['new'] === 1 ? '' : 's') . '.'));
            redirect(admin_url($kind === 'orders' && !$rep['errors'] ? 'orders' : 'moving'));
        }
        $token = bin2hex(random_bytes(12));
        $_SESSION['shop_moving_' . $kind] = $token;
        $report = moving_report_html($kind, $rep, count($rows), $token);
    }
    admin_shell('Import customers and orders', '<h1>Import customers and past orders</h1>' . ($report !== '' ? $report : '
      <p class="lede">For moving from another shop, like WooCommerce. Bring customers across first, then their past orders. The converter that comes with
      Wren Shop (<code>tools/woo-to-wren.php</code>) makes both files from WooCommerce\'s exports.</p>
      <h2>Customers</h2>
      <p class="muted">One row per customer: <code>' . implode('</code>, <code>', CUSTOMER_CSV_COLUMNS) . '</code>. Only <code>email</code> is needed.
      With a WordPress <code>password_hash</code>, customers sign in with the password they already have (it\'s changed to Wren Shop\'s own as they do).
      Without one, they use "Forgotten your password?". Accounts that already exist here are left as they are.</p>
      <form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="kind" value="customers">
        <label>Customers CSV <input type="file" name="file" accept=".csv,text/csv" required></label><button>Check this file</button></form>
      <h2>Past orders</h2>
      <p class="muted">One row per item: <code>' . implode('</code>, <code>', ORDER_CSV_COLUMNS) . '</code>. They keep their old numbers, show in each customer\'s
      account and in Orders, marked as from your old shop. They\'re a record only: they never change stock, send emails or count towards discount codes.
      Completed, processing, refunded and cancelled orders come across; pending and on-hold ones don\'t. Finish any you\'re still sending in the old shop first.
      Bringing the same file again adds nothing twice.</p>
      <form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="kind" value="orders">
        <label>Orders CSV <input type="file" name="file" accept=".csv,text/csv" required></label><button>Check this file</button></form>
      ' . redirects_section()));
}

function moving_report_html(string $kind, array $rep, int $rows, string $token): string
{
    $out = '<div class="report"><p class="lede">Checked ' . number_format($rows) . ' row' . ($rows === 1 ? '' : 's') . '. Nothing has changed yet.</p>';
    if ($rep['errors']) {
        return $out . '<div class="err"><b>' . count($rep['errors']) . ' problem' . (count($rep['errors']) === 1 ? '' : 's') . '.</b> Nothing can be imported until they\'re fixed.'
            . '<table class="errs">' . implode('', array_map(fn($x) => '<tr><td>' . ($x[0] ? 'Row ' . $x[0] : 'File') . '</td><td>' . e($x[1]) . '</td></tr>', array_slice($rep['errors'], 0, 300))) . '</table></div>'
            . '<p><a class="btn quiet" href="' . e(admin_url('moving')) . '">Check another file</a></p></div>';
    }
    if ($kind === 'customers') {
        $out .= '<p class="flash"><b>' . $rep['new'] . ' new customer' . ($rep['new'] === 1 ? '' : 's') . '</b>, ' . $rep['passwords'] . ' keeping their password.'
              . ($rep['existing'] ? ' ' . $rep['existing'] . ' already have an account here and are left as they are.' : '') . '</p>';
    } else {
        $out .= '<p class="flash"><b>' . $rep['new'] . ' past order' . ($rep['new'] === 1 ? '' : 's') . '</b> with ' . $rep['items'] . ' item' . ($rep['items'] === 1 ? '' : 's') . ', '
              . $rep['linked'] . ' matched to a customer\'s account.' . ($rep['again'] ? ' ' . $rep['again'] . ' brought across before, so left alone.' : '') . '</p>'
              . ($rep['left'] ? '<p class="muted">Left out: ' . e(implode(', ', array_map(fn($s, $c) => $c . ' ' . ($s ?: 'with no status'), array_keys($rep['left']), $rep['left']))) . '.</p>' : '');
    }
    return $out . ($rep['new'] ? '<form method="post" class="actions-row" style="border:0;padding:0">' . csrf_field()
            . '<input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="do" value="apply"><input type="hidden" name="token" value="' . e($token) . '">'
            . '<button>Bring them across</button> <a href="' . e(admin_url('moving')) . '">Cancel</a></form>'
            : '<p><a class="btn quiet" href="' . e(admin_url('moving')) . '">Back</a></p>') . '</div>';
}

/* ---------------------------------------------------------- redirects */

/* The old shop's address for a product, from the products CSV's old_url column. */
function redirect_set(string $oldUrl, int $pid): ?string
{
    $path = parse_url(trim($oldUrl), PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path === '/' || !preg_match('#^/[A-Za-z0-9._~%/+-]*$#', $path)) {
        return 'The old address ' . $oldUrl . ' isn\'t a web address path like /product/wishbone-front/.';
    }
    $path = '/' . trim(rawurldecode($path), '/') . '/';
    db()->prepare('INSERT OR REPLACE INTO redirects (old_path, product_id) VALUES (?, ?)')->execute([$path, $pid]);
    return null;
}

/* Rewrite rules for the site's .htaccess: each old address to its product or category. */
function redirect_rules(bool $woocats): array
{
    $rules = [];
    $bad = [];
    $q = preg_quote(...);
    foreach (db()->query('SELECT r.old_path, p.slug, p.visible, p.name FROM redirects r LEFT JOIN products p ON p.id = r.product_id ORDER BY r.old_path') as $r) {
        if ($r['slug'] === null || !(int)$r['visible']) {
            $bad[] = $r['old_path'] . ($r['slug'] === null ? ' (its product has been deleted)' : ' (' . $r['name'] . ' is hidden)');
            if ($r['slug'] === null) {
                continue;
            }
        }
        $rules[] = 'RewriteRule ^' . $q(ltrim(rtrim($r['old_path'], '/'), '/')) . '/?$ ' . shop_base_path() . '/p/' . $r['slug'] . ' [R=301,L]';
    }
    if ($woocats) {   // WooCommerce's /product-category/parent/child/ addresses
        foreach (cat_tree() as $c) {
            $parts = array_map(fn($x) => wp_slug($x['name']), cat_trail((int)$c['id']));
            $rules[] = 'RewriteRule ^product-category/' . $q(implode('/', $parts)) . '/?$ ' . shop_base_path() . '/c/' . $c['slug'] . ' [R=301,L]';
        }
    }
    return [$rules, $bad];
}

/* A name as WordPress makes it into an address: "Oils & Fluids" -> oils-fluids. */
function wp_slug(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(['&', "'", '’'], '', $s);
    if (function_exists('iconv')) {
        $s = (string)@iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    }
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

/* /shop, or wherever the shop lives on the site. */
function shop_base_path(): string
{
    return rtrim((string)parse_url(abs_shop_url(), PHP_URL_PATH), '/') ?: '/shop';
}

function redirects_section(): string
{
    $n = (int)db()->query('SELECT COUNT(*) FROM redirects')->fetchColumn();
    if (!$n && !cat_tree()) {
        return '';
    }
    [$rules, $bad] = redirect_rules(true);
    return '<h2 id="redirects">Old addresses</h2>'
        . '<p class="muted">So links and search results for your old shop still work, your site sends each old address on to its new one. '
        . ($n ? $n . ' old product address' . ($n === 1 ? '' : 'es') . ' came from the <code>old_url</code> column of your products import. ' : 'Add an <code>old_url</code> column to your products import to list them. ')
        . 'WooCommerce\'s category addresses (<code>/product-category/…/</code>) are included, made from your categories\' names.</p>'
        . ($bad ? '<div class="err"><b>' . count($bad) . ' won\'t reach a product in the shop:</b> ' . e(implode('; ', array_slice($bad, 0, 30))) . '</div>' : '')
        . '<p><a class="btn quiet" href="' . e(url_with(admin_url('moving'), ['rules' => '1'])) . '">Download the redirect rules</a></p>'
        . '<p class="muted">Put them in your site\'s main <code>.htaccess</code> (the one beside Wren\'s <code>index.php</code>), straight after the line '
        . '<code>RewriteEngine On</code>, so they come before Wren\'s own rules.</p>';
}

function redirect_rules_download(): never
{
    [$rules] = redirect_rules(true);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="wren-shop-redirects.txt"');
    echo "# Wren Shop: old shop addresses to their new ones (" . gmdate('Y-m-d') . ")\n"
       . "# Paste straight after \"RewriteEngine On\" in the site's main .htaccess.\n"
       . implode("\n", $rules) . "\n";
    exit;
}

/* ========================================================= related products
   "You might also like" (1.3): up to 8 products chosen on a product's form (or
   in the CSV's related column), in that order; with none chosen, others from
   the same category. Hidden products never show. */

const RELATED_MAX = 8;
const RELATED_NEIGHBOURS = 4;

function related_ids(int $pid): array
{
    $st = db()->prepare('SELECT related_id FROM product_related WHERE product_id = ? ORDER BY position');
    $st->execute([$pid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/* The products to show under this one. */
function related_for(array $p): array
{
    $ids = related_ids((int)$p['id']);
    if ($ids) {
        $st = db()->prepare('SELECT * FROM products WHERE visible = 1 AND id IN (' . implode(',', $ids) . ')');
        $st->execute();
        $by = array_column($st->fetchAll(), null, 'id');
        return array_values(array_filter(array_map(fn($i) => $by[$i] ?? null, $ids)));
    }
    if (!(int)$p['category_id']) {
        return [];
    }
    // Neighbours: the same main category (or shown in it), newest first.
    $st = db()->prepare('SELECT * FROM products p WHERE p.visible = 1 AND p.id != ? AND (p.category_id = ?
                         OR p.id IN (SELECT product_id FROM product_categories WHERE category_id = ?)) ORDER BY p.created DESC, p.id DESC LIMIT ' . RELATED_NEIGHBOURS);
    $st->execute([$p['id'], $p['category_id'], $p['category_id']]);
    return $st->fetchAll();
}

function related_html(array $p): string
{
    $rows = related_for($p);
    return $rows ? '<section class="shop-related"><h2 class="shop-h2">You might also like</h2><div class="shop-grid">' . product_cards($rows) . '</div></section>' : '';
}

/* A product named by SKU (its own or a choice's), web address or exact name. */
function product_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    foreach (['SELECT * FROM products WHERE sku = ? COLLATE NOCASE', 'SELECT * FROM products WHERE slug = ?',
              'SELECT p.* FROM products p JOIN variants v ON v.product_id = p.id WHERE v.sku = ? COLLATE NOCASE',
              'SELECT * FROM products WHERE name = ? COLLATE NOCASE'] as $sql) {
        $st = db()->prepare($sql . ' LIMIT 2');
        $st->execute([$ref]);
        $r = $st->fetchAll();
        if (count($r) === 1) {
            return $r[0];
        }
    }
    return null;
}

/* Read a list of related products ("SKU, web address, ..."). Returns ids, adding errors. */
function related_parse(string $list, int $self, array &$errors): array
{
    $ids = [];
    foreach (preg_split('/\s*[,|\n]\s*/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $ref) {
        $p = product_by_ref(preg_replace('/\s+—\s+.*$/u', '', $ref));   // "SKU — name" from the suggestions
        if (!$p) {
            $errors[] = 'No product has the SKU or web address "' . $ref . '" (for related products).';
        } elseif ((int)$p['id'] !== $self && !in_array((int)$p['id'], $ids, true)) {
            $ids[] = (int)$p['id'];
        }
    }
    if (count($ids) > RELATED_MAX) {
        $errors[] = 'Up to ' . RELATED_MAX . ' related products, please.';
    }
    return array_slice($ids, 0, RELATED_MAX);
}

function related_save(int $pid, array $ids): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM product_related WHERE product_id = ?')->execute([$pid]);
    foreach (array_values($ids) as $i => $rid) {
        $pdo->prepare('INSERT INTO product_related (product_id, related_id, position) VALUES (?, ?, ?)')->execute([$pid, $rid, $i]);
    }
}

/* How a product is named in the related list: its SKU, else its web address. */
function related_ref(array $p): string
{
    return $p['sku'] !== '' ? $p['sku'] : $p['slug'];
}

function related_refs(int $pid): string
{
    $out = [];
    foreach (related_ids($pid) as $id) {
        if ($r = product_by_id($id)) {
            $out[] = related_ref($r);
        }
    }
    return implode(', ', $out);
}

/* The product form's Related products section. */
function related_form_html(?array $p, array $v): string
{
    $val = $v['related'] ?? ($p ? related_refs((int)$p['id']) : '');
    $n = (int)db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $opts = '';
    if ($n <= 3000) {   // suggestions as you type
        foreach (db()->query('SELECT sku, slug, name FROM products ORDER BY name COLLATE NOCASE') as $r) {
            $opts .= '<option value="' . e(($r['sku'] !== '' ? $r['sku'] : $r['slug']) . ' — ' . $r['name']) . '">';
        }
    }
    return '<details class="opts" id="related"' . ($val !== '' ? ' open' : '') . '><summary>Related products <span class="hint">(optional: "You might also like")</span></summary>'
        . '<p class="muted">Up to ' . RELATED_MAX . ' SKUs or web addresses, separated by commas, in the order to show them. Leave empty to show others from the same category.</p>'
        . '<input name="related" value="' . e($val) . '" list="related-list" placeholder="e.g. 804501, shock-oil-400cps" aria-label="Related products">'
        . ($opts !== '' ? '<datalist id="related-list">' . $opts . '</datalist>' : '')
        . '</details>';
}

/* ============================================================ sales reports
   Orders → Reports: paid orders by month, best sellers and sales by category,
   for a date range (UK dates of when orders were placed, as in the orders CSV).
   Refunded orders are left out of the sales and shown on their own. */

function report_range(): array
{
    $tz = new DateTimeZone('Europe/London');
    $today = new DateTimeImmutable('now', $tz);
    $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
    $to = $ok($_GET['to'] ?? null) ?? $today->format('Y-m-d');
    $from = $ok($_GET['from'] ?? null) ?? $today->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
    return $from <= $to ? [$from, $to] : [$to, $from];
}

/* Paid and refunded orders placed in the range, each with its UK month. */
function report_orders(string $from, string $to): array
{
    $tz = new DateTimeZone('Europe/London');
    $utc = fn(string $d, bool $end) => (new DateTimeImmutable($d . ($end ? ' 23:59:59' : ' 00:00:00'), $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $st = db()->prepare("SELECT * FROM orders WHERE status IN ('paid', 'refunded') AND created >= ? AND created <= ? ORDER BY created");
    $st->execute([$utc($from, false), $utc($to, true)]);
    $out = [];
    foreach ($st->fetchAll() as $o) {
        $o['month'] = (new DateTimeImmutable($o['created'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m');
        $out[] = $o;
    }
    return $out;
}

function report_data(string $from, string $to): array
{
    $orders = report_orders($from, $to);
    $months = [];
    // Every month in the range, even quiet ones.
    for ($m = new DateTimeImmutable(substr($from, 0, 7) . '-01'); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->modify('+1 month')) {
        $months[$m->format('Y-m')] = ['orders' => 0, 'goods' => 0, 'discounts' => 0, 'delivery' => 0, 'vat' => 0, 'total' => 0, 'refunds' => 0, 'refunded' => 0];
    }
    $paid = [];
    foreach ($orders as $o) {
        $r = &$months[$o['month']];
        if ($o['status'] === 'refunded') {
            $r['refunds']++;
            $r['refunded'] += (int)$o['total_pence'];
            continue;
        }
        $paid[] = (int)$o['id'];
        $r['orders']++;
        $r['goods'] += (int)$o['subtotal_pence'] - (int)$o['group_pence'];
        $r['discounts'] += (int)$o['discount_pence'];
        $r['delivery'] += (int)$o['postage_pence'];
        $r['vat'] += (int)($o['vat_pence'] ?? 0);
        $r['total'] += (int)$o['total_pence'];
        unset($r);
    }
    // Best sellers and categories, from the items of paid orders (at the prices charged).
    $best = [];
    $cats = [];
    if ($paid) {
        foreach (array_chunk($paid, 500) as $chunk) {
            $rows = db()->query('SELECT i.product_id, i.name, i.qty, i.unit_pence, p.category_id, p.name AS pname FROM order_items i
                                 LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id IN (' . implode(',', $chunk) . ')')->fetchAll();
            foreach ($rows as $i) {
                $key = (int)$i['product_id'] && $i['pname'] !== null ? 'p' . $i['product_id'] : 'n' . mb_strtolower($i['name']);
                $best[$key] ??= ['name' => $i['pname'] ?? $i['name'], 'qty' => 0, 'value' => 0, 'id' => (int)$i['product_id']];
                $best[$key]['qty'] += (int)$i['qty'];
                $best[$key]['value'] += (int)$i['qty'] * (int)$i['unit_pence'];
                $top = $i['category_id'] !== null && (int)$i['category_id'] ? (cat_trail((int)$i['category_id'])[0] ?? null) : null;
                $ck = $top ? (int)$top['id'] : 0;
                $cats[$ck] ??= ['name' => $top ? $top['name'] : 'No category', 'qty' => 0, 'value' => 0];
                $cats[$ck]['qty'] += (int)$i['qty'];
                $cats[$ck]['value'] += (int)$i['qty'] * (int)$i['unit_pence'];
            }
        }
    }
    $byQty = array_values($best);
    usort($byQty, fn($a, $b) => [$b['qty'], $b['value']] <=> [$a['qty'], $a['value']]);
    $byValue = array_values($best);
    usort($byValue, fn($a, $b) => [$b['value'], $b['qty']] <=> [$a['value'], $a['qty']]);
    $cats = array_values($cats);
    usort($cats, fn($a, $b) => $b['value'] <=> $a['value']);
    return ['months' => $months, 'qty' => array_slice($byQty, 0, 20), 'value' => array_slice($byValue, 0, 20), 'cats' => $cats];
}

/* A bar chart of sales by month, drawn as SVG by the page itself. */
function report_chart(array $months): string
{
    $max = max(1, ...array_values(array_map(fn($m) => $m["total"], $months)));
    $n = count($months);
    $w = 640;
    $h = 180;
    $bw = $w / max(1, $n);
    $bars = '';
    $i = 0;
    foreach ($months as $key => $m) {
        $bh = (int)round($m['total'] / $max * ($h - 30));
        $x = $i * $bw;
        $label = (new DateTimeImmutable($key . '-01'))->format($n > 12 ? 'M y' : 'M');
        $bar = min($bw * 0.7, 56);   // slim bars even with only a few months
        $bars .= '<g><title>' . e($label . ': ' . money($m['total']) . ', ' . $m['orders'] . ' orders') . '</title>'
               . '<rect x="' . round($x + ($bw - $bar) / 2, 1) . '" y="' . ($h - 20 - $bh) . '" width="' . round($bar, 1) . '" height="' . max($bh, $m['total'] ? 1 : 0) . '" rx="2"></rect>'
               . '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 5) . '" text-anchor="middle">' . e($label) . '</text></g>';
        $i++;
    }
    return '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Sales by month, the highest ' . e(money($max)) . '">' . $bars . '</svg>';
}

/* One report table as CSV rows. */
function report_table(array $d, string $which): array
{
    $m = fn(int $p) => price_input($p);
    return match ($which) {
        'months' => array_merge([['Month', 'Orders', 'Goods', 'Discounts', 'Delivery', 'VAT included', 'Total', 'Refunded orders', 'Refunded']],
            array_map(fn($k, $r) => [(new DateTimeImmutable($k . '-01'))->format('F Y'), $r['orders'], $m($r['goods']), $m($r['discounts']),
                $m($r['delivery']), $m($r['vat']), $m($r['total']), $r['refunds'], $m($r['refunded'])], array_keys($d['months']), $d['months'])),
        'qty', 'value' => array_merge([['Product', 'Sold', 'Sales']], array_map(fn($r) => [$r['name'], $r['qty'], $m($r['value'])], $d[$which])),
        'cats' => array_merge([['Category', 'Sold', 'Sales']], array_map(fn($r) => [$r['name'], $r['qty'], $m($r['value'])], $d['cats'])),
        default => [],
    };
}

function view_admin_reports(): never
{
    require_admin();
    [$from, $to] = report_range();
    $d = report_data($from, $to);
    if (isset($_GET['csv']) && in_array($_GET['csv'], ['months', 'qty', 'value', 'cats'], true)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sales-' . $_GET['csv'] . '-' . $from . '-to-' . $to . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF";
        foreach (report_table($d, $_GET['csv']) as $r) {
            echo implode(',', array_map('csv_cell', $r)), "\r\n";
        }
        exit;
    }
    $csv = fn(string $w) => '<a class="rcsv" href="' . e(url_with(admin_url('reports'), ['from' => $from, 'to' => $to, 'csv' => $w])) . '">Download CSV</a>';
    $sum = array_reduce($d['months'], fn($c, $r) => array_map(fn($a, $b) => $a + $b, $c, $r), array_fill(0, 8, 0));
    $sum = array_combine(array_keys(reset($d['months']) ?: ['orders' => 0, 'goods' => 0, 'discounts' => 0, 'delivery' => 0, 'vat' => 0, 'total' => 0, 'refunds' => 0, 'refunded' => 0]), $sum);
    $rows = '';
    foreach (array_reverse($d['months'], true) as $k => $r) {
        $rows .= '<tr><td>' . e((new DateTimeImmutable($k . '-01'))->format('F Y')) . '</td><td class="num">' . $r['orders'] . '</td><td class="num">' . money($r['goods'])
               . '</td><td class="num">' . ($r['discounts'] ? '−' . money($r['discounts']) : '') . '</td><td class="num">' . money($r['delivery'])
               . '</td><td class="num">' . money($r['vat']) . '</td><td class="num"><b>' . money($r['total']) . '</b></td><td class="num muted">'
               . ($r['refunds'] ? $r['refunds'] . ' · ' . money($r['refunded']) : '') . '</td></tr>';
    }
    $tbl = function (array $list, string $first) {
        $out = '';
        foreach ($list as $i => $r) {
            $out .= '<tr><td>' . ($i + 1) . '.</td><td>' . (!empty($r['id']) ? '<a href="' . e(admin_url('edit/' . $r['id'])) . '">' . e($r['name']) . '</a>' : e($r['name']))
                  . '</td><td class="num">' . $r['qty'] . '</td><td class="num">' . money($r['value']) . '</td></tr>';
        }
        return $out !== '' ? '<table class="rtable"><tr><th></th><th>' . $first . '</th><th class="num">Sold</th><th class="num">Sales</th></tr>' . $out . '</table>' : '<p class="muted">None in these dates.</p>';
    };
    $presets = [];
    $tz = new DateTimeZone('Europe/London');
    $now = new DateTimeImmutable('now', $tz);
    foreach (['This month' => [$now->modify('first day of this month'), $now], 'Last month' => [$now->modify('first day of last month'), $now->modify('last day of last month')],
              'Last 12 months' => [$now->modify('first day of this month')->modify('-11 months'), $now],
              'This year' => [$now->setDate((int)$now->format('Y'), 1, 1), $now]] as $label => [$a, $b]) {
        $presets[] = '<a href="' . e(url_with(admin_url('reports'), ['from' => $a->format('Y-m-d'), 'to' => $b->format('Y-m-d')])) . '">' . $label . '</a>';
    }
    admin_shell('Reports', '<h1>Sales reports</h1>'
        . '<form method="get" action="' . e(admin_url('reports')) . '" class="rrange">'
        . (str_contains(admin_url('reports'), '?') ? '<input type="hidden" name="q" value="admin/reports">' : '')
        . '<label>From <input type="date" name="from" value="' . e($from) . '"></label><label>To <input type="date" name="to" value="' . e($to) . '"></label>'
        . '<button class="quiet">Show</button><span class="muted">' . implode(' · ', $presets) . '</span></form>'
        . '<p class="muted">Paid orders placed in these dates (UK dates, as in the orders CSV). Refunded orders aren\'t counted as sales; they\'re shown on their own. '
        . 'Prices include VAT. Orders brought across from an old shop are included.</p>'
        . '<div class="rsum"><div><span>' . money($sum['total']) . '</span>Sales</div><div><span>' . $sum['orders'] . '</span>Orders</div>'
        . '<div><span>' . ($sum['orders'] ? money(intdiv($sum['total'], $sum['orders'])) : '–') . '</span>Average order</div>'
        . '<div><span>' . money($sum['vat']) . '</span>VAT included</div></div>'
        . report_chart($d['months'])
        . '<div class="rhead"><h2>By month</h2>' . $csv('months') . '</div>'
        . '<div class="rwrap"><table class="rtable"><tr><th>Month</th><th class="num">Orders</th><th class="num">Goods</th><th class="num">Discounts</th><th class="num">Delivery</th>'
        . '<th class="num">VAT incl.</th><th class="num">Total</th><th class="num">Refunded</th></tr>' . $rows
        . '<tr class="rtot"><td>Total</td><td class="num">' . $sum['orders'] . '</td><td class="num">' . money($sum['goods']) . '</td><td class="num">'
        . ($sum['discounts'] ? '−' . money($sum['discounts']) : '') . '</td><td class="num">' . money($sum['delivery']) . '</td><td class="num">' . money($sum['vat'])
        . '</td><td class="num">' . money($sum['total']) . '</td><td class="num muted">' . ($sum['refunds'] ? $sum['refunds'] . ' · ' . money($sum['refunded']) : '') . '</td></tr></table></div>'
        . '<div class="rcols"><div><div class="rhead"><h2>Best sellers by quantity</h2>' . $csv('qty') . '</div>' . $tbl($d['qty'], 'Product') . '</div>'
        . '<div><div class="rhead"><h2>Best sellers by sales</h2>' . $csv('value') . '</div>' . $tbl($d['value'], 'Product') . '</div></div>'
        . '<div class="rhead"><h2>By category</h2>' . $csv('cats') . '</div>' . $tbl($d['cats'], 'Category')
        . '<p class="muted">Best sellers and categories count items at the prices charged, before any discount code on the whole order, and by each product\'s main category today.</p>');
}
/* ------------------------------------------------------------------ router */

$q = trim(str_in($_GET['q'] ?? ''), '/');

// Every shop response: no MIME sniffing, no full URLs (with order or reset
// tokens) sent to other sites, and only this site may show it in a frame.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
if ($q === '' && !empty($_SERVER['REQUEST_URI'])) {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, shop_base() . '/')) {
        $path = trim(substr($path, strlen(shop_base())), '/');
        if ($path !== '' && $path !== 'index.php') {
            $q = $path;
        }
    }
}
$seg = $q === '' ? [] : explode('/', $q);
$GLOBALS['shop_seg'] = $seg;   // the admin menu reads it to show where you are

if (($seg[0] ?? '') === 'admin') {
    $sub = $seg[1] ?? '';
    match (true) {
        $sub === 'setup'                        => view_setup(),
        $sub === 'login'                        => view_login(),
        $sub === 'verify'                       => view_verify(),
        $sub === 'logout'                       => admin_logout(),
        $sub === ''                             => view_admin_products(),
        $sub === 'new'                          => view_product_form(null),
        $sub === 'edit' && isset($seg[2])       => (function () use ($seg) {
            require_admin();
            $p = product_by_id((int)$seg[2]);
            $p ? view_product_form($p) : redirect(admin_url());
        })(),
        $sub === 'save' && is_post()            => admin_save(),
        $sub === 'quick' && is_post()           => admin_quick(),
        $sub === 'duplicate' && is_post()       => admin_duplicate(),
        $sub === 'delete' && is_post()          => admin_delete(),
        $sub === 'categories'                   => view_admin_categories(),
        $sub === 'import'                       => view_admin_import(),
        $sub === 'orders'                       => view_admin_orders(),
        $sub === 'customers'                    => view_admin_customers(),
        $sub === 'discounts'                    => view_admin_discounts(),
        $sub === 'groups'                       => view_admin_groups(),
        $sub === 'trade'                        => view_admin_trade(),
        $sub === 'moving' && ($_GET['rules'] ?? '') === '1' => (function () { require_admin(); redirect_rules_download(); })(),
        $sub === 'moving'                       => view_admin_moving(),
        $sub === 'reports'                      => view_admin_reports(),
        $sub === 'ready'                        => view_admin_ready(),
        $sub === 'export'                       => view_admin_export(),
        $sub === 'licences'                     => view_admin_licences(),
        $sub === 'licence' && isset($seg[2])    => view_admin_licence((int)$seg[2]),
        $sub === 'privacy'                      => view_admin_privacy(),
        $sub === 'group' && isset($seg[2])      => view_admin_group($seg[2]),
        $sub === 'discount' && isset($seg[2])   => view_admin_discount($seg[2]),
        $sub === 'customer' && isset($seg[2])   => view_admin_customer((int)$seg[2]),
        $sub === 'order' && isset($seg[2]) && ($seg[3] ?? '') === 'slip' => view_packing_slip((int)$seg[2]),
        $sub === 'order' && isset($seg[2]) && ($seg[3] ?? '') === 'invoice' => view_invoice_admin((int)$seg[2]),
        $sub === 'order' && isset($seg[2])      => is_post() ? admin_order_action((int)$seg[2]) : view_admin_order((int)$seg[2]),
        $sub === 'settings'                     => view_admin_settings($seg[2] ?? 'general'),
        $sub === 'refresh-look'                 => redirect(settings_url('advanced')),
        default                                 => redirect(admin_url()),
    };
}

orders_tidy();

if (($seg[0] ?? '') === 'account') {
    if (!accounts_on()) {
        view_404();
    }
    $sub = $seg[1] ?? '';
    match (true) {
        $sub === ''                                  => view_account(),
        $sub === 'login'                             => view_account_login(),
        $sub === 'register'                          => view_account_register(),
        $sub === 'logout' && is_post()               => account_logout(),
        $sub === 'forgot'                            => view_account_forgot(),
        $sub === 'reset'                             => view_account_reset($seg[2] ?? null),
        $sub === 'verify'                            => view_account_verify($seg[2] ?? null),
        $sub === 'verify-send' && is_post()          => account_verify_send(),
        $sub === 'details'                           => view_account_details(),
        $sub === 'password'                          => view_account_password(),
        $sub === 'addresses'                         => view_account_addresses(),
        $sub === 'delete'                            => view_account_delete(),
        $sub === 'from-order' && is_post()           => account_from_order(),
        $sub === 'licences'                          => view_account_licences(),
        $sub === 'trade'                             => view_account_trade(),
        $sub === 'licence' && isset($seg[2])         => view_account_licence((int)$seg[2]),
        $sub === 'download' && isset($seg[2])        => account_download((int)$seg[2]),
        default                                      => view_404(),
    };
}

match (true) {
    $q === ''                                    => view_list(),
    ($seg[0] ?? '') === 'p' && count($seg) === 2 => view_product($seg[1]),
    ($seg[0] ?? '') === 'c' && count($seg) === 2 => view_list($seg[1]),
    $q === 'basket/add' && is_post()             => basket_add(),
    $q === 'basket/update' && is_post()          => basket_update(),
    $q === 'checkout'                            => is_post() ? checkout_post() : view_checkout(),
    $q === 'paypal/return'                       => paypal_return(),
    ($seg[0] ?? '') === 'licence' && count($seg) === 2 => licence_api($seg[1]),
    $q === 'paypal/cancel'                       => paypal_cancel(),
    ($seg[0] ?? '') === 'order' && count($seg) === 2 => view_order($seg[1]),
    ($seg[0] ?? '') === 'order' && count($seg) === 3 && $seg[2] === 'invoice' => view_invoice_public($seg[1]),
    $q === 'basket'                              => view_basket(),
    $q === 'search'                              => view_search(),
    $q === 'sitemap.xml' && !shop_demo()           => view_sitemap(),
    default                                      => view_404(),
};

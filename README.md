# Wren Shop

A small, free online shop that sits beside a [Wren CMS](https://wrencms.com) website.

Wren Shop lives in its own `/shop/` folder and borrows your Wren site's theme and menu, so the shop looks like part of the site. Wren itself is never changed, and its database is never opened: remove the `shop` folder and Wren is exactly as it was.

It's one PHP file and a SQLite database, made for UK businesses, from a handful of products to a few thousand.

- Products with photos, SKUs, and one or two options (colour and size) with their own prices, stock, SKUs and weights; combinations not made can be switched off
- Categories inside categories (three levels), products in several categories, in a card down the side of the shop (or dropdown menus along the top)
- Sale prices with optional start and end dates, and backorders for stock that's on its way
- Product import and export by spreadsheet (CSV), checked before anything changes, and photo import (a zip named by SKU, or web addresses), done in batches
- Moving from WooCommerce: products, photos, customers keeping their passwords, past orders and redirects from the old addresses
- Search, with part numbers first and your own synonyms; paged and sortable lists
- Delivery methods (standard, next day, courier, collection) priced flat, by order value or by weight, with UK postcode zones, and free delivery over an amount
- Payment by **PayPal**, **bank transfer** or **pay on collection**
- VAT shown for VAT-registered shops, and printable VAT invoices (receipts for other shops)
- Order emails over your own SMTP, packing slips, refunds through PayPal
- Customer accounts (guest checkout always works), discount codes, and customer groups such as reseller prices
- Trade accounts: fixed trade prices, prices shown without VAT, minimum orders, ways to pay per group and an application form
- **Digital downloads**, including Wren themes licensed per website, with renewals for updates
- Sales reports (by month, best sellers, categories), related products, low-stock emails, CSV export for bookkeeping, and a tool for customers' data requests
- A full-width admin with sections down the side and tabs along the top that works on a phone, with two-factor sign-in and a "Getting ready" checklist for launch or handover

**Demo:** [wrencms.com/shop](https://wrencms.com/shop/)
**Owner's guide:** [the wiki](https://github.com/Wren-CMS/wren-shop/wiki)

## Requirements

- A Wren CMS site
- PHP 8.1 or newer with SQLite (PDO), GD, cURL and mbstring (and Zip, to import a zip of photos)
- Apache or LiteSpeed (nginx works with two extra rules; see the guide)
- An SSL certificate (`https://`)

## Installing

1. Download the latest release and unzip it.
2. Upload the `shop` folder (with its `.htaccess` file) next to Wren's `index.php`.
3. Visit `/shop/admin`. Open `shop/data/setup-code.php` in your hosting file manager, copy the code, and create the admin account.
4. Work through **Settings → Getting ready**.
5. Add a menu link to `/shop/` in Wren.

## Upgrading

Upload only the new `shop/index.php`, replacing the old one. Never replace the `data` or `media` folders. Then open the shop admin once.

Upgrading to 1.3.3 from any earlier version is the same; everything is kept. One thing looks different: on a computer, the categories are now in a card down the side (Settings → Shop puts them back along the top). If you use **Proxy or CDN: Cloudflare**, upgrade soon: earlier versions showed an error page with it on.

Upgrading from 0.12.0 or earlier: the shop now learns its web address from your signed-in visit to the admin, so it uses a plain version of your theme and sends no emails until you've opened the admin once.

## Moving from WooCommerce

`tools/woo-to-wren.php` turns WooCommerce's exports into Wren Shop import files: products (with photos and each product's old address), and optionally customers (with their password hashes), past orders and redirects from the old shop's sitemap, with a report of anything that doesn't map. See [Moving from WooCommerce](https://github.com/Wren-CMS/wren-shop/wiki/Moving-from-WooCommerce) in the guide.

## Demo products

To show the shop off with sample products (as on wrencms.com), copy `demo/media/demo/` to `shop/media/demo/` **before** the first visit to `/shop/`. A fresh install that finds those pictures fills itself with six sample products and starts in demo mode. Switch demo mode off under **Settings → Shop**.

## Licence

MIT. Copyright (c) 2026 Forty Nine Systems Ltd. Developed by Alan Paynter.

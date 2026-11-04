# Wren Shop

A small, free online shop that sits beside a [Wren CMS](https://wrencms.com) website.

Wren Shop lives in its own `/shop/` folder and borrows your Wren site's theme and menu, so the shop looks like part of the site. Wren itself is never changed, and its database is never opened: remove the `shop` folder and Wren is exactly as it was.

It's one PHP file and a SQLite database, made for small UK businesses.

- Products with photos, options (sizes, colours) with their own prices and stock, and categories
- Basket with UK delivery, free delivery over an amount, or collection
- Payment by **PayPal**, **bank transfer** or **pay on collection**
- VAT shown for VAT-registered shops
- Order emails over your own SMTP, packing slips, refunds through PayPal
- Customer accounts (guest checkout always works), discount codes, and customer groups such as reseller prices
- **Digital downloads**, including Wren themes licensed per website, with renewals for updates
- Low-stock emails, CSV export for bookkeeping, and a tool for customers' data requests
- An admin that works on a phone, with two-factor sign-in and a "Getting ready" checklist for launch or handover

**Demo:** [wrencms.com/shop](https://wrencms.com/shop/)
**Owner's guide:** [the wiki](https://github.com/Wren-CMS/wren-shop/wiki)

## Requirements

- A Wren CMS site
- PHP 8.1 or newer with SQLite (PDO), GD, cURL and mbstring
- Apache or LiteSpeed (nginx works with two extra rules; see the guide)
- An SSL certificate (`https://`)

## Installing

1. Download the latest release and unzip it.
2. Upload the `shop` folder (with its `.htaccess` file) next to Wren's `index.php`.
3. Visit `/shop/admin`. Open `shop/data/setup-code.php` in your hosting file manager, copy the code, and create the admin account.
4. Work through **Settings → Getting ready checklist**.
5. Add a menu link to `/shop/` in Wren.

## Upgrading

Upload only the new `shop/index.php`, replacing the old one. Never replace the `data` or `media` folders. Then open the shop admin once.

Upgrading from 0.12.0 or earlier: the shop now learns its web address from your signed-in visit to the admin, so it uses a plain version of your theme and sends no emails until you've opened the admin once.

## Demo products

To show the shop off with sample products (as on wrencms.com), copy `demo/media/demo/` to `shop/media/demo/` **before** the first visit to `/shop/`. A fresh install that finds those pictures fills itself with six sample products and starts in demo mode. Switch demo mode off under **Settings → Demo mode**.

## Licence

MIT. Copyright (c) 2026 Forty Nine Systems Ltd. Developed by Alan Paynter.

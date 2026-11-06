# Changelog

## 1.2.0 (6 November 2026)

**Catalogue foundations: Wren Shop for thousands of products, without making it harder for five.**

- **A new admin layout**: full width, with the sections down the left and each section's pages as tabs along the top. Settings is split into tabs (Getting ready, Shop, Delivery, Payments, Email, Business and VAT, Your sign-in, Advanced), and saving brings you back to the same tab. On a phone the sections become a bar across the top.
- **Paged, sortable lists**: the shop, each category and search show 24 products at a time (a setting, 6 to 96), with page links and a sort menu once there are a dozen. The admin product list shows 50 at a time with search (by name or SKU) and filters for category, hidden, low or sold out, sold out, on backorder and on sale.
- **SKUs** on products and on each choice, unique, shown on the product page, basket, orders, emails, packing slips, invoices and the orders CSV. Orders keep the SKU as it was.
- **Categories inside categories**, three levels deep, with dropdown menus in the shop (an expanding list on phones), sub-category links on category pages and full breadcrumbs. A product can show in several categories. Group rates set on a category cover the ones inside it.
- **Sale prices** on products and on choices with their own price, with optional first and last days; they start and end by themselves. A Sale badge, the full price crossed out, and sorting by price uses them. Sale prices, group prices and discount codes never add up: the customer gets whichever is cheapest, and the basket says which.
- **Backorders**: a product can keep selling when its counted stock runs out, quietly or telling customers what's on backorder. Orders record how many were on backorder; cancelling or refunding puts stock back correctly; low-stock emails skip these products.
- **Product import and export** (Products → Import and export): a spreadsheet of every product, or one row per choice. Import matches by SKU, then web address; blank cells leave values alone and `-` clears them; missing categories are made from paths. Every import is checked first and shows each change before and after and every problem by row number, then applies all at once or not at all. Also `tools/woo-to-wren.php` for moving from WooCommerce.
- **Search** in the shop menu once a shop has 12 products: names, SKUs, categories and descriptions, the start of a word is enough, exact part numbers first (and straight to the product when there's only one), and a synonyms setting. Full-text search where the host has it, a simpler search where it doesn't.
- **VAT invoices** for paid orders, from the admin and the customer's order page, printable or saved as PDF, adding up to the order total to the penny. Shops not registered for VAT get receipts.
- The product form has two columns on wide screens, with Save, Show in the shop, category and stock in a panel that stays in view.
- The demo shows the new features: categories, SKUs, a sale and a backorder.
- Upgrading needs nothing but the new `index.php`: everything is kept as it was.

## 1.1.0 (4 November 2026)

**Digital downloads and Wren theme licences.**

- Sell downloads: a product can be a zip file instead of something to post. Downloads-only orders skip delivery, and customers find their files in their account as soon as they've paid.
- Wren themes can be licensed for one website. Each copy bought gets a licence key; customers choose and change the website in their account (up to 3 moves in 30 days, all logged). Licences include 12 months of updates (you choose), with a paid **Renew** for more.
- Licences are signed with a key your shop makes for itself, and Wren 1.11 checks them and renews them by itself, so refunds and moves take effect. Only themes marked as licensed are checked; people's own and free themes never need one.
- **Customers → Licences**: search, change website, extend updates, cancel or reinstate, and each licence's history. Refunding an order cancels its licences; refunding a renewal takes its months back.
- Checkout records the customer's agreement to download straight away (UK rules on cancelling digital content).
- Buying a download needs a customer account.
- A deleted customer's number is never given to a new account.

## 1.0.0 (4 November 2026)

First public release.

- The shop puts back its own `shop/.htaccess` if it's missing.
- Owner's guide published on the wiki; **?** Help link in the admin menu.

## 0.13.1
- Help link in the admin; tidier admin menu spacing.

## 0.13.0
- **Export orders to CSV**: one row per order or per item, by date range, paid only or all orders.
- **Find a person's data**: see, export (JSON) or anonymise what the shop holds about one customer. Open orders are never touched; paid orders are kept unless chosen.
- **Proxy or CDN** setting for Cloudflare and other proxies: real visitor addresses for limits, and https detection.

## 0.12.2
- Fallback theme footer fixed; Settings explains why the site's look couldn't be copied; `www.` and non-`www.` treated as the same site.

## 0.12.1 (security release)
Following an independent security review:
- The shop's web address is learnt from the owner's signed-in visit and never taken from visitors' requests (fixes link and page poisoning through the Host header).
- Confirming an email address needs the account holder signed in and pressing a button; reset and confirmation tokens are kept out of the address bar.
- Setup code stored in `data/setup-code.php`, which can't be read from the web even where `.htaccess` is ignored.
- Admin "sign out everywhere", password changes and two-factor changes really end other sessions.
- Order and sign-in limits; unpaid bank transfers lapse after a set number of days (with **Restore order**); "once per customer" codes can't be dodged with `+tags` or Gmail dots.
- PayPal payments taken on an order cancelled at the same moment are recorded and the owner emailed.
- Account emails' links not kept after sending; order email contents cleared after 90 days.
- Security headers, file permissions, no error details shown to visitors; checklist items for https and a privacy notice.

## 0.12.0
- Self-check that warns if the data folder can be downloaded.
- **Getting ready** checklist for launch and handover.

## 0.11.1
- Admin buttons no longer touch the field above.

## 0.11.0
- **Customer groups** (for example resellers): a main discount with per-category rates; never stacks with a discount code.

## 0.10.0
- **Discount codes**: percentage, amount and/or free delivery, with minimum spend, dates, use limits and once per customer.
- **Low-stock emails.**
- **Demo mode** becomes a setting.

## 0.9.0
- **Customer accounts**: register, sign in, saved addresses, order history, password reset, email change, account deletion; guest checkout always available.
- Shop version shown in the footer.

## 0.8.0
- **Order emails** over SMTP, with an outbox and automatic retries; packing slips.

## 0.7.0
- **Checkout** with billing and delivery addresses; payment by PayPal, bank transfer or on collection; order pages; orders in the admin with refunds through PayPal.

## 0.6.0
- Product **options** (such as sizes) with their own prices and stock; **postage**, **collection** and **VAT**.

## 0.5.0
- Quantities and an editable basket.

## 0.4.0
- Shop menu (categories) and breadcrumbs.

## 0.3.0
- Products with several photos, categories and stock; basket; the shop admin with two-factor sign-in; demo products.

## 0.2.0
- Products and the shop admin's beginnings.

## 0.1.0
- Proof of concept: the shop borrows Wren's own page as its frame.

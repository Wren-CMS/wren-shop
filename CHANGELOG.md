# Changelog

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

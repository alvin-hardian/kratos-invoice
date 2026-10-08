# Kratos Invoice — Quotation & Invoice App

Slim 4 + Twig + MySQL (PDO) + Tailwind CSS (compiled, no Node) + Grid.js + Tom Select + vanilla JS + Dompdf. Server-rendered, no Node build.
Single company, IDR only. Money stored as whole rupiah (BIGINT); quantities/multipliers DECIMAL(10,3).

## Requirements
- PHP >= 8.3 (verified on 8.4) with pdo_mysql, mbstring, gd, intl
- MySQL 8 (dev: 127.0.0.1:3306, root/root, database `kratos_invoice` auto-created)
- Composer

## Quick start (built-in server)
```
composer install
copy .env.example .env   # adjust DB_* if needed
php bin/migrate.php
php bin/create-user.php "Admin" admin@local.id admin123
php bin/seed-demo.php    # optional demo data
run.bat                  # starts PHP server on http://127.0.0.1:8099
```
Open http://127.0.0.1:8099/login

## Frontend (Tailwind, no Node)
- Styles are compiled with the standalone Tailwind binary: `assets/app.css` → `public/css/tailwind.css`.
- Recompile after changing templates/JS classes: `bin\tailwindcss.exe -i assets\app.css -o public\css\tailwind.css --minify`
- Local vendor libs (no CDN at runtime except none): `public/vendor/gridjs`, `public/vendor/tom-select`; fonts in `public/fonts`.

## Apache deployment
- DocumentRoot → `<project>/public`
- Enable mod_rewrite; `.htaccess` included
- Example vhost:
```
<VirtualHost *:80>
  ServerName invoice.local
  DocumentRoot "C:/KRATOS/kratos-invoice/public"
  <Directory "C:/KRATOS/kratos-invoice/public">
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
```
- Set `APP_ENV=prod`, `APP_DEBUG=0` in `.env`; ensure `public/uploads` writable; store fonts in `public/fonts` (Inter + Oswald included).

## Key workflows
- Quotations: new → save draft (sections required, every line in a section) → issue (QT-2608001) → accept/reject/cancel → convert (one invoice per quotation, duplicate-safe) → invoice draft → issue (INV-2608001).
- Editor: customer select + inline create, reusable-product insert (defaults only, free unit-price/qty/unit/mult edit; totals always computed), per-line multiplier toggle, waived checkbox (Rp0 + Waived/Compliment/Included label, basis hidden in PDF), document discount (fixed/% pre-tax), DP terms (%/fixed), multi-bank select with preview, live totals.
- Invoices: manual payments (dp/balance/other) with overpay + dp-cap protection (transactions), void with reason, cancel blocked with active payments.
- Summary: date range + customer filter, full-dataset aggregates, invoice table + CSV export with same filters.

## Calculation policy
Line = waived ? 0 : round(qty × [mult ×] price) → subtotal → discount pre-tax (proportional allocation, largest remainder) → exclusive tax per rate → total. DP % computed on final total. Backend authoritative; JS mirrors it.

## Tests
```
vendor/bin/phpunit tests --no-coverage
php tests/e2e_verify.php   # draft→issue→accept→convert→issue→payments→snapshots→PDF
```

## Importing history from Bookipi
One-way migration of your existing Bookipi data (customers, items, all invoices + payments) via the official Bookipi CLI.
- IDR only; original Bookipi numbers (e.g. `INV-650`) are preserved as frozen issued history; our `INV-YYMMNNN` counter is untouched. Voided → cancelled, drafts → drafts.
1. Install Node 22+, then in a scratch folder:
```
git clone https://github.com/Bookipi-Group/bookipi-cli.git ~/bookipi-cli
node ~/bookipi-cli/skills/bookipi-cli/bin/bookipi.js init   # browser OAuth sign-in
```
2. Dump JSON (`invoice list` already includes lines, payments, and totals — no per-invoice fetch needed; repeat `-p 2…` with `--limit 200` if you have more than 200 rows and merge the arrays):
```
bookipi invoice list --limit 200 --json > invoices.json
bookipi customer list --limit 200 --json > customers.json
bookipi item list --limit 200 --json > items.json
```
Save as `<dir>/invoices.json` (array; `invoice_details.json` also accepted), `<dir>/customers.json`, `<dir>/items.json`.
3. Import (dry-run first):
```
php bin/import-bookipi.php --dir <dir> --dry-run
php bin/import-bookipi.php --dir <dir> [--actor <user-id>]
```
- Idempotent: re-running skips existing numbers. Source-vs-recomputed total mismatches and non-IDR records are reported, never silently altered (`import-report.json` is written to the dir).
- Sample input shapes: `tests/fixtures/bookipi/`.

## Notes / limitations
- One internal-user role; sessions + CSRF on mutations; logo validated (PNG/JPG/WebP ≤2MB).
- Commercial documents only — not e-Faktur tax invoices. Tax rates configurable (seed: No tax, PPN 12%, PPN 11%, PPh 23 2%).
- Timezone Asia/Jakarta, UTF-8 throughout.

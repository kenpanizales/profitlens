# Profit Lens — MySQL → PostgreSQL migration + Render deploy guide

## Ano ang ginawa

Wala kang kailangang i-edit sa 10 malalaking PHP files mo (`dashboard.php`,
`products.php`, `profit.php`, `expenses.php`, `reports.php`, `revenue.php`,
`export_excel.php`, `register.php`, `index.php`, `system_reset.php`). Lahat
sila tumatawag pa rin ng parehong pamilyar na `$db->query()`,
`$db->prepare()`, `$stmt->bind_param()`, `->fetch_assoc()`, atbp.

Ang ginawa: dalawang bagong file lang sa `includes/`:

- **`includes/db_compat.php`** — isang "compatibility shim" na gumagaya sa
  mysqli API pero PostgreSQL (via PDO) ang totoong ginagamit sa likod.
  Automatic nitong ina-translate ang MySQL-only syntax papuntang
  PostgreSQL bago patakbuhin ang bawat query: `YEAR()`, `MONTH()`,
  `DATE_FORMAT()`, `CURDATE()`, `DATE_SUB()/DATE_ADD()`, backtick
  identifiers, `SHOW COLUMNS`, `INSERT IGNORE`, `AUTO_INCREMENT`,
  `DATETIME`, `ON DUPLICATE KEY UPDATE`, atbp.
- **`includes/config.php`** — binago para kumonekta sa PostgreSQL sa halip
  na MySQL (gamit ang `DATABASE_URL` env var na ibibigay ni Render).

May isang file lang na talagang na-edit ang logic: **`system_reset.php`**.
Yung "Reset" feature nito gumagamit ng `SET FOREIGN_KEY_CHECKS=0/1` bago
mag-TRUNCATE — walang ganito sa PostgreSQL. Pinalitan ko ng
PostgreSQL-native approach (nag-nu-null muna sa `sales.product_id` bago
i-truncate ang `products` kung hindi kasama ang `sales` sa reset, at
`DEFERRABLE` foreign key para gumana pa rin ang "Restore Backup" kahit
anong pagkakasunod-sunod ng pag-restore).

## 1. I-setup ang PostgreSQL database sa Render

1. Sa Render dashboard: **New → PostgreSQL**. Piliin ang region na pareho
   ng gagamitin mong Web Service (mas mabilis at libre ang internal
   connection).
2. Kapag gumana na, buksan ang database → **Connect** tab. Kopyahin ang
   **Internal Database URL** (kung parehong region ang web service at DB)
   o **External Database URL** (kung gusto mong ikonekta mula sa sarili
   mong computer, e.g. para i-import ang schema).

## 2. I-import ang schema

Gamit ang `schema_postgresql.sql` na kasama sa ZIP na ito. Halimbawa gamit
ang `psql` (kung naka-install sa computer mo, o gamitin ang isang GUI tool
gaya ng pgAdmin/DBeaver/TablePlus at i-paste na lang ang laman ng file):

```
psql "<External Database URL mula sa Render>" -f schema_postgresql.sql
```

Kasama na dito ang lahat ng tables (`products`, `sales`, `expenses`,
`deleted_expenses`, `category_budgets`, `system_backups`, `users`) at ang
existing user accounts mo (parehong password hashes — gagana pa rin ang
mga existing login mo, walang kailangang i-reset).

## 3. I-deploy ang code sa Render

1. **New → Web Service**, ikonekta ang repo/folder na ito (o i-upload
   diretso kung gumagamit ka ng static/manual deploy).
2. Environment: PHP. Siguraduhing naka-enable ang `pdo_pgsql` extension
   (default na naka-enable ito sa halos lahat ng PHP runtime images ni
   Render — kung Docker-based deploy ang gagamitin mo, siguraduhin lang na
   naka-install ang `php-pgsql` package).
3. Sa **Environment Variables** ng Web Service, i-add:
   - `DATABASE_URL` = yung **Internal Database URL** mula sa Step 1.
4. Deploy. Yung `includes/config.php` awtomatikong babasahin ang
   `DATABASE_URL` at kokonekta sa Postgres — walang kailangang i-edit pa.

## 4. Lokal na pagsubok (opsyonal, bago mag-deploy)

Kung gusto mong subukan muna lokal gamit ang PostgreSQL sa sarili mong
computer (sa halip na MySQL/XAMPP):

1. I-install ang PostgreSQL lokal, gumawa ng database (hal. `gdr`).
2. I-import: `psql -U postgres -d gdr -f schema_postgresql.sql`
3. I-set ang environment variables bago patakbuhin ang PHP built-in server
   (o i-edit lang ang fallback values sa loob mismo ng
   `includes/config.php`):
   ```
   DB_HOST=localhost
   DB_PORT=5432
   DB_NAME=gdr
   DB_USER=postgres
   DB_PASS=<password mo>
   ```

## 5. Ano ang susuriin pagkatapos mag-deploy

- Mag-login gamit ang existing admin account mo.
- Dashboard — tignan kung tama ang mga numbers (sales/expenses this month,
  chart, best seller).
- Add/Edit/Delete ng Product, Sale, Expense.
- Reports page (mismatch check ng Year/Month filters).
- Export to Excel (lahat ng report types).
- **System Reset** — ito yung pinaka-binagong logic, kaya subukan mo muna
  gamit ang test data (hindi importante): i-reset ang isang dataset, tapos
  i-restore ito ulit mula sa Backup History, at siguraduhing tama ang mga
  bilang.

Kung may error kang makikita, karamihan lalabas ito bilang PHP warning na
nagsisimula sa `SQL error:` — ipadala mo lang sa akin ang buong mensahe
(kasama yung query text na kasunod nito) at aayusin ko agad.

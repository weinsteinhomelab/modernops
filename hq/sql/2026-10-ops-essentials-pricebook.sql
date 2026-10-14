-- Ops Essentials: rename the CRM / quoting / inbox items in the Modern Ops pricebook.
-- One-off. Run ONLY after approval, against the Modern Ops HQ database:
--   docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < sql/2026-10-ops-essentials-pricebook.sql
-- Each UPDATE matches on id AND the current title, so running it twice (or on a
-- different database) changes nothing.
-- Prices: Ops Customers keeps today's prices. Ops Quotes setup is a PLACEHOLDER ($250).
-- The Ops Essentials bundle is NOT added here (not final yet).

START TRANSACTION;

UPDATE catalog_items SET title = 'Ops Customers Setup',
       description = 'Ops Customers set up for your business: customer list, website requests and contact history'
 WHERE id = 11 AND title = 'CRM Creation';

UPDATE catalog_items SET title = 'Ops Customers Monthly (per seat)',
       description = 'Hosting, updates and support for Ops Customers, per user'
 WHERE id = 12 AND title = 'CRM Per Seat';

UPDATE catalog_items SET title = 'Ops Quotes Setup', unit_price = 250.00,
       description = 'Ops Quotes set up with your branding, terms and price list'
 WHERE id = 13 AND title = 'Quoting Tool Setup';

UPDATE catalog_items SET title = 'Ops Quotes Monthly',
       description = 'Hosting, updates and support for Ops Quotes'
 WHERE id = 14 AND title = 'Quoting Tool Usage';

UPDATE catalog_items SET title = 'Ops Inbox Setup',
       description = 'Your business email connected to Ops Inbox'
 WHERE id = 15 AND title = 'Email Inbox Setup';

UPDATE catalog_items SET title = 'Ops Inbox Monthly',
       description = 'Hosting, updates and support for Ops Inbox'
 WHERE id = 16 AND title = 'Email Inbox Usage';

COMMIT;

SELECT id, title, unit_price, billing FROM catalog_items WHERE id BETWEEN 11 AND 16 ORDER BY id;

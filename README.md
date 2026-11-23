# Cabinet collectible auctions

A PHP and MySQL/MariaDB auction website for collectibles. Users can browse lots, publish items, place competitive bids, follow auctions, receive notifications and complete orders.

## Requirements

- PHP 8.1 or later, with PDO MySQL, mbstring and fileinfo enabled.
- MySQL or MariaDB with InnoDB and utf8mb4 support.
- Either the PHP command line (for the built-in local server) or MAMP, XAMPP or an equivalent Apache/PHP/database environment.

Tested with PHP 8.6 and MariaDB 13 on macOS.

## Quick start from the command line

Run these from this directory with the database server already running. The `php` command must be the same PHP build (with PDO MySQL) that will serve the site; with MAMP, use its PHP executable. The `mysql` client must also be on your path (MAMP keeps it in its own `Library/bin` folder); alternatively create the empty database in phpMyAdmin.

```sh
cp config/database.example.php config/database.php
# Edit config/database.php: host, port, database name, user and password.
mysql -u root -p -e "CREATE DATABASE auction_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php tools/install.php
php tools/seed_demo.php --demo
sh serve.sh
```

Open `http://127.0.0.1:8088` and sign in with one of the demonstration accounts listed below. `config/database.php` and everything in `runtime/` are local-only and excluded by `.gitignore`.

## Installing with phpMyAdmin and MAMP/XAMPP

1. Put this directory in your web root, for example a `cabinet` folder inside `htdocs`. Start the web and database servers.
2. In phpMyAdmin create a **new empty** database, for example `auction_db`, using utf8mb4.
3. Copy `config/database.example.php` to `config/database.php` and enter the database host, port, name, username and password. MAMP and XAMPP use different default ports and credentials.
4. Import `database/schema.sql`, then `migration_001_extensions.sql`, `migration_002_handoff.sql` and `migration_003_after_sales.sql` from the same directory, in that order. A complete installation has 20 tables. (`php tools/install.php` does the same and refuses to run on a database that already has tables.)
5. Run `php tools/seed_demo.php --demo` to add the demonstration data.
6. Open the site through your server URL, for example `http://localhost:8888/cabinet/`, using the web port from your MAMP/XAMPP settings.

To upgrade an existing database, back it up and run `php tools/migrate.php`. Migrations 002 and 003 can be applied again safely after an interrupted upgrade.

## Demonstration data

`php tools/seed_demo.php --demo` only runs on a database with no users. It creates eight live lots, three labelled status samples (sold, unsold because the reserve was not met, and upcoming) and four accounts:

| Username | Password | Purpose |
| --- | --- | --- |
| mira | CabinetDemo2026! | Seller |
| noah | CabinetDemo2026! | Bidder |
| ella | CabinetDemo2026! | Competing bidder |
| `123456` | `123456` | Test account with watched lots, bids, won and sold lots already in place; also accepts `123456@art.com` at sign-in |

Every account can switch between buyer and seller mode. None of the sample lots represents a real sale. Replace this data before using the application with real users.

Other seed options for an existing demonstration database:

- `--test-account` adds only the `123456` account.
- `--profile-samples` adds that account's watched lots, bids and orders once; rerunning keeps later changes.
- `--status-samples` adds the three labelled status samples.
- `--spotlight-samples` adds the iPhone and MacBook sample lots.

`database/seed.sql` is a smaller sample for the ten base tables only. Import it right after `schema.sql`, then run `php tools/migrate.php`. Its accounts cannot sign in; register a new account or use `--test-account`.

## Local development server

`sh serve.sh` starts the site on `http://127.0.0.1:8088`. Pass another port if that one is busy, for example `sh serve.sh 8090`. Set `PHP_BINARY` if PHP is not on your path. The launcher allows 5 MB per photo and 32 MB per request, enough for a five-photo listing. On MAMP/XAMPP set the same limits in the active PHP settings; `.user.ini` covers CGI/FPM setups that read it.

`router.php` serves public assets and blocks configuration, source, database, tool and runtime paths. On Apache the `.htaccess` files do the same and need AllowOverride enabled.

The environment variables `AUCTION_DB_HOST`, `AUCTION_DB_PORT`, `AUCTION_DB_NAME`, `AUCTION_DB_USER` and `AUCTION_DB_PASSWORD` override `config/database.php`. `AUCTION_RUNTIME_DIR` moves runtime output outside the web root. Never commit database credentials or runtime files.

## Checks

```sh
find . -name '*.php' -not -path './runtime/*' -exec php -l {} \; | grep -v '^No syntax errors'
mysql -u root -p auction_db < database/check.sql
```

The first command prints nothing when every file parses. The second lists the tables (20 after a full installation) and a summary of auctions and results.

## Auction rules

- Prices are GBP with up to two decimal places. Bid validation uses integer pence.
- The first bid can equal the starting price. Each later bid must be at least the highest bid plus the minimum increment.
- A seller cannot bid on their own lot, including after switching roles.
- Bidding and closing lock the same auction row, so two simultaneous bids, or a bid arriving as the auction closes, are applied one at a time. Expired, scheduled and closed lots cannot receive bids.
- At closing, the highest bid wins only if the reserve is met. No bids or a missed reserve produce `unsold`, with no result or transaction.
- After an unsold round the seller can choose **Relist this collectible** and set new terms. The item, images and handover options carry over; the bid history starts fresh. An item can have only one open or upcoming round, and a sold item cannot be relisted.
- Only a successful outcome creates a transaction. The winning buyer chooses one of the seller's handover methods.
- Payment, dispatch and receipt are separate, permission-checked states. The payment step records a status only and moves no money.
- Buyer and seller can message each other on the private order page. For tracked delivery the buyer enters the address and the seller records the carrier and tracking reference; for collection they agree a place and time. Only the two parties can see these details.
- After payment either party can open an after-sales case. The other party can reply, and the requester can mark it resolved or withdraw it. Each party can review the other once after receipt.
- Times are stored in UTC and shown in the viewer's browser timezone.
- The reserve amount is visible only to the seller. Others see whether a reserve exists and whether it has been reached.

## Notifications and scheduled work

New bids, outbids and closing outcomes create notification records. Buyers choose whether to be told about outbids and watched lots. Sellers choose immediate, hourly, daily or no new-bid email.

`sh serve.sh` also runs a worker that closes due auctions and processes notifications every five seconds. It writes email as `.eml` files in `runtime/outbox` and marks them `saved`. Under Apache, schedule `php tools/maintenance.php --once` every minute instead, using absolute paths.

For real email, schedule `tools/close_auctions.php` and `tools/deliver_notifications.php`, set `MAIL_TRANSPORT=mail` and a valid `MAIL_FROM`, and configure PHP's `mail()` on the server. Only successful submissions are marked `sent`.

## Recommendations

The site finds other users who bid on the same auctions as the current user and ranks their other active auctions. If there are fewer than four such matches, it fills the remaining places from the user's preferred categories. The user's own lots and lots they already bid on are excluded, and each recommendation shows why it appears.

## Code map

| Area | Files | Tables |
| --- | --- | --- |
| Request handling | `index.php` (form actions and page routing), `router.php` | |
| Database, accounts, sessions, CSRF | `src/bootstrap.php` | `users` |
| Auctions, bids, closing, notifications, email | `src/services.php` (`create_auction`, `place_bid`, `close_due_auctions`) | `items`, `item_images`, `auctions`, `bids`, `auction_results`, `notifications`, `notification_settings` |
| Pages | `src/views.php`, `src/spotlight.php`, `src/listing_controls.php`, `src/account_menu.php`, `src/account_feed.php` | |
| My collection | `src/dashboard.php` | `watches`, `user_preferences` |
| Relisting | `src/relisting.php` | `auctions` |
| Orders and handover | `src/order_handoff.php` | `transactions`, `transaction_handoffs`, `transaction_messages`, `delivery_methods`, `auction_delivery` |
| After-sales | `src/after_sales.php` | `after_sales_requests`, `after_sales_events`, `reviews` |
| Languages | `src/i18n*.php` | |
| Schema and seed data | `database/`, `tools/` | |
| Styles and scripts | `assets/` | |

Add schema changes as a new numbered migration rather than editing earlier ones.

## Not yet implemented

Qualification checks, deposits, automatic proxy bids, referral discounts, distance-based recommendations and penalties for low ratings need business rules first. Payments, delivery confirmations and after-sales outcomes are recorded locally only; real charges, refunds and carrier tracking need separate services.

## Appearance

The header switches between 中文 and English, and the browser remembers the choice. Interface text, dates and the sample catalogue are bilingual; user listings and comments stay as written.

The homepage rotates up to five available lots every 5.5 seconds and refreshes bid totals every eight seconds while visible. The background is a canvas particle animation that pauses in hidden tabs and stops when the system's reduced-motion setting is on. The site works without canvas or browser storage.

## Third-party assets

See `SOURCES.md` for image and font credits and licences.

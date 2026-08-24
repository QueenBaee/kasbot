# Kasbot

Kasbot is a private Telegram financial assistant built with Laravel 13. It records salary, income, expenses, installments, and transfers in an auditable transaction ledger, then derives weekly budget periods and wallet balances from that ledger.

The bot accepts Indonesian Rupiah input such as `15000`, `15.000`, and `1,500,000`. Financial amounts are stored as integers.

## Features

- Secure Telegram webhook authentication and authorized-user lookup.
- Idempotent webhook processing with a transactional delivery outbox.
- Salary cycles from the 25th through the following month's 24th.
- Four calendar-based budget periods per salary cycle.
- Fixed and revolving installment tracking.
- Expense category matching using configurable keywords.
- Auditable reversal with `/undo` and `/undo_gajian`.
- Smart spending guidance, status, recap, projection, and CSV export.
- Scheduled period notifications, daily recaps, and salary reminders.
- Reliable Telegram delivery with retry and rate-limit handling.

`transactions` is the financial source of truth. Wallets and budget-period balances are rebuildable projections.

## Supported Commands

```text
/gajian <amount>
/masuk <amount> <description>
/tagihan <name> <amount>
/ambil_dingin <amount>
/undo
/undo_gajian
/input <amount> <description>
/status
/recap
/recap bulan
/proyeksi
/export
```

Expenses can also be entered directly:

```text
15000 bakso pak yud
25.000 kopi
```

## Requirements

- PHP 8.3 or newer with Laravel's required extensions.
- Composer.
- MySQL.
- Node.js and npm for the Vite asset build.
- An HTTPS URL reachable by Telegram in production.
- A Telegram bot created through BotFather.

Scheduled tasks use `Asia/Jakarta`. Financial dates use each user's configured timezone, which defaults to `Asia/Jakarta`.

## Installation

### 1. Install dependencies

```bash
git clone <repository-url> kasbot
cd kasbot
composer install
cp .env.example .env
php artisan key:generate
npm install
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

### 2. Configure MySQL

Create an empty database and update `.env`:

```dotenv
APP_NAME=Kasbot
APP_URL=https://your-public-domain.example

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kasbot
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

Run the migrations:

```bash
php artisan migrate
```

### 3. Configure Telegram

Generate a strong webhook secret and add these values to `.env`:

```dotenv
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_WEBHOOK_SECRET=your_random_webhook_secret
```

Reload configuration:

```bash
php artisan config:clear
```

Register the webhook with Telegram. Replace the placeholders locally; never commit or log the real values.

```bash
curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
  -d "url=https://your-public-domain.example/api/telegram/webhook" \
  -d "secret_token=<WEBHOOK_SECRET>"
```

Telegram sends updates to:

```text
POST /api/telegram/webhook
```

Only requests containing the configured `X-Telegram-Bot-Api-Secret-Token` are accepted.

### 4. Register authorized users

Kasbot has no public signup. Add each permitted account to the `users` table with:

- `telegram_user_id`: the Telegram user's numeric ID, not a chat ID.
- `name`: the user's display name.
- `timezone`: an IANA timezone such as `Asia/Jakarta`.

`telegram_user_id` is unique. Updates from unknown users are quietly ignored.

### 5. Build assets

```bash
npm run build
```

### 6. Run the scheduler

Laravel owns all internal schedules. Configure one OS cron entry:

```cron
* * * * * cd /absolute/path/to/kasbot && php artisan schedule:run >> /dev/null 2>&1
```

| Command | Schedule |
|---|---|
| `telegram:dispatch-outbox` | Every minute |
| `finance:notify-period-starts` | Daily at 00:00 Asia/Jakarta |
| `finance:gajian-reminder` | Daily at 08:00 Asia/Jakarta |
| `finance:daily-recap` | Daily at 20:00 Asia/Jakarta |

Verify the schedule with:

```bash
php artisan schedule:list --timezone=Asia/Jakarta
```

No separate cron entry is needed for each task.

## Local Development

Start the application processes:

```bash
composer run dev
```

For webhook testing, expose the local application through an HTTPS tunnel and register its public `/api/telegram/webhook` URL with Telegram.

Dispatch pending Telegram messages manually when needed:

```bash
php artisan telegram:dispatch-outbox
```

## Testing and Formatting

```bash
php artisan test --compact
vendor/bin/pint --format agent
```

Tests fake Telegram HTTP requests and never contact the real Telegram Bot API.

## Operational Notes

- Keep `.env`, Telegram credentials, database credentials, and generated exports private.
- CSV exports are stored under `storage/app/private/exports` and deleted only after confirmed delivery.
- Ensure `storage` and `bootstrap/cache` are writable by the application process.
- Run `php artisan optimize` during production deployment after configuring the environment.
- Delivery retries never rerun financial commands.
- Skipped scheduler runs do not corrupt financial state; scheduled tasks only notify and refresh derived projections.

## License

This project is built on the Laravel framework, which is licensed under the MIT License.

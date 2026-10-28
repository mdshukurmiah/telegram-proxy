# TG Relay — Telegram Bot API Relay for Laravel

A lightweight, stateless Laravel API that acts as a transparent proxy between self-hosted [n8n](https://n8n.io) and `api.telegram.org`.

## Why TG Relay?

If your self-hosted n8n instance runs on a network or server whose IP cannot directly reach `api.telegram.org` (e.g., regional ISP blocks, datacenter IP restrictions, or firewall policies), native Telegram nodes fail. 

**TG Relay** runs on any machine with outbound access to Telegram (such as a local computer, VPS, or proxy machine) and mirrors the Telegram Bot API structure 1:1. By updating only the **Base URL** in your n8n Telegram credentials, all existing Telegram nodes, file uploads (up to 50 MB), file downloads (up to 20 MB), and trigger workflows continue working seamlessly.

---

## Features

- **Generic Bot API Pass-Through**: Forwards every method (`sendMessage`, `sendPhoto`, `sendDocument`, `getMe`, etc.) without per-method boilerplate.
- **Full Body Format Support**: Transparently handles JSON, `x-www-form-urlencoded`, multipart file uploads, and query strings.
- **Streaming File Downloads**: Streams bot files from `api.telegram.org/file/bot<token>/<path>` in 8 KB chunks with zero in-memory buffering.
- **Telegram-Shaped Error Contract**: Every relay error returns Telegram-compatible JSON (`{"ok": false, "error_code": 502, "description": "Relay: ..."}`), preventing n8n parser crashes.
- **Strict Privacy & Token Masking**: Full bot tokens are masked to `<bot_id>:****` across logs and database rows. Request/response bodies are never stored.
- **Hardened Security**: Protected by a secret path prefix (`RELAY_PATH_PREFIX` ≥ 16 characters), bot ID allowlisting (`RELAY_ALLOWED_BOT_IDS`), optional `X-Relay-Key`, optional IP allowlists, and rate limiting (120 req/min per bot).
- **Phase 2 Inbound Webhook Rewriting**: Intercepts `setWebhook`, `getWebhookInfo`, and `deleteWebhook` so Telegram pushes updates to the relay, which queues and forwards them to n8n asynchronously.

---

## Quick Start (< 5 minutes)

### Prerequisites

- PHP 8.3+ with `curl`, `mbstring`, `sqlite3`, `intl`, `zip`
- Composer 2+

### 1. Installation

```bash
git clone <repo-url> tg-relay
cd tg-relay
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### 2. Configure Environment (`.env`)

Configure the required variables in `.env`:

```env
# Secret path prefix (minimum 16 random characters)
RELAY_PATH_PREFIX=r/your_secret_prefix_16chars_min

# Comma-separated allowlist of bot IDs (digits before the colon in your bot token)
RELAY_ALLOWED_BOT_IDS=123456789,987654321

# Optional security gates
RELAY_KEY=
RELAY_ALLOWED_IPS=
RELAY_RATE_LIMIT_PER_MIN=120
```

### 3. Run the Relay

Run the server with multiple workers to support concurrent requests from n8n:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8080
```

---

## Configuring n8n

### Option A: Native Telegram Nodes (Recommended)

1. In n8n, open or create a **Telegram API** credential.
2. Enter your bot token as usual.
3. Set **Base URL** to:
   ```text
   http://<RELAY_HOST>:8080/{RELAY_PATH_PREFIX}
   ```
   *Example:* `http://192.168.1.50:8080/r/your_secret_prefix_16chars_min`
   *(Ensure there is no trailing slash and no `/bot` at the end).*
4. Save the credential. n8n will test `getMe` through the relay and verify connectivity.

### Option B: HTTP Request Node (Fallback)

- **Method**: `POST`
- **URL**: `http://<RELAY_HOST>:8080/{RELAY_PATH_PREFIX}/bot{{$env.TG_BOT_TOKEN}}/sendMessage`
- **Body**: JSON `{"chat_id": "...", "text": "..."}`
- **Headers**: `X-Relay-Key: <key>` *(if `RELAY_KEY` is configured)*

---

## Inbound Webhooks (Phase 2 / Telegram Triggers)

To support n8n's **Telegram Trigger** node without exposing n8n directly to Telegram:

1. Enable webhook rewriting in `.env`:
   ```env
   RELAY_WEBHOOK_REWRITE=true
   RELAY_ALLOW_PRIVATE_TARGETS=true
   ```
2. Start the queue worker in a separate terminal:
   ```bash
   php artisan queue:work --tries=4
   ```
3. Expose port 8080 publicly via HTTPS (e.g. `cloudflared tunnel --url http://localhost:8080` or a reverse proxy) and set `APP_URL` to your public HTTPS address:
   ```env
   APP_URL=https://relay.yourdomain.com
   ```
4. Activate your Telegram Trigger workflow in n8n. The relay will rewrite `setWebhook` to point to `https://relay.yourdomain.com/{P}/hook/{bot_id}/{route_secret}`, validate incoming updates with `X-Telegram-Bot-Api-Secret-Token`, acknowledge Telegram immediately (within 100 ms), and dispatch queued background jobs to deliver the update to n8n.

---

## Health Checks & Diagnostics

- **Shallow Health**:
  ```bash
  curl http://localhost:8080/health
  # {"status":"ok","version":"1.0.0"}
  ```
- **Deep Upstream Reachability Check**:
  ```bash
  curl http://localhost:8080/{RELAY_PATH_PREFIX}/health/deep
  # {"status":"ok","telegram":"reachable","latency_ms":85}
  ```

---

## Testing

Run the automated test suite (36 tests covering all proxy methods, streaming file downloads, security gates, and webhook rewrite logic):

```bash
php artisan test
```

Run code style checks and security audit:

```bash
./vendor/bin/pint --test
composer audit
```

---

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).

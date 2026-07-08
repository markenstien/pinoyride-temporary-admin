# Customer Search — Vanilla PHP + Bootstrap

A minimal, dependency-free PHP app to search/filter the `public.customer`
table in your Postgres database. Connects through an SSH tunnel to your
prod DB — no framework, no Composer needed.

## Files

```
customer-app/
├── config.php          # Loads .env and opens a PDO (pgsql) connection
├── index.php            # Filter form + results table + pagination
├── includes/
│   ├── header.php        # Bootstrap CDN + page head/nav
│   └── footer.php         # Closing tags/scripts
├── .env.example          # Copy to .env and fill in real values
└── .gitignore             # Keeps .env out of version control
```

## 1. Requirements

- PHP 7.4+ with the **pdo_pgsql** extension enabled
  - Check with: `php -m | grep pdo_pgsql`
  - If missing (Windows/XAMPP): uncomment `extension=pdo_pgsql` in `php.ini`, then restart your server
- `ssh` client (built into Mac/Linux terminal; use WSL or PuTTY on Windows)

## 2. Open the SSH tunnel

In a terminal, keep this running while you use the app:

```bash
ssh -L 5433:localhost:5432 your_ssh_user@your-server-ip -N
```

- Change `localhost:5432` if Postgres is on a different internal host/port
  relative to the SSH server.
- `-N` means "just forward the port, don't open a shell." Leave the
  terminal window open — closing it closes the tunnel.
- Test it works: `psql -h 127.0.0.1 -p 5433 -U your_db_user -d your_db_name`

## 3. Configure the app

```bash
cp .env.example .env
```

Edit `.env`:

```
DB_HOST=127.0.0.1
DB_PORT=5433
DB_NAME=your_db_name
DB_USER=your_db_user
DB_PASS=your_db_password
```

`.env` is already in `.gitignore` — never commit real credentials.

## 4. Run it

Using PHP's built-in server (fine for local dev):

```bash
cd customer-app
php -S localhost:8000
```

Then open **http://localhost:8000** in your browser (make sure the SSH
tunnel from Step 2 is still running in another terminal).

## 5. Using the app

- **Created From / Created To** — filters `created_at` as an inclusive
  date range (the "To" date includes the full day up to 23:59:59).
- **Mobile / First Name / Last Name** — partial, case-insensitive match
  (e.g. searching "jos" matches "Jose", "Josie", etc.). All filters are
  optional and combine with AND — leave any blank to skip it.
- Filters are passed via the URL (`?mobile=...&fname=...`), so a search
  result page can be bookmarked or shared.
- Results are paginated 25 per page.
- Soft-deleted customers (`deleted_at IS NOT NULL`) are excluded
  automatically.

## Notes / things worth deciding next

- **Read-only DB user**: since this points at prod, ask for a DB user
  with `SELECT`-only privileges on `customer` if you don't already have
  one — this app currently only ever reads, but a read-only credential
  is a good safety net.
- **Deploying beyond your machine**: this is built for local dev via the
  tunnel. If you later host this app on a server, you'd instead run the
  tunnel *from that server* (or better, have the server reach the DB
  directly on an internal network) rather than exposing `.env` credentials
  more broadly.
- No authentication/login is included — this app is assumed to run only
  on your local machine, not to be deployed publicly as-is.

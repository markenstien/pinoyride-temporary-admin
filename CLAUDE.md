# pinoy-ride-admin

Vanilla PHP + Bootstrap admin panel for the Pinoy Ride app. No framework, no
Composer. Connects directly to the production Postgres DB (`riderapp`) over
an SSH tunnel — there is no separate staging DB, so treat every write here
as a real prod write.

This repo is **admin tooling only**. The actual customer/driver-facing apps
and their backend API live elsewhere and are not in this codebase — this
panel just reads/writes the same Postgres DB they use.

## Running it

- SSH tunnel must be up first: `other-scripts/tunnel.sh` (forwards local
  `5433` → `postgres-riderapp:5432` on the remote host). `.env` has
  `DB_HOST=127.0.0.1 DB_PORT=5433 DB_NAME=riderapp`.
- `config.php` loads `.env` and exposes `get_pdo()`.
- PHP built-in server: `php -S localhost:8000`.

## Terminology: customer = passenger, riders = driver

The `customer` table holds **passengers**, the `riders` table holds
**drivers**. This naming is inconsistent but fixed — `customer_edit.php` /
`customer_show.php` are the passenger pages, `rider_edit.php` /
`rider_show.php` are the driver pages.

## eKYC sync rule (important, easy to violate silently)

`customer` and `riders` both have an `ekyc_request_user_id` column that
joins to `top_ph_ekyc_details.generate_request_user_id` (column name
differs on each side — same value). This is a string match, not a real FK
constraint.

**Any time customer or rider details are updated (name, email, mobile,
address, gender, etc.), the matching `top_ph_ekyc_details` row must be
updated too**, or the two tables drift out of sync. See `customer_edit.php`
and `rider_edit.php` for the reference implementation (both update the
linked eKYC row inside the same transaction as the main record).

Schema reference dumps (`\d` output) live in `table/` — currently only
`table/customer.sql`. Add more there as you look them up rather than
re-guessing column names from app code.

## Booking lifecycle

`booking.status` (see `includes/booking_status.php`, single source of
truth):

| status | meaning            |
|--------|--------------------|
| 0      | Looking for Driver |
| 1      | Accepted by Driver |
| 2      | In Transit         |
| 3      | Complete Trip      |
| 4      | Cancelled          |

Tables that can be touched over a booking's life (not all on every
booking):

- `booking` — the trip itself.
- `booking_payment` — fare breakdown, created together with `booking` at
  creation time (one row per booking, `booking_id` FK). `commission` is the
  platform's cut, `rider_net_amount` is what the driver keeps.
- `booking_ratings` — one row, added after the trip ends (completed *or*
  cancelled — cancelled bookings with a rating are a red flag, see below).
- `wallet_history` — created when a **cash** trip completes: debits the
  driver's wallet for `booking_payment.commission` (the driver already
  holds the cash fare from the passenger, so only the platform's cut needs
  to move). NOT a credit of `rider_net_amount` — that money is already in
  the driver's hand in cash.
- `booking_issues`, `ignored_bookings`, `promo_availed` — conditional:
  issue reports, a driver declining a broadcast, promo code usage. Not
  populated on a typical booking.

`booking_show.php`'s "Update Status" action (admin manually marking a
booking Complete/Cancelled) now replicates the commission-debit step for
Complete Trip — see the `new_status === 3` block. Before 2026-08-10 it only
flipped `booking.status` and silently skipped the wallet debit, so
admin-completed trips never charged the driver commission. Fixed by
mirroring the pattern found in real completed bookings' `wallet_history`
rows (`type='debit'`, `tran_type='booking'`,
`description='Debit|Ref-<ref_code>'`).

**Known upstream bug (outside this repo, not fixed here):** across recent
bookings, ~17% of `status=3` (Complete Trip) bookings have no matching
`wallet_history` row — commission silently not charged on some completions.
Also saw one `status=4` (Cancelled) booking (id 523) that received a rating
0.9s after its status changed — the same timing pattern as a normal
completion-then-rate flow — suggesting it should have landed on status 3,
not 4. Both point to a bug in the real app/API's trip-completion logic, not
in this admin panel.

## Wallet mechanics

- `wallet` — one row per `(user_id, user_type, type)`. Drivers have
  `user_type='rider'`; the wallet used for commission debits is
  `type='user-wallet'` (there's also a `type='pr-user-wallet'` — different
  wallet, not used for this).
- `wallet.avail_balance` is a **cached** running balance
  (`credit_amount - debit_amount` accumulated); `wallet_history` is the
  source of truth ledger. When debiting/crediting, update both the
  `wallet` row's `avail_balance`/`debit_amount`/`credit_amount` *and*
  insert a `wallet_history` row — see `wallet_credit_create.php` and the
  commission-debit block in `booking_show.php` for the pattern.
- `wallet_history.booking_id` is `character varying`, not `integer` like
  every other `booking_id` FK in this schema — cast/compare as text when
  joining.

## Folder conventions

- `sql/` — standalone one-off query/update scripts (not run by the app).
  Any UPDATE script in here should end with a verify `SELECT`.
- `table/` — real `\d` schema dumps for reference, so future SQL doesn't
  have to guess column names from app code.
- `other-scripts/` — operational scripts (SSH tunnel, ad-hoc DB checks).
  Ad-hoc one-off inspection scripts should be deleted after use rather than
  left lying around here.

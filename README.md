# Tupay — Ledger & Settlement Engine

A cross-border financial engine moving money between Nigeria (NGN) and China (CNY),
built on a pure double-entry ledger with database-enforced invariants, step-up 2FA
bound to individual actions, and deterministic locking under concurrency.

**Stack:** PHP 8.2 · Laravel 11 · PostgreSQL · Redis · Pest · PHPStan level 8 · Pint

---

## Contents

1. [Quick start](#quick-start)
2. [Architectural decisions](#1-architectural-decisions)
3. [Step-up token hashing and invalidation](#2-step-up-token-hashing-and-invalidation)
4. [Deadlock prevention and lock acquisition order](#3-deadlock-prevention-and-lock-acquisition-order)
5. [Database indexing rationale](#4-database-indexing-rationale)
6. [Testing](#testing)
7. [Honest limitations](#honest-limitations)

---

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate

# PostgreSQL on :5433, Redis on :6379
php artisan migrate:fresh --seed

php artisan serve
```

Seeding prints three test users with fixed TOTP secrets. Password is `password`
for all of them.

| Email | Balance | Purpose |
|---|---|---|
| `trader@tupay.test` | 5,000,000 NGN / 20,000 CNY | General purpose |
| `whale@tupay.test` | 50,000,000 NGN | Crosses several slippage tiers |
| `thin@tupay.test` | 10,000 NGN | Funds exactly one swap — used by the race test |

TOTP codes rotate every 30 seconds, so they cannot be written into a collection:

```bash
php artisan tupay:totp trader@tupay.test
```

Then walk `api-test.http` top to bottom.

---

## 1. Architectural decisions

### Layering

```
app/
├── Domain/            Framework-free business rules
│   ├── Money/         Money, Currency, BankersRounding, Decimal
│   ├── Ledger/        LedgerException (owns the HTTP status contract)
│   ├── StepUp/        ActionHasher, ElevatedAction, StepUpException
│   ├── Swap/          SlippageCalculator, SwapQuote
│   └── Settlement/    SettlementStatus (ranked state machine)
├── Services/          Orchestration; talks to Redis, the database, the network
│   ├── StepUp/        ElevatedActionTokenService, TotpService
│   ├── Swap/          SwapService, DistributedLock
│   └── Rates/         RateProvider (stale-while-revalidate)
├── Http/              Thin controllers and middleware
├── Jobs/              ProcessSettlement, RefreshExchangeRate
└── Models/            Eloquent, deliberately behaviour-light
```

`Domain/` has no framework dependencies and holds the rules that must be true
regardless of transport. `Services/` coordinates I/O. Controllers validate,
delegate, and shape a response — the swap controller is under 60 lines because
every decision it might have made lives somewhere it can be tested directly.

### Money is a type, not an integer

Every amount is a `Money` value object wrapping **64-bit integer subunits**
(kobo, fen). Floats and `DECIMAL` never enter the arithmetic path.

The subtle part is rounding. PHP's `round()` routes through a float, and
`PHP_ROUND_HALF_EVEN` inherits the same binary representation problem; BCMath
has no rounding mode at all, because `bcdiv` truncates. So banker's rounding is
implemented directly on decimal strings in `BankersRounding`.

Half-even rather than half-up is a deliberate financial choice: rounding `.5`
consistently upward imparts a systematic bias that accumulates across millions
of conversions and leaves the ledger permanently long.

```
2.5 → 2      3.5 → 4      (ties go to the even neighbour)
-2.5 → -2   -3.5 → -4     (symmetric on negatives)
123.5 → 124  124.5 → 124  (adjacent ties diverge)
```

### The ledger has no balance column

`wallets` deliberately has **no `balance` column**. A balance is derived
exclusively from `ledger_entries.signed_amount`, a PostgreSQL **stored generated
column**:

```sql
signed_amount BIGINT GENERATED ALWAYS AS
    (CASE WHEN direction = 'credit' THEN amount ELSE -amount END) STORED
```

Putting the sign in the database rather than in PHP means the non-negative
trigger, the `wallet_balances` view, and any ad-hoc SQL all agree by
construction. A cached balance column would be a second source of truth, and
therefore a race condition waiting to be discovered.

### Two invariants, enforced by the database

A `CHECK` constraint sees a single row; both of these are aggregates over
sibling rows. Triggers are the only correct mechanism.

**No user wallet may go negative** — `AFTER INSERT`, not `BEFORE`. A `BEFORE`
trigger cannot see the very row that overdraws the wallet, so it would miss the
only case it exists to catch.

**Every transaction nets to zero** — `DEFERRABLE INITIALLY DEFERRED`, evaluated
at `COMMIT`. A posting is only balanced once every leg is written; checking
eagerly would reject the first `INSERT` of every legitimate pair. Netting is
computed **per currency**, so a cross-currency swap balances NGN and CNY
independently rather than summing them into a meaningless total.

**System wallets are exempt from the non-negative rule.** The house is the
counterparty to every user movement and necessarily carries the negative
position that a user's positive balance implies. Without a `user`/`system`
distinction, double-entry cannot balance at all.

**Ledger entries are append-only.** `UPDATE` and `DELETE` are rejected by
trigger; corrections are reversing entries. History is not editable.

### Idempotency, by layer

| Concern | Mechanism | Why |
|---|---|---|
| Swap replay | Single-use EAT (Redis `GETDEL`) | Two identical *deliberate* swaps are two legitimate swaps; only a replayed authorisation is an error |
| Webhook replay | `UNIQUE` on `provider_reference` | Only the database can make "exactly once" survive a cache flush |
| Ledger posting | `ledger_posted_at` under a row lock | Uniqueness locks expire; a retried job must still be a no-op |

---

## 2. Step-up token hashing and invalidation

### The token is both stateless and stateful

- **Stateless** — an HMAC-SHA256 signature over the payload proves authenticity
  and integrity with no lookup. A forged or edited token is rejected before
  Redis is touched at all.
- **Stateful** — a Redis key holds the single-use *right to spend it*. A
  signature alone can never express "already used": anyone holding a valid
  signed token could otherwise replay it until expiry.

The signing key is derived from `APP_KEY` via HMAC with a domain-separation
label (`tupay:eat:v1`), so an EAT signature can never be confused with any other
artefact signed by the same application key.

### What the hash covers

```
action_hash = SHA-256( canonical_json({
    action:     "swap",
    parameters: { source_currency, target_currency, amount_subunits },
    user_id:    <acting user>
}))
```

Canonicalisation is the whole game. If the challenge and the swap serialise the
same logical payload differently, every legitimate request 422s; if it is too
lax, materially different payloads collide and the binding means nothing. The
rules:

- object keys sorted recursively — key order carries no meaning
- scalars normalised to strings — `1000` and `"1000"` agree
- booleans become `"true"`/`"false"`, never `"1"`/`""`, so they cannot collide
  with integers
- **floats are rejected outright** — a float in a money payload is a bug
  upstream, and its textual form is platform-dependent
- the acting `user_id` is folded in, so one user's EAT cannot authorise another's

Both the challenge endpoint and the swap endpoint derive their parameters
through the same `ElevatedAction` enum. Building those arrays independently
would let them drift, which either breaks every request or silently widens what
a token authorises.

### Invalidation

```php
$storedHash = Redis::command('getdel', [$key]);   // atomic: read AND delete
```

`GETDEL` is one round trip. A `GET` followed by a `DEL` has a window in which
two concurrent replays both observe the token as unused — which is precisely
the bug the single-use property exists to prevent.

Order of checks, and why:

1. Signature (no I/O — reject forgeries cheaply)
2. Issuer, subject, expiry — **expiry is checked before the Redis call**, so an
   expired token cannot burn a key
3. `GETDEL` — atomic consume
4. Recompute the hash from the *live request* and compare with `hash_equals`

A mismatch after consumption still burns the token. That is intentional: the
spec requires invalidation on first read, and it prevents an attacker grinding
parameters against a live token.

### Status codes

| Situation | Status | Code |
|---|---|---|
| Missing / malformed / forged / expired / replayed | **401** | `elevated_token_*` |
| Valid token, wrong parameters | **422** | `elevated_token_action_mismatch` |

401 means "this is not a valid credential"; 422 means "valid, but it does not
authorise *this*". The spec permits either for a replay; the split makes the
failure diagnosable without telling an attacker which half they failed.

### TOTP replay protection (beyond the brief)

A TOTP code stays valid for its whole window plus drift — roughly 90 seconds —
so the same six digits could otherwise mint several EATs. Each accepted code is
burned in Redis via `SET NX` for the duration of its validity.

---

## 3. Deadlock prevention and lock acquisition order

### The failure being prevented

Two swaps touching the same wallets in opposite directions: A locks wallet X and
waits for Y; B locks Y and waits for X. Neither proceeds.

The fix is not cleverness, it is a **total order**. If every participant
acquires resources in the same sequence, a cycle cannot form.

```php
$lock->acquireAll([
    'user:'.$user->id,
    'wallet:'.$sourceWallet->id,
    'wallet:'.$targetWallet->id,
]);
// sort($keys, SORT_STRING) inside acquireAll — the total order
```

Wallet identifiers are **UUIDs** specifically so that "sorted in strict
alphabetical order" is meaningful. With auto-increment integers the requirement
would be incoherent.

### Full sequence

1. **Redis locks**, alphabetically sorted, `SET NX EX` — atomic acquisition with
   a TTL so a crashed process cannot wedge a wallet forever
2. **SQL transaction at `REPEATABLE READ`** — a stable snapshot, so the balance
   validated is the balance posted against
3. **`SELECT … FOR UPDATE`** on the participating wallet rows, ordered by id
4. Validate balance → post four legs → commit
5. **Release Redis locks in a `finally` block**, in reverse order,
   unconditionally

Steps 1 and 3 are not redundant. Redis rejects contenders cheaply and returns a
fast **409** without touching the database; the row lock is the authority that
still holds if Redis is unavailable, restarted, or a TTL lapses mid-swap. The
cheap check comes first.

### Locks are released by owner, not by key

Each lock carries a random token, and release is a Lua compare-and-delete:

```lua
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end
```

Deleting by key alone would let a process whose lock had already expired delete
a lock a *different* process has since acquired — corrupting exactly the
invariant the lock exists to protect.

### Fail fast, don't queue

`TUPAY_LOCK_BLOCK_SECONDS=0`. A waiter would still have to re-validate the
balance after acquiring, so blocking buys nothing but latency — and it is what
makes the concurrency test deterministic.

### Why the balance read locks the wallet row

`lockForUpdate()->sum()` is **invalid** in PostgreSQL — `FOR UPDATE` cannot be
combined with an aggregate. More fundamentally, locking existing entry rows
would not help: the hazard is a concurrent `INSERT` of a *new* entry, which no
lock on already-visible rows can prevent. The **wallet row** is therefore the
serialisation point.

### Error mapping

| Cause | Status |
|---|---|
| Redis lock held by another request | **409** `wallet_locked` |
| PostgreSQL `40001` / `40P01` (serialization failure, deadlock) | **409** `serialization_failure` |
| Balance insufficient (app check or DB trigger) | **422** `insufficient_funds` |

---

## 4. Database indexing rationale

### `GET /api/ledger/{wallet_id}` uses keyset pagination, not `OFFSET`

```sql
CREATE INDEX ledger_entries_wallet_id_id_index ON ledger_entries (wallet_id, id);
```

The query:

```sql
SELECT * FROM ledger_entries
WHERE wallet_id = ? AND id < ?     -- cursor
ORDER BY id DESC
LIMIT ?;
```

**Why this index.** Equality on the leading column (`wallet_id`), range scan on
the second (`id`), results already in index order. No sort step — and because
`signed_amount` participates in the balance `SUM` over the same index, that
aggregate is an index-only scan.

**Why not `OFFSET`.** A ledger is append-only and unbounded. `OFFSET 50000`
forces PostgreSQL to walk and discard fifty thousand rows before returning
anything — page 10,000 costs proportionally more than page 1. Keyset pagination
seeks directly into the index and costs the same at any depth.

**Correctness, not just speed.** `OFFSET` shifts rows between pages whenever a
new entry is inserted mid-traversal. On a live ledger that means a client can
see the same entry twice or miss one entirely. A cursor anchored to a monotonic
`id` is stable under concurrent writes — and the test asserts that consecutive
pages never overlap.

`per_page` is capped at 100.

### Other indexes

| Index | Serves |
|---|---|
| `ledger_entries (transaction_id)` | Fetching all legs of one posting; FK enforcement |
| `wallets (user_id, currency)` UNIQUE | One wallet per user per currency |
| `wallets (currency) WHERE type='system'` | Partial unique — exactly one house wallet per currency |
| `ledger_transactions (reference)` UNIQUE | Business idempotency handle |
| `settlement_events (provider_reference)` UNIQUE | Webhook idempotency — the authoritative guard |
| `ledger_transactions (user_id, created_at)` | Per-user transaction history |

---

## Testing

```bash
vendor/bin/pest --testsuite=Unit,Feature   # 118 tests, 266 assertions
vendor/bin/phpstan analyse                 # level 8, zero errors
vendor/bin/pint --test                     # style
```

| Suite | Covers |
|---|---|
| `Unit/MoneyTest` | Banker's rounding edge cases, BIGINT overflow, currency mixing, BCMath guards |
| `Feature/LedgerInvariantsTest` | The database triggers themselves, exercised via **raw SQL that bypasses the application** |
| `Feature/StepUpAuthTest` | The EAT contract: one action, one parameter set, one user, exactly once |
| `Feature/SwapTest` | Four-leg postings, tiered slippage, lock contention (409), lock release |
| `Feature/SettlementWebhookTest` | HMAC, replay window, idempotency, out-of-order delivery |
| `Feature/LedgerTest` | Keyset pagination, non-overlapping pages, ownership |
| `Stress/ConcurrentSwapTest` | Ten parallel swaps against a real HTTP server |

The ledger invariant tests deliberately use the query builder rather than the
swap service. Testing through the application would only prove the PHP check
works; what matters is that the invariants still hold against raw SQL, because
a migration or a future bug that skips the service must not be able to corrupt
the ledger.

### The race condition test

```bash
php artisan serve                    # terminal 1
vendor/bin/pest --testsuite=Stress   # terminal 2
```

Ten concurrent `POST /api/swap` for a user funded for exactly one. Asserts
**exactly one 200**, **exactly nine 409/422**, zero overdraft, and a ledger that
still nets to zero in every currency.

It runs against a **real HTTP server** on purpose. Laravel's in-process test
client executes requests sequentially inside one process, so a race cannot occur
there — a "concurrency test" written against it passes unconditionally and
proves nothing.

The test also **reports whether it actually raced**:

```
[concurrency] statuses={"200":1,"422":9} wall=16.738s
[concurrency] 409 lock-contention=0  422 insufficient-funds=9
[concurrency] WARNING: no lock contention observed — the server may have
              serialised these requests.
```

Client-side timing cannot answer this: if the server handles requests one at a
time, they still all *start* together and simply wait. The honest signal is the
shape of the failures. A **409** can only occur if another request held the lock
at that moment — proof of a real race. All-422 means the contenders ran after
the winner committed, which is what serialised execution looks like. Correct
either way, but only the former exercises the concurrency guard.

---

## Honest limitations

**The local stress run does not prove concurrency safety on Windows.**
`php artisan serve` is single-threaded unless `PHP_CLI_SERVER_WORKERS` is set,
and that variable is POSIX-only. On a Windows development machine the ten
requests are serialised and the diagnostic above will say so. The authoritative
run is the `concurrency` job in CI, on Ubuntu, with ten workers — that is where
409s appear.

**`REPEATABLE READ` is skipped when nested.** PostgreSQL requires
`SET TRANSACTION ISOLATION LEVEL` to be the first statement in a transaction and
rejects it on a `SAVEPOINT`. Under `RefreshDatabase` the suite holds an outer
transaction, so the isolation level is only issued when the swap is genuinely
the root transaction — always true in production, and in tests the `FOR UPDATE`
row locks still carry the correctness. The stress suite runs without
`RefreshDatabase` and therefore does exercise it.

**`predis` locally, `phpredis` in CI.** The C extension is not available on
Windows/XAMPP. All Redis access goes through `Redis::command()` rather than the
magic methods, because the facade is typed against phpredis whose `set()`
signature differs from the variadic protocol form — so the same code is correct
under both drivers, and CI proves it.

**The mock rate provider is served by this same application.** On a
single-threaded server, a swap that tries to fetch a rate waits on a request the
server cannot begin until the swap finishes. The stress test pre-warms the rate
cache, which is exactly what stale-while-revalidate exists to make possible. In
production the provider would be a separate host and the problem would not arise.

**Interpretation of the slippage tiers.** "0.5% base + 0.1% per additional
500,000 NGN" is implemented as whole tranches only: the tier increments when a
tranche is fully crossed. At exactly 1,500,000 NGN the rate is 60 bps; at
1,499,999 NGN it is still 50 bps. "Swaps exceeding 1,000,000 NGN" is read as
strictly greater than, so a swap of exactly 1,000,000 NGN is free.

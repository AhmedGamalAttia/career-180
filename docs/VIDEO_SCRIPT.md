# Video Script — Instructor Revenue Ledger (15–20 min)

Teleprompter-style guide. Each section has: **[SAY]** what to say, **[SHOW]** what
to put on screen, **[RUN]** commands for live demos. Timings are targets.

> Before recording: `php artisan migrate:fresh --seed` once so the Filament screen
> has data, and have two terminals + the editor + the browser ready.

---

## 1. Introduction — 2–3 min

**[SAY]**
- Who you are, years with Laravel.
- Any past work with payments / subscriptions / ledgers / financial flows /
  large-scale systems. *(Personalise — this is where you build credibility.)*
- The one sentence that frames everything: *"I optimised for the two things the
  brief said it cares about most — correctness of money and failure handling —
  not for feature count. So this is a small solution with strong guarantees."*
- Name your single biggest decision up front: *"The key call I made is recognising
  revenue on an **accrual** basis, which is what makes mid-term refunds safe
  without ever clawing back a payout. I'll show why."*

---

## 2. Architecture Walkthrough — 5 min

**[SHOW]** `docs/ARCHITECTURE.md` §1 diagram, then the migrations folder.

**[SAY] Domain model (30s)**
- Student buys a Subscription (a Plan), paid up front for the whole term via a
  SubscriptionPayment.
- The instructors that share in it are the distinct instructors behind the
  subscription's Enrollments.
- Money-in = `subscription_payments`; the split = `revenue_allocations`;
  money-out = `payout_batches` → `payouts` → `payout_attempts`.

**[SAY] Database design (1 min)** — **[SHOW]** a couple of migration files.
- *"Money is stored as integer piasters in BIGINT — no floats anywhere. The
  platform fee is basis points, so the fee is also pure integer maths."*
- Point at the **unique constraints**: `subscription_payments.idempotency_key`,
  `revenue_allocations (payment, instructor)`, `payout_batches.period_key`,
  `payouts (batch, instructor)`. *"Idempotency is enforced by the database, not
  just by code — constraints don't have race conditions."*

**[SAY] Revenue allocation strategy (1 min)** — **[SHOW]**
`app/Services/RevenueAllocationService.php`.
- Platform cut floored, pool = amount − fee.
- Equal split; the rounding remainder is handed out one piaster at a time to the
  lowest instructor ids. *"`sum(shares) == pool` always — no piaster created or
  lost."*
- Why equal split, and why the instructor set is snapshotted on first allocation.

**[SAY] Instructor balance approach (1 min)** — **[SHOW]**
`app/Services/BalanceService.php`.
- The three questions: earned / paid / outstanding.
- `earned = Σ vested`, `outstanding = earned − paid − in_flight`.
- *"in-flight is subtracted so a second run can't pay money that's already in a
  pending or unknown payout."*
- `instructor_balances` is a **rebuildable projection**, not the source of truth.

**[SAY] Payout architecture + key trade-off (1.5 min)** — **[SHOW]**
`app/Services/PayoutService.php`, point at the payout state enum.
- `pending → processing → paid | failed | unknown`.
- **The trade-off to name out loud:** *"I recognise revenue on accrual — it vests
  daily over the term. The alternative, cash-basis, pays the full share on day
  one. I chose accrual specifically because the brief warns about money that has
  to be recovered. With accrual we only ever pay for days already served, so a
  mid-term refund just freezes vesting — nothing is clawed back."*

---

## 3. Failure Scenario Demonstration — 5–7 min  ⭐ (the most important section)

> Strategy: the Pest tests are **deterministic** and named per scenario, so they
> are the cleanest way to demonstrate. Run each with `--filter` and read the
> assertions aloud. Then show the real seeded run in Filament for variety.

**[SAY]** *"Each of these is a named test that asserts not just the row state, but
the number of times money actually moved at the provider — so 'never double-pays'
is proven, not claimed."*

### (a) Running payouts twice — **[RUN]**
```bash
./vendor/bin/pest --filter="executed twice"
```
**[SHOW]** `tests/Unit/PayoutServiceTest.php` → *"never double-pays when the whole
payout run is executed twice"*. Read it: open the batch, plan, pay; then run the
**same period** again — same batch, `planPayouts` returns 0, one payout row, one
money movement.

### (b) Duplicate / retried job — **[RUN]**
```bash
./vendor/bin/pest --filter="retried"
```
**[SAY]** *"I process the same payout three times — simulating a crashed-and-retried
job. The provider's idempotency key means the retries replay the recorded outcome;
`movesFor(key) == 1`."*

### (c) Provider timeout (money moved, response lost) — **[RUN]**
```bash
./vendor/bin/pest --filter="timeout as unknown"
```
**[SAY]** *"The provider times out **after** moving the money. We do NOT mark it
paid — we mark it `unknown`. Then reconciliation calls `checkStatus` with the same
key, discovers it really succeeded, and settles it as paid. Total movements stays
at 1."*

### (d) Timeout where nothing moved — **[RUN]**
```bash
./vendor/bin/pest --filter="moved nothing"
```
**[SAY]** *"Same timeout, but the money never moved. While unknown, the amount is
in-flight so it can't be re-paid. Reconciliation finds 'not found', marks it
failed, and the money returns to available for a later batch. Movements = 0."*

### (e) Refund after payout (no clawback) — **[RUN]**
```bash
./vendor/bin/pest --filter="claw back"
```
**[SHOW]** `tests/Unit/RefundServiceTest.php`. *"Instructor is paid the vested
amount at day 100. The student then leaves the same day. Vesting freezes, paid
stays paid, outstanding is 0 — nothing goes negative, nothing is recovered."*

### (f) Rounding edge — **[RUN]**
```bash
./vendor/bin/pest --filter="remainder"
```
**[SAY]** *"Pool of 21001 across 3 instructors → 7001, 7000, 7000. The extra
piaster goes to the lowest id, deterministically, and the total is exact."*

### Real run with variety — **[SHOW]** browser
**[RUN]** (already done by the seed) — open `http://localhost:8000/admin`.
- Show the Instructor Earnings table: Earned / Paid / In-flight / Outstanding.
- Open one instructor → balance + **payout history** with paid/failed badges.
- **[SAY]** *"The seed ran a real payout cycle against the random provider — you
  can see a mix of paid and failed. The failed ones' money is back in
  Outstanding."*

---

## 4. Testing Strategy — 2–3 min

**[RUN]**
```bash
./vendor/bin/pest
```
**[SAY]**
- *"15 tests, run against a real MySQL test database — not in-memory SQLite — so
  row locks, unique constraints and INSERT IGNORE behave exactly like
  production."*
- **What I tested and why:** the financial calculations (split, rounding,
  vesting) and the behaviours that move money under failure (double-run, retry,
  timeout, refund).
- **What risk each protects against:** double-payment, lost/created money on
  rounding, and clawbacks.
- *"The tests assert money-movement counts via a Fake provider, so they catch a
  real double-charge even if the row states look right."*

---

## 5. AI Usage & Engineering Decisions — 2–3 min

**[SHOW]** `docs/AI_USAGE.md`.
**[SAY]**
- *"I used AI as a pair-programmer to scaffold and write boilerplate, but the
  architecture was decided first and then implemented."*
- **Decisions I made myself:** integer piasters + bps; **accrual vesting**;
  idempotency at the DB level; provider-side idempotency key; balances as a
  rebuildable projection.
- **An AI suggestion I corrected:** the first EnrollmentFactory eagerly created an
  orphan Course+Instructor when overridden — I caught it by sanity-checking seeded
  counts (89 instructors instead of 6) and made the default lazy. *(Add your own.)*
- **What makes it different from a typical AI dump:** the guarantees are
  structural and the tests prove money-movement, not just CRUD.

---

## 6. Future Improvements — 1–2 min

**[SAY]** If this were production:
- **Materialise vesting daily** into the projection so balances are an O(1) lookup
  instead of summing allocations — the main scaling step for 500k subscriptions /
  tens of millions of rows.
- Replace the cache-backed mock with the real provider's API + a scheduled
  reconciliation sweep for `unknown` payouts.
- Add a **negative ledger entry** path for truly backdated refunds (the one edge
  the accrual model floors at zero today).
- Multi-currency; observability/alerting on stuck `unknown` payouts.

**[SAY] Closing line:** *"A smaller solution with strong guarantees — money is
exact, idempotency is structural, and the hardest requirement, mid-term refunds,
is a non-event because of the accrual model."*

---

## Quick command cheat-sheet

```bash
php artisan migrate:fresh --seed                 # reset + demo data + a payout run
php artisan serve                                # http://localhost:8000/admin
./vendor/bin/pest                                # full suite (15 tests)
./vendor/bin/pest --filter="executed twice"      # scenario (a)
./vendor/bin/pest --filter="retried"             # scenario (b)
./vendor/bin/pest --filter="timeout as unknown"  # scenario (c)
./vendor/bin/pest --filter="moved nothing"       # scenario (d)
./vendor/bin/pest --filter="claw back"           # scenario (e)
./vendor/bin/pest --filter="remainder"           # scenario (f)
php artisan payouts:run --period=2026-07 --sync  # live payout run
php artisan payouts:reconcile --sync             # resolve unknown payouts
```

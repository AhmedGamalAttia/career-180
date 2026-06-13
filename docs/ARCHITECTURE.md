# Architecture & Engineering Decisions

This document explains how the money core works and, more importantly, **why**
each decision was made. The brief left several rules unspecified on purpose; the
calls made here are spelled out with their trade-offs.

---

## 1. Domain model

```
Student ──< Subscription >── Plan
                  │
                  ├──< Enrollment >── Course ── Instructor
                  │
                  └──< SubscriptionPayment (money IN)
                            │
                            ├──< RevenueAllocation >── Instructor   (the split + vesting)
                            └──< Refund

PayoutBatch ──< Payout >── Instructor          (money OUT)
                  └──< PayoutAttempt            (provider interaction log)

InstructorBalance   (cached projection: earned / paid / in-flight / outstanding)
```

- A **Subscription** is paid for up front for a whole term via a
  **SubscriptionPayment**.
- The instructors that share in a subscription are the distinct instructors
  behind its **Enrollments**.
- A payment is divided into one **RevenueAllocation** per instructor.
- A **PayoutBatch** groups the **Payouts** for one scheduled run; each Payout
  records its provider interactions as **PayoutAttempts**.

---

## 2. How money is stored

**Integer minor units (piasters) in `BIGINT`. No floats or decimals anywhere.**

Floating point cannot represent currency exactly; `DECIMAL` works but invites
accidental float arithmetic in PHP. Storing integer piasters makes every
operation exact and every rounding decision explicit and testable. The platform
fee is stored per plan in **basis points** (e.g. `3000` = 30%) so the fee is also
pure integer arithmetic.

Display formatting (`/ 100`) happens only at the UI edge in Filament.

---

## 3. Revenue allocation strategy

When a payment is captured, `RevenueAllocationService::allocate()`:

1. Computes the **platform cut**: `fee = floor(amount × bps / 10000)`.
2. The **instructor pool** is `amount − fee`. Flooring the fee means any
   sub-piaster favours the instructors, and `pool + fee == amount` exactly.
3. Splits the pool **equally** among the distinct enrolled instructors:
   `base = floor(pool / n)`, and the remainder of `pool − base×n` piasters is
   handed out **one piaster at a time to the lowest instructor ids**.

This guarantees `sum(shares) == pool` — **no piaster is ever created or lost**.

### Why equal split?

The brief allows any division. Equal-per-instructor is the defensible default: it
is simple, explainable to instructors, and deterministic. The schema keeps an
unused `revenue_share_bps_override` column so weighted splits (by watch time,
negotiated rates) can be layered on later without migration churn. A weighted
split would change only step 3.

### Why snapshot the instructor set?

The set of participating instructors is fixed on the **first** allocation. If a
student enrolled in a new course later, re-running allocation must not retroapply
a different division and over-allocate the pool. So allocation is a one-time,
idempotent event per payment.

---

## 4. Revenue recognition: accrual / daily vesting

**The most consequential decision.** Revenue is **not** earned in full on day one.
Each allocation's `share_minor` vests **linearly over the term, by whole days**:

```
vested(asOf) = share_minor × elapsed_days / term_days      (floored)
```

(See `RevenueAllocation::vestedAmount()`.)

### Why accrual instead of cash-basis?

The brief warns specifically about *"paid money that later has to be recovered."*
Accrual is what makes that problem disappear:

- Payouts only ever pay **vested-and-unpaid** money — i.e. money for days already
  served.
- When a student leaves mid-term, we simply **freeze vesting** at the leave date.
  The instructor keeps exactly what they earned for the served days; the unearned
  remainder is cancelled and is the same money refunded to the student.
- Therefore **a refund never requires clawing back a completed payout.** The two
  models would otherwise fight: cash-basis pays the instructor the full share on
  day one, and a day-two refund forces a recovery.

Trade-off: "earned" is now time-dependent, so balances are a calculation rather
than a running total (addressed by the projection in §5 and scaling in §9).

---

## 5. Balances — the three questions

The system can always answer, per instructor:

| Question | Definition |
|---|---|
| How much **earned**? | `Σ allocation.vestedAmount(now)` over non-cancelled allocations |
| How much **paid**? | `Σ payouts.amount where status = paid` |
| How much **in flight**? | `Σ payouts.amount where status ∈ {pending, processing, unknown}` |
| How much **outstanding**? | `earned − paid − in_flight` (never negative) |

`InstructorBalance` is a **cached projection** of these figures, not the source of
truth. The source of truth is always the append-only `revenue_allocations` and
`payouts` tables; the projection can be rebuilt at any time with
`BalanceService::recompute()`. The seed verifies the invariant
`earned == paid + in_flight + outstanding`.

Including **in-flight** in the subtraction is the cross-run guard: while a payout
is pending/processing/unknown, its amount is not "available", so a second payout
run (a different period, a manual trigger) cannot schedule it again.

---

## 6. Payout architecture

```
payouts:run --period=YYYY-MM
   └─ openBatch(period)          unique(period_key)  → one batch per period
   └─ planPayouts(batch)         one Payout per owed instructor, amount snapshotted
   └─ dispatch ProcessPayoutJob per pending payout
         └─ PayoutService::processPayout()
               claim (lock + settled-check) → provider.pay(idempotency_key) → settle
```

A payout moves through: `pending → processing → paid | failed | unknown`.

- **paid / failed** are terminal (settled).
- **unknown** means a provider timeout: the outcome is genuinely unknown and is
  resolved later by reconciliation (§8), never by blindly re-sending.

---

## 7. Idempotency — five layers

The requirement *"no instructor is ever paid twice for the same thing"* is
enforced at the database level (constraints), not just in code:

| # | Layer | Mechanism | Protects against |
|---|---|---|---|
| 1 | Inbound payment | `unique(idempotency_key)` on `subscription_payments` | Duplicate webhook / retried capture |
| 2 | Allocation | `unique(subscription_payment_id, instructor_id)` + row lock + `insertOrIgnore` | Double allocation, concurrent triggers |
| 3 | Batch | `unique(period_key)` on `payout_batches` | Two runs / two servers opening the same period |
| 4 | Payout | `unique(payout_batch_id, instructor_id)` | A second payout row for an instructor in a batch |
| 5 | Provider | stable `provider_idempotency_key` sent to the provider | A retried job after the money already moved |

On top of the constraints, `processPayout()` uses a **row lock + settled-check**
to claim a payout, and the network call happens **outside** the lock (no I/O
under a lock). `ProcessPayoutJob` adds `WithoutOverlapping` middleware keyed by
payout id as defence in depth.

---

## 8. Provider timeout handling

The mock provider (`MockPaymentProvider`) randomly succeeds, fails permanently,
or **times out after possibly already moving the money** — the realistic, scary
case. It keeps its own ledger keyed by the idempotency key (in the cache, as an
external provider's records would be), so a retried `pay()` with the same key
**replays the recorded outcome instead of charging again**.

Our side handles the uncertainty like this:

1. `pay()` times out → the payout is marked **`unknown`** (never `paid`). The
   amount stays in-flight, so it cannot be re-scheduled.
2. `payouts:reconcile` (or `ReconcilePayoutJob`) calls `checkStatus()` with the
   same key:
   - provider reports **success** → mark `paid` (the money really moved once).
   - provider reports **not found** → the money never moved → mark `failed`, and
     the amount returns to the instructor's available balance for a later batch.

At no point is a second payment initiated for a timed-out payout. This is proven
by `PayoutServiceTest` asserting the provider's real money-movement count stays
at exactly 1 (or 0) across the timeout + reconcile cycle.

---

## 9. Failure handling & retries

- **Queued jobs** (`AllocateRevenueJob`, `ProcessPayoutJob`, `ReconcilePayoutJob`)
  have `tries`/`backoff`. Because allocation and payout are idempotent, a worker
  that crashes mid-way and is retried causes no double effect.
- **Transactions** wrap the multi-row state changes (allocation insert, payout
  settle) so a partial write cannot leave the ledger inconsistent.
- **Row locks** serialise concurrent operations on the same payment/payout.

---

## 10. Refunds (mid-term)

`RefundService::refund()`:

1. Locks the payment (idempotent on a refund key).
2. Computes the **unconsumed amount**: `floor(amount × remaining_days / term_days)`.
3. **Freezes vesting** on every allocation of that payment at the leave date.
4. Records the refund, updates the payment (`partially_refunded`/`refunded`) and
   the subscription (`refunded`, `canceled_at`).
5. Recomputes affected instructor balances.

Because instructors were only ever paid for served days (§4), the frozen vesting
matches what was already payable — **no payout is reversed**.

---

## 11. Scaling considerations (500k subscriptions, tens of millions of rows)

- **Allocation** is O(instructors-per-subscription) per payment and append-only —
  it scales horizontally across queue workers.
- **Vesting is computed, not stored.** At platform scale, summing
  `vestedAmount()` across an instructor's allocations is the expensive path. The
  `instructor_balances` projection absorbs reads for the UI and payout planning;
  the production step would be a **scheduled job that materialises vested totals
  daily** (vesting only changes by the day), turning the read into an O(1) lookup.
- **Payout planning** already chunks instructors (`chunkById`) and snapshots the
  amount, so a run is bounded memory regardless of instructor count.
- Indexes exist on the hot paths: `(instructor_id, status)` on allocations and
  payouts, `(subscription_id, status)` on payments, `(status, ends_at)` on
  subscriptions.

---

## 12. Known limitations

- **Vesting materialisation is not implemented** — balances are recomputed
  eagerly. Fine at exercise scale; §11 describes the production path.
- **Retroactive refunds** (an effective date *before* a payout that already
  covered that period) are floored at zero rather than generating a clawback.
  With refunds processed at leave time this cannot happen; a true backdated
  refund would need a negative ledger entry. Documented rather than silently
  ignored.
- **Single currency** (EGP). Multi-currency would add a currency dimension to the
  pool split and payouts.
- The mock provider's ledger lives in the cache; a production reconciliation
  would query the real provider's API.

---

## 13. Senior bonus — mid-term plan change (discussion)

*How would the design cope with a student upgrading partway through an annual
subscription, with a fair price adjustment?*

The accrual model makes this clean and consistent with refunds:

1. **Close the old term** exactly like a refund: freeze vesting on the current
   payment's allocations at the change date. Instructors keep what they earned for
   the consumed days.
2. **Compute the credit** for the unconsumed portion of the old plan
   (`unconsumedAmount`) — the same calculation already used for refunds.
3. **Capture a new payment** for the new plan, priced as `new_plan_price −
   credit` (the fair adjustment), starting a fresh term from the change date.
4. **Allocate** the new payment normally; it vests over the new term.

So an upgrade is modelled as *(partial settle of the old term) + (new term with a
credit applied)* — no new primitives are needed, because "freeze vesting at a
date" and "allocate a captured payment" already exist. The one design question to
settle with the business is whether the credit is computed on the gross amount or
on the instructor pool; the gross-amount approach above keeps the student-facing
price fair and lets the new term's split stand on its own.

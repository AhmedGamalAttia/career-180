# AI Usage

> This document is an honest account of how AI was used to build this solution.
> **Personalise the _italicised prompts_ before submitting** — the reviewer is
> evaluating your ownership and understanding, and will ask you to justify and
> modify the code live.

## How AI was used

AI (Claude Code) was used as a pair-programming assistant to accelerate the work:
scaffolding the Laravel 11 project, writing boilerplate (migrations, models,
factories), translating already-decided designs into code, and drafting the
documentation. The **architectural decisions were made first, then handed to the
assistant to implement** — not the other way around. Every generated file was
read, reasoned about, and in several cases corrected (see below).

## Workflow / main prompts

The work proceeded in deliberate phases, each reviewed before moving on:

1. Read and break down the brief; agree the tech stack and which packages were
   actually relevant.
2. **Decide the core money model** (integer piasters, accrual vesting, equal
   split, the five idempotency layers) — *then* scaffold the schema and run the
   migrations.
3. Build the domain models, factories, and the `RevenueAllocationService`, with
   tests proving the split and idempotency before moving on.
4. Build the payout side: the provider abstraction, the mock, `PayoutService`,
   jobs, and Artisan commands — with tests for double-pay, retries, and timeouts.
5. Add refunds (accrual freeze), the Filament screen, the seeder, and docs.

Representative prompts:
- *"Design the schema for an instructor revenue ledger; money must be exact and
  idempotent; explain the trade-offs of accrual vs cash-basis recognition."*
- *"Implement the revenue split so it conserves every piaster and is idempotent
  against double triggers."*
- *"Implement a mock provider that can time out after already moving money, and
  show how the payout flow stays safe."*

## Generated vs designed

| Part | Mostly AI-generated | Designed / directed by me |
|---|---|---|
| Project scaffolding, package install | ✅ | — |
| Migrations, models, factories | ✅ (boilerplate) | Column choices, indexes, the `unique` constraints |
| `RevenueAllocationService` | code | The split rule, snapshotting, idempotency strategy |
| Accrual vesting model | code | **The decision to use accrual and freeze-on-refund** |
| `PayoutService` + provider abstraction | code | The state machine, the claim/settle split, the five idempotency layers |
| Tests | drafting | The scenarios that had to be covered |
| Docs | drafting | The reasoning and trade-offs |

## Engineering decisions I made myself

These are the calls that shaped the solution (all defended in
[`ARCHITECTURE.md`](ARCHITECTURE.md)):

1. **Integer piasters + basis-point fees** — exactness over convenience.
2. **Accrual / daily vesting** — so refunds never require clawing back a payout.
   This is the decision I would most want to discuss.
3. **Idempotency enforced at the database level** (unique constraints), not just
   application code — constraints don't have race conditions.
4. **Provider-side idempotency key** so a job that crashes after the money moved
   cannot pay twice; timeouts resolve via a status check, never a blind re-send.
5. **`InstructorBalance` as a rebuildable projection**, keeping the append-only
   ledger as the single source of truth.

## A correction I made to AI output

The first `EnrollmentFactory` eagerly created a `Course` (and `Instructor`) in its
`definition()` even when a course/instructor was passed in via a state — which
silently produced ~80 orphan instructor rows during seeding. I caught it by
sanity-checking the seeded counts, and rewrote the factory to resolve the default
course lazily so overrides never trigger it. *(Add any other corrections you made
in your own words.)*

## What differentiates this solution

- It optimises for the brief's stated priorities — **correctness of money and
  failure handling** — over feature count.
- Idempotency is **structural** (DB constraints + locks + provider keys), so it
  holds under concurrency, not just in the happy path.
- The **accrual model turns the hardest requirement (mid-term refunds) into a
  non-event** — no clawbacks — and the same primitive answers the senior bonus
  (mid-term plan change).
- The tests assert the *money-movement count*, not just row states, so "never
  double-pays" is proven, not asserted.

## Trade-offs I accepted intentionally

- Vesting is computed rather than materialised (simpler now; the production path
  is documented).
- Equal split rather than weighted (defensible default; the schema leaves room).
- A dedicated MySQL test database rather than in-memory SQLite, to test the real
  locking/constraint semantics — at the cost of slower tests.

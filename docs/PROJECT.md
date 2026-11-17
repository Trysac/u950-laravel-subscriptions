# Project Record

## Objective
To provide a payment-provider-agnostic subscription billing core in pure PHP, handling plans, trials, calendar-anchored billing periods, exact integer proration, and sequential invoice generation.

## Scope
- The public API defined in `src/`, including `Plan`, `Subscription`, `SubscriptionService`, `Proration`, `Invoice`, `InvoiceNumberer`, `PaymentGateway`, and `ClockInterface`.
- The CLI tool for proration calculations.
- All core billing arithmetic and state transitions.

## Out-of-Scope
- Laravel framework integration (adapters, service providers, models).
- Real payment provider APIs (e.g., Stripe, PayPal).
- Coupons, taxes, and usage-based billing features.
- Persistence layers or database interactions.

## Factory run u950 (measured from API usage)

| Date (UTC) | Phase | Model | Batch | Input | Output | Cache write | Cache read | Cost (USD) |
|---|---|---|---|---|---|---|---|---|
| 2026-11-17 | generation | google/gemini-4-argon | yes | 6433 | 22672 | 0 | 0 | 0.4792 |
| 2026-11-17 | fix-1 | google/gemini-3.8-flash | no | 34368 | 457 | 0 | 60777 | 0.0320 |
| 2026-11-17 | audit | google/gemini-3.8-flash | no | 149481 | 1451 | 0 | 1350481 | 0.2188 |
| 2026-11-17 | threat-model | google/gemini-3.8-flash | no | 189447 | 861 | 0 | 565274 | 0.1877 |
| 2026-11-17 | docs | google/gemini-3.1-flash-lite | no | 24418 | 1287 | 0 | 0 | 0.0080 |

Total: $0.9258. Prices: vertex line, as measured by its adapter. Providers serve the current version of each model id, so the date tells which version answered; versions, launch stages and price sources per line are in the factory's model register (docs/MODELS.md).

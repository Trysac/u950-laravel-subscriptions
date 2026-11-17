# Architecture

`trysac/laravel-subscriptions` is a dependency-free billing domain core designed to sit beneath a framework adapter.

## Components

- **`Plan` and `Subscription`**: Immutable `readonly` value objects representing plan rules and subscription lifecycle state (`trialing`, `active`, `past_due`, `canceled`).
- **`PeriodCalculator`**: Pure calendar arithmetic in UTC. Monthly and yearly intervals preserve the original anchor day of the month and clamp to the last day of shorter months (e.g. Jan 31 -> Feb 28 -> Mar 31 -> Apr 30).
- **`Proration`**: Static integer calculator computing `credit` and `charge` with exact round-half-up integer division:
  `intdiv(2 * price * remaining + total, 2 * total)`.
- **`SubscriptionService`**: Coordinates `ClockInterface`, `PaymentGateway`, and `InvoiceNumberer` to execute `subscribe`, `renew`, `changePlan`, `cancel`, and `retryPayment`.
- **`Cli`**: Stream-based command runner exposing `prorate <old> <new> <start> <end> <at>` as JSON.

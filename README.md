# laravel-subscriptions

Payment-provider-agnostic subscription billing core in pure PHP 8.4 with plans, trials, calendar-anchored periods, exact integer proration, and sequential invoices.

## The Problem
SaaS teams on Laravel often bind their subscription logic to a single payment provider, pushing plan changes, trials, and proration into provider dashboards where they cannot be unit-tested. Hand-written proration logic frequently drifts by cents due to floating-point arithmetic. This library keeps the state machine and money arithmetic in plain PHP using integer minor units, a `ClockInterface`, and a `PaymentGateway` interface.

## Usage
```php
use Trysac\LaravelSubscriptions\{FakeGateway, FrozenClock, Plan, SequentialNumberer, SubscriptionService};

$start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
$clock = new FrozenClock($start);
$service = new SubscriptionService($clock, new FakeGateway(), new SequentialNumberer());
$sub = $service->subscribe(new Plan('basic', 3000, 'USD', 'month'), $start);
$clock->setTo(new \DateTimeImmutable('2026-01-16T00:00:00Z'));
$change = $service->changePlan($sub, new Plan('pro', 6000, 'USD', 'month'));
// $change->proration->credit === 1548, $change->proration->charge === 3097
```

## API Summary
- **`Plan`**: Immutable plan definition (`priceMinor`, `interval`, `trialDays`).
- **`Subscription`**: Lifecycle snapshot (`status`, `anchor`, `currentPeriodStart`).
- **`SubscriptionService`**: Coordinates `subscribe`, `renew`, `changePlan`, `cancel`, `retryPayment`.
- **`Proration`**: Calculates exact adjustments for mid-period plan changes using `intdiv(2 * price * remaining + total, 2 * total)`.
- **Limits/Options**: Currencies are 3 uppercase ASCII letters; `priceMinor` max 100,000,000,000; interval counts: month 1-12, year 1, week 1-52, day 1-365.
- **Errors**: `SubscriptionException` provides codes: `invalid_price`, `invalid_interval`, `invalid_currency`, `currency_mismatch`, `invalid_state`, `invalid_date`.

## Why not Laravel Cashier?
Laravel Cashier binds subscription logic to a specific payment provider, preventing unit-testing of core billing logic. This library is provider-agnostic, deterministic, and avoids floating-point drift.

## Security
See [docs/SECURITY.md](docs/SECURITY.md).
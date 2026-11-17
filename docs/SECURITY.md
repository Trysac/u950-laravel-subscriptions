# Security Notes

## Threat Model
`trysac/laravel-subscriptions` is a pure PHP library with no I/O, minimizing the attack surface. It does not perform network requests or shell execution.

## Limits and Defenses
- **Input Validation**: `Plan` validates that `priceMinor` is between 0 and 100,000,000,000; currencies must be 3 uppercase ASCII letters; interval counts are bounded by period length.
- **Exceptions**: `SubscriptionException` truncates error messages to 200 characters to prevent log flooding or memory issues.
- **No I/O**: The library uses `ClockInterface` and `PaymentGateway` interfaces to isolate side effects. It never reads or writes files or databases.
- **Arithmetic Safety**: Calculations use integer arithmetic and `intdiv`, keeping results within `PHP_INT_MAX` limits.
# Exact Integer Proration and Calendar-Anchored UTC Periods

Status: accepted (generated, pending human review)

## Context

Subscription billing calculations that rely on floating-point division or naive 30-day month offsets accumulate rounding errors and drift across leap years and month-end dates.

## Decision

Represent all monetary values in integer minor units, normalize all timestamps to UTC DateTimeImmutable instances, clamp monthly and yearly renewals to the anchor day of the target month, and compute proration credits and charges with integer half-up rounding via intdiv(2 * price * remaining + total, 2 * total).

## Consequences

Every proration and invoice line sum is exact, deterministic, and free of floating-point drift, while keeping integer products safely below PHP_INT_MAX on 64-bit PHP by bounding prices to 100_000_000_000 and periods to at most 366 days.

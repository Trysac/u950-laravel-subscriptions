# Testing

## Coverage
- `tests/SubscriptionTest.php`: Contains unit and acceptance tests covering subscription lifecycles, proration edge cases, validation logic, and state transitions.
- `tests/fixtures/proration_cases.json`: Defines 12+ hand-computed reference cases used to verify proration accuracy against formulaic expectations.

## Policy
- Tests are deterministic, using `FrozenClock` and `FakeGateway` to avoid external time or network dependencies.
- Property testing via PHPUnit data providers verifies invoice total sums and proration calculation invariants.
- Conformance with the specified billing logic (e.g., monthly/yearly clamping to month-ends) is asserted explicitly.
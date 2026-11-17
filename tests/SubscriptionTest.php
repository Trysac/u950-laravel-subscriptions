<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trysac\LaravelSubscriptions\Cli;
use Trysac\LaravelSubscriptions\FakeGateway;
use Trysac\LaravelSubscriptions\FrozenClock;
use Trysac\LaravelSubscriptions\Invoice;
use Trysac\LaravelSubscriptions\PaymentResult;
use Trysac\LaravelSubscriptions\Plan;
use Trysac\LaravelSubscriptions\Proration;
use Trysac\LaravelSubscriptions\SequentialNumberer;
use Trysac\LaravelSubscriptions\SubscriptionException;
use Trysac\LaravelSubscriptions\SubscriptionService;

/**
 * Acceptance and edge-case tests for the subscription billing core.
 *
 * @internal
 */
final class SubscriptionTest extends TestCase
{
    public function testAcceptanceJanMidpointUpgradeAndDowngrade(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-02-01T00:00:00Z');
        $at = new \DateTimeImmutable('2026-01-16T00:00:00Z');

        $upgrade = Proration::calculate(3000, 6000, $start, $end, $at);
        self::assertSame(1548, $upgrade->credit);
        self::assertSame(3097, $upgrade->charge);
        self::assertSame(1549, $upgrade->net);

        $downgrade = Proration::calculate(6000, 3000, $start, $end, $at);
        self::assertSame(3097, $downgrade->credit);
        self::assertSame(1548, $downgrade->charge);
        self::assertSame(-1549, $downgrade->net);

        $clock = new FrozenClock($start);
        $gateway = new FakeGateway();
        $numberer = new SequentialNumberer();
        $service = new SubscriptionService($clock, $gateway, $numberer);

        $plan6000 = new Plan('pro', 6000, 'USD', 'month');
        $plan3000 = new Plan('basic', 3000, 'USD', 'month');

        $sub = $service->subscribe($plan6000, $start);
        $clock->setTo($at);

        $changeResult = $service->changePlan($sub, $plan3000, true);
        self::assertSame(1549, $changeResult->subscription->creditBalance);
        self::assertNotNull($changeResult->invoice);
        self::assertSame(0, $changeResult->invoice->total);
        self::assertSame('paid', $changeResult->invoice->status);

        // Next renewal applies the 1549 credit balance first
        $clock->setTo($end);
        $renewal = $service->renew($changeResult->subscription);
        self::assertNotNull($renewal->invoice);
        self::assertSame(3000 - 1549, $renewal->invoice->total);
        self::assertSame(0, $renewal->subscription->creditBalance);
    }

    /**
     * @return iterable<string, array{int, int, string, string, string, int, int, int}>
     */
    public static function referenceProrationCases(): iterable
    {
        $path = __DIR__ . '/fixtures/proration_cases.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        /** @var list<array{name: string, oldMinor: int, newMinor: int, start: string, end: string, at: string, credit: int, charge: int, net: int}> $cases */
        $cases = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(12, count($cases));

        foreach ($cases as $case) {
            yield $case['name'] => [
                $case['oldMinor'],
                $case['newMinor'],
                $case['start'],
                $case['end'],
                $case['at'],
                $case['credit'],
                $case['charge'],
                $case['net'],
            ];
        }
    }

    #[DataProvider('referenceProrationCases')]
    public function testReferenceProrationFixtureCases(
        int $oldMinor,
        int $newMinor,
        string $start,
        string $end,
        string $at,
        int $wantCredit,
        int $wantCharge,
        int $wantNet,
    ): void {
        $result = Proration::calculate(
            $oldMinor,
            $newMinor,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($at),
        );

        self::assertSame($wantCredit, $result->credit);
        self::assertSame($wantCharge, $result->charge);
        self::assertSame($wantNet, $result->net);
    }

    public function testMonthlyAndYearlyAnchorClamping(): void
    {
        $start = new \DateTimeImmutable('2026-01-31T00:00:00Z');
        $clock = new FrozenClock($start);
        $service = new SubscriptionService($clock, new FakeGateway(), new SequentialNumberer());

        $monthly = new Plan('monthly', 2000, 'USD', 'month');
        $sub = $service->subscribe($monthly, $start);
        self::assertSame('2026-02-28T00:00:00+00:00', $sub->currentPeriodEnd->format('c'));

        $clock->setTo(new \DateTimeImmutable('2026-02-28T00:00:00Z'));
        $sub = $service->renew($sub)->subscription;
        self::assertSame('2026-03-31T00:00:00+00:00', $sub->currentPeriodEnd->format('c'));

        $clock->setTo(new \DateTimeImmutable('2026-03-31T00:00:00Z'));
        $sub = $service->renew($sub)->subscription;
        self::assertSame('2026-04-30T00:00:00+00:00', $sub->currentPeriodEnd->format('c'));

        // Yearly anchored on leap day 2028-02-29T00:00:00Z
        $leapAnchor = new \DateTimeImmutable('2028-02-29T00:00:00Z');
        $clock->setTo($leapAnchor);
        $yearly = new Plan('yearly', 20000, 'USD', 'year');
        $yearlySub = $service->subscribe($yearly, $leapAnchor);
        self::assertSame('2029-02-28T00:00:00+00:00', $yearlySub->currentPeriodEnd->format('c'));

        $clock->setTo(new \DateTimeImmutable('2029-02-28T00:00:00Z'));
        $yearlySub = $service->renew($yearlySub)->subscription;
        self::assertSame('2030-02-28T00:00:00+00:00', $yearlySub->currentPeriodEnd->format('c'));
    }

    public function testDailyAndWeeklyPeriods(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T12:00:00+02:00');
        $clock = new FrozenClock($start);
        $service = new SubscriptionService($clock, new FakeGateway(), new SequentialNumberer());

        $daily = new Plan('daily', 100, 'USD', 'day', 5);
        $sub = $service->subscribe($daily, $start);
        self::assertSame('2026-01-06T10:00:00+00:00', $sub->currentPeriodEnd->format('c'));

        $weekly = new Plan('weekly', 500, 'USD', 'week', 2);
        $subWeek = $service->subscribe($weekly, $start);
        self::assertSame('2026-01-15T10:00:00+00:00', $subWeek->currentPeriodEnd->format('c'));
    }

    public function testTrialSubscriptionAndRenewalLifecycle(): void
    {
        $start = new \DateTimeImmutable('2026-03-01T10:00:00Z');
        $clock = new FrozenClock($start);
        $gateway = new FakeGateway();
        $numberer = new SequentialNumberer();
        $events = [];
        $service = new SubscriptionService(
            $clock,
            $gateway,
            $numberer,
            static function (array $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $plan = new Plan('starter', 3000, 'USD', 'month', 1, 14);
        $sub = $service->subscribe($plan, $start);

        self::assertSame('trialing', $sub->status);
        self::assertNotNull($sub->trialEnd);
        self::assertSame('2026-03-15T10:00:00+00:00', $sub->trialEnd->format('c'));
        self::assertSame([], $gateway->chargedInvoices());

        // Renew before trialEnd is a no-op
        $clock->advanceSeconds(3600);
        $noop = $service->renew($sub);
        self::assertNull($noop->invoice);
        self::assertSame('trialing', $noop->subscription->status);

        // Renew at trialEnd creates INV-000001
        $clock->setTo(new \DateTimeImmutable('2026-03-15T10:00:00Z'));
        $renewal1 = $service->renew($sub);
        self::assertNotNull($renewal1->invoice);
        self::assertSame('INV-000001', $renewal1->invoice->number);
        self::assertSame(3000, $renewal1->invoice->total);
        self::assertSame('paid', $renewal1->invoice->status);
        self::assertSame('active', $renewal1->subscription->status);
        self::assertSame('2026-03-15T10:00:00+00:00', $renewal1->subscription->currentPeriodStart->format('c'));
        self::assertSame('2026-04-15T10:00:00+00:00', $renewal1->subscription->currentPeriodEnd->format('c'));

        // Next renewal creates INV-000002
        $clock->setTo(new \DateTimeImmutable('2026-04-15T10:00:00Z'));
        $renewal2 = $service->renew($renewal1->subscription);
        self::assertNotNull($renewal2->invoice);
        self::assertSame('INV-000002', $renewal2->invoice->number);
        self::assertNotEmpty($events);
    }

    public function testDeclinesRetryAndCancellationStateTransitions(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $clock = new FrozenClock($start);
        $gateway = new FakeGateway([
            true,
            PaymentResult::declined('insufficient_funds'),
            false,
            true,
            false,
            false,
            false,
        ]);
        $numberer = new SequentialNumberer();
        $service = new SubscriptionService($clock, $gateway, $numberer);

        $plan = new Plan('pro', 3000, 'USD', 'month');
        $sub = $service->subscribe($plan, $start);
        self::assertSame('active', $sub->status);

        // Move to period end and decline twice
        $clock->setTo(new \DateTimeImmutable('2026-02-01T00:00:00Z'));
        $r1 = $service->renew($sub);
        self::assertSame('past_due', $r1->subscription->status);
        self::assertSame(1, $r1->subscription->failedAttempts);
        self::assertNotNull($r1->invoice);
        self::assertSame('open', $r1->invoice->status);

        $r2 = $service->retryPayment($r1->subscription, $r1->invoice);
        self::assertSame('past_due', $r2->subscription->status);
        self::assertSame(2, $r2->subscription->failedAttempts);
        self::assertNotNull($r2->invoice);

        // Approved retry returns it to active and resets failedAttempts
        $r3 = $service->retryPayment($r2->subscription, $r2->invoice);
        self::assertSame('active', $r3->subscription->status);
        self::assertSame(0, $r3->subscription->failedAttempts);
        self::assertNotNull($r3->invoice);
        self::assertSame('paid', $r3->invoice->status);

        // Next period: 3 consecutive declines make it canceled
        $clock->setTo(new \DateTimeImmutable('2026-03-01T00:00:00Z'));
        $d1 = $service->renew($r3->subscription);
        self::assertSame('past_due', $d1->subscription->status);
        self::assertNotNull($d1->invoice);

        $d2 = $service->retryPayment($d1->subscription, $d1->invoice);
        self::assertSame('past_due', $d2->subscription->status);
        self::assertNotNull($d2->invoice);

        $d3 = $service->retryPayment($d2->subscription, $d2->invoice);
        self::assertSame('canceled', $d3->subscription->status);
        self::assertSame(3, $d3->subscription->failedAttempts);

        // changePlan on a canceled subscription throws invalid_state
        try {
            $service->changePlan($d3->subscription, new Plan('other', 4000, 'USD', 'month'));
            self::fail('Expected SubscriptionException for canceled subscription');
        } catch (SubscriptionException $e) {
            self::assertSame('invalid_state', $e->errorCode);
        }
    }

    public function testCancelAtPeriodEndAndImmediateCancel(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $clock = new FrozenClock($start);
        $service = new SubscriptionService($clock, new FakeGateway([true]), new SequentialNumberer());
        $plan = new Plan('pro', 3000, 'USD', 'month');

        $sub = $service->subscribe($plan, $start);
        $scheduled = $service->cancel($sub, atPeriodEnd: true);
        self::assertSame('active', $scheduled->status);
        self::assertTrue($scheduled->cancelAtPeriodEnd);

        $clock->setTo(new \DateTimeImmutable('2026-02-01T00:00:00Z'));
        $renewResult = $service->renew($scheduled);
        self::assertSame('canceled', $renewResult->subscription->status);
        self::assertNull($renewResult->invoice);

        // Immediate cancel
        $sub2 = $service->subscribe($plan, $start);
        $immediate = $service->cancel($sub2, atPeriodEnd: false);
        self::assertSame('canceled', $immediate->status);

        try {
            $service->cancel($immediate, atPeriodEnd: false);
            self::fail('Expected invalid_state when canceling already canceled subscription');
        } catch (SubscriptionException $e) {
            self::assertSame('invalid_state', $e->errorCode);
        }
    }

    /**
     * @return iterable<string, array{callable(): void, string}>
     */
    public static function validationErrorCases(): iterable
    {
        yield 'negative priceMinor' => [
            static function (): void {
                new Plan('p', -1, 'USD', 'month');
            },
            'invalid_price',
        ];

        yield 'priceMinor above 100_000_000_000' => [
            static function (): void {
                new Plan('p', 100_000_000_001, 'USD', 'month');
            },
            'invalid_price',
        ];

        yield 'interval fortnight' => [
            static function (): void {
                new Plan('p', 1000, 'USD', 'fortnight');
            },
            'invalid_interval',
        ];

        yield 'currency lowercase usd' => [
            static function (): void {
                new Plan('p', 1000, 'usd', 'month');
            },
            'invalid_currency',
        ];

        yield 'intervalCount 13 for month' => [
            static function (): void {
                new Plan('p', 1000, 'USD', 'month', 13);
            },
            'invalid_interval',
        ];

        yield 'intervalCount 2 for year' => [
            static function (): void {
                new Plan('p', 1000, 'USD', 'year', 2);
            },
            'invalid_interval',
        ];

        yield 'negative trialDays' => [
            static function (): void {
                new Plan('p', 1000, 'USD', 'month', 1, -1);
            },
            'invalid_interval',
        ];

        yield 'empty plan id' => [
            static function (): void {
                new Plan('   ', 1000, 'USD', 'month');
            },
            'invalid_state',
        ];

        yield 'proration at before start' => [
            static function (): void {
                Proration::calculate(
                    1000,
                    2000,
                    new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                    new \DateTimeImmutable('2026-02-01T00:00:00Z'),
                    new \DateTimeImmutable('2025-12-31T23:59:59Z'),
                );
            },
            'invalid_date',
        ];

        yield 'proration end before start' => [
            static function (): void {
                Proration::calculate(
                    1000,
                    2000,
                    new \DateTimeImmutable('2026-02-01T00:00:00Z'),
                    new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                    new \DateTimeImmutable('2026-01-15T00:00:00Z'),
                );
            },
            'invalid_date',
        ];

        yield 'proration negative price' => [
            static function (): void {
                Proration::calculate(
                    -5,
                    2000,
                    new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                    new \DateTimeImmutable('2026-02-01T00:00:00Z'),
                    new \DateTimeImmutable('2026-01-15T00:00:00Z'),
                );
            },
            'invalid_price',
        ];
    }

    /**
     * @param callable(): void $action
     */
    #[DataProvider('validationErrorCases')]
    public function testValidationErrors(callable $action, string $expectedCode): void
    {
        try {
            $action();
            self::fail(sprintf('Expected SubscriptionException with errorCode %s', $expectedCode));
        } catch (SubscriptionException $e) {
            self::assertSame($expectedCode, $e->errorCode);
            self::assertSame(0, $e->getCode());
            self::assertLessThanOrEqual(200, strlen($e->getMessage()));
        }
    }

    public function testCurrencyMismatchAndAdditionalServiceGuards(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $clock = new FrozenClock($start);
        $service = new SubscriptionService($clock, new FakeGateway(), new SequentialNumberer());

        $usdPlan = new Plan('usd-plan', 3000, 'USD', 'month');
        $eurPlan = new Plan('eur-plan', 3000, 'EUR', 'month');
        $sub = $service->subscribe($usdPlan, $start);

        try {
            $service->changePlan($sub, $eurPlan);
            self::fail('Expected currency_mismatch');
        } catch (SubscriptionException $e) {
            self::assertSame('currency_mismatch', $e->errorCode);
        }

        // Unprorated changePlan updates plan without creating an invoice
        $usdPlan2 = new Plan('usd-plan-2', 5000, 'USD', 'month');
        $noProrate = $service->changePlan($sub, $usdPlan2, prorate: false);
        self::assertNull($noProrate->invoice);
        self::assertSame('usd-plan-2', $noProrate->subscription->plan->id);

        // Retrying a paid invoice throws invalid_state
        $paidInvoice = new Invoice('INV-999999', 'USD', [], 0, 'paid');
        try {
            $service->retryPayment($sub, $paidInvoice);
            self::fail('Expected invalid_state on paid invoice retry');
        } catch (SubscriptionException $e) {
            self::assertSame('invalid_state', $e->errorCode);
        }

        // Renewing or retrying on canceled subscription throws invalid_state
        $canceled = $service->cancel($sub, false);
        try {
            $service->renew($canceled);
            self::fail('Expected invalid_state on canceled renew');
        } catch (SubscriptionException $e) {
            self::assertSame('invalid_state', $e->errorCode);
        }

        try {
            $service->retryPayment($canceled, new Invoice('INV-999998', 'USD', [], 100, 'open'));
            self::fail('Expected invalid_state on canceled retryPayment');
        } catch (SubscriptionException $e) {
            self::assertSame('invalid_state', $e->errorCode);
        }
    }

    public function testCliProrateCommandAndErrors(): void
    {
        $stdout = fopen('php://memory', 'r+b');
        $stderr = fopen('php://memory', 'r+b');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = Cli::run(
            ['prorate', '3000', '6000', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', '2026-01-16T00:00:00Z'],
            $stdout,
            $stderr,
        );
        rewind($stdout);
        $out = stream_get_contents($stdout);
        fclose($stdout);
        fclose($stderr);

        self::assertSame(0, $code);
        self::assertSame('{"credit":1548,"charge":3097,"net":1549}', $out);

        // Script name prefix in $argv[0]
        $stdout2 = fopen('php://memory', 'r+b');
        $stderr2 = fopen('php://memory', 'r+b');
        self::assertIsResource($stdout2);
        self::assertIsResource($stderr2);
        $code2 = Cli::run(
            ['bin/cli', 'prorate', '3000', '6000', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', '2026-01-16T00:00:00Z'],
            $stdout2,
            $stderr2,
        );
        fclose($stdout2);
        fclose($stderr2);
        self::assertSame(0, $code2);

        // Usage error (missing args)
        $stdout3 = fopen('php://memory', 'r+b');
        $stderr3 = fopen('php://memory', 'r+b');
        self::assertIsResource($stdout3);
        self::assertIsResource($stderr3);
        self::assertSame(2, Cli::run(['invalid'], $stdout3, $stderr3));
        self::assertSame(2, Cli::run(['prorate', 'abc', '100', '2026-01-01', '2026-02-01', '2026-01-15'], $stdout3, $stderr3));
        self::assertSame(2, Cli::run(['prorate', str_repeat('1', 70), '100', '2026-01-01', '2026-02-01', '2026-01-15'], $stdout3, $stderr3));
        self::assertSame(1, Cli::run(['prorate', '100', '200', 'not-a-date', '2026-02-01T00:00:00Z', '2026-01-15T00:00:00Z'], $stdout3, $stderr3));
        self::assertSame(1, Cli::run(['prorate', '-100', '200', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', '2026-01-15T00:00:00Z'], $stdout3, $stderr3));
        fclose($stdout3);
        fclose($stderr3);
    }
}

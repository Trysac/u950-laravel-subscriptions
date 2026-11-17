<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trysac\LaravelSubscriptions\FakeGateway;
use Trysac\LaravelSubscriptions\FrozenClock;
use Trysac\LaravelSubscriptions\Plan;
use Trysac\LaravelSubscriptions\SequentialNumberer;
use Trysac\LaravelSubscriptions\SubscriptionService;
use Trysac\LaravelSubscriptions\Text;

/**
 * Property-based tests for invoice line invariants and text normalization.
 *
 * @internal
 */
final class TextPropertiesTest extends TestCase
{
    /**
     * @return iterable<int, array{string}>
     */
    public static function nonBlankAsciiStrings(): iterable
    {
        mt_srand(42);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 -_';
        $len = strlen($alphabet);
        for ($i = 0; $i < 30; ++$i) {
            $size = mt_rand(1, 24);
            $buf = '';
            for ($j = 0; $j < $size; ++$j) {
                $buf .= $alphabet[mt_rand(0, $len - 1)];
            }
            if ('' === trim($buf)) {
                $buf .= 'a';
            }

            yield $i => ['  ' . $buf . "\n"];
        }
    }

    #[DataProvider('nonBlankAsciiStrings')]
    public function testNormalizeIsIdempotentAndTrimmed(string $raw): void
    {
        $once = Text::normalize($raw);
        $twice = Text::normalize($once);

        self::assertSame($once, $twice);
        self::assertSame(trim($once), $once);
        self::assertSame(strtolower($once), $once);
    }

    public function testInvoiceTotalsAlwaysEqualSumOfLinesOver500SeededPlanChanges(): void
    {
        mt_srand(7);
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $clock = new FrozenClock($start);
        $gateway = new FakeGateway();
        $numberer = new SequentialNumberer();
        $service = new SubscriptionService($clock, $gateway, $numberer);

        $initialPlan = new Plan('plan-0', 5000, 'USD', 'month');
        $sub = $service->subscribe($initialPlan, $start);

        for ($i = 1; $i <= 500; ++$i) {
            $periodDuration = $sub->currentPeriodEnd->getTimestamp() - $sub->currentPeriodStart->getTimestamp();
            $offset = mt_rand(0, $periodDuration);
            $at = $sub->currentPeriodStart->modify(sprintf('+%d seconds', $offset));
            $clock->setTo($at);

            $newPrice = mt_rand(0, 50_000);
            $newPlan = new Plan(sprintf('plan-%d', $i), $newPrice, 'USD', 'month');

            $change = $service->changePlan($sub, $newPlan, true);
            $sub = $change->subscription;
            $invoice = $change->invoice;
            self::assertNotNull($invoice);

            $lineSum = 0;
            foreach ($invoice->lines as $line) {
                $lineSum += $line->amountMinor;
            }

            self::assertSame($lineSum, $invoice->total);
            self::assertGreaterThanOrEqual(0, $invoice->total);
            self::assertGreaterThanOrEqual(0, $sub->creditBalance);

            if (0 === $i % 10) {
                $clock->setTo($sub->currentPeriodEnd);
                $renewal = $service->renew($sub);
                $sub = $renewal->subscription;
                if (null !== $renewal->invoice) {
                    $renewalSum = 0;
                    foreach ($renewal->invoice->lines as $line) {
                        $renewalSum += $line->amountMinor;
                    }
                    self::assertSame($renewalSum, $renewal->invoice->total);
                }
            }
        }
    }
}

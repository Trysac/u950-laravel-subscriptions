<?php

declare(strict_types=1);

namespace Trysac\LaravelSubscriptions;

/**
 * Command-line interface for exact integer proration calculations.
 */
final class Cli
{
    /**
     * Maximum allowed length for any single CLI argument.
     */
    public const int MAX_ARG_LENGTH = 64;

    /**
     * Runs the CLI command and writes output to the provided streams.
     *
     * @param list<string> $argv command-line arguments (either starting with 'prorate' or script name)
     * @param resource $stdout output stream resource
     * @param resource $stderr error stream resource
     *
     * @return int exit code (0 = success, 1 = domain error, 2 = usage error)
     */
    public static function run(array $argv, $stdout, $stderr): int
    {
        $args = $argv;
        if ([] !== $args && 'prorate' !== $args[0] && isset($args[1]) && 'prorate' === $args[1]) {
            array_shift($args);
        }

        if (6 !== count($args) || 'prorate' !== $args[0]) {
            fwrite($stderr, "Usage: prorate <old> <new> <start> <end> <at>\n");

            return 2;
        }

        foreach ($args as $arg) {
            if (strlen($arg) > self::MAX_ARG_LENGTH) {
                fwrite($stderr, "Error: argument exceeds maximum length\n");

                return 2;
            }
        }

        [, $oldRaw, $newRaw, $startRaw, $endRaw, $atRaw] = $args;

        if (1 !== preg_match('/^-?\d+$/', $oldRaw) || 1 !== preg_match('/^-?\d+$/', $newRaw)) {
            fwrite($stderr, "Usage: old and new prices must be integers\n");

            return 2;
        }

        $oldMinor = (int) $oldRaw;
        $newMinor = (int) $newRaw;

        try {
            $start = new \DateTimeImmutable($startRaw);
            $end = new \DateTimeImmutable($endRaw);
            $at = new \DateTimeImmutable($atRaw);
        } catch (\Exception) {
            fwrite($stderr, "Error: invalid_date\n");

            return 1;
        }

        try {
            $result = Proration::calculate($oldMinor, $newMinor, $start, $end, $at);
        } catch (SubscriptionException $e) {
            fwrite($stderr, sprintf("Error (%s): %s\n", $e->errorCode, $e->getMessage()));

            return 1;
        }

        $json = json_encode(
            [
                'credit' => $result->credit,
                'charge' => $result->charge,
                'net' => $result->net,
            ],
            JSON_THROW_ON_ERROR,
        );

        fwrite($stdout, $json);

        return 0;
    }
}

<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageInstallmentPolicy;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

enum Arrears
{
    case CURRENT;
    case DAYS_ONE_TO_SEVEN;
    case MORE_THAN_SEVEN_DAYS;
}

/** Calendar math only. Input is the verified unpaid subset of a complete,
 * authoritative schedule. An empty subset is not a substitute for evidence. */
final class CalendarArrears
{
    public static function classify(array $verifiedUnpaidDueDates, DateTimeImmutable $asOf): Arrears
    {
        $saoPaulo = new DateTimeZone('America/Sao_Paulo');
        // Select the local civil day, then compare civil labels in UTC so even
        // historical DST changes at São Paulo midnight cannot skew day counts.
        $utc = new DateTimeZone('UTC');
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf->setTimezone($saoPaulo)->format('Y-m-d'), $utc);
        if (!$today) throw new InvalidArgumentException('Invalid evaluation date');
        $max = 0;
        foreach ($verifiedUnpaidDueDates as $date) {
            $due = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $utc) : false;
            if (!$due || $due->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Invalid authoritative due date');
            }
            $max = max($max, (int) $due->diff($today)->format('%r%a'));
        }
        return match (true) {
            $max > 7 => Arrears::MORE_THAN_SEVEN_DAYS,
            $max > 0 => Arrears::DAYS_ONE_TO_SEVEN,
            default => Arrears::CURRENT,
        };
    }
}

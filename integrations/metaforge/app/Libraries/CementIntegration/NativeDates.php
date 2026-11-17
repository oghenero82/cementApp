<?php

namespace App\Libraries\CementIntegration;

/** MetaForge datetimes are canonical UTC; Cement browser inputs carry an explicit offset. */
final class NativeDates
{
    public static function canonical(string $value): string
    {
        $utc = new \DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
            if (!$d || $d->format('Y-m-d H:i:s') !== $value) {
                throw new \InvalidArgumentException('Invalid datetime');
            }return $value;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) {
            throw new \InvalidArgumentException('Use an ISO datetime');
        }
        try {
            $d = new \DateTimeImmutable($value, $utc);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                throw new \InvalidArgumentException('Invalid datetime');
            }return $d->setTimezone($utc)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid datetime');
        }
    }
}

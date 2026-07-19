<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ExchangeDateParser
{
    private DateTimeZone $timezone;

    public function __construct(string $timezone = 'Asia/Kolkata')
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function parse(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw new SourceFormatException('Announcement timestamp is empty.');
        }

        $formats = [
            '!d-M-Y H:i:s',
            '!d-M-Y H:i',
            '!d-M-Y h:i:s A',
            '!d-M-Y h:i A',
            '!d M Y H:i:s',
            '!Y-m-d H:i:s',
            '!Y-m-d\\TH:i:s',
            '!Y-m-d\\TH:i:sP',
            '!Y-m-d\\TH:i:s.u',
            '!Y-m-d\\TH:i:s.uP',
            '!m/d/Y H:i:s',
        ];

        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $this->timezone);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        try {
            return new DateTimeImmutable($value, $this->timezone);
        } catch (Throwable) {
            throw new SourceFormatException('Announcement timestamp format is not recognized.');
        }
    }
}

<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

final readonly class AlertReservation
{
    public function __construct(
        public int $deliveryId,
        public int $attemptId,
        public int $attemptNumber,
    ) {
    }
}

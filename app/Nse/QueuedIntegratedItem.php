<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

final readonly class QueuedIntegratedItem
{
    public function __construct(
        public int $id,
        public IntegratedFeedItem $item,
        public int $attempts,
    ) {
    }
}

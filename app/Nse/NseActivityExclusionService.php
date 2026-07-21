<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use PDO;

final class NseActivityExclusionService
{
    public const BANKING_FILENAME_PREFIX = 'INTEGRATED_FILING_BANKING_';
    public const BANKING_REASON = 'Conventional banking taxonomy excluded from Sharia screening.';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function reasonForFilename(string $filename): ?string
    {
        return str_starts_with(strtoupper(trim($filename)), self::BANKING_FILENAME_PREFIX)
            ? self::BANKING_REASON
            : null;
    }

    public function apply(): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_feed_items
            SET status = 'excluded',
                next_attempt_at = NULL,
                last_error = NULL,
                exclusion_reason = :reason,
                excluded_at = COALESCE(excluded_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP
            WHERE LEFT(source_filename, :prefix_length) = :prefix
              AND status IN ('pending', 'processing', 'failed')
            SQL
        );
        $statement->bindValue('reason', self::BANKING_REASON);
        $statement->bindValue('prefix_length', strlen(self::BANKING_FILENAME_PREFIX), PDO::PARAM_INT);
        $statement->bindValue('prefix', self::BANKING_FILENAME_PREFIX);
        $statement->execute();

        return $statement->rowCount();
    }
}

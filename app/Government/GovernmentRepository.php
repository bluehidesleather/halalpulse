<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class GovernmentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{announcements:int,pending:int,tailwinds:int,source_failures:int} */
    public function summary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM government_announcements) AS announcements,
                (SELECT COUNT(*) FROM government_announcements ga WHERE NOT EXISTS(SELECT 1 FROM government_tailwind_reviews gtr WHERE gtr.announcement_id=ga.id AND gtr.review_status='current')) AS pending,
                (SELECT COUNT(*) FROM government_tailwind_reviews gtr WHERE gtr.review_status='current' AND gtr.impact IN('strong_tailwind','moderate_tailwind')) AS tailwinds,
                (SELECT COUNT(*) FROM government_source_checkpoints WHERE consecutive_failures>0) AS source_failures
            SQL
        )->fetch();
        return [
            'announcements' => (int) ($row['announcements'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'tailwinds' => (int) ($row['tailwinds'] ?? 0),
            'source_failures' => (int) ($row['source_failures'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function announcements(string $status = 'pending', string $source = '', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $where = [];
        $parameters = [];
        if ($source !== '') {
            if (!in_array($source, ['PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET'], true)) {
                throw new InvalidArgumentException('Government source filter is invalid.');
            }
            $where[] = 'ga.source=:source';
            $parameters['source'] = $source;
        }
        if ($status === 'pending') {
            $where[] = "NOT EXISTS(SELECT 1 FROM government_tailwind_reviews pending_review WHERE pending_review.announcement_id=ga.id AND pending_review.review_status='current')";
        } elseif ($status === 'tailwind') {
            $where[] = "gtr.impact IN('strong_tailwind','moderate_tailwind')";
        } elseif ($status === 'reviewed') {
            $where[] = 'gtr.id IS NOT NULL';
        } elseif ($status !== 'all') {
            throw new InvalidArgumentException('Government review filter is invalid.');
        }
        $sql = <<<'SQL'
            SELECT ga.*,gtr.id AS review_id,gtr.sector AS reviewed_sector,gtr.impact AS reviewed_impact,
                gtr.rationale AS review_rationale,gtr.reviewed_at,u.display_name AS reviewer_name
            FROM government_announcements ga
            LEFT JOIN government_tailwind_reviews gtr ON gtr.announcement_id=ga.id AND gtr.review_status='current'
            LEFT JOIN users u ON u.id=gtr.reviewed_by_user_id
            SQL;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ga.published_at DESC,ga.id DESC LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function announcement(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM government_announcements WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function saveReview(int $announcementId, string $sector, string $impact, string $rationale, int $userId): int
    {
        $allowed = ['strong_tailwind', 'moderate_tailwind', 'neutral', 'headwind', 'not_relevant'];
        if (!in_array($impact, $allowed, true)) {
            throw new InvalidArgumentException('Government review impact is invalid.');
        }
        $sector = trim($sector);
        if ($impact !== 'not_relevant' && (mb_strlen($sector) < 2 || mb_strlen($sector) > 150)) {
            throw new InvalidArgumentException('Reviewed sector must contain 2 to 150 characters.');
        }
        if ($impact === 'not_relevant') {
            $sector = 'Not relevant';
        }
        if (mb_strlen($rationale) < 10 || mb_strlen($rationale) > 1000) {
            throw new InvalidArgumentException('Review rationale must contain 10 to 1,000 characters.');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM government_announcements WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $announcementId]);
            if ($lock->fetchColumn() === false) {
                throw new InvalidArgumentException('Government announcement was not found.');
            }
            $supersede = $this->pdo->prepare("UPDATE government_tailwind_reviews SET review_status='superseded' WHERE announcement_id=:announcement_id AND review_status='current'");
            $supersede->execute(['announcement_id' => $announcementId]);
            $insert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO government_tailwind_reviews(announcement_id,sector,impact,rationale,review_status,reviewed_by_user_id,reviewed_at)
                VALUES(:announcement_id,:sector,:impact,:rationale,'current',:user_id,CURRENT_TIMESTAMP)
                SQL
            );
            $insert->execute(['announcement_id' => $announcementId, 'sector' => $sector, 'impact' => $impact, 'rationale' => $rationale, 'user_id' => $userId]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function approvedTailwinds(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query(
            <<<'SQL'
            SELECT gtr.id,gtr.sector,gtr.impact,gtr.rationale,gtr.reviewed_at,ga.source,ga.title,ga.published_at,ga.official_url
            FROM government_tailwind_reviews gtr
            INNER JOIN government_announcements ga ON ga.id=gtr.announcement_id
            WHERE gtr.review_status='current' AND gtr.impact IN('strong_tailwind','moderate_tailwind')
            ORDER BY ga.published_at DESC,gtr.id DESC
            LIMIT
            SQL . ' ' . $limit
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function approvedTailwind(int $reviewId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT gtr.id,gtr.sector,gtr.impact,gtr.rationale,gtr.review_status,ga.source,ga.title,ga.published_at,ga.official_url
            FROM government_tailwind_reviews gtr
            INNER JOIN government_announcements ga ON ga.id=gtr.announcement_id
            WHERE gtr.id=:id AND gtr.review_status='current' AND gtr.impact IN('strong_tailwind','moderate_tailwind')
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $reviewId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function sourceHealth(): array
    {
        return $this->pdo->query('SELECT * FROM government_source_checkpoints ORDER BY source')->fetchAll();
    }
}

<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use PDO;
use RuntimeException;
use Throwable;

final readonly class ShariaPolicyInstaller
{
    public function __construct(
        private PDO $pdo,
        private ShariaPolicyValidator $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{version: string, policy_hash: string}
     */
    public function installAndActivate(array $input): array
    {
        $policy = $this->validator->validate($input);
        $hash = $this->validator->hash($policy);
        $ratiosJson = json_encode($policy['ratios'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->pdo->beginTransaction();

        try {
            $existing = $this->pdo->prepare('SELECT id, policy_hash FROM sharia_policies WHERE version = :version FOR UPDATE');
            $existing->execute(['version' => $policy['version']]);
            $row = $existing->fetch();

            if (is_array($row) && !hash_equals((string) $row['policy_hash'], $hash)) {
                throw new RuntimeException('That policy version already exists with different content. Use a new version.');
            }

            $this->pdo->exec('UPDATE sharia_policies SET is_active = 0 WHERE is_active = 1');

            if (is_array($row)) {
                $activate = $this->pdo->prepare(
                    <<<'SQL'
                    UPDATE sharia_policies
                    SET is_active = 1,
                        activated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                    SQL
                );
                $activate->execute(['id' => $row['id']]);
            } else {
                $insert = $this->pdo->prepare(
                    <<<'SQL'
                    INSERT INTO sharia_policies (
                        version,
                        name,
                        authority_name,
                        authority_standard,
                        authority_reference_url,
                        effective_date,
                        verified_by,
                        verification_note,
                        ratios_json,
                        policy_hash,
                        is_active,
                        activated_at
                    ) VALUES (
                        :version,
                        :name,
                        :authority_name,
                        :authority_standard,
                        :authority_reference_url,
                        :effective_date,
                        :verified_by,
                        :verification_note,
                        :ratios_json,
                        :policy_hash,
                        1,
                        CURRENT_TIMESTAMP
                    )
                    SQL
                );
                $insert->execute([
                    'version' => $policy['version'],
                    'name' => $policy['name'],
                    'authority_name' => $policy['authority_name'],
                    'authority_standard' => $policy['authority_standard'],
                    'authority_reference_url' => $policy['authority_reference_url'],
                    'effective_date' => $policy['effective_date'],
                    'verified_by' => $policy['verified_by'],
                    'verification_note' => $policy['verification_note'],
                    'ratios_json' => $ratiosJson,
                    'policy_hash' => $hash,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return ['version' => $policy['version'], 'policy_hash' => $hash];
    }
}

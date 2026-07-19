<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use PDO;
use RuntimeException;
use Throwable;

final readonly class MultibaggerMethodologyInstaller
{
    public function __construct(private PDO $pdo, private MultibaggerMethodologyValidator $validator)
    {
    }

    /** @param array<string, mixed> $input @return array{version: string, methodology_hash: string} */
    public function installAndActivate(array $input): array
    {
        $definition = $this->validator->validate($input);
        $hash = $this->validator->hash($definition);
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT id, methodology_hash FROM multibagger_methodologies WHERE version = :version FOR UPDATE');
            $existing->execute(['version' => $definition['version']]);
            $row = $existing->fetch();
            if (is_array($row) && !hash_equals((string) $row['methodology_hash'], $hash)) {
                throw new RuntimeException('That methodology version already exists with different content. Use a new version.');
            }

            $this->pdo->exec('UPDATE multibagger_methodologies SET is_active = 0 WHERE is_active = 1');
            if (is_array($row)) {
                $activate = $this->pdo->prepare('UPDATE multibagger_methodologies SET is_active = 1, activated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $activate->execute(['id' => $row['id']]);
            } else {
                $insert = $this->pdo->prepare(
                    <<<'SQL'
                    INSERT INTO multibagger_methodologies (
                        version, name, effective_date, verified_by, verification_note,
                        definition_json, methodology_hash, is_active, activated_at
                    ) VALUES (
                        :version, :name, :effective_date, :verified_by, :verification_note,
                        :definition_json, :methodology_hash, 1, CURRENT_TIMESTAMP
                    )
                    SQL
                );
                $insert->execute([
                    'version' => $definition['version'],
                    'name' => $definition['name'],
                    'effective_date' => $definition['effective_date'],
                    'verified_by' => $definition['verified_by'],
                    'verification_note' => $definition['verification_note'],
                    'definition_json' => $json,
                    'methodology_hash' => $hash,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }

        return ['version' => $definition['version'], 'methodology_hash' => $hash];
    }
}

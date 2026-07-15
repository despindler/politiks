<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

use Closure;
use PDO;

final class AiPromptStore
{
    private ?PDO $connection = null;

    /** @param Closure():PDO $connectionFactory */
    public function __construct(private readonly Closure $connectionFactory)
    {
    }

    /** @return array{id:int,purpose:string,version:int,system_text:string,output_schema_version:string} */
    public function active(string $purpose): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $purpose) !== 1) {
            throw new AiFilterException('AI_PROMPT_INVALID', 'Der KI-Prompt-Zweck ist ungÃ¼ltig.', 500);
        }
        $statement = $this->connection()->prepare(
            'SELECT id, purpose, version, system_text, output_schema_version
             FROM ai_prompt_template WHERE purpose=? AND is_active=1 ORDER BY version DESC'
        );
        $statement->execute([$purpose]);
        $rows = $statement->fetchAll();
        if (count($rows) !== 1) {
            throw new AiFilterException(
                'AI_PROMPT_UNAVAILABLE',
                'Die KI-Filteranweisung ist nicht eindeutig konfiguriert.',
                503,
            );
        }
        $row = $rows[0];
        return [
            'id' => (int) $row['id'],
            'purpose' => (string) $row['purpose'],
            'version' => (int) $row['version'],
            'system_text' => (string) $row['system_text'],
            'output_schema_version' => (string) $row['output_schema_version'],
        ];
    }

    private function connection(): PDO
    {
        return $this->connection ??= ($this->connectionFactory)();
    }
}

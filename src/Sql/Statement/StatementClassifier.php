<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Statement;

/**
 * Classifies supported top-level SQLite statements, including WITH prefixes.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class StatementClassifier
{
    /**
     * Classify the type of a SQL statement.
     *
     * @return string|null Statement type: 'SELECT', 'INSERT', 'UPDATE', 'DELETE',
     *                     'CREATE_TABLE', 'DROP_TABLE', 'ALTER_TABLE', or null if unsupported.
     */
    public function classifyStatement(string $sql): ?string
    {
        $keywords = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\TopLevelKeywordScanner())->scanTopLevelKeywords($sql);
        if ($keywords === []) {
            return null;
        }

        $first = $keywords[0]['keyword'];
        $result = null;
        if ($first === 'WITH') {
            foreach ($keywords as $token) {
                if (!$token['afterGroup']) {
                    continue;
                }

                $result = match ($token['keyword']) {
                    'SELECT' => 'SELECT',
                    'INSERT', 'REPLACE' => 'INSERT',
                    'UPDATE' => 'UPDATE',
                    'DELETE' => 'DELETE',
                    default => null,
                };

                if ($result !== null) {
                    break;
                }
            }
        } else {
            $second = $keywords[1]['keyword'] ?? null;
            $third = $keywords[2]['keyword'] ?? null;
            $result = $this->classifyKeywords($first, $second, $third);
        }

        return $result;
    }

    /**
     * Maps leading keywords to supported statement kinds.
     */
    public function classifyKeywords(string $first, ?string $second, ?string $third): ?string
    {
        return match ($first) {
            'SELECT' => 'SELECT',
            'INSERT', 'REPLACE' => 'INSERT',
            'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
            'CREATE' => match ($second) {
                'TABLE' => 'CREATE_TABLE',
                'TEMP', 'TEMPORARY' => $third === 'TABLE' ? 'CREATE_TABLE' : null,
                default => null,
            },
            'DROP' => $second === 'TABLE' ? 'DROP_TABLE' : null,
            'ALTER' => $second === 'TABLE' ? 'ALTER_TABLE' : null,
            default => null,
        };
    }
}

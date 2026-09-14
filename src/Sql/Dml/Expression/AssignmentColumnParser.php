<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Dml\Expression;

/**
 * Reads an assignment target and removes any qualifying relation prefix.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class AssignmentColumnParser
{
    /**
     * @return array{name: string, end: int} Decoded column and first byte after its name.
     */
    public function parse(string $setClause, int $i): array
    {
        $len = strlen($setClause);
        $colStart = $i;
        if ($setClause[$i] === '"' || $setClause[$i] === '`' || $setClause[$i] === '[') {
            $quoteChar = $setClause[$i] === '[' ? ']' : $setClause[$i];
            $i++;
            while ($i < $len && $setClause[$i] !== $quoteChar) {
                if ($setClause[$i] === $quoteChar && $i + 1 < $len && $setClause[$i + 1] === $quoteChar) {
                    $i += 2;
                    continue;
                }
                $i++;
            }
            if ($i < $len) {
                $i++;
            }
        } else {
            while ($i < $len && $setClause[$i] !== '=' && !ctype_space($setClause[$i])) {
                $i++;
            }
        }
        $colName = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier(trim(substr($setClause, $colStart, $i - $colStart)));
        if (str_contains($colName, '.')) {
            $parts = explode('.', $colName);
            $colName = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\IdentifierDecoder())->unquoteIdentifier(trim(end($parts)));
        }

        return ['name' => $colName, 'end' => $i];
    }
}

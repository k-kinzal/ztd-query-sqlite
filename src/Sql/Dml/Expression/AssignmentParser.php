<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Dml\Expression;

use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses column assignments while preserving nested value expressions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class AssignmentParser
{
    /**
     * Extract SET assignments from an UPDATE statement.
     *
     * @return array<string, string> Column name => value expression.
     */
    public function extractUpdateAssignments(string $sql): array
    {
        $setClause = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(
            ['SET'],
            [['FROM'], ['WHERE'], ['ORDER', 'BY'], ['LIMIT'], ['RETURNING']],
        );
        if ($setClause === null) {
            return [];
        }

        return $this->parseAssignments($setClause);
    }

    /**
     * Extract ON CONFLICT update columns from an upsert statement.
     *
     * @return array<string, string> Column name => value expression.
     */
    public function extractOnConflictUpdates(string $sql): array
    {
        $action = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->topLevelClause(['DO'], [['RETURNING']]);
        if ($action === null) {
            return [];
        }
        $actionStream = SqlTokenStream::tokenize($action, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create());
        if ($actionStream->firstTopLevelKeyword() !== 'UPDATE') {
            return [];
        }
        $setClause = $actionStream->topLevelClause(['SET'], [['WHERE']]);
        if ($setClause === null) {
            return [];
        }

        return $this->parseAssignments($setClause);
    }

    /**
     * Parse SET assignments: col1 = val1, col2 = val2.
     *
     * @return array<string, string>
     */
    public function parseAssignments(string $setClause): array
    {
        $assignments = [];
        $length = strlen($setClause);
        $index = 0;
        while ($index < $length) {
            while ($index < $length && ctype_space($setClause[$index])) {
                $index++;
            }
            if ($index >= $length) {
                break;
            }
            $column = (new AssignmentColumnParser())->parse($setClause, $index);
            $index = $column['end'];
            while ($index < $length && (ctype_space($setClause[$index]) || $setClause[$index] === '=')) {
                $index++;
            }
            $end = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan())->end($setClause, $index);
            $value = trim(substr($setClause, $index, $end - $index));
            if ($column['name'] !== '' && $value !== '') {
                $assignments[$column['name']] = $value;
            }
            $index = $end;
            if ($index < $length && $setClause[$index] === ',') {
                $index++;
            }
        }

        return $assignments;
    }
}

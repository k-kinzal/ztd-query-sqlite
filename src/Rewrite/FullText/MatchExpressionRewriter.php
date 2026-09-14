<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\FullText;

use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Projects MATCH expressions onto shadowed full-text table columns.
 *
 * @phpstan-import-type TableContext from \ZtdQuery\Platform\Sqlite\Rewrite\FullText\SqliteFullTextSearchRewriter
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class MatchExpressionRewriter
{
    /**
     * Binds the collaborators used by this operation.
     */
    public function __construct(
        private SqliteParser $parser,
        private SqliteIdentifierQuoter $quoter
    ) {
    }

    /**
     * @param array<string, TableContext> $tables
     * @return array{start: int, end: int, replacement: string}|null
     */
    public function expressionEdit(SqlTokenStream $stream, SqlToken $operator, array $tables): ?array
    {
        if ($operator->isKeyword('MATCH')) {
            $allowsColumnName = true;
        } elseif ($operator->kind === SqlTokenKind::Symbol && $operator->text === '=') {
            $allowsColumnName = false;
        } else {
            return null;
        }

        $left = $stream->significantTokenBefore($operator);
        if ($left === null || !self::isIdentifier($left)) {
            return null;
        }
        $query = $stream->significantTokenAfter($operator);
        if ($query === null || !self::isQueryExpression($query)) {
            return null;
        }

        $name = $this->parser->unquoteIdentifier($left->text);
        $columns = (new FullTextColumns())->tableColumns($name, $tables);
        if ($columns === null && $allowsColumnName) {
            $columns = (new FullTextColumns())->matchingColumn($name, $tables);
        }
        if ($columns === null || $columns === []) {
            return null;
        }

        $documentParts = array_map(
            fn (string $column): string => "COALESCE(CAST({$this->quoter->quote($column)} AS TEXT), '')",
            $columns,
        );
        $document = 'LOWER(' . implode(" || ' ' || ", $documentParts) . ')';
        $needle = "LOWER(NULLIF(TRIM(CAST(({$query->text}) AS TEXT)), ''))";

        return [
            'start' => $left->offset,
            'end' => $query->endOffset(),
            'replacement' => "(INSTR($document, $needle) > 0)",
        ];
    }

    /**
     * Projects MATCH expressions onto shadowed full-text table columns.
     */
    public static function isIdentifier(SqlToken $token): bool
    {
        return match ($token->kind) {
            SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier => true,
            SqlTokenKind::String, SqlTokenKind::Number, SqlTokenKind::Parameter,
            SqlTokenKind::Symbol, SqlTokenKind::Comment, SqlTokenKind::Whitespace => false,
        };
    }

    /**
     * Projects MATCH expressions onto shadowed full-text table columns.
     */
    public static function isQueryExpression(SqlToken $token): bool
    {
        return in_array($token->kind, [SqlTokenKind::String, SqlTokenKind::Parameter], true);
    }
}

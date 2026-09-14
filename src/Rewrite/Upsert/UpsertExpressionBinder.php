<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Upsert;

use ZtdQuery\Platform\IdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Binds upsert column references while preserving nested subqueries.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class UpsertExpressionBinder
{
    /**
     * Binds accepted incoming namespaces and identifier quoting.
     *
     * @param non-empty-list<string> $incomingNamespaces
     */
    public function __construct(
        private array $incomingNamespaces,
        private IdentifierQuoter $quoter
    ) {
    }

    /**
     * @param list<string> $tableColumns
     */
    public function bindExpression(
        string $expression,
        string $tableName,
        array $tableColumns,
        string $unqualifiedAlias = '__ztd_existing',
    ): string {
        $tokens = SqlTokenStream::tokenize($expression, SqliteLexerProfile::create())->significantTokens();
        $subqueryTokens = $this->subqueryTokenIndexes($tokens);
        $replacements = [];
        $columnNames = array_fill_keys(array_map('strtolower', $tableColumns), true);
        $incomingNamespaces = array_fill_keys(array_map('strtolower', $this->incomingNamespaces), true);

        foreach ($tokens as $index => $token) {
            if (!$this->isIdentifier($token)) {
                continue;
            }
            if ($subqueryTokens[$index] ?? false) {
                continue;
            }
            $name = $this->identifier($token);
            $next = $tokens[$index + 1] ?? null;
            $afterNext = $tokens[$index + 2] ?? null;
            if ($next?->text === '.' && $afterNext !== null && $this->isIdentifier($afterNext)) {
                $namespace = strtolower($name);
                $alias = isset($incomingNamespaces[$namespace])
                    ? '__ztd_incoming'
                    : (strcasecmp($name, $tableName) === 0 ? '__ztd_existing' : null);
                if ($alias !== null) {
                    $replacements[] = [
                        'offset' => $token->offset,
                        'length' => $afterNext->endOffset() - $token->offset,
                        'value' => (new UpsertConflictPredicate($this->quoter))->qualified($alias, $this->identifier($afterNext)),
                    ];
                }
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            if ($previous?->text === '.' || $next?->text === '(' || !isset($columnNames[strtolower($name)])) {
                continue;
            }
            $replacements[] = [
                'offset' => $token->offset,
                'length' => strlen($token->text),
                'value' => (new UpsertConflictPredicate($this->quoter))->qualified($unqualifiedAlias, $name),
            ];
        }

        return (new \ZtdQuery\Platform\Sqlite\Rewrite\SqlEdits())->apply($expression, $replacements);
    }

    /**
     * @param list<SqlToken> $tokens
     * @return array<int, true>
     */
    public function subqueryTokenIndexes(array $tokens): array
    {
        $indexes = [];
        foreach ($tokens as $start => $token) {
            if (!$token->isKeyword('SELECT') || $token->isTopLevel()) {
                continue;
            }
            for ($index = $start; isset($tokens[$index]); ++$index) {
                $candidate = $tokens[$index];
                if ($candidate->depth < $token->depth) {
                    break;
                }
                $indexes[$index] = true;
            }
        }

        return $indexes;
    }

    /**
     * Binds upsert column references while preserving nested subqueries.
     */
    public function isIdentifier(SqlToken $token): bool
    {
        return in_array($token->kind, [SqlTokenKind::Word, SqlTokenKind::QuotedIdentifier], true);
    }

    /**
     * Binds upsert column references while preserving nested subqueries.
     */
    public function identifier(SqlToken $token): string
    {
        $identifier = SqlTokenStream::tokenize($token->text, SqliteLexerProfile::create())->identifierAt();

        return $identifier === null ? $token->text : $identifier['name'];
    }
}

<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Cte;

use ZtdQuery\Sql\SqlTokenStream;

/**
 * Merges fixture CTEs with existing WITH declarations without shadowing user CTEs.
 */
final class SqliteCteShadowComposer
{
    /**
     * @param array<string, string> $tableCtes
     */
    public function compose(string $sql, array $tableCtes): string
    {
        $ctes = (new CteDependencies())->required($sql, $tableCtes);
        $shadowedTables = array_keys($ctes);

        if ($ctes === []) {
            return $sql;
        }

        $sql = (new \ZtdQuery\Platform\Sqlite\Sql\Relation\SqliteSelectRelationParser())->unqualify($sql, $shadowedTables);
        $tokens = SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens();
        $with = $tokens[0] ?? null;
        if ($with === null || !$with->isKeyword('WITH')) {
            return 'WITH ' . implode(",\n", $ctes) . "\n" . $sql;
        }

        $insertionToken = $with;
        $next = $tokens[1] ?? null;
        if ($next !== null && $next->isTopLevel() && $next->isKeyword('RECURSIVE')) {
            $insertionToken = $next;
        }

        return substr_replace(
            $sql,
            ' ' . implode(",\n", $ctes) . ",\n",
            $insertionToken->endOffset(),
            0,
        );
    }

    /**
     * @return list<string>
     */
    public function declaredCteNames(string $sql): array
    {
        return (new CteHeaderParser())->parseHeader($sql)['names'];
    }

    /**
     * Preserves the original WITH prefix around a rewritten statement.
     */
    public function carryPrefix(string $originalSql, string $rewrittenStatement): string
    {
        $header = (new CteHeaderParser())->parseHeader($originalSql);
        if ($header['statementOffset'] === null) {
            return $rewrittenStatement;
        }

        $prefix = rtrim(substr($originalSql, 0, $header['statementOffset']));

        $rewrittenTokens = SqlTokenStream::tokenize($rewrittenStatement, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->significantTokens();
        $rewrittenWith = $rewrittenTokens[0] ?? null;
        if ($rewrittenWith !== null && $rewrittenWith->isKeyword('WITH')) {
            $rewrittenHeader = (new CteHeaderParser())->parseHeader($rewrittenStatement);
            $rewrittenStatementOffset = $rewrittenHeader['statementOffset'];
            if ($rewrittenStatementOffset === null) {
                return $prefix . "\n" . $rewrittenStatement;
            }

            $contentToken = $rewrittenWith;
            $rewrittenNext = $rewrittenTokens[1] ?? null;
            if ($rewrittenNext !== null && $rewrittenNext->isKeyword('RECURSIVE')) {
                $contentToken = $rewrittenNext;
            }

            $rewrittenBody = trim(substr(
                $rewrittenStatement,
                $contentToken->endOffset(),
                $rewrittenStatementOffset - $contentToken->endOffset(),
            ));
            $rewrittenTail = substr($rewrittenStatement, $rewrittenStatementOffset);
            if ((new CteReferences())->referencesAnyIdentifier($rewrittenBody, $header['names'])) {
                return $prefix . ",\n" . $rewrittenBody . "\n" . $rewrittenTail;
            }

            return (new CtePrefixMerger())->prepend(
                $originalSql,
                $header['statementOffset'],
                $rewrittenBody,
                $rewrittenTail,
            );
        }

        return $prefix . "\n" . $rewrittenStatement;
    }

    /**
     * Returns the main statement following any WITH declarations.
     */
    public function statementSql(string $sql): string
    {
        $offset = (new CteHeaderParser())->parseHeader($sql)['statementOffset'];

        return $offset === null ? $sql : substr($sql, $offset);
    }

}

<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

final class SqliteSelectRelationParser
{
    /** @return list<string> */
    public function fromClauses(string $sql): array
    {
        $tokens = $this->tokens($sql);
        /** @var array<int, array<int, bool>> $selectScopes */
        $selectScopes = [];
        $clauses = [];

        foreach ($tokens as $token) {
            if ($token->isKeyword('SELECT')) {
                $selectScopes[$token->depth][$token->bracketDepth] = true;
                continue;
            }
            if ($token->isKeyword('UNION') || $token->isKeyword('INTERSECT') || $token->isKeyword('EXCEPT')) {
                $selectScopes[$token->depth][$token->bracketDepth] = false;
                continue;
            }
            if (!$token->isKeyword('FROM') || ($selectScopes[$token->depth][$token->bracketDepth] ?? false) !== true) {
                continue;
            }

            $end = $this->findFromEnd($sql, $tokens, $token);
            $clause = trim(substr($sql, $token->endOffset(), $end - $token->endOffset()));
            if ($clause !== '') {
                $clauses[] = $clause;
            }
            $selectScopes[$token->depth][$token->bracketDepth] = false;
        }

        return $clauses;
    }

    /** @return list<string> */
    public function tableNames(string $sql): array
    {
        $names = [];
        foreach ($this->references($sql) as $reference) {
            $normalized = strtolower($reference['name']);
            if (!isset($names[$normalized])) {
                $names[$normalized] = $reference['name'];
            }
        }

        return array_values($names);
    }

    /** @return list<array{name: string, start: int, unqualifiedStart: int, end: int}> */
    public function references(string $sql): array
    {
        $tokens = $this->tokens($sql);
        /** @var array<int, array<int, bool>> $selectScopes */
        $selectScopes = [];
        $references = [];

        foreach ($tokens as $token) {
            if ($token->isKeyword('SELECT')) {
                $selectScopes[$token->depth][$token->bracketDepth] = true;
                continue;
            }
            if (!$token->isKeyword('FROM') || ($selectScopes[$token->depth][$token->bracketDepth] ?? false) !== true) {
                continue;
            }

            $clauseStart = $token->endOffset();
            $clauseEnd = $this->findFromEnd($sql, $tokens, $token);
            $clause = substr($sql, $clauseStart, $clauseEnd - $clauseStart);
            foreach ($this->referencesFromClause($clause) as $reference) {
                $references[] = [
                    'name' => $reference['name'],
                    'start' => $clauseStart + $reference['start'],
                    'unqualifiedStart' => $clauseStart + $reference['unqualifiedStart'],
                    'end' => $clauseStart + $reference['end'],
                ];
            }
            $selectScopes[$token->depth][$token->bracketDepth] = false;
        }

        return $references;
    }

    /** @param list<string> $relationNames */
    public function unqualify(string $sql, array $relationNames): string
    {
        $targets = array_map(strtolower(...), $relationNames);
        $removals = [];

        foreach ($this->references($sql) as $reference) {
            if ($reference['start'] === $reference['unqualifiedStart']
                || !in_array(strtolower($reference['name']), $targets, true)
            ) {
                continue;
            }
            $removals[] = ['start' => $reference['start'], 'end' => $reference['unqualifiedStart']];
        }

        usort($removals, static fn (array $left, array $right): int => $right['start'] <=> $left['start']);
        foreach ($removals as $removal) {
            $sql = substr_replace($sql, '', $removal['start'], $removal['end'] - $removal['start']);
        }

        return $sql;
    }

    /** @return list<array{name: string, start: int, unqualifiedStart: int, end: int}> */
    private function referencesFromClause(string $clause): array
    {
        $tokens = $this->tokens($clause);
        $references = [];
        $expectSource = true;

        foreach ($tokens as $index => $token) {
            if (!$token->isTopLevel()) {
                continue;
            }
            if ($token->isKeyword('JOIN')) {
                $expectSource = true;
                continue;
            }
            if ($token->kind === SqlTokenKind::Symbol && $token->text === ',') {
                $expectSource = true;
                continue;
            }
            if (!$expectSource) {
                continue;
            }
            if ($token->kind === SqlTokenKind::Symbol && $token->text === '(') {
                $closingToken = $this->closingToken($tokens, $index);
                if ($closingToken === null) {
                    continue;
                }
                $innerStart = $token->endOffset();
                $inner = substr($clause, $innerStart, $closingToken->offset - $innerStart);
                $innerTokens = $this->tokens($inner);
                if ($innerTokens === []) {
                    continue;
                }
                if ($innerTokens[0]->isKeyword('SELECT')
                    || $innerTokens[0]->isKeyword('WITH')
                    || $innerTokens[0]->isKeyword('VALUES')
                ) {
                    continue;
                }
                foreach ($this->referencesFromClause($inner) as $reference) {
                    $references[] = [
                        'name' => $reference['name'],
                        'start' => $innerStart + $reference['start'],
                        'unqualifiedStart' => $innerStart + $reference['unqualifiedStart'],
                        'end' => $innerStart + $reference['end'],
                    ];
                }
                continue;
            }

            $expectSource = false;
            $reference = $this->referenceAt($clause, $tokens, $index);
            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /** @param list<SqlToken> $tokens */
    private function closingToken(array $tokens, int $openingIndex): ?SqlToken
    {
        for ($index = $openingIndex; isset($tokens[$index]); $index++) {
            $candidate = $tokens[$index];
            if ($candidate->text === ')' && $candidate->isTopLevel()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<SqlToken> $tokens
     * @return array{name: string, start: int, unqualifiedStart: int, end: int}|null
     */
    private function referenceAt(string $sql, array $tokens, int $index): ?array
    {
        $token = $tokens[$index];
        if ($token->isKeyword('VALUES') || $token->isKeyword('SELECT') || $token->isKeyword('WITH')) {
            return null;
        }

        $component = $this->identifierComponentAt($tokens, $index);
        if ($component === null) {
            return null;
        }
        [$name, $nextIndex, $start, $unqualifiedStart, $end] = $component;

        while (($tokens[$nextIndex] ?? null)?->kind === SqlTokenKind::Symbol
            && $tokens[$nextIndex]->text === '.'
        ) {
            $component = $this->identifierComponentAt($tokens, $nextIndex + 1);
            if ($component === null) {
                break;
            }
            [$name, $nextIndex, , $unqualifiedStart, $end] = $component;
        }

        $next = $tokens[$nextIndex] ?? null;
        if ($next !== null && $next->kind === SqlTokenKind::Symbol && $next->text === '(') {
            return null;
        }

        return [
            'name' => $name,
            'start' => $start,
            'unqualifiedStart' => $unqualifiedStart,
            'end' => $end,
        ];
    }

    /**
     * @param list<SqlToken> $tokens
     * @return array{string, int, int, int, int}|null
     */
    private function identifierComponentAt(array $tokens, int $index): ?array
    {
        $token = $tokens[$index] ?? null;
        if ($token === null) {
            return null;
        }
        if ($token->kind === SqlTokenKind::Word) {
            return [$token->text, $index + 1, $token->offset, $token->offset, $token->endOffset()];
        }
        if ($token->kind !== SqlTokenKind::QuotedIdentifier) {
            return null;
        }
        $name = SqliteLexerProfile::create()->quotedIdentifierValue($token->text);
        if ($name === null) {
            return null;
        }

        return [$name, $index + 1, $token->offset, $token->offset, $token->endOffset()];
    }

    /** @param list<SqlToken> $tokens */
    private function findFromEnd(string $sql, array $tokens, SqlToken $fromToken): int
    {
        $terminators = [
            ['WHERE'], ['GROUP', 'BY'], ['HAVING'], ['WINDOW'], ['ORDER', 'BY'],
            ['LIMIT'], ['OFFSET'], ['RETURNING'],
            ['UNION'], ['INTERSECT'], ['EXCEPT'],
        ];
        $afterFrom = false;
        foreach ($tokens as $index => $token) {
            if (!$afterFrom) {
                $afterFrom = $token === $fromToken;
                continue;
            }
            if ($token->depth < $fromToken->depth || $token->bracketDepth < $fromToken->bracketDepth) {
                return $token->offset;
            }
            if ($token->depth !== $fromToken->depth || $token->bracketDepth !== $fromToken->bracketDepth) {
                continue;
            }
            foreach ($terminators as $sequence) {
                if ($this->matchesKeywordSequence($tokens, $index, $sequence)) {
                    return $token->offset;
                }
            }
        }

        return strlen($sql);
    }

    /**
     * @param list<SqlToken> $tokens
     * @param non-empty-list<string> $keywords
     */
    private function matchesKeywordSequence(array $tokens, int $index, array $keywords): bool
    {
        foreach ($keywords as $relative => $keyword) {
            $candidate = $tokens[$index + $relative] ?? null;
            if ($candidate === null || !$candidate->isKeyword($keyword)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<SqlToken> */
    private function tokens(string $sql): array
    {
        return SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
    }
}

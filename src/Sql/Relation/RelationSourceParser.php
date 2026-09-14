<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Relation;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses table sources, qualified identifiers and relation-clause boundaries.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class RelationSourceParser
{
    /**
     * @return list<array{name: string, start: int, unqualifiedStart: int, end: int}>
     */
    public function referencesFromClause(string $clause): array
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
                array_push($references, ...$this->nestedReferences($clause, $token->endOffset(), $closingToken->offset));
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

    /**
     * @param list<SqlToken> $tokens
     */
    public function closingToken(array $tokens, int $openingIndex): ?SqlToken
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
    public function referenceAt(string $sql, array $tokens, int $index): ?array
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
    public function identifierComponentAt(array $tokens, int $index): ?array
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

    /**
     * @param list<SqlToken> $tokens
     */
    public function findFromEnd(string $sql, array $tokens, SqlToken $fromToken): int
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
    public function matchesKeywordSequence(array $tokens, int $index, array $keywords): bool
    {
        foreach ($keywords as $relative => $keyword) {
            $candidate = $tokens[$index + $relative] ?? null;
            if ($candidate === null || !$candidate->isKeyword($keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<SqlToken>
     */
    public function tokens(string $sql): array
    {
        return SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens();
    }
    /**
     * Finds parenthesized join sources and translates their offsets to the enclosing clause.
     *
     * @return list<array{name: string, start: int, unqualifiedStart: int, end: int}>
     */
    public function nestedReferences(string $clause, int $start, int $end): array
    {
        $inner = substr($clause, $start, $end - $start);
        $first = $this->tokens($inner)[0] ?? null;
        if ($first === null || $first->isKeyword('SELECT') || $first->isKeyword('WITH') || $first->isKeyword('VALUES')) {
            return [];
        }
        $references = [];
        foreach ($this->referencesFromClause($inner) as $reference) {
            $references[] = [
                'name' => $reference['name'],
                'start' => $start + $reference['start'],
                'unqualifiedStart' => $start + $reference['unqualifiedStart'],
                'end' => $start + $reference['end'],
            ];
        }

        return $references;
    }

}

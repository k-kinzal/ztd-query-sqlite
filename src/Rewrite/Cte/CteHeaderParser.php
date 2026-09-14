<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite\Cte;

use ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Parses CTE declarations and locates the main statement.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class CteHeaderParser
{
    /**
     * @return array{names: list<string>, statementOffset: int|null}
     */
    public function parseHeader(string $sql): array
    {
        $tokens = [];
        foreach (SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->significantTokens() as $token) {
            if ($token->isTopLevel()) {
                $tokens[] = $token;
            }
        }
        if (($tokens[0] ?? null)?->isKeyword('WITH') !== true) {
            return ['names' => [], 'statementOffset' => null];
        }

        $index = 1;
        if (($tokens[$index] ?? null)?->isKeyword('RECURSIVE') === true) {
            $index++;
        }

        $names = [];
        while (isset($tokens[$index])) {
            $name = $this->identifierName($tokens[$index]);
            if ($name === null) {
                break;
            }
            $index++;

            $bodyEnd = $this->bodyEndIndex($tokens, $index);
            if ($bodyEnd === null) {
                return ['names' => $names, 'statementOffset' => null];
            }
            $index = $bodyEnd;
            $names[] = strtolower($name);

            $separator = $tokens[$index] ?? null;
            if (!$this->isSymbol($separator, ',')) {
                break;
            }
            $index++;
        }

        $statement = $tokens[$index] ?? null;

        return [
            'names' => $names,
            'statementOffset' => $statement?->offset,
        ];
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public function findAsIndex(array $tokens, int $start): ?int
    {
        for ($index = $start; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if ($token->isKeyword('AS')) {
                return $index;
            }
            if ($token->kind === SqlTokenKind::Word) {
                return null;
            }
        }

        return null;
    }

    /**
     * Parses CTE declarations and locates the main statement.
     */
    public function isSymbol(?SqlToken $token, string $symbol): bool
    {
        return $token instanceof SqlToken
            && $token->kind === SqlTokenKind::Symbol
            && $token->text === $symbol;
    }

    /**
     * Parses CTE declarations and locates the main statement.
     */
    public function identifierName(SqlToken $token): ?string
    {
        if ($token->kind === SqlTokenKind::Word) {
            return $token->text;
        }
        if ($token->kind !== SqlTokenKind::QuotedIdentifier || strlen($token->text) < 2) {
            return null;
        }

        $quote = $token->text[0];
        $inner = substr($token->text, 1, -1);

        return str_replace($quote . $quote, $quote, $inner);
    }

    /**
     * @param list<SqlToken> $tokens Top-level CTE header tokens.
     */
    public function bodyEndIndex(array $tokens, int $index): ?int
    {
        $asIndex = $this->findAsIndex($tokens, $index);
        $index = ($asIndex ?? count($tokens)) + 1;
        if (($tokens[$index] ?? null)?->isKeyword('NOT') === true) {
            $index++;
        }
        if (($tokens[$index] ?? null)?->isKeyword('MATERIALIZED') === true) {
            $index++;
        }
        if (!$this->isSymbol($tokens[$index] ?? null, '(')
            || !$this->isSymbol($tokens[$index + 1] ?? null, ')')
        ) {
            return null;
        }

        return $index + 2;
    }
}

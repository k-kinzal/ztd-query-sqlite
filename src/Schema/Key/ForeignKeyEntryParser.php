<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Schema\Key;

use ZtdQuery\Schema\Key\ForeignKeyDefinition;
use ZtdQuery\Schema\Key\ReferentialAction;
use ZtdQuery\Sql\SqlToken;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Extracts local and referenced columns and referential actions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ForeignKeyEntryParser
{
    /**
     * @return array{name: string, foreignKey: ForeignKeyDefinition}|null
     */
    public function parseEntry(
        SqlTokenStream $stream,
        string $defaultName,
        ?string $inlineColumn,
    ): ?array {
        $tokens = $stream->significantTokens();
        $references = ForeignKeyTokens::keywordIndex($tokens, 'REFERENCES');
        if ($references === null) {
            return null;
        }

        $columns = $inlineColumn !== null
            ? [$inlineColumn]
            : $this->foreignKeyColumns($stream, $tokens, $references);
        if ($columns === []) {
            return null;
        }

        $referenced = $this->referencedRelation($stream, $tokens, $references + 1);
        if ($referenced === null) {
            return null;
        }

        $name = $defaultName;
        if ($tokens[0]->isKeyword('CONSTRAINT')) {
            $constraint = $stream->identifierAt(1);
            if ($constraint !== null) {
                $name = $constraint['name'];
            }
        }

        return [
            'name' => $name,
            'foreignKey' => new ForeignKeyDefinition(
                $columns,
                $referenced['table'],
                $referenced['columns'],
                $this->action($tokens, 'DELETE'),
                $this->action($tokens, 'UPDATE'),
            ),
        ];
    }

    /**
     * @param list<SqlToken> $tokens
     * @return list<string>
     */
    public function foreignKeyColumns(
        SqlTokenStream $stream,
        array $tokens,
        int $references,
    ): array {
        $foreign = ForeignKeyTokens::keywordIndex(array_slice($tokens, 0, $references), 'FOREIGN');
        if ($foreign === null) {
            return [];
        }

        $key = $tokens[$foreign + 1];
        if (!$key->isKeyword('KEY')) {
            return [];
        }

        $opening = $foreign + 2;
        return $this->identifierList($stream, $tokens, $opening);
    }

    /**
     * @param list<SqlToken> $tokens
     * @return array{table: string, columns: list<string>}|null
     */
    public function referencedRelation(
        SqlTokenStream $stream,
        array $tokens,
        int $start,
    ): ?array {
        $identifier = $stream->identifierAt($start);
        if ($identifier === null) {
            return null;
        }

        $table = $identifier['name'];
        $next = $identifier['next'];
        while (ForeignKeyTokens::isSymbol($tokens[$next] ?? null, '.')) {
            $component = $stream->identifierAt($next + 1);
            if ($component === null) {
                return null;
            }
            $table = $component['name'];
            $next = $component['next'];
        }

        $opening = ForeignKeyTokens::symbolIndex($tokens, '(', $next);
        $columns = $opening !== null ? $this->identifierList($stream, $tokens, $opening) : [];

        return ['table' => $table, 'columns' => $columns];
    }

    /**
     * @param list<SqlToken> $tokens
     * @return list<string>
     */
    public function identifierList(SqlTokenStream $stream, array $tokens, int $opening): array
    {
        $depth = $tokens[$opening]->depth;
        $candidates = array_slice($tokens, $opening);
        array_shift($candidates);
        $identifiers = [];
        $index = $opening;
        foreach ($candidates as $token) {
            $index++;
            if ($token->depth === $depth) {
                if (ForeignKeyTokens::isSymbol($token, ')')) {
                    return $identifiers;
                }

                return [];
            }
            if ($token->depth !== $depth + 1) {
                continue;
            }

            $identifier = $stream->identifierAt($index);
            if ($identifier !== null) {
                $identifiers[] = $identifier['name'];
            }
        }

        return [];
    }

    /**
     * @param list<SqlToken> $tokens
     */
    public function action(array $tokens, string $event): ReferentialAction
    {
        foreach ($tokens as $index => $token) {
            if (!$token->isTopLevel() || !$token->isKeyword('ON')) {
                continue;
            }
            $eventToken = $tokens[$index + 1] ?? null;
            if ($eventToken === null || !$eventToken->isKeyword($event)) {
                continue;
            }

            $action = $tokens[$index + 2] ?? null;
            if ($action === null) {
                return ReferentialAction::NoAction;
            }
            if ($action->isKeyword('CASCADE')) {
                return ReferentialAction::Cascade;
            }
            if ($action->isKeyword('RESTRICT')) {
                return ReferentialAction::Restrict;
            }
            if (!$action->isKeyword('SET')) {
                return ReferentialAction::NoAction;
            }

            $qualifier = $tokens[$index + 3] ?? null;
            if ($qualifier === null) {
                return ReferentialAction::NoAction;
            }
            if ($qualifier->isKeyword('NULL')) {
                return ReferentialAction::SetNull;
            }
            if ($qualifier->isKeyword('DEFAULT')) {
                return ReferentialAction::SetDefault;
            }

            return ReferentialAction::NoAction;
        }

        return ReferentialAction::NoAction;
    }
}

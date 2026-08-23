<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Platform\ParameterBindingCompiler;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

final class SqlitePdoParameterBindingCompiler implements ParameterBindingCompiler
{
    public function __construct(
        private readonly SqliteCastRenderer $castRenderer = new SqliteCastRenderer(),
    ) {
    }

    public function compile(string $sql, ?array $params): array
    {
        if ($params === null) {
            return ['sql' => $sql, 'params' => null];
        }

        $replacements = [];
        $positionalIndex = 0;
        foreach (SqlTokenStream::tokenize($sql, SqliteLexerProfile::create())->tokens() as $token) {
            if ($token->kind !== SqlTokenKind::Parameter) {
                continue;
            }
            if ($token->text === '?') {
                $parameterKey = $positionalIndex++;
            } else {
                $name = ltrim($token->text, ':');
                $parameterKey = array_key_exists($name, $params) ? $name : $token->text;
            }
            if (!array_key_exists($parameterKey, $params)) {
                continue;
            }
            $type = $this->parameterType($params[$parameterKey]);
            if ($type !== null) {
                $replacements[$token->offset] = [
                    'length' => strlen($token->text),
                    'sql' => $this->castRenderer->renderCast($token->text, $type),
                ];
            }
        }

        if ($replacements !== []) {
            krsort($replacements);
            foreach ($replacements as $offset => $replacement) {
                $sql = substr_replace($sql, $replacement['sql'], $offset, $replacement['length']);
            }
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function parameterType(mixed $value): ?ColumnType
    {
        return match (true) {
            is_bool($value) => new ColumnType(ColumnTypeFamily::BOOLEAN, 'INTEGER'),
            is_int($value) => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER'),
            is_float($value) => new ColumnType(ColumnTypeFamily::DOUBLE, 'REAL'),
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Connection\Parameter;

use ZtdQuery\Platform\ParameterBindingCompiler;
use ZtdQuery\Schema\ColumnDeclaration;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Sql\SqlTokenKind;
use ZtdQuery\Sql\SqlTokenStream;

/**
 * Compiles PDO parameter values into typed SQLite literals.
 */
final class SqlitePdoParameterBindingCompiler implements ParameterBindingCompiler
{
    /**
     * Binds the dependencies used by this operation.
     */
    public function __construct(
        private readonly \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer $castRenderer = new \ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer(),
    ) {
    }

    /**
     * Compiles supplied PDO parameters into ordered typed bindings.
     */
    public function compile(string $sql, ?array $params): array
    {
        if ($params === null) {
            return ['sql' => $sql, 'params' => null];
        }

        $replacements = [];
        $positionalIndex = 0;
        foreach (SqlTokenStream::tokenize($sql, \ZtdQuery\Platform\Sqlite\Sql\SqliteLexerProfile::create())->tokens() as $token) {
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
            $value = $params[$parameterKey];
            $type = match (true) {
                is_bool($value) => new ColumnDeclaration(ColumnTypeFamily::BOOLEAN, 'INTEGER'),
                is_int($value) => new ColumnDeclaration(ColumnTypeFamily::INTEGER, 'INTEGER'),
                is_float($value) => new ColumnDeclaration(ColumnTypeFamily::DOUBLE, 'REAL'),
                default => null,
            };
            if ($type !== null) {
                $replacements[$token->offset] = [
                    'length' => strlen($token->text),
                    'sql' => $this->castRenderer->renderCast($token->text, $type),
                ];
            }
        }

        return ['sql' => (new ParameterReplacements())->apply($sql, $replacements), 'params' => $params];
    }

}

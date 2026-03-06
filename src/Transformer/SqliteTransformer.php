<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Transformer;

use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Rewrite\SqlTransformer;

/**
 * Composite SQL transformer for SQLite.
 *
 * Parses the SQL, determines its type, and delegates to the appropriate
 * sub-transformer.
 */
final class SqliteTransformer implements SqlTransformer
{
    private SqliteParser $parser;
    private SelectTransformer $selectTransformer;
    private InsertTransformer $insertTransformer;
    private UpdateTransformer $updateTransformer;
    private DeleteTransformer $deleteTransformer;

    public function __construct(
        SqliteParser $parser,
        SelectTransformer $selectTransformer,
        InsertTransformer $insertTransformer,
        UpdateTransformer $updateTransformer,
        DeleteTransformer $deleteTransformer
    ) {
        $this->parser = $parser;
        $this->selectTransformer = $selectTransformer;
        $this->insertTransformer = $insertTransformer;
        $this->updateTransformer = $updateTransformer;
        $this->deleteTransformer = $deleteTransformer;
    }

    /**
     * {@inheritDoc}
     */
    public function transform(string $sql, array $tables): string
    {
        $type = $this->parser->classifyStatement($sql);
        if ($type === null) {
            throw new UnsupportedSqlException($sql, 'Empty or unparseable');
        }

        return match ($type) {
            'SELECT' => $this->selectTransformer->transform($sql, $tables),
            'INSERT' => $this->insertTransformer->transform($sql, $tables),
            'UPDATE' => $this->updateTransformer->transform($sql, $tables),
            'DELETE' => $this->deleteTransformer->transform($sql, $tables),
            default => throw new UnsupportedSqlException($sql, 'Statement type not supported by transformer'),
        };
    }
}

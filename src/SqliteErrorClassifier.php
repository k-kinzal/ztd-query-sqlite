<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Platform\ErrorClassifier;
use ZtdQuery\Connection\Exception\DatabaseException;

/**
 * SQLite-specific error classifier.
 *
 * Classifies SQLite error codes to determine the type of error.
 * SQLite uses SQLITE_ERROR (1) for general SQL errors including
 * unknown columns and tables. More specific codes are available
 * via extended error codes.
 */
final class SqliteErrorClassifier implements ErrorClassifier
{
    /**
     * SQLite error code: General SQL error (includes unknown column/table).
     */
    private const SQLITE_ERROR = 1;

    /**
     * {@inheritDoc}
     */
    public function isUnknownSchemaError(DatabaseException $e): bool
    {
        $code = $e->getDriverErrorCode();
        if ($code === null) {
            return false;
        }

        if ($code === self::SQLITE_ERROR) {
            $message = strtolower($e->getMessage());

            return str_contains($message, 'no such table')
                || str_contains($message, 'no such column')
                || str_contains($message, 'has no column named');
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Value;

/**
 * Converts bound PHP values into quoted SQLite literals.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ValueLiteralRenderer
{
    /**
     * @param resource $stream
     */
    public function readStream($stream): string
    {
        $position = ftell($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        if ($position !== false) {
            fseek($stream, $position);
        }

        return $contents === false ? '' : $contents;
    }

    /**
     * Converts bound PHP values into quoted SQLite literals.
     */
    public function quoteValue(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

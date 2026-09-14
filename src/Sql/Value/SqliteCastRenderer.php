<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Value;

use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Schema\ColumnDeclaration;

/**
 * SQLite implementation of CastRenderer.
 *
 * Maps ColumnDeclaration to SQLite CAST syntax using SQLite's type affinity system.
 * SQLite supports: INTEGER, REAL, TEXT, BLOB, NUMERIC.
 */
final class SqliteCastRenderer implements CastRenderer
{
    /**
     * Wraps a SQL expression in a cast to its portable column type.
     */
    public function renderCast(string $expression, ColumnDeclaration $type): string
    {
        $castType = (new CastTypeMapper())->mapToCastType($type);

        return "CAST($expression AS $castType)";
    }

    /**
     * Renders a typed NULL expression for an empty shadow table.
     */
    public function renderNullCast(ColumnDeclaration $type): string
    {
        $castType = (new CastTypeMapper())->mapToCastType($type);

        return "CAST(NULL AS $castType)";
    }

}

# ZTD Query SQLite

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)

SQLite platform support for [ZTD Query PHP](https://github.com/k-kinzal/ztd-query-core). Provides SQL parsing, classification, rewriting, and schema management for SQLite.

## Overview

This package implements the SQLite-specific logic for ZTD (Zero Table Dependency) query transformation. It handles:

- **SQL Parsing** - Parse SQLite statements using a built-in regex-based parser
- **Query Classification** - Classify queries as READ, WRITE_SIMULATED, or DDL_SIMULATED
- **CTE Rewriting** - Transform SELECT queries to use CTE-shadowed fixture data
- **Result Select Query** - Convert INSERT/UPDATE/DELETE/REPLACE into SELECT queries returning affected rows
- **Schema Management** - Reflect and track SQLite table definitions via `sqlite_master` and `PRAGMA` queries
- **Error Classification** - Identify SQLite-specific error codes for unknown schema detection

This package is used internally by the [PDO adapter](https://github.com/k-kinzal/ztd-query-pdo-adapter), but can also be used directly for custom adapter implementations.

## Requirements

- PHP 8.1 or higher
- [k-kinzal/ztd-query-php](https://github.com/k-kinzal/ztd-query-core) (core)

## Installation

```bash
composer require k-kinzal/ztd-query-sqlite
```

## Usage

### Creating a SQLite Session

`SqliteSessionFactory` is the main entry point. It creates a fully configured `Session` instance for SQLite:

```php
use ZtdQuery\Config\ZtdConfig;
use ZtdQuery\Platform\Sqlite\SqliteSessionFactory;

// $connection implements ZtdQuery\Connection\ConnectionInterface
$session = SqliteSessionFactory::create($connection, ZtdConfig::default());
```

The factory automatically:
1. Reflects the database schema via `sqlite_master` and `PRAGMA` queries
2. Sets up the SQL parser, query guard, and all transformers
3. Configures the shadow store for virtual write tracking

### Query Classification

`SqliteQueryGuard` classifies SQL statements into query kinds:

```php
use ZtdQuery\Platform\Sqlite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Rewrite\QueryKind;

$parser = new SqliteParser();
$guard = new SqliteQueryGuard($parser);

$guard->classify('SELECT * FROM users');
// => QueryKind::READ

$guard->classify('INSERT INTO users (name) VALUES (\'Alice\')');
// => QueryKind::WRITE_SIMULATED

$guard->classify('CREATE TABLE logs (id INT)');
// => QueryKind::DDL_SIMULATED

$guard->classify('BEGIN');
// => null (unsupported)
```

### SQL Rewriting

`SqliteRewriter` transforms SQL statements for ZTD execution:

```php
use ZtdQuery\Platform\Sqlite\SqliteRewriter;

// Rewrite a single statement
$plan = $rewriter->rewrite('SELECT email FROM users WHERE id = 1');
// $plan->sql() returns the CTE-shadowed query
// $plan->kind() returns the QueryKind

// Rewrite multiple statements (e.g., multi-query)
$plans = $rewriter->rewriteMultiple('SELECT 1; SELECT 2');
```

### Error Classification

`SqliteErrorClassifier` identifies SQLite error codes related to unknown schemas:

```php
use ZtdQuery\Platform\Sqlite\SqliteErrorClassifier;

$classifier = new SqliteErrorClassifier();

$classifier->isUnknownSchemaError(1); // true (SQL error or missing database)
```

## Architecture

```
SqliteSessionFactory
    |
    +-- SqliteParser (regex-based SQL parsing)
    +-- SqliteQueryGuard (query classification)
    +-- SqliteSchemaReflector (database schema reflection via sqlite_master/PRAGMA)
    +-- SqliteSchemaParser (CREATE TABLE parsing)
    +-- SqliteRewriter (query rewriting orchestrator)
    |       +-- SqliteTransformer
    |       |       +-- SelectTransformer (CTE injection)
    |       |       +-- InsertTransformer (INSERT/REPLACE -> SELECT)
    |       |       +-- UpdateTransformer (UPDATE -> SELECT)
    |       |       +-- DeleteTransformer (DELETE -> SELECT)
    |       +-- SqliteMutationResolver (virtual DDL tracking)
    +-- SqliteErrorClassifier (error code classification)
```

## SQL Support

### Fully Supported

- **SELECT**: All clauses including JOIN, GROUP BY, HAVING, ORDER BY, LIMIT, OFFSET, UNION, INTERSECT, EXCEPT, subqueries, CTEs, window functions, DISTINCT, VALUES
- **INSERT**: VALUES, SELECT, DEFAULT VALUES, ON CONFLICT (upsert)
- **REPLACE**: VALUES, SELECT
- **UPDATE**: Single-table with WHERE, ORDER BY/LIMIT
- **DELETE**: Single-table with WHERE, ORDER BY/LIMIT
- **DDL**: CREATE TABLE, ALTER TABLE, DROP TABLE (virtual schema)
- **WITH**: CTE and recursive CTE

### Unsupported

- Triggers, views, virtual tables
- Database operations (ATTACH, DETACH)
- VACUUM, ANALYZE, REINDEX
- PRAGMA statements

## Development

```bash
# Run tests
composer test

# Run unit tests
composer test:unit

# Run linter (PHP-CS-Fixer + PHPStan level max)
composer lint

# Run fuzz tests
composer fuzz:robustness
composer fuzz:robustness:classify
composer fuzz:robustness:rewrite

# Fix code style
composer format
```

## License

MIT License. See [LICENSE](LICENSE) for details.

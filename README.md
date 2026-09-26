# ZTD Query SQLite

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Docs](https://img.shields.io/badge/docs-ztd--query--sqlite-0969da?logo=php&logoColor=white)](https://k-kinzal.github.io/ztd-query-php/k-kinzal/ztd-query-sqlite/)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/k-kinzal/ztd-query-php)

ZTD Query is a Zero Table Dependency testing library for PHP: it runs the SQL of an application on a real database engine without reading or writing any physical table. Before a query reaches the database, every table it references is replaced by a CTE holding the rows the test has written, and every INSERT, UPDATE, and DELETE is turned into a SELECT whose result is kept in the session, so later queries see the change. Tests therefore need no migrations, seeding, or cleanup, and they can run in parallel against one empty database. This package is the SQLite platform: it reads SQLite statements, rewrites them, and keeps track of the tables the session defines. Use it through the PDO adapter.

## Requirements

- PHP 8.1+
- SQLite 3.x

## Installation

With PDO:

```bash
composer require --dev k-kinzal/ztd-query-pdo-adapter k-kinzal/ztd-query-sqlite
```

## Usage

`ZtdPdo` extends `PDO`, so it can be passed wherever the application expects a PDO connection. Tables are created and filled through the same connection; they exist only in the session.

```php
use PDO;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Adapter\Pdo\ZtdPdo;

final class UserQueryTest extends TestCase
{
    public function testSelectsActiveUsers(): void
    {
        $pdo = new ZtdPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, active BOOLEAN NOT NULL)');
        $pdo->exec("INSERT INTO users (id, name, active) VALUES (1, 'Alice', TRUE), (2, 'Bob', FALSE)");

        $statement = $pdo->prepare('SELECT name FROM users WHERE active ORDER BY id');
        $statement->execute();

        self::assertSame(['Alice'], $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
```

`ZtdPdo::fromPdo($pdo)` wraps an existing connection instead of opening a new one, and `disableZtd()` and `enableZtd()` switch between the physical database and the session.

## Configuration

```php
use ZtdQuery\Adapter\Pdo\ZtdPdo;
use ZtdQuery\Config\UnknownSchemaBehavior;
use ZtdQuery\Config\UnsupportedSqlBehavior;
use ZtdQuery\Config\ZtdConfig;

$config = new ZtdConfig(
    // Unsupported statements: Exception (default), Notice, or Ignore
    unsupportedBehavior: UnsupportedSqlBehavior::Exception,

    // Tables the session does not know: Passthrough to the database (default), or Exception
    unknownSchemaBehavior: UnknownSchemaBehavior::Exception,

    // Per-statement overrides of unsupportedBehavior; the first matching rule wins
    behaviorRules: [
        'CREATE INDEX' => UnsupportedSqlBehavior::Ignore,       // case-insensitive prefix
        '/^SET\s+/i' => UnsupportedSqlBehavior::Notice,          // regular expression
    ],
);

$pdo = new ZtdPdo($dsn, $user, $password, config: $config);
```

`Ignore` skips the statement, `Notice` skips it and raises a PHP notice, and `Exception` throws an exception.

## SQL Support

| Statement | SQLite |
|-----------|--------|
| SELECT, including joins, grouping, set operations, subqueries, CTEs, recursive CTEs, and window functions | Supported |
| INSERT with VALUES or SELECT | Supported |
| Upsert | `ON CONFLICT`, `INSERT OR ...`, `REPLACE` |
| UPDATE and DELETE | Supported, including `UPDATE ... FROM` |
| `RETURNING` | Supported |
| TRUNCATE | – |
| CREATE TABLE, DROP TABLE | Supported |
| ALTER TABLE | Supported |
| BEGIN, COMMIT, ROLLBACK | Applied to the session: ROLLBACK discards the writes made since BEGIN |
| Views, indexes, routines, triggers, SET, and server or user administration | Unsupported |

Unsupported statements are handled as configured by `unsupportedBehavior`. The full specification is in [docs/spec.md](docs/spec.md).

## License

MIT License. See [LICENSE](LICENSE) for details.

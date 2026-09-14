<?php

declare(strict_types=1);

namespace Bench;

use PhpBench\Attributes as Benchmark;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;

/**
 * Measures fixed parser workloads with construction outside the timed subjects.
 */
final class ParserBench
{
    private SqliteParser $parser;

    private string $selectSql = 'SELECT u.id, u.name, o.status FROM users u JOIN orders o ON o.user_id = u.id WHERE u.id = 1';

    private string $insertSql = "INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')";

    /**
     * Recreates dependencies before each benchmark iteration.
     */
    public function setUp(): void
    {
        $this->parser = new SqliteParser();
    }

    /**
     * Measures one operation over the fixed SQL fixture.
     */
    #[Benchmark\BeforeMethods('setUp')]
    #[Benchmark\Revs(250)]
    public function benchClassifySelect(): ?string
    {
        return $this->parser->classifyStatement($this->selectSql);
    }

    /**
     * Measures one operation over the fixed SQL fixture.
     *
     * @return list<string>
     */
    #[Benchmark\BeforeMethods('setUp')]
    #[Benchmark\Revs(250)]
    public function benchSplitInsert(): array
    {
        return $this->parser->splitStatements($this->insertSql);
    }
}

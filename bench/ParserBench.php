<?php

declare(strict_types=1);

namespace Bench;

use ZtdQuery\Platform\Sqlite\SqliteParser;

final class ParserBench
{
    private SqliteParser $parser;

    private string $selectSql = 'SELECT u.id, u.name, o.status FROM users u JOIN orders o ON o.user_id = u.id WHERE u.id = 1';

    private string $insertSql = "INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')";

    public function setUp(): void
    {
        $this->parser = new SqliteParser();
    }

    /**
     * @BeforeMethods({"setUp"})
     * @Revs(250)
     * @Iterations(5)
     */
    public function benchClassifySelect(): void
    {
        $this->parser->classifyStatement($this->selectSql);
    }

    /**
     * @BeforeMethods({"setUp"})
     * @Revs(250)
     * @Iterations(5)
     */
    public function benchSplitInsert(): void
    {
        $this->parser->splitStatements($this->insertSql);
    }
}

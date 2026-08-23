<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use PHPUnit\Framework\TestCase;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\CastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteLexicalMasker;
use ZtdQuery\Platform\Sqlite\SqliteParser;
use ZtdQuery\Platform\Sqlite\Transformer\InsertSelectRenderer;
use ZtdQuery\Platform\Sqlite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\SqliteCastRenderer;
use ZtdQuery\Platform\Sqlite\SqliteIdentifierQuoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ZtdQuery\Schema\ColumnType;
use ZtdQuery\Schema\ColumnTypeFamily;
use ZtdQuery\Schema\IdentityGenerationStrategy;

#[CoversClass(InsertTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteSelectRelationParser::class)]
#[UsesClass(SqliteLexicalMasker::class)]
#[UsesClass(SqliteParser::class)]
#[UsesClass(SelectTransformer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteFullTextSearchRewriter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteIndexHintStripper::class)]
#[UsesClass(SqliteCastRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteValueRenderer::class)]
#[UsesClass(SqliteIdentifierQuoter::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\Transformer\InsertRowRenderer::class)]
#[UsesClass(InsertSelectRenderer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteCteShadowComposer::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteNativeUpsertProjector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteGeneratedColumnProjector::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class InsertTransformerTest extends TestCase
{
    public function testProjectsConflictExpressionUsingCandidateKeys(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
                'candidateKeys' => ['PRIMARY' => ['id']],
            ],
        ];

        $result = $transformer->transform(
            "INSERT INTO users (id, name) VALUES (1, 'Alice') ON CONFLICT (id) DO UPDATE SET name = excluded.name",
            $tables,
        );

        self::assertStringContainsString('"__ztd_incoming"."name"', $result);
        self::assertStringContainsString('__ztd_upsert_value_0', $result);
        self::assertStringNotContainsString('excluded.', $result);
    }

    public function testUsesInjectedCastRendererAndColumnTypes(): void
    {
        $castRenderer = self::createStub(CastRenderer::class);
        $castRenderer->method('renderCast')->willReturn('CUSTOM_CAST');
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer(), $castRenderer);
        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id'],
                'columnTypes' => ['id' => new ColumnType(ColumnTypeFamily::INTEGER, 'INTEGER')],
            ],
        ];

        self::assertStringContainsString(
            'SELECT CUSTOM_CAST AS "id"',
            $transformer->transform('INSERT INTO users (id) VALUES (1)', $tables),
        );
    }

    public function testTransformInsertValues(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users (id, name) VALUES (1, 'Alice')", $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('"id"', $result);
        self::assertStringContainsString('"name"', $result);
    }

    public function testTransformInsertMultipleValues(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users (id, name) VALUES (1, 'Alice'), (2, 'Bob')", $tables);

        self::assertStringContainsString('UNION ALL', $result);
    }

    public function testTransformInsertSelect(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
            'temp_users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('INSERT INTO users (id, name) SELECT id, name FROM temp_users', $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('temp_users', $result);
    }

    public function testTransformThrowsForNonInsert(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('SELECT * FROM users', []);
    }

    public function testTransformUsesTableContextForMissingColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [],
                'columns' => ['id', 'name'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO users VALUES (1, 'Alice')", $tables);

        self::assertStringContainsString('SELECT', $result);
        self::assertStringContainsString('"id"', $result);
        self::assertStringContainsString('"name"', $result);
    }

    public function testTransformInsertSelectUsesExplicitTargetColumnsForProjectionAndIdentity(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['archive' => [
            'rows' => [],
            'columns' => ['id', 'name', 'status'],
            'columnTypes' => [],
            'identityStrategies' => ['id' => IdentityGenerationStrategy::MaxValue],
        ]];

        $result = $transformer->transform('INSERT INTO archive (name) SELECT name FROM users', $tables);

        self::assertStringContainsString('1 + ROW_NUMBER() OVER () - 1 AS "id"', $result);
        self::assertStringContainsString('"__ztd_insert_0" AS "name"', $result);
        self::assertStringContainsString('NULL AS "status"', $result);
    }

    public function testTransformWithoutTableThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('INSERT', []);
    }

    public function testTransformWithoutColumnsOrContextThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO users VALUES (1, 'Alice')";

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform($sql, []);
    }

    public function testTransformMismatchedColumnCountThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO users (id, name, email) VALUES (1, 'Alice')";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(0);
        $transformer->transform($sql, []);
    }

    public function testTransformNoValuesThrows(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = 'INSERT INTO users (id) DEFAULT VALUES';

        $this->expectException(\RuntimeException::class);
        $transformer->transform($sql, []);
    }

    public function testTransformReplaceInto(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "REPLACE INTO users (id, name) VALUES (1, 'Alice')";

        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('AS "id"', $result);
        self::assertStringContainsString('AS "name"', $result);
    }

    public function testTransformInsertOrReplace(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT OR REPLACE INTO users (id, name) VALUES (1, 'Alice')";

        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('AS "id"', $result);
    }

    public function testTransformValueTrimmed(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO users (id, name) VALUES ( 1 , 'Alice' )";
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('1 AS "id"', $result);
        self::assertStringContainsString("'Alice' AS \"name\"", $result);
    }

    public function testTransformInsertSelectPassesThrough(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = 'INSERT INTO users (id, name) SELECT id, name FROM temp';
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('SELECT id, name FROM temp', $result);
    }

    public function testTransformColumnAsInOutput(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO t (col1, col2) VALUES (10, 20)";
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('10 AS "col1"', $result);
        self::assertStringContainsString('20 AS "col2"', $result);
    }

    public function testTransformThrowsForNonInsertSelectStatement(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('SELECT * FROM t', []);
    }

    public function testTransformThrowsForNoTarget(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $this->expectException(UnsupportedSqlException::class);
        $transformer->transform('INSERT', []);
    }

    public function testTransformWithTableContextColumnsUsedWhenNoInsertColumns(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            't' => [
                'rows' => [],
                'columns' => ['a', 'b'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform("INSERT INTO t VALUES (1, 2)", $tables);
        self::assertStringContainsString('1 AS "a"', $result);
        self::assertStringContainsString('2 AS "b"', $result);
    }

    public function testTransformMultipleValueSets(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $result = $transformer->transform("INSERT INTO t (a) VALUES (1), (2), (3)", []);
        self::assertStringContainsString('UNION ALL', $result);
        self::assertSame(2, substr_count($result, 'UNION ALL'));
    }

    public function testTransformWithValuesTrimmed(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $result = $transformer->transform("INSERT INTO t (a) VALUES ( 1 )", []);
        self::assertStringContainsString('1 AS "a"', $result);
    }

    public function testTransformInsertSelectWithColumnsAndShadowData(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $tables = [
            'users' => [
                'rows' => [['id' => 1]],
                'columns' => ['id'],
                'columnTypes' => [],
            ],
        ];

        $result = $transformer->transform('INSERT INTO t (a) SELECT id FROM users', $tables);
        self::assertStringContainsString('SELECT', $result);
    }

    public function testTransformValueTrimMatters(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO t (a) VALUES (  hello  )";
        $result = $transformer->transform($sql, []);
        self::assertStringContainsString('hello AS "a"', $result);
        self::assertStringNotContainsString('  hello  ', $result);
    }

    public function testTransformOutputContainsSelectKeyword(): void
    {
        $parser = new SqliteParser();
        $selectTransformer = new SelectTransformer();
        $transformer = new InsertTransformer($parser, $selectTransformer);

        $sql = "INSERT INTO t (a) VALUES (1)";
        $result = $transformer->transform($sql, []);
        self::assertStringStartsWith('SELECT ', $result);
    }

    public function testTransformProjectsOmittedAndExplicitDefaults(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['users' => [
            'rows' => [],
            'columns' => ['id', 'status', 'note'],
            'columnTypes' => [],
            'columnDefaults' => ['status' => "'active'"],
        ]];

        $result = $transformer->transform('INSERT INTO users (id, status) VALUES (1, DEFAULT)', $tables);

        self::assertStringContainsString('1 AS "id"', $result);
        self::assertStringContainsString("'active' AS \"status\"", $result);
        self::assertStringContainsString('NULL AS "note"', $result);
    }

    public function testTransformDefaultValuesProjectsCompleteRow(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['settings' => [
            'rows' => [],
            'columns' => ['enabled', 'label'],
            'columnTypes' => [],
            'columnDefaults' => ['enabled' => '1', 'label' => "'new'"],
        ]];

        $result = $transformer->transform('INSERT INTO settings DEFAULT VALUES', $tables);

        self::assertStringContainsString('1 AS "enabled"', $result);
        self::assertStringContainsString("'new' AS \"label\"", $result);
    }

    public function testTransformNormalizesSparseTableColumnKeys(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['users' => [
            'rows' => [],
            'columns' => [2 => 'id', 5 => 'name'],
            'columnTypes' => [],
        ]];

        $result = $transformer->transform("INSERT INTO users (id, name) VALUES (1, 'Alice')", $tables);

        self::assertStringContainsString('1 AS "id"', $result);
        self::assertStringContainsString("'Alice' AS \"name\"", $result);
    }

    public function testTransformAllocatesRowidValuesMonotonically(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['users' => [
            'rows' => [],
            'columns' => ['id', 'name'],
            'columnTypes' => [],
            'identityStrategies' => ['id' => IdentityGenerationStrategy::MaxValue],
        ]];

        $first = $transformer->transform("INSERT INTO users (name) VALUES ('Alice'), ('Bob')", $tables);
        $transformer->commitRewriteState();
        $second = $transformer->transform("INSERT INTO users (name) VALUES ('Carol')", $tables);

        self::assertStringContainsString('1 AS "id"', $first);
        self::assertStringContainsString('2 AS "id"', $first);
        self::assertStringContainsString('3 AS "id"', $second);
    }

    public function testUncommittedTransformDoesNotConsumeRowidValue(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['users' => [
            'rows' => [],
            'columns' => ['id', 'name'],
            'columnTypes' => [],
            'identityStrategies' => ['id' => IdentityGenerationStrategy::MaxValue],
        ]];

        $preview = $transformer->transform("INSERT INTO users (name) VALUES ('preview')", $tables);
        $executed = $transformer->transform("INSERT INTO users (name) VALUES ('executed')", $tables);

        self::assertStringContainsString('1 AS "id"', $preview);
        self::assertStringContainsString('1 AS "id"', $executed);
    }

    public function testTransformAllocatesRowidAfterExistingRows(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['users' => [
            'rows' => [['id' => 7, 'name' => 'Existing']],
            'columns' => ['id', 'name'],
            'columnTypes' => [],
            'identityStrategies' => ['id' => IdentityGenerationStrategy::MaxValue],
        ]];

        $result = $transformer->transform("INSERT INTO users (name) VALUES ('Alice')", $tables);

        self::assertStringContainsString('8 AS "id"', $result);
    }

    public function testExplicitIdentityDoesNotConsumeGeneratedIdentity(): void
    {
        $transformer = new InsertTransformer(new SqliteParser(), new SelectTransformer());
        $tables = ['users' => [
            'rows' => [],
            'columns' => ['id', 'name'],
            'columnTypes' => [],
            'identityStrategies' => ['id' => IdentityGenerationStrategy::MaxValue],
        ]];

        $transformer->transform("INSERT INTO users (id, name) VALUES (42, 'explicit')", $tables);
        $transformer->commitRewriteState();
        $generated = $transformer->transform("INSERT INTO users (name) VALUES ('generated')", $tables);

        self::assertStringContainsString('1 AS "id"', $generated);
    }
}

<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Rewrite;

use ZtdQuery\Exception\UnknownSchemaException;
use ZtdQuery\Exception\UnsupportedSqlException;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Rewrite\MultiRewritePlan;
use ZtdQuery\Rewrite\RewritePlan;
use ZtdQuery\Rewrite\RewriteStateCommitter;
use ZtdQuery\Rewrite\SqlRewriter;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Schema\ViewDefinitionSet;
use ZtdQuery\Shadow\ShadowStore;
use ZtdQuery\Sql\TransactionStatement;

/**
 * SQLite rewrite implementation for ZTD.
 *
 * Orchestrates parsing, classification, transformation, and mutation resolution.
 * Uses Result Select Query approach (not RETURNING) for consistency.
 * @visibility public
 * @example Compose a SQLite rewriter with caller-owned state
 *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
 *     $store = new \ZtdQuery\Shadow\ShadowStore();
 *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
 *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
 *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
 *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
 *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
 *     $rewriter->rewrite('SELECT 1')->sql() // => 'SELECT 1'
 */
final class SqliteRewriter implements SqlRewriter, RewriteStateCommitter
{
    /**
     * Returns transaction statement.
     * @visibility public
     * @example Recognize transaction commands independently of query classification
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $rewriter->transactionStatement('BEGIN') instanceof \ZtdQuery\Sql\TransactionStatement // => true
     *     $rewriter->transactionStatement('SELECT 1') // => null
     */
    public function transactionStatement(string $sql): ?TransactionStatement
    {
        return (new \ZtdQuery\Platform\Sqlite\Sql\Transaction\SqliteTransactionStatementParser())->parse($sql);
    }

    private SqliteQueryGuard $guard;
    private ShadowStore $shadowStore;
    private TableDefinitionRegistry $registry;
    private SqliteTransformer $transformer;
    private \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver $mutationResolver;
    private \ZtdQuery\Platform\Sqlite\Sql\SqliteParser $parser;
    private Returning\SqliteReturningProjectionParser $returningProjectionParser;
    private Cte\SqliteCteShadowComposer $cteComposer;
    private ViewDefinitionSet $views;

    /**
     * Binds the dependencies used by this operation.
     * @visibility public
     * @example Bind schema, shadow data and transformers
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $rewriter->rewrite('SELECT 1')->kind() // => \ZtdQuery\Rewrite\QueryKind::READ
     */
    public function __construct(
        SqliteQueryGuard $guard,
        ShadowStore $shadowStore,
        TableDefinitionRegistry $registry,
        SqliteTransformer $transformer,
        \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver $mutationResolver,
        \ZtdQuery\Platform\Sqlite\Sql\SqliteParser $parser,
        ?ViewDefinitionSet $views = null,
    ) {
        $this->guard = $guard;
        $this->shadowStore = $shadowStore;
        $this->registry = $registry;
        $this->transformer = $transformer;
        $this->mutationResolver = $mutationResolver;
        $this->parser = $parser;
        $this->returningProjectionParser = new Returning\SqliteReturningProjectionParser();
        $this->cteComposer = new Cte\SqliteCteShadowComposer();
        $this->views = $views ?? new ViewDefinitionSet();
    }

    /**
     * {@inheritDoc}
     *
     * @throws UnsupportedSqlException When SQL is empty, unparseable, or multi-statement.
     * @throws UnknownSchemaException When SQL references unknown tables/columns.
     * @visibility public
     * @example Reject multiple statements on the single-statement API
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $rewriter->rewrite('SELECT 1; SELECT 2') // throws \ZtdQuery\Exception\UnsupportedSqlException: Multi-statement
     */
    public function rewrite(string $sql): RewritePlan
    {
        $statements = $this->parser->splitStatements($sql);
        if ($statements === []) {
            throw new UnsupportedSqlException($sql, 'Empty or unparseable');
        }

        if (count($statements) === 1) {
            return (new StatementRewriter($this->cteComposer, $this->guard, $this->mutationResolver, $this->parser, $this->registry, $this->returningProjectionParser, $this->shadowStore, $this->transformer, $this->views))->rewriteStatement($statements[0], $sql);
        }

        throw new UnsupportedSqlException($sql, 'Multi-statement');
    }

    /**
     * {@inheritDoc}
     *
     * @throws UnsupportedSqlException When SQL is empty or unparseable.
     * @throws UnknownSchemaException When SQL references unknown tables/columns.
     * @visibility public
     * @example Produce an ordered multi-statement plan
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $plans = $rewriter->rewriteMultiple('SELECT 1; SELECT 2');
     *     $plans->count() // => 2
     *     $plans->get(1)?->sql() // => 'SELECT 2'
     */
    public function rewriteMultiple(string $sql): MultiRewritePlan
    {
        $statements = $this->splitStatements($sql);

        if ($statements === []) {
            throw new UnsupportedSqlException($sql, 'Empty or unparseable');
        }

        $plans = [];
        foreach ($statements as $statement) {
            $plans[] = (new StatementRewriter($this->cteComposer, $this->guard, $this->mutationResolver, $this->parser, $this->registry, $this->returningProjectionParser, $this->shadowStore, $this->transformer, $this->views))->rewriteStatement($statement, $statement);
        }

        return new MultiRewritePlan($plans);
    }

    /**
     * {@inheritDoc}
     * @visibility public
     * @example Split statements while preserving quoted semicolons
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $rewriter->splitStatements("SELECT ';'; SELECT 2") // => ["SELECT ';'", 'SELECT 2']
     */
    public function splitStatements(string $sql): array
    {
        return $this->parser->splitStatements($sql);
    }

    /**
     * Commits the transformer state after a rewrite completes.
     * @visibility public
     * @example Commit rewrite state after execution
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $rewriter->rewrite('SELECT 1');
     *     $rewriter->commitRewriteState();
     *     $rewriter->rewrite('SELECT 2')->sql() // => 'SELECT 2'
     */
    public function commitRewriteState(): void
    {
        $this->transformer->commitRewriteState();
    }

    /**
     * Returns a SELECT that produces no result rows.
     * @visibility public
     * @example Produce a SELECT with no rows
     *     $parser = new \ZtdQuery\Platform\Sqlite\Sql\SqliteParser();
     *     $store = new \ZtdQuery\Shadow\ShadowStore();
     *     $registry = new \ZtdQuery\Schema\TableDefinitionRegistry();
     *     $select = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer();
     *     $transformer = new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer($parser, $select, new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer($parser, $select), new \ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer($parser, $select));
     *     $resolver = new \ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver($store, $registry, new \ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser(), $parser);
     *     $rewriter = new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter(new \ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard($parser), $store, $registry, $transformer, $resolver, $parser);
     *     $sql = $rewriter->emptyResultSelect();
     *     $pdo = new \PDO('sqlite::memory:');
     *     $pdo->query($sql)->fetchAll() // => []
     */
    public function emptyResultSelect(): string
    {
        return 'SELECT 1 WHERE 0';
    }
}

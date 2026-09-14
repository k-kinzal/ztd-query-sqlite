<?php

declare(strict_types=1);

namespace Fuzz\Robustness\Target;

use ZtdQuery\Platform\Sqlite\Rewrite\SqliteQueryGuard;
use ZtdQuery\Platform\Sqlite\Rewrite\SqliteRewriter;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\DeleteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\InsertTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SelectTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\SqliteTransformer;
use ZtdQuery\Platform\Sqlite\Rewrite\Transformer\UpdateTransformer;
use ZtdQuery\Platform\Sqlite\Schema\SqliteSchemaParser;
use ZtdQuery\Platform\Sqlite\Shadow\SqliteMutationResolver;
use ZtdQuery\Platform\Sqlite\Sql\SqliteIdentifierQuoter;
use ZtdQuery\Platform\Sqlite\Sql\SqliteParser;
use ZtdQuery\Platform\Sqlite\Sql\Value\SqliteCastRenderer;
use ZtdQuery\Schema\TableDefinitionRegistry;
use ZtdQuery\Shadow\ShadowStore;

/**
 * Verifies each rewrite once against a fresh schema registry and shadow store.
 */
final class RewriteTarget
{
    /**
     * Discards all rewrite and mutation state after this input, including on failure.
     */
    public function __invoke(string $sql): void
    {
        $store = new ShadowStore();
        $registry = new TableDefinitionRegistry();
        $schemaParser = new SqliteSchemaParser();
        $schemas = [
            'users' => 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT, status TEXT)',
            'orders' => 'CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, amount REAL, created_at TEXT)',
            'order_items' => 'CREATE TABLE order_items (order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, quantity INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (order_id, product_id))',
            'products' => 'CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price REAL, category TEXT)',
        ];
        foreach ($schemas as $table => $sqlSchema) {
            $definition = $schemaParser->parse($sqlSchema);
            if ($definition !== null) {
                $registry->register($table, $definition);
            }
        }
        $fixtureRows = [
            'users' => [
                ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
                ['id' => '2', 'name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'pending'],
                ['id' => '3', 'name' => 'Charlie', 'email' => null, 'status' => 'active'],
            ],
            'orders' => [
                ['id' => '1', 'user_id' => '1', 'amount' => '100.00', 'created_at' => '2024-01-01 00:00:00'],
                ['id' => '2', 'user_id' => '2', 'amount' => '250.50', 'created_at' => '2024-01-02 12:30:00'],
            ],
            'order_items' => [
                ['order_id' => '1', 'product_id' => '1', 'quantity' => '2'],
                ['order_id' => '1', 'product_id' => '2', 'quantity' => '1'],
                ['order_id' => '2', 'product_id' => '1', 'quantity' => '3'],
            ],
            'products' => [
                ['id' => '1', 'name' => 'Widget', 'price' => '19.99', 'category' => 'tools'],
                ['id' => '2', 'name' => 'Gadget', 'price' => '49.99', 'category' => 'electronics'],
            ],
        ];
        foreach ($fixtureRows as $table => $rows) {
            $store->set($table, $rows);
        }
        $parser = new SqliteParser();
        $guard = new SqliteQueryGuard($parser);
        $castRenderer = new SqliteCastRenderer();
        $quoter = new SqliteIdentifierQuoter();
        $selectTransformer = new SelectTransformer($castRenderer, $quoter);
        $insertTransformer = new InsertTransformer($parser, $selectTransformer);
        $updateTransformer = new UpdateTransformer($parser, $selectTransformer);
        $deleteTransformer = new DeleteTransformer($parser, $selectTransformer);
        $transformer = new SqliteTransformer($parser, $selectTransformer, $insertTransformer, $updateTransformer, $deleteTransformer);
        $schemaParser = new SqliteSchemaParser();
        $mutationResolver = new SqliteMutationResolver($store, $registry, $schemaParser, $parser);
        $rewriter = new SqliteRewriter($guard, $store, $registry, $transformer, $mutationResolver, $parser);
        RewriteCheck::verify(new SqliteQueryGuard(new SqliteParser()), $rewriter, $sql);
    }
}

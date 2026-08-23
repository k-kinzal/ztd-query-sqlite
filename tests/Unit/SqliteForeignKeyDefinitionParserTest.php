<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZtdQuery\Platform\Sqlite\SqliteForeignKeyDefinitionParser;
use ZtdQuery\Schema\ReferentialAction;

#[CoversClass(SqliteForeignKeyDefinitionParser::class)]
#[UsesClass(\ZtdQuery\Platform\Sqlite\SqliteLexerProfile::class)]
final class SqliteForeignKeyDefinitionParserTest extends TestCase
{
    public function testParsesNamedCompositeConstraintWithoutRegexSubstitution(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            'CREATE TABLE child (id INT, tenant_id INT, parent_id INT, '
            . 'CONSTRAINT "fk child parent" FOREIGN KEY (tenant_id, parent_id) '
            . 'REFERENCES app.parents (tenant_id, id) ON UPDATE CASCADE ON DELETE SET NULL)',
        );

        self::assertSame(['fk child parent'], array_keys($foreignKeys));
        self::assertSame(['tenant_id', 'parent_id'], $foreignKeys['fk child parent']->columns);
        self::assertSame('parents', $foreignKeys['fk child parent']->referencedTable);
        self::assertSame(['tenant_id', 'id'], $foreignKeys['fk child parent']->referencedColumns);
        self::assertSame(ReferentialAction::SetNull, $foreignKeys['fk child parent']->onDelete);
        self::assertSame(ReferentialAction::Cascade, $foreignKeys['fk child parent']->onUpdate);
    }

    public function testParsesInlineAndSqliteQuotedReferences(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            'CREATE TABLE [child] ([id] INT, [parent_id] INT REFERENCES [app].[parents] ([id]) ON DELETE RESTRICT)',
        );

        self::assertCount(1, $foreignKeys);
        $foreignKey = array_values($foreignKeys)[0];
        self::assertSame(['parent_id'], $foreignKey->columns);
        self::assertSame('parents', $foreignKey->referencedTable);
        self::assertSame(['id'], $foreignKey->referencedColumns);
        self::assertSame(ReferentialAction::Restrict, $foreignKey->onDelete);
        self::assertSame(ReferentialAction::NoAction, $foreignKey->onUpdate);
    }

    public function testUsesReferencedPrimaryKeyWhenColumnListIsOmitted(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            'CREATE TABLE child (parent_id INTEGER REFERENCES parent ON DELETE CASCADE)',
        );

        self::assertSame([], array_values($foreignKeys)[0]->referencedColumns);
        self::assertSame(ReferentialAction::Cascade, array_values($foreignKeys)[0]->onDelete);
    }

    public function testRejectsStatementsAndMalformedReferencesWithoutTableBody(): void
    {
        $parser = new SqliteForeignKeyDefinitionParser();

        self::assertSame([], $parser->parseCreateTable('CREATE TABLE child AS SELECT 1'));
        self::assertSame([], $parser->parseCreateTable('CREATE TABLE child (id INT, FOREIGN KEY REFERENCES parent(id))'));
    }

    public function testParsesEveryActionAndAssignsSequentialSyntheticNames(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            'create table child ('
            . 'id int, '
            . 'cascade_id int references cascade_parent(id) on delete cascade on update restrict, '
            . 'default_id int references default_parent(id) on delete set default on update no action, '
            . 'null_id int references null_parent(id) on delete set null, '
            . 'foreign key (id, cascade_id) references composite_parent(tenant_id, id) '
            . 'on delete restrict on update cascade'
            . ')',
        );

        self::assertSame(['foreign_0', 'foreign_1', 'foreign_2', 'foreign_3'], array_keys($foreignKeys));
        self::assertSame(ReferentialAction::Cascade, $foreignKeys['foreign_0']->onDelete);
        self::assertSame(ReferentialAction::Restrict, $foreignKeys['foreign_0']->onUpdate);
        self::assertSame(ReferentialAction::SetDefault, $foreignKeys['foreign_1']->onDelete);
        self::assertSame(ReferentialAction::NoAction, $foreignKeys['foreign_1']->onUpdate);
        self::assertSame(ReferentialAction::SetNull, $foreignKeys['foreign_2']->onDelete);
        self::assertSame(['id', 'cascade_id'], $foreignKeys['foreign_3']->columns);
        self::assertSame(['tenant_id', 'id'], $foreignKeys['foreign_3']->referencedColumns);
        self::assertSame(ReferentialAction::Restrict, $foreignKeys['foreign_3']->onDelete);
        self::assertSame(ReferentialAction::Cascade, $foreignKeys['foreign_3']->onUpdate);
    }

    public function testUsesOnlyCreateTableTopLevelParentheses(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            "CREATE TABLE child (id INT, note TEXT DEFAULT '(not structure)', "
            . 'parent_id INT, FOREIGN KEY (parent_id) REFERENCES parent(id)) WITHOUT ROWID',
        );

        self::assertSame(['foreign_0'], array_keys($foreignKeys));
        self::assertSame(['parent_id'], $foreignKeys['foreign_0']->columns);
        self::assertSame('parent', $foreignKeys['foreign_0']->referencedTable);
        self::assertSame(['id'], $foreignKeys['foreign_0']->referencedColumns);
    }

    public function testRejectsNonCreateAndMalformedForeignKeyStructures(): void
    {
        $parser = new SqliteForeignKeyDefinitionParser();

        self::assertSame([], $parser->parseCreateTable(
            'WRAP (FOREIGN KEY (bad_id) REFERENCES wrong(id)) CREATE TABLE child '
            . '(parent_id INT REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN (id) REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN KEY (id) REFERENCES)',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN KEY (id REFERENCES parent(id))',
        ));
    }

    public function testDoesNotTreatOtherCreateStatementParenthesesAsTableBodies(): void
    {
        $parser = new SqliteForeignKeyDefinitionParser();

        self::assertSame([], $parser->parseCreateTable(
            'CREATE VIEW child AS fn(parent_id INT REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TYPE child AS (parent_id INT REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT), fake_id INT REFERENCES fake_parent(id)',
        ));
    }

    public function testMalformedActionsRemainNoActionWithoutTokenLookaheadFailures(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            'CREATE TABLE child ('
            . 'bare_id INT REFERENCES bare_parent, '
            . 'on_id INT REFERENCES on_parent(id) ON, '
            . 'delete_id INT REFERENCES delete_parent(id) ON DELETE, '
            . 'set_id INT REFERENCES set_parent(id) ON DELETE SET, '
            . 'unknown_id INT REFERENCES unknown_parent(id) ON DELETE UNKNOWN NULL'
            . ')',
        );

        self::assertSame(
            ['foreign_0', 'foreign_1', 'foreign_2', 'foreign_3', 'foreign_4'],
            array_keys($foreignKeys),
        );
        self::assertSame('bare_parent', $foreignKeys['foreign_0']->referencedTable);
        self::assertSame([], $foreignKeys['foreign_0']->referencedColumns);
        self::assertSame(ReferentialAction::NoAction, $foreignKeys['foreign_1']->onDelete);
        self::assertSame(ReferentialAction::NoAction, $foreignKeys['foreign_2']->onDelete);
        self::assertSame(ReferentialAction::NoAction, $foreignKeys['foreign_3']->onDelete);
        self::assertSame(ReferentialAction::NoAction, $foreignKeys['foreign_4']->onDelete);
    }

    public function testNestedActionTokensCannotOverrideTopLevelAction(): void
    {
        $foreignKeys = (new SqliteForeignKeyDefinitionParser())->parseCreateTable(
            'CREATE TABLE child ('
            . 'parent_id INT REFERENCES parent(id) CHECK (ON DELETE CASCADE) ON DELETE RESTRICT'
            . ')',
        );

        self::assertSame(ReferentialAction::Restrict, $foreignKeys['foreign_0']->onDelete);
    }

    public function testForeignKeyClauseRequiresImmediateKeyAndColumnListTokens(): void
    {
        $parser = new SqliteForeignKeyDefinitionParser();

        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN WRONG KEY (id) REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN WRONG (id) REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN KEY id REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN KEY () REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, CONSTRAINT fk REFERENCES parent(id) FOREIGN KEY (id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, CONSTRAINT KEY (id) REFERENCES parent(id))',
        ));
        self::assertSame([], $parser->parseCreateTable(
            'CREATE TABLE child (id INT, FOREIGN KEY (id) REFERENCES parent.)',
        ));
    }
}

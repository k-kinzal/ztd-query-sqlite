<?php

declare(strict_types=1);

namespace Tests\Unit\Shadow\Mutation\Table\Alter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use ZtdQuery\Platform\Sqlite\Shadow\Mutation\Table\Alter\ColumnDefinitionEditor;
use ZtdQuery\Schema\Key\ForeignKeyDefinition;
use ZtdQuery\Schema\Key\ReferentialAction;

#[CoversClass(ColumnDefinitionEditor::class)]
final class ColumnDefinitionEditorTest extends TestCase
{
    public function testWithoutColumnRemovesAllMatchesAndReindexes(): void
    {
        self::assertSame(['b', 'c'], ColumnDefinitionEditor::withoutColumn([3 => 'a', 4 => 'b', 5 => 'a', 8 => 'c'], 'a'));
        self::assertSame(['a'], ColumnDefinitionEditor::withoutColumn(['a'], 'A'));
    }

    public function testWithoutMapKeyPreservesOtherValuesAndOrder(): void
    {
        $value = new stdClass();
        self::assertSame(['a' => $value, 'c' => 3], ColumnDefinitionEditor::withoutMapKey(['a' => $value, 'b' => 2, 'c' => 3], 'b'));
    }

    public function testRenamedColumnsPreservesOrderAndUntouchedNames(): void
    {
        self::assertSame(['new', 'keep', 'new'], ColumnDefinitionEditor::renamedColumns(['old', 'keep', 'old'], 'old', 'new'));
    }

    public function testRenamedForeignKeyPreservesReferencedRelationAndActions(): void
    {
        $original = new ForeignKeyDefinition(['tenant', 'parent'], 'other', ['tenant', 'id'], ReferentialAction::Cascade, ReferentialAction::SetDefault);
        $renamed = ColumnDefinitionEditor::renamedForeignKey($original, 'parent', 'parent_id');
        self::assertSame(['tenant', 'parent_id'], $renamed->columns);
        self::assertSame('other', $renamed->referencedTable);
        self::assertSame(['tenant', 'id'], $renamed->referencedColumns);
        self::assertSame(ReferentialAction::Cascade, $renamed->onDelete);
        self::assertSame(ReferentialAction::SetDefault, $renamed->onUpdate);
        self::assertSame(['tenant', 'parent'], $original->columns);
    }

    public function testRenamedMapKeyPreservesValuesAndInsertionOrder(): void
    {
        $value = new stdClass();
        self::assertSame(['new' => $value, 'keep' => 2], ColumnDefinitionEditor::renamedMapKey(['old' => $value, 'keep' => 2], 'old', 'new'));
    }

}

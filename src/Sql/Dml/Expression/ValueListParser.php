<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite\Sql\Dml\Expression;

/**
 * Splits parenthesized VALUES rows without splitting nested expressions.
 *
 * @visibility ZtdQuery\Platform\Sqlite
 */
final class ValueListParser
{
    /**
     * Parse VALUE sets: (val1, val2), (val3, val4).
     *
     * @return array<int, array<int, string>>
     */
    public function parseValueSets(string $rest): array
    {
        $sets = [];
        $length = strlen($rest);
        $index = 0;
        while ($index < $length) {
            $index += strspn($rest, " ,\n\r\t", $index);
            if ($index >= $length || $rest[$index] !== '(') {
                break;
            }
            $index++;
            $values = [];
            while ($index < $length) {
                $end = (new \ZtdQuery\Platform\Sqlite\Sql\Lexing\ExpressionSpan())->end($rest, $index);
                $value = trim(substr($rest, $index, $end - $index));
                $delimiter = $rest[$end] ?? null;
                $index = $end;
                if ($delimiter === ',') {
                    $values[] = $value;
                    $index++;
                    continue;
                }
                if ($delimiter === ')') {
                    if ($value !== '') {
                        $values[] = $value;
                    }
                    $index++;
                }
                break;
            }
            if ($values !== []) {
                $sets[] = $values;
            }
        }

        return $sets;
    }
}

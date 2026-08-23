<?php

declare(strict_types=1);

namespace ZtdQuery\Platform\Sqlite;

use ZtdQuery\Sql\SqlLexerProfile;

final class SqliteLexerProfile
{
    public static function create(): SqlLexerProfile
    {
        return new SqlLexerProfile(
            lineCommentPrefixes: ['--'],
            whitespaceDelimitedLineCommentPrefixes: [],
            blockCommentPairs: ['/*' => '*/'],
            stringQuotePairs: ["'" => "'"],
            identifierQuotePairs: ['"' => '"', '`' => '`', '[' => ']'],
            namedParameterSeparators: [':' => [], '@' => [], '$' => ['::']],
            namedParameterSuffixPatterns: ['$' => '/^\([^ \t\n\r\0\x0B)]*\)/'],
            namedParameterForbiddenPredecessors: [':' => [':'], '@' => [], '$' => []],
            backslashEscapedStringPrefixes: [],
            positionalParameterPatterns: ['/^\?[0-9]*/'],
            dollarQuoteDelimiterPattern: null,
            numericLiteralPattern: '/^(?:0[xX]_?[0-9A-Fa-f](?:_?[0-9A-Fa-f])*|(?:(?:[0-9](?:_?[0-9])*)(?:\.(?:[0-9](?:_?[0-9])*)?)?|\.(?:[0-9](?:_?[0-9])*))(?:[eE][+-]?[0-9](?:_?[0-9])*)?)/',
            identifierStartPattern: '/^[_A-Za-z$\x80-\xFF]$/',
            identifierPartPattern: '/^[_A-Za-z0-9$\x80-\xFF]$/',
            bracketPair: ['[', ']'],
            nestingPair: ['(', ')'],
            statementDelimiter: ';',
            listDelimiter: ',',
            nestedBlockComments: false,
            backslashEscapedStrings: false,
        );
    }
}

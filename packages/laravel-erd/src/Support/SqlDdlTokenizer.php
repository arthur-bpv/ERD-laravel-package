<?php

namespace Makermind\Erd4dev\Support;

use InvalidArgumentException;

/** Splits DDL without treating punctuation in strings, identifiers, or comments as syntax. */
final class SqlDdlTokenizer
{
    public static function tokens(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char) || ($i === 0 && substr($sql, 0, 3) === "\xEF\xBB\xBF")) {
                $i += $char === "\xEF" ? 3 : 1;

                continue;
            }
            if (substr($sql, $i, 2) === '--' || $char === '#') {
                $i = strpos($sql, "\n", $i) ?: $length;

                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new InvalidArgumentException('SQL comment was not closed.');
                }
                $i = $end + 2;

                continue;
            }
            if ($char === '$' && preg_match('/\A\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', substr($sql, $i), $match)) {
                $end = strpos($sql, $match[0], $i + strlen($match[0]));
                if ($end === false) {
                    throw new InvalidArgumentException('Dollar-quoted SQL text was not closed.');
                }
                $tokens[] = substr($sql, $i, $end + strlen($match[0]) - $i);
                $i = $end + strlen($match[0]);

                continue;
            }
            if (in_array($char, ["'", '"', '`', '['], true)) {
                $start = $i++;
                $close = $char === '[' ? ']' : $char;
                $closed = false;
                while ($i < $length) {
                    if ($sql[$i] === '\\' && $char === "'") {
                        $i += 2;
                    } elseif ($sql[$i] === $close) {
                        if (($sql[$i + 1] ?? '') === $close) {
                            $i += 2;
                        } else {
                            $i++;
                            $closed = true;
                            break;
                        }
                    } else {
                        $i++;
                    }
                }
                if (! $closed) {
                    throw new InvalidArgumentException('SQL string or identifier was not closed.');
                }
                $tokens[] = substr($sql, $start, $i - $start);

                continue;
            }
            if (str_contains('(),;.', $char)) {
                $tokens[] = $char;
                $i++;

                continue;
            }
            $start = $i++;
            while ($i < $length && ! ctype_space($sql[$i]) && ! str_contains("(),;.'\"`[]", $sql[$i])
                && substr($sql, $i, 2) !== '--' && substr($sql, $i, 2) !== '/*') {
                $i++;
            }
            $token = substr($sql, $start, $i - $start);
            if (strtoupper($token) === 'GO' || $token === '/') {
                $lineStart = strrpos(substr($sql, 0, $start), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $lineEnd = strpos($sql, "\n", $i);
                if (trim(substr($sql, $lineStart, $start - $lineStart)) === ''
                    && trim(substr($sql, $i, ($lineEnd === false ? $length : $lineEnd) - $i)) === '') {
                    $token = ';';
                }
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /** Split at a delimiter outside parentheses. */
    public static function split(array $tokens, string $delimiter): array
    {
        $parts = [];
        $part = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            }
            if ($depth < 0) {
                throw new InvalidArgumentException('Invalid SQL parentheses.');
            }
            if ($token === $delimiter && $depth === 0) {
                if ($part !== []) {
                    $parts[] = $part;
                }
                $part = [];
            } else {
                $part[] = $token;
            }
        }
        if ($depth !== 0) {
            throw new InvalidArgumentException('SQL parentheses were not closed.');
        }
        if ($part !== []) {
            $parts[] = $part;
        }

        return $parts;
    }

    public static function identifier(array $tokens, int &$position): string
    {
        $token = $tokens[$position++] ?? '';
        $quote = $token[0] ?? '';
        if (in_array($quote, ['"', '`', '['], true)) {
            $close = $quote === '[' ? ']' : $quote;

            return str_replace($close.$close, $close, substr($token, 1, -1));
        }
        if (! preg_match('/^[\pL_][\pL\pN_$#]*$/u', $token)) {
            throw new InvalidArgumentException('Invalid SQL identifier: '.$token);
        }

        return $token;
    }

    public static function qualifiedName(array $tokens, int &$position): string
    {
        $name = self::identifier($tokens, $position);
        while (($tokens[$position] ?? '') === '.') {
            $position++;
            $name .= '.'.self::identifier($tokens, $position);
        }

        return $name;
    }

    public static function group(array $tokens, int &$position): array
    {
        if (($tokens[$position++] ?? '') !== '(') {
            throw new InvalidArgumentException('Expected a parenthesized SQL group.');
        }
        $start = $position;
        $depth = 1;
        while (isset($tokens[$position])) {
            $token = $tokens[$position++];
            $depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
            if ($depth === 0) {
                return array_slice($tokens, $start, $position - $start - 1);
            }
        }

        throw new InvalidArgumentException('SQL parentheses were not closed.');
    }
}

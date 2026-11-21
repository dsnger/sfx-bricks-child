<?php

declare(strict_types=1);

namespace SFX\Markdown;

/**
 * Serializes decoded text so it cannot become Markdown structure or HTML.
 */
class Escape
{
    /** Inline text: escapes every char that is structure in any position. */
    public static function text(string $s): string
    {
        // A trailing ! is escaped too: the converter may write a link right after this run.
        return (string) preg_replace('/[\\\\`*_~\[\]<>|&]|!(?=\[|\z)/', '\\\\$0', $s);
    }

    /** Adds line-start escapes to every line of an already text()-escaped string. */
    public static function block_start(string $line): string
    {
        // CommonMark also ends a line at a lone CR, which PCRE's ^ does not see.
        $line = str_replace(["\r\n", "\r"], "\n", $line);
        $line = (string) preg_replace('/^([ \t]*)([#+\-=>])/m', '$1\\\\$2', $line);
        return (string) preg_replace('/^([ \t]*\d+)([.)])/m', '$1\\\\$2', $line);
    }

    /** Link and image labels, table cells, titles: one line. */
    public static function label(string $s): string
    {
        return self::text(self::one_line($s));
    }

    /** URL destination in angle brackets. */
    public static function destination(string $url): string
    {
        $url = (string) preg_replace_callback(
            '/[\x00-\x20\x7F-\xFF]/',
            static fn(array $m): string => sprintf('%%%02X', ord($m[0])),
            $url
        );
        return '<' . preg_replace('/[\\\\<>&]/', '\\\\$0', $url) . '>';
    }

    /** Inline code, delimited by one more backtick than the longest run inside. */
    public static function code_span(string $s): string
    {
        // A newline inside the span could start a block (e.g. "# ") before inlines are parsed; it renders as a space anyway.
        $s = self::one_line($s);
        $delim = str_repeat('`', self::longest_backtick_run($s) + 1);
        $pad = trim($s, ' ') !== '' && ($s[0] === '`' || $s[0] === ' ' || $s[-1] === '`' || $s[-1] === ' ');
        return $pad ? "{$delim} {$s} {$delim}" : "{$delim}{$s}{$delim}";
    }

    /** Whole fenced code block; the fence is longer than any backtick run inside. */
    public static function fence(string $code): string
    {
        $fence = str_repeat('`', max(3, self::longest_backtick_run($code) + 1));
        return "{$fence}\n{$code}\n{$fence}";
    }

    private static function one_line(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $s);
    }

    private static function longest_backtick_run(string $s): int
    {
        preg_match_all('/`+/', $s, $m);
        return $m[0] === [] ? 0 : max(array_map('strlen', $m[0]));
    }
}

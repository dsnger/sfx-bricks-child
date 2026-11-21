<?php

declare(strict_types=1);

namespace SFX\Markdown;

/**
 * Converts the Bricks content area (id="brx-content") of a finished HTML page to Markdown.
 *
 * One pass over WordPress' HTML parser, no recursion: a stack of open frames keyed by the
 * parser's depth, so closing an element ends exactly what it opened. Any doubt about the
 * markup returns null, and the caller sends the HTML unchanged.
 */
class Converter
{
    private const ROOT_ID = 'brx-content';

    /** Dropped with their whole subtree. */
    private const DROPPED = [
        'HEAD' => 1, 'SCRIPT' => 1, 'STYLE' => 1, 'NOSCRIPT' => 1, 'TEMPLATE' => 1, 'SVG' => 1,
        'MATH' => 1, 'IFRAME' => 1, 'FORM' => 1, 'SELECT' => 1, 'TEXTAREA' => 1, 'BUTTON' => 1,
        'NAV' => 1, 'VIDEO' => 1, 'AUDIO' => 1, 'CANVAS' => 1, 'OBJECT' => 1, 'EMBED' => 1, 'DIALOG' => 1,
        // Atomic like XMP (text on the tag token), but display:none in every browser.
        'TITLE' => 1, 'NOEMBED' => 1, 'NOFRAMES' => 1,
    ];

    /** Unwrapped elements a browser renders as blocks: they end a paragraph (a space in a label). */
    private const BLOCK = [
        'ADDRESS' => 1, 'ARTICLE' => 1, 'ASIDE' => 1, 'BLOCKQUOTE' => 1, 'BODY' => 1, 'CAPTION' => 1,
        'CENTER' => 1, 'DD' => 1, 'DETAILS' => 1, 'DIR' => 1, 'DIV' => 1, 'DL' => 1, 'DT' => 1,
        'FIELDSET' => 1, 'FIGCAPTION' => 1, 'FIGURE' => 1, 'FOOTER' => 1, 'H1' => 1, 'H2' => 1,
        'H3' => 1, 'H4' => 1, 'H5' => 1, 'H6' => 1, 'HEADER' => 1, 'HGROUP' => 1, 'HR' => 1,
        'LEGEND' => 1, 'LI' => 1, 'LISTING' => 1, 'MAIN' => 1, 'MENU' => 1, 'OL' => 1, 'P' => 1, 'PRE' => 1,
        'SEARCH' => 1, 'SECTION' => 1, 'SUMMARY' => 1, 'TABLE' => 1, 'TD' => 1, 'TH' => 1, 'TR' => 1, 'UL' => 1,
    ];

    /** Frames that hold blocks; every other frame holds one line of inline Markdown. */
    private const CONTAINERS = ['root' => 1, 'quote' => 1, 'li' => 1];

    /** Marks a `|` inside a code span in a table cell; the parser never yields NUL in text. */
    private const CELL_PIPE = "\x00";

    private string $base;

    /** @var list<\stdClass> */
    private array $stack = [];

    private bool $found = false;

    /** Depth of the dropped element being skipped, or null. */
    private ?int $skip = null;

    private string $out = '';

    /** Set when output would be ambiguous Markdown; the page is then sent as HTML. */
    private bool $bad = false;

    private function __construct(string $base)
    {
        $this->base = $base;
    }

    /** Markdown ending in one "\n", or null to send the HTML unchanged. */
    public static function convert(string $html, string $base_url): ?string
    {
        try {
            $p = \WP_HTML_Processor::create_full_parser($html);
            if ($p === null) {
                return null;
            }
            $c = new self($base_url);
            while ($p->next_token()) {
                if (!$c->token($p) || $c->bad) {
                    return null;
                }
            }
            if ($p->get_last_error() !== null || $p->paused_at_incomplete_token() || !$c->found) {
                return null;
            }
        } catch (\WP_HTML_Unsupported_Exception $e) {
            return null;
        }
        while ($c->stack !== []) {
            $c->finish();
        }
        return $c->out === '' || $c->bad ? null : $c->out . "\n";
    }

    /** Handles one token; false = doubt about the markup. */
    private function token(\WP_HTML_Processor $p): bool
    {
        $type = $p->get_token_type();
        $depth = $p->get_current_depth();
        $is_tag = $type === '#tag';
        $closer = $is_tag && $p->is_tag_closer();

        if ($this->skip !== null) {
            if ($closer && $depth < $this->skip) {
                $this->skip = null;
            } elseif ($is_tag && !$closer && $p->get_attribute('id') === self::ROOT_ID) {
                return false; // a root inside a dropped element
            }
            return true;
        }

        if ($type === '#text') {
            return $this->stack === [] || $this->text($p->get_modifiable_text());
        }
        if (!$is_tag) {
            return true; // comments, doctype
        }
        if ($closer) {
            $this->close((string) $p->get_tag(), $depth + 1);
            return true;
        }

        $tag = (string) $p->get_tag();
        $void = !$p->expects_closer();
        $is_root = $p->get_attribute('id') === self::ROOT_ID;
        if (self::dropped($p, $tag)) {
            if ($is_root) {
                return false;
            }
            if (!$void) {
                $this->skip = $depth;
            }
            return true;
        }
        if ($is_root) {
            if ($this->found) {
                return false;
            }
            $this->found = true;
            if (!$void) {
                $this->push('root', $depth);
            }
            return true;
        }
        if ($this->stack === []) {
            return true; // outside the root
        }
        while ($this->stack !== [] && $this->top()->depth >= $depth) {
            $this->finish();
        }
        return $this->stack === [] || $this->open($p, $tag, $depth);
    }

    private static function dropped(\WP_HTML_Processor $p, string $tag): bool
    {
        if (isset(self::DROPPED[$tag]) || $p->get_attribute('hidden') !== null) {
            return true;
        }
        $id = $p->get_attribute('id');
        if ($id === 'brx-header' || $id === 'brx-footer') {
            return true;
        }
        $aria = $p->get_attribute('aria-hidden');
        if (is_string($aria) && strtolower(trim($aria)) === 'true') {
            return true;
        }
        $role = $p->get_attribute('role');
        if (is_string($role) && in_array('navigation', preg_split('/\s+/', strtolower($role)) ?: [], true)) {
            return true;
        }
        return $p->has_class('bricks-pagination') === true;
    }

    private function open(\WP_HTML_Processor $p, string $tag, int $depth): bool
    {
        $top = $this->top();

        if ($top->kind === 'code' || $top->kind === 'pre') {
            if ($tag === 'BR') {
                $top->buf .= "\n";
            } elseif (isset(self::BLOCK[$tag]) || $tag === 'XMP') {
                $this->boundary();
                if ($tag === 'XMP') {
                    $top->buf .= $p->get_modifiable_text();
                }
            }
            return true;
        }
        if ($top->kind === 'list') {
            if ($tag !== 'LI') {
                return false;
            }
            $this->push('li', $depth);
            return true;
        }
        if ($top->kind === 'table') {
            if ($tag === 'TR') {
                $this->push('row', $depth)->cells = [];
            } elseif ($tag === 'CAPTION') {
                $this->push('caption', $depth);
            } elseif (!in_array($tag, ['THEAD', 'TBODY', 'TFOOT', 'COLGROUP', 'COL'], true)) {
                return false;
            }
            return true;
        }
        if ($top->kind === 'row') {
            if ($tag !== 'TD' && $tag !== 'TH') {
                return false;
            }
            $this->push('cell', $depth);
            return true;
        }

        if ($tag === 'XMP') {
            // Atomic: its text comes on this token and no closer follows. Rendered like pre.
            if (!$this->open($p, 'PRE', $depth)) {
                return false;
            }
            $this->text($p->get_modifiable_text());
            if ($this->top()->depth === $depth) {
                $this->finish();
            }
            return true;
        }
        $container = isset(self::CONTAINERS[$top->kind]);
        switch ($tag) {
            case 'BR':
                if ($container || $this->breaks()) {
                    self::newline($top);
                } else {
                    self::space($top);
                }
                return true;
            case 'IMG':
                $this->image($p, $top);
                return true;
            case 'A':
                $href = $p->get_attribute('href');
                if ($href !== null) {
                    $this->push('link', $depth)->url = Url::resolve(is_string($href) ? $href : '', $this->base, 'link');
                }
                return true;
            case 'STRONG':
            case 'B':
                $this->push('emph', $depth)->mark = '**';
                return true;
            case 'EM':
            case 'I':
                $this->push('emph', $depth)->mark = '*';
                return true;
            case 'CODE':
                $this->push('code', $depth);
                return true;
        }
        if (!$container) {
            if ($tag === 'PRE' || $tag === 'LISTING') {
                $this->push('code', $depth);
            } elseif (isset(self::BLOCK[$tag])) {
                $this->boundary();
            }
            return true;
        }

        if (!isset(self::BLOCK[$tag])) {
            return true; // inline element: unwrapped
        }
        $this->boundary();
        if ($tag === 'HR') {
            $top->blocks[] = ['hr', '---'];
        } elseif (preg_match('/^H([1-6])$/', $tag, $m)) {
            $this->push('heading', $depth)->level = (int) $m[1];
        } elseif ($tag === 'UL' || $tag === 'OL') {
            $list = $this->push('list', $depth);
            $start = $p->get_attribute('start');
            $list->ordered = $tag === 'OL';
            // CommonMark allows at most nine digits.
            $list->n = $tag === 'OL' && is_string($start) && preg_match('/^\d{1,9}$/', trim($start)) ? (int) trim($start) : 1;
            $list->start = $list->n;
            $list->items = [];
        } elseif ($tag === 'BLOCKQUOTE') {
            $this->push('quote', $depth);
        } elseif ($tag === 'PRE' || $tag === 'LISTING') {
            $this->push('pre', $depth);
        } elseif ($tag === 'TABLE') {
            $table = $this->push('table', $depth);
            $table->rows = [];
            $table->caption = '';
        }
        return true;
    }

    private function close(string $tag, int $element_depth): void
    {
        while ($this->stack !== [] && $this->top()->depth > $element_depth) {
            $this->finish();
        }
        if ($this->stack === []) {
            return;
        }
        if ($this->top()->depth === $element_depth) {
            $this->finish();
        } elseif (isset(self::BLOCK[$tag])) {
            $this->boundary();
        }
    }

    private function text(string $s): bool
    {
        $top = $this->top();
        if ($top->kind === 'list' || $top->kind === 'table' || $top->kind === 'row') {
            return self::collapse($s) === '' || self::collapse($s) === ' ';
        }
        if ($top->kind === 'code' || $top->kind === 'pre') {
            $top->buf .= $s;
            return true;
        }
        $s = self::collapse($s);
        // An inline frame keeps one leading space, which merge() moves outside it.
        $start = $top->buf === '' && isset(self::CONTAINERS[$top->kind]);
        if ($start || str_ends_with($top->buf, ' ') || str_ends_with($top->buf, "\n")) {
            $s = ltrim($s, ' ');
        }
        if ($s !== '') {
            $s = Escape::text($s);
            $this->closes($top, $s);
            $top->buf .= $s;
            $top->text = $top->text || trim($s) !== '';
        }
        return true;
    }

    private function image(\WP_HTML_Processor $p, \stdClass $top): void
    {
        $src = $p->get_attribute('src');
        $src = is_string($src) ? trim($src) : '';
        $lazy = $p->get_attribute('data-src');
        // Bricks lazy loading: a data: placeholder in src, the real address in data-src.
        if (($src === '' || stripos($src, 'data:') === 0) && is_string($lazy) && trim($lazy) !== '') {
            $src = $lazy;
        }
        $alt = $p->get_attribute('alt');
        $alt = is_string($alt) ? $alt : '';
        $url = $src === '' ? null : Url::resolve($src, $this->base, 'image');
        if ($url === null) {
            $this->text($alt);
            return;
        }
        $top->buf .= '![' . Escape::label(trim(self::collapse($alt))) . '](' . Escape::destination($url) . ')';
        $top->img = true;
    }

    /** A block boundary: ends the paragraph in a container, a space in a line. */
    private function boundary(): void
    {
        $top = $this->top();
        if (isset(self::CONTAINERS[$top->kind])) {
            self::flush($top);
            return;
        }
        if ($top->kind === 'pre') {
            if ($top->buf !== '' && !str_ends_with($top->buf, "\n")) {
                $top->buf .= "\n";
            }
            return;
        }
        if ($top->kind === 'code') {
            $top->buf .= ' ';
        } else {
            self::space($top);
        }
        // A link around blocks is written as its own paragraph (P6).
        for ($i = count($this->stack) - 1; $i >= 0 && !isset(self::CONTAINERS[$this->stack[$i]->kind]); $i--) {
            $this->stack[$i]->had_block = true;
        }
    }

    private function push(string $kind, int $depth): \stdClass
    {
        $frame = (object) ['kind' => $kind, 'depth' => $depth, 'buf' => '', 'blocks' => [], 'text' => false, 'img' => false, 'had_block' => false, 'tail' => null];
        $this->stack[] = $frame;
        return $frame;
    }

    private function top(): \stdClass
    {
        return $this->stack[count($this->stack) - 1];
    }

    /** Pops the top frame and writes it into its parent. */
    private function finish(): void
    {
        $f = array_pop($this->stack);
        if ($f->kind === 'root') {
            self::flush($f);
            $this->out = self::join($f->blocks, false);
            return;
        }
        $parent = $this->top();

        switch ($f->kind) {
            case 'quote':
                self::flush($f);
                $body = self::join($f->blocks, false);
                if ($body !== '') {
                    $lines = array_map(static fn(string $l): string => $l === '' ? '>' : '> ' . $l, explode("\n", $body));
                    $parent->blocks[] = ['quote', implode("\n", $lines)];
                }
                return;
            case 'li':
                self::flush($f);
                $body = self::join($f->blocks, true);
                if ($body === '') {
                    return;
                }
                $marker = $parent->ordered ? $parent->n++ . '. ' : '- ';
                $indent = str_repeat(' ', strlen($marker));
                $lines = explode("\n", $body);
                foreach ($lines as $i => $line) {
                    $lines[$i] = $i === 0 ? $marker . $line : ($line === '' ? '' : $indent . $line);
                }
                $parent->items[] = implode("\n", $lines);
                return;
            case 'list':
                if ($f->items !== []) {
                    // A list right after one of its kind would continue it: the other delimiter starts a new one.
                    $prev = end($parent->blocks);
                    $alt = is_array($prev) && $prev[0] === 'list' && $prev[3] === $f->ordered && !$prev[4];
                    $items = $f->items;
                    if ($alt) {
                        $items = array_map(static fn(string $i): string => $f->ordered ? (string) preg_replace('/^(\d+)\./', '$1)', $i) : '*' . substr($i, 1), $items);
                    }
                    // Only a bullet list or one starting at 1 can directly follow a paragraph line.
                    $parent->blocks[] = ['list', implode("\n", $items), !$f->ordered || $f->start === 1, $f->ordered, $alt];
                }
                return;
            case 'heading':
                $body = trim($f->buf, ' ');
                if ($body !== '') {
                    if (str_ends_with($body, '#')) {
                        $body = substr($body, 0, -1) . '\#'; // else it reads as a closing sequence
                    }
                    $parent->blocks[] = ['h', str_repeat('#', $f->level) . ' ' . $body];
                }
                return;
            case 'pre':
                $code = rtrim(str_replace(["\r\n", "\r"], "\n", $f->buf), "\n");
                if (trim($code) !== '') {
                    $parent->blocks[] = ['pre', Escape::fence($code)];
                }
                return;
            case 'code':
                $code = self::collapse($f->buf);
                if ($code !== '') {
                    if ($this->in_cell()) {
                        $code = str_replace('|', self::CELL_PIPE, $code);
                    }
                    $f->text = true;
                    $this->merge($parent, $f, Escape::code_span($code), $code);
                }
                return;
            case 'emph':
                $body = trim($f->buf, " \n");
                $this->merge($parent, $f, $body === '' ? '' : $f->mark . $body . $f->mark, $body);
                return;
            case 'link':
                $label = trim($f->buf, ' ');
                if ($f->url === null || !$f->text) {
                    $md = $label; // unsafe URL keeps its text; an image-only link becomes the image
                } else {
                    $md = '[' . $label . '](' . Escape::destination($f->url) . ')';
                }
                if ($f->had_block && $md !== '' && isset(self::CONTAINERS[$parent->kind])) {
                    self::flush($parent);
                    $parent->buf = $md;
                    self::flush($parent);
                    return;
                }
                $this->merge($parent, $f, $md);
                return;
            case 'cell':
                $parent->cells[] = trim($f->buf, ' ');
                return;
            case 'caption':
                $parent->caption = trim($f->buf, ' ');
                return;
            case 'row':
                if ($f->cells !== []) {
                    $parent->rows[] = $f->cells;
                }
                return;
            case 'table':
                if ($f->caption !== '') {
                    $parent->blocks[] = ['p', Escape::block_start($f->caption)];
                }
                self::table($parent, $f->rows);
                return;
        }
    }

    /** @param list<list<string>> $rows */
    private static function table(\stdClass $parent, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $widths = array_unique(array_map('count', $rows));
        if (count($widths) === 1) {
            $lines = [];
            foreach ($rows as $i => $cells) {
                $lines[] = '| ' . str_replace(self::CELL_PIPE, '\|', implode(' | ', $cells)) . ' |';
                if ($i === 0) {
                    $lines[] = '| ' . implode(' | ', array_fill(0, count($cells), '---')) . ' |';
                }
            }
            $parent->blocks[] = ['table', implode("\n", $lines)];
            return;
        }
        // Ragged: one paragraph per row, so no two lines can form a GFM table.
        foreach ($rows as $cells) {
            $line = trim(str_replace(self::CELL_PIPE, '|', implode(' | ', $cells)), ' ');
            if ($line !== '') {
                $parent->blocks[] = ['p', Escape::block_start($line)];
            }
        }
    }

    private function in_cell(): bool
    {
        for ($i = count($this->stack) - 1; $i >= 0 && !isset(self::CONTAINERS[$this->stack[$i]->kind]); $i--) {
            if ($this->stack[$i]->kind === 'cell') {
                return true;
            }
        }
        return false;
    }

    /**
     * Writes an inline child into its parent; spaces (and an emphasis' line breaks) at its
     * edges move outside it. $raw is a code span's text or an emphasis' body.
     */
    private function merge(\stdClass $parent, \stdClass $child, string $md, string $raw = ''): void
    {
        $edge = static function (string $c) use ($parent, $child): void {
            if ($c === ' ') {
                self::space($parent);
            } elseif ($c === "\n" && $child->kind === 'emph') {
                self::newline($parent);
            }
        };
        $edge(substr($child->buf, 0, 1));
        if ($md !== '') {
            $tail = $parent->tail !== null && $parent->tail['end'] === strlen($parent->buf) ? $parent->tail : null;
            $start = strlen($parent->buf);
            $kind = $child->kind;
            if ($kind === 'code' && $tail !== null && $tail['kind'] === 'code') {
                // Neighbouring code spans become one: their backtick runs would join (an escaped tag could turn into HTML).
                $raw = $tail['raw'] . $raw;
                $start = $tail['start'];
                $parent->buf = substr($parent->buf, 0, $start);
                $md = Escape::code_span($raw);
            } elseif ($kind === 'emph') {
                [$md, $raw, $start, $kind] = $this->emphasis($parent, $tail, $child->mark, $raw);
            } else {
                $this->closes($parent, $md);
            }
            if (!$this->bad) {
                $parent->buf .= $md;
                $parent->tail = $kind === 'code' || $kind === 'emph'
                    ? ['kind' => $kind, 'start' => $start, 'end' => strlen($parent->buf), 'raw' => $raw, 'mark' => $child->mark ?? '']
                    : null;
            }
        }
        $edge(substr($child->buf, -1));
        $parent->text = $parent->text || ($child->text && $md !== '');
        $parent->img = $parent->img || $child->img;
        $parent->had_block = $parent->had_block || $child->had_block;
    }

    /**
     * Chooses how an emphasis is written so CommonMark's flanking rules read it as written
     * (`a**(b)**` or two runs meeting as `****` would print the stars). An emphasis that cannot
     * be delimited safely loses its markers and keeps its text.
     *
     * @param array<string,mixed>|null $tail the emphasis or code span the parent buffer ends with
     * @return array{0: string, 1: string, 2: int, 3: string} markdown, body, start offset (emph only), tail kind
     */
    private function emphasis(\stdClass $parent, ?array $tail, string $mark, string $body): array
    {
        $plain = [$body, $body, 0, 'plain'];
        if ($tail !== null && $tail['kind'] === 'emph') {
            if (self::delimited($tail['raw']) || self::delimited($body)) {
                $this->bad = true; // nested runs would meet; too many cases to prove safe
                return $plain;
            }
            if ($tail['mark'] === $mark) {
                // **a** + **b** = **ab**
                $parent->buf = substr($parent->buf, 0, $tail['end'] - strlen($mark));
                return [$body . $mark, $tail['raw'] . $body, $tail['start'], 'emph'];
            }
            // *a* + **b** = *a***b**: one run of three that must close the first and open the second,
            // which holds when the characters on both sides are of the same class.
            $last = self::cls(self::last($tail['raw']));
            $first = self::cls(self::first($body));
            if ($last === $first) {
                return [$mark . $body . $mark, $body, strlen($parent->buf), 'emph'];
            }
            if ($first === 1) {
                return $plain; // *a*(b): the first closes before punctuation
            }
            $this->strip($parent, $tail); // a.**b**: the second opens after punctuation
        }
        if (self::cls(self::first($body)) === 0 || self::cls(self::last($body)) === 0) {
            return $this->plain($plain);
        }
        $before = self::cls(self::last($parent->buf));
        if (self::cls(self::first($body)) === 1 && $before === 2) {
            return $this->plain($plain); // x**(b)** would not open
        }
        return [$mark . $body . $mark, $body, strlen($parent->buf), 'emph'];
    }

    /** @param array{0: string, 1: string, 2: int, 3: string} $plain */
    private function plain(array $plain): array
    {
        if (self::delimited($plain[1])) {
            $this->bad = true;
        }
        return $plain;
    }

    /** Before $next is written: an emphasis ending in punctuation cannot close before a letter (`*a.*b`). */
    private function closes(\stdClass $f, string $next): void
    {
        $tail = $f->tail;
        if ($tail !== null && $tail['kind'] === 'emph' && $tail['end'] === strlen($f->buf)
            && self::cls(self::last($tail['raw'])) === 1 && self::cls(self::first($next)) === 2) {
            $this->strip($f, $tail);
        }
    }

    /** Removes the markers of the emphasis the buffer ends with, keeping its text. */
    private function strip(\stdClass $f, array $tail): void
    {
        if (self::delimited($tail['raw'])) {
            $this->bad = true;
            return;
        }
        $f->buf = substr($f->buf, 0, $tail['start']) . $tail['raw'];
        $f->tail = null;
    }

    /** Whether an edge of $s is a delimiter of a nested span or emphasis (or an escaped one: kept conservative). */
    private static function delimited(string $s): bool
    {
        return strpbrk(substr($s, 0, 1) . substr($s, -1), '*`') !== false;
    }

    /** CommonMark character class: 0 whitespace or none, 1 punctuation, 2 other. */
    private static function cls(string $c): int
    {
        if ($c === '' || preg_match('/^[\s\p{Z}\x{FEFF}]/u', $c)) {
            return 0;
        }
        return preg_match('/^[!-\/:-@\[-`{-~\p{P}\p{S}]/u', $c) ? 1 : 2;
    }

    private static function first(string $s): string
    {
        return preg_match('/^./su', $s, $m) ? $m[0] : substr($s, 0, 1);
    }

    private static function last(string $s): string
    {
        return preg_match('/.$/su', $s, $m) ? $m[0] : substr($s, -1);
    }

    /** Whether a br in the top frame can be a hard break: only emphasis between it and its container. */
    private function breaks(): bool
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $kind = $this->stack[$i]->kind;
            if (isset(self::CONTAINERS[$kind])) {
                return true;
            }
            if ($kind !== 'emph') {
                return false;
            }
        }
        return false;
    }

    /** A hard line break; at the start of a container's paragraph it is dropped. */
    private static function newline(\stdClass $f): void
    {
        $f->buf = rtrim($f->buf, ' ');
        if (($f->buf !== '' || $f->kind === 'emph') && !str_ends_with($f->buf, "\n")) {
            $f->buf .= "\n";
        }
    }

    private static function space(\stdClass $f): void
    {
        if ($f->buf !== '' && !str_ends_with($f->buf, ' ') && !str_ends_with($f->buf, "\n")) {
            $f->buf .= ' ';
        }
    }

    /** Ends a container's paragraph; a "\n" in it is a hard line break. */
    private static function flush(\stdClass $f): void
    {
        $text = trim($f->buf, " \n");
        $f->buf = '';
        $f->tail = null;
        if ($text !== '') {
            $f->blocks[] = ['p', str_replace("\n", "\\\n", Escape::block_start($text))];
        }
    }

    /** @param list<array{0: string, 1: string, 2?: bool}> $blocks */
    private static function join(array $blocks, bool $in_item): string
    {
        $out = '';
        foreach ($blocks as $i => $block) {
            if ($i > 0) {
                $tight = $in_item && $block[0] === 'list' && !empty($block[2]) && $blocks[$i - 1][0] === 'p';
                $out .= $tight ? "\n" : "\n\n";
            }
            $out .= $block[1];
        }
        return $out;
    }

    /** Whitespace as a browser renders it outside pre. */
    private static function collapse(string $s): string
    {
        return (string) preg_replace('/[ \t\n\r\f]+/', ' ', $s);
    }
}

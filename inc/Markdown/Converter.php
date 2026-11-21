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

    private string $base;

    /** @var list<\stdClass> */
    private array $stack = [];

    private bool $found = false;

    /** Depth of the dropped element being skipped, or null. */
    private ?int $skip = null;

    private string $out = '';

    /** An ordered-list number past nine digits, which CommonMark cannot write: pass-through. */
    private bool $overflow = false;

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
                if (!$c->token($p)) {
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
        return $c->out === '' || $c->overflow ? null : $c->out . "\n";
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
        if (($is_root || $this->stack !== []) && self::numbering_unkept($p, $tag)) {
            $this->overflow = true; // numbering Markdown cannot show as the visitor sees it: serve the HTML
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

    /**
     * An ordered list whose numbering CommonMark cannot reproduce: a start that is not 1-9 digits
     * (no sign), a reversed order, or an item with its own value. Checked for every OL and LI in
     * the content area, whatever context would later flatten it.
     */
    private static function numbering_unkept(\WP_HTML_Processor $p, string $tag): bool
    {
        if ($tag === 'OL') {
            $start = $p->get_attribute('start');
            return ($start !== null && !(is_string($start) && preg_match('/^\d{1,9}$/', trim($start))))
                || $p->get_attribute('reversed') !== null;
        }
        if ($tag !== 'LI' || $p->get_attribute('value') === null) {
            return false;
        }
        $crumbs = $p->get_breadcrumbs() ?? [];
        array_pop($crumbs); // the LI itself
        foreach (array_reverse($crumbs) as $ancestor) {
            if ($ancestor === 'OL' || $ancestor === 'UL' || $ancestor === 'MENU') {
                return $ancestor === 'OL';
            }
        }
        return false;
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

        if ($top->kind === 'pre') {
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
            } else {
                $this->boundary(); // inside a line: plain text, a block boundary on both sides
            }
            return true;
        }
        $container = isset(self::CONTAINERS[$top->kind]);
        switch ($tag) {
            case 'BR':
                if ($container) {
                    $top->buf = rtrim($top->buf, ' ');
                    if ($top->buf !== '' && !str_ends_with($top->buf, "\n")) {
                        $top->buf .= "\n";
                    }
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
        }
        if (!$container) {
            // A line (heading, link label, cell) cannot hold a block: pre's text stays plain text there.
            if (isset(self::BLOCK[$tag])) {
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
            // Any other start was sent to the HTML by numbering_unkept().
            $list->n = $list->ordered && is_string($start) && preg_match('/^\d{1,9}$/', trim($start)) ? (int) trim($start) : 1;
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
        if ($top->kind === 'pre') {
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
            $top->buf .= Escape::text($s);
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
        self::space($top);
        // A link around blocks is written as its own paragraph (P6).
        for ($i = count($this->stack) - 1; $i >= 0 && !isset(self::CONTAINERS[$this->stack[$i]->kind]); $i--) {
            $this->stack[$i]->had_block = true;
        }
    }

    private function push(string $kind, int $depth): \stdClass
    {
        $frame = (object) ['kind' => $kind, 'depth' => $depth, 'buf' => '', 'blocks' => [], 'text' => false, 'img' => false, 'had_block' => false];
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
                if ($body === '' && !$parent->ordered) {
                    return;
                }
                if ($parent->ordered && $parent->n > 999999999) {
                    $this->overflow = true;
                }
                // An empty ordered item keeps its number: a bare "3." is a valid empty item.
                $marker = $parent->ordered ? $parent->n++ . ($body === '' ? '.' : '. ') : '- ';
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
                    // Only a bullet list or one starting at 1, with a first item that is not empty, can directly follow a paragraph line.
                    $parent->blocks[] = ['list', implode("\n", $items), !$f->ordered || ($f->start === 1 && !preg_match('/^\d+[.)]$/', $items[0])), $f->ordered, $alt];
                }
                return;
            case 'heading':
                $body = trim($f->buf, ' ');
                if ($body !== '') {
                    $parent->blocks[] = ['h', str_repeat('#', $f->level) . ' ' . Escape::heading_tail($body)];
                }
                return;
            case 'pre':
                $code = rtrim(str_replace(["\r\n", "\r"], "\n", $f->buf), "\n");
                if (trim($code) !== '') {
                    $parent->blocks[] = ['pre', Escape::fence($code)];
                }
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
                self::merge($parent, $f, $md);
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
                $lines[] = '| ' . implode(' | ', $cells) . ' |';
                if ($i === 0) {
                    $lines[] = '| ' . implode(' | ', array_fill(0, count($cells), '---')) . ' |';
                }
            }
            $parent->blocks[] = ['table', implode("\n", $lines)];
            return;
        }
        // Ragged: one paragraph per row, so no two lines can form a GFM table.
        foreach ($rows as $cells) {
            $line = trim(implode(' | ', $cells), ' ');
            if ($line !== '') {
                $parent->blocks[] = ['p', Escape::block_start($line)];
            }
        }
    }

    /** Writes an inline child into its parent; spaces at its edges move outside it. */
    private static function merge(\stdClass $parent, \stdClass $child, string $md): void
    {
        if (str_starts_with($child->buf, ' ')) {
            self::space($parent);
        }
        $parent->buf .= $md;
        if (str_ends_with($child->buf, ' ')) {
            self::space($parent);
        }
        $parent->text = $parent->text || ($child->text && $md !== '');
        $parent->img = $parent->img || $child->img;
        $parent->had_block = $parent->had_block || $child->had_block;
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

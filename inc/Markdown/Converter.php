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
        'MATH' => 1, 'IFRAME' => 1, 'FORM' => 1, 'SELECT' => 1, 'TEXTAREA' => 1,
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

    /** @var array<int, true> Depths of open elements whose edges are word boundaries (word_part()). */
    private array $bounded = [];

    /**
     * Part classes that hold one part of a value each; Bricks prints them side by side with no
     * whitespace (Bricks 2.4.6 includes/elements/*.php). Only inside their own element.
     */
    private const PARTS = [
        'brxe-pricing-tables' => ['pricing-table-original-price' => 1, 'pricing-table-price-prefix' => 1, 'pricing-table-price' => 1, 'pricing-table-price-suffix' => 1],
        'brxe-post-meta' => ['item' => 1, 'separator' => 1],
        'brxe-progress-bar' => ['label' => 1, 'percentage' => 1],
        'brxe-list' => ['title' => 1, 'meta' => 1],
    ];

    /** @var list<array{0: int, 1: array<string, int>}> Open PARTS elements: depth, part classes. */
    private array $scopes = [];

    /** @var array{depth: int, value: ?string}|null The open Bricks counter and its end value. */
    private ?array $counter = null;

    /** @var list<\stdClass> The open Bricks tabs elements being paired, innermost last. */
    private array $tabs = [];

    /** Tabs elements opened so far; numbers them in document order. */
    private int $tab_count = 0;

    /** @var array<int, true> Tabs that hold tabs and failed to pair; their panes keep the heading state. */
    private array $unpaired = [];

    /** Level of the last heading written, 0 before the first. */
    private int $heading = 0;

    /** An ordered-list number past nine digits, which CommonMark cannot write: pass-through. */
    private bool $overflow = false;

    private function __construct(string $base)
    {
        $this->base = $base;
    }

    /** Markdown ending in one "\n", or null to send the HTML unchanged. */
    public static function convert(string $html, string $base_url): ?string
    {
        return self::run($html, $base_url, []);
    }

    /** @param array<int, true> $unpaired Tabs known not to pair (see fail()). */
    private static function run(string $html, string $base_url, array $unpaired): ?string
    {
        try {
            $p = \WP_HTML_Processor::create_full_parser($html);
            if ($p === null) {
                return null;
            }
            $c = new self($base_url);
            $c->unpaired = $unpaired;
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
        if ($c->unpaired !== $unpaired) {
            // Pairing never depends on heading levels, so the second run fails the same tabs and stops.
            return self::run($html, $base_url, $c->unpaired);
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
            if ($this->tabs !== [] && end($this->tabs)->open === null && trim(self::collapse($p->get_modifiable_text())) !== '') {
                end($this->tabs)->ok = false; // text outside every title and pane
            }
            return $this->stack === [] || $this->text($p->get_modifiable_text());
        }
        if (!$is_tag) {
            return true; // comments, doctype
        }
        if ($closer) {
            $this->close((string) $p->get_tag(), $depth + 1);
            if (isset($this->bounded[$depth + 1])) {
                unset($this->bounded[$depth + 1]);
                $this->word_boundary();
            }
            while ($this->scopes !== [] && end($this->scopes)[0] > $depth) {
                array_pop($this->scopes);
            }
            if ($this->counter !== null && $this->counter['depth'] > $depth) {
                $this->counter = null;
            }
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
        if ($this->stack === []) {
            return true;
        }
        $word = $this->word_part($p, $depth, $void);
        if ($word) {
            // Bricks prints the parts of one value (and adjacent buttons) with no whitespace between them.
            $this->word_boundary();
            if (!$void) {
                $this->bounded[$depth] = true;
            }
        }
        if ($this->counter !== null && $depth === $this->counter['depth'] + 1 && $p->has_class('count') === true) {
            $value = $this->counter['value'];
            $this->counter = null;
            if ($value !== null && !$void) {
                // The end value JS counts up to replaces the start value in the HTML.
                $this->skip = $depth;
                return $this->text($value);
            }
        }
        if (!$this->open($p, $tag, $depth)) {
            return false;
        }
        if ($word && $void) {
            $this->word_boundary(); // a void element has no closer to end its word
        }
        if (!$void && $p->has_class('brxe-counter') === true) {
            $this->counter = ['depth' => $depth, 'value' => self::counter_end($p)];
        }
        $this->tabs_open($p, $depth);
        return true;
    }

    /**
     * Whether the element is a word of its own: a Bricks element (class brxe-* or bricks-*) or a
     * part class of the PARTS element around it. Opens that element's scope as a side effect.
     */
    private function word_part(\WP_HTML_Processor $p, int $depth, bool $void): bool
    {
        $parts = $this->scopes === [] ? [] : end($this->scopes)[1];
        $word = false;
        foreach ($p->class_list() ?? [] as $class) {
            if (str_starts_with($class, 'brxe-') || str_starts_with($class, 'bricks-') || isset($parts[$class])) {
                $word = true;
            }
            if (!$void && isset(self::PARTS[$class])) {
                $this->scopes[] = [$depth, self::PARTS[$class]];
            }
        }
        return $word;
    }

    /**
     * The value Bricks' counter shows once its count-up ends (frontend.js bricksCounterFn), from
     * data-bricks-counter-options; null keeps the server text.
     */
    private static function counter_end(\WP_HTML_Processor $p): ?string
    {
        $json = $p->get_attribute('data-bricks-counter-options');
        $options = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($options) || !array_key_exists('countFrom', $options) || !array_key_exists('countTo', $options)) {
            return null;
        }
        $from = self::whole($options['countFrom']);
        $to = self::whole($options['countTo']);
        if ($from === null || $to === null || $to <= $from) {
            return null; // JS stops at once and leaves the start value
        }
        $thousands = $options['thousands'] ?? '';
        if ($thousands === '' || $thousands === false) {
            return (string) $to;
        }
        $separator = $options['separator'] ?? '';
        if ($thousands !== true || !is_string($separator)) {
            return null;
        }
        if ($separator === '') {
            return (string) $to; // JS groups by the visitor's locale, unknown here: the digits alone
        }
        // esc_html()'d text, digits that JS would read back as the count, or a "$" that JS's
        // replaceAll() reads as a replacement pattern: in doubt.
        if (strpbrk($separator, '&<0123456789$') !== false) {
            return null;
        }
        return number_format($to, 0, '.', $separator);
    }

    /** A non-negative whole number as JSON or a digit string that JS reads exactly, else null. */
    private static function whole($v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 && $v <= 9007199254740991 ? $v : null; // Number.MAX_SAFE_INTEGER
        }
        return is_string($v) && $v !== '' && strlen($v) <= 15 && ctype_digit($v) ? (int) $v : null;
    }

    /**
     * Bricks tabs (brxe-tabs, brxe-tabs-nested): .tab-menu > .tab-title, then .tab-content > .tab-pane.
     * Records where each title and pane starts; tabs_close() pairs them. Tabs inside a pane get a
     * tracker of their own.
     */
    private function tabs_open(\WP_HTML_Processor $p, int $depth): void
    {
        $t = $this->tabs === [] ? null : end($this->tabs);
        $top = $this->top();
        if ($t !== null && $t->ok) {
            if ($depth === $t->depth + 1) {
                $t->child = $p->has_class('tab-menu') === true ? 'title' : ($p->has_class('tab-content') === true ? 'pane' : null);
                $t->ok = $t->child !== null && $p->expects_closer(); // a void or atomic wrapper holds no titles or panes
            } elseif ($depth === $t->depth + 2) {
                $id = $p->get_attribute($t->child === 'title' ? 'aria-controls' : 'id');
                if ($p->has_class('tab-' . $t->child) !== true || !is_string($id) || $id === '' || !isset(self::CONTAINERS[$top->kind]) || !$p->expects_closer()) {
                    $t->ok = false;
                    return;
                }
                self::flush($top);
                $t->open = ['kind' => $t->child, 'id' => $id, 'depth' => $depth, 'frame' => $top, 'start' => count($top->blocks)];
                if ($t->child === 'pane' && !isset($this->unpaired[$t->n])) {
                    $this->heading = $t->level; // once paired, the pane follows its title
                }
            }
        }
        if (($t === null || $t->open !== null) && ($p->has_class('brxe-tabs') === true || $p->has_class('brxe-tabs-nested') === true) && $p->expects_closer() && isset(self::CONTAINERS[$top->kind])) {
            self::flush($top); // text before the tabs stays outside their range
            if ($t !== null) {
                $t->nested = true; // these tabs take their level from $t's pane
            }
            $this->tabs[] = (object) [
                'n' => $this->tab_count++, 'nested' => false,
                'depth' => $depth, 'frame' => $top, 'start' => count($top->blocks),
                'level' => $this->heading === 0 ? 3 : min(6, $this->heading + 1),
                'seen' => $this->heading, // the last heading in document order, if pairing fails
                'child' => null, 'open' => null, 'titles' => [], 'panes' => [], 'ok' => true,
            ];
        }
    }

    /** At a closer, after the frames inside it finished: ends a title, a pane or the innermost tabs. */
    private function tabs_close(int $element_depth): void
    {
        if ($this->tabs === []) {
            return;
        }
        $t = end($this->tabs);
        if ($t->open !== null && $t->open['depth'] === $element_depth) {
            $o = $t->open;
            $t->open = null;
            if ($this->top() !== $o['frame']) {
                $t->ok = false;
                return;
            }
            self::flush($o['frame']);
            $blocks = array_slice($o['frame']->blocks, $o['start']);
            if ($o['kind'] === 'pane') {
                $t->panes[] = [$o['id'], $blocks, $this->heading];
                return;
            }
            $line = self::title_line($blocks);
            if ($line === null || isset($t->titles[$o['id']])) {
                $t->ok = false;
                return;
            }
            $t->titles[$o['id']] = $line;
            return;
        }
        if ($t->depth !== $element_depth) {
            return;
        }
        array_pop($this->tabs);
        $this->heading = $t->seen;
        if (!$t->ok || $this->top() !== $t->frame || $t->panes === [] || count($t->titles) !== count($t->panes)) {
            $this->fail($t);
            return; // in doubt: titles and panes stay in document order
        }
        $paired = [];
        $heading = $t->seen;
        foreach ($t->panes as [$id, $blocks, $heading]) {
            if (!isset($t->titles[$id])) {
                $this->fail($t);
                return;
            }
            $paired[] = ['h', str_repeat('#', $t->level) . ' ' . $t->titles[$id]];
            array_push($paired, ...$blocks);
            unset($t->titles[$id]); // a second pane with the same id finds none
        }
        self::flush($t->frame);
        array_splice($t->frame->blocks, $t->start, count($t->frame->blocks) - $t->start, $paired);
        $this->heading = $heading; // the last pane's last heading, or its title
        foreach ($this->tabs as $outer) {
            $outer->seen = $heading;
        }
    }

    /** Tabs that did not pair: tabs inside them were levelled as if they had, so convert() runs again. */
    private function fail(\stdClass $t): void
    {
        if ($t->nested) {
            $this->unpaired[$t->n] = true;
        }
    }

    /** A tab title as heading text: exactly one paragraph or heading of one line, else null. */
    private static function title_line(array $blocks): ?string
    {
        if (count($blocks) !== 1 || !in_array($blocks[0][0], ['p', 'h'], true)) {
            return null;
        }
        $line = $blocks[0][1];
        $line = $blocks[0][0] === 'h' ? ltrim(substr($line, strspn($line, '#')), ' ') : Escape::heading_tail($line);
        return $line === '' || str_contains($line, "\n") ? null : $line;
    }

    /**
     * One space in the current line, unless it is at a start or already ends in whitespace. At the
     * start of an inline frame (a link) it is the leading space merge() moves outside the frame.
     */
    private function word_boundary(): void
    {
        if ($this->stack === [] || $this->top()->kind === 'pre') {
            return;
        }
        $top = $this->top();
        if ($top->buf === '' && !isset(self::CONTAINERS[$top->kind])) {
            $top->buf = ' ';
            return;
        }
        self::space($top);
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
        $this->tabs_close($element_depth);
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
                    $this->heading = $f->level;
                    foreach ($this->tabs as $t) {
                        $t->seen = $f->level;
                    }
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

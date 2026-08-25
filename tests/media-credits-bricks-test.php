<?php

declare(strict_types=1);

require __DIR__ . '/support/media-credits-stubs.php';
require __DIR__ . '/support/media-credits-bricks-stubs.php';

require dirname(__DIR__) . '/inc/MediaCredits/Settings.php';
require dirname(__DIR__) . '/inc/MediaCredits/Credit.php';
require dirname(__DIR__) . '/inc/MediaCredits/Bricks.php';

use SFX\MediaCredits\Bricks;
use SFX\MediaCredits\Credit;
use SFX\MediaCredits\Settings;

$test_thumbnail_ids   = [];
$test_current_post_id = 0;
$test_posts           = [];

/** Give attachment $id a copyright and an AI slug. */
function seed_attachment(int $id, string $copyright = '', string $ai = ''): void
{
    $GLOBALS['test_attachment_url'][$id] = "https://example.test/{$id}.jpg";
    $GLOBALS['test_post_meta'][$id][Credit::META_COPYRIGHT] = $copyright;
    $GLOBALS['test_post_meta'][$id][Credit::META_AI] = $ai;
    Credit::reset_cache();
}

// --------------------------------------------- Case 1: hook registration
// Priority 20 on render_tag is load-bearing, exactly as documented in
// NavMenuQuery\MenuItemTags: Bricks registers its own handler at 10 before
// our theme can hook at all, and its handler re-wraps unknown tags in braces.

Bricks::register();

$render_tag = test_registrations('bricks/dynamic_data/render_tag');
assert_same(1, count($render_tag), 'Case 1a: render_tag registered once');
assert_same(20, $render_tag[0]['priority'], 'Case 1b: at priority 20, behind Bricks own handler');
assert_same(3, $render_tag[0]['accepted_args'], 'Case 1c: with all three arguments');

$settings_hook = test_registrations('bricks/element/settings');
assert_same(2, $settings_hook[0]['accepted_args'], 'Case 1d: element settings filter takes the instance too');

$render_element = test_registrations('bricks/frontend/render_element');
assert_same(2, $render_element[0]['accepted_args'], 'Case 1e: render_element filter takes the instance too');

// --------------------------------------------- Case 2: tag recognition

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller', 'ai_generated');

$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

assert_same('Foto Müller', Bricks::render_tag('sfx_media_copyright', $post, null), 'Case 2a: the bare tag resolves');
assert_same('Foto Müller', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 2b: the brace-wrapped form resolves too — Bricks re-wraps unknown tags');
assert_same('KI-generiert', Bricks::render_tag('{sfx_media_ai_label}', $post, null), 'Case 2c: the label tag');
assert_contains('©&nbsp;Foto Müller', Bricks::render_tag('{sfx_media_credit}', $post, null), 'Case 2d: the composed line');

// A tag that is not ours must come back BYTE-IDENTICAL. Returning '' or a
// normalised copy destroys the value for every provider after us.
assert_same('{post_title}', Bricks::render_tag('{post_title}', $post, null), 'Case 2e: a foreign tag is passed through untouched');
assert_same('{sfx_media_nonsense}', Bricks::render_tag('{sfx_media_nonsense}', $post, null), 'Case 2f: an unknown suffix in our namespace is left alone');
assert_same(['x'], Bricks::render_tag(['x'], $post, null), 'Case 2g: a non-string tag is passed through');

// ------------------------------------------ Case 3: context resolution
// Explicit id beats loop object beats $post beats featured image.

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'From post');
seed_attachment(6, 'From loop');
seed_attachment(7, 'From explicit id');
seed_attachment(8, 'From featured image');

$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

assert_same('From post', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 3a: a $post that is an attachment');

\Bricks\Query::$looping     = true;
\Bricks\Query::$loop_object = new WP_Post(['ID' => 6, 'post_type' => 'attachment']);
assert_same('From loop', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 3b: the loop object beats $post');

assert_same('From explicit id', Bricks::render_tag('{sfx_media_copyright:7}', $post, null), 'Case 3c: an explicit id beats everything');

\Bricks\Query::reset();
$GLOBALS['test_thumbnail_ids'][42] = 8;
$GLOBALS['test_current_post_id']   = 42;
$page = new WP_Post(['ID' => 42, 'post_type' => 'page']);
assert_same('From featured image', Bricks::render_tag('{sfx_media_copyright}', $page, null), 'Case 3d: a non-attachment post falls back to its featured image');

$GLOBALS['test_thumbnail_ids'] = [];
assert_same('', Bricks::render_tag('{sfx_media_copyright}', $page, null), 'Case 3e: nothing resolvable renders empty, never the literal tag');

// ------------------------------------------------ Case 4: render_content

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

$out = Bricks::render_content('Bild: {sfx_media_copyright}.', $post, null);
assert_same('Bild: Foto Müller.', $out, 'Case 4a: the tag is replaced in place');
assert_same('Nothing here.', Bricks::render_content('Nothing here.', $post, null), 'Case 4b: content without our tags is untouched');
assert_contains('{post_title}', Bricks::render_content('{post_title}', $post, null), 'Case 4c: foreign tags survive');

// Braces from stored data must not reach the page even through the tags.
seed_attachment(5, '{echo:phpinfo}');
assert_not_contains('{echo:', Bricks::render_content('{sfx_media_copyright}', $post, null), 'Case 4d: brace escaping applies to tag output too');
// Exact string, not a substring: this is what catches a double-encoded
// &amp;#123; from a wrong escaping order, which a contains() check would pass.
assert_same('&#123;echo:phpinfo&#125;', Bricks::render_content('{sfx_media_copyright}', $post, null), 'Case 4e: exactly one round of entity escaping on the render_content path');

// ------------------------------------------ Case 5: the effective caption
// This mirrors Bricks' own branch order (image.php:794-810). The third branch
// is the one that is easy to miss: type 'custom' with an EMPTY field still
// falls through to the attachment caption.

test_reset();
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => 'Attachment caption']);

assert_same('', Bricks::effective_caption(['caption' => 'none'], 5), 'Case 5a: type none renders nothing');
assert_same('Mine', Bricks::effective_caption(['caption' => 'custom', 'captionCustom' => 'Mine'], 5), 'Case 5b: a non-empty custom caption');
assert_same('Attachment caption', Bricks::effective_caption(['caption' => 'custom', 'captionCustom' => ''], 5), 'Case 5c: an EMPTY custom caption falls through to the attachment');
assert_same('Attachment caption', Bricks::effective_caption(['caption' => 'attachment'], 5), 'Case 5d: type attachment');
assert_same('Attachment caption', Bricks::effective_caption([], 5), 'Case 5e: an unset control defaults to attachment, not to nothing');
assert_same('', Bricks::effective_caption([], 5, 'none'), 'Case 5f: the theme-style default is honoured when passed');
assert_same('', Bricks::effective_caption(['caption' => 'attachment'], 0), 'Case 5g: no image, no caption');

// The two boundaries where PHP's empty() and a trim() test disagree, and
// where Bricks follows empty(): '0' is empty and falls through, whitespace is
// not empty and trims to nothing.
assert_same('Attachment caption', Bricks::effective_caption(['caption' => 'custom', 'captionCustom' => '0'], 5), 'Case 5h: "0" is empty to Bricks and falls through');
assert_same('', Bricks::effective_caption(['caption' => 'custom', 'captionCustom' => '   '], 5), 'Case 5i: a whitespace-only custom caption renders as nothing, it does not fall through');

// ------------------------------------------------ Case 6: marker matching

assert_same(true, Bricks::has_marker('<figure><span class="sfx-credit">x</span></figure>'), 'Case 6a: the marker is found');
assert_same(true, Bricks::has_marker('<span class="sfx-credit sfx-credit--overlay">x</span>'), 'Case 6b: found among other classes');
assert_same(false, Bricks::has_marker('<span class="sfx-credit-note">x</span>'), 'Case 6c: a longer class is NOT the marker');
assert_same(false, Bricks::has_marker('the words sfx-credit in prose'), 'Case 6d: prose is not a class attribute');
assert_same(false, Bricks::has_marker(''), 'Case 6e: empty is empty');

assert_same(true, Bricks::has_no_credit_class(['_cssClasses' => 'foo no-credit bar']), 'Case 6f: no-credit as one token among many');
assert_same(false, Bricks::has_no_credit_class(['_cssClasses' => 'no-credit-card']), 'Case 6g: a longer class does not suppress');
assert_same(false, Bricks::has_no_credit_class([]), 'Case 6h: no classes, no suppression');

// -------------------------------------------- Case 7: tag substitution

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller', 'ai_generated');

$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => 'Vorher {sfx_media_credit} nachher'],
    ['id' => 5]
);

$out = Bricks::element_settings($element->settings, $element);
assert_contains('Foto Müller', $out['captionCustom'], 'Case 7a: the tag is replaced with the credit');
assert_contains('class="sfx-credit"', $out['captionCustom'], 'Case 7b: wrapped in the marker, so auto-output can see it');
assert_not_contains('{sfx_media_credit}', $out['captionCustom'], 'Case 7c: nothing of the tag is left');
assert_contains('Vorher', $out['captionCustom'], 'Case 7d: surrounding text survives');

// Only captionCustom. altText is rendered into an attribute and _cssClasses
// was consumed before this filter ran (base.php:2908, :2929).
$element = new Test_Bricks_Element(
    ['altText' => '{sfx_media_credit}', '_cssClasses' => '{sfx_media_credit}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_same('{sfx_media_credit}', $out['altText'], 'Case 7e: altText is left alone');
assert_same('{sfx_media_credit}', $out['_cssClasses'], 'Case 7f: _cssClasses is left alone');

// An explicit id inside an image element still wins.
seed_attachment(9, 'Anderes Bild');
$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => '{sfx_media_copyright:9}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_contains('Anderes Bild', $out['captionCustom'], 'Case 7g: an explicit id beats the element image');

// A non-image element is not ours.
$other = new Test_Bricks_Element(['captionCustom' => '{sfx_media_credit}'], ['id' => 5], 'heading');
assert_same('{sfx_media_credit}', Bricks::element_settings($other->settings, $other)['captionCustom'], 'Case 7h: only the image element is touched');

// ------------------------------------------- Case 8: caption auto-output

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => 'Attachment caption']);

$element = new Test_Bricks_Element([], ['id' => 5]);
$out = Bricks::element_settings($element->settings, $element);

assert_same('custom', $out['caption'], 'Case 8a: the caption type is switched to custom');
assert_contains('Attachment caption', $out['captionCustom'], 'Case 8b: the existing attachment caption is preserved');
assert_contains('Foto Müller', $out['captionCustom'], 'Case 8c: and the credit is added');
assert_contains('class="sfx-credit"', $out['captionCustom'], 'Case 8d: marked, so overlay mode would not add it twice');

// No existing caption: the credit stands alone, with no stray separator.
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => '']);
Credit::reset_cache();
$element = new Test_Bricks_Element([], ['id' => 5]);
$out = Bricks::element_settings($element->settings, $element);
assert_not_contains('<br>', $out['captionCustom'], 'Case 8e: no separator when there was no caption');

// Dedup: a hand-placed tag must not be doubled by auto-output.
$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => '{sfx_media_credit}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_same(1, substr_count($out['captionCustom'], 'class="sfx-credit"'), 'Case 8f: exactly one credit, not two');

// Dedup tests the EFFECTIVE caption, not the raw setting. A marker sitting in
// a captionCustom Bricks will not render (type attachment) must NOT suppress
// the auto-credit — that would ship the image with no disclosure at all.
$GLOBALS['test_posts'][5] = new WP_Post(['ID' => 5, 'post_excerpt' => '']);
Credit::reset_cache();
$element = new Test_Bricks_Element(
    ['caption' => 'attachment', 'captionCustom' => '<span class="sfx-credit">stale</span>'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_contains('Foto Müller', $out['captionCustom'], 'Case 8g: a marker in an unrendered caption does not suppress the credit');

// no-credit opts the element out.
$element = new Test_Bricks_Element(['_cssClasses' => 'no-credit'], ['id' => 5]);
$out = Bricks::element_settings($element->settings, $element);
assert_same(false, isset($out['caption']), 'Case 8h: no-credit suppresses caption auto-output');

// Nothing stored, nothing added.
test_reset();
seed_attachment(6, '', '');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
$GLOBALS['test_posts'][6] = new WP_Post(['ID' => 6, 'post_excerpt' => '']);
$element = new Test_Bricks_Element([], ['id' => 6]);
assert_same(false, isset(Bricks::element_settings($element->settings, $element)['caption']), 'Case 8i: an image with no credit data is untouched');

// ------------------------------------------------ Case 9: force_wrapper

test_reset();
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay', 'force_wrapper' => 1];

$element = new Test_Bricks_Element([], ['id' => 5]);
assert_same('figure', Bricks::element_settings($element->settings, $element)['tag'] ?? null, 'Case 9a: an element with no tag gets one, which is what flips has_html_tag');

$element = new Test_Bricks_Element(['tag' => 'div'], ['id' => 5]);
assert_same('div', Bricks::element_settings($element->settings, $element)['tag'], 'Case 9b: a tag the user chose is never overwritten');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption', 'force_wrapper' => 1];
$element = new Test_Bricks_Element([], ['id' => 5]);
assert_same(false, isset(Bricks::element_settings($element->settings, $element)['tag']), 'Case 9c: force_wrapper is an overlay-mode setting only');

// ------------------------------------------------- Case 10: root detection

assert_same('figure', Bricks::root_tag('<figure class="x"><img></figure>'), 'Case 10a: figure root');
assert_same('div', Bricks::root_tag("\n  <div><img></div>"), 'Case 10b: leading whitespace is skipped');
assert_same('img', Bricks::root_tag('<img src="x">'), 'Case 10c: a bare image root');
assert_same('', Bricks::root_tag('no markup at all'), 'Case 10d: no tag, no root');

// ------------------------------------------------ Case 11: overlay injection

$line = '©&nbsp;Foto';

$out = Bricks::inject_overlay('<figure class="x"><img src="a"></figure>', $line);
assert_contains('sfx-credit--overlay', $out, 'Case 11a: the overlay is inserted');
assert_same(true, strpos($out, 'sfx-credit--overlay') < strpos($out, '</figure>'), 'Case 11b: inside the wrapper, before its closing tag');

$out = Bricks::inject_overlay('<section><img src="a"></section>', $line);
assert_contains('sfx-credit--overlay', $out, 'Case 11c: a custom root tag also takes the overlay');

// The three roots that must be left alone. Wrapping them would change the
// layout of a page nobody asked us to change.
foreach (['<img src="a">', '<picture><img src="a"></picture>', '<a href="#"><img src="a"></a>'] as $i => $html) {
    assert_same($html, Bricks::inject_overlay($html, $line), "Case 11d{$i}: no wrapper, no injection");
}

// ------------------------------------------- Case 12: render_element gate

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay'];

$element = new Test_Bricks_Element(['caption' => 'none'], ['id' => 5]);
$html    = '<figure><img src="a"></figure>';

assert_contains('Foto Müller', Bricks::render_element($html, $element), 'Case 12a: overlay mode injects the credit');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
assert_same($html, Bricks::render_element($html, $element), 'Case 12b: caption mode does not inject HTML');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'off'];
assert_same($html, Bricks::render_element($html, $element), 'Case 12c: off means off');

$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay'];
$optout = new Test_Bricks_Element(['_cssClasses' => 'no-credit'], ['id' => 5]);
assert_same($html, Bricks::render_element($html, $optout), 'Case 12d: no-credit opts out');

$already = '<figure><span class="sfx-credit">done</span><img src="a"></figure>';
assert_same($already, Bricks::render_element($already, $element), 'Case 12e: an element that already carries a credit gets no second one');

$heading = new Test_Bricks_Element([], ['id' => 5], 'heading');
assert_same($html, Bricks::render_element($html, $heading), 'Case 12f: only image elements');

// ------------------------------------------ Case 13: data-sfx-ai attribute

test_reset();
seed_attachment(5, '', 'ai_generated');
$attachment = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

$attr = Bricks::image_attributes(['src' => 'x'], $attachment);
assert_same('ai_generated', $attr['data-sfx-ai'] ?? null, 'Case 13a: the slug is exposed on the img');

seed_attachment(6, 'Foto', '');
$attr = Bricks::image_attributes(['src' => 'x'], new WP_Post(['ID' => 6, 'post_type' => 'attachment']));
assert_same(false, isset($attr['data-sfx-ai']), 'Case 13b: no AI marking, no attribute');
assert_same('not an array', Bricks::image_attributes('not an array', $attachment), 'Case 13c: a non-array passes through');

// ------------------------------- Case 14: sfx_media_credits_parts, the four sinks
//
// Gate A pass 1's blocker: Credit::for() returns the raw $parts array
// ALONGSIDE the gated line (Credit.php:92-95), and four separate consumers
// read it, each with its own escaping. Asserting the composed line and
// inferring the rest was exactly the reasoning pass 1 refuted, so each sink
// gets its own assertion here, and every escaping assertion is an exact
// string — a not_contains('{echo:') would still pass on a double-encoded
// '&amp;#123;echo:phpinfo&amp;#125;', which is visibly broken output.

test_reset();
\Bricks\Query::reset();
seed_attachment(5, 'Foto Müller', 'ai_generated');
$post = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);

// Baseline, unfiltered: this is the label a coherence bug would leak.
assert_same('KI-generiert', Bricks::render_tag('{sfx_media_ai_label}', $post, null), 'Case 14a: baseline label before any parts filter runs');

$GLOBALS['test_filter_returns']['sfx_media_credits_parts'] = static function (array $parts) {
    $parts['copyright'] = '{echo:phpinfo}';
    $parts['ai_key']    = 'ai_hallucinated'; // not in Settings::get_labels(): must sanitize to ''
    unset($parts['ai_label']);               // and no explicit label bridges the gap either
    return $parts;
};
Credit::reset_cache();

// Sink 1: Credit::for()['line'], through the gate at Credit.php:88-93.
// with_copyright_prefix() runs esc_html() (a no-op on braces — they are not
// HTML-special) and prepends '©&nbsp;'; the ai part is '' because the
// invalid key clears the whole tuple, so compose() never reaches the
// separator and the line is copyright alone. wp_kses_post() is a no-op on
// brace-only input, and escape_braces() runs last.
assert_same('©&nbsp;&#123;echo:phpinfo&#125;', Credit::for(5)['line'], 'Case 14b: sink 1 (the composed line) is brace-escaped, exact string');

// Sink 2: a dynamic tag, through Bricks::raw_value(). It returns the RAW
// parts value for its own control to escape — no © prefix, no ai part, no
// wp_kses_post() — brace-escaped only (Bricks.php:165).
assert_same('&#123;echo:phpinfo&#125;', Bricks::render_tag('{sfx_media_copyright}', $post, null), 'Case 14c: sink 2 (raw_value dynamic tag) brace-escapes the copyright on its own, independent of the line gate');

// Coherence, same sink: the invalid ai_key must not leave the PREVIOUS
// label readable through the tag. It must be empty, not "KI-generiert".
assert_same('', Bricks::render_tag('{sfx_media_ai_label}', $post, null), 'Case 14d: sink 2 coherence — an invalid ai_key empties the label tag, not the stale one from Case 14a');

// Sink 3: a tag substituted into a caption, through Bricks::substitute().
// It wraps the value in the marker span and runs
// escape_braces(esc_html(...)) — esc_html() is again a no-op on braces, so
// the escaped fragment is identical to sink 2's, now inside the span.
$element = new Test_Bricks_Element(
    ['caption' => 'custom', 'captionCustom' => '{sfx_media_copyright}'],
    ['id' => 5]
);
$out = Bricks::element_settings($element->settings, $element);
assert_same('<span class="sfx-credit">&#123;echo:phpinfo&#125;</span>', $out['captionCustom'], 'Case 14e: sink 3 (substitute, a tag inside a caption) brace-escapes the tag value, exact string');

// Sink 4: the machine-readable attribute, through Bricks::image_attributes().
// It reads ai_key alone — never ai_label, never copyright — and the invalid
// key sanitizes to ''. The attribute must therefore be ABSENT: never the
// filter's 'ai_hallucinated' text, and never a fallback to the previous
// 'ai_generated' slug either.
$attr = Bricks::image_attributes(['src' => 'x'], $post);
assert_same(false, isset($attr['data-sfx-ai']), 'Case 14f: sink 4 (the attribute) — an invalid ai_key leaves data-sfx-ai absent, never filter text');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_parts']);
Credit::reset_cache();

// ------------------------------ Case 15: sfx_media_credits_should_auto_output
//
// Gate A pass 3's finding: the decision is taken ONCE, at settings time, and
// memoised against the element OBJECT — never $id or $uid, and never
// re-evaluated at render time. Pass 1 and pass 2 both got this wrong in ways
// that left a `figure` wrapper with no credit inside it; see the addendum's
// should_auto_output subsection for the full history.

test_reset();
\Bricks\Query::reset();
Bricks::reset_decisions();

// 15a: caption mode, filter false — caption/captionCustom left untouched.
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'caption'];
$GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output'] = static function ($should, array $args) {
    return false;
};

$element = new Test_Bricks_Element([], ['id' => 5]);
$out     = Bricks::element_settings($element->settings, $element);
assert_same(false, isset($out['caption']), 'Case 15a: caption untouched when should_auto_output returns false');
assert_same(false, isset($out['captionCustom']), 'Case 15a2: captionCustom untouched too');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output']);
Bricks::reset_decisions();

// 15b/15c: overlay mode + force_wrapper, filter false — settings-time half:
// no tag key is written at all (this is the pass-1 finding: a `figure`
// forced open for a credit that never arrives). Render-time half: the SAME
// decision, consumed rather than re-evaluated, injects nothing.
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay', 'force_wrapper' => 1];
$GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output'] = static function ($should, array $args) {
    return false;
};

$element = new Test_Bricks_Element([], ['id' => 5]);
$out     = Bricks::element_settings($element->settings, $element);
assert_same(false, isset($out['tag']), 'Case 15b: no tag key set, even with force_wrapper on, when should_auto_output is false');

$element->settings = $out;
$html   = '<figure><img src="a"></figure>';
$result = Bricks::render_element($html, $element);
assert_same($html, $result, 'Case 15c: render_element injects nothing, consuming the settings-time decision rather than re-evaluating');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output']);
Bricks::reset_decisions();

// 15d: the filter must not be consulted by image_attributes() at all — the
// machine-readable marking survives even should_auto_output returning false.
seed_attachment(5, '', 'ai_generated');
$GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output'] = static function ($should, array $args) {
    return false;
};
$attachment = new WP_Post(['ID' => 5, 'post_type' => 'attachment']);
$attr       = Bricks::image_attributes(['src' => 'x'], $attachment);
assert_same('ai_generated', $attr['data-sfx-ai'] ?? null, 'Case 15d: data-sfx-ai survives should_auto_output returning false');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output']);
Bricks::reset_decisions();

// 15e/15f: loop safety, the pass-3 finding. Two DISTINCT element instances
// carrying the SAME id — exactly what Bricks constructs for one element
// rendered across a query loop of many posts (frontend.php:743, base.php:74-76)
// — must each get their OWN decision. A filter that returns true for the
// first call and false for the second proves the memo does not collide.
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay'];

$calls = 0;
$GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output'] = static function ($should, array $args) use (&$calls) {
    $calls++;
    return $calls === 1;
};

$first  = new Test_Bricks_Element([], ['id' => 5], 'image', 'loop-image');
$second = new Test_Bricks_Element([], ['id' => 5], 'image', 'loop-image');
assert_same('loop-image', $first->id, 'Case 15e0: sanity — both loop instances carry the same $id');
assert_same($first->id, $second->id, 'Case 15e1: sanity — the ids are identical, only the instances differ');

$first->settings  = Bricks::element_settings($first->settings, $first);
$second->settings = Bricks::element_settings($second->settings, $second);
assert_same(2, $calls, 'Case 15e2: the filter ran once per instance, not once per id');

$html = '<figure><img src="a"></figure>';
assert_contains('Foto Müller', Bricks::render_element($html, $first), 'Case 15e: the first loop iteration (filter returned true) gets its credit');
assert_same($html, Bricks::render_element($html, $second), 'Case 15f: the second loop iteration — SAME id, DIFFERENT instance — gets none; keying on $id would freeze the first decision for both');

unset($GLOBALS['test_filter_returns']['sfx_media_credits_should_auto_output']);
Bricks::reset_decisions();

// 15g: no filter registered — render_element() falls back to evaluating for
// itself when element_settings() never saw the instance, and behaviour is
// byte-identical to before this task.
seed_attachment(5, 'Foto Müller');
$GLOBALS['test_options'][Settings::OPTION_NAME] = ['output_mode' => 'overlay'];
$element = new Test_Bricks_Element(['caption' => 'none'], ['id' => 5]);
$html    = '<figure><img src="a"></figure>';
assert_contains('Foto Müller', Bricks::render_element($html, $element), 'Case 15g: no settings-time decision recorded — render_element evaluates the filter itself and injects as before');

Bricks::reset_decisions();

// ------------------------------------------------------------- epilogue

global $failures;

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}

echo "PASS: all media-credits bricks tests\n";
exit(0);

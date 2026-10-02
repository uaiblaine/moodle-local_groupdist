<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_groupdist\local;

/**
 * Pins the class-name and stylesheet rules no linter checks.
 *
 * phpcs, the mustache lint and stylelint never read a class name out of a
 * Mustache or JS file, so an illegible badge or a deprecated Bootstrap 4
 * spelling passes every other check. On this branch the plugin runs on
 * Moodle 4.5, which ships Bootstrap 4, so the class also pins the polyfill at
 * the tail of styles.css, the pages that switch it on, the data-API spellings
 * both Bootstrap versions read and the fallbacks the theme tokens need there.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class bootstrap_compat_test extends \basic_testcase {
    /**
     * Background utilities that need an explicit text colour on a badge.
     *
     * Bootstrap 5's .badge defaults to white text, so a light background is
     * illegible: bg-light (#f8f9fa) with the default colour is 1.05:1 against
     * the 4.5:1 AA floor, and 15.37:1 with text-dark. The saturated backgrounds
     * are listed too, so that no badge relies on the default colour.
     *
     * Each background takes exactly the utility named here. The bg-* utilities
     * keep their colour in dark mode, while text-muted and text-body follow the
     * theme: bg-light text-muted is about 1.2:1 there, light grey on near-white.
     *
     * @return array Background utility => the text utility it needs.
     */
    private function badge_text_colours(): array {
        return [
            'bg-light' => 'text-dark',
            'bg-secondary' => 'text-dark',
            'bg-warning' => 'text-dark',
            'bg-success' => 'text-white',
            'bg-primary' => 'text-white',
            'bg-danger' => 'text-white',
            'bg-info' => 'text-white',
            'bg-dark' => 'text-white',
        ];
    }

    /**
     * Bootstrap 4 spellings that only still resolve on 5.x through
     * bs4-compat.scss, which wraps each in deprecated-styles() and which
     * Moodle 6.0 removes (MDL-84465).
     *
     * @return array List of regular expressions.
     */
    private function bootstrap4_only_names(): array {
        return [
            '/\b[mp][lr]-[0-9]\b/',
            '/\btext-(left|right)\b/',
            '/\bfloat-(left|right)\b/',
            '/\bborder-(left|right)\b/',
            '/\brounded-(left|right)\b/',
            '/\bsr-only\b/',
            '/\bno-gutters\b/',
        ];
    }

    /**
     * Every file whose contents can put a class name in front of a reader.
     *
     * amd/build is generated from amd/src and docs is export-ignored, so both
     * are skipped: a finding there is a duplicate or is never shipped.
     *
     * @return array List of absolute file paths.
     */
    private function markup_files(): array {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach ([$root . '/templates', $root . '/amd/src', $root . '/classes'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['mustache', 'js', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Whether a line is prose rather than markup.
     *
     * These rules are about what reaches the browser: a comment naming a class
     * in order to explain the rule is not a breach of it.
     *
     * @param string $line One raw source line.
     * @return bool Whether the line opens with a comment marker.
     */
    private function is_comment_line(string $line): bool {
        $trimmed = ltrim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '{{!');
    }

    /**
     * Every badge background states the text colour badge_text_colours() names.
     *
     * Checked on every line carrying a background utility, not only on lines
     * that also say "badge": the word may sit on another line, e.g. in a method
     * name. Two exemptions: the text-bg-* utilities, which set background and
     * text colour together, and progress bar fills, which carry no text.
     */
    public function test_badges_state_their_text_colour(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line)) {
                    continue;
                }
                if (str_contains($line, 'progress-bar')) {
                    // A meter fill carries no text; its label is the aria-label.
                    continue;
                }
                foreach ($this->badge_text_colours() as $background => $required) {
                    // The lookbehind spares text-bg-*, which is the pairing itself.
                    if (!preg_match('/(?<!text-)\b' . preg_quote($background, '/') . '\b/', $line)) {
                        continue;
                    }
                    // The lookarounds keep text-dark-emphasis from passing for text-dark.
                    if (!preg_match('/(?<![\w-])' . preg_quote($required, '/') . '(?![\w-])/', $line)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' needs ' . $required;
                    }
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 5 defaults .badge text to white, and a theme-relative text colour flips in dark '
                . 'mode while the background does not: ' . implode('; ', $offenders)
        );
    }

    /**
     * Warning-coloured text uses text-warning-emphasis, never text-warning.
     *
     * text-warning paints the theme's warning colour, #f0ad4e on 5.1 and 5.2
     * Boost: about 1.9:1 on white, under the 4.5:1 AA floor.
     * text-warning-emphasis reads --bs-warning-text-emphasis, #60451f in light
     * mode and #f6ce95 in dark mode, above 8:1 on the page background in both.
     * Font Awesome icons are not checked: they are aria-hidden, and the text
     * beside each states the same condition.
     */
    public function test_warning_text_uses_the_emphasis_colour(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line) || str_contains($line, '<i class="fa ')) {
                    continue;
                }
                if (preg_match('/(?<![\w-])text-warning(?![\w-])/', $line)) {
                    $offenders[] = basename($path) . ':' . ($number + 1);
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'text-warning fails AA contrast on a light background; use text-warning-emphasis: '
                . implode('; ', $offenders)
        );
    }

    /**
     * No Bootstrap 4 spelling survives anywhere in the shipped markup.
     */
    public function test_no_bootstrap4_only_class_names(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line)) {
                    continue;
                }
                foreach ($this->bootstrap4_only_names() as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' uses ' . $matches[0];
                    }
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'These resolve on 5.x only through bs4-compat.scss, which paints a deprecation outline '
                . 'under themedesignermode and which Moodle 6.0 removes: ' . implode('; ', $offenders)
        );
    }

    /**
     * The plugin never declares a custom property in core's own namespace.
     *
     * Core's design system prefixes its tokens with mds- (the $mds-* SCSS
     * tokens of theme/boost/scss/design-system in Moodle 5.2; Boost reads
     * --mds-* custom properties in 5.3), so a plugin declaring --mds-* can
     * collide with core.
     */
    public function test_no_mds_namespace(): void {
        $css = file_get_contents(dirname(__DIR__, 2) . '/styles.css');
        $this->assertSame(
            0,
            preg_match_all('/--mds-[a-z0-9-]+\s*:/', $css),
            'Custom properties must carry the plugin\'s own frankenstyle prefix, not core\'s --mds-*'
        );
    }
    /**
     * The source-search suggestion list must be laid out by styles.css.
     *
     * The list is rendered position-absolute with up to 20 matches. Without a
     * z-index, a background and a height bound it draws as a transparent column
     * under the neighbouring content, where its options cannot be clicked.
     */
    public function test_the_source_suggestion_list_is_laid_out(): void {
        $css = file_get_contents(__DIR__ . '/../../styles.css');
        $this->assertSame(
            1,
            preg_match('/\.local-groupdist-sourceresults\s*\{(.*?)\}/s', $css, $matches),
            'The suggestion list has no rule in styles.css at all.'
        );
        $rule = $matches[1];

        foreach (['z-index', 'background', 'max-height', 'overflow-y'] as $property) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($property, '/') . '\s*:/',
                $rule,
                $property . ' is missing: without it the list is unclickable, unbounded or see-through.'
            );
        }
        // A positioned element only wins the stacking contest with a real number.
        $this->assertDoesNotMatchRegularExpression('/z-index\s*:\s*auto/', $rule);
        // Colours follow the theme, because 5.1 and 5.2 both ship dark mode.
        $this->assertMatchesRegularExpression('/background\s*:\s*var\(--bs-/', $rule);
    }

    /**
     * A chosen search value can be cleared, and it replaces the search box.
     *
     * A search box left beside the chip reads as "nothing selected", and without
     * a clear control the only way to change the value is to delete the rule.
     */
    public function test_a_chosen_search_value_can_be_cleared(): void {
        $row = file_get_contents(__DIR__ . '/../../templates/rules_row.mustache');

        $this->assertStringContainsString('data-action="clearsource"', $row);
        // Chip and search box are exclusive: the box lives in the inverted
        // section of the same variable that paints the chip.
        $this->assertSame(
            2,
            preg_match_all('/\{\{\^chosenlabel\}\}/', $row),
            'The search box must sit in an inverted chosenlabel section, once per searchable kind.'
        );
        $this->assertSame(2, preg_match_all('/\{\{#chosenlabel\}\}/', $row));
    }

    /**
     * Bootstrap 5 classes that Moodle 4.5 does not define, as regular expressions.
     *
     * Every family here is defined by the compiled Boost CSS of 5.2 and by none
     * of 4.5's core rules, checked family by family. The list is wider than
     * what the markup uses today so that a class arriving with a change from
     * main is caught here rather than rendering as nothing on 4.5. form-label
     * is not here: 4.5 has no rule for it, but Bootstrap 4's reboot already
     * gives a label its margin.
     *
     * @return array List of regular expressions, each matching whole class tokens.
     */
    private function bs5_only_utilities(): array {
        $families = [
            'visually-hidden(-focusable)?',
            'form-select(-sm|-lg)?',
            'form-(switch|check-reverse|control-color|floating|range)',
            '(row-|column-)?gap-[0-5]',
            'fw-(bold|bolder|semibold|medium|normal|light|lighter)',
            'fst-(italic|normal)',
            'font-monospace',
            'fs-[1-6]',
            'lh-(1|sm|base|lg)',
            'opacity-(0|10|25|50|75|100)',
            '(bg|text|border)-opacity-(10|25|50|75|100)',
            'text-bg-[a-z]+',
            'text-[a-z]+-emphasis',
            'text-body-(secondary|tertiary|emphasis)',
            'bg-body(-secondary|-tertiary)?',
            '(bg|border)-[a-z]+-subtle',
            'd-(inline-)?grid',
            '(top|bottom|start|end)-(0|50|100)',
            'translate-middle(-x|-y)?',
            'border-[1-5]',
            'rounded-[1-5]',
            'ratio(-[0-9x]+)?',
            'vr',
            '[hv]stack',
            'z-[0-3]',
            'object-fit-[a-z-]+',
            'link-(offset|opacity)-[0-9]+(-hover)?',
            'link-underline-[a-z0-9-]+',
            'icon-link',
            'focus-ring',
            'g[xy]?-[1-5]',
        ];
        $patterns = array_map(static fn ($family) => '/(?<![\w-])(' . $family . ')(?![\w-])/', $families);
        // The lookahead also spares the input attribute of the same name.
        $patterns[] = '/(?<![\w-])placeholder(-(glow|wave|xs|sm|lg))?(?![\w=-])/';
        return $patterns;
    }

    /**
     * The exact class tokens the polyfill defines behind the Bootstrap 4 gate.
     *
     * Token level on purpose: a family check would pass with gap-1 defined and
     * gap-3 missing.
     *
     * @return array List of class tokens, without the leading dot.
     */
    private function polyfilled_tokens(): array {
        $css = file_get_contents(dirname(__DIR__, 2) . '/styles.css');
        // The block's own prose names classes it does not define.
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $gate = preg_quote(bootstrap::BODY_CLASS_BS4, '/');
        $tokens = [];
        foreach (explode('}', $css) as $block) {
            $selector = explode('{', $block)[0];
            if (!preg_match('/body\.' . $gate . '(?![\w-])/', $selector)) {
                continue;
            }
            preg_match_all('/\.([a-z][a-z0-9-]*)/', $selector, $matches);
            $tokens = array_merge($tokens, $matches[1]);
        }
        return array_values(array_unique($tokens));
    }

    /**
     * The badge colour suffixes the PHP side hands to a text-bg-{{...}} class.
     *
     * The audit templates build the class from a context value, so the token
     * never appears whole in a template; the values do, as the 'class' entries
     * of the status and outcome arrays in classes/.
     *
     * @return array List of suffixes, e.g. success.
     */
    private function badge_suffixes(): array {
        $suffixes = [];
        foreach ($this->markup_files() as $path) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            preg_match_all("/'class' => '([a-z]+)'/", file_get_contents($path), $matches);
            $suffixes = array_merge($suffixes, $matches[1]);
        }
        return array_values(array_unique($suffixes));
    }

    /**
     * The Bootstrap 5 class tokens the markup uses that 4.5 does not define.
     *
     * @return array Token => list of file basenames using it.
     */
    private function used_bs5_tokens(): array {
        $used = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $line) {
                if ($this->is_comment_line($line)) {
                    continue;
                }
                $tokens = [];
                foreach ($this->bs5_only_utilities() as $pattern) {
                    preg_match_all($pattern, $line, $matches);
                    $tokens = array_merge($tokens, $matches[0]);
                }
                if (str_contains($line, 'text-bg-{{')) {
                    foreach ($this->badge_suffixes() as $suffix) {
                        $tokens[] = 'text-bg-' . $suffix;
                    }
                }
                foreach ($tokens as $token) {
                    $used[$token][basename($path)] = true;
                }
            }
        }
        return array_map('array_keys', $used);
    }

    /**
     * Every Bootstrap 5 class the markup uses that 4.5 lacks is polyfilled.
     *
     * Changes that must make it fail: deleting the .gap-3 rule from the
     * polyfill, or the .text-bg-danger rule, which only an audit status or
     * outcome value reaches.
     *
     * @return void
     */
    public function test_every_bs5_utility_used_is_polyfilled(): void {
        $polyfilled = $this->polyfilled_tokens();
        $this->assertNotEmpty($this->badge_suffixes(), 'No badge suffix found in classes/, so text-bg-{{...}} goes unchecked.');
        $missing = [];
        foreach ($this->used_bs5_tokens() as $token => $files) {
            if (!in_array($token, $polyfilled, true)) {
                $missing[] = $token . ' (' . implode(', ', array_slice($files, 0, 3)) . ')';
            }
        }
        sort($missing);
        $this->assertSame(
            [],
            $missing,
            'These Bootstrap 5 classes resolve to nothing on Moodle 4.5; define them in the polyfill at the '
                . 'tail of styles.css: ' . implode('; ', $missing)
        );
    }

    /**
     * The polyfill defines nothing the markup no longer uses.
     *
     * Change that must make it fail: adding a .fw-light rule to the polyfill.
     *
     * @return void
     */
    public function test_polyfill_carries_nothing_unused(): void {
        // The gate itself, and the form-check pair whose Bootstrap 4 layout the polyfill corrects.
        $structural = [bootstrap::BODY_CLASS_BS4, 'form-check', 'form-check-input'];
        $unused = array_values(array_diff(
            $this->polyfilled_tokens(),
            array_keys($this->used_bs5_tokens()),
            $structural
        ));
        sort($unused);
        $this->assertSame(
            [],
            $unused,
            'The Bootstrap 4 polyfill defines classes nothing uses any more; delete them: ' . implode(', ', $unused)
        );
    }

    /**
     * Every plugin page that prints a header adds the polyfill's gate first.
     *
     * add_body_class() throws once the header is out, so the call must come
     * before it. Code only: comments are stripped before matching. Change
     * that must make it fail: removing the mark_page() call from bulkedit.php.
     *
     * @return void
     */
    public function test_entry_points_mark_the_bootstrap_version(): void {
        $checked = 0;
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/*.php') ?: [] as $path) {
            $code = '';
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            $header = strpos($code, '$OUTPUT->header()');
            if ($header === false) {
                continue;
            }
            $checked++;
            $mark = strpos($code, 'bootstrap::mark_page();');
            if ($mark === false || $mark > $header) {
                $offenders[] = basename($path);
            }
        }
        $this->assertGreaterThan(0, $checked, 'No page prints a header, so this test checked nothing.');
        $this->assertSame(
            [],
            $offenders,
            'These pages print a header without calling bootstrap::mark_page() before it, so the Bootstrap 4 '
                . 'polyfill never reaches them: ' . implode(', ', $offenders)
        );
    }

    /**
     * Markup wired to Bootstrap's data API carries both versions' spellings.
     *
     * Bootstrap 4 listens on data-toggle and places a right-aligned menu by
     * dropdown-menu-right; Bootstrap 5 reads data-bs-toggle and
     * dropdown-menu-end. Two exemptions: tooltips, which bulkedit.js
     * constructs itself, where a data-toggle="tooltip" would also match
     * Boost 4.5's delegated tooltip handler and give each element two; and
     * data-bs-auto-close, which Bootstrap 4 has no option for (bulkedit.js
     * stops the column menu's clicks instead). Changes that must make it
     * fail: removing data-toggle or dropdown-menu-right from the column menu
     * in bulkedit.mustache.
     *
     * @return void
     */
    public function test_data_api_attributes_are_paired(): void {
        $pairs = [
            'data-bs-toggle' => 'data-toggle',
            'data-bs-target' => 'data-target',
            'data-bs-dismiss' => 'data-dismiss',
            'data-bs-parent' => 'data-parent',
            'dropdown-menu-end' => 'dropdown-menu-right',
        ];
        $checked = 0;
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line) || preg_match('/data-bs-toggle\W+tooltip/', $line)) {
                    continue;
                }
                foreach ($pairs as $bs5 => $bs4) {
                    if (!preg_match('/(?<![\w-])' . preg_quote($bs5, '/') . '(?![\w-])/', $line)) {
                        continue;
                    }
                    $checked++;
                    if (!preg_match('/(?<![\w-])' . preg_quote($bs4, '/') . '(?![\w-])/', $line)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' has ' . $bs5 . ' without ' . $bs4;
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $checked, 'No data-API markup found, so this test checked nothing.');
        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 4 (Moodle 4.5) reads only its own spelling, so the component never opens there: '
                . implode('; ', $offenders)
        );
    }

    /**
     * Every Bootstrap 5 theme token in styles.css carries a fallback.
     *
     * Moodle 4.5 defines the Bootstrap 4 names (--success, --warning) and no
     * --bs-* token, and an unresolved var() without a fallback makes the whole
     * declaration invalid rather than falling back to the property's default.
     * Change that must make it fail: dropping the fallback from the rule
     * builder's border colour.
     *
     * @return void
     */
    public function test_theme_tokens_carry_a_fallback(): void {
        $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents(dirname(__DIR__, 2) . '/styles.css'));
        $this->assertGreaterThan(0, preg_match_all('/var\(\s*--bs-/', $css), 'styles.css reads no theme token.');
        preg_match_all('/var\(\s*--bs-[a-z0-9-]+\s*\)/', $css, $matches);
        $this->assertSame(
            [],
            $matches[0],
            'Moodle 4.5 defines no --bs-* token; chain these as var(--bs-x, var(--x, literal)): '
                . implode(', ', $matches[0])
        );
    }
}

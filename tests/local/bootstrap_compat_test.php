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
 * spelling passes every other check. The plugin supports 5.1+ only, so it
 * needs no Bootstrap 4 polyfill; these are the rules that bind on 5.x.
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
}

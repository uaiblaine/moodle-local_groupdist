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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Guards the class-name rules nothing else in the pipeline can see.
 *
 * phpcs reads PHP, the mustache lint reads HTML structure and stylelint reads
 * CSS; none of them reads a class name out of a Mustache or a JS file and asks
 * whether it resolves, or whether it is legible once it does. So those rules
 * fail silently with CI fully green — which is how a badge shipped at 1.05:1
 * here: Bootstrap 5's .badge defaults to white text, and "bg-light" with no
 * text utility put white on #f8f9fa.
 *
 * This plugin supports 5.1 and 5.2 only, so it needs none of the Bootstrap 4
 * polyfill machinery a 4.5-supporting plugin carries. What it does need is the
 * half of the contract that binds on 5.x too.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class bootstrap_compat_test extends \basic_testcase {
    /**
     * Background utilities that need an explicit text colour on a badge.
     *
     * Bootstrap 5's .badge sets color: #fff, so a LIGHT background renders
     * white on near-white; Bootstrap 4's set no colour at all, so a SATURATED
     * background rendered near-black on a dark fill. Measured against the
     * compiled Boost sheet on the running 5.2 stack: bg-light (#f8f9fa) with
     * the default badge colour is 1.05:1, against the 4.5:1 AA floor, and
     * 15.37:1 once text-dark is stated.
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
     * Every badge background states its own text colour.
     *
     * Checked on every line carrying a background utility, never only on lines
     * that also say "badge": the word is as likely to sit one line up in a
     * method name as on the markup itself. Two exemptions, both because the
     * element provably carries no text of its own — the text-bg-* utilities,
     * which set the pair together, and progress bar fills.
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
                    if (!preg_match('/\btext-(white|dark|body|muted)\b/', $line)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' needs ' . $required;
                    }
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 5 defaults .badge text to white, so a badge that does not state its own '
                . 'colour fails contrast on a light background: ' . implode('; ', $offenders)
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
     * Moodle 5.2 ships theme/boost/scss/design-system with $mds-* tokens and
     * 5.3 brings MDS React; declaring those names squats a namespace core is
     * actively expanding.
     */
    public function test_no_mds_namespace(): void {
        $css = file_get_contents(dirname(__DIR__, 2) . '/styles.css');
        $this->assertSame(
            0,
            preg_match_all('/--mds-[a-z0-9-]+\s*:/', $css),
            'Custom properties must carry the plugin\'s own frankenstyle prefix, not core\'s --mds-*'
        );
    }
}

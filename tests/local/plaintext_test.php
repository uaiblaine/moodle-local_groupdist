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
 * Tests for the plain spelling of admin- and user-set strings.
 *
 * PHPUnit metadata is written as docblock tags, never attributes: Moodle 4.5
 * runs PHPUnit 9, which reads only the tags, and its moodle-cs reports every
 * test of a class without a covers tag.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_groupdist\local\plaintext
 */
final class plaintext_test extends \advanced_testcase {
    /**
     * Stored strings, each under both values of formatstringstriptags.
     *
     * The fixtures are a bare ampersand, a bare "<" followed by a non-space and
     * a bare ">": the characters the two format_string() paths spell
     * differently. A tag-shaped fixture would not show the difference.
     *
     * @return array Data sets: setting value, stored string, expected result.
     */
    public static function spelling_provider(): array {
        return [
            'ampersand, setting on' => [1, 'Turma A & B', 'Turma A & B'],
            'ampersand, setting off' => [0, 'Turma A & B', 'Turma A & B'],
            'bare less-than, setting on' => [1, 'Turno <3 anos', 'Turno '],
            'bare less-than, setting off' => [0, 'Turno <3 anos', 'Turno '],
            'bare greater-than, setting on' => [1, '> Alpha', '> Alpha'],
            'bare greater-than, setting off' => [0, '> Alpha', '> Alpha'],
        ];
    }

    /**
     * The result reads the same whatever formatstringstriptags says, and is a
     * value PARAM_TEXT leaves unchanged.
     *
     * With the setting on, the result is exactly what format_string() returns
     * unescaped, so no sink sees a change on a default site. With it off, a
     * precondition proves format_string() alone still holds an entity: without
     * it this data set would pass with the helper doing nothing.
     *
     * @dataProvider spelling_provider
     * @param int $striptags The formatstringstriptags value.
     * @param string $stored The stored string.
     * @param string $expected The plain spelling.
     * @return void
     */
    public function test_the_plain_spelling_does_not_depend_on_the_setting(
        int $striptags,
        string $stored,
        string $expected
    ): void {
        $this->resetAfterTest();
        set_config('formatstringstriptags', $striptags);
        $context = \core\context\system::instance();

        $unescaped = format_string($stored, true, ['context' => $context, 'escape' => false]);
        $result = plaintext::format($stored, $context);

        $this->assertSame($expected, $result);
        $this->assertSame($result, clean_param($result, PARAM_TEXT), 'PARAM_TEXT would change the result.');
        if ($striptags) {
            $this->assertSame($unescaped, $result, 'The helper changed the output of a default site.');
        } else {
            $this->assertStringContainsString('&', $unescaped, 'format_string() returned no entity to decode.');
        }
    }
}

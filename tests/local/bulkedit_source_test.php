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
 * Pins the bulk edit save's handling of edits made while a save is running.
 *
 * The save sends a snapshot of the dirty cells in chunks and leaves the editors
 * live. A cell edited again while its chunk is in flight must stay dirty, or the
 * newer value is silently dropped from the next save. That is browser behaviour
 * PHPUnit cannot run, so this reads amd/src/bulkedit.js for the three parts of
 * the rule, and the build map for proof that the served module is that source.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class bulkedit_source_test extends \basic_testcase {
    /**
     * The module source, read once.
     *
     * @return string
     */
    private function source(): string {
        return file_get_contents(dirname(__DIR__, 2) . '/amd/src/bulkedit.js');
    }

    /**
     * The body of one top-level `const name = (...) => {...};` function.
     *
     * @param string $name The function name.
     * @return string The text from the declaration to the closing line.
     */
    private function function_body(string $name): string {
        $this->assertSame(
            1,
            preg_match('/^const ' . preg_quote($name, '/') . ' = .*?^};$/ms', $this->source(), $match),
            "function {$name} not found in amd/src/bulkedit.js"
        );
        return $match[0];
    }

    /**
     * A response clears a cell from the dirty set only when the cell still holds the value sent.
     *
     * Clearing every saved key unconditionally is the defect: the reader's newer
     * value would no longer be dirty and the next save would skip it. Refusals
     * are filtered the same way, so a refusal of the old value is not shown on
     * the new one.
     *
     * Changes that must make it fail: clear every saved key; mark every refused key.
     *
     * @return void
     */
    public function test_a_response_keeps_a_cell_edited_during_the_save_dirty(): void {
        $body = $this->function_body('applyResponse');

        $this->assertMatchesRegularExpression(
            '/const unchanged = \(item\) => state\.dirty\.get\(keyOf\(item\)\) === sent\.get\(keyOf\(item\)\);/',
            $body
        );
        $this->assertStringContainsString('response.saved.filter(unchanged).forEach(', $body);
        $this->assertStringContainsString('response.errors.filter(unchanged).forEach(', $body);
        // The values compared are the ones the request carried, not the server's echo.
        $this->assertMatchesRegularExpression('/const sent = new Map\(sentchanges\.map\(/', $body);
    }

    /**
     * A second save cannot start while one is running, and the button says so.
     *
     * Two overlapping saves would each send a snapshot of the same dirty cells,
     * and the first response would decide for both.
     *
     * Changes that must make it fail: drop the early return; drop `state.saving` from the button state.
     *
     * @return void
     */
    public function test_a_save_cannot_start_while_one_is_running(): void {
        $this->assertMatchesRegularExpression(
            '/if \(state\.saving\) \{\s*return;\s*\}\s*state\.saving = true;/',
            $this->function_body('save')
        );
        $this->assertStringContainsString(
            'save.disabled = state.saving || state.dirty.size === 0;',
            $this->function_body('refreshChrome')
        );
    }

    /**
     * The module the browser is served was built from the committed source.
     *
     * Moodle serves amd/build, never amd/src, so a source fix that was never
     * rebuilt reaches nobody, and every test above would still pass.
     *
     * @return void
     */
    public function test_the_built_module_is_the_committed_source(): void {
        $map = json_decode(file_get_contents(dirname(__DIR__, 2) . '/amd/build/bulkedit.min.js.map'), true);

        $this->assertSame(['../src/bulkedit.js'], $map['sources']);
        $this->assertSame($this->source(), $map['sourcesContent'][0]);
    }
}

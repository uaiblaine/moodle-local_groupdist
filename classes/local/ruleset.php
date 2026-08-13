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
 * Ordered affinity ruleset value object.
 *
 * A ruleset is a flat, ordered list of rules; every rule always applies (the
 * rules are summed, an implicit AND) and the list position is the priority:
 * when rules conflict, or a cluster must be split for capacity, the rule
 * closest to the top prevails and violations are charged to the rule further
 * down. There is deliberately no OR and no nesting — boolean composition is
 * reserved for per-rule scope matchers in a future schema version, which is
 * why entries carrying structural keys such as an operator are rejected here:
 * modes must never appear inside a tree node.
 *
 * Pure and DB-free by design (the guardrail maximum comes in as a parameter),
 * so it stays testable as a basic_testcase.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ruleset {
    /** @var int Schema version emitted by to_json() — the upgrade hinge for richer shapes. */
    public const VERSION = 1;

    /** @var int Guardrail maximum when no site setting overrides it. */
    public const DEFAULT_MAX_RULES = 10;

    /** @var string Source kind: native user table column. */
    public const KIND_NATIVE = 'native';

    /** @var string Source kind: custom profile field (profile_<id>). */
    public const KIND_PROFILE = 'profile';

    /** @var string Source kind: cohort membership (cohort_<id>). */
    public const KIND_COHORT = 'cohort';

    /** @var array Ordered list of rules ('source' and 'mode' keys); position = priority. */
    private array $rules = [];

    /**
     * Build a ruleset from a plain list of rule entries.
     *
     * Accepts arrays or stdClass entries (adhoc task customdata arrives
     * json-decoded as objects). Each entry must carry exactly the keys
     * 'source' and 'mode' — any other key, notably an operator, is rejected
     * so that ill-defined boolean shapes are unrepresentable.
     *
     * @param array $rules List of rule entries in priority order.
     * @param int $maxrules Guardrail maximum accepted; the caller resolves it
     *   (site setting or DEFAULT_MAX_RULES) so this class stays DB-free.
     * @return self The validated ruleset.
     * @throws \moodle_exception When the shape, a source, a mode, a duplicate
     *   or the count is invalid.
     */
    public static function from_array(array $rules, int $maxrules = self::DEFAULT_MAX_RULES): self {
        if (count($rules) > max(1, $maxrules)) {
            throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: too many rules');
        }

        $ruleset = new self();
        $seen = [];
        foreach (array_values($rules) as $entry) {
            $entry = (array) $entry;
            $extra = array_diff(array_keys($entry), ['source', 'mode']);
            if ($extra || !isset($entry['source']) || !isset($entry['mode'])) {
                throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: bad rule shape');
            }
            $source = (string) $entry['source'];
            $mode = (string) $entry['mode'];
            if (self::source_kind($source) === '') {
                throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: bad source');
            }
            if (!in_array($mode, [options::AFFINITY_TOGETHER, options::AFFINITY_APART], true)) {
                throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: bad mode');
            }
            if (isset($seen[$source])) {
                throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: duplicate source');
            }
            $seen[$source] = true;
            $ruleset->rules[] = ['source' => $source, 'mode' => $mode];
        }
        return $ruleset;
    }

    /**
     * Build a ruleset from the versioned JSON envelope.
     *
     * @param string $json The envelope, as produced by to_json().
     * @param int $maxrules Guardrail maximum accepted.
     * @return self The validated ruleset.
     * @throws \moodle_exception When the JSON or its version is invalid.
     */
    public static function from_json(string $json, int $maxrules = self::DEFAULT_MAX_RULES): self {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || (int) ($decoded['v'] ?? 0) !== self::VERSION || !is_array($decoded['rules'] ?? null)) {
            throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: bad envelope');
        }
        return self::from_array($decoded['rules'], $maxrules);
    }

    /**
     * The rules as a plain list, the canonical transport shape.
     *
     * @return array List of rules ('source' and 'mode' keys) in priority order.
     */
    public function to_array(): array {
        return $this->rules;
    }

    /**
     * The versioned JSON envelope, the canonical storage shape.
     *
     * @return string JSON with 'v' and 'rules' keys.
     */
    public function to_json(): string {
        return json_encode(['v' => self::VERSION, 'rules' => $this->rules]);
    }

    /**
     * The rules in priority order.
     *
     * @return array List of rules ('source' and 'mode' keys).
     */
    public function get_rules(): array {
        return $this->rules;
    }

    /**
     * Number of rules.
     *
     * @return int The count.
     */
    public function count(): int {
        return count($this->rules);
    }

    /**
     * Whether the ruleset has no rules.
     *
     * @return bool True when empty.
     */
    public function is_empty(): bool {
        return $this->rules === [];
    }

    /**
     * The highest-priority rule.
     *
     * @return array|null The first rule ('source' and 'mode' keys), or null.
     */
    public function first(): ?array {
        return $this->rules[0] ?? null;
    }

    /**
     * Classify a source key.
     *
     * @param string $source The source key.
     * @return string One of the KIND_* constants, or '' when invalid.
     */
    public static function source_kind(string $source): string {
        if (in_array($source, options::NATIVE_AFFINITY_FIELDS, true)) {
            return self::KIND_NATIVE;
        }
        if (preg_match('/^profile_\d+$/', $source)) {
            return self::KIND_PROFILE;
        }
        if (preg_match('/^cohort_\d+$/', $source)) {
            return self::KIND_COHORT;
        }
        return '';
    }

    /**
     * The custom profile field id of a profile source.
     *
     * @param string $source The source key.
     * @return int The field id, or 0 when the source is not a profile field.
     */
    public static function source_profile_fieldid(string $source): int {
        if (preg_match('/^profile_(\d+)$/', $source, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * The cohort id of a cohort source.
     *
     * @param string $source The source key.
     * @return int The cohort id, or 0 when the source is not a cohort.
     */
    public static function source_cohortid(string $source): int {
        if (preg_match('/^cohort_(\d+)$/', $source, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}

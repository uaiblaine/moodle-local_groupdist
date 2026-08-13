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
 * Value object holding one distribution request's options.
 *
 * The same canonical array shape round-trips through the options form, the
 * preview web service, the apply POST and the adhoc task customdata, so the
 * deterministic recompute (seed included) sees identical inputs everywhere.
 * Affinity travels as an ordered {@see ruleset}; in the POST paths its rules
 * are flattened into the parallel scalar arrays affinityrulesources[] and
 * affinityrulemodes[] (see rules_from_post()).
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class options {
    /** @var string Allocation order: seeded shuffle. */
    public const ALLOCATE_RANDOM = 'random';

    /** @var string Allocation order: firstname, lastname. */
    public const ALLOCATE_FIRSTNAME = 'firstname';

    /** @var string Allocation order: lastname, firstname. */
    public const ALLOCATE_LASTNAME = 'lastname';

    /** @var string Allocation order: ID number. */
    public const ALLOCATE_IDNUMBER = 'idnumber';

    /** @var string Affinity strategy: same field value lands in the same group. */
    public const AFFINITY_TOGETHER = 'together';

    /** @var string Affinity strategy: avoid repeating a field value inside a group. */
    public const AFFINITY_APART = 'apart';

    /** @var string[] Native user table columns offered as affinity sources. */
    public const NATIVE_AFFINITY_FIELDS = ['city', 'department', 'institution', 'country'];

    /** @var int Course id. */
    public int $courseid = 0;

    /** @var array Target group ids, in display order. */
    public array $groupids = [];

    /** @var int Role id filter (0 = any role). */
    public int $roleid = 0;

    /** @var int Cohort id filter (0 = any cohort). */
    public int $cohortid = 0;

    /** @var string Allocation order, one of the ALLOCATE_* constants. */
    public string $allocateby = self::ALLOCATE_RANDOM;

    /** @var bool Skip users already member of one of the selected groups. */
    public bool $ignoregrouped = true;

    /** @var bool Include only active enrolments (forced on without viewsuspendedusers). */
    public bool $onlyactive = true;

    /** @var bool Also include active enrolments whose start date lies in the future. */
    public bool $includefuture = false;

    /** @var ruleset Ordered affinity rules; position = priority. */
    public ruleset $affinityrules;

    /** @var bool Respect the groups' seats custom field as capacity. */
    public bool $useseats = true;

    /** @var int Extra members allowed beyond the seats value, per group. */
    public int $overbook = 0;

    /** @var int Seed making the random order reproducible between preview and apply. */
    public int $seed = 0;

    /**
     * Constructor: starts with an empty ruleset.
     */
    public function __construct() {
        $this->affinityrules = ruleset::from_array([]);
    }

    /**
     * Build an options object from a canonical array, validating enumerations.
     *
     * @param array $data Values keyed by property name; groupids may be an array
     *   of ints or a comma-separated string, affinityrules a list of entries
     *   each carrying 'source' and 'mode' (arrays or stdClass).
     * @return self The validated options.
     * @throws \moodle_exception When an enumerated value is out of range.
     */
    public static function from_array(array $data): self {
        $options = new self();
        $options->courseid = (int) ($data['courseid'] ?? 0);

        $groupids = $data['groupids'] ?? [];
        if (is_string($groupids)) {
            $groupids = explode(',', $groupids);
        }
        $options->groupids = array_values(array_filter(array_map('intval', $groupids)));

        $options->roleid = (int) ($data['roleid'] ?? 0);
        $options->cohortid = (int) ($data['cohortid'] ?? 0);
        $options->allocateby = (string) ($data['allocateby'] ?? self::ALLOCATE_RANDOM);
        $options->ignoregrouped = !empty($data['ignoregrouped']);
        $options->onlyactive = !empty($data['onlyactive']);
        $options->includefuture = !empty($data['includefuture']);
        $rawrules = (array) ($data['affinityrules'] ?? []);
        // The site-setting lookup only happens for multi-rule input, so the
        // single-rule paths (and the pure basic_testcase suites) stay DB-free.
        $maxrules = (count($rawrules) > 1) ? self::max_affinity_rules() : ruleset::DEFAULT_MAX_RULES;
        $options->affinityrules = ruleset::from_array($rawrules, $maxrules);
        $options->useseats = !empty($data['useseats']);
        $options->overbook = max(0, (int) ($data['overbook'] ?? 0));
        $options->seed = (int) ($data['seed'] ?? 0);

        $allocations = [
            self::ALLOCATE_RANDOM,
            self::ALLOCATE_FIRSTNAME,
            self::ALLOCATE_LASTNAME,
            self::ALLOCATE_IDNUMBER,
        ];
        if (!in_array($options->allocateby, $allocations, true)) {
            throw new \moodle_exception('invalidparameter', 'debug', '', null, 'allocateby');
        }
        if ($options->affinityrules->count() > 1) {
            /* Transitional: the deterministic engine still consumes a single
               rule; rejecting extra rules is honest, silently ignoring them is
               not. The multi-rule allocator lifts this in the next phase. */
            throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityrules: engine limit');
        }
        return $options;
    }

    /**
     * Export the canonical array shape (groupids as comma-separated string).
     *
     * @return array The canonical representation.
     */
    public function to_array(): array {
        return [
            'courseid' => $this->courseid,
            'groupids' => implode(',', $this->groupids),
            'roleid' => $this->roleid,
            'cohortid' => $this->cohortid,
            'allocateby' => $this->allocateby,
            'ignoregrouped' => (int) $this->ignoregrouped,
            'onlyactive' => (int) $this->onlyactive,
            'includefuture' => (int) $this->includefuture,
            'affinityrules' => $this->affinityrules->to_array(),
            'useseats' => (int) $this->useseats,
            'overbook' => $this->overbook,
            'seed' => $this->seed,
        ];
    }

    /**
     * Read affinity rules from the flattened POST transport.
     *
     * The preview/apply round trip carries the ruleset as two parallel scalar
     * arrays (honest PARAM types, no JSON blob): affinityrulesources[i] and
     * affinityrulemodes[i]. Shared indexes pair them back up.
     *
     * @return array List of rule entries for from_array().
     */
    public static function rules_from_post(): array {
        $sources = optional_param_array('affinityrulesources', [], PARAM_ALPHANUMEXT);
        $modes = optional_param_array('affinityrulemodes', [], PARAM_ALPHA);
        $rules = [];
        foreach ($sources as $key => $source) {
            $rules[] = [
                'source' => (string) $source,
                'mode' => (string) ($modes[$key] ?? self::AFFINITY_TOGETHER),
            ];
        }
        return $rules;
    }

    /**
     * The source key of the highest-priority rule.
     *
     * The engine consumes one rule until the multi-rule allocator lands.
     *
     * @return string The source key, or '' when no rule is set.
     */
    public function get_affinity_source(): string {
        $first = $this->affinityrules->first();
        return $first['source'] ?? '';
    }

    /**
     * The mode of the highest-priority rule.
     *
     * @return string One of the AFFINITY_* constants (together when no rule).
     */
    public function get_affinity_mode(): string {
        $first = $this->affinityrules->first();
        return $first['mode'] ?? self::AFFINITY_TOGETHER;
    }

    /**
     * The custom profile field id when the first rule's source is a custom field.
     *
     * @return int The field id, or 0 for none/native/cohort.
     */
    public function get_custom_affinity_fieldid(): int {
        return ruleset::source_profile_fieldid($this->get_affinity_source());
    }

    /**
     * Whether the first rule's source is a native user table column.
     *
     * @return bool True for a native column.
     */
    public function is_native_affinity(): bool {
        return in_array($this->get_affinity_source(), self::NATIVE_AFFINITY_FIELDS, true);
    }

    /**
     * The effective ruleset guardrail (site setting, or the class default).
     *
     * @return int Maximum accepted number of rules.
     */
    private static function max_affinity_rules(): int {
        $max = (int) get_config('local_groupdist', 'maxaffinityrules');
        return ($max > 0) ? $max : ruleset::DEFAULT_MAX_RULES;
    }
}

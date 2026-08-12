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

    /** @var string[] Native user table columns offered as affinity fields. */
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

    /** @var string Affinity field: '' (none), a NATIVE_AFFINITY_FIELDS entry, or profile_<id>. */
    public string $affinityfield = '';

    /** @var string Affinity strategy, one of the AFFINITY_* constants. */
    public string $affinitymode = self::AFFINITY_TOGETHER;

    /** @var bool Respect the groups' seats custom field as capacity. */
    public bool $useseats = true;

    /** @var int Extra members allowed beyond the seats value, per group. */
    public int $overbook = 0;

    /** @var int Seed making the random order reproducible between preview and apply. */
    public int $seed = 0;

    /**
     * Build an options object from a canonical array, validating enumerations.
     *
     * @param array $data Values keyed by property name; groupids may be an array
     *   of ints or a comma-separated string.
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
        $options->affinityfield = (string) ($data['affinityfield'] ?? '');
        $options->affinitymode = (string) ($data['affinitymode'] ?? self::AFFINITY_TOGETHER);
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
        if (!in_array($options->affinitymode, [self::AFFINITY_TOGETHER, self::AFFINITY_APART], true)) {
            throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinitymode');
        }
        $isnative = in_array($options->affinityfield, self::NATIVE_AFFINITY_FIELDS, true);
        $iscustom = (bool) preg_match('/^profile_\d+$/', $options->affinityfield);
        if ($options->affinityfield !== '' && !$isnative && !$iscustom) {
            throw new \moodle_exception('invalidparameter', 'debug', '', null, 'affinityfield');
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
            'affinityfield' => $this->affinityfield,
            'affinitymode' => $this->affinitymode,
            'useseats' => (int) $this->useseats,
            'overbook' => $this->overbook,
            'seed' => $this->seed,
        ];
    }

    /**
     * The custom profile field id when the affinity field is a custom one.
     *
     * @return int The field id, or 0 for none/native.
     */
    public function get_custom_affinity_fieldid(): int {
        if (preg_match('/^profile_(\d+)$/', $this->affinityfield, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Whether the affinity field is a native user table column.
     *
     * @return bool True for a native column.
     */
    public function is_native_affinity(): bool {
        return in_array($this->affinityfield, self::NATIVE_AFFINITY_FIELDS, true);
    }
}

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

namespace local_groupdist\output;

use local_groupdist\local\options;
use local_groupdist\local\profilefields;

/**
 * Preview page (step 2): options recap + client-rendered distribution sample.
 *
 * All numbers and group cards are rendered client-side from the preview web
 * service — one rendering path, so the WS return structure is the single
 * source of truth for what appears.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_page implements \renderable, \templatable {
    /** @var options The validated distribution options. */
    protected options $options;

    /** @var \core\context\course The course context. */
    protected \core\context\course $context;

    /** @var array Role menu (id => localised name) used to echo the chosen role. */
    protected array $rolenames;

    /**
     * Constructor.
     *
     * @param options $options The validated options.
     * @param \core\context\course $context The course context.
     * @param array $rolenames Role menu (id => localised name).
     */
    public function __construct(options $options, \core\context\course $context, array $rolenames) {
        $this->options = $options;
        $this->context = $context;
        $this->rolenames = $rolenames;
    }

    /**
     * Export the template context.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        $options = $this->options;

        $memberchips = [];
        $memberchips[] = ['text' => ($options->roleid && isset($this->rolenames[$options->roleid]))
            ? get_string('recaprole', 'local_groupdist', $this->rolenames[$options->roleid])
            : get_string('all')];
        if ($options->cohortid) {
            $cohort = cohort_get_cohort($options->cohortid, $this->context);
            if ($cohort) {
                $memberchips[] = ['text' => get_string('recapcohort', 'local_groupdist', format_string($cohort->name))];
            }
        }
        if ($options->onlyactive) {
            $memberchips[] = ['text' => get_string('includeonlyactiveenrol', 'group'), 'on' => true];
        }
        if ($options->ignoregrouped) {
            $memberchips[] = ['text' => get_string('ignoregrouped', 'local_groupdist'), 'on' => true];
        }

        $allocatestrings = [
            options::ALLOCATE_RANDOM => get_string('random', 'group'),
            options::ALLOCATE_FIRSTNAME => get_string('byfirstname', 'group'),
            options::ALLOCATE_LASTNAME => get_string('bylastname', 'group'),
            options::ALLOCATE_IDNUMBER => get_string('byidnumber', 'group'),
        ];
        $allocationchips = [['text' => $allocatestrings[$options->allocateby]]];

        $affinitychips = [];
        if ($options->affinityfield !== '') {
            $label = profilefields::get_label($options->affinityfield, $this->context);
            $modestring = ($options->affinitymode === options::AFFINITY_TOGETHER)
                ? get_string('affinitymodetogether', 'local_groupdist')
                : get_string('affinitymodeapart', 'local_groupdist');
            $affinitychips[] = ['text' => get_string('recapaffinity', 'local_groupdist', $label)];
            $affinitychips[] = ['text' => $modestring];
        } else {
            $affinitychips[] = ['text' => get_string('affinitynone', 'local_groupdist')];
        }

        $seatschips = [];
        if ($options->useseats) {
            $seatschips[] = ['text' => get_string('useseats', 'local_groupdist'), 'on' => true];
            if ($options->overbook > 0) {
                $seatschips[] = ['text' => get_string('recapoverbook', 'local_groupdist', $options->overbook)];
            }
        } else {
            $seatschips[] = ['text' => get_string('seatsignored', 'local_groupdist')];
        }

        $recap = [
            ['label' => get_string('groupmembers', 'group'), 'items' => $memberchips],
            ['label' => get_string('allocateby', 'group'), 'items' => $allocationchips],
            ['label' => get_string('affinitysection', 'local_groupdist'), 'items' => $affinitychips],
            ['label' => get_string('seatssection', 'local_groupdist'), 'items' => $seatschips],
        ];

        return [
            'recap' => $recap,
            'groupcount' => count($options->groupids),
            'optionsjson' => json_encode($options->to_array()),
        ];
    }
}

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

namespace local_groupdist\task;

use local_groupdist\local\applier;
use local_groupdist\local\distribution;
use local_groupdist\local\options;
use local_groupdist\local\runlog;

/**
 * Adhoc task applying a large distribution in the background.
 *
 * Runs as the teacher who queued it (set_userid at queue time), so the
 * capability-dependent parts of the recompute — suspended enrolment
 * visibility, group membership prechecks — behave exactly as in the preview.
 *
 * @package    local_groupdist
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_distribution extends \core\task\adhoc_task {
    use \core\task\stored_progress_task_trait;

    /**
     * Factory keeping the customdata shape in one place.
     *
     * @param options $options The validated distribution options.
     * @param string $fingerprint The fingerprint the teacher previewed.
     * @param int $runid The audit run created at queue time; a retried task
     *   keeps writing into the same snapshot.
     * @return self The task, ready to queue.
     */
    public static function create(options $options, string $fingerprint, int $runid): self {
        $task = new self();
        $task->set_custom_data([
            'options' => $options->to_array(),
            'fingerprint' => $fingerprint,
            'runid' => $runid,
        ]);
        return $task;
    }

    /**
     * Localised task name (shown by the task manager UI and the indicator).
     *
     * @return string The name.
     */
    public function get_name(): string {
        return get_string('task_apply_distribution', 'local_groupdist');
    }

    /**
     * Recompute the distribution, re-verify the fingerprint, write memberships.
     *
     * A fingerprint mismatch is permanent (the course changed since the
     * preview), so it must NOT throw — a throwing adhoc task is retried and
     * would keep failing forever. It logs and ends; the teacher re-runs the flow.
     *
     * @return void
     */
    public function execute(): void {
        $this->start_stored_progress();

        $data = (array) $this->get_custom_data();
        $options = options::from_array((array) $data['options']);
        $runid = (int) ($data['runid'] ?? 0);
        $context = \core\context\course::instance($options->courseid);

        $distribution = distribution::build($options, $context);
        if ($distribution->fingerprint !== $data['fingerprint']) {
            mtrace('local_groupdist: fingerprint mismatch — enrolments or groups changed since the preview. '
                . 'Nothing was written; the distribution must be previewed again.');
            runlog::abort($runid);
            $this->notify_owner(
                $options->courseid,
                get_string('applymessagestale', 'local_groupdist'),
                get_string('applymessagestalebody', 'local_groupdist')
            );
            return;
        }

        $summary = applier::apply($distribution, $this->get_progress(), $runid);
        runlog::complete($runid, $summary);
        mtrace("local_groupdist: applied distribution to course {$options->courseid}: "
            . "{$summary['added']} memberships written, {$summary['failed']} rejected.");
        $this->notify_owner(
            $options->courseid,
            get_string('applymessagesuccess', 'local_groupdist'),
            get_string('applymessagesuccessbody', 'local_groupdist', (object) [
                'added' => $summary['added'],
                'groups' => count($options->groupids),
            ])
        );
    }

    /**
     * Send the run outcome to the teacher who queued the task.
     *
     * The staleness abort is otherwise invisible (the status page cannot tell
     * a finished run from an aborted one), so both outcomes are messaged.
     *
     * @param int $courseid The course id.
     * @param string $subject Message subject.
     * @param string $body Message body (plain text).
     * @return void
     */
    private function notify_owner(int $courseid, string $subject, string $body): void {
        $message = new \core\message\message();
        $message->component = 'local_groupdist';
        $message->name = 'applyresult';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $this->get_userid();
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/group/index.php', ['id' => $courseid]))->out(false);
        $message->contexturlname = get_string('groups', 'group');
        message_send($message);
    }

    /**
     * Find a pending distribution task for a course, if any.
     *
     * @param int $courseid The course id.
     * @return int The adhoc task id, or 0 when none is queued.
     */
    public static function get_taskid_for_course(int $courseid): int {
        global $DB;

        $records = $DB->get_records('task_adhoc', ['classname' => '\\' . self::class], 'id ASC', 'id, customdata');
        foreach ($records as $record) {
            $customdata = json_decode($record->customdata ?? '');
            if ((int) ($customdata->options->courseid ?? 0) === $courseid) {
                return (int) $record->id;
            }
        }
        return 0;
    }

    /**
     * Load a task instance from its adhoc task id.
     *
     * @param int $taskid The adhoc task id.
     * @return self|null The task, or null when it no longer exists (finished).
     */
    public static function load(int $taskid): ?self {
        global $DB;

        $record = $DB->get_record('task_adhoc', ['id' => $taskid, 'classname' => '\\' . self::class]);
        if (!$record) {
            return null;
        }
        $task = \core\task\manager::adhoc_task_from_record($record);
        return ($task instanceof self) ? $task : null;
    }
}

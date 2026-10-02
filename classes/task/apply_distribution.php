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
 * Runs as the teacher who queued it (set_userid() at queue time), so the
 * capability-dependent parts behave as they did for that teacher: the
 * recompute's suspended-enrolment filter, which the fingerprint check depends
 * on, and the visibility-filtered groups_is_member() check inside
 * groups_add_member().
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
     * preview), so it must not throw: a throwing adhoc task is retried and
     * would fail the same way on every attempt. Instead it aborts the run and
     * messages the teacher, who previews again.
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
            // Non-zero only when an earlier attempt of this task wrote some members before dying.
            $kept = runlog::abort($runid);
            mtrace('local_groupdist: fingerprint mismatch — enrolments or groups changed since the preview. '
                . "Nothing more was written ({$kept} memberships from an earlier attempt were kept); "
                . 'the distribution must be previewed again.');
            $body = $kept
                ? get_string('applymessagestalepartialbody', 'local_groupdist', $kept)
                : get_string('applymessagestalebody', 'local_groupdist');
            $this->notify_owner($options->courseid, get_string('applymessagestale', 'local_groupdist'), $body);
            return;
        }

        $summary = applier::apply($distribution, $this->progress_reporter(), $runid);
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
     * Record the task's progress bar as pending, right after queueing it.
     *
     * Where core supports a pending bar (stored_progress_task_trait's
     * initialise_stored_progress()), the status page shows it before the task
     * starts. Moodle 4.5 has no pending state: the bar's row is created when
     * the task starts, and status.php shows only the message until then.
     *
     * @return void
     */
    public function initialise_progress(): void {
        if (method_exists($this, 'initialise_stored_progress')) {
            $this->initialise_stored_progress();
        }
    }

    /**
     * The progress consumer that moves this task's stored progress bar.
     *
     * Core's own (stored_progress_task_trait::get_progress()) where it exists;
     * Moodle 4.5 has neither it nor core\progress\stored, so there the plugin's
     * equivalent wraps the bar start_stored_progress() created.
     *
     * @return \core\progress\base The progress consumer.
     */
    private function progress_reporter(): \core\progress\base {
        if (method_exists($this, 'get_progress')) {
            return $this->get_progress();
        }
        return new \local_groupdist\local\stored_progress($this->progress);
    }

    /**
     * Send the run outcome to the teacher who queued the task.
     *
     * The status page reports the outcome only to someone who opens it again
     * after the task has run, so both outcomes are also messaged.
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
     * Find a distribution task for a course that can still run, if any.
     *
     * A task out of attempts is skipped: core never runs it again
     * (\core\task\manager selects only attemptsavailable > 0 or NULL) but keeps
     * its row until the failed-task cleanup, so counting it would refuse every
     * new apply for the course and freeze the status page's progress bar
     * until then.
     *
     * @param int $courseid The course id.
     * @return int The adhoc task id, or 0 when none is queued that can still run.
     */
    public static function get_taskid_for_course(int $courseid): int {
        global $DB;

        $records = $DB->get_records_select(
            'task_adhoc',
            'classname = :classname AND (attemptsavailable > 0 OR attemptsavailable IS NULL)',
            ['classname' => '\\' . self::class],
            'id ASC',
            'id, customdata'
        );
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

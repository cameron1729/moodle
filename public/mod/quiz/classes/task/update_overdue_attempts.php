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

/**
 * Update Overdue Attempts Task
 *
 * @package    mod_quiz
 * @copyright  2017 Michael Hughes
 * @author Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_quiz\task;

use core\task\manager;
use mod_quiz\quiz_attempt;
use moodle_exception;
use moodle_recordset;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Update Overdue Attempts Task
 *
 * @package    mod_quiz
 * @copyright  2017 Michael Hughes
 * @author Michael Hughes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
class update_overdue_attempts extends \core\task\scheduled_task {
    /** @var int Time in seconds to defer rechecking an attempt after queueing. */
    private const EXPIRY_DURATION_SECONDS = 300;

    public function get_name(): string {
        return get_string('updateoverdueattemptstask', 'mod_quiz');
    }

    /**
     * Close off any overdue attempts.
     */
    public function execute() {
        global $DB;

        $timenow = time();
        $processto = $timenow - (int)get_config('quiz', 'graceperiodmin');
        $maxruntime = (int)get_config('quiz', 'overdueattemptsmaxruntime');
        $starttime = microtime(true);
        $expirytime = $timenow + self::EXPIRY_DURATION_SECONDS;
        $queuedcount = 0;
        $scannedcount = 0;
        $runtimelimitreached = false;
        $existingtasks = $this->get_existing_update_tasks();

        mtrace('  Looking for overdue quiz attempts to queue...');

        $attemptstoprocess = $DB->get_recordset_select(
            'quiz_attempts',
            "state IN ('inprogress', 'overdue') AND timecheckstate <= :processto",
            ['processto' => $processto],
            'timecheckstate, id',
            'id, timecheckstate',
        );

        try {
            foreach ($attemptstoprocess as $attempt) {
                if ($this->runtime_limit_reached($starttime, $maxruntime)) {
                    $runtimelimitreached = true;
                    break;
                }

                $scannedcount++;

                $attemptid = (int)$attempt->id;
                $wasqueued = isset($existingtasks[$attemptid]) && !$existingtasks[$attemptid]['exhausted'];
                $wasexhausted = isset($existingtasks[$attemptid]) && $existingtasks[$attemptid]['exhausted'];

                if ($wasqueued) {
                    $this->defer_attempt_recheck($attemptid, $expirytime);
                    continue;
                }

                $task = new update_overdue_attempts_worker();
                $task->set_custom_data((object)['attemptid' => $attemptid]);

                if (manager::queue_adhoc_task($task, true) !== false) {
                    $queuedcount++;
                    $existingtasks[$attemptid] = ['exhausted' => false];
                    $this->defer_attempt_recheck($attemptid, $expirytime);

                    if ($wasexhausted) {
                        mtrace("  Re-queued exhausted update_overdue_attempts_worker for attempt {$attemptid}");
                    } else {
                        mtrace("  Queued update_overdue_attempts_worker for attempt {$attemptid}");
                    }
                } else {
                    // Another process may have queued this in parallel.
                    $existingtasks[$attemptid] = ['exhausted' => false];
                    $this->defer_attempt_recheck($attemptid, $expirytime);
                }
            }
        } finally {
            $attemptstoprocess->close();
        }

        if ($runtimelimitreached && $maxruntime > 0) {
            mtrace("  Reached runtime limit ({$maxruntime}s).");
        }

        mtrace("  Queued {$queuedcount} overdue attempt update tasks after scanning {$scannedcount} attempts.");
    }

    /**
     * Check whether the configured time limit has been reached.
     *
     * @param float $starttime Execution start timestamp from microtime(true).
     * @param int $maxruntime Configured time limit in seconds. 0 means unlimited.
     * @return bool True when execution should stop queueing.
     */
    protected function runtime_limit_reached(float $starttime, int $maxruntime): bool {
        if ($maxruntime <= 0) {
            return false;
        }

        return (microtime(true) - $starttime) >= $maxruntime;
    }

    /**
     * Get currently queued overdue attempt update tasks keyed by attempt ID.
     *
     * @return array Quiz attempt IDs keyed to an array containing an exhausted flag.
     */
    private function get_existing_update_tasks(): array {
        $records = manager::get_adhoc_tasks(update_overdue_attempts_worker::class);

        $tasks = [];
        foreach ($records as $record) {
            $customdata = $record->get_custom_data();
            $attemptid = isset($customdata->attemptid) ? (int)$customdata->attemptid : 0;
            if ($attemptid <= 0) {
                continue;
            }

            $tasks[$attemptid] = [
                'exhausted' => ($record->get_attempts_available() === 0),
            ];
        }

        return $tasks;
    }

    /**
     * Move an attempt's timecheckstate forward to defer immediate rechecks while work is pending.
     *
     * @param int $attemptid Quiz attempt ID.
     * @param int $expirytime Unix timestamp to defer rechecks until.
     */
    private function defer_attempt_recheck(int $attemptid, int $expirytime): void {
        global $DB;

        $sql = "UPDATE {quiz_attempts}
                   SET timecheckstate = :expirytimeset
                 WHERE id = :attemptid
                   AND state IN ('inprogress', 'overdue')
                   AND timecheckstate IS NOT NULL
                   AND timecheckstate < :expirytimecmp";

        $params = [
            'expirytimeset' => $expirytime,
            'expirytimecmp' => $expirytime,
            'attemptid' => $attemptid,
        ];

        $DB->execute($sql, $params);
    }

    /**
     * Do the processing required.
     *
     * @param int $timenow the time to consider as 'now' during the processing.
     * @param int $processto only process attempt with timecheckstate longer ago than this.
     * @return array with two elements, the number of attempt considered, and how many different quizzes that was.
     */
    public function update_all_overdue_attempts(int $timenow, int $processto): array {
        global $DB;

        $attemptstoprocess = $this->get_list_of_overdue_attempts($processto);

        $course = null;
        $quiz = null;
        $cm = null;

        $count = 0;
        $quizcount = 0;
        foreach ($attemptstoprocess as $attemptinfo) {
            try {
                $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptinfo->id], '*', IGNORE_MISSING);

                if (!$attempt) {
                    continue;
                }

                $attempt->usertimeclose = $attemptinfo->usertimeclose;
                $attempt->usertimelimit = $attemptinfo->usertimelimit;

                // If we have moved on to a different quiz, fetch the new data.
                if (!$quiz || $attempt->quiz != $quiz->id) {
                    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
                    $cm = get_coursemodule_from_instance('quiz', $attempt->quiz);
                    $quizcount += 1;
                }

                // If we have moved on to a different course, fetch the new data.
                if (!$course || $course->id != $quiz->course) {
                    $course = get_course($quiz->course);
                }

                // Make a specialised version of the quiz settings, with the relevant overrides.
                $quizforuser = clone($quiz);
                $quizforuser->timeclose = $attempt->usertimeclose;
                $quizforuser->timelimit = $attempt->usertimelimit;

                // Trigger any transitions that are required.
                $attemptobj = new quiz_attempt($attempt, $quizforuser, $cm, $course);
                $attemptobj->handle_if_time_expired($timenow, false);
                $count += 1;

            } catch (moodle_exception $e) {
                // If an error occurs while processing one attempt, don't let that kill cron.
                mtrace("Error while processing attempt $attempt->id at $attempt->quiz quiz:");
                mtrace_exception($e);
                // Close down any currently open transactions, otherwise one error
                // will stop following DB changes from being committed.
                $DB->force_transaction_rollback();
            }
        }

        $attemptstoprocess->close();
        return [$count, $quizcount];
    }

    /**
     * Get a recordset of all the attempts that need to be processed now.
     *
     * (Only public to allow unit testing. Do not use!)
     *
     * @param int $processto timestamp to process up to.
     * @return moodle_recordset of quiz_attempts that need to be processed because time has
     *     passed, sorted by courseid then quizid.
     */
    public function get_list_of_overdue_attempts(int $processto): moodle_recordset {
        global $DB;

        // SQL to compute timeclose and timelimit for each attempt.
        $quizausersql = quiz_get_attempt_usertime_sql(
                "iquiza.state IN ('inprogress', 'overdue') AND iquiza.timecheckstate <= :iprocessto");

        // This query returns the attempt id and the relevant user time information.
        return $DB->get_recordset_sql("
         SELECT quiza.id,
                quiza.quiz,
                quizauser.usertimeclose,
                quizauser.usertimelimit

           FROM {quiz_attempts} quiza
           JOIN {quiz} quiz ON quiz.id = quiza.quiz
           JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id

          WHERE quiza.state IN ('inprogress', 'overdue')
            AND quiza.timecheckstate <= :processto
       ORDER BY quiz.course, quiza.quiz",

                ['processto' => $processto, 'iprocessto' => $processto]);
    }
}

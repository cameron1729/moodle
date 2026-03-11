<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace mod_quiz\task;

use coding_exception;
use core\task\adhoc_task;
use mod_quiz\quiz_attempt;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Adhoc task to process one overdue attempt.
 *
 * @package   mod_quiz
 * @copyright 2026 Monash University
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_overdue_attempts_worker extends adhoc_task {
    #[\Override]
    public function get_name(): string {
        return get_string('updateoverdueattempttask', 'mod_quiz');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $attemptid = filter_var($data->attemptid ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($attemptid === false) {
            mtrace('Cannot process overdue attempt task: missing or invalid attemptid.');
            $this->set_attempts_available(0);
            throw new coding_exception(__METHOD__ . ' requires a valid attemptid in customdata.');
        }
        $attemptid = (int)$attemptid;

        mtrace("Processing update_overdue_attempts_worker for attempt {$attemptid}.");

        // Recheck the latest attempt state and user specific timing data before processing.
        $attempt = $this->get_attempt_to_process($attemptid);
        if (!$attempt) {
            mtrace("Skipping overdue attempt {$attemptid}: attempt not found or no longer in inprogress/overdue state.");
            return;
        }

        $timenow = time();

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', IGNORE_MISSING);
        if (!$quiz) {
            mtrace("Cannot process overdue attempt {$attemptid}: quiz {$attempt->quiz} not found.");
            $this->set_attempts_available(0);
            throw new coding_exception("Cannot process overdue attempt {$attemptid}: quiz {$attempt->quiz} not found.");
        }

        $cm = get_coursemodule_from_instance('quiz', (int)$attempt->quiz, (int)$quiz->course, false, IGNORE_MISSING);
        if (!$cm) {
            mtrace("Cannot process overdue attempt {$attemptid}: course module for quiz {$attempt->quiz} not found.");
            $this->set_attempts_available(0);
            $message = "Cannot process overdue attempt {$attemptid}: " .
                "course module for quiz {$attempt->quiz} not found.";
            throw new coding_exception($message);
        }

        $course = $DB->get_record('course', ['id' => $quiz->course], '*', IGNORE_MISSING);
        if (!$course) {
            mtrace("Cannot process overdue attempt {$attemptid}: course {$quiz->course} not found.");
            $this->set_attempts_available(0);
            throw new coding_exception("Cannot process overdue attempt {$attemptid}: course {$quiz->course} not found.");
        }

        $attempttiming = $this->get_attempt_timing($attemptid);
        if (!$attempttiming) {
            mtrace("Cannot process overdue attempt {$attemptid}: user timing data not found.");
            $this->set_attempts_available(0);
            throw new coding_exception("Cannot process overdue attempt {$attemptid}: user timing data not found.");
        }

        $quizforuser = clone($quiz);
        $quizforuser->timeclose = $attempttiming->usertimeclose;
        $quizforuser->timelimit = $attempttiming->usertimelimit;

        $attemptobj = new quiz_attempt($attempt, $quizforuser, $cm, $course);
        $beforeattempt = clone($attemptobj->get_attempt());
        $attemptobj->handle_if_time_expired($timenow, false);
        $afterattempt = $attemptobj->get_attempt();

        $beforetimecheckstate = is_null($beforeattempt->timecheckstate) ? 'null' : (string)$beforeattempt->timecheckstate;
        $aftertimecheckstate = is_null($afterattempt->timecheckstate) ? 'null' : (string)$afterattempt->timecheckstate;
        mtrace("Processed overdue attempt {$attemptid}: state {$beforeattempt->state} -> {$afterattempt->state}, " .
            "timefinish {$beforeattempt->timefinish} -> {$afterattempt->timefinish}, " .
            "timecheckstate {$beforetimecheckstate} -> {$aftertimecheckstate}.");
    }

    /**
     * Get the attempt if it still requires overdue handling.
     *
     * @param int $attemptid Quiz attempt ID.
     * @return stdClass|null Attempt record if it still requires overdue handling.
     */
    private function get_attempt_to_process(int $attemptid): ?stdClass {
        global $DB;

        $sql = "SELECT quiza.*
                  FROM {quiz_attempts} quiza
                 WHERE quiza.id = :attemptid
                   AND quiza.state IN ('inprogress', 'overdue')";

        $params = [
            'attemptid' => $attemptid,
        ];

        return $DB->get_record_sql($sql, $params) ?: null;
    }

    /**
     * Get user specific timing data for an attempt.
     *
     * @param int $attemptid Quiz attempt ID.
     * @return stdClass|null User timing data for the attempt.
     */
    private function get_attempt_timing(int $attemptid): ?stdClass {
        global $DB;

        $quizausersql = quiz_get_attempt_usertime_sql("iquiza.id = :iattemptid");

        $sql = "SELECT quizauser.usertimeclose, quizauser.usertimelimit
                  FROM ($quizausersql) quizauser
                 WHERE quizauser.id = :attemptid";

        $params = [
            'iattemptid' => $attemptid,
            'attemptid' => $attemptid,
        ];

        return $DB->get_record_sql($sql, $params) ?: null;
    }
}

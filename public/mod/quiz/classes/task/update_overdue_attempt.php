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
use Override;
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
class update_overdue_attempt extends adhoc_task {
    #[Override]
    public function get_name(): string {
        return get_string('updateoverdueattempttask', 'mod_quiz');
    }

    #[Override]
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $attemptid = filter_var($data->attemptid ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $timenow = time();
        $processto = $timenow - (int)get_config('quiz', 'graceperiodmin');

        if ($attemptid === false) {
            $this->set_attempts_available(0);
            throw new coding_exception(__METHOD__ . ' requires a valid attemptid in customdata.');
        }

        // Recheck the latest attempt state and user-specific timing data before processing.
        $attempt = $this->get_attempt_to_process($attemptid, $processto);
        if (!$attempt) {
            return;
        }

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', IGNORE_MISSING);
        if (!$quiz) {
            $this->set_attempts_available(0);
            throw new coding_exception("Cannot process overdue attempt {$attemptid}: quiz {$attempt->quiz} not found.");
        }

        $cm = get_coursemodule_from_instance('quiz', (int)$attempt->quiz, (int)$quiz->course, false, IGNORE_MISSING);
        if (!$cm) {
            $this->set_attempts_available(0);
            $message = "Cannot process overdue attempt {$attemptid}: " .
                "course module for quiz {$attempt->quiz} not found.";
            throw new coding_exception($message);
        }

        $course = $DB->get_record('course', ['id' => $quiz->course], '*', IGNORE_MISSING);
        if (!$course) {
            $this->set_attempts_available(0);
            throw new coding_exception("Cannot process overdue attempt {$attemptid}: course {$quiz->course} not found.");
        }

        $quizforuser = clone($quiz);
        $quizforuser->timeclose = $attempt->usertimeclose;
        $quizforuser->timelimit = $attempt->usertimelimit;

        $attemptobj = new quiz_attempt($attempt, $quizforuser, $cm, $course);
        $attemptobj->handle_if_time_expired($timenow, false);
    }

    /**
     * Get the attempt if it still requires overdue handling.
     *
     * @param int $attemptid
     * @param int $processto timestamp to process up to.
     * @return stdClass|null
     */
    private function get_attempt_to_process(int $attemptid, int $processto): ?stdClass {
        global $DB;

        $quizausersql = quiz_get_attempt_usertime_sql(
            "iquiza.id = :iattemptid
             AND iquiza.state IN ('inprogress', 'overdue')
             AND iquiza.timecheckstate <= :iprocessto",
        );

        $sql = "SELECT quiza.*, quizauser.usertimeclose, quizauser.usertimelimit
                  FROM {quiz_attempts} quiza
                  JOIN ($quizausersql) quizauser ON quizauser.id = quiza.id
                 WHERE quiza.id = :attemptid
                   AND quiza.state IN ('inprogress', 'overdue')
                   AND quiza.timecheckstate <= :processto";

        $params = [
            'iattemptid' => $attemptid,
            'iprocessto' => $processto,
            'attemptid' => $attemptid,
            'processto' => $processto,
        ];

        return $DB->get_record_sql($sql, $params) ?: null;
    }
}

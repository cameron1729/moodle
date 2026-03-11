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

use advanced_testcase;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for update_overdue_attempt task.
 *
 * @package   mod_quiz
 * @copyright 2026 Monash University
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(update_overdue_attempt::class)]
final class update_overdue_attempt_test extends advanced_testcase {
    /**
     * Create a quiz attempt that is due overdue processing.
     *
     * @return int attempt id.
     */
    private function create_due_attempt(): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'overduehandling' => 'autosubmit',
            'timelimit' => 1,
        ]);

        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);

        $DB->update_record('quiz_attempts', (object)[
            'id' => $attempt->id,
            'state' => quiz_attempt::IN_PROGRESS,
            'timestart' => time() - 600,
            'timecheckstate' => time() - 120,
            'timefinish' => 0,
        ]);

        $this->setAdminUser();
        return (int)$attempt->id;
    }

    /**
     * The task should process one due attempt.
     */
    public function test_execute_processes_due_attempt(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('graceperiodmin', 0, 'quiz');

        $attemptid = $this->create_due_attempt();

        $task = new update_overdue_attempt();
        $task->set_custom_data((object)['attemptid' => $attemptid]);
        $task->execute();

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertGreaterThan(0, $attempt->timefinish);
        $this->assertNull($attempt->timecheckstate);
    }

    /**
     * The task should no-op when the attempt is not yet due.
     */
    public function test_execute_skips_attempt_not_due(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $attemptid = $this->create_due_attempt();

        $DB->update_record('quiz_attempts', (object)[
            'id' => $attemptid,
            'timecheckstate' => time() + HOURSECS,
        ]);

        $task = new update_overdue_attempt();
        $task->set_custom_data((object)['attemptid' => $attemptid]);
        $task->execute();

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $this->assertEquals(quiz_attempt::IN_PROGRESS, $attempt->state);
        $this->assertEquals(0, (int)$attempt->timefinish);
        $this->assertGreaterThan(time(), (int)$attempt->timecheckstate);
    }
}

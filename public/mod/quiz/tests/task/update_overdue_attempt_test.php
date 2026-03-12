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
use coding_exception;
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

    /**
     * Invalid custom task data should fail without retries.
     */
    public function test_execute_fails_for_invalid_attemptid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $task = new update_overdue_attempt();
        $task->set_custom_data((object)['attemptid' => 'not-an-int']);

        try {
            $task->execute();
            $this->fail('Expected coding_exception for invalid attemptid custom data.');
        } catch (coding_exception $e) {
            $this->assertStringContainsString('requires a valid attemptid', $e->getMessage());
            $this->assertEquals(0, $task->get_attempts_available());
        }
    }

    /**
     * An attempt referencing a missing quiz should fail without retries.
     */
    public function test_execute_fails_for_missing_quiz(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('graceperiodmin', 0, 'quiz');

        $attemptid = $this->create_due_attempt();
        $DB->set_field('quiz_attempts', 'quiz', 99999999, ['id' => $attemptid]);

        $task = new update_overdue_attempt();
        $task->set_custom_data((object)['attemptid' => $attemptid]);

        try {
            $task->execute();
            $this->fail('Expected coding_exception for missing quiz.');
        } catch (coding_exception $e) {
            $this->assertStringContainsString('quiz', $e->getMessage());
            $this->assertStringContainsString('not found', $e->getMessage());
            $this->assertEquals(0, $task->get_attempts_available());
        }
    }

    /**
     * An attempt with a quiz pointing to a missing course should fail without retries.
     */
    public function test_execute_fails_for_missing_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('graceperiodmin', 0, 'quiz');

        $attemptid = $this->create_due_attempt();
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', (int)$attempt->quiz, 0, false, MUST_EXIST);

        $invalidcourseid = 99999999;
        $DB->set_field('quiz', 'course', $invalidcourseid, ['id' => $attempt->quiz]);
        $DB->set_field('course_modules', 'course', $invalidcourseid, ['id' => $cm->id]);

        $task = new update_overdue_attempt();
        $task->set_custom_data((object)['attemptid' => $attemptid]);

        try {
            $task->execute();
            $this->fail('Expected coding_exception for missing course.');
        } catch (coding_exception $e) {
            $this->assertStringContainsString('course', $e->getMessage());
            $this->assertStringContainsString('not found', $e->getMessage());
            $this->assertEquals(0, $task->get_attempts_available());
        }
    }
}

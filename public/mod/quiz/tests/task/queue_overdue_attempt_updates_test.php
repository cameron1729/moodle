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
use context_module;
use core\task\manager;
use mod_quiz\quiz_attempt;
use PHPUnit\Framework\Attributes\CoversClass;
use question_engine;
use stdClass;

/**
 * Unit tests for queue_overdue_attempt_updates task.
 *
 * @package   mod_quiz
 * @copyright 2026 Monash University
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(queue_overdue_attempt_updates::class)]
final class queue_overdue_attempt_updates_test extends advanced_testcase {
    /**
     * Create a question usage for a test attempt.
     *
     * @param stdClass $quiz quiz record.
     * @return int question usage id.
     */
    private function usage_id(stdClass $quiz): int {
        $quba = question_engine::make_questions_usage_by_activity(
            'mod_quiz',
            context_module::instance($quiz->cmid),
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        question_engine::save_questions_usage_by_activity($quba);
        return $quba->get_id();
    }

    /**
     * Insert an overdue attempt for testing queueing behaviour.
     *
     * @param stdClass $quiz quiz record.
     * @param int $userid user id.
     * @param int $attemptnumber attempt number.
     * @param int $timecheckstate timecheckstate value.
     * @return int attempt id.
     */
    private function create_overdue_attempt(stdClass $quiz, int $userid, int $attemptnumber, int $timecheckstate): int {
        global $DB;

        return (int)$DB->insert_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $userid,
            'attempt' => $attemptnumber,
            'state' => quiz_attempt::IN_PROGRESS,
            'timestart' => $timecheckstate - HOURSECS,
            'timecheckstate' => $timecheckstate,
            'layout' => '',
            'uniqueid' => $this->usage_id($quiz),
        ]);
    }

    /**
     * The task should count only newly queued tasks towards the configured limit.
     */
    public function test_execute_counts_successfully_queued_tasks_towards_limit(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('graceperiodmin', 0, 'quiz');
        set_config('overdueattemptsmaxqueueperrun', 2, 'quiz');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance(['course' => $course->id]);

        $timenow = time();
        $attempt1 = $this->create_overdue_attempt($quiz, $user->id, 1, $timenow - 300);
        $attempt2 = $this->create_overdue_attempt($quiz, $user->id, 2, $timenow - 200);
        $attempt3 = $this->create_overdue_attempt($quiz, $user->id, 3, $timenow - 100);
        $attempt4 = $this->create_overdue_attempt($quiz, $user->id, 4, $timenow - 50);
        $notdueattempt = $this->create_overdue_attempt($quiz, $user->id, 5, $timenow + 300);
        $finishedattempt = $this->create_overdue_attempt($quiz, $user->id, 6, $timenow - 400);
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::FINISHED, ['id' => $finishedattempt]);

        // Pre-queue the first attempt to simulate an already queued task at the front of the backlog.
        $task = new update_overdue_attempt();
        $task->set_custom_data((object)['attemptid' => $attempt1]);
        manager::queue_adhoc_task($task, true);

        $task = new queue_overdue_attempt_updates();
        ob_start();
        $task->execute();
        ob_end_clean();

        $classname = manager::get_canonical_class_name(update_overdue_attempt::class);

        $records = $DB->get_records(
            'task_adhoc',
            ['classname' => $classname],
            'id ASC',
            'id, customdata',
        );
        $this->assertCount(3, $records);

        $queuedattemptids = [];
        foreach ($records as $record) {
            $queuedattemptids[] = (int)json_decode($record->customdata)->attemptid;
        }

        $this->assertCount(3, array_unique($queuedattemptids));
        $this->assertContains($attempt1, $queuedattemptids);
        $this->assertContains($attempt2, $queuedattemptids);
        $this->assertContains($attempt3, $queuedattemptids);
        $this->assertNotContains($attempt4, $queuedattemptids);
        $this->assertNotContains($notdueattempt, $queuedattemptids);
        $this->assertNotContains($finishedattempt, $queuedattemptids);
    }

    /**
     * The task should queue the oldest overdue attempts first.
     */
    public function test_execute_queues_oldest_attempts_first(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('graceperiodmin', 0, 'quiz');
        set_config('overdueattemptsmaxqueueperrun', 2, 'quiz');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance(['course' => $course->id]);

        $timenow = time();
        $latest = $this->create_overdue_attempt($quiz, $user->id, 1, $timenow - 100);
        $oldest = $this->create_overdue_attempt($quiz, $user->id, 2, $timenow - 300);
        $middle = $this->create_overdue_attempt($quiz, $user->id, 3, $timenow - 200);
        $finishedold = $this->create_overdue_attempt($quiz, $user->id, 4, $timenow - 400);
        $notdue = $this->create_overdue_attempt($quiz, $user->id, 5, $timenow + 300);
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::FINISHED, ['id' => $finishedold]);

        $task = new queue_overdue_attempt_updates();
        ob_start();
        $task->execute();
        ob_end_clean();

        $classname = manager::get_canonical_class_name(update_overdue_attempt::class);

        $records = $DB->get_records(
            'task_adhoc',
            ['classname' => $classname],
            'id ASC',
            'id, customdata',
        );

        $this->assertCount(2, $records);
        $queuedattemptids = [];
        foreach ($records as $record) {
            $queuedattemptids[] = (int)json_decode($record->customdata)->attemptid;
        }

        $this->assertEquals([$oldest, $middle], $queuedattemptids);
        $this->assertNotContains($finishedold, $queuedattemptids);
        $this->assertNotContains($latest, $queuedattemptids);
        $this->assertNotContains($notdue, $queuedattemptids);
    }
}

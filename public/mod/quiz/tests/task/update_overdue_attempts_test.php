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
 * Unit tests for update_overdue_attempts task.
 *
 * @package   mod_quiz
 * @copyright 2026 Monash University
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(update_overdue_attempts::class)]
final class update_overdue_attempts_test extends advanced_testcase {
    /**
     * Create a question usage for a test attempt.
     *
     * @param stdClass $quiz Quiz record.
     * @return int Question usage ID.
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
     * @param stdClass $quiz Quiz record.
     * @param int $userid User ID.
     * @param int $attemptnumber Attempt number.
     * @param int $timecheckstate Timecheckstate value.
     * @return int Attempt ID.
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
     * The task should queue all due attempts, including those after prequeued attempts.
     */
    public function test_execute_queues_all_due_attempts_with_prequeued_tasks(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('graceperiodmin', 0, 'quiz');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $userid = (int)$user->id;

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance(['course' => $course->id]);

        $timenow = time();
        $attempt1 = $this->create_overdue_attempt($quiz, $userid, 1, $timenow - 300);
        $attempt2 = $this->create_overdue_attempt($quiz, $userid, 2, $timenow - 200);
        $attempt3 = $this->create_overdue_attempt($quiz, $userid, 3, $timenow - 100);
        $attempt4 = $this->create_overdue_attempt($quiz, $userid, 4, $timenow - 50);
        $notdueattempt = $this->create_overdue_attempt($quiz, $userid, 5, $timenow + 300);
        $finishedattempt = $this->create_overdue_attempt($quiz, $userid, 6, $timenow - 400);
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::FINISHED, ['id' => $finishedattempt]);

        // Prequeue the first attempt to simulate an already queued task at the front of the backlog.
        $task = new update_overdue_attempts_worker();
        $task->set_custom_data((object)['attemptid' => $attempt1]);
        manager::queue_adhoc_task($task, true);

        $task = new update_overdue_attempts();
        $this->expectOutputRegex(
            '/Queued update_overdue_attempts_worker for attempt ' . $attempt2 .
            '.*Queued update_overdue_attempts_worker for attempt ' . $attempt3 .
            '.*Queued update_overdue_attempts_worker for attempt ' . $attempt4 .
            '.*Queued 3 overdue attempt update tasks after scanning 4 attempts\./s',
        );
        $task->execute();

        $classname = manager::get_canonical_class_name(update_overdue_attempts_worker::class);

        $records = $DB->get_records(
            'task_adhoc',
            ['classname' => $classname],
            'id ASC',
            'id, customdata',
        );
        $this->assertCount(4, $records);

        $queuedattemptids = [];
        foreach ($records as $record) {
            $queuedattemptids[] = (int)json_decode($record->customdata)->attemptid;
        }

        $this->assertCount(4, array_unique($queuedattemptids));
        $this->assertContains($attempt1, $queuedattemptids);
        $this->assertContains($attempt2, $queuedattemptids);
        $this->assertContains($attempt3, $queuedattemptids);
        $this->assertContains($attempt4, $queuedattemptids);
        $this->assertNotContains($notdueattempt, $queuedattemptids);
        $this->assertNotContains($finishedattempt, $queuedattemptids);

        $this->assertGreaterThan(time(), (int)$DB->get_field('quiz_attempts', 'timecheckstate', ['id' => $attempt1]));
        $this->assertGreaterThan(time(), (int)$DB->get_field('quiz_attempts', 'timecheckstate', ['id' => $attempt2]));
        $this->assertGreaterThan(time(), (int)$DB->get_field('quiz_attempts', 'timecheckstate', ['id' => $attempt3]));
        $this->assertGreaterThan(time(), (int)$DB->get_field('quiz_attempts', 'timecheckstate', ['id' => $attempt4]));
    }

    /**
     * The task should queue the oldest overdue attempts first.
     */
    public function test_execute_queues_oldest_attempts_first(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('graceperiodmin', 0, 'quiz');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $userid = (int)$user->id;

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance(['course' => $course->id]);

        $timenow = time();
        $latest = $this->create_overdue_attempt($quiz, $userid, 1, $timenow - 100);
        $oldest = $this->create_overdue_attempt($quiz, $userid, 2, $timenow - 300);
        $middle = $this->create_overdue_attempt($quiz, $userid, 3, $timenow - 200);
        $finishedold = $this->create_overdue_attempt($quiz, $userid, 4, $timenow - 400);
        $notdue = $this->create_overdue_attempt($quiz, $userid, 5, $timenow + 300);
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::FINISHED, ['id' => $finishedold]);

        $task = new update_overdue_attempts();
        $this->expectOutputRegex(
            '/Queued update_overdue_attempts_worker for attempt ' . $oldest .
            '.*Queued update_overdue_attempts_worker for attempt ' . $middle .
            '.*Queued update_overdue_attempts_worker for attempt ' . $latest .
            '.*Queued 3 overdue attempt update tasks after scanning 3 attempts\./s',
        );
        $task->execute();

        $classname = manager::get_canonical_class_name(update_overdue_attempts_worker::class);

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

        $this->assertEquals([$oldest, $middle, $latest], $queuedattemptids);
        $this->assertNotContains($finishedold, $queuedattemptids);
        $this->assertNotContains($notdue, $queuedattemptids);
    }

}

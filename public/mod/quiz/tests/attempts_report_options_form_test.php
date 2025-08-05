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

namespace mod_quiz;

use advanced_testcase;
use mod_quiz\local\reports\attempts_report;
use mod_quiz\local\reports\attempts_report_options_form;

/**
 * Unit tests for attempts_report_options_form.
 *
 * @package   mod_quiz
 * @copyright 2025 Monash University
 * @author    Cameron Ball
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_quiz\local\reports\attempts_report_options_form
 */
final class attempts_report_options_form_test extends advanced_testcase {

    /**
     * Test form validation.
     *
     * @dataProvider validation_provider
     *
     * @param array $data Form data.
     * @param array $expected Expected errors.
     */
    public function test_validation(array $data, array $expected): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $form = new class(null, ['quiz' => $quiz]) extends attempts_report_options_form {
        };

        $this->assertEqualsCanonicalizing($expected, $form->validation($data, []));
    }

    /**
     * Provider for validation.
     *
     * @return array Array of testcases.
     */
    public static function validation_provider(): array {
        $pagerange = ['pagemin' => attempts_report::MIN_PAGE_SIZE, 'pagemax' => attempts_report::MAX_PAGE_SIZE];

        return [
            'No state selected' => [
                [
                    'attempts'        => attempts_report::ENROLLED_WITH,
                    'stateinprogress' => 0,
                    'stateoverdue'    => 0,
                    'statefinished'   => 0,
                    'stateabandoned'  => 0,
                    'statenotstarted' => 0,
                    'statesubmitted'  => 0,
                    'pagesize'        => 30,
                ],
                [get_string('reportmustselectstate', 'quiz')],
            ],
            'Enrolled without' => [
                [
                    'attempts'        => \mod_quiz\local\reports\attempts_report::ENROLLED_WITHOUT,
                    'stateinprogress' => 0,
                    'stateoverdue'    => 0,
                    'statefinished'   => 0,
                    'stateabandoned'  => 0,
                    'statenotstarted' => 0,
                    'statesubmitted'  => 0,
                    'pagesize'        => 30,
                ],
                [],
            ],
            'One state selected' => [
                [
                    'attempts' => attempts_report::ENROLLED_WITH,
                    'stateinprogress' => 1,
                    'stateoverdue' => 0,
                    'statefinished' => 0,
                    'stateabandoned' => 0,
                    'statenotstarted' => 0,
                    'statesubmitted' => 0,
                    'pagesize' => 30,
                ],
                [],
            ],
            'Page size too large' => [
                [
                    'attempts' => attempts_report::ENROLLED_WITH,
                    'stateinprogress' => 1,
                    'stateoverdue' => 0,
                    'statefinished' => 0,
                    'stateabandoned' => 0,
                    'statenotstarted' => 0,
                    'statesubmitted' => 0,
                    'pagesize' => 2001,
                ],
                [get_string('reportpagesizeerror', 'quiz', $pagerange)],
            ],
            'Page size too small' => [
                [
                    'attempts' => attempts_report::ENROLLED_WITH,
                    'stateinprogress' => 1,
                    'stateoverdue' => 0,
                    'statefinished' => 0,
                    'stateabandoned' => 0,
                    'statenotstarted' => 0,
                    'statesubmitted' => 0,
                    'pagesize' => -30,
                ],
                [get_string('reportpagesizeerror', 'quiz', $pagerange)],
            ],
            'Page size not specified' => [
                [
                    'attempts' => attempts_report::ENROLLED_WITH,
                    'stateinprogress' => 1,
                    'stateoverdue' => 0,
                    'statefinished' => 0,
                    'stateabandoned' => 0,
                    'statenotstarted' => 0,
                    'statesubmitted' => 0,
                ],
                [get_string('reportpagesizeerror', 'quiz', $pagerange)],
            ],
        ];
    }
}

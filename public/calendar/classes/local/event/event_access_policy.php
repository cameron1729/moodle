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

namespace core_calendar\local\event;

/**
 * Checks whether a raw calendar event can be exposed to a user.
 *
 * @package    core_calendar
 * @copyright  2017 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_access_policy {
    /**
     * Whether event creation should stop before the event entity is built.
     *
     * @param \stdClass $dbrow Raw event record.
     * @param int $requestinguserid Requesting user id.
     * @return bool
     */
    public function should_bail_out(\stdClass $dbrow, int $requestinguserid): bool {
        if (!empty($dbrow->categoryid)) {
            $category = \core_course_category::get($dbrow->categoryid, IGNORE_MISSING, true, $requestinguserid);
            if (empty($category) || !$category->is_uservisible($requestinguserid)) {
                return true;
            }
        }

        // Component callbacks perform all checks for events which are not associated with a course module.
        if (empty($dbrow->modulename)) {
            return false;
        }

        $instances = get_fast_modinfo($dbrow->courseid, $requestinguserid)->instances;
        if (!isset($instances[$dbrow->modulename][$dbrow->instance])) {
            return true;
        }

        $cm = $instances[$dbrow->modulename][$dbrow->instance];
        if (!$cm->uservisible) {
            return true;
        }

        $coursecontext = \context_course::instance($dbrow->courseid);
        if (
            !$cm->get_course()->visible &&
            !has_capability('moodle/course:viewhiddencourses', $coursecontext, $requestinguserid)
        ) {
            return true;
        }

        if (
            !has_capability('moodle/course:view', $coursecontext, $requestinguserid) &&
            !is_enrolled($coursecontext, $requestinguserid)
        ) {
            return true;
        }

        if ($dbrow->eventtype !== \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED) {
            return false;
        }

        $course = (object) ['id' => $dbrow->courseid];
        $completion = new \completion_info($course);
        if (!$completion->is_enabled($cm)) {
            return true;
        }

        $completiondata = $completion->get_data($cm);
        return (int) $completiondata->completionstate === COMPLETION_COMPLETE;
    }
}

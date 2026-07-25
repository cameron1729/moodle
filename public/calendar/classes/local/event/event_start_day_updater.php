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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');

use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\mappers\event_mapper;

/**
 * Updates the start day of an event while preserving its time of day.
 *
 * @package    core_calendar
 * @copyright  2017 Ryan Wyllie <ryan@moodle.com>
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_start_day_updater {
    /**
     * Constructor.
     *
     * @param event_mapper $eventmapper Event mapper.
     */
    public function __construct(
        /** @var event_mapper $eventmapper Event mapper. */
        private readonly event_mapper $eventmapper,
    ) {
    }

    /**
     * Change the start day for an event.
     *
     * Only the date is modified. The existing time of day is retained.
     *
     * @param event_interface $event Existing event.
     * @param \DateTimeInterface $startdate New start date.
     * @return event_interface Updated event.
     */
    public function update(
        event_interface $event,
        \DateTimeInterface $startdate,
    ): event_interface {
        global $DB;

        $legacyevent = $this->eventmapper->from_event_to_legacy_event($event);
        $hascoursemodule = !empty($event->get_course_module());
        $moduleinstance = null;
        $starttime = $event->get_times()->get_start_time()->setDate(
            $startdate->format('Y'),
            $startdate->format('n'),
            $startdate->format('j'),
        );
        $starttimestamp = $starttime->getTimestamp();

        if ($hascoursemodule) {
            $moduleinstance = $DB->get_record(
                $event->get_course_module()->get('modname'),
                ['id' => $event->get_course_module()->get('instance')],
                '*',
                MUST_EXIST,
            );

            // Apply the component's valid start-time range when one is provided.
            [$min, $max] = component_callback(
                'mod_' . $event->get_course_module()->get('modname'),
                'core_calendar_get_valid_event_timestart_range',
                [$legacyevent, $moduleinstance],
                [false, false],
            );
        } else if ($legacyevent->courseid != 0 && $legacyevent->courseid != SITEID && $legacyevent->groupid == 0) {
            [$min, $max] = component_callback(
                'core_course',
                'core_calendar_get_valid_event_timestart_range',
                [$legacyevent, $event->get_course()->get_proxied_instance()],
                [0, 0],
            );
        } else {
            $min = $max = 0;
        }

        if ($min === false || $max === false) {
            throw new \moodle_exception('The start day of this event can not be modified');
        }

        if ($min && $starttimestamp < $min[0]) {
            throw new \moodle_exception($min[1]);
        }

        if ($max && $starttimestamp > $max[0]) {
            throw new \moodle_exception($max[1]);
        }

        // This function performs the capability checks for the update.
        $legacyevent->update((object) ['timestart' => $starttimestamp]);

        // Notify the owning activity only when the user may manually edit its event.
        if ($hascoursemodule && calendar_edit_event_allowed($legacyevent, true)) {
            component_callback(
                'mod_' . $event->get_course_module()->get('modname'),
                'core_calendar_event_timestart_updated',
                [$legacyevent, $moduleinstance],
            );

            $courseid = $event->get_course()->get('id');
            $cmid = $event->get_course_module()->get('id');
            \course_modinfo::purge_course_module_cache($courseid, $cmid);
            rebuild_course_cache($courseid, true, true);
        }

        return $this->eventmapper->from_legacy_event_to_event($legacyevent);
    }
}

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

namespace core_calendar\presenter;

use context;
use core_calendar\external\calendar_event_exporter;
use core_calendar\external\event_exporter;
use core_calendar\external\events_related_objects_cache;
use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\mappers\event_mapper;
use renderer_base;
use stdClass;

/**
 * Presents Calendar events for templates and web services.
 *
 * @package    core_calendar
 * @copyright  2017 Ryan Wyllie <ryan@moodle.com>
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event {
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
     * Present an event for a web service.
     *
     * @param event_interface $event Event.
     * @param context $context Event context.
     * @param stdClass|null $course Related course.
     * @param renderer_base $renderer Renderer.
     * @return stdClass
     */
    public function for_webservice(
        event_interface $event,
        context $context,
        ?stdClass $course,
        renderer_base $renderer,
    ): stdClass {
        return (new event_exporter(
            $event,
            [
                'context' => $context,
                'course' => $course,
            ],
            $this->eventmapper,
        ))->export($renderer);
    }

    /**
     * Present an event for a Calendar view template.
     *
     * @param event_interface $event Event.
     * @param array $related Related Calendar view data.
     * @param renderer_base $renderer Renderer.
     * @return stdClass
     */
    public function for_template(
        event_interface $event,
        array $related,
        renderer_base $renderer,
    ): stdClass {
        return (new calendar_event_exporter(
            $event,
            $related,
            $this->eventmapper,
        ))->export($renderer);
    }

    /**
     * Present a list of events for a web service.
     *
     * @param event_interface[] $events Events.
     * @param events_related_objects_cache $cache Related-object cache.
     * @param renderer_base $renderer Renderer.
     * @return stdClass
     */
    public function collection_for_webservice(
        array $events,
        events_related_objects_cache $cache,
        renderer_base $renderer,
    ): stdClass {
        $presentedevents = array_map(
            fn(event_interface $event): stdClass => $this->for_webservice(
                $event,
                $cache->get_context($event),
                $cache->get_course($event),
                $renderer,
            ),
            $events,
        );

        return (object) [
            'events' => $presentedevents,
            'firstid' => $presentedevents ? reset($presentedevents)->id : null,
            'lastid' => $presentedevents ? end($presentedevents)->id : null,
        ];
    }

    /**
     * Present events grouped by course for a web service.
     *
     * @param array $eventsbycourse Events indexed by course id.
     * @param events_related_objects_cache $cache Related-object cache.
     * @param renderer_base $renderer Renderer.
     * @return stdClass
     */
    public function grouped_by_course_for_webservice(
        array $eventsbycourse,
        events_related_objects_cache $cache,
        renderer_base $renderer,
    ): stdClass {
        $groups = [];
        foreach ($eventsbycourse as $courseid => $events) {
            $group = $this->collection_for_webservice($events, $cache, $renderer);
            $group->courseid = $courseid;
            $groups[] = $group;
        }

        return (object) ['groupedbycourse' => $groups];
    }

    /**
     * Get the event type used by Calendar view filters.
     *
     * @param event_interface $event Event.
     * @return string
     */
    public function get_calendar_event_type(event_interface $event): string {
        return $event->get_course_module() ? 'course' : $event->get_type();
    }
}

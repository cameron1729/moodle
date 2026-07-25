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

namespace core_calendar\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');

use context_system;
use context_user;
use core_calendar\local\event\data_access\event_vault_factory;
use core_calendar\local\event\event_start_day_updater;
use core_calendar\local\event\forms\create as create_event_form;
use core_calendar\local\event\forms\update as update_event_form;
use core_calendar\local\event\mappers\create_update_form_mapper;
use core_calendar\local\event\mappers\event_mapper;
use core_calendar\presenter\event as event_presenter;
use core_external\external_api;
use renderer_base;
use stdClass;

/**
 * Handles Calendar external operations.
 *
 * @package    core_calendar
 * @copyright  2012 Ankit Agarwal
 * @copyright  2017 Ryan Wyllie <ryan@moodle.com>
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event_controller {
    /**
     * Constructor.
     *
     * @param event_vault_factory $eventvaultfactory Event vault factory.
     * @param create_update_form_mapper $formmapper Form mapper.
     * @param event_mapper $eventmapper Event mapper.
     * @param event_start_day_updater $eventupdater Event start-day updater.
     * @param event_presenter $eventpresenter Event presenter.
     */
    public function __construct(
        /** @var event_vault_factory $eventvaultfactory Event vault factory. */
        private readonly event_vault_factory $eventvaultfactory,
        /** @var create_update_form_mapper $formmapper Form mapper. */
        private readonly create_update_form_mapper $formmapper,
        /** @var event_mapper $eventmapper Event mapper. */
        private readonly event_mapper $eventmapper,
        /** @var event_start_day_updater $eventupdater Event start-day updater. */
        private readonly event_start_day_updater $eventupdater,
        /** @var event_presenter $eventpresenter Event presenter. */
        private readonly event_presenter $eventpresenter,
    ) {
    }

    /**
     * Retrieve action events ordered by time.
     *
     * @param stdClass $user Requesting user.
     * @param int|null $timesortfrom Lower timesort bound.
     * @param int|null $timesortto Upper timesort bound.
     * @param int|null $aftereventid Last seen event id.
     * @param int $limitnum Maximum number of events.
     * @param bool $limittononsuspendedevents Whether to exclude suspended enrolments.
     * @param string|null $searchvalue Search value.
     * @param renderer_base $renderer Calendar renderer.
     * @return stdClass
     */
    public function get_action_events_by_timesort(
        stdClass $user,
        ?int $timesortfrom,
        ?int $timesortto,
        ?int $aftereventid,
        int $limitnum,
        bool $limittononsuspendedevents,
        ?string $searchvalue,
        renderer_base $renderer,
    ): stdClass {
        $vault = $this->eventvaultfactory->create($user->id);
        $afterevent = null;
        if ($aftereventid && $event = $vault->get_event_by_id($aftereventid)) {
            $afterevent = $event;
        }
        $events = $vault->get_action_events_by_timesort(
            $user,
            $timesortfrom,
            $timesortto,
            $afterevent,
            $limitnum,
            $limittononsuspendedevents,
            $searchvalue,
        );

        return $this->eventpresenter->collection_for_webservice(
            $events,
            new events_related_objects_cache($events),
            $renderer,
        );
    }

    /**
     * Retrieve action events for one course.
     *
     * @param stdClass $user Requesting user.
     * @param stdClass $course Course record.
     * @param int|null $timesortfrom Lower timesort bound.
     * @param int|null $timesortto Upper timesort bound.
     * @param int|null $aftereventid Last seen event id.
     * @param int $limitnum Maximum number of events.
     * @param string|null $searchvalue Search value.
     * @param renderer_base $renderer Calendar renderer.
     * @return stdClass
     */
    public function get_action_events_by_course(
        stdClass $user,
        stdClass $course,
        ?int $timesortfrom,
        ?int $timesortto,
        ?int $aftereventid,
        int $limitnum,
        ?string $searchvalue,
        renderer_base $renderer,
    ): stdClass {
        $vault = $this->eventvaultfactory->create($user->id);
        $afterevent = null;
        if ($aftereventid && $event = $vault->get_event_by_id($aftereventid)) {
            $afterevent = $event;
        }
        $events = $vault->get_action_events_by_course(
            $user,
            $course,
            $timesortfrom,
            $timesortto,
            $afterevent,
            $limitnum,
            $searchvalue,
        );

        return $this->eventpresenter->collection_for_webservice(
            $events,
            new events_related_objects_cache($events, [$course]),
            $renderer,
        );
    }

    /**
     * Retrieve action events for multiple courses.
     *
     * @param stdClass $user Requesting user.
     * @param stdClass[] $courses Course records.
     * @param int|null $timesortfrom Lower timesort bound.
     * @param int|null $timesortto Upper timesort bound.
     * @param int $limitnum Maximum number of events per course.
     * @param string|null $searchvalue Search value.
     * @param renderer_base $renderer Calendar renderer.
     * @return stdClass|array
     */
    public function get_action_events_by_courses(
        stdClass $user,
        array $courses,
        ?int $timesortfrom,
        ?int $timesortto,
        int $limitnum,
        ?string $searchvalue,
        renderer_base $renderer,
    ): stdClass|array {
        $vault = $this->eventvaultfactory->create($user->id);
        $events = [];
        foreach ($courses as $course) {
            $events[$course->id] = $vault->get_action_events_by_course(
                $user,
                $course,
                $timesortfrom,
                $timesortto,
                null,
                $limitnum,
                $searchvalue,
            );
        }

        if (empty($events)) {
            return ['groupedbycourse' => []];
        }

        return $this->eventpresenter->grouped_by_course_for_webservice(
            $events,
            new events_related_objects_cache(array_merge(...array_values($events)), $courses),
            $renderer,
        );
    }

    /**
     * Retrieve, authorise, and present an event.
     *
     * @param int $requestinguserid Requesting user id.
     * @param int $eventid Event id.
     * @param renderer_base $renderer Calendar renderer.
     * @return array Event details and warnings.
     */
    public function get_calendar_event_by_id(
        int $requestinguserid,
        int $eventid,
        renderer_base $renderer,
    ): array {
        $event = $this->eventvaultfactory->create($requestinguserid)->get_event_by_id($eventid);
        if ($event && !calendar_view_event_allowed($this->eventmapper->from_event_to_legacy_event($event))) {
            throw new \moodle_exception('nopermissiontoviewcalendar', 'error');
        }

        if (!$event) {
            // The event context is unavailable, so use system context for the exception.
            throw new \required_capability_exception(
                context_system::instance(),
                'moodle/course:view',
                'nopermissions',
                'error',
            );
        }

        $cache = new events_related_objects_cache([$event]);
        $presentedevent = $this->eventpresenter->for_webservice(
            $event,
            $cache->get_context($event),
            $cache->get_course($event),
            $renderer,
        );

        return [
            'event' => $presentedevent,
            'warnings' => [],
        ];
    }

    /**
     * Validate, save, and present a submitted event.
     *
     * @param string $formdata URI-encoded event form data.
     * @param context_user $context Requesting user context.
     * @param renderer_base $renderer Calendar renderer.
     * @return array Created or updated event, or a validation error.
     */
    public function submit_create_update_form(
        string $formdata,
        context_user $context,
        renderer_base $renderer,
    ): array {
        global $CFG, $USER;

        require_once($CFG->libdir . '/filelib.php');

        $data = [];
        parse_str($formdata, $data);

        if (WS_SERVER) {
            // Requests through a web-service server do not use form sesskey validation.
            $USER->ignoresesskey = true;
        }

        $eventtype = $data['eventtype'] ?? null;
        $coursekey = $eventtype === 'group' ? 'groupcourseid' : 'courseid';
        $courseid = !empty($data[$coursekey]) ? $data[$coursekey] : null;
        $editoroptions = create_event_form::build_editor_options($context);
        $formoptions = ['editoroptions' => $editoroptions, 'courseid' => $courseid];
        $allowedeventtypes = calendar_get_allowed_event_types($courseid);

        if (!in_array(true, $allowedeventtypes, true)) {
            throw new \moodle_exception('nopermissiontoupdatecalendar');
        }
        if (empty($eventtype) || empty($allowedeventtypes[$eventtype])) {
            return ['validationerror' => true];
        }

        $formoptions['eventtypes'] = $allowedeventtypes;
        if ($courseid) {
            require_once($CFG->libdir . '/grouplib.php');
            $groupcoursedata = groups_get_all_groups($courseid);
            if (!empty($groupcoursedata)) {
                $formoptions['groups'] = [];
                foreach ($groupcoursedata as $groupid => $groupdata) {
                    $formoptions['groups'][$groupid] = $groupdata->name;
                }
            }
        }

        if (!empty($data['id'])) {
            $eventid = clean_param($data['id'], PARAM_INT);
            $legacyevent = \calendar_event::load($eventid);
            $legacyevent->count_repeats();
            $formoptions['event'] = $legacyevent;
            $mform = new update_event_form(null, $formoptions, 'post', '', null, true, $data);
        } else {
            $legacyevent = null;
            $mform = new create_event_form(null, $formoptions, 'post', '', null, true, $data);
        }

        $validateddata = $mform->get_data();
        if (!$validateddata) {
            return ['validationerror' => true];
        }

        $properties = $this->formmapper->from_data_to_event_properties($validateddata);
        if ($legacyevent === null) {
            $legacyevent = new \calendar_event($properties);
            // Initialise description and the other default event properties.
            $properties = $legacyevent->properties(true);
        }

        if (!calendar_edit_event_allowed($legacyevent, true)) {
            throw new \moodle_exception('nopermissiontoupdatecalendar');
        }

        $legacyevent->update($properties);
        $eventcontext = $legacyevent->context;

        file_remove_editor_orphaned_files($validateddata->description);
        $description = file_save_draft_area_files(
            $validateddata->description['itemid'],
            $eventcontext->id,
            'calendar',
            'event_description',
            $legacyevent->id,
            create_event_form::build_editor_options($eventcontext),
            $validateddata->description['text'],
        );

        if ($description != $validateddata->description['text']) {
            $properties->id = $legacyevent->id;
            $properties->description = $description;
            $legacyevent->update($properties);
        }

        $event = $this->eventmapper->from_legacy_event_to_event($legacyevent);
        $cache = new events_related_objects_cache([$event]);
        $presentedevent = $this->eventpresenter->for_webservice(
            $event,
            $cache->get_context($event),
            $cache->get_course($event),
            $renderer,
        );

        return ['event' => $presentedevent];
    }

    /**
     * Authorise, update, and present an event's start day.
     *
     * @param int $requestinguserid Requesting user id.
     * @param int $eventid Event id.
     * @param int $daytimestamp Timestamp within the desired day.
     * @param renderer_base $renderer Calendar renderer.
     * @return array Updated event.
     */
    public function update_event_start_day(
        int $requestinguserid,
        int $eventid,
        int $daytimestamp,
        renderer_base $renderer,
    ): array {
        $event = $this->eventvaultfactory->create($requestinguserid)->get_event_by_id($eventid);
        if (!$event) {
            throw new \moodle_exception('Unable to find event with id ' . $eventid);
        }

        $legacyevent = $this->eventmapper->from_event_to_legacy_event($event);
        if (!calendar_edit_event_allowed($legacyevent, true)) {
            throw new \moodle_exception('nopermissiontoupdatecalendar');
        }

        external_api::validate_context($legacyevent->context);

        $newdate = usergetdate($daytimestamp);
        $startdate = new \DateTimeImmutable(implode('-', [$newdate['year'], $newdate['mon'], $newdate['mday']]));
        $event = $this->eventupdater->update($event, $startdate);
        $cache = new events_related_objects_cache([$event]);
        $presentedevent = $this->eventpresenter->for_webservice(
            $event,
            $cache->get_context($event),
            $cache->get_course($event),
            $renderer,
        );

        return ['event' => $presentedevent];
    }
}

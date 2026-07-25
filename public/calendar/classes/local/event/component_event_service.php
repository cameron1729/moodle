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

use core_calendar\action_factory;
use core_calendar\local\event\entities\action_event;
use core_calendar\local\event\entities\action_event_interface;
use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\mappers\event_mapper;

/**
 * Applies component-provided action and visibility behaviour to calendar events.
 *
 * @package    core_calendar
 * @copyright  2017 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class component_event_service {
    /**
     * Constructor.
     *
     * @param event_mapper $mapper Event mapper.
     * @param action_factory $actionfactory Action factory.
     */
    public function __construct(
        /** @var event_mapper $mapper Event mapper. */
        private readonly event_mapper $mapper,
        /** @var action_factory $actionfactory Action factory. */
        private readonly action_factory $actionfactory,
    ) {
    }

    /**
     * Apply the component action callback to an event.
     *
     * @param event_interface $event The event.
     * @param int $requestinguserid Requesting user id.
     * @return event_interface
     */
    public function apply_action(event_interface $event, int $requestinguserid): event_interface {
        $action = null;
        if ($event->get_component()) {
            $legacyevent = $this->mapper->from_event_to_legacy_event($event);
            if (empty($event->user) && !empty($legacyevent->userid)) {
                $legacyevent->userid = $requestinguserid;
            }

            $action = component_callback(
                $event->get_component(),
                'core_calendar_provide_event_action',
                [$legacyevent, $this->actionfactory, $requestinguserid],
            );
        }

        return $action ? new action_event($event, $action) : $event;
    }

    /**
     * Apply the component visibility callback to an event.
     *
     * @param event_interface $event The event.
     * @param int $requestinguserid Requesting user id.
     * @return bool
     */
    public function is_visible(event_interface $event, int $requestinguserid): bool {
        $eventvisible = null;
        if ($event->get_component()) {
            $legacyevent = $this->mapper->from_event_to_legacy_event($event);
            if (empty($event->user) && !empty($legacyevent->userid)) {
                $legacyevent->userid = $requestinguserid;
            }

            $eventvisible = component_callback(
                $event->get_component(),
                'core_calendar_is_event_visible',
                [$legacyevent, $requestinguserid],
            );
        }

        if ($event instanceof action_event_interface && $event->get_action()->get_item_count() === 0) {
            return false;
        }

        return $eventvisible === null ? true : (bool) $eventvisible;
    }
}

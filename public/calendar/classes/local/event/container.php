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

/**
 * Core container for calendar events.
 *
 * The purpose of this class is simply to wire together the various
 * implementations of calendar event components to produce a solution
 * to the problems Moodle core wants to solve.
 *
 * @package    core_calendar
 * @copyright  2017 Cameron Ball <cameron@cameron1729.xyz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_calendar\local\event;

defined('MOODLE_INTERNAL') || die();

use core\attribute\deprecated;
use core_calendar\action_factory;
use core_calendar\local\event\data_access\event_vault;
use core_calendar\local\event\entities\action_event;
use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\factories\event_factory;
use core_calendar\local\event\mappers\event_mapper;
use core_calendar\local\event\strategies\raw_event_retrieval_strategy;

/**
 * Core container.
 *
 * @copyright 2017 Cameron Ball <cameron@cameron1729.xyz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
 *     at application boundaries.
 */
#[deprecated(
    replacement: null,
    since: '5.3',
    reason: 'Use constructor injection and resolve only graph roots with \core\di at application boundaries.',
    mdl: 'MDL-89216',
)]
class container extends legacy_container_state {
    /**
     * Initialises the dependency graph if it hasn't yet been.
     */
    private static function init() {
        if (empty(self::$eventfactory)) {
            self::$actionfactory = new action_factory();
            self::$eventmapper = new event_mapper(
                // The event mapper we return from here needs to know how to
                // make events, so it needs an event factory. However we can't
                // give it the same one as we store and return in the container
                // as that one uses all our plumbing to control event visibility.
                //
                // So we make a new even factory that doesn't do anyting other than
                // return the instance.
                new event_factory(
                    // Never apply actions, simply return.
                    function (event_interface $event) {
                        return $event;
                    },
                    // Never hide an event.
                    function () {
                        return true;
                    },
                    // Never bail out early when instantiating an event.
                    function () {
                        return false;
                    },
                    self::$coursecache,
                    self::$modulecache
                )
            );

            $eventaccesspolicy = new event_access_policy();
            $componenteventservice = self::get_component_event_service();

            self::$eventfactory = new event_factory(
                fn(event_interface $event) => $componenteventservice->apply_action(
                    $event,
                    legacy_container_state::get_requesting_user(),
                ),
                fn(event_interface $event) => $componenteventservice->is_visible(
                    $event,
                    legacy_container_state::get_requesting_user(),
                ),
                fn($dbrow) => $eventaccesspolicy->should_bail_out(
                    $dbrow,
                    legacy_container_state::get_requesting_user(),
                ),
                self::$coursecache,
                self::$modulecache
            );
        }

        if (empty(self::$eventvault)) {
            self::$eventretrievalstrategy = new raw_event_retrieval_strategy();
            self::$eventvault = new event_vault(self::$eventfactory, self::$eventretrievalstrategy);
        }
    }

    /**
     * Reset all static caches, called between tests.
     *
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function reset_caches() {
        self::emit_deprecation();
        parent::reset_caches();
    }

    /**
     * Gets the event factory.
     *
     * @return event_factory
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function get_event_factory() {
        self::emit_deprecation();
        self::init();
        return self::$eventfactory;
    }

    /**
     * Gets the event mapper.
     *
     * @return event_mapper
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function get_event_mapper() {
        self::emit_deprecation();
        self::init();
        return self::$eventmapper;
    }

    /**
     * Return an event vault.
     *
     * @return event_vault
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function get_event_vault() {
        self::emit_deprecation();
        self::init();
        return self::$eventvault;
    }

    /**
     * Sets the requesting user so that all capability checks are done against this user.
     * Setting the requesting user (hence calling this function) is optional and if you do not so,
     * $USER will be used as the requesting user. However, if you wish to set the requesting user yourself,
     * you should call this function before any other function of the container class is called.
     *
     * @param int $userid The user id.
     * @throws \coding_exception
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function set_requesting_user($userid) {
        self::emit_deprecation();
        parent::set_requesting_user($userid);
    }

    /**
     * Returns the requesting user id.
     * It usually is the current user unless it has been set explicitly using set_requesting_user.
     *
     * @return int
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function get_requesting_user() {
        self::emit_deprecation();
        return parent::get_requesting_user();
    }

    /**
     * Calls callback 'core_calendar_provide_event_action' from the component responsible for the event
     *
     * If no callback is present or callback returns null, there is no action on the event
     * and it will not be displayed on the dashboard.
     *
     * @param event_interface $event
     * @return action_event|event_interface
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function apply_component_provide_event_action(event_interface $event) {
        self::emit_deprecation();
        self::init();
        return self::get_component_event_service()->apply_action($event, parent::get_requesting_user());
    }

    /**
     * Calls callback 'core_calendar_is_event_visible' from the component responsible for the event
     *
     * The visibility callback is optional, if not present it is assumed as visible.
     * If it is an actionable event but the get_item_count() returns 0 the visibility
     * is set to false.
     *
     * @param event_interface $event
     * @return bool
     * @deprecated since Moodle 5.3 MDL-89216 - use constructor injection and resolve only graph roots with \core\di
     *     at application boundaries.
     */
    public static function apply_component_is_event_visible(event_interface $event) {
        self::emit_deprecation();
        self::init();
        return self::get_component_event_service()->is_visible($event, parent::get_requesting_user());
    }

    /**
     * Create the component callback service used by the legacy compatibility methods.
     *
     * @return component_event_service
     */
    private static function get_component_event_service(): component_event_service {
        return new component_event_service(self::$eventmapper, self::$actionfactory);
    }

    /**
     * Emit the deprecation notice for the legacy container API.
     */
    private static function emit_deprecation(): void {
        \core\deprecation::emit_deprecation_if_present(self::class);
    }
}

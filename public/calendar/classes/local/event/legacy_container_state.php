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
use core_calendar\local\event\data_access\event_vault;
use core_calendar\local\event\factories\event_factory;
use core_calendar\local\event\mappers\event_mapper;
use core_calendar\local\event\strategies\raw_event_retrieval_strategy;

/**
 * Holds the legacy Calendar container graph and requesting-user state.
 *
 * The protected properties remain here so that subclasses of the legacy container retain access to the original state.
 *
 * @package    core_calendar
 * @copyright  2017 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class legacy_container_state {
    /** @var event_factory Event factory. */
    protected static $eventfactory;

    /** @var event_mapper Event mapper. */
    protected static $eventmapper;

    /** @var action_factory Action factory. */
    protected static $actionfactory;

    /** @var event_vault Event vault. */
    protected static $eventvault;

    /** @var raw_event_retrieval_strategy Event retrieval strategy. */
    protected static $eventretrievalstrategy;

    /** @var \stdClass[] Courses cached by the legacy event factory. */
    protected static $coursecache = [];

    /** @var \stdClass[] Course modules cached by the legacy event factory. */
    protected static $modulecache = [];

    /** @var int|null User used for legacy capability checks. */
    protected static $requestinguserid;

    /**
     * Reset the legacy graph and requesting-user state.
     */
    public static function reset_caches() {
        self::$requestinguserid = null;
        self::$eventfactory = null;
        self::$eventmapper = null;
        self::$eventvault = null;
        self::$actionfactory = null;
        self::$eventretrievalstrategy = null;
        self::$coursecache = [];
        self::$modulecache = [];
    }

    /**
     * Set the user used for legacy capability checks.
     *
     * @param int $userid User id.
     */
    public static function set_requesting_user($userid) {
        self::$requestinguserid = $userid;
    }

    /**
     * Get the user used for legacy capability checks.
     *
     * Empty values retain the historical behaviour of falling back to the current user.
     *
     * @return int User id.
     */
    public static function get_requesting_user() {
        global $USER;

        return empty(self::$requestinguserid) ? $USER->id : self::$requestinguserid;
    }
}

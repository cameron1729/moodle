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

namespace core_calendar\local\event\factories;

use core_calendar\local\event\entities\event_interface;

/**
 * Creates event entities without applying access or component policy.
 *
 * This factory is used by the event mapper, where converting an existing event must not make it disappear.
 *
 * @package    core_calendar
 * @copyright  2017 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_entity_factory extends event_factory {
    /** @var array Course cache used while creating event entities. */
    private array $coursecache = [];

    /** @var array Course module cache used while creating event entities. */
    private array $modulecache = [];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            static fn(event_interface $event): event_interface => $event,
            static fn(): bool => true,
            static fn(): bool => false,
            $this->coursecache,
            $this->modulecache,
        );
    }
}

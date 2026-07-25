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

namespace core_calendar;

use core\hook\di_configuration;
use core_calendar\local\event\factories\event_entity_factory;
use core_calendar\local\event\mappers\event_mapper;

/**
 * Hook callbacks for the calendar subsystem.
 *
 * @package    core_calendar
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add calendar definitions to the dependency injection container.
     *
     * @param di_configuration $hook Dependency injection configuration hook.
     * @return void
     * @codeCoverageIgnore
     */
    public static function provide_di_configuration(di_configuration $hook): void {
        $hook->add_definition(
            event_mapper::class,
            \DI\autowire(event_mapper::class)->constructorParameter(
                'factory',
                \DI\get(event_entity_factory::class),
            ),
        );
    }
}

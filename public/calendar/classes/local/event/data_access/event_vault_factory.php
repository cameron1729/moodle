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

namespace core_calendar\local\event\data_access;

use core_calendar\local\event\component_event_service;
use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\event_access_policy;
use core_calendar\local\event\factories\event_factory;
use core_calendar\local\event\strategies\raw_event_retrieval_strategy;

/**
 * Creates event vaults configured for an explicit requesting user.
 *
 * @package    core_calendar
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_vault_factory {
    /** @var array Course cache shared by the event factories created here. */
    private array $coursecache = [];

    /** @var array Course module cache shared by the event factories created here. */
    private array $modulecache = [];

    /**
     * Constructor.
     *
     * @param component_event_service $componenteventservice Component callback service.
     * @param event_access_policy $eventaccesspolicy Event access policy.
     * @param raw_event_retrieval_strategy $retrievalstrategy Raw event retrieval strategy.
     */
    public function __construct(
        /** @var component_event_service $componenteventservice Component callback service. */
        private readonly component_event_service $componenteventservice,
        /** @var event_access_policy $eventaccesspolicy Event access policy. */
        private readonly event_access_policy $eventaccesspolicy,
        /** @var raw_event_retrieval_strategy $retrievalstrategy Raw event retrieval strategy. */
        private readonly raw_event_retrieval_strategy $retrievalstrategy,
    ) {
    }

    /**
     * Create an event vault for a requesting user.
     *
     * @param int $requestinguserid Requesting user id.
     * @return event_vault
     */
    public function create(int $requestinguserid): event_vault {
        $eventfactory = new event_factory(
            fn(event_interface $event): event_interface => $this->componenteventservice->apply_action(
                $event,
                $requestinguserid,
            ),
            fn(event_interface $event): bool => $this->componenteventservice->is_visible(
                $event,
                $requestinguserid,
            ),
            fn(\stdClass $record): bool => $this->eventaccesspolicy->should_bail_out(
                $record,
                $requestinguserid,
            ),
            $this->coursecache,
            $this->modulecache,
        );

        return new event_vault($eventfactory, $this->retrievalstrategy);
    }
}

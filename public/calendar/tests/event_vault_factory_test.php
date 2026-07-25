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

use core\di;
use core_calendar\local\event\component_event_service;
use core_calendar\local\event\data_access\event_vault;
use core_calendar\local\event\data_access\event_vault_factory;
use core_calendar\local\event\entities\event_interface;
use core_calendar\local\event\event_access_policy;
use core_calendar\local\event\factories\event_entity_factory;
use core_calendar\local\event\mappers\event_mapper;
use core_calendar\local\event\strategies\raw_event_retrieval_strategy;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Tests for the native event vault dependency graph.
 *
 * @package    core_calendar
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(event_vault_factory::class)]
#[CoversClass(event_entity_factory::class)]
final class event_vault_factory_test extends \advanced_testcase {
    /**
     * Set up each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Test that the native container can resolve the complete event vault graph.
     */
    public function test_di_graph_resolves(): void {
        $mapper = di::get(event_mapper::class);
        $factoryproperty = new \ReflectionProperty(event_mapper::class, 'factory');

        $this->assertInstanceOf(event_entity_factory::class, $factoryproperty->getValue($mapper));
        $this->assertInstanceOf(event_vault_factory::class, di::get(event_vault_factory::class));
    }

    /**
     * Test that the graph honours a dependency replaced at its composition root.
     */
    public function test_di_graph_honours_replaced_dependency(): void {
        $retrievalstrategy = $this->createMock(raw_event_retrieval_strategy::class);
        $retrievalstrategy->expects($this->once())
            ->method('get_raw_events')
            ->willReturn([]);
        di::set(raw_event_retrieval_strategy::class, $retrievalstrategy);

        $vault = di::get(event_vault_factory::class)->create(42);

        $this->assertInstanceOf(event_vault::class, $vault);
        $this->assertSame([], $vault->get_events(limitnum: 1));
    }

    /**
     * Test that requesting user context is captured per vault and does not leak between calls.
     */
    public function test_event_vault_factory_does_not_store_requesting_user(): void {
        $policyrequesters = [];
        $actionrequesters = [];
        $visibilityrequesters = [];

        $policy = $this->createMock(event_access_policy::class);
        $policy->method('should_bail_out')
            ->willReturnCallback(function (\stdClass $record, int $requestinguserid) use (&$policyrequesters): bool {
                $policyrequesters[] = $requestinguserid;
                return false;
            });

        $componentservice = $this->createMock(component_event_service::class);
        $componentservice->method('apply_action')
            ->willReturnCallback(function (event_interface $event, int $requestinguserid) use (&$actionrequesters) {
                $actionrequesters[] = $requestinguserid;
                return $event;
            });
        $componentservice->method('is_visible')
            ->willReturnCallback(function (event_interface $event, int $requestinguserid) use (&$visibilityrequesters) {
                $visibilityrequesters[] = $requestinguserid;
                return true;
            });

        $record = (object) [
            'id' => 1,
            'name' => 'Event',
            'description' => 'Description',
            'format' => FORMAT_PLAIN,
            'categoryid' => 0,
            'courseid' => 0,
            'groupid' => 0,
            'userid' => 0,
            'repeatid' => 0,
            'component' => 'core',
            'modulename' => '',
            'instance' => 0,
            'eventtype' => 'site',
            'timestart' => 1,
            'timeduration' => 0,
            'timemodified' => 1,
            'timesort' => 1,
            'visible' => 1,
            'subscriptionid' => null,
            'location' => '',
            'type' => CALENDAR_EVENT_TYPE_ACTION,
        ];
        $retrievalstrategy = $this->createStub(raw_event_retrieval_strategy::class);
        $retrievalstrategy->method('get_raw_events')->willReturn([$record]);

        $factory = new event_vault_factory($componentservice, $policy, $retrievalstrategy);
        $firstvault = $factory->create(11);
        $secondvault = $factory->create(22);

        $secondvault->get_events(limitnum: 1);
        $firstvault->get_events(limitnum: 1);
        $secondvault->get_events(limitnum: 1);

        $this->assertSame([22, 11, 22], $policyrequesters);
        $this->assertSame([22, 11, 22], $actionrequesters);
        $this->assertSame([22, 11, 22], $visibilityrequesters);
    }

    /**
     * Test that the event vault graph can be resolved from a compiled container.
     */
    public function test_compiled_di_graph_resolves(): void {
        $builder = new \DI\ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->enableCompilation(make_request_directory(), 'CoreCalendarCompiledContainer');
        $builder->addDefinitions([
            \moodle_database::class => function (): \moodle_database {
                global $DB;
                return $DB;
            },
            \core\clock::class => fn(): \core\clock => new \core\system_clock(),
        ]);

        hook_callbacks::provide_di_configuration(new \core\hook\di_configuration($builder));
        $container = $builder->build();
        $mapping = (new \ReflectionClass($container))->getReflectionConstant('METHOD_MAPPING')->getValue();

        $this->assertArrayHasKey(event_mapper::class, $mapping);
        $this->assertInstanceOf(event_vault_factory::class, $container->get(event_vault_factory::class));
        $this->assertInstanceOf(event_mapper::class, $container->get(event_mapper::class));
    }
}

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

namespace core\lock;

/**
 * Tests the transaction aware lock release behaviour.
 *
 * @package   core
 * @copyright 2025 Catalyst IT Australia Pty Ltd
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \core\lock\transaction_manager
 * @covers \core\lock\lock::release
 * @covers \core\lock\lock::release_immediately
 */
final class transaction_manager_test extends \advanced_testcase {
    /**
     * Ensure lock releases are deferred until the outer transaction commits.
     */
    public function test_release_deferred_until_commit(): void {
        global $DB;

        $this->resetAfterTest();

        $factory = new db_record_lock_factory('phpunit_tx_commit');
        $resource = 'resource_commit';
        $lock = $factory->get_lock($resource, 5);
        $this->assertNotFalse($lock);

        $transaction = $DB->start_delegated_transaction();
        $this->assertTrue($lock->release());

        // The lock should still be held while the transaction is open.
        $this->assertFalse($factory->get_lock($resource, 0));

        $transaction->allow_commit();

        $reacquired = $factory->get_lock($resource, 0);
        $this->assertNotFalse($reacquired);
        $reacquired->release();
    }

    /**
     * Ensure locks are kept until the outermost transaction completes.
     */
    public function test_release_waits_for_outer_transaction(): void {
        global $DB;

        $this->resetAfterTest();

        $factory = new db_record_lock_factory('phpunit_tx_nested');
        $resource = 'resource_nested';
        $lock = $factory->get_lock($resource, 5);
        $this->assertNotFalse($lock);

        $outer = $DB->start_delegated_transaction();
        $inner = $DB->start_delegated_transaction();

        $this->assertTrue($lock->release());
        $this->assertFalse($factory->get_lock($resource, 0));

        // Finishing the inner transaction should not release the lock yet.
        $inner->allow_commit();
        $this->assertFalse($factory->get_lock($resource, 0));

        // Once the outer transaction closes the lock becomes available.
        $outer->allow_commit();
        $reacquired = $factory->get_lock($resource, 0);
        $this->assertNotFalse($reacquired);
        $reacquired->release();
    }

    /**
     * Ensure locks are released when a transaction rolls back.
     */
    public function test_release_after_rollback(): void {
        global $DB;

        $this->resetAfterTest();

        $factory = new db_record_lock_factory('phpunit_tx_rollback');
        $resource = 'resource_rollback';
        $lock = $factory->get_lock($resource, 5);
        $this->assertNotFalse($lock);

        $transaction = $DB->start_delegated_transaction();
        $this->assertTrue($lock->release());
        $this->assertFalse($factory->get_lock($resource, 0));

        try {
            $transaction->rollback(new \coding_exception('transaction rollback test'));
            $this->fail('Rollback should throw the provided exception.');
        } catch (\coding_exception $expected) {
            $this->assertStringContainsString('transaction rollback test', $expected->getMessage());
        }

        $reacquired = $factory->get_lock($resource, 0);
        $this->assertNotFalse($reacquired);
        $reacquired->release();
    }
}

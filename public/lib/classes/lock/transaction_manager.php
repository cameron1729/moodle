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

declare(strict_types=1);

namespace core\lock;

/**
 * Coordinates lock releases with the current database transaction state.
 *
 * @package   core
 * @copyright 2025 Catalyst IT Australia Pty Ltd
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transaction_manager {
    /** @var array Locks awaiting release once all transactions complete. */
    private static array $deferredlocks = [];

    /**
     * Release a lock immediately, or defer the release until the active transaction completes.
     *
     * @param lock $lock
     * @return bool True if the release was performed immediately, or the release was queued.
     */
    public static function release_or_queue(lock $lock): bool {
        if (self::should_defer()) {
            self::$deferredlocks[spl_object_id($lock)] = $lock;
            return true;
        }

        return $lock->release_immediately();
    }

    /**
     * Remove a lock from the deferred queue if it is present.
     *
     * @param lock $lock
     */
    public static function forget(lock $lock): void {
        $identifier = spl_object_id($lock);
        if (array_key_exists($identifier, self::$deferredlocks)) {
            unset(self::$deferredlocks[$identifier]);
        }
    }

    /**
     * Called when the outermost database transaction commits.
     */
    public static function database_transaction_commited(): void {
        self::process_pending_releases();
    }

    /**
     * Called when the outermost database transaction rolls back.
     */
    public static function database_transaction_rolledback(): void {
        self::process_pending_releases();
    }

    /**
     * Process any queued lock releases.
     */
    private static function process_pending_releases(): void {
        if (empty(self::$deferredlocks)) {
            return;
        }

        $locks = self::$deferredlocks;
        self::$deferredlocks = [];

        foreach ($locks as $lock) {
            $lock->release_immediately();
        }
    }

    /**
     * Determine if the release should be deferred because a transaction is active.
     *
     * @return bool
     */
    private static function should_defer(): bool {
        global $DB;

        return isset($DB) && $DB->is_transaction_started();
    }
}

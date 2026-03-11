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

namespace mod_quiz\task;

use core\task\manager;
use core\task\scheduled_task;

/**
 * Queue overdue attempt updates.
 *
 * @package   mod_quiz
 * @copyright 2026 Monash University
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_overdue_attempt_updates extends scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('queueoverdueattemptupdatestask', 'mod_quiz');
    }

    #[\Override]
    public function execute(): void {
        global $DB;

        $timenow = time();
        $processto = $timenow - (int)get_config('quiz', 'graceperiodmin');
        $maxattemptstoqueue = (int)get_config('quiz', 'overdueattemptsmaxqueueperrun');
        $queuedcount = 0;
        $scannedcount = 0;
        $existingtasks = $this->get_existing_update_tasks();

        mtrace('  Looking for overdue quiz attempts to queue...');

        $attemptstoprocess = $DB->get_recordset_select(
            'quiz_attempts',
            "state IN ('inprogress', 'overdue') AND timecheckstate <= :processto",
            ['processto' => $processto],
            'timecheckstate, id',
            'id, timecheckstate',
        );

        try {
            foreach ($attemptstoprocess as $attempt) {
                $scannedcount++;

                $task = new update_overdue_attempt();
                $attemptid = (int)$attempt->id;
                $task->set_custom_data((object)['attemptid' => $attemptid]);
                $wasqueued = isset($existingtasks[$attemptid]) && !$existingtasks[$attemptid]['exhausted'];

                // When MDL-86422 lands, queue_adhoc_task(..., true) returns an existing task id for
                // duplicates instead of false. Combined with the snapshot above, !== false keeps this
                // branch working under both semantics and only counts tasks that were newly queued or
                // revived during this run.
                if (manager::queue_adhoc_task($task, true) !== false && !$wasqueued) {
                    $queuedcount++;
                    $existingtasks[$attemptid] = ['exhausted' => false];
                    if ($queuedcount >= $maxattemptstoqueue) {
                        break;
                    }
                }
            }
        } finally {
            $attemptstoprocess->close();
        }

        mtrace("  Queued {$queuedcount} overdue attempt update tasks after scanning {$scannedcount} attempts.");
    }

    /**
     * Get currently queued overdue attempt update tasks keyed by attempt id.
     *
     * @return array Quiz attempt ids keyed to the queued task's attemptsavailable value.
     *               A value of 0 means the queued task is exhausted.
     */
    private function get_existing_update_tasks(): array {
        global $DB;

        $classname = manager::get_canonical_class_name(update_overdue_attempt::class);
        $records = $DB->get_records(
            'task_adhoc',
            ['classname' => $classname],
            '',
            'id, customdata, attemptsavailable',
        );

        $tasks = [];
        foreach ($records as $record) {
            $customdata = json_decode($record->customdata);
            $attemptid = isset($customdata->attemptid) ? (int)$customdata->attemptid : 0;
            if ($attemptid <= 0) {
                continue;
            }

            $tasks[$attemptid] = [
                'exhausted' => ((int)$record->attemptsavailable === 0),
            ];
        }

        return $tasks;
    }
}

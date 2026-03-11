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
use Override;

/**
 * Queue overdue attempt updates.
 *
 * @package   mod_quiz
 * @copyright 2026 Monash University
 * @author    Cameron Ball <cameronball@catalyst-au.net>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_overdue_attempt_updates extends scheduled_task {
    #[Override]
    public function get_name(): string {
        return get_string('queueoverdueattemptupdatestask', 'mod_quiz');
    }

    #[Override]
    public function execute(): void {
        global $DB;

        $timenow = time();
        $processto = $timenow - (int)get_config('quiz', 'graceperiodmin');
        $maxattemptstoqueue = (int)get_config('quiz', 'overdueattemptsmaxqueueperrun');
        $queuedcount = 0;
        $scannedcount = 0;

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
                $task->set_custom_data((object)['attemptid' => (int)$attempt->id]);

                if (manager::queue_adhoc_task($task, true)) {
                    $queuedcount++;
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
}

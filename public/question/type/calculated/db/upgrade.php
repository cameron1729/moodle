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
 * Calculated question type upgrade code.
 *
 * @package    qtype
 * @subpackage calculated
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade code for the calculated question type.
 * @param int $oldversion the version we are upgrading from.
 */
function xmldb_qtype_calculated_upgrade($oldversion) {
    global $DB;

    // Automatically generated Moodle v4.4.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.5.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.0.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.1.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.2.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v5.3.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2026102800) {
        // Additional plugins might use questions or store data that we can't check during upgrade.
        $additionalplugins = array_filter(
            array_keys(core_component::get_plugin_types()),
            fn($type) => array_diff(
                array_keys(core_component::get_plugin_list($type)),
                core_plugin_manager::standard_plugins_list($type) ?: [],
            ),
        );

        if (!$additionalplugins) {
            $normalise = function (mixed $value) use (&$normalise): mixed {
                if (is_array($value)) {
                    return array_map($normalise, array_values($value));
                }

                if (!is_object($value)) {
                    return $value;
                }

                $fields = array_diff_key((array) $value, array_flip(['id', 'question', 'questionid']));
                ksort($fields);

                return array_map($normalise, $fields);
            };

            $groupsql = "SELECT MIN(q.id) AS id, q.stamp, q.qtype, qbe.questioncategoryid
                          FROM {question} q
                          JOIN {question_versions} qv ON qv.questionid = q.id
                          JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                         WHERE q.qtype IN ('calculated', 'calculatedsimple', 'calculatedmulti')
                               AND q.parent = 0 AND q.stamp <> ''
                      GROUP BY q.stamp, q.qtype, qbe.questioncategoryid
                        HAVING COUNT(1) > 1";

            $qtypefilesql = $DB->sql_like('f.component', ':qtypecomponent');
            $questionssql = "SELECT q.*, qbe.id AS entryid, qbe.ownerid, qbe.idnumber, qbe.nextversion,
                                    qv.version, qv.status, qc.contextid
                               FROM {question} q
                               JOIN {question_versions} qv ON qv.questionid = q.id
                               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                              WHERE q.stamp = :stamp AND q.qtype = :qtype AND qbe.questioncategoryid = :categoryid
                                    AND q.id > :lastid AND q.parent = 0
                                    AND NOT EXISTS (
                                        SELECT 1 FROM {question_versions} other
                                         WHERE (other.questionbankentryid = qbe.id OR other.questionid = q.id) AND other.id <> qv.id
                                    )
                                    AND NOT EXISTS (
                                        SELECT 1 FROM {question_set_references} qsr WHERE qsr.questionscontextid = qc.contextid
                                    )
                                    AND NOT EXISTS (
                                        SELECT 1 FROM {files} f WHERE f.contextid = qc.contextid
                                            AND (f.component = 'question' OR {$qtypefilesql})
                                    )
                           ORDER BY q.id";

            $customfieldsql = "SELECT 1 FROM {customfield_data} d
                                JOIN {customfield_field} f ON f.id = d.fieldid
                                JOIN {customfield_category} c ON c.id = f.categoryid
                               WHERE d.instanceid = ? AND c.component = 'qbank_customfields'";

            $datasetsql = "SELECT d.*
                             FROM {question_dataset_definitions} d
                            WHERE EXISTS (
                                SELECT 1 FROM {question_datasets} qd
                                 WHERE qd.datasetdefinition = d.id AND qd.question = ?
                            )
                         ORDER BY d.type, d.category, d.name, d.id";

            // Use the same dataset values as `qtype_calculated::get_database_dataset_items`.
            $itemsql = "SELECT i.itemnumber, i.value
                         FROM {question_dataset_items} i
                         JOIN (SELECT MAX(id) AS maxid
                                 FROM {question_dataset_items}
                                WHERE definition = ?
                             GROUP BY itemnumber) latest ON latest.maxid = i.id
                     ORDER BY i.itemnumber";

            $questiontables = [
                'question_calculated' => 'question',
                'question_numerical' => 'question',
                'question_calculated_options' => 'question',
                'question_numerical_options' => 'question',
                'question_numerical_units' => 'question',
                'question_hints' => 'questionid',
                'question_answers' => 'question',
            ];

            $batchsize = 100;
            $groups = $DB->get_recordset_sql($groupsql);

            foreach ($groups as $group) {
                $original = null;
                $lastid = 0;
                $params = [
                    'stamp' => $group->stamp,
                    'qtype' => $group->qtype,
                    'categoryid' => $group->questioncategoryid,
                    'qtypecomponent' => $DB->sql_like_escape('qtype_') . '%',
                ];

                upgrade_set_timeout();

                while ($questions = $DB->get_records_sql($questionssql, $params + ['lastid' => $lastid], 0, $batchsize)) {
                    foreach ($questions as $question) {
                        $lastid = $question->id;

                        // Leave questions with children, tags, comments or custom fields alone.
                        if (
                            $DB->record_exists('question', ['parent' => $question->id]) ||
                            $DB->record_exists('tag_instance', [
                                'component' => 'core_question',
                                'itemtype' => 'question',
                                'itemid' => $question->id,
                            ]) ||
                            $DB->record_exists('comments', ['component' => 'qbank_comment', 'itemid' => $question->id]) ||
                            $DB->record_exists_sql($customfieldsql, [$question->id])
                        ) {
                            continue;
                        }

                        $data = clone $question;
                        foreach ($questiontables as $table => $field) {
                            $data->$table = $DB->get_records($table, [$field => $question->id], 'id');
                        }

                        if (
                            !$data->question_answers ||
                            count($data->question_calculated) !== count($data->question_answers) ||
                            array_diff(array_keys($data->question_answers), array_column($data->question_calculated, 'answer'))
                        ) {
                            continue;
                        }

                        // Compare answer settings by answer position, since copies have different answer IDs.
                        $answerids = array_flip(array_keys($data->question_answers));
                        foreach (['question_calculated', 'question_numerical'] as $table) {
                            if (array_diff(array_column($data->$table, 'answer'), array_keys($answerids))) {
                                continue 2;
                            }

                            $data->$table = array_map(
                                fn($row) => (object) array_replace((array) $row, ['answer' => $answerids[$row->answer]]),
                                $data->$table,
                            );
                        }

                        $datasets = $DB->get_records_sql($datasetsql, [$question->id]);
                        if (
                            !$datasets ||
                            count($datasets) !== $DB->count_records('question_datasets', ['question' => $question->id]) ||
                            array_filter($datasets, fn($dataset) => $dataset->itemcount < 1)
                        ) {
                            continue;
                        }

                        foreach ($datasets as $dataset) {
                            $dataset->items = $DB->get_records_sql($itemsql, [$dataset->id]);
                        }
                        $data->datasets = $datasets;

                        unset($data->entryid);
                        $data = $normalise($data);

                        if ($original === null) {
                            $original = $data;

                            continue;
                        }

                        if (
                            $data !== $original ||
                            $DB->record_exists('question_attempts', ['questionid' => $question->id]) ||
                            $DB->record_exists('question_references', ['questionbankentryid' => $question->entryid]) ||
                            $DB->record_exists('question_statistics', ['questionid' => $question->id]) ||
                            $DB->record_exists('question_response_analysis', ['questionid' => $question->id])
                        ) {
                            continue;
                        }

                        $transaction = $DB->start_delegated_transaction();
                        $DB->delete_records('question_datasets', ['question' => $question->id]);

                        // Only remove a dataset if no other question uses it.
                        foreach ($datasets as $dataset) {
                            if (!$DB->record_exists('question_datasets', ['datasetdefinition' => $dataset->id])) {
                                $DB->delete_records('question_dataset_items', ['definition' => $dataset->id]);
                                $DB->delete_records('question_dataset_definitions', ['id' => $dataset->id]);
                            }
                        }

                        foreach ($questiontables as $table => $field) {
                            $DB->delete_records($table, [$field => $question->id]);
                        }
                        $DB->delete_records('question_versions', ['questionid' => $question->id]);
                        $DB->delete_records('question', ['id' => $question->id]);
                        $DB->delete_records('question_bank_entries', ['id' => $question->entryid]);
                        $transaction->allow_commit();
                    }

                    upgrade_set_timeout();
                }
            }

            $groups->close();
        }

        upgrade_plugin_savepoint(true, 2026102800, 'qtype', 'calculated');
    }

    return true;
}

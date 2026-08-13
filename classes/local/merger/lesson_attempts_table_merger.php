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
 * Merges lesson_attempts, lesson_branch, lesson_grades and lesson_timer together.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers\local\merger;

/**
 * Merges a lesson's attempt history (lesson_attempts, lesson_branch, lesson_grades,
 * lesson_timer) for both users, one lesson at a time.
 *
 * None of these tables has a real unique index, and 'retry' is only meaningful together
 * with 'lessonid' - conflicts are therefore resolved per lesson, not per row, and this
 * class never delegates to the generic compound-index mechanism (a single representative
 * row per group would be picked, silently losing the rest of that lesson's rows).
 *
 * lesson_grades/lesson_timer have no 'retry' column; their position for a given retry is
 * only ever derived by ORDER BY (completed/starttime respectively). RENUMBER therefore
 * only needs to touch lesson_attempts/lesson_branch's explicit 'retry' values, driven by
 * lesson_grades.completed order; lesson_grades/lesson_timer only need their owner
 * reassigned. lesson_grade() (the actual grade calculation) only ever reads
 * lesson_attempts.retry, so grading correctness does not depend on lesson_timer at all -
 * only the per-attempt "time taken" report stat does, and only in the rare case of two
 * users' sessions genuinely overlapping in time.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_attempts_table_merger extends generic_table_merger {
    /** @var string Delete lesson data from the old user on conflict. */
    const ACTION_DELETE_FROM_SOURCE = 'delete_fromid';
    /** @var string Delete lesson data from the new user on conflict. */
    const ACTION_DELETE_FROM_TARGET = 'delete_toid';
    /** @var string Merge and renumber retries chronologically on conflict. */
    const ACTION_RENUMBER = 'renumber';
    /** @var string Leave conflicting lesson data untouched, related to its original user. */
    const ACTION_REMAIN = 'remain';

    /** @var string[] tables handled directly by this merger. */
    const RELATED_TABLES = ['lesson_attempts', 'lesson_branch', 'lesson_grades', 'lesson_timer'];

    /** @var string configured action for conflicting lessons. */
    protected string $action;

    /**
     * Loads the configured action to apply on conflicting lessons.
     */
    public function __construct() {
        parent::__construct();
        $this->action = get_config('tool_mergeusers', 'lessonattemptsaction');
    }

    /**
     * This merger processes lesson_branch, lesson_grades and lesson_timer directly, so
     * they must be skipped by the generic per-table processing.
     *
     * @return array
     */
    public function get_tables_to_skip(): array {
        return ['lesson_branch', 'lesson_grades', 'lesson_timer'];
    }

    /**
     * Merges lesson data lesson by lesson, applying the configured action only where both
     * users actually have data for the same lesson.
     *
     * @param array $data details of merging (fromid, toid).
     * @param array $logs list of performed actions.
     * @param array $errors list of error messages.
     */
    public function merge($data, &$logs, &$errors): void {
        global $DB;

        $fromid = $data['fromid'];
        $toid = $data['toid'];

        $lessonids = $DB->get_records_sql(
            "SELECT DISTINCT lessonid FROM {lesson_attempts} WHERE userid = ?
             UNION SELECT DISTINCT lessonid FROM {lesson_branch} WHERE userid = ?
             UNION SELECT DISTINCT lessonid FROM {lesson_grades} WHERE userid = ?
             UNION SELECT DISTINCT lessonid FROM {lesson_timer} WHERE userid = ?",
            [$fromid, $fromid, $fromid, $fromid]
        );

        foreach (array_keys($lessonids) as $lessonid) {
            if (!$this->has_lesson_data($lessonid, $toid)) {
                $this->reassign_lesson($lessonid, $fromid, $toid, $logs, $errors);
                continue;
            }

            switch ($this->action) {
                case self::ACTION_REMAIN:
                    $logs[] = get_string('lessonattempt_action_remain_log', 'tool_mergeusers', $lessonid);
                    break;
                case self::ACTION_DELETE_FROM_SOURCE:
                    $this->delete_lesson($lessonid, $fromid, $logs, $errors);
                    break;
                case self::ACTION_DELETE_FROM_TARGET:
                    $this->delete_lesson($lessonid, $toid, $logs, $errors);
                    $this->reassign_lesson($lessonid, $fromid, $toid, $logs, $errors);
                    break;
                case self::ACTION_RENUMBER:
                    $this->renumber_lesson($lessonid, $fromid, $toid, $logs, $errors);
                    break;
            }
        }
    }

    /**
     * Whether $userid has any row for $lessonid, across the 4 related tables.
     *
     * @param int $lessonid the lesson to check.
     * @param int $userid the user to check.
     * @return bool true if $userid has at least one row for $lessonid in any related table.
     */
    protected function has_lesson_data(int $lessonid, int $userid): bool {
        global $DB;

        foreach (self::RELATED_TABLES as $table) {
            if ($DB->record_exists($table, ['lessonid' => $lessonid, 'userid' => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reassigns all of $fromid's rows for $lessonid to $toid, across the 4 related tables.
     *
     * @param int $lessonid the lesson whose rows to reassign.
     * @param int $fromid current owner of the rows.
     * @param int $toid new owner of the rows.
     * @param array $logs list of performed actions, appended to.
     * @param array $errors list of error messages, appended to.
     */
    protected function reassign_lesson(int $lessonid, int $fromid, int $toid, array &$logs, array &$errors): void {
        foreach (self::RELATED_TABLES as $table) {
            $this->reassign_table_rows($table, $lessonid, $fromid, $toid, $logs, $errors);
        }
    }

    /**
     * Reassigns one table's rows for $lessonid from $fromid to $toid.
     *
     * @param string $table table name, without prefix.
     * @param int $lessonid the lesson whose rows to reassign.
     * @param int $fromid current owner of the rows.
     * @param int $toid new owner of the rows.
     * @param array $logs list of performed actions, appended to.
     * @param array $errors list of error messages, appended to.
     */
    protected function reassign_table_rows(
        string $table,
        int $lessonid,
        int $fromid,
        int $toid,
        array &$logs,
        array &$errors,
    ): void {
        global $CFG, $DB;

        $sql = 'UPDATE ' . $CFG->prefix . $table .
            ' SET userid = ' . $toid .
            ' WHERE lessonid = ' . $lessonid . ' AND userid = ' . $fromid;
        if ($DB->execute($sql)) {
            $logs[] = $sql;
        } else {
            $errors[] = get_string('tableko', 'tool_mergeusers', $table) . ': ' . $DB->get_last_error();
        }
    }

    /**
     * Deletes all of $userid's rows for $lessonid, across the 4 related tables.
     *
     * @param int $lessonid the lesson whose rows to delete.
     * @param int $userid owner of the rows to delete.
     * @param array $logs list of performed actions, appended to.
     * @param array $errors list of error messages, appended to.
     */
    protected function delete_lesson(int $lessonid, int $userid, array &$logs, array &$errors): void {
        global $CFG, $DB;

        foreach (self::RELATED_TABLES as $table) {
            $sql = 'DELETE FROM ' . $CFG->prefix . $table .
                ' WHERE lessonid = ' . $lessonid . ' AND userid = ' . $userid;
            if ($DB->execute($sql)) {
                $logs[] = $sql;
            } else {
                $errors[] = get_string('tableko', 'tool_mergeusers', $table) . ': ' . $DB->get_last_error();
            }
        }
    }

    /**
     * Merges both users' attempts for $lessonid into one chronological retry sequence for
     * $toid.
     *
     * self::chronological_retry_entries() lists every completed retry from either user,
     * oldest first. Renumbering them in place, one at a time, risks a collision: e.g. if
     * fromid's retry 0 must become retry 1, but toid already has its own (not yet touched)
     * retry 1, the two would clash mid-way. To avoid this, lesson_attempts/lesson_branch
     * are updated in two passes (the same technique quiz_attempts_table_merger uses for its
     * 'attempt' column): first every entry moves to a temporary retry number
     * (count($entries) + its position, always higher than any real retry still untouched),
     * then a second pass renumbers those temporary values down to the final 0, 1, 2...
     * lesson_grades/lesson_timer only need their owner reassigned - they have no 'retry'
     * column of their own to renumber; reading them back ordered by completed/starttime
     * already reproduces the same chronological sequence used to build $entries.
     *
     * @param int $lessonid the lesson to renumber.
     * @param int $fromid user being removed.
     * @param int $toid user being kept.
     * @param array $logs list of performed actions, appended to.
     * @param array $errors list of error messages, appended to.
     */
    protected function renumber_lesson(int $lessonid, int $fromid, int $toid, array &$logs, array &$errors): void {
        $entries = $this->chronological_retry_entries($lessonid, $fromid, $toid);
        $offset = count($entries);

        // Phase 1: move every (owner, old retry) group to a unique, never-colliding offset slot.
        foreach ($entries as $i => $entry) {
            $this->update_retry_group($lessonid, $entry->userid, $entry->oldretry, $toid, $offset + $i, $logs, $errors);
        }
        // Phase 2: collapse the offset slots down to the final 0-indexed sequential retry.
        foreach ($entries as $i => $entry) {
            $this->update_retry_group($lessonid, $toid, $offset + $i, $toid, $i, $logs, $errors);
        }

        foreach (['lesson_grades', 'lesson_timer'] as $table) {
            $this->reassign_table_rows($table, $lessonid, $fromid, $toid, $logs, $errors);
        }
    }

    /**
     * Builds the chronological (userid, old retry) sequence for a lesson, one entry per
     * completed retry (ordered by lesson_grades.completed), plus a trailing entry for each
     * user's still-in-progress retry, if any (there can be at most one per user).
     *
     * @param int $lessonid the lesson to inspect.
     * @param int $fromid one of the two users to include.
     * @param int $toid the other user to include.
     * @return \stdClass[] each with ->userid, ->oldretry, ->order (used only to sort).
     */
    protected function chronological_retry_entries(int $lessonid, int $fromid, int $toid): array {
        global $DB;

        $entries = [];
        foreach ([$fromid, $toid] as $userid) {
            $params = ['lessonid' => $lessonid, 'userid' => $userid];
            $grades = array_values($DB->get_records('lesson_grades', $params, 'completed ASC'));
            foreach ($grades as $oldretry => $grade) {
                $entries[] = (object) ['userid' => $userid, 'oldretry' => $oldretry, 'order' => (int) $grade->completed];
            }
        }
        usort($entries, fn($a, $b) => $a->order <=> $b->order);

        // A retry beyond the graded count, if any rows exist for it, is still in progress.
        foreach ([$fromid, $toid] as $userid) {
            $gradedcount = $DB->count_records('lesson_grades', ['lessonid' => $lessonid, 'userid' => $userid]);
            if ($DB->record_exists('lesson_attempts', ['lessonid' => $lessonid, 'userid' => $userid, 'retry' => $gradedcount])) {
                $entries[] = (object) ['userid' => $userid, 'oldretry' => $gradedcount, 'order' => PHP_INT_MAX];
            }
        }

        return $entries;
    }

    /**
     * Moves one (owner, old retry) group of lesson_attempts/lesson_branch rows to a new
     * owner and retry value.
     *
     * @param int $lessonid the lesson the group belongs to.
     * @param int $fromuserid current owner of the group.
     * @param int $oldretry current retry value of the group.
     * @param int $touserid new owner of the group.
     * @param int $newretry new retry value for the group.
     * @param array $logs list of performed actions, appended to.
     * @param array $errors list of error messages, appended to.
     */
    protected function update_retry_group(
        int $lessonid,
        int $fromuserid,
        int $oldretry,
        int $touserid,
        int $newretry,
        array &$logs,
        array &$errors,
    ): void {
        global $CFG, $DB;

        foreach (['lesson_attempts', 'lesson_branch'] as $table) {
            $sql = 'UPDATE ' . $CFG->prefix . $table .
                ' SET retry = ' . $newretry . ', userid = ' . $touserid .
                ' WHERE lessonid = ' . $lessonid . ' AND userid = ' . $fromuserid . ' AND retry = ' . $oldretry;
            if ($DB->execute($sql)) {
                $logs[] = $sql;
            } else {
                $errors[] = get_string('tableko', 'tool_mergeusers', $table) . ': ' . $DB->get_last_error();
            }
        }
    }
}

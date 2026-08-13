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
 * Merges choice_answers records, accounting for mod_choice's per-instance
 * 'allowmultiple' setting.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers\local\merger;

use stdClass;

/**
 * Merges choice_answers records, accounting for mod_choice's per-instance
 * 'allowmultiple' setting.
 *
 * Whether a single user can legitimately hold more than one choice_answers
 * row for the same choiceid depends on that choice's own 'allowmultiple'
 * column, on the {choice} table, not on choice_answers itself. When
 * allowmultiple is off, a user may have at most one row per choiceid,
 * regardless of optionid (mod/choice/lib.php's choice_user_outline() reads
 * it with a plain, singular get_record(), which - with more than one
 * matching row - silently returns only the first one and logs a
 * debugging() notice, rather than reporting the user's real answer). When
 * allowmultiple is on, a user may legitimately hold several rows per
 * choiceid, one per optionid selected, and those must not be collapsed
 * into each other.
 *
 * A single static 'otherfields' list cannot express both cases at once, so
 * this table_merger groups records by choiceid alone when allowmultiple is
 * off, and by (choiceid, optionid) when allowmultiple is on.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_answers_table_merger extends generic_table_merger {
    /**
     * Return empty array. It has no other tables rather than 'choice_answers' to process.
     *
     * The JOIN with {choice} added by self::build_sql_query() is read-only lookup data,
     * not a table this merger updates, so there is nothing else to skip here.
     *
     * @return array Empty list.
     */
    public function get_tables_to_skip(): array {
        return [];
    }

    /**
     * Generates an SQL query that also fetches the owning choice's 'allowmultiple' value,
     * needed by self::build_group_key() to decide the real uniqueness key per record.
     *
     * Uses a LEFT JOIN, not an INNER JOIN: Moodle does not enforce this foreign key at the
     * database level, so a choice_answers row could in principle point to a choice that no
     * longer exists. An INNER JOIN would silently exclude such orphaned rows from conflict
     * detection entirely, defeating the purpose of this merger for them.
     *
     * @param array $data Array containing the table name, `fromid`, and `toid` for the merging operation.
     * @param string $userfield The field name in the table that refers to the user ID.
     * @param string $otherfieldsstr A string representing the other fields in the compound index, separated by commas.
     * @return array Array with the form [$sql, $params] to be used.
     */
    protected function build_sql_query(array $data, string $userfield, string $otherfieldsstr): array {
        $sql = 'SELECT ca.id, ca.' . $userfield . ', ' . $otherfieldsstr . ', c.allowmultiple' .
            ' FROM {' . $data['tableName'] . '} ca' .
            ' LEFT JOIN {choice} c ON c.id = ca.choiceid' .
            ' WHERE ca.' . $userfield . ' IN ( ?, ?)';

        return [$sql, [$data['fromid'], $data['toid']]];
    }

    /**
     * Builds the grouping key for a choice_answers record: choiceid alone when the owning
     * choice does not allow multiple answers per user, or (choiceid, optionid) when it does.
     *
     * A missing (null) 'allowmultiple' - an orphaned row whose choice no longer exists, per
     * the LEFT JOIN in self::build_sql_query() - is treated the same as 0 (single-select),
     * the stricter and safer interpretation.
     *
     * @param stdClass $resobj the record being grouped, including the joined 'allowmultiple' column.
     * @param array $otherfields table's field names that refer to the other members of the compound index.
     * @return string the grouping key for this record.
     */
    protected function build_group_key(stdClass $resobj, array $otherfields): string {
        if ((int) ($resobj->allowmultiple ?? 0)) {
            return parent::build_group_key($resobj, $otherfields);
        }

        return (string) $resobj->choiceid;
    }
}

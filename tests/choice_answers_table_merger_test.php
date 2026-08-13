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

namespace tool_mergeusers;

use tool_mergeusers\local\jsonizer;
use tool_mergeusers\local\merger\generic_table_merger;
use tool_mergeusers\local\user_merger;

/**
 * Tests for choice_answers_table_merger class.
 *
 * @package    tool_mergeusers
 * @author     Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright  2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class choice_answers_table_merger_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $userkeep;
    /** @var \stdClass */
    private $userremove;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG;
        require_once($CFG->dirroot . '/mod/choice/lib.php');

        $this->course = $this->getDataGenerator()->create_course();
        $this->userkeep = $this->getDataGenerator()->create_user(['firstname' => 'Kept']);
        $this->userremove = $this->getDataGenerator()->create_user(['firstname' => 'Removed']);
        $this->getDataGenerator()->enrol_user($this->userkeep->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->userremove->id, $this->course->id, 'student');
    }

    /**
     * When a choice does not allow multiple answers, two users who each picked a different
     * option for the same choice must be merged down to a single answer, so that
     * choice_user_outline() reports the merged user's real answer instead of silently
     * returning an arbitrary one of the duplicates.
     *
     * @see self::test_without_specialized_merger_allowmultiple_off_leaves_inconsistency() for
     * the same scenario left unresolved when choice_answers_table_merger is not used.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_choice_answers
     * @covers \tool_mergeusers\local\merger\choice_answers_table_merger::build_group_key
     * @covers \tool_mergeusers\local\merger\choice_answers_table_merger::build_sql_query
     */
    public function test_single_select_choice_merges_to_one_answer(): void {
        global $DB;

        $choice = $this->create_single_select_conflicting_scenario();

        $mut = new user_merger();
        $mut->merge($this->userkeep->id, $this->userremove->id);

        $answers = $DB->get_records('choice_answers', ['choiceid' => $choice->id, 'userid' => $this->userkeep->id]);
        $this->assertCount(1, $answers);
        $this->assertFalse(
            $DB->record_exists('choice_answers', ['choiceid' => $choice->id, 'userid' => $this->userremove->id])
        );

        $cm = get_coursemodule_from_id('choice', $choice->cmid);
        $outline = choice_user_outline($this->course, $this->userkeep, $cm, $choice);
        $this->assertNotNull($outline);
    }

    /**
     * Demonstrates why choice_answers_table_merger is needed at all: with the plain
     * generic_table_merger (grouping strictly by the static (choiceid, optionid)
     * otherfields), two users who picked different options on a single-select choice are
     * never recognised as conflicting, so both answers survive the merge. get_record()'s
     * default strictness does not throw on this - it silently returns an arbitrary one of
     * the two rows (and logs a debugging() notice), so choice_user_outline() ends up
     * reporting a wrong answer instead of the merged user's real one.
     *
     * @see self::test_single_select_choice_merges_to_one_answer() for the same scenario
     * resolved correctly with the default configuration (choice_answers_table_merger active).
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_choice_answers
     * @covers \tool_mergeusers\local\merger\choice_answers_table_merger
     */
    public function test_without_specialized_merger_allowmultiple_off_leaves_inconsistency(): void {
        global $DB;

        set_config(
            'customdbsettings',
            jsonizer::to_json(['tablemergers' => ['choice_answers' => generic_table_merger::class]]),
            'tool_mergeusers'
        );

        $choice = $this->create_single_select_conflicting_scenario();

        $mut = new user_merger();
        $mut->merge($this->userkeep->id, $this->userremove->id);

        $this->assertCount(
            2,
            $DB->get_records('choice_answers', ['choiceid' => $choice->id, 'userid' => $this->userkeep->id])
        );

        $cm = get_coursemodule_from_id('choice', $choice->cmid);
        $outline = choice_user_outline($this->course, $this->userkeep, $cm, $choice);
        $this->assertNotNull($outline);
        $this->assertDebuggingCalled('Error: mdb->get_record() found more than one record!');
    }

    /**
     * Creates a single-select choice (allowmultiple=0) with two conflicting answers: the
     * user to remove picks one option, the user to keep picks a different one - a scenario
     * that should never exist for a single user after a correct merge.
     *
     * @return \stdClass the created choice instance.
     */
    private function create_single_select_conflicting_scenario(): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_choice');
        $choice = $generator->create_instance([
            'course' => $this->course->id,
            'allowmultiple' => 0,
            'option' => ['Tea', 'Coffee'],
        ]);
        $options = array_values($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id'));

        $generator->create_response([
            'choiceid' => $choice->id,
            'responses' => $options[0]->id,
            'userid' => $this->userremove->id,
        ]);
        $generator->create_response([
            'choiceid' => $choice->id,
            'responses' => $options[1]->id,
            'userid' => $this->userkeep->id,
        ]);

        return $choice;
    }

    /**
     * When a choice allows multiple answers, a user's own distinct selections must all
     * survive the merge, while any selection colliding with the other user's own choice
     * of the very same option must be deduplicated to a single row.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_choice_answers
     * @covers \tool_mergeusers\local\merger\choice_answers_table_merger::build_group_key
     * @covers \tool_mergeusers\local\merger\choice_answers_table_merger::build_sql_query
     */
    public function test_multi_select_choice_keeps_distinct_selections_and_dedupes_conflicts(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_choice');
        $choice = $generator->create_instance([
            'course' => $this->course->id,
            'allowmultiple' => 1,
            'option' => ['Tea', 'Coffee', 'Juice'],
        ]);
        $options = array_values($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id'));

        // The user to remove has two legitimate selections of their own.
        $generator->create_response([
            'choiceid' => $choice->id,
            'responses' => [$options[0]->id, $options[1]->id],
            'userid' => $this->userremove->id,
        ]);
        // The user to keep selected the same first option as the user to remove: a real conflict.
        $generator->create_response([
            'choiceid' => $choice->id,
            'responses' => $options[0]->id,
            'userid' => $this->userkeep->id,
        ]);

        $mut = new user_merger();
        $mut->merge($this->userkeep->id, $this->userremove->id);

        $answers = $DB->get_records('choice_answers', ['choiceid' => $choice->id, 'userid' => $this->userkeep->id]);
        $this->assertCount(2, $answers);

        $optionids = array_values(array_map(fn($answer) => (int) $answer->optionid, $answers));
        sort($optionids);
        $expected = [(int) $options[0]->id, (int) $options[1]->id];
        sort($expected);
        $this->assertEquals($expected, $optionids);

        $this->assertCount(
            0,
            $DB->get_records('choice_answers', ['choiceid' => $choice->id, 'userid' => $this->userremove->id])
        );
    }
}

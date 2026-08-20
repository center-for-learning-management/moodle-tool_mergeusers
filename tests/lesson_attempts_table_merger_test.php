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
use tool_mergeusers\local\merger\lesson_attempts_table_merger;
use tool_mergeusers\local\user_merger;

/**
 * Tests for lesson_attempts_table_merger class.
 *
 * @package    tool_mergeusers
 * @author     Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright  2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lesson_attempts_table_merger_test extends \advanced_testcase {
    /** @var \stdClass */
    private $userkeep;
    /** @var \stdClass */
    private $userremove;
    /** @var \stdClass */
    private $lesson;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG;
        require_once($CFG->dirroot . '/mod/lesson/lib.php');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->userkeep = $this->getDataGenerator()->create_user(['firstname' => 'Kept']);
        $this->userremove = $this->getDataGenerator()->create_user(['firstname' => 'Removed']);
        $this->lesson = $this->create_lesson($course->id);
    }

    /**
     * A lesson only one of the two users has data for is always reassigned, regardless of
     * the configured action (there is no conflict to resolve).
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger::reassign_lesson
     */
    public function test_no_conflict_reassigns_regardless_of_action(): void {
        set_config('lessonattemptsaction', lesson_attempts_table_merger::ACTION_REMAIN, 'tool_mergeusers');
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);

        $this->merge();

        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userkeep->id, 1);
        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userremove->id, 0);
    }

    /**
     * REMAIN leaves a conflicting lesson's data untouched, related to its original user.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger::merge
     */
    public function test_remain_leaves_conflicting_lesson_untouched(): void {
        set_config('lessonattemptsaction', lesson_attempts_table_merger::ACTION_REMAIN, 'tool_mergeusers');
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);
        $this->create_attempt($this->lesson->id, $this->userkeep->id, 0, 90, 2000);

        $this->merge();

        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userremove->id, 1);
        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userkeep->id, 1);
    }

    /**
     * DELETE_FROM_SOURCE drops the old user's conflicting lesson data entirely.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger::delete_lesson
     */
    public function test_delete_from_source_removes_old_users_conflicting_data(): void {
        set_config('lessonattemptsaction', lesson_attempts_table_merger::ACTION_DELETE_FROM_SOURCE, 'tool_mergeusers');
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);
        $this->create_attempt($this->lesson->id, $this->userkeep->id, 0, 90, 2000);

        $this->merge();

        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userremove->id, 0);
        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userkeep->id, 1);
    }

    /**
     * DELETE_FROM_TARGET drops the new user's conflicting lesson data, keeping the old
     * user's data reassigned instead.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger::delete_lesson
     */
    public function test_delete_from_target_removes_new_users_conflicting_data(): void {
        global $DB;

        set_config('lessonattemptsaction', lesson_attempts_table_merger::ACTION_DELETE_FROM_TARGET, 'tool_mergeusers');
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);
        $this->create_attempt($this->lesson->id, $this->userkeep->id, 0, 90, 2000);

        $this->merge();

        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userremove->id, 0);
        $grade = $DB->get_record('lesson_grades', ['lessonid' => $this->lesson->id, 'userid' => $this->userkeep->id]);
        $this->assertEquals(80, (int) $grade->grade);
    }

    /**
     * RENUMBER merges both users' attempts, renumbering retry chronologically by
     * lesson_grades.completed, and grading correctly reflects the combined history.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger::renumber_lesson
     */
    public function test_renumber_merges_and_renumbers_chronologically(): void {
        global $DB;

        set_config('lessonattemptsaction', lesson_attempts_table_merger::ACTION_RENUMBER, 'tool_mergeusers');
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);
        $this->create_attempt($this->lesson->id, $this->userkeep->id, 0, 90, 2000);

        $this->merge();

        $this->assert_lesson_owner_and_count($this->lesson->id, $this->userremove->id, 0);

        $attempts = array_values(
            $DB->get_records('lesson_attempts', ['lessonid' => $this->lesson->id, 'userid' => $this->userkeep->id], 'retry')
        );
        $this->assertEquals([0, 1], array_map(fn($a) => (int) $a->retry, $attempts));

        $branches = array_values(
            $DB->get_records('lesson_branch', ['lessonid' => $this->lesson->id, 'userid' => $this->userkeep->id], 'retry')
        );
        $this->assertEquals([0, 1], array_map(fn($b) => (int) $b->retry, $branches));

        $grades = array_values(
            $DB->get_records('lesson_grades', ['lessonid' => $this->lesson->id, 'userid' => $this->userkeep->id], 'completed')
        );
        $this->assertEquals([80, 90], array_map(fn($g) => (int) $g->grade, $grades));

        $usergrades = lesson_get_user_grades($this->lesson, $this->userkeep->id);
        $this->assertEqualsWithDelta(85.0, current($usergrades)->rawgrade, 0.01);
    }

    /**
     * With no lessonattemptsaction ever saved, get_config() returns false; the merger must
     * still work, falling back to RENUMBER rather than crashing on the typed property.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger::__construct
     */
    public function test_falls_back_to_renumber_when_config_unset(): void {
        global $DB;

        unset_config('lessonattemptsaction', 'tool_mergeusers');
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);
        $this->create_attempt($this->lesson->id, $this->userkeep->id, 0, 90, 2000);

        $this->merge();

        $attempts = array_values(
            $DB->get_records('lesson_attempts', ['lessonid' => $this->lesson->id, 'userid' => $this->userkeep->id], 'retry')
        );
        $this->assertEquals([0, 1], array_map(fn($a) => (int) $a->retry, $attempts));
    }

    /**
     * Demonstrates why lesson_attempts_table_merger is needed: with plain
     * generic_table_merger, both users' retry=0 rows collide under the same retry value.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_lesson_attempts
     * @covers \tool_mergeusers\local\merger\lesson_attempts_table_merger
     */
    public function test_without_specialized_merger_leaves_duplicate_retries(): void {
        global $DB;

        set_config(
            'customdbsettings',
            jsonizer::to_json(['tablemergers' => ['lesson_attempts' => generic_table_merger::class]]),
            'tool_mergeusers'
        );
        $this->create_attempt($this->lesson->id, $this->userremove->id, 0, 80, 1000);
        $this->create_attempt($this->lesson->id, $this->userkeep->id, 0, 90, 2000);

        $this->merge();

        $this->assertCount(
            2,
            $DB->get_records('lesson_attempts', ['lessonid' => $this->lesson->id, 'userid' => $this->userkeep->id, 'retry' => 0])
        );
    }

    /**
     * Merges userremove into userkeep.
     */
    private function merge(): void {
        (new user_merger())->merge($this->userkeep->id, $this->userremove->id);
    }

    /**
     * Asserts $userid has exactly $count rows in each of the 4 lesson tables for $lessonid.
     *
     * @param int $lessonid
     * @param int $userid
     * @param int $count expected row count per table.
     */
    private function assert_lesson_owner_and_count(int $lessonid, int $userid, int $count): void {
        global $DB;

        foreach (['lesson_attempts', 'lesson_branch', 'lesson_grades', 'lesson_timer'] as $table) {
            $this->assertCount($count, $DB->get_records($table, ['lessonid' => $lessonid, 'userid' => $userid]), $table);
        }
    }

    /**
     * Creates a real lesson instance via the core generator (this merger needs no course
     * module, but the {lesson} row itself has several required fields best left to it).
     *
     * @param int $courseid
     * @return \stdClass the created lesson.
     */
    private function create_lesson(int $courseid): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        return $generator->create_instance(['course' => $courseid, 'retake' => 1, 'usemaxgrade' => 0]);
    }

    /**
     * Creates one complete retry's worth of rows across the 4 related tables.
     *
     * @param int $lessonid
     * @param int $userid
     * @param int $retry
     * @param int $grade
     * @param int $completed
     */
    private function create_attempt(int $lessonid, int $userid, int $retry, int $grade, int $completed): void {
        global $DB;

        $DB->insert_record('lesson_attempts', (object) [
            'lessonid' => $lessonid, 'pageid' => 1, 'userid' => $userid, 'answerid' => 1,
            'retry' => $retry, 'correct' => 1, 'useranswer' => '', 'timeseen' => $completed,
        ]);
        $DB->insert_record('lesson_branch', (object) [
            'lessonid' => $lessonid, 'userid' => $userid, 'pageid' => 1,
            'retry' => $retry, 'flag' => 0, 'timeseen' => $completed, 'nextpageid' => 0,
        ]);
        $DB->insert_record('lesson_grades', (object) [
            'lessonid' => $lessonid, 'userid' => $userid, 'grade' => $grade, 'late' => 0, 'completed' => $completed,
        ]);
        $DB->insert_record('lesson_timer', (object) [
            'lessonid' => $lessonid, 'userid' => $userid, 'starttime' => $completed - 5,
            'lessontime' => $completed, 'completed' => 1, 'timemodifiedoffline' => 0,
        ]);
    }
}

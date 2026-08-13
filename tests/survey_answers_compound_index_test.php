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

use tool_mergeusers\local\user_merger;

/**
 * Tests for the survey_answers compound index configuration.
 *
 * mod_survey was removed from Moodle core after 4.5, but this plugin still declares support
 * for 4.5. On a Moodle version where mod_survey (and its survey_answers table) is not
 * installed, these tests are skipped rather than failed.
 *
 * @package    tool_mergeusers
 * @author     Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright  2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class survey_answers_compound_index_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('survey') || !$dbman->table_exists('survey_answers')) {
            $this->markTestSkipped('mod_survey is not installed on this Moodle version.');
        }
    }

    /**
     * Two users answering the same survey question must be merged down to a single answer,
     * instead of leaving the merged user with two answers to the same question, which would
     * silently double-count in survey results reports.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_survey_answers
     * @covers \tool_mergeusers\local\merger\generic_table_merger::merge_compound_index
     */
    public function test_merge_deduplicates_same_question_answer(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $userkeep = $this->getDataGenerator()->create_user(['firstname' => 'Kept']);
        $userremove = $this->getDataGenerator()->create_user(['firstname' => 'Removed']);

        $surveyid = $DB->insert_record('survey', (object) [
            'course' => $course->id,
            'template' => 0,
            'days' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'name' => 'Test survey',
            'intro' => '',
            'introformat' => FORMAT_MOODLE,
            'questions' => '1',
            'completionsubmit' => 0,
        ]);

        $DB->insert_record('survey_answers', (object) [
            'userid' => $userremove->id,
            'survey' => $surveyid,
            'question' => 1,
            'time' => time(),
            'answer1' => 'Answer from removed user',
            'answer2' => '',
        ]);
        $DB->insert_record('survey_answers', (object) [
            'userid' => $userkeep->id,
            'survey' => $surveyid,
            'question' => 1,
            'time' => time(),
            'answer1' => 'Answer from kept user',
            'answer2' => '',
        ]);

        $mut = new user_merger();
        $mut->merge($userkeep->id, $userremove->id);

        $answers = $DB->get_records('survey_answers', ['survey' => $surveyid, 'userid' => $userkeep->id]);
        $this->assertCount(1, $answers);
        $this->assertFalse(
            $DB->record_exists('survey_answers', ['survey' => $surveyid, 'userid' => $userremove->id])
        );
    }

    /**
     * A user's own legitimate answers to different questions of the same survey must all
     * survive the merge unaffected.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_survey_answers
     * @covers \tool_mergeusers\local\merger\generic_table_merger::merge_compound_index
     */
    public function test_merge_keeps_answers_to_different_questions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $userkeep = $this->getDataGenerator()->create_user(['firstname' => 'Kept']);
        $userremove = $this->getDataGenerator()->create_user(['firstname' => 'Removed']);

        $surveyid = $DB->insert_record('survey', (object) [
            'course' => $course->id,
            'template' => 0,
            'days' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'name' => 'Test survey',
            'intro' => '',
            'introformat' => FORMAT_MOODLE,
            'questions' => '1,2',
            'completionsubmit' => 0,
        ]);

        $DB->insert_record('survey_answers', (object) [
            'userid' => $userremove->id,
            'survey' => $surveyid,
            'question' => 1,
            'time' => time(),
            'answer1' => 'Answer to question 1',
            'answer2' => '',
        ]);
        $DB->insert_record('survey_answers', (object) [
            'userid' => $userkeep->id,
            'survey' => $surveyid,
            'question' => 2,
            'time' => time(),
            'answer1' => 'Answer to question 2',
            'answer2' => '',
        ]);

        $mut = new user_merger();
        $mut->merge($userkeep->id, $userremove->id);

        $answers = $DB->get_records('survey_answers', ['survey' => $surveyid, 'userid' => $userkeep->id]);
        $this->assertCount(2, $answers);
    }
}

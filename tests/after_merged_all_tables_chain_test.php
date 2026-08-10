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
 * Integration test for the whole production chain of after_merged_all_tables callbacks.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers;

use advanced_testcase;
use completion_completion;
use grade_item;
use stdClass;
use tool_mergeusers\local\user_merger;

/**
 * Runs a single real merge (no DI-swapped hook manager) with data set up for every
 * currently registered {@see \tool_mergeusers\hook\after_merged_all_tables} callback
 * (regrading and course completion reaggregation - see db/hooks.php), to prove the
 * whole chain executes correctly together.
 *
 * This matters because {@see \core\hook\manager::dispatch()} calls each callback in
 * priority order with no isolation: an uncaught exception from a higher-priority
 * callback stops every lower-priority one from ever running.
 *
 * Note: profile picture merging is NOT part of this hook chain - it runs afterwards,
 * from the post-commit `user_merged_success` event observer instead (see
 * {@see \tool_mergeusers\local\observer\olduser} and
 * {@see \tool_mergeusers\local\picture_merger}), precisely so it never does any work
 * on a merge that might still be rolled back. Its own "runs together with the rest of
 * a real merge" coverage lives in `picture_merger_integration_test.php`.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \tool_mergeusers\local\user_merger
 */
final class after_merged_all_tables_chain_test extends advanced_testcase {
    /**
     * Every registered after_merged_all_tables callback runs, in priority order, and
     * none of them is skipped or blocked by another during a single real merge.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_after_merge_hook
     */
    public function test_full_hook_chain_runs_without_interference(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $fromuser = $this->getDataGenerator()->create_user();
        $touser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($fromuser->id, $course->id);
        $this->getDataGenerator()->enrol_user($touser->id, $course->id);

        // Data for the regrading callback: an assignment with grades for both users.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        require_once($CFG->libdir . '/gradelib.php');
        grade_update('mod/assign', $course->id, 'mod', 'assign', $assign->id, 0, ['userid' => $fromuser->id, 'rawgrade' => 80.0]);
        $this->assertNotEmpty(grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $course->id,
        ]));

        // Data for the course completion reaggregation callback: an in-progress completion for fromuser.
        $data = $this->getDataGenerator()->create_module('data', ['course' => $course->id], ['completion' => 1]);
        $cmdata = get_coursemodule_from_id('data', $data->cmid);
        $criteriadata = new stdClass();
        $criteriadata->id = $course->id;
        $criteriadata->criteria_activity = [$cmdata->id => 1];
        (new \completion_criteria_activity())->update_config($criteriadata);

        $completioncriteria = $DB->get_record('course_completion_criteria', []);
        $DB->insert_records('course_modules_completion', [(object) [
            'coursemoduleid' => $cmdata->id,
            'userid' => $fromuser->id,
            'completionstate' => 1,
            'viewed' => 0,
            'overrideby' => null,
            'timemodified' => 0,
        ]]);
        $DB->insert_records('course_completion_crit_compl', [(object) [
            'criteriaid' => $completioncriteria->id,
            'userid' => $fromuser->id,
            'timecompleted' => 0,
        ]]);
        $ccompletion = new completion_completion(['course' => $course->id, 'userid' => $fromuser->id]);
        $time = time();
        $ccompletion->mark_inprogress($time);
        // MDL-33320: for instant completions we need aggregate to work in a single run.
        $DB->set_field('course_completions', 'reaggregate', $time - 2);
        aggregate_completions(0);

        // A single real merge: the hook manager is NOT swapped out, so every callback
        // registered in db/hooks.php (regrading, update_completion) fires.
        $mut = new user_merger();
        [$success, $logs, $logid] = $mut->merge($touser->id, $fromuser->id);

        $this->assertTrue($success, 'Merge failed: ' . implode(' | ', $logs));

        $this->assert_log_contains($logs, 'Regraded grade item', 'regrading callback');
        $this->assert_log_contains($logs, 'Course completion reaggregated for user', 'course completion callback');

        // Final state confirms each callback's own effect actually landed, not just its log line.
        $this->assertNotEmpty(grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $course->id,
        ]));
        $this->assertFalse($DB->get_record('course_completions', ['userid' => $fromuser->id]));
        $this->assertIsObject($DB->get_record('course_completions', ['userid' => $touser->id]));
    }

    /**
     * Asserts that at least one log line contains the given needle.
     *
     * @param array $logs
     * @param string $needle
     * @param string $callbackdescription Used only to build a helpful failure message.
     * @return void
     */
    private function assert_log_contains(array $logs, string $needle, string $callbackdescription): void {
        foreach ($logs as $log) {
            if (strpos($log, $needle) !== false) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail(sprintf(
            'Expected a log line from the %s (containing "%s") but none was found. Full log: %s',
            $callbackdescription,
            $needle,
            implode(' | ', $logs)
        ));
    }
}

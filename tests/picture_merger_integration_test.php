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
 * Integration tests for profile picture merging as part of a real, full user_merger::merge() call.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers;

use advanced_testcase;
use context_user;
use Exception;
use tool_mergeusers\local\config;
use tool_mergeusers\local\logger;
use tool_mergeusers\local\user_merger;

/**
 * Profile picture merging (see {@see \tool_mergeusers\local\picture_merger}) runs from the
 * post-commit `user_merged_success` event observer, not from the pre-commit
 * `after_merged_all_tables` hook chain (covered instead by `after_merged_all_tables_chain_test.php`).
 * These tests exercise it through a real, unmodified `user_merger::merge()` call, without swapping
 * out any Moodle internals, to prove:
 *
 * 1. It genuinely runs as part of a real merge, and its outcome is visible in the SAME persisted
 *    merge log an administrator would see on log.php - even though, unlike the previous hook-based
 *    implementation, its log lines are no longer part of the array `merge()` itself returns (they are
 *    appended afterwards, from the observer, via {@see logger::update_log_status()}).
 * 2. It never runs at all when the merge does not reach `user_merged_success` - reproduced here via
 *    the CLI `--alwaysrollback` option, which forces `merge()` to throw before ever committing.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \tool_mergeusers\local\user_merger
 * @covers    \tool_mergeusers\local\observer\olduser
 * @covers    \tool_mergeusers\local\picture_merger
 */
final class picture_merger_integration_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function tearDown(): void {
        $config = config::instance();
        unset($config->alwaysrollback);
        parent::tearDown();
    }

    /**
     * A real merge copies the removed user's picture onto the kept user, and the outcome is visible
     * in the merge's own persisted log (log.php), even though it happened after merge() itself had
     * already returned its own $logs array.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_real_merge_persists_picture_outcome_to_the_merge_log(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');

        $touser = $this->getDataGenerator()->create_user();
        $fromuser = $this->getDataGenerator()->create_user();

        $imagepath = __DIR__ . '/fixtures/user_picture.png';
        $this->assertFileExists($imagepath);
        $newrev = process_new_icon(context_user::instance($fromuser->id), 'user', 'icon', 0, $imagepath);
        $this->assertNotFalse($newrev);
        $DB->set_field('user', 'picture', $newrev, ['id' => $fromuser->id]);

        $mut = new user_merger();
        [$success, $logs, $logid] = $mut->merge($touser->id, $fromuser->id);
        $this->assertTrue($success, 'Merge failed: ' . implode(' | ', $logs));

        // The kept user now holds the picture captured before the merge...
        $this->assertEquals($newrev, $DB->get_field('user', 'picture', ['id' => $touser->id]));
        // ... and the removed user's own picture was, separately, overwritten with the generic
        // "suspended" placeholder by the pre-existing, unrelated suspend step (tool_mergeusers/
        // suspenduser is enabled by default) - proof the picture merge ran strictly before it.
        $this->assertNotEquals($newrev, $DB->get_field('user', 'picture', ['id' => $fromuser->id]));

        // The $logs array returned by merge() does NOT include the picture outcome: that only gets
        // appended afterwards, from the post-commit observer.
        $this->assertFalse($this->log_contains($logs, 'copied from user'));

        // But it IS visible in the merge's own persisted log, exactly as an administrator reviewing
        // log.php would see it, together with the raw suspendedplaceholderpicture bookkeeping field.
        $logger = new logger();
        $persisted = $logger->detail_from($logid);
        $this->assertTrue($this->log_contains((array) $persisted->log->actions, 'copied from user'));
        $this->assertEquals(
            $DB->get_field('user', 'picture', ['id' => $fromuser->id]),
            $persisted->log->suspendedplaceholderpicture
        );
    }

    /**
     * Reproduces, end to end, the exact scenario Jordi found manually: merges for the same removed
     * user are deliberately repeated (both from the web, when Moodle activity trickles in on a
     * suspended user mid-migration, and always twice for CLI/unattended merges, by this plugin's own
     * documented operating practice) - to sweep up any stragglers. Before this fix, a second merge of
     * an already-suspended user would see its own placeholder picture and wrongly copy it onto the
     * new kept user. With this fix, that picture is recognised as this plugin's own placeholder and
     * ignored, exactly as if the removed user had no picture at all.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_repeated_merge_after_suspension_does_not_propagate_placeholder(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $userc = $this->getDataGenerator()->create_user();

        $imagepath = __DIR__ . '/fixtures/user_picture.png';
        $newrev = process_new_icon(context_user::instance($usera->id), 'user', 'icon', 0, $imagepath);
        $this->assertNotFalse($newrev);
        $DB->set_field('user', 'picture', $newrev, ['id' => $usera->id]);

        // First merge: A (with a real picture) into B (with none). B gets A's real picture; A gets
        // suspended and its own picture replaced by the generic placeholder.
        $mut = new user_merger();
        [$success] = $mut->merge($userb->id, $usera->id);
        $this->assertTrue($success);
        $this->assertEquals($newrev, $DB->get_field('user', 'picture', ['id' => $userb->id]));
        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $usera->id]));
        $aplaceholder = (int) $DB->get_field('user', 'picture', ['id' => $usera->id]);
        $this->assertNotEquals($newrev, $aplaceholder);
        $this->assertGreaterThan(0, $aplaceholder);

        // Second, repeated merge: A (still suspended, still carrying the placeholder from the merge
        // above) is merged again, this time into C, to sweep up any stragglers left behind. C must
        // NOT end up with A's placeholder image.
        [$success] = $mut->merge($userc->id, $usera->id);
        $this->assertTrue($success);
        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $userc->id]));
    }

    /**
     * With --alwaysrollback set, merge() throws before ever committing, so user_merged_success never
     * fires: neither user's picture is ever touched, confirming this is structural (nothing to roll
     * back after the fact), not merely something extra checks happen to prevent.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_alwaysrollback_never_touches_any_picture(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');

        $touser = $this->getDataGenerator()->create_user();
        $fromuser = $this->getDataGenerator()->create_user();

        $imagepath = __DIR__ . '/fixtures/user_picture.png';
        $newrev = process_new_icon(context_user::instance($fromuser->id), 'user', 'icon', 0, $imagepath);
        $this->assertNotFalse($newrev);
        $DB->set_field('user', 'picture', $newrev, ['id' => $fromuser->id]);

        $config = config::instance();
        $config->alwaysrollback = true;
        $mut = new user_merger($config);

        $thrown = null;
        try {
            $mut->merge($touser->id, $fromuser->id);
        } catch (Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'merge() was expected to throw with alwaysrollback set.');
        $this->assertStringContainsString('alwaysrollback', $thrown->getMessage());

        // Neither user's picture was touched: the removed user still has its original picture...
        $this->assertEquals($newrev, $DB->get_field('user', 'picture', ['id' => $fromuser->id]));
        // ... and the kept user was never given a copy of it.
        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $touser->id]));
        // Not suspended either: the whole post-commit observer chain never ran.
        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $fromuser->id]));
    }

    /**
     * Whether any log line contains the given needle.
     *
     * @param array $logs
     * @param string $needle
     * @return bool
     */
    private function log_contains(array $logs, string $needle): bool {
        foreach ($logs as $log) {
            if (strpos($log, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}

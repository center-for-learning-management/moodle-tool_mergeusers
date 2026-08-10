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
 * Tests for the profile picture merging logic.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers;

use advanced_testcase;
use context_user;
use ReflectionMethod;
use tool_mergeusers\local\logger;
use tool_mergeusers\local\picture_merger;

/**
 * Tests for {@see picture_merger}.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \tool_mergeusers\local\picture_merger
 */
final class picture_merger_test extends advanced_testcase {
    /** @var object User to keep. */
    private object $touser;

    /** @var object User to remove. */
    private object $fromuser;

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->touser = $this->getDataGenerator()->create_user();
        $this->fromuser = $this->getDataGenerator()->create_user();

        set_config('mergepicture', 1, 'tool_mergeusers');
        set_config('uniquekeynewidtomaintain', 1, 'tool_mergeusers');
    }

    /**
     * Sets a real profile picture, from the bundled test fixture image, on the given user.
     *
     * @param int $userid
     * @return int the resulting user.picture value.
     */
    private function set_user_picture(int $userid): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');

        $imagepath = __DIR__ . '/fixtures/user_picture.png';
        $this->assertFileExists($imagepath, 'Test fixture image is missing.');

        $context = context_user::instance($userid);
        $newrev = process_new_icon($context, 'user', 'icon', 0, $imagepath);
        $this->assertNotFalse($newrev, 'process_new_icon() failed to set up the fixture picture.');
        $DB->set_field('user', 'picture', $newrev, ['id' => $userid]);

        return (int) $newrev;
    }

    /**
     * Records, directly in the tool_mergeusers log table, a past successful merge where $userid was
     * the removed user ("fromuserid") and its own picture was overwritten with the given placeholder
     * value - exactly what olduser::old_user_suspend() would have recorded for a real earlier merge.
     *
     * @param int $userid
     * @param int $placeholderpicture
     * @return void
     */
    private function record_past_placeholder_merge(int $userid, int $placeholderpicture): void {
        $logger = new logger();
        $someotherid = $this->getDataGenerator()->create_user()->id;
        $logger->log($someotherid, $userid, true, ['(a previous, unrelated merge)'], 'success', null, null, $placeholderpicture);
    }

    /**
     * Whether any log line contains the given needle.
     *
     * A fixed, distinguishing substring is used per test (instead of reconstructing the whole lang
     * string) because every one of this class's log strings shares the same "Profile picture: "
     * prefix, and "picturemergefailed" additionally interpolates a runtime exception message that
     * must not be hardcoded here.
     *
     * @param array $actions
     * @param string $needle
     * @return bool
     */
    private function log_contains(array $actions, string $needle): bool {
        foreach ($actions as $action) {
            if (strpos($action, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the content hash of the given user's profile icon file, or null if none.
     *
     * @param int $userid
     * @return string|null
     */
    private function get_icon_contenthash(int $userid): ?string {
        $fs = get_file_storage();
        $context = context_user::instance($userid);
        $files = $fs->get_area_files($context->id, 'user', 'icon', 0, 'itemid, filepath, filename', false);
        $file = reset($files);

        return $file ? $file->get_contenthash() : null;
    }

    /**
     * When the "Merge profile picture" setting is disabled, nothing is copied and the
     * reason is logged, even if there would otherwise be something to merge.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_disabled_setting_skips_and_logs(): void {
        global $DB;

        set_config('mergepicture', 0, 'tool_mergeusers');
        $this->set_user_picture($this->fromuser->id);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        $this->assertTrue($this->log_contains($actions, 'merging is disabled'));
    }

    /**
     * Default setting (keep new user's data): if the kept user already has a picture,
     * it is kept as-is and nothing is copied, regardless of the removed user's picture.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_kept_user_already_winner_noop(): void {
        global $DB;

        $this->set_user_picture($this->touser->id);
        $this->set_user_picture($this->fromuser->id);
        $originalpicture = $DB->get_field('user', 'picture', ['id' => $this->touser->id]);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertEquals($originalpicture, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        $this->assertTrue($this->log_contains($actions, 'already has the picture that must prevail'));
    }

    /**
     * Default setting: if the kept user has no picture but the removed user does, the
     * removed user's picture is copied onto the kept user - using a real fixture image
     * to verify the actual file content (not just the "picture" flag) is copied intact.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_copies_from_removed_user_when_kept_user_has_none(): void {
        global $DB;

        $this->set_user_picture($this->fromuser->id);
        $fromhash = $this->get_icon_contenthash($this->fromuser->id);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertTrue($this->log_contains($actions, 'copied from user'));

        // The "picture" flag was copied onto the kept user...
        $this->assertEquals(
            $DB->get_field('user', 'picture', ['id' => $this->fromuser->id]),
            $DB->get_field('user', 'picture', ['id' => $this->touser->id])
        );
        // ... and so was the actual file content, byte for byte (same content hash, no re-encoding).
        $this->assertNotNull($fromhash);
        $this->assertEquals($fromhash, $this->get_icon_contenthash($this->touser->id));

        // The removed user's own picture is left untouched by this callback.
        $this->assertEquals($fromhash, $this->get_icon_contenthash($this->fromuser->id));
    }

    /**
     * When neither user has a profile picture, nothing happens and the reason is logged.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_neither_user_has_picture_noop(): void {
        global $DB;

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        $this->assertTrue($this->log_contains($actions, 'nothing to merge'));
    }

    /**
     * Reversed setting (keep old user's data): the removed user's picture prevails and
     * is copied onto the kept user, overwriting the kept user's own existing picture.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_reversed_setting_overwrites_kept_user_own_picture(): void {
        set_config('uniquekeynewidtomaintain', 0, 'tool_mergeusers');
        $this->set_user_picture($this->touser->id);
        $this->set_user_picture($this->fromuser->id);
        $fromhash = $this->get_icon_contenthash($this->fromuser->id);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertTrue($this->log_contains($actions, 'copied from user'));
        $this->assertEquals($fromhash, $this->get_icon_contenthash($this->touser->id));
    }

    /**
     * Reversed setting: if the removed user (now the winner) has no picture, the kept
     * user's own picture is kept as a fallback and nothing is copied.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_reversed_setting_fallback_keeps_kept_user_own_picture(): void {
        global $DB;

        set_config('uniquekeynewidtomaintain', 0, 'tool_mergeusers');
        $this->set_user_picture($this->touser->id);
        $originalpicture = $DB->get_field('user', 'picture', ['id' => $this->touser->id]);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertEquals($originalpicture, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        $this->assertTrue($this->log_contains($actions, 'kept as a fallback'));
    }

    /**
     * Simulates the "database replica without a moodledata replica" scenario explicitly flagged as a
     * concern for this feature: the removed user's picture file row exists in the database, but its
     * actual content is missing from disk (a broken image would be shown on the web). Since copying
     * only duplicates the database-level file record (see file_storage::create_file_from_storedfile()),
     * the merge still succeeds: the (still broken) reference is carried over to the kept user, and no
     * exception is ever thrown - this is expected, not a bug in this class.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_with_missing_moodledata_content_still_copies_reference(): void {
        $this->set_user_picture($this->fromuser->id);
        $fromhash = $this->get_icon_contenthash($this->fromuser->id);
        $this->assertNotNull($fromhash);

        // Delete the physical file content from moodledata, keeping the database row as-is.
        $fs = get_file_storage();
        $context = context_user::instance($this->fromuser->id);
        $files = $fs->get_area_files($context->id, 'user', 'icon', 0, 'itemid, filepath, filename', false);
        $this->assertNotEmpty($files, 'Fixture setup must have created at least one icon file.');
        foreach ($files as $file) {
            $path = $fs->get_file_system()->get_local_path_from_storedfile($file, false);
            if (is_file($path)) {
                unlink($path);
            }
        }

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        // Not affected: the (broken) reference is still copied over.
        $this->assertTrue($this->log_contains($actions, 'copied from user'));
        $this->assertEquals($fromhash, $this->get_icon_contenthash($this->touser->id));
    }

    /**
     * Simulates a database inconsistency where "user.picture" is set but there is no matching file
     * row at all (not even a broken one). Copying is attempted, fails internally, and is logged as a
     * soft warning: it must never fail the whole merge, since a profile picture is not critical data.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_without_any_file_row_logs_soft_warning(): void {
        global $DB;

        // Simulate the inconsistency directly: flag set, but no {files} row was ever created.
        $DB->set_field('user', 'picture', 1, ['id' => $this->fromuser->id]);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertTrue($this->log_contains($actions, 'could not copy from user'));
        // The kept user's picture must not have been corrupted by the failed attempt.
        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
    }

    /**
     * If the removed user's current picture is, per this plugin's own merge history, a leftover copy
     * of the generic "suspended" placeholder from an earlier, unrelated merge, it must be treated as
     * if the removed user had no picture at all - not copied onto the kept user.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_ignores_fromid_stale_suspended_placeholder(): void {
        global $DB;

        $placeholderpicture = $this->set_user_picture($this->fromuser->id);
        $this->record_past_placeholder_merge($this->fromuser->id, $placeholderpicture);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        $this->assertTrue($this->log_contains($actions, 'nothing to merge'));
    }

    /**
     * If the KEPT user's current picture is, per this plugin's own merge history, a leftover copy of
     * the generic "suspended" placeholder from an earlier, unrelated merge (e.g. it was itself
     * suspended and later reactivated), it must not block a genuine picture coming from the removed
     * user in the current merge.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_ignores_toid_stale_suspended_placeholder(): void {
        global $DB;

        $touserplaceholder = $this->set_user_picture($this->touser->id);
        $this->record_past_placeholder_merge($this->touser->id, $touserplaceholder);

        $this->assertNull($this->get_icon_contenthash($this->fromuser->id));
        $this->set_user_picture($this->fromuser->id);
        $fromhash = $this->get_icon_contenthash($this->fromuser->id);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertTrue($this->log_contains($actions, 'copied from user'));
        $this->assertEquals($fromhash, $this->get_icon_contenthash($this->touser->id));
        $this->assertNotEquals($touserplaceholder, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
    }

    /**
     * A user whose CURRENT picture happens to match an old placeholder record only in value, but that
     * merge was not successful, must still be treated as having a real, genuine picture: only a
     * successful past merge is trusted as evidence of a placeholder.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_does_not_ignore_picture_from_failed_past_merge_record(): void {
        global $DB;

        $picture = $this->set_user_picture($this->fromuser->id);
        $logger = new logger();
        $someotherid = $this->getDataGenerator()->create_user()->id;
        $logger->log($someotherid, $this->fromuser->id, false, ['(a previous, failed merge)'], 'error', null, null, $picture);

        $actions = [];
        picture_merger::merge_picture($this->touser->id, $this->fromuser->id, $actions);

        $this->assertTrue($this->log_contains($actions, 'copied from user'));
        $this->assertEquals($picture, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
    }

    /**
     * A failure that happens AFTER the kept user's previous icon files have already been
     * removed (Copilot's review comment on PR #427) must not leave the kept user with a broken
     * reference to files that no longer exist: it must fall back to a clean "no picture" state.
     *
     * Reproduced by injecting, via the test-only $files seam, a stored_file list where one file's
     * underlying database row has already been deleted - exactly what
     * file_storage::create_file_from_storedfile() needs to fail on.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_picture
     */
    public function test_merge_picture_partial_failure_leaves_kept_user_with_no_picture(): void {
        global $DB;

        $this->set_user_picture($this->fromuser->id);
        $this->set_user_picture($this->touser->id); // Kept user starts with its OWN picture files present.

        $fs = get_file_storage();
        $fromcontext = context_user::instance($this->fromuser->id);
        $files = $fs->get_area_files($fromcontext->id, 'user', 'icon', 0, 'itemid, filepath, filename', false);
        $this->assertGreaterThan(1, count($files), 'Need at least 2 icon files to test a partial failure.');

        // Simulate the source's underlying database row vanishing right before it gets copied.
        $victim = end($files);
        $DB->delete_records('files', ['id' => $victim->get_id()]);

        $actions = [];
        $method = new ReflectionMethod(picture_merger::class, 'copy_picture');
        $method->setAccessible(true);
        // ReflectionMethod::invoke() cannot pass $actions by reference; invokeArgs() can, as long as
        // the array itself holds a real reference to it.
        $method->invokeArgs(null, [$this->touser->id, $this->fromuser->id, &$actions, $files]);

        $this->assertTrue($this->log_contains($actions, 'could not copy from user'));
        // No stale/broken reference: the kept user ends up with a clean "no picture" state, not a
        // partially-populated one pointing at files that were removed along the way.
        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        $remaining = $fs->get_area_files(
            context_user::instance($this->touser->id)->id,
            'user',
            'icon',
            0,
            'itemid, filepath, filename',
            false
        );
        $this->assertEmpty($remaining, 'No partial icon files should be left behind on the kept user.');
    }
}

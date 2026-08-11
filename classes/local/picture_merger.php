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
 * Merges the profile picture of the two users involved in a merge.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers\local;

use context_user;
use dml_exception;
use moodle_exception;
use Throwable;

/**
 * Merges the profile picture of the two merged users into the user to keep.
 *
 * See issue https://github.com/jpahullo/moodle-tool_mergeusers/issues/377 for the requested behaviour.
 *
 * This is deliberately NOT an after_merged_all_tables hook callback: it must only ever run once the
 * merge has definitively succeeded and committed, so it is called directly, synchronously, from
 * {@see \tool_mergeusers\local\observer\olduser::old_user_suspend()} - a plain method call, not a
 * second event observer, since Moodle does not formally guarantee execution order between two
 * observers registered on the same event. Running only after commit also means that a merge which
 * ends up rolled back (including via the CLI --alwaysrollback flag) never touches any picture at all.
 *
 * The user whose picture should prevail is decided by the general setting
 * `tool_mergeusers/uniquekeynewidtomaintain`, the same one used to resolve conflicting records on
 * compound indexes. If the prevailing user has no trusted picture, the other user's picture is used
 * as a fallback. If neither has one, nothing happens. A user's own picture is never trusted, on top
 * of simply not having one, when either that user is currently suspended, or its picture still
 * matches this plugin's own record of a placeholder it applied on an earlier, unrelated merge - see
 * {@see picture_status()} for the full detail behind both checks.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class picture_merger {
    /**
     * Merges the profile picture of the two merged users into the user to keep.
     *
     * Every possible outcome, including every case where no picture is copied, is appended to
     * $actions (by reference) so the merge's own persisted log always explains why. An unexpected
     * failure while copying the picture files (for instance, a file row present in the database but
     * without matching content on moodledata) is logged as a soft warning and never aborts anything:
     * a profile picture is not critical data and the affected user can easily upload it again.
     *
     * @param int $toid user.id to keep.
     * @param int $fromid user.id to remove.
     * @param array $actions list of log lines to append to, by reference.
     * @param int|null $currentlogid the log id of the merge currently being processed, so its own
     * (already persisted, but not yet fully populated) log row is never mistaken for evidence of an
     * earlier, genuinely separate merge when checking either user's history below.
     * @return void
     * @throws dml_exception
     */
    public static function merge_picture(int $toid, int $fromid, array &$actions, ?int $currentlogid = null): void {
        $a = (object) ['toid' => $toid, 'fromid' => $fromid];

        if (!(bool) (int) get_config('tool_mergeusers', 'mergepicture')) {
            $actions[] = get_string('picturemergedisabled', 'tool_mergeusers', $a);
            return;
        }

        $winnerid = (bool) (int) get_config('tool_mergeusers', 'uniquekeynewidtomaintain') ? $toid : $fromid;
        $otherid = ($winnerid === $toid) ? $fromid : $toid;

        $winnerstatus = self::picture_status($winnerid, $currentlogid);
        $otherstatus = self::picture_status($otherid, $currentlogid);
        self::log_if_suspended($winnerid, $winnerstatus, $actions);
        self::log_if_suspended($otherid, $otherstatus, $actions);

        $winnerhaspicture = $winnerstatus['trusted'];
        $otherhaspicture = $otherstatus['trusted'];

        if (!$winnerhaspicture && !$otherhaspicture) {
            $actions[] = get_string('picturemergenopicture', 'tool_mergeusers', $a);
            return;
        }

        $sourceid = $winnerhaspicture ? $winnerid : $otherid;

        if ($sourceid === $toid) {
            $reason = ($winnerid === $toid) ? 'picturemergealreadywinner' : 'picturemergefallbackkept';
            $actions[] = get_string($reason, 'tool_mergeusers', $a);
            return;
        }

        // At this point, $sourceid === $fromid: the picture that must prevail on the kept user lives on the removed user.
        self::copy_picture($toid, $fromid, $actions);
    }

    /**
     * Whether the given user has a profile picture that can be trusted as their own, real picture -
     * and if not, why not.
     *
     * A picture is NOT trusted, on top of the user simply having none at all (user.picture == 0), in
     * two further cases, both meant to guard the same underlying concern - never letting this
     * plugin's own "suspended" placeholder image reach an active account - from two different angles:
     *
     * 1. The user is currently suspended. In this plugin's own normal operation, a suspended user is
     * (almost always) one this plugin has already processed as the removed side of an earlier merge,
     * so its picture is not to be trusted, REGARDLESS of whether the exact recorded placeholder value
     * below still matches (belt and braces: cheap, and correct even if, for some reason, that record
     * is missing or stale).
     * 2. Independently of the current suspended flag (which an administrator could have cleared since,
     * deliberately or not), the picture still matches this plugin's own durable record of the exact
     * value it set as the placeholder the last time this user was the removed side of a merge.
     *
     * @param int $userid
     * @param int|null $currentlogid see {@see merge_picture()}.
     * @return array{trusted: bool, suspended: bool} "trusted" is false whenever the picture must not
     * be used; "suspended" is true only when that specific reason applied (used only for logging).
     * @throws dml_exception
     */
    private static function picture_status(int $userid, ?int $currentlogid): array {
        global $DB;

        $picture = (int) $DB->get_field('user', 'picture', ['id' => $userid]);
        if ($picture === 0) {
            return ['trusted' => false, 'suspended' => false];
        }

        if ((bool) (int) $DB->get_field('user', 'suspended', ['id' => $userid])) {
            return ['trusted' => false, 'suspended' => true];
        }

        $trusted = !self::is_suspended_placeholder($userid, $picture, $currentlogid);

        return ['trusted' => $trusted, 'suspended' => false];
    }

    /**
     * Appends a dedicated log line when $status marks $userid's picture as disregarded specifically
     * because the user is suspended (as opposed to genuinely having none, or matching this plugin's
     * own recorded placeholder value), so the merge's persisted log distinguishes this case too.
     *
     * @param int $userid
     * @param array{trusted: bool, suspended: bool} $status
     * @param array $actions list of log lines to append to, by reference.
     * @return void
     */
    private static function log_if_suspended(int $userid, array $status, array &$actions): void {
        if ($status['suspended']) {
            $actions[] = get_string('picturemergeuserskippedsuspended', 'tool_mergeusers', (object) ['userid' => $userid]);
        }
    }

    /**
     * Whether $currentpicture is, as far as this plugin's own history can tell, the generic
     * "suspended" placeholder this plugin previously applied to $userid's own picture.
     *
     * Detection is not based on file content hashes (the PHP/GD/Moodle versions on this instance
     * change roughly yearly, and process_new_icon()'s output bytes for the same source image are not
     * guaranteed stable across such upgrades) nor on filename (process_new_icon() always renames
     * generated files to f1/f2/f3 plus extension, never preserving the source filename). Instead, it
     * relies on this plugin's own durable record: the exact user.picture value that was set the last
     * time $userid was the removed side ("fromuserid") of a successful merge PRIOR to the one
     * currently being processed, stored in that (earlier) merge's own persisted log. If the user's
     * CURRENT picture still matches that recorded value, it is still the placeholder; any other value
     * (a real upload, by any means, at any point since) naturally, and automatically, no longer
     * matches - no event needs to be observed to keep this in sync.
     *
     * @param int $userid
     * @param int $currentpicture
     * @param int|null $currentlogid see {@see merge_picture()}.
     * @return bool
     * @throws dml_exception
     */
    private static function is_suspended_placeholder(int $userid, int $currentpicture, ?int $currentlogid): bool {
        $lastasfromuser = (new logger())->latest_as_fromuser($userid, $currentlogid);
        if ($lastasfromuser === null || $lastasfromuser->status !== 'success') {
            return false;
        }

        $logdata = json_decode($lastasfromuser->log, true);
        $recordedplaceholder = $logdata['suspendedplaceholderpicture'] ?? null;

        return $recordedplaceholder !== null && (int) $recordedplaceholder === $currentpicture;
    }

    /**
     * Copies the removed user's profile picture files onto the kept user, and updates the kept
     * user's "picture" field accordingly.
     *
     * If creating the new files fails partway through - after the kept user's previous icon files
     * were already removed - the kept user is left with a clean "no picture" state (files removed,
     * "picture" field reset to 0) rather than a broken reference to files that no longer exist.
     *
     * Any failure here is only ever logged as a soft warning: it must never be treated as a fatal
     * error by the caller (see class docblock).
     *
     * @param int $toid
     * @param int $fromid
     * @param array $actions list of log lines to append to, by reference.
     * @param array|null $files test-only seam: when given, used instead of fetching $fromid's current
     * icon files, so a test can inject a stored_file list including one whose underlying database row
     * has since been removed, deterministically reproducing a failure partway through the loop below.
     * Always null in production; never fetched from user input.
     * @return void
     */
    private static function copy_picture(int $toid, int $fromid, array &$actions, ?array $files = null): void {
        global $DB;

        $a = (object) ['toid' => $toid, 'fromid' => $fromid];

        try {
            $fs = get_file_storage();
            $sourcecontextid = context_user::instance($fromid)->id;
            $targetcontextid = context_user::instance($toid)->id;

            $files = $files ?? $fs->get_area_files($sourcecontextid, 'user', 'icon', 0, 'itemid, filepath, filename', false);
            if (empty($files)) {
                throw new moodle_exception('exception:nopicturefiles', 'tool_mergeusers', '', (object) ['userid' => $fromid]);
            }

            $fs->delete_area_files($targetcontextid, 'user', 'icon', 0);

            try {
                foreach ($files as $file) {
                    $fs->create_file_from_storedfile(['contextid' => $targetcontextid], $file);
                }
            } catch (Throwable $e) {
                // The kept user's previous icon files are already gone: never leave a partial/broken
                // set behind. Fall back to a clean "no picture" state instead.
                $fs->delete_area_files($targetcontextid, 'user', 'icon', 0);
                $DB->set_field('user', 'picture', 0, ['id' => $toid]);
                throw $e;
            }

            $DB->set_field('user', 'picture', $DB->get_field('user', 'picture', ['id' => $fromid]), ['id' => $toid]);
            $actions[] = get_string('picturemerged', 'tool_mergeusers', $a);
        } catch (Throwable $e) {
            $params = clone $a;
            $params->error = $e->getMessage();
            $actions[] = get_string('picturemergefailed', 'tool_mergeusers', $params);
        }
    }
}

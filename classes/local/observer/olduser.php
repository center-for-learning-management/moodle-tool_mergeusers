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
 * Observer for the user_merged_success event for suspending the user to remove.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol-Ahulló <jordi.pujol@urv.cat>
 * @author    John Hoopes <hoopes@wisc.edu>
 * @copyright 2013 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @copyright University of Wisconsin - Madison
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers\local\observer;

use context_user;
use dml_missing_record_exception;
use stdClass;
use tool_mergeusers\event\user_merged_success;
use tool_mergeusers\local\logger;
use tool_mergeusers\local\picture_merger;

/**
 * Observer for the user_merged_success event for suspending the user to remove.
 *
 * Also drives, in this specific order, the profile picture merge (see {@see picture_merger}): it
 * must run strictly before the placeholder image is applied to the removed user below, and only
 * after the merge has definitively committed - which is exactly when this event fires. Both actions
 * are combined into this single observer, rather than split into two separately registered
 * observers, because Moodle does not formally guarantee execution order between two observers on
 * the same event.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol-Ahulló <jordi.pujol@urv.cat>
 * @author    John Hoopes <hoopes@wisc.edu>
 * @copyright 2013 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @copyright University of Wisconsin - Madison
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class olduser {
    /**
     * Merges the profile picture, then suspends the old user's account and updates its own
     * profile picture to a generic placeholder one.
     *
     * @param user_merged_success $event Event data.
     */
    public static function old_user_suspend(user_merged_success $event): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');

        $toid = $event->get_new_user_id();
        $fromid = $event->get_old_user_id();
        $logid = $event->get_log_id();

        $newactions = [];

        // 1. Merge the profile picture, independently of whether the removed user is suspended below.
        picture_merger::merge_picture($toid, $fromid, $newactions, $logid);

        // 2. Check configuration to see if the old user has to be suspended.
        $suspenduser = (bool) (int) get_config('tool_mergeusers', 'suspenduser');
        $suspendedplaceholderpicture = null;
        if ($suspenduser) {
            // 2.1. Update suspended flag.
            $usertoremove = new stdClass();
            $usertoremove->id = $fromid;
            $usertoremove->suspended = 1;
            $usertoremove->timemodified = time();
            $DB->update_record('user', $usertoremove);

            // 2.2. Update profile picture: get source, common image.
            $fullpath = dirname(__DIR__, 3) . "/pix/suspended.jpg";
            if (file_exists($fullpath)) {
                // Place the common image as the profile picture.
                $context = context_user::instance($fromid);
                if (($newrev = process_new_icon($context, 'user', 'icon', 0, $fullpath))) {
                    $DB->set_field('user', 'picture', $newrev, ['id' => $fromid]);
                    $suspendedplaceholderpicture = $newrev;
                }
            } // Else: do nothing, aborting, given that the image does not exist. This should not happen.
        }

        self::persist_actions($logid, $newactions, $suspendedplaceholderpicture);
    }

    /**
     * Appends $newactions to the merge's already persisted log, and records
     * $suspendedplaceholderpicture on it, if any.
     *
     * update_log_status() replaces the whole "actions" list, rather than appending to it, so the
     * existing ones are read first and combined with the new ones here.
     *
     * @param int $logid
     * @param array $newactions
     * @param int|null $suspendedplaceholderpicture
     * @return void
     */
    private static function persist_actions(int $logid, array $newactions, ?int $suspendedplaceholderpicture): void {
        if ($logid <= 0 || (empty($newactions) && $suspendedplaceholderpicture === null)) {
            return;
        }

        $logger = new logger();
        try {
            $existing = $logger->detail_from($logid);
        } catch (dml_missing_record_exception $e) {
            debugging('Cannot append picture actions to non-existent merge log: ' . $logid, DEBUG_DEVELOPER);
            return;
        }

        $existingactions = (array) ($existing->log->actions ?? []);
        $logger->update_log_status(
            $logid,
            $existing->status,
            array_merge($existingactions, $newactions),
            $suspendedplaceholderpicture,
        );
    }
}

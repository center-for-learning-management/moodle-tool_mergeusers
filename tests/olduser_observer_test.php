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
 * Tests for the olduser observer.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers;

use advanced_testcase;
use context_system;
use tool_mergeusers\event\user_merged_success;
use tool_mergeusers\local\logger;
use tool_mergeusers\local\observer\olduser;

/**
 * Tests for {@see olduser}.
 *
 * @package   tool_mergeusers
 * @author    Jordi Pujol Ahulló <jordi.pujol@urv.cat>
 * @copyright 2026 onwards to Universitat Rovira i Virgili (https://www.urv.cat)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \tool_mergeusers\local\observer\olduser
 */
final class olduser_observer_test extends advanced_testcase {
    /** @var object */
    private object $touser;

    /** @var object */
    private object $fromuser;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->touser = $this->getDataGenerator()->create_user();
        $this->fromuser = $this->getDataGenerator()->create_user();
    }

    /**
     * Builds a user_merged_success event for the standard touser/fromuser pair, backed by a real,
     * freshly created pending log entry (so get_log_id() resolves to a real, updatable row).
     *
     * @return user_merged_success
     */
    private function build_event(): user_merged_success {
        $logger = new logger();
        $logid = $logger->create_pending_log($this->touser->id, $this->fromuser->id, 2);

        return user_merged_success::create([
            'context' => context_system::instance(),
            'other' => [
                'usersinvolved' => ['toid' => $this->touser->id, 'fromid' => $this->fromuser->id],
                'logid' => $logid,
                'log' => [],
            ],
        ]);
    }

    /**
     * With the "Suspend old user" setting enabled (the default), the removed user is suspended and
     * its own picture is overwritten with the generic placeholder image, whose resulting value is
     * also recorded on the merge's persisted log for future placeholder detection.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_observer
     */
    public function test_suspends_removed_user_and_records_placeholder_picture(): void {
        global $DB;

        set_config('suspenduser', 1, 'tool_mergeusers');
        $event = $this->build_event();

        olduser::old_user_suspend($event);

        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $this->fromuser->id]));
        $newpicture = (int) $DB->get_field('user', 'picture', ['id' => $this->fromuser->id]);
        $this->assertGreaterThan(0, $newpicture);

        $logger = new logger();
        $persisted = $logger->detail_from($event->get_log_id());
        $this->assertEquals($newpicture, $persisted->log->suspendedplaceholderpicture);
    }

    /**
     * With "Suspend old user" disabled, the removed user is left untouched (matching the pre-existing
     * behaviour), and no placeholder value is recorded.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_observer
     */
    public function test_does_not_suspend_when_setting_disabled(): void {
        global $DB;

        set_config('suspenduser', 0, 'tool_mergeusers');
        $event = $this->build_event();

        olduser::old_user_suspend($event);

        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $this->fromuser->id]));
        $this->assertEquals(0, $DB->get_field('user', 'picture', ['id' => $this->fromuser->id]));

        $logger = new logger();
        $persisted = $logger->detail_from($event->get_log_id());
        $this->assertNull($persisted->log->suspendedplaceholderpicture);
    }

    /**
     * The profile picture merge runs regardless of the "Suspend old user" setting: it is governed
     * only by its own "Merge profile picture" setting.
     *
     * @group tool_mergeusers
     * @group tool_mergeusers_observer
     * @group tool_mergeusers_picture
     */
    public function test_merges_picture_even_when_suspend_setting_disabled(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gdlib.php');

        set_config('suspenduser', 0, 'tool_mergeusers');
        set_config('mergepicture', 1, 'tool_mergeusers');
        set_config('uniquekeynewidtomaintain', 1, 'tool_mergeusers');

        $imagepath = __DIR__ . '/fixtures/user_picture.png';
        $context = \context_user::instance($this->fromuser->id);
        $newrev = process_new_icon($context, 'user', 'icon', 0, $imagepath);
        $this->assertNotFalse($newrev);
        $DB->set_field('user', 'picture', $newrev, ['id' => $this->fromuser->id]);

        $event = $this->build_event();
        olduser::old_user_suspend($event);

        // The picture was merged onto touser...
        $this->assertEquals($newrev, $DB->get_field('user', 'picture', ['id' => $this->touser->id]));
        // ... while fromuser's own picture is left exactly as it was (suspend is disabled, so nothing
        // overwrites it).
        $this->assertEquals($newrev, $DB->get_field('user', 'picture', ['id' => $this->fromuser->id]));

        $logger = new logger();
        $persisted = $logger->detail_from($event->get_log_id());
        $this->assertStringContainsString('copied from user', implode(' ', $persisted->log->actions));
    }
}

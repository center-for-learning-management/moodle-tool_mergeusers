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
 * In-memory implementation of last_merge, used as a test double.
 *
 * @package   tool_mergeusers
 * @author    Matthew Hilton <matthewhilton@catalyst-au.net>
 * @copyright 2025 Catalyst IT Australia
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mergeusers\fixtures;

use tool_mergeusers\local\last_merge;

/**
 * In-memory implementation of last_merge, used as a test double so that renderer tests
 * do not need to hit the database.
 *
 * @package   tool_mergeusers
 * @author    Matthew Hilton <matthewhilton@catalyst-au.net>
 * @copyright 2025 Catalyst IT Australia
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class in_memory_last_merge extends last_merge {
    /** @var int the id of the user this merge information relates to. */
    private int $userid;

    /** @var bool whether the user is suspended. */
    private bool $suspended;

    /** @var mixed the merge log entry for merges performed into this user. */
    private mixed $tome;

    /** @var mixed the merge log entry for merges performed from this user. */
    private mixed $fromme;

    /**
     * Constructor.
     *
     * @param int $userid the id of the user this merge information relates to
     * @param bool $suspended whether the user is suspended
     * @param mixed $tome the merge log entry for merges performed into this user
     * @param mixed $fromme the merge log entry for merges performed from this user
     */
    public function __construct(int $userid, bool $suspended, mixed $tome, mixed $fromme) {
        $this->userid = $userid;
        $this->suspended = $suspended;
        $this->tome = $tome;
        $this->fromme = $fromme;
    }

    /**
     * Returns the merge log entry for merges performed from this user.
     *
     * @return null|\stdClass
     */
    public function fromme(): null|\stdClass {
        return $this->fromme;
    }

    /**
     * Returns the merge log entry for merges performed into this user.
     *
     * @return null|\stdClass
     */
    public function tome(): null|\stdClass {
        return $this->tome;
    }

    /**
     * Returns whether this user is deletable.
     *
     * @return bool
     */
    public function is_this_user_deletable(): bool {
        return true;
    }
}

<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace core;

use advanced_testcase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests for the default time formats used by userdate().
 *
 * @package    core
 * @category   test
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('userdate')]
final class userdate_test extends advanced_testcase {
    /**
     * The default format respects calendar translations and local customisations.
     */
    public function test_localised_default_format(): void {
        global $CFG;

        $this->resetAfterTest();
        $timestamp = (new DateTimeImmutable('2026-01-02T13:45:00+00:00'))->getTimestamp();

        $langfolder = $CFG->dataroot . '/lang/xx';
        check_dir_exists($langfolder);
        file_put_contents($langfolder . '/langconfig.php', <<<'PHP'
            <?php
            $string['thislanguage'] = 'Test language';
            PHP);
        file_put_contents($langfolder . '/calendar.php', <<<'PHP'
            <?php
            $string['strftimedaydatetime'] = '%Y/%m/%d %H:%M';
            PHP);
        get_string_manager()->reset_caches(true);
        force_current_language('xx');

        $this->assertSame('2026/01/02 13:45', userdate($timestamp, timezone: 'UTC', fixday: false));

        $localfolder = $CFG->dataroot . '/lang/xx_local';
        check_dir_exists($localfolder);
        file_put_contents($localfolder . '/calendar.php', <<<'PHP'
            <?php
            $string['strftimedaydatetime'] = '%d.%m.%Y %H:%M';
            PHP);
        get_string_manager()->reset_caches(true);

        $this->assertSame('02.01.2026 13:45', userdate($timestamp, timezone: 'UTC', fixday: false));
    }
}

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
use PHPUnit\Framework\Attributes\DataProvider;

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
     * The default time formats name exact noon and midnight in the user's timezone.
     *
     * @param string $datetime An ISO 8601 date and time with an explicit timezone offset
     * @param string $timezone The user's timezone
     * @param string $formatkey A langconfig string key, or an empty string for the default format
     * @param string $expected The expected display text
     * @param bool $fixhour Whether to remove the leading zero from the hour
     */
    #[DataProvider('userdate_noon_and_midnight_provider')]
    public function test_userdate_noon_and_midnight(
        string $datetime,
        string $timezone,
        string $formatkey,
        string $expected,
        bool $fixhour = true,
    ): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('UTC', 'UTC');
        force_current_language('en');
        $USER->timezone = $timezone;

        $timestamp = (new DateTimeImmutable($datetime))->getTimestamp();
        $format = $formatkey === '' ? '' : get_string($formatkey, 'langconfig');

        $this->assertSame($expected, userdate($timestamp, $format, fixhour: $fixhour));
    }

    /**
     * Data provider for {@see test_userdate_noon_and_midnight()}.
     *
     * @return array
     */
    public static function userdate_noon_and_midnight_provider(): array {
        return [
            '12-hour time at local noon' => [
                '2026-01-02T04:00:00+00:00',
                'Australia/Perth',
                'strftimetime12',
                'noon',
            ],
            '12-hour time at local midnight' => [
                '2026-01-01T16:00:00+00:00',
                'Australia/Perth',
                'strftimetime12',
                'midnight (start of day)',
            ],
            'default time format at noon' => [
                '2026-01-02T12:00:00+00:00',
                'UTC',
                'strftimetime',
                'noon',
            ],
            'date and time at noon' => [
                '2026-01-02T12:00:00+00:00',
                'UTC',
                'strftimedatetime',
                '2 January 2026, noon',
            ],
            'date and time with seconds at noon' => [
                '2026-01-02T12:00:00+00:00',
                'UTC',
                'strftimedatetimeaccurate',
                '2 January 2026, noon',
            ],
            'date and time with seconds at midnight' => [
                '2026-01-02T00:00:00+00:00',
                'UTC',
                'strftimedatetimeaccurate',
                '2 January 2026, midnight (start of day)',
            ],
            'midnight uses the date in the user timezone' => [
                '2026-01-01T16:00:00+00:00',
                'Australia/Perth',
                'strftimedaydatetime',
                'Friday, 2 January 2026, midnight (start of day)',
            ],
            'recent date and time at noon' => [
                '2026-01-02T12:00:00+00:00',
                'UTC',
                'strftimerecentfull',
                'Fri, 2 Jan 2026, noon',
            ],
            'default date and time format at midnight' => [
                '2026-01-02T00:00:00+00:00',
                'UTC',
                '',
                'Friday, 2 January 2026, midnight (start of day)',
            ],
            'local noon during daylight saving time' => [
                '2026-07-02T11:00:00+00:00',
                'Europe/London',
                'strftimetime12',
                'noon',
            ],
            'one second before noon' => [
                '2026-01-02T11:59:59+00:00',
                'UTC',
                'strftimetime12',
                '11:59 AM',
            ],
            'one second after noon' => [
                '2026-01-02T12:00:01+00:00',
                'UTC',
                'strftimetime12',
                '12:00 PM',
            ],
            'one second before midnight' => [
                '2026-01-01T23:59:59+00:00',
                'UTC',
                'strftimetime12',
                '11:59 PM',
            ],
            'one second after midnight' => [
                '2026-01-02T00:00:01+00:00',
                'UTC',
                'strftimetime12',
                '12:00 AM',
            ],
            'ordinary time without a leading zero' => [
                '2026-01-02T08:05:00+00:00',
                'UTC',
                'strftimetime12',
                '8:05 AM',
            ],
            'ordinary time with a leading zero' => [
                '2026-01-02T08:05:00+00:00',
                'UTC',
                'strftimetime12',
                '08:05 AM',
                false,
            ],
            'ordinary time including seconds' => [
                '2026-01-02T08:05:06+00:00',
                'UTC',
                'strftimedatetimeaccurate',
                '2 January 2026, 8:05:06 AM',
            ],
            '24-hour time at noon' => [
                '2026-01-02T12:00:00+00:00',
                'UTC',
                'strftimetime24',
                '12:00',
            ],
            '24-hour time at midnight' => [
                '2026-01-02T00:00:00+00:00',
                'UTC',
                'strftimetime24',
                '00:00',
            ],
        ];
    }
}

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

namespace quizaccess_seb;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/test_helper_trait.php');

/**
 * PHPUnit tests for helper class.
 *
 * @package    quizaccess_seb
 * @author     Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @copyright  2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \quizaccess_seb\helper
 */
class helper_test extends \advanced_testcase {
    use \quizaccess_seb_test_helper_trait;

    /**
     * Test that we can check valid seb string.
     */
    public function test_is_valid_seb_config() {
        $validseb = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">
<plist version=\"1.0\"><dict><key>showTaskBar</key><true/><key>allowWlan</key><false/><key>showReloadButton</key><true/>"
            . "<key>showTime</key><false/><key>showInputLanguage</key><true/><key>allowQuit</key><true/>"
            . "<key>quitURLConfirm</key><true/><key>audioControlEnabled</key><true/><key>audioMute</key><false/>"
            . "<key>allowSpellCheck</key><false/><key>browserWindowAllowReload</key><true/><key>URLFilterEnable</key><true/>"
            . "<key>URLFilterEnableContentFilter</key><false/><key>hashedQuitPassword</key>"
            . "<string>9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08</string><key>URLFilterRules</key>"
            . "<array><dict><key>action</key><integer>1</integer><key>active</key><true/><key>expression</key>"
            . "<string>test.com</string><key>regex</key><false/></dict></array>"
            . "<key>sendBrowserExamKey</key><true/></dict></plist>\n";
        $invalidseb = 'Invalid seb';
        $emptyseb = '';

        $this->assertTrue(\quizaccess_seb\helper::is_valid_seb_config($validseb));
        $this->assertFalse(\quizaccess_seb\helper::is_valid_seb_config($invalidseb));
        $this->assertFalse(\quizaccess_seb\helper::is_valid_seb_config($emptyseb));
    }

    /**
     * Test that we can get seb file headers.
     */
    public function test_get_seb_file_headers() {
        $expiretime = 1582767914;
        $headers = \quizaccess_seb\helper::get_seb_file_headers($expiretime);

        $this->assertCount(5, $headers);
        $this->assertEquals('Cache-Control: private, max-age=1, no-transform', $headers[0]);
        $this->assertEquals('Expires: Thu, 27 Feb 2020 01:45:14 GMT', $headers[1]);
        $this->assertEquals('Pragma: no-cache', $headers[2]);
        $this->assertEquals('Content-Disposition: attachment; filename=config.seb', $headers[3]);
        $this->assertEquals('Content-Type: application/seb', $headers[4]);
    }


    /**
     * Test that the course module must exist to get a seb config file content.
     */
    public function test_can_not_get_config_content_with_invalid_cmid() {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user); // Log user in.

        $this->expectException(\dml_exception::class);
        $this->expectExceptionMessage("Can't find data record in database. (SELECT cm.*, m.name, md.name AS modname \n"
            . "              FROM {course_modules} cm\n"
            . "                   JOIN {modules} md ON md.id = cm.module\n"
            . "                   JOIN {quiz} m ON m.id = cm.instance\n"
            . "                   \n"
            . "             WHERE cm.id = :cmid AND md.name = :modulename\n"
            . "                   \n"
            . "[array (\n"
            . "  'cmid' => '999',\n"
            . "  'modulename' => 'quiz',\n"
            .')])');
        \quizaccess_seb\helper::get_seb_config_content('999');
    }

    /**
     * Test that the user must be enrolled to get seb config content.
     */
    public function test_can_not_get_config_content_when_user_not_enrolled_in_course() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->create_test_quiz($course, \quizaccess_seb\settings_provider::USE_SEB_CONFIG_MANUALLY);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user); // Log user in.

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Unsupported redirect detected, script execution terminated');
        \quizaccess_seb\helper::get_seb_config_content($quiz->cmid);
    }

    /**
     * Test that if SEB quiz settings can't be found, a seb config content won't be provided.
     */
    public function test_can_not_get_config_content_if_config_not_found_for_cmid() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->create_test_quiz($course);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user); // Log user in.

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage("No SEB config could be found for quiz with cmid: $quiz->cmid");
        \quizaccess_seb\helper::get_seb_config_content($quiz->cmid);
    }

    /**
     * That that if config is empty for a quiz, a seb config content won't be provided.
     */
    public function test_can_not_get_config_content_if_config_empty() {
        $this->resetAfterTest();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->create_test_quiz($course, \quizaccess_seb\settings_provider::USE_SEB_NO);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user); // Log user in.

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage("No SEB config could be found for quiz with cmid: $quiz->cmid");
        \quizaccess_seb\helper::get_seb_config_content($quiz->cmid);
    }

    /**
     * Test config content is provided successfully.
     */
    public function test_config_provided() {
        $this->resetAfterTest();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->create_test_quiz($course, \quizaccess_seb\settings_provider::USE_SEB_CONFIG_MANUALLY);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user); // Log user in.

        $config = \quizaccess_seb\helper::get_seb_config_content($quiz->cmid);

        $url = new \moodle_url("/mod/quiz/view.php", ['id' => $quiz->cmid]);

        $this->assertEquals("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
            . "<plist version=\"1.0\"><dict><key>showTaskBar</key><true/><key>allowWlan</key>"
            . "<false/><key>showReloadButton</key><true/><key>showTime</key><true/><key>showInputLanguage</key>"
            . "<true/><key>allowQuit</key><true/><key>quitURLConfirm</key><true/><key>audioControlEnabled</key>"
            . "<false/><key>audioMute</key><false/><key>allowSpellCheck</key><false/><key>browserWindowAllowReload</key>"
            . "<true/><key>URLFilterEnable</key><false/><key>URLFilterEnableContentFilter</key><false/>"
            . "<key>URLFilterRules</key><array/><key>startURL</key><string>$url</string>"
            . "<key>sendBrowserExamKey</key><true/><key>browserWindowWebView</key><integer>3</integer>"
            . "<key>examSessionClearCookiesOnStart</key><false/>"
            . "<key>allowPreferencesWindow</key><false/></dict></plist>\n", $config);
    }

    /**
     * Test native PHP data is encoded as a "SEB-JSON" string as per the specification.
     *
     * @dataProvider seb_json_encode_data_provider
     * @param mixed $data Data to encode.
     * @param string $expected Expected result.
     *
     * @covers ::seb_json_encode
     */
    public function test_seb_json_encode($data, $expected) {
        $this->assertEquals($expected, helper::seb_json_encode($data));
    }

    /**
     * For extra fun (it is a fact that everyone loves character encoding bugs), create
     * an absolute mess of control characters interspersed throughout random strings and
     * verify that none of them make it in to the "SEB-JSON" string.
     *
     * @dataProvider seb_json_encode_randomised_data_provider
     * @param mixed $data Data to encode.
     *
     * @covers ::seb_json_encode
     */
    public function test_seb_json_encode_randomised($data) {
        $intersperse = function(string $string, array $elements): string {
            $chararray = mb_str_split($string);
            $len = count($chararray);
            $chunks = array_chunk($chararray, ceil($len / random_int(1, $len)));

            $interspersed = array_map(
                fn(array $chunk): array => array_merge(
                    $chunk,
                    [$elements[array_rand($elements)]]
                ), $chunks);

            return  array_reduce($interspersed, fn(string $c, array $v): string => $c . join($v), '');
        };

        $stuffthatshouldnotbeescaped = ["\\", "/", "\""];
        $stuffthatshouldbestripped = [chr(8), "\f", "\n", "\r", "\t", " "];
        $stufftointersperse = array_merge($stuffthatshouldnotbeescaped, $stuffthatshouldbestripped);
        $interspersed = $intersperse($data, $stufftointersperse);

        $this->assertEmpty(
            array_intersect(mb_str_split(helper::seb_json_encode($interspersed)),
            $stuffthatshouldbestripped)
        );

        $codepoints = array_flip(array_map('ord', $stuffthatshouldnotbeescaped));
        $inputcounts = array_intersect_key(count_chars($interspersed . "\"\"", 1), $codepoints);
        $outputcounts = array_intersect_key(count_chars(helper::seb_json_encode($interspersed), 1), $codepoints);
        $this->assertEquals($inputcounts, $outputcounts);
    }

    /**
     * Data provider for test_seb_json_encode.
     *
     * @return array List of test cases and expected results.
     */
    public function seb_json_encode_data_provider(): array {
        return [
            'String with plain whitespace' => ['A basic string with whitespace', "\"Abasicstringwithwhitespace\""],
            'String with quotation marks' => [
                '"I don\'t know half of you half as well as I should like, ' .
                'and I like less than half of you half as well as you deserve."',
                '""Idon\'tknowhalfofyouhalfaswellasIshouldlike,andIlikelessthanhalfofyouhalfaswellasyoudeserve.""',
            ],
            'String with reverse solidus' => ["my\\name\\chef", "\"my\\name\\chef\""],
            'String with double reverse solidus' => ["my\\\\name\\\\chef", "\"my\\\\name\\\\chef\""],
            'String with solidus' => ["my/name/chef", "\"my/name/chef\""],
            'String with double solidus' => ["my//name//chef", "\"my//name//chef\""],
            'String with backspace' => [
                "Not sure why" . chr(8) . "en you would do this but here it is",
                "\"Notsurewhyenyouwoulddothisbuthereitis\""
            ],
            'String with form feed' => ["Form\ffeed", "\"Formfeed\""],
            'String with newline' => ["hello\nfriends", "\"hellofriends\""],
            'String with carriage return' => ["hello\rfriends", "\"hellofriends\""],
            'String with tabulation' => ["hello\tfriends", "\"hellofriends\""],
            'String with multibyte UTF-8' => ["哈喽啊👋！我叫芒芒🙈！我很喜欢吃香蕉🍌!", "\"哈喽啊👋！我叫芒芒🙈！我很喜欢吃香蕉🍌!\""]
        ];
    }

    /**
     * Data provider for test_seb_json_encode_randomised.
     *
     * @return array List of test cases and expected results.
     */
    public function seb_json_encode_randomised_data_provider(): array {
        $utf8multibytesentences = ["我是你爸爸", "من پدرت هستم", "나는 당신의 아빠입니다", "私はあなたのお父さんです", "я твой папа", "😁😁😁"];
        $utf8singlebytesentences = ["Hello! How's it going?", "Pretty good, thanks", "The quick brown fox jumps over the lazy dog"];

        return [
            'A random multibyte UTF-8 sentence' => [$utf8multibytesentences[array_rand($utf8multibytesentences)]],
            'A random single byte UTF-8 sentence' => [$utf8singlebytesentences[array_rand($utf8singlebytesentences)]]
        ];
    }
}

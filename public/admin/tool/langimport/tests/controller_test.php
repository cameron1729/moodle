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

namespace tool_langimport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for \tool_langimport\locale class.
 *
 * @package    tool_langimport
 * @category   test
 * @copyright  2023 Andrew Lyons <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\tool_langimport\controller::class)]
#[CoversClass(\tool_langimport\task\install_langpacks::class)]
final class controller_test extends \advanced_testcase {

    /**
     * Installed and updated packs are recorded and linked from the notification.
     *
     * @param bool $updating Whether this is an update of existing language packs.
     */
    #[DataProvider('install_languagepacks_provider')]
    public function test_install_languagepacks_readme_links(bool $updating): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        foreach (['xx_child', 'xx'] as $lang) {
            $directory = make_upload_directory('lang/' . $lang);
            file_put_contents("{$directory}/langconfig.php", "<?php\n\$string['thislanguage'] = 'Test language';\n");
        }
        get_string_manager()->reset_caches();

        \curl::mock_response('');
        $controller = new controller();
        $installer = $this->createMock(\lang_installer::class);
        $installer->expects($this->once())->method('set_queue')->with(['xx_child']);
        $installer->method('run')->willReturn([
            'xx_child' => \lang_installer::RESULT_INSTALLED,
            'xx' => \lang_installer::RESULT_INSTALLED,
            'xx_other' => \lang_installer::RESULT_UPTODATE,
        ]);
        $property = new \ReflectionProperty(controller::class, 'installer');
        $property->setValue($controller, $installer);

        $this->assertSame(2, $controller->install_languagepacks(['xx_child'], $updating));
        $this->assertSame(['xx_child', 'xx'], $controller->changedlangs);

        $task = new \tool_langimport\task\install_langpacks();
        $task->set_userid($USER->id);
        $sink = $this->redirectMessages();

        $method = new \ReflectionMethod($task, 'notify_user_success');
        $method->invoke($task, $controller);

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $url = new \moodle_url($messages[0]->contexturl);
        $this->assertSame(['xx_child', 'xx'], $url->get_param('readme'));
    }

    /**
     * Installation and update scenarios.
     *
     * @return array Installation and update test cases.
     */
    public static function install_languagepacks_provider(): array {
        return [
            'Installation' => [false],
            'Update' => [true],
        ];
    }

    /**
     * Test uninstall of language with invalid values.
     *
     * @params string $lang
     */
    #[DataProvider('uninstall_lang_invalid_provider')]
    public function test_uninstall_lang_invalid(string $lang): void {
        global $CFG;

        $controller = new controller();
        $this->assertFalse($controller->uninstall_language($lang));
        $this->assertFileExists("{$CFG->dataroot}/lang");
        $this->assertFileExists("{$CFG->dirroot}/lang/en");
    }

    /**
     * Data provider for uninstall_lang tests with invalid values.
     *
     * @return array
     */
    public static function uninstall_lang_invalid_provider(): array {
        return [
            'Empty string' => [''],
            'Meaningless empty string' => [' '],
            'Default language' => ['en'],
            'Invalid language string' => ['swedish'],
        ];
    }
}

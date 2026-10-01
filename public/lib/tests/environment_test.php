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

namespace core;

use core\tests\environment as environment_tester;

/**
 * Tests for the \core\environment class.
 *
 * @package    core
 * @category   test
 * @copyright  Andrew Lyons <andrew@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(environment::class)]
final class environment_test extends \advanced_testcase {
    public function test_composer_installed(): void {
        \org\bovigo\vfs\vfsStream::setup('root', null, [
            'vendor' => [
                'autoload.php' => '',
                'composer' => [
                    'installed.php' => '',
                ],
            ],
        ]);

        environment_tester::set_vendor_path(\org\bovigo\vfs\vfsStream::url('root/vendor'));

        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_dependencies_installed($result);
        $this->assertNull($result);
    }

    /**
     * Check that only installed packages with mismatched versions block installation.
     *
     * @param array|null $installed Installed package versions, or null when Composer has not been run
     * @param string|null $feedback Expected package diagnostic, or null when the check passes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('composer_dependencies_provider')]
    public function test_composer_dependencies_current(?array $installed, ?string $feedback): void {
        $tempdir = make_request_directory();
        $composer = new \core\tests\testable_composer($tempdir . '/vendor', $tempdir . '/composer.lock');
        $composer->set_composer_lock(['package/test' => 'v2.0.0']);
        if ($installed !== null) {
            $composer->create_composer_installed_files();
            $composer->set_installed_versions($installed);
        }
        \core\di::set(\core\composer::class, $composer);

        $result = environment_tester::check_composer_dependencies_current(new \environment_results('custom_check'));
        if ($feedback === null) {
            $this->assertNull($result);
            return;
        }

        $this->assertNotNull($result);
        $this->assertFalse($result->getStatus());
        $this->assertSame(['composerdependenciesoutdated', 'admin', $feedback], $result->getFeedbackStr());
    }

    /**
     * Composer installations with matching, mismatched or missing packages.
     *
     * @return array
     */
    public static function composer_dependencies_provider(): array {
        return [
            'current' => [['package/test' => '2.0.0'], null],
            'outdated' => [['package/test' => '1.0.0'], 'package/test (1.0.0 → 2.0.0)'],
            'missing package' => [[], null],
            'Composer not installed' => [null, null],
        ];
    }

    public function test_composer_dev_installed(): void {
        \org\bovigo\vfs\vfsStream::setup('root', null, [
            'vendor' => [
                'autoload.php' => '',
                'composer' => [
                    'installed.php' => '<?php return ["root" => ["dev" => true]];',
                ],
            ],
        ]);
        environment_tester::set_vendor_path(\org\bovigo\vfs\vfsStream::url('root/vendor'));
        environment_tester::set_developer_mode(false);

        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_developer_dependencies_not_installed($result);
        $this->assertEquals('composerdeveloperdependenciesinstalled', $result->getFeedbackStr());

        // Check that the dependencies tests do not error in these conditions.
        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_dependencies_installed($result);
        $this->assertNull($result);
    }

    public function test_composer_dev_installed_with_developer_mode(): void {
        \org\bovigo\vfs\vfsStream::setup('root', null, [
            'vendor' => [
                'autoload.php' => '',
                'composer' => [
                    'installed.php' => '<?php return ["root" => ["dev" => true]];',
                ],
            ],
        ]);
        environment_tester::set_vendor_path(\org\bovigo\vfs\vfsStream::url('root/vendor'));
        environment_tester::set_developer_mode(true);

        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_developer_dependencies_not_installed($result);
        $this->assertNull($result);

        // Check that the dependencies tests do not error in these conditions.
        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_dependencies_installed($result);
        $this->assertNull($result);
    }

    public function test_composer_dev_not_installed(): void {
        \org\bovigo\vfs\vfsStream::setup('root', null, [
            'vendor' => [
                'autoload.php' => '',
                'composer' => [
                    'installed.php' => '<?php return ["root" => ["dev" => false]];',
                ],
            ],
        ]);
        environment_tester::set_vendor_path(\org\bovigo\vfs\vfsStream::url('root/vendor'));

        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_developer_dependencies_not_installed($result);
        $this->assertNull($result);

        // Check that the dependencies tests do not error in these conditions.
        $result = new \environment_results('custom_check');
        $result = environment_tester::check_composer_dependencies_installed($result);
        $this->assertNull($result);
    }
}

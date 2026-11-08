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

namespace tool_langimport;

use PHPUnit\Framework\Attributes\CoversClass;
use tool_langimport\output\readmes;

/**
 * Tests for language pack notes.
 *
 * @package    tool_langimport
 * @category   test
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(readmes::class)]
final class readme_test extends \advanced_testcase {
    /**
     * Create a parent pack and child packs with missing and empty notes.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->write_strings('xx', 'langconfig', ['thislanguage' => 'Parent language', 'parentlanguage' => 'en']);
        $this->write_strings('xx', 'tool_langimport', ['readme' => 'Parent pack notes']);
        foreach (['xx_child', 'xx_empty'] as $lang) {
            $this->write_strings($lang, 'langconfig', ['thislanguage' => $lang, 'parentlanguage' => 'xx']);
        }
        $this->write_strings('xx_empty', 'tool_langimport', ['readme' => " \n\t"]);
        get_string_manager()->reset_caches();
    }

    /**
     * Write a language file for a test pack.
     *
     * @param string $lang Language code, optionally ending in _local.
     * @param string $component Component filename.
     * @param array $strings String identifiers and values.
     */
    private function write_strings(string $lang, string $component, array $strings): void {
        $directory = make_upload_directory('lang/' . $lang);
        $contents = "<?php\n";
        foreach ($strings as $identifier => $value) {
            $contents .= '$string[' . var_export($identifier, true) . '] = ' . var_export($value, true) . ";\n";
        }
        file_put_contents("{$directory}/{$component}.php", $contents);
    }

    /**
     * Export language pack notes for testing.
     *
     * @param array $expandedlangs Language codes whose notes should be expanded.
     * @return array Template data.
     */
    private function export_readmes(array $expandedlangs = []): array {
        global $PAGE;

        $PAGE->set_context(\context_system::instance());
        return (new readmes($expandedlangs))->export_for_template($PAGE->get_renderer('tool_langimport'));
    }

    /**
     * Export each pack's own notes and expand the requested packs.
     */
    public function test_export_readmes(): void {
        $this->write_strings('en_local', 'tool_langimport', ['readme' => 'English notes']);
        $this->write_strings('xx_none', 'langconfig', ['thislanguage' => 'No notes', 'parentlanguage' => 'en']);
        get_string_manager()->reset_caches();

        $languages = get_string_manager()->get_list_of_translations(true);
        $data = $this->export_readmes();
        $notes = array_column($data['readmes'], 'readme', 'name');
        $this->assertEqualsCanonicalizing([$languages['en'], $languages['xx']], array_keys($notes));
        $this->assertStringContainsString('English notes', $notes[$languages['en']]);
        $this->assertStringContainsString('Parent pack notes', $notes[$languages['xx']]);
        $this->assertSame([false, false], array_column($data['readmes'], 'expanded'));

        $this->write_strings('xx_child', 'tool_langimport', ['readme' => 'Child pack notes']);
        $data = $this->export_readmes(['xx_child', '', 'xx_unknown', '../xx', 'xx_local']);
        $notes = array_column($data['readmes'], null, 'name');
        $this->assertCount(3, $notes);
        $this->assertStringContainsString('Child pack notes', $notes[$languages['xx_child']]['readme']);
        $this->assertTrue($notes[$languages['xx_child']]['expanded']);
        $this->assertFalse($notes[$languages['xx']]['expanded']);
        $this->assertFalse($notes[$languages['en']]['expanded']);
    }

    /**
     * A pack's own local customisations can replace or suppress its notes.
     */
    public function test_local_customisations(): void {
        global $CFG;

        $CFG->langlocalroot = make_temp_directory('langimport_local');
        mkdir($CFG->langlocalroot . '/xx_local');
        $file = $CFG->langlocalroot . '/xx_local/tool_langimport.php';

        file_put_contents($file, "<?php\n\$string['pluginname'] = 'Custom name';\n");
        $data = $this->export_readmes();
        $this->assertCount(1, $data['readmes']);
        $this->assertStringContainsString('Parent pack notes', $data['readmes'][0]['readme']);

        file_put_contents($file, "<?php\n\$string['readme'] = 'Local pack notes';\n");
        $data = $this->export_readmes();
        $this->assertCount(1, $data['readmes']);
        $this->assertStringContainsString('Local pack notes', $data['readmes'][0]['readme']);

        file_put_contents($file, "<?php\n\$string['readme'] = '';\n");
        $data = $this->export_readmes();
        $this->assertSame([], $data['readmes']);
    }

    /**
     * Exported notes use Markdown formatting and HTML cleaning.
     */
    public function test_export_formatted_notes(): void {
        $text = "**Setup**\n\n    @font-face { font-family: 'Tengwar'; }\n\n";
        $text .= "<script>alert('unsafe')</script>\n\n";
        $text .= '<span lang="en" class="multilang">English notes</span>';
        $text .= '<span lang="fr" class="multilang">French notes</span>';
        $this->write_strings('xx', 'tool_langimport', ['readme' => $text]);
        filter_set_global_state('multilang', TEXTFILTER_ON);

        $data = $this->export_readmes();
        $notes = $data['readmes'][0]['readme'];

        $this->assertStringContainsString('Setup', $notes);
        $this->assertStringNotContainsString('**Setup**', $notes);
        $this->assertStringContainsString('@font-face', $notes);
        $this->assertStringNotContainsString('<script', $notes);
        $this->assertStringContainsString('English notes', $notes);
        $this->assertStringContainsString('French notes', $notes);
    }
}

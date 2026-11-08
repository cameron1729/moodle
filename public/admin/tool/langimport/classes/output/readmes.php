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

namespace tool_langimport\output;

use context_system;
use renderable;
use renderer_base;
use templatable;

/**
 * Notes supplied by installed language packs.
 *
 * @package    tool_langimport
 * @copyright  2026 Cameron Ball <cameron@cameron1729.xyz>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class readmes implements renderable, templatable {
    /**
     * Create the language pack notes renderable.
     *
     * @param array $expandedlangs Language codes whose notes should initially be expanded.
     */
    public function __construct(
        /** @var array Language codes whose notes should initially be expanded. */
        private array $expandedlangs = [],
    ) {
    }

    /**
     * Export language pack notes for the template.
     *
     * @param renderer_base $output Renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $languages = get_string_manager()->get_list_of_translations(true);
        $langs = array_keys($languages);
        $export = fn(string $lang, string $text): array => match (trim($text)) {
            '' => [],
            default => [
                'name' => $languages[$lang],
                'expanded' => in_array($lang, $this->expandedlangs, true),
                'readme' => format_text($text, FORMAT_MARKDOWN, ['context' => context_system::instance(), 'filter' => false]),
            ],
        };

        return ['readmes' => array_values(array_filter(array_map($export, $langs, array_map($this->get_readme(...), $langs))))];
    }

    /**
     * Get the pack's Markdown notes, including its own local customisations.
     *
     * These describe the particular pack, so the normal string manager fallback
     * to parent languages and English must not be used.
     *
     * @param string $lang Installed language code.
     * @return string The notes, or an empty string when none are supplied.
     */
    private function get_readme(string $lang): string {
        global $CFG;

        $string = [];
        $files = [
            dirname(__DIR__, 2) . "/lang/{$lang}/tool_langimport.php",
            "{$CFG->langotherroot}/{$lang}/tool_langimport.php",
            "{$CFG->langlocalroot}/{$lang}_local/tool_langimport.php",
        ];

        foreach (array_filter($files, is_readable(...)) as $file) {
            include($file);
        }

        return $string['readme'] ?? '';
    }
}

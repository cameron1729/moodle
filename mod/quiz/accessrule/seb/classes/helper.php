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
 * Helper class.
 *
 * @package    quizaccess_seb
 * @author     Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @copyright  2020 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_seb;


use CFPropertyList\CFPropertyList;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class.
 *
 * @copyright  2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Get a filler icon for display in the actions column of a table.
     *
     * @param string $url The URL for the icon.
     * @param string $icon The icon identifier.
     * @param string $alt The alt text for the icon.
     * @param string $iconcomponent The icon component.
     * @param array $options Display options.
     * @return string
     */
    public static function format_icon_link($url, $icon, $alt, $iconcomponent = 'moodle', $options = array()) {
        global $OUTPUT;

        return $OUTPUT->action_icon(
            $url,
            new \pix_icon($icon, $alt, $iconcomponent, [
                'title' => $alt,
            ]),
            null,
            $options
        );
    }

    /**
     * Validate seb config string.
     *
     * @param string $sebconfig
     * @return bool
     */
    public static function is_valid_seb_config(string $sebconfig) : bool {
        $result = true;

        set_error_handler(function($errno, $errstr, $errfile, $errline ){
            throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
        });

        $plist = new CFPropertyList();
        try {
            $plist->parse($sebconfig);
        } catch (\ErrorException $e) {
            $result = false;
        } catch (\Exception $e) {
            $result = false;
        }

        restore_error_handler();

        return $result;
    }

    /**
     * A helper function to get a list of seb config file headers.
     *
     * @param int|null $expiretime  Unix timestamp
     * @return array
     */
    public static function get_seb_file_headers(int $expiretime = null) : array {
        if (is_null($expiretime)) {
            $expiretime = time();
        }
        $headers = [];
        $headers[] = 'Cache-Control: private, max-age=1, no-transform';
        $headers[] = 'Expires: '. gmdate('D, d M Y H:i:s', $expiretime) .' GMT';
        $headers[] = 'Pragma: no-cache';
        $headers[] = 'Content-Disposition: attachment; filename=config.seb';
        $headers[] = 'Content-Type: application/seb';

        return $headers;
    }

    /**
     * Get seb config content for a particular quiz. This method checks caps.
     *
     * @param string $cmid The course module ID for a quiz with config.
     * @return string SEB config string.
     */
    public static function get_seb_config_content(string $cmid) : string {
        // Try and get the course module.
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);

        // Make sure the user is logged in and has access to the module.
        require_login($cm->course, false, $cm);

        // Retrieve the config for quiz.
        $config = quiz_settings::get_config_by_quiz_id($cm->instance);
        if (empty($config)) {
            throw new \moodle_exception('noconfigfound', 'quizaccess_seb', '', $cm->id);
        }
        return $config;
    }

    /**
     * Serve a file to browser for download.
     *
     * @param string $contents Contents of file.
     */
    public static function send_seb_config_file(string $contents) {
        // We can now send the file back to the browser.
        foreach (self::get_seb_file_headers() as $header) {
            header($header);
        }

        echo($contents);
    }

    /**
     * Creates a "SEB-JSON" string.
     *
     * "SEB-JSON" strings are specified uner the "Summary Config Key Generation" section of the developer
     * documentation: https://safeexambrowser.org/developer/seb-config-key.html
     *
     * This function handles the transforming of native PHP data type in to "SEB-JSON". In particular it
     * handles the requirement to not add whitespace or character escaping.
     *
     * Note that even though the documention says not to add any white space or line formatting, we have
     * nonetheless observed "SEB-JSON" containing U+0020 (" "). Unfortunately the language used:
     *
     *     "Don't add any whitespace or line formatting to the SEB-JSON string."
     *
     * Is not precise enough. Does it mean don't add additional whitespace or line formatting (i.e., preserve
     * existing white space and line formatting), or does it mean don't add white space or line formatting
     * at all (i.e., strip out whitespace and line formatting).
     *
     * A parameter, $preserve is provided to prevent specific characters from being stripped out.
     *
     * @param mixed $data Data to encode as a "SEB-JSON" string.
     * @param array $preserve Array of strings to preserve.
     * @return string The "SEB-JSON" string.
     */
    public static function seb_json_encode($data, array $preserve = []): string {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F\s]/',
            fn(array $match): string => in_array($match[0], $preserve) ? $match[0] : '',
            stripcslashes(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        );
    }
}

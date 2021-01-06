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
 * This file contains the definition for the renderable assign header.
 *
 * @package   mod_assign
 * @copyright 2020 Matt Porritt <mattp@catalyst-au.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_assign\output;

/**
 * This file contains the definition for the renderable assign header.
 *
 * @package   mod_assign
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_header implements \renderable {
    /** @var \stdClass the assign record.  */
    public $assign = null;
    /** @var mixed context|null the context record.  */
    public $context = null;
    /** @var bool $showintro - show or hide the intro. */
    public $showintro = false;
    /** @var int coursemoduleid - The course module id. */
    public $coursemoduleid = 0;
    /** @var string $subpage optional subpage (extra level in the breadcrumbs). */
    public $subpage = '';
    /** @var string $preface optional preface (text to show before the heading). */
    public $preface = '';
    /** @var string $postfix optional postfix (text to show after the intro). */
    public $postfix = '';
    /** @var \moodle_url $subpageurl link for the subpage */
    public $subpageurl = null;

    /**
     * Constructor
     *
     * @param \stdClass $assign  - the assign database record.
     * @param mixed $context context|null the course module context.
     * @param bool $showintro  - show or hide the intro.
     * @param int $coursemoduleid  - the course module id.
     * @param string $subpage  - an optional sub page in the navigation.
     * @param string $preface  - an optional preface to show before the heading.
     * @param string $postfix  - optionsa text to show after the intro.
     */
    public function __construct(
        \stdClass $assign,
        $context,
        $showintro,
        $coursemoduleid,
        $subpage = '',
        $preface = '',
        $postfix = '',
        \moodle_url $subpageurl = null
    ) {
        $this->assign = $assign;
        $this->context = $context;
        $this->showintro = $showintro;
        $this->coursemoduleid = $coursemoduleid;
        $this->subpage = $subpage;
        $this->preface = $preface;
        $this->postfix = $postfix;
        $this->subpageurl = $subpageurl;
    }
}

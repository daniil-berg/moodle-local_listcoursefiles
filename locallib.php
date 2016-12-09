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
 * Internal API of local listcoursefiles.
 *
 * @package    local_listcoursefiles
 * @copyright  2016 Martin Gauk (@innoCampus, TU Berlin)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Maximum number of files per page.
 * @var int
 */
define('LISTCOURSEFILES_MAX_FILES', 500);

class Course_files {
    /**
     * @var context
     */
    protected $context;

    /**
     * @var int
     */
    protected $filescount = -1;

    /**
     * @var array
     */
    protected $components = null;

    /**
     * @var array
     */
    protected $filelist = null;
    protected $licenses = null;
    protected $licenscolors = null;

    /**
     * @var string
     */
    protected $filtercomponent;
    protected $filterfiletype;

    /**
     * @var course_modinfo
     */
    protected $coursemodinfo;

    /**
     * @var int
     */
    protected $courseid;

    /**
     * Mapping of file types to possible mime types.
     * @var array
     */
    static protected $mimetypes = array(
        'document' => array('application/pdf', 'application/epub+zip', 'application/vnd.ms-%',
            'application/vnd.openxmlformats-officedocument%'),
        'image' => array('image/%'),
        'audio' => array('audio/%'),
        'video' => array('video/%'),
        'text' => array('text/%', 'application/x-tex'),
        'archive' => array('application/zip', 'application/x-tar', 'application/g-zip',
            'application/x-rar-compressed', 'application/x-7z-compressed', 'application/vnd.moodle.backup'),
    );

    public function __construct($courseid, context $context, $component, $filetype) {
        $this->courseid = $courseid;
        $this->context = $context;
        $this->filtercomponent = $component;
        $this->filterfiletype = $filetype;
        $this->coursemodinfo = get_fast_modinfo($courseid);
    }

    /**
     * Retrieve the files within a course/context.
     *
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function get_file_list($offset, $limit) {
        global $DB;

        if ($this->filelist !== null) {
            return $this->filelist;
        }

        $availcomponents = $this->get_components();
        $sqlwhere = '';
        $sqlwherecomponent = '';
        if ($this->filtercomponent === 'all_wo_submissions') {
            $sqlwhere .= 'AND f.component NOT LIKE :component';
            $sqlwherecomponent = 'assign%';
        } else if ($this->filtercomponent !== 'all' && isset($availcomponents[$this->filtercomponent])) {
            $sqlwhere .= 'AND f.component LIKE :component';
            $sqlwherecomponent = $this->filtercomponent;
        }

        if ($this->filterfiletype === 'other') {
            $sqlwhere .= ' AND ' . $this->get_sql_mimetype(array_keys(self::$mimetypes), false);
        } else if (isset(self::$mimetypes[$this->filterfiletype])) {
            $sqlwhere .= ' AND ' . $this->get_sql_mimetype($this->filterfiletype, true);
        }

        $usernamefields = get_all_user_name_fields(true, 'u');

        $sql = 'FROM {files} f
                LEFT JOIN {context} c ON (c.id = f.contextid)
                LEFT JOIN {user} u ON (u.id = f.userid)
                WHERE f.filename NOT LIKE \'.\'
                    AND (c.path LIKE :path OR c.id = :cid) ' . $sqlwhere;

        $sqlselectfiles = 'SELECT f.*, c.contextlevel, c.instanceid,' . $usernamefields .
            ' ' . $sql . ' ORDER BY f.component, f.filename';

        $params = array(
            'path' => $this->context->path . '/%',
            'cid' => $this->context->id,
            'component' => $sqlwherecomponent,
        );

        $this->filelist = $DB->get_records_sql($sqlselectfiles, $params, $offset, $limit);

        // Determine number of all files.
        if (count($this->filelist) < $limit) {
            $this->filescount = count($this->filelist) + $offset;
        } else {
            $sqlcount = 'SELECT COUNT(*) ' . $sql;
            $this->filescount = $DB->count_records_sql($sqlcount, $params);
        }

        return $this->filelist;
    }

    protected function get_sql_mimetype($types, $in) {
        if (is_array($types)) {
            $list = array();
            foreach ($types as $type) {
                $list = array_merge($list, self::$mimetypes[$type]);
            }
        } else {
            $list = &self::$mimetypes[$types];
        }

        if ($in) {
            $first = "(f.mimetype LIKE '";
            $glue = "' OR f.mimetype LIKE '";
        } else {
            $first = "(f.mimetype NOT LIKE '";
            $glue = "' AND f.mimetype NOT LIKE '";
        }

        return $first . implode($glue, $list) . "')";
    }

    /**
     * Returns the number of files in a component and with a specific file type.
     * May only be called after get_file_list.
     */
    public function get_file_list_total_size() {
        return $this->filescount;
    }

    /**
     * Get all available components with files.
     * @return array
     */
    public function get_components() {
        global $DB;

        if ($this->components !== null) {
            return $this->components;
        }

        $sql = 'SELECT f.component
                FROM {files} f
                LEFT JOIN {context} c ON (c.id = f.contextid)
                WHERE f.filename NOT LIKE \'.\'
                    AND (c.path LIKE :path OR c.id = :cid)
                GROUP BY f.component';

        $params = array('path' => $this->context->path . '/%', 'cid' => $this->context->id);
        $ret = $DB->get_fieldset_sql($sql, $params);

        $this->components = array();
        foreach ($ret as $r) {
            $this->components[$r] = get_component_translation($r);
        }

        asort($this->components, SORT_STRING | SORT_FLAG_CASE);
        $componentsall = array(
            'all' => get_string('all_files', 'local_listcoursefiles'),
            'all_wo_submissions' => get_string('all_wo_submissions', 'local_listcoursefiles'),
        );
        $this->components = $componentsall + $this->components;

        return $this->components;
    }

    public function get_available_licenses() {
        global $CFG;

        if ($this->licenses === null) {
            $this->licenses = array();
            $a = explode(',', $CFG->licenses);
            foreach ($a as $license) {
                $this->licenses[$license] = get_string($license, 'license');
            }
        }

        return $this->licenses;
    }

    /**
     *
     * @param string short name of a license
     * @return full name of the license with HTML
     */
    public function get_license_name_color($licenseshort) {
        $licenses = $this->get_available_licenses();

        if ($this->licenscolors === null) {
            $colorscfg = get_config('local_listcoursefiles', 'licensecolors');
            $matches = array();
            preg_match_all('@\s*(\S+)\s*([a-fA-F0-9]{6})\s*@', $colorscfg, $matches, PREG_SET_ORDER);
            $this->licenscolors = array();
            foreach ($matches as $m) {
                $this->licenscolors[$m[1]] = $m[2];
            }
        }

        $name = (isset($licenses[$licenseshort])) ? $licenses[$licenseshort] : '';
        if (isset($this->licenscolors[$licenseshort])) {
            $name = html_writer::tag('span', $name, array('style' => 'color: #' . $this->licenscolors[$licenseshort]));
        }
        return $name;
    }

    /**
     * Change the license of multiple files.
     *
     * @param array $fileids keys are the file IDs
     * @param string $license shortname of the license
     */
    public function set_files_license($fileids, $license) {
        global $DB;

        $licenses = $this->get_available_licenses();
        if (!isset($licenses[$license])) {
            throw new moodle_exception('invalid_license', 'local_listcoursefiles');
        }

        if (count($fileids) > LISTCOURSEFILES_MAX_FILES) {
            throw new moodle_exception('too_many_files', 'local_listcoursefiles');
        }

        if (count($fileids) == 0) {
            return;
        }

        // Check if the given files really belong to the context.
        list($sqlin, $paramfids) = $DB->get_in_or_equal(array_keys($fileids), SQL_PARAMS_QM);
        $sql = 'SELECT f.id, f.contextid, c.path
                FROM {files} f
                JOIN {context} c ON (c.id = f.contextid)
                WHERE f.id ' . $sqlin;
        $res = $DB->get_records_sql($sql, $paramfids);

        $thiscontextpath = $this->context->path;
        $thiscontextpathlen = strlen($this->context->path);
        $thiscontextid = $this->context->id;
        $checkedfileids = array();
        foreach ($res as $f) {
            if ($f->contextid == $thiscontextid ||
                substr($f->path, 0, $thiscontextpathlen) === $thiscontextpath) {

                $checkedfileids[] = $f->id;
            }
        }

        list($sqlin, $paramfids) = $DB->get_in_or_equal($checkedfileids, SQL_PARAMS_QM);
        $transaction = $DB->start_delegated_transaction();
        $sql = 'UPDATE {files}
                SET license = ?
                WHERE id ' . $sqlin;
        $DB->execute($sql, array_merge(array($license), $paramfids));

        foreach ($checkedfileids as $fid) {
            $event = local_listcoursefiles\event\license_changed::create(array(
                'context' => $this->context,
                'objectid' => $fid,
                'other' => array('license' => $license),
            ));
            $event->trigger();
        }
        $transaction->allow_commit();
    }

    /**
     * Try to get the url for the component (module or course).
     *
     * @param int contextlevel
     * @param int instanceid
     * @return null|moodle_url
     */
    public function get_component_url($contextlevel, $instanceid) {
        if ($contextlevel == CONTEXT_MODULE) {
            if (!empty($this->coursemodinfo->cms[$instanceid])) {
                return $this->coursemodinfo->cms[$instanceid]->url;
            }
        } else if ($contextlevel == CONTEXT_COURSE) {
            return new moodle_url('/course/view.php', array('id' => $this->courseid));
        }

        return null;
    }

    /**
     * Try to get the download url for a file.
     *
     * @param array $file
     * @return null|moodle_url
     */
    public function get_file_download_url($file) {
        switch ($file->component . '#' . $file->filearea) {
            case 'mod_folder#intro':
            case 'mod_folder#content':
            case 'mod_resource#intro':
            case 'mod_resource#content':
                return new moodle_url('/pluginfile.php/'. $file->contextid . '/' . $file->component . '/' .
                        $file->filearea . '/0' . $file->filepath . $file->filename);

            case 'mod_assign#intro':
            case 'mod_label#intro':
                return new moodle_url('/pluginfile.php/'. $file->contextid . '/' . $file->component . '/' .
                        $file->filearea . '/' . $file->filepath . $file->filename);

            case 'assignsubmission_file#submission_files':
            case 'mod_assign#introattachment':
            case 'mod_data#content':
            case 'mod_forum#post':
            case 'mod_forum#attachment':
            case 'mod_page#content':
            case 'mod_page#intro':
            case 'mod_glossary#entry':
            case 'mod_wiki#attachments':
            case 'course#section':
                return new moodle_url('/pluginfile.php/'. $file->contextid . '/' . $file->component . '/' .
                        $file->filearea . '/' . $file->itemid . $file->filepath . $file->filename);

            case 'course#legacy':
                return new moodle_url('/file.php/'. $this->courseid . $file->filepath . $file->filename);
        }

        return null;
    }

    public static function get_file_types() {
        $types = array('all' => get_string('filetype_all', 'local_listcoursefiles'));
        foreach (self::$mimetypes as $type => $unused) {
            $types[$type] = get_string('filetype_' . $type, 'local_listcoursefiles');
        }
        $types['other'] = get_string('filetype_other', 'local_listcoursefiles');
        return $types;
    }

    public static function get_file_type_translation($mimetype) {
        foreach (self::$mimetypes as $name => $types) {
            foreach ($types as $mime) {
                if ($mime === $mimetype ||
                        (substr($mime, -1) === '%' && strncmp($mime, $mimetype, strlen($mime) - 1) === 0)) {
                    return get_string('filetype_' . $name, 'local_listcoursefiles');
                }
            }
        }

        return $mimetype;
    }
}

function get_component_translation($name) {
    $translated = $name;
    if (get_string_manager()->string_exists('pluginname', $name)) {
        $translated = get_string('pluginname', $name);
    } else if (get_string_manager()->string_exists($name, '')) {
        $translated = get_string($name, '');
    }
    return $translated;
}

function print_course_selection(moodle_url $url, $currentcourseid) {
    global $OUTPUT;

    $url = clone $url;
    $url->remove_params('courseid', 'page');

    $availcourses = array();
    $allcourses = enrol_get_my_courses();
    foreach ($allcourses as $course) {
        $context = context_course::instance($course->id, IGNORE_MISSING);
        if (has_capability('local/listcoursefiles:view', $context)) {
            $availcourses[$course->id] = $course->shortname;
        }
    }

    return $OUTPUT->single_select($url, 'courseid', $availcourses, $currentcourseid, null, 'courseselector');
}

function print_component_selection(moodle_url $url, $allcomponents, $currentcomponent) {
    global $OUTPUT;

    $url = clone $url;
    $url->remove_params('page');

    return $OUTPUT->single_select($url, 'component', $allcomponents, $currentcomponent, null, 'componentselector');
}

function print_file_type_selection(moodle_url $url, $currenttype) {
    global $OUTPUT;

    $url = clone $url;
    $url->remove_params('page');

    return $OUTPUT->single_select($url, 'filetype', Course_files::get_file_types(), $currenttype, null, 'filetypeselector');
}

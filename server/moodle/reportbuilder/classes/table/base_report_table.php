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

declare(strict_types=1);

namespace core_reportbuilder\table;

use context;
use moodle_url;
use renderable;
use table_default_export_format_parent;
use table_sql;
use html_writer;
use core_table\dynamic;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\filters\base;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\report\base as base_report;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\output\dataformat_export_format;
use core\output\notification;

defined('MOODLE_INTERNAL') || die;

require_once("{$CFG->libdir}/tablelib.php");

/**
 * Base report dynamic table class
 *
 * @package     core_reportbuilder
 * @copyright   2021 David Matamoros <davidmc@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_report_table extends table_sql implements dynamic, renderable {

    /** @var report $persistent */
    protected $persistent;

    /** @var base_report $report */
    protected $report;

    /** @var string $groupbysql */
    protected $groupbysql = '';

    /** @var bool $applyfilters */
    protected $applyfilters = true;

    /**
     * Initialises table SQL properties
     *
     * @param string $fields
     * @param string $from
     * @param array $joins
     * @param string $where
     * @param array $params
     * @param array $groupby
     */
    protected function init_sql(string $fields, string $from, array $joins, string $where, array $params,
            array $groupby = []): void {

        $wheres = [];
        if ($where !== '') {
            $wheres[] = $where;
        }

        // For each condition, we need to ensure their values are always accounted for in the report.
        $conditionvalues = $this->report->get_condition_values();
        foreach ($this->report->get_active_conditions() as $condition) {
            [$conditionsql, $conditionparams] = $this->get_filter_sql($condition, $conditionvalues);
            if ($conditionsql !== '') {
                $joins = array_merge($joins, $condition->get_joins());
                $wheres[] = "({$conditionsql})";
                $params = array_merge($params, $conditionparams);
            }
        }

        // For each filter, we also need to apply their values (will differ according to user viewing the report).
        if ($this->applyfilters) {
            $filtervalues = $this->report->get_filter_values();
            foreach ($this->report->get_active_filters() as $filter) {
                [$filtersql, $filterparams] = $this->get_filter_sql($filter, $filtervalues);
                if ($filtersql !== '') {
                    $joins = array_merge($joins, $filter->get_joins());
                    $wheres[] = "({$filtersql})";
                    $params = array_merge($params, $filterparams);
                }
            }
        }

        // Join all the filters into a SQL WHERE clause, falling back to all records.
        if (!empty($wheres)) {
            $wheresql = implode(' AND ', $wheres);
        } else {
            $wheresql = '1=1';
        }

        if (!empty($groupby)) {
            $this->groupbysql = 'GROUP BY ' . implode(', ', $groupby);
        }

        // Add unique table joins.
        $from .= ' ' . implode(' ', array_unique($joins));

        $this->set_sql($fields, $from, $wheresql, $params);

        $counttablealias = database::generate_alias();
        $this->set_count_sql("
            SELECT COUNT(1)
              FROM (SELECT {$fields}
                      FROM {$from}
                     WHERE {$wheresql}
                           {$this->groupbysql}
                   ) {$counttablealias}", $params);
    }

    /**
     * Whether active filters should be applied to the report, defaults to true except in the case where we are editing a report
     * and we do not want filters to be applied to it
     *
     * @param bool $applyfilters
     */
    public function set_filters_applied(bool $applyfilters): void {
        $this->applyfilters = $applyfilters;
    }

    /**
     * Return SQL fragments from given filter instance suitable for inclusion in table SQL
     *
     * @param filter $filter
     * @param array $filtervalues
     * @return array [$sql, $params]
     */
    private function get_filter_sql(filter $filter, array $filtervalues): array {
        /** @var base $filterclass */
        $filterclass = $filter->get_filter_class();

        return $filterclass::create($filter)->get_sql_filter($filtervalues);
    }

    /**
     * Override parent method of the same, to make use of a recordset and avoid issues with duplicate values in the first column
     *
     * @param int $pagesize
     * @param bool $useinitialsbar
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;

        $sql = "SELECT {$this->sql->fields} FROM {$this->sql->from} WHERE {$this->sql->where} {$this->groupbysql}";

        $sort = $this->get_sql_sort();
        if ($sort) {
            $sql .= " ORDER BY {$sort}";
        }

        if (!$this->is_downloading()) {
            $this->pagesize($pagesize, $DB->count_records_sql($this->countsql, $this->countparams));

            $this->rawdata = $DB->get_recordset_sql($sql, $this->sql->params, $this->get_page_start(), $this->get_page_size());
        } else {
            $this->rawdata = $DB->get_recordset_sql($sql, $this->sql->params);
        }
    }

    /**
     * Set the export class to use when downloading reports (TODO: consider applying to all tables, MDL-72058)
     *
     * @param table_default_export_format_parent|null $exportclass
     * @return table_default_export_format_parent|null
     */
    public function export_class_instance($exportclass = null) {
        if (is_null($this->exportclass) && $this->is_downloading()) {
            $this->exportclass = new dataformat_export_format($this, $this->download);
            if (!$this->exportclass->document_started()) {
                $this->exportclass->start_document($this->filename, $this->sheettitle);
            }
        }

        return $this->exportclass;
    }

    /**
     * Get the context for the table (that of the report persistent)
     *
     * @return context
     */
    public function get_context(): context {
        return $this->persistent->get_context();
    }

    /**
     * Set the base URL of the table to the current page URL
     */
    public function guess_base_url(): void {
        $this->baseurl = new moodle_url('/');
    }

    /**
     * Override print_nothing_to_display to modity the output styles.
     */
    public function print_nothing_to_display() {
        global $OUTPUT;

        echo $this->get_dynamic_table_html_start();
        echo $this->render_reset_button();

        echo $OUTPUT->render(new notification(get_string('nothingtodisplay'), notification::NOTIFY_INFO, false));

        echo $this->get_dynamic_table_html_end();
    }

    /**
     * Override start of HTML to remove top pagination.
     */
    public function start_html() {
        // Render the dynamic table header.
        echo $this->get_dynamic_table_html_start();

        // Render button to allow user to reset table preferences.
        echo $this->render_reset_button();

        $this->wrap_html_start();

        echo html_writer::start_tag('div', ['class' => 'no-overflow']);
        echo html_writer::start_tag('table', $this->attributes);
    }
}

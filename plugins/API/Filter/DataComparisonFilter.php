<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\API\Filter;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataTable;
use Piwik\DataTable\Simple;
use Piwik\Metrics;
use Piwik\Metrics\Formatter;
use Piwik\Period;
use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Segment;
use Piwik\Segment\SegmentExpression;

// TODO: unit test

// TODO: if comparing days w/ non-days, in html table & elsewhere, we must display nb_visits instead of nb_uniq_visitors

/**
 * TODO
 */
class DataComparisonFilter
{
    /**
     * @var array
     */
    private $request;

    /**
     * @var int
     */
    private $segmentCompareLimit;

    /**
     * @var int
     */
    private $periodCompareLimit;

    /**
     * @var array[]
     */
    private $availableSegments;

    /**
     * @var array
     */
    private $columnMappings;

    /**
     * @var string
     */
    private $segmentName;

    /**
     * @var string[]
     */
    private $compareSegments;

    /**
     * @var string[]
     */
    private $compareDates;

    /**
     * @var string[]
     */
    private $comparePeriods;

    /**
     * @var int[]
     */
    private $compareSegmentIndices;

    /**
     * @var int[]
     */
    private $comparePeriodIndices;

    public function __construct($request, Report $report = null)
    {
        $this->request = $request;

        $generalConfig = Config::getInstance()->General;
        $this->segmentCompareLimit = (int) $generalConfig['data_comparison_segment_limit'];
        $this->checkComparisonLimit($this->segmentCompareLimit, 'data_comparison_segment_limit');

        $this->periodCompareLimit = (int) $generalConfig['data_comparison_period_limit'];
        $this->checkComparisonLimit($this->periodCompareLimit, 'data_comparison_period_limit');

        $this->columnMappings = $this->getColumnMappings();

        $this->segmentName = $this->getSegmentNameFromReport($report);

        $this->compareSegments = Common::getRequestVar('compareSegments', $default = [], $type = 'array', $this->request);
        if (count($this->compareSegments) > $this->segmentCompareLimit) {
            throw new \Exception("The maximum number of segments that can be compared simultaneously is {$this->segmentCompareLimit}.");
        }

        $this->compareDates = Common::getRequestVar('compareDates', $default = [], $type = 'array', $this->request);
        $this->compareDates = array_values($this->compareDates);

        $this->comparePeriods = Common::getRequestVar('comparePeriods', $default = [], $type = 'array', $this->request);
        $this->comparePeriods = array_values($this->comparePeriods);

        if (count($this->compareDates) !== count($this->comparePeriods)) {
            throw new \InvalidArgumentException("compareDates query parameter length must match comparePeriods query parameter length.");
        }

        if (count($this->compareDates) > $this->periodCompareLimit) {
            throw new \Exception("The maximum number of periods that can be compared simultaneously is {$this->periodCompareLimit}.");
        }

        if (empty($this->compareSegments)
            && empty($this->comparePeriods)
        ) {
            throw new \Exception("compare=1 set, but no segments or periods to compare.");
        }

        // add base compare against segment and date
        array_unshift($this->compareSegments, isset($this->request['segment']) ? $this->request['segment'] : '');
        array_unshift($this->compareDates, ''); // for date/period, we use the metadata in the table to avoid requesting multiple periods
        array_unshift($this->comparePeriods, '');

        // map segments/periods to their indexes in the query parameter arrays for comparisonIdSubtable matching
        $this->compareSegmentIndices = array_flip($this->compareSegments);
        foreach ($this->comparePeriods as $index => $period) {
            $date = $this->compareDates[$index];
            $this->comparePeriodIndices[$period][$date] = $index;
        }
    }

    /**
     * TODO
     * - build permutations here
     * - query data, once per permutation, not once per datable combination
     * - then call filter
     *
     *
     * @param DataTable\DataTableInterface $table
     */
    public function compare(DataTable\DataTableInterface $table)
    {
        $method = Common::getRequestVar('method', $default = null, $type = 'string', $this->request);
        if ($method == 'Live') {
            throw new \Exception("Data comparison is not enabled for the Live API.");
        }

        $this->availableSegments = self::getAvailableSegments();

        $comparisonTotals = [];

        // fetch data first
        $reportsToCompare = $this->getReportsToCompare();
        foreach ($reportsToCompare as $index => $modifiedParams) {
            $metadata = $this->getMetadataFromModifiedParams($modifiedParams); // TODO: need to handle periods here

            $compareTable = $this->requestReport($metadata, $modifiedParams); // TODO: method should not be needed
            $this->compareTables($metadata, $table, $compareTable, $comparisonTotals); // TODO: set comparison totals here
        }

        // format comparison table metrics
        $this->formatComparisonTables($table);

        // TODO
    }

    /**
     * @param DataTable $table
     * @throws \Exception
     */
    private function filter($table)
    {
        foreach ($reportsToCompare as $modifiedParams) {


            $totals = $compareTable->getMetadata('totals');
            if (!empty($totals)) {
                $totals = $this->replaceIndexesInTotals($totals);
                $comparisonTotals[] = array_merge($metadata, [
                    'totals' => $totals,
                ]);
            }

            Common::destroy($compareTable);
            unset($compareTable);
        }


        // add comparison parameters as metadata
        if (!empty($segments)) {
            $table->setMetadata('compareSegments', $segments);
        }

        if (!empty($dates)) {
            $table->setMetadata('compareDates', $dates);
        }

        if (!empty($periods)) {
            $table->setMetadata('comparePeriods', $periods);
        }

        if (!empty($comparisonTotals)) {
            $table->setMetadata('comparisonTotals', $comparisonTotals);
        }
    }

    private function getReportsToCompare()
    {
        $permutations = [];

        // NOTE: the order of these loops determines the order of the rows in the comparison table. ie,
        // if we loop over dates then segments, then we'll see comparison rows change segments before changing
        // periods. this is because this loop determines in what order we fetch report data.
        foreach ($this->compareDates as $index => $date) {
            foreach ($this->compareSegments as $segment) {
                $period = $this->comparePeriods[$index];

                $params = [];
                $params['segment'] = $segment;

                if (!empty($period)
                    && !empty($date)
                ) {
                    $params['date'] = $date;
                    $params['period'] = $period;
                }

                $permutations[] = $params;
            }
        }
        return $permutations;
    }

    /**
     * @param $paramsToModify
     * @return DataTable
     */
    private function requestReport(DataTable $table, $method, $paramsToModify)
    {
        /** @var Period $period */
        $period = $table->getMetadata('period');

        $params = array_merge(
            [
                'filter_limit' => -1,
                'filter_offset' => 0,
                'filter_sort_column' => '',
                'filter_truncate' => -1,
                'compare' => 0,
                'totals' => 1,
                'disable_queued_filters' => 1,
                'format_metrics' => 0,
            ],
            $paramsToModify
        );

        if (!isset($params['idSite'])) {
            $params['idSite'] = $table->getMetadata('site')->getId();
        }
        if (!isset($params['period'])) {
            $params['period'] = $period->getLabel();
        }
        if (!isset($params['date'])) {
            $params['date'] = $period->getDateStart()->toString();
        }

        $idSubtable = Common::getRequestVar('idSubtable', 0, 'int', $this->request);
        if ($idSubtable > 0) {
            $comparisonIdSubtables = Common::getRequestVar('comparisonIdSubtables', $default = false, 'json', $this->request);
            if (empty($comparisonIdSubtables)) {
                throw new \Exception("Comparing segments/periods with subtables only works when the comparison idSubtables are supplied as well.");
            }

            $segmentIndex = empty($paramsToModify['segment']) ? 0 : $this->compareSegmentIndices[$paramsToModify['segment']];
            $periodIndex = empty($paramsToModify['period']) ? 0 : $this->comparePeriodIndices[$paramsToModify['period']][$paramsToModify['date']];

            if (!isset($comparisonIdSubtables[$segmentIndex][$periodIndex])) {
                throw new \Exception("Invalid comparisonIdSubtables parameter: no idSubtable found for segment $segmentIndex and period $periodIndex");
            }

            $comparisonIdSubtable = $comparisonIdSubtables[$segmentIndex][$periodIndex];
            if ($comparisonIdSubtable === -1) { // no subtable in comparison row
                return new DataTable();
            }

            $params['idSubtable'] = $comparisonIdSubtable;
        }

        return Request::processRequest($method, $params);
    }

    private function formatComparisonTables(DataTable $table)
    {
        $formatter = new Formatter();
        foreach ($table->getRows() as $row) {
            /** @var DataTable $comparisonTable */
            $comparisonTable = $row->getComparisons();
            if (empty($comparisonTable)
                || $comparisonTable->getRowsCount() === 0
            ) { // sanity check
                continue;
            }

            $columnMappings = $this->columnMappings;
            $comparisonTable->filter(DataTable\Filter\ReplaceColumnNames::class, [$columnMappings]);

            $formatter->formatMetrics($comparisonTable);

            $subtable = $row->getSubtable();
            if ($subtable) {
                $this->formatComparisonTables($subtable);
            }
        }
    }

    private function compareRow($metadata, DataTable\Row $row, DataTable\Row $compareRow = null)
    {
        $comparisonDataTable = $row->getComparisons();
        if (empty($comparisonDataTable)) {
            $comparisonDataTable = new DataTable();
            $row->setComparisons($comparisonDataTable);
        }

        $this->addPrettifiedMetadata($metadata);

        $columns = [];
        if ($compareRow) {
            foreach ($compareRow as $name => $value) {
                if (!is_numeric($value)
                    || $name == 'label'
                ) {
                    continue;
                }

                $columns[$name] = $value;
            }
        } else {
            foreach ($row as $name => $value) {
                if (!is_numeric($value)
                    || $name == 'label'
                ) {
                    continue;
                }

                $columns[$name] = 0;
            }
        }

        $newRow = new DataTable\Row([
            DataTable\Row::COLUMNS => $columns,
            DataTable\Row::METADATA => $metadata,
        ]);

        // set subtable
        $newRow->setMetadata('idsubdatatable_in_db', -1);
        if ($compareRow) {
            $subtableId = $compareRow->getMetadata('idsubdatatable_in_db') ?: $compareRow->getIdSubDataTable();
            if ($subtableId) {
                $newRow->setMetadata('idsubdatatable_in_db', $subtableId);
            }
        }

        // add segment metadatas
        if ($row->getMetadata('segment')) {
            $newSegment = $row->getMetadata('segment');
            if ($newRow->getMetadata('compareSegment')) {
                $newSegment = Segment::combine($newRow->getMetadata('compareSegment'), SegmentExpression::AND_DELIMITER, $newSegment);
            }
            $newRow->setMetadata('segment', $newSegment);
        } else if ($this->segmentName
            && $row->getMetadata('segmentValue') !== false
        ) {
            $segmentValue = $row->getMetadata('segmentValue');
            $newRow->setMetadata('segment', sprintf('%s==%s', $this->segmentName, urlencode($segmentValue)));
        }

        // calculate changes (including processed metric changes)
        foreach ($newRow->getColumns() as $name => $value) {
            $valueToCompare = $row->getColumn($name) ?: 0;
            $change = DataTable\Filter\CalculateEvolutionFilter::calculate($value, $valueToCompare, $precision = 1, $appendPercent = false);

            if ($change >= 0) {
                $change = '+' . $change;
            }
            $change .= '%';

            $newRow->addColumn($name . '_change', $change);
        }

        $comparisonDataTable->addRow($newRow);

        // recurse on subtable if there
        $subtable = $row->getSubtable();
        if ($subtable
            && $compareRow
        ) {
            $this->compareTables($metadata, $subtable, $compareRow->getSubtable());
        }
    }

    private function compareTables($metadata, DataTable $table, DataTable $compareTable = null)
    {
        // if there are no rows in the table because the metrics are 0, add one so we can still set comparison values
        if ($table->getRowsCount() == 0) {
            $table->addRow(new DataTable\Row());
        }

        foreach ($table->getRows() as $row) {
            $label = $row->getColumn('label');

            $compareRow = null;
            if ($compareTable instanceof Simple) {
                $compareRow = $compareTable->getFirstRow();
            } else if ($compareTable instanceof DataTable) {
                $compareRow = $compareTable->getRowFromLabel($label) ?: null;
            }

            $this->compareRow($metadata, $row, $compareRow);
        }
    }

    private function getColumnMappings()
    {
        $allMappings = Metrics::getMappingFromIdToName(); // TODO: cache this

        $mappings = [];
        foreach ($allMappings as $index => $name) {
            $mappings[$index] = $name;
            $mappings[$index . '_change'] = $name . '_change';
        }
        return $mappings;
    }

    private function checkComparisonLimit($n, $configName)
    {
        if ($n <= 1) {
            throw new \Exception("The [General] $configName INI config option must be greater than 1.");
        }
    }

    private function addPrettifiedMetadata(array &$metadata)
    {
        if (isset($metadata['compareSegment'])) {
            $storedSegment = $this->findSegment($metadata['compareSegment']);
            $metadata['compareSegmentPretty'] = $storedSegment ? $storedSegment['name'] : $metadata['compareSegment'];
        }
        if (!empty($metadata['comparePeriod'])
            && !empty($metadata['compareDate'])
        ) {
            $prettyPeriod = Period\Factory::build($metadata['comparePeriod'], $metadata['compareDate'])->getLocalizedLongString();
            $metadata['comparePeriodPretty'] = ucfirst($prettyPeriod);
        }
    }

    public static function getAvailableSegments() // TODO: should this be cached in transient cache?
    {
        $segments = Request::processRequest('SegmentEditor.getAll', $override = [], $default = []);
        usort($segments, function ($lhs, $rhs) {
            return strcmp($lhs['name'], $rhs['name']);
        });
        return $segments;
    }

    private function findSegment($segment)
    {
        $segment = trim($segment);
        if (empty($segment)) {
            return ['name' => Piwik::translate('SegmentEditor_DefaultAllVisits')];
        }
        foreach ($this->availableSegments as $storedSegment) {
            if ($storedSegment['definition'] == $segment
                || $storedSegment['definition'] == urldecode($segment)
                || $storedSegment['definition'] == urlencode($segment)
            ) {
                return $storedSegment;
            }
        }
        return null;
    }

    private function getMetadataFromModifiedParams($modifiedParams)
    {
        $metadata = [];
        if (isset($modifiedParams['segment'])) {
            $metadata['compareSegment'] = $modifiedParams['segment'];
        }
        if (!empty($modifiedParams['period'])) {
            $metadata['comparePeriod'] = $modifiedParams['period'];
        }
        if (!empty($modifiedParams['date'])) {
            $metadata['compareDate'] = $modifiedParams['date'];
        }
        return $metadata;
    }

    private function replaceIndexesInTotals($totals)
    {
        foreach ($totals as $index => $value) {
            if (isset($this->columnMappings[$index])) {
                $name = $this->columnMappings[$index];
                $totals[$name] = $totals[$index];
                unset($totals[$index]);
            }
        }
        return $totals;
    }

    private function getSegmentNameFromReport(Report $report = null)
    {
        if (empty($report)) {
            return null;
        }

        $dimension = $report->getDimension();
        if (empty($dimension)) {
            return null;
        }

        $segments = $dimension->getSegments();
        if (empty($segments)) {
            return null;
        }

        /** @var \Piwik\Plugin\Segment $segment */
        $segment     = reset($segments);
        $segmentName = $segment->getSegment();
        return $segmentName;
    }
}
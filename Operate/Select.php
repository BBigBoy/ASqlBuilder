<?php
/**
 * Created by PhpStorm.
 * User: yizhihouzi
 * Date: 2017/9/17
 * Time: 上午11:29
 */

namespace DBOperate\Operate;

use DBOperate\ArrayHelper;
use DBOperate\Column;
use DBOperate\Condition;
use DBOperate\Operate;
use DBOperate\Table;

class Select extends Operate
{
    private $fetchColumns = [];
    private $limitStart, $limitEnd;

    private $groupByColumns  = [];
    private $orderByInfo     = [];
    private $whereConditions = [];
    private $innerJoinInfo   = [];
    private $lJoinInfo       = [];
    private $rJoinInfo       = [];
    private $forUpdate       = false;
    private $matchInfo       = null;

    public function fetchCols(Column ...$cols)
    {
        $this->fetchColumns = array_merge($this->fetchColumns, $cols);
        return $this;
    }

    public function clearFetchCols()
    {
        $this->fetchColumns = [];
        return $this;
    }

    public function forUpdate(bool $forUpdate)
    {
        $this->forUpdate = $forUpdate;
    }

    public function where(Condition ...$conditions)
    {
        $this->whereConditions = array_merge($this->whereConditions, $conditions);
        return $this;
    }

    public function matchAgainst(bool $boolMode, string $searchText, Column ...$cols)
    {
        $matchMode       = $boolMode ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
        $index           = implode(',', $cols);
        $this->matchInfo = ['matchMode' => $matchMode, 'searchText' => $searchText, 'index' => $index];
    }

    public function join(Table $table, Condition ...$conditions)
    {
        $this->innerJoinInfo[] = [$table, $conditions];
        return $this;
    }

    public function lJoin(Table $table, Condition ...$conditions)
    {
        $this->lJoinInfo[] = [$table, $conditions];
        return $this;
    }

    public function rJoin(Table $table, Condition ...$conditions)
    {
        $this->rJoinInfo[] = [$table, $conditions];
        return $this;
    }

    public function groupBy(Column ...$cols)
    {
        $this->groupByColumns = array_merge($this->groupByColumns, $cols);
        return $this;
    }

    public function orderBy(Column $col, bool $asc = true)
    {
        $this->orderByInfo[] = "`{$col->colName()}`" . ($asc ? ' ASC' : ' DESC');
    }

    public function limit(int $start, int $end)
    {
        $this->limitStart = $start;
        $this->limitEnd   = $end;
    }

    /**
     * @return string
     */
    public function prepareStr()
    {
        $tablesStr     = $this->table;
        $selectColStr  = $this->createSelectColStr();
        $joinStr       = $this->innerJoinStr();
        $lJoinStr      = $this->lJoinStr();
        $rJoinStr      = $this->rJoinStr();
        $whereStr      = $this->whereConditionStr();
        $groupByColStr = $this->groupByColStr();
        $orderByStr    = $this->orderByStr();
        $preStr        = "SELECT $selectColStr FROM $tablesStr $joinStr $lJoinStr $rJoinStr $whereStr $groupByColStr $orderByStr";
        if (is_int($this->limitStart) && is_int($this->limitEnd)) {
            $preStr = "$preStr limit $this->limitStart,$this->limitEnd";
        }
        if ($this->forUpdate) {
            $preStr = "$preStr FOR UPDATE";
        }
        return $preStr;
    }

    public function createSelectColStr()
    {
        if (!empty($this->fetchColumns)) {
            $colsStrArr = [];
            foreach ($this->fetchColumns as $column) {
                /** @var Column $column */
                $colsStrArr[] = $column->toSelectColStr();
            }
            return implode(',', $colsStrArr);
        } else {
            return '*';
        }
    }

    /**
     * @return string
     */
    private function innerJoinStr()
    {
        return self::createJoinStr($this->innerJoinInfo, '');
    }

    /**
     * @param $joinInfo
     * @param $joinDirection
     *
     * @return string
     */
    private static function createJoinStr($joinInfo, $joinDirection)
    {
        $lJoinStrArr = [];
        foreach ($joinInfo as $lJoinItem) {
            $conditionArr  = $lJoinItem[1];
            $conditionStr  = self::createConditionArrStr($conditionArr);
            $tableName     = (string)$lJoinItem[0];
            $lJoinStrArr[] = "$joinDirection JOIN $tableName ON $conditionStr";
        }
        return implode(' ', $lJoinStrArr);
    }

    /**
     * @param array $conditionArr
     *
     * @return string
     */
    private static function createConditionArrStr(array $conditionArr)
    {
        if (empty($conditionArr)) {
            return '1';
        }
        $conditionGroup = [];
        foreach ($conditionArr as $condition) {
            if ($condition instanceof Condition) {
                $conditionGroup[$condition->getGroupName()][] = (string)$condition;
            }
        }
        foreach ($conditionGroup as $key => $item) {
            $conditionGroup[$key] = '(' . implode(' AND ', $item) . ')';
        }
        return implode(' OR ', $conditionGroup);
    }

    /**
     * @return string
     */
    private function lJoinStr()
    {
        return self::createJoinStr($this->lJoinInfo, 'LEFT');
    }

    /**
     * @return string
     */
    private function rJoinStr()
    {
        return self::createJoinStr($this->rJoinInfo, 'RIGHT');
    }

    /**
     * @return string
     */
    private function whereConditionStr()
    {
        if (!empty($this->whereConditions)) {
            $conditionStr = self::createConditionArrStr($this->whereConditions);
        }
        if (!empty($this->matchInfo)) {
            ['matchMode' => $matchMode, 'index' => $index] = $this->matchInfo;
            $matchStr = "MATCH($index) AGAINST (? $matchMode)";
        }
        if (isset($conditionStr)) {
            $whereStr = 'WHERE ' . $conditionStr;
        }
        if (isset($matchStr)) {
            $whereStr = isset($whereStr) ? "$whereStr AND $matchStr" : "WHERE $matchStr";
        }
        return $whereStr ?? '';
    }

    public function groupByColStr()
    {
        if (empty($this->groupByColumns)) {
            return '';
        }
        $colStrArr = [];
        foreach ($this->groupByColumns as $column) {
            $colStrArr[] = (string)$column;
        }
        $colStr = implode(',', $colStrArr);
        return "GROUP BY ($colStr) ";
    }

    public function orderByStr()
    {
        if (empty($this->orderByInfo)) {
            return '';
        } else {
            return 'ORDER BY ' . implode(',', $this->orderByInfo);
        }
    }

    /**
     * @return array
     */
    public function prepareValues()
    {
        $innerConditionValues = $this->innerJoinConditionValueArr();
        $lConditionValues     = $this->lJoinConditionValueArr();
        $rConditionValues     = $this->rJoinConditionValueArr();
        $whereConditionValues = $this->whereConditionValueArr();
        return array_merge($innerConditionValues, $lConditionValues, $rConditionValues, $whereConditionValues);
    }

    /**
     * @return array
     */
    private function innerJoinConditionValueArr()
    {
        $conditionArr = array_column($this->innerJoinInfo, 1);
        return self::createConditionValueArr(...$conditionArr);
    }

    /**
     * @param Condition[] ...$conditionArr
     *
     * @return array
     */
    private static function createConditionValueArr(Condition ...$conditionArr)
    {
        $values = [];
        foreach ($conditionArr as $condition) {
            if (($v = $condition->getValue()) !== false) {
                $values[] = $v;
            }
        }
        return ArrayHelper::flatten($values);
    }

    /**
     * @return array
     */
    private function lJoinConditionValueArr()
    {
        $conditionArr = array_column($this->lJoinInfo, 1);
        return self::createConditionValueArr(...$conditionArr);
    }

    /**
     * @return array
     */
    private function rJoinConditionValueArr()
    {
        $conditionArr = array_column($this->rJoinInfo, 1);
        return self::createConditionValueArr(...$conditionArr);
    }

    /**
     * @return array
     */
    private function whereConditionValueArr()
    {
        $whereConditionValueArr = self::createConditionValueArr(...$this->whereConditions);
        if (!empty($this->matchInfo)) {
            $whereConditionValueArr[] = $this->matchInfo['searchText'];
        }
        return $whereConditionValueArr;
    }
}
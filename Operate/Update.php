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
use DBOperate\Element;
use DBOperate\Exception\DBOperateException;
use DBOperate\Operate;
use DBOperate\Table;

class Update extends Operate
{
    private $columnUpdateInfo = [];
    private $withTableArr     = [];
    private $limitStart, $limitEnd;

    private   $whereConditions = [];
    protected $table;

    /**
     * DBOperateInterface constructor.
     *
     * @param Table $table
     */
    public function __construct(Table $table)
    {
        $this->table = $table;
    }

    public function setColumn(Column $col, $value)
    {
        $this->columnUpdateInfo[] = [$col, $value];
        return $this;
    }

    public function with(Table ...$tableArr)
    {
        $this->withTableArr = array_merge($this->withTableArr, $tableArr);
    }


    public function where(Condition ...$conditions)
    {
        $this->whereConditions = array_merge($this->whereConditions, $conditions);
        return $this;
    }

    public function limit(int $start, int $end)
    {
        $this->limitStart = $start;
        $this->limitEnd   = $end;
    }

    /**
     * @return string
     * @throws DBOperateException
     */
    public function prepareStr()
    {
        $tablesStr    = self::createTablesStr();
        $updateColStr = $this->createUpdateColStr();
        $whereStr     = $this->createWhereConditionStr();
        $preStr       = "UPDATE $tablesStr $updateColStr $whereStr";
        if (is_int($this->limitStart) && is_int($this->limitEnd)) {
            $preStr = "$preStr limit $this->limitStart,$this->limitEnd";
        }
        return preg_replace('/\s+/', ' ', $preStr);
    }

    public function createTablesStr()
    {
        if (!empty($this->withTableArr)) {
            $withTableStr = implode(',', $this->withTableArr);
            return "$this->table,$withTableStr";
        }
        return $this->table;
    }

    /**
     * @return string
     * @throws DBOperateException
     */
    private function createUpdateColStr()
    {
        if (empty($this->columnUpdateInfo)) {
            DBOperateException::nonUpdateColumn();
        }
        $colUpdateStrArr = [];
        foreach ($this->columnUpdateInfo as $singleColumnUpdateInfo) {
            list($col, $value) = $singleColumnUpdateInfo;
            $colName       = (string)$col;
            $isScalarValue = is_scalar($value);
            if ($isScalarValue) {
                $colUpdateStrArr[] = "$colName=?";
            } else {
                if ($value instanceof Element) {
                    $valueStr          = $value->prepareStr();
                    $colUpdateStrArr[] = "$colName=($valueStr)";
                } else if (is_null($value)) {
                    $colUpdateStrArr[] = "$colName=null";
                } else {
                    $colUpdateStrArr[] = "$colName=$value";
                }
            }
        }
        return 'SET ' . implode(',', $colUpdateStrArr);
    }

    /**
     * @return string
     */
    private function createWhereConditionStr()
    {
        if (!empty($this->whereConditions)) {
            return 'WHERE ' . self::createConditionArrStr($this->whereConditions);
        }
        return '';
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
     * @return array
     */
    public function prepareValues()
    {
        $updatePrepareValues  = $this->createUpdateColValues();
        $whereConditionValues = $this->createWhereJoinConditionValueArr();
        return array_merge($updatePrepareValues, $whereConditionValues);
    }

    private function createUpdateColValues()
    {
        $colUpdateValueArr = [];
        foreach ($this->columnUpdateInfo as $singleColumnUpdateInfo) {
            list(, $value) = $singleColumnUpdateInfo;
            $isScalarValue = is_scalar($value);
            if (!$isScalarValue) {
                if ($value instanceof Element) {
                    $colUpdateValueArr[] = $value->prepareValues();
                }
            } else {
                $colUpdateValueArr[] = $value;
            }
        }
        return ArrayHelper::flatten($colUpdateValueArr);
    }

    /**
     * @return array
     */
    private function createWhereJoinConditionValueArr()
    {
        return self::createConditionValueArr($this->whereConditions);
    }

    /**
     * @param array ...$conditionArr
     *
     * @return array
     */
    private static function createConditionValueArr(...$conditionArr)
    {
        $values       = [];
        $conditionArr = ArrayHelper::flatten($conditionArr);
        foreach ($conditionArr as $condition) {
            if ($condition instanceof Condition) {
                if (($v = $condition->getValue()) !== false) {
                    $values[] = $v;
                }
            }
        }
        return ArrayHelper::flatten($values);
    }
}
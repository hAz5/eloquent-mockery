<?php

namespace Imanghafoori\EloquentMockery;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FakeQueryBuilder extends Builder
{
    public $recordedWheres = [];

    public $recordedWhereIn = [];

    public $recordedWhereNotIn = [];

    public $recordedWhereNull = [];

    public $recordedWhereNotNull = [];

    public $modelObj = null;

    public $recordedWhereLikes = [];

    public $orderBy = [];

    public $recordedWhereBetween = [];

    public $recordedWhereNotBetween = [];

    public function __construct($model)
    {
        $this->modelObj = $model;
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($not) {
            $this->recordedWhereNotIn[] = [$column, $values];
        } else {
            $this->recordedWhereIn[] = [$column, $values];
        }

        return $this;
    }

    public function whereNotIn($column, $values, $boolean = 'and', $not = false)
    {
        $this->recordedWhereNotIn[] = [$column, $values];

        return $this;
    }

    public function orderBy($column, $direction = 'asc')
    {
        $this->orderBy = [$column, $direction];

        return $this;
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        return $this;
    }

    public function leftJoin($table, $first, $operator = null, $second = null)
    {
        return $this;
    }

    public function rightJoin($table, $first, $operator = null, $second = null)
    {
        return $this;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($operator === 'like') {
            $this->recordedWhereLikes[] = [$column, $value];
        } else {
            $this->recordedWheres[] = [$column, $operator, $value];
        }

        return $this;
    }

    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        $this->recordedWhereNull[] = [$columns];

        return $this;
    }

    public function whereNotNull($columns, $boolean = 'and')
    {
        $this->recordedWhereNotNull[] = [$columns];

        return $this;
    }

    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        $this->recordedWhereBetween[] = [$column, $values];

        return $this;

    }

    public function whereNotBetween($column, iterable $values, $boolean = 'and')
    {
        $this->recordedWhereNotBetween[] = [$column, $values];

        return $this;

    }

    public function delete($id = null)
    {
        return $this->filterRows()->count();
    }

    public function update(array $values)
    {
        $collection = $this->filterRows()->map(function ($item) use ($values) {
            return $values + $item;
        });

        $collection->each(function ($val, $key) {
            $this->modelObj::$fakeRows[$key] = $val;
        });

        return $collection->count();
    }

    public function updateRow($originalModel, array $attributes)
    {
        $row = $this->filterRows();

        foreach ($row as $i) {
            $originalModel::$fakeRows[$i] = $originalModel::$fakeRows[$i] + $attributes;
        }
    }

    public function filterRows()
    {
        $collection = collect($this->modelObj::$fakeRows);

        if ($this->orderBy) {
            $sortBy = ($this->orderBy[1] === 'desc' ? 'sortByDesc' : 'sortBy');
            $column = $this->orderBy[0];
            $createdAt = $this->modelObj->getCreatedAtColumn();
            $updatedAt = $this->modelObj->getUpdatedAtColumn();

            if (in_array($column, [$createdAt, $updatedAt, 'deleted_at'])) {
                $collection = $collection->sort(function ($t, $item) use ($column) {
                    $direction = ($this->orderBy[1] === 'desc' ? 1 : -1);

                    return (strtotime($item[$column]) <=> strtotime($t[$column])) * $direction;
                });
            } else {
                $collection = $collection->$sortBy($column);
            }
        }

        if ($this->modelObj::$ignoreWheres) {
            return $collection;
        }

        $conditions = array_merge(
            Arr::prependKeysWith($this->recordedWhereIn,'recordedWhereIn'),
            Arr::prependKeysWith($this->recordedWhereNotIn,'recordedWhereNotIn'),
            Arr::prependKeysWith($this->recordedWhereNull,'recordedWhereNull'),
            Arr::prependKeysWith($this->recordedWhereNotNull,'recordedWhereNotNull'),
            Arr::prependKeysWith($this->recordedWhereLikes,'recordedWhereLikes'),
            Arr::prependKeysWith($this->recordedWhereBetween,'recordedWhereBetween'),
            Arr::prependKeysWith($this->recordedWhereNotBetween,'recordedWhereNotBetween'),
            Arr::prependKeysWith($this->recordedWheres,'recordedWheres'),
        );

        foreach ($conditions as $conditionTypeKey => $_where) {
            if (empty($_where)) {
                continue;
            }

            switch ($conditionTypeKey) {
                case Str::startsWith($conditionTypeKey, 'recordedWhereBetween'):
                    $collection = Filters::whereBetween($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWhereNotBetween'):
                    $collection = Filters::whereNotBetween($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWheres'):
                    $collection = Filters::wheres($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWhereLikes'):
                    $collection = Filters::whereLikes($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWhereIn'):
                    $collection = Filters::whereIn($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWhereNotIn'):
                    $collection = Filters::whereNotIn($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWhereNull'):
                    $collection = Filters::whereNull($collection, $_where);
                    break;
                case Str::startsWith($conditionTypeKey, 'recordedWhereNotNull'):
                    $collection = Filters::whereNotNull($collection, $_where);
                    break;
            }
        }

        return $collection->map(function ($item) {
            return $this->_renameKeys(Arr::dot($item), $this->modelObj::$columnAliases);
        });
    }

    private function _renameKeys(array $array, array $replace)
    {
        $newArray = [];
        if (! $replace) {
            return $array;
        }

        foreach ($array as $key => $value) {
            $key = array_key_exists($key, $replace) ? $replace[$key] : $key;
            $key = explode('.', $key);
            $key = array_pop($key);
            $newArray[$key] = $value;
        }

        return $newArray;
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $key = array_key_last($this->modelObj::$fakeRows);

        $id = $this->modelObj::$fakeRows[$key]['id'] ?? 0;

        return $id + 1;
    }
}

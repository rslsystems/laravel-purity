<?php

namespace Abbasudo\Purity\Filters;

use Abbasudo\Purity\Exceptions\FieldNotSupported;
use Abbasudo\Purity\Exceptions\NoOperatorMatch;
use Abbasudo\Purity\Exceptions\OperatorNotSupported;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Resolve
{
    /**
     * List of relations and the column.
     */
    private array $fields = [];

    /**
     * Column to apply at the deepest relation level.
     */
    private ?string $currentColumn = null;

    /**
     * List of available filters.
     */
    private FilterList $filterList;

    private Model $model;

    private array $previousModels = [];

    public function __construct(FilterList $filterList, Model $model)
    {
        $this->filterList = $filterList;
        $this->model = $model;
    }

    /**
     * @throws Exception
     * @throws Exception
     */
    public function apply(Builder $query, string $field, array|string $values): void
    {
        if (! $this->safe(fn () => $this->validate([$field => $values]))) {
            return;
        }

        $this->filter($query, $field, $values);
    }

    /**
     * run functions with or without exception.
     *
     *
     * @throws Exception
     * @throws Exception
     */
    private function safe(Closure $closure): bool
    {
        try {
            $closure();

            return true;
        } catch (Exception $exception) {
            if (config('purity.silent')) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @return void
     */
    private function validate(array|string $values = [])
    {
        if (empty($values) || is_string($values)) {
            throw NoOperatorMatch::create($this->filterList->keys());
        }

        if (! in_array(key($values), $this->filterList->keys())) {
            $this->validate(array_values($values)[0]);
        }
    }

    /**
     * Apply a single filter to the query builder instance.
     *
     *
     * @throws Exception
     * @throws Exception
     */
    private function filter(Builder $query, string $field, array|string|null $filters): void
    {
        $filters = is_array($filters) ? $filters : [$filters];

        if ($this->filterList->get($field) !== null) {
            $this->safe(fn () => $this->applyFilterStrategy($query, $field, $filters));

            return;
        }

        $firstKey = array_key_first($filters);
        if ($firstKey !== null && $this->filterList->get($firstKey) !== null) {
            $path = $this->fields;

            foreach ($filters as $operator => $opFilters) {
                if (! $this->safe(fn () => $this->validateOperator($field, $operator))) {
                    continue;
                }

                $real = $this->model->getField($field);
                $this->currentColumn = null;

                if ($this->model?->userDefinedFilterFields && isset($this->model?->userDefinedFilterFields[$real])) {
                    $this->fields[] = $real;
                    $this->currentColumn = $real;
                } elseif (str_contains($real, '.')) {
                    $parts = explode('.', $real);
                    $column = array_pop($parts);
                    $this->currentColumn = $column;
                    $this->fields = array_merge($path, $parts);
                } else {
                    $this->fields = array_merge($path, []);
                    $this->currentColumn = $real;
                }

                $this->safe(fn () => $this->applyFilterStrategy(
                    $query,
                    $operator,
                    is_array($opFilters) ? $opFilters : [$opFilters]
                ));

                // reset for next operator
                $this->currentColumn = null;
            }

            $this->fields = $path;

            return;
        }

        $this->safe(fn () => $this->applyRelationFilter($query, $field, $filters));
    }

    private function applyFilterStrategy(Builder $query, string $operator, array $filters): void
    {
        $filter = $this->filterList->get($operator);

        $field = $this->currentColumn ?? end($this->fields);

        $callback = (new $filter($query, $field, $filters))->apply();

        $this->filterRelations($query, $callback);
    }

    private function filterRelations(Builder $query, Closure $callback): void
    {
        // Only pop when the last element is actually the column (legacy path).
        if (! is_null($this->currentColumn) && $this->checkModelRelationshipsForColumn($this->model, $this->fields)) {
            // column is not inside $fields, skip popping
        } else {
            array_pop($this->fields);
        }

        $this->applyRelations($query, $callback);
    }

    private function checkModelRelationshipsForColumn(Model $model, array $fields): bool
    {
        $field = $fields[array_key_last($fields)] ?? null;

        if ($field === null) {
            return false;
        }

        $columns = $model->userDefinedFilterFields ?? $model->columns;

        return in_array($field, $columns) || in_array($this->currentColumn, $columns);
    }

    /**
     * Resolve nested relations if any.
     */
    private function applyRelations(Builder $query, Closure $callback): void
    {
        if (empty($this->fields)) {
            $callback($query);
        } else {
            $this->relation($query, $callback);
        }
    }

    /**
     * @return void
     */
    private function relation(Builder $query, Closure $callback)
    {
        $field = array_shift($this->fields);
        $query->whereHas($field, fn ($subQuery) => $this->applyRelations($subQuery, $callback));
    }

    /**
     * @throws Exception
     */
    private function applyRelationFilter(Builder $query, string $field, array $filters): void
    {
        $this->validateField($field);

        $this->fields[] = $this->model->getField($field);
        $this->prepareModelForRelation();

        foreach ($filters as $subField => $subFilter) {
            $this->filter($query, $subField, $subFilter);
        }

        $this->restorePreviousModel();
    }

    private function prepareModelForRelation(): void
    {
        $relation = end($this->fields);
        if ($relation !== false) {
            $this->previousModels[] = $this->model;
            $this->model = $this->model->$relation()->getRelated();
        }
    }

    private function restorePreviousModel(): void
    {
        array_pop($this->fields);
        if (! empty($this->previousModels)) {
            $this->model = array_pop($this->previousModels);
        }
    }

    private function validateField(string $field): void
    {
        $availableFields = $this->model->availableFields();

        if (! in_array($field, $availableFields)) {
            throw FieldNotSupported::create($field, $this->model::class, $availableFields);
        }
    }

    private function validateOperator(string $field, string $operator): void
    {
        $availableFilters = $this->model->getAvailableFiltersFor($field);

        if (! $availableFilters || in_array($operator, $availableFilters)) {
            return;
        }

        throw OperatorNotSupported::create($field, $operator, $availableFilters);
    }
}

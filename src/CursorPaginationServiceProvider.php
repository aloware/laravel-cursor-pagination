<?php

namespace Aloware\CursorPagination;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;

class CursorPaginationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerMacro();
    }

    /**
     * Create Macros for the Builders.
     */
    public function registerMacro()
    {
        /**
         * @param int $perPage default=15
         * @param array $columns default=['*']
         * @param string $cursor_name default='cursor'
         * @param string|null $cursor_column default=null
         * @param string|null $cursor_value default=null
         *
         * @return CursorPaginator
         */
        $macro = function ($perPage = 15, $columns = ['*'], $cursor_name = 'cursor', $cursor_column = null, $cursor_value = null) {
            $query_orders = isset($this->query) ? collect($this->query->orders) : collect($this->orders);
            $cursor_identifier_column = $cursor_column ? $cursor_column : QueryBuilderHelper::getCursorIdentifierColumn($this);
            $identifier_sort = null;

            // Build the default identifier by considering column sorting and primaryKeys
            if (!$cursor_column) {

                // Check if has explicit orderBy clause
                if ($query_orders->isNotEmpty()) {
                    // Make the identifier the name of the first sorted column
                    $identifier_sort = $query_orders->filter(function ($value) use ($cursor_column) {
                        return $value['column'] === $cursor_column;
                    })
                    ->first();
                    if (!$identifier_sort) {
                        $identifier_sort = $query_orders->first();
                    }
                    $cursor_column = $identifier_sort['column'];
                } else {
                    // If has no orderBy clause, use the primaryKeyName
                    // (if it's a Model), or the default 'id'
                    $cursor_column = isset($this->model) ? $this->model->getKeyName() : 'id';
                }
            } else {
                $identifier_sort = $query_orders->firstWhere('column', $cursor_column);
            }

            // Clear Default Quey Order By
            if($this instanceof EloquentBuilder) {
                $this->getQuery()->orders = null;
            } else {
                $this->orders = null;
            }

            // If there's a sorting by the identifier, check if it's desc so the cursor is inverted
            $identifier_sort_inverted = $identifier_sort ? $identifier_sort['direction'] === 'desc' : false;

            return new CursorPaginator(
                $this,
                $perPage,
                $identifier_sort_inverted,
                $cursor_identifier_column,
                $columns,
                $cursor_name,
                $cursor_value
            );
        };

        // Register Macros
        QueryBuilder::macro('cursorPaginate', $macro);
        EloquentBuilder::macro('cursorPaginate', $macro);
    }
}

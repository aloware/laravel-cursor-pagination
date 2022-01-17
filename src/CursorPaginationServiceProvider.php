<?php

namespace Aloware\CursorPagination;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
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
         * @param string $cursorName default='cursor'
         * @param null $cursorColumn default=null
         *
         * @return CursorPaginator
         */
        $macro = function ($perPage = 15, $columns = ['*'], $cursorName = 'cursor', $cursorColumn = null) {
            $query_orders = isset($this->query) ? collect($this->query->orders) : collect($this->orders);
            $cursorIdentofierColumn = $cursorColumn ? $cursorColumn : $this->model->getKeyName();
            $cursor_helper = new Cursor();
            $identifier_sort = null;

            // Build the default identifier by considering column sorting and primaryKeys
            if ( ! $cursorColumn ) {

                // Check if has explicit orderBy clause
                if ($query_orders->isNotEmpty()) {
                    // Make the identifier the name of the first sorted column
                    $identifier_sort = $query_orders->filter(function ($value) use($cursorColumn) {
                        return $value['column'] === $cursorColumn;
                    })
                    ->first();
                    if( ! $identifier_sort) {
                        $identifier_sort = $query_orders->first();
                    }
                    $cursorColumn = $identifier_sort['column'];
                } else {
                    // If has no orderBy clause, use the primaryKeyName
                    // (if it's a Model), or the default 'id'
                    $cursorColumn = isset($this->model) ? $this->model->getKeyName() : 'id';
                }
            } else {
                $identifier_sort = $query_orders->firstWhere('column', $cursorColumn);
            }
            $cursor_paginator = new CursorPaginator($identifier_sort['column']);

            // Clear Default Quey Order By
            $this->query->orders = null;

            // If there's a sorting by the identifier, check if it's desc so the cursor is inverted
            $identifier_sort_inverted = $identifier_sort ? $identifier_sort['direction'] === 'desc' : false;
            $cursor = request($cursorName, null);
            if($cursor) {
                $json = json_decode(base64_decode($cursor, true), true);
                $cursor_value = $json[$cursorColumn];
                if($json['_pointsToNextItems']) {
                    $cursor_helper->setNextCursor($cursor_value);
                } else {
                    $cursor_helper->setPrevCursor($cursor_value);
                }
            } else {
                // If cursor param not exists so we are in the first page
                $cursor_paginator->setFirstPage();
            }

            // We put `limit` into a new variable to get one more row
            // to understand if it has more pages or not
            $query = $this;
            $main_limit = $perPage;
            $limit = $main_limit + 1;
            $has_more_pages = false;
            
            // Propper order by direction based on next or prev  
            if($cursor_helper->getNextCursor()) {
                $query->take($limit)
                    ->where($cursorIdentofierColumn, $identifier_sort_inverted ? '<' : '>', $cursor_helper->getNextCursor());
            } elseif($cursor_helper->getPrevCursor()) {
                $cursor_helper->setDirection('prev');
                $sub_query = $query->where($cursorIdentofierColumn, $identifier_sort_inverted ? '>' : '<', $cursor_helper->getPrevCursor())
                    ->take($limit);
                $sub_query->orderBy($cursorIdentofierColumn, $identifier_sort_inverted ? 'asc' : 'desc');
                $query = DB::table( DB::raw("({$sub_query->toSql()}) as pagination") )
                    ->selectRaw('pagination.*')
                    ->mergeBindings($sub_query->getQuery());
            } else {
                $query->take($limit);
            }
            $query
                ->orderBy($cursorIdentofierColumn, $identifier_sort_inverted ? 'desc' : 'asc');
            $data = $query->get();

            // Check if it has more pages
            if( ( $data_count = count($data) ) > $main_limit ) {
                $has_more_pages = true;
                if($cursor_helper->pointsToPrev()) {
                    $data->forget(0);
                } else {
                    $data->forget($data_count - 1);
                }
                $data = $data->values();
            }
            // Get Navigation Links
            return $cursor_paginator->getNavigationLinks($data, $main_limit, $cursor_helper->getDirection(), $has_more_pages);
        };

        // Register Macros
        QueryBuilder::macro('cursorPaginate', $macro);
        EloquentBuilder::macro('cursorPaginate', $macro);
    }
}

<?php

namespace Aloware\CursorPagination;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class QueryBuilderHelper
{
    /**
     * Exports Raw Query with all params
     *
     * @param Builder
     *
     * @return string
     */
    public static function exportSqlQuery($builder)
    {
        $query = str_replace(['?'], ['\'%s\''], $builder->toSql());
        $query = vsprintf($query, $builder->getBindings());

        return $query;
    }

    /**
     * Get Cursor Identifier Column
     *
     * @param Builder
     *
     * @return string
     */
    public static function getCursorIdentifierColumn($builder)
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->getModel()->getTable() . '.' . $builder->getModel()->getKeyName();
        }
        return self::getTableName($builder) . '.id';
    }

    /**
     * Get query builder table name
     *
     * @param Builder $builder
     * @return string
     */
    public static function getTableName($builder)
    {
        $from = str_replace('`', '', $builder->from);

        $table = explode(' ', trim($from))[0];

        return $table;
    }
}

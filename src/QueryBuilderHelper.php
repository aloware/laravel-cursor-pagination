<?php

namespace Aloware\CursorPagination;

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
        return $builder->getModel()->getTable() . '.' . $builder->model->getKeyName();
    }
}

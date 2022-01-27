<?php

namespace Aloware\CursorPagination;

use ArrayAccess;
use Countable;
use DateTime;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use IteratorAggregate;
use JsonSerializable;

class CursorPaginator extends AbstractPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, JsonSerializable, Jsonable, PaginatorContract
{
    /**
     * Determine if there are more items in the data source.
     *
     * @return bool
     */
    protected $hasMore;

    /**
     * @var string
     */
    protected $identifier = 'id';

    /**
     * @var string
     */
    protected $direction = 'next';

    /**
     * Should cast to date the identifier.
     *
     * @var bool
     */
    protected $date_identifier = false;

    /**
     * @var Request
     */
    protected $request = null;

    /**
     * @var bool
     */
    protected $is_first_page = false;

    /**
     * @var string
     */
    protected $next_cursor = null;

    /**
     * @var string
     */
    protected $prev_cursor = null;

    /**
     * @var Cursor
     */
    protected $cursor = null;

    /**
     * @var string
     */
    protected $cursor_name = 'cursor';

    /**
     * @var string
     */
    protected $cursor_pointer_name = '_pointsToNextItems';

    /**
     * @var bool
     */
    protected $identifier_sort_inverted = false;

    /**
     * @var string
     */
    protected $cursor_identifier_column = null;

    /**
     * @var array|null
     */
    protected $cursor_queue_names = null;

    /**
     * @var array
     */
    protected $columns = ['*'];

    /**
     * Create a new paginator instance.
     *
     * @param Model $model
     * @param int   $perPage
     * @param bool $identifier_sort_inverted
     * @param string $cursor_identifier_column
     */
    public function __construct(
        $model,
        $perPage,
        $identifier_sort_inverted,
        $cursor_identifier_column,
        $columns,
        $cursor_name
    ) {
        $this->perPage = $perPage;

        $this->columns = $columns;

        $this->cursor_name = $cursor_name;

        $this->identifier_sort_inverted = $identifier_sort_inverted;

        $this->cursor_identifier_column = $cursor_identifier_column;

        if (is_null($this->request)) {
            $this->request = request();
        }

        $this->cursor = $this->resolveCurrentCursor();

        $data = $this->getQueryData($model);

        $this->query = $this->getRawQuery();

        $this->path = $this->path !== '/' ? rtrim($this->path, '/') : rtrim($this->request->path(), '/');

        $this->setItems($data);
    }

    /**
     * We put `limit` into a new variable to get one more row
     * to understand if it has more pages or not
     *
     * @param Builder $builder
     *
     * @return Collection|array
     */
    public function getQueryData($builder)
    {
        $full_identifier_name = $this->getFullIdentifierName($builder);
        $query = $builder;
        $limit = $this->perPage + 1;
        $this->has_more_pages = false;
        if ($this->cursor->getNextCursor()) {
            // If Cursor Points To Next
            $query->take($limit)
                ->where($full_identifier_name, $this->identifier_sort_inverted ? '<' : '>', $this->cursor->getNextCursor());
        } elseif ($this->cursor->getPrevCursor()) {
            // If Cursor Points To Prev
            $this->cursor->setDirection('prev');
            $sub_query = $query->where($full_identifier_name, $this->identifier_sort_inverted ? '>' : '<', $this->cursor->getPrevCursor())
                ->take($limit);
            $full_sub_query = QueryBuilderHelper::exportSqlQuery($sub_query);
            $sub_query->orderBy($this->cursor_identifier_column, $this->identifier_sort_inverted ? 'asc' : 'desc');
            $query = DB::table(DB::raw("({$full_sub_query}) as pagination"))
                ->selectRaw('pagination.*');
        } else {
            // If Cursor Param not exist
            $query->take($limit);
        }
        $query
            ->orderBy($this->cursor_identifier_column, $this->identifier_sort_inverted ? 'desc' : 'asc');
        if ($this->cursor->getPrevCursor()) {
            // Converts Collection to Eloquent Collection
            $data = $builder->hydrate($query->get($this->columns)->toArray());
            $data = $this->applyModelEagerLoads($data, $builder);
        } else {
            $data = $query->get($this->columns);
        }

        // Check if it has more pages
        if (($data_count = count($data)) > $this->perPage) {
            $this->has_more_pages = true;
            if ($this->cursor->pointsToPrev()) {
                $data->forget(0);
            } else {
                $data->forget($data_count - 1);
            }
            $data = $data->values();
        }

        return $data;
    }

    /**
     * @param Request|null $request
     */
    public function resolveCurrentCursor()
    {
        $cursor = new Cursor();
        $cursor_name = request($this->cursor_name, null);

        if ($cursor_name) {
            $json = json_decode(base64_decode($cursor_name, true), true);
            $cursor_value = $json[$this->identifier];
            if ($json[$this->cursor_pointer_name]) {
                $cursor->setNextCursor($cursor_value);
            } else {
                $cursor->setPrevCursor($cursor_value);
            }
        } else {
            // If cursor param not exists so we are in the first page
            $this->setFirstPage();
        }

        return $cursor;
    }

    /**
     * Returns full identifier name. `table_Name`.`identifier_column`
     * For example instead of `id` it will return `users`.`id`
     *
     * @param Builder $builder
     *
     * @return string
     */
    public function getFullIdentifierName($builder)
    {
        $table_name = $builder->getModel()->getTable();

        return $table_name . '.' .$this->cursor_identifier_column;
    }

    /**
     * Applies model eagerLoads to the new collection
     * @param Collection $data
     * @param Model $model
     *
     * @return Collection
     */
    public function applyModelEagerLoads($data, $model)
    {
        foreach ($model->getEagerLoads() as $key=>$eagerLoad) {
            $data->load($key);
        }

        return $data;
    }

    /**
     * Set the items for the paginator.
     *
     * @param mixed $items
     *
     * @return void
     */
    protected function setItems($items)
    {
        $this->items = $items instanceof Collection ? $items : Collection::make($items);

        $this->hasMore = $this->items->count() > $this->perPage;

        $this->items = $this->items->slice(0, $this->perPage);
    }

    public function nextCursor()
    {
        $this->prepareNextCursor();

        return $this->next_cursor
            ? $this->encodePageUrl($this->next_cursor)
            : null;
    }

    /**
     * The URL for the next page, or null.
     *
     * @return string|null
     */
    public function nextPageUrl()
    {
        $this->prepareNextCursor();

        return $this->next_cursor
            ? $this->getCursorLink($this->next_cursor)
            : null;
    }

    /**
     * @return string|null
     */
    public function prevCursor()
    {
        $this->preparePrevCursor();

        return $this->prev_cursor
            ? $this->encodePageUrl($this->prev_cursor, false)
            : null;
    }

    /**
     * @return null|string
     */
    public function prevPageUrl()
    {
        $this->preparePrevCursor();

        return $this->prev_cursor
            ? $this->getCursorLink($this->prev_cursor, false)
            : null;
    }

    /**
     * @return null
     */
    public function preparePrevCursor()
    {
        if (!$this->items->count()) {
            $this->prev_cursor = null;

            return;
        }
        $this->prev_cursor = $this->items->first()->{$this->getIdentifier()};
        if ($this->prev_cursor instanceof DateTime) {
            $this->prev_cursor = $this->prev_cursor->format('Y-m-d H:i:s');
        }
        if ($this->cursor->pointsToPrev()) {
            if (!$this->has_more_pages) {
                $this->prev_cursor = null;
            }
        }
    }

    /**
     * @return null
     */
    public function prepareNextCursor()
    {
        if (!$this->items->count()) {
            $this->next_cursor = null;

            return;
        }
        $this->next_cursor = $this->items->last()->{$this->getIdentifier()};
        if ($this->next_cursor instanceof DateTime) {
            $this->next_cursor = $this->next_cursor->format('Y-m-d H:i:s');
        }
        if ($this->cursor->pointsToNext()) {
            if (!$this->has_more_pages) {
                $this->next_cursor = null;
            }
        }
    }

    /**
     * @return bool
     */
    public function isFirstPage()
    {
        return $this->is_first_page === true;
    }

    /**
     * @return null
     */
    public function setFirstPage()
    {
        $this->is_first_page = true;
    }

    /**
     * Returns the request query without the cursor parameters.
     *
     * @return array
     */
    protected function getRawQuery()
    {
        list($prev, $next) = $this->getCursorQueryNames();

        return collect($this->request->query())
            ->diffKeys([
                $prev => true,
                $next => true,
            ])->all();
    }

    /**
     * @return array
     */
    public function getCursorQueryNames()
    {
        if (!is_null($this->cursor_queue_names)) {
            $this->cursor_queue_names;
        }

        return [
            'prev',
            'next',
        ];
    }

    /**
     * @param array $cursor
     *
     * @return string
     */
    public function url($cursor = [])
    {
        $query = array_merge($this->query, $cursor);

        return $this->path
            .(Str::contains($this->path, '?') ? '&' : '?')
            .http_build_query($query, '', '&')
            .$this->buildFragment();
    }

    /**
     * Determine if there is more items in the data store.
     *
     * @return bool
     */
    public function hasMorePages()
    {
        return $this->hasMore;
    }

    /**
     * Return the first identifier of the results.
     *
     * @return mixed
     */
    public function firstItem()
    {
        return $this->getIdentifier($this->items->first());
    }

    /**
     * Return the last identifier of the results.
     *
     * @return mixed
     */
    public function lastItem()
    {
        return $this->getIdentifier($this->items->last());
    }

    /**
     * Gets identifier.
     *
     * @return string
     */
    protected function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param $id
     *
     * @return int
     */
    protected function parseDateIdentifier($id): int
    {
        return Carbon::parse($id)->timestamp;
    }

    /**
     * Will check if the identifier is type date.
     *
     * @param $id
     *
     * @return bool
     */
    protected function isDateIdentifier($id): bool
    {
        return $this->date_identifier || $id instanceof Carbon;
    }

    /**
     * Render the paginator using a given view.
     *
     * @param string|null $view
     * @param array       $data
     *
     * @return string
     */
    public function render($view = null, $data = [])
    {
        // No render method
        return '';
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        list($prev, $next) = $this->getCursorQueryNames();

        return [
            'data'          => $this->items->toArray(),
            'path'          => $this->getCurrentUrl(),
            $next           => self::castCursor($this->nextCursor()),
            $prev           => self::castCursor($this->prevCursor()),
            'per_page'      => (int) $this->perPage(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->prevPageUrl(),
        ];
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     *
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * @param mixed $val
     *
     * @return null|string
     */
    protected static function castCursor($val = null)
    {
        if (is_null($val)) {
            return $val;
        }

        return (string) $val;
    }

    /**
     * Get Current Path Without Parameters
     *
     * @return string
     */
    protected function getCurrentUrl()
    {
        return url()->current();
    }

    /**
     * Get Next Page Url
     *
     * @return string
     */
    protected function getNextPageUrl($cursor)
    {
        return $this->encodePageUrl($cursor, true);
    }

    /**
     * Get Previous Page Url
     *
     * @return string
     */
    protected function getPrevPageUrl($cursor)
    {
        return $this->encodePageUrl($cursor, false);
    }

    /**
     * Encodes Cursor Url
     *
     * @param int $cursor
     * @param bool $is_pointing_next
     *
     * @return string
     */
    protected function encodePageUrl($cursor, $is_pointing_next = true)
    {
        if (!$is_pointing_next && $this->isFirstPage()) {
            return null;
        }
        $params = [
            $this->getIdentifier() => $cursor,
            $this->cursor_pointer_name => $is_pointing_next,
        ];

        return base64_encode(json_encode($params));
    }

    /**
     * Get Cursor Link
     *
     * @param int $cursor
     * @param bool $is_pointing_next
     *
     * @return string
     */
    protected function getCursorLink($cursor, $is_pointing_next = true)
    {
        if (!$is_pointing_next && $this->isFirstPage()) {
            return null;
        }
        if (!$cursor) {
            return null;
        }
        $cursor = $this->encodePageUrl($cursor, $is_pointing_next);
        $link = $this->getCurrentUrl();

        return $link . '?cursor=' .  $cursor;
    }
}

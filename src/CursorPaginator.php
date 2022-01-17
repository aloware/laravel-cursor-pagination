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
    protected $has_more;

    /**
     * @var string
     */
    protected $identifier = 'id';

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
     * @var Cursor
     */
    protected $cursor = null;

    /**
     * @var array|null
     */
    protected $cursor_queue_names = null;

    /**
     * Create a new paginator instance.
     *
     * @param mixed $items
     * @param int   $perPage
     * @param array $options
     */
    public function __construct($identifier)
    {
        $this->identifier = $identifier ? $identifier : $this->identifier;
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
        return $this->hasMorePages() ? $this->lastItem() : null;
    }

    /**
     * The URL for the next page, or null.
     *
     * @return string|null
     */
    public function nextPageUrl()
    {
        list($prev, $next) = $this->getCursorQueryNames();

        if ($this->nextCursor()) {
            $query = [
                $next => $this->nextCursor(),
            ];

            if ($this->cursor->isPrev()) {
                $query[$prev] = $this->cursor->getPrevCursor();
            }

            return $this->url($query);
        }
    }

    /**
     * @return string|null
     */
    public function prevCursor()
    {
        if ($this->isFirstPage()) {
            return ($this->cursor->isPrev() && $this->isEmpty()) ?
                $this->cursor->getPrevCursor() :
                $this->firstItem();
        }
    }

    /**
     * @return null|string
     */
    public function previousPageUrl()
    {
        list($prev) = $this->getCursorQueryNames();

        if ($pre_cursor = $this->prevCursor()) {
            return $this->url([
                $prev => $pre_cursor,
            ]);
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
            'path'          => $this->url(),
            $prev           => self::castCursor($this->prevCursor()),
            $next           => self::castCursor($this->nextCursor()),
            'per_page'      => (int) $this->perPage(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
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
     * @param boolean $is_pointing_next
     * 
     * @return string
     */
    protected function encodePageUrl($cursor, $is_pointing_next = true)
    {
        $params = [
            $this->getIdentifier() => $cursor,
            '_pointsToNextItems' => $is_pointing_next
        ];

        return base64_encode(json_encode($params));
    }

    /**
     * Get Cursor Link
     * 
     * @param int $cursor
     * @param boolean $is_pointing_next
     * 
     * @return string
     */
    protected function getCursorLink($cursor, $is_pointing_next = true)
    {
        if( ! $is_pointing_next && $this->isFirstPage()) {
            return null;
        }
        if( ! $cursor ) {
            return null;
        }
        $cursor = $this->encodePageUrl($cursor, $is_pointing_next);
        $link = $this->getCurrentUrl();
        return $link . '?cursor=' .  $cursor;
    }

    /**
     * Get Navigation Links
     * 
     * @param collection $data
     * @param int $per_page
     * @param string $direction
     * @param boolean $has_more_pages
     * 
     * @return string
     */
    public function getNavigationLinks($data, $per_page, $direction, $has_more_pages)
    {
        if($data->count()) {
            $prev_cursor = $data->first()->{$this->getIdentifier()};
            $next_cursor = $data->last()->{$this->getIdentifier()};
            if($prev_cursor instanceof DateTime) {
                $prev_cursor = $prev_cursor->format('Y-m-d H:i:s');
            }
            if($next_cursor instanceof DateTime) {
                $next_cursor = $next_cursor->format('Y-m-d H:i:s');
            }
            if($direction == 'prev') {
                if( ! $has_more_pages ) {
                    $prev_cursor = null;
                }
            } else {
                if( ! $has_more_pages ) {
                    $next_cursor = null;
                }
            }
        } else {
            $next_cursor = null;
            $prev_cursor = null;
        }
        return [
            'data' => $data,
            'path' => $this->getCurrentUrl(),
            'per_page' => $per_page,
            'next_cursor' => $this->encodePageUrl($next_cursor),
            'prev_cursor' => $this->encodePageUrl($next_cursor, false),
            'next_page_url' => $this->getCursorLink($next_cursor),
            'prev_page_url' => $this->getCursorLink($prev_cursor, false),
        ];
    }
}

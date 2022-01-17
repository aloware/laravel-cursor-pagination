<?php

namespace Aloware\CursorPagination;

class Cursor
{
    protected $prev = null;
    protected $next = null;
    protected $direction = 'next';

    /**
     * @return bool
     */
    public function isPresent()
    {
        return $this->isNext() || $this->isPrev();
    }

    /**
     * @return bool
     */
    public function isNext()
    {
        return !is_null($this->next);
    }

    /**
     * @return bool
     */
    public function isPrev()
    {
        return !is_null($this->prev);
    }

    /**
     * @return mixed
     */
    public function getPrevCursor()
    {
        return $this->prev;
    }

    /**
     * @param mixed $cursor
     * 
     * @return mixed
     */
    public function setPrevCursor($cursor)
    {
        $this->prev = $cursor;
    }

    /**
     * @return mixed
     */
    public function getNextCursor()
    {
        return $this->next;
    }

    /**
     * @return bool
     */
    public function pointsToNext()
    {
        return $this->direction === 'next';
    }

    /**
     * @return bool
     */
    public function pointsToPrev()
    {
        return $this->direction === 'prev';
    }

    /**
     * @param mixed $cursor
     * 
     * @return mixed
     */
    public function setNextCursor($cursor)
    {
        $this->next = $cursor;
    }

    /**
     * @return string
     */
    public function getDirection()
    {
        return $this->direction;
    }

    /**
     * @param string $direction
     * 
     * @return void
     */
    public function setDirection($direction)
    {
        $this->direction = $direction;
    }

    /**
     * @return mixed
     */
    public function getPrevQuery()
    {
        $prev = $this->getPrevCursor();

        if ($this->date_identifier && is_numeric($prev)) {
            return date('c', $prev);
        }

        return $prev;
    }

    /**
     * @return mixed
     */
    public function getNextQuery()
    {
        $next = $this->getNextCursor();

        if ($this->date_identifier && is_numeric($next)) {
            return date('c', $next);
        }

        return $next;
    }
}

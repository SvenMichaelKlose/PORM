<?php

class ReadonlyArray implements ArrayAccess, Iterator {

    protected $_data = [];

    function __construct ($data = [])
    {
        $this->_data = $data;
    }

    function data (array $noargs = [])
    {
        if ($noargs)
            throw new Exception ('ReadonlyArray cannot be modified.');
        return $this->_data;
    }

    function offsetExists ($offset)  { return isset ($this->_data[$offset]); }
    function offsetGet ($offset)     { return $this->_data[$offset]; }
    function offsetSet ($offset, $x) { throw new ReadonlyWriteAttempt; }
    function offsetUnset ($offset)   { throw new ReadonlyWriteAttempt; }
    function __isset ($offset)       { return $this->offsetExists ($offset); }
    function __get ($offset)         { return $this->offsetGet ($offset); }
    function __set ($offset, $x)     { return $this->offsetSet ($offset, $x); }
    function __unset ($offset)       { return $this->offsetUnset ($offset); }

    function current () { return current ($this->_data); }
    function key ()     { return key ($this->_data); }
    function next ()    { next ($this->_data); }
    function rewind ()  { reset ($this->_data); }

    function valid ()   { return true; }
}

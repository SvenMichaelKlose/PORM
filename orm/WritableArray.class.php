<?php

function is_associative_array ($x)
{
    return !(array_keys ($x) === range (0, count ($x) - 1));
}

class WritableArray extends ReadonlyArray {

    protected $_columns;
    protected $_defaultValues = [];

    protected function _resetData ()
    {
        $this->_data = [];

        foreach ($this->_columns as $name)
            $this->_data[$name] = $this->_defaultValues[$name] ?? NULL;
    }

    function __construct ($columns)
    {
        if (!is_associative_array ($columns)) {
            dump ($columns);
            throw new Exception ('Associative array of columns expected.');
        }

        $this->_columns = array_keys ($columns);
        $this->_defaultValues = $columns;
        $this->_resetData ();
    }

    protected function _set ($offset, $value)
    {
        if (array_search ($offset, $this->_columns) === NULL)
            throw new Exception ("Invalid column name '$offset'. " .
                                 "Valid names are: " . implode (", ", $this->_columns));

        return $this->_data[$offset] = $value;
    }

    function offsetSet ($offset, $value)
    {
        return $this->_set ($offset, $value);
    }

    function data (array $data = [])
    {
        foreach ($data as $key => $value)
            $this[$key] = $value;

        return parent::data ();
    }
}

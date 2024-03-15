<?php

abstract class Storage {

    protected $_db;

    abstract function query (string $type, array $query);
    abstract function insert (Record $record);
    abstract function update (Record $record);
    abstract function delete (Record $record);

    function __construct ($db)
    {
        $this->_db = $db;
    }
}

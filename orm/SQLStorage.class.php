<?php

$DUMP_SQL = FALSE;
$SQL_OPS = ['=', '!=', '<', '>', '<=', '>=', 'between', 'like'];
$TJOURNALS = [];
$RJOURNALS = [];
$SQL_CACHE = [];

function dump_sql ($x = true)
{
    $GLOBALS['DUMP_SQL'] = $x;
}

class SQLStorage extends Storage {

    static $numSelects = 0;
    static $numUpdates = 0;
    static $numInserts = 0;
    static $numDeletes = 0;

    static function table ($x)
    {
        global $SCHEMAS;

        if ($table = ($SCHEMAS[is_string ($x) ? $x : get_class ($x)]['@sql-table']) ?? false)
            return $table;
        return $x;
    }

    private static function _dump ($x, string $headline)
    {
        if ($GLOBALS['DUMP_SQL'])
            dumpJSON ($x, $headline);
        return $x;
    }

    private function _query (string $sql)
    {
        $res = $this->_db->query ($sql);
        if ($error = $this->_db->error)
            throw new Exception ($error);
        return $res;
    }

    private static function _field ($x)
    {
        $dot = strpos ($x, '.');
        if ($dot === false)
            return $x;

        $field = explode ('.', $x);
        $table = self::table ($field[0]);
        return ($table ?? $field[0]) . '.' . $field[1];
    }

    private static function _quote ($x)
    {
        if ($x === NULL)
            return 'NULL';
        if (is_array ($x))
            return self::_field ($x[0]);
        return '"' . addslashes ($x) . '"';
    }

    private static function _expandLogical ($defaultType, $op, $params)
    {
        return [$op => array_map (
            function ($i) use ($defaultType) {
                return self::_expandOp ($defaultType, $i);
            },
            $params
        )];
    }

    private static function _expandTypeName ($type, $field)
    {
        $x = explode ('.', $field);
        if (sizeof ($x) == 2)
            [$type, $field] = $x;
        return [$type, $field];
    }

    private static function _expandField ($defaultType, $n)
    {
        global $SCHEMAS;

        if (strpos ($n, '(') !== false)
            return $n;

        [$type, $field] = self::_expandTypeName ($defaultType, $n);
        if ($table = $SCHEMAS[$type]['@sql-table'] ?? false)
            $type = $table;
        return $type . '.' . $field;
    }

    private static function _expandFields ($defaultType, $x)
    {
        return array_map (
            function ($f) use ($defaultType)
            {
                return self::_expandField ($defaultType, $f);
            },
            $x
        );
    }

    private static function _expandValue ($defaultType, $v)
    {
        global $SCHEMAS;

        if (is_array ($v) && sizeof ($v) == 1) {
            # TODO: Where are those things constructed? There shouldn't be
            # unquoted table names in selections!
            if (is_string ($v[0]) && $table = $SCHEMAS[$v[0]]['@sql-table'] ?? false)
                return $table;
            return [self::_expandField ($defaultType, $v[0])];
        }
        if (is_string ($v) && $table = $SCHEMAS[$v]['@sql-table'] ?? false)
            return $table;
        return $v;
    }

    private static function _expandOp ($defaultType, $x)
    {
        $op = array_key_first ($x);
        $params = $x[$op];

        if ($op == '&' || $op == '|')
            return self::_expandLogical ($defaultType, $op, $params);
        return [
            $op => [
                'n' => self::_expandField ($defaultType, $params['n']),
                'v' => self::_expandValue ($defaultType, $params['v'])
            ]
        ];
    }

    private static function _expandedSelections ($defaultType, $query)
    {
        if ($query['where'] ?? false)
            $query['where'] = self::_expandOp ($defaultType, $query['where']);
        if ($query['joins'] ?? false)
            $query['joins'] = array_map (
                function ($join) use ($defaultType) {
                    $join['on'] = self::_expandOp ($defaultType, $join['on']);
                    return $join;
                },
                $query['joins']
            );
        return $query;
    }
 
    private static function _logicalWhere (string $defaultType, array $set, string $op = 'AND')
    {
        return '(' . 
                implode (
                    " $op ",
                    array_map (
                        function ($where) use ($defaultType) {
                            return self::_where ($defaultType, $where);
                        },
                        $set
                    )
                ) .
               ')';
    }

    private static function _where ($defaultType, $x)
    {
        if (sizeof ($x) > 1) {
            dumpJSON ($x, 'JSON query op should contain only one key: the name of the operator.');
            ensure_backtrace;
        }

        $op = array_key_first ($x);
        $params = $x[$op];
        if ($op == '&')
            return self::_logicalWhere ($defaultType, $params, 'AND');
        if ($op == '|')
            return self::_logicalWhere ($defaultType, $params, 'OR');
        if (in_array (strtolower ($op), $GLOBALS['SQL_OPS']))
            return $params['n'] . " $op " . self::_quote ($params['v']);

        echo ('Invalid JSON query op: '. json_encode ($x));
        ensure_backtrace;
    }

    private static function _joins (string $defaultType, array $joins)
    {
        return implode (
            ' ',
            array_map (
                function ($join) use ($defaultType) {
                    global $SCHEMAS;

                    $table = $SCHEMAS[$join['type']]['@sql-table'];
                    return "JOIN $table ON " . self::_where ($defaultType, $join['on']);
                },
                $joins ?? []
            )
        );
    }

    static function _typedResult (string $defaultType, array $record) : array
    {
        global $INVERSE_REFS, $TABLE_TYPES;

        $typed = [];

        foreach ($record as $field => $value) {
            $type = NULL;
            $x = explode ('.', $field);
            if (sizeof ($x) == 2) 
                [$type, $field] = $x;

            if (!$type)
                $type = $defaultType;
            if ($ref = $INVERSE_REFS[$type][$field] ?? false)
                if ($field == ($ref['typeField'] ?? null))
                    $value = $TABLE_TYPES[$value];

            $typed[$type . '.' . $field] = $value;
        }

        return $typed;
    }

    private function _fetchMysqli (string $type, string $clause)
    {
        self::_dump ($clause, 'SQLStorage query');

        $res = [];
        $dbres = $this->_query ($clause);
        while ($row = $dbres->fetch_assoc ())
            $res[] = $row;

        $res = array_map (
            function ($result) use ($type) {
                return self::_typedResult ($type, $result);
            },
            $res
        );

        self::_dump ($res, 'SQLStorage result');
        return $res;
    }

    private function _uncachedQuery (string $type, array $query)
    {
        $table   = $query['table'];
        $primary = $type::primary ();
        $fields  = $query['fields'];
        if (!is_array ($fields))
            $fields = [$fields];
        $fields = implode (', ', array_map (function ($f) { return self::_field ($f); }, $fields));
        if ($query['count'] ?? false)
            $fields = ($query['distinct'] ?? false) ?
                "COUNT(DISTINCT $table.$primary)" :
                "COUNT(1)";
        else if ($query['distinct'] ?? false)
            $fields = "DISTINCT $fields";

        $where = isset ($query['where']) ?
            self::_where ($type, self::_expandOp ($type, $query['where'])) :
            NULL;
        if ($groupBy = $query['groupBy'] ?? NULL)
            $groupBy = self::_expandField ($type, $groupBy);
        if ($orderBy = $query['orderBy'] ?? NULL)
            $orderBy = implode (', ', self::_expandFields ($type, $orderBy));
        $orderDirection = $query['orderDirection'] ?? 'ASC';

        $offset = $query['offset'] ?? NULL;
        $limit = $query['limit'] ?? NULL;

        $clause = "SELECT $fields " .
                  "FROM $table " .
                  self::_joins ($type, $query['joins'] ?? []) .
                  ($where ? " WHERE $where " : '') .
                  ($groupBy ? " GROUP BY $groupBy" : '') .
                  ($orderBy ? ' ORDER BY ' . $orderBy . ' ' . $orderDirection : '') .
                  ($limit ? " LIMIT $limit" : '') .
                  ($offset ? " OFFSET $offset" : '');
        return [$this->_fetchMysqli ($type, $clause), $fields];
    }

    function query (string $type, array $query)
    {
        self::$numSelects++;

        $query = self::_expandedSelections ($type, $query);
        $query['table'] = $table = $query['table'] ?? $type::table ();
        $queryKey = json_encode ($query);

        if ($GLOBALS['DUMP_SQL'])
            dumpJSON ($query, "Expanded SQL query for $type:");
        $noCache = $query['@no-cache'] ?? false;
        if ($noCache || !$data = $GLOBALS['SQL_CACHE'][$queryKey] ?? null) {
            [$data, $fields] = $this->_uncachedQuery ($type, $query);
            $GLOBALS['SQL_CACHE'][$queryKey] = $data;
        }

        if ($query['count'] ?? false)
            return isset ($data[0][$fields]) ?
                $data[0][$fields] :
                $data[0]["$type.COUNT(1)"];
        return $data;
    }

    private function _columnAssignments (array $data)
    {
        return implode (
            ', ',
            array_map (
                function ($k, $v) {
                    global $SCHEMAS;

                    if ($table = $SCHEMAS[$v]['@sql-table'] ?? false)
                        $v = $table;
                    return self::_field ($k) . ' = ' . self::_quote ($v);
                },
                array_keys ($data),
                $data
            )
        );
    }

    function insert (Record $r)
    {
        global $SCHEMAS;

        $schema = $SCHEMAS[$r::type ()];
        $set = $r->expandedSet ();
        if ($key = $schema['@key'] ?? false)
            if ($set[$key] === null)
                unset ($set[$key]);
        $clause = '
            INSERT INTO ' . self::table ($r) . '
            SET ' . self::_columnAssignments ($set);
        self::_dump ($clause, $r::type ());
        $this->_query ($clause);
        $id = $this->_db->insert_id;
        $r[$r::primary ()] = $id;
        $GLOBALS['SQL_CACHE'] = [];

        return $id;
    }

    function update (Record $r)
    {
        $clause = '
            UPDATE ' . self::table ($r). '
            SET ' . self::_columnAssignments ($r->expandedSet ()) . '
            WHERE ' . $r::primary () . ' = ' . self::_quote ($r['id']);
        self::_dump ($clause, $r::type ());
        $GLOBALS['SQL_CACHE'] = [];
        return $this->_query ($clause);
    }

    function delete (Record $r)
    {
        $clause = '
            DELETE FROM ' . self::table ($r) . '
            WHERE id = ' . self::_quote ($r['id']);
        self::_dump ($clause, $r::type ());
        $GLOBALS['SQL_CACHE'] = [];
        return $this->_query ($clause);
    }

    function createType (string $type)
    {
        global $SCHEMAS, $DUMP_SQL;

        $schema = $SCHEMAS[$type];
        $clause = '';
        foreach ($schema['properties'] as $field => $def) {
            if ($clause)
                $clause .= ', ';
            if ($typeField = $def['@typeField'] ?? false) {
                $clause .= $typeField . ' VARCHAR(255), ' .
                           $def['@keyField'] . ' integer';
                continue;
            }
            if ($keyField = $def['@keyField'] ?? false) {
                $clause .= $keyField . ' integer';
                continue;
            }
            $type = ($def['type'] ?? false) ? $def['type'] : $def;
            if ($type == 'string')
                $type = 'VARCHAR(255)';
            if (isset ($schema['@key']) && $schema['@key'] == $field) {
                if ($type == 'integer')
                    $type .= ' AUTO_INCREMENT';
                $type .= ' PRIMARY KEY';
            }
            $clause .= "$field $type";
        }
        $table = $schema['@sql-table'];
        $clause = "CREATE TABLE $table ($clause)";
        if ($DUMP_SQL)
            echo "$clause\n";
        $this->_query ($clause);
    }

    function dropType (string $type)
    {
        global $SCHEMAS, $DUMP_SQL;

        $schema = $SCHEMAS[$type];
        $table = $schema['@sql-table'];
        $clause = "DROP TABLE IF EXISTS $table";
        if ($DUMP_SQL)
            echo "$clause\n";
        $this->_query ($clause);
     }
}

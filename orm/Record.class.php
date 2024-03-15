<?php

$DUMP_QUERIES = false;

function dump_queries ($x = true)
{
    $GLOBALS['DUMP_QUERIES'] = !!$x;
}

class Record extends WritableArray implements JsonSerializable {

    static private $_storage = NULL;

    function JsonSerialize ()
    {
        return $this->data ();
    }

    ###############
    ## Type info ##
    ###############

    static function type ()
    {
        return get_called_class ();
    }

    static function table ()
    {
        return self::$_storage::table (self::type ());
    }

    static function schema ()
    {
        return  $GLOBALS['SCHEMAS'][self::type ()];
    }

    static function primary ()
    {
        return self::schema ()['@key'];
    }

    static function fullPrimary ()
    {
        return self::type () . '.' . self::primary ();
    }

    static function fields ()
    {
        return  array_keys (self::schema ()['properties']);
    }

    static function defaultSelection ()
    {
        return  self::schema ()['@where'] ?? null;
    }

    static function refInfo ($field = null)
    {
        global $REFS;

        $info = $REFS[self::type ()] ?? [];
        if ($field)
            return $info[$field] ?? null;
        return $info;
    }


    #############
    ## Storage ##
    #############

    static function storage (Storage $new = NULL)
    {
        if ($new !== NULL)
            return self::$_storage = $new;
        return self::$_storage;
    }


    ############################
    ## Field type/name getter ##
    ############################

    private static function _splitField (?string $queryType, string $field)
    {
        $x = explode ('.', $field);
        if (sizeof ($x) > 2)
            throw new Exception ("Illegal field name '$field' – too many dots.");
        if (sizeof ($x) == 2)
            return $x;
        return [$queryType, $field];
    }

    static function _fieldType ($field) : string
    {
        [$type, $name] = self::_splitField (self::type (), $field);
        return $type;
    }

    static function _fieldName ($field) : string
    {
        [$type, $name] = self::_splitField (NULL, $field);
        return $name;
    }


    #################
    ## Field lists ##
    #################

    static function _expandFieldList (array $query, ?string $queryType = NULL) : Array
    {
        if (!$queryType)
            $queryType = self::type ();
        if (!$fields = $query['fields'] ?? NULL)
            $fields = $queryType::fields ();

        $expanded = [];
        foreach ($fields as $field) {
            [$type, $field] = self::_splitField ($queryType, $field);
            if (!$def = $type::schema ()['properties'][$field] ?? false)
                throw new Exception ("No field '$field' in type '$type'.");

            $prefix = (isset ($query['joins']) || $type != $queryType) ?
                "$type." : '';
            if (!$ref = $type::refInfo ($field)) {
                $expanded[] = $prefix . $field;
                continue;
            }
            switch ($ref['refType']) {
                case '1:1':
                    $expanded[] = $prefix . $def['@keyField'];
                    break;

                case 'm1:1':
                    $expanded[] = $prefix . $def['@typeField'];
                    $expanded[] = $prefix . $def['@keyField'];
                    break;
            }
        }

        $query['fields'] = $expanded;
        return $query;
    }


    #####################################
    ## Expand references in selections ##
    #####################################

    private static function _expandLogical ($queryType, $op, $params)
    {
        return [$op => array_map (
            function ($i) use ($queryType) { return self::_expandSelection ($queryType, $i); },
            $params
        )];
    }

    private static function _expandField ($queryType, $n)
    {
        if (strpos ($n, '(') !== false)
            return $n;

        if (isset ($GLOBALS['SCHEMAS'][$n]))
            return $n . '.' . $n::primary ();

        [$type, $field] = self::_splitField ($queryType, $n);
        if (($ref = $type::refInfo ($field)) && isset ($ref['keyField']))
            $field = $ref['keyField'];

        return "$type.$field";
    }

    private static function _expandFields ($queryType, $x)
    {
        return array_map (
            function ($f) use ($queryType) { return self::_expandField ($queryType, $f); },
            ensure_array ($x)
        );
    }

    private static function _expandValue ($queryType, $v)
    {
        if (is_array ($v) && sizeof ($v) == 1)
            return [self::_expandField ($queryType, $v[0])];
        if (is_object ($v))
            return $v[$v::primary ()];
        return $v;
    }

    private static function _expandEq ($queryType, $x)
    {
        $params = reset ($x);
        $n      = $params['n'];
        $v      = $params['v'];

        [$type, $field] = self::_splitField ($queryType, $n);
        if ($ref = $type::refInfo ($field)) {
            switch ($ref['refType']) {
                case '1:1':
                    return
                        qEq ($type . '.' . $ref['keyField'],
                             self::_expandValue ($queryType, $v));

                case 'm1:1':
                    return qAnd (
                        qEq ($type . '.' . $ref['typeField'],
                             is_array ($v) ?
                                 $v :
                                 $v::type ()),
                        qEq ($type . '.' . $ref['keyField'],
                             is_array ($v) ?
                                 [$v[0]::fullPrimary ()] :
                                 self::_expandValue ($queryType, $v))
                    );
            }
        }

        return qEq (
            self::_expandField ($queryType, $n),
            self::_expandValue ($queryType, $v)
        );
    }

    private static function _expandSelection ($queryType, $x)
    {
        $op     = array_key_first ($x);
        $params = reset ($x);

        if ($op == '&' || $op == '|')
            return self::_expandLogical ($queryType, $op, $params);
        if ($op == '=')
            return self::_expandEq ($queryType, $x);
        return [$op => [
            'n' => self::_expandField ($queryType, $params['n']),
            'v' => self::_expandValue ($queryType, $params['v'])
        ]];
    }

    private static function _expandSelections ($queryType, $query)
    {
        if ($query['where'] ?? false)
            $query['where'] = self::_expandSelection ($queryType, $query['where']);
        if ($query['joins'] ?? false)
            $query['joins'] = array_map (
                function ($join) use ($queryType) {
                    $join['on'] = self::_expandSelection ($queryType, $join['on']);
                    return $join;
                },
                $query['joins']
            );
        if ($query['orderBy'] ?? false)
            $query['orderBy'] = self::_expandFields ($queryType, $query['orderBy']);
        if ($query['groupBy'] ?? false)
            $query['groupBy'] = self::_expandField ($queryType, $query['groupBy']);
        return $query;
    }


    ###################################
    ## Contract references in result ##
    ###################################

    static function _shortField (string $queryType, string $field) : string
    {
        [$type, $name] = self::_splitField ($queryType, $field);
        if ($type == $queryType)
            return $name;
        return $field;
    }

    static function _shortRecord (string $queryType, array $record) : array
    {
        $short = [];
        foreach ($record as $field => $value)
            $short[self::_shortField ($queryType, $field)] = $value;
        return $short;
    }

    static function _contractRecord (string $queryType, array $record)
    {
        global $INVERSE_REFS;

        $contracted = [];
        foreach (array_keys ($record) as $field) {
            $type = self::_fieldType ($field) ?? $queryType;
            if (!$ref = $INVERSE_REFS[$type][$field] ?? false) {
                $contracted[$field] = $record[$field];
                continue;
            }

            switch ($refType = $ref['refType']) {
                case '1:1':
                    $ref['fieldValue'] = $record[$ref['keyField']];
                    break;

                case 'm1:1':
                    if (isset ($contract[$field]))
                        break;
                    $ref['typeValue'] = $record[$ref['typeField']];
                    $ref['keyValue']  = $record[$ref['keyField']];
                    break;

                default:
                    throw new Exception (
                        "Unknown reference type '$refType' for $type::$method()'."
                    );
            }
            $contracted[$ref['field']] = $ref;
        }

        return $contracted;
    }

    static function _contractRecords (string $queryType, array $records)
    {
        return array_map (
            function ($x) use ($queryType) {
                return self::_contractRecord ($queryType, self::_shortRecord ($queryType, $x));
            },
            $records
        );
    }


    #######################
    ## Low-level queries ##
    #######################

    static function query (array $query)
    {
        global $DUMP_QUERIES;

        $type = self::type ();
        if (!isset ($query['fields']))
            $query['fields'] = $type::fields ();
        else if (!is_array ($query['fields']))
            $query['fields'] = [$query['fields']];
        if (isset ($query['where']) && $defaultSelection = self::defaultSelection ())
            $query['where'] = qAnd ($query['where'], $defaultSelection);

        if ($DUMP_QUERIES)
            dumpJSON ($query, "Unexpanded query for $type:");
        $query = self::_expandFieldList ($query);
        $query = self::_expandSelections ($type, $query);
        if ($DUMP_QUERIES)
            dumpJSON ($query, "Expanded query for $type:");

        $res   = self::$_storage->query ($type, $query);
        if (!($query['count'] ?? false))
            $res = self::_contractRecords ($type, $res);
        if ($DUMP_QUERIES)
            dumpJSON ($res, "Contracted result of $type:");

        return $res;
    }

    static function queryField (string $field, array $query)
    {
        $query['fields'] = is_array ($field) ? $field : [$field];
        $res = self::query ($query);
        $res = is_array ($field) ?
            $field :
            array_column ($res, $field);
        return $res;
    }

    static function querySingle (array $query)
    {
        $query['limit'] = 1;
        if ($res = self::query ($query))
            return $res[0];
    }

    static function mergedQueries (array $a, array $b) : array
    {
        if (!isset ($a['where'])) {
            if (isset ($b['where']))
                $a['where'] = $b['where'];
        } else
            if (isset ($b['where']))
                $a['where'] = qAnd ($a['where'], $b['where']);
        unset ($b['where']);
        return array_merge_recursive ($a, $b);
    }

    static function count (array $query)
    {
        $query['count'] = true;
        return self::query ($query);
    }


    #######################
    ## Reference queries ##
    #######################

    private function _getRef1TO1 (string $field)
    {
        if (!$ref = $this->_data[$field])
            return $ref;
        $fieldType = $ref['fieldType'];
        $id        = $ref['fieldValue'];
        return $fieldType::byID ($id);
    }

    private function _getRef1TON (string $field, array $query = [])
    {
        $ref       = self::type ()::refInfo ($field);
        $type      = $ref['type'];
        $fieldType = $ref['fieldType'];
        $keyField  = $ref['keyField'];
        $id        = $this->_data[self::primary ()];
        $key       = $type::primary ();
        $query     = self::mergedQueries ($query, qWhere (qEq ($keyField, $id)));
        return $fieldType::by ($query);
    }

    private function _getMultitype1TO1 (string $field, array $query = [])
    {
        if (!$data = $this->_data[$field] ?? null)
            return $data;
        $refInfo   = self::type ()::refInfo ($field);
        $keyField  = $refInfo['keyField'];
        $id        = $data['keyValue'];
        $type      = $data['typeValue'];
        $query     = self::mergedQueries ($query, qWhere (qEq ($type::primary (), $id)));
        return $type::oneBy ($query);
    }

    private function _getMultitype1TON (string $field, array $query = [])
    {
        $ref       = self::type ()::refInfo ($field);
        $type      = $ref['type'];
        $fieldType = $ref['fieldType'];
        $typeField = $ref['typeField'];
        $keyField  = $ref['keyField'];
        $query     = self::mergedQueries (['where' => qAnd (
                qEq ($typeField, $type),
                qEq ($keyField, $this->_data[self::primary ()])
            )],
            $query
        );
        return $fieldType::by ($query);
    }

    private function _1X1Join (array $src, array $dst)
    {
        $srcType = $src['type'];
        $dstType = $dst['type'];
        if ($dst['refType'] == '1:1')
            return qEq ("$dstType." . $dst['keyField'], [$srcType::fullPrimary ()]);
        if ($dst['refType'] == '1:N')
            return qEq ("$srcType." . $dst['keyField'], [$dstType::fullPrimary ()]);
    }

    private function _1X1Joins (array $ref)
    {
        $path      = array_reverse ($ref['path']);
        $fieldType = $ref['fieldType'];
        # TODO: Replace by _1X1Join(). (pixel)
        switch ($path[0]['refType']) {
            case '1:1':
                $joins     = [[
                    'type' => $path[0]['type'],
                    'on'   => qEq ($fieldType . '.' . $fieldType::primary (), [$path[0]['type'] . '.' . $path[0]['keyField']])
                ]];
                break;
            case '1:N':
                $joins     = [[
                    'type' => $path[0]['type'],
                    'on'   => qEq ($fieldType . '.' .$path[0]['keyField'], [$path[0]['type']::fullPrimary ()])
                ]];
                break;
        }

        while (sizeof ($path) > 1) {
            $joins[] = [
                'type' => $path[1]['type'],
                'on'   => $this->_1x1Join ($path[0], $path[1])
            ];
            array_shift ($path);
        }

        return $joins;
    }

    private function _getRef1X1 (string $field, array $query = [])
    {
        $type      = self::type ();
        $ref       = self::type ()::refInfo ($field);
        $fieldType = $ref['fieldType'];
        $query     = self::mergedQueries ([
            'joins' => $this->_1x1Joins ($ref),
            'where' => qEq (self::fullPrimary (), $this->_data[self::primary ()])
        ], $query);

        if ($ref['returnsList'])
            return $fieldType::by ($query);
        return $fieldType::oneBy ($query);
    }


    #############
    ## Get/set ##
    #############

    function data (array $data = [])
    {
        foreach ($data as $key => $value)
            $this[$key] = $value;
        $r = [];
        foreach ($this->_data as $key => $value)
            $r[$key] = $this[$key];
        return $r;
    }

    function offsetExists ($field)
    {
        $type = self::type ();
        return !!$type::refInfo ($field) || parent::offsetExists ($field);
    }

    function offsetGet ($field)
    {
        $type = self::type ();
        if (!$ref = $type::refInfo ($field))
            return parent::offsetGet ($field);

        switch ($refType = $ref['refType']) {
            case '1:1':
                return $this->_getRef1TO1 ($field);
            case '1:N':
                return $this->_getRef1TON ($field);
            case 'm1:1':
                return $this->_getMultitype1TO1 ($field);
            case 'm1:N':
                return $this->_getMultitype1TON ($field);
            case 'far':
                return $this->_getRef1X1 ($field);
            default:
                throw new Exception (
                    "Unknown reference type '$refType' for $type::$field()'."
                );
        }
    }

    function offsetSet ($field, $value)
    {
        $type = self::type ();
        if (!$ref = $type::refInfo ($field))
            return parent::offsetSet ($field, $value);

        switch ($refType = $ref['refType']) {
            case '1:1':
                $this->_data[$field]['fieldValue'] = $value ? $value[$value::primary ()] : null;
                break;
            case 'm1:1':
                $this->_data[$field]['keyValue'] = $value ? $value[$value::primary ()] : null;
                $this->_data[$field]['typeValue'] = $value ? $value::type () : null;
                break;
            default:
                throw new Exception (
                    "Cannot set reference type '$refType' for $type::$field()'."
                );
        }

        return $value;
    }

    function __call (string $method, ?array $args)
    {
        $arg = $args[0] ?? [];
        if (sizeof ($args) > 1)
            throw new Exception ('Too many arguments.');
        $type = self::type ();
        if (!$ref = $type::refInfo ($method))
            throw new Exception ("No method '$method' in class '$type'.");

        switch ($refType = $ref['refType']) {
            case '1:1':
                return $this->_getRef1TO1 ($method);
            case '1:N':
                return $this->_getRef1TON ($method, $arg);
            case 'm1:1':
                return $this->_getMultitype1TO1 ($method, $arg);
            case 'm1:N':
                return $this->_getMultitype1TON ($method, $arg);
            case 'far':
                return $this->_getRef1X1 ($method, $arg);
            default:
                throw new Exception (
                    "Unknown reference type '$refType' for $type::$method()'."
                );
        }
    }


    #############
    ## Look-up ##
    #############

    static function _initRefs (array $record)
    {
        $contracted = [];
        foreach (array_keys ($record) as $field) {
            $value = $record[$field];
            if (!$ref = self::refInfo ($field)) {
                $contracted[$field] = $value;
                continue;
            }

            switch ($refType = $ref['refType']) {
                case '1:1':
                    $ref['fieldValue'] = $value ? $value[$value::primary ()] : null;
                    break;

                case 'm1:1':
                    $ref['keyValue']  = $value ? $value[$value::primary ()] : null;
                    $ref['typeValue'] = $value ? $value::type () : null;
                    break;

                default:
                    throw new Exception (
                        "Unknown reference type '$refType' for $type::$field()'."
                    );
            }
            $contracted[$field] = $ref;
        }
        return $contracted;
    }

    function __construct (string $type, $id = NULL)
    {
        parent::__construct (self::_initRefs (schemaDefaultValues ($type)));
        if ($id === NULL)
            return;
        if (is_array ($id)) {
            $this->_data = $id;
        } else
            $this->_data = self::querySingle (['where' => qEq (self::primary (), $id)]);
    }

    static function byID ($id)
    {
        if (!$id)
            return;
        $class = self::type ();
        $x = new $class ($id);
        if ($x[self::primary ()])
            return $x;
    }

    private static function _constructMany ($data)
    {
        $class = self::type ();
        return array_map (
            function ($x) use ($class) {
                return new $class ($x);
            },
            $data
        );
    }

    static function byIDs (?array $ids) : array
    {
        if (!$ids)
            return [];
        return self::_constructMany (self::by (qWhere (qOrList (self::primary (), $ids))));
    }

    static function by (array $query = []) : array
    {
        return self::_constructMany (self::query ($query));
    }

    static function oneBy (array $query) : ?Record
    {
        $query['limit'] = 1;
        if ($res = self::by ($query))
            return $res[0];
        return NULL;
    }

    static function byField (string $name, $value, ?array $query = []) : ?array
    {
        return self::by (self::mergedQueries (qWhere (qEq ($name, $value)), $query));
    }

    static function oneByField (string $name, $value, ?array $query = []) : ?Record
    {
        $query['limit'] = 1;
        if ($res = self::byField ($name, $value, $query))
            return $res[0];
        return null;
    }


    ##########################
    ## Insert/update/delete ##
    ##########################

    function expandedSet ()
    {
        global $REFS;

        foreach ($this->_data as $field => $value) {
            if ($ref = $REFS[self::type ()][$field] ?? false) {
                if (is_array ($value) && $ref['refType'] == '1:1')
                    $value = $value['fieldValue'] ?? null;
                $type      = is_object ($value) ?
                               get_class ($value) :
                               gettype ($value);
                $fieldType = $ref['fieldType'];
                $keyField  = $ref['keyField'];

                if (!$value)
                    $expanded[$keyField] = null;
                switch ($ref['refType']) {
                    case '1:1':
                        if (is_object ($value))
                            $value = $value[$fieldType::primary ()];
                        $expanded[$keyField] = $value;
                        continue 2;

                    case 'm1:1':
                        $typeField = $ref['typeField'];

                        if (!$value) {
                            $expanded[$typeField] = null;
                            continue 2;
                        }
                        if (is_object ($value)) {
                            $expanded[$keyField]  = $value[$value::primary ()];
                            $expanded[$typeField] = $value::table ();
                            continue 2;
                        }
                        $expanded[$keyField]  = $value['keyValue'];
                        $expanded[$typeField] = $value['typeValue'];
                        continue 2;

                    default:
                        throw new Exception ("Unknown refType '$refType'.");
                }
            } else
                $expanded[$field] = $value;
        }
        return $expanded;
    }

    function insert ()
    {
        $id = self::$_storage->insert ($this);
        $this->_data[self::primary ()] = $id;
        return $this;
    }
  
    function update ()
    {
        return $num = self::$_storage->update ($this);
    }
  
    function updateOrInsert ()
    {
        if (!$this[$this::primary ()])
            $this->insert ();
        else
            $this->update ();
    }

    function delete ()
    {
        return self::$_storage->delete ($this);
    }


    #################
    ## JSON export ##
    #################

    private static $jsonKnown = [];

    static function initJson ()
    {
        self::$jsonKnown = [];
    }

    function json ()
    {
        $id  = $this[self::primary ()];
        $key = self::type () . $id;
        if (self::$jsonKnown[$key] ?? false)
            return $id;
        self::$jsonKnown[$key] = true;

        $fields = array_merge ($this::fields (), $this::schema ()['@json-refs'] ?? []);
        $tmp = [];
        foreach ($fields as $field) {
            if (!isset ($this[$field]))
                continue;

            $value = $this[$field];
            if (is_object ($value)) {
                $tmp[$field] = $value->json ();
                continue;
            }

            if (is_array ($value))
                $value = array_map (function ($x) { return $x->json (); }, $value);
            if ($value || is_numeric ($value))
                $tmp[$field] = $value;
        }

        return $tmp;
    }
}

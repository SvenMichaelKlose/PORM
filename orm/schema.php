<?php

$SCHEMAS        = [];
$REFS           = [];
$INVERSE_REFS   = [];
$TABLE_TYPES    = [];

function linkedType ($type)
{
    if ($GLOBALS['SCHEMAS'][$type] ?? false)
        return "<a href=\"#$type\">$type</a>";
    return $type;
}

function dumpSchema ($type)
{
    global $SCHEMAS, $REFS;

    $schema = $SCHEMAS[$type];

    echo "<div>
        <h2 id=\"$type\">$type</h2>
        <table>
            <tr>
                <th>Field name</th>
                <th>Type</th>
                <th>Path</th>
            </tr>
";
    $props = $schema['properties'];
    ksort ($props);
    foreach ($props as $field => $def) {
        if ($def['@keyField'] ?? false)
            continue;

        $fieldType = $def['type'] ?? $def;
        if (is_array ($fieldType))
            $fieldType = implode (', ', linkedType ($fieldType));
        echo "<tr>
            <td>$field</td>
            <td>" . linkedType ($fieldType) . "</td>
            <td></td>
        </tr>
";
    }

    if ($REFS[$type] ?? false) {
        $refs = $REFS[$type];
        ksort ($refs);
        foreach ($refs as $field => $def) {
            $bs = $be = '';
            if ($def['returnsList'] ?? false) {
                $bs = '[';
                $be = ']';
            }
            $fieldType = $def['fieldType'];
            if (is_array ($fieldType))
                $fieldType = implode (', ', array_map ('linkedType', $fieldType));
            echo "<tr>
                <td>$field</td>
                <td>$bs" . linkedType ($fieldType) . "$be</td>";
                if ($def['refType'] == 'far') {
                    echo "<td>
                        " . implode ('->', array_map ('linkedType',
                                                      array_column (array_slice ($def['path'], 1),
                                                                                'type'))) . "-&gt;" . linkedType ($fieldType);
                } else {
                    echo "<td></td>";
                }
            echo "</tr>
";
        }
    }
    echo "
        </table>
    </div>
";
}

function dumpSchemas ()
{
    global $SCHEMAS;

    array_map ('dumpSchema', array_keys ($SCHEMAS));
}

function addSchema (string $type, array $schema)
{
    global $SCHEMAS, $TABLE_TYPES;

    if (!isset ($schema['type']))
        $schema = array_merge (['type' => 'object'], $schema);
    else if ($schema['type'] != 'object')
        throw new Exception ("Type 'object' expected");
    if (isset ($SCHEMAS[$type]))
        throw new Exception ("Schema '$type' is already defined");

    $SCHEMAS[$type] = $schema;
    if ($table = $schema['@sql-table'] ?? null)
        $TABLE_TYPES[$table] = $type;
}

$EXISTING_SCHEMA_CLASSES = [];

function genJSONSchemaClasses ()
{
    global $EXISTING_SCHEMA_CLASSES;
    foreach ($GLOBALS['SCHEMAS'] as $name => $schema) {
        $class  = $schema['@classname'] ?? $name;
        if (isset ($EXISTING_SCHEMA_CLASSES[$class]))
            continue;

        $traits = isset ($schema['@traits']) ?
            'use ' . $schema['@traits'] . ';' :
            '';

        eval ("
            class $class extends Record
            {
                $traits
                function __construct (\$id = NULL)
                {
                    parent::__construct (\"" . addslashes ($name) . "\", \$id);
                }
            }
        ");

        $EXISTING_SCHEMA_CLASSES[$class] = true;
    }
}

function makem1to1 ($type, $field, $fieldType, $typeField, $keyField)
{
    global $REFS;

    $ref = [
        'refType'    => 'm1:1',
        'type'       => $type,
        'field'      => $field,
        'fieldType'  => $fieldType,
        'typeField'  => $typeField,
        'keyField'   => $keyField
    ];
    $REFS[$type][$field] = $ref;

    return $ref;
}

function collectMultitypeRefs (string $type, string $field)
{
    global $SCHEMAS, $REFS, $INVERSE_REFS;

    $def        = $SCHEMAS[$type]['properties'][$field];
    $typeField  = $def['@typeField'];
    $keyField   = $def['@keyField'];
    $fieldTypes = $def['type'];

    foreach (ensure_array ($fieldTypes) as $fieldType) {
        $ref    = makem1to1 ($type, strtolower ($fieldType), $fieldType, $typeField, $keyField);
        $plural = $SCHEMAS[$type]['@plural'];
        $REFS[$fieldType][$plural] = array_merge ($ref, [
            'refType'     => 'm1:N',
            'type'        => $fieldType,
            'field'       => $plural,
            'fieldType'   => $type,
            'returnsList' => true
        ]);
    }

    $ref = makem1to1 ($type, $field, $fieldTypes, $typeField, $keyField);
    $INVERSE_REFS[$type][$typeField] = $ref;
    $INVERSE_REFS[$type][$keyField] = $ref;
}

function collectUnitypeRefs (string $type, string $field)
{
    global $SCHEMAS, $REFS, $INVERSE_REFS;

    $def        = $SCHEMAS[$type]['properties'][$field];
    $fieldType  = $def['type'];
    $keyField   = $def['@keyField'];

    $ref = [
        'refType'   => '1:1',
        'type'      => $type,
        'field'     => $field,
        'fieldType' => $fieldType,
        'keyField'  => $keyField
    ];
    $REFS[$type][$field] = $ref;
    $INVERSE_REFS[$type][$keyField] = $ref;

    if (!$plural = $SCHEMAS[$type]['@plural'] ?? false) {
        #echo "@plural for type '$type' missing.<br>\n";
        return;
    }
    $REFS[$fieldType][$plural] = [
        'refType'     => '1:N',
        'type'        => $fieldType,
        'field'       => $plural,
        'fieldType'   => $type,
        'keyField'    => $keyField,
        'returnsList' => true
    ];
}

function collectReferences (string $type, array $schema)
{
    foreach ($schema['properties'] as $field => $def)
        if (isset ($def['@typeField']))
            collectMultitypeRefs ($type, $field);
        else if (isset ($def['@keyField']))
            collectUnitypeRefs ($type, $field);
}

function isShorterPath (array $path, string $type, string $field)
{
    global $REFS;

    return $REFS[$type][$field]['refType'] == 'far' &&
           sizeof ($REFS[$type][$field]['path']) > sizeof ($path);
}

function traceRef (array $ref, string $field, string $fromType, string $type = NULL, array $path = [], $needsPlural)
{
    global $SCHEMAS, $REFS;

    $fieldType = $ref['fieldType'];

    # TODO: Move after registering ref once JOINs can handle double type occurence.
    if (in_array ($fieldType, array_column ($path, 'type')))
        return;

    if (sizeof ($path) > 1) {
        if ($needsPlural)
            $field = $SCHEMAS[$fieldType]['@plural'] ?? false;

        if ($field && (!isset ($REFS[$fromType][$field]) || isShorterPath ($path, $fromType, $field)))
            $REFS[$fromType][$field] = [
                'refType'      => 'far',
                'type'         => $fromType,
                'fieldType'    => $fieldType,
                'path'         => $path,
                'returnsList'  => $needsPlural
            ];
    }

    traceRefsInType ($fromType, $ref['fieldType'], $path, $needsPlural);
}

function traceRefsInType (string $fromType, string $type = NULL, array $path = [], $needsPlural = false)
{
    global $REFS;

    if (!$type)
        $type = $fromType;

    foreach ($REFS[$type] ?? [] as $field => $ref) {
        $refType = $ref['refType'];
        if ($refType == '1:1' || (!$needsPlural && $refType == '1:N'))
            traceRef ($ref, $field, $fromType, $type, array_merge ($path, [$ref]), $needsPlural || $refType == '1:N');
    }
}

function traceReferences ()
{
    global $REFS;

    array_map ('traceRefsInType', array_keys ($REFS));
}

function mapSchemas ()
{
    global $SCHEMAS;

    genJSONSchemaClasses ();
    foreach ($SCHEMAS as $type => $schema)
        collectReferences ($type, $schema);
    traceReferences ();
}

function schemaDefaultValues (string $type)
{
    global $SCHEMAS;

    $defaults = [];
    foreach ($SCHEMAS[$type]['properties'] as $name => $prop)
        if (isset ($prop['default']) && !isset ($prop['@keyField']) && !isset ($prop['@typeField']))
            $defaults[$name] = $prop['default'];
        else
            $defaults[$name] = NULL;
    return $defaults;
}

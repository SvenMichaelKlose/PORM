<?php

function is_assoc_array ($arr) : bool
{
    if (!is_array ($arr))
        return false;
    return array_keys ($arr) !== range (0, count ($arr) - 1);
}

class JSONSchema {

    private $_schema = [];

    function __construct ($schema)
    {
        $this->_schema = $schema;
    }


    ################
    ### Location ###
    ################

    private $_path = [];

    private function path ()
    {
        return '#/' . implode ('/', $this->_path);
    }

    private function enter (string $key)
    {
        array_push ($this->_path, $key);
    }

    private function leave ()
    {
        array_pop ($this->_path);
    }

    private function location ()
    {
        return [
            'keywordLocation'   => $this->path (),
            'instanceLocation'  => ''
        ];
    }


    ############
    ## ERRORS ##
    ############

    private function valid ()
    {
        return array_merge (['valid' => true], $this->location ());
    }

    private function invalid (string $error)
    {
        return array_merge (['valid' => false, 'error' => $error], $this->location ());
    }

    private function call (string $method, $data, $schema, $key = null)
    {
        if (!method_exists ($this, $method))
            throw new Exception ("Invalid JSON Schema: Unknown '$key' (no method '$method')");

        if ($key)
            $this->enter ($key);
        $r = $this->$method ($data, $schema) ?? $this->valid ();
        if ($key)
            $this->leave ($key);

        return $r;
    }

    private function hasErrors ($results)
    {
        if (is_assoc_array ($results))
            return !$results['valid'];
        foreach ($results as $result) {
            if (!is_assoc_array ($result))
                throw new Exception ('Expected JSON object as a result instead of : ' . json_encode ($result));
            if (!isset ($result['valid']))
                throw new Exception ('Missing field \'valid\' in: ' . json_encode ($result));
            if (!$result['valid'])
                return true;
        }
        return false;
    }

    private function collapseResults ($results)
    {
        if (!$results || !$this->hasErrors ($results))
            return $this->valid ();
        return array_merge ($this->location (), ['valid' => false, 'errors' => $results]);
    }


    ###########
    ## TYPES ##  For the keyword 'type'.
    ###########

    private function type_boolean ($data, $schema)
    {
        if (!is_bool ($data))
            return $this->invalid ('not boolean');
    }

    # Non-standard! Lets strings pass as long as they are numeric.
    private function type_integer ($data, $schema)
    {
        if (!is_integer ($data))
            return $this->invalid ('not an integer');
    }

    private function type_number ($data, $schema)
    {
        if (!is_numeric ($data))
            return $this->invalid ('not numeric');
    }

    private function type_string ($data, $schema)
    {
        if (!is_string ($data))
            return $this->invalid ('not a string');
    }

    private function type_array ($data, $schema)
    {
        if (!is_array ($data))
            return $this->invalid ('not an array');
    }

    private function type_object ($data, $schema)
    {
        if (!is_assoc_array ($data))
            return $this->invalid ('not an object');
    }


    #############  Types that need more than subschemas or regexsps.
    ## FORMATS ##  Like resolving domain names to test email
    #############  adresses.

    private function format_email ($data, $schema)
    {
        if (!$data)
            return;
        if (!filter_var ($data, FILTER_VALIDATE_EMAIL))
            return $this->invalid ('Invalid email address format.');
        if (!checkdnsrr (substr ($data, strpos ($data, '@') + 1), 'MX'))
            return $this->invalid ('Unknown email address domain.');
    }

    private function format_phone ($data, $schema)
    {
        if (!$data)
            return;
        if (!filter_var ($data, FILTER_SANITIZE_NUMBER_INT))
            return $this->invalid ('Invalid phone number format.');
    }


    ##############
    ## KEYWORDS ##  Core keywords to support types.
    ##############

    private function Const ($data, $schema)
    {
        if ($schema['const'] !== $data)
            return $this->invalid ('Constant does not match.');
    }

    private function TypeAtomic ($data, $schema, $type)
    {
        if ($schema === null) {
            if ($data === NULL)
                return;
            return $this->invalid ('NULL expected.');
        }
        if ($schema === true)
            return;
        if ($schema === false)
            return $this->invalid ('Type \'false\' (always fails).');
        return $this->call ("type_$type", $data, $schema);
    }

    private function Type ($data, $schema)
    {
        $type = is_string ($schema) ? $schema : $schema['type'];
        if ($type === null)
            $type = 'null';

        if (!is_array ($type))
            return $this->TypeAtomic ($data, $schema, $type);

        foreach ($type as $subType) {
            $r = $this->TypeAtomic ($data, $schema, $subType);
            if ($r['valid'])
                return;
        }

        return $this->invalid ('invalid type');
    }

    private function Format ($data, $schema)
    {
        $callback = "format_" . $schema['format'];
        return $this->call ($callback, $data, $schema);
    }

    private function MultipleOf ($data, $schema)
    {
        $multiple = $schema['multipleOf'];
        if ($data % $multiple)
            return $this->invalid ("$data is not a multiple of $multiple.");
    }

    private function Minimum ($data, $schema)
    {
        $limit = $schema['minimum'];
        if ($data < $limit)
            return $this->invalid ("$data is less than $limit.");
    }

    private function Maximum ($data, $schema)
    {
        $limit = $schema['maximum'];
        if ($data > $limit)
            return $this->invalid ("$data is greater than $limit.");
    }

    private function ExclusiveMinimum ($data, $schema)
    {
        $limit = $schema['exclusiveMinimum'];
        if ($data <= $limit)
            return $this->invalid ("$data is less than or equal to $limit.");
    }

    private function ExclusiveMaximum ($data, $schema)
    {
        $limit = $schema['exclusiveMaximum'];
        if ($data >= $limit)
            return $this->invalid ("$data is greater than or equal to $limit.");
    }

    private function MinLength ($data, $schema)
    {
        $limit = $schema['minLength'];
        $len = strlen ($data);
        if ($len < $limit)
            return $this->invalid ("Minimum string length is $limit, got $len.");
    }

    private function MaxLength ($data, $schema)
    {
        $limit = $schema['maxLength'];
        $len = strlen ($data);
        if ($len > $limit)
            return $this->invalid ("Maximum string length is $limit, got $len.");
    }

    private function Items (array $data, $schema)
    {
        $size = sizeof ($data);
        $res = [];
        for ($i = 0; $i < $size; $i++) {
            $this->enter ($i);
            $res[] = $this->validate ($data[$i], $schema['items']);
            $this->leave ();
        }
        return $this->collapseResults ($res);
    }

    private function Properties ($data, $schema)
    {
        $res = [];
        $properties = $schema['properties'] ?? (object) [];
        $additionalProperties = $schema['additionalProperties'] ?? true;

        if ($additionalProperties === false) {
            foreach ($data as $key => $dummy) {
                if (!isset ($properties[$key])) {
                    $this->enter ($key);
                    $res[] = $this->invalid ("Additional property '$key'.");
                    $this->leave ();
                }
            }
        }

        foreach ($properties as $name => $def) {
            if (isset ($data[$name])) {
                $this->enter ($name);
                $res[] = $this->validate ($data[$name], $def);
                $this->leave ();
            }
        }

        return $this->collapseResults ($res);
    }

    private function Required ($data, $schema)
    {
        $res = [];

        $required = $schema['required'];
        if ($required === true)
            $required = array_keys ($schema['properties']);

        foreach ($required as $name) {
            $this->enter ($name);
            $res[] = isset ($data[$name]) ?
                $this->valid () :
                $this->invalid ("Non-optional key '$name' missing.");
            $this->leave ();
        }

        return $this->collapseResults ($res);
    }

    private function PropertyNames ($data, $schema)
    {
        if ($propertyNames = $schema['propertyNames']) {
            $res = [];
            foreach (array_keys ($data) as $key)
                $res[] = $this->validate ($key, $propertyNames);
            return $this->collapseResults ($res);
        }
    }

    private function MinProperties ($data, $schema)
    {
        $limit = $schema['minProperties'];
        $size = sizeof ($data);
        if ($size < $limit)
            return $this->invalid ("Minimum number of properties is $limit, got $size.");
    }

    private function MaxProperties ($data, $schema)
    {
        $limit = $schema['maxProperties'];
        $size = sizeof ($data);
        if ($size > $limit)
            return $this->invalid ("Maximum number of properties is $limit, got $size.");
    }


    #################
    ## APPLICATORS ##  Keywords to combine schemas.
    #################

    private function Not ($data, $schema)
    {
        $res = $this->validate ($data, $schema['not']);
        if (!$this->hasErrors ($res))
            return $this->invalid ('Found excluded type.', $res);
    }

    private function Enum ($data, $schema)
    {
        if (!in_array ($data, $schema['enum']))
            return $this->invalid ('Item not in enumeration.');
    }

    private function Pattern ($data, $schema)
    {
        $pattern = $schema['pattern'];
        if (!preg_match ("/$pattern/", $data))
            return $this->invalid ("Regexp pattern '$pattern' does not match.");
    }

    private function AllOf ($data, $schema)
    {
        $res = [];
        foreach ($schema['allOf'] as $subschema)
            $res[] = $this->validate ($data, $subschema);
        return $this->collapseResults ($res);
    }

    private function AnyOf ($data, $schema)
    {
        foreach ($schema['anyOf'] as $subschema)
            if ($this->isValid ($data, $subschema))
                return;
        return $this->invalid ('No type matches.');
    }

    private function OneOf ($data, $schema)
    {
        $matches = 0;
        foreach ($schema['oneOf'] as $subschema)
            if ($this->isValid ($data, $subschema))
                $matches++;
        if ($matches != 1)
            return $this->invalid ("$matches matches instead of 1.");
    }

    private function If ($data, $schema)
    {
        if ($this->isValid ($data, $schema['if']))
            return $this->validate ($data, $schema['then']);
        elseif (isset ($schema['else']))
            return $this->validate ($data, $schema['else']);
    }


    ###############
    ## TOP LEVEL ##
    ###############

    private $keywords = [
        'const'             => 'Const',
        'type'              => 'Type',
        'format'            => 'Format',

        'minLength'         => 'MinLength',
        'maxLength'         => 'MaxLength',

        'multipleOf'        => 'MultipleOf',
        'minimum'           => 'Minimum',
        'maximum'           => 'Maximum',
        'exclusiveMinimum'  => 'ExclusiveMinimum',
        'exclusiveMaximum'  => 'ExclusiveMaximum',

        'items'             => 'Items',

        'properties'        => 'Properties',
        'propertyNames'     => 'PropertyNames',
        'required'          => 'Required',

        'minProperties'     => 'MinProperties',
        'maxProperties'     => 'MaxProperties',

        'enum'              => 'Enum',
        'pattern'           => 'Pattern',

        'not'               => 'Not',
        'allOf'             => 'AllOf',
        'anyOf'             => 'AnyOf',
        'oneOf'             => 'OneOf',
        'if'                => 'If'
    ];

    function validate ($data, $schema = NULL)
    {
        if ($schema === NULL)
            $schema = $this->_schema;
        if (!is_array ($schema))
            return $this->call ('Type', $data, $schema, json_encode ($schema));

        $schema = (is_string ($schema) || is_bool ($schema)) ? ['type' => $schema] : $schema;
        $res = [];
        foreach (array_keys ($schema) as $key)
            if (isset ($this->keywords[$key]))
                $res[] = $this->call ($this->keywords[$key], $data, $schema, $key);

        return $this->collapseResults ($res);
    }

    function isValid ($data, $schema = NULL)
    {
        return $this->hasErrors ($this->validate ($data, $schema));
    }
}

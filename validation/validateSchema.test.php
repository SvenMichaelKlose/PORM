<?php

$TC_RED   = "\u{001b}[31;1m";
$TC_GREEN = "\u{001b}[32;1m";
$TC_WHITE = "\u{001b}[37m";

require "validateSchema.php";
$path = "validateSchema.test.json";

echo "Testing function 'validateSchema'`…\n";

function validateSchema ($data, $schema)
{
    $v = new JSONSchema ($schema);
    return $v->validate ($data);
}

function doTests ($tests)
{
    global $TC_RED, $TC_GREEN, $TC_WHITE;

    $i = 1;
    foreach ($tests as $test) {
        echo $TC_WHITE . "Test " . $i++ . ": " . $test['description'] . "\n";

        $status = validateSchema ($test['data'], $test['schema']);

        if ($test['valid'] == $status['valid'])
            continue;

        echo $TC_RED;
        echo "\nERROR: Test " . ($i - 1) . " failed.\n";
        if ($test['valid'])
            echo "Unexpected error.\n";
        else
            echo "Error expected.\n";
        echo "\n";
        echo "Test:\n";
        echo json_encode ($test, JSON_PRETTY_PRINT) . "\n";
        echo "Report:\n";
        echo $TC_WHITE;
        echo json_encode ($status, JSON_PRETTY_PRINT) . "\n";

        exit (255);
    }
}

echo "Loading test data from `$path`…\n";
$json = file_get_contents ($path);
$tests = json_decode ($json, true);
if (!$tests) {
    echo $TC_RED . "ERROR: Invalid JSON syntax." . $TC_WHITE;
    exit (255);
}

doTests ($tests);

echo $TC_GREEN . "TESTS PASSED\n" . $TC_WHITE;

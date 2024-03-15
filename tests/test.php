#!/usr/bin/env php
<?php

$host = '0.0.0.0';
$port = '7000';
$user = $password = $database = 'test';

$TC_RED   = "\u{001b}[31;1m";
$TC_GREEN = "\u{001b}[32;1m";
$TC_WHITE = "\u{001b}[37m";

echo "###################\n" .
     "### PORM tests. ###\n" .
     "###################\n";

function dump ($x, $what = '')
{
    echo "# $what: \n";
    echo var_dump ($x) . "\n";
    return $x;
}

function dumpJSON ($x, $what = '')
{
    echo "# $what: \n";
    echo json_encode ($x, JSON_PRETTY_PRINT) . "\n";
    return $x;
}

define ('BASEDIR', '../');

require BASEDIR . 'lib/array.php';
require BASEDIR . 'orm/query-ops.php';
require BASEDIR . 'orm/Storage.class.php';
require BASEDIR . 'orm/SQLStorage.class.php';
require BASEDIR . 'orm/ReadonlyArray.class.php';
require BASEDIR . 'orm/WritableArray.class.php';
require BASEDIR . 'orm/Record.class.php';
require BASEDIR . 'orm/schema.php';

function print_error (string $x)
{
    global $TC_RED, $TC_WHITE;

    echo $TC_RED . $x . $TC_WHITE . "\n";
}

function print_success (string $x)
{
    global $TC_GREEN, $TC_WHITE;

    echo $TC_GREEN . $x . $TC_WHITE . "\n";
}

function print_ok ()
{
    print_success (' OK');
}

function check ($expected, $result)
{
    if ($result == $expected) {
        print_ok ();
        return;
    }

    print_error ('!!! ERROR !!!');
    echo "!!! Expected:\n";
    dumpJSON ($expected);
    echo "!!! Incorrect result:\n";
    dumpJSON ($result);
    exit (-1);
}

echo "# Connecting to database '$database' on $host:$port". "…";
$db = new mysqli ("$host:$port", $user, $password, $database);
if (!$db->ping ()) {
    error_log ($db->error);
    exit (-1);
}
$storage = new SQLStorage ($db);
Record::storage ($storage);
print_ok ();

echo $TC_GREEN .
     "###################################\n" .
     "### Congrats! All tests passed. ###\n" .
     "###################################\n" .
     $TC_WHITE;

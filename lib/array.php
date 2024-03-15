<?php

function ensure_array ($x)
{
    return is_array ($x) ? $x : [$x];
}

function is_assoc_array ($arr) : bool
{
    if (!is_array ($arr))
        return false;
    return array_keys ($arr) !== range (0, count ($arr) - 1);
}

function ksort_recursive ($a)
{
    if (!is_assoc_array ($a))
        return $a;

    ksort ($a);
    $sorted = [];
    foreach ($a as $k => $v)
        $sorted[$k] = ksort_recursive ($v);

    return $sorted;
}

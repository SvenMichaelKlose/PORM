<?php

function qOp ($op, $n, $v)
{
    return [$op => ['n' => $n, 'v' => $v]];
}

function qEq ($n, $v)
{
    return qOp ('=', $n, $v);
}

function qNeq ($n, $v)
{
    return qOp ('!=', $n, $v);
}

function qLt ($n, $v)
{
    return qOp ('<', $n, $v);
}

function qGt ($n, $v)
{
    return qOp ('>', $n, $v);
}

function qLte ($n, $v)
{
    return qOp ('<=', $n, $v);
}

function qGte ($n, $v)
{
    return qOp ('>=', $n, $v);
}

function qBetween ($n, $v)
{
    return qOp ('between', $n, $v);
}

function qLike ($n, $v)
{
    return qOp ('like', $n, $v);
}

function qAnd (...$x)
{
    return ['&' => array_filter ($x)];
}

function qOr (...$x)
{
    return ['|' => array_filter ($x)];
}

function qJoin (string $type, array $selection)
{
    return ['type' => $type, 'on' => $selection];
}

function qWhere (array $selection)
{
    return ['where' => $selection];
}

function qList (string $operator, string $predicate, string $field, array $values)
{
    return call_user_func_array (
        $operator,
        array_map (
            function ($value) use ($field, $predicate)
            {
                return call_user_func ($predicate, $field, $value);
            },
            $values
        )
    );
}

function qOrList (string $field, array $values)
{
    return qList ('qOr', 'qEq', $field, $values);
}

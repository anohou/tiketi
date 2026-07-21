<?php

namespace App\Support;

class TenantDatabaseReconciler
{
    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     * @return array{matched: list<string>, missing: list<string>, orphaned: list<string>}
     */
    public function classify(array $expected, array $actual): array
    {
        $expected = array_values(array_unique($expected));
        $actual = array_values(array_unique($actual));
        sort($expected);
        sort($actual);

        return [
            'matched' => array_values(array_intersect($expected, $actual)),
            'missing' => array_values(array_diff($expected, $actual)),
            'orphaned' => array_values(array_diff($actual, $expected)),
        ];
    }
}

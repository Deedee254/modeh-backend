<?php

namespace App\Traits;

trait SeedableShuffle
{
    /**
     * Deterministic shuffle using a seed.
     */
    protected function baseSeededShuffle(array $items, string $seed): array
    {
        $copy = array_values($items);
        $state = crc32($seed) & 0xFFFFFFFF;
        $lcg = function () use (&$state) {
            $state = (1103515245 * $state + 12345) & 0x7fffffff;
            return $state / 2147483647;
        };

        $n = count($copy);
        for ($i = $n - 1; $i > 0; $i--) {
            $r = $lcg();
            $j = (int) floor($r * ($i + 1));
            $tmp = $copy[$i];
            $copy[$i] = $copy[$j];
            $copy[$j] = $tmp;
        }

        return $copy;
    }
}

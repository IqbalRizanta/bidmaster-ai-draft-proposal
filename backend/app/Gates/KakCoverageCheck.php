<?php

namespace App\Gates;

use App\Gates\Contracts\GateCheck;

class KakCoverageCheck implements GateCheck
{
    public function check(array $ctx): ?string
    {
        $n = count($ctx['uncovered'] ?? []);

        return $n > 0 ? "{$n} kebutuhan KAK tanpa komponen cocok — petakan manual." : null;
    }
}

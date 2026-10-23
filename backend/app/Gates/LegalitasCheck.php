<?php

namespace App\Gates;

use App\Gates\Contracts\GateCheck;

class LegalitasCheck implements GateCheck
{
    public function check(array $ctx): ?string
    {
        $missing = $ctx['missing_legal'] ?? [];

        return $missing !== [] ? 'Legalitas kurang: '.implode(', ', $missing).'.' : null;
    }
}

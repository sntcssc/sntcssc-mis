<?php

namespace App\Actions\Support;

use App\Models\User;
use Carbon\Carbon;

class UrnGenerator
{
    public static function generate(): string
    {
        $datePart = Carbon::now()->format('Ymd');

        // milliseconds for uniqueness
        $timePart = substr((string) round(microtime(true) * 1000), -6);

        $urn = $datePart.$timePart;

        // Ensure uniqueness if a record already exists with the same URN
        if (User::where('urn', $urn)->exists()) {
            usleep(1000); // 1ms delay
            $timePart = substr((string) round(microtime(true) * 1000), -6);
            $urn = $datePart.$timePart;
        }

        return $urn;
    }
}

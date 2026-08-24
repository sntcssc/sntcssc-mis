<?php

namespace Database\Seeders;

use App\Services\RbacService;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RbacService::seedDefaults();
    }
}

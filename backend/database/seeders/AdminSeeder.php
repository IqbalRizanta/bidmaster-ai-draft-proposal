<?php

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Member::firstOrCreate(
            ['nama' => 'admin'],
            ['peran' => 'admin',
                'password' => Hash::make(config('bidmaster.seed_admin_pass'))],
        );
    }
}

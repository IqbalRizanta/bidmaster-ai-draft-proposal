<?php

use App\Models\Member;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bidmaster:admin-password {password?}', function (?string $password = null) {
    $admin = Member::where('peran', 'admin')->first();
    if (! $admin) {
        $this->error('Admin member tidak ditemukan.');

        return 1;
    }
    $newPass = $password ?: Str::random(12);
    $admin->password = Hash::make($newPass);
    $admin->save();
    $this->info("Password admin ({$admin->nama}) berhasil diperbarui: {$newPass}");

    return 0;
})->purpose('Reset password akun admin');

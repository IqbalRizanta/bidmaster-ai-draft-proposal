<?php

namespace App\Repositories;

use App\Models\ApiToken;
use App\Repositories\Contracts\TokenRepository;
use Illuminate\Support\Str;

class EloquentTokenRepository implements TokenRepository
{
    public function create(int $memberId, int $ttlSeconds): array
    {
        // Bersihkan token kedaluwarsa secara berkala
        ApiToken::where('expires_at', '<', time())->delete();

        $token = Str::random(48);
        $row = ApiToken::create([
            'token' => hash('sha256', $token),
            'member_id' => $memberId,
            'expires_at' => time() + $ttlSeconds,
        ]);

        return ['token' => $token, 'member_id' => $row->member_id, 'expires_at' => $row->expires_at];
    }

    public function findValid(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = ApiToken::with('member')->find(hash('sha256', $token));
        if (! $row || $row->expires_at < time() || ! $row->member) {
            return null;
        }

        return ['id' => $row->member->id, 'nama' => $row->member->nama, 'peran' => $row->member->peran];
    }

    public function revoke(string $token): void
    {
        if ($token !== '') {
            ApiToken::where('token', hash('sha256', $token))->delete();
        }
    }
}

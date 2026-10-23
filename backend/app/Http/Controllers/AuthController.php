<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\AuditRepository;
use App\Repositories\Contracts\MemberRepository;
use App\Repositories\Contracts\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function __construct(
        private MemberRepository $members,
        private TokenRepository $tokens,
        private AuditRepository $audits,
    ) {}

    public function login(Request $request)
    {
        $nama = mb_substr(trim((string) $request->input('nama', '')), 0, 40);
        $pw = (string) $request->input('pw', '');
        $m = $this->members->findByNama($nama);
        if (! $m || ! Hash::check($pw, $m->password)) {
            return response()->json(['error' => 'Nama/password salah.'], 401);
        }
        $s = $this->tokens->create($m->id, config('bidmaster.session_hours') * 3600);
        $this->audits->log($m->id, 'login');

        $secure = $request->isSecure() || app()->isProduction();

        return response()->json(['token' => $s['token'], 'nama' => $m->nama, 'peran' => $m->peran])
            ->cookie('bm_token', $s['token'], 0, '/', null, $secure, true, false, 'Strict');
    }

    public function logout(Request $request)
    {
        $auth = $request->header('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? mb_substr($auth, 7, 128) : $request->cookie('bm_token', '');
        $this->tokens->revoke($token);

        return response()->json(['ok' => true])->withoutCookie('bm_token');
    }

    public function me(Request $request)
    {
        $u = $request->attributes->get('member');

        return response()->json(['id' => $u['id'], 'nama' => $u['nama'], 'peran' => $u['peran']]);
    }
}

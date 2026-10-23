<?php

namespace Tests\Feature\Security;

use App\Models\ApiToken;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $peran = 'bd'): Member
    {
        return Member::create(['nama' => 'tester', 'peran' => $peran,
            'password' => Hash::make('rahasia123')]);
    }

    public function test_login_berhasil_tanpa_bocor_hash(): void
    {
        $this->user();
        $r = $this->postJson('/api/login', ['nama' => 'tester', 'pw' => 'rahasia123']);
        $r->assertOk()->assertJsonStructure(['token', 'nama', 'peran']);
        $this->assertStringNotContainsString('rahasia123', $r->getContent());
        $this->assertArrayNotHasKey('password', $r->json());
    }

    public function test_login_salah_401(): void
    {
        $this->user();
        $this->postJson('/api/login', ['nama' => 'tester', 'pw' => 'salah'])
            ->assertStatus(401);
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
        $this->postJson('/api/draft', [])->assertStatus(401);
    }

    public function test_logout_mematikan_token(): void
    {
        $this->user();
        $tok = $this->postJson('/api/login', ['nama' => 'tester', 'pw' => 'rahasia123'])->json('token');
        $this->getJson('/api/me', ['Authorization' => "Bearer {$tok}"])->assertOk();
        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$tok}"])->assertOk();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$tok}"])->assertStatus(401);
    }

    public function test_token_kedaluwarsa_ditolak(): void
    {
        $m = $this->user();
        $tok = 'kadaluarsa-test-token-1234567890abcdef';
        ApiToken::create(['token' => hash('sha256', $tok),
            'member_id' => $m->id, 'expires_at' => time() - 10]);
        $this->getJson('/api/me', ['Authorization' => "Bearer {$tok}"])->assertStatus(401);
    }

    public function test_token_disimpan_hash_bukan_plaintext(): void
    {
        $this->user();
        $tok = $this->postJson('/api/login', ['nama' => 'tester', 'pw' => 'rahasia123'])->json('token');
        $this->assertDatabaseMissing('api_tokens', ['token' => $tok]);
        $this->assertDatabaseHas('api_tokens', ['token' => hash('sha256', $tok)]);
    }

    public function test_artisan_reset_admin_password(): void
    {
        $this->user('admin');
        $this->artisan('bidmaster:admin-password passbaru123')
            ->assertExitCode(0);

        $this->postJson('/api/login', ['nama' => 'tester', 'pw' => 'passbaru123'])
            ->assertOk();
    }

    public function test_token_prune_expired(): void
    {
        $m = $this->user();
        ApiToken::create([
            'token' => hash('sha256', 'old-tok'),
            'member_id' => $m->id,
            'expires_at' => time() - 3600,
        ]);
        $this->assertDatabaseHas('api_tokens', ['token' => hash('sha256', 'old-tok')]);

        $this->postJson('/api/login', ['nama' => 'tester', 'pw' => 'rahasia123'])->assertOk();
        $this->assertDatabaseMissing('api_tokens', ['token' => hash('sha256', 'old-tok')]);
    }
}

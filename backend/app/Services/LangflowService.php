<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Proxy ke Langflow Run API (project Bidmaster_Proposal, 1 flow aktif). */
class LangflowService
{
    /** Berapa kali percobaan untuk galat yang bisa dicoba ulang (kuota/5xx). */
    private const MAX_ATTEMPTS = 2;

    public function configured(): bool
    {
        return (bool) config('bidmaster.langflow_url')
            && (bool) config('bidmaster.langflow_key');
    }

    public function flow(): string
    {
        return config('bidmaster.langflow_flow', 'generate_proposal_draft');
    }

    /**
     * Override Prompt node agar Bob bisa menjawab pertanyaan umum (di luar
     * konteks proposal) tanpa kehilangan kemampuan proposal/tender.
     */
    public function generalTemplate(): array
    {
        $node = config('bidmaster.langflow_prompt_node', 'Prompt Template-KZctx');
        $template = <<<'TPL'
        Kamu "Bob", asisten AI BidMaster AI.
        Jawab ramah, ringkas, dan akurat dalam bahasa Indonesia.
        - Pertanyaan umum (di luar tender) tetap dijawab dengan baik.
        - Untuk proposal/tender, gunakan KONTEKS PERUSAHAAN; jangan mengarang fakta, harga, atau sertifikasi.
        - Jika diminta membuat dokumen/file, tulis isi lengkap dan rapi dalam Markdown
          (judul, tabel, daftar) agar bisa diekspor ke Word.
        - Tandai hal yang belum pasti dengan [perlu konfirmasi].
        - Harga final dan keputusan akhir tetap di tangan manusia.

        KONTEKS PERUSAHAAN:
        {context}

        PERMINTAAN PENGGUNA:
        {tender}
        TPL;

        return [$node => ['template' => $template]];
    }

    /**
     * Jalankan flow. Galat yang bisa dicoba ulang (kuota 429 / 5xx) dicoba
     * sekali lagi sebelum menyerah. Pesan galat diterjemahkan ke bahasa
     * Indonesia yang ramah (bukan JSON mentah Langflow).
     *
     * @return array{ok:bool,text?:string,error?:string,code?:string,retryable?:bool}
     */
    public function run(string $message, string $flow, string $sessionId = '', array $tweaks = []): array
    {
        $payload = ['input_value' => $message, 'output_type' => 'chat', 'input_type' => 'chat'];
        if ($sessionId !== '') {
            $payload['session_id'] = $sessionId;
        }
        if ($tweaks !== []) {
            $payload['tweaks'] = $tweaks;
        }
        $url = rtrim(config('bidmaster.langflow_url'), '/')."/api/v1/run/{$flow}?stream=false";

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $res = Http::timeout(120)
                    ->withHeader('x-api-key', config('bidmaster.langflow_key'))
                    ->post($url, $payload);
            } catch (\Throwable $e) {
                return ['ok' => false, 'code' => 'LF_UNREACHABLE', 'retryable' => true,
                    'error' => 'Tidak dapat menghubungi mesin analisis (Langflow). Pastikan Langflow berjalan lalu coba lagi.'];
            }

            if ($res->successful()) {
                $text = $this->extract($res->json());
                if ($text === null) {
                    return ['ok' => false, 'code' => 'LF_FORMAT', 'retryable' => false,
                        'error' => 'Balasan mesin analisis tidak berisi teks. Coba lagi.'];
                }

                return ['ok' => true, 'text' => $text];
            }

            $err = $this->classify($res->status(), (string) $res->body());
            Log::warning('Langflow error', ['status' => $res->status(), 'code' => $err['code'],
                'attempt' => $attempt, 'body' => mb_substr((string) $res->body(), 0, 800)]);

            if ($err['retryable'] && $attempt < self::MAX_ATTEMPTS) {
                usleep(1_500_000); // jeda singkat sebelum percobaan ulang

                continue;
            }

            return ['ok' => false, 'code' => $err['code'], 'retryable' => $err['retryable'],
                'error' => $err['error']];
        }

        return ['ok' => false, 'code' => 'LF_ERROR', 'retryable' => true,
            'error' => 'Mesin analisis gagal. Coba lagi sebentar.'];
    }

    /**
     * Terjemahkan galat Langflow/HTTP menjadi pesan ramah + kode + bisa-ulang.
     *
     * @return array{code:string,error:string,retryable:bool}
     */
    private function classify(int $status, string $body): array
    {
        $b = mb_strtolower($body);

        if ($status === 429 || str_contains($b, 'resource_exhausted') || str_contains($b, 'resource exhausted')
            || str_contains($b, 'quota') || str_contains($b, 'rate limit')) {
            return ['code' => 'LF_QUOTA', 'retryable' => true,
                'error' => 'Kuota model AI di Langflow sedang habis (HTTP 429). Tunggu 1–2 menit lalu coba lagi; '
                    .'bila tetap, ganti model embedding/LLM di Langflow ke penyedia lain.'];
        }
        if (str_contains($b, 'no model class defined')) {
            return ['code' => 'LF_MODEL', 'retryable' => false,
                'error' => 'Model di Langflow tidak dikenali. Perbarui Langflow atau pilih model lain '
                    .'(mis. Groq llama-3.3-70b-versatile).'];
        }
        if ($status >= 500) {
            return ['code' => 'LF_SERVER', 'retryable' => true,
                'error' => "Mesin analisis (Langflow) sedang bermasalah (HTTP {$status}). Coba lagi sebentar."];
        }

        return ['code' => 'LF_HTTP', 'retryable' => false,
            'error' => "Mesin analisis menolak permintaan (HTTP {$status}). Periksa konfigurasi Langflow."];
    }

    private function extract(mixed $data): ?string
    {
        try {
            return $data['outputs'][0]['outputs'][0]['results']['message']['text'];
        } catch (\Throwable) {
        }
        try {
            return $data['outputs'][0]['outputs'][0]['messages'][0]['message'];
        } catch (\Throwable) {
        }

        return null;
    }
}

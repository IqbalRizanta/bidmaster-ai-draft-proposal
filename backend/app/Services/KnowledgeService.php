<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

/**
 * Loader knowledge + fallback .example (pola .env.example).
 * File asli (*.md/*.yaml) rahasia perusahaan; yang di-push hanya *.example.
 */
class KnowledgeService
{
    public const TAG = '[perlu konfirmasi';

    /** @var array<string,string> cache baca file per-request (file knowledge statis) */
    private array $cache = [];

    public function __construct(private string $dir) {}

    public function read(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }
        $p = $this->dir.DIRECTORY_SEPARATOR.$name;
        if (is_file($p)) {
            $txt = file_get_contents($p);
        } else {
            $stem = pathinfo($name, PATHINFO_FILENAME).'.example';
            $pExample = $this->dir.DIRECTORY_SEPARATOR.$stem;
            $txt = is_file($pExample) ? file_get_contents($pExample) : '';
        }
        // Strip header sanitasi "# CONTOH ..." sampai baris kosong pertama.
        if (str_starts_with(ltrim($txt), '# CONTOH')) {
            $lines = explode("\n", $txt);
            foreach ($lines as $i => $ln) {
                if (trim($ln) === '') {
                    $txt = ltrim(implode("\n", array_slice($lines, $i + 1)))."\n";
                    break;
                }
            }
        }

        return $this->cache[$name] = $txt;
    }

    /**
     * Baca JSON dengan fallback .example
     * (mis. perusahaan.json -> perusahaan.example). Untuk file YAML,
     * teruskan apa adanya karena read() sudah fallback sendiri.
     */
    public function json(string $name): array
    {
        $data = json_decode($this->read($name), true);

        return is_array($data) ? $data : [];
    }

    /** @return array{template:string,catalog:array} */
    public function website(): array
    {
        $template = $this->read('proposal_template.md');
        if (($i = strpos($template, '# {client_name}')) !== false) {
            $template = substr($template, $i);
        }

        return ['template' => $template, 'catalog' => Yaml::parse($this->read('price_catalog.yaml'))];
    }

    /** @return array{tender:array,template:string,company:string,team:array} */
    public function tender(): array
    {
        return [
            'tender' => Yaml::parse($this->read('tender_price.yaml')),
            'template' => $this->read('tender_template.md'),
            'company' => $this->read('tender_company.md'),
            'team' => Yaml::parse($this->read('tender_team.yaml')),
        ];
    }

    /** Teks ringkasan kapabilitas perusahaan K01-K18 */
    public function knowledgePerusahaan(): string
    {
        return $this->read('knowledge_perusahaan.md');
    }
}

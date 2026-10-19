<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WebSearch
{
    public function search(string $query): array
    {
        abort_unless(config('models.search_url'), 503, 'Configure your self-hosted SearXNG search endpoint first.');
        $r = Http::acceptJson()->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])->get(config('models.search_url'), ['q' => mb_substr($query, 0, 500), 'format' => 'json']);
        abort_unless($r->successful(), 503, 'Your search service is unavailable.');

        return collect($r->json('results', []))->filter(fn ($v) => is_array($v) && is_string($v['url'] ?? null) && in_array(parse_url($v['url'], PHP_URL_SCHEME), ['http', 'https']))->take(5)->map(fn ($v) => ['title' => mb_substr(strip_tags((string) ($v['title'] ?? $v['url'])), 0, 200), 'url' => mb_substr($v['url'], 0, 2000), 'snippet' => mb_substr(strip_tags((string) ($v['content'] ?? '')), 0, 2000)])->values()->all();
    }
}

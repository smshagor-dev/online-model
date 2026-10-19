<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WebSearch
{
    public static function canonicalUrl(string $url): ?string
    {
        $p = parse_url($url);
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && ! in_array($p['port'], [80, 443], true))) {
            return null;
        }
        $host = rtrim(strtolower($p['host']), '.');
        if (! preg_match('/^[a-z0-9.-]+$/', $host) || ! str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(utm_|fbclid$|gclid$)/i', $key)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return strtolower($p['scheme']).'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '/').($query ? '?'.http_build_query($query) : '');
    }

    public function search(string $query, array $options = []): array
    {
        abort_unless(config('models.search_url'), 503, 'Configure your self-hosted SearXNG search endpoint first.');
        $params = ['q' => mb_substr($query, 0, 500), 'format' => 'json', 'safesearch' => 1];
        if (in_array($options['time_range'] ?? '', ['day', 'week', 'month', 'year'], true)) {
            $params['time_range'] = $options['time_range'];
        }
        $r = Http::acceptJson()->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])->get(config('models.search_url'), $params);
        abort_unless($r->successful() && is_array($r->json('results')), 503, 'Your search service is unavailable or returned invalid results.');
        $results = [];
        foreach ($r->json('results') as $v) {
            if (! is_array($v) || ! is_string($v['url'] ?? null) || strlen($v['url']) > 2000 || ! ($url = self::canonicalUrl($v['url']))) {
                continue;
            }
            if (isset($results[$url])) {
                continue;
            }
            $text = fn ($value, $max) => mb_substr(html_entity_decode(strip_tags(is_scalar($value) ? (string) $value : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, $max);
            $results[$url] = ['title' => $text($v['title'] ?? $url, 200) ?: mb_substr($url, 0, 200), 'url' => $url, 'snippet' => $text($v['content'] ?? '', 2000)];
            if (count($results) >= min(10, max(1, (int) ($options['limit'] ?? 5)))) {
                break;
            }
        }

        return array_values($results);
    }
}

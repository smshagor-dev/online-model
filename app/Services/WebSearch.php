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

    public static function isSearchPortal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $portal = preg_match('/^(www\.|search\.|html\.)?(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.com|search\.yahoo\.com)$/D', $host);

        return (bool) $portal && ($path === '' || preg_match('~^/(search|xhtml/search|html|lite|web|results)(/|$)~', $path));
    }

    public function search(string $query, array $options = []): array
    {
        $params = ['q' => mb_substr($query, 0, 500), 'format' => 'json', 'safesearch' => 1];
        if (in_array($options['time_range'] ?? '', ['day', 'week', 'month', 'year'], true)) {
            $params['time_range'] = $options['time_range'];
        }
        $providers = [];
        if (config('models.search_url')) {
            $providers[] = ['searxng', config('models.search_url'), $params];
        }
        if (config('models.public_search_fallback')) {
            $providers[] = ['bing_rss', 'https://www.bing.com/search', ['q' => $params['q'], 'format' => 'rss']];
            $providers[] = ['duckduckgo', 'https://html.duckduckgo.com/html/', ['q' => $params['q']]];
        }
        foreach ($providers as [$provider, $url, $queryParams]) {
            try {
                $r = Http::withHeaders(['Accept' => $provider === 'searxng' ? 'application/json' : 'application/rss+xml,text/html', 'User-Agent' => 'OpenMindAI-Research/1.0'])->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url, $queryParams);
                if (! $r->successful()) {
                    continue;
                }
                $stream = $r->toPsrResponse()->getBody();
                try {
                    $body = '';
                    while (! $stream->eof()) {
                        $body .= $stream->read(65536);
                        if (strlen($body) > 1000000) {
                            throw new \RuntimeException('Search response too large.');
                        }
                    }
                } finally {
                    $stream->close();
                }
                $rows = $this->parseResults($provider, $body);
                $results = $this->normalize($rows, $options);
                if ($results) {
                    return array_map(fn ($source) => [...$source, 'provider' => $provider, 'time_filter_applied' => $provider === 'searxng' && isset($params['time_range'])], $results);
                }
            } catch (\Throwable $e) {
                // Try the next provider without exposing credentials or upstream error bodies.
            }
        }
        abort(503, 'Web search is unavailable. No live sources could be retrieved.');
    }

    private function parseResults(string $provider, string $body): array
    {
        if ($provider === 'searxng') {
            $data = json_decode($body, true);

            return is_array($data['results'] ?? null) ? $data['results'] : [];
        }
        if (stripos($body, '<!DOCTYPE') !== false && $provider === 'bing_rss') {
            return [];
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $provider === 'bing_rss'
                ? $dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                : $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                return [];
            }
            $rows = [];
            if ($provider === 'bing_rss') {
                foreach ($dom->getElementsByTagName('item') as $item) {
                    $rows[] = ['url' => $item->getElementsByTagName('link')->item(0)?->textContent ?? '', 'title' => $item->getElementsByTagName('title')->item(0)?->textContent ?? '', 'content' => $item->getElementsByTagName('description')->item(0)?->textContent ?? ''];
                }
            } else {
                $xpath = new \DOMXPath($dom);
                foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]') as $link) {
                    $url = $link->getAttribute('href');
                    if (str_starts_with($url, '//')) {
                        $url = 'https:'.$url;
                    }
                    $parts = parse_url($url);
                    if (in_array($parts['host'] ?? '', ['duckduckgo.com', 'www.duckduckgo.com'], true)) {
                        parse_str($parts['query'] ?? '', $wrapped);
                        $url = is_string($wrapped['uddg'] ?? null) ? $wrapped['uddg'] : '';
                    }
                    $rows[] = ['url' => $url, 'title' => $link->textContent, 'content' => ''];
                }
            }

            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalize(array $rows, array $options): array
    {
        $results = [];
        foreach ($rows as $v) {
            if (! is_array($v) || ! is_string($v['url'] ?? null) || strlen($v['url']) > 2000 || ! ($url = self::canonicalUrl($v['url']))) {
                continue;
            }
            if (self::isSearchPortal($url) || isset($results[$url])) {
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

<?php

namespace App\Services;

class ArtifactPreviews
{
    public function validate(mixed $items): array
    {
        if (! is_array($items) || count($items) > 21 || strlen(json_encode($items, JSON_INVALID_UTF8_SUBSTITUTE)) > 2000000) {
            throw new \RuntimeException('Invalid artifact previews.');
        }
        $valid = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null) || mb_strlen($item['name']) > 200) {
                throw new \RuntimeException('Invalid artifact name.');
            }
            $preview = ['name' => basename($item['name']), 'type' => $item['type'] ?? ''];
            if ($preview['type'] === 'image') {
                $raw = is_string($item['content'] ?? null) ? base64_decode($item['content'], true) : false;
                $size = $raw ? @getimagesizefromstring($raw) : false;
                if (! $raw || strlen($raw) > 500000 || ! $size || $size[0] * $size[1] > 16000000 || $size['mime'] !== 'image/png') {
                    throw new \RuntimeException('Invalid preview image.');
                }
                $preview['content'] = base64_encode($raw);
            } elseif ($preview['type'] === 'table') {
                $rows = $item['rows'] ?? null;
                if (! is_array($rows) || count($rows) > 101) {
                    throw new \RuntimeException('Invalid preview table.');
                }
                foreach ($rows as $row) {
                    if (! is_array($row) || count($row) > 30) {
                        throw new \RuntimeException('Invalid preview row.');
                    }foreach ($row as $cell) {
                        if (! is_string($cell) || mb_strlen($cell) > 2048) {
                            throw new \RuntimeException('Invalid preview cell.');
                        }
                    }
                }
                $preview['rows'] = array_map('array_values', array_values($rows));
            } elseif ($preview['type'] === 'text') {
                if (! is_string($item['content'] ?? null) || mb_strlen($item['content']) > 20000) {
                    throw new \RuntimeException('Invalid text preview.');
                }$preview['content'] = $item['content'];
            } else {
                throw new \RuntimeException('Unsupported preview.');
            }
            $valid[] = $preview;
        }

        return $valid;
    }
}

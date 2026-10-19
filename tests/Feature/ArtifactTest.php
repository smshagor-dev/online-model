<?php

namespace Tests\Feature;

use App\Services\ArtifactPreviews;
use Tests\TestCase;

class ArtifactTest extends TestCase
{
    public function test_safe_previews_are_normalized(): void
    {
        $items = app(ArtifactPreviews::class)->validate([['name' => 'nested/data.csv', 'type' => 'table', 'rows' => [['x', 'y'], ['1', '2']]], ['name' => 'note.txt', 'type' => 'text', 'content' => 'Hello']]);
        $this->assertSame('data.csv', $items[0]['name']);
        $this->assertSame('2', $items[0]['rows'][1][1]);
    }

    public function test_html_and_non_image_payloads_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ArtifactPreviews::class)->validate([['name' => 'fake.png', 'type' => 'image', 'content' => base64_encode('<script>bad</script>')]]);
    }

    public function test_unbounded_rows_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ArtifactPreviews::class)->validate([['name' => 'data.csv', 'type' => 'table', 'rows' => array_fill(0, 102, ['1'])]]);
    }

    public function test_valid_png_preview_is_accepted(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=';
        $items = app(ArtifactPreviews::class)->validate([['name' => 'chart.png', 'type' => 'image', 'content' => $png]]);
        $this->assertSame($png, $items[0]['content']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Canvas;
use Illuminate\Http\Request;

class CanvasController
{
    public function index(Request $r)
    {
        return Canvas::where('user_id', $r->user()->id)->latest('updated_at')->get(['id', 'title', 'format', 'version', 'updated_at']);
    }

    public function show(Request $r, int $id)
    {
        return Canvas::where('user_id', $r->user()->id)->findOrFail($id);
    }

    public function create(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:120', 'format' => 'required|in:markdown,code', 'content' => 'present|string|max:50000']);
        abort_if(Canvas::where('user_id', $r->user()->id)->count() >= 100, 422, 'Canvas limit reached.');

        return Canvas::create(['user_id' => $r->user()->id, ...$d]);
    }

    public function update(Request $r, int $id)
    {
        $c = Canvas::where('user_id', $r->user()->id)->findOrFail($id);
        $d = $r->validate(['title' => 'required|string|max:120', 'format' => 'required|in:markdown,code', 'content' => 'present|string|max:50000', 'version' => 'required|integer|min:1']);
        $version = $d['version'];
        unset($d['version']);
        $ok = Canvas::whereKey($id)->where('version', $version)->update([...$d, 'version' => $version + 1, 'updated_at' => now()]);
        abort_unless($ok, 409, 'This canvas changed in another tab. Reopen it before saving.');

        return $c->fresh();
    }

    public function delete(Request $r, int $id)
    {
        Canvas::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }
}

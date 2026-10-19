<?php

namespace App\Http\Controllers;

use App\Models\Canvas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function rules(): array
    {
        return ['title' => 'required|string|max:120', 'format' => 'required|in:markdown,code', 'content' => 'present|string|max:50000'];
    }

    private function snapshot(Canvas $c): void
    {
        DB::table('canvas_versions')->insertOrIgnore(['canvas_id' => $c->id, 'version' => $c->version, 'title' => $c->title, 'format' => $c->format, 'content' => $c->content, 'created_at' => now(), 'updated_at' => now()]);
        $keep = DB::table('canvas_versions')->where('canvas_id', $c->id)->orderByDesc('version')->limit(50)->pluck('id');
        DB::table('canvas_versions')->where('canvas_id', $c->id)->whereNotIn('id', $keep)->delete();
    }

    public function create(Request $r)
    {
        $d = $r->validate($this->rules());
        abort_if(Canvas::where('user_id', $r->user()->id)->count() >= 100, 422, 'Canvas limit reached.');

        return DB::transaction(function () use ($r, $d) {
            $c = Canvas::create(['user_id' => $r->user()->id, ...$d]);
            $c->refresh();
            $this->snapshot($c);

            return $c;
        });
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate([...$this->rules(), 'version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $id, $d) {
            $c = Canvas::where('user_id', $r->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($c->version === $d['version'], 409, 'This canvas changed in another tab. Reopen it before saving.');
            $this->snapshot($c);
            $c->fill([...$d, 'version' => $d['version'] + 1])->save();
            $this->snapshot($c);

            return $c;
        });
    }

    public function versions(Request $r, int $id)
    {
        $this->show($r, $id);

        return DB::table('canvas_versions')->where('canvas_id', $id)->orderByDesc('version')->get(['version', 'title', 'format', 'created_at']);
    }

    public function version(Request $r, int $id, int $version)
    {
        $this->show($r, $id);

        return DB::table('canvas_versions')->where('canvas_id', $id)->where('version', $version)->firstOrFail();
    }

    public function delete(Request $r, int $id)
    {
        $this->show($r,$id)->delete();

        return response()->noContent();
    }
}

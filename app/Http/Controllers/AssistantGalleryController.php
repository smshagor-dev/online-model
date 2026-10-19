<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\PublishedAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AssistantGalleryController
{
    public function index(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);

        return PublishedAssistant::where('blocked', false)->whereHas('user', fn ($q) => $q->where('is_active', true))->with('user:id,name')->when($r->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$r->query('q').'%'))->latest()->paginate(20)->through(fn ($a) => ['slug' => $a->slug, 'name' => $a->name, 'description' => $a->description, 'author' => $a->user->name]);
    }

    public function show(string $slug)
    {
        $a = PublishedAssistant::where('slug', $slug)->where('blocked', false)->whereHas('user', fn ($q) => $q->where('is_active', true))->with('user:id,name')->firstOrFail();

        return ['slug' => $a->slug, 'name' => $a->name, 'description' => $a->description, 'author' => $a->user->name, 'instructions' => $a->instructions];
    }

    public function publish(Request $r, int $id)
    {
        $r->validate(['public_consent' => 'required|accepted']);
        $a = Assistant::where('user_id', $r->user()->id)->findOrFail($id);
        abort_if(PublishedAssistant::where('assistant_id', $a->id)->where('blocked', true)->exists(), 403, 'This publication is blocked by an administrator.');

        return PublishedAssistant::updateOrCreate(['assistant_id' => $a->id], ['user_id' => $r->user()->id, 'slug' => PublishedAssistant::where('assistant_id', $a->id)->value('slug') ?: (string) Str::uuid(), 'name' => $a->name, 'description' => $a->description, 'instructions' => $a->instructions]);
    }

    public function unpublish(Request $r, int $id)
    {
        Assistant::where('user_id', $r->user()->id)->findOrFail($id);
        PublishedAssistant::where('assistant_id', $id)->where('blocked', false)->delete();

        return response()->noContent();
    }

    public function copy(Request $r, string $slug)
    {
        return Cache::lock('assistant-copy:'.$r->user()->id, 10)->block(2, function () use ($r, $slug) {
            $a = $this->show($slug);
            abort_if(Assistant::where('user_id', $r->user()->id)->count() >= 50, 422, 'Assistant limit reached.');

            return response()->json(Assistant::create(['user_id' => $r->user()->id, 'name' => $a['name'], 'description' => $a['description'], 'instructions' => $a['instructions'], 'attachment_ids' => [], 'model_id' => null]), 201);
        });
    }

    public function moderation()
    {
        return view('admin.assistant-gallery', ['publications' => PublishedAssistant::with('user:id,name')->latest()->paginate(20)]);
    }

    public function moderate(Request $r, PublishedAssistant $publication)
    {
        $d = $r->validate(['blocked' => 'required|boolean']);
        $publication->update($d);

        return back()->with('status', 'Publication visibility updated.');
    }
}

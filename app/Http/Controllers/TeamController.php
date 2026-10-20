<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController
{
    private function access(Request $r, int $id, bool $manage = false): Team
    {
        $team = Team::whereHas('members', fn ($q) => $q->where('users.id', $r->user()->id))->findOrFail($id);
        $role = $team->members()->where('users.id', $r->user()->id)->first()->pivot->role;
        abort_if($manage && ! in_array($role, ['owner', 'admin']), 403);

        return $team;
    }

    public function index(Request $r)
    {
        return ['teams' => Team::whereHas('members', fn ($q) => $q->where('users.id', $r->user()->id))->with(['members:id,name'])->get(), 'invites' => DB::table('team_invites')->join('teams', 'teams.id', '=', 'team_invites.team_id')->where('email', strtolower($r->user()->email))->where('expires_at', '>', now())->get(['team_invites.id', 'teams.name', 'team_invites.role', 'team_invites.expires_at'])];
    }

    public function create(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120']);

        return DB::transaction(function () use ($r, $d) {
            DB::table('users')->where('id', $r->user()->id)->lockForUpdate()->first();
            abort_if(Team::where('user_id', $r->user()->id)->count() >= 10, 422, 'Team limit reached.');
            $team = Team::create(['user_id' => $r->user()->id, 'name' => $d['name']]);
            $team->members()->attach($r->user()->id, ['role' => 'owner']);

            return $team;
        });
    }

    public function show(Request $r, int $id)
    {
        $team = $this->access($r, $id);

        return ['team' => $team->load('members:id,name'), 'documents' => DB::table('team_documents')->where('team_id', $id)->latest('id')->get(['id', 'title', 'revision', 'updated_at']), 'threads' => DB::table('team_threads')->where('team_id', $id)->latest('updated_at')->limit(100)->get(), 'invites' => $team->members()->where('users.id', $r->user()->id)->first()->pivot->role === 'member' ? [] : DB::table('team_invites')->where('team_id', $id)->get(['id', 'email', 'role', 'expires_at'])];
    }

    public function invite(Request $r, int $id)
    {
        $this->access($r, $id, true);
        $d = $r->validate(['email' => 'required|email|max:254', 'role' => 'required|in:member,admin']);

        return DB::transaction(function () use ($r, $id, $d) {
            Team::whereKey($id)->lockForUpdate()->firstOrFail();
            $team = $this->access($r, $id, true);
            abort_if($d['role'] === 'admin' && $team->user_id !== $r->user()->id, 403);
            abort_if($team->members()->count() + DB::table('team_invites')->where('team_id', $id)->where('expires_at', '>', now())->count() >= 100, 422, 'Team member limit reached.');
            DB::table('team_invites')->updateOrInsert(['team_id' => $id, 'email' => strtolower($d['email'])], ['role' => $d['role'], 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return ['invited' => true];
        });
    }

    public function accept(Request $r, int $invite)
    {
        abort_unless($r->user()->email_verified_at, 403, 'Verify your email before accepting an invitation.');

        return DB::transaction(function () use ($r, $invite) {
            $row = DB::table('team_invites')->where('id', $invite)->first();
            abort_unless($row && $row->email === strtolower($r->user()->email), 404);
            $team = Team::whereKey($row->team_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('team_invites')->where('id', $invite)->lockForUpdate()->first();
            abort_unless($row && $row->expires_at > now() && $team->members()->count() < 100, 409, 'Invitation expired or team is full.');
            // Accepting a second invitation cannot change an existing member's role.
            if (! $team->members()->where('users.id', $r->user()->id)->exists()) {
                $team->members()->attach($r->user()->id, ['role' => $row->role]);
            }
            DB::table('team_invites')->where('id', $invite)->delete();

            return ['team_id' => $team->id];
        });
    }

    public function revokeInvite(Request $r, int $id, int $invite)
    {
        $this->access($r, $id, true);
        DB::table('team_invites')->where('team_id', $id)->where('id', $invite)->delete();

        return response()->noContent();
    }

    public function member(Request $r, int $id, int $member)
    {
        return DB::transaction(function () use ($r, $id, $member) {
            Team::whereKey($id)->lockForUpdate()->firstOrFail();
            $team = $this->access($r, $id);
            $self = $r->user()->id === $member;
            if (! $self || $r->isMethod('put')) {
                $this->access($r, $id, true);
            }
            abort_if($team->user_id === $member, 409, 'The owner cannot leave or be demoted. Delete the team instead.');
            // Only the owner may change/remove an admin.
            $target = $team->members()->where('users.id', $member)->firstOrFail();
            abort_if(! $self && $target->pivot->role === 'admin' && $team->user_id !== $r->user()->id, 403);
            if ($r->isMethod('delete')) {
                $team->members()->detach($member);
            } else {
                abort_unless($team->user_id === $r->user()->id, 403);
                $d = $r->validate(['role' => 'required|in:member,admin']);
                $team->members()->updateExistingPivot($member, ['role' => $d['role']]);
            }

            return response()->noContent();
        });
    }

    public function delete(Request $r, int $id)
    {
        $team = $this->access($r, $id);
        abort_unless($team->user_id === $r->user()->id, 403);
        $r->validate(['confirmation' => 'required|in:DELETE']);
        $team->delete();

        return response()->noContent();
    }

    public function document(Request $r, int $id, ?int $document = null)
    {
        return DB::transaction(function () use ($r, $id, $document) {
            Team::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access($r, $id);
            $q = DB::table('team_documents')->where('team_id', $id)->where('id', $document);
            if ($r->isMethod('get')) {
                return $q->firstOrFail();
            }
            if ($r->isMethod('delete')) {
                $q->delete();

                return response()->noContent();
            }
            $d = $r->validate(['title' => 'required|string|max:120', 'content' => 'present|nullable|string|max:100000', 'revision' => $document ? 'required|integer|min:1' : 'sometimes|integer']);
            if ($document) {
                abort_unless($q->where('revision', $d['revision'])->update(['title' => $d['title'], 'content' => $d['content'] ?? '', 'revision' => $d['revision'] + 1, 'updated_at' => now()]), 409, 'Document changed. Reload before saving.');
            } else {
                abort_if(DB::table('team_documents')->where('team_id', $id)->count() >= 100, 422, 'Document limit reached.');
                $document = DB::table('team_documents')->insertGetId(['team_id' => $id, 'title' => $d['title'], 'content' => $d['content'] ?? '', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }

            return DB::table('team_documents')->where('id', $document)->first();
        });
    }

    private function snapshot(Request $r, int $conversation): string
    {
        $c = Conversation::where('user_id', $r->user()->id)->findOrFail($conversation);
        $rows = $c->messages()->where('status', 'complete')->orderBy('id')->limit(101)->get();
        abort_if($rows->count() > 100, 422, 'Share a smaller chat.');
        $content = $rows->map(fn ($m) => $m->role.': '.$m->content)->implode("\n\n");
        abort_if(strlen($content) > 190000 || ! trim($content), 422, 'Share a smaller completed chat.');

        return $content;
    }

    public function preview(Request $r, int $id)
    {
        $this->access($r, $id);
        $d = $r->validate(['conversation_id' => 'required|integer']);
        $snapshot = $this->snapshot($r, $d['conversation_id']);

        return ['content' => $snapshot, 'revision' => hash('sha256', $snapshot)];
    }

    public function thread(Request $r, int $id, ?int $thread = null)
    {
        return DB::transaction(function () use ($r, $id, $thread) {
            Team::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access($r, $id);
            if ($thread) {
                $row = DB::table('team_threads')->where('team_id', $id)->where('id', $thread)->firstOrFail();
                if ($r->isMethod('get')) {
                    return ['thread' => $row, 'posts' => DB::table('team_posts')->where('team_thread_id', $thread)->orderByDesc('id')->limit(100)->get()->reverse()->values()];
                }
                if ($r->isMethod('delete')) {
                    $this->access($r, $id, true);
                    DB::table('team_threads')->where('id', $thread)->delete();

                    return response()->noContent();
                }
                $d = $r->validate(['content' => 'required|string|max:16000']);
                $content = $d['content'];
            } else {
                $d = $r->validate(['title' => 'required|string|max:120', 'content' => 'nullable|string|max:16000', 'conversation_id' => 'nullable|integer', 'share_revision' => 'required_with:conversation_id|string|size:64', 'share_consent' => 'required_with:conversation_id|accepted']);
                abort_if(DB::table('team_threads')->where('team_id', $id)->count() >= 100, 422, 'Thread limit reached.');
                $content = $d['content'] ?? '';
                if (! empty($d['conversation_id'])) {
                    $snapshot = $this->snapshot($r, $d['conversation_id']);
                    abort_unless(hash_equals(hash('sha256', $snapshot), $d['share_revision']), 409, 'Chat changed after preview. Review the snapshot again.');
                    $content .= "\n\nShared chat snapshot:\n".$snapshot;
                }
                abort_if(strlen($content) > 200000 || ! trim($content), 422, 'Enter a message or share a smaller completed chat.');
                $thread = DB::table('team_threads')->insertGetId(['team_id' => $id, 'title' => $d['title'], 'created_at' => now(), 'updated_at' => now()]);
            }
            abort_if(DB::table('team_posts')->where('team_thread_id', $thread)->count() >= 1000, 422, 'Start another thread.');
            $post = DB::table('team_posts')->insertGetId(['team_thread_id' => $thread, 'user_id' => $r->user()->id, 'author' => $r->user()->name, 'content' => $content, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_threads')->where('id', $thread)->update(['updated_at' => now()]);

            return ['id' => $post, 'thread_id' => $thread];
        });
    }
}

<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        $u = User::create(['name' => $name, 'email' => $name.'@example.com', 'password' => 'StrongPassword123']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    private function team(User $owner): int
    {
        Sanctum::actingAs($owner);

        return $this->postJson('/api/v1/teams', ['name' => 'Research team'])->assertCreated()->json('id');
    }

    public function test_invitation_acceptance_requires_verified_correct_email_and_member_revocation_is_immediate(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $other = $this->user('other');
        $id = $this->team($owner);
        $this->postJson("/api/v1/teams/$id/invites", ['email' => $member->email, 'role' => 'member'])->assertOk();
        $invite = DB::table('team_invites')->value('id');
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/team-invites/$invite/accept")->assertNotFound();
        Sanctum::actingAs($member);
        $member->forceFill(['email_verified_at' => null])->save();
        $this->postJson("/api/v1/team-invites/$invite/accept")->assertForbidden();
        $member->forceFill(['email_verified_at' => now()])->save();
        $this->postJson("/api/v1/team-invites/$invite/accept")->assertOk();
        $this->getJson("/api/v1/teams/$id")->assertOk()->assertJsonCount(2, 'team.members');
        $this->postJson("/api/v1/teams/$id/invites", ['email' => $other->email, 'role' => 'admin'])->assertForbidden();
        $this->deleteJson("/api/v1/teams/$id/members/{$owner->id}")->assertForbidden();
        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/teams/$id/members/{$member->id}")->assertNoContent();
        Sanctum::actingAs($member);
        $this->getJson("/api/v1/teams/$id")->assertNotFound();
        $this->postJson("/api/v1/teams/$id/threads", ['title' => 'No', 'content' => 'Denied'])->assertNotFound();
    }

    public function test_document_revision_prevents_lost_updates_and_cross_team_reads(): void
    {
        $owner = $this->user('owner');
        $id = $this->team($owner);
        $doc = $this->postJson("/api/v1/teams/$id/documents", ['title' => 'Plan', 'content' => 'Original'])->assertOk()->json('id');
        $this->putJson("/api/v1/teams/$id/documents/$doc", ['title' => 'Plan', 'content' => 'First edit', 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->putJson("/api/v1/teams/$id/documents/$doc", ['title' => 'Plan', 'content' => 'Overwrite', 'revision' => 1])->assertStatus(409);
        $this->getJson("/api/v1/teams/$id/documents/$doc")->assertJsonPath('content', 'First edit');
        $other = $this->user('other');
        $otherTeam = $this->team($other);
        $this->getJson("/api/v1/teams/$otherTeam/documents/$doc")->assertNotFound();
        $this->putJson("/api/v1/teams/$otherTeam/documents/$doc", ['title' => 'Attack', 'content' => 'No', 'revision' => 2])->assertStatus(409);
    }

    public function test_chat_sharing_is_explicit_owned_snapshot_and_team_deletion_cascades(): void
    {
        $owner = $this->user('owner');
        $id = $this->team($owner);
        $c = Conversation::create(['user_id' => $owner->id, 'title' => 'Private']);
        $c->messages()->create(['role' => 'user', 'content' => 'Question', 'status' => 'complete']);
        $c->messages()->create(['role' => 'assistant', 'content' => 'Public answer', 'status' => 'complete']);
        $c->messages()->create(['role' => 'assistant', 'content' => 'UNFINISHED SECRET', 'status' => 'failed']);
        $this->postJson("/api/v1/teams/$id/threads", ['title' => 'Review', 'conversation_id' => $c->id])->assertUnprocessable();
        $revision = $this->postJson("/api/v1/teams/$id/preview", ['conversation_id' => $c->id])->assertOk()->json('revision');
        $thread = $this->postJson("/api/v1/teams/$id/threads", ['title' => 'Review', 'conversation_id' => $c->id, 'share_consent' => true, 'share_revision' => $revision])->assertOk()->json('thread_id');
        $this->getJson("/api/v1/teams/$id/threads/$thread")->assertSee('Public answer')->assertDontSee('UNFINISHED SECRET');
        $c->messages()->where('content', 'Public answer')->update(['content' => 'CHANGED']);
        $this->getJson("/api/v1/teams/$id/threads/$thread")->assertDontSee('CHANGED');
        $this->postJson("/api/v1/teams/$id/threads", ['title' => 'Stale preview', 'conversation_id' => $c->id, 'share_consent' => true, 'share_revision' => $revision])->assertStatus(409);
        $this->postJson("/api/v1/teams/$id/threads/$thread", ['content' => 'Team reply'])->assertOk();
        $this->getJson("/api/v1/teams/$id/threads/$thread")->assertJsonCount(2, 'posts');
        $other = $this->user('other');
        $foreign = Conversation::create(['user_id' => $other->id, 'title' => 'Other']);
        $this->postJson("/api/v1/teams/$id/threads", ['title' => 'Attack', 'conversation_id' => $foreign->id, 'share_consent' => true, 'share_revision' => str_repeat('a', 64)])->assertNotFound();
        $this->deleteJson("/api/v1/teams/$id/members/{$owner->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/teams/$id", ['confirmation' => 'DELETE'])->assertNoContent();
        $this->assertDatabaseCount('team_threads', 0);
        $this->assertDatabaseCount('team_posts', 0);
        $this->assertDatabaseCount('team_members', 0);
        $this->assertDatabaseHas('conversations', ['id' => $c->id]);
    }

    public function test_admin_cannot_create_admins_or_remove_owner_and_expired_invites_cannot_be_accepted(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('admin');
        $member = $this->user('member');
        $id = $this->team($owner);
        Team::find($id)->members()->attach($admin->id, ['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/teams/$id/invites", ['email' => $member->email, 'role' => 'admin'])->assertForbidden();
        $this->putJson("/api/v1/teams/$id/members/{$owner->id}", ['role' => 'member'])->assertStatus(409);
        $this->postJson("/api/v1/teams/$id/invites", ['email' => $member->email, 'role' => 'member'])->assertOk();
        $invite = DB::table('team_invites')->value('id');
        $this->travel(8)->days();
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/team-invites/$invite/accept")->assertStatus(409);
    }
}

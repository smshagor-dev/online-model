<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\PublishedAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Author', 'email' => uniqid().'@example.org', 'password' => 'StrongPassword123']);
    }

    public function test_explicit_publication_is_a_snapshot_and_copy_keeps_files_private(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $assistant = Assistant::create(['user_id' => $u->id, 'name' => 'Tutor', 'description' => 'Learning help', 'instructions' => 'Explain clearly', 'attachment_ids' => [999]]);
        $this->postJson('/api/v1/assistants/'.$assistant->id.'/publish', [])->assertUnprocessable();
        $published = $this->postJson('/api/v1/assistants/'.$assistant->id.'/publish', ['public_consent' => true])->assertCreated();
        $slug = $published->json('slug');
        $assistant->update(['instructions' => 'Private draft update']);
        $this->getJson('/api/v1/explore/assistants/'.$slug)->assertOk()->assertJsonPath('instructions', 'Explain clearly')->assertJsonMissingPath('attachment_ids')->assertJsonMissingPath('user.email');
        $this->getJson('/api/v1/explore/assistants?q=Tutor')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.instructions');
        $other = $this->user();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/explore/assistants/'.$slug.'/copy', [])->assertCreated()->assertJsonPath('instructions', 'Explain clearly')->assertJsonPath('attachment_ids', [])->assertJsonPath('model_id', null);
        $this->deleteJson('/api/v1/assistants/'.$assistant->id.'/publish')->assertNotFound();
        Sanctum::actingAs($u);
        $this->deleteJson('/api/v1/assistants/'.$assistant->id.'/publish')->assertNoContent();
        $this->getJson('/api/v1/explore/assistants/'.$slug)->assertNotFound();
        $this->assertSame(1, Assistant::where('user_id', $other->id)->count());
    }

    public function test_blocked_or_suspended_publications_are_hidden_and_cannot_be_republished(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $a = Assistant::create(['user_id' => $u->id, 'name' => 'Tutor', 'instructions' => 'Explain clearly']);
        $slug = $this->postJson('/api/v1/assistants/'.$a->id.'/publish', ['public_consent' => true])->assertCreated()->json('slug');
        $p = PublishedAssistant::where('slug', $slug)->firstOrFail();
        $p->update(['blocked' => true]);
        $this->getJson('/api/v1/explore/assistants/'.$slug)->assertNotFound();
        $this->postJson('/api/v1/assistants/'.$a->id.'/publish', ['public_consent' => true])->assertForbidden();
        $this->deleteJson('/api/v1/assistants/'.$a->id.'/publish')->assertNoContent();
        $this->postJson('/api/v1/assistants/'.$a->id.'/publish', ['public_consent' => true])->assertForbidden();
        $p->update(['blocked' => false]);
        $u->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/explore/assistants/'.$slug)->assertNotFound();
    }
}

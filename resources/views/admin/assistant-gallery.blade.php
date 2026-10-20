@extends('admin.layout')
@section('title', 'Public assistants')
@section('section', 'Public assistants')
@section('content')
<div class="admin-page-heading"><div><span class="eyebrow">CONTENT MODERATION</span><h1>Public assistant templates</h1><p>Review public instructions and manage the visibility of published assistants. Suspended users' publications are hidden automatically.</p></div></div>
<div class="entity-list">
    @forelse($publications as $a)
    <article class="admin-card entity-card">
        <div class="entity-card-header"><div><h2>{{ $a->name }}</h2><p class="entity-meta">Published by {{ $a->user->name }}</p></div><div class="badge-set"><span class="status-pill {{ $a->blocked ? 'danger' : 'success' }}">{{ $a->blocked ? 'Blocked' : 'Public' }}</span></div></div>
        <p>{{ $a->description }}</p>
        <details><summary>View published instructions</summary><pre>{{ $a->instructions }}</pre></details>
        <div class="entity-actions"><form method="post" action="/admin/assistants/{{ $a->id }}">@csrf @method('PATCH')<input type="hidden" name="blocked" value="{{ $a->blocked ? 0 : 1 }}"><button class="{{ $a->blocked ? 'btn-primary-admin' : 'secondary' }}" type="submit">{{ $a->blocked ? 'Unblock publication' : 'Block publication' }}</button></form></div>
    </article>
    @empty<div class="empty-block">No public assistant templates to review.</div>@endforelse
</div>
<div class="pagination-wrap">{{ $publications->links() }}</div>
@endsection

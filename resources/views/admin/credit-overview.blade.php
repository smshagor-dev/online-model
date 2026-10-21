<section class="admin-card" style="margin:24px 0" aria-label="API credit analytics">
    @php
        $configuredModels = collect($creditModels)->filter(fn ($m) => $m['limit'] !== null);
        $allocated = (int) $configuredModels->sum('limit');
        $usedUnderAllocations = (int) $configuredModels->sum('used');
    @endphp
    <div class="panel-heading">
        <div><h2>API credits · {{ $creditMonth }}</h2><p>Frontend-connected API request units, by model and user</p></div>
        <a class="view-all" href="/admin/api-credits">Manage allocations →</a>
    </div>
    <div class="credit-overview-top">
        @include('admin.credit-pie', ['used' => $usedUnderAllocations, 'limit' => $allocated ?: null])
        <div class="muted-note"><strong>Request credits, not provider billing balance.</strong><div>Totals and pie charts cover models with a monthly allocation. Connected models without allocation show tracked use but no invented remaining balance.</div></div>
    </div>
    <div class="credit-grid">
        @forelse($creditModels as $m)
        <article class="credit-model-card">
            <h3>{{ $m['model'] }}</h3>
            <p class="entity-meta">{{ ucfirst(str_replace('_', ' ', $m['kind'])) }} · {{ $m['provider'] }} · {{ $m['last_success_at'] ? 'Previously successful' : 'No completed calls' }}</p>
            @include('admin.credit-pie', ['used' => $m['used'], 'limit' => $m['limit']])
        </article>
        @empty<div class="empty-block">No frontend model usage has been reported yet.</div>@endforelse
    </div>
    <div class="section-separator" style="margin-top:25px"><h2>User credit usage</h2><p>Top accounts for {{ $creditMonth }}</p></div>
    <div class="credit-users-grid">
        @foreach(array_slice($creditUsers, 0, 6) as $userCredit)
        <a class="credit-user-card" href="/admin/users/{{ $userCredit['id'] }}">
            <strong>{{ $userCredit['name'] }}</strong>
            @include('admin.credit-pie', ['used' => $userCredit['used'], 'limit' => $userCredit['limit']])
        </a>
        @endforeach
    </div>
</section>

@php
    $creditUsed = max(0, (int) $used);
    $creditLimit = isset($limit) ? (int) $limit : null;
    $creditRemaining = $creditLimit === null ? null : max(0, $creditLimit - $creditUsed);
    $creditPercent = $creditLimit ? min(100, 100 * $creditUsed / $creditLimit) : 0;
@endphp
<div class="credit-meter">
    @if($creditLimit !== null)
        <div class="credit-donut" role="img" aria-label="{{ number_format($creditUsed) }} credits used, {{ number_format($creditRemaining) }} remaining"
             style="--used-percent:{{ $creditPercent }}%">
            <span>{{ round($creditPercent) }}%</span>
        </div>
    @else
        <div class="credit-donut credit-unset" role="img" aria-label="No credit allocation set">
            <span>—</span>
        </div>
    @endif
    <div class="credit-figures">
        <strong>{{ number_format($creditUsed) }} used</strong>
        <span>{{ $creditRemaining === null ? 'Remaining: not configured' : number_format($creditRemaining).' remaining' }}</span>
        <small>{{ $creditLimit === null ? 'No monthly allocation' : number_format($creditLimit).' allocated this month' }}</small>
    </div>
</div>

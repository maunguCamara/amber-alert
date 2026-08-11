@extends('layouts.app')
@section('title', ($case['child_name'] ?? 'Case') . ' — Amber Alert')

@section('content')
<div class="max-w-2xl mx-auto py-8 px-4">

    {{-- Back link --}}
    <a href="{{ route('home') }}" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1 mb-6">
        ← Back to map
    </a>

    {{-- Status badge --}}
    @php
        $status    = $case['status'] ?? 'review';
        $badgeCls  = match($status) {
            'active'   => 'bg-red-100 text-red-700',
            'resolved' => 'bg-green-100 text-green-700',
            default    => 'bg-yellow-100 text-yellow-700',
        };
        $label = match($status) {
            'active'   => '● ACTIVE ALERT',
            'resolved' => '✓ RESOLVED',
            default    => '⏳ UNDER REVIEW',
        };
    @endphp

    <div class="bg-white rounded-xl shadow-sm border overflow-hidden">

        {{-- Photo --}}
        @if(!empty($case['photos']))
            <img src="{{ $case['photos'][0]['url'] }}"
                 alt="Photo of {{ $case['child_name'] }}"
                 class="w-full h-56 object-cover">
        @else
            <div class="w-full h-40 bg-gray-100 flex items-center justify-center text-gray-400 text-4xl">
                👤
            </div>
        @endif

        <div class="p-6">
            {{-- Name and status --}}
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $case['child_name'] }}</h1>
                    <p class="text-gray-500 text-sm mt-0.5">{{ $case['reference_no'] ?? '' }}</p>
                </div>
                <span class="{{ $badgeCls }} text-xs font-bold px-3 py-1 rounded-full shrink-0">
                    {{ $label }}
                </span>
            </div>

            {{-- Details grid --}}
            <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm border-t pt-4">
                <div>
                    <span class="text-gray-500">Age</span>
                    <div class="font-medium text-gray-900">{{ $case['age'] }} years old</div>
                </div>
                <div>
                    <span class="text-gray-500">Gender</span>
                    <div class="font-medium text-gray-900">{{ ucfirst($case['gender'] ?? 'Unknown') }}</div>
                </div>
                <div>
                    <span class="text-gray-500">Last seen</span>
                    <div class="font-medium text-gray-900">{{ $case['last_seen_area'] ?? '—' }}</div>
                </div>
                <div>
                    <span class="text-gray-500">County</span>
                    <div class="font-medium text-gray-900">{{ $case['county'] ?? '—' }}</div>
                </div>
                <div>
                    <span class="text-gray-500">Missing since</span>
                    <div class="font-medium text-gray-900">
                        {{ \Carbon\Carbon::parse($case['missing_since'])->format('D, d M Y, g:i A') }}
                        <span class="text-gray-400 font-normal">
                            ({{ \Carbon\Carbon::parse($case['missing_since'])->diffForHumans() }})
                        </span>
                    </div>
                </div>
                <div>
                    <span class="text-gray-500">Clothing</span>
                    <div class="font-medium text-gray-900">{{ $case['clothing'] ?? '—' }}</div>
                </div>
                @if(!empty($case['distinctive']))
                <div class="col-span-2">
                    <span class="text-gray-500">Distinctive features</span>
                    <div class="font-medium text-gray-900">{{ $case['distinctive'] }}</div>
                </div>
                @endif
                @if($status === 'resolved' && !empty($case['resolution']))
                <div class="col-span-2">
                    <span class="text-gray-500">Resolution</span>
                    <div class="font-medium text-green-700">{{ $case['resolution'] }}</div>
                </div>
                @endif
            </div>

            {{-- Description --}}
            @if(!empty($case['description']))
            <div class="mt-4 pt-4 border-t">
                <p class="text-sm text-gray-500 mb-1">Description</p>
                <p class="text-sm text-gray-800 leading-relaxed">{{ $case['description'] }}</p>
            </div>
            @endif

            {{-- Share / tip actions --}}
            @if($status === 'active')
            <div class="mt-6 pt-4 border-t flex gap-3">
                <button onclick="shareCase()"
                    class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold py-2.5 rounded-lg transition">
                    📢 Share Alert
                </button>
                <a href="tel:999"
                    class="flex-1 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold py-2.5 rounded-lg text-center transition">
                    📞 Call Police (999)
                </a>
            </div>
            <p class="text-xs text-gray-400 mt-2 text-center">
                Have information? SMS <strong>TIP {{ $case['reference_no'] ?? '' }} your message</strong> to 22384
            </p>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function shareCase() {
    const text = `AMBER ALERT: {{ $case['child_name'] }}, age {{ $case['age'] }}, missing from {{ $case['last_seen_area'] ?? $case['county'] }}. If seen call 999. Details: ${window.location.href}`;
    if (navigator.share) {
        navigator.share({ title: 'Amber Alert', text, url: window.location.href });
    } else {
        navigator.clipboard.writeText(text).then(() => alert('Alert text copied to clipboard.'));
    }
}
</script>
@endpush
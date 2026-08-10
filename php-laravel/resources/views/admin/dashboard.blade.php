@extends('layouts.app')
@section('title', 'Officer Dashboard')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    {{-- Stats row --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
            <div class="text-3xl font-bold text-red-600">{{ $stats['active'] ?? 0 }}</div>
            <div class="text-sm text-red-500 mt-1">Active Alerts</div>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
            <div class="text-3xl font-bold text-yellow-600">{{ $stats['review'] ?? 0 }}</div>
            <div class="text-sm text-yellow-500 mt-1">Pending Review</div>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
            <div class="text-3xl font-bold text-green-600">{{ $stats['resolved'] ?? 0 }}</div>
            <div class="text-sm text-green-500 mt-1">Resolved (30d)</div>
        </div>
    </div>

    {{-- Pending approval queue --}}
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">
            ⏳ Pending Review
            <span class="text-sm font-normal text-gray-500 ml-2">— approve or reject before cases go live</span>
        </h2>

        @if(empty($pendingCases))
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center text-gray-500">
                No cases pending review.
            </div>
        @else
            <div class="space-y-3">
                @foreach($pendingCases as $case)
                <div class="bg-white border border-yellow-200 rounded-lg p-4 flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <span class="bg-yellow-100 text-yellow-700 text-xs font-semibold px-2 py-0.5 rounded">REVIEW</span>
                            <span class="font-semibold text-gray-900">{{ $case['child_name'] }}</span>
                            <span class="text-sm text-gray-500">Age {{ $case['age'] }} · {{ ucfirst($case['gender']) }}</span>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            📍 {{ $case['county'] }} · Missing {{ \Carbon\Carbon::parse($case['missing_since'])->diffForHumans() }}
                        </div>
                        <div class="text-xs text-gray-400 mt-1">Ref: {{ $case['reference_no'] ?? 'Pending' }}</div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        {{-- Approve --}}
                        <form method="POST" action="{{ route('dashboard.cases.status', $case['id']) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="active">
                            <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1.5 rounded transition">
                                ✓ Approve
                            </button>
                        </form>
                        {{-- Reject --}}
                        <form method="POST" action="{{ route('dashboard.cases.status', $case['id']) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="closed">
                            <input type="hidden" name="resolution" value="Rejected by officer">
                            <button type="submit"
                                class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm px-3 py-1.5 rounded transition"
                                onclick="return confirm('Reject this case?')">
                                ✗ Reject
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Active cases --}}
    <div>
        <h2 class="text-lg font-semibold text-gray-800 mb-3">🔴 Active Cases</h2>

        @if(empty($activeCases))
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 text-center text-gray-500">
                No active cases.
            </div>
        @else
            <div class="space-y-3">
                @foreach($activeCases as $case)
                <div class="bg-white border border-red-100 rounded-lg p-4 flex items-start justify-between gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <span class="bg-red-100 text-red-700 text-xs font-semibold px-2 py-0.5 rounded animate-pulse">ACTIVE</span>
                            <span class="font-semibold text-gray-900">{{ $case['child_name'] }}</span>
                            <span class="text-sm text-gray-500">Age {{ $case['age'] }}</span>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            📍 {{ $case['county'] }} · Missing {{ \Carbon\Carbon::parse($case['missing_since'])->diffForHumans() }}
                        </div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        {{-- Mark resolved --}}
                        <form method="POST" action="{{ route('dashboard.cases.status', $case['id']) }}"
                              onsubmit="return resolveCase(this)">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="resolved">
                            <input type="hidden" name="resolution" id="resolution-{{ $case['id'] }}" value="">
                            <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1.5 rounded transition">
                                ✓ Mark Resolved
                            </button>
                        </form>
                        {{-- SMS Blast --}}
                        <form method="POST" action="{{ route('dashboard.cases.broadcast', $case['id']) }}">
                            @csrf
                            <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1.5 rounded transition">
                                📱 SMS Blast
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
function resolveCase(form) {
    const note = prompt('How was the child found? (e.g. Found at grandmother\'s home in Kisumu)');
    if (note === null) return false; // user cancelled
    if (!note.trim()) { alert('Please provide a resolution note.'); return false; }
    form.querySelector('input[name="resolution"]').value = note;
    return true;
}
</script>
@endpush
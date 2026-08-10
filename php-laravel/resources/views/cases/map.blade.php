@extends('layouts.app')
@section('title', 'Kenya Amber Alert — Live Map')

@push('head')
<style>
  #map { height: calc(100vh - 96px); }
  .case-card { transition: background 0.15s; }
  .case-card:hover { background: #f9fafb; }
  .pin-active   { color: #dc2626; }
  .pin-review   { color: #d97706; }
  .pin-resolved { color: #16a34a; }
</style>
@endpush

@section('content')
{{-- Stats bar --}}
<div class="flex items-center gap-6 px-4 py-2 bg-white border-b text-sm shadow-sm">
    <span class="font-semibold text-gray-700">Live Cases</span>
    <span class="text-red-600 font-bold">● Active: <span id="stat-active">{{ $stats['active'] ?? 0 }}</span></span>
    <span class="text-yellow-600">● Review: <span id="stat-review">{{ $stats['review'] ?? 0 }}</span></span>
    <span class="text-green-600">● Resolved (30d): <span id="stat-resolved">{{ $stats['resolved'] ?? 0 }}</span></span>
    <a href="{{ route('cases.create') }}"
       class="ml-auto bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-4 py-1.5 rounded transition">
        + Report Missing Child
    </a>
    <span class="text-gray-400 text-xs" id="ws-status">Connecting…</span>
</div>

<div class="flex" style="height: calc(100vh - 96px)">

    {{-- Sidebar: missing persons list --}}
    <div class="w-72 border-r bg-white flex flex-col overflow-hidden shrink-0">
        <div class="px-3 py-2 border-b">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Missing Children</h2>
            <div class="flex gap-1 mt-1.5">
                <button onclick="filterPins('all', this)"
                    class="filter-btn text-xs px-2 py-0.5 rounded-full bg-gray-800 text-white">All</button>
                <button onclick="filterPins('active', this)"
                    class="filter-btn text-xs px-2 py-0.5 rounded-full border border-gray-300 text-gray-600">Active</button>
                <button onclick="filterPins('review', this)"
                    class="filter-btn text-xs px-2 py-0.5 rounded-full border border-gray-300 text-gray-600">Review</button>
            </div>
        </div>
        <div class="overflow-y-auto flex-1" id="case-list">
            <div class="p-4 text-center text-gray-400 text-sm" id="list-loading">Loading cases…</div>
        </div>
    </div>

    {{-- Map --}}
    <div class="flex-1" id="map"></div>
</div>
@endsection

@push('scripts')
<script>
// ── Map setup ────────────────────────────────────────────────────────────────
const map = L.map('map').setView([0.0236, 37.9062], 6);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors', maxZoom: 18,
}).addTo(map);

// ── Icon factory ─────────────────────────────────────────────────────────────
const colours = { active: '#dc2626', review: '#d97706', resolved: '#16a34a', closed: '#9ca3af' };

function makeIcon(status) {
    const c = colours[status] || '#9ca3af';
    return L.divIcon({
        className: '',
        html: `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 28 36">
          <circle cx="14" cy="14" r="12" fill="${c}" stroke="#fff" stroke-width="2"/>
          <polygon points="14,36 7,22 21,22" fill="${c}"/>
        </svg>`,
        iconSize: [28, 36], iconAnchor: [14, 36], popupAnchor: [0, -38],
    });
}

// ── State ────────────────────────────────────────────────────────────────────
const markers = {};
let allCases  = [];
let currentFilter = 'all';

// ── Load cases from Go API ────────────────────────────────────────────────────
const API_URL = '{{ config("amber.api_url", "http://localhost:8080") }}';

fetch(`${API_URL}/api/v1/cases/map?limit=500`)
    .then(r => r.ok ? r.json() : Promise.reject())
    .then(({ data }) => {
        allCases = data || [];
        renderAll();
    })
    .catch(() => {
        document.getElementById('list-loading').textContent = 'Could not load cases. Is the Go API running?';
    });

function renderAll() {
    // Clear existing markers
    Object.values(markers).forEach(m => map.removeLayer(m));
    Object.keys(markers).forEach(k => delete markers[k]);

    const filtered = currentFilter === 'all'
        ? allCases
        : allCases.filter(c => c.status === currentFilter);

    // Render map pins
    filtered.forEach(addPin);

    // Render sidebar list
    const list = document.getElementById('case-list');
    if (filtered.length === 0) {
        list.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">No cases found.</div>';
        return;
    }
    list.innerHTML = filtered.map(c => `
        <div class="case-card px-3 py-2.5 border-b cursor-pointer" onclick="focusCase('${c.id}')">
            <div class="flex items-center gap-2">
                <span style="color:${colours[c.status] || '#9ca3af'}" class="text-lg">●</span>
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-sm text-gray-900 truncate">${c.child_name}</div>
                    <div class="text-xs text-gray-500">Age ${c.age} · ${c.county}</div>
                    <div class="text-xs text-gray-400">${timeSince(c.missing_since)}</div>
                </div>
                ${c.thumbnail_url ? `<img src="${c.thumbnail_url}" class="w-9 h-9 rounded object-cover shrink-0">` : ''}
            </div>
        </div>
    `).join('');
}

function addPin(c) {
    const popup = `
        <div style="min-width:180px">
            ${c.thumbnail_url ? `<img src="${c.thumbnail_url}" style="width:100%;height:80px;object-fit:cover;border-radius:4px;margin-bottom:6px">` : ''}
            <strong style="font-size:14px">${c.child_name}</strong><br>
            <span style="font-size:12px;color:#666">${c.reference_no || ''} · Age ${c.age} · ${c.gender}</span><br>
            <span style="font-size:12px">📍 ${c.county}</span><br>
            <span style="font-size:12px;color:#888">Missing ${timeSince(c.missing_since)}</span><br>
            <a href="/cases/${c.id}" style="display:block;margin-top:6px;background:#dc2626;color:#fff;text-align:center;padding:4px 8px;border-radius:4px;font-size:12px;text-decoration:none">View full case →</a>
        </div>`;

    const m = L.marker([c.lat, c.lng], { icon: makeIcon(c.status) })
        .addTo(map)
        .bindPopup(popup);
    markers[c.id] = m;
}

function focusCase(id) {
    const c = allCases.find(x => x.id === id);
    if (!c || !markers[id]) return;
    map.setView([c.lat, c.lng], 12);
    markers[id].openPopup();
}

function filterPins(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => {
        b.className = 'filter-btn text-xs px-2 py-0.5 rounded-full border border-gray-300 text-gray-600';
    });
    btn.className = 'filter-btn text-xs px-2 py-0.5 rounded-full bg-gray-800 text-white';
    renderAll();
}

// ── WebSocket — live updates ──────────────────────────────────────────────────
const WS_URL = '{{ config("amber.ws_url", "ws://localhost:8080/ws") }}';
const wsStatus = document.getElementById('ws-status');
let ws, reconnect;

function connectWS() {
    ws = new WebSocket(WS_URL);
    ws.onopen  = () => { wsStatus.textContent = '● Live'; wsStatus.className = 'text-xs text-green-600 font-semibold'; clearTimeout(reconnect); };
    ws.onclose = () => { wsStatus.textContent = '○ Reconnecting…'; wsStatus.className = 'text-xs text-yellow-600'; reconnect = setTimeout(connectWS, 4000); };
    ws.onerror = () => ws.close();
    ws.onmessage = ({ data }) => {
        try {
            const event = JSON.parse(data);
            const point = event.payload;
            const existing = allCases.findIndex(c => c.id === point.id);
            if (existing >= 0) allCases[existing] = point; else allCases.unshift(point);
            renderAll();
        } catch(e) {}
    };
}
connectWS();

// ── Helpers ───────────────────────────────────────────────────────────────────
function timeSince(iso) {
    const ms = Date.now() - new Date(iso).getTime();
    const h = Math.floor(ms / 3600000);
    if (h < 24)  return `${h}h ago`;
    const d = Math.floor(h / 24);
    if (d < 30)  return `${d} day${d===1?'':'s'} ago`;
    return `${Math.floor(d/30)} month${Math.floor(d/30)===1?'':'s'} ago`;
}
</script>
@endpush
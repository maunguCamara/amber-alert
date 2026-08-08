@extends('layouts.app')

@section('title', 'Kenya Amber Alert — Live Map')

@section('content')
{{-- Stats bar --}}
<div class="flex items-center gap-6 px-4 py-2 bg-white border-b text-sm shadow-sm">
    <span class="font-semibold text-gray-700">Live Cases</span>
    <span class="text-red-600 font-bold">● Active: <span id="stat-active">{{ $stats['active'] ?? '—' }}</span></span>
    <span class="text-yellow-600">● Review: <span id="stat-review">{{ $stats['review'] ?? '—' }}</span></span>
    <span class="text-green-600">● Resolved (30d): <span id="stat-resolved">{{ $stats['resolved'] ?? '—' }}</span></span>
    <span class="ml-auto text-gray-400 text-xs" id="ws-status">Connecting…</span>
</div>

<div id="map"></div>
@endsection

@push('scripts')
<script>
// ── Leaflet map centred on Kenya ────────────────────────────────────────────
const map = L.map('map', { zoomControl: true }).setView([0.0236, 37.9062], 6);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 18,
}).addTo(map);

// ── Icon factory ─────────────────────────────────────────────────────────────
const colours = { active: '#E24B4A', review: '#EF9F27', resolved: '#639922', closed: '#9CA3AF' };

function makeIcon(status) {
    const c = colours[status] || '#9CA3AF';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 28 36">
      <circle cx="14" cy="14" r="12" fill="${c}" stroke="#fff" stroke-width="2"/>
      <polygon points="14,36 7,22 21,22" fill="${c}"/>
    </svg>`;
    return L.divIcon({
        className: '',
        html: svg,
        iconSize: [28, 36],
        iconAnchor: [14, 36],
        popupAnchor: [0, -36],
    });
}

// ── Markers registry ──────────────────────────────────────────────────────────
const markers = {};

function addOrUpdatePin(point) {
    const popupHtml = buildPopup(point);
    if (markers[point.id]) {
        markers[point.id].setLatLng([point.lat, point.lng])
            .setIcon(makeIcon(point.status))
            .setPopupContent(popupHtml);
    } else {
        const m = L.marker([point.lat, point.lng], { icon: makeIcon(point.status) })
            .addTo(map)
            .bindPopup(popupHtml);
        markers[point.id] = m;
    }
}

function removePin(id) {
    if (markers[id]) {
        map.removeLayer(markers[id]);
        delete markers[id];
    }
}

function buildPopup(p) {
    const thumb = p.thumbnail_url
        ? `<img src="${p.thumbnail_url}" class="w-full h-24 object-cover rounded mb-2">`
        : '';
    const missing = timeSince(p.missing_since);
    return `<div style="min-width:180px">
        ${thumb}
        <p class="font-bold text-base mb-0.5">${p.child_name}</p>
        <p class="text-sm text-gray-500">${p.reference_no} · Age ${p.age} · ${p.gender}</p>
        <p class="text-sm text-gray-600 mt-1">📍 ${p.county}</p>
        <p class="text-sm text-gray-500">Missing ${missing}</p>
        <a href="/cases/${p.id}"
           class="mt-2 block text-center bg-red-600 text-white text-sm rounded px-3 py-1 hover:bg-red-700">
           View full case
        </a>
    </div>`;
}

function timeSince(iso) {
    const ms = Date.now() - new Date(iso).getTime();
    const h  = Math.floor(ms / 3600000);
    if (h < 24)  return `${h}h ago`;
    const d = Math.floor(h / 24);
    if (d < 30)  return `${d}d ago`;
    return `${Math.floor(d/30)}mo ago`;
}

// ── Load initial pins from Go API ─────────────────────────────────────────────
const API = '{{ config("amber.api_url") }}';

fetch(`${API}/api/v1/cases/map?limit=500`)
    .then(r => r.ok ? r.json() : Promise.reject(r))
    .then(({ data }) => (data || []).forEach(addOrUpdatePin))
    .catch(() => console.warn('Failed to load initial case data'));

// ── Live WebSocket updates ────────────────────────────────────────────────────
const WS_URL  = '{{ config("amber.ws_url") }}';
const wsStatus = document.getElementById('ws-status');
let ws, reconnectTimer;

function connectWS() {
    ws = new WebSocket(WS_URL);

    ws.onopen = () => {
        wsStatus.textContent = '● Live';
        wsStatus.className   = 'ml-auto text-xs text-green-600 font-semibold';
        clearTimeout(reconnectTimer);
    };

    ws.onmessage = ({ data }) => {
        try {
            const event = JSON.parse(data);
            switch (event.type) {
                case 'case.new':
                case 'case.updated':
                    addOrUpdatePin(event.payload);
                    break;
                case 'case.resolved':
                    addOrUpdatePin(event.payload); // update icon to resolved colour
                    break;
            }
        } catch(e) {
            console.warn('WS parse error', e);
        }
    };

    ws.onclose = () => {
        wsStatus.textContent = '○ Reconnecting…';
        wsStatus.className   = 'ml-auto text-xs text-yellow-600';
        reconnectTimer = setTimeout(connectWS, 4000);
    };

    ws.onerror = () => ws.close();
}

connectWS();
</script>
@endpush
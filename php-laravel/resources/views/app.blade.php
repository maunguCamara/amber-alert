<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kenya Amber Alert System')</title>

    {{-- Leaflet CSS --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

    {{-- Tailwind (CDN for this template — replace with compiled in production) --}}
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        #map { height: calc(100vh - 56px); }
        .alert-pin { cursor: pointer; }
        .pin-pulse {
            animation: pulse 1.8s ease-in-out infinite;
        }
        @keyframes pulse {
            0%,100% { transform: scale(1);   opacity: 0.6; }
            50%      { transform: scale(1.8); opacity: 0; }
        }
        .leaflet-popup-content { min-width: 200px; }
    </style>

    @stack('head')
</head>
<body class="h-full bg-gray-50 font-sans antialiased">

{{-- Top navigation --}}
<nav class="h-14 bg-red-700 text-white flex items-center px-4 gap-4 shadow-md z-50 relative">
    <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold tracking-wide">
        <span class="bg-white text-red-700 text-xs font-bold px-2 py-0.5 rounded">AMBER</span>
        <span class="hidden sm:inline">Kenya Child Alert System</span>
    </a>

    <div class="ml-auto flex items-center gap-3 text-sm">
        @auth
            <span class="opacity-80">{{ Auth::user()->full_name }}</span>
            <a href="{{ route('cases.create') }}"
               class="bg-white text-red-700 font-semibold px-3 py-1 rounded hover:bg-red-50 transition">
                + {{ __('Report') }}
            </a>
            @if(in_array(Auth::user()->role, ['officer','admin','superadmin']))
                <a href="{{ route('dashboard.index') }}" class="opacity-80 hover:opacity-100">Dashboard</a>
            @endif
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <button class="opacity-70 hover:opacity-100">{{ __('Logout') }}</button>
            </form>
        @else
            <a href="{{ route('login') }}" class="opacity-80 hover:opacity-100">{{ __('Login') }}</a>
            <a href="{{ route('register') }}"
               class="bg-white text-red-700 font-semibold px-3 py-1 rounded hover:bg-red-50 transition">
                {{ __('Register') }}
            </a>
        @endguest
    </div>
</nav>

{{-- Flash messages --}}
@if(session('success'))
    <div class="bg-green-100 border-l-4 border-green-600 text-green-800 px-4 py-3 text-sm" role="alert">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="bg-red-100 border-l-4 border-red-600 text-red-800 px-4 py-3 text-sm" role="alert">
        {{ session('error') }}
    </div>
@endif

@yield('content')

{{-- Leaflet JS --}}
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV/XN/WPeM=" crossorigin=""></script>

@stack('scripts')
</body>
</html>
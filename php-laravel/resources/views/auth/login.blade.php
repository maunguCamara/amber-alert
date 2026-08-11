@extends('layouts.app')
@section('title', 'Login — Kenya Amber Alert')

@section('content')
<div class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
<div class="w-full max-w-md">

    <div class="text-center mb-8">
        <div class="inline-flex items-center gap-2 mb-2">
            <span class="bg-red-700 text-white text-sm font-bold px-3 py-1 rounded">AMBER</span>
            <span class="font-semibold text-gray-800">Kenya Child Alert System</span>
        </div>
        <h1 class="text-xl font-bold text-gray-900">Officer / Admin Login</h1>
        <p class="text-sm text-gray-500 mt-1">
            Public? <a href="{{ route('cases.create') }}" class="text-red-600 hover:underline">Report a missing child here</a> — no login needed.
        </p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">

        @if($errors->any())
        <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 @error('email') border-red-400 @enderror">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-gray-600">
                    <input type="checkbox" name="remember" class="rounded border-gray-300"> Remember me
                </label>
            </div>

            <button type="submit"
                class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 rounded-lg transition text-sm">
                Sign in
            </button>
        </form>
    </div>

    <p class="text-center text-xs text-gray-400 mt-4">
        Kenya National Child Protection Initiative
    </p>
</div>
</div>
@endsection
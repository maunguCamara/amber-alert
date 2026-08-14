<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AmberApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function __construct(private readonly AmberApiClient $api) {}

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email'     => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone'     => ['required', 'string', 'max:20'],
            'password'  => ['required', 'min:8', 'confirmed'],
        ]);

        try {
            $result = $this->api->register([
                'full_name' => $data['full_name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'],
                'password'  => $data['password'],
            ]);
        } catch (\Exception $e) {
            return back()->withInput()
                ->with('error', 'Registration failed: ' . $e->getMessage());
        }

        $user = User::create([
            'full_name'     => $data['full_name'],
            'email'         => $data['email'],
            'phone'         => $data['phone'],
            'password'      => Hash::make($data['password']),
            'role'          => $result['user']['role'] ?? 'public',
            'api_token'     => $result['access_token']  ?? null,
            'refresh_token' => $result['refresh_token'] ?? null,
        ]);

        Auth::login($user);

        return redirect()->route('home')
            ->with('success', 'Welcome! Your account has been created.');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        try {
            $result = $this->api->login(
                $credentials['email'],
                $credentials['password'],
            );
        } catch (\Exception) {
            return back()->withInput()
                ->withErrors(['email' => 'Invalid email or password.']);
        }

        $user = User::where('email', $credentials['email'])->first();

        if ($user === null) {
            $user = User::create([
                'full_name'     => $result['user']['full_name'] ?? $credentials['email'],
                'email'         => $credentials['email'],
                'phone'         => $result['user']['phone'] ?? null,
                'password'      => Hash::make($credentials['password']),
                'role'          => $result['user']['role'] ?? 'public',
                'api_token'     => $result['access_token']  ?? null,
                'refresh_token' => $result['refresh_token'] ?? null,
            ]);
        } else {
            $user->update([
                'api_token'     => $result['access_token']  ?? $user->api_token,
                'refresh_token' => $result['refresh_token'] ?? $user->refresh_token,
                'role'          => $result['user']['role']  ?? $user->role,
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
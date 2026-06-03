<?php

namespace App\Http\Controllers;

use App\Services\AmberApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function __construct(private readonly AmberApiClient $api) {}

    public function showLogin()
    {
        return view('auth.login');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    /**
     * Register: create in Go API, store locally for session, redirect to map.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:120',
            'email'     => 'required|email|max:180',
            'phone'     => 'required|string|max:20',
            'password'  => 'required|min:8|confirmed',
        ]);

        $result = $this->api->register([
            'full_name' => $data['full_name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'],
            'password'  => $data['password'],
        ]);

        if (! $result) {
            return back()->withInput()->with('error', __('messages.register_failed'));
        }

        // Upsert a local shadow user to carry the session and api_token
        $user = User::updateOrCreate(
            ['email' => $data['email']],
            [
                'full_name'     => $data['full_name'],
                'phone'         => $data['phone'],
                'password'      => Hash::make($data['password']),
                'role'          => $result['user']['role'] ?? 'public',
                'api_token'     => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
            ]
        );

        Auth::login($user);

        return redirect()->route('home')->with('success', __('messages.register_success'));
    }

    /**
     * Login: authenticate against Go API, store tokens in session user.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $result = $this->api->login($data['email'], $data['password']);

        if (! $result) {
            return back()->withInput()->withErrors(['email' => __('auth.failed')]);
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return back()->withErrors(['email' => __('auth.failed')]);
        }

        // Refresh stored tokens
        $user->update([
            'api_token'     => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
        ]);

        Auth::login($user, $request->boolean('remember'));

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }
}
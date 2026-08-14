<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckRole
{
    /**
     * Usage in routes: middleware('role:officer,admin,superadmin')
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login')
                ->with('error', 'Please log in to access this page.');
        }

        if (! in_array($user->role, $roles, strict: true)) {
            abort(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
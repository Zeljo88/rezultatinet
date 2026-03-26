<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('app.admin_token');

        // Check session
        if (session('admin_authenticated') === true) {
            return $next($request);
        }

        // Check token in query string
        if ($request->query('token') && $request->query('token') === $token) {
            session(['admin_authenticated' => true]);
            return redirect($request->url());
        }

        // Show login form
        if ($request->isMethod('post') && $request->has('password')) {
            if ($request->input('password') === $token) {
                session(['admin_authenticated' => true]);
                return redirect()->intended('/admin/posts');
            }
            return back()->withErrors(['password' => 'Pogrešna lozinka.']);
        }

        return response()->view('admin.login');
    }
}

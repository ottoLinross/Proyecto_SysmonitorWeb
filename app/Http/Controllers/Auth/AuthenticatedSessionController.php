<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuthenticatedSessionController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('processes.index');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('processes.index');
        }

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Ingresa tu correo electrónico.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.string' => 'Ingresa un correo electrónico válido.',
            'email.max' => 'El correo electrónico es demasiado largo.',
            'password.required' => 'Ingresa tu contraseña.',
            'password.string' => 'Ingresa una contraseña válida.',
        ]);

        $key = 'm6-login:'.hash('sha256', Str::lower(trim($credentials['email'])).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Intenta nuevamente en '.RateLimiter::availableIn($key).' segundos.',
            ])->status(429);
        }

        if (! Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales proporcionadas no son correctas.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->to($this->safeIntended($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function safeIntended(Request $request): string
    {
        $fallback = route('processes.index');
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($intended))) {
            return $fallback;
        }

        $parts = parse_url($intended);
        $origin = parse_url($request->getSchemeAndHttpHost());
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['host']) && (
                ($parts['scheme'] ?? null) !== $origin['scheme']
                || $parts['host'] !== $origin['host']
                || ($parts['port'] ?? null) !== ($origin['port'] ?? null)
            )) || (isset($parts['scheme']) && ! isset($parts['host']))) {
            return $fallback;
        }

        $path = $parts['path'] ?? '';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpExceptionInterface) {
            return $fallback;
        }

        if (in_array($route->getName(), ['login', 'login.store', 'logout'], true)) {
            return $fallback;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}

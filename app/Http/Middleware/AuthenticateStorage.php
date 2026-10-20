<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateStorage
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('storage_access.username');
        $hash = config('storage_access.password_hash');
        $providedUser = $request->getUser();
        $providedPassword = $request->getPassword();

        if (! is_string($username) || $username === ''
            || ! is_string($hash) || $hash === ''
            || ! is_string($providedUser) || ! is_string($providedPassword)
            || ! hash_equals($username, $providedUser)
            || ! password_verify($providedPassword, $hash)) {
            return response('Acceso no autorizado.', 401, [
                'WWW-Authenticate' => 'Basic realm="SysMonitor M5", charset="UTF-8"',
                'Cache-Control' => 'no-store',
            ]);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

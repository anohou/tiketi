<?php

namespace App\Http\Middleware;

use App\Models\CrewMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CrewMiddleware
{
    /**
     * Allow only authenticated crew members to continue.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user instanceof CrewMember) {
            abort(403, 'Cette ressource est réservée aux membres d\'équipage.');
        }

        if ($user->currentAccessToken() && ! $user->tokenCan('crew')) {
            $user->currentAccessToken()->delete();
            abort(403, 'Ce jeton ne possède pas la capacité équipage.');
        }

        if (! $user->active) {
            $user->currentAccessToken()?->delete();
            abort(403, 'Ce compte équipage est désactivé.');
        }

        return $next($request);
    }
}

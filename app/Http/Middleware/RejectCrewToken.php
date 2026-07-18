<?php

namespace App\Http\Middleware;

use App\Models\CrewMember;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectCrewToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $request->user() instanceof CrewMember,
            403,
            'Un jeton équipage ne peut pas utiliser ce canal API.',
        );

        abort_unless($request->user() instanceof User, 403, 'Ce canal API exige un compte interne.');

        if (! $request->user()->active) {
            $request->user()->currentAccessToken()?->delete();
            abort(403, 'Ce compte interne est désactivé.');
        }

        return $next($request);
    }
}

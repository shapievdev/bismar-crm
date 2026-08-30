<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Дальше — только администратор или суперадминистратор.
 *
 * Правом это не выразить: `can:` спрашивает Gate, а Gate::before пропускает
 * администраторов и не умеет сказать «и никого больше». Здесь же речь именно о
 * должности — так распорядился пользователь про структуру компании
 * (2026-08-30): смотрят её все, а рисуют двое.
 *
 * Отказ — 403: человек вошёл и назвал себя, ему просто не по чину.
 */
final class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->accessLevel()->grantsEverything()) {
            abort(Response::HTTP_FORBIDDEN, 'Это может делать только администратор.');
        }

        return $next($request);
    }
}

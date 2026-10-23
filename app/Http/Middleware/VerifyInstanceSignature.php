<?php

namespace App\Http\Middleware;

use App\Dba\Signature;
use App\Models\AuditLog;
use App\Models\Instance;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Signed check-ins pushed by an ERP instance: same HMAC scheme as manager->ERP calls, per-instance secret, nonce replay guard. */
class VerifyInstanceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $instance = Instance::where('slug', (string) $request->route('slug'))->first();
        if (! $instance) {
            return response()->json(['ok' => false, 'error' => 'bad signature'], 401); // don't reveal which slugs exist
        }
        $err = Signature::check((string) $instance->secret, $request->method(), '/'.$request->path(),
            $request->header('X-Dba-Timestamp'), $request->header('X-Dba-Nonce'), $request->header('X-Dba-Signature'),
            $request->getContent(), null, (int) config('dba.window', 300));
        if ($err === null) {
            DB::table('dba_nonces')->where('created_at', '<', now()->subSeconds(2 * (int) config('dba.window', 300)))->delete();
            try {
                DB::table('dba_nonces')->insert(['nonce' => $instance->id.':'.$request->header('X-Dba-Nonce'), 'created_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                $err = 'replayed request';
            }
        }
        if ($err !== null) {
            AuditLog::record('check_in_rejected', $instance, $err, ok: false, user: 'instance');

            return response()->json(['ok' => false, 'error' => $err], 401);
        }
        $request->attributes->set('instance', $instance);

        return $next($request);
    }
}

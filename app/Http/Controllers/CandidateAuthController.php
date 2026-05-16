<?php

namespace App\Http\Controllers;

use App\Http\Requests\CandidateLoginRequest;
use App\Http\Requests\CandidateResumeRequest;
use App\Services\CandidateLoginService;

class CandidateAuthController extends Controller
{
    public function login(CandidateLoginRequest $request, CandidateLoginService $service)
    {
        $result = $service->login($request->validated(), $request);
        $attempt = $result['attempt'];

        return response()->json([
            'attempt_id' => $attempt->id,
            'attempt_uuid' => $attempt->uuid,
            'session_key' => $result['session_key'],
            'expires_at' => $result['expires_at'],
            'server_time' => $result['server_time'],
        ]);
    }

    public function resume(CandidateResumeRequest $request, CandidateLoginService $service)
    {
        $result = $service->resume($request->validated(), $request);
        $attempt = $result['attempt'];

        return response()->json([
            'attempt_id' => $attempt->id,
            'attempt_uuid' => $attempt->uuid,
            'session_key' => $result['session_key'],
            'expires_at' => $result['expires_at'],
            'server_time' => $result['server_time'],
        ]);
    }
}

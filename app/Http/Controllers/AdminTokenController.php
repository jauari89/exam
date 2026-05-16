<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateTokenRequest;
use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\ExamSession;
use App\Services\AuditLogService;
use App\Services\CandidateTokenService;

class AdminTokenController extends Controller
{
    public function generate(GenerateTokenRequest $request, CandidateTokenService $tokens, AuditLogService $audit)
    {
        $this->authorize('create', CandidateExamToken::class);
        $session = ExamSession::query()->findOrFail($request->integer('exam_session_id'));
        $candidates = Candidate::query()
            ->when($request->input('candidate_ids'), fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->when($request->integer('candidate_group_id'), fn ($query, int $groupId) => $query->where('candidate_group_id', $groupId))
            ->when($request->boolean('all_candidates'), fn ($query) => $query)
            ->orderBy('candidate_number')
            ->get();
        $expiresAt = $request->date('expires_at');

        $issued = $candidates->map(fn (Candidate $candidate) => $tokens->issue($candidate, $session, 'initial', null, $request->user(), $expiresAt))
            ->map(fn (array $item) => [
                'candidate_id' => $item['token']->candidate_id,
                'candidate_number' => $item['token']->candidate->candidate_number,
                'candidate_name' => $item['token']->candidate->name,
                'exam_session_id' => $item['token']->exam_session_id,
                'token_id' => $item['token']->id,
                'plain_token' => $item['plain_token'],
                'expires_at' => $item['token']->expires_at?->toIso8601String(),
            ]);

        $audit->record('token.generate', $request, $request->user(), auditable: $session, metadata: ['candidate_count' => $issued->count()]);

        return response()->json(['tokens' => $issued]);
    }
}

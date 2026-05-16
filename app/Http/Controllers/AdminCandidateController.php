<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportCandidatesRequest;
use App\Http\Requests\StoreCandidateRequest;
use App\Models\Candidate;
use App\Services\AuditLogService;
use App\Services\CandidateImportService;
use Illuminate\Support\Facades\DB;

class AdminCandidateController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Candidate::class);

        return Candidate::query()
            ->with('group')
            ->orderBy('id')
            ->paginate(200);
    }

    public function store(StoreCandidateRequest $request, AuditLogService $audit)
    {
        $this->authorize('create', Candidate::class);
        $payload = $request->validated();

        $created = DB::transaction(function () use ($payload) {
            if (isset($payload['candidates'])) {
                return collect($payload['candidates'])->map(fn (array $candidate) => Candidate::query()->updateOrCreate(
                    ['candidate_number' => $candidate['candidate_number']],
                    $candidate + ['normalized_name' => Candidate::normalizeName($candidate['name'])],
                ));
            }

            return Candidate::query()->updateOrCreate(
                ['candidate_number' => $payload['candidate_number']],
                $payload + ['normalized_name' => Candidate::normalizeName($payload['name'])],
            );
        });

        $audit->record('candidate.upsert', $request, $request->user(), auditable: is_object($created) && method_exists($created, 'getKey') ? $created : null);

        return response()->json($created, 201);
    }

    public function import(ImportCandidatesRequest $request, CandidateImportService $importer, AuditLogService $audit)
    {
        $this->authorize('create', Candidate::class);

        $result = $importer->import($request->file('file'), $request->integer('candidate_group_id') ?: null);
        $audit->record('candidate.import', $request, $request->user(), metadata: [
            'imported' => $result['imported'],
            'file_name' => $request->file('file')?->getClientOriginalName(),
        ]);

        return response()->json($result, 201);
    }
}

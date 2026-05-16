<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuildPackageFromBankRequest;
use App\Http\Requests\ImportQuestionBankFileRequest;
use App\Http\Requests\ImportQuestionBankRequest;
use App\Http\Requests\StoreQuestionBankItemRequest;
use App\Http\Requests\StoreQuestionBankRequest;
use App\Models\ExamPaper;
use App\Models\QuestionBank;
use App\Models\QuestionBankItem;
use App\Services\AuditLogService;
use App\Services\QuestionBankFileImportService;
use App\Services\QuestionBankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AdminQuestionBankController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', QuestionBank::class);

        return QuestionBank::query()
            ->withCount('items')
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $query->where(fn ($inner) => $inner
                    ->where('code', 'like', "%$search%")
                    ->orWhere('title', 'like', "%$search%")
                    ->orWhere('subject', 'like', "%$search%"));
            })
            ->latest()
            ->paginate(25);
    }

    public function store(StoreQuestionBankRequest $request, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('create', QuestionBank::class);

        $bank = $banks->createBank($request->validated(), $request->user());
        $audit->record('question_bank.create', $request, $request->user(), auditable: $bank, after: $bank->toArray());

        return response()->json($bank->loadCount('items'), 201);
    }

    public function show(QuestionBank $questionBank)
    {
        $this->authorize('view', $questionBank);

        return $questionBank->load('items.options', 'items.rubrics')->loadCount('items');
    }

    public function update(StoreQuestionBankRequest $request, QuestionBank $questionBank, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBank);

        $before = $questionBank->toArray();
        $bank = $banks->updateBank($questionBank, $request->validated());
        $audit->record('question_bank.update', $request, $request->user(), auditable: $bank, before: $before, after: $bank->toArray());

        return response()->json($bank->loadCount('items'));
    }

    public function storeQuestion(StoreQuestionBankItemRequest $request, QuestionBank $questionBank, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBank);

        $item = $banks->upsertItem($questionBank, $request->validated());
        $audit->record('question_bank.question.upsert', $request, $request->user(), auditable: $item, after: $item->toArray());

        return response()->json($item, $item->wasRecentlyCreated ? 201 : 200);
    }

    public function updateQuestion(StoreQuestionBankItemRequest $request, QuestionBankItem $questionBankItem, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBankItem->bank);

        $before = $questionBankItem->load('options', 'rubrics')->toArray();
        $item = $banks->updateItem($questionBankItem, $request->validated());
        $audit->record('question_bank.question.update', $request, $request->user(), auditable: $item, before: $before, after: $item->toArray());

        return response()->json($item);
    }

    public function destroyQuestion(Request $request, QuestionBankItem $questionBankItem, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBankItem->bank);

        $before = $questionBankItem->load('options', 'rubrics')->toArray();
        $banks->deleteItem($questionBankItem);
        $audit->record('question_bank.question.delete', $request, $request->user(), before: $before);

        return response()->noContent();
    }

    public function import(ImportQuestionBankRequest $request, QuestionBank $questionBank, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBank);

        $result = $banks->import($questionBank, $request->validated());
        $audit->record('question_bank.import', $request, $request->user(), auditable: $questionBank, after: $result);

        return response()->json($result);
    }

    public function importFile(ImportQuestionBankFileRequest $request, QuestionBank $questionBank, QuestionBankFileImportService $files, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBank);

        $payload = $files->toImportPayload($request->file('file'), $request->input('mode', 'upsert'));
        $result = $banks->import($questionBank, $payload);
        $audit->record('question_bank.import_file', $request, $request->user(), auditable: $questionBank, after: $result, metadata: [
            'file_name' => $request->file('file')?->getClientOriginalName(),
        ]);

        return response()->json($result, 201);
    }

    public function uploadMedia(Request $request, QuestionBank $questionBank, AuditLogService $audit)
    {
        $this->authorize('update', $questionBank);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $directory = 'question-bank-media/'.Str::slug($questionBank->code ?: 'bank-'.$questionBank->id);
        $fileName = Str::uuid()->toString().'.'.$extension;

        File::ensureDirectoryExists(public_path($directory));
        $file->move(public_path($directory), $fileName);

        $result = [
            'url' => '/'.$directory.'/'.$fileName,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => File::mimeType(public_path($directory.'/'.$fileName)),
            'size' => File::size(public_path($directory.'/'.$fileName)),
        ];

        $audit->record('question_bank.media_upload', $request, $request->user(), auditable: $questionBank, after: $result);

        return response()->json($result, 201);
    }

    public function buildPackage(BuildPackageFromBankRequest $request, QuestionBank $questionBank, QuestionBankService $banks, AuditLogService $audit)
    {
        $this->authorize('update', $questionBank);

        $paper = ExamPaper::query()->findOrFail($request->integer('exam_paper_id'));
        $package = $banks->buildPackage($questionBank, $paper, $request->validated(), $request->user());
        $audit->record('question_bank.package_build', $request, $request->user(), auditable: $package, after: [
            'question_bank_id' => $questionBank->id,
            'exam_paper_id' => $paper->id,
            'package_id' => $package->id,
            'checksum' => $package->checksum,
        ]);

        return response()->json($package->load('questions.options', 'questions.rubrics'), 201);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\CandidateGroup;
use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Models\QuestionBank;
use App\Services\CandidateTokenService;
use App\Services\QuestionBankService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportQuestionJsonBank extends Command
{
    protected $signature = 'exam:import-question-json-bank
        {questions : Path to questions.json}
        {media : Path to media.json}
        {answers : Path to keyanswer.json}
        {--bank= : Question bank code, defaults to ExamName}
        {--replace : Replace existing items in the bank}
        {--build-package : Also create/update exam, paper, session, and a published shuffled package}
        {--token=ASTROTO1-TOKEN : Demo candidate one-time token when building package}
        {--candidate=Astro Sample Student : Demo candidate name when building package}
        {--candidate-number=ASTRO-STU-001 : Demo candidate number when building package}';

    protected $description = 'Import paired questions/media/key-answer JSON files into a question bank with correct answers.';

    public function handle(QuestionBankService $banks, CandidateTokenService $tokens): int
    {
        $questionsSource = $this->readJson((string) $this->argument('questions'));
        $mediaSource = $this->readJson((string) $this->argument('media'));
        $answerSource = $this->readJson((string) $this->argument('answers'));

        $examName = (string) ($questionsSource['ExamName'] ?? $answerSource['ExamName'] ?? 'IMPORTED-EXAM');
        $bankCode = (string) ($this->option('bank') ?: Str::upper(Str::slug($examName, '')));
        $duration = (int) ($questionsSource['Duration'] ?? 90);
        $answers = collect($answerSource['Answers'] ?? [])->keyBy('IDQuestion');
        $media = collect($mediaSource['Media'] ?? [])->keyBy('IDQuestion');

        $missingAnswers = collect($questionsSource['Questions'] ?? [])
            ->pluck('IDQuestion')
            ->reject(fn (string $id) => $answers->has($id))
            ->values();

        if ($missingAnswers->isNotEmpty()) {
            $this->error('Missing key answers for: '.$missingAnswers->implode(', '));

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($questionsSource, $answers, $media, $bankCode, $examName, $duration, $banks, $tokens): array {
            $bank = QuestionBank::query()->updateOrCreate(
                ['code' => $bankCode],
                [
                    'title' => $examName.' Question Bank',
                    'subject' => 'Astronomy',
                    'level' => 'Tryout',
                    'status' => 'active',
                    'metadata' => [
                        'source' => 'questions/media/keyanswer JSON',
                        'exam_name' => $examName,
                        'topics' => $questionsSource['Topics'] ?? [],
                    ],
                ],
            );

            $payload = [
                'mode' => $this->option('replace') ? 'replace' : 'upsert',
                'questions' => collect($questionsSource['Questions'])->values()->map(
                    fn (array $question, int $index): array => $this->toQuestionPayload($question, $answers->get($question['IDQuestion']), $media->get($question['IDQuestion']), $index),
                )->all(),
            ];

            $import = $banks->import($bank, $payload);
            $package = null;
            $session = null;
            $candidate = null;

            if ($this->option('build-package')) {
                $build = $this->buildExamPackage($bank, $examName, $duration, $banks, $tokens);
                $package = $build['package'];
                $session = $build['session'];
                $candidate = $build['candidate'];
            }

            return compact('bank', 'import', 'package', 'session', 'candidate');
        });

        $this->info('Question JSON bank imported.');
        $this->table(
            ['Item', 'Value'],
            [
                ['Bank', $result['bank']->code.' - '.$result['bank']->title],
                ['Questions in bank', $result['import']['total_items']],
                ['Created/updated', $result['import']['created'].' / '.$result['import']['updated']],
                ['Package', $result['package'] ? $result['package']->id.' / v'.$result['package']->version : '-'],
                ['Session ID', $result['session']?->id ?? '-'],
                ['Candidate', $result['candidate'] ? $result['candidate']->name : '-'],
                ['Candidate token', $result['session'] ? (string) $this->option('token') : '-'],
            ],
        );

        return self::SUCCESS;
    }

    private function readJson(string $path): array
    {
        $resolved = $this->resolvePath($path);

        if (! is_file($resolved)) {
            $this->fail("JSON file not found: $resolved");
        }

        return json_decode((string) file_get_contents($resolved), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolvePath(string $path): string
    {
        return str($path)->startsWith(['/', '\\']) || preg_match('/^[A-Za-z]:\\\\/', $path)
            ? $path
            : base_path($path);
    }

    private function toQuestionPayload(array $question, array $answer, ?array $media, int $index): array
    {
        $type = match ($question['TypeOfQuestion']) {
            'MCQ' => 'objective',
            'MultipleCheckbox' => 'checkbox',
            'TrueFalse' => 'objective',
            default => 'structured',
        };
        $points = (float) ($answer['Points'] ?? 1);
        $stem = [
            'text' => $this->stemText((string) $question['Question'], $media),
        ];

        if ($media) {
            $stem['image'] = $this->makePlaceholderMedia($answer['IDQuestion'], $media);
            $stem['caption'] = $media['Caption'] ?? null;
        }

        return [
            'external_id' => (string) $question['IDQuestion'],
            'type' => $type,
            'difficulty' => $this->difficultyFor($index, $type, $points),
            'position' => $index + 1,
            'topic' => $question['Topic'] ?? $answer['Topic'] ?? null,
            'max_marks' => $points,
            'stem' => $stem,
            'correct_answer' => $this->correctAnswer($type, $answer['KeyAnswer']),
            'validation_rules' => [],
            'feedback' => ['text' => $answer['Explanation'] ?? null],
            'media' => $media ? [
                'type' => $media['Type'] ?? null,
                'file_name' => $media['FileName'] ?? null,
                'file_id' => $media['FileID'] ?? null,
                'caption' => $media['Caption'] ?? null,
            ] : null,
            'metadata' => [
                'source_type' => $question['TypeOfQuestion'],
                'raw_key_answer' => $answer['KeyAnswer'],
                'source_exam_name' => $answer['ExamName'] ?? null,
            ],
            'options' => $this->answerOptions($question, $answer, $points),
        ];
    }

    private function stemText(string $question, ?array $media): string
    {
        if (! $media) {
            return $question;
        }

        $caption = trim((string) ($media['Caption'] ?? ''));
        $fileName = trim((string) ($media['FileName'] ?? ''));

        return trim($question."\n\nMedia: ".$caption.($fileName ? " ($fileName)" : ''));
    }

    private function answerOptions(array $question, array $answer, float $points): array
    {
        if ($question['TypeOfQuestion'] === 'TrueFalse') {
            return collect(['True', 'False'])->map(fn (string $value): array => [
                'external_id' => Str::upper($value),
                'content' => ['text' => $value],
                'is_correct' => Str::lower((string) $answer['KeyAnswer']) === Str::lower($value),
                'marks' => Str::lower((string) $answer['KeyAnswer']) === Str::lower($value) ? $points : 0,
            ])->all();
        }

        $correct = collect(is_array($answer['KeyAnswer']) ? $answer['KeyAnswer'] : [$answer['KeyAnswer']])
            ->map(fn ($letter) => Str::upper((string) $letter))
            ->values();
        $correctCount = max(1, $correct->count());

        return collect($question['OptionAnswer'] ?? [])->values()->map(function (string $text, int $index) use ($correct, $correctCount, $points, $question): array {
            $letter = chr(65 + $index);
            $isCorrect = $correct->contains($letter);

            return [
                'external_id' => $letter,
                'content' => ['text' => $text],
                'is_correct' => $isCorrect,
                'marks' => $isCorrect ? ($question['TypeOfQuestion'] === 'MultipleCheckbox' ? round($points / $correctCount, 2) : $points) : 0,
            ];
        })->all();
    }

    private function correctAnswer(string $type, mixed $key): array
    {
        if ($key === 'True' || $key === 'False') {
            return ['option_ids' => Str::upper((string) $key)];
        }

        if ($type === 'checkbox') {
            return ['option_ids' => array_values((array) $key)];
        }

        return ['option_ids' => is_array($key) ? ($key[0] ?? null) : $key];
    }

    private function difficultyFor(int $index, string $type, float $points): string
    {
        if ($type === 'checkbox' || $points >= 2) {
            return $index >= 20 ? 'hard' : 'medium';
        }

        return match (true) {
            $index < 10 => 'easy',
            $index < 20 => 'medium',
            default => 'hard',
        };
    }

    private function makePlaceholderMedia(string $questionId, array $media): string
    {
        $examName = 'AstroTO1';
        $directory = public_path("imported-media/$examName");

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $baseName = pathinfo((string) ($media['FileName'] ?? $questionId), PATHINFO_FILENAME) ?: $questionId;
        $fileName = Str::slug($baseName).'.svg';
        $path = "$directory/$fileName";
        $caption = htmlspecialchars((string) ($media['Caption'] ?? $media['FileName'] ?? $questionId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = htmlspecialchars(Str::headline((string) ($media['Type'] ?? 'media')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        file_put_contents($path, <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="960" height="540" viewBox="0 0 960 540" role="img" aria-label="$caption">
  <rect width="960" height="540" fill="#f8fbfa"/>
  <rect x="48" y="48" width="864" height="444" rx="18" fill="#ffffff" stroke="#106b5a" stroke-width="4"/>
  <text x="80" y="120" font-family="Arial, sans-serif" font-size="34" font-weight="700" fill="#10221f">$questionId media placeholder</text>
  <text x="80" y="170" font-family="Arial, sans-serif" font-size="26" fill="#106b5a">$type reference</text>
  <foreignObject x="80" y="215" width="800" height="210">
    <div xmlns="http://www.w3.org/1999/xhtml" style="font-family:Arial,sans-serif;font-size:28px;line-height:1.35;color:#344640;">$caption</div>
  </foreignObject>
  <text x="80" y="455" font-family="Arial, sans-serif" font-size="18" fill="#64746f">Replace this SVG with the original media file when available.</text>
</svg>
SVG);

        return "/imported-media/$examName/$fileName";
    }

    private function buildExamPackage(QuestionBank $bank, string $examName, int $duration, QuestionBankService $banks, CandidateTokenService $tokens): array
    {
        $series = ExamSeries::query()->updateOrCreate(
            ['code' => 'IMPORTED-QUESTION-BANKS'],
            [
                'title' => 'Imported Question Banks',
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addYear(),
                'metadata' => ['source' => 'exam:import-question-json-bank'],
            ],
        );

        $exam = Exam::query()->updateOrCreate(
            ['exam_series_id' => $series->id, 'code' => Str::upper(Str::slug($examName, ''))],
            [
                'title' => $examName,
                'type' => 'mixed',
                'mode' => 'tryout',
                'status' => 'published',
                'default_duration_minutes' => $duration,
                'randomize_questions' => true,
                'reveal_feedback' => true,
                'metadata' => ['question_bank_code' => $bank->code],
            ],
        );

        $paper = ExamPaper::query()->updateOrCreate(
            ['exam_id' => $exam->id, 'code' => 'PAPER-1', 'version' => 1],
            [
                'title' => $examName.' Paper 1',
                'status' => 'published',
                'duration_minutes' => $duration,
                'total_marks' => $bank->items()->sum('max_marks'),
                'instructions' => 'Answer all questions. Objective, checkbox, and true/false items are scored automatically.',
                'content' => ['question_bank_code' => $bank->code],
            ],
        );

        $package = $banks->buildPackage($bank, $paper, [
            'question_count' => $bank->items()->count(),
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'strict_mode' => false,
            'duration_minutes' => $duration,
            'metadata' => ['source_exam_name' => $examName],
        ]);

        $session = ExamSession::query()->updateOrCreate(
            ['exam_id' => $exam->id, 'name' => 'Imported Demo Session - '.$examName],
            [
                'exam_paper_id' => $paper->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDays(14),
                'duration_minutes' => $duration,
                'mode' => 'tryout',
                'status' => 'active',
                'timezone' => config('app.timezone', 'UTC'),
                'settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'show_results' => true],
            ],
        );

        $group = CandidateGroup::query()->updateOrCreate(
            ['name' => 'Imported Demo Candidates'],
            [
                'exam_series_id' => $series->id,
                'metadata' => ['source' => 'exam:import-question-json-bank'],
            ],
        );

        $candidateName = (string) $this->option('candidate');
        $candidate = Candidate::query()->updateOrCreate(
            ['candidate_number' => (string) $this->option('candidate-number')],
            [
                'candidate_group_id' => $group->id,
                'name' => $candidateName,
                'normalized_name' => Candidate::normalizeName($candidateName),
                'metadata' => ['source' => 'exam:import-question-json-bank'],
            ],
        );

        $plainToken = (string) $this->option('token');
        $normalized = $tokens->normalizeToken($plainToken);
        CandidateExamToken::query()->updateOrCreate(
            ['token_lookup_hash' => $tokens->lookupHash($normalized)],
            [
                'candidate_id' => $candidate->id,
                'exam_session_id' => $session->id,
                'purpose' => 'initial',
                'token_hash' => Hash::make($normalized),
                'token_suffix' => substr($normalized, -4),
                'expires_at' => $session->ends_at->copy()->addDay(),
                'used_at' => null,
                'revoked_at' => null,
                'metadata' => ['source' => 'exam:import-question-json-bank'],
            ],
        );

        return compact('package', 'session', 'candidate');
    }
}

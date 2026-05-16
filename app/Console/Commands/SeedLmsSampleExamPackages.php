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

class SeedLmsSampleExamPackages extends Command
{
    protected $signature = 'exam:seed-lms-samples {--duration=70 : Duration in minutes for every sample session}';

    protected $description = 'Seed five LMS-style online test samples with question banks, shuffled packages, demo sessions, candidates, and hashed tokens.';

    public function handle(QuestionBankService $banks, CandidateTokenService $tokens): int
    {
        $duration = (int) $this->option('duration');

        $result = DB::transaction(function () use ($banks, $tokens, $duration): array {
            $series = ExamSeries::query()->updateOrCreate(
                ['code' => 'LMS-SAMPLES'],
                [
                    'title' => 'LMS Online Test Sample Suite',
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addYear(),
                    'metadata' => [
                        'source' => 'Cambridge workbook gap-fill seed',
                        'notes' => 'Workbook has student/progress/score data, while this seed adds reusable question authoring data.',
                    ],
                ],
            );

            $group = CandidateGroup::query()->updateOrCreate(
                ['name' => 'LMS Sample Candidates'],
                [
                    'exam_series_id' => $series->id,
                    'metadata' => ['source' => 'exam:seed-lms-samples'],
                ],
            );

            $rows = [];

            foreach ($this->samples() as $sample) {
                $bank = QuestionBank::query()->updateOrCreate(
                    ['code' => $sample['bank_code']],
                    [
                        'title' => $sample['title'].' Question Bank',
                        'subject' => $sample['subject'],
                        'level' => $sample['level'],
                        'status' => 'active',
                        'metadata' => ['sample_no' => $sample['no'], 'source' => 'exam:seed-lms-samples'],
                    ],
                );

                $banks->import($bank, [
                    'mode' => 'replace',
                    'questions' => $sample['questions'],
                ]);

                $exam = Exam::query()->updateOrCreate(
                    ['exam_series_id' => $series->id, 'code' => $sample['exam_code']],
                    [
                        'title' => $sample['title'],
                        'type' => 'mixed',
                        'mode' => $sample['mode'],
                        'status' => 'published',
                        'default_duration_minutes' => $duration,
                        'randomize_questions' => true,
                        'reveal_feedback' => $sample['mode'] === 'tryout',
                        'metadata' => [
                            'question_bank_code' => $bank->code,
                            'sample_no' => $sample['no'],
                        ],
                    ],
                );

                $paper = ExamPaper::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'code' => 'PAPER-1', 'version' => 1],
                    [
                        'title' => $sample['title'].' Paper 1',
                        'status' => 'published',
                        'duration_minutes' => $duration,
                        'total_marks' => collect($sample['questions'])->sum(fn (array $question) => (float) ($question['max_marks'] ?? 1)),
                        'instructions' => 'Answer all questions. Objective answers are scored automatically; essay and structured answers require manual marking.',
                        'content' => ['question_bank_code' => $bank->code],
                    ],
                );

                $package = $banks->buildPackage($bank, $paper, [
                    'question_count' => 10,
                    'difficulty_mix' => ['easy' => 4, 'medium' => 4, 'hard' => 2],
                    'shuffle_questions' => true,
                    'shuffle_options' => true,
                    'strict_mode' => $sample['mode'] === 'strict',
                    'duration_minutes' => $duration,
                    'metadata' => ['sample_no' => $sample['no']],
                ]);

                $session = ExamSession::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'name' => 'Demo Session - '.$sample['exam_code']],
                    [
                        'exam_paper_id' => $paper->id,
                        'starts_at' => now()->subHour(),
                        'ends_at' => now()->addDays(14),
                        'duration_minutes' => $duration,
                        'mode' => $sample['mode'],
                        'status' => 'active',
                        'timezone' => config('app.timezone', 'UTC'),
                        'settings' => [
                            'show_results' => $sample['mode'] === 'tryout',
                            'shuffle_questions' => true,
                            'shuffle_options' => true,
                        ],
                    ],
                );

                $candidate = Candidate::query()->updateOrCreate(
                    ['candidate_number' => sprintf('LMS-S%02d-STU01', $sample['no'])],
                    [
                        'candidate_group_id' => $group->id,
                        'name' => 'Sample Student '.$sample['no'],
                        'normalized_name' => Candidate::normalizeName('Sample Student '.$sample['no']),
                        'metadata' => ['source' => 'exam:seed-lms-samples', 'sample_no' => $sample['no']],
                    ],
                );

                $plainToken = sprintf('LMS-S%02d-TOKEN', $sample['no']);
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
                        'metadata' => ['source' => 'exam:seed-lms-samples'],
                    ],
                );

                $rows[] = [
                    $sample['no'],
                    $bank->code,
                    $exam->code,
                    $paper->id,
                    $package->id.' / v'.$package->version,
                    $session->id,
                    $candidate->name,
                    $plainToken,
                ];
            }

            return $rows;
        });

        $this->info('LMS sample question banks and shuffled packages seeded.');
        $this->table(
            ['No', 'Bank', 'Exam', 'Paper ID', 'Package', 'Session ID', 'Candidate', 'One-time token'],
            $result,
        );

        return self::SUCCESS;
    }

    private function samples(): array
    {
        return [
            [
                'no' => 1,
                'bank_code' => 'LMS-SAMPLE-BANK-01',
                'exam_code' => 'LMS-SAMPLE-01',
                'title' => 'Measurement Fundamentals',
                'subject' => 'Physics',
                'level' => 'Cambridge IGCSE',
                'mode' => 'tryout',
                'questions' => [
                    $this->objective('S1-Q01', 'easy', 'Measurements', 'Which instrument is most suitable for measuring the diameter of a thin wire?', ['Ruler', 'Stopwatch', 'Micrometer screw gauge', 'Measuring cylinder'], 'C'),
                    $this->objective('S1-Q02', 'easy', 'Units operation', 'What is the SI unit of force?', ['joule', 'newton', 'watt', 'pascal'], 'B'),
                    $this->checkbox('S1-Q03', 'medium', 'Physical quantities', 'Select all scalar quantities.', ['speed' => true, 'velocity' => false, 'mass' => true, 'acceleration' => false], 2),
                    $this->numerical('S1-Q04', 'medium', 'Significant figures calculation in measurements', 'A length is recorded as 12.4 cm. Convert this value to metres.', 0.124, 0.001),
                    $this->objective('S1-Q05', 'easy', 'Systematic and random error', 'A zero error on a balance affects every reading by the same amount. This is mainly a...', ['random error', 'systematic error', 'parallax error', 'reaction-time error'], 'B'),
                    $this->objective('S1-Q06', 'medium', 'Dimensional Analysis', 'Which expression has the same dimensions as acceleration?', ['distance / time', 'velocity / time', 'force x distance', 'mass / volume'], 'B'),
                    $this->structured('S1-Q07', 'hard', 'Uncertainty calculation in measurements', 'A student measures a length as 20.0 cm using a ruler with +/-0.1 cm precision. Calculate the percentage uncertainty and explain one way to reduce it.', 4),
                    $this->objective('S1-Q08', 'easy', 'Measurements', 'Which reading is given to three significant figures?', ['0.0300 m', '3.0 m', '3000 m', '0.03 m'], 'A'),
                    $this->checkbox('S1-Q09', 'medium', 'Units operation', 'Which derived units are equivalent to kg m s^-2?', ['N' => true, 'J' => false, 'Pa m^2' => true, 'W s^-1' => false], 2),
                    $this->essay('S1-Q10', 'hard', 'Systematic and random error', 'Explain the difference between random error and systematic error using one laboratory example for each.', 6),
                ],
            ],
            [
                'no' => 2,
                'bank_code' => 'LMS-SAMPLE-BANK-02',
                'exam_code' => 'LMS-SAMPLE-02',
                'title' => 'Forces and Diagrams',
                'subject' => 'Physics',
                'level' => 'Cambridge IGCSE',
                'mode' => 'tryout',
                'questions' => [
                    $this->imageObjective('S2-Q01', 'easy', 'Forces', 'In the diagram, which arrow represents friction?', '/sample-assets/force-diagram.svg', ['N', 'W', 'Friction', 'Pull'], 'C'),
                    $this->objective('S2-Q02', 'easy', 'Forces', 'Balanced forces on an object mean the object...', ['must be stationary', 'has zero resultant force', 'must be slowing down', 'has no weight'], 'B'),
                    $this->numerical('S2-Q03', 'medium', 'Newton laws', 'A 2.0 kg mass accelerates at 3.0 m/s^2. Calculate the resultant force in newtons.', 6, 0.01),
                    $this->checkbox('S2-Q04', 'medium', 'Forces', 'Select contact forces.', ['friction' => true, 'air resistance' => true, 'weight' => false, 'normal reaction' => true], 3),
                    $this->objective('S2-Q05', 'easy', 'Moments', 'The moment of a force is calculated using...', ['force x perpendicular distance', 'mass x velocity', 'power x time', 'density x volume'], 'A'),
                    $this->structured('S2-Q06', 'hard', 'Free body diagram', 'Describe how you would draw a free-body diagram for a book resting on a table, including the direction of each force.', 4),
                    $this->objective('S2-Q07', 'medium', 'Pressure', 'Pressure increases when...', ['force decreases and area increases', 'force increases and area decreases', 'mass decreases and volume increases', 'density decreases'], 'B'),
                    $this->numerical('S2-Q08', 'easy', 'Pressure', 'A force of 50 N acts over an area of 0.25 m^2. Calculate the pressure in Pa.', 200, 0.1),
                    $this->objective('S2-Q09', 'medium', 'Hooke law', 'Within the limit of proportionality, extension is proportional to...', ['mass only', 'applied force', 'spring length only', 'temperature'], 'B'),
                    $this->essay('S2-Q10', 'hard', 'Forces', 'A cyclist moves at constant speed on a flat road. Explain the forces acting and why the speed remains constant.', 6),
                ],
            ],
            [
                'no' => 3,
                'bank_code' => 'LMS-SAMPLE-BANK-03',
                'exam_code' => 'LMS-SAMPLE-03',
                'title' => 'Electricity Mixed Practice',
                'subject' => 'Physics',
                'level' => 'Cambridge IGCSE',
                'mode' => 'strict',
                'questions' => [
                    $this->imageObjective('S3-Q01', 'easy', 'Circuits', 'In the circuit diagram, which component is labelled A?', '/sample-assets/circuit-diagram.svg', ['voltmeter', 'ammeter', 'resistor', 'cell'], 'B'),
                    $this->objective('S3-Q02', 'easy', 'Current', 'Electric current is measured in...', ['volts', 'ohms', 'amperes', 'joules'], 'C'),
                    $this->numerical('S3-Q03', 'medium', 'Ohm law', 'A resistor has 12 V across it and a current of 3 A through it. Calculate the resistance in ohms.', 4, 0.01),
                    $this->checkbox('S3-Q04', 'medium', 'Series and parallel', 'Select statements true for a series circuit.', ['current is the same in each component' => true, 'voltage is always the same across each component' => false, 'total resistance increases when resistors are added' => true, 'there is more than one path for current' => false], 2),
                    $this->objective('S3-Q05', 'easy', 'Energy transfer', 'Electrical power is calculated using...', ['P = IV', 'P = IR', 'P = V/R', 'P = Q/I'], 'A'),
                    $this->structured('S3-Q06', 'hard', 'Circuits', 'Explain why the lamps in a parallel circuit can remain lit when one lamp is removed.', 4),
                    $this->objective('S3-Q07', 'medium', 'Safety', 'A fuse is connected in the live wire because it...', ['reduces voltage', 'melts if current is too large', 'stores charge', 'measures current'], 'B'),
                    $this->numerical('S3-Q08', 'medium', 'Charge', 'A current of 0.5 A flows for 20 s. Calculate the charge transferred in coulombs.', 10, 0.01),
                    $this->objective('S3-Q09', 'easy', 'Resistance', 'The unit ohm is equivalent to...', ['A/V', 'V/A', 'J/C', 'C/s'], 'B'),
                    $this->essay('S3-Q10', 'hard', 'Electricity', 'Compare the advantages of using parallel circuits instead of series circuits in household wiring.', 6),
                ],
            ],
            [
                'no' => 4,
                'bank_code' => 'LMS-SAMPLE-BANK-04',
                'exam_code' => 'LMS-SAMPLE-04',
                'title' => 'Structured and Essay Skills',
                'subject' => 'Science',
                'level' => 'Lower Secondary',
                'mode' => 'tryout',
                'questions' => [
                    $this->objective('S4-Q01', 'easy', 'Scientific method', 'A hypothesis is best described as...', ['a final conclusion', 'a testable prediction', 'a raw result', 'a safety symbol'], 'B'),
                    $this->structured('S4-Q02', 'medium', 'Experimental design', 'Plan a fair test to investigate how surface area affects cooling rate. Include variables and control measures.', 5),
                    $this->essay('S4-Q03', 'hard', 'Data interpretation', 'A graph shows a non-linear relationship between temperature and time. Explain how you would describe and evaluate the trend.', 6),
                    $this->objective('S4-Q04', 'easy', 'Graphs', 'Which axis usually shows the independent variable?', ['x-axis', 'y-axis', 'both axes', 'neither axis'], 'A'),
                    $this->checkbox('S4-Q05', 'medium', 'Reliability', 'Which actions can improve reliability?', ['repeat measurements' => true, 'average valid repeats' => true, 'ignore anomalies without checking' => false, 'use calibrated equipment' => true], 3),
                    $this->numerical('S4-Q06', 'medium', 'Mean calculation', 'Three readings are 4.2, 4.4 and 4.7. Calculate the mean to one decimal place.', 4.4, 0.05),
                    $this->structured('S4-Q07', 'hard', 'Evaluation', 'A student collected only two readings. Explain two limitations of the method and suggest improvements.', 4),
                    $this->objective('S4-Q08', 'easy', 'Safety', 'Which item protects eyes during heating?', ['gloves', 'goggles', 'apron only', 'ruler'], 'B'),
                    $this->checkbox('S4-Q09', 'medium', 'Variables', 'Select examples of controlled variables.', ['same volume of water' => true, 'same starting temperature' => true, 'time measured as outcome' => false, 'type of container kept constant' => true], 3),
                    $this->essay('S4-Q10', 'hard', 'Scientific method', 'Explain why peer review and repeatable methods matter in scientific investigations.', 6),
                ],
            ],
            [
                'no' => 5,
                'bank_code' => 'LMS-SAMPLE-BANK-05',
                'exam_code' => 'LMS-SAMPLE-05',
                'title' => 'Cambridge Style Review',
                'subject' => 'Physics',
                'level' => 'Cambridge Checkpoint',
                'mode' => 'strict',
                'questions' => [
                    $this->objective('S5-Q01', 'easy', 'Measurements', 'Which value is closest to the mass of an apple?', ['1 g', '100 g', '10 kg', '100 kg'], 'B'),
                    $this->objective('S5-Q02', 'easy', 'Energy', 'The unit of energy is...', ['newton', 'joule', 'ampere', 'tesla'], 'B'),
                    $this->checkbox('S5-Q03', 'medium', 'Waves', 'Select transverse waves.', ['light wave' => true, 'water surface wave' => true, 'sound in air' => false, 'microwave' => true], 3),
                    $this->numerical('S5-Q04', 'medium', 'Speed', 'A runner travels 100 m in 12.5 s. Calculate the average speed in m/s.', 8, 0.01),
                    $this->objective('S5-Q05', 'easy', 'Density', 'Density is calculated using...', ['mass / volume', 'volume / mass', 'force / area', 'distance / time'], 'A'),
                    $this->structured('S5-Q06', 'hard', 'Thermal physics', 'Explain how conduction transfers thermal energy through a metal rod.', 4),
                    $this->imageObjective('S5-Q07', 'medium', 'Circuit symbols', 'What would happen in the circuit if the switch shown is open?', '/sample-assets/circuit-diagram.svg', ['current flows normally', 'no current flows around the complete circuit', 'resistance becomes zero', 'the ammeter reads maximum current'], 'B'),
                    $this->numerical('S5-Q08', 'medium', 'Density', 'A block has mass 240 g and volume 80 cm^3. Calculate its density in g/cm^3.', 3, 0.01),
                    $this->objective('S5-Q09', 'easy', 'Forces', 'Weight is caused by...', ['friction', 'gravity', 'magnetism only', 'air pressure only'], 'B'),
                    $this->essay('S5-Q10', 'hard', 'Review', 'Describe how measurement uncertainty can affect a final calculated result and how students should report it.', 6),
                ],
            ],
        ];
    }

    private function objective(string $id, string $difficulty, string $topic, string $stem, array $options, string $correct): array
    {
        return [
            'external_id' => $id,
            'type' => 'objective',
            'difficulty' => $difficulty,
            'topic' => $topic,
            'max_marks' => 1,
            'stem' => ['text' => $stem],
            'options' => collect($options)->values()->map(fn (string $text, int $index): array => [
                'external_id' => chr(65 + $index),
                'content' => ['text' => $text],
                'is_correct' => chr(65 + $index) === $correct,
                'marks' => chr(65 + $index) === $correct ? 1 : 0,
            ])->all(),
        ];
    }

    private function imageObjective(string $id, string $difficulty, string $topic, string $stem, string $image, array $options, string $correct): array
    {
        $question = $this->objective($id, $difficulty, $topic, $stem, $options, $correct);
        $question['stem']['image'] = $image;
        $question['media'] = ['image' => $image];

        return $question;
    }

    private function checkbox(string $id, string $difficulty, string $topic, string $stem, array $options, int $maxMarks): array
    {
        $correctCount = max(1, collect($options)->filter()->count());

        return [
            'external_id' => $id,
            'type' => 'checkbox',
            'difficulty' => $difficulty,
            'topic' => $topic,
            'max_marks' => $maxMarks,
            'stem' => ['text' => $stem],
            'options' => collect($options)
                ->map(fn (bool $isCorrect, string $text): array => ['text' => $text, 'is_correct' => $isCorrect])
                ->values()
                ->map(fn (array $option, int $index): array => [
                    'external_id' => chr(65 + $index),
                    'content' => ['text' => $option['text']],
                    'is_correct' => $option['is_correct'],
                    'marks' => $option['is_correct'] ? round($maxMarks / $correctCount, 2) : 0,
                ])
                ->all(),
        ];
    }

    private function numerical(string $id, string $difficulty, string $topic, string $stem, float $answer, float $tolerance): array
    {
        return [
            'external_id' => $id,
            'type' => 'numerical',
            'difficulty' => $difficulty,
            'topic' => $topic,
            'max_marks' => 2,
            'stem' => ['text' => $stem],
            'correct_answer' => ['value' => $answer],
            'validation_rules' => ['tolerance' => $tolerance],
        ];
    }

    private function structured(string $id, string $difficulty, string $topic, string $stem, int $maxMarks): array
    {
        return [
            'external_id' => $id,
            'type' => 'structured',
            'difficulty' => $difficulty,
            'topic' => $topic,
            'max_marks' => $maxMarks,
            'stem' => ['text' => $stem],
            'validation_rules' => ['max_length' => 12000],
            'rubrics' => [
                [
                    'criterion' => 'Conceptual accuracy and method',
                    'max_marks' => $maxMarks,
                    'descriptors' => [
                        'full' => 'Clear method, correct science, and complete explanation.',
                        'partial' => 'Some correct ideas with minor gaps.',
                        'limited' => 'Limited relevant science or incomplete method.',
                    ],
                ],
            ],
        ];
    }

    private function essay(string $id, string $difficulty, string $topic, string $stem, int $maxMarks): array
    {
        return [
            'external_id' => $id,
            'type' => 'essay',
            'difficulty' => $difficulty,
            'topic' => $topic,
            'max_marks' => $maxMarks,
            'stem' => ['text' => $stem],
            'validation_rules' => ['max_length' => 8000],
            'rubrics' => [
                [
                    'criterion' => 'Knowledge, explanation, and examples',
                    'max_marks' => $maxMarks,
                    'descriptors' => [
                        'high' => 'Accurate, coherent explanation with relevant examples.',
                        'mid' => 'Mostly accurate explanation with some detail.',
                        'low' => 'Basic ideas with limited explanation.',
                    ],
                ],
            ],
        ];
    }
}

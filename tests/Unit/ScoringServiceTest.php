<?php

namespace Tests\Unit;

use App\Services\ScoringService;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    public function test_checkbox_scores_are_clamped_to_question_maximum(): void
    {
        $service = new ScoringService;
        $score = $service->scoreAnswer([
            'type' => 'checkbox',
            'max_marks' => 2,
            'options' => [
                ['id' => 1, 'is_correct' => true, 'marks' => 2],
                ['id' => 2, 'is_correct' => true, 'marks' => 2],
            ],
        ], [1, 2]);

        $this->assertSame(2.0, $score);
    }
}

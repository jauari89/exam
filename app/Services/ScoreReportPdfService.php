<?php

namespace App\Services;

use App\Models\ExamSession;
use App\Models\Score;

class ScoreReportPdfService
{
    public function render(ExamSession $session): string
    {
        $scores = Score::query()
            ->with('submission.attempt.candidate')
            ->whereIn('exam_attempt_id', $session->attempts()->pluck('id'))
            ->orderByDesc('total_score')
            ->get();

        $lines = [
            'Secure Exam Platform - Score Report',
            'Session: '.$session->name,
            'Exam: '.($session->exam?->title ?? '-'),
            'Generated: '.now()->toIso8601String(),
            '',
            str_pad('Candidate', 36).str_pad('Score', 16).'Status',
            str_repeat('-', 78),
        ];

        foreach ($scores as $score) {
            $candidate = $score->submission?->attempt?->candidate;
            $name = trim(($candidate?->candidate_number ?? '-').' '.$candidate?->name);
            $scoreText = number_format((float) $score->total_score, 2).'/'.number_format((float) $score->max_score, 2);
            $lines[] = str($name)->limit(34, '')->padRight(36)->toString().str_pad($scoreText, 16).$score->status;
        }

        if ($scores->isEmpty()) {
            $lines[] = 'No scores are available for this session yet.';
        }

        return $this->pdf($lines);
    }

    private function pdf(array $lines): string
    {
        $pages = array_chunk($lines, 44);
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $kids = [];

        foreach ($pages as $pageIndex => $pageLines) {
            $pageObject = 4 + ($pageIndex * 2);
            $contentObject = $pageObject + 1;
            $kids[] = "$pageObject 0 R";
            $objects[$pageObject - 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $contentObject 0 R >>";
            $stream = $this->contentStream($pageLines);
            $objects[$contentObject - 1] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
        }

        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n$object\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= str_pad((string) $offsets[$index], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

        return $pdf;
    }

    private function contentStream(array $lines): string
    {
        $stream = "BT\n/F1 10 Tf\n50 790 Td\n";

        foreach ($lines as $line) {
            $stream .= '('.$this->escape($line).") Tj\n0 -16 Td\n";
        }

        return $stream.'ET';
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'));
    }
}

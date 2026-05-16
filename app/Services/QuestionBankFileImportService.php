<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class QuestionBankFileImportService
{
    public function toImportPayload(UploadedFile $file, string $mode = 'upsert'): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $questions = match ($extension) {
            'json' => $this->fromJson($file->getRealPath()),
            'csv', 'txt' => $this->fromDelimited($file->getRealPath(), ','),
            'tsv' => $this->fromDelimited($file->getRealPath(), "\t"),
            'xlsx' => $this->fromXlsx($file->getRealPath()),
            default => throw ValidationException::withMessages(['file' => 'Supported question formats: JSON, CSV, TSV, XLSX.']),
        };

        return [
            'mode' => $mode,
            'questions' => $questions,
        ];
    }

    private function fromJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $questions = $payload['questions'] ?? $payload['Questions'] ?? (array_is_list($payload) ? $payload : null);

        if (! is_array($questions)) {
            throw ValidationException::withMessages(['file' => 'JSON must contain a questions array.']);
        }

        return collect($questions)->values()->map(fn (array $question, int $index) => $this->normalizeQuestion($question, $index))->all();
    }

    private function fromDelimited(string $path, string $delimiter): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Unable to read question import file.']);
        }

        $headers = null;
        $rows = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($headers === null) {
                $headers = $this->normalizeHeaders($line);

                continue;
            }

            if (count(array_filter($line, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($line, count($headers), null)) ?: [];
        }

        fclose($handle);

        return collect($rows)->values()->map(fn (array $row, int $index) => $this->normalizeQuestion($row, $index))->all();
    }

    private function fromXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'XLSX import requires PHP ZipArchive.']);
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Unable to open XLSX file.']);
        }

        $sharedStrings = $this->sharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! $sheetXml) {
            throw ValidationException::withMessages(['file' => 'XLSX sheet1.xml not found.']);
        }

        $sheet = simplexml_load_string($sheetXml);
        $headers = null;
        $rows = [];

        foreach ($sheet->sheetData->row as $row) {
            $values = [];

            foreach ($row->c as $cell) {
                $values[$this->columnIndex((string) $cell['r'])] = $this->cellValue($cell, $sharedStrings);
            }

            if ($values === []) {
                continue;
            }

            ksort($values);
            $line = [];
            $max = max(array_keys($values));

            for ($index = 0; $index <= $max; $index++) {
                $line[] = $values[$index] ?? null;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($line);

                continue;
            }

            if (count(array_filter($line, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($line, count($headers), null)) ?: [];
        }

        return collect($rows)->values()->map(fn (array $row, int $index) => $this->normalizeQuestion($row, $index))->all();
    }

    private function normalizeQuestion(array $row, int $index): array
    {
        $row = collect($row)->mapWithKeys(fn ($value, $key) => [$this->normalizeHeader((string) $key) => is_string($value) ? trim($value) : $value])->all();
        $externalId = (string) ($row['external_id'] ?? $row['id_question'] ?? $row['id'] ?? 'Q'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT));
        $type = $this->type((string) ($row['type'] ?? $row['type_of_question'] ?? $row['question_type'] ?? 'objective'));
        $maxMarks = (float) ($row['max_marks'] ?? $row['points'] ?? $row['marks'] ?? ($type === 'checkbox' ? 2 : 1));
        $key = $row['correct_answer'] ?? $row['key_answer'] ?? $row['answer'] ?? null;
        $options = $this->options($row, $type, $key, $maxMarks);

        return [
            'external_id' => $externalId,
            'type' => $type,
            'difficulty' => $this->difficulty((string) ($row['difficulty'] ?? 'medium')),
            'position' => (int) ($row['position'] ?? $index + 1),
            'topic' => $row['topic'] ?? null,
            'max_marks' => $maxMarks,
            'stem' => array_filter([
                'text' => $row['stem_text'] ?? $row['question'] ?? $row['text'] ?? '',
                'image' => $row['stem_image'] ?? $row['image'] ?? null,
                'math' => $row['math'] ?? null,
            ]),
            'correct_answer' => $this->correctAnswer($type, $key),
            'validation_rules' => array_filter([
                'tolerance' => isset($row['tolerance']) && $row['tolerance'] !== '' ? (float) $row['tolerance'] : null,
                'max_length' => in_array($type, ['essay', 'structured'], true) ? (int) ($row['max_length'] ?? ($type === 'essay' ? 8000 : 12000)) : null,
            ], fn ($value) => $value !== null),
            'feedback' => isset($row['explanation']) || isset($row['feedback']) ? ['text' => $row['explanation'] ?? $row['feedback']] : null,
            'metadata' => ['source' => 'question_bank_file_import'],
            'options' => $options,
            'rubrics' => in_array($type, ['essay', 'structured'], true) ? [[
                'criterion' => $row['rubric_criterion'] ?? 'Rubric',
                'max_marks' => $maxMarks,
                'descriptors' => ['text' => $row['rubric'] ?? $row['rubric_descriptors'] ?? 'Mark using the published rubric.'],
            ]] : [],
        ];
    }

    private function options(array $row, string $type, mixed $key, float $maxMarks): array
    {
        if ($type === 'numerical' || in_array($type, ['essay', 'structured'], true)) {
            return [];
        }

        if (($row['type'] ?? $row['type_of_question'] ?? null) === 'TrueFalse' || (string) $key === 'True' || (string) $key === 'False') {
            $values = ['True', 'False'];
        } else {
            $values = collect(['a', 'b', 'c', 'd', 'e', 'f'])
                ->mapWithKeys(fn (string $letter) => [$letter => $row['option_'.$letter] ?? $row[$letter] ?? null])
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->values()
                ->all();
        }

        $correct = collect(preg_split('/[,;|]+/', (string) $key) ?: [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->values();
        $correctCount = max(1, $correct->count());

        return collect($values)->values()->map(function (string $text, int $index) use ($correct, $correctCount, $maxMarks, $type): array {
            $letter = chr(65 + $index);
            $id = in_array($text, ['True', 'False'], true) ? strtoupper($text) : $letter;
            $isCorrect = $correct->contains($id) || $correct->contains($letter);

            return [
                'external_id' => $id,
                'content' => ['text' => $text],
                'is_correct' => $isCorrect,
                'marks' => $isCorrect ? ($type === 'checkbox' ? round($maxMarks / $correctCount, 2) : $maxMarks) : 0,
            ];
        })->all();
    }

    private function correctAnswer(string $type, mixed $key): ?array
    {
        if ($type === 'numerical') {
            return ['value' => is_numeric($key) ? (float) $key : $key];
        }

        if (in_array($type, ['essay', 'structured'], true)) {
            return null;
        }

        $values = collect(preg_split('/[,;|]+/', (string) $key) ?: [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        return ['option_ids' => $type === 'checkbox' ? $values : ($values[0] ?? null)];
    }

    private function type(string $type): string
    {
        return match (strtolower($type)) {
            'mcq', 'objective', 'truefalse', 'true_false', 'tf' => 'objective',
            'multiplecheckbox', 'multiple_checkbox', 'checkbox', 'multi' => 'checkbox',
            'number', 'numeric', 'numerical' => 'numerical',
            'essay' => 'essay',
            'structured' => 'structured',
            default => 'objective',
        };
    }

    private function difficulty(string $difficulty): string
    {
        return match (strtolower($difficulty)) {
            'easy', 'medium', 'hard' => strtolower($difficulty),
            default => 'medium',
        };
    }

    private function normalizeHeaders(array $headers): array
    {
        return collect($headers)->map(fn ($header) => $this->normalizeHeader((string) $header))->all();
    }

    private function normalizeHeader(string $header): string
    {
        $value = str($header)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return match ($value) {
            'idquestion', 'id_question', 'question_id' => 'external_id',
            'typeofquestion', 'type_of_question' => 'type',
            'optionanswer_a', 'option_a', 'answer_a' => 'option_a',
            'optionanswer_b', 'option_b', 'answer_b' => 'option_b',
            'optionanswer_c', 'option_c', 'answer_c' => 'option_c',
            'optionanswer_d', 'option_d', 'answer_d' => 'option_d',
            'optionanswer_e', 'option_e', 'answer_e' => 'option_e',
            'optionanswer_f', 'option_f', 'answer_f' => 'option_f',
            'keyanswer', 'key_answer' => 'correct_answer',
            default => $value,
        };
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if (! $xml) {
            return [];
        }

        $strings = simplexml_load_string($xml);
        $values = [];

        foreach ($strings->si as $item) {
            $values[] = isset($item->t)
                ? (string) $item->t
                : collect($item->r ?? [])->map(fn ($run) => (string) $run->t)->implode('');
        }

        return $values;
    }

    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            return $sharedStrings[(int) $cell->v] ?? null;
        }

        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        return isset($cell->v) ? (string) $cell->v : null;
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^([A-Z]+)/', $cellRef, $matches);
        $index = 0;

        foreach (str_split($matches[1] ?? 'A') as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }
}

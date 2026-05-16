<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class CandidateImportService
{
    public function import(UploadedFile $file, ?int $candidateGroupId = null): array
    {
        $rows = $this->readRowsFromPath($file->getRealPath(), strtolower($file->getClientOriginalExtension()));

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Import file has no candidate rows.']);
        }

        $imported = DB::transaction(function () use ($rows, $candidateGroupId) {
            return collect($rows)->map(function (array $row) use ($candidateGroupId): Candidate {
                $candidateNumber = trim((string) ($row['candidate_number'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));

                if ($candidateNumber === '' || $name === '') {
                    throw ValidationException::withMessages(['file' => 'Every row must include candidate_number and name.']);
                }

                return Candidate::query()->updateOrCreate(
                    ['candidate_number' => $candidateNumber],
                    [
                        'candidate_group_id' => $row['candidate_group_id'] ?? $candidateGroupId,
                        'name' => $name,
                        'normalized_name' => Candidate::normalizeName($name),
                        'email' => $row['email'] ?? null,
                        'external_id' => $row['external_id'] ?? null,
                        'metadata' => array_filter([
                            'source' => 'candidate_file_import',
                            'class' => $row['class'] ?? null,
                            'room' => $row['room'] ?? null,
                            'exam_name' => $row['exam_name'] ?? null,
                            'import_status' => $row['import_status'] ?? null,
                            'login_time' => $row['login_time'] ?? null,
                            'submission_time' => $row['submission_time'] ?? null,
                        ]),
                    ],
                );
            });
        });

        return [
            'imported' => $imported->count(),
            'candidates' => $imported->values(),
        ];
    }

    public function readRowsFromPath(string $path, ?string $extension = null): array
    {
        return match (strtolower($extension ?: pathinfo($path, PATHINFO_EXTENSION))) {
            'csv', 'txt' => $this->readDelimited($path, ','),
            'tsv' => $this->readDelimited($path, "\t"),
            'xlsx' => $this->readXlsx($path),
            default => throw ValidationException::withMessages(['file' => 'Supported formats: CSV, TSV, XLSX.']),
        };
    }

    private function readDelimited(string $path, string $delimiter): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'Unable to read import file.']);
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

            $rows[] = $this->normalizeRow(array_combine($headers, array_pad($line, count($headers), null)) ?: []);
        }

        fclose($handle);

        return $rows;
    }

    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'XLSX import requires PHP ZipArchive.']);
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Unable to open XLSX file.']);
        }

        $sharedStrings = $this->sharedStrings($zip);
        $worksheetPaths = $this->worksheetPaths($zip);
        $candidateRows = [];

        foreach ($worksheetPaths as $sheetName => $worksheetPath) {
            $sheetXml = $zip->getFromName($worksheetPath);

            if (! $sheetXml) {
                continue;
            }

            $rows = $this->rowsFromWorksheetXml($sheetXml, $sharedStrings);

            if ($this->hasCandidateRows($rows)) {
                $candidateRows = $rows;

                if (str($sheetName)->lower()->trim()->toString() === 'data student') {
                    break;
                }
            }
        }

        if ($candidateRows === [] && $worksheetPaths === []) {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $candidateRows = $sheetXml ? $this->rowsFromWorksheetXml($sheetXml, $sharedStrings) : [];
        }

        $zip->close();

        if (! $this->hasCandidateRows($candidateRows)) {
            throw ValidationException::withMessages(['file' => 'No worksheet with candidate_number and name columns was found.']);
        }

        return $candidateRows;
    }

    private function rowsFromWorksheetXml(string $sheetXml, array $sharedStrings): array
    {
        $sheet = simplexml_load_string($sheetXml);

        if (! $sheet || ! isset($sheet->sheetData)) {
            return [];
        }

        $rows = [];
        $headers = null;

        foreach ($sheet->sheetData->row as $row) {
            $values = [];

            foreach ($row->c as $cell) {
                $cellRef = (string) $cell['r'];
                $column = $this->columnIndex($cellRef);
                $values[$column] = $this->cellValue($cell, $sharedStrings);
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

            $rows[] = $this->normalizeRow(array_combine($headers, array_pad($line, count($headers), null)) ?: []);
        }

        return $rows;
    }

    private function worksheetPaths(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! $workbookXml || ! $relsXml) {
            return [];
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (! $workbook || ! $rels || ! isset($workbook->sheets)) {
            return [];
        }

        $relationships = [];

        foreach ($rels->Relationship as $relationship) {
            $relationships[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $paths = [];

        foreach ($workbook->sheets->sheet as $sheet) {
            $sheetName = (string) $sheet['name'];
            $relationshipId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $relationships[$relationshipId] ?? null;

            if (! $target) {
                continue;
            }

            $paths[$sheetName] = str_starts_with($target, '/')
                ? ltrim($target, '/')
                : 'xl/'.ltrim($target, '/');
        }

        uksort($paths, fn (string $left, string $right): int => match (true) {
            str($left)->lower()->trim()->toString() === 'data student' => -1,
            str($right)->lower()->trim()->toString() === 'data student' => 1,
            default => 0,
        });

        return $paths;
    }

    private function hasCandidateRows(array $rows): bool
    {
        return collect($rows)->contains(fn (array $row): bool => filled($row['candidate_number'] ?? null) && filled($row['name'] ?? null));
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
            if (isset($item->t)) {
                $values[] = (string) $item->t;

                continue;
            }

            $values[] = collect($item->r ?? [])->map(fn ($run) => (string) $run->t)->implode('');
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
        $letters = $matches[1] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function normalizeHeaders(array $headers): array
    {
        return collect($headers)->map(fn ($header) => match ($this->normalizeHeaderText((string) $header)) {
            'candidate_no', 'candidate_number', 'number', 'nomor', 'nomor_peserta', 'no_peserta', 'student_id', 'nis', 'nisn' => 'candidate_number',
            'student_name', 'candidate_name', 'nama', 'nama_siswa', 'full_name' => 'name',
            'mail', 'email_address' => 'email',
            'external', 'externalid', 'external_id' => 'external_id',
            'group_id', 'candidate_group_id' => 'candidate_group_id',
            'kelas', 'class_name' => 'class',
            'exam', 'exam_name', 'nama_ujian' => 'exam_name',
            'status' => 'import_status',
            'login_time', 'waktu_login' => 'login_time',
            'submission_time', 'submit_time', 'waktu_submit' => 'submission_time',
            default => $this->normalizeHeaderText((string) $header),
        })->all();
    }

    private function normalizeHeaderText(string $header): string
    {
        return str($header)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function normalizeRow(array $row): array
    {
        return collect($row)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== '')
            ->all();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CandidateImportAndEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_candidates_from_csv_and_generate_token_slips(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
        $session = $this->sessionFixture();

        $file = UploadedFile::fake()->createWithContent('candidates.csv', "candidate_number,name,email\nC001,Ada Lovelace,ada@example.test\nC002,Grace Hopper,grace@example.test\n");

        $this->post('/api/admin/candidates/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('imported', 2);

        $tokens = $this->postJson('/api/admin/tokens/generate', [
            'exam_session_id' => $session->id,
            'all_candidates' => true,
        ])->assertOk()->json('tokens');

        $this->assertCount(2, $tokens);
        $this->assertArrayHasKey('candidate_name', $tokens[0]);
        $this->assertArrayHasKey('plain_token', $tokens[0]);
        $this->assertDatabaseCount('candidate_exam_tokens', 2);
    }

    public function test_admin_can_import_candidates_from_cambridge_xlsx_data_student_sheet(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not installed.');
        }

        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());

        $file = new UploadedFile(
            $this->cambridgeWorkbookPath(),
            'Cambridge.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->post('/api/admin/candidates/import', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('imported', 1);

        $candidate = Candidate::query()->where('candidate_number', '1')->firstOrFail();

        $this->assertSame('Ada Student', $candidate->name);
        $this->assertSame('TOPIC1', $candidate->metadata['exam_name']);
        $this->assertSame('PENDING', $candidate->metadata['import_status']);
        $this->assertArrayNotHasKey('token', $candidate->metadata);
    }

    public function test_admin_can_export_evidence_zip(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not installed.');
        }

        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
        $session = $this->sessionFixture();

        $export = $this->postJson('/api/reports/evidence-exports', [
            'exam_session_id' => $session->id,
            'format' => 'zip',
        ])->assertCreated()->json();

        $this->assertSame('zip', $export['format']);
        $this->assertStringEndsWith('.zip', $export['path']);

        $this->get("/api/reports/evidence-exports/{$export['id']}/download")->assertOk();
    }

    public function test_admin_can_download_score_report_pdf(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
        $session = $this->sessionFixture();

        $response = $this->get("/api/reports/sessions/{$session->id}/score-report.pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    private function sessionFixture(): ExamSession
    {
        $series = ExamSeries::query()->create(['code' => 'SER-IMP', 'title' => 'Series']);
        $exam = Exam::query()->create([
            'exam_series_id' => $series->id,
            'code' => 'IMP',
            'title' => 'Import Exam',
            'mode' => 'tryout',
            'default_duration_minutes' => 60,
        ]);
        $paper = ExamPaper::query()->create([
            'exam_id' => $exam->id,
            'code' => 'P1',
            'title' => 'Paper 1',
            'duration_minutes' => 60,
        ]);

        return ExamSession::query()->create([
            'exam_id' => $exam->id,
            'exam_paper_id' => $paper->id,
            'name' => 'Import Session',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'duration_minutes' => 60,
            'mode' => 'tryout',
        ]);
    }

    private function cambridgeWorkbookPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cambridge-xlsx-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Summary" sheetId="1" r:id="rId1"/>
    <sheet name="Data Student" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="12" uniqueCount="12">
  <si><t>Ignored</t></si>
  <si><t>Value</t></si>
  <si><t>Number</t></si>
  <si><t>Student Name</t></si>
  <si><t>Token</t></si>
  <si><t>Status</t></si>
  <si><t>Exam Name</t></si>
  <si><t>1</t></si>
  <si><t>Ada Student</t></si>
  <si><t>abc-123</t></si>
  <si><t>PENDING</t></si>
  <si><t>TOPIC1</t></si>
</sst>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="s"><v>0</v></c>
      <c r="B1" t="s"><v>1</v></c>
    </row>
  </sheetData>
</worksheet>
XML);
        $zip->addFromString('xl/worksheets/sheet2.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="s"><v>2</v></c>
      <c r="B1" t="s"><v>3</v></c>
      <c r="C1" t="s"><v>4</v></c>
      <c r="D1" t="s"><v>5</v></c>
      <c r="E1" t="s"><v>6</v></c>
    </row>
    <row r="2">
      <c r="A2" t="s"><v>7</v></c>
      <c r="B2" t="s"><v>8</v></c>
      <c r="C2" t="s"><v>9</v></c>
      <c r="D2" t="s"><v>10</v></c>
      <c r="E2" t="s"><v>11</v></c>
    </row>
  </sheetData>
</worksheet>
XML);
        $zip->close();

        return $path;
    }
}

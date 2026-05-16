<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('exam_series', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('status')->default('draft')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_series_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->string('type')->default('objective');
            $table->string('mode')->default('strict');
            $table->string('status')->default('draft')->index();
            $table->unsignedSmallInteger('default_duration_minutes')->default(90);
            $table->boolean('randomize_questions')->default(false);
            $table->boolean('reveal_feedback')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['exam_series_id', 'code']);
        });

        Schema::create('exam_papers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft')->index();
            $table->unsignedSmallInteger('duration_minutes')->default(90);
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->text('instructions')->nullable();
            $table->json('content')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'code', 'version']);
        });

        Schema::create('exam_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_paper_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('mode')->default('strict');
            $table->string('status')->default('scheduled')->index();
            $table->string('timezone')->default('UTC');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_series_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('candidate_number')->unique();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('email')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_paper_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('checksum')->index();
            $table->boolean('strict_mode')->default(true);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->json('source_payload')->nullable();
            $table->json('validated_payload');
            $table->timestamps();
            $table->unique(['exam_paper_id', 'version']);
        });

        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('questions')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('type')->index();
            $table->unsignedInteger('position')->default(1);
            $table->string('topic')->nullable()->index();
            $table->decimal('max_marks', 8, 2)->default(1);
            $table->json('stem');
            $table->json('correct_answer')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('feedback')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['exam_package_id', 'external_id']);
        });

        Schema::create('question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->unsignedInteger('position')->default(1);
            $table->json('content');
            $table->boolean('is_correct')->default(false);
            $table->decimal('marks', 8, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['question_id', 'external_id']);
        });

        Schema::create('question_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('criterion');
            $table->decimal('max_marks', 8, 2);
            $table->json('descriptors')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_exam_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->index();
            $table->string('purpose')->default('initial')->index();
            $table->string('token_lookup_hash', 128)->unique();
            $table->string('token_hash');
            $table->string('token_suffix', 8)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_exam_token_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('status')->default('in_progress')->index();
            $table->string('mode')->default('strict');
            $table->string('session_key_hash', 128)->unique();
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lock_reason')->nullable();
            $table->boolean('auto_submitted')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('attempt_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_attempt_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('exam_package_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('snapshot_version')->default(1);
            $table->string('package_checksum');
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->json('payload');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('autosaves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('client_sequence')->default(1);
            $table->json('payload');
            $table->json('normalized_answers');
            $table->json('validation_errors')->nullable();
            $table->timestamp('saved_at')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'client_sequence']);
        });

        Schema::create('submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_attempt_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('idempotency_key_hash', 128);
            $table->string('status')->default('submitted')->index();
            $table->timestamp('submitted_at')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->boolean('auto_submitted')->default(false);
            $table->json('raw_payload')->nullable();
            $table->json('normalized_answers');
            $table->string('score_state')->default('pending');
            $table->string('payload_hash', 128);
            $table->timestamps();
            $table->unique(['exam_attempt_id', 'idempotency_key_hash']);
        });

        Schema::create('submission_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question_external_id');
            $table->string('answer_type')->index();
            $table->json('answer')->nullable();
            $table->json('normalized_answer')->nullable();
            $table->decimal('max_marks', 8, 2)->default(0);
            $table->decimal('auto_score', 8, 2)->nullable();
            $table->decimal('manual_score', 8, 2)->nullable();
            $table->decimal('final_score', 8, 2)->nullable();
            $table->boolean('requires_manual_marking')->default(false);
            $table->json('feedback')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'question_external_id']);
        });

        Schema::create('scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->decimal('auto_score', 8, 2)->default(0);
            $table->decimal('manual_score', 8, 2)->default(0);
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(0);
            $table->string('status')->default('pending_manual')->index();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('score_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('score_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_answer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('earned_marks', 8, 2)->default(0);
            $table->decimal('max_marks', 8, 2)->default(0);
            $table->string('source')->default('auto')->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('marking_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('assigned')->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });

        Schema::create('manual_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marker_id')->constrained('users')->cascadeOnDelete();
            $table->string('marker_role')->default('first_marker');
            $table->decimal('earned_marks', 8, 2);
            $table->decimal('max_marks', 8, 2);
            $table->text('comments')->nullable();
            $table->string('status')->default('pending_review')->index();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();
            $table->unique(['submission_answer_id', 'marker_id', 'marker_role']);
        });

        Schema::create('moderation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision')->default('accepted')->index();
            $table->decimal('final_score', 8, 2)->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('proctor_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('severity')->default('info')->index();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('incident_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('severity')->default('medium')->index();
            $table->string('status')->default('open')->index();
            $table->string('title');
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('checked_in')->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type')->default('system')->index();
            $table->string('action')->index();
            $table->string('auditable_type')->nullable()->index();
            $table->unsignedBigInteger('auditable_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('evidence_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('format')->default('json');
            $table->string('path')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_exports');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('incident_reports');
        Schema::dropIfExists('proctor_events');
        Schema::dropIfExists('moderation_reviews');
        Schema::dropIfExists('manual_marks');
        Schema::dropIfExists('marking_assignments');
        Schema::dropIfExists('score_details');
        Schema::dropIfExists('scores');
        Schema::dropIfExists('submission_answers');
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('autosaves');
        Schema::dropIfExists('attempt_snapshots');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('candidate_exam_tokens');
        Schema::dropIfExists('question_rubrics');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('exam_packages');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('candidate_groups');
        Schema::dropIfExists('exam_rooms');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('exam_papers');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('exam_series');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};

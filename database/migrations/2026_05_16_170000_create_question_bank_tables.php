<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('subject')->nullable()->index();
            $table->string('level')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('question_bank_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('type')->index();
            $table->string('difficulty')->default('medium')->index();
            $table->unsignedInteger('position')->default(1);
            $table->string('topic')->nullable()->index();
            $table->decimal('max_marks', 8, 2)->default(1);
            $table->json('stem');
            $table->json('correct_answer')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('feedback')->nullable();
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['question_bank_id', 'external_id']);
        });

        Schema::create('question_bank_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_bank_item_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->unsignedInteger('position')->default(1);
            $table->json('content');
            $table->boolean('is_correct')->default(false);
            $table->decimal('marks', 8, 2)->default(0);
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['question_bank_item_id', 'external_id']);
        });

        Schema::create('question_bank_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_bank_item_id')->constrained()->cascadeOnDelete();
            $table->string('criterion');
            $table->decimal('max_marks', 8, 2);
            $table->json('descriptors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_rubrics');
        Schema::dropIfExists('question_bank_options');
        Schema::dropIfExists('question_bank_items');
        Schema::dropIfExists('question_banks');
    }
};

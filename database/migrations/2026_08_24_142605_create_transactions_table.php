<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_period_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer', 'adjustment', 'reversal']);
            $table->bigInteger('amount');
            $table->string('description');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_wallet')->nullable();
            $table->string('target_wallet')->nullable();
            $table->foreignId('reference_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->restrictOnDelete();
            $table->enum('status', ['success', 'reversed'])->default('success');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

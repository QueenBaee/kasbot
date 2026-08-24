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
        Schema::create('budget_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_cycle_id')->constrained()->cascadeOnDelete();
            $table->integer('period_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->bigInteger('allocated_amount');
            $table->bigInteger('carry_over_amount')->default(0);
            $table->bigInteger('total_budget');
            $table->bigInteger('spent_amount')->default(0);
            $table->bigInteger('remaining_amount');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['budget_cycle_id', 'period_number']);
            $table->index(['budget_cycle_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_periods');
    }
};

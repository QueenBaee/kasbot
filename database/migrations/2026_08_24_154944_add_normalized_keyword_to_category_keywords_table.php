<?php

declare(strict_types=1);

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
        Schema::table('category_keywords', function (Blueprint $table) {
            $table->string('normalized_keyword')->after('keyword')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_keywords', function (Blueprint $table) {
            $table->dropUnique(['normalized_keyword']);
            $table->dropColumn('normalized_keyword');
        });
    }
};

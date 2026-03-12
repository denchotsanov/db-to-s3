<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("files", function (Blueprint $table) {
            $table->string("s3_path")->nullable()->after("type");
        });
    }
    public function down(): void {
        Schema::table("files", function (Blueprint $table) {
            $table->dropColumn("s3_path");
        });
    }
};

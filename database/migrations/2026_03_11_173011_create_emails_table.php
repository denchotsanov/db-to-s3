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
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->integer('client_id')->index();
            $table->integer('loan_id')->index();
            $table->integer('email_template_id')->index();
            $table->string('receiver_email');
            $table->string('sender_email');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('file_ids');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};

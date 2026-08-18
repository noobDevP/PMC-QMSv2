<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('ad_media', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 255);
            $table->string('file_type', 20);
            $table->integer('duration')->default(10);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('ad_media'); }
};
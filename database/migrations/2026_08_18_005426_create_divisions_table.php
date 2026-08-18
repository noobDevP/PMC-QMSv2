<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('prefix', 10);
            $table->integer('tv_id')->default(1);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('divisions'); }
};
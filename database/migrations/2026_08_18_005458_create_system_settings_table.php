<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('tv_idle_seconds')->default(30);
            $table->integer('shrink_timeout')->default(15);
            $table->integer('collapse_timeout')->default(30);
            $table->integer('periodic_return_timer')->default(0);
            $table->string('periodic_return_mode', 20)->default('full_queue');
            $table->integer('ads_interval')->default(10);
            $table->text('announcement')->nullable();
            $table->string('media_mode', 20)->default('ads');
            $table->string('youtube_id', 100)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('system_settings'); }
};
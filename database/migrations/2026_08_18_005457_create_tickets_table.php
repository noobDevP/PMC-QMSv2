<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20);
            $table->string('customer_type', 20);
            $table->string('customer_name', 100)->nullable();
            $table->string('additional_info', 255)->nullable();
            $table->foreignId('division_id')->constrained('divisions')->onDelete('cascade');
            $table->foreignId('purpose_id')->constrained('purposes')->onDelete('cascade');
            $table->string('status', 20)->default('IN_QUEUE');
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('teller_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('tickets'); }
};
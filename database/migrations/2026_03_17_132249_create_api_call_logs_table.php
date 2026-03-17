<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('api_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 100);
            $table->date('called_date');
            $table->timestamps();
            $table->index('called_date');
        });
    }
    public function down(): void { Schema::dropIfExists('api_call_logs'); }
};

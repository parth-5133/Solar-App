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
        Schema::create('solar_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->string('roof_type')->nullable();
            $table->decimal('roof_area', 10, 2)->nullable();
            $table->string('capacity')->nullable();
            $table->string('solar_company')->nullable();
            $table->string('inverter_company')->nullable();
            $table->string('jan_samarth_id')->nullable();
            $table->enum('loan_required', ['Yes', 'No'])->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('consumer_no')->nullable();
            $table->string('application_ref_no')->nullable();
            $table->unsignedBigInteger('channel_partner_id')->nullable();
            $table->date('registration_date')->nullable();
            $table->decimal('solar_total_amount', 12, 2)->nullable();
            $table->string('installers')->nullable();
            $table->text('customer_address')->nullable();
            $table->text('customer_residential_address')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solar_details');
    }
};

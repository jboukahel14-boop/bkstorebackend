<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->foreignId('wilaya_id')->nullable()->constrained()->nullOnDelete();
            $table->string('commune')->nullable();
            $table->string('address')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('shipping_fee', 8, 2)->default(0);
            $table->enum('delivery_type', ['home', 'desk'])->default('home');
            $table->enum('status', ['pending', 'confirmed', 'shipped', 'delivered', 'canceled'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
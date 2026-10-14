<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('role', ['admin', 'staff', 'user'])->default('user');
            $table->timestamp('created_at')->useCurrent();
        });

        try {
            DB::unprepared("
                DROP FUNCTION IF EXISTS has_role;
                CREATE FUNCTION has_role(p_user_id BIGINT, p_role VARCHAR(50))
                RETURNS TINYINT(1)
                DETERMINISTIC
                READS SQL DATA
                BEGIN
                    DECLARE role_count INT DEFAULT 0;
                    SELECT COUNT(*) INTO role_count FROM user_roles WHERE user_id = p_user_id AND role = p_role;
                    RETURN IF(role_count > 0, 1, 0);
                END;
            ");
        } catch (\Throwable $e) {
            // Environment without raw function privileges
        }
    }

    public function down(): void
    {
        try {
            DB::unprepared("DROP FUNCTION IF EXISTS has_role;");
        } catch (\Throwable $e) {}
        Schema::dropIfExists('user_roles');
    }
};

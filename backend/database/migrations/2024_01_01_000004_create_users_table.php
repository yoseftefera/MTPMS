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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('password', 255);
            $table->uuid('department_id')->nullable();
            $table->enum('status', ['active', 'inactive', 'locked'])->default('active');
            $table->tinyInteger('failed_login_attempts')->unsigned()->default(0);
            $table->string('avatar', 500)->nullable();
            $table->string('phone', 50)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->onDelete('set null');

            // Composite unique: email must be unique within a tenant
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('department_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->text('short_description')->nullable();
            $table->text('requirements')->nullable();
            $table->text('what_will_learn')->nullable();
            $table->unsignedBigInteger('instructor_id');
            $table->unsignedBigInteger('category_id');
            $table->string('thumbnail')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('promotional_video_url')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->timestamp('discount_ends_at')->nullable();
            $table->integer('duration_in_minutes')->default(0);
            $table->enum('level', ['beginner', 'intermediate', 'advanced', 'all-levels'])->default('all-levels');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->integer('ratings_count')->default(0);
            $table->integer('students_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('instructor_id')->references('id')->on('users');
            $table->foreign('category_id')->references('id')->on('categories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};

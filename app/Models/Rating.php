<?php
// Arquivo: app/Models/Rating.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rating extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'course_id',
        'rating',
        'review',
        'is_approved',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'rating' => 'integer',
        'is_approved' => 'boolean',
    ];

    /**
     * O usuário que fez a avaliação.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O curso que foi avaliado.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Escopo para avaliações aprovadas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Aprovar a avaliação.
     */
    public function approve()
    {
        $this->is_approved = true;
        $this->save();

        // Recalcular a média de avaliações do curso
        $this->updateCourseRating();
    }

    /**
     * Rejeitar a avaliação.
     */
    public function reject()
    {
        $this->is_approved = false;
        $this->save();

        // Recalcular a média de avaliações do curso
        $this->updateCourseRating();
    }

    /**
     * Atualizar a média de avaliações do curso.
     */
    private function updateCourseRating()
    {
        $course = $this->course;

        $ratings = Rating::where('course_id', $course->id)
            ->where('is_approved', true)
            ->get();

        $ratingsCount = $ratings->count();

        if ($ratingsCount > 0) {
            $averageRating = $ratings->avg('rating');

            $course->average_rating = round($averageRating, 2);
            $course->ratings_count = $ratingsCount;
            $course->save();
        } else {
            $course->average_rating = 0;
            $course->ratings_count = 0;
            $course->save();
        }
    }
}

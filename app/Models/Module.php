<?php
// Arquivo: app/Models/Module.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Module extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'course_id',
        'title',
        'description',
        'order',
        'is_free',
        'duration_in_minutes',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'order' => 'integer',
        'is_free' => 'boolean',
        'duration_in_minutes' => 'integer',
    ];

    /**
     * O curso ao qual este módulo pertence.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * As aulas que compõem este módulo.
     */
    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    /**
     * Atualizar a duração do módulo com base nas aulas.
     */
    public function updateDuration()
    {
        $this->duration_in_minutes = $this->lessons()->sum('duration_in_minutes');
        $this->save();

        // Atualizar também a duração do curso
        $this->course->duration_in_minutes = $this->course->modules()->sum('duration_in_minutes');
        $this->course->save();
    }
}

<?php
// Arquivo: app/Models/Lesson.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'module_id',
        'title',
        'description',
        'type',
        'video_url',
        'content',
        'duration_in_minutes',
        'order',
        'is_free',
        'is_published',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'duration_in_minutes' => 'integer',
        'order' => 'integer',
        'is_free' => 'boolean',
        'is_published' => 'boolean',
    ];

    /**
     * O módulo ao qual esta aula pertence.
     */
    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Os materiais de estudo relacionados a esta aula.
     */
    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    /**
     * Os registros de progresso dos alunos nesta aula.
     */
    public function progress()
    {
        return $this->hasMany(StudentProgress::class);
    }

    /**
     * As perguntas feitas pelos alunos sobre esta aula.
     */
    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Verificar se a aula já foi completada por um usuário específico.
     *
     * @param int $userId
     * @return bool
     */
    public function isCompletedByUser($userId)
    {
        return $this->progress()
            ->where('user_id', $userId)
            ->where('is_completed', true)
            ->exists();
    }

    /**
     * Obter o curso ao qual esta aula pertence (através do módulo).
     */
    public function getCourseAttribute()
    {
        return $this->module->course;
    }
}

<?php
// Arquivo: app/Models/StudentProgress.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentProgress extends Model
{
    use HasFactory;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'lesson_id',
        'is_completed',
        'watched_seconds',
        'completed_at',
        'last_watched_at',
        'quiz_answers',
        'quiz_score',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_completed' => 'boolean',
        'watched_seconds' => 'integer',
        'completed_at' => 'datetime',
        'last_watched_at' => 'datetime',
        'quiz_answers' => 'array',
        'quiz_score' => 'integer',
    ];

    /**
     * O usuário (aluno) a quem este progresso pertence.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A aula à qual este progresso está associado.
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Marcar a aula como concluída.
     */
    public function markAsCompleted()
    {
        $this->is_completed = true;
        $this->completed_at = now();
        $this->save();

        // Atualizar o progresso geral da inscrição
        $enrollment = Enrollment::where('user_id', $this->user_id)
            ->where('course_id', $this->lesson->module->course_id)
            ->first();

        if ($enrollment) {
            $enrollment->calculateProgress();
        }
    }

    /**
     * Atualizar o tempo de visualização.
     *
     * @param int $seconds
     */
    public function updateWatchedTime($seconds)
    {
        $this->watched_seconds = $seconds;
        $this->last_watched_at = now();

        // Verificar se o vídeo foi assistido quase completamente
        if (
            $this->lesson->type === 'video' &&
            $this->lesson->duration_in_minutes > 0 &&
            $seconds >= ($this->lesson->duration_in_minutes * 60 * 0.9)
        ) {
            $this->markAsCompleted();
        } else {
            $this->save();
        }
    }
}

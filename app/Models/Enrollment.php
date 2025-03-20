<?php
// Arquivo: app/Models/Enrollment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
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
        'status',
        'paid_amount',
        'transaction_id',
        'payment_method',
        'expires_at',
        'completed_at',
        'progress_percentage',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'paid_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress_percentage' => 'integer',
    ];

    /**
     * O usuário (aluno) inscrito.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O curso em que o usuário está inscrito.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Verificar se a inscrição está ativa.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status === 'active' &&
            ($this->expires_at === null || now()->lt($this->expires_at));
    }

    /**
     * Verificar se o curso foi concluído.
     *
     * @return bool
     */
    public function isCompleted()
    {
        return $this->status === 'completed' && $this->completed_at !== null;
    }

    /**
     * Calcular e atualizar o progresso do aluno no curso.
     */
    public function calculateProgress()
    {
        $totalLessons = $this->course->getLessonsCountAttribute();

        if ($totalLessons === 0) {
            $this->progress_percentage = 0;
            $this->save();
            return;
        }

        // Contar aulas completadas pelo aluno neste curso
        $completedLessons = StudentProgress::where('user_id', $this->user_id)
            ->whereHas('lesson.module', function ($query) {
                $query->where('course_id', $this->course_id);
            })
            ->where('is_completed', true)
            ->count();

        $progressPercentage = ($completedLessons / $totalLessons) * 100;

        $this->progress_percentage = min(100, round($progressPercentage));

        // Se o progresso for 100%, marcar como concluído
        if ($this->progress_percentage === 100 && $this->status !== 'completed') {
            $this->status = 'completed';
            $this->completed_at = now();
        }

        $this->save();

        // Se o curso foi concluído, gerar certificado
        if ($this->isCompleted()) {
            // Verificar se já existe um certificado
            $certificateExists = Certificate::where('user_id', $this->user_id)
                ->where('course_id', $this->course_id)
                ->exists();

            if (!$certificateExists) {
                // Gerar certificado (usando job para processamento assíncrono)
                GenerateCertificate::dispatch($this->user_id, $this->course_id);
            }
        }
    }
}

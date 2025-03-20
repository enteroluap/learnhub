<?php
// Arquivo: app/Models/Question.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'lesson_id',
        'question',
        'answer',
        'answered_by',
        'answered_at',
        'is_resolved',
        'is_public',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'answered_at' => 'datetime',
        'is_resolved' => 'boolean',
        'is_public' => 'boolean',
    ];

    /**
     * O usuário que fez a pergunta.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A aula à qual esta pergunta está associada.
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * O usuário que respondeu à pergunta.
     */
    public function answeredBy()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /**
     * Responder à pergunta.
     *
     * @param string $answer
     * @param int $answeredById
     */
    public function addAnswer($answer, $answeredById)
    {
        $this->answer = $answer;
        $this->answered_by = $answeredById;
        $this->answered_at = now();
        $this->is_resolved = true;
        $this->save();
    }
}

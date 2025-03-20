<?php
// Arquivo: app/Models/AiInteraction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiInteraction extends Model
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
        'query',
        'response',
        'model_used',
        'tokens_used',
        'was_helpful',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tokens_used' => 'decimal:2',
        'was_helpful' => 'boolean',
    ];

    /**
     * O usuário que fez a pergunta.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A aula à qual esta interação está associada (se houver).
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Marcar se a resposta da IA foi útil.
     *
     * @param bool $wasHelpful
     */
    public function markHelpfulness($wasHelpful)
    {
        $this->was_helpful = $wasHelpful;
        $this->save();
    }
}

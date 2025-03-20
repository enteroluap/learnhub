<?php
// Arquivo: app/Models/Transaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'course_id',
        'transaction_id',
        'amount',
        'status',
        'payment_method',
        'payment_gateway',
        'gateway_response',
        'metadata',
        'paid_at',
        'invoice_id',
        'refund_id',
        'refund_reason',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    /**
     * O usuário que realizou a transação.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O curso que foi comprado (se aplicável).
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Verificar se a transação foi bem-sucedida.
     *
     * @return bool
     */
    public function isSuccessful()
    {
        return $this->status === 'completed' && $this->paid_at !== null;
    }

    /**
     * Verificar se a transação foi reembolsada.
     *
     * @return bool
     */
    public function isRefunded()
    {
        return $this->status === 'refunded' && $this->refund_id !== null;
    }

    /**
     * Processar reembolso.
     *
     * @param string $reason
     * @return bool
     */
    public function processRefund($reason = null)
    {
        if ($this->status !== 'completed') {
            return false;
        }

        // Lógica para processar o reembolso com o gateway de pagamento
        // ...

        $this->status = 'refunded';
        $this->refund_reason = $reason;
        $this->refund_id = 'REF-' . uniqid();
        $this->save();

        // Atualizar a inscrição do curso
        $enrollment = Enrollment::where('user_id', $this->user_id)
            ->where('course_id', $this->course_id)
            ->first();

        if ($enrollment) {
            $enrollment->status = 'canceled';
            $enrollment->save();
        }

        return true;
    }
}

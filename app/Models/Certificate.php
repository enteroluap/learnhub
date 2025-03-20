<?php
// Arquivo: app/Models/Certificate.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
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
        'certificate_number',
        'file_path',
        'issued_at',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'issued_at' => 'datetime',
    ];

    /**
     * O usuário que recebeu o certificado.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O curso para o qual o certificado foi emitido.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Gerar um número de certificado único.
     *
     * @param int $userId
     * @param int $courseId
     * @return string
     */
    public static function generateCertificateNumber($userId, $courseId)
    {
        $prefix = 'CERT';
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));

        return "{$prefix}-{$userId}-{$courseId}-{$timestamp}-{$random}";
    }

    /**
     * Obter URL completa do arquivo do certificado.
     *
     * @return string|null
     */
    public function getFileUrlAttribute()
    {
        if ($this->file_path) {
            return asset('storage/' . $this->file_path);
        }

        return null;
    }
}

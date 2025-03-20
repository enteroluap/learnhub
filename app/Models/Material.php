<?php
// Arquivo: app/Models/Material.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'lesson_id',
        'title',
        'description',
        'file_path',
        'file_type',
        'file_size',
        'is_downloadable',
        'download_count',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'file_size' => 'integer',
        'is_downloadable' => 'boolean',
        'download_count' => 'integer',
    ];

    /**
     * A aula à qual este material pertence.
     */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Incrementar o contador de downloads.
     */
    public function incrementDownloadCount()
    {
        $this->increment('download_count');
    }

    /**
     * Obter URL completa do arquivo.
     *
     * @return string
     */
    public function getFileUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }

    /**
     * Obter o tamanho do arquivo formatado (KB, MB, etc).
     *
     * @return string
     */
    public function getFormattedFileSizeAttribute()
    {
        if ($this->file_size < 1024) {
            return $this->file_size . ' bytes';
        } elseif ($this->file_size < 1048576) {
            return round($this->file_size / 1024, 2) . ' KB';
        } else {
            return round($this->file_size / 1048576, 2) . ' MB';
        }
    }
}

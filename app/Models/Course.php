<?php
// Arquivo: app/Models/Course.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'slug',
        'description',
        'short_description',
        'requirements',
        'what_will_learn',
        'instructor_id',
        'category_id',
        'thumbnail',
        'cover_image',
        'promotional_video_url',
        'price',
        'discount_price',
        'discount_ends_at',
        'duration_in_minutes',
        'level',
        'is_featured',
        'is_published',
        'average_rating',
        'ratings_count',
        'students_count',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
        'discount_ends_at' => 'datetime',
        'duration_in_minutes' => 'integer',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'average_rating' => 'decimal:2',
        'ratings_count' => 'integer',
        'students_count' => 'integer',
    ];

    /**
     * O instrutor que criou este curso.
     */
    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /**
     * A categoria a que este curso pertence.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Os módulos que compõem este curso.
     */
    public function modules()
    {
        return $this->hasMany(Module::class)->orderBy('order');
    }

    /**
     * Os alunos inscritos neste curso.
     */
    public function students()
    {
        return $this->belongsToMany(User::class, 'enrollments')
            ->withPivot('status', 'paid_amount', 'transaction_id', 'payment_method', 'expires_at', 'completed_at')
            ->withTimestamps();
    }

    /**
     * As avaliações deste curso.
     */
    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * Os certificados emitidos para este curso.
     */
    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * Verificar se o curso está em promoção.
     *
     * @return bool
     */
    public function isOnSale()
    {
        return $this->discount_price !== null &&
            $this->discount_ends_at !== null &&
            now()->lt($this->discount_ends_at);
    }

    /**
     * Obter o preço atual do curso (com desconto se aplicável).
     *
     * @return float
     */
    public function getCurrentPrice()
    {
        if ($this->isOnSale()) {
            return $this->discount_price;
        }

        return $this->price;
    }

    /**
     * Contar o número total de aulas no curso.
     *
     * @return int
     */
    public function getLessonsCountAttribute()
    {
        return Lesson::whereHas('module', function ($query) {
            $query->where('course_id', $this->id);
        })->count();
    }

    /**
     * Calcular a duração total do curso em minutos.
     *
     * @return int
     */
    public function getTotalDurationAttribute()
    {
        return $this->modules()->sum('duration_in_minutes');
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_image',
        'phone',
        'document_number',
        'bio',
        'preferences',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'is_admin',
        'is_instructor',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'preferences' => 'array',
        'is_admin' => 'boolean',
        'is_instructor' => 'boolean',
    ];
    /**
     * Obter o identificador que será armazenado na reivindicação do JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Retorna um array de chave-valor contendo quaisquer reivindicações
     * personalizadas a serem adicionadas ao JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Cursos que este usuário criou (como instrutor).
     */
    public function coursesCreated()
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    /**
     * Cursos em que este usuário está inscrito (como aluno).
     */
    public function enrolledCourses()
    {
        return $this->belongsToMany(Course::class, 'enrollments')
            ->withPivot('status', 'paid_amount', 'transaction_id', 'payment_method', 'expires_at', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Progresso do aluno em aulas.
     */
    public function progress()
    {
        return $this->hasMany(StudentProgress::class);
    }

    /**
     * Perguntas feitas pelo usuário.
     */
    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Avaliações feitas pelo usuário.
     */
    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * Transações financeiras do usuário.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Certificados obtidos pelo usuário.
     */
    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * Interações do usuário com a IA.
     */
    public function aiInteractions()
    {
        return $this->hasMany(AiInteraction::class);
    }

    /**
     * Determina se o usuário é administrador.
     */
    public function isAdmin()
    {
        return $this->is_admin;
    }

    /**
     * Determina se o usuário é instrutor.
     */
    public function isInstructor()
    {
        return $this->is_instructor;
    }
}

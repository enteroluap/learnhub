<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Models\Enrollment;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateCertificate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID do usuário.
     *
     * @var int
     */
    protected $userId;

    /**
     * ID do curso.
     *
     * @var int
     */
    protected $courseId;

    /**
     * Número de tentativas máximas do job.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Criar uma nova instância do job.
     *
     * @param  int  $userId
     * @param  int  $courseId
     * @return void
     */
    public function __construct($userId, $courseId)
    {
        $this->userId = $userId;
        $this->courseId = $courseId;

        // Definir a conexão para RabbitMQ
        $this->onConnection('rabbitmq');

        // Definir a fila para geração de certificados
        $this->onQueue('certificates');
    }

    /**
     * Executar o job.
     *
     * @return void
     */
    public function handle()
    {
        // Verificar se já existe um certificado para este curso e usuário
        $existingCertificate = Certificate::where('user_id', $this->userId)
            ->where('course_id', $this->courseId)
            ->first();

        if ($existingCertificate) {
            Log::info('Certificado já existe', [
                'user_id' => $this->userId,
                'course_id' => $this->courseId,
                'certificate_id' => $existingCertificate->id
            ]);
            return;
        }

        // Verificar se o usuário concluiu o curso
        $enrollment = Enrollment::where('user_id', $this->userId)
            ->where('course_id', $this->courseId)
            ->where('status', 'completed')
            ->first();

        if (!$enrollment) {
            Log::warning('Tentativa de gerar certificado para curso não concluído', [
                'user_id' => $this->userId,
                'course_id' => $this->courseId
            ]);
            return;
        }

        // Buscar dados do usuário e do curso
        $user = User::find($this->userId);
        $course = Course::find($this->courseId);

        if (!$user || !$course) {
            Log::error('Usuário ou curso não encontrado', [
                'user_id' => $this->userId,
                'course_id' => $this->courseId
            ]);
            return;
        }

        try {
            // Gerar número do certificado
            $certificateNumber = Certificate::generateCertificateNumber($this->userId, $this->courseId);

            // Preparar dados para o template
            $data = [
                'user_name' => $user->name,
                'course_name' => $course->title,
                'certificate_number' => $certificateNumber,
                'completion_date' => $enrollment->completed_at->format('d/m/Y'),
                'instructor_name' => $course->instructor->name,
                'course_duration' => $this->formatDuration($course->duration_in_minutes),
                'issue_date' => Carbon::now()->format('d/m/Y'),
            ];

            // Gerar PDF do certificado usando template
            $pdf = PDF::loadView('certificates.template', $data);

            // Definir metadados do PDF
            $pdf->getDomPDF()->set_option('isPhpEnabled', true);
            $pdf->getDomPDF()->set_option('isHtml5ParserEnabled', true);
            $pdf->getDomPDF()->set_option('isRemoteEnabled', true);

            // Definir caminho para salvar o arquivo
            $path = 'certificates/' . $this->userId;
            $fileName = 'certificate_' . $this->courseId . '_' . time() . '.pdf';
            $filePath = $path . '/' . $fileName;

            // Salvar o PDF no storage
            Storage::disk('public')->put($filePath, $pdf->output());

            // Criar o registro do certificado no banco de dados
            $certificate = Certificate::create([
                'user_id' => $this->userId,
                'course_id' => $this->courseId,
                'certificate_number' => $certificateNumber,
                'file_path' => $filePath,
                'issued_at' => Carbon::now(),
            ]);

            // Registrar log de sucesso
            Log::info('Certificado gerado com sucesso', [
                'user_id' => $this->userId,
                'course_id' => $this->courseId,
                'certificate_id' => $certificate->id
            ]);

            // Enviar e-mail ao usuário informando sobre o certificado (simulado)
            // Mail::to($user->email)->send(new CertificateGenerated($certificate));

        } catch (\Exception $e) {
            Log::error('Erro ao gerar certificado', [
                'user_id' => $this->userId,
                'course_id' => $this->courseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Lançar a exceção para que o job possa ser tentado novamente
            throw $e;
        }
    }

    /**
     * Formatar a duração do curso em horas e minutos.
     *
     * @param  int  $minutes
     * @return string
     */
    private function formatDuration($minutes)
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return $hours . ' hora' . ($hours > 1 ? 's' : '') .
                ($mins > 0 ? ' e ' . $mins . ' minuto' . ($mins > 1 ? 's' : '') : '');
        }

        return $mins . ' minuto' . ($mins > 1 ? 's' : '');
    }

    /**
     * Manipular o job falho.
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        Log::error('Falha no job de geração de certificado', [
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}

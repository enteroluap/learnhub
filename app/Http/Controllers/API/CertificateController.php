<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Jobs\GenerateCertificate;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CertificateController extends Controller
{
    /**
     * Criar uma nova instância de controller.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Listar certificados do usuário.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $user = auth()->user();

        $certificates = Certificate::with(['course:id,title,instructor_id,thumbnail', 'course.instructor:id,name'])
            ->where('user_id', $user->id)
            ->orderBy('issued_at', 'desc')
            ->get();

        // Adicionar URLs de download
        foreach ($certificates as $certificate) {
            $certificate->download_url = $certificate->file_path
                ? url('api/certificates/' . $certificate->id . '/download')
                : null;
        }

        return response()->json($certificates);
    }

    /**
     * Exibir detalhes de um certificado específico.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = auth()->user();
        $certificate = Certificate::with(['course:id,title,instructor_id,thumbnail', 'course.instructor:id,name'])
            ->where('id', $id)
            ->first();

        // Verificar permissões (apenas proprietário ou admin)
        if (!$certificate || ($certificate->user_id !== $user->id && !$user->is_admin)) {
            return response()->json(['error' => 'Certificado não encontrado'], 404);
        }

        // Adicionar URL de download
        $certificate->download_url = $certificate->file_path
            ? url('api/certificates/' . $certificate->id . '/download')
            : null;

        return response()->json($certificate);
    }

    /**
     * Baixar um certificado.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function download($id)
    {
        $user = auth()->user();
        $certificate = Certificate::find($id);

        // Verificar permissões (apenas proprietário ou admin)
        if (!$certificate || ($certificate->user_id !== $user->id && !$user->is_admin)) {
            return response()->json(['error' => 'Certificado não encontrado'], 404);
        }

        // Verificar se o arquivo existe
        if (!$certificate->file_path || !Storage::disk('public')->exists($certificate->file_path)) {
            // Se o arquivo não existe, tentamos gerá-lo novamente
            return $this->regenerate($id);
        }

        // Obter o nome do arquivo
        $fileName = 'certificado-' . $certificate->certificate_number . '.pdf';

        // Retornar o arquivo para download
        return response()->download(storage_path('app/public/' . $certificate->file_path), $fileName);
    }

    /**
     * Verificar a validade de um certificado (rota pública).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request)
    {
        // Validação
        $request->validate([
            'certificate_number' => 'required|string'
        ]);

        $certificateNumber = $request->certificate_number;

        // Buscar o certificado
        $certificate = Certificate::with(['user:id,name', 'course:id,title'])
            ->where('certificate_number', $certificateNumber)
            ->first();

        if (!$certificate) {
            return response()->json([
                'valid' => false,
                'message' => 'Certificado não encontrado'
            ]);
        }

        // Retornar informações básicas para verificação
        return response()->json([
            'valid' => true,
            'certificate_number' => $certificate->certificate_number,
            'student_name' => $certificate->user->name,
            'course_title' => $certificate->course->title,
            'issue_date' => $certificate->issued_at->format('d/m/Y')
        ]);
    }

    /**
     * Gerar novamente um certificado.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function regenerate($id)
    {
        $user = auth()->user();
        $certificate = Certificate::with('course')->find($id);

        // Verificar permissões (apenas proprietário ou admin)
        if (!$certificate || ($certificate->user_id !== $user->id && !$user->is_admin)) {
            return response()->json(['error' => 'Certificado não encontrado'], 404);
        }

        // Verificar se o curso foi concluído
        $enrollment = Enrollment::where('user_id', $certificate->user_id)
            ->where('course_id', $certificate->course_id)
            ->where('status', 'completed')
            ->first();

        if (!$enrollment && !$user->is_admin) {
            return response()->json([
                'error' => 'Você precisa completar o curso para gerar o certificado'
            ], 400);
        }

        try {
            // Preparar dados para o template
            $courseUser = $certificate->user_id === $user->id ? $user : $certificate->user;

            $data = [
                'user_name' => $courseUser->name,
                'course_name' => $certificate->course->title,
                'certificate_number' => $certificate->certificate_number,
                'completion_date' => $enrollment ? $enrollment->completed_at->format('d/m/Y') : Carbon::now()->format('d/m/Y'),
                'instructor_name' => $certificate->course->instructor->name,
                'course_duration' => $this->formatDuration($certificate->course->duration_in_minutes),
                'issue_date' => Carbon::now()->format('d/m/Y'),
            ];

            // Gerar PDF
            $pdf = PDF::loadView('certificates.template', $data);

            // Definir metadados do PDF
            $pdf->getDomPDF()->set_option('isPhpEnabled', true);
            $pdf->getDomPDF()->set_option('isHtml5ParserEnabled', true);
            $pdf->getDomPDF()->set_option('isRemoteEnabled', true);

            // Definir caminho para salvar o arquivo
            $path = 'certificates/' . $certificate->user_id;
            $fileName = 'certificate_' . $certificate->course_id . '_' . time() . '.pdf';
            $filePath = $path . '/' . $fileName;

            // Verificar se o diretório existe, criar se não
            if (!Storage::disk('public')->exists($path)) {
                Storage::disk('public')->makeDirectory($path);
            }

            // Salvar o PDF no storage
            Storage::disk('public')->put($filePath, $pdf->output());

            // Atualizar o registro do certificado
            $oldPath = $certificate->file_path;
            $certificate->file_path = $filePath;
            $certificate->save();

            // Remover arquivo antigo, se existir
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            // Se foi chamado pelo método download, retornar o arquivo
            if (request()->routeIs('certificates.download')) {
                $fileName = 'certificado-' . $certificate->certificate_number . '.pdf';
                return response()->download(storage_path('app/public/' . $filePath), $fileName);
            }

            return response()->json([
                'message' => 'Certificado regenerado com sucesso',
                'certificate' => $certificate,
                'download_url' => url('api/certificates/' . $certificate->id . '/download')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Falha ao regenerar certificado',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Solicitar geração de certificado para um curso concluído.
     *
     * @param  int  $courseId
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate($courseId)
    {
        $user = auth()->user();

        // Verificar se o usuário já possui um certificado para este curso
        $existingCertificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if ($existingCertificate) {
            return response()->json([
                'message' => 'Você já possui um certificado para este curso',
                'certificate' => $existingCertificate,
                'download_url' => url('api/certificates/' . $existingCertificate->id . '/download')
            ]);
        }

        // Verificar se o curso foi concluído
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'error' => 'Você não está inscrito neste curso'
            ], 400);
        }

        if ($enrollment->status !== 'completed') {
            return response()->json([
                'error' => 'Você precisa completar o curso para obter o certificado',
                'progress' => $enrollment->progress_percentage . '%'
            ], 400);
        }

        // Disparar job para gerar o certificado
        GenerateCertificate::dispatch($user->id, $courseId);

        return response()->json([
            'message' => 'Solicitação de certificado recebida. O certificado estará disponível em breve.'
        ]);
    }

    /**
     * Listar certificados (para administradores).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function adminList(Request $request)
    {
        // Verificar permissões
        if (!auth()->user()->is_admin) {
            return response()->json(['error' => 'Acesso não autorizado'], 403);
        }

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 15);
        $search = $request->get('search', '');
        $courseId = $request->get('course_id');

        // Consulta base
        $query = Certificate::with(['user:id,name,email', 'course:id,title']);

        // Filtrar por termo de busca
        if (!empty($search)) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
                ->orWhereHas('course', function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%");
                })
                ->orWhere('certificate_number', 'like', "%{$search}%");
        }

        // Filtrar por curso
        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        // Ordenar e paginar
        $certificates = $query->orderBy('issued_at', 'desc')
            ->paginate($perPage);

        return response()->json($certificates);
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
}

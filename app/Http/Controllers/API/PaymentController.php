<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Enrollment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * URL base da API Vindi (gateway de pagamento).
     * Em produção, isso seria configurado no arquivo .env
     *
     * @var string
     */
    protected $vindiApiUrl = 'https://app.vindi.com.br/api/v1/';

    /**
     * Chave de API da Vindi.
     * Em produção, isso seria obtido do arquivo .env
     *
     * @var string
     */
    protected $vindiApiKey;

    /**
     * Criar uma nova instância de controller.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth:api');

        // Em produção, obter a chave da configuração
        $this->vindiApiKey = config('services.vindi.api_key', 'sua_chave_de_api_vindi');
    }

    /**
     * Processar um novo pagamento.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function process(Request $request)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'course_id' => 'required|exists:courses,id',
            'payment_method' => 'required|in:credit_card,debit_card,boleto,pix',
            'card_number' => 'required_if:payment_method,credit_card,debit_card|nullable|string|size:16',
            'card_holder_name' => 'required_if:payment_method,credit_card,debit_card|nullable|string',
            'card_expiration_month' => 'required_if:payment_method,credit_card,debit_card|nullable|numeric|between:1,12',
            'card_expiration_year' => 'required_if:payment_method,credit_card,debit_card|nullable|numeric|min:' . date('Y'),
            'card_cvv' => 'required_if:payment_method,credit_card,debit_card|nullable|string|size:3',
            'installments' => 'nullable|integer|min:1|max:12',
            'coupon_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Obter usuário autenticado
        $user = auth()->user();

        // Encontrar o curso
        $course = Course::findOrFail($request->course_id);

        // Verificar se o curso está publicado
        if (!$course->is_published) {
            return response()->json(['error' => 'Este curso não está disponível para compra'], 400);
        }

        // Verificar se o usuário já está inscrito neste curso
        $existingEnrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $request->course_id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if ($existingEnrollment) {
            return response()->json(['error' => 'Você já está inscrito neste curso'], 400);
        }

        // Determinar o preço a ser pago (considerando promoções)
        $amount = $course->getCurrentPrice();

        // Se o preço for zero, processar como uma inscrição gratuita
        if ($amount == 0) {
            // Criar uma nova inscrição gratuita
            $enrollment = Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $request->course_id,
                'status' => 'active',
                'paid_amount' => 0,
                'payment_method' => 'free',
                'expires_at' => now()->addYears(10), // Longo prazo para cursos gratuitos
            ]);

            // Incrementar contador de alunos no curso
            Course::where('id', $request->course_id)->increment('students_count');

            return response()->json([
                'message' => 'Inscrição gratuita realizada com sucesso',
                'enrollment' => $enrollment
            ], 201);
        }

        // Para pagamentos, precisaremos chamar o gateway de pagamento
        try {
            // Esta é uma implementação simulada da integração com a Vindi
            // Em um ambiente real, você faria uma chamada HTTP para a API da Vindi

            // Simular uma resposta de transação bem-sucedida
            $transactionId = 'TRX-' . Str::random(10);
            $paymentStatus = 'completed';

            // Em um cenário real, você processaria o pagamento assim:
            /*
            $response = Http::withBasicAuth($this->vindiApiKey, '')
                ->post($this->vindiApiUrl . 'charges', [
                    'customer_id' => $this->getOrCreateVindiCustomer($user),
                    'payment_method_code' => $this->mapPaymentMethod($request->payment_method),
                    'amount' => $amount,
                    'installments' => $request->installments ?? 1,
                    'description' => "Compra do curso: {$course->title}",
                    'credit_card' => [
                        'holder_name' => $request->card_holder_name,
                        'card_number' => $request->card_number,
                        'card_expiration' => $request->card_expiration_month . '/' . $request->card_expiration_year,
                        'security_code' => $request->card_cvv,
                    ],
                ]);
                
            if ($response->successful()) {
                $responseData = $response->json();
                $transactionId = $responseData['charge']['id'];
                $paymentStatus = $this->mapVindiStatus($responseData['charge']['status']);
            } else {
                Log::error('Erro no processamento de pagamento Vindi', [
                    'response' => $response->json(),
                    'user_id' => $user->id,
                    'course_id' => $course->id
                ]);
                
                return response()->json([
                    'error' => 'Erro no processamento do pagamento',
                    'message' => $response->json()['errors'] ?? 'Tente novamente mais tarde'
                ], 422);
            }
            */

            // Registrar a transação no banco de dados
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'course_id' => $request->course_id,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $paymentStatus,
                'payment_method' => $request->payment_method,
                'payment_gateway' => 'vindi',
                'paid_at' => now(),
                'metadata' => [
                    'installments' => $request->installments ?? 1,
                    'coupon_code' => $request->coupon_code,
                ]
            ]);

            // Se o pagamento foi bem-sucedido, criar a inscrição
            if ($paymentStatus === 'completed') {
                $enrollment = Enrollment::create([
                    'user_id' => $user->id,
                    'course_id' => $request->course_id,
                    'status' => 'active',
                    'paid_amount' => $amount,
                    'transaction_id' => $transactionId,
                    'payment_method' => $request->payment_method,
                    'expires_at' => now()->addYear(), // 1 ano de acesso
                ]);

                // Incrementar contador de alunos no curso
                Course::where('id', $request->course_id)->increment('students_count');

                return response()->json([
                    'message' => 'Pagamento processado com sucesso',
                    'transaction' => $transaction,
                    'enrollment' => $enrollment
                ], 201);
            } else {
                // Se o pagamento falhou mas foi registrado
                return response()->json([
                    'message' => 'Pagamento registrado mas não aprovado',
                    'transaction' => $transaction,
                    'status' => $paymentStatus
                ], 202);
            }
        } catch (\Exception $e) {
            Log::error('Erro no processamento de pagamento', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'course_id' => $course->id
            ]);

            return response()->json([
                'error' => 'Falha ao processar o pagamento',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar o status de uma transação.
     *
     * @param  string  $transactionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkStatus($transactionId)
    {
        // Obter usuário autenticado
        $user = auth()->user();

        // Buscar a transação
        $transaction = Transaction::where('transaction_id', $transactionId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Em um cenário real, você verificaria o status atual na API da Vindi
        /*
        $response = Http::withBasicAuth($this->vindiApiKey, '')
            ->get($this->vindiApiUrl . 'charges/' . $transactionId);
            
        if ($response->successful()) {
            $responseData = $response->json();
            $currentStatus = $this->mapVindiStatus($responseData['charge']['status']);
            
            // Atualizar o status no banco de dados, se diferente
            if ($currentStatus !== $transaction->status) {
                $transaction->status = $currentStatus;
                $transaction->save();
            }
        }
        */

        return response()->json([
            'transaction_id' => $transaction->transaction_id,
            'status' => $transaction->status,
            'payment_method' => $transaction->payment_method,
            'amount' => $transaction->amount,
            'paid_at' => $transaction->paid_at,
            'course_id' => $transaction->course_id,
            'course_title' => $transaction->course ? $transaction->course->title : null
        ]);
    }

    /**
     * Obter o histórico de transações do usuário.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        // Obter usuário autenticado
        $user = auth()->user();

        // Parâmetros de paginação e filtragem
        $perPage = $request->get('per_page', 10);
        $status = $request->get('status'); // completed, pending, refunded, failed
        $courseId = $request->get('course_id');

        // Consulta base
        $query = Transaction::where('user_id', $user->id);

        // Filtrar por status
        if ($status) {
            $query->where('status', $status);
        }

        // Filtrar por curso
        if ($courseId) {
            $query->where('course_id', $courseId);
        }

        // Ordenar e paginar
        $transactions = $query->with('course:id,title,thumbnail')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Solicitar reembolso de uma transação.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $transactionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestRefund(Request $request, $transactionId)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Obter usuário autenticado
        $user = auth()->user();

        // Buscar a transação
        $transaction = Transaction::where('transaction_id', $transactionId)
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->firstOrFail();

        // Verificar período de reembolso (por exemplo, 7 dias após o pagamento)
        $refundPeriod = now()->subDays(7);
        if ($transaction->paid_at && $transaction->paid_at < $refundPeriod) {
            return response()->json([
                'error' => 'Período de reembolso expirado',
                'message' => 'Reembolsos só são aceitos até 7 dias após o pagamento'
            ], 400);
        }

        // Em um cenário real, você processaria o reembolso na API da Vindi
        /*
        $response = Http::withBasicAuth($this->vindiApiKey, '')
            ->post($this->vindiApiUrl . 'charges/' . $transactionId . '/refund');
            
        if (!$response->successful()) {
            return response()->json([
                'error' => 'Falha ao processar o reembolso',
                'message' => $response->json()['errors'] ?? 'Tente novamente mais tarde'
            ], 422);
        }
        */

        // Simulação de processamento bem-sucedido
        $refundId = 'REF-' . Str::random(10);

        // Atualizar o status da transação
        $transaction->status = 'refunded';
        $transaction->refund_id = $refundId;
        $transaction->refund_reason = $request->reason;
        $transaction->save();

        // Atualizar a inscrição relacionada
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $transaction->course_id)
            ->where('transaction_id', $transactionId)
            ->first();

        if ($enrollment) {
            $enrollment->status = 'canceled';
            $enrollment->save();

            // Decrementar contador de alunos no curso
            Course::where('id', $transaction->course_id)->decrement('students_count');
        }

        return response()->json([
            'message' => 'Reembolso processado com sucesso',
            'refund_id' => $refundId,
            'transaction' => $transaction
        ]);
    }

    /**
     * Verificar se um cupom é válido e aplicar desconto.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateCoupon(Request $request)
    {
        // Validação dos dados de entrada
        $validator = Validator::make($request->all(), [
            'coupon_code' => 'required|string',
            'course_id' => 'required|exists:courses,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Em um sistema real, você teria uma tabela de cupons
        // Esta é uma implementação simulada
        $validCoupons = [
            'WELCOME10' => ['discount' => 10, 'type' => 'percentage', 'expires_at' => '2025-12-31'],
            'SUMMER20' => ['discount' => 20, 'type' => 'percentage', 'expires_at' => '2025-12-31'],
            'NEWSTUDENT' => ['discount' => 15, 'type' => 'fixed', 'expires_at' => '2025-12-31'],
        ];

        $couponCode = strtoupper($request->coupon_code);

        // Verificar se o cupom existe
        if (!isset($validCoupons[$couponCode])) {
            return response()->json([
                'valid' => false,
                'message' => 'Cupom inválido ou expirado'
            ]);
        }

        $coupon = $validCoupons[$couponCode];

        // Verificar se o cupom ainda é válido
        if (Carbon::parse($coupon['expires_at'])->isPast()) {
            return response()->json([
                'valid' => false,
                'message' => 'Cupom expirado'
            ]);
        }

        // Encontrar o curso
        $course = Course::findOrFail($request->course_id);

        // Determinar o preço original
        $originalPrice = $course->getCurrentPrice();

        // Calcular o desconto
        $discountAmount = 0;
        if ($coupon['type'] === 'percentage') {
            $discountAmount = ($originalPrice * $coupon['discount']) / 100;
        } else {
            $discountAmount = $coupon['discount'];
        }

        // Garantir que o desconto não torne o preço negativo
        $discountAmount = min($discountAmount, $originalPrice);

        // Calcular o preço final
        $finalPrice = $originalPrice - $discountAmount;

        return response()->json([
            'valid' => true,
            'coupon_code' => $couponCode,
            'discount_type' => $coupon['type'],
            'discount_value' => $coupon['discount'],
            'discount_amount' => $discountAmount,
            'original_price' => $originalPrice,
            'final_price' => $finalPrice,
            'message' => "Cupom aplicado com sucesso! Desconto de " .
                ($coupon['type'] === 'percentage' ? $coupon['discount'] . '%' : 'R$ ' . $coupon['discount'])
        ]);
    }

    /**
     * Mapear método de pagamento para o formato aceito pela Vindi.
     *
     * @param  string  $method
     * @return string
     */
    private function mapPaymentMethod($method)
    {
        $map = [
            'credit_card' => 'credit_card',
            'debit_card' => 'debit_card',
            'boleto' => 'bank_slip',
            'pix' => 'pix',
        ];

        return $map[$method] ?? $method;
    }

    /**
     * Mapear status da Vindi para o formato interno.
     *
     * @param  string  $vindiStatus
     * @return string
     */
    private function mapVindiStatus($vindiStatus)
    {
        $map = [
            'paid' => 'completed',
            'pending' => 'pending',
            'canceled' => 'failed',
            'refunded' => 'refunded',
        ];

        return $map[$vindiStatus] ?? 'pending';
    }

    /**
     * Obter ou criar cliente na Vindi.
     *
     * @param  \App\Models\User  $user
     * @return string
     */
    private function getOrCreateVindiCustomer($user)
    {
        // Em um cenário real, você verificaria se o usuário já tem um ID de cliente na Vindi
        // e criaria um novo se necessário

        // Esta é uma implementação simulada
        return 'customer_' . $user->id;
    }
}

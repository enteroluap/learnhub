<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentProgress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\TopicConf;

// Definir constantes do RdKafka como globais se não estiverem disponíveis
if (!defined('RD_KAFKA_RESP_ERR_NO_ERROR')) {
    \define('RD_KAFKA_RESP_ERR_NO_ERROR', 0);
}
if (!defined('RD_KAFKA_RESP_ERR__PARTITION_EOF')) {
    \define('RD_KAFKA_RESP_ERR__PARTITION_EOF', -191);
}
if (!defined('RD_KAFKA_RESP_ERR__TIMED_OUT')) {
    \define('RD_KAFKA_RESP_ERR__TIMED_OUT', -185);
}
if (!defined('RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS')) {
    \define('RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS', -175);
}
if (!defined('RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS')) {
    \define('RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS', -174);
}
if (!defined('RD_KAFKA_PARTITION_UA')) {
    \define('RD_KAFKA_PARTITION_UA', -1);
}

class ProcessEngagementData extends Command
{
    /**
     * O nome e a assinatura do comando de console.
     *
     * @var string
     */
    protected $signature = 'kafka:process-engagement';

    /**
     * A descrição do comando de console.
     *
     * @var string
     */
    protected $description = 'Processa dados de engajamento dos alunos usando Kafka';

    /**
     * Tópico Kafka para processar.
     *
     * @var string
     */
    protected $topic = 'student-engagement';

    /**
     * Grupo de consumidor.
     *
     * @var string
     */
    protected $consumerGroup = 'engagement-analyzer';

    /**
     * Brokers Kafka (configuração).
     *
     * @var string
     */
    protected $brokers = 'localhost:9092';

    /**
     * Criar uma nova instância do comando.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // Em produção, obter a configuração do .env
        $this->brokers = config('kafka.brokers', 'localhost:9092');
    }

    /**
     * Executar o comando de console.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando processamento de dados de engajamento...');

        try {
            // Configurar o consumidor Kafka
            $conf = new Conf();
            $conf->set('group.id', $this->consumerGroup);
            $conf->set('metadata.broker.list', $this->brokers);
            $conf->set('auto.offset.reset', 'smallest');

            // Definir callback para processo de rebalanceamento
            $conf->setRebalanceCb(function (Consumer $kafka, $err, array $partitions = null) {
                if ($err == RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS) {
                    $this->info('Partições atribuídas: ' . json_encode($partitions));
                    $kafka->assign($partitions);
                } else if ($err == RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS) {
                    $this->info('Partições revogadas: ' . json_encode($partitions));
                    $kafka->assign(NULL);
                } else {
                    $this->error('Erro de rebalanceamento: ' . $err);
                }
            });

            // Configurar o tópico
            $topicConf = new TopicConf();
            $topicConf->set('auto.commit.interval.ms', '1000');
            $topicConf->set('auto.offset.reset', 'smallest');

            // Criar consumidor
            $consumer = new \RdKafka\KafkaConsumer($conf);

            // Inscrever-se no tópico
            $consumer->subscribe([$this->topic]);

            $this->info('Aguardando mensagens do tópico: ' . $this->topic);

            // Loop de processamento
            while (true) {
                // Consumir mensagem com timeout
                $message = $consumer->consume(10000);

                // Verificar se há uma mensagem
                if ($message === null) {
                    continue;
                }

                // Processar com base no código de resposta
                switch ($message->err) {
                    case RD_KAFKA_RESP_ERR_NO_ERROR:
                        // Processar a mensagem
                        $this->processMessage($message->payload);
                        break;

                    case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                        // Fim da partição, nada para processar
                        $this->info('Fim da partição: ' . json_encode($message));
                        break;

                    case RD_KAFKA_RESP_ERR__TIMED_OUT:
                        // Timeout, continuar
                        break;

                    default:
                        // Erro
                        $this->error('Erro ao consumir mensagem: ' . $message->errstr());
                        break;
                }

                // Verificar se o comando foi interrompido
                if ($this->isInterrupted()) {
                    $consumer->close();
                    $this->info('Interrompido pelo usuário.');
                    return 0;
                }
            }
        } catch (\Exception $e) {
            $this->error('Erro: ' . $e->getMessage());
            Log::error('Erro no processamento de dados de engajamento', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Processar uma mensagem do Kafka.
     *
     * @param  string  $payload
     * @return void
     */
    private function processMessage($payload)
    {
        $this->info('Processando mensagem: ' . $payload);

        try {
            // Decodificar a mensagem JSON
            $data = json_decode($payload, true);

            if (!$data || !isset($data['event_type'])) {
                $this->warn('Mensagem inválida: ' . $payload);
                return;
            }

            // Processar com base no tipo de evento
            switch ($data['event_type']) {
                case 'lesson_viewed':
                    $this->processLessonView($data);
                    break;

                case 'lesson_completed':
                    $this->processLessonCompletion($data);
                    break;

                case 'course_enrollment':
                    $this->processCourseEnrollment($data);
                    break;

                case 'course_completion':
                    $this->processCourseCompletion($data);
                    break;

                case 'user_login':
                    $this->processUserLogin($data);
                    break;

                default:
                    $this->warn('Tipo de evento desconhecido: ' . $data['event_type']);
                    break;
            }
        } catch (\Exception $e) {
            $this->error('Erro ao processar mensagem: ' . $e->getMessage());
            Log::error('Erro ao processar mensagem de engajamento', [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Processar evento de visualização de aula.
     *
     * @param  array  $data
     * @return void
     */
    private function processLessonView($data)
    {
        // Verificar dados necessários
        if (!isset($data['user_id'], $data['lesson_id'], $data['viewed_seconds'])) {
            $this->warn('Dados incompletos para processamento de visualização de aula');
            return;
        }

        // Atualizar o progresso do aluno
        $progress = StudentProgress::firstOrNew([
            'user_id' => $data['user_id'],
            'lesson_id' => $data['lesson_id'],
        ]);

        // Atualizar o tempo assistido (manter o maior valor)
        $progress->watched_seconds = max($progress->watched_seconds ?? 0, $data['viewed_seconds']);
        $progress->last_watched_at = Carbon::now();

        // Verificar se o aluno completou a aula (assistiu pelo menos 90% do vídeo)
        if (isset($data['lesson_duration']) && $data['lesson_duration'] > 0) {
            $completionThreshold = $data['lesson_duration'] * 0.9;

            if ($progress->watched_seconds >= $completionThreshold) {
                $progress->is_completed = true;
                $progress->completed_at = Carbon::now();
            }
        }

        $progress->save();

        // Atualizar o progresso geral da inscrição
        $this->updateEnrollmentProgress($data['user_id'], $data['course_id'] ?? null);

        $this->info('Progresso da aula atualizado para o usuário ' . $data['user_id']);
    }

    /**
     * Processar evento de conclusão de aula.
     *
     * @param  array  $data
     * @return void
     */
    private function processLessonCompletion($data)
    {
        // Verificar dados necessários
        if (!isset($data['user_id'], $data['lesson_id'])) {
            $this->warn('Dados incompletos para processamento de conclusão de aula');
            return;
        }

        // Atualizar o progresso do aluno
        $progress = StudentProgress::firstOrNew([
            'user_id' => $data['user_id'],
            'lesson_id' => $data['lesson_id'],
        ]);

        // Marcar como concluído
        $progress->is_completed = true;
        $progress->completed_at = Carbon::now();
        $progress->save();

        // Atualizar o progresso geral da inscrição
        $this->updateEnrollmentProgress($data['user_id'], $data['course_id'] ?? null);

        $this->info('Aula marcada como concluída para o usuário ' . $data['user_id']);
    }

    /**
     * Processar evento de inscrição em curso.
     *
     * @param  array  $data
     * @return void
     */
    private function processCourseEnrollment($data)
    {
        // Verificar dados necessários
        if (!isset($data['user_id'], $data['course_id'])) {
            $this->warn('Dados incompletos para processamento de inscrição em curso');
            return;
        }

        // Registrar evento de engajamento (pode ser útil para análises futuras)
        DB::table('engagement_events')->insert([
            'user_id' => $data['user_id'],
            'course_id' => $data['course_id'],
            'event_type' => 'enrollment',
            'created_at' => Carbon::now(),
            'metadata' => json_encode([
                'payment_method' => $data['payment_method'] ?? null,
                'source' => $data['source'] ?? 'web',
                'referrer' => $data['referrer'] ?? null,
            ]),
        ]);

        $this->info('Evento de inscrição registrado para o usuário ' . $data['user_id']);
    }

    /**
     * Processar evento de conclusão de curso.
     *
     * @param  array  $data
     * @return void
     */
    private function processCourseCompletion($data)
    {
        // Verificar dados necessários
        if (!isset($data['user_id'], $data['course_id'])) {
            $this->warn('Dados incompletos para processamento de conclusão de curso');
            return;
        }

        // Registrar evento de engajamento
        DB::table('engagement_events')->insert([
            'user_id' => $data['user_id'],
            'course_id' => $data['course_id'],
            'event_type' => 'completion',
            'created_at' => Carbon::now(),
            'metadata' => json_encode([
                'total_time_seconds' => $data['total_time_seconds'] ?? null,
                'days_to_complete' => $data['days_to_complete'] ?? null,
            ]),
        ]);

        // Verificar se já foi emitido um certificado
        $hasCertificate = DB::table('certificates')
            ->where('user_id', $data['user_id'])
            ->where('course_id', $data['course_id'])
            ->exists();

        // Se não há certificado, disparar job para gerar
        if (!$hasCertificate) {
            // Em um cenário real, você usaria:
            // GenerateCertificate::dispatch($data['user_id'], $data['course_id']);
            $this->info('Job de geração de certificado seria disparado para o usuário ' . $data['user_id']);
        }

        $this->info('Evento de conclusão de curso registrado para o usuário ' . $data['user_id']);
    }

    /**
     * Processar evento de login do usuário.
     *
     * @param  array  $data
     * @return void
     */
    private function processUserLogin($data)
    {
        // Verificar dados necessários
        if (!isset($data['user_id'])) {
            $this->warn('Dados incompletos para processamento de login de usuário');
            return;
        }

        // Atualizar último login do usuário
        User::where('id', $data['user_id'])->update([
            'last_login_at' => Carbon::now()
        ]);

        // Registrar evento de engajamento
        DB::table('engagement_events')->insert([
            'user_id' => $data['user_id'],
            'event_type' => 'login',
            'created_at' => Carbon::now(),
            'metadata' => json_encode([
                'ip' => $data['ip'] ?? null,
                'device' => $data['device'] ?? null,
                'platform' => $data['platform'] ?? null,
            ]),
        ]);

        // Identificar cursos não concluídos para enviar lembretes
        $this->checkForIncompleteCoursesReminders($data['user_id']);

        $this->info('Evento de login registrado para o usuário ' . $data['user_id']);
    }

    /**
     * Atualizar o progresso geral da inscrição.
     *
     * @param  int  $userId
     * @param  int|null  $courseId
     * @return void
     */
    private function updateEnrollmentProgress($userId, $courseId = null)
    {
        // Se o courseId não foi fornecido, precisamos buscá-lo a partir da lição
        if (!$courseId) {
            $this->warn('courseId não fornecido, isso pode ser ineficiente');
            return;
        }

        // Buscar a inscrição
        $enrollment = Enrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->first();

        if (!$enrollment) {
            $this->warn('Inscrição não encontrada para o usuário ' . $userId . ' no curso ' . $courseId);
            return;
        }

        // Calcular o progresso
        $enrollment->calculateProgress();

        $this->info('Progresso da inscrição atualizado para o usuário ' . $userId);
    }

    /**
     * Verificar cursos incompletos para enviar lembretes.
     *
     * @param  int  $userId
     * @return void
     */
    private function checkForIncompleteCoursesReminders($userId)
    {
        // Buscar inscrições ativas com mais de 7 dias sem atividade
        $inactiveEnrollments = Enrollment::where('user_id', $userId)
            ->where('status', 'active')
            ->where('progress_percentage', '<', 100)
            ->whereRaw('DATE(updated_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)')
            ->get();

        if ($inactiveEnrollments->isEmpty()) {
            return;
        }

        foreach ($inactiveEnrollments as $enrollment) {
            // Em um cenário real, você dispararia um job para enviar um e-mail ou notificação
            // SendCourseReminderEmail::dispatch($userId, $enrollment->course_id);
            $this->info('Lembrete de curso seria enviado para o usuário ' . $userId . ' sobre o curso ' . $enrollment->course_id);
        }
    }

    /**
     * Verificar se o comando foi interrompido.
     *
     * @return bool
     */
    private function isInterrupted()
    {
        // Verificar por sinais de interrupção
        return function_exists('pcntl_signal_dispatch') && pcntl_signal_dispatch();
    }
}

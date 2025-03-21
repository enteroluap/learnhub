<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\Producer;

// Definir constantes do RdKafka como globais se não estiverem disponíveis
if (!defined('RD_KAFKA_PARTITION_UA')) {
    // Usamos define() no namespace global
    \define('RD_KAFKA_PARTITION_UA', -1);
}

class KafkaProducerService
{
    /**
     * Instância do produtor RdKafka.
     *
     * @var \RdKafka\Producer
     */
    protected $producer;

    /**
     * Brokers Kafka (configuração).
     *
     * @var string
     */
    protected $brokers;

    /**
     * Criar uma nova instância do serviço.
     *
     * @return void
     */
    public function __construct()
    {
        $this->brokers = config('kafka.brokers', 'localhost:9092');
        $this->initProducer();
    }

    /**
     * Inicializar o produtor Kafka.
     *
     * @return void
     */
    protected function initProducer()
    {
        try {
            $conf = new Conf();
            $conf->set('metadata.broker.list', $this->brokers);

            // Configurações adicionais
            $conf->set('socket.keepalive.enable', 'true');
            $conf->set('socket.timeout.ms', '5000');
            $conf->set('queue.buffering.max.messages', '100000');
            $conf->set('queue.buffering.max.ms', '1000');
            $conf->set('batch.num.messages', '1000');

            // Configurar callback de entrega para registrar erros
            $conf->setDrMsgCb(function ($kafka, $message) {
                if ($message->err) {
                    Log::error('Kafka message delivery failed: ' . $message->errstr());
                }
            });

            $this->producer = new Producer($conf);

            // Poll para manter as callbacks funcionando
            $this->producer->poll(0);
        } catch (\Exception $e) {
            Log::error('Failed to initialize Kafka producer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Publicar uma mensagem em um tópico Kafka.
     *
     * @param  string  $topic
     * @param  array  $data
     * @param  string|null  $key
     * @return bool
     */
    public function publish($topic, array $data, $key = null)
    {
        try {
            // Converter dados para JSON
            $payload = json_encode($data);

            // Criar/obter tópico
            $kafkaTopic = $this->producer->newTopic($topic);

            // Produzir mensagem
            $kafkaTopic->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);

            // Executar o poll para lidar com eventos e callbacks
            $this->producer->poll(0);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to publish Kafka message: ' . $e->getMessage(), [
                'topic' => $topic,
                'data' => $data
            ]);

            return false;
        }
    }

    /**
     * Publicar evento de visualização de aula.
     *
     * @param  int  $userId
     * @param  int  $lessonId
     * @param  int  $courseId
     * @param  int  $viewedSeconds
     * @param  int|null  $lessonDuration
     * @return bool
     */
    public function publishLessonView($userId, $lessonId, $courseId, $viewedSeconds, $lessonDuration = null)
    {
        $data = [
            'event_type' => 'lesson_viewed',
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'course_id' => $courseId,
            'viewed_seconds' => $viewedSeconds,
            'lesson_duration' => $lessonDuration,
            'timestamp' => time()
        ];

        return $this->publish('student-engagement', $data, 'user_' . $userId);
    }

    /**
     * Publicar evento de conclusão de aula.
     *
     * @param  int  $userId
     * @param  int  $lessonId
     * @param  int  $courseId
     * @return bool
     */
    public function publishLessonCompletion($userId, $lessonId, $courseId)
    {
        $data = [
            'event_type' => 'lesson_completed',
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'course_id' => $courseId,
            'timestamp' => time()
        ];

        return $this->publish('student-engagement', $data, 'user_' . $userId);
    }

    /**
     * Publicar evento de inscrição em curso.
     *
     * @param  int  $userId
     * @param  int  $courseId
     * @param  string|null  $paymentMethod
     * @param  string|null  $source
     * @return bool
     */
    public function publishCourseEnrollment($userId, $courseId, $paymentMethod = null, $source = null)
    {
        $data = [
            'event_type' => 'course_enrollment',
            'user_id' => $userId,
            'course_id' => $courseId,
            'payment_method' => $paymentMethod,
            'source' => $source ?? 'web',
            'timestamp' => time()
        ];

        return $this->publish('student-engagement', $data, 'user_' . $userId);
    }

    /**
     * Publicar evento de conclusão de curso.
     *
     * @param  int  $userId
     * @param  int  $courseId
     * @param  int|null  $totalTimeSeconds
     * @param  int|null  $daysToComplete
     * @return bool
     */
    public function publishCourseCompletion($userId, $courseId, $totalTimeSeconds = null, $daysToComplete = null)
    {
        $data = [
            'event_type' => 'course_completion',
            'user_id' => $userId,
            'course_id' => $courseId,
            'total_time_seconds' => $totalTimeSeconds,
            'days_to_complete' => $daysToComplete,
            'timestamp' => time()
        ];

        return $this->publish('student-engagement', $data, 'user_' . $userId);
    }

    /**
     * Publicar evento de login de usuário.
     *
     * @param  int  $userId
     * @param  string|null  $ip
     * @param  string|null  $device
     * @param  string|null  $platform
     * @return bool
     */
    public function publishUserLogin($userId, $ip = null, $device = null, $platform = null)
    {
        $data = [
            'event_type' => 'user_login',
            'user_id' => $userId,
            'ip' => $ip,
            'device' => $device,
            'platform' => $platform,
            'timestamp' => time()
        ];

        return $this->publish('student-engagement', $data, 'user_' . $userId);
    }

    /**
     * Publicar evento de interação com IA.
     *
     * @param  int  $userId
     * @param  int|null  $lessonId
     * @param  string  $query
     * @param  int|null  $tokensUsed
     * @return bool
     */
    public function publishAiInteraction($userId, $lessonId, $query, $tokensUsed = null)
    {
        $data = [
            'event_type' => 'ai_interaction',
            'user_id' => $userId,
            'lesson_id' => $lessonId,
            'query' => $query,
            'tokens_used' => $tokensUsed,
            'timestamp' => time()
        ];

        return $this->publish('student-engagement', $data, 'user_' . $userId);
    }
}

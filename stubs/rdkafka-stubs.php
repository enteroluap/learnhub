<?php

/**
 * Este arquivo contém stubs (definições de classes) para a extensão rdkafka.
 * Essas definições são apenas para IDEs como PHPStorm e intelephense
 * e não afetam o funcionamento real do código.
 * 
 * @package RdKafka
 */

namespace RdKafka {

    /**
     * Configuração para o produtor/consumidor Kafka.
     */
    class Conf
    {
        /**
         * Define uma propriedade de configuração.
         *
         * @param string $name
         * @param string $value
         * @return void
         */
        public function set($name, $value) {}

        /**
         * Define o callback para o rebalanceamento de partições.
         *
         * @param callable $callback
         * @return void
         */
        public function setRebalanceCb($callback) {}

        /**
         * Define o callback para mensagens entregues.
         *
         * @param callable $callback
         * @return void
         */
        public function setDrMsgCb($callback) {}

        /**
         * Define o callback para erros.
         *
         * @param callable $callback
         * @return void
         */
        public function setErrorCb($callback) {}
    }

    /**
     * Configuração de tópico Kafka.
     */
    class TopicConf
    {
        /**
         * Define uma propriedade de configuração do tópico.
         *
         * @param string $name
         * @param string $value
         * @return void
         */
        public function set($name, $value) {}
    }

    /**
     * Produtor Kafka.
     */
    class Producer
    {
        /**
         * Construtor.
         *
         * @param Conf $conf
         */
        public function __construct(Conf $conf) {}

        /**
         * Adiciona brokers ao produtor.
         *
         * @param string $brokerList
         * @return int
         */
        public function addBrokers($brokerList) {}

        /**
         * Cria um novo objeto de tópico.
         *
         * @param string $topic
         * @return Topic
         */
        public function newTopic($topic) {}

        /**
         * Executa o poll para processar eventos de entrega e callbacks.
         *
         * @param int $timeout_ms
         * @return int
         */
        public function poll($timeout_ms) {}
    }

    /**
     * Tópico Kafka.
     */
    class Topic
    {
        /**
         * Produz uma mensagem no tópico.
         *
         * @param int $partition
         * @param int $msgflags
         * @param string $payload
         * @param string $key
         * @return void
         */
        public function produce($partition, $msgflags, $payload, $key = null) {}
    }

    /**
     * Consumidor Kafka.
     */
    class Consumer
    {
        /**
         * Construtor.
         *
         * @param Conf $conf
         */
        public function __construct(Conf $conf) {}

        /**
         * Adiciona brokers ao consumidor.
         *
         * @param string $brokerList
         * @return int
         */
        public function addBrokers($brokerList) {}

        /**
         * Atribui partições para o consumidor.
         *
         * @param array $partitions
         * @return void
         */
        public function assign($partitions = null) {}
    }

    /**
     * Consumidor Kafka orientado a tópicos.
     */
    class KafkaConsumer
    {
        /**
         * Construtor.
         *
         * @param Conf $conf
         */
        public function __construct(Conf $conf) {}

        /**
         * Assina os tópicos fornecidos.
         *
         * @param array $topics
         * @return void
         */
        public function subscribe(array $topics) {}

        /**
         * Consome mensagens.
         *
         * @param int $timeout_ms
         * @return Message|null
         */
        public function consume($timeout_ms) {}

        /**
         * Fecha o consumidor.
         *
         * @return void
         */
        public function close() {}
    }

    /**
     * Mensagem Kafka.
     */
    class Message
    {
        /**
         * Código de erro da mensagem.
         *
         * @var int
         */
        public $err;

        /**
         * Tópico da mensagem.
         *
         * @var string
         */
        public $topic_name;

        /**
         * Partição da mensagem.
         *
         * @var int
         */
        public $partition;

        /**
         * Payload da mensagem.
         *
         * @var string
         */
        public $payload;

        /**
         * Chave da mensagem.
         *
         * @var string
         */
        public $key;

        /**
         * Offset da mensagem.
         *
         * @var int
         */
        public $offset;

        /**
         * Timestamp da mensagem.
         *
         * @var int
         */
        public $timestamp;

        /**
         * Obtém a descrição do erro.
         *
         * @return string
         */
        public function errstr() {}
    }
}

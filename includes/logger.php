<?php

/**
 * Sistema de logging com suporte a Telegram
 */
class Logger
{
    private static $instance = null;
    private $logFile;
    private $telegramEnabled = false;
    private $telegramToken = '';
    private $telegramChatId = '';
    private $messageBuffer = [];
    private $taskName = '';

    private function __construct()
    {
        $this->logFile = __DIR__ . '/../logs/tasks_' . date('Y-m') . '.log';
        $this->ensureLogDirectory();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }

    /**
     * Configura o logger a partir da configuração
     */
    public function configure($config)
    {
        // Configurações gerais
        if (isset($config['settings']['timezone'])) {
            date_default_timezone_set($config['settings']['timezone']);
        }

        // Configurações de log
        if (isset($config['logs']['enabled']) && !$config['logs']['enabled']) {
            $this->telegramEnabled = false;
            return;
        }

        // Telegram
        if (isset($config['logs']['send']) && $config['logs']['send'] === 'telegram') {
            if (isset($config['logs']['bot_token']) && isset($config['logs']['chat_id'])) {
                $this->telegramEnabled = true;
                $this->telegramToken = $config['logs']['bot_token'];
                $this->telegramChatId = $config['logs']['chat_id'];
            }
        }

        // Arquivo de log personalizado
        if (isset($config['logs']['log_file'])) {
            $this->logFile = __DIR__ . '/../' . ltrim($config['logs']['log_file'], '/');
            $this->ensureLogDirectory();
        }
    }

    /**
     * Configura o Telegram para envio de logs
     */
    public function configureTelegram($config)
    {
        if (isset($config['logs']['send']) && $config['logs']['send'] === 'telegram') {
            if (isset($config['logs']['bot_token']) && isset($config['logs']['chat_id'])) {
                $this->telegramEnabled = true;
                $this->telegramToken = $config['logs']['bot_token'];
                $this->telegramChatId = $config['logs']['chat_id'];
                return true;
            }
        }
        return false;
    }

    /**
     * Define o nome da tarefa atual
     */
    public function setTaskName($taskName)
    {
        $this->taskName = $taskName;
    }

    /**
     * Registra uma mensagem
     */
    public function log($message, $level = 'INFO')
    {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] [{$level}] {$message}";

        // Adiciona ao buffer
        $this->messageBuffer[] = $formattedMessage;

        // Exibe no console
        echo $formattedMessage . "\n";

        // Salva no arquivo de log
        $this->saveToFile($formattedMessage);

        // Se temos muitas mensagens no buffer, envia para o Telegram
        if (count($this->messageBuffer) >= 5) {
            $this->flushBuffer();
        }
    }

    /**
     * Envia todas as mensagens pendentes para o Telegram
     */
    public function flushBuffer()
    {
        if (empty($this->messageBuffer) || !$this->telegramEnabled) {
            return;
        }

        $messageText = "📊 *Tarefa:* {$this->taskName}\n";
        $messageText .= "📅 *Data:* " . date('d/m/Y H:i:s') . "\n";
        $messageText .= "────────────────────\n";

        foreach ($this->messageBuffer as $msg) {
            // Remove timestamp do log para o Telegram (fica mais limpo)
            $cleanMsg = preg_replace('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[(\w+)\] /', '', $msg);
            $messageText .= "• {$cleanMsg}\n";
        }

        $this->sendToTelegram($messageText);
        $this->messageBuffer = [];
    }

    /**
     * Envia mensagem imediatamente para o Telegram
     */
    public function sendImmediate($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] {$message}";

        echo $formattedMessage . "\n";
        $this->saveToFile($formattedMessage);

        if ($this->telegramEnabled) {
            $this->sendToTelegram($formattedMessage);
        }
    }

    /**
     * Envia mensagem de sucesso final
     */
    public function sendSuccess($taskName, $executionTime = null, $backupInfo = null)
    {
        $message = "✅ *BACKUP CONCLUÍDO COM SUCESSO!*\n\n";
        $message .= "📋 *Tarefa:* {$taskName}\n";
        $message .= "⏰ *Horário:* " . date('d/m/Y H:i:s') . "\n";

        if ($executionTime !== null) {
            $message .= "⏱️ *Duração:* {$executionTime}s\n";
        }

        if ($backupInfo !== null) {
            $message .= "💾 *Backup:* {$backupInfo}\n";
        }

        $this->sendToTelegram($message);
    }

    /**
     * Envia mensagem de erro
     */
    public function sendError($taskName, $errorMessage)
    {
        $message = "❌ *ERRO NO BACKUP!*\n\n";
        $message .= "📋 *Tarefa:* {$taskName}\n";
        $message .= "⏰ *Horário:* " . date('d/m/Y H:i:s') . "\n";
        $message .= "🚨 *Erro:* {$errorMessage}\n";

        $this->sendToTelegram($message);
    }

    /**
     * Testa a conexão com o Telegram
     */
    public function testTelegram()
    {
        if (!$this->telegramEnabled) {
            return "Telegram não configurado. Verifique o config.json.";
        }

        $message = "🔧 *Teste de Conexão Telegram*\n\n";
        $message .= "✅ Configuração carregada com sucesso!\n";
        $message .= "🤖 Bot Token: " . substr($this->telegramToken, 0, 10) . "...\n";
        $message .= "💬 Chat ID: {$this->telegramChatId}\n";
        $message .= "⏰ Data/Hora: " . date('d/m/Y H:i:s');

        return $this->sendToTelegram($message, true);
    }

    /**
     * Envia mensagem para o Telegram
     */
    private function sendToTelegram($message, $isTest = false)
    {
        if (!$this->telegramEnabled) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->telegramToken}/sendMessage";

        $data = [
            'chat_id' => $this->telegramChatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data)
            ]
        ];

        $context = stream_context_create($options);

        try {
            $result = file_get_contents($url, false, $context);
            $response = json_decode($result, true);

            if ($isTest) {
                if ($response['ok']) {
                    return "✅ Teste do Telegram realizado com sucesso!\nMensagem enviada para o chat.";
                } else {
                    return "❌ Erro ao enviar mensagem: " . ($response['description'] ?? 'Desconhecido');
                }
            }

            return $response['ok'] ?? false;
        } catch (Exception $e) {
            if ($isTest) {
                return "❌ Erro de conexão: " . $e->getMessage();
            }
            return false;
        }
    }

    /**
     * Salva mensagem no arquivo de log
     */
    private function saveToFile($message)
    {
        @file_put_contents($this->logFile, $message . "\n", FILE_APPEND);
    }

    /**
     * Garante que o diretório de logs existe
     */
    private function ensureLogDirectory()
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
}

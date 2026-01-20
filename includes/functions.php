<?php

/**
 * Arquivo de funções auxiliares para o sistema de tarefas
 */

/**
 * Processa argumentos da linha de comando
 */
function parseArguments($argv)
{
    $params = [];

    foreach ($argv as $arg) {
        if ($arg === $argv[0]) continue;

        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);

            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $params[$key] = $value;
            } else {
                $params[$arg] = true;
            }
        } elseif (strpos($arg, '-') === 0) {
            $arg = substr($arg, 1);
            $params[$arg] = true;
        }
    }

    return $params;
}

/**
 * Exibe a mensagem de ajuda
 */
function showHelp($appName, $version)
{
    echo "{$appName} v{$version}\n\n";
    echo "Uso: php tasks.php --task=nome_da_tarefa [OPÇÕES]\n\n";
    echo "Opções:\n";
    echo "  --task=<nome>         Nome da tarefa (definida em config.json -> tasks)\n";
    echo "  --config=<arquivo>    Arquivo de configuração\n";
    echo "                        (padrão: procura config.local.json, config.json, config.json.example)\n";
    echo "  --list-tasks          Lista todas as tarefas disponíveis\n";
    echo "  --test-telegram       Testa conexão completa com o Telegram\n";
    echo "  --validate-token      Valida apenas o token do bot\n";
    echo "  --create-example      Cria config.json a partir do exemplo\n";
    echo "  --help, -h            Exibe esta mensagem de ajuda\n";
    echo "  --version, -v         Exibe a versão do script\n\n";
    echo "Exemplos:\n";
    echo "  php tasks.php --task=db.exemplo\n";
    echo "  php tasks.php --task=meu_banco --config=config.producao.json\n";
    echo "  php tasks.php --list-tasks\n";
    echo "  php tasks.php --test-telegram\n";
    echo "  php tasks.php --create-example\n";
    echo "  php tasks.php --help\n";
}

/**
 * Exibe a versão do script
 */
function showVersion($appName, $version)
{
    echo "{$appName} v{$version}\n";
}

/**
 * Valida se um parâmetro obrigatório está presente
 */
function validateRequiredParam($params, $required, $appName, $version)
{
    if (!isset($params[$required]) || empty($params[$required])) {
        echo "Erro: O parâmetro '{$required}' é obrigatório.\n\n";
        showHelp($appName, $version);
        return false;
    }
    return true;
}

/**
 * Carrega o arquivo de configuração JSON
 */
function loadConfig($configFile)
{
    if (!file_exists($configFile)) {
        echo "❌ Arquivo de configuração não encontrado: {$configFile}\n";

        // Sugere criar a partir do exemplo
        $exampleFile = dirname($configFile) . '/config.json.example';
        if (file_exists($exampleFile)) {
            echo "\n💡 Dica: Crie um config.json a partir do exemplo:\n";
            echo "   cp config.json.example config.json\n";
            echo "   Ou: php tasks.php --create-example\n";
        }

        return false;
    }

    // Avisa se está usando o arquivo de exemplo
    if (isExampleConfig($configFile)) {
        echo "⚠️  AVISO: Usando arquivo de exemplo config.json.example\n";
        echo "   Crie um config.json com suas configurações reais\n";
        echo "   Comando: php tasks.php --create-example\n\n";
    }

    $configContent = file_get_contents($configFile);
    if ($configContent === false) {
        echo "❌ Erro ao ler o arquivo de configuração.\n";
        return false;
    }

    $config = json_decode($configContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "❌ Erro ao decodificar JSON: " . json_last_error_msg() . "\n";
        return false;
    }

    // Valida a estrutura básica
    if (!isset($config['tasks']) || !is_array($config['tasks'])) {
        echo "⚠️  Aviso: Estrutura 'tasks' não encontrada no arquivo de configuração.\n";
        echo "   Esperado: {\"tasks\": {\"nome_tarefa\": {...}}}\n";

        // Tenta usar estrutura antiga (compatibilidade)
        if (!empty($config) && is_array($config)) {
            echo "   Usando estrutura antiga (sem 'tasks') para compatibilidade...\n";
            $config = ['tasks' => $config];
        } else {
            return false;
        }
    }

    return $config;
}
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

/**
 * Função auxiliar para logging (mantém compatibilidade)
 */
function displayMessage($message, $level = 'INFO')
{
    $logger = Logger::getInstance();
    $logger->log($message, $level);
}

/**
 * Função para enviar mensagem imediata
 */
function sendTelegramMessage($message)
{
    $logger = Logger::getInstance();
    $logger->sendImmediate($message);
}

/**
 * Verifica se um comando do sistema está disponível
 */
function commandExists($command)
{
    $os = strtoupper(substr(PHP_OS, 0, 3));

    if ($os === 'WIN') {
        // Windows
        $where = 'where';
        exec("{$where} {$command} 2>nul", $output, $returnCode);
    } else {
        // Linux/Unix/Mac
        $which = 'which';
        exec("{$which} {$command} 2>/dev/null", $output, $returnCode);
    }

    return $returnCode === 0;
}

/**
 * Cria um diretório se não existir
 */
function ensureDirectory($directory)
{
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true)) {
            throw new Exception("Não foi possível criar o diretório: {$directory}");
        }
        // Adiciona arquivo .htaccess para proteção (se for web)
        $htaccess = $directory . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }
    return $directory;
}

/**
 * Descriptografa uma senha (implementação básica)
 */
function decryptPassword($encryptedPassword, $key = null)
{
    // Se não começar com "enc:", assume que não está criptografada
    if (strpos($encryptedPassword, 'enc:') !== 0) {
        return $encryptedPassword;
    }

    // Implementação simples - EM PRODUÇÃO USE ALGO MAIS SEGURO
    $data = substr($encryptedPassword, 4);
    $parts = explode(':', $data);

    if (count($parts) === 2) {
        list($encrypted, $iv) = $parts;
        $iv = base64_decode($iv);
        $encrypted = base64_decode($encrypted);

        // Use uma chave padrão se não for fornecida
        $key = $key ?: 'chave_padrao_32_caracteres_123456789';

        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        if ($decrypted !== false) {
            return $decrypted;
        }
    }

    return $encryptedPassword;
}

/**
 * Encontra o caminho completo de um comando
 */
function findCommandPath($command)
{
    $os = strtoupper(substr(PHP_OS, 0, 3));

    if ($os === 'WIN') {
        // Windows
        exec("where {$command} 2>nul", $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return trim($output[0]);
        }
    } else {
        // Linux/Unix/Mac
        exec("which {$command} 2>/dev/null", $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return trim($output[0]);
        }

        // Tenta alguns caminhos comuns
        $commonPaths = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/homebrew/bin/mysqldump',
            '/bin/mysqldump',
            '/usr/sbin/mysqldump'
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
    }

    return null;
}

/**
 * Lista todas as tarefas disponíveis no arquivo de configuração
 */
function listTasks($configFile)
{
    $config = loadConfig($configFile);
    if ($config === false) {
        echo "Erro ao carregar configuração.\n";
        return false;
    }

    if (!isset($config['tasks']) || empty($config['tasks'])) {
        echo "Nenhuma tarefa configurada.\n";
        return true;
    }

    echo "Tarefas disponíveis em '{$configFile}':\n\n";

    foreach ($config['tasks'] as $taskName => $taskConfig) {
        echo "Nome: {$taskName}\n";
        echo "Tipo: " . ($taskConfig['task'] ?? 'Não especificado') . "\n";

        echo str_repeat('-', 40) . "\n";
    }

    echo "\nTotal: " . count($config['tasks']) . " tarefa(s)\n";

    return true;
}

/**
 * Escapa string para MySQL de forma segura, tratando valores nulos
 */
function safeEscapeString($mysqli, $value)
{
    if ($value === null) {
        return 'NULL';
    }

    // Converte para string se não for
    if (!is_string($value) && !is_numeric($value)) {
        $value = (string)$value;
    }

    return "'" . $mysqli->real_escape_string($value) . "'";
}

/**
 * Detecta se uma coluna MySQL é numérica
 */
function isColumnNumeric($columnType)
{
    if ($columnType === null) {
        return false;
    }

    $columnType = strtolower($columnType);
    $numericPatterns = [
        '/^tinyint/',
        '/^smallint/',
        '/^mediumint/',
        '/^int/',
        '/^bigint/',
        '/^decimal/',
        '/^float/',
        '/^double/',
        '/^real/',
        '/^bit/',
        '/^bool/',
        '/^boolean/'
    ];

    foreach ($numericPatterns as $pattern) {
        if (preg_match($pattern, $columnType)) {
            return true;
        }
    }

    return false;
}

/**
 * Obtém o diretório de backup para uma tarefa específica
 */
function getBackupDir($taskName)
{
    $baseDir = __DIR__ . '/../backups';
    $taskDir = $baseDir . '/' . sanitizeFilename($taskName);

    return ensureDirectory($taskDir);
}

/**
 * Sanitiza nome de arquivo/diretório
 */
function sanitizeFilename($filename)
{
    // Remove caracteres perigosos
    $filename = preg_replace('/[^\w\-\.]/', '_', $filename);

    // Remove múltiplos underscores
    $filename = preg_replace('/_+/', '_', $filename);

    // Remove underscores no início/fim
    $filename = trim($filename, '_');

    // Garante que não está vazio
    if (empty($filename)) {
        $filename = 'backup_' . date('Ymd_His');
    }

    return $filename;
}

/**
 * Formata bytes para formato legível
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Determina qual arquivo de configuração usar
 */
function getConfigFile($params)
{
    // Se especificado via parâmetro
    if (isset($params['config'])) {
        return $params['config'];
    }

    // Tenta arquivos na ordem de prioridade
    $possibleFiles = [
        __DIR__ . '/../config.local.json',  // Configuração local (não versionada)
        __DIR__ . '/../config.json',        // Configuração principal
        __DIR__ . '/../config.json.example' // Exemplo (somente leitura)
    ];

    foreach ($possibleFiles as $file) {
        if (file_exists($file)) {
            return $file;
        }
    }

    // Nenhum arquivo encontrado
    return __DIR__ . '/../config.json'; // Vai falhar, mas dá mensagem de erro boa
}

/**
 * Cria um arquivo de configuração de exemplo
 */
function createExampleConfig()
{
    $exampleFile = __DIR__ . '/../config.json.example';
    $targetFile = __DIR__ . '/../config.json';

    if (!file_exists($exampleFile)) {
        echo "❌ Arquivo de exemplo não encontrado: {$exampleFile}\n";
        return false;
    }

    if (file_exists($targetFile)) {
        echo "⚠️  Arquivo config.json já existe. Deseja sobrescrever? (s/N): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);

        if (trim(strtolower($line)) !== 's') {
            echo "❌ Operação cancelada.\n";
            return false;
        }
    }

    if (copy($exampleFile, $targetFile)) {
        echo "✅ Arquivo config.json criado com sucesso!\n";
        echo "   Edite o arquivo com suas configurações.\n";
        return true;
    } else {
        echo "❌ Erro ao criar config.json\n";
        return false;
    }
}

/**
 * Verifica se o arquivo de configuração é o exemplo
 */
function isExampleConfig($configFile)
{
    return basename($configFile) === 'config.json.example';
}

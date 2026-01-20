<?php

/**
 * tasks.php - Sistema de Tarefas Automatizadas
 * 
 * Uso: php tasks.php --task=nome_da_tarefa --config=config.json
 */

// Configurações da aplicação
$version = '1.2.0';
$appName = 'Sistema de Tarefas Automatizadas';

// Verifica se está sendo executado via CLI
if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado apenas via linha de comando.\n");
}

// Inclui o arquivo de funções
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';

// Processa os argumentos
$params = parseArguments($argv);

// Determina o arquivo de configuração
$configFile = getConfigFile($params);

// Carrega a configuração
$config = loadConfig($configFile);
if ($config === false) {
    echo "❌ Erro ao carregar o arquivo de configuração.\n";
    echo "   Arquivo tentado: {$configFile}\n";
    echo "   Crie um arquivo config.json baseado em config.json.example\n";
    exit(1);
}

// Configura o logger
$logger = Logger::getInstance();
$logger->configure($config);

// Verifica se foi solicitado teste do Telegram
if (isset($params['test-telegram'])) {
    $result = $logger->testTelegram();
    echo "\n" . $result . "\n";
    exit(0);
}

// Verifica se foi solicitada validação do token
if (isset($params['validate-token'])) {
    $result = $logger->validateBotToken();
    echo "\n" . $result . "\n";
    exit(0);
}

// Verifica se foi solicitada ajuda
if (isset($params['help']) || isset($params['h'])) {
    showHelp($appName, $version);
    exit(0);
}

// Verifica se foi solicitada versão
if (isset($params['version']) || isset($params['v'])) {
    showVersion($appName, $version);
    exit(0);
}

// Verifica se foi solicitada lista de tarefas
if (isset($params['list-tasks'])) {
    listTasks($configFile);
    exit(0);
}

// Verifica se foi solicitada criação de exemplo
if (isset($params['create-example'])) {
    createExampleConfig();
    exit(0);
}

// Valida se a tarefa foi especificada
if (!validateRequiredParam($params, 'task', $appName, $version)) {
    exit(1);
}

// Verifica se a estrutura tasks existe
if (!isset($config['tasks']) || !is_array($config['tasks'])) {
    echo "❌ Erro: Estrutura 'tasks' não encontrada no arquivo de configuração.\n";
    exit(1);
}

// Verifica se a tarefa existe na configuração
if (!isset($config['tasks'][$params['task']])) {
    echo "❌ Erro: A tarefa '{$params['task']}' não foi encontrada.\n";

    // Lista tarefas disponíveis
    $availableTasks = array_keys($config['tasks']);
    if (!empty($availableTasks)) {
        echo "   Tarefas disponíveis:\n";
        foreach ($availableTasks as $task) {
            echo "     - {$task}\n";
        }
    } else {
        echo "   Nenhuma tarefa configurada.\n";
    }

    exit(1);
}

// Obtém a configuração da tarefa
$taskConfig = $config['tasks'][$params['task']];

// Verifica se o tipo de tarefa foi especificado
if (!isset($taskConfig['task'])) {
    echo "❌ Erro: Tipo de tarefa não especificado para '{$params['task']}'.\n";
    exit(1);
}

// Configura o logger com o nome da tarefa
$logger->setTaskName($params['task']);

// Envia mensagem inicial para o Telegram
$logger->sendImmediate("🚀 *Iniciando backup: {$params['task']}*");

// Executa a tarefa baseada no tipo
$startTime = microtime(true);
$backupInfo = null;

try {
    $result = executeTask($params['task'], $taskConfig);
    $executionTime = round(microtime(true) - $startTime, 2);

    // Envia todas as mensagens pendentes
    $logger->flushBuffer();

    if ($result) {
        // Se temos informações do backup
        if (is_array($result) && isset($result['backup_file'])) {
            $backupInfo = basename($result['backup_file']);
            if (isset($result['file_size'])) {
                $backupInfo .= " (" . formatBytes($result['file_size']) . ")";
            }
        }

        // Envia mensagem de sucesso para o Telegram
        $logger->sendSuccess($params['task'], $executionTime, $backupInfo);

        echo "\n✅ Tarefa '{$params['task']}' executada com sucesso em {$executionTime}s!\n";
        exit(0);
    } else {
        // Envia mensagem de erro para o Telegram
        $logger->sendError($params['task'], "Erro desconhecido na execução");

        echo "\n❌ Erro ao executar a tarefa '{$params['task']}'.\n";
        exit(1);
    }
} catch (Exception $e) {
    $executionTime = round(microtime(true) - $startTime, 2);

    // Envia todas as mensagens pendentes
    $logger->flushBuffer();

    // Envia mensagem de erro para o Telegram
    $logger->sendError($params['task'], $e->getMessage());

    echo "\n❌ Erro na execução da tarefa '{$params['task']}':\n";
    echo "   {$e->getMessage()}\n";
    echo "   ⏱️ Tempo de execução: {$executionTime}s\n";
    exit(1);
}

<?php

/**
 * Implementação das tarefas
 */

/**
 * Executa uma tarefa baseada na configuração
 */
function executeTask($taskName, $taskConfig)
{
    displayMessage("Iniciando tarefa: {$taskName}");

    // Determina o tipo de tarefa
    $taskType = $taskConfig['task'];

    switch ($taskType) {
        case 'backup.database':
            return executeDatabaseBackup($taskName, $taskConfig);

        default:
            throw new Exception("Tipo de tarefa não suportado: {$taskType}");
    }
}

/**
 * Executa backup de banco de dados MySQL
 */
function executeDatabaseBackup($taskName, $config)
{
    displayMessage("Executando backup de banco de dados: {$taskName}");

    // Valida configurações obrigatórias
    $requiredFields = ['host', 'database', 'username', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($config[$field]) || empty($config[$field])) {
            throw new Exception("Campo obrigatório não encontrado: {$field}");
        }
    }

    // Obtém configurações de retenção (com valores padrão)
    $retentionDays = isset($config['retention_days']) ? (int)$config['retention_days'] : 30;
    $minBackups = isset($config['min_backups']) ? (int)$config['min_backups'] : 1;

    // Valida valores
    if ($retentionDays < 1) {
        $retentionDays = 1;
        displayMessage("Aviso: retention_days ajustado para 1 (valor mínimo)", "WARNING");
    }

    if ($minBackups < 0) {
        $minBackups = 0;
        displayMessage("Aviso: min_backups ajustado para 0", "WARNING");
    }

    displayMessage("Retenção: manter backups por {$retentionDays} dias, mínimo {$minBackups} arquivos");

    // Obtém diretório de backup específico da tarefa
    $backupDir = getBackupDir($taskName);
    displayMessage("Diretório de backup: {$backupDir}");

    // Define safeDbName ANTES de usar
    $safeDbName = sanitizeFilename($config['database']);
    displayMessage("Nome seguro do banco: {$safeDbName}");

    // Tenta encontrar o mysqldump
    $mysqldumpPath = findCommandPath('mysqldump');

    if (!$mysqldumpPath) {
        // Tenta usar mysqldump do PHP
        displayMessage("mysqldump não encontrado no PATH, tentando backup via PHP...");

        // Alternativa: Usar PHP para backup
        if (function_exists('mysqli_connect')) {
            $result = executeDatabaseBackupPHP($taskName, $config, $backupDir);

            // Retorna informações do backup
            if ($result && isset($result['backup_file'])) {
                return $result;
            }
            return true;
        }

        throw new Exception("Comando mysqldump não encontrado e backup via PHP não disponível.");
    }

    displayMessage("mysqldump encontrado em: {$mysqldumpPath}");

    // Verifica se gzip está disponível
    $gzipPath = findCommandPath('gzip');
    $useCompression = ($gzipPath !== null);

    if ($useCompression) {
        displayMessage("gzip encontrado, usando compressão");
    } else {
        displayMessage("gzip não encontrado, criando backup sem compressão", "WARNING");
    }

    // Descriptografa a senha se necessário
    $password = decryptPassword($config['password']);

    // Cria nome do arquivo de backup (já temos $safeDbName definido)
    $timestamp = date('Y-m-d_H-i-s');

    if ($useCompression) {
        $backupFile = $backupDir . '/' . $safeDbName . '_' . $timestamp . '.sql.gz';
    } else {
        $backupFile = $backupDir . '/' . $safeDbName . '_' . $timestamp . '.sql';
    }

    // Monta o comando mysqldump baseado nas opções configuradas
    $command = $mysqldumpPath;

    // Opções básicas obrigatórias
    $command .= sprintf(
        ' --host=%s --user=%s --password=%s',
        escapeshellarg($config['host']),
        escapeshellarg($config['username']),
        escapeshellarg($password)
    );

    // Porta (se especificada)
    if (isset($config['port']) && !empty($config['port'])) {
        $command .= ' --port=' . escapeshellarg($config['port']);
    }

    // Conjunto de caracteres (se especificado)
    if (isset($config['charset']) && !empty($config['charset'])) {
        $command .= ' --default-character-set=' . escapeshellarg($config['charset']);
    }

    // Opções de backup configuráveis
    $backupOptions = isset($config['backup_options']) ? $config['backup_options'] : [];

    // Valores padrão para as opções
    $defaultOptions = [
        'single_transaction' => true,
        'routines' => true,
        'triggers' => true,
        'events' => true,
        'hex_blob' => true,
        'complete_insert' => false,
        'lock_tables' => false,
        'add_drop_table' => true,
        'add_locks' => true,
        'comments' => true,
        'create_options' => true,
        'skip_comments' => false,
        'skip_extended_insert' => false,
        'skip_opt' => false,
        'skip_dump_date' => false,
        'skip_tz_utc' => false
    ];

    // Merge das opções com defaults
    $options = array_merge($defaultOptions, $backupOptions);

    // Adiciona opções baseadas na configuração
    $optionMessages = [];

    if ($options['single_transaction']) {
        $command .= ' --single-transaction';
        $optionMessages[] = "transação única";
    }

    if ($options['routines']) {
        $command .= ' --routines';
        $optionMessages[] = "procedures/functions";
    }

    if ($options['triggers']) {
        $command .= ' --triggers';
        $optionMessages[] = "triggers";
    }

    if ($options['events']) {
        $command .= ' --events';
        $optionMessages[] = "events";
    }

    if ($options['hex_blob']) {
        $command .= ' --hex-blob';
        $optionMessages[] = "blobs em hexadecimal";
    }

    if ($options['complete_insert']) {
        $command .= ' --complete-insert';
        $optionMessages[] = "INSERT completo";
    }

    if (!$options['lock_tables']) {
        $command .= ' --skip-lock-tables';
    } else {
        $optionMessages[] = "lock tables";
    }

    if ($options['add_drop_table']) {
        $command .= ' --add-drop-table';
        $optionMessages[] = "DROP TABLE";
    }

    if ($options['add_locks']) {
        $command .= ' --add-locks';
        $optionMessages[] = "LOCK TABLES";
    }

    if ($options['comments']) {
        $command .= ' --comments';
        $optionMessages[] = "comentários";
    }

    if ($options['create_options']) {
        $command .= ' --create-options';
        $optionMessages[] = "opções CREATE";
    }

    if ($options['skip_comments']) {
        $command .= ' --skip-comments';
    }

    if ($options['skip_extended_insert']) {
        $command .= ' --skip-extended-insert';
    }

    if ($options['skip_opt']) {
        $command .= ' --skip-opt';
    }

    if ($options['skip_dump_date']) {
        $command .= ' --skip-dump-date';
    } else {
        $command .= ' --dump-date';
        $optionMessages[] = "data no dump";
    }

    if ($options['skip_tz_utc']) {
        $command .= ' --skip-tz-utc';
    } else {
        $command .= ' --tz-utc';
        $optionMessages[] = "timezone UTC";
    }

    // Adiciona opções adicionais se suportadas
    if (checkMysqldumpOption($mysqldumpPath, '--column-statistics')) {
        $command .= ' --column-statistics=0';
    }

    // Mostra as opções ativas
    if (!empty($optionMessages)) {
        displayMessage("Opções ativas: " . implode(', ', $optionMessages));
    }

    // Nome do banco
    $command .= ' ' . escapeshellarg($config['database']);

    // Adiciona compressão se disponível
    if ($useCompression) {
        $command .= ' 2>/dev/null | ' . escapeshellarg($gzipPath);
    }

    $command .= ' > ' . escapeshellarg($backupFile);

    displayMessage("Executando comando de backup...");

    // Executa o backup
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        // Tenta com opções reduzidas se falhar
        displayMessage("Tentando com opções reduzidas...", "WARNING");

        // Comando básico sem opções avançadas
        $simpleCommand = sprintf(
            '%s --host=%s --user=%s --password=%s %s',
            escapeshellarg($mysqldumpPath),
            escapeshellarg($config['host']),
            escapeshellarg($config['username']),
            escapeshellarg($password),
            escapeshellarg($config['database'])
        );

        if ($useCompression) {
            $simpleCommand .= ' 2>/dev/null | ' . escapeshellarg($gzipPath);
        }

        $simpleCommand .= ' > ' . escapeshellarg($backupFile);

        exec($simpleCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            // Log do erro detalhado
            $errorDetails = implode("\n", $output);
            displayMessage("Detalhes do erro:\n" . $errorDetails, "ERROR");
            throw new Exception("Falha ao executar mysqldump (código: {$returnCode})");
        }
    }

    // Verifica se o arquivo foi criado
    if (!file_exists($backupFile) || filesize($backupFile) === 0) {
        throw new Exception("Arquivo de backup não foi criado ou está vazio: " . basename($backupFile));
    }

    $fileSize = filesize($backupFile);
    $fileSizeFormatted = formatBytes($fileSize);

    displayMessage("✓ Backup criado: " . basename($backupFile) . " ({$fileSizeFormatted})", "SUCCESS");

    // Registra estatísticas
    if ($options['routines'] || $options['triggers'] || $options['events']) {
        $backupContent = $useCompression ?
            shell_exec('zcat ' . escapeshellarg($backupFile) . ' | head -100') :
            file_get_contents($backupFile, false, null, 0, 5000);

        $stats = [];
        if ($options['routines'] && preg_match('/CREATE.*(PROCEDURE|FUNCTION)/i', $backupContent)) {
            $stats[] = "procedures/functions";
        }
        if ($options['triggers'] && preg_match('/CREATE.*TRIGGER/i', $backupContent)) {
            $stats[] = "triggers";
        }
        if ($options['events'] && preg_match('/CREATE.*EVENT/i', $backupContent)) {
            $stats[] = "events";
        }

        if (!empty($stats)) {
            displayMessage("✓ Inclui: " . implode(', ', $stats), "INFO");
        }
    }

    // Rotaciona backups antigos com configuração individual
    $deletedCount = rotateBackups($backupDir, $safeDbName, $retentionDays, $minBackups);

    if ($deletedCount > 0) {
        displayMessage("Removidos {$deletedCount} backups antigos", "INFO");
    }

    // Retorna informações do backup
    return [
        'backup_file' => $backupFile,
        'file_size' => $fileSize,
        'file_size_formatted' => $fileSizeFormatted,
        'compressed' => $useCompression,
        'options' => $options
    ];
}

/**
 * Alternativa: Backup usando PHP puro (se mysqldump não estiver disponível)
 */
function executeDatabaseBackupPHP($taskName, $config, $backupDir = null)
{
    displayMessage("Usando backup via PHP (sem mysqldump)...", "WARNING");

    if (!function_exists('mysqli_connect')) {
        throw new Exception("Extensão mysqli não está habilitada no PHP.");
    }

    // Obtém configurações de retenção
    $retentionDays = isset($config['retention_days']) ? (int)$config['retention_days'] : 30;
    $minBackups = isset($config['min_backups']) ? (int)$config['min_backups'] : 1;

    displayMessage("Retenção: manter backups por {$retentionDays} dias, mínimo {$minBackups} arquivos");

    // Obtém diretório de backup se não fornecido
    if ($backupDir === null) {
        $backupDir = getBackupDir($taskName);
    }

    // Define safeDbName
    $safeDbName = sanitizeFilename($config['database']);
    displayMessage("Nome seguro do banco: {$safeDbName}");

    // Descriptografa a senha se necessário
    $password = decryptPassword($config['password']);

    // Conecta ao banco de dados
    $mysqli = @new mysqli(
        $config['host'],
        $config['username'],
        $password,
        $config['database']
    );

    if ($mysqli->connect_error) {
        throw new Exception("Erro de conexão: " . $mysqli->connect_error);
    }

    // Cria nome do arquivo de backup
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/' . $safeDbName . '_' . $timestamp . '.sql';

    // Abre arquivo para escrita
    $handle = fopen($backupFile, 'w');
    if (!$handle) {
        throw new Exception("Não foi possível criar arquivo: {$backupFile}");
    }

    // Opções de backup configuráveis
    $backupOptions = isset($config['backup_options']) ? $config['backup_options'] : [];
    $options = array_merge([
        'routines' => true,
        'triggers' => true,
        'events' => true
    ], $backupOptions);

    // Mostra opções ativas
    $optionMessages = [];
    if ($options['routines']) $optionMessages[] = "procedures/functions";
    if ($options['triggers']) $optionMessages[] = "triggers";
    if ($options['events']) $optionMessages[] = "events";

    if (!empty($optionMessages)) {
        displayMessage("Opções ativas: " . implode(', ', $optionMessages));
    }

    // Escreve informações do backup
    fwrite($handle, "-- Backup gerado por tasks.php (PHP backup)\n");
    fwrite($handle, "-- Data: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Banco: " . $config['database'] . "\n");
    fwrite($handle, "-- Host: " . $config['host'] . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    fwrite($handle, "SET NAMES utf8mb4;\n\n");
    fwrite($handle, "SET TIME_ZONE='+00:00';\n\n");

    // 1. Exporta eventos (se habilitado)
    if ($options['events']) {
        displayMessage("Exportando eventos...");
        $eventsResult = $mysqli->query("SHOW EVENTS");
        if ($eventsResult && $eventsResult->num_rows > 0) {
            fwrite($handle, "--\n");
            fwrite($handle, "-- Eventos\n");
            fwrite($handle, "--\n\n");

            while ($event = $eventsResult->fetch_assoc()) {
                $eventName = $event['Name'];
                $createResult = $mysqli->query("SHOW CREATE EVENT `{$eventName}`");
                if ($createResult) {
                    $createRow = $createResult->fetch_assoc();
                    fwrite($handle, "DROP EVENT IF EXISTS `{$eventName}`;\n");
                    fwrite($handle, $createRow['Create Event'] . ";\n\n");
                    $createResult->free();
                }
            }
            fwrite($handle, "\n");
        }
        if ($eventsResult) $eventsResult->free();
    }

    // 2. Exporta procedures e functions (se habilitado)
    if ($options['routines']) {
        displayMessage("Exportando procedures e functions...");

        // Procedures
        $proceduresResult = $mysqli->query("SHOW PROCEDURE STATUS WHERE Db = '" . $mysqli->real_escape_string($config['database']) . "'");
        if ($proceduresResult && $proceduresResult->num_rows > 0) {
            fwrite($handle, "--\n");
            fwrite($handle, "-- Procedures\n");
            fwrite($handle, "--\n\n");

            while ($proc = $proceduresResult->fetch_assoc()) {
                $procName = $proc['Name'];
                $createResult = $mysqli->query("SHOW CREATE PROCEDURE `{$procName}`");
                if ($createResult) {
                    $createRow = $createResult->fetch_assoc();
                    fwrite($handle, "DROP PROCEDURE IF EXISTS `{$procName}`;\n");
                    fwrite($handle, $createRow['Create Procedure'] . ";\n\n");
                    $createResult->free();
                }
            }
            fwrite($handle, "\n");
        }
        if ($proceduresResult) $proceduresResult->free();

        // Functions
        $functionsResult = $mysqli->query("SHOW FUNCTION STATUS WHERE Db = '" . $mysqli->real_escape_string($config['database']) . "'");
        if ($functionsResult && $functionsResult->num_rows > 0) {
            fwrite($handle, "--\n");
            fwrite($handle, "-- Functions\n");
            fwrite($handle, "--\n\n");

            while ($func = $functionsResult->fetch_assoc()) {
                $funcName = $func['Name'];
                $createResult = $mysqli->query("SHOW CREATE FUNCTION `{$funcName}`");
                if ($createResult) {
                    $createRow = $createResult->fetch_assoc();
                    fwrite($handle, "DROP FUNCTION IF EXISTS `{$funcName}`;\n");
                    fwrite($handle, $createRow['Create Function'] . ";\n\n");
                    $createResult->free();
                }
            }
            fwrite($handle, "\n");
        }
        if ($functionsResult) $functionsResult->free();
    }

    // 3. Obtém todas as tabelas
    $tables = [];
    $result = $mysqli->query('SHOW TABLES');
    if ($result) {
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        $result->free();
    } else {
        fclose($handle);
        throw new Exception("Erro ao obter lista de tabelas: " . $mysqli->error);
    }

    if (empty($tables)) {
        displayMessage("Aviso: Nenhuma tabela encontrada no banco de dados.", "WARNING");
    } else {
        displayMessage("Exportando " . count($tables) . " tabelas...");

        // Exporta cada tabela
        foreach ($tables as $table) {
            displayMessage("  Exportando tabela: {$table}");

            // Exporta estrutura da tabela
            $result = $mysqli->query("SHOW CREATE TABLE `{$table}`");
            if ($result) {
                $row = $result->fetch_assoc();
                fwrite($handle, "--\n");
                fwrite($handle, "-- Estrutura da tabela `{$table}`\n");
                fwrite($handle, "--\n\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $row['Create Table'] . ";\n\n");
                $result->free();
            } else {
                displayMessage("  Aviso: Não foi possível obter estrutura da tabela {$table}", "WARNING");
                continue;
            }

            // Exporta triggers da tabela (se habilitado)
            if ($options['triggers']) {
                $triggersResult = $mysqli->query("SHOW TRIGGERS LIKE '" . $mysqli->real_escape_string($table) . "'");
                if ($triggersResult && $triggersResult->num_rows > 0) {
                    fwrite($handle, "--\n");
                    fwrite($handle, "-- Triggers da tabela `{$table}`\n");
                    fwrite($handle, "--\n\n");

                    while ($trigger = $triggersResult->fetch_assoc()) {
                        $triggerName = $trigger['Trigger'];
                        $createResult = $mysqli->query("SHOW CREATE TRIGGER `{$triggerName}`");
                        if ($createResult) {
                            $createRow = $createResult->fetch_assoc();
                            fwrite($handle, "DROP TRIGGER IF EXISTS `{$triggerName}`;\n");
                            fwrite($handle, $createRow['SQL Original Statement'] . ";\n\n");
                            $createResult->free();
                        }
                    }
                    fwrite($handle, "\n");
                }
                if ($triggersResult) $triggersResult->free();
            }

            // Exporta dados da tabela
            $result = $mysqli->query("SELECT * FROM `{$table}`");
            if ($result) {
                if ($result->num_rows > 0) {
                    fwrite($handle, "--\n");
                    fwrite($handle, "-- Dados da tabela `{$table}`\n");
                    fwrite($handle, "--\n\n");

                    // Obtém informações das colunas
                    $columnsInfo = [];
                    $fieldsResult = $mysqli->query("SHOW COLUMNS FROM `{$table}`");
                    if ($fieldsResult) {
                        while ($fieldRow = $fieldsResult->fetch_assoc()) {
                            $columnsInfo[$fieldRow['Field']] = $fieldRow;
                        }
                        $fieldsResult->free();
                    }

                    // Exporta cada linha
                    while ($row = $result->fetch_assoc()) {
                        $values = [];
                        foreach ($row as $column => $value) {
                            // Verifica se o valor é NULL
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                // Escapa o valor, tratando tipos especiais
                                $escapedValue = $mysqli->real_escape_string((string)$value);

                                // Verifica se a coluna é numérica para não usar aspas
                                $columnType = strtolower($columnsInfo[$column]['Type'] ?? '');
                                $isNumeric = preg_match('/(int|decimal|float|double|bit|bool)/', $columnType);

                                if ($isNumeric && is_numeric($value)) {
                                    $values[] = $value;
                                } else {
                                    $values[] = "'{$escapedValue}'";
                                }
                            }
                        }

                        fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
                    }

                    fwrite($handle, "\n");
                } else {
                    fwrite($handle, "-- Tabela `{$table}` está vazia\n\n");
                }
                $result->free();
            } else {
                displayMessage("  Aviso: Não foi possível exportar dados da tabela {$table}", "WARNING");
            }
        }
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);
    $mysqli->close();

    // Verifica se o arquivo tem conteúdo
    if (filesize($backupFile) === 0) {
        unlink($backupFile);
        throw new Exception("Arquivo de backup vazio. Verifique as permissões do banco.");
    }

    $fileSize = filesize($backupFile);
    $compressed = false;

    // Tenta comprimir com gzip se disponível
    $gzipPath = findCommandPath('gzip');
    if ($gzipPath) {
        displayMessage("Comprimindo backup com gzip...");
        exec(escapeshellarg($gzipPath) . ' ' . escapeshellarg($backupFile));
        $backupFile .= '.gz';
        $compressed = true;
        $fileSize = filesize($backupFile);
    }

    $fileSizeFormatted = formatBytes($fileSize);

    displayMessage("✓ Backup criado: " . basename($backupFile) . " ({$fileSizeFormatted})", "SUCCESS");

    // Rotaciona backups antigos com configuração individual
    $deletedCount = rotateBackups($backupDir, $safeDbName, $retentionDays, $minBackups);

    if ($deletedCount > 0) {
        displayMessage("Removidos {$deletedCount} backups antigos", "INFO");
    }

    // Retorna informações do backup
    return [
        'backup_file' => $backupFile,
        'file_size' => $fileSize,
        'file_size_formatted' => $fileSizeFormatted,
        'compressed' => $compressed,
        'options' => $options
    ];
}

/**
 * Rotaciona backups mantendo apenas os mais recentes
 * 
 * @param string $backupDir Diretório dos backups
 * @param string $filePrefix Prefixo dos arquivos (ex: nome_do_banco)
 * @param int $keepDays Número de dias para manter (padrão: 30)
 * @param int $keepMinNumber Número mínimo de backups a manter (mesmo se mais antigos)
 * @return int Número de arquivos deletados
 */
function rotateBackups($backupDir, $filePrefix, $keepDays = 30, $keepMinNumber = 1)
{
    $pattern = $backupDir . '/' . $filePrefix . '_*.sql*';
    $files = glob($pattern);

    if (empty($files)) {
        displayMessage("Nenhum backup encontrado para rotacionar", "INFO");
        return 0;
    }

    // Ordena por data (mais recente primeiro)
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $cutoffTime = time() - ($keepDays * 24 * 60 * 60);
    $deletedCount = 0;
    $keptCount = 0;

    foreach ($files as $file) {
        $fileTime = filemtime($file);
        $isOld = ($fileTime < $cutoffTime);

        // Mantém se:
        // 1. Não é muito antigo, OU
        // 2. É um dos últimos X backups que queremos manter
        if (!$isOld || $keptCount < $keepMinNumber) {
            $keptCount++;
            continue;
        }

        // Remove o arquivo
        if (unlink($file)) {
            displayMessage("  Removido backup antigo: " . basename($file) .
                " (" . date('Y-m-d', $fileTime) . ")", "INFO");
            $deletedCount++;
        }
    }

    if ($deletedCount > 0) {
        displayMessage("✓ Rotação: {$deletedCount} backups antigos removidos, {$keptCount} mantidos", "INFO");
    }

    return $deletedCount;
}

/**
 * Verifica se uma opção é suportada pelo mysqldump
 */
function checkMysqldumpOption($mysqldumpPath, $option)
{
    $command = escapeshellarg($mysqldumpPath) . ' --help 2>&1';
    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        foreach ($output as $line) {
            if (strpos($line, $option) !== false) {
                return true;
            }
        }
    }

    return false;
}

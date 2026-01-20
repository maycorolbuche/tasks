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

    // Monta o comando mysqldump com opções seguras
    $command = sprintf(
        '%s --host=%s --user=%s --password=%s --single-transaction --routines --triggers --events --complete-insert --hex-blob %s',
        escapeshellarg($mysqldumpPath),
        escapeshellarg($config['host']),
        escapeshellarg($config['username']),
        escapeshellarg($password),
        escapeshellarg($config['database'])
    );

    // Adiciona opções adicionais se suportadas
    if (checkMysqldumpOption($mysqldumpPath, '--column-statistics')) {
        $command .= ' --column-statistics=0';
    }

    // Adiciona compressão se disponível
    if ($useCompression) {
        $command .= ' 2>/dev/null | ' . escapeshellarg($gzipPath);
    }

    $command .= ' > ' . escapeshellarg($backupFile);

    displayMessage("Executando comando de backup...");

    // Executa o backup
    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        // Tenta sem algumas opções que podem causar problemas
        displayMessage("Tentando com opções reduzidas...", "WARNING");

        $command = sprintf(
            '%s --host=%s --user=%s --password=%s %s',
            escapeshellarg($mysqldumpPath),
            escapeshellarg($config['host']),
            escapeshellarg($config['username']),
            escapeshellarg($password),
            escapeshellarg($config['database'])
        );

        if ($useCompression) {
            $command .= ' 2>/dev/null | ' . escapeshellarg($gzipPath);
        }

        $command .= ' > ' . escapeshellarg($backupFile);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
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
        'compressed' => $useCompression
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

    // Obtém todas as tabelas
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
        fclose($handle);
        $mysqli->close();
        displayMessage("Aviso: Nenhuma tabela encontrada no banco de dados.", "WARNING");
        return true; // Não é erro, apenas banco vazio
    }

    displayMessage("Exportando " . count($tables) . " tabelas...");

    // Escreve informações do backup
    fwrite($handle, "-- Backup gerado por tasks.php\n");
    fwrite($handle, "-- Data: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Banco: " . $config['database'] . "\n");
    fwrite($handle, "-- Host: " . $config['host'] . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    fwrite($handle, "SET NAMES utf8mb4;\n\n");

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
        'compressed' => $compressed
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

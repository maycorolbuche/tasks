<?php

/**
 * Script para verificar requisitos do sistema
 */

require_once __DIR__ . '/includes/functions.php';

echo "Verificando requisitos do sistema...\n\n";

// Verifica SO
$os = strtoupper(substr(PHP_OS, 0, 3));
echo "Sistema Operacional: " . PHP_OS . " ({$os})\n";

// Verifica PHP
echo "PHP Versão: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n";

// Verifica extensões
$extensions = ['mysqli', 'json', 'openssl'];
foreach ($extensions as $ext) {
    echo "Extensão {$ext}: " . (extension_loaded($ext) ? "✓ OK" : "✗ FALTANDO") . "\n";
}

echo "\nVerificando comandos do sistema:\n";

// Verifica comandos
$commands = ['mysqldump', 'gzip', 'which', 'where'];
foreach ($commands as $cmd) {
    $exists = commandExists($cmd);
    $path = findCommandPath($cmd);
    echo "{$cmd}: " . ($exists ? "✓ OK" : "✗ NÃO ENCONTRADO");
    if ($path) {
        echo " ({$path})";
    }
    echo "\n";
}

// Caminhos comuns do mysqldump
echo "\nCaminhos comuns do mysqldump:\n";
$commonPaths = [
    '/usr/bin/mysqldump',
    '/usr/local/bin/mysqldump',
    '/usr/local/mysql/bin/mysqldump',
    '/opt/homebrew/bin/mysqldump',
    '/bin/mysqldump',
    '/usr/sbin/mysqldump'
];

foreach ($commonPaths as $path) {
    if (file_exists($path)) {
        echo "✓ {$path} - " . (is_executable($path) ? "Executável" : "Não executável") . "\n";
    }
}

echo "\nInstruções de instalação:\n";
if ($os === 'WIN') {
    echo "- Baixe o MySQL Installer do site oficial\n";
    echo "- Adicione o MySQL ao PATH\n";
} else {
    echo "- Ubuntu/Debian: sudo apt-get install mysql-client\n";
    echo "- CentOS/RHEL: sudo yum install mysql\n";
    echo "- Mac: brew install mysql-client\n";
}

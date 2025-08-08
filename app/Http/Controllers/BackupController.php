<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Ifsnop\Mysqldump\Mysqldump;
use App\Http\Controllers\EmailController;
use ZipArchive;

class BackupController extends Controller
{
    public function __construct()
    {
        set_time_limit(60 * 15); // 15 minutos
        ini_set('memory_limit', '1024M');
    }

    public function databases(Request $request, $conn = '')
    {
        function log($message, $dir = "")
        {
            if ($message <> "") {
                $message = date("Y-m-d H:i:s") . " " . $message;
            } else {
                $message = "◾◽◾";
            }

            if (!empty($dir)) {
                $logPath = rtrim($dir, '/') . '/log.txt'; // Defina o nome do arquivo de log
                file_put_contents($logPath, $message . PHP_EOL, FILE_APPEND);
            }
            return $message;
        }
        function rep_dir($dir)
        {
            return str_replace(['\\', '/', '//'], "/", $dir);
        }
        function removeDirectory($dirPath)
        {
            if (!is_dir($dirPath)) {
                throw new \Exception("O caminho não é um diretório: $dirPath");
            }

            $files = scandir($dirPath);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $filePath = $dirPath . '/' . $file;
                if (is_dir($filePath)) {
                    removeDirectory($filePath);
                } else {
                    unlink($filePath);
                }
            }

            rmdir($dirPath);
        }
        function zipFile($file, $file_zip)
        {
            $files = [$file];

            $zip = new ZipArchive();

            if ($zip->open($file_zip, ZipArchive::CREATE) === TRUE) {
                foreach ($files as $file) {
                    $file_name = basename($file); // Use o nome do arquivo original como nome dentro do ZIP
                    $zip->addFile($file, $file_name);
                }

                $zip->close();
                return response()->download($file_zip)->deleteFileAfterSend(true);
                return true;
            } else {
                return false;
            }
        }

        $errors = 0;
        $log = [];
        $log[] = log("Início da Rotina");

        $decode = json_decode(file_get_contents(env("BACKUP_DB_FILE")), true);
        $decode = array_change_key_case($decode, CASE_LOWER);

        $conn = strtolower($conn);

        $connections = [];
        if ($conn <> "") {
            $connections[$conn] = $decode[$conn];
        } else {
            $connections = $decode;
        }

        foreach ($connections as $title => $connection) {
            $db = array();
            $db['active'] = isset($connection['active']) ? $connection['active'] : true;
            $db['host'] = isset($connection['host']) ? $connection['host'] : '';
            $db['user'] = isset($connection['user']) ? $connection['user'] : '';
            $db['password'] = isset($connection['password']) ? $connection['password'] : (isset($connection['pwd']) ? $connection['pwd'] : '');
            $db['qt'] = isset($connection['qt']) ? $connection['qt'] : 0;

            $db['databases'] = array();
            if (isset($connection['database'])) {
                if (is_string($connection['database'])) {
                    $db['databases'][] = $connection['database'];
                }
                if (is_array($connection['database'])) {
                    $db['databases'] = array_merge($db['databases'], $connection['database']);
                }
            }
            if (isset($connection['databases'])) {
                if (is_string($connection['databases'])) {
                    $db['databases'][] = $connection['databases'];
                }
                if (is_array($connection['databases'])) {
                    $db['databases'] = array_merge($db['databases'], $connection['databases']);
                }
            }

            $db["include-tables"] = $this->toDBTables($connection['include-tables'] ?? "");
            $db["exclude-tables"] = $this->toDBTables($connection['exclude-tables'] ?? "");

            $log[] = log("");
            $datetime = date("Y-m-d_H-i-s");
            $dir_host = rep_dir(env("BACKUP_DB_DIR") . "/{$title}");
            $dir = rep_dir("{$dir_host}/{$datetime}");
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $log[] = log("🟢 Conexão: $title", $dir);
            $local_errors = 0;
            foreach ($db["databases"] as $database) {
                $db["database"] = $database;

                $host = $db['host'];
                $user = $db['user'];
                $password = $db['password'];
                $database = $db['database'];


                $backupPath = rep_dir("{$dir}/{$database}.sql");

                $backupDirectory = dirname($backupPath);
                if (!is_dir($backupDirectory)) {
                    mkdir($backupDirectory, 0777, true);
                }

                $log[] = log("");
                $log[] = log("➡️ Database: " . $database);
                $log[] = log("Gerando backup em: " . $backupPath, $dir);

                //try {
                $data = array();
                $data["include-tables"] = array();
                $data["exclude-tables"] = array();

                if (isset($db["include-tables"]["*"]) && count($db["include-tables"]["*"]) > 0) {
                    $data["include-tables"] = array_merge($data["include-tables"], $db["include-tables"]["*"]);
                }
                if (isset($db["include-tables"][$database]) && count($db["include-tables"][$database]) > 0) {
                    $data["include-tables"] = array_merge($data["include-tables"], $db["include-tables"][$database]);
                }

                if (isset($db["exclude-tables"]["*"]) && count($db["exclude-tables"]["*"]) > 0) {
                    $data["exclude-tables"] = array_merge($data["exclude-tables"], $db["exclude-tables"]["*"]);
                }
                if (isset($db["exclude-tables"][$database]) && count($db["exclude-tables"][$database]) > 0) {
                    $data["exclude-tables"] = array_merge($data["exclude-tables"], $db["exclude-tables"][$database]);
                }

                if (count($data["include-tables"]) <= 0) {
                    unset($data["include-tables"]);
                }
                if (count($data["exclude-tables"]) <= 0) {
                    unset($data["exclude-tables"]);
                }

                $dump = new Mysqldump("mysql:host={$host};dbname={$database}", $user, $password, $data);
                $dump->start($backupPath);
                $log[] = log("✔️ Backup gerado com sucesso", $dir);

                $info = pathinfo($backupPath);
                $file_zip = $info['dirname'] . '/' . $info['filename'] . '.zip';

                $log[] = log("Compactando arquivo em: " . $file_zip, $dir);
                $zip = zipFile($backupPath, $file_zip);
                if ($zip <> true) {
                    $log[] = log("❌ Erro ao compactar arquivo", $dir);
                } else {
                    $log[] = log("✔️ Arquivo compactado com sucesso", $dir);
                    if (file_exists($backupPath)) {
                        unlink($backupPath);
                    }
                }
                /*  } catch (\Exception $e) {
                    $log[] = log("❌ Erro ao fazer backup: " . $e->getMessage(), $dir);
                    $local_errors++;
                    $errors++;
                }*/
            }

            if ($local_errors <= 0) {
                //Não deu nenhum erro, então apaga backups antigos
                $log[] = log("");
                $log[] = log("📅 Qtd. de Backups para armazenar: " . ($db["qt"] <= 0 ? "Todos" : $db["qt"]));
                if ($db["qt"] > 0) {
                    $regex = '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/';

                    // Lista os arquivos no diretório de backups
                    $files = scandir($dir_host);

                    // Crie um array de objetos com informações de diretório e data de modificação
                    $directories = [];
                    foreach ($files as $file) {
                        $dirPath = $dir_host . '/' . $file;
                        $stats = stat($dirPath);
                        $directories[] = ['path' => $dirPath, 'mtime' => $stats['mtime']];
                    }

                    // Filtra os diretórios que correspondem à expressão regular
                    $filteredDirectories = array_filter($directories, function ($item) use ($regex) {
                        return preg_match($regex, basename($item['path']));
                    });

                    // Ordene os diretórios pela data de modificação em ordem decrescente (mais recente primeiro)
                    usort($filteredDirectories, function ($a, $b) {
                        return $b['mtime'] - $a['mtime'];
                    });

                    // Mantenha apenas os diretórios mais recentes
                    $keep = array_slice($filteredDirectories, 0, $db["qt"]);

                    // Remova os diretórios que não estão na lista de diretórios para manter
                    foreach ($filteredDirectories as $dir) {
                        if (!in_array($dir, $keep, true)) {
                            try {
                                removeDirectory($dir['path']);
                                $log[] = log("✔️ Diretório removido: {$dir['path']}");
                            } catch (\Exception $err) {
                                $log[] = log("❌ Erro ao remover diretório {$dir['path']}: {$err->getMessage()}");
                            }
                        }
                    }
                }
            }
        }


        $log[] = log("");
        $log[] = log(($errors > 0 ? "🔴" : "🟢") . " Rotina processada com sucesso");

        $this->enviarEmail($connections, $log, $errors);

        return response()->json($log);
    }


    public function enviarEmail($connections, $log, $errors = 0)
    {
        // Crie uma instância do EmailController
        $emailController = new EmailController();

        $conn_keys = implode(', ', array_keys($connections));
        $message = implode('<br>', $log);

        // Crie um objeto Request com os dados necessários para enviar o e-mail
        $request = new Request([
            'to' => 'mayco_rolbuche@hotmail.com',
            'subject' => ($errors > 0 ? " [❌ Erro] " : "") . 'Logs de Backup - ' . $conn_keys,
            'message' => $message,
        ]);

        // Chame o método sendEmail do EmailController
        $response = $emailController->sendEmail($request);

        // Manipule a resposta de acordo com suas necessidades
        if ($response->getStatusCode() == 200) {
            // O e-mail foi enviado com sucesso
            return response()->json(['message' => 'E-mail enviado com sucesso']);
        } else {
            // Ocorreu um erro ao enviar o e-mail
            return response()->json(['error' => 'Erro ao enviar o e-mail'], $response->getStatusCode());
        }
    }

    private function toDBTables($data)
    {
        $tables = array();
        if (is_string($data)) {
            $tables = explode(",", $data);
        } else {
            $tables = $data;
        }

        $db = array();
        foreach ($tables as $table) {
            $name = explode(".", $table);

            if (count($name) <= 1) {
                $db_name = "*";
                $table_name = $name[0];
            } else {
                $db_name = $name[0];
                $table_name = $name[1];
            }

            if (!isset($db[$db_name])) {
                $db[$db_name] = array();
            }
            if ($table_name <> "") {
                $db[$db_name][] = $table_name;
            }
        }
        return $db;
    }
}

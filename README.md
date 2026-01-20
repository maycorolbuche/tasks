# Sistema de Tarefas e Backup de Banco de Dados (PHP)

Este projeto é um **sistema simples de tarefas em PHP via linha de comando**, com foco principal em **backup de bancos de dados MySQL/MariaDB**, controle de retenção e **envio de logs via Telegram**.

Ele foi pensado para ser leve, sem frameworks, fácil de rodar em servidores Linux ou Windows, e simples de automatizar via **cron** ou **Agendador de Tarefas**.

---

## 📌 Funcionalidades

- Execução de tarefas via **CLI (linha de comando)**
- Backup de banco de dados MySQL/MariaDB usando `mysqldump`
- Suporte a **múltiplos ambientes** (ex: teste, produção)
- Controle de retenção de backups
- Senhas **criptografadas** no arquivo de configuração
- Envio de logs e alertas via **Telegram**
- Verificação automática de requisitos do sistema

---

## ✅ Requisitos

- **PHP 8.0+**
- Extensões PHP:
  - `json`
  - `openssl`
- Utilitários do sistema:
  - `mysqldump`
- Acesso via terminal (CLI)

Para validar automaticamente, execute:

```bash
php check_requirements.php
```

---

## ⚙️ Configuração

Toda a configuração do sistema fica no arquivo **`config.json`**.

### Exemplo de configuração

```json
{
  "tasks": {
    "db.teste": {
      "task": "backup.database",
      "host": "localhost",
      "database": "meu_banco",
      "username": "root",
      "password": "root",
      "retention_days": 7,
      "min_backups": 3
    }
  },
  "logs": {
    "send": "telegram",
    "bot_token": "SEU_TOKEN",
    "chat_id": "SEU_CHAT_ID"
  }
}
```

### 🔹 Parâmetros da tarefa `backup.database`

| Parâmetro | Descrição |
|---------|----------|
| `host` | Host do banco de dados |
| `database` | Nome do banco |
| `username` | Usuário do banco |
| `password` | Senha (pode ser criptografada) |
| `retention_days` | Dias para manter backups antigos |
| `min_backups` | Quantidade mínima de backups |

---

## 🔐 Criptografando Senhas

O sistema suporta senhas criptografadas no formato:

```
enc:CONTEUDO_CRIPTOGRAFADO:IV_BASE64
```

> Isso evita armazenar senhas em texto puro no `config.json`.

A lógica de descriptografia é feita automaticamente pelo sistema durante a execução.

---

## ▶️ Como Executar

### Executar uma tarefa específica

```bash
php tasks.php --task=db.teste
```

### Executar todas as tarefas configuradas

```bash
php tasks.php
```

### Exemplo de saída

```
[OK] Backup realizado com sucesso
[INFO] Arquivo salvo em /backups/db.teste/2026-01-20.sql.gz
```

---

## 📬 Logs via Telegram

Se configurado, o sistema envia:

- Sucesso na execução
- Erros de backup
- Falhas de configuração

### Como obter os dados

1. Crie um bot no **@BotFather**
2. Copie o `bot_token`
3. Pegue o `chat_id` do grupo ou usuário
4. Configure no `config.json`

---

## ⏱️ Automatização (Cron)

### Linux (cron)

```bash
0 2 * * * /usr/bin/php /caminho/tasks.php --task=db.producao
```

### Windows (Agendador de Tarefas)

- Programa: `php.exe`
- Argumentos: `tasks.php --task=db.producao`
- Iniciar em: pasta do projeto

---

## 🛡️ Boas Práticas de Segurança

- Nunca versionar o `config.json` com credenciais reais
- Use permissões restritas na pasta `backups/`
- Prefira senhas criptografadas
- Use usuários de banco apenas com permissão de **leitura**

---

## 🚀 Extensões Futuras (Sugestões)

- Backup incremental
- Upload para S3 / FTP
- Suporte a PostgreSQL
- Logs em arquivo
- Modo dry-run

---

## 📄 Licença

Uso livre para projetos pessoais ou corporativos.

---

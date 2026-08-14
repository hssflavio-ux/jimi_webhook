<?php
/**
 * JIMI IoT Hub - Enhanced Logger
 * Versão: 2.0.0
 * Data: 2026-01-23
 * 
 * Sistema de logging melhorado com:
 * - Múltiplos níveis (DEBUG, INFO, WARNING, ERROR, CRITICAL)
 * - Contexto estruturado em JSON
 * - Rotação de logs por data
 * - Performance tracking
 * - Stack traces para erros
 */

class Logger {

    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';

    /**
     * Fuso de EXIBIÇÃO do log (v4.7.2).
     *
     * O armazenamento do sistema continua em UTC — os devices transmitem GMT 0
     * e a conexão PDO força `time_zone = '+00:00'`. O log, porém, não é dado:
     * é texto lido por gente, e a operação daqui é GMT-3. Converter só na hora
     * de carimbar mantém as duas coisas verdadeiras ao mesmo tempo, sem tocar
     * em uma linha do banco.
     *
     * Antes desta versão o log saía em UTC enquanto o `mtime` do arquivo saía
     * em BRT (o SO do servidor é America/Sao_Paulo): o mesmo evento aparecia
     * com três horas de diferença conforme se olhasse o `ls` ou o conteúdo.
     */
    const DISPLAY_TZ = 'America/Sao_Paulo';

    private static $logDir = __DIR__ . '/../logs';
    private static $logLevel = self::INFO; // Nível mínimo para logar
    private static $requestId = null;
    private static $envLevelApplied = false;
    private static $displayTz = null;
    
    /**
     * Inicializa logger com request ID único
     */
    public static function init() {
        self::$requestId = self::generateRequestId();
        
        // Criar diretório de logs se não existir
        if (!file_exists(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }
    
    /**
     * Relógio de exibição do log, sempre em BRT (ver DISPLAY_TZ).
     *
     * Não depende de `includes/functions.php` de propósito: o Logger é
     * carregado por todo webhook e não pode passar a exigir o resto do app.
     * Também não mexe em `date_default_timezone_set()`, que valeria para o
     * processo inteiro e faria `date()` gravar BRT em coluna UTC.
     *
     * @param float|null $timestamp Epoch com fração; null usa o agora
     * @returns DateTime Instante já convertido para o fuso de exibição
     */
    private static function displayClock($timestamp = null) {
        if (self::$displayTz === null) {
            self::$displayTz = new DateTimeZone(self::DISPLAY_TZ);
        }

        $epoch = ($timestamp === null) ? microtime(true) : (float)$timestamp;

        // 'U.u' preserva os microssegundos; createFromFormat devolve em UTC,
        // e só então convertemos — somar offset na mão erraria no histórico
        // anterior a 2019, quando o horário de verão ainda vigorava aqui.
        $dt = DateTime::createFromFormat('U.u', sprintf('%.6F', $epoch), new DateTimeZone('UTC'));
        if ($dt === false) {
            $dt = new DateTime('@' . (int)$epoch);
        }

        return $dt->setTimezone(self::$displayTz);
    }

    /**
     * Carimbo de data/hora em BRT para saída de CONSOLE/LOG.
     *
     * ⚠️ Use isto SOMENTE para texto que uma pessoa vai ler (echo de script de
     * cron, cabeçalho de log). **Nunca** para montar valor que vá ao banco: as
     * colunas são UTC, e gravar BRT numa delas mistura dois fusos na mesma
     * coluna — dano silencioso e caro de desfazer. Para o banco continue com
     * `date('Y-m-d H:i:s')` (o PHP roda em UTC) ou `UTC_TIMESTAMP()` no SQL.
     *
     * @param string $format Formato aceito por DateTime::format()
     * @returns string Data/hora já em America/Sao_Paulo
     */
    public static function stamp($format = 'Y-m-d H:i:s') {
        return self::displayClock()->format($format);
    }

    /**
     * Gera ID único para rastrear request
     */
    private static function generateRequestId() {
        return sprintf(
            '%s-%s',
            self::displayClock()->format('YmdHis'),
            substr(md5(uniqid('', true)), 0, 8)
        );
    }
    
    /**
     * Log nível DEBUG
     */
    public static function debug($message, array $context = []) {
        self::log(self::DEBUG, $message, $context);
    }
    
    /**
     * Log nível INFO
     */
    public static function info($message, array $context = []) {
        self::log(self::INFO, $message, $context);
    }
    
    /**
     * Log nível WARNING
     */
    public static function warning($message, array $context = []) {
        self::log(self::WARNING, $message, $context);
    }
    
    /**
     * Log nível ERROR
     */
    public static function error($message, array $context = []) {
        self::log(self::ERROR, $message, $context);
    }
    
    /**
     * Log nível CRITICAL
     */
    public static function critical($message, array $context = []) {
        self::log(self::CRITICAL, $message, $context);
    }
    
    /**
     * Método principal de logging
     */
    private static function log($level, $message, array $context = []) {
        // LOG_LEVEL do .env aplicado lazy: o .env só é parseado no primeiro
        // Database::getInstance() (config/database.php), que normalmente ocorre
        // depois do load deste arquivo — reavalia até a variável existir
        if (!self::$envLevelApplied) {
            $envLevel = getenv('LOG_LEVEL');
            if ($envLevel !== false && $envLevel !== '') {
                self::setLogLevel(strtoupper(trim($envLevel)));
                self::$envLevelApplied = true;
            }
        }

        // Verificar se deve logar baseado no nível
        if (!self::shouldLog($level)) {
            return;
        }
        
        // Montar entrada de log
        $entry = self::formatLogEntry($level, $message, $context);
        
        // Escrever no arquivo
        self::writeToFile($level, $entry);
        
        // Se for erro crítico, enviar também para error_log do PHP
        if ($level === self::CRITICAL) {
            error_log($entry);
        }
    }
    
    /**
     * Verifica se deve logar baseado no nível configurado
     */
    private static function shouldLog($level) {
        $levels = [
            self::DEBUG => 0,
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3,
            self::CRITICAL => 4
        ];
        
        return $levels[$level] >= $levels[self::$logLevel];
    }
    
    /**
     * Formata entrada de log em formato estruturado
     */
    private static function formatLogEntry($level, $message, array $context) {
        // Timestamp com microsegundos, em BRT (ver DISPLAY_TZ)
        $fullTimestamp = self::displayClock(microtime(true))->format('Y-m-d H:i:s.u');
        
        // Request ID para rastrear
        if (self::$requestId === null) {
            self::init();
        }
        
        // Adicionar informações de request ao contexto
        $context['request_id'] = self::$requestId;
        $context['memory_mb'] = round(memory_get_usage() / 1024 / 1024, 2);
        
        // Adicionar informações de HTTP se disponível
        if (!empty($_SERVER['REQUEST_METHOD'])) {
            $context['http_method'] = $_SERVER['REQUEST_METHOD'];
            $context['http_uri'] = $_SERVER['REQUEST_URI'] ?? 'unknown';
            $context['http_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
        
        // Formatar como JSON para parsing fácil
        $logData = [
            'timestamp' => $fullTimestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        // Formato legível para humanos
        $contextJson = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        
        return sprintf(
            "[%s] [%s] %s%s\n",
            $fullTimestamp,
            $level,
            $message,
            $contextJson
        );
    }
    
    /**
     * Escreve no arquivo de log
     */
    private static function writeToFile($level, $entry) {
        try {
            // Nome do arquivo com data (rotação diária) — em BRT, igual ao
            // carimbo das linhas. Se o nome ficasse em UTC e o conteúdo em
            // BRT, tudo entre 21:00 e 00:00 BRT cairia no arquivo do dia
            // SEGUINTE; e `tail logs/webhook_$(date +%F).log` no servidor
            // (cujo SO é BRT) apontaria para o arquivo errado nessa faixa.
            $date = self::displayClock()->format('Y-m-d');
            $filename = sprintf('%s/webhook_%s.log', self::$logDir, $date);
            
            // Criar arquivo com flag para append
            file_put_contents($filename, $entry, FILE_APPEND | LOCK_EX);
            
            // 🔴 0666, NÃO 0644 — e isto não é relaxamento de segurança, é o que
            // faz o log existir. DOIS usuários diferentes escrevem no mesmo
            // arquivo: o Apache (`www-data`, nos webhooks) e o cron (o usuário
            // do deploy, nos workers). Com 0644 quem cria o arquivo do dia é o
            // dono exclusivo e o OUTRO fica trancado até a virada do dia.
            //
            // Medido em produção em 14/08/2026: o cron criou
            // `webhook_2026-08-14.log` às 00:00:08 como `644 administrador`, e
            // TODO webhook do dia falhou ao logar — "Failed to open stream:
            // Permission denied", uma vez por requisição, só no error_log do
            // Apache. Os webhooks continuaram gravando no banco, então nada
            // parecia errado: só o diagnóstico é que sumiu, que é justamente o
            // que se procura quando algo dá errado.
            //
            // `chmod` só funciona para o DONO do arquivo, então o segundo
            // usuário nem consegue corrigir sozinho — daí o aviso de
            // "Operation not permitted" que aparecia em seguida.
            if (file_exists($filename)) {
                @chmod($filename, 0666);
            }
            
        } catch (Exception $e) {
            // Fallback: tentar error_log do PHP
            error_log("Logger falhou ao escrever: " . $e->getMessage());
            error_log($entry);
        }
    }
    
    /**
     * Log de performance de operação
     */
    public static function performance($operation, $startTime, array $context = []) {
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $context['execution_time_ms'] = $executionTime;
        $context['operation'] = $operation;
        
        // Avisar se operação está lenta (> 500ms)
        if ($executionTime > 500) {
            self::warning("SLOW OPERATION: {$operation}", $context);
        } else {
            self::info("PERFORMANCE: {$operation}", $context);
        }
    }
    
    /**
     * Log de exceção com stack trace
     */
    public static function exception(Exception $e, $message = "Exception occurred", array $context = []) {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::formatStackTrace($e->getTrace())
        ];
        
        self::error($message, $context);
    }
    
    /**
     * Formata stack trace de forma legível
     */
    private static function formatStackTrace(array $trace) {
        $formatted = [];
        
        foreach (array_slice($trace, 0, 5) as $i => $frame) {
            $formatted[] = sprintf(
                "#%d %s(%d): %s%s%s()",
                $i,
                $frame['file'] ?? 'unknown',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? 'unknown'
            );
        }
        
        return $formatted;
    }
    
    /**
     * Limpar logs antigos (> 30 dias)
     *
     * Cobre TODOS os logs do diretório (webhook_*, worker, órfãos de writers
     * antigos) e os .log.old gerados pela rotação por tamanho do
     * scripts/log_cleanup.php — não apenas webhook_*.log.
     */
    public static function cleanOldLogs($daysToKeep = 30) {
        try {
            $files = array_merge(
                glob(self::$logDir . '/*.log') ?: [],
                glob(self::$logDir . '/*.log.old') ?: []
            );
            $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                    self::info("Old log file deleted", ['file' => basename($file)]);
                }
            }
            
        } catch (Exception $e) {
            self::warning("Failed to clean old logs", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Obter estatísticas de logs do dia
     */
    public static function getDailyStats() {
        try {
            $today = self::displayClock()->format('Y-m-d');
            $filename = sprintf('%s/webhook_%s.log', self::$logDir, $today);
            
            if (!file_exists($filename)) {
                return [
                    'total_lines' => 0,
                    'errors' => 0,
                    'warnings' => 0,
                    'info' => 0
                ];
            }
            
            $content = file_get_contents($filename);
            
            return [
                'total_lines' => substr_count($content, "\n"),
                'errors' => substr_count($content, '[ERROR]'),
                'critical' => substr_count($content, '[CRITICAL]'),
                'warnings' => substr_count($content, '[WARNING]'),
                'info' => substr_count($content, '[INFO]'),
                'file_size_mb' => round(filesize($filename) / 1024 / 1024, 2)
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Configurar nível mínimo de log
     */
    public static function setLogLevel($level) {
        $validLevels = [self::DEBUG, self::INFO, self::WARNING, self::ERROR, self::CRITICAL];
        
        if (in_array($level, $validLevels)) {
            self::$logLevel = $level;
        }
    }
}

// Inicializar logger
Logger::init();

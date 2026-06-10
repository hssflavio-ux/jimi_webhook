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
    
    private static $logDir = __DIR__ . '/../logs';
    private static $logLevel = self::INFO; // Nível mínimo para logar
    private static $requestId = null;
    
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
     * Gera ID único para rastrear request
     */
    private static function generateRequestId() {
        return sprintf(
            '%s-%s',
            date('YmdHis'),
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
        // Timestamp com microsegundos
        $timestamp = microtime(true);
        $datetime = date('Y-m-d H:i:s', $timestamp);
        $microseconds = sprintf('%06d', ($timestamp - floor($timestamp)) * 1000000);
        $fullTimestamp = $datetime . '.' . $microseconds;
        
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
            // Nome do arquivo com data (rotação diária)
            $date = date('Y-m-d');
            $filename = sprintf('%s/webhook_%s.log', self::$logDir, $date);
            
            // Criar arquivo com flag para append
            file_put_contents($filename, $entry, FILE_APPEND | LOCK_EX);
            
            // Garantir permissões corretas
            if (file_exists($filename)) {
                chmod($filename, 0644);
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
     */
    public static function cleanOldLogs($daysToKeep = 30) {
        try {
            $files = glob(self::$logDir . '/webhook_*.log');
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
            $today = date('Y-m-d');
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

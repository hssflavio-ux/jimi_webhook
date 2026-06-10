-- --------------------------------------------------------
-- Servidor:                     189.22.240.43
-- Versão do servidor:           8.0.46 - MySQL Community Server - GPL
-- OS do Servidor:               Linux
-- HeidiSQL Versão:              12.14.0.7165
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Copiando estrutura do banco de dados para jimi_tracker
CREATE DATABASE IF NOT EXISTS `jimi_tracker` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `jimi_tracker`;

-- Copiando estrutura para tabela jimi_tracker.alarm_types
CREATE TABLE IF NOT EXISTS `alarm_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `alarm_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Código do alarme (ex: 100, 256, 265)',
  `protocol` enum('JIMI','JTT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Protocolo: JIMI (msgClass=0) ou JTT (msgClass=1)',
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Categoria do alarme (ADAS, DMS, Sistema, etc)',
  `severity` enum('low','medium','high','critical','info','warning') COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `alarm_name_pt` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome do alarme em português',
  `alarm_name_en` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nome do alarme em inglês',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Descrição detalhada do alarme',
  `requires_action` tinyint(1) DEFAULT '0' COMMENT 'Se requer ação imediata',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code_protocol` (`alarm_code`,`protocol`),
  KEY `idx_protocol` (`protocol`),
  KEY `idx_category` (`category`),
  KEY `idx_severity` (`severity`),
  KEY `idx_alarm_name_pt` (`alarm_name_pt`),
  KEY `idx_alarm_name_en` (`alarm_name_en`),
  KEY `idx_requires_action` (`requires_action`),
  KEY `idx_alarm_types_protocol_code` (`protocol`,`alarm_code`)
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela de referência de tipos de alarmes para protocolos JIMI e JTT';

-- Copiando dados para a tabela jimi_tracker.alarm_types: ~114 rows (aproximadamente)
INSERT INTO `alarm_types` (`id`, `alarm_code`, `protocol`, `category`, `severity`, `alarm_name_pt`, `alarm_name_en`, `description`, `requires_action`, `created_at`, `updated_at`) VALUES
	(1, '1', 'JIMI', 'Emergency', 'critical', 'Alerta SOS', 'SOS Alarm', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(2, '2', 'JIMI', 'Security', 'critical', 'Corte de Alimentação Externa', 'External Power Cut-off', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(3, '3', 'JIMI', 'Vehicle', 'warning', 'Alerta de Vibração', 'Vibration Alarm', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(4, '4', 'JIMI', 'Geofence', 'warning', 'Entrada em Cerca Eletrônica', 'Enter Geofence', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(5, '5', 'JIMI', 'Geofence', 'warning', 'Saída de Cerca Eletrônica', 'Exit Geofence', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(6, '6', 'JIMI', 'Driving', 'warning', 'Excesso de Velocidade', 'Overspeed Alarm', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(7, '9', 'JIMI', 'Security', 'warning', 'Alerta de Deslocamento', 'Displacement Alarm', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(8, '10', 'JIMI', 'Device', 'warning', 'Entrada em Zona Cega GPS', 'Enter GPS Blind Zone', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(9, '11', 'JIMI', 'Device', 'info', 'Saída de Zona Cega GPS', 'Exit GPS Blind Zone', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(10, '12', 'JIMI', 'Device', 'info', 'Dispositivo Ligado (Power On)', 'Power On', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(11, '13', 'JIMI', 'Device', 'info', 'Primeiro Fix GPS', 'GPS First Fix', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(12, '14', 'JIMI', 'Device', 'warning', 'Bateria Fraca', 'Low Battery Alarm', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(13, '15', 'JIMI', 'Device', 'warning', 'Proteção por Baixa Tensão', 'Low Power Protection Mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(14, '16', 'JIMI', 'Security', 'warning', 'Cartão SIM Alterado', 'SIM Card Changed', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(15, '17', 'JIMI', 'Device', 'critical', 'Desligamento por Bateria Baixa', 'Low Battery Shutdown', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(16, '18', 'JIMI', 'Driving', 'warning', 'Direção em Baixa Velocidade', 'Slow Driving Alarm', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(17, '20', 'JIMI', 'Vehicle', 'info', 'Status de Porta Anormal', 'Abnormal Door Status', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(18, '21', 'JIMI', 'Device', 'info', 'Desligado por Baixa Tensão', 'Shut down due to low power', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(19, '22', 'JIMI', 'Security', 'info', 'Ruído Ambiente Excessivo', 'Ambient Sound Too Loud', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(20, '23', 'JIMI', 'Security', 'critical', 'Pseudo Estação-Base Detectada', 'Pseudo Base Station Detected', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(21, '24', 'JIMI', 'Security', 'warning', 'Tampa do Dispositivo Aberta', 'Device Cover Opened', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(22, '25', 'JIMI', 'Device', 'info', 'Tensão da Bateria Interna Baixa', 'Internal Battery Voltage Low', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(23, '26', 'JIMI', 'Device', 'info', 'Saída do Modo Transporte', 'Exit Transport Mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(24, '28', 'JIMI', 'Vehicle', 'info', 'Porta Aberta', 'Door Opened', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(25, '29', 'JIMI', 'Vehicle', 'info', 'Porta Fechada', 'Door Closed', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(26, '30', 'JIMI', 'Accident', 'critical', 'Airbag Acionado / Colisão', 'Safety Airbag Deployed', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(27, '31', 'JIMI', 'Accident', 'critical', 'Capotamento', 'Rollover Alarm', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(28, '32', 'JIMI', 'Device', 'info', 'Modo Sono Profundo', 'Deep Sleep Mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(29, '33', 'JIMI', 'Driving', 'warning', 'Derrapagem do Veículo', 'Vehicle Skidding', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(30, '34', 'JIMI', 'Driving', 'warning', 'Aceleração Rápida (GPS)', 'Rapid Acceleration', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(31, '35', 'JIMI', 'Personal', 'warning', 'Queda de Usuário', 'User has fallen down', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(32, '36', 'JIMI', 'Device', 'info', 'Carregador Conectado', 'Charger connected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(33, '40', 'JIMI', 'Device', 'info', 'Entrando em Modo Sono', 'Entering Sleep Mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(34, '41', 'JIMI', 'Driving', 'critical', 'Aceleração Brusca', 'Harsh Acceleration', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(35, '42', 'JIMI', 'Driving', 'warning', 'Curva Brusca à Esquerda', 'Harsh Left Turn', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(36, '43', 'JIMI', 'Driving', 'warning', 'Curva Brusca à Direita', 'Harsh Right Turn', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(37, '44', 'JIMI', 'Driving', 'critical', 'Frenagem Brusca', 'Harsh Braking', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(38, '45', 'JIMI', 'Accident', 'critical', 'Veículo Tumbado', 'Vehicle Tipped Over', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(39, '46', 'JIMI', 'Emergency', 'critical', 'Queda Detectada', 'Fall Detection', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(40, '47', 'JIMI', 'Security', 'critical', 'Dispositivo Desconectado/Removido', 'Device Detached', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(41, '51', 'JIMI', 'Security', 'warning', 'Dispositivo Bloqueado', 'Device Locked', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(42, '52', 'JIMI', 'Security', 'info', 'Dispositivo Desbloqueado', 'Device Unlocked', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(43, '53', 'JIMI', 'Security', 'critical', 'Desbloqueio Inesperado', 'Unexpected Unlocked', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(44, '101', 'JIMI', 'Device', 'warning', 'Impacto Violento Detectado', 'Violent Hit Detected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(45, '102', 'JIMI', 'Device', 'warning', 'Exceção de Tensão', 'Voltage Value Exception', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(46, '103', 'JIMI', 'Geofence', 'warning', 'Entrada em Geocerca OBD', 'OBD Enter Geofence', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(47, '104', 'JIMI', 'Geofence', 'warning', 'Saída de Geocerca OBD', 'OBD Exit Geofence', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(48, '105', 'JIMI', 'Video', 'info', 'Upload de Vídeo Concluído', 'File Uploaded Completely', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(49, '134', 'JIMI', 'Device', 'warning', 'Nenhum Cartão SD Detectado', 'No SD card is detected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(50, '136', 'JIMI', 'Security', 'critical', 'Corte de Alimentação Externa (Periférico)', 'External power was cut off', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(51, '137', 'JIMI', 'Video', 'warning', 'Nenhuma Câmera USB Detectada', 'No USB camera is detected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(52, '138', 'JIMI', 'Vehicle', 'info', 'Combustível e Energia Reconectados', 'Fuel and power reconnected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(53, '139', 'JIMI', 'Vehicle', 'info', 'Combustível e Energia Desconectados', 'Fuel and power disconnected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(54, '141', 'JIMI', 'Device', 'warning', 'Modo Transporte Terrestre Ativado', 'Device changed to land transport mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(55, '142', 'JIMI', 'Sensor', 'warning', 'Ambiente Anormal Detectado', 'Abnormal ambient environment', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(56, '143', 'JIMI', 'DMS', 'warning', 'Distração do Motorista', 'Driver Attention Distracted', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(57, '147', 'JIMI', 'DMS', 'critical', 'Fadiga Extrema do Motorista', 'Extreme Fatigue', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(58, '149', 'JIMI', 'Device', 'info', 'Modo Transporte Aquaviário Ativado', 'Device changed to waterborne transport mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(59, '150', 'JIMI', 'Device', 'warning', 'Modo Estacionário Ativado', 'Device changed to stationery mode', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(60, '151', 'JIMI', 'DMS', 'critical', 'Motorista ao Telefone', 'Using Phone', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(61, '154', 'JIMI', 'DMS', 'warning', 'Motorista Fumando', 'Smoking Alarm', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(62, '156', 'JIMI', 'DMS', 'critical', 'Motorista Ausente', 'Driver Absent', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(63, '157', 'JIMI', 'DMS', 'warning', 'Câmera DMS Bloqueada', 'Camera Blocked', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(64, '158', 'JIMI', 'DMS', 'warning', 'Comendo ou Bebendo ao Volante', 'Eating/Drinking', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(65, '159', 'JIMI', 'DMS', 'warning', 'Olhar Lateral Prolongado', 'Long Side Glance', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(66, '160', 'JIMI', 'DMS', 'warning', 'Motorista Bocejando', 'Driver Yawning', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(67, '204', 'JIMI', 'ADAS', 'critical', 'ADAS: Colisão Frontal (FCW)', 'Forward Collision Warning', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(68, '205', 'JIMI', 'ADAS', 'warning', 'ADAS: Saída de Faixa (LDW)', 'Lane Departure Warning', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(69, '206', 'JIMI', 'ADAS', 'warning', 'ADAS: Distância Insegura (HMW)', 'Headway Monitor Warning', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(70, '207', 'JIMI', 'ADAS', 'critical', 'ADAS: Colisão com Pedestre (PCW)', 'Pedestrian Collision Warning', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(71, '254', 'JIMI', 'Security', 'info', 'Status de Ignição Alterado', 'Ignition Status Changed', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(72, '256', 'JTT', 'Device', 'info', 'Alarme Padrão JT/T', 'Standard Alarm', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(73, '257', 'JTT', 'Video', 'critical', 'Perda de Sinal de Vídeo', 'Video Signal Lost', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(74, '258', 'JTT', 'Video', 'warning', 'Obstrução da Câmera', 'Video Signal Blocked', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(75, '259', 'JTT', 'Device', 'critical', 'Falha no Armazenamento', 'Storage Fault', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(76, '260', 'JTT', 'Driving', 'warning', 'Condução Anormal (Geral)', 'Abnormal Driving', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(77, '262', 'JTT', 'Driving', 'warning', 'Comportamento de Condução Irregular', 'Abnormal Driving Behavior', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(78, '264-1', 'JTT', 'ADAS', 'critical', 'ADAS: Colisão Frontal (FCW)', 'Forward Collision Warning', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(79, '264-2', 'JTT', 'ADAS', 'warning', 'ADAS: Saída de Faixa (LDW)', 'Lane Departure Warning', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(80, '264-3', 'JTT', 'ADAS', 'warning', 'ADAS: Distância Insegura (HMW)', 'Headway Monitoring', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(81, '264-4', 'JTT', 'ADAS', 'critical', 'ADAS: Colisão com Pedestre', 'Pedestrian Collision', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(82, '264-5', 'JTT', 'ADAS', 'warning', 'ADAS: Mudança de Faixa Frequente', 'Freq. Lane Change', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(83, '264-6', 'JTT', 'ADAS', 'info', 'ADAS: Reconhecimento de Placa', 'Traffic Sign', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(84, '264-7', 'JTT', 'ADAS', 'warning', 'ADAS: Obstáculo Detectado', 'Obstacle Detection', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(85, '264-8', 'JTT', 'ADAS', 'info', 'ADAS: Veículo à Frente Partiu', 'Front Car Start', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(86, '264-9', 'JTT', 'ADAS', 'critical', 'ADAS: Violação de Sinal Vermelho', 'Red Light Violation', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(87, '264-10', 'JTT', 'ADAS', 'warning', 'ADAS: Manutenção de Faixa', 'Lane Keeping', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(88, '264-11', 'JTT', 'ADAS', 'warning', 'ADAS: Cruzamento de Faixa', 'Lane Crossing', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(89, '264-12', 'JTT', 'ADAS', 'warning', 'ADAS: Mudança de Faixa', 'Lane Changing', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(90, '265-1', 'JTT', 'DMS', 'warning', 'DMS: Fadiga ao Dirigir (Nível 1)', 'Fatigue Level 1', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(91, '265-2', 'JTT', 'DMS', 'warning', 'DMS: Uso de Celular ao Volante', 'Handheld Phone Use', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(92, '265-3', 'JTT', 'DMS', 'warning', 'DMS: Motorista Fumando', 'Smoking Detected', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(93, '265-4', 'JTT', 'DMS', 'warning', 'DMS: Distração Visual', 'Distraction', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(94, '265-5', 'JTT', 'DMS', 'critical', 'DMS: Motorista não Detectado', 'Driver Exception', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(95, '265-6', 'JTT', 'DMS', 'info', 'DMS: Captura Automática', 'Automatic Capture', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(96, '265-7', 'JTT', 'DMS', 'info', 'DMS: Captura por Tempo', 'Timed Auto Capture', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(97, '265-8', 'JTT', 'DMS', 'info', 'DMS: Troca de Motorista Detectada', 'Driver Change Detection', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(98, '265-10', 'JTT', 'DMS', 'warning', 'DMS: Comendo ou Bebendo ao Volante', 'Eating/Drinking While Driving', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(99, '265-11', 'JTT', 'DMS', 'critical', 'DMS: Ausência do Motorista', 'Driver Absence', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(100, '265-13', 'JTT', 'DMS', 'warning', 'DMS: Falha na Autenticação ID', 'Driver ID Auth Fail', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(101, '265-16', 'JTT', 'DMS', 'warning', 'DMS: Bocejando', 'Yawning', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(102, '265-17', 'JTT', 'DMS', 'critical', 'DMS: Fadiga ao Dirigir (Nível 2)', 'Fatigue Level 2', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(103, '265-18', 'JTT', 'DMS', 'warning', 'DMS: Sem Cinto de Segurança', 'No Seatbelt', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(104, '265-19', 'JTT', 'DMS', 'warning', 'DMS: Lente da Câmera Bloqueada', 'Lens Blocking', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(105, '265-20', 'JTT', 'DMS', 'warning', 'DMS: Postura da Cabeça Anormal', 'Head Attitude', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(106, '265-21', 'JTT', 'DMS', 'critical', 'DMS: Mãos fora do Volante', 'Hands Off Steering', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(107, '266-1', 'JTT', 'BSD', 'warning', 'BSD: Ponto Cego Traseiro', 'Rear Blind Spot', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(108, '266-2', 'JTT', 'BSD', 'warning', 'BSD: Ponto Cego Ultrapassagem', 'Overtaking Blind Spot', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(109, '266-3', 'JTT', 'BSD', 'warning', 'BSD: Pedestre Ponto Cego', 'Pedestrian BSD', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(110, '266-4', 'JTT', 'BSD', 'warning', 'BSD: Aproximação Traseira', 'Rear Approach Warning', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(111, '1040', 'JTT', 'Driving', 'warning', 'Ociosidade (Idling) Excessiva', 'Prolonged Idling', NULL, 0, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(112, '1041', 'JTT', 'Security', 'critical', 'Ignição Não Autorizada', 'Unauthorized Ignition', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(113, '1042', 'JTT', 'Driving', 'critical', 'Aceleração Brusca', 'Harsh Acceleration', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37'),
	(114, '1043', 'JTT', 'Driving', 'critical', 'Frenagem Brusca', 'Harsh Deceleration', NULL, 1, '2026-03-11 20:22:37', '2026-03-11 20:22:37');

-- Copiando estrutura para tabela jimi_tracker.alarms
CREATE TABLE IF NOT EXISTS `alarms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alarm_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Código numérico ou string do alarme',
  `alert_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Código real do alerta',
  `alarm_subtype` int DEFAULT NULL COMMENT 'Hierarchical subtype: alarmType for ADAS/DMS, channel for video alarms, NULL for simple types',
  `standard_alarm_bitmask` int unsigned DEFAULT NULL COMMENT 'Bitmask value for Standard Alarm (alertType 256), stores standardAlarmValue',
  `alarm_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nome do alarme (lookup em alarm_types)',
  `alert_value` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0' COMMENT 'Valor/threshold do alarme',
  `alarm_serial_no` int DEFAULT NULL COMMENT 'Número serial do alarme (JT/T)',
  `msg_class` tinyint DEFAULT '0' COMMENT '0: JIMI Protocol, 1: JT/T Protocol',
  `fence_id` int DEFAULT NULL COMMENT 'ID da cerca geográfica',
  `driver_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ID do motorista',
  `driver_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nome do motorista',
  `alarm_time` datetime NOT NULL COMMENT 'Hora que o alarme foi detectado',
  `gps_time` datetime DEFAULT NULL COMMENT 'Hora do GPS no momento do alarme',
  `gateway_time` datetime DEFAULT NULL COMMENT 'Hora que o alarme chegou no gateway',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `speed` decimal(6,2) DEFAULT NULL COMMENT 'Velocidade no momento do alarme',
  `car_speed` decimal(6,2) DEFAULT NULL COMMENT 'Velocidade do veículo (JT/T) em km/h',
  `car_status` int unsigned DEFAULT NULL COMMENT 'Status do veículo (JT/T bitfield)',
  `satellite_num` int DEFAULT NULL COMMENT 'Número de satélites',
  `gps_num` int DEFAULT NULL COMMENT 'Número de satélites GPS no momento do alarme',
  `gps_mode` tinyint DEFAULT NULL COMMENT '0: Real-time upload, 1: Re-upload',
  `direction` smallint DEFAULT NULL COMMENT 'Direção em graus (0-360)',
  `altitude` int DEFAULT NULL COMMENT 'Altitude em metros',
  `status` enum('active','acknowledged','resolved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `file_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'URL do arquivo de mídia (foto/vídeo)',
  `file_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'image, video, audio',
  `alarm_data` json DEFAULT NULL COMMENT 'Dados estruturados adicionais',
  `alarm_level` tinyint unsigned DEFAULT NULL COMMENT 'Nível do alarme: 1=baixo, 2=médio, 3=alto, 4=crítico',
  `alarm_status` tinyint unsigned DEFAULT NULL COMMENT 'Status do alarme (JT/T)',
  `fatigue_level` tinyint unsigned DEFAULT NULL COMMENT 'Nível de fadiga do motorista (0-255)',
  `alarm_id` int unsigned DEFAULT NULL COMMENT 'ID único do alarme no dispositivo (JT/T)',
  `alarm_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Label do alarme para requisitar arquivos (JT/T)',
  `standard_alarm_value` int unsigned DEFAULT NULL COMMENT 'Valor padrão do alarme (JT/T)',
  `signal_drop_channel` int unsigned DEFAULT NULL COMMENT 'Canais com perda de sinal de vídeo (bitfield)',
  `signal_cover_channel` int unsigned DEFAULT NULL COMMENT 'Canais com vídeo obstruído (bitfield)',
  `storage_fault_channel` int unsigned DEFAULT NULL COMMENT 'Canais com falha de armazenamento (bitfield)',
  `driving_alarm_flag` int unsigned DEFAULT NULL COMMENT 'Flag de comportamento anormal de direção (bitfield)',
  `version` tinyint unsigned DEFAULT NULL COMMENT 'Versão do protocolo',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Descrição ou observações',
  `voltage` decimal(5,2) DEFAULT NULL COMMENT 'Tensão bateria veículo (V)',
  `raw_data` json DEFAULT NULL COMMENT 'Payload completo original',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imei_alarm` (`imei`,`alarm_time` DESC),
  KEY `idx_active_alarms` (`status`),
  KEY `idx_alarm_type` (`alarm_type`),
  KEY `idx_gateway_time` (`gateway_time` DESC),
  KEY `idx_driver` (`driver_id`),
  KEY `idx_msg_class` (`msg_class`),
  KEY `idx_alarm_name` (`alarm_name`),
  KEY `idx_alarm_id` (`alarm_id`),
  KEY `idx_alarm_label` (`alarm_label`),
  KEY `idx_msg_class_type` (`msg_class`,`alarm_type`),
  KEY `idx_alarm_subtype` (`alarm_subtype`),
  KEY `idx_alarm_hierarchical` (`alarm_type`,`alarm_subtype`),
  KEY `idx_standard_bitmask` (`standard_alarm_value`),
  KEY `idx_alert_type` (`alert_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Alarmes e notificações dos dispositivos (pushalarm) - Atualizado 2026-01-22';

-- Copiando dados para a tabela jimi_tracker.alarms: ~13.039 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.commands
CREATE TABLE IF NOT EXISTS `commands` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `command_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Conteúdo do comando (texto ou JSON)',
  `command_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'request' COMMENT 'request, query, instruct',
  `status` enum('pending','queued','sent','executed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'admin' COMMENT 'Usuário que enviou',
  `api_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'instruct' COMMENT 'instruct, media, query',
  `media_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'URL de mídia relacionada',
  `response_time` datetime DEFAULT NULL COMMENT 'Hora da resposta',
  `response_payload` json DEFAULT NULL COMMENT 'Resposta completa do dispositivo',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cmd_status` (`imei`,`status`),
  KEY `idx_created_at` (`created_at` DESC)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comandos enviados aos dispositivos (pushcmd/pushInstructResponse)';

-- Copiando dados para a tabela jimi_tracker.commands: ~1 rows (aproximadamente)
INSERT INTO `commands` (`id`, `imei`, `command_content`, `command_type`, `status`, `operator`, `api_type`, `media_url`, `response_time`, `response_payload`, `created_at`, `updated_at`) VALUES
	(1, '865478070003241', '{"beginTime":"2026-02-23 19:00:00","endTime":"2026-02-23 20:00:00","mediaType":0,"channelId":1,"eventCode":0}', 'request', 'failed', 'dashboard', 'jtt_instruct', NULL, NULL, '{"msg": "Missing required parameter,parameter:serverFlagId", "code": 400002, "data": null}', '2026-02-23 22:53:57', '2026-02-23 22:53:57');

-- Copiando estrutura para procedure jimi_tracker.create_index_if_not_exists
DELIMITER //
CREATE PROCEDURE `create_index_if_not_exists`(
    IN p_table VARCHAR(128),
    IN p_index VARCHAR(128),
    IN p_definition TEXT
)
BEGIN
    DECLARE idx_count INT;
    SELECT COUNT(*) INTO idx_count 
    FROM information_schema.statistics 
    WHERE table_schema = DATABASE() 
      AND table_name = p_table 
      AND index_name = p_index;
    
    IF idx_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table, ' ADD INDEX ', p_index, ' ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- Copiando estrutura para função jimi_tracker.decode_standard_alarm_bits
DELIMITER //
CREATE FUNCTION `decode_standard_alarm_bits`(bitmask INT UNSIGNED) RETURNS varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    DETERMINISTIC
BEGIN
    DECLARE result VARCHAR(255) DEFAULT '';
    DECLARE bit_pos INT DEFAULT 0;
    DECLARE first_bit BOOLEAN DEFAULT TRUE;
    
    
    WHILE bit_pos < 32 DO
        IF (bitmask & (1 << bit_pos)) > 0 THEN
            IF first_bit THEN
                SET result = bit_pos;
                SET first_bit = FALSE;
            ELSE
                SET result = CONCAT(result, ',', bit_pos);
            END IF;
        END IF;
        SET bit_pos = bit_pos + 1;
    END WHILE;
    
    RETURN result;
END//
DELIMITER ;

-- Copiando estrutura para tabela jimi_tracker.device_events
CREATE TABLE IF NOT EXISTS `device_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Pode ser NULL se for evento de sistema',
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_time` datetime NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `raw_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_imei` (`imei`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela jimi_tracker.device_events: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.device_statistics
CREATE TABLE IF NOT EXISTS `device_statistics` (
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_gps_time` datetime DEFAULT NULL,
  `last_latitude` decimal(10,8) DEFAULT NULL,
  `last_longitude` decimal(11,8) DEFAULT NULL,
  `last_speed` decimal(6,2) DEFAULT NULL,
  `total_distance_km` decimal(10,2) DEFAULT '0.00',
  `battery_level` int DEFAULT NULL,
  `gsm_signal` int DEFAULT NULL,
  `is_online` tinyint(1) DEFAULT '0',
  `total_gps_count` bigint DEFAULT '0' COMMENT 'Total de pontos GPS recebidos',
  `total_alarm_count` bigint DEFAULT '0' COMMENT 'Total de alarmes',
  `total_event_count` bigint DEFAULT '0' COMMENT 'Total de eventos',
  `last_heartbeat_time` datetime DEFAULT NULL,
  `last_event_time` datetime DEFAULT NULL,
  `last_acc_status` tinyint(1) DEFAULT '0' COMMENT 'Último status da ignição',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`imei`),
  KEY `idx_online` (`is_online`),
  KEY `idx_updated` (`updated_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache de estatísticas em tempo real (performance)';

-- Copiando dados para a tabela jimi_tracker.device_statistics: ~4 rows (aproximadamente)
INSERT INTO `device_statistics` (`imei`, `last_gps_time`, `last_latitude`, `last_longitude`, `last_speed`, `total_distance_km`, `battery_level`, `gsm_signal`, `is_online`, `total_gps_count`, `total_alarm_count`, `total_event_count`, `last_heartbeat_time`, `last_event_time`, `last_acc_status`, `updated_at`) VALUES
	('353376110010771', '2026-03-12 21:00:08', -19.96622800, -43.95522200, 4.00, 60.02, NULL, 0, 1, 19876, 0, 193, '2026-03-12 21:00:23', '2026-03-12 21:10:22', 1, '2026-03-12 21:10:23'),
	('864993060182939', '2026-04-08 17:16:14', -19.96596600, -43.95481600, 0.00, 3943.39, NULL, 0, 1, 5289, 18, 384, '2026-04-08 18:04:13', '2026-04-08 18:04:15', 0, '2026-04-08 18:04:18'),
	('865478070003241', '2026-05-27 21:26:34', -19.94090600, -43.98497600, 1.00, 32.12, NULL, 31, 1, 5585, 0, 99, '2026-05-27 21:28:55', '2026-05-27 21:29:02', 1, '2026-05-27 21:29:05'),
	('865478070011327', '2026-05-19 18:25:57', -19.96621600, -43.95506800, 8.00, 4154.23, NULL, 31, 1, 12703, 30, 14761, '2026-05-19 18:26:14', '2026-05-19 18:26:44', 1, '2026-05-19 18:26:49');

-- Copiando estrutura para tabela jimi_tracker.devices
CREATE TABLE IF NOT EXISTS `devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activation_date` datetime DEFAULT NULL,
  `last_communication` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_imei` (`imei`),
  KEY `idx_last_comm` (`last_communication`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=154331 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cadastro central de dispositivos GPS/Câmeras';

-- Copiando dados para a tabela jimi_tracker.devices: ~4 rows (aproximadamente)
INSERT INTO `devices` (`id`, `imei`, `device_name`, `device_model`, `activation_date`, `last_communication`, `is_active`, `created_at`, `updated_at`) VALUES
	(10768, '864993060182939', NULL, NULL, NULL, '2026-04-08 18:04:18', 1, '2026-01-28 18:01:07', '2026-04-08 18:04:18'),
	(10818, '865478070011327', NULL, NULL, NULL, '2026-05-19 18:26:49', 1, '2026-01-28 21:17:30', '2026-05-19 18:26:49'),
	(11229, '353376110010771', NULL, NULL, NULL, '2026-03-12 21:10:23', 1, '2026-01-29 15:28:38', '2026-03-12 21:10:23'),
	(11942, '865478070003241', NULL, NULL, NULL, '2026-05-27 21:29:05', 1, '2026-01-29 21:56:55', '2026-05-27 21:29:05');

-- Copiando estrutura para tabela jimi_tracker.events
CREATE TABLE IF NOT EXISTS `events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tipo descritivo do evento',
  `event_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Código numérico/string do evento',
  `event_time` datetime NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `event_data` json DEFAULT NULL COMMENT 'Dados estruturados do evento',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Descrição ou contexto adicional',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imei_event` (`imei`,`event_time`),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Eventos gerais dos dispositivos (pushevent)';

-- Copiando dados para a tabela jimi_tracker.events: ~3.900 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.ftp_uploads
CREATE TABLE IF NOT EXISTS `ftp_uploads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'image, video, audio, log, etc',
  `file_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Caminho remoto ou URL completa',
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint DEFAULT NULL COMMENT 'Tamanho em bytes',
  `upload_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'completed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imei_ftp` (`imei`,`upload_time` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Arquivos enviados via FTP (pushftpfileupload)';

-- Copiando dados para a tabela jimi_tracker.ftp_uploads: ~0 rows (aproximadamente)

-- Copiando estrutura para função jimi_tracker.get_alarm_name_by_code
DELIMITER //
CREATE FUNCTION `get_alarm_name_by_code`(
    p_alarm_code VARCHAR(50),
    p_msg_class TINYINT,
    p_language VARCHAR(5)
) RETURNS varchar(200) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
    READS SQL DATA
    DETERMINISTIC
BEGIN
    DECLARE v_alarm_name VARCHAR(200);
    
    
    IF p_language = 'pt' THEN
        SELECT COALESCE(alarm_name_pt, alarm_name_en)
        INTO v_alarm_name
        FROM alarm_types
        WHERE alarm_code = p_alarm_code AND msg_class = p_msg_class
        LIMIT 1;
    ELSE
        SELECT alarm_name_en
        INTO v_alarm_name
        FROM alarm_types
        WHERE alarm_code = p_alarm_code AND msg_class = p_msg_class
        LIMIT 1;
    END IF;
    
    
    IF v_alarm_name IS NULL THEN
        IF p_msg_class = 1 THEN
            SET v_alarm_name = CONCAT('JT/T Alarm ', p_alarm_code);
        ELSE
            SET v_alarm_name = CONCAT('JIMI Alarm ', p_alarm_code);
        END IF;
    END IF;
    
    RETURN v_alarm_name;
END//
DELIMITER ;

-- Copiando estrutura para tabela jimi_tracker.gps_data
CREATE TABLE IF NOT EXISTS `gps_data` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `gps_time` datetime NOT NULL COMMENT 'Hora do GPS do dispositivo',
  `gateway_time` datetime DEFAULT NULL COMMENT 'Hora que chegou no gateway IoT',
  `server_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Hora que chegou no webhook',
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `speed` decimal(6,2) DEFAULT NULL COMMENT 'Velocidade em km/h',
  `direction` int DEFAULT NULL COMMENT 'Direção em graus (0-360)',
  `satellites` int DEFAULT NULL COMMENT 'Número de satélites',
  `gps_mode` tinyint DEFAULT '0' COMMENT '0=GPS, 1=LBS, 2=WiFi',
  `gsm_signal` int DEFAULT NULL COMMENT 'Sinal GSM (0-100)',
  `mileage` decimal(12,2) DEFAULT NULL COMMENT 'Odômetro acumulado (km)',
  `battery` int DEFAULT NULL COMMENT 'Nível bateria interna (%)',
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'VALID',
  `distance_from_previous` decimal(10,3) DEFAULT '0.000' COMMENT 'Distância do ponto anterior (km)',
  `acc` tinyint(1) DEFAULT '0' COMMENT '1=Ignição ligada, 0=Desligada',
  `device_status_code` bigint DEFAULT '0' COMMENT 'Código de status binário do dispositivo',
  `altitude` int DEFAULT '0' COMMENT 'Altitude em metros',
  `raw_data` json DEFAULT NULL COMMENT 'Payload completo original (auditoria)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uc_imei_time` (`imei`,`gps_time`),
  KEY `idx_imei_time` (`imei`,`gps_time` DESC),
  KEY `idx_server_time` (`server_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dados de posicionamento GPS (pushgps)';

-- Copiando dados para a tabela jimi_tracker.gps_data: ~4.796 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.heartbeats
CREATE TABLE IF NOT EXISTS `heartbeats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `heartbeat_time` datetime NOT NULL,
  `battery` int DEFAULT NULL COMMENT 'Nível bateria interna (%)',
  `gsm_signal` int DEFAULT NULL COMMENT 'Sinal GSM (0-100)',
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Status textual do dispositivo',
  `temperature` int DEFAULT NULL COMMENT 'Temperatura em °C',
  `voltage` decimal(5,2) DEFAULT NULL COMMENT 'Tensão bateria veículo (V)',
  `extra_data` json DEFAULT NULL COMMENT 'Dados extras do heartbeat (raw)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imei_hb` (`imei`,`heartbeat_time` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Heartbeats (sinais de vida periódicos - pushhb)';

-- Copiando dados para a tabela jimi_tracker.heartbeats: ~8.694 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.iothub_events
CREATE TABLE IF NOT EXISTS `iothub_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tipo do evento de plataforma',
  `event_time` datetime NOT NULL,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'platform' COMMENT 'Origem: platform, gateway, etc',
  `payload` json DEFAULT NULL COMMENT 'Dados completos do evento',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_imei_hub` (`imei`,`event_time` DESC),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Eventos de plataforma IoT Hub (pushIothubEvent)';

-- Copiando dados para a tabela jimi_tracker.iothub_events: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.lbs_data
CREATE TABLE IF NOT EXISTS `lbs_data` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mcc` int unsigned DEFAULT NULL COMMENT 'Mobile Country Code',
  `mnc` int unsigned DEFAULT NULL COMMENT 'Mobile Network Code',
  `lac` int unsigned DEFAULT NULL COMMENT 'Location Area Code',
  `cell_id` bigint unsigned DEFAULT NULL COMMENT 'Cell ID',
  `signal_strength` int DEFAULT NULL COMMENT 'Força do sinal (rssi)',
  `lbs_time` datetime NOT NULL COMMENT 'Hora da triangulação',
  `gateway_time` datetime DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL COMMENT 'Lat calculada pela Jimi (se disponível)',
  `longitude` decimal(11,8) DEFAULT NULL COMMENT 'Lng calculada pela Jimi (se disponível)',
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lbs_imei_time` (`imei`,`lbs_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela jimi_tracker.lbs_data: ~928 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.media_files
CREATE TABLE IF NOT EXISTS `media_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` enum('image','video','audio','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'other',
  `file_size` bigint DEFAULT '0',
  `file_url` text COLLATE utf8mb4_unicode_ci,
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_time` datetime DEFAULT NULL COMMENT 'Hora do evento gerador',
  `raw_data` json DEFAULT NULL COMMENT 'Payload original completo',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_imei` (`imei`),
  KEY `idx_media_time` (`event_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela jimi_tracker.media_files: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.request_logs
CREATE TABLE IF NOT EXISTS `request_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `endpoint` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_code` int DEFAULT NULL,
  `execution_time` decimal(10,2) DEFAULT NULL COMMENT 'Tempo de execução em ms',
  `payload_hash` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_endpoint` (`endpoint`),
  KEY `idx_created_at` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de performance das requisições';

-- Copiando dados para a tabela jimi_tracker.request_logs: ~25.269 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.resource_lists
CREATE TABLE IF NOT EXISTS `resource_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'video, image, audio',
  `file_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint DEFAULT '0',
  `start_time` datetime DEFAULT NULL COMMENT 'Hora de início da gravação',
  `end_time` datetime DEFAULT NULL COMMENT 'Hora de fim da gravação',
  `channel_id` int DEFAULT '0',
  `alarm_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tipo de alarme que gerou',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_file_idx` (`imei`,`file_name`,`channel_id`),
  KEY `idx_imei_res` (`imei`,`resource_type`),
  KEY `idx_start_time` (`start_time` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lista de recursos de mídia disponíveis (pushresourcelist)';

-- Copiando dados para a tabela jimi_tracker.resource_lists: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela jimi_tracker.system_info
CREATE TABLE IF NOT EXISTS `system_info` (
  `id` int NOT NULL,
  `version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `installation_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_update` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Metadados do sistema e versionamento';

-- Copiando dados para a tabela jimi_tracker.system_info: ~0 rows (aproximadamente)

-- Copiando estrutura para procedure jimi_tracker.update_alarm_name
DELIMITER //
CREATE PROCEDURE `update_alarm_name`(
    IN p_alarm_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_alarm_type VARCHAR(50);
    DECLARE v_msg_class TINYINT;
    DECLARE v_alarm_name VARCHAR(200);
    
    
    SELECT alarm_type, msg_class 
    INTO v_alarm_type, v_msg_class
    FROM alarms 
    WHERE id = p_alarm_id;
    
    
    SELECT COALESCE(alarm_name_pt, alarm_name_en)
    INTO v_alarm_name
    FROM alarm_types
    WHERE alarm_code = v_alarm_type
    AND protocol = CASE 
        WHEN v_msg_class = 0 THEN 'JIMI'
        WHEN v_msg_class = 1 THEN 'JTT'
        ELSE NULL
    END
    LIMIT 1;
    
    
    IF v_alarm_name IS NOT NULL THEN
        UPDATE alarms 
        SET alarm_name = v_alarm_name
        WHERE id = p_alarm_id;
    END IF;
END//
DELIMITER ;

-- Copiando estrutura para procedure jimi_tracker.update_device_stats_after_alarm
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_alarm`(
    IN p_imei VARCHAR(20), 
    IN p_alarm_time DATETIME
)
BEGIN
    INSERT INTO device_statistics (imei, total_alarm_count, is_online, updated_at)
    VALUES (p_imei, 1, 1, NOW())
    ON DUPLICATE KEY UPDATE
        total_alarm_count = total_alarm_count + 1, 
        is_online = 1,
        updated_at = NOW();

    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- Copiando estrutura para procedure jimi_tracker.update_device_stats_after_event
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_event`(
    IN p_imei VARCHAR(20),
    IN p_event_time DATETIME
)
BEGIN
    INSERT INTO device_statistics (
        imei, last_event_time, total_event_count, is_online, updated_at
    ) 
    VALUES (
        p_imei, p_event_time, 1, 1, NOW()
    )
    ON DUPLICATE KEY UPDATE
        last_event_time = IF(p_event_time > COALESCE(last_event_time, '2000-01-01'), p_event_time, last_event_time),
        total_event_count = total_event_count + 1,
        is_online = 1,
        updated_at = NOW();

    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- Copiando estrutura para procedure jimi_tracker.update_device_stats_after_gps
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_gps`(
    IN p_imei VARCHAR(20), 
    IN p_gps_time DATETIME, 
    IN p_lat DECIMAL(10,8), 
    IN p_lon DECIMAL(11,8), 
    IN p_speed DECIMAL(6,2), 
    IN p_dist DECIMAL(10,2), 
    IN p_gsm INT,
    IN p_acc TINYINT
)
BEGIN
    INSERT INTO device_statistics (
        imei, last_gps_time, last_latitude, last_longitude, last_speed, 
        total_distance_km, gsm_signal, is_online, total_gps_count, last_acc_status, updated_at
    )
    VALUES (
        p_imei, p_gps_time, p_lat, p_lon, p_speed, 
        COALESCE(p_dist, 0), p_gsm, 1, 1, p_acc, NOW()
    )
    ON DUPLICATE KEY UPDATE
        last_gps_time = IF(p_gps_time > last_gps_time, p_gps_time, last_gps_time),
        last_latitude = IF(p_gps_time >= last_gps_time, p_lat, last_latitude),
        last_longitude = IF(p_gps_time >= last_gps_time, p_lon, last_longitude),
        last_speed = IF(p_gps_time >= last_gps_time, p_speed, last_speed),
        last_acc_status = IF(p_gps_time >= last_gps_time, p_acc, last_acc_status),
        gsm_signal = IF(p_gps_time >= last_gps_time, p_gsm, gsm_signal),
        total_distance_km = total_distance_km + COALESCE(p_dist, 0),
        total_gps_count = total_gps_count + 1,
        is_online = 1,
        updated_at = NOW();
    
    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- Copiando estrutura para procedure jimi_tracker.update_device_stats_after_heartbeat
DELIMITER //
CREATE PROCEDURE `update_device_stats_after_heartbeat`(
    IN p_imei VARCHAR(20), 
    IN p_hb_time DATETIME, 
    IN p_bat INT, 
    IN p_gsm INT
)
BEGIN
    INSERT INTO device_statistics (
        imei, last_heartbeat_time, battery_level, gsm_signal, is_online, updated_at
    )
    VALUES (p_imei, p_hb_time, p_bat, p_gsm, 1, NOW())
    ON DUPLICATE KEY UPDATE
        last_heartbeat_time = IF(p_hb_time > COALESCE(last_heartbeat_time, '2000-01-01'), p_hb_time, last_heartbeat_time),
        battery_level = COALESCE(p_bat, battery_level),
        gsm_signal = COALESCE(p_gsm, gsm_signal),
        is_online = 1,
        updated_at = NOW();

    INSERT IGNORE INTO devices (imei, last_communication) VALUES (p_imei, NOW())
    ON DUPLICATE KEY UPDATE last_communication = NOW();
END//
DELIMITER ;

-- Copiando estrutura para view jimi_tracker.v_alarm_report
-- Criando tabela temporária para evitar erros de dependência de VIEW
CREATE TABLE `v_alarm_report` (
	`alarm_id` BIGINT UNSIGNED NOT NULL,
	`imei` VARCHAR(1) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`device_name` VARCHAR(1) NULL COLLATE 'utf8mb4_unicode_ci',
	`alarm_time` DATETIME NOT NULL COMMENT 'Hora que o alarme foi detectado',
	`alarm_name` VARCHAR(1) NULL COLLATE 'utf8mb4_unicode_ci',
	`severity` VARCHAR(1) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`latitude` DECIMAL(10,8) NULL,
	`longitude` DECIMAL(11,8) NULL,
	`file_url` TEXT NULL COMMENT 'URL do arquivo de mídia (foto/vídeo)' COLLATE 'utf8mb4_unicode_ci',
	`alarm_type` VARCHAR(1) NOT NULL COMMENT 'Código numérico ou string do alarme' COLLATE 'utf8mb4_unicode_ci',
	`alarm_subtype` INT NULL COMMENT 'Hierarchical subtype: alarmType for ADAS/DMS, channel for video alarms, NULL for simple types'
);

-- Copiando estrutura para view jimi_tracker.v_alarm_statistics
-- Criando tabela temporária para evitar erros de dependência de VIEW
CREATE TABLE `v_alarm_statistics` (
	`alarm_type` VARCHAR(1) NOT NULL COMMENT 'Código numérico ou string do alarme' COLLATE 'utf8mb4_unicode_ci',
	`alarm_name` VARCHAR(1) NULL COMMENT 'Nome do alarme (lookup em alarm_types)' COLLATE 'utf8mb4_unicode_ci',
	`msg_class` TINYINT NULL COMMENT '0: JIMI Protocol, 1: JT/T Protocol',
	`protocol` ENUM('JIMI','JTT') NULL COMMENT 'Protocolo: JIMI (msgClass=0) ou JTT (msgClass=1)' COLLATE 'utf8mb4_unicode_ci',
	`category` VARCHAR(1) NULL COMMENT 'Categoria do alarme (ADAS, DMS, Sistema, etc)' COLLATE 'utf8mb4_unicode_ci',
	`severity` ENUM('low','medium','high','critical','info','warning') NULL COLLATE 'utf8mb4_unicode_ci',
	`total_count` BIGINT NOT NULL,
	`active_count` BIGINT NOT NULL,
	`with_coords` BIGINT NOT NULL,
	`with_media` BIGINT NOT NULL,
	`first_occurrence` TIMESTAMP NULL,
	`last_occurrence` TIMESTAMP NULL
);

-- Copiando estrutura para view jimi_tracker.v_alarms_enriched
-- Criando tabela temporária para evitar erros de dependência de VIEW
CREATE TABLE `v_alarms_enriched` (
	`id` BIGINT UNSIGNED NOT NULL,
	`imei` VARCHAR(1) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`alarm_type` VARCHAR(1) NOT NULL COMMENT 'Código numérico ou string do alarme' COLLATE 'utf8mb4_unicode_ci',
	`alarm_name` VARCHAR(1) NULL COMMENT 'Nome do alarme (lookup em alarm_types)' COLLATE 'utf8mb4_unicode_ci',
	`alert_value` VARCHAR(1) NULL COMMENT 'Valor/threshold do alarme' COLLATE 'utf8mb4_unicode_ci',
	`alarm_serial_no` INT NULL COMMENT 'Número serial do alarme (JT/T)',
	`msg_class` TINYINT NULL COMMENT '0: JIMI Protocol, 1: JT/T Protocol',
	`fence_id` INT NULL COMMENT 'ID da cerca geográfica',
	`driver_id` VARCHAR(1) NULL COMMENT 'ID do motorista' COLLATE 'utf8mb4_unicode_ci',
	`driver_name` VARCHAR(1) NULL COMMENT 'Nome do motorista' COLLATE 'utf8mb4_unicode_ci',
	`alarm_time` DATETIME NOT NULL COMMENT 'Hora que o alarme foi detectado',
	`gps_time` DATETIME NULL COMMENT 'Hora do GPS no momento do alarme',
	`gateway_time` DATETIME NULL COMMENT 'Hora que o alarme chegou no gateway',
	`latitude` DECIMAL(10,8) NULL,
	`longitude` DECIMAL(11,8) NULL,
	`speed` DECIMAL(6,2) NULL COMMENT 'Velocidade no momento do alarme',
	`car_speed` DECIMAL(6,2) NULL COMMENT 'Velocidade do veículo (JT/T) em km/h',
	`car_status` INT UNSIGNED NULL COMMENT 'Status do veículo (JT/T bitfield)',
	`satellite_num` INT NULL COMMENT 'Número de satélites',
	`gps_num` INT NULL COMMENT 'Número de satélites GPS no momento do alarme',
	`gps_mode` TINYINT NULL COMMENT '0: Real-time upload, 1: Re-upload',
	`direction` SMALLINT NULL COMMENT 'Direção em graus (0-360)',
	`altitude` INT NULL COMMENT 'Altitude em metros',
	`status` ENUM('active','acknowledged','resolved') NULL COLLATE 'utf8mb4_unicode_ci',
	`file_url` TEXT NULL COMMENT 'URL do arquivo de mídia (foto/vídeo)' COLLATE 'utf8mb4_unicode_ci',
	`file_type` VARCHAR(1) NULL COMMENT 'image, video, audio' COLLATE 'utf8mb4_unicode_ci',
	`alarm_data` JSON NULL COMMENT 'Dados estruturados adicionais',
	`alarm_level` TINYINT UNSIGNED NULL COMMENT 'Nível do alarme: 1=baixo, 2=médio, 3=alto, 4=crítico',
	`alarm_status` TINYINT UNSIGNED NULL COMMENT 'Status do alarme (JT/T)',
	`fatigue_level` TINYINT UNSIGNED NULL COMMENT 'Nível de fadiga do motorista (0-255)',
	`alarm_id` INT UNSIGNED NULL COMMENT 'ID único do alarme no dispositivo (JT/T)',
	`alarm_label` VARCHAR(1) NULL COMMENT 'Label do alarme para requisitar arquivos (JT/T)' COLLATE 'utf8mb4_unicode_ci',
	`standard_alarm_value` INT UNSIGNED NULL COMMENT 'Valor padrão do alarme (JT/T)',
	`signal_drop_channel` INT UNSIGNED NULL COMMENT 'Canais com perda de sinal de vídeo (bitfield)',
	`signal_cover_channel` INT UNSIGNED NULL COMMENT 'Canais com vídeo obstruído (bitfield)',
	`storage_fault_channel` INT UNSIGNED NULL COMMENT 'Canais com falha de armazenamento (bitfield)',
	`driving_alarm_flag` INT UNSIGNED NULL COMMENT 'Flag de comportamento anormal de direção (bitfield)',
	`version` TINYINT UNSIGNED NULL COMMENT 'Versão do protocolo',
	`description` TEXT NULL COMMENT 'Descrição ou observações' COLLATE 'utf8mb4_unicode_ci',
	`voltage` DECIMAL(5,2) NULL COMMENT 'Tensão bateria veículo (V)',
	`raw_data` JSON NULL COMMENT 'Payload completo original',
	`created_at` TIMESTAMP NULL,
	`alarm_name_pt` VARCHAR(1) NULL COMMENT 'Nome do alarme em português' COLLATE 'utf8mb4_unicode_ci',
	`alarm_name_en` VARCHAR(1) NULL COMMENT 'Nome do alarme em inglês' COLLATE 'utf8mb4_unicode_ci',
	`protocol` ENUM('JIMI','JTT') NULL COMMENT 'Protocolo: JIMI (msgClass=0) ou JTT (msgClass=1)' COLLATE 'utf8mb4_unicode_ci',
	`category` VARCHAR(1) NULL COMMENT 'Categoria do alarme (ADAS, DMS, Sistema, etc)' COLLATE 'utf8mb4_unicode_ci',
	`alarm_description` TEXT NULL COMMENT 'Descrição detalhada do alarme' COLLATE 'utf8mb4_unicode_ci',
	`severity` ENUM('low','medium','high','critical','info','warning') NULL COLLATE 'utf8mb4_unicode_ci',
	`requires_action` TINYINT(1) NULL COMMENT 'Se requer ação imediata',
	`protocol_name` VARCHAR(1) NOT NULL COLLATE 'utf8mb4_0900_ai_ci',
	`level_description` VARCHAR(1) NULL COLLATE 'utf8mb4_0900_ai_ci',
	`minutes_since_alarm` BIGINT NULL,
	`gps_status` VARCHAR(1) NOT NULL COLLATE 'utf8mb4_0900_ai_ci',
	`signal_drop_info` VARCHAR(1) NULL COLLATE 'utf8mb4_0900_ai_ci',
	`signal_cover_info` VARCHAR(1) NULL COLLATE 'utf8mb4_0900_ai_ci'
);

-- Copiando estrutura para view jimi_tracker.vw_alarm_types_ambiguous_codes
-- Criando tabela temporária para evitar erros de dependência de VIEW
CREATE TABLE `vw_alarm_types_ambiguous_codes` 
);

-- Copiando estrutura para view jimi_tracker.vw_alarm_types_unknown_codes
-- Criando tabela temporária para evitar erros de dependência de VIEW
CREATE TABLE `vw_alarm_types_unknown_codes` 
);

-- Copiando estrutura para trigger jimi_tracker.trg_alarms_before_insert
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER `trg_alarms_before_insert` BEFORE INSERT ON `alarms` FOR EACH ROW BEGIN
    DECLARE v_alarm_name VARCHAR(200);
    
    
    IF NEW.alarm_name IS NULL THEN
        SELECT COALESCE(alarm_name_pt, alarm_name_en)
        INTO v_alarm_name
        FROM alarm_types
        WHERE alarm_code = NEW.alarm_type
        AND protocol = CASE 
            WHEN NEW.msg_class = 0 THEN 'JIMI'
            WHEN NEW.msg_class = 1 THEN 'JTT'
            ELSE NULL
        END
        LIMIT 1;
        
        
        IF v_alarm_name IS NOT NULL THEN
            SET NEW.alarm_name = v_alarm_name;
        END IF;
    END IF;
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

-- Removendo tabela temporária e criando a estrutura VIEW final
DROP TABLE IF EXISTS `v_alarm_report`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_alarm_report` AS select `a`.`id` AS `alarm_id`,`a`.`imei` AS `imei`,`d`.`device_name` AS `device_name`,`a`.`alarm_time` AS `alarm_time`,coalesce(`at`.`alarm_name_pt`,`at`.`alarm_name_en`,`a`.`alarm_name`,concat('Código: ',`a`.`alarm_type`,if((`a`.`alarm_subtype` is not null),concat('-',`a`.`alarm_subtype`),''))) AS `alarm_name`,coalesce(`at`.`severity`,'info') AS `severity`,`a`.`latitude` AS `latitude`,`a`.`longitude` AS `longitude`,`a`.`file_url` AS `file_url`,`a`.`alarm_type` AS `alarm_type`,`a`.`alarm_subtype` AS `alarm_subtype` from ((`alarms` `a` left join `devices` `d` on((`a`.`imei` = `d`.`imei`))) left join `alarm_types` `at` on((((`a`.`msg_class` = 1) and (`at`.`protocol` = 'JTT') and (`at`.`alarm_code` = concat(`a`.`alarm_type`,'-',`a`.`alarm_subtype`))) or ((`a`.`msg_class` = 1) and (`at`.`protocol` = 'JTT') and (`at`.`alarm_code` = `a`.`alarm_type`) and (`a`.`alarm_subtype` is null)) or ((`a`.`msg_class` = 0) and (`at`.`protocol` = 'JIMI') and (`at`.`alarm_code` = `a`.`alarm_type`))))) order by `a`.`alarm_time` desc
;

-- Removendo tabela temporária e criando a estrutura VIEW final
DROP TABLE IF EXISTS `v_alarm_statistics`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_alarm_statistics` AS select `a`.`alarm_type` AS `alarm_type`,`a`.`alarm_name` AS `alarm_name`,`a`.`msg_class` AS `msg_class`,`at`.`protocol` AS `protocol`,`at`.`category` AS `category`,`at`.`severity` AS `severity`,count(0) AS `total_count`,count((case when (`a`.`status` = 'active') then 1 end)) AS `active_count`,count((case when (`a`.`latitude` is not null) then 1 end)) AS `with_coords`,count((case when (`a`.`file_url` is not null) then 1 end)) AS `with_media`,min(`a`.`created_at`) AS `first_occurrence`,max(`a`.`created_at`) AS `last_occurrence` from (`alarms` `a` left join `alarm_types` `at` on(((`a`.`alarm_type` = `at`.`alarm_code`) and (`at`.`protocol` = (case when (`a`.`msg_class` = 0) then 'JIMI' else 'JTT' end))))) group by `a`.`alarm_type`,`a`.`alarm_name`,`a`.`msg_class`,`at`.`protocol`,`at`.`category`,`at`.`severity`
;

-- Removendo tabela temporária e criando a estrutura VIEW final
DROP TABLE IF EXISTS `v_alarms_enriched`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_alarms_enriched` AS select `a`.`id` AS `id`,`a`.`imei` AS `imei`,`a`.`alarm_type` AS `alarm_type`,`a`.`alarm_name` AS `alarm_name`,`a`.`alert_value` AS `alert_value`,`a`.`alarm_serial_no` AS `alarm_serial_no`,`a`.`msg_class` AS `msg_class`,`a`.`fence_id` AS `fence_id`,`a`.`driver_id` AS `driver_id`,`a`.`driver_name` AS `driver_name`,`a`.`alarm_time` AS `alarm_time`,`a`.`gps_time` AS `gps_time`,`a`.`gateway_time` AS `gateway_time`,`a`.`latitude` AS `latitude`,`a`.`longitude` AS `longitude`,`a`.`speed` AS `speed`,`a`.`car_speed` AS `car_speed`,`a`.`car_status` AS `car_status`,`a`.`satellite_num` AS `satellite_num`,`a`.`gps_num` AS `gps_num`,`a`.`gps_mode` AS `gps_mode`,`a`.`direction` AS `direction`,`a`.`altitude` AS `altitude`,`a`.`status` AS `status`,`a`.`file_url` AS `file_url`,`a`.`file_type` AS `file_type`,`a`.`alarm_data` AS `alarm_data`,`a`.`alarm_level` AS `alarm_level`,`a`.`alarm_status` AS `alarm_status`,`a`.`fatigue_level` AS `fatigue_level`,`a`.`alarm_id` AS `alarm_id`,`a`.`alarm_label` AS `alarm_label`,`a`.`standard_alarm_value` AS `standard_alarm_value`,`a`.`signal_drop_channel` AS `signal_drop_channel`,`a`.`signal_cover_channel` AS `signal_cover_channel`,`a`.`storage_fault_channel` AS `storage_fault_channel`,`a`.`driving_alarm_flag` AS `driving_alarm_flag`,`a`.`version` AS `version`,`a`.`description` AS `description`,`a`.`voltage` AS `voltage`,`a`.`raw_data` AS `raw_data`,`a`.`created_at` AS `created_at`,`at`.`alarm_name_pt` AS `alarm_name_pt`,`at`.`alarm_name_en` AS `alarm_name_en`,`at`.`protocol` AS `protocol`,`at`.`category` AS `category`,`at`.`description` AS `alarm_description`,`at`.`severity` AS `severity`,`at`.`requires_action` AS `requires_action`,(case when (`a`.`msg_class` = 0) then 'JIMI' when (`a`.`msg_class` = 1) then 'JTT' else 'UNKNOWN' end) AS `protocol_name`,(case `a`.`alarm_level` when 1 then 'Baixo' when 2 then 'Médio' when 3 then 'Alto' when 4 then 'Crítico' else NULL end) AS `level_description`,timestampdiff(MINUTE,`a`.`alarm_time`,now()) AS `minutes_since_alarm`,(case when ((`a`.`latitude` is not null) and (`a`.`longitude` is not null)) then 'Com GPS' else 'Sem GPS' end) AS `gps_status`,(case when (`a`.`signal_drop_channel` is not null) then concat('Canais com perda de sinal: ',`a`.`signal_drop_channel`) else NULL end) AS `signal_drop_info`,(case when (`a`.`signal_cover_channel` is not null) then concat('Canais obstruídos: ',`a`.`signal_cover_channel`) else NULL end) AS `signal_cover_info` from (`alarms` `a` left join `alarm_types` `at` on(((`a`.`alarm_type` = `at`.`alarm_code`) and (`at`.`protocol` = (case when (`a`.`msg_class` = 0) then 'JIMI' when (`a`.`msg_class` = 1) then 'JTT' else NULL end))))) order by `a`.`created_at` desc
;

-- Removendo tabela temporária e criando a estrutura VIEW final
DROP TABLE IF EXISTS `vw_alarm_types_ambiguous_codes`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vw_alarm_types_ambiguous_codes` AS select `a`.`id` AS `id`,`a`.`alarm_code` AS `alarm_code`,`a`.`protocol` AS `protocol`,`a`.`category` AS `category`,`a`.`severity` AS `severity`,`a`.`alarm_name_pt` AS `alarm_name_pt`,`a`.`alarm_name_en` AS `alarm_name_en`,`a`.`description` AS `description`,`a`.`requires_action` AS `requires_action`,`a`.`created_at` AS `created_at`,`a`.`updated_at` AS `updated_at`,`r`.`doc_ambiguous` AS `doc_ambiguous` from (`alarm_types` `a` join `alarm_types_reference` `r` on((((`a`.`protocol` collate utf8mb4_unicode_ci) = `r`.`protocol`) and ((cast(`a`.`alarm_code` as char charset utf8mb4) collate utf8mb4_unicode_ci) = `r`.`alarm_code`)))) where (`r`.`doc_ambiguous` = 1)
;

-- Removendo tabela temporária e criando a estrutura VIEW final
DROP TABLE IF EXISTS `vw_alarm_types_unknown_codes`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vw_alarm_types_unknown_codes` AS select `a`.`id` AS `id`,`a`.`alarm_code` AS `alarm_code`,`a`.`protocol` AS `protocol`,`a`.`category` AS `category`,`a`.`severity` AS `severity`,`a`.`alarm_name_pt` AS `alarm_name_pt`,`a`.`alarm_name_en` AS `alarm_name_en`,`a`.`description` AS `description`,`a`.`requires_action` AS `requires_action`,`a`.`created_at` AS `created_at`,`a`.`updated_at` AS `updated_at` from (`alarm_types` `a` left join `alarm_types_reference` `r` on((((`a`.`protocol` collate utf8mb4_unicode_ci) = `r`.`protocol`) and ((cast(`a`.`alarm_code` as char charset utf8mb4) collate utf8mb4_unicode_ci) = `r`.`alarm_code`)))) where (`r`.`alarm_code` is null)
;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;

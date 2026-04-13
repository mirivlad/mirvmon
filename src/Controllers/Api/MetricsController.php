<?php
// src/Controllers/Api/MetricsController.php

namespace App\Controllers\Api;

use App\Models\Model;
use App\Services\NotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Config\DatabaseConfig;

class MetricsController extends Model
{
    private $notificationService;

    public function __construct()
    {
        parent::__construct();
        $this->notificationService = new NotificationService();
    }

    public function collectMetrics(Request $request, Response $response, $args)
    {
        $input = json_decode($request->getBody(), true);

        if (!$input || !isset($input['token'])) {
            return $response->withStatus(400);
        }

        $token = $input['token'];
        $metrics = $input['metrics'] ?? [];
        $services = $input['services'] ?? [];

        // Проверяем токен (сравниваем хеш)
        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare("
            SELECT at.server_id, s.name as server_name
            FROM agent_tokens at
            JOIN servers s ON at.server_id = s.id
            WHERE at.token_hash = :token_hash
        ");

        $stmt->execute([':token_hash' => $tokenHash]);
        $tokenInfo = $stmt->fetch();

        if (!$tokenInfo) {
            return $response->withStatus(401);
        }

        $serverId = $tokenInfo['server_id'];
        $serverName = $tokenInfo['server_name'];

        // Обновляем время последних метрик для сервера
        $stmt = $this->pdo->prepare("
            UPDATE servers
            SET last_metrics_at = NOW()
            WHERE id = :server_id
        ");
        $stmt->execute([':server_id' => $serverId]);

        // Сохраняем метрики если есть
        if (!empty($metrics)) {
            foreach ($metrics as $metricName => $value) {
                // Получаем ID метрики из справочника
                $stmt = $this->pdo->prepare("SELECT id FROM metric_names WHERE name = :name");
                $stmt->execute([':name' => $metricName]);
                $metricInfo = $stmt->fetch();

                if ($metricInfo) {
                    $metricId = $metricInfo['id'];

                    // Сохраняем метрику
                    $stmt = $this->pdo->prepare("
                        INSERT INTO server_metrics (server_id, metric_name_id, value)
                        VALUES (:server_id, :metric_name_id, :value)
                    ");
                    $stmt->execute([
                        ':server_id' => $serverId,
                        ':metric_name_id' => $metricId,
                        ':value' => $value
                    ]);

                    // Проверяем пороги и отправляем уведомления
                    $this->checkThresholds($serverId, $metricId, $value, $metricName, $serverName);
                }
            }
        }

        // Сохраняем статусы сервисов если есть
        if (!empty($services)) {
            foreach ($services as $service) {
                $serviceName = $service['name'] ?? '';
                $serviceStatus = $service['status'] ?? 'unknown';
                $loadState = $service['load_state'] ?? '';
                $activeState = $service['active_state'] ?? '';
                $subState = $service['sub_state'] ?? '';

                if (empty($serviceName)) {
                    continue;
                }

                // Обновляем статус сервиса (INSERT OR UPDATE)
                $stmt = $this->pdo->prepare("
                    INSERT INTO service_status (server_id, service_name, status, load_state, active_state, sub_state, updated_at)
                    VALUES (:server_id, :service_name, :status, :load_state, :active_state, :sub_state, NOW())
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        load_state = VALUES(load_state),
                        active_state = VALUES(active_state),
                        sub_state = VALUES(sub_state),
                        updated_at = NOW()
                ");

                $stmt->execute([
                    ':server_id' => $serverId,
                    ':service_name' => $serviceName,
                    ':status' => $serviceStatus,
                    ':load_state' => $loadState,
                    ':active_state' => $activeState,
                    ':sub_state' => $subState
                ]);

                // Если сервис остановлен - создаем алерт
                if ($serviceStatus === 'stopped') {
                    $this->createServiceAlert($serverId, $serviceName, $serviceStatus, $serverName);
                }
            }

            // Обновляем время последней проверки сервисов
            $stmt = $this->pdo->prepare("
                UPDATE servers
                SET last_service_check_at = NOW()
                WHERE id = :server_id
            ");
            $stmt->execute([':server_id' => $serverId]);
        }

        // Обновляем время последнего использования токена
        $stmt = $this->pdo->prepare("
            UPDATE agent_tokens
            SET last_used_at = NOW()
            WHERE server_id = :server_id
        ");
        $stmt->execute([':server_id' => $serverId]);

        return $response->withStatus(200);
    }

    private function checkThresholds($serverId, $metricId, $value, $metricName, $serverName)
    {
        // Получаем пороговые значения для этой метрики на этом сервере
        $stmt = $this->pdo->prepare("
            SELECT warning_threshold, critical_threshold
            FROM metric_thresholds
            WHERE server_id = :server_id AND metric_name_id = :metric_name_id
        ");
        $stmt->execute([
            ':server_id' => $serverId,
            ':metric_name_id' => $metricId
        ]);
        $thresholds = $stmt->fetch();

        if ($thresholds) {
            $warningThreshold = $thresholds['warning_threshold'];
            $criticalThreshold = $thresholds['critical_threshold'];

            $severity = null;
            $threshold = null;
            if ($criticalThreshold && $value >= $criticalThreshold) {
                $severity = 'critical';
                $threshold = $criticalThreshold;
            } elseif ($warningThreshold && $value >= $warningThreshold) {
                $severity = 'warning';
                $threshold = $warningThreshold;
            }

            if ($severity) {
                // Проверяем есть ли уже неразрешённый алерт для этой метрики
                $stmt = $this->pdo->prepare("
                    SELECT id, severity FROM alerts
                    WHERE server_id = :server_id AND metric_name = :metric_name AND resolved = FALSE
                    ORDER BY created_at DESC LIMIT 1
                ");
                $stmt->execute([
                    ':server_id' => $serverId,
                    ':metric_name' => $metricName
                ]);
                $existingAlert = $stmt->fetch();

                if ($existingAlert) {
                    // Алерт уже есть — обновляем значение но НЕ отправляем уведомление
                    $stmt = $this->pdo->prepare("
                        UPDATE alerts SET value = :value WHERE id = :id
                    ");
                    $stmt->execute([
                        ':value' => $value,
                        ':id' => $existingAlert['id']
                    ]);

                    // Если серьёзность повысилась (warning -> critical) — отправляем
                    if ($severity === 'critical' && $existingAlert['severity'] === 'warning') {
                        $stmt = $this->pdo->prepare("
                            UPDATE alerts SET severity = :severity WHERE id = :id
                        ");
                        $stmt->execute([
                            ':severity' => $severity,
                            ':id' => $existingAlert['id']
                        ]);
                        $this->notificationService->sendAlertNotification(
                            $serverName,
                            $metricName,
                            $value,
                            $severity,
                            $threshold
                        );
                    }
                } else {
                    // Нового алерта нет — создаём и отправляем уведомление
                    $stmt = $this->pdo->prepare("
                        INSERT INTO alerts (server_id, metric_name, value, severity)
                        VALUES (:server_id, :metric_name, :value, :severity)
                    ");
                    $stmt->execute([
                        ':server_id' => $serverId,
                        ':metric_name' => $metricName,
                        ':value' => $value,
                        ':severity' => $severity
                    ]);

                    $this->notificationService->sendAlertNotification(
                        $serverName,
                        $metricName,
                        $value,
                        $severity,
                        $threshold
                    );
                }
            }
        }

        // Всегда проверяем resolved — даже если пороги не настроены или удалены
        // Если есть неразрешённый алерт а значение сейчас в норме — разрешаем
        $stmt = $this->pdo->prepare("
            SELECT id FROM alerts
            WHERE server_id = :server_id AND metric_name = :metric_name AND resolved = FALSE
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([
            ':server_id' => $serverId,
            ':metric_name' => $metricName
        ]);
        $existingAlert = $stmt->fetch();

        if ($existingAlert) {
            // Проверяем действительно ли значение в норме
            // (если пороги есть — проверяем по ним, если нет — считаем что в норме)
            $isNormal = true;
            if ($thresholds) {
                $w = $thresholds['warning_threshold'];
                $c = $thresholds['critical_threshold'];
                if (($c && $value >= $c) || ($w && $value >= $w)) {
                    $isNormal = false;
                }
            }

            if ($isNormal) {
                $stmt = $this->pdo->prepare("
                    UPDATE alerts SET resolved = TRUE, resolved_at = NOW() WHERE id = :id
                ");
                $stmt->execute([':id' => $existingAlert['id']]);

                $this->notificationService->sendAlertNotification(
                    $serverName,
                    $metricName,
                    $value,
                    'resolved',
                    'Порог более не превышен'
                );
            }
        }
    }

    private function createServiceAlert($serverId, $serviceName, $status, $serverName)
    {
        // Проверяем есть ли уже неразрешенный алерт для этого сервиса
        $stmt = $this->pdo->prepare("
            SELECT id FROM service_alerts
            WHERE server_id = :server_id AND service_name = :service_name AND resolved = FALSE
            ORDER BY created_at DESC LIMIT 1
        ");

        $stmt->execute([
            ':server_id' => $serverId,
            ':service_name' => $serviceName
        ]);

        $existingAlert = $stmt->fetch();

        // Если алерта нет - создаем новый и отправляем уведомление
        if (!$existingAlert) {
            $stmt = $this->pdo->prepare("
                INSERT INTO service_alerts (server_id, service_name, status, severity)
                VALUES (:server_id, :service_name, :status, 'critical')
            ");

            $stmt->execute([
                ':server_id' => $serverId,
                ':service_name' => $serviceName,
                ':status' => $status
            ]);

            // Отправляем уведомление о остановке сервиса
            $this->notificationService->sendAlertNotification(
                $serverName,
                "Сервис: {$serviceName}",
                $status,
                'critical',
                'running'
            );
        }
    }

    public function getServices(Request $request, Response $response, $args)
    {
        $serverId = $args['id'];

        $stmt = $this->pdo->prepare("
            SELECT service_name, status, load_state, active_state, sub_state, updated_at
            FROM service_status
            WHERE server_id = :server_id
            ORDER BY service_name
        ");
        $stmt->execute([':server_id' => $serverId]);
        $services = $stmt->fetchAll();

        $response->getBody()->write(json_encode(['services' => $services]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getProcesses(Request $request, Response $response, $args)
    {
        $serverId = $args['id'];
        $timeParam = $request->getQueryParams()['time'] ?? null;

        if (!$timeParam) {
            return $response->withStatus(400)->getBody()->write(json_encode(['error' => 'Time parameter required']));
        }

        $timestamp = strtotime($timeParam);
        // Парсинг формата d.m H:i (12.04 07:48)
        if ($timestamp === false && preg_match("/^(\d{1,2})\.(\d{2}) (\d{1,2}):(\d{2})$/", $timeParam, $m)) {
            $timestamp = strtotime(date("Y") . "-" . $m[2] . "-" . $m[1] . " " . $m[3] . ":" . $m[4] . ":00");
        }

        if ($timestamp === false && preg_match('/^\d{1,2}:\d{2}$/', $timeParam)) {
            $today = date('Y-m-d');
            $timestamp = strtotime($today . ' ' . $timeParam);
        }

        if ($timestamp === false) {
            return $response->withStatus(400)->getBody()->write(json_encode(['error' => 'Invalid time format']));
        }

        $time = date('Y-m-d H:i:s', $timestamp);

        $stmt = $this->pdo->prepare("
            SELECT value FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :server_id AND mn.name = 'top_cpu_proc'
            AND sm.created_at BETWEEN DATE_SUB(:time1, INTERVAL 30 SECOND) AND DATE_ADD(:time2, INTERVAL 30 SECOND)
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, sm.created_at, :time3)) LIMIT 1
        ");
        $stmt->execute([':server_id' => $serverId, ':time1' => $time, ':time2' => $time, ':time3' => $time]);
        $topCpuResult = $stmt->fetch();

        $stmt = $this->pdo->prepare("
            SELECT value FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :server_id AND mn.name = 'top_ram_proc'
            AND sm.created_at BETWEEN DATE_SUB(:time1, INTERVAL 30 SECOND) AND DATE_ADD(:time2, INTERVAL 30 SECOND)
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, sm.created_at, :time3)) LIMIT 1
        ");
        $stmt->execute([':server_id' => $serverId, ':time1' => $time, ':time2' => $time, ':time3' => $time]);
        $topRamResult = $stmt->fetch();

        $topCpu = $topCpuResult ? json_decode($topCpuResult['value'], true) : [];
        $topRam = $topRamResult ? json_decode($topRamResult['value'], true) : [];

        $response->getBody()->write(json_encode([
            'top_cpu' => $topCpu,
            'top_ram' => $topRam,
            'time' => $time
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getMetrics(Request $request, Response $response, $args)
    {
        $serverId = $args['id'];
        $from = $request->getQueryParams()['from'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
        $to = $request->getQueryParams()['to'] ?? date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            SELECT sm.value, mn.name, mn.unit, sm.created_at
            FROM server_metrics sm
            JOIN metric_names mn ON sm.metric_name_id = mn.id
            WHERE sm.server_id = :id
            AND sm.created_at BETWEEN :from AND :to
            AND mn.name NOT LIKE '%_proc'
            ORDER BY sm.created_at ASC
        ");
        $stmt->execute([':id' => $serverId, ':from' => $from, ':to' => $to]);
        $metrics = $stmt->fetchAll();

        $grouped = [];
        foreach ($metrics as $m) {
            $name = $m['name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [];
            }
            $grouped[$name][] = [
                'value' => (float)$m['value'],
                'time' => $m['created_at'],
                'unit' => $m['unit']
            ];
        }

        $response->getBody()->write(json_encode([
            'server_id' => (int)$serverId,
            'from' => $from,
            'to' => $to,
            'metrics' => $grouped
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

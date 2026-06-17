<?php
require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/MailService.php';

/**
 * RUMS — Notification Service
 *
 * Orchestrates SMS (Africa's Talking), Email (SMTP / mail()),
 * and in-app notifications. Handles:
 *   - Template rendering ({{PLACEHOLDER}} substitution)
 *   - Communication log persistence
 *   - Payment & lease reminder batches
 *   - Broadcast campaigns
 */
class NotificationService extends BaseService
{
    private SmsService  $sms;
    private MailService $mail;

    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        $this->sms  = $this->buildSms();
        $this->mail = $this->buildMail();
    }

    // ── Factory helpers ────────────────────────────────────────

    private function buildSms(): SmsService
    {
        $cfg = $this->fetchSettingsGroup(['sms_api_key', 'sms_username', 'sms_sender_id']);
        return new SmsService([
            'api_key'   => $cfg['sms_api_key']   ?? '',
            'username'  => $cfg['sms_username']   ?? 'sandbox',
            'sender_id' => $cfg['sms_sender_id']  ?? '',
        ]);
    }

    private function buildMail(): MailService
    {
        $cfg = $this->fetchSettingsGroup([
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
            'smtp_encryption', 'mail_from_name', 'mail_from_email',
        ]);
        return new MailService([
            'smtp_host'       => $cfg['smtp_host']       ?? '',
            'smtp_port'       => (int)($cfg['smtp_port'] ?? 587),
            'smtp_user'       => $cfg['smtp_user']       ?? '',
            'smtp_pass'       => $cfg['smtp_pass']       ?? '',
            'smtp_encryption' => $cfg['smtp_encryption'] ?? 'tls',
            'from_name'       => $cfg['mail_from_name']  ?? ($cfg['company_name'] ?? 'RUMS'),
            'from_email'      => $cfg['mail_from_email'] ?? ($cfg['smtp_user'] ?? ''),
        ]);
    }

    private function fetchSettingsGroup(array $keys): array
    {
        if (empty($keys)) return [];
        $in     = implode(',', array_fill(0, count($keys), '?'));
        $rows   = $this->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ($in)", $keys);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    private function settingVal(string $key, string $default = ''): string
    {
        $row = $this->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row ? (string)$row['value'] : $default;
    }

    // ── Template rendering ─────────────────────────────────────

    /**
     * Replace {{PLACEHOLDER}} tokens in a template body.
     */
    public function renderTemplate(string $body, array $vars): string
    {
        foreach ($vars as $key => $val) {
            $body = str_replace('{{' . strtoupper($key) . '}}', (string)$val, $body);
        }
        return $body;
    }

    /**
     * Resolve standard template variables for a given tenant/lease context.
     * Returns an assoc array keyed as the uppercase placeholder name (lowercase key).
     */
    public function resolveVarsForTenant(int $tenantId, ?int $leaseId = null, array $extra = []): array
    {
        $companyName = $this->settingVal('company_name', 'RUMS');

        $tenant = $this->fetchOne(
            "SELECT first_name, last_name, email, phone FROM tenants WHERE id = ?",
            [$tenantId]
        );

        $vars = [
            'company_name' => $companyName,
            'tenant_name'  => $tenant ? trim($tenant['first_name'] . ' ' . $tenant['last_name']) : '',
        ];

        if ($leaseId) {
            $lease = $this->fetchOne(
                "SELECT l.lease_number, l.start_date, l.end_date, l.monthly_rent, l.payment_day,
                        u.unit_number, pr.name AS property_name
                 FROM leases l
                 JOIN units u      ON u.id  = l.unit_id
                 JOIN properties pr ON pr.id = u.property_id
                 WHERE l.id = ?",
                [$leaseId]
            );
            if ($lease) {
                $vars['lease_number']  = $lease['lease_number'];
                $vars['unit_number']   = $lease['unit_number'];
                $vars['property_name'] = $lease['property_name'];
                $vars['monthly_rent']  = number_format((float)$lease['monthly_rent'], 2);
                $vars['payment_day']   = $lease['payment_day'];
                $vars['start_date']    = $lease['start_date'];
                $vars['end_date']      = $lease['end_date'];
                if ($lease['end_date']) {
                    $vars['days_remaining'] = max(0, (int)ceil(
                        (strtotime($lease['end_date']) - time()) / 86400
                    ));
                }
            }
        }

        return array_merge($vars, $extra);
    }

    // ── Core send methods ──────────────────────────────────────

    /**
     * Send an SMS, log it, optionally link to a tenant.
     */
    public function sendSms(
        string $phone,
        string $message,
        ?int   $tenantId    = null,
        ?int   $templateId  = null,
        ?int   $broadcastId = null,
        ?int   $sentBy      = null
    ): array {
        $result = $this->sms->send($phone, $message);

        $this->logCommunication([
            'tenant_id'    => $tenantId,
            'recipient'    => $phone,
            'channel'      => 'sms',
            'template_id'  => $templateId,
            'body'         => $message,
            'status'       => $result['success'] ? 'sent' : 'failed',
            'error_message'=> $result['error'] ?? null,
            'provider'     => 'africastalking',
            'provider_ref' => $result['message_id'] ?? null,
            'broadcast_id' => $broadcastId,
            'sent_by'      => $sentBy,
            'sent_at'      => $result['success'] ? date('Y-m-d H:i:s') : null,
        ]);

        return $result;
    }

    /**
     * Send an email, log it, optionally link to a tenant.
     */
    public function sendEmail(
        string  $email,
        string  $subject,
        string  $htmlBody,
        ?int    $tenantId    = null,
        ?int    $templateId  = null,
        ?int    $broadcastId = null,
        ?int    $sentBy      = null,
        ?string $textBody    = null
    ): array {
        $result = $this->mail->send($email, $subject, $htmlBody, $textBody);

        $this->logCommunication([
            'tenant_id'    => $tenantId,
            'recipient'    => $email,
            'channel'      => 'email',
            'template_id'  => $templateId,
            'subject'      => $subject,
            'body'         => $htmlBody,
            'status'       => $result['success'] ? 'sent' : 'failed',
            'error_message'=> $result['error'] ?? null,
            'provider'     => $result['provider'] ?? null,
            'provider_ref' => $result['message_id'] ?? null,
            'broadcast_id' => $broadcastId,
            'sent_by'      => $sentBy,
            'sent_at'      => $result['success'] ? date('Y-m-d H:i:s') : null,
        ]);

        return $result;
    }

    /**
     * Create an in-app notification for a user linked to a tenant.
     */
    public function sendInApp(
        int     $userId,
        string  $title,
        string  $message,
        string  $type       = 'info',
        ?string $link       = null,
        ?int    $tenantId   = null,
        ?int    $sentBy     = null
    ): int {
        $id = $this->insert(
            "INSERT INTO notifications (user_id, title, message, type, link) VALUES (?,?,?,?,?)",
            [$userId, $title, $message, $type, $link ?? '']
        );

        $this->logCommunication([
            'tenant_id' => $tenantId,
            'recipient' => "user:$userId",
            'channel'   => 'in_app',
            'body'      => $message,
            'status'    => 'delivered',
            'provider'  => 'internal',
            'sent_by'   => $sentBy,
            'sent_at'   => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    // ── Reminder batches ───────────────────────────────────────

    /**
     * Send payment reminders for all unpaid invoices due within the given days.
     * Returns ['sent'=>N, 'failed'=>N, 'skipped'=>N].
     */
    public function sendPaymentReminders(int $dueDays = 3, ?int $sentBy = null): array
    {
        $rows = $this->fetchAll(
            "SELECT i.id AS invoice_id, i.invoice_number, i.total_amount,
                    i.amount_paid, (i.total_amount - COALESCE(i.amount_paid,0)) AS amount_due,
                    i.due_date, i.tenant_id, i.lease_id,
                    t.first_name, t.last_name, t.phone, t.email,
                    u.unit_number, pr.name AS property_name, pr.id AS property_id
             FROM invoices i
             JOIN tenants t     ON t.id  = i.tenant_id
             JOIN leases l      ON l.id  = i.lease_id
             JOIN units u       ON u.id  = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE i.status IN ('unpaid','partial')
               AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY i.due_date ASC",
            [$dueDays]
        );

        $smsTemplate   = $this->getActiveTemplate('payment', 'sms');
        $emailTemplate = $this->getActiveTemplate('payment', 'email');
        $companyName   = $this->settingVal('company_name', 'RUMS');

        $sent = $failed = $skipped = 0;

        foreach ($rows as $row) {
            $vars = [
                'tenant_name'    => trim($row['first_name'] . ' ' . $row['last_name']),
                'amount_due'     => number_format((float)$row['amount_due'], 2),
                'unit_number'    => $row['unit_number'],
                'property_name'  => $row['property_name'],
                'due_date'       => $row['due_date'],
                'invoice_number' => $row['invoice_number'],
                'company_name'   => $companyName,
            ];

            $anySent = false;

            // SMS
            if ($row['phone'] && $smsTemplate) {
                $body   = $this->renderTemplate($smsTemplate['body'], $vars);
                $result = $this->sendSms(
                    SmsService::formatPhone($row['phone']),
                    $body,
                    $row['tenant_id'],
                    $smsTemplate['id'],
                    null,
                    $sentBy
                );
                $result['success'] ? $anySent = true : $failed++;
            }

            // Email
            if ($row['email'] && $emailTemplate) {
                $html    = $this->renderTemplate($emailTemplate['body'], $vars);
                $subject = $this->renderTemplate($emailTemplate['subject'] ?? 'Payment Reminder', $vars);
                $result  = $this->sendEmail(
                    $row['email'],
                    $subject,
                    $html,
                    $row['tenant_id'],
                    $emailTemplate['id'],
                    null,
                    $sentBy
                );
                $result['success'] ? $anySent = true : $failed++;
            }

            if (!$row['phone'] && !$row['email']) {
                $skipped++;
            } elseif ($anySent) {
                $sent++;
            }
        }

        return compact('sent', 'failed', 'skipped');
    }

    /**
     * Send lease expiry reminders for leases expiring within the given days.
     */
    public function sendLeaseReminders(int $expiryDays = 30, ?int $sentBy = null): array
    {
        $rows = $this->fetchAll(
            "SELECT l.id AS lease_id, l.lease_number, l.end_date, l.monthly_rent, l.payment_day,
                    l.tenant_id,
                    t.first_name, t.last_name, t.phone, t.email,
                    u.unit_number, pr.name AS property_name
             FROM leases l
             JOIN tenants t     ON t.id  = l.tenant_id
             JOIN units u       ON u.id  = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             WHERE l.status = 'active'
               AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY l.end_date ASC",
            [$expiryDays]
        );

        $smsTemplate   = $this->getActiveTemplate('lease', 'sms');
        $emailTemplate = $this->getActiveTemplate('lease', 'email');
        $companyName   = $this->settingVal('company_name', 'RUMS');

        $sent = $failed = $skipped = 0;

        foreach ($rows as $row) {
            $vars = [
                'tenant_name'   => trim($row['first_name'] . ' ' . $row['last_name']),
                'unit_number'   => $row['unit_number'],
                'property_name' => $row['property_name'],
                'end_date'      => $row['end_date'],
                'days_remaining'=> max(0, (int)ceil((strtotime($row['end_date']) - time()) / 86400)),
                'lease_number'  => $row['lease_number'],
                'monthly_rent'  => number_format((float)$row['monthly_rent'], 2),
                'company_name'  => $companyName,
            ];

            $anySent = false;

            if ($row['phone'] && $smsTemplate) {
                $body   = $this->renderTemplate($smsTemplate['body'], $vars);
                $result = $this->sendSms(
                    SmsService::formatPhone($row['phone']),
                    $body,
                    $row['tenant_id'],
                    $smsTemplate['id'],
                    null,
                    $sentBy
                );
                $result['success'] ? $anySent = true : $failed++;
            }

            if ($row['email'] && $emailTemplate) {
                $html    = $this->renderTemplate($emailTemplate['body'], $vars);
                $subject = $this->renderTemplate($emailTemplate['subject'] ?? 'Lease Expiry Notice', $vars);
                $result  = $this->sendEmail(
                    $row['email'],
                    $subject,
                    $html,
                    $row['tenant_id'],
                    $emailTemplate['id'],
                    null,
                    $sentBy
                );
                $result['success'] ? $anySent = true : $failed++;
            }

            if (!$row['phone'] && !$row['email']) {
                $skipped++;
            } elseif ($anySent) {
                $sent++;
            }
        }

        return compact('sent', 'failed', 'skipped');
    }

    // ── Broadcast ──────────────────────────────────────────────

    /**
     * Execute a broadcast: resolve recipients, send, update counts.
     */
    public function sendBroadcast(int $broadcastId, ?int $sentBy = null): array
    {
        $broadcast = $this->fetchOne("SELECT * FROM broadcast_messages WHERE id = ?", [$broadcastId]);
        if (!$broadcast) return ['success' => false, 'error' => 'Broadcast not found.'];
        if (!in_array($broadcast['status'], ['draft', 'failed'])) {
            return ['success' => false, 'error' => 'Broadcast is not in a sendable state.'];
        }

        // Mark as sending
        $this->execute(
            "UPDATE broadcast_messages SET status='sending', started_at=NOW() WHERE id=?",
            [$broadcastId]
        );

        $recipients = $this->resolveBroadcastRecipients($broadcast['recipient_filter']);
        $total      = count($recipients);
        $sent       = 0;
        $failed     = 0;
        $channel    = $broadcast['channel']; // sms | email | both

        foreach ($recipients as $r) {
            $vars = [
                'tenant_name'   => $r['tenant_name'],
                'unit_number'   => $r['unit_number']   ?? '',
                'property_name' => $r['property_name'] ?? '',
                'company_name'  => $this->settingVal('company_name', 'RUMS'),
            ];
            $message = $this->renderTemplate($broadcast['message'], $vars);
            $subject = $this->renderTemplate($broadcast['subject'] ?? 'Message from Management', $vars);

            $ok = false;

            if (in_array($channel, ['sms', 'both']) && !empty($r['phone'])) {
                $res = $this->sendSms(
                    SmsService::formatPhone($r['phone']),
                    $message,
                    $r['tenant_id'],
                    $broadcast['template_id'] ?: null,
                    $broadcastId,
                    $sentBy
                );
                if ($res['success']) $ok = true;
            }

            if (in_array($channel, ['email', 'both']) && !empty($r['email'])) {
                $res = $this->sendEmail(
                    $r['email'],
                    $subject,
                    nl2br(htmlspecialchars($message)),
                    $r['tenant_id'],
                    $broadcast['template_id'] ?: null,
                    $broadcastId,
                    $sentBy
                );
                if ($res['success']) $ok = true;
            }

            $ok ? $sent++ : $failed++;
        }

        $this->execute(
            "UPDATE broadcast_messages
             SET status='sent', completed_at=NOW(),
                 total_recipients=?, sent_count=?, failed_count=?
             WHERE id=?",
            [$total, $sent, $failed, $broadcastId]
        );

        return ['success' => true, 'total' => $total, 'sent' => $sent, 'failed' => $failed];
    }

    private function resolveBroadcastRecipients(?string $filterJson): array
    {
        $filter     = $filterJson ? (json_decode($filterJson, true) ?? []) : [];
        $where      = ['l.status = \'active\''];
        $params     = [];

        if (!empty($filter['property_id'])) {
            $where[]  = 'u.property_id = ?';
            $params[] = (int)$filter['property_id'];
        }
        if (!empty($filter['has_overdue'])) {
            $where[] = "EXISTS (SELECT 1 FROM invoices i WHERE i.tenant_id = t.id AND i.status IN ('unpaid','partial') AND i.due_date < CURDATE())";
        }

        $w = 'WHERE ' . implode(' AND ', $where);

        return $this->fetchAll(
            "SELECT DISTINCT t.id AS tenant_id,
                    CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                    t.phone, t.email,
                    u.unit_number, pr.name AS property_name
             FROM leases l
             JOIN tenants t     ON t.id  = l.tenant_id
             JOIN units u       ON u.id  = l.unit_id
             JOIN properties pr ON pr.id = u.property_id
             $w
             ORDER BY tenant_name",
            $params
        );
    }

    // ── Template helpers ───────────────────────────────────────

    public function getActiveTemplate(string $category, string $channel): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM message_templates
             WHERE category = ? AND channel IN (?, 'both') AND is_active = 1
             ORDER BY channel = ? DESC, id ASC
             LIMIT 1",
            [$category, $channel, $channel]
        );
    }

    // ── Communication log ──────────────────────────────────────

    private function logCommunication(array $data): void
    {
        $cols = array_keys($data);
        $sql  = "INSERT INTO communication_logs (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")";
        try {
            $this->execute($sql, array_values($data));
        } catch (Throwable) {
            // Logging must never break the send flow
        }
    }

    // ── Log queries ────────────────────────────────────────────

    public function getLogs(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['tenant_id'])) { $where[] = 'cl.tenant_id = ?';  $params[] = (int)$filters['tenant_id']; }
        if (!empty($filters['channel']))   { $where[] = 'cl.channel = ?';     $params[] = $filters['channel']; }
        if (!empty($filters['status']))    { $where[] = 'cl.status = ?';      $params[] = $filters['status']; }
        if (!empty($filters['date_from'])) { $where[] = 'cl.created_at >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = 'cl.created_at <= ?'; $params[] = $filters['date_to'] . ' 23:59:59'; }

        $w   = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT cl.*,
                    CONCAT(t.first_name,' ',t.last_name) AS tenant_name,
                    mt.name AS template_name,
                    CONCAT(u.first_name,' ',u.last_name) AS sent_by_name
                FROM communication_logs cl
                LEFT JOIN tenants t           ON t.id  = cl.tenant_id
                LEFT JOIN message_templates mt ON mt.id = cl.template_id
                LEFT JOIN users u             ON u.id  = cl.sent_by
                $w ORDER BY cl.created_at DESC";

        $countSql = "SELECT COUNT(*) FROM communication_logs cl $w";

        return $this->paginatedQuery($sql, $params, $countSql, $params, $page, $perPage);
    }

    // ── Broadcast CRUD ─────────────────────────────────────────

    public function getBroadcasts(int $page = 1, int $perPage = 20): array
    {
        $sql = "SELECT b.*,
                    CONCAT(u.first_name,' ',u.last_name) AS created_by_name,
                    mt.name AS template_name
                FROM broadcast_messages b
                LEFT JOIN users u             ON u.id  = b.created_by
                LEFT JOIN message_templates mt ON mt.id = b.template_id
                ORDER BY b.created_at DESC";

        $countSql = "SELECT COUNT(*) FROM broadcast_messages";

        return $this->paginatedQuery($sql, [], $countSql, [], $page, $perPage);
    }

    public function findBroadcast(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT b.*,
                    CONCAT(u.first_name,' ',u.last_name) AS created_by_name
             FROM broadcast_messages b
             LEFT JOIN users u ON u.id = b.created_by
             WHERE b.id = ?",
            [$id]
        );
    }

    public function createBroadcast(array $data, int $userId): array
    {
        $missing = $this->requireFields($data, ['title', 'channel', 'message']);
        if ($missing) return ['success' => false, 'errors' => $missing];

        $id = $this->insert(
            "INSERT INTO broadcast_messages
                (title, channel, subject, message, template_id, recipient_filter, status, scheduled_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [
                $data['title'],
                $data['channel'],
                $data['subject']          ?? null,
                $data['message'],
                !empty($data['template_id']) ? (int)$data['template_id'] : null,
                !empty($data['recipient_filter']) ? json_encode($data['recipient_filter']) : null,
                'draft',
                $data['scheduled_at']     ?? null,
                $userId,
            ]
        );

        return ['success' => true, 'id' => $id];
    }

    public function cancelBroadcast(int $id): bool
    {
        return (bool)$this->execute(
            "UPDATE broadcast_messages SET status='cancelled' WHERE id=? AND status='draft'",
            [$id]
        );
    }

    // ── Template CRUD ──────────────────────────────────────────

    public function listTemplates(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['category'])) { $where[] = 'category = ?'; $params[] = $filters['category']; }
        if (!empty($filters['channel']))  { $where[] = 'channel = ?';  $params[] = $filters['channel']; }

        $w = 'WHERE ' . implode(' AND ', $where);
        return $this->fetchAll(
            "SELECT mt.*,
                    CONCAT(u.first_name,' ',u.last_name) AS created_by_name
             FROM message_templates mt
             LEFT JOIN users u ON u.id = mt.created_by
             $w ORDER BY mt.category, mt.name",
            $params
        );
    }

    public function findTemplate(int $id): ?array
    {
        return $this->fetchOne("SELECT * FROM message_templates WHERE id = ?", [$id]);
    }

    public function createTemplate(array $data, int $userId): array
    {
        $missing = $this->requireFields($data, ['name', 'category', 'channel', 'body']);
        if ($missing) return ['success' => false, 'errors' => $missing];

        $id = $this->insert(
            "INSERT INTO message_templates (name, category, channel, subject, body, is_active, created_by)
             VALUES (?,?,?,?,?,?,?)",
            [
                $data['name'],
                $data['category'],
                $data['channel'],
                $data['subject']   ?? null,
                $data['body'],
                isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
                $userId,
            ]
        );

        return ['success' => true, 'id' => $id];
    }

    public function updateTemplate(int $id, array $data): array
    {
        $allowed = $this->only($data, ['name', 'category', 'channel', 'subject', 'body', 'is_active']);
        if (empty($allowed)) return ['success' => false, 'error' => 'Nothing to update.'];

        [$set, $vals] = $this->buildSet($allowed);
        $vals[] = $id;
        $this->execute("UPDATE message_templates SET $set WHERE id = ?", $vals);

        return ['success' => true];
    }

    public function deleteTemplate(int $id): bool
    {
        return (bool)$this->execute("DELETE FROM message_templates WHERE id = ?", [$id]);
    }
}

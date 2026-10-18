<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Audit;

use DmLab\AdminScim\Model\Auth\ClientIp;
use Psr\Log\LoggerInterface;

/**
 * Writes SCIM audit events to the dedicated `dmlab_admin_scim_audit` log channel.
 *
 * Callers pass ids and names only — never the token, headers or the raw body.
 * A failed audit write goes to the system log instead; if that fails too, it is dropped:
 * auditing must never turn a committed write into a 500.
 */
class AuditLogger
{
    /**
     * @param LoggerInterface $logger the `DmLab\AdminScim\Logger\Audit` channel
     * @param ClientIp $clientIp
     * @param LoggerInterface $fallbackLogger system logger, reports audit write failures
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClientIp $clientIp,
        private readonly LoggerInterface $fallbackLogger
    ) {
    }

    /**
     * Log one audit event, prefixed with the client IP.
     *
     * @param string $event e.g. `user.create`, `auth.failure`
     * @param array<string,mixed> $context
     */
    public function log(string $event, array $context = []): void
    {
        try {
            $this->logger->info($event, ['ip' => $this->clientIp->get()] + $context);
        } catch (\Throwable $e) {
            $message = 'SCIM audit log write failed: ' . $e->getMessage();
            try {
                $this->fallbackLogger->critical($message, ['event' => $event]);
            } catch (\Throwable) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                // Both sinks share var/log; nothing left to report to.
            }
        }
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminScim\Model\Auth;

use Magento\Framework\App\Request\Http;

/**
 * Client IP for throttling and audit: `REMOTE_ADDR`, unless it is a trusted proxy.
 *
 * `X-Forwarded-For` is read only behind a configured trusted proxy, right to left,
 * so a client cannot spoof its throttle bucket (Magento's `RemoteAddress` trusts the
 * left-most `X-Forwarded-For` entry by default).
 */
class ClientIp
{
    /**
     * @param Http $request
     * @param string[] $trustedProxies proxy IPs whose `X-Forwarded-For` is trusted
     */
    public function __construct(
        private readonly Http $request,
        private readonly array $trustedProxies = []
    ) {
    }

    /**
     * The client IP; empty string when unknown.
     */
    public function get(): string
    {
        $address = (string)$this->request->getServer('REMOTE_ADDR', '');
        if (!in_array($address, $this->trustedProxies, true)) {
            return $address;
        }

        $hops = explode(',', (string)$this->request->getServer('HTTP_X_FORWARDED_FOR', ''));
        foreach (array_reverse(array_map('trim', $hops)) as $hop) {
            if (!in_array($hop, $this->trustedProxies, true)) {
                return filter_var($hop, FILTER_VALIDATE_IP) === false ? $address : $hop;
            }
        }

        return $address;
    }
}

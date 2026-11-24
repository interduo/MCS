<?php

namespace App\Filters;

use App\Libraries\LimitApi;
use App\Libraries\OpcjeMcs;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Throttle\ThrottlerInterface;

/**
 * Rate limit dla tokenowych endpointów API (issue #451): 429 + Retry-After,
 * gdy kubełek tokenu lub adresu IP jest wyczerpany (LimitApi, limit mcs.api.rateLimit req/min).
 *
 * Nakładany PO filtrze mcsBadgeToken (kolejność grup w Config\Filters::$filters),
 * więc liczone są tylko żądania z poprawnym tokenem.
 */
class McsApiThrottleFilter implements FilterInterface
{
    private ?ThrottlerInterface $throttler;

    public function __construct(?ThrottlerInterface $throttler = null)
    {
        $this->throttler = $throttler;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $limit = new LimitApi($this->throttler ?? service('throttler'));
        $token = McsBadgeTokenFilter::wyciagnijToken($request) ?? '';
        $ip    = $request instanceof IncomingRequest ? $request->getIPAddress() : '';

        if ($limit->pozwol($token, $ip)) {
            return null;
        }

        return service('response')
            ->setStatusCode(429)
            ->setHeader('Retry-After', (string) LimitApi::retryAfter(OpcjeMcs::int('mcs.api.rateLimit')))
            ->setJSON([
                'ok'   => false,
                'blad' => 'Za dużo żądań API (rate limit). Spróbuj później.',
                'tryb' => 'limit_api',
            ]);
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

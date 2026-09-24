<?php

declare(strict_types=1);

namespace EasyProduct;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

defined('ABSPATH') || exit;

/**
 * Verbindungsfehler von wp_remote_request() im Format, das PSR-18 erwartet.
 */
final class WpHttpNetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

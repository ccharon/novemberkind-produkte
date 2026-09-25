<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

defined('ABSPATH') || exit;

/**
 * PSR-18-Client über die HTTP-Funktionen von WordPress. Damit braucht das Anthropic-SDK kein Guzzle,
 * das in WordPress oft mit den Versionen anderer Plugins kollidiert, und es gelten Proxy- und
 * Zertifikatseinstellungen von WordPress.
 */
final class WpHttpClient implements ClientInterface
{
    public const TIMEOUT = 60;

    /**
     * Schickt eine PSR-7-Anfrage über `wp_remote_request()` und liefert die Antwort als PSR-7.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $result = wp_remote_request((string) $request->getUri(), [
            'method'      => $request->getMethod(),
            'headers'     => $headers,
            'body'        => (string) $request->getBody(),
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'httpversion' => '1.1',
        ]);
        if (is_wp_error($result)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- die Meldung landet im Log, nicht im HTML
            throw new WpHttpNetworkException($result->get_error_message(), $request);
        }

        $factory  = new Psr17Factory();
        $response = $factory->createResponse((int) wp_remote_retrieve_response_code($result));
        $received = wp_remote_retrieve_headers($result);
        foreach (is_object($received) ? $received->getAll() : (array) $received as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withBody($factory->createStream(wp_remote_retrieve_body($result)));
    }
}

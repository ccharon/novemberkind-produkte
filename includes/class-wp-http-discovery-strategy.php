<?php

declare(strict_types=1);

namespace EasyProduct;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

defined('ABSPATH') || exit;

/**
 * Meldet den Client bei php-http/discovery an, weil das SDK dort beim Erzeugen nach einem Client sucht.
 */
final class WpHttpDiscoveryStrategy implements DiscoveryStrategy
{
    /**
     * @param string $type
     * @return array<int, array{class: string, condition: string}>
     */
    public static function getCandidates($type)
    {
        return $type === ClientInterface::class ? [['class' => WpHttpClient::class, 'condition' => WpHttpClient::class]] : [];
    }
}

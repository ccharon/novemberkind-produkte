<?php

/**
 * Vorlagen je Produktart. %s steht für den Motivnamen, die Felder prüft ProductType::parse().
 * Kategorien, Versandklassen, Lieferzeiten und Rückseitenfotos werden im Shop über ihren Namen gesucht.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

return [
    'button' => [
        'label'             => 'Button',
        'new_label'         => 'Neuer Button',
        'hint'              => 'Ansteck-Button mit 8 Rückseiten zur Auswahl',
        'product_type'      => 'variable',
        'category'          => ['Physische Produkte', 'Buttons'],
        'name'              => 'Button: %s',
        'short_description' => 'Button: %s',
        'cart_description'  => 'Button: %s',
        'price'             => '4.50',
        'shipping_class'    => 'Päckchen',
        'delivery_time'     => '1-3 Werktage',
        'weight'            => '0.020',
        'dimensions'        => ['length' => '5.9', 'width' => '5.9', 'height' => '1'],
        'tags'              => [],
        'fields'            => [],
        'plastic_free_note' => false,
        'variations'        => [
            'attribute' => 'Rückseite',
            'default'   => 'Sicherheitsnadel',
            'options'   => [
                ['name' => 'Metallrückseite mit Klett', 'image' => 'Flache-Metallrueckseite-mit-Klettpunkt-gross'],
                ['name' => 'Flaschenöffner', 'image' => 'Flaschenoeffner-gross'],
                ['name' => 'Kroko-Clip', 'image' => 'Kroko-Clip-gross'],
                ['name' => 'Kühlschrankmagnet', 'image' => 'Kuehlschrankmagnet-gross'],
                [
                    'name'                => 'Kleidungsmagnet',
                    'image'               => 'Premium-Kleidungsmagnet-gross',
                    'safety_instructions' => '<p>⚠️ACHTUNG! Für Menschen mit Herzschrittmacher NICHT geeignet!⚠️</p>',
                ],
                ['name' => 'Saugnapf', 'image' => 'Saugnapf-gross'],
                ['name' => 'Sicherheitsnadel', 'image' => 'Sicherheitsnadel-gross'],
                ['name' => 'Taschenspiegel', 'image' => 'Taschenspiegel-gross'],
            ],
        ],
    ],

    'card' => [
        'label'             => 'Karte',
        'new_label'         => 'Neue Karte',
        'hint'              => 'Postkarte mit Umschlag, hoch oder quer',
        'product_type'      => 'simple',
        'category'          => ['Physische Produkte', 'Karten'],
        'name'              => 'Karte: %s',
        'short_description' => 'Karte %s',
        'cart_description'  => 'Karte %s',
        'price'             => '2.50',
        'shipping_class'    => 'Brief',
        'delivery_time'     => '1-3 Werktage',
        'weight'            => '',
        'dimensions'        => [],
        'tags'              => ['karte', 'postkarte', 'matt', 'Papier'],
        'fields'            => ['format', 'a4'],
        'price_a4'          => '5.00',
        'plastic_free_note' => true,
    ],

    'sticker' => [
        'label'             => 'Sticker',
        'new_label'         => 'Neuer Sticker',
        'hint'              => 'Vinyl-Sticker, matt oder glänzend',
        'product_type'      => 'simple',
        'category'          => ['Physische Produkte', 'Sticker'],
        'name'              => 'Sticker: %s',
        'short_description' => 'Sticker %s',
        'cart_description'  => 'Sticker %s',
        'price'             => '2.50',
        'shipping_class'    => 'Brief',
        'delivery_time'     => '1-3 Werktage',
        'weight'            => '',
        'dimensions'        => [],
        'tags'              => ['sticker', 'Aufkleber', 'Vinyl'],
        'fields'            => ['finish', 'size'],
        'plastic_free_note' => true,
    ],

    'bookmark' => [
        'label'             => 'Lesezeichen',
        'new_label'         => 'Neues Lesezeichen',
        'hint'              => 'Magnetisches Lesezeichen, 5 oder 7 cm breit',
        'product_type'      => 'simple',
        'category'          => ['Physische Produkte', 'magnetische Lesezeichen'],
        'name'              => '%s',
        'short_description' => 'Magnetisches Lesezeichen: %s',
        'cart_description'  => 'Magnetisches Lesezeichen: %s',
        'price'             => '4.90',
        'shipping_class'    => 'Brief',
        'delivery_time'     => '1-3 Werktage',
        'weight'            => '',
        'dimensions'        => ['height' => '12'],
        'tags'              => ['Lesezeichen', 'magnetisch'],
        'fields'            => ['bookmark_width'],
        'plastic_free_note' => false,
    ],

    'original' => [
        'label'             => 'Originalzeichnung',
        'new_label'         => 'Neue Originalzeichnung',
        'hint'              => 'Unikat, das es genau einmal gibt',
        'product_type'      => 'simple',
        'category'          => ['Physische Produkte', 'Originalzeichnungen'],
        'name'              => 'Original: %s',
        'short_description' => 'Originalzeichnung „%s“, ein Unikat.',
        'cart_description'  => 'Original: %s',
        'price'             => '',
        'shipping_class'    => 'Päckchen',
        'delivery_time'     => '1-3 Werktage',
        'weight'            => '',
        'dimensions'        => [],
        'tags'              => ['Original', 'Unikat', 'handgemalt'],
        'fields'            => ['technique', 'size', 'year', 'text'],
        'plastic_free_note' => true,
        'unique'            => true,
    ],
];

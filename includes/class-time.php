<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Datum und Uhrzeit für Eingaben und Anzeigen in der Zeitzone von WordPress.
 * Gespeichert werden immer Unix-Zeitstempel.
 */
final class Time
{
    public static function zone(): \DateTimeZone
    {
        return wp_timezone();
    }

    /**
     * Zeitpunkt in der Zeitzone von WordPress, Monatsnamen in der Sprache von WordPress.
     */
    public static function format(string $format, ?int $timestamp = null): string
    {
        return (string) wp_date($format, $timestamp, self::zone());
    }

    /**
     * Zeitstempel aus Datum `Y-m-d` und Uhrzeit `H:i` in der Zeitzone von WordPress, null bei ungültigen Werten.
     * Eine Uhrzeit, die es wegen der Umstellung auf Sommerzeit nicht gibt, rückt eine Stunde vor.
     * Eine doppelte Uhrzeit bei der Umstellung auf Winterzeit gilt als die erste, also noch Sommerzeit.
     */
    public static function parse(string $date, string $time = '00:00'): ?int
    {
        if (
            !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d)
            || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])
            || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)
        ) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, self::zone());
        if ($parsed === false) {
            return null;
        }
        // PHP-Versionen wählen bei einer doppelten Uhrzeit unterschiedlich, deshalb die frühere erzwingen
        $earlier = $parsed->getTimestamp() - HOUR_IN_SECONDS;

        return self::format('Y-m-d H:i', $earlier) === $date . ' ' . $time ? $earlier : $parsed->getTimestamp();
    }

    /**
     * Beginn des Tages `Y-m-d`, auch über die Zeitumstellung hinweg richtig; null bei ungültigem Datum.
     */
    public static function day_start(string $date, int $add_days = 0): ?int
    {
        $day = self::parse($date) === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::zone());

        return $day === false ? null : $day->modify(sprintf('%+d days', $add_days))->getTimestamp();
    }
}

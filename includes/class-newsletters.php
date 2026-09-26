<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Ausgaben des Newsletters: anlegen, testen, planen und im Hintergrund in Päckchen versenden.
 *
 * @phpstan-type Issue array{id: int, subject: string, preheader: string, content: string, products: int[], status: string, scheduled: int, recipients: int, sent: int, failed: int, finished: int, modified: int}
 */
final class Newsletters
{
    public const POST_TYPE = 'novemberkind_ausgabe';
    public const META = '_novemberkind_produkte_newsletter';
    public const META_QUEUE = '_novemberkind_produkte_queue';
    public const CAPABILITY = 'manage_woocommerce';
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent'];
    public const SEND_MODES = ['draft', 'now', 'scheduled'];
    public const SUBJECT_MAX_LENGTH = 150;
    public const PREHEADER_MAX_LENGTH = 150;
    // Hoster begrenzen die Zahl der Mails pro Stunde, deshalb in kleinen Päckchen
    public const BATCH_SIZE = 25;
    public const BATCH_INTERVAL = 60;
    // Der Action Scheduler übergibt ['id' => …] als benanntes Argument, der Parameter muss $id heißen
    public const HOOK_START = 'novemberkind_produkte_newsletter_start';
    public const HOOK_BATCH = 'novemberkind_produkte_newsletter_batch';
    private const GROUP = 'novemberkind-produkte';
    private const LOCK_PREFIX = 'nkp_newsletter_';

    /**
     * Meldet den Inhaltstyp und die Aufgaben für den Action Scheduler an.
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action(self::HOOK_START, [$this, 'start']);
        add_action(self::HOOK_BATCH, [$this, 'send_batch']);
    }

    /**
     * Privater Inhaltstyp ohne Backend-Oberfläche, REST und Export.
     */
    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'               => __('Newsletter', 'novemberkind-produkte'),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => false,
            'supports'            => ['title'],
            'capability_type'     => 'product',
            'map_meta_cap'        => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> alle Ausgaben, zuletzt geänderte zuerst
     * @phpstan-return array<int, Issue>
     */
    public static function all(): array
    {
        $posts = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'private',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);

        return array_values(array_filter(array_map([self::class, 'from_post'], $posts)));
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Issue|null
     */
    public static function get(int $id): ?array
    {
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof \WP_Post && $post->post_type === self::POST_TYPE && $post->post_status === 'private' ? self::from_post($post) : null;
    }

    /**
     * Ob eine Ausgabe noch geändert werden darf. Laufende und versendete bleiben als Nachweis unverändert.
     *
     * @param array<string, mixed> $issue
     */
    public static function is_locked(array $issue): bool
    {
        return in_array($issue['status'], ['sending', 'sent'], true);
    }

    /**
     * Prüft die Eingaben aus dem Formular.
     *
     * @param array<string, mixed> $data
     * @return array{subject: string, preheader: string, content: string, products: int[]}|\WP_Error
     */
    public function parse(array $data): array|\WP_Error
    {
        $errors  = [];
        // sanitize_text_field macht aus einem einzelnen < ein &lt;, das im Posteingang sichtbar wäre
        $subject = trim(wp_specialchars_decode(sanitize_text_field(wp_unslash(Plugin::input($data, 'subject'))), ENT_QUOTES));
        if ($subject === '') {
            $errors['subject'] = __('Bitte gib einen Betreff ein.', 'novemberkind-produkte');
        } elseif (mb_strlen($subject) > self::SUBJECT_MAX_LENGTH) {
            /* translators: %d: größte Anzahl Zeichen */
            $errors['subject'] = sprintf(__('Der Betreff darf höchstens %d Zeichen lang sein.', 'novemberkind-produkte'), self::SUBJECT_MAX_LENGTH);
        }

        $preheader = trim(wp_specialchars_decode(sanitize_text_field(wp_unslash(Plugin::input($data, 'preheader'))), ENT_QUOTES));
        if (mb_strlen($preheader) > self::PREHEADER_MAX_LENGTH) {
            /* translators: %d: größte Anzahl Zeichen */
            $errors['preheader'] = sprintf(__('Die Vorschauzeile darf höchstens %d Zeichen lang sein.', 'novemberkind-produkte'), self::PREHEADER_MAX_LENGTH);
        }

        // Offene Elemente würden in der Mail den Fuß mit dem Abmeldelink umschließen
        $content  = trim(force_balance_tags(wp_kses_post(wp_unslash(Plugin::input($data, 'content')))));
        $products = array_values(array_filter(
            array_unique(array_map('absint', (array) ($data['products'] ?? []))),
            static fn(int $product_id): bool => get_post_type($product_id) === 'product'
        ));
        if (trim(wp_strip_all_tags($content)) === '' && $products === []) {
            $errors['content'] = __('Bitte schreib einen Text oder wähle Produkte aus.', 'novemberkind-produkte');
        }

        if ($errors !== []) {
            return new \WP_Error('invalid', __('Bitte prüfe die markierten Felder.', 'novemberkind-produkte'), $errors);
        }

        return ['subject' => $subject, 'preheader' => $preheader, 'content' => $content, 'products' => $products];
    }

    /**
     * Speichert eine Ausgabe als Entwurf, plant sie oder startet den Versand.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Issue|\WP_Error
     */
    public function save(array $data, int $id = 0): array|\WP_Error
    {
        // Gesperrt, damit zwei gleichzeitige Anfragen den Versand nicht zweimal starten
        if ($id && !self::lock($id)) {
            return new \WP_Error('busy', __('Der Newsletter wird gerade gespeichert oder verschickt. Bitte lade die Seite neu.', 'novemberkind-produkte'));
        }
        try {
            return $this->save_locked($data, $id);
        } finally {
            if ($id) {
                self::unlock($id);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|\WP_Error
     * @phpstan-return Issue|\WP_Error
     */
    private function save_locked(array $data, int $id): array|\WP_Error
    {
        $existing = $id ? self::get($id) : null;
        if ($id && $existing === null) {
            return new \WP_Error('not_found', __('Diesen Newsletter gibt es nicht mehr.', 'novemberkind-produkte'));
        }
        if ($existing !== null && self::is_locked($existing)) {
            return new \WP_Error('locked', __('Dieser Newsletter ist schon verschickt und lässt sich nicht mehr ändern.', 'novemberkind-produkte'));
        }

        $values = $this->parse($data);
        $errors = is_wp_error($values) ? (array) $values->get_error_data() : [];

        $mode = Plugin::input($data, 'send', 'draft');
        if (!in_array($mode, self::SEND_MODES, true)) {
            $mode = 'draft';
        }
        $scheduled = 0;
        if ($mode === 'scheduled') {
            $scheduled = (int) ProductService::parse_local_datetime(Plugin::input($data, 'send_date'), Plugin::input($data, 'send_time'));
            if ($scheduled === 0) {
                $errors['send'] = __('Bitte wähle, wann der Newsletter verschickt wird.', 'novemberkind-produkte');
            } elseif ($scheduled <= time()) {
                $errors['send'] = __('Der Zeitpunkt liegt in der Vergangenheit. Wähle einen späteren oder verschicke den Newsletter gleich.', 'novemberkind-produkte');
            }
        }
        if ($mode === 'now' && Subscribers::counts()['confirmed'] === 0) {
            $errors['send'] = __('Es gibt noch niemanden, der die Anmeldung bestätigt hat.', 'novemberkind-produkte');
        }

        if ($errors !== [] || is_wp_error($values)) {
            return new \WP_Error('invalid', __('Bitte prüfe die markierten Felder.', 'novemberkind-produkte'), $errors);
        }

        $post_id = wp_insert_post([
            'ID'          => $id,
            'post_type'   => self::POST_TYPE,
            'post_status' => 'private',
            'post_title'  => wp_slash($values['subject']),
        ], true);
        if (is_wp_error($post_id)) {
            return new \WP_Error('save', __('Der Newsletter konnte nicht gespeichert werden.', 'novemberkind-produkte'));
        }

        $this->unschedule($post_id);
        $this->store($post_id, $values + [
            'status'    => $mode === 'draft' ? 'draft' : 'scheduled',
            'scheduled' => $mode === 'scheduled' ? $scheduled : 0,
        ]);
        if ($mode === 'now') {
            $id ? $this->start_locked($post_id) : $this->start($post_id);
        } elseif ($mode === 'scheduled') {
            as_schedule_single_action($scheduled, self::HOOK_START, ['id' => $post_id], self::GROUP);
        }

        return self::get($post_id) ?? new \WP_Error('save', __('Der Newsletter konnte nicht gespeichert werden.', 'novemberkind-produkte'));
    }

    /**
     * Schickt den aktuellen Stand des Formulars an eine Adresse, ohne zu speichern.
     *
     * @param array<string, mixed> $data
     * @return true|\WP_Error
     */
    public function send_test(array $data, string $email): bool|\WP_Error
    {
        $values = $this->parse($data);
        if (is_wp_error($values)) {
            return $values;
        }

        $mail = NewsletterMail::render($values);
        /* translators: %s: Betreff */
        $subject = sprintf(__('[Test] %s', 'novemberkind-produkte'), $values['subject']);
        if (!NewsletterMail::send($email, $subject, NewsletterMail::personalize($mail['html'], 'test'), NewsletterMail::personalize($mail['text'], 'test'))) {
            return new \WP_Error('mail', __('Die Testmail konnte nicht verschickt werden. Bitte prüfe die E-Mail-Einstellungen des Shops.', 'novemberkind-produkte'));
        }

        return true;
    }

    /**
     * Legt die Empfänger fest und startet den Versand. Läuft beim Senden sofort oder geplant über den Action Scheduler.
     */
    public function start(int $id): void
    {
        if (!self::lock($id)) {
            return;
        }
        try {
            $this->start_locked($id);
        } finally {
            self::unlock($id);
        }
    }

    private function start_locked(int $id): void
    {
        $issue = self::get($id);
        if ($issue === null || $issue['status'] !== 'scheduled') {
            return;
        }

        // Je Adresse nur ein Empfänger, falls eine Adresse doppelt angelegt wurde
        $queue = array_values(array_column(array_column(Subscribers::confirmed(), null, 'email'), 'id'));
        update_post_meta($id, self::META_QUEUE, $queue);
        $this->store($id, ['status' => 'sending', 'scheduled' => $issue['scheduled'] ?: time(), 'recipients' => count($queue), 'sent' => 0, 'failed' => 0] + $issue);
        as_enqueue_async_action(self::HOOK_BATCH, ['id' => $id], self::GROUP);
    }

    /**
     * Verschickt das nächste Päckchen. Die Empfänger werden vor dem Senden aus der Warteschlange genommen,
     * damit nach einem Abbruch niemand die Mail doppelt bekommt.
     */
    public function send_batch(int $id): void
    {
        // Läuft schon ein Päckchen, etwa bei langsamem SMTP, kommt dieses später dran
        if (!self::lock($id)) {
            as_schedule_single_action(time() + self::BATCH_INTERVAL, self::HOOK_BATCH, ['id' => $id], self::GROUP);
            return;
        }
        try {
            $this->send_locked_batch($id);
        } finally {
            self::unlock($id);
        }
    }

    private function send_locked_batch(int $id): void
    {
        $issue = self::get($id);
        if ($issue === null || $issue['status'] !== 'sending') {
            return;
        }

        $queue = array_map('intval', (array) get_post_meta($id, self::META_QUEUE, true));
        $batch = array_splice($queue, 0, self::BATCH_SIZE);
        update_post_meta($id, self::META_QUEUE, $queue);
        if ($queue !== []) {
            as_schedule_single_action(time() + self::BATCH_INTERVAL, self::HOOK_BATCH, ['id' => $id], self::GROUP);
        }

        $mail   = NewsletterMail::render($issue);
        $sent   = 0;
        $failed = 0;
        foreach ($batch as $subscriber_id) {
            // Wer sich inzwischen abgemeldet hat, bekommt nichts mehr
            $subscriber = Subscribers::get($subscriber_id);
            if ($subscriber === null || $subscriber['status'] !== 'confirmed') {
                continue;
            }
            $ok = NewsletterMail::send(
                $subscriber['email'],
                $issue['subject'],
                NewsletterMail::personalize($mail['html'], $subscriber['token']),
                NewsletterMail::personalize($mail['text'], $subscriber['token']),
                NewsletterMail::unsubscribe_headers($subscriber['token'])
            );
            $ok ? $sent++ : $failed++;
        }

        $issue = self::get($id) ?? $issue;
        $issue['sent']   += $sent;
        $issue['failed'] += $failed;
        if ($queue === []) {
            $issue['status']   = 'sent';
            $issue['finished'] = time();
            delete_post_meta($id, self::META_QUEUE);
        }
        $this->store($id, $issue);
    }

    /**
     * Setzt einen Versand fort, dessen nächste Aufgabe fehlt, etwa nach einem Abbruch oder
     * wenn das Plugin zum geplanten Zeitpunkt deaktiviert war.
     */
    public function resume_stalled(): void
    {
        foreach (self::all() as $issue) {
            $args = ['id' => $issue['id']];
            if ($issue['status'] === 'sending' && !as_has_scheduled_action(self::HOOK_BATCH, $args, self::GROUP)) {
                as_enqueue_async_action(self::HOOK_BATCH, $args, self::GROUP);
            } elseif ($issue['status'] === 'scheduled' && $issue['scheduled'] <= time() && !as_has_scheduled_action(self::HOOK_START, $args, self::GROUP)) {
                as_enqueue_async_action(self::HOOK_START, $args, self::GROUP);
            }
        }
    }

    /**
     * Wie viele Empfänger noch in der Warteschlange stehen.
     */
    public static function remaining(int $id): int
    {
        $queue = get_post_meta($id, self::META_QUEUE, true);

        return is_array($queue) ? count($queue) : 0;
    }

    /**
     * Sperre je Ausgabe über GET_LOCK der Datenbank: wirkt über alle PHP-Prozesse und endet auch bei einem Absturz.
     * Nur eine belegte Sperre (0) blockiert, eine Datenbank ohne GET_LOCK (NULL) nicht.
     */
    private static function lock(int $id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Sperre, keine Daten
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name($id))) !== '0';
        if ($locked) {
            // Werte, die diese Anfrage vorher gelesen hat, können inzwischen veraltet sein
            wp_cache_delete($id, 'post_meta');
        }

        return $locked;
    }

    private static function unlock(int $id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Sperre, keine Daten
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($id)));
    }

    /**
     * Der Name gilt für den ganzen Datenbankserver, deshalb mit Datenbank und Tabellenpräfix.
     */
    private static function lock_name(int $id): string
    {
        global $wpdb;

        return self::LOCK_PREFIX . substr(md5($wpdb->dbname . $wpdb->prefix), 0, 12) . '_' . $id;
    }

    private function unschedule(int $id): void
    {
        as_unschedule_all_actions(self::HOOK_START, ['id' => $id], self::GROUP);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function store(int $id, array $values): void
    {
        // update_post_meta entfernt Backslashes, deshalb vorher wp_slash
        update_post_meta($id, self::META, wp_slash([
            'subject'    => (string) ($values['subject'] ?? ''),
            'preheader'  => (string) ($values['preheader'] ?? ''),
            'content'    => (string) ($values['content'] ?? ''),
            'products'   => array_map('intval', (array) ($values['products'] ?? [])),
            'status'     => in_array($values['status'] ?? '', self::STATUSES, true) ? (string) $values['status'] : 'draft',
            'scheduled'  => (int) ($values['scheduled'] ?? 0),
            'recipients' => (int) ($values['recipients'] ?? 0),
            'sent'       => (int) ($values['sent'] ?? 0),
            'failed'     => (int) ($values['failed'] ?? 0),
            'finished'   => (int) ($values['finished'] ?? 0),
        ]));
    }

    /**
     * @return array<string, mixed>|null
     * @phpstan-return Issue|null
     */
    private static function from_post(\WP_Post $post): ?array
    {
        $meta = get_post_meta($post->ID, self::META, true);
        if (!is_array($meta)) {
            return null;
        }

        return [
            'id'         => $post->ID,
            // Der Betreff steht in den Metadaten, weil WordPress im Titel & zu &amp; macht
            'subject'    => (string) ($meta['subject'] ?? $post->post_title),
            'preheader'  => (string) ($meta['preheader'] ?? ''),
            'content'    => (string) ($meta['content'] ?? ''),
            'products'   => array_map('intval', (array) ($meta['products'] ?? [])),
            'status'     => in_array($meta['status'] ?? '', self::STATUSES, true) ? (string) $meta['status'] : 'draft',
            'scheduled'  => (int) ($meta['scheduled'] ?? 0),
            'recipients' => (int) ($meta['recipients'] ?? 0),
            'sent'       => (int) ($meta['sent'] ?? 0),
            'failed'     => (int) ($meta['failed'] ?? 0),
            'finished'   => (int) ($meta['finished'] ?? 0),
            'modified'   => (int) get_post_modified_time('U', true, $post),
        ];
    }
}

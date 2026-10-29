<?php

namespace App\Notifications;

use App\Models\UserNotification;

/**
 * Single source of truth for how a notification type becomes an outbound message.
 *
 * Three questions are answered here, once, for every type:
 *
 *  1. Which opt-out category does it belong to? A user mutes a category, not a type, so adding a
 *     type never needs a new preference row.
 *  2. Is it mandatory? Account-security events cannot be muted: a user who could silence
 *     "password changed" could also silence the warning that their account is being taken over.
 *  3. What may the email say? Emails are projected from the notification payload through an
 *     explicit allowlist, never by dumping the payload. An email leaves the platform's
 *     trust boundary, so it carries the least data that still makes the message useful.
 */
class NotificationCatalog
{
    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_FULFILLMENT = 'fulfillment';

    public const CATEGORY_REVIEWS = 'reviews';

    public const CATEGORY_MESSAGES = 'messages';

    public const CATEGORY_DISCOVERY = 'discovery';

    public const CATEGORY_SECURITY = 'security';

    public const CHANNEL_EMAIL = 'email';

    /**
     * Categories a user may switch off per channel.
     *
     * @return array<int, string>
     */
    public static function mailableCategories(): array
    {
        return [
            self::CATEGORY_ORDERS,
            self::CATEGORY_FULFILLMENT,
            self::CATEGORY_REVIEWS,
            self::CATEGORY_MESSAGES,
            self::CATEGORY_DISCOVERY,
        ];
    }

    /**
     * Categories that are always delivered on every channel.
     *
     * @return array<int, string>
     */
    public static function mandatoryCategories(): array
    {
        return [self::CATEGORY_SECURITY];
    }

    public static function isMandatoryCategory(string $category): bool
    {
        return in_array($category, self::mandatoryCategories(), true);
    }

    /**
     * Notification type => opt-out category.
     *
     * Types absent from this map are in-app only: they have no outbound projection defined, so the
     * channel simply skips them rather than guessing a subject line.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            UserNotification::TYPE_ORDER_PLACED => self::CATEGORY_ORDERS,
            UserNotification::TYPE_FULFILLMENT_ACCEPTED => self::CATEGORY_FULFILLMENT,
            UserNotification::TYPE_FULFILLMENT_READY => self::CATEGORY_FULFILLMENT,
            UserNotification::TYPE_FULFILLMENT_COMPLETED => self::CATEGORY_FULFILLMENT,
            UserNotification::TYPE_FULFILLMENT_CANCELLED => self::CATEGORY_FULFILLMENT,
            UserNotification::TYPE_RENTAL_CANCELLED => self::CATEGORY_FULFILLMENT,
            UserNotification::TYPE_REVIEW_RECEIVED => self::CATEGORY_REVIEWS,
            UserNotification::TYPE_REVIEW_REPLIED => self::CATEGORY_REVIEWS,
            UserNotification::TYPE_MESSAGE_RECEIVED => self::CATEGORY_MESSAGES,

            UserNotification::TYPE_NEW_MATCH => self::CATEGORY_DISCOVERY,
            UserNotification::TYPE_AVAILABILITY_ALERT => self::CATEGORY_DISCOVERY,

            // Security events are recorded through SecurityEventRecorder, not the in-app feed, but
            // they share this catalog so preferences and subjects stay in one place.
            self::TYPE_SECURITY_PASSWORD_CHANGED => self::CATEGORY_SECURITY,
            self::TYPE_SECURITY_EMAIL_CHANGED => self::CATEGORY_SECURITY,
            self::TYPE_SECURITY_SESSIONS_REVOKED => self::CATEGORY_SECURITY,
        ];
    }

    // These strings are exactly what SecurityEventRecorder already records, so a security event
    // can be routed to the outbox without translating between two vocabularies.
    public const TYPE_SECURITY_PASSWORD_CHANGED = 'password.changed';

    public const TYPE_SECURITY_EMAIL_CHANGED = 'email.changed';

    public const TYPE_SECURITY_SESSIONS_REVOKED = 'sessions.revoked_all';

    public static function categoryFor(string $type): ?string
    {
        return self::categories()[$type] ?? null;
    }

    /**
     * Whether the type has an outbound projection at all.
     */
    public static function isDeliverable(string $type): bool
    {
        return self::categoryFor($type) !== null;
    }

    public static function subject(string $type): string
    {
        return match ($type) {
            UserNotification::TYPE_ORDER_PLACED => 'Pesanan baru masuk di CosplayNesia',
            UserNotification::TYPE_FULFILLMENT_ACCEPTED => 'Pesananmu telah diterima penjual',
            UserNotification::TYPE_FULFILLMENT_READY => 'Pesananmu siap diserahkan',
            UserNotification::TYPE_FULFILLMENT_COMPLETED => 'Pesananmu telah selesai',
            UserNotification::TYPE_FULFILLMENT_CANCELLED => 'Sebagian pesananmu dibatalkan',
            UserNotification::TYPE_RENTAL_CANCELLED => 'Sewa kostum dibatalkan',
            UserNotification::TYPE_REVIEW_RECEIVED => 'Listing kamu menerima ulasan baru',
            UserNotification::TYPE_REVIEW_REPLIED => 'Penjual membalas ulasanmu',
            UserNotification::TYPE_MESSAGE_RECEIVED => 'Pesan baru pada pesananmu',
            UserNotification::TYPE_NEW_MATCH => 'Ada kostum baru yang cocok dengan pencarianmu',
            UserNotification::TYPE_AVAILABILITY_ALERT => 'Kostum yang kamu pantau sudah tersedia',
            self::TYPE_SECURITY_PASSWORD_CHANGED => 'Kata sandi CosplayNesia diubah',
            self::TYPE_SECURITY_EMAIL_CHANGED => 'Email akun CosplayNesia diubah',
            self::TYPE_SECURITY_SESSIONS_REVOKED => 'Semua perangkat CosplayNesia dikeluarkan',
            default => 'Pemberitahuan CosplayNesia',
        };
    }

    /**
     * Human-readable lines for the email body, projected from the payload allowlist.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function lines(array $payload): array
    {
        $lines = [];

        if (isset($payload['product_name']) && is_string($payload['product_name'])) {
            $lines[] = 'Produk: '.$payload['product_name'];
        }
        if (isset($payload['variant_label']) && is_string($payload['variant_label'])) {
            $lines[] = 'Variasi: '.$payload['variant_label'];
        }
        if (isset($payload['seller_name']) && is_string($payload['seller_name'])) {
            $lines[] = 'Penjual: '.$payload['seller_name'];
        }
        if (isset($payload['quantity'])) {
            $lines[] = 'Jumlah: '.(int) $payload['quantity'].' unit';
        }
        if (isset($payload['item_count'])) {
            $lines[] = 'Jumlah baris item: '.(int) $payload['item_count'];
        }
        if (isset($payload['subtotal'])) {
            $lines[] = 'Subtotal: Rp'.number_format((int) $payload['subtotal'], 0, ',', '.');
        }
        if (isset($payload['rating'])) {
            $lines[] = 'Rating: '.(int) $payload['rating'].'/5';
        }
        if (isset($payload['rental_start_date'], $payload['rental_end_date'])
            && is_string($payload['rental_start_date']) && is_string($payload['rental_end_date'])) {
            $lines[] = 'Jadwal sewa: '.$payload['rental_start_date'].' sampai '.$payload['rental_end_date'];
        }
        if (isset($payload['preview']) && is_string($payload['preview'])) {
            $lines[] = 'Cuplikan pesan: '.$payload['preview'];
        }
        if (isset($payload['search_name']) && is_string($payload['search_name'])) {
            $lines[] = 'Pencarian: '.$payload['search_name'];
        }
        if (isset($payload['price'])) {
            $lines[] = 'Harga: Rp'.number_format((int) $payload['price'], 0, ',', '.');
        }
        if (isset($payload['from_domain']) && is_string($payload['from_domain'])) {
            $lines[] = 'Domain email sebelumnya: '.$payload['from_domain'];
        }

        return $lines;
    }

    /**
     * The in-app destination for a notification, so an email can link somewhere useful.
     */
    public static function actionPath(string $type): ?string
    {
        return match ($type) {
            self::TYPE_SECURITY_PASSWORD_CHANGED,
            self::TYPE_SECURITY_EMAIL_CHANGED,
            self::TYPE_SECURITY_SESSIONS_REVOKED => '/?panel=profile',
            default => '/?panel=notifications',
        };
    }
}

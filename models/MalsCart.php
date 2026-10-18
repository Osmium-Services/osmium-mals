<?php

declare(strict_types=1);

namespace Osmium\Services\Mals\Models;

/**
 * Mals hosted-cart configuration and the visitor's cart summary.
 *
 * Config lives in app/config/services/mals.json.php. The summary (item count, total) comes from the
 * query string Mals appends when it sends the visitor back (qty, tot, or r to empty the cart). Core
 * stashes that query string in the session and redirects to the clean URL, so it is read from there once,
 * kept in the session for the visitor's next pages, and falls back to the liveqty / livetot cookies.
 */
class MalsCart
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/mals.json.php';

    public static function get(): object
    {
        if (self::$config !== null) return self::$config;

        $content = \file_exists(self::$configPath) ? (string) \file_get_contents(self::$configPath) : null;

        return self::$config = self::fromContent($content);
    }

    /**
     * payment.hosted - tell Settings > Payments that payment is taken on Mals' own page, so no provider is chosen there.
     *
     * @return array{label: string, settingsRoute: string}
     */
    public static function hostedPayment(array $payload): array
    {
        return ['label' => 'Mals', 'settingsRoute' => 'settings/mals/'];
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /**
     * The settings from the saved file's text (a PHP guard line, then JSON); defaults for anything missing or unreadable.
     */
    public static function fromContent(?string $content): object
    {
        $defaults = ['enabled' => false, 'cartUserID' => '', 'addToCartURL' => '', 'checkoutURL' => '', 'hideZeroItems' => false];

        $jsonStart = $content === null ? false : \strpos($content, '{');
        if ($content === null || $jsonStart === false) return (object) $defaults;

        $decoded = \json_decode(\substr($content, $jsonStart));
        $saved = \is_object($decoded) && \is_object($decoded->mals ?? null) ? (array) $decoded->mals : [];

        return (object) \array_merge($defaults, $saved);
    }

    public static function isReady(?object $config = null): bool
    {
        $config ??= self::get();

        return (bool) $config->enabled && $config->cartUserID !== '' && $config->addToCartURL !== '' && $config->checkoutURL !== '';
    }

    /**
     * The cart as a theme uses it, or null when Mals is off or not set up. Reads and updates $_SESSION.
     *
     * @return array{cartTotalItems: int, cartTotalExcTax: float, cartID: string, checkoutLink: string, addItemLink: string, hideZeroItems: bool}|null
     */
    public static function data(): ?array
    {
        return self::build(self::get(), $_SESSION, $_COOKIE);
    }

    /**
     * @param array<string, mixed> $session the stashed query string and the kept summary; updated in place
     * @param array<string, mixed> $cookies
     * @return array{cartTotalItems: int, cartTotalExcTax: float, cartID: string, checkoutLink: string, addItemLink: string, hideZeroItems: bool}|null
     */
    public static function build(object $config, array &$session, array $cookies): ?array
    {
        if (!self::isReady($config)) return null;

        $summary = self::summary(self::consumeStash($session), $session['mals_cart'] ?? null, $cookies);
        $session['mals_cart'] = $summary;

        return [
            'cartTotalItems' => $summary['items'],
            'cartTotalExcTax' => $summary['total'],
            'cartID' => (string) $config->cartUserID,
            'checkoutLink' => \str_replace('{CARTUSERID}', \rawurlencode((string) $config->cartUserID), (string) $config->checkoutURL),
            'addItemLink' => (string) $config->addToCartURL,
            'hideZeroItems' => (bool) $config->hideZeroItems,
        ];
    }

    /**
     * Reads the cart parameters (qty, tot, r) Mals sent back from the stashed query string, and removes the
     * stash when it held any, so they apply once.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private static function consumeStash(array &$session): array
    {
        $stashed = (string) ($session['qs'] ?? '');
        if ($stashed === '') return [];

        \parse_str($stashed, $params);
        $cart = \array_intersect_key($params, \array_flip(['qty', 'tot', 'r']));
        if ($cart !== []) unset($session['qs']);

        return $cart;
    }

    /**
     * @param array<string, mixed> $query qty / tot / r from Mals' return
     * @param array{items: int, total: float}|null $kept the summary kept in the session from an earlier page
     * @param array<string, mixed> $cookies
     * @return array{items: int, total: float}
     */
    public static function summary(array $query, ?array $kept, array $cookies): array
    {
        if (isset($query['r'])) return ['items' => 0, 'total' => 0.0];

        $items = $query['qty'] ?? $kept['items'] ?? $cookies['liveqty'] ?? 0;
        $total = $query['tot'] ?? $kept['total'] ?? $cookies['livetot'] ?? 0;

        return [
            'items' => \max(0, (int) $items),
            'total' => \max(0.0, (float) $total),
        ];
    }
}

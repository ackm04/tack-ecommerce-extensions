<?php
/**
 * TackQuote for OpenCart — shared helpers for the inbound catalog/order feed
 * (`index.php?route=extension/tack/api/*`).
 *
 * This is the INBOUND direction: TackQuote calls this store. It is the mirror
 * image of ApiClient (system/library/api_client.php), which is this store
 * calling TackQuote. The two use different secrets on purpose — see README.md.
 *
 * Autoloading: `catalog/controller/startup/extension.php` registers
 * `Opencart\System\Library\Extension\<Code>` => `extension/<code>/system/library/`,
 * and system/engine/autoloader.php maps `ApiGuard` => `api_guard.php`
 * (`strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', …))`).
 *   https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/controller/startup/extension.php
 *   https://github.com/opencart/opencart/blob/4.0.2.3/upload/system/engine/autoloader.php
 *
 * UNVERIFIED — the auth scheme is TackQuote's own. OpenCart's documentation
 * publishes no way to authenticate a CUSTOM extension route: its only API page,
 * <https://docs.opencart.com/admin-interface/system/users/api>, describes the
 * admin-managed API user for core's session cart/checkout API and exposes
 * nothing reusable from an extension controller. Bearer + hash_equals() was
 * chosen to match what the TackQuote connector already sends, not because a
 * vendor convention exists to follow. Do not "correct" it towards core's
 * `api_token` query parameter — that belongs to a session the connector has
 * not and cannot open.
 *
 * The JSON emission below (Content-Type header + json_encode into
 * Response::setOutput) IS the vendor's own idiom, shown in the worked example
 * at <https://docs.opencart.com/developer-guide/extensions>.
 */

namespace Opencart\System\Library\Extension\Tack;

class ApiGuard
{
    /** Orders per page when the caller does not ask for a specific size. */
    public const DEFAULT_ORDER_LIMIT = 50;

    /** Hard ceiling on `limit`, so one request cannot ask for the whole table. */
    public const MAX_LIMIT = 250;

    /**
     * Read the presented Bearer token.
     *
     * `Authorization` does not always survive to PHP: mod_php exposes it as
     * HTTP_AUTHORIZATION, but under CGI/FastCGI it is commonly dropped unless
     * the vhost forwards it, in which case it usually arrives as
     * REDIRECT_HTTP_AUTHORIZATION. getallheaders() covers the remaining cases.
     * All three are checked rather than assuming one — a missing header here
     * reads to the merchant as "wrong token", which is the hardest kind of
     * failure to diagnose.
     *
     * Note on encoding: OpenCart's Request constructor runs htmlspecialchars()
     * over $_SERVER (system/library/request.php), so `&`, `<`, `>` and `"` in a
     * header arrive escaped. The raw $_SERVER superglobal is preferred here and
     * the value is entity-decoded before use, so a token is compared as the
     * merchant typed it either way. (Tokens should still be URL-safe —
     * [A-Za-z0-9_-] — which sidesteps the question entirely.)
     *
     * @param array<string, mixed> $server $this->request->server, used as a fallback.
     */
    public static function presentedToken(array $server): string
    {
        $header = '';

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                $header = (string) $_SERVER[$key];
                break;
            }

            if (!empty($server[$key])) {
                $header = html_entity_decode((string) $server[$key], ENT_COMPAT, 'UTF-8');
                break;
            }
        }

        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($header, 7));
    }

    /**
     * Authorise a request against the token stored in
     * `module_tackquotes_connector_token`.
     *
     * Fails CLOSED: with no token configured the feed does not exist at all
     * (503), so a fresh install never serves catalog or order data to an
     * unauthenticated caller. Comparison is hash_equals(), not `===`, because
     * this is a bearer secret compared on every request.
     *
     * @param array<string, mixed> $server
     *
     * @return array{ok: bool, status?: int, reason?: string, code?: string, error?: string}
     */
    public static function check(array $server, string $expectedToken): array
    {
        if ($expectedToken === '') {
            return [
                'ok'     => false,
                'status' => 503,
                'reason' => 'Service Unavailable',
                'code'   => 'feed_disabled',
                'error'  => 'The TackQuote catalog/order feed is switched off. Set a "Catalog / order feed token" in OpenCart admin under Extensions > Modules > TackQuote.',
            ];
        }

        // The stored token came in through $this->request->post, which is
        // htmlspecialchars()-cleaned, so decode it back before comparing.
        $expectedToken = html_entity_decode($expectedToken, ENT_COMPAT, 'UTF-8');

        $presented = self::presentedToken($server);

        if ($presented === '') {
            return [
                'ok'     => false,
                'status' => 401,
                'reason' => 'Unauthorized',
                'code'   => 'missing_token',
                'error'  => 'Missing Authorization: Bearer <token> header.',
            ];
        }

        if (!hash_equals($expectedToken, $presented)) {
            return [
                'ok'     => false,
                'status' => 401,
                'reason' => 'Unauthorized',
                'code'   => 'invalid_token',
                'error'  => 'The presented token does not match this store\'s TackQuote feed token.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * Normalise `page` / `limit` query parameters.
     *
     * `limit` absent means "no limit" — the caller gets everything. That is
     * deliberate for the catalog feed: TackQuote's `syncProducts` issues one
     * unpaginated `product.list` call, and defaulting to a page size here would
     * silently truncate the catalog rather than fail loudly. Order pulls DO
     * page, and pass both parameters.
     *
     * @param array<string, mixed> $get $this->request->get.
     *
     * @return array{page: int, limit: int, start: int}
     */
    public static function paging(array $get, int $defaultLimit = 0): array
    {
        $page = isset($get['page']) ? (int) $get['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $limit = isset($get['limit']) ? (int) $get['limit'] : $defaultLimit;
        if ($limit < 0) {
            $limit = $defaultLimit;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        return [
            'page'  => $page,
            'limit' => $limit,
            'start' => ($page - 1) * $limit,
        ];
    }

    /**
     * Emit a JSON response with a real HTTP status line.
     *
     * Setting the status through Response::addHeader() with the request's own
     * SERVER_PROTOCOL is OpenCart's own convention — see
     * catalog/controller/error/not_found.php in 4.0.2.3.
     *
     * @param object               $response $this->response
     * @param array<string, mixed> $server   $this->request->server
     * @param array<string, mixed> $payload
     */
    public static function json(object $response, array $server, int $status, string $reason, array $payload): void
    {
        $protocol = isset($server['SERVER_PROTOCOL']) ? (string) $server['SERVER_PROTOCOL'] : 'HTTP/1.1';

        $response->addHeader($protocol . ' ' . $status . ' ' . $reason);
        $response->addHeader('Content-Type: application/json');
        // A catalog/order feed must never be cached by an intermediary — it is
        // authenticated and per-store.
        $response->addHeader('Cache-Control: no-store');
        $response->setOutput((string) json_encode($payload));
    }
}

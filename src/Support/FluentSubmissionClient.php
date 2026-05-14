<?php

declare(strict_types=1);

namespace FluentFormsExtendedApi\Support;

use WP_Http_Cookie;

/**
 * Submits a form to Fluent Forms via its publicly-exposed AJAX action.
 *
 * Fluent Forms registers `wp_ajax_fluentform_submit` and `wp_ajax_nopriv_fluentform_submit`
 * as its submission entry point. That action name appears in the HTML/JS of every form
 * Fluent Forms renders, so it is part of their publicly observable contract — we use it
 * as a black-box HTTP service via {@see wp_remote_post()} so this plugin never references
 * any Fluent Forms class, model, service, validator, or framework component.
 *
 * Why HTTP (not direct PHP):
 *   - Fluent Forms' public PHP API (`fluentFormApi()`) only exposes read methods on the
 *     `forms` and `submissions` modules. There is no documented programmatic submission
 *     helper. Going through admin-ajax is the only path that uses purely public surface.
 *
 * Trade-offs / limitations:
 *   - One loopback HTTP round-trip per submission. On hosts that block loopback the call
 *     will return a WP_Error and the service maps that to 503.
 *   - Fluent's `fluentform/nonce_verify` filter, when set to true on a site, will reject
 *     server-generated nonces across the loopback boundary. Default is false, so this is
 *     only an issue for sites that opted into stricter nonce checks; those sites should
 *     short-circuit verification for trusted internal requests.
 */
final class FluentSubmissionClient
{
    /**
     * Maximum seconds we'll wait for the loopback to complete before giving up.
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * Submit a form via the public AJAX endpoint and return a normalised result.
     *
     * @param array<string, mixed> $payload Associative field name => value map (the same shape
     *                                      Fluent's own JS frontend builds before posting).
     *
     * @return array{
     *     transport_ok: bool,
     *     status_code:  int,
     *     decoded:      array<string, mixed>,
     *     error:        ?string
     * } `transport_ok` reports whether the HTTP call itself succeeded (independent of the
     *   business outcome). `decoded` is the JSON body parsed as an array (`[]` when the body
     *   wasn't decodable). `status_code` is `0` when transport failed entirely.
     */
    public function submit(int $formId, array $payload): array
    {
        // Fluent's submit handler expects `data` as a single URL-encoded string that it
        // then runs through parse_str() — see SubmissionHandler::submit(). Building the
        // body to that contract here is the only thing we have to mirror.
        $body = [
            'action'  => 'fluentform_submit',
            'form_id' => (string) $formId,
            'data'    => http_build_query($payload),
        ];

        $args = [
            'timeout'     => self::REQUEST_TIMEOUT,
            'redirection' => 0,
            'blocking'    => true,
            // Local loopback may use self-signed certs; respect the documented WP filter
            // for local SSL verification rather than hardcoding `false`.
            'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false),
            'cookies'     => $this->forwardAuthCookies(),
            'headers'     => [
                'Accept' => 'application/json',
            ],
            'body'        => $body,
        ];

        $response = wp_remote_post(admin_url('admin-ajax.php'), $args);

        if (is_wp_error($response)) {
            return [
                'transport_ok' => false,
                'status_code'  => 0,
                'decoded'      => [],
                'error'        => $response->get_error_message(),
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody    = (string) wp_remote_retrieve_body($response);

        $decoded = [];
        if ($rawBody !== '') {
            // Fluent's endpoint always emits JSON via wp_send_json[_success].
            $maybe = json_decode($rawBody, true);
            if (is_array($maybe)) {
                $decoded = $maybe;
            }
        }

        return [
            'transport_ok' => true,
            'status_code'  => $statusCode,
            'decoded'      => $decoded,
            'error'        => null,
        ];
    }

    /**
     * Forward only WordPress-issued auth cookies so the loopback request runs as the
     * same user as the originating REST call (preserves submission attribution).
     *
     * Filtering by prefix keeps unrelated cookies (analytics, third-party SDKs, …) out
     * of the loopback request entirely.
     *
     * @return array<int, WP_Http_Cookie>
     */
    private function forwardAuthCookies(): array
    {
        if (empty($_COOKIE) || ! is_array($_COOKIE)) {
            return [];
        }

        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }
            if (strpos($name, 'wordpress_') !== 0 && strpos($name, 'wp_') !== 0) {
                continue;
            }
            $cookies[] = new WP_Http_Cookie([
                // Cookie values are intentionally NOT re-sanitised — they must round-trip
                // byte-for-byte for WordPress to recognise them as a valid auth cookie.
                'name'  => $name,
                'value' => $value,
            ]);
        }

        return $cookies;
    }
}

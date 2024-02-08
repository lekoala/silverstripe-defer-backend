<?php

namespace LeKoala\DeferBackend;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\View\TemplateGlobalProvider;

/**
 * A dead simple csp provider
 *
 * @link https://csp.withgoogle.com/docs/strict-csp.html
 * @link https://content-security-policy.com/strict-dynamic/
 */
class CspProvider implements TemplateGlobalProvider
{
    use Configurable;

    /**
     * @config
     * @var string
     */
    private static $default_referrer_policy = "no-referrer-when-downgrade";

    /**
     * @config
     * @var bool
     */
    private static $enable_hsts = true;

    /**
     * @config
     * @var string
     */
    private static $hsts_header = 'max-age=300; includeSubDomains; preload; always;';

    /**
     * @config
     * @var bool
     */
    private static $enable_csp = false;

    /**
     * @config
     * @var string
     */
    private static $frame_ancestors = "'self'";

    /**
     * @config
     * @var string
     */
    private static $frame_options = "SAMEORIGIN";

    /**
     * @config
     * @var string
     */
    private static $csp_report_uri = null;

    /**
     * @config
     * @var bool
     */
    private static $csp_report_only = true;

    /**
     * @var string
     */
    protected static $csp_nonce = null;

    /**
     * Allows calling getCspNonce in the template for script inclusion
     *
     * @return array<string,string>
     */
    public static function get_template_global_variables()
    {
        return [
            'getCspNonce' => 'getCspNonce'
        ];
    }

    /**
     * @link https://content-security-policy.com/nonce/
     * @return string
     */
    public static function getCspNonce()
    {
        if (!self::$csp_nonce) {
            self::$csp_nonce = str_replace(["/", "+"], "", base64_encode(random_bytes(18)));
        }
        return self::$csp_nonce;
    }

    /**
     * Allow setting nonce from an external source
     * @param string $nonce
     * @return void
     */
    public static function setCspNonce($nonce)
    {
        self::$csp_nonce = $nonce;
    }

    /**
     * @param HTTPResponse $response
     * @return HTTPResponse
     */
    public static function addSecurityHeaders(HTTPResponse $response)
    {
        $config = self::config();

        // @link https://web.dev/referrer-best-practices/
        if ($config->default_referrer_policy) {
            $response->addHeader('Referrer-Policy', $config->default_referrer_policy);
        }
        // enable HTTP Strict Transport Security
        if ($config->enable_hsts && $config->hsts_header && Director::is_https()) {
            $response->addHeader('Strict-Transport-Security', $config->hsts_header);
        }
        //
        if ($config->frame_options) {
            if (!$response->getHeader('X-Frame-Options')) {
                $response->addHeader('X-Frame-Options', $config->frame_options);
            }
        }
        return $response;
    }

    /**
     * Add CSP to the response using a flexible strict dynamic way
     *
     * @param HTTPResponse $response
     * @return void
     */
    public static function addCspHeaders(HTTPResponse $response)
    {
        // Only supported in https
        if (!Director::is_https()) {
            return;
        }
        $config = self::config();

        // Only add if enabled in config
        if (!$config->enable_csp) {
            return;
        }

        $csp = "default-src 'self' data:";

        $csp .= ';';
        $csp .= "script-src 'nonce-" . self::getCspNonce() . "' 'strict-dynamic' 'unsafe-inline' 'unsafe-eval' https: http:;";
        $csp .= "style-src * 'unsafe-inline';";
        $csp .= "object-src 'self';";
        $csp .= "img-src * data:;";
        $csp .= "font-src * data:;";

        // @link https://cheatsheetseries.owasp.org/cheatsheets/Clickjacking_Defense_Cheat_Sheet.html#content-security-policy-frame-ancestors-examples
        if ($config->frame_ancestors) {
            $csp .= "frame-ancestors " . $config->frame_ancestors . ";";
        }

        $report = $config->csp_report_uri;
        $reportOnly = $config->csp_report_only;

        if ($report) {
            $csp .= "report-uri "  . $report;
        }

        $headerName = 'Content-Security-Policy';
        if ($report && $reportOnly) {
            $headerName = 'Content-Security-Policy-Report-Only';
        }
        $response->addHeader($headerName, $csp);
    }
}

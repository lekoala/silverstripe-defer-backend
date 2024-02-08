<?php

namespace LeKoala\DeferBackend;

use Exception;
use SilverStripe\View\HTML;
use InvalidArgumentException;
use SilverStripe\View\SSViewer;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\View\ThemeResourceLoader;
use SilverStripe\View\Requirements_Backend;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

/**
 * A backend that defers everything by default
 *
 * Also insert custom head tags first because order may matter
 *
 * @link https://flaviocopes.com/javascript-async-defer/
 */
class DeferBackend extends Requirements_Backend
{
    use Configurable;

    /**
     * @config
     * @var boolean
     */
    private static $enable_js_modules = false;

    // It's better to write to the head with defer
    /**
     * @var boolean
     */
    public $writeJavascriptToBody = false;

    /**
     * @return DeferBackend
     */
    public static function getDeferBackend()
    {
        $backend = Requirements::backend();
        if (!($backend instanceof DeferBackend)) {
            throw new Exception("Requirements backend is currently of class " . get_class($backend));
        }
        return $backend;
    }

    /**
     * @param Requirements_Backend $oldBackend defaults to current backend
     * @return DeferBackend
     */
    public static function replaceBackend(Requirements_Backend $oldBackend = null)
    {
        if ($oldBackend === null) {
            $oldBackend = Requirements::backend();
        }
        $deferBackend = new self();
        foreach ($oldBackend->getCSS() as $file => $opts) {
            $deferBackend->css($file, null, $opts);
        }
        foreach ($oldBackend->getJavascript() as $file => $opts) {
            // Old scripts may get defer=false even if the option is not passed due to no null state
            unset($opts['defer']);
            $deferBackend->javascript($file, $opts);
        }
        foreach ($oldBackend->getCustomCSS() as $id => $script) {
            $deferBackend->customCSS($script, $id);
        }
        foreach ($oldBackend->getCustomScripts() as $id => $script) {
            $deferBackend->customScript($script, $id);
        }
        Requirements::set_backend($deferBackend);
        return $deferBackend;
    }

    /**
     * @return array<string>
     */
    public static function listCookieTypes()
    {
        return ['strictly-necessary', 'functionality', 'tracking', 'targeting'];
    }

    /**
     * Register the given JavaScript file as required.
     *
     * @param string $file Either relative to docroot or in the form "vendor/package:resource"
     * @param array<string,mixed> $options List of options. Available options include:
     * - 'provides' : List of scripts files included in this file
     * - 'async' : Boolean value to set async attribute to script tag
     * - 'defer' : Boolean value to set defer attribute to script tag (true by default)
     * - 'type' : Override script type= value.
     * - 'integrity' : SubResource Integrity hash
     * - 'crossorigin' : Cross-origin policy for the resource
     * - 'cookie-consent' : Type of cookie for conditionnal loading : strictly-necessary,functionality,tracking,targeting
     * - 'nomodule' : Boolean value to set nomodule attribute to script tag
     *
     * @return void
     */
    public function javascript($file, $options = [])
    {
        if (!is_array($options)) {
            $options = [];
        }
        if (self::config()->enable_js_modules) {
            if (empty($options['type']) && self::config()->enable_js_modules) {
                $options['type'] = 'module';
            }
            // Modules are deferred by default
            if (isset($options['defer']) && $options['type'] == "module") {
                unset($options['defer']);
            }
        } else {
            // We want to defer by default, but we can disable it if needed
            if (!isset($options['defer'])) {
                $options['defer'] = true;
            }
        }
        if (isset($options['cookie-consent'])) {
            if (!in_array($options['cookie-consent'], self::listCookieTypes())) {
                throw new InvalidArgumentException("The cookie-consent value is invalid, it must be one of: strictly-necessary,functionality,tracking,targeting");
            }
            // switch to text plain for conditional loading
            $options['type'] = 'text/plain';
        }
        if (isset($options['nomodule'])) {
            // Force type regardless of global setting
            $options['type'] = 'application/javascript';
        }
        parent::javascript($file, $options);

        $resolvedFile = ModuleResourceLoader::singleton()->resolvePath($file);
        // Parent call doesn't store all attributes, so we adjust ourselves
        if (isset($options['cookie-consent'])) {
            $this->javascript[$resolvedFile]['cookie-consent'] = $options['cookie-consent'];
        }
        if (isset($options['nomodule'])) {
            $this->javascript[$resolvedFile]['nomodule'] = $options['nomodule'];
        }
    }

    /**
     * @param string $name
     * @param mixed $type Pass the type or an array of options
     * @return void
     */
    public function themedJavascript($name, $type = null)
    {
        if ($type !== null && (!is_string($type) && !is_array($type))) {
            throw new InvalidArgumentException("Type must be a string or an array");
        }
        $path = ThemeResourceLoader::inst()->findThemedJavascript($name, SSViewer::get_themes());
        if ($path) {
            $options = [];
            if ($type) {
                if (is_string($type)) {
                    $options['type'] = $type;
                } else {
                    $options = $type;
                }
            }
            $this->javascript($path, $options);
        } else {
            throw new InvalidArgumentException(
                "The javascript file doesn't exist. Please check if the file $name.js exists in any "
                    . "context or search for themedJavascript references calling this file in your templates."
            );
        }
    }

    /**
     * Get all css files
     *
     * @return array<string,mixed>
     */
    public function getCSS()
    {
        $css = array_diff_key($this->css, $this->blocked);
        // Theme and assets files should always come last to have a proper cascade
        $allCss = [];
        $themeCss = [];
        foreach ($css as $file => $arr) {
            if (strpos($file, 'themes') === 0 || strpos($file, '/assets') === 0) {
                $themeCss[$file] = $arr;
            } else {
                $allCss[$file] = $arr;
            }
        }
        return array_merge($allCss, $themeCss);
    }

    /**
     * Update the given HTML content with the appropriate include tags for the registered
     * requirements. Needs to receive a valid HTML/XHTML template in the $content parameter,
     * including a head and body tag.
     *
     * @param string $content HTML content that has already been parsed from the $templateFile through {@link SSViewer}
     * @return string HTML content augmented with the requirements tags
     */
    public function includeInHTML($content)
    {
        // Get our CSP nonce, it's always good to have even if we don't use it :-)
        $nonce = CspProvider::getCspNonce();

        // Skip if content isn't injectable, or there is nothing to inject
        $tagsAvailable = preg_match('#</head\b#', $content);
        $hasFiles = !empty($this->css)
            || !empty($this->javascript)
            || !empty($this->customCSS)
            || !empty($this->customScript)
            || !empty($this->customHeadTags);

        if (!$tagsAvailable || !$hasFiles) {
            return $content;
        }
        $requirements = '';
        $jsRequirements = '';

        // Combine files - updates $this->javascript and $this->css
        $this->processCombinedFiles();

        // Script tags for js links
        foreach ($this->getJavascript() as $file => $attributes) {
            // Build html attributes
            $htmlAttributes = [
                'type' => isset($attributes['type']) ? $attributes['type'] : "application/javascript",
                'src' => $this->pathForFile($file),
                'nonce' => $nonce,
            ];
            if (!empty($attributes['async'])) {
                $htmlAttributes['async'] = 'async';
            }
            // defer is not allowed for module, ignore it as it does the same anyway
            if (!empty($attributes['defer']) && $htmlAttributes['type'] !== 'module') {
                $htmlAttributes['defer'] = 'defer';
            }
            if (!empty($attributes['integrity'])) {
                $htmlAttributes['integrity'] = $attributes['integrity'];
            }
            if (!empty($attributes['crossorigin'])) {
                $htmlAttributes['crossorigin'] = $attributes['crossorigin'];
            }
            if (!empty($attributes['cookie-consent'])) {
                $htmlAttributes['cookie-consent'] = $attributes['cookie-consent'];
            }
            if (!empty($attributes['nomodule'])) {
                $htmlAttributes['nomodule'] = 'nomodule';
            }
            $jsRequirements .= str_replace(' />', '>', HTML::createTag('script', $htmlAttributes));
            $jsRequirements .= "\n";
        }

        // Add all inline JavaScript *after* including external files they might rely on
        foreach ($this->getCustomScripts() as $scriptId => $script) {
            $type = self::config()->enable_js_modules ? 'module' : 'application/javascript';
            $attributes = [
                'type' => $type,
                'nonce' => $nonce,
            ];

            // since the Requirements API does not support passing variables, we use naming conventions
            if ($scriptId) {
                // Check for jsmodule in the name, since we have no other way to pass arguments
                if (strpos($scriptId, "jsmodule") !== false) {
                    $attributes['type'] = 'module';
                }

                // For cookie-consent, we rely on last part of uniquness id
                $parts = explode("-", $scriptId);
                $lastPart = array_pop($parts);
                if (in_array($lastPart, self::listCookieTypes())) {
                    $attributes['type'] = 'text/plain';
                    $attributes['cookie-consent'] = $lastPart;
                }
            }

            // Wrap script in a DOMContentLoaded
            // Make sure we don't add the eventListener twice (this will only work for simple scripts)
            // Make sure we don't wrap scripts concerned by security policies
            // Js modules are deferred by default, even if they are inlined, so not wrapping needed
            // @link https://stackoverflow.com/questions/41394983/how-to-defer-inline-javascript
            if (empty($attributes['cookie-consent']) && strpos($script, 'window.addEventListener') === false && $attributes['type'] !== 'module') {
                $script = "window.addEventListener('DOMContentLoaded', function() { $script });";
            }

            // Remove comments if any
            $script = preg_replace('/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\'|\")\/\/.*))/', '', $script);

            $jsRequirements .= HTML::createTag(
                'script',
                $attributes,
                "//<![CDATA[\n{$script}\n//]]>"
            );
            $jsRequirements .= "\n";
        }

        // Custom head tags (comes first)
        foreach ($this->getCustomHeadTags() as $customHeadTag) {
            $requirements .= "{$customHeadTag}\n";
        }

        // CSS file links
        foreach ($this->getCSS() as $file => $params) {
            $htmlAttributes = [
                'rel' => 'stylesheet',
                'type' => 'text/css',
                'href' => $this->pathForFile($file),
            ];
            if (!empty($params['media'])) {
                $htmlAttributes['media'] = $params['media'];
            }
            $requirements .= str_replace(' />', '>', HTML::createTag('link', $htmlAttributes));
            $requirements .= "\n";
        }

        // Literal custom CSS content
        foreach ($this->getCustomCSS() as $css) {
            $requirements .= HTML::createTag('style', ['type' => 'text/css'], "\n{$css}\n");
            $requirements .= "\n";
        }

        // Inject CSS  into body
        $content = $this->insertTagsIntoHead($requirements, $content);

        // Inject scripts
        if ($this->getForceJSToBottom()) {
            $content = $this->insertScriptsAtBottom($jsRequirements, $content);
        } elseif ($this->getWriteJavascriptToBody()) {
            $content = $this->insertScriptsIntoBody($jsRequirements, $content);
        } else {
            $content = $this->insertTagsIntoHead($jsRequirements, $content);
        }
        return $content;
    }
}

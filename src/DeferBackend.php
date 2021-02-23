<?php

namespace LeKoala\DeferBackend;

use Exception;
use SilverStripe\View\HTML;
use InvalidArgumentException;
use SilverStripe\View\SSViewer;
use SilverStripe\View\Requirements;
use SilverStripe\View\ThemeResourceLoader;
use SilverStripe\View\Requirements_Backend;

/**
 * A backend that defers everything by default
 *
 * Also insert custom head tags first because order may matter
 *
 * @link https://flaviocopes.com/javascript-async-defer/
 */
class DeferBackend extends Requirements_Backend
{
    // It's better to write to the head with defer
    public $writeJavascriptToBody = false;

    /**
     * @return $this
     */
    public static function getDeferBackend()
    {
        $backend = Requirements::backend();
        if (!$backend instanceof self) {
            throw new Exception("Requirements backend is currently of class " . get_class($backend));
        }
        return $backend;
    }

    /**
     * @param Requirements_Backend $oldBackend defaults to current backend
     * @return $this
     */
    public static function replaceBackend(Requirements_Backend $oldBackend = null)
    {
        if ($oldBackend === null) {
            $oldBackend = Requirements::backend();
        }
        $deferBackend = new static;
        foreach ($oldBackend->getCSS() as $file => $opts) {
            $deferBackend->css($file, null, $opts);
        }
        foreach ($oldBackend->getJavascript() as $file => $opts) {
            $deferBackend->javascript($file, null, $opts);
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
     * @return array
     */
    public static function listCookieTypes()
    {
        return ['strictly-necessary', 'functionality', 'tracking', 'targeting'];
    }

    /**
     * Register the given JavaScript file as required.
     *
     * @param string $file Either relative to docroot or in the form "vendor/package:resource"
     * @param array $options List of options. Available options include:
     * - 'provides' : List of scripts files included in this file
     * - 'async' : Boolean value to set async attribute to script tag
     * - 'defer' : Boolean value to set defer attribute to script tag (true by default)
     * - 'type' : Override script type= value.
     * - 'integrity' : SubResource Integrity hash
     * - 'crossorigin' : Cross-origin policy for the resource
     * - 'cookie-consent' : Type of cookie for conditionnal loading : strictly-necessary,functionality,tracking,targeting
     */
    public function javascript($file, $options = array())
    {
        // We want to defer by default, but we can disable it if needed
        if (!isset($options['defer'])) {
            $options['defer'] = true;
        }
        if (isset($options['cookie-consent'])) {
            if (!in_array($options['cookie-consent'], self::listCookieTypes())) {
                throw new InvalidArgumentException("The cookie-consent value is invalid, it must be one of: strictly-necessary,functionality,tracking,targeting");
            }
            // switch to text plain for conditional loading
            $options['type'] = 'text/plain';
        }
        parent::javascript($file, $options);
        if (isset($options['cookie-consent'])) {
            $this->javascript[$file]['cookie-consent'] = $options['cookie-consent'];
        }
    }

    /**
     * @param string $name
     * @param string|array $type Pass the type or an array of options
     * @return void
     */
    public function themedJavascript($name, $type = null)
    {
        $path = ThemeResourceLoader::inst()->findThemedJavascript($name, SSViewer::get_themes());
        if ($path) {
            $opts = [];
            if ($type) {
                if (is_string($type)) {
                    $opts['type'] = $type;
                } elseif (is_array($type)) {
                    $opts = $type;
                }
            }
            $this->javascript($path, $opts);
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
     * @return array
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
        $hasFiles = $this->css || $this->javascript || $this->customCSS || $this->customScript || $this->customHeadTags;
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
            if (!empty($attributes['defer'])) {
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
            $jsRequirements .= HTML::createTag('script', $htmlAttributes);
            $jsRequirements .= "\n";
        }

        // Add all inline JavaScript *after* including external files they might rely on
        foreach ($this->getCustomScripts() as $scriptId => $script) {
            if (is_numeric($scriptId)) {
                $script = $scriptId;
                $scriptId = null;
            }
            $attributes = [
                'type' => 'application/javascript',
                'nonce' => $nonce,
            ];
            // For cookie-consent, since the Requirements API does not support passing variables
            // we rely on last part of uniquness id
            if ($scriptId) {
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
            // @link https://stackoverflow.com/questions/41394983/how-to-defer-inline-javascript
            if (empty($attributes['cookie-consent']) && strpos($script, 'window.addEventListener') === false) {
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
            $requirements .= HTML::createTag('link', $htmlAttributes);
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

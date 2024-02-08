<?php

namespace LeKoala\DeferBackend\Test;

use LeKoala\DeferBackend\CspProvider;
use SilverStripe\Dev\SapphireTest;
use LeKoala\DeferBackend\DeferBackend;
use SilverStripe\Control\HTTPResponse;

class DeferBackendTest extends SapphireTest
{
    public function testWriteToHeader():void
    {
        $backend = new DeferBackend;
        $this->assertFalse($backend->writeJavascriptToBody);
    }

    public function testNonce():void
    {
        $this->assertNotEmpty(CspProvider::getCspNonce());
    }

    public function testProvideTemplate():void
    {
        $this->assertContains("getCspNonce", CspProvider::get_template_global_variables());
    }

    public function testAddSecurityHeaders():void
    {
        $res = new HTTPResponse();
        CspProvider::addSecurityHeaders($res);

        $headers = array_keys($res->getHeaders());
        $this->assertContains('referrer-policy', $headers, "Header not found in : " . implode(", ", $headers));
    }

    public function testWrapScripts():void
    {
        $backend = new DeferBackend;
        $backend->customScript("var test = 'test';");

        $sampleHTML = <<<HTML
<html>
<head></head>
<body></body>
</html>
HTML;
        $resultHTML = $backend->includeInHTML($sampleHTML);
        $this->assertStringContainsString("window.addEventListener('DOMContentLoaded'", $resultHTML);

        // not for js modules
        $backend->clear();
        $backend->customScript("var test = 'test';", "jsmodule");

        $resultHTML = $backend->includeInHTML($sampleHTML);
        $this->assertStringNotContainsString("window.addEventListener('DOMContentLoaded'", $resultHTML);
    }
}

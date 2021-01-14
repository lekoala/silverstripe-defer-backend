# SilverStripe Defer Backend module

[![Build Status](https://travis-ci.com/lekoala/silverstripe-defer-backend.svg?branch=master)](https://travis-ci.com/lekoala/silverstripe-defer-backend/)
[![scrutinizer](https://scrutinizer-ci.com/g/lekoala/silverstripe-defer-backend/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-defer-backend/)
[![Code coverage](https://codecov.io/gh/lekoala/silverstripe-defer-backend/branch/master/graph/badge.svg)](https://codecov.io/gh/lekoala/silverstripe-defer-backend)

## Intro

This module allows you to define a backend that defers your script by default.
As a nice bonus, it also allows you to set a simple content security policy by adding nonce to your scripts.

## Defer your requirements

In order to defer your scripts, you need to replace in your `PageController` the default backend.

    protected function init()
    {
        parent::init();

        DeferBackend::replaceBackend();
    }

Once this is done, all scripts (provided by modules or yourself) will be deferred. This is great
for performance because all scripts become non blocking and load order is preserved.
Scripts are added in the head, since they are not blocking, the browser can load them while parsing
the html.

### Inline scripts

Deferring inline scripts is not possible as such. But since events are fired once the dom is parsed,
you can wrap your scripts like so

    window.addEventListener('DOMContentLoaded', function() { ... });

This module automatically does this. Be aware that if you rely on global variables, you might want to
prevent this from happening by adding a comment with `//window.addEventListener` somewhere. This
will prevent our class to automatically wrap your script.

### Css order

This module also check your css files and make sure your themes files are loaded last. This make
sure that your styles cascade properly.

## Security headers

As a small bonus, this module allows you to add two security headers:
- Referrer-Policy
- Strict-Transport-Security (only if https is enabled)

    public function handleRequest(HTTPRequest $request)
    {
        $response = parent::handleRequest($request);

        CspProvider::addSecurityHeaders($response);

        return $response;
    }

## Content security policy

This module also add random nonce to your scripts. This allows you to setup a simple
Content Security Policy.

Also, a `$getCspNonce` is made available in your templates.

    public function handleRequest(HTTPRequest $request)
    {
        $response = parent::handleRequest($request);

        CspProvider::addCspHeaders($response);

        return $response;
    }

Please note that the csp is disabled by default. You might want to enable it with the following config:

    LeKoala\DeferBackend\CspProvider:
    enable_cst: true
    csp_report_uri: 'https://my-url-here'
    csp_report_only: false

Consider setting this to `csp_report_only` at the beginnning because enabling csp can break your website.

## Compatibility

Tested with 4.6 but should work on any ^4 projects

## Maintainer

LeKoala - thomas@lekoala.be

# SilverStripe Defer Backend module

[![Build Status](https://travis-ci.com/lekoala/silverstripe-defer-backend.svg?branch=master)](https://travis-ci.com/lekoala/silverstripe-defer-backend/)
[![scrutinizer](https://scrutinizer-ci.com/g/lekoala/silverstripe-defer-backend/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-defer-backend/)
[![Code coverage](https://codecov.io/gh/lekoala/silverstripe-defer-backend/branch/master/graph/badge.svg)](https://codecov.io/gh/lekoala/silverstripe-defer-backend)

## Intro

This module allows you to define a backend that defers your script by default.
As a nice bonus, it also allows you to set a simple content security policy by adding nonce to your scripts.

## Defer your requirements

In order to defer your scripts, you need to replace in your `PageController` the default backend.

```php
protected function init()
{
    parent::init();

    DeferBackend::replaceBackend();
}
```

Once this is done, all scripts (provided by modules or yourself) will be deferred. This is great
for performance because all scripts become non blocking and load order is preserved.
Scripts are added in the head, since they are not blocking, the browser can load them while parsing
the html.

### Inline scripts

Deferring inline scripts is not possible as such. But since events are fired once the dom is parsed,
you can wrap your scripts like so

```js
window.addEventListener('DOMContentLoaded', function() { ... });
```

This module automatically does this. Be aware that if you rely on global variables, you might want to
prevent this from happening by adding a comment with `//window.addEventListener` somewhere. This
will prevent our class to automatically wrap your script.

### Css order

This module also check your css files and make sure your themes files are loaded last. This make
sure that your styles cascade properly.

## Themed javascript

You can pass an array of options instead of just "type" parameter.

## Cookie consent

In order to support my [cookieconsent module](https://github.com/lekoala/silverstripe-cookieconsent) you
can now pass an additionnal option "cookie-consent" to your javascript files to load them conditionnaly.

```php
Requirements::javascript('myscript.js',['cookie-consent' => 'tracking']);
```

This also work (kind of) for custom scripts. Since the requirements api does not support anything
outside script and uniquenessID, we append the cookie type to the uniquenessID id

```php
Requirements::customScript($script, "ga-tracking");
```

## Security headers

As a small bonus, this module allows you to add two security headers:
- Referrer-Policy
- Strict-Transport-Security (only if https is enabled)

```php
public function handleRequest(HTTPRequest $request)
{
    $response = parent::handleRequest($request);

    CspProvider::addSecurityHeaders($response);

    return $response;
}
```

## Js modules support

If you want to use [native js modules](https://javascript.info/modules-intro), this can
be done with the following config flag:`

```yml
LeKoala\DeferBackend\DeferBackend:
  enable_js_modules: true
```

Js modules are deferred by default as well. In addition, script with `type=module` are only
loaded by modern browser, which can be really nice if you want to use modern browsers
and let other older browsers experience a js-less webpage.

This allows you to use native es6 syntax without bundlers like webpack, etc. at the cost
of not supporting older browsers.

## Content security policy

This module also add random nonce to your scripts. This allows you to setup a simple
Content Security Policy.

Also, a `$getCspNonce` is made available in your templates.

```php
public function handleRequest(HTTPRequest $request)
{
    $response = parent::handleRequest($request);

    CspProvider::addCspHeaders($response);

    return $response;
}
```
Please note that the csp is disabled by default. You might want to enable it with the following config:

```yml
LeKoala\DeferBackend\CspProvider:
  enable_cst: true
  csp_report_uri: 'https://my-url-here'
  csp_report_only: false
```

Consider setting this to `csp_report_only` at the beginnning because enabling csp can break your website.

## Compatibility

Tested with 4.6 but should work on any ^4 projects

## Maintainer

LeKoala - thomas@lekoala.be

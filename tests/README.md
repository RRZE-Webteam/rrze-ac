# Standalone regression tests

Run from the WordPress root (PHP 8.2+, WordPress 6.8+):

```sh
php wp-content/plugins/rrze-ac/tests/rest-sso.php
php wp-content/plugins/rrze-ac/tests/rest-sso.php --http
```

An optional WordPress root path can be supplied before `--http` when the plugin
is checked out elsewhere. Both runs are required because `REST_REQUEST` is a
constant: the default run tests internal REST dispatch without that constant
and subsequent frontend access; `--http` tests an HTTP REST request context.

The suite uses WordPress's actual REST server, `rest_do_request()`, REST context
detection and the plugin's access, SSO and collection-filtering methods. Fixture
routes exercise page collections and individual page/media access. Storage,
user identity, passwords and SimpleSAML are replaced with local fixtures.
No WordPress database, HTTP request or identity provider is used.

Covered behavior:

- GET/HEAD REST requests never start an interactive SSO login.
- Unauthorized pages are excluded from collections, including explicit include
  selections; individual protected pages and attachments return a REST error.
- Existing SSO sessions and affiliation/entitlement checks remain effective.
- A valid password alternative still grants access.
- Frontend automatic SSO, passive session checks and password alternatives
  retain their behavior after an internal REST request finishes.

These tests do not exercise a real SSO round trip, database query execution,
HTTP headers or the complete WordPress Core content controllers.

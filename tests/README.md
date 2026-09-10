# Isolated security regression tests

Run from `wp-content/plugins` with PHP and a local WordPress installation:

```sh
php rrze-ac/tests/rest-resources.php
php rrze-ac/tests/rest-collections.php
```

An optional first argument specifies the WordPress root. Tests use actual AC
access/permission classes and WordPress REST route matching, with fixtures for
storage, identity and SSO availability. They do not make database or HTTP requests
and do not replace testing real SSO, content rendering or FAUbox downloads.

The resource tests cover canonical paths, case variants, leading-zero IDs,
protected attachments, permitted IPs and unrelated plugin routes.

Collection tests cover explicit include selections, mixed allowed/denied IDs,
empty results, existing exclusions and authorized access. They execute the ID
restriction branch from the installed WordPress query builder without a database.

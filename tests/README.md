# Isolated security regression tests

Run from `wp-content/plugins` with PHP and a local WordPress installation:

```sh
php rrze-ac/tests/rest-resources.php
php rrze-ac/tests/rest-collections.php
php rrze-ac/tests/client-ip.php
php rrze-ac/tests/ip-access.php
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

IP tests cover forged forwarding headers, separate proxy trust, IPv4/IPv6 CIDRs,
single addresses, invalid input and authorized visitors behind proxy chains.

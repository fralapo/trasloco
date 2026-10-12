# Agency monitoring (opt-in, read-only)

Use the agency site dashboard only from a trusted WordPress installation. Each client WordPress must run a version of Trasloco with the read-only `GET /wp-json/trasloco/v1/health` endpoint and an administrator's dedicated WordPress Application Password.

Configure in the *central* WordPress wp-config.php (not in Git or a public admin form):

```php
define('TRASLOCO_AGENCY_SITES', array(
    array(
        'name' => 'Client A',
        'url' => 'https://client-a.example/',
        'user' => getenv('CLIENT_A_WP_USER'),
        'password' => getenv('CLIENT_A_WP_APP_PASSWORD'),
    ),
));
```

The agency page presents connection, backup-storage readiness and active background-job status. Transport is HTTPS with WordPress safe requests and redirects disabled. The plugin does not persist these credentials in its options table or expose them to browsers. Cache expires after five minutes; up to 15 sites are accepted in this first revision. Separate remote credentials for every client, revoke them when a contract ends.

This is **not yet** a multi-tenant remote-control or SSO platform: no remote commands, remote snapshots, billing, RBAC tenancy or automated enrollment. Do not configure public dashboards. Conduct an independent permissions audit before connecting client systems.

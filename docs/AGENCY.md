# Agency monitoring

The optional **Trasloco → Agency sites** page gives a local administrator a read-only view of up to 15 separately configured WordPress sites. It queries only the authenticated `GET /wp-json/trasloco/v1/health` endpoint already provided by Trasloco. It cannot start backups, restore archives, inspect archive contents, or execute remote commands.

Configure site definitions in the central site's `wp-config.php`, before WordPress loads plugins. Use a dedicated, revocable WordPress Application Password for each remote site; never commit credentials or put them in a database option or browser form.

```php
define( 'TRASLOCO_AGENCY_SITES', array(
    array(
        'name'     => 'Client A',
        'url'      => 'https://client-a.example/',
        'user'     => getenv( 'CLIENT_A_WP_USER' ),
        'password' => getenv( 'CLIENT_A_WP_APP_PASSWORD' ),
    ),
) );
```

The remote endpoint must be reachable through a public HTTPS URL and the Application Password must belong to an administrator. Requests verify TLS, reject redirects, time out after at most five seconds and limit the response to 16 KiB. The page spends at most ten seconds making remote requests; sites not reached within that page-load budget are checked after a reload. It displays only connection state, backup count, schedule and active/failed job counts. Results, including connection errors, are cached for five minutes; credentials and remote response fields are not rendered or stored as options.

This page is an overview, not a multi-tenant control plane. It has no site enrollment, remote actions, tenancy/RBAC, audit history, billing or automated credential rotation. Review the central site's access and revoke remote Application Passwords when they are no longer needed. Real remote hosting and credential lifecycle tests remain outstanding.

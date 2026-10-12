Direct WordPress-to-WordPress transfer (CLI)

On the destination host, use environment variables TRASLOCO_PEER_USER and TRASLOCO_PEER_APP_PASSWORD for a dedicated WordPress administrator Application Password on the source site. Run:

    wp trasloco pull-site https://source.example/ backup.wpress
    wp trasloco preview backup.wpress
    wp trasloco restore backup.wpress

The source must use public HTTPS and have a verified .wpress backup. Endpoint authorization is enforced on every request; the destination verifies each 512 KiB fragment and the full SHA-256. Partial files are stored in a private directory outside the webroot (defaults to PHP system temporary directory; configurable using TRASLOCO_PRIVATE_TRANSFER_DIR) and can be resumed after interruptions. Existing archives are not overwritten. No automatic target restore or pairing code. Revoke the source Application Password after migration. Real cross-host/WAF verification remains pending.

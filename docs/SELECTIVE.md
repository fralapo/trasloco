# Limited selective media recovery

From a verified local .wpress archive, restore ONE deleted ordinary upload, without replacing the database:

```bash
wp trasloco restore-media archive.wpress 2026/09/photo.jpg
```

The path is relative to `wp-content/uploads`. Supported non-executable extensions: jpg, jpeg, png, gif, webp, avif, pdf, txt, mp4, mov, webm, mp3, wav. This command **refuses** to replace existing files, limits recovered file size to 1 GiB, requires an archive SHA-256 manifest, and rejects path traversal and destination symlinks.

This is deliberately not an arbitrary PHP file restore: restoring executable files could compromise the site. There is currently no selective database/table restore or conflict resolution UI. Full restoration and recovery-point checks are separate features.

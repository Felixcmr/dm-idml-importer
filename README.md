# DM IDML Importer

Imports repeating InDesign **IDML** layouts into **Digitales Magazin** ACF blocks.

## Where to use

In WordPress admin:

- **Tools → IDML Import**

## Templates

- **Infoseiten (Content Teaser)** → generates `acf/ownheader` + intro paragraph + `acf/contentteaser`
- **Fotostrecke (Parallax Background)** → generates `acf/ownheader` + intro paragraph + `acf/parallaxbackground`

## Notes

- Images are resolved by matching the IDML link basename to an existing attachment (expected pattern: `*_small.jpg`).
- If you run this importer in a different WordPress than the one holding the Media Library, image fields will stay empty.

## Update notifications (GitHub)

This plugin can show update notifications in the WP admin (no one-click update).

By default it checks the public GitHub repo for the latest release tag.

Optional override in `wp-config.php`:

```php
define('DM_IDML_IMPORTER_GITHUB_REPO', 'your-org/dm-idml-importer');
```

Create GitHub Releases with tags like `v0.2.0` (the plugin reads `tag_name` from the latest release).

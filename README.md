## Enhanced Slug Regeneration Features for URL Routing in TYPO3 v9+

TYPO3 v9 has built-in handling for speaking URLs - called "slugs".

Sometimes it is necessary to recreate these speaking URLs for a site, bulk - this is handled via this extension.

If the extension `redirects` is installed, then redirects are generated for you.

Currently this is just a CLI script, callable via `vendor/bin/typo3 urls:regenerate`.

Try out `--help` for more detailled features.

## Installation

Use it via `composer req b13/slugr` or install the Extension `slugr` from the TYPO3 Extension Repository.

Once ready, try out the command line.

## ToDo

A backend module would be cool.

## License

As TYPO3 Core, _slugr_ is licensed under GPL2 or later. See the LICENSE file for more details.

## Authors & Maintenance

_slugr_ was initially created for a Christian Knauf by Benni Mack for [b13, Stuttgart](https://b13.com).

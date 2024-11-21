# RawCSS

A MediaWiki extension which allows for the transclusion of raw CSS from the RawCSS or Template namespace into pages

## System administrator guide

|     Configuration option      | Description                                                                                                  | Default |
|:-----------------------------:|--------------------------------------------------------------------------------------------------------------|---------|
| `$wgRawCSSSetCSSContentModel` | Set the default content model for any page in the Template namespace ending in `.css` to `CONTENT_MODEL_CSS` | `true`  |

### Added namespace (RawCSS)

- The ID is `6200` (and `6201` for the talk pages)
- The default content model is `less`
- The permissions required are:
	- `editrawcss`

### Added permission (`editrawcss`)

- The default groups denied this permission are:
	- `*`
- The default groups allowed this permission are:
	- `interface-admin`
- The default grants which give this permission are:
	- `editsiteconfig`

### Conflict with [TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles)

If `$wgRawCSSSetCSSContentModel` is set to `true` (by default),
you must disable `$wgTemplateStylesNamespaces[NS_TEMPLATE]` by setting it to `false` in [`LocalSettings.php`](https://www.mediawiki.org/wiki/Manual:LocalSettings.php).

## User guide

Create a page called `MediaWiki:RawCSS-applications.json` with a general format like this:

```json
{
	"Apple": {
		"coatings": [
			"RawCSS:Apple styling"
		],
		"variables": {
			"color-apple": "ff007a"
		},
		"preload": [
			{
				"href": "https://example.com/",
				"as": "image"
			}
		]
	}
}
```

### `MediaWiki:RawCSS-applications.json` schema

The general format of `MediaWiki:RawCSS-applications.json` is:

- The top-level JSON data type must be an object (the file must start with `{` and end with `}`)
- Each top-level property must be the name of a template (with or without the `Template:` prefix)
- The `coatings` property must be a list of coating pages (either in the `RawCSS` or `Template` namespaces, with the content model set to `css`)
- The `variables` property must be an object of CSS variables to set (don't prepend the name with `--`)
- The `preload` property must be a list of objects which must have the `href` and `as` properties set
	- `href` must be a valid URL

### Styling a template

1. Create a template with any content; it doesn't matter.
2. Create a page, either in the RawCSS ([Less](https://lesscss.org/) or CSS allowed) or Template (CSS only) namespaces.
3. Create or edit the `MediaWiki:RawCSS-applications.json` with something like [the above snippet](#user-guide) (if your template is `Template:Apple` and your coating is `RawCSS:Apple styling`)

### Additional notes

- If you need to set a Less variable, add the `variables` property with something like [the above snippet](#user-guide).
- Setting CSS variables is not supported.
- If you need to add something to be preloaded, add the `preload` property with something like [the above snippet](#user-guide).

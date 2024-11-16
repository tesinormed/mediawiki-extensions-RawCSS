# RawCSS

## Description

A MediaWiki extension which allows for the transclusion of raw (templated) CSS from the RawCSS namespace into the `<head>` of pages.

## System administrator guide

|       Configuration option       | Description                                                                           | Default                                |
|:--------------------------------:|---------------------------------------------------------------------------------------|----------------------------------------|
|    `$wgRawCSSLatteCachePath`     | The path for Latte to put its templates                                               | `$wgCacheDirectory/RawCSS/Latte`       |
| `$wgRawCSSPurgeOnStyleSheetEdit` | If the pages which use a style sheet should be purged when that style sheet is edited | `true`                                 |
|  `$wgRawCSSLatteSecurityPolicy`  | The [Latte security policy](https://latte.nette.org/en/sandbox) to use                | See `src/Utilities/TemplateEngine.php` |

## User guide

### Simple

To set up a simple style sheet, add your CSS into a page in the RawCSS namespace and reference it using `{{#rawcss:PageName}}`.

### Parameters

Passing parameters is like using a template; `{{#rawcss:PageName|arg1=value1|arg2=param2}}`.

To use these parameters in your CSS, simply add `{$arg1}`.

Getting errors from the syntax checker? Surround it in `/**/` (`/*{$arg1}*/`).

Another trick you may use (which is helpful for colors) is by prefixing it with `/*whatever*/unset` (like `/*#*/unset/*{$color}*/`).
This will become `#{$color}`.

> [!NOTE]
> Both of these tricks will be removed when it is used on a page; it will not be removed in the source style sheet.

If you need to add anything with escaped characters, like "#" or "/", you may either use `noescape` (like `{$color|noescape}`) or use the `unset` trick.

> [!WARNING]
> Be careful when you use `noescape`; this entirely disables escaping of input,
> which [could be dangerous for your wiki's security](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html).

> [!IMPORTANT]
> Spaces are not allowed *within the space between `/*` and `{` for tricks*.
> For example, `/*{$arg1}*/` and `/*{=$arg1 . $arg2}*/` would work, while `/* {$arg1} */` would not.

### Preloading assets

If you need to preload assets (like images or fonts), simply use the following *in the page*:

```
{{#linkheader:https://example.com|as=image|type=image/png}}
```

In the above, the URL is the link to the asset, `as` is the type of asset, and `type` is the MIME type of the asset file.
These parameters match [the `Link` header's parameters](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Link).

### Example style sheet

```css
/*{default $color = ff0072}*/
body {
	background-repeat: no-repeat;
	background-position: center;
	background-size: cover;
	background-image: url('{$background_image|checkUrl}');
}

a {
	color: /*#*/ unset /*{$color}*/
}
```

# RawCSS

## Description

A MediaWiki extension which allows for the transclusion of raw (templated) CSS from the RawCSS namespace into the
`<head>` of pages.

## System administrator guide

| Configuration option             | Description                                                                           | Default                          |
|----------------------------------|---------------------------------------------------------------------------------------|----------------------------------|
| `$wgRawCSSLatteCachePath`        | The path for Latte to put its templates                                               | `$wgCacheDirectory/RawCSS/Latte` |
| `$wgRawCSSPurgeOnStyleSheetEdit` | If the pages which use a style sheet should be purged when that style sheet is edited | `true`                           |
| `$wgRawCSSPreloadHrefRegex`      | The regex for validating a URL in the `href` of a preload directive                   | `.+`                             |                     

## User guide

### Simple

To set up a simple style sheet, add your CSS into a page in the RawCSS namespace and reference it using
`{{#rawcss:PageName}}`.

### Parameters

Passing parameters is like using a template; `{{#rawcss:PageName|arg1=value1|arg2=param2}}`.

To use these parameters in your CSS, simply add `{{$arg1}}`.

Getting errors from the syntax checker? Surround it in `/**/` (`/*{{$arg1}}*/`).

Another trick you may use (which is helpful for colors) is by prefixing it with `/*whatever*/unset` (like
`/*#*/unset/*{{$color}}*/`).
This will become `#{{$color}}`.

> [!NOTE]  
> Both of these tricks will be removed when it is used on a page; it will not be removed in the source style sheet.

If you need to add anything with escaped characters, like "#" or "/", you may either use `noescape` (like
`{{$color|noescape}}`) or use the `unset` trick.

> [!WARNING]  
> Be careful when you use `noescape`; this entirely disables escaping of input,
> which [could be dangerous for your wiki's security](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html).

### Preloading assets

If you need to preload assets (like images or fonts), simply use the following template:

```css
/*!preload|href=https://example.com|as=image|type=image/png*/
```

In the above, `href` is the asset's URL, `as` is the type of asset, and `type` is the MIME type of the asset file.
These parameters match [the `Link` header's parameters](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Link).

If you need to enable CORS, simply add `|crossorigin` at the end, like so:

```css
/*!preload|href=https://example.com|as=image|type=image/png|crossorigin*/
```

### Example style sheet

```css
/*{{default $color = ff0072}}*/
/* using noescape below is fine because the URL gets passed through PHP's FILTER_VALIDATE_URL (ONLY FOR PRELOAD DIRECTIVES) */
/* MIME is also validated to be in the format of image/* or font/* or text/css */
/*!preload|href={{$background_image|checkUrl|noescape}}|as=image|type={{$background_image_type|noescape}}*/
body {
    background-repeat: no-repeat;
    background-position: center;
    background-size: cover;
    background-image: url('{{$background_image|checkUrl}}');
}

a {
    color: /*#*/ unset /*{{$color}}*/
}
```

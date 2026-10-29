<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
    </url>
@endforeach
</urlset>

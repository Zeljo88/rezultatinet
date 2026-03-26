<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>rezultati.net — Live Score Vijesti</title>
    <link>https://rezultati.net</link>
    <description>Najnovije sportske vijesti, rezultati i recapi s rezultati.net</description>
    <language>bs-BA</language>
    <atom:link href="https://rezultati.net/feed" rel="self" type="application/rss+xml"/>
    <lastBuildDate>{{ now()->toRssString() }}</lastBuildDate>
    <image>
      <url>https://rezultati.net/images/og/default.jpg</url>
      <title>rezultati.net</title>
      <link>https://rezultati.net</link>
    </image>
    @foreach($posts as $post)
    <item>
      <title><![CDATA[{{ $post->title }}]]></title>
      <link>https://rezultati.net/blog/{{ $post->slug }}</link>
      <guid isPermaLink="true">https://rezultati.net/blog/{{ $post->slug }}</guid>
      <description><![CDATA[{{ $post->meta_description ?? Str::limit(strip_tags($post->content), 200) }}]]></description>
      <pubDate>{{ $post->created_at->toRssString() }}</pubDate>
      @if($post->featured_image)
      <enclosure url="{{ asset($post->featured_image) }}" type="image/png" length="0"/>
      @else
      <enclosure url="https://rezultati.net/images/og/default.jpg" type="image/jpeg" length="0"/>
      @endif
      @if($post->featured_image)
      <media:content url="{{ asset($post->featured_image) }}" medium="image"/>
      @else
      <media:content url="https://rezultati.net/images/og/default.jpg" medium="image"/>
      @endif
    </item>
    @endforeach
  </channel>
</rss>

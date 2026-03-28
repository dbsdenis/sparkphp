<?php

path('/documents/:slug')->name('docs.show');

// GET /documents/:slug — view a specific documentation page
get(function (string $slug) {
    $docsPath = app()->getBasePath() . '/docs';
    $file     = "{$docsPath}/{$slug}.md";

    if (!file_exists($file)) {
        abort(404, "Documento não encontrado: {$slug}");
    }

    $raw = file_get_contents($file);

    // Extract title from first H1
    $title = $slug;
    if (preg_match('/^#\s+(.+)/m', $raw, $m)) {
        $title = trim($m[1]);
    }

    // Build sidebar nav (all docs)
    $files = glob("{$docsPath}/*.md");
    sort($files);

    $docs = [];
    foreach ($files as $f) {
        $basename = basename($f, '.md');
        if ($basename === 'README') continue;

        $content = file_get_contents($f);
        $docTitle = $basename;
        if (preg_match('/^#\s+(.+)/m', $content, $dm)) {
            $docTitle = trim($dm[1]);
        }

        $docs[] = [
            'slug'   => $basename,
            'title'  => $docTitle,
            'active' => $basename === $slug,
        ];
    }

    return view('docs/show', [
        'title'      => $title,
        'slug'       => $slug,
        'content'    => markdown($raw, copyable(['php', 'bash', 'env', 'js', 'html', 'sql'])),
        'docs'       => $docs,
    ]);
});

<?php

path('/documents')->name('docs.index');

// GET /documents — documentation index
get(function () {
    $docsPath = app()->getBasePath() . '/docs';
    $readme   = file_exists("{$docsPath}/README.md")
        ? file_get_contents("{$docsPath}/README.md")
        : '';

    // Scan available doc files (sorted by prefix number)
    $files = glob("{$docsPath}/*.md");
    sort($files);

    $docs = [];
    foreach ($files as $file) {
        $basename = basename($file, '.md');
        if ($basename === 'README') continue;

        // Extract title from first H1 line
        $content = file_get_contents($file);
        $title   = $basename;
        if (preg_match('/^#\s+(.+)/m', $content, $m)) {
            $title = trim($m[1]);
        }

        $docs[] = [
            'slug'  => $basename,
            'title' => $title,
        ];
    }

    return view('docs/index', [
        'docs'    => $docs,
        'content' => markdown($readme),
    ]);
});

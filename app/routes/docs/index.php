<?php

path('/documents')->name('docs.index');

// GET /documents — documentation index
get(function () {
    $docsPath = app()->getBasePath() . '/docs';
    $readme   = file_exists("{$docsPath}/README.md")
        ? file_get_contents("{$docsPath}/README.md")
        : '';

    $svgIcon = static function (string $paths): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
    };

    $siteIcons = [
        'download' => $svgIcon('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>'),
        'route' => $svgIcon('<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>'),
        'file' => $svgIcon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>'),
        'file-input' => $svgIcon('<path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/>'),
        'layout' => $svgIcon('<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>'),
        'database' => $svgIcon('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>'),
        'shield-check' => $svgIcon('<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>'),
        'settings' => $svgIcon('<path d="M12 3v2.5"/><path d="M12 18.5V21"/><path d="M4.93 4.93l1.77 1.77"/><path d="M17.3 17.3l1.77 1.77"/><path d="M3 12h2.5"/><path d="M18.5 12H21"/><path d="M4.93 19.07l1.77-1.77"/><path d="M17.3 6.7l1.77-1.77"/><circle cx="12" cy="12" r="3.5"/>'),
        'lock' => $svgIcon('<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
        'key-round' => $svgIcon('<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>'),
        'hard-drive' => $svgIcon('<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>'),
        'bolt' => $svgIcon('<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>'),
        'mail' => $svgIcon('<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>'),
        'wrench' => $svgIcon('<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'),
        'sparkles' => $svgIcon('<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>'),
        'eye' => $svgIcon('<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>'),
        'rocket' => $svgIcon('<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>'),
        'terminal' => $svgIcon('<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>'),
        'arrows-up-down' => $svgIcon('<path d="m21 16-4 4-4-4"/><path d="M17 20V4"/><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/>'),
        'arrow-right' => $svgIcon('<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>'),
        'search' => $svgIcon('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>'),
        'brain' => $svgIcon('<path d="M12 5a3 3 0 1 0-5.997.125 4 4 0 0 0-2.526 5.77 4 4 0 0 0 .556 6.588A4 4 0 1 0 12 18Z"/><path d="M12 5a3 3 0 1 1 5.997.125 4 4 0 0 1 2.526 5.77 4 4 0 0 1-.556 6.588A4 4 0 1 1 12 18Z"/><path d="M15 13a4.5 4.5 0 0 1-3-4 4.5 4.5 0 0 1-3 4"/><path d="M17.599 6.5a3 3 0 0 0 .399-1.375"/><path d="M6.003 5.125A3 3 0 0 0 6.401 6.5"/><path d="M3.477 10.896a4 4 0 0 1 .585-.396"/><path d="M19.938 10.5a4 4 0 0 1 .585.396"/><path d="M6 18a4 4 0 0 1-1.967-.516"/><path d="M19.967 17.484A4 4 0 0 1 18 18"/>'),
        'code' => $svgIcon('<path d="m8 18-6-6 6-6"/><path d="m16 6 6 6-6 6"/><path d="m14.5 4-5 16"/>'),
        'chart-column' => $svgIcon('<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>'),
    ];

    $escapeInlineCode = static function (string $text): string {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return preg_replace_callback(
            '/`([^`]+)`/',
            static fn(array $match): string => '<code>' . htmlspecialchars($match[1], ENT_QUOTES, 'UTF-8') . '</code>',
            $escaped
        );
    };

    $extractGuideRows = static function (string $markdown) use ($escapeInlineCode): array {
        $rows = [];

        if (!preg_match('/## Guia\s+(?:\|.*\R)+/u', $markdown, $match)) {
            return $rows;
        }

        $lines = preg_split('/\R/u', trim($match[0])) ?: [];

        foreach ($lines as $line) {
            if (!str_starts_with(trim($line), '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 3 || $cells[0] === '#' || str_starts_with($cells[0], '----')) {
                continue;
            }

            if (!preg_match('/\[(.+?)\]\((.+?)\)/u', $cells[1], $topicMatch)) {
                continue;
            }

            $slug = basename($topicMatch[2], '.md');

            $rows[] = [
                'number'           => (int) $cells[0],
                'title'            => trim($topicMatch[1]),
                'slug'             => $slug,
                'description'      => trim($cells[2]),
                'description_html' => $escapeInlineCode(trim($cells[2])),
            ];
        }

        return $rows;
    };

    $extractPrinciples = static function (string $markdown) use ($siteIcons): array {
        $principles = [];

        if (!preg_match('/## Principios\s+(.*?)(?:\R## |\z)/su', $markdown, $match)) {
            return $principles;
        }

        $icons = [
            $siteIcons['file'],
            $siteIcons['settings'],
            $siteIcons['sparkles'],
            $siteIcons['eye'],
            $siteIcons['rocket'],
            $siteIcons['code'],
        ];

        foreach (preg_split('/\R/u', trim($match[1])) ?: [] as $line) {
            if (!preg_match('/^\d+\.\s+\*\*(.+?)\*\*\s+—\s+(.+)$/u', trim($line), $item)) {
                continue;
            }

            $principles[] = [
                'title'       => trim($item[1]),
                'description' => trim($item[2]),
                'icon'        => $icons[count($principles)] ?? '&#128196;',
            ];
        }

        return $principles;
    };

    $extractBadges = static function (string $markdown): array {
        if (!preg_match('/^Baseline atual:\s*(.+)$/mu', $markdown, $match)) {
            return [];
        }

        $baseline = rtrim(trim($match[1]), '.');
        $parts = preg_split('/,\s*| e /u', $baseline) ?: [];

        return array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            $parts
        ), static function (string $part): bool {
            return str_starts_with($part, 'PHP')
                || str_starts_with($part, 'MySQL')
                || str_starts_with($part, 'PostgreSQL');
        }));
    };

    $docs = $extractGuideRows($readme);

    if (empty($docs)) {
        $files = glob("{$docsPath}/*.md");
        sort($files);

        foreach ($files as $file) {
            $basename = basename($file, '.md');
            if ($basename === 'README') {
                continue;
            }

            $content = file_get_contents($file);
            $title = $basename;

            if (preg_match('/^#\s+(.+)/m', $content, $m)) {
                $title = trim($m[1]);
            }

            $docs[] = [
                'number'           => (int) strtok($basename, '-'),
                'slug'             => $basename,
                'title'            => $title,
                'description'      => $basename . '.md',
                'description_html' => htmlspecialchars($basename . '.md', ENT_QUOTES, 'UTF-8'),
            ];
        }
    }

    usort($docs, fn(array $a, array $b): int => $a['number'] <=> $b['number']);

    $coreTopics = array_values(array_filter($docs, fn(array $doc): bool => $doc['number'] <= 20));
    $productGuides = array_values(array_filter($docs, fn(array $doc): bool => $doc['number'] >= 21));

    $quickTopics = [
        [
            'number' => '01',
            'slug' => '01-installation',
            'title' => 'Instalação',
            'description' => 'Requisitos, setup, estrutura do projeto, .env',
            'icon' => $siteIcons['download'],
        ],
        [
            'number' => '02',
            'slug' => '02-routing',
            'title' => 'Routing',
            'description' => 'File-based routing, verbos, groups, smart resolving',
            'icon' => $siteIcons['route'],
        ],
        [
            'number' => '03',
            'slug' => '03-request-response',
            'title' => 'Request & Response',
            'description' => 'Input, output, headers, JSON, upload, factories',
            'icon' => $siteIcons['file-input'],
        ],
        [
            'number' => '04',
            'slug' => '04-views',
            'title' => 'Views & Templates',
            'description' => 'Spark Templates, layouts, pipes, loops, componentes',
            'icon' => $siteIcons['layout'],
        ],
        [
            'number' => '05',
            'slug' => '05-database',
            'title' => 'Database',
            'description' => 'QueryBuilder, Models, relações, migrations, seeds',
            'icon' => $siteIcons['database'],
        ],
        [
            'number' => '06',
            'slug' => '06-validation',
            'title' => 'Validation',
            'description' => 'Regras, mensagens, erros de view, old input',
            'icon' => $siteIcons['shield-check'],
        ],
        [
            'number' => '07',
            'slug' => '07-middleware',
            'title' => 'Middleware',
            'description' => 'Criando, aplicando com middleware.php, por rota/grupo',
            'icon' => $siteIcons['lock'],
        ],
        [
            'number' => '08',
            'slug' => '08-authentication',
            'title' => 'Authentication',
            'description' => 'Login, logout, registro, proteção de rotas',
            'icon' => $siteIcons['key-round'],
        ],
        [
            'number' => '09',
            'slug' => '09-session-cache',
            'title' => 'Session & Cache',
            'description' => 'Session, flash, CSRF, cache, opengraph',
            'icon' => $siteIcons['hard-drive'],
        ],
        [
            'number' => '10',
            'slug' => '10-events-jobs',
            'title' => 'Events & Jobs',
            'description' => 'Eventos file-based, jobs, filas, workers',
            'icon' => $siteIcons['bolt'],
        ],
        [
            'number' => '11',
            'slug' => '11-mail',
            'title' => 'Mail',
            'description' => 'SMTP, drivers, templates e mail assíncrono',
            'icon' => $siteIcons['mail'],
        ],
        [
            'number' => '12',
            'slug' => '12-helpers',
            'title' => 'Helpers (Funções Globais)',
            'description' => 'Panorama completo de todas as funções globais',
            'icon' => $siteIcons['wrench'],
        ],
        [
            'number' => '13',
            'slug' => '13-cli',
            'title' => 'CLI (Spark Commands)',
            'description' => 'Todos os comandos PHP Spark: generate, deploy',
            'icon' => $siteIcons['terminal'],
        ],
        [
            'number' => '14',
            'slug' => '14-releases',
            'title' => 'Releases & Compatibilidade',
            'description' => 'Changelog, suporte, backports, deprecações',
            'icon' => $siteIcons['arrows-up-down'],
        ],
        [
            'number' => '15',
            'slug' => '15-upgrade-guide',
            'title' => 'Upgrade Guide',
            'description' => 'Checklist oficial de upgrade e mudanças importantes',
            'icon' => $siteIcons['arrow-right'],
        ],
        [
            'number' => '16',
            'slug' => '16-ai',
            'title' => 'AI SDK',
            'description' => 'SDK unificado para texto, embeddings, imagem, áudio',
            'icon' => $siteIcons['sparkles'],
        ],
        [
            'number' => '17',
            'slug' => '17-ai-conventions',
            'title' => 'AI Conventions',
            'description' => 'app/ai/*, prompts nomeados, file-based structured output',
            'icon' => $siteIcons['brain'],
        ],
        [
            'number' => '18',
            'slug' => '19-ai-observability',
            'title' => 'AI Observability',
            'description' => 'Inspeção, mapeamento de traces, A/B test e smoke test',
            'icon' => $siteIcons['eye'],
        ],
        [
            'number' => '19',
            'slug' => '20-starter-kits',
            'title' => 'Starter Kits',
            'description' => 'Presets first-party para API, SaaS, admin e docs',
            'icon' => $siteIcons['rocket'],
        ],
        [
            'number' => '20',
            'slug' => '20-realtime',
            'title' => 'Realtime',
            'description' => 'SSE, WebSocket opcional, app/channels e broker append-only',
            'icon' => $siteIcons['bolt'],
        ],
        [
            'number' => '21',
            'slug' => '23-benchmarking',
            'title' => 'Benchmarks',
            'description' => 'Como medir Spark com honestidade e usar o CLI de benchmarks',
            'icon' => $siteIcons['chart-column'],
        ],
    ];

    $guideTopics = [];
    foreach ($quickTopics as $topic) {
        $guideTopics[$topic['slug']] = $topic;
    }

    $coreTopics = array_values(array_map(
        static function (array $doc) use ($guideTopics, $siteIcons): array {
            $guideTopic = $guideTopics[$doc['slug']] ?? null;

            if ($guideTopic === null) {
                return $doc + [
                    'icon' => $siteIcons['file'],
                ];
            }

            return [
                'number' => $guideTopic['number'],
                'slug' => $doc['slug'],
                'title' => $guideTopic['title'],
                'description' => $guideTopic['description'],
                'description_html' => htmlspecialchars($guideTopic['description'], ENT_QUOTES, 'UTF-8'),
                'icon' => $guideTopic['icon'],
            ];
        },
        $coreTopics
    ));

    return view('docs/index', [
        'coreTopics'    => $coreTopics,
        'productGuides' => $productGuides,
        'quickTopics'   => $quickTopics,
        'arrowIcon'     => $siteIcons['arrow-right'],
        'guideSearchIcon' => $siteIcons['search'],
        'badges'        => $extractBadges($readme),
        'highlights'    => [
            [
                'icon' => $siteIcons['sparkles'],
                'title' => 'Narrativa',
                'description' => 'Mais simples, mais previsível, mais observável',
            ],
            [
                'icon' => $siteIcons['code'],
                'title' => 'PHP puro',
                'description' => 'Sem dependências. Composer opcional.',
            ],
            [
                'icon' => $siteIcons['settings'],
                'title' => 'Zero config',
                'description' => 'Rode com .env e php spark serve',
            ],
        ],
        'principles'    => $extractPrinciples($readme),
    ]);
});

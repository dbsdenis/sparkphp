<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductGovernanceTest extends TestCase
{
    public function testContributionSurfacePublishesReviewChecklistGate(): void
    {
        $base = dirname(__DIR__, 2);

        $this->assertFileExists($base . '/CONTRIBUTING.md');
        $this->assertFileExists($base . '/.github/PULL_REQUEST_TEMPLATE.md');
        $this->assertFileExists($base . '/docs/25-review-checklist.md');

        $contributing = (string) file_get_contents($base . '/CONTRIBUTING.md');
        $template = (string) file_get_contents($base . '/.github/PULL_REQUEST_TEMPLATE.md');
        $guide = (string) file_get_contents($base . '/docs/25-review-checklist.md');

        $this->assertStringContainsString('mais curto', $contributing);
        $this->assertStringContainsString('mais claro', $contributing);
        $this->assertStringContainsString('mais observavel', $contributing);
        $this->assertStringContainsString('Mais curta', $template);
        $this->assertStringContainsString('Mais clara', $template);
        $this->assertStringContainsString('Mais observavel', $template);
        $this->assertStringContainsString('Exemplos de aprovacao', $guide);
        $this->assertStringContainsString('Exemplos de rejeicao', $guide);
    }

    public function testPublicDocumentationIndexesTheReviewChecklist(): void
    {
        $base = dirname(__DIR__, 2);

        $docsReadme = (string) file_get_contents($base . '/docs/README.md');
        $rootReadme = (string) file_get_contents($base . '/README.md');

        $this->assertStringContainsString('25-review-checklist.md', $docsReadme);
        $this->assertStringContainsString('Contributing', $rootReadme);
    }
}

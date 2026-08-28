<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SimilaritySearchBoundaryTest extends TestCase
{
    public function test_pgvector_operators_are_confined_to_the_postgres_adapter(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_ends_with($path, '/FaceRecognition/PostgresSimilaritySearch.php')) {
                continue;
            }
            $contents = file_get_contents($path);
            if (is_string($contents) && (str_contains($contents, '<=>') || str_contains($contents, 'vector_dims('))) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, 'pgvector-specific constructs escaped the PostgreSQL adapter.');
    }
}

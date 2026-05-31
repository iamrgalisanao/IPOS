<?php

namespace Tests\Feature\Compliance;

use Tests\TestCase;

class UserGuideQualityTest extends TestCase
{
    /**
     * Verify that no user guide file contains unresolved placeholder markers.
     */
    public function test_user_guide_files_have_no_placeholders(): void
    {
        $dir = base_path('docs/user-guide');
        $this->assertDirectoryExists($dir);

        $files = $this->getMarkdownFiles($dir);
        $this->assertNotEmpty($files, "No markdown files found under docs/user-guide");

        $placeholders = ['TODO', 'TBD', 'PLACEHOLDER', '[ ]'];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            // Allow status indicators explanation in the README
            if ($basename === 'README.md') {
                continue;
            }

            foreach ($placeholders as $placeholder) {
                $this->assertStringNotContainsString(
                    $placeholder,
                    $content,
                    "File {$basename} contains unresolved placeholder: '{$placeholder}'"
                );
            }
        }
    }

    /**
     * Verify that all links to other files inside the repository from the user guide are valid.
     */
    public function test_user_guide_links_are_valid(): void
    {
        $dir = base_path('docs/user-guide');
        $files = $this->getMarkdownFiles($dir);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $fileDir = dirname($file);

            // Find all markdown links [text](link)
            // Regex matches [something](anything)
            preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches);

            if (empty($matches[2])) {
                continue;
            }

            foreach ($matches[2] as $link) {
                // Ignore external web links, email links, anchors to same page
                if (
                    str_starts_with($link, 'http://') ||
                    str_starts_with($link, 'https://') ||
                    str_starts_with($link, 'mailto:') ||
                    str_starts_with($link, '#')
                ) {
                    continue;
                }

                // Parse file:/// URI or relative path
                $targetPath = $link;
                if (str_starts_with($link, 'file:///')) {
                    // For file:/// absolute links, convert to actual system path
                    // e.g. file:///Users/teamsolo/Documents/Dev/IPOS/docs/...
                    // We can resolve it by stripping file:/// and ensuring it exists
                    $targetPath = str_replace('file://', '', $link);
                    // Remove anchor if any
                    $targetPath = explode('#', $targetPath)[0];
                    // Also support URL-encoded spaces etc. if any
                    $targetPath = urldecode($targetPath);
                } else {
                    // Relative link, resolve against current file directory
                    $targetPath = explode('#', $targetPath)[0];
                    $targetPath = realpath($fileDir . '/' . $targetPath);
                }

                $this->assertNotNull($targetPath, "Broken link relative reference: '{$link}' in file " . basename($file));
                $this->assertFileExists($targetPath, "Link target does not exist: '{$link}' (resolved as '{$targetPath}') in file " . basename($file));
            }
        }
    }

    /**
     * Verify that all validated epics in the roadmap are represented in the user guides.
     */
    public function test_validated_roadmap_epics_are_documented(): void
    {
        $roadmapPath = base_path('docs/roadmap/validated-implementation-roadmap.md');
        $this->assertFileExists($roadmapPath);

        $roadmapContent = file_get_contents($roadmapPath);

        // Extract epics in the summary table that are closed or validated
        // Format example: | **Epic 1** | SaaS Foundation & Fail-Closed Tenant Isolation | **[Closed]** |
        // Format example: | **Epic 28** | Offline-Resilient POS Architecture | **[Phase 1 Closed / Phase 2 Implemented & Locally Validated ...]** |
        preg_match_all('/\|\s*\*\*Epic\s+(\d+)\*\*\s*\|\s*([^|]+)\s*\|\s*\*+\[?([^|*\]]+)/i', $roadmapContent, $matches);

        $closedEpics = [];
        if (!empty($matches[1])) {
            foreach ($matches[1] as $idx => $epicNum) {
                $status = trim($matches[3][$idx]);
                // If it contains Closed or Implemented, it's considered validated/approved
                if (
                    str_ireplace('Proposed', '', $status) !== $status &&
                    str_ireplace('Closed', '', $status) === $status &&
                    str_ireplace('Implemented', '', $status) === $status
                ) {
                    // It is Proposed and not closed/implemented, so skip
                    continue;
                }
                $closedEpics[] = (int) $epicNum;
            }
        }

        $this->assertNotEmpty($closedEpics, "Failed to parse any closed epics from the roadmap.");

        // Read changelog and module guides to verify coverage
        $changelogPath = base_path('docs/user-guide/changelog.md');
        $this->assertFileExists($changelogPath);
        $changelogContent = file_get_contents($changelogPath);

        $moduleGuidesDir = base_path('docs/user-guide/04-module-guides');
        $moduleGuideFiles = $this->getMarkdownFiles($moduleGuidesDir);

        $allGuidesContent = $changelogContent;
        foreach ($moduleGuideFiles as $moduleFile) {
            $allGuidesContent .= "\n" . file_get_contents($moduleFile);
        }

        foreach ($closedEpics as $epicNum) {
            // Search for "Epic X" or "Epic X:" in the combined documentation content
            $pattern = '/Epic\s+' . $epicNum . '\b/i';
            $this->assertMatchesRegularExpression(
                $pattern,
                $allGuidesContent,
                "Closed Epic {$epicNum} is not documented in any user guide or changelog."
            );
        }
    }

    /**
     * Helper to recursively scan directory for markdown files.
     */
    private function getMarkdownFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $files = array_merge($files, $this->getMarkdownFiles($path));
            } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'md') {
                $files[] = $path;
            }
        }

        return $files;
    }
}

<?php
/**
 * Content Processor Class
 * Handles post-upload processing of content files
 */

class ContentProcessor {
    private $db;
    private $claudeAPI;
    private $contentDir;
    private $basePath;

    public function __construct($db, $claudeAPI, $contentDir, $basePath = '') {
        $this->db = $db;
        $this->claudeAPI = $claudeAPI;
        $this->contentDir = $contentDir;
        $this->basePath = $basePath;
    }

    /**
     * Process uploaded content based on type
     */
    public function processContent($contentId, $contentType, $filePath) {
        switch ($contentType) {
            case 'scorm':
            case 'html':
                return $this->processZipContent($contentId, $contentType, $filePath);

            case 'email':
                return $this->processEmailContent($contentId, $filePath);

            case 'raw_html':
            case 'landing':
                return $this->processRawHTML($contentId, $filePath);

            case 'video':
                // Videos don't need processing, just store path
                return ['success' => true, 'message' => 'Video uploaded successfully'];

            default:
                throw new Exception("Unsupported content type: {$contentType}");
        }
    }

    /**
     * Process ZIP content (SCORM or HTML)
     */
    private function processZipContent($contentId, $contentType, $zipPath) {
        $extractPath = $this->contentDir . $contentId . '/';

        // Create extraction directory
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        // Extract ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception("Failed to open ZIP file");
        }

        $zip->extractTo($extractPath);
        $zip->close();

        // Delete the ZIP file after extraction
        unlink($zipPath);

        // Find index.html
        $indexPath = $this->findIndexFile($extractPath);
        if (!$indexPath) {
            throw new Exception("index.html not found in ZIP");
        }

        // Read HTML content
        $htmlContent = file_get_contents($indexPath);

        // For SCORM, preserve original HTML to avoid stripping JavaScript
        // For regular HTML, tag content with Claude API
        $tags = [];
        if ($contentType === 'html') {
            // Only tag simple HTML content, not SCORM
            $result = $this->claudeAPI->tagHTMLContent($htmlContent, 'general');
            $modifiedHTML = $result['html'];
            $tags = $result['tags'];

            // Download external assets (CDN references, /system paths, etc.)
            $modifiedHTML = $this->downloadSystemAssets($modifiedHTML, $extractPath, $contentId);
        } else {
            // For SCORM, use original HTML without modification
            $modifiedHTML = $htmlContent;
            // Extract minimal tags from title or meta tags if available
            if (preg_match('/<title>(.*?)<\/title>/i', $htmlContent, $matches)) {
                $title = strtolower(trim($matches[1]));
                // Basic keyword detection
                $keywords = ['phishing', 'ransomware', 'malware', 'password', 'security', 'privacy', 'email'];
                foreach ($keywords as $keyword) {
                    if (stripos($title, $keyword) !== false) {
                        $tags[] = $keyword;
                    }
                }
            }

            // Also download assets for SCORM (but don't modify HTML via AI)
            $modifiedHTML = $this->downloadSystemAssets($modifiedHTML, $extractPath, $contentId);
        }

        // Rename index.html to index.php
        $phpPath = str_replace('index.html', 'index.php', $indexPath);
        rename($indexPath, $phpPath);

        // Add tracking script to the HTML
        $trackingScript = "<?php \$trackingLinkId = \$_GET['tid'] ?? 'unknown'; ?>\n";

        // Inject base tag for relative URLs to work correctly
        // IMPORTANT: Base tag must be first in <head> to affect all relative URLs
        $baseTag = "<base href=\"{$this->basePath}/content/{$contentId}/\">";
        if (stripos($modifiedHTML, '<head>') !== false) {
            $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
        } elseif (stripos($modifiedHTML, '</head>') !== false) {
            $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
        }

        // Inject tracking script before </body>
        $trackingJS = str_replace('{$trackingLinkId}', "<?php echo \$trackingLinkId; ?>",
            $this->claudeAPI->generateTrackingScript('{$trackingLinkId}', $this->basePath));

        $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);

        // Write modified content
        file_put_contents($phpPath, $trackingScript . $modifiedHTML);

        // Store tags in database
        $this->storeTags($contentId, $tags);

        // Update content URL in database
        $this->db->update('content',
            ['content_url' => $contentId . '/index.php'],
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'Content processed successfully',
            'tags' => $tags,
            'path' => $contentId . '/index.php'
        ];
    }

    /**
     * Process email content with NIST Phish Scales
     */
    private function processEmailContent($contentId, $htmlPath) {
        $htmlContent = file_get_contents($htmlPath);

        // Load NIST guide if available
        $nistGuidePath = __DIR__ . '/../NIST_Phish_Scales_guide.pdf';
        $nistGuide = null;
        // Note: For full implementation, you'd extract text from PDF
        // For now, we'll use a summary prompt

        // Tag email with phishing cues
        $result = $this->claudeAPI->tagPhishingEmail($htmlContent, $nistGuide);

        // Create directory for email content
        $extractPath = $this->contentDir . $contentId . '/';
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        $phpPath = $extractPath . 'index.php';

        // Add tracking script
        $trackingScript = "<?php \$trackingLinkId = \$_GET['tid'] ?? 'unknown'; ?>\n";
        $modifiedHTML = $result['html'];

        // Inject base tag for relative URLs to work correctly
        // IMPORTANT: Base tag must be first in <head> to affect all relative URLs
        $baseTag = "<base href=\"{$this->basePath}/content/{$contentId}/\">";
        if (stripos($modifiedHTML, '<head>') !== false) {
            $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
        } elseif (stripos($modifiedHTML, '</head>') !== false) {
            $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
        }

        // Inject tracking script
        $trackingJS = str_replace('{$trackingLinkId}', "<?php echo \$trackingLinkId; ?>",
            $this->claudeAPI->generateTrackingScript('{$trackingLinkId}', $this->basePath));

        $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);

        // Write modified content
        file_put_contents($phpPath, $trackingScript . $modifiedHTML);

        // Store cues as tags
        $this->storeTags($contentId, $result['cues'], 'phish-cue');

        // Update content URL and difficulty score
        $updateData = ['content_url' => $contentId . '/index.php'];

        // Add difficulty score if provided
        if (isset($result['difficulty'])) {
            $updateData['difficulty'] = (string)$result['difficulty'];
        }

        $this->db->update('content',
            $updateData,
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'Email content processed successfully',
            'cues' => $result['cues'],
            'difficulty' => $result['difficulty'] ?? null,
            'path' => $contentId . '/index.php'
        ];
    }

    /**
     * Process raw HTML content
     */
    private function processRawHTML($contentId, $htmlContent) {
        // Tag content with Claude API
        $result = $this->claudeAPI->tagHTMLContent($htmlContent, 'general');
        $modifiedHTML = $result['html'];

        // Create directory
        $extractPath = $this->contentDir . $contentId . '/';
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        // Download any /system assets referenced in the HTML
        $modifiedHTML = $this->downloadSystemAssets($modifiedHTML, $extractPath, $contentId);

        $phpPath = $extractPath . 'index.php';

        // Add tracking script
        $trackingScript = "<?php \$trackingLinkId = \$_GET['tid'] ?? 'unknown'; ?>\n";

        // Inject base tag for relative URLs to work correctly
        // IMPORTANT: Base tag must be first in <head> to affect all relative URLs
        $baseTag = "<base href=\"{$this->basePath}/content/{$contentId}/\">";
        if (stripos($modifiedHTML, '<head>') !== false) {
            $modifiedHTML = preg_replace('/<head>/i', "<head>\n" . $baseTag, $modifiedHTML, 1);
        } elseif (stripos($modifiedHTML, '</head>') !== false) {
            $modifiedHTML = preg_replace('/<\/head>/i', $baseTag . "\n</head>", $modifiedHTML, 1);
        }

        // Inject tracking script
        $trackingJS = str_replace('{$trackingLinkId}', "<?php echo \$trackingLinkId; ?>",
            $this->claudeAPI->generateTrackingScript('{$trackingLinkId}', $this->basePath));

        if (stripos($modifiedHTML, '</body>') !== false) {
            $modifiedHTML = str_replace('</body>', $trackingJS . "\n</body>", $modifiedHTML);
        } else {
            $modifiedHTML .= "\n" . $trackingJS;
        }

        // Write content
        file_put_contents($phpPath, $trackingScript . $modifiedHTML);

        // Store tags
        $this->storeTags($contentId, $result['tags']);

        // Update content URL
        $this->db->update('content',
            ['content_url' => $contentId . '/index.php'],
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'HTML content processed successfully',
            'tags' => $result['tags'],
            'path' => $contentId . '/index.php'
        ];
    }

    /**
     * Find index.html in extracted directory
     */
    private function findIndexFile($dir) {
        // Check root directory first
        if (file_exists($dir . 'index.html')) {
            return $dir . 'index.html';
        }

        // Check subdirectories
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getFilename()) === 'index.html') {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * Store tags in database
     */
    private function storeTags($contentId, $tags, $tagType = 'interaction') {
        // Store in content_tags table (structured data for queries)
        foreach ($tags as $tag) {
            try {
                $this->db->insert('content_tags', [
                    'content_id' => $contentId,
                    'tag_name' => $tag,
                    'tag_type' => $tagType,
                    'confidence_score' => 1.0
                ]);
            } catch (Exception $e) {
                // Tag might already exist, ignore duplicate errors
                if (strpos($e->getMessage(), 'duplicate') === false) {
                    throw $e;
                }
            }
        }

        // Also store in content.tags field (comma-separated for compatibility)
        if (!empty($tags)) {
            $tagsString = implode(', ', $tags);
            try {
                $this->db->update('content',
                    ['tags' => $tagsString],
                    'id = :id',
                    [':id' => $contentId]
                );
            } catch (Exception $e) {
                // Column might not exist yet, log but don't fail
                error_log("Warning: Could not update content.tags field: " . $e->getMessage());
            }
        }
    }

    /**
     * Get content tags
     */
    public function getContentTags($contentId) {
        return $this->db->fetchAll(
            'SELECT * FROM content_tags WHERE content_id = :content_id',
            [':content_id' => $contentId]
        );
    }

    /**
     * Download external assets referenced in HTML
     * Finds references to /system paths (from login.phishme.com) and CDN assets (images.pmeimg.com)
     * Downloads them and updates HTML references to point to local copies
     */
    private function downloadSystemAssets($html, $contentDir, $contentId) {
        $assetsToDownload = [];

        // Pattern 1: /system paths (from login.phishme.com)
        $systemPatterns = [
            '/src=["\']?(\/system\/[^"\'\s>]+)["\'\s>]/i',
            '/href=["\']?(\/system\/[^"\'\s>]+)["\'\s>]/i',
            '/url\(["\']?(\/system\/[^"\'\)]+)["\'\)]/i' // CSS url() references
        ];

        foreach ($systemPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $fullPath) {
                    // Separate path from query string
                    $parts = explode('?', $fullPath, 2);
                    $pathOnly = $parts[0];

                    // Validate path to prevent directory traversal
                    if ($this->isValidSystemPath($pathOnly, $contentDir)) {
                        $assetsToDownload[$fullPath] = [
                            'fullPath' => $fullPath,
                            'pathOnly' => $pathOnly,
                            'downloadUrl' => 'https://login.phishme.com' . $fullPath,
                            'localPath' => $contentDir . ltrim($pathOnly, '/'),
                            'newHtmlPath' => $this->basePath . '/content/' . $contentId . $pathOnly
                        ];
                    } else {
                        error_log("Rejected invalid system path (directory traversal attempt): $pathOnly");
                    }
                }
            }
        }

        // Pattern 2: CDN assets (//images.pmeimg.com, //cdn.example.com, etc.)
        $cdnPatterns = [
            '/src=["\'](\/\/[^"\'\s>]+)["\'\s>]/i',
            '/href=["\'](\/\/[^"\'\s>]+)["\'\s>]/i',
            '/url\(["\']?(\/\/[^"\'\)]+)["\'\)]/i'
        ];

        foreach ($cdnPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $fullUrl) {
                    // Parse URL to extract components
                    $urlParts = parse_url('https:' . $fullUrl);
                    if (!$urlParts || !isset($urlParts['host'])) {
                        continue;
                    }

                    $host = $urlParts['host'];
                    $path = $urlParts['path'] ?? '/';
                    $query = isset($urlParts['query']) ? '?' . $urlParts['query'] : '';

                    // Create safe local directory structure: cdn/{host}/path
                    $localRelativePath = 'cdn/' . $host . $path;
                    $localPath = $contentDir . $localRelativePath;

                    $assetsToDownload[$fullUrl] = [
                        'fullPath' => $fullUrl,
                        'pathOnly' => $path,
                        'downloadUrl' => 'https:' . $fullUrl,
                        'localPath' => $localPath,
                        'newHtmlPath' => $this->basePath . '/content/' . $contentId . '/' . $localRelativePath
                    ];
                }
            }
        }

        // Download each unique asset
        foreach ($assetsToDownload as $originalRef => $asset) {
            $downloadUrl = $asset['downloadUrl'];
            $localPath = $asset['localPath'];

            // Create directory structure
            $localDir = dirname($localPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            // Download file using wget (with timeout and error handling)
            $escapedUrl = escapeshellarg($downloadUrl);
            $escapedPath = escapeshellarg($localPath);

            // Use wget with: timeout, follow redirects, quiet mode, overwrite existing
            $command = "wget --timeout=10 --tries=2 -q -O $escapedPath $escapedUrl 2>&1";

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                error_log("Downloaded asset: $originalRef from $downloadUrl");
            } else {
                error_log("Failed to download asset: $originalRef from $downloadUrl (exit code: $returnCode)");
                // Continue with other assets even if one fails
            }
        }

        // Update HTML references to point to the new local location
        foreach ($assetsToDownload as $originalRef => $asset) {
            $newPath = $asset['newHtmlPath'];

            // Replace all variations of the reference
            $html = str_replace('src="' . $originalRef . '"', 'src="' . $newPath . '"', $html);
            $html = str_replace("src='" . $originalRef . "'", "src='" . $newPath . "'", $html);
            $html = str_replace('href="' . $originalRef . '"', 'href="' . $newPath . '"', $html);
            $html = str_replace("href='" . $originalRef . "'", "href='" . $newPath . "'", $html);
            $html = str_replace('url(' . $originalRef . ')', 'url(' . $newPath . ')', $html);
            $html = str_replace('url("' . $originalRef . '")', 'url("' . $newPath . '")', $html);
            $html = str_replace("url('" . $originalRef . "')", "url('" . $newPath . "')", $html);
        }

        return $html;
    }

    /**
     * Validate that a system path is safe and doesn't contain directory traversal
     * Note: Path should not include query strings - they should be stripped before validation
     */
    private function isValidSystemPath($path, $contentDir) {
        // Must start with /system/
        if (strpos($path, '/system/') !== 0) {
            return false;
        }

        // Remove leading slash for path construction
        $relativePath = ltrim($path, '/');

        // Check for directory traversal sequences
        if (strpos($relativePath, '..') !== false) {
            return false;
        }

        // Construct the full path
        $fullPath = $contentDir . $relativePath;

        // Normalize the path to resolve any . or .. segments
        $realContentDir = realpath($contentDir);

        // If directory doesn't exist yet, check parent directories
        $pathToCheck = $fullPath;
        while (!file_exists($pathToCheck)) {
            $parent = dirname($pathToCheck);
            if ($parent === $pathToCheck) {
                // Reached root without finding existing path
                break;
            }
            $pathToCheck = $parent;
        }

        if (file_exists($pathToCheck)) {
            $resolvedPath = realpath($pathToCheck);
            // Ensure the resolved path is within the content directory
            if ($resolvedPath === false || strpos($resolvedPath, $realContentDir) !== 0) {
                return false;
            }
        }

        // Additional check: ensure path only contains safe filesystem characters
        // This validates the path portion only (query strings should be stripped before calling)
        if (!preg_match('/^\/system\/[a-zA-Z0-9\/_.\-]+$/', $path)) {
            return false;
        }

        return true;
    }
}

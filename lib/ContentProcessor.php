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
        $contentSize = strlen($htmlContent);

        error_log("Processing {$contentType} content, size: {$contentSize} bytes");

        // For SCORM, preserve original HTML to avoid stripping JavaScript
        // For regular HTML, tag content with Claude API
        $tags = [];

        // Skip AI processing if content is too large (>500KB) to prevent timeout
        $maxSizeForAI = 500000; // 500KB
        $chunkThreshold = 50000; // 50KB - use chunking for files larger than this

        if ($contentType === 'html' && $contentSize <= $maxSizeForAI) {
            // Decide whether to use chunking or single-pass processing
            if ($contentSize > $chunkThreshold) {
                // Large file: use chunking approach
                error_log("HTML content is large ({$contentSize} bytes), using chunked processing");
                $result = $this->processHTMLInChunks($htmlContent, 'educational');
                $modifiedHTML = $result['html'];
                $tags = $result['tags'];
            } else {
                // Small file: process in one go
                error_log("Tagging HTML content with Claude API (single pass)");
                $result = $this->claudeAPI->tagHTMLContent($htmlContent, 'educational');
                $modifiedHTML = $result['html'];
                $tags = $result['tags'];
            }
        } else {
            if ($contentType === 'html') {
                error_log("HTML content too large ({$contentSize} bytes), skipping AI tagging");
            }
            // For SCORM or extremely large HTML, use original HTML without modification
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
        $contentSize = strlen($htmlContent);
        error_log("Processing raw HTML content, size: {$contentSize} bytes");

        // Skip AI processing if content is too large (>500KB) to prevent timeout
        $maxSizeForAI = 500000; // 500KB
        $chunkThreshold = 50000; // 50KB - use chunking for files larger than this

        if ($contentSize <= $maxSizeForAI) {
            // Decide whether to use chunking or single-pass processing
            if ($contentSize > $chunkThreshold) {
                // Large file: use chunking approach
                error_log("Raw HTML content is large ({$contentSize} bytes), using chunked processing");
                $result = $this->processHTMLInChunks($htmlContent, 'educational');
                $modifiedHTML = $result['html'];
                $tags = $result['tags'];
            } else {
                // Small file: process in one go
                error_log("Tagging raw HTML content with Claude API (single pass)");
                $result = $this->claudeAPI->tagHTMLContent($htmlContent, 'educational');
                $modifiedHTML = $result['html'];
                $tags = $result['tags'];
            }
        } else {
            error_log("Raw HTML content too large ({$contentSize} bytes), skipping AI tagging");
            $modifiedHTML = $htmlContent;
            // Extract tags from title
            $tags = [];
            if (preg_match('/<title>(.*?)<\/title>/i', $htmlContent, $matches)) {
                $title = strtolower(trim($matches[1]));
                $keywords = ['phishing', 'ransomware', 'malware', 'password', 'security', 'privacy', 'email'];
                foreach ($keywords as $keyword) {
                    if (stripos($title, $keyword) !== false) {
                        $tags[] = $keyword;
                    }
                }
            }
        }

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
        $this->storeTags($contentId, $tags);

        // Update content URL
        $this->db->update('content',
            ['content_url' => $contentId . '/index.php'],
            'id = :id',
            [':id' => $contentId]
        );

        return [
            'success' => true,
            'message' => 'HTML content processed successfully',
            'tags' => $tags,
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
     * Download system assets referenced in HTML
     * Finds references to /system paths and downloads them from https://login.phishme.com
     * Updates HTML references to point to downloaded local copies
     */
    private function downloadSystemAssets($html, $contentDir, $contentId) {
        $baseUrl = 'https://login.phishme.com';

        // Find all references to /system paths in common HTML attributes
        $patterns = [
            '/src=["\']?(\/system\/[^"\'\s>]+)["\'\s>]/i',
            '/href=["\']?(\/system\/[^"\'\s>]+)["\'\s>]/i',
            '/url\(["\']?(\/system\/[^"\'\)]+)["\'\)]/i' // CSS url() references
        ];

        $assetsToDownload = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $fullPath) {
                    // Separate path from query string
                    $parts = explode('?', $fullPath, 2);
                    $pathOnly = $parts[0];
                    $queryString = isset($parts[1]) ? '?' . $parts[1] : '';

                    // Validate path to prevent directory traversal
                    if ($this->isValidSystemPath($pathOnly, $contentDir)) {
                        // Store both the full path (with query) for download and path-only for filesystem
                        $assetsToDownload[$fullPath] = [
                            'fullPath' => $fullPath,
                            'pathOnly' => $pathOnly,
                            'queryString' => $queryString
                        ];
                    } else {
                        error_log("Rejected invalid system path (directory traversal attempt): $pathOnly");
                    }
                }
            }
        }

        // Download each unique asset
        foreach ($assetsToDownload as $asset) {
            $fullPath = $asset['fullPath'];
            $pathOnly = $asset['pathOnly'];

            // Use full path (with query string) for download URL
            $fullUrl = $baseUrl . $fullPath;

            // Use path only (without query string) for local filesystem
            $localPath = $contentDir . ltrim($pathOnly, '/');

            // Create directory structure
            $localDir = dirname($localPath);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            // Download file using wget (with timeout and error handling)
            $escapedUrl = escapeshellarg($fullUrl);
            $escapedPath = escapeshellarg($localPath);

            // Use wget with: timeout, follow redirects, quiet mode, overwrite existing
            $command = "wget --timeout=10 --tries=2 -q -O $escapedPath $escapedUrl 2>&1";

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                error_log("Downloaded asset: $fullPath from $fullUrl");
            } else {
                error_log("Failed to download asset: $fullPath from $fullUrl (exit code: $returnCode)");
                // Continue with other assets even if one fails
            }
        }

        // Update HTML references to point to the new local location
        // Replace /system/... with {basePath}/content/{contentId}/system/...
        foreach ($assetsToDownload as $asset) {
            $fullPath = $asset['fullPath'];
            $pathOnly = $asset['pathOnly'];

            // New path points to local file (without query string since file is local)
            $newPath = $this->basePath . '/content/' . $contentId . $pathOnly;

            // Replace the full original path (with query string) with new local path (without query string)
            $html = str_replace('src="' . $fullPath . '"', 'src="' . $newPath . '"', $html);
            $html = str_replace("src='" . $fullPath . "'", "src='" . $newPath . "'", $html);
            $html = str_replace('href="' . $fullPath . '"', 'href="' . $newPath . '"', $html);
            $html = str_replace("href='" . $fullPath . "'", "href='" . $newPath . "'", $html);
            $html = str_replace('url(' . $fullPath . ')', 'url(' . $newPath . ')', $html);
            $html = str_replace('url("' . $fullPath . '")', 'url("' . $newPath . '")', $html);
            $html = str_replace("url('" . $fullPath . "')", "url('" . $newPath . "')", $html);
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

    /**
     * Split HTML content into chunks at safe boundaries
     * Splits at closing tags to avoid breaking HTML structure
     */
    private function splitHTMLIntoChunks($html, $maxChunkSize = 50000) {
        if (strlen($html) <= $maxChunkSize) {
            return [$html];
        }

        $chunks = [];
        $currentPos = 0;
        $htmlLength = strlen($html);

        error_log("Splitting HTML (" . $htmlLength . " bytes) into chunks of ~" . $maxChunkSize . " bytes");

        while ($currentPos < $htmlLength) {
            // Calculate the end position for this chunk
            $endPos = min($currentPos + $maxChunkSize, $htmlLength);

            // If we're at the end, just take the rest
            if ($endPos >= $htmlLength) {
                $chunks[] = substr($html, $currentPos);
                break;
            }

            // Extract chunk from current position to end position
            $chunk = substr($html, $currentPos, $endPos - $currentPos);

            // Find the last safe closing tag in this chunk
            // Look for common closing tags
            $safeTagPattern = '/<\/(div|section|form|article|main|p|li|ul|ol|table|tr|td|th|header|footer|nav|aside)>/i';

            if (preg_match_all($safeTagPattern, $chunk, $matches, PREG_OFFSET_CAPTURE)) {
                // Get the last match
                $lastMatch = end($matches[0]);
                $splitPoint = $lastMatch[1] + strlen($lastMatch[0]);

                // Adjust chunk to end at this safe point
                $chunk = substr($chunk, 0, $splitPoint);
            }
            // If no safe tags found, try to at least split at a tag boundary (any closing tag)
            elseif (preg_match_all('/<\/[^>]+>/i', $chunk, $matches, PREG_OFFSET_CAPTURE)) {
                $lastMatch = end($matches[0]);
                $splitPoint = $lastMatch[1] + strlen($lastMatch[0]);
                $chunk = substr($chunk, 0, $splitPoint);
            }

            $chunks[] = $chunk;
            $currentPos += strlen($chunk);
        }

        error_log("Split HTML into " . count($chunks) . " chunks");
        return $chunks;
    }

    /**
     * Process large HTML content in chunks
     * Each chunk is tagged separately and then reassembled
     */
    private function processHTMLInChunks($htmlContent, $contentType = 'educational') {
        $chunks = $this->splitHTMLIntoChunks($htmlContent, 50000); // 50KB chunks

        $taggedChunks = [];
        $allTags = [];

        foreach ($chunks as $index => $chunk) {
            $chunkNum = $index + 1;
            $totalChunks = count($chunks);

            error_log("Processing chunk {$chunkNum}/{$totalChunks} (" . strlen($chunk) . " bytes)");

            try {
                $result = $this->claudeAPI->tagHTMLContent($chunk, $contentType);
                $taggedChunks[] = $result['html'];

                // Accumulate tags from all chunks
                foreach ($result['tags'] as $tag) {
                    if (!in_array($tag, $allTags)) {
                        $allTags[] = $tag;
                    }
                }

                error_log("Chunk {$chunkNum}/{$totalChunks} completed, found " . count($result['tags']) . " tags");
            } catch (Exception $e) {
                error_log("Error processing chunk {$chunkNum}/{$totalChunks}: " . $e->getMessage());
                // On error, use original chunk without tags
                $taggedChunks[] = $chunk;
            }
        }

        // Reassemble all chunks
        $taggedHTML = implode('', $taggedChunks);

        error_log("Chunked processing complete. Total unique tags: " . count($allTags));

        return [
            'html' => $taggedHTML,
            'tags' => $allTags
        ];
    }
}

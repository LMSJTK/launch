<?php
/**
 * Claude API Integration Class
 * Handles communication with Anthropic's Claude API for content tagging
 */

class ClaudeAPI {
    private $config;
    private $apiKey;
    private $apiUrl;
    private $model;
    private $maxTokens;

    public function __construct($config) {
        $this->config = $config;
        $this->apiKey = $config['api_key'];
        $this->apiUrl = $config['api_url'];
        $this->model = $config['model'];
        $this->maxTokens = $config['max_tokens'];
    }

    /**
     * Send request to Claude API
     */
    private function sendRequest($messages, $systemPrompt = null) {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Claude API cURL Error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("Claude API HTTP Error {$httpCode}: {$response}");
        }

        $result = json_decode($response, true);
        if (!isset($result['content'][0]['text'])) {
            throw new Exception("Unexpected Claude API response format");
        }

        return $result['content'][0]['text'];
    }

    /**
     * Strip markdown code blocks from response
     */
    private function stripMarkdownCodeBlocks($text) {
        // Remove ```html ... ``` or ```... ``` blocks
        $text = preg_replace('/```(?:html)?\s*\n?(.*?)\n?```/s', '$1', $text);
        // Also remove any leading/trailing whitespace
        return trim($text);
    }

    /**
     * Extract only HTML from response, removing explanatory text
     */
    private function extractHTMLOnly($text) {
        // If the text starts with <!DOCTYPE or <html or <, it's likely all HTML
        if (preg_match('/^\s*(<(!DOCTYPE|html|head|body|div|form|script|style|!--|meta|link))/i', $text)) {
            // Find where HTML ends - look for common ending patterns followed by explanation text
            // Look for closing </html> or </body> followed by non-HTML text
            if (preg_match('/(.*?<\/html>\s*)(?:[^<]|$)/is', $text, $matches)) {
                return trim($matches[1]);
            }
            if (preg_match('/(.*?<\/body>\s*)(?:[^<]|$)/is', $text, $matches)) {
                return trim($matches[1]);
            }
        }

        // Try to extract HTML between first < and last >
        if (preg_match('/<.*>/s', $text, $matches)) {
            return trim($matches[0]);
        }

        return trim($text);
    }

    /**
     * Tag HTML content with interactive elements
     */
    public function tagHTMLContent($htmlContent, $contentType = 'educational') {
        $systemPrompt = "You are an expert at analyzing HTML content and identifying interactive elements. " .
            "Your task is to add data-tag attributes to interactive HTML elements to categorize the topics or skills being tested.\n\n" .
            "Rules:\n" .
            "1. Add data-tag attributes to interactive elements like inputs, buttons, selects, textareas, clickable elements\n" .
            "2. Tag values should be lowercase, hyphenated topic names (e.g., 'ransomware', 'phishing', 'password-security')\n" .
            "3. Only tag elements that are clearly testing knowledge or interaction with a specific topic\n" .
            "4. Return ONLY the complete modified HTML with data-tag attributes added\n" .
            "5. Do not modify the functionality or structure of the HTML, only add data-tag attributes\n" .
            "6. Do not include any explanations, comments, or markdown formatting - return ONLY the raw HTML\n" .
            "7. Common security topics include: phishing, ransomware, malware, social-engineering, password-security, data-privacy, email-security";

        $messages = [
            [
                'role' => 'user',
                'content' => "Add data-tag attributes to interactive elements in this HTML. Return ONLY the modified HTML without any explanations or markdown:\n\n" . $htmlContent
            ]
        ];

        $taggedHTML = $this->sendRequest($messages, $systemPrompt);

        // Strip any markdown code blocks and explanatory text
        $taggedHTML = $this->stripMarkdownCodeBlocks($taggedHTML);
        $taggedHTML = $this->extractHTMLOnly($taggedHTML);

        // Extract tags that were added
        preg_match_all('/data-tag="([^"]+)"/', $taggedHTML, $matches);
        $tags = array_unique($matches[1]);

        return [
            'html' => $taggedHTML,
            'tags' => $tags
        ];
    }

    /**
     * Tag phishing email content with NIST Phish Scale cues
     */
    public function tagPhishingEmail($emailHTML, $nistGuideContent = null) {
        $systemPrompt = "You are an expert at analyzing phishing emails using the NIST Phishing Scale methodology. " .
            "Your task is to identify phishing indicators and add data-cue attributes to mark them.\n\n" .
            "NIST Phish Scale Categories:\n" .
            "1. VISUAL CUES: Logo/branding issues, suspicious badges, fake security indicators\n" .
            "2. LANGUAGE CUES: Generic greetings, urgency tactics, grammar errors, requests for sensitive info\n" .
            "3. TECHNICAL CUES: Domain spoofing, URL hyperlinking tricks, suspicious attachments\n" .
            "4. ERROR CUES: Inconsistencies, formatting issues, suspicious patterns\n\n" .
            "NIST Phish Scale Difficulty Ratings:\n" .
            "- Least Difficult (1): Multiple obvious red flags, amateur mistakes, very easy to detect\n" .
            "- Moderately Difficult (2): Some red flags but requires closer inspection, decent attempt\n" .
            "- Very Difficult (3): Sophisticated, few obvious indicators, requires expert knowledge to detect\n\n" .
            "Rules:\n" .
            "1. Add data-cue attributes to elements containing phishing indicators\n" .
            "2. Use format: data-cue=\"category:description\" (e.g., data-cue=\"language:urgency-tactic\")\n" .
            "3. Categories: visual, language, technical, error\n" .
            "4. On the FIRST line, output: DIFFICULTY:X (where X is 1, 2, or 3)\n" .
            "5. Then output the complete modified HTML with data-cue attributes added\n" .
            "6. Do not modify the content or structure, only add data-cue attributes\n" .
            "7. Do not include any other explanations, comments, or markdown formatting";

        if ($nistGuideContent) {
            $systemPrompt .= "\n\nReference Guide:\n" . $nistGuideContent;
        }

        $messages = [
            [
                'role' => 'user',
                'content' => "Add data-cue attributes to phishing indicators in this email and assess its difficulty level. " .
                    "First line must be DIFFICULTY:X (1, 2, or 3), then the modified HTML:\n\n" . $emailHTML
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Extract difficulty score from first line
        $difficulty = 2; // Default to moderate
        if (preg_match('/^DIFFICULTY:\s*(\d+)/i', $response, $diffMatch)) {
            $difficulty = intval($diffMatch[1]);
            // Remove the difficulty line from response
            $response = preg_replace('/^DIFFICULTY:\s*\d+\s*\n?/i', '', $response);
        }

        // Strip any markdown code blocks and explanatory text
        $taggedHTML = $this->stripMarkdownCodeBlocks($response);
        $taggedHTML = $this->extractHTMLOnly($taggedHTML);

        // Extract cues that were added
        preg_match_all('/data-cue="([^"]+)"/', $taggedHTML, $matches);
        $cues = array_unique($matches[1]);

        return [
            'html' => $taggedHTML,
            'cues' => $cues,
            'difficulty' => $difficulty
        ];
    }

    /**
     * Analyze SCORM content and suggest tags
     */
    public function analyzeSCORMContent($htmlContent) {
        $systemPrompt = "You are an expert at analyzing educational content. " .
            "Analyze the provided HTML content and identify the main topics, skills, or knowledge areas being taught or tested.";

        $messages = [
            [
                'role' => 'user',
                'content' => "Please analyze this SCORM content and list the main topics/skills covered. " .
                    "Return a JSON array of topic names (lowercase, hyphenated):\n\n" .
                    substr($htmlContent, 0, 10000) // Limit content size
            ]
        ];

        $response = $this->sendRequest($messages, $systemPrompt);

        // Try to parse JSON from response
        preg_match('/\[.*?\]/s', $response, $matches);
        if (!empty($matches)) {
            $tags = json_decode($matches[0], true);
            return is_array($tags) ? $tags : [];
        }

        return [];
    }

    /**
     * Generate interaction tracking script for injecting into content
     */
    public function generateTrackingScript($trackingLinkId, $basePath = '') {
        // Build API base URL
        $apiBase = $basePath . '/api';

        return <<<JAVASCRIPT
<script>
(function() {
    // API base path from config
    const API_BASE = '{$apiBase}';

    const TRACKING_LINK_ID = '{$trackingLinkId}';
    const interactions = [];
    let finalScore = null;

    // Track interactions with tagged elements
    function trackInteraction(element, interactionType, value = null) {
        const tag = element.getAttribute('data-tag') || element.getAttribute('data-cue');
        if (!tag) return;

        const interaction = {
            tag: tag,
            type: interactionType,
            value: value,
            timestamp: new Date().toISOString()
        };

        interactions.push(interaction);

        // Send to API
        fetch(API_BASE + '/track-interaction.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tracking_link_id: TRACKING_LINK_ID,
                tag_name: tag,
                interaction_type: interactionType,
                interaction_value: value
            })
        }).catch(err => console.error('Tracking error:', err));
    }

    // Listen for interactions on tagged elements
    document.addEventListener('DOMContentLoaded', function() {
        // Track clicks
        document.querySelectorAll('[data-tag], [data-cue]').forEach(el => {
            el.addEventListener('click', function(e) {
                trackInteraction(this, 'click');
            });

            // Track input changes
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                el.addEventListener('change', function(e) {
                    trackInteraction(this, 'input', this.value);
                });
            }
        });
    });

    // Hijack SCORM RecordTest function
    window.RecordTest = function(score) {
        finalScore = score;

        // Send score to API
        fetch(API_BASE + '/record-score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tracking_link_id: TRACKING_LINK_ID,
                score: score,
                interactions: interactions
            })
        }).catch(err => console.error('Score recording error:', err));

        return true;
    };

    // Track when page is viewed
    fetch(API_BASE + '/track-view.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tracking_link_id: TRACKING_LINK_ID })
    }).catch(err => console.error('View tracking error:', err));
})();
</script>
JAVASCRIPT;
    }
}

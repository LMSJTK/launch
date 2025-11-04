<?php
/**
 * Tracking Manager Class
 * Handles interaction tracking, score recording, and SNS publishing
 */

class TrackingManager {
    private $db;
    private $sns;

    public function __construct($db, $sns) {
        $this->db = $db;
        $this->sns = $sns;
    }

    /**
     * Create a tracking link for content launch
     */
    public function createTrackingLink($recipientId, $contentId) {
        // Generate unique tracking link ID
        $trackingLinkId = $this->generateUniqueId();

        // Insert tracking link
        $this->db->insert('oms_tracking_links', [
            'id' => $trackingLinkId,
            'recipient_id' => $recipientId,
            'content_id' => $contentId,
            'launch_url' => '/launch.php?tid=' . $trackingLinkId,
            'status' => 'pending'
        ]);

        return [
            'tracking_link_id' => $trackingLinkId,
            'launch_url' => '/launch.php?tid=' . $trackingLinkId
        ];
    }

    /**
     * Track content view
     */
    public function trackView($trackingLinkId) {
        $tracking = $this->getTrackingLink($trackingLinkId);
        if (!$tracking) {
            throw new Exception("Tracking link not found");
        }

        // Update viewed_at and status if not already viewed
        if ($tracking['status'] === 'pending') {
            $this->db->update('oms_tracking_links',
                [
                    'viewed_at' => date('Y-m-d H:i:s'),
                    'status' => 'viewed'
                ],
                'id = :id',
                [':id' => $trackingLinkId]
            );
        }

        // Create SNS message entry
        $this->createOrUpdateSNSMessage($trackingLinkId, [
            'event' => 'viewed',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
        ]);

        return ['success' => true];
    }

    /**
     * Track interaction with tagged element
     */
    public function trackInteraction($trackingLinkId, $tagName, $interactionType, $interactionValue = null, $success = null) {
        $tracking = $this->getTrackingLink($trackingLinkId);
        if (!$tracking) {
            throw new Exception("Tracking link not found");
        }

        // Insert interaction record
        $this->db->insert('content_interactions', [
            'tracking_link_id' => $trackingLinkId,
            'tag_name' => $tagName,
            'interaction_type' => $interactionType,
            'interaction_value' => $interactionValue,
            'success' => $success,
            'interaction_data' => json_encode([
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
            ])
        ]);

        // Update SNS message with interaction
        $this->addInteractionToSNSMessage($trackingLinkId, [
            'tag' => $tagName,
            'type' => $interactionType,
            'value' => $interactionValue,
            'success' => $success,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
        ]);

        return ['success' => true];
    }

    /**
     * Record test score
     */
    public function recordScore($trackingLinkId, $score, $interactions = []) {
        $tracking = $this->getTrackingLink($trackingLinkId);
        if (!$tracking) {
            throw new Exception("Tracking link not found");
        }

        // Determine pass/fail status (assuming 80% is passing)
        $passed = $score >= 80;
        $status = $passed ? 'passed' : 'failed';

        // Update tracking link
        $this->db->update('oms_tracking_links',
            [
                'score' => $score,
                'completed_at' => date('Y-m-d H:i:s'),
                'status' => $status
            ],
            'id = :id',
            [':id' => $trackingLinkId]
        );

        // If passed, increment tag scores for recipient
        if ($passed) {
            $this->updateRecipientTagScores($tracking['recipient_id'], $tracking['content_id']);
        }

        // Update SNS message with final score
        $this->addScoreToSNSMessage($trackingLinkId, $score, $status);

        // Publish to SNS
        $this->publishSNSMessage($trackingLinkId);

        return [
            'success' => true,
            'score' => $score,
            'status' => $status
        ];
    }

    /**
     * Update recipient tag scores
     */
    private function updateRecipientTagScores($recipientId, $contentId) {
        // Get content tags
        $tags = $this->db->fetchAll(
            'SELECT DISTINCT tag_name FROM content_tags WHERE content_id = :content_id',
            [':content_id' => $contentId]
        );

        foreach ($tags as $tag) {
            $tagName = $tag['tag_name'];

            // Check if record exists
            $existing = $this->db->fetchOne(
                'SELECT * FROM recipient_tag_scores WHERE recipient_id = :recipient_id AND tag_name = :tag_name',
                [':recipient_id' => $recipientId, ':tag_name' => $tagName]
            );

            if ($existing) {
                // Update existing record
                $this->db->query(
                    'UPDATE recipient_tag_scores SET score_count = score_count + 1, total_attempts = total_attempts + 1, last_updated = NOW() WHERE recipient_id = :recipient_id AND tag_name = :tag_name',
                    [':recipient_id' => $recipientId, ':tag_name' => $tagName]
                );
            } else {
                // Insert new record
                $this->db->insert('recipient_tag_scores', [
                    'recipient_id' => $recipientId,
                    'tag_name' => $tagName,
                    'score_count' => 1,
                    'total_attempts' => 1
                ]);
            }
        }
    }

    /**
     * Get tracking link
     */
    public function getTrackingLink($trackingLinkId) {
        return $this->db->fetchOne(
            'SELECT * FROM oms_tracking_links WHERE id = :id',
            [':id' => $trackingLinkId]
        );
    }

    /**
     * Get content for tracking link
     */
    public function getContentForTracking($trackingLinkId) {
        $tracking = $this->getTrackingLink($trackingLinkId);
        if (!$tracking) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM content WHERE id = :id',
            [':id' => $tracking['content_id']]
        );
    }

    /**
     * Create or update SNS message
     */
    private function createOrUpdateSNSMessage($trackingLinkId, $eventData) {
        $tracking = $this->getTrackingLink($trackingLinkId);

        $existing = $this->db->fetchOne(
            'SELECT * FROM sns_message_queue WHERE tracking_link_id = :tid AND sent = false',
            [':tid' => $trackingLinkId]
        );

        $messageData = $existing ? json_decode($existing['message_data'], true) : [
            'tracking_link_id' => $trackingLinkId,
            'recipient_id' => $tracking['recipient_id'],
            'content_id' => $tracking['content_id'],
            'events' => [],
            'interactions' => []
        ];

        $messageData['events'][] = $eventData;

        if ($existing) {
            $this->db->update('sns_message_queue',
                ['message_data' => json_encode($messageData)],
                'id = :id',
                [':id' => $existing['id']]
            );
        } else {
            $this->db->insert('sns_message_queue', [
                'tracking_link_id' => $trackingLinkId,
                'message_data' => json_encode($messageData),
                'sent' => false
            ]);
        }
    }

    /**
     * Add interaction to SNS message
     */
    private function addInteractionToSNSMessage($trackingLinkId, $interaction) {
        $existing = $this->db->fetchOne(
            'SELECT * FROM sns_message_queue WHERE tracking_link_id = :tid AND sent = false',
            [':tid' => $trackingLinkId]
        );

        if ($existing) {
            $messageData = json_decode($existing['message_data'], true);
            $messageData['interactions'][] = $interaction;

            $this->db->update('sns_message_queue',
                ['message_data' => json_encode($messageData)],
                'id = :id',
                [':id' => $existing['id']]
            );
        }
    }

    /**
     * Add score to SNS message
     */
    private function addScoreToSNSMessage($trackingLinkId, $score, $status) {
        $existing = $this->db->fetchOne(
            'SELECT * FROM sns_message_queue WHERE tracking_link_id = :tid AND sent = false',
            [':tid' => $trackingLinkId]
        );

        if ($existing) {
            $messageData = json_decode($existing['message_data'], true);
            $messageData['final_score'] = $score;
            $messageData['status'] = $status;
            $messageData['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');

            $this->db->update('sns_message_queue',
                ['message_data' => json_encode($messageData)],
                'id = :id',
                [':id' => $existing['id']]
            );
        }
    }

    /**
     * Publish SNS message
     */
    private function publishSNSMessage($trackingLinkId) {
        $message = $this->db->fetchOne(
            'SELECT * FROM sns_message_queue WHERE tracking_link_id = :tid AND sent = false',
            [':tid' => $trackingLinkId]
        );

        if (!$message) {
            return;
        }

        $messageData = json_decode($message['message_data'], true);

        try {
            $result = $this->sns->publishInteractionEvent(
                $trackingLinkId,
                $messageData['recipient_id'],
                $messageData['content_id'],
                $messageData['interactions'],
                $messageData['final_score'] ?? null
            );

            // Mark as sent
            $this->db->update('sns_message_queue',
                [
                    'sent' => true,
                    'sent_at' => date('Y-m-d H:i:s')
                ],
                'id = :id',
                [':id' => $message['id']]
            );

            return $result;
        } catch (Exception $e) {
            error_log("Failed to publish SNS message: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate unique ID
     */
    private function generateUniqueId() {
        return bin2hex(random_bytes(16));
    }
}

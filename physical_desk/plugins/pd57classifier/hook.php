<?php

/**
 * PD57 Classifier Plugin — Hook Functions
 *
 * Contains the actual classification logic triggered on ticket creation.
 * Calls the local pd57-classifier service and stores suggestions in the
 * plugin's own table for human review.
 *
 * @package    PD57Classifier
 */

/**
 * Plugin install — create required database tables.
 */
function plugin_pd57classifier_install(): bool
{
    plugin_pd57classifier_ensure_table();
    return true;
}

/**
 * Plugin uninstall — remove database tables.
 */
function plugin_pd57classifier_uninstall(): bool
{
    global $DB;
    if ($DB->tableExists('glpi_plugin_pd57classifier_suggestions')) {
        $DB->doQuery('DROP TABLE `glpi_plugin_pd57classifier_suggestions`');
    }
    return true;
}

/**
 * Get canonical category ID for "Other > Manual Triage" dynamically.
 * Never hardcodes numeric IDs.
 */
function plugin_pd57classifier_get_manual_triage_category_id(): int
{
    static $cachedId = null;
    if ($cachedId !== null) {
        return $cachedId;
    }

    global $DB;
    if ($DB && $DB->tableExists('glpi_itilcategories')) {
        $result = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_itilcategories',
            'WHERE'  => [
                'OR' => [
                    ['completename' => 'Other > Manual Triage'],
                    ['name'         => 'Manual Triage'],
                ]
            ],
            'LIMIT'  => 1
        ]);
        if ($row = $result->current()) {
            $cachedId = (int)$row['id'];
            return $cachedId;
        }
    }

    return 0; // Safe fallback if DB lookup fails
}

/**
 * Get the classifier service URL from environment or config.
 */
function plugin_pd57classifier_get_service_url(): string
{
    $url = getenv('PD57_CLASSIFIER_URL');
    if ($url === false || $url === '') {
        $url = 'http://pd57-classifier:5000';
    }
    return rtrim($url, '/');
}

/**
 * Call the classifier service with the ticket text.
 *
 * @param string $text The combined title + description
 * @return array  The parsed response array, guaranteed non-null
 */
function plugin_pd57classifier_call_service(string $text): array
{
    $url = plugin_pd57classifier_get_service_url() . '/v1/classify';

    $payload = json_encode([
        'text'  => $text,
        'top_n' => 3,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,           // Bounded synchronous timeout: 3 seconds
        CURLOPT_CONNECTTIMEOUT => 2,           // 2 second connection timeout
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $manualTriageId = plugin_pd57classifier_get_manual_triage_category_id();

    // 1. Connection error or HTTP non-200 (e.g. classifier down or timeout)
    if ($error !== '' || $httpCode !== 200 || $response === false) {
        $reason = $error !== '' ? $error : "HTTP {$httpCode}";
        Glpi\Event::log(
            0,
            'Ticket',
            4,
            'pd57classifier',
            sprintf('Classifier bounded synchronous call failed (%s). Safe fallback applied.', $reason)
        );
        return [
            'suggestions'        => [
                [
                    'category_id' => $manualTriageId,
                    'name'        => 'Manual Triage',
                    'path'        => 'Other > Manual Triage',
                    'confidence'  => 0.0,
                    'source'      => 'fallback'
                ]
            ],
            'needs_human_review' => true,
            'fallback_used'      => true,
            'model_name'         => 'cross-encoder/nli-MiniLM2-L6-H768',
            'inference_time_ms'  => 0.0,
            'failure_reason'     => "classifier_failed: {$reason}",
            'classification_source' => 'classifier_failed'
        ];
    }

    // 2. Safe JSON parsing & structural validation (handles malformed response, invalid scores, missing fields)
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        $jsonErr = json_last_error_msg();
        Glpi\Event::log(0, 'Ticket', 4, 'pd57classifier', 'Classifier returned malformed JSON: ' . $jsonErr);
        return [
            'suggestions'        => [
                [
                    'category_id' => $manualTriageId,
                    'name'        => 'Manual Triage',
                    'path'        => 'Other > Manual Triage',
                    'confidence'  => 0.0,
                    'source'      => 'fallback'
                ]
            ],
            'needs_human_review' => true,
            'fallback_used'      => true,
            'model_name'         => 'cross-encoder/nli-MiniLM2-L6-H768',
            'inference_time_ms'  => 0.0,
            'failure_reason'     => "malformed_json: {$jsonErr}",
            'classification_source' => 'classifier_failed'
        ];
    }

    // 3. Validate suggestions structure
    $validSuggestions = [];
    if (!empty($data['suggestions']) && is_array($data['suggestions'])) {
        foreach ($data['suggestions'] as $s) {
            if (is_array($s) && isset($s['category_id'])) {
                $cid = (int)$s['category_id'];
                if ($cid > 0) {
                    $validSuggestions[] = [
                        'category_id' => $cid,
                        'name'        => (string)($s['name'] ?? 'Unknown'),
                        'path'        => (string)($s['path'] ?? ''),
                        'confidence'  => is_numeric($s['confidence'] ?? 0) ? (float)$s['confidence'] : 0.0,
                        'source'      => (string)($s['source'] ?? 'classifier'),
                    ];
                }
            }
        }
    }

    if (empty($validSuggestions)) {
        return [
            'suggestions'        => [
                [
                    'category_id' => $manualTriageId,
                    'name'        => 'Manual Triage',
                    'path'        => 'Other > Manual Triage',
                    'confidence'  => 0.0,
                    'source'      => 'fallback'
                ]
            ],
            'needs_human_review' => true,
            'fallback_used'      => true,
            'model_name'         => $data['model_name'] ?? 'cross-encoder/nli-MiniLM2-L6-H768',
            'inference_time_ms'  => (float)($data['inference_time_ms'] ?? 0),
            'failure_reason'     => 'no_valid_suggestions',
            'classification_source' => 'classifier_abstained'
        ];
    }

    $data['suggestions'] = $validSuggestions;
    $data['classification_source'] = $data['fallback_used'] ? 'classifier_abstained' : 'ai_suggested';
    return $data;
}

/**
 * PRE_ITEM_ADD hook for Ticket.
 *
 * Bounded synchronous execution: calls classifier service before ticket save.
 * If user explicitly provided a category or "I'm not sure", respects their choice.
 * Never blocks ticket creation.
 *
 * @param Ticket $ticket The ticket being created
 */
function plugin_pd57classifier_pre_item_add_ticket(Ticket $ticket): void
{
    $input = $ticket->input;

    // Check if employee manually selected a valid category before submission
    $explicitCategorySelected = false;
    $userCategoryId = (int)($input['itilcategories_id'] ?? 0);
    $manualTriageId = plugin_pd57classifier_get_manual_triage_category_id();

    if ($userCategoryId > 0 && $userCategoryId !== $manualTriageId) {
        $explicitCategorySelected = true;
    }

    // Build text from title + description
    $title = $input['name'] ?? '';
    $content = $input['content'] ?? '';
    $text = trim($title . ' ' . strip_tags($content));

    $result = null;
    if (strlen($text) >= 5) {
        $result = plugin_pd57classifier_call_service($text);
    }

    $topAiCatId = (int)($result['suggestions'][0]['category_id'] ?? 0);
    $fallbackUsed = !empty($result['fallback_used']);
    $failureReason = $result['failure_reason'] ?? '';

    $source = 'ai_suggested';
    if ($explicitCategorySelected) {
        if ($topAiCatId > 0 && !$fallbackUsed) {
            if ($userCategoryId === $topAiCatId) {
                $source = 'employee_confirmed';
            } else {
                $source = 'employee_override';
            }
        } else {
            $source = 'manual_selection';
        }
    } elseif (isset($input['_is_not_sure']) && $input['_is_not_sure']) {
        $source = 'manual_triage';
        $ticket->input['itilcategories_id'] = $manualTriageId;
    } elseif ($fallbackUsed) {
        if (str_starts_with($failureReason, 'classifier_failed') || str_starts_with($failureReason, 'malformed_json')) {
            $source = 'classifier_failed';
        } else {
            $source = 'classifier_abstained';
        }
    }

    $_SESSION['pd57_classification'] = [
        'suggestions'                 => $result['suggestions'] ?? [],
        'needs_human_review'          => $result['needs_human_review'] ?? true,
        'fallback_used'               => $result['fallback_used'] ?? false,
        'model_name'                  => $result['model_name'] ?? 'cross-encoder/nli-MiniLM2-L6-H768',
        'inference_time_ms'           => $result['inference_time_ms'] ?? 0.0,
        'classification_source'       => $source,
        'explicit_category_selected'  => $explicitCategorySelected,
        'user_selected_category_id'   => $userCategoryId,
        'failure_reason'              => $result['failure_reason'] ?? '',
    ];
}

/**
 * ITEM_ADD hook for Ticket.
 * Persists comprehensive classification metadata to plugin DB table.
 *
 * @param Ticket $ticket The ticket that was just created
 */
function plugin_pd57classifier_item_add_ticket(Ticket $ticket): void
{
    global $DB;

    $classification = $_SESSION['pd57_classification'] ?? null;
    unset($_SESSION['pd57_classification']);

    if ($classification === null) {
        return;
    }

    $ticketId = $ticket->getID();
    if ($ticketId <= 0) {
        return;
    }

    plugin_pd57classifier_ensure_table();

    $suggestions = $classification['suggestions'] ?? [];
    $topSuggestion = $suggestions[0] ?? null;
    $secondSuggestion = $suggestions[1] ?? null;

    $topScore = (float)($topSuggestion['confidence'] ?? 0.0);
    $secondScore = (float)($secondSuggestion['confidence'] ?? 0.0);
    $margin = max(0.0, $topScore - $secondScore);

    $parts = explode(' > ', $topSuggestion['path'] ?? '');
    $dept = $parts[0] ?? '';
    $subdept = $parts[1] ?? '';
    $leaf = end($parts) ?: '';

    $finalCatId = (int)$ticket->fields['itilcategories_id'];

    foreach ($suggestions as $rank => $suggestion) {
        $DB->insert('glpi_plugin_pd57classifier_suggestions', [
            'tickets_id'                  => $ticketId,
            'category_id'                 => (int)($suggestion['category_id'] ?? 0),
            'category_name'               => $suggestion['name'] ?? 'Unknown',
            'category_path'               => $suggestion['path'] ?? '',
            'confidence'                  => (float)($suggestion['confidence'] ?? 0),
            'rank'                        => $rank + 1,
            'source'                      => $suggestion['source'] ?? 'classifier',
            'fallback_used'               => (int)($classification['fallback_used'] ?? 0),
            'model_name'                  => $classification['model_name'] ?? 'cross-encoder/nli-MiniLM2-L6-H768',
            'inference_time_ms'           => (float)($classification['inference_time_ms'] ?? 0),
            'classification_source'       => $classification['classification_source'] ?? 'ai_suggested',
            'ai_department'               => $dept,
            'ai_subdepartment'            => $subdept,
            'ai_leaf_category'            => $leaf,
            'ai_score'                    => $topScore,
            'ai_margin'                   => $margin,
            'final_confirmed_category_id' => $finalCatId,
            'employee_changed_suggestion' => ($finalCatId > 0 && $topSuggestion && $finalCatId != $topSuggestion['category_id']) ? 1 : 0,
            'failure_reason'              => $classification['failure_reason'] ?? '',
            'human_confirmed'             => 0,
            'human_override'              => 0,
            'date_creation'               => date('Y-m-d H:i:s'),
        ]);
    }

    if ($topSuggestion) {
        Glpi\Event::log(
            $ticketId,
            'Ticket',
            4,
            'pd57classifier',
            sprintf(
                'Classification record created (Source: %s, Top: %s, Score: %.1f%%).',
                $classification['classification_source'] ?? 'ai_suggested',
                $topSuggestion['name'] ?? 'Unknown',
                $topScore * 100
            )
        );
    }
}

/**
 * Ensure the plugin's suggestion table exists and has all required metadata columns.
 */
function plugin_pd57classifier_ensure_table(): void
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_pd57classifier_suggestions')) {
        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_pd57classifier_suggestions` (
            `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tickets_id`                  INT UNSIGNED NOT NULL DEFAULT 0,
            `category_id`                 INT UNSIGNED NOT NULL DEFAULT 0,
            `category_name`               VARCHAR(255) NOT NULL DEFAULT '',
            `category_path`               VARCHAR(500) NOT NULL DEFAULT '',
            `confidence`                  FLOAT NOT NULL DEFAULT 0,
            `rank`                        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `source`                      VARCHAR(50) NOT NULL DEFAULT 'classifier',
            `fallback_used`               TINYINT(1) NOT NULL DEFAULT 0,
            `model_name`                  VARCHAR(255) NOT NULL DEFAULT '',
            `inference_time_ms`           FLOAT NOT NULL DEFAULT 0,
            `classification_source`       VARCHAR(50) NOT NULL DEFAULT 'ai_suggested',
            `ai_department`               VARCHAR(100) NOT NULL DEFAULT '',
            `ai_subdepartment`            VARCHAR(100) NOT NULL DEFAULT '',
            `ai_leaf_category`            VARCHAR(100) NOT NULL DEFAULT '',
            `ai_score`                    FLOAT NOT NULL DEFAULT 0,
            `ai_margin`                   FLOAT NOT NULL DEFAULT 0,
            `final_confirmed_category_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `employee_changed_suggestion` TINYINT(1) NOT NULL DEFAULT 0,
            `failure_reason`              VARCHAR(255) NOT NULL DEFAULT '',
            `human_confirmed`             TINYINT(1) NOT NULL DEFAULT 0,
            `human_override`              TINYINT(1) NOT NULL DEFAULT 0,
            `override_category_id`        INT UNSIGNED DEFAULT NULL,
            `override_user_id`            INT UNSIGNED DEFAULT NULL,
            `override_date`               DATETIME DEFAULT NULL,
            `date_creation`               DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `category_id` (`category_id`),
            KEY `confidence` (`confidence`),
            KEY `classification_source` (`classification_source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $DB->doQuery($query);
        return;
    }

    // Alter table to add missing columns if upgrading schema
    $columnsToEnsure = [
        'classification_source'       => "VARCHAR(50) NOT NULL DEFAULT 'ai_suggested'",
        'ai_department'               => "VARCHAR(100) NOT NULL DEFAULT ''",
        'ai_subdepartment'            => "VARCHAR(100) NOT NULL DEFAULT ''",
        'ai_leaf_category'            => "VARCHAR(100) NOT NULL DEFAULT ''",
        'ai_score'                    => "FLOAT NOT NULL DEFAULT 0",
        'ai_margin'                   => "FLOAT NOT NULL DEFAULT 0",
        'final_confirmed_category_id' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'employee_changed_suggestion' => "TINYINT(1) NOT NULL DEFAULT 0",
        'failure_reason'              => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];

    foreach ($columnsToEnsure as $col => $definition) {
        if (!$DB->fieldExists('glpi_plugin_pd57classifier_suggestions', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_pd57classifier_suggestions` ADD COLUMN `{$col}` {$definition}");
        }
    }
}

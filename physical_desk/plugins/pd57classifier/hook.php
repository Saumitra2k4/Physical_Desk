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

/** Resolve a classifier path against the configured taxonomy, never a model-supplied ID. */
function plugin_pd57classifier_category_id_for_path(string $path): int
{
    global $DB;
    if ($path === '' || !$DB || !$DB->tableExists('glpi_itilcategories')) {
        return 0;
    }
    $rows = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_itilcategories',
        'WHERE' => ['completename' => $path, 'is_helpdeskvisible' => 1],
        'LIMIT' => 2,
    ]);
    // Ambiguous paths must be reviewed rather than assigned arbitrarily.
    if ($rows->count() !== 1) {
        return 0;
    }
    $row = $rows->current();
    return (int)$row['id'];
}

function plugin_pd57classifier_get_manual_triage_category_id(): int
{
    return plugin_pd57classifier_category_id_for_path('Other > Manual Triage');
}

function plugin_pd57classifier_fallback(string $reason): array
{
    return [
        'suggestions' => [[
            'category_id' => plugin_pd57classifier_get_manual_triage_category_id(),
            'name' => 'Manual Triage',
            'path' => 'Other > Manual Triage',
            'confidence' => 0.0,
            'source' => 'fallback',
        ]],
        'needs_human_review' => true,
        'fallback_used' => true,
        'model_name' => 'cross-encoder/nli-MiniLM2-L6-H768',
        'inference_time_ms' => 0.0,
        'failure_reason' => $reason,
        'classification_source' => in_array($reason, ['classifier_unavailable', 'classifier_timeout', 'malformed_json'], true)
            ? 'classifier_failed' : 'classifier_abstained',
    ];
}

/** Validate the untrusted HTTP body and resolve canonical paths locally. */
function plugin_pd57classifier_parse_response(string $response): array
{
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return plugin_pd57classifier_fallback('malformed_json');
    }
    $valid = [];
    if (isset($data['suggestions']) && is_array($data['suggestions'])) {
        foreach ($data['suggestions'] as $suggestion) {
            if (!is_array($suggestion) || !is_string($suggestion['path'] ?? null)) {
                continue;
            }
            $path = $suggestion['path'];
            $id = plugin_pd57classifier_category_id_for_path($path);
            $confidence = $suggestion['confidence'] ?? null;
            if ($id <= 0 || !is_numeric($confidence) || (float)$confidence < 0 || (float)$confidence > 1) {
                continue;
            }
            $parts = explode(' > ', $path);
            $valid[] = [
                'category_id' => $id,
                'name' => end($parts),
                'path' => $path,
                'confidence' => (float)$confidence,
                'source' => ($path === 'Other > Manual Triage') ? 'fallback' : 'classifier',
            ];
        }
    }
    if (!$valid || !empty($data['fallback_used']) || $valid[0]['path'] === 'Other > Manual Triage') {
        return plugin_pd57classifier_fallback('classifier_abstained_or_invalid_category');
    }
    return [
        'suggestions' => $valid,
        'needs_human_review' => true,
        'fallback_used' => false,
        'model_name' => is_string($data['model_name'] ?? null) ? substr($data['model_name'], 0, 255) : 'unknown',
        'inference_time_ms' => is_numeric($data['inference_time_ms'] ?? null) ? max(0.0, (float)$data['inference_time_ms']) : 0.0,
        'failure_reason' => '',
        'classification_source' => 'ai_suggested',
    ];
}

function plugin_pd57classifier_get_service_url(): string
{
    $url = getenv('PD57_CLASSIFIER_URL');
    return rtrim($url === false || $url === '' ? 'http://pd57-classifier:5000' : $url, '/');
}

/** Bounded classifier call; all failures produce a ticket-safe fallback. */
function plugin_pd57classifier_call_service(string $text): array
{
    $ch = curl_init(plugin_pd57classifier_get_service_url() . '/v1/classify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['text' => $text, 'top_n' => 3]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorCode = curl_errno($ch);
    curl_close($ch);
    if ($response === false || $httpCode !== 200) {
        Glpi\Event::log(0, 'Ticket', 4, 'pd57classifier',
            sprintf('Classifier request failed (HTTP %d, cURL %d); Manual Triage fallback applied.', $httpCode, $errorCode));
        return plugin_pd57classifier_fallback($errorCode === CURLE_OPERATION_TIMEDOUT ? 'classifier_timeout' : 'classifier_unavailable');
    }
    return plugin_pd57classifier_parse_response($response);
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
        if ($manualTriageId > 0) {
            $ticket->input['itilcategories_id'] = $manualTriageId;
        }
    } elseif ($fallbackUsed || $result === null) {
        $source = $result['classification_source'] ?? 'classifier_abstained';
        if ($manualTriageId > 0) {
            $ticket->input['itilcategories_id'] = $manualTriageId;
        }
    }

    $_SESSION['pd57_classification'] = [
        'suggestions'                 => $result['suggestions'] ?? [plugin_pd57classifier_fallback('classifier_short_text')['suggestions'][0]],
        'needs_human_review'          => $result['needs_human_review'] ?? true,
        'fallback_used'               => $result['fallback_used'] ?? true,
        'model_name'                  => $result['model_name'] ?? 'cross-encoder/nli-MiniLM2-L6-H768',
        'inference_time_ms'           => $result['inference_time_ms'] ?? 0.0,
        'classification_source'       => $source,
        'explicit_category_selected'  => $explicitCategorySelected,
        'user_selected_category_id'   => $userCategoryId,
        'failure_reason'              => $result['failure_reason'] ?? ($result === null ? 'classifier_short_text' : ''),
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

/** Apply a human decision only to a persisted suggestion on its own ticket. */
function plugin_pd57classifier_apply_suggestion(
    string $action,
    int $suggestionId,
    int $ticketId,
    int $requestedCategoryId,
    ?callable $ticketUpdater = null
): array {
    global $DB;
    if (!in_array($action, ['confirm', 'override'], true) || $suggestionId <= 0 || $ticketId <= 0) {
        return ['code' => 400, 'error' => 'Invalid parameters'];
    }
    if (Session::getLoginUserID() <= 0) {
        return ['code' => 403, 'error' => 'Access denied'];
    }
    if (!$DB->tableExists('glpi_plugin_pd57classifier_suggestions')) {
        return ['code' => 404, 'error' => 'Suggestion unavailable'];
    }
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticketId)) {
        return ['code' => 404, 'error' => 'Ticket unavailable'];
    }
    $isRequester = $ticket->canRequesterUpdateItem() && $ticket->canUpdateItem();
    $isAgent = Session::getCurrentInterface() !== 'helpdesk'
        && (Session::haveRight('ticket', UPDATE) || $ticket->ownItem())
        && $ticket->canUpdateItem();
    if (!$isRequester && !$isAgent) {
        return ['code' => 403, 'error' => 'Access denied'];
    }
    $suggestion = $DB->request([
        'FROM' => 'glpi_plugin_pd57classifier_suggestions',
        'WHERE' => ['id' => $suggestionId, 'tickets_id' => $ticketId],
        'LIMIT' => 1,
    ])->current();
    if (!$suggestion) {
        return ['code' => 404, 'error' => 'Suggestion unavailable for this ticket'];
    }
    if ((int)$suggestion['human_confirmed'] || (int)$suggestion['human_override']) {
        return ['code' => 409, 'error' => 'Suggestion already decided'];
    }
    $decided = $DB->request([
        'FROM' => 'glpi_plugin_pd57classifier_suggestions',
        'WHERE' => ['tickets_id' => $ticketId, 'OR' => ['human_confirmed' => 1, 'human_override' => 1]],
        'LIMIT' => 1,
    ]);
    if ($decided->count() > 0) {
        return ['code' => 409, 'error' => 'Ticket classification already decided'];
    }
    if ($action === 'confirm') {
        // The stored canonical path is the authority; the client ID is only an integrity check.
        $categoryId = plugin_pd57classifier_category_id_for_path((string)$suggestion['category_path']);
        if ($categoryId <= 0 || $categoryId !== (int)$suggestion['category_id']
            || $requestedCategoryId !== $categoryId) {
            return ['code' => 400, 'error' => 'Suggested category is invalid or changed'];
        }
    } else {
        $categoryId = $requestedCategoryId;
        $category = new ITILCategory();
        if ($categoryId <= 0 || !$category->getFromDB($categoryId)
            || (int)$category->fields['is_helpdeskvisible'] !== 1
            || !Session::haveAccessToEntity((int)$category->fields['entities_id'], (bool)$category->fields['is_recursive'])) {
            return ['code' => 400, 'error' => 'Invalid override category'];
        }
    }
    $category = new ITILCategory();
    if (!$category->getFromDB($categoryId)
        || !Session::haveAccessToEntity((int)$category->fields['entities_id'], (bool)$category->fields['is_recursive'])) {
        return ['code' => 400, 'error' => 'Category not available in this entity'];
    }
    $update = $ticketUpdater ?? static fn (Ticket $item, array $input): bool => (bool)$item->update($input);
    if (!$update($ticket, ['id' => $ticketId, 'itilcategories_id' => $categoryId])) {
        return ['code' => 409, 'error' => 'Ticket category update failed'];
    }
    $reloaded = new Ticket();
    if (!$reloaded->getFromDB($ticketId) || (int)$reloaded->fields['itilcategories_id'] !== $categoryId) {
        return ['code' => 409, 'error' => 'Ticket category did not persist'];
    }
    $audit = $action === 'confirm'
        ? ['human_confirmed' => 1]
        : ['human_override' => 1, 'override_category_id' => $categoryId,
           'override_user_id' => Session::getLoginUserID(), 'override_date' => date('Y-m-d H:i:s')];
    $audit['final_confirmed_category_id'] = $categoryId;
    if (!$DB->update('glpi_plugin_pd57classifier_suggestions', $audit,
        ['id' => $suggestionId, 'tickets_id' => $ticketId, 'human_confirmed' => 0, 'human_override' => 0])) {
        return ['code' => 500, 'error' => 'Ticket updated but audit persistence failed'];
    }
    Glpi\Event::log($ticketId, 'Ticket', 4, 'pd57classifier',
        sprintf('AI suggestion %s by user %d: category ID %d', strtoupper($action), Session::getLoginUserID(), $categoryId));
    return ['code' => 200, 'success' => true, 'message' => 'Ticket category and classification audit updated'];
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

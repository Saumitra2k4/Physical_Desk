<?php

/**
 * PD57 Classifier — Ticket Suggestion Tab
 *
 * Adds an "AI Suggestions" tab to the Ticket form showing classification
 * results. Provides buttons for human confirmation or override.
 *
 * @package    PD57Classifier
 */

class PluginPd57classifierTicketSuggestion extends CommonDBTM
{
    public static $rightname = 'ticket';

    /**
     * Get the tab name for display on the Ticket form.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Ticket)) {
            return '';
        }

        global $DB;

        // Count suggestions for this ticket
        $count = 0;
        if ($DB->tableExists('glpi_plugin_pd57classifier_suggestions')) {
            $result = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_plugin_pd57classifier_suggestions',
                'WHERE' => ['tickets_id' => $item->getID()],
            ]);
            if ($row = $result->current()) {
                $count = (int)$row['cpt'];
            }
        }

        if ($count === 0) {
            return '';
        }

        return self::createTabEntry(
            '🤖 AI Suggestions',
            $count
        );
    }

    /**
     * Display the tab content with classification suggestions.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Ticket)) {
            return false;
        }

        self::showSuggestions($item);
        return true;
    }

    /**
     * Render the suggestions table with confirm/override actions.
     */
    public static function showSuggestions(Ticket $ticket): void
    {
        global $DB;

        $ticketId = $ticket->getID();

        if (!$DB->tableExists('glpi_plugin_pd57classifier_suggestions')) {
            echo '<div class="alert alert-info">No AI classification data available.</div>';
            return;
        }

        $suggestions = $DB->request([
            'FROM'  => 'glpi_plugin_pd57classifier_suggestions',
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => ['rank ASC'],
        ]);

        if ($suggestions->count() === 0) {
            echo '<div class="alert alert-info">No AI suggestions for this ticket.</div>';
            return;
        }

        // Check if any suggestion has been confirmed or overridden
        $hasConfirmed = false;
        $rows = [];
        foreach ($suggestions as $row) {
            $rows[] = $row;
            if ($row['human_confirmed'] || $row['human_override']) {
                $hasConfirmed = true;
            }
        }

        echo '<div class="pd57-suggestions-container">';
        echo '<h3>🤖 AI Category Suggestions & Governance</h3>';
        echo '<div class="pd57-authority-badge alert alert-secondary mb-3">';
        echo '<strong>Authority Hierarchy:</strong> <code>AI Suggestion</code> &rarr; ';
        echo '<code>Employee Confirmation/Change</code> &rarr; ';
        echo '<code>Authorized Agent Correction</code>';
        echo '</div>';

        echo '<p class="pd57-disclaimer">';
        echo '<strong>Human Governance:</strong> The AI model assists but never silently makes final routing decisions.';
        echo '</p>';

        echo '<table class="tab_cadre_fixe">';
        echo '<thead><tr>';
        echo '<th>Rank</th>';
        echo '<th>Suggested Category</th>';
        echo '<th>Confidence</th>';
        echo '<th>Classification Source</th>';
        echo '<th>Status</th>';
        echo '<th>Agent Action</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($rows as $row) {
            $confidence = round(($row['confidence'] ?? 0) * 100, 1);
            $confClass  = $confidence >= 70 ? 'pd57-conf-high'
                        : ($confidence >= 45 ? 'pd57-conf-medium' : 'pd57-conf-low');

            $status = 'Pending Review';
            $statusClass = 'pd57-status-pending';
            if ($row['human_confirmed']) {
                $status = '✅ Confirmed';
                $statusClass = 'pd57-status-confirmed';
            } elseif ($row['human_override']) {
                $status = '✏️ Overridden by Agent';
                $statusClass = 'pd57-status-overridden';
            }

            $sourceLabel = htmlspecialchars($row['classification_source'] ?? $row['source'] ?? 'classifier');

            echo '<tr>';
            echo '<td>#' . (int)$row['rank'] . '</td>';
            echo '<td>';
            echo '<strong>' . htmlspecialchars($row['category_name']) . '</strong><br>';
            echo '<small class="text-muted">' . htmlspecialchars($row['category_path']) . '</small>';
            echo '</td>';
            echo '<td><span class="pd57-confidence ' . $confClass . '">' . $confidence . '%</span></td>';
            echo '<td><code>' . $sourceLabel . '</code></td>';
            echo '<td><span class="' . $statusClass . '">' . $status . '</span></td>';
            echo '<td>';

            if (!$hasConfirmed && !$row['human_confirmed'] && !$row['human_override']) {
                echo '<button class="btn btn-sm btn-success pd57-confirm-btn" ';
                echo 'data-suggestion-id="' . (int)$row['id'] . '" ';
                echo 'data-ticket-id="' . $ticketId . '" ';
                echo 'data-category-id="' . (int)$row['category_id'] . '">';
                echo '✅ Confirm</button> ';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Show model & execution metadata
        $firstRow = $rows[0] ?? null;
        if ($firstRow) {
            echo '<div class="pd57-model-info mt-3 p-2 bg-light border rounded">';
            echo '<small>';
            echo '<strong>Model:</strong> ' . htmlspecialchars($firstRow['model_name'] ?? 'cross-encoder/nli-MiniLM2-L6-H768');
            echo ' | <strong>Latency:</strong> ' . round($firstRow['inference_time_ms'] ?? 0, 1) . 'ms';
            echo ' | <strong>Mode:</strong> Bounded Synchronous Inference';
            echo ' | <strong>Fallback:</strong> ' . ($firstRow['fallback_used'] ? 'Yes' : 'No');
            if (!empty($firstRow['failure_reason'])) {
                echo ' | <strong>Failure Reason:</strong> ' . htmlspecialchars($firstRow['failure_reason']);
            }
            echo '</small>';
            echo '</div>';
        }

        echo '</div>';
    }
}

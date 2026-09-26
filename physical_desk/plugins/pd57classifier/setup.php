<?php

/**
 * PD57 Classifier Plugin — GLPI Plugin Definition
 *
 * Integrates the local MiniLM classifier service with GLPI ticket intake.
 * Provides AI-assisted category suggestions with mandatory human confirmation.
 *
 * @package    PD57Classifier
 * @author     Physical Desk Engineering
 * @license    GPLv3
 */

use Glpi\Plugin\Hooks;

define('PD57_CLASSIFIER_VERSION', '1.0.0');

/**
 * Plugin description for GLPI.
 */
function plugin_version_pd57classifier()
{
    return [
        'name'           => 'PD57 Classifier',
        'version'        => PD57_CLASSIFIER_VERSION,
        'author'         => 'Physical Desk Engineering',
        'license'        => 'GPLv3',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => '11.0',
            ],
        ],
    ];
}

/**
 * Check plugin prerequisites.
 */
function plugin_pd57classifier_check_prerequisites()
{
    return true;
}

/**
 * Check plugin configuration.
 */
function plugin_pd57classifier_check_config($verbose = false)
{
    return true;
}

/**
 * Plugin initialization — register hooks.
 */
function plugin_init_pd57classifier()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['pd57classifier'] = true;

    // Register the pre-item-add hook for Tickets
    // This fires BEFORE the ticket is saved, allowing us to inject suggestions
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['pd57classifier'] = [
        'Ticket' => 'plugin_pd57classifier_pre_item_add_ticket',
    ];

    // Register the post-item-add hook for logging classification results
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['pd57classifier'] = [
        'Ticket' => 'plugin_pd57classifier_item_add_ticket',
    ];

    // Add a tab to the Ticket form for classification suggestions
    Plugin::registerClass(
        'PluginPd57classifierTicketSuggestion',
        ['addtabon' => ['Ticket']]
    );

    // Add JS/CSS resources
    $PLUGIN_HOOKS['add_javascript']['pd57classifier'] = ['js/classifier_ui.js'];
    $PLUGIN_HOOKS['add_css']['pd57classifier'] = ['css/classifier_ui.css'];
}

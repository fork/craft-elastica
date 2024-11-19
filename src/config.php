<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * A plugin to connect to Elasticsearch and persist elements via hooks
 *
 * @link      http://fork.de
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 */

/**
 * Elasticsearch config.php
 *
 * This file exists only as a template for the Elasticsearch settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'elasticsearch.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multienvironment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

return [
    'hosts' => [],
    'indexTemplateName' => '',
    'indexTemplate' => '',
    'searchTemplates' => [],

    // Overrides the default ttr for the reindexing Queue Job.
    // This might be necessary when you have lots of data to index.
    // Set to null to use Craft's default (300 seconds).
    'reindexTtr' => null
];

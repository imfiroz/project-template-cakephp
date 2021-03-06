<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
use Cake\Core\Configure;
use Cake\Core\Exception\MissingPluginException;
use Cake\Core\Plugin;

/**
 * Additional bootstrapping and configuration for CLI environments should
 * be put here.
 */

// Set logs to default file mode.
Configure::write('Log', [
    'debug' => [
        'className' => 'Cake\Log\Engine\FileLog',
        'path' => LOGS,
        'file' => 'cli-debug',
        'levels' => ['debug'],
    ],
    'error' => [
        'className' => 'Cake\Log\Engine\FileLog',
        'path' => LOGS,
        'file' => 'cli-error',
        'levels' => ['notice', 'info', 'warning', 'error', 'critical', 'alert', 'emergency']
    ],
]);

try {
    Plugin::load('Bake');
} catch (MissingPluginException $e) {
    // Do not halt if the plugin is missing
}

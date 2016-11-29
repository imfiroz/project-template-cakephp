<?php
namespace App\Event\Controller;

use Cake\Event\Event;
use Cake\Event\EventListenerInterface;
use Cake\ORM\TableRegistry;
use RolesCapabilities\Utility\Capability;

class AccessControlListener implements EventListenerInterface
{
    /**
     * {@inheritDoc}
     */
    public function implementedEvents()
    {
        return [
            'Controller.Acl.accessibleUrl' => 'getAccessibleUrl'
        ];
    }

    /**
     * Retrieve current user capabilities and try to figure out an accessible url.
     *
     * @param \Cake\Event\Event $event Event instance
     * @param array $user User info
     * @return void
     */
    public function getAccessibleUrl(Event $event, array $user)
    {
        if (empty($user) || empty($user['id'])) {
            return;
        }

        $table = TableRegistry::get('RolesCapabilities.Capabilities');

        $capabilities = $table->getUserCapabilities($user['id']);

        foreach ($capabilities as $capability) {
            if (false !== strpos($capability, 'index')) {
                $event->result = Capability::capabilityToUrl($capability);
                break;
            }
        }
    }
}

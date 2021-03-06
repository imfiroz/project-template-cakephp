<?php
namespace App\Event\Plugin\Menu\View;

use Cake\Event\Event;
use Cake\Event\EventListenerInterface;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use CsvMigrations\MigrationTrait;
use Exception;
use Menu\Event\EventName;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;
use RolesCapabilities\CapabilityTrait;

class MenuListener implements EventListenerInterface
{
    use CapabilityTrait;
    use MigrationTrait;

    /**
     * ACL instance
     *
     * @var object
     */
    protected $_aclInstance;

    /**
     * {@inheritDoc}
     */
    public function implementedEvents()
    {
        return [
            (string)EventName::GET_MENU_ITEMS() => 'getMenuItems',
            (string)EventName::MENU_BEFORE_RENDER() => 'beforeRender'
        ];
    }

    /**
     * Method that returns menu nested array based on provided menu name
     *
     * @param \Cake\Event\Event $event Event object
     * @param string $name Menu name
     * @param array $user Current user
     * @param bool $fullBaseUrl Flag for fullbase url on menu links
     * @param array $modules Modules to fetch menu items for
     * @return void
     */
    public function getMenuItems(Event $event, $name, array $user, $fullBaseUrl = false, array $modules = [])
    {
        $result = [];
        if (empty($modules)) {
            $modules = $this->_getAllModules();
            if (MENU_MAIN === $name) {
                $modules[] = 'Search.Dashboards';
            }
        }

        $key = array_search('Search.Dashboards', $modules);
        if (false !== $key) {
            unset($modules[$key]);
            $result[] = [
                'label' => 'Dashboards',
                'url' => '#',
                'icon' => 'tachometer',
                'children' => $this->_getDashboardLinks($user)
            ];
        }

        foreach ($modules as $module) {
            try {
                $mc = new ModuleConfig(ConfigType::MENUS(), $module);
                $parsed = (array)json_decode(json_encode($mc->parse()), true);
                if (empty($parsed[$name])) {
                    continue;
                }

                foreach ($parsed[$name] as $item) {
                    $result[] = $item;
                }
            } catch (Exception $e) {
                //
            }
        }

        $event->result = $result;
    }

    /**
     * Get dashboard links for the menu.
     *
     * @param array $user Current user
     * @return array
     */
    protected function _getDashboardLinks(array $user)
    {
        $dashboards = TableRegistry::get('Search.Dashboards')->getUserDashboards($user);

        $result = [];
        foreach ($dashboards as $dashboard) {
            $result[] = [
                'label' => $dashboard->name,
                'url' => [
                    'plugin' => 'Search',
                    'controller' => 'Dashboards',
                    'action' => 'view',
                    $dashboard->id
                ],
                'icon' => 'tachometer'
            ];
        }

        $result[] = [
            'label' => 'Create',
            'url' => '/search/dashboards/add',
            'icon' => 'plus'
        ];

        return $result;
    }

    /**
     * Method that adds elements to view View top menu.
     *
     * @param  \Cake\Event\Event $event Event object
     * @param  array             $menu  Menu
     * @param  array             $user  User
     * @return void
     */
    public function beforeRender(Event $event, array $menu, array $user)
    {
        $event->result = $this->_checkItemsAccess($event, $menu, $user);
    }

    /**
     * Method responsible for checking user access on menu items.
     *
     * @param  \Cake\Event\Event $event Event object
     * @param  array             $menu  Menu items
     * @param  array             $user  User details
     * @return array
     */
    protected function _checkItemsAccess(Event $event, array $menu, array $user)
    {
        $result = [];
        foreach ($menu as $item) {
            // this is for label like menu items without a url or children
            if (empty($item['url']) && empty($item['children'])) {
                $result[] = $item;
                continue;
            }

            // if empty user get it from the SESSION
            if (empty($user)) {
                if (!empty($_SESSION['Auth']['User'])) {
                    $user = $_SESSION['Auth']['User'];
                }
            }

            // skip on empty user
            if (empty($user)) {
                $result[] = $item;
                continue;
            }

            $this->_aclInstance = TableRegistry::get('RolesCapabilities.Capabilities');

            $result[] = current($this->_checkItemAccess([$item], $user));
        }

        return $result;
    }

    /**
     * Method responsible for checking user access on menu current item(s).
     *
     * @param  array  $items Menu current item(s)
     * @param  array  $user  User details
     * @return array
     */
    protected function _checkItemAccess(array $items, array $user)
    {
        foreach ($items as $k => &$item) {
            $url = $item['url'];

            $internal = $this->_isInternalLink($item['url']);

            // access check on internal links
            if ($internal) {
                $url = $this->_parseUrl($item['url']);

                if (!$this->_checkAccess($url, $user)) {
                    // remove url from parent item on access check fail
                    if (!empty($item['children'])) {
                        unset($item['url']);
                    } else { // remove item on access check fail
                        unset($items[$k]);
                    }
                }
            }

            // evaluate child items
            if (!empty($item['children'])) {
                $item['children'] = $this->_checkItemAccess($item['children'], $user);
                if (empty($item['children']) && (empty($item['url']) || '#' === trim($item['url']))) {
                    unset($items[$k]);
                }
            }
        }

        return $items;
    }

    /**
     * Checks if provided URL is an internal link.
     *
     * @param array|string $url URL
     * @return bool
     */
    protected function _isInternalLink($url)
    {
        if (!is_string($url)) {
            return true;
        }

        if (!preg_match('/http/i', $url)) {
            return true;
        }

        if (0 === strpos($url, Router::fullBaseUrl())) {
            return true;
        }

        return false;
    }

    /**
     * Parses menu item URL.
     *
     * @param array|string $url Menu item URL
     * @return array
     */
    protected function _parseUrl($url)
    {
        if (!is_string($url)) {
            return $url;
        }

        $fullBaseUrl = Router::fullBaseUrl();

        // strip out full base URL from menu item's URL.
        if (false !== strpos($url, $fullBaseUrl)) {
            $url = str_replace($fullBaseUrl, '', $url);
        }

        return Router::parse($url);
    }
}

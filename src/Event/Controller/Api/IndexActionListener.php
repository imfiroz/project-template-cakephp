<?php
namespace App\Event\Controller\Api;

use App\Event\EventName;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Network\Request;
use Cake\ORM\Query;
use Cake\ORM\ResultSet;
use Cake\View\View;
use CsvMigrations\ConfigurationTrait;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;
use Qobo\Utils\ModuleConfig\ConfigType;
use Qobo\Utils\ModuleConfig\ModuleConfig;

class IndexActionListener extends BaseActionListener
{
    /**
     * Include menus identifier
     */
    const FLAG_INCLUDE_MENUS = 'menus';

    /**
     * Include primary key
     */
    const FLAG_INCLUDE_PRIMARY_KEY = 'primary_key';

    /**
     * Property name for menu items
     */
    const MENU_PROPERTY_NAME = '_Menus';

    /**
     * {@inheritDoc}
     */
    public function implementedEvents()
    {
        return [
            (string)EventName::API_INDEX_BEFORE_PAGINATE() => 'beforePaginate',
            (string)EventName::API_INDEX_AFTER_PAGINATE() => 'afterPaginate',
            (string)EventName::API_INDEX_BEFORE_RENDER() => 'beforeRender'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function beforePaginate(Event $event, Query $query)
    {
        $table = $event->subject()->{$event->subject()->name};
        $request = $event->subject()->request;

        if (!in_array($request->query('format'), [static::FORMAT_PRETTY, static::FORMAT_DATATABLES])) {
            $query->contain($this->_getFileAssociations($table));
        }
        $this->_filterByConditions($query, $event);

        $select = $this->getSelectClause($event);
        if (!empty($select)) {
            $query->select($select, true);
        }

        $order = $this->_handleDtSorting($event);
        $query->order($order);
    }

    /**
     * {@inheritDoc}
     */
    public function afterPaginate(Event $event, ResultSet $entities)
    {
        if ($entities->isEmpty()) {
            return;
        }

        $table = $event->subject()->{$event->subject()->name};
        $request = $event->subject()->request;

        foreach ($entities as $entity) {
            $this->_resourceToString($entity);
        }

        if (in_array($request->query('format'), [static::FORMAT_PRETTY, static::FORMAT_DATATABLES])) {
            $fields = [];
            if (static::FORMAT_DATATABLES === $request->query('format')) {
                $fields = $this->_getActionFields($event->subject()->request);
            }

            foreach ($entities as $entity) {
                $this->_prettify($entity, $table, $fields);
            }
        } else { // @todo temporary functionality, please see _includeFiles() method documentation.
            foreach ($entities as $entity) {
                $this->_restructureFiles($entity, $table);
            }
        }

        if ((bool)$event->subject()->request->query(static::FLAG_INCLUDE_MENUS)) {
            $this->_includeMenus($entities, $event);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beforeRender(Event $event, ResultSet $entities)
    {
        if ($entities->isEmpty()) {
            return;
        }

        $this->_datatablesStructure($entities, $event);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getActionFields(Request $request, $action = null)
    {
        $fields = parent::_getActionFields($request, $action);

        if (empty($fields)) {
            return $fields;
        }

        foreach ($fields as &$field) {
            $field = current((array)$field);
        }

        return $fields;
    }

    /**
     * Method that returns SELECT clause for the Query to only include
     * action fields (as defined in the csv file).
     *
     * @param  \Cake\Event\Event $event The event
     * @return array
     */
    protected function getSelectClause(Event $event)
    {
        if (!in_array($event->subject()->request->query('format'), [static::FORMAT_DATATABLES])) {
            return [];
        }

        $fields = $this->_getActionFields($event->subject()->request);

        if (empty($fields)) {
            return [];
        }

        $primaryKey = $event->subject()->{$event->subject()->name}->primaryKey();
        // always include primary key, useful for menus URLs
        if (!in_array($primaryKey, $fields)) {
            array_push($fields, $primaryKey);
        }

        $table = $event->subject()->{$event->subject()->name};

        $mc = new ModuleConfig(ConfigType::MIGRATION(), $event->subject()->name);
        $config = $mc->parse();

        $migrationFields = json_decode(json_encode($config), true);
        if (empty($migrationFields)) {
            return [];
        }

        $mc = new ModuleConfig(ConfigType::MODULE(), $event->subject()->name);
        $config = $mc->parse();
        $virtualFields = (array)$config->virtualFields;

        $factory = new FieldHandlerFactory();

        $result = [];
        foreach ($fields as $field) {
            // skip non-existing fields
            if (!isset($migrationFields[$field]) && !isset($virtualFields[$field])) {
                continue;
            }

            // convert virtual field
            if (isset($virtualFields[$field])) {
                $result = array_merge($result, $virtualFields[$field]);
                continue;
            }

            $csvField = new CsvField($migrationFields[$field]);
            // convert combined field into relevant db fields
            foreach ($factory->fieldToDb($csvField, $table, $field) as $dbField) {
                $result[] = $dbField->getName();
            }
        }

        $result = array_unique($result);

        return $result;
    }

    /**
     * Handle datatables sorting parameters to match Query order() accepted parameters.
     *
     * @param  \Cake\Event\Event $event The event
     * @return array
     */
    protected function _handleDtSorting(Event $event)
    {
        if (!in_array($event->subject()->request->query('format'), [static::FORMAT_DATATABLES])) {
            return [];
        }

        if (!$event->subject()->request->query('order')) {
            return [];
        }

        $table = $event->subject()->{$event->subject()->name};

        $column = $event->subject()->request->query('order.0.column') ?: 0;

        $direction = $event->subject()->request->query('order.0.dir') ?: 'asc';
        if (!in_array($direction, ['asc', 'desc'])) {
            $direction = 'asc';
        }

        $fields = $this->_getActionFields($event->subject()->request);
        if (empty($fields)) {
            return [];
        }

        // skip if sort column is not found in the action fields
        if (!isset($fields[$column])) {
            return [];
        }

        $column = $fields[$column];

        $schema = $table->getSchema();
        // virtual or combined field
        if (!in_array($column, $schema->columns())) {
            $mc = new ModuleConfig(ConfigType::MODULE(), $event->subject()->name);
            $config = $mc->parse();
            $virtualFields = (array)$config->virtualFields;
            $virtual = false;
            // handle virtual field
            if (isset($virtualFields[$column])) {
                $virtual = true;
                $column = $virtualFields[$column];
            }

            // handle combined field
            if (!$virtual) {
                $factory = new FieldHandlerFactory();
                $mc = new ModuleConfig(ConfigType::MIGRATION(), $event->subject()->name);
                $config = $mc->parse();
                $csvField = new CsvField((array)$config->{$column});

                $combined = [];
                foreach ($factory->fieldToDb($csvField, $table, $column) as $dbField) {
                    $combined[] = $dbField->getName();
                }

                $column = $combined;
            }
        }

        $columns = (array)$column;

        // prefix table name
        foreach ($columns as &$v) {
            $v = $table->aliasField($v);
        }

        // add sort direction to all columns
        $conditions = array_fill_keys($columns, $direction);

        return $conditions;
    }

    /**
     * Method that retrieves and attaches menu elements to API response.
     *
     * @param  \Cake\ORM\ResultSet $entities Entities
     * @param  \Cake\Event\Event   $event    Event instance
     * @return void
     */
    protected function _includeMenus(ResultSet $entities, Event $event)
    {
        $appView = new View();
        $plugin = $event->subject()->request->plugin;
        $controller = $event->subject()->request->controller;
        $displayField = $event->subject()->{$event->subject()->name}->displayField();

        foreach ($entities as $entity) {
            $entity->{static::MENU_PROPERTY_NAME} = $appView->element('CsvMigrations.Menu/index_actions', [
                'plugin' => $event->subject()->request->plugin,
                'controller' => $event->subject()->request->controller,
                'displayField' => $displayField,
                'entity' => $entity,
                'user' => $event->subject()->Auth->user()
            ]);
        }
    }

    /**
     * Method that re-formats entities to Datatables supported format.
     *
     * @param  \Cake\ORM\ResultSet $entities Entities
     * @param  \Cake\Event\Event   $event    Event instance
     * @return void
     */
    protected function _datatablesStructure(ResultSet $entities, Event $event)
    {
        if (static::FORMAT_DATATABLES !== $event->subject()->request->query('format')) {
            return;
        }

        $fields = $this->_getActionFields($event->subject()->request);

        if (empty($fields)) {
            return;
        }

        // include primary key to the response fields
        if ((bool)$event->subject()->request->query(static::FLAG_INCLUDE_PRIMARY_KEY)) {
            array_unshift($fields, $event->subject()->{$event->subject()->name}->primaryKey());
        }

        // include actions menu to the response fields
        if ((bool)$event->subject()->request->query(static::FLAG_INCLUDE_MENUS)) {
            $fields[] = static::MENU_PROPERTY_NAME;
        }

        foreach ($entities as $entity) {
            $savedEntity = $entity->toArray();
            // remove non-action fields property
            foreach (array_diff($entity->visibleProperties(), $fields) as $field) {
                $entity->unsetProperty($field);
            }

            // set fields with numeric index
            foreach ($fields as $k => $v) {
                $entity->{$k} = $savedEntity[$v];
                $entity->unsetProperty($v);
            }
        }
    }

    /**
     * Method that filters ORM records by provided conditions.
     *
     * @param  \Cake\ORM\Query   $query Query object
     * @param  \Cake\Event\Event $event The event
     * @return void
     */
    protected function _filterByConditions(Query $query, Event $event)
    {
        if (empty($event->subject()->request->query('conditions'))) {
            return;
        }

        $conditions = [];
        $tableName = $event->subject()->name;
        foreach ($event->subject()->request->query('conditions') as $k => $v) {
            if (false === strpos($k, '.')) {
                $k = $tableName . '.' . $k;
            }

            $conditions[$k] = $v;
        };

        $query->applyOptions(['conditions' => $conditions]);
    }
}

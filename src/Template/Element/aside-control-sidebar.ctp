<?php
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use CsvMigrations\FieldHandlers\FieldHandlerFactory;

$fhf = new FieldHandlerFactory();
$table = TableRegistry::get('LogAudit');
$history = $table
    ->find('all')
    ->limit(10)
    ->where(['LogAudit.meta LIKE' => '%"user":"' . $user['id'] . '"%'])
    ->distinct(['LogAudit.primary_key'])
    ->order(['timestamp' => 'DESC'])
    ->all();
$hasActivity = false;
?>
<aside class="control-sidebar control-sidebar-dark">
    <!-- Create the tabs -->
    <ul class="nav nav-tabs nav-justified control-sidebar-tabs">
        <li class="active"><a href="#control-sidebar-home-tab" data-toggle="tab"><i class="fa fa-clock-o"></i></a></li>
        <li><a href="#control-sidebar-settings-tab" data-toggle="tab"><i class="fa fa-gears"></i></a></li>
    </ul>

    <!-- Tab panes -->
    <div class="tab-content">
        <!-- Home tab content -->
        <div class="tab-pane active" id="control-sidebar-home-tab">
            <h3 class="control-sidebar-heading">Recent Activity</h3>
            <ul class="control-sidebar-menu">
                <?php foreach ($history as $item) : ?>
                <?php
                $table = TableRegistry::get(Inflector::camelize($item['source']));

                $moduleAlias = $item['source'];
                if (method_exists($table, 'moduleAlias') && is_callable([$table, 'moduleAlias'])) {
                    $moduleAlias = $table->moduleAlias();
                }
                $moduleAlias = Inflector::humanize($moduleAlias);
                try {
                    $entity = $table->get($item['primary_key']);
                } catch (Exception $e) {
                    continue;
                }
                $label = $fhf->renderValue(
                    $table,
                    $table->displayField(),
                    $entity->{$table->displayField()},
                    [ 'renderAs' => 'plain']
                );
                $hasActivity = true;
                ?>
                <li>
                    <a href="<?= $this->Url->build(['plugin' => null, 'controller' => $table->alias(), 'action' => 'view', $item['primary_key']]) ?>">
                        <i class="menu-icon fa fa-<?= method_exists($table, 'icon') ? $table->icon() : Configure::read('Menu.default_menu_item_icon'); ?> bg-primary" title="<?= $moduleAlias ?>"></i>
                        <div class="menu-info">
                            <h4 class="control-sidebar-subheading"><?= $label ?></h4>
                            <p><?= $item['timestamp']->i18nFormat('yyyy-MM-dd HH:mm') ?></p>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if (!$hasActivity) : ?>
                <li>
                    <a href="#">
                        <i class="menu-icon fa fa-ban bg-primary"></i>
                        <div class="menu-info">
                            <h4 class="control-sidebar-subheading"><?= __('No recent activity') ?></h4>
                            <p><?= date('Y-m-d H:i:s') ?></p>
                        </div>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <!-- /.tab-pane -->

        <!-- Settings tab content -->
        <div class="tab-pane" id="control-sidebar-settings-tab">
            <h3 class="control-sidebar-heading">Settings</h3>
            <?= $this->cell('Menu.Menu', ['name' => MENU_ADMIN, 'user' => $user]) ?>
        </div>
        <!-- /.tab-pane -->
    </div>
</aside>

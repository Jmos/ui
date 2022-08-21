<?php

declare(strict_types=1);

namespace Atk4\Ui\Demos;

use Atk4\Ui\Crud;
use Atk4\Ui\Table;

/** @var \Atk4\Ui\App $app */
require_once __DIR__ . '/../init-app.php';

$model = new Stat($app->db);
$model->getUserAction('add')->system = true;
$model->getUserAction('edit')->system = true;
$model->getUserAction('delete')->system = true;

$grid = Crud::addTo($app, ['paginator' => false]);
$grid->setModel($model);
$grid->addDecorator($model->fieldName()->project_code, [Table\Column\Link::class]);

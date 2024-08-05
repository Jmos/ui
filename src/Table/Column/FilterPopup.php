<?php

declare(strict_types=1);

namespace Atk4\Ui\Table\Column;

use Atk4\Data\Field;
use Atk4\Data\Model;
use Atk4\Ui\Button;
use Atk4\Ui\Form;
use Atk4\Ui\Js\Jquery;
use Atk4\Ui\Js\JsBlock;
use Atk4\Ui\Js\JsReload;
use Atk4\Ui\Popup;
use Atk4\Ui\View;

/**
 * Implement a filterPopup in a table column.
 * The popup contains a form associate to a field type model
 * and use session to store it's data.
 */
class FilterPopup extends Popup
{
    /** @var Form The form associate with this FilterPopup. */
    public $form;

    /** The table field that need filtering. */
    public Field $field;

    /** @var View|null The view associated with this filter popup that needs to be reloaded. */
    public $reload;

    /**
     * The Table Column triggering the popup.
     * This is need to simulate click in order to properly
     * close the popup window on "Clear".
     *
     * @var string
     */
    public $colTrigger;

    #[\Override]
    protected function init(): void
    {
        parent::init();

        $this->setOption('delay', ['hide' => 1500]);
        $this->setHoverable();

        $entity = FilterModel::factoryType($this->getApp(), $this->field)
            ->createEntity();

        $this->form = Form::addTo($this)->addClass('');
        $this->form->buttonSave->addClass('');
        $this->form->addGroup('Where ' . $this->field->getCaption() . ':');

        $this->form->buttonSave->set('Set');

        $this->form->setControlsDisplayRules($entity->getFormDisplayRules());

        // load data associated with this popup
        $filter = $entity->recallData();
        if ($filter !== null) {
            $entity->setMulti($filter);
        }
        $this->form->setModel($entity);

        $this->form->onSubmit(function (Form $form) {
            $form->entity->save();

            return new JsReload($this->reload);
        });

        Button::addTo($this->form, ['Clear', 'class.clear' => true])
            ->on('click', function (Jquery $j) use ($entity) {
                $entity->clearData();

                return new JsBlock([
                    $this->form->js()->form('reset'),
                    new JsReload($this->reload),
                    (new Jquery($this->colTrigger))->click(),
                ]);
            });
    }

    public function isFilterOn(): bool
    {
        return $this->recallData() !== null;
    }

    public function recallData(): ?array
    {
        return $this->form->entity->recallData();
    }

    /**
     * Set filter condition base on the field Type model use in this FilterPopup.
     */
    public function setFilterCondition(Model $tableModel): void
    {
        $this->form->entity->setConditionForModel($tableModel);
    }
}

<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\DataShareAuthoritable;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\ConditionItems\ConditionItemBase;

class CustomViewController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);
        
        $this->setPageInfo(exmtrans("custom_view.header"), exmtrans("custom_view.header"), exmtrans("custom_view.description"), 'fa-th-list');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }
        return parent::index($request, $content);
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomView::class, $id, 'view')) {
            return;
        }

        // check has system permission
        $view = CustomView::getEloquent($id);
        if (!$view->hasEditPermission()) {
            Checker::error();
            return false;
        }
        
        return parent::edit($request, $content, $tableKey, $id);
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }

        if (!is_null($copy_id = $request->get('copy_id'))) {
            return $this->AdminContent($content)->body($this->form(null, $copy_id)->replicate($copy_id, ['view_view_name', 'default_flg', 'view_type', 'view_kind_type']));
        }

        return parent::create($request, $content);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomView);
        $grid->column('table_name', exmtrans("custom_table.table_name"))
            ->displayEscape(function () {
                return $this->custom_table->table_name;
            });
        $grid->column('table_view_name', exmtrans("custom_table.table_view_name"))
            ->displayEscape(function () {
                return $this->custom_table->table_view_name;
            });
        $grid->column('view_view_name', exmtrans("custom_view.view_view_name"))->sortable();
        if ($this->custom_table->hasSystemViewPermission()) {
            $grid->column('view_type', exmtrans("custom_view.view_type"))->sortable()->displayEscape(function ($view_type) {
                return Enums\ViewType::getEnum($view_type)->transKey("custom_view.custom_view_type_options");
            });
        }

        if (!$this->custom_table->hasSystemViewPermission()) {
            $grid->model()->where('view_type', Enums\ViewType::USER);
        }
        
        $grid->column('view_kind_type', exmtrans("custom_view.view_kind_type"))->sortable()->displayEscape(function ($view_kind_type) {
            return ViewKindType::getEnum($view_kind_type)->transKey("custom_view.custom_view_kind_type_options");
        });

        $grid->model()->where('custom_table_id', $this->custom_table->id);
        $custom_table = $this->custom_table;

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table) {
            $table_name = $custom_table->table_name;
            if (boolval($actions->row->hasEditPermission())) {
                if (boolval($actions->row->disabled_delete)) {
                    $actions->disableDelete();
                }
                if (intval($actions->row->view_kind_type) === Enums\ViewKindType::AGGREGATE ||
                    intval($actions->row->view_kind_type) === Enums\ViewKindType::CALENDAR) {
                    $actions->disableEdit();
                    
                    $linker = (new Linker)
                        ->url(admin_urls('view', $table_name, $actions->getKey(), 'edit').'?view_kind_type='.$actions->row->view_kind_type)
                        ->icon('fa-edit')
                        ->tooltip(trans('admin.edit'));
                    $actions->prepend($linker);
                }
            } else {
                $actions->disableEdit();
                $actions->disableDelete();
            }
            // if ($actions->row->disabled_delete) {
            //     $actions->disableDelete();
            // }
            $actions->disableView();

            if (intval($actions->row->view_kind_type) != Enums\ViewKindType::FILTER) {
                $linker = (new Linker)
                ->url($custom_table->getGridUrl(true, ['view' => $actions->row->suuid]))
                ->icon('fa-database')
                ->tooltip(exmtrans('custom_view.view_datalist'));
                $actions->prepend($linker);
            }
            
            $linker = (new Linker)
                ->url(admin_urls('view', $table_name, "create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('custom_view.custom_view_button_label')));
            $actions->prepend($linker);
        });

        $grid->disableCreateButton();
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomViewMenuButton($this->custom_table, null, false));
            $tools->append(new Tools\CustomTableMenuButton('view', $this->custom_table));
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null, $copy_id = null)
    {
        // get request
        $request = Request::capture();
        $copy_custom_view = CustomView::getEloquent($copy_id);
        
        $form = new Form(new CustomView);

        if (!isset($id)) {
            $id = $form->model()->id;
        }
        if (isset($id)) {
            $model = CustomView::getEloquent($id);
        }
        if (isset($model)) {
            $suuid = $model->suuid;
            $view_type = $model->view_type;
            $view_kind_type = $model->view_kind_type;
        } else {
            $suuid = null;
            $view_type = null;
            $view_kind_type = null;
        }
        
        // get view_kind_type
        if (!is_null($request->input('view_kind_type'))) {
            $view_kind_type = $request->input('view_kind_type');
        } elseif (!is_null($request->query('view_kind_type'))) {
            $view_kind_type =  $request->query('view_kind_type');
        } elseif (isset($copy_custom_view)) {
            $view_kind_type =  array_get($copy_custom_view, 'view_kind_type');
            // if all data, change default
            if ($view_kind_type == ViewKindType::ALLDATA) {
                $view_kind_type = ViewKindType::DEFAULT;
            }
        } elseif (is_null($view_kind_type)) {
            $view_kind_type = ViewKindType::DEFAULT;
        }
        
        // get from_data
        $from_data = false;
        if ($request->has('from_data')) {
            $from_data = boolval($request->get('from_data'));
        }

        $form->hidden('custom_table_id')->default($this->custom_table->id);

        $form->hidden('view_kind_type')->default($view_kind_type);
        $form->hidden('from_data')->default($from_data);
        $form->ignore('from_data');
        
        $form->display('custom_table.table_name', exmtrans("custom_table.table_name"))->default($this->custom_table->table_name);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->default($this->custom_table->table_view_name);
        $form->display('view_kind_type', exmtrans("custom_view.view_kind_type"))
            ->with(function ($value) use ($view_kind_type) {
                return ViewKindType::getEnum($value?? $view_kind_type)->transKey("custom_view.custom_view_kind_type_options");
            });

        $form->text('view_view_name', exmtrans("custom_view.view_view_name"))->required()->rules("max:40");
        if (!System::userview_available() || intval($view_kind_type) == Enums\ViewKindType::FILTER) {
            $form->hidden('view_type')->default(Enums\ViewType::SYSTEM);
        } else {
            // select view type
            if ($this->custom_table->hasSystemViewPermission() && (is_null($view_type) || $view_type == Enums\ViewType::USER)) {
                $form->select('view_type', exmtrans('custom_view.view_type'))
                    ->default(Enums\ViewType::SYSTEM)
                    ->config('allowClear', false)
                    ->help(exmtrans('custom_view.help.custom_view_type'))
                    ->options(Enums\ViewType::transKeyArray('custom_view.custom_view_type_options'));
            } else {
                $form->hidden('view_type')->default(Enums\ViewType::USER);
            }
        }
        
        // remove default
        // if (intval($view_kind_type) != Enums\ViewKindType::FILTER) {
        //     $form->switchbool('default_flg', exmtrans("common.default"))->default(false);
        // }
        
        // set column' s form
        $classname = ViewKindType::getGridItemClassName($view_kind_type);
        $classname::setViewForm($view_kind_type, $form, $this->custom_table);

        $custom_table = $this->custom_table;

        // set view condition
        $this->setViewConditionFields($form, $model, $view_kind_type);

        // check filters and sorts count before save
        $form->saving(function (Form $form) {
            if (!is_null($form->custom_view_filters)) {
                $cnt = collect($form->custom_view_filters)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                })->count();
                if ($cnt > 5) {
                    admin_toastr(exmtrans('custom_view.message.over_filters_max'), 'error');
                    return back()->withInput();
                }
            }
            if (!is_null($form->custom_view_sorts)) {
                $cnt = collect($form->custom_view_sorts)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                })->count();
                if ($cnt > 5) {
                    admin_toastr(exmtrans('custom_view.message.over_sorts_max'), 'error');
                    return back()->withInput();
                }
            }
        });

        $form->saved(function (Form $form) use ($from_data, $custom_table) {
            if (boolval($from_data) && $form->model()->view_kind_type != Enums\ViewKindType::FILTER) {
                // get view suuid
                $suuid = $form->model()->suuid;
                
                admin_toastr(trans('admin.save_succeeded'));

                return redirect($custom_table->getGridUrl(true, ['view' => $suuid]));
            }
        });

        $form->tools(function (Form\Tools $tools) use ($id, $suuid, $custom_table, $view_type) {
            $tools->add((new Tools\CustomTableMenuButton('view', $custom_table)));

            if ($view_type == Enums\ViewType::USER) {
                $tools->append(new Tools\ShareButton(
                    $id,
                    admin_urls(Enums\ShareTargetType::VIEW()->lowerkey(), $custom_table->table_name, $id, "shareClick")
                ));
            }
    
            if (isset($suuid)) {
                $tools->append(view('exment::tools.button', [
                    'href' => $custom_table->getGridUrl(true, ['view' => $suuid]),
                    'label' => exmtrans('custom_view.view_datalist'),
                    'icon' => 'fa-database',
                    'btn_class' => 'btn-purple',
                ]));
            }
        });
        
        return $form;
    }


    protected function setViewConditionFields($form, $model, $view_kind_type){
        // set view condition
        // if (!System::systemview_condition_available()) {
        //     return;
        // }
        if(!$this->custom_table->hasSystemViewPermission()){
            return;
        }
        if(!isset($model)){
            return;
        }


        // filter setting
        $hasManyTable = new Tools\ConditionHasManyTable($form, [
            'ajax' => admin_urls('webapi', $this->custom_table->table_name, 'filter-value'),
            'name' => "custom_view_conditions",
            'linkage' => json_encode(['condition_key' => admin_urls('webapi', $this->custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $this->custom_table->getColumnsSelectOptions([
                'include_condition' => true,
                'include_system' => false,
                'include_column' => false,
                'ignore_attachment' => true,
            ]),
            'custom_table' => $this->custom_table,
            'filterKind' => Enums\FilterKind::VIEW,
        ]);
        $hasManyTable->render();


    }

    /**
     * get filter condition
     */
    public function getSummaryCondition(Request $request)
    {
        $view_column_target = $request->get('q');
        if (!isset($view_column_target)) {
            return [];
        }

        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
        if (!isset($columnItem)) {
            return [];
        }

        // only numeric
        if ($columnItem->isNumeric()) {
            $options = SummaryCondition::getOptions();
        } else {
            $options = SummaryCondition::getOptions(['numeric' => false]);
        }
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.summary_condition_options.'.array_get($array, 'name'))];
        });
    }

    public function getGroupCondition(Request $request)
    {
        return $this->_getGroupCondition($request->get('q'));
    }

    /**
     * get group condition
     */
    protected function _getGroupCondition($view_column_target = null)
    {
        if (!isset($view_column_target)) {
            return [];
        }

        // get column item from $view_column_target
        $columnItem = CustomViewColumn::getColumnItem($view_column_target);
        if (!isset($columnItem)) {
            return [];
        }

        if (!$columnItem->isDate()) {
            return [];
        }

        // if date, return option
        $options = GroupCondition::getOptions();
        return collect($options)->map(function ($array) {
            return ['id' => array_get($array, 'id'), 'text' => exmtrans('custom_view.group_condition_options.'.array_get($array, 'name'))];
        });
    }

    /**
     * validation table
     * @param mixed $table id or customtable
     */
    protected function validateTable($table, $role_name)
    {
        if (!$this->custom_table->hasViewPermission()) {
            Checker::error();
            return false;
        }
        return parent::validateTable($table, $role_name);
    }
    
    /**
     * get filter condition
     */
    public function getFilterCondition(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('q'));
        if (!isset($item)) {
            return [];
        }
        return $item->getFilterCondition();
    }
    
    /**
     * get filter condition
     */
    public function getFilterValue(Request $request)
    {
        $item = $this->getConditionItem($request, $request->get('target'));
        if (!isset($item)) {
            return [];
        }
        return $item->getFilterValue($request->get('cond_key'), $request->get('cond_name'));
    }

    protected function getConditionItem(Request $request, $target)
    {
        $item = ConditionItemBase::getItem($this->custom_table, $target);
        if (!isset($item)) {
            return null;
        }

        $elementName = str_replace('view_filter_condition', 'view_filter_condition_value', $request->get('cond_name'));
        $label = exmtrans('condition.condition_value');
        $item->setElement($elementName, 'view_filter_condition_value', $label);

        $item->filterKind(Enums\FilterKind::VIEW);

        return $item;
    }

    /**
     * create share form
     */
    public function shareClick(Request $request, $tableKey, $id)
    {
        // get custom view
        $custom_view = CustomView::getEloquent($id);

        $form = DataShareAuthoritable::getShareDialogForm($custom_view, $tableKey);
        
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('common.shared')
        ]);
    }

    /**
     * set share users organizations
     */
    public function sendShares(Request $request, $tableKey, $id)
    {
        // get custom view
        $custom_view = CustomView::getEloquent($id);
        return DataShareAuthoritable::saveShareDialogForm($custom_view);
    }
}

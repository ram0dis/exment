<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Carbon\Carbon;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File as ExmentFile;

class DocumentProvider extends FileColumnProvider
{
    /**
     * validate imported all data.
     * @param mixed $dataObjects
     * @return array
     */
    public function validateImportData($dataObjects)
    {
        $error_data = [];
        $success_data = [];
        foreach ($dataObjects as $line_no => $value) {
            $check = $this->validateDataRow($line_no, $value, null, $dataObjects);
            if ($check === true) {
                $success_data[] = $value;
            } else {
                $error_data = array_merge($error_data, $check);
            }
        }

        return [$success_data, $error_data];
    }
    
    /**
     * validate data row
     * @param int $line_no
     * @param array $dataAndModel
     * @param array $validate_columns(not use)
     * @param array $dataObjects
     * @return array
     */
    public function validateDataRow($line_no, $dataAndModel, $validate_columns, $dataObjects)
    {
        return $this->_validateDataRow($line_no, $dataAndModel, $validate_columns, false);
    }


    /**
     * import data
     */
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');
        $fileFullPath = array_get($dataAndModel, 'fileFullPath');
        $displayFileName = array_get($data, 'display_file_name') ?? array_get($data, 'file_name');

        // get file
        $file = \File::get($fileFullPath);

        // save file info
        $exmentfile = ExmentFile::storeAs($file, $this->custom_table->table_name, $displayFileName)
            ->saveCustomValue($model->id, null, $this->custom_table);
        
        // save document model
        $exmentfile->saveDocumentModel($model, $displayFileName);
                
        return $model;
    }
}

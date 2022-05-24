<?php
// Set the namespace defined in your config file
namespace ORCA\AutocompleteOff;

// The next 2 lines should always be included and be the same in every module
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Exception;

require_once 'traits/REDCapUtils.php';

/**
 * Class AutocompleteOff
 * @package ORCA\AutocompleteOff
 */
class AutocompleteOff extends AbstractExternalModule {
    use \ORCA\AutocompleteOff\REDCapUtils;

    public $_module_path = null;
    public function __construct()
    {
        parent::__construct();
        $this->_module_path = $this->getModulePath();
    }

    /**
     * Hook function to execute on every data entry form
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $repeat_instance
     */
    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        try {
            $this->autoCompleteOff($instrument);
        }
        catch (Exception $ex) {
            $this->alert($ex->getMessage(), "danger", $this->PREFIX);
        }
    }


    /**
     * Hook function to execute on every survey form
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $repeat_instance
     */
    function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        try {
            $this->autoCompleteOff($instrument);
        }
        catch (Exception $ex) {
            $this->alert($ex->getMessage(), "danger", $this->PREFIX);
        }
    }

    function alert($contents, $type = "info", $lead = "") {
        $valid_types = [ "danger", "warning", "info" ];
        if (!in_array($type, $valid_types)) {
            $type = "info";
        }
        if (!empty($lead)) {
            $lead = "<b>$lead:</b> ";
        }
        echo "<div class='alert alert-$type mb-4'>" . $lead . $contents . "</div>";
    }

    function autoCompleteOff($instrument){
        $autocomplete_off_fields = [];
        $field_selectors = [];
        foreach ($this->getSubSettings("autocomplete_off_fields") as $autocomplete_off_field) {
            $autocomplete_off_fields = array_filter($autocomplete_off_field["autocomplete_off_field_name"], function($a) {return $a !== null;});
        }
        if(!empty($autocomplete_off_fields)) {
            //filter fields on that instrument
            $instrument_fields = \REDCap::getFieldNames($instrument);
            $autocomplete_off_instrument_fields = array_intersect($autocomplete_off_fields,$instrument_fields);
            // prepare field selectors
            foreach ($autocomplete_off_instrument_fields as $i => $field) {
                $field_selectors[] = "input[name='$field']";
            }
            ?>
            <script type='text/javascript'>
                $(function () {
                    $("<?=implode(",", $field_selectors)?>").prop("autocomplete", "off");
                });
            </script>
            <?php
        }
    }
}
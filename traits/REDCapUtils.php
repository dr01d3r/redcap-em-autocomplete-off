<?php

namespace ORCA\AutocompleteOff;

use Exception;

trait REDCapUtils {

    private $_dataDictionary = [];
    private $_dictionaryValues = [];
    private $_metadata = [];
    private $timers = [];
    public $aggregate_timers = [];
    public $_HTML;

    public function getMetadata() {
        if (empty($this->_metadata)) {
            global $Proj;
            $this->_metadata = [
                "fields" => [],
                "forms" => [],
                "form_statuses" => [
                    0 => "Incomplete",
                    1 => "Unverified",
                    2 => "Complete"
                ],
                "date_field_formats" => [
                    "date_mdy" => "m/d/Y",
                    "datetime_mdy" => "m/d/Y G:i"
                ],
                "unstructured_field_types" => [
                    "text",
                    "textarea"
                ],
                "custom_dictionary_values" => [
                    "yesno" => [
                        "1" => "Yes",
                        "0" => "No"
                    ],
                    "truefalse" => [
                        "1" => "True",
                        "0" => "False"
                    ]
                ]
            ];

            foreach ($Proj->forms as $form_name => $form_data) {
                $this->_metadata["forms"][$form_name] = [
                    "event_id" => null,
                    "repeating" => false
                ];
                foreach ($form_data["fields"]  as $field_name => $field_label) {
                    $this->_metadata["fields"][$field_name] = [
                        "form" => $form_name,
                        "label" => $Proj->metadata[$field_name]["element_label"],
                        "required" => $Proj->metadata[$field_name]["field_req"]
                    ];
                }
            }
            foreach ($Proj->eventsForms as $event_id => $event_forms) {
                foreach ($event_forms as $form_index => $form_name) {
                    $this->_metadata["forms"][$form_name]["event_id"] = $event_id;
                }
            }
            if ($Proj->hasRepeatingForms()) {
                foreach ($Proj->getRepeatingFormsEvents() as $event_id => $event_forms) {
                    foreach ($event_forms as $form_name => $value) {
                        $this->_metadata["forms"][$form_name]["repeating"] = true;
                    }
                }
            }
        }
        return $this->_metadata;
    }

    /**
     * Pulled from AbstractExternalModule
     * For broad REDCap version compatibility
     * @return string|null
     */
    public function getPID() {
        $pid = @$_GET['pid'];

        // Require only digits to prevent sql injection.
        if (ctype_digit($pid)) {
            return $pid;
        } else {
            return null;
        }
    }

    /**
     * Pulled from AbstractExternalModule
     * For broad REDCap version compatibility
     * @return string|null
     */
    public function getID()
    {
        $id = @$_GET['id'];

        // Require only digits to prevent sql injection.
        if (ctype_digit($id)) {
            return $id;
        } else {
            return null;
        }
    }

    public function getDataDictionary($format = 'array') {
        if (!array_key_exists($format, $this->_dataDictionary)) {
            $this->_dataDictionary[$format] = \REDCap::getDataDictionary($format);
        }
        $dictionaryToReturn = $this->_dataDictionary[$format];
        return $dictionaryToReturn;
    }

    public function getFieldValidationTypeFor($field_name) {
        $result = $this->getDataDictionary()[$field_name]['text_validation_type_or_show_slider_number'];
        if (empty($result)) {
            return null;
        }
        return $result;
    }

    public function getDictionaryLabelFor($key) {
        $label = $this->getDataDictionary()[$key]['field_label'];
        if (empty($label)) {
            return $key;
        }
        return $label;
    }

    public function getDictionaryValuesFor($key) {
        // TODO consider using $this->getChoiceLabels()
        if (!array_key_exists($key, $this->_dictionaryValues)) {
            $this->_dictionaryValues[$key] =
                $this->flatten_type_values($this->getDataDictionary()[$key]['select_choices_or_calculations']);
        }
        return $this->_dictionaryValues[$key];
    }

    public function sortArrayByDate(&$array, $dateKey, $fallbackKey, $sortDesc = false) {
        // this sorts in ASCENDING order
        uasort($array, function ($a, $b) use ($dateKey, $fallbackKey, $sortDesc) {
            $retval = 0;
            $ref_a = strtotime($a[$dateKey]);
            $ref_b = strtotime($b[$dateKey]);

            if ($ref_a === $ref_b) {
                if (isset($a[$fallbackKey]) && isset($b[$fallbackKey])) {
                    $retval = $a[$fallbackKey] > $b[$fallbackKey] ? 1 : -1;
                }
            } else {
                $retval = $ref_a > $ref_b ? 1 : -1;
            }

            // invert the sort (DESCENDING) if necessary
            if ($sortDesc === true) {
                $retval = $retval * -1;
            }

            return $retval;
        });
        return true;
    }

    /**
     * Returns a formatted date string if the provided date is valid, otherwise returns FALSE
     * @param mixed $date
     * @param string $format
     * @return false|string
     */
    public function getFormattedDateString($date, $format) {
        if (empty($format)) {
            return $date;
        } else if ($date instanceof \DateTime) {
            return date_format($date, $format);
        } else {
            if (!empty($date)) {
                $timestamp = strtotime($date);
                if ($timestamp !== false) {
                    return date($format, $timestamp);
                }
            }
        }
        return false;
    }

    public function comma_delim_to_key_value_array($value) {
        $arr = explode(', ', trim($value));
        $sliced = array_slice($arr, 1, count($arr) - 1, true);
        return array($arr[0] => implode(', ', $sliced));
    }

    public function array_flatten($array) {
        $return = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $return = $return + $this->array_flatten($value);
            } else {
                $return[$key] = $value;
            }
        }
        return $return;
    }

    public function flatten_type_values($value) {
        $split = explode('|', $value);
        $mapped = array_map(function ($value) {
            return $this->comma_delim_to_key_value_array($value);
        }, $split);
        $result = $this->array_flatten($mapped);
        return $result;
    }

    public function getFieldValue($field_name, $record, $use_raw_value = false) {
        global $Proj;

        $field_result = [];

        if (!isset($this->getMetadata()["fields"][$field_name])) {
            return $field_result;
        }
        // prep some form info
        $field_form_name = $this->getMetadata()["fields"][$field_name]["form"];
        $field_form_event_id = $this->getMetadata()["forms"][$field_form_name]["event_id"];

        // initialize some helper variables/arrays
        $field_type = $Proj->metadata[$field_name]["element_type"];
        $field_value = null;
        $form_values = [];

        // set the form_values array with the data we want to look at
        if ($this->getMetadata()["forms"][$field_form_name]["repeating"]) {
            $form_values = end($record["repeat_instances"][$field_form_event_id][$field_form_name]);
            $field_result["instance"] = key($record["repeat_instances"][$field_form_event_id][$field_form_name]);
        } else {
            $form_values = $record[$field_form_event_id];
        }

        // set the raw value of the field
        $field_value = $form_values[$field_name];

        if ($field_name === $Proj->table_pk) {
            $parts = explode("-", $field_value);
            if (count($parts) > 1) {
                $field_result["__SORT__"] = implode(".", [$parts[0], str_pad($parts[1], 10, "0", STR_PAD_LEFT)]);
            } else {
                $field_result["__SORT__"] = $field_value;
            }
        }

        if ($Proj->isFormStatus($field_name)) {
            // special value handling for form statuses
            $field_value = $this->getMetadata()["form_statuses"][$field_value];
        } else if (!in_array($field_type, $this->getMetadata()["unstructured_field_types"])) {
            switch ($field_type) {
                case "select":
                case "radio":
                    if ($use_raw_value != true) {
                        $field_value = $this->getDictionaryValuesFor($field_name)[$field_value];
                    }
                    break;
                case "checkbox":
                    $temp_field_array = [];
                    $field_value_dd = $this->getDictionaryValuesFor($field_name);
                    foreach ($field_value as $field_value_key => $field_value_value) {
                        if ($field_value_value === "1") {
                            $temp_field_array[$field_value_key] = $field_value_dd[$field_value_key];
                        }
                    }
                    $field_value = $temp_field_array;
                    break;
                case "yesno":
                case "truefalse":
                    $field_value = $this->getMetadata()["custom_dictionary_values"][$Proj->metadata[$field_name]["element_type"]][$field_value];
                    break;
                case "sql":
                    if (isset($this->getMetadata()["custom_dictionary_values"][$field_name][$field_value])) {
                        $field_value = $this->getMetadata()["custom_dictionary_values"][$field_name][$field_value];
                    } else if ($field_value !== null && $field_value != '') {
                        // we don't want to show the raw value if a match is not found
                        $field_value = "";
                    }
                    break;
                default: break;
            }
        }

        $element_validation_type = $Proj->metadata[$field_name]["element_validation_type"];
        // update field value if this is a known date format
        if (array_key_exists($element_validation_type, $this->getMetadata()["date_field_formats"]) && !empty($field_value)) {
            $field_result["__SORT__"] = strtotime($field_value);
            $field_value = date_format(date_create($field_value),$this->getMetadata()["date_field_formats"][$element_validation_type]);
        }
        $field_result["value"] = $field_value;

        return $field_result;
    }

    public function preout($content) {
        if (is_array($content) || is_object($content)) {
            echo "<pre>" . print_r($content, true) . "</pre>";
        } else {
            echo "<pre>$content</pre>";
        }
    }

    public function addTime($key = null) {
        if ($key == null) {
            $key = "STEP " . count($this->timers);
        }
        $this->timers[] = [
            "label" => $key,
            "value" => microtime(true)
        ];
    }

    public function outputTimerInfo($showAll = false, $return = false) {
        $initTime = null;
        $preTime = null;
        $curTime = null;
        $output = [];
        foreach ($this->timers as $index => $timeInfo) {
            $curTime = $timeInfo;
            if ($preTime == null) {
                $initTime = $timeInfo;
            } else {
                $calcTime = round($curTime["value"] - $preTime["value"], 4);
                if ($showAll) {
                    if ($return === true) {
                        $output[] = "{$timeInfo["label"]}: {$calcTime}";
                    } else {
                        echo "<p><i>{$timeInfo["label"]}: {$calcTime}</i></p>";
                    }
                }
            }
            $preTime = $curTime;
        }
        $calcTime = round($curTime["value"] - $initTime["value"], 4);
        if ($return === true) {
            $output[] = "Total Processing Time: {$calcTime} seconds";
            return $output;
        } else {
            echo "<p><i>Total Processing Time: {$calcTime} seconds</i></p>";
        }
    }

    public function getCSV($file) {
        $lines = [];
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                $lines[] = $data;
            }
            fclose($handle);
        }
        return $lines;
    }

    public function getFileContents($file) {
        $lines = [];
        $handle = @fopen($file, "r");
        if ($handle) {
            while (($buffer = fgets($handle)) !== false) {
                $lines[] = $buffer;
            }
            if (!feof($handle)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($handle);
        }
        return $lines;
    }

    public function validatePath($path) {
        $path2 = str_replace("\\", "/", $path);
        return [
            "original_path" => $path,
            "normalized_path" => $path2,
            "is_dir" => is_dir($path2),
            "is_file" => is_file($path2),
            "is_writeable" => is_writeable($path2)
        ];
    }

    /**
     * Adds the HTML/script tag for the debug button to the page in the correct state,
     * if the current user has permission to see it at all.
     */
    public function _getDebugButton()
    {

        $url = $this->getUrl("toggle_debug.php");
        if ($this->_userIsDebugger()) {
            if ($this->_isDebugging()) {
                $btn_debug = "<a href='$url' class='btn btn-sm btn-success d-print-none' style='padding: 2px 8px; margin-left: 5px;'><i class='fas fa-bug' aria-hidden='true'></i>&nbsp;debug: ON</a>";
            } else {
                $btn_debug = "<a href='$url' class='btn btn-sm btn-danger d-print-none' style='padding: 2px 8px; margin-left: 5px;'><i class='fas fa-bug' aria-hidden='true'></i>&nbsp;debug: OFF</a>";
            }

            $this->_addToHtml("<script type=\"text/javascript\">
                jQuery(\"#subheaderDiv2\").append(\"$btn_debug\");
            </script>");
            $this->_outputHtml();
        }
    }
    protected function _userIsDebugger():bool{
        $isDebugger = false;
        $debuggers = ["roushj","deeringl","kadolphc"];
        if(in_array(USERID, $debuggers)){
            $isDebugger = true;
        }
        return $isDebugger;
    }

    public function _isDebugging() {
        $debuggingButtonEnabled  = false;

        if(array_key_exists("pid{$this->getPid()}_DEBUG", $_SESSION) && (bool)$_SESSION["pid{$this->getPid()}_DEBUG"] === true){
            $debuggingButtonEnabled = true;
        }
        return $debuggingButtonEnabled ;
    }
    /**
     * Used to add HTML directly to the output of the page being generated
     * NOTE: Use only when you *must*; try a Smarty template instead (security, readability, etc)
     *
     * @param string $html - The
     */
    protected function _addToHtml(string $html)
    {
        $this->_HTML .= $html;
    }
    protected function _outputHtml()
    {
        echo $this->_HTML;
    }
}
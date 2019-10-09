<?php
/*-------------------------------------------------------+
| Ilja's Input Validation Extension                      |
| Amnesty International Vlaanderen                       |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_I3val_ExtensionUtil as E;

class CRM_I3val_Logic {
  /**
   * Create an I3Val Update Request Activity with the given data
   *
   * @param $entity   int    the contact to be updated
   * @param $params   array  the new values
   */
  public static function createEntityUpdateRequest($entity, $params) {
    CRM_I3val_Session::log("PROCESS {$entity} update request: " . json_encode($params));
    $config = CRM_I3val_Configuration::getConfiguration();

    // input sanitation
    $config->sanitiseInput($params);

    // get handlers
    if (!empty($params['activity_type_id'])) {
      $activity_type_id = $params['activity_type_id'];
      $handlers = $config->getHandlersForActivityType($activity_type_id);
    } else {
      $activity_type_id = $config->getDefaultActivityTypeForEntity($entity);
      $handlers = $config->getHandlersForEntity($entity);
    }

    $activity_data = array();
    foreach ($handlers as $handler) {
      $handler->generateDiffData($entity, $params, $activity_data);
    }

    // if no vital data was created, there is nothing to do...
    $ignoreable_attributes = array('target_id', 'activity_type_id'); // TODO: more?
    $vital_attributes_present = FALSE;
    foreach ($activity_data as $key => $value) {
      if (!in_array($key, $ignoreable_attributes)) {
        $vital_attributes_present = TRUE;
        break;
      }
    }


    if ($vital_attributes_present) {
      // add basic activity params
      self::addActivityParams($params, $activity_data);

      // add specific activity params
      $activity_data['subject'] = E::ts("%1 Update Request", array(1 => $entity));
      $activity_data['activity_type_id'] = $activity_type_id;

      // create activity, reload and return
      return self::createActivity($activity_data);
    } else {
      return NULL;
    }
  }

  /**
   * Create a new activity, along with the custom fields:
   *
   * @param $activity_data  array activity data
   * @return array created activity data
=   */
  protected static function createActivity($activity_data) {
    //CRM_I3val_Session::log('ACTIVIY ' . json_encode($activity_data));

    // first: resolve the (remaining) parameters to the custom_xx notation
    CRM_I3val_CustomData::resolveCustomFields($activity_data);

    // separate out the parameters
    $activity_base_data = [];
    $activity_custom_data = [];
    foreach ($activity_data as $field_name => $field_value) {
      if (preg_match("/^custom_[0-9]/", $field_name)) {
        $activity_custom_data[$field_name] = $field_value;
      } else {
        $activity_base_data[$field_name] = $field_value;
      }
    }

    // create the activity itself
    $activity = civicrm_api3('Activity', 'create', $activity_base_data);

    // create the custom data
    if (!empty($activity_custom_data)) {
      $activity_custom_data['entity_id'] = $activity['id'];
      civicrm_api3('CustomValue', 'create', $activity_custom_data);
    }

    return civicrm_api3('Activity', 'getsingle', ['id' => $activity['id']]);
  }

  /**
   * Add the generic activity parameters, partly derived from the $params
   *
   * @param $params         array the parameters present
   * @param $activity_data  array the activity parameters will be added to this array
   */
  protected static function addActivityParams($params, &$activity_data, $contact_id = NULL) {
    $activity_data['activity_date_time'] = date('YmdHis'); // NOW
    $activity_data['status_id'] = CRM_Core_OptionGroup::getValue('activity_status', 'Scheduled', 'name');

    if (!empty($params['activity_id'])) {
      $activity_data['parent_id'] = $params['activity_id'];

      $trigger_activity = civicrm_api3('Activity', 'getsingle', array('id' => $params['activity_id']));
      if (!empty($trigger_activity['campaign_id'])) {
        $activity_data['campaign_id'] = $trigger_activity['campaign_id'];
      }
    }

    // assign contacts
    // TODO: make it configurable
    // $activity_data['assignee_id'] = CRM_I3val_Configuration::getAssignee();

    // make sure the source contact is there
    $activity_data['source_contact_id'] = CRM_I3val_Configuration::getCurrentUserID($contact_id);

    // add target contact
    if (!empty($contact_id)) {
      $activity_data['target_id'] = $contact_id;
    }

    // add the note (if submitted)
    if (!empty($params['i3val_note'])) {
      $activity_data['details'] = $params['i3val_note'];
    }

    // add parent activity (if submitted)
    if (!empty($params['i3val_parent_id']) && is_numeric($params['i3val_parent_id'])) {
      $activity_data['parent_id'] = (int) $params['i3val_parent_id'];
    }

    // adjust schedule date
    if (!empty($params['i3val_schedule_date'])) {
      $schedule_date = strtotime($params['i3val_schedule_date']);
      if ($schedule_date) {
        $activity_data['activity_date_time'] = date('YmdHis', $schedule_date);
      }
    }
  }










  /**
   * Inject the JavaScript to adjust the activity view
   * @todo fix
   */
  public static function adjustAcitivityView($activity_id, $activity_type_id) {
    $configuration = CRM_I3val_Configuration::getConfiguration();
    // get the fields for this activity_type_id
    $handlers = $configuration->getHandlersForActivityType($activity_type_id);
    if (empty($handlers)) {
      return;
    }

    // pre-cache data
    $custom_groups = array();
    foreach ($handlers as $handler) {
      $custom_groups[] = $handler->getCustomGroupName();
    }
    CRM_I3val_CustomData::cacheCustomGroups($custom_groups);

    // load data
    $activity = civicrm_api3('Activity', 'getsingle', array('id' => $activity_id));
    $values = array();
    foreach ($handlers as $handler) {
      $custom_group = $handler->getCustomGroupName();
      $field2label  = $handler->getField2Label();
      foreach ($field2label as $field_name => $field_label) {
        $original_data_field  = CRM_I3val_CustomData::getCustomField($custom_group, "{$field_name}_original");
        $submitted_data_field = CRM_I3val_CustomData::getCustomField($custom_group, "{$field_name}_submitted");
        $applied_data_field   = CRM_I3val_CustomData::getCustomField($custom_group, "{$field_name}_applied");
        if (isset($activity["custom_{$original_data_field['id']}"])) {
          // i.e. the value is set
          $values[] = array(
            'title'      => $field_label,
            'field_name' => $field_name,
            'original'   => CRM_Utils_Array::value("custom_{$original_data_field['id']}",  $activity, ''),
            'submitted'  => CRM_Utils_Array::value("custom_{$submitted_data_field['id']}", $activity, ''),
            'applied'    => CRM_Utils_Array::value("custom_{$applied_data_field['id']}",   $activity, ''),
            );
        }
      }
    }

    // render panel
    $smarty = CRM_Core_Smarty::singleton();
    $smarty->assign('i3val_activity', $activity);
    $smarty->assign('i3val_values', $values);
    $smarty->assign('i3val_edit', FALSE);//($activity['status_id'] == 1));
    $panel = array(
      'html'   => $smarty->fetch('CRM/Activity/I3valPanel.tpl'),
      'fields' => $fields);

    $script = file_get_contents(__DIR__ . '/../../js/activity_view_changes.js');
    $script = str_replace('INJECTED_ACTIVITY_ID', $activity_id, $script);
    $script = str_replace('INJECTED_ACTIVITY_TYPE_ID', $activity_type_id, $script);
    $script = str_replace('INJECTED_PANEL', json_encode($panel), $script);

    CRM_Core_Region::instance('page-footer')->add(array('script' => $script));
  }

}

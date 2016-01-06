<?php

require_once 'ukdirectdebitadditional.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function ukdirectdebitadditional_civicrm_config(&$config) {
  _ukdirectdebitadditional_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function ukdirectdebitadditional_civicrm_xmlMenu(&$files) {
  _ukdirectdebitadditional_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function ukdirectdebitadditional_civicrm_install() {
  return _ukdirectdebitadditional_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function ukdirectdebitadditional_civicrm_uninstall() {
  return _ukdirectdebitadditional_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function ukdirectdebitadditional_civicrm_enable() {
  return _ukdirectdebitadditional_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function ukdirectdebitadditional_civicrm_disable() {
  return _ukdirectdebitadditional_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function ukdirectdebitadditional_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ukdirectdebitadditional_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function ukdirectdebitadditional_civicrm_managed(&$entities) {
  return _ukdirectdebitadditional_civix_civicrm_managed($entities);
}

function ukdirectdebitadditional_civicrm_handleSmartDebitMembershipRenewal(&$params) {

  $ts1 = time();
  $ts2 = strtotime ($params['end_date']);

  $year1 = date('Y', $ts1);
  $year2 = date('Y', $ts2);

  $month1 = date('m', $ts1);
  $month2 = date('m', $ts2);

  $diff = (($year2 - $year1) * 12) + ($month2 - $month1);
	
	CRM_Core_Error::debug_var("diff", $diff);
	CRM_Core_Error::debug_var("params", $params);

  if ($diff > 18) {
    unset($params['end_date']);
  }
  // Set the membership as current
  $params['status_id'] = 2;
}

function ukdirectdebitadditional_civicrm_alterSmartDebitContributionParams(&$params) {
  $contactId = $params['contact_id'];
  $receiveDate = $params['receive_date'];

  $contributionId = ukdirectdebitadditional_civicrm_alterSmartDebitContributionParams_get_id_if_first_contribution($params['contribution_recur_id'], $receiveDate);

  if (!empty($contributionId)) {
    unset($params['source']);
    $params['id'] = $contributionId;
    //$action = 'update';
  }
 // $params['custom_268'] = date('dmy', strtotime($params['receive_date'])).'031';
}

function ukdirectdebitadditional_civicrm_alterSmartDebitContributionParams_get_id_if_first_contribution($contriRecurId, $receiveDate) {

  $membershipQuery  = "SELECT `membership_id` FROM `civicrm_contribution_recur` WHERE `id` = %1";
  $membershipId     = CRM_Core_DAO::singleValueQuery($membershipQuery, array( 1 => array( $contriRecurId, 'Int' ) ) );
  //CRM_Core_Error::debug_var("Membership id", $membershipId);
	
	// if $membershipId is empty, check if we can get from civicrm_membership table
	if (empty($membershipId)) {
		$membershipQuery  = "SELECT `id` FROM `civicrm_membership` WHERE `contribution_recur_id` = %1";
		$membershipId     = CRM_Core_DAO::singleValueQuery($membershipQuery, array( 1 => array( $contriRecurId, 'Int' ) ) );
	}

  $contributionId = '';
  if (!empty($membershipId)) {
    $memParams = array(
      'version' => 3,
      'membership_id' => $membershipId,
    );
    $memResult = civicrm_api('MembershipPayment', 'get', $memParams);
    //CRM_Core_Error::debug_var("MembershipPayment results", $memResult);exit;
    if ($memResult['count'] == 1) {
      $contributionId = $memResult['values'][$memResult['id']]['contribution_id'];

      $findContributionParams = array(
        'version' => 3,
        'id' => $contributionId,
      );
      $findContributionResult = civicrm_api('Contribution', 'getsingle', $findContributionParams);

      if (!empty($findContributionResult['receive_date']) && !empty($receiveDate)) {

        // Find the date difference between the contribution date and new collection date
        $dateDiff = _ukdirectdebitadditional_get_date_difference($receiveDate, $findContributionResult['receive_date']);

        //CRM_Core_Error::debug_var("Dates", $receiveDate.' - '.$findContributionResult['receive_date']);exit;
        //CRM_Core_Error::debug_var("Date Diff", $dateDiff);

        // if diff is less than 14 days, return Contribution ID update the contribution
        if ($dateDiff < 90) {
          //CRM_Core_Error::debug_var("Contribution Id", $contributionId);
          return $contributionId;
        }
      }
    }
    // Get the recent pending contribution if there are more than 1 payment for the membership
    else if ($memResult['count'] > 1) {
      $sql_params  = array( 1 => array( $membershipId , 'Integer' ));
      $sql = "SELECT cmp.contribution_id, cc.receive_date FROM civicrm_membership_payment cmp
      JOIN civicrm_contribution cc on cc.id = cmp.contribution_id
      WHERE cmp.membership_id = %1 AND cc.contribution_status_id = 2 ORDER BY cc.receive_date DESC";
      $dao = CRM_Core_DAO::executeQuery($sql , $sql_params);
      while($dao->fetch()) {
        if (!empty($dao->receive_date) && !empty($receiveDate)) {
          $dateDiff = _ukdirectdebitadditional_get_date_difference($receiveDate, $dao->receive_date);
          if ($dateDiff < 90) {
            return $dao->contribution_id;
          }
        }
      }
    }
  }

  return NULL;
}

/*
 * Function to get number of days difference between 2 dates
 */
function _ukdirectdebitadditional_get_date_difference($date1, $date2) {
  return floor((strtotime($date1) - strtotime($date2))/(60*60*24));
}

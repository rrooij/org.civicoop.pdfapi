<?php

/**
 * Pdf.Create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_pdf_create_spec(&$spec) {
  $spec['contact_id']['api.required'] = 1;
  $spec['template_id']['api.required'] = 1;
  $spec['to_email']['api.required'] = 1;
}

/**
 * Pdf.Create API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_pdf_create($params) {
  $domain  = CRM_Core_BAO_Domain::getDomain();
  $version = CRM_Core_BAO_Domain::version();
  $html    = array();

  if (!preg_match('/[0-9]+(,[0-9]+)*/i', $params['contact_id'])) {
    throw new API_Exception('Parameter contact_id must be a unique id or a list of ids separated by comma');
  }
  $contactIds = explode(",", $params['contact_id']);

  // Compatibility with CiviCRM > 4.3
  if($version >= 4.4) {
    $messageTemplates = new CRM_Core_DAO_MessageTemplate();
  } else {
    $messageTemplates = new CRM_Core_DAO_MessageTemplates();
  }

  $messageTemplates->id = $params['template_id'];
  if (!$messageTemplates->find(TRUE)) {
    throw new API_Exception('Could not find template with ID: ' . $params['template_id']);
  }

  // Optional pdf_format_id, if not default 0
  if (isset($params['pdf_format_id'])) {
    $messageTemplates->pdf_format_id = CRM_Utils_Array::value('pdf_format_id', $params, 0);
  }
  $html_template = _civicrm_api3_pdf_formatMessage($messageTemplates);

  $tokens = CRM_Utils_Token::getTokens($html_template);

  // get replacement text for these tokens
  $returnProperties = array(
      'sort_name' => 1,
      'email' => 1,
      'address' => 1,
      'do_not_email' => 1,
      'is_deceased' => 1,
      'on_hold' => 1,
      'display_name' => 1,
  );
  if (isset($messageToken['contact'])) {
    foreach ($messageToken['contact'] as $key => $value) {
      $returnProperties[$value] = 1;
    }
  }


  foreach($contactIds as $contactId){
    $html_message = $html_template;
    list($details) = CRM_Utils_Token::getTokenDetails(array($contactId), $returnProperties, false, false, null, $tokens);
    $contact = reset( $details );
    if ($contact['do_not_mail'] || CRM_Utils_Array::value('is_deceased', $contact) || $contact['on_hold']) {
      // Throw exception only if we are printing for 1 contact, if not skip it
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name']);
      else
        continue;
    }

    // call token hook
    $hookTokens = array();
    CRM_Utils_Hook::tokens($hookTokens);
    $categories = array_keys($hookTokens);

    CRM_Utils_Token::replaceGreetingTokens($html_message, NULL, $contact['contact_id']);
    $html_message = CRM_Utils_Token::replaceDomainTokens($html_message, $domain, true, $tokens, true);
    $html_message = CRM_Utils_Token::replaceContactTokens($html_message, $contact, false, $tokens, false, true);
    $html_message = CRM_Utils_Token::replaceComponentTokens($html_message, $contact, $tokens, true);
    $html_message = CRM_Utils_Token::replaceHookTokens($html_message, $contact , $categories, true);
    if (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY) {
      $smarty = CRM_Core_Smarty::singleton();
      // also add the contact tokens to the template
      $smarty->assign_by_ref('contact', $contact);
      $html_message = $smarty->fetch("string:$html_message");
    }

    $html[] = $html_message;

    //create activity
    $activityTypeID = CRM_Core_OptionGroup::getValue('activity_type',
      'Print PDF Letter',
      'name'
    );
    $activityParams = array(
      'source_contact_id' => $contactId,
      'activity_type_id' => $activityTypeID,
      'activity_date_time' => date('YmdHis'),
      'details' => $html_message,
    );
    $activity = CRM_Activity_BAO_Activity::create($activityParams);

    // Compatibility with CiviCRM >= 4.4
    if($version >= 4.4){
      $activityContacts = CRM_Core_OptionGroup::values('activity_contacts', FALSE, FALSE, FALSE, NULL, 'name');
      $targetID = CRM_Utils_Array::key('Activity Targets', $activityContacts);

      $activityTargetParams = array(
        'activity_id' => $activity->id,
        'contact_id' => $contactId,
        'record_type_id' => $targetID
      );
      CRM_Activity_BAO_ActivityContact::create($activityTargetParams);
    }
    else{
      $activityTargetParams = array(
        'activity_id' => $activity->id,
        'target_contact_id' => $contactId,
      );
      CRM_Activity_BAO_Activity::createActivityTarget($activityTargetParams);
    }
  }

  $fileName = CRM_Utils_String::munge($messageTemplates->msg_title) . '.pdf';
  $pdf = CRM_Utils_PDF_Utils::html2pdf($html, $fileName, TRUE, $messageTemplates->pdf_format_id);
  $tmpFileName = CRM_Utils_File::tempnam();
  file_put_contents($tmpFileName, $pdf);
  unset($pdf); //we don't need the temp file in memory

  //send PDF to e-mail address
  $from = CRM_Core_BAO_Domain::getNameAndEmail();
  $from = "$from[0] <$from[1]>";
  // set up the parameters for CRM_Utils_Mail::send
  $mailParams = array(
    'groupName' => 'PDF Letter API',
    'from' => $from,
    'toName' => $from[0],
    'toEmail' => $params['to_email'],
    'subject' => 'PDF Letter from Civicrm - ' . $messageTemplates->msg_title,
    'text' => "CiviCRM has generated a PDF letter",
    'attachments' => array(
        array(
            'fullPath' => $tmpFileName,
            'mime_type' => 'application/pdf',
            'cleanName' => $fileName,
        )
    )
  );

  $result = CRM_Utils_Mail::send($mailParams);
  if (!$result) {
    throw new API_Exception('Error sending e-mail to '.$params['to_email']);
  }


  $returnValues = array();
  return civicrm_api3_create_success($returnValues, $params, 'Pdf', 'Create');
}

function _civicrm_api3_pdf_formatMessage($messageTemplates){
  $html_message = $messageTemplates->msg_html;

  //time being hack to strip '&nbsp;'
  //from particular letter line, CRM-6798
  $newLineOperators = array(
      'p' => array(
          'oper' => '<p>',
          'pattern' => '/<(\s+)?p(\s+)?>/m',
      ),
      'br' => array(
          'oper' => '<br />',
          'pattern' => '/<(\s+)?br(\s+)?\/>/m',
      ),
  );
  $htmlMsg = preg_split($newLineOperators['p']['pattern'], $html_message);
  foreach ($htmlMsg as $k => & $m) {
    $messages = preg_split($newLineOperators['br']['pattern'], $m);
    foreach ($messages as $key => & $msg) {
      $msg = trim($msg);
      $matches = array();
      if (preg_match('/^(&nbsp;)+/', $msg, $matches)) {
        $spaceLen = strlen($matches[0]) / 6;
        $trimMsg = ltrim($msg, '&nbsp; ');
        $charLen = strlen($trimMsg);
        $totalLen = $charLen + $spaceLen;
        if ($totalLen > 100) {
          $spacesCount = 10;
          if ($spaceLen > 50) {
            $spacesCount = 20;
          }
          if ($charLen > 100) {
            $spacesCount = 1;
          }
          $msg = str_repeat('&nbsp;', $spacesCount) . $trimMsg;
        }
      }
    }
    $m = implode($newLineOperators['br']['oper'], $messages);
  }
  $html_message = implode($newLineOperators['p']['oper'], $htmlMsg);

  return $html_message;
}

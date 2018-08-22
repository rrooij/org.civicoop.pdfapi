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
  $subject = $messageTemplates->msg_subject;
  $html_template = _civicrm_api3_pdf_formatMessage($messageTemplates);

  $tokens = CRM_Utils_Token::getTokens($html_template);

  // Optional template_email_id, if not default 0
  $template_email_id = CRM_Utils_Array::value('template_email_id', $params, 0);
  // Optional argument: use email subject from email template
  $template_email_use_subject = CRM_Utils_Array::value('template_email_use_subject', $params, 0);

  if ($template_email_id) {
    if($version >= 4.4) {
      $messageTemplatesEmail = new CRM_Core_DAO_MessageTemplate();
    } else {
      $messageTemplatesEmail = new CRM_Core_DAO_MessageTemplates();
    }
    $messageTemplatesEmail->id = $template_email_id;
    if (!$messageTemplatesEmail->find(TRUE)) {
      throw new API_Exception('Could not find template with ID: ' . $template_email_id);
    }
    $html_message_email = $messageTemplatesEmail->msg_html;
    $email_subject = $messageTemplatesEmail->msg_subject;
    $tokens_email = CRM_Utils_Token::getTokens($html_message_email);
  }

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
    if (isset($contact['do_not_mail']) && $contact['do_not_mail'] == TRUE) {
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name'].' because DO NOT MAIL is set');
      else
        continue;
    }
    if (isset($contact['is_deceased']) && $contact['is_deceased'] == TRUE) {
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name'].' because contact is deceased');
      else
        continue;
    }
    if (isset($contact['on_hold']) && $contact['on_hold'] == TRUE) {
      if(count($contactIds) == 1)
        throw new API_Exception('Suppressed creating pdf letter for: '.$contact['display_name'].' because contact is on hold');
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
  }

  $fileName = CRM_Utils_String::munge($messageTemplates->msg_title) . '.pdf';
  $pdf = CRM_Utils_PDF_Utils::html2pdf($html, $fileName, false, $messageTemplates->pdf_format_id);
  file_put_contents('/tmp/test.pdf', $pdf);
  echo $pdf;
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

<?php

ini_set('max_execution_time', 20000);
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

function _civicrm_api3_pdf_generate_html(&$text, $pdfFormat = NULL ) {
    // Get PDF Page Format
    $format = CRM_Core_BAO_PdfFormat::getDefaultValues();
    if (is_array($pdfFormat)) {
      // PDF Page Format parameters passed in
      $format = array_merge($format, $pdfFormat);
    }
    else {
      // PDF Page Format ID passed in
      $format = CRM_Core_BAO_PdfFormat::getById($pdfFormat);
    }
    $paperSize = CRM_Core_BAO_PaperSize::getByName($format['paper_size']);
    $paper_width = CRM_Utils_PDF_Utils::convertMetric($paperSize['width'], $paperSize['metric'], 'pt');
    $paper_height = CRM_Utils_PDF_Utils::convertMetric($paperSize['height'], $paperSize['metric'], 'pt');
    // dompdf requires dimensions in points
    $paper_size = array(0, 0, $paper_width, $paper_height);
    $orientation = CRM_Core_BAO_PdfFormat::getValue('orientation', $format);
    $metric = CRM_Core_BAO_PdfFormat::getValue('metric', $format);
    $t = CRM_Core_BAO_PdfFormat::getValue('margin_top', $format);
    $r = CRM_Core_BAO_PdfFormat::getValue('margin_right', $format);
    $b = CRM_Core_BAO_PdfFormat::getValue('margin_bottom', $format);
    $l = CRM_Core_BAO_PdfFormat::getValue('margin_left', $format);
    $stationery_path_partial = CRM_Core_BAO_PdfFormat::getValue('stationery', $format);
    $stationery_path = NULL;
    if (strlen($stationery_path_partial)) {
      $doc_root = $_SERVER['DOCUMENT_ROOT'];
      $stationery_path = $doc_root . "/" . $stationery_path_partial;
    }
    $margins = array($metric, $t, $r, $b, $l);
    $config = CRM_Core_Config::singleton();
    // Add a special region for the HTML header of PDF files:
    $pdfHeaderRegion = CRM_Core_Region::instance('export-document-header', FALSE);
    $htmlHeader = ($pdfHeaderRegion) ? $pdfHeaderRegion->render('', FALSE) : '';
    $html = "
<html>
  <head>
    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/>
    <style>@page { margin: {$t}{$metric} {$r}{$metric} {$b}{$metric} {$l}{$metric}; }</style>
    <style type=\"text/css\">@import url({$config->userFrameworkResourceURL}css/print.css);</style>
    {$htmlHeader}
  </head>
  <body>
    <div id=\"crm-container\">\n";
    $html .= $text;
    $html .= "
    </div>
  </body>
</html>";
    return $html;
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
  $version = CRM_Core_BAO_Domain::version();
  $html    = '';

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

  $imgTags = array();
  $imgRegex = '/<img([\w\W]+?)/>/';
  // Also capure the offset of the img tags so that we can insret them later
  preg_match($imgRegex, $html_template, $matches, PREG_OFFSET_CAPTURE);
  // Temporarily delete all img tags and put them back later
  if (count($imgTags) !== 0) {
    preg_replace($imgRegex, '');
  }

  list($details) = CRM_Utils_Token::getTokenDetails($contactIds, $returnProperties, false, false, null, $tokens);
  // call token hook
  $hookTokens = array();
  CRM_Utils_Hook::tokens($hookTokens);
  $categories = array_keys($hookTokens);
  foreach($contactIds as $contactId){
    $html_message = $html_template;
    $contact = $details[$contactId];
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
    CRM_Utils_Token::replaceGreetingTokens($html_message, NULL, $contact['contact_id']);
    $html_message = CRM_Utils_Token::replaceContactTokens($html_message, $contact, false, $tokens, false, true);
    $html_message = CRM_Utils_Token::replaceHookTokens($html_message, $contact , $categories, true);
    // Put all img tags back in the string
    foreach($imgTags as $imgTag) {
      substr_replace($html_message, $imgTag[0], $imgTag[1], 0);
    }
    // Add new page after every contact
    $html_message .= '<div style="page-break-after: always"></div>';
    $html .= $html_message;
  }
  $finalHtml = _civicrm_api3_pdf_generate_html($html, $messageTemplates->pdf_format_id);
  // Write HTML to temporary file
  $htmlFile = fopen("/tmp/{$messageTemplates->id}.html", 'w');
  fwrite($htmlFile, $finalHtml);
  fclose($htmlFile);
  return civicrm_api3_create_success(['html' => 'ok'], $params, 'Pdf', 'Create');
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

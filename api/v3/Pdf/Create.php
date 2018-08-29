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
  $spec['pdf_format_id']['api.required'] = 1;
  $spec['msg_html']['api.required'] = 1;
}

/**
 * Generate HTML needed for PDF
 * @param string $text Text to render HTML
 * @param int $pdfFormat PDF format ID for rendering the PDF
 * @return string The generated HTML
 */
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
    return "
<html>
  <head>
    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\"/>
    <style>@page { margin: {$t}{$metric} {$r}{$metric} {$b}{$metric} {$l}{$metric}; }</style>
    <style type=\"text/css\">@import url({$config->userFrameworkResourceURL}css/print.css);</style>
    {$htmlHeader}
  </head>
  <body>
    <div id=\"crm-container\">\n
          {$text}
    </div>
  </body>
</html>";
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
  $html    = '';

  if (!preg_match('/[0-9]+(,[0-9]+)*/i', $params['contact_id'])) {
    throw new API_Exception('Parameter contact_id must be a unique id or a list of ids separated by comma');
  }
  $contactIds = explode(",", $params['contact_id']);

  $html_template = $params['msg_html'];
  $imgRegex = '/<img.*>/';
  preg_match($imgRegex, $html_template, $imgTags, PREG_OFFSET_CAPTURE);
  // Temporarily delete all img tags and put them back later
  if (count($imgTags) !== 0) {
	  $html_template = preg_replace($imgRegex, '', $html_template);
  }
  $tokens = CRM_Utils_Token::getTokens($html_template);
  // get replacement text for these tokens
  $returnProperties = array(
      'do_not_email' => 1,
      'is_deceased' => 1,
      'on_hold' => 1,
  );
  if (isset($tokens['contact'])) {
    foreach ($tokens['contact'] as $key => $value) {
      $returnProperties[$value] = 1;
    }
  }

  list($details) = CRM_Utils_Token::getTokenDetails($contactIds, $returnProperties, false, false, null, $tokens);
  // call token hook
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
    // Custom token replacement method, way faster because we fetch all the token
    // values beforehand.
    foreach($tokens as $type => $tokenValue) {
        foreach($tokenValue as $var) {
            $contactKey = null;
            if ($type === 'contact') {
                $contactKey = "$var";
            }
            else {
                $contactKey = "$type.$var";
            }
            CRM_Utils_Token::token_replace($type, $var, $contact[$contactKey], $html_message);
	    }
    }
    // Add new page after every contact
    // Put all img tags back in the string
    foreach($imgTags as $imgTag) {
        $html_message = substr_replace($html_message, $imgTag[0], $imgTag[1], 0);
    }
    $html_message .= '<div style="page-break-after: always"></div>';
    $html .= $html_message;
  }
  $finalHtml = _civicrm_api3_pdf_generate_html($html, $params['pdf_format_id']);
  return civicrm_api3_create_success(['html' => $finalHtml], $params, 'Pdf', 'Create');
}

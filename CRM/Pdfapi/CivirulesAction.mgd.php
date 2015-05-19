<?php

if (_pdfapi_is_civirules_installed()) {
  return array (
    0 =>
      array (
        'name' => 'Civirules:Action.Pdfapi',
        'entity' => 'CiviRuleAction',
        'params' =>
          array (
            'version' => 3,
            'name' => 'pdfapi_send',
            'label' => 'Send PDF',
            'class_name' => 'CRM_Pdfapi_CivirulesAction',
            'is_active' => 1
          ),
      ),
  );
}
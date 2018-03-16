# org.civicoop.pdfapi
PDF API for CiviCRM to create a PDF file and send it to a specified e-mail address.
This is usefull for automatic generation of letters

The entity for the PDF API is Pdf and the action is Create.
Parameters for the api are specified below:
- contact_id: list of contacts IDs to create the PDF Letter (separated by ",")
- template_id: ID of the message template which will be used in the API. _You have to enter the text in the HTML part of the template and select PDF Page format_
- to_email: e-mail address where the pdf file is send to
- pdf_format_id: (optional) ID of the PDF format, is not especified the default PDF format is used
- template_email_id: (optional) ID of the message template which will be used to generate the email body.

*It is not possible to specify your own message through the API.*



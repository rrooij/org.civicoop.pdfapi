# org.civicoop.pdfapi
PDF API for CiviCRM to create a PDF file and send it to a specified e-mail address.
This is usefull for automatic generation of letters

The entity for the PDF API is Pdf and the action is Create.
Parameters for the api are specified below:
- contact_id: contact which will receive the e-mail
- template_id: ID of the message template which will be used in the API. 
- to_email: e-mail address where the pdf file is send to

*It is not possible to specify your own message through the API.*

    

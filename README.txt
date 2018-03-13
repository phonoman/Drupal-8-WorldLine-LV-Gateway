/****** MODULE *******/
Currently this module (commerce_ibis) works for Drupal 8 as offsite (redirect) gateway. 
Also, first version is created only for credit card payments. 

/****** REVERSING *******/
Although payment reversing is already provided, currently upon visiting 
/admin/config/{order_id}/payment/reverse 
a full order amount reverse is instantly issued due to not using this feature apart from testing.

/****** CERTIFICATES *******/
Certificates are issued by WorldLine LV team and in the first version are supposed to be placed at 
commerce_ibis/certs/ folder.
In order to feed the certificate - upload the file and type its name in the gateway configuration.

/****** SERVER URLS *******/
Test and live URLs are hard-coded since I believe they won't be changed.

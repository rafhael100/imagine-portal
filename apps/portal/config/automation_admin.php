<?php
return [
 'transport_enabled'=>env('IMAGINE_AUTOMATION_TRANSPORT_ENABLED',false),
 'ca_file'=>env('AUTOMATION_S2S_CA_FILE'),
 'certificate_file'=>env('AUTOMATION_S2S_CERT_FILE'),
 'private_key_file'=>env('AUTOMATION_S2S_PRIVATE_KEY_FILE'),
 'signing_key_file'=>env('AUTOMATION_S2S_SIGNING_KEY_FILE'),
 'enabled'=>env('IMAGINE_AUTOMATION_ADMIN_ENABLED',false),
 'mode'=>env('IMAGINE_AUTOMATION_ADMIN_MODE','disabled'),
];

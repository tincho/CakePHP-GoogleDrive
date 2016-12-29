# CakePHP Component for using Google Drive API

This was developed for a university library webapp which needed to publish
**lots** of PDF content (like, >30gb) and had no server space to upload so much
data!

So, as it had three main careers and Google Drive free acount storage limit is
15gb, three Google Accounts were created, one for each career's library.

Using this component, each file "metadata" was stored in the database, holding
the Google Drive file ID and folder ID, and the files were uploaded to GDrive.

I developed this more than 2 years ago and I don't think it'd be useful for anyone.
Anyway I'd like to keep it and share it 'cause it's fun to see how one evolves as professional.

Caution: Look at the code with one eye! ;)

# Contents

- Vendor/google-api-php-client holds Google API PHP client provided by Google, with some minor tweaks
- Controller/Component/GoogleApiComponent.php contains the abstraction component...
- Controller/sample-BibliotecaController.php contians the controller which uses the Component, to use as example
- Console/Command/DriveShell.php contains a Cake CLI utility used to batch upload lots of files

# Config

The following array must be set somehow to CakeConfig

```php
$config['Google'] = array(
	'keysPath' => dirname(APP) . DS .  'service_keys',
	'ServiceAccountCredentials' => array(
		'career 1' => array(
				'application' => 'Career 1',
				'client_id' => 'google api client id',
				'service_account_email' => 'google service account email',
				'key_filename' => 'something-privatekey.p12' // stored in service_keys dir!
		),
        // ... the other careers
	)
);
```

# LICENSE

Code written by me is MIT Licensed
Code included in Vendor has its own license, not sure which but I'm sure its open source

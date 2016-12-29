<?php
/**
 * Google Client Api Component for CakePHP for using with SERVICE ACCOUNT auth
 * read some background 
 * - implements some functionality provided by Google API PHP Client . 
 * - so far only some Drive methods-
 * Developer: Martin Salinas
 * License: LGPL
 * (see Google's Services terms and conditions!)
 * 
 * Uso:
 * 
 * 1) Multi account
	public $components = array(
		'GoogleApi'
	);
	* y luego llamar a setCredentials en beforeFilter
	* 
	*

 * 2) Single account

	public $components = array(
		'GoogleApi' => array(
			'credentials'=> array(
				'application' => 'APPLICATION NAME',
				'client_id' => 'CLIENT ID',
				'service_account_email' => 'SERVICE ACCOUNT EMAIL',
				'key_filename' => 'PUBLICKEYID-privatekey.p12', 
			)
		)
	);


 * 
 * */


App::uses('Component', 'Controller');

set_include_path(APP . '/Vendor/google-api-php-client/src/' . PATH_SEPARATOR . get_include_path());

require_once 'Google/Client.php';
require_once 'Google/Http/MediaFileUpload.php';

require_once 'Google/Service/Drive.php'; 

class GoogleApiComponent extends Component {

	public $components = array('Session');
	public $controller;
	public $errorMessage = '';

	private $_defaults = array(
		'services'=> array( 'Drive' ), // enumerate here services that will be used
		'scopes' => array('https://www.googleapis.com/auth/drive'),
	);
	
	
	/*
	 * @type Google_Client
	 * */
	private $client;

	/*
	 * @type array of Google_Service child instances
	 * */
	private $services;
	
	/*
	 * @type Google_Auth_AssertionCredentials
	 * */
	private $authCredentials;

	/*
	 * @type Google_Service_Drive_DriveFile
	 * */
	private $_file;

	/*
	 * 
	 * */
	public function __construct(ComponentCollection $collection, $settings = array()) {
		$this->settings = Set::merge($this->_defaults, $settings);
		parent::__construct($collection, $this->settings);
		$this->controller = $collection->getController();
	}

	/*
	 * 
	 * */
	public function setCredentials( $credentials = array() ) {
		if(empty($credentials)) {
			//throw somethingWrong!!
			return false;
		}
		$this->settings['credentials'] = $credentials;
	}
	
	/*
	 * 
	 * 
	 * */
	public function startup(Controller $controller) {

		$credentials = $this->settings['credentials'];

		$this->client = new Google_Client();
		$this->client->setApplicationName( $credentials['application'] );

		$authScopes = $this->settings['scopes'];

		$this->authenticate($credentials, $authScopes);
		
		$this->instantiateServices( );
	}
	
	private function instantiateServices(  ) {
		foreach( $this->settings['services'] as $svcName) {
			$svcClassName = 'Google_Service_' . $svcName;
			$this->services[ $svcName ] = new $svcClassName ($this->client);
		}
	}

	/* ******************
	 * generic api code
	 * */
	private function authenticate($credentials, $scopes) {

		// TODO: abstraer this->Session y Config ? para no depender de CakeSession y CakeConfig

		$actual_client_id = $this->Session->read('GoogleApi.client_id');
		if(!empty($actual_client_id)) {
			if($actual_client_id != $credentials['client_id'])
				$this->Session->delete('GoogleApi');
		}
		$keysPath = Configure::read('Google.keysPath');
		$key = file_get_contents($keysPath . DS . $credentials['key_filename']);

		if ($this->Session->check('GoogleApi.service_token')) {
			$this->client->setAccessToken($this->Session->read('GoogleApi.service_token'));
		} 
		
		$this->authCredentials = new Google_Auth_AssertionCredentials(
			$credentials['service_account_email'],
			$scopes,
			$key
		);

		$this->client->setAssertionCredentials($this->authCredentials);

		if($this->client->getAuth()->isAccessTokenExpired()) {
			$this->client->getAuth()->refreshTokenWithAssertion($this->authCredentials);
		}

		$this->Session->write('GoogleApi.service_token', $this->client->getAccessToken());
		$this->Session->write('GoogleApi.client_id', $credentials['client_id']); 
	}

	

	/* ****************************
	 * service-specific wrappers (Drive in this case)
	 * */

	/*
	 *
	 * get multiple files/folders 
	 * */
	public function getDriveFiles($options = array()) {

		$_defaults = array(
			'recursive'=> false, // recurse into folders
			'trashed' => false,  // includes trashed files
			'parentId' => null,  // filters from specific folder
			'folders' => false,  // include folders in result
			'files'=> true,      // include files in result
			'q' => '',           // custom query overrides all
			'q_append' => '',    // custom query appended to q 
			'raw' => false,      // returns $files->getItems() object instead of formatted array
			'parents' => true,   // include Parent in array (may be slow)
			'properties' => false, // include Properties in array (may be slow)
		);

		$options = array_merge($_defaults, $options);
		
		if(empty($options['q'])) { 

			$options['q'] = ( $options['trashed'] ) ? 'trashed = true' : 'trashed = false';

			if($options['folders'] && !$options['files']) // solo carpetas
				$options['q'] .= " and mimeType = 'application/vnd.google-apps.folder'";
			if($options['files'] && !$options['folders']) // solo archivos
				$options['q'] .= " and mimeType != 'application/vnd.google-apps.folder'";
			if($options['parentId']) {
				$options['q'] .= " and '".$options['parentId']."' in parents";
			}
			
		}
		if(!empty($options['q_append'])) $options['q'] .= $options['q_append'];

		$files = $this->services['Drive']->files->listFiles(array(
			'q'=>$options['q'],
		));

		$items = $files->getItems();

		if($options['raw']) 
			return $items;
		
		$data = array();
		foreach($items as $file) { // $file @type Google_Service_Drive_DriveFile

			$_data = array(
				'DriveFile' => array(
					'id' => $file->getId(),
					'url' => $file->webContentLink,
					'title' => $file->getTitle(),
					'mimeType'=> $file->getMimeType(),
					'size' => $file->fileSize,
					'is_folder' => ($file->getMimeType() == 'application/vnd.google-apps.folder' )
				),
			);

			if($options['properties'])
				$_data['Properties'] = $this->getDriveFileProperties( $file->getId() );
			
			if($options['parents']) 
				$_data['Parent'] = array(
					'id' => ( $file->getParents()[0]->isRoot ) ? 'root' : $file->getParents()[0]->getId(),
					'title' => $this->getDriveFileTitle($file->getParents()[0]->getId()),
				);

			if($file->getMimeType() == 'application/vnd.google-apps.folder') 
			{
				if($options['recursive'] )
					$_data['Children'] = $this->getDriveFolders( $file->getId(), false );
			}


			$data[] = $_data;
		}

		return $data;
	}
	
	// wrapersito de getDriveFiles 
	public function getDriveFolders( $parentId = 'root', $recursive = true) {
		return $this->getDriveFiles( array(
			'recursive' => $recursive,
			'files'     => false,
			'folders'   => true, 
			'parentId'  => $parentId,
			'parents'   => false,
			'properties'=> false
		));
	}

	/*
	 * 
	 * get single file/folder
	 * */
	public function getDriveFile( $fileId, $raw = false ) { 

		$this->_file = $this->services['Drive']->files->get($fileId);
		if($raw) 
			return $this->_file;

		$data = array(
			'DriveFile'=> array(
				'id' => $fileId,
				'title' => $this->_file->title,
				'url' => $this->_file->webContentLink,
				'size' => $this->_file->fileSize,
				'is_folder' => ($this->_file->getMimeType() == 'application/vnd.google-apps.folder' )
			),
			//'Properties' => $this->getDriveFileProperties($fileId),
			'Parent'=>array(
				'id' => $this->_file->parents[0]->getId(),
				'title' => $this->getDriveFileTitle($this->_file->parents[0]->getId()),
			)
		);
		if($this->_file->getMimeType() == 'application/vnd.google-apps.folder') 
		{
			// si es carpeta quier su contenido tambien
			$data['Children'] = $this->getDriveFiles( array('parentId'=>$fileId, 'folders'=>true,'files'=>true, 'recursive'=>false) );
		}

		return $data;
	}
	
	
	public function createDriveFolder($title, $options = array()) {
		$_defaults = array(
			'properties' => null,
			'parentId' => null,
		);
		$options = array_merge($_defaults, $options);
		
		/**
		 * "In the Drive API, a folder is essentially a file identified by MIME type application/vnd.google-apps.folder"
		 * */

		try {
			$this->_file = new Google_Service_Drive_DriveFile();
		
			$this->_file->title = $title;
			$this->_file->setMimeType('application/vnd.google-apps.folder');
		
			if ($options['parentId'] != null) {
				$parent = new Google_Service_Drive_ParentReference();
				$parent->setId($options['parentId']);
				$this->_file->setParents(array($parent));
				unset($parent);
			}

			$this->_file = $this->services['Drive']->files->insert($this->_file, array( 'mimeType' => 'application/vnd.google-apps.folder' ));

		} catch (Exception $e) {
			$this->errorMessage = $e->getMessage();
			return false;
		}
		
		return $this->_file;		
	}


	/**
	uploadDriveFile 

	@param $file_data format : 
				array(
					'file'=> array(
						'name' => $fileName,
						'tmp_name' => $filePath,
						'type' => finfo_file($finfo, $filePath),
						'size' => filesize($filePath),
					),
					'parent_id' => $parentId , // OPTIONAL
				);	
	*/
	public function uploadDriveFile ( $file_data, $properties = array() ) {

		$this->_file = new Google_Service_Drive_DriveFile();
		
		$this->_file->title = $file_data['file']['name'];

		$this->_file->setOriginalFilename($file_data['file']['name']);

		try {

			if (!empty($file_data['parent_id'])) {
				$parent = new Google_Service_Drive_ParentReference();
				$parent->setId($file_data['parent_id']);
				$this->_file->setParents(array($parent));
				unset($parent);
			}

			$chunkSizeBytes = 1 * 1024 * 1024;
			// Call the API with the media upload, defer so it doesn't immediately return.
			$this->client->setDefer(true);
			
			$request = $this->_file = $this->services['Drive']->files->insert($this->_file);
			
			// Create a media file upload to represent our upload process.
			$media = new Google_Http_MediaFileUpload(
				$this->client,
				$request,
				$file_data['file']['type'],
				null,
				true,
				$chunkSizeBytes
			);
			$media->setFileSize($file_data['file']['size']);
			
			// Upload the various chunks. $status will be false until the process is
			// complete.
			$status = false;
			$handle = fopen($file_data['file']['tmp_name'], "rb");
			while (!$status && !feof($handle)) {
				$chunk = fread($handle, $chunkSizeBytes);
				$status = $media->nextChunk($chunk);
			}
			fclose($handle);
			unset($media);
			
			$this->_file = $status;
					
			if ( $status != false ) {
				$this->_file = $status;
				// if public :
				//$this->_setDriveFilePermissions($this->_file->getId(), 'anyone', 'reader');

			} else {
				$this->_file = false;
			}


		} catch ( Exception $e ) {
			$this->errorMessage = $e->getMessage();
			return false;
		}

		if(!$this->_file)
			return false;
			
		$id = $this->_file->getId();
		
		return $this->_file;
		
	}
	

	public function updateDriveFile( $file_data ) {
		try {
			$fileId = $file_data['id'];
			if(empty($fileId))
				throw new Exception('Archivo no especificado');

			$file = $this->getDriveFile($fileId, $raw = true );

			$file->setTitle($file_data['title']);
			$file->setDescription($file_data['description']);
			
			if (!empty($file_data['parent_id'])) {
				$parent = new Google_Service_Drive_ParentReference();
				$parent->setId($file_data['parent_id']);
				$this->_file->setParents(array($parent));
				unset($parent);
			}

			$additionalParams = array();

			if(!empty($file_data['file'])) {
				$file->setMimeType($file_data['file']['type']);
				$data = file_get_contents($file_data['file']['tmp_name']);
				$additionalParams = array(
					'data' => $data,
					'mimeType' => $newMimeType
				);
			}
		
			$updatedFile = $this->services['Drive']->files->update($fileId, $file, $additionalParams);
			
			return $updatedFile;
		} catch (Exception $e) {
			$this->errorMessage = $e->getMessage();
			return false;
		}
	}


	public function deleteDriveFile( $id ) {
		try {
			$this->services['Drive']->files->delete($id);
			return true;
		} catch (Exception $e) {
			$this->errorMessage = $e->getMessage();
			return false;
		}		
	}

	/**
	 	THIS METHOD IS EXTREMELY DAN GE ROUS 
		called with no params (or unsafe params) means deleting EVERYTHING ON THE DRIVE WITH NO USER CONSENT
		set to FALSE the $safety param to use it with empty $q

	*/
	public function deleteDriveFiles( $q = null, $safety = true ) {
		
		$options = array();
		if(!empty($q)) 
			$options['q'] = $q;
		elseif($safety) return true; 
		 
		$files = $this->services['Drive']->files->listFiles($options);

		try {
			foreach($files->getItems() as $file) {
				$this->services['Drive']->files->delete($file->getId());
			}
		} catch (Exception $e) {
			$this->errorMessage = $e->getMessage();
			return false;
		}
		return true;
	}

	public function setDriveFileProperties($fileId, $properties) {
		try
		{
			foreach($properties as $key => $value) {
				$property = new Google_Service_Drive_Property();
				$property->setKey($key)->setValue($value);
				$this->services['Drive']->properties->insert($fileId, $property);
			}
			unset($property);
		} catch ( Exception $e ) {
			$this->errorMessage = $e->getMessage();
			return false;
		}
	}
	
	/**
	 * @see https://developers.google.com/drive/v2/reference/permissions
	 * 
	 */
	public function setDriveFilePermissions($fileId, $type, $role) {

		$newPermission = new Google_Service_Drive_Permission();
		$newPermission->setType($type);
		$newPermission->setRole($role);
		try {
			$permissions_result = $this->services['Drive']->permissions->insert($fileId, $newPermission);
		} catch (Exception $e) {
			$this->errorMessage = $e->getMessage();
			return false;
		}
		unset($newPermission);
		return $permissions_result;
	}	

	public function getUploadedDriveFile() {
		if ( ! $this->_file ) 
			return false;	
		return $this->_file;
	}
	
	public function getDriveFileTitle ( $fileId ) {
		return $this->services['Drive']->files->get($fileId)->getTitle();
	}
	public function getDriveFileProperties($fileId) {
		return $this->services['Drive']->properties->listProperties($fileId)->getItems();
	}
	
	public function getDriveFilePermissions($fileId) {
		$permissions = $this->services['Drive']->permissions->listPermissions($fileId);
		return $permissions;
	}

}

class Drive {
	private $service; 
	private $client; 
}


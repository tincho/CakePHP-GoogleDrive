<?php


App::uses('BibliotecaController', 'Controller');
//App::uses('Carrera', 'Model');
App::uses('Folder', 'Utility');
App::uses('File', 'Utility');


set_include_path(APP . '/Vendor/google-api-php-client/src/' . PATH_SEPARATOR . get_include_path());

require_once 'Google/Client.php';
require_once 'Google/Http/MediaFileUpload.php';
require_once 'Google/Service/Drive.php';

class DriveShell extends AppShell
{

	public $carreras = array ( 1 => 'enfermeria', 'terapia-ocupacional', 'trabajo-social');

	public function startup()
	{
		$this->Biblioteca = new BibliotecaController();
		$this->Biblioteca->constructClasses();

		if(empty($this->args[0])) 
			die('Especificar carrera!!');

		$this->Biblioteca->Carrera->id = $this->args[0];
		$this->Biblioteca->Carrera->read();
		$this->GoogleApi = new GoogleApi($this->Biblioteca->Carrera->getGoogleCredentials());
	}



    public function permissions() {
    	$fileId = $this->args[1];
    	debug($this->GoogleApi->getDriveFilePermissions($fileId));
    }
    public function set_public() {
    	$fileId = $this->args[1];
		debug($this->GoogleApi->setDriveFilePermissions($fileId, 'anyone', 'reader'));
    }

    public function batch_public() {
    	$files = $this->Biblioteca->DriveFile->find('list',array( 
    		'conditions' => array(
    			'DriveFile.carrera_id' => $this->Biblioteca->Carrera->id,
    			'DriveFile.mimeType != ' => 'application/vnd.google-apps.folder'
    		) 
    	));
    	foreach($files as $fileId => $fileName) {
    		$this->out("<info>cambiando permisos a: $fileName</info>");
			debug($this->GoogleApi->setDriveFilePermissions($fileId, 'anyone', 'reader'));
			sleep(5);
    	}
    }

	public function batch_upload()
	{

		$carrera_slug = $this->carreras[ $this->args[0] ];
		$carrera_dir = new Folder('/var/www/proyectitos/material/'.$carrera_slug);

		$finfo = finfo_open(FILEINFO_MIME_TYPE);

		$folder_pseudoid = 1;
		
		$max = $this->Biblioteca->DriveFile->field('MAX(id)', array(
			'DriveFile.carrera_id' => $this->Biblioteca->Carrera->id,
			'DriveFile.mimeType'=>'application/vnd.google-apps.folder',
		));	
		$folder_pseudoid = (int)substr($max,28) + 1;

		foreach ($carrera_dir->read()[0] as $folderName)
		{
			$this->out('<info>'.$folderName.'</info>');

			$folderPath = $carrera_dir->pwd() . '/' . $folderName;

			/*
			$driveFolder = $this->GoogleApi->createDriveFolder($folderName);

			if(!$driveFolder) {
				$this->out('error con ' . $folderName . ' -error: ' .$this->GoogleApi->errorMessage);
				break;
			}
			$parentId = $driveFolder->getId();

			$this->GoogleApi->setDriveFilePermissions($parentId, 'anyone', 'reader');
			*/

			$existingFolder = $this->Biblioteca->DriveFile->find('first',array('conditions'=>array(
				'DriveFile.title' => $folderName,
				'DriveFile.carrera_id' => $this->Biblioteca->Carrera->id
			)));
			if(!empty($existingFolder)) {
					$this->out("\t<warning> carpeta ya creada :  $folderName - id: " . $existingFolder['DriveFile']['id'] . '</warning>');

					$parentId = $existingFolder['DriveFile']['id'];
					// if modo_ desatendido continue else pregunto que hacer !

			} else {


				if($this->args[0]==1) $carrera_id_prefix='0';
				if($this->args[0]==2) $carrera_id_prefix='t';
				if($this->args[0]==3) $carrera_id_prefix='s';

				$parentId = str_pad($folder_pseudoid, 30, $carrera_id_prefix, STR_PAD_LEFT);


				$this->Biblioteca->DriveFile->create();

				preg_match_all('/^(\d+)-?(\d+)?/', $folderName, $matches);
				$codigo_desde = $codigo_hasta = null;
				if(!empty($matches[1][0]))
					$codigo_desde = $matches[1][0];
				if(!empty($matches[2][0]))
					$codigo_hasta = $matches[2][0];
				$folder_data = array(
					'id' => $parentId,
					'title'=> $folderName,
					'mimeType'=>'application/vnd.google-apps.folder',
					'carrera_id'=>$this->Biblioteca->Carrera->id,
					'codigo_desde' => $codigo_desde,
					'codigo_hasta' => $codigo_hasta,
				);
				if(!$this->Biblioteca->DriveFile->save($folder_data))
				{
					debug($this->Biblioteca->DriveFile->validationErrors);
					continue;
				}
				$folder_pseudoid++;

			}


			 // */

			$folder = new Folder($folderPath);
			$files = $folder->find('.*');
			foreach($files as $fileName)
			{

				if($fileName == 'Thumbs.db') 
					continue;


				$filePath = $folderPath . '/' . $fileName;

				$this->out("\t<info>/$fileName (". round(filesize($filePath)/1024/1024,2) .' mB) </info>');


				$existing = $this->Biblioteca->DriveFile->find('first', array('conditions'=>array( 
					'DriveFile.title' => $fileName, 
					'DriveFile.size' => filesize($filePath), 
					'DriveFile.carrera_id' =>  $this->Biblioteca->Carrera->id )
				) );
				if(!empty($existing)) // hay un archivo de mismo nombre, carrera y tamaño que el que estoy recorriendo
				{ 

					$this->out("\t<warning> ya existe :  $filePath - id: " . $existing['DriveFile']['id'] . '</warning>');


					if($existing['DriveFile']['parent_id'] != $parentId) {
						$this->Biblioteca->DriveFile->id = $existing['DriveFile']['id'];
						$this->Biblioteca->DriveFile->saveField('parent_id', $parentId);
						$this->out("<comment>se actualizo parent_id para archivo $fileName. era: ".$existing['DriveFile']['parent_id']." ahora es: $parentId</comment>");
					}

					continue;
				}


				$file = array(
					'file'=> array(
						'name' => $fileName,
						'tmp_name' => $filePath,
						'type' => finfo_file($finfo, $filePath),
						'size' => filesize($filePath),
					),
				);

				// /*
				$driveFile = $this->GoogleApi->uploadDriveFile( $file ) ;

				if(!$driveFile)
				{
					$this->out('problema con ' . $filePath . ' error : ' . $this->GoogleApi->errorMessage);
					continue;
				}
				$this->GoogleApi->setDriveFilePermissions($driveFile->getId(), 'anyone', 'reader');

				$this->Biblioteca->DriveFile->create();
				$file_data = array();
				$file_data['id'] = $driveFile->getId();
				$file_data['title'] = $driveFile->title;
				$file_data['parent_id'] = $parentId;
				$file_data['url'] = $driveFile->webContentLink;
				$file_data['drive_url'] = $driveFile->getDownloadUrl();
				$file_data['mimeType'] = $file['file']['type'];
				$file_data['size'] = $driveFile->fileSize;
				//$file_data['parent_name'] = $folderName;
				$file_data['carrera_id'] = $this->Biblioteca->Carrera->id;

				$this->Biblioteca->DriveFile->save ( $file_data );


				// */

				unset($driveFile);

				$this->out('<comment>èxito</comment>');
				sleep(5);

			}
		}

		finfo_close($finfo);
		die;
	}

	public function analyze($output = false) {

		$carrera_slug = $this->carreras[ $this->args[0] ];
		$carrera_dir = new Folder('/var/www/proyectitos/material/'.$carrera_slug);
		$finfo = finfo_open(FILEINFO_MIME_TYPE);

		$totalTotal = 0;
		$totalUploaded = 0;

		$missing = array();
		foreach ($carrera_dir->read()[0] as $folderName)
		{

			$folderPath = $carrera_dir->pwd() . '/' . $folderName;

			$existingFolder = $this->Biblioteca->DriveFile->find('first',array('conditions'=>array(
				'DriveFile.title' => $folderName,
				'DriveFile.carrera_id' => $this->Biblioteca->Carrera->id
			)));

			if(empty($existingFolder)) {
				continue;
			} 
			$folder = new Folder($folderPath);
			$files = $folder->find('.*');

			foreach($files as $fileName)
			{

				if($fileName == 'Thumbs.db') 
					continue;
				$filePath = $folderPath . '/' . $fileName;


				$existing = $this->Biblioteca->DriveFile->find('first', array('conditions'=>array( 
					'DriveFile.title' => $fileName, 
					'DriveFile.size' => filesize($filePath), 
					'DriveFile.carrera_id' =>  $this->Biblioteca->Carrera->id )
				) );


				if(empty($existing)) 
				{
					$missing[] = array(
						'file'=> array(
							'name' => $fileName,
							'tmp_name' => $filePath,
							'type' => finfo_file($finfo, $filePath),
							'size' => filesize($filePath),
							'parent_id' => $existingFolder['DriveFile']['id']
						),
					);
				}

			}
		}

		finfo_close($finfo);


		if($output) debug($missing);

		return $missing;
	}


	public function uploadMissing() {
		$missing = $this->analyze();

		// serialize($missing);

		foreach($missing as $file) {

			$filePath = $file['file']['tmp_name'];

			$this->out(date('Y-m-d H:i:s'). ' ___ subiendo ' . $filePath);

			$driveFile = $this->GoogleApi->uploadDriveFile( $file ) ;

				if(!$driveFile)
				{
					$this->out('problema con ' . $filePath . ' error : ' . $this->GoogleApi->errorMessage);
					continue; 
				}
				$this->GoogleApi->setDriveFilePermissions($driveFile->getId(), 'anyone', 'reader');

				$this->Biblioteca->DriveFile->create();
				$file_data = array();
				$file_data['id'] = $driveFile->getId();
				$file_data['title'] = $driveFile->title;
				$file_data['parent_id'] = $file['file']['parent_id'];
				$file_data['url'] = $driveFile->webContentLink;
				$file_data['drive_url'] = $driveFile->getDownloadUrl();
				$file_data['mimeType'] = $file['file']['type'];
				$file_data['size'] = $driveFile->fileSize;
				//$file_data['parent_name'] = $folderName;
				$file_data['carrera_id'] = $this->Biblioteca->Carrera->id;

				$this->Biblioteca->DriveFile->save ( $file_data );

				$this->out('<comment>exito</comment>');

				unset($driveFile);

				// */

		}
	}


}


class GoogleApi {

	public $errorMessage = '';

	private $_defaults = array(
		'services'=> array( 'Drive' ) // enumerate here services that will be used
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


	private $settings;

	public function __construct($credentials) {
		//$this->settings = Set::merge($this->_defaults, $settings);
		if(empty($credentials)) {
			//throw somethingWrong!!
			return false;
		}

		$this->settings['credentials'] = $credentials;

		$this->client = new Google_Client();

		$this->client->setApplicationName( $credentials['application'] );

		$authScopes = array('https://www.googleapis.com/auth/drive'); // TODO: sacar de $this->settings

		$this->_authenticate($credentials, $authScopes);
		
		$this->_instantiateServices( );
	}

	
	private function _instantiateServices(  ) {
			$svcClassName = 'Google_Service_Drive';
			$this->services[ 'Drive' ] = new $svcClassName ($this->client);
	}


	private function _authenticate($credentials, $scopes) {

		$actual_client_id = Configure::read('GoogleApi.client_id');
		if(!empty($actual_client_id)) {
			if($actual_client_id != $credentials['client_id'])
				Configure::write('GoogleApi',array());
		}
		$keysPath = Configure::read('Google.keysPath');
		$key = file_get_contents($keysPath . DS . $credentials['key_filename']);

		$service_token = Configure::read('GoogleApi.service_token');
		if (!empty($service_token)) {
			$this->client->setAccessToken($service_token);
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

		Configure::write('GoogleApi.service_token', $this->client->getAccessToken());
		Configure::write('GoogleApi.client_id', $credentials['client_id']); 
	}

	public function getDriveFiles($options = array()) {

		$_defaults = array(
			'recursive'=> false,
			'trashed' => false,
			'parentId' => null,
			'folders' => false,
			'files'=> true,
			'q' => '', // properties has { key='additionalID' and value='8e8aceg2af2ge72e78' and visibility='PRIVATE' }
			'q_append' => '',
			'raw' => false,
		);
		$options = array_merge($_defaults, $options);
		
		if(empty($options['q'])) { // si se especifica query a mano se ignoran otros 

			$options['q'] = ( $options['trashed'] ) ? 'trashed = true' : 'trashed = false';

			if($options['folders'] && !$options['files']) // solo carpetas
				$options['q'] .= " and mimeType = 'application/vnd.google-apps.folder'";
			if($options['files'] && !$options['folders']) // solo archivos
				$options['q'] .= " and mimeType != 'application/vnd.google-apps.folder'";
			if($options['parentId']) {
				$options['q'] .= " and '".$options['parentId']."' in parents";
			}
			
			// if files AND folders AND recursive : return a beautiful fully-formatted tree (!??!)
		}
		if(!empty($options['q_append'])) $options['q'] .= $options['q_append'];
				
		// TODO: CACHE
		// $cached_queries
		// if (q in cached_queries) return = cache files [ q ]
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
				//'Properties' => $this->getDriveFileProperties( $file->getId() ),
				'Parent' => array(
					'id' => ( $file->getParents()[0]->isRoot ) ? 'root' : $file->getParents()[0]->getId(),
					'title' => $this->getDriveFileTitle($file->getParents()[0]->getId()),
				)
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
	
	// wrapersito
	public function getDriveFolders( $parentId = 'root', $recursive = true) {
		return $this->getDriveFiles( array(
			'recursive' => $recursive,
			'files'     => false,
			'folders'   => true, 
			'parentId'  => $parentId
		));
	}
	
	public function createDriveFolder($title, $options = array()) {
		$_defaults = array(
			'properties' => null,
			'parentId' => null,
		);
		$options = array_merge($_defaults, $options);

		$this->services['Drive'] = new Google_Service_Drive($this->client);
		
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
			}

			$insert_data = array( 'mimeType' => 'application/vnd.google-apps.folder' );
			$this->_file = $this->services['Drive']->files->insert($this->_file, $insert_data);

		} catch( Exception $e) {
			$this->errorMessage = $e->getMessage();
			return false;
		}


		//if (!empty($options['properties']))
		//	$this->setDriveFileProperties($this->_file->getId(), $options['propeties']);

		$return = $this->_file;
		unset($this->_file);
		return $return;
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
			'Properties' => $this->getDriveFileProperties($fileId),
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
	


	public function uploadDriveFile ( $file_data, $properties = array() ) {

		$this->_file = new Google_Service_Drive_DriveFile();
		
		$this->_file->title = (!empty($file_data['title'])) ? $file_data['title'] : $file_data['file']['name'];

		$this->_file->setOriginalFilename($file_data['file']['name']);

		try {
			if (!empty($file_data['parent_id'])) {
				$parent = new Google_Service_Drive_ParentReference();
				$parent->setId($file_data['parent_id']); // ID del file que corresponde a la carpeta donde va
				$this->_file->setParents(array($parent));
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

		//if(!empty($properties))
		//	$this->setDriveFileProperties($id, $properties); 

		
		return $this->_file;
		
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
	
	public function updateDriveFile( $file_data ) {
		try {
			$fileId = $file_data['id'];
			if(empty($fileId))
				throw new Exception('Archivo no especificado');

			$file = $this->getDriveFile($fileId, $raw = true );

			$file->setTitle($file_data['title']);
			$file->setDescription($file_data['description']);
			
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

	public function deleteDriveFiles( $q = null, $safety = true ) {
		
		// THIS METHOD IS EXTREMELY DAN GE ROUS 
		// called with no params equals to delete EVERYTHING
		// so I added a $safety param . you set it to false when you are sure that it may dlet everything
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

	public function setDriveFileProperties($fileId, $properties) {
		foreach($properties as $key => $value) {
			$property = new Google_Service_Drive_Property();
			$property->setKey($key)->setValue($value);
			$this->services['Drive']->properties->insert($fileId, $property);
			unset($property);
		}
	}
	
	
	/*
	 * 
	 * @see https://developers.google.com/drive/v2/reference/permissions
	 * */
	public function setDriveFilePermissions($fileId, $type, $role) {

		$newPermission = new Google_Service_Drive_Permission();
		$newPermission->setType($type);
		$newPermission->setRole($role);
		try {
			$permissions_result = $this->services['Drive']->permissions->insert($fileId, $newPermission);
		} catch (Exception $e) {
			print "An error occurred: " . $e->getMessage();
		}
		return $permissions_result;
	}
	
	public function getDriveFilePermissions($fileId) {
		$permissions = $this->services['Drive']->permissions->listPermissions($fileId);
		return $permissions;		
	}

}



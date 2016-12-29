<?php

App::uses('AppController', 'Controller');

class BibliotecaController extends AppController {

	public $uses = array(
		'DriveFile',
		'Carrera',
	);

	public $components = array('GoogleApi');


	private function detectCarrera() {
		$carrera_id = $this->param('carrera_id');
		if(!$carrera_id) return false;

		$this->Carrera->id = $carrera_id;
		if( ! $this->Carrera->exists() )  return false;
		$this->Carrera->read();
		$this->DriveFile->Carrera = $this->Carrera;

		return true;
	}

	public function beforeFilter() {

		$this->public_actions[] = 'ver';
		parent::beforeFilter();

		$requiresCarrera = array('index','ver','admin_cargar','admin_view','admin_index','admin_edit', 'admin_delete', 'admin_deleteAll', 'admin_debug');

		if ( in_array( $this->action, $requiresCarrera ) ) {
			if( !$this->detectCarrera() ) {
				$this->Components->unload('GoogleApi');

				if(!$this->Auth->user('is_admin') && $this->request->params['prefix'] == 'admin')
					$this->layout = 'default';
				$this->render('/Biblioteca/select_carrera');
				$this->response->send();
				$this->_stop();
				return false;
			}
			$this->GoogleApi->setCredentials( $this->Carrera->getGoogleCredentials() );
		} else {
			$this->Components->unload('GoogleApi');
		}
	}

	public function index() {

		$this->request->params['pass']['carrera_id'] = $this->Carrera->id;
		$this->Prg->commonProcess();

		$parentId = 'root';
		if ($this->param('parentId')) {
			$parentId = $this->param('parentId');
			//$folder = $this->GoogleApi->getDriveFileTitle($parentId);
			$folder = $this->DriveFile->Parent->id = $parentId;
			$folder = $this->DriveFile->Parent->field('title');
			$this->set('folder', $folder);
		}

		$folders = $this->DriveFile->getFoldersByCode();

		$this->Paginator->settings['limit'] = 20;
		//$this->Paginator->settings['order'] = array('DriveFile._code' => 'ASC');
		$search_conds = $this->DriveFile->parseCriteria($this->request->params['named']);
		$this->Paginator->settings['conditions'] = $search_conds;
		$this->Paginator->settings['conditions']['DriveFile.mimeType != '] = 'application/vnd.google-apps.folder';
		$this->Paginator->settings['conditions']['DriveFile.parent_id'] = $parentId;

		if(!empty($search_conds) && $parentId=='root')
			unset($this->Paginator->settings['conditions']['DriveFile.parent_id']);

		$files = $this->Paginator->paginate('DriveFile');

		$carrera = $this->Carrera->data;
		$this->set(compact('folders', 'files', 'carrera', 'parentId'));
	}



	public function ver($fileId) {

		$file = $this->DriveFile->find('first', array('conditions'=>array('DriveFile.id'=>$fileId)));

		$url = $file['DriveFile']['url'];
		$headers = get_headers($url);

		if ( FALSE !== strstr($headers[2], 'Location: https://www.google.com/accounts/ServiceLogin')) {
			$this->GoogleApi->setDriveFilePermissions($fileId, 'anyone','reader');
		}

		if($file['DriveFile']['is_folder'])
			return $this->redirect(array('action'=>'index','carrera_id'=>$this->Carrera->id,'parentId'=>$fileId));

		$title_for_layout = $file['DriveFile']['title'] . ' | ' . $this->Carrera->data['Carrera']['nombre'] .  ' | Biblioteca ';

		$carrera = $this->Carrera->data;

		$this->set(compact('file', 'title_for_layout', 'carrera'));
	}

	public function admin_cargar() {

		$carrera = $this->Carrera->data;

		if ($this->request->is('post')) {

			$uploaded_file = $this->request->data['DriveFile'];

			if(!empty($uploaded_file['materia_id']))
			{ // leer materia
				$materia = $this->Carrera->Materia->read(null,$uploaded_file['materia_id']);

				//if(empty($materia)) que onda ?

			} elseif(!empty($this->request->data['Materia']['nombre']))
			{ // sino, crear materia ...
				$this->Carrera->Materia->create();
				$this->request->data['Materia']['carrera_id'] = $this->Carrera->id;
				if($this->Carrera->Materia->save( $this->request->data['Materia'] ) ) {
					$this->Carrera->Materia->id = $this->Carrera->Materia->getLastInsertID();
					$materia = $this->Carrera->Materia->read();
				} // else ??
			}

			if(empty($materia['Materia']['drive_folder_fileId']))
			{
				$folderName = $materia['Materia']['nombre'];
				$newFolder = $this->GoogleApi->createDriveFolder( $folderName );
				$this->GoogleApi->setDriveFilePermissions($newFolder->getId(), 'anyone', 'reader');

				$this->DriveFile->create();
				$folder_data = array(
					'id' => $newFolder->getId(),
					'title'=> $folderName,
					'mimeType'=>'application/vnd.google-apps.folder',
					'carrera_id'=>$this->Carrera->id,
					'materia_id' => $materia['Materia']['id'],
					'materia_anio' => $materia['Materia']['anio'],
				);
				$this->DriveFile->save($folder_data);

				$this->Carrera->Materia->saveField('drive_folder_fileId', $newFolder->getId());
				$materia['Materia']['drive_folder_fileId'] = $newFolder->getId();
			}

			$uploaded_file['parent_id'] = $materia['Materia']['drive_folder_fileId'];
			$uploaded_file['materia_anio'] = $materia['Materia']['anio'];

			//$file_properties['materia'] = $materia['Materia']['nombre'];
			//$file_properties['materia_id'] = $materia['Materia']['id'];
			//$file_properties['materia_anio'] = $materia['Materia']['anio'];
			//$file_properties['carrera'] = $carrera['Carrera']['slug'];

			$file = $this->GoogleApi->uploadDriveFile( $uploaded_file /* , $file_properties */);
			$this->GoogleApi->setDriveFilePermissions($file->getId(), 'anyone', 'reader');


			if($file != false) {

				$this->DriveFile->create();
				$file_data = $uploaded_file;
				$file_data['id'] = $file->getId();
				$file_data['filename'] = $uploaded_file['file']['name'];
				$file_data['url'] = $file->webContentLink;
				$file_data['drive_url'] = $file->getDownloadUrl();
				$file_data['mimeType'] = $uploaded_file['file']['type'];
				$file_data['size'] = $file->fileSize;
				$file_data['carrera_id'] = $this->Carrera->id;
				$file_data['materia_id'] = $materia['Materia']['id'];
				$file_data['materia_anio'] = $materia['Materia']['anio'];
				$this->DriveFile->save ( $file_data );

				$this->Session->setFlash('Se cargó correctamente el archivo. '
		 .		 'Ver <a target="_blank" href="/biblioteca/'.$carrera['Carrera']['slug'].'/ver/'.$file->getId().'">aqui</a>', 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-success'));

				$this->request->data = array('DriveFile'=>array('materia_id'=>$materia['Materia']['id']));
			} else {
				$this->Session->setFlash('Ocurrió un error: ' . $this->GoogleApi->errorMessage, 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-danger'));
			}
		}
		$materias = $this->Carrera->getMaterias('list');

		$this->set(compact('carrera', 'materias'));

	}

	public function admin_index() {

		$parentId = '';
		if ($this->param('parentId')) {
			$parentId = $this->param('parentId');
			//$folder = $this->GoogleApi->getDriveFileTitle($parentId);
			$folder = $this->DriveFile->Parent->id = $parentId;
			$folder = $this->DriveFile->Parent->field('title');
			$this->set('folder', $folder);
		}

		////$folders = $this->GoogleApi->getDriveFolders( $parentId );
		//$recursive = false;
		//$folders = $this->GoogleApi->getDriveFolders( 'root', $recursive );
		//$files = $this->GoogleApi->getDriveFiles( array('parentId' => $parentId, 'maxResults' => 10, 'page' => $page) );

		$folders = $this->DriveFile->getFolders();

		$this->Paginator->settings['limit'] = 20;
		$this->Paginator->settings['conditions'] = array(
			'DriveFile.mimeType != ' => 'application/vnd.google-apps.folder',
			'DriveFile.parent_id' => $parentId,
		);
		$files = $this->Paginator->paginate('DriveFile');
		//$files = $this->DriveFile->getFiles( $parentId );

		$carrera = $this->Carrera->data;
		$this->set(compact('folders', 'files', 'carrera', 'parentId'));

	}


	public function admin_edit() {

		$fileId = $this->param('id');
		$file = $this->DriveFile->find('first', array('conditions'=>array('DriveFile.id'=>$fileId)));
		$carrera = $this->Carrera->data;


		if( $this->request->is('post')) {

			$uploaded_file = $this->request->data['DriveFile'];

			if(!empty($uploaded_file['materia_id']))
			{ // leer materia
				$materia = $this->Carrera->Materia->read(null,$uploaded_file['materia_id']);

			} elseif(!empty($this->request->data['Materia']['nombre']))
			{ // sino, crear materia ...
				$this->Carrera->Materia->create();
				$this->request->data['Materia']['carrera_id'] = $this->Carrera->id;
				if($this->Carrera->Materia->save( $this->request->data['Materia'] ) ) {
					$this->Carrera->Materia->id = $this->Carrera->Materia->getLastInsertID();
					$materia = $this->Carrera->Materia->read();
				} // else ??
			}

			if(empty($materia['Materia']['drive_folder_fileId']))
			{
				$folderName = $materia['Materia']['nombre'];
				$newFolder = $this->GoogleApi->createDriveFolder( $folderName );
				$folderId = $newFolder->getId();
				$this->GoogleApi->setDriveFilePermissions($folderId, 'anyone', 'reader');

				$this->DriveFile->create();
				$folder_data = array(
					'id' => $folderId,
					'title'=> $folderName,
					'mimeType'=>'application/vnd.google-apps.folder',
					'carrera_id'=>$this->Carrera->id,
					'materia_id' => $materia['Materia']['id'],
					'materia_anio' => $materia['Materia']['anio'],
				);
				$this->DriveFile->save($folder_data);

				$this->Carrera->Materia->saveField('drive_folder_fileId', $folderId);
				$materia['Materia']['drive_folder_fileId'] = $newFolder->getId();
			}

			$uploaded_file['parent_id'] = $materia['Materia']['drive_folder_fileId'];
			$uploaded_file['materia_anio'] = $materia['Materia']['anio'];

			if($this->GoogleApi->updateDriveFile($uploaded_file)) {
				$this->DriveFile->save($this->request->data);
				$this->Session->setFlash('Se actualizó correctamente el archivo. ', 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-success'));
			} else {
				$this->Session->setFlash('Falló la actualización ', 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-danger'));
			}

		} else {
			$this->request->data = $file;
		}
		$materias = $this->Carrera->getMaterias('list');

		$this->set(compact('fileId','file','materias','carrera'));

	}


	public function admin_delete( ) {

		$id = $this->param('id');

		if (!$id) {
			throw new NotFoundException('Archivo inválido');
		}

		$this->DriveFile->id = $id;
		$file = $this->DriveFile->read();

		if ($this->GoogleApi->deleteDriveFile($id)) {

			$this->DriveFile->delete($id);

			$this->Session->setFlash('Eliminado con éxito', 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-success'));
			return $this->redirect(array('action' => 'index', 'admin'=>true,'carrera_id'=>$this->Carrera->id));
		}
		$this->Session->setFlash('No se pudo eliminar: '. $this->GoogleApi->errorMessage, 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-danger'));
		return $this->redirect(array('action' => 'index'));
	}


	public function admin_deleteAll() {

		die('no permitido');

		if($this->GoogleApi->deleteDriveFiles( null, false ) ) {

			$this->DriveFile->deleteAll(array('DriveFile.carrera_id'=>$this->Carrera->id));

			$this->Session->setFlash('Se eliminaron todos los archivos y carpetas', 'alert', array('plugin' => 'BoostCake', 'class' => 'alert-success'));
			$this->redirect(array('action'=>'dashboard')) ;
		}
	}

	public function admin_view() {

		$fileId = $this->param('id');
		if(!$fileId)
			throw new NotFoundException('Archivo inválido');


		$file = $this->GoogleApi->getDriveFile( $fileId ) ;

		if($file['DriveFile']['is_folder'])
			return $this->redirect(array('action'=>'index','carrera_id'=>$this->Carrera->id,'parentId'=>$fileId));

		//$title_for_layout = $file['DriveFile']['title'] . ' | ' . $this->Carrera->data['Carrera']['nombre'] .  ' | Biblioteca ';

		$carrera = $this->Carrera->data;

		$this->set(compact('file', 'title_for_layout', 'carrera'));
		return $this->render('/Biblioteca/ver');
	}

	public function admin_debug() {
		$id = $this->param('id');

//		debug($this->GoogleApi->_setDriveFilePermissions($id, 'anyone', 'reader') );
//		debug($this->GoogleApi->getDriveFiles( ) );
		debug($this->GoogleApi->getDriveFile( $id ) );
		debug($this->GoogleApi->getDriveFilePermissions( $id ) );
		debug($this->GoogleApi->_file);

		$parentId = 'root';
		debug($this->DriveFile->getFiles( $parentId ));
		die;
	}

	public function param($param) {
	/**
	 * busco exhaustivament entre diversos lugares donde puede haberme quedado el param
	 * */
		if(empty($this->request->params[$param])) {
			if(empty($this->request->params['named'][$param])) {
				if(empty($this->passedArgs[$param]))
					return false;
				return $this->passedArgs[$param];
			}
			return $this->request->params['named'][$param];
		}
		return $this->request->params[$param];
	}
}

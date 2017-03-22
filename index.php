<?php
/**
 * Created by PhpStorm.
 * User: paschi
 * Date: 09.02.17
 * Time: 20:53
 */

// require the Harvest API core class
require_once( 'HarvestAPI.php' );

// register the class auto loader
spl_autoload_register( array( 'HarvestAPI', 'autoload' ) );


// wearerequired
// User IDs
// Dominik: 1416494
// Paschi : 1360645
// Client IDs
// required gmbh: 4727107

// USer IDs required
// Dominik:
// Paschi : 1297658


echo "<pre>";


class WeAreRequiredHarvestSync {

	private $api_from 	= null;
	private $api_to 	= null;

	private $user_map 	= array();
	private $project_map = array();
	private $task_map	= array();

	private $synced_entries = array();

	private $translations = array();

	/**
	 * @var int the client_id of required gmbh inside wearerequired harvest account
	 */
	private $required_client_id = 4727107;

	/**
	 * WeAreRequiredHarvestSync constructor.
	 *
	 * @param $from_user
	 * @param $from_pass
	 * @param $from_account
	 * @param $to_user
	 * @param $to_pass
	 * @param $to_account
	 */
	public function __construct( $from_user, $from_pass, $from_account, $to_user, $to_pass, $to_account ) {

		// instantiate the api object
		$this->api_from = new HarvestAPI();
		$this->api_from->setUser( $from_user );
		$this->api_from->setPassword( $from_pass );
		$this->api_from->setAccount( $from_account );
		$this->api_from->setSSL( true );

		$this->api_to = new HarvestAPI();
		$this->api_to->setUser( $to_user );
		$this->api_to->setPassword( $to_pass );
		$this->api_to->setAccount( $to_account );
		$this->api_to->setSSL( true );

		$this->loadProjectMapping();
		$this->loadTaskMapping();
		$this->loadSyncedEntries();

	}

	/**
	 * This will load a json file with already synced time entries
	 */
	private function loadSyncedEntries() {

		$json = file_get_contents( 'synced_entries.json' );
		if ( ! empty( $json ) ) {

			$this->synced_entries = json_decode( $json );
		}

	}

	/**
	 * Save synced entries to file
	 */
	public function saveSyncedEntries() {

		file_put_contents( 'synced_entries.json', json_encode( $this->synced_entries ) );

	}

	/**
	 * Load project id mapping form source to destination account
	 *
	 * @throws Exception
	 */
	private function loadProjectMapping() {

		$project_map = array();

		// try to load the project mappings (id's from - to)
		$r = $this->api_from->getProjects();
		if ( $r->isSuccess() ) {

			/**
			 * @var $project Harvest_Project
			 */
			foreach ( $r->data as $project ) {

				// ignore archived projects
				if ( $project->get( 'active' ) == 'false' ) continue;

				$code = $project->get( 'code' );
				if ( empty( $code ) ) {
					throw new Exception( 'Project does not have a code: ' . $project->get( 'name' ) );
				}

				$project_map[ $project->get( 'code' ) ] = array( 'from' => $project->get( 'id' ) );
				$this->translations['p'.$project->get( 'id' )] = $project->get( 'name' );
			}

		} else {
			throw new Exception( 'yolo' );
		}

		$r = $this->api_to->getProjects();
		if ( $r->isSuccess() ) {

			/**
			 * @var $project Harvest_Project
			 */
			foreach ( $r->data as $project ) {
				if ( array_key_exists( $project->get( 'code' ), $project_map ) ) {
					$project_map[ $project->get( 'code' ) ]['to'] = $project->get( 'id' );
					$this->translations['p'.$project->get( 'id' )] = $project->get( 'name' );
				}
			}

		} else {
			throw new Exception( 'yolo 2' );
		}

		$stop = false;
		foreach ( $project_map as $code => $match ) {
			if ( ! array_key_exists( 'to', $match ) ) {
				printf( 'The following project code does not exist at destination: <strong>%s</strong><br>', $code );
				$stop = true;
			} else {
				$this->project_map[ $match['from'] ] = $match['to'];
			}
		}

		if ( $stop )
			throw new Exception( 'Please fix the above.' );

	}

	/**
	 * Load task mappings from source to destination
	 *
	 * @throws Exception
	 */
	private function loadTaskMapping() {

		$task_map = array();

		// try to load the project mappings (id's from - to)
		$r = $this->api_from->getTasks();
		if ( $r->isSuccess() ) {

			/**
			 * @var $task Harvest_Task
			 */
			foreach ( $r->data as $task ) {

				// ignore archived projects
				if ( $task->get( 'deactivated' ) == 'true' ) continue;

				$task_map[ $task->get( 'name' ) ] = array( 'from' => $task->get( 'id' ) );
				$this->translations['t'.$task->get( 'id' )] = $task->get( 'name' );
			}

		} else {
			throw new Exception( 'Can not fetch tasks from source.' );
		}

		$r = $this->api_to->getTasks();
		if ( $r->isSuccess() ) {

			/**
			 * @var $task Harvest_Task
			 */
			foreach ( $r->data as $task) {
				if ( array_key_exists( $task->get( 'name' ), $task_map ) ) {
					$task_map[ $task->get( 'name' ) ]['to'] = $task->get( 'id' );
					$this->translations['t'.$task->get( 'id' )] = $task->get( 'name' );
				}
			}

		} else {
			throw new Exception( 'Can not fetch tasks from destination.' );
		}

		$stop = false;
		foreach ( $task_map as $name => $match ) {
			if ( ! array_key_exists( 'to', $match ) ) {
				printf( 'The following task does not exist at destination: <strong>%s</strong><br>', $name );
				$stop = true;
			} else {
				$this->task_map[ $match['from'] ] = $match['to'];
			}
		}

		if ( $stop )
			throw new Exception( 'Please fix the above.' );

	}

	/**
	 * Add a user mapping
	 *
	 * @param $from_user_id users id in account from where the data will be synced
	 * @param $to_user_id user id of account to where the data should be synced
	 */
	public function addUserMap( $from_user_id, $to_user_id ) {
		$this->user_map[$from_user_id] = $to_user_id;
	}

	/**
	 * Sync time entries for defined users and given range
	 *
	 * @param Harvest_Range $range
	 * @throws Exception
	 */
	public function syncEntries( Harvest_Range $range ) {

		foreach ( $this->user_map as $from_user_id => $to_user_id ) {

			$r = $this->api_from->getUserEntries( $from_user_id, $range );
			if ( $r->isSuccess() ) {

				/**
				 * @var Harvest_DayEntry $entry_source
				 */
				foreach ( $r->data as $entry_source ) {

					printf(
						'Start syncing entry (%s) of user %s with %s hours on <strong>%s | %s</strong> (to <strong>%s | %s</strong>) at %s<br>',
						$entry_source->get( 'id' ),
						$from_user_id,
						$entry_source->get( 'hours' ),
						$this->translations['p'.$entry_source->get( 'project_id' )],
						$this->translations['t'.$entry_source->get( 'task_id' )],
						$this->translations['p'.$this->project_map[ $entry_source->get( 'project_id' )]],
						$this->translations['t'.$this->task_map[ $entry_source->get( 'task_id' )]],
						$entry_source->get( 'spent_at' )
					); flush();

					$entry_dest = new Harvest_DayEntry();
					$entry_dest->set( 'notes', htmlspecialchars( $entry_source->get( 'notes' ) ) );
					$entry_dest->set( 'spent_at', $entry_source->get( 'spent_at' ) );
					$entry_dest->set( 'hours', $entry_source->get( 'hours' ) );
					$entry_dest->set( 'user_id', $to_user_id );
					$entry_dest->set( 'project_id', $this->project_map[ $entry_source->get( 'project_id' ) ] );
					$entry_dest->set( 'task_id', $this->task_map[ $entry_source->get( 'task_id' ) ] );
					// $entry_dest->set( 'is_closed', false );
					// $entry_dest->set( 'is_billed', $entry_source->get( 'is_billed' ) );

					$source_id = $entry_source->get( 'id' );

					// check if entry already synced
					if ( ! empty( $this->synced_entries->{$source_id} ) ) {

						printf( 'Entry already exist, updating it. ' ); flush();

						// entry was already synced, update it
						$entry_dest->set( 'id', $this->synced_entries->{$source_id} );

						$r = $this->api_to->updateEntry( $entry_dest );
						if ( $r->isSuccess() ) {

							printf( 'done.<br>' ); flush();

						} else {

							var_dump( $entry_dest );
							var_dump( $r );
							$this->saveSyncedEntries();
							throw new Exception( 'Time entry could not be updated.' );

						}

					} else {

						printf( 'Entry does not exist, will create new one. ' ); flush();

						// create new entry
						$r = $this->api_to->createEntry( $entry_dest );

						if ( $r->isSuccess() ) {

							/**
							 * @var $r Harvest_Result
							 */
							printf( '<strong>done</strong>.<br>' ); flush();
							$data = $r->get( 'data' );
							$this->synced_entries->{$source_id} = $data->get( 'id' );

						} else {

							printf( '<strong>Entry Source:</strong>' );
							var_dump( $entry_source );
							printf( '<strong>Entry Dest:</strong>' );
							var_dump( $entry_dest );
							printf( '<strong>Response:</strong>' );
							var_dump( $r );
							$this->saveSyncedEntries();
							throw new Exception( 'Time entry could not be created.' );

						}

					}

					// don't do that too fast. We only have 100 requests per 15 seconds on the harvest API.
					usleep( 100 );

				}

			} else {
				$this->saveSyncedEntries();
				throw new Exception( 'Can not fetch user entries for user ' . $from_user_id );
			}

		}

		$this->saveSyncedEntries();

	}

}

try {

	$sync = new WeAreRequiredHarvestSync( 'USER', 'PASS', 'ACCOUNT', 'USER', 'PASS', 'ACCOUNT' );

	// paschi
	$sync->addUserMap( 1360645, 1297658 );
	// dominik
	$sync->addUserMap( 1416494, 1522042 );

	// sync the last 7 days, except today.
	$range = new Harvest_Range( '2017-03-22', date( 'Ymd', time() - 60*60*24 ) );
	$sync->syncEntries( $range );

} catch( Exception $e ) {

	die( 'Something went wrong :-( : ' . $e->getMessage() );

}

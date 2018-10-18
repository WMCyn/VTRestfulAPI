<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public License, v.2.0.
 * If a copy of the MPL was not distributed with this file, 
 * you can obtain one at http://mozilla.org/MPL/2.0/.
 * 
 * The Original Code is VTRestfulAPI.
 * 
 * The Initial Developer of the Original Code is Jonathan SARDO.
 * Portions created by Jonathan SARDO are Copyright (C). All Rights Reserved.
 */

header("Access-Control-Allow-Origin: *");
header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Content-Range, Content-Disposition, Content-Description');
//header('Access-Control-Max-Age: 1000');

class RestfulApi_Api_Action extends RestFulApi_Rest_Model
{
	protected $module;
	protected $action;
	protected $a_params;
	protected $userId;
	protected $response;	

	protected $inventoryModules = array('Quotes', 'Invoice', 'SalesOrder', 'PurchaseOrder');
    protected $productFields = array('hidtax_row_no', 'hdnProductId', 'comment', 'qty', 'listPrice', 'discount_percentage', 'discount_amount', 'tax1_percentage');
		
	protected function preProcess(Vtiger_Request $request)
	{
		// Get asked module
		$this->module	= $request->get('module');
				
		if($this->module == 'Auth') //Auth
		{
			//Get auth action
			$this->_getAuthAction($request);
		}
		else //Call
		{
			//Get user from token
			$this->_getUserFromToken($request);
			
			// Get asked action
			$this->_getRequestAction($request);
					
			// Get passed parameters
			$this->_getRequestParams($request);
		}
	}
	
	public function process(Vtiger_Request $request)
	{	
		//PreProcess
		$this->preProcess($request);

		//Process
		if(!empty($this->module) && !empty($this->action))
		{
			if($this->module == 'Auth')
			{
				//Login
				$m_result = $this->_login();
			}
			else
			{
				//Call module action
				$m_result = $this->_callModuleAction();
			}
		}
		else
		{
			$this->response("", 406);
		}
		
		//PostProcess
		$this->postProcess($m_result);
	}
	
	protected function postProcess($m_result)
	{
		if($this->action == 'Create' && preg_match('`^[0-9]+`', $m_result))
		{
			$this->response($m_result, 201); //Created
		}
		elseif(is_array($m_result) && isset($m_result["success"]) && $m_result["success"] === false)
		{
			//$this->response($m_result, 200, true); //OK with error
			$this->response($m_result["error"], 200, true); //OK with error
		}
		else
		{
			$this->response($m_result, 200); //OK
		}
	}

	public function response($data, $status, $error=false) //Must be public as in class RestFulApi_Rest_Model
	{
		$this->_code = ($status) ? $status : 200;
		$this->set_headers();
	
		$this->response = new Vtiger_Response();

		if(!$error && ($status == 200 || $status == 201))
		{
			$this->response->setResult($data);
		}
		else
		{
			$this->response->setError($data["code"], $data["message"]);
		}
		
		$this->response->emit();
		
		die(); //To do not launch the following actions
	}

	protected function _getAuthAction(Vtiger_Request $request)
	{
		if($request->has('key'))
		{
			$this->action = 'loginByKey';
			$this->a_params = array("key" => $request->get('key'));
		}
		elseif($request->has('login') && $request->has('password'))
		{
			$this->action = 'login';
			$this->a_params = array("login" => $request->get('login'), "password" => $request->get('password'));
		}
		else
		{
			$this->response("", 406);
		}
	}

	protected function _getUserFromToken(Vtiger_Request $request)
	{
		if(!$request->has('token'))
		{
			$error = array("code" => "TOKEN_NOT_FOUND", "message" => "Token not found");
		}
		else
		{
			$authController = new RestfulApi_Auth_Action();
			$m_result = $authController->checkToken($request->get('token'));
			
			if($m_result["success"] === false)
			{
                            // Retrieve message inside 'error' key 
                            $error = $m_result["error"];
			}
			else
			{
				$this->userId = $m_result["user_id"]; 
			}
		}
		if(!empty($error))
		{
			$this->response($error, 400, true);
		}
	}

	protected function _getRequestAction(Vtiger_Request $request)
	{	
		switch($this->get_request_method())
		{
			//CRUD
			case 'POST':
					if($request->has("id") && $request->get("id") > 0 )
					{
						$this->action = 'Update'; //U
					}
					else
					{
						$this->action = 'Create'; //C
					}
				break;
			case 'GET':
					$this->action = 'Retrieve'; //R
				break;
			case 'PUT':
					$this->action = 'Update'; //U
				break;			
			case 'DELETE':
					$this->action = 'Delete'; //D
				break;
			default:
					$this->action = null;
				break;
		}

	}

	protected function _getRequestParams(Vtiger_Request $request)
	{
		$this->a_params = array();			
		foreach($this->_request as $key => $val)
		{
			if($key != 'module' && $key != 'action' && $key != 'token')
			{
				if(!preg_match('`[^\\\]/`', $val))
				{
					$this->a_params[$key] = str_replace("\/", "/", $val);
				}
				else
				{
					$a_data = explode("/", $val);
					
					for($i=0; $i<count($a_data)-1; $i+=2)
					{
						$param_name = $a_data[$i];
						$param_value = $a_data[$i+1];
						
						$this->a_params[$param_name] = str_replace("\/", "/", $param_value);
					}
				}
			}
			elseif($key == 'action')
			{
				$this->action = $val;
			}
		}
				
		if($this->action == 'Update' || $this->action == 'Delete')
		{
			if($request->has("id"))
			{
				$this->a_params["id"] = $request->get("id");
			}
			else
			{
				$this->response("", 406);
			}
		}
	}

	protected function _login()
	{
		$m_result = null;

		$authController = new RestFulApi_Auth_Action();

		if(!empty($this->a_params["key"]))
		{					
			$m_result = $authController->loginByKey($this->a_params["key"]);
		}
		else
		{
			$m_result = $authController->login($this->a_params["login"], $this->a_params["password"]);
		}

		return $m_result;
	}

	protected function _callModuleAction()
	{
		$moduleInstance = Vtiger_Module::getInstance($this->module);

		if(empty($moduleInstance))
		{
			$this->response("", 404);
		}
		else
		{
			switch($this->action)
			{
				//CRUD

				case 'Create': //C
                                                if($this->module === 'Documents'){
                                                    $m_result = $this->_createDocument();
                                                }
                                                else{
                                                    $m_result = $this->_createItem();
                                                }
						break;

				case 'Retrieve': //R
						$id 					= !empty($this->a_params["id"]) 			? $this->a_params["id"] 			: null;
						$start 					= !empty($this->a_params["start"]) 			? $this->a_params["start"] 			: 0;
						$length 				= !empty($this->a_params["length"]) 		? $this->a_params["length"]			: 20;
						$order 					= !empty($this->a_params["order"]) 			? $this->a_params["order"]			: '';
						$criteria 				= !empty($this->a_params["criteria"]) 		? $this->a_params["criteria"]		: '';
						$picklist 				= !empty($this->a_params["picklist"]) 		? $this->a_params["picklist"]		: '';
						$picklistDependencies 	= !empty($this->a_params["picklistdep"]) 	? $this->a_params["picklistdep"] 	: false;

						if(!empty($id))
						{
							//Unique item
							$m_result = $this->_retrieveItem($id);
						}
						elseif(!empty($picklist))
						{
							//Picklist values
							$m_result = $this->_retrievePickListValues($picklist, $picklistDependencies);
						}
						else
						{
							//Multiples items
							$m_result = $this->_retrieveItems($start, $length, $criteria, $order);
						}
					break;
				
				case 'Update': //U
						$id = !empty($this->a_params["id"]) ? $this->a_params["id"] : null;

						$m_result = $this->_updateItem($id);
					break;
				
				case 'Delete': //D
						$id = !empty($this->a_params["id"]) ? $this->a_params["id"] : null;

						if(!empty($id))
						{
							//Delete item
							$m_result = $this->_deleteItem($id);
						}
					break;
			}
		}

		return $m_result;
	}
        
    protected function base64url_encode($data) { 
      return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); 
    } 
    protected function base64url_decode($data) { 
      return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT)); 
    } 
    
    protected function getMIMEType($filename) {
        $finfo = finfo_open();
        $fileinfo = finfo_file($finfo, $filename, FILEINFO_MIME_TYPE);
        finfo_close($finfo);
        return $fileinfo;
    }

    public function _createDocument() {

        global $adb, $log;
        global $root_directory, $upload_badext;

        $input_array = $this->a_params;

        $log->debug("Entering function _createDocument");
        $log->debug("INPUT ARRAY for the function _createDocument");
        //$log->debug(print_r($input_array), true);
	//$log->debug(json_encode($this->a_params));
                
        $ticketid = $input_array['parentid'];
        $filename = $input_array['filename'];
       // 
        $filesize = $input_array['filesize'];
        $folderid = intval($input_array['folderid'])>0 ? intval($input_array['folderid'])  : 1;
        $filecontents = $input_array['filecontents'];
        $assigned_user_id = $input_array['assigned_user_id'];
        
        if(!isset($assigned_user_id)){
            throw  new Exception('NO_ASSIGNED_USER');
        }

        //decide the file path where we should upload the file in the server
        $upload_filepath = Vtiger_Functions::initStorageFileDirectory();
        $log->debug("upload_filepath is $upload_filepath");

        $attachmentid = $adb->getUniqueID("vtiger_crmentity");

        //fix for space in file name
        $filename = sanitizeUploadFileName($filename, $upload_badext);
        $new_filename = $attachmentid . '_' . $filename;
        $log->debug("new_filename is $new_filename");

        $data = $this->base64url_decode($filecontents);
        //$description = 'CustomerPortal Attachment';

        //write a file with the passed content
        $handle = @fopen($upload_filepath . $new_filename, 'w');
        fputs($handle, $data);
        fclose($handle);
        
        $filetype = $this->getMIMEType($upload_filepath . $new_filename);

        //Now store this file information in db and relate with the ticket
        $date_var = $adb->formatDate(date('Y-m-d H:i:s'), true);
        
        // Creo il documento
        require_once('modules/Documents/Documents.php');
        $focus = new Documents();
        $focus->column_fields['notes_title'] = $filename;
        $focus->column_fields['filename'] = $filename;
        $focus->column_fields['filetype'] = $filetype;
        $focus->column_fields['filesize'] = $filesize;
        $focus->column_fields['filelocationtype'] = 'I';
        $focus->column_fields['filedownloadcount'] = 0;
        $focus->column_fields['filestatus'] = 1;
        $focus->column_fields['assigned_user_id'] = $assigned_user_id;
        $focus->column_fields['folderid'] = $folderid;
        $focus->parent_id = $ticketid;
        $focus->save('Documents');

        // ------------ 1a CREATE ATTACHMENT
        $crmquery = "INSERT INTO `vtiger_crmentity` (`crmid`, `smcreatorid`, `smownerid`, `modifiedby`, 
            `setype`, `description`, `createdtime`, `modifiedtime`,
            `version`, `presence`, `deleted`) 
            VALUES (?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?);";

        $crmresult = $adb->pquery($crmquery, 
                array($attachmentid, $assigned_user_id, $assigned_user_id, $assigned_user_id,
                'Documents Attachment', $filename, $date_var,$date_var,
                0, 1,0
            ));
        if($crmresult === FALSE){
            throw new Exception($adb->database->ErrorMsg(), $adb->database->ErrorNo());
        }
        
        // ------------ 1b CREATE ATTACHEMENT
        $attachmentquery = "insert into vtiger_attachments(attachmentsid,name,description,type,path) values(?,?,?,?,?)";
        $res = $adb->pquery($attachmentquery, 
                array($attachmentid, $filename, $filename, $filetype, $upload_filepath));

        //$relatedquery = "insert into vtiger_seattachmentsrel values(?,?)";
        //$relatedresult = $adb->pquery($relatedquery, array($ticketid, $attachmentid));
       
        // ------------ 2 relate attachment with document
        $related_doc = 'insert into vtiger_seattachmentsrel(crmid,attachmentsid) values (?,?)';
        $res = $adb->pquery($related_doc, array($focus->id, $attachmentid));

        // ----------- 3 relate document with parent entity
        $senotesrel = 'insert into vtiger_senotesrel(crmid, notesid) values(?,?)';
        $res = $adb->pquery($senotesrel, array($ticketid, $focus->id));
        
        $log->debug("Exiting function _createDocument new record id = " . $focus->id);
        
        return $focus->id;
    }

	//Create
	public function _createItem()
	{	
		require_once("modules/{$this->module}/{$this->module}.php"); //Mandatory else there is the following exeption: 'Sorry! Attempt to access restricted file.'

		//New item
		$focus = CRMEntity::getInstance($this->module);
		$focus->mode = '';

		//Set item data
		$this->_setItemData($focus);

		if($this->_isInventory() && !empty($this->a_params["items"]))
		{
			/*Si on est en inventory, on enregistre toutes les lignes d'une meme entité en une fois:
				- soit dans la boucle while()
				- soit apres la boucle pour la fin du fichier

			a chaque iteration dans le while(), on ajoute les lignes produits numérotés dans $lastEntity*/

			$a_items = json_decode($this->a_params["items"]);
			
			$focus->column_fields['totalProductCount'] += count($a_items);

			//Set currency automaticaly if necessary
			if(empty($focus->column_fields['currency_id']))
			{
				$focus->column_fields['currency_id'] = CurrencyField::getDBCurrencyId();
			}

			foreach($a_items as $j => $item)
			{
				$index = $j + 1;

				foreach ($this->productFields as $field) 
				{
					$focus->column_fields[ $field.$index ] = $item->{$field};
				}

				if(!empty($item->discount_percentage) && $item->discount_percentage > 0)
				{
					$focus->column_fields["discount$index"] = "on";
					$focus->column_fields["discount_type$index"] = "percentage";
				}
				elseif(!empty($item->discount_amount) && $item->discount_amount > 0)
				{
					$focus->column_fields["discount$index"] = "on";
					$focus->column_fields["discount_type$index"] = "amount";
				}
			}

			$focusId = $this->_createInventoryEntity($focus->column_fields);
		}
		else //NOT Inventory
		{
			//Save data
			$focus->save($this->module);
			$focusId = $focus->id;
			
		}		

		return $focusId;
	}

	protected function _createInventoryEntity($a_columnFields)
    {
        if(!empty($a_columnFields["discount_percentage_final"]) && $a_columnFields["discount_percentage_final"] > 0)
        {
            $a_columnFields["discount_final"] = "on";
            $a_columnFields["discount_type_final"] = "percentage";
        }
        elseif(!empty($a_columnFields["discount_amount_final"]) && $a_columnFields["discount_amount_final"] > 0)
        {
            $a_columnFields["discount_final"] = "on";
            $a_columnFields["discount_type_final"] = "amount";
        }
        
        $_REQUEST = $a_columnFields;

        $focus = CRMEntity::getInstance($this->module);
        $focus->mode = '';
        $focus->column_fields = $_REQUEST;

        $focus->save($this->module);

        return $focus->id;
    }

	//Retrieve multiple
	protected function _retrieveItems($start=0, $length=20, $criteriaList='', $order='')
	{
		$a_items = array();		
		
		$result = $this->_doRetrieveQuery($start, $length, $criteriaList, $order);

		$db = PearDatabase::getInstance();
		while($row = $db->fetchByAssoc($result))
		{
			$item = $this->_retrieveItem($row["id"], false); //Don't check entity existence because here this existence is obvious (returned by the previous query)

			if(!empty($item))
			{
				$a_items[] = $item;
			}
		}
		
		return $a_items;
	}

	//Retrieve unique
	protected function _retrieveItem($id, $checkModuleEntityExistence=true)
	{
		$m_result = null;

                if($this->module === 'Calendar'){
                    require_once("modules/Calendar/Activity.php");
                }
                else{
                    require_once("modules/{$this->module}/{$this->module}.php");
                }
		
		//Check if an entity exists for the module and the id
		if($checkModuleEntityExistence)
		{
			$db = PearDatabase::getInstance();
                        
                        if($this->module === 'Users'){
                            $query = "select u.*, r.rolename
                                    from vtiger_users u
                                    left join vtiger_userscf cf on u.id = cf.usersid
                                    inner join vtiger_user2role ur on ur.userid = u.id
                                    inner join vtiger_role r on ur.roleid = r.roleid where u.id=?";
                            $result = $db->pquery($query, array($id));
                        }
                        
			else{
                            $query = "SELECT * 
				FROM vtiger_crmentity CE
				WHERE CE.deleted = 0
				AND CE.setype LIKE ?
				AND CE.crmid = ?";
			$result = $db->pquery($query, array($this->module, $id));
                        }
                        
			$count = $db->num_rows($result);
		}

		//If entity exists, retrieve info
		if(!$checkModuleEntityExistence || $count > 0)
		{
			$focus = CRMEntity::getInstance($this->module);
			$focus->retrieve_entity_info($id, $this->module);

			foreach($focus->column_fields as &$field)
			{
				$field = vtlib_purify($field);
			}

			$m_result = $focus->column_fields;
                        
                        $db = PearDatabase::getInstance();
                        
                        /*
                         * If module is Documents then retrieve filecontents and file type
                         */
                        if($this->module == 'Documents'){

                            $db = PearDatabase::getInstance();

                            $res = $db->pquery("select rel.attachmentsid ,path, name , type 
                                    from vtiger_attachments as a
                                    inner join vtiger_seattachmentsrel as rel on rel.attachmentsid = a.attachmentsid
                                    where crmid=?", array($id));
                            $m_result["filecontent"] ='';
                            while($row = $db->fetchByAssoc($res))
                            {
                                global $root_directory;
                               
                                $m_result['filetype'] = $row['type'];
                                $m_result["filecontent"] = $this->base64url_encode(file_get_contents($root_directory .$row['path'] . $row['attachmentsid'] .'_'. $row['name']));
                            }
                            
                            

                        }

                        // Add support for documents
                        $res = $db->pquery(
                                "select n.notesid, note_no, n.title, filename, notecontent, folderid, filetype, 
                                    filesize, u.first_name, u.last_name, c.createdtime,c.modifiedtime, c.smownerid
                                    from vtiger_senotesrel as r 
                                    inner join vtiger_crmentity as c on r.notesid = c.crmid inner 
                                    join vtiger_notes as n on c.crmid = n.notesid and setype='Documents' and c.deleted=0
                                    inner join vtiger_users as u on u.id = c.smownerid
                                    where r.crmid=?", array($id));

                        $m_result["documents"] = array();
                        while($row = $db->fetchByAssoc($res))
                        {
                            $m_result["documents"][] = $row;
                        }

                        // // Add support for comments
                        $res = $db->pquery(
                                "select distinct modcommentsid,commentcontent, c.smownerid, u.first_name, u.last_name, c.createdtime,c.modifiedtime, c.smownerid
                                 from vtiger_crmentity as c inner join vtiger_modcomments as n on c.crmid = n.modcommentsid  
                                 and setype='ModComments' and c.deleted=0 and related_to=? 
                                 inner join vtiger_users as u on u.id = c.smownerid
                                 order by modcommentsid asc", array($id));
                        $m_result["comments"] = array();
                        while($row = $db->fetchByAssoc($res))
                        {
                            $m_result["comments"][] = $row;
                        }
                        
                        // Add support for activity
                        $res = $db->pquery("select a.*
                                from vtiger_crmentityrel r
                                inner join vtiger_crmentity d on r.relcrmid = d.crmid and d.deleted = 0
                                inner JOIN vtiger_activity a on a.activityid = r.relcrmid and r.relmodule='Calendar'
                                where r.crmid = ?", array($id));
                        
                         $m_result["activity"] = array();
                        while($row = $db->fetchByAssoc($res))
                        {
                            $m_result["activity"][] = $row;
                        }

                        $m_result["api_date_now"] = date("Y-m-d H:i:s"); //Added to control the serveur hour
		}

		return $m_result;
	}

	//Retrieve picklist values
	protected function _retrievePickListValues($picklistFieldName, $getDependencies=false)
	{
		$m_result = null;

		require_once("modules/PickList/PickListUtils.php");
		//require_once("modules/PickList/DependentPickListUtils.php");

		$a_translations = $this->_getTranslations();

		$a_values = getAllPickListValues($picklistFieldName, $a_translations);

		$m_result = array(
			"values" => $a_values
		);

		if($getDependencies)
		{
			$m_result["dependencies"] = array();

			//Get field dependencies
			$a_dependancies = Vtiger_DependencyPicklist::getPicklistDependencyDatasource($this->module);

			foreach($a_dependancies as $fieldName => $dependency)
			{
				if($fieldName == $picklistFieldName)
				{
					$m_result["dependencies"] = $dependency;
				}
			}
		}

		return $m_result;
	}

	//Update
	protected function _updateItem($id)
	{
		$m_result = false;
		
		$item = $this->_retrieveItem($id);
		
		if(!empty($item))
		{
			//Get item
			$focus = CRMEntity::getInstance($this->module);
			$focus->retrieve_entity_info($id, $this->module);

			foreach($focus->column_fields as &$field)
			{
				$field = vtlib_purify($field);
			}

			$focus->mode = 'edit';
			$focus->id = $id;

			//Set item data
			$this->_setItemData($focus);
			
			//Save data
			try
			{
				$focus->save($this->module);

				$m_result = $id;
			}
			catch(Exception $e)
			{
				$m_result = $e;
			}
		}

		return $m_result;
	}

	//Delete
	protected function _deleteItem($id)
	{
		$b_deleted = false;

		$item = $this->_retrieveItem($id);

		if(!empty($item))
		{
			$db = PearDatabase::getInstance();
			$query = "UPDATE vtiger_crmentity 
					SET deleted = 1
					WHERE crmid = ?";
			$db->pquery($query, array($id));

			$b_deleted = true;
		}

		return $b_deleted;
	}

	protected function _doRetrieveQuery($start=0, $length=20, $criteriaList='', $order='')
	{
		//Get module database data
		require_once("modules/{$this->module}/{$this->module}.php");

		$moduleName = $this->module;
		$o_module = new $moduleName();
		$a_moduleTables = $o_module->tab_name_index; //List of used tables

		$moduleInstance = Vtiger_Module::getInstance($this->module);
		
		$tableName = $moduleInstance->basetable;
		$tableId = $moduleInstance->basetableid;

                if($moduleName === 'Users'){
                    $order = !empty($order) ? $order : 'u.user_name ASC';
                }
                else{
                    $order = !empty($order) ? $order : 'CE.createdtime ASC';
                }
		

		//Get criteria
		$a_criteriaParams = array();
		$a_criteria = explode(";", $criteriaList);
		if(!empty($a_criteria))
		{
			$criteriaQuery = "1 ";

			foreach($a_criteria as $criteria)
			{
				$a_criteriaFields = explode(":", $criteria);

				//Without operator
				if(count($a_criteriaFields) == 2)
				{
					$field = trim($a_criteriaFields[0]);
					$value = trim($a_criteriaFields[1]);

					if(!empty($field) && !empty($value))
					{
						$criteriaQuery .= "AND $field = ? ";
						
						$a_criteriaParams[] = $value;
					}
					else
					{
						$criteriaQuery .= "AND 0 ";
					}
				}
				//With operator
				elseif(count($a_criteriaFields) > 2)
				{
					$field = trim($a_criteriaFields[0]);
					$operatorStr = trim($a_criteriaFields[1]);
					$value = trim($a_criteriaFields[2]);

					//Securize query
					switch($operatorStr)
					{
						case 'neq':
								$operator = '!=';
							break;
						
						case 'lt':
								$operator = '<';
							break;
						
						case 'gt':
								$operator = '>';
							break;
						
						case 'lte':
								$operator = '<=';
							break;
						
						case 'gte':
								$operator = '>=';
							break;
						
						case 'like':
								$operator = 'LIKE';

						case 'eq':
						default:
								$operator = '=';
							break;
					}

					if(!empty($field) && !empty($operator) && !empty($value))
					{
						$criteriaQuery .= "AND $field $operator ? ";

						$a_criteriaParams[] = $value;
					}
					else
					{
						$criteriaQuery .= "AND 0 ";
					}
				}
			}
		}
                
                if($moduleName === 'Users'){
                    
                    $query = "
                                select u.*, r.rolename
                                from vtiger_users u
                                left join vtiger_userscf cf on u.id = cf.usersid
                                inner join vtiger_user2role ur on ur.userid = u.id
                                inner join vtiger_role r on ur.roleid = r.roleid ";
                }
                else{
                    //Add SELECT clause
                    $query = "SELECT T.$tableId AS id
                                    FROM $tableName T ";				

                    //Add JOIN clauses
                    $query .= "INNER JOIN vtiger_crmentity CE ON CE.crmid = T.$tableId AND CE.deleted = 0 ";
                    if(count($a_moduleTables)>0){
                        foreach($a_moduleTables as $table => $idField)
                        {
                                if($table != 'vtiger_crmentity')
                                {
                                        $query .= "LEFT JOIN $table ON $table.$idField = T.$tableId ";
                                }
                        }
                    }
                }

		

		//Add WHERE, ORDERY BY, LIMIT clauses
		$query .= "WHERE $criteriaQuery
				ORDER BY $order
				LIMIT $start, $length";
echo $query;
                $db = PearDatabase::getInstance();
		$result = $db->pquery($query, array($a_criteriaParams));

		return $result;
	}

	protected function _setItemData(&$focus)
	{
		foreach($this->a_params as $fieldName => $fieldValue)
		{
			if(is_array($fieldValue))
			{
				$focus->column_fields[$fieldName] = $fieldValue;
			}
			else if($fieldValue !== null)
			{
				$focus->column_fields[$fieldName] = decode_html($fieldValue);
			}
		}

		if(empty($focus->column_fields["assigned_user_id"]))
		{
			$focus->column_fields["assigned_user_id"] = $this->userId;
		}
	}

	protected function _getTranslations()
	{
		global $default_language;

		//Get translations
		$userLanguage = Vtiger_Language_Handler::getLanguage();
		$languageStrings = $jsLanguageStrings = array();
		
		$a_translations = array();

        if(file_exists("../../languages/$userLanguage/Vtiger.php")) //User language. Warning: file_exists() do not take in consideration the include path (so we add ../../)
		{
			include("languages/$userLanguage/Vtiger.php"); //CRM default language
		}
		else
		{
			include("languages/fr_fr/Vtiger.php");
		}

        $a_translations = array_merge($languageStrings, $jsLanguageStrings);

        //Module
		if(file_exists("../../languages/$userLanguage/".$this->module.".php")) //User language. Warning: file_exists() do not take in consideration the include path (so we add ../../)
		{
			include("languages/$userLanguage/".$this->module.".php"); //CRM default language
		}
		else
		{
			include("languages/$default_language/".$this->module.".php");
		}
		
		$a_moduleTranslations = array_merge($languageStrings, $jsLanguageStrings);

        foreach($a_moduleTranslations as $label => $translation)
        {
            $a_translations[$label] = vtlib_purify($translation);
        }

		return $a_translations;
	}

	protected function _isInventory()
    {
        return in_array($this->module, $this->inventoryModules);
    }
}
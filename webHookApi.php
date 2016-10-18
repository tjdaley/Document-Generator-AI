<?php

class JDRWH
{
	static $logInfoMsgs = false;
	
	static function setLogInfoMessages($v=true) {self::$logInfoMsgs = $v;}
	static function getLogInfoMessages() { return self::$logInfoMsgs; }
	
	static function dbInsertFormSubmission($mysqli, $object, $table, $skipNulls=true)
	{
		error_log('Saving form submission');
		//First, see if this form permits multiple versions or just one.
		$sql = 'SELECT saveMultiple FROM `wh_forms` WHERE `formId`=?';
		$stmt = $mysqli->prepare($sql);
		$stmt->bind_param('i', $object->formId);
		$stmt->execute();
		$stmt->bind_result($saveMultiple);
		$stmt->fetch();
		$stmt->free_result();
		$stmt->close();
	  
		//If we are not allowed to have multiple versionso of this form, delete the existing one.
		if ($saveMultiple == 0)
		{
			error_log('Not permitted to save multiple');
			$sql = 'DELETE FROM `wh_form_submissions` WHERE `user_id`=? AND `formId`=?';
			$stmt = $mysqli->prepare($sql);
			$stmt->bind_param('ii', $object->user_id, $object->formId);
			$stmt->execute();
		}
		else
		{
			error_log('OK to save multiple');
		}
	
		//Now save the new form submission
		return JDRWH::dbInsert($mysqli, $object, $table, $skipNulls);
	}
  
	static function dbInsert($mysqli, $object, $table, $skipNulls=true)
	{
		error_log('Inserting into database');
		$sql         = 'INSERT INTO `'.$table.'` ';
		$values      = '';
		$columnNames = array();
		$values      = array();

		foreach ($object as $key=>$value)
		{
		  if ($value != null || $skipNulls = false)
		  {
			$columnNames[] = '`'.$key.'`';
			$values[]      = '\''.$mysqli->real_escape_string($value).'\'';
		  }
		}

		$sql .= '('.implode(',',$columnNames).') VALUES '.
				'('.implode(',',$values).')';

		return $mysqli->query($sql);
	}
  

  static function getFormSections($mysqli, $formId)
  {
    $result = array();
    $sql = 'SELECT `form_id` as `formId`, `sectionName`, `saveAsSectionName` FROM `wh_form_sections` WHERE `form_id`=?';
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $formId);
    $stmt->execute();
    $sectionName = false;
    $saveAsName  = false;
	$formId      = -1;
    $stmt->bind_result($formId, $sectionName, $saveAsName);

    while ($stmt->fetch())
    {
      $result[$sectionName] = $saveAsName;
    }

    $stmt->close(); 

    return $result;
  }

  static function xsaveSectionSubmission($mysqli, $oSection)
  {
    //Try to just save it.
    if (JDRWH::dbInsert($mysqli, $oSection, 'wh_form_section_submissions')) return true;

    //If we got a unique key violation, then perform an update
    if ($mysqli->errno == 1062) //Unique key violation (Duplicate Entry)
    {
      $sql = 'UPDATE `wh_form_section_submissions` SET `dateSubmitted`=?, `rawBody`=?, `formSubmissionId`=? '.
             'WHERE `internalName`=? AND `sectionName`=? AND `user_id`=?';
      $stmt = $mysqli->prepare($sql);
      $stmt->bind_param('ssissi', $oSection->dateSubmitted, $oSection->rawBody, $oSection->formSubmissionId,
          $oSection->internalName, $oSection->sectionName, $oSection->user_id);
      $stmt->execute();
      $stmt->close();
      return true;
    }

    return false;
  }

  static function saveSectionSubmission($mysqli, $oSection)
  {
		
	//Don't save sections for User_ID = -1 . . . that's a user that has not logged in and just
	//got a URL from someone else.
	if ($oSection->user_id < 1) return true;
	
	$priorVersionExists = false;
	  
	//Get version of this section that is already in the DB
	$sql = 'SELECT `id`, `rawBody` FROM `wh_form_section_submissions` '.
		   'WHERE `sectionName`=? AND `user_id`=? LIMIT 1';
	$stmt = $mysqli->prepare($sql);
	$stmt->bind_param('si', $oSection->sectionName, $oSection->user_id);
	$stmt->execute();
	$result = $stmt->get_result();
	
	$existingRowId = false;
	$existingBody = false;

	if ($result !== false)
	{
		$row = $result->fetch_assoc();
		$priorVersionExists = true;
		$existingRowId = $row['id'];
		$existingBody = json_decode($row['rawBody']);
	}

	$result->free();
	$stmt->close();
	  
	//If no prior version, just insert this one.
	//Try to just save it.
	if (!$priorVersionExists)
	{
		if (JDRWH::dbInsert($mysqli, $oSection, 'wh_form_section_submissions')) return true;
	}
	
	//Here to update the old json body with the newly submitted data.
	//We might get new fields or just new values or both. We might not get all the values
	//that had previously been saved.
	$newBody = json_decode($oSection->rawBody);
	
	foreach ($newBody as $newKey=>$newValue)
	{
		if (!isset($existingBody->$newKey)) //We have a new key. Just add it
		{
			if (JDRWH::getLogInfoMessages()) error_log("ADDING $newKey = $newValue");
			$existingBody->$newKey = $newValue;
			break;
		}
		
		JDRWH::updateSubsection($existingBody, $newKey, $newValue);
	}
	
	//Now save the revised body into the database.
	$sql = 'UPDATE `wh_form_section_submissions` SET `dateSubmitted`=?, `rawBody`=?, `formSubmissionId`=? '.
           'WHERE `internalName`=? AND `sectionName`=? AND `user_id`=?';
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ssissi', $oSection->dateSubmitted, json_encode($newBody), $oSection->formSubmissionId,
          $oSection->internalName, $oSection->sectionName, $oSection->user_id);
    $stmt->execute();
    $stmt->close();
    return true;
  }
  
	static function updateSubsection($object, $newKey, $newValue)
	{
		//If the corresponding value is not an array, then just update it and return.
		//If it is an array, then recursively call this method to update the array.
		$valueType = gettype($object->$newKey);
		if ($valueType != 'array' && $valueType != 'object')
		{
			if (self::$logInfoMsgs) error_log("UPDATING SINGLE VALUE $newKey = $newValue");
			$object->$newKey = $newValue;
			return;
		}
		
		if (self::$logInfoMsgs) error_log("UPDATING $valueType VALUE $newKey");
		foreach ($newValue as $innerKey=>$innerValue)
		{
			JDRWH::updateSubsection($object->$newKey, $innerKey, $innerValue);
		}
	}
}
?>

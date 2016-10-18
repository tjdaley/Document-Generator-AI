<?php
include 'smarty-3.1.27/libs/Smarty.class.php';
include_once 'configuration.php';
include_once 'mailer.php';

$config = new Configuration();
$mailer = new Mailer($config);
date_default_timezone_set($config->TIMEZONE);
$mysqli = dbConnect();

logError('Starting (smarty)');
$requestCount = 0;

/*
 * Dequeue document generation requests. For each request
 *    1. generate the requested documents
 *	  2. save the document to disk
 *    3. email the document to the next person who should see it
 *    4. remove the request from the queue
 *    5. archive the generated document.
 *
 * Check out config.txt for parameters that control how this
 * program operates. One of those parameters is REQUESTMAX.
 * This script will only process REQUESTMAX queue entries each
 * time it runs. That prevents runaway processing trains that
 * might be caused by queueing errors. If REQUESTMAX is set to
 * zero, it is effectively ignored and this script will process all
 * queued items on each run.
 */
while (($document = getQueuedRequest()))
{
	$requestCount++;
	
	if ((generateDocuments($document)))
	{
		if ((saveDocuments($document)))
		{
			if (sendDocuments($document))
			{
				removeQueueRequest($document);
				archiveDocuments($document);
			}
			else
				moveRequestToErrorQueue($document, 'PENDING');
		}
		else
			moveRequestToErrorQueue($document);
	}
	else
	{
		moveRequestToErrorQueue($document);
		logError('Unable to process request id '.$document->getQueuedRequestId());
	}
	
	if ($requestCount >= $config->REQUESTMAX && $config->REQUESTMAX > 0)
	{
		logError('Quitting after '.$requestCount.' requests');
		break;
	}
}
logError('Ending - Processed '.$requestCount.' requests.');
exit();

/*
 * Send the generated document to an email address specified in the
 * queued request.
 */
function sendDocuments($document)
{
	global $config;
	global $mailer;
	$result = true;
	$filename = $document->getFilename();
	$mailer->Subject = 'JDReview.com - Completed Document';
	$mailer->Body = '<h1>JDReview.com</h1><p>Your completed document is attached.</p>';
	$mailer->IsHTML();
	$mailer->AddAddress($document->getQueuedRequest()['emailTo']);
	$mailer->AddAttachment($config->GENERATEDIR.'/'.$filename);
	
	try
	{
		$result = $mailer->Send();
	}
	catch(Exception $e)
	{
		$result = false;
		$message = $e->getMessage();
		$document->setMessage($message);
		logError('Error sending queue id '.$document->getQueuedRequestId.': '.$message.'. Will retry');
	}
	return $result;
}

/*
 * Move the generated document to an archive folder, specified in config.txt.
 * Another job should come along and clean up the archive folder periodically.
 */
function archiveDocuments($document)
{
	global $config;
	$filename = $document->getFilename();
	$fromFile = $config->GENERATEDIR.'/'.$filename;
	$toFile   = $config->ARCHIVEDIR.'/'.$filename;
	$status   = rename($fromFile, $toFile);
	logError(($status ? "Succeeded " : "Failed " )."in moving $fromFile to $toFile");
	return $status;
}

/*
 * Save the generated document to disk to be used as an attachment to
 * an email.
 */
function saveDocuments($document)
{
	global $config;
	
	$documentContents = $document->getContents();
	$submission = $document->getSubmission();
	$form       = $document->getForm();
	$request    = $document->getQueuedRequest();
	$json       = json_decode($submission['rawBody']);
	
	$outputFile = $request['formSubmissionId'] . '-' . $form['formFile'];
	$document->setFilename($outputFile);
	
	try
	{
		$myfile = fopen($config->GENERATEDIR.'/'.$outputFile, 'w') or die("Unable to open file!");
		fwrite($myfile, $documentContents);
		fclose($myfile);
	}
	catch(Exception $e)
	{
		$message = 'Error writing to '.$config->GENERATEDIR.'/'.$outputFile.': '.$e->getMessage();
		$document->setMessage($message);
		logError($message);
		return false;
	}
	
	return true;
}

/*
 * Function to merge a template file with data submitted by the user
 * to create a finished document.
 *
 * Each form definition in the database (wh_forms) will be linked to
 * zero or more sections of data (wh_form_sections). Each form submission
 * will contain at least one data section. There may be other data sections
 * in the database that have already been submitted by the user.
 *
 * What this function does is first merge every section that was included
 * in the wh_form_submission into the template. Then, if there are any data
 * sections called for that have not been merged, it will go look for data
 * that may have been previously saved by the user, as a supplement.
 *
 * Here's an example. Let's say a wh_form_sections tells us that form_id 1
 * takes three sections: AboutYou, AboutYourCase, and SubjectProperty. So the
 * template form wants data from all three of these data sections. Now say
 * the user interface sends us a form generation request for a special warranty
 * deed and that form generation request (row in wh_form_submissions) only
 * contains AboutYou and SubjectProperty sections. Let's further assume that
 * the user has previously filled out a different HTML form and saved an AboutYou
 * section to the database in wh_form_section_submissions. First, the AboutYou
 * and SubjectProperty sections will be merged in from the form submission.
 * Then this script to realize that the AboutYourCase section is needed but
 * not provided. So then it will go see if you have EVER submitted an AboutYourCase
 * section. If so, it will retrieve that section and merge it into the template
 * to complete the form.
 */
function generateDocuments($document)
{
	global $config;
	$result = true;
	
	$request = $document->getQueuedRequest();
	
	$submission = getFormSubmission($request['formSubmissionId']);
	$form       = getForm($submission['formId']);
	
	//$sections contains one entry for each data section required by the template.
	$sections	= getFormSections($submission['formId']);
	
	//$json will contain each data section that has just arrived from the user.
	//It may contain more or fewer sections than $sections.
	$json       = json_decode($submission['rawBody'], true);

	//Smarty is what we use to merge the templates with user data.
	$smarty = new Smarty;
	$smarty->left_delimiter = '[['; 
	$smarty->right_delimiter = ']]'; 

	//Construct the template file name
	$templateFile = $config->FORMSDIR.'/'.$form['formFile'];

	//Loop through each section provided by the form
	//submission. 
	foreach($json as $key=>$value)
	{
		$smarty->assign($key, $value);
		
		//Indicate that we have merged this required data section.
		unset($sections[$key]);
	}
	
	//If there is any missing data, try to find it from a prior
	//submission.
	$userId = $submission['user_id'];
	foreach($sections as $key=>$value)
	{
		if (($data = getUserFormSectionData($userId, $key)))
		{
			$smarty->assign($key, $data);
		}
	}
	
	//Finalize the merge operation.
	try
	{
		$output = $smarty->fetch($templateFile);
		$document->setSubmission($submission);
		$document->setForm($form);
		$document->setContents($output);
	}
	catch(Exception $e)
	{
		$message = $e->getMessage();
		$document->setMessage($message);
		logError($message);
		$result = false;
	}

	return $result;
}

function getFormSubmission($id)
{
	$sql = 'SELECT * FROM wh_form_submissions WHERE `id`='.$id.' LIMIT 1';
	return getFirstRecord($sql);
}

function getForm($formId)
{
	$sql = 'SELECT * FROM wh_forms WHERE `formId`='.$formId.' LIMIT 1';
	return getFirstRecord($sql);
}

function getFormSections($formId)
{
	global $mysqli;
	$sections = array();
	$sql = 'SELECT * from wh_form_sections WHERE `form_id`='.$formId;
	
	if (!($result = $mysqli->query($sql)))
	{
		logError('Query failed: )'. $mysqli->errno . ') ' . $mysqli->error);
		return false;
	}
	
	while (($row = $result->fetch_assoc()))
	{
		$sections[$row['sectionName']] = $row;
	}
	
	return $sections;
}

/*
 * When a form is submitted, it should have all the data it needs. However, some forms in
 * the template library might refer to sections that are not in the Form UI. For these sections
 * we look for a prior user submission of that same section from a different form.
 *
 * For example, we almost use the 'AboutYou' section in almost every template. If the database
 * tells us that the template needs the 'AboutYou' section (through wh_form_sections), but
 * the UI designer did not include that section in his or her HTML form, then the 'AboutYou'
 * data will not be included in the wh_form_submission rawBody of data. In such a case we look
 * to see if the same user has EVERY submitted an 'AboutYou' section as part of another HTML form.
 * If so, we'll lookup that section, decode it and use it.
 */
function getUserFormSectionData($userId, $sectionName)
{
	global $mysqli;
	$sectionData = false;
	$error = false;
	$sql = 'SELECT `rawBody` FROM `wh_form_section_submissions` '.
	       'WHERE `user_id`='.$userId." AND `sectionName`='".$sectionName."' ".
		   'ORDER BY `id` LIMIT 1';
	$row = getFirstRecord($sql);
	if (!$row) return false;
	
	try
	{
		$sectionData = json_decode($row['rawBody'], true);
	}
	catch(Exception $e)
	{
		$error = true;
	}
	
	if ($error || $sectionData === NULL)
	{
		$msg = "Error decoding json data for section '$sectionName', user_id=$userId :". $json_errors[json_last_error()] . ' ('. $row['rawBody'].')';
		logError($msg);
		return false;
	}
	
	return $sectionData;
}

/*
 * Just a utility method to get the first record of an SQL query loaded into an
 * associative array.
 */
function getFirstRecord($sql)
{
	global $mysqli;
	
	if (!($result = $mysqli->query($sql)))
	{
		logError('Query failed: ('. $mysqli->errno . ') ' . $mysqli->error);
		return false;
	}
	
	$result->data_seek(0);
	
	if (!($row = $result->fetch_assoc()))
		return false;
	return $row;
}

/*
 * Pops a form generation request from the queue for processing. For now,
 * the queue is implemented as a DB table and this program is called
 * periodically through a cron job. In the future we could implement the queue
 * through a TCP socket, a FIFO, a JMS queue, or some other more responsive
 * mechanism.
 */
function getQueuedRequest()
{
	global $mysqli;
	
	$sql = 	'SELECT * '.
			'FROM wh_generation_queue q '.
			'WHERE dateCompleted IS NULL AND `queueName`='."'PENDING' ".
			'ORDER BY q.`id` ASC '.
			'LIMIT 1';
			
	$document = new Document();
	$request = getFirstRecord($sql);
	
	if (!$request)
	{
		return false;
	}
	
	$document->setQueuedRequest($request);
	$document->setQueuedRequestId($request['id']);
	
	return $document;
}

/*
 * A request stays in the queue until we successfully process it. Once
 * it is successfully processed, the queued request is removed from the
 * queue.
 */
function removeQueueRequest($document)
{
	global $mysqli;
	$id = $document->getQueuedRequestId();
	$now = '\''.date('Y-m-d H:i:s').'\'';

	$sql =	'UPDATE wh_generation_queue q '.
			'SET dateCompleted = '.$now .', `queueName`='."'DONE' ".
			'WHERE q.`id`='.$id;
			
	if (!$mysqli->query($sql))
	{
		logError('Update failed: ('. $mysqli->errno . ') ' . $mysqli->error."($sql)");
		return false;
	}
	
	return true;
}

/*
 * If we could not process the request, move it to the error queue.
 */
function moveRequestToErrorQueue($document, $queue='ERROR')
{
	global $mysqli;
	$id = $document->getQueuedRequestId();
	$now = '\''.date('Y-m-d H:i:s').'\'';

	$sql =	'UPDATE wh_generation_queue q '.
			'SET dateCompleted = '.$now .', `queueName`='."'".$queue."', `message`='". 
			$mysqli->real_escape_string($document->getMessage()) . "' ".
			'WHERE q.`id`='.$id;
			
	if (!$mysqli->query($sql))
	{
		logError('Update failed: ('. $mysqli->errno . ') ' . $mysqli->error."($sql)");
		return false;
	}
	
	return true;
}

/*
 * Utility function to connect to the MYSQL database.
 */
function dbConnect()
{
	global $config;
	$dbhost     = $config->DBHOST;
	$dbusername = $config->DBUSERNAME;
	$dbpassword = $config->DBPASSWORD;
	$dbname     = $config->DBNAME;

	$mysqli = new mysqli($dbhost, $dbusername, $dbpassword, $dbname);

	if ($mysqli->connect_errno)
	{
		logError('Failed to connect to MYSQL ('.$dbname.'): '.$mysqli->connect_errno.' - '.$mysqli->connect_error);
		die;
	}
	
	return $mysqli;
}

/*
 * Utility function to log an error message.
 */
function logError($message, $level = 3)
{
	global $config;
	echo(date($config->LOGTIMESTAMPFORMAT).': '.$message."\n");
}

/*
 * The Document class collects information about the document being generated as
 * we work through the document generation process.
 */
class Document
{
	private $items = array();

	function __get($id) { return $this->items[ $id ]; }
	function __set($id,$v) { $this->items[ $id ] = $v; }
	
	function setQueuedRequestId($id) { $this->items['QueuedRequestId'] = $id; }
	function getQueuedRequestId() { return $this->items['QueuedRequestId']; }
	function setQueuedRequest($req) { $this->items['Request'] = $req; }
	function getQueuedRequest() { return $this->items['Request']; }
	function setContents($v) { $this->items['Contents'] = $v; }
	function getContents() { return $this->items['Contents']; }
	function setForm($v) { $this->items['Form'] = $v; }
	function getForm() { return $this->items['Form']; }
	function setSubmission($v) { $this->items['Submission'] = $v ; }
	function getSubmission() { return $this->items['Submission']; }
	function setFilename($v) { $this->items['Filename'] = $v; }
	function getFilename() { return $this->items['Filename']; }
	function setMessage($v) { $this->items['Message'] = $v;}
	function getMessage() { return $this->items['Message'];}
}
?>
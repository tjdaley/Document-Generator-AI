<?php
include_once 'webHookApi.php';
include_once 'tjd-configuration.php';

$config 	= new Configuration();
$dbhost     = $config->DBHOST;
$dbusername = $config->DBUSERNAME;
$dbpassword = $config->DBPASSWORD;
$dbname     = $config->DBNAME;
$logInfoMsgs= ($config->LOGINFOMESSAGES == 'Y');
JDRWH::setLogInfoMessages($logInfoMsgs);

date_default_timezone_set($config->TIMEZONE);

$json = file_get_contents('php://input');

if (empty($json)) die;

$payload = json_decode($json);

if ($payload == null && json_last_error() != JSON_ERROR_NONE)
{
  echo 'Error reading JSON: '.json_last_error();
}

$mysqli = new mysqli($dbhost, $dbusername, $dbpassword, $dbname);

if ($mysqli->connect_errno)
{
  echo 'Failed to connect to MYSQL ('.$dbname.'): '.$mysqli->connect_errno.' - '.$mysqli->connect_error;
  die;
}

/*
 * FIRST: Save the form data to our database. If all else fails, we have the data here.
 */

if ($logInfoMsgs)file_put_contents('php://stderr', print_r($payload, TRUE));
$userId = $payload->AboutYou->UserID;

$oFormSubmission = new stdClass();

$oFormSubmission->internalName  = $payload->Form->InternalName;
$oFormSubmission->formId        = $payload->Form->Id;
$oFormSubmission->entryId       = $payload->Entry->Number;
$oFormSubmission->entryNumber   = $payload->Id;
$oFormSubmission->origin        = json_encode($payload->Entry->Origin);
$oFormSubmission->rawBody       = $json;
$oFormSubmission->dateSubmitted = $payload->Entry->Timestamp;
$oFormSubmission->user_id       = $userId;

JDRWH::dbInsertFormSubmission($mysqli, $oFormSubmission, 'wh_form_submissions');
$submissionId = $mysqli->insert_id;

/*
 * NEXT: Save each section (that we want) to the wb_form_section_submissions table. Forms
 * are pre-populated from this table and we look to that table for missing values when
 * documents are being generated.
 */

$dbSections = JDRWH::getFormSections($mysqli, $payload->Form->Id);

$oSection = new stdClass();

foreach ($dbSections as $key=>$value)
{

  if (property_exists($payload, $key))
  {
    $saveAs = explode('::', $value, 2);

    $internalName = $payload->Form->InternalName;
    $sectionName  = $value;
    if (array_key_exists(0, $saveAs)) $internalName = $saveAs[0];
    if (array_key_exists(1, $saveAs)) $sectionName  = $saveAs[1];

    $oSection->internalName     = $internalName;
    $oSection->sectionName      = $sectionName;
    $oSection->user_id          = $userId;
    $oSection->dateSubmitted    = $payload->Entry->Timestamp;
    $oSection->rawBody          = json_encode($payload->$key);
    $oSection->formSubmissionId = $submissionId;

    JDRWH::saveSectionSubmission($mysqli, $oSection);
  }
}

//Now queue the document building request
$now = date('Y-m-d H:i:s');
$toEmail = 'tjd@powerdaley.com';
if (!isset($payload->Payment->AttorneyReview) || $payload->Payment->AttorneyReview == false)
{
	$toEmail = $payload->AboutYou->Email;
}

//TODO Fix SQL Injection Vulnerability
$sql = 'INSERT INTO wh_generation_queue (`dateSubmitted`, `formSubmissionId`, `emailTo`, `queueName`) '.
       "VALUES ('$now', $submissionId,'$toEmail','PENDING')";
if (!$mysqli->query($sql))
	error_log("Error queueing document request ($sql) ".$mysqli->error);
?>

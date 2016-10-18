#!/usr/bin/php
<?php
include_once 'configuration.php';

$filename = $argv[1];

//Read in the source file to be cleaned
$myfile = fopen($filename, 'r');
if (!($contents=fread($myfile,filesize($filename))))
	die ('Could not read file.');

fclose($myfile);

//Extract SMARTY expression or field from MAILMERGE field
//I use MAILMERGE fields because they are self-contained
//(as long as you find balanced braces) and the document is
//not harmed by their removal.
$outText = '';
$index = strpos($contents, '{\field');
while ($index !== FALSE)
{
	//Extract the document UP TO the location of the search pattern.
	$goodContent = substr($contents, 0, $index);
	
	//Extract everything that follows the starting position of the
	//search pattern instance we just located.
	$contents    = substr($contents, $index);
	
	//Loop through the reaming contents until we find the "{" that
	//closes the one that started the pattern.
	$openBraces = 1;
	$i = 0;
	$length = strlen($contents);
	
	while ($i < $length && $openBraces > 0)
	{
		$i++;
		$myChar = substr($contents, $i, 1);
		if ($myChar == '{')
			$openBraces++;
		else if ($myChar == '}')
			$openBraces--;
	}
	
	//$i is the index of the brace that matches the starting brace.
	$field = substr($contents, 0, $i+1);
	$contents = substr($contents, $i+1);
	//echo substr($contents, 0, 30)."\n";
	
	//echo $field . '  -->  ';
	$field = cleanField($field);
	//echo "\t\t$field\n\n";
	//echo "$field\n";
	$outText .= $goodContent . $field;
	$index = strpos($contents, '{\field');
}

$outText.=$contents;

//Write out the cleaned up RTF code
$outfile = $filename.'-clean.rtf';
if (!($myfile = fopen($outfile, 'w')))
	die ('Could not open '.$outfile.' for write');
fwrite($myfile, $outText);
fclose($myfile);

//Function to remove RTF code from a merge field
// Sample Input:  {\field{\*\fldinst {\rtlch\fcs1 \af0\afs28 \ltrch\fcs0 
//                \f0\fs28\insrsid857795  MERGEFIELD  [[$AboutYou.Name.First]]  
//				  \\* MERGEFORMAT }}{\fldrslt {\rtlch\fcs1 \af0\afs28 \ltrch\fcs0 
//				  \f0\fs28\lang1024\langfe1024\noproof\insrsid857795 
//				  \'ab[[$AboutYou.Name.First]]\'bb}}}
// Sample Output: [[$AboutYou.Name.First]]
function cleanField($field)
{
	$origField = $field;
	
	$cleanedField = '';
	//Remove all new lines, whether of Windows or Unix origin, and escaped double-quotes
	$trans = array("\x0D" => '', "\n"=>'', '\\\\"' => '"');
	$field = strtr($field, $trans);
	
	//There are other RTF field codes other than our MERGEFIELD codes, such as page numbers.
	//We don't want to mess with stuff that is not ours, so look for the word MERGEFIELD. If
	//it's not there, assume this is someone else's field code and just send it back.
	if (strpos($field, 'MERGEFIELD', 0) === FALSE) return $origField;	//Return any field that's not a mergefield
	if (strpos($field, '[[', 0) === FALSE) return $origField;			//Return any mergefield that does not have our delimiters
	
	//Extract formatting codes that precede the field
	$pattern = '/\\\\\*\\\\fldinst\s*{([\s\S]*)\s*MERGEFIELD/';
	preg_match($pattern, $field, $matches);
	if (count($matches) > 1) $formatCodes = trim($matches[1]); else $formatCodes = '';
	
	//echo '====================================================================================='."\n";
	//echo $field."\n";
	//echo 'vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv'."\n";
	
	//Look for merge formatting macros that WORD lays out there
	$msWordCodes = array('\\\\* Upper'=>'\caps', '\\\\* Lower'=>'\scaps', '\\\\* FirstCap'=>'', '\\\\* Caps'=>'');
	foreach ($msWordCodes as $code=>$rtf)
	{
		$field = str_replace($code, '', $field, $Count);
		if ($Count > 0)
		{
			$formatCodes.=$rtf;
		}
	}
	
	//echo $field."\n";
	//echo '-------------------------------------------------------------------------------------'."\n\n";
	
	//Remove Microsoft Word MERGEFIELD stuff
	//$pattern = '/MERGEFIELD\s*"?([\s\S]*]])"?\s*\\\\\\\\\* MERGEFORMAT/'; //What we want to keep
	$pattern = '/MERGEFIELD\s*"?([\s\S]*]])"?[\s\S]*\* MERGEFORMAT/';
	preg_match($pattern, $field, $matches);
	//print_r($matches);
	if (count($matches) > 1)
	{
		$cleanedField = trim($matches[1]);
	}
	else
	{
		echo "CORRUPT FIELD TAGS OR IMPROPER FIELD DELIMITERS: $field\n\n";
	}
	
	//Despite all our efforts, there may still be some RTF code in the field. Maybe the user tries
	//to bold one letter in the mergefield. Who knows. Here we remove any remaining RTF code.
	$pattern = '/\\\\\S*\s/';
	$cleanedField = preg_replace($pattern, '', $cleanedField);
	echo "$cleanedField\n\n";
	$cleanedField = '{'.$formatCodes . ' ' . $cleanedField .'}';
	
	return $cleanedField;
}
?>

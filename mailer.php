<?php
include_once 'configuration.php';
include_once '/var/www/libraries/vendor/phpmailer/phpmailer/PHPMailerAutoload.php';
include_once '/var/www/libraries/vendor/phpmailer/phpmailer/class.phpmailer.php';

class Mailer
{
	private $config  = false;
	private $mail    = false;
	private $options = array('Subject', 'Body', 'MsgHTML', 'AltBody');
	
	function __construct($config) 
	{ 
		$this->config  = $config;
		
		$mail = new PHPMailer();
		
		$mail->IsSMTP();
		$mail->Host = $this->config->SMTPHOST;
		$mail->SMTPDebug = $this->config->SMTPDEBUG;
		$mail->SMTPAuth = true;
		$mail->Port = $this->config->SMTPPORT;
		$mail->Username = $this->config->SMTPUSERNAME;
		$mail->Password = $this->config->SMTPPASSWORD;
		$mail->SetFrom($this->config->SMTPFROM, $this->config->SMTPFROMNAME);
		$mail->AddReplyTo($this->config->SMTPREPLYTO, $this->config->SMTPREPLYTONAME);
		
		$this->mail = $mail;
	}
	
	function __set($option,$v) 
	{ 
		if (in_array($option, $this->options))
		{
			$this->mail->$option = $v;
		}
		else
		{
			throw new Exception("mailer.php - Invalid property '$option'");
		}
	}
	
	function IsHTML($v=true) { $this->mail->IsHTML($v); }
	
	function AddAddress($address, $name=false)
	{
		if (!$name)
			$this->mail->AddAddress($address);
		else
			$this->mail->AddAddress($address, $name);
	}
	
	function AddAttachment($filename)
	{
		$this->mail->AddAttachment($filename);
	}
	
	function Send()
	{
		if (!$this->mail->Send())
		{
			throw new Exception ('mailer.php - '.$this->mail->ErrorInfo);
		}
		
		return true;
	}
}
?>
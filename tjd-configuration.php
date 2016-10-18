<?php
/*
 * Class Configuration
 *
 * Provides functions to retrieve, set, and save application-specific configuration data.
 * I have modified the original so that it skips blank lines and so that it saves and items
 * newly added throught the __set method when you call the save method.
 *
 * Thomas J. Daley, September 15, 2015
 * McKinney, Texas
 *
 * Original version by Jack D. Herrington (jherr@pobox.com)
 * Original version date: 2006-Aug-29
 *
 * Revised by Thomas J. Daley (tjd@powerdaley.com)
 * Revision date: 2015-Sep-19
 *
 * Source: http://www.ibm.com/developerworks/library/os-php-config/index.html
 */
class Configuration
{
  private $configFile = 'config.txt';

  private $items = array();

  function __construct() { $this->parse(); }

  function __get($id) 
  {
	  if (!array_key_exists($id, $this->items))
	  {
		  error_log("key '$id' does not exist in ".$this->configFile);
		  return null;
	  }
	  
	  return $this->items[ $id ]; 
  }
  
  function __set($id,$v) { $this->items[ $id ] = $v; }

  /**
   * parse() - read the configuration file on startup and
   * cache the results. NOTE, based on the regular expression
   * used to separate the key from the value, you should not
   * put any whitespace around the equal sign:
   *
   * GOOD:	KEY=value
   * BAD:	KEY = value
   */
  function parse()
  {
    $fh = fopen( $this->configFile, 'r' );
    while( $l = fgets( $fh ) )
    {
      if ( preg_match( '/^#/', $l ) == false )
      {
        preg_match( '/^(.*?)=(.*?)$/', $l, $found );
        if (array_key_exists(2, $found))
		{
			$this->items[ trim($found[1]) ] = trim($found[2]);
		}
      }
    }
    fclose( $fh );
  }
  
  /**
   * save() - Save updated values to the configuration file.
   *
   * What this does is read the configuration file. For each line,
   * if the line is in the form KEY=VALUE, then use the KEY read in
   * to lookup the value in the $items cache (which may have been
   * modified during this run), and write the new value to a new
   * version of the file.
   */
  function save()
  {
    $nf = '';
    $fh = fopen( $this->configFile, 'r' );
    while( $l = fgets( $fh ) )
    {
      if ( preg_match( '/^#/', $l ) == false )
      {
        preg_match( '/^(.*?)=(.*?)$/', $l, $found );
        if (array_key_exists(2, $found))
		{
			$nf .= $found[1].'='.$this->items[$found[1]]."\n";
			unset($this->items[$found[1]]); //remove found items so we can save new items added.
		}
		else
			$nf .= $l;
      }
      else
      {
        $nf .= $l;
      }
    }
    fclose( $fh );
    copy( $this->configFile, $this->configFile.'.bak' );
    $fh = fopen( $this->configFile, 'w' );
    fwrite( $fh, $nf );
	
	//save newly added items.
	if (array_filter($this->items))
	{
		$today = date('Y-m-d H:i:s');
		$nf = "\n## ITEMS NEWLY ADDED $today\n##\n";
		foreach ($this->items as $key=>$value)
		  $nf .= "$key=$value\n";
		fwrite( $fh, $nf );
	}
	
    fclose( $fh );
  }
}

/*
 * SAMPLE USAGE
 *
	$c = new Configuration();
	echo( $c->TemplateDirectory."\n" );
	$c->TemplateDirectory = '/home/tom';
	$c->save();
 */
?>
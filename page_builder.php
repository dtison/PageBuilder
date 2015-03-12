<?php
/* 
    PageBuilder - CMS that implements strict Separation of Concerns
    

*/
/*  Parent class for all these fragment-based pages  */

#  Constants 
define ("VALIDATE_JS", "<script type=\"text/javascript\" src=\"/ecps/javascript/validate.js\"></script>");

class PageApplication {
	protected $group;
	protected $style;
	protected $is_ajax  = false;
	protected $form_count = 0;
    public static $application;
	public $script_name;
	public $use_ajax 	= false;
	public $session;
	public $is_developer;
	public $current_file;
	public $build_main;
	public $fragment_handler 	= 'FragmentHandler';
	public $site_id;
	public $is_error = false;
	public 	$document_root;
	public 	$html_framework;
	public	$server_name;
	public  $db;
	public  $redirect_requested;
	public  $redirect_page;
	public  $redirect_counter;
	private	$use_alt_yui;			#  Supports default / alternate YUI_HEADER's and FOOTER's  1-2009 D. Ison
	private $display_fragments; 	#  Special debugging mode that adds a chart of fragments loaded
	private $fragments_used;		#  Stores fragment names for above	
	private $html_dom;				#  Special html dom processor for YUI header directives support
	private $yui_css;				#  Array of CSS include requests from yui headers and footers
	private $yui_js;				#  Array of JS include requests from yui headers and footers
	private $use_form_validation; 	#  Includes (automatically) .js code for YUI compatible form validations
	static private $debug_level = 0;
	static private $debug_string = '';	#  To display stuff to browser.  8-2009 D. Ison

    public function __construct() {
        PageApplication::$application = $this;
    }


    /*  Initialises things impossible to do in constructor.  D. Ison 9-2008  */
	function init ($build_main, $html_framework, $group, $style)  {

		try {
			if (! isset ($session)) {$session = '';}
			//new code by Frank C.
			if($session == ''){session_start();}
      $session = 'PHPSESSID=' . md5((session_id()));			
						
			#  Initialise general Pagebuilder items  
			//$this->session 				= strip_tags(SID);
			$this->session 				= $session;
			$this->group 				= $group;
			$this->style				= "$style.css";
			$this->build_main			= $build_main;
			$this->script_name 			= $_SERVER ['SCRIPT_NAME'];
			$this->server_name 			= $_SERVER['SERVER_NAME'];
			$this->current_file 		= $this->GetCurrentScriptPrefix();
			$this->is_developer 		= isset($is_developer) ? $is_developer : false;
			$this->display_fragments 	= $this->GetSessionOrURLValue ('DISPLAY_FRAGMENTS');
			$this->fragments_used		= array();
			$this->yui_js				= array();
			$this->yui_css				= array();
			$this->html_dom 			= new simple_html_dom();
			#  Experimental work for AJAX support using YAHOO.util.Connect.asyncRequest() 
			$this->is_ajax =  array_key_exists ('AJAX', $_REQUEST);
			if ($this->is_ajax) {
#				trigger_error ("is_ajax [$this->is_ajax]", E_USER_WARNING);
			}

			$this->ManageRedirects();
			#  Initialise yui_js with default values necessary for display_fragments 2009-02 D. Ison
			array_push ($this->yui_js, 'yuiloader-dom-event/yuiloader-dom-event.js');
			array_push ($this->yui_js, 'element/element-beta-min.js');
			#  Setup PageBuilder error handler  8-2009 D. Ison
			set_error_handler ("PageApplication::PageBuilderErrorHandler");

		}
		catch (Exception $exception) {
			$this->ErrorFail ($exception);
			trigger_error ("catch", E_USER_WARNING);

		}
	}

	function GetFragment ($file, $section) {

		$file 		= strtolower ($file);
		$section 	= strtolower ($section);
		$file_path 	= $this->document_root . '/user_files/fragments/' . "$this->group/$file" . '_' . $section . '.html';

		#  Support for YUI_HEADER alternate fragments added 1-2009 D. Ison
		$yui_header_tag = '_yui_header';
		$yui_footer_tag = '_yui_footer';
		if ($this->use_alt_yui) {
			#  If use_alt_yui is enabled, use the _alt fragment instead
			$yui_header_tag .= '_alt';
			$yui_footer_tag .= '_alt';
		}
		$yui_header_path 		= $this->document_root . '/user_files/fragments/' . "$this->group/$file" . '_' . $section . $yui_header_tag . '.html';
		$yui_footer_path 		= $this->document_root . '/user_files/fragments/' . "$this->group/$file" . '_' . $section . $yui_footer_tag . '.html';
	
		$ret_val = '';
#$this->LogMessage ("Fragment is [$file_path], yui_header: [$yui_header_path]");
		if (file_exists ($file_path)) {
#$this->LogMessage ("Inserting Fragment [$file_path]");
			$contents 	= $this->GetFileContents ($file_path);
			#  First local fragment handler
			$fragment_handler = $this->fragment_handler;
			$contents = $fragment_handler ($contents);
			#  Next insert YUI stuff 
			if (file_exists ($yui_header_path)) {
				$header = $this->GetFileContents ($yui_header_path);	
				$this->GetYUIDirectives ($header);
				$contents = str_replace ("{YUI_HEADER}", $header, $contents);
			}
			if (file_exists ($yui_footer_path)) {
				$footer = $this->GetFileContents ($yui_footer_path);	
				$this->GetYUIDirectives ($footer);
				$contents = str_replace ("{YUI_FOOTER}", $footer, $contents);
			}
			#  Experimental automatic SetupForm() 
			$contents = $this->SetupForm ($contents, $section);

			$ret_val .= "\n<!--  $file: inserting fragment [$section]  -->\n";
			$ret_val .= $contents;

			$ret_val .= "\n<!--  Ending insertion of fragment [$section]  -->\n";
		}
		return $ret_val;
	}

	private function GetCurrentScriptPrefix () {
		$name = basename ($_SERVER ['SCRIPT_FILENAME']);
	#	$list = split  ("\.", $name);
		$list = explode  ('.', $name);
		$ret_val = $list[0];
		return $ret_val;
	}

	/*  Notes for AJAX support:
		1.  Standard non-ajax, perform everything as-is.
		2.  For AJAX mode then ONLY display fragment returned from build_main
	*/
	function DisplayPage() {

		try {
	
			#  Must call build_main first to get processing done D. Ison 12-2008
			$build_main 	= $this->build_main;
			$main_fragment 	= $build_main();

			if (self::$debug_level >= 3) {
		#		$main_fragment = ("$main_fragment <br>Diagnostics: <b>self::$debug_string</b>");
				$main_fragment .= "<br>Diagnostics: <b>";
				$main_fragment .= self::$debug_string;
				$main_fragment .= "</b>";
			}

			#  Next check validate (form validation) 2009-02 D. Ison	
			$form_validation = ($this->use_form_validation) ? VALIDATE_JS : '';
			/*	if ($this->use_form_validation) {
				$form_validation = VALIDATE_JS;
			}  */

			#  Decrement redirect counter as applicable
			if ($this->redirect_requested) {
				$this->redirect_counter--;
				$_SESSION ['REDIRECT_COUNTER'] = $this->redirect_counter;	
#trigger_error ("this->redirect_requested [$this->redirect_requested] counter [$this->redirect_counter]", E_USER_NOTICE); 			
			}
			if ($this->redirect_requested && $this->redirect_counter == 0) {
				$redirect = "Location: $this->redirect_page";	
#trigger_error ("triggering redirect [$redirect]", E_USER_NOTICE); 
				print header ($redirect);					
			} else {
				#  BuildMain() used to be here D. Ison 8-2008 
				if (! $this->is_ajax) {
					#  Retrieve user-defined tlbr framework  
					$contents = file_get_contents  ($this->html_framework);
					#  Any additional HTML header code 
					$fragment = $this->GetFragment ($this->current_file , 'HTML_HEADER');
					$contents = str_replace ("{HTML_HEADER}", $fragment, $contents);
					#  Do any YUI Includes as appropriate
					#  This probably needs to also be done in the is_ajax=true case as well.  D. Ison 2009-02
					$yui_includes = $this->BuildYUIIncludes();
					#  Append to yui_includes form validation if applicable
					$yui_includes .= $form_validation;				
					$contents = str_replace ("{YUI_INCLUDES}", $yui_includes, $contents);
					#  Standard page elements  
					$fragment = $this->GetFragment ($this->current_file , 'TOP');
					$contents = str_replace ("{TOP}", $fragment, $contents);
					$fragment = $this->GetFragment ($this->current_file , 'LEFT');
					$contents = str_replace ("{LEFT}", $fragment, $contents);
					$fragment = $this->GetFragment ($this->current_file , 'BOTTOM');
					$contents = str_replace ("{BOTTOM}", $fragment, $contents);
					$fragment = $this->GetFragment ($this->current_file , 'RIGHT');
					$contents = str_replace ("{RIGHT}", $fragment, $contents);
					#  Also set style & YUI modules variables
					$site_id_str = sprintf ("%00d", $this->site_id);
					$contents = str_replace ("{SITE_ID}", "$site_id_str", $contents);
					$contents = str_replace ("{STYLE}", "$this->style", $contents);

					#  Append fragments display as appropriate
					$main_fragment .= $this->DisplayFragments();

					#  Finally place results of BuildMain()  
					$contents = str_replace ("{MAIN}", $main_fragment, $contents);
					print $contents;		
				} else {
					/*  Otherwise just display the BuildMain() fragment  */
					print $main_fragment;		
				}
			}
		}
		catch (Exception $exception) {
			$this->ErrorFail ($exception);
		}
	}	
	
	function SetupForm ($fragment, $name) {

		#  Get a form name for this
		#  Model for strpos() use  D. Ison 7-2009
		if (strpos ($fragment, '{START_FORM}') !== false) {
			$form_index = sprintf ("%00d", $this->form_count);
			$form_name = "FORM_" . $form_index;
			$this->form_count++;
			if ($this->use_ajax) {
				$html = "<FORM METHOD=\"POST\" name=\"{FORM_NAME}\" onsubmit=\"submitForm ('{FORM_NAME}', true, '{FORM_ACTION_AJAX}?AJAX=YES'); return false;\">";
	#			$html = "<FORM name=\"{FORM_NAME}\" onsubmit=\"submitForm ('{FORM_NAME}', true, '{FORM_ACTION_AJAX}?AJAX=YES'); return false;\">";
			} else {
				$html = "<FORM METHOD=\"POST\" ACTION=\"{FORM_ACTION}\" NAME=\"{FORM_NAME}\" autocomplete=\"off\">";
			}
			
			$action 		= "$this->script_name?$this->session";
			$action_ajax 	= "$this->script_name";
	
			$html = str_replace ("{FORM_ACTION}", $action, $html);
			$html = str_replace ("{FORM_ACTION_AJAX}", $action_ajax, $html);
	
			$html = str_replace ("{FORM_NAME}", $form_name, $html);
	
	  //		$html = preg_replace_callback ('/{FORM_NAME}/', 'PageApplication::SetupFormCallback', $html);
		   
		  
			$fragment = str_replace ("{START_FORM}", $html, $fragment);
		}
		return $fragment;
	}
	
	function ErrorFail ($exception) {
		$message = sprintf ("Error  %s:%d %s\n", $exception->getFile(), 
			$exception->getLine(), $exception->getMessage());
		ShowError ($message);
		die;
	}

	function LogMessage ($message) {
		syslog (LOG_INFO, $message);
	}

	/*  Experimental function  */
	function GetSessionOrURLValue ($target) {
/*
		$value = $_REQUEST [$target];
		if (strlen ($value) > 0) {
			$_SESSION [$target] = $value;
		} else {
			$value = $_SESSION [$target];
		}
*/
		$value = $this->GetRequest ($target);
		if (strlen ($value) > 0) {
			$_SESSION [$target] = $value;
		} else {
			$value = $this->GetSession ($target);
		}
		

		return $value;
	}


/*	
	static function SetupFormCallback ($matches) {
		static $form_count;
//		$form_name = sprintf ("%s_%00d", $name, $form_count);
		$form_name = sprintf ("FORM_%00d", $form_count);
		$form_count++;	
		$matches = $form_name;
		return $matches;
	}
	
*/

	function ManageRedirects() {
		#  If a REDIRECT_PAGE is passed in we initialise everything
		$redirect_page = $this->GetRequest ('REDIRECT_PAGE');
		if (strlen ($redirect_page) > 0) {
			$_SESSION ['REDIRECT_PAGE'] 		= $redirect_page;
			$_SESSION ['REDIRECT_REQUESTED']	= true;
	#		$_SESSION ['REDIRECT_COUNTER']		= 2;		
			$_SESSION ['REDIRECT_COUNTER']		= 1;		

		}
		$this->redirect_requested = $this->GetSession ('REDIRECT_REQUESTED');
		if ($this->redirect_requested) {
			#  Get the rest of relevant values
			$this->redirect_counter 	= $this->GetSession ('REDIRECT_COUNTER');
			$this->redirect_page 		= $this->GetSession ('REDIRECT_PAGE');
 //   	trigger_error ("redirect_page [$this->redirect_page] redirect_counter [$this->redirect_counter]", E_USER_NOTICE);
		}
	}
	
	function Date2MySQL ($date) {
		$ret_val = '';
		if (! empty ($date)) {
			$month 	= substr ($date, 0, 2);
			$day	= substr ($date, 3, 2);
			$year 	= substr ($date, 6, 4);
			$ret_val = "$year-$month-$day";
		}
		return $ret_val;
	}
	function MySQL2Date ($date) {
		$ret_val = '';
		if (! empty ($date)) {
			$year 	= substr ($date, 0, 4);  
			$month	= substr ($date, 5, 2); 
			$day 	= substr ($date, 8, 2);
			$ret_val = "$month/$day/$year";
		}
		return $ret_val;
	}	
	function SetAltYUI ($value) {
		$this->use_alt_yui = $value;
	}
	function GetAltYUI() {
		return $this->use_alt_yui;
	}
	function SetUseFormValidation ($value) {
		$this->use_form_validation = $value;
	}
	function GetUseFormValidation() {
		return $this->use_form_validation;
	}
	function SetIsAjax ($value) {
		$this->is_ajax = $value;
	}
	function GetIsAjax() {
		return $this->is_ajax;
	}
	function SetUseAjax ($value) {
		$this->use_ajax = $value;
	}
	function GetUseAjax() {
		return $this->use_ajax;
	}
	function GetDateValues ($date) {
		$month 	= substr ($date, 0, 2);
		$day 	= substr ($date, 3, 2);
		$year 	= substr ($date, 6, 4);
		$ret_val = array ($month, $day, $year);
		return $ret_val;
	}
	
	function GetYUIDirectives ($fragment) {
		$this->html_dom->load ($fragment);
		$comments_array = $this->html_dom->find ('comment'); 	
		#  Search comments for YUI_JS & YUI_CSS
		foreach ($comments_array as $comment) {
			#  Check for YUI_JS
			if (strpos ($comment, 'YUI_JS') > 0) {
				$comment_lines = explode ("\n", $comment);
				foreach ($comment_lines as $line) {
					if (strstr ($line, 'YUI_JS')) {
						$directive = explode ("=", $line);
						$filename = $directive[1];
						if (! in_array ($filename, $this->yui_js)) {
							array_push ($this->yui_js, $filename);
						}
					}
				}
			}
			#  Check for YUI_CSS
			if (strpos ($comment, 'YUI_CSS') > 0) {
				$comment_lines = explode ("\n", $comment);
				foreach ($comment_lines as $line) {
					if (strstr ($line, 'YUI_CSS')) {
						$directive = explode ("=", $line);
						$filename = $directive[1];
						if (! in_array ($filename, $this->yui_css)) {
							array_push ($this->yui_css, $filename);
						}
					}
				}
			}
		}
	}

	function BuildYUIIncludes() {

		$ret_val = "<!-- Pagebuilder YUI Includes  -->\n";
		foreach ($this->yui_css as $css) {
			$ret_val .= "<link rel=\"stylesheet\" type=\"text/css\" href=\"/ecps/javascript/yui/2.6.0/build/$css\" />\n";		
		}

		foreach ($this->yui_js as $js) {
			$ret_val .= "<script type=\"text/javascript\" src=\"/ecps/javascript/yui/2.6.0/build/$js\"> </script>\n";	
		}

#		$ret_val .= '</script>';
	
		return $ret_val;	
	}
	
	function DisplayFragments() {
		#  Locals
		$fragments_text = '';
		
		if ($this->display_fragments == 'YES') {
			foreach ($this->fragments_used as $fragment) {
				$base_name = basename ($fragment);
				$fragments_text .= "<strong>$base_name </strong> <br>";
			}

			$ret_val = <<<FRAGMENT

<!-- Load the YUI Loader script: -->
<script src="/ecps/javascript/yui/2.6.0/build/yuiloader/yuiloader-min.js"></script>
<script src="/ecps/javascript/yui/2.6.0/build/event/event.js"></script>


<script>
var loader = new YAHOO.util.YUILoader({
        require: ["event", "container"], 
     	base: '/ecps/javascript/yui/2.6.0/build/', 
 		loadOptional: true, 
     //   combine: true, 
     //   filter: "MIN", 
     //   allowRollup: true, 
        onSuccess: function() {
    },
    timeout: 10000,
    combine: true
});
loader.insert();
</script>

<style>
	.yui-overlay { position:absolute;background:#fff;border:1px dotted black;padding:5px;margin:10px; }
	.yui-overlay .hd { border:1px solid red;padding:5px; }
	.yui-overlay .bd { border:1px solid green;padding:5px; }
	.yui-overlay .ft { border:1px solid blue;padding:5px; }

	#ctx { background:orange;width:100px;height:25px; }
	#example {height:15em;}
</style>

<script>
		YAHOO.namespace("example.container");

		function init() {
			// Build display_fragments based on markup, initially hidden, fixed to the center of the viewport, and 300px wide
			YAHOO.example.container.overlay1 = new YAHOO.widget.Overlay("overlay1", { fixedcenter:true, 
																					  visible:false,
																					  width:"400px" } );
			YAHOO.example.container.overlay1.render();
			YAHOO.util.Event.addListener("show1", "click", YAHOO.example.container.overlay1.show, YAHOO.example.container.overlay1, true);
			YAHOO.util.Event.addListener("hide1", "click", YAHOO.example.container.overlay1.hide, YAHOO.example.container.overlay1, true);

			}

		YAHOO.util.Event.addListener(window, "load", init);
</script>

<div>
	 Fragments used in this page:
	<button id="show1">Show</button>
	<button id="hide1">Hide</button>
</div>

	<div id="overlay1" style="visibility:hidden">
	<div class="hd">Begin fragment display.</div>
	<div class="bd">$fragments_text</div>
	<div class="ft">End of fragments</div>
</div>
FRAGMENT;
		}
		return isset ($ret_val) ? $ret_val : '';
	}
	function GetFileContents ($file_path) {
	 	if ($this->display_fragments) {
	 		array_push ($this->fragments_used, $file_path);
	 	}
		return file_get_contents  ($file_path);	
	}
	
	#  Adds $days days to specified date.  Returns date string
	function AddDays ($date, $days) {
	    $time = strtotime ($date); 
  		$end_time = $time + ($days * 86400); 
     	$ret_val = date ("Y/m/d", $end_time);
     	return ($ret_val);
	}
	
	#  Subtracts $date2 - $date1.  Returns number of days difference;
	function SubtractDates ($date1, $date2) {
	    $time1 = strtotime ($date1); 
	    $time2 = strtotime ($date2); 
  		$end_time = $time2 - $time1;
     	$ret_val = ($end_time / 86400); 
     	return ($ret_val);
	}

	function Terminate ($message, $sql_command = '') {
		$backtrace = debug_backtrace();
		$caller = $backtrace[0];
		$file 	= $caller ['file'];
		$line	= $caller ['line'];
		if (strlen ($message) > 0) {
			print "$message [<b>$file</b> @ line $line]"; 
		}
		if (strlen ($sql_command) > 0) {
			error_log ("Terminate called with mysql query [$sql_command]");
		}
#		print "<br>";
		die;
	}

	#  Experimental - taken from comments in php.net site  8-2009 D. Ison
	static function RenderBacktrace ($raw){
        $output = '';
        foreach($raw as $entry){
                $output.="File: " . $entry['file'] . " (Line: ".$entry['line'].") ~ ";
                $output.="Function: ".$entry['function'] . " ";
#                $output.="Args: ".implode(", ", $entry['args'])."\n";
        }
        return $output;
    }
    #  Custom error handler.  Debug levels:  0 = normal, 1 = normal, 2 = high 3 = drastic
	static function PageBuilderErrorHandler ($errno, $errstr, $errfile, $errline) {

		$message = '';
		$is_fatal = false;
		$debug_level = self::$debug_level;
		switch ($errno) {
			case E_USER_ERROR:
				$message = "PHP Fatal error ";
				$is_fatal = true;
			break;
			case E_USER_WARNING:
				if ($debug_level > 0) {
					$message = "PHP Warning ";
				}
			break;
			case E_USER_NOTICE:
				if ($debug_level > 1) {
					$message = "PHP Notice ";
				}
			break;
			default:
				if ($debug_level > 2) {
					$message = "PHP Unknown ";
				}
			break;
		}

		if (! empty ($message)) {
			$errfile_base = basename ($errfile);
			$message = "$message [$errno] \"$errstr\" in $errfile_base line $errline  (debug=$debug_level)";
			error_log ($message);
		}

		#  Maintain a copy for browser display
		if (self::$debug_level >= 0) {
			self::$debug_string .= "<br>$errstr";
		}


		#  These are find-and-quit's.  Add as-needed
/*		
		if (strpos  ($errstr, 'mysql_num_rows') !== false) {
			$backtrace = self::RenderBackTrace (debug_backtrace());
			error_log ($backtrace);
			$is_fatal = true;
		}
*/
		#  Only the highest debug level terminates and displays 
		if (self::$debug_level >= 3 && strpos  ($errstr, 'mysql_data_seek') !== false) {
			$backtrace = self::RenderBackTrace (debug_backtrace());
			error_log ($backtrace);
			$is_fatal = true;
		}

		if (self::$debug_level >= 3 && strpos  ($errstr, 'Array to string') !== false) {
			$backtrace = self::RenderBackTrace (debug_backtrace());
			error_log ($backtrace);
			$is_fatal = true;
		}



		if ($is_fatal) {
			exit (1);
		}
	
		#  Don't execute PHP internal error handler
		return true;
	}
    static function SetDebugLevel ($level) {
    	self::$debug_level = $level;
    }
    static function GetDebugLevel() {
    	return self::$debug_level;
    }

	#  Not sure about these stripslashes().  Why was browser converting ' to \' ?  D. Ison  9-2009
 	function GetRequest ($string) {
    	return (isset ($_REQUEST [$string]) ? stripcslashes ($_REQUEST [$string]) : '');
    }
    function GetSession ($string) {
    	return (isset ($_SESSION [$string]) ? $_SESSION [$string] : '');
    }
    function GetPost ($string) {
    	return (isset ($_POST [$string]) ? $_POST [$string] : '');
    }
    function GetArrayValue ($array, $index, $default=NULL) {
    	return (isset ($array[$index]) ? $array[$index] : $default);
    }
    
    
 	#  Converts array of SQL conditions 
    function MakeWherePhrase ($phrase_array, $operator) {
		$where_phrase = '';
		if (count ($phrase_array) > 0) {
			$last_phrase = end ($phrase_array);	
			foreach ($phrase_array as $phrase) {
				$where_phrase .= $phrase;
				if ($phrase != $last_phrase) {
					$where_phrase .= " $operator ";
				}
			}		
		}
    	if (empty ($where_phrase)) {
    		$where_phrase = "TRUE";
		}
		return ($where_phrase);
    }
}

?>


<?php


#  MySQL connection class returning iterator resultset objects
#  From: http://www.w3style.co.uk/a-mysql-classiterator-for-php5
class MySQLdb
{
	private $conn;
	private $db;
	private static $instance = null;
	public function __construct($host, $user, $pass, $db=false) {
		$this->connect($host, $user, $pass);
		if ($this->conn && $db) $this->selectDb($db);
	}
	public static function getInstance($host, $user, $pass, $db) {
		if (self::$instance === null) {
			self::$instance = new DB($host, $user, $pass, $db);
		}
		return self::$instance;
	}
	public function connect($host, $user, $pass) {
		$this->conn = @mysql_connect($host, $user, $pass);
	}
	public function selectDb($db) {
		@mysql_select_db($db, $this->conn);
		$this->db = $db;
	}	
	public function getDbName() {
		return $this->db;
	}
	public function isConnected() {
		return is_resource($this->conn);
	}
	public function disconnect() {
		@mysql_close($this->conn);
	}
	public function getError() {
#		return mysql_error($this->conn);
		return mysql_error();
	}	
	public function query($query) {
		#  Eliminate \n's and \t's  8-2009 D. Ison
		$query = str_replace  ("\n", '', $query);  
		$query = str_replace  ("\t", '', $query);  

#		$result = @mysql_query($query);
		$result = mysql_query($query);
		$error = $this->getError();

		if (strlen ($error) > 0) {
			error_log ("MySQL server error: [$error] for [$query] ");
			if (PageApplication::GetDebugLevel() > 0) {
				$backtrace = PageApplication::RenderBackTrace (debug_backtrace());
				error_log ("Backtrace information: [$backtrace]");
			}
		}
		$insert = false;
		#  Added 8-2009 D. Ison
		$query_reduced = trim (strtolower ($query));
		$insert = (strpos ($query_reduced, 'insert') === 0 || strpos ($query_reduced, 'update') === 0) ? true : false;		
/*
		if (strpos ($query_reduced, 'insert') === 0 || strpos ($query_reduced, 'update') === 0) {
			$insert = true;		
		}
*/		
/*		if (strpos(trim(strtolower($query)), 'insert') === 0) { 
			$insert = true;
		} elseif  (strpos(trim(strtolower($query)), 'update') === 0) {
			$insert = true;
		}
*/
		return new MySQLdb_Result($result, $this->conn, $insert);
	}
	public function info() {
		return mysql_get_server_info($this->conn);
	}
	public function status() {
		$retval = explode ('  ', mysql_stat($this->conn));
		return $retval;
	}
	public function escape($string) {
		return mysql_real_escape_string($string, $this->conn);
	}
}

/**
 * DB_Result class.  Provides an iterator wrapper
 * for working with a MySQL result.
 * @author d11wtq
 */
 
function myfunction($value,$key) {
	echo "The key $key has the value $value<br />";
}

 
class MySQLdb_Result
{
        private $id;  			#  ID resulting from row insertion
        private $length = 0;	#  Resultset size
        private $result;
        private $currentRow 	= array();
        private $position 		= 0;
        private $lastPosition 	= 0;
        private $gotResult 		= false;
        private $affectedRows 	= -1;
       
        public function __construct (&$result, &$conn, $insert=false) {
                $this->result 	= $result;
                $this->conn 	= $conn;

/*
   				#  Locals 8-2009 D. Ison
   				$num_rows = 0;
   				if (isset ($this->result)) {
   					$num_rows = @mysql_num_rows($this->result); 				
   				}  

*/
#                if ((@mysql_num_rows($this->result) >= 0 && $this->result !== false) || $insert)
                if ($insert || ($this->result !== false && @mysql_num_rows($this->result) >= 0)) {
#                if ($insert || ($this->result !== false && $num_rows >= 0)) {
                        if ($insert) {
                        	$this->id = mysql_insert_id($conn);
                        	$this->length = 0;
                        } else {
                        	$this->length = (int) @mysql_num_rows($this->result);
                       	}
                        $this->affectedRows = mysql_affected_rows($conn);
                }
        }
 
        public function __get($field) {
                if ($this->lastPosition != $this->position || !$this->gotResult) {
                        mysql_data_seek($this->result, $this->position);
                        $this->currentRow = mysql_fetch_assoc($this->result);
                        $this->lastPosition = $this->position;
                        $this->gotResult = true;
                }
#    array_walk($this->currentRow,"myfunction");
            

                return $this->currentRow[$field];
        }
        public function id() {
                return $this->id;
        }
        public function length() {
                return $this->length;
        }
        public function first() {
                if ($this->length > 0) {
                        $this->goto1(0);
                        return true;
                }
                else return false;
        }
        public function last() {
                return $this->goto1($this->length-1);
        }
        public function end() {
                if ($this->position >= $this->length) return true;
                else return false;
       	}
        public function start() {
                return ($this->position < 0);
       	}
        public function next() {
                return $this->goto1($this->position+1);
        }
        public function prev() {
                return $this->goto1($this->position-1);
        }
        public function goto1($position) {
                if ($position < -1 || $position > $this->length) return false;
                else {
                        $this->position = $position;
                        return true;
                }
        }
        public function affectedRows() {
                return $this->affectedRows;
        }
        public function &get() {
                return $this->result;
        }
        public function position() {
                return $this->position;
        }
}

?>

<?php
/*
			echo "<b>My ERROR</b> [$errno] $errstr<br />\n";
			echo "  Fatal error on line $errline in file $errfile";
			echo ", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br />\n";
			echo "Aborting...<br />\n";
			exit(1);*/


?>

<?php
/* 
 * functions.php is created by Jai Dhar, FPS-Tech, for use with eyeZm
 * iPhone application. This is not intended for use with any other applications,
 * although source-code is provided under GPL.
 *
 * For questions, please email jdhar@eyezm.com (http://www.eyezm.com)
 *
 */

/* There appears to be some discrepancy btw. 1.24.1/2 and .3 for EventPaths, to escape them here */
function getEventPathSafe($event)
{
	if (!strcmp(ZM_VERSION, "1.24.1") || !strcmp(ZM_VERSION, "1.24.2")) {
		$ret = getEventPath($event);
	} else {
		$ret = ZM_DIR_EVENTS."/".getEventPath($event);
	}
	/* Make sure ZM_DIR_EVENTS is defined, otherwise need to fudge the path */
	if (!defined("ZM_DIR_EVENTS")) {
		$ret = "events/".$event['MonitorId']."/".$event['Id'];
		error_log("ZM_DIR_EVENTS not defined, guessing path to be ".$ret);
	}
	return $ret;
}
function updateClientVer()
{
	$str = $_SERVER['HTTP_USER_AGENT'];
	/* Check if it starts with eyeZm */
	if (!strcmp(substr($str, 0, 5),"eyeZm")) {
		/* Found eyeZm */
		$ver = substr($str, 6);
		$verarray = explode(".", $ver);
		$_SESSION['vermaj']=$verarray[0];
		$_SESSION['vermin']=$verarray[1];
		$_SESSION['verbuild']=$verarray[2];
	}
}
function getClientVerMaj()
{
	if (isset($_SESSION['vermaj'])) return $_SESSION['vermaj'];
	return "0";
}
function getClientVerMin()
{
	if (isset($_SESSION['vermin'])) return $_SESSION['vermin'];
	return "0";
}
function requireVer($maj, $min)
{
	if (getClientVerMaj() > $maj) return 1;
	if ((getClientVerMaj() == $maj) && (getClientVerMin() >= $min)) return 1;
	return 0;
}
function logXml($str)
{
	if (!defined("ZM_XML_DEBUG")) define ( "ZM_XML_DEBUG", "0" );
	if (ZM_XML_DEBUG == 1) trigger_error("XML_LOG: ".$str, E_USER_NOTICE);
}
/* Returns defval if varname is not set, otherwise return varname */
function getset($varname, $defval)
{
	if (isset($_GET[$varname])) return $_GET[$varname];
	return $defval;
}
function xml_header()
{
	header ("content-type: text/xml");
	echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
}
function xml_tag_val($tag, $val)
{
	echo "<".$tag.">".$val."</".$tag.">";
	//echo "&lt;".$tag."&gt;".$val."&lt;/".$tag."&gt<br>";
}
function xml_tag_sec($tag, $open)
{
	if ($open) $tok = "<";
	else $tok = "</";
	echo $tok.$tag.">";
}
function xhtmlHeaders( $file, $title )
{
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<style type="text/css">
body {
	border: 0px solid;
	margin: 0px;
	padding: 0px;
}
</style>
	<script type="text/javascript">
	</script>
</head>
<?php
}
/** Returns whether necessary components for H264 streaming
 * are present */
function canStream264() {
	/* Make sure segmenter exists */
	$res = shell_exec("which segmenter");
	if ($res == "") {
		logXml("H264 Requested, but segmenter not installed.");
		return 0;
	}
	/* Check for zmstreamer */
	$res = shell_exec("which zmstreamer");
	if ($res == "") {
		logXml("ZMSTREAMER not installed, cannot stream H264");
		return 0;
	}
	/* Check for ffmpeg */
	$res = shell_exec("which ffmpeg");
	if ($res == "") {
		logXml("ZMSTREAMER not installed, cannot stream H264");
		return 0;
	}
	/* Check for libx264 support */
	$res = shell_exec("ffmpeg -codecs 2> /dev/null | grep libx264");
	if ($res == "") {
		logXml("FFMPEG doesn't support libx264");
		return 0;
	}
	logXml("Determined can stream for H264");
	return 1;
}
/** Return FFMPEG parameters for H264 encoding */
function getFfmpeg264Str($width, $height, $br, $fin, $fout)
{
	$ffparms = "-f mpegts -analyzeduration 0 -acodec copy -s 320x240";
	$ffparms .= " -vcodec libx264 -b ".$br;
	$ffparms .= " -flags +loop -cmp +chroma -partitions +parti4x4+partp8x8+partb8x8";
        $ffparms .= " -subq 5 -trellis 1 -refs 1 -coder 0 -me_range 16 -keyint_min 25";
        $ffparms .= " -sc_threshold 40 -i_qfactor 0.71 -bt 200k -maxrate ";
	$ffparms .= $br." -bufsize ".$br." -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6";
	$ffparms .= " -qmin 10 -qmax 51 -qdiff 4 -level 30";
	$ffparms .= " -g 30 -analyzeduration 0 -async 2 ".$fout.(ZM_XML_DEBUG?"":" 2> /dev/null");
	$ffstr = "ffmpeg -t ".ZM_XML_H264_MAX_DURATION." -analyzeduration 0 -i ";
	$ffstr .= $fin." ".$ffparms;
	return $ffstr;
}
/** Returns the width and height of a monitor */
function getMonitorDims($monitor)
{
	$query = "select Width,Height from Monitors where Id = ".$monitor;
	$res = dbFetchOne($query);
	return $res;
}
/** Returns the temp directory for H264 encoding */
function getTempDir()
{
	/* Assume that the directory structure is <base>/skins/xml/views */
	return dirname(__FILE__)."/../../../temp";
}
/** Returns the name of the m3u8 playlist based on monitor */
function m3u8fname($monitor) {
	return "stream_".$monitor.".m3u8";
}

/** Erases the M3u8 and TS file names for a given monitor */
function eraseH264Files($monitor) {
	/* Remove wdir/.m3u8 and wdir/sample_<mon>*.ts */
	shell_exec("rm -f ".getTempDir()."/".m3u8fname($monitor)." ".getTempDir()."/sample_".$monitor."*.ts");
}
function kill264proc($monitor) {
	$pid = trim(shell_exec("pgrep -f -x \"zmstreamer -m ".$monitor."\""));
	if ($pid == "") {
		logXml("No PID found for ZMStreamer to kill");
	} else {
		shell_exec("kill -9 ".$pid);
		logXml("Killed process ".$pid." for Monitor ".$monitor);
	}
}
/** Return the command-line shell function to setup H264 stream */
function stream264fn ($mid, $width, $height, $br) {
	$cdir = "./temp";
	$zmstrm = "zmstreamer -m ".$mid.(ZM_XML_DEBUG?"":" 2> /dev/null");
	$ffstr = getFfmpeg264Str($width, $height, $br, "-", "-");
	$seg = "segmenter - ".ZM_XML_SEG_DURATION." ".$cdir."/sample_".$mid." ".$cdir."/".m3u8fname($mid)." ../".(ZM_XML_DEBUG?"":" 2> /dev/null");
	$url = $zmstrm . " | ".$ffstr." | " . $seg;
	return "nohup ".$url." & echo $!";
}

/** Generate the web-page presented to the viewer when using H264 */
function h264vidHtml($width, $height, $monitor, $br) {
	$ajaxUrl = "?view=actions&action=spawn264&&monitor=".$monitor."&br=".$br;
	/* Call these two directly to bypass server blocking issues */
	$ajax2Url = "./skins/xml/views/actions.php?action=chk264&monitor=".$monitor;
	$ajax2Url .= "&timeout=".ZM_XML_H264_TIMEOUT;
	$ajax3Url = "./skins/xml/views/actions.php?action=kill264&monitor=".$monitor;
?>
<html>
<head>
	<script type="text/javascript">
	/* Called when paused or done is pressed */
	function vidAbort() {
		document.getElementById("viddiv").style.display = "none";
		document.getElementById("loaddiv").style.display = "block";
		var pElement = document.getElementsByTagName('video')[0];
		var ajaxKill = new AjaxConnection("<?php echo $ajax3Url;?>");
		ajaxKill.connect("cbKilled");
		pElement.stop();
		pElement.src="";
		
	}
	/* Callback when spawn264 process is ended */
	function cbVidLoad()
	{
		document.getElementById("loaddiv").innerHTML = "H264 Stream Terminated";
	}
	function vidLoaded() {
<?php if (ZM_XML_H264_AUTOPLAY==1) { ?>
		window.setTimeout("startVid()", 500);
<?php } ?>
	}
	function bindListeners()
	{
		var pElement = document.getElementsByTagName('video')[0];
		/* Bind abort */
		pElement.addEventListener('abort', vidAbort, false);
		pElement.addEventListener('done', vidAbort, false);
		pElement.addEventListener('ended', vidAbort, false);
		pElement.addEventListener('pause', vidAbort, false);
		pElement.addEventListener('loadstart', vidLoaded, false);
	}
	/* Callback when kill264 process is ended */
	function cbKilled()
	{
		document.getElementById("loaddiv").innerHTML = "H264 Stream Terminated";
	}
	function loadVid()
	{
		var pElement = document.getElementById("vidcontainer");
<?php
		echo "pElement.src=\"./temp/".m3u8fname($monitor)."\"\n";
?>
		pElement.load();
<?php if (ZM_XML_H264_AUTOPLAY == 1) { ?>
<?php } else { ?>
		document.getElementById("viddiv").style.display = "block";
		document.getElementById("loaddiv").style.display = "none";
<?php } ?>
	}
	function startVid()
	{
		var pElement = document.getElementById("vidcontainer");
		document.getElementById("viddiv").style.display = "block";
		document.getElementById("loaddiv").style.display = "none";
		pElement.play();
	}
	/* Callback when stream is active and ready to be played */
	function cbFileExists()
	{
		window.setTimeout("loadVid()", 500);
	}
	/* On-load triggers two requests immediately: spawn264 and chk264 */
	window.onload = function() {
		bindListeners();
		var ajax1 = new AjaxConnection("<?php echo "$ajaxUrl";?>");
		var ajax2 = new AjaxConnection("<?php echo "$ajax2Url";?>");
		ajax1.connect("cbVidLoad");
		ajax2.connect("cbFileExists");
	}
	function AjaxConnection(url) {
		this.connect = connect;
		this.url = url;
	}
	function connect(return_func) {
		this.x = new XMLHttpRequest();
		this.x.open("GET", this.url, true);
		var self = this;
		this.x.onreadystatechange = function() {
			if (self.x.readyState != 4)
				return;
			eval(return_func + '()');
			delete self.x;
		}
		this.x.send(null);
	}
	</script>
<style type="text/css">
body {
	border: 0px solid;
	margin: 0px;
	padding: 0px;
	background-color: black;
	width: <?php echo $width ?>px;
	height: <?php echo $height ?>px;
}
.textcl {
	text-align: center;
	font-family: Arial;
	font-size: larger;
	width: 100%;
	color: white;
<?php echo "margin-top: ".($height/2)."px;"; ?>
}
</style>
</head>
<body>
<div id="viddiv" style="display: none;">
<?php
		echo "<video id=\"vidcontainer\" width='".$width."' height='".$height."' />\n";
?>
</div>
<div id="loaddiv" class="textcl">
Initializing H264 Stream (<?php echo($br); ?>)...
</div>
</body>
</html>
<?php
}
?>

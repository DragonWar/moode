<?php
/**
 *  This Program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3, or (at your option)
 *  any later version.
 *
 *  This Program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with TsunAMP; see the file COPYING.  If not, see
 *  <http://www.gnu.org/licenses/>.
 *
 *	PlayerUI Copyright (C) 2013 Andrea Coiutti & Simone De Gregori
 *	Tsunamp Team
 *	http://www.tsunamp.com
 *
 * Rewrite by Tim Curtis and Andreas Goetz
 */

require_once dirname(__FILE__) . '/../inc/connection.php';

function mpdTouchFiles() {
	return sysCmd("find '" . MPD_LIB . 'WEBRADIO' . "' -name \"" . "*.pls" . "\"" . " -exec touch {} \+");
}

if (!$mpd) {
	die('Error: connection to MPD failed');
}

if (!isset($_GET['cmd'])) {
	die('Error: missing or invalid command');
}

$cmd = $_GET['cmd'];
$path = isset($_POST['path']) ? $_POST['path'] : null;

$res = array('OK' => true);


switch ($cmd) {
	case 'filepath':
		$res = (null !== $path)
			? searchDB($mpd, 'filepath', $path)
			: searchDB($mpd, 'filepath');
		break;

	// - delete radio station file
	case 'deleteradiostn':
		if (null !== $path) {
			$res = array('syscmd' => array());
			$res['syscmd'][] = sysCmd("rm '" . MPD_LIB . $path . "'");
			// update time stamp on files so MPD picks up the change and commits the update
			$res['syscmd'][] = mpdTouchFiles();
		}
		break;

	// - add radio station file, also used for update
	case 'addradiostn':
		if (null !== $path) {
			$res = array('syscmd' => array());

			// create new file if none exists, or open existing file for overwrite
			$_file = MPD_LIB . 'WEBRADIO/' . $path . '.pls';
			$handle = fopen($_file, 'w') or die('db/index.php: file create failed on '.$_file);
			// format .pls lines
			$data = '[playlist]' . "\n";
			$data .= 'numberofentries=1' . "\n";
			$data .= 'File1='.$_POST['url'] . "\n";
			$data .= 'Title1=' . $path . "\n";
			$data .= 'Length1=-1' . "\n";
			$data .= 'version=2' . "\n";
			// write data, close file
			fwrite($handle, $data);
			fclose($handle);

			// reset file permissions
			$res['syscmd'][] = sysCmd("chmod 777 \"" .$_file . "\"");
			// update time stamp on files so MPD picks up the change and commits the update
			$res['syscmd'][] = mpdTouchFiles();
		}
		break;

	// - list contents of saved playlist
	// - delete saved playlist
	case 'listsavedpl':
		if (null !== $path) {
			$res = listPlayList($mpd, $path);
		}
		break;

	case 'deletesavedpl':
		if (null !== $path) {
			$res = removePlayList($mpd, $path);
		}
		break;

	case 'playlist':
		$res = getPlayQueue($mpd);
		break;

	case 'add':
		if (null !== $path) {
			$res = addQueue($mpd, $path);
		}
		break;

	case 'addplay':
		if (null !== $path) {
			$status = _parseStatusResponse(MpdStatus($mpd));
			$pos = $status['playlistlength'] ;
			addQueue($mpd, $path);
			sendMpdCommand($mpd, 'play '.$pos);
			$res = readMpdResponse($mpd);
		}
		break;

	case 'addreplaceplay':
		if (null !== $path) {
			sendMpdCommand($mpd, 'clear');
			addQueue($mpd, $path);
			sendMpdCommand($mpd, 'play');
			$res = readMpdResponse($mpd);
		}
		break;

	case 'update':
		if (null !== $path) {
			sendMpdCommand($mpd,"update \"" .html_entity_decode($path) . "\"");
			$res = readMpdResponse($mpd);
		}
		break;

	case 'trackremove':
		if (isset($_GET['songid']) && $_GET['songid'] != '') {
			$res = remTrackQueue($mpd,$_GET['songid']);
		}
		break;

	// TC (Tim Curtis) 2014-12-23
	// - move playlist tracks
	case 'trackmove':
		if (isset($_GET['songid']) && $_GET['songid'] != '') {
			$_args = $_GET['songid'].' '.$_GET['newpos'];
			sendMpdCommand($mpd, 'move '.$_args);
			$res = 'track move args= '.$_args;
		}
		break;

	case 'savepl':
		if (isset($_GET['plname']) && $_GET['plname'] != '') {
			sendMpdCommand($mpd,"rm \"" .html_entity_decode($_GET['plname']) . "\"");
			sendMpdCommand($mpd,"save \"" .html_entity_decode($_GET['plname']) . "\"");
			$res = readMpdResponse($mpd);
		}
		break;

	case 'search':
		if (isset($_POST['query']) && $_POST['query'] != '' &&
			isset($_GET['querytype']) && $_GET['querytype'] != '')
		{
			$res = searchDB($mpd,$_GET['querytype'],$_POST['query']);
		}
		break;

	case 'loadlib':
		$res = loadAllLib($mpd);
		break;

	case 'addall':
		if (null !== $path) {
			$res = enqueueAll($mpd, $path);
		}
		break;

	// TC (Tim Curtis) 2014-09-17
	// - added code to set the playlist song pos for play
	case 'playall':
		if (null !== $path) {
			// TC just a copy/paste from addplay above
			$status = _parseStatusResponse(MpdStatus($mpd));
			$pos = $status['playlistlength'] ;
			// original code, did not set play posn
			$res = playAll($mpd, $path);
			sendMpdCommand($mpd, 'play '.$pos);
			$res = readMpdResponse($mpd);
		}
		break;

	// TC (Tim Curtis) 2014-09-17
	// - library panel Add/replace/playall btn
	case 'addallreplaceplay':
		if (null !== $path) {
			$res = playAllReplace($mpd, $path);
		}
		break;
}

closeMpdSocket($mpd);

header('Content-type: application/json');
echo json_encode($res);
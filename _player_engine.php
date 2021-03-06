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

require_once dirname(__FILE__) . '/inc/connection.php';


if (!$mpd) {
	die('Error: connection to MPD failed');
}

Session::open();

// Fetch MPD status
$status = _parseStatusResponse(mpdStatus($mpd));

// Register player state in session
$_SESSION['state'] = $status['state'];

Session::close();

// Check and compare GUI state with Backend state
// MPD idle timeout loop, mpdMonitorState() waits until something changes in MPD then returns status
if ($_GET['state'] == $status['state']) {
	$status = mpdMonitorState($mpd);
}

// make sure song ist defined
$song = isset($status['song']) ? $status['song'] : '';

$status['x_status'] = $status;
$status['x_currentsong'] = _parseMpdCurrentSong(execMpdCommand($mpd, 'currentsong'));
$status['x_playlistinfo'] = _parseFileListResponse(execMpdCommand($mpd, "playlistinfo " . $song));

// get track info for currently playing track
$queue = mpdQueueTrackInfo($mpd, $song);

if (isset($queue[0])) {
	$track = $queue[0];

	// TODO check moving this to mpdEnrichItem
	$status['fileext'] = pathinfo($track['file'], PATHINFO_EXTENSION);

	// TODO use standard names instead of current xyz
	$status['currentartist'] = isset($track['Artist']) ? $track['Artist'] : '';
	$status['currentsong'] = isset($track['Title']) ? $track['Title'] : '';
	$status['currentalbum'] = isset($track['Album']) ? $track['Album'] : '';

	// experimental
	mpdEnrichItemInfo($track);
	$status = array_merge($status, $track);
}

closeMpdSocket($mpd);

header('Content-type: application/json');
echo json_encode($status, JSON_PRETTY_PRINT);

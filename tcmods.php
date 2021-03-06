<?php
/*
 *	This Program is free software; you can redistribute it and/or modify
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
 *  along with this software. If not, refer to the following link.
 *  http://www.gnu.org/licenses/
 *
 * Rewrite by Tim Curtis and Andreas Goetz
 */

require_once dirname(__FILE__) . '/inc/connection.php';
require_once dirname(__FILE__) . '/inc/worker.php';

Session::open();

/*
 * System commands
 */
if (isset($_POST['syscmd'])) {
	$workerSuccess = false;

	switch ($_POST['syscmd']) {
		case 'poweroff':
			if ($workerSuccess = workerPushTask("poweroff")) {
				uiSetNotification('Shutdown', 'System shutdown initiated...');
			}
			$tpl = "poweroff";
			break;

		case 'reboot':
			if ($workerSuccess = workerPushTask("reboot")) {
				uiSetNotification('Reboot', 'System reboot initiated...');
			}
			$tpl = "reboot";
			break;

		case 'reloadclockradio':
			$workerSuccess = workerPushTask("reloadclockradio");
			break;

		case 'reloadtcmodsconf':
			$workerSuccess = workerPushTask("reloadtcmodsconf");
			break;

		default:
			uiSetNotification('Error', 'Invalid command: ' . $_POST['syscmd']);
	}

	// TODO check if uiNotification rather than echo()
	if (false === $workerSuccess) {
		uiSetNotification('Job failed', 'Background worker is busy');
		echo "Background worker is busy";
	}

	// Display template if not clock radio reload or tcmods conf reload
	if (isset($tpl)) {
		render($tpl);
	}

	exit();
}

/*
 * Json commands
 */
if (isset($_GET['cmd']) && $_GET['cmd'] != '') {
	header('Content-type: application/json');

	switch ($cmd = $_GET['cmd']) {
		case 'getaudiodevdesc':
			$result = ConfigDB::read('cfg_audiodev', $_POST['audiodev']);
			$res = $result[0];
			break;

		case 'getupnpcoverurl':
			$rtn = sysCmd('upexplorer --album-art "' . $_SESSION['upnp_name'] . '"');
			$res = array('coverurl' => $rtn[0]);
			break;

		case 'readtcmconf':
			$res = getTcmodsConf();
			break;

		case 'updatetcmconf':
			$res = _updTcmodsConf($_POST);
			break;

		case 'getmpdstatus':
			$res = _parseStatusResponse(mpdStatus($mpd));
			break;

		case 'readstationfile':
			// misuse mpd function to split lines
			$res = parseMpdKeyedResponse(file_get_contents(MPD_LIB . $_POST['path']), '=');
			break;

		case 'readplayhistory':
			$res = explode("\n", file_get_contents('/var/www/playhistory.log'));
			break;

		// TC (Tim Curtis) 2015-06-26: TESTING ALSA-Direct volume control, requires www-data user in visudo
		case 'sendalsacmd':
			$mixername = getMixerName(getKernelVer($_SESSION['kernelver']), $_SESSION['i2s']);
			$rtn = sysCmd("sudo ".$_POST['alsacmd']." ".$mixername." ".$_POST['volumelevel'].$_POST['scale']);
			$res = $rtn[0];
			break;

		default:
			http_reponse_code(500); // internal server error
			exit();
	}

	header('Content-type: application/json');
	echo json_encode($res, JSON_PRETTY_PRINT);

	exit();
}

/*
 * Audio info
 */
$_hwparams = getHwParams();

if ($_hwparams['status'] == 'active') {
	$audioinfo_hwparams_format = $_hwparams['channels'] . ", ";
	$audioinfo_hwparams_format .= $_hwparams['format'] . " bit, ";
	$audioinfo_hwparams_format .= $_hwparams['rate'] . " kHz";
	$audioinfo_hwparams_calcrate = $_hwparams['calcrate'] . " mbps";
}
else {
	$audioinfo_hwparams_format = '';
	$audioinfo_hwparams_calcrate = '0 bps';
}

// INPUT INFO: mpd currentsong and status cmds
if (!$mpd) {
    $audioinfo_mpdstatus = 'Error Connecting to MPD daemon';
}
else {
	// mpd currentsong
	$res = execMpdCommand($mpd, 'currentsong');
	$_mpdcurrentsong = _parseMpdCurrentSong($res);

	$audioinfo_mpdcurrentsong_file = $_mpdcurrentsong['file'];
	// mpd status
	$_mpdstatus = _parseStatusResponse(mpdStatus($mpd));
	if ($_hwparams['status'] == 'active') {
		// source format
		$audioinfo_mpdstatus_format = $_mpdstatus['audio_channels'] . ", ";
		// TC (Tim Curtis) 2015-07-31: format when "dsd" (for dsf files)
		$audioinfo_mpdstatus_format .= $_mpdstatus['audio_sample_depth'];
		$audioinfo_mpdstatus_format .= ($_mpdstatus['audio_sample_depth'] == "dsd") ? ", " : " bit, ";
		$audioinfo_mpdstatus_format .= $_mpdstatus['audio_sample_rate'] . " kHz";
		// bit rate
		$audioinfo_mpdstatus_bitrate = $_mpdstatus['bitrate'] . " kbps";
	}
	else {
		$audioinfo_mpdstatus_format = '';
		$audioinfo_mpdstatus_bitrate = "0 bps";
	}
}

// DSP INFO: mpd.conf, configured SRC output format and converter
// TC (Tim Curtis) 2015-06-26: add Volume settings from tcmods.conf
$_tcmodsconf = getTcmodsConf();
$_mpdconf = _parseMpdConf();

if ($_mpdconf['audio_channels'] != '') {
	$audioinfo_mpdconf_src = $_mpdconf['samplerate_converter'];
	$audioinfo_mpdconf_format = $_mpdconf['audio_channels'] . ", ";
	$audioinfo_mpdconf_format .= $_mpdconf['audio_sample_depth'] . " bit, ";
	$audioinfo_mpdconf_format .= $_mpdconf['audio_sample_rate'] . " kHz";
}
else {
	$audioinfo_mpdconf_src = 'off';
	$audioinfo_mpdconf_format = '';
}

if ($_tcmodsconf['volume_mixer_type'] == "hardware") {
	if ($_tcmodsconf['volume_curve_logarithmic'] == "Yes") {
		$curve_type = "Logarthmic curve";
		if ($_tcmodsconf['volume_curve_factor'] == 56) {$curve_slope = "Standard slope,";}
		else if ($_tcmodsconf['volume_curve_factor'] == 66) {$curve_slope = "Less (-10) slope,";}
		else if ($_tcmodsconf['volume_curve_factor'] == 76) {$curve_slope = "Less (-20) slope,";}
		else if ($_tcmodsconf['volume_curve_factor'] == 86) {$curve_slope = "Less (-30) slope,";}
		else if ($_tcmodsconf['volume_curve_factor'] == 50) {$curve_slope = "More (+06) slope,";}
		else if ($_tcmodsconf['volume_curve_factor'] == 44) {$curve_slope = "More (+12) slope,";}
		else if ($_tcmodsconf['volume_curve_factor'] == 38) {$curve_slope = "More (+18) slope,";}
	}
	else {
		$curve_type = "Linear curve";
		$curve_slope = '';
	}
	$audioinfo_tcmodsconf_volume = "Hardware, ".$curve_type.", ".$curve_slope." Vol-max ".$_tcmodsconf['volume_max_percent']."%";
}
else if ($_tcmodsconf['volume_mixer_type'] == "software") {
	$audioinfo_tcmodsconf_volume = "Software (MPD 32 bit float with dither)";
}

// DEVICE INFO: tcmods.conf, audio device description (manually entered by user)
$audioinfo_tcmodsconf_device_name = $_tcmodsconf['audio_device_name'];
$audioinfo_tcmodsconf_device_dac = $_tcmodsconf['audio_device_dac'];
$audioinfo_tcmodsconf_device_arch = $_tcmodsconf['audio_device_arch'];
$audioinfo_tcmodsconf_device_iface = $_tcmodsconf['audio_device_iface'];
$audioinfo_tcmodsconf_device_other = $_tcmodsconf['audio_device_other'];

// SYSTEM INFO: architecture, cpu util, temp and freq
$systeminfo_cpuload = number_format(trim(shell_exec("top -bn 2 -d 0.5 | grep 'Cpu(s)' | tail -n 1 | awk '{print $2 + $4 + $6}'")),0,'.','');
$systeminfo_cputemp = substr(file_get_contents('/sys/class/thermal/thermal_zone0/temp'), 0, 2);
$_cpufreq = (float)file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq');
if ($_cpufreq < 1000000) {
	$_cpufreq = $_cpufreq / 1000;
	$systeminfo_cpufreq = number_format($_cpufreq,0,'.','');
	$systeminfo_cpufreq .= " MHz";
}
else {
	$_cpufreq = $_cpufreq / 1000000;
	$systeminfo_cpufreq = number_format($_cpufreq,1,'.','');
	$systeminfo_cpufreq .= " GHz";
}
// TC (Tim Curtis) 2015-02-25: processor architecture
$systeminfo_arch = trim(shell_exec('uname -m'));


Session::close();

render("audioinfo", false);

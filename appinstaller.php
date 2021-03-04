<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Copyright (C) 2008-2016 All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Michael S <michaelasuiter@gmail.com>
*/

    //generates the sessioncloud.appinstaller file for the ms-appinstaller to install. Needs to be dynamically generated.

	//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/functions/functions.php";

    //check if logged in so we don't clobber the user's current session. Only applicable when testing/accessing directly.
    //This file should usually be accessed by ms-appinstaller which won't have access to a user's cookies/_SESSION from their browser.
    if (!isset($_SESSION['domain_name'])) {
        $domain_name = $_REQUEST['HTTP_HOST'];
        load_defaults($domain_name);
    }

    $appinstaller_url = $_SESSION['sessiontalk']['windows_appinstaller_url']['text'];
    $update_interval = $_SESSION['sessiontalk']['windows_update_interval']['numeric'];
    $softphone_name = $_SESSION['sessiontalk']['windows_softphone_name']['text'];

    //do it with a DOMDocument 
    $appdom = new DOMDocument("1.0");
    $appdom->preserveWhiteSpace = true;
    $appdom->formatOutput = true;
    $appdom->load($appinstaller_url);

    //root node
    $appinstaller = $appdom->documentElement;
    //Set the URI to reference the redirected file
    $protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $url = $protocol . $_SERVER['HTTP_HOST'] . "/app/sessiontalk/?" . $softphone_name . ".appinstaller";
    $appinstaller->setAttribute('Uri', $url);
    //Set the update interval
    if (isset($update_interval)) {
        $updatesettings = $appdom->getElementsbyTagName('OnLaunch');
        if ($updatesettings->length >= 1) {
            $updatesettings->item(0)->setAttribute('HoursBetweenUpdateChecks', $update_interval);
        }
        else {
            $updatesettings = $appdom->CreateElement('UpdateSettings');
            $updateinterval = $appdom->CreateElement('UpdateInterval');
            $updateinterval->setAttribute('HoursBetweenUpdateChecks', $update_interval);
            $updatesettings->appendChild($updateinterval);
            $appinstaller->appendChild($updatesettings);
        }
    }

    $output = $appdom->saveXML();
    //return the file
    Header("Content-Disposition: attachment; filename=" . $softphone_name . ".appinstaller");
    Header('Content-type: text/xml');
    Header("Content-length: " . strlen($output)); // tells file size, the Microsoft app installer sends a HEAD and fails if it doesn't get the content length
    echo $output;
?>